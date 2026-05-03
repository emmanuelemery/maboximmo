<?php
declare(strict_types=1);

/**
 * GED — Bootstrap des dossiers depuis les tiers BDD
 *
 * Créé pour répondre à la question "comment fait-on pour créer la GED avec
 * les tiers role bailleur ?".
 *
 * Cette page :
 *   1. Compte les tiers role='proprietaire' (= bailleurs au sens MBI)
 *   2. Compte les dossiers GED déjà instanciés pour ces tiers (entity_type='tiers')
 *   3. Affiche la liste des manquants
 *   4. Bouton "Instancier les dossiers manquants" (idempotent)
 *
 * Pour chaque tiers manquant, appelle ged_instantiate_template_for_entity()
 * avec :
 *   - templateCode    = TPL_PROPRIETAIRE
 *   - parentFolderId  = id du dossier 01_PROPRIETAIRES sous 05_GESTION_LOCATIVE
 *   - entityLabel     = nom du tiers (raison sociale ou nom + prénom)
 *   - entityType      = 'tiers'
 *   - entityId        = tiers.id
 *
 * Réservée super admin (id_role = 1).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_functions.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé super admin.</h1>');
}

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$flash = null;

// ─── Trouver le dossier parent 01_PROPRIETAIRES ────────────────────
$stParent = $pdo->prepare("SELECT id FROM ged_folders WHERE slug = ? AND parent_id IS NULL OR slug = ? LIMIT 1");
$stParent->execute(['01_proprietaires', '01_proprietaires']);
$parentFolderId = (int)$pdo->query("SELECT id FROM ged_folders WHERE slug = '01_proprietaires' AND is_archived=0 LIMIT 1")->fetchColumn();

// ─── Action POST : instancier les manquants ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'instantiate' && $parentFolderId > 0) {
    $tiersToProcess = $pdo->query("
        SELECT t.id, t.id_societe,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.prenom, t.nom)), ''), t.raison_sociale, CONCAT('Tiers #', t.id)) AS label
        FROM tiers t
        INNER JOIN tiers_roles tr ON tr.id_tiers = t.id AND tr.role_code = 'proprietaire'
        LEFT JOIN ged_folders f ON f.entity_type = 'tiers' AND f.entity_id = t.id AND f.is_archived = 0
        WHERE f.id IS NULL
        ORDER BY t.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $ok = 0;
    $err = 0;
    $errors = [];
    foreach ($tiersToProcess as $row) {
        try {
            ged_instantiate_template_for_entity(
                'TPL_PROPRIETAIRE',
                $parentFolderId,
                (string)$row['label'],
                'tiers',
                (int)$row['id']
            );
            $ok++;
        } catch (Throwable $e) {
            $err++;
            $errors[] = '#' . $row['id'] . ' ' . $row['label'] . ' → ' . $e->getMessage();
        }
    }
    $flash = [
        'type' => $err === 0 ? 'success' : 'warning',
        'msg'  => "✅ {$ok} dossiers GED créés." . ($err > 0 ? " ⚠️ {$err} erreurs : " . implode(' | ', array_slice($errors, 0, 5)) : ''),
    ];
}

// ─── Stats actuelles ───────────────────────────────────────────────
$nbTiersBailleurs = (int)$pdo->query("
    SELECT COUNT(DISTINCT t.id) FROM tiers t
    INNER JOIN tiers_roles tr ON tr.id_tiers = t.id AND tr.role_code = 'proprietaire'
")->fetchColumn();

$nbGedInstanced = (int)$pdo->query("
    SELECT COUNT(*) FROM ged_folders
    WHERE entity_type = 'tiers' AND is_archived = 0 AND parent_id = " . (int)$parentFolderId . "
")->fetchColumn();

$nbMissing = $nbTiersBailleurs - $nbGedInstanced;

// ─── Liste des manquants (preview, max 20) ─────────────────────────
$missing = [];
if ($parentFolderId > 0) {
    $missing = $pdo->query("
        SELECT t.id, t.id_societe,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.prenom, t.nom)), ''), t.raison_sociale, CONCAT('Tiers #', t.id)) AS label
        FROM tiers t
        INNER JOIN tiers_roles tr ON tr.id_tiers = t.id AND tr.role_code = 'proprietaire'
        LEFT JOIN ged_folders f ON f.entity_type = 'tiers' AND f.entity_id = t.id AND f.is_archived = 0
        WHERE f.id IS NULL
        ORDER BY t.id ASC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle    = 'GED — Bootstrap tiers bailleurs';
$pageSubtitle = 'Ma GED Box · Création des dossiers depuis les tiers';
$layoutSidebar = 'sidebar_ged';
require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<style>
  .gbt-wrap { max-width: 1100px; }
  .gbt-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 22px; }
  .gbt-stat {
    background: #fff; padding: 18px 22px; border-radius: 12px;
    box-shadow: 4px 4px 14px #c8c4be, -4px -4px 14px #fff;
  }
  .gbt-stat-label { font-size: 11px; text-transform: uppercase; color: #9a9690; letter-spacing: .04em; }
  .gbt-stat-value { font-size: 32px; font-weight: 800; color: #2c2a28; margin-top: 4px; }
  .gbt-stat.s-warn .gbt-stat-value { color: #d97706; }
  .gbt-stat.s-ok   .gbt-stat-value { color: #16a34a; }

  .gbt-action {
    background: #fff; padding: 22px; border-radius: 12px; margin-bottom: 22px;
    border-left: 4px solid #4878a6;
  }
  .gbt-btn {
    background: #4878a6; color: #fff; border: none; padding: 12px 24px;
    border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
  }
  .gbt-btn:hover { background: #2c5d92; }
  .gbt-btn:disabled { opacity: .5; cursor: not-allowed; }

  .gbt-list {
    background: #fff; padding: 16px 22px; border-radius: 12px;
    max-height: 500px; overflow-y: auto;
  }
  .gbt-list table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .gbt-list th, .gbt-list td { padding: 8px 10px; border-bottom: 1px solid #f0eeec; text-align: left; }
  .gbt-list th { background: #f8f7f5; font-weight: 700; color: #4a4640; }

  .gbt-flash { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
  .gbt-flash.success { background: #ecfdf5; color: #065f46; border-left: 4px solid #16a34a; }
  .gbt-flash.warning { background: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b; }
  .gbt-flash.error   { background: #fef2f2; color: #991b1b; border-left: 4px solid #dc2626; }

  .gbt-info { background: #f0f9ff; padding: 14px 18px; border-radius: 10px; font-size: 13px; color: #075985; margin-bottom: 22px; border-left: 4px solid #0ea5e9; }
</style>

<div class="gbt-wrap">

  <h1 style="font-family:Sora,sans-serif;font-size:22px;color:#2c2a28;margin:0 0 8px">
    🌱 Bootstrap GED — Création des dossiers depuis les tiers BDD
  </h1>
  <p style="color:#6b6660;font-size:13px;margin:0 0 22px">
    Pour chaque tiers ayant le rôle <code>proprietaire</code>, instancie le template
    <code>TPL_PROPRIETAIRE</code> sous le dossier <code>03_GESTION_LOCATIVE / 01_PROPRIETAIRES</code>.
    Idempotent : ne crée que les manquants.
  </p>

  <?php if ($flash): ?>
    <div class="gbt-flash <?= $h($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <?php if ($parentFolderId === 0): ?>
    <div class="gbt-flash error">
      ❌ Dossier parent <code>01_PROPRIETAIRES</code> introuvable dans <code>ged_folders</code>.
      Vérifie que la migration <code>20260502_ged_v1_07_metier_seed.php</code> a été appliquée.
    </div>
  <?php else: ?>

  <div class="gbt-info">
    📋 <strong>Dossier parent :</strong> <code>ged_folders.id = <?= $parentFolderId ?></code>
    (slug <code>01_proprietaires</code> sous <code>05_GESTION_LOCATIVE</code>)
  </div>

  <div class="gbt-stats">
    <div class="gbt-stat">
      <div class="gbt-stat-label">Tiers bailleurs en BDD</div>
      <div class="gbt-stat-value"><?= $nbTiersBailleurs ?></div>
    </div>
    <div class="gbt-stat s-ok">
      <div class="gbt-stat-label">Dossiers GED instanciés</div>
      <div class="gbt-stat-value"><?= $nbGedInstanced ?></div>
    </div>
    <div class="gbt-stat <?= $nbMissing > 0 ? 's-warn' : 's-ok' ?>">
      <div class="gbt-stat-label">Manquants à créer</div>
      <div class="gbt-stat-value"><?= $nbMissing ?></div>
    </div>
  </div>

  <?php if ($nbMissing > 0): ?>
    <div class="gbt-action">
      <h3 style="margin:0 0 8px;font-size:15px;color:#2c2a28">▶️ Action</h3>
      <p style="margin:0 0 14px;font-size:13px;color:#4a4640">
        Cliquer ci-dessous pour créer les <?= $nbMissing ?> dossiers GED manquants.
        Chaque dossier sera instancié avec le template <code>TPL_PROPRIETAIRE</code> qui
        crée automatiquement les sous-dossiers métier (Administratif, Mandats, Fiscalité,
        Comptabilité, Biens, Courriers, Mails, Contentieux, Archives).
      </p>
      <form method="POST" onsubmit="return confirm('Créer <?= $nbMissing ?> dossiers GED maintenant ? Opération idempotente, recommencable sans risque.');">
        <input type="hidden" name="action" value="instantiate">
        <button type="submit" class="gbt-btn">
          🌱 Instancier les <?= $nbMissing ?> dossiers manquants
        </button>
      </form>
    </div>
  <?php else: ?>
    <div class="gbt-flash success">
      ✅ Tous les tiers bailleurs ont déjà leur dossier GED — rien à faire.
    </div>
  <?php endif; ?>

  <?php if (!empty($missing)): ?>
    <div class="gbt-list">
      <h3 style="margin:0 0 14px;font-size:14px;color:#2c2a28">
        Liste des <?= count($missing) ?> premiers manquants (preview)
      </h3>
      <table>
        <thead>
          <tr>
            <th>Tiers ID</th>
            <th>Société</th>
            <th>Label (sera utilisé pour name_display)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($missing as $r): ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td><?= $h($r['id_societe'] ?? '—') ?></td>
              <td><?= $h($r['label']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($nbMissing > 20): ?>
        <p style="font-size:11px;color:#9a9690;margin-top:10px;font-style:italic">
          … et <?= $nbMissing - 20 ?> autres ne sont pas affichés ici (mais seront traités).
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php';
