<?php
/**
 * bailleur_patrimoine_actif.php — État du patrimoine actif
 * Fusion de : bailleur_dashboard.php + gestion/patrimoine_sir.php + tableau_proprietaires.php
 * Accès : super admin uniquement (import CRG = régie)
 * 3 niveaux par immeuble : Actifs | Partis pliés (archivables) | Archivés pliés
 * Immeubles vendus archivés en bas (pliés)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();
// Fusion de doublons réservée au staff manager (cf. admin_biens_merge_action.php)
$canMerge     = $isSuperAdmin || in_array($roleId, [1, 2, 7], true);
// MODE d'affichage : 'patrimoine' (défaut) ou 'portefeuille' (= MÊME liste, mais
// lignes avec cases de sélection + boutons À vendre / Pas à vendre). L'onglet
// « Portefeuille à vendre » charge cette page en ?mode=portefeuille → liste identique garantie.
$mode = in_array(($_GET['mode'] ?? ''), ['proposer','portefeuille'], true) ? 'proposer' : 'patrimoine';

// ── Accès : super admin OU service bailleur ─────────────
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403);
    exit('Accès réservé au module Bailleur.');
}

// ── Filtre propriétaires ─────────────────────────────────
// Priorité : 1) GET props[]  2) Session bailleur_props  3) Tous
$propFilterWhere = '';
if ($isSuperAdmin) {
    $getProps = [];
    // Priorité 0 : "voir en tant que" un bailleur (hub ?bailleur=ID) → ses propriétaires assignés.
    $asBailleur = isset($_GET['bailleur']) ? (int)$_GET['bailleur'] : 0;
    if ($asBailleur > 0) {
        $stB = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
        $stB->execute([$asBailleur]);
        foreach ($stB->fetchAll(\PDO::FETCH_COLUMN) as $sid) { $sid = (int)$sid; if ($sid > 0) $getProps[] = $sid; }
    } elseif (isset($_GET['props'])) {
        // GET explicite → mémoriser en session
        foreach ((array)$_GET['props'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $getProps[] = $sid;
        }
        $_SESSION['bailleur_props'] = $getProps;
    } elseif (!empty($_SESSION['bailleur_props'])) {
        // Reprendre la session (navigation depuis dashboard)
        foreach ($_SESSION['bailleur_props'] as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $getProps[] = $sid;
        }
    }
    if (!empty($getProps)) {
        $in = implode(',', $getProps);
        $propFilterWhere = "AND ct.id_proprietaire IN ({$in})";
    } elseif ($asBailleur > 0) {
        $propFilterWhere = 'AND 1=0';   // bailleur sélectionné sans propriétaires → rien
    }
    // Badge de filtre actif
    $filtreActif = !empty($getProps) || $asBailleur > 0;
    $filtreIds   = $getProps;
} else {
    // Bailleur : limité à ses user_proprietaires
    $stmtP = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
    $stmtP->execute([$userId]);
    $allowedIds = $stmtP->fetchAll(\PDO::FETCH_COLUMN);
    if (empty($allowedIds)) {
        $propFilterWhere = 'AND 1=0';
    } else {
        $in = implode(',', array_map('intval', $allowedIds));
        $propFilterWhere = "AND ct.id_proprietaire IN ({$in})";
    }
    $filtreActif = false;
    $filtreIds   = [];
}

// Construire l'URL retour dashboard avec même sélection
$backQuery = !empty($filtreIds) ? '?' . http_build_query(['props' => $filtreIds]) : '';

if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
// Normalisation pour la recherche : minuscules + accents neutralisés (« casse acceptée »).
// Doit rester COHÉRENT avec la normalisation JS (paNorm) côté navigateur.
if (!function_exists('pa_norm')) {
    function pa_norm($v): string {
        $s = mb_strtolower(trim((string)($v ?? '')), 'UTF-8');
        $from = ['à','á','â','ä','ã','å','ç','è','é','ê','ë','ì','í','î','ï','ñ','ò','ó','ô','ö','õ','ù','ú','û','ü','ý','ÿ','œ','æ'];
        $to   = ['a','a','a','a','a','a','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','u','u','u','u','y','y','oe','ae'];
        $s = str_replace($from, $to, $s);
        return preg_replace('/\s+/', ' ', $s);
    }
}
// Blob de recherche d'une ligne (locataire + adresse bien/immeuble + villes + réf).
if (!function_exists('pa_row_search')) {
    function pa_row_search(array $d): string {
        return pa_norm(implode(' ', array_filter([
            $d['locataire_nom']  ?? '',
            $d['bien_adresse']   ?? '',
            $d['bien_ville']     ?? '',
            $d['nom_immeuble']   ?? '',
            $d['adresse_1']      ?? '',
            $d['imm_ville']      ?? '',
            $d['reference_bien'] ?? '',
        ])));
    }
}
function fmtE(float $v): string {
    // Délègue au helper central (inc/format.php) : "21.410 €", "—" si 0.
    return fmt_euro_dash($v);
}
function sumCols(array $locs, string $col): float {
    return array_sum(array_column($locs, $col));
}
// Total des prix de vente affichés (colonne « Prix de vente » = prix courant du bien,
// validé OU pré-rempli). Dédoublonné par id_bien : un bien à plusieurs locataires
// ne compte qu'une fois. On prend le prix validé (bien_prix courant) en priorité,
// repli sur le miroir biens.prix_demande_initial.
function sumPrixVente(array $locs): float {
    $seen = []; $s = 0.0;
    foreach ($locs as $d) {
        $ib = (int)($d['id_bien'] ?? 0);
        if ($ib <= 0 || isset($seen[$ib])) continue;
        $seen[$ib] = true;
        $px = (float)($d['prix_valide_montant'] ?? 0);
        if ($px <= 0) $px = (float)($d['prix_demande_initial'] ?? 0);
        $s += $px;
    }
    return $s;
}
// Loyer mensuel effectif d'un ensemble de lignes : baux.loyer (réf. PATRIMOINE) sinon repli CRG (trimestriel/3)
function sumLoyerMois(array $locs): float {
    $s = 0.0;
    foreach ($locs as $d) {
        $bl = (float)($d['bail_loyer'] ?? 0);
        $s += $bl > 0 ? $bl : ((float)($d['loyer_appele'] ?? 0) / 3);
    }
    return $s;
}

// ── BASE SQL — UNIQUEMENT le dernier trimestre par propriétaire ──
// On prend le trimestre le plus récent chargé pour chaque propriétaire
// (ex: T1 2026) et on affiche TOUS les locataires de ce trimestre.
// Les locataires d'anciens trimestres ne sont PAS inclus.
require_once __DIR__ . '/inc/patrimoine_base.php';   // source unique partagée avec le hub
// Scénario sélectionné (server-side) : par défaut 'courant'. Quand un scénario alternatif
// est passé en ?scenario=CODE, les "lots porteurs de scénario" (prix courant nul, mais
// valorisés dans CE scénario) entrent dans la population et deviennent visibles UNIQUEMENT
// sous ce scénario. La surcouche JS remplit ensuite leurs prix.
$scenarioSel = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['scenario'] ?? 'courant'))) ?: 'courant';
$base_sql = patrimoine_base_sql($propFilterWhere, $scenarioSel);

// ── STATS GLOBALES ───────────────────────────────────────
$stats = $pdo->query("
    SELECT
      COUNT(DISTINCT p.id) AS nb_prop,
      COUNT(DISTINCT CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_presents,
      COUNT(DISTINCT CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_partis,
      ROUND(SUM(CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                     THEN sub.total_impaye ELSE 0 END),0) AS impaye_actif,
      ROUND(SUM(CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0
                     THEN sub.total_impaye ELSE 0 END),0) AS creances_partis
    FROM proprietaires p
    JOIN ({$base_sql}) sub ON sub.id_proprietaire = p.id
")->fetch(\PDO::FETCH_ASSOC);

// ── LISTE PAR PROPRIÉTAIRE ──────────────────────────────
$result = $pdo->query("
    SELECT
      p.id, p.id_tiers, p.civilite, p.nom, p.prenom, p.societe, p.type_personne,
      COUNT(DISTINCT CASE WHEN sub.imm_vendu=0 THEN sub.id_bien END) AS nb_biens,
      COUNT(DISTINCT CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_presents,
      COUNT(DISTINCT CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0
                          THEN CONCAT(sub.id_bien,'-',sub.locataire_nom) END) AS nb_partis,
      ROUND(SUM(CASE WHEN sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.loyer_appele ELSE 0 END)/3,0) AS loyer_total,
      ROUND(SUM(CASE WHEN sub.presence='present' AND sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.total_impaye ELSE 0 END),0) AS impaye_actif,
      ROUND(SUM(CASE WHEN sub.presence='parti' AND sub.imm_vendu=0 AND sub.loc_archive=0 THEN sub.total_impaye ELSE 0 END),0) AS creances_partis,
      ROUND((SELECT SUM(c2.total_regle) FROM crg_trimestres t2 JOIN crg_situations_locataires c2 ON c2.id_crg=t2.id WHERE t2.id_proprietaire=p.id AND t2.annee=2026 AND t2.trimestre=1),0) AS enc_t1_2026,
      ROUND((SELECT SUM(c2.total_regle) FROM crg_trimestres t2 JOIN crg_situations_locataires c2 ON c2.id_crg=t2.id WHERE t2.id_proprietaire=p.id AND t2.annee=2025),0) AS enc_2025
    FROM proprietaires p
    JOIN ({$base_sql}) sub ON sub.id_proprietaire = p.id
    GROUP BY p.id
    ORDER BY impaye_actif DESC, creances_partis DESC
");
$rows = $result->fetchAll(\PDO::FETCH_ASSOC);

// ── DÉTAIL PAR PROPRIÉTAIRE ──────────────────────────────
// PERF : UNE seule requête pour TOUS les propriétaires (évite le N+1 qui ré-exécutait
// la lourde sous-requête CRG+UNION une fois par propriétaire — ~12 ms × 200+ proprios).
// On regroupe ensuite en PHP par id_proprietaire.
$details = [];
foreach ($rows as $row) { $details[$row['id']] = []; }   // garantit une entrée par proprio (même vide)
$detailStmt = $pdo->query("
    SELECT
      sub.id_proprietaire,
      sub.id_bien, sub.locataire_nom, sub.loyer_appele,
      sub.total_regle, sub.total_impaye, sub.presence,
      sub.imm_vendu, sub.loc_archive, sub.hors_crg,
      CASE WHEN sub.hors_crg=1 THEN '—' ELSE CONCAT(sub.annee,' T',sub.trimestre) END AS dernier_crg,
      (SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=sub.id_bien AND bp.type_valeur='loyer' AND bp.is_courant=1 AND bp.scenario_code='courant' ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1) AS loyer_estime_valide,
      b.reference_bien, b.surface_habitable, b.surface_carrez,
      b.prix_demande_initial, b.rendement_brut, b.type_commercialisation, b.a_proposer,
      b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
      i.id AS id_immeuble, i.nom_immeuble, i.adresse_1, i.ville AS imm_ville, i.date_vente,
      (SELECT bx.loyer FROM baux bx
         WHERE bx.id_bien = sub.id_bien AND bx.id_proprietaire = sub.id_proprietaire
         ORDER BY (bx.statut='actif') DESC, bx.id DESC LIMIT 1) AS bail_loyer,
      (SELECT bp.montant FROM bien_prix bp
         WHERE bp.id_bien = sub.id_bien AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code='courant'
         ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1) AS prix_valide_montant
    FROM ({$base_sql}) sub
    LEFT JOIN biens b ON b.id = sub.id_bien
    LEFT JOIN immeubles i ON i.id = b.id_immeuble
    ORDER BY sub.id_proprietaire,
             sub.imm_vendu ASC, sub.presence ASC, sub.loc_archive ASC,
             b.reference_bien ASC, sub.locataire_nom
");
foreach ($detailStmt as $r) {
    $details[(int)$r['id_proprietaire']][] = $r;
}

// ── Biens RATTACHÉS hors CRG (ex: CB FINANCES affiché sous GROUPE SIR) ──
// Le patrimoine est piloté par les CRG ; certains biens (créés sans CRG) doivent
// néanmoins apparaître sous un propriétaire affiché. Dérivé de la fusion du module
// portefeuille : on rattache un propriétaire fusionné UNIQUEMENT s'il n'a PAS de CRG
// (sinon il s'affiche déjà nativement). → portable local/prod (résolution par noms).
$PATRIMOINE_ATTACH = [];
try {
    $pfCfg = require __DIR__ . '/inc/portefeuille_perimetre.php';
    foreach (($pfCfg['merge'] ?? []) as $srcId => $tgtId) {
        $hasCrg = (int)$pdo->query("SELECT COUNT(*) FROM crg_trimestres WHERE id_proprietaire=" . (int)$srcId)->fetchColumn();
        if ($hasCrg === 0) { $PATRIMOINE_ATTACH[(int)$tgtId][] = (int)$srcId; }  // ex: CB FINANCES sous GROUPE SIR
    }
} catch (Throwable $e) { $PATRIMOINE_ATTACH = []; }
$extraBiens = [];
foreach ($PATRIMOINE_ATTACH as $targetPid => $srcPids) {
    $inSrc = implode(',', array_map('intval', $srcPids));
    if ($inSrc === '') continue;
    $stx = $pdo->query("
        SELECT b.id,
            COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
            COALESCE(NULLIF(b.ville,''), i.ville)         AS ville,
            b.reference_bien,
            COALESCE(b.surface_habitable, b.surface_carrez) AS surface,
            b.loyer_hc,
            COALESCE((SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code='courant' ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1),
                     b.prix_demande_initial, b.prix_vente_estime) AS prix_vente,
            pr.societe AS proprio
        FROM biens b
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        LEFT JOIN proprietaires pr ON pr.id = b.id_proprietaire
        WHERE b.id_proprietaire IN ($inSrc)
          AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
          AND (b.date_retrait_commercialisation IS NULL AND b.prix_final_vente IS NULL)
        ORDER BY pr.societe, b.adresse_1");
    $extraBiens[$targetPid] = $stx->fetchAll(\PDO::FETCH_ASSOC);
}

// ── Mode affichage : 1 proprio → immeubles dépliés auto ─
// Si un seul propriétaire visible (filtre ou bailleur avec 1 prop)
$singlePropMode = (count($rows) === 1);

// ── HELPERS ─────────────────────────────────────────────
function groupByImmeuble(array $rows): array {
    $groups = []; $order = [];
    foreach ($rows as $d) {
        // Bien rattaché à un immeuble → groupé par immeuble. Bien SANS immeuble (ex. vacant à
        // vendre) → bloc propre identifié par le bien, en-tête = sa propre adresse.
        $iid = !empty($d['id_immeuble']) ? (int)$d['id_immeuble'] : ('B' . (int)$d['id_bien']);
        if (!isset($groups[$iid])) {
            $sansImmeuble = empty($d['id_immeuble']);
            $groups[$iid] = [
                'id_immeuble'  => $d['id_immeuble'] ?? 0,
                'nom_immeuble' => $sansImmeuble ? '' : ($d['nom_immeuble'] ?? '—'),
                'ville'        => $sansImmeuble ? (string)($d['bien_ville'] ?? '') : (string)($d['imm_ville'] ?? ''),
                'adresse_1'    => $sansImmeuble
                    ? trim((string)($d['bien_adresse'] ?? '') . ' ' . (string)($d['bien_ville'] ?? ''))
                    : ($d['adresse_1'] ?? ''),
                'date_vente'   => $d['date_vente'] ?? null,
                'vendu'        => (int)($d['imm_vendu'] ?? 0),
                'actifs'       => [],
                'partis'       => [],
                'archives'     => [],
            ];
            $order[] = $iid;
        }
        if ((int)$d['loc_archive'] === 1)                              $groups[$iid]['archives'][] = $d;
        // Vacants hors CRG : affichés AVEC les actifs (bloc immeuble déplié, adresse, tri),
        // distingués en VACANT/doré. Ils ne comptent pas comme "occupés" (calcul SQL séparé).
        elseif ($d['presence'] === 'present' || !empty($d['hors_crg'])) $groups[$iid]['actifs'][]   = $d;
        else                                                           $groups[$iid]['partis'][]   = $d;
    }
    return array_map(fn($iid) => $groups[$iid], $order);
}

function renderLocRows(array $locs, string $niveau, int $pid): string {
    if (empty($locs)) return '';
    global $canMerge, $mode;
    $html = '';
    foreach ($locs as $d) {
        $nom   = e($d['locataire_nom']);
        $bien  = e($d['reference_bien'] ?? '—');
        $crg   = e($d['dernier_crg']);
        // Loyer mensuel : référence = baux.loyer (PATRIMOINE = loyer annuel/12) ; repli CRG (trimestriel/3)
        $bailLoyer = (float)($d['bail_loyer'] ?? 0);
        $loyerMois = $bailLoyer > 0 ? $bailLoyer : ((float)$d['loyer_appele'] / 3);
        if (!empty($d['hors_crg'])) {   // bien vacant : loyer estimé ÉDITABLE directement dans la colonne Loyer/mois
            $le  = (float)($d['loyer_estime_valide'] ?? 0);
            $ibL = (int)$d['id_bien'];
            $loyer = "<input type='number' class='v-loyer' data-bien='{$ibL}' data-pid='{$pid}' value='".($le > 0 ? (int)round($le) : '')."' onchange='saveLoyerEstime(this)' oninput='recalcVenteLoyer(this)' placeholder='à estimer €' min='0' step='10' title='Loyer mensuel estimé (fictif) — remplacé par le CRG dès que le bien est loué' style='width:96px;text-align:right'>";
        } else {
            $loyer  = $loyerMois > 0 ? fmt_euro($loyerMois) : '—';
        }
        $regle  = $d['total_regle']  > 0 ? fmt_euro((float)$d['total_regle'])  : '—';
        $impaye = $d['total_impaye'] > 0
            ? '<strong style="color:#c62828">'.fmt_euro((float)$d['total_impaye']).'</strong>'
            : '—';
        $ib   = (int)$d['id_bien'];
        $nenc = addslashes($d['locataire_nom']);
        $isOccupProprio = ($d['locataire_nom'] === 'OCCUPÉ PAR PROPRIÉTAIRE');
        if ($niveau === 'actif') {
            if (!empty($d['hors_crg'])) {
                $badge = "<span class='badge b-vacant'>🟡 VACANT</span>";
            } else {
                $badge = $isOccupProprio
                    ? "<span class='badge b-occ'>🏠 OCCUPÉ PROPRIÉTAIRE</span>"
                    : ($d['total_impaye'] > 0
                        ? "<span class='badge b-imp'>IMPAYÉ</span>"
                        : "<span class='badge b-ok'>RÉGULIER</span>");
            }
            $btn = "<button class='btn-vente-go' onclick=\"mettreEnVente({$ib},{$pid})\" title='Basculer ce bien vers le module Transaction'>🏷️ Mettre en vente</button>";
        } elseif ($niveau === 'parti') {
            if (!empty($d['hors_crg'])) {
                // Bien rattaché au propriétaire mais sans CRG → VACANT (valeurs estimées, fond doré).
                $badge = "<span class='badge b-vacant'>🟡 VACANT</span>";
                $btn = "<button class='btn-vente-go' onclick=\"mettreEnVente({$ib},{$pid})\" title='Basculer ce bien vers le module Transaction'>🏷️ Mettre en vente</button>";
            } else {
                $badge = "<span class='badge b-parti'>PARTI</span>";
                $btn = "<button class='btn-loc btn-archive' onclick=\"archiveLoc({$ib},'{$nenc}',{$pid},1)\">📦 Archiver</button>";
            }
        } else {
            $badge = "<span class='badge b-arch'>ARCHIVÉ</span>";
            $btn = "<button class='btn-loc btn-restore' onclick=\"archiveLoc({$ib},'{$nenc}',{$pid},0)\">↩️ Restaurer</button>";
        }
        $tr_cls = match($niveau) { 'parti'=>'parti', 'archive'=>'archive', default=>'' };
        if (!empty($d['hors_crg'])) $tr_cls .= ' hors-crg';
        $onclick = "openLocHistory({$ib},".htmlspecialchars(json_encode($d['locataire_nom']), ENT_QUOTES).",{$pid})";
        $loc360  = "locataire_360.php?id_bien={$ib}&id_proprietaire={$pid}&loc=".rawurlencode($d['locataire_nom']);

        // ── Colonnes décision vente ──
        // VENTE → surface Carrez (prioritaire) ; repli sur habitable si Carrez absente.
        // (l'habitable reste la référence côté LOCATION ; ici on est en contexte vente.)
        $surfCarrez = (float)($d['surface_carrez'] ?? 0);
        $surfHab    = (float)($d['surface_habitable'] ?? 0);
        // Prix/rendement COURANTS (BDD) → pré-remplissage des champs vente
        $pvVal  = (float)($d['prix_demande_initial'] ?? 0);
        $rtVal  = (float)($d['rendement_brut'] ?? 0);
        // Prix VALIDÉ courant (bien_prix.is_courant) → état initial du bouton "Validé"
        $courantPrix = (float)($d['prix_valide_montant'] ?? 0);
        // Si le miroir prix_demande_initial est vide mais qu'un prix a été VALIDÉ
        // (ex. validé dans un scénario, miroir non synchronisé), on affiche le prix validé
        // pour que l'input corresponde à data-courant → bouton "✓ Validé" cohérent.
        if ($pvVal <= 0 && $courantPrix > 0) { $pvVal = $courantPrix; }
        $pvAttr = $pvVal > 0 ? " value='".(int)round($pvVal)."'" : '';
        $rtAttr = $rtVal > 0 ? " value='".rtrim(rtrim(number_format($rtVal,2,'.',''),'0'),'.')."'" : '';
        $surf       = $surfCarrez > 0 ? $surfCarrez : $surfHab;
        $surfTag    = $surfCarrez > 0 ? 'C' : ($surfHab > 0 ? 'H' : '');
        $surfTitle  = 'Carrez : ' . ($surfCarrez > 0 ? number_format($surfCarrez,0,',',' ').' m²' : 'n/c')
                    . ' · Habitable : ' . ($surfHab > 0 ? number_format($surfHab,0,',',' ').' m²' : 'n/c')
                    . ($surfCarrez > 0 ? ' — base vente : Carrez' : ' — base vente : Habitable (pas de Carrez)');
        $loyerAn = $loyerMois * 12; // base = loyer mensuel (patrimoine si dispo, sinon CRG)
        $surfTxt = $surf > 0
            ? number_format($surf, 2, ',', ' ').' m²<sup class="surf-tag '.($surfTag==='H'?'surf-h':'surf-c').'" title="'.e($surfTitle).'">'.$surfTag.'</sup>'
            : '—';
        $loyerAnTxt = fmt_euro_dash($loyerAn);
        $surfInputVal = $surf > 0 ? rtrim(rtrim(number_format($surf, 2, '.', ''), '0'), '.') : '';
        $surfBadge = $surfTag !== ''
            ? "<sup class='surf-tag ".($surfTag==='H'?'surf-h':'surf-c')."' title=\"".e($surfTitle)."\">{$surfTag}</sup>"
            : '';

        // Cellule surface : éditable pour les actifs, texte sinon
        if ($niveau === 'actif') {
            $surfaceCell = "<td class='num-r'><input type='number' class='v-sf' data-bien='{$ib}' data-pid='{$pid}' value='{$surfInputVal}' oninput='recalcVente(this,\"sf\")' onchange='saveSurface(this)' placeholder='m²' min='0' step='0.01' title='Surface Carrez (base vente) — modifiable, au centième de m²'>{$surfBadge}</td>";
        } else {
            $surfaceCell = "<td class='num-r'>{$surfTxt}</td>";
        }

        // Ligne principale (le bouton d'action n'apparaît que pour parti/archive ;
        //  pour les actifs, le bouton « Mettre en vente » est sur la 2e ligne)
        // Repère immeuble (uniquement pour les listes vides/archivés, sorties de leur en-tête immeuble)
        $immTxt = '';
        if ($niveau !== 'actif') {
            $imAdr = trim(trim((string)($d['nom_immeuble'] ?? '')).' — '.trim((string)($d['adresse_1'] ?? '')), " —");
            if ($imAdr !== '') $immTxt = "<div style='font-size:.76em;color:#6a1b9a;margin-top:2px'>🏢 ".e($imAdr)."</div>";
        }
        $isProposed = ((int)($d['a_proposer'] ?? 0) === 1);   // champ dédié
        $prixData   = $courantPrix > 0 ? $courantPrix : $pvVal;   // prix validé sinon demandé
        $pfAttr = $mode === 'proposer'
            ? (" data-propose='".($isProposed?'1':'0')."' data-prix='".(int)round($prixData)."'")
            : '';
        // En mode "proposer" (Biens à proposer) : pas d'ENCAISSÉ ni de CRG.
        $encCell = $mode === 'proposer' ? '' : "<td class='num-r enc-link' onclick=\"{$onclick}\" title='Voir l&#39;historique CRG (encaissements)'>{$regle}</td>";
        $crgCell = $mode === 'proposer' ? '' : "<td>{$crg}</td>";
        $idImm = (int)($d['id_immeuble'] ?? 0);
        if (!empty($d['hors_crg'])) {
            // VACANT : pas de bail → pas de lien locataire. Liens propriétaire + immeuble.
            $vacLinks = "<a href='agency_proprietaire_fiche.php?id={$pid}' target='_blank' rel='noopener' title='Fiche propriétaire'>👤 Propriétaire ↗</a>";
            if ($idImm > 0) $vacLinks .= " · <a href='immeuble_360.php?id={$idImm}' target='_blank' rel='noopener' title='Fiche immeuble'>🏢 Immeuble ↗</a>";
            $nomCell = "<td class='nom-col'><strong style='color:#8a6d1b'>LOGEMENT VACANT</strong><div style='font-size:.76em;margin-top:3px;color:#6a1b9a'>{$vacLinks}</div></td>";
        } else {
            $nomCell = "<td class='nom-col'><a href='".e($loc360)."' class='loc-link' title='Fiche locataire 360°'><strong>{$nom}</strong></a></td>";
        }
        $rowSearch = e(pa_row_search($d));
        $html .= "<tr class='{$tr_cls} pf-frow'{$pfAttr} data-search=\"{$rowSearch}\" data-surface='{$surf}' data-loyeran='".round($loyerAn)."'>
            {$nomCell}
            <td>" . ($canMerge ? "<input type='checkbox' class='merge-pick' data-bien='{$ib}' data-label=\"".e((string)$bien)."\" title='Cocher 2 biens pour fusionner les doublons' onclick='event.stopPropagation();mergeSync();'> " : '') . "<a href='bien_360.php?id={$ib}' target='_blank' rel='noopener' class='bien-link' title='Ouvrir la fiche du bien'><code style='font-size:.82em'>{$bien}</code> ↗</a>{$immTxt}</td>
            <td>{$badge}</td>
            <td class='num-r'>{$loyer}</td>
            {$surfaceCell}
            <td class='num-r'>{$loyerAnTxt}</td>
            {$encCell}
            <td class='num-r'>{$impaye}</td>
            {$crgCell}
            <td class='action-col'>".($mode === 'proposer' ? '' : ($niveau === 'actif' ? '' : $btn))."</td>
        </tr>";

        // 2e ligne : simulateur vente + bouton.
        // Actifs ET vacants (locataire parti) : on peut fixer un prix de vente.
        // Pour les vacants, pas de loyer => rentabilité vide (assumé), prix saisissable.
        if ($niveau === 'actif' || $niveau === 'parti') {
            $venteCls = !empty($d['hors_crg']) ? 'vente-row pf-frow hors-crg' : 'vente-row pf-frow';
            $html .= "<tr class='{$venteCls}'{$pfAttr} data-search=\"{$rowSearch}\"><td colspan='".($mode === 'proposer' ? 8 : 10)."'>
              <div class='vente-panel'>
                <span class='vp-lbl'>💶 Simuler :</span>
                <label>Prix /m² <input type='number' class='v-pm' data-bien='{$ib}' data-pid='{$pid}' oninput='recalcVente(this,\"pm\")' placeholder='€/m²' min='0' step='10'></label>
                <label>Prix de vente <input type='number' class='v-pv' data-bien='{$ib}' data-pid='{$pid}'{$pvAttr} oninput='recalcVente(this,\"pv\")' placeholder='€' min='0' step='1000'></label>
                <label>Rentabilité <input type='number' class='v-rt' data-bien='{$ib}' data-pid='{$pid}'{$rtAttr} oninput='recalcVente(this,\"rt\")' placeholder='%' min='0' step='0.1'></label>
                <button class='btn-dvf' onclick=\"estimerDVF({$ib},this)\" title='Estimer le prix au m² via les ventes réelles DVF du secteur (même type, taille équivalente)'>📊 Estimer</button>
                <button class='btn-valider' data-bien='{$ib}' data-courant='".(int)round($courantPrix)."' onclick=\"clickValider({$ib},{$pid},this)\" title='Fixe ce prix comme prix courant (historisé) — repris dans l annonce et l export'>✓ Valider le prix</button>
                <button class='btn-histo' onclick=\"histoPrix({$ib})\" title='Voir l historique des prix validés'>🕑 Historique</button>
                <span class='dvf-res' data-bien='{$ib}' style='font-size:.9em;color:#1a237e'></span>
                " . ($mode === 'proposer'
                    ? ("<span class='pf-spacer'></span>" . ($isProposed
                        ? "<button class='pf-btn pf-on' disabled>✓ Proposé</button><button class='pf-btn pf-off' onclick=\"pfToggle({$ib},0,this)\">✗ Ne pas proposer</button>"
                        : "<button class='pf-btn pf-set' onclick=\"pfToggle({$ib},1,this)\">✓ À proposer</button><button class='pf-btn pf-off' disabled title='Non proposé'>✗ Ne pas proposer</button>"))
                    : (($d['type_commercialisation'] ?? '') === 'vente'
                        ? "<span class='badge-envente'>🏷️ En vente</span><button class='btn-retirer' onclick=\"retirerVente({$ib},{$pid},this)\" title='Retirer ce bien de la commercialisation (annule un doublon)'>↩️ Retirer</button>"
                        : "<button class='btn-vente-go' onclick=\"mettreEnVente({$ib},{$pid})\">🏷️ Mettre en vente</button>")) . "
              </div></td></tr>";
        }
    }
    return $html;
}

$nProposer = ($mode === 'proposer');
$col_headers = "
<table class='detail'>
<colgroup>
  <col style='width:17%'><col style='width:11%'><col style='width:9%'>
  <col style='width:9%'><col style='width:10%'><col style='width:9%'>
  " . ($nProposer ? "" : "<col style='width:10%'>") . "<col style='width:9%'>" . ($nProposer ? "" : "<col style='width:8%'>") . "<col style='width:8%'>
</colgroup>
<thead><tr>
  <th data-col='0' onclick='sortTable(this)'>LOCATAIRE<span class='sort-icon'>⇅</span></th>
  <th data-col='1' onclick='sortTable(this)'>BIEN<span class='sort-icon'>⇅</span></th>
  <th data-col='2' onclick='sortTable(this)'>STATUT<span class='sort-icon'>⇅</span></th>
  <th data-col='3' class='num-r' onclick='sortTable(this)'>LOYER<br>/mois<span class='sort-icon'>⇅</span></th>
  <th data-col='4' class='num-r' onclick='sortTable(this)' title='Base vente = Carrez (C) ; repli Habitable (H)'>SURFACE<br>m²<span class='sort-icon'>⇅</span></th>
  <th data-col='5' class='num-r' onclick='sortTable(this)'>LOYER<br>/an<span class='sort-icon'>⇅</span></th>
  " . ($nProposer ? "" : "<th data-col='6' class='num-r' onclick='sortTable(this)'>ENCAISSÉ<span class='sort-icon'>⇅</span></th>") . "
  <th data-col='7' class='num-r' onclick='sortTable(this)'>IMPAYÉ<span class='sort-icon'>⇅</span></th>
  " . ($nProposer ? "" : "<th data-col='8' onclick='sortTable(this)'>CRG<span class='sort-icon'>⇅</span></th>") . "
  <th style='width:90px'></th>
</tr></thead>
<tbody>";

// ── LAYOUT MBI ──────────────────────────────────────────
// Nom du bailleur affiché dans la topbar (à la place du titre dupliqué)
$bailleurIds = $isSuperAdmin ? ($filtreIds ?? []) : ($allowedIds ?? []);
$bailleurNom = '';
if (!empty($bailleurIds)) {
    $inB   = implode(',', array_map('intval', $bailleurIds));
    $names = $pdo->query("SELECT COALESCE(NULLIF(societe,''),CONCAT_WS(' ',prenom,nom)) FROM proprietaires WHERE id IN ($inB)")->fetchAll(\PDO::FETCH_COLUMN);
    $bailleurNom = (count($names) === 1) ? (string)$names[0] : (count($names) . ' propriétaires');
}
$pageTitle    = $bailleurNom !== '' ? $bailleurNom : 'Patrimoine — tous les propriétaires';
$pageSubtitle = 'Ma Box Bailleur · État du Patrimoine Actif';
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_patrimoine_actif';

$extraCss = '
<style>
/* ═══════════════ PATRIMOINE ACTIF ═══════════════ */
.agency-content { padding:14px 12px 14px 14px !important; }
.pat-summary { background:#e8eaf6; padding:14px 18px; border-left:5px solid #3f51b5;
               border-radius:6px; margin:0 0 20px; font-size:.93em; }
.pat-legend  { background:#fff9c4; padding:9px 14px; border-left:4px solid #f9a825;
               border-radius:4px; font-size:.84em; margin-bottom:16px; }

/* TABLE PRINCIPALE */
.main-pat-wrap { width:100%; overflow-x:auto; border-radius:6px; }
table.main-pat { border-collapse:collapse; width:100%; background:white;
                 box-shadow:0 2px 6px rgba(0,0,0,.1); border-radius:6px; overflow:hidden; }
table.main-pat thead tr { background:#1a237e; color:white; }
table.main-pat thead th { padding:7px 7px; text-align:left; font-size:.7em; line-height:1.15;
                          white-space:nowrap; letter-spacing:-.01em; }
table.main-pat tbody tr.prop-row { cursor:pointer; transition:background .15s; }
table.main-pat tbody tr.prop-row:hover td { background:#e8f4fd; }
table.main-pat tbody tr.prop-row.active td { background:#dbeafe; }
table.main-pat tbody td { padding:7px 7px; border-bottom:1px solid #eee; font-size:.8em; vertical-align:middle; white-space:nowrap; }
table.main-pat tbody td:first-child { white-space:normal; }
table.main-pat tr.total-row td { background:#e8eaf6; font-weight:bold; border-top:2px solid #3f51b5; }

/* DÉTAIL INLINE */
tr.detail-row { display:none; }
tr.detail-row.open { display:table-row; }
tr.detail-row td { padding:0; background:#f8f9ff; border-bottom:3px solid #3f51b5; }
.detail-inner { padding:8px 10px; }
.detail-inner > h4 { margin:0 0 10px; color:#1a237e; font-size:.92em; }

/* BLOC IMMEUBLE */
.imm-block { margin-bottom:12px; border:1px solid #c5cae9; border-radius:6px; overflow:hidden; }
.imm-block.vendu-block { border-color:#e0e0e0; opacity:.72; }
.imm-header { display:flex; align-items:center; gap:10px; padding:7px 12px; background:#e8eaf6; }
.imm-header.vendu-header { background:#f5f5f5; }
.imm-title { flex:1; font-weight:bold; font-size:.86em; color:#1a237e; }
.imm-title.vendu-title { color:#9e9e9e; text-decoration:line-through; }
.imm-addr { font-size:.78em; color:#666; font-weight:normal; }
.imm-meta { font-size:.76em; color:#666; font-weight:normal; margin-left:8px; }
.imm-pv-total { background:#0e6b75; color:#fff; font-size:.78em; font-weight:bold;
                padding:3px 10px; border-radius:12px; margin-right:8px; white-space:nowrap; }
.imm-stat { background:#eef2f7; color:#36577d; font-size:.78em; font-weight:bold;
            padding:3px 10px; border-radius:12px; margin-right:6px; white-space:nowrap; }
.imm-stat.renta { background:#e8f5e9; color:#2e7d32; }
.btn-export-xls { display:inline-block; padding:5px 14px; font-size:13px; font-weight:700; cursor:pointer;
    text-decoration:none; color:#1b5e20; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:8px; }
.btn-export-xls:hover { background:#c8e6c9; }
.btn-scenario-reprendre { padding:5px 12px; font-size:.9em; font-weight:700; color:#fff; background:#7e57c2;
    border:none; border-radius:7px; cursor:pointer; }
.btn-scenario-reprendre:hover { background:#5e35b1; }
.btn-foldall { padding:5px 12px; font-size:.9em; font-weight:700; color:#3a3830; background:#fff;
    border:1px solid #b39ddb; border-radius:7px; cursor:pointer; }
.btn-foldall:hover { background:#f3eefb; }
.pat-searchbar { display:flex; align-items:center; gap:8px; margin:10px 0 12px; padding:8px 14px;
    background:#fff; border:1px solid #d7ccec; border-left:5px solid #5e35b1; border-radius:8px; }
.pat-search-ic { font-size:1.05em; opacity:.6; }
.pat-search-in { flex:1; min-width:220px; padding:8px 12px; border:1px solid #cfc6e0; border-radius:8px;
    font-size:.98em; background:#faf8ff; }
.pat-search-in:focus { outline:none; border-color:#5e35b1; background:#fff; }
.pat-search-clear { border:1px solid #e0d9cf; background:#fff; color:#7a5cc0; border-radius:8px;
    padding:7px 11px; cursor:pointer; font-weight:700; }
.pat-search-clear:hover { background:#f3eefb; }
.pat-search-count { color:#6b7280; font-size:.86em; white-space:nowrap; }
.scenario-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:10px 0 14px;
    padding:8px 14px; background:#ede7f6; border-left:5px solid #5e35b1; border-radius:8px; font-size:.92em; }
.scenario-bar select { padding:5px 10px; border:1px solid #b39ddb; border-radius:7px; font-size:.95em; background:#fff; }
.btn-scenario-new { padding:5px 12px; font-size:.9em; font-weight:700; color:#fff; background:#5e35b1;
    border:none; border-radius:7px; cursor:pointer; }
.btn-scenario-new:hover { background:#4527a0; }
.btn-scenario-save { padding:5px 12px; font-size:.9em; font-weight:700; color:#1b5e20; background:#e8f5e9;
    border:1px solid #a5d6a7; border-radius:7px; cursor:pointer; }
.btn-scenario-save:hover { background:#c8e6c9; }
.scenario-info { font-weight:700; color:#4527a0; }
.scenario-hint { font-size:.8em; color:#7e57c2; margin-left:auto; max-width:420px; }
.prop-pv-total { background:#e0f2f1; color:#0e6b75; font-size:.82em; font-weight:normal;
                 padding:2px 10px; border-radius:12px; margin-left:8px; white-space:nowrap;
                 border:1px solid #0e6b75; }

/* BARRE TOTAUX IMMEUBLE */
.imm-totals { display:flex; gap:0; border-top:2px solid #3f51b5; background:#e8eaf6; font-size:.81em; font-weight:bold; }
.imm-totals .it-cell { padding:5px 12px; border-right:1px solid #c5cae9; }
.imm-totals .it-cell:last-child { border-right:none; }
.imm-totals .it-label { color:#555; font-weight:normal; font-size:.9em; display:block; }
.imm-totals .it-val.enc { color:#2e7d32; }
.imm-totals .it-val.imp { color:#c62828; }
.imm-totals .it-val.loyer { color:#1565c0; }

/* TOTAUX PROPRIÉTAIRE */
.prop-totals { display:flex; gap:0; border:2px solid #1a237e; border-radius:6px;
               background:#e8eaf6; font-size:.86em; font-weight:bold;
               margin-top:14px; overflow:hidden; }
.prop-totals .pt-cell { padding:7px 14px; border-right:1px solid #c5cae9; flex:1; }
.prop-totals .pt-cell:last-child { border-right:none; }
.prop-totals .pt-label { color:#555; font-size:.83em; font-weight:normal; display:block; margin-bottom:2px; }
.prop-totals .pt-val.enc { color:#2e7d32; }
.prop-totals .pt-val.imp { color:#c62828; }
.prop-totals .pt-val.loyer { color:#1565c0; }

/* SECTIONS PLIABLES */
.fold-toggle { display:flex; align-items:center; gap:8px; padding:5px 12px;
               cursor:pointer; user-select:none; font-size:.81em; font-weight:bold;
               border-top:1px solid #e0e0e0; }
.fold-toggle:hover { filter:brightness(.96); }
.fold-toggle.partis-toggle  { color:#880e4f; background:#fdf2f5; }
.fold-toggle.archive-toggle { color:#616161; background:#f5f5f5; }
.fold-toggle.vendus-toggle  { color:#616161; background:#eeeeee; border-radius:6px;
                               border:1px solid #bdbdbd; display:inline-flex;
                               margin-top:12px; padding:6px 14px; }
.fold-arrow { transition:transform .2s; display:inline-block; font-size:.83em; }
.fold-toggle.open .fold-arrow { transform:rotate(90deg); }
.fold-content { display:none; }
.fold-content.open { display:block; }
/* Surbrillance de la ligne trouvée par la recherche */
tr.pat-hit > td { background:#fff8d8 !important; box-shadow: inset 3px 0 0 #f5a623; }

/* TABLE DÉTAIL */
table.detail { border-collapse:collapse; width:100%; font-size:.92em; table-layout:fixed; }
table.detail th, table.detail td { overflow:hidden; text-overflow:ellipsis; }
table.detail th { background:#3f51b5; color:white; padding:4px 6px;
                  text-align:left; white-space:nowrap; cursor:pointer; user-select:none;
                  font-size:.7em; line-height:1.15; opacity:.92; }
table.detail th.num-r { text-align:right; }
table.detail th:hover { background:#303f9f; }
.sort-icon { margin-left:2px; font-size:.8em; opacity:.6; }
table.detail td { padding:3px 7px; border-bottom:1px solid #eee; vertical-align:middle; white-space:nowrap; }
table.detail td.num-r { font-variant-numeric:tabular-nums; font-size:1.04em; }
table.detail td.nom-col { white-space:normal; }

/* 2e ligne : simulateur de vente */
tr.vente-row td { background:#eef2ff; border-bottom:2px solid #c5cae9; padding:5px 8px; }
.vente-panel { display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-size:1.05em; }
.vente-panel .vp-lbl { font-weight:bold; color:#1a237e; font-size:1.05em; }
.vente-panel label { font-size:1em; color:#444; display:flex; align-items:center; gap:6px; white-space:nowrap; font-weight:600; }
.vente-panel input { width:104px !important; font-size:1.1em !important; padding:4px 6px !important; }
.vente-panel input.v-rt { width:52px !important; }   /* Rentabilité : champ réduit de 50% (gain de place) */
.vente-panel .btn-vente-go { margin-left:auto; }
/* Bouton doré, petit (même gabarit que le bouton détail-bien) */
.btn-dvf{ padding:5px 14px; font-size:13px; line-height:1.2; font-weight:800; color:#3a2c00;
    background:linear-gradient(135deg,#bf953f 0%,#fcf6ba 45%,#d4af37 60%,#b38728 100%);
    border:1px solid #b38728; border-radius:8px; cursor:pointer;
    box-shadow:0 2px 5px rgba(150,110,0,.3); text-shadow:0 1px 0 rgba(255,255,255,.4);
    transition:filter .15s, transform .1s; }
.btn-dvf:hover{ filter:brightness(1.07); }
.btn-dvf:active{ transform:scale(.97); }
.btn-dvf:disabled{ opacity:.6; cursor:default; }
/* Même effet 3D que le bouton « Estimer », en VERT AMANDE de référence (#759e78 / #4a6038) */
.btn-vente-go{ padding:5px 14px; font-size:13px; line-height:1.2; font-weight:800; color:#fff;
    background:linear-gradient(135deg,#4a6038 0%,#8fb389 45%,#759e78 60%,#3e5230 100%);
    border:1px solid #3e5230; border-radius:8px; cursor:pointer;
    box-shadow:0 2px 5px rgba(60,82,48,.35); text-shadow:0 1px 0 rgba(0,0,0,.25);
    transition:filter .15s, transform .1s; }
.btn-vente-go:hover{ filter:brightness(1.07); }
.btn-vente-go:active{ transform:scale(.97); }
/* Valider le prix (fixe le prix courant + historise) */
.btn-valider{ padding:5px 14px; font-size:13px; line-height:1.2; font-weight:700; color:#fff; background:#36577d;
    border:none; border-radius:8px; cursor:pointer; transition:background .15s, transform .1s; }
.btn-valider:hover{ background:#2b4466; }
.btn-valider:active{ transform:scale(.97); }
.btn-valider:disabled{ opacity:.6; cursor:default; }
/* État VALIDÉ : bleu pétrole, persistant — clic = historique des prix */
.btn-valider.is-valide{ background:#0e6b75; box-shadow:0 1px 3px rgba(14,107,117,.4); }
.btn-valider.is-valide:hover{ background:#0a565e; }
.btn-histo{ padding:5px 14px; font-size:13px; line-height:1.2; font-weight:700; color:#4a148c;
    background:#f3e5f5; border:1px solid #ce93d8; border-radius:8px; cursor:pointer; transition:background .15s; }
.btn-histo:hover{ background:#e1bee7; }
.badge-envente{ background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; border-radius:8px;
    padding:5px 10px; font-size:12px; font-weight:700; }
.btn-retirer{ padding:5px 12px; font-size:12px; font-weight:700; color:#b71c1c; background:#fff;
    border:1px solid #ef9a9a; border-radius:8px; cursor:pointer; transition:background .15s; }
.btn-retirer:hover{ background:#ffebee; }
.btn-retirer:disabled{ opacity:.6; cursor:default; }
table.detail tr:hover td { background:#f0f4ff; }
table.detail input.v-pm, table.detail input.v-pv, table.detail input.v-rt, table.detail input.v-sf {
  width:64px; padding:2px 4px; border:1px solid #90caf9; border-radius:4px;
  font-size:.95em; text-align:right; background:#f5fbff; -moz-appearance:textfield; }
table.detail input.v-sf { background:#ede7f6; border-color:#b39ddb; }
table.detail input.v-pv { background:#fff8e1; border-color:#ffcc80; }
table.detail input.v-rt { background:#f1f8e9; border-color:#aed581; font-weight:bold; color:#33691e; }
table.detail input::-webkit-outer-spin-button, table.detail input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
table.detail input.v-pm:focus, table.detail input.v-pv:focus, table.detail input.v-rt:focus, table.detail input.v-sf:focus {
  outline:none; box-shadow:0 0 0 2px rgba(63,81,181,.25); }
.surf-tag { font-size:.62em; font-weight:bold; padding:0 3px; border-radius:6px; margin-left:3px;
  vertical-align:super; cursor:help; }
.surf-tag.surf-c { background:#c8e6c9; color:#1b5e20; }   /* Carrez (base vente) */
.surf-tag.surf-h { background:#ffe0b2; color:#bf360c; }   /* Habitable (repli) */
table.detail tr.parti   td { background:#fff5f7; color:#5d1a2e; }
table.detail tr.archive td { background:#fafafa; color:#9e9e9e; }
table.detail tr.tot-sub td { background:#f5f0ff; color:#4a148c; font-weight:bold;
                              font-size:.82em; border-top:1px solid #ce93d8; }

/* BOUTONS LOC */
.btn-loc { border:none; border-radius:10px; padding:2px 8px; font-size:.74em;
           cursor:pointer; font-weight:bold; white-space:nowrap; }
.btn-archive { background:#fff3e0; color:#e65100; border:1px solid #ffb74d; }
.btn-archive:hover { background:#ffe0b2; }
.btn-restore { background:#e8f5e9; color:#2e7d32; border:1px solid #81c784; }
.btn-restore:hover { background:#c8e6c9; }
.btn-vente { background:#e3f2fd; color:#0d47a1; border:1px solid #64b5f6; }
.btn-vente:hover { background:#bbdefb; }
.btn-vendu { border:none; border-radius:12px; padding:3px 10px; font-size:.76em;
             cursor:pointer; font-weight:bold; white-space:nowrap; }
.btn-add-bien { border:none; border-radius:12px; padding:3px 10px; font-size:.76em; font-weight:bold; cursor:pointer; text-decoration:none; background:#1a237e; color:#fff; }
.btn-add-bien:hover { background:#283593; }
.btn-vendu.mark   { background:#ffecb3; color:#e65100; border:1px solid #ffb300; }
.btn-vendu.mark:hover   { background:#ffe082; }
.btn-vendu.unmark { background:#c8e6c9; color:#1b5e20; border:1px solid #81c784; }

/* BADGES */
.badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:.77em; font-weight:bold; }
.b-soc   { background:#bbdefb; color:#0d47a1; }
.b-phy   { background:#e1bee7; color:#4a148c; }
.b-ok    { background:#c8e6c9; color:#1b5e20; }
.b-warn  { background:#ffe0b2; color:#bf360c; }
.b-bad   { background:#ffcdd2; color:#b71c1c; }
.b-parti { background:#fce4ec; color:#880e4f; }
.b-imp   { background:#fff3e0; color:#e65100; }
.b-arch  { background:#eeeeee; color:#757575; }
.b-vacant{ background:#fff3d6; color:#8a6d1b; border:1px solid #e8cf88; }
.btn-creer-bien{ display:inline-block; background:#1a237e; color:#fff; text-decoration:none; font-weight:700; font-size:13px; padding:8px 14px; border-radius:10px; }
.btn-creer-bien:hover{ background:#283593; }
/* Lignes hors CRG : valeurs estimées/fictives → fond doré pour les distinguer du réel */
tr.hors-crg td { background:#fffaf0; }
tr.hors-crg .v-pv, tr.hors-crg .v-pm, tr.hors-crg .v-rt, tr.hors-crg .v-loyer { background:#fff3d6; border-color:#e8cf88; }
.loyer-estime { background:#fff3d6; border:1px solid #e8cf88; border-radius:6px; padding:1px 6px; color:#8a6d1b; font-weight:700; }
.b-occ   { background:#d7ccc8; color:#4e342e; }

/* MISC */
.arrow { font-size:.78em; margin-left:5px; transition:transform .2s; display:inline-block; }
.active .arrow { transform:rotate(180deg); }
.num-r { text-align:right; }
.txt-g { color:#2e7d32; font-weight:bold; }
.txt-r { color:#880e4f; }
.txt-b { color:#1565c0; font-weight:bold; }
.action-col { text-align:right; white-space:nowrap; }
.loc-link { cursor:pointer; text-decoration:underline dotted; text-underline-offset:2px; color:#1a237e; }
.loc-link:hover { color:#3f51b5; text-decoration:underline; }
.bien-link { text-decoration:none; color:#2c2a28; }
.bien-link:hover { color:#4878a6; }
.bien-link code { background:#f0f3f7; border-radius:4px; padding:1px 4px; }
/* Encaissé cliquable → ouvre le CRG, couleur distincte (vert encaissement) */
.enc-link { color:#2e7d32 !important; font-weight:bold; cursor:pointer;
            text-decoration:underline dotted; text-underline-offset:2px; background:#f1f8f1; }
.enc-link:hover { background:#dcedc8; color:#1b5e20 !important; }

/* MODAL VENDU */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:2000;
                 align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:white; border-radius:10px; padding:26px 30px; min-width:300px;
             box-shadow:0 8px 32px rgba(0,0,0,.25); }
.modal-box h3 { margin:0 0 14px; color:#1a237e; font-size:.95em; }
.modal-box label { display:block; margin-bottom:8px; font-size:.88em; }
.modal-box input[type=date] { width:100%; padding:7px; border:1px solid #ccc; border-radius:4px; font-size:.92em; margin-top:3px; }
.modal-box .modal-btns { display:flex; gap:8px; margin-top:16px; justify-content:flex-end; }
.modal-box button { padding:6px 16px; border-radius:6px; border:none; cursor:pointer; font-size:.85em; font-weight:bold; }
.btn-confirm { background:#e65100; color:white; }
.btn-cancel  { background:#e0e0e0; color:#333; }

/* MODAL HISTORIQUE LOCATAIRE */
.hist-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:2100;
                align-items:center; justify-content:center; padding:20px; }
.hist-overlay.open { display:flex; }
.hist-box { background:white; border-radius:12px; width:100%; max-width:820px; max-height:88vh;
            display:flex; flex-direction:column; box-shadow:0 12px 48px rgba(0,0,0,.3); overflow:hidden; }
.hist-header { background:#1a237e; color:white; padding:14px 20px; display:flex; align-items:center; gap:10px; }
.hist-header h3 { margin:0; font-size:.95em; flex:1; }
.hist-close { background:rgba(255,255,255,.2); border:none; color:white; border-radius:50%;
              width:26px; height:26px; cursor:pointer; font-size:.9em; }
.hist-tabs { display:flex; gap:0; border-bottom:2px solid #e0e0e0; padding:0 14px;
             background:#f8f9ff; overflow-x:auto; flex-shrink:0; }
.hist-tab { padding:8px 14px; border:none; background:none; cursor:pointer; font-size:.82em;
            font-weight:bold; color:#666; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; }
.hist-tab:hover { color:#1a237e; }
.hist-tab.active { color:#1a237e; border-bottom-color:#1a237e; background:white; }
.hist-tab.latest { color:#2e7d32; }
.hist-tab.latest.active { border-bottom-color:#2e7d32; }
.hist-body { flex:1; overflow-y:auto; padding:18px 22px; }
.hist-panel { display:none; }
.hist-panel.active { display:block; }
.crg-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:16px; }
.crg-card { background:#f5f7ff; border:1px solid #c5cae9; border-radius:8px; padding:12px 14px; }
.crg-card.enc { background:#f1f8f1; border-color:#a5d6a7; }
.crg-card.imp { background:#fff5f5; border-color:#ef9a9a; }
.crg-card.ok  { background:#f1f8f1; border-color:#a5d6a7; }
.crg-card .cc-label { font-size:.76em; color:#666; margin-bottom:3px; }
.crg-card .cc-val { font-size:1.2em; font-weight:bold; color:#1a237e; }
.crg-card.enc .cc-val { color:#2e7d32; }
.crg-card.imp .cc-val { color:#c62828; }
.crg-delta { font-size:.76em; margin-top:3px; }
.delta-up { color:#c62828; } .delta-down { color:#2e7d32; }
.hist-loading { text-align:center; padding:36px; color:#888; font-size:.92em; }
.pdf-viewer-wrap { margin-top:12px; border:1px solid #c5cae9; border-radius:6px; overflow:hidden; }
.pdf-viewer-bar  { background:#3f51b5; color:white; padding:6px 14px; font-size:.8em; display:flex; align-items:center; gap:10px; }
.pdf-viewer-bar a { color:#bbdefb; font-size:.88em; margin-left:auto; }
.pdf-iframe { width:100%; height:500px; border:none; display:block; background:#f5f5f5; }
.pdf-missing { padding:16px; text-align:center; color:#999; font-size:.86em; background:#fafafa; }
table.timeline { border-collapse:collapse; width:100%; font-size:.82em; }
table.timeline th { background:#e8eaf6; color:#1a237e; padding:5px 10px; text-align:left; }
table.timeline td { padding:5px 10px; border-bottom:1px solid #eee; }
table.timeline tr.active-row td { background:#fff9c4; font-weight:bold; }
.hist-timeline h4 { font-size:.82em; color:#555; margin:0 0 8px; text-transform:uppercase; letter-spacing:.05em; }
.dvf-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:9999;}
.dvf-modal-bg.show{display:flex;}
.dvf-modal{background:#fff;border-radius:12px;max-width:700px;width:94%;max-height:88vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3);}
.dvf-modal h3{margin:0;padding:15px 20px;border-bottom:1px solid #eee;color:#1a237e;font-size:1.1em;}
.dvf-modal .dvf-body{padding:16px 20px;font-size:.92em;color:#333;}
.dvf-modal .dvf-est{font-size:1.5em;font-weight:700;color:#2e7d32;}
.dvf-modal .dvf-sub{color:#777;font-size:.85em;}
.dvf-modal table{width:100%;border-collapse:collapse;font-size:.85em;margin-top:12px;}
.dvf-modal th,.dvf-modal td{padding:5px 8px;border-bottom:1px solid #f0f0f0;text-align:left;}
.dvf-modal th{background:#f5f6fa;color:#555;}
.dvf-modal .num-r{text-align:right;}
.dvf-modal .actions{padding:14px 20px;border-top:1px solid #eee;display:flex;gap:10px;justify-content:flex-end;}
.dvf-modal .btn-apply{background:#2e7d32;color:#fff;border:0;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;}
.dvf-modal .btn-close{background:#eee;color:#333;border:0;border-radius:8px;padding:9px 16px;cursor:pointer;}
</style>
';

// ── Total prix de vente (topbar, centré) ────────────────
// Somme des prix de vente affichés de tous les propriétaires visibles (dédoublonné/bien).
// Le total réel est recalculé en JS (les prix de vente affichés sont simulés côté client) ;
// on rend toujours le conteneur (masqué) pour que le JS puisse l'alimenter.
$topbarCenter = "<span id=\"tb-pv-total\" style=\"display:none;background:#0e6b75;color:#fff;"
    . "font-size:.9em;font-weight:bold;padding:5px 16px;border-radius:14px;white-space:nowrap;"
    . "box-shadow:0 1px 4px rgba(14,107,117,.35)\"></span>";

require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<!-- ══════════════════════════════════════════════════════════
     CONTENU PRINCIPAL
     ══════════════════════════════════════════════════════════ -->
<div style="padding:0; max-width:none; margin:0;">


<?php if ($mode !== 'proposer'): ?>
<div class="pat-summary">
  👤 <strong><?= $stats['nb_prop'] ?></strong> propriétaires &nbsp;|&nbsp;
  🏠 Actifs : <strong><?= $stats['nb_presents'] ?></strong> &nbsp;|&nbsp;
  🚪 Partis-débiteurs : <strong><?= $stats['nb_partis'] ?></strong> &nbsp;|&nbsp;
  🟢 Impayés actifs : <strong class="txt-g"><?= fmt_euro((float)$stats['impaye_actif']) ?></strong>
</div>
<?php endif; ?>

<div class="pat-searchbar">
  <span class="pat-search-ic">🔎</span>
  <input type="search" id="pat-search" class="pat-search-in"
         placeholder="🔎 Rechercher : propriétaire, ville du bien, immeuble, locataire, réf…" autocomplete="off"
         oninput="patSearch(this.value)" title="Recherche insensible à la casse et aux accents">
  <button type="button" class="pat-search-clear" onclick="patSearchClear()" title="Effacer la recherche">✕</button>
  <span id="pat-search-count" class="pat-search-count"></span>
  <div id="pat-sugg" class="pat-sugg" style="display:none"></div>
</div>
<style>
.pat-searchbar{position:relative}
.pat-sugg{position:absolute;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid #d8d4cd;
  border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.18);z-index:50;max-height:340px;overflow:auto}
.pat-sugg-item{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f3f1ec}
.pat-sugg-item:last-child{border-bottom:none}
.pat-sugg-item:hover,.pat-sugg-item.act{background:#eef2ff}
.pat-sugg-badge{font-size:10px;font-weight:800;border-radius:5px;padding:1px 6px;color:#fff;flex:none}
.pat-sugg-P{background:#5e35b1}.pat-sugg-B{background:#1d4ed8}.pat-sugg-L{background:#0f766e}
</style>

<div class="scenario-bar">
  🎬 <strong>Scénario de valorisation :</strong>
  <select id="scenario-select">
    <option value="courant|Courant">Courant (prix diffusé)</option>
    <option value="ifi|IFI">IFI</option>
    <option value="prix_min|Prix mini">Prix mini</option>
    <option value="prix_max|Prix maxi">Prix maxi</option>
  </select>
  <button type="button" class="btn-scenario-reprendre" onclick="reprendreScenario()" title="Charger les prix du scénario sélectionné dans le tableau">↻ Reprendre</button>
  <button type="button" class="btn-scenario-new" onclick="creerScenario()">＋ Nouveau scénario</button>
  <button type="button" class="btn-scenario-save" id="btn-scenario-save" onclick="enregistrerScenario()" style="display:none">💾 Enregistrer ce scénario</button>
  <button type="button" class="btn-export-xls" onclick="exporterPatrimoine()" title="Exporter l'état du patrimoine affiché À L'INSTANT (prix en cours de saisie) — fichier nommé par propriétaire + utilisateur, à archiver en GED">📥 Export Excel</button>
  <button type="button" class="btn-scenario-reprendre" onclick="ouvrirCompareScenarios()" title="Comparer 2 ou 3 scénarios côte à côte">⚖️ Comparer des scénarios</button>
  <span id="scenario-info" class="scenario-info"></span>
  <?php if ($mode === 'proposer'): ?>
  <!-- Ligne 1 : montants (poussés à droite) -->
  <span id="pf-totaux" class="pf-totaux-line1"></span>
  <!-- Ligne 2 : plier/déplier + filtre, alignés à droite -->
  <span class="pf-filter-inline" title="Le scénario sélectionné s'applique à tous les propriétaires. Seul « Courant » alimente l'annonce/Ubiflow.">
    <button type="button" class="btn-foldall" onclick="expandAllDetails()" title="Déplier tous les propriétaires">⊞ Tout déplier</button>
    <button type="button" class="btn-foldall" onclick="collapseAllDetails()" title="Replier tous les propriétaires">⊟ Tout replier</button>
    <span class="pf-flbl" style="margin-left:14px;">Filtrer :</span>
    <button class="pf-fbtn active" data-f="all" onclick="pfFilter('all',this)">Tous</button>
    <button class="pf-fbtn" data-f="1" onclick="pfFilter('1',this)">✓ À proposer</button>
    <button class="pf-fbtn" data-f="0" onclick="pfFilter('0',this)">✗ À ne pas proposer</button>
    <span class="pf-rangefilter">
      <span class="pf-flbl">Prix</span>
      <input type="number" id="pf-prix-min" class="pf-rin" placeholder="min €" min="0" step="1000" oninput="pfRange()">
      <span class="pf-rsep">–</span>
      <input type="number" id="pf-prix-max" class="pf-rin" placeholder="max €" min="0" step="1000" oninput="pfRange()">
      <span class="pf-flbl" style="margin-left:10px;">Surface</span>
      <input type="number" id="pf-surf-min" class="pf-rin pf-rin-sm" placeholder="min" min="0" step="1" oninput="pfRange()">
      <span class="pf-rsep">–</span>
      <input type="number" id="pf-surf-max" class="pf-rin pf-rin-sm" placeholder="max" min="0" step="1" oninput="pfRange()">
      <span class="pf-runit">m²</span>
      <button type="button" class="pf-rclear" onclick="pfRangeClear()" title="Effacer les filtres prix / surface">✕</button>
    </span>
    <span id="pf-fcount" style="color:#6b7280;font-size:12px;margin-left:4px;"></span>
  </span>
  <?php else: ?>
  <span class="scenario-hint">Les saisies de prix sont rattachées au scénario sélectionné. Seul « Courant » alimente l'annonce/Ubiflow.</span>
  <span class="pf-filter-inline">
    <button type="button" class="btn-foldall" onclick="expandAllDetails()" title="Déplier tous les propriétaires">⊞ Tout déplier</button>
    <button type="button" class="btn-foldall" onclick="collapseAllDetails()" title="Replier tous les propriétaires">⊟ Tout replier</button>
  </span>
  <?php endif; ?>
</div>

<!-- Formulaire caché : l'export POST les prix AFFICHÉS (live) au moment du clic -->
<form id="export-form" method="post" action="bailleur_patrimoine_export.php<?= $backQuery ?>" style="display:none">
  <input type="hidden" name="prix_live" id="export-prix">
  <input type="hidden" name="scenario_label" id="export-scenario">
</form>

<div class="main-pat-wrap">
<table class="main-pat">
<thead<?= $singlePropMode ? ' style="display:none"' : '' ?>><tr>
  <th class="mp-sort" onclick="sortMainPat(this,0)" style="cursor:pointer">PROPRIÉTAIRE <span class="sort-icon">⇅</span></th>
  <th class="mp-sort" onclick="sortMainPat(this,1)" style="cursor:pointer">TYPE <span class="sort-icon">⇅</span></th>
  <th class="mp-sort" onclick="sortMainPat(this,2)" style="text-align:center;cursor:pointer">BIENS <span class="sort-icon">⇅</span></th>
  <th class="mp-sort" onclick="sortMainPat(this,3)" style="text-align:center;cursor:pointer">ACTIFS <span class="sort-icon">⇅</span></th>
  <th class="mp-sort num-r" onclick="sortMainPat(this,4)" style="cursor:pointer" title="Loyers appelés par mois">LOYER<br>/mois € <span class="sort-icon">⇅</span></th>
  <th class="mp-sort num-r" onclick="sortMainPat(this,5)" style="cursor:pointer" title="Encaissé T1 2026">ENC.<br>T1 26 € <span class="sort-icon">⇅</span></th>
  <th class="mp-sort num-r" onclick="sortMainPat(this,6)" style="cursor:pointer" title="Encaissé 2025">ENC.<br>2025 € <span class="sort-icon">⇅</span></th>
<?php if ($mode === 'proposer'): ?>
  <th class="mp-sort num-r" onclick="sortMainPat(this,7)" style="cursor:pointer" title="Valeur patrimoine proposé">VALEUR<br>proposé € <span class="sort-icon">⇅</span></th>
  <th class="mp-sort num-r" onclick="sortMainPat(this,8)" style="cursor:pointer">BIENS<br>proposés <span class="sort-icon">⇅</span></th>
<?php else: ?>
  <th class="mp-sort num-r" onclick="sortMainPat(this,7)" style="cursor:pointer" title="Impayés des locataires actifs">IMPAYÉS<br>actifs € <span class="sort-icon">⇅</span></th>
  <th class="mp-sort num-r" onclick="sortMainPat(this,8)" style="cursor:pointer" title="Valeur du patrimoine">VALEUR<br>patrim. € <span class="sort-icon">⇅</span></th>
<?php endif; ?>
</tr></thead>
<tbody>
<?php
// ── REGROUPEMENT PAR TIERS ───────────────────────────────────────────────
// Un même propriétaire réel (tiers) peut avoir PLUSIEURS comptes CRG = plusieurs
// fiches proprietaire (ex. GROUPE SIR = comptes 0230 + 0104). La compta exige 1 compte
// par proprietaire, donc on NE fusionne PAS les fiches : on les regroupe À L'AFFICHAGE
// sur la fiche canonique (plus petit id partageant le tiers). Biens + agrégats cumulés.
$byTiers = [];
foreach ($rows as $r) { $t = (int)($r['id_tiers'] ?? 0); if ($t > 0) $byTiers[$t][] = (int)$r['id']; }
$foldInto = [];   // pid source => pid canonique
foreach ($byTiers as $pids) {
    if (count($pids) < 2) continue;
    sort($pids);
    $canon = $pids[0];
    foreach ($pids as $pid) { if ($pid !== $canon) $foldInto[$pid] = $canon; }
}
if ($foldInto) {
    $rowsById = []; foreach ($rows as $r) { $rowsById[(int)$r['id']] = $r; }
    foreach ($foldInto as $src => $canon) {
        // Détails (biens/locataires) + biens hors-CRG repliés sur le canonique
        if (!empty($details[$src]))    { $details[$canon] = array_merge($details[$canon] ?? [], $details[$src]); unset($details[$src]); }
        if (!empty($extraBiens[$src])) { $extraBiens[$canon] = array_merge($extraBiens[$canon] ?? [], $extraBiens[$src]); unset($extraBiens[$src]); }
        // Agrégats additifs
        if (isset($rowsById[$src], $rowsById[$canon])) {
            foreach (['nb_biens','nb_presents','nb_partis','loyer_total','impaye_actif','creances_partis','enc_t1_2026','enc_2025'] as $k) {
                $rowsById[$canon][$k] = (float)($rowsById[$canon][$k] ?? 0) + (float)($rowsById[$src][$k] ?? 0);
            }
        }
    }
    // Retire les fiches sources, garde les canoniques (agrégats cumulés), re-trie par impayé.
    $rows = array_values(array_filter($rowsById, fn($r) => !isset($foldInto[(int)$r['id']])));
    usort($rows, fn($a,$b) => ((float)$b['impaye_actif'] <=> (float)$a['impaye_actif']) ?: ((float)$b['creances_partis'] <=> (float)$a['creances_partis']));
}

// ── Dossiers CRÉANCIERS / SAISIES par tiers (1 requête groupée) ──────────────
// Sert au bouton rouge « Dossier créancier en cours » dans l'en-tête de chaque
// propriétaire. Lecture seule, ignorée si les tables créanciers ne sont pas là.
$creancierByTiers = [];
$tiersIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['id_tiers'] ?? 0), $rows))));
if ($tiersIds) {
    try {
        $ph = implode(',', array_fill(0, count($tiersIds), '?'));
        $stCre = $pdo->prepare("
            SELECT cdl.entity_id AS id_tiers, COUNT(DISTINCT cd.id) AS nb
              FROM creancier_dossier_lien cdl
              JOIN creancier_dossier cd ON cd.id = cdl.id_dossier
             WHERE cdl.entity_type = 'TIERS' AND cdl.entity_id IN ($ph)
             GROUP BY cdl.entity_id
        ");
        $stCre->execute($tiersIds);
        foreach ($stCre->fetchAll(PDO::FETCH_ASSOC) as $r) { $creancierByTiers[(int)$r['id_tiers']] = (int)$r['nb']; }
    } catch (Throwable $e) { $creancierByTiers = []; }
}

$tot_loyer=$tot_actif=$tot_partis=$tot_enc_t1=$tot_enc_2025=0;
$patIndex = [];   // index d'autocomplétion (propriétaires / biens / locataires)
foreach ($rows as $row):
    $pid = $row['id'];
    $nom = trim(implode(' ', array_filter([
        $row['civilite'], $row['prenom'], $row['nom'],
        ($row['societe'] && $row['societe'] !== $row['nom']) ? $row['societe'] : null,
    ])));
    // Blob de recherche du propriétaire = son nom + toutes ses lignes (locataires, adresses, villes).
    $propSearch = pa_norm($nom);
    foreach ($details[$pid] as $dd) { $propSearch .= ' ' . pa_row_search($dd); }
    $propSearch = e($propSearch);
    // ── Index d'autocomplétion : propriétaire + ses biens + ses locataires ──
    $patIndex[] = ['t'=>'P','l'=>$nom,'pid'=>(int)$pid];
    $seenBidx = [];
    foreach ($details[$pid] as $dd) {
        $ibx = (int)($dd['id_bien'] ?? 0);
        $ref = trim((string)($dd['reference_bien'] ?? ''));
        $adr = trim((string)($dd['bien_adresse'] ?? $dd['adresse_1'] ?? ''));
        $vil = trim((string)($dd['bien_ville'] ?? $dd['imm_ville'] ?? ''));
        if ($adr === '') $adr = trim((string)($dd['adresse_1'] ?? ''));      // repli : rue de l'immeuble
        if ($vil === '') $vil = trim((string)($dd['imm_ville'] ?? ''));      // repli : ville de l'immeuble
        $imm = trim((string)($dd['nom_immeuble'] ?? ''));                    // nom d'immeuble (recherchable)
        if ($ibx > 0 && !isset($seenBidx[$ibx])) {
            $seenBidx[$ibx] = 1;
            $label = trim(implode(' · ', array_filter([$ref, trim($adr.($vil ? ' '.$vil : '')), $imm])));
            $patIndex[] = ['t'=>'B','l'=>$label,'u'=>'bien_360.php?id='.$ibx];
        }
        $loc = trim((string)($dd['locataire_nom'] ?? ''));
        if ($loc !== '' && $loc !== 'OCCUPÉ PAR PROPRIÉTAIRE') {
            $patIndex[] = ['t'=>'L','l'=>$loc.($ref ? ' · '.$ref : ''),
                           'u'=>'locataire_360.php?id_bien='.$ibx.'&id_proprietaire='.(int)$pid.'&loc='.rawurlencode($loc)];
        }
    }
    $type = $row['societe']
        ? "<span class='badge b-soc'>SOCIÉTÉ</span>"
        : "<span class='badge b-phy'>PHYSIQUE</span>";
    // Loyer mensuel du propriétaire = somme des loyers (baux PATRIMOINE, repli CRG) des actifs non vendus/non archivés
    $loyerMoisProp = sumLoyerMois(array_filter($details[$pid],
        fn($d) => (int)($d['imm_vendu'] ?? 0) === 0 && (int)($d['loc_archive'] ?? 0) === 0 && ($d['presence'] ?? '') === 'present'));
    $pct = $loyerMoisProp > 0 ? round($row['impaye_actif']/$loyerMoisProp*100) : 0;
    $enc_t1   = $row['enc_t1_2026'] ?? 0;
    $enc_2025 = $row['enc_2025']    ?? 0;
    $q = $row['impaye_actif']==0
        ? "<span class='badge b-ok'>🟢 OK</span>"
        : ($pct<50 ? "<span class='badge b-warn'>🟠 {$pct}%</span>"
                   : "<span class='badge b-bad'>🔴 {$pct}%</span>");
    $tot_loyer+=$loyerMoisProp; $tot_actif+=$row['impaye_actif'];
    $tot_partis+=$row['creances_partis']; $tot_enc_t1+=$enc_t1; $tot_enc_2025+=$enc_2025;

    $groups     = groupByImmeuble($details[$pid]);
    $actifs_imm = array_values(array_filter($groups, fn($g)=>$g['vendu']==0));
    $vendus_imm = array_values(array_filter($groups, fn($g)=>$g['vendu']==1));
    $nb_vendus  = count($vendus_imm);

    // Mode proposer : valeur patrimoine (Σ prix de vente) + nb de biens proposés (distincts)
    $valPatrimoine = sumPrixVente($details[$pid]);
    $nbProposes = 0; $seenBienProp = [];
    foreach ($details[$pid] as $dd) {
        $bidd = (int)$dd['id_bien'];
        if (isset($seenBienProp[$bidd])) continue;
        $seenBienProp[$bidd] = 1;
        if ((int)($dd['a_proposer'] ?? 0) === 1) $nbProposes++;
    }
    $totValPatrimoine = ($totValPatrimoine ?? 0) + $valPatrimoine;
    $totNbProposes    = ($totNbProposes ?? 0) + $nbProposes;

    $ptot = ['loyer'=>0,'regle'=>0,'impaye_a'=>0,'impaye_p'=>0,'impaye_ar'=>0];
    foreach ($actifs_imm as $g) {
        $ta  = ['loyer'=>sumLoyerMois($g['actifs']),
                'regle'=>array_sum(array_column($g['actifs'],'total_regle')),
                'impaye'=>array_sum(array_column($g['actifs'],'total_impaye'))];
        $tp  = ['regle'=>array_sum(array_column($g['partis'],'total_regle')),
                'impaye'=>array_sum(array_column($g['partis'],'total_impaye'))];
        $tar = ['regle'=>array_sum(array_column($g['archives'],'total_regle')),
                'impaye'=>array_sum(array_column($g['archives'],'total_impaye'))];
        $ptot['loyer']     += $ta['loyer'];
        $ptot['regle']     += $ta['regle']+$tp['regle']+$tar['regle'];
        $ptot['impaye_a']  += $ta['impaye'];
        $ptot['impaye_p']  += $tp['impaye'];
        $ptot['impaye_ar'] += $tar['impaye'];
    }
?>
<tr class="prop-row<?= $singlePropMode ? ' active' : '' ?>"
    onclick="toggleDetail(<?=$pid?>)" id="row-<?=$pid?>" data-search="<?=$propSearch?>"
    <?= $singlePropMode ? 'style="display:none"' : '' ?>>
  <td><strong><?=e($nom)?></strong><span class="arrow" id="arr-<?=$pid?>">▼</span></td>
  <td><?=$type?></td>
  <td style="text-align:center"><?=$row['nb_biens']?>
    <?=$nb_vendus>0?"<span style='color:#9e9e9e;font-size:.76em'>(+{$nb_vendus}v)</span>":""?>
  </td>
  <td style="text-align:center"><?=$row['nb_presents']?>
    <?=$row['nb_partis']>0?"<span style='color:#880e4f;font-size:.76em'>(+{$row['nb_partis']}p)</span>":""?>
  </td>
  <td class="num-r"><?=fmt_euro((float)$loyerMoisProp)?></td>
  <td class="num-r txt-b"><?=$enc_t1>0?fmt_euro((float)$enc_t1):'—'?></td>
  <td class="num-r" style="color:#1565c0"><?=$enc_2025>0?fmt_euro((float)$enc_2025):'—'?></td>
<?php if ($mode === 'proposer'): ?>
  <td class="num-r" style="color:#1a237e;font-weight:700" id="valpat-<?=$pid?>"><?=fmt_euro((float)$valPatrimoine)?></td>
  <td class="num-r" style="color:#2e7d32;font-weight:700"><?=$nbProposes?></td>
<?php else: ?>
  <td class="num-r txt-g"><?=fmt_euro((float)$row['impaye_actif'])?></td>
  <td class="num-r" style="color:#1a237e;font-weight:700" id="valpat-<?=$pid?>"><?=fmt_euro((float)$valPatrimoine)?></td>
<?php endif; ?>
</tr>
<tr class="detail-row<?= $singlePropMode ? ' open' : '' ?>" id="detail-<?=$pid?>">
  <td colspan="9">
    <div class="detail-inner">
      <?php $propPrixVente = sumPrixVente($details[$pid]); ?>
      <h4>
        👤 <?=e($nom)?> —
        <?=$row['nb_presents']?> actif(s) · <?=$row['nb_partis']?> parti(s) · <?=$row['nb_biens']?> bien(s)
        <?=$nb_vendus>0?"· <span style='color:#9e9e9e'>{$nb_vendus} vendu(s)</span>":""?>
        <?php if ($propPrixVente > 0): ?>
        <span class="prop-pv-total">🏷️ <?= $mode === 'proposer' ? 'Valeur patrimoine proposé' : 'Total prix de vente validés' ?> : <strong><?=fmt_euro($propPrixVente)?></strong></span>
        <?php endif; ?>
      </h4>

      <div style="margin:-4px 0 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <a class="btn-creer-bien" href="<?= e(app_url('/bien_detail.php?id_proprietaire=' . (int)$pid . '&open_immeuble=1')) ?>" target="_blank" rel="noopener"
           title="Créer un nouveau bien pour ce propriétaire (immeuble obligatoire → réf {immeuble}-9001+)">➕ Créer un bien</a>
        <?php $tidProp = (int)($row['id_tiers'] ?? 0); $nbCre = $tidProp > 0 ? (int)($creancierByTiers[$tidProp] ?? 0) : 0; ?>
        <?php if ($nbCre > 0): ?>
        <a href="<?= e(app_url('/creancier_liste.php?tiers=' . $tidProp)) ?>" target="_blank" rel="noopener"
           title="Voir les dossiers créanciers / saisies de ce propriétaire"
           style="display:inline-flex;align-items:center;gap:7px;background:#dc2626;color:#fff;font-weight:700;font-size:13px;padding:9px 15px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(220,38,38,.3);">
          🚨 Dossier<?= $nbCre > 1 ? 's' : '' ?> créancier<?= $nbCre > 1 ? 's' : '' ?> en cours<?= $nbCre > 1 ? ' (' . $nbCre . ')' : '' ?>
        </a>
        <?php endif; ?>
      </div>

<?php foreach ($actifs_imm as $g):
    $iid   = (int)$g['id_immeuble'];
    $iname = e($g['nom_immeuble']);
    $iville = e(trim((string)($g['ville'] ?? '')));
    $iaddr = e($g['adresse_1']);
    $nb_a  = count($g['actifs']); $nb_p = count($g['partis']); $nb_ar = count($g['archives']);
    $uid   = "imm-{$pid}-{$iid}";
    $ta    = ['loyer'=>sumLoyerMois($g['actifs']),
              'regle'=>array_sum(array_column($g['actifs'],'total_regle')),
              'impaye'=>array_sum(array_column($g['actifs'],'total_impaye'))];
    $tp    = ['regle'=>array_sum(array_column($g['partis'],'total_regle')),
              'impaye'=>array_sum(array_column($g['partis'],'total_impaye'))];
    $tar   = ['regle'=>array_sum(array_column($g['archives'],'total_regle')),
              'impaye'=>array_sum(array_column($g['archives'],'total_impaye'))];
    $it_loyer = $ta['loyer']; // déjà mensuel (baux PATRIMOINE, repli CRG/3)
    $it_regle = $ta['regle']+$tp['regle']+$tar['regle'];
    $it_imp_a = $ta['impaye']; $it_imp_p = $tp['impaye']; $it_imp_ar = $tar['impaye'];
    $it_prixvente = sumPrixVente(array_merge($g['actifs'],$g['partis'],$g['archives']));
    // Surface totale (dédupliquée par bien) + loyer/an + rentabilité moyenne de l'immeuble
    $surfByBien = [];
    foreach (array_merge($g['actifs'],$g['partis'],$g['archives']) as $r) {
        $bid = (int)$r['id_bien'];
        $sc = (float)($r['surface_carrez'] ?? 0); $sh = (float)($r['surface_habitable'] ?? 0);
        $surfByBien[$bid] = $sc > 0 ? $sc : $sh;   // une seule surface par bien
    }
    $it_surface  = array_sum($surfByBien);
    $it_loyer_an = $it_loyer * 12;                 // cohérent avec le total "Loyers /mois" affiché
    $it_renta    = $it_prixvente > 0 ? round($it_loyer_an / $it_prixvente * 100, 2) : 0;
?>
      <div class="imm-block" id="imm-block-<?=$iid?>">
        <div class="imm-header">
          <span class="imm-title">
            <?php if ($canMerge): ?><input type="checkbox" class="imm-pick" data-iid="<?=$iid?>" data-label="<?=e(($iname?:'').' '.($iaddr?:''))?>" title="Cocher 2 immeubles pour fusionner les doublons" onclick="event.stopPropagation();immMergeSync();"> <?php endif; ?>
            🏢 <?=$iname?><?= $iville ? " <span class='imm-ville' style='color:#5a6678;font-weight:700;'>_ {$iville}</span>" : "" ?>
            <?=$iaddr?"<span class='imm-addr'>— {$iaddr}</span>":""?>
            <span class="imm-meta">
              <?=$nb_a?> actif(s)<?=$nb_p>0?"·<span style='color:#c62828'> {$nb_p}p</span>":""?><?=$nb_ar>0?"·<span style='color:#9e9e9e'> {$nb_ar}ar</span>":""?>
            </span>
          </span>
          <?php if ($it_surface > 0): ?>
          <span class="imm-stat" title="Surface totale des lots (dédupliquée par bien)">📐 <?=number_format($it_surface,0,',',' ')?> m²</span>
          <?php endif; ?>
          <?php if ($it_loyer_an > 0): ?>
          <span class="imm-stat" title="Loyer annuel total (loyer mensuel × 12)">💶 <?=fmt_euro($it_loyer_an)?>/an</span>
          <?php endif; ?>
          <?php if ($it_prixvente > 0): ?>
          <span class="imm-pv-total" title="Somme des prix de vente validés des lots de cet immeuble">🏷️ <?=fmt_euro($it_prixvente)?></span>
          <?php endif; ?>
          <?php if ($it_renta > 0): ?>
          <span class="imm-stat renta" title="Rentabilité moyenne = loyer annuel ÷ prix de vente validé">📊 <?=number_format($it_renta,2,',',' ')?> %</span>
          <?php endif; ?>
          <?php $immParam = (int)($g['id_immeuble'] ?? 0) > 0 ? '&id_immeuble=' . (int)$g['id_immeuble'] : ''; ?>
          <a class="btn-add-bien" onclick="event.stopPropagation()" target="_blank" rel="noopener"
             href="<?= e(app_url('/bien_detail.php?id_proprietaire=' . (int)$pid . $immParam . '&open_immeuble=1')) ?>"
             title="Ajouter un bien à cet immeuble (réf {immeuble}-9001+)">➕ Ajouter un bien</a>
          <button class="btn-vendu mark"
                  onclick="event.stopPropagation();openVenduModal(<?=$iid?>,'<?=addslashes($g['nom_immeuble'])?>',<?=$pid?>)">
            🏷️ Marquer vendu
          </button>
        </div>

        <?php if ($nb_a > 0): ?>
        <?=$col_headers?><?=renderLocRows($g['actifs'],'actif',$pid)?></tbody></table>
        <?php endif; ?>
        <?php /* Partis (vides) + archivés : regroupés plus bas en UN bloc par propriétaire */ ?>

        <!-- Totaux immeuble -->
        <div class="imm-totals">
          <div class="it-cell"><span class="it-label">Loyers /mois</span>
            <span class="it-val loyer"><?=$it_loyer>0?fmt_euro((float)$it_loyer):'—'?></span></div>
          <?php if ($mode !== 'proposer'): ?>
          <div class="it-cell"><span class="it-label">Total encaissé</span>
            <span class="it-val enc"><?=$it_regle>0?fmt_euro((float)$it_regle):'—'?></span></div>
          <?php endif; ?>
          <div class="it-cell"><span class="it-label">Impayés actifs</span>
            <span class="it-val imp"><?=$it_imp_a>0?fmt_euro((float)$it_imp_a):'—'?></span></div>
          <?php if ($mode === 'proposer' && $it_prixvente > 0): ?>
          <div class="it-cell"><span class="it-label">Valeur patrimoine proposé</span>
            <span class="it-val" style="color:#1a237e"><?=fmt_euro((float)$it_prixvente)?></span></div>
          <?php endif; ?>
        </div>
      </div>

<?php endforeach; ?>

<?php
  // ── Bloc consolidé PAR PROPRIÉTAIRE : biens VIDES (locataire parti) + ARCHIVÉS ──
  // Sous les biens actifs. Données déjà chargées (CRG du dernier trimestre) : zéro requête en plus.
  $allPartis = []; $allArchives = [];
  foreach ($actifs_imm as $g) {
      $allPartis   = array_merge($allPartis,   $g['partis']);
      $allArchives = array_merge($allArchives, $g['archives']);
  }
  $nbVides = count($allPartis); $nbArch = count($allArchives);
  $impVides = array_sum(array_column($allPartis, 'total_impaye'));
  // Mode « biens à proposer » : on n'affiche QUE les actifs (pas de partis/archivés).
  if ($mode !== 'proposer' && $nbVides + $nbArch > 0):
?>
      <div class="fold-toggle vides-toggle" onclick="toggleFold(this,'vides-<?=$pid?>')" style="background:#fff5f7;border-left:4px solid #ad1457">
        <span class="fold-arrow">▶</span>
        🔓 <strong>Biens vides / archivés</strong> —
        <strong><?=$nbVides?></strong> vide(s)<?=$nbArch>0?" · <strong>{$nbArch}</strong> archivé(s)":""?>
        <?=$impVides>0?" · <span style='color:#c62828'>".fmt_euro((float)$impVides)." créances résiduelles</span>":""?>
      </div>
      <div class="fold-content" id="vides-<?=$pid?>">
        <?php if ($nbVides > 0): ?>
        <div style="font-size:.82em;color:#ad1457;font-weight:600;margin:6px 0 2px">🚪 Vides (locataire parti — lot libre)</div>
        <?=$col_headers?><?=renderLocRows($allPartis,'parti',$pid)?></tbody></table>
        <?php endif; ?>
        <?php if ($nbArch > 0): ?>
        <div style="font-size:.82em;color:#9e9e9e;font-weight:600;margin:8px 0 2px">📦 Archivés (dossiers clos)</div>
        <?=$col_headers?><?=renderLocRows($allArchives,'archive',$pid)?></tbody></table>
        <?php endif; ?>
      </div>
<?php endif; ?>

<?php if ($mode !== 'proposer' && $nb_vendus > 0): ?>
      <div class="fold-toggle vendus-toggle" onclick="toggleFold(this,'vendus-<?=$pid?>')">
        <span class="fold-arrow">▶</span>
        🏷️ <?=$nb_vendus?> immeuble(s) vendu(s)
      </div>
      <div class="fold-content" id="vendus-<?=$pid?>">
<?php foreach ($vendus_imm as $g):
    $iid   = (int)$g['id_immeuble'];
    $iname = e($g['nom_immeuble']); $iaddr = e($g['adresse_1']);
    $iville = e(trim((string)($g['ville'] ?? '')));
    $dvente = $g['date_vente'] ? ' · vendu '.date('d/m/Y',strtotime($g['date_vente'])) : '';
    $all_nb = count($g['actifs'])+count($g['partis'])+count($g['archives']);
?>
        <div class="imm-block vendu-block">
          <div class="imm-header vendu-header">
            <span class="imm-title vendu-title">
              🏢 <?=$iname?><?= $iville ? " <span style='color:#5a6678;font-weight:700;'>_ {$iville}</span>" : "" ?><?=$iaddr?"<span class='imm-addr'> — {$iaddr}</span>":""?>
              <span style="color:#9e9e9e;font-size:.76em"><?=$dvente?></span>
            </span>
            <button class="btn-vendu unmark"
                    onclick="event.stopPropagation();unmarquerVendu(<?=$iid?>,<?=$pid?>)">↩️ Restaurer</button>
          </div>
          <?php if ($all_nb > 0): ?>
          <?=$col_headers?>
          <?=renderLocRows($g['actifs'],'actif',$pid)?>
          <?=renderLocRows($g['partis'],'parti',$pid)?>
          <?=renderLocRows($g['archives'],'archive',$pid)?>
          </tbody></table>
          <?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
<?php endif; ?>

<?php
  // ── Biens rattachés hors CRG (ex: CB FINANCES sous GROUPE SIR) ──
  if (!empty($extraBiens[$pid])):
      $exRows = $extraBiens[$pid];
      $exLoyerAn = 0; $exPrix = 0;
      foreach ($exRows as $xr) { $exLoyerAn += ((float)($xr['loyer_hc'] ?? 0)) * 12; $exPrix += (float)($xr['prix_vente'] ?? 0); }
?>
      <div class="fold-toggle" onclick="toggleFold(this,'extra-<?=$pid?>')" style="background:#eef5fb;border-left:4px solid #4878a6">
        <span class="fold-arrow">▶</span>
        🏢 <strong>Biens rattachés (hors CRG)</strong> — <strong><?=count($exRows)?></strong> bien(s)
        <?=$exPrix>0?" · <span style='color:#2c5687'>".fmt_euro($exPrix)." prix de vente</span>":""?>
      </div>
      <div class="fold-content" id="extra-<?=$pid?>">
        <table class="loc-table" style="width:100%"><thead><tr>
          <th>Propriétaire</th><th>Adresse</th><th>Réf.</th><th style="text-align:right">Surface</th>
          <th style="text-align:right">Loyer /mois</th><th style="text-align:right">Prix de vente</th>
        </tr></thead><tbody>
        <?php foreach ($exRows as $xr): ?>
          <tr>
            <td><strong><?=e($xr['proprio'] ?? '')?></strong></td>
            <td><a href="bien_360.php?id=<?=(int)$xr['id']?>" target="_blank" rel="noopener" style="color:#2c2a28;text-decoration:none"><?=e($xr['adresse'] ?: ('Bien #'.$xr['id']))?><?=$xr['ville']?' · '.e($xr['ville']):''?> ↗</a></td>
            <td><?=e($xr['reference_bien'] ?: '—')?></td>
            <td style="text-align:right"><?=$xr['surface']?number_format((float)$xr['surface'],0,',',' ').' m²':'—'?></td>
            <td style="text-align:right"><?=$xr['loyer_hc']?fmt_euro((float)$xr['loyer_hc']):'—'?></td>
            <td style="text-align:right"><?=$xr['prix_vente']?fmt_euro((float)$xr['prix_vente']):'—'?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
<?php endif; ?>

    </div>
  </td>
</tr>
<?php endforeach; ?>
<tr class="total-row">
  <td colspan="4"><strong>TOTAL (<?=count($rows)?> propriétaires)</strong></td>
  <td class="num-r"><?=fmt_euro((float)$tot_loyer)?></td>
  <td class="num-r txt-b"><?=fmt_euro((float)$tot_enc_t1)?></td>
  <td class="num-r" style="color:#1565c0"><?=fmt_euro((float)$tot_enc_2025)?></td>
<?php if ($mode === 'proposer'): ?>
  <td class="num-r" style="color:#1a237e;font-weight:800" id="valpat-total"><?=fmt_euro((float)($totValPatrimoine ?? 0))?></td>
  <td class="num-r" style="color:#2e7d32;font-weight:800"><?=(int)($totNbProposes ?? 0)?></td>
<?php else: ?>
  <td class="num-r txt-g"><?=fmt_euro((float)$tot_actif)?></td>
  <td class="num-r" style="color:#1a237e;font-weight:800" id="valpat-total"><?=fmt_euro((float)($totValPatrimoine ?? 0))?></td>
<?php endif; ?>
</tr>
</tbody>
</table>
</div><!-- /main-pat-wrap -->

</div><!-- /main container -->

<!-- MODAL VENDU -->
<div class="modal-overlay" id="modal-vendu">
  <div class="modal-box">
    <h3>🏷️ Marquer l'immeuble comme vendu</h3>
    <p id="modal-imm-name" style="font-weight:bold;color:#1a237e;margin:0 0 12px"></p>
    <label>Date de vente (optionnelle) :
      <input type="date" id="modal-date-vente">
    </label>
    <div class="modal-btns">
      <button class="btn-cancel" onclick="closeVenduModal()">Annuler</button>
      <button class="btn-confirm" id="modal-vendu-confirm" onclick="confirmerVendu()">Confirmer</button>
    </div>
  </div>
</div>

<!-- MODAL HISTORIQUE LOCATAIRE -->
<div class="hist-overlay" id="hist-overlay" onclick="if(event.target===this)closeLocHistory()">
  <div class="hist-box">
    <div class="hist-header">
      <div style="flex:1">
        <h3 id="hist-nom">—</h3>
        <div style="font-size:.8em;opacity:.8" id="hist-sub">—</div>
      </div>
      <button class="hist-close" onclick="closeLocHistory()">✕</button>
    </div>
    <div class="hist-tabs" id="hist-tabs"></div>
    <div class="hist-body" id="hist-body"><div class="hist-loading">⏳ Chargement…</div></div>
  </div>
</div>

<!-- MODAL RÉSULTAT MISE EN VENTE -->
<div class="hist-overlay" id="vente-overlay" onclick="if(event.target===this)closeVente()">
  <div class="hist-box" style="max-width:560px">
    <div class="hist-header">
      <div style="flex:1"><h3 id="vente-titre">🏷️ Mise en vente</h3>
        <div style="font-size:.8em;opacity:.8" id="vente-sub">—</div></div>
      <button class="hist-close" onclick="closeVente()">✕</button>
    </div>
    <div class="hist-body" id="vente-body"><div class="hist-loading">⏳ Bascule en cours…</div></div>
  </div>
</div>

<?php
// JS inline
$extraJs = <<<'JS'
<script>
const API_BASE = '/';

// ── ACCORDION PRINCIPAL ─────────────────────────────────
function toggleDetail(pid) {
    const d=document.getElementById('detail-'+pid), r=document.getElementById('row-'+pid);
    const isOpen=d.classList.contains('open');
    document.querySelectorAll('.detail-row.open').forEach(el=>el.classList.remove('open'));
    document.querySelectorAll('.prop-row.active').forEach(el=>el.classList.remove('active'));
    if(!isOpen){d.classList.add('open');r.classList.add('active');}
    if(window.patSave)patSave();
}
function expandAllDetails(){
    document.querySelectorAll('.detail-row').forEach(function(el){el.classList.add('open');});
    document.querySelectorAll('.prop-row').forEach(function(el){el.classList.add('active');});
    if(window.patSave)patSave();
}
function collapseAllDetails(){
    document.querySelectorAll('.detail-row.open').forEach(function(el){el.classList.remove('open');});
    document.querySelectorAll('.prop-row.active').forEach(function(el){el.classList.remove('active');});
    if(window.patSave)patSave();
}

// ── RECHERCHE locataire / adresse / ville (insensible casse + accents) ──
// Mono-propriétaire = une seule carte proprio (les prop-row sont masquées et le détail est ouvert d'office).
var PAT_SINGLE = document.querySelectorAll('.prop-row').length <= 1;
// Doit rester cohérent avec pa_norm() côté PHP.
function paNorm(s){
    s=(s||'').toString().toLowerCase().trim();
    s=s.normalize('NFD').replace(/[\u0300-\u036f]/g,''); // retire les accents
    return s.replace(/\s+/g,' ');
}
function patSearch(q){
    var cnt=document.getElementById('pat-search-count');
    q=paNorm(q);
    document.querySelectorAll('.pat-hit').forEach(function(el){ el.classList.remove('pat-hit'); });
    if(!q){ patSearchReset(); if(cnt) cnt.textContent=''; return; }
    var toks=q.split(' ').filter(Boolean);
    function hit(hay){ hay=hay||''; for(var i=0;i<toks.length;i++){ if(hay.indexOf(toks[i])<0) return false; } return true; }

    // Règle : on va DIRECTEMENT sur ce qui correspond, on n'ouvre PAS tout le patrimoine.
    //  · une ligne (locataire/immeuble/ville/réf) ne s'affiche que si ELLE correspond ;
    //  · le détail d'un propriétaire ne se déplie que s'il contient au moins une ligne trouvée ;
    //  · si seul le NOM du propriétaire correspond (sans ligne), on met sa ligne en évidence
    //    sans déballer son patrimoine (l'utilisateur clique pour l'ouvrir).
    var shownRows=0, shownProps=0, firstHit=null;
    document.querySelectorAll('.prop-row').forEach(function(r){
        var pid=r.id.replace('row-','');
        var d=document.getElementById('detail-'+pid);
        var propMatch=hit(r.getAttribute('data-search')||'');   // nom du propriétaire uniquement
        var anyRow=false;
        if(d){
            d.querySelectorAll('tr.pf-frow').forEach(function(tr){
                var ok=hit(tr.getAttribute('data-search')||'');
                tr.style.display = ok ? '' : 'none';
                if(ok){ anyRow=true; shownRows++; tr.classList.add('pat-hit'); if(!firstHit) firstHit=tr; }
            });
            // Sections repliables : ouvrir celles qui contiennent un match, masquer les vides.
            d.querySelectorAll('.fold-content').forEach(function(fc){
                var vis=false;
                fc.querySelectorAll('tr.pf-frow').forEach(function(tr){ if(tr.style.display!=='none') vis=true; });
                var tg=(fc.previousElementSibling && fc.previousElementSibling.classList.contains('fold-toggle')) ? fc.previousElementSibling : null;
                fc.classList.toggle('open', vis);
                fc.style.display = vis ? '' : 'none';
                if(tg){ tg.classList.toggle('open', vis); tg.style.display = vis ? '' : 'none'; }
            });
            // Masque les lignes de totaux pendant la recherche.
            d.querySelectorAll('tr.tot-sub').forEach(function(tr){ tr.style.display='none'; });
        }
        var show=anyRow||propMatch;
        if(!PAT_SINGLE){
            if(show){
                r.style.display=''; r.classList.add('active'); shownProps++;
                // On ne déplie QUE si des lignes correspondent (pas juste le nom).
                if(d){ if(anyRow){ d.classList.add('open'); } else { d.classList.remove('open'); } }
                if(!anyRow && propMatch){ r.classList.add('pat-hit'); if(!firstHit) firstHit=r; }
            } else {
                r.style.display='none'; r.classList.remove('active'); if(d) d.classList.remove('open');
            }
        } else if(show){ shownProps++; }
    });
    // Blocs immeuble : masque ceux sans aucune ligne visible.
    document.querySelectorAll('.imm-block').forEach(function(b){
        var vis=false;
        b.querySelectorAll('tr.pf-frow').forEach(function(tr){ if(tr.style.display!=='none') vis=true; });
        b.style.display = vis ? '' : 'none';
    });
    if(cnt) cnt.textContent = (PAT_SINGLE ? '' : (shownProps+' propriétaire(s) · ')) + shownRows+' bien(s)';
    if(firstHit){ try{ firstHit.scrollIntoView({behavior:'smooth',block:'center'}); }catch(e){ try{ firstHit.scrollIntoView(); }catch(_){} } }
}

// Tri du tableau principal par GROUPE (ligne propriétaire + sa ligne détail bougent ensemble).
function sortMainPat(th, col){
    var table=th.closest('table'), tb=table?table.querySelector('tbody'):null; if(!tb) return;
    var dir = th.getAttribute('data-dir')==='asc' ? 'desc' : 'asc';
    table.querySelectorAll('thead th').forEach(function(h){ h.setAttribute('data-dir',''); var s=h.querySelector('.sort-icon'); if(s) s.textContent='⇅'; });
    th.setAttribute('data-dir', dir);
    var si=th.querySelector('.sort-icon'); if(si) si.textContent = dir==='asc' ? '▲' : '▼';
    var rows=Array.prototype.slice.call(tb.children), groups=[], tail=[];
    for(var i=0;i<rows.length;i++){
        if(rows[i].classList.contains('prop-row')){
            var grp=[rows[i]];
            while(i+1<rows.length && !rows[i+1].classList.contains('prop-row')){ grp.push(rows[i+1]); i++; }
            groups.push(grp);
        } else { tail.push(rows[i]); }   // lignes hors groupe (ex. TOTAL)
    }
    function val(grp){
        var c=grp[0].children[col]; if(!c) return '';
        var t=(c.innerText||'').replace(/\s+/g,' ').trim();
        if(/^[\s\d.,€%+()v p-]+$/i.test(t) && t!==''){ var n=parseFloat(t.replace(/[^0-9.,-]/g,'').replace(',','.')); if(!isNaN(n)) return n; }
        return t.toLowerCase();
    }
    groups.sort(function(a,b){ var va=val(a), vb=val(b);
        if(typeof va==='number' && typeof vb==='number') return dir==='asc'? va-vb : vb-va;
        return dir==='asc'? String(va).localeCompare(String(vb),'fr') : String(vb).localeCompare(String(va),'fr'); });
    groups.forEach(function(grp){ grp.forEach(function(r){ tb.appendChild(r); }); });
    tail.forEach(function(r){ tb.appendChild(r); });
}

function patSearchReset(){
    document.querySelectorAll('.pat-hit').forEach(function(el){ el.classList.remove('pat-hit'); });
    document.querySelectorAll('tr.pf-frow').forEach(function(tr){ tr.style.display=''; });
    document.querySelectorAll('tr.tot-sub').forEach(function(tr){ tr.style.display=''; });
    document.querySelectorAll('.imm-block').forEach(function(b){ b.style.display=''; });
    // Referme les sections repliables et restaure leurs boutons (état par défaut).
    document.querySelectorAll('.fold-content').forEach(function(fc){ fc.classList.remove('open'); fc.style.display=''; });
    document.querySelectorAll('.fold-toggle').forEach(function(tg){ tg.classList.remove('open'); tg.style.display=''; });
    if(PAT_SINGLE) return; // layout mono-propriétaire : détail déjà ouvert, prop-row masquée
    document.querySelectorAll('.prop-row').forEach(function(r){ r.style.display=''; r.classList.remove('active'); });
    document.querySelectorAll('.detail-row.open').forEach(function(d){ d.classList.remove('open'); });
}
function patSearchClear(){
    var i=document.getElementById('pat-search');
    if(i){ i.value=''; i.focus(); }
    patSearch('');
}

// ── SECTIONS PLIABLES ───────────────────────────────────
function toggleFold(toggle,contentId){
    const c=document.getElementById(contentId); if(!c)return;
    const open=c.classList.toggle('open');
    toggle.classList.toggle('open',open);
    if(window.patSave)patSave();
}

// ── TRI COLONNES ────────────────────────────────────────
function sortTable(th){
    const table=th.closest('table'),tbody=table.querySelector('tbody');
    const ths=Array.from(th.closest('tr').querySelectorAll('th[data-col]'));
    const col=parseInt(th.dataset.col),cur=th.dataset.dir||'asc',next=cur==='asc'?'desc':'asc';
    ths.forEach(h=>{h.dataset.dir='';h.querySelector('.sort-icon').textContent='⇅';});
    th.dataset.dir=next;th.querySelector('.sort-icon').textContent=next==='asc'?'▲':'▼';
    const allRows=Array.from(tbody.querySelectorAll('tr'));
    const dataRows=allRows.filter(r=>!r.classList.contains('tot-sub'));
    function getVal(row){
        const cell=row.querySelectorAll('td')[col];if(!cell)return'';
        const txt=cell.innerText.trim();
        if(col===1){const m=txt.match(/^(\S+)-(\d+)$/);if(m)return m[1]+'-'+m[2].padStart(8,'0');return txt.toLowerCase();}
        const clean=txt.replace(/[€\s ]/g,'').replace(',','.');
        const num=parseFloat(clean);return isNaN(num)?txt.toLowerCase():num;
    }
    function sortRows(rows){return rows.sort((a,b)=>{const va=getVal(a),vb=getVal(b);
        if(typeof va==='number'&&typeof vb==='number')return next==='asc'?va-vb:vb-va;
        return next==='asc'?String(va).localeCompare(String(vb),'fr'):String(vb).localeCompare(String(va),'fr');});}
    sortRows(dataRows).forEach(r=>tbody.appendChild(r));
    allRows.filter(r=>r.classList.contains('tot-sub')).forEach(r=>tbody.appendChild(r));
}

// ── ARCHIVER / RESTAURER LOCATAIRE ──────────────────────
function archiveLoc(idBien,nom,pid,archive){
    if(!confirm(archive?'Archiver ce locataire (dossier clos) ?':'Restaurer ce locataire ?'))return;
    const fd=new FormData();
    fd.append('id_bien',idBien);fd.append('locataire_nom',nom);
    fd.append('id_proprietaire',pid);fd.append('archive',archive);
    fetch('toggle_locataire_archive.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert('Erreur');});
}

// ── IMMEUBLE VENDU ──────────────────────────────────────
let _pendingIid=null;
function openVenduModal(iid,nom,pid){
    _pendingIid=iid;
    document.getElementById('modal-imm-name').textContent=nom;
    document.getElementById('modal-date-vente').value='';
    document.getElementById('modal-vendu').classList.add('open');
}
function closeVenduModal(){document.getElementById('modal-vendu').classList.remove('open');_pendingIid=null;}
function confirmerVendu(){
    if(!_pendingIid)return;
    const date=document.getElementById('modal-date-vente').value;
    const btn=document.getElementById('modal-vendu-confirm');
    if(btn){btn.disabled=true;btn.dataset.lbl=btn.textContent;btn.textContent='⏳ Enregistrement…';}
    const fd=new FormData();fd.append('id_immeuble',_pendingIid);fd.append('vendu',1);
    if(date)fd.append('date_vente',date);
    fetch('toggle_immeuble_vendu.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            if(d.ok){ if(btn)btn.textContent='✅ Enregistré'; setTimeout(()=>(window.patReload||(()=>location.reload()))(),350); }
            else { alert('Erreur: '+(d.msg||'?')); if(btn){btn.disabled=false;btn.textContent=btn.dataset.lbl||'Confirmer';} }
        })
        .catch(e=>{ alert('Erreur réseau : '+e.message); if(btn){btn.disabled=false;btn.textContent=btn.dataset.lbl||'Confirmer';} });
}
function unmarquerVendu(iid){
    if(!confirm('Restaurer cet immeuble ?'))return;
    const fd=new FormData();fd.append('id_immeuble',iid);fd.append('vendu',0);
    fetch('toggle_immeuble_vendu.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{if(d.ok)(window.patReload||(()=>location.reload()))();else alert('Erreur');})
        .catch(e=>alert('Erreur réseau : '+e.message));
}
document.getElementById('modal-vendu').addEventListener('click',function(e){if(e.target===this)closeVenduModal();});

// ── HISTORIQUE LOCATAIRE ────────────────────────────────
function openLocHistory(idBien,nom,pid){
    const overlay=document.getElementById('hist-overlay');
    document.getElementById('hist-nom').textContent=nom;
    document.getElementById('hist-sub').textContent='Chargement…';
    document.getElementById('hist-tabs').innerHTML='';
    document.getElementById('hist-body').innerHTML='<div class="hist-loading">⏳ Chargement…</div>';
    overlay.classList.add('open');
    fetch('get_locataire_history.php?id_bien='+idBien+'&locataire_nom='+encodeURIComponent(nom)+'&id_proprietaire='+pid)
        .then(r=>r.json()).then(data=>{
        if(!data.ok||!data.trimestres.length){
            document.getElementById('hist-body').innerHTML='<div class="hist-loading">Aucun historique trouvé.</div>';return;
        }
        const trims=data.trimestres,last=trims[trims.length-1];
        document.getElementById('hist-sub').textContent=(last.nom_immeuble||'')+(last.adresse?' — '+last.adresse:'')+'  · '+last.reference_bien+'  · '+last.proprietaire;
        const tabsEl=document.getElementById('hist-tabs'),bodyEl=document.getElementById('hist-body');
        tabsEl.innerHTML='';bodyEl.innerHTML='';
        const panels=[];
        const tabSynth=document.createElement('button');
        tabSynth.className='hist-tab active';tabSynth.textContent='📊 Synthèse';
        tabSynth.onclick=()=>switchTab(0);tabsEl.appendChild(tabSynth);
        const synthPanel=document.createElement('div');
        synthPanel.className='hist-panel active';synthPanel.innerHTML=buildSynthesePanel(trims);
        bodyEl.appendChild(synthPanel);panels.push(synthPanel);
        [...trims].reverse().forEach((t,idx)=>{
            const isLatest=idx===0;
            const tab=document.createElement('button');
            tab.className='hist-tab'+(isLatest?' latest':'');
            tab.textContent=t.label+(isLatest?' ★':'');tab.onclick=()=>switchTab(idx+1);
            tabsEl.appendChild(tab);
            const panel=document.createElement('div');
            panel.className='hist-panel';panel.innerHTML=buildTrimPanel(t,trims);
            bodyEl.appendChild(panel);panels.push(panel);
        });
        function switchTab(n){tabsEl.querySelectorAll('.hist-tab').forEach((t,i)=>t.classList.toggle('active',i===n));panels.forEach((p,i)=>p.classList.toggle('active',i===n));}
    });
}
function fmtE(v){if(!v||v==0)return'—';return'€'+parseFloat(v).toLocaleString('fr-FR',{maximumFractionDigits:0});}
function deltaHtml(d){if(d==null)return'';if(d===0)return'<span class="crg-delta" style="color:#888">= identique</span>';const sign=d>0?'▲ +':'▼ ';const cls=d>0?'delta-up':'delta-down';return`<span class="crg-delta ${cls}">${sign}€${Math.abs(d).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`;}
function buildTrimPanel(t,allTrims){
    const actif=t.loyer>0;
    const statut=actif?(t.impaye>0?'🟠 Actif — impayé':'🟢 Actif — régulier'):(t.impaye>0?'🔴 Parti — créance':'⚪ Parti — soldé');
    let pdfHtml='';
    if(t.pdf_url){
        const page=t.page_pdf||1;
        pdfHtml=`<div class="pdf-viewer-wrap"><div class="pdf-viewer-bar">📄 CRG ${t.label} — page ${page}<a href="${t.pdf_url}" target="_blank">🔗 Nouvel onglet</a></div><iframe class="pdf-iframe" src="${t.pdf_url}#page=${page}"></iframe></div>`;
    } else {
        pdfHtml='<div class="pdf-missing">📄 PDF non disponible pour ce trimestre</div>';
    }
    return`<div class="crg-grid"><div class="crg-card"><div class="cc-label">Loyer appelé</div><div class="cc-val">${fmtE(t.loyer)}</div><div class="crg-delta">${actif?'✓ Présent':'— Parti'}</div></div><div class="crg-card enc"><div class="cc-label">Total encaissé</div><div class="cc-val">${fmtE(t.regle)}</div>${deltaHtml(t.delta_regle)}</div><div class="crg-card ${t.impaye===0?'ok':'imp'}"><div class="cc-label">Solde impayé</div><div class="cc-val">${fmtE(t.impaye)}</div>${deltaHtml(t.delta_impaye)}</div></div><p style="font-size:.82em;color:#555;margin:0 0 10px">Statut : <strong>${statut}</strong></p>${pdfHtml}<div class="hist-timeline" style="margin-top:12px"><h4>Tous les trimestres</h4>${buildTimeline(allTrims,t.label)}</div>`;
}
function buildSynthesePanel(trims){
    const last=trims[trims.length-1],first=trims[0];
    const totalEnc=trims.reduce((s,t)=>s+t.regle,0);
    const trend=last.impaye-first.impaye;
    const evHtml=trims.length>1?(trend>0?`<span style="color:#c62828">▲ En hausse de €${Math.abs(trend).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:trend<0?`<span style="color:#2e7d32">▼ En baisse de €${Math.abs(trend).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:'<span style="color:#888">= Stable</span>'):'';
    return`<div class="crg-grid"><div class="crg-card"><div class="cc-label">Loyer actuel (${last.label})</div><div class="cc-val">${fmtE(last.loyer)}</div><div class="crg-delta">${last.loyer>0?'✓ Présent':'— Parti'}</div></div><div class="crg-card enc"><div class="cc-label">Cumulé encaissé</div><div class="cc-val">${fmtE(totalEnc)}</div><div class="crg-delta">${trims.length} trimestre(s)</div></div><div class="crg-card ${last.impaye===0?'ok':'imp'}"><div class="cc-label">Solde impayé actuel</div><div class="cc-val">${fmtE(last.impaye)}</div><div class="crg-delta">${evHtml}</div></div></div><div class="hist-timeline"><h4>Historique complet</h4>${buildTimeline(trims,last.label)}</div>`;
}
function buildTimeline(trims,activeLabel){
    const rows=[...trims].reverse().map(t=>{
        const isActive=t.label===activeLabel;
        const delta=t.delta_impaye!=null?(t.delta_impaye>0?`<span style="color:#c62828;font-size:.83em">▲+€${Math.abs(t.delta_impaye).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:t.delta_impaye<0?`<span style="color:#2e7d32;font-size:.83em">▼-€${Math.abs(t.delta_impaye).toLocaleString('fr-FR',{maximumFractionDigits:0})}</span>`:'<span style="color:#888;font-size:.83em">=</span>'):'';
        const pr=t.loyer>0?'🟢':'🔴';
        return`<tr class="${isActive?'active-row':''}"><td>${pr} <strong>${t.label}</strong></td><td style="text-align:right">${fmtE(t.loyer)||'—'}</td><td style="text-align:right;color:#2e7d32">${fmtE(t.regle)||'—'}</td><td style="text-align:right;color:${t.impaye>0?'#c62828':'#2e7d32'}">${fmtE(t.impaye)||'—'}</td><td>${delta}</td></tr>`;
    }).join('');
    return`<table class="timeline"><thead><tr><th>Trimestre</th><th style="text-align:right">Loyer</th><th style="text-align:right">Encaissé</th><th style="text-align:right">Impayé</th><th>Évolution</th></tr></thead><tbody>${rows}</tbody></table>`;
}
function closeLocHistory(){document.getElementById('hist-overlay').classList.remove('open');}

// ── MISE EN VENTE → TRANSACTION ─────────────────────────
var _venteEnCours=false; // garde anti double-clic (évite la double création)
function mettreEnVente(idBien,pid,force){
    if(_venteEnCours) return;
    if(!force && !confirm('Basculer ce bien vers le module Transaction (mise en vente) ?\nUne annonce brouillon sera créée.'))return;
    _venteEnCours=true;
    const ov=document.getElementById('vente-overlay');
    document.getElementById('vente-titre').textContent='🏷️ Mise en vente';
    document.getElementById('vente-sub').textContent='Bien #'+idBien;
    document.getElementById('vente-body').innerHTML='<div class="hist-loading">⏳ Bascule en cours…</div>';
    ov.classList.add('open');
    const fd=new FormData();fd.append('id_bien',idBien);fd.append('id_proprietaire',pid);
    if(force) fd.append('force',1);
    fetch('bailleur_mettre_en_vente.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
        _venteEnCours=false;
        if(d.duplicate){
            ov.classList.remove('open');
            if(confirm('⚠️ '+(d.message||'Un bien identique est déjà en vente.')+'\n\nForcer une 2e mise en vente ?\nOK = créer quand même · Annuler = voir l\'annonce existante')){
                mettreEnVente(idBien,pid,true);
            } else if(d.url_transaction){ window.open(d.url_transaction,'_blank'); }
            return;
        }
        if(!d.ok){document.getElementById('vente-body').innerHTML='<div class="hist-loading" style="color:#c62828">❌ '+(d.error||'Erreur')+'</div>';return;}
        const occ=d.occupation==='loue'?'🔑 Vendu loué (locataire en place)':'🔓 Bien libre';
        const present=(d.docs.present||[]).map(x=>'<li style="color:#2e7d32">✅ '+x+'</li>').join('');
        const missing=(d.docs.missing||[]).map(x=>'<li style="color:#c62828">⬜ '+x+'</li>').join('');
        document.getElementById('vente-body').innerHTML=
            '<p style="margin:0 0 10px"><strong>✅ Bien basculé en commercialisation « vente ».</strong><br>'
            +'<span style="font-size:.88em;color:#555">'+occ+(d.copropriete?' · 🏢 Copropriété':'')+' · Annonce brouillon #'+d.id_annonce+' créée.</span></p>'
            +'<div style="background:#fff9c4;border-left:4px solid #f9a825;padding:8px 12px;border-radius:4px;font-size:.84em;margin-bottom:12px">'
            +'📋 Documents requis pour la vente — <strong>'+(d.docs.present.length)+'/'+(d.docs.total)+'</strong> présents'
            +(d.docs.missing.length?' · <strong style="color:#c62828">'+d.docs.missing.length+' manquant(s)</strong>':' · ✅ complet')+'</div>'
            +'<ul style="list-style:none;padding:0;margin:0 0 14px;font-size:.86em;line-height:1.9">'+present+missing+'</ul>'
            +'<div style="display:flex;gap:8px;justify-content:flex-end">'
            +'<a href="'+d.url_bien+'" class="btn-loc btn-vente" style="text-decoration:none;padding:6px 14px">🏠 Fiche bien 360°</a>'
            +'<a href="'+d.url_transaction+'" class="btn-loc btn-vente" style="text-decoration:none;padding:6px 14px;background:#1a237e;color:#fff;border-color:#1a237e">→ Module Transaction</a>'
            +'</div>';
    }).catch(()=>{_venteEnCours=false;document.getElementById('vente-body').innerHTML='<div class="hist-loading" style="color:#c62828">❌ Erreur réseau</div>';});
}
function closeVente(){document.getElementById('vente-overlay').classList.remove('open');}
// Retirer un bien de la vente (annuler un doublon) — ne supprime pas le bien.
function retirerVente(idBien,pid,btn){
    if(!confirm('Retirer ce bien de la commercialisation « vente » ?\nIl quitte le tableau Transaction et son annonce brouillon est archivée.\n(Le bien n\'est pas supprimé.)')) return;
    btn.disabled=true; var ol=btn.textContent; btn.textContent='⏳…';
    var fd=new FormData(); fd.append('id_bien',idBien); fd.append('id_proprietaire',pid);
    fetch('bailleur_retirer_vente.php',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok){ btn.disabled=false; btn.textContent=ol; alert('❌ '+(d.error||'Échec')); return; }
            btn.textContent='✓ Retiré'; setTimeout(function(){ location.reload(); }, 700);
        })
        .catch(function(){ btn.disabled=false; btn.textContent=ol; alert('❌ Erreur réseau'); });
}

// ── SIMULATEUR VENTE : Prix/m² ⇄ Prix de vente ⇄ Rentabilité ──
// Surface (S) et loyer annuel (La) sont fixes par ligne (data-*).
//   Prix vente PV = S × Prix/m²        Prix/m² = PV / S
//   Renta %   = La / PV × 100          PV = La × 100 / Renta
// La ligne principale (main) porte data-surface/data-loyeran + le champ surface ;
// la 2e ligne (vente-row) porte prix/m², prix de vente, rentabilité.
function venteGroup(input){
    const tr=input.closest('tr');
    if(tr.classList.contains('vente-row')) return {main:tr.previousElementSibling, vente:tr};
    return {main:tr, vente:tr.nextElementSibling};
}
var _dvfData=null, _dvfBien=null;
function dvfModalEl(){
    var m=document.getElementById('dvf-modal-bg');
    if(!m){
        m=document.createElement('div'); m.id='dvf-modal-bg'; m.className='dvf-modal-bg';
        m.innerHTML="<div class='dvf-modal'><h3 id='dvf-modal-title'>📊 Estimation marché (DVF)</h3><div class='dvf-body' id='dvf-body'></div>"
          +"<div class='actions'><button type='button' class='btn-close' onclick='closeDVF()'>Fermer</button>"
          +"<button type='button' class='btn-apply' id='dvf-apply' onclick='applyDVF()'>✓ Appliquer au tableau</button></div></div>";
        document.body.appendChild(m);
        m.addEventListener('click',function(e){ if(e.target===m) closeDVF(); });
    }
    return m;
}
function closeDVF(){ var m=document.getElementById('dvf-modal-bg'); if(m) m.classList.remove('show'); }
function fr(n){ return (n==null)?'—':Number(n).toLocaleString('fr-FR'); }
async function estimerDVF(idBien, btn){
    _dvfBien=idBien; if(btn){ btn.disabled=true; var ol=btn.textContent; btn.textContent='⏳ DVF…'; }
    var m=dvfModalEl(), body=document.getElementById('dvf-body'), apply=document.getElementById('dvf-apply');
    var _t=document.getElementById('dvf-modal-title'); if(_t) _t.textContent='📊 Estimation marché (DVF)';
    try{
        var r=await fetch('api/dvf_comparables.php?id_bien='+idBien);
        var d=await r.json(); _dvfData=d;
        if(d.error){ body.innerHTML="⚠️ "+d.error; apply.style.display='none'; }
        else if(!d.nb_ventes){ body.innerHTML="Aucune vente comparable trouvée sur ce secteur pour cette taille."; apply.style.display='none'; }
        else{
            apply.style.display='';
            var h="<div class='dvf-est'>"+fr(d.estimation)+" €</div>"
              +"<div class='dvf-sub'>estimation pour "+fr(d.bien.surface)+" m² · "+(d.bien.nature||'')+" ("+d.bien.type+") — "+d.bien.ville+", section "+d.bien.section+"</div>"
              +(d.note?"<div class='dvf-sub' style='color:#b26a00;margin-top:6px'>⚠️ "+d.note+"</div>":"")
              +(d.fiabilite?"<div class='dvf-sub' style='color:#c62828;margin-top:6px;font-weight:600'>⚠️ "+d.fiabilite+"</div>":"")
              +"<p style='margin:10px 0'>Prix médian : <strong>"+fr(d.prix_m2_median)+" €/m²</strong> "
              +"<span class='dvf-sub'>(fourchette "+fr(d.prix_m2_min)+"–"+fr(d.prix_m2_max)+" €/m² · "+d.nb_ventes+" ventes · "+d.periode+")</span></p>"
              +"<table><thead><tr><th>Date</th><th>Adresse</th><th class='num-r'>Surface</th><th class='num-r'>Prix</th><th class='num-r'>€/m²</th></tr></thead><tbody>";
            (d.comparables||[]).forEach(function(c){ h+="<tr><td>"+c.date+"</td><td>"+(c.adresse||'')+"</td><td class='num-r'>"+fr(c.surface)+" m²</td><td class='num-r'>"+fr(c.prix)+" €</td><td class='num-r'>"+fr(c.prix_m2)+"</td></tr>"; });
            h+="</tbody></table>";
            h+="<div class='dvf-sub' style='margin-top:10px'>Source : DVF — Demandes de Valeurs Foncières (open data, ventes réelles enregistrées). "
              +(d.lien_dvf?"<a href='"+d.lien_dvf+"' target='_blank' rel='noopener' style='font-weight:600'>🔗 Vérifier sur la carte DVF officielle ↗</a>":"")+"</div>";
            body.innerHTML=h;
        }
        m.classList.add('show');
    }catch(e){ body.innerHTML="⚠️ Erreur réseau"; apply.style.display='none'; m.classList.add('show'); }
    finally{ if(btn){ btn.disabled=false; btn.textContent='📊 Estimer'; } }
}
function applyDVF(){
    if(!_dvfData||!_dvfData.prix_m2_median||!_dvfBien){ closeDVF(); return; }
    var pm=document.querySelector('.v-pm[data-bien="'+_dvfBien+'"]');
    if(pm){ pm.value=_dvfData.prix_m2_median; recalcVente(pm,'pm'); }
    var res=document.querySelector('.dvf-res[data-bien="'+_dvfBien+'"]');
    if(res) res.textContent=' ✓ '+fr(_dvfData.prix_m2_median)+' €/m² (DVF) — cliquez « Valider le prix »';
    closeDVF();
}
// Clic sur le bouton : si déjà validé (au prix courant) → ouvre l'historique ; sinon → valide.
function clickValider(idBien, pid, btn){
    // Toujours proposer l'enregistrement d'un NOUVEAU prix (même si déjà validé).
    // L'historique reste accessible via le bouton dédié « 🕑 Historique ».
    validerPrix(idBien, pid, btn);
}
// Met le bouton dans l'état "Validé" (bleu pétrole) si la valeur saisie == prix courant validé.
function refreshValiderBtn(btn){
    if(!btn) return;
    var courant=parseFloat(btn.dataset.courant)||0;
    var grp=venteGroup(btn), vente=grp.vente; if(!vente) return;
    var pv=vente.querySelector('.v-pv'); var val=parseFloat(pv&&pv.value)||0;
    if(courant>0 && Math.round(val)===Math.round(courant)){
        btn.classList.add('is-valide');
        btn.textContent='✓ Validé';
        btn.title='Prix validé — cliquer pour voir l\'historique des prix';
    } else {
        btn.classList.remove('is-valide');
        btn.textContent='✓ Valider le prix';
        btn.title='Fixe ce prix comme prix courant (historisé) — repris dans l\'annonce et l\'export';
    }
}
// ── SCÉNARIOS DE VALORISATION ───────────────────────────
// Scénario initial = celui passé dans l'URL (?scenario=CODE), rendu côté serveur.
// Permet aux "lots porteurs de scénario" (invisibles en Courant) d'être déjà présents
// dans le tableau ; la surcouche JS remplit ensuite leurs prix.
var _scnParam=(new URLSearchParams(location.search).get('scenario')||'courant').toLowerCase().replace(/[^a-z0-9_\-]/g,'')||'courant';
var _scenarioCode=_scnParam, _scenarioLabel=(_scnParam==='courant'?'Courant':_scnParam);
// État « modifications non enregistrées » : passe à true dès qu'un prix est saisi
// dans un scénario ≠ Courant, et n'est remis à false qu'après enregistrement ou
// chargement d'un scénario. Sert à AVERTIR avant de tout perdre (changement de
// scénario, rechargement, fermeture de l'onglet).
var _scenarioDirty=false;
function markScenarioDirty(){
    if(_scenarioCode && _scenarioCode!=='courant'){
        _scenarioDirty=true;
        var sb=document.getElementById('btn-scenario-save');
        if(sb && sb.textContent.indexOf('●')<0){ sb.textContent='💾 Enregistrer ce scénario ●'; sb.style.background='#fff3e0'; sb.style.color='#bf6000'; }
    }
}
function clearScenarioDirty(){
    _scenarioDirty=false;
    var sb=document.getElementById('btn-scenario-save');
    if(sb){ sb.textContent='💾 Enregistrer ce scénario'; sb.style.background=''; sb.style.color=''; }
}
// Garde : si des prix non enregistrés existent, demande confirmation avant l'action.
function confirmDiscardScenario(){
    if(!_scenarioDirty) return true;
    return confirm('⚠️ Vous avez des prix NON ENREGISTRÉS dans le scénario « '+_scenarioLabel+' ».\n\n'
        + 'Si vous continuez, ces saisies seront PERDUES.\n\n'
        + 'OK = continuer et perdre les saisies\nAnnuler = revenir enregistrer (bouton « 💾 Enregistrer ce scénario »).');
}
// « Reprendre » : applique le scénario actuellement SÉLECTIONNÉ dans la liste.
function reprendreScenario(){
    var sel=document.getElementById('scenario-select'); if(!sel) return;
    if(!confirmDiscardScenario()) return;
    var parts=(sel.value||'courant|Courant').split('|');
    var code=parts[0]||'courant';
    // Un scénario peut contenir des "lots porteurs" absents de la vue Courant : il faut
    // un RE-RENDU serveur (?scenario=CODE) pour les faire entrer dans le tableau, puis
    // la surcouche JS s'applique automatiquement au chargement (cf. DOMContentLoaded).
    var u=new URL(location.href);
    if(code==='courant'){ u.searchParams.delete('scenario'); }
    else { u.searchParams.set('scenario', code); }
    if(window.patSave) window.patSave();
    location.href=u.toString();
}
function applyScenario(){
    var info=document.getElementById('scenario-info');
    var saveBtn=document.getElementById('btn-scenario-save');
    if(_scenarioCode==='courant'){
        if(info) info.textContent='';
        if(saveBtn) saveBtn.style.display='none';
        location.reload(); // retour propre aux valeurs courantes
        return;
    }
    if(saveBtn) saveBtn.style.display='';
    // Charge les prix du scénario et les superpose dans les champs Prix de vente
    fetch('bailleur_scenarios.php?action=load&scenario='+encodeURIComponent(_scenarioCode))
      .then(function(r){ if(!r.ok){ return r.text().then(function(t){ throw new Error('HTTP '+r.status+' — '+t.slice(0,160)); }); } return r.json(); })
      .then(function(d){
        if(!d.ok){ alert('❌ '+(d.error||'Erreur')); return; }
        // Scénarios EXCLUSIFS (ex. DUSART) : seuls les lots valorisés DANS le scénario comptent ;
        // tous les autres passent à 0 → total = somme des seules valeurs du scénario, "pile au total".
        // Les scénarios classiques (IFI, mini, maxi) gardent le prix courant des lots non repricés.
        var EXCLUSIVE_SCENARIOS={dusart:1};
        var excl=!!EXCLUSIVE_SCENARIOS[_scenarioCode];
        var n=0;
        document.querySelectorAll('.v-pv').forEach(function(pv){
            var bid=pv.dataset.bien;
            if(d.prix && d.prix[bid]!=null){ pv.value=Math.round(d.prix[bid]); recalcVente(pv,'pv'); n++; }
            else if(excl){ pv.value=0; recalcVente(pv,'pv'); }
        });
        if(info) info.textContent='— '+_scenarioLabel+' : '+n+' prix chargé(s). Validez pour compléter ce scénario.';
        document.querySelectorAll('.btn-valider').forEach(function(b){ b.classList.remove('is-valide'); b.textContent='✓ Valider le prix'; });
        recomputeAllPVTotals();
        clearScenarioDirty(); // fraîchement chargé depuis la base = état propre
      })
      .catch(function(e){ alert('❌ '+(e&&e.message?e.message:'Erreur réseau')+'\n\n(Si « 404 » : fichier non déployé. Si « 500/Unknown column scenario_code » : migration 20260601 non jouée.)'); });
}
// Peuple la liste avec les scénarios ENREGISTRÉS (au-delà des 4 standards) pour pouvoir les reprendre après rechargement.
function chargerListeScenarios(){
    var sel=document.getElementById('scenario-select'); if(!sel) return;
    fetch('bailleur_scenarios.php?action=list')
      .then(function(r){ return r.ok?r.json():null; })
      .then(function(d){
        if(!d||!d.ok||!d.scenarios) return;
        var present={}; Array.prototype.forEach.call(sel.options,function(o){ present[(o.value.split('|')[0])]=true; });
        d.scenarios.forEach(function(s){
            var code=s.scenario_code; if(!code||code==='courant'||present[code]) return;
            var label=s.label||code;
            var opt=document.createElement('option'); opt.value=code+'|'+label;
            opt.textContent=label+' ('+s.nb+' prix)'; sel.appendChild(opt); present[code]=true;
        });
      }).catch(function(){});
}
// EXPORT : envoie les prix AFFICHÉS (live, à l'instant du clic) au générateur CSV.
function exporterPatrimoine(){
    var prix={};
    document.querySelectorAll('.v-pv').forEach(function(pv){
        var b=pv.dataset.bien; if(!b || prix[b]!=null) return;
        var v=parseFloat(pv.value)||0; if(v>0) prix[b]=Math.round(v);
    });
    document.getElementById('export-prix').value=JSON.stringify(prix);
    document.getElementById('export-scenario').value=_scenarioLabel||'Courant';
    document.getElementById('export-form').submit();
}
// Enregistre EN MASSE les prix affichés dans le scénario actif (début ou fin de travail).
// Ne touche JAMAIS le Courant : "prend les valeurs indiquées" → scénario uniquement.
function enregistrerScenario(){
    if(_scenarioCode==='courant'){ alert('Le « Courant » (prix diffusé) se valide bien par bien, pour ne pas écraser votre travail.'); return; }
    var items=[];
    document.querySelectorAll('.v-pv').forEach(function(pv){
        var v=parseFloat(pv.value)||0;
        if(v>0 && pv.dataset.bien && pv.dataset.pid) items.push({b:pv.dataset.bien,p:pv.dataset.pid,prix:Math.round(v)});
    });
    if(!items.length){ alert('Aucun prix affiché à enregistrer.'); return; }
    if(!confirm('Enregistrer '+items.length+' prix dans le scénario « '+_scenarioLabel+' » ?\n\nLe prix Courant (diffusé annonce/Ubiflow) n\'est PAS modifié.')) return;
    var fd=new FormData(); fd.append('scenario_code',_scenarioCode); fd.append('scenario_label',_scenarioLabel); fd.append('items',JSON.stringify(items));
    fetch('bailleur_scenario_save_bulk.php',{method:'POST',body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            if(!d.ok){ alert('❌ '+(d.error||'Échec')); return; }
            var info=document.getElementById('scenario-info');
            if(info) info.textContent='— '+_scenarioLabel+' : '+d.n+' prix enregistrés ✓'+(d.hors_perimetre?(' ('+d.hors_perimetre+' hors périmètre ignorés)'):'');
            clearScenarioDirty(); // enregistré en base = état propre
        })
        .catch(function(){ alert('❌ Erreur réseau'); });
}
function creerScenario(){
    if(!confirmDiscardScenario()) return;
    var nom=prompt('Nom du nouveau scénario (ex : IFI 2026, Estimation banque…) :',''); if(!nom) return;
    var code=nom.toLowerCase().replace(/[^a-z0-9_\-]+/g,'_').replace(/^_+|_+$/g,'').slice(0,40)||('snap_'+Date.now());
    var sel=document.getElementById('scenario-select');
    var opt=document.createElement('option'); opt.value=code+'|'+nom; opt.textContent=nom; sel.appendChild(opt);
    sel.value=code+'|'+nom; reprendreScenario();
}
// VALIDER le prix : modale 2 champs (prix + motif) → historisé + reprise annonce/export.
function validerModalEl(){
    var m=document.getElementById('valider-modal-bg');
    if(!m){
        m=document.createElement('div'); m.id='valider-modal-bg'; m.className='dvf-modal-bg';
        m.innerHTML="<div class='dvf-modal' style='max-width:460px'><h3>✓ Valider le prix de vente</h3>"
          +"<div class='dvf-body'>"
          +"<label style='display:block;font-size:.88em;font-weight:600;margin-bottom:4px'>Nouveau prix de vente courant (€)</label>"
          +"<input id='vm-prix' type='number' min='0' step='1000' style='width:100%;padding:9px;border:1px solid #90caf9;border-radius:7px;font-size:1.1em;font-weight:700'>"
          +"<label style='display:block;font-size:.88em;font-weight:600;margin:14px 0 4px'>Motif de la modification</label>"
          +"<input id='vm-motif' type='text' placeholder='ex : aligné DVF, baisse négociation, mandat signé…' style='width:100%;padding:9px;border:1px solid #ccc;border-radius:7px'>"
          +"</div>"
          +"<div class='actions'><button type='button' class='btn-close' onclick='closeValiderModal()'>Annuler</button>"
          +"<button type='button' class='btn-apply' id='vm-ok'>✓ Valider</button></div></div>";
        document.body.appendChild(m);
        m.addEventListener('click',function(e){ if(e.target===m) closeValiderModal(); });
    }
    return m;
}
function closeValiderModal(){ var m=document.getElementById('valider-modal-bg'); if(m) m.classList.remove('show'); }
function validerPrix(idBien, pid, btn){
    var grp=venteGroup(btn), vente=grp.vente; if(!vente){ vente=document.querySelector('.v-pv[data-bien=\"'+idBien+'\"]').closest('tr'); }
    var pv=vente.querySelector('.v-pv'), rt=vente.querySelector('.v-rt');
    var cur = pv ? Math.round(parseFloat(pv.value)||0) : 0;
    var m=validerModalEl();
    document.getElementById('vm-prix').value = cur>0?cur:'';
    // Le motif reprend automatiquement le libellé du scénario actif (gain de temps).
    document.getElementById('vm-motif').value = (_scenarioCode!=='courant') ? _scenarioLabel : '';
    var _tt=document.querySelector('#valider-modal-bg h3'); if(_tt) _tt.textContent='✓ Valider le prix — '+_scenarioLabel;
    document.getElementById('vm-motif').onkeydown=function(e){ if(e.key==='Enter'){ e.preventDefault(); document.getElementById('vm-ok').click(); } };
    document.getElementById('vm-ok').onclick=function(){
        var prix=parseFloat(String(document.getElementById('vm-prix').value).replace(/[^0-9.,]/g,'').replace(/,/g,'.'))||0;
        if(prix<=0){ alert('Prix invalide.'); return; }
        var motif=document.getElementById('vm-motif').value||'';
        closeValiderModal();
        if(pv){ pv.value=Math.round(prix); recalcVente(pv,'pv'); }
        btn.disabled=true; var ol=btn.textContent; btn.textContent='⏳…';
        var fd=new FormData();
        fd.append('id_bien',idBien); fd.append('id_proprietaire',pid);
        fd.append('prix_vente',prix); if(rt) fd.append('rendement', rt.value||''); fd.append('commentaire',motif);
        fd.append('scenario_code',_scenarioCode); fd.append('scenario_label',_scenarioLabel);
        fetch('bailleur_save_vente.php',{method:'POST',body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                btn.disabled=false; btn.textContent=ol;
                if(!d.ok){ alert('❌ '+(d.error||'Échec')); return; }
                if(pv){ pv.style.background='#e8f5e9'; setTimeout(function(){pv.style.background='';},1200); }
                // Le prix validé devient la référence du scénario AFFICHÉ → bouton "Validé" dans tous les cas.
                btn.dataset.courant=String(Math.round(prix));
                if(_scenarioCode==='courant'){
                    refreshValiderBtn(btn);
                } else {
                    btn.classList.add('is-valide');
                    btn.textContent='✓ Validé ('+_scenarioLabel+')';
                    btn.title='Prix validé pour le scénario '+_scenarioLabel+' — cliquer pour l\'historique';
                }
            })
            .catch(function(){ btn.disabled=false; btn.textContent=ol; alert('❌ Erreur réseau'); });
    };
    m.classList.add('show');
    // Le prix est déjà repris → focus direct sur le MOTIF. Entrée = valider.
    setTimeout(function(){ var i=document.getElementById('vm-motif'); if(i) i.focus(); },50);
}
// Historique des prix validés (modale légère)
function histoPrix(idBien){
    fetch('bailleur_prix_historique.php?id_bien='+idBien)
      .then(function(r){return r.json();})
      .then(function(d){
        var m=dvfModalEl(), body=document.getElementById('dvf-body'), apply=document.getElementById('dvf-apply');
        var _t=document.getElementById('dvf-modal-title'); if(_t) _t.textContent='🕑 Historique des prix validés';
        apply.style.display='none';
        if(!d.ok){ body.innerHTML='⚠️ '+(d.error||'Erreur'); m.classList.add('show'); return; }
        if(!d.lignes||!d.lignes.length){ body.innerHTML='Aucun prix validé pour ce bien.'; m.classList.add('show'); return; }
        var h='<table><thead><tr><th>Date</th><th>Type</th><th class=\"num-r\">Montant</th><th>Source</th><th>Commentaire</th></tr></thead><tbody>';
        d.lignes.forEach(function(l){ h+='<tr'+(l.is_courant==1?' style=\"font-weight:700;background:#e8f5e9\"':'')+'><td>'+l.date_validation+'</td><td>'+l.type_valeur+'</td><td class=\"num-r\">'+fr(l.montant)+' €'+(l.is_courant==1?' ✓':'')+'</td><td>'+(l.source||'')+'</td><td>'+(l.commentaire||'')+'</td></tr>'; });
        h+='</tbody></table>';
        body.innerHTML=h; m.classList.add('show');
      })
      .catch(function(){ alert('❌ Erreur réseau'); });
}
// ── TOTAUX PRIX DE VENTE (live, à partir des prix affichés) ──
function fmtPV(v){ return Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.')+' €'; }
function pvSum(inputs){
    var seen={}, s=0;
    inputs.forEach(function(pv){
        var b=pv.dataset.bien; if(seen[b]) return;
        var v=parseFloat(pv.value)||0; if(v<=0) return;
        seen[b]=true; s+=v;                 // dédoublonné par bien (co-locataires)
    });
    return s;
}
function recomputeAllPVTotals(){
    // Par immeuble
    document.querySelectorAll('.imm-block').forEach(function(block){
        var t=pvSum(Array.prototype.slice.call(block.querySelectorAll('.v-pv')));
        var hdr=block.querySelector('.imm-header'); if(!hdr) return;
        var chip=hdr.querySelector('.imm-pv-total');
        if(t>0){
            if(!chip){ chip=document.createElement('span'); chip.className='imm-pv-total';
                hdr.insertBefore(chip, hdr.querySelector('.btn-vendu')); }
            chip.textContent='🏷️ '+fmtPV(t);
        } else if(chip){ chip.remove(); }
    });
    // Par propriétaire + total global (topbar)
    var grand=0;
    var _pvLabel = document.querySelector('.pf-fbtn') ? 'Valeur patrimoine proposé' : 'Total prix de vente';
    document.querySelectorAll('.detail-inner').forEach(function(di){
        var t=pvSum(Array.prototype.slice.call(di.querySelectorAll('.v-pv')));
        var h4=di.querySelector('h4'); if(!h4) return;
        var chip=h4.querySelector('.prop-pv-total');
        if(t>0){
            if(!chip){ chip=document.createElement('span'); chip.className='prop-pv-total'; h4.appendChild(chip); }
            chip.innerHTML='🏷️ '+_pvLabel+' : <strong>'+fmtPV(t)+'</strong>';
        } else if(chip){ chip.remove(); }
        // ── MAJ AUTO de la cellule "Valeur patrimoine proposé" du tableau récap ──
        var row=di.closest('.detail-row');
        if(row && row.id){
            var pid=row.id.replace('detail-','');
            var cell=document.getElementById('valpat-'+pid);
            if(cell) cell.textContent = fmtPV(t);
        }
        grand+=t;
    });
    var tb=document.getElementById('tb-pv-total');
    if(tb){ if(grand>0){ tb.textContent='🏷️ Total prix de vente : '+fmtPV(grand); tb.style.display=''; }
            else tb.style.display='none'; }
    var vt=document.getElementById('valpat-total'); if(vt) vt.textContent=fmtPV(grand);   // total colonne VALEUR PATRIMOINE
}
function recalcVente(input,field){
    const g=venteGroup(input); const main=g.main, vente=g.vente;
    if(!main||!vente)return;
    const sf=main.querySelector('.v-sf');
    let S = sf ? (parseFloat(sf.value)||0) : (parseFloat(main.dataset.surface)||0);
    if(sf) main.dataset.surface=S;
    const La=parseFloat(main.dataset.loyeran)||0;
    const pm=vente.querySelector('.v-pm'),pv=vente.querySelector('.v-pv'),rt=vente.querySelector('.v-rt');
    let PM=parseFloat(pm.value)||0,PV=parseFloat(pv.value)||0,RT=parseFloat(rt.value)||0;
    if(field==='pm'){ PV=S>0?PM*S:0; RT=PV>0?La/PV*100:0; }
    else if(field==='pv'){ PM=S>0?PV/S:0; RT=PV>0?La/PV*100:0; }
    else if(field==='rt'){ PV=RT>0?La*100/RT:0; PM=S>0?PV/S:0; }
    else if(field==='sf'){ // surface modifiée : on garde le prix/m² s'il existe, sinon le prix de vente
        if(PM>0){ PV=S>0?PM*S:0; RT=PV>0?La/PV*100:0; }
        else if(PV>0){ PM=S>0?PV/S:0; RT=PV>0?La/PV*100:0; } }
    if(field!=='pm') pm.value=PM>0?Math.round(PM):'';
    if(field!=='pv') pv.value=PV>0?Math.round(PV):'';
    if(field!=='rt') rt.value=RT>0?RT.toFixed(2):'';
    // Persistance locale du prix/m² (clé par bien)
    const k='vpm_'+(pm.dataset.bien);
    if(PM>0) localStorage.setItem(k,String(Math.round(PM)));
    else if(!pm.value) localStorage.removeItem(k);
    // Met à jour l'état du bouton "Valider/Validé" selon le prix saisi vs prix courant.
    refreshValiderBtn(vente.querySelector('.btn-valider'));
    recomputeAllPVTotals();
    markScenarioDirty(); // prix saisi dans un scénario ≠ Courant → non enregistré
}
// Loyer estimé (bien vacant) : met à jour la rentabilité affichée en direct (loyer annuel = loyer*12).
function recalcVenteLoyer(input){
    const g=venteGroup(input); const main=g.main, vente=g.vente;
    if(!main||!vente) return;
    const La=(parseFloat(input.value)||0)*12;
    main.dataset.loyeran=La;
    const pv=vente.querySelector('.v-pv'); if(pv && (parseFloat(pv.value)||0)>0) recalcVente(pv,'pv');
}
// Enregistre le loyer estimé en base (bien_prix type 'loyer'), fond doré le temps de l'enregistrement.
function saveLoyerEstime(input){
    const v=parseFloat(input.value)||0;
    const fd=new FormData();
    fd.append('id_bien',input.dataset.bien);
    fd.append('id_proprietaire',input.dataset.pid);
    fd.append('loyer_estime',v);
    input.style.background='#fff9c4';
    fetch('bailleur_save_loyer_estime.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            input.style.background = d.ok ? '#e8f5e9' : '#ffcdd2';
            if(!d.ok) alert('Loyer estimé non enregistré : '+(d.error||'?'));
            setTimeout(()=>{input.style.background='';},900);
        }).catch(()=>{input.style.background='#ffcdd2';});
}
// Enregistre la surface Carrez en base (modifiable)
function saveSurface(input){
    const v=parseFloat(input.value)||0;
    const fd=new FormData();
    fd.append('id_bien',input.dataset.bien);
    fd.append('id_proprietaire',input.dataset.pid);
    fd.append('surface_carrez',v);
    input.style.background='#fff9c4';
    fetch('bailleur_save_surface.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            input.style.background = d.ok ? '#e8f5e9' : '#ffcdd2';
            if(!d.ok) alert('Surface non enregistrée : '+(d.error||'?'));
            setTimeout(()=>{input.style.background='';},900);
        }).catch(()=>{input.style.background='#ffcdd2';});
}
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('tr.vente-row').forEach(function(tr){
        var pv=tr.querySelector('.v-pv'), pm=tr.querySelector('.v-pm');
        if(pv && pv.value){ recalcVente(pv,'pv'); }   // prix courant BDD prioritaire
        else if(pm){ var v=localStorage.getItem('vpm_'+pm.dataset.bien); if(v){ pm.value=v; recalcVente(pm,'pm'); } }
        // État initial du bouton (Validé si un prix courant existe déjà en base).
        refreshValiderBtn(tr.querySelector('.btn-valider'));
    });
    recomputeAllPVTotals();
    chargerListeScenarios();
    // Scénario passé dans l'URL (rendu serveur) → présélection + surcouche des prix.
    if(_scenarioCode && _scenarioCode!=='courant'){
        var sel=document.getElementById('scenario-select');
        if(sel){
            var found=Array.prototype.some.call(sel.options,function(o){ if(o.value.split('|')[0]===_scenarioCode){ sel.value=o.value; _scenarioLabel=(o.value.split('|')[1]||_scenarioCode); return true; } return false; });
            if(!found){ var opt=document.createElement('option'); opt.value=_scenarioCode+'|'+_scenarioLabel; opt.textContent=_scenarioLabel; sel.appendChild(opt); sel.value=opt.value; }
        }
        applyScenario();  // remplit les prix du scénario (dont les lots porteurs)
    }
});
</script>
JS;

// ── Rechargement EN CONSERVANT la position de défilement (page en iframe) ──
?>
<script>
// ── PERSISTANCE de l'emplacement de travail (scroll + propriétaire ouvert +
//    sections dépliées) : restauré à CHAQUE retour sur la page (reload, iframe,
//    navigation aller-retour). Évite de reperdre sa place dans la liste.
(function(){
  var KEY = <?= json_encode('patState_' . $mode) ?>;
  window.patSave = function(){
    try {
      var open = document.querySelector('.detail-row.open');
      var pid  = open ? open.id.replace('detail-','') : null;
      var folds = [].slice.call(document.querySelectorAll('.fold-content.open')).map(function(c){return c.id;});
      localStorage.setItem(KEY, JSON.stringify({
        pid: pid, folds: folds,
        scrollY: window.scrollY || document.documentElement.scrollTop || 0
      }));
    } catch(e){}
  };
  window.patReload = function(){ if(window._scenarioDirty && typeof confirmDiscardScenario==='function' && !confirmDiscardScenario()) return; if(window.patSave)patSave(); location.reload(); };
  function applyScroll(y){ try{ window.scrollTo(0, y); }catch(e){} }
  window.patRestore = function(){
    var s; try { s = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch(e){ s=null; }
    if(!s) return;
    if(s.pid){
      var d=document.getElementById('detail-'+s.pid), r=document.getElementById('row-'+s.pid);
      if(d){ d.classList.add('open'); if(r)r.classList.add('active'); }
    }
    (s.folds||[]).forEach(function(id){
      var c=document.getElementById(id); if(!c)return; c.classList.add('open');
      document.querySelectorAll('.fold-toggle').forEach(function(t){
        if((t.getAttribute('onclick')||'').indexOf("'"+id+"'")>=0) t.classList.add('open');
      });
    });
    if(s.scrollY){ applyScroll(s.scrollY);
      requestAnimationFrame(function(){applyScroll(s.scrollY);});
      setTimeout(function(){applyScroll(s.scrollY);},150);
    }
  };
  // Sauvegarde du scroll en continu (throttle léger) + avant de quitter la page
  var _t=null;
  window.addEventListener('scroll', function(){ if(_t)return; _t=setTimeout(function(){_t=null; if(window.patSave)patSave();},250); }, {passive:true});
  window.addEventListener('beforeunload', function(ev){
    if(window.patSave)patSave();
    // Avertissement natif si des prix de scénario ne sont pas enregistrés.
    if(window._scenarioDirty){ ev.preventDefault(); ev.returnValue=''; return ''; }
  });
  document.addEventListener('DOMContentLoaded', function(){ if(window.patRestore)patRestore(); });
})();
</script>
<?php
// ── FUSION DE DOUBLONS (staff manager) : barre + modal comparaison champ par champ ──
if ($canMerge): ?>
<style>
#mergebar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:9000;display:none;
  background:#2c2a28;color:#fff;border-radius:12px;padding:10px 16px;box-shadow:0 10px 30px rgba(0,0,0,.3);
  align-items:center;gap:14px;font-size:14px;}
#mergebar b{color:#ffd479;}
#mergebar .mb-btn{cursor:pointer;border:none;border-radius:8px;padding:8px 14px;font-weight:800;font-size:13px;}
#mergebar .mb-go{background:#4878a6;color:#fff;} #mergebar .mb-go:disabled{opacity:.45;cursor:not-allowed;}
#mergebar .mb-clear{background:#5a5650;color:#fff;}
.mg-overlay{position:fixed;inset:0;background:rgba(20,22,28,.55);z-index:9100;display:none;}
.mg-modal{position:fixed;z-index:9101;top:50%;left:50%;transform:translate(-50%,-50%);width:min(820px,95vw);
  max-height:88vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.35);padding:22px;display:none;}
.mg-modal h3{margin:0 0 6px;}
.mg-dst{display:flex;gap:10px;margin:10px 0 16px;}
.mg-dst label{flex:1;border:2px solid #e5e7eb;border-radius:10px;padding:10px 12px;cursor:pointer;font-size:13px;}
.mg-dst label.sel{border-color:#4878a6;background:#eef4fb;}
.mg-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.mg-tbl th,.mg-tbl td{border-bottom:1px solid #eee;padding:7px 8px;text-align:left;vertical-align:top;}
.mg-tbl th{color:#7a766f;font-weight:700;}
.mg-tbl tr.conflit{background:#fff7e6;}
.mg-opt{display:block;cursor:pointer;padding:2px 0;}
.mg-opt.empty{color:#b0aaa0;font-style:italic;}
.mg-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
.mg-foot button{cursor:pointer;border:none;border-radius:9px;padding:10px 16px;font-weight:800;}
.mg-cancel{background:#eceef1;color:#374151;} .mg-do{background:linear-gradient(135deg,#2d8a4e,#23703f);color:#fff;}
</style>
<div id="mergebar">
  <span><b id="mb-count">0</b> bien(s) sélectionné(s)</span>
  <button type="button" class="mb-btn mb-go" id="mb-go" disabled onclick="openMergeModal()">🔀 Fusionner les doublons</button>
  <button type="button" class="mb-btn mb-clear" onclick="mergeClear()">Annuler</button>
</div>
<div class="mg-overlay" id="mg-overlay" onclick="mergeModalClose()"></div>
<div class="mg-modal" id="mg-modal">
  <h3>🔀 Fusion de 2 biens en doublon</h3>
  <div style="color:#7a766f;font-size:13px;">Choisis le bien à CONSERVER, puis la valeur à garder pour chaque champ. L'autre bien sera fusionné (baux, documents, mandats transférés) puis désactivé.</div>
  <div class="mg-dst" id="mg-dst"></div>
  <div id="mg-fields"><div style="padding:18px;text-align:center;color:#7a766f;">Chargement…</div></div>
  <div class="mg-foot">
    <button type="button" class="mg-cancel" onclick="mergeModalClose()">Annuler</button>
    <button type="button" class="mg-do" id="mg-do" onclick="confirmMerge()">✓ Fusionner</button>
  </div>
</div>
<script>
(function(){
  let picks=[]; // {id,label}
  let cmp=null;  // données de comparaison
  window.mergeSync=function(){
    picks=[...document.querySelectorAll('.merge-pick:checked')].map(c=>({id:+c.dataset.bien,label:c.dataset.label||('#'+c.dataset.bien)}));
    const bar=document.getElementById('mergebar');
    document.getElementById('mb-count').textContent=picks.length;
    document.getElementById('mb-go').disabled=(picks.length!==2);
    bar.style.display=picks.length>0?'flex':'none';
  };
  window.mergeClear=function(){
    document.querySelectorAll('.merge-pick:checked').forEach(c=>c.checked=false); mergeSync();
  };
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  function val(v){return (v===null||v===''||v===undefined)?'<span class="mg-opt empty">(vide)</span>':esc(v);}
  window.mergeModalClose=function(){document.getElementById('mg-overlay').style.display='none';document.getElementById('mg-modal').style.display='none';};
  window.openMergeModal=async function(){
    if(picks.length!==2)return;
    document.getElementById('mg-overlay').style.display='block';
    document.getElementById('mg-modal').style.display='block';
    document.getElementById('mg-fields').innerHTML='<div style="padding:18px;text-align:center;color:#7a766f;">Chargement…</div>';
    try{
      const r=await fetch('api/bien_compare.php?a='+picks[0].id+'&b='+picks[1].id);
      const j=await r.json();
      if(!j.ok){document.getElementById('mg-fields').innerHTML='<div style="padding:14px;color:#c62828;">⚠ '+esc(j.error)+'</div>';return;}
      cmp=j; renderMerge();
    }catch(e){document.getElementById('mg-fields').innerHTML='<div style="padding:14px;color:#c62828;">⚠ '+esc(e.message)+'</div>';}
  };
  function renderMerge(){
    const A=picks[0],B=picks[1],ca=cmp.a.compteurs,cb=cmp.b.compteurs;
    // défaut : conserver le bien le plus "riche" (baux+docs+mandats)
    const scoreA=ca.baux+ca.documents+ca.mandats, scoreB=cb.baux+cb.documents+cb.mandats;
    const dstDef=(scoreB>scoreA)?'b':'a';
    document.getElementById('mg-dst').innerHTML=
      '<label data-side="a"><input type="radio" name="mg-dst" value="a" '+(dstDef==='a'?'checked':'')+'> <b>Garder A</b> · '+esc(A.label)+
        '<div style="color:#7a766f">'+ca.baux+' bail · '+ca.documents+' doc · '+ca.mandats+' mandat</div></label>'+
      '<label data-side="b"><input type="radio" name="mg-dst" value="b" '+(dstDef==='b'?'checked':'')+'> <b>Garder B</b> · '+esc(B.label)+
        '<div style="color:#7a766f">'+cb.baux+' bail · '+cb.documents+' doc · '+cb.mandats+' mandat</div></label>';
    let rows='<table class="mg-tbl"><thead><tr><th>Champ</th><th>A — '+esc(A.label)+'</th><th>B — '+esc(B.label)+'</th></tr></thead><tbody>';
    cmp.fields.forEach((f,i)=>{
      const cls=f.conflit?' class="conflit"':'';
      rows+='<tr'+cls+'><td>'+esc(f.label)+(f.conflit?' ⚠':'')+'</td>'+
        '<td><label class="mg-opt"><input type="radio" name="f_'+i+'" value="a" '+(f.defaut==='a'?'checked':'')+'> '+val(f.a)+'</label></td>'+
        '<td><label class="mg-opt"><input type="radio" name="f_'+i+'" value="b" '+(f.defaut==='b'?'checked':'')+'> '+val(f.b)+'</label></td></tr>';
    });
    rows+='</tbody></table>';
    document.getElementById('mg-fields').innerHTML=rows;
    document.querySelectorAll('.mg-dst label').forEach(l=>{
      const upd=()=>document.querySelectorAll('.mg-dst label').forEach(x=>x.classList.toggle('sel',x.querySelector('input').checked));
      l.querySelector('input').addEventListener('change',upd); upd();
    });
  }
  window.confirmMerge=async function(){
    if(!cmp)return;
    const dstSide=(document.querySelector('input[name="mg-dst"]:checked')||{}).value||'a';
    const keep=(dstSide==='a')?picks[0]:picks[1];
    const drop=(dstSide==='a')?picks[1]:picks[0];
    // Valeurs retenues par champ
    const fields={};
    cmp.fields.forEach((f,i)=>{
      const sel=(document.querySelector('input[name="f_'+i+'"]:checked')||{}).value||f.defaut;
      const v=(sel==='a')?f.a:f.b;
      if(v!==null&&v!==undefined&&v!=='') fields[f.col]=v;
    });
    if(!confirm('Fusionner « '+drop.label+' » dans « '+keep.label+' » ?\nCette action est irréversible (le doublon sera désactivé).'))return;
    const btn=document.getElementById('mg-do'); btn.disabled=true; btn.textContent='⏳ Fusion…';
    try{
      const fd=new FormData();
      fd.append('destination_id',keep.id); fd.append('source_id',drop.id);
      fd.append('fields_json',JSON.stringify(fields));
      const r=await fetch('api/admin_biens_merge_action.php',{method:'POST',body:fd});
      const j=await r.json();
      if(!j.ok){alert('Échec : '+(j.error||'?'));btn.disabled=false;btn.textContent='✓ Fusionner';return;}
      btn.textContent='✅ Fusionné'; setTimeout(()=>(window.patReload||(()=>location.reload()))(),600);
    }catch(e){alert('Erreur : '+e.message);btn.disabled=false;btn.textContent='✓ Fusionner';}
  };
})();
</script>
<?php endif;

// ── FUSION D'IMMEUBLES (staff manager) : doublons d'adresse → 1 immeuble ──
if ($canMerge): ?>
<div id="immergebar" style="position:fixed;left:50%;bottom:64px;transform:translateX(-50%);z-index:9000;display:none;background:#1a237e;color:#fff;border-radius:12px;padding:10px 16px;box-shadow:0 10px 30px rgba(0,0,0,.3);align-items:center;gap:14px;font-size:14px;">
  <span>🏢 <b id="imb-count">0</b> immeuble(s)</span>
  <button type="button" class="mb-btn mb-go" id="imb-go" disabled onclick="openImmMergeModal()" style="cursor:pointer;border:none;border-radius:8px;padding:8px 14px;font-weight:800;font-size:13px;background:#4878a6;color:#fff;">🔀 Fusionner les immeubles</button>
  <button type="button" onclick="immMergeClear()" style="cursor:pointer;border:none;border-radius:8px;padding:8px 14px;font-weight:800;font-size:13px;background:#5a5650;color:#fff;">Annuler</button>
</div>
<div class="mg-overlay" id="imm-overlay" onclick="immModalClose()"></div>
<div class="mg-modal" id="imm-modal">
  <h3>🔀 Fusion de 2 immeubles en doublon</h3>
  <div style="color:#7a766f;font-size:13px;">Choisis l'immeuble à CONSERVER puis l'adresse à garder. Tous les biens (et documents) de l'autre y seront rattachés ; le doublon sera désactivé.</div>
  <div class="mg-dst" id="imm-dst"></div>
  <div id="imm-fields"><div style="padding:18px;text-align:center;color:#7a766f;">Chargement…</div></div>
  <div class="mg-foot">
    <button type="button" class="mg-cancel" onclick="immModalClose()">Annuler</button>
    <button type="button" class="mg-do" id="imm-do" onclick="confirmImmMerge()">✓ Fusionner</button>
  </div>
</div>
<script>
(function(){
  let picks=[], cmp=null;
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  function val(v){return (v===null||v===''||v===undefined)?'<span class="mg-opt empty">(vide)</span>':esc(v);}
  window.immMergeSync=function(){
    picks=[...document.querySelectorAll('.imm-pick:checked')].map(c=>({id:+c.dataset.iid,label:c.dataset.label||('#'+c.dataset.iid)}));
    document.getElementById('imb-count').textContent=picks.length;
    document.getElementById('imb-go').disabled=(picks.length!==2);
    document.getElementById('immergebar').style.display=picks.length>0?'flex':'none';
  };
  window.immMergeClear=function(){document.querySelectorAll('.imm-pick:checked').forEach(c=>c.checked=false);immMergeSync();};
  window.immModalClose=function(){document.getElementById('imm-overlay').style.display='none';document.getElementById('imm-modal').style.display='none';};
  window.openImmMergeModal=async function(){
    if(picks.length!==2)return;
    document.getElementById('imm-overlay').style.display='block';
    document.getElementById('imm-modal').style.display='block';
    document.getElementById('imm-fields').innerHTML='<div style="padding:18px;text-align:center;color:#7a766f;">Chargement…</div>';
    try{
      const r=await fetch('api/immeuble_compare.php?a='+picks[0].id+'&b='+picks[1].id);
      const j=await r.json();
      if(!j.ok){document.getElementById('imm-fields').innerHTML='<div style="padding:14px;color:#c62828;">⚠ '+esc(j.error)+'</div>';return;}
      cmp=j; renderImm();
    }catch(e){document.getElementById('imm-fields').innerHTML='<div style="padding:14px;color:#c62828;">⚠ '+esc(e.message)+'</div>';}
  };
  function renderImm(){
    const A=picks[0],B=picks[1];
    const dstDef=(cmp.b.nb_biens>cmp.a.nb_biens)?'b':'a';
    document.getElementById('imm-dst').innerHTML=
      '<label data-side="a"><input type="radio" name="imm-dst" value="a" '+(dstDef==='a'?'checked':'')+'> <b>Garder A</b> · '+esc(A.label)+'<div style="color:#7a766f">'+cmp.a.nb_biens+' bien(s)</div></label>'+
      '<label data-side="b"><input type="radio" name="imm-dst" value="b" '+(dstDef==='b'?'checked':'')+'> <b>Garder B</b> · '+esc(B.label)+'<div style="color:#7a766f">'+cmp.b.nb_biens+' bien(s)</div></label>';
    let rows='<table class="mg-tbl"><thead><tr><th>Champ</th><th>A</th><th>B</th></tr></thead><tbody>';
    cmp.fields.forEach((f,i)=>{
      rows+='<tr'+(f.conflit?' class="conflit"':'')+'><td>'+esc(f.label)+(f.conflit?' ⚠':'')+'</td>'+
        '<td><label class="mg-opt"><input type="radio" name="imf_'+i+'" value="a" '+(f.defaut==='a'?'checked':'')+'> '+val(f.a)+'</label></td>'+
        '<td><label class="mg-opt"><input type="radio" name="imf_'+i+'" value="b" '+(f.defaut==='b'?'checked':'')+'> '+val(f.b)+'</label></td></tr>';
    });
    rows+='</tbody></table>';
    document.getElementById('imm-fields').innerHTML=rows;
    document.querySelectorAll('#imm-dst label').forEach(l=>{const upd=()=>document.querySelectorAll('#imm-dst label').forEach(x=>x.classList.toggle('sel',x.querySelector('input').checked));l.querySelector('input').addEventListener('change',upd);upd();});
  }
  window.confirmImmMerge=async function(){
    if(!cmp)return;
    const dstSide=(document.querySelector('input[name="imm-dst"]:checked')||{}).value||'a';
    const keep=(dstSide==='a')?picks[0]:picks[1], drop=(dstSide==='a')?picks[1]:picks[0];
    const fields={};
    cmp.fields.forEach((f,i)=>{const sel=(document.querySelector('input[name="imf_'+i+'"]:checked')||{}).value||f.defaut;const v=(sel==='a')?f.a:f.b;if(v!==null&&v!==undefined&&v!=='')fields[f.col]=v;});
    if(!confirm('Fusionner l\'immeuble « '+drop.label+' » dans « '+keep.label+' » ?\nTous ses biens y seront rattachés. Irréversible.'))return;
    const btn=document.getElementById('imm-do');btn.disabled=true;btn.textContent='⏳ Fusion…';
    try{
      const fd=new FormData();fd.append('destination_id',keep.id);fd.append('source_id',drop.id);fd.append('fields_json',JSON.stringify(fields));
      const r=await fetch('api/immeuble_merge_action.php',{method:'POST',body:fd});
      const j=await r.json();
      if(!j.ok){alert('Échec : '+(j.error||'?'));btn.disabled=false;btn.textContent='✓ Fusionner';return;}
      btn.textContent='✅ Fusionné';setTimeout(()=>(window.patReload||(()=>location.reload()))(),600);
    }catch(e){alert('Erreur : '+e.message);btn.disabled=false;btn.textContent='✓ Fusionner';}
  };
})();
</script>
<?php endif;

// ── MODE « BIENS À PROPOSER » : boutons À proposer / Ne pas proposer + filtre ──
if ($mode === 'proposer'): ?>
<style>
.pf-actions{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.pf-btn{cursor:pointer;border:none;border-radius:7px;padding:5px 9px;font-size:12px;font-weight:800;}
.pf-set{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;}
.pf-on{background:#2e7d32;color:#fff;}
.pf-off{background:#fdecea;color:#c0453f;border:1px solid #f1b0ab;}
.pf-btn:hover{filter:brightness(1.05);}
.pf-btn:disabled{opacity:.5;cursor:default;}
.vente-panel .pf-spacer{flex:1 1 auto;}
/* Filtre déplacé dans la barre scénario (.pf-filter-inline) */
.pf-totaux-line1{margin-left:auto;font-size:12.5px;font-weight:700;}
.pf-filter-inline{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;flex-basis:100%;justify-content:flex-end;}
.pf-filter-inline .pf-flbl{font-weight:800;color:#374151;font-size:12.5px;}
.pf-fbtn{cursor:pointer;border:1px solid #cfd6de;background:#f4f6f8;color:#374151;
  border-radius:8px;padding:5px 10px;font-weight:700;font-size:12.5px;}
.pf-fbtn.active{background:#1a237e;color:#fff;border-color:#1a237e;}
.pf-rangefilter{display:inline-flex;align-items:center;gap:4px;margin-left:14px;padding-left:14px;border-left:1px solid #d7dde5;}
.pf-rin{width:80px;border:1px solid #cfd6de;border-radius:8px;padding:5px 8px;font-size:12.5px;color:#374151;background:#fff;}
.pf-rin-sm{width:58px;}
.pf-rin:focus{outline:none;border-color:#1a237e;box-shadow:0 0 0 2px rgba(26,35,126,.12);}
.pf-rsep{color:#9aa4b4;font-weight:700;}
.pf-runit{color:#6b7280;font-size:12px;margin-left:2px;}
.pf-rclear{cursor:pointer;border:1px solid #cfd6de;background:#f4f6f8;color:#6b7280;border-radius:8px;padding:4px 8px;font-size:12px;margin-left:6px;}
.pf-rclear:hover{background:#fde8e8;color:#c0392b;border-color:#e7b7b7;}
</style>
<script>
(function(){
  const CSRF_STATUT=<?= json_encode(csrf_token('portefeuille_statut')) ?>;
  window.pfToggle=async function(idBien,aProposer,btn){
    if(btn){btn.disabled=true;btn.dataset.t=btn.textContent;btn.textContent='…';}
    function fail(msg){alert('À proposer — échec :\n'+msg);if(btn){btn.disabled=false;btn.textContent=btn.dataset.t;}}
    try{
      const fd=new FormData();fd.append('id_bien',idBien);fd.append('proposer',aProposer?'1':'0');fd.append('csrf_token',CSRF_STATUT);
      const r=await fetch('api/bien_proposer.php',{method:'POST',body:fd,headers:{'X-CSRF-Token':CSRF_STATUT}});
      const txt=await r.text();
      let j=null; try{ j=JSON.parse(txt); }catch(e){
        return fail('Réponse non-JSON (HTTP '+r.status+'). '+(r.status===404?'Le fichier api/bien_proposer.php n’est pas déployé.':'')+'\n'+txt.slice(0,200));
      }
      if(!j.ok){return fail((j.error||j.message||'?')+' (HTTP '+r.status+')');}
      (window.patReload||(()=>location.reload()))();
    }catch(e){fail(e.message);}
  };
  const pfState={mode:'all',pmin:null,pmax:null,smin:null,smax:null};
  const _num=id=>{const v=parseFloat(document.getElementById(id).value);return isNaN(v)?null:v;};
  window.pfFilter=function(f,btn){
    pfState.mode=f;
    document.querySelectorAll('.pf-fbtn').forEach(b=>b.classList.toggle('active',b===btn));
    applyPfFilter();
  };
  window.pfRange=function(){
    pfState.pmin=_num('pf-prix-min');pfState.pmax=_num('pf-prix-max');
    pfState.smin=_num('pf-surf-min');pfState.smax=_num('pf-surf-max');
    applyPfFilter();
  };
  window.pfRangeClear=function(){
    ['pf-prix-min','pf-prix-max','pf-surf-min','pf-surf-max'].forEach(id=>document.getElementById(id).value='');
    pfRange();
  };
  function applyPfFilter(){
    const active=pfState.mode!=='all'||pfState.pmin!=null||pfState.pmax!=null||pfState.smin!=null||pfState.smax!=null;
    let shown=0;
    document.querySelectorAll('tr.pf-frow:not(.vente-row)[data-propose]').forEach(tr=>{
      const prix=parseInt(tr.dataset.prix,10)||0, surf=parseFloat(tr.dataset.surface)||0;
      let ok=(pfState.mode==='all')||(tr.dataset.propose===pfState.mode);
      if(ok&&pfState.pmin!=null) ok=prix>=pfState.pmin;
      if(ok&&pfState.pmax!=null) ok=ok&&prix<=pfState.pmax;
      if(ok&&pfState.smin!=null) ok=ok&&surf>=pfState.smin;
      if(ok&&pfState.smax!=null) ok=ok&&surf<=pfState.smax;
      tr.style.display=ok?'':'none';
      const nx=tr.nextElementSibling;
      if(nx&&nx.classList.contains('vente-row')) nx.style.display=ok?'':'none';
      if(ok) shown++;
    });
    document.getElementById('pf-fcount').textContent=active?(shown+' bien(s)'):'';
  }
  function eur(n){return (Math.round(n)||0).toLocaleString('fr-FR')+' €';}
  function pfTotaux(){
    // On somme par bien (ligne principale, pas la ligne simulateur) pour éviter le doublon.
    let tProp=0,tNon=0;
    document.querySelectorAll('tr.pf-frow[data-prix]:not(.vente-row)').forEach(tr=>{
      const p=parseInt(tr.dataset.prix,10)||0;
      if(tr.dataset.propose==='1') tProp+=p; else tNon+=p;
    });
    document.getElementById('pf-totaux').innerHTML=
      '🟢 À proposer : <span style="color:#2e7d32">'+eur(tProp)+'</span>'+
      ' &nbsp;·&nbsp; ⚪ Non : <span style="color:#6b7280">'+eur(tNon)+'</span>'+
      ' &nbsp;·&nbsp; Σ <span style="color:#1a237e">'+eur(tProp+tNon)+'</span>';
  }
  document.addEventListener('DOMContentLoaded',pfTotaux);
})();
</script>

<!-- Suggestions de recherche (même moteur serveur que le topbar : api/quick_search.php).
     Bloc ISOLÉ + fetch : ne peut pas casser le reste de la page (pas de JSON inline). -->
<script>
(function(){
  var inp=document.getElementById('pat-search'), box=document.getElementById('pat-sugg');
  if(!inp||!box) return;
  var URL=<?= json_encode(app_url('/api/quick_search.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var BIEN=<?= json_encode(app_url('/bien_360.php?id='), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var t, items=[];
  function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
  function hide(){ box.style.display='none'; box.innerHTML=''; }
  function render(res){
    items=res||[];
    if(!items.length){ hide(); return; }
    box.innerHTML=items.map(function(r,i){
      var sub=[r.ref, r.ville, r.loc].filter(Boolean).join(' · ');
      return '<div class="pat-sugg-item" data-i="'+i+'"><span class="pat-sugg-badge pat-sugg-B">BIEN</span>'
        +'<span>'+esc(r.adresse||r.ref||('#'+r.id))+(sub?(' <span style="color:#8a8680">'+esc(sub)+'</span>'):'')+'</span></div>';
    }).join('');
    box.style.display='';
    Array.prototype.slice.call(box.querySelectorAll('.pat-sugg-item')).forEach(function(el){
      el.addEventListener('mousedown', function(e){ e.preventDefault();
        var r=items[+el.getAttribute('data-i')]; if(!r) return;
        if(window.openFicheModal) window.openFicheModal(BIEN+r.id); else window.open(BIEN+r.id,'_blank'); });
    });
  }
  function go(){
    var q=(inp.value||'').trim(); if(q.length<2){ hide(); return; }
    fetch(URL+'?q='+encodeURIComponent(q),{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){ render(j&&j.ok?j.results:[]); })
      .catch(function(){ hide(); });
  }
  inp.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(go,220); });
  inp.addEventListener('blur', function(){ setTimeout(hide,180); });
  inp.addEventListener('keydown', function(e){ if(e.key==='Escape') hide(); });
})();
</script>

<!-- Modal fiche (bien / locataire) : reste DANS le module bailleur, sans bascule agency.
     La fiche est chargée en iframe avec embed=1&ctx=bailleur → page épurée + actions agency masquées. -->
<div id="fiche-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,18,24,.6);">
  <div style="position:absolute;inset:18px;background:#fff;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.45);">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-bottom:1px solid #eee;background:#f5f3fb;">
      <strong style="color:#4527a0;font-size:13px;">Fiche (consultation bailleur)</strong>
      <button type="button" onclick="closeFicheModal()" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 14px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <iframe id="fiche-modal-frame" src="" style="flex:1;border:0;width:100%;"></iframe>
  </div>
</div>
<script>
(function(){
  function withEmbed(u){ if(!u) return u; return u + (u.indexOf('?')>=0?'&':'?') + 'embed=1&ctx=bailleur'; }
  window.openFicheModal=function(u){
    var m=document.getElementById('fiche-modal'), f=document.getElementById('fiche-modal-frame');
    if(!m||!f) return; f.src=withEmbed(u); m.style.display='block';
  };
  window.closeFicheModal=function(){
    var m=document.getElementById('fiche-modal'), f=document.getElementById('fiche-modal-frame');
    if(m) m.style.display='none'; if(f) f.src='about:blank';
  };
  // Interception : tout lien vers bien_360 / locataire_360 / bail_360 → ouvre en modal (pas de bascule agency)
  document.addEventListener('click', function(e){
    var a=e.target.closest ? e.target.closest('a') : null; if(!a) return;
    var href=a.getAttribute('href')||'';
    if(/(bien_360|locataire_360|bail_360)\.php/.test(href) && href.indexOf('embed=1')<0){
      e.preventDefault(); window.openFicheModal(a.href);
    }
  }, true);
  var bg=document.getElementById('fiche-modal');
  if(bg) bg.addEventListener('click', function(e){ if(e.target===this) closeFicheModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeFicheModal(); });
})();
</script>
<?php endif;

// ── Scénarios disponibles (pour le modal « Comparer ») ──
$cmpScenarios = [];
try {
    $rowsSc = $pdo->query("SELECT scenario_code, COALESCE(MAX(NULLIF(scenario_label,'')), scenario_code) AS lbl, COUNT(DISTINCT id_bien) AS nb
                           FROM bien_prix WHERE type_valeur='prix_vente' AND is_courant=1 AND montant>0
                           GROUP BY scenario_code ORDER BY (scenario_code='courant') DESC, nb DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsSc as $r) { $cmpScenarios[] = $r; }
} catch (Throwable) {}
$cmpBailleur = isset($_GET['bailleur']) ? (int)$_GET['bailleur'] : 0;
?>
<div id="cmp-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;max-width:520px;width:92%;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
      <h3 style="margin:0;color:#243B5C;font-size:18px;">⚖️ Comparer des scénarios</h3>
      <button type="button" onclick="fermerCompareScenarios()" style="border:none;background:#f1f5f9;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;">✕</button>
    </div>
    <p style="color:#64748b;font-size:13px;margin:4px 0 14px;">Sélectionne <strong>2 ou 3</strong> scénarios à comparer côte à côte.</p>
    <div id="cmp-list" style="display:flex;flex-direction:column;gap:8px;max-height:320px;overflow:auto;">
      <?php foreach ($cmpScenarios as $s): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid #e3ebf5;border-radius:10px;cursor:pointer;">
          <input type="checkbox" class="cmp-cb" value="<?= htmlspecialchars($s['scenario_code'], ENT_QUOTES) ?>" onchange="cmpLimit(this)">
          <span style="font-weight:700;color:#2d4a72;"><?= htmlspecialchars($s['lbl'], ENT_QUOTES) ?></span>
          <span style="color:#94a3b8;font-size:12px;margin-left:auto;"><?= (int)$s['nb'] ?> biens</span>
        </label>
      <?php endforeach; ?>
      <?php if (!$cmpScenarios): ?><em style="color:#94a3b8;">Aucun scénario avec des prix.</em><?php endif; ?>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
      <button type="button" onclick="fermerCompareScenarios()" style="border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer;">Annuler</button>
      <button type="button" id="cmp-go" onclick="lancerCompareScenarios()" disabled style="border:none;background:#0e6b75;color:#fff;border-radius:8px;padding:8px 18px;font-weight:700;cursor:pointer;">Comparer →</button>
    </div>
  </div>
</div>
<script>
const CMP_BAILLEUR = <?= $cmpBailleur ?>;
function ouvrirCompareScenarios(){ document.getElementById('cmp-modal').style.display='flex'; }
function fermerCompareScenarios(){ document.getElementById('cmp-modal').style.display='none'; }
function cmpChecked(){ return [...document.querySelectorAll('.cmp-cb:checked')].map(c=>c.value); }
function cmpLimit(cb){
  const sel=cmpChecked();
  if(sel.length>3){ cb.checked=false; }
  const n=cmpChecked().length;
  document.getElementById('cmp-go').disabled = (n<2);
}
function lancerCompareScenarios(){
  const sel=cmpChecked(); if(sel.length<2) return;
  let url='bailleur_scenarios_compare.php?scenarios='+encodeURIComponent(sel.join(','));
  if(CMP_BAILLEUR>0) url+='&bailleur='+CMP_BAILLEUR;
  window.location.href=url;
}
</script>
<?php
require_once __DIR__ . '/inc/agency_layout_bottom.php';
