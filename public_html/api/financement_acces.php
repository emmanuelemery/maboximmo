<?php
declare(strict_types=1);
/**
 * api/financement_acces.php — Participants & droits internes d'un dossier financier (T1).
 *   GET  ?op=search_user&q=...   → utilisateurs de la société
 *   GET  ?op=search_tiers&q=...  → tiers (propriétaire/avocat/comptable/notaire…)
 *   POST {op:'add', id_dossier, identite_type, identite_id, role_intervenant, niveau, perimetre?}
 *   POST {op:'remove', id_dossier, acces_id}
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/financement.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
function fo(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$pdo = $GLOBALS['pdo'];
$soc = fin_soc();
$isAdmin = (function_exists('is_super_admin') && is_super_admin()) || in_array((int)current_role_id(), [1, 7], true);

$op = (string)($_GET['op'] ?? '');
if ($op === 'search_user' || $op === 'search_tiers') {
    $like = '%' . trim((string)($_GET['q'] ?? '')) . '%';
    if ($op === 'search_user') {
        $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) label, fonction FROM users WHERE id_societe=? AND actif=1 AND (prenom LIKE ? OR nom LIKE ?) ORDER BY prenom LIMIT 20");
        $st->execute([$soc, $like, $like]);
    } else {
        $st = $pdo->prepare("SELECT id, COALESCE(nom_affichage, CONCAT(COALESCE(nom,''),' ',COALESCE(prenom,''))) label, email FROM tiers WHERE id_societe=? AND (nom_affichage LIKE ? OR nom LIKE ? OR raison_sociale LIKE ?) ORDER BY nom_affichage LIMIT 20");
        $st->execute([$soc, $like, $like, $like]);
    }
    fo(['ok' => true, 'results' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fo(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('financement');
if (!$isAdmin) fo(['ok' => false, 'error' => 'Réservé administrateurs.'], 403);
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$dossierId = (int)($body['id_dossier'] ?? 0);
if (!fin_scope_ok($pdo, $dossierId)) fo(['ok' => false, 'error' => 'Dossier hors périmètre'], 403);
$op = (string)($body['op'] ?? '');

try {
    if ($op === 'add') {
        $role = (string)($body['role_intervenant'] ?? 'regie');
        $niveau = (string)($body['niveau'] ?? 'lecture');
        if (!isset(fin_role_labels()[$role]) || !isset(fin_niveau_labels()[$niveau])) fo(['ok' => false, 'error' => 'Rôle/niveau invalide'], 400);
        $ok = fin_acces_add($pdo, $dossierId, (string)($body['identite_type'] ?? 'user'), (int)($body['identite_id'] ?? 0), $role, $niveau, (string)($body['perimetre'] ?? 'tout'));
        fo($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Identité invalide'], $ok ? 200 : 400);
    }
    if ($op === 'remove') {
        fin_acces_remove($pdo, $dossierId, (int)($body['acces_id'] ?? 0));
        fo(['ok' => true]);
    }
    fo(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) { fo(['ok' => false, 'error' => $e->getMessage()], 500); }
