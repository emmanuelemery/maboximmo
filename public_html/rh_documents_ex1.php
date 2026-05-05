<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_doc_types.php';

require_login();

$pdo    = $GLOBALS['pdo'] ?? null;
$roleId = current_role_id();
$userId = current_user_id();

if (!$pdo) { http_response_code(500); exit('Erreur DB'); }

function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── S'assurer que les colonnes confidentiel/obligatoire existent ──────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM salaires_documents")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('confidentiel', $cols)) $pdo->exec("ALTER TABLE salaires_documents ADD COLUMN confidentiel TINYINT DEFAULT 0");
    if (!in_array('obligatoire',  $cols)) $pdo->exec("ALTER TABLE salaires_documents ADD COLUMN obligatoire TINYINT DEFAULT 0");
} catch (Exception $e) {}

// ── Filtres STE / AGC ─────────────────────────────────────────────────────────
$agenceScope = can_manage_salaires_agence(); // 0 = admin/user, >0 = id_agence forcé

// Sociétés & agences (pour admin uniquement)
$societes = [];
$agences  = [];
if ($roleId === 1) {
    $societes = $pdo->query("SELECT id, nom FROM societes WHERE nom != 'Externe' ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
    $agences  = $pdo->query("SELECT id, nom_agence, id_societe FROM agences WHERE actif=1 ORDER BY nom_agence ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Sélections GET
$societe_sel = $_GET['societe'] ?? 'toutes';
$agence_sel  = $agenceScope > 0 ? (string)$agenceScope : ($_GET['agence'] ?? 'toutes');

// Agences filtrées selon société sélectionnée
$agences_filtered = ($societe_sel !== 'toutes')
    ? array_values(array_filter($agences, fn($a) => (string)$a['id_societe'] === (string)$societe_sel))
    : $agences;

// ── Liste utilisateurs filtrée par STE + AGC ─────────────────────────────────
$usersList = [];
if ($roleId === 1) {
    if ($agence_sel !== 'toutes') {
        $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE actif=1 AND id_agence=? ORDER BY nom, prenom");
        $st->execute([(int)$agence_sel]);
    } elseif ($societe_sel !== 'toutes') {
        $st = $pdo->prepare("SELECT u.id, u.prenom, u.nom FROM users u JOIN agences a ON a.id=u.id_agence WHERE u.actif=1 AND a.id_societe=? ORDER BY u.nom, u.prenom");
        $st->execute([(int)$societe_sel]);
    } else {
        $st = $pdo->query("SELECT id, prenom, nom FROM users WHERE actif=1 ORDER BY nom, prenom");
    }
    $usersList = $st->fetchAll(PDO::FETCH_ASSOC);
} elseif ($agenceScope > 0) {
    $st = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE actif=1 AND id_agence=? ORDER BY nom, prenom");
    $st->execute([$agenceScope]);
    $usersList = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── Utilisateur visualisé ─────────────────────────────────────────────────────
$viewUserId   = $userId;
$viewUserName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));

if (($roleId === 1 || $agenceScope > 0) && isset($_GET['user_id'])) {
    $candidate = (int)$_GET['user_id'];
    // Vérifier que le candidat est dans la liste autorisée
    foreach ($usersList as $u) {
        if ((int)$u['id'] === $candidate) {
            $viewUserId   = $candidate;
            $viewUserName = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
            break;
        }
    }
} else {
    // Par défaut : afficher les docs du user connecté (pas le 1er alphabétique)
    $viewUserId   = $userId;
    $viewUserName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
}

// ── Rubriques + types depuis DB ──────────────────────────────────────────────
$rubriquesMeta   = rh_doc_rubriques_meta();        // label + couleur (statique)
$typesByRubrique = rh_doc_types_load($pdo);        // types depuis rh_doc_types (avec seed auto)

// Construire le tableau $rubriques au format attendu par le template
$rubriques = [];
foreach ($rubriquesMeta as $rubKey => $meta) {
    $rubriques[$rubKey] = array_merge($meta, ['types' => []]);
    foreach ($typesByRubrique[$rubKey] ?? [] as $t) {
        $rubriques[$rubKey]['types'][] = [
            'key'          => $t['type_key'],
            'label'        => $t['label'],
            'obligatoire'  => (bool)$t['obligatoire'],
            'dispo'        => $t['dispo'] ?? 'public',
        ];
    }
}

// Map type_doc → label
$typeLabels = [];
foreach ($rubriques as $rubKey => $rub) {
    foreach ($rub['types'] as $t) {
        $typeLabels[$rubKey . '/' . $t['key']] = $t['label'];
    }
}

// ── Charger les documents de l'utilisateur ──────────────────────────
// Source unique : `rh_documents` — LA table de référence pour les docs
// RH collaborateur (identité, véhicule, RH). Les uploads depuis cette
// page ET depuis rh_profil.php convergent ici.
//
// Normalisation de la taxonomie `rh_profil.php` (carte_identite, permis_conduire,
// assurance_vehicule…) vers celle de `rh_doc_types` (cni, permis, assurance_veh…)
// pour que le template puisse mapper les docs aux bonnes cards de la page.
$stmtDocs = $pdo->prepare("
    SELECT
        id,
        CASE categorie
            WHEN 'identite' THEN 'personne'
            WHEN 'autre'    THEN 'divers'
            ELSE categorie
        END AS categorie,
        CASE type_document
            WHEN 'carte_identite'     THEN 'cni'
            WHEN 'passeport'          THEN 'cni'
            WHEN 'carte_secu'         THEN 'cni'
            WHEN 'permis_conduire'    THEN 'permis'
            WHEN 'assurance_vehicule' THEN 'assurance_veh'
            WHEN 'vehicule_autre'     THEN 'attestation_veh'
            WHEN 'contrat_travail'    THEN 'contrat'
            WHEN 'avenant'            THEN 'contrat'
            ELSE type_document
        END AS sous_categorie,
        original_name,
        filename,
        upload_date,
        uploaded_by,
        0 AS confidentiel,
        0 AS obligatoire
    FROM rh_documents
    WHERE id_user = ? AND actif = 1
    ORDER BY upload_date DESC
");
$stmtDocs->execute([$viewUserId]);
$allDocs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

// ── Documents administratifs société (table `documents`) ─────────────────────
// Injectés dans la rubrique "societe" pour que la colonne Société
// affiche aussi les Kbis, assurances, etc. chargés via admin_documents.php
$userSocieteId = (int)($_SESSION['id_societe'] ?? 0);
if ($userSocieteId > 0) {
    try {
        $stmtSocDocs = $pdo->prepare("
            SELECT id, type_document, nom_fichier, chemin_fichier, mime_type,
                   taille_octets, titre, date_creation, date_expiration, analysis_json
            FROM documents
            WHERE id_societe = ? AND categorie_document = 'administratif'
            ORDER BY type_document, date_creation DESC
        ");
        $stmtSocDocs->execute([$userSocieteId]);
        $socAdminDocs = $stmtSocDocs->fetchAll(PDO::FETCH_ASSOC);

        // Mapper vers le format attendu par le template
        $socTypeMap = [
            'kbis' => 'kbis', 'assurance_rcp' => 'rcp', 'assurance_locative' => 'assurance_soc',
            'carte_professionnelle' => 'entete', 'garantie_financiere' => 'assurance_soc',
            'rib' => 'kbis', 'inscription_insee' => 'kbis', 'bail_commercial' => 'convention',
            'contrat' => 'convention', 'attestation' => 'rcp', 'autre' => 'kbis',
        ];
        foreach ($socAdminDocs as $sd) {
            $allDocs[] = [
                'id'             => 'soc_' . $sd['id'],  // préfixe pour éviter collision d'ID
                'categorie'      => 'societe',
                'sous_categorie' => $socTypeMap[$sd['type_document']] ?? 'kbis',
                'original_name'  => $sd['titre'] ?: $sd['nom_fichier'],
                'filename'       => basename($sd['chemin_fichier']),
                'upload_date'    => $sd['date_creation'],
                'uploaded_by'    => null,
                'confidentiel'   => 0,
                'obligatoire'    => 0,
                '_is_societe_doc' => true,
                '_chemin'        => $sd['chemin_fichier'],
                '_analysis'      => $sd['analysis_json'] ?? null,
            ];
        }
    } catch (Throwable $e) { /* table pas migrée */ }
}

// Grouper par rubrique
$docsByRubrique = [];
foreach ($allDocs as $doc) {
    $rub = $doc['categorie'] ?? 'divers';
    if (!isset($docsByRubrique[$rub])) $docsByRubrique[$rub] = [];
    $docsByRubrique[$rub][] = $doc;
}

// ── Docs obligatoires manquants ───────────────────────────────────────────────
$missingObligatoires = [];
foreach ($rubriques as $rubKey => $rub) {
    foreach ($rub['types'] as $t) {
        if (!$t['obligatoire']) continue;
        if (!rh_doc_dispo_allowed($t['dispo'] ?? 'public', $roleId, $agenceScope, $userId === $viewUserId)) continue;
        $found = false;
        foreach ($docsByRubrique[$rubKey] ?? [] as $doc) {
            if ($doc['sous_categorie'] === $t['key']) { $found = true; break; }
        }
        if (!$found) $missingObligatoires[] = $rub['label'] . ' — ' . $t['label'];
    }
}

// ── Helper pour construire les URLs des pills ─────────────────────────────────
function pillUrl(array $override = []): string {
    $base = array_filter([
        'societe' => $_GET['societe'] ?? null,
        'agence'  => $_GET['agence']  ?? null,
        'user_id' => $_GET['user_id'] ?? null,
    ], fn($v) => $v !== null);
    return '?' . http_build_query(array_merge($base, $override));
}

$csrfToken    = csrf_token();
$current_page = 'documents';

// ══════════════════════════════════════════════════════════════════════════════
// Layout variables
// ══════════════════════════════════════════════════════════════════════════════

$layout_title   = 'Documents — ' . h($viewUserName);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

// KPIs
$totalDocs   = count($allDocs);
$missingCnt  = count($missingObligatoires);
$rubCount    = count($rubriques);
$layout_head_kpis = '
<div class="ph-kpi"><div class="ph-kpi-val">' . $totalDocs . '</div><div class="ph-kpi-lbl">Documents</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $missingCnt . '</div><div class="ph-kpi-lbl">Manquants</div></div>
<div class="ph-kpi"><div class="ph-kpi-val">' . $rubCount . '</div><div class="ph-kpi-lbl">Rubriques</div></div>
';

// Actions
$layout_head_actions = '
<a href="rh_documents.php" class="ph-btn primary">Documents</a>
' . ($roleId === 1 ? '<a href="rh_documents_config.php" class="ph-btn">Config</a>' : '<span class="ph-btn dispo">—</span>') . '
<span class="ph-btn dispo">—</span>
<span class="ph-btn dispo">—</span>
';

// Extra CSS
$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ── Override local supprimé — tokens.css est déjà en blanc ── */

    /* ── Page head (identique rh_salaires) ── */
    .page-head {
        display:flex; align-items:center;
        height:110px; flex-shrink:0; gap:0;
        border-bottom:1px solid rgba(196,192,186,0.3); margin-bottom:4px;
        padding:14px 0 12px; overflow:hidden;
    }
    /* Quand il n'y a pas de ph-scope (user simple), hauteur réduite */
    .page-head.no-scope { height:56px; }
    .page-head-module { font-family:'DM Mono',monospace; font-size:9px; text-transform:uppercase; letter-spacing:0.22em; color:#a8a49e; margin-bottom:4px; }
    .page-head-row { display:flex; align-items:center; gap:10px; }
    .page-head-title { font-family:'Sora'; font-size:20px; font-weight:700; color:#1a1816; }
    .page-head-user { font-family:'Sora'; font-size:16px; font-weight:400; color:#6a6660; }

    /* ── Scope pills (identique rh_salaires) ── */
    .ph-scope { display:flex; flex-direction:column; gap:11px; justify-content:center; min-width:0; }
    .ph-scope-row { display:flex; align-items:center; gap:15px; flex-wrap:wrap; }
    .ph-scope-label { font-family:'DM Mono',monospace; font-size:12px; font-weight:500; text-transform:uppercase; letter-spacing:0.10em; color:var(--shadow-dark); width:46px; flex-shrink:0; text-align:right; }
    .ph-scope-btns { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .ph-scope-pill { height:24px; padding:0 12px; border-radius:999px; background:var(--bg-primary); box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); font-family:'Sora',sans-serif; font-size:10px; font-weight:500; color:#8a8680; text-decoration:none; display:inline-flex; align-items:center; transition:box-shadow 0.12s,color 0.12s; white-space:nowrap; }
    .ph-scope-pill:hover { color:#36577d; }
    .ph-scope-pill.active { box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); color:#36577d; font-weight:700; }
    /* Row collaborateur : select quand trop de monde */
    .ph-scope-select { height:24px; padding:0 10px; border-radius:999px; background:var(--bg-primary); box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); border:none; font-family:'Sora',sans-serif; font-size:10px; color:#36577d; font-weight:600; cursor:pointer; outline:none; }

    /* ── Alerte manquants ── */
    .alert-manquants { background:var(--bg-primary); border-radius:14px; box-shadow:5px 5px 12px var(--shadow-dark),-5px -5px 12px var(--shadow-light); padding:14px 18px; border-left:4px solid #8a5040; }
    .alert-manquants-title { font-size:12px; font-weight:700; color:#8a5040; margin-bottom:8px; display:flex; align-items:center; gap:8px; }
    .alert-manquants-list { list-style:none; display:flex; flex-wrap:wrap; gap:6px; }
    .alert-manquants-list li { font-size:11px; background:rgba(138,80,64,0.1); color:#8a5040; border-radius:6px; padding:3px 10px; font-weight:500; }

    /* ── Grille rubriques ── */
    .rubriques-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:20px; }

    /* ── Card rubrique ── */
    .rub-card { background:var(--bg-primary); border-radius:18px; box-shadow:6px 6px 14px var(--shadow-dark),-6px -6px 14px var(--shadow-light); overflow:hidden; display:flex; flex-direction:column; }
    .rub-card-head { display:flex; align-items:center; gap:10px; padding:14px 18px 12px; border-bottom:1px solid rgba(196,192,186,0.3); }
    .rub-card-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
    .rub-card-title { font-size:13px; font-weight:700; color:#1a1816; flex:1; }
    .rub-card-count { font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; }
    .rub-card-body { padding:12px 16px; flex:1; display:flex; flex-direction:column; gap:8px; min-height:40px; }
    .rub-card-foot { padding:10px 16px 14px; display:flex; justify-content:flex-end; }

    /* ── Bouton ajouter ── */
    .btn-add-doc { display:flex; align-items:center; gap:6px; padding:7px 14px; background:var(--bg-primary); border:none; border-radius:10px; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); font-family:'Sora',sans-serif; font-size:11px; font-weight:600; color:#4a6038; cursor:pointer; }
    .btn-add-doc:active { box-shadow:inset 2px 2px 6px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); }
    .btn-add-doc svg { width:13px; height:13px; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; }

    /* ── Mini-card document ── */
    .doc-minicard { display:flex; align-items:center; gap:10px; padding:9px 12px; background:var(--bg-primary); border-radius:12px; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); }
    .doc-minicard-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:16px; }
    .doc-minicard-body { flex:1; min-width:0; }
    .doc-minicard-name { font-size:11.5px; font-weight:600; color:#1a1816; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .doc-minicard-meta { font-size:10px; color:#a8a49e; font-family:'DM Mono',monospace; margin-top:2px; display:flex; align-items:center; flex-wrap:wrap; gap:5px; }
    .badge-oblig { font-size:9px; padding:1px 6px; border-radius:999px; font-weight:600; background:rgba(138,80,64,0.12); color:#8a5040; }
    .badge-conf  { font-size:9px; padding:1px 6px; border-radius:999px; font-weight:600; background:rgba(54,87,125,0.12); color:#36577d; }
    .doc-minicard-actions { display:flex; align-items:center; gap:5px; flex-shrink:0; }
    .doc-icon-btn { width:28px; height:28px; border-radius:8px; background:var(--bg-primary); box-shadow:2px 2px 6px var(--shadow-dark),-2px -2px 5px var(--shadow-light); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; text-decoration:none; }
    .doc-icon-btn:active { box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light); }
    .doc-icon-btn svg { width:13px; height:13px; stroke:#8a8680; fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .doc-icon-btn.del svg { stroke:#8a5040; }
    .doc-icon-btn.archive svg { stroke:#c97b2e; }
    .doc-icon-btn.view svg { stroke:#36577d; }

    /* ── Type manquant ── */
    .doc-missing { display:flex; align-items:center; gap:10px; padding:7px 12px; border-radius:10px; background:rgba(138,80,64,0.06); border:1px dashed rgba(138,80,64,0.3); }
    .doc-missing-label { font-size:11px; color:#8a5040; flex:1; font-weight:500; }
    .doc-missing-btn { font-size:10px; padding:4px 10px; background:var(--bg-primary); border:none; border-radius:8px; box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light); color:#4a6038; font-weight:600; cursor:pointer; font-family:'Sora',sans-serif; }
    .doc-missing-btn:active { box-shadow:inset 1px 1px 4px var(--shadow-dark),inset -1px -1px 3px var(--shadow-light); }
    .rub-empty { font-size:11px; color:#a8a49e; font-style:italic; padding:4px 0; }

    /* ── Modal upload ── */
    .modal-overlay { position:fixed; inset:0; background:rgba(26,24,22,0.45); z-index:500; display:none; align-items:center; justify-content:center; }
    .modal-overlay.active { display:flex; }
    .modal-box { background:var(--bg-primary); border-radius:20px; box-shadow:10px 10px 30px rgba(26,24,22,0.25),-6px -6px 20px rgba(255,255,255,0.8); padding:28px 32px; width:460px; max-width:95vw; max-height:90vh; overflow-y:auto; display:flex; flex-direction:column; gap:18px; }
    .modal-title { font-size:16px; font-weight:700; color:#1a1816; }
    .form-group { display:flex; flex-direction:column; gap:6px; }
    .form-label { font-size:11px; font-weight:600; color:#6a6660; text-transform:uppercase; letter-spacing:0.08em; }
    .form-control { background:var(--bg-primary); border:none; border-radius:10px; box-shadow:inset 3px 3px 7px var(--shadow-dark),inset -3px -3px 8px var(--shadow-light); padding:9px 14px; font-family:'Sora',sans-serif; font-size:13px; color:#1a1816; outline:none; width:100%; }
    .form-control:focus { box-shadow:inset 4px 4px 9px var(--shadow-dark),inset -4px -4px 10px var(--shadow-light),0 0 0 2px rgba(74,96,56,0.25); }
    .drop-zone { border:2px dashed rgba(74,96,56,0.3); border-radius:12px; padding:20px; text-align:center; cursor:pointer; transition:background 0.15s,border-color 0.15s; background:rgba(74,96,56,0.03); }
    .drop-zone.dragover { background:rgba(74,96,56,0.08); border-color:rgba(74,96,56,0.6); }
    .drop-zone-txt { font-size:12px; color:#8a8680; line-height:1.5; }
    .drop-zone-txt strong { color:#4a6038; display:block; font-size:13px; margin-bottom:4px; }
    .file-preview { font-size:12px; color:#4a6038; font-weight:600; margin-top:8px; }
    .modal-actions { display:flex; gap:10px; justify-content:flex-end; }
    .btn-submit { padding:10px 22px; background:#4a6038; color:var(--bg-secondary); border:none; border-radius:12px; font-family:'Sora',sans-serif; font-size:13px; font-weight:600; cursor:pointer; box-shadow:3px 3px 8px rgba(74,96,56,0.3); }
    .btn-submit:hover { opacity:0.9; }
    .btn-submit:disabled { opacity:0.5; cursor:not-allowed; }
    .btn-cancel { padding:10px 18px; background:var(--bg-primary); border:none; border-radius:12px; font-family:'Sora',sans-serif; font-size:13px; color:#8a8680; cursor:pointer; box-shadow:3px 3px 8px var(--shadow-dark),-3px -3px 8px var(--shadow-light); }

    /* ── Toast ── */
    #doc-toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
    .toast-item { background:var(--bg-primary); border-radius:12px; box-shadow:6px 6px 16px rgba(26,24,22,0.18); padding:12px 18px; font-size:12px; font-weight:600; border-left:4px solid #4a6038; color:#1a1816; animation:toastIn 0.25s ease; }
    .toast-item.err { border-left-color:#8a5040; color:#8a5040; }
    @keyframes toastIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
</style>
EXTRACSS;

// Extra JS
$_rubriquesJson = json_encode(
    array_map(fn($r) => ['label' => $r['label'], 'types' => $r['types']], $rubriques),
    JSON_UNESCAPED_UNICODE
);
$layout_extra_js = <<<EXTRAJS
<meta name="csrf-token" content="{$csrfToken}">
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const VIEW_USER_ID = {$viewUserId};

const RUBRIQUES = {$_rubriquesJson};

// ── Modal ─────────────────────────────────────────────────────────────────────
let selectedFile = null;

function openModal(rubrique = 'personne', typeDoc = null) {
    document.getElementById('modal-rubrique').value = rubrique;
    onRubChange(typeDoc);
    document.getElementById('modal-nom').value = '';
    selectedFile = null;
    document.getElementById('file-preview').style.display = 'none';
    document.getElementById('dz-txt').style.display = '';
    document.getElementById('upload-modal').classList.add('active');
}

function closeModal() { document.getElementById('upload-modal').classList.remove('active'); }

function onRubChange(forceType = null) {
    const rubKey = document.getElementById('modal-rubrique').value;
    const sel    = document.getElementById('modal-type');
    sel.innerHTML = '';
    (RUBRIQUES[rubKey]?.types || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.key;
        const dispoTag = t.dispo === 'admin' ? ' 🔒' : (t.dispo === 'manager' ? ' 👁' : '');
        opt.textContent = t.label + (t.obligatoire ? ' *' : '') + dispoTag;
        sel.appendChild(opt);
    });
    if (forceType) sel.value = forceType;
}

// ── Drop zone ─────────────────────────────────────────────────────────────────
function onFileSelect(i) { if (i.files?.[0]) setFile(i.files[0]); }
function dzOver(e) { e.preventDefault(); document.getElementById('drop-zone').classList.add('dragover'); }
function dzLeave(e) { document.getElementById('drop-zone').classList.remove('dragover'); }
function dzDrop(e) { e.preventDefault(); document.getElementById('drop-zone').classList.remove('dragover'); if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); }
function setFile(f) {
    selectedFile = f;
    const p = document.getElementById('file-preview');
    p.textContent = '📎 ' + f.name + ' (' + (f.size/1024).toFixed(0) + ' Ko)';
    p.style.display = 'block';
    document.getElementById('dz-txt').style.display = 'none';
}

// ── Upload ────────────────────────────────────────────────────────────────────
async function submitUpload() {
    if (!selectedFile) { showToast('Veuillez sélectionner un fichier', true); return; }
    const btn = document.getElementById('btn-upload');
    btn.disabled = true; btn.textContent = 'Envoi…';
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('file', selectedFile);
    fd.append('user_id', VIEW_USER_ID);
    fd.append('rubrique', document.getElementById('modal-rubrique').value);
    fd.append('type_doc',  document.getElementById('modal-type').value);
    fd.append('nom_affiche', document.getElementById('modal-nom').value.trim() || selectedFile.name);
    showToast('🔍 Analyse IA en cours (5-15 sec)…');
    btn.textContent = '⏳ Analyse IA…';
    try {
        const r = await fetch('api/rh_doc_upload.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            showToast('Document ajouté');
            // Feedback IA : si le pipeline a appliqué des champs au profil
            if (j.rhdx && j.rhdx.type) {
                const applied    = j.rhdx.applied    || {};
                const candidates = j.rhdx.candidates || {};
                const nbApplied    = Object.keys(applied).length;
                const nbCandidates = Object.keys(candidates).length;
                if (nbApplied > 0) {
                    setTimeout(() => showToast('✅ ' + nbApplied + ' champ(s) appliqué(s) au profil (' + j.rhdx.type + ')'), 400);
                }
                if (nbCandidates > 0) {
                    setTimeout(() => showToast('⚠ ' + nbCandidates + ' champ(s) en conflit — voir la page profil pour résoudre'), 900);
                }
            }
            closeModal();
            setTimeout(() => location.reload(), 2200);
        }
        else showToast(j.message || 'Erreur upload', true);
    } catch { showToast('Erreur réseau', true); }
    btn.disabled = false; btn.textContent = 'Téléverser';
}

// ── Supprimer ─────────────────────────────────────────────────────────────────
async function deleteDoc(docId, btn) {
    if (!confirm('Supprimer ce document définitivement ?')) return;
    btn.disabled = true;
    try {
        const r = await fetch('api/delete_user_doc.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ doc_id: docId }),
        });
        const j = await r.json();
        if (j.success) {
            showToast('Document supprimé');
            const card = btn.closest('.doc-minicard');
            if (card) {
                const rubCard = card.closest('.rub-card');
                card.remove();
                if (rubCard) {
                    const nb = rubCard.querySelectorAll('.doc-minicard').length;
                    const cnt = rubCard.querySelector('.rub-count');
                    if (cnt) cnt.textContent = nb + ' doc' + (nb !== 1 ? 's' : '');
                }
            }
        } else showToast(j.message || 'Erreur', true);
    } catch { showToast('Erreur réseau', true); }
    btn.disabled = false;
}

// ── Archiver ─────────────────────────────────────────────────────────────────
async function archiveDoc(docId, btn) {
    if (!confirm('Archiver ce document ? Il sera déplacé dans les archives.')) return;
    btn.disabled = true;
    try {
        const r = await fetch('api/rh_doc_archive.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ doc_id: docId, action: 'archive' }),
        });
        const j = await r.json();
        if (j.success) {
            showToast('📦 Document archivé');
            const card = btn.closest('.doc-minicard');
            if (card) {
                const rubCard = card.closest('.rub-card');
                card.remove();
                if (rubCard) {
                    const nb = rubCard.querySelectorAll('.doc-minicard').length;
                    const cnt = rubCard.querySelector('.rub-count');
                    if (cnt) cnt.textContent = nb + ' doc' + (nb !== 1 ? 's' : '');
                }
            }
        } else showToast(j.message || 'Erreur', true);
    } catch { showToast('Erreur réseau', true); }
    btn.disabled = false;
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, isErr = false) {
    const el = document.createElement('div');
    el.className = 'toast-item' + (isErr ? ' err' : '');
    el.textContent = msg;
    document.getElementById('doc-toast').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

onRubChange();
</script>
EXTRAJS;

// ══════════════════════════════════════════════════════════════════════════════
// Content
// ══════════════════════════════════════════════════════════════════════════════
ob_start();
?>

<!-- ══════════════════════════════════════════════════════════
     ANALYSE AUTOMATIQUE DE DOCUMENT (Phase 2 — pipeline OCR/IA)
     Section drag & drop appelant api/rh_user_doc_extract.php
     qui route automatiquement vers le bon extracteur
     (RIB, CNI, justif domicile, carte vitale, carte grise…)
════════════════════════════════════════════════════════════ -->
<style>
.rhdx-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 6px 6px 14px #d4d7de, -6px -6px 14px #fff;
    padding: 22px 26px;
    margin-bottom: 22px;
}
.rhdx-head { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.rhdx-head-ico {
    width: 46px; height: 46px; border-radius: 12px;
    background: linear-gradient(135deg, #4878a6, #2f587d);
    color: #fff; font-size: 22px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.rhdx-head h3 { font-size: 15px; font-weight: 700; color: #2f587d; margin-bottom: 3px; }
.rhdx-head p  { font-size: 12px; color: #8a8680; line-height: 1.5; }

.rhdx-dropzone {
    border: 2px dashed #d4d7de;
    border-radius: 12px;
    padding: 32px 20px;
    text-align: center;
    background: #ffffff;
    cursor: pointer;
    transition: all .2s;
}
.rhdx-dropzone:hover,
.rhdx-dropzone.dragging {
    border-color: #4878a6;
    background: #fafbfc;
    transform: translateY(-2px);
}
.rhdx-dropzone-ico { font-size: 36px; opacity: .6; margin-bottom: 8px; }
.rhdx-dropzone-txt { font-size: 13px; color: #6a6660; font-weight: 600; }
.rhdx-dropzone-hint { font-size: 11px; color: #a8a49e; margin-top: 4px; }
.rhdx-dropzone input[type=file] { display: none; }

.rhdx-progress {
    display: none;
    padding: 16px;
    background: #f7f8fa;
    border-radius: 10px;
    margin-top: 14px;
    font-size: 12px;
    color: #2f587d;
    font-weight: 600;
}
.rhdx-progress.active { display: block; }
.rhdx-spinner {
    display: inline-block;
    width: 14px; height: 14px;
    border: 2px solid rgba(72,120,166,0.25);
    border-top-color: #2f587d;
    border-radius: 50%;
    animation: rhdxSpin 0.8s linear infinite;
    vertical-align: middle; margin-right: 8px;
}
@keyframes rhdxSpin { to { transform: rotate(360deg); } }

.rhdx-results { display: none; margin-top: 16px; }
.rhdx-results.active { display: block; }

.rhdx-result-card {
    background: #fff;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 10px;
    box-shadow: 2px 2px 8px rgba(180,185,175,0.3);
    border-left: 4px solid #4878a6;
}
.rhdx-result-card.failed  { border-left-color: #8a5040; }
.rhdx-result-card.success { border-left-color: #4a6038; }

.rhdx-result-head {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(196,192,186,0.3);
}
.rhdx-result-type {
    display: inline-block;
    padding: 3px 11px;
    border-radius: 999px;
    background: #e8efe0;
    color: #4a6038;
    font-family: 'DM Mono', monospace; font-size: 9px;
    font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
}
.rhdx-result-name {
    flex: 1;
    font-size: 13px; font-weight: 600; color: #2f587d;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.rhdx-result-score {
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #7a9060; font-weight: 700;
}

.rhdx-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 6px 14px; }
.rhdx-field {
    display: flex; align-items: baseline; gap: 6px;
    padding: 4px 0;
    font-size: 12px;
    border-bottom: 1px dotted rgba(196,192,186,0.25);
}
.rhdx-field-key {
    color: #8a8680; font-weight: 600;
    text-transform: capitalize;
    min-width: 110px;
}
.rhdx-field-val { color: #1a1816; font-weight: 500; word-break: break-word; flex: 1; }
.rhdx-field-ok  { color: #4a6038; font-size: 10px; }
.rhdx-field-err { color: #8a5040; font-size: 10px; }

.rhdx-engine {
    display: inline-block; margin-top: 8px;
    padding: 2px 8px; border-radius: 6px;
    font-family: 'DM Mono', monospace; font-size: 9px;
    background: rgba(138,80,64,0.08); color: #8a5040;
    letter-spacing: .04em; text-transform: uppercase;
}

.rhdx-applied {
    margin-top: 10px; padding: 10px 14px;
    background: rgba(74,96,56,0.08);
    border-radius: 8px;
    border-left: 3px solid #4a6038;
    font-size: 12px;
}
.rhdx-applied-title {
    font-weight: 700; color: #4a6038;
    text-transform: uppercase; font-size: 10px; letter-spacing: .06em;
    margin-bottom: 6px;
}
.rhdx-applied-list { color: #4a6038; font-size: 11px; line-height: 1.6; }
.rhdx-applied-list code {
    background: rgba(74,96,56,0.12); padding: 1px 6px; border-radius: 4px;
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #2f4a1f;
}

.rhdx-candidates {
    margin-top: 8px; padding: 10px 14px;
    background: rgba(201,123,46,0.06);
    border-radius: 8px;
    border-left: 3px solid #c97b2e;
    font-size: 12px;
}
.rhdx-candidates-title {
    font-weight: 700; color: #8a5a1a;
    text-transform: uppercase; font-size: 10px; letter-spacing: .06em;
    margin-bottom: 6px;
}
.rhdx-candidates-list { color: #6a4010; font-size: 11px; line-height: 1.5; }
.rhdx-resolve-btn {
    margin-top: 8px; padding: 8px 16px; border: none; border-radius: 8px;
    background: linear-gradient(135deg, #c97b2e, #e8a040); color: #fff;
    font-weight: 700; font-size: 12px; cursor: pointer;
    transition: transform .15s, box-shadow .15s;
}
.rhdx-resolve-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(201,123,46,0.3); }
.conflict-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 9999;
    display: flex; align-items: center; justify-content: center;
    animation: fadeIn .2s;
}
.conflict-modal {
    background: #fff; border-radius: 16px; padding: 28px 24px;
    width: 95%; max-width: 480px; max-height: 85vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.conflict-modal h3 { margin: 0 0 16px; font-size: 16px; color: #2f587d; }
.conflict-row {
    margin-bottom: 16px; padding: 14px; background: #f8f6f2; border-radius: 12px;
    border: 1px solid #e8e4de;
}
.conflict-field-name {
    font-size: 11px; font-weight: 700; color: #8a5a1a;
    text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px;
}
.conflict-options { display: flex; flex-direction: column; gap: 6px; }
.conflict-option {
    display: flex; align-items: center; gap: 10px; padding: 8px 12px;
    border-radius: 8px; border: 2px solid #e0dcd6; cursor: pointer;
    transition: border-color .15s, background .15s;
}
.conflict-option:hover { border-color: #c97b2e; background: rgba(201,123,46,0.04); }
.conflict-option.selected { border-color: #4a6038; background: rgba(74,96,56,0.06); }
.conflict-option input[type="radio"] { accent-color: #4a6038; flex-shrink: 0; }
.conflict-option-tag {
    font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px;
    text-transform: uppercase; letter-spacing: .04em;
}
.conflict-option-tag.current { background: #e0dcd6; color: #6a6050; }
.conflict-option-tag.new { background: #e8efe0; color: #4a6038; }
.conflict-edit {
    width: 100%; margin-top: 6px; padding: 6px 10px; border: 1px solid #d0ccc6;
    border-radius: 6px; font-size: 13px; font-family: inherit; display: none;
}
.conflict-option.selected .conflict-edit { display: block; }
.conflict-actions {
    display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px;
}
.conflict-btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
}
.conflict-btn.cancel { background: #e8e4de; color: #6a6050; }
.conflict-btn.apply {
    background: linear-gradient(135deg, #4a6038, #5a7a48); color: #fff;
    box-shadow: 0 4px 12px rgba(74,96,56,0.2);
}
.conflict-btn.apply:hover { transform: translateY(-1px); }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.rhdx-error {
    color: #8a5040; font-size: 12px; padding: 10px 14px;
    background: rgba(138,80,64,0.06); border-radius: 8px;
}
</style>

<div class="rhdx-card">
    <div class="rhdx-head">
        <div class="rhdx-head-ico">🔍</div>
        <div>
            <h3>Analyse automatique de document</h3>
            <p>Déposez un document (CNI, RIB, justificatif de domicile, carte vitale, carte grise…) et l'IA extrait automatiquement les informations.</p>
        </div>
    </div>

    <div class="rhdx-dropzone" id="rhdxDropzone">
        <div class="rhdx-dropzone-ico">📥</div>
        <div class="rhdx-dropzone-txt">Cliquez ou glissez un document ici</div>
        <div class="rhdx-dropzone-hint">PDF, JPG, PNG, WEBP · max 10 Mo</div>
        <input type="file" id="rhdxFileInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">
    </div>

    <div class="rhdx-progress" id="rhdxProgress">
        <span class="rhdx-spinner"></span>
        <span id="rhdxProgressLabel">Analyse en cours…</span>
    </div>

    <div class="rhdx-results" id="rhdxResults"></div>
</div>

<script>
(function() {
    const dropzone  = document.getElementById('rhdxDropzone');
    const fileInput = document.getElementById('rhdxFileInput');
    const progress  = document.getElementById('rhdxProgress');
    const label     = document.getElementById('rhdxProgressLabel');
    const results   = document.getElementById('rhdxResults');

    // ── Drag & drop events ──
    dropzone.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) handleFile(fileInput.files[0]);
    });
    ['dragenter','dragover'].forEach(ev => {
        dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('dragging'); });
    });
    ['dragleave','drop'].forEach(ev => {
        dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('dragging'); });
    });
    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
    });

    // ── Upload + analyse ──
    async function handleFile(file) {
        progress.classList.add('active');
        label.textContent = '📤 Upload : ' + file.name + ' (' + (file.size/1024/1024).toFixed(1) + ' Mo)…';

        const fd = new FormData();
        fd.append('fichier', file);
        fd.append('csrf_token', CSRF);

        try {
            label.textContent = '🔍 Extraction IA en cours…';
            const res = await fetch('api/rh_user_doc_extract.php', {
                method: 'POST',
                body: fd,
            });
            const data = await res.json();
            progress.classList.remove('active');
            renderResult(data, file);
        } catch (err) {
            progress.classList.remove('active');
            renderError('Erreur réseau : ' + err.message);
        }
    }

    // ── Rendu des résultats ──
    const TYPE_LABELS = {
        rib: 'RIB',
        cni: 'Carte identité',
        passeport: 'Passeport',
        justif_domicile: 'Justif. domicile',
        carte_vitale: 'Carte Vitale',
        mutuelle: 'Mutuelle',
        titre_sejour: 'Titre séjour',
        permis: 'Permis',
        carte_grise: 'Carte grise',
        assurance_vehicule: 'Assurance véhicule',
        unknown: 'Non reconnu',
    };

    function renderResult(data, file) {
        const card = document.createElement('div');
        card.className = 'rhdx-result-card ' + (data.ok ? 'success' : 'failed');

        if (!data.ok) {
            card.innerHTML = `
                <div class="rhdx-result-head">
                    <span class="rhdx-result-type" style="background:#f0e2da;color:#8a5040">Échec</span>
                    <div class="rhdx-result-name">${escapeHtml(file.name)}</div>
                </div>
                <div class="rhdx-error">⚠ ${escapeHtml(data.error || 'Erreur inconnue')}</div>
            `;
        } else {
            const type = data.doc_type || 'unknown';
            const fields = data.fields || {};
            const valid  = data.validations || {};

            let fieldsHtml = '';
            for (const [k, v] of Object.entries(fields)) {
                const vState = valid[k];
                let stateIcon = '';
                if (vState === 'ok' || vState === 'ok_mrz') stateIcon = ' <span class="rhdx-field-ok">✓</span>';
                else if (vState && vState !== 'ok') stateIcon = ' <span class="rhdx-field-err">⚠ ' + escapeHtml(vState) + '</span>';
                fieldsHtml += `<div class="rhdx-field"><span class="rhdx-field-key">${escapeHtml(k.replace(/_/g, ' '))}</span><span class="rhdx-field-val">${escapeHtml(String(v))}${stateIcon}</span></div>`;
            }
            if (!fieldsHtml) fieldsHtml = '<div class="rhdx-field"><em style="color:#a8a49e">Aucun champ extrait</em></div>';

            // Section "Appliqué au profil" (champs écrits dans users)
            let appliedHtml = '';
            const applied = data.applied || {};
            if (Object.keys(applied).length > 0) {
                let items = '';
                for (const [col, val] of Object.entries(applied)) {
                    items += `<div>✓ <code>${escapeHtml(col)}</code> → ${escapeHtml(String(val))}</div>`;
                }
                appliedHtml = `
                    <div class="rhdx-applied">
                        <div class="rhdx-applied-title">✅ Appliqué à votre profil</div>
                        <div class="rhdx-applied-list">${items}</div>
                    </div>`;
            }

            // Section "Candidates" (champs en conflit — popup de résolution)
            let candidatesHtml = '';
            const candidates = data.candidates || {};
            const docType = data.type || type;
            if (Object.keys(candidates).length > 0) {
                const cId = 'cand_' + Date.now();
                window[cId] = { candidates, docType };
                candidatesHtml = `
                    <div class="rhdx-candidates">
                        <div class="rhdx-candidates-title">⚠ ${Object.keys(candidates).length} champ(s) en conflit</div>
                        <button class="rhdx-resolve-btn" onclick="openConflictModal('${cId}')">
                            Résoudre les conflits →
                        </button>
                    </div>`;
            }

            card.innerHTML = `
                <div class="rhdx-result-head">
                    <span class="rhdx-result-type">${escapeHtml(TYPE_LABELS[type] || type)}</span>
                    <div class="rhdx-result-name">${escapeHtml(file.name)}</div>
                    <span class="rhdx-result-score">Confiance ${data.confidence}%</span>
                </div>
                <div class="rhdx-fields">${fieldsHtml}</div>
                ${appliedHtml}
                ${candidatesHtml}
                <div class="rhdx-engine">Moteur : ${escapeHtml(data.engine || 'n/a')}</div>
            `;
        }

        results.prepend(card);
        results.classList.add('active');
    }

    function renderError(msg) {
        const card = document.createElement('div');
        card.className = 'rhdx-result-card failed';
        card.innerHTML = `<div class="rhdx-error">⚠ ${escapeHtml(msg)}</div>`;
        results.prepend(card);
        results.classList.add('active');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }
})();

// ── Popup de résolution de conflits ──────────────────────────
function openConflictModal(candId) {
    const { candidates, docType } = window[candId];
    if (!candidates || !Object.keys(candidates).length) return;

    const FIELD_LABELS = {
        nom: 'Nom', prenom: 'Prénom', date_naissance: 'Date de naissance',
        lieu_naissance: 'Lieu de naissance', nationalite: 'Nationalité',
        civilite: 'Civilité', num_secu: 'N° Sécu', adresse: 'Adresse',
        code_postal: 'Code postal', ville: 'Ville', iban: 'IBAN', bic: 'BIC',
        vehicule_immat: 'Immatriculation', vehicule_marque: 'Marque',
        vehicule_modele: 'Modèle', vehicule_puissance_fiscale: 'Puissance fiscale',
    };

    let rowsHtml = '';
    for (const [field, diff] of Object.entries(candidates)) {
        const label = FIELD_LABELS[field] || field.replace(/_/g, ' ');
        const oldVal = String(diff.old || '');
        const newVal = String(diff.new || '');
        rowsHtml += `
        <div class="conflict-row" data-field="${field}">
            <div class="conflict-field-name">${label}</div>
            <div class="conflict-options">
                <label class="conflict-option selected" onclick="selectConflictOption(this)">
                    <input type="radio" name="cf_${field}" value="old" checked>
                    <span class="conflict-option-tag current">Actuel</span>
                    <input type="text" class="conflict-edit" value="${oldVal.replace(/"/g, '&quot;')}" style="display:block">
                </label>
                <label class="conflict-option" onclick="selectConflictOption(this)">
                    <input type="radio" name="cf_${field}" value="new">
                    <span class="conflict-option-tag new">Nouveau (IA)</span>
                    <input type="text" class="conflict-edit" value="${newVal.replace(/"/g, '&quot;')}">
                </label>
            </div>
        </div>`;
    }

    const overlay = document.createElement('div');
    overlay.className = 'conflict-overlay';
    overlay.innerHTML = `
    <div class="conflict-modal">
        <h3>Résoudre les conflits</h3>
        <p style="font-size:12px;color:#888;margin:-8px 0 16px;">
            Cliquez sur la valeur à garder. Vous pouvez la modifier avant de valider.
        </p>
        ${rowsHtml}
        <div class="conflict-actions">
            <button class="conflict-btn cancel" onclick="closeConflictModal()">Annuler</button>
            <button class="conflict-btn apply" onclick="applyConflicts('${candId}')">Appliquer</button>
        </div>
    </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.conflict-modal').addEventListener('click', e => e.stopPropagation());
    overlay.addEventListener('click', closeConflictModal);
}

function selectConflictOption(label) {
    const row = label.closest('.conflict-row');
    row.querySelectorAll('.conflict-option').forEach(o => o.classList.remove('selected'));
    label.classList.add('selected');
    label.querySelector('input[type="radio"]').checked = true;
}

function closeConflictModal() {
    document.querySelector('.conflict-overlay')?.remove();
}

async function applyConflicts(candId) {
    const { candidates, docType } = window[candId];
    const fields = {};
    const rows = document.querySelectorAll('.conflict-row');

    rows.forEach(row => {
        const field = row.dataset.field;
        const selected = row.querySelector('.conflict-option.selected');
        if (!selected) return;
        const radio = selected.querySelector('input[type="radio"]');
        const editInput = selected.querySelector('.conflict-edit');
        const value = editInput ? editInput.value.trim() : '';
        if (radio.value === 'new' || value !== String(candidates[field]?.old || '')) {
            fields[field] = value;
        }
    });

    if (Object.keys(fields).length === 0) {
        closeConflictModal();
        showToast('Aucune modification');
        return;
    }

    const btn = document.querySelector('.conflict-btn.apply');
    btn.disabled = true; btn.textContent = 'Enregistrement…';

    try {
        const r = await fetch('api/rh_user_doc_apply_candidates.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ user_id: VIEW_USER_ID, doc_type: docType, fields }),
        });
        const j = await r.json();
        if (j.ok) {
            const nb = Object.keys(j.applied || {}).length;
            closeConflictModal();
            showToast('✅ ' + nb + ' champ(s) mis à jour');
        } else {
            showToast('Erreur : ' + (j.error || 'inconnue'), true);
            btn.disabled = false; btn.textContent = 'Appliquer';
        }
    } catch (e) {
        showToast('Erreur réseau', true);
        btn.disabled = false; btn.textContent = 'Appliquer';
    }
}
</script>

    <!-- ── Page head ── -->
    <div class="page-head <?= ($roleId !== 1 && $agenceScope === 0) ? 'no-scope' : '' ?>">
      <!-- Gauche : titre — largeur fixe 250px pour aligner les filtres -->
      <div style="width:250px;flex-shrink:0">
        <div class="page-head-module">Module RH</div>
        <div class="page-head-row">
          <span class="page-head-title">Documents</span>
        </div>
        <?php if ($roleId === 1): ?>
        <a href="rh_documents_config.php" style="display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:4px 11px;background:var(--bg-primary);border-radius:8px;box-shadow:2px 2px 5px var(--shadow-dark),-2px -2px 5px var(--shadow-light);font-family:'DM Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:0.12em;color:#7a9060;text-decoration:none;font-weight:500;">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Gérer les listes
        </a>
        <?php endif; ?>
      </div>

      <?php if ($roleId === 1 || $agenceScope > 0): ?>
      <!-- Filtres STE / AGC / Col — hauteur fixe : les 3 lignes toujours rendues -->
      <div class="ph-scope">

        <?php if ($roleId === 1): ?>
        <!-- Ligne STÉ (toujours visible) -->
        <div class="ph-scope-row">
          <span class="ph-scope-label">Sté</span>
          <div class="ph-scope-btns">
            <a href="<?= h(pillUrl(['societe'=>'toutes','agence'=>'toutes','user_id'=>null])) ?>"
               class="ph-scope-pill <?= $societe_sel==='toutes'?'active':'' ?>">Toutes</a>
            <?php foreach ($societes as $s): ?>
            <a href="<?= h(pillUrl(['societe'=>$s['id'],'agence'=>'toutes','user_id'=>null])) ?>"
               class="ph-scope-pill <?= ((string)$societe_sel===(string)$s['id'])?'active':'' ?>">
              <?= h($s['nom']) ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Ligne AGC — toujours rendue, invisible si STÉ=toutes (réserve la hauteur) -->
        <div class="ph-scope-row" style="<?= ($societe_sel==='toutes'||empty($agences_filtered)) ? 'visibility:hidden' : '' ?>">
          <span class="ph-scope-label">Agc</span>
          <div class="ph-scope-btns">
            <a href="<?= h(pillUrl(['agence'=>'toutes','user_id'=>null])) ?>"
               class="ph-scope-pill <?= $agence_sel==='toutes'?'active':'' ?>">Toutes</a>
            <?php foreach ($agences_filtered as $ag): ?>
            <a href="<?= h(pillUrl(['agence'=>$ag['id'],'user_id'=>null])) ?>"
               class="ph-scope-pill <?= ((string)$agence_sel===(string)$ag['id'])?'active':'' ?>">
              <?= h($ag['nom_agence']) ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <!-- Gestionnaire agence : pas de STÉ/AGC, réserver 2 lignes -->
        <div class="ph-scope-row" style="visibility:hidden"><span class="ph-scope-label">Sté</span></div>
        <div class="ph-scope-row" style="visibility:hidden"><span class="ph-scope-label">Agc</span></div>
        <?php endif; ?>

        <!-- Ligne Col — toujours rendue -->
        <div class="ph-scope-row" style="<?= empty($usersList)?'visibility:hidden':'' ?>">
          <span class="ph-scope-label">Col</span>
          <div class="ph-scope-btns">
            <?php if (count($usersList) <= 8): ?>
              <?php foreach ($usersList as $u): ?>
              <a href="<?= h(pillUrl(['user_id'=>$u['id']])) ?>"
                 class="ph-scope-pill <?= ((int)$u['id']===$viewUserId)?'active':'' ?>">
                <?= h($u['prenom'] . ' ' . $u['nom']) ?>
              </a>
              <?php endforeach; ?>
            <?php else: ?>
              <select class="ph-scope-select" onchange="location.href='<?= h(pillUrl([])) ?>&user_id='+this.value">
                <?php foreach ($usersList as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id']===$viewUserId)?'selected':'' ?>>
                  <?= h($u['prenom'] . ' ' . $u['nom']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
        </div>

      </div>
      <?php endif; ?>
    </div><!-- /.page-head -->

    <!-- Alertes docs obligatoires manquants -->
    <?php if ($missingObligatoires): ?>
    <div class="alert-manquants">
      <div class="alert-manquants-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Documents obligatoires manquants (<?= count($missingObligatoires) ?>)
      </div>
      <ul class="alert-manquants-list">
        <?php foreach ($missingObligatoires as $m): ?>
          <li><?= h($m) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Grille rubriques -->
    <div class="rubriques-grid">
    <?php foreach ($rubriques as $rubKey => $rub):
        $docs = $docsByRubrique[$rubKey] ?? [];
        // Filtrer selon dispo du TYPE (lookup dans $rubriques)
        $typeDispoForRub = [];
        foreach ($rub['types'] as $t) { $typeDispoForRub[$t['key']] = $t['dispo']; }
        $visibleDocs = array_filter($docs, function($d) use ($roleId, $agenceScope, $userId, $viewUserId, $typeDispoForRub) {
            $dispo = $typeDispoForRub[$d['sous_categorie'] ?? ''] ?? 'public';
            return rh_doc_dispo_allowed($dispo, $roleId, $agenceScope, $userId === $viewUserId);
        });
        $nbVisible   = count($visibleDocs);
        $missingInRub = [];
        foreach ($rub['types'] as $t) {
            if (!$t['obligatoire']) continue;
            if (($t['confidentiel'] ?? false) && $roleId !== 1) continue;
            $found = false;
            foreach ($docs as $d) {
                if ($d['sous_categorie'] === $t['key']) { $found = true; break; }
            }
            if (!$found) $missingInRub[] = $t;
        }
    ?>
    <div class="rub-card" data-rubrique="<?= h($rubKey) ?>">
      <div class="rub-card-head">
        <div class="rub-card-dot" style="background:<?= h($rub['color']) ?>"></div>
        <div class="rub-card-title"><?= h($rub['label']) ?></div>
        <div class="rub-card-count rub-count"><?= $nbVisible ?> doc<?= $nbVisible !== 1 ? 's' : '' ?></div>
      </div>
      <div class="rub-card-body">
        <?php if ($nbVisible === 0 && empty($missingInRub)): ?>
          <div class="rub-empty">Aucun document</div>
        <?php endif; ?>

        <?php foreach ($visibleDocs as $doc):
            $isSocDoc = !empty($doc['_is_societe_doc']);
            $ext  = strtolower(pathinfo($doc['filename'] ?? '', PATHINFO_EXTENSION));
            $icon = match($ext) {
                'pdf'  => '📄',
                'jpg','jpeg','png','gif' => '🖼️',
                'doc','docx' => '📝',
                'xls','xlsx' => '📊',
                default => '📎',
            };
            $docDispo = $typeDispoForRub[$doc['sous_categorie'] ?? ''] ?? 'public';
            $isOblig  = (int)$doc['obligatoire'] === 1;
            $typeLabel = $typeLabels[$rubKey . '/' . ($doc['sous_categorie'] ?? '')] ?? ($doc['sous_categorie'] ?? 'Document');
            $uploadDate = $doc['upload_date'] ? date('d/m/Y', strtotime($doc['upload_date'])) : '';
            $viewUrl = $isSocDoc ? h($doc['_chemin']) : 'api/rh_doc_serve.php?id=' . (int)$doc['id'];
            $docDisplayId = $isSocDoc ? 0 : (int)$doc['id'];
            // Résumé IA pour tooltip
            $docTip = '';
            if ($isSocDoc && !empty($doc['_analysis'])) {
                $parsed = json_decode($doc['_analysis'], true);
                $docTip = $parsed['resume'] ?? '';
            }
        ?>
        <div class="doc-minicard" data-id="<?= $docDisplayId ?>" <?= $docTip ? 'title="' . h($docTip) . '"' : '' ?>>
          <div class="doc-minicard-icon" style="background:rgba(<?= h($rub['rgb']) ?>,0.12)"><?= $icon ?></div>
          <div class="doc-minicard-body">
            <div class="doc-minicard-name" title="<?= h($doc['original_name'] ?? '') ?>"><?= h($doc['original_name'] ?? 'Document') ?></div>
            <div class="doc-minicard-meta">
              <span><?= h($typeLabel) ?></span>
              <?php if ($uploadDate): ?><span>· <?= $uploadDate ?></span><?php endif; ?>
              <?php if ($isSocDoc): ?><span class="badge-conf">🏢 Société</span><?php endif; ?>
              <?php if ($docDispo !== 'public' && !$isSocDoc): ?>
              <span class="badge-conf"><?= $docDispo === 'admin' ? '🔒 Admin' : '👁 Manager' ?></span>
              <?php endif; ?>
              <?php if ($isOblig): ?><span class="badge-oblig">Obligatoire</span><?php endif; ?>
            </div>
          </div>
          <div class="doc-minicard-actions">
            <a href="<?= $viewUrl ?>" target="_blank" class="doc-icon-btn view" title="Voir">
              <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <?php if (!$isSocDoc): ?>
            <button class="doc-icon-btn archive" title="Archiver" onclick="archiveDoc(<?= (int)$doc['id'] ?>, this)">
              <svg viewBox="0 0 24 24"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
            </button>
            <button class="doc-icon-btn del" title="Supprimer" onclick="deleteDoc(<?= (int)$doc['id'] ?>, this)">
              <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($missingInRub as $t): ?>
        <div class="doc-missing">
          <div class="doc-missing-label">⚠ <?= h($t['label']) ?> manquant</div>
          <button class="doc-missing-btn" onclick="openModal('<?= h($rubKey) ?>','<?= h($t['key']) ?>')">Ajouter</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="rub-card-foot">
        <button class="btn-add-doc" onclick="openModal('<?= h($rubKey) ?>')">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Ajouter
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

<!-- ── Modal Upload ── -->
<div class="modal-overlay" id="upload-modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-title">Ajouter un document</div>
    <div class="form-group">
      <label class="form-label">Rubrique</label>
      <select class="form-control" id="modal-rubrique" onchange="onRubChange()">
        <?php foreach ($rubriques as $rk => $rub): ?>
          <option value="<?= h($rk) ?>"><?= h($rub['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Type de document</label>
      <select class="form-control" id="modal-type"></select>
    </div>
    <div class="form-group">
      <label class="form-label">Nom affiché <span style="font-weight:400;text-transform:none;color:#a8a49e">(optionnel)</span></label>
      <input type="text" class="form-control" id="modal-nom" placeholder="Laisser vide pour utiliser le nom du fichier">
    </div>
    <div class="form-group">
      <label class="form-label">Fichier</label>
      <div class="drop-zone" id="drop-zone" onclick="document.getElementById('file-input').click()" ondragover="dzOver(event)" ondragleave="dzLeave(event)" ondrop="dzDrop(event)">
        <div class="drop-zone-txt" id="dz-txt">
          <strong>Cliquer ou glisser-déposer</strong>
          PDF, JPG, PNG, DOC, XLS — max 10 Mo
        </div>
        <div class="file-preview" id="file-preview" style="display:none"></div>
      </div>
      <input type="file" id="file-input" style="display:none" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt" onchange="onFileSelect(this)">
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal()">Annuler</button>
      <button class="btn-submit" id="btn-upload" onclick="submitUpload()">Téléverser</button>
    </div>
  </div>
</div>

<div id="doc-toast"></div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
