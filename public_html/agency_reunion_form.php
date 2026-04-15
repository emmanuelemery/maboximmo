<?php
// agency_reunion_form.php — Création / Édition réunion V2 MaBoxImmo
$current_page = 'reunions';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
if ($roleId > 2) { header('Location: agency_reunions.php'); exit; }
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$editId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit   = ($editId > 0);
$reunion  = null;
$odj_rows = [];
$parts    = [];
$errors   = [];
$success  = false;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM agency_reunion WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $reunion = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reunion) { header('Location: agency_reunions.php'); exit; }
    $odj_rows = $pdo->prepare("SELECT * FROM agency_reunion_odj WHERE id_reunion = :id ORDER BY ordre, id");
    $odj_rows->execute([':id' => $editId]);
    $odj_rows = $odj_rows->fetchAll(PDO::FETCH_ASSOC);
    $parts_stmt = $pdo->prepare("SELECT id_user FROM agency_reunion_participant WHERE id_reunion = :id");
    $parts_stmt->execute([':id' => $editId]);
    $parts = array_column($parts_stmt->fetchAll(PDO::FETCH_ASSOC), 'id_user');
}

// Data for selects
$immeubles  = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$all_users  = $pdo->query("SELECT id, nom, prenom, email FROM users WHERE actif = 1 ORDER BY nom, prenom")->fetchAll(PDO::FETCH_ASSOC);
$etabs      = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// ── POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre         = trim($_POST['titre'] ?? '');
    $type_reunion  = $_POST['type_reunion'] ?? 'AG';
    $date_reunion  = $_POST['date_reunion'] ?? '';
    $lieu          = trim($_POST['lieu'] ?? '');
    $id_immeuble   = (int)($_POST['id_immeuble'] ?? 0) ?: null;
    $id_etab       = (int)($_POST['id_etablissement'] ?? 0) ?: null;
    $commentaire   = trim($_POST['commentaire'] ?? '');
    $participants  = $_POST['participants'] ?? [];
    $points_odj    = json_decode($_POST['points_odj_json'] ?? '[]', true);

    // Normalise datetime-local
    if ($date_reunion) $date_reunion = str_replace('T', ' ', $date_reunion);

    if ($titre === '') $errors[] = 'Le titre est obligatoire.';
    if (!$date_reunion) $errors[] = 'La date est obligatoire.';
    if (empty($points_odj) || !array_filter($points_odj)) $errors[] = 'Au moins un point à l\'ordre du jour est requis.';

    if (empty($errors)) {
        if ($isEdit) {
            $pdo->prepare("UPDATE agency_reunion SET titre=:t, type_reunion=:tr, date_reunion=:d, lieu=:l, id_immeuble=:im, id_etablissement=:e, commentaire=:c WHERE id=:id")
                ->execute([':t'=>$titre,':tr'=>$type_reunion,':d'=>$date_reunion,':l'=>$lieu,':im'=>$id_immeuble,':e'=>$id_etab,':c'=>$commentaire,':id'=>$editId]);
            $rid = $editId;
            // Synchro ODJ : supprime + reinsère
            $pdo->prepare("DELETE FROM agency_reunion_odj WHERE id_reunion = ?")->execute([$rid]);
        } else {
            $pdo->prepare("INSERT INTO agency_reunion (titre, type_reunion, date_reunion, lieu, id_immeuble, id_etablissement, commentaire, statut, cree_par, created_at) VALUES (:t,:tr,:d,:l,:im,:e,:c,'planifiee',:u,NOW())")
                ->execute([':t'=>$titre,':tr'=>$type_reunion,':d'=>$date_reunion,':l'=>$lieu,':im'=>$id_immeuble,':e'=>$id_etab,':c'=>$commentaire,':u'=>$userId]);
            $rid = (int)$pdo->lastInsertId();
            // Synchro participants
            $pdo->prepare("DELETE FROM agency_reunion_participant WHERE id_reunion = ?")->execute([$rid]);
        }
        // ODJ
        $ordre = 1;
        foreach ((array)$points_odj as $pt) {
            if (trim((string)$pt) !== '') {
                $pdo->prepare("INSERT INTO agency_reunion_odj (id_reunion, ordre, intitule, traite) VALUES (?,?,?,0)")
                    ->execute([$rid, $ordre++, trim($pt)]);
            }
        }
        // Participants
        if ($isEdit) $pdo->prepare("DELETE FROM agency_reunion_participant WHERE id_reunion = ?")->execute([$rid]);
        foreach ($participants as $uid) {
            $pdo->prepare("INSERT IGNORE INTO agency_reunion_participant (id_reunion, id_user) VALUES (?,?)")
                ->execute([$rid, (int)$uid]);
        }
        // Admins auto
        foreach ($all_users as $u) {
            // (optionnel — pas d'auto-add admins ici, laissé à la main)
        }
        header("Location: agency_reunion_detail.php?id=$rid&created=1");
        exit;
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$dtVal = '';
if (!empty($reunion['date_reunion'])) {
    $ts = strtotime($reunion['date_reunion']);
    $dtVal = $ts ? date('Y-m-d\TH:i', $ts) : '';
}

// ── Layout ───────────────────────────────────────────────────────────
$layout_title   = $isEdit ? 'Modifier la réunion' : 'Nouvelle réunion';
$layout_module  = 'Ma Box Agency · Syndic';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.($isEdit ? 'EDIT' : 'NEW').'</div><div class="ph-kpi-lbl">Mode</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.count($odj_rows).'</div><div class="ph-kpi-lbl">Points ODJ</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.count($parts).'</div><div class="ph-kpi-lbl">Invités</div></div>
';

$layout_head_actions = '
    <a href="agency_reunions.php" class="ph-btn">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        Retour
    </a>
    <a class="ph-btn dispo">—</a>
    <a class="ph-btn dispo">—</a>
    <a class="ph-btn dispo">—</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.form-card{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:24px 28px;margin-bottom:20px}
.form-card-title{font-family:'Sora',sans-serif;font-size:14px;font-weight:700;color:#2c2a28;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.form-card-title svg{width:15px;height:15px;stroke:#4878a6;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.cols3{grid-template-columns:1fr 1fr 1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}
.form-input,.form-select,.form-textarea{background:var(--bg-secondary,#eef1f6);border:none;border-radius:10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:10px 14px;font-family:'Sora',sans-serif;font-size:13px;color:#2c2a28;width:100%;transition:box-shadow .2s}
.form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee,0 0 0 3px rgba(72,120,166,.3)}
.form-textarea{resize:vertical;min-height:70px}
.alert.error{background:#fce8e8;color:#c84040;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px}
/* ODJ builder */
.odj-list{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.odj-item{display:flex;align-items:center;gap:8px;background:var(--bg-secondary,#eef1f6);border-radius:10px;padding:8px 10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee}
.odj-num{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#4878a6;width:20px;text-align:center;flex-shrink:0}
.odj-input{flex:1;background:transparent;border:none;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;outline:none}
.odj-del{width:24px;height:24px;border-radius:50%;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 5px var(--shadow-light,#fff);border:none;cursor:pointer;color:#c84040;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
/* Participants checkboxes */
.parts-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.part-check{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:10px;background:var(--bg-secondary,#eef1f6);box-shadow:inset 2px 2px 4px #cac6c0,inset -2px -2px 4px #f8f4ee;cursor:pointer}
.part-check input{accent-color:#4878a6}
.part-check span{font-family:'Sora',sans-serif;font-size:11px;color:#2c2a28}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:10px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de)}
.btn-secondary{background:var(--bg-primary,var(--bg-primary,#e4e8f0));color:#4878a6;box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff)}
.btn svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);border:none;cursor:pointer;color:#4878a6}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
// Si pas de points ODJ en édition, on ajoute un premier champ
document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelectorAll('.odj-item').length === 0) addOdj();
    renum();
});

function addOdj() {
    const list = document.getElementById('odj-list');
    const idx  = list.children.length;
    const div  = document.createElement('div');
    div.className = 'odj-item';
    div.innerHTML = `<span class="odj-num">${idx+1}</span>
        <input type="text" class="odj-input" placeholder="Point de l'ordre du jour">
        <button type="button" class="odj-del" onclick="delOdj(this)">×</button>`;
    list.appendChild(div);
    div.querySelector('.odj-input').focus();
}

function delOdj(btn) {
    if (document.querySelectorAll('.odj-item').length <= 1) return;
    btn.closest('.odj-item').remove();
    renum();
}

function renum() {
    document.querySelectorAll('.odj-item').forEach((el, i) => {
        el.querySelector('.odj-num').textContent = i + 1;
    });
}

function collectOdj() {
    const pts = [...document.querySelectorAll('.odj-input')].map(i => i.value.trim()).filter(Boolean);
    document.getElementById('points_odj_json').value = JSON.stringify(pts);
}
</script>
EXTRAJS;

ob_start();
?>

<?php if (!empty($errors)): ?>
<div class="alert error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<form method="POST" id="frmReunion">
    <input type="hidden" name="points_odj_json" id="points_odj_json">

    <!-- Informations générales -->
    <div class="form-card">
        <div class="form-card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Informations générales
        </div>
        <div class="form-grid cols3">
            <div class="form-group full">
                <label class="form-label">Titre *</label>
                <input type="text" name="titre" class="form-input" value="<?= h($reunion['titre'] ?? $_POST['titre'] ?? '') ?>" placeholder="Ex. Assemblée Générale Annuelle" required>
            </div>
            <div class="form-group">
                <label class="form-label">Type *</label>
                <select name="type_reunion" class="form-select">
                    <?php foreach (['AG'=>'Assemblée Générale','CS'=>'Conseil Syndical','autre'=>'Autre'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= (($reunion['type_reunion'] ?? $_POST['type_reunion'] ?? 'AG') === $v) ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date et heure *</label>
                <input type="datetime-local" name="date_reunion" class="form-input" value="<?= h($dtVal ?: ($_POST['date_reunion'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Lieu</label>
                <input type="text" name="lieu" class="form-input" value="<?= h($reunion['lieu'] ?? $_POST['lieu'] ?? '') ?>" placeholder="Salle, adresse…">
            </div>
            <div class="form-group">
                <label class="form-label">Immeuble</label>
                <select name="id_immeuble" class="form-select">
                    <option value="">— Aucun —</option>
                    <?php foreach ($immeubles as $im): ?>
                    <option value="<?= $im['id'] ?>" <?= (($reunion['id_immeuble'] ?? 0) == $im['id']) ? 'selected' : '' ?>>
                        <?= h($im['nom']) ?> (<?= h($im['reference']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($roleId === 1): ?>
            <div class="form-group">
                <label class="form-label">Établissement</label>
                <select name="id_etablissement" class="form-select">
                    <option value="">— Aucun —</option>
                    <?php foreach ($etabs as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= (($reunion['id_etablissement'] ?? 0) == $e['id']) ? 'selected' : '' ?>><?= h($e['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group full">
                <label class="form-label">Commentaire / Note préparatoire</label>
                <textarea name="commentaire" class="form-textarea"><?= h($reunion['commentaire'] ?? $_POST['commentaire'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Ordre du jour -->
    <div class="form-card">
        <div class="form-card-title">
            <svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            Ordre du jour *
        </div>
        <div class="odj-list" id="odj-list">
            <?php foreach ($odj_rows as $i => $pt): ?>
            <div class="odj-item" data-idx="<?= $i ?>">
                <span class="odj-num"><?= $i+1 ?></span>
                <input type="text" class="odj-input" value="<?= h($pt['intitule']) ?>" placeholder="Point de l'ordre du jour">
                <button type="button" class="odj-del" onclick="delOdj(this)">×</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-xs" onclick="addOdj()" style="margin-top:4px">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Ajouter un point
        </button>
    </div>

    <!-- Participants -->
    <div class="form-card">
        <div class="form-card-title">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Participants invités
        </div>
        <div class="parts-grid">
            <?php foreach ($all_users as $u): ?>
            <label class="part-check">
                <input type="checkbox" name="participants[]" value="<?= $u['id'] ?>"
                    <?= in_array($u['id'], (array)($parts ?: ($_POST['participants'] ?? []))) ? 'checked' : '' ?>>
                <span><?= h(trim($u['prenom'] . ' ' . $u['nom'])) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Submit -->
    <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:30px">
        <a href="agency_reunions.php" class="btn btn-secondary">Annuler</a>
        <button type="submit" class="btn btn-primary" onclick="collectOdj()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $isEdit ? 'Enregistrer' : 'Créer la réunion' ?>
        </button>
    </div>
</form>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
