<?php
declare(strict_types=1);

/**
 * Diagnostic d'un bien pour les supports commerciaux : vérifie en un coup
 * d'œil que bien + photos + agence + prix sont OK avant de générer
 * les 4 affiches vitrine.
 *
 * Usage : /admin/admin_bien_diag.php?id=886
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$pdo = $GLOBALS['pdo'];
$idBien = (int)($_GET['id'] ?? 0);
if ($idBien <= 0) {
    exit('<p style="font-family:sans-serif;padding:24px;">Usage : <code>?id=886</code></p>');
}

// Bien
$bien = [];
try {
    $st = $pdo->prepare("SELECT * FROM biens WHERE id = :id LIMIT 1");
    $st->execute([':id' => $idBien]);
    $bien = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $bienErr = $e->getMessage();
}

// Photos
$photos = [];
try {
    $st = $pdo->prepare("SELECT * FROM biens_photos WHERE id_bien = :id ORDER BY ordre ASC, id ASC");
    $st->execute([':id' => $idBien]);
    $photos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// Photos résolues sur disque
$photosResolved = [];
if (function_exists('mbi_supports_resoudre_photo_path')) {
    foreach ($photos as $p) {
        $abs = mbi_supports_resoudre_photo_path($p);
        $photosResolved[] = ['photo' => $p, 'path' => $abs, 'exists' => $abs !== null];
    }
} else {
    require_once __DIR__ . '/../inc/mbi_supports_helpers.php';
    foreach ($photos as $p) {
        $abs = mbi_supports_resoudre_photo_path($p);
        $photosResolved[] = ['photo' => $p, 'path' => $abs, 'exists' => $abs !== null];
    }
}

// Agence — chargement enrichi avec colonnes officielles depuis societes
require_once __DIR__ . '/../inc/agence_load_with_societe_docs.php';
$agence = [];
if (!empty($bien['id_agence'])) {
    $agence = agence_load_with_societe_docs($pdo, (int)$bien['id_agence']) ?: [];
}

// Annonce
$annonce = null;
try {
    $st = $pdo->prepare("SELECT id, titre, titre_ia, description, texte_ia, statut FROM annonces WHERE id_bien = :id ORDER BY id DESC LIMIT 1");
    $st->execute([':id' => $idBien]);
    $annonce = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

// Critique IA (si dispo)
$critique = null;
try {
    require_once __DIR__ . '/../inc/mbi_supports_critic_engine.php';
    $critique = mbi_supports_critic_check($idBien, 'affiche_vitrine');
} catch (Throwable $e) {
    $critiqueErr = $e->getMessage();
}

// Détection location vs vente : on regarde plusieurs indices et la nature du mandat / annonce.
$prixVente = (float)($bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0);
$loyer     = (float)($bien['loyer'] ?? $bien['loyer_hc'] ?? $bien['loyer_cc'] ?? 0);
$charges   = (float)($bien['charges'] ?? $bien['provisions_charges'] ?? 0);
$depot     = (float)($bien['depot_garantie'] ?? 0);

$transaction = strtolower((string)(
    $bien['type_transaction']
    ?? $bien['transaction']
    ?? $bien['nature_mandat']
    ?? ''
));
if ($transaction === '') {
    // Heuristique : si on a un loyer mais pas de prix vente → location
    if ($loyer > 0 && $prixVente <= 0) $transaction = 'location';
    elseif ($prixVente > 0)             $transaction = 'vente';
    else                                $transaction = 'inconnu';
}
$isLocation = str_contains($transaction, 'loc');

// ─── Mini-test : génération PDF avec juste la photo (isolé) ──────────
$miniPdfTest = null;
if (!empty($_GET['test_pdf']) && !empty($photosResolved)) {
    $first = null;
    foreach ($photosResolved as $r) {
        if ($r['exists']) { $first = $r; break; }
    }
    if ($first) {
        try {
            require_once __DIR__ . '/../tcpdf/tcpdf.php';
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $pdf->SetCreator('Diag photo');
            $pdf->SetMargins(0, 0, 0);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetAutoPageBreak(false);
            $pdf->AddPage();

            // Tente d'insérer la photo sur toute la page
            $err = null;
            try {
                $pdf->Image($first['path'], 0, 0, 210, 297, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }

            $pdf->SetXY(10, 10);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Write(5, 'TEST PHOTO bien #' . $idBien . ' photo #' . ($first['photo']['id'] ?? '?'));
            if ($err) {
                $pdf->Ln(6);
                $pdf->Write(5, 'TCPDF Image() erreur : ' . $err);
            }

            $outDir = dirname(__DIR__) . '/uploads/supports/drafts/';
            if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
            $outName = 'diag_photo_bien_' . $idBien . '_' . date('His') . '.pdf';
            $outPath = $outDir . $outName;
            $pdf->Output($outPath, 'F');

            // détecte le base path
            $script = str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']);
            $prefix = preg_replace('#/admin/[^/]+\.php$#', '', $script);
            if ($prefix === '/' || $prefix === '') $prefix = '';

            $miniPdfTest = [
                'ok'      => is_file($outPath) && filesize($outPath) > 1000,
                'url'     => $prefix . '/uploads/supports/drafts/' . $outName,
                'taille'  => is_file($outPath) ? filesize($outPath) : 0,
                'photo_id'=> (int)($first['photo']['id'] ?? 0),
                'path_in' => $first['path'],
                'err'     => $err,
                'getimagesize' => @getimagesize($first['path']),
            ];
        } catch (Throwable $e) {
            $miniPdfTest = ['ok' => false, 'fatal' => $e->getMessage()];
        }
    }
}

$appLayout = true;
$pageTitle = 'Diag bien #' . $idBien;
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .bd { max-width: 1100px; margin: 0 auto; padding: 24px 20px; font-size: 13px; }
  .bd h1 { font-size: 22px; color:#0f172a; margin: 0 0 12px; }
  .bd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; margin-bottom:14px; }
  .bd-card h2 { font-size:14px; color:#0f172a; margin: 0 0 10px; }
  .bd-grid { display:grid; grid-template-columns: 200px 1fr; gap:6px 14px; }
  .bd-grid > div:nth-child(odd) { color:#64748b; font-weight:600; }
  .bd-ok    { color:#166534; }
  .bd-warn  { color:#92400e; }
  .bd-err   { color:#991b1b; font-weight:700; }
  .bd-photo-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; }
  .bd-photo { background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:6px; text-align:center; font-size:11px; }
  .bd-photo img { width:100%; height:120px; object-fit:cover; border-radius:6px; }
  .bd-photo .miss { background:#fef2f2; height:120px; display:flex; align-items:center; justify-content:center; color:#991b1b; border-radius:6px; }
  .bd-bigbtn { display:inline-block; padding:14px 24px; background:#243B5C; color:#fff; border-radius:10px; text-decoration:none; font-weight:700; font-size:14px; }
  .bd-bigbtn:hover { background:#1e3050; }
</style>

<div class="bd">
  <h1>🔬 Diagnostic bien #<?= $idBien ?></h1>

  <!-- Bien -->
  <div class="bd-card">
    <h2>📋 Bien</h2>
    <?php if (empty($bien)): ?>
      <div class="bd-err">Bien introuvable.</div>
    <?php else: ?>
      <div class="bd-grid">
        <div>id</div><div><?= (int)$bien['id'] ?></div>
        <div>Désignation</div><div><?= htmlspecialchars((string)($bien['designation'] ?? $bien['titre'] ?? '—')) ?></div>
        <div>Adresse</div><div><?= htmlspecialchars((string)($bien['adresse'] ?? '—')) ?> · <?= htmlspecialchars((string)($bien['code_postal'] ?? '')) ?> <?= htmlspecialchars((string)($bien['ville'] ?? '')) ?></div>
        <div>Type</div><div><?= htmlspecialchars((string)($bien['type'] ?? $bien['type_bien'] ?? '—')) ?></div>
        <div>Transaction</div><div><strong style="color:<?= $isLocation ? '#0369a1' : '#7e22ce' ?>;"><?= strtoupper(htmlspecialchars($transaction)) ?></strong></div>
        <div>Surface</div><div><?= htmlspecialchars((string)($bien['surface_habitable'] ?? $bien['surface'] ?? '—')) ?> m²</div>
        <?php if ($isLocation): ?>
          <div>Loyer</div><div class="<?= $loyer > 0 ? 'bd-ok' : 'bd-err' ?>"><?= $loyer > 0 ? number_format($loyer, 0, ',', ' ') . ' €' . ($charges > 0 ? ' + ' . number_format($charges, 0, ',', ' ') . ' € charges' : '') : '❌ NON DÉFINI' ?></div>
          <div>Dépôt de garantie</div><div class="<?= $depot > 0 ? 'bd-ok' : 'bd-warn' ?>"><?= $depot > 0 ? number_format($depot, 0, ',', ' ') . ' €' : '⚠️ vide' ?></div>
        <?php else: ?>
          <div>Prix vente</div><div class="<?= $prixVente > 0 ? 'bd-ok' : 'bd-err' ?>"><?= $prixVente > 0 ? number_format($prixVente, 0, ',', ' ') . ' €' : '❌ NON DÉFINI' ?></div>
        <?php endif; ?>
        <div>DPE / GES</div><div>DPE <?= htmlspecialchars((string)($bien['dpe_classe'] ?? '—')) ?> / GES <?= htmlspecialchars((string)($bien['ges_classe'] ?? '—')) ?></div>
        <div>id_agence</div><div><?= (int)($bien['id_agence'] ?? 0) ?></div>
        <div>id_societe</div><div><?= (int)($bien['id_societe'] ?? 0) ?></div>
        <div>Description</div><div><?= htmlspecialchars(mb_substr((string)($bien['description'] ?? '—'), 0, 200)) ?><?= mb_strlen((string)($bien['description'] ?? '')) > 200 ? '…' : '' ?></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Annonce -->
  <div class="bd-card">
    <h2>📰 Annonce active</h2>
    <?php if (empty($annonce)): ?>
      <div class="bd-warn">Pas d'annonce associée — la rédaction IA partira sur les seuls champs du bien.</div>
    <?php else: ?>
      <div class="bd-grid">
        <div>id</div><div><?= (int)$annonce['id'] ?> · statut <?= htmlspecialchars((string)$annonce['statut']) ?></div>
        <div>Titre</div><div><?= htmlspecialchars((string)($annonce['titre_ia'] ?? $annonce['titre'] ?? '—')) ?></div>
        <div>Description</div><div><?= htmlspecialchars(mb_substr((string)($annonce['description'] ?? $annonce['texte_ia'] ?? ''), 0, 300)) ?>…</div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Photos -->
  <div class="bd-card">
    <h2>🖼️ Photos (<?= count($photos) ?> en BDD, <?= count(array_filter($photosResolved, fn($r) => $r['exists'])) ?> résolues sur disque)</h2>
    <?php if (empty($photos)): ?>
      <div class="bd-err">❌ Aucune photo en BDD pour ce bien — l'affiche aura un placeholder.</div>
    <?php else: ?>
      <div class="bd-photo-grid">
        <?php foreach (array_slice($photosResolved, 0, 8) as $r):
          $p = $r['photo'];
          $url = (string)($p['url_photo'] ?? $p['url'] ?? '');
        ?>
          <div class="bd-photo">
            <?php if ($r['exists']): ?>
              <img src="/MaBoxImmo2026/public_html<?= htmlspecialchars($url[0] === '/' ? $url : '/' . $url) ?>" alt="">
            <?php else: ?>
              <div class="miss">FICHIER ABSENT</div>
            <?php endif; ?>
            <div style="margin-top:4px;font-family:monospace;font-size:10px;word-break:break-all;">
              #<?= (int)($p['id'] ?? 0) ?> · ordre <?= (int)($p['ordre'] ?? 0) ?>
              <?= ((int)($p['exploitable'] ?? 1)) === 0 ? ' · <span style="color:#991b1b;">non exploitable</span>' : '' ?>
            </div>
            <div style="font-family:monospace;font-size:9px;color:#64748b;">
              <?= htmlspecialchars(mb_substr($url, 0, 50)) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Agence -->
  <div class="bd-card">
    <h2>🏢 Agence</h2>
    <?php if (empty($agence)): ?>
      <div class="bd-warn">Pas d'agence rattachée.</div>
    <?php else: ?>
      <div class="bd-grid">
        <div>Nom</div><div><?= htmlspecialchars((string)($agence['nom_agence'] ?? '—')) ?></div>
        <div>Ville</div><div><?= htmlspecialchars((string)($agence['ville'] ?? '—')) ?></div>
        <div>Tél</div><div><?= htmlspecialchars((string)($agence['telephone'] ?? '—')) ?></div>
        <div>Carte pro</div><div class="<?= !empty($agence['carte_pro_numero']) ? 'bd-ok' : 'bd-warn' ?>"><?= htmlspecialchars((string)($agence['carte_pro_numero'] ?? '⚠️ vide')) ?></div>
        <div>Garant financier</div><div class="<?= !empty($agence['garant_financier']) ? 'bd-ok' : 'bd-warn' ?>"><?= htmlspecialchars((string)($agence['garant_financier'] ?? '⚠️ vide')) ?></div>
        <div>RC pro</div><div class="<?= !empty($agence['rc_pro']) ? 'bd-ok' : 'bd-warn' ?>"><?= htmlspecialchars((string)($agence['rc_pro'] ?? '⚠️ vide')) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Critique -->
  <div class="bd-card">
    <h2>⚖️ Critique IA</h2>
    <?php if (!empty($critiqueErr)): ?>
      <div class="bd-err">Erreur : <?= htmlspecialchars($critiqueErr) ?></div>
    <?php elseif (!is_array($critique)): ?>
      <div class="bd-warn">Critique non disponible.</div>
    <?php else:
      $peut = (bool)($critique['peut_exporter'] ?? false);
      $blocsDurs = $critique['blocs_durs_violes'] ?? [];
      $blocsMous = $critique['blocs_mous_violes'] ?? [];
    ?>
      <?php if ($peut): ?>
        <div class="bd-ok" style="font-size:14px;font-weight:700;">✅ La critique AUTORISE l'export</div>
      <?php else: ?>
        <div class="bd-err" style="font-size:14px;">❌ La critique REFUSE l'export</div>
        <p style="margin:10px 0 4px;"><strong>Blocs durs violés (bloquants) :</strong></p>
        <ul style="margin:0;color:#991b1b;">
          <?php foreach ($blocsDurs as $b): ?>
            <li><?= htmlspecialchars(is_array($b) ? json_encode($b) : (string)$b) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($blocsMous)): ?>
        <p style="margin:10px 0 4px;"><strong>Blocs mous (warnings) :</strong></p>
        <ul style="margin:0;color:#92400e;">
          <?php foreach ($blocsMous as $b): ?>
            <li><?= htmlspecialchars(is_array($b) ? json_encode($b) : (string)$b) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Mini-test PDF photo isolée -->
  <div class="bd-card">
    <h2>🧪 Test isolé : générer un PDF avec uniquement la 1ère photo</h2>
    <p style="font-size:12px;color:#64748b;margin: 0 0 10px;">
      Confirme si TCPDF arrive à intégrer une photo de ce bien. Si ce mini-PDF montre la photo, le problème vient du template (pas de TCPDF). Sinon, c'est TCPDF qui rejette l'image.
    </p>
    <a class="bd-bigbtn" href="?id=<?= $idBien ?>&test_pdf=1" style="background:#0ea5e9;">🧪 Lancer le test PDF photo</a>

    <?php if ($miniPdfTest): ?>
      <div style="margin-top:14px;padding:12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;">
        <?php if (!empty($miniPdfTest['fatal'])): ?>
          <div class="bd-err">❌ Fatal : <?= htmlspecialchars($miniPdfTest['fatal']) ?></div>
        <?php else: ?>
          <div class="bd-grid" style="font-size:12px;">
            <div>Statut</div><div><strong class="<?= $miniPdfTest['ok'] ? 'bd-ok' : 'bd-err' ?>">
              <?= $miniPdfTest['ok'] ? '✅ PDF généré (' . round($miniPdfTest['taille']/1024) . ' Ko)' : '❌ Échec ou PDF vide' ?>
            </strong></div>
            <div>Photo testée</div><div>#<?= $miniPdfTest['photo_id'] ?></div>
            <div>Path absolu</div><div style="font-family:monospace;font-size:11px;word-break:break-all;"><?= htmlspecialchars((string)$miniPdfTest['path_in']) ?></div>
            <div>getimagesize()</div>
            <div style="font-family:monospace;font-size:11px;">
              <?php $gi = $miniPdfTest['getimagesize']; ?>
              <?php if (is_array($gi)): ?>
                <?= (int)$gi[0] ?>×<?= (int)$gi[1] ?> · type <?= htmlspecialchars((string)($gi['mime'] ?? '?')) ?>
              <?php else: ?>
                <span class="bd-err">FALSE — fichier illisible par PHP</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($miniPdfTest['err'])): ?>
              <div>Erreur TCPDF</div><div class="bd-err"><?= htmlspecialchars((string)$miniPdfTest['err']) ?></div>
            <?php endif; ?>
            <div>URL ouverture</div>
            <div><a href="<?= htmlspecialchars((string)$miniPdfTest['url']) ?>" target="_blank" style="color:#0369a1;font-weight:700;">→ Ouvrir le mini-PDF</a></div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="text-align:center;margin: 30px 0;">
    <a class="bd-bigbtn" href="/MaBoxImmo2026/public_html/bien_detail.php?id=<?= $idBien ?>">
      → Aller sur la fiche bien (#<?= $idBien ?>) pour générer les affiches
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
