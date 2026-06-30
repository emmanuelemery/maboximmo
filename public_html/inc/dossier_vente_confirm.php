<?php
/**
 * inc/dossier_vente_confirm.php — Écran de confirmation AVANT création d'un dossier de vente.
 *
 * Inclus par transaction_dossier.php quand on arrive avec ?id_bien=X sans dossier
 * existant et sans &confirm=1. Aucune écriture en base ici : on ne crée le dossier
 * qu'après clic explicite sur « Oui » (qui recharge avec &confirm=1).
 *
 * Contexte attendu : $pdo, $idBien (int). Helpers app_url() / h() déjà chargés.
 */
declare(strict_types=1);

if (!isset($pdo) || empty($idBien)) { http_response_code(400); exit('Contexte invalide.'); }

// Bien (libellé + adresse) pour donner du contexte — lecture seule.
$stB = $pdo->prepare("
    SELECT b.designation, b.reference_bien,
           COALESCE(NULLIF(b.adresse_1,''), im.adresse_1) AS adr,
           COALESCE(NULLIF(b.code_postal,''), im.code_postal) AS cp,
           COALESCE(NULLIF(b.ville,''), im.ville) AS ville
      FROM biens b
      LEFT JOIN immeubles im ON im.id = b.id_immeuble
     WHERE b.id = ? LIMIT 1");
$stB->execute([(int)$idBien]);
$b = $stB->fetch(PDO::FETCH_ASSOC) ?: [];

$titreBien = trim((string)($b['designation'] ?? '')) ?: (trim((string)($b['reference_bien'] ?? '')) ?: ('Bien #' . (int)$idBien));
$adrBien   = trim(($b['adr'] ?? '') . ' ' . ($b['cp'] ?? '') . ' ' . ($b['ville'] ?? ''));

$urlOui = app_url('/transaction_dossier.php?id_bien=' . (int)$idBien . '&confirm=1');
$urlNon = app_url('/bien_360.php?id=' . (int)$idBien);
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ouvrir un dossier de vente ?</title>
  <style>
    *{box-sizing:border-box;}
    body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:#f1f5f9;
         min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
    .dvc-card{background:#fff;max-width:520px;width:100%;border-radius:16px;
              box-shadow:0 20px 60px rgba(36,59,92,.18);overflow:hidden;}
    .dvc-head{background:#243B5C;color:#fff;padding:22px 26px;display:flex;align-items:center;gap:14px;}
    .dvc-head .ic{font-size:30px;}
    .dvc-head h1{margin:0;font-size:18px;font-weight:700;}
    .dvc-body{padding:24px 26px;color:#334155;line-height:1.55;}
    .dvc-body p{margin:0 0 14px;}
    .dvc-bien{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin:4px 0 18px;}
    .dvc-bien .t{font-weight:700;color:#243B5C;}
    .dvc-bien .a{color:#64748b;font-size:13px;margin-top:2px;}
    .dvc-warn{color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;
              padding:10px 14px;font-size:13px;}
    .dvc-actions{display:flex;gap:12px;padding:0 26px 24px;}
    .dvc-btn{flex:1;text-align:center;text-decoration:none;padding:13px 16px;border-radius:10px;
             font-weight:700;font-size:15px;cursor:pointer;border:0;}
    .dvc-oui{background:#D4A047;color:#fff;}
    .dvc-oui:hover{background:#bb8a32;}
    .dvc-non{background:#e2e8f0;color:#334155;}
    .dvc-non:hover{background:#cbd5e1;}
  </style>
</head>
<body>
  <div class="dvc-card">
    <div class="dvc-head">
      <span class="ic">⚠️</span>
      <h1>Ouvrir un dossier de vente ?</h1>
    </div>
    <div class="dvc-body">
      <p>Vous êtes sur le point d'<strong>ouvrir un dossier pour la mise en vente</strong> de ce bien :</p>
      <div class="dvc-bien">
        <div class="t">🏠 <?= h($titreBien) ?></div>
        <?php if ($adrBien !== ''): ?><div class="a"><?= h($adrBien) ?></div><?php endif; ?>
      </div>
      <p class="dvc-warn">Un dossier de vente engage le processus de commercialisation
        (estimation, mandat, annonce…). N'en créez un que si la mise en vente est réelle.</p>
    </div>
    <div class="dvc-actions">
      <a class="dvc-btn dvc-non" href="<?= h($urlNon) ?>">Non, revenir au bien</a>
      <a class="dvc-btn dvc-oui" href="<?= h($urlOui) ?>">Oui, ouvrir le dossier</a>
    </div>
  </div>
</body>
</html>
