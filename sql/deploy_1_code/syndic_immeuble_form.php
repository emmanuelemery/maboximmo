<?php
/*
 * syndic_immeuble_form.php
 * Rôle  : Formulaire ajout / modification d'un immeuble
 * Accès : Admin (role_id=1) uniquement pour ajout, manager (2) pour modif
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = current_role_id();
if ($roleId > 2) {
    header('Location: syndic_immeubles.php');
    exit;
}

$pdo = $GLOBALS['pdo'];
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Mode édition ou création ──────────────────────────────────
$id       = (int)($_GET['id'] ?? 0);
$editMode = false;
$imm      = [];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM immeubles WHERE id = ?");
    $stmt->execute([$id]);
    $imm = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $editMode = !empty($imm);
}

// ── Référentiels ──────────────────────────────────────────────
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$gestionnaires  = $pdo->query("SELECT id, nom_complet FROM users WHERE actif=1 ORDER BY nom_complet")->fetchAll(PDO::FETCH_ASSOC);

$types = [
    'sdc'                  => 'SDC (Syndicat de copropriété)',
    'Appartement'          => 'Appartement',
    'Maison individuelle'  => 'Maison individuelle',
    'Maison  - Jumelée'    => 'Maison jumelée',
    'Local commercial'     => 'Local commercial',
    'Garage'               => 'Garage',
];

// ── Messages feedback ─────────────────────────────────────────
$errors  = [];
$success = '';

// ── Traitement POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'reference'        => trim($_POST['reference']       ?? ''),
        'nom'              => trim($_POST['nom']             ?? ''),
        'adresse'          => trim($_POST['adresse']         ?? ''),
        'code_postal'      => trim($_POST['code_postal']     ?? ''),
        'ville'            => trim($_POST['ville']           ?? ''),
        'nb_lots'          => (int)($_POST['nb_lots']        ?? 0),
        'type'             => $_POST['type']                 ?? '',
        'id_etablissement' => (int)($_POST['id_etablissement'] ?? 0),
        'immatriculation'  => strtoupper(trim($_POST['immatriculation'] ?? '')),
        'gestionnaire'     => (int)($_POST['gestionnaire']  ?? 0) ?: null,
    ];

    // Validations
    if ($data['reference'] === '') $errors[] = 'La référence est obligatoire.';
    if ($data['nom'] === '')        $errors[] = 'Le nom est obligatoire.';
    if ($data['adresse'] === '')    $errors[] = 'L\'adresse est obligatoire.';
    if ($data['code_postal'] === '') $errors[] = 'Le code postal est obligatoire.';
    if ($data['ville'] === '')      $errors[] = 'La ville est obligatoire.';
    if ($data['id_etablissement'] === 0) $errors[] = 'L\'établissement est obligatoire.';
    if ($data['nb_lots'] <= 0)      $errors[] = 'Le nombre de lots doit être supérieur à 0.';

    if (empty($errors)) {
        if ($editMode) {
            $stmt = $pdo->prepare("UPDATE immeubles SET
                reference=:reference, nom=:nom, adresse=:adresse, code_postal=:code_postal,
                ville=:ville, nb_lots=:nb_lots, type=:type, id_etablissement=:id_etablissement,
                immatriculation=:immatriculation, gestionnaire=:gestionnaire
                WHERE id=:id");
            $data['id'] = $id;
            $stmt->execute($data);
            $success = 'Immeuble modifié avec succès.';
            // Recharger
            $stmt2 = $pdo->prepare("SELECT * FROM immeubles WHERE id = ?");
            $stmt2->execute([$id]);
            $imm = $stmt2->fetch(PDO::FETCH_ASSOC) ?: $imm;
        } else {
            $stmt = $pdo->prepare("INSERT INTO immeubles
                (reference, nom, adresse, code_postal, ville, nb_lots, type, id_etablissement, immatriculation, gestionnaire)
                VALUES (:reference, :nom, :adresse, :code_postal, :ville, :nb_lots, :type, :id_etablissement, :immatriculation, :gestionnaire)");
            $stmt->execute($data);
            $newId = (int)$pdo->lastInsertId();
            // Rediriger vers la fiche
            header("Location: syndic_immeuble_fiche.php?id=$newId");
            exit;
        }
    }
}

// Valeurs du formulaire (POST ou BDD)
$val = function(string $k) use ($imm): string {
    return (string)($_POST[$k] ?? $imm[$k] ?? '');
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $editMode ? 'Modifier' : 'Ajouter' ?> un immeuble — Syndic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/theme-syndic.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; background: #ffffff; color: #1a1816; min-height: 100vh; display: flex; }
        .shell { width: 100%; min-height: 100vh; }
        .sb-content { margin-left: 220px; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        .topbar { display: flex; align-items: center; gap: 10px; padding: 0 24px 0 20px; height: 56px; background: #ffffff; box-shadow: 0 4px 12px rgba(196,192,186,0.45); position: sticky; top: 0; z-index: 100; flex-shrink: 0; }
        .topbar-back { width: 34px; height: 34px; border-radius: 10px; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
        .topbar-back:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .topbar-back svg { width:15px; height:15px; stroke:#7a9ab8; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; font-family: 'DM Mono', monospace; font-size: 12px; letter-spacing: 0.06em; color: #8a8680; margin-left: 40px; }
        .topbar-breadcrumb a { color: inherit; text-decoration: none; }
        .topbar-breadcrumb .active { color: #4878a6; font-weight: 500; }
        .topbar-sep { color: #c8c4be; }
        .topbar-spacer { flex: 1; }
        .topbar-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #6898bf, #4878a6); display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }

        .main { flex: 1; overflow-y: auto; padding: 0 28px 40px; }
        .page-head { display: flex; align-items: center; justify-content: space-between; height: 80px; flex-shrink: 0; border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 20px; }
        .page-head-module { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.22em; text-transform: uppercase; color: #a8a49e; margin-bottom: 3px; }
        .page-head-title { font-size: 22px; font-weight: 700; color: #1a1816; letter-spacing: -0.02em; }

        .sec-head { display: flex; align-items: center; gap: 14px; margin: 20px 0 10px; }
        .sec-txt { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0; background: linear-gradient(180deg,#8eb4d3 0%,#4878a6 40%,#25486a 70%,#6898bf 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .line-l { height: 1.5px; width: 28px; flex-shrink: 0; background: linear-gradient(90deg, transparent 0%, #25486a 40%, #8eb4d3 100%); border-radius: 2px; }
        .line-r { height: 1.5px; flex: 1; background: linear-gradient(90deg, #8eb4d3 0%, #4878a6 30%, #25486a 55%, transparent 100%); border-radius: 2px; }

        .form-card { background: #ffffff; border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; padding: 20px 24px; margin-bottom: 16px; max-width: 800px; }
        .form-row { display: grid; gap: 14px; margin-bottom: 14px; }
        .form-row.cols2 { grid-template-columns: 1fr 1fr; }
        .form-row.cols3 { grid-template-columns: 1fr 1fr 1fr; }
        .form-row.cols1 { grid-template-columns: 1fr; }
        .form-row:last-child { margin-bottom: 0; }

        .ff label { display: block; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: #a8a49e; margin-bottom: 5px; }
        .ff label .req { color: #4878a6; }
        .ff input, .ff select, .ff textarea {
            width: 100%; background: #ffffff;
            box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff;
            border: none; border-radius: 10px; padding: 9px 12px;
            font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
            outline: none; box-sizing: border-box;
        }
        .ff input:focus, .ff select:focus {
            box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(72,120,166,0.3);
        }
        .ff input.error { box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(204,92,88,0.4); }

        .v2-btn { padding: 0 20px; height: 38px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; color: #3a3830; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: box-shadow 0.15s; }
        .v2-btn:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .v2-btn.primary { background: #4878a6; color: #fff; }

        .alert { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; max-width: 800px; }
        .alert.success { background: #e0f0e8; color: #1a6035; border: 1px solid #b0d8c0; }
        .alert.error   { background: #fce8e6; color: #a03020; border: 1px solid #f0c0bc; }
        .alert ul { margin: 4px 0 0 16px; }

        /* Inputmask */
        #immatriculation { font-family: 'DM Mono', monospace; letter-spacing: 0.1em; }
        .immat-hint { font-size: 10px; color: #a8a49e; margin-top: 4px; font-family: 'DM Mono', monospace; }
    </style>
</head>
<body>
<div class="shell">

    <?php include __DIR__ . '/sidebar_syndic.php'; ?>

    <div class="sb-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <button class="topbar-back" onclick="history.back()">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="topbar-back" onclick="history.forward()">
                <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <nav class="topbar-breadcrumb">
                <span>Syndic</span>
                <span class="topbar-sep">›</span>
                <a href="syndic_immeubles.php">Immeubles</a>
                <span class="topbar-sep">›</span>
                <span class="active"><?= $editMode ? 'Modifier ' . h($imm['nom'] ?? '') : 'Ajouter' ?></span>
            </nav>
            <div class="topbar-spacer"></div>
            <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? '?', 0, 1) . substr($_SESSION['nom'] ?? '', 0, 1)) ?></div>
        </header>

        <!-- MAIN -->
        <main class="main">

            <div class="page-head">
                <div>
                    <div class="page-head-module">Module Syndic</div>
                    <div class="page-head-title"><?= $editMode ? '✏️ Modifier l\'immeuble' : '➕ Nouvel immeuble' ?></div>
                </div>
            </div>

            <?php if ($success): ?>
            <div class="alert success" style="max-width:800px">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?= h($success) ?>
                <?php if ($editMode): ?>
                &nbsp;—&nbsp;<a href="syndic_immeuble_fiche.php?id=<?= $id ?>" style="color:#1a6035;font-weight:600">Voir la fiche →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($errors): ?>
            <div class="alert error" style="max-width:800px">
                <div>
                    <strong>Veuillez corriger les erreurs suivantes :</strong>
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?= h($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST">

                <!-- Section Identité -->
                <div class="sec-head">
                    <div class="line-l"></div>
                    <span class="sec-txt">Identité de l'immeuble</span>
                    <div class="line-r"></div>
                </div>
                <div class="form-card">
                    <div class="form-row cols3">
                        <div class="ff">
                            <label>Référence <span class="req">*</span></label>
                            <input type="text" name="reference" value="<?= h($val('reference')) ?>"
                                   placeholder="ex. 1070" required class="<?= in_array('La référence est obligatoire.', $errors) ? 'error' : '' ?>">
                        </div>
                        <div class="ff" style="grid-column: span 2">
                            <label>Nom de l'immeuble <span class="req">*</span></label>
                            <input type="text" name="nom" value="<?= h($val('nom')) ?>"
                                   placeholder="ex. Résidence Le Chamois" required>
                        </div>
                    </div>
                    <div class="form-row cols2">
                        <div class="ff">
                            <label>Type <span class="req">*</span></label>
                            <select name="type" required>
                                <option value="">— Sélectionner —</option>
                                <?php foreach ($types as $k => $label): ?>
                                    <option value="<?= h($k) ?>" <?= $val('type') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ff">
                            <label>Nombre de lots <span class="req">*</span></label>
                            <input type="number" name="nb_lots" value="<?= h($val('nb_lots')) ?>"
                                   min="0" placeholder="0" required>
                        </div>
                    </div>
                    <div class="form-row cols1">
                        <div class="ff">
                            <label>Immatriculation (SDC)</label>
                            <input type="text" name="immatriculation" id="immatriculation"
                                   value="<?= h($val('immatriculation')) ?>" placeholder="AAA-000-000">
                            <div class="immat-hint">Format : AAA-000-000 (ex. RCS-123-456)</div>
                        </div>
                    </div>
                </div>

                <!-- Section Adresse -->
                <div class="sec-head">
                    <div class="line-l"></div>
                    <span class="sec-txt">Adresse</span>
                    <div class="line-r"></div>
                </div>
                <div class="form-card">
                    <div class="form-row cols1">
                        <div class="ff">
                            <label>Adresse <span class="req">*</span></label>
                            <input type="text" name="adresse" value="<?= h($val('adresse')) ?>"
                                   placeholder="Numéro et rue" required>
                        </div>
                    </div>
                    <div class="form-row cols2">
                        <div class="ff">
                            <label>Code postal <span class="req">*</span></label>
                            <input type="text" name="code_postal" value="<?= h($val('code_postal')) ?>"
                                   placeholder="69000" maxlength="10" required>
                        </div>
                        <div class="ff">
                            <label>Ville / Commune <span class="req">*</span></label>
                            <input type="text" name="ville" value="<?= h($val('ville')) ?>"
                                   placeholder="Lyon" required>
                        </div>
                    </div>
                </div>

                <!-- Section Organisation -->
                <div class="sec-head">
                    <div class="line-l"></div>
                    <span class="sec-txt">Organisation & Affectation</span>
                    <div class="line-r"></div>
                </div>
                <div class="form-card">
                    <div class="form-row cols2">
                        <div class="ff">
                            <label>Établissement <span class="req">*</span></label>
                            <select name="id_etablissement" required>
                                <option value="">— Sélectionner —</option>
                                <?php foreach ($etablissements as $e): ?>
                                    <option value="<?= $e['id'] ?>"
                                        <?= (int)$val('id_etablissement') === (int)$e['id'] ? 'selected' : '' ?>>
                                        <?= h($e['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ff">
                            <label>Gestionnaire</label>
                            <select name="gestionnaire">
                                <option value="">— Aucun —</option>
                                <?php foreach ($gestionnaires as $g): ?>
                                    <option value="<?= $g['id'] ?>"
                                        <?= (int)$val('gestionnaire') === (int)$g['id'] ? 'selected' : '' ?>>
                                        <?= h($g['nom_complet']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div style="display:flex;gap:10px;align-items:center;max-width:800px;margin-top:8px">
                    <a href="syndic_immeubles.php" class="v2-btn">✕ Annuler</a>
                    <?php if ($editMode): ?>
                        <a href="syndic_immeuble_fiche.php?id=<?= $id ?>" class="v2-btn">📄 Voir la fiche</a>
                    <?php endif; ?>
                    <button type="submit" class="v2-btn primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <?= $editMode ? 'Enregistrer les modifications' : 'Créer l\'immeuble' ?>
                    </button>
                </div>

            </form>

        </main>
    </div>
</div>

<!-- Inputmask pour l'immatriculation -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('immatriculation');
    if (!el) return;
    el.addEventListener('input', function () {
        let v = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        let out = '';
        for (let i = 0; i < v.length && i < 9; i++) {
            if (i === 3 || i === 6) out += '-';
            out += v[i];
        }
        const pos = this.selectionStart;
        this.value = out;
        try { this.setSelectionRange(pos, pos); } catch(e) {}
    });
});
</script>
</body>
</html>
