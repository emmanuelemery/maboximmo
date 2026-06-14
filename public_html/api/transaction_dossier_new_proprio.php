<?php
// api/transaction_dossier_new_proprio.php — Étape 1 du parcours « Nouveau dossier ».
// Garantit une fiche proprietaires pour un tiers (créé via tiers_selector). Idempotent.
// POST : id_tiers  →  { ok, id_proprietaire, id_tiers, nom }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idTiers = (int)(post('id_tiers') ?? 0);
if ($idTiers <= 0) { echo json_encode(['ok'=>false,'error'=>'id_tiers manquant']); exit; }

try {
    $stT = $pdo->prepare("SELECT id, nom, prenom, raison_sociale, nom_affichage,
                                 email, telephone, id_agence, type_tiers
                          FROM tiers WHERE id = ? LIMIT 1");
    $stT->execute([$idTiers]);
    $t = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo json_encode(['ok'=>false,'error'=>'tiers introuvable']); exit; }

    // Déjà un propriétaire pour ce tiers ?
    $stP = $pdo->prepare("SELECT id FROM proprietaires WHERE id_tiers = ? LIMIT 1");
    $stP->execute([$idTiers]);
    $idProp = (int)($stP->fetchColumn() ?: 0);

    // Affichage : RAISON SOCIALE en priorité (ex. « SCI FOCH ») ; si vide,
    // on retombe sur NOM + prénom.
    $rs = trim((string)($t['raison_sociale'] ?? ''));
    $np = trim(trim((string)($t['nom'] ?? '')) . ' ' . trim((string)($t['prenom'] ?? '')));
    $nom = $rs !== '' ? $rs : ($np !== '' ? $np : ('Tiers #' . $idTiers));

    if ($idProp <= 0) {
        $estMorale = in_array($t['type_tiers'], ['personne_morale','entite_juridique','indivision','syndicat_coprop'], true);
        $ins = $pdo->prepare("INSERT INTO proprietaires
            (id_tiers, id_agence, type_personne, nom, prenom, societe, email, telephone, actif, date_creation, date_modification)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $ins->execute([
            $idTiers,
            $t['id_agence'] ?: (function_exists('current_agence_id') ? current_agence_id() : null),
            $estMorale ? 'morale' : 'physique',
            $nom,
            $t['prenom'] ?: null,
            $estMorale ? ($t['raison_sociale'] ?: $nom) : null,
            $t['email'] ?: null,
            $t['telephone'] ?: null,
        ]);
        $idProp = (int)$pdo->lastInsertId();

        // Rôle propriétaire global (cohérent avec proprietaire_creer).
        $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif, date_creation)
                       VALUES (?, 'proprietaire', NULL, NULL, 1, NOW())")->execute([$idTiers]);
    }

    echo json_encode(['ok'=>true, 'id_proprietaire'=>$idProp, 'id_tiers'=>$idTiers, 'nom'=>$nom], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_new_proprio] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
