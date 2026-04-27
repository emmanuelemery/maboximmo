<?php
/**
 * rh_salaire_detail.php — Détail Salaire RH (migré layout_maboximmo.php)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$roleId = current_role_id();
if (!in_array($roleId, [1, 2, 3], true)) {
    deny_access('Accès RH restreint.');
}

$agenceScope    = can_manage_salaires_agence();
$adminOnlyFields = ['salaire_brut_base', 'treizieme_mois', 'anciennete'];

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Auto-create comment columns if missing
$fields_to_check = ['salaire_brut_base','treizieme_mois','anciennete','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','vehicule_utilise','ik_montant','salaire_modele'];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    // Create comment columns for each field
    foreach ($fields_to_check as $f) {
        $colName = "comment_$f";
        if (!in_array($colName, $existing)) {
            $pdo->exec("ALTER TABLE salaires ADD COLUMN `$colName` TEXT NULL DEFAULT NULL");
        }
    }

    // Create general and admin comment columns
    if (!in_array('commentaire_general', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN commentaire_general TEXT NULL DEFAULT NULL");
    }
    if (!in_array('commentaire_admin', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN commentaire_admin TEXT NULL DEFAULT NULL");
    }
    if (!in_array('salaire_modele', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN salaire_modele TINYINT(1) NOT NULL DEFAULT 0");
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

    // Add missing columns if needed
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
    'remboursement_achat' => 'Achats',
    'stationnement' => 'Stationnement',
    'frais_professionnels' => 'Frais Professionnels',
    'frais_reception' => 'Frais Réception',
];

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m){ $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[(int)$m]??''; }
function first_day_of($y,$m){ return sprintf('%04d-%02d-01',$y,$m); }
function fmt_val($v, $type){ if($v === null || $v === '') return ''; if($type === 'money') return number_format((float)$v, 2, ',', ''); if($type === 'int') return (string)(int)$v; return (string)$v; }

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$idUser = (int)($_GET['id_user'] ?? 0);

if (!isset($_GET['mois_ref']) || !$_GET['mois_ref']) {
    die('mois_ref manquant');
}

$mois_ref = $_GET['mois_ref'];
$dt = DateTime::createFromFormat('Y-m-d', $mois_ref);
if (!$dt) {
    die('mois_ref invalide');
}
$mois_sel = (int)$dt->format('n');
$annee_sel = (int)$dt->format('Y');
 $monthClosed = rh_is_salary_month_closed($pdo, sprintf('%04d-%02d', $annee_sel, $mois_sel));

if ($idUser <= 0) {
    die('id_user manquant');
}

// Sécurité stricte : chaque user ne voit que son propre salaire,
// sauf admin (role 1) et sauf users avec gestion_salaires=1 (agenceScope>0)
// qui peuvent voir les salaires de leur agence uniquement.
$isSelf = ($idUser === current_user_id());
if ($roleId !== 1 && !$isSelf) {
    if ($agenceScope > 0) {
        // User avec gestion_salaires (ex : Géraldine Chaponost)
        // → target user doit appartenir à la même agence
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
        $chk->execute([$idUser, $agenceScope]);
        if (!$chk->fetch()) deny_access('Accès refusé : ce salarié n\'appartient pas à votre agence.');
    } else {
        // Aucun droit : on ne voit que son propre salaire
        deny_access('Accès refusé : vous ne pouvez consulter que votre propre fiche.');
    }
}

$idUserLegacy = rh_user_salary_id($pdo, $idUser);

// Get user
$stmtUser = $pdo->prepare("SELECT id, prenom, nom, id_societe, id_agence, vehicule_nom, vehicule_type, vehicule_immat, vehicule_puissance_fiscale, indemnite_km FROM users WHERE id=?");
$stmtUser->execute([$idUser]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
$nom_complet = $user ? trim(($user['prenom']??'').' '.($user['nom']??'')) : 'N/A';
$userSociete = $user['id_societe'] ?? null;
$userAgence = $user['id_agence'] ?? null;

// Get salary
$stmtSal = $pdo->prepare("SELECT * FROM salaires WHERE (id_user=? OR id_user=?) AND mois_reference=? LIMIT 1");
$stmtSal->execute([$idUser, $idUserLegacy, $mois_ref]);
$sal = $stmtSal->fetch(PDO::FETCH_ASSOC);
if (!$sal) $sal = ['id'=>null, 'id_user'=>$idUserLegacy, 'mois_reference'=>$mois_ref];
// Injecter l'immatriculation depuis le profil user (lecture seule)
$sal['vehicule_immat'] = $user['vehicule_immat'] ?? '';

// Get current user info for navigation
$currentUserId = current_user_id();
$currentUserAgencyId = null;
if ($roleId === 2) { // Manager
    $stmtCurrentUser = $pdo->prepare("SELECT id_agence FROM users WHERE id=?");
    $stmtCurrentUser->execute([$currentUserId]);
    $currentUserData = $stmtCurrentUser->fetch(PDO::FETCH_ASSOC);
    $currentUserAgencyId = $currentUserData['id_agence'] ?? null;
}

// Get users based on role
if ($roleId === 1) { // Admin: all users
    $stmtAllUsers = $pdo->query("SELECT id, TRIM(CONCAT_WS(' ', IFNULL(prenom,''), IFNULL(nom,''))) AS nom FROM users WHERE actif=1 ORDER BY nom");
} elseif ($roleId === 2) { // Manager: only users from their agency
    $stmtAllUsers = $pdo->prepare("SELECT id, TRIM(CONCAT_WS(' ', IFNULL(prenom,''), IFNULL(nom,''))) AS nom FROM users WHERE actif=1 AND id_agence=? ORDER BY nom");
    $stmtAllUsers->execute([$currentUserAgencyId]);
} else { // Employee: can't navigate
    $stmtAllUsers = $pdo->prepare("SELECT id, TRIM(CONCAT_WS(' ', IFNULL(prenom,''), IFNULL(nom,''))) AS nom FROM users WHERE id=? ORDER BY nom");
    $stmtAllUsers->execute([$idUser]);
}
$allUsers = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);

// Vérification stricte des droits d'accès
if ($roleId === 3 && $idUser !== $currentUserId) {
    deny_access('Accès refusé : vous ne pouvez consulter que votre propre fiche.');
}
if ($roleId === 2 && $currentUserAgencyId !== null && $userAgence !== null && (int)$userAgence !== (int)$currentUserAgencyId) {
    deny_access('Accès refusé : cet employé n\'appartient pas à votre agence.');
}

// Handle download all documents
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['download_all_docs'])) {
    verify_csrf();
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
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_doc'])) {
    verify_csrf();    if ($monthClosed) {
        http_response_code(403);
        exit('Mois clôturé');
    }
    $docId = (int)$_POST['delete_doc'];
    $stmtDoc = $pdo->prepare("SELECT * FROM salaires_documents WHERE id=? AND id_salaire=?");
    $stmtDoc->execute([$docId, $sal['id']??0]);
    $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);
    if ($doc) {
        $uploadsBase = realpath(__DIR__ . '/uploads');
        $resolvedPath = realpath(__DIR__ . $doc['file_path']);
        if ($resolvedPath && $uploadsBase && strpos($resolvedPath, $uploadsBase . DIRECTORY_SEPARATOR) === 0) {
            @unlink($resolvedPath);
        }
        $pdo->prepare("DELETE FROM salaires_documents WHERE id=?")->execute([$docId]);
        http_response_code(200); exit;
    }
    http_response_code(400); exit;
}

// Handle file uploads
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['doc_file'])) {
    verify_csrf();    if ($monthClosed) {
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
    // Validate real MIME type
    $allowedMimes = ['application/pdf','image/jpeg','image/png','image/gif','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($realMime, $allowedMimes)) {
        http_response_code(400); exit('Type de fichier non autorisé');
    }

    $uploadsDir = __DIR__ . '/uploads/salaires';
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

// Get documents — filtrés sur l'user + mois/année (+ id_salaire si la fiche existe)
$salId = (int)($sal['id'] ?? 0);
if ($salId > 0) {
    // Fiche salaire existante : on filtre sur id_salaire ET id_user pour être sûr
    $stmtDocs = $pdo->prepare("
        SELECT * FROM salaires_documents
        WHERE id_salaire = ? AND id_user = ?
        ORDER BY categorie, upload_date DESC
    ");
    $stmtDocs->execute([$salId, $idUser]);
} else {
    // Pas encore de fiche : on filtre sur user + mois + année
    $stmtDocs = $pdo->prepare("
        SELECT * FROM salaires_documents
        WHERE id_user = ?
          AND YEAR(upload_date)  = ?
          AND MONTH(upload_date) = ?
        ORDER BY categorie, upload_date DESC
    ");
    $stmtDocs->execute([$idUser, $annee_sel, $mois_sel]);
}
$docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
$docs_by_cat = [];
foreach ($docs as $doc) {
    $docs_by_cat[$doc['categorie']][] = $doc;
}

// Handle saves
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_modele'])) {
    verify_csrf();
    if ($roleId !== 1) { http_response_code(403); exit('Réservé admin'); }
    $val = $_POST['toggle_modele'] === '1' ? 1 : 0;
    if ($sal['id']) {
        $pdo->prepare("UPDATE salaires SET salaire_modele=? WHERE id=?")->execute([$val, $sal['id']]);
    } else {
        // Créer le salaire si inexistant
        $pdo->prepare("INSERT INTO salaires (id_user, mois_reference, salaire_modele) VALUES (?,?,?)")->execute([$idUserLegacy, $mois_ref, $val]);
    }
    http_response_code(200); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_field'])) {
    verify_csrf();
    if ($monthClosed) {
        http_response_code(403);
        exit('Mois clôturé');
    }
    $field = $_POST['save_field']??'';
    $value = $_POST['value']??null;

    // Champs réservés à l'admin
    $adminOnlyFields = ['salaire_brut_base', 'treizieme_mois', 'anciennete'];
    if (can_manage_salaires_agence() > 0 && in_array($field, $adminOnlyFields, true)) {
        http_response_code(403);
        exit('Modification réservée à l\'administrateur');
    }

    $allowed = ['salaire_brut_base','treizieme_mois','anciennete','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','vehicule_utilise','ik_montant','comment_salaire_brut_base','comment_treizieme_mois','comment_anciennete','comment_avantage_nature','comment_heures_supp','comment_commission_ca','comment_commission_ca_nouvelles_affaires','comment_ik_nb_km','comment_total_ik','comment_remboursement_achat','comment_frais_professionnels','comment_frais_reception','comment_prime_admin','comment_prime_exceptionnelle','comment_stationnement','comment_frais_deplacement','comment_vehicule_utilise','comment_ik_montant','commentaire_general'];

    // Add commentaire_admin only for admins
    if ($roleId === 1) {
        $allowed[] = 'commentaire_admin';
    }

    if (!in_array($field, $allowed)) { http_response_code(400); exit; }

    if (in_array($field, ['salaire_brut_base','treizieme_mois','avantage_nature','heures_supp','commission_ca','commission_ca_nouvelles_affaires','ik_nb_km','total_ik','remboursement_achat','frais_professionnels','frais_reception','prime_admin','prime_exceptionnelle','stationnement','frais_deplacement','ik_montant'])) {
        $value = ($value===''||$value===null) ? null : (float)str_replace(',', '.', $value);
    } elseif ($field==='anciennete') {
        $value = ($value===''||$value===null) ? null : (int)$value;
    } else {
        $value = ($value===''||$value===null) ? null : trim($value);
    }

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
    'Rémunération de base' => ['salaire_brut_base'=>['label'=>'Salaire brut','type'=>'money'],'treizieme_mois'=>['label'=>'13e mois','type'=>'money'],'anciennete'=>['label'=>'Ancienneté (%)','type'=>'int'],'avantage_nature'=>['label'=>'Avantage nature','type'=>'money'],'heures_supp'=>['label'=>'Heures supplémentaires','type'=>'money'],'prime_admin'=>['label'=>'Prime administrative','type'=>'money'],'prime_exceptionnelle'=>['label'=>'Prime exceptionnelle','type'=>'money']],
    'Achats & Frais' => ['stationnement'=>['label'=>'Stationnement','type'=>'money'],'remboursement_achat'=>['label'=>'Remboursement achat','type'=>'money'],'frais_professionnels'=>['label'=>'Frais professionnels','type'=>'money'],'frais_reception'=>['label'=>'Frais réception','type'=>'money'],'frais_deplacement'=>['label'=>'Frais déplacement','type'=>'money']],
    'Primes & Commissions' => ['commission_ca'=>['label'=>'Commission CA','type'=>'money'],'commission_ca_nouvelles_affaires'=>['label'=>'Commission NA','type'=>'money']],
    'Indemnités Kilométriques' => ['ik_nb_km'=>['label'=>'Nombre km','type'=>'money','readonly'=>true],'total_ik'=>['label'=>'Total IK','type'=>'money','readonly'=>true],'ik_montant'=>['label'=>'IK montant (€)','type'=>'money','readonly'=>true],'vehicule_immat'=>['label'=>'Immatriculation','type'=>'text','readonly'=>true]],
];

// Calcul du total brut pour la topbar
$moneyFields = ['salaire_brut_base','treizieme_mois','avantage_nature','stationnement',
    'remboursement_achat','frais_professionnels','frais_reception','frais_deplacement',
    'heures_supp','commission_ca','commission_ca_nouvelles_affaires','prime_admin',
    'prime_exceptionnelle','ik_montant','total_ik'];
$kpiBase   = (float)($sal['salaire_brut_base']??0) + (float)($sal['treizieme_mois']??0);
$kpiAvant  = (float)($sal['avantage_nature']??0) + (float)($sal['stationnement']??0)
           + (float)($sal['remboursement_achat']??0) + (float)($sal['frais_professionnels']??0)
           + (float)($sal['frais_reception']??0) + (float)($sal['frais_deplacement']??0);
$kpiPrimes = (float)($sal['heures_supp']??0) + (float)($sal['commission_ca']??0)
           + (float)($sal['commission_ca_nouvelles_affaires']??0) + (float)($sal['prime_admin']??0)
           + (float)($sal['prime_exceptionnelle']??0);
$kpiIK     = (float)($sal['ik_montant']??0) + (float)($sal['total_ik']??0);
$kpiTotal  = $kpiBase + $kpiAvant + $kpiPrimes + $kpiIK;

// Libellé période pour les modals
$mois_noms = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$periode_label = ($mois_ref === '0000-00-00') ? 'Modèle' : ($mois_noms[$mois_sel] . ' ' . $annee_sel);

// Pills années : n-2, n-1, n
$nowYear = (int)$now->format('Y');
$pillYears = [$nowYear-2, $nowYear-1, $nowYear];

// Pills mois : m-1, m courant, m+1 (mois en cours toujours encadré)
$curMois = (int)$now->format('n');
$pillMonths = [];
for ($pm = $curMois - 1; $pm <= $curMois + 1; $pm++) {
    $m = $pm;
    if ($m <= 0)  $m += 12;
    if ($m > 12)  $m -= 12;
    $pillMonths[] = $m;
}

// Navigation user
$currentIndex = array_search($idUser, array_column($allUsers, 'id'));
$prevUser = $currentIndex > 0 ? $allUsers[$currentIndex - 1] : null;
$nextUser = $currentIndex < count($allUsers) - 1 ? $allUsers[$currentIndex + 1] : null;

/* ═══════════════════════════════════════════════════════════════════════
   LAYOUT VARIABLES
   ═══════════════════════════════════════════════════════════════════════ */

$layout_title   = 'Détail Salaire';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#2f587d">'.number_format($kpiBase,0,',',' ').'</div><div class="ph-kpi-lbl">Base</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.number_format($kpiAvant,0,',',' ').'</div><div class="ph-kpi-lbl">Avantages</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.number_format($kpiPrimes,0,',',' ').'</div><div class="ph-kpi-lbl">Primes</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.number_format($kpiIK,0,',',' ').'</div><div class="ph-kpi-lbl">IK</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.h($nom_complet).'</div><div class="ph-kpi-lbl">Collaborateur</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#2f587d;font-weight:700">'.number_format($kpiTotal,0,',',' ').'</div><div class="ph-kpi-lbl">Total brut</div></div>
';

$layout_head_actions = '
    <a href="rh_user.php?highlight_user='.$idUser.'" class="ph-btn" title="Fiche collaborateur">Fiche</a>
    <a href="rh_user_historiq.php?user_id='.$idUser.'" class="ph-btn" title="Historique">Historiq.</a>
    <a href="rh_salaires.php" class="ph-btn primary" title="Retour liste">Liste</a>
    <span class="ph-btn dispo">attente</span>
';

$_csrf_token_for_js = h(csrf_token());

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ═══════════════════════════════════════════════════════
       rh_salaire_detail — Styles page-spécifiques
    ═══════════════════════════════════════════════════════ */
    meta[name="csrf-token"] { display: none; }

    /* Boutons V2 */
    .v2-btn { padding:0 16px; height:32px; border-radius:999px; cursor:pointer; border:none; outline:none; font-family:'Sora',sans-serif; font-size:11px; letter-spacing:0.04em; background:var(--bg-primary); box-shadow:4px 4px 10px var(--shadow-dark),-4px -4px 10px var(--shadow-light); font-weight:600; color:#3a3830; text-decoration:none; display:inline-flex; align-items:center; gap:5px; transition:box-shadow 0.15s; }
    .v2-btn:active { box-shadow:inset 3px 3px 7px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light); }
    .v2-btn.primary { background:#36577d; color:var(--bg-primary); }
    .v2-btn.success { background:#4a6038; color:var(--bg-primary); }
    .v2-btn.danger  { background:#8a5040; color:var(--bg-primary); }
    .v2-btn.gold    { background:#7a6030; color:var(--bg-primary); }
    .v2-btn[title]:hover::after { content:attr(title); position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#2c2a27; color:#f0ece6; font-size:10px; white-space:nowrap; padding:3px 7px; border-radius:4px; pointer-events:none; z-index:999; }

    /* Nav user */
    .nav-select { height:28px; padding:0 8px; background:var(--bg-primary); box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:11px; color:#1a1816; cursor:pointer; outline:none; }
    .nav-arrow { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:var(--bg-primary); box-shadow:3px 3px 7px var(--shadow-dark),-3px -3px 8px var(--shadow-light); color:#6a6660; text-decoration:none; flex-shrink:0; transition:box-shadow 0.12s,color 0.12s; }
    .nav-arrow:hover { color:#36577d; }
    .nav-arrow:active { box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); }
    .nav-arrow.disabled { opacity:.35; cursor:not-allowed; pointer-events:none; }
    .user-name-btn { display:inline-flex; align-items:center; gap:6px; padding:5px 16px; border-radius:999px; background:#36577d; color:var(--bg-primary); font-family:'Sora',sans-serif; font-size:16px; font-weight:700; text-decoration:none; box-shadow:4px 4px 10px var(--shadow-dark),-4px -4px 10px var(--shadow-light); transition:box-shadow 0.15s,opacity 0.15s; }
    .user-name-btn:hover { opacity:.88; }
    .user-name-btn:active { box-shadow:inset 3px 3px 7px rgba(0,0,0,0.2); }
    .user-name-btn svg { flex-shrink:0; opacity:.7; }

    /* Card neumorphique */
    .v2-card { background:#eae6e0; border-radius:16px; box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light),0 0 0 1px rgba(196,192,186,0.25); margin-bottom:16px; }
    .v2-card-head { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid rgba(196,192,186,0.4); }
    .v2-card-title { font-family:'DM Mono',monospace; font-size:10px; font-weight:500; text-transform:uppercase; letter-spacing:0.22em; color:#7a9060; }
    .v2-card-body { padding:18px 20px; }

    /* User info pills */
    .info-pills { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .info-pill { display:flex; flex-direction:column; gap:1px; padding:6px 12px; border-radius:10px; background:var(--bg-primary); box-shadow:3px 3px 7px var(--shadow-dark),-3px -3px 8px var(--shadow-light); }
    .info-pill-label { font-family:'DM Mono',monospace; font-size:8px; text-transform:uppercase; letter-spacing:0.18em; color:#a8a49e; }
    .info-pill-value { font-family:'DM Mono',monospace; font-size:12px; font-weight:500; color:#36577d; }

    /* Section title V2 */
    .sec-head { display:flex; align-items:center; gap:14px; margin:15px 0 10px; }
    .sec-txt { font-family:'DM Mono',monospace; font-size:14px; font-weight:500; letter-spacing:0.28em; text-transform:uppercase; white-space:nowrap; flex-shrink:0; background:linear-gradient(180deg,#7a9060 0%,#4a6038 40%,#304828 70%,#607848 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
    .line-l { height:1.5px; width:28px; flex-shrink:0; background:linear-gradient(90deg,transparent 0%,#304828 40%,#9ab870 100%); border-radius:2px; }
    .line-r { height:1.5px; flex:1; background:linear-gradient(90deg,#9ab870 0%,#607848 30%,#4a6038 55%,transparent 100%); border-radius:2px; }

    /* Filtres */
    .filters-row { display:flex; align-items:flex-end; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
    .tf { display:flex; flex-direction:column; gap:2px; }
    .tf label { font-family:'DM Mono',monospace; font-size:8px; font-weight:500; letter-spacing:0.18em; text-transform:uppercase; color:#a8a49e; }
    .tf select { height:30px; padding:0 8px; background:var(--bg-primary); box-shadow:inset 3px 3px 6px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:11px; color:#1a1816; cursor:pointer; outline:none; }

    /* Champs formulaire */
    .fields-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; padding:4px 0 8px; }
    .fields-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; padding:4px 0 8px; }
    .fields-grid-ik { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; padding:4px 0 8px; }
    .form-field { display:flex; flex-direction:column; gap:5px; }
    .form-field label { font-family:'DM Mono',monospace; font-size:9px; font-weight:600; text-transform:uppercase; letter-spacing:0.15em; color:#7a7670; }
    .form-field input[type=text] { height:36px !important; padding:0 12px !important; background:#f0f1f3 !important; box-shadow:inset 2px 2px 5px #c0bbb5,inset -2px -2px 5px #f5f2ed !important; border:1px solid rgba(196,192,186,0.5) !important; border-radius:8px !important; font-family:'Sora',sans-serif !important; font-size:13px !important; color:#1a1816 !important; outline:none !important; display:block !important; width:100% !important; box-sizing:border-box !important; }
    .form-field input[type=text]:focus { box-shadow:inset 2px 2px 5px #c0bbb5,inset -2px -2px 5px #f5f2ed,0 0 0 2px rgba(74,96,56,0.3) !important; border-color:rgba(74,96,56,0.4) !important; }
    .form-field input.readonly { cursor:default !important; color:#6a8aaa !important; font-style:italic; }
    .fields-grid-ik .form-field input.readonly { background:#eef4fb !important; border-color:rgba(30,64,175,0.15) !important; color:#36577d !important; font-style:normal; font-weight:600; }
    /* Champs commissions auto-remplis */
    .col-primes .form-field input.readonly { background:#fefce8 !important; border-color:rgba(146,64,14,0.15) !important; color:#92400e !important; font-style:normal; font-weight:600; }
    .form-field textarea { padding:6px 12px !important; background:#f0f1f3 !important; box-shadow:inset 2px 2px 5px #c0bbb5,inset -2px -2px 5px #f5f2ed !important; border:1px solid rgba(196,192,186,0.5) !important; border-radius:8px !important; font-family:'Sora',sans-serif !important; font-size:11px !important; color:#4a4840 !important; resize:none !important; min-height:24px !important; overflow:hidden !important; outline:none !important; margin-top:0 !important; display:block !important; width:100% !important; box-sizing:border-box !important; }
    .form-field textarea:focus { box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light),0 0 0 2px rgba(74,96,56,0.15); }
    .save-status { font-family:'DM Mono',monospace; font-size:9px; color:#4a6038; margin-top:2px; display:none; }
    .save-status.show { display:block; }

    /* Commentaires */
    .comments-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; }
    .comments-grid.single { grid-template-columns:1fr; }
    .comment-block { background:var(--bg-primary); border-radius:14px; box-shadow:5px 5px 12px var(--shadow-dark),-5px -5px 12px var(--shadow-light); padding:16px; }
    .comment-block.admin { box-shadow:5px 5px 12px var(--shadow-dark),-5px -5px 12px var(--shadow-light),0 0 0 2px rgba(122,96,48,0.2); }
    .comment-block-label { font-family:'DM Mono',monospace; font-size:9px; text-transform:uppercase; letter-spacing:0.2em; color:#7a9060; margin-bottom:8px; }
    .comment-block.admin .comment-block-label { color:#7a6030; }
    .comment-block textarea { width:100%; padding:8px 10px; background:var(--bg-primary); box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:13px; color:#3a3830; resize:none; min-height:60px; outline:none; line-height:1.5; }

    /* Documents */
    .docs-grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .doc-item { display:flex; align-items:center; gap:6px; padding:6px 10px; background:var(--bg-primary); border-radius:10px; box-shadow:3px 3px 7px var(--shadow-dark),-3px -3px 8px var(--shadow-light); }
    .doc-item-name { font-size:11px; font-weight:600; color:#36577d; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .doc-btn { display:inline-flex; align-items:center; justify-content:center; height:22px; padding:0 8px; border-radius:6px; border:none; cursor:pointer; font-family:'Sora',sans-serif; font-size:10px; font-weight:600; background:var(--bg-primary); box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); color:#3a3830; text-decoration:none; }
    .doc-btn:active { box-shadow:inset 1px 1px 3px var(--shadow-dark),inset -1px -1px 3px var(--shadow-light); }
    .doc-btn.danger { color:#8a5040; }

    /* Upload buttons inline */
    .upload-btns { display:flex; gap:6px; flex-wrap:wrap; }

    /* Modals upload */
    .upload-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(26,24,22,0.45); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; }
    .upload-modal.show { display:flex; }

    /* Modal aperçu doc */
    .doc-preview-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(26,24,22,0.55);backdrop-filter:blur(4px);z-index:2000;align-items:center;justify-content:center}
    .doc-preview-modal.show{display:flex}
    .doc-preview-box{background:#fff;border-radius:14px;box-shadow:6px 6px 18px rgba(0,0,0,.25);width:92vw;max-width:1100px;height:88vh;display:flex;flex-direction:column;position:relative;overflow:hidden}
    .doc-preview-head{padding:12px 18px;border-bottom:1px solid #e4e6ec;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .doc-preview-title{font-family:'Sora',sans-serif;font-size:14px;font-weight:700;color:#3a3830;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .doc-preview-actions{display:flex;gap:8px;align-items:center}
    .doc-preview-body{flex:1;overflow:hidden;background:#f0f1f3}
    .doc-preview-iframe{width:100%;height:100%;border:0;display:block}
    .modal-box { background:var(--bg-primary); border-radius:18px; box-shadow:6px 6px 18px #c0bcb6,-4px -4px 10px var(--shadow-light),0 0 0 1px rgba(196,192,186,0.35); padding:24px; max-width:400px; width:90%; position:relative; }
    .modal-box-title { font-family:'DM Mono',monospace; font-size:10px; font-weight:500; text-transform:uppercase; letter-spacing:0.22em; color:#7a9060; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid rgba(196,192,186,0.4); }
    .modal-close-btn { position:absolute; top:14px; right:16px; width:26px; height:26px; border-radius:50%; background:var(--bg-primary); box-shadow:3px 3px 6px var(--shadow-dark),-3px -3px 8px var(--shadow-light); border:none; cursor:pointer; font-size:13px; color:#8a8680; display:flex; align-items:center; justify-content:center; line-height:1; transition:color 0.15s; }
    .modal-close-btn:hover { color:#8a5040; }
    .modal-info-row { display:flex; gap:8px; margin-bottom:12px; }
    .modal-info-chip { display:flex; flex-direction:column; gap:2px; flex:1; }
    .modal-info-chip label { font-family:'DM Mono',monospace; font-size:8px; text-transform:uppercase; letter-spacing:0.18em; color:#a8a49e; }
    .modal-info-chip span { font-family:'Sora',sans-serif; font-size:11px; font-weight:600; color:#3a3830; background:#f0f1f3; box-shadow:inset 2px 2px 4px var(--shadow-dark),inset -2px -2px 4px #f5f2ed; border-radius:7px; padding:5px 9px; display:block; }
    .modal-field { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
    .modal-field label { font-family:'DM Mono',monospace; font-size:8px; text-transform:uppercase; letter-spacing:0.18em; color:#a8a49e; }
    .dropzone { border:2px dashed rgba(196,192,186,0.6); border-radius:10px; padding:22px 16px; text-align:center; cursor:pointer; transition:all 0.2s; background:#f0f1f3; box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px #f5f2ed; }
    .dropzone:hover { border-color:#7a9060; }
    .dropzone .dz-main { font-family:'Sora',sans-serif; font-size:13px; font-weight:600; color:#4a6038; margin-bottom:2px; }
    .dropzone .dz-sub { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; }
    .modal-btns { display:flex; margin-top:16px; }
    .modal-btns .v2-btn { width:100%; justify-content:center; }

    /* Badge */
    .v2-badge { display:inline-flex; align-items:center; padding:2px 10px; border-radius:999px; font-size:10px; font-weight:600; letter-spacing:0.04em; font-family:'DM Mono',monospace; }
    .v2-badge.warn { background:#fef5db; color:#7a4e0a; }
    .v2-badge.ok   { background:#d4f0df; color:#1a6035; }
    .v2-badge.locked { background:var(--bg-secondary); color:#8a8680; box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); }

    /* Action strip */
    .action-strip { display:flex; flex-direction:row; align-items:flex-start; gap:20px; margin-bottom:20px; }
    .action-strip-filters { display:flex; flex-direction:column; gap:12px; flex:0 0 auto; }
    .filter-row { display:flex; align-items:center; gap:15px; }
    .filter-label { font-family:'DM Mono',monospace; font-size:12px; font-weight:500; text-transform:uppercase; letter-spacing:0.12em; color:#a8a49e; width:46px; flex-shrink:0; text-align:right; }
    .filter-btns { display:flex; align-items:center; gap:12px; }
    .filter-pill { height:28px; padding:0 10px; border-radius:999px; background:var(--bg-primary); box-shadow:3px 3px 7px var(--shadow-dark),-3px -3px 8px var(--shadow-light); border:none; cursor:pointer; outline:none; font-family:'Sora',sans-serif; font-size:11px; font-weight:500; color:#6a6660; transition:box-shadow 0.12s,color 0.12s; white-space:nowrap; text-align:center; }
    .filter-pill:hover { color:#36577d; }
    .filter-pill.active { box-shadow:inset 3px 3px 6px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light); color:#36577d; font-weight:700; }
    .filter-more { height:28px; width:68px; padding:0 6px; text-align:center; background:var(--bg-primary); box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:11px; color:#6a6660; cursor:pointer; outline:none; }
    .filter-select { height:28px; background:var(--bg-primary); box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); border:none; border-radius:8px; font-family:'Sora',sans-serif; font-size:11px; color:#6a6660; cursor:pointer; outline:none; padding:0 6px; }
    .action-strip-btns { display:grid; grid-template-columns:repeat(3,auto); justify-content:end; align-content:start; gap:12px 19px; flex:1; }
    .filter-sep { width:1px; height:20px; background:rgba(196,192,186,0.6); margin:0 4px; flex-shrink:0; }
    .nav-user-col { display:flex; flex-direction:column; align-items:center; gap:4px; }
    .nav-user-modele { display:flex; align-items:center; gap:5px; font-family:'DM Mono',monospace; font-size:9px; text-transform:uppercase; letter-spacing:.12em; color:#a8a49e; }

    /* Page head comment admin */
    .page-head-comment-admin { flex:0 0 30%; display:flex; flex-direction:column; justify-content:center; gap:3px; }
    .page-head-comment-admin .comment-block-label { font-family:'DM Mono',monospace; font-size:8px; text-transform:uppercase; letter-spacing:0.2em; color:#7a6030; }
    .page-head-comment-admin textarea { width:100%; padding:4px 8px !important; background:#f0f1f3 !important; box-shadow:inset 2px 2px 5px #c0bbb5,inset -2px -2px 5px #f5f2ed !important; border:1px solid rgba(196,192,186,0.5) !important; border-radius:7px !important; font-family:'Sora',sans-serif !important; font-size:11px !important; color:#3a3830 !important; resize:none !important; min-height:44px !important; max-height:52px !important; outline:none !important; line-height:1.4; display:block !important; box-sizing:border-box !important; }

    /* Bouton Frais KM */
    .btn-ik { background:#dbeafe !important; color:#1e40af !important; box-shadow:3px 3px 8px #c0ceea,-3px -3px 8px #f0f5ff !important; border:1px solid rgba(30,64,175,0.15) !important; }
    .btn-ik:hover { background:#bfdbfe !important; text-decoration:none !important; }
    /* Bouton Commissions */
    .btn-commission { background:#fef3c7 !important; color:#92400e !important; box-shadow:3px 3px 8px #e8d89a,-3px -3px 8px #fffde7 !important; border:1px solid rgba(146,64,14,0.15) !important; }
    .btn-commission:hover { background:#fde68a !important; text-decoration:none !important; }

    /* Champ avec document chargé */
    .form-field input.has-doc { background:#e8f5e9 !important; border-color:rgba(74,160,96,0.35) !important; }
    .form-field input.has-doc:hover { background:#dff0e1 !important; }
    /* Immatriculation cliquable */
    .field-immat-link { display:flex; align-items:center; height:36px; padding:0 12px; border-radius:8px; background:#f0f1f3; border:1px solid rgba(196,192,186,0.5); font-family:'Sora',sans-serif; font-size:13px; color:#36577d; font-weight:600; text-decoration:none; box-shadow:inset 2px 2px 5px #c0bbb5,inset -2px -2px 5px #f5f2ed; cursor:pointer; transition:background 0.15s; }
    .field-immat-link:hover { background:#d0f0d8; border-color:rgba(74,160,96,0.4); text-decoration:none; }
    .field-immat-link.has-doc { background:#e8f5e9; border-color:rgba(74,160,96,0.35); }

    /* Sections côte à côte (Primes + IK) */
    .sections-row { display:flex; gap:0; align-items:flex-start; justify-content:space-between; }
    .section-col { display:flex; flex-direction:column; }
    .section-col.col-primes { flex:0 0 40%; min-width:0; }
    .section-col.col-ik { flex:0 0 50%; min-width:0; }

    /* Collapse section */
    .sec-collapse-btn { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; border:none; cursor:pointer; background:var(--bg-primary); color:#7a9060; box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px #f5f2ed; transition:transform 0.25s ease,box-shadow 0.15s; flex-shrink:0; margin-left:4px; }
    .sec-collapse-btn:active { box-shadow:inset 2px 2px 4px var(--shadow-dark),inset -2px -2px 4px #f5f2ed; }
    .sec-collapse-btn.open { transform:rotate(180deg); }
    .sec-collapsible { overflow:hidden; transition:max-height 0.3s ease, opacity 0.25s ease; max-height:0; opacity:0; }
    .sec-collapsible.open { max-height:1000px; opacity:1; }

    @media (max-width:900px) { .fields-grid-ik,.comments-grid { grid-template-columns:1fr; } .sections-row { flex-direction:column; } }
</style>
EXTRACSS;

$layout_extra_js = '<meta name="csrf-token" content="' . $_csrf_token_for_js . '">'
. '<script>
const _CSRF = document.querySelector(\'meta[name="csrf-token"]\')?.content || \'\';
const MONTH_LOCKED = ' . ($monthClosed ? 'true' : 'false') . ';
let saveTimeout = {};
function autoSaveField(fieldName, value) {
    if (MONTH_LOCKED) return;
    clearTimeout(saveTimeout[fieldName]);
    const statusEl = document.getElementById(`status-${fieldName}`);
    if (statusEl) {
        statusEl.textContent = \'Enreg...\';
        statusEl.classList.add(\'show\');
    }
    saveTimeout[fieldName] = setTimeout(() => {
        const formData = new FormData();
        formData.append(\'save_field\', fieldName);
        formData.append(\'value\', value);
        formData.append(\'csrf_token\', _CSRF);
        fetch(window.location.pathname + window.location.search, {method:\'POST\', body:formData})
        .then(r => {
            if (statusEl) {
                statusEl.textContent = r.ok ? \'✓\' : \'✗\';
                setTimeout(() => {statusEl.classList.remove(\'show\');}, 1500);
            }
        })
        .catch(e => {
            if (statusEl) statusEl.textContent = \'✗\';
        });
    }, 500);
}
function navigateToUser(userId) {
    if (userId) {
        const params = new URLSearchParams(window.location.search);
        window.location.href = `?id_user=${userId}&mois_ref=${encodeURIComponent(params.get(\'mois_ref\'))}`;
    }
}
function deleteDoc(docId, docName) {
    if (confirm(`Êtes-vous sûr de vouloir supprimer le document "${docName}" ?`)) {
        const formData = new FormData();
        formData.append(\'delete_doc\', docId);
        formData.append(\'csrf_token\', _CSRF);
        fetch(window.location.pathname + window.location.search, {method:\'POST\', body:formData})
        .then(r => {
            if (r.ok) {
                location.reload();
            } else {
                alert(\'Erreur lors de la suppression\');
            }
        })
        .catch(e => alert(\'Erreur: \' + e.message));
    }
}
function autoResizeTextarea(textarea) {
    textarea.style.height = \'auto\';
    textarea.style.height = Math.min(textarea.scrollHeight, 300) + \'px\';
}
document.addEventListener(\'DOMContentLoaded\', function() {
    document.querySelectorAll(\'.auto-resize-textarea\').forEach(textarea => {
        autoResizeTextarea(textarea);
    });
});
function changeFilters() {
    const m = String(document.getElementById(\'f-mois\').value).padStart(2, \'0\');
    const a = document.getElementById(\'f-annee\').value;
    const u = document.getElementById(\'f-user\').value;
    const moisRef = `${a}-${m}-01`;
    window.location = `?id_user=${u}&mois_ref=${moisRef}`;
}
function setAnnee(a) {
    const m = String(document.getElementById(\'f-mois\')?.value || ' . $mois_sel . ').padStart(2,\'0\');
    const u = document.getElementById(\'f-user\')?.value || ' . $idUser . ';
    window.location = `?id_user=${u}&mois_ref=${a}-${m}-01`;
}
function setMois(m) {
    const a = document.getElementById(\'f-annee\')?.value || ' . $annee_sel . ';
    const u = document.getElementById(\'f-user\')?.value || ' . $idUser . ';
    window.location = `?id_user=${u}&mois_ref=${a}-${String(m).padStart(2,\'0\')}-01`;
}
function toggleKpi() {
    const extra = document.getElementById(\'kpi-extra\');
    const btn   = document.getElementById(\'kpi-toggle-btn\');
    const open  = extra.style.display === \'none\';
    extra.style.display = open ? \'flex\' : \'none\';
    btn.classList.toggle(\'open\', open);
}
function openDoc(url) {
    window.open(url, \'_blank\');
}
function previewDoc(url, name) {
    var titleEl = document.getElementById(\'doc-preview-title\');
    var iframeEl = document.getElementById(\'doc-preview-iframe\');
    var dlEl = document.getElementById(\'doc-preview-dl\');
    if (titleEl) titleEl.textContent = name || \'Aperçu document\';
    if (iframeEl) iframeEl.src = url;
    if (dlEl) dlEl.href = url.replace(/[?&]inline=1/g, \'\').replace(/[?&]$/, \'\');
    var modal = document.getElementById(\'doc-preview-modal\');
    if (modal) modal.classList.add(\'show\');
    document.addEventListener(\'keydown\', _docPreviewEscHandler);
}
function closeDocPreview() {
    var iframeEl = document.getElementById(\'doc-preview-iframe\');
    var modal = document.getElementById(\'doc-preview-modal\');
    if (iframeEl) iframeEl.src = \'\';
    if (modal) modal.classList.remove(\'show\');
    document.removeEventListener(\'keydown\', _docPreviewEscHandler);
}
function _docPreviewEscHandler(e) {
    if (e.key === \'Escape\') closeDocPreview();
}
function toggleModele(checked) {
    const params = new URLSearchParams(window.location.search);
    if (checked) {
        const returnRef = params.get(\'mois_ref\') || \'' . urlencode($mois_ref) . '\';
        window.location.href = \'?id_user=' . $idUser . '&mois_ref=0000-00-00&return_ref=\' + encodeURIComponent(returnRef);
    } else {
        const returnRef = params.get(\'return_ref\') || \'' . urlencode(sprintf('%04d-%02d-01', $annee_sel, $mois_sel)) . '\';
        window.location.href = \'?id_user=' . $idUser . '&mois_ref=\' + encodeURIComponent(returnRef);
    }
}
function toggleSection(id) {
    const c = document.getElementById(\'collapsible-\' + id);
    const b = document.getElementById(\'collapse-btn-\' + id);
    const open = c.classList.toggle(\'open\');
    b.classList.toggle(\'open\', open);
}
function openUploadModal(categorie) {
    document.getElementById(\'modal-\' + categorie).classList.add(\'show\');
    const prev = document.getElementById(\'preview-\' + categorie);
    if (prev) { prev.style.display = \'none\'; document.getElementById(\'preview-img-\' + categorie).style.display = \'none\'; document.getElementById(\'preview-pdf-\' + categorie).style.display = \'none\'; }
    setTimeout(() => setupDragDrop(categorie), 100);
}
function closeUploadModal(categorie) {
    document.getElementById(\'modal-\' + categorie).classList.remove(\'show\');
}
function previewFile(categorie, input) {
    if (!input.files.length) return;
    const file = input.files[0];
    const prev = document.getElementById(\'preview-\' + categorie);
    const img  = document.getElementById(\'preview-img-\' + categorie);
    const pdf  = document.getElementById(\'preview-pdf-\' + categorie);
    prev.style.display = \'block\';
    if (file.type.startsWith(\'image/\')) {
        img.src = URL.createObjectURL(file);
        img.style.display = \'block\';
        pdf.style.display = \'none\';
    } else if (file.type === \'application/pdf\') {
        img.style.display = \'none\';
        pdf.style.display = \'flex\';
        pdf._blob = URL.createObjectURL(file);
    } else {
        img.style.display = \'none\';
        pdf.style.display = \'none\';
        prev.style.display = \'none\';
    }
    const dz = document.getElementById(\'dropzone-\' + categorie);
    if (dz) dz.querySelector(\'.dz-main\').textContent = \'✓ \' + file.name;
}
function previewPdf(categorie) {
    const pdf = document.getElementById(\'preview-pdf-\' + categorie);
    if (pdf && pdf._blob) window.open(pdf._blob, \'_blank\');
}
function uploadDoc(categorie) {
    const fileInput = document.getElementById(\'file-\' + categorie);
    if (!fileInput.files.length) {
        alert(\'Sélectionne un fichier\');
        return;
    }
    const formData = new FormData();
    formData.append(\'doc_file\', fileInput.files[0]);
    formData.append(\'doc_categorie\', categorie);
    formData.append(\'csrf_token\', _CSRF);
    fetch(window.location.pathname + window.location.search, {method:\'POST\', body:formData})
    .then(r => r.text().then(text => ({ok: r.ok, text})))
    .then(({ok, text}) => {
        if (ok) {
            closeUploadModal(categorie);
            location.reload();
        } else {
            alert(\'Erreur upload: \' + text);
        }
    })
    .catch(e => alert(\'Erreur: \' + e.message));
}
function setupDragDrop(categorie) {
    const dropZone = document.getElementById(\'dropzone-\' + categorie);
    const fileInput = document.getElementById(\'file-\' + categorie);
    if (!dropZone) return;
    fileInput.addEventListener(\'change\', function() {
        if (this.files.length > 0) {
            dropZone.innerHTML = \'<p style="color:#4a6038"><strong>✓ \' + this.files[0].name + \'</strong></p>\';
        }
    });
    [\'dragenter\', \'dragover\', \'dragleave\', \'drop\'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });
    function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }
    [\'dragenter\', \'dragover\'].forEach(eventName => {
        dropZone.addEventListener(eventName, highlight, false);
    });
    [\'dragleave\', \'drop\'].forEach(eventName => {
        dropZone.addEventListener(eventName, unhighlight, false);
    });
    function highlight(e) { dropZone.style.background = \'rgba(72,120,166,0.1)\'; dropZone.style.borderColor = \'#4a6038\'; }
    function unhighlight(e) { dropZone.style.background = \'\'; dropZone.style.borderColor = \'\'; }
    dropZone.addEventListener(\'drop\', handleDrop, false);
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        fileInput.files = files;
        if (files.length > 0) {
            dropZone.innerHTML = \'<p style="color:#4a6038"><strong>✓ \' + files[0].name + \'</strong></p>\';
        }
        unhighlight(e);
    }
}
function printDoc(filePath) {
    const win = window.open(filePath, \'_blank\');
    if (win) { win.onload = function() { win.print(); }; }
    else { alert(\'Ouvrir le fichier pour imprimer...\\n\' + filePath); }
}
function transferDoc(fileName) {
    const email = prompt(\'Adresse email pour transférer le fichier:\\n\' + fileName);
    if (email) { alert(\'Fonction de transfert en développement.\\nFichier: \' + fileName + \'\\nEmail: \' + email); }
}
function downloadAllDocs() {
    const docs = ' . json_encode($docs) . ';
    if (!docs || docs.length === 0) { alert(\'Aucun document à télécharger\'); return; }
    const form = document.createElement(\'form\');
    form.method = \'POST\';
    form.style.display = \'none\';
    const input = document.createElement(\'input\');
    input.type = \'hidden\';
    input.name = \'download_all_docs\';
    input.value = \'1\';
    form.appendChild(input);
    const docIds = document.createElement(\'input\');
    docIds.type = \'hidden\';
    docIds.name = \'doc_ids\';
    docIds.value = JSON.stringify(docs.map(d => d.id));
    form.appendChild(docIds);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
</script>';

/* ═══════════════════════════════════════════════════════════════════════
   HTML CONTENT
   ═══════════════════════════════════════════════════════════════════════ */
ob_start();
?>

            <!-- PAGE HEAD extra (admin comment) -->
            <?php if ($roleId === 1): ?>
            <div style="display:flex;align-items:flex-start;gap:20px;margin-bottom:16px">
                <div style="flex:1">
                    <div class="page-head-comment-admin">
                        <div class="comment-block-label">&#128274; Commentaire Admin</div>
                        <textarea class="auto-resize-textarea" id="f-commentaire-admin"
                                  placeholder="Commentaire réservé à l'administrateur..."
                                  onchange="autoSaveField('commentaire_admin', this.value)"
                                  oninput="autoResizeTextarea(this)"><?=h($sal['commentaire_admin']??'')?></textarea>
                        <div class="save-status" id="status-commentaire_admin"></div>
                    </div>
                </div>
                <div>
                    <span class="page-head-sub">
                        <?=mois_fr($mois_sel)?> <?=$annee_sel?>
                        — <a href="rh_user.php?highlight_user=<?=$idUser?>" style="color:#7a9060;text-decoration:none" title="Fiche collaborateur"><?=h($nom_complet)?></a>
                        — User #<?=$idUser?>
                        — Salaire #<?=$sal['id']??'À créer'?>
                        <?php if ($monthClosed): ?>&nbsp;<span class="v2-badge locked">&#128274; Clôturé</span><?php endif; ?>
                    </span>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-bottom:16px">
                <span class="page-head-sub" style="font-family:'DM Mono',monospace;font-size:11px;color:#7a9060">
                    <?=mois_fr($mois_sel)?> <?=$annee_sel?>
                    — <a href="rh_user.php?highlight_user=<?=$idUser?>" style="color:#7a9060;text-decoration:none" title="Fiche collaborateur"><?=h($nom_complet)?></a>
                    — User #<?=$idUser?>
                    — Salaire #<?=$sal['id']??'À créer'?>
                    <?php if ($monthClosed): ?>&nbsp;<span class="v2-badge locked">&#128274; Clôturé</span><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>


            <!-- ── Barre d'actions + filtres ── -->
            <div class="section-title">
                <span class="line-l"></span>
                <span class="sec-txt">Actions</span>
                <span class="line-r"></span>
            </div>
            <div class="action-strip">

                <!-- Filtres période -->
                <div class="action-strip-filters">
                    <!-- Ligne 1 : Mois + nav user -->
                    <div class="filter-row">
                        <span class="filter-label">Mois</span>
                        <div class="filter-btns">
                            <?php foreach($pillMonths as $pm): ?>
                            <button type="button"
                                    class="filter-pill <?=$pm==$mois_sel?'active':''?>"
                                    onclick="setMois(<?=$pm?>)"><?=mois_fr($pm)?></button>
                            <?php endforeach; ?>
                            <select class="filter-more" onchange="setMois(this.value)" title="Autre mois">
                                <option value="">…</option>
                                <?php for($m=1;$m<=12;$m++):
                                    if (in_array($m,$pillMonths)) continue; ?>
                                <option value="<?=$m?>" <?=$m==$mois_sel&&!in_array($mois_sel,$pillMonths)?'selected':''?>><?=mois_fr($m)?></option>
                                <?php endfor; ?>
                            </select>
                            <?php if ($roleId !== 3): ?>
                            <span class="filter-sep"></span>
                            <?php if ($prevUser): ?>
                            <a href="?id_user=<?=$prevUser['id']?>&mois_ref=<?=urlencode($mois_ref)?>" class="nav-arrow" title="<?=h($prevUser['nom'])?>">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                            </a>
                            <?php else: ?>
                            <span class="nav-arrow disabled"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></span>
                            <?php endif; ?>
                            <select onchange="navigateToUser(this.value)" class="filter-more" style="width:140px" title="Choisir un collaborateur">
                                <?php foreach ($allUsers as $u): ?>
                                <option value="<?=$u['id']?>" <?=$u['id']==$idUser?'selected':''?>><?=h($u['nom'])?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($nextUser): ?>
                            <a href="?id_user=<?=$nextUser['id']?>&mois_ref=<?=urlencode($mois_ref)?>" class="nav-arrow" title="<?=h($nextUser['nom'])?>">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                            </a>
                            <?php else: ?>
                            <span class="nav-arrow disabled"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Ligne 2 : Année + toggle Modèle -->
                    <div class="filter-row">
                        <span class="filter-label">Année</span>
                        <div class="filter-btns">
                            <?php foreach($pillYears as $py): ?>
                            <button type="button"
                                    class="filter-pill <?=$py==$annee_sel?'active':''?>"
                                    onclick="setAnnee(<?=$py?>)"><?=$py?></button>
                            <?php endforeach; ?>
                            <select class="filter-more" onchange="setAnnee(this.value)" title="Autre année">
                                <option value="">…</option>
                                <?php for($a=$nowYear+1;$a>=$nowYear-8;$a--):
                                    if (in_array($a,$pillYears)) continue; ?>
                                <option value="<?=$a?>" <?=$a==$annee_sel&&!in_array($annee_sel,$pillYears)?'selected':''?>><?=$a?></option>
                                <?php endfor; ?>
                            </select>
                            <?php if ($roleId === 1): ?>
                            <span class="filter-sep"></span>
                            <span class="filter-label" style="width:auto">Voir son modèle</span>
                            <label class="toggle toggle-sm" title="Marquer ce salaire comme modèle réutilisable">
                                <input type="checkbox"
                                       id="modele-toggle-cb"
                                       <?=$mois_ref === '0000-00-00' ? 'checked' : ''?>
                                       onchange="toggleModele(this.checked)">
                                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                            </label>
                            <span id="modele-status" style="font-family:'DM Mono',monospace;font-size:9px;color:#7a9060;display:none">✓</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Boutons actions -->
                <div class="action-strip-btns">
                    <a href="rh_user.php?highlight_user=<?=$idUser?>"
                       class="v2-btn"
                       title="Voir la fiche collaborateur">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Voir l'user
                    </a>
                    <a href="rh_user_historiq.php?user_id=<?=$idUser?>"
                       class="v2-btn"
                       title="Historique du collaborateur">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        Historique
                    </a>
                </div>

            </div>

            <!-- DOCUMENTS (si existants) -->
            <?php if (count($docs_by_cat) > 0): ?>
            <div class="v2-card" style="margin-bottom:20px">
                <div class="v2-card-head">
                    <span class="v2-card-title">Documents (<?=count($docs)?>)</span>
                    <button onclick="downloadAllDocs()" class="v2-btn success" title="Télécharger tous les documents">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Télécharger tout
                    </button>
                </div>
                <div class="v2-card-body">
                    <div class="docs-grid">
                        <?php foreach ($docs_by_cat as $cat => $cat_docs): ?>
                        <?php foreach ($cat_docs as $doc): ?>
                        <div class="doc-item">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#7a9060" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <span class="doc-item-name" title="<?=h($doc['original_name'])?>"><?=h($doc['original_name'])?></span>
                            <button type="button" onclick="previewDoc('api/rh_salaire_doc_download.php?id=<?=(int)$doc['id']?>&inline=1', '<?=h(addslashes($doc['original_name']))?>')" class="doc-btn" title="Visualiser dans une fenêtre">
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            </button>
                            <a href="api/rh_salaire_doc_download.php?id=<?=(int)$doc['id']?>" download class="doc-btn" title="Télécharger">
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                DL
                            </a>
                            <button onclick="printDoc('api/rh_salaire_doc_download.php?id=<?=(int)$doc['id']?>&inline=1')" class="doc-btn" title="Imprimer">
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            </button>
                            <button onclick="transferDoc('<?=h(addslashes($doc['original_name']))?>')" class="doc-btn" title="Transférer">
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                            </button>
                            <button onclick="deleteDoc(<?=$doc['id']?>, '<?=h(addslashes($doc['original_name']))?>')" class="doc-btn danger" title="Supprimer">
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>


            <!-- SECTIONS FORMULAIRE -->
            <?php
            $ikPlacement = ['ik_nb_km'=>'grid-column:1;grid-row:1','total_ik'=>'grid-column:2;grid-row:1','ik_montant'=>'grid-column:3;grid-row:1','vehicule_immat'=>'grid-column:4;grid-row:1'];
            foreach($sections as $title => $fields):
                $isIK = ($title === 'Indemnités Kilométriques');
                $isPrimes = ($title === 'Primes & Commissions');
                $uploadBtns = [];
                foreach ($fields as $fname => $meta) {
                    if (isset($categories_doc[$fname])) $uploadBtns[$fname] = $categories_doc[$fname];
                }
                if ($isPrimes): // ouvre le wrapper côte à côte
            ?>
            <div class="sections-row">
            <?php endif; ?>

            <div class="section-col <?=$isPrimes?'col-primes':($isIK?'col-ik':'')?>">
                <div class="sec-head">
                    <div class="line-l"></div>
                    <span class="sec-txt"><?=$title?></span>
                    <?php if ($title === 'Rémunération de base'): ?>
                    <button class="sec-collapse-btn" id="collapse-btn-base" onclick="toggleSection('base')" title="Replier / déplier">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($uploadBtns) || $isIK || $isPrimes): ?>
                    <div class="upload-btns">
                        <?php foreach ($uploadBtns as $fname => $lbl): ?>
                        <button class="v2-btn" onclick="openUploadModal('<?=$fname?>')" title="Joindre un document <?=$lbl?>">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            <?=$lbl?>
                        </button>
                        <?php endforeach; ?>
                        <?php /* Bouton "Saisie" masqué temporairement — page rh_commission_detail.php
                                  à créer en mai 2026. En attendant : champs Commission CA / NA
                                  saisissables directement dans le tableau ci-dessous + justificatifs
                                  envoyés par mail au manager.
                        if ($isPrimes): ?>
                        <a href="rh_commission_detail.php?id_user=<?=$idUser?>&mois_ref=<?=urlencode($mois_ref)?>"
                           class="v2-btn btn-commission"
                           title="Saisir / voir les commissions du mois">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            Saisie
                        </a>
                        <?php endif; */ ?>
                        <?php if ($isIK): ?>
                        <a href="rh_indemnite_km.php?id_user=<?=$idUser?>&mois_ref=<?=urlencode($mois_ref)?>"
                           class="v2-btn btn-ik"
                           title="Saisir / voir les frais kilométriques du mois">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Saisie
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="line-r"></div>
                </div>

                <?php if ($title === 'Rémunération de base'): ?><div class="sec-collapsible" id="collapsible-base"><?php endif; ?>
                <div class="<?=$isIK?'fields-grid-ik':($title==='Rémunération de base'?'fields-grid-4':'fields-grid')?>" style="margin-bottom:16px">
                        <?php foreach($fields as $fname => $meta):
                            $isProtected = ($agenceScope > 0 && in_array($fname, $adminOnlyFields, true)) || !empty($meta['readonly']);
                            $colSpan = ($isIK && isset($ikPlacement[$fname])) ? ' style="'.$ikPlacement[$fname].'"' : '';
                            // Document chargé pour ce champ ?
                            $fieldDoc = $docs_by_cat[$fname][0] ?? null;
                            $hasDoc = $fieldDoc !== null;
                            // Immatriculation = cliquable vers carte grise
                            $isImmat = ($fname === 'vehicule_immat');
                        ?>
                            <div class="form-field"<?=$colSpan?>>
                                <label for="f-<?=$fname?>"><?=$meta['label']?></label>
                                <?php if ($isImmat): ?>
                                <a href="rh_user.php?highlight_user=<?=$idUser?>#carte-grise"
                                   class="field-immat-link <?=$hasDoc?'has-doc':''?>"
                                   title="Voir la carte grise">
                                    <?=h($sal['vehicule_immat'] ?: '—')?>
                                </a>
                                <?php elseif ($isProtected): ?>
                                <input type="text" id="f-<?=$fname?>"
                                       class="readonly <?=$hasDoc?'has-doc':''?>"
                                       value="<?=h(fmt_val($sal[$fname]??null, $meta['type']))?>"
                                       placeholder="<?=$meta['type']==='money'?'0,00':'0'?>"
                                       readonly
                                       title="Modification réservée à l'administrateur">
                                <?php else: ?>
                                <input type="text" id="f-<?=$fname?>"
                                       class="<?=$hasDoc?'has-doc':''?>"
                                       value="<?=h(fmt_val($sal[$fname]??null, $meta['type']))?>"
                                       placeholder="<?=$meta['type']==='money'?'0,00':'0'?>"
                                       <?=$hasDoc?'onclick="openDoc(\'api/rh_salaire_doc_download.php?id='.(int)$fieldDoc['id'].'&inline=1\')" title="Cliquer pour ouvrir le document joint" style="cursor:pointer"':''?>
                                       onchange="autoSaveField('<?=$fname?>', this.value)">
                                <?php endif; ?>
                                <?php if (!$isImmat): ?>
                                <textarea id="f-comment-<?=$fname?>"
                                          class="auto-resize-textarea"
                                          placeholder="Commentaire..."
                                          oninput="autoResizeTextarea(this)"
                                          onchange="autoSaveField('comment_<?=$fname?>', this.value)"><?=h($sal['comment_'.$fname]??'')?></textarea>
                                <div class="save-status" id="status-<?=$fname?>"></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                </div>
                <?php if ($title === 'Rémunération de base'): ?></div><?php endif; ?>
            </div>

            <?php if ($isIK): // ferme le wrapper côte à côte ?>
            </div><!-- /sections-row -->
            <?php endif; ?>

            <?php endforeach; ?>

<!-- MODALS UPLOAD -->
<?php foreach ($categories_doc as $fieldName => $label): ?>
<div class="upload-modal" id="modal-<?=$fieldName?>">
    <div class="modal-box">
        <button class="modal-close-btn" onclick="closeUploadModal('<?=$fieldName?>')">&#10005;</button>
        <div class="modal-box-title">Joindre — <?=h($label)?></div>
        <div class="modal-info-row">
            <div class="modal-info-chip">
                <label>Type</label>
                <span><?=h($label)?></span>
            </div>
            <div class="modal-info-chip">
                <label>Période</label>
                <span><?=h($periode_label)?></span>
            </div>
        </div>
        <div class="modal-info-row" style="margin-bottom:14px">
            <div class="modal-info-chip">
                <label>Collaborateur</label>
                <span><?=h($nom_complet)?></span>
            </div>
        </div>
        <div class="modal-field">
            <label>Fichier</label>
            <div class="dropzone" id="dropzone-<?=$fieldName?>" onclick="document.getElementById('file-<?=$fieldName?>').click()">
                <div class="dz-main">Glisse un fichier ici</div>
                <div class="dz-sub">ou clique pour ouvrir l'explorateur</div>
            </div>
            <input type="file" id="file-<?=$fieldName?>" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx" style="display:none"
                   onchange="previewFile('<?=$fieldName?>', this)">
        </div>
        <div id="preview-<?=$fieldName?>" style="display:none;margin-top:10px">
            <img id="preview-img-<?=$fieldName?>" src="" alt="" style="max-width:100%;max-height:160px;border-radius:8px;box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light);display:none">
            <button id="preview-pdf-<?=$fieldName?>" class="v2-btn" style="display:none;width:100%;justify-content:center" onclick="previewPdf('<?=$fieldName?>')">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Aperçu PDF
            </button>
        </div>
        <div class="modal-btns">
            <button onclick="uploadDoc('<?=$fieldName?>')" class="v2-btn success">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                Envoyer
            </button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Modal aperçu document (PDF / image inline) -->
<div id="doc-preview-modal" class="doc-preview-modal" onclick="if(event.target===this)closeDocPreview()">
    <div class="doc-preview-box">
        <div class="doc-preview-head">
            <div class="doc-preview-title" id="doc-preview-title">Aperçu document</div>
            <div class="doc-preview-actions">
                <a id="doc-preview-dl" href="#" download class="v2-btn" title="Télécharger">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    DL
                </a>
                <button type="button" onclick="closeDocPreview()" class="modal-close-btn" title="Fermer">×</button>
            </div>
        </div>
        <div class="doc-preview-body">
            <iframe id="doc-preview-iframe" class="doc-preview-iframe" src="" title="Aperçu du document"></iframe>
        </div>
    </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
