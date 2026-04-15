<?php
// agency_tache_detail.php — Détail / Création tâche (layout_maboximmo)
$current_page = 'taches';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew = isset($_GET['new']) || $id === 0;

$CATEGORIES = ['comptable','mutation','rappel','juridique','technique','administratif','communication','autre'];
$PRIORITES  = ['basse','normale','haute','critique'];
$STATUTS    = ['à faire','en cours','en attente','terminee','archivee'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function journal_add(PDO $pdo, int $idT, int $idU, string $action, string $msg): void {
    try {
        $pdo->prepare("INSERT INTO taches_journal (id_tache,id_user,type_action,message,date_action) VALUES (?,?,?,?,NOW())")
            ->execute([$idT,$idU,$action,$msg]);
    } catch(Throwable $e){}
}

// ── AJAX : récupérer commentaires ────────────────────────────────────
if (isset($_GET['ajax_comments']) && $id) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("
        SELECT c.id, c.contenu, c.date_creation, u.nom, u.prenom
        FROM taches_commentaires c
        JOIN users u ON u.id = c.id_utilisateur
        WHERE c.id_tache = ?
        ORDER BY c.date_creation ASC
    ");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX : ajouter commentaire ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_comment'])) {
    header('Content-Type: application/json');
    $contenu = trim($_POST['contenu'] ?? '');
    if ($contenu === '' || !$id) { echo json_encode(['ok'=>false]); exit; }
    $pdo->prepare("INSERT INTO taches_commentaires (id_tache,id_utilisateur,message,date_creation) VALUES (?,?,?,NOW())")
        ->execute([$id,$userId,$contenu]);
    $cid = (int)$pdo->lastInsertId();
    journal_add($pdo,$id,$userId,'commentaire','Commentaire ajouté');
    $c = $pdo->prepare("SELECT c.*,u.nom,u.prenom FROM taches_commentaires c JOIN users u ON u.id=c.id_utilisateur WHERE c.id=?");
    $c->execute([$cid]);
    echo json_encode(['ok'=>true,'comment'=>$c->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

// ── AJAX : supprimer commentaire ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_comment'])) {
    $cid = (int)$_POST['del_comment'];
    $stmt = $pdo->prepare("SELECT id_utilisateur FROM taches_commentaires WHERE id=? AND id_tache=?");
    $stmt->execute([$cid,$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && ($row['id_utilisateur'] == $userId || $roleId === 1)) {
        $pdo->prepare("DELETE FROM taches_commentaires WHERE id=?")->execute([$cid]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── AJAX : changer statut ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_statut'])) {
    $ns = $_POST['new_statut'] ?? '';
    if (in_array($ns, $STATUTS, true) && $id) {
        $pdo->prepare("UPDATE taches SET statut=? WHERE id=?")->execute([$ns,$id]);
        journal_add($pdo,$id,$userId,'statut_change','Statut → '.$ns);
        echo json_encode(['ok'=>true,'statut'=>$ns]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── AJAX : changer priorité ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_priorite'])) {
    $np = $_POST['new_priorite'] ?? '';
    if (in_array($np, $PRIORITES, true) && $id) {
        $pdo->prepare("UPDATE taches SET priorite=? WHERE id=?")->execute([$np,$id]);
        journal_add($pdo,$id,$userId,'priorite_change','Priorité → '.$np);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── AJAX : editer champ inline ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_field'])) {
    $field  = $_POST['field'] ?? '';
    $valeur = trim($_POST['valeur'] ?? '');
    $allowed = ['titre','description','categorie','priorite','statut','date_echeance','id_immeuble'];
    if (in_array($field, $allowed, true) && $id) {
        $v = $valeur ?: null;
        $pdo->prepare("UPDATE taches SET $field=? WHERE id=?")->execute([$v,$id]);
        journal_add($pdo,$id,$userId,'edit',$field.' modifié');
        echo 'OK'; exit;
    }
    echo 'ERR'; exit;
}

// ── Upload document ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file']) && $id) {
    $uploadDir = __DIR__ . '/uploads/taches/' . $id . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $file     = $_FILES['doc_file'];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed_ext = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','gif','txt','csv','zip'];
    if (!in_array($ext, $allowed_ext)) {
        echo json_encode(['ok'=>false,'msg'=>'Type de fichier non autorisé']); exit;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/','_',$origName);
    $destPath = $uploadDir . time() . '_' . $safeName;
    $relPath  = 'uploads/taches/' . $id . '/' . basename($destPath);
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $pdo->prepare("INSERT INTO taches_documents (id_tache,ajoute_par,fichier,chemin,type,taille,date_ajout) VALUES (?,?,?,?,?,?,NOW())")
            ->execute([$id,$userId,$origName,$relPath,$file['type'] ?: 'application/octet-stream',$file['size']]);
        $did = (int)$pdo->lastInsertId();
        journal_add($pdo,$id,$userId,'doc_upload','Document ajouté : '.$origName);
        echo json_encode(['ok'=>true,'id'=>$did,'nom'=>$origName,'chemin'=>$relPath]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Erreur upload']); exit;
}

// ── Supprimer document ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_doc']) && $id) {
    $did = (int)$_POST['del_doc'];
    $stmt = $pdo->prepare("SELECT * FROM taches_documents WHERE id=? AND id_tache=?");
    $stmt->execute([$did,$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc && ($doc['ajoute_par'] == $userId || $roleId === 1)) {
        $full = __DIR__ . '/' . ltrim($doc['chemin'],'/');
        if (file_exists($full)) @unlink($full);
        $pdo->prepare("DELETE FROM taches_documents WHERE id=?")->execute([$did]);
        journal_add($pdo,$id,$userId,'doc_delete','Document supprimé : '.$doc['fichier']);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── Envoyer notification mail aux assignés ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notif']) && $id) {
    require_once __DIR__ . '/inc/mailer_tache.php';
    $result = sendTacheNotification($pdo, $id, $userId, trim($_POST['msg_notif'] ?? ''));
    header("Location: agency_tache_detail.php?id=$id&notif=".($result?'ok':'err')); exit;
}

$errors = [];
// ── POST créer/modifier tâche ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tache'])) {
    $titre       = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categorie   = in_array($_POST['categorie']??'', $CATEGORIES) ? $_POST['categorie'] : 'autre';
    $priorite    = in_array($_POST['priorite']??'', $PRIORITES)   ? $_POST['priorite']  : 'normale';
    $statut      = in_array($_POST['statut']??'', $STATUTS)       ? $_POST['statut']    : 'à faire';
    $id_immeuble = (int)($_POST['id_immeuble'] ?? 0) ?: null;
    $date_ech    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date_echeance']??'') ? $_POST['date_echeance'] : null;
    $assignes    = array_map('intval', (array)($_POST['assignes'] ?? []));

    if ($titre === '') $errors[] = 'Le titre est obligatoire.';

    if (empty($errors)) {
        if ($isNew) {
            $pdo->prepare("INSERT INTO taches (titre,description,categorie,priorite,statut,id_immeuble,id_createur,date_echeance,source,date_creation) VALUES (?,?,?,?,?,?,?,'manuelle',NOW())")
                ->execute([$titre,$description,$categorie,$priorite,$statut,$id_immeuble,$userId,$date_ech]);
            $id = (int)$pdo->lastInsertId();
            journal_add($pdo,$id,$userId,'creation','Tâche créée');
        } else {
            $pdo->prepare("UPDATE taches SET titre=?,description=?,categorie=?,priorite=?,statut=?,id_immeuble=?,date_echeance=? WHERE id=?")
                ->execute([$titre,$description,$categorie,$priorite,$statut,$id_immeuble,$date_ech,$id]);
            journal_add($pdo,$id,$userId,'edit','Tâche modifiée');
        }
        $pdo->prepare("DELETE FROM taches_utilisateurs WHERE id_tache=?")->execute([$id]);
        if (empty($assignes)) $assignes = [$userId];
        foreach (array_unique($assignes) as $uid) {
            $pdo->prepare("INSERT IGNORE INTO taches_utilisateurs (id_tache,id_utilisateur) VALUES (?,?)")->execute([$id,$uid]);
        }
        header("Location: agency_tache_detail.php?id=$id&saved=1"); exit;
    }
}

// ── Charger tâche ────────────────────────────────────────────────────
$tache     = null;
$assignes  = [];
$docs      = [];
$journal   = [];
if (!$isNew && $id) {
    $stmt = $pdo->prepare("
        SELECT t.*,
               i.nom_immeuble AS imm_nom, i.reference_immeuble AS imm_ref,
               u.nom AS auteur_nom, u.prenom AS auteur_prenom
        FROM taches t
        LEFT JOIN immeubles i ON i.id = t.id_immeuble
        LEFT JOIN users u ON u.id = t.id_createur
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $tache = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tache) { header('Location: agency_taches.php'); exit; }

    $isCreator  = ((int)$tache['id_createur'] === $userId);
    $isAssigned = (bool)$pdo->prepare("SELECT 1 FROM taches_utilisateurs WHERE id_tache=? AND id_utilisateur=?")->execute([$id,$userId]);
    if ($roleId > 2 && !$isCreator && !$isAssigned) { header('Location: agency_taches.php'); exit; }

    $ass_stmt = $pdo->prepare("SELECT u.id,u.nom,u.prenom,u.email FROM taches_utilisateurs ta JOIN users u ON u.id=ta.id_utilisateur WHERE ta.id_tache=?");
    $ass_stmt->execute([$id]);
    $assignes = $ass_stmt->fetchAll(PDO::FETCH_ASSOC);

    $doc_stmt = $pdo->prepare("SELECT d.*,u.nom,u.prenom FROM taches_documents d JOIN users u ON u.id=d.ajoute_par WHERE d.id_tache=? ORDER BY d.date_ajout DESC");
    $doc_stmt->execute([$id]);
    $docs = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);

    $jrn_stmt = $pdo->prepare("SELECT j.*,u.nom,u.prenom FROM taches_journal j LEFT JOIN users u ON u.id=j.id_user WHERE j.id_tache=? ORDER BY j.date_action DESC LIMIT 30");
    $jrn_stmt->execute([$id]);
    $journal = $jrn_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$all_users = $pdo->query("SELECT id,nom,prenom FROM users WHERE actif=1 ORDER BY nom,prenom")->fetchAll(PDO::FETCH_ASSOC);
$immeubles = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$PRIO_COLOR = ['critique'=>'#8a5040','haute'=>'#7a6830','normale'=>'#4878a6','basse'=>'#808080'];
$PRIO_BG    = ['critique'=>'#fce8e8','haute'=>'#f8eddc','normale'=>'#d8e8f5','basse'=>'#e8e8e8'];
$STAT_COLOR = ['à faire'=>'#9a9690','en cours'=>'#4878a6','en attente'=>'#7a6830','terminee'=>'#3a7a6a','archivee'=>'var(--shadow-dark,#d4d7de)'];
$STAT_BG    = ['à faire'=>'var(--bg-primary,#e4e8f0)','en cours'=>'#d8e8f5','en attente'=>'#f8eddc','terminee'=>'#d8eee3','archivee'=>'#f0eee8'];
$STAT_LBL   = ['à faire'=>'À faire','en cours'=>'En cours','en attente'=>'En attente','terminee'=>'Terminée','archivee'=>'Archivée'];

$canEdit  = ($roleId <= 2 || (!$isNew && (int)($tache['id_createur']??0) === $userId));
$editMode = isset($_GET['edit']) || $isNew;
$curStatut  = $tache['statut'] ?? 'à faire';
$curPriorite= $tache['priorite'] ?? 'normale';

// ── Layout ──
$layout_title    = $isNew ? 'Nouvelle tâche' : ($tache['titre'] ?? 'Tâche');
$layout_module   = 'Ma Box Agency';
$layout_sidebar  = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.($id ? '#'.$id : 'NEW').'</div><div class="ph-kpi-lbl">ID</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:'.($PRIO_COLOR[$curPriorite] ?? '#808080').'">'.ucfirst($curPriorite).'</div><div class="ph-kpi-lbl">Priorité</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:'.($STAT_COLOR[$curStatut] ?? '#9a9690').'">'.($STAT_LBL[$curStatut] ?? $curStatut).'</div><div class="ph-kpi-lbl">Statut</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($assignes).'</div><div class="ph-kpi-lbl">Assignés</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.count($docs).'</div><div class="ph-kpi-lbl">Docs</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.(!empty($tache['date_echeance']) ? date('d/m', strtotime($tache['date_echeance'])) : '—').'</div><div class="ph-kpi-lbl">Échéance</div></div>
';

$layout_head_actions = '
    <a href="agency_taches.php" class="ph-btn">← Liste</a>
    '.(!$isNew && $canEdit && !$editMode
        ? '<a href="agency_tache_detail.php?id='.$id.'&edit=1" class="ph-btn primary">Modifier</a>'
        : '<a class="ph-btn dispo">Modifier</a>').'
    <a href="agency_tache_detail.php?new=1" class="ph-btn">Nouvelle</a>
    <a class="ph-btn dispo">—</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.det-grid{display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start}
.det-card{background:var(--bg-primary,#e4e8f0);border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px #ffffff;padding:20px 24px;margin-bottom:18px}
.det-card-title{font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#2c2a28;margin-bottom:14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e4e6ec;padding-bottom:10px}
.det-card-title svg{width:15px;height:15px;stroke:#4878a6;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.fg label{font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;letter-spacing:.1em}
.finput,.fselect,.ftextarea{background:var(--bg-secondary,#eef1f6);border:none;border-radius:10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:9px 13px;font-family:'Sora',sans-serif;font-size:13px;color:#2c2a28;width:100%;transition:box-shadow .2s}
.finput:focus,.fselect:focus,.ftextarea:focus{outline:none;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee,0 0 0 3px rgba(72,120,166,.3)}
.ftextarea{resize:vertical;min-height:80px}
.form-2col{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px #ffffff;color:#4a4844;transition:box-shadow .15s}
.btn-primary{background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff}
.btn-secondary{background:var(--bg-primary,#e4e8f0)}
.btn svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;text-decoration:none;border:none;cursor:pointer;background:var(--bg-primary,#e4e8f0);box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 7px #ffffff;transition:box-shadow .15s;color:#4878a6}
/* Chat */
.chat-wrap{height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:10px;margin-bottom:12px;padding:4px 2px;scrollbar-width:thin;scrollbar-color:var(--shadow-dark,#d4d7de) transparent}
.chat-msg{display:flex;gap:9px;align-items:flex-start}
.chat-msg.mine{flex-direction:row-reverse}
.chat-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#8eb4d3,#4878a6);display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:10px;font-weight:700;color:#fff;flex-shrink:0}
.chat-bubble{max-width:75%;background:var(--bg-secondary,#eef1f6);border-radius:12px;padding:8px 12px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee}
.chat-msg.mine .chat-bubble{background:#d8e8f5;box-shadow:inset 2px 2px 5px #b8c8d8,inset -2px -2px 5px #f0f8ff}
.chat-author{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690;margin-bottom:3px}
.chat-text{font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;line-height:1.5;word-break:break-word}
.chat-time{font-family:'DM Mono',monospace;font-size:9px;color:var(--shadow-dark,#d4d7de);margin-top:3px;text-align:right}
.chat-del{background:none;border:none;cursor:pointer;color:var(--shadow-dark,#d4d7de);font-size:11px;padding:0 2px;opacity:0;transition:opacity .15s}
.chat-msg:hover .chat-del{opacity:1}
.chat-input-row{display:flex;gap:8px;align-items:flex-end}
.chat-input{flex:1;background:var(--bg-secondary,#eef1f6);border:none;border-radius:12px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:10px 14px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;resize:none;min-height:40px;max-height:100px;outline:none}
.chat-send{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#6898bf,#4878a6);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de)}
.chat-send svg{width:15px;height:15px;stroke:#fff;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
/* Docs */
.doc-row{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;background:var(--bg-secondary,#eef1f6);box-shadow:inset 2px 2px 4px #cac6c0,inset -2px -2px 4px #f8f4ee;margin-bottom:6px}
.doc-icon{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#8eb4d3,#4878a6);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.doc-icon svg{width:15px;height:15px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.doc-name{font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#1a1816;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.doc-meta{font-family:'DM Mono',monospace;font-size:9px;color:#9a9690}
/* Journal */
.jrn-row{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #ece8e2;align-items:flex-start}
.jrn-row:last-child{border-bottom:none}
.jrn-dot{width:8px;height:8px;border-radius:50%;background:#4878a6;flex-shrink:0;margin-top:4px}
.jrn-msg{font-family:'Sora',sans-serif;font-size:11px;color:#4a4844;flex:1}
.jrn-time{font-family:'DM Mono',monospace;font-size:9px;color:var(--shadow-dark,#d4d7de);white-space:nowrap}
/* Statut boutons */
.stat-btn{display:flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;border:none;cursor:pointer;width:100%;margin-bottom:6px;transition:opacity .15s;text-align:left}
.stat-btn:hover{opacity:.85}
/* Participants */
.parts-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px}
.part-chk{display:flex;align-items:center;gap:7px;padding:6px 9px;border-radius:9px;background:var(--bg-secondary,#eef1f6);box-shadow:inset 1px 1px 3px #cac6c0,inset -1px -1px 3px #f8f4ee;cursor:pointer;font-family:'Sora',sans-serif;font-size:11px;color:#2c2a28}
.part-chk input{accent-color:#4878a6}
/* Toast */
#stoa{position:fixed;bottom:20px;right:20px;background:#2c2a28;color:#fff;border-radius:12px;padding:10px 18px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;display:none;z-index:999;box-shadow:0 4px 16px rgba(0,0,0,.2)}
/* Drop zone */
.drop-zone{border:2px dashed var(--shadow-dark,#d4d7de);border-radius:12px;padding:14px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;font-family:'DM Mono',monospace;font-size:11px;color:#9a9690}
.drop-zone.drag-over,.drop-zone:hover{border-color:#4878a6;background:#d8e8f522}
.title-hl{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:700;color:#1a1816;margin-bottom:16px}
</style>
EXTRACSS;

// ── Contenu ──
ob_start();
?>

<?php if (!$isNew): ?>
<div class="title-hl">
    <?= h($tache['titre'] ?? '') ?>
    <span id="statut-badge" style="background:<?= $STAT_BG[$curStatut] ?? 'var(--bg-primary,#e4e8f0)' ?>;color:<?= $STAT_COLOR[$curStatut] ?? '#9a9690' ?>;padding:3px 12px;border-radius:999px;font-size:12px;font-weight:700;font-family:'DM Mono',monospace">
        <?= $STAT_LBL[$curStatut] ?? $curStatut ?>
    </span>
</div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
<div style="background:#d8eee3;color:#3a7a6a;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">✅ Tâche enregistrée.</div>
<?php endif; ?>
<?php if (isset($_GET['notif'])): ?>
<div style="background:<?= $_GET['notif']==='ok'?'#d8eee3':'#fce8e8' ?>;color:<?= $_GET['notif']==='ok'?'#3a7a6a':'#8a5040' ?>;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">
    <?= $_GET['notif']==='ok' ? '✅ Notification envoyée.' : '❌ Erreur lors de l\'envoi.' ?>
</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div style="background:#fce8e8;color:#8a5040;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px"><?= implode('<br>',array_map('htmlspecialchars',$errors)) ?></div>
<?php endif; ?>

<div id="stoa">✅ Sauvegardé</div>

<?php if ($isNew || $editMode): ?>
<!-- ══ FORMULAIRE CRÉATION / ÉDITION ══════════════════════════ -->
<form method="POST" id="frmTache">
    <input type="hidden" name="save_tache" value="1">
    <div class="det-grid">
        <div>
            <div class="det-card">
                <div class="det-card-title">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Informations
                </div>
                <div class="fg">
                    <label>Titre *</label>
                    <input type="text" name="titre" class="finput" value="<?= h($tache['titre'] ?? $_POST['titre'] ?? '') ?>" required placeholder="Titre de la tâche">
                </div>
                <div class="fg">
                    <label>Description</label>
                    <textarea name="description" class="ftextarea" placeholder="Détail, contexte, instructions…"><?= h($tache['description'] ?? $_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-2col">
                    <div class="fg">
                        <label>Catégorie</label>
                        <select name="categorie" class="fselect">
                            <?php foreach ($CATEGORIES as $c): ?>
                            <option value="<?= $c ?>" <?= (($tache['categorie']??$_POST['categorie']??'autre')===$c)?'selected':'' ?>><?= ucfirst($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Priorité</label>
                        <select name="priorite" class="fselect">
                            <?php foreach (['basse'=>'Basse','normale'=>'Normale','haute'=>'Haute','critique'=>'Critique'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= (($tache['priorite']??$_POST['priorite']??'normale')===$v)?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Statut</label>
                        <select name="statut" class="fselect">
                            <?php foreach (['à faire'=>'À faire','en cours'=>'En cours','en attente'=>'En attente','terminee'=>'Terminée','archivee'=>'Archivée'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= (($tache['statut']??'à faire')===$v)?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Échéance</label>
                        <input type="date" name="date_echeance" class="finput" value="<?= h($tache['date_echeance'] ?? $_POST['date_echeance'] ?? '') ?>">
                    </div>
                    <div class="fg" style="grid-column:1/-1">
                        <label>Immeuble lié</label>
                        <select name="id_immeuble" class="fselect">
                            <option value="">— Aucun —</option>
                            <?php foreach ($immeubles as $im): ?>
                            <option value="<?= $im['id'] ?>" <?= (($tache['id_immeuble']??0)==$im['id'])?'selected':'' ?>><?= h($im['nom']) ?> (<?= h($im['reference']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div>
            <div class="det-card">
                <div class="det-card-title">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Assignés
                </div>
                <div class="parts-grid">
                    <?php
                    $assigned_ids = array_column($assignes, 'id');
                    foreach ($all_users as $u):
                        $checked = in_array($u['id'], $assigned_ids) || ($isNew && $u['id'] === $userId);
                    ?>
                    <label class="part-chk">
                        <input type="checkbox" name="assignes[]" value="<?= $u['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                        <span><?= h(trim($u['prenom'].' '.$u['nom'])) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-bottom:10px">
                <a href="<?= $isNew ? 'agency_taches.php' : 'agency_tache_detail.php?id='.$id ?>" class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                    <?= $isNew ? 'Créer la tâche' : 'Enregistrer' ?>
                </button>
            </div>
        </div>
    </div>
</form>

<?php else: ?>
<!-- ══ VUE DÉTAIL ══════════════════════════════════════════════ -->
<div class="det-grid">

    <div>

        <!-- Infos -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Détails
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                <?php
                $fields = [
                    ['Catégorie',  ucfirst($tache['categorie'] ?? '—')],
                    ['Priorité',   ucfirst($tache['priorite']  ?? '—')],
                    ['Échéance',   $tache['date_echeance'] ? date('d/m/Y', strtotime($tache['date_echeance'])) : '—'],
                    ['Créée le',   $tache['date_creation'] ? date('d/m/Y', strtotime($tache['date_creation'])) : '—'],
                    ['Créé par',   trim(($tache['auteur_prenom']??'').' '.($tache['auteur_nom']??''))],
                    ['Immeuble',   $tache['imm_nom'] ? $tache['imm_nom'].' ('.$tache['imm_ref'].')' : '—'],
                ];
                foreach ($fields as [$lbl,$val]):
                ?>
                <div>
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:2px"><?= $lbl ?></div>
                    <div style="font-family:'Sora',sans-serif;font-size:13px;font-weight:500;color:#2c2a28"><?= htmlspecialchars($val) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($tache['description']): ?>
            <div style="border-top:1px solid #e4e6ec;padding-top:12px;margin-top:4px">
                <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;text-transform:uppercase;margin-bottom:6px">Description</div>
                <div style="font-family:'Sora',sans-serif;font-size:13px;color:#4a4844;line-height:1.7"><?= nl2br(h($tache['description'])) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Commentaires / Chat -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                Commentaires
            </div>
            <div class="chat-wrap" id="chat-list"></div>
            <div class="chat-input-row">
                <textarea class="chat-input" id="chat-input" placeholder="Écrire un commentaire… (Entrée pour envoyer)" rows="1"
                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendComment()}"
                    oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px'"></textarea>
                <button class="chat-send" onclick="sendComment()">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        </div>

        <!-- Documents -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Documents
                <span style="margin-left:auto;font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;font-weight:400"><?= count($docs) ?> fichier<?= count($docs)>1?'s':'' ?></span>
            </div>
            <div id="docs-list">
            <?php foreach ($docs as $doc):
                $extIco = in_array(strtolower(pathinfo($doc['nom_fichier'] ?? '',PATHINFO_EXTENSION)),['pdf']) ? '#8a5040' : '#4878a6';
            ?>
            <div class="doc-row" id="doc-<?= $doc['id'] ?>">
                <div class="doc-icon" style="background:linear-gradient(135deg,<?= $extIco ?>88,<?= $extIco ?>)">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="doc-name"><?= h($doc['nom_fichier'] ?? $doc['fichier'] ?? '') ?></div>
                    <div class="doc-meta"><?= number_format(($doc['taille'] ?? 0)/1024,1) ?> Ko · <?= h(trim(($doc['prenom']??'').' '.($doc['nom']??''))) ?> · <?= !empty($doc['date_creation']) ? date('d/m/Y', strtotime($doc['date_creation'])) : (!empty($doc['date_ajout']) ? date('d/m/Y', strtotime($doc['date_ajout'])) : '') ?></div>
                </div>
                <a href="<?= h($doc['chemin']) ?>" download class="btn-xs" title="Télécharger">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
                <?php if (($doc['ajoute_par'] ?? 0) == $userId || $roleId === 1): ?>
                <button class="btn-xs" onclick="delDoc(<?= $doc['id'] ?>)" style="color:#8a5040" title="Supprimer">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <!-- Upload -->
            <div class="drop-zone" id="drop-zone" onclick="document.getElementById('file-input').click()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9a9690" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:4px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <div>Cliquer ou déposer un fichier ici</div>
                <div style="font-size:10px;margin-top:2px">PDF, Word, Excel, images, ZIP — max 10 Mo</div>
            </div>
            <input type="file" id="file-input" style="display:none" onchange="uploadDoc(this.files[0])">
        </div>

        <!-- Journal -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Journal d'activité
            </div>
            <?php if (empty($journal)): ?>
            <div style="font-family:'DM Mono',monospace;font-size:11px;color:#9a9690;text-align:center;padding:10px 0">Aucune activité</div>
            <?php else: ?>
            <?php foreach ($journal as $j): ?>
            <div class="jrn-row">
                <div class="jrn-dot"></div>
                <div class="jrn-msg">
                    <?= h($j['message']) ?>
                    <span style="color:#9a9690"> — <?= h(trim(($j['prenom']??'').' '.($j['nom']??''))) ?></span>
                </div>
                <div class="jrn-time"><?= !empty($j['date_creation']) ? date('d/m H:i', strtotime($j['date_creation'])) : (!empty($j['date_action']) ? date('d/m H:i', strtotime($j['date_action'])) : '') ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- Sidebar droite -->
    <div>

        <?php if ($canEdit): ?>
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Changer le statut
            </div>
            <?php foreach (['à faire'=>'À faire','en cours'=>'En cours','en attente'=>'En attente','terminee'=>'Terminée','archivee'=>'Archivée'] as $sv=>$sl): ?>
            <button class="stat-btn <?= $curStatut===$sv?'stat-active':'' ?>"
                style="background:<?= $STAT_BG[$sv]??'var(--bg-primary,#e4e8f0)' ?>;color:<?= $STAT_COLOR[$sv]??'#9a9690' ?>;<?= $curStatut===$sv?'box-shadow:inset 2px 2px 5px rgba(0,0,0,.1)':'' ?>"
                onclick="changeStatut('<?= $sv ?>')">
                <?= $sl ?>
                <?php if ($curStatut===$sv): ?><span style="margin-left:auto">✓</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Assignés -->
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Assignés
            </div>
            <?php foreach ($assignes as $a):
                $ini = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));
            ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
                <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#8eb4d3,#4878a6);display:flex;align-items:center;justify-content:center;font-family:'DM Mono',monospace;font-size:10px;font-weight:700;color:#fff;flex-shrink:0"><?= h($ini) ?></div>
                <span style="font-family:'Sora',sans-serif;font-size:12px;font-weight:500;color:#2c2a28"><?= h(trim($a['prenom'].' '.$a['nom'])) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($assignes)): ?><div style="font-family:'DM Mono',monospace;font-size:11px;color:#9a9690">Non assignée</div><?php endif; ?>
        </div>

        <!-- Notif mail -->
        <?php if ($canEdit && !empty($assignes)): ?>
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Notification
            </div>
            <form method="POST">
                <input type="hidden" name="send_notif" value="1">
                <textarea name="msg_notif" class="ftextarea" placeholder="Message personnalisé (optionnel)…" style="min-height:55px;margin-bottom:8px"></textarea>
                <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center"
                    onclick="return confirm('Envoyer une notification aux <?= count($assignes) ?> assigné(s) ?')">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Envoyer notification
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Immeuble lié -->
        <?php if (!empty($tache['imm_nom'])): ?>
        <div class="det-card">
            <div class="det-card-title">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                Immeuble lié
            </div>
            <div style="font-weight:700;color:#1a1816"><?= h($tache['imm_nom']) ?></div>
            <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin-bottom:6px"><?= h($tache['imm_ref']) ?></div>
            <a href="agency_immeuble_fiche.php?id=<?= $tache['id_immeuble'] ?>" style="font-family:'DM Mono',monospace;font-size:10px;color:#4878a6;text-decoration:none">Fiche immeuble →</a>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<script>
const TASK_ID = <?= (int)$id ?>;
const MY_ID   = <?= (int)$userId ?>;
const IS_ADMIN = <?= $roleId <= 1 ? 'true' : 'false' ?>;

function toast(msg) {
    const t = document.getElementById('stoa');
    if (!t) return;
    t.textContent = msg || '✅ Sauvegardé';
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => t.style.display = 'none', 2200);
}

function changeStatut(ns) {
    fetch('agency_tache_detail.php?id=' + TASK_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'change_statut=1&new_statut='+encodeURIComponent(ns)
    }).then(r=>r.json()).then(d=>{
        if (d.ok) {
            const badge = document.getElementById('statut-badge');
            if (badge) {
                const labels = {'à faire':'À faire','en cours':'En cours','en attente':'En attente','terminee':'Terminée','archivee':'Archivée'};
                const colors = {'à faire':'#9a9690','en cours':'#4878a6','en attente':'#7a6830','terminee':'#3a7a6a','archivee':'var(--shadow-dark,#d4d7de)'};
                const bgs    = {'à faire':'var(--bg-primary,#e4e8f0)','en cours':'#d8e8f5','en attente':'#f8eddc','terminee':'#d8eee3','archivee':'#f0eee8'};
                badge.textContent = labels[ns] || ns;
                badge.style.color = colors[ns] || '#9a9690';
                badge.style.background = bgs[ns] || 'var(--bg-primary,#e4e8f0)';
            }
            document.querySelectorAll('.stat-btn').forEach(b => {
                const sv = b.getAttribute('onclick').match(/'([^']+)'/)?.[1];
                if (sv === ns) {
                    b.style.boxShadow = 'inset 2px 2px 5px rgba(0,0,0,.1)';
                    if (!b.querySelector('span')) b.innerHTML += '<span style="margin-left:auto">✓</span>';
                } else {
                    b.style.boxShadow = '';
                    const tick = b.querySelector('span');
                    if (tick) tick.remove();
                }
            });
            toast('Statut mis à jour');
        }
    });
}

function renderMsg(c) {
    const isMine = parseInt(c.id_utilisateur || c.MY_ID) === MY_ID;
    const ini = ((c.prenom||'').charAt(0) + (c.nom||'').charAt(0)).toUpperCase();
    const dt  = c.date_creation ? new Date(c.date_creation).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '';
    return `<div class="chat-msg ${isMine?'mine':''}" id="msg-${c.id}">
        <div class="chat-avatar">${ini}</div>
        <div>
            <div class="chat-bubble">
                <div class="chat-author">${(c.prenom||'')+' '+(c.nom||'')}</div>
                <div class="chat-text">${escHtml(c.contenu)}</div>
                <div class="chat-time">${dt} ${(IS_ADMIN || isMine) ? `<button class="chat-del" onclick="delComment(${c.id})" title="Supprimer">×</button>` : ''}</div>
            </div>
        </div>
    </div>`;
}
function escHtml(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

function loadComments() {
    fetch('agency_tache_detail.php?id='+TASK_ID+'&ajax_comments=1')
    .then(r=>r.json()).then(list=>{
        const el = document.getElementById('chat-list');
        if (!el) return;
        el.innerHTML = list.map(renderMsg).join('');
        el.scrollTop = el.scrollHeight;
    });
}

function sendComment() {
    const inp = document.getElementById('chat-input');
    const txt = inp.value.trim();
    if (!txt) return;
    inp.value = '';
    inp.style.height = 'auto';
    fetch('agency_tache_detail.php?id='+TASK_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_comment=1&contenu='+encodeURIComponent(txt)
    }).then(r=>r.json()).then(d=>{
        if (d.ok && d.comment) {
            const el = document.getElementById('chat-list');
            el.insertAdjacentHTML('beforeend', renderMsg(d.comment));
            el.scrollTop = el.scrollHeight;
        }
    });
}

function delComment(cid) {
    if (!confirm('Supprimer ce commentaire ?')) return;
    fetch('agency_tache_detail.php?id='+TASK_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'del_comment='+cid
    }).then(r=>r.json()).then(d=>{
        if (d.ok) document.getElementById('msg-'+cid)?.remove();
    });
}

const dropZone = document.getElementById('drop-zone');
if (dropZone) {
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault(); dropZone.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) uploadDoc(e.dataTransfer.files[0]);
    });
}
function uploadDoc(file) {
    if (!file) return;
    if (file.size > 10*1024*1024) { alert('Fichier trop volumineux (max 10 Mo)'); return; }
    const fd = new FormData();
    fd.append('doc_file', file);
    dropZone && (dropZone.textContent = '⬆ Upload en cours…');
    fetch('agency_tache_detail.php?id='+TASK_ID, { method:'POST', body:fd })
    .then(r=>r.json()).then(d=>{
        if (d.ok) {
            const list = document.getElementById('docs-list');
            list.insertAdjacentHTML('beforeend',
                `<div class="doc-row" id="doc-${d.id}">
                    <div class="doc-icon"><svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:#fff;fill:none;stroke-width:2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                    <div style="flex:1;min-width:0">
                        <div class="doc-name">${escHtml(d.nom)}</div>
                        <div class="doc-meta">Vient d'être ajouté</div>
                    </div>
                    <a href="${d.chemin}" download class="btn-xs">⬇</a>
                    <button class="btn-xs" onclick="delDoc(${d.id})" style="color:#8a5040">🗑</button>
                </div>`
            );
            toast('Document ajouté');
        } else { alert(d.msg || 'Erreur upload'); }
        if (dropZone) {
            dropZone.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9a9690" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:4px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><div>Cliquer ou déposer un fichier ici</div>';
        }
    });
}
function delDoc(did) {
    if (!confirm('Supprimer ce document ?')) return;
    fetch('agency_tache_detail.php?id='+TASK_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'del_doc='+did
    }).then(r=>r.json()).then(d=>{
        if (d.ok) { document.getElementById('doc-'+did)?.remove(); toast('Document supprimé'); }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (TASK_ID) {
        loadComments();
        setInterval(loadComments, 15000);
    }
});
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
