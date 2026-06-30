<?php
/**
 * creancier_partage.php — Vue PUBLIQUE en LECTURE SEULE d'un dossier créancier.
 *
 * Accès par jeton (?t=TOKEN) sans authentification. Aucune action possible :
 * synthèse, montants, agenda, créanciers/intervenants, documents (noms), fil d'actualité.
 * Pas de téléchargement de pièces, pas de chat, pas de commentaire_admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/creancier_urgence_data.php';
require_once __DIR__ . '/inc/creancier_mouvement.php';
if (function_exists('gdl_documents_for_entity') === false) {
    @include_once __DIR__ . '/inc/ged_document_links.php';
}

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string)$d)) : '—';

$pdo   = $GLOBALS['pdo'];
$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));

function partage_deny(string $msg): void {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Lien indisponible</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:90px auto;text-align:center;color:#374151;">'
       . '<div style="font-size:42px;">🔒</div><h1 style="font-size:20px;">Lien indisponible</h1>'
       . '<p style="color:#6b7280;">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
    exit;
}

if ($token === '' || strlen($token) < 32) { partage_deny('Lien invalide.'); }

$st = $pdo->prepare("SELECT * FROM creancier_dossier_partage WHERE token = ? LIMIT 1");
$st->execute([$token]);
$partage = $st->fetch(PDO::FETCH_ASSOC);
if (!$partage)                                   { partage_deny('Ce lien n\'existe pas ou a été supprimé.'); }
if (!empty($partage['revoked_at']))              { partage_deny('Ce lien a été révoqué.'); }
if (!empty($partage['expires_at']) && strtotime((string)$partage['expires_at']) < time()) {
    partage_deny('Ce lien a expiré le ' . $dfr($partage['expires_at']) . '.');
}

$idDossier = (int)$partage['id_dossier'];
$std = $pdo->prepare("SELECT * FROM creancier_dossier WHERE id = ? LIMIT 1");
$std->execute([$idDossier]);
$dossier = $std->fetch(PDO::FETCH_ASSOC);
if (!$dossier) { partage_deny('Dossier introuvable.'); }

// Compteur de vues (best-effort).
try { $pdo->prepare("UPDATE creancier_dossier_partage SET nb_vues = nb_vues + 1, last_view_at = NOW() WHERE id = ?")->execute([(int)$partage['id']]); } catch (Throwable $e) {}

// ── Acteurs ──
$stl = $pdo->prepare("
    SELECT l.entity_type, l.entity_id, l.role_dossier,
           COALESCE(NULLIF(t.nom_affichage,''),NULLIF(t.raison_sociale,''),NULLIF(TRIM(CONCAT_WS(' ',t.prenom,t.nom)),''),CONCAT('Tiers #',t.id)) AS tiers_lib,
           COALESCE(NULLIF(s.raison_sociale,''),NULLIF(s.nom,'')) AS soc_lib
    FROM creancier_dossier_lien l
    LEFT JOIN tiers t    ON l.entity_type='TIERS'   AND t.id = l.entity_id
    LEFT JOIN societes s ON l.entity_type='SOCIETE' AND s.id = l.entity_id
    WHERE l.id_dossier = ? ORDER BY l.entity_type, l.role_dossier");
$stl->execute([$idDossier]);
$creanciers = []; $pros = []; $debiteurs = [];
foreach ($stl as $l) {
    $role = (string)$l['role_dossier'];
    if ($l['entity_type'] === 'TIERS' && str_starts_with($role, 'creancier')) $creanciers[] = $l;
    elseif ($l['entity_type'] === 'TIERS' && in_array($role, ['avocat','commissaire_justice','expert_comptable','gerant','gestionnaire','notaire'], true)) $pros[] = $l;
    elseif (str_contains($role, 'debit') || $role === 'groupe') $debiteurs[] = $l;
}

// ── Items (dettes) + agenda ──
$sti = $pdo->prepare("SELECT type, titre, montant, date_echeance, statut FROM creancier_dossier_item WHERE id_dossier = ? ORDER BY priorite DESC, date_echeance IS NULL, date_echeance ASC");
$sti->execute([$idDossier]); $items = $sti->fetchAll(PDO::FETCH_ASSOC);
$totalDette = 0.0;
foreach ($items as $it) { if ($it['type'] === 'DETTE' && $it['montant'] !== null) $totalDette += (float)$it['montant']; }
$agenda = function_exists('creancier_agenda') ? creancier_agenda($pdo, [$idDossier], 365) : [];

// ── Fil d'actualité ──
$stf = $pdo->prepare("
    SELECT m.message, m.created_at,
           COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.prenom,u.nom)),''), u.username, 'Intervenant') AS auteur
    FROM creancier_dossier_message m
    LEFT JOIN users u ON u.id = m.id_user
    WHERE m.id_dossier = ? AND m.canal = 'feed'
    ORDER BY m.id DESC LIMIT 200");
$stf->execute([$idDossier]); $feed = $stf->fetchAll(PDO::FETCH_ASSOC);

// ── Mouvements financiers (lecture seule) ──
$mvt       = creancier_mouvements($pdo, $idDossier);
$mvtLabels = creancier_mouvement_labels();

// ── Documents (noms seulement, pas de téléchargement public) ──
$docs = [];
if (function_exists('gdl_documents_for_entity')) {
    try { $docs = gdl_documents_for_entity($pdo, 'CREANCIER_DOSSIER', $idDossier, ['limit' => 100]); } catch (Throwable $e) { $docs = []; }
}

$statutLbl = ['actif'=>'Actif','surveillance'=>'Surveillance','clos'=>'Clos'];
$riskCol   = ['rouge'=>'#dc2626','orange'=>'#ea580c','vert'=>'#16a34a'][$dossier['niveau_risque']] ?? '#6b7280';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Dossier <?= h($dossier['code']) ?> — partage</title>
<style>
  :root { --navy:#243B5C; --or:#D4A047; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,'Segoe UI',sans-serif; background:#f4f5f7; color:#1f2937; }
  .pp-top { background:var(--navy); color:#fff; padding:18px 24px; }
  .pp-top h1 { margin:0; font-size:20px; }
  .pp-top .sub { opacity:.8; font-size:13px; margin-top:4px; }
  .pp-ro { display:inline-block; margin-top:8px; background:rgba(255,255,255,.15); border-radius:99px; padding:3px 12px; font-size:11px; font-weight:700; letter-spacing:.04em; }
  .pp-wrap { max-width:920px; margin:20px auto; padding:0 16px 60px; }
  .pp-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px 20px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,.05); }
  .pp-card h2 { margin:0 0 12px; font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
  .pp-kpis { display:flex; gap:24px; flex-wrap:wrap; }
  .pp-kpi-val { font-size:22px; font-weight:800; color:var(--navy); }
  .pp-kpi-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; }
  .pp-row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px solid #f1f1f3; font-size:13.5px; }
  .pp-row:last-child { border-bottom:none; }
  .pp-pill { font-size:10px; padding:2px 8px; border-radius:99px; background:#eef1f6; color:#4878a6; font-weight:700; }
  .pp-date { font-family:ui-monospace,monospace; font-weight:700; color:#4878a6; }
  .pp-date.retard { color:#dc2626; }
  .pp-feed-item { padding:10px 0; border-bottom:1px solid #f1f1f3; }
  .pp-feed-item:last-child { border-bottom:none; }
  .pp-feed-head { font-size:12px; color:#6b7280; margin-bottom:3px; }
  .pp-feed-head b { color:var(--navy); }
  .pp-feed-msg { font-size:13.5px; white-space:pre-line; }
  .pp-empty { color:#9ca3af; font-style:italic; font-size:13px; }
  .pp-foot { text-align:center; color:#9ca3af; font-size:11px; margin-top:24px; }
  .pp-syn { font-size:14px; line-height:1.55; white-space:pre-line; color:#374151; }
</style>
</head>
<body>
<div class="pp-top">
  <h1>⚖️ <?= h($dossier['libelle'] ?: $dossier['code']) ?></h1>
  <div class="sub"><?= h($dossier['code']) ?><?= $dossier['numero_dossier_adverse'] ? ' · n° ' . h($dossier['numero_dossier_adverse']) : '' ?>
    · <span style="color:<?= $riskCol ?>;font-weight:700;background:#fff;padding:1px 8px;border-radius:6px;"><?= h(ucfirst((string)$dossier['niveau_risque'])) ?></span>
    · <?= h($statutLbl[$dossier['statut']] ?? $dossier['statut']) ?></div>
  <span class="pp-ro">🔒 Lecture seule · lien partagé</span>
</div>

<div class="pp-wrap">

  <div class="pp-card">
    <h2>💰 Synthèse</h2>
    <div class="pp-kpis" style="margin-bottom:14px;">
      <div><div class="pp-kpi-val" style="color:#dc2626;"><?= $eur($totalDette) ?></div><div class="pp-kpi-lbl">Dette totale</div></div>
      <div><div class="pp-kpi-val"><?= count($creanciers) ?></div><div class="pp-kpi-lbl">Créanciers</div></div>
      <div><div class="pp-kpi-val"><?= count($docs) ?></div><div class="pp-kpi-lbl">Documents</div></div>
    </div>
    <div class="pp-syn"><?= $dossier['synthese'] ? h($dossier['synthese']) : '<span class="pp-empty">Aucune synthèse.</span>' ?></div>
  </div>

  <?php if ($debiteurs || $creanciers || $pros): ?>
  <div class="pp-card">
    <h2>🏛️ Acteurs du dossier</h2>
    <?php foreach ($debiteurs as $d0): ?>
      <div class="pp-row"><span><b><?= h($d0['soc_lib'] ?: $d0['tiers_lib']) ?></b></span><span class="pp-pill">débiteur</span></div>
    <?php endforeach; ?>
    <?php foreach ($creanciers as $c): ?>
      <div class="pp-row"><span><?= h($c['tiers_lib']) ?></span><span class="pp-pill" style="background:#fef2f2;color:#b91c1c;">créancier</span></div>
    <?php endforeach; ?>
    <?php foreach ($pros as $p): ?>
      <div class="pp-row"><span><?= h($p['tiers_lib']) ?></span><span class="pp-pill" style="background:#f5f3ff;color:#6d28d9;"><?= h($p['role_dossier']) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="pp-card">
    <h2>📅 Agenda — échéances</h2>
    <?php if (!$agenda): ?><div class="pp-empty">Aucune échéance.</div><?php endif; ?>
    <?php foreach ($agenda as $ev): ?>
      <div class="pp-row"><span><span class="pp-pill"><?= h($ev['type']) ?></span> <?= h($ev['libelle']) ?></span>
        <span class="pp-date <?= $ev['retard'] ? 'retard' : '' ?>"><?= $dfr($ev['date']) ?></span></div>
    <?php endforeach; ?>
  </div>

  <?php if ($items): ?>
  <div class="pp-card">
    <h2>⚖️ Dettes &amp; procédures</h2>
    <?php foreach ($items as $it): ?>
      <div class="pp-row"><span><span class="pp-pill"><?= h(strtolower((string)$it['type'])) ?></span> <?= h($it['titre']) ?></span>
        <span><?= $it['montant'] !== null ? '<b>' . $eur($it['montant']) . '</b>' : '' ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($mvt['rows']): ?>
  <div class="pp-card">
    <h2>💶 Mouvements financiers</h2>
    <div class="pp-kpis" style="margin-bottom:12px;">
      <?php foreach ($mvtLabels as $k => $lbl): if (($mvt['totaux'][$k] ?? 0) <= 0) continue; ?>
        <div><div class="pp-kpi-val" style="font-size:17px;"><?= $eur($mvt['totaux'][$k]) ?></div><div class="pp-kpi-lbl"><?= h($lbl) ?></div></div>
      <?php endforeach; ?>
      <div><div class="pp-kpi-val" style="font-size:17px;color:#243B5C;"><?= $eur($mvt['total_general']) ?></div><div class="pp-kpi-lbl">Total décaissé</div></div>
    </div>
    <?php foreach ($mvt['rows'] as $m): ?>
      <div class="pp-row">
        <span><span class="pp-pill"><?= h($mvtLabels[$m['type']] ?? $m['type']) ?></span>
          <?= $m['date_mouvement'] ? ' · ' . $dfr($m['date_mouvement']) : '' ?>
          <?= $m['beneficiaire'] ? ' · ' . h($m['beneficiaire']) : '' ?>
          <?= $m['reference'] ? ' · ' . h($m['reference']) : '' ?></span>
        <strong><?= $eur($m['montant']) ?></strong>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="pp-card">
    <h2>📣 Fil d'actualité</h2>
    <?php if (!$feed): ?><div class="pp-empty">Aucun message.</div><?php endif; ?>
    <?php foreach ($feed as $f): ?>
      <div class="pp-feed-item">
        <div class="pp-feed-head"><b><?= h($f['auteur']) ?></b> · <?= h(date('d/m/Y H:i', strtotime((string)$f['created_at']))) ?></div>
        <div class="pp-feed-msg"><?= h($f['message']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($docs): ?>
  <div class="pp-card">
    <h2>📂 Documents (<?= count($docs) ?>)</h2>
    <div class="pp-empty" style="margin-bottom:8px;">Liste des pièces du dossier. Téléchargement non disponible via le lien public.</div>
    <?php foreach ($docs as $d): ?>
      <div class="pp-row"><span>📄 <?= h($d['name_display'] ?? $d['name_file'] ?? ('Doc #' . ($d['id'] ?? '?'))) ?></span>
        <span style="color:#9ca3af;font-size:11px;"><?= isset($d['created_at']) ? h(date('d/m/y', strtotime((string)$d['created_at']))) : '' ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="pp-foot">Document partagé en lecture seule · MaBoxImmo · <?= h(date('d/m/Y')) ?></div>
</div>
</body>
</html>
