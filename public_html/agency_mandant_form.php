<?php
// agency_mandant_form.php — Création / édition d'un mandant
require_once __DIR__ . '/inc/init.php';
require_login();

$etab_id = (int)($_SESSION['etablissement_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role_id = (int)current_role_id();

$editId   = (int)($_GET['id'] ?? 0);
$mandant  = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM agency_mandant WHERE id=?");
    $stmt->execute([$editId]);
    $mandant = $stmt->fetch(PDO::FETCH_ASSOC);
}

$immeubles      = $pdo->query("SELECT id, nom, ville FROM immeubles ORDER BY nom LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// ── Sauvegarde ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mandant'])) {
    $type   = in_array($_POST['type_mandant']??'',['copropriete','proprietaire','sci','autre']) ? $_POST['type_mandant'] : 'copropriete';
    $fields = [
        'type_mandant'    => $type,
        'raison_sociale'  => trim($_POST['raison_sociale'] ?? ''),
        'representant'    => trim($_POST['representant'] ?? ''),
        'adresse'         => trim($_POST['adresse'] ?? ''),
        'code_postal'     => trim($_POST['code_postal'] ?? ''),
        'ville'           => trim($_POST['ville'] ?? ''),
        'email'           => trim($_POST['email'] ?? ''),
        'telephone'       => trim($_POST['telephone'] ?? ''),
        'siret'           => trim($_POST['siret'] ?? ''),
        'id_immeuble'     => (int)($_POST['id_immeuble'] ?? 0) ?: null,
        'id_etablissement'=> (int)($_POST['id_etablissement'] ?? 0) ?: null,
        'actif'           => isset($_POST['actif']) ? 1 : 0,
    ];

    if (empty($fields['raison_sociale'])) {
        $error = 'Le nom est obligatoire.';
    } else {
        $pdo->beginTransaction();
        try {
            // ── Sauvegarde agency_mandant (legacy conservé) ──
            if ($editId) {
                $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($fields)));
                $pdo->prepare("UPDATE agency_mandant SET $set WHERE id=?")->execute([...array_values($fields), $editId]);
            } else {
                $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
                $phs  = implode(',', array_fill(0, count($fields), '?'));
                $pdo->prepare("INSERT INTO agency_mandant ($cols) VALUES($phs)")->execute(array_values($fields));
                $editId = (int)$pdo->lastInsertId();
            }

            // ── Double écriture TIERS (Phase 3.2) ──
            // Type de tiers selon type_mandant : copropriete → syndicat_coprop, sci → personne_morale, sinon personne_morale
            $typeTiers = match ($type) {
                'copropriete' => 'syndicat_coprop',
                'sci'         => 'personne_morale',
                default       => 'personne_morale',
            };
            // role_code selon type_mandant
            $roleCode = match ($type) {
                'copropriete' => 'syndicat_coprop',
                'sci'         => 'proprietaire',
                default       => 'mandant',
            };

            // Récupérer l'id_tiers existant ou en créer un
            $existingTiers = (int)$pdo->query("SELECT id_tiers FROM agency_mandant WHERE id=$editId")->fetchColumn();
            if ($existingTiers > 0) {
                // UPDATE tiers existant
                $pdo->prepare("
                    UPDATE tiers SET
                        id_agence=:id_agence,
                        type_tiers=:type_tiers,
                        raison_sociale=:rs, siret=:siret,
                        email=:email, telephone=:tel,
                        adresse_ligne1=:adr, code_postal=:cp, ville=:ville,
                        actif=:actif,
                        nom_affichage=:rs
                    WHERE id=:id
                ")->execute([
                    ':id_agence'  => $fields['id_etablissement'] ?: null,
                    ':type_tiers' => $typeTiers,
                    ':rs'         => $fields['raison_sociale'],
                    ':siret'      => $fields['siret'] ?: null,
                    ':email'      => $fields['email'] ?: null,
                    ':tel'        => $fields['telephone'] ?: null,
                    ':adr'        => $fields['adresse'] ?: null,
                    ':cp'         => $fields['code_postal'] ?: null,
                    ':ville'      => $fields['ville'] ?: null,
                    ':actif'      => $fields['actif'],
                    ':id'         => $existingTiers,
                ]);
                $idTiers = $existingTiers;
            } else {
                // INSERT nouveau tiers
                $pdo->prepare("
                    INSERT INTO tiers
                        (id_agence, type_tiers, raison_sociale, nom_affichage, siret,
                         email, telephone, adresse_ligne1, code_postal, ville, pays, actif,
                         source_creation, id_user_createur)
                    VALUES
                        (:id_agence, :type_tiers, :rs, :rs, :siret,
                         :email, :tel, :adr, :cp, :ville, 'France', :actif,
                         'agency_mandant_form', :user_id)
                ")->execute([
                    ':id_agence'  => $fields['id_etablissement'] ?: null,
                    ':type_tiers' => $typeTiers,
                    ':rs'         => $fields['raison_sociale'],
                    ':siret'      => $fields['siret'] ?: null,
                    ':email'      => $fields['email'] ?: null,
                    ':tel'        => $fields['telephone'] ?: null,
                    ':adr'        => $fields['adresse'] ?: null,
                    ':cp'         => $fields['code_postal'] ?: null,
                    ':ville'      => $fields['ville'] ?: null,
                    ':actif'      => $fields['actif'],
                    ':user_id'    => $user_id ?: null,
                ]);
                $idTiers = (int)$pdo->lastInsertId();
                // Lier agency_mandant.id_tiers
                $pdo->prepare("UPDATE agency_mandant SET id_tiers=? WHERE id=?")->execute([$idTiers, $editId]);
            }

            // Rôle contextualisé à l'immeuble si renseigné
            if ($fields['id_immeuble']) {
                $pdo->prepare("
                    INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif)
                    VALUES (?, ?, 'immeuble', ?, ?)
                ")->execute([$idTiers, $roleCode, $fields['id_immeuble'], $fields['actif']]);
            } else {
                // Rôle global sans contexte — vérifier qu'il n'existe pas déjà
                $st = $pdo->prepare("SELECT COUNT(*) FROM tiers_roles WHERE id_tiers=? AND role_code=? AND objet_type IS NULL");
                $st->execute([$idTiers, $roleCode]);
                if ((int)$st->fetchColumn() === 0) {
                    $pdo->prepare("
                        INSERT INTO tiers_roles (id_tiers, role_code, objet_type, id_objet, actif)
                        VALUES (?, ?, NULL, NULL, ?)
                    ")->execute([$idTiers, $roleCode, $fields['actif']]);
                }
            }

            $pdo->commit();
            header('Location: agency_mandant_fiche.php?id='.$editId);
            exit;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'Erreur enregistrement : ' . $ex->getMessage();
        }
    }
}

$m = $mandant ?? [];
$v = fn($k, $def='') => htmlspecialchars($m[$k] ?? $def);

$layout_title   = $editId ? 'Modifier le mandant' : 'Nouveau mandant';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '';

$layout_head_actions = '
<a href="agency_mandants.php" class="ph-btn">
    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Retour
</a>
<button type="submit" form="mandant_form" class="ph-btn primary">
    <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Enregistrer
</button>
<span class="ph-btn dispo">dispo</span>
<span class="ph-btn dispo">dispo</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.mbi-card-form{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:16px;box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:22px 24px;margin-bottom:18px}
.mbi-card-form .card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8a5040;margin-bottom:16px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid.three{grid-template-columns:1fr 1fr 1fr}
.fg2{grid-column:span 2}.fg3{grid-column:span 3}
.form-group{display:flex;flex-direction:column;gap:5px}
label.fl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#7a6830}
.mbi-card-form input,.mbi-card-form select,.mbi-card-form textarea{padding:9px 13px;border:none;border-radius:10px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff);color:#1a1816;font-family:inherit;font-size:13px;outline:none;width:100%}
.mbi-card-form textarea{resize:vertical;min-height:72px}
.type-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.type-card{padding:14px;border-radius:14px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:6px 6px 14px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);cursor:pointer;text-align:center;transition:all .15s;border:2px solid transparent}
.type-card:hover{box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff)}
.type-card input[type=radio]{display:none}
.type-card.selected{border-color:#3a7a6a;box-shadow:inset 3px 3px 6px var(--shadow-dark,#d4d7de),inset -3px -3px 8px var(--shadow-light,#fff);color:#3a7a6a}
.type-card .ico{font-size:22px;margin-bottom:6px}
.type-card .lbl{font-size:12px;font-weight:700}
.alert{padding:12px 18px;border-radius:10px;font-size:13px;margin-bottom:16px;background:#fee2e2;color:#8a5040}
.chk-item{display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding:8px 14px;border-radius:10px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 8px var(--shadow-light,#fff);width:fit-content}
.chk-item input{width:16px;height:16px;box-shadow:none}
@media(max-width:700px){.form-grid,.form-grid.three,.type-grid{grid-template-columns:1fr}.fg2,.fg3{grid-column:1}}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
function selectType(v) {
    document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
    const lbl = document.querySelector('.type-card input[value="'+v+'"]')?.closest('.type-card');
    if (lbl) lbl.classList.add('selected');
    document.querySelector('input[name=type_mandant][value="'+v+'"]').checked = true;
}
</script>
EXTRAJS;

ob_start();
?>

<?php if (!empty($error)): ?>
<div class="alert"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" id="mandant_form">
<input type="hidden" name="save_mandant" value="1">

<!-- Type -->
<div class="mbi-card-form">
    <div class="card-title">Type de mandant</div>
    <div class="type-grid" id="typeGrid">
        <?php foreach (['copropriete'=>['🏢','Copropriété'],'proprietaire'=>['👤','Propriétaire'],'sci'=>['🏛️','SCI'],'autre'=>['📋','Autre']] as $val=>[$ico,$lbl]): ?>
        <label class="type-card <?= ($m['type_mandant']??'copropriete')===$val?'selected':'' ?>" onclick="selectType('<?= $val ?>')">
            <input type="radio" name="type_mandant" value="<?= $val ?>" <?= ($m['type_mandant']??'copropriete')===$val?'checked':'' ?>>
            <div class="ico"><?= $ico ?></div>
            <div class="lbl"><?= $lbl ?></div>
        </label>
        <?php endforeach; ?>
    </div>
</div>

<!-- Identité -->
<div class="mbi-card-form">
    <div class="card-title">Identification</div>
    <div class="form-grid">
        <div class="form-group fg2">
            <label class="fl">Nom / Raison sociale *</label>
            <input type="text" name="raison_sociale" required value="<?= $v('raison_sociale') ?>" placeholder="Ex: Résidence Les Tilleuls — CS, M. Martin...">
        </div>
        <div class="form-group">
            <label class="fl">Représentant / Contact principal</label>
            <input type="text" name="representant" value="<?= $v('representant') ?>" placeholder="Président CS, gérant SCI...">
        </div>
        <div class="form-group">
            <label class="fl">SIRET (optionnel)</label>
            <input type="text" name="siret" value="<?= $v('siret') ?>" placeholder="000 000 000 00000">
        </div>
    </div>
</div>

<!-- Coordonnées -->
<div class="mbi-card-form">
    <div class="card-title">Coordonnées</div>
    <div class="form-grid">
        <div class="form-group fg2">
            <label class="fl">Adresse</label>
            <input type="text" name="adresse" value="<?= $v('adresse') ?>">
        </div>
        <div class="form-group">
            <label class="fl">Code postal</label>
            <input type="text" name="code_postal" value="<?= $v('code_postal') ?>">
        </div>
        <div class="form-group">
            <label class="fl">Ville</label>
            <input type="text" name="ville" value="<?= $v('ville') ?>">
        </div>
        <div class="form-group">
            <label class="fl">Email</label>
            <input type="email" name="email" value="<?= $v('email') ?>">
        </div>
        <div class="form-group">
            <label class="fl">Téléphone</label>
            <input type="tel" name="telephone" value="<?= $v('telephone') ?>">
        </div>
    </div>
</div>

<!-- Liens -->
<div class="mbi-card-form">
    <div class="card-title">Liens &amp; Agence</div>
    <div class="form-grid">
        <div class="form-group">
            <label class="fl">Immeuble associé</label>
            <select name="id_immeuble">
                <option value="">— Aucun lien direct —</option>
                <?php foreach ($immeubles as $i): ?>
                <option value="<?= $i['id'] ?>" <?= ($m['id_immeuble']??'')==$i['id']?'selected':'' ?>><?= htmlspecialchars($i['nom'].($i['ville']?' — '.$i['ville']:'')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="fl">Agence</label>
            <select name="id_etablissement">
                <option value="">— Toute la société —</option>
                <?php foreach ($etablissements as $e): ?>
                <option value="<?= $e['id'] ?>" <?= ($m['id_etablissement']??$etab_id)==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="chk-item">
                <input type="checkbox" name="actif" <?= !isset($m['actif'])||$m['actif']?'checked':'' ?>>
                Mandant actif
            </label>
        </div>
    </div>
</div>

</form>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
