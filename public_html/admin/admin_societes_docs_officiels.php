<?php
declare(strict_types=1);

/**
 * ADMIN — Saisie manuelle des documents officiels par société
 *
 * Bypass de l'OCR Sonnet : permet à un super admin de saisir directement
 * les valeurs des docs officiels (KBIS, CPI, GF, RC pro) sur `societes.*`,
 * sans passer par upload + Sonnet.
 *
 * Cas d'usage :
 *   - L'OCR a échoué (fichier illisible, scan dégradé, API timeout)
 *   - On a déjà les valeurs sous la main (papier ou autre source)
 *   - On veut éviter le coût IA pour des docs qu'on connaît déjà
 *
 * URL : /admin/admin_societes_docs_officiels.php
 * Sécurité : super admin (role_id = 1) uniquement.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if ((int)($_SESSION['id_role'] ?? 0) !== 1) {
    http_response_code(403);
    exit('<h1>403 — Réservé super admin (role_id=1)</h1>');
}

$pdo = $GLOBALS['pdo'];

$flash = null;

// Sauvegarde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $idSoc = (int)($_POST['id_societe'] ?? 0);
    if ($idSoc > 0) {
        $champs = [
            'carte_pro_numero', 'carte_pro_cci', 'carte_pro_validite',
            'rc_pro', 'rc_pro_numero', 'rc_pro_validite', 'rc_pro_montant',
            'garant_financier', 'garant_validite', 'garant_montant',
            'kbis_numero', 'kbis_date',
        ];
        $params = [':id' => $idSoc];
        $sets   = [];
        foreach ($champs as $c) {
            $val = trim((string)($_POST[$c] ?? ''));
            if ($val === '') $val = null;
            elseif (in_array($c, ['rc_pro_montant', 'garant_montant'])) {
                $val = (float)str_replace([' ', ','], ['', '.'], $val);
            }
            $sets[]            = "`{$c}` = :{$c}";
            $params[":{$c}"]   = $val;
        }
        try {
            $sql = "UPDATE societes SET " . implode(', ', $sets) . " WHERE id = :id";
            $st  = $pdo->prepare($sql);
            $st->execute($params);
            $flash = ['ok' => true, 'msg' => '✅ Société #' . $idSoc . ' enregistrée. Toutes les agences héritent automatiquement via le helper agence_load_with_societe_docs().'];
        } catch (Throwable $e) {
            $flash = ['ok' => false, 'msg' => '❌ Erreur SQL : ' . htmlspecialchars($e->getMessage())];
        }
    }
}

// Liste des sociétés
$societes = $pdo->query("
    SELECT id, nom,
           carte_pro_numero, carte_pro_cci, carte_pro_validite,
           rc_pro, rc_pro_numero, rc_pro_validite, rc_pro_montant,
           garant_financier, garant_validite, garant_montant,
           kbis_numero, kbis_date
    FROM societes
    WHERE nom != 'Externe'
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

$csrf = csrf_token();

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8">
<title>Docs officiels — saisie manuelle</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 1400px; margin: 24px auto; padding: 0 20px; line-height: 1.5; background: #f5f7fa; }
  h1 { color: #0f172a; margin-bottom: 6px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 20px; }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .flash.ko { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .societe-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 22px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
  .societe-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 6px; display: flex; align-items: center; gap: 8px; }
  .societe-id { font-family: monospace; font-size: 11px; color: #94a3b8; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; }
  .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 14px; }
  .group { background: #f8fafc; border-radius: 8px; padding: 12px 14px; border: 1px solid #e5e7eb; }
  .group h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #475569; margin: 0 0 10px; font-weight: 700; }
  .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }
  .field label { font-size: 11px; color: #64748b; font-weight: 600; }
  .field input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 13px; background: #fff; }
  .field input:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 2px rgba(14,165,233,0.15); }
  .actions { margin-top: 14px; display: flex; gap: 10px; }
  .btn { padding: 8px 18px; border-radius: 8px; border: none; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
  .btn-primary { background: #0ea5e9; color: #fff; }
  .btn-primary:hover { background: #0284c7; }
  .help { font-size: 11px; color: #94a3b8; margin-top: 6px; font-style: italic; }
</style>
</head><body>

<h1>📋 Documents officiels — saisie manuelle par société</h1>
<p class="sub">Pour les cas où l'OCR a échoué ou pour gagner du temps. Toutes les agences de la société héritent automatiquement (via JOIN societes au runtime).</p>

<?php if ($flash): ?>
  <div class="flash <?= $flash['ok'] ? 'ok' : 'ko' ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<?php foreach ($societes as $s): ?>
<form method="post" class="societe-card">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="id_societe" value="<?= (int)$s['id'] ?>">

  <h2 class="societe-title">
    <?= htmlspecialchars((string)$s['nom']) ?>
    <span class="societe-id">id=<?= (int)$s['id'] ?></span>
  </h2>

  <div class="grid">
    <!-- KBIS -->
    <div class="group">
      <h3>📜 KBIS</h3>
      <div class="field">
        <label>N° RCS</label>
        <input type="text" name="kbis_numero" value="<?= htmlspecialchars((string)($s['kbis_numero'] ?? '')) ?>" placeholder="ex : 123 456 789 RCS Lyon">
      </div>
      <div class="field">
        <label>Date émission</label>
        <input type="date" name="kbis_date" value="<?= htmlspecialchars((string)($s['kbis_date'] ?? '')) ?>">
      </div>
    </div>

    <!-- Carte pro CPI -->
    <div class="group">
      <h3>🪪 Carte pro (CPI)</h3>
      <div class="field">
        <label>N° CPI</label>
        <input type="text" name="carte_pro_numero" value="<?= htmlspecialchars((string)($s['carte_pro_numero'] ?? '')) ?>" placeholder="ex : CPI 6901 2018 000 031 709">
      </div>
      <div class="field">
        <label>CCI émettrice</label>
        <input type="text" name="carte_pro_cci" value="<?= htmlspecialchars((string)($s['carte_pro_cci'] ?? '')) ?>" placeholder="ex : CCI Lyon Métropole">
      </div>
      <div class="field">
        <label>Validité (date fin)</label>
        <input type="date" name="carte_pro_validite" value="<?= htmlspecialchars((string)($s['carte_pro_validite'] ?? '')) ?>">
      </div>
    </div>

    <!-- RC Pro -->
    <div class="group">
      <h3>🛡️ RC Pro</h3>
      <div class="field">
        <label>Assureur</label>
        <input type="text" name="rc_pro" value="<?= htmlspecialchars((string)($s['rc_pro'] ?? '')) ?>" placeholder="ex : AXA, MMA, Allianz">
      </div>
      <div class="field">
        <label>N° contrat</label>
        <input type="text" name="rc_pro_numero" value="<?= htmlspecialchars((string)($s['rc_pro_numero'] ?? '')) ?>">
      </div>
      <div class="field">
        <label>Validité</label>
        <input type="date" name="rc_pro_validite" value="<?= htmlspecialchars((string)($s['rc_pro_validite'] ?? '')) ?>">
      </div>
      <div class="field">
        <label>Plafond garantie (€)</label>
        <input type="text" name="rc_pro_montant" value="<?= htmlspecialchars((string)($s['rc_pro_montant'] ?? '')) ?>" placeholder="ex : 8000000">
      </div>
    </div>

    <!-- Garantie financière -->
    <div class="group">
      <h3>💰 Garantie financière</h3>
      <div class="field">
        <label>Garant</label>
        <input type="text" name="garant_financier" value="<?= htmlspecialchars((string)($s['garant_financier'] ?? '')) ?>" placeholder="ex : Galian, Socaf, MMA Caution">
      </div>
      <div class="field">
        <label>Validité</label>
        <input type="date" name="garant_validite" value="<?= htmlspecialchars((string)($s['garant_validite'] ?? '')) ?>">
      </div>
      <div class="field">
        <label>Plafond garantie (€)</label>
        <input type="text" name="garant_montant" value="<?= htmlspecialchars((string)($s['garant_montant'] ?? '')) ?>" placeholder="ex : 110000">
      </div>
    </div>
  </div>

  <div class="actions">
    <button type="submit" class="btn btn-primary">💾 Enregistrer cette société</button>
    <span class="help">Modifie uniquement cette société. Les agences héritent automatiquement.</span>
  </div>
</form>
<?php endforeach; ?>

<?php if (empty($societes)): ?>
  <div class="flash ko">Aucune société trouvée. Vérifie la table <code>societes</code>.</div>
<?php endif; ?>

</body></html>
