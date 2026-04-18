<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════════════
 * Runner web pour la migration Express (sql/migration_express_flow_2026-04-18.sql)
 * ════════════════════════════════════════════════════════════════
 *
 * Visite : https://dev.maboximmo.fr/scripts/migrate_express_web.php
 *
 * Requiert :
 *   - session admin (role_id = 1)
 *   - action=apply + csrf_token pour exécuter
 *
 * Sécurité :
 *   - Statements additifs (IF NOT EXISTS), donc rejouable sans casse
 *   - Mode dry-run par défaut → montre ce qui sera exécuté
 *   - Confirme via POST + CSRF
 *
 * À SUPPRIMER APRÈS USAGE (ou bloquer après application réussie).
 * ════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

// Admin only
$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    echo '<h1>403 — Accès réservé aux administrateurs.</h1>';
    exit;
}

$pdo = $GLOBALS['pdo'];

// ─── SQL embarqué (copie de sql/migration_express_flow_2026-04-18.sql) ────
$SQL = <<<'SQL'
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `ref_pattern_bien` VARCHAR(200) NOT NULL
    DEFAULT '{TYPE3}-{VILLE3}-{YY}-{SEQ:04}-{USER3}'
    COMMENT 'Pattern référence bien par défaut société',
  ADD COLUMN IF NOT EXISTS `ref_pattern_annonce` VARCHAR(200) NOT NULL
    DEFAULT '{BIEN_REF}-{TRANS3}-{ANN_SEQ:02}'
    COMMENT 'Pattern référence annonce',
  ADD COLUMN IF NOT EXISTS `ref_annual_reset` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `ref_pattern_bien` VARCHAR(200) NULL
    COMMENT 'Override du pattern société pour cette agence',
  ADD COLUMN IF NOT EXISTS `ref_pattern_annonce` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `ref_seq_bien_current` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ref_seq_annonce_current` INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `ref_seq_year` INT UNSIGNED NULL;

ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(200) NULL COMMENT 'Slug SEO URL publique',
  ADD COLUMN IF NOT EXISTS `id_user_actuel` INT UNSIGNED NULL COMMENT 'Commercial courant';

ALTER TABLE `biens`
  ADD INDEX IF NOT EXISTS `idx_biens_slug` (`slug`),
  ADD INDEX IF NOT EXISTS `idx_biens_user_actuel` (`id_user_actuel`);

ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `reference_annonce` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `titre_seo` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `titre_lbc` VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `meta_description` VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS `mots_cles` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `h1_public` VARCHAR(150) NULL;

ALTER TABLE `annonces`
  ADD INDEX IF NOT EXISTS `idx_annonces_ref` (`reference_annonce`),
  ADD INDEX IF NOT EXISTS `idx_annonces_slug` (`slug`);

CREATE TABLE IF NOT EXISTS `annonces_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annonce` INT UNSIGNED NOT NULL,
  `id_biens_photo` INT UNSIGNED NOT NULL,
  `ordre` INT NOT NULL DEFAULT 0,
  `alt_text` VARCHAR(255) NULL,
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annonce_photo` (`id_annonce`, `id_biens_photo`),
  KEY `idx_annonce_ordre` (`id_annonce`, `ordre`),
  KEY `idx_biens_photo` (`id_biens_photo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

// Split basique : un statement par ";" final de ligne (SQL ci-dessus est simple, pas de ; dans strings)
function mew_split_sql(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            continue;
        }
        if ($ch === ';') {
            $t = trim($buf);
            if ($t !== '') $stmts[] = $t;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $tail = trim($buf);
    if ($tail !== '') $stmts[] = $tail;
    return $stmts;
}

$statements = mew_split_sql($SQL);

// ─── Traitement ───
$applied = [];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    verify_csrf('migrate_express');
    foreach ($statements as $idx => $stmt) {
        $label = 'Statement #' . ($idx + 1) . ' — ' . mb_substr(preg_replace('/\s+/', ' ', $stmt) ?? $stmt, 0, 90);
        try {
            $pdo->exec($stmt);
            $applied[] = $label . ' ✓';
        } catch (Throwable $e) {
            $errors[] = $label . ' ✗ ' . $e->getMessage();
        }
    }
}

$csrf = generate_csrf_token('migrate_express');

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Migration Express — MaBoxImmo</title>
  <style>
    body { font-family: -apple-system, system-ui, Segoe UI, sans-serif; max-width: 1000px; margin: 40px auto; padding: 0 20px; color: #0f172a; }
    h1 { color: #0369a1; }
    pre { background: #f1f5f9; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 12px; line-height: 1.5; }
    .warn { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin: 12px 0; }
    .ok   { background: #f0fdf4; border-left: 4px solid #16a34a; padding: 12px 16px; border-radius: 8px; margin: 12px 0; }
    .err  { background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 16px; border-radius: 8px; margin: 12px 0; }
    button { padding: 10px 20px; background: #0ea5e9; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; }
    button:hover { background: #0284c7; }
    .stmt { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 12px; margin: 6px 0; font-family: monospace; font-size: 11px; }
    .stmt.ok { background: #f0fdf4; border-color: #86efac; }
    .stmt.err { background: #fef2f2; border-color: #fecaca; }
  </style>
</head>
<body>
  <h1>Migration Express — <?= h((string)($_SESSION['prenom'] ?? 'Admin')) ?></h1>

  <?php if ($applied || $errors): ?>
    <?php if ($applied && !$errors): ?>
      <div class="ok"><strong>✅ Migration appliquée avec succès</strong> — <?= count($applied) ?> statements exécutés.</div>
    <?php elseif ($errors): ?>
      <div class="err"><strong>⚠️ Certains statements ont échoué</strong> — <?= count($applied) ?> OK, <?= count($errors) ?> en erreur.</div>
    <?php endif; ?>

    <?php foreach ($applied as $line): ?>
      <div class="stmt ok"><?= h($line) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $line): ?>
      <div class="stmt err"><?= h($line) ?></div>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
      <div class="warn">
        ✅ Migration complète. Tu peux maintenant <strong>supprimer ce fichier</strong>
        (<code>public_html/scripts/migrate_express_web.php</code>) pour des raisons de sécurité.
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="warn">
      ⚠️ Cette migration ajoute des colonnes et une table. Les statements sont <strong>additifs</strong>
      (<code>IF NOT EXISTS</code>) → <strong>rejouables sans casse</strong>. Aucune donnée existante n'est modifiée.
    </div>

    <h2>Statements à exécuter (<?= count($statements) ?>)</h2>
    <?php foreach ($statements as $idx => $stmt): ?>
      <div class="stmt"><strong>#<?= $idx + 1 ?></strong> <?= h(mb_substr(preg_replace('/\s+/', ' ', $stmt) ?? $stmt, 0, 200)) ?></div>
    <?php endforeach; ?>

    <form method="post" style="margin-top: 24px;">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="apply">
      <button type="submit">🚀 Appliquer la migration</button>
    </form>
  <?php endif; ?>

  <p style="margin-top: 40px; font-size: 11px; color: #64748b;">
    Base : <?= h(DB_NAME) ?> • Hôte : <?= h(DB_HOST) ?>
  </p>
</body>
</html>
