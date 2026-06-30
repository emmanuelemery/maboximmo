<?php
/**
 * api/quick_search.php — Recherche rapide (topbar) : nom de locataire OU adresse/ville/réf de bien.
 * Renvoie une liste de biens correspondants → lien vers bien_360.php.
 * Insensible à la casse (collation MySQL). GET q=…  → {ok, results:[{id,ref,adresse,ville,loc}]}.
 * Sécurité : login.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true, 'results'=>[]]); exit; }
$like = '%' . $q . '%';

// ── Périmètre : staff (1,2,3,7) + super admin = tout ; bailleur (9,10) = SES biens uniquement
// (ses propriétaires via user_proprietaires, + biens reliés par les CRG de ses propriétaires).
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? 0);
$isStaff = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
$scopeSql = '';
if (!$isStaff) {
    $scopeSql = " AND ( b.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?)
                        OR b.id IN (SELECT s.id_bien FROM crg_situations_locataires s
                                    JOIN crg_trimestres t ON t.id = s.id_crg
                                    WHERE t.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?)) )";
}

$sql = "
    SELECT b.id,
           b.reference_bien AS ref,
           COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
           COALESCE(NULLIF(b.ville,''),    i.ville)      AS ville,
           (SELECT COALESCE(NULLIF(tl.nom_affichage,''), bb.locataire_raison_sociale,
                            NULLIF(TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)),''))
              FROM bien_baux bb
              LEFT JOIN tiers tl ON tl.id = bb.id_tiers_locataire
             WHERE bb.id_bien = b.id
             ORDER BY (bb.statut='actif') DESC, bb.date_prise_effet DESC LIMIT 1) AS loc,
           (SELECT bb.id FROM bien_baux bb
             WHERE bb.id_bien = b.id
             ORDER BY (bb.statut='actif') DESC, bb.date_prise_effet DESC LIMIT 1) AS bail_id
    FROM biens b
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    WHERE (
            b.reference_bien LIKE ?
         OR b.adresse_1 LIKE ? OR i.adresse_1 LIKE ?
         OR b.ville LIKE ?     OR i.ville LIKE ?
         OR EXISTS (SELECT 1 FROM bien_baux bb2
                    LEFT JOIN tiers tl2 ON tl2.id = bb2.id_tiers_locataire
                    WHERE bb2.id_bien = b.id
                      AND (bb2.locataire_nom LIKE ? OR bb2.locataire_raison_sociale LIKE ?
                           OR tl2.nom_affichage LIKE ? OR tl2.raison_sociale LIKE ?))
         OR i.nom_immeuble LIKE ?
         OR EXISTS (SELECT 1 FROM proprietaires p
                    WHERE p.id = b.id_proprietaire
                      AND (p.nom LIKE ? OR p.societe LIKE ? OR p.prenom LIKE ?))
          )
    {$scopeSql}
    ORDER BY (b.reference_bien LIKE ?) DESC, b.id DESC
    LIMIT 12";
try {
    // Placeholders positionnels (EMULATE_PREPARES=false → pas de réutilisation de nom).
    // Ordre : 13 LIKE (WHERE) → [scope uid, uid2] → 1 LIKE (ORDER BY).
    $args = array_fill(0, 13, $like);
    if (!$isStaff) { $args[] = $userId; $args[] = $userId; }   // :uid, :uid2 du $scopeSql
    $args[] = $like;                                            // ORDER BY
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
}

$results = array_map(fn($r) => [
    'id'      => (int)$r['id'],
    'bail_id' => (int)($r['bail_id'] ?? 0),
    'ref'     => (string)($r['ref'] ?? ''),
    'adresse' => trim((string)($r['adresse'] ?? '')),
    'ville'   => trim((string)($r['ville'] ?? '')),
    'loc'     => trim((string)($r['loc'] ?? '')),
], $rows);

echo json_encode(['ok'=>true, 'results'=>$results], JSON_UNESCAPED_UNICODE);
