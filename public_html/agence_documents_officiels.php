<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * agence_documents_officiels.php — Page de gestion des docs officiels
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Liste des 5 types de docs officiels d'une agence avec :
 *   - statut couleur (vert > 120j / jaune 60-120j / rouge < 60j ou expiré)
 *   - upload direct par type → OCR + replique automatique vers agences.*
 *   - historique des versions (1 actif + N remplacés)
 *
 * Accès :
 *   - super admin (role=1) : choisit l'agence via ?id_agence=X
 *   - autres rôles : voit l'agence courante (id_agence en session)
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo          = $GLOBALS['pdo'];
$userId       = (int)($_SESSION['user_id']    ?? 0);
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$agenceIdSess = (int)($_SESSION['id_agence']  ?? 0);
$isSuperAdmin = (int)($_SESSION['id_role']    ?? 0) === 1;

// Sélection de l'agence
$idAgence = $isSuperAdmin && isset($_GET['id_agence']) && ctype_digit((string)$_GET['id_agence'])
    ? (int)$_GET['id_agence']
    : $agenceIdSess;

if ($idAgence <= 0) {
    http_response_code(400);
    exit('<h1>400 — Aucune agence sélectionnée.</h1>');
}

// Charge l'agence
try {
    $st = $pdo->prepare("SELECT * FROM agences WHERE id = ? LIMIT 1");
    $st->execute([$idAgence]);
    $agence = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable) { $agence = null; }
if (!$agence) {
    http_response_code(404);
    exit('<h1>404 — Agence introuvable.</h1>');
}
if (!$isSuperAdmin && $societeId > 0 && (int)($agence['id_societe'] ?? 0) !== $societeId) {
    http_response_code(403);
    exit('<h1>403 — Hors scope société.</h1>');
}

// Charge les docs actifs + historique
try {
    $st = $pdo->prepare("
        SELECT *
        FROM agences_documents_officiels
        WHERE id_agence = :a
        ORDER BY type_document, id DESC
    ");
    $st->execute([':a' => $idAgence]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $docs = []; }

// Group by type
$byType = [];
foreach ($docs as $d) {
    $byType[$d['type_document']][] = $d;
}

$types = [
    'carte_pro'         => ['label' => '📇 Carte professionnelle', 'desc' => 'Carte T transaction et/ou G gestion délivrée par la CCI'],
    'kbis'              => ['label' => '📋 Extrait KBIS',          'desc' => 'Extrait du registre du commerce, ≤ 3 mois'],
    'garant_financier'  => ['label' => '🏛️ Garant financier',      'desc' => 'Attestation Galian, Socaf, etc. avec plafond et validité'],
    'rc_pro'            => ['label' => '🛡️ Assurance RC pro',      'desc' => 'Attestation responsabilité civile professionnelle'],
    'bareme_honoraires' => ['label' => '💶 Barème honoraires',     'desc' => 'PDF du barème affiché en agence (loi Hoguet)'],
];

$today = new DateTimeImmutable('today');
$statutCouleur = static function (?string $dateValidite) use ($today): array {
    if (empty($dateValidite)) return ['couleur' => '#9ca3af', 'fond' => '#f3f4f6', 'texte' => 'Non renseigné', 'jours' => null];
    try {
        $dt = new DateTimeImmutable($dateValidite);
    } catch (Throwable) {
        return ['couleur' => '#9ca3af', 'fond' => '#f3f4f6', 'texte' => 'Date illisible', 'jours' => null];
    }
    $diff = (int)$today->diff($dt)->format('%r%a');
    if ($diff < 0)        return ['couleur' => '#7a1d1d', 'fond' => '#fee2e2', 'texte' => 'Expiré il y a ' . abs($diff) . ' j', 'jours' => $diff];
    if ($diff <= 60)      return ['couleur' => '#7c2d12', 'fond' => '#fed7aa', 'texte' => 'Expire dans ' . $diff . ' j', 'jours' => $diff];
    if ($diff <= 120)     return ['couleur' => '#854d0e', 'fond' => '#fef3c7', 'texte' => 'Expire dans ' . $diff . ' j', 'jours' => $diff];
    return ['couleur' => '#065f46', 'fond' => '#d1fae5', 'texte' => 'Valide ' . $diff . ' j', 'jours' => $diff];
};

$pageTitle = 'Documents officiels — ' . htmlspecialchars((string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence'));
include __DIR__ . '/inc/agency_layout_top.php';

// CSRF token (form_id partagé "ajouter_bien" — pattern du site)
require_once __DIR__ . '/inc/csrf.php';
$csrfToken = csrf_token('ajouter_bien');
?>

<main style="max-width:1080px; margin:24px auto; padding:0 24px;">

  <header style="margin-bottom:24px;">
    <h1 style="font-size:24px; font-weight:700; color:#243B5C; margin:0 0 4px;">📁 Documents officiels</h1>
    <p style="color:#6b7280; font-size:14px; margin:0;">
      <?= htmlspecialchars((string)($agence['nom_agence'] ?? $agence['nom'] ?? '')) ?>
      — Source unique : 1 doc validé pour 5 supports (affiches, fiches, mentions légales)
    </p>
  </header>

  <?php foreach ($types as $typeKey => $tInfo):
    $rows  = $byType[$typeKey] ?? [];
    $actif = null;
    foreach ($rows as $r) { if ($r['statut'] === 'actif') { $actif = $r; break; } }
    $st = $statutCouleur($actif['date_validite'] ?? null);
  ?>
    <section style="background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px 24px; margin-bottom:16px;">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:12px;">
        <div>
          <div style="font-size:16px; font-weight:700; color:#243B5C;"><?= $tInfo['label'] ?></div>
          <div style="font-size:12px; color:#6b7280; margin-top:2px;"><?= htmlspecialchars($tInfo['desc']) ?></div>
        </div>
        <div style="background:<?= $st['fond'] ?>; color:<?= $st['couleur'] ?>; padding:6px 14px; border-radius:999px; font-size:12px; font-weight:700; white-space:nowrap;">
          <?= htmlspecialchars($st['texte']) ?>
        </div>
      </div>

      <?php if ($actif): ?>
        <div style="background:#f9fafb; border-radius:8px; padding:12px 14px; margin-bottom:12px; font-size:13px; color:#374151;">
          <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
            <?php if (!empty($actif['numero'])): ?><div><strong>N° :</strong> <?= htmlspecialchars((string)$actif['numero']) ?></div><?php endif; ?>
            <?php if (!empty($actif['emetteur'])): ?><div><strong>Émetteur :</strong> <?= htmlspecialchars((string)$actif['emetteur']) ?></div><?php endif; ?>
            <?php if (!empty($actif['date_validite'])): ?><div><strong>Validité :</strong> <?= htmlspecialchars((string)$actif['date_validite']) ?></div><?php endif; ?>
            <?php if (!empty($actif['date_emission'])): ?><div><strong>Émis le :</strong> <?= htmlspecialchars((string)$actif['date_emission']) ?></div><?php endif; ?>
            <?php if (!empty($actif['montant_garantie'])): ?><div><strong>Plafond :</strong> <?= number_format((float)$actif['montant_garantie'], 0, ',', ' ') ?> €</div><?php endif; ?>
            <?php if (!empty($actif['ocr_confidence'])): ?><div><strong>Confiance OCR :</strong> <?= (int)$actif['ocr_confidence'] ?>%</div><?php endif; ?>
          </div>
          <?php if (!empty($actif['fichier_path'])): ?>
            <div style="margin-top:8px;">
              <a href="<?= htmlspecialchars((string)$actif['fichier_path']) ?>" target="_blank"
                 style="font-size:12px; color:#243B5C; font-weight:600;">📄 Voir le document</a>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data"
            data-doc-type="<?= htmlspecialchars($typeKey) ?>"
            class="js-doc-upload-form"
            style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken) ?>">
        <input type="hidden" name="id_agence" value="<?= (int)$idAgence ?>">
        <input type="hidden" name="type_document" value="<?= htmlspecialchars($typeKey) ?>">
        <input type="file" name="fichier" required
               accept=".pdf,image/jpeg,image/png,image/webp"
               style="font-size:13px;">
        <button type="submit"
                style="padding:8px 18px; background:#243B5C; color:#fff; border:0; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer;">
          📤 Uploader & analyser (Sonnet)
        </button>
        <span class="js-doc-upload-msg" style="font-size:12px; color:#6b7280;"></span>
      </form>

      <?php
      $historique = array_filter($rows, fn($r) => $r['statut'] !== 'actif');
      if (!empty($historique)): ?>
        <details style="margin-top:10px;">
          <summary style="font-size:12px; color:#6b7280; cursor:pointer;">Historique (<?= count($historique) ?> version<?= count($historique) > 1 ? 's' : '' ?> remplacée<?= count($historique) > 1 ? 's' : '' ?>)</summary>
          <ul style="margin:8px 0 0 0; padding-left:20px; font-size:12px; color:#6b7280;">
          <?php foreach ($historique as $h): ?>
            <li>
              <?= htmlspecialchars((string)$h['created_at']) ?> —
              <?= htmlspecialchars((string)$h['statut']) ?>
              <?php if (!empty($h['fichier_path'])): ?>
                — <a href="<?= htmlspecialchars((string)$h['fichier_path']) ?>" target="_blank">voir</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

</main>

<script>
document.querySelectorAll('.js-doc-upload-form').forEach(form => {
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const msg = form.querySelector('.js-doc-upload-msg');
    const btn = form.querySelector('button[type=submit]');
    msg.textContent = '⏳ Upload + analyse Sonnet (~5s)…';
    msg.style.color = '#6b7280';
    btn.disabled = true;

    try {
      const fd = new FormData(form);
      const r = await fetch('api/agence_doc_officiel_upload.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Erreur');

      let txt = '✅ Sauvegardé. ';
      if (j.ocr_ok) {
        txt += `OCR : ${j.ocr.numero || '-'} · validité ${j.ocr.date_validite || '?'} (${j.ocr.confidence}%)`;
      } else {
        txt += 'OCR indisponible : ' + (j.ocr_erreur || '?') + ' — saisie manuelle requise.';
      }
      msg.textContent = txt;
      msg.style.color = j.ocr_ok ? '#065f46' : '#9a3412';
      setTimeout(() => location.reload(), 1500);
    } catch (err) {
      msg.textContent = '❌ ' + err.message;
      msg.style.color = '#991b1b';
      btn.disabled = false;
    }
  });
});
</script>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
