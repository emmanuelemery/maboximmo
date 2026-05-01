<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_critique.php — Page test du moteur critique (mentions légales)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Module : Ma Box Communication (mbi_supports)
 *
 * Usage :
 *   /mbi_supports_critique.php?id_bien=1234
 *   /mbi_supports_critique.php?id_bien=1234&type=affiche_vitrine
 *
 * Affiche pour un bien donné :
 *   - peut_exporter (oui/non)
 *   - liste des blocs durs violés (à corriger avant export)
 *   - liste des alertes (recommandations)
 *   - mentions textes à apposer (DPE en_cours / non_soumis…)
 *   - version active des mentions appliquée
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mbi_supports_critic_engine.php';
require_login();

$idBien = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien'])
    ? (int)$_GET['id_bien'] : 0;
$type   = (string)($_GET['type'] ?? 'affiche_vitrine');
$typesValides = ['affiche_vitrine','fiche_client','fiche_visite_interne','dossier_presentation','email','reseaux_sociaux'];
if (!in_array($type, $typesValides, true)) $type = 'affiche_vitrine';

$result = ($idBien > 0) ? mbi_supports_critic_check($idBien, $type) : null;

function mbisupc_h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Critique mentions légales — Ma Box Communication</title>
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
  .form-bar input, .form-bar select { padding:8px 10px; border:1px solid var(--border);
                                       border-radius:8px; font-size:14px; min-width:180px; }
  .btn { padding:10px 18px; border-radius:8px; border:none; cursor:pointer;
         font-weight:600; font-size:14px; }
  .btn-primary { background:var(--navy); color:#fff; }
  .btn-primary:hover { background:#1a2f4a; }

  .verdict { padding:24px; border-radius:14px; margin:24px 0;
             font-weight:600; display:flex; align-items:center; gap:14px; }
  .verdict.ok { background:#e8f4ec; color:var(--vert); border:1px solid #b8dcc4; }
  .verdict.ko { background:#fdecec; color:var(--rouge); border:1px solid #ecb5b5; }
  .verdict .ico { font-size:32px; line-height:1; }
  .verdict .txt-main { font-size:18px; }
  .verdict .txt-sub  { font-size:13px; font-weight:400; opacity:0.85; }

  .card { background:#fff; border-radius:14px; padding:24px;
          box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:16px; }
  .card h2 { margin:0 0 14px; font-size:14px; color:var(--navy);
             text-transform:uppercase; letter-spacing:0.06em; }

  .item-list { list-style:none; padding:0; margin:0; }
  .item-list li { padding:12px 16px; border-radius:10px; margin-bottom:8px;
                  display:flex; gap:12px; align-items:flex-start; }
  .item-list li .code { font-family:"Menlo",monospace; font-size:11px;
                        opacity:0.65; min-width:160px; }
  .item-list li .body { flex:1; }
  .item-list li .lib  { font-weight:600; font-size:14px; }
  .item-list li .det  { font-size:13px; opacity:0.85; margin-top:2px; }

  .item-bd  { background:#fdecec; color:var(--rouge); }
  .item-al  { background:#fff7e8; color:var(--orange); }
  .item-ok  { background:#e8f4ec; color:var(--vert); }

  .mentions-box { background:#f0f4f9; border-left:3px solid var(--navy);
                  padding:14px 18px; border-radius:8px; margin-bottom:8px;
                  font-style:italic; color:var(--text); }

  .badges { display:flex; gap:8px; flex-wrap:wrap; }
  .badge { padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600;
           background:#eef1f5; color:var(--navy); }
  .empty { color:var(--muted); font-style:italic; padding:8px 0; }
  .meta-row { display:flex; gap:12px; flex-wrap:wrap; font-size:12px;
              color:var(--muted); }
  .meta-row span { padding:4px 10px; background:#f0f4f9; border-radius:6px; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>Critique mentions légales</h1>
      <div class="subtitle">Ma Box Communication — assistant critique avant export</div>
    </div>
    <a class="back-link" href="<?=$idBien>0?'/bien_detail.php?edit='.$idBien.'&section=descriptif':'/bien_liste.php'?>">← Retour</a>
  </div>

  <form class="form-bar" method="get" action="">
    <label>id_bien
      <input type="number" name="id_bien" value="<?=mbisupc_h($idBien?:'')?>" placeholder="ex: 1234" required>
    </label>
    <label>Type de support
      <select name="type">
        <?php foreach ($typesValides as $tv): ?>
          <option value="<?=$tv?>" <?=$type===$tv?'selected':''?>><?=$tv?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn btn-primary" type="submit">Vérifier</button>
  </form>

  <?php if ($result === null): ?>
    <div class="card" style="margin-top:24px;">
      <p class="empty">Saisis un <code>id_bien</code> + un type de support pour vérifier les mentions légales applicables.</p>
    </div>

  <?php elseif (!$result['ok']): ?>
    <div class="verdict ko">
      <div class="ico">✗</div>
      <div>
        <div class="txt-main">Erreur technique</div>
        <div class="txt-sub">Le moteur critique n'a pas pu se prononcer (voir détail ci-dessous).</div>
      </div>
    </div>
    <div class="card">
      <h2>Détail</h2>
      <ul class="item-list">
        <?php foreach ($result['blocs_durs_violes'] as $b): ?>
          <li class="item-bd">
            <div class="code"><?=mbisupc_h($b['code'])?></div>
            <div class="body">
              <div class="lib"><?=mbisupc_h($b['libelle'])?></div>
              <?php if (!empty($b['detail'])): ?>
                <div class="det"><?=mbisupc_h($b['detail'])?></div>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

  <?php else: ?>

    <?php
      $peut = (bool)$result['peut_exporter'];
      $nbBd = count($result['blocs_durs_violes']);
      $nbAl = count($result['alertes']);
    ?>
    <div class="verdict <?=$peut?'ok':'ko'?>">
      <div class="ico"><?=$peut?'✓':'✗'?></div>
      <div>
        <div class="txt-main">
          <?php if ($peut): ?>
            Export autorisé pour ce support
          <?php else: ?>
            Export refusé — <?=$nbBd?> mention<?=$nbBd>1?'s':''?> obligatoire<?=$nbBd>1?'s':''?> à corriger
          <?php endif; ?>
        </div>
        <div class="txt-sub">
          Type : <strong><?=mbisupc_h($type)?></strong> ·
          Mentions appliquées : <strong><?=mbisupc_h($result['mentions_version'] ?? '—')?></strong> ·
          <?=$nbAl?> alerte<?=$nbAl>1?'s':''?> qualité
        </div>
      </div>
    </div>

    <?php if ($nbBd > 0): ?>
      <div class="card">
        <h2 style="color:var(--rouge);">Bloc dur — <?=$nbBd?> mention<?=$nbBd>1?'s':''?> à corriger</h2>
        <ul class="item-list">
          <?php foreach ($result['blocs_durs_violes'] as $b): ?>
            <li class="item-bd">
              <div class="code"><?=mbisupc_h($b['code'])?></div>
              <div class="body">
                <div class="lib"><?=mbisupc_h($b['libelle'])?></div>
                <?php if (!empty($b['detail'])): ?>
                  <div class="det"><?=mbisupc_h($b['detail'])?></div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="card">
        <h2 style="color:var(--vert);">Bloc dur — toutes les mentions obligatoires sont OK</h2>
        <ul class="item-list"><li class="item-ok"><div class="body"><div class="lib">Aucune violation détectée</div></div></li></ul>
      </div>
    <?php endif; ?>

    <?php if ($nbAl > 0): ?>
      <div class="card">
        <h2 style="color:var(--orange);">Alertes qualité — <?=$nbAl?> recommandation<?=$nbAl>1?'s':''?></h2>
        <ul class="item-list">
          <?php foreach ($result['alertes'] as $a): ?>
            <li class="item-al">
              <div class="code"><?=mbisupc_h($a['code'])?></div>
              <div class="body">
                <div class="lib"><?=mbisupc_h($a['libelle'])?></div>
                <?php if (!empty($a['detail'])): ?>
                  <div class="det"><?=mbisupc_h($a['detail'])?></div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($result['mentions_textes'])): ?>
      <div class="card">
        <h2>Mentions à apposer sur le support</h2>
        <?php foreach ($result['mentions_textes'] as $cle => $txt): ?>
          <div class="mentions-box">
            <strong><?=mbisupc_h($cle)?> :</strong> «&nbsp;<?=mbisupc_h($txt)?>&nbsp;»
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
      $bien   = $result['contexte']['bien']        ?? [];
      $agence = $result['contexte']['agence']      ?? null;
      $nego   = $result['contexte']['negociateur'] ?? null;
      $mandat = $result['contexte']['mandat']      ?? null;
      $estCopro = !empty($result['contexte']['est_copro']);
      $nbPhotos = count($result['contexte']['photos'] ?? []);
    ?>
    <div class="card">
      <h2>Contexte chargé</h2>
      <div class="meta-row" style="margin-bottom:8px;">
        <?php if (!empty($bien['reference_bien'])): ?>
          <span><strong>Réf :</strong> <?=mbisupc_h($bien['reference_bien'])?></span>
        <?php endif; ?>
        <?php if (!empty($bien['ville'])): ?><span><?=mbisupc_h($bien['ville'])?></span><?php endif; ?>
        <?php if (!empty($bien['surface_habitable'])): ?><span><?=mbisupc_h($bien['surface_habitable'])?> m²</span><?php endif; ?>
        <?php $_prixB = (float)($bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0); ?>
        <?php if ($_prixB > 0): ?>
          <span><?=mbisupc_h(number_format($_prixB, 0, ',', ' '))?> €</span>
        <?php endif; ?>
        <span>Photos : <?=$nbPhotos?></span>
        <span>Copro : <?=$estCopro?'oui':'non'?></span>
      </div>
      <div class="badges">
        <span class="badge">Mandat : <?=is_array($mandat)?'présent':'absent'?></span>
        <span class="badge">Agence : <?=is_array($agence)?'OK':'absente'?></span>
        <span class="badge">Négociateur : <?=is_array($nego)?'OK':'absent'?></span>
        <?php if (!empty($bien['dpe_classe'] ?? $bien['dpe'] ?? '')): ?>
          <span class="badge">DPE : <?=mbisupc_h($bien['dpe_classe'] ?? $bien['dpe'])?></span>
        <?php endif; ?>
        <?php if (!empty($bien['dpe_statut'])): ?>
          <span class="badge">Statut DPE : <?=mbisupc_h($bien['dpe_statut'])?></span>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>

</div>
</body>
</html>
