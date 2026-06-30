<?php
/**
 * admin/admin_dedup_refs_merge.php — Fusion des biens en DOUBLE de reference_bien.
 *
 * Plusieurs enregistrements `biens` partagent la même `reference_bien` (import CRG joué
 * 2×). Pour chaque groupe : on GARDE le plus riche (gardien), on fusionne les autres dedans,
 * puis soft-delete des sources. Transfère baux, CRG, prix, annonces, statuts locataires,
 * dossier_vente, photos, tiers_roles(bien), GED, mandats réels (AUTO-* supprimés).
 *
 * GET               : APERÇU (lecture seule).
 * POST action=run   : exécution (transaction par source). Réservé super-admin (role 1).
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) { http_response_code(403); exit('Réservé super-admin (role 1).'); }

/** Score de richesse d'un bien (pour choisir le gardien). */
function bien_score(PDO $pdo, int $id): int {
    $q = function(string $sql) use ($pdo, $id): int {
        try { $st=$pdo->prepare($sql); $st->execute([$id]); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    return 3*$q("SELECT COUNT(*) FROM bien_baux WHERE id_bien=?")
         + 3*$q("SELECT COUNT(*) FROM crg_situations_locataires WHERE id_bien=?")
         + 2*$q("SELECT COUNT(*) FROM bien_prix WHERE id_bien=?")
         + 2*$q("SELECT COUNT(*) FROM annonces WHERE id_bien=?")
         + 2*$q("SELECT COUNT(*) FROM dossier_vente WHERE id_bien=?")
         + 1*$q("SELECT COUNT(*) FROM biens_photos WHERE id_bien=?")
         + 1*$q("SELECT COUNT(*) FROM mandats WHERE id_bien=? AND numero_mandat NOT LIKE 'AUTO-%'");
}

/** Groupes de biens partageant la même reference_bien (actifs, >1). */
function ref_groups(PDO $pdo): array {
    $refs = $pdo->query("
        SELECT reference_bien
          FROM biens
         WHERE reference_bien IS NOT NULL AND reference_bien <> ''
           AND statut_bien <> 'supprime'
         GROUP BY reference_bien
        HAVING COUNT(*) > 1
         ORDER BY reference_bien")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $groups = [];
    $stB = $pdo->prepare("SELECT id, reference_bien, statut_bien,
                                 COALESCE(NULLIF(adresse_1,''),'') AS adresse_1, ville
                            FROM biens
                           WHERE reference_bien = ? AND statut_bien <> 'supprime'
                           ORDER BY id");
    foreach ($refs as $ref) {
        $stB->execute([$ref]);
        $biens = $stB->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($biens) < 2) continue;
        foreach ($biens as &$b) { $b['score'] = bien_score($pdo, (int)$b['id']); }
        unset($b);
        // gardien = score max, puis id le plus petit.
        usort($biens, fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
        $sources = array_slice($biens, 1);
        // SÛR = exactement 2 biens ET toutes les sources strictement vides (score 0).
        //        → on ne perd aucune donnée, le gardien garde tout.
        // AMBIGU = plus de 2 biens (réf partagée par des biens distincts), ou une source a des données.
        $safe = (count($biens) === 2)
              && array_reduce($sources, fn($c,$s) => $c && (int)$s['score'] === 0, true);
        $groups[] = ['ref' => $ref, 'keeper' => $biens[0], 'sources' => $sources, 'safe' => $safe];
    }
    return $groups;
}

/** Fusionne un bien source dans le gardien (full). Transaction. */
function merge_bien_full(PDO $pdo, int $src, int $dst): array {
    $stats = ['src'=>$src, 'dst'=>$dst];
    if ($src<=0 || $dst<=0 || $src===$dst) return $stats+['error'=>'ids invalides'];
    try {
        $pdo->beginTransaction();
        $move = function(string $table) use ($pdo,$src,$dst,&$stats) {
            try { $st=$pdo->prepare("UPDATE `$table` SET id_bien=? WHERE id_bien=?"); $st->execute([$dst,$src]); $stats[$table]=$st->rowCount(); }
            catch (Throwable $e) {
                // tentative IGNORE si contrainte unique, puis purge des restes
                try { $st=$pdo->prepare("UPDATE IGNORE `$table` SET id_bien=? WHERE id_bien=?"); $st->execute([$dst,$src]);
                      $pdo->prepare("DELETE FROM `$table` WHERE id_bien=?")->execute([$src]); $stats[$table]='ignore+purge'; }
                catch (Throwable $e2) { $stats[$table]='skip'; }
            }
        };
        $move('bien_baux'); $move('crg_situations_locataires'); $move('bien_prix');
        $move('annonces');  $move('locataires_statuts');        $move('biens_photos');

        // dossier_vente (UNIQUE id_bien)
        $dstHasDoss = (int)$pdo->query('SELECT COUNT(*) FROM dossier_vente WHERE id_bien='.$dst)->fetchColumn();
        if ($dstHasDoss === 0) { $st=$pdo->prepare('UPDATE dossier_vente SET id_bien=? WHERE id_bien=?'); $st->execute([$dst,$src]); $stats['dossier_vente']=$st->rowCount(); }
        else { $stats['dossier_vente'] = 'gardien a déjà un dossier (source conservée sur bien masqué)'; }

        // tiers_roles (objet bien)
        try { $st=$pdo->prepare("UPDATE IGNORE tiers_roles SET id_objet=? WHERE objet_type='bien' AND id_objet=?"); $st->execute([$dst,$src]); $stats['tiers_roles']=$st->rowCount();
              $pdo->prepare("DELETE FROM tiers_roles WHERE objet_type='bien' AND id_objet=?")->execute([$src]); } catch (Throwable $e) { $stats['tiers_roles']='skip'; }

        // GED
        try { if ((bool)$pdo->query("SHOW COLUMNS FROM ged_documents LIKE 'id_bien'")->fetchColumn()) {
                  $st=$pdo->prepare('UPDATE ged_documents SET id_bien=? WHERE id_bien=?'); $st->execute([$dst,$src]); $stats['ged']=$st->rowCount(); }
              $pdo->prepare("UPDATE ged_documents SET metadata=JSON_SET(metadata,'$.classement.bien_id_bdd',?) WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.classement.bien_id_bdd'))=?")->execute([$dst,$src]); }
        catch (Throwable $e) { $stats['ged']='skip'; }
        try { $st=$pdo->prepare('UPDATE biens_documents SET id_bien=? WHERE id_bien=?'); $st->execute([$dst,$src]); $stats['biens_documents']=$st->rowCount(); } catch (Throwable $e) {}

        // mandats : déplacer les réels, supprimer les AUTO-*
        try { $st=$pdo->prepare("UPDATE mandats SET id_bien=? WHERE id_bien=? AND numero_mandat NOT LIKE 'AUTO-%'"); $st->execute([$dst,$src]); $stats['mandats_reels']=$st->rowCount();
              $st=$pdo->prepare("DELETE FROM mandats WHERE id_bien=? AND numero_mandat LIKE 'AUTO-%'"); $st->execute([$src]); $stats['mandats_auto_suppr']=$st->rowCount(); } catch (Throwable $e) { $stats['mandats']='skip'; }

        // copie défensive des champs vides du gardien depuis la source
        try {
            $dstRow=$pdo->query("SELECT * FROM biens WHERE id=$dst")->fetch(PDO::FETCH_ASSOC);
            $srcRow=$pdo->query("SELECT * FROM biens WHERE id=$src")->fetch(PDO::FETCH_ASSOC);
            $cols=['adresse_1','code_postal','ville','latitude','longitude','google_place_id','surface_habitable',
                   'surface_totale','nb_pieces','etage','numero_lot','designation','description','usage_bien',
                   'dpe_classe','ges_classe','annee_construction','loyer_hc','charges_locatives','id_immeuble','code_crg'];
            $upd=[]; $par=[':id'=>$dst];
            foreach ($cols as $c) { if (array_key_exists($c,$dstRow) && empty($dstRow[$c]) && !empty($srcRow[$c])) { $upd[]="`$c`=:$c"; $par[":$c"]=$srcRow[$c]; } }
            if ($upd) { $st=$pdo->prepare('UPDATE biens SET '.implode(',',$upd).', date_modification=NOW() WHERE id=:id'); foreach($par as $k=>$v)$st->bindValue($k,$v); $st->execute(); $stats['champs_copies']=count($upd); }
        } catch (Throwable $e) {}

        $note='['.date('Y-m-d H:i')."] Doublon reference_bien fusionné → #$dst.";
        $pdo->prepare("UPDATE biens SET commentaire=CONCAT(COALESCE(commentaire,''),'\n',?) WHERE id=?")->execute([$note,$dst]);
        $pdo->prepare("UPDATE biens SET statut_bien='supprime', designation=CONCAT('[FUSIONNÉ→#$dst] ',COALESCE(designation,'')), date_modification=NOW() WHERE id=?")->execute([$src]);

        $pdo->commit(); $stats['ok']=true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $stats['error']=$e->getMessage(); }
    return $stats;
}

// ── EXÉCUTION ──
$report = null;
if (($_POST['action'] ?? '') === 'run') {
    verify_csrf('dedup_refs');
    $mode = (string)($_POST['mode'] ?? 'safe');   // 'safe' = sources vides ; 'ambig' = le reste
    $groups = array_values(array_filter(ref_groups($pdo),
        fn($g) => $mode === 'ambig' ? !$g['safe'] : $g['safe']));
    $report = ['groupes'=>count($groups), 'fusions'=>0, 'ok'=>0, 'err'=>0, 'lignes'=>[]];
    foreach ($groups as $g) {
        $dst = (int)$g['keeper']['id'];
        foreach ($g['sources'] as $s) {
            $r = merge_bien_full($pdo, (int)$s['id'], $dst);
            $r['ref'] = $g['ref']; $report['fusions']++;
            if (!empty($r['ok'])) $report['ok']++; else $report['err']++;
            $report['lignes'][] = $r;
        }
    }
}

$allGroups = $report ? [] : ref_groups($pdo);
$groups = array_values(array_filter($allGroups, fn($g) => $g['safe']));      // fusionnables
$ambig  = array_values(array_filter($allGroups, fn($g) => !$g['safe']));     // à revoir
$nbSources = 0; foreach ($groups as $g) $nbSources += count($g['sources']);
$nbAmbigBiens = 0; foreach ($ambig as $g) $nbAmbigBiens += 1 + count($g['sources']);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Fusion références en double — Admin</title>
<style>
body{font-family:system-ui,Segoe UI,sans-serif;padding:26px;background:#f7f4ef;max-width:1150px;margin:0 auto;color:#1f2937;}
h1{font-size:21px;} .muted{color:#6b7280;font-size:13px;}
.kpi{display:inline-block;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 20px;margin:8px 12px 8px 0;}
.kpi b{font-size:26px;display:block;color:#b45309;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;font-size:12.5px;margin-top:14px;}
th,td{padding:7px 10px;border-bottom:1px solid #f0ece6;text-align:left;}
th{background:#f3f4f6;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;}
.ref{font-family:monospace;font-weight:700;color:#4338ca;}
.keep{color:#0b6b35;font-weight:800;}
.warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin:14px 0;}
.ok{background:#ecfdf5;border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;margin:14px 0;}
.btn{background:#b91c1c;color:#fff;border:none;border-radius:9px;padding:12px 22px;font-weight:800;font-size:14px;cursor:pointer;}
</style></head><body>

<h1>🔗 Fusion des biens en double de référence</h1>

<?php if ($report): ?>
  <div class="ok"><strong>Fusion terminée.</strong> <?= (int)$report['groupes'] ?> groupes · <?= (int)$report['fusions'] ?> fusions · <?= (int)$report['ok'] ?> OK · <?= (int)$report['err'] ?> erreur(s).</div>
  <table><thead><tr><th>réf</th><th>source</th><th>→ gardien</th><th>baux</th><th>crg</th><th>prix</th><th>dossier_vente</th><th>résultat</th></tr></thead><tbody>
  <?php foreach ($report['lignes'] as $r): ?>
    <tr><td class="ref"><?= htmlspecialchars((string)$r['ref']) ?></td>
      <td>#<?= (int)$r['src'] ?></td><td>#<?= (int)$r['dst'] ?></td>
      <td><?= htmlspecialchars((string)($r['bien_baux'] ?? '0')) ?></td>
      <td><?= htmlspecialchars((string)($r['crg_situations_locataires'] ?? '0')) ?></td>
      <td><?= htmlspecialchars((string)($r['bien_prix'] ?? '0')) ?></td>
      <td><?= htmlspecialchars((string)($r['dossier_vente'] ?? '0')) ?></td>
      <td><?= !empty($r['ok'])?'✅':('❌ '.htmlspecialchars((string)($r['error'] ?? ''))) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <p><a href="?">↻ Recharger l'aperçu (doit afficher 0 groupe restant)</a></p>

<?php elseif (!$groups): ?>
  <div class="ok">✅ Aucune référence en double. Tous les biens ont une référence unique.</div>

<?php else: ?>
  <div>
    <div class="kpi">Fusionnables EN SÉCURITÉ<b><?= $nbSources ?></b><span class="muted"><?= count($groups) ?> réf · source vide, 1 seul jumeau</span></div>
    <div class="kpi">À REVOIR à la main<b><?= $nbAmbigBiens ?></b><span class="muted"><?= count($ambig) ?> réf · &gt;2 biens ou données des 2 côtés</span></div>
  </div>
  <div class="warn"><strong>Aperçu — rien n'est modifié.</strong> Le bouton ci-dessous ne traite QUE les
    <span class="keep"><?= $nbSources ?> cas sûrs</span> : exactement 2 biens, dont la source est <strong>strictement vide</strong>
    (aucune donnée perdue). Les <?= count($ambig) ?> cas ambigus ne sont <strong>pas</strong> touchés (voir 2ᵉ tableau).</div>
  <?php if ($nbSources > 0): ?>
  <form method="post" onsubmit="return confirm('Fusionner <?= $nbSources ?> doublons SÛRS (source vide) ? Action sur la prod.');">
    <?= csrf_field('dedup_refs') ?><input type="hidden" name="action" value="run">
    <button class="btn" type="submit">🔗 Fusionner les <?= $nbSources ?> doublons SÛRS</button>
  </form>
  <?php endif; ?>

  <h3 style="margin-top:22px;">✅ Fusionnables en sécurité (<?= count($groups) ?>)</h3>
  <table><thead><tr><th>référence</th><th>biens (score)</th><th>gardien gardé</th><th>adresse</th></tr></thead><tbody>
  <?php foreach ($groups as $g): ?>
    <tr>
      <td class="ref"><?= htmlspecialchars((string)$g['ref']) ?></td>
      <td><?php foreach (array_merge([$g['keeper']],$g['sources']) as $b): ?>
            <span class="<?= (int)$b['id']===(int)$g['keeper']['id']?'keep':'' ?>">#<?= (int)$b['id'] ?> (<?= (int)$b['score'] ?><?= (string)$b['statut_bien']!=='actif'?' · '.htmlspecialchars((string)$b['statut_bien']):'' ?>)</span>&nbsp;
          <?php endforeach; ?></td>
      <td class="keep">#<?= (int)$g['keeper']['id'] ?></td>
      <td><?= htmlspecialchars(trim((string)$g['keeper']['adresse_1'].' '.(string)$g['keeper']['ville'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>

  <?php if ($ambig): ?>
  <h3 style="margin-top:22px;color:#b91c1c;">⚠️ Cas à revoir (<?= count($ambig) ?>)</h3>
  <p class="muted">Réf. partagée par &gt;2 biens, ou plusieurs biens avec des données. Après vérification (mêmes adresses = mêmes lots), tu peux les fusionner aussi : le bien le plus riche est gardé, les autres fusionnés dedans (données transférées, conflit de dossier de vente géré).</p>
  <form method="post" onsubmit="return confirm('Fusionner ces <?= $nbAmbigBiens - count($ambig) ?> doublons restants dans leur gardien (données transférées) ? Action sur la prod.');">
    <?= csrf_field('dedup_refs') ?><input type="hidden" name="action" value="run"><input type="hidden" name="mode" value="ambig">
    <button class="btn" type="submit" style="background:#92400e;">🔗 Fusionner aussi ces <?= count($ambig) ?> cas (transfert de données)</button>
  </form>
  <div style="height:10px;"></div>
  <table><thead><tr><th>référence</th><th>biens (score)</th><th>adresse</th></tr></thead><tbody>
  <?php foreach ($ambig as $g): ?>
    <tr>
      <td class="ref"><?= htmlspecialchars((string)$g['ref']) ?></td>
      <td><?php foreach (array_merge([$g['keeper']],$g['sources']) as $b): ?>
            <span>#<?= (int)$b['id'] ?> (<?= (int)$b['score'] ?><?= (string)$b['statut_bien']!=='actif'?' · '.htmlspecialchars((string)$b['statut_bien']):'' ?>)</span>&nbsp;
          <?php endforeach; ?></td>
      <td><?= htmlspecialchars(trim((string)$g['keeper']['adresse_1'].' '.(string)$g['keeper']['ville'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
<?php endif; ?>

</body></html>
