<?php
declare(strict_types=1);

/**
 * RH SALAIRE WORKFLOW — helper pour le journal des aller-retours comptables
 *
 * Migration source : 20260430_rh_salaire_workflow.
 * Cf. table `rh_salaire_workflow_log`.
 *
 * 3 types d'actions possibles :
 *   - envoi_comptable     : agence envoie le PDF des salaires au comptable
 *   - import_projet       : agence importe le projet renvoyé par le comptable
 *   - import_bulletins    : agence importe les bulletins finaux
 *
 * Chaque action est logguée + le fichier est conservé sur disque, pour
 * permettre à l'utilisateur de :
 *   - voir l'historique complet des aller-retours d'un mois donné
 *   - re-télécharger n'importe quel fichier (PDF envoyé, projet reçu, etc.)
 *   - tracer qui a fait quoi (triggered_by → users.id)
 */

const RH_WF_TYPE_ENVOI     = 'envoi_comptable';
const RH_WF_TYPE_PROJET    = 'import_projet';
const RH_WF_TYPE_BULLETINS = 'import_bulletins';

const RH_WF_TYPES_LABELS = [
    RH_WF_TYPE_ENVOI     => '📤 Envoi au comptable',
    RH_WF_TYPE_PROJET    => '📥 Projet reçu',
    RH_WF_TYPE_BULLETINS => '📋 Bulletins finaux',
];

/**
 * Calcule le prochain numéro d'itération pour un (agence, mois, type).
 * Permet d'ordonner les aller-retours dans la timeline.
 */
function rh_wf_next_iteration(PDO $pdo, int $idAgence, string $moisRef, string $type): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(MAX(iteration), 0) + 1
         FROM rh_salaire_workflow_log
         WHERE id_agence = ? AND mois_reference = ? AND type_action = ?"
    );
    $st->execute([$idAgence, $moisRef, $type]);
    return (int)$st->fetchColumn() ?: 1;
}

/**
 * Stocke physiquement un fichier dans uploads/rh_salaires/{soc}/{ag}/{mois}/{type}/
 * et renvoie le chemin RELATIF depuis public_html/.
 *
 * @return string|null Chemin relatif ou null si échec.
 */
function rh_wf_save_file(int $idSociete, int $idAgence, string $moisRef, string $type, int $iteration, string $sourceContent, string $originalName): ?string
{
    $publicHtml = dirname(__DIR__);
    $relDir = sprintf('uploads/rh_salaires/%d/%d/%s/%s', $idSociete, $idAgence, $moisRef, $type);
    $absDir = $publicHtml . '/' . $relDir;

    if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        error_log("[rh_wf] Impossible de créer le dossier : $absDir");
        return null;
    }

    // Nom : {iteration}_{horodatage}_{nom_safe}
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
    $filename = sprintf('%02d_%s_%s', $iteration, date('Ymd-His'), $safeName);
    $relPath = $relDir . '/' . $filename;
    $absPath = $absDir . '/' . $filename;

    if (file_put_contents($absPath, $sourceContent) === false) {
        error_log("[rh_wf] Échec écriture : $absPath");
        return null;
    }
    return $relPath;
}

/**
 * Logge une action dans rh_salaire_workflow_log.
 *
 * @return int|null id du log créé ou null si échec.
 */
function rh_wf_log_action(
    PDO $pdo,
    int $idSociete,
    int $idAgence,
    string $moisRef,
    string $type,
    ?string $fichierPath = null,
    ?string $fichierNomOriginal = null,
    ?int $fichierTaille = null,
    ?string $destinataire = null,
    ?int $triggeredBy = null,
    string $status = 'ok',
    ?string $errorMsg = null,
    ?string $commentaire = null
): ?int {
    $iteration = rh_wf_next_iteration($pdo, $idAgence, $moisRef, $type);

    try {
        $st = $pdo->prepare("
            INSERT INTO rh_salaire_workflow_log
              (id_societe, id_agence, mois_reference, type_action, iteration,
               fichier_path, fichier_nom_original, fichier_taille,
               destinataire, triggered_by, status, error_msg, commentaire)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $idSociete, $idAgence, $moisRef, $type, $iteration,
            $fichierPath, $fichierNomOriginal, $fichierTaille,
            $destinataire, $triggeredBy, $status, $errorMsg, $commentaire,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[rh_wf_log_action] ' . $e->getMessage());
        return null;
    }
}

/**
 * Récupère l'historique complet d'un (agence, mois) toutes actions confondues,
 * avec les infos user (prénom + nom) en JOIN.
 */
function rh_wf_history(PDO $pdo, int $idAgence, string $moisRef): array
{
    try {
        $st = $pdo->prepare("
            SELECT w.id, w.type_action, w.iteration, w.fichier_path, w.fichier_nom_original,
                   w.fichier_taille, w.destinataire, w.status, w.error_msg, w.commentaire,
                   w.date_action, w.triggered_by,
                   TRIM(CONCAT_WS(' ', u.prenom, u.nom)) AS user_nom
            FROM rh_salaire_workflow_log w
            LEFT JOIN users u ON u.id = w.triggered_by
            WHERE w.id_agence = ? AND w.mois_reference = ?
            ORDER BY w.date_action ASC, w.id ASC
        ");
        $st->execute([$idAgence, $moisRef]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[rh_wf_history] ' . $e->getMessage());
        return [];
    }
}

/**
 * Filtre une portion d'historique sur un type d'action donné.
 */
function rh_wf_history_for_type(array $history, string $type): array
{
    return array_values(array_filter($history, fn($h) => $h['type_action'] === $type));
}

/**
 * Vérifie qu'un user a le droit de voir/agir sur le workflow d'une agence.
 * - admin (role=1) → tout
 * - gestion_salaires=1 → uniquement son agence
 * - autres → non
 */
function rh_wf_can_access(PDO $pdo, int $idUser, int $idAgenceCible): bool
{
    if ($idUser <= 0) return false;
    try {
        $st = $pdo->prepare("SELECT id_role, gestion_salaires, id_agence FROM users WHERE id = ? LIMIT 1");
        $st->execute([$idUser]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return false;
        if ((int)$u['id_role'] === 1) return true;
        if ((int)$u['gestion_salaires'] === 1 && (int)$u['id_agence'] === $idAgenceCible) return true;
        return false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Helper d'affichage : taille humanisée.
 */
function rh_wf_human_size(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) return '—';
    if ($bytes < 1024) return $bytes . ' o';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
    return number_format($bytes / (1024 * 1024), 2, ',', ' ') . ' Mo';
}
