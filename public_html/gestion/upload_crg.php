<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/bootstrap.php';
require_once __DIR__.'/../inc/roles_services.php';
require_login();

if (!function_exists('e')) {
    function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

function fmt_money(float|int|string|null $v): string {
    return number_format((float)($v ?? 0), 2, ',', ' ') . ' &euro;';
}

// ── Auth : admin, manager ou module gestion activé ──────────
$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 7], true) && !hasServiceAccess($roleId, 'gestion') && !hasServiceAccess($roleId, 'agency')) {
    http_response_code(403);
    exit('Acces interdit');
}
$userSocieteId = (int)($_SESSION['id_societe'] ?? 0);
$userAgenceId  = (int)($_SESSION['id_agence'] ?? 0);

global $pdo;

// ── Geocode helper ──────────────────────────────────────────
function geocode_address(string $address): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q'      => $address,
        'format' => 'json',
        'limit'  => 1,
    ]);
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: MaBoxImmo/1.0\r\n",
        'timeout' => 5,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    if (empty($data[0])) return null;
    return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
}

// ── POST handler ────────────────────────────────────────────
$msg = '';
$msg_type = '';
$parse_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_proprietaire = (int)($_POST['id_proprietaire'] ?? 0);
    $annee           = (int)($_POST['annee'] ?? 0);
    $trimestre       = (int)($_POST['trimestre'] ?? 0);
    $confirmer       = !empty($_POST['confirmer_remplacement']);

    // Validate inputs
    if ($id_proprietaire < 1 || $annee < 2000 || $trimestre < 1 || $trimestre > 4) {
        $msg = 'Parametres invalides.';
        $msg_type = 'error';
    } elseif (empty($_FILES['fichier_crg']['tmp_name']) || $_FILES['fichier_crg']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Veuillez selectionner un fichier PDF.';
        $msg_type = 'error';
    } else {
        $tmp  = $_FILES['fichier_crg']['tmp_name'];
        $size = $_FILES['fichier_crg']['size'];
        $mime = mime_content_type($tmp);

        if ($mime !== 'application/pdf') {
            $msg = 'Le fichier doit etre un PDF (type detecte : ' . e($mime) . ').';
            $msg_type = 'error';
        } elseif ($size > 50 * 1024 * 1024) {
            $msg = 'Le fichier depasse 50 Mo.';
            $msg_type = 'error';
        } else {
            // Check duplicate
            $stmt = $pdo->prepare('SELECT id FROM crg_trimestres WHERE id_proprietaire=? AND annee=? AND trimestre=?');
            $stmt->execute([$id_proprietaire, $annee, $trimestre]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && !$confirmer) {
                $msg = 'Un CRG existe deja pour ce proprietaire / annee / trimestre. Cochez "Confirmer le remplacement" pour re-importer.';
                $msg_type = 'error';
            } else {
                // Save PDF
                $dir = rtrim(UPLOAD_CRG, '/\\') . '/' . $id_proprietaire;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $filename = $annee . '_T' . $trimestre . '.pdf';
                $pdf_path = $dir . '/' . $filename;
                move_uploaded_file($tmp, $pdf_path);
                $relative_path = $id_proprietaire . '/' . $filename;

                $crg_id = null;

                if ($existing) {
                    $crg_id = (int)$existing['id'];
                    // Delete child data for re-import
                    $pdo->prepare('DELETE FROM crg_situations_locataires WHERE id_crg=?')->execute([$crg_id]);
                    $pdo->prepare('DELETE FROM crg_ecritures WHERE id_crg=?')->execute([$crg_id]);
                    $pdo->prepare('UPDATE crg_trimestres SET fichier_pdf=?, parse_statut="en_cours", uploaded_at=NOW() WHERE id=?')
                         ->execute([$relative_path, $crg_id]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO crg_trimestres (id_proprietaire, annee, trimestre, fichier_pdf, parse_statut) VALUES (?,?,?,?,?)');
                    $stmt->execute([$id_proprietaire, $annee, $trimestre, $relative_path, 'en_cours']);
                    $crg_id = (int)$pdo->lastInsertId();
                }

                // ── Run parser ──────────────────────────────────
                $script = rtrim(SCRIPTS_PATH, '/\\') . '/parse_crg.py';
                $cmd = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($pdf_path) . ' 2>&1';
                $output = shell_exec($cmd);
                $parsed = $output ? json_decode($output, true) : null;

                if (!$parsed || !empty($parsed['error'])) {
                    $pdo->prepare('UPDATE crg_trimestres SET parse_statut="erreur", parse_log=?, parsed_at=NOW() WHERE id=?')
                         ->execute([$output ?? 'Aucune sortie', $crg_id]);
                    $msg = 'Erreur lors du parsing du CRG : ' . e(substr($output ?? 'Aucune sortie', 0, 500));
                    $msg_type = 'error';
                } else {
                    // Update CRG meta
                    $pdo->prepare('UPDATE crg_trimestres SET parse_statut="ok", parsed_at=NOW(),
                        date_arrete=?, solde_report=?, total_debits=?, total_credits=?, total_tva=?, parse_log=NULL
                        WHERE id=?')->execute([
                        $parsed['date_arrete'] ?? null,
                        $parsed['solde_report'] ?? 0,
                        $parsed['total_debits'] ?? 0,
                        $parsed['total_credits'] ?? 0,
                        $parsed['total_tva'] ?? 0,
                        $crg_id,
                    ]);

                    $nb_immeubles = 0;
                    $nb_lots = 0;
                    $nb_impaye = 0;

                    // ── Loop immeubles ──────────────────────────
                    foreach ($parsed['immeubles'] ?? [] as $imm) {
                        $nb_immeubles++;
                        $code_crg = $imm['code'] ?? '';
                        $nom      = $imm['nom'] ?? '';
                        $adresse  = $imm['adresse'] ?? '';

                        // Find or create immeuble
                        $stmt = $pdo->prepare('SELECT id FROM immeubles WHERE code_crg=? AND id_proprietaire=?');
                        $stmt->execute([$code_crg, $id_proprietaire]);
                        $imm_row = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($imm_row) {
                            $id_immeuble = (int)$imm_row['id'];
                        } else {
                            // Geocode (rate limit 1 req/s)
                            $geo = null;
                            if ($adresse) {
                                $geo = geocode_address($adresse);
                                usleep(1100000); // 1.1s
                            }
                            $stmt = $pdo->prepare('INSERT INTO immeubles (id_proprietaire, id_societe, id_agence, code_crg, nom_immeuble, adresse_1, latitude, longitude, type_immeuble, mode_gestion) VALUES (?,?,?,?,?,?,?,?,?,?)');
                            $stmt->execute([
                                $id_proprietaire,
                                $userSocieteId ?: null,
                                $userAgenceId ?: null,
                                $code_crg,
                                $nom,
                                $adresse,
                                $geo['lat'] ?? null,
                                $geo['lng'] ?? null,
                                'immeuble',
                                'gestion',
                            ]);
                            $id_immeuble = (int)$pdo->lastInsertId();
                        }

                        // ── Loop lots ───────────────────────────
                        foreach ($imm['lots'] ?? [] as $lot) {
                            $nb_lots++;
                            $numero_lot = $lot['numero_lot'] ?? '';
                            $type_bien  = $lot['type_bien'] ?? 'appartement';
                            $statut_occ = $lot['statut'] ?? 'vacant';

                            // Find or create bien
                            $stmt = $pdo->prepare('SELECT id FROM biens WHERE id_immeuble=? AND numero_lot=?');
                            $stmt->execute([$id_immeuble, $numero_lot]);
                            $bien_row = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($bien_row) {
                                $id_bien = (int)$bien_row['id'];
                                $pdo->prepare('UPDATE biens SET statut_occupation=? WHERE id=?')
                                     ->execute([$statut_occ, $id_bien]);
                            } else {
                                $stmt = $pdo->prepare('INSERT INTO biens (id_immeuble, id_proprietaire, numero_lot, type_bien, statut_occupation) VALUES (?,?,?,?,?)');
                                $stmt->execute([$id_immeuble, $id_proprietaire, $numero_lot, $type_bien, $statut_occ]);
                                $id_bien = (int)$pdo->lastInsertId();
                            }

                            // Find or create bail for occupied lots
                            $id_bail = null;
                            if ($statut_occ === 'occupe' && !empty($lot['locataire_nom'])) {
                                $stmt = $pdo->prepare('SELECT id FROM baux WHERE id_bien=? AND actif=1 LIMIT 1');
                                $stmt->execute([$id_bien]);
                                $bail_row = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($bail_row) {
                                    $id_bail = (int)$bail_row['id'];
                                } else {
                                    $stmt = $pdo->prepare('INSERT INTO baux (id_bien, locataire_nom, actif) VALUES (?,?,1)');
                                    $stmt->execute([$id_bien, $lot['locataire_nom']]);
                                    $id_bail = (int)$pdo->lastInsertId();
                                }
                            }

                            $impaye = (float)($lot['total_impaye'] ?? 0);
                            if ($impaye > 0) $nb_impaye++;

                            // INSERT situation locataire
                            $stmt = $pdo->prepare('INSERT INTO crg_situations_locataires
                                (id_crg, id_bien, id_bail, locataire_nom, numero_lot, type_bien,
                                 categorie_bien, loyer_appele, solde_anterieur, total_loyers,
                                 total_charges, total_regle, total_impaye, nb_huissier,
                                 montant_huissier, statut_trimestre)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                            $stmt->execute([
                                $crg_id,
                                $id_bien,
                                $id_bail,
                                $lot['locataire_nom'] ?? '',
                                $numero_lot,
                                $type_bien,
                                $lot['categorie_bien'] ?? 'habitation',
                                $lot['loyer_appele'] ?? 0,
                                $lot['solde_anterieur'] ?? 0,
                                $lot['total_loyers'] ?? 0,
                                $lot['total_charges'] ?? 0,
                                $lot['total_regle'] ?? 0,
                                $impaye,
                                $lot['nb_huissier'] ?? 0,
                                $lot['montant_huissier'] ?? 0,
                                $lot['statut_trimestre'] ?? $statut_occ,
                            ]);
                        }

                        // ── INSERT ecritures (charges) ──────────
                        foreach ($imm['ecritures'] ?? [] as $ecr) {
                            $stmt = $pdo->prepare('INSERT INTO crg_ecritures
                                (id_crg, id_bien, libelle, categorie, debit, credit, tva)
                                VALUES (?,?,?,?,?,?,?)');
                            $stmt->execute([
                                $crg_id,
                                $ecr['id_bien'] ?? null,
                                $ecr['libelle'] ?? '',
                                $ecr['categorie'] ?? 'autre',
                                $ecr['debit'] ?? 0,
                                $ecr['credit'] ?? 0,
                                $ecr['tva'] ?? 0,
                            ]);
                        }
                    }

                    $parse_result = [
                        'nb_immeubles' => $nb_immeubles,
                        'nb_lots'      => $nb_lots,
                        'nb_impaye'    => $nb_impaye,
                        'solde_report' => (float)($parsed['solde_report'] ?? 0),
                        'immeubles'    => $parsed['immeubles'] ?? [],
                    ];

                    $msg = 'CRG importe et parse avec succes.';
                    $msg_type = 'success';
                }
            }
        }
    }
}

// ── Load proprietaires for form ─────────────────────────────
$proprietaires = $pdo->query('SELECT id, nom, prenom FROM proprietaires WHERE actif=1 ORDER BY nom, prenom')->fetchAll(PDO::FETCH_ASSOC);

// ── History: last 50 CRG ────────────────────────────────────
$history = $pdo->query('
    SELECT c.*, CONCAT(p.nom, " ", p.prenom) AS proprio_nom
    FROM crg_trimestres c
    LEFT JOIN proprietaires p ON p.id = c.id_proprietaire
    ORDER BY c.uploaded_at DESC
    LIMIT 50
')->fetchAll(PDO::FETCH_ASSOC);

$current_page = 'upload_crg';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import CRG - Ma Box Immo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/gestion.css') ?>">
<style>
.page-wrap{display:flex;min-height:100vh}
.main-content{margin-left:272px;flex:1;padding:32px 40px;background:#f8f7f5}
.page-title{font-family:'Sora',sans-serif;font-size:22px;font-weight:700;color:#2c2c2c;margin-bottom:24px}
.alert{padding:14px 20px;border-radius:8px;margin-bottom:20px;font-size:14px;font-family:'Sora',sans-serif}
.alert-success{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}
.alert-error{background:#fdecea;color:#c62828;border:1px solid #ef9a9a}

/* Upload zone */
.upload-zone{border:2px dashed #c4c0ba;border-radius:12px;padding:40px;text-align:center;background:#faf9f7;transition:border-color .2s,background .2s;cursor:pointer;margin-bottom:20px}
.upload-zone.dragover{border-color:#e67e22;background:#fef5ec}
.upload-zone p{font-family:'Sora',sans-serif;font-size:14px;color:#6b6b6b;margin:0}
.upload-zone .file-info{margin-top:10px;font-family:'JetBrains Mono',monospace;font-size:13px;color:#e67e22}

/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.04em}
.form-group select,.form-group input[type="number"]{padding:10px 12px;border:1px solid #d5d0ca;border-radius:8px;font-family:'Sora',sans-serif;font-size:14px;background:#fff}
.form-group select:focus,.form-group input:focus{outline:none;border-color:#e67e22;box-shadow:0 0 0 3px rgba(230,126,34,.15)}
.checkbox-group{display:flex;align-items:center;gap:8px;margin-bottom:16px;font-family:'Sora',sans-serif;font-size:13px;color:#555}
.btn-upload{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border:none;border-radius:8px;background:#e67e22;color:#fff;font-family:'Sora',sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-upload:hover{background:#cf6d17}

/* KPI grid */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px}
.kpi-card{background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.kpi-val{font-family:'Sora',sans-serif;font-size:26px;font-weight:700;color:#2c2c2c}
.kpi-label{font-family:'Sora',sans-serif;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.04em;margin-top:4px}

/* Tables */
.table-wrap{overflow-x:auto;margin-bottom:28px}
table.data{width:100%;border-collapse:collapse;font-family:'Sora',sans-serif;font-size:13px}
table.data th{background:#f0ede8;padding:10px 14px;text-align:left;font-weight:600;color:#555;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
table.data td{padding:10px 14px;border-bottom:1px solid #ebe8e3;color:#333}
table.data tbody tr:hover{background:#faf8f5}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-ok{background:#e8f5e9;color:#2e7d32}
.badge-erreur{background:#fdecea;color:#c62828}
.badge-en_attente{background:#fff8e1;color:#f57f17}
.badge-en_cours{background:#e3f2fd;color:#1565c0}

.section-title{font-family:'Sora',sans-serif;font-size:16px;font-weight:600;color:#2c2c2c;margin-bottom:12px}
</style>
</head>
<body>
<div class="page-wrap">
<?php include __DIR__.'/../sidebar_bailleur.php'; ?>
<div class="main-content">

<h1 class="page-title">Import CRG trimestriel</h1>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type ?>"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($parse_result): ?>
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-val"><?= $parse_result['nb_immeubles'] ?></div>
        <div class="kpi-label">Immeubles</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-val"><?= $parse_result['nb_lots'] ?></div>
        <div class="kpi-label">Lots</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-val"><?= $parse_result['nb_impaye'] ?></div>
        <div class="kpi-label">Impayes</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-val"><?= fmt_money($parse_result['solde_report']) ?></div>
        <div class="kpi-label">Solde report</div>
    </div>
</div>

<?php if (!empty($parse_result['immeubles'])): ?>
<h2 class="section-title">Immeubles importes</h2>
<div class="table-wrap">
<table class="data">
<thead><tr><th>Code</th><th>Nom</th><th>Lots</th><th>Debits</th><th>Credits</th><th>Solde</th></tr></thead>
<tbody>
<?php foreach ($parse_result['immeubles'] as $imm): ?>
<tr>
    <td><?= e($imm['code'] ?? '') ?></td>
    <td><?= e($imm['nom'] ?? '') ?></td>
    <td><?= count($imm['lots'] ?? []) ?></td>
    <td><?= fmt_money($imm['total_debits'] ?? 0) ?></td>
    <td><?= fmt_money($imm['total_credits'] ?? 0) ?></td>
    <td><?= fmt_money(($imm['total_credits'] ?? 0) - ($imm['total_debits'] ?? 0)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Upload form ─────────────────────────────────────────── -->
<form method="post" enctype="multipart/form-data" id="uploadForm">
    <div class="upload-zone" id="dropZone">
        <p>Glissez-deposez un fichier PDF ici ou cliquez pour selectionner</p>
        <div class="file-info" id="fileInfo"></div>
        <input type="file" name="fichier_crg" id="fichierCrg" accept="application/pdf" style="display:none">
    </div>

    <div class="form-grid">
        <div class="form-group">
            <label for="id_proprietaire">Proprietaire</label>
            <select name="id_proprietaire" id="id_proprietaire" required>
                <option value="">-- Choisir --</option>
                <?php foreach ($proprietaires as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['nom'] . ' ' . $p['prenom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="annee">Annee</label>
            <input type="number" name="annee" id="annee" min="2000" max="2099" value="<?= date('Y') ?>" required>
        </div>
        <div class="form-group">
            <label for="trimestre">Trimestre</label>
            <select name="trimestre" id="trimestre" required>
                <option value="1">T1 (Jan-Mar)</option>
                <option value="2">T2 (Avr-Jun)</option>
                <option value="3">T3 (Jul-Sep)</option>
                <option value="4">T4 (Oct-Dec)</option>
            </select>
        </div>
    </div>

    <label class="checkbox-group">
        <input type="checkbox" name="confirmer_remplacement" value="1">
        Confirmer le remplacement si un CRG existe deja
    </label>

    <button type="submit" class="btn-upload">Importer le CRG</button>
</form>

<!-- ── History ────────────────────────────────────────────── -->
<?php if (!empty($history)): ?>
<h2 class="section-title" style="margin-top:36px">Historique des imports</h2>
<div class="table-wrap">
<table class="data">
<thead><tr>
    <th>Proprietaire</th><th>Annee</th><th>Trimestre</th><th>Date arrete</th>
    <th>Statut</th><th>Debits</th><th>Credits</th><th>Importe le</th>
</tr></thead>
<tbody>
<?php foreach ($history as $h): ?>
<tr>
    <td><?= e($h['proprio_nom']) ?></td>
    <td><?= (int)$h['annee'] ?></td>
    <td>T<?= (int)$h['trimestre'] ?></td>
    <td><?= $h['date_arrete'] ? e($h['date_arrete']) : '-' ?></td>
    <td><span class="badge badge-<?= e($h['parse_statut']) ?>"><?= e($h['parse_statut']) ?></span></td>
    <td><?= fmt_money($h['total_debits']) ?></td>
    <td><?= fmt_money($h['total_credits']) ?></td>
    <td><?= e(date('d/m/Y H:i', strtotime($h['uploaded_at']))) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</div><!-- .main-content -->
</div><!-- .page-wrap -->

<script>
(function(){
    const zone = document.getElementById('dropZone');
    const input = document.getElementById('fichierCrg');
    const info = document.getElementById('fileInfo');

    zone.addEventListener('click', () => input.click());

    ['dragenter','dragover'].forEach(ev => {
        zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('dragover'); });
    });
    ['dragleave','drop'].forEach(ev => {
        zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('dragover'); });
    });

    zone.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if (files.length) {
            input.files = files;
            showFile(files[0]);
        }
    });

    input.addEventListener('change', () => {
        if (input.files.length) showFile(input.files[0]);
    });

    function showFile(f) {
        const sizeMB = (f.size / 1024 / 1024).toFixed(2);
        info.textContent = f.name + ' (' + sizeMB + ' Mo)';
    }
})();
</script>
</body>
</html>
