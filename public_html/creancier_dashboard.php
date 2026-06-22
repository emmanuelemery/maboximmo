<?php
/**
 * creancier_dashboard.php — Cockpit d'un dossier CRÉANCIERS (lecture seule).
 *
 * "Comprendre le dossier en 30 secondes" : bandeau risque + KPIs (reste dû, net bloqué,
 * trésorerie captée, prochaine butoir), retards en tête, acteurs par rôle, biens/sociétés
 * liés, saisies actives, items (dettes/risques/décisions), docs GED, analyses IA à valider.
 *
 * N'écrit RIEN. Agrège l'existant via creancier_urgence_data() + creancier_dossier_lien.
 * Accès protégé par l'ACL (creancier_dossier_acces) + filtrage tenant.
 *
 * Usage : /creancier_dashboard.php?id_dossier=1
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

$pdo       = $GLOBALS['pdo'];
$idDossier = (int)($_GET['id_dossier'] ?? 0);

// ── En-tête dossier ──────────────────────────────────────────────────
$std = $pdo->prepare("SELECT * FROM creancier_dossier WHERE id = ? LIMIT 1");
$std->execute([$idDossier]);
$dossier = $std->fetch(PDO::FETCH_ASSOC);

if (!$dossier) { http_response_code(404); exit('Dossier introuvable.'); }

$data = creancier_urgence_data($pdo, $idDossier);
if (!$data['acces']) { http_response_code(403); exit('Accès non autorisé à ce dossier.'); }

// ── Liens : acteurs (tiers), biens, sociétés ─────────────────────────
$stl = $pdo->prepare("
    SELECT l.entity_type, l.entity_id, l.role_dossier, l.note,
           COALESCE(NULLIF(t.nom_affichage,''), NULLIF(t.raison_sociale,''), NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),''), CONCAT('Tiers #', t.id)) AS tiers_lib,
           t.email AS tiers_email, t.telephone AS tiers_tel, t.mobile AS tiers_mobile,
           COALESCE(NULLIF(s.raison_sociale,''), NULLIF(s.nom,'')) AS soc_lib,
           COALESCE(NULLIF(b.reference_bien,''), NULLIF(b.bien_titre_affiche,'')) AS bien_ref
    FROM creancier_dossier_lien l
    LEFT JOIN tiers t    ON l.entity_type='TIERS'   AND t.id = l.entity_id
    LEFT JOIN societes s ON l.entity_type='SOCIETE' AND s.id = l.entity_id
    LEFT JOIN biens b    ON l.entity_type='BIEN'    AND b.id = l.entity_id
    WHERE l.id_dossier = ?
    ORDER BY l.entity_type, l.role_dossier
");
$stl->execute([$idDossier]);
$acteurs = []; $sociétés = []; $biens = [];
foreach ($stl as $l) {
    if ($l['entity_type'] === 'TIERS') {
        $acteurs[] = $l;
    } elseif ($l['entity_type'] === 'SOCIETE') {
        $sociétés[] = $l;
    } elseif ($l['entity_type'] === 'BIEN') {
        $biens[] = $l;
    }
}

// ── Items propres au dossier ─────────────────────────────────────────
$sti = $pdo->prepare("SELECT * FROM creancier_dossier_item WHERE id_dossier = ? ORDER BY priorite DESC, date_echeance IS NULL, date_echeance ASC");
$sti->execute([$idDossier]);
$items = $sti->fetchAll(PDO::FETCH_ASSOC);

// ── Analyses IA à valider ────────────────────────────────────────────
$sta = $pdo->prepare("SELECT id, type_doc, extr_creancier_nom, extr_numero_dossier, extr_montant_total, confidence, review_flags, created_at FROM creancier_doc_analyse WHERE id_dossier = ? AND statut='a_valider' ORDER BY created_at DESC");
$sta->execute([$idDossier]);
$analyses = $sta->fetchAll(PDO::FETCH_ASSOC);

// ── Docs GED liés (best effort) ──────────────────────────────────────
$docs = [];
$gedFile = __DIR__ . '/inc/ged_document_links.php';
if (is_file($gedFile)) {
    require_once $gedFile;
    if (function_exists('gdl_documents_for_entity')) {
        try { $docs = gdl_documents_for_entity($pdo, 'CREANCIER_DOSSIER', $idDossier, ['limit' => 8]); }
        catch (Throwable $e) { $docs = []; }
    }
}

// ── Charte risque ────────────────────────────────────────────────────
$riskColor = ['vert' => '#16a34a', 'orange' => '#ea580c', 'rouge' => '#dc2626'][$dossier['niveau_risque']] ?? '#ea580c';
$statutLbl = ['actif' => 'Actif', 'surveillance' => 'Surveillance', 'clos' => 'Clos'][$dossier['statut']] ?? $dossier['statut'];

// ── Layout ───────────────────────────────────────────────────────────
$layout_title   = 'Créanciers · ' . ($dossier['libelle'] ?: $dossier['code']);
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#dc2626">' . $eur($data['reste_du']) . '</div><div class="ph-kpi-lbl">Reste dû</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">' . $eur($data['total_net_bloque']) . '</div><div class="ph-kpi-lbl">Net bloqué</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">' . $eur($data['tresorerie_captee_mensuelle']) . '</div><div class="ph-kpi-lbl">Capté / mois</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:' . ($data['butoirs_en_retard'] ? '#dc2626' : '#3a7a6a') . '">' . (count($data['butoirs_en_retard']) ?: $dfr($data['prochaine_butoir'])) . '</div><div class="ph-kpi-lbl">' . ($data['butoirs_en_retard'] ? 'Butoirs en retard' : 'Prochaine butoir') . '</div></div>
';

$layout_extra_css = <<<'CSS'
<style>
.cre-wrap { padding: 18px; display: grid; gap: 16px; }
.cre-band { display:flex; align-items:center; gap:14px; padding:14px 18px; border-radius:12px; background:#fff; border:1px solid #e6e1d8; border-left:6px solid var(--risk); }
.cre-pastille { width:14px; height:14px; border-radius:50%; background:var(--risk); box-shadow:0 0 0 4px color-mix(in srgb, var(--risk) 20%, transparent); }
.cre-band h2 { margin:0; font-size:18px; font-family:'Sora',sans-serif; color:#243B5C; }
.cre-tag { font-size:11px; font-weight:600; padding:3px 9px; border-radius:999px; background:#f1ede5; color:#6b6358; }
.cre-synth { color:#5b6470; font-size:13px; margin-top:4px; }
.cre-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; }
.cre-card { background:#fff; border:1px solid #e6e1d8; border-radius:12px; padding:14px 16px; }
.cre-card h3 { margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:#8a8680; font-family:'Sora',sans-serif; }
.cre-row { display:flex; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px dashed #efeae1; font-size:13px; }
.cre-row:last-child { border-bottom:none; }
.cre-row .muted { color:#9aa0a8; font-size:12px; }
.cre-retard { color:#dc2626; font-weight:600; }
.cre-pill { font-size:11px; padding:2px 8px; border-radius:999px; background:#eef1f6; color:#4878a6; font-weight:600; }
.cre-pill.warn { background:#fef3c7; color:#92400e; }
table.cre-tbl { width:100%; border-collapse:collapse; font-size:13px; }
table.cre-tbl th { text-align:left; color:#8a8680; font-weight:600; font-size:11px; text-transform:uppercase; padding:6px 8px; border-bottom:1px solid #e6e1d8; }
table.cre-tbl td { padding:7px 8px; border-bottom:1px solid #f3efe8; }
.cre-empty { color:#aab; font-size:13px; font-style:italic; padding:6px 0; }
</style>
CSS;

ob_start();
?>
<div class="cre-wrap" style="--risk: <?= $riskColor ?>;">

  <!-- Bandeau -->
  <div class="cre-band">
    <span class="cre-pastille"></span>
    <div style="flex:1">
      <h2><?= h($dossier['libelle']) ?> <span class="cre-tag"><?= h($dossier['code']) ?></span> <span class="cre-tag"><?= h($statutLbl) ?></span></h2>
      <?php if (!empty($dossier['synthese'])): ?><div class="cre-synth"><?= h($dossier['synthese']) ?></div><?php endif; ?>
      <?php if (!empty($dossier['numero_dossier_adverse'])): ?><div class="cre-synth">N° dossier : <?= h($dossier['numero_dossier_adverse']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Retards (priorité absolue) -->
  <?php if ($data['butoirs_en_retard']): ?>
  <div class="cre-card" style="border-left:6px solid #dc2626">
    <h3 style="color:#dc2626">⚠️ Butoirs en retard</h3>
    <?php foreach ($data['butoirs_en_retard'] as $r): ?>
      <div class="cre-row"><span><?= h($r['creancier']) ?> · <span class="muted"><?= h($r['type_saisie']) ?></span></span>
        <span class="cre-retard"><?= $dfr($r['date_butoir']) ?> (J<?= (int)$r['jours'] ?>)</span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="cre-grid">

    <!-- Saisies par créancier -->
    <div class="cre-card">
      <h3>Saisies par créancier</h3>
      <?php if (!$data['saisies_par_creancier']): ?><div class="cre-empty">Aucune saisie active.</div><?php endif; ?>
      <?php foreach ($data['saisies_par_creancier'] as $c): ?>
        <div class="cre-row"><span><?= h($c['libelle']) ?> <span class="muted">(<?= (int)$c['nb'] ?>)</span></span>
          <span><strong><?= $eur($c['total_net']) ?></strong> · <span class="muted"><?= $dfr($c['prochaine_butoir']) ?></span></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Saisies par cible -->
    <div class="cre-card">
      <h3>Cibles des saisies</h3>
      <?php if (!$data['saisies_par_cible']): ?><div class="cre-empty">—</div><?php endif; ?>
      <?php foreach ($data['saisies_par_cible'] as $t): ?>
        <div class="cre-row"><span><span class="cre-pill"><?= h($t['cible_type']) ?></span> <?= h($t['libelle'] ?: ('#' . $t['cible_id'])) ?></span>
          <span><strong><?= $eur($t['total_net']) ?></strong><?= $t['loyer_capte'] > 0 ? ' · ' . $eur($t['loyer_capte']) . '/m' : '' ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Acteurs -->
    <div class="cre-card">
      <h3>Acteurs</h3>
      <?php if (!$acteurs): ?><div class="cre-empty">Aucun acteur lié.</div><?php endif; ?>
      <?php foreach ($acteurs as $a): ?>
        <div class="cre-row"><span><?= h($a['tiers_lib']) ?> <?php if ($a['role_dossier']): ?><span class="cre-pill"><?= h($a['role_dossier']) ?></span><?php endif; ?></span>
          <span class="muted"><?= h($a['tiers_email'] ?: $a['tiers_mobile'] ?: $a['tiers_tel'] ?: '') ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Sociétés & biens liés -->
    <div class="cre-card">
      <h3>Sociétés & biens</h3>
      <?php if (!$sociétés && !$biens): ?><div class="cre-empty">—</div><?php endif; ?>
      <?php foreach ($sociétés as $s): ?>
        <div class="cre-row"><span><span class="cre-pill">SOCIÉTÉ</span> <?= h($s['soc_lib'] ?: ('#' . $s['entity_id'])) ?></span>
          <span class="muted"><?= h($s['role_dossier']) ?></span></div>
      <?php endforeach; ?>
      <?php foreach ($biens as $b): ?>
        <div class="cre-row"><span><span class="cre-pill">BIEN</span> <?= h($b['bien_ref'] ?: ('#' . $b['entity_id'])) ?></span>
          <span class="muted"><?= h($b['role_dossier']) ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Items dossier -->
    <div class="cre-card">
      <h3>Dettes · risques · décisions</h3>
      <?php if (!$items): ?><div class="cre-empty">Aucun item.</div><?php endif; ?>
      <?php foreach ($items as $it): ?>
        <div class="cre-row"><span><span class="cre-pill"><?= h($it['type']) ?></span> <?= h($it['titre']) ?>
          <?php if ($it['date_echeance']): ?><span class="muted"> · <?= $dfr($it['date_echeance']) ?></span><?php endif; ?></span>
          <span><?= $it['montant'] !== null ? '<strong>' . $eur($it['montant']) . '</strong>' : '' ?></span></div>
      <?php endforeach; ?>
    </div>

    <!-- Documents GED -->
    <div class="cre-card">
      <h3>Documents (GED)</h3>
      <?php if (!$docs): ?><div class="cre-empty">Aucun document lié.</div><?php endif; ?>
      <?php foreach ($docs as $d): ?>
        <div class="cre-row"><span><?= h($d['name_display'] ?? $d['name_file'] ?? ('Doc #' . ($d['id'] ?? '?'))) ?></span>
          <span class="muted"><?= h($d['document_type'] ?? '') ?></span></div>
      <?php endforeach; ?>
    </div>

  </div>

  <!-- Analyses IA à valider -->
  <?php if ($analyses): ?>
  <div class="cre-card" style="border-left:6px solid #eab308">
    <h3>📄 Analyses IA à valider (<?= count($analyses) ?>)</h3>
    <table class="cre-tbl">
      <tr><th>Type</th><th>Créancier extrait</th><th>N° dossier</th><th>Montant</th><th>Confiance</th><th>Alertes</th></tr>
      <?php foreach ($analyses as $an): ?>
      <tr>
        <td><span class="cre-pill"><?= h($an['type_doc']) ?></span></td>
        <td><?= h($an['extr_creancier_nom']) ?></td>
        <td><?= h($an['extr_numero_dossier']) ?></td>
        <td><?= $an['extr_montant_total'] !== null ? $eur($an['extr_montant_total']) : '—' ?></td>
        <td><?= (int)round((float)$an['confidence'] * 100) ?> %</td>
        <td><?= $an['review_flags'] ? '<span class="cre-pill warn">' . count(explode("\n", (string)$an['review_flags'])) . ' alerte(s)</span>' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
