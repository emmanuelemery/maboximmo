<?php
/**
 * transaction_modeles.php — Bibliothèque des modèles de transaction (stockés en GED).
 *
 * Liste les ged_documents document_type='MODELE_TRANSACTION', groupés par étape.
 * Actions : consulter / télécharger (vierge). « Compléter & envoyer pour signature »
 * et « mise à dispo espace pro » = phases suivantes (commencent par le test mandat).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
$pdo = $GLOBALS['pdo'];

$rows = $pdo->query("
    SELECT id, name_display, metadata, final_destination
      FROM ged_documents
     WHERE document_type='MODELE_TRANSACTION' AND status='active'
     ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Regroupe par étape.
$groupes = ['mandat'=>[], 'visite'=>[], 'compromis'=>[], 'autre'=>[]];
foreach ($rows as $r) {
    $m = json_decode((string)($r['metadata'] ?? ''), true) ?: [];
    $r['_etape'] = $m['etape'] ?? 'autre';
    $r['_copro'] = $m['copro'] ?? null;
    $g = isset($groupes[$r['_etape']]) ? $r['_etape'] : 'autre';
    $groupes[$g][] = $r;
}
$titres = ['mandat'=>'📝 Mandats', 'visite'=>'👁️ Visite', 'compromis'=>'🤝 Avant-contrat (compromis / promesse)', 'autre'=>'📄 Autres'];

$pageTitle = 'Modèles de transaction';
$pageIcon  = '📑';
$pageSubtitle = 'Ma Box Agency · Transaction';
$idDossier = (int)($_GET['dossier'] ?? 0); // contexte dossier (optionnel)
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
.tm-wrap{max-width:1000px;margin:0 auto;padding:10px 16px 48px;}
.tm-grp{margin-bottom:26px;}
.tm-grp h2{font-size:15px;font-weight:900;color:#0f172a;margin:0 0 12px;}
.tm-list{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:760px){.tm-list{grid-template-columns:1fr;}}
.tm-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:14px 16px;display:flex;align-items:center;gap:12px;}
.tm-card .ic{font-size:26px;flex:none;}
.tm-card .body{flex:1;min-width:0;}
.tm-card .nm{font-weight:800;font-size:13.5px;color:#0f172a;}
.tm-card .tags{margin-top:3px;display:flex;gap:5px;}
.tm-badge{font-size:10px;font-weight:800;border-radius:5px;padding:1px 7px;background:#f1f5f9;color:#475569;}
.tm-badge.copro{background:#eef5fc;color:#0c5aa0;}
.tm-badge.hors{background:#fff4e6;color:#9a5a18;}
.tm-acts{display:flex;gap:6px;flex-wrap:wrap;}
.tm-btn{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:7px 11px;font-size:12px;font-weight:700;color:#0f6cbd;text-decoration:none;cursor:pointer;}
.tm-btn:hover{border-color:#0f6cbd;background:#eef5fc;}
.tm-btn.soon{color:#94a3b8;cursor:default;}
.tm-note{font-size:12px;color:#94a3b8;margin-top:6px;}
</style>

<div class="tm-wrap">
  <p class="tm-note">📦 Modèles stockés en GED (accessibles à tous). Téléchargement « vierge » disponible. « Compléter & envoyer pour signature » : prochaine étape (test sur le mandat de vente).</p>

  <?php foreach ($groupes as $etape => $items): if (!$items) continue; ?>
    <div class="tm-grp">
      <h2><?= h($titres[$etape] ?? $etape) ?></h2>
      <div class="tm-list">
        <?php foreach ($items as $r): ?>
          <div class="tm-card">
            <span class="ic">📄</span>
            <div class="body">
              <div class="nm"><?= h($r['name_display']) ?></div>
              <div class="tags">
                <?php if ($r['_copro'] === 1): ?><span class="tm-badge copro">copropriété</span>
                <?php elseif ($r['_copro'] === 0): ?><span class="tm-badge hors">hors copro</span><?php endif; ?>
              </div>
            </div>
            <div class="tm-acts">
              <a class="tm-btn" href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$r['id'])) ?>" target="_blank">👁️ Voir / imprimer</a>
              <a class="tm-btn" href="<?= h(app_url('/api/ged_doc_serve.php?id=' . (int)$r['id'] . '&dl=1')) ?>">⬇️ Vierge</a>
              <span class="tm-btn soon" title="Prochaine étape : remplissage depuis le dossier + signature">✍️ Compléter</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$rows): ?>
    <div class="tm-note">Aucun modèle importé.</div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
