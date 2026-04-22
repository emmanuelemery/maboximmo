<?php
declare(strict_types=1);
/**
 * investisseur/contacts.php — Contacts externes (gérants propriétaires)
 *
 * Une seule page qui fait tout :
 *   - Liste des contacts existants
 *   - Formulaire ajout / édition (quand ?id=X ou ?new=1)
 *   - Attribution des SCI (checkboxes propriétaires)
 *   - Bouton "📤 Générer un lien d'accès" vers le module partage
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_contacts.php';
require_once __DIR__ . '/../inc/investisseur_partage.php';

$pdo = $GLOBALS['pdo'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew = !empty($_GET['new']) && $id === 0;
$errors = []; $flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_any();
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'save') {
            $idSaved = inv_contact_save($pdo, $_POST, $id > 0 ? $id : null);
            $props = array_map('intval', $_POST['proprietaires'] ?? []);
            inv_contact_attach_proprietaires($pdo, $idSaved, $props);
            header('Location: ?id=' . $idSaved . '&saved=1');
            exit;
        }
        if ($action === 'delete' && $id > 0) {
            inv_contact_delete($pdo, $id);
            header('Location: ?deleted=1');
            exit;
        }
        if ($action === 'generate_link' && $id > 0) {
            $contact = inv_contact_load($pdo, $id);
            if (!$contact) throw new RuntimeException('Contact introuvable');
            $idP = inv_partage_create($pdo, [
                'type' => 'portefeuille',
                'id_ref' => null,
                'destinataire_email' => $contact['email'],
                'destinataire_nom' => trim($contact['prenom'] . ' ' . $contact['nom']),
                'message' => trim((string)($_POST['message'] ?? '')),
                'jours' => (int)($_POST['jours'] ?? 90),
            ]);
            // Lier au contact
            $pdo->prepare("UPDATE investisseur_partages SET id_contact_externe = :c WHERE id = :p")
                ->execute([':c' => $id, ':p' => $idP]);
            if (!empty($_POST['envoyer_mail'])) {
                $r = inv_partage_send_email($pdo, $idP);
                $flash = $r['ok']
                    ? '✓ Lien généré et envoyé à ' . $contact['email']
                    : '⚠ Lien généré mais email NON envoyé : ' . $r['error'];
            } else {
                $flash = '✓ Lien généré. Copiez-le ci-dessous.';
            }
            header('Location: ?id=' . $id . '&flash=' . urlencode($flash));
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$contact = null;
$propsAttaches = [];
if ($id > 0) {
    $contact = inv_contact_load($pdo, $id);
    if (!$contact) { http_response_code(404); die('Contact introuvable'); }
    $propsAttaches = array_column(inv_contact_proprietaires($pdo, $id), 'id');
    $propsAttaches = array_map('intval', $propsAttaches);
}
$allContacts = inv_contact_list($pdo);
$allProps    = inv_contact_proprietaires_dispos($pdo);
$flashFromUrl = trim((string)($_GET['flash'] ?? ''));

// Liens existants pour ce contact
$partages = [];
if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM investisseur_partages WHERE id_contact_externe = :c ORDER BY created_at DESC LIMIT 50");
    $st->bindValue(':c', $id, PDO::PARAM_INT);
    $st->execute();
    $partages = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle     = $id ? 'Contact : ' . (($contact['prenom'] ?? '') . ' ' . ($contact['nom'] ?? '')) : ($isNew ? 'Nouveau contact' : 'Contacts externes');
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Contacts';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$val = fn($k, $def = '') => $h($contact[$k] ?? $def);
?>
<div class="inv-wrap">

    <div class="inv-header">
        <h1>👤 <?= $h($pageTitle) ?></h1>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <?php if ($id || $isNew): ?>
                <a href="<?= $h($u('/investisseur/contacts.php')) ?>">← Tous les contacts</a>
            <?php else: ?>
                <a href="<?= $h($u('/investisseur/contacts.php?new=1')) ?>" class="primary">+ Nouveau contact</a>
            <?php endif; ?>
            <a href="<?= $h($u('/investisseur/')) ?>">⌂ Dashboard</a>
        </div>
    </div>

    <?php if ($flashFromUrl): ?>
        <div class="inv-paper" style="border-left:4px solid #4f7a3a; padding:12px 18px;"><?= $h($flashFromUrl) ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['saved'])): ?>
        <div class="inv-paper" style="border-left:4px solid #4f7a3a; padding:12px 18px;">✓ Contact enregistré.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="inv-paper" style="border-left:4px solid #b4443a; padding:12px 18px;">Contact supprimé.</div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="inv-paper" style="border-left:4px solid #b4443a">
            <ul style="margin:0"><?php foreach ($errors as $e) echo '<li style="color:#b4443a">' . $h($e) . '</li>'; ?></ul>
        </div>
    <?php endif; ?>

    <?php if (!$id && !$isNew): ?>
        <!-- ═══════════ MODE LISTE ═══════════ -->
        <?php if (empty($allContacts)): ?>
            <div class="inv-empty">
                <h3>Aucun contact externe</h3>
                <p>Créez un contact gérant (ex : T. Saby pour le Groupe SIR) pour lui donner accès à ses SCI.</p>
                <div style="margin-top:20px">
                    <a href="<?= $h($u('/investisseur/contacts.php?new=1')) ?>" class="inv-btn primary">+ Nouveau contact</a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($allContacts as $c): ?>
            <a href="?id=<?= (int)$c['id'] ?>" class="inv-card-list" style="--bar-color:<?= $c['actif'] ? '#4f7a3a' : '#9a9690' ?>; text-decoration:none">
                <div class="ic-title-block">
                    <div class="ic-title"><?= $h(($c['prenom'] ? $c['prenom'] . ' ' : '') . $c['nom']) ?></div>
                    <div class="ic-sub"><?= $h($c['email']) ?> · <?= $h($c['role']) ?><?php if ($c['telephone']): ?> · <?= $h($c['telephone']) ?><?php endif; ?></div>
                </div>
                <div class="ic-metric"><span class="mv"><?= (int)$c['nb_proprietaires'] ?></span><span class="ml">SCI</span></div>
                <div class="ic-metric"><span class="mv"><?= (int)$c['nb_partages'] ?></span><span class="ml">Liens</span></div>
                <span class="inv-btn sm">Ouvrir</span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>
        <!-- ═══════════ MODE FICHE CONTACT ═══════════ -->
        <form method="post">
            <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">

            <div class="inv-paper">
                <h2>Coordonnées</h2>
                <div class="inv-form-grid">
                    <div class="inv-field"><label>Civilité</label>
                        <select name="civilite">
                            <option value="">—</option>
                            <?php foreach (['M.', 'Mme', 'Me'] as $c): ?>
                                <option <?= ($contact['civilite'] ?? '') === $c ? 'selected' : '' ?>><?= $h($c) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="inv-field"><label>Prénom</label>
                        <input type="text" name="prenom" value="<?= $val('prenom') ?>"></div>
                    <div class="inv-field"><label>Nom *</label>
                        <input type="text" name="nom" required value="<?= $val('nom') ?>"></div>

                    <div class="inv-field"><label>Email *</label>
                        <input type="email" name="email" required value="<?= $val('email') ?>" placeholder="t.saby@groupe-sir.fr"></div>
                    <div class="inv-field"><label>Téléphone</label>
                        <input type="text" name="telephone" value="<?= $val('telephone') ?>"></div>
                    <div class="inv-field"><label>Rôle</label>
                        <select name="role">
                            <?php foreach (['gerant' => 'Gérant', 'proprietaire' => 'Propriétaire', 'mandataire' => 'Mandataire', 'associe' => 'Associé'] as $k => $l): ?>
                                <option value="<?= $h($k) ?>" <?= ($contact['role'] ?? 'gerant') === $k ? 'selected' : '' ?>><?= $h($l) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="inv-field span-full"><label>Notes internes</label>
                        <textarea name="notes" rows="2"><?= $val('notes') ?></textarea></div>
                </div>
            </div>

            <div class="inv-paper">
                <h2>SCI / Propriétaires rattachés</h2>
                <p style="margin:0 0 12px; color:#5a5a55; font-size:13px;">Cochez les SCI dont ce contact est gérant. Il pourra voir tous les biens de ces SCI via un lien magique.</p>
                <div class="inv-form-grid" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
                    <?php foreach ($allProps as $p):
                        $isAttached = in_array((int)$p['id'], $propsAttaches, true);
                        $label = $p['societe'] ?: trim($p['prenom'] . ' ' . $p['nom']) ?: 'Prop. #' . $p['id'];
                    ?>
                    <label style="display:flex; gap:10px; align-items:center; padding:10px 14px; background:var(--inv-bg); border-radius:10px; cursor:pointer; border-left:4px solid <?= $isAttached ? '#4f7a3a' : '#c8c4be' ?>;">
                        <input type="checkbox" name="proprietaires[]" value="<?= (int)$p['id'] ?>" <?= $isAttached ? 'checked' : '' ?>>
                        <span style="flex:1">
                            <strong style="display:block; color:#24324a; font-size:13px;"><?= $h($label) ?></strong>
                            <span style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:0.08em;">
                                <?= (int)$p['nb_biens'] ?> bien<?= (int)$p['nb_biens'] > 1 ? 's' : '' ?>
                            </span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="inv-paper">
                <div style="display:flex; gap:12px; justify-content:space-between; align-items:center;">
                    <?php if ($id): ?>
                    <div>
                        <button type="button" class="inv-btn danger" onclick="if (confirm('Supprimer ce contact ? (les liens magiques restent valides tant qu\'ils ne sont pas révoqués individuellement)')) { document.getElementById('frm-del').submit(); }">🗑 Supprimer</button>
                    </div>
                    <?php else: ?><div></div><?php endif; ?>
                    <div>
                        <a href="<?= $h($u('/investisseur/contacts.php')) ?>" class="inv-btn ghost">Annuler</a>
                        <button type="submit" class="inv-btn primary"><?= $id ? 'Enregistrer' : 'Créer le contact' ?></button>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($id): ?>
        <form id="frm-del" method="post" style="display:none;">
            <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
        </form>

        <!-- ═══════════ GÉNÉRATION DE LIEN D'ACCÈS ═══════════ -->
        <div class="inv-paper" style="border-left:4px solid #4f7a3a">
            <h2>📤 Générer un lien d'accès au portefeuille</h2>
            <p style="margin:0 0 14px; color:#5a5a55; font-size:13px;">
                Le lien magique donnera accès à tous les biens des <strong><?= count($propsAttaches) ?> SCI rattachées</strong>
                — photos, baux, CRG et documents (hors documents marqués "interne").
                Le destinataire pourra également <strong>ajouter des documents</strong> qui vous seront notifiés.
            </p>
            <form method="post">
                <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="action" value="generate_link">
                <div class="inv-form-grid">
                    <div class="inv-field">
                        <label>Durée de validité</label>
                        <select name="jours">
                            <option value="30">30 jours</option>
                            <option value="90" selected>90 jours</option>
                            <option value="180">6 mois</option>
                            <option value="365">1 an</option>
                        </select>
                    </div>
                    <div class="inv-field span-2">
                        <label>Message personnel (dans l'email)</label>
                        <input type="text" name="message" placeholder="Bonjour, voici l'accès à vos biens avec les derniers CRG, les analyses et la valorisation actuelle.">
                    </div>
                </div>
                <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="submit" name="envoyer_mail" value="0" class="inv-btn">Générer sans envoyer</button>
                    <button type="submit" name="envoyer_mail" value="1" class="inv-btn primary">📧 Générer et envoyer par email</button>
                </div>
            </form>
        </div>

        <!-- Historique des liens déjà créés -->
        <?php if (!empty($partages)): ?>
        <div class="inv-paper">
            <h2>📚 Liens d'accès existants (<?= count($partages) ?>)</h2>
            <?php foreach ($partages as $p):
                $valid = inv_partage_is_valid($p);
                $url = inv_partage_build_url((string)$p['token']);
            ?>
            <div class="inv-card-list" style="--bar-color:<?= $valid ? '#4f7a3a' : '#b4443a' ?>; margin-bottom:8px;">
                <div class="ic-title-block">
                    <div class="ic-title">Lien créé le <?= date('d/m/Y', strtotime((string)$p['created_at'])) ?></div>
                    <div class="ic-sub">
                        expire le <?= date('d/m/Y', strtotime((string)$p['expire_at'])) ?>
                        · <?= $valid ? '<span style="color:#4f7a3a">actif</span>' : '<span style="color:#b4443a">expiré/révoqué</span>' ?>
                        · <?= (int)$p['consulte_count'] ?> consultation<?= (int)$p['consulte_count'] > 1 ? 's' : '' ?>
                    </div>
                </div>
                <div class="ic-actions">
                    <button type="button" class="inv-btn sm" onclick="navigator.clipboard.writeText('<?= $h($url) ?>').then(()=>this.textContent='✓ Copié')">Copier</button>
                    <a href="<?= $h($url) ?>" target="_blank" class="inv-btn sm">Tester</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
