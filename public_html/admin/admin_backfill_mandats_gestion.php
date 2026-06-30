<?php
/**
 * admin/admin_backfill_mandats_gestion.php
 *
 * Audit + backfill : tout bien sans mandat → création d'un mandat GESTION par défaut.
 *
 * Directive Emmanuel 2026-05-25 : "tous les biens créés avec un mandat null,
 * doit avoir un mandat GESTION. Et quand on a un mandat gestion, on peut lui
 * ajouter un mandat LOCATION et un mandat VENTE à la fois."
 *
 * Modes :
 *   - audit   : compte biens sans mandat actif
 *   - dryrun  : liste les biens à backfiller (limit 50)
 *   - apply   : crée les mandats GESTION pour tous les biens sans mandat
 *
 * Réservé super admin (id_role=1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$mode  = (string)($_GET['mode']  ?? 'audit');
$limit = max(1, min(2000, (int)($_GET['limit'] ?? 500)));

$report = [];
$totals = ['biens_total' => 0, 'biens_avec_mandat' => 0, 'biens_sans_mandat' => 0,
           'created' => 0, 'errors' => 0, 'skipped' => 0];

// ─── 1. Audit (toujours) ───
// Règle métier 2026-05-25 (Emmanuel) :
//   - Biens avec mandat VENTE/TRANSACTION actif → EXCLUS (on ne touche pas)
//   - Biens avec mandat GESTION déjà → skip (rien à faire)
//   - Tous les autres (sans mandat OU avec uniquement LOCATION) → ajouter GESTION
$totals['biens_avec_vente']   = 0;
$totals['biens_avec_gestion'] = 0;
$totals['biens_a_traiter']    = 0; // candidats au backfill GESTION
try {
    $totals['biens_total'] = (int)$pdo->query("SELECT COUNT(*) FROM biens")->fetchColumn();
    $totals['biens_avec_gestion'] = (int)$pdo->query("
        SELECT COUNT(DISTINCT b.id) FROM biens b
        INNER JOIN mandats m ON m.id_bien = b.id AND m.statut = 'actif'
        WHERE m.type_mandat IN ('gestion', 'gerance')
    ")->fetchColumn();
    $totals['biens_avec_vente'] = (int)$pdo->query("
        SELECT COUNT(DISTINCT b.id) FROM biens b
        INNER JOIN mandats m ON m.id_bien = b.id AND m.statut = 'actif'
        WHERE m.type_mandat IN ('vente', 'transaction')
    ")->fetchColumn();
    // Candidats = biens SANS mandat gestion + SANS mandat vente actif
    $totals['biens_a_traiter'] = (int)$pdo->query("
        SELECT COUNT(*) FROM biens b
        WHERE NOT EXISTS (
            SELECT 1 FROM mandats m1
            WHERE m1.id_bien = b.id AND m1.statut = 'actif'
              AND m1.type_mandat IN ('gestion', 'gerance')
        )
        AND NOT EXISTS (
            SELECT 1 FROM mandats m2
            WHERE m2.id_bien = b.id AND m2.statut = 'actif'
              AND m2.type_mandat IN ('vente', 'transaction')
        )
    ")->fetchColumn();
    $totals['biens_sans_mandat'] = $totals['biens_a_traiter']; // alias rétro-compat
} catch (Throwable $e) {
    $report[] = "❌ Audit query: " . $e->getMessage();
}

// ─── 2. Apply / Dryrun ───
if (in_array($mode, ['dryrun', 'apply'], true) && $totals['biens_a_traiter'] > 0) {
    if ($mode === 'apply' && ($_POST['confirm'] ?? '') !== 'BACKFILL') {
        $report[] = "⚠️ APPLY refusé : confirmation textuelle 'BACKFILL' manquante.";
        $mode = 'dryrun';
    }

    try {
        // Biens à traiter :
        //   - PAS de mandat GESTION/GERANCE actif (sinon déjà fait)
        //   - PAS de mandat VENTE/TRANSACTION actif (règle métier : on laisse les biens en vente tels quels)
        //   - LOCATION seul → OK pour ajouter GESTION (cumul)
        $st = $pdo->prepare("
            SELECT b.id, b.reference_bien, b.designation, b.id_proprietaire, b.id_agence,
                   b.id_societe, b.ville,
                   (SELECT GROUP_CONCAT(m3.type_mandat) FROM mandats m3
                    WHERE m3.id_bien = b.id AND m3.statut = 'actif') AS mandats_actuels
            FROM biens b
            WHERE NOT EXISTS (
                SELECT 1 FROM mandats m1
                WHERE m1.id_bien = b.id AND m1.statut = 'actif'
                  AND m1.type_mandat IN ('gestion', 'gerance')
            )
            AND NOT EXISTS (
                SELECT 1 FROM mandats m2
                WHERE m2.id_bien = b.id AND m2.statut = 'actif'
                  AND m2.type_mandat IN ('vente', 'transaction')
            )
            ORDER BY b.id DESC
            LIMIT $limit
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $report[] = "── " . count($rows) . " biens candidats au mandat GESTION (limit=$limit) ──";
        $report[] = "   (exclu : biens avec mandat VENTE/TRANSACTION actif — on les laisse tels quels)";

        $insMandat = $pdo->prepare("INSERT INTO mandats
            (id_bien, id_proprietaire, id_agence,
             numero_mandat, type_mandat, nature_mandat, exclusif,
             date_debut, statut, id_user, date_creation)
            VALUES (?, ?, ?, ?, 'gestion', NULL, 0, CURDATE(), 'actif', ?, NOW())");

        foreach ($rows as $b) {
            $bienId = (int)$b['id'];
            $numMandat = 'AUTO-G-' . date('Y') . '-' . str_pad((string)$bienId, 5, '0', STR_PAD_LEFT);

            $mandatActuelInfo = $b['mandats_actuels'] ? " (mandats actifs: {$b['mandats_actuels']})" : ' (aucun mandat)';
            if ($mode === 'dryrun') {
                $report[] = "  [DRY] bien #$bienId ({$b['reference_bien']}) → mandat GESTION num=$numMandat$mandatActuelInfo";
                $totals['created']++;
                continue;
            }

            try {
                $insMandat->execute([
                    $bienId,
                    $b['id_proprietaire'] ?: null,
                    $b['id_agence'] ?: null,
                    $numMandat,
                    $userId ?: null,
                ]);
                $mandatId = (int)$pdo->lastInsertId();
                $report[] = "  ✅ bien #$bienId → mandat #$mandatId GESTION ($numMandat)";
                $totals['created']++;
            } catch (Throwable $e) {
                $report[] = "  ❌ bien #$bienId : " . $e->getMessage();
                $totals['errors']++;
            }
        }
    } catch (Throwable $e) {
        $report[] = "❌ Backfill query : " . $e->getMessage();
        $totals['errors']++;
    }
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.bm-wrap { max-width: 1100px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; font-size: 13px; }
.bm-wrap h1 { color: #243B5C; font-size: 22px; }
.bm-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 16px 0; }
.bm-stat { background: #fff; padding: 12px; border-radius: 8px; text-align: center; border-left: 4px solid #D4A047; }
.bm-stat .v { font-size: 24px; font-weight: 800; }
.bm-stat .l { font-size: 10px; color: #7a766f; text-transform: uppercase; }
.bm-actions { display: flex; gap: 10px; margin: 16px 0; flex-wrap: wrap; }
.bm-btn { padding: 10px 20px; border: 0; border-radius: 6px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; }
.bm-btn-dry { background: #60a5fa; color: #fff; }
.bm-btn-apply { background: #16a34a; color: #fff; }
.bm-btn-back { background: #e3dfd8; color: #2c2a28; }
.bm-log { background: #0f172a; color: #d1d5db; padding: 14px; border-radius: 8px; font-size: 11px; white-space: pre-wrap; max-height: 500px; overflow-y: auto; }
</style>
<div class="bm-wrap">
    <h1>🔧 Backfill mandats GESTION (biens sans mandat)</h1>
    <p style="color: #7a766f;">Directive 2026-05-25 : tous les biens doivent avoir au minimum un mandat GESTION actif. Ce script crée un mandat GESTION automatique pour chaque bien qui n'en a aucun.</p>

    <div class="bm-stats" style="grid-template-columns: repeat(5, 1fr);">
        <div class="bm-stat"><div class="v"><?= $totals['biens_total'] ?></div><div class="l">Biens total</div></div>
        <div class="bm-stat" style="border-left-color:#16a34a;"><div class="v"><?= $totals['biens_avec_gestion'] ?></div><div class="l">✅ Avec GESTION</div></div>
        <div class="bm-stat" style="border-left-color:#eab308;"><div class="v"><?= $totals['biens_avec_vente'] ?></div><div class="l">🏷️ Avec VENTE (exclus)</div></div>
        <div class="bm-stat" style="border-left-color:#dc2626;"><div class="v"><?= $totals['biens_a_traiter'] ?></div><div class="l">⚠️ À backfiller</div></div>
        <div class="bm-stat" style="border-left-color:<?= $totals['errors'] > 0 ? '#dc2626' : '#16a34a' ?>;"><div class="v"><?= $totals['created'] ?></div><div class="l">Créés cette session</div></div>
    </div>

    <div class="bm-actions">
        <a href="?mode=dryrun&limit=<?= (int)$limit ?>" class="bm-btn bm-btn-dry">👁️ Dry-run (preview)</a>
        <?php if ($mode === 'dryrun' && $totals['created'] > 0): ?>
        <form method="POST" action="?mode=apply&limit=<?= (int)$limit ?>" style="display:inline;"
              onsubmit="return confirm('⚠️ APPLY va créer ' + <?= $totals['biens_a_traiter'] ?> + ' mandats GESTION. Continuer ?')">
            <input type="hidden" name="confirm" value="BACKFILL">
            <button type="submit" class="bm-btn bm-btn-apply">🔥 APPLY (créer <?= $totals['biens_a_traiter'] ?> mandats)</button>
        </form>
        <?php endif; ?>
        <a href="admin_migrations.php" class="bm-btn bm-btn-back">← Retour</a>
    </div>

    <?php if (!empty($report)): ?>
    <div class="bm-log"><?php foreach ($report as $l) echo $h($l) . "\n"; ?></div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 14px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047; font-size: 12px;">
        <strong>📚 Règle métier backfill 2026-05-25 (Emmanuel)</strong>
        <ul style="margin: 8px 0 0 16px;">
            <li><strong>✅ Backfille</strong> : biens sans aucun mandat actif → ajoute GESTION</li>
            <li><strong>✅ Backfille</strong> : biens avec uniquement LOCATION actif → ajoute GESTION (cumul autorisé)</li>
            <li><strong>❌ N'EXCLUT PAS</strong> mais ne touche pas : biens avec VENTE/TRANSACTION actif (on les laisse tels quels — pas de gestion ajoutée)</li>
            <li><strong>⏭️ Skip</strong> : biens avec GESTION/GERANCE déjà actif</li>
            <li>Export Ubiflow : si VENTE → annonce "à vendre" ; si LOCATION → annonce "à louer" ; les deux possibles en parallèle</li>
            <li>Numéro mandat auto : AUTO-G-{YYYY}-{bien_id 5 digits}</li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
