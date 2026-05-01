<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_pdf.php — Page test du générateur PDF
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Usage :
 *   /mbi_supports_pdf.php?id_bien=893
 *   /mbi_supports_pdf.php?id_bien=893&type=affiche_vitrine
 *   /mbi_supports_pdf.php?id_bien=893&type=affiche_vitrine&action=generer
 *   /mbi_supports_pdf.php?id_bien=893&type=affiche_vitrine&action=generer&force=1
 *
 * Si action=generer :
 *   1. Lance le critique
 *   2. Si bloc dur → affiche les manquants + bouton "Forcer"
 *   3. Si OK → génère PDF, persiste dans mbi_supports_commerciaux,
 *      affiche lien de téléchargement
 *
 * Affiche aussi l'historique des supports générés sur ce bien.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mbi_supports_pdf_generator.php';
require_login();

$idBien = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien'])
    ? (int)$_GET['id_bien'] : 0;
$type   = (string)($_GET['type']   ?? 'affiche_vitrine');
$action = (string)($_GET['action'] ?? '');
$force  = isset($_GET['force']) && $_GET['force'] === '1';
$angle  = (string)($_GET['angle']  ?? '');
$brief  = trim((string)($_POST['orientation_user'] ?? $_GET['orientation_user'] ?? ''));

$typesValides = ['affiche_vitrine','fiche_client','fiche_visite_interne','dossier_presentation'];
if (!in_array($type, $typesValides, true)) $type = 'affiche_vitrine';

$result = null;
if ($idBien > 0 && $action === 'generer') {
    $opts = [];
    if ($force) $opts['force_export'] = true;
    if ($angle !== '') $opts['angle_marketing'] = $angle;
    $result = mbi_supports_pdf_generer($idBien, $type, $brief !== '' ? $brief : null, $opts);
}

// Historique des supports sur ce bien
$historique = [];
if ($idBien > 0) {
    try {
        $st = $pdo->prepare("
            SELECT id, type_support, version, statut, angle_marketing, fichier_pdf_path,
                   nom_fichier, is_interne, date_generation, mentions_version
            FROM mbi_supports_commerciaux
            WHERE id_bien = :b AND deleted_at IS NULL
            ORDER BY date_generation DESC, id DESC
            LIMIT 30
        ");
        $st->execute([':b' => $idBien]);
        $historique = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { $historique = []; }
}

function mbisupp_h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Générateur PDF — Ma Box Communication</title>
<style>
  :root {
    --navy: #243B5C;
    --or:   #D4A047;
    --bg:   #f7f8fa;
    --text: #1F2937;
    --muted:#6b7280;
    --border:#e5e7eb;
    --rouge:#7a2828;
    --vert: #1a5e36;
    --orange:#8a6422;
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
  .form-bar input, .form-bar select, .form-bar textarea {
      padding:8px 10px; border:1px solid var(--border);
      border-radius:8px; font-size:14px; min-width:160px;
      font-family:inherit; }
  .form-bar textarea { min-height:60px; min-width:260px; }
  .btn { padding:10px 18px; border-radius:8px; border:none; cursor:pointer;
         font-weight:600; font-size:14px; }
  .btn-primary { background:var(--navy); color:#fff; }
  .btn-primary:hover { background:#1a2f4a; }
  .btn-warn { background:#a85858; color:#fff; }
  .card { background:#fff; border-radius:14px; padding:24px;
          box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-top:16px; }
  .card h2 { margin:0 0 14px; font-size:14px; color:var(--navy);
             text-transform:uppercase; letter-spacing:0.06em; }
  .verdict { padding:24px; border-radius:14px; margin:24px 0;
             font-weight:600; display:flex; align-items:center; gap:14px; }
  .verdict.ok { background:#e8f4ec; color:var(--vert); border:1px solid #b8dcc4; }
  .verdict.ko { background:#fdecec; color:var(--rouge); border:1px solid #ecb5b5; }
  .verdict .ico { font-size:32px; line-height:1; }
  .verdict .txt-main { font-size:18px; }
  .verdict .txt-sub  { font-size:13px; font-weight:400; opacity:0.85; }
  .item-list { list-style:none; padding:0; margin:0; }
  .item-list li { padding:10px 14px; border-radius:10px; margin-bottom:6px; font-size:13px; }
  .item-bd { background:#fdecec; color:var(--rouge); }
  .badges { display:flex; gap:8px; flex-wrap:wrap; }
  .badge { padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600;
           background:#eef1f5; color:var(--navy); }
  .badge.interne { background:#fdecec; color:var(--rouge); }
  table.histo { width:100%; border-collapse:collapse; font-size:13px; }
  table.histo th, table.histo td { padding:8px 10px; text-align:left;
                                    border-bottom:1px solid var(--border); }
  table.histo th { color:var(--muted); font-weight:600; font-size:11px;
                   text-transform:uppercase; letter-spacing:0.06em; }
  .empty { color:var(--muted); font-style:italic; padding:8px 0; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>Générateur PDF</h1>
      <div class="subtitle">Ma Box Communication — affiche vitrine · fiche client · fiche visite interne</div>
    </div>
    <a class="back-link" href="<?=$idBien>0?'/bien_detail.php?edit='.$idBien.'&section=descriptif':'/bien_liste.php'?>">← Retour</a>
  </div>

  <form class="form-bar" method="post" action="?id_bien=<?=$idBien?>&type=<?=mbisupp_h($type)?>&action=generer<?=$force?'&force=1':''?>">
    <label>id_bien
      <input type="number" name="id_bien" value="<?=mbisupp_h($idBien?:'')?>" placeholder="ex: 893" form="form-nav">
    </label>
    <label>Type de support
      <select name="type" form="form-nav">
        <?php foreach ($typesValides as $tv): ?>
          <option value="<?=$tv?>" <?=$type===$tv?'selected':''?>><?=$tv?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Angle marketing
      <select name="angle" form="form-nav">
        <option value="">— auto (suggéré par IA) —</option>
        <option value="famille">famille</option>
        <option value="investisseur">investisseur</option>
        <option value="premium">premium</option>
        <option value="premier_achat">premier achat</option>
        <option value="generique">générique</option>
      </select>
    </label>
    <label>Brief libre (optionnel)
      <textarea name="orientation_user" placeholder="Insister sur le jardin, mentionner travaux récents..."><?=mbisupp_h($brief)?></textarea>
    </label>
    <button class="btn btn-primary" type="submit">Générer le PDF</button>
  </form>
  <form id="form-nav" method="get" style="display:none;"></form>

  <?php if ($idBien <= 0): ?>
    <div class="card">
      <p class="empty">Saisis un <code>id_bien</code> + un type de support pour générer un PDF de test.</p>
    </div>

  <?php elseif ($result === null): ?>
    <div class="card">
      <p class="empty">Clique sur "Générer le PDF" pour produire un support pour le bien #<?=$idBien?>.</p>
    </div>

  <?php elseif (!$result['ok']): ?>
    <?php if (($result['statut'] ?? '') === 'refuse'): ?>
      <div class="verdict ko">
        <div class="ico">✗</div>
        <div>
          <div class="txt-main">Génération refusée par l'assistant critique</div>
          <div class="txt-sub">Mentions légales obligatoires manquantes — corrige le bien puis relance, ou force la génération (mode test).</div>
        </div>
      </div>
      <div class="card">
        <h2>Mentions à corriger</h2>
        <ul class="item-list">
          <?php foreach ($result['critique']['blocs_durs_violes'] ?? [] as $b): ?>
            <li class="item-bd"><strong><?=mbisupp_h($b['libelle'] ?? '')?></strong>
              <?php if (!empty($b['detail'])): ?> — <?=mbisupp_h($b['detail'])?><?php endif; ?>
              <span style="opacity:0.55; font-family:monospace; font-size:11px;"> · <?=mbisupp_h($b['code'])?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p style="margin-top:16px;">
          <a class="btn btn-warn" href="?id_bien=<?=$idBien?>&type=<?=mbisupp_h($type)?>&action=generer&force=1<?=$angle!==''?'&angle='.mbisupp_h($angle):''?>">
            Forcer la génération (test uniquement)
          </a>
        </p>
      </div>
    <?php else: ?>
      <div class="verdict ko">
        <div class="ico">✗</div>
        <div>
          <div class="txt-main">Erreur technique</div>
          <div class="txt-sub"><?=mbisupp_h($result['erreur'] ?? 'erreur inconnue')?></div>
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <div class="verdict ok">
      <div class="ico">✓</div>
      <div>
        <div class="txt-main">PDF généré (version <?=$result['version']?>)</div>
        <div class="txt-sub">support_id <?=$result['support_id']?> · <?=mbisupp_h($result['fichier_pdf'])?></div>
      </div>
    </div>
    <div class="card">
      <h2>Téléchargement</h2>
      <p>
        <a class="btn btn-primary" href="<?=mbisupp_h(app_url('/mbi_supports_pdf_download.php?id=' . $result['support_id']))?>" target="_blank">
          Ouvrir le PDF
        </a>
        <a class="btn" style="background:#eef1f5;color:var(--navy);" href="<?=mbisupp_h(app_url('/mbi_supports_pdf_download.php?id=' . $result['support_id'] . '&dl=1'))?>">
          Télécharger
        </a>
      </p>
      <?php if (!empty($result['critique']['alertes'])): ?>
        <h2 style="margin-top:20px; color:var(--orange);">Alertes restantes (non bloquantes)</h2>
        <ul class="item-list">
          <?php foreach ($result['critique']['alertes'] as $a): ?>
            <li style="background:#fff7e8; color:var(--orange); padding:8px 12px; border-radius:8px; margin-bottom:4px; font-size:13px;">
              <strong><?=mbisupp_h($a['libelle'] ?? '')?></strong>
              <?php if (!empty($a['detail'])): ?> — <?=mbisupp_h($a['detail'])?><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($historique)): ?>
    <div class="card">
      <h2>Historique des supports — bien #<?=$idBien?></h2>
      <table class="histo">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Version</th>
            <th>Statut</th>
            <th>Angle</th>
            <th>Mentions</th>
            <th>Fichier</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historique as $h): ?>
            <tr>
              <td><?=mbisupp_h(date('d/m/Y H:i', strtotime((string)($h['date_generation'] ?: 'now'))))?></td>
              <td>
                <span class="badge <?=((int)$h['is_interne'] === 1)?'interne':''?>">
                  <?=mbisupp_h($h['type_support'])?>
                </span>
              </td>
              <td>v<?=mbisupp_h($h['version'])?></td>
              <td><?=mbisupp_h($h['statut'])?></td>
              <td><?=mbisupp_h($h['angle_marketing'])?></td>
              <td style="font-size:11px; color:var(--muted);"><?=mbisupp_h($h['mentions_version'])?></td>
              <td>
                <?php if (!empty($h['fichier_pdf_path'])): ?>
                  <a href="<?=mbisupp_h(app_url('/mbi_supports_pdf_download.php?id=' . $h['id']))?>" target="_blank">Ouvrir</a>
                  · <a href="<?=mbisupp_h(app_url('/mbi_supports_pdf_download.php?id=' . $h['id'] . '&dl=1'))?>">DL</a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
