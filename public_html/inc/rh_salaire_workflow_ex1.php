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

const RH_WF_TYPE_ENVOI      = 'envoi_comptable';
const RH_WF_TYPE_PROJET     = 'import_projet';
const RH_WF_TYPE_BULLETINS  = 'import_bulletins';
const RH_WF_TYPE_VALIDATION = 'validation_projet';

const RH_WF_TYPES_LABELS = [
    RH_WF_TYPE_ENVOI      => '📤 Envoi au comptable',
    RH_WF_TYPE_PROJET     => '📥 Projet reçu',
    RH_WF_TYPE_BULLETINS  => '📋 Bulletins finaux',
    RH_WF_TYPE_VALIDATION => '✅ Validation au comptable',
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
 * Slugifie un nom (agence, société…) pour usage dans un nom de fichier.
 * Garde les accents (UTF-8 OK sur disque & dans les attachments PHPMailer),
 * remplace seulement les caractères incompatibles avec un filename.
 *
 * Ex : "Régie Émery — Chaponost" → "Régie_Émery_Chaponost"
 */
function rh_wf_slug_filename(string $s): string
{
    $s = trim($s);
    if ($s === '') return 'inconnu';
    // Retire les caractères interdits sur la plupart des FS (Windows + *nix)
    $s = preg_replace('/[\/\\\\:*?"<>|]+/u', '', $s) ?? $s;
    // Tirets longs/em-dashes → simple tiret
    $s = str_replace(['—', '–'], '-', $s);
    // Espaces (et runs de séparateurs) → underscore
    $s = preg_replace('/\s+/u', '_', $s) ?? $s;
    $s = preg_replace('/_+/', '_', $s) ?? $s;
    return trim($s, '_-') ?: 'inconnu';
}

/**
 * Construit le nom de fichier standard pour un PDF Salaires & Congés
 * d'une agence donnée pour un mois donné.
 *
 * Format : Salaires_congés_<NOM_AGENCE>_<YYYY-MM>.pdf
 * Ex     : Salaires_congés_REGIE_EMERY_CHAPONOST_2026-04.pdf
 */
function rh_wf_pdf_filename(string $nomAgence, int $annee, int $mois): string
{
    $slug = rh_wf_slug_filename(mb_strtoupper($nomAgence, 'UTF-8'));
    return sprintf('Salaires_congés_%s_%04d-%02d.pdf', $slug, $annee, $mois);
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

/**
 * Intègre un fichier PDF déposé (projet ou bulletins) en rapport de comparaison
 * `rh_salaires_comparaisons`, pour UNE agence. Factorise la logique utilisée par
 * rh_salaires.php afin de pouvoir intégrer AUTOMATIQUEMENT un dépôt via lien
 * « Demander un document » (plus besoin du bouton manuel « Importer les finaux »).
 *
 * @param string $type 'projet' | 'bulletins'
 * @return array ['ok'=>bool, 'created'=>bool, 'error'=>?string, 'total_net'=>float]
 */
function rh_wf_integrate_comparaison(PDO $pdo, int $idSociete, int $idAgence, string $moisRef, string $type, string $absPath, string $fileName, ?int $createdBy = null): array
{
    $type = ($type === 'bulletins') ? 'bulletins' : 'projet';
    // Dépendances (parser + lib de comparaison) chargées à la demande.
    if (!function_exists('rh_parse_bulletins_file')) {
        $p = __DIR__ . '/rh_salaires_parser.php';
        if (is_file($p)) require_once $p;
    }
    if (!function_exists('rh_compare_bulletins_expected')) {
        $c = __DIR__ . '/rh_compare_lib.php';
        if (is_file($c)) require_once $c;
    }
    if (!function_exists('rh_parse_bulletins_file') || !function_exists('rh_compare_bulletins_expected')) {
        return ['ok' => false, 'created' => false, 'error' => 'dépendances RH indisponibles', 'total_net' => 0.0];
    }
    if (!is_file($absPath)) {
        return ['ok' => false, 'created' => false, 'error' => 'fichier introuvable', 'total_net' => 0.0];
    }

    $moisPost  = (int)substr($moisRef, 5, 2);
    $anneePost = (int)substr($moisRef, 0, 4);

    $meta = []; $parsed = rh_parse_bulletins_file($absPath, $meta);
    $employees = ($parsed['ok'] ?? false) ? ($parsed['data']['employees'] ?? []) : [];
    if (empty($employees)) {
        return ['ok' => false, 'created' => false, 'error' => 'extraction PDF impossible (' . ($parsed['error'] ?? 'parser KO') . ')', 'total_net' => 0.0];
    }
    $groups = rh_dispatch_bulletins_by_agence($pdo, $idSociete, $employees);
    $group  = $groups[$idAgence] ?? null;
    if (!$group) {
        return ['ok' => false, 'created' => false, 'error' => 'aucun matricule ne correspond à l\'agence', 'total_net' => 0.0];
    }

    $expected = rh_load_expected_map($pdo, $idSociete, $moisRef, $idAgence);
    $compare  = rh_compare_bulletins_expected($expected, $group['employees']);
    $compare['conges'] = rh_compute_conges_summary($pdo, $expected, $moisPost, $anneePost, $group['employees']);

    // Copie du PDF dans le dossier servable (volet PDF des modals).
    $destDir = dirname(__DIR__) . '/uploads/salaires_comptable';
    if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
    $destName = 'auto_' . $type . '_' . $idSociete . '_ag' . $idAgence . '_' . $anneePost . str_pad((string)$moisPost, 2, '0', STR_PAD_LEFT) . '_' . time() . '.pdf';
    $filePathRel = @copy($absPath, $destDir . '/' . $destName) ? ('/uploads/salaires_comptable/' . $destName) : $absPath;

    if ($type === 'bulletins') {
        $totalNet = 0.0;
        foreach ($group['employees'] as $e) { if (isset($e['net']) && $e['net'] !== null) $totalNet += (float)$e['net']; }
        $ins = $pdo->prepare("INSERT INTO rh_salaires_comparaisons (id_societe,id_agence,mois,annee,type,file_name,file_path,total_pdf_net,compare_ok,compare_json,parsed_json,created_by) VALUES (?,?,?,?,'bulletins',?,?,?,?,?,?,?)");
        $ins->execute([$idSociete, $idAgence, $moisPost, $anneePost, $fileName, $filePathRel, $totalNet, $compare['ok'] ? 1 : 0, json_encode($compare, JSON_UNESCAPED_UNICODE), json_encode(['employees' => $group['employees']], JSON_UNESCAPED_UNICODE), $createdBy]);
        return ['ok' => true, 'created' => true, 'error' => null, 'total_net' => $totalNet];
    }

    $ins = $pdo->prepare("INSERT INTO rh_salaires_comparaisons (id_societe,id_agence,mois,annee,type,file_name,file_path,total_pdf_brut,total_expected_brut,compare_ok,compare_json,parsed_json,created_by) VALUES (?,?,?,?,'projet',?,?,?,?,?,?,?,?)");
    $ins->execute([$idSociete, $idAgence, $moisPost, $anneePost, $fileName, $filePathRel, $compare['total_pdf'], $compare['total_expected'], $compare['ok'] ? 1 : 0, json_encode($compare, JSON_UNESCAPED_UNICODE), json_encode(['employees' => $group['employees']], JSON_UNESCAPED_UNICODE), $createdBy]);
    return ['ok' => true, 'created' => true, 'error' => null, 'total_net' => 0.0];
}
