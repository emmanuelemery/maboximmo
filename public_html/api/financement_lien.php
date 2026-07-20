<?php
declare(strict_types=1);
/**
 * api/financement_lien.php — Liens polymorphes d'un dossier financier (T1).
 *   GET  ?op=search&type=BIEN|IMMEUBLE|TIERS|CREANCIER_DOSSIER|SOCIETE&q=...  → candidats (société)
 *   POST {op:'add', id_dossier, type, entity_id, role?}   / {op:'remove', id_dossier, type, entity_id}
 * On RÉFÉRENCE des objets existants (aucune duplication).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/financement.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
function fo(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$pdo = $GLOBALS['pdo'];
$soc = fin_soc();
$isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)current_role_id(), [1, 7], true);

/* ── Recherche (GET) ── */
if (($_GET['op'] ?? '') === 'search') {
    $type = strtoupper((string)($_GET['type'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $like = '%' . $q . '%';
    $res = [];
    try {
        if ($type === 'BIEN') {
            $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(reference_bien,''),' · ',COALESCE(adresse_1,''),' ',COALESCE(ville,''))) label FROM biens WHERE id_societe=? AND (reference_bien LIKE ? OR adresse_1 LIKE ? OR ville LIKE ?) ORDER BY reference_bien LIMIT 20");
            $st->execute([$soc, $like, $like, $like]);
        } elseif ($type === 'IMMEUBLE') {
            $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(nom_immeuble,''),' · ',COALESCE(adresse_1,''),' ',COALESCE(ville,''))) label FROM immeubles WHERE id_societe=? AND (nom_immeuble LIKE ? OR adresse_1 LIKE ? OR ville LIKE ?) ORDER BY nom_immeuble LIMIT 20");
            $st->execute([$soc, $like, $like, $like]);
        } elseif ($type === 'TIERS') {
            $st = $pdo->prepare("SELECT id, COALESCE(nom_affichage, CONCAT(COALESCE(nom,''),' ',COALESCE(prenom,''))) label FROM tiers WHERE id_societe=? AND (nom_affichage LIKE ? OR nom LIKE ? OR prenom LIKE ? OR raison_sociale LIKE ?) ORDER BY nom_affichage LIMIT 20");
            $st->execute([$soc, $like, $like, $like, $like]);
        } elseif ($type === 'CREANCIER_DOSSIER') {
            $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(code,''),' — ',COALESCE(libelle,''))) label FROM creancier_dossier WHERE id_societe=? AND (code LIKE ? OR libelle LIKE ?) ORDER BY created_at DESC LIMIT 20");
            $st->execute([$soc, $like, $like]);
        } elseif ($type === 'SOCIETE') {
            $st = $pdo->prepare("SELECT id, nom label FROM societes WHERE nom LIKE ? ORDER BY nom LIMIT 20");
            $st->execute([$like]);
        } else {
            fo(['ok' => false, 'error' => 'Type non recherchable'], 400);
        }
        $res = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { fo(['ok' => false, 'error' => $e->getMessage()], 500); }
    fo(['ok' => true, 'results' => $res]);
}

/* ── Écritures (POST) ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fo(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('financement');
if (!$isAdmin) fo(['ok' => false, 'error' => 'Réservé administrateurs.'], 403);
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$dossierId = (int)($body['id_dossier'] ?? 0);
$d = fin_scope_ok($pdo, $dossierId);
if (!$d) fo(['ok' => false, 'error' => 'Dossier hors périmètre'], 403);

$op = (string)($body['op'] ?? '');
try {
    if ($op === 'add') {
        $ok = fin_link_add($pdo, $dossierId, (string)($body['type'] ?? ''), (int)($body['entity_id'] ?? 0), $body['role'] ?? null, $body['note'] ?? null);
        fo($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Lien invalide'], $ok ? 200 : 400);
    }
    if ($op === 'remove') {
        fin_link_remove($pdo, $dossierId, (string)($body['type'] ?? ''), (int)($body['entity_id'] ?? 0));
        fo(['ok' => true]);
    }
    fo(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) { fo(['ok' => false, 'error' => $e->getMessage()], 500); }
