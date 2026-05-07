<?php
declare(strict_types=1);

/**
 * ADMIN — Saisie manuelle des documents officiels par société + activités
 *
 * Permet à un super admin de :
 *   - Cocher les activités exercées par chaque société (Transaction, Gestion,
 *     Syndic, Marchand, Immobilier, RH)
 *   - Saisir les docs racine société (KBIS + Carte pro CPI)
 *   - Saisir UNE attestation RCP + UNE attestation Garantie financière par
 *     activité COCHÉE (donc max 4 RCP + 4 GF par société)
 *
 * Bypass de l'OCR Sonnet : utile quand l'OCR a échoué ou pour gain de temps.
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

// Activités exercées par les sociétés Hoguet
const ACTIVITES = ['transaction', 'gestion', 'syndic', 'marchand'];
const ACTIVITES_LABELS = [
    'transaction' => 'Transaction',
    'gestion'     => 'Gestion locative',
    'syndic'      => 'Syndic',
    'marchand'    => 'Marchand de biens',
];

$flash = null;

// ─── POST : sauvegarde d'une société ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $idSoc = (int)($_POST['id_societe'] ?? 0);
    if ($idSoc > 0) {
        try {
            $pdo->beginTransaction();

            // 1. Société : flags activités + docs racine (KBIS, carte pro CPI)
            $sql = "UPDATE societes SET
                activite_immobilier  = :imm,
                activite_transaction = :tr,
                activite_gestion     = :ge,
                activite_syndic      = :sy,
                activite_marchand    = :ma,
                activite_rh          = :rh,
                kbis_numero          = :kbis_num,
                kbis_date            = :kbis_date,
                carte_pro_numero     = :cpi_num,
                carte_pro_cci        = :cpi_cci,
                carte_pro_validite   = :cpi_val
              WHERE id = :id";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':id'       => $idSoc,
                ':imm'      => !empty($_POST['activite_immobilier'])  ? 1 : 0,
                ':tr'       => !empty($_POST['activite_transaction']) ? 1 : 0,
                ':ge'       => !empty($_POST['activite_gestion'])     ? 1 : 0,
                ':sy'       => !empty($_POST['activite_syndic'])      ? 1 : 0,
                ':ma'       => !empty($_POST['activite_marchand'])    ? 1 : 0,
                ':rh'       => !empty($_POST['activite_rh'])          ? 1 : 0,
                ':kbis_num' => trim((string)($_POST['kbis_numero']      ?? '')) ?: null,
                ':kbis_date'=> trim((string)($_POST['kbis_date']        ?? '')) ?: null,
                ':cpi_num'  => trim((string)($_POST['carte_pro_numero']  ?? '')) ?: null,
                ':cpi_cci'  => trim((string)($_POST['carte_pro_cci']     ?? '')) ?: null,
                ':cpi_val'  => trim((string)($_POST['carte_pro_validite']?? '')) ?: null,
            ]);

            // 2. RCP + GF par activité — UPSERT sur societe_activites_docs
            $upsertSad = $pdo->prepare("
                INSERT INTO societe_activites_docs
                  (id_societe, activite,
                   rc_pro_assureur, rc_pro_numero, rc_pro_validite, rc_pro_montant,
                   garant_nom, garant_numero, garant_validite, garant_montant,
                   updated_by)
                VALUES
                  (:id_soc, :act,
                   :rcp_a, :rcp_n, :rcp_v, :rcp_m,
                   :gf_n, :gf_num, :gf_v, :gf_m,
                   :user)
                ON DUPLICATE KEY UPDATE
                  rc_pro_assureur = VALUES(rc_pro_assureur),
                  rc_pro_numero   = VALUES(rc_pro_numero),
                  rc_pro_validite = VALUES(rc_pro_validite),
                  rc_pro_montant  = VALUES(rc_pro_montant),
                  garant_nom      = VALUES(garant_nom),
                  garant_numero   = VALUES(garant_numero),
                  garant_validite = VALUES(garant_validite),
                  garant_montant  = VALUES(garant_montant),
                  updated_by      = VALUES(updated_by)
            ");

            $userIdEdit = (int)($_SESSION['user_id'] ?? 0);

            foreach (ACTIVITES as $act) {
                $rcp_a = trim((string)($_POST["rcp_{$act}_assureur"] ?? ''));
                $rcp_n = trim((string)($_POST["rcp_{$act}_numero"]   ?? ''));
                $rcp_v = trim((string)($_POST["rcp_{$act}_validite"] ?? ''));
                $rcp_m = trim((string)($_POST["rcp_{$act}_montant"]  ?? ''));
                $gf_n  = trim((string)($_POST["gf_{$act}_nom"]       ?? ''));
                $gf_num= trim((string)($_POST["gf_{$act}_numero"]    ?? ''));
                $gf_v  = trim((string)($_POST["gf_{$act}_validite"]  ?? ''));
                $gf_m  = trim((string)($_POST["gf_{$act}_montant"]   ?? ''));

                // Skip si rien rempli pour cette activité
                if ($rcp_a === '' && $rcp_n === '' && $gf_n === '' && $gf_num === '') continue;

                $upsertSad->execute([
                    ':id_soc' => $idSoc,
                    ':act'    => $act,
                    ':rcp_a'  => $rcp_a ?: null,
                    ':rcp_n'  => $rcp_n ?: null,
                    ':rcp_v'  => $rcp_v ?: null,
                    ':rcp_m'  => $rcp_m !== '' ? (float)str_replace([' ', ','], ['', '.'], $rcp_m) : null,
                    ':gf_n'   => $gf_n  ?: null,
                    ':gf_num' => $gf_num?: null,
                    ':gf_v'   => $gf_v  ?: null,
                    ':gf_m'   => $gf_m  !== '' ? (float)str_replace([' ', ','], ['', '.'], $gf_m) : null,
                    ':user'   => $userIdEdit ?: null,
                ]);
            }

            $pdo->commit();
            $flash = ['ok' => true, 'msg' => '✅ Société #' . $idSoc . ' enregistrée. Toutes ses agences héritent automatiquement.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $flash = ['ok' => false, 'msg' => '❌ Erreur : ' . htmlspecialchars($e->getMessage())];
        }
    }
}

// ─── Chargement données ───
$societes = $pdo->query("
    SELECT id, nom,
           activite_immobilier, activite_transaction, activite_gestion,
           activite_syndic, activite_marchand, activite_rh,
           carte_pro_numero, carte_pro_cci, carte_pro_validite,
           kbis_numero, kbis_date
    FROM societes
    WHERE nom != 'Externe'
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

// Charge les activités docs en map [id_societe][activite] = row
$activitesDocs = [];
$rows = $pdo->query("SELECT * FROM societe_activites_docs ORDER BY id_societe, activite")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $activitesDocs[(int)$r['id_societe']][$r['activite']] = $r;
}

$csrf = csrf_token();

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8">
<title>Docs officiels par société + activités</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 1500px; margin: 24px auto; padding: 0 20px; line-height: 1.5; background: #f5f7fa; color: #0f172a; }
  h1 { margin-bottom: 6px; }
  .sub { color: #64748b; font-size: 13px; margin-bottom: 20px; }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
  .flash.ok { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .flash.ko { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }

  .societe-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
  .societe-title { font-size: 20px; font-weight: 700; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
  .societe-id { font-family: monospace; font-size: 11px; color: #94a3b8; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; }

  /* Activités */
  .activites { display: flex; flex-wrap: wrap; gap: 10px; margin: 14px 0 18px; padding: 12px 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; }
  .activites-label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.06em; align-self: center; margin-right: 6px; }
  .check-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fff; border: 1.5px solid #e5e7eb; border-radius: 99px; font-size: 13px; cursor: pointer; user-select: none; transition: all 0.15s; }
  .check-pill input { margin: 0; }
  .check-pill:hover { border-color: #94a3b8; }
  .check-pill.has-input input:checked + span { font-weight: 700; }
  .check-pill input:checked ~ * { color: #0f766e; }
  .check-pill:has(input:checked) { background: #f0fdfa; border-color: #14b8a6; }

  /* Sections principales */
  .grid-haut { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
  .group { background: #f8fafc; border-radius: 10px; padding: 14px 16px; border: 1px solid #e2e8f0; }
  .group h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #475569; margin: 0 0 12px; font-weight: 700; }

  /* Activités RCP/GF */
  .activite-block { border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin-top: 12px; transition: all 0.2s; background: #fff; }
  .activite-block.active { border-color: #14b8a6; background: #f0fdfa; }
  .activite-block.disabled { opacity: 0.45; }
  .activite-block h4 { font-size: 14px; margin: 0 0 12px; color: #0f766e; font-weight: 700; }
  .activite-block.disabled h4 { color: #94a3b8; }
  .activite-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  /* Champs */
  .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }
  .field label { font-size: 11px; color: #64748b; font-weight: 600; }
  .field input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 13px; background: #fff; }
  .field input:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 2px rgba(14,165,233,0.15); }

  /* Actions */
  .actions { margin-top: 18px; display: flex; gap: 10px; align-items: center; }
  .btn { padding: 9px 20px; border-radius: 8px; border: none; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
  .btn-primary { background: #0ea5e9; color: #fff; }
  .btn-primary:hover { background: #0284c7; }
  .help { font-size: 11px; color: #94a3b8; font-style: italic; }
</style>
</head><body>

<h1>📋 Documents officiels par société + activités</h1>
<p class="sub">Coche les activités exercées par chaque société, puis saisis pour chacune l'assureur RC pro et le garant financier. Les agences héritent automatiquement.</p>

<?php if ($flash): ?>
  <div class="flash <?= $flash['ok'] ? 'ok' : 'ko' ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<?php foreach ($societes as $s):
    $idS = (int)$s['id'];
    $actsS = $activitesDocs[$idS] ?? [];
?>
<form method="post" class="societe-card" data-societe-id="<?= $idS ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="id_societe" value="<?= $idS ?>">

  <h2 class="societe-title">
    <?= htmlspecialchars((string)$s['nom']) ?>
    <span class="societe-id">id=<?= $idS ?></span>
  </h2>

  <!-- Activités cochables -->
  <div class="activites">
    <span class="activites-label">Activités exercées :</span>
    <label class="check-pill"><input type="checkbox" name="activite_immobilier" <?= !empty($s['activite_immobilier']) ? 'checked' : '' ?>><span>🏠 Immobilier</span></label>
    <label class="check-pill act-toggle" data-act="transaction"><input type="checkbox" name="activite_transaction" <?= !empty($s['activite_transaction']) ? 'checked' : '' ?>><span>📑 Transaction</span></label>
    <label class="check-pill act-toggle" data-act="gestion"><input type="checkbox" name="activite_gestion" <?= !empty($s['activite_gestion']) ? 'checked' : '' ?>><span>🏘️ Gestion locative</span></label>
    <label class="check-pill act-toggle" data-act="syndic"><input type="checkbox" name="activite_syndic" <?= !empty($s['activite_syndic']) ? 'checked' : '' ?>><span>🏢 Syndic</span></label>
    <label class="check-pill act-toggle" data-act="marchand"><input type="checkbox" name="activite_marchand" <?= !empty($s['activite_marchand']) ? 'checked' : '' ?>><span>💼 Marchand de biens</span></label>
    <label class="check-pill"><input type="checkbox" name="activite_rh" <?= !empty($s['activite_rh']) ? 'checked' : '' ?>><span>👥 RH</span></label>
  </div>

  <!-- KBIS + Carte pro CPI (au niveau racine société) -->
  <div class="grid-haut">
    <div class="group">
      <h3>📜 KBIS</h3>
      <div class="field"><label>N° RCS</label><input type="text" name="kbis_numero" value="<?= htmlspecialchars((string)($s['kbis_numero'] ?? '')) ?>" placeholder="ex : 123 456 789 RCS Lyon"></div>
      <div class="field"><label>Date émission</label><input type="date" name="kbis_date" value="<?= htmlspecialchars((string)($s['kbis_date'] ?? '')) ?>"></div>
    </div>
    <div class="group">
      <h3>🪪 Carte pro CPI (Hoguet)</h3>
      <div class="field"><label>N° CPI</label><input type="text" name="carte_pro_numero" value="<?= htmlspecialchars((string)($s['carte_pro_numero'] ?? '')) ?>" placeholder="ex : CPI 6901 2018 000 031 709"></div>
      <div class="field"><label>CCI émettrice</label><input type="text" name="carte_pro_cci" value="<?= htmlspecialchars((string)($s['carte_pro_cci'] ?? '')) ?>" placeholder="ex : CCI Lyon Métropole"></div>
      <div class="field"><label>Validité (date fin)</label><input type="date" name="carte_pro_validite" value="<?= htmlspecialchars((string)($s['carte_pro_validite'] ?? '')) ?>"></div>
    </div>
  </div>

  <!-- 4 blocs RCP + GF par activité (T/G/S/M) -->
  <?php foreach (ACTIVITES as $act):
      $isActive = !empty($s["activite_{$act}"]);
      $row = $actsS[$act] ?? [];
      $emoji = ['transaction'=>'📑','gestion'=>'🏘️','syndic'=>'🏢','marchand'=>'💼'][$act];
  ?>
  <div class="activite-block <?= $isActive ? 'active' : 'disabled' ?>" data-activite="<?= $act ?>">
    <h4><?= $emoji ?> <?= ACTIVITES_LABELS[$act] ?></h4>
    <div class="activite-row">
      <div>
        <strong style="font-size:11px;color:#475569;">🛡️ RC Pro <?= ACTIVITES_LABELS[$act] ?></strong>
        <div class="field"><label>Assureur</label><input type="text" name="rcp_<?= $act ?>_assureur" value="<?= htmlspecialchars((string)($row['rc_pro_assureur'] ?? '')) ?>" placeholder="ex : AXA, MMA, Allianz"></div>
        <div class="field"><label>N° contrat</label><input type="text" name="rcp_<?= $act ?>_numero" value="<?= htmlspecialchars((string)($row['rc_pro_numero'] ?? '')) ?>"></div>
        <div class="field"><label>Validité</label><input type="date" name="rcp_<?= $act ?>_validite" value="<?= htmlspecialchars((string)($row['rc_pro_validite'] ?? '')) ?>"></div>
        <div class="field"><label>Plafond garantie (€)</label><input type="text" name="rcp_<?= $act ?>_montant" value="<?= htmlspecialchars((string)($row['rc_pro_montant'] ?? '')) ?>" placeholder="ex : 8000000"></div>
      </div>
      <div>
        <strong style="font-size:11px;color:#475569;">💰 Garantie financière <?= ACTIVITES_LABELS[$act] ?></strong>
        <div class="field"><label>Garant</label><input type="text" name="gf_<?= $act ?>_nom" value="<?= htmlspecialchars((string)($row['garant_nom'] ?? '')) ?>" placeholder="ex : Galian, Socaf"></div>
        <div class="field"><label>N° contrat</label><input type="text" name="gf_<?= $act ?>_numero" value="<?= htmlspecialchars((string)($row['garant_numero'] ?? '')) ?>"></div>
        <div class="field"><label>Validité</label><input type="date" name="gf_<?= $act ?>_validite" value="<?= htmlspecialchars((string)($row['garant_validite'] ?? '')) ?>"></div>
        <div class="field"><label>Plafond garantie (€)</label><input type="text" name="gf_<?= $act ?>_montant" value="<?= htmlspecialchars((string)($row['garant_montant'] ?? '')) ?>" placeholder="ex : 110000"></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="actions">
    <button type="submit" class="btn btn-primary">💾 Enregistrer cette société</button>
    <span class="help">Toutes ses agences héritent automatiquement (helper agence_load_with_societe_docs).</span>
  </div>
</form>
<?php endforeach; ?>

<?php if (empty($societes)): ?>
  <div class="flash ko">Aucune société trouvée. Vérifie la table <code>societes</code>.</div>
<?php endif; ?>

<script>
// Toggle visuel : si on décoche une activité, le bloc RCP/GF associé devient grisé
document.querySelectorAll('.act-toggle input').forEach(cb => {
    const updateBlock = () => {
        const act = cb.closest('.act-toggle').dataset.act;
        const block = cb.closest('form').querySelector('.activite-block[data-activite="' + act + '"]');
        if (block) {
            block.classList.toggle('active', cb.checked);
            block.classList.toggle('disabled', !cb.checked);
        }
    };
    cb.addEventListener('change', updateBlock);
});
</script>

</body></html>
