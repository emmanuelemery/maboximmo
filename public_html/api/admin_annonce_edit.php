<?php
// api/admin_annonce_edit.php — Édition inline d'un champ annonces par admin
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo       = $GLOBALS['pdo'];
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$isAdmin      = in_array($roleId, [1, 2, 3], true);

if (!$isAdmin) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Accès réservé aux administrateurs']));
}

if (function_exists('verify_csrf_any')) {
    try { verify_csrf_any('admin_annonce_edit'); }
    catch (Throwable $e) { http_response_code(419); exit(json_encode(['ok' => false, 'error' => 'CSRF invalide'])); }
}

$idAnnonce = isset($_POST['id_annonce']) && ctype_digit((string)$_POST['id_annonce']) ? (int)$_POST['id_annonce'] : 0;
$field     = trim((string)($_POST['field'] ?? ''));
$value     = $_POST['value'] ?? '';

if ($idAnnonce <= 0) exit(json_encode(['ok' => false, 'error' => 'id_annonce manquant']));

// ─── Whitelist des champs éditables ──────────────────────────────
$editableFields = [
    // text
    'titre'              => ['type' => 'text', 'maxlen' => 255],
    'reference_annonce'  => ['type' => 'text', 'maxlen' => 50],
    'mandat_numero'      => ['type' => 'text', 'maxlen' => 50],
    'url_tarifs_publics' => ['type' => 'text', 'maxlen' => 2083],
    // number
    'prix'               => ['type' => 'decimal'],
    'loyer'              => ['type' => 'decimal'],
    'loyer_cc'           => ['type' => 'decimal'],
    'charges'            => ['type' => 'decimal'],
    'depot_garantie'     => ['type' => 'decimal'],
    'honoraires_location_bail'  => ['type' => 'decimal'],
    'honoraires_etat_des_lieux' => ['type' => 'decimal'],
    'taxe_fonciere'      => ['type' => 'decimal'],
    'taxe_habitation'    => ['type' => 'decimal'],
    'taxe_ordures_menageres' => ['type' => 'decimal'],
    // enum
    'type_transaction'   => ['type' => 'enum', 'values' => ['vente','location','saisonnier','viager','location_annuelle','location_saisonniere','cession_bail','fonds_commerce','vente_fonds','neuf','vefa','']],
    'statut'             => ['type' => 'enum', 'values' => ['brouillon','publiee','active','en_ligne','archivee','']],
    'etat_publication'   => ['type' => 'enum', 'values' => ['brouillon','diffusee','publiee','archivee','archived','']],
    'mandat_type'        => ['type' => 'enum', 'values' => ['exclusif','simple','']],
    // bool (0/1)
    'visible_portails'   => ['type' => 'bool'],
    'visible_site'       => ['type' => 'bool'],
    'visible_maboximmo'  => ['type' => 'bool'],
    'visible_site_perso' => ['type' => 'bool'],
    'exclusivite'        => ['type' => 'bool'],
    'coup_coeur'         => ['type' => 'bool'],
    'meuble'             => ['type' => 'bool'],
    'disponible_de_suite'=> ['type' => 'bool'],
    // FK (avec validation d'existence)
    'id_agence'          => ['type' => 'fk', 'table' => 'agences'],
    'id_societe'         => ['type' => 'fk', 'table' => 'societes'],
    'id_user'            => ['type' => 'fk', 'table' => 'users'],
    // date
    'date_mandat'        => ['type' => 'date'],
    'date_disponibilite' => ['type' => 'date'],
];

if (!isset($editableFields[$field])) {
    exit(json_encode(['ok' => false, 'error' => 'Champ non autorisé : ' . $field]));
}

// ─── Scope société : admin non-SA limité à sa société ────────────
try {
    $stSoc = $pdo->prepare("SELECT id_societe FROM annonces WHERE id = ?");
    $stSoc->execute([$idAnnonce]);
    $annSoc = (int)($stSoc->fetchColumn() ?: 0);
    if (!$isSuperAdmin && $societeId > 0 && $annSoc !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Annonce hors de votre société']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérif scope']));
}

// ─── Validation de la valeur selon le type ───────────────────────
$def  = $editableFields[$field];
$sqlValue = null;

switch ($def['type']) {
    case 'text':
        $s = substr(trim((string)$value), 0, (int)($def['maxlen'] ?? 255));
        $sqlValue = $s === '' ? null : $s;
        break;
    case 'decimal':
        $sqlValue = ($value === '' || $value === null) ? null : (float)str_replace(',', '.', (string)$value);
        break;
    case 'enum':
        $s = (string)$value;
        if (!in_array($s, $def['values'], true)) {
            exit(json_encode(['ok' => false, 'error' => 'Valeur enum invalide']));
        }
        $sqlValue = $s === '' ? null : $s;
        break;
    case 'bool':
        $sqlValue = (int)!!(int)$value;
        break;
    case 'date':
        $s = trim((string)$value);
        if ($s === '') { $sqlValue = null; break; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            exit(json_encode(['ok' => false, 'error' => 'Date invalide (YYYY-MM-DD attendu)']));
        }
        $sqlValue = $s;
        break;
    case 'fk':
        $v = (int)$value;
        $sqlValue = $v > 0 ? $v : null;
        if ($sqlValue !== null) {
            $tbl = $def['table'];
            if (!in_array($tbl, ['agences','societes','users','biens','mandats'], true)) {
                exit(json_encode(['ok' => false, 'error' => 'Table FK invalide']));
            }
            $stFk = $pdo->prepare("SELECT id FROM `{$tbl}` WHERE id = ? LIMIT 1");
            $stFk->execute([$sqlValue]);
            if (!$stFk->fetchColumn()) {
                exit(json_encode(['ok' => false, 'error' => 'FK inexistante dans ' . $tbl]));
            }
        }
        break;
}

// ─── UPDATE ──────────────────────────────────────────────────────
try {
    $st = $pdo->prepare("UPDATE annonces SET `{$field}` = :v, date_modification = NOW() WHERE id = :id");
    $st->execute([':v' => $sqlValue, ':id' => $idAnnonce]);

    // Re-lecture pour retourner la valeur normalisée (pour affichage côté JS)
    $stR = $pdo->prepare("SELECT `{$field}` FROM annonces WHERE id = ?");
    $stR->execute([$idAnnonce]);
    $final = $stR->fetchColumn();

    // Pour les FK, on retourne aussi le libellé pour affichage direct
    $display = null;
    if ($def['type'] === 'fk' && $final) {
        if ($def['table'] === 'agences') {
            $s = $pdo->prepare("SELECT nom_agence FROM agences WHERE id = ?");
            $s->execute([(int)$final]); $display = $s->fetchColumn();
        } elseif ($def['table'] === 'users') {
            $s = $pdo->prepare("SELECT CONCAT(prenom, ' ', nom) FROM users WHERE id = ?");
            $s->execute([(int)$final]); $display = $s->fetchColumn();
        } elseif ($def['table'] === 'societes') {
            $s = $pdo->prepare("SELECT COALESCE(NULLIF(raison_sociale,''), nom) FROM societes WHERE id = ?");
            $s->execute([(int)$final]); $display = $s->fetchColumn();
        }
    }

    error_log(sprintf('[admin_annonce_edit] annonce=%d field=%s value=%s user=%d', $idAnnonce, $field, (string)$final, (int)($_SESSION['user_id'] ?? 0)));
    exit(json_encode([
        'ok' => true,
        'id_annonce' => $idAnnonce,
        'field' => $field,
        'value' => $final,
        'display' => $display,
    ]));
} catch (Throwable $e) {
    error_log('[admin_annonce_edit] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
