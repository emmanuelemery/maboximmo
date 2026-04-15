<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$meId   = current_user_id();
$roleId = current_role_id();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Determine target user
$userId = isset($_GET['id']) ? (int)$_GET['id'] : $meId;
if ($userId <= 0) $userId = $meId;

// Security: viewing someone else requires role 1 or 2
if ($userId !== $meId && $roleId > 2) {
    http_response_code(403);
    exit('Accès refusé.');
}

$isOwnProfile = ($userId === $meId);
$canEditAdmin = ($roleId === 1);

// ── POST handler ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profil') {
    verify_csrf_any();
    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId !== $meId && $roleId !== 1) {
        http_response_code(403); exit('Accès refusé.');
    }
    $fields = [
        'telephone','telephone_pro','adresse','adresse2','code_postal','ville','pays',
        'date_naissance','lieu_naissance','nationalite','num_secu','civilite',
        'permis_conduire','vehicule_nom','vehicule_type','vehicule_puissance_fiscale','vehicule_immat',
        'indemnite_km',
        'contact_urgence_nom','contact_urgence_tel','bio_courte',
    ];
    if ($roleId === 1) {
        $fields = array_merge($fields, [
            'fonction','type_contrat','temps_travail','date_entree','date_sortie',
            'iban','bic','notes_rh','id_agence','id_societe',
        ]);
    }

    $useBankTable = ($roleId === 1 && rh_table_exists($pdo, rh_bank_table_name()));
    $bankFields = [];
    if ($useBankTable) {
        foreach (rh_bank_allowed_fields() as $f) {
            if (array_key_exists($f, $_POST)) {
                $bankFields[$f] = $_POST[$f];
            }
        }
        if ($bankFields) {
            rh_bank_save($pdo, $targetId, $meId, $bankFields);
        }
    }

    $sets = []; $params = [];
    foreach ($fields as $f) {
        if ($useBankTable && array_key_exists($f, $bankFields)) {
            continue;
        }
        if (array_key_exists($f, $_POST)) {
            $val = trim($_POST[$f]);
            $sets[] = "$f = ?";
            $params[] = $val !== '' ? $val : null;
        }
    }
    if ($sets) {
        $params[] = $targetId;
        $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . ", date_modification=NOW() WHERE id=?")->execute($params);
    }
    header("Location: rh_profil.php?id=$targetId&saved=1"); exit;
}

// ── Load user data ────────────────────────────────────────────────
$user = [];
try {
    $stmt = $pdo->prepare("SELECT u.*, s.nom AS societe_nom, a.nom_agence AS agence_nom
        FROM users u
        LEFT JOIN societes s ON s.id = u.id_societe
        LEFT JOIN agences a ON a.id = u.id_agence
        WHERE u.id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $user = []; }

if (!$user) { http_response_code(404); exit('Utilisateur introuvable.'); }

$bank = rh_bank_get($pdo, $userId);
if ($bank) {
    $user['iban'] = $bank['iban'] ?? null;
    $user['bic']  = $bank['bic'] ?? null;
}

$canSeeRib = ($canEditAdmin || $isOwnProfile);
$ribHistory = [];
$ribFieldLabels = ['iban' => 'IBAN', 'bic' => 'BIC / SWIFT'];
if ($canSeeRib && rh_table_exists($pdo, rh_bank_hist_table_name())) {
    try {
        $stmtH = $pdo->prepare("SELECT h.*, " . rh_user_name_expr('u') . " AS nom_complet, u.prenom, u.nom, u.username, u.email\n            FROM " . rh_bank_hist_table_name() . " h\n            LEFT JOIN users u ON u.id = h.changed_by\n            WHERE h.id_user = ?\n            ORDER BY h.changed_at DESC, h.id DESC\n            LIMIT 30");
        $stmtH->execute([$userId]);
        foreach ($stmtH->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fields = json_decode((string)($row['changed_fields'] ?? '[]'), true);
            if (!is_array($fields)) $fields = [];
            $oldValues = json_decode((string)($row['old_values'] ?? '{}'), true);
            if (!is_array($oldValues)) $oldValues = [];
            $newValues = json_decode((string)($row['new_values'] ?? '{}'), true);
            if (!is_array($newValues)) $newValues = [];
            $items = [];
            foreach ($fields as $f) {
                $items[] = [
                    'field' => $f,
                    'label' => $ribFieldLabels[$f] ?? $f,
                    'old' => $oldValues[$f] ?? null,
                    'new' => $newValues[$f] ?? null,
                ];
            }
            if (!$items) {
                continue;
            }
            $row['_items'] = $items;
            $row['_by'] = rh_user_display_name($row);
            $ribHistory[] = $row;
        }
    } catch (PDOException $e) {
        $ribHistory = [];
    }
}

// ── Détection automatique du type de document ─────────────────────
function detectDocType(string $label, string $orig, string $cat = ''): string {
    $s = strtolower($label . ' ' . $orig . ' ' . $cat);
    if (preg_match('/carte.grise|immatricul|registration/i', $s))       return 'carte_grise';
    if (preg_match('/assurance|insurance/i', $s))                         return 'assurance_vehicule';
    if (preg_match('/permis|driving.licen/i', $s))                        return 'permis_conduire';
    if (preg_match('/carte.id|cni|carte.nation|identit/i', $s))           return 'carte_identite';
    if (preg_match('/vitale|carte.s[eé]|s[eé]curit[eé].soc/i', $s))     return 'carte_secu';
    if (preg_match('/passeport|passport/i', $s))                          return 'passeport';
    if (preg_match('/\brib\b|relev[eé].identit[eé]|bancaire/i', $s))     return 'rib';
    if (preg_match('/avenant/i', $s))                                     return 'avenant';
    if (preg_match('/contrat/i', $s))                                     return 'contrat_travail';
    return 'autre';
}

// ── Documents du user (onglet Documents dans le profil) ─────────────
// Chargement des docs actifs + archivés pour affichage dans le tab "Documents".
$userDocsActive = [];
$userDocsArchived = [];
try {
    // Docs actifs (non archivés, non supprimés)
    $stmtD = $pdo->prepare("
        SELECT id, categorie, type_document, label, filename, original_name, file_path, upload_date
        FROM rh_documents
        WHERE id_user = ? AND actif = 1 AND archived_at IS NULL
        ORDER BY upload_date DESC
    ");
    $stmtD->execute([$userId]);
    $userDocsActive = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    // Docs archivés (visible admin uniquement)
    if ($canEditAdmin) {
        $stmtA = $pdo->prepare("
            SELECT id, categorie, type_document, label, filename, original_name, file_path, upload_date, archived_at, archived_by
            FROM rh_documents
            WHERE id_user = ? AND actif = 1 AND archived_at IS NOT NULL
            ORDER BY archived_at DESC
        ");
        $stmtA->execute([$userId]);
        $userDocsArchived = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $userDocsActive = [];
    $userDocsArchived = [];
}

// Profile completion
$completionFields = [
    'telephone','adresse','code_postal','ville','date_naissance','lieu_naissance',
    'nationalite','num_secu','contact_urgence_nom','contact_urgence_tel',
    'vehicule_nom','permis_conduire','bio_courte',
];
$filled = 0;
foreach ($completionFields as $f) {
    if (!empty($user[$f])) $filled++;
}
$completionPct = (int)round($filled / count($completionFields) * 100);

// Avatar initials + color
$initials = strtoupper(substr($user['prenom'] ?? '', 0, 1) . substr($user['nom'] ?? '', 0, 1));
$avatarColor = !empty($user['couleur']) ? $user['couleur'] : '#4f8ef7';
$avatarBorder = 'rgba(102,217,255,.35)';
$avatarGlow = 'rgba(102,217,255,.25)';
if (preg_match('/^#[0-9a-f]{6}$/i', $avatarColor)) {
    sscanf($avatarColor, '#%02x%02x%02x', $r, $g, $b);
    $avatarBorder = sprintf('rgba(%d,%d,%d,0.6)', $r, $g, $b);
    $avatarGlow = sprintf('rgba(%d,%d,%d,0.35)', $r, $g, $b);
}

// IBAN masking
$ibanDisplay = '';
if (!empty($user['iban'])) {
    $raw = $user['iban'];
    $ibanDisplay = substr($raw, 0, 4) . str_repeat('•', max(0, strlen($raw) - 8)) . substr($raw, -4);
}

// Contract badge color
$badgeMap = [
    'CDI'        => '#22c55e',
    'CDD'        => '#f59e0b',
    'Alternance' => '#a78bfa',
    'Stage'      => '#38bdf8',
    'Freelance'  => 'var(--btn-save-to)',
    'Autre'      => '#94a3b8',
];
$badgeColor = $badgeMap[$user['type_contrat'] ?? ''] ?? '#94a3b8';

$csrfToken = csrf_token();
$saved     = ($_GET['saved'] ?? '') === '1';
$activeTab = $_GET['tab'] ?? 'perso';

// ── Helper: render a document upload zone ────────────────────────
function renderDocZone(string $categorie, string $icon, string $label, array $docs, int $userId, string $csrfToken): void
{
    $catDocs = $docs[$categorie] ?? [];
    $hasDocs = !empty($catDocs);
    ?>
<div class="doc-zone" id="doczone-<?= h($categorie) ?>">
  <div class="doc-zone-header">
    <span><?= $icon ?> <?= h($label) ?></span>
    <label class="doc-upload-btn" for="upload-<?= h($categorie) ?>">
      ⬆ Ajouter
      <input type="file" id="upload-<?= h($categorie) ?>" accept=".pdf,.jpg,.jpeg,.png"
             style="display:none" onchange="uploadDoc(this, '<?= h($categorie) ?>', <?= (int)$userId ?>)">
    </label>
  </div>
  <div class="doc-list" id="doclist-<?= h($categorie) ?>">
    <?php foreach ($catDocs as $doc):
        // Compatible rh_documents (filename) et rh_user_documents (nom_fichier)
        $nomFichier  = $doc['filename'] ?? $doc['nom_fichier'] ?? '';
        $nomOriginal = $doc['original_name'] ?? $doc['nom_original'] ?? $nomFichier;
        $url         = $doc['_url'] ?? ('./uploads/rh_docs/' . (int)$userId . '/' . $nomFichier);
        $ext         = strtolower(pathinfo($nomFichier, PATHINFO_EXTENSION));
        $fileIcon    = $ext === 'pdf' ? '📄' : '🖼️';
        $sizeBytes   = (int)($doc['taille'] ?? $doc['file_size'] ?? 0);
        if ($sizeBytes < 1024)        $sizeStr = $sizeBytes . ' o';
        elseif ($sizeBytes < 1048576) $sizeStr = round($sizeBytes/1024) . ' Ko';
        else                          $sizeStr = number_format($sizeBytes/1048576, 1) . ' Mo';
        $dateStr = !empty($doc['upload_date']) ? date('d/m/Y', strtotime($doc['upload_date'])) :
                   (!empty($doc['created_at'])  ? date('d/m/Y', strtotime($doc['created_at'])) : '');
    ?>
    <div class="doc-item" id="doc-<?= (int)$doc['id'] ?>">
      <a href="<?= h($url) ?>" target="_blank" class="doc-link">
        <?= $fileIcon ?> <?= h($nomOriginal) ?>
        <?php if ($dateStr): ?><span class="doc-size"><?= $dateStr ?></span><?php endif; ?>
        <?php if ($sizeBytes > 0): ?><span class="doc-size"><?= h($sizeStr) ?></span><?php endif; ?>
      </a>
      <button onclick="deleteDoc(<?= (int)$doc['id'] ?>, '<?= h($categorie) ?>')" class="doc-del" title="Supprimer">🗑</button>
    </div>
    <?php endforeach; ?>
    <div class="doc-empty" id="docempty-<?= h($categorie) ?>"<?= $hasDocs ? ' style="display:none"' : '' ?>>Aucun document</div>
  </div>
</div>
    <?php
}
?>
<?php
// ── Layout variables ─────────────────────────────────────────────
$layout_title        = 'Profil — ' . h(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
$layout_module       = 'Ma Box RH';
$layout_sidebar      = 'rh_sidebar';
$layout_accent       = '#4878a6';

// Topbar : vide (avatar + nom + complétude dans page-head)
$layout_topbar_right = '';

// Page-head gauche : Avatar + nom + complétude
$_phAvatarHtml = !empty($user['avatar_url'])
    ? '<img src="' . h($user['avatar_url']) . '" style="width:52px;height:52px;border-radius:14px;object-fit:cover;cursor:pointer;box-shadow:2px 2px 6px #d4d7de,-2px -2px 6px #fff" onclick="document.getElementById(\'avatarInput\')?.click()" title="Modifier la photo">'
    : '<div style="width:52px;height:52px;border-radius:14px;background:' . h($avatarColor) . ';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;cursor:pointer;box-shadow:2px 2px 6px #d4d7de,-2px -2px 6px #fff" onclick="document.getElementById(\'avatarInput\')?.click()" title="Modifier la photo">' . h($initials) . '</div>';

$layout_head_kpis = '
<div style="display:flex;align-items:center;gap:14px;margin-right:20px">
    ' . $_phAvatarHtml . '
    <div>
        <div style="font-size:15px;font-weight:700;color:#2f587d;margin-bottom:3px">' . h(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) . '</div>
        <div style="display:flex;align-items:center;gap:8px">
            <div style="width:80px;height:6px;border-radius:4px;background:#e4e6ec;overflow:hidden"><div style="width:' . $completionPct . '%;height:100%;border-radius:4px;background:linear-gradient(90deg,#4878a6,#4a6038)"></div></div>
            <span style="font-family:\'DM Mono\',monospace;font-size:10px;color:#4878a6;font-weight:700">' . $completionPct . '%</span>
        </div>
    </div>
</div>
<div class="ph-kpi" style="min-width:120px;flex-direction:row;gap:10px;align-items:center;padding:8px 14px">
    <span style="font-size:18px">🏢</span>
    <div><div class="ph-kpi-lbl">Société</div><div class="ph-kpi-val" style="font-size:12px">' . h($user['societe_nom'] ?? '—') . '</div></div>
</div>
<div class="ph-kpi" style="min-width:120px;flex-direction:row;gap:10px;align-items:center;padding:8px 14px">
    <span style="font-size:18px">📍</span>
    <div><div class="ph-kpi-lbl">Agence</div><div class="ph-kpi-val" style="font-size:12px">' . h($user['agence_nom'] ?? '—') . '</div></div>
</div>
' . (!empty($user['type_contrat']) ? '
<div class="ph-kpi" style="min-width:90px;flex-direction:row;gap:10px;align-items:center;padding:8px 14px">
    <span style="font-size:18px">📋</span>
    <div><div class="ph-kpi-lbl">Contrat</div><div class="ph-kpi-val" style="font-size:12px;color:' . h($badgeColor) . '">' . h($user['type_contrat']) . '</div></div>
</div>' : '');

$layout_head_actions = '';
if ($isOwnProfile || $canEditAdmin) {
    $layout_head_actions = '
    <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:10px;font-weight:700;color:#8a8680;text-transform:uppercase;letter-spacing:.06em">Couleur</span>
        <input type="color" id="colorInput" value="' . h($avatarColor) . '" style="width:40px;height:40px;border:2px solid #d4d7de;border-radius:10px;cursor:pointer;padding:2px;box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff">
        <button type="button" id="colorApplyBtn" style="padding:6px 14px;background:#4878a6;color:#fff;border:none;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;box-shadow:2px 2px 5px #d4d7de,-2px -2px 5px #fff">OK</button>
    </div>';
}

$layout_extra_css = <<<EXTRACSS
<style>
/* ── Override page-head hauteur pour accueillir avatar + cards ── */
.mbi-page-head { height: auto !important; min-height: 72px; padding: 12px 28px !important; }
.mbi-page-head .ph-left { flex-wrap: wrap; gap: 12px; }
.mbi-page-head .ph-kpi-strip { display: flex !important; flex-wrap: wrap; gap: 12px; grid-template-columns: none !important; }

/* ── Tab Infos perso : 3 sections sur 1 ligne ──────────────────────── */
#tab-perso {
    display: none;
}
#tab-perso.active {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    align-items: start;
}
/* Onglet Contact : 2 sections côte à côte */
#tab-contact.active {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
}
/* Onglet Véhicule : 3 sections côte à côte */
#tab-vehicule.active {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    align-items: start;
}
/* Onglet RH : sections côte à côte */
#tab-rh {
    display: none;
}
#tab-rh.active {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    align-items: start;
}
/* Notes RH (admin) prend toute la largeur */
#tab-rh .form-section:last-child {
    grid-column: 1 / -1;
}
/* Responsive : 1 colonne sur petit écran */
@media (max-width: 1100px) {
    #tab-perso.active,
    #tab-vehicule.active,
    #tab-rh.active {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 768px) {
    #tab-perso.active,
    #tab-contact.active,
    #tab-vehicule.active,
    #tab-rh.active {
        grid-template-columns: 1fr;
    }
}

/* ── Override global : passage white mode (MaBoxImmo Phase 3) ──────── */
:root {
    --bg: #f7f8fa !important;
    --bg-soft: #ffffff !important;
    --bg-secondary: #f7f8fa !important;
    --ink: #1a1816 !important;
    --muted: #8a8680 !important;
    --accent: #4878a6 !important;
    --stroke: rgba(196,192,186,0.25) !important;
    --shadow-dark: #d4d7de !important;
    --shadow-light: #ffffff !important;
}

.toast-saved {
    margin: 16px 30px 0;
    background: #e8efe0;
    border: 1px solid rgba(74,96,56,0.3);
    color: #4a6038;
    border-radius: 10px;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 10px 18px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    z-index: 9999;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .25s, transform .25s;
    pointer-events: none;
}
.toast.show {
 opacity: 1;
 transform: translateY(0);
 }
.toast.ok {
 background: rgba(34,197,94,.15);
 border: 1px solid rgba(34,197,94,.35);
 color: #4ade80;
 }
.toast.err {
 background: rgba(239,68,68,.15);
 border: 1px solid rgba(239,68,68,.35);
 color: #f87171;
 }

.profil-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 24px;
    padding: 24px 30px 40px;
    align-items: start;
}
@media (max-width: 900px) {
    .profil-layout {
 grid-template-columns: 1fr;
 }
}

.profile-card {
    background: var(--bg-soft);
    border: 1px solid var(--stroke);
    border-radius: 14px;
    padding: 28px 20px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
    position: sticky;
    top: 20px;
    box-shadow: 0 4px 24px #f7f8fa;
}
.avatar-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
}
.avatar-circle {
    width: 88px;
 height: 88px;
    border-radius: 50%;
    display: flex;
 align-items: center;
 justify-content: center;
    font-size: 28px;
 font-weight: 800;
    color: #fff;
    margin-bottom: 0;
    box-shadow: 0 0 0 4px rgba(255,255,255,.07);
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}
.avatar-circle.clickable {
 cursor: pointer;
 }
.avatar-img {
 width:100%;
 height:100%;
 object-fit:cover;
 display:block;
 }
.avatar-initials {
 position: relative;
 z-index: 1;
 }
.avatar-upload {
    font-size: 11px;
    font-weight: 700;
    color: var(--accent,#4878a6);
    background: rgba(102,217,255,.12);
    border: 1px solid rgba(102,217,255,.3);
    padding: 4px 10px;
    border-radius: 8px;
    cursor: pointer;
    transition: background .15s;
}
.avatar-upload:hover {
 background: rgba(102,217,255,.22);
 }
.profile-name {
    font-size: 17px;
 font-weight: 800;
    color: var(--ink);
    text-align: center;
    margin-bottom: 4px;
}
.profile-fonction {
    font-size: 12px;
 color: var(--muted);
    text-align: center;
 margin-bottom: 16px;
}
.profile-divider {
    width: 100%;
 height: 1px;
    background: var(--stroke);
    margin: 12px 0;
}
.profile-info-row {
    width: 100%;
    display: flex;
 align-items: flex-start;
 gap: 9px;
    font-size: 12px;
 color: var(--muted);
    padding: 5px 0;
}
.profile-info-row .pi-icon {
 font-size: 14px;
 flex-shrink: 0;
 margin-top: 1px;
 }
.profile-info-row .pi-val {
 color: var(--ink);
 font-weight: 500;
 word-break: break-all;
 }
.contract-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .4px;
}

.completion-wrap {
    width: 100%;
    margin-top: 14px;
}
.completion-label {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.completion-bar {
    height: 5px;
    background: rgba(255,255,255,.08);
    border-radius: 99px;
    overflow: hidden;
}
.completion-fill {
    height: 100%;
    border-radius: 99px;
    background: linear-gradient(90deg, #4878a6 0%, #4a6038 100%);
    transition: width .5s ease;
}
.color-picker-wrap {
    width: 100%;
    margin-top: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.color-picker-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--muted);
}
.color-input {
    height: 34px;
    width: 100%;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,.2);
    background: transparent;
    cursor: pointer;
    padding: 0;
    -webkit-appearance: none;
    appearance: none;
}
.color-input::-webkit-color-swatch-wrapper {
 padding: 0;
 }
.color-input::-webkit-color-swatch {
    border: none;
    border-radius: 7px;
}
.color-input::-moz-color-swatch {
    border: none;
    border-radius: 7px;
}
.color-picker-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.color-apply-btn {
    height: 34px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid rgba(102,217,255,.4);
    background: rgba(102,217,255,.18);
    color: var(--accent,#4878a6);
    font-weight: 700;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}
.color-apply-btn:hover {
    background: rgba(102,217,255,.28);
    border-color: rgba(102,217,255,.6);
}
.avatar-crop-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.avatar-crop-modal.active {
 display: flex;
 }
.avatar-crop-card {
    width: 420px;
    max-width: 92vw;
    background: var(--bg-soft);
    border: 1px solid var(--stroke);
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 12px 40px rgba(0,0,0,.35);
}
.avatar-crop-title {
    font-size: 14px;
    font-weight: 800;
    color: var(--ink);
    margin-bottom: 12px;
}
.avatar-crop-area {
    width: 240px;
    height: 240px;
    margin: 0 auto 12px;
    position: relative;
    background: #0b1220;
    border-radius: 12px;
    overflow: hidden;
    cursor: grab;
    touch-action: none;
}
.avatar-crop-area:active {
 cursor: grabbing;
 }
.avatar-crop-area:after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    box-shadow: 0 0 0 999px rgba(0,0,0,.45);
    border: 1px solid #8a8680;
    pointer-events: none;
}
.avatar-crop-img {
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    user-select: none;
    -webkit-user-drag: none;
}
.avatar-crop-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}
.avatar-crop-controls input[type=range] {
 width: 100%;
 }
.avatar-crop-actions {
    display: flex;
    gap: 10px;
    margin-top: 14px;
}
.avatar-btn {
    flex: 1;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid var(--stroke);
    background: rgba(0,0,0,.2);
    color: var(--ink);
    font-weight: 700;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}
.avatar-btn:hover {
 background: rgba(0,0,0,.35);
 }
.avatar-btn.primary {
    background: rgba(16,185,129,.25);
    border-color: rgba(16,185,129,.5);
    color: #4a6038;
}
.avatar-btn.primary:hover {
 background: rgba(16,185,129,.35);
 }

.profil-right {
    display: flex;
    flex-direction: column;
    gap: 0;
}
/* ── Onglets style MaBoxImmo : pills neumorphiques blanches ──────── */
.tabs-bar {
    display: flex;
    gap: 6px;
    margin-bottom: 22px;
    padding: 5px;
    background: #f0f1f3;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: inset 2px 2px 5px #d4d7de, inset -2px -2px 5px #fff;
}
.tab-btn {
    padding: 10px 20px;
    font-size: 12px;
    font-weight: 600;
    color: #8a8680;
    cursor: pointer;
    border: none;
    background: transparent;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    white-space: nowrap;
    transition: all .2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    letter-spacing: .02em;
}
.tab-btn:hover {
    color: #2f587d;
    background: rgba(255,255,255,0.6);
}
.tab-btn.active {
    color: #2f587d;
    background: #ffffff;
    box-shadow: 2px 2px 6px #d4d7de, -2px -2px 6px #fff;
    font-weight: 700;
}
.tab-panel {
    display: none;
}
.tab-panel.active {
    display: block;
}

.form-section {
    background: #ffffff;
    border: 1px solid #e4e6ec;
    border-radius: 14px;
    padding: 22px 24px;
    margin-bottom: 16px;
    box-shadow: 3px 3px 10px #d4d7de, -3px -3px 10px #fff;
}
.form-section-title {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #4a6038;
    padding-left: 10px;
    border-left: 3px solid #4878a6;
    margin-bottom: 18px;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}
.form-grid.cols-3 {
    grid-template-columns: 1fr 1fr 1fr;
}
.form-grid.cols-1 {
    grid-template-columns: 1fr;
}
/* Sur grand écran avec tab RH (large), 2 colonnes */
#tab-rh .form-grid { grid-template-columns: 1fr; }
#tab-rh .form-grid.cols-1 { grid-template-columns: 1fr; }
#tab-docs .form-grid { grid-template-columns: 1fr; }
@media (max-width: 640px) {
    .form-grid, .form-grid.cols-3 {
 grid-template-columns: 1fr;
 }
}
.form-group {
 display: flex;
 flex-direction: column;
 gap: 5px;
 }
.form-group.full {
 grid-column: 1 / -1;
 }
.form-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--muted);
}
.form-control {
    background: #ffffff;
    border: 1px solid #d4d7de;
    border-radius: 7px;
    padding: 8px 12px;
    color: #1a1816;
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: border-color .15s, background .15s, box-shadow .15s;
    width: 100%;
    box-sizing: border-box;
}
.form-control:focus {
    border-color: #4878a6;
    background: #ffffff;
    box-shadow: 0 0 0 2px rgba(72,120,166,0.15);
}
.form-control[readonly] {
    background: #f7f8fa;
    color: #6a6660;
    cursor: not-allowed;
}
select.form-control option {
    background: #ffffff;
    color: #1a1816;
}
textarea.form-control {
 resize: vertical;
 min-height: 80px;
 }
.admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(167,139,250,.12);
    border: 1px solid rgba(167,139,250,.25);
    color: #a78bfa;
    border-radius: 6px;
    padding: 3px 9px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-left: 8px;
}

.autosave-indicator {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--muted);
    opacity: 0;
    transition: opacity .3s;
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--bg-soft);
    border: 1px solid var(--stroke);
    border-radius: 20px;
    padding: 6px 14px;
    z-index: 100;
    pointer-events: none;
}
.autosave-indicator.saving {
 opacity: 1;
 color: var(--muted);
 }
.autosave-indicator.saved  {
 opacity: 1;
 color: #4ade80;
 border-color: rgba(74,222,128,.3);
 }
.autosave-indicator.error  {
 opacity: 1;
 color: #f87171;
 border-color: rgba(248,113,113,.3);
 }

.doc-zone {
 background:rgba(0,0,0,.15);
 border-radius:10px;
 padding:12px 14px;
 margin-top:10px;
 }
.doc-zone-header {
 display:flex;
 align-items:center;
 justify-content:space-between;
 margin-bottom:8px;
 font-size:12px;
 font-weight:700;
 color:var(--ink);
 }
.doc-upload-btn {
 background:rgba(102,217,255,.15);
 border:1px solid rgba(102,217,255,.3);
 color:var(--accent,#4878a6);
 padding:4px 10px;
 border-radius:6px;
 font-size:11px;
 font-weight:700;
 cursor:pointer;
 transition:background .15s;
 }
.doc-upload-btn:hover {
 background:rgba(102,217,255,.25);
 }
.doc-item {
 display:flex;
 align-items:center;
 gap:8px;
 padding:6px 0;
 border-bottom:1px solid rgba(255,255,255,.05);
 }
.doc-item:last-child {
 border-bottom:none;
 }
.doc-link {
 flex:1;
 font-size:12px;
 color:var(--muted);
 text-decoration:none;
 display:flex;
 align-items:center;
 gap:6px;
 }
.doc-link:hover {
 color:var(--accent);
 }
.doc-size {
 font-size:10px;
 color:rgba(255,255,255,.25);
 }
.doc-del {
 background:none;
 border:none;
 cursor:pointer;
 color:rgba(255,100,100,.5);
 font-size:14px;
 padding:0 4px;
 transition:color .15s;
 }
.doc-del:hover {
 color:#f87171;
 }
.doc-empty {
 font-size:11px;
 color:rgba(255,255,255,.2);
 font-style:italic;
 }

.vehicule-table {
 width:100%;
 border-collapse:collapse;
 font-size:12px;
 background:rgba(0,0,0,.1);
 border-radius:8px;
 overflow:hidden;
 }
.vehicule-table th, .vehicule-table td {
 padding:8px 10px;
 border-bottom:1px solid rgba(255,255,255,.06);
 text-align:left;
 }
.vehicule-table th {
 color:var(--muted);
 font-size:10px;
 text-transform:uppercase;
 letter-spacing:.4px;
 width:40%;
 }
.vehicule-table tr:last-child th, .vehicule-table tr:last-child td {
 border-bottom:none;
 }
.vehicule-empty {
 font-size:11px;
 color:rgba(255,255,255,.25);
 font-style:italic;
 }
.rib-history { display:flex; flex-direction:column; gap:12px; }
.rib-empty { font-size:12px; color:#8a8680; font-style:italic; }
.rib-history-item {
    background:#f7f8fa;
    border:1px solid #e4e6ec;
    border-radius:10px;
    padding:12px 14px;
}
.rib-history-meta {
    display:flex; flex-wrap:wrap; gap:8px;
    font-size:11px; color:#6a6660; margin-bottom:8px;
}
.rib-history-meta .rib-history-action {
    padding:2px 8px; border-radius:999px;
    background:rgba(72,120,166,0.1); color:#2f587d;
    border:1px solid rgba(72,120,166,0.2);
    font-weight:700; text-transform:uppercase; letter-spacing:.04em; font-size:9px;
}
.rib-history-table { display:grid; gap:6px; }
.rib-history-row {
    display:grid; grid-template-columns:140px 1fr 1fr;
    gap:10px; font-size:12px; align-items:center;
}
.rib-history-head {
    font-size:10px; color:#8a8680; text-transform:uppercase;
    letter-spacing:.06em; font-weight:700;
}
.rib-history-old { color:#8a5040; }
.rib-history-new { color:#2f587d; font-weight:600; }
@media (max-width:900px) {
    .rib-history-row { grid-template-columns:1fr; }
    .rib-history-head { display:none; }
}</style>
EXTRACSS;

ob_start();
?>
<div id="toast" class="toast"></div>
<div id="autosave-indicator" class="autosave-indicator">⏳ Enregistrement…</div>
<?php if ($saved): ?>
<div class="toast-saved">✅ Profil mis à jour avec succès.</div>
<?php endif; ?>
  <div class="profil-layout" style="display:block">    <!-- ══ LEFT CARD CACHÉE (déplacée dans page-head) ══ -->    <div class="profile-card" style="display:none !important" style="border-color:<?= h($avatarBorder) ?>; box-shadow:0 4px 24px #f7f8fa, 0 0 0 1px <?= h($avatarBorder) ?>;">      <div class="avatar-wrap">        <div class="avatar-circle" style="background:<?= h($avatarColor) ?>">          <?php if (!empty($user['avatar_url'])): ?>
            <img src="<?= h($user['avatar_url']) ?>" alt="Photo de profil" class="avatar-img">          <?php else: ?>
            <span class="avatar-initials"><?= h($initials ?: '?') ?>
</span>          <?php endif;
 ?>
        </div>        <?php if ($isOwnProfile || $canEditAdmin): ?>
          <label class="avatar-upload">            📸 Modifier la photo            <input type="file" id="avatarInput" accept="image/*" style="display:none">          </label>        <?php endif;
 ?>
      </div>      <div class="profile-name"><?= h(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?>
</div>      <div class="profile-fonction"><?= h($user['fonction'] ?? 'Collaborateur') ?>
</div>      <?php if (!empty($user['type_contrat'])): ?>
      <span class="contract-badge" style="background:<?= h($badgeColor) ?>22;color:<?= h($badgeColor) ?>;border:1px solid <?= h($badgeColor) ?>44">        <?= h($user['type_contrat']) ?>
      </span>      <?php endif;
 ?>
      <div class="profile-divider"></div>      <?php if (!empty($user['societe_nom'])): ?>
      <div class="profile-info-row">        <span class="pi-icon">🏢</span>        <span class="pi-val"><?= h($user['societe_nom']) ?>
</span>      </div>      <?php endif;
 ?>
      <?php if (!empty($user['agence_nom'])): ?>
      <div class="profile-info-row">        <span class="pi-icon">📍</span>        <span class="pi-val"><?= h($user['agence_nom']) ?>
</span>      </div>      <?php endif;
 ?>
      <div class="profile-info-row">        <span class="pi-icon">✉️</span>        <span class="pi-val"><?= h($user['email'] ?? '') ?>
</span>      </div>      <?php if (!empty($user['telephone'])): ?>
      <div class="profile-info-row">        <span class="pi-icon">📞</span>        <span class="pi-val"><?= h($user['telephone']) ?>
</span>      </div>      <?php endif;
 ?>
      <?php if (!empty($user['date_entree'])): ?>
      <div class="profile-info-row">        <span class="pi-icon">📅</span>        <span class="pi-val">Entrée : <?= h(date('d/m/Y', strtotime($user['date_entree']))) ?>
</span>      </div>      <?php endif;
 ?>
      <?php if (!empty($user['last_login_at'])): ?>
      <div class="profile-info-row">        <span class="pi-icon">🕐</span>        <span class="pi-val">Dernière co. : <?= h(date('d/m/Y H:i', strtotime($user['last_login_at']))) ?>
</span>      </div>      <?php endif;
 ?>
      <div class="profile-divider"></div>      <!-- Completion indicator -->      <div class="completion-wrap">        <div class="completion-label">          <span>Complétude profil</span>          <span><?= $completionPct ?>
%</span>        </div>        <div class="completion-bar">          <div class="completion-fill" style="width:<?= $completionPct ?>%"></div>        </div>      </div>      <?php if ($isOwnProfile || $canEditAdmin): ?>
      <div class="color-picker-wrap" id="colorPickerWrap">        <div class="color-picker-label">Couleur planning</div>        <div class="color-picker-row">          <input type="color" id="colorInput" class="color-input" value="<?= h($avatarColor) ?>" aria-label="Sélecteur de couleur">          <button type="button" class="color-apply-btn" id="colorApplyBtn">Valider</button>        </div>      </div>      <?php endif;
 ?>
    </div>    <!-- ══ FORM PLEINE LARGEUR ══ -->    <div class="profil-right" style="width:100%">      <div class="tabs-bar">        <a href="?id=<?= $userId ?>&tab=perso"    class="tab-btn <?= $activeTab==='perso'    ? 'active':'' ?>"><span style="font-size:14px">👤</span> Infos personnelles</a>        <a href="?id=<?= $userId ?>&tab=contact"  class="tab-btn <?= $activeTab==='contact'  ? 'active':'' ?>"><span style="font-size:14px">📞</span> Contact</a>        <a href="?id=<?= $userId ?>&tab=vehicule" class="tab-btn <?= $activeTab==='vehicule' ? 'active':'' ?>"><span style="font-size:14px">🚗</span> Véhicule</a>        <a href="?id=<?= $userId ?>&tab=rh"       class="tab-btn <?= $activeTab==='rh'       ? 'active':'' ?>"><span style="font-size:14px">💼</span> Infos RH<?= $canEditAdmin ? '<span class="admin-badge">Admin</span>' : '' ?></a>        <a href="?id=<?= $userId ?>&tab=docs"     class="tab-btn <?= $activeTab==='docs'     ? 'active':'' ?>">📄 Documents
</a>      </div>      <div id="profil-form-wrap">        <!-- TAB 1: Infos personnelles -->        <div class="tab-panel <?= $activeTab==='perso' ? 'active':'' ?>" id="tab-perso">          <div class="form-section">            <div class="form-section-title">État civil</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">Civilité</label>                <select name="civilite" class="form-control">                  <option value="">—</option>                  <?php foreach (['M.','Mme','Autre'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= ($user['civilite']??'')===$opt?'selected':'' ?>
><?= h($opt) ?>
</option>                  <?php endforeach;
 ?>
                </select>              </div>              <div class="form-group">                <label class="form-label">Nationalité</label>                <input type="text" name="nationalite" class="form-control" value="<?= h($user['nationalite'] ?? 'Française') ?>">              </div>              <div class="form-group">                <label class="form-label">Date de naissance</label>                <input type="date" name="date_naissance" class="form-control" value="<?= h($user['date_naissance'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Lieu de naissance</label>                <input type="text" name="lieu_naissance" class="form-control" value="<?= h($user['lieu_naissance'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">N° Sécurité sociale</label>                <input type="text" name="num_secu" class="form-control" placeholder="1 84 05 75 …" value="<?= h($user['num_secu'] ?? '') ?>">              </div>            </div>          </div>          <div class="form-section">            <div class="form-section-title">Adresse</div>            <div class="form-grid cols-1">              <div class="form-group">                <label class="form-label">Adresse (ligne 1)</label>                <input type="text" name="adresse" class="form-control" value="<?= h($user['adresse'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Adresse (ligne 2)</label>                <input type="text" name="adresse2" class="form-control" value="<?= h($user['adresse2'] ?? '') ?>">              </div>            </div>            <div class="form-grid cols-3" style="margin-top:14px">              <div class="form-group">                <label class="form-label">Code postal</label>                <input type="text" name="code_postal" class="form-control" value="<?= h($user['code_postal'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Ville</label>                <input type="text" name="ville" class="form-control" value="<?= h($user['ville'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Pays</label>                <input type="text" name="pays" class="form-control" value="<?= h($user['pays'] ?? 'France') ?>">              </div>            </div>          </div>          <div class="form-section">            <div class="form-section-title">Contact d'urgence</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">Nom complet</label>                <input type="text" name="contact_urgence_nom" class="form-control" value="<?= h($user['contact_urgence_nom'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Téléphone</label>                <input type="tel" name="contact_urgence_tel" class="form-control" value="<?= h($user['contact_urgence_tel'] ?? '') ?>">              </div>            </div>          </div>          <!-- Section Documents retirée : gestion centralisée dans rh_documents.php -->        </div>        <!-- TAB 2: Contact -->        <div class="tab-panel <?= $activeTab==='contact' ? 'active':'' ?>" id="tab-contact">          <div class="form-section">            <div class="form-section-title">Coordonnées</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">Email (identifiant de connexion)</label>                <input type="email" class="form-control" value="<?= h($user['email'] ?? '') ?>" readonly>              </div>              <div class="form-group">                <label class="form-label">Téléphone personnel</label>                <input type="tel" name="telephone" class="form-control" value="<?= h($user['telephone'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Téléphone professionnel</label>                <input type="tel" name="telephone_pro" class="form-control" value="<?= h($user['telephone_pro'] ?? '') ?>">              </div>            </div>          </div>          <div class="form-section">            <div class="form-section-title">Bio courte</div>            <div class="form-grid cols-1">              <div class="form-group">                <label class="form-label">Quelques mots sur vous</label>                <textarea name="bio_courte" class="form-control" style="min-height:100px"><?= h($user['bio_courte'] ?? '') ?>
</textarea>              </div>            </div>          </div>        </div>        <!-- TAB 3: Véhicule -->        <div class="tab-panel <?= $activeTab==='vehicule' ? 'active':'' ?>" id="tab-vehicule">          <div class="form-section">            <div class="form-section-title">Permis de conduire</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">Type(s) de permis</label>                <input type="text" name="permis_conduire" class="form-control" placeholder="ex: B, BE, C…" value="<?= h($user['permis_conduire'] ?? '') ?>">              </div>            </div>          </div>          <div class="form-section">            <div class="form-section-title">Véhicule personnel</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">Marque / modèle</label>                <input type="text" name="vehicule_nom" class="form-control" placeholder="ex: Renault Clio" value="<?= h($user['vehicule_nom'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Type / variante</label>                <input type="text" name="vehicule_type" class="form-control" placeholder="ex: BERLINE, II" value="<?= h($user['vehicule_type'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Puissance fiscale (CV)</label>                <input type="number" name="vehicule_puissance_fiscale" class="form-control" min="1" max="30" value="<?= h($user['vehicule_puissance_fiscale'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Immatriculation</label>                <input type="text" name="vehicule_immat" class="form-control" placeholder="AA-000-AA" value="<?= h($user['vehicule_immat'] ?? '') ?>">              </div>              <div class="form-group">                <label class="form-label">Indemnité kilométrique (€/km)</label>                <input type="number" name="indemnite_km" class="form-control" step="0.0001" min="0" placeholder="0.3200" value="<?= h($user['indemnite_km'] ?? '') ?>">              </div>            </div>          </div>                    <div class="form-section">            <div class="form-section-title">Synthèse carte grise</div>            <?php            $vehiculeInfos = [                'Marque / modèle' => $user['vehicule_nom'] ?? '',                'Type / variante' => $user['vehicule_type'] ?? '',                'Immatriculation' => $user['vehicule_immat'] ?? '',                'Puissance fiscale (CV)' => $user['vehicule_puissance_fiscale'] ?? '',                'Indemnité (€/km)' => $user['indemnite_km'] ?? '',            ];
            $hasVehiculeInfos = false;
            foreach ($vehiculeInfos as $v) {
                if ($v !== null && $v !== '') {
 $hasVehiculeInfos = true;
 break;
 }
            }
            ?>
            <table id="vehicule-table" class="vehicule-table"<?= $hasVehiculeInfos ? '' : ' style="display:none"' ?>
>              <?php foreach ($vehiculeInfos as $k => $v): if ($v === null || $v === '') continue;
 ?>
                <tr><th><?= h($k) ?>
</th><td><?= h($v) ?>
</td></tr>              <?php endforeach;
 ?>
            </table>            <div id="vehicule-empty" class="vehicule-empty"<?= $hasVehiculeInfos ? ' style="display:none"' : '' ?>
>Aucune donnée de carte grise détectée.</div>          </div>          <!-- Section Documents retirée : gestion centralisée dans rh_documents.php -->        </div>        <!-- TAB 4: Infos RH -->        <div class="tab-panel <?= $activeTab==='rh' ? 'active':'' ?>" id="tab-rh">          <div class="form-section">            <div class="form-section-title">Contrat de travail</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">Type de contrat</label>                <select name="type_contrat" class="form-control" <?= !$canEditAdmin && !$isOwnProfile ? 'disabled' : '' ?>
>                  <option value="">—</option>                  <?php foreach (['CDI','CDD','Alternance','Stage','Freelance','Autre'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= ($user['type_contrat']??'')===$opt?'selected':'' ?>
><?= h($opt) ?>
</option>                  <?php endforeach;
 ?>
                </select>              </div>              <div class="form-group">                <label class="form-label">Temps de travail</label>                <select name="temps_travail" class="form-control" <?= !$canEditAdmin && !$isOwnProfile ? 'disabled' : '' ?>
>                  <option value="">—</option>                  <?php foreach (['Temps plein','Temps partiel'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= ($user['temps_travail']??'')===$opt?'selected':'' ?>
><?= h($opt) ?>
</option>                  <?php endforeach;
 ?>
                </select>              </div>              <div class="form-group">                <label class="form-label">Date d'entrée</label>                <input type="date" name="date_entree" class="form-control" value="<?= h($user['date_entree'] ?? '') ?>" <?= !$canEditAdmin && !$isOwnProfile ? 'readonly' : '' ?>>              </div>              <div class="form-group">                <label class="form-label">Date de sortie</label>                <input type="date" name="date_sortie" class="form-control" value="<?= h($user['date_sortie'] ?? '') ?>" <?= !$canEditAdmin && !$isOwnProfile ? 'readonly' : '' ?>>              </div>              <div class="form-group">                <label class="form-label">Fonction</label>                <input type="text" name="fonction" class="form-control" value="<?= h($user['fonction'] ?? '') ?>">              </div>            </div>          </div>          <div class="form-section">            <div class="form-section-title">RIB</div>            <div class="form-grid">              <div class="form-group">                <label class="form-label">IBAN</label>                <?php if ($canEditAdmin || $isOwnProfile): ?>                <input type="text" name="iban" class="form-control" placeholder="FR76 …" value="<?= h($user['iban'] ?? '') ?>" <?= !$canEditAdmin && !$isOwnProfile ? 'readonly':'' ?>>                <?php else: ?>                <input type="text" class="form-control" value="<?= h($ibanDisplay) ?>" readonly>                <?php endif; ?>              </div>              <div class="form-group">                <label class="form-label">BIC / SWIFT</label>                <input type="text" name="bic" class="form-control" placeholder="BNPAFRPP…" value="<?= h($user['bic'] ?? '') ?>" <?= !$canEditAdmin && !$isOwnProfile ? 'readonly':'' ?>>              </div>            </div>
          <?php if ($canSeeRib): ?>
          <div class="form-section">
            <div class="form-section-title">Historique RIB</div>
            <div class="rib-history">
              <?php if (empty($ribHistory)): ?>
                <div class="rib-empty">Aucune modification enregistrée.</div>
              <?php else: ?>
                <?php foreach ($ribHistory as $h): ?>
                  <div class="rib-history-item">
                    <div class="rib-history-meta">
                      <span class="rib-history-date"><?= h(date('d/m/Y H:i', strtotime($h['changed_at']))) ?></span>
                      <span class="rib-history-by">par <?= h($h['_by']) ?></span>
                      <span class="rib-history-action"><?= h($h['action']) ?></span>
                    </div>
                    <div class="rib-history-table">
                      <div class="rib-history-row rib-history-head">
                        <div>Champ</div><div>Ancienne valeur</div><div>Nouvelle valeur</div>
                      </div>
                      <?php foreach ($h['_items'] as $it): ?>
                        <div class="rib-history-row">
                          <div><?= h($it['label']) ?></div>
                          <div class="rib-history-old"><?= h($it['old'] ?? '—') ?></div>
                          <div class="rib-history-new"><?= h($it['new'] ?? '—') ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>          </div>          <?php if ($canEditAdmin): ?>          <div class="form-section">            <div class="form-section-title">Notes RH <span class="admin-badge">Admin seulement</span></div>            <div class="form-grid cols-1">              <div class="form-group">                <label class="form-label">Notes internes</label>                <textarea name="notes_rh" class="form-control" style="min-height:110px"><?= h($user['notes_rh'] ?? '') ?></textarea>              </div>            </div>          </div>          <?php endif; ?>        </div>
        <!-- TAB 5: Documents -->
        <div class="tab-panel <?= $activeTab==='docs' ? 'active':'' ?>" id="tab-docs">
          <div class="form-section">
            <div class="form-section-title">
              📄 Mes documents
              <a href="./rh_documents.php?user_id=<?= (int)$userId ?>" class="btn-add-doc" style="float:right;padding:4px 14px;background:#4878a6;color:#fff;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;font-family:'Sora',sans-serif">+ Ajouter un document</a>
            </div>
            <?php if (empty($userDocsActive)): ?>
              <div style="padding:20px;text-align:center;color:#8a8680;font-size:13px;font-style:italic">Aucun document. Cliquez sur "+ Ajouter" pour déposer vos pièces.</div>
            <?php else: ?>
              <table style="width:100%;border-collapse:collapse">
                <thead>
                  <tr style="background:#f7f8fa">
                    <th style="padding:10px 12px;text-align:left;font-family:'DM Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4a6038;border-bottom:1px solid #e4e6ec">Type</th>
                    <th style="padding:10px 12px;text-align:left;font-family:'DM Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4a6038;border-bottom:1px solid #e4e6ec">Nom</th>
                    <th style="padding:10px 12px;text-align:left;font-family:'DM Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4a6038;border-bottom:1px solid #e4e6ec">Date</th>
                    <th style="padding:10px 12px;text-align:right;font-family:'DM Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4a6038;border-bottom:1px solid #e4e6ec">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($userDocsActive as $doc):
                    $ext = strtolower(pathinfo($doc['filename'] ?? '', PATHINFO_EXTENSION));
                    $icon = match($ext) { 'pdf' => '📄', 'jpg','jpeg','png' => '🖼️', default => '📎' };
                    $url = './uploads/rh_docs/' . $userId . '/' . ($doc['filename'] ?? '');
                    $dateStr = !empty($doc['upload_date']) ? date('d/m/Y', strtotime($doc['upload_date'])) : '—';
                    $typeLabel = h($doc['label'] ?? $doc['type_document'] ?? $doc['categorie'] ?? '—');
                ?>
                  <tr style="border-bottom:1px solid #f0f1f3" data-doc-id="<?= (int)$doc['id'] ?>">
                    <td style="padding:10px 12px;font-size:12px"><?= $icon ?> <?= $typeLabel ?></td>
                    <td style="padding:10px 12px;font-size:12px;color:#2f587d"><?= h($doc['original_name'] ?? $doc['filename'] ?? '—') ?></td>
                    <td style="padding:10px 12px;font-size:11px;color:#8a8680;font-family:'DM Mono',monospace"><?= $dateStr ?></td>
                    <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                      <a href="<?= h($url) ?>" target="_blank" title="Visualiser" style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;background:#fff;border:1px solid #d4d7de;border-radius:6px;font-size:10px;font-weight:600;color:#4878a6;text-decoration:none;cursor:pointer;box-shadow:1px 1px 3px #d4d7de,-1px -1px 3px #fff">👁 Voir</a>
                      <button onclick="docAction(<?= (int)$doc['id'] ?>,'archive')" title="Archiver" style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;background:#fff;border:1px solid #d4d7de;border-radius:6px;font-size:10px;font-weight:600;color:#c97b2e;cursor:pointer;box-shadow:1px 1px 3px #d4d7de,-1px -1px 3px #fff">📦 Archiver</button>
                      <button onclick="docAction(<?= (int)$doc['id'] ?>,'delete')" title="Supprimer" style="display:inline-flex;align-items:center;gap:3px;padding:5px 10px;background:#fff;border:1px solid #d4d7de;border-radius:6px;font-size:10px;font-weight:600;color:#8a5040;cursor:pointer;box-shadow:1px 1px 3px #d4d7de,-1px -1px 3px #fff">🗑 Supprimer</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <?php if ($canEditAdmin && !empty($userDocsArchived)): ?>
          <div class="form-section" style="margin-top:16px">
            <div class="form-section-title">📦 Archives <span class="admin-badge">Admin</span></div>
            <table style="width:100%;border-collapse:collapse">
              <thead>
                <tr style="background:#fef8f0">
                  <th style="padding:8px 12px;text-align:left;font-size:9px;font-weight:700;color:#c97b2e;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #f0e2da">Type</th>
                  <th style="padding:8px 12px;text-align:left;font-size:9px;font-weight:700;color:#c97b2e;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #f0e2da">Nom</th>
                  <th style="padding:8px 12px;text-align:left;font-size:9px;font-weight:700;color:#c97b2e;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #f0e2da">Archivé le</th>
                  <th style="padding:8px 12px;text-align:right;font-size:9px;font-weight:700;color:#c97b2e;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #f0e2da">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($userDocsArchived as $doc):
                  $url = './uploads/rh_docs/' . $userId . '/' . ($doc['filename'] ?? '');
                  $archDate = !empty($doc['archived_at']) ? date('d/m/Y H:i', strtotime($doc['archived_at'])) : '—';
              ?>
                <tr style="border-bottom:1px solid #fdf2e6" data-doc-id="<?= (int)$doc['id'] ?>">
                  <td style="padding:8px 12px;font-size:12px"><?= h($doc['label'] ?? $doc['type_document'] ?? '—') ?></td>
                  <td style="padding:8px 12px;font-size:12px;color:#8a5040"><?= h($doc['original_name'] ?? '—') ?></td>
                  <td style="padding:8px 12px;font-size:11px;color:#8a8680;font-family:'DM Mono',monospace"><?= $archDate ?></td>
                  <td style="padding:8px 12px;text-align:right">
                    <a href="<?= h($url) ?>" target="_blank" style="padding:4px 10px;background:#fff;border:1px solid #d4d7de;border-radius:6px;font-size:10px;font-weight:600;color:#4878a6;text-decoration:none">👁 Voir</a>
                    <button onclick="docAction(<?= (int)$doc['id'] ?>,'unarchive')" style="padding:4px 10px;background:#fff;border:1px solid #d4d7de;border-radius:6px;font-size:10px;font-weight:600;color:#4a6038;cursor:pointer">♻️ Restaurer</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div><!-- /profil-form-wrap -->    </div><!-- /profil-right -->  </div><!-- /profil-layout --><div id="avatarCropModal" class="avatar-crop-modal">  <div class="avatar-crop-card">    <div class="avatar-crop-title">Recadrer la photo</div>    <div class="avatar-crop-area" id="avatarCropArea">      <img id="avatarCropImg" class="avatar-crop-img" alt="Aperçu">    </div>    <div class="avatar-crop-controls">      <input type="range" id="avatarZoom" min="1" max="2.5" step="0.01" value="1">    </div>    <div class="avatar-crop-actions">      <button type="button" class="avatar-btn" id="avatarCancel">Annuler</button>      <button type="button" class="avatar-btn primary" id="avatarSave">Enregistrer</button>    </div>  </div></div><script>const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;const PROFILE_USER_ID = <?= (int)$userId ?>;let toastTimer;function showToast(msg, type = 'ok') {    const t = document.getElementById('toast');    t.textContent = msg;    t.className = 'toast ' + type + ' show';    clearTimeout(toastTimer);    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);}const avatarInput = document.getElementById('avatarInput');const avatarModal = document.getElementById('avatarCropModal');const avatarArea = document.getElementById('avatarCropArea');const avatarImg = document.getElementById('avatarCropImg');const avatarZoom = document.getElementById('avatarZoom');const avatarSave = document.getElementById('avatarSave');const avatarCancel = document.getElementById('avatarCancel');const avatarCircle = document.querySelector('.avatar-circle');const colorInput = document.getElementById('colorInput');const colorApplyBtn = document.getElementById('colorApplyBtn');let currentColor = '<?= h($avatarColor) ?>
';function hexToRgb(hex) {    const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');    if (!m) return null;    const intVal = parseInt(m[1], 16);    return {        r: (intVal >> 16) & 255,        g: (intVal >> 8) & 255,        b: intVal & 255    };}function relativeLuminance(rgb) {    const srgb = [rgb.r, rgb.g, rgb.b].map(v => {        const c = v / 255;        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);    });    return 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2];}function isTooLight(hex) {    const rgb = hexToRgb(hex);    if (!rgb) return true;    return relativeLuminance(rgb) > 0.6;}async function saveProfileColor(color) {    try {        const r = await fetch('./api/rh_profil_save_field.php', {            method: 'POST',            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },            body: JSON.stringify({ user_id: PROFILE_USER_ID, fields: { couleur: color }, csrf_token: CSRF_TOKEN })        });        const d = await r.json();        if (!d.success) throw new Error(d.error || d.message || 'Erreur');        showToast('Couleur mise à jour', 'ok');        return true;    } catch (e) {        showToast(e.message || 'Erreur', 'err');        return false;    }}if (colorInput) {    colorInput.addEventListener('input', () => {        const color = colorInput.value;        if (isTooLight(color)) {            return;        }        if (avatarCircle) avatarCircle.style.background = color;    });    colorApplyBtn && colorApplyBtn.addEventListener('click', async () => {        const color = colorInput.value;        if (isTooLight(color)) {            showToast('Choisissez une teinte plus foncée', 'err');            colorInput.value = currentColor;            if (avatarCircle) avatarCircle.style.background = currentColor;            return;        }        const ok = await saveProfileColor(color);        if (ok) {            currentColor = color;        } else {            colorInput.value = currentColor;            if (avatarCircle) avatarCircle.style.background = currentColor;        }    });}const cropState = {    baseScale: 1,    zoom: 1,    offsetX: 0,    offsetY: 0,    dragging: false,    startX: 0,    startY: 0,    startOffsetX: 0,    startOffsetY: 0};function applyCropTransform() {    if (!avatarArea || !avatarImg) return;    const rect = avatarArea.getBoundingClientRect();    const totalScale = cropState.baseScale * cropState.zoom;    const displayW = avatarImg.naturalWidth * totalScale;    const displayH = avatarImg.naturalHeight * totalScale;    const maxOffsetX = Math.max(0, (displayW - rect.width) / 2);    const maxOffsetY = Math.max(0, (displayH - rect.height) / 2);    cropState.offsetX = Math.max(-maxOffsetX, Math.min(maxOffsetX, cropState.offsetX));    cropState.offsetY = Math.max(-maxOffsetY, Math.min(maxOffsetY, cropState.offsetY));    avatarImg.style.transform = `translate(-50%, -50%) translate(${cropState.offsetX}px, ${cropState.offsetY}px) scale(${totalScale})`;}function openAvatarModal(src) {    if (!avatarModal || !avatarImg || !avatarArea) return;    avatarModal.classList.add('active');    avatarZoom.value = '1';    cropState.zoom = 1;    cropState.offsetX = 0;    cropState.offsetY = 0;    avatarImg.onload = () => {        requestAnimationFrame(() => {            const rect = avatarArea.getBoundingClientRect();            cropState.baseScale = Math.max(rect.width / avatarImg.naturalWidth, rect.height / avatarImg.naturalHeight);            applyCropTransform();        });    };    avatarImg.src = src;}function closeAvatarModal() {    if (!avatarModal) return;    avatarModal.classList.remove('active');    if (avatarInput) avatarInput.value = '';}if (avatarInput) {    avatarInput.addEventListener('change', (e) => {        const file = e.target.files && e.target.files[0];        if (!file) return;        const reader = new FileReader();        reader.onload = () => openAvatarModal(reader.result);        reader.readAsDataURL(file);    });}if (avatarCircle && avatarInput) {    avatarCircle.classList.add('clickable');    avatarCircle.addEventListener('click', () => avatarInput.click());}if (avatarModal) {    avatarModal.addEventListener('click', (e) => {        if (e.target === avatarModal) closeAvatarModal();    });}if (avatarCancel) {    avatarCancel.addEventListener('click', closeAvatarModal);}if (avatarZoom) {    avatarZoom.addEventListener('input', () => {        cropState.zoom = parseFloat(avatarZoom.value || '1');        applyCropTransform();    });}if (avatarArea) {    avatarArea.addEventListener('pointerdown', (e) => {        cropState.dragging = true;        cropState.startX = e.clientX;        cropState.startY = e.clientY;        cropState.startOffsetX = cropState.offsetX;        cropState.startOffsetY = cropState.offsetY;        avatarArea.setPointerCapture(e.pointerId);    });    avatarArea.addEventListener('pointermove', (e) => {        if (!cropState.dragging) return;        cropState.offsetX = cropState.startOffsetX + (e.clientX - cropState.startX);        cropState.offsetY = cropState.startOffsetY + (e.clientY - cropState.startY);        applyCropTransform();    });    const endDrag = () => { cropState.dragging = false; };    avatarArea.addEventListener('pointerup', endDrag);    avatarArea.addEventListener('pointercancel', endDrag);}if (avatarSave) {    avatarSave.addEventListener('click', async () => {        if (!avatarImg || !avatarArea) return;        const rect = avatarArea.getBoundingClientRect();        const totalScale = cropState.baseScale * cropState.zoom;        const displayW = avatarImg.naturalWidth * totalScale;        const displayH = avatarImg.naturalHeight * totalScale;        const imageTopLeftX = rect.width / 2 + cropState.offsetX - displayW / 2;        const imageTopLeftY = rect.height / 2 + cropState.offsetY - displayH / 2;        let sx = (0 - imageTopLeftX) / totalScale;        let sy = (0 - imageTopLeftY) / totalScale;        let sWidth = rect.width / totalScale;        let sHeight = rect.height / totalScale;        sx = Math.max(0, Math.min(avatarImg.naturalWidth - sWidth, sx));        sy = Math.max(0, Math.min(avatarImg.naturalHeight - sHeight, sy));        const outputSize = 400;        const canvas = document.createElement('canvas');        canvas.width = outputSize;        canvas.height = outputSize;        const ctx = canvas.getContext('2d');        ctx.imageSmoothingQuality = 'high';        ctx.drawImage(avatarImg, sx, sy, sWidth, sHeight, 0, 0, outputSize, outputSize);        avatarSave.disabled = true;        canvas.toBlob(async (blob) => {            if (!blob) {                showToast('Erreur de recadrage', 'err');                avatarSave.disabled = false;                return;            }            try {                const formData = new FormData();                formData.append('avatar', blob, 'avatar.jpg');                formData.append('user_id', PROFILE_USER_ID);                formData.append('csrf_token', CSRF_TOKEN);                const r = await fetch('./api/rh_profil_upload_avatar.php', { method: 'POST', body: formData });                const d = await r.json();                if (!d.success) throw new Error(d.error || d.message || 'Erreur');                if (avatarCircle) {                    let img = avatarCircle.querySelector('img');                    if (!img) {                        avatarCircle.innerHTML = '';                        img = document.createElement('img');                        img.className = 'avatar-img';                        avatarCircle.appendChild(img);                    }                    img.src = d.url + '?t=' + Date.now();                }                showToast('Photo mise à jour', 'ok');                closeAvatarModal();            } catch (e) {                showToast(e.message || 'Erreur upload', 'err');            } finally {                avatarSave.disabled = false;            }        }, 'image/jpeg', 0.9);    });}async function uploadDoc(input, categorie, userId) {    if (!input.files[0]) return;    const formData = new FormData();    formData.append('fichier', input.files[0]);    formData.append('categorie', categorie);    formData.append('user_id', userId);    formData.append('csrf_token', CSRF_TOKEN);    const btn = input.parentElement;    const labelNode = btn && btn.childNodes ? btn.childNodes[0] : null;    const originalText = labelNode ? labelNode.textContent.trim() : '';    if (labelNode) labelNode.textContent = '⏳ Analyse IA…';    showToast('🔍 Analyse IA en cours (5-15 sec)…', 'ok');    try {        const r = await fetch('./api/rh_profil_upload_doc.php', { method:'POST', body:formData });        const data = await r.json();        if (!data.success) throw new Error(data.error || data.message || 'Erreur');        const list = document.getElementById('doclist-' + categorie);        const empty = document.getElementById('docempty-' + categorie);        if (empty) empty.style.display = 'none';        const ext = data.nom_original.split('.').pop().toLowerCase();        const icon = ext === 'pdf' ? '📄' : '🖼️';        const div = document.createElement('div');        div.className = 'doc-item'; div.id = 'doc-' + data.id;        div.innerHTML = `<a href="${data.url}" target="_blank" class="doc-link">${icon} ${data.nom_original} <span class="doc-size">${formatSize(data.taille)}</span></a><button onclick="deleteDoc(${data.id},'${
categorie}
')" class="doc-del">🗑</button>`;        list.appendChild(div);        showToast('Document ajouté', 'ok');        if (data.analysis) {            if (data.analysis.ok === false) {                let extra = '';                const meta = data.analysis.meta || {};                if (typeof meta.shell_exec !== 'undefined' || typeof meta.has_tesseract !== 'undefined' || typeof meta.has_pdftotext !== 'undefined') {                    const se = meta.shell_exec ? '1' : '0';                    const pt = meta.has_pdftotext ? '1' : '0';                    const ts = meta.has_tesseract ? '1' : '0';                    const pp = meta.has_pdftoppm ? '1' : '0';                    extra = ' (shell_exec:' + se + ' pdftotext:' + pt + ' tesseract:' + ts + ' pdftoppm:' + pp + ')';                }                showToast('OCR: ' + (data.analysis.error || 'Erreur') + extra, 'err');            } else {                const info = data.analysis.info || {};                applyVehicleInfo(info);                const parts = [];                if (info.immat) parts.push('Immat: ' + info.immat);                if (info.cv) parts.push('CV: ' + info.cv);                if (info.indemnite_km) parts.push('IK: ' + info.indemnite_km + ' €/km');                if (parts.length) {                    showToast('OCR OK - ' + parts.join(' | '), 'ok');                } else {                    if (data.analysis.meta && data.analysis.meta.preview) {                        const preview = data.analysis.meta.preview;                        showToast('OCR: aucune donnée détectée (copier le texte)', 'err');                        window.prompt('OCR preview (copier le texte):', preview);                    } else {                        showToast('OCR: aucune donnée détectée', 'err');                    }                }            }        }        if (data.rhdx) { handleRhdxResult(data.rhdx); }    } catch(e) {        showToast(e.message || 'Erreur upload', 'err');    }    if (labelNode) labelNode.textContent = originalText || '⬆ Ajouter';    input.value = '';}async function deleteDoc(docId, categorie) {    if (!confirm('Supprimer ce document ?')) return;    try {        const formData = new FormData();        formData.append('doc_id', docId);        formData.append('csrf_token', CSRF_TOKEN);        const r = await fetch('./api/rh_profil_delete_doc.php', {            method:'POST',            body: formData        });        const data = await r.json();        if (!data.success) throw new Error(data.error || data.message);        const el = document.getElementById('doc-' + docId);        if (el) el.remove();        const list = document.getElementById('doclist-' + categorie);        if (list && !list.querySelector('.doc-item')) {            const empty = document.getElementById('docempty-' + categorie);            if (empty) empty.style.display = '';        }        showToast('Document supprimé', 'ok');    } catch(e) { showToast(e.message || 'Erreur', 'err'); }}function applyVehicleInfo(info) {    if (!info) return;    const fields = [        ['vehicule_nom', info.vehicule_nom || ''],        ['vehicule_type', info.type || ''],        ['vehicule_immat', info.immat || ''],        ['vehicule_puissance_fiscale', info.cv || ''],        ['indemnite_km', info.indemnite_km || '']    ];    for (const [name, val] of fields) {        if (val === null || val === undefined || val === '') continue;        const el = document.querySelector(`[name="${name}"]`);        if (el) el.value = val;    }    const table = document.getElementById('vehicule-table');    const empty = document.getElementById('vehicule-empty');    if (table) {        const rows = [];        if (info.vehicule_nom) rows.push(['Marque / modèle', info.vehicule_nom]);        if (info.type) rows.push(['Type / variante', info.type]);        if (info.immat) rows.push(['Immatriculation', info.immat]);        if (info.cv) rows.push(['Puissance fiscale (CV)', info.cv]);        if (info.indemnite_km) rows.push(['Indemnité (€/km)', info.indemnite_km]);        if (rows.length) {            table.innerHTML = rows.map(r => `<tr><th>${r[0]}</th><td>${r[1]}</td></tr>`).join('');            table.style.display = '';            if (empty) empty.style.display = 'none';        }    }}function handleRhdxResult(rhdx) {    if (!rhdx) return;    if (rhdx.error) { showToast('⚠ IA: ' + rhdx.error, 'err'); return; }    const applied = rhdx.applied || {};    const fields  = rhdx.fields  || {};    const candidates = rhdx.candidates || {};    const appliedCount    = Object.keys(applied).length;    const fieldsCount     = Object.keys(fields).length;    const candidatesCount = Object.keys(candidates).length;    for (const [name, val] of Object.entries(applied)) {        const el = document.querySelector(`[name="${name}"]`);        if (el && val !== null && val !== undefined) { el.value = val; el.dispatchEvent(new Event('change', {bubbles:true})); }    }    const typeLabel = rhdx.type || '?';    if (appliedCount > 0) {        showToast('✅ ' + appliedCount + ' champ(s) appliqué(s) au profil (' + typeLabel + ')', 'ok');    }    if (candidatesCount > 0) {        setTimeout(() => {            showToast('⚠ ' + candidatesCount + ' champ(s) en conflit — choisissez', 'ok');            rhdxConflictOpen(typeLabel, candidates);        }, 500);        return;    }    if (appliedCount > 0) return;    if (fieldsCount > 0) {        showToast('ℹ ' + fieldsCount + ' champ(s) extrait(s) (' + typeLabel + ')', 'ok');    } else {        showToast('⚠ Aucun champ reconnu sur ce document', 'err');    }}async function docAction(docId, action) {    const labels = {archive:'Archiver ce document ?',unarchive:'Restaurer ce document ?',delete:'Supprimer définitivement ce document ?'};    if (!confirm(labels[action] || 'Confirmer ?')) return;    try {        const r = await fetch('./api/rh_doc_archive.php', {            method:'POST',            headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},            body:JSON.stringify({doc_id:docId, action:action})        });        const d = await r.json();        if (d.success) { showToast(d.message || 'OK','ok'); setTimeout(()=>location.reload(),800); }        else showToast(d.message || 'Erreur','err');    } catch(e) { showToast('Erreur réseau','err'); }}function formatSize(bytes) {    if (bytes < 1024) return bytes + ' o';    if (bytes < 1024*1024) return Math.round(bytes/1024) + ' Ko';    return (bytes/1024/1024).toFixed(1) + ' Mo';}// Auto-dismiss saved toast after 4s(function(){  const t = document.querySelector('.toast-saved');  if (t) setTimeout(() => { t.style.transition='opacity .5s'; t.style.opacity='0'; setTimeout(()=>t.remove(),500); }, 4000);})();// ── Auto-save on input ──────────────────────────────────────────(function() {    const indicator = document.getElementById('autosave-indicator');    let pendingFields = {};    let saveTimer = null;    let hideTimer = null;    function setIndicator(state, msg) {        indicator.textContent = msg;        indicator.className = 'autosave-indicator ' + state;        clearTimeout(hideTimer);        if (state === 'saved' || state === 'error') {            hideTimer = setTimeout(() => { indicator.className = 'autosave-indicator'; }, 2500);        }    }    async function flush() {        if (!Object.keys(pendingFields).length) return;        const toSave = Object.assign({}, pendingFields);        pendingFields = {};        setIndicator('saving', '⏳ Enregistrement…');        try {            const r = await fetch('./api/rh_profil_save_field.php', {                method: 'POST',                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },                body: JSON.stringify({ user_id: PROFILE_USER_ID, fields: toSave, csrf_token: CSRF_TOKEN })            });            const d = await r.json();            if (!d.success) throw new Error(d.error || d.message || 'Erreur');            setIndicator('saved', '✓ Enregistré');        } catch(e) {            setIndicator('error', '✗ ' + (e.message || 'Erreur'));            // Put fields back so next change retries            Object.assign(pendingFields, toSave);        }    }    function schedSave() {        clearTimeout(saveTimer);        saveTimer = setTimeout(flush, 600);    }    function onFieldChange(e) {        const el = e.target;        const name = el.name;        if (!name) return;        pendingFields[name] = el.value;        schedSave();    }    const wrap = document.getElementById('profil-form-wrap');    if (wrap) {        wrap.addEventListener('input',  onFieldChange);        wrap.addEventListener('change', onFieldChange);    }})();</script>

<!-- ══════════════════════════════════════════════════════════
     MODALE RÉSOLUTION CONFLITS (Phase 3 — candidates UI)
     Apparaît automatiquement après un upload de document si des
     champs déjà remplis entrent en conflit avec ceux extraits par l'IA.
════════════════════════════════════════════════════════════ -->
<style>
.rhdx-conflict-backdrop {
    position: fixed; inset: 0;
    background: rgba(15, 24, 34, 0.75);
    backdrop-filter: blur(5px);
    display: none;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 20px;
    z-index: 9998;
    overflow-y: auto;
    animation: rhdxFadeIn .25s ease-out;
}
.rhdx-conflict-backdrop.active { display: flex; }
@keyframes rhdxFadeIn { from { opacity: 0; } to { opacity: 1; } }

.rhdx-conflict-modal {
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 10px 10px 30px rgba(0,0,0,0.45);
    padding: 28px 32px;
    max-width: 680px;
    width: 100%;
    animation: rhdxSlideIn .3s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes rhdxSlideIn {
    from { transform: translateY(20px) scale(0.96); opacity: 0; }
    to   { transform: translateY(0) scale(1); opacity: 1; }
}

.rhdx-conflict-header {
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 8px;
    padding-bottom: 14px;
    border-bottom: 1px solid rgba(196,192,186,0.4);
}
.rhdx-conflict-ico {
    width: 46px; height: 46px; border-radius: 12px;
    background: linear-gradient(135deg, #c97b2e, #8a5040);
    color: #fff; font-size: 22px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 3px 3px 10px #c8cbd2, -3px -3px 10px #fff;
}
.rhdx-conflict-header h3 { font-size: 16px; color: #8a5040; margin-bottom: 3px; }
.rhdx-conflict-header p  { font-size: 12px; color: #6a6660; line-height: 1.5; }

.rhdx-conflict-type {
    display: inline-block; padding: 3px 11px; margin-left: 6px;
    border-radius: 999px;
    background: #e8efe0; color: #4a6038;
    font-family: 'DM Mono', monospace; font-size: 9px;
    font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
}

.rhdx-conflict-list {
    display: flex; flex-direction: column; gap: 10px;
    margin: 18px 0;
    max-height: 420px; overflow-y: auto;
    padding-right: 8px;
}
.rhdx-conflict-list::-webkit-scrollbar { width: 6px; }
.rhdx-conflict-list::-webkit-scrollbar-track { background: transparent; }
.rhdx-conflict-list::-webkit-scrollbar-thumb { background: #d4d7de; border-radius: 4px; }

.rhdx-conflict-item {
    background: #fff;
    border-radius: 10px;
    padding: 14px 16px;
    box-shadow: 2px 2px 8px rgba(180,185,175,0.3);
    border-left: 4px solid #c97b2e;
    transition: border-left-color .2s, opacity .2s;
}
.rhdx-conflict-item.resolved-keep {
    border-left-color: #8a8680;
    opacity: .55;
}
.rhdx-conflict-item.resolved-apply {
    border-left-color: #4a6038;
    opacity: .55;
}

.rhdx-conflict-field-name {
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #8a8680; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em;
    margin-bottom: 6px;
}

.rhdx-conflict-values {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 10px 14px;
    align-items: center;
    margin-bottom: 10px;
}
.rhdx-conflict-value {
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 12px;
    min-height: 34px;
    display: flex; align-items: center;
    word-break: break-word;
}
.rhdx-conflict-value.old {
    background: #f0e2da;
    color: #6a4010;
    text-decoration: line-through;
    text-decoration-color: rgba(138,80,64,0.35);
}
.rhdx-conflict-value.new {
    background: #e8efe0;
    color: #2f4a1f;
    font-weight: 600;
}
.rhdx-conflict-arrow {
    color: #8a8680; font-size: 16px;
    text-align: center;
}

.rhdx-conflict-actions {
    display: flex; gap: 8px; justify-content: flex-end;
}
.rhdx-conflict-btn {
    padding: 7px 14px;
    border-radius: 8px;
    border: none;
    font-family: 'Sora', sans-serif;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 5px;
    transition: transform .15s, box-shadow .15s;
}
.rhdx-conflict-btn.keep {
    background: #f0f1f3; color: #6a6660;
    box-shadow: 1px 1px 4px #d4d7de, -1px -1px 4px #fff;
}
.rhdx-conflict-btn.apply {
    background: #4a6038; color: #fff;
    box-shadow: 1px 1px 4px #d4d7de, -1px -1px 4px #fff;
}
.rhdx-conflict-btn:hover:not(:disabled) { transform: translateY(-1px); }
.rhdx-conflict-btn:disabled { opacity: .5; cursor: default; }

.rhdx-conflict-footer {
    display: flex; justify-content: space-between; gap: 12px;
    padding-top: 16px;
    border-top: 1px solid rgba(196,192,186,0.4);
    flex-wrap: wrap;
}
.rhdx-conflict-footer-left, .rhdx-conflict-footer-right {
    display: flex; gap: 8px;
}
.rhdx-conflict-bulk {
    padding: 9px 16px;
    border-radius: 10px;
    border: none;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 2px 2px 6px #d4d7de, -2px -2px 6px #fff;
    transition: transform .15s;
}
.rhdx-conflict-bulk:hover { transform: translateY(-1px); }
.rhdx-conflict-bulk.keep-all { background: #f0f1f3; color: #6a6660; }
.rhdx-conflict-bulk.apply-all { background: #4878a6; color: #fff; }
.rhdx-conflict-bulk.close { background: #ffffff; color: #6a6660; }
</style>

<div class="rhdx-conflict-backdrop" id="rhdxConflictModal">
  <div class="rhdx-conflict-modal">
    <div class="rhdx-conflict-header">
      <div class="rhdx-conflict-ico">⚠</div>
      <div>
        <h3>Des champs sont déjà remplis — que faire ?</h3>
        <p>
          L'IA a extrait des informations de votre document<span class="rhdx-conflict-type" id="rhdxConflictType">—</span><br>
          Certaines valeurs diffèrent de celles déjà enregistrées sur votre profil. Choisissez au cas par cas.
        </p>
      </div>
    </div>
    <div class="rhdx-conflict-list" id="rhdxConflictList"></div>
    <div class="rhdx-conflict-footer">
      <div class="rhdx-conflict-footer-left">
        <button type="button" class="rhdx-conflict-bulk keep-all" onclick="rhdxConflictBulkKeep()">🚫 Tout garder ancien</button>
      </div>
      <div class="rhdx-conflict-footer-right">
        <button type="button" class="rhdx-conflict-bulk close" onclick="rhdxConflictClose()">Fermer</button>
        <button type="button" class="rhdx-conflict-bulk apply-all" onclick="rhdxConflictBulkApply()">✓ Tout appliquer</button>
      </div>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   PHASE 3 — Gestion modale conflits de champs (candidates UI)
   ═══════════════════════════════════════════════════════════════════════ */
let rhdxConflictState = { docType: null, candidates: {}, resolved: {} };

function rhdxConflictOpen(docType, candidates) {
    rhdxConflictState = { docType, candidates: candidates || {}, resolved: {} };
    const list = document.getElementById('rhdxConflictList');
    const typeLabel = document.getElementById('rhdxConflictType');
    typeLabel.textContent = docType || '—';
    list.innerHTML = '';

    for (const [field, diff] of Object.entries(candidates || {})) {
        const item = document.createElement('div');
        item.className = 'rhdx-conflict-item';
        item.id = 'rhdx-conflict-' + field;
        const oldVal = String(diff.old ?? '');
        const newVal = String(diff.new ?? '');
        item.innerHTML = `
            <div class="rhdx-conflict-field-name">${rhdxEscapeHtml(field.replace(/_/g, ' '))}</div>
            <div class="rhdx-conflict-values">
                <div class="rhdx-conflict-value old">${rhdxEscapeHtml(oldVal) || '<em>vide</em>'}</div>
                <div class="rhdx-conflict-arrow">→</div>
                <div class="rhdx-conflict-value new">${rhdxEscapeHtml(newVal) || '<em>vide</em>'}</div>
            </div>
            <div class="rhdx-conflict-actions">
                <button type="button" class="rhdx-conflict-btn keep" onclick="rhdxConflictKeep('${rhdxEscapeAttr(field)}')">🚫 Garder ancien</button>
                <button type="button" class="rhdx-conflict-btn apply" onclick="rhdxConflictApplyOne('${rhdxEscapeAttr(field)}')">✓ Écraser</button>
            </div>
        `;
        list.appendChild(item);
    }
    document.getElementById('rhdxConflictModal').classList.add('active');
}

function rhdxConflictClose() {
    document.getElementById('rhdxConflictModal').classList.remove('active');
    rhdxConflictState = { docType: null, candidates: {}, resolved: {} };
}

function rhdxConflictKeep(field) {
    rhdxConflictState.resolved[field] = 'keep';
    const el = document.getElementById('rhdx-conflict-' + field);
    if (el) {
        el.classList.remove('resolved-apply');
        el.classList.add('resolved-keep');
        el.querySelectorAll('button').forEach(b => b.disabled = true);
    }
    rhdxConflictCheckAllResolved();
}

async function rhdxConflictApplyOne(field) {
    const newVal = rhdxConflictState.candidates[field]?.new;
    if (newVal === undefined) return;
    const ok = await rhdxConflictSendApply({ [field]: newVal });
    if (ok) {
        rhdxConflictState.resolved[field] = 'apply';
        const el = document.getElementById('rhdx-conflict-' + field);
        if (el) {
            el.classList.remove('resolved-keep');
            el.classList.add('resolved-apply');
            el.querySelectorAll('button').forEach(b => b.disabled = true);
        }
        // Met à jour l'input du form en live
        const input = document.querySelector(`[name="${field}"]`);
        if (input) {
            input.value = newVal;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        rhdxConflictCheckAllResolved();
    }
}

async function rhdxConflictBulkApply() {
    const payload = {};
    for (const [field, diff] of Object.entries(rhdxConflictState.candidates)) {
        if (rhdxConflictState.resolved[field]) continue;
        payload[field] = diff.new;
    }
    if (Object.keys(payload).length === 0) { rhdxConflictClose(); return; }
    const ok = await rhdxConflictSendApply(payload);
    if (ok) {
        for (const field of Object.keys(payload)) {
            rhdxConflictState.resolved[field] = 'apply';
            const input = document.querySelector(`[name="${field}"]`);
            if (input) {
                input.value = payload[field];
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        showToast('✅ ' + Object.keys(payload).length + ' champ(s) écrasé(s)', 'ok');
        rhdxConflictClose();
    }
}

function rhdxConflictBulkKeep() {
    rhdxConflictClose();
    showToast('🚫 Toutes les anciennes valeurs conservées', 'ok');
}

function rhdxConflictCheckAllResolved() {
    const total = Object.keys(rhdxConflictState.candidates).length;
    const resolved = Object.keys(rhdxConflictState.resolved).length;
    if (resolved >= total && total > 0) {
        setTimeout(() => rhdxConflictClose(), 600);
    }
}

async function rhdxConflictSendApply(fields) {
    try {
        const r = await fetch('./api/rh_user_doc_apply_candidates.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({
                user_id:  PROFILE_USER_ID,
                doc_type: rhdxConflictState.docType,
                fields:   fields,
            }),
        });
        const data = await r.json();
        if (!data.ok) {
            showToast('⚠ ' + (data.error || 'Erreur application'), 'err');
            return false;
        }
        return true;
    } catch (e) {
        showToast('⚠ Erreur réseau : ' + e.message, 'err');
        return false;
    }
}

function rhdxEscapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
}
function rhdxEscapeAttr(s) {
    return String(s ?? '').replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}
</script>

<?php
$layout_content = ob_get_clean();
$layout_extra_js = '';
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>