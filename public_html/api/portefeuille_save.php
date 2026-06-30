<?php
// api/portefeuille_save.php — Enregistre un portefeuille de vente (sélection + prix + honoraires).
// Sécurité : login + rôle gestionnaire + CSRF. Périmètre : biens des bailleurs autorisés uniquement.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$roleId = (int)current_role_id();
// Staff (1,2,3) + super-admin (7) + BAILLEURS (9,10). Le périmètre reste une borne dure
// (pf_scope) : un bailleur ne peut sélectionner QUE les biens de ses propres propriétaires.
if (!in_array($roleId, [1, 2, 3, 7, 9, 10], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}
if (function_exists('is_readonly_user') && is_readonly_user()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Compte en lecture seule : enregistrement non autorisé.']);
    exit;
}
verify_csrf_any('portefeuille_save');

$numf = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST[$k]) : null;
$idPf       = (int)($_POST['id_portefeuille'] ?? 0);   // >0 = mise à jour d'un portefeuille existant
$nom        = trim((string)($_POST['nom'] ?? ''));
$typeDest   = (string)($_POST['type_destinataire'] ?? '');
$destNom    = trim((string)($_POST['destinataire_nom'] ?? ''));
$destPrenom = trim((string)($_POST['destinataire_prenom'] ?? ''));
$destEmail  = trim((string)($_POST['destinataire_email'] ?? ''));
$destTel    = trim((string)($_POST['destinataire_tel'] ?? ''));
$critPxMin  = $numf('critere_prix_min'); $critPxMax = $numf('critere_prix_max');
$critSfMin  = $numf('critere_surf_min'); $critSfMax = $numf('critere_surf_max');
$critType   = strtolower(trim((string)($_POST['critere_type'] ?? '')));
if ($critType === 'professionnel') { $critType = 'pro'; }   // tolère l'alias long
if (!in_array($critType, ['habitation', 'pro'], true)) { $critType = null; }   // NULL = indifférent (valeurs UI : habitation | pro)
$commentaire = trim((string)($_POST['commentaire'] ?? ''));
$items      = json_decode((string)($_POST['items'] ?? '[]'), true) ?: [];

if ($nom === '')             { echo json_encode(['success' => false, 'message' => 'Nom du portefeuille requis.']); exit; }
if (!in_array($typeDest, ['commercialisateur', 'investisseur', ''], true)) { $typeDest = ''; }
if (empty($items))           { echo json_encode(['success' => false, 'message' => 'Sélectionnez au moins un bien.']); exit; }

// Périmètre = borne dure (scope de l'utilisateur : staff = tout ; bailleur = ses proprios).
require_once __DIR__ . '/../inc/portefeuille_scope.php';
$perimetreIds = pf_scope($pdo)['ids'];
$in           = !empty($perimetreIds) ? implode(',', array_map('intval', $perimetreIds)) : '0';

// Snapshot des biens autorisés.
$ids = array_values(array_unique(array_filter(array_map(fn($it) => (int)($it['id_bien'] ?? 0), $items))));
if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'Sélection vide.']); exit; }
$inIds = implode(',', $ids);
$snapStmt = $pdo->query("
    SELECT b.id,
        COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
        COALESCE(NULLIF(b.ville,''), i.ville)         AS ville,
        b.reference_bien, b.surface_habitable,
        COALESCE((SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id
                  ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1),
                 b.loyer_hc) AS loyer_mensuel
    FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
    WHERE b.id IN ($inIds) AND b.id_proprietaire IN ($in)
      AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))");
$snap = [];
foreach ($snapStmt as $r) { $snap[(int)$r['id']] = $r; }

$num = fn($v) => ($v === null || $v === '') ? null : (float)str_replace([' ', ','], ['', '.'], (string)$v);

$lines = [];
$totPV = 0.0; $totNV = 0.0; $totHo = 0.0;
$ordre = 0;
foreach ($items as $it) {
    $bid = (int)($it['id_bien'] ?? 0);
    if (!isset($snap[$bid])) { continue; } // hors périmètre / supprimé → ignoré
    $s = $snap[$bid];
    $pv = $num($it['prix_vente'] ?? null);
    $pm = $num($it['prix_m2'] ?? null);
    $rt = $num($it['rendement'] ?? null);
    $hp = $num($it['honoraires_pct'] ?? null);
    $hm = $num($it['honoraires_montant'] ?? null);
    $nv = $num($it['net_vendeur'] ?? null);
    $dmp = $num($it['droits_mutation_pct'] ?? null);
    $dm  = $num($it['droits_mutation_montant'] ?? null);
    $aem = $num($it['prix_acte_en_main'] ?? null);
    // Documents GED à joindre (IDs entiers > 0). NULL si aucun coché → rétrocompat.
    $docIds = array_values(array_filter(array_map('intval', (array)($it['documents_joints'] ?? [])), fn($v) => $v > 0));
    $docJson = $docIds ? json_encode($docIds) : null;
    $lines[] = [$bid, $pv, $pm, $rt, $hp, $hm, $nv, $dmp, $dm, $aem, $docJson, $s, ++$ordre];
    if ($pv) $totPV += $pv;
    if ($nv) $totNV += $nv;
    if ($hm) $totHo += $hm;
}
if (empty($lines)) { echo json_encode(['success' => false, 'message' => 'Aucun bien valide dans le périmètre.']); exit; }

// id_user = créateur (traçabilité). La VISIBILITÉ d'un portefeuille ne dépend PAS de ce champ :
// un portefeuille peut regrouper plusieurs propriétaires et reste accessible à l'admin + à tout
// bailleur concerné par au moins un des biens (cf. portefeuille_scope / liste, filtre par appartenance).
$userId = function_exists('current_user_id') ? (int)current_user_id() : null;

try {
    $pdo->beginTransaction();
    if ($idPf > 0) {
        // Mise à jour : on remplace les lignes et on rafraîchit l'entête.
        $chk = $pdo->prepare("SELECT id FROM portefeuilles WHERE id = ?");
        $chk->execute([$idPf]);
        if (!$chk->fetchColumn()) { throw new RuntimeException('Portefeuille introuvable.'); }
        // Garde-fou périmètre : un BAILLEUR (9,10) ne peut modifier qu'un portefeuille
        // contenant au moins un de SES biens (pas d'accès par id deviné).
        if (in_array($roleId, [9, 10], true)) {
            $chkOwn = $pdo->prepare("SELECT 1 FROM portefeuille_biens pb JOIN biens b ON b.id = pb.id_bien
                WHERE pb.id_portefeuille = ? AND b.id_proprietaire IN ($in) LIMIT 1");
            $chkOwn->execute([$idPf]);
            if (!$chkOwn->fetchColumn()) { throw new RuntimeException('Portefeuille hors de votre périmètre.'); }
        }
        $pdo->prepare("UPDATE portefeuilles SET nom=?, type_destinataire=?, destinataire_nom=?, destinataire_prenom=?, destinataire_email=?, destinataire_tel=?,
            critere_prix_min=?, critere_prix_max=?, critere_surf_min=?, critere_surf_max=?, critere_type=?, commentaire=?,
            nb_biens=?, total_prix_vente=?, total_net_vendeur=?, total_honoraires=?, date_modification=NOW() WHERE id=?")
            ->execute([$nom, $typeDest ?: null, $destNom ?: null, $destPrenom ?: null, $destEmail ?: null, $destTel ?: null,
                       $critPxMin, $critPxMax, $critSfMin, $critSfMax, $critType, $commentaire ?: null,
                       count($lines), $totPV, $totNV, $totHo, $idPf]);
        $pdo->prepare("DELETE FROM portefeuille_biens WHERE id_portefeuille = ?")->execute([$idPf]);
        $pid = $idPf;
    } else {
        $pdo->prepare("INSERT INTO portefeuilles
            (nom, type_destinataire, destinataire_nom, destinataire_prenom, destinataire_email, destinataire_tel,
             critere_prix_min, critere_prix_max, critere_surf_min, critere_surf_max, critere_type,
             statut, commentaire, nb_biens, total_prix_vente, total_net_vendeur, total_honoraires, id_user)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$nom, $typeDest ?: null, $destNom ?: null, $destPrenom ?: null, $destEmail ?: null, $destTel ?: null,
                       $critPxMin, $critPxMax, $critSfMin, $critSfMax, $critType,
                       'brouillon', $commentaire ?: null,
                       count($lines), $totPV, $totNV, $totHo, $userId]);
        $pid = (int)$pdo->lastInsertId();
    }

    // La colonne documents_joints peut ne pas exister (migration 20260623 non appliquée).
    // On l'inclut seulement si elle est présente → pas de casse côté prod en attente de migration.
    $hasDocCol = false;
    try { $hasDocCol = (bool)$pdo->query("SHOW COLUMNS FROM portefeuille_biens LIKE 'documents_joints'")->fetchColumn(); } catch (Throwable $e) {}

    if ($hasDocCol) {
        $ins = $pdo->prepare("INSERT INTO portefeuille_biens
            (id_portefeuille, id_bien, prix_vente, prix_m2, rendement, honoraires_pct, honoraires_montant, net_vendeur,
             droits_mutation_pct, droits_mutation_montant, prix_acte_en_main, documents_joints,
             snap_adresse, snap_ville, snap_reference, snap_surface, snap_loyer_mensuel, ordre)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    } else {
        $ins = $pdo->prepare("INSERT INTO portefeuille_biens
            (id_portefeuille, id_bien, prix_vente, prix_m2, rendement, honoraires_pct, honoraires_montant, net_vendeur,
             droits_mutation_pct, droits_mutation_montant, prix_acte_en_main,
             snap_adresse, snap_ville, snap_reference, snap_surface, snap_loyer_mensuel, ordre)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    }
    foreach ($lines as $l) {
        [$bid,$pv,$pm,$rt,$hp,$hm,$nv,$dmp,$dm,$aem,$docJson,$s,$ord] = $l;
        $base = [$pid, $bid, $pv, $pm, $rt, $hp, $hm, $nv, $dmp, $dm, $aem];
        $tail = [$s['adresse'] ?? null, $s['ville'] ?? null, $s['reference_bien'] ?? null,
                 $s['surface_habitable'] ?? null, $s['loyer_mensuel'] ?? null, $ord];
        $ins->execute($hasDocCol ? array_merge($base, [$docJson], $tail) : array_merge($base, $tail));
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'id_portefeuille' => $pid, 'updated' => $idPf > 0, 'nb_biens' => count($lines), 'total_prix_vente' => $totPV]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
