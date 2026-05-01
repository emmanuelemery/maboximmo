<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_edit.php — Éditeur de support PDF
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Permet à l'utilisateur d'ajuster les textes (titre, accroche, description)
 * et la photo héro AVANT de produire le PDF final, puis de régénérer une
 * nouvelle version aussi souvent qu'il le souhaite.
 *
 * Layout :
 *   - Gauche : formulaire (bandeau critique en info)
 *   - Droite : aperçu PDF inline (iframe)
 *
 * Usage :
 *   /mbi_supports_edit.php?id=<support_id>
 *
 * POST action=regenerer → relance le générateur avec les surcharges saisies
 * (incrémente la version, ouvre l'aperçu).
 *
 * Aucune modification du bien lui-même : les surcharges sont locales au
 * support (cf. spec V1 — confirmé par Emmanuel 2026-05-02).
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mbi_supports_pdf_generator.php';
require_once __DIR__ . '/inc/mbi_supports_critic_engine.php';
require_login();

$supportId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($supportId <= 0) {
    http_response_code(400);
    exit('Paramètre id manquant.');
}

// Charge le support + scope check
try {
    $st = $pdo->prepare("SELECT * FROM mbi_supports_commerciaux WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $st->execute([':id' => $supportId]);
    $support = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[mbi_supports_edit] ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur lecture du support.');
}
if (!$support) {
    http_response_code(404);
    exit('Support introuvable.');
}

$roleId = (int)($_SESSION['id_role'] ?? 0);
$idSocSess = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if ($roleId !== 1 && $idSocSess !== null) {
    if ((int)$support['id_societe'] !== $idSocSess) {
        http_response_code(403);
        exit('Accès refusé (scope société).');
    }
}

$idBien = (int)$support['id_bien'];
$type   = (string)$support['type_support'];

// Charge le bien (pour pré-remplir le formulaire si surcharges vides)
try {
    $st = $pdo->prepare("SELECT * FROM biens WHERE id = :id LIMIT 1");
    $st->execute([':id' => $idBien]);
    $bien = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) { $bien = []; }

// Charge les photos pour le sélecteur de photo héro
try {
    $st = $pdo->prepare("SELECT id, COALESCE(legende, alt_text, caption, '') AS lib, ordre FROM biens_photos WHERE id_bien = :b ORDER BY ordre, id");
    $st->execute([':b' => $idBien]);
    $photos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) { $photos = []; }

// ─── POST : action "valider" (fige la version, alimente l'historique) ──
$msgGen = null;
$nouveauSupportId = null;
if (($_POST['action'] ?? '') === 'valider') {
    try {
        $up = $pdo->prepare("UPDATE mbi_supports_commerciaux
                             SET statut = 'valide', date_validation = NOW(), updated_at = NOW()
                             WHERE id = :id AND statut = 'draft'");
        $up->execute([':id' => $supportId]);
        $msgGen = ['ok' => true, 'msg' => 'Affiche validée — visible dans l\'historique.'];
        // Recharge le support (pour reflèter le nouveau statut dans la page)
        $st = $pdo->prepare("SELECT * FROM mbi_supports_commerciaux WHERE id = :id LIMIT 1");
        $st->execute([':id' => $supportId]);
        $support = $st->fetch(PDO::FETCH_ASSOC) ?: $support;
    } catch (Throwable $e) {
        $msgGen = ['ok' => false, 'msg' => 'Erreur validation : ' . $e->getMessage()];
    }
}

// ─── POST : régénération avec surcharges ───────────────────────────────
if (($_POST['action'] ?? '') === 'regenerer') {
    $opts = [
        'force_export'              => true,        // l'éditeur fait toujours sauter le bloc dur
        'titre_personnalise'        => trim((string)($_POST['titre_personnalise']        ?? '')),
        'accroche'                  => trim((string)($_POST['accroche']                  ?? '')),
        'description_personnalisee' => trim((string)($_POST['description_personnalisee'] ?? '')),
        'photo_hero_id_personnalise'=> (int)($_POST['photo_hero_id_personnalise']        ?? 0) ?: null,
        'angle_marketing'           => trim((string)($_POST['angle_marketing']           ?? '')),
        'source_support_id'         => $supportId,
    ];
    $brief = trim((string)($_POST['orientation_user'] ?? ''));
    $r = mbi_supports_pdf_generer($idBien, $type, $brief !== '' ? $brief : null, $opts);
    if ($r['ok']) {
        // Si c'est le même support_id (mode UPDATE drafts) : on reste sur la même URL
        // Sinon (création nouvelle version après validation) : on redirige
        $nouveauSupportId = (int)$r['support_id'];
        if ($nouveauSupportId !== $supportId) {
            header('Location: ' . app_url('/mbi_supports_edit.php?id=' . $nouveauSupportId));
            exit;
        }
        $msgGen = ['ok' => true, 'msg' => "Brouillon mis à jour — l'aperçu PDF est rafraîchi."];
        // Recharge le support
        $st = $pdo->prepare("SELECT * FROM mbi_supports_commerciaux WHERE id = :id LIMIT 1");
        $st->execute([':id' => $supportId]);
        $support = $st->fetch(PDO::FETCH_ASSOC) ?: $support;
    } else {
        $msgGen = ['ok' => false, 'msg' => 'Erreur : ' . ($r['erreur'] ?? '?')];
    }
}

// ─── Critique pour ce support (info, non bloquante) ────────────────────
$critique = mbi_supports_critic_check($idBien, $type);
$nbBd = $critique['ok'] ? count($critique['blocs_durs_violes']) : 0;
$nbAl = $critique['ok'] ? count($critique['alertes']) : 0;

// ─── Pré-remplissage formulaire ────────────────────────────────────────
$formTitre   = (string)($support['titre_personnalise']        ?? '');
$formAccroche = (string)($support['accroche']                 ?? '');
$formDesc    = (string)($support['description_personnalisee'] ?? '');
$formHero    = (int)($support['photo_hero_id_personnalise']   ?? 0);
$formAngle   = (string)($support['angle_marketing']           ?? '');

// Suggestion : si aucune surcharge sauvée, on peut suggérer la valeur du bien
$suggDesc = (string)($bien['description'] ?? '');
$suggTitre = (string)($bien['designation'] ?? '');

function mbisedit_h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$urlPdf = app_url('/mbi_supports_pdf_download.php?id=' . $supportId);
$urlDashboard = app_url('/mbi_supports_dashboard.php?id_bien=' . $idBien);
$urlBien = app_url('/bien_detail.php?edit=' . $idBien . '&section=annonce');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Éditeur de support — Ma Box Communication</title>
<style>
  :root {
    --navy:#243B5C; --or:#D4A047; --bg:#f7f8fa; --text:#1F2937;
    --muted:#6b7280; --border:#e5e7eb; --rouge:#7a2828; --vert:#1a5e36; --orange:#8a6422;
  }
  * { box-sizing: border-box; }
  html,body { margin:0; padding:0; height:100%; }
  body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
         background:var(--bg); color:var(--text); line-height:1.5;
         display:flex; flex-direction:column; }

  .topbar {
    background:#fff; border-bottom:1px solid var(--border);
    padding:14px 24px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;
  }
  .topbar .title { font-size:16px; font-weight:600; color:var(--navy); }
  .topbar .meta { color:var(--muted); font-size:12px; }
  .topbar .spacer { flex:1; }
  .topbar a { color:var(--navy); text-decoration:none; font-size:13px; }
  .topbar a:hover { text-decoration:underline; }

  .main {
    flex:1; display:grid; grid-template-columns:520px 1fr; gap:0; overflow:hidden;
  }
  @media (max-width:1100px) { .main { grid-template-columns:1fr; } }

  .col-form {
    overflow-y:auto; padding:24px; background:#fff; border-right:1px solid var(--border);
  }
  .col-pdf {
    background:#444; display:flex; flex-direction:column; align-items:stretch;
  }
  .col-pdf iframe { flex:1; border:0; width:100%; }
  .col-pdf .pdf-bar {
    background:#222; color:#fff; padding:8px 14px; font-size:12px;
    display:flex; gap:12px; align-items:center;
  }
  .col-pdf .pdf-bar a { color:var(--or); text-decoration:none; }

  h2 { font-size:13px; color:var(--navy); text-transform:uppercase;
       letter-spacing:0.06em; margin:0 0 10px; }

  .crit-bandeau {
    padding:12px 14px; border-radius:10px; margin-bottom:18px;
    display:flex; align-items:center; gap:10px; font-size:13px;
  }
  .crit-bandeau.ok { background:#e8f4ec; color:var(--vert); }
  .crit-bandeau.warn { background:#fff7e8; color:var(--orange); }
  .crit-bandeau .ico { font-size:20px; }
  .crit-bandeau strong { font-weight:700; }
  .crit-bandeau small { font-size:11px; opacity:0.85; display:block; margin-top:2px; }

  details summary { cursor:pointer; color:var(--navy); font-weight:600; font-size:12px;
                    padding:6px 0; }
  details ul { padding-left:0; margin:6px 0 12px; list-style:none; }
  details ul li { padding:6px 10px; border-radius:6px; margin-bottom:4px; font-size:11.5px; }
  details ul li.bd  { background:#fdecec; color:var(--rouge); }
  details ul li.al  { background:#fff7e8; color:var(--orange); }

  .field { margin-bottom:14px; }
  .field label { display:block; font-size:11px; font-weight:600; color:var(--muted);
                  text-transform:uppercase; letter-spacing:0.06em; margin-bottom:4px; }
  .field input, .field select, .field textarea {
    width:100%; padding:8px 10px; border:1px solid var(--border);
    border-radius:8px; font-family:inherit; font-size:13px;
  }
  .field textarea { min-height:80px; resize:vertical; }
  .field .hint { font-size:11px; color:var(--muted); margin-top:3px; font-style:italic; }
  .field .ai-suggest {
    margin-top:4px; padding:6px 10px; background:#f0f4f9; border-radius:6px;
    font-size:11px; color:var(--muted); border-left:2px solid var(--navy);
  }
  .field .ai-suggest button {
    margin-left:6px; padding:1px 8px; background:var(--navy); color:#fff;
    border:0; border-radius:4px; font-size:10px; cursor:pointer;
  }

  .actions {
    position:sticky; bottom:0; background:#fff;
    margin:24px -24px -24px; padding:14px 24px;
    border-top:1px solid var(--border);
    display:flex; gap:10px;
  }
  .btn { padding:10px 18px; border-radius:8px; border:0; cursor:pointer;
         font-weight:600; font-size:14px; }
  .btn-primary { background:var(--navy); color:#fff; flex:1; }
  .btn-primary:hover { background:#1a2f4a; }
  .btn-secondary { background:#fff; color:var(--navy); border:1px solid var(--navy); }

  .alert-success { background:#e8f4ec; color:var(--vert); padding:10px 14px;
                   border-radius:8px; margin-bottom:14px; font-size:13px; }
  .alert-error { background:#fdecec; color:var(--rouge); padding:10px 14px;
                 border-radius:8px; margin-bottom:14px; font-size:13px; }

  .badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
  .badge { padding:3px 9px; border-radius:999px; font-size:11px; font-weight:600;
           background:#eef1f5; color:var(--navy); }
</style>
</head>
<body>

<div class="topbar">
  <div>
    <div class="title">📰 <?= mbisedit_h($support['titre_support'] ?? 'Support') ?>
      <?php
        $statutAff = (string)$support['statut'];
        $statutColor = match($statutAff) {
            'draft'         => '#8a6422', // orange
            'valide'        => '#1a5e36', // vert
            'diffuse'       => '#1a5e36',
            'archive'       => '#6b7280',
            'erreur'        => '#7a2828',
            default         => '#6b7280',
        };
        $statutLib = match($statutAff) {
            'draft'   => 'BROUILLON',
            'valide'  => 'VALIDÉ',
            'diffuse' => 'DIFFUSÉ',
            'archive' => 'ARCHIVÉ',
            'erreur'  => 'ERREUR',
            default   => strtoupper($statutAff),
        };
      ?>
      <span style="display:inline-block; margin-left:8px; padding:3px 10px;
             border-radius:999px; font-size:10px; font-weight:700;
             background:<?= $statutColor ?>22; color:<?= $statutColor ?>;
             vertical-align:middle;">
        <?= mbisedit_h($statutLib) ?>
      </span>
    </div>
    <div class="meta">
      Bien #<?= $idBien ?> · <?= mbisedit_h($bien['reference_bien'] ?? '—') ?> ·
      <?= mbisedit_h($type) ?> · v<?= (int)$support['version'] ?> ·
      Mentions <?= mbisedit_h($support['mentions_version']) ?>
    </div>
  </div>
  <div class="spacer"></div>
  <a href="<?= mbisedit_h($urlDashboard) ?>">← Dashboard</a>
  <a href="<?= mbisedit_h($urlBien) ?>">Voir le bien</a>
</div>

<div class="main">

  <!-- ── COL FORM ── -->
  <div class="col-form">

    <?php if ($msgGen): ?>
      <div class="<?= $msgGen['ok'] ? 'alert-success' : 'alert-error' ?>">
        <?= mbisedit_h($msgGen['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Bandeau critique -->
    <?php if ($nbBd === 0): ?>
      <div class="crit-bandeau ok">
        <div class="ico">✓</div>
        <div>
          <strong>Mentions légales OK</strong>
          <small><?= $nbAl ?> alerte<?= $nbAl > 1 ? 's' : '' ?> qualité.</small>
        </div>
      </div>
    <?php else: ?>
      <div class="crit-bandeau warn">
        <div class="ico">⚠</div>
        <div>
          <strong><?= $nbBd ?> mention<?= $nbBd > 1 ? 's' : '' ?> à corriger sur le bien</strong>
          <small>Tu peux quand même générer le PDF en mode test pour valider la maquette.</small>
        </div>
      </div>
      <details>
        <summary>Voir le détail des manquements</summary>
        <ul>
          <?php foreach ($critique['blocs_durs_violes'] as $b): ?>
            <li class="bd"><strong><?= mbisedit_h($b['libelle'] ?? '') ?></strong><?php if (!empty($b['detail'])): ?> — <?= mbisedit_h($b['detail']) ?><?php endif; ?></li>
          <?php endforeach; ?>
          <?php foreach ($critique['alertes'] as $a): ?>
            <li class="al"><strong><?= mbisedit_h($a['libelle'] ?? '') ?></strong><?php if (!empty($a['detail'])): ?> — <?= mbisedit_h($a['detail']) ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <h2>Personnaliser le contenu</h2>

    <form method="post" action="">
      <input type="hidden" name="action" value="regenerer">

      <div class="field">
        <label for="titre">Titre commercial</label>
        <input type="text" id="titre" name="titre_personnalise"
               value="<?= mbisedit_h($formTitre) ?>"
               placeholder="<?= mbisedit_h($suggTitre) ?>"
               maxlength="200">
        <div class="hint">Si vide, utilise la désignation du bien.</div>
      </div>

      <div class="field">
        <label for="accroche">Accroche commerciale ✨</label>
        <input type="text" id="accroche" name="accroche"
               value="<?= mbisedit_h($formAccroche) ?>"
               placeholder="ex: Le charme de l'ancien dans un écrin lumineux à deux pas des écoles"
               maxlength="500">
        <div class="hint">Phrase d'accroche affichée en grand format italique sur l'affiche. Conseil : 1 phrase sensorielle (≤ 90 chars).</div>
      </div>

      <div class="field">
        <label for="description">Description retravaillée</label>
        <textarea id="description" name="description_personnalisee" rows="6"
                  placeholder="<?= mbisedit_h(mb_substr($suggDesc, 0, 200)) ?>..."><?= mbisedit_h($formDesc) ?></textarea>
        <div class="hint">Si vide, utilise la description du bien.</div>
      </div>

      <div class="field">
        <label for="hero">Photo héro</label>
        <select id="hero" name="photo_hero_id_personnalise">
          <option value="">— auto (1ʳᵉ photo ou suggestion IA) —</option>
          <?php foreach ($photos as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $formHero === (int)$p['id'] ? 'selected' : '' ?>>
              Photo #<?= (int)$p['id'] ?><?= $p['lib'] !== '' ? ' — ' . mbisedit_h(mb_substr((string)$p['lib'], 0, 40)) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint"><?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?> disponible<?= count($photos) > 1 ? 's' : '' ?> sur ce bien.</div>
      </div>

      <div class="field">
        <label for="angle">Angle marketing</label>
        <select id="angle" name="angle_marketing">
          <?php
            $angles = ['' => '— inchangé —','famille'=>'Famille','investisseur'=>'Investisseur',
                       'premium'=>'Premium','premier_achat'=>'Premier achat','generique'=>'Générique','autre'=>'Autre'];
          ?>
          <?php foreach ($angles as $val => $lib): ?>
            <option value="<?= $val ?>" <?= $formAngle === $val ? 'selected' : '' ?>><?= mbisedit_h($lib) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="brief">Brief libre IA (optionnel)</label>
        <input type="text" id="brief" name="orientation_user"
               value="<?= mbisedit_h($support['orientation_user'] ?? '') ?>"
               placeholder="Ex: insister sur le jardin, mentionner travaux récents...">
        <div class="hint">Pris en compte au prochain calcul de score IA. N'affecte pas le PDF directement.</div>
      </div>

      <div class="actions">
        <?php if ((string)$support['statut'] === 'draft'): ?>
          <button class="btn btn-primary" type="submit">🔄 Régénérer le brouillon</button>
        <?php else: ?>
          <button class="btn btn-primary" type="submit">📄 Créer une nouvelle version</button>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= mbisedit_h($urlDashboard) ?>">Retour</a>
      </div>
    </form>

    <?php if ((string)$support['statut'] === 'draft'): ?>
      <!-- Bouton VALIDER (formulaire séparé) -->
      <form method="post" action="" style="margin-top:14px;">
        <input type="hidden" name="action" value="valider">
        <button class="btn" type="submit"
                style="width:100%; background:#1a5e36; color:#fff; padding:12px; font-size:14px;"
                onclick="return confirm('Valider cette version ? Elle sera figée dans l\'historique officiel.');">
          ✓ Valider l'affiche (figer dans l'historique)
        </button>
        <p style="font-size:11px; color:var(--muted); margin-top:6px; text-align:center;">
          Tant que le support est en brouillon, "Régénérer" met à jour le même fichier (pas de pollution d'historique).
          La validation fige la version et l'inscrit officiellement.
        </p>
      </form>
    <?php endif; ?>
  </div>

  <!-- ── COL PDF ── -->
  <div class="col-pdf">
    <div class="pdf-bar">
      <span>Aperçu — version <?= (int)$support['version'] ?> (<?= mbisedit_h($support['statut']) ?>)</span>
      <span style="opacity:0.6;">·</span>
      <a href="<?= mbisedit_h($urlPdf) ?>" target="_blank">Ouvrir dans un onglet</a>
      <span style="opacity:0.6;">·</span>
      <a href="<?= mbisedit_h($urlPdf . '&dl=1') ?>">Télécharger</a>
    </div>
    <iframe src="<?= mbisedit_h($urlPdf) ?>#toolbar=0&view=FitH" title="Aperçu PDF"></iframe>
  </div>

</div>
</body>
</html>
