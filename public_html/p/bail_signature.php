<?php
declare(strict_types=1);
/**
 * p/bail_signature.php — Page publique de signature d'un bail commercial (token).
 * Sans authentification. Affiche les conditions + formulaire (nom + « lu et approuvé »).
 * Preuve = IP + horodatage + user-agent (signature électronique simple).
 * URL : /p/bail_signature.php?t=<token_64_hex>
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bail_signature.php';

$pdo = $GLOBALS['pdo'];
$token = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); die('Lien invalide.'); }

$sig = bsig_get_by_token($pdo, $token);
if (!$sig) { http_response_code(404); die('Ce lien n\'existe pas.'); }

function bsig_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', (string)$_SERVER[$k])[0]);
    }
    return '';
}

$flash = null; $justSigned = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sig['statut'] !== 'signe') {
    $nom = trim((string)($_POST['nom_signataire'] ?? ''));
    $approuve = !empty($_POST['lu_approuve']);
    if ($nom === '')    { $flash = 'Merci d\'indiquer votre nom complet.'; }
    elseif (!$approuve) { $flash = 'Merci de cocher « lu et approuvé ».'; }
    else {
        $res = bsig_sign($pdo, $token, $nom, bsig_client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!empty($res['ok'])) { $justSigned = true; $sig = bsig_get_by_token($pdo, $token); }
        else { $flash = $res['error'] ?? 'Erreur lors de la signature.'; }
    }
}

$dejaSigne = ($sig['statut'] === 'signe');
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eur = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$adresse = trim(($sig['bien_adresse'] ?? '') . ' ' . ($sig['bien_cp'] ?? '') . ' ' . ($sig['bien_ville'] ?? ''));
$preneur = $sig['locataire_raison_sociale'] ?: trim((string)($sig['locataire_prenom'] ?? '') . ' ' . ($sig['locataire_nom'] ?? ''));
$roleLbl = ($sig['role_code'] ?? '') === 'caution' ? 'la caution (garant)' : 'le preneur';
$loyerA = ($sig['loyer_mensuel_hc'] ?? null) !== null ? (float)$sig['loyer_mensuel_hc'] * 12 : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signature du bail commercial</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:#f1f5f9;color:#1f2937;}
  .wrap{max-width:680px;margin:0 auto;padding:24px 16px 60px;}
  .card{background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:28px 30px;margin-top:18px;}
  h1{font-size:22px;margin:0 0 4px;}
  .sub{color:#64748b;font-size:13px;}
  .terms{margin:20px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
  .terms .row{display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;gap:14px;}
  .terms .row:last-child{border-bottom:0;}
  .terms .k{color:#64748b;} .terms .v{font-weight:700;text-align:right;}
  .ok-banner{background:linear-gradient(135deg,#e9f7ef,#d7f0e0);border:1px solid #9ad3ab;color:#0b6b35;border-radius:12px;padding:18px 20px;}
  .err{background:#fde2e1;border:1px solid #f3b4b1;color:#a11;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px;}
  label.fld{display:block;font-size:12px;font-weight:700;color:#475569;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.05em;}
  input[type=text]{width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;}
  .chk{display:flex;gap:10px;align-items:flex-start;margin:16px 0;font-size:14px;}
  .chk input{margin-top:3px;transform:scale(1.3);}
  .btn{width:100%;margin-top:18px;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,#5f8f93,#84A7AB);color:#fff;font-size:16px;font-weight:800;cursor:pointer;}
  .legal{font-size:11px;color:#94a3b8;margin-top:16px;line-height:1.5;}
  .proof{font-size:12px;color:#475569;margin-top:10px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>🔑 Bail commercial</h1>
    <div class="sub"><?= $h($sig['numero_bail'] ?: '') ?> · <?= $h($sig['designation'] ?: $sig['reference_bien']) ?> · Vous signez en tant que <strong><?= $h($roleLbl) ?></strong></div>

    <div class="terms">
      <div class="row"><span class="k">Local</span><span class="v"><?= $h($sig['reference_bien']) ?></span></div>
      <?php if ($adresse !== ''): ?><div class="row"><span class="k">Adresse</span><span class="v"><?= $h($adresse) ?></span></div><?php endif; ?>
      <div class="row"><span class="k">Preneur</span><span class="v"><?= $h($preneur ?: '—') ?></span></div>
      <div class="row"><span class="k">Loyer annuel HT</span><span class="v"><?= $h($eur($loyerA)) ?></span></div>
      <div class="row"><span class="k">Charges / mois</span><span class="v"><?= $h($eur($sig['charges_mensuelles'] ?? null)) ?></span></div>
      <div class="row"><span class="k">Prise d'effet</span><span class="v"><?= $h($fmtDate($sig['date_prise_effet'] ?? null)) ?></span></div>
    </div>

    <?php if ($dejaSigne): ?>
      <div class="ok-banner">
        <strong>✅ Bail signé.</strong><br>
        Signé par <strong><?= $h($sig['nom_signataire']) ?></strong> le <?= $h(date('d/m/Y à H:i', strtotime((string)$sig['signed_at']))) ?>.
        <div class="proof">Preuve enregistrée — IP : <?= $h($sig['ip'] ?: '—') ?> · horodatage : <?= $h($sig['signed_at']) ?>.</div>
      </div>
      <p class="legal">Une copie de ce bail signé est conservée par votre agence. Le document complet vous a été adressé en pièce jointe. Vous pouvez fermer cette page.</p>
    <?php else: ?>
      <?php if ($flash): ?><div class="err"><?= $h($flash) ?></div><?php endif; ?>
      <p style="font-size:13px;color:#475569;">Le bail complet vous a été envoyé <strong>en pièce jointe</strong> de l'email. Merci de le lire avant de signer.</p>
      <form method="post">
        <input type="hidden" name="t" value="<?= $h($token) ?>">
        <label class="fld">Votre nom et prénom</label>
        <input type="text" name="nom_signataire" value="<?= $h($_POST['nom_signataire'] ?? '') ?>" placeholder="Ex. Jean Dupont" required autofocus>
        <label class="chk">
          <input type="checkbox" name="lu_approuve" value="1" required>
          <span>J'ai lu et j'approuve les termes de ce bail commercial. En signant, je reconnais que ma signature électronique a valeur d'engagement.</span>
        </label>
        <button class="btn" type="submit">✍️ Signer le bail</button>
        <p class="legal">
          Signature électronique simple : votre adresse IP (<?= $h(bsig_client_ip() ?: 'non détectée') ?>) et l'horodatage de votre validation
          seront enregistrés comme preuve. Ce procédé n'est pas une signature électronique qualifiée.
        </p>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
