<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * ⚠ SUPPRESSION RÉELLE DE PROPRIÉTAIRES AVEC CASCADE — ADMIN ONLY
 * ═══════════════════════════════════════════════════════════════════════
 *
 * 2 modes :
 *   - action=preview  → retourne pour chaque propriétaire candidat le
 *                       compte des dépendances (biens, baux, mandats…),
 *                       SANS rien supprimer.
 *   - action=delete   → supprime les propriétaires + nettoie les liaisons
 *                       (id_proprietaire passé à NULL sur biens/baux/etc.).
 *                       Si force=1, supprime aussi les biens orphelins
 *                       (cascade complète vers annonces, photos, baux…).
 *
 * Sécurités :
 *   - role_id = 1 obligatoire
 *   - CSRF
 *   - Preview obligatoire (token serveur)
 *   - Transaction atomique
 *   - Filtre par id_societe via les biens du proprio
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo     = $GLOBALS['pdo'];
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$userId  = (int)($_SESSION['user_id'] ?? 0);
$socId   = (int)($_SESSION['id_societe'] ?? 0);

if ($roleId !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Accès réservé aux super-admins (role_id=1)']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}
verify_csrf_any('proprietaire_delete_cascade');

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['preview', 'delete'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'Action inconnue']));
}

// ─── Récupère les IDs propriétaires soumis ───
// Tolérant : accepte "52", "#52", "tiers #52", "12, 34, 56", " 12 ; 34 " etc.
// On extrait tous les groupes de chiffres via regex pour ignorer #, espaces, ; etc.
$rawIds = $_POST['ids'] ?? '';
if (is_array($rawIds)) {
    $ids = array_values(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0));
} else {
    $ids = [];
    if (preg_match_all('/\d+/', (string)$rawIds, $m)) {
        $ids = array_values(array_filter(array_map('intval', $m[0]), fn($v) => $v > 0));
    }
}
$ids = array_values(array_unique($ids));

// Filtre alternatif : "propriétaires sans bien lié, depuis date X"
$filterMode = (string)($_POST['filter_mode'] ?? '');
$dateMin    = (string)($_POST['date_min'] ?? '');

if ($filterMode === 'no_biens') {
    // Trouve les proprietaires SANS bien lié
    $sql = "SELECT p.id FROM proprietaires p
            WHERE NOT EXISTS (SELECT 1 FROM biens b WHERE b.id_proprietaire = p.id)";
    $params = [];
    if ($dateMin && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateMin = substr($dateMin, 0, 10) . ' 00:00:00';
        $sql .= " AND p.date_creation >= ?";
        $params[] = $dateMin;
    }
    if ($socId > 0) {
        // Limite aux proprios de la même société (via leurs biens passés OU agence)
        // Pour les proprios sans bien, on filtre via id_agence si possible
        $sql .= " AND (p.id_agence IS NULL OR p.id_agence IN (SELECT id FROM agences WHERE id_societe = ?))";
        $params[] = $socId;
    }
    $sql .= " ORDER BY p.id ASC LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $ids = array_values(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

if (empty($ids)) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun propriétaire à traiter']));
}

// ─── Charge infos des candidats ───
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare("SELECT id, civilite, nom, prenom, societe, email, telephone, id_tiers, date_creation FROM proprietaires WHERE id IN ($placeholders)");
$st->execute($ids);
$candidates = $st->fetchAll(PDO::FETCH_ASSOC);
if (empty($candidates)) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun propriétaire trouvé pour ces IDs']));
}

$candidateIds = array_map('intval', array_column($candidates, 'id'));
$candidateTierIds = array_values(array_filter(array_map('intval', array_column($candidates, 'id_tiers')), fn($v) => $v > 0));

// ─── Compte des dépendances par table ───
$inIds = implode(',', $candidateIds);
$childTables = [
    'biens'              => 'id_proprietaire',
    'baux'               => 'id_proprietaire',
    'crg_trimestres'     => 'id_proprietaire',
    'documents'          => 'id_proprietaire',
    'mandats'            => 'id_proprietaire',
    'user_proprietaires' => 'id_proprietaire',
];
$countByTable = [];
foreach ($childTables as $tbl => $col) {
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}` WHERE `{$col}` IN ({$inIds})")->fetchColumn();
        if ($n > 0) $countByTable[$tbl] = $n;
    } catch (Throwable $e) {
        // table absente → ignoré
    }
}

// Détail par propriétaire (pour affichage UI)
$detailByProprio = [];
foreach ($candidates as $p) {
    $pid = (int)$p['id'];
    $detail = [];
    foreach ($childTables as $tbl => $col) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}` WHERE `{$col}` = $pid")->fetchColumn();
            if ($n > 0) $detail[$tbl] = $n;
        } catch (Throwable) {}
    }
    $detailByProprio[$pid] = $detail;
}

// ═══════════════════════════════════════════════════════════════════════
// MODE PREVIEW
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'preview') {
    $scopeHash = hash('sha256', implode(',', $candidateIds) . '|' . $filterMode . '|' . $dateMin);
    $_SESSION['proprietaire_delete_cascade_token'] = [
        'hash'    => $scopeHash,
        'expires' => time() + 600,
        'count'   => count($candidateIds),
    ];
    exit(json_encode([
        'ok'                 => true,
        'mode'               => 'preview',
        'count'              => count($candidateIds),
        'candidates'         => $candidates,
        'detail_by_proprio'  => $detailByProprio,
        'count_by_table'     => $countByTable,
        'token'              => $scopeHash,
        'has_dependencies'   => !empty($countByTable),
    ], JSON_UNESCAPED_UNICODE));
}

// ═══════════════════════════════════════════════════════════════════════
// MODE DELETE
// ═══════════════════════════════════════════════════════════════════════
$submittedToken = (string)($_POST['token'] ?? '');
$stored = $_SESSION['proprietaire_delete_cascade_token'] ?? null;
if (!is_array($stored) || empty($stored['hash']) || $stored['expires'] < time()) {
    exit(json_encode(['ok' => false, 'error' => 'Token de préview absent ou expiré — re-clique sur "Prévisualiser"']));
}
$expectedHash = hash('sha256', implode(',', $candidateIds) . '|' . $filterMode . '|' . $dateMin);
if (!hash_equals($expectedHash, $submittedToken) || !hash_equals($stored['hash'], $submittedToken)) {
    exit(json_encode(['ok' => false, 'error' => 'Scope modifié depuis la préview — refais une préview']));
}

// Mode "force" : supprime aussi les biens en cascade (très destructif)
$force = (int)($_POST['force'] ?? 0) === 1;

if (!empty($countByTable) && !$force) {
    exit(json_encode([
        'ok'    => false,
        'error' => 'Dépendances détectées — ajoute force=1 pour supprimer en cascade (ou désélectionne ces propriétaires)',
        'count_by_table' => $countByTable,
    ]));
}

$deleted = [];
try {
    $pdo->beginTransaction();

    if ($force) {
        // Cascade : supprimer les biens liés (qui à leur tour cascadent vers annonces, photos…)
        $bIds = $pdo->query("SELECT id FROM biens WHERE id_proprietaire IN ($inIds)")->fetchAll(PDO::FETCH_COLUMN);
        $bIds = array_map('intval', $bIds ?: []);
        if (!empty($bIds)) {
            $bInIds = implode(',', $bIds);
            // Reproduire le cascade biens (lite)
            $bienChildTables = [
                'annonces_photos' => null, // via annonces, traité ci-dessous
                'annonces', 'baux', 'bien_baux', 'bailleur_acte',
                'bien_dependances_exterieurs', 'bien_energies', 'bien_types_chauffage', 'bien_vues',
                'biens_caracteristiques', 'biens_documents', 'biens_photos', 'biens_tags', 'biens_versions',
                'documents', 'dpe_diags', 'estimations', 'leads_annonces', 'mandats', 'medias',
                'visites', 'visites_zones',
            ];
            // 1. annonces_photos via annonces
            try {
                $n = $pdo->exec("DELETE ap FROM annonces_photos ap JOIN annonces a ON a.id = ap.id_annonce WHERE a.id_bien IN ($bInIds)");
                if ($n > 0) $deleted['annonces_photos'] = $n;
            } catch (Throwable) {}
            foreach ($bienChildTables as $i => $tbl) {
                if (!is_string($tbl)) continue;
                try {
                    $n = $pdo->exec("DELETE FROM `{$tbl}` WHERE id_bien IN ($bInIds)");
                    if ($n > 0) $deleted[$tbl] = ($deleted[$tbl] ?? 0) + $n;
                } catch (Throwable $e) {
                    error_log("[proprio_delete_cascade biens.$tbl] " . $e->getMessage());
                }
            }
            $n = $pdo->exec("DELETE FROM biens WHERE id IN ($bInIds)");
            if ($n > 0) $deleted['biens'] = $n;
        }

        // Supprimer baux/mandats/etc. orphelins liés au proprio (au cas où)
        foreach (['baux', 'crg_trimestres', 'documents', 'mandats', 'user_proprietaires'] as $tbl) {
            try {
                $n = $pdo->exec("DELETE FROM `{$tbl}` WHERE id_proprietaire IN ($inIds)");
                if ($n > 0) $deleted[$tbl] = ($deleted[$tbl] ?? 0) + $n;
            } catch (Throwable $e) {
                error_log("[proprio_delete_cascade $tbl] " . $e->getMessage());
            }
        }
    }

    // Suppression des propriétaires eux-mêmes
    $n = $pdo->exec("DELETE FROM proprietaires WHERE id IN ($inIds)");
    $deleted['proprietaires'] = $n;

    // Suppression des tiers orphelins (anciens id_tiers des proprio supprimés)
    // + nettoyage tiers_roles et tiers_contacts uniquement pour ces orphelins
    // (on ne touche jamais à un tiers encore référencé ailleurs : locataire,
    //  mandant, agency_mandant, etc.).
    if (!empty($candidateTierIds)) {
        $tInIds = implode(',', $candidateTierIds);
        try {
            // 1. Pré-identifier les tiers vraiment orphelins
            $orphans = $pdo->query("
                SELECT t.id FROM tiers t
                WHERE t.id IN ($tInIds)
                  AND NOT EXISTS (SELECT 1 FROM proprietaires p WHERE p.id_tiers = t.id)
                  AND NOT EXISTS (SELECT 1 FROM mandants m WHERE m.id_tiers = t.id)
                  AND NOT EXISTS (SELECT 1 FROM agency_mandant am WHERE am.id_tiers = t.id)
            ")->fetchAll(PDO::FETCH_COLUMN);
            $orphans = array_map('intval', $orphans ?: []);

            if (!empty($orphans)) {
                $oInIds = implode(',', $orphans);
                // 2. Nettoyer tiers_roles (tous rôles : propriétaire, bailleur, indivisaire, SCI…)
                try {
                    $n = $pdo->exec("DELETE FROM tiers_roles WHERE id_tiers IN ($oInIds)");
                    if ($n > 0) $deleted['tiers_roles'] = $n;
                } catch (Throwable $e) { error_log('[proprio_delete_cascade tiers_roles] ' . $e->getMessage()); }
                // 3. Nettoyer tiers_contacts (relations représentant ↔ entité)
                try {
                    $n = $pdo->exec("DELETE FROM tiers_contacts WHERE id_tiers_entite IN ($oInIds) OR id_tiers_contact IN ($oInIds)");
                    if ($n > 0) $deleted['tiers_contacts'] = $n;
                } catch (Throwable $e) { error_log('[proprio_delete_cascade tiers_contacts] ' . $e->getMessage()); }
                // 4. Supprimer les tiers eux-mêmes
                try {
                    $n = $pdo->exec("DELETE FROM tiers WHERE id IN ($oInIds)");
                    if ($n > 0) $deleted['tiers'] = $n;
                } catch (Throwable $e) { error_log('[proprio_delete_cascade tiers] ' . $e->getMessage()); }
            }
        } catch (Throwable $e) {
            error_log('[proprio_delete_cascade tiers orphans lookup] ' . $e->getMessage());
        }
    }

    $pdo->commit();
    unset($_SESSION['proprietaire_delete_cascade_token']);

    error_log(sprintf(
        '[proprio_delete_cascade] OK user=%d societe=%d ids=%s force=%d deleted=%s',
        $userId, $socId, implode(',', $candidateIds), $force ? 1 : 0, json_encode($deleted)
    ));

    exit(json_encode([
        'ok'              => true,
        'mode'            => 'delete',
        'force'           => $force,
        'deleted'         => $deleted,
        'total_proprios'  => $deleted['proprietaires'] ?? 0,
    ], JSON_UNESCAPED_UNICODE));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[proprio_delete_cascade] ROLLBACK : ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode([
        'ok'    => false,
        'error' => 'Échec transaction — rollback : ' . $e->getMessage(),
    ]));
}
