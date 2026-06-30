<?php
/**
 * api/fluxbox_entity_search.php — Correctif 7 : recherche UNIVERSELLE typée (multi-entités).
 *
 * UN seul champ → tous les types : bien, immeuble, societe, tiers (propriétaire/locataire),
 * collaborateur (user). Réutilise les patterns existants (quick_search / ged_inbox_entity_search)
 * + em_normalize_for_search(). Résultats TYPÉS avec badge + 2 repères, rendus en BOUTONS cascade.
 *
 * GET  q=<fragment>  [types=bien,immeuble,societe,tiers,user]
 * →    {ok, results:[{entity_type, badge, id, label, repere1, repere2, score}]}
 *
 * Multi-tenant : périmètre société/agence du user (super admin = tout).
 * Sécurité : login.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/entity_matcher.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true,'results'=>[]]); exit; }
$norm = em_normalize_for_search($q);
$like = '%' . $norm . '%';
$prefix = $norm . '%';

$typesReq = array_filter(array_map('trim', explode(',', (string)($_GET['types'] ?? 'bien,immeuble,societe,tiers,user'))));
$want = static fn(string $t): bool => in_array($t, $typesReq, true);

// ── Périmètre multi-tenant ───────────────────────────────────────────────
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isSuper = in_array($roleId, [1,7], true) || (function_exists('is_super_admin') && is_super_admin());
$ctx = function_exists('ged_v3_get_user_context') ? ged_v3_get_user_context($pdo) : [];
$socId = (int)($ctx['societe_id'] ?? 0);
$ageId = (int)($ctx['agence_id'] ?? 0);
// Manager (2) = société ; collaborateur (3) = agence ; sinon société.
$scopeAgence = ($roleId === 3 && $ageId > 0);

$results = [];

/** Helper : ajoute une clause de périmètre société/agence si non super admin. */
$scope = function(string $alias) use ($isSuper, $socId, $scopeAgence, $ageId): array {
    if ($isSuper) return ['', []];
    $sql = ''; $args = [];
    if ($socId > 0) { $sql .= " AND ({$alias}.id_societe = ? OR {$alias}.id_societe IS NULL)"; $args[] = $socId; }
    if ($scopeAgence) { $sql .= " AND ({$alias}.id_agence = ? OR {$alias}.id_agence IS NULL)"; $args[] = $ageId; }
    return [$sql, $args];
};

try {
    // ── BIENS ──────────────────────────────────────────────────────────
    if ($want('bien')) {
        [$sc, $scArgs] = $scope('b');
        $sql = "SELECT b.id, b.reference_bien, b.designation,
                       COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
                       COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
                       (SELECT COALESCE(NULLIF(tl.nom_affichage,''), bb.locataire_raison_sociale,
                                NULLIF(TRIM(CONCAT_WS(' ',bb.locataire_prenom,bb.locataire_nom)),''))
                          FROM bien_baux bb LEFT JOIN tiers tl ON tl.id=bb.id_tiers_locataire
                         WHERE bb.id_bien=b.id ORDER BY (bb.statut='actif') DESC, bb.date_prise_effet DESC LIMIT 1) AS loc,
                       (SELECT COALESCE(NULLIF(p.societe,''), NULLIF(TRIM(CONCAT_WS(' ',p.prenom,p.nom)),''))
                          FROM proprietaires p WHERE p.id=b.id_proprietaire LIMIT 1) AS proprio
                FROM biens b LEFT JOIN immeubles i ON i.id=b.id_immeuble
                WHERE (b.reference_bien LIKE ? OR b.designation LIKE ? OR b.adresse_1 LIKE ?
                       OR i.adresse_1 LIKE ? OR b.ville LIKE ?
                       OR EXISTS(SELECT 1 FROM bien_baux bb2 LEFT JOIN tiers tl2 ON tl2.id=bb2.id_tiers_locataire
                                 WHERE bb2.id_bien=b.id AND (bb2.locataire_nom LIKE ? OR tl2.nom_affichage LIKE ?)))
                      {$sc}
                ORDER BY (b.reference_bien LIKE ?) DESC, b.id DESC LIMIT 8";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$like,$like,$like,$like,$like,$like,$like], $scArgs, [$prefix]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $adr = trim((string)$r['adresse'] . ' · ' . (string)$r['ville'], ' ·');
            $rep2 = [];
            if (!empty($r['loc']))    $rep2[] = '🔑 ' . $r['loc'];
            if (!empty($r['proprio'])) $rep2[] = '👤 ' . $r['proprio'];
            $results[] = ['entity_type'=>'bien','badge'=>'Bien','id'=>(int)$r['id'],
                'label'=>trim((string)($r['designation'] ?: $adr ?: ('Bien #'.$r['id']))),
                'repere1'=>$adr, 'repere2'=>implode(' · ', $rep2), 'score'=>0];
        }
    }

    // ── IMMEUBLES ──────────────────────────────────────────────────────
    if ($want('immeuble')) {
        [$sc, $scArgs] = $scope('i');
        $sql = "SELECT i.id, i.reference_immeuble, i.nom_immeuble, i.adresse_1, i.code_postal, i.ville
                FROM immeubles i
                WHERE (i.nom_immeuble LIKE ? OR i.reference_immeuble LIKE ? OR i.adresse_1 LIKE ? OR i.ville LIKE ?)
                      {$sc}
                ORDER BY (i.nom_immeuble LIKE ?) DESC, i.id DESC LIMIT 6";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$like,$like,$like,$like], $scArgs, [$prefix]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $adr = trim((string)$r['adresse_1'].' · '.(string)$r['code_postal'].' '.(string)$r['ville'], ' ·');
            $results[] = ['entity_type'=>'immeuble','badge'=>'Immeuble','id'=>(int)$r['id'],
                'label'=>(string)($r['nom_immeuble'] ?: $r['adresse_1'] ?: ('Immeuble #'.$r['id'])),
                'repere1'=>$adr, 'repere2'=>(string)($r['reference_immeuble'] ?? ''), 'score'=>0];
        }
    }

    // ── SOCIÉTÉS ───────────────────────────────────────────────────────
    if ($want('societe')) {
        $sql = "SELECT id, nom, raison_sociale, siren, ville FROM societes
                WHERE (nom LIKE ? OR raison_sociale LIKE ? OR REPLACE(siren,' ','') LIKE ?)
                ORDER BY (nom LIKE ?) DESC, id DESC LIMIT 5";
        $st = $pdo->prepare($sql);
        $st->execute([$like,$like,$like,$prefix]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = ['entity_type'=>'societe','badge'=>'Société','id'=>(int)$r['id'],
                'label'=>(string)($r['nom'] ?: $r['raison_sociale']),
                'repere1'=>(string)($r['ville'] ?? ''), 'repere2'=>$r['siren'] ? 'SIREN '.$r['siren'] : '', 'score'=>0];
        }
    }

    // ── TIERS (propriétaire / locataire / autre — badge selon rôle) ─────
    if ($want('tiers') || $want('proprietaire') || $want('locataire')) {
        [$sc, $scArgs] = $scope('t');
        $sql = "SELECT t.id, t.nom_affichage, t.nom, t.prenom, t.raison_sociale, t.ville, t.siren,
                       (SELECT GROUP_CONCAT(DISTINCT tr.role_code) FROM tiers_roles tr WHERE tr.id_tiers=t.id AND tr.actif=1) AS roles
                FROM tiers t
                WHERE t.actif=1 AND (LOWER(CONCAT_WS(' ',t.prenom,t.nom)) LIKE ? OR t.nom LIKE ?
                       OR t.raison_sociale LIKE ? OR t.nom_affichage LIKE ? OR REPLACE(t.siren,' ','') LIKE ?)
                      {$sc}
                ORDER BY (t.nom_affichage LIKE ?) DESC, t.id DESC LIMIT 8";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$like,$like,$like,$like,$like], $scArgs, [$prefix]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roles = strtolower((string)($r['roles'] ?? ''));
            $badge = str_contains($roles,'proprietaire') ? 'Propriétaire'
                   : (str_contains($roles,'locataire') ? 'Locataire' : 'Tiers');
            $results[] = ['entity_type'=>'tiers','badge'=>$badge,'id'=>(int)$r['id'],
                'label'=>(string)($r['nom_affichage'] ?: trim(($r['prenom']??'').' '.($r['nom']??'')) ?: $r['raison_sociale']),
                'repere1'=>(string)($r['ville'] ?? ''), 'repere2'=>$r['siren'] ? 'SIREN '.$r['siren'] : '', 'score'=>0];
        }
    }

    // ── COLLABORATEURS (users) ─────────────────────────────────────────
    if ($want('user') || $want('collaborateur')) {
        [$sc, $scArgs] = $scope('u');
        $sql = "SELECT u.id, u.prenom, u.nom, u.fonction FROM users u
                WHERE u.actif=1 AND LOWER(CONCAT_WS(' ',u.prenom,u.nom)) LIKE ? {$sc}
                ORDER BY u.nom LIMIT 6";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$like], $scArgs));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $results[] = ['entity_type'=>'user','badge'=>'Collaborateur','id'=>(int)$r['id'],
                'label'=>trim((string)($r['prenom'] ?? '').' '.(string)($r['nom'] ?? '')),
                'repere1'=>(string)($r['fonction'] ?? ''), 'repere2'=>'', 'score'=>0];
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}

// ── Boost par ancres du document (adresse → biens en tête, etc.) ──────────
$anchorAdr  = em_normalize_for_search((string)($_GET['anchor_adresse'] ?? ''));
$anchorType = (string)($_GET['anchor_type'] ?? ''); // 'adresse'|'siren'|'nom'
foreach ($results as &$r) {
    // exact prefix match du label = boost
    if (str_starts_with(em_normalize_for_search((string)$r['label']), $norm)) $r['score'] += 20;
    if ($anchorAdr !== '' && in_array($r['entity_type'], ['bien','immeuble'], true)) $r['score'] += 15;
    if ($anchorType === 'siren' && $r['entity_type'] === 'societe') $r['score'] += 15;
    if ($anchorType === 'nom' && in_array($r['entity_type'], ['tiers','user'], true)) $r['score'] += 10;
}
unset($r);
usort($results, static fn($a,$b) => $b['score'] <=> $a['score']);

echo json_encode(['ok'=>true,'results'=>array_slice($results, 0, 20)], JSON_UNESCAPED_UNICODE);
