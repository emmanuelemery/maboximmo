<?php
declare(strict_types=1);
/**
 * investisseur/partager.php — Création d'un lien magique + envoi email
 *
 * Paramètres GET :
 *   ?type=presentation|analyse|scenario  (obligatoire)
 *   ?id_ref=X                             (obligatoire)
 *   ?email=a@b.c                          (pré-remplissage)
 *
 * POST : génère le token, sauvegarde, envoie l'email, affiche le statut.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_partage.php';

$pdo = $GLOBALS['pdo'];

$type   = (string)($_GET['type'] ?? $_POST['type'] ?? 'presentation');
$idRef  = (int)($_GET['id_ref'] ?? $_POST['id_ref'] ?? 0);
if (!in_array($type, ['presentation','analyse','scenario'], true)) $type = 'presentation';

// Charge le libellé de la ressource pour affichage
$resourceLabel = '';
if (in_array($type, ['presentation','analyse'], true) && $idRef > 0) {
    $st = $pdo->prepare("SELECT titre_analyse, ville FROM investisseur_analyses WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $idRef, PDO::PARAM_INT);
    $st->execute();
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) $resourceLabel = $r['titre_analyse'] . ' — ' . $r['ville'];
} elseif ($type === 'scenario' && $idRef > 0) {
    $st = $pdo->prepare("SELECT nom_scenario FROM investisseur_valo_scenarios WHERE id = :id LIMIT 1");
    $st->bindValue(':id', $idRef, PDO::PARAM_INT);
    $st->execute();
    $resourceLabel = (string)$st->fetchColumn();
}

$result = null;
$errors = [];
$createdPartage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_any();
        $data = [
            'type'               => $type,
            'id_ref'             => $idRef,
            'destinataire_email' => trim((string)($_POST['email'] ?? '')),
            'destinataire_nom'   => trim((string)($_POST['nom'] ?? '')),
            'message'            => trim((string)($_POST['message'] ?? '')),
            'jours'              => (int)($_POST['jours'] ?? 30),
        ];
        $idP = inv_partage_create($pdo, $data);
        $send = !empty($_POST['envoyer_mail']);
        if ($send) {
            $mailRes = inv_partage_send_email($pdo, $idP);
            if ($mailRes['ok']) {
                $result = ['ok' => true, 'msg' => 'Lien créé et email envoyé avec succès.'];
            } else {
                $result = ['ok' => false, 'msg' => 'Lien créé mais email NON envoyé : ' . $mailRes['error'] . '. Vous pouvez copier le lien manuellement ci-dessous.'];
            }
        } else {
            $result = ['ok' => true, 'msg' => 'Lien créé (sans envoi d\'email). Copiez-le ci-dessous.'];
        }
        // Récupère le partage pour afficher le lien
        $st = $pdo->prepare("SELECT * FROM investisseur_partages WHERE id = :id");
        $st->bindValue(':id', $idP, PDO::PARAM_INT);
        $st->execute();
        $createdPartage = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

// Historique des partages existants pour cette ressource
$history = inv_partage_list($pdo, ['type' => $type, 'id_ref' => $idRef]);

$pageTitle     = 'Partager — ' . $resourceLabel;
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Partage';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
?>
<div class="inv-wrap">

    <div class="inv-header">
        <h1>📤 Partager</h1>
        <span style="font-family:'DM Mono',monospace; font-size:11px; color:#9a9690; letter-spacing:.08em; text-transform:uppercase;"><?= $h($resourceLabel) ?></span>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <?php if ($type === 'scenario'): ?>
                <a href="<?= $h($u('/investisseur/valorisation.php?loaded=' . $idRef)) ?>">← Retour au scénario</a>
            <?php else: ?>
                <a href="<?= $h($u('/investisseur/detail.php?id=' . $idRef)) ?>">← Retour à l'analyse</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="inv-paper" style="border-left:4px solid #b4443a">
            <strong style="color:#b4443a">Erreur :</strong>
            <ul style="margin:8px 0 0"><?php foreach ($errors as $e) echo '<li>' . $h($e) . '</li>'; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="inv-paper" style="border-left:4px solid <?= $result['ok'] ? '#4f7a3a' : '#d97a3a' ?>">
            <strong style="color:<?= $result['ok'] ? '#4f7a3a' : '#d97a3a' ?>"><?= $result['ok'] ? '✓' : '⚠' ?></strong>
            <?= $h($result['msg']) ?>
            <?php if ($createdPartage):
                $url = inv_partage_build_url((string)$createdPartage['token']);
            ?>
                <div style="margin-top:14px;">
                    <label style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">Lien magique</label>
                    <div style="display:flex; gap:8px; margin-top:6px;">
                        <input type="text" readonly value="<?= $h($url) ?>" onclick="this.select()"
                               style="flex:1; font-family:'DM Mono',monospace; font-size:11.5px; padding:10px 14px; background:#f9f7f2; border-radius:8px; border:1px solid #e6e1d7;">
                        <button type="button" class="inv-btn sm" onclick="navigator.clipboard.writeText('<?= $h($url) ?>').then(()=>this.textContent='✓ Copié !')">Copier</button>
                        <a href="<?= $h($url) ?>" target="_blank" class="inv-btn sm">Ouvrir</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire -->
    <div class="inv-paper">
        <h2>Créer un nouveau lien</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="type" value="<?= $h($type) ?>">
            <input type="hidden" name="id_ref" value="<?= (int)$idRef ?>">

            <div class="inv-form-grid">
                <div class="inv-field">
                    <label>Email destinataire *</label>
                    <input type="email" name="email" required
                           value="<?= $h($_POST['email'] ?? $_GET['email'] ?? '') ?>"
                           placeholder="t.saby@groupe-sir.fr">
                </div>
                <div class="inv-field">
                    <label>Nom destinataire (optionnel)</label>
                    <input type="text" name="nom" value="<?= $h($_POST['nom'] ?? '') ?>" placeholder="M. SABY">
                </div>
                <div class="inv-field">
                    <label>Durée de validité (jours)</label>
                    <select name="jours">
                        <option value="7">7 jours</option>
                        <option value="30" selected>30 jours</option>
                        <option value="60">60 jours</option>
                        <option value="90">90 jours</option>
                        <option value="180">6 mois</option>
                    </select>
                </div>
                <div class="inv-field span-full">
                    <label>Message personnel (optionnel)</label>
                    <textarea name="message" rows="4" placeholder="Bonjour, vous trouverez en lien l'analyse de votre bien..."><?= $h($_POST['message'] ?? '') ?></textarea>
                </div>
            </div>

            <div style="margin-top:18px; display:flex; gap:12px; justify-content:flex-end;">
                <button type="submit" name="envoyer_mail" value="0" class="inv-btn">Créer le lien sans envoyer</button>
                <button type="submit" name="envoyer_mail" value="1" class="inv-btn primary">📧 Créer et envoyer par email</button>
            </div>
        </form>
    </div>

    <!-- Historique des liens pour cette ressource -->
    <?php if (!empty($history)): ?>
    <div class="inv-paper">
        <h2>📚 Historique des partages (<?= count($history) ?>)</h2>
        <?php foreach ($history as $p):
            $valid = inv_partage_is_valid($p);
            $url = inv_partage_build_url((string)$p['token']);
            $col = $valid ? '#4f7a3a' : '#b4443a';
        ?>
        <div class="inv-card-list" style="--bar-color:<?= $col ?>; margin-bottom:8px;">
            <div class="ic-title-block">
                <div class="ic-title"><?= $h($p['destinataire_email']) ?><?php if (!empty($p['destinataire_nom'])): ?> — <?= $h($p['destinataire_nom']) ?><?php endif; ?></div>
                <div class="ic-sub">
                    créé le <?= date('d/m/Y', strtotime((string)$p['created_at'])) ?>
                    · expire le <?= date('d/m/Y', strtotime((string)$p['expire_at'])) ?>
                    · <?= $valid ? '<span style="color:#4f7a3a">actif</span>' : '<span style="color:#b4443a">expiré/révoqué</span>' ?>
                    · <?= (int)$p['consulte_count'] ?> consultation<?= (int)$p['consulte_count'] > 1 ? 's' : '' ?>
                </div>
            </div>
            <div class="ic-metric">
                <span class="mv"><?= (int)$p['consulte_count'] ?></span>
                <span class="ml">vues</span>
            </div>
            <div class="ic-actions">
                <button type="button" class="inv-btn sm" onclick="navigator.clipboard.writeText('<?= $h($url) ?>').then(()=>this.textContent='✓')">Copier</button>
                <a href="<?= $h($url) ?>" target="_blank" class="inv-btn sm">Ouvrir</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
