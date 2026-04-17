<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$roleId = current_role_id();
if (!in_array($roleId, [1, 2, 3], true)) {
    deny_access('Accès RH restreint.');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Auto-create comment columns if missing
$fields_to_check = ['salaire_brut_base','treizieme_mois','anciennete','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','vehicule_utilise','ik_montant'];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    foreach ($fields_to_check as $f) {
        $colName = "comment_$f";
        if (!in_array($colName, $existing)) {
            $pdo->exec("ALTER TABLE salaires ADD COLUMN `$colName` TEXT NULL DEFAULT NULL");
        }
    }

    if (!in_array('commentaire_general', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN commentaire_general TEXT NULL DEFAULT NULL");
    }
    if (!in_array('commentaire_admin', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN commentaire_admin TEXT NULL DEFAULT NULL");
    }
} catch (Exception $e) {}

// Create documents table if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS salaires_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_salaire INT,
        id_user INT,
        categorie VARCHAR(50),
        filename VARCHAR(255),
        original_name VARCHAR(255),
        file_path VARCHAR(255),
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(id_salaire),
        INDEX(id_user),
        INDEX(categorie)
    )");

    $stmt = $pdo->query("SHOW COLUMNS FROM salaires_documents");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    $requiredCols = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'id_salaire' => 'INT',
        'id_user' => 'INT',
        'categorie' => 'VARCHAR(50)',
        'filename' => 'VARCHAR(255)',
        'original_name' => 'VARCHAR(255)',
        'file_path' => 'VARCHAR(255)',
        'upload_date' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
    ];
    foreach ($requiredCols as $col => $type) {
        if (!in_array($col, $existing)) {
            if ($col !== 'id') {
                $pdo->exec("ALTER TABLE salaires_documents ADD COLUMN `$col` $type");
            }
        }
    }
} catch (Exception $e) {}

// Create uploads directory if missing
$uploadsDir = __DIR__ . '/uploads/salaires';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0777, true);
    @chmod($uploadsDir, 0777);
}

// Categories allowed
$categories_doc = [
    'ik_nb_km' => 'Frais KM',
    'remboursement_achat' => 'Achats',
    'stationnement' => 'Stationnement',
    'frais_professionnels' => 'Frais Professionnels',
    'frais_reception' => 'Frais Réception',
    'commission_ca' => 'Prime CA'
];

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m){ $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[(int)$m]??''; }
function first_day_of($y,$m){ return sprintf('%04d-%02d-01',$y,$m); }
function fmt_val($v, $type){ if($v === null || $v === '') return ''; if($type === 'money') return number_format((float)$v, 2, ',', ''); if($type === 'int') return (string)(int)$v; return (string)$v; }

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$idUser = current_user_id();

// Get selected month or default to current month
$mois_sel = (int)($_GET['mois'] ?? $now->format('n'));
$annee_sel = (int)($_GET['annee'] ?? $now->format('Y'));
$mois_ref = sprintf('%04d-%02d-01', $annee_sel, $mois_sel);
$monthClosed = rh_is_salary_month_closed($pdo, sprintf('%04d-%02d', $annee_sel, $mois_sel));

if ($idUser <= 0) {
    die('id_user manquant');
}

$idUserLegacy = rh_user_salary_id($pdo, $idUser);

// Get user
$stmtUser = $pdo->prepare("SELECT id, prenom, nom, vehicule_nom, vehicule_type, vehicule_immat, vehicule_puissance_fiscale, indemnite_km FROM users WHERE id=?");
$stmtUser->execute([$idUser]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
$nom_complet = $user ? trim(($user['prenom']??'').' '.($user['nom']??'')) : 'N/A';

// Get salary
$stmtSal = $pdo->prepare("SELECT * FROM salaires WHERE (id_user=? OR id_user=?) AND mois_reference=? LIMIT 1");
$stmtSal->execute([$idUser, $idUserLegacy, $mois_ref]);
$sal = $stmtSal->fetch(PDO::FETCH_ASSOC);
if (!$sal) $sal = ['id'=>null, 'id_user'=>$idUserLegacy, 'mois_reference'=>$mois_ref];

// Get all users
$stmtAllUsers = $pdo->query("SELECT id, TRIM(CONCAT_WS(' ', IFNULL(prenom,''), IFNULL(nom,''))) AS nom FROM users WHERE actif=1 ORDER BY nom");
$allUsers = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

// Handle download all documents
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_all_docs'])) {
    $docIds = json_decode($_POST['doc_ids']??'[]', true);
    if (!empty($docIds) && extension_loaded('zip')) {
        $zip = new ZipArchive();
        $zipPath = sys_get_temp_dir() . '/docs_' . uniqid() . '.zip';

        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            foreach ($docIds as $docId) {
                $stmt = $pdo->prepare("SELECT * FROM salaires_documents WHERE id=? AND id_salaire=?");
                $stmt->execute([$docId, $sal['id']??0]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($doc) {
                    $filePath = dirname(__DIR__) . $doc['file_path'];
                    if (file_exists($filePath)) {
                        $zip->addFile($filePath, $doc['original_name']);
                    }
                }
            }
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="documents_salaire.zip"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            @unlink($zipPath);
            exit;
        }
    }
    http_response_code(400); exit('ZIP non disponible');
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc'])) {
    if ($monthClosed) {
        http_response_code(403);
        exit('Mois clôturé');
    }
    $docId = (int)$_POST['delete_doc'];
    $stmtDoc = $pdo->prepare("SELECT * FROM salaires_documents WHERE id=? AND id_salaire=?");
    $stmtDoc->execute([$docId, $sal['id']??0]);
    $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);
    if ($doc) {
        @unlink(__DIR__ . $doc['file_path']);
        $pdo->prepare("DELETE FROM salaires_documents WHERE id=?")->execute([$docId]);
        http_response_code(200); exit;
    }
    http_response_code(400); exit;
}

// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file'])) {
    if ($monthClosed) {
        http_response_code(403);
        exit('Mois clôturé');
    }
    $categorie = $_POST['doc_categorie']??'';

    // Create salary first if needed
    if (!$sal['id']) {
        $i = $pdo->prepare("INSERT INTO salaires (id_user, mois_reference) VALUES (?, ?)");
        try {
            $i->execute([$idUserLegacy, $mois_ref]);
            $sal['id'] = $pdo->lastInsertId();
        } catch (Exception $e) {
            http_response_code(400); exit('Erreur création salaire');
        }
    }

    if (!isset($categories_doc[$categorie]) || !$sal['id']) {
        http_response_code(400); exit('Catégorie invalide');
    }

    $file = $_FILES['doc_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400); exit('Erreur upload: ' . $file['error']);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png','gif','doc','docx'])) {
        http_response_code(400); exit('Format non autorisé');
    }

    $uploadsDir = dirname(__DIR__) . '/../uploads/salaires';
    if (!is_dir($uploadsDir)) {
        if (!@mkdir($uploadsDir, 0777, true)) {
            http_response_code(400); exit('Impossible de créer le dossier');
        }
        @chmod($uploadsDir, 0777);
    }

    $newName = $sal['id'] . '_' . $categorie . '_' . time() . '.' . $ext;
    $uploadPath = $uploadsDir . '/' . $newName;

    if (!@move_uploaded_file($file['tmp_name'], $uploadPath)) {
        http_response_code(400); exit('Impossible de déplacer le fichier');
    }

    @chmod($uploadPath, 0666);

    try {
        $stmt = $pdo->prepare("INSERT INTO salaires_documents (id_salaire, id_user, categorie, filename, original_name, file_path) VALUES (?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([$sal['id'], $idUser, $categorie, $newName, $file['name'], '/uploads/salaires/' . $newName]);
        if ($result) {
            http_response_code(200); exit('OK');
        } else {
            @unlink($uploadPath);
            http_response_code(400); exit('Erreur insertion');
        }
    } catch (Throwable $e) {
        @unlink($uploadPath);
        error_log('Upload error: ' . $e->getMessage());
        http_response_code(400); exit('Erreur: ' . substr($e->getMessage(), 0, 50));
    }
}

// Get documents
$stmtDocs = $pdo->prepare("SELECT * FROM salaires_documents WHERE id_salaire=? ORDER BY categorie, upload_date DESC");
$stmtDocs->execute([$sal['id']??0]);
$docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
$docs_by_cat = [];
foreach ($docs as $doc) {
    $docs_by_cat[$doc['categorie']][] = $doc;
}

// Handle saves
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_field'])) {
    if ($monthClosed) {
        http_response_code(403);
        exit('Mois clôturé');
    }
    $field = $_POST['save_field']??'';
    $value = $_POST['value']??null;
    $allowed = ['salaire_brut_base','treizieme_mois','anciennete','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','vehicule_utilise','ik_montant','commentaire_general','comment_salaire_brut_base','comment_treizieme_mois','comment_anciennete','comment_avantage_nature','comment_heures_supp','comment_commission_ca','comment_commission_ca_nouvelles_affaires','comment_ik_nb_km','comment_total_ik','comment_remboursement_achat','comment_frais_professionnels','comment_frais_reception','comment_prime_admin','comment_prime_exceptionnelle','comment_stationnement','comment_frais_deplacement','comment_vehicule_utilise','comment_ik_montant'];

    if (!in_array($field, $allowed)) { http_response_code(400); exit; }

    // Restrict certain fields for users
    $readOnlyFields = ['salaire_brut_base', 'treizieme_mois', 'anciennete', 'avantage_nature', 'ik_montant'];
    if (in_array($field, $readOnlyFields)) {
        http_response_code(400); exit('Champ non modifiable');
    }

    if (in_array($field, ['heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','ik_montant'])) {
        $value = ($value===''||$value===null) ? null : (float)str_replace(',', '.', $value);
    } elseif ($field==='anciennete') {
        $value = ($value===''||$value===null) ? null : (int)$value;
    } else {
        $value = ($value===''||$value===null) ? null : trim($value);
    }

    // Ensure field exists in table (create if needed)
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM salaires WHERE Field='$field'");
        if ($checkCol->rowCount() === 0) {
            $pdo->exec("ALTER TABLE salaires ADD COLUMN `$field` TEXT NULL");
        }
    } catch (Exception $e) {}

    if ($sal['id']) {
        $u = $pdo->prepare("UPDATE salaires SET `$field`=? WHERE id=?");
        $u->execute([$value, $sal['id']]);
    } else {
        $i = $pdo->prepare("INSERT INTO salaires (id_user, mois_reference, `$field`) VALUES (?, ?, ?)");
        $i->execute([$idUserLegacy, $mois_ref, $value]);
        $sal['id'] = $pdo->lastInsertId();
    }
    http_response_code(200); exit;
}

$sections = [
    'Rémunération de base' => ['salaire_brut_base'=>['label'=>'Salaire brut','type'=>'money'],'treizieme_mois'=>['label'=>'13e mois','type'=>'money'],'anciennete'=>['label'=>'Ancienneté (%)','type'=>'int']],
    'Avantages & Indemnités' => ['avantage_nature'=>['label'=>'Avantage nature','type'=>'money'],'stationnement'=>['label'=>'Stationnement','type'=>'money'],'remboursement_achat'=>['label'=>'Remboursement achat','type'=>'money'],'frais_professionnels'=>['label'=>'Frais professionnels','type'=>'money'],'frais_reception'=>['label'=>'Frais réception','type'=>'money'],'frais_deplacement'=>['label'=>'Frais déplacement','type'=>'money']],
    'Primes & Commissions' => ['heures_supp'=>['label'=>'Heures supp','type'=>'money'],'commission_ca'=>['label'=>'Commission CA','type'=>'money'],'commission_ca_nouvelles_affaires'=>['label'=>'Commission NA','type'=>'money'],'prime_admin'=>['label'=>'Prime admin','type'=>'money'],'prime_exceptionnelle'=>['label'=>'Prime excep','type'=>'money']],
    'Indemnités Kilométriques' => ['ik_nb_km'=>['label'=>'Nombre km','type'=>'money'],'total_ik'=>['label'=>'Total IK','type'=>'money'],'vehicule_utilise'=>['label'=>'Véhicule','type'=>'text'],'ik_montant'=>['label'=>'IK montant (€)','type'=>'money']],
];

// ── Layout variables ─────────────────────────────────────────────────────
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Détail Salaire — ' . h($_userName);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis    = '';
$layout_head_actions = '';

$_monthClosedJs = $monthClosed ? 'true' : 'false';
$_docsJson = json_encode($docs);

$layout_extra_css = <<<'EXTRACSS'
<style>
    :root{--bg-primary:#ffffff !important;--bg-secondary:#f7f8fa !important;--bg-soft:#ffffff !important;--ink:#1a1816 !important;--muted:#8a8680 !important;--accent:#4878a6 !important;--stroke:rgba(196,192,186,0.25) !important;--shadow-dark:#d4d7de !important;--shadow-light:#ffffff !important}
    .container{max-width:1000px;margin:0 auto}
    .back-link{color:#4878a6;text-decoration:none;font-size:12px;margin-bottom:16px;display:inline-block}
    .header{background:#ffffff;border:1px solid #e4e6ec;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:4px 4px 12px #d4d7de,-4px -4px 12px #fff}
    .header-title{font-size:28px;font-weight:800;color:#8a5040;margin-bottom:8px}
    .header-info{display:flex;gap:30px;font-size:12px;color:var(--muted);margin-top:12px}
    .header-info div{display:flex;gap:6px}
    .header-info strong{color:var(--ink);font-family:monospace}
    .filters{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;padding:16px;background:#f7f8fa;border-radius:10px}
    .filter-group{display:flex;flex-direction:column;gap:4px}
    .filter-group label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted)}
    .filter-group select{padding:8px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-size:13px;font-family:inherit}
    .nav{display:flex;gap:10px;margin-bottom:20px}
    .nav-btn{padding:8px 14px;background:rgba(72,120,166,0.08);border:1px solid rgba(72,120,166,0.2);color:var(--accent);border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;text-decoration:none}
    .nav-btn:hover:not(:disabled){background:rgba(72,120,166,0.15)}
    .nav-btn:disabled{opacity:0.4;cursor:not-allowed}
    .section{background:#ffffff;border:1px solid var(--stroke);border-radius:10px;margin-bottom:16px;overflow:hidden}
    .section-title{background:#f7f8fa;padding:10px 16px;border-bottom:1px solid var(--stroke);font-size:12px;font-weight:700;text-transform:uppercase;color:var(--accent)}
    .section-content{padding:14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
    .form-group{display:flex;flex-direction:column;gap:3px}
    .form-group label{font-size:11px;font-weight:600;color:var(--muted)}
    .form-group input{padding:7px 9px;background:#ffffff;border:1px solid var(--stroke);border-radius:5px;color:var(--ink);font-family:inherit;font-size:12px}
    .form-group input:focus{outline:none;border-color:var(--accent)}
    .form-group textarea{padding:7px 9px;background:#ffffff;border:1px solid var(--stroke);border-radius:5px;color:var(--ink);font-family:inherit;font-size:11px;resize:none;height:50px;margin-top:4px}
    .form-group textarea:focus{outline:none;border-color:var(--accent)}
    .form-comment{font-size:10px;color:var(--muted);margin-top:6px}
    .save-status{font-size:9px;color:#3a7a6a;margin-top:1px;display:none}
    .save-status.show{display:block}
    .section-header{display:flex;justify-content:space-between;align-items:center}
    .upload-btn{padding:4px 8px;background:rgba(72,120,166,0.1);border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:4px;cursor:pointer;font-size:10px;font-weight:600}
    .upload-btn:hover{background:rgba(72,120,166,0.2)}
    /* Modal : décalée de la largeur sidebar (220px) pour être centrée
       sur la zone contenu visible, pas sur le viewport entier. */
    .modal{display:none;position:fixed;top:0;left:220px;width:calc(100% - 220px);height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center}
    .modal.show{display:flex}
    @media (max-width: 768px) {
        .modal{left:0;width:100%}
    }
    .modal-content{background:var(--bg-soft);border:1px solid var(--stroke);border-radius:12px;padding:20px;max-width:400px;width:90%}
    .modal-title{font-size:14px;font-weight:700;color:var(--ink);margin-bottom:16px}
    .modal-close{position:absolute;top:10px;right:10px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:20px}
    .modal-form{display:flex;flex-direction:column;gap:12px}
    .modal-form label{font-size:11px;font-weight:600;color:var(--muted)}
    .modal-form input,.modal-form select{padding:8px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:var(--ink);font-family:inherit}
    .modal-form button{padding:8px;background:rgba(72,120,166,0.1);border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:6px;cursor:pointer;font-weight:600}
    .docs-list{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
    .doc-badge{padding:4px 8px;background:rgba(72,120,166,0.08);border:1px solid rgba(72,120,166,0.2);border-radius:4px;font-size:10px;color:var(--accent);display:flex;gap:4px;align-items:center}
    .doc-link{color:var(--accent);text-decoration:none;cursor:pointer}
    .doc-link:hover{text-decoration:underline}
    .dropzone{border:2px dashed var(--stroke);border-radius:6px;padding:20px;text-align:center;cursor:pointer;transition:all 0.2s;background:rgba(72,120,166,0.04)}
    .dropzone:hover{background:#f7f8fa;border-color:var(--accent)}
    .dropzone p{font-size:12px;color:var(--muted);margin:0}
    .dropzone strong{color:var(--accent)}
    @media(max-width:900px){.filters{grid-template-columns:1fr}.section-content{grid-template-columns:1fr}.section-header{flex-direction:column;align-items:flex-start}}
</style>
EXTRACSS;

$layout_extra_js = <<<EXTRAJS
<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const MONTH_LOCKED = {$_monthClosedJs};
function appendCsrf(formData) {
    if (CSRF_TOKEN) {
        formData.append('csrf_token', CSRF_TOKEN);
    }
    return formData;
}
let saveTimeout = {};
function autoSaveField(fieldName, value) {
    if (MONTH_LOCKED) { return; }
    clearTimeout(saveTimeout[fieldName]);
    const statusEl = document.getElementById('status-' + fieldName);
    if (statusEl) {
        statusEl.textContent = 'Enreg...';
        statusEl.classList.add('show');
    }
    saveTimeout[fieldName] = setTimeout(() => {
        const formData = new FormData();
        formData.append('save_field', fieldName);
        appendCsrf(formData);
        formData.append('value', value);
        fetch(window.location.pathname + window.location.search, {method:'POST', body:formData})
        .then(r => {
            if (statusEl) {
                statusEl.textContent = r.ok ? '\u2713' : '\u2717';
                setTimeout(() => {statusEl.classList.remove('show');}, 1500);
            }
        })
        .catch(e => {
            if (statusEl) statusEl.textContent = '\u2717';
        });
    }, 500);
}
function changeFilters() {
    const m = String(document.getElementById('f-mois').value).padStart(2, '0');
    const a = document.getElementById('f-annee').value;
    const u = document.getElementById('f-user').value;
    const moisRef = a + '-' + m + '-01';
    window.location = '?id_user=' + u + '&mois_ref=' + moisRef;
}
function autoResizeTextarea(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 300) + 'px';
}
function openUploadModal(categorie) {
    document.getElementById('modal-' + categorie).classList.add('show');
    setTimeout(() => setupDragDrop(categorie), 100);
}
function closeUploadModal(categorie) {
    document.getElementById('modal-' + categorie).classList.remove('show');
}
function uploadDoc(categorie) {
    if (MONTH_LOCKED) { alert('Mois clôturé'); return; }
    const fileInput = document.getElementById('file-' + categorie);
    if (!fileInput.files.length) {
        alert('Sélectionne un fichier');
        return;
    }
    const formData = new FormData();
    formData.append('doc_file', fileInput.files[0]);
    formData.append('doc_categorie', categorie);
    appendCsrf(formData);
    fetch(window.location.pathname + window.location.search, {method:'POST', body:formData})
    .then(r => r.text().then(text => ({ok: r.ok, text})))
    .then(({ok, text}) => {
        if (ok) {
            closeUploadModal(categorie);
            location.reload();
        } else {
            alert('Erreur upload: ' + text);
        }
    })
    .catch(e => alert('Erreur: ' + e.message));
}
function deleteDoc(docId) {
    if (MONTH_LOCKED) { alert('Mois clôturé'); return; }
    if (confirm('Supprimer ce document?')) {
        const formData = new FormData();
        formData.append('delete_doc', docId);
        appendCsrf(formData);
        fetch(window.location.pathname + window.location.search, {method:'POST', body:formData})
        .then(r => r.ok ? location.reload() : alert('Erreur'));
    }
}
function setupDragDrop(categorie) {
    const dropZone = document.getElementById('dropzone-' + categorie);
    const fileInput = document.getElementById('file-' + categorie);
    if (!dropZone) return;
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            dropZone.innerHTML = '<p style="color:var(--accent)"><strong>\u2713 ' + this.files[0].name + '</strong></p>';
        }
    });
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, function(e) { e.preventDefault(); e.stopPropagation(); }, false);
    });
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, function() {
            dropZone.style.background = 'rgba(72,120,166,0.08)';
            dropZone.style.borderColor = 'var(--accent)';
        }, false);
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, function() {
            dropZone.style.background = '';
            dropZone.style.borderColor = '';
        }, false);
    });
    dropZone.addEventListener('drop', function(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        fileInput.files = files;
        if (files.length > 0) {
            dropZone.innerHTML = '<p style="color:var(--accent)"><strong>\u2713 ' + files[0].name + '</strong></p>';
        }
    }, false);
}
function printDoc(filePath) {
    const win = window.open(filePath, '_blank');
    if (win) { win.onload = function() { win.print(); }; }
    else { alert('Ouvrir le fichier pour imprimer...\\n' + filePath); }
}
function transferDoc(fileName) {
    const email = prompt('Adresse email pour transférer le fichier:\\n' + fileName);
    if (email) { alert('Fonction de transfert en développement.\\nFichier: ' + fileName + '\\nEmail: ' + email); }
}
function downloadAllDocs() {
    const docs = {$_docsJson};
    if (!docs || docs.length === 0) { alert('Aucun document à télécharger'); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    if (CSRF_TOKEN) {
        const csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = CSRF_TOKEN;
        form.appendChild(csrf);
    }
    form.style.display = 'none';
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'download_all_docs'; input.value = '1';
    form.appendChild(input);
    const docIds = document.createElement('input');
    docIds.type = 'hidden'; docIds.name = 'doc_ids'; docIds.value = JSON.stringify(docs.map(d => d.id));
    form.appendChild(docIds);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.auto-resize-textarea').forEach(textarea => {
        autoResizeTextarea(textarea);
    });
});
</script>
EXTRAJS;

ob_start();
?>
            <div class="container">
                <?php $backUrl = ($roleId === 1 || $roleId === 2) ? 'rh_salaires.php' : 'rh_salaires_user_list.php'; ?>
                <a href="<?= h($backUrl) ?>" class="back-link">&larr; Retour salaires</a>

        <div class="header">
            <div class="header-title"><?=h($nom_complet)?></div>
            <div class="header-info" style="flex-wrap:wrap">
                <div><span>ID User:</span><strong><?=$idUser?></strong></div>
                <div><span>ID Salaire:</span><strong><?=$sal['id']??'À créer'?></strong></div>
<?php if (!empty($user['vehicule_nom']) || !empty($user['vehicule_type'])): ?>
<div><span>Véhicule:</span><strong><?= h(trim(($user['vehicule_nom'] ?? '') . ' ' . ($user['vehicule_type'] ?? ''))) ?></strong></div>
<?php endif; ?>
<?php if (!empty($user['vehicule_immat'])): ?>
<div><span>Immatriculation:</span><strong><?= h($user['vehicule_immat']) ?></strong></div>
<?php endif; ?>
<?php if (!empty($user['indemnite_km'])): ?>
<div><span>IK &euro;/km:</span><strong><?= h(number_format((float)$user['indemnite_km'], 4, ',', '')) ?></strong></div>
<?php endif; ?>
                <div style="display:flex;align-items:center;gap:12px;min-width:300px">
                    <div style="display:flex;flex-direction:column;gap:4px"><label style="font-size:10px;font-weight:600;text-transform:uppercase;color:var(--muted)">Mois</label><select data-mois-select onchange="location.href='?mois='+this.value+'&annee='+document.querySelector('[data-annee-select]').value" style="padding:6px 8px;background:#ffffff;border:1px solid var(--stroke);border-radius:4px;color:var(--ink);font-size:11px"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=($m==$mois_sel?'selected':'')?>><?=mois_fr($m)?></option><?php endfor;?></select></div>
                    <div style="display:flex;flex-direction:column;gap:4px"><label style="font-size:10px;font-weight:600;text-transform:uppercase;color:var(--muted)">Année</label><select data-annee-select onchange="location.href='?mois='+document.querySelector('[data-mois-select]').value+'&annee='+this.value" style="padding:6px 8px;background:#ffffff;border:1px solid var(--stroke);border-radius:4px;color:var(--ink);font-size:11px"><?php $y=(int)$now->format('Y');for($a=$y+1;$a>=$y-2;$a--):?><option value="<?=$a?>" <?=($a==$annee_sel?'selected':'')?>><?=$a?></option><?php endfor;?></select></div>
                    <?php $nextMonth=$mois_sel+1;$nextYear=$annee_sel;if($nextMonth>12){$nextMonth=1;$nextYear++;}?><button onclick="location.href='?mois=<?=$nextMonth?>&annee=<?=$nextYear?>'" style="padding:6px 12px;background:rgba(124,245,214,0.2);border:1px solid rgba(124,245,214,0.4);color:#3a7a6a;border-radius:4px;cursor:pointer;font-weight:600;font-size:11px;align-self:flex-end">+ Nouveau (<?=mois_fr($nextMonth)?> <?=$nextYear?>)</button>
                </div>
            </div>
            <?php
            $returnUrl = urlencode('rh_salaires_user.php?mois=' . $mois_sel . '&annee=' . $annee_sel);
            $docsUrl = 'rh_documents.php?user_id=' . $idUser . '&mois=' . $mois_sel . '&annee=' . $annee_sel . '&return_to=' . $returnUrl;
            ?>
            <div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <a href="<?=h($docsUrl)?>"
                   style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:rgba(124,245,214,0.15);border:1px solid rgba(124,245,214,0.35);border-radius:8px;color:#3a7a6a;font-size:13px;font-weight:700;text-decoration:none;transition:all 0.18s"
                   onmouseover="this.style.background='rgba(124,245,214,0.25)'"
                   onmouseout="this.style.background='rgba(124,245,214,0.15)'">
                    Gérer les documents<?php if (count($docs) > 0): ?> <span style="background:rgba(124,245,214,0.3);border-radius:10px;padding:1px 7px;font-size:11px"><?=count($docs)?></span><?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Commentaire Général -->
        <div style="display:grid;grid-template-columns:1fr;gap:16px;margin-bottom:20px">
            <div style="background:#ffffff;border:1px solid var(--stroke);border-radius:10px;padding:16px;overflow:hidden">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--accent);margin-bottom:12px">Commentaire Général</div>
                <textarea class="auto-resize-textarea" id="f-commentaire-general" placeholder="Ajouter un commentaire général sur ce salaire..." onchange="autoSaveField('commentaire_general', this.value)" oninput="autoResizeTextarea(this)" style="width:100%;height:auto;min-height:50px;max-height:300px;padding:8px;background:#ffffff;border:1px solid var(--stroke);border-radius:6px;color:#d0e4ff;font-family:inherit;font-size:13px;line-height:1.5;resize:none;overflow:hidden"><?=h($sal['commentaire_general']??'')?></textarea>
                <div class="save-status" id="status-commentaire_general" style="margin-top:6px;font-size:11px"></div>
            </div>
        </div>

        <div class="filters">
            <div class="filter-group">
                <label for="f-user">Utilisateur</label>
                <select id="f-user" onchange="changeFilters()">
                    <?php foreach($allUsers as $u): ?>
                        <option value="<?=$u['id']?>" <?=($u['id']==$idUser?'selected':'')?>><?=h($u['nom'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="f-mois">Mois</label>
                <select id="f-mois" onchange="changeFilters()">
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?=$m?>" <?=($m==$mois_sel?'selected':'')?>><?=mois_fr($m)?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="f-annee">Année</label>
                <select id="f-annee" onchange="changeFilters()">
                    <?php $y=(int)$now->format('Y'); for($a=$y+1;$a>=$y-5;$a--): ?>
                        <option value="<?=$a?>" <?=($a==$annee_sel?'selected':'')?>><?=$a?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <?php foreach($sections as $title => $fields):
            $hasUpload = false;
            foreach ($fields as $fname => $meta) {
                if (isset($categories_doc[$fname])) {
                    $hasUpload = true;
                    break;
                }
            }
        ?>
            <div class="section">
                <div class="section-title">
                    <div class="section-header">
                        <span><?=$title?></span>
                        <?php if ($hasUpload): ?>
                            <div class="docs-list">
                                <?php foreach ($fields as $fname => $meta) {
                                    if (isset($categories_doc[$fname])) {
                                        echo '<button class="upload-btn" onclick="openUploadModal(\''.$fname.'\')">'. $categories_doc[$fname].'</button>';
                                    }
                                } ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="section-content">
                    <?php foreach($fields as $fname => $meta): ?>
                        <div class="form-group">
                            <label for="f-<?=$fname?>"><?=$meta['label']?></label>
                            <input type="text" id="f-<?=$fname?>" value="<?=h(fmt_val($sal[$fname]??null, $meta['type']))?>" placeholder="<?=$meta['type']==='money'?'0,00':'0'?>" onchange="autoSaveField('<?=$fname?>', this.value)" <?=in_array($fname, ['salaire_brut_base', 'treizieme_mois', 'anciennete', 'avantage_nature', 'ik_montant'])?'disabled':'';?>>
                            <textarea id="f-comment-<?=$fname?>" placeholder="Ajouter un commentaire..." onchange="autoSaveField('comment_<?=$fname?>', this.value)"><?=h($sal['comment_'.$fname]??'')?></textarea>
                            <div class="save-status" id="status-<?=$fname?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
            </div>

    <!-- Upload Modals -->
    <?php foreach ($categories_doc as $fieldName => $label): ?>
        <div class="modal" id="modal-<?=$fieldName?>">
            <div class="modal-content">
                <button class="modal-close" onclick="closeUploadModal('<?=$fieldName?>')">&#10005;</button>
                <div class="modal-title"><?=$label?></div>
                <div class="modal-form">
                    <div>
                        <label>Utilisateur</label>
                        <input type="text" disabled value="<?=h($nom_complet)?>">
                    </div>
                    <div>
                        <label>Salaire ID</label>
                        <input type="text" disabled value="<?=$sal['id']??'À créer'?>">
                    </div>
                    <div>
                        <label>Fichier</label>
                        <div class="dropzone" id="dropzone-<?=$fieldName?>" onclick="document.getElementById('file-<?=$fieldName?>').click()">
                            <p><strong>Glisse un fichier ici</strong></p>
                            <p style="font-size:11px;margin-top:6px">ou clique pour sélectionner</p>
                        </div>
                        <input type="file" id="file-<?=$fieldName?>" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx" style="display:none">
                    </div>
                    <div style="display:flex;gap:8px">
                        <button onclick="uploadDoc('<?=$fieldName?>')" style="flex:1">Télécharger</button>
                        <button onclick="closeUploadModal('<?=$fieldName?>')" style="flex:1;background:rgba(255,100,130,0.2);color:#ffc1cc;border:1px solid rgba(255,100,130,0.4)">Annuler</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
