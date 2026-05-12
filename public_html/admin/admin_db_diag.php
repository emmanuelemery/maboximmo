<?php
declare(strict_types=1);

/**
 * Diagnostic BDD : vérifie où on est connecté et quelles colonnes OCR
 * existent réellement sur rh_documents. Permet de comprendre pourquoi
 * une migration peut sembler "appliquée" sans que les colonnes existent.
 *
 * Réservé super admin (role=1).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$pdo = $GLOBALS['pdo'];

// ── 1. Identité de la connexion ─────────────────────────────────────
$identite = $pdo->query("
    SELECT
        DATABASE()    AS bdd_courante,
        @@hostname    AS serveur,
        @@version     AS version,
        @@version_comment AS version_comment,
        USER()        AS user_courant,
        @@datadir     AS datadir
")->fetch(PDO::FETCH_ASSOC);

// ── 2. Colonnes attendues (3 migrations OCR) ────────────────────────
$colonnesAttendues = [
    // Migration 20260506_rh_documents_ocr_societe (de base)
    'numero', 'emetteur', 'montant_garantie', 'date_emission', 'date_validite',
    'ocr_modele', 'ocr_confidence', 'ocr_cout_centimes', 'ocr_json', 'ocr_at',
    'alerte_120j_envoyee_at', 'alerte_90j_envoyee_at', 'alerte_60j_envoyee_at',
    // Migration 20260506_3_rh_documents_ocr_detail (détaillée)
    'numero_client', 'adresse_emetteur', 'raison_sociale',
    'siret', 'siren', 'tva_intra', 'capital_social', 'code_ape',
    'date_effet', 'date_echeance', 'date_anniversaire',
    'montant_franchise', 'montant_plafond_2', 'nature_garantie',
    'dirigeants_json', 'metadata_json',
    // Migration 20260506_2_docs_activites_agence
    'activite_code',
];

// Récupère TOUTES les colonnes existantes
$colonnesExistantes = [];
try {
    $st = $pdo->query("SHOW COLUMNS FROM rh_documents");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $colonnesExistantes[$row['Field']] = $row;
    }
} catch (Throwable $e) {
    $colonnesExistantes = [];
    $tableErr = $e->getMessage();
}

// ── 3. Test "ADD COLUMN IF NOT EXISTS" supporté ? ────────────────────
$supportIfNotExists = null;
try {
    $pdo->exec("CREATE TEMPORARY TABLE _test_inex (id INT)");
    $pdo->exec("ALTER TABLE _test_inex ADD COLUMN IF NOT EXISTS test_col VARCHAR(10)");
    $supportIfNotExists = true;
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS _test_inex");
} catch (Throwable $e) {
    $supportIfNotExists = false;
    $supportIfNotExistsErr = $e->getMessage();
    try { $pdo->exec("DROP TEMPORARY TABLE IF EXISTS _test_inex"); } catch (Throwable) {}
}

// ── 4. Migrations enregistrées ──────────────────────────────────────
$migrationsLog = [];
try {
    $st = $pdo->query("
        SELECT id, applied_at, statements_ok, statements_err, error_log
        FROM _migrations_applied
        WHERE id LIKE '20260506%'
        ORDER BY id
    ");
    foreach ($st as $row) $migrationsLog[] = $row;
} catch (Throwable) {}

// ── 5. Action : appliquer manuellement les colonnes manquantes ──────
$actionLog = [];
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_add_columns') {
    verify_csrf('admin_db_diag');

    $alters = [
        ['numero',                 "ADD COLUMN `numero` VARCHAR(150) NULL"],
        ['emetteur',               "ADD COLUMN `emetteur` VARCHAR(255) NULL"],
        ['montant_garantie',       "ADD COLUMN `montant_garantie` DECIMAL(12,2) NULL"],
        ['date_emission',          "ADD COLUMN `date_emission` DATE NULL"],
        ['date_validite',          "ADD COLUMN `date_validite` DATE NULL"],
        ['ocr_modele',             "ADD COLUMN `ocr_modele` VARCHAR(50) NULL"],
        ['ocr_confidence',         "ADD COLUMN `ocr_confidence` TINYINT UNSIGNED NULL"],
        ['ocr_cout_centimes',      "ADD COLUMN `ocr_cout_centimes` INT UNSIGNED NULL"],
        ['ocr_json',               "ADD COLUMN `ocr_json` JSON NULL"],
        ['ocr_at',                 "ADD COLUMN `ocr_at` TIMESTAMP NULL"],
        ['alerte_120j_envoyee_at', "ADD COLUMN `alerte_120j_envoyee_at` TIMESTAMP NULL"],
        ['alerte_90j_envoyee_at',  "ADD COLUMN `alerte_90j_envoyee_at` TIMESTAMP NULL"],
        ['alerte_60j_envoyee_at',  "ADD COLUMN `alerte_60j_envoyee_at` TIMESTAMP NULL"],
        ['numero_client',          "ADD COLUMN `numero_client` VARCHAR(100) NULL"],
        ['adresse_emetteur',       "ADD COLUMN `adresse_emetteur` VARCHAR(500) NULL"],
        ['raison_sociale',         "ADD COLUMN `raison_sociale` VARCHAR(255) NULL"],
        ['siret',                  "ADD COLUMN `siret` VARCHAR(20) NULL"],
        ['siren',                  "ADD COLUMN `siren` VARCHAR(20) NULL"],
        ['tva_intra',              "ADD COLUMN `tva_intra` VARCHAR(20) NULL"],
        ['capital_social',         "ADD COLUMN `capital_social` DECIMAL(15,2) NULL"],
        ['code_ape',               "ADD COLUMN `code_ape` VARCHAR(10) NULL"],
        ['date_effet',             "ADD COLUMN `date_effet` DATE NULL"],
        ['date_echeance',          "ADD COLUMN `date_echeance` DATE NULL"],
        ['date_anniversaire',      "ADD COLUMN `date_anniversaire` DATE NULL"],
        ['montant_franchise',      "ADD COLUMN `montant_franchise` DECIMAL(12,2) NULL"],
        ['montant_plafond_2',      "ADD COLUMN `montant_plafond_2` DECIMAL(12,2) NULL"],
        ['nature_garantie',        "ADD COLUMN `nature_garantie` VARCHAR(100) NULL"],
        ['dirigeants_json',        "ADD COLUMN `dirigeants_json` JSON NULL"],
        ['metadata_json',          "ADD COLUMN `metadata_json` JSON NULL"],
        ['activite_code',          "ADD COLUMN `activite_code` VARCHAR(40) NULL"],
    ];

    $cols = $colonnesExistantes;
    $okCount = 0; $skipCount = 0; $errCount = 0;
    foreach ($alters as [$colName, $alterClause]) {
        if (isset($cols[$colName])) {
            $actionLog[] = ['col' => $colName, 'status' => 'skip', 'msg' => 'déjà présente'];
            $skipCount++;
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE `rh_documents` $alterClause");
            $actionLog[] = ['col' => $colName, 'status' => 'ok', 'msg' => 'créée'];
            $okCount++;
        } catch (Throwable $e) {
            $actionLog[] = ['col' => $colName, 'status' => 'err', 'msg' => $e->getMessage()];
            $errCount++;
        }
    }

    // Index sur date_validite (best-effort)
    try {
        $pdo->exec("CREATE INDEX `idx_rh_docs_validite_alertes` ON `rh_documents` (`date_validite`, `actif`)");
        $actionLog[] = ['col' => '[INDEX idx_rh_docs_validite_alertes]', 'status' => 'ok', 'msg' => 'créé'];
    } catch (Throwable $e) {
        // Index existe peut-être déjà
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'exists')) {
            $actionLog[] = ['col' => '[INDEX idx_rh_docs_validite_alertes]', 'status' => 'skip', 'msg' => 'existe déjà'];
        } else {
            $actionLog[] = ['col' => '[INDEX idx_rh_docs_validite_alertes]', 'status' => 'err', 'msg' => $e->getMessage()];
        }
    }

    $flash = "Forçage terminé : $okCount créées · $skipCount déjà présentes · $errCount erreurs";

    // Recharge la liste des colonnes
    $colonnesExistantes = [];
    try {
        $st = $pdo->query("SHOW COLUMNS FROM rh_documents");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $colonnesExistantes[$row['Field']] = $row;
        }
    } catch (Throwable) {}
}

$csrf = csrf_token('admin_db_diag');

$appLayout = true;
$pageTitle = 'Diagnostic BDD';
require_once __DIR__ . '/../inc/header.php';
?>
<style>
  .dbd { max-width: 1100px; margin: 0 auto; padding: 24px 20px; font-size: 13px; }
  .dbd h1 { font-size: 22px; color:#0f172a; margin: 0 0 6px; }
  .dbd .sub { color:#64748b; margin: 0 0 24px; }
  .dbd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 20px; margin-bottom:14px; }
  .dbd-card h2 { font-size:14px; color:#0f172a; margin: 0 0 10px; }
  .dbd-grid { display:grid; grid-template-columns: 220px 1fr; gap:6px 14px; }
  .dbd-grid > div:nth-child(odd) { color:#64748b; font-weight:600; }
  .dbd-cols { display:grid; grid-template-columns: repeat(2, 1fr); gap:4px 12px; font-family: monospace; font-size: 11px; }
  .dbd-col-ok    { color:#166534; }
  .dbd-col-miss  { color:#991b1b; font-weight:700; }
  .dbd-flash { background:#0ea5e9; color:#fff; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-weight:600; }
  .dbd-warn  { background:#fffbeb; border-left:4px solid #f59e0b; color:#92400e; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
  .dbd-ok    { background:#f0fdf4; border-left:4px solid #16a34a; color:#14532d; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
  .dbd-err   { background:#fef2f2; border-left:4px solid #dc2626; color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
  .dbd-btn { padding:10px 16px; border-radius:8px; background:#dc2626; color:#fff; border:none; font-size:13px; font-weight:700; cursor:pointer; font-family: inherit; }
  .dbd-btn:hover { background:#b91c1c; }
  .dbd-actlog { font-family: monospace; font-size: 11px; max-height: 360px; overflow-y: auto; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding: 10px; }
  .dbd-actlog .ok   { color:#166534; }
  .dbd-actlog .skip { color:#64748b; }
  .dbd-actlog .err  { color:#991b1b; font-weight:700; }
  .dbd-mlog { font-family: monospace; font-size: 11px; }
  .dbd-mlog th, .dbd-mlog td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; text-align:left; }
</style>

<div class="dbd">
  <h1>🔬 Diagnostic BDD — colonnes OCR rh_documents</h1>
  <p class="sub">Pourquoi une migration peut sembler appliquée sans que les colonnes existent.</p>

  <?php if ($flash): ?><div class="dbd-flash">✅ <?= htmlspecialchars($flash) ?></div><?php endif; ?>

  <!-- 1. Identité connexion -->
  <div class="dbd-card">
    <h2>1️⃣ Connexion BDD courante</h2>
    <div class="dbd-grid">
      <div>BDD</div><div><strong style="color:#dc2626;font-size:14px;"><?= htmlspecialchars((string)$identite['bdd_courante']) ?></strong></div>
      <div>Serveur (host)</div><div><?= htmlspecialchars((string)$identite['serveur']) ?></div>
      <div>Version SGBD</div><div><strong><?= htmlspecialchars((string)$identite['version']) ?></strong> <span style="color:#64748b;">— <?= htmlspecialchars((string)$identite['version_comment']) ?></span></div>
      <div>User</div><div><?= htmlspecialchars((string)$identite['user_courant']) ?></div>
      <div>Datadir</div><div style="font-family:monospace;font-size:11px;"><?= htmlspecialchars((string)$identite['datadir']) ?></div>
      <div>Constante DB_NAME</div><div><?= defined('DB_NAME') ? htmlspecialchars(DB_NAME) : '<em>non définie</em>' ?></div>
      <div>Constante DB_HOST</div><div><?= defined('DB_HOST') ? htmlspecialchars(DB_HOST) : '<em>non définie</em>' ?></div>
    </div>
  </div>

  <!-- 2. Support IF NOT EXISTS -->
  <div class="dbd-card">
    <h2>2️⃣ Support de <code>ADD COLUMN IF NOT EXISTS</code></h2>
    <?php if ($supportIfNotExists === true): ?>
      <div class="dbd-ok">✅ Le serveur SUPPORTE <code>ADD COLUMN IF NOT EXISTS</code> (MariaDB 10.0.2+).</div>
    <?php else: ?>
      <div class="dbd-err">
        ❌ Le serveur NE SUPPORTE PAS <code>ADD COLUMN IF NOT EXISTS</code>.<br>
        <small><?= htmlspecialchars($supportIfNotExistsErr ?? '?') ?></small><br><br>
        <strong>C'est probablement la cause :</strong> les migrations utilisent cette syntaxe et plantent silencieusement.
        MySQL standard ne supporte pas <code>IF NOT EXISTS</code> sur ADD COLUMN — uniquement MariaDB.
      </div>
    <?php endif; ?>
  </div>

  <!-- 3. Colonnes attendues vs présentes -->
  <div class="dbd-card">
    <h2>3️⃣ Colonnes OCR sur <code>rh_documents</code></h2>
    <?php
    $manquantes = [];
    foreach ($colonnesAttendues as $c) if (!isset($colonnesExistantes[$c])) $manquantes[] = $c;
    ?>
    <?php if (empty($manquantes)): ?>
      <div class="dbd-ok">✅ Les <?= count($colonnesAttendues) ?> colonnes attendues sont toutes présentes.</div>
    <?php else: ?>
      <div class="dbd-warn">⚠️ <strong><?= count($manquantes) ?></strong> colonne<?= count($manquantes) > 1 ? 's' : '' ?> manquante<?= count($manquantes) > 1 ? 's' : '' ?> sur <?= count($colonnesAttendues) ?>.</div>
    <?php endif; ?>

    <div class="dbd-cols">
      <?php foreach ($colonnesAttendues as $c):
        $exists = isset($colonnesExistantes[$c]);
      ?>
        <div class="<?= $exists ? 'dbd-col-ok' : 'dbd-col-miss' ?>">
          <?= $exists ? '✓' : '✗' ?> <?= htmlspecialchars($c) ?>
          <?= $exists ? '<span style="color:#94a3b8;"> · ' . htmlspecialchars((string)$colonnesExistantes[$c]['Type']) . '</span>' : '' ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($manquantes)): ?>
      <form method="post" style="margin-top:18px;" onsubmit="return confirm('Forcer l\'ajout des <?= count($manquantes) ?> colonnes manquantes ? Opération additive (ADD COLUMN, sans IF NOT EXISTS).');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="force_add_columns">
        <button type="submit" class="dbd-btn">🔧 Forcer l'ajout des <?= count($manquantes) ?> colonnes manquantes</button>
        <span style="font-size:11px;color:#64748b;margin-left:10px;">Chaque ALTER passe en mode try/catch — un échec sur une colonne ne bloque pas les autres.</span>
      </form>
    <?php endif; ?>

    <?php if (!empty($actionLog)): ?>
      <h3 style="margin: 18px 0 8px; font-size: 13px;">🔧 Log du forçage</h3>
      <div class="dbd-actlog">
        <?php foreach ($actionLog as $l): ?>
          <div class="<?= htmlspecialchars($l['status']) ?>">
            [<?= strtoupper($l['status']) ?>] <?= htmlspecialchars($l['col']) ?> — <?= htmlspecialchars($l['msg']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- 5. Derniers docs société uploadés (test import OCR) -->
  <div class="dbd-card">
    <h2>5️⃣ Derniers docs Société uploadés (test import OCR)</h2>
    <p style="color:#64748b;font-size:12px;margin: 0 0 12px;">
      Affiche les 5 derniers <code>rh_documents</code> avec <code>categorie='societe'</code>, toutes colonnes OCR confondues.
      Permet de vérifier que l'upload + l'OCR Sonnet ont bien rempli les colonnes.
    </p>
    <?php
    $derniersDocs = [];
    try {
        $st = $pdo->query("
            SELECT
                id, id_societe, id_agence, sous_categorie AS type, original_name,
                created_at, ocr_at, ocr_modele, ocr_confidence, ocr_cout_centimes,
                numero, numero_client, raison_sociale, siret, siren, capital_social, code_ape,
                emetteur, adresse_emetteur, tva_intra, activite_code,
                date_emission, date_effet, date_echeance, date_validite, date_anniversaire,
                montant_garantie, montant_franchise, montant_plafond_2, nature_garantie,
                dirigeants_json, metadata_json
            FROM rh_documents
            WHERE categorie = 'societe'
            ORDER BY id DESC
            LIMIT 5
        ");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) $derniersDocs[] = $row;
    } catch (Throwable $e) {
        $derniersDocsErr = $e->getMessage();
    }
    ?>
    <?php if (empty($derniersDocs)): ?>
      <div class="dbd-warn">
        Aucun doc Société uploadé pour l'instant.
        <?php if (!empty($derniersDocsErr)): ?><br><small>Erreur : <?= htmlspecialchars($derniersDocsErr) ?></small><?php endif; ?>
        <br><br>
        Pour tester : ouvre <code>rh_documents.php?societe=1</code> → upload une carte pro PDF → reviens ici.
      </div>
    <?php else: ?>
      <?php foreach ($derniersDocs as $d): ?>
        <details style="margin-bottom:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
          <summary style="font-weight:700;color:#0f172a;">
            #<?= (int)$d['id'] ?> · <?= htmlspecialchars((string)$d['type']) ?> · <?= htmlspecialchars((string)$d['original_name']) ?>
            <span style="color:#64748b;font-weight:400;">— sté <?= (int)$d['id_societe'] ?> · <?= htmlspecialchars((string)$d['created_at']) ?></span>
            <?php if ($d['ocr_at']): ?>
              <span style="background:#16a34a;color:#fff;padding:2px 8px;border-radius:4px;font-size:10px;margin-left:8px;">OCR ✓ <?= (int)$d['ocr_confidence'] ?>%</span>
            <?php else: ?>
              <span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;font-size:10px;margin-left:8px;">OCR ✗</span>
            <?php endif; ?>
          </summary>
          <div class="dbd-grid" style="margin-top:10px;font-size:11px;">
            <?php
            $blocs = [
              'OCR'         => ['ocr_at','ocr_modele','ocr_confidence','ocr_cout_centimes'],
              'Identité'    => ['numero','numero_client','raison_sociale','siret','siren','capital_social','code_ape','tva_intra','activite_code'],
              'Émetteur'    => ['emetteur','adresse_emetteur'],
              'Dates'       => ['date_emission','date_effet','date_echeance','date_validite','date_anniversaire'],
              'Montants'    => ['montant_garantie','montant_franchise','montant_plafond_2','nature_garantie'],
              'JSON brut'   => ['dirigeants_json','metadata_json'],
            ];
            foreach ($blocs as $titre => $cols): ?>
              <div style="grid-column:1/-1;font-weight:700;color:#0369a1;margin-top:8px;border-bottom:1px solid #e2e8f0;"><?= $titre ?></div>
              <?php foreach ($cols as $c):
                $v = $d[$c] ?? null;
                $isJson = str_ends_with($c, '_json');
                ?>
                <div style="color:#64748b;"><?= htmlspecialchars($c) ?></div>
                <div style="font-family:monospace;<?= $v === null || $v === '' ? 'color:#cbd5e1;' : '' ?>">
                  <?php if ($v === null || $v === ''): ?>
                    —
                  <?php elseif ($isJson): ?>
                    <pre style="margin:0;font-size:10px;white-space:pre-wrap;background:#fff;padding:6px;border-radius:4px;max-height:120px;overflow:auto;"><?= htmlspecialchars((string)$v) ?></pre>
                  <?php else: ?>
                    <?= htmlspecialchars((string)$v) ?>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- 4. Log migrations -->
  <div class="dbd-card">
    <h2>4️⃣ Log des migrations OCR (<code>_migrations_applied</code>)</h2>
    <?php if (empty($migrationsLog)): ?>
      <div class="dbd-warn">Aucune migration <code>20260506*</code> trouvée dans <code>_migrations_applied</code>.</div>
    <?php else: ?>
      <table class="dbd-mlog" style="width:100%;border-collapse:collapse;">
        <thead style="background:#f8fafc;">
          <tr><th>ID</th><th>Appliquée le</th><th>OK</th><th>Err</th><th>Erreurs</th></tr>
        </thead>
        <tbody>
          <?php foreach ($migrationsLog as $m): ?>
            <tr>
              <td><?= htmlspecialchars((string)$m['id']) ?></td>
              <td><?= htmlspecialchars((string)$m['applied_at']) ?></td>
              <td style="color:#166534;font-weight:700;"><?= (int)$m['statements_ok'] ?></td>
              <td style="color:<?= (int)$m['statements_err'] ? '#991b1b' : '#94a3b8' ?>;font-weight:700;"><?= (int)$m['statements_err'] ?></td>
              <td style="font-family:monospace;font-size:10px;color:#991b1b;max-width:400px;"><?= nl2br(htmlspecialchars((string)($m['error_log'] ?? ''))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <p style="font-size:11px;color:#94a3b8;text-align:center;">
    <a href="admin_migrations.php">← Retour aux migrations</a>
  </p>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
