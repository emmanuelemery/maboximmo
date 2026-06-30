<?php
/**
 * merge_immeuble_dups.php — Fusion sûre des doublons d'immeubles (par google_place_id).
 *
 * RÈGLES DE SÉCURITÉ :
 *  - On ne fusionne QUE des immeubles d'une même adresse (place_id) qui désignent
 *    le MÊME bâtiment : soit le même code_crg, soit des orphelins (sans code_crg)
 *    quand le groupe ne contient qu'UN seul code_crg distinct.
 *  - Si le groupe contient PLUSIEURS code_crg distincts (ex. "6 Maupassant" vs
 *    "6 Maupassant-Garages") → bâtiments DISTINCTS → on NE fusionne PAS, on signale.
 *  - Survivant = immeuble portant une annonce ; sinon le plus de biens ; sinon plus petit id.
 *  - On NE supprime JAMAIS d'annonce : les biens (et donc leurs annonces) sont
 *    DÉPLACÉS vers le survivant, pas supprimés.
 *
 * Usage : php merge_immeuble_dups.php        (DRY-RUN)
 *         php merge_immeuble_dups.php go      (applique, transaction)
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
$pdo = $GLOBALS['pdo'];

// ── Accès : CLI libre, mais via web = super-admin uniquement ───────────
$IS_WEB = (PHP_SAPI !== 'cli');
if ($IS_WEB) {
    require_once __DIR__ . '/../inc/auth.php';
    require_login();
    $roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
    if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }
    @set_time_limit(600);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Fusion immeubles</title><style>
    body{font-family:Sora,system-ui,sans-serif;background:#f7f4ef;margin:0;padding:28px;color:#243B5C}
    h1{margin:0 0 4px} .sub{color:#7a766f;margin-bottom:20px}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px}
    .kpi{background:#fff;border-radius:12px;padding:16px;text-align:center;box-shadow:0 3px 10px rgba(0,0,0,.06)}
    .kpi strong{display:block;font-size:26px} .kpi span{font-size:11px;color:#7a766f}
    .card{background:#fff;border-radius:12px;padding:18px 22px;margin-bottom:16px;box-shadow:0 3px 10px rgba(0,0,0,.06)}
    table{width:100%;border-collapse:collapse;font-size:13px} td,th{padding:7px 10px;border-bottom:1px solid #f0ece6;text-align:left;vertical-align:top}
    th{color:#7a766f;font-size:11px;text-transform:uppercase}
    .surv{color:#2d6a35;font-weight:700} .lose{color:#8a6d3b} .flag{color:#a8323b}
    .btn{display:inline-block;padding:12px 24px;border-radius:9px;color:#fff;text-decoration:none;font-weight:700;background:#a8323b;border:0;cursor:pointer;font-size:14px}
    .btn-sm{padding:7px 14px;background:#2d6a35;font-size:13px}
    .tag{font-size:11px;background:#eef2f7;padding:2px 7px;border-radius:6px;color:#3a6890}
    .arb td{border-bottom:1px solid #f0ece6} .arb label{cursor:pointer}
    </style>';
}

// GO via CLI (argv) OU via web (?go=1)
$GO = (($argv[1] ?? '') === 'go') || (($_GET['go'] ?? '') === '1');

// Tables à repointer (id_immeuble) — hors table de backup biens_prop_bak.
$repoint = ['biens','immeubles_gestion','immeubles_infos','agency_reunion','agency_facture','agency_contrat','agency_mandant','agency_mandat','agency_syndic_proposition','agency_syndic_tarif','bailleur_documents','contrat_syndic','factures','ged_classification_staging','ged_documents','ged_import_releves_items','immeubles_documents','biens_supprimes','reg_mandats','retour_ag'];

function stats(PDO $pdo, int $id): array {
    return [
        (int)$pdo->query("SELECT COUNT(*) FROM biens WHERE id_immeuble=$id")->fetchColumn(),
        (int)$pdo->query("SELECT COUNT(*) FROM annonces a JOIN biens b ON b.id=a.id_bien WHERE b.id_immeuble=$id")->fetchColumn(),
    ];
}

// Absorbe un immeuble perdant $L dans le survivant $S (déplace biens, repointe tables, supprime $L).
function absorbLoser(PDO $pdo, int $S, int $L, array $repoint): int {
    $moved = 0;
    $bs = $pdo->query("SELECT id, numero_lot, code_crg FROM biens WHERE id_immeuble=$L")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bs as $b) {
        $coll = null;
        if ($b['code_crg']) {
            $c = $pdo->prepare("SELECT id FROM biens WHERE id_immeuble=? AND code_crg=? AND id<>? LIMIT 1");
            $c->execute([$S, $b['code_crg'], $b['id']]); $coll = $c->fetchColumn();
        }
        if ($coll) {
            $pdo->prepare("UPDATE annonces SET id_bien=? WHERE id_bien=?")->execute([$coll, $b['id']]);
            $pdo->prepare("UPDATE baux SET id_bien=? WHERE id_bien=?")->execute([$coll, $b['id']]);
            $pdo->prepare("UPDATE crg_situations_locataires SET id_bien=? WHERE id_bien=?")->execute([$coll, $b['id']]);
            $pdo->prepare("DELETE FROM biens WHERE id=?")->execute([$b['id']]);
        } else {
            $pdo->prepare("UPDATE biens SET id_immeuble=? WHERE id=?")->execute([$S, $b['id']]); $moved++;
        }
    }
    foreach ($repoint as $t) {
        if ($t === 'biens') continue;
        try { $pdo->prepare("UPDATE `$t` SET id_immeuble=? WHERE id_immeuble=?")->execute([$S, $L]); } catch (Throwable $e) {}
    }
    $pdo->prepare("DELETE FROM immeubles WHERE id=?")->execute([$L]);
    return $moved;
}

// Applique une liste de fusions [{S, losers[], crg?, keep_name?}] en transaction + invariants (rollback si anomalie).
function mergeWithInvariants(PDO $pdo, array $items, array $repoint): array {
    $annB = (int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn();
    $brkB = (int)$pdo->query("SELECT COUNT(*) FROM annonces a LEFT JOIN biens b ON b.id=a.id_bien WHERE b.id IS NULL")->fetchColumn();
    $bnB  = (int)$pdo->query("SELECT COUNT(*) FROM biens")->fetchColumn();
    $pdo->beginTransaction();
    $nM=0;$nMov=0;$nDel=0;
    foreach ($items as $it) {
        $S=(int)$it['S']; if($S<=0) continue;
        if (!empty($it['crg']))       $pdo->prepare("UPDATE immeubles SET code_crg=? WHERE id=? AND (code_crg IS NULL OR code_crg='')")->execute([$it['crg'],$S]);
        if (!empty($it['keep_name'])) $pdo->prepare("UPDATE immeubles SET nom_immeuble=? WHERE id=? AND nom_immeuble<>?")->execute([$it['keep_name'],$S,$it['keep_name']]);
        foreach ($it['losers'] as $L) { $L=(int)$L; if($L<=0||$L===$S) continue; $nMov += absorbLoser($pdo,$S,$L,$repoint); $nDel++; }
        $nM++;
    }
    $annA = (int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn();
    $brkA = (int)$pdo->query("SELECT COUNT(*) FROM annonces a LEFT JOIN biens b ON b.id=a.id_bien WHERE b.id IS NULL")->fetchColumn();
    $brkBien = (int)$pdo->query("SELECT COUNT(*) FROM biens b WHERE b.id_immeuble IS NOT NULL AND NOT EXISTS (SELECT 1 FROM immeubles i WHERE i.id=b.id_immeuble)")->fetchColumn();
    $err=[];
    if ($annA!==$annB)  $err[]="annonces $annB → $annA (doit être identique)";
    if ($brkA>$brkB)    $err[]="annonces orphelines $brkB → $brkA";
    if ($brkBien>0)     $err[]="$brkBien biens pointent vers un immeuble supprimé";
    if ($err){ $pdo->rollBack(); return ['ok'=>false,'errors'=>$err]; }
    $pdo->commit();
    $bnA=(int)$pdo->query("SELECT COUNT(*) FROM biens")->fetchColumn();
    return ['ok'=>true,'merged'=>$nM,'moved'=>$nMov,'deleted'=>$nDel,'annB'=>$annB,'annA'=>$annA,'biensB'=>$bnB,'biensA'=>$bnA];
}

// ── Handler ARBITRAGE MANUEL (POST) : fusion d'une sélection libre ─────
$manualDone = null;
if ($IS_WEB && ($_POST['action'] ?? '') === 'manual_merge') {
    $S = (int)($_POST['survivor'] ?? 0);
    $losers = array_filter(array_map('intval', (array)($_POST['losers'] ?? [])), fn($x)=>$x>0 && $x!==$S);
    $keep = trim((string)($_POST['keep_name'] ?? ''));
    if ($S>0 && $losers) {
        $r = mergeWithInvariants($pdo, [['S'=>$S,'losers'=>$losers,'crg'=>null,'keep_name'=>$keep]], $repoint);
        $manualDone = $r['ok']
            ? '<div class="card"><h3 class="surv">✅ Fusion manuelle appliquée</h3><p>survivant #'.$S.' · '.count($losers).' immeuble(s) absorbé(s) · '.$r['moved'].' biens déplacés</p></div>'
            : '<div class="card"><h3 class="flag">❌ ROLLBACK : '.htmlspecialchars(implode(' ; ',$r['errors'])).'</h3></div>';
    } else {
        $manualDone = '<div class="card"><h3 class="flag">⚠️ Sélection invalide (un survivant + au moins un immeuble à fusionner).</h3></div>';
    }
    echo $manualDone; // affiché en haut ; les listes ci-dessous sont recalculées après fusion
}

$dups = $pdo->query("SELECT google_place_id, GROUP_CONCAT(id) ids
    FROM immeubles WHERE google_place_id IS NOT NULL AND google_place_id<>''
    GROUP BY google_place_id HAVING COUNT(*)>1")->fetchAll(PDO::FETCH_ASSOC);

// Normalise une adresse_1 → "n° + nom de rue" comparable (sans type de voie, accents, casse).
// Permet de fusionner "18 Pipet" == "18 Rue Pipet", mais PAS "4 Place St Martin" != "10 Rue Pérouillère".
function normAdr(?string $s): string {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','û'=>'u','ù'=>'u','ü'=>'u','ç'=>'c']);
    // retire les types de voie (mots entiers)
    $s = preg_replace('/\b(rue|avenue|av|bd|boulevard|route|rte|place|pl|cours|impasse|imp|chemin|che|allee|quai|montee|passage|square|venelle|chaussee|faubourg|fbg|promenade|cite|clos|lotissement|grand|grande|petite|de|du|des|la|le|les|d|l)\b/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// Vrai nom de RÉSIDENCE (à préserver) vs code/adresse (LI0008, "13 PINEL", réf chiffrée…)
function isResidenceName(?string $n): bool {
    $n = trim((string)$n);
    if ($n === '' || mb_strlen($n) < 4) return false;
    if (preg_match('/^\d/', $n)) return false;       // commence par un chiffre = adresse
    if (preg_match('/^LI\s*\d/i', $n)) return false; // code type LI0008
    return true;
}

// Adresse lisible à partir de ce qu'on a (sans re-appeler Google) : adresse_formatee si dispo, sinon adresse_1+CP+ville
function addrTxt(array $r): string {
    $f = trim((string)($r['adresse_formatee'] ?? ''));
    if ($f !== '') return $f;
    return trim(trim((string)($r['adresse_1'] ?? '')).' '.trim((string)($r['code_postal'] ?? '')).' '.trim((string)($r['ville'] ?? '')));
}
$hasGps = fn(array $r) => ($r['latitude'] ?? null) !== null && ($r['longitude'] ?? null) !== null;

$plan = []; $flagged = [];
foreach ($dups as $d) {
    $imms = $pdo->query("SELECT id, nom_immeuble, adresse_1, code_postal, ville, adresse_formatee, latitude, longitude, code_crg, id_proprietaire FROM immeubles WHERE id IN ({$d['ids']})")->fetchAll(PDO::FETCH_ASSOC);

    // ── SÉCURITÉ : sous-grouper par adresse_1 normalisée ───────────────
    // Un même place_id approximatif (centre-ville) peut couvrir des bâtiments DIFFÉRENTS.
    // On ne fusionne QUE les immeubles dont l'adresse réelle (n°+rue) coïncide.
    $byAdr = [];
    foreach ($imms as $x) { $byAdr[normAdr($x['adresse_1'])][] = $x; }

    if (count($byAdr) > 1) {
        // place_id partagé par plusieurs adresses distinctes → on signale, on ne fusionne pas en bloc
        $flagged[] = ['adr' => addrTxt($imms[0]), 'imms' => $imms,
                      'why' => count($byAdr).' adresses distinctes sous le même place_id (géocodage approximatif)'];
        // mais on traite quand même les sous-groupes réellement identiques ci-dessous
    }

    foreach ($byAdr as $adrKey => $grp) {
        if ($adrKey === '' || count($grp) < 2) continue; // adresse vide ou rien à fusionner

        $distinctCrg = array_values(array_unique(array_filter(array_map(fn($x)=>trim((string)$x['code_crg']), $grp))));
        if (count($distinctCrg) > 1) {
            $flagged[] = ['adr' => addrTxt($grp[0]), 'imms' => $grp, 'why' => count($distinctCrg).' code_crg distincts (bâtiments différents)'];
            continue;
        }
        foreach ($grp as &$x) { [$x['_b'],$x['_a']] = stats($pdo,(int)$x['id']); } unset($x);
        usort($grp, function($a,$b){
            if ($a['_a'] !== $b['_a']) return $b['_a'] <=> $a['_a']; // annonce d'abord
            if ($a['_b'] !== $b['_b']) return $b['_b'] <=> $a['_b']; // + de biens
            return $a['id'] <=> $b['id'];                            // plus petit id
        });
        // Meilleur nom de RÉSIDENCE du groupe à conserver sur le survivant.
        // (le survivant reste le plus riche en données ; on lui greffe juste le bon nom)
        $keepName = $grp[0]['nom_immeuble'];
        if (!isResidenceName($keepName)) {
            foreach ($grp as $x) { if (isResidenceName($x['nom_immeuble'])) { $keepName = trim($x['nom_immeuble']); break; } }
        }
        $plan[] = ['adr'=>addrTxt($grp[0]), 'gps'=>$hasGps($grp[0]), 'survivor'=>$grp[0], 'losers'=>array_slice($grp,1),
                   'crg'=>$distinctCrg[0] ?? null, 'keep_name'=>$keepName];
    }
}

// ── Affichage du plan ─────────────────────────────────────────────
$nbLosers = array_sum(array_map(fn($p)=>count($p['losers']), $plan));
if ($IS_WEB) {
    $H = fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ?>
    <h1><?= $GO ? '🔧 Fusion appliquée' : '🔍 Aperçu de fusion (dry-run)' ?></h1>
    <div class="sub"><?= $GO ? 'Résultat ci-dessous.' : 'Aucune écriture — vérifie le plan avant d\'appliquer.' ?></div>
    <div class="kpis">
      <div class="kpi"><strong><?= count($plan) ?></strong><span>Groupes à fusionner</span></div>
      <div class="kpi"><strong><?= $nbLosers ?></strong><span>Immeubles absorbés</span></div>
      <div class="kpi"><strong class="flag"><?= count($flagged) ?></strong><span>Non fusionnés (à voir)</span></div>
    </div>

    <div class="card">
      <h3>✅ Fusions prévues (adresse réelle identique)</h3>
      <table>
        <thead><tr><th>Adresse</th><th>Survivant (gardé)</th><th>Absorbés</th></tr></thead>
        <tbody>
        <?php foreach ($plan as $p): ?>
          <tr>
            <td><?= $H($p['adr']) ?>
                <?php if ($p['gps']): ?><span class="tag" style="background:#e7f3ea;color:#2d6a35">📍 GPS</span>
                <?php else: ?><span class="tag" style="background:#fbeaec;color:#a8323b">⚠️ sans GPS</span><?php endif; ?></td>
            <td class="surv"><a href="/agency_immeuble_fiche.php?id=<?= (int)$p['survivor']['id'] ?>" target="_blank" rel="noopener">#<?= (int)$p['survivor']['id'] ?> 🔗</a> <?= $H($p['keep_name']) ?>
                <?php if (trim((string)$p['keep_name']) !== trim((string)$p['survivor']['nom_immeuble'])): ?>
                  <span class="tag" style="background:#e7f3ea;color:#2d6a35">nom repris du groupe (était : <?= $H($p['survivor']['nom_immeuble']) ?>)</span>
                <?php endif; ?>
                <span class="tag">biens <?= $p['survivor']['_b'] ?> · annonces <?= $p['survivor']['_a'] ?></span></td>
            <td><?php foreach ($p['losers'] as $l): ?>
                <div class="lose">← <a href="/agency_immeuble_fiche.php?id=<?= (int)$l['id'] ?>" target="_blank" rel="noopener">#<?= (int)$l['id'] ?> 🔗</a> <?= $H($l['nom_immeuble']) ?>
                <span class="tag">biens <?= $l['_b'] ?> · annonces <?= $l['_a'] ?></span></div>
                <?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($flagged): ?>
    <h3 class="flag" style="margin-top:26px">⚠️ À arbitrer manuellement (<?= count($flagged) ?>) — choisis le survivant (conseillé pré-coché) et coche les immeubles à fusionner dedans</h3>
    <?php foreach ($flagged as $f):
        foreach ($f['imms'] as &$x) { [$x['_b'],$x['_a']] = stats($pdo,(int)$x['id']); } unset($x);
        $best = $f['imms'][0];
        foreach ($f['imms'] as $x) { if ($x['_a'] > $best['_a'] || ($x['_a']==$best['_a'] && $x['_b'] > $best['_b'])) $best = $x; }
        $bestId = (int)$best['id'];
        $kn = ''; foreach ($f['imms'] as $x) { if (isResidenceName($x['nom_immeuble'])) { $kn = trim($x['nom_immeuble']); break; } }
    ?>
    <form method="post" class="card">
      <input type="hidden" name="action" value="manual_merge">
      <input type="hidden" name="keep_name" value="<?= $H($kn) ?>">
      <div style="margin-bottom:6px"><strong><?= $H($f['adr']) ?></strong> &nbsp;<span class="tag flag"><?= $H($f['why']) ?></span></div>
      <table class="arb">
        <thead><tr><th>Survivant</th><th>Fusionner</th><th>Immeuble</th><th>Adresse</th><th>Données</th></tr></thead>
        <tbody>
        <?php foreach ($f['imms'] as $x): $id=(int)$x['id']; $reco=($id===$bestId); ?>
          <tr>
            <td><label><input type="radio" name="survivor" value="<?= $id ?>" <?= $reco?'checked':'' ?>> <?= $reco?'<span class="tag" style="background:#e7f3ea;color:#2d6a35">conseillé</span>':'garder' ?></label></td>
            <td><input type="checkbox" name="losers[]" value="<?= $id ?>"></td>
            <td><a href="/agency_immeuble_fiche.php?id=<?= $id ?>" target="_blank" rel="noopener">#<?= $id ?> 🔗</a> <strong><?= $H($x['nom_immeuble']) ?></strong></td>
            <td><span class="tag"><?= $H($x['adresse_1']) ?></span> <?= $H($x['code_postal']) ?> <?= $H($x['ville']) ?></td>
            <td><span class="tag">biens <?= $x['_b'] ?></span>
                <?php if ($x['_a'] > 0): ?><span class="tag" style="background:#e7f3ea;color:#2d6a35">📢 <?= $x['_a'] ?> annonce<?= $x['_a']>1?'s':'' ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn btn-sm" onclick="return confirm('Fusionner les immeubles cochés dans le survivant sélectionné ?')">▶️ Fusionner la sélection</button>
    </form>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php
} else {
    echo $GO ? "=== FUSION (application) ===\n" : "=== DRY-RUN (aucune écriture) ===\n";
    foreach ($plan as $p) {
        echo "\n• {$p['adr']}\n";
        echo "   SURVIVANT  imm#{$p['survivor']['id']} ({$p['survivor']['nom_immeuble']}) — biens:{$p['survivor']['_b']} annonces:{$p['survivor']['_a']}\n";
        foreach ($p['losers'] as $l) echo "   fusionné ← imm#{$l['id']} ({$l['nom_immeuble']}) — biens:{$l['_b']} annonces:{$l['_a']}\n";
    }
    if ($flagged) {
        echo "\n=== NON FUSIONNÉS (à voir manuellement) ===\n";
        foreach ($flagged as $f) {
            echo "\n• {$f['adr']} — {$f['why']}\n";
            foreach ($f['imms'] as $x) echo "   imm#{$x['id']} {$x['nom_immeuble']} [{$x['adresse_1']}]\n";
        }
    }
}

// ── Application ───────────────────────────────────────────────────
if ($GO) {
    $items = array_map(fn($p)=>[
        'S'=>(int)$p['survivor']['id'],
        'losers'=>array_map(fn($l)=>(int)$l['id'], $p['losers']),
        'crg'=>empty($p['survivor']['code_crg']) ? $p['crg'] : null,
        'keep_name'=>$p['keep_name'],
    ], $plan);
    $r = mergeWithInvariants($pdo, $items, $repoint);
    if (!$r['ok']) {
        $msg = "INVARIANT VIOLÉ → ROLLBACK (rien écrit) : " . implode(' ; ', $r['errors']);
        echo $IS_WEB ? '<div class="card"><h3 class="flag">❌ '.htmlspecialchars($msg).'</h3></div>' : "\n❌ $msg\n";
        exit(1);
    }
    if ($IS_WEB) {
        echo '<div class="card"><h2 class="surv">✅ Fusion appliquée</h2>'
           . "<p>{$r['merged']} groupes fusionnés · {$r['moved']} biens déplacés · {$r['deleted']} immeubles supprimés</p>"
           . "<p>Invariants OK : annonces {$r['annB']} = {$r['annA']} · 0 lien cassé · biens {$r['biensB']} → {$r['biensA']}</p>"
           . '<p style="color:#7a766f">(les annonces sont déplacées vers le survivant, jamais supprimées)</p></div>';
    } else {
        echo "\n=== APPLIQUÉ : {$r['merged']} groupes, {$r['moved']} biens déplacés, {$r['deleted']} immeubles supprimés ===\n";
        echo "Invariants OK : annonces {$r['annB']} = {$r['annA']} | 0 lien cassé | biens {$r['biensB']} → {$r['biensA']}\n";
    }
} else {
    if ($IS_WEB) {
        $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        echo '<div class="card"><a class="btn" href="'.htmlspecialchars($base).'?go=1" '
           . 'onclick="return confirm(\'Appliquer la fusion ? Sauvegarde immeubles recommandée avant.\')">▶️ APPLIQUER la fusion</a> '
           . '<span style="color:#7a766f;margin-left:10px">transaction + rollback auto si anomalie</span></div>';
    } else {
        echo "\n→ Appliquer : php merge_immeuble_dups.php go\n";
    }
}
