<?php
/**
 * merge_biens_dups.php — Fusion MANUELLE des doublons de biens (coquilles CRG ↔ vrai bien).
 *
 * Aucune fusion automatique : on liste, par immeuble contenant ≥1 coquille,
 * TOUS ses biens avec leur richesse (bail / annonce / prix / surface) ; l'utilisateur
 * choisit le survivant (radio, le plus riche est conseillé) et coche ceux à fusionner.
 * Réservé super-admin. Transaction + invariants + rollback.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Super admin uniquement.'); }
@set_time_limit(600);
$pdo = $GLOBALS['pdo'];
$H = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Fusion doublons biens</title><style>
body{font-family:Sora,system-ui,sans-serif;background:#f7f4ef;margin:0;padding:28px;color:#243B5C}
h1{margin:0 0 4px}.sub{color:#7a766f;margin-bottom:18px}
.card{background:#fff;border-radius:12px;padding:16px 20px;margin-bottom:14px;box-shadow:0 3px 10px rgba(0,0,0,.06)}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:6px 9px;border-bottom:1px solid #f0ece6;text-align:left}
th{color:#7a766f;font-size:11px;text-transform:uppercase}
.btn{display:inline-block;padding:9px 16px;border-radius:8px;color:#fff;font-weight:700;background:#2d6a35;border:0;cursor:pointer;font-size:13px}
.tag{font-size:11px;background:#eef2f7;padding:2px 7px;border-radius:6px;color:#3a6890}
.tg-bail{background:#e7f3ea;color:#2d6a35}.tg-ann{background:#fef3e7;color:#8a4c12}.tg-vide{background:#fbeaec;color:#a8323b}
a{color:#4878a6}
</style>';

// Bien "riche" ? (a des données) — sert au score de survivant conseillé.
function bienScore(PDO $pdo, int $id): array {
    $bail = (int)$pdo->query("SELECT EXISTS(SELECT 1 FROM bien_baux WHERE id_bien=$id AND statut='actif')")->fetchColumn();
    $ann  = (int)$pdo->query("SELECT EXISTS(SELECT 1 FROM annonces WHERE id_bien=$id)")->fetchColumn();
    $prix = (int)$pdo->query("SELECT EXISTS(SELECT 1 FROM bien_prix WHERE id_bien=$id)")->fetchColumn();
    return ['bail'=>$bail,'ann'=>$ann,'prix'=>$prix];
}

// Déplace un bien perdant L vers le survivant S (repointe les enfants, supprime L).
function absorbBien(PDO $pdo, int $S, int $L): void {
    $tables = ['annonces','baux','bien_baux','bien_prix','crg_situations_locataires',
               'locataires_statuts','bien_photos','bien_documents','bien_score_commercial',
               'visites','estimations','dossier_vente_bien','bien_prix_historique'];
    foreach ($tables as $t) {
        try { $pdo->prepare("UPDATE `$t` SET id_bien=? WHERE id_bien=?")->execute([$S,$L]); }
        catch (Throwable $e) { /* table/colonne absente → ignore */ }
    }
    $pdo->prepare("DELETE FROM biens WHERE id=?")->execute([$L]);
}

// ── POST : fusion d'une sélection ─────────────────────────────────────
$done = null;
if (($_POST['action'] ?? '') === 'merge_biens') {
    $S = (int)($_POST['survivor'] ?? 0);
    $losers = array_filter(array_map('intval', (array)($_POST['losers'] ?? [])), fn($x)=>$x>0 && $x!==$S);
    if ($S>0 && $losers) {
        $annB  = (int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn();
        $bailB = (int)$pdo->query("SELECT COUNT(*) FROM bien_baux")->fetchColumn();
        $pdo->beginTransaction();
        foreach ($losers as $L) absorbBien($pdo, $S, (int)$L);
        $annA  = (int)$pdo->query("SELECT COUNT(*) FROM annonces")->fetchColumn();
        $bailA = (int)$pdo->query("SELECT COUNT(*) FROM bien_baux")->fetchColumn();
        $orphAnn = (int)$pdo->query("SELECT COUNT(*) FROM annonces a LEFT JOIN biens b ON b.id=a.id_bien WHERE b.id IS NULL")->fetchColumn();
        $err = [];
        if ($annA !== $annB)   $err[] = "annonces $annB → $annA";
        if ($bailA !== $bailB) $err[] = "baux $bailB → $bailA";
        if ($orphAnn > 0)      $err[] = "$orphAnn annonces orphelines";
        if ($err) { $pdo->rollBack(); $done = '<div class="card"><h3 class="tg-vide">❌ ROLLBACK : '.$H(implode(' ; ',$err)).'</h3></div>'; }
        else { $pdo->commit(); $done = '<div class="card"><h3 style="color:#2d6a35">✅ Fusion OK : survivant #'.$S.', '.count($losers).' bien(s) absorbé(s)</h3></div>'; }
    } else {
        $done = '<div class="card"><h3 class="tg-vide">⚠️ Sélection invalide (1 survivant + au moins 1 bien à fusionner).</h3></div>';
    }
}

// ── Détection : immeubles ayant ≥1 coquille (bien sans bail/annonce/prix/surface) ──
$skelCond = "(b.surface_habitable IS NULL OR b.surface_habitable=0)
   AND NOT EXISTS(SELECT 1 FROM bien_baux x WHERE x.id_bien=b.id AND x.statut='actif')
   AND NOT EXISTS(SELECT 1 FROM annonces  x WHERE x.id_bien=b.id)
   AND NOT EXISTS(SELECT 1 FROM bien_prix x WHERE x.id_bien=b.id)";

$immIds = $pdo->query("
   SELECT DISTINCT b.id_immeuble
   FROM biens b
   WHERE b.id_immeuble IS NOT NULL
     AND COALESCE(b.statut_bien,'') NOT IN ('archive','vendu','supprime')
     AND $skelCond
   ORDER BY b.id_immeuble")->fetchAll(PDO::FETCH_COLUMN) ?: [];

echo '<h1>🧹 Fusion doublons de biens</h1><div class="sub">Sélection manuelle — rien ne bouge sans toi. '.count($immIds).' immeuble(s) contenant au moins une coquille.</div>';
if ($done) echo $done;

foreach ($immIds as $immId) {
    $immId = (int)$immId;
    $imm = $pdo->query("SELECT id,nom_immeuble,adresse_1,code_postal,ville FROM immeubles WHERE id=$immId")->fetch(PDO::FETCH_ASSOC);
    if (!$imm) continue;
    $biens = $pdo->query("SELECT id, reference_bien, numero_lot, surface_habitable, type_commercialisation, statut_bien
        FROM biens WHERE id_immeuble=$immId
          AND COALESCE(statut_bien,'') NOT IN ('archive','vendu','supprime')
        ORDER BY numero_lot, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($biens) < 2) continue;

    // score + survivant conseillé
    $best=null; $bestScore=-1;
    foreach ($biens as &$b) {
        $b['_s'] = bienScore($pdo,(int)$b['id']);
        $sc = $b['_s']['bail']*8 + $b['_s']['ann']*4 + $b['_s']['prix']*2 + ((float)$b['surface_habitable']>0?1:0);
        $b['_score']=$sc;
        if ($sc>$bestScore){ $bestScore=$sc; $best=(int)$b['id']; }
    } unset($b);

    $adr = trim(($imm['adresse_1']?:'').' '.($imm['code_postal']?:'').' '.($imm['ville']?:''));
    ?>
    <form method="post" class="card">
      <input type="hidden" name="action" value="merge_biens">
      <div style="margin-bottom:8px">
        <strong style="font-size:15px"><?= $H($imm['nom_immeuble'] ?: ('Immeuble #'.$immId)) ?></strong>
        <span class="tag"><?= $H($adr) ?></span>
        <a href="/immeuble_360.php?id=<?= $immId ?>" target="_blank" rel="noopener">🔗 360°</a>
      </div>
      <table>
        <thead><tr><th>Survivant</th><th>Fusionner</th><th>Réf.</th><th>N° lot</th><th>Surface</th><th>Données</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($biens as $b): $id=(int)$b['id']; $reco=($id===$best); ?>
          <tr>
            <td><label><input type="radio" name="survivor" value="<?= $id ?>" <?= $reco?'checked':'' ?>> <?= $reco?'<span class="tag tg-bail">conseillé</span>':'garder' ?></label></td>
            <td><input type="checkbox" name="losers[]" value="<?= $id ?>"></td>
            <td><?= $H($b['reference_bien'] ?: ('#'.$id)) ?></td>
            <td><?= $H($b['numero_lot'] ?: '—') ?></td>
            <td><?= $b['surface_habitable'] ? (int)$b['surface_habitable'].' m²' : '<span class="tag tg-vide">—</span>' ?></td>
            <td>
              <?= $b['_s']['bail'] ? '<span class="tag tg-bail">🛏 bail</span> ' : '' ?>
              <?= $b['_s']['ann']  ? '<span class="tag tg-ann">📢 annonce</span> ' : '' ?>
              <?= $b['_s']['prix'] ? '<span class="tag">💰 prix</span> ' : '' ?>
              <?= ($b['_score']==0) ? '<span class="tag tg-vide">coquille vide</span>' : '' ?>
            </td>
            <td><a href="/bien_360.php?id=<?= $id ?>" target="_blank" rel="noopener">🔗</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn" onclick="return confirm('Fusionner les biens cochés dans le survivant ?')">▶️ Fusionner la sélection</button>
    </form>
    <?php
}
if (!$immIds) echo '<div class="card">Aucune coquille détectée. 🎉</div>';
