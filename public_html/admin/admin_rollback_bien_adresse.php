<?php
/**
 * admin/admin_rollback_bien_adresse.php
 *
 * Outil de rollback rapide d'adresse de bien polluée par hallucination IA.
 *
 * Contexte (2026-05-25) : avant le fix P0-2 (createdNow=true requis), le
 * pipeline `bien_intake_upload.php` appliquait automatiquement les champs
 * extraits par l'IA aux biens EXISTANTS — y compris des hallucinations.
 * Cas connu : bien #726 (9 Bd Pinel 69003 Lyon) → corrompu en "12 Rue
 * Laffitte 75009 Paris" par un test IA.
 *
 * Cet outil VIDE les champs adresse de biens.* pour qu'ils héritent à nouveau
 * de l'adresse de l'immeuble via le COALESCE JOIN existant dans bien_360.
 *
 * RÉSERVÉ super admin (id_role=1). Dry-run obligatoire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin.</h1>');
}

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$mode   = (string)($_GET['mode']   ?? 'preview');
$bienId = (int)($_GET['bien_id']  ?? 0);

$bien = null; $immeuble = null; $log = [];

if ($bienId > 0) {
    try {
        $st = $pdo->prepare("SELECT b.id, b.designation, b.reference_bien,
                                    b.adresse_1, b.adresse_2, b.code_postal, b.ville,
                                    b.id_immeuble,
                                    i.adresse_1 AS imm_adresse, i.code_postal AS imm_cp, i.ville AS imm_ville,
                                    i.nom_immeuble
                              FROM biens b
                              LEFT JOIN immeubles i ON i.id = b.id_immeuble
                              WHERE b.id = ?");
        $st->execute([$bienId]);
        $bien = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $log[] = "❌ Erreur lecture bien : " . $e->getMessage();
    }
}

if ($mode === 'apply' && $bienId > 0 && $bien) {
    if (($_POST['confirm'] ?? '') !== 'CLEAN') {
        $log[] = "⚠️ Confirmation textuelle manquante.";
    } else {
        try {
            $pdo->prepare("UPDATE biens SET adresse_1='', adresse_2='', code_postal='', ville='' WHERE id = ?")
                ->execute([$bienId]);
            $log[] = "✅ Bien #$bienId : adresse vidée. L'affichage hérite désormais de l'immeuble #" . (int)$bien['id_immeuble'];
            // Recharger après UPDATE
            $st = $pdo->prepare("SELECT b.*, i.adresse_1 AS imm_adresse, i.code_postal AS imm_cp, i.ville AS imm_ville
                                  FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble WHERE b.id = ?");
            $st->execute([$bienId]);
            $bien = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $log[] = "❌ Erreur UPDATE : " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../inc/header.php';
?>
<style>
.rb-wrap { max-width: 900px; margin: 20px auto; padding: 20px; font-family: 'DM Mono', monospace; }
.rb-wrap h1 { color: #243B5C; font-size: 22px; margin-bottom: 4px; }
.rb-form { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; }
.rb-form input[type=number] { padding: 8px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px; width: 200px; }
.rb-form button { padding: 8px 18px; background: #60a5fa; color: #fff; border: 0; border-radius: 4px; font-weight: 700; cursor: pointer; }
.rb-card { background: #fff; padding: 16px; border-radius: 8px; margin: 12px 0; box-shadow: 2px 2px 6px #e3dfd8; }
.rb-card h3 { margin: 0 0 12px; color: #243B5C; font-size: 16px; }
.rb-row { display: grid; grid-template-columns: 200px 1fr; gap: 8px; padding: 4px 0; font-size: 13px; border-bottom: 1px solid #f1eee9; }
.rb-row .l { color: #7a766f; }
.rb-row .v { color: #2c2a28; font-weight: 600; }
.rb-pollution { background: #fef2f2; padding: 12px; border-radius: 6px; border-left: 4px solid #dc2626; margin: 12px 0; }
.rb-clean { background: #f0fdf4; padding: 12px; border-radius: 6px; border-left: 4px solid #16a34a; margin: 12px 0; }
.rb-log { background: #0f172a; color: #d1d5db; padding: 14px; border-radius: 8px; font-size: 12px; line-height: 1.5; white-space: pre-wrap; }
.rb-btn-apply { background: #dc2626; color: #fff; padding: 10px 22px; border: 0; border-radius: 4px; font-weight: 700; cursor: pointer; }
</style>

<div class="rb-wrap">
    <h1>🧹 Rollback adresse bien polluée par IA</h1>
    <p style="color: #7a766f; font-size: 13px;">
        Vide les colonnes adresse/cp/ville d'un bien (cas pollution IA pré-fix 2026-05-25).
        L'affichage utilisera ensuite l'adresse de l'immeuble parent via COALESCE JOIN.
    </p>

    <form method="GET" class="rb-form">
        <input type="hidden" name="mode" value="preview">
        <label>ID Bien : </label>
        <input type="number" name="bien_id" value="<?= (int)$bienId ?>" min="1" required>
        <button type="submit">🔍 Inspecter</button>
        <span style="margin-left: 16px; color: #7a766f; font-size: 12px;">
            Ex: 726 (cas connu pollué Test 2 GED Centrale)
        </span>
    </form>

    <?php if ($bien): ?>
    <div class="rb-card">
        <h3>📌 Bien #<?= (int)$bien['id'] ?> — <?= $h($bien['designation'] ?? $bien['reference_bien'] ?? '?') ?></h3>
        <div class="rb-row"><span class="l">Référence</span><span class="v"><?= $h($bien['reference_bien'] ?? '—') ?></span></div>
        <div class="rb-row"><span class="l">Désignation</span><span class="v"><?= $h($bien['designation'] ?? '—') ?></span></div>
        <div class="rb-row"><span class="l">id_immeuble</span><span class="v">#<?= (int)($bien['id_immeuble'] ?? 0) ?></span></div>

        <div class="<?= !empty($bien['adresse_1']) ? 'rb-pollution' : 'rb-clean' ?>">
            <strong><?= !empty($bien['adresse_1']) ? '⚠️ Adresse BIEN saisie (potentiellement polluée)' : '✅ Adresse BIEN vide (héritera de l\'immeuble)' ?></strong>
            <div class="rb-row"><span class="l">biens.adresse_1</span><span class="v"><?= $h($bien['adresse_1'] ?: '∅') ?></span></div>
            <div class="rb-row"><span class="l">biens.adresse_2</span><span class="v"><?= $h($bien['adresse_2'] ?: '∅') ?></span></div>
            <div class="rb-row"><span class="l">biens.code_postal</span><span class="v"><?= $h($bien['code_postal'] ?: '∅') ?></span></div>
            <div class="rb-row"><span class="l">biens.ville</span><span class="v"><?= $h($bien['ville'] ?: '∅') ?></span></div>
        </div>

        <div class="rb-clean">
            <strong>🏢 Adresse IMMEUBLE parent (héritée si bien vide)</strong>
            <div class="rb-row"><span class="l">immeubles.adresse_1</span><span class="v"><?= $h($bien['imm_adresse'] ?? '—') ?></span></div>
            <div class="rb-row"><span class="l">immeubles.code_postal</span><span class="v"><?= $h($bien['imm_cp'] ?? '—') ?></span></div>
            <div class="rb-row"><span class="l">immeubles.ville</span><span class="v"><?= $h($bien['imm_ville'] ?? '—') ?></span></div>
        </div>

        <?php if (!empty($bien['adresse_1']) || !empty($bien['ville'])): ?>
        <form method="POST" action="?mode=apply&bien_id=<?= (int)$bien['id'] ?>"
              onsubmit="return confirm('⚠️ Vider l\'adresse du bien #<?= (int)$bien['id'] ?> ? L\'immeuble prendra le relais.')">
            <input type="hidden" name="confirm" value="CLEAN">
            <button type="submit" class="rb-btn-apply">🧹 Vider adresse bien (UPDATE)</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($log)): ?>
    <div class="rb-log"><?php foreach ($log as $l) echo $h($l) . "\n"; ?></div>
    <?php endif; ?>

    <div style="margin-top: 24px; padding: 14px; background: #fef9e7; border-radius: 8px; border-left: 4px solid #d4a047; font-size: 12px;">
        <strong>📚 Note</strong> — Depuis le 2026-05-25, le pipeline `bien_intake_upload.php` n'écrase plus jamais les biens existants (fix P0-2 : `createdNow=true` requis pour la sync IA). Cet outil est utile uniquement pour les biens corrompus AVANT ce fix.
    </div>
</div>
<?php require_once __DIR__ . '/../inc/footer.php'; ?>
