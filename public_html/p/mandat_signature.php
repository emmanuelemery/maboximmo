<?php
declare(strict_types=1);
/**
 * p/mandat_signature.php — Page publique de signature d'un mandat de vente (token).
 *
 * Sans authentification. Affiche le mandat + un formulaire de signature simple
 * (nom + « lu et approuvé »). À la validation, capture IP + horodatage + user-agent
 * comme preuve (signature électronique simple).
 *
 * URL : /p/mandat_signature.php?t=<token_64_hex>
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/mandat_signature.php';

$pdo = $GLOBALS['pdo'];

$token = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); die('Lien invalide.'); }

$sig = msig_get_by_token($pdo, $token);
if (!$sig) { http_response_code(404); die('Ce lien n\'existe pas.'); }

// Capture IP (best-effort derrière proxy Hostinger).
function msig_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', (string)$_SERVER[$k])[0]);
    }
    return '';
}

$flash = null;
$justSigned = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sig['statut'] !== 'signe') {
    $nom       = trim((string)($_POST['nom_signataire'] ?? ''));
    $approuve  = !empty($_POST['lu_approuve']);
    if ($nom === '')        { $flash = 'Merci d\'indiquer votre nom complet.'; }
    elseif (!$approuve)     { $flash = 'Merci de cocher « lu et approuvé ».'; }
    else {
        $res = msig_sign($pdo, $token, $nom, msig_client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!empty($res['ok'])) { $justSigned = true; $sig = msig_get_by_token($pdo, $token); }
        else { $flash = $res['error'] ?? 'Erreur lors de la signature.'; }
    }
}

$dejaSigne = ($sig['statut'] === 'signe');
$fmtPrix = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$prix = $sig['prix_demande_initial'] ?? $sig['prix_vente_estime'] ?? null;
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$adresse = trim(($sig['bien_adresse'] ?? '') . ' ' . ($sig['bien_cp'] ?? '') . ' ' . ($sig['bien_ville'] ?? ''));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signature du mandat de vente</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:#f1f5f9;color:#1f2937;}
  .wrap{max-width:680px;margin:0 auto;padding:24px 16px 60px;}
  .card{background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:28px 30px;margin-top:18px;}
  h1{font-size:22px;margin:0 0 4px;}
  .sub{color:#64748b;font-size:13px;}
  .terms{margin:20px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
  .terms .row{display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;}
  .terms .row:last-child{border-bottom:0;}
  .terms .k{color:#64748b;} .terms .v{font-weight:700;}
  .ok-banner{background:linear-gradient(135deg,#e9f7ef,#d7f0e0);border:1px solid #9ad3ab;color:#0b6b35;border-radius:12px;padding:18px 20px;}
  .err{background:#fde2e1;border:1px solid #f3b4b1;color:#a11;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px;}
  label.fld{display:block;font-size:12px;font-weight:700;color:#475569;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.05em;}
  input[type=text]{width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;}
  .chk{display:flex;gap:10px;align-items:flex-start;margin:16px 0;font-size:14px;}
  .chk input{margin-top:3px;transform:scale(1.3);}
  .btn{width:100%;margin-top:18px;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;font-size:16px;font-weight:800;cursor:pointer;}
  .legal{font-size:11px;color:#94a3b8;margin-top:16px;line-height:1.5;}
  .proof{font-size:12px;color:#475569;margin-top:10px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>📝 Mandat de vente</h1>
    <div class="sub"><?= $h($sig['numero_mandat'] ?: '') ?> · <?= $h($sig['designation'] ?: $sig['reference_bien']) ?></div>

    <div class="terms">
      <div class="row"><span class="k">Bien</span><span class="v"><?= $h($sig['reference_bien']) ?></span></div>
      <?php if ($adresse !== ''): ?><div class="row"><span class="k">Adresse</span><span class="v"><?= $h($adresse) ?></span></div><?php endif; ?>
      <div class="row"><span class="k">Type de mandat</span><span class="v"><?= !empty($sig['exclusif']) ? 'Exclusif' : 'Simple' ?></span></div>
      <div class="row"><span class="k">Prix de vente</span><span class="v"><?= $h($fmtPrix($prix)) ?></span></div>
      <?php if ($sig['honoraires'] !== null && $sig['honoraires'] !== ''): ?>
        <div class="row"><span class="k">Honoraires</span><span class="v"><?= $h($fmtPrix($sig['honoraires'])) ?><?= $sig['honoraires_charge'] ? ' (à charge ' . $h($sig['honoraires_charge']) . ')' : '' ?></span></div>
      <?php endif; ?>
      <div class="row"><span class="k">Prise d'effet</span><span class="v"><?= $h($fmtDate($sig['date_debut'])) ?></span></div>
      <?php if ($sig['date_fin']): ?><div class="row"><span class="k">Échéance</span><span class="v"><?= $h($fmtDate($sig['date_fin'])) ?></span></div><?php endif; ?>
    </div>

    <?php if ($dejaSigne): ?>
      <div class="ok-banner">
        <strong>✅ Mandat signé<?= $justSigned ? '' : '' ?>.</strong><br>
        Signé par <strong><?= $h($sig['nom_signataire']) ?></strong> le <?= $h(date('d/m/Y à H:i', strtotime((string)$sig['signed_at']))) ?>.
        <div class="proof">Preuve enregistrée — IP : <?= $h($sig['ip'] ?: '—') ?> · horodatage : <?= $h($sig['signed_at']) ?>.</div>
      </div>
      <p class="legal">Une copie de ce mandat signé est conservée par votre agence. Vous pouvez fermer cette page.</p>
    <?php else: ?>
      <?php if ($flash): ?><div class="err"><?= $h($flash) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="t" value="<?= $h($token) ?>">
        <label class="fld">Votre nom et prénom</label>
        <input type="text" name="nom_signataire" value="<?= $h($_POST['nom_signataire'] ?? '') ?>" placeholder="Ex. Jean Dupont" required autofocus>
        <label class="chk">
          <input type="checkbox" name="lu_approuve" value="1" required>
          <span>J'ai lu et j'approuve les termes de ce mandat de vente. En signant, je reconnais que ma signature électronique a valeur d'engagement.</span>
        </label>
        <button class="btn" type="submit">✍️ Signer le mandat</button>
        <p class="legal">
          Signature électronique simple : votre adresse IP (<?= $h(msig_client_ip() ?: 'non détectée') ?>) et l'horodatage de votre validation
          seront enregistrés comme preuve. Ce procédé n'est pas une signature électronique qualifiée.
        </p>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
