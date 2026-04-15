<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mailer.php';
require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$roleId = current_role_id();
$userId = current_user_id();

// Accès réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    exit('Accès réservé aux managers et administrateurs.');
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors  = [];
$success = '';
$old     = [];

// Récupérer l'agence du manager connecté
$managerAgenceId = 0;
try {
    $stmtMgr = $pdo->prepare("SELECT id_agence FROM users WHERE id = ?");
    $stmtMgr->execute([$userId]);
    $managerRow = $stmtMgr->fetch(PDO::FETCH_ASSOC);
    $managerAgenceId = (int)($managerRow['id_agence'] ?? 0);
} catch (Exception $e) {}

// Charger collaborateurs (role >= 3, actif=1)
$collabSql = "SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom_complet, u.email
              FROM users u
              WHERE u.id_role >= 3 AND u.actif = 1";
$collabParams = [];
if ($roleId === 2 && $managerAgenceId > 0) {
    $collabSql .= " AND u.id_agence = ?";
    $collabParams[] = $managerAgenceId;
}
$collabSql .= " ORDER BY u.nom, u.prenom";

$collaborateurs = [];
try {
    $stmtC = $pdo->prepare($collabSql);
    $stmtC->execute($collabParams);
    $collaborateurs = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Impossible de charger la liste des collaborateurs.';
}

// Charger modèles actifs
$modeles = [];
try {
    $stmtM = $pdo->query("SELECT id, nom FROM rh_entretien_modeles WHERE actif = 1 ORDER BY nom");
    $modeles = $stmtM->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$typesEntretien = [
    'annuel'         => 'Entretien Annuel',
    'pro'            => 'Entretien Professionnel',
    'mi_annee'       => 'Mi-Année',
    'periode_essai'  => "Période d'Essai",
    'retour_absence' => 'Retour Absence',
];

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $collaborateurId = (int)($_POST['collaborateur_id'] ?? 0);
    $typeEntretien   = trim($_POST['type_entretien'] ?? '');
    $datePlanifiee   = trim($_POST['date_planifiee'] ?? '');
    $modeleId        = !empty($_POST['modele_id']) ? (int)$_POST['modele_id'] : null;

    if ($collaborateurId <= 0) {
        $errors[] = 'Veuillez sélectionner un collaborateur.';
    }
    if (!array_key_exists($typeEntretien, $typesEntretien)) {
        $errors[] = "Type d'entretien invalide.";
    }
    if ($datePlanifiee === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePlanifiee)) {
        $errors[] = 'Veuillez saisir une date planifiée valide.';
    }

    $collabData = null;
    if ($collaborateurId > 0 && empty($errors)) {
        try {
            $stmtChk = $pdo->prepare("SELECT id, prenom, nom, email, id_agence FROM users WHERE id = ? AND actif = 1");
            $stmtChk->execute([$collaborateurId]);
            $collabData = $stmtChk->fetch(PDO::FETCH_ASSOC);
            if (!$collabData) {
                $errors[] = 'Collaborateur introuvable.';
            }
            if ($roleId === 2 && $collabData && $managerAgenceId > 0 && (int)$collabData['id_agence'] !== $managerAgenceId) {
                $errors[] = 'Ce collaborateur ne fait pas partie de votre agence.';
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la vérification du collaborateur.';
        }
    }

    if (empty($errors)) {
        $tokenCollaborateur = bin2hex(random_bytes(32));
        $agenceId = (int)($collabData['id_agence'] ?? $managerAgenceId ?: null);

        try {
            $stmtIns = $pdo->prepare(
                "INSERT INTO rh_entretiens
                 (collaborateur_id, manager_id, agence_id, type_entretien, date_planifiee, modele_id, statut, token_collaborateur, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'planifie', ?, NOW())"
            );
            $stmtIns->execute([
                $collaborateurId,
                $userId,
                $agenceId ?: null,
                $typeEntretien,
                $datePlanifiee,
                $modeleId,
                $tokenCollaborateur,
            ]);

            // Mail de convocation
            if (!empty($collabData['email'])) {
                $collabNom     = trim($collabData['prenom'] . ' ' . $collabData['nom']);
                $typeLabel     = $typesEntretien[$typeEntretien] ?? $typeEntretien;
                $dateFormatted = date('d/m/Y', strtotime($datePlanifiee));
                $loginUrl      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                                 . '://' . ($_SERVER['HTTP_HOST'] ?? 'maboximmo.fr') . '/login.php';

                $mailSubject = 'Un moment rien que pour vous — votre entretien individuel est programmé !';
                $mailBody    = '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:\'Manrope\',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:36px 0">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#1a2535;border-radius:16px;overflow:hidden;border:1px solid #2d3d52;max-width:600px">
        <tr>
          <td style="background:linear-gradient(135deg,#0d1b2a 0%,#1a3048 60%,#0f2840 100%);padding:32px 36px;border-bottom:1px solid #2d3d52">
            <h1 style="margin:0 0 4px;font-size:22px;color:#4878a6;font-weight:800">Ma Box Immo</h1>
            <p style="margin:0;font-size:12px;color:#8899aa;font-weight:500;letter-spacing:0.5px;text-transform:uppercase">Ressources Humaines</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px">
            <p style="font-size:17px;color:#dde6f0;margin:0 0 8px;font-weight:700">Bonjour <span style="color:#4878a6">'.h($collabNom).'</span> !</p>
            <p style="font-size:14px;color:#aabbc9;margin:0 0 28px;line-height:1.8">
              Votre prochain entretien individuel vient d\'être programmé. C\'est un moment précieux, entièrement dédié à vous.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,#0d1b2a,#132540);border-radius:12px;border:1px solid #2d3d52;margin-bottom:28px;overflow:hidden">
              <tr><td style="padding:18px 22px;border-bottom:1px solid #2d3d52">
                <span style="font-size:10px;text-transform:uppercase;color:#4878a6;font-weight:800;letter-spacing:1px">Type d\'entretien</span><br>
                <span style="font-size:16px;color:#dde6f0;font-weight:700;margin-top:4px;display:block">'.h($typeLabel).'</span>
              </td></tr>
              <tr><td style="padding:18px 22px">
                <span style="font-size:10px;text-transform:uppercase;color:#4878a6;font-weight:800;letter-spacing:1px">Date programmée</span><br>
                <span style="font-size:20px;color:#4878a6;font-weight:800;margin-top:4px;display:block">'.h($dateFormatted).'</span>
              </td></tr>
            </table>
            <table cellpadding="0" cellspacing="0" style="margin-bottom:28px">
              <tr><td style="background:rgba(102,217,255,0.18);border:1px solid rgba(72,120,166,0.3);border-radius:10px">
                <a href="'.h($loginUrl).'" style="display:inline-block;padding:13px 32px;color:#4878a6;text-decoration:none;font-weight:800;font-size:14px">Accéder à mon espace RH &rarr;</a>
              </td></tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 36px;border-top:1px solid #2d3d52;background:#0d1b2a">
            <p style="margin:0;font-size:12px;color:#778899;line-height:1.7">
              Ce message a été envoyé automatiquement par <strong style="color:#8899aa">Ma Box Immo RH</strong>.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
                send_mail($collabData['email'], $mailSubject, $mailBody, [], true);
            }

            header('Location: rh_entretien_liste.php?success=' . urlencode('Entretien créé et convocation envoyée par e-mail.'));
            exit;

        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la création de l\'entretien : ' . $e->getMessage();
        }
    }
}

/* ── Layout variables ── */
$layout_title   = 'Planifier un entretien';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ── Section title ── */
    .sec-head { display: flex; align-items: center; gap: 14px; margin: 0 0 18px; }

    /* ── Form card ── */
    .form-wrap {
        max-width: 640px;
        width: 100%;
    }
    .form-card {
        background: var(--bg-primary); border-radius: 20px;
        box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
        overflow: hidden;
    }
    .form-card-body { padding: 28px 28px 0; }
    .form-card-foot {
        padding: 20px 28px;
        display: flex; gap: 12px; justify-content: flex-end; align-items: center;
        border-top: 1px solid rgba(196,192,186,0.4);
        margin-top: 24px;
    }

    /* ── Form groups ── */
    .form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 22px; }
    .form-group label {
        font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
        letter-spacing: 0.2em; text-transform: uppercase; color: #a8a49e;
    }
    .form-group label .req { color: #8a5040; margin-left: 2px; }
    .form-group select,
    .form-group input[type="date"] {
        height: 38px; padding: 0 12px;
        background: var(--bg-primary);
        box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
        border: none; border-radius: 10px;
        font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
        cursor: pointer; outline: none;
        width: 100%;
    }
    .form-group select:focus,
    .form-group input[type="date"]:focus {
        box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light),
                    0 0 0 2px rgba(74,96,56,0.25);
    }
    .form-hint {
        font-family: 'DM Mono', monospace; font-size: 9px; color: #b8b4ae;
        letter-spacing: 0.04em; line-height: 1.5;
    }

    /* ── Info banner email ── */
    .info-banner {
        display: flex; align-items: flex-start; gap: 12px;
        background: rgba(54,87,125,0.08); border-radius: 12px;
        padding: 14px 16px; margin-bottom: 22px;
        box-shadow: inset 2px 2px 6px rgba(54,87,125,0.1);
    }
    .info-banner-ico {
        width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
        background: var(--bg-primary);
        box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        display: flex; align-items: center; justify-content: center;
    }
    .info-banner-ico svg { width: 15px; height: 15px; stroke: #36577d; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .info-banner-txt { font-size: 12px; line-height: 1.6; color: #5a5650; }
    .info-banner-txt strong { color: #36577d; font-weight: 600; }

    /* ── Errors ── */
    .msg-error {
        padding: 14px 18px; border-radius: 12px; font-size: 12px;
        margin-bottom: 20px; font-weight: 500;
        background: rgba(138,80,64,0.12); color: #8a5040;
        box-shadow: inset 2px 2px 6px rgba(138,80,64,0.15);
    }
    .msg-error ul { margin: 6px 0 0 16px; }
    .msg-error li { margin-bottom: 3px; }

    /* ── Buttons ── */
    .btn-cancel {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 0 18px; height: 38px; border-radius: 999px; border: none; cursor: pointer;
        font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 500; color: #6a6660;
        text-decoration: none;
        background: var(--bg-primary);
        box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
    }
    .btn-cancel:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .btn-submit {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 0 22px; height: 38px; border-radius: 999px; border: none; cursor: pointer;
        font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 700; color: #ffffff;
        background: linear-gradient(135deg, #4a6038, #7a9060);
        box-shadow: 4px 4px 10px rgba(74,96,56,0.4), -2px -2px 6px rgba(255,255,255,0.6);
    }
    .btn-submit:hover { box-shadow: 5px 5px 13px rgba(74,96,56,0.5), -2px -2px 6px rgba(255,255,255,0.7); }
    .btn-submit svg { width: 14px; height: 14px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* ── Divider ── */
    .form-divider {
        height: 1px; margin: 8px 0 22px;
        background: linear-gradient(90deg, transparent, #c8c4be 20%, #c8c4be 80%, transparent);
    }

    @media (max-width: 900px) {
        .form-wrap { max-width: 100%; }
    }
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
EXTRAJS;

/* ── Page content ── */
ob_start();
?>

<?php if (!empty($errors)): ?>
    <div class="msg-error" style="max-width:640px">
        <strong>Veuillez corriger les erreurs suivantes :</strong>
        <ul>
            <?php foreach($errors as $err): ?>
                <li><?=h($err)?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="form-wrap">

    <!-- Info banner -->
    <div class="info-banner">
        <div class="info-banner-ico">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </div>
        <div class="info-banner-txt">
            <strong>Convocation automatique</strong> — Un e-mail de convocation sera envoyé au collaborateur dès la création de l'entretien, avec la date et le type d'entretien planifiés.
        </div>
    </div>

    <!-- Section title -->
    <div class="sec-head">
        <div class="line-l"></div>
        <span class="sec-txt">Informations de l'entretien</span>
        <div class="line-r"></div>
    </div>

    <div class="form-card">
        <form method="POST" action="rh_entretien_ajouter.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-card-body">

                <!-- Collaborateur -->
                <div class="form-group">
                    <label for="collaborateur_id">Collaborateur <span class="req">*</span></label>
                    <?php if (empty($collaborateurs)): ?>
                        <p style="font-size:12px;color:#8a5040;padding:8px 0">Aucun collaborateur disponible.</p>
                    <?php else: ?>
                        <select name="collaborateur_id" id="collaborateur_id" required>
                            <option value="">— Sélectionner un collaborateur —</option>
                            <?php foreach($collaborateurs as $c): ?>
                                <option value="<?=(int)$c['id']?>" <?=(isset($old['collaborateur_id']) && (int)$old['collaborateur_id']===(int)$c['id']?'selected':'')?>>
                                    <?=h($c['nom_complet'])?><?=!empty($c['email'])?' — '.h($c['email']):''?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <span class="form-hint">Seuls les collaborateurs actifs<?=$roleId===2?' de votre agence':''?> sont listés.</span>
                </div>

                <!-- Type d'entretien -->
                <div class="form-group">
                    <label for="type_entretien">Type d'entretien <span class="req">*</span></label>
                    <select name="type_entretien" id="type_entretien" required>
                        <option value="">— Sélectionner un type —</option>
                        <?php foreach($typesEntretien as $val => $lbl): ?>
                            <option value="<?=h($val)?>" <?=(($old['type_entretien'] ?? '')===$val?'selected':'')?>><?=h($lbl)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date planifiée -->
                <div class="form-group">
                    <label for="date_planifiee">Date planifiée <span class="req">*</span></label>
                    <input type="date" name="date_planifiee" id="date_planifiee"
                           value="<?=h($old['date_planifiee'] ?? '')?>"
                           min="<?=date('Y-m-d')?>" required>
                </div>

                <div class="form-divider"></div>

                <!-- Modèle de grille -->
                <div class="form-group">
                    <label for="modele_id">Modèle de grille</label>
                    <select name="modele_id" id="modele_id">
                        <option value="">Standard (aucun modèle spécifique)</option>
                        <?php foreach($modeles as $m): ?>
                            <option value="<?=(int)$m['id']?>" <?=(isset($old['modele_id']) && (int)$old['modele_id']===(int)$m['id']?'selected':'')?>>
                                <?=h($m['nom'])?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint">Un modèle standard sera utilisé si aucun n'est sélectionné.</span>
                </div>

            </div><!-- .form-card-body -->

            <div class="form-card-foot">
                <a href="rh_entretien_liste.php" class="btn-cancel">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    Annuler
                </a>
                <button type="submit" class="btn-submit">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Créer et envoyer la convocation
                </button>
            </div>
        </form>
    </div><!-- .form-card -->

</div><!-- .form-wrap -->

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
