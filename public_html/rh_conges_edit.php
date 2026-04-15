<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$roleId = current_role_id();
$userId = current_user_id();
$leaveId = (int)($_GET['id'] ?? 0);

if (!$leaveId) {
    http_response_code(400);
    exit('ID congé manquant');
}

// Get leave details
$stmt = $pdo->prepare("SELECT c.*, u.prenom, u.nom FROM conges c JOIN users u ON c.id_user = u.id WHERE c.id = ?");
$stmt->execute([$leaveId]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    http_response_code(404);
    exit('Congé non trouvé');
}

// Check authorization (admin only or own leave)
if ($roleId !== 1 && $leave['id_user'] != $userId) {
    http_response_code(403);
    exit('Accès refusé');
}

// Get users list for admin
$users = [];
if ($roleId === 1) {
    $users = $pdo->query("SELECT id, prenom, nom FROM users WHERE actif = 1 ORDER BY prenom, nom")->fetchAll(PDO::FETCH_ASSOC);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Layout variables ─────────────────────────────────────────────────────
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Modifier congé — ' . h($_userName);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '<a class="ph-btn" href="rh_conges.php" style="text-decoration:none">&larr; Retour</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
    .container{max-width:600px;margin:0 auto}
    .form-group{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
    .form-group label{font-size:12px;font-weight:600;color:var(--muted)}
    .form-group input, .form-group select, .form-group textarea{padding:10px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-family:inherit;font-size:12px}
    .form-group textarea{min-height:100px;resize:vertical}
    .btn-group{display:flex;gap:12px;margin-top:30px}
    .btn{flex:1;padding:12px;border:1px solid;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;transition:all 0.2s}
    .btn-primary{background:rgba(16,185,129,0.2);border-color:rgba(16,185,129,0.4);color:#4a6038}
    .btn-primary:hover{background:rgba(16,185,129,0.3)}
    .btn-secondary{background:rgba(72,120,166,0.12);border-color:rgba(72,120,166,0.25);color:#4878a6}
    .btn-secondary:hover{background:rgba(72,120,166,0.2)}
    .btn-danger{background:rgba(239,68,68,0.2);border-color:rgba(239,68,68,0.4);color:#ff6b6b}
    .btn-danger:hover{background:rgba(239,68,68,0.3)}
</style>
EXTRACSS;

$layout_extra_js = <<<EXTRAJS
<script>
    const _CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';

    document.getElementById('editForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('csrf_token', _CSRF);
        const response = await fetch('api/update_conge.php', {
            method: 'POST',
            body: new URLSearchParams(formData)
        });

        const result = await response.text();
        if (response.ok) {
            alert('Congé modifié avec succès!');
            window.location.href = 'rh_conges.php';
        } else {
            alert('Erreur: ' + result);
        }
    });

    function downloadPDF(id) {
        window.location.href = 'exporter_conges_pdf.php?id=' + id;
    }

    function deleteLeave(id) {
        if (!confirm('Êtes-vous sûr de vouloir supprimer ce congé?')) return;

        fetch('api/delete_conge.php', {
            method: 'POST',
            body: new URLSearchParams({id: id, csrf_token: _CSRF})
        }).then(r => r.text()).then(result => {
            if (result.includes('success')) {
                alert('Congé supprimé!');
                window.location.href = 'rh_conges.php';
            } else {
                alert('Erreur: ' + result);
            }
        });
    }
</script>
EXTRAJS;

ob_start();
?>
        <div class="container">
            <form id="editForm" method="POST" action="api/update_conge.php">
                <input type="hidden" name="id" value="<?=$leaveId?>">

                <?php if ($roleId === 1): ?>
                <div class="form-group">
                    <label>Employé</label>
                    <select name="id_user" required>
                        <option value="">Choisir un employé</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?=$u['id']?>" <?=$u['id'] == $leave['id_user'] ? 'selected' : ''?>><?=h($u['prenom'] . ' ' . $u['nom'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label>Employé</label>
                    <input type="text" value="<?=h($leave['prenom'] . ' ' . $leave['nom'])?>" disabled>
                </div>
                <input type="hidden" name="id_user" value="<?=$leave['id_user']?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Date début</label>
                    <input type="date" name="date_debut" value="<?=$leave['date_debut']?>" required>
                </div>

                <div class="form-group">
                    <label>Date fin</label>
                    <input type="date" name="date_fin" value="<?=$leave['date_fin']?>" required>
                </div>

                <div class="form-group">
                    <label>Type de congé</label>
                    <select name="motif" required>
                        <option value="">Choisir un type</option>
                        <option value="conges_payes" <?=$leave['motif'] == 'conges_payes' ? 'selected' : ''?>>Congés payés</option>
                        <option value="rtt" <?=$leave['motif'] == 'rtt' ? 'selected' : ''?>>RTT</option>
                        <option value="maladie_justifiee_deduite" <?=$leave['motif'] == 'maladie_justifiee_deduite' ? 'selected' : ''?>>Maladie (justifiée)</option>
                        <option value="absence_justifiee_deduite_heures" <?=$leave['motif'] == 'absence_justifiee_deduite_heures' ? 'selected' : ''?>>Absence (justifiée)</option>
                        <option value="autre_legal_non_deduit" <?=$leave['motif'] == 'autre_legal_non_deduit' ? 'selected' : ''?>>Autre (légal)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Demi-journée début</label>
                    <select name="demi_journee_debut">
                        <option value="non" <?=$leave['demi_journee_debut'] == 'non' ? 'selected' : ''?>>Journée complète</option>
                        <option value="matin" <?=$leave['demi_journee_debut'] == 'matin' ? 'selected' : ''?>>Matin</option>
                        <option value="apres-midi" <?=$leave['demi_journee_debut'] == 'apres-midi' ? 'selected' : ''?>>Après-midi</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Demi-journée fin</label>
                    <select name="demi_journee_fin">
                        <option value="non" <?=$leave['demi_journee_fin'] == 'non' ? 'selected' : ''?>>Journée complète</option>
                        <option value="matin" <?=$leave['demi_journee_fin'] == 'matin' ? 'selected' : ''?>>Matin</option>
                        <option value="apres-midi" <?=$leave['demi_journee_fin'] == 'apres-midi' ? 'selected' : ''?>>Après-midi</option>
                    </select>
                </div>

                <?php if ($roleId === 1): ?>
                <div class="form-group">
                    <label>Statut</label>
                    <select name="statut">
                        <option value="en_attente" <?=$leave['statut'] == 'en_attente' ? 'selected' : ''?>>En attente</option>
                        <option value="validé" <?=$leave['statut'] == 'validé' ? 'selected' : ''?>>Validé</option>
                        <option value="refusé" <?=$leave['statut'] == 'refusé' ? 'selected' : ''?>>Refusé</option>
                        <option value="archivé" <?=$leave['statut'] == 'archivé' ? 'selected' : ''?>>Archivé</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Commentaire</label>
                    <textarea name="commentaire"><?=h($leave['commentaire'] ?? '')?></textarea>
                </div>

                <?php if ($roleId === 1): ?>
                <div class="form-group">
                    <label>Commentaire Admin (non exporté en PDF)</label>
                    <textarea name="commentaire_admin"><?=h($leave['commentaire_admin'] ?? '')?></textarea>
                </div>
                <?php endif; ?>

                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='rh_conges.php'">&larr; Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                    <button type="button" class="btn btn-secondary" onclick="downloadPDF(<?=$leaveId?>)" style="background:rgba(239,68,68,0.2);border-color:rgba(239,68,68,0.4);color:#ff6b6b;">PDF</button>
                    <?php if ($roleId === 1): ?>
                        <button type="button" class="btn btn-danger" onclick="deleteLeave(<?=$leaveId?>)">Supprimer</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
