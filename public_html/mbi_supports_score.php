<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_score.php — Page test du moteur de score commercial
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Module : Ma Box Communication (mbi_supports)
 *
 * Usage :
 *   /mbi_supports_score.php?id_bien=1234            → affiche le score actuel
 *   /mbi_supports_score.php?id_bien=1234&calc=1     → recalcule (mode hybride)
 *   /mbi_supports_score.php?id_bien=1234&calc=1&mode=deterministe → mode forcé
 *
 * Page autonome (pas de layout V2 imbriqué) — pensée pour démos négociateur
 * et mandant : visuel clair du score, du breakdown et des recos IA.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mbi_supports_score_engine.php';
require_login();

// ─── Paramètres ───────────────────────────────────────────────────────────
$idBien = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien'])
    ? (int)$_GET['id_bien'] : 0;
$doCalc = isset($_GET['calc']) && $_GET['calc'] === '1';
$mode   = $_GET['mode'] ?? 'hybride';
if (!in_array($mode, ['deterministe','ia','hybride'], true)) $mode = 'hybride';

$result   = null;
$score    = null;
$historique = [];
$bienInfo = null;

// ─── Calcul si demandé ─────────────────────────────────────────────────────
if ($idBien > 0 && $doCalc) {
    $result = mbi_supports_score_calculer($idBien, current_user_id(), $mode);
}

// ─── Lecture du dernier score ──────────────────────────────────────────────
if ($idBien > 0) {
    $score      = mbi_supports_score_get_dernier($idBien);
    $historique = mbi_supports_score_historique($idBien, 10);
    try {
        $st = $pdo->prepare("SELECT id, reference_bien, designation, ville, code_postal, prix_vente, surface_habitable, dpe_classe, ges_classe FROM biens WHERE id = :id LIMIT 1");
        $st->execute([':id' => $idBien]);
        $bienInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        // Colonnes absentes possibles (schéma variable) — on tolère
        $bienInfo = null;
    }
}

// ─── Helpers d'affichage ────────────────────────────────────────────────────
function mbisup_score_color(int $s): string {
    return match(true) {
        $s >= 80 => '#1a8754',  // vert
        $s >= 60 => '#5a8a3f',  // vert clair
        $s >= 40 => '#c97b2e',  // orange
        $s >= 20 => '#a85858',  // rouge clair
        default  => '#7a3030',  // rouge foncé
    };
}
function mbisup_score_label(int $s): string {
    return match(true) {
        $s >= 80 => 'Excellent',
        $s >= 60 => 'Bon',
        $s >= 40 => 'Correct',
        $s >= 20 => 'Faible',
        default  => 'Très faible',
    };
}
function mbisup_h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$breakdown = [];
if ($score && !empty($score['score_breakdown_json'])) {
    $bd = json_decode((string)$score['score_breakdown_json'], true);
    if (is_array($bd)) $breakdown = $bd;
}
$pointsForts   = $score && !empty($score['points_forts_json'])   ? json_decode((string)$score['points_forts_json'],   true) : [];
$pointsFaibles = $score && !empty($score['points_faibles_json']) ? json_decode((string)$score['points_faibles_json'], true) : [];
$snapshot      = $score && !empty($score['data_snapshot_json'])  ? json_decode((string)$score['data_snapshot_json'],  true) : [];
$signaux       = is_array($snapshot['signaux'] ?? null) ? $snapshot['signaux'] : [];
$iaCommentaire = (string)($snapshot['ia_commentaire'] ?? '');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Score commercial — Ma Box Communication</title>
<style>
  :root {
    --navy: #243B5C;
    --or:   #D4A047;
    --bg:   #f7f8fa;
    --text: #1F2937;
    --muted:#6b7280;
    --border:#e5e7eb;
  }
  * { box-sizing: border-box; }
  html,body { margin:0; padding:0; }
  body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
         background:var(--bg); color:var(--text); line-height:1.5; }
  .wrap { max-width:1100px; margin:0 auto; padding:32px 24px 80px; }
  h1 { font-size:24px; margin:0 0 4px; color:var(--navy); }
  .subtitle { color:var(--muted); font-size:14px; margin-bottom:28px; }

  .topbar { display:flex; justify-content:space-between; align-items:center;
            margin-bottom:24px; gap:16px; flex-wrap:wrap; }
  .back-link { color:var(--navy); text-decoration:none; font-size:14px; }
  .back-link:hover { text-decoration:underline; }

  .form-bar { display:flex; align-items:end; gap:12px; flex-wrap:wrap;
              background:#fff; padding:16px; border-radius:12px;
              box-shadow:0 1px 3px rgba(0,0,0,0.05); }
  .form-bar label { display:flex; flex-direction:column; font-size:12px;
                    color:var(--muted); font-weight:600; gap:4px; }
  .form-bar input, .form-bar select { padding:8px 10px; border:1px solid var(--border);
                                       border-radius:8px; font-size:14px; min-width:140px; }
  .btn { padding:10px 18px; border-radius:8px; border:none; cursor:pointer;
         font-weight:600; font-size:14px; }
  .btn-primary { background:var(--navy); color:#fff; }
  .btn-primary:hover { background:#1a2f4a; }
  .btn-secondary { background:#fff; color:var(--navy); border:1px solid var(--navy); }
  .btn-secondary:hover { background:var(--navy); color:#fff; }

  .grid { display:grid; grid-template-columns: 320px 1fr; gap:24px; margin-top:28px; }
  @media (max-width:900px) { .grid { grid-template-columns:1fr; } }

  .card { background:#fff; border-radius:14px; padding:24px;
          box-shadow:0 1px 3px rgba(0,0,0,0.05); }
  .card h2 { margin:0 0 16px; font-size:16px; color:var(--navy);
             text-transform:uppercase; letter-spacing:0.04em; }

  .gauge { display:flex; flex-direction:column; align-items:center; gap:8px; padding:8px 0; }
  .gauge .num { font-size:64px; font-weight:700; line-height:1; }
  .gauge .max { color:var(--muted); font-size:18px; margin-left:4px; font-weight:400; }
  .gauge .label { font-size:14px; padding:6px 14px; border-radius:999px;
                  color:#fff; font-weight:600; }
  .gauge .meta { font-size:12px; color:var(--muted); text-align:center; margin-top:8px; }

  .bloc { display:flex; align-items:center; gap:12px; padding:10px 0;
          border-bottom:1px solid var(--border); }
  .bloc:last-child { border-bottom:none; }
  .bloc .name { width:140px; font-weight:600; font-size:14px; }
  .bloc .barwrap { flex:1; height:10px; background:#eee; border-radius:5px; overflow:hidden; }
  .bloc .bar { height:100%; background:var(--navy); border-radius:5px; transition:width 0.4s; }
  .bloc .pts { width:80px; text-align:right; font-size:13px; color:var(--muted); }
  .bloc .pts strong { color:var(--text); }

  .pf-list, .pfa-list, .sig-list { margin:0; padding:0; list-style:none; }
  .pf-list li, .pfa-list li, .sig-list li {
      padding:8px 12px; border-radius:8px; margin-bottom:6px; font-size:14px;
  }
  .pf-list li { background:#e8f4ec; color:#1a5e36; }
  .pfa-list li { background:#fdecec; color:#7a2828; }
  .sig-list li { background:#fff7e8; color:#8a6422; font-size:12px;
                 font-family:"Menlo",monospace; }

  .badges { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
  .badge { padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600;
           background:#eef1f5; color:var(--navy); }
  .badge.urg-fort { background:#fdecec; color:#7a2828; }
  .badge.urg-moyen { background:#fff7e8; color:#8a6422; }
  .badge.urg-faible { background:#e8f4ec; color:#1a5e36; }

  .commentaire { background:#f0f4f9; border-left:3px solid var(--navy);
                 padding:12px 16px; border-radius:8px; font-style:italic;
                 color:var(--muted); margin-top:12px; }

  table.histo { width:100%; border-collapse:collapse; font-size:13px; }
  table.histo th, table.histo td { padding:8px 10px; text-align:left;
                                    border-bottom:1px solid var(--border); }
  table.histo th { color:var(--muted); font-weight:600; font-size:11px;
                   text-transform:uppercase; letter-spacing:0.06em; }
  table.histo .err { color:#7a2828; font-style:italic; }

  .empty { color:var(--muted); font-style:italic; padding:20px 0; text-align:center; }
  .alert-success { background:#e8f4ec; color:#1a5e36; padding:12px 16px;
                   border-radius:8px; margin-bottom:16px; font-size:14px; }
  .alert-error { background:#fdecec; color:#7a2828; padding:12px 16px;
                 border-radius:8px; margin-bottom:16px; font-size:14px; }
  .meta-row { display:flex; gap:12px; flex-wrap:wrap; font-size:12px;
              color:var(--muted); margin-top:8px; }
  .meta-row span { padding:2px 8px; background:#f0f4f9; border-radius:6px; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>Score de commercialisation</h1>
      <div class="subtitle">Ma Box Communication — moteur hybride (déterministe + IA)</div>
    </div>
    <a class="back-link" href="<?=$idBien>0?'/bien_detail.php?edit='.$idBien.'&section=descriptif':'/bien_liste.php'?>">← Retour</a>
  </div>

  <form class="form-bar" method="get" action="">
    <label>id_bien
      <input type="number" name="id_bien" value="<?=mbisup_h($idBien?:'')?>" placeholder="ex: 1234" required>
    </label>
    <label>Mode
      <select name="mode">
        <option value="hybride"      <?=$mode==='hybride'?'selected':''?>>Hybride (déterministe + IA)</option>
        <option value="deterministe" <?=$mode==='deterministe'?'selected':''?>>Déterministe seul</option>
        <option value="ia"           <?=$mode==='ia'?'selected':''?>>IA seule (déterministe quand même calculé)</option>
      </select>
    </label>
    <button class="btn btn-secondary" type="submit">Voir score actuel</button>
    <button class="btn btn-primary"   type="submit" name="calc" value="1">Calculer / Recalculer</button>
  </form>

  <?php if ($result): ?>
    <?php if ($result['ok']): ?>
      <div class="alert-success" style="margin-top:16px;">
        ✓ Score recalculé (id <?=mbisup_h($result['score_id'])?>) — score : <strong><?=mbisup_h($result['score'])?>/100</strong>
        <?php if ($result['erreur']): ?>
          <br><small>Note : IA a échoué (<?=mbisup_h($result['erreur'])?>) — score déterministe seul.</small>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="alert-error" style="margin-top:16px;">
        ✗ Erreur : <?=mbisup_h($result['erreur'])?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($idBien <= 0): ?>
    <div class="card" style="margin-top:24px;">
      <p class="empty">Saisis un <code>id_bien</code> ci-dessus pour évaluer un bien.</p>
    </div>
  <?php elseif (!$score): ?>
    <div class="card" style="margin-top:24px;">
      <p class="empty">
        <?= $bienInfo
            ? 'Bien #'.mbisup_h($idBien).' trouvé. Aucun score calculé pour l\'instant.'
            : 'Bien #'.mbisup_h($idBien).' introuvable ou hors de votre périmètre.' ?>
      </p>
      <?php if ($bienInfo): ?>
        <p style="text-align:center;margin-top:12px;">
          <button class="btn btn-primary" onclick="window.location.href='?id_bien=<?=$idBien?>&calc=1&mode=<?=$mode?>'">
            Lancer la première évaluation
          </button>
        </p>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <div class="grid">
      <!-- Colonne gauche : score + métadonnées -->
      <div class="card">
        <h2>Score</h2>
        <?php $sNum = (int)($score['score'] ?? 0); $color = mbisup_score_color($sNum); ?>
        <div class="gauge">
          <div class="num" style="color:<?=$color?>">
            <?=mbisup_h($sNum)?><span class="max">/100</span>
          </div>
          <div class="label" style="background:<?=$color?>"><?=mbisup_h(mbisup_score_label($sNum))?></div>
          <div class="meta">
            Calculé le <?=mbisup_h(date('d/m/Y H:i', strtotime((string)$score['date_calcul'])))?>
          </div>
        </div>

        <div class="badges">
          <?php
            $angle = (string)($score['angle_recommande'] ?? '');
            $urg   = (string)($score['niveau_urgence'] ?? '');
            $mode  = (string)($score['calcul_mode'] ?? '');
            $conf  = $score['confidence_score'] ?? null;
          ?>
          <?php if ($angle !== ''): ?><span class="badge">Angle : <?=mbisup_h($angle)?></span><?php endif; ?>
          <?php if ($urg !== ''):   ?><span class="badge urg-<?=mbisup_h($urg)?>">Urgence : <?=mbisup_h($urg)?></span><?php endif; ?>
          <?php if ($mode !== ''):  ?><span class="badge"><?=mbisup_h($mode)?></span><?php endif; ?>
          <?php if ($conf !== null):?><span class="badge">Confiance IA : <?=mbisup_h($conf)?>%</span><?php endif; ?>
        </div>

        <?php if ($iaCommentaire !== ''): ?>
          <div class="commentaire">« <?=mbisup_h($iaCommentaire)?> »</div>
        <?php endif; ?>

        <div class="meta-row">
          <?php if (!empty($bienInfo['reference_bien'])): ?>
            <span><strong>Réf :</strong> <?=mbisup_h($bienInfo['reference_bien'])?></span>
          <?php endif; ?>
          <?php if (!empty($bienInfo['ville'])): ?>
            <span><?=mbisup_h($bienInfo['ville'])?></span>
          <?php endif; ?>
          <?php if (!empty($bienInfo['surface_habitable'])): ?>
            <span><?=mbisup_h($bienInfo['surface_habitable'])?> m²</span>
          <?php endif; ?>
          <?php if (!empty($bienInfo['prix_vente'])): ?>
            <span><?=mbisup_h(number_format((float)$bienInfo['prix_vente'], 0, ',', ' '))?> €</span>
          <?php endif; ?>
        </div>

        <?php if ((int)($score['cout_ia_centimes'] ?? 0) > 0): ?>
          <div class="meta-row">
            <span>IA : <?=mbisup_h($score['modele_ia'] ?? '')?> · <?=number_format(((int)$score['cout_ia_centimes'])/100, 2, ',', ' ')?> €</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- Colonne droite : breakdown + IA -->
      <div>
        <div class="card" style="margin-bottom:16px;">
          <h2>Détail par bloc</h2>
          <?php if (empty($breakdown)): ?>
            <p class="empty">Pas de breakdown disponible.</p>
          <?php else: ?>
            <?php foreach ($breakdown as $key => $b): ?>
              <?php
                $max = (int)($b['max'] ?? 0);
                $pts = (float)($b['points'] ?? 0);
                $pct = $max > 0 ? min(100, ($pts / $max) * 100) : 0;
              ?>
              <div class="bloc">
                <div class="name"><?=mbisup_h($b['libelle'] ?? $key)?></div>
                <div class="barwrap"><div class="bar" style="width:<?=$pct?>%"></div></div>
                <div class="pts"><strong><?=mbisup_h(rtrim(rtrim(number_format($pts,1),'0'),'.'))?></strong>/<?=$max?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($pointsForts) || !empty($pointsFaibles)): ?>
          <div class="card" style="margin-bottom:16px;">
            <h2>Lecture IA</h2>
            <?php if (!empty($pointsForts)): ?>
              <h3 style="font-size:13px;color:#1a5e36;margin:8px 0;">Points forts</h3>
              <ul class="pf-list">
                <?php foreach ($pointsForts as $pf): ?>
                  <li>+ <?=mbisup_h((string)$pf)?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if (!empty($pointsFaibles)): ?>
              <h3 style="font-size:13px;color:#7a2828;margin:16px 0 8px;">Points faibles</h3>
              <ul class="pfa-list">
                <?php foreach ($pointsFaibles as $pfa): ?>
                  <li>– <?=mbisup_h((string)$pfa)?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($signaux)): ?>
          <div class="card" style="margin-bottom:16px;">
            <h2>Signaux détectés</h2>
            <ul class="sig-list">
              <?php foreach ($signaux as $s): ?>
                <li><?=mbisup_h((string)$s)?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($historique)): ?>
      <div class="card" style="margin-top:24px;">
        <h2>Historique</h2>
        <table class="histo">
          <thead>
            <tr>
              <th>Date</th>
              <th>Score</th>
              <th>Statut</th>
              <th>Mode</th>
              <th>Angle</th>
              <th>Urgence</th>
              <th>Conf.</th>
              <th>Coût IA</th>
              <th>Erreur</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historique as $h): ?>
              <tr<?=($h['statut']==='erreur')?' class="err"':''?>>
                <td><?=mbisup_h(date('d/m/Y H:i', strtotime((string)$h['date_calcul'])))?></td>
                <td><strong><?=mbisup_h($h['score'] ?? '—')?></strong></td>
                <td><?=mbisup_h($h['statut'])?></td>
                <td><?=mbisup_h($h['calcul_mode'])?></td>
                <td><?=mbisup_h($h['angle_recommande'] ?? '—')?></td>
                <td><?=mbisup_h($h['niveau_urgence'] ?? '—')?></td>
                <td><?=mbisup_h($h['confidence_score'] ?? '—')?></td>
                <td><?=$h['cout_ia_centimes']>0?number_format((int)$h['cout_ia_centimes']/100,2,',',' ').' €':'—'?></td>
                <td><?=mbisup_h(mb_substr((string)($h['derniere_erreur'] ?? ''),0,60))?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>
</body>
</html>
