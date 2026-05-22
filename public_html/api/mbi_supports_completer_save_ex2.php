<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * api/mbi_supports_completer_save.php
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Endpoint AJAX du LOT 1 (modale "Compléter les mentions légales").
 *
 * Reçoit :
 *   POST id_bien      : int
 *   POST type_support : string (par défaut affiche_vitrine)
 *   POST fields[<entity>.<table>.<colonne>] = valeur
 *
 * Renvoie JSON :
 *   { ok, peut_exporter, blocs_durs_violes:[…], saved:{biens:[…], agences:[…], mandats:[…]}, ignored:[…] }
 *
 * Sécurité :
 *   - Session connectée requise
 *   - Scope multi-tenant : id_societe du bien doit matcher la session
 *     (super-admin role=1 bypass)
 *   - Whitelist : on n'écrit QUE dans les colonnes définies dans
 *     mbi_supports_completer_fields_map() (impossible d'écrire ailleurs)
 *   - Existence des colonnes vérifiée (INFORMATION_SCHEMA) — si une colonne
 *     n'existe pas dans la base courante, on la skippe avec note dans `ignored`
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
require_once dirname(__DIR__) . '/inc/mbi_supports_critic_engine.php';
require_once dirname(__DIR__) . '/inc/mbi_supports_completer_fields.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('mbi_supports_completer_jsend')) {
    function mbi_supports_completer_jsend(int $http, array $payload): void {
        http_response_code($http);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mbi_supports_completer_jsend(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// CSRF — le form de la modale envoie le token sous le nom 'csrf_token'
// (cf. card_bien.php, ajouté dans cette même session)
if (function_exists('verify_csrf_any')) {
    verify_csrf_any('ajouter_bien');
}


$idBien      = (int)($_POST['id_bien'] ?? 0);
$typeSupport = (string)($_POST['type_support'] ?? 'affiche_vitrine');
$fieldsIn    = $_POST['fields'] ?? [];

if ($idBien <= 0) {
    mbi_supports_completer_jsend(400, ['ok' => false, 'error' => 'id_bien_required']);
}
if (!is_array($fieldsIn)) {
    mbi_supports_completer_jsend(400, ['ok' => false, 'error' => 'fields_must_be_array']);
}

$pdo = db();

// ─── Charge le bien et vérifie le scope ──────────────────────────────────
try {
    $st = $pdo->prepare("SELECT id, id_societe, id_agence FROM biens WHERE id = :id LIMIT 1");
    $st->execute([':id' => $idBien]);
    $bien = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    mbi_supports_completer_jsend(500, ['ok' => false, 'error' => 'db_read_bien: ' . $e->getMessage()]);
}
if (!$bien) {
    mbi_supports_completer_jsend(404, ['ok' => false, 'error' => 'bien_not_found']);
}

$roleId       = (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSocSess    = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idSocBien    = (int)($bien['id_societe'] ?? 0);
if (!$isSuperAdmin && $idSocSess !== null && $idSocBien > 0 && $idSocBien !== $idSocSess) {
    mbi_supports_completer_jsend(403, ['ok' => false, 'error' => 'scope_violation']);
}

// ─── Whitelist : union de tous les contextes (le contexte sert au RENDU,
// pas à la sécurité — au save on accepte tout champ déclaré dans la map).
$whitelist = []; // "table.column" => définition
foreach ([['transaction'=>'vente'], ['transaction'=>'location'], ['transaction'=>'entreprise']] as $c) {
    foreach (mbi_supports_completer_fields_map($c) as $defs) {
        foreach ($defs as $def) {
            if (empty($def['table']) || empty($def['column'])) continue;
            $whitelist[$def['table'] . '.' . $def['column']] = $def;
        }
    }
}

// ─── Construit la liste des colonnes existantes par table ────────────────
function mbi_supports_completer_columns_of(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $st->execute([':t' => $table]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable) { $rows = []; }
    $cache[$table] = array_map('strtolower', $rows);
    return $cache[$table];
}

// ─── Ventile les valeurs reçues par entité cible + lookup des id liés ────
$idsParEntite = [
    'biens'    => $idBien,
    'agences'  => (int)($bien['id_agence'] ?? 0),
    'mandats'  => 0,
    'annonces' => 0, // pour les champs honoraires location stockés sur annonces
    'users'    => 0, // négociateur du bien
];

// Charge le mandat actif (id), l'annonce active et le négociateur courant
try {
    $st = $pdo->prepare("SELECT id FROM mandats
                         WHERE id_bien = :b
                         AND (statut = 'actif' OR statut = 'en_cours' OR statut IS NULL)
                         ORDER BY id DESC LIMIT 1");
    $st->execute([':b' => $idBien]);
    $idsParEntite['mandats'] = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

try {
    $st = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = :b
                         ORDER BY date_modification DESC, id DESC LIMIT 1");
    $st->execute([':b' => $idBien]);
    $idsParEntite['annonces'] = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

try {
    $st = $pdo->prepare("SELECT id_user_actuel FROM biens WHERE id = :b LIMIT 1");
    $st->execute([':b' => $idBien]);
    $idsParEntite['users'] = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// ─── Bucketise les valeurs par table en respectant la whitelist ──────────
$buckets = []; // table => [column => value]
$ignored = [];

foreach ($fieldsIn as $key => $value) {
    // Format attendu : "table.column" (ex: "biens.prix_vente")
    if (!is_string($key) || !str_contains($key, '.')) {
        $ignored[] = ['key' => (string)$key, 'reason' => 'malformed_key'];
        continue;
    }
    [$table, $column] = explode('.', $key, 2);
    $whKey = $table . '.' . $column;
    if (!isset($whitelist[$whKey])) {
        $ignored[] = ['key' => $whKey, 'reason' => 'not_in_whitelist'];
        continue;
    }
    if (!isset($idsParEntite[$table]) || $idsParEntite[$table] <= 0) {
        $ignored[] = ['key' => $whKey, 'reason' => 'no_target_id_for_' . $table];
        continue;
    }
    $existingCols = mbi_supports_completer_columns_of($pdo, $table);
    if (!in_array(strtolower($column), $existingCols, true)) {
        $ignored[] = ['key' => $whKey, 'reason' => 'column_missing_in_db'];
        continue;
    }
    // Cast selon type
    $def = $whitelist[$whKey];
    $clean = mbi_supports_completer_cast($value, $def['type'] ?? 'text');
    $buckets[$table][$column] = $clean;
}

// ─── UPDATE par table (transaction) ──────────────────────────────────────
$saved = ['biens' => [], 'agences' => [], 'mandats' => [], 'users' => []];

// Si TOUTES les valeurs ont été ignorées (cas typique : le user coche "Oui"
// sur des champs MANDAT mais aucun mandat n'est rattaché au bien), on
// retourne un message clair AU LIEU de save silencieux qui boucle.
if (empty($buckets) && !empty($ignored) && !empty($fieldsIn)) {
    $missingTargets = [];
    foreach ($ignored as $ig) {
        if (str_starts_with((string)($ig['reason'] ?? ''), 'no_target_id_for_')) {
            $tableManquante = substr($ig['reason'], strlen('no_target_id_for_'));
            $missingTargets[$tableManquante] = true;
        }
    }
    if (!empty($missingTargets)) {
        $details = [];
        if (isset($missingTargets['mandats'])) {
            $details[] = 'Aucun mandat actif rattaché au bien — crée un mandat avant de cocher cette case (pour un bien en location, un mandat de gestion locative inclut automatiquement l\'autorisation de diffusion).';
        }
        if (isset($missingTargets['annonces'])) {
            $details[] = 'Aucune annonce active sur ce bien.';
        }
        if (isset($missingTargets['users'])) {
            $details[] = 'Aucun négociateur attribué à ce bien.';
        }
        mbi_supports_completer_jsend(409, [
            'ok'                  => false,
            'error'               => 'no_target_entities',
            'message'             => implode(' ', $details),
            'missing_targets'     => array_keys($missingTargets),
            'ignored'             => $ignored,
            'help_create_mandat'  => isset($missingTargets['mandats']) ? 'agency_mandant_form.php?id_bien=' . $idBien : null,
        ]);
    }
}

try {
    $pdo->beginTransaction();
    foreach ($buckets as $table => $cols) {
        if (empty($cols)) continue;
        $id = $idsParEntite[$table] ?? 0;
        if ($id <= 0) continue;
        $sets = [];
        $params = [':id' => $id];
        foreach ($cols as $c => $v) {
            $sets[] = "`{$c}` = :v_" . $c;
            $params[':v_' . $c] = $v;
        }
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $saved[$table] = array_keys($cols);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mbi_supports_completer_jsend(500, [
        'ok' => false, 'error' => 'db_update_failed: ' . $e->getMessage(),
        'saved' => $saved, 'ignored' => $ignored,
    ]);
}

// ─── Relance le critic check pour renvoyer le nouvel état ────────────────
$critique = mbi_supports_critic_check($idBien, $typeSupport);

mbi_supports_completer_jsend(200, [
    'ok'                => true,
    'peut_exporter'     => (bool)($critique['peut_exporter'] ?? false),
    'mentions_version'  => $critique['mentions_version'] ?? null,
    'blocs_durs_violes' => $critique['blocs_durs_violes'] ?? [],
    'alertes'           => $critique['alertes'] ?? [],
    'saved'             => $saved,
    'ignored'           => $ignored,
]);

// ═════════════════════════════════════════════════════════════════════════
// Cast des valeurs reçues selon le type déclaré
// ═════════════════════════════════════════════════════════════════════════
function mbi_supports_completer_cast($v, string $type)
{
    if ($v === '' || $v === null) {
        // Préserve null pour les champs vides (ne force pas '0' sur number)
        return null;
    }
    return match ($type) {
        'number'   => is_numeric($v) ? (float)$v : null,
        'checkbox' => (in_array($v, ['1', 1, true, 'on', 'true', 'oui'], true)) ? 1 : 0,
        'date'     => preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$v) ? substr((string)$v, 0, 10) : null,
        default    => trim((string)$v),
    };
}
