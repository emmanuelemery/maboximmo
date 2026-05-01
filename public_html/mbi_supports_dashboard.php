<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_dashboard.php — Dashboard Ma Box Communication par bien
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Vue 360° pour un bien donné :
 *   - Bandeau bien (réf, ville, prix)
 *   - Score commercial actuel + bouton recalculer
 *   - Critique pour le type courant + sélecteur de type
 *   - 3 boutons de génération (affiche / fiche / interne)
 *   - Historique des supports générés
 *
 * Cible des clics depuis :
 *   - inc/mbi_supports_card_bien.php (pill topbar bien_detail.php)
 *   - sidebar (entrée à venir)
 *
 * Usage : /mbi_supports_dashboard.php?id_bien=XXX[&type=affiche_vitrine]
 *
 * Param POST :
 *   action=generer  → produit un PDF (utilise mbi_supports_pdf_generer)
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mbi_supports_score_engine.php';
require_once __DIR__ . '/inc/mbi_supports_critic_engine.php';
require_once __DIR__ . '/inc/mbi_supports_pdf_generator.php';
require_login();

$idBien = isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien'])
    ? (int)$_GET['id_bien'] : 0;
$type   = (string)($_GET['type'] ?? 'affiche_vitrine');
$typesValides = ['affiche_vitrine','fiche_client','fiche_visite_interne','dossier_presentation'];
if (!in_array($type, $typesValides, true)) $type = 'affiche_vitrine';

$msgGen = null; // ['ok'=>bool, 'msg'=>str, 'support_id'=>?int, 'fichier'=>?str]
$msgScore = null;

// ─── Action : Recalculer le score ─────────────────────────────────────
if ($idBien > 0 && (($_GET['action'] ?? '') === 'recalculer_score')) {
    $modele = (string)($_GET['modele'] ?? 'haiku');
    $r = mbi_supports_score_calculer($idBien, current_user_id(), 'hybride', $modele);
    $msgScore = $r['ok']
        ? ['ok'=>true,  'msg'=>"Score recalculé : {$r['score']}/100"]
        : ['ok'=>false, 'msg'=>'Échec recalcul : ' . ($r['erreur'] ?? '?')];
}

// ─── Action : Générer un support ──────────────────────────────────────
if ($idBien > 0 && ($_POST['action'] ?? '') === 'generer') {
    $typeGen = (string)($_POST['type'] ?? $type);
    if (!in_array($typeGen, $typesValides, true)) $typeGen = $type;
    $angle  = (string)($_POST['angle'] ?? '');
    $brief  = trim((string)($_POST['orientation_user'] ?? ''));
    $force  = !empty($_POST['force']);
    $opts = [];
    if ($force)        $opts['force_export']    = true;
    if ($angle !== '') $opts['angle_marketing'] = $angle;

    $r = mbi_supports_pdf_generer($idBien, $typeGen, $brief !== '' ? $brief : null, $opts);
    if ($r['ok']) {
        $msgGen = [
            'ok' => true,
            'msg' => "PDF généré (v{$r['version']}, {$typeGen})",
            'support_id' => $r['support_id'],
            'fichier' => $r['fichier_pdf'],
        ];
    } else {
        $msgGen = [
            'ok' => false,
            'msg' => $r['statut'] === 'refuse'
                ? 'Génération refusée — voir critique ci-dessous'
                : ('Erreur : ' . ($r['erreur'] ?? '?')),
            'support_id' => null, 'fichier' => null,
        ];
    }
    $type = $typeGen; // bascule la vue critique sur le type qu'on vient de demander
}

// ─── Lectures ─────────────────────────────────────────────────────────
$bien = null;
$score = null;
$critique = null;
$supports = [];

if ($idBien > 0) {
    try {
        $st = $pdo->prepare("
            SELECT b.id, b.id_societe, b.id_agence,
                   b.reference_bien, b.designation, b.ville, b.code_postal,
                   b.prix_vente_estime, b.surface_habitable, b.nb_pieces,
                   b.dpe_classe, b.ges_classe, b.bien_en_copropriete, b.statut_bien
            FROM biens b WHERE b.id = :id LIMIT 1
        ");
        $st->execute([':id' => $idBien]);
        $bien = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('[mbi_supports_dashboard bien] ' . $e->getMessage()); }

    // Scope check (super admin role=1 bypass)
    if ($bien) {
        $roleId    = (int)($_SESSION['id_role'] ?? 0);
        $idSocSess = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
        if ($roleId !== 1 && $idSocSess !== null) {
            $idSocBien = (int)($bien['id_societe'] ?? 0);
            if ($idSocBien > 0 && $idSocBien !== $idSocSess) $bien = null;
        }
    }

    if ($bien) {
        $score    = mbi_supports_score_get_dernier($idBien);
        $critique = mbi_supports_critic_check($idBien, $type);
        try {
            $st = $pdo->prepare("
                SELECT id, type_support, version, statut, angle_marketing,
                       fichier_pdf_path, nom_fichier, is_interne, date_generation,
                       mentions_version
                FROM mbi_supports_commerciaux
                WHERE id_bien = :b AND deleted_at IS NULL
                ORDER BY date_generation DESC, id DESC LIMIT 30
            ");
            $st->execute([':b' => $idBien]);
            $supports = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { $supports = []; }
    }
}

function mbisd_h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function mbisd_score_color(?int $s): string {
    if ($s === null) return '#6b7280';
    return match(true) {
        $s >= 80 => '#1a8754',
        $s >= 60 => '#5a8a3f',
        $s >= 40 => '#c97b2e',
        $s >= 20 => '#a85858',
        default  => '#7a3030',
    };
}

$pointsForts   = $score && !empty($score['points_forts_json'])   ? json_decode((string)$score['points_forts_json'],   true) : [];
$pointsFaibles = $score && !empty($score['points_faibles_json']) ? json_decode((string)$score['points_faibles_json'], true) : [];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ma Box Communication — Dashboard</title>
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
  .wrap { max-width:1200px; margin:0 auto; padding:24px; }
  h1 { font-size:22px; margin:0 0 4px; color:var(--navy); }
  .topbar { display:flex; justify-content:space-between; align-items:center;
            margin-bottom:24px; gap:16px; flex-wrap:wrap; }
  .back-link { color:var(--navy); text-decoration:none; font-size:14px; }
  .back-link:hover { text-decoration:underline; }

  .bandeau {
    background:linear-gradient(135deg, var(--navy) 0%, #1a2f4a 100%);
    color:#fff; border-radius:16px; padding:24px 28px; margin-bottom:20px;
    display:flex; gap:24px; align-items:center; flex-wrap:wrap;
  }
  .bandeau .ref { font-size:11px; letter-spacing:0.08em; text-transform:uppercase;
                  opacity:0.75; margin-bottom:4px; }
  .bandeau .designation { font-size:20px; font-weight:600; margin-bottom:6px; }
  .bandeau .meta { font-size:13px; opacity:0.85; }
  .bandeau .prix { font-size:32px; font-weight:700; color:var(--or); margin-left:auto; }

  .grid { display:grid; grid-template-columns: 1fr 1fr; gap:20px; }
  @media (max-width:900px) { .grid { grid-template-columns:1fr; } }

  .card { background:#fff; border-radius:14px; padding:22px;
          box-shadow:0 1px 3px rgba(0,0,0,0.05); }
  .card h2 { margin:0 0 14px; font-size:13px; color:var(--navy);
             text-transform:uppercase; letter-spacing:0.08em; }

  .gauge { display:flex; align-items:center; gap:14px; }
  .gauge .num { font-size:48px; font-weight:700; line-height:1; }
  .gauge .max { color:var(--muted); font-size:14px; font-weight:400; }
  .gauge .meta { display:flex; flex-direction:column; gap:4px; font-size:12px; color:var(--muted); }
  .badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
  .badge { padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600;
           background:#eef1f5; color:var(--navy); }
  .badge.urg-fort { background:#fdecec; color:var(--rouge); }
  .badge.urg-moyen { background:#fff7e8; color:var(--orange); }
  .badge.urg-faible { background:#e8f4ec; color:var(--vert); }

  .verdict {
    padding:16px; border-radius:12px; margin-bottom:14px;
    font-weight:600; display:flex; align-items:center; gap:12px;
  }
  .verdict.ok { background:#e8f4ec; color:var(--vert); border:1px solid #b8dcc4; }
  .verdict.ko { background:#fdecec; color:var(--rouge); border:1px solid #ecb5b5; }
  .verdict .ico { font-size:24px; }
  .verdict .txt-main { font-size:14px; }
  .verdict .txt-sub  { font-size:12px; font-weight:400; opacity:0.85; }

  .pf-list { list-style:none; padding:0; margin:0; }
  .pf-list li { padding:7px 11px; border-radius:8px; margin-bottom:5px; font-size:12.5px; }
  .pf-list li.pf { background:#e8f4ec; color:var(--vert); }
  .pf-list li.pfa { background:#fdecec; color:var(--rouge); }

  .gen-buttons { display:flex; gap:10px; flex-wrap:wrap; }
  .gen-btn {
    flex:1; min-width:160px; background:#fff; border:2px solid var(--navy);
    color:var(--navy); padding:14px 16px; border-radius:12px;
    cursor:pointer; font-weight:600; font-size:14px; text-align:center;
    transition:all 0.15s;
  }
  .gen-btn:hover { background:var(--navy); color:#fff; }
  .gen-btn.interne { border-color:#a85858; color:#a85858; }
  .gen-btn.interne:hover { background:#a85858; color:#fff; }
  .gen-btn .lbl { display:block; font-size:14px; }
  .gen-btn .desc { display:block; font-size:11px; font-weight:400; opacity:0.75; margin-top:2px; }

  .alert-success { background:#e8f4ec; color:var(--vert); padding:12px 16px;
                   border-radius:10px; margin-bottom:16px; font-size:14px; }
  .alert-error { background:#fdecec; color:var(--rouge); padding:12px 16px;
                 border-radius:10px; margin-bottom:16px; font-size:14px; }

  .selector { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  .selector a {
    padding:6px 12px; border-radius:999px; background:#eef1f5;
    color:var(--navy); text-decoration:none; font-size:12px; font-weight:600;
    border:1px solid transparent;
  }
  .selector a.active { background:var(--navy); color:#fff; }

  table.histo { width:100%; border-collapse:collapse; font-size:12.5px; }
  table.histo th, table.histo td { padding:7px 10px; text-align:left;
                                    border-bottom:1px solid var(--border); }
  table.histo th { color:var(--muted); font-weight:600; font-size:11px;
                   text-transform:uppercase; letter-spacing:0.06em; }
  .empty { color:var(--muted); font-style:italic; padding:8px 0; }

  details summary { cursor:pointer; color:var(--navy); font-weight:600; font-size:12px;
                    margin-top:8px; }
  details ul { padding-left:18px; margin:6px 0 0; }

  .brief-form { display:flex; flex-direction:column; gap:8px; margin-top:12px; }
  .brief-form input, .brief-form select, .brief-form textarea {
    padding:8px 10px; border:1px solid var(--border); border-radius:8px;
    font-family:inherit; font-size:13px;
  }
  .brief-form label { font-size:11px; color:var(--muted); font-weight:600;
                       text-transform:uppercase; letter-spacing:0.06em; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>Ma Box Communication</h1>
      <div style="color:var(--muted); font-size:13px;">Score commercial · Mentions légales · Génération PDF</div>
    </div>
    <a class="back-link" href="<?= function_exists('app_url') ? mbisd_h(app_url('/bien_detail.php?edit='.$idBien.'&section=descriptif')) : '#' ?>">← Retour au bien</a>
  </div>

  <?php if ($idBien <= 0 || !$bien): ?>
    <div class="card">
      <p class="empty">
        <?= $idBien <= 0 ? 'Saisis un id_bien dans l\'URL : ?id_bien=XXX' : 'Bien introuvable ou hors de votre périmètre.' ?>
      </p>
    </div>

  <?php else: ?>

    <!-- BANDEAU BIEN -->
    <div class="bandeau">
      <div>
        <div class="ref">Réf <?= mbisd_h($bien['reference_bien'] ?? '—') ?> · #<?= (int)$bien['id'] ?></div>
        <div class="designation"><?= mbisd_h($bien['designation'] ?? 'Bien') ?></div>
        <div class="meta">
          <?= mbisd_h(trim(($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''))) ?>
          <?php if (!empty($bien['surface_habitable'])): ?>
            · <?= mbisd_h(rtrim(rtrim(number_format((float)$bien['surface_habitable'], 2), '0'), '.')) ?> m²
          <?php endif; ?>
          <?php if (!empty($bien['nb_pieces'])): ?>
            · <?= (int)$bien['nb_pieces'] ?> pièces
          <?php endif; ?>
          <?php if (!empty($bien['dpe_classe'])): ?>
            · DPE <?= mbisd_h($bien['dpe_classe']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="prix">
        <?php
          $prix = (float)($bien['prix_vente_estime'] ?? 0);
          echo $prix > 0 ? number_format($prix, 0, ',', ' ') . ' €' : '— €';
        ?>
      </div>
    </div>

    <?php if ($msgScore): ?>
      <div class="<?= $msgScore['ok'] ? 'alert-success' : 'alert-error' ?>">
        <?= mbisd_h($msgScore['msg']) ?>
      </div>
    <?php endif; ?>
    <?php if ($msgGen): ?>
      <div class="<?= $msgGen['ok'] ? 'alert-success' : 'alert-error' ?>">
        <?= mbisd_h($msgGen['msg']) ?>
        <?php if (!empty($msgGen['support_id'])): ?>
          ·
          <a href="<?= mbisd_h(app_url('/mbi_supports_pdf_download.php?id=' . $msgGen['support_id'])) ?>" target="_blank">Ouvrir</a>
          ·
          <a href="<?= mbisd_h(app_url('/mbi_supports_pdf_download.php?id=' . $msgGen['support_id'] . '&dl=1')) ?>">Télécharger</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="grid">

      <!-- ── COL 1 : SCORE ──────────────────────────────────────────── -->
      <div class="card">
        <h2>Score commercial</h2>
        <?php if (!$score): ?>
          <p class="empty">Aucun score calculé pour ce bien.</p>
          <p>
            <a class="gen-btn" style="display:inline-block; padding:10px 16px;"
               href="?id_bien=<?= $idBien ?>&action=recalculer_score&modele=haiku">
              Évaluer commercialement
            </a>
          </p>
        <?php else: ?>
          <?php $sNum = (int)($score['score'] ?? 0); $col = mbisd_score_color($sNum); ?>
          <div class="gauge">
            <div class="num" style="color:<?= $col ?>"><?= $sNum ?><span class="max">/100</span></div>
            <div class="meta">
              <div>Calculé le <?= mbisd_h(date('d/m/Y H:i', strtotime((string)$score['date_calcul']))) ?></div>
              <div>Mode : <?= mbisd_h($score['calcul_mode'] ?? '—') ?></div>
              <?php if (!empty($score['cout_ia_centimes'])): ?>
                <div>Coût IA : <?= number_format(((int)$score['cout_ia_centimes'])/100, 2, ',', ' ') ?> €</div>
              <?php endif; ?>
              <div class="badges">
                <?php if (!empty($score['angle_recommande'])): ?>
                  <span class="badge">Angle : <?= mbisd_h($score['angle_recommande']) ?></span>
                <?php endif; ?>
                <?php if (!empty($score['niveau_urgence'])): ?>
                  <span class="badge urg-<?= mbisd_h($score['niveau_urgence']) ?>">Urgence : <?= mbisd_h($score['niveau_urgence']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!empty($pointsForts)): ?>
            <div style="margin-top:14px;">
              <div style="font-size:11px; font-weight:600; color:var(--vert); text-transform:uppercase; margin-bottom:6px;">Points forts</div>
              <ul class="pf-list">
                <?php foreach (array_slice($pointsForts, 0, 4) as $pf): ?>
                  <li class="pf">+ <?= mbisd_h((string)$pf) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if (!empty($pointsFaibles)): ?>
            <div style="margin-top:8px;">
              <div style="font-size:11px; font-weight:600; color:var(--rouge); text-transform:uppercase; margin-bottom:6px;">Points faibles</div>
              <ul class="pf-list">
                <?php foreach (array_slice($pointsFaibles, 0, 3) as $pfa): ?>
                  <li class="pfa">– <?= mbisd_h((string)$pfa) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <p style="margin-top:14px;">
            <a href="?id_bien=<?= $idBien ?>&action=recalculer_score&modele=haiku" style="font-size:12px; color:var(--navy); text-decoration:underline;">
              Recalculer (Haiku)
            </a>
            ·
            <a href="<?= mbisd_h(app_url('/mbi_supports_score.php?id_bien='.$idBien)) ?>" style="font-size:12px; color:var(--muted); text-decoration:underline;">
              Vue détaillée
            </a>
          </p>
        <?php endif; ?>
      </div>

      <!-- ── COL 2 : CRITIQUE MENTIONS LÉGALES ──────────────────────── -->
      <div class="card">
        <h2>Mentions légales — <?= mbisd_h($type) ?></h2>
        <div class="selector">
          <?php foreach ($typesValides as $tv): ?>
            <a href="?id_bien=<?= $idBien ?>&type=<?= $tv ?>" class="<?= $type === $tv ? 'active' : '' ?>"><?= $tv ?></a>
          <?php endforeach; ?>
        </div>

        <?php if (!$critique || !$critique['ok']): ?>
          <div class="verdict ko">
            <div class="ico">⚠</div>
            <div>
              <div class="txt-main">Critique indisponible</div>
              <div class="txt-sub">Vérifie qu'une version de mentions est active.</div>
            </div>
          </div>
        <?php else: ?>
          <?php $peut = (bool)$critique['peut_exporter']; $nbBd = count($critique['blocs_durs_violes']); $nbAl = count($critique['alertes']); ?>
          <div class="verdict <?= $peut ? 'ok' : 'ko' ?>">
            <div class="ico"><?= $peut ? '✓' : '✗' ?></div>
            <div>
              <div class="txt-main">
                <?= $peut ? 'Export autorisé' : ($nbBd . ' mention' . ($nbBd>1?'s':'') . ' obligatoire' . ($nbBd>1?'s':'') . ' à corriger') ?>
              </div>
              <div class="txt-sub">
                Mentions <?= mbisd_h($critique['mentions_version'] ?? '—') ?> · <?= $nbAl ?> alerte<?= $nbAl>1?'s':'' ?> qualité
              </div>
            </div>
          </div>

          <?php if ($nbBd > 0): ?>
            <details open>
              <summary>Voir les <?= $nbBd ?> mention<?= $nbBd>1?'s':'' ?> à corriger</summary>
              <ul style="margin-top:8px; padding-left:0; list-style:none;">
                <?php foreach ($critique['blocs_durs_violes'] as $b): ?>
                  <li style="background:#fdecec; color:var(--rouge); padding:6px 10px; border-radius:6px; margin-bottom:4px; font-size:12px;">
                    <strong><?= mbisd_h($b['libelle'] ?? '') ?></strong>
                    <?php if (!empty($b['detail'])): ?> — <?= mbisd_h($b['detail']) ?><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>

          <?php if ($nbAl > 0): ?>
            <details>
              <summary><?= $nbAl ?> alerte<?= $nbAl>1?'s':'' ?> qualité</summary>
              <ul style="margin-top:8px; padding-left:0; list-style:none;">
                <?php foreach ($critique['alertes'] as $a): ?>
                  <li style="background:#fff7e8; color:var(--orange); padding:6px 10px; border-radius:6px; margin-bottom:4px; font-size:12px;">
                    <strong><?= mbisd_h($a['libelle'] ?? '') ?></strong>
                    <?php if (!empty($a['detail'])): ?> — <?= mbisd_h($a['detail']) ?><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endif; ?>

          <?php if (!empty($critique['mentions_textes'])): ?>
            <div style="margin-top:12px; padding:10px 12px; background:#f0f4f9; border-left:3px solid var(--navy); border-radius:6px; font-size:11.5px; font-style:italic; color:var(--muted);">
              <?php foreach ($critique['mentions_textes'] as $cle => $txt): ?>
                <div style="margin:2px 0;">«&nbsp;<?= mbisd_h($txt) ?>&nbsp;»</div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── GÉNÉRATION ─────────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px;">
      <h2>Générer un support</h2>
      <form method="post" class="brief-form">
        <input type="hidden" name="action" value="generer">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label for="angle">Angle marketing</label>
            <select name="angle" id="angle">
              <option value="">— auto (suggéré par l'IA) —</option>
              <option value="famille">Famille</option>
              <option value="investisseur">Investisseur</option>
              <option value="premium">Premium</option>
              <option value="premier_achat">Premier achat</option>
              <option value="generique">Générique</option>
            </select>
          </div>
          <div>
            <label for="orientation_user">Brief libre (optionnel)</label>
            <input type="text" name="orientation_user" id="orientation_user"
                   placeholder="Insister sur le jardin, mentionner travaux récents...">
          </div>
        </div>
        <div style="margin-top:6px;">
          <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--muted);">
            <input type="checkbox" name="force" value="1"> Forcer la génération même si bloc dur (mode test)
          </label>
        </div>
        <div class="gen-buttons" style="margin-top:14px;">
          <button class="gen-btn" type="submit" name="type" value="affiche_vitrine">
            <span class="lbl">📰 Affiche vitrine</span>
            <span class="desc">A4 portrait — photo héro + prix + DPE/GES</span>
          </button>
          <button class="gen-btn" type="submit" name="type" value="fiche_client">
            <span class="lbl">📄 Fiche client</span>
            <span class="desc">Multi-page — descriptif complet + galerie + mentions</span>
          </button>
          <button class="gen-btn interne" type="submit" name="type" value="fiche_visite_interne">
            <span class="lbl">🔒 Fiche visite interne</span>
            <span class="desc">Filigrane — points forts / objections / questions</span>
          </button>
        </div>
      </form>
    </div>

    <!-- ── HISTORIQUE ─────────────────────────────────────────────── -->
    <div class="card" style="margin-top:20px;">
      <h2>Historique des supports (<?= count($supports) ?>)</h2>
      <?php if (empty($supports)): ?>
        <p class="empty">Aucun support généré pour ce bien.</p>
      <?php else: ?>
        <table class="histo">
          <thead>
            <tr>
              <th>Date</th>
              <th>Type</th>
              <th>Version</th>
              <th>Statut</th>
              <th>Angle</th>
              <th>Mentions</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($supports as $s): ?>
              <tr>
                <td><?= mbisd_h(date('d/m/Y H:i', strtotime((string)($s['date_generation'] ?: 'now')))) ?></td>
                <td>
                  <span class="badge<?= ((int)$s['is_interne'] === 1) ? ' urg-fort' : '' ?>">
                    <?= mbisd_h($s['type_support']) ?>
                  </span>
                </td>
                <td>v<?= (int)$s['version'] ?></td>
                <td><?= mbisd_h($s['statut']) ?></td>
                <td><?= mbisd_h($s['angle_marketing']) ?></td>
                <td style="font-size:10px; color:var(--muted);"><?= mbisd_h($s['mentions_version']) ?></td>
                <td>
                  <?php if (!empty($s['fichier_pdf_path'])): ?>
                    <a href="<?= mbisd_h(app_url('/mbi_supports_pdf_download.php?id='.$s['id'])) ?>" target="_blank">Ouvrir</a>
                    ·
                    <a href="<?= mbisd_h(app_url('/mbi_supports_pdf_download.php?id='.$s['id'].'&dl=1')) ?>">DL</a>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
