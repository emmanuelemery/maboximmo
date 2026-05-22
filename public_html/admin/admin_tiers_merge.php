<?php
// admin/admin_tiers_merge.php — Détection + édition + fusion doublons tiers (super admin)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);

// Accès : utilisateurs connectés. Le scope est appliqué selon le rôle :
//   - Super admin (role 1) ou Admin (role 7) → voient TOUS les tiers
//   - Manager (role 2)                        → tiers de sa société (toutes agences confondues)
//   - Autres rôles                            → tiers de SON agence uniquement
$isSuperAdmin = ($roleId === 1);
$isAdmin      = function_exists('is_admin_or_super_admin') ? is_admin_or_super_admin() : ($roleId === 1 || $roleId === 7);
$isManager    = ($roleId === 1 || $roleId === 2);
$idSocSession = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAgeSession = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;

// Construit le WHERE de scope tiers (appliqué partout : détection doublons + recherche)
$scopeWhere = '1=1';
$scopeBind  = [];
if (!$isAdmin) {
    if ($isManager && $idSocSession !== null) {
        // Manager d'une société : tous les tiers de sa société
        $scopeWhere = '(t.id_societe = ? OR t.id_societe IS NULL)';
        $scopeBind  = [$idSocSession];
    } elseif ($idAgeSession !== null) {
        // Utilisateur standard : tiers de son agence uniquement
        $scopeWhere = '(t.id_agence = ? OR t.id_agence IS NULL)';
        $scopeBind  = [$idAgeSession];
    } else {
        // Pas de scope défini → refus prudent
        http_response_code(403);
        exit('Accès refusé : aucune société/agence définie en session.');
    }
}
$scopeLabel = $isAdmin
    ? '🌐 Toutes sociétés / agences (admin)'
    : ($isManager
        ? '🏢 Société #' . $idSocSession
        : '🏠 Agence #' . $idAgeSession);

$q = trim((string)($_GET['q'] ?? ''));

// ─── Auto-create table tiers_non_doublons + charge les groupes ignorés ─
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tiers_non_doublons` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `criteria_type` VARCHAR(20) NOT NULL,
        `criteria_key`  VARCHAR(255) NOT NULL,
        `tiers_ids_csv` VARCHAR(500) NOT NULL,
        `notes`         TEXT NULL,
        `marked_by`     INT UNSIGNED NULL,
        `marked_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_group` (`criteria_type`, `criteria_key`, `tiers_ids_csv`),
        INDEX `idx_type_key` (`criteria_type`, `criteria_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$ignoredGroups = [];
try {
    $stIg = $pdo->query("SELECT criteria_type, criteria_key, tiers_ids_csv, notes, marked_at FROM tiers_non_doublons");
    while ($r = $stIg->fetch(PDO::FETCH_ASSOC)) {
        $key = $r['criteria_type'] . '||' . $r['criteria_key'] . '||' . $r['tiers_ids_csv'];
        $ignoredGroups[$key] = $r;
    }
} catch (Throwable $e) {}

// ─── Détection automatique des candidats doublons (4 niveaux, scopé) ─
$autoCandidates = [];
try {
    // 🔴 SIREN identique
    $sql = "SELECT REPLACE(t.siren, ' ', '') AS k, GROUP_CONCAT(t.id ORDER BY t.id) AS ids, COUNT(*) AS n
        FROM tiers t WHERE t.siren IS NOT NULL AND t.siren <> '' AND $scopeWhere
        GROUP BY REPLACE(t.siren, ' ', '') HAVING COUNT(*) > 1 LIMIT 100";
    $stmt = $pdo->prepare($sql); $stmt->execute($scopeBind);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $autoCandidates[] = ['type' => '🔴 Même SIREN', 'key' => $r['k'], 'ids' => explode(',', $r['ids']), 'klass' => 'siren'];
    }
    // 🟠 Email identique
    $sql = "SELECT LOWER(t.email) AS k, GROUP_CONCAT(t.id ORDER BY t.id) AS ids, COUNT(*) AS n
        FROM tiers t WHERE t.email IS NOT NULL AND t.email <> '' AND $scopeWhere
        GROUP BY LOWER(t.email) HAVING COUNT(*) > 1 LIMIT 100";
    $stmt = $pdo->prepare($sql); $stmt->execute($scopeBind);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $autoCandidates[] = ['type' => '🟠 Même email', 'key' => $r['k'], 'ids' => explode(',', $r['ids']), 'klass' => 'email'];
    }
    // 🟡 Raison sociale proche
    $sql = "SELECT LOWER(LEFT(REPLACE(REPLACE(t.raison_sociale, ' SAS', ''), ' SARL', ''), 12)) AS k,
        GROUP_CONCAT(t.id ORDER BY t.id) AS ids, COUNT(*) AS n
        FROM tiers t WHERE t.raison_sociale IS NOT NULL AND CHAR_LENGTH(t.raison_sociale) >= 6 AND $scopeWhere
        GROUP BY k HAVING COUNT(*) > 1 LIMIT 100";
    $stmt = $pdo->prepare($sql); $stmt->execute($scopeBind);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $autoCandidates[] = ['type' => '🟡 Raison sociale proche', 'key' => $r['k'], 'ids' => explode(',', $r['ids']), 'klass' => 'raison'];
    }
    // 🟡 Nom + prénom identiques (personnes physiques)
    $sql = "SELECT CONCAT(LOWER(COALESCE(t.prenom, '')), '|', LOWER(COALESCE(t.nom, ''))) AS k,
        GROUP_CONCAT(t.id ORDER BY t.id) AS ids, COUNT(*) AS n
        FROM tiers t
        WHERE t.nom IS NOT NULL AND t.nom <> ''
          AND (t.raison_sociale IS NULL OR t.raison_sociale = '')
          AND $scopeWhere
        GROUP BY k HAVING COUNT(*) > 1 LIMIT 100";
    $stmt = $pdo->prepare($sql); $stmt->execute($scopeBind);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $autoCandidates[] = ['type' => '🟡 Nom + prénom', 'key' => $r['k'], 'ids' => explode(',', $r['ids']), 'klass' => 'nom'];
    }
} catch (Throwable $e) { $autoErr = $e->getMessage(); }

// ─── Filtrer les groupes marqués "pas un doublon" ────────────────────
$ignoredCandidates = [];
$autoCandidatesFiltered = [];
foreach ($autoCandidates as $c) {
    $idsSorted = $c['ids']; sort($idsSorted);
    $idsCsv = implode(',', $idsSorted);
    // Convertit klass interne (siren/email/raison/nom) au criteria_type
    $criteriaType = $c['klass'];
    $key = $criteriaType . '||' . $c['key'] . '||' . $idsCsv;
    if (isset($ignoredGroups[$key])) {
        $c['ignored_at'] = $ignoredGroups[$key]['marked_at'];
        $c['ignored_notes'] = $ignoredGroups[$key]['notes'];
        $ignoredCandidates[] = $c;
    } else {
        $autoCandidatesFiltered[] = $c;
    }
}
$autoCandidates = $autoCandidatesFiltered;

// ─── Recherche libre (scopée) ────────────────────────────────────────
$searchResults = [];
if ($q !== '') {
    $sql = "SELECT t.id FROM tiers t
        WHERE (LOWER(t.nom) LIKE LOWER(?) OR LOWER(t.prenom) LIKE LOWER(?) OR LOWER(t.raison_sociale) LIKE LOWER(?)
           OR LOWER(t.email) LIKE LOWER(?) OR REPLACE(t.siren, ' ', '') = ?)
           AND $scopeWhere
        ORDER BY t.id DESC LIMIT 100";
    $st = $pdo->prepare($sql);
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like, preg_replace('/\D/', '', $q)];
    foreach ($scopeBind as $b) $params[] = $b;
    $st->execute($params);
    $searchResults = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id');
}

// ─── Helper : récupère détail d'un tiers + biens/immeubles/baux liés ─
function _tiers_full(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT t.id, t.type_tiers, t.civilite, t.nom, t.prenom, t.raison_sociale,
        t.siren, t.email, t.telephone, t.ville, t.code_postal,
        t.nom_affichage, t.source_creation, t.date_creation, t.notes_internes,
        (SELECT COUNT(*) FROM tiers_roles WHERE id_tiers = t.id AND actif = 1) AS nb_roles,
        (SELECT COUNT(*) FROM bien_baux WHERE id_tiers_locataire = t.id) AS nb_baux_locataire
        FROM tiers t WHERE id IN ($in)");
    $st->execute($ids);
    $tiers = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$r['id'];

        // ─── 1. BIENS liés ────────────────────────────────────────
        $biens = [];

        // 1.a — via tiers_roles (objet='bien')
        try {
            $stB = $pdo->prepare("SELECT DISTINCT b.id, b.reference_bien, b.adresse_1, b.ville, b.code_postal, tr.role_code, tr.quote_part
                FROM tiers_roles tr
                INNER JOIN biens b ON b.id = tr.id_objet
                WHERE tr.id_tiers = ? AND tr.objet_type = 'bien' AND tr.actif = 1
                LIMIT 50");
            $stB->execute([$tid]);
            while ($b = $stB->fetch(PDO::FETCH_ASSOC)) {
                $biens[$b['id']] = $b;
            }
        } catch (Throwable $e) {}

        // 1.b — via biens.id_proprietaire (legacy proprietaires.id_tiers)
        try {
            $stP = $pdo->prepare("SELECT DISTINCT b.id, b.reference_bien, b.adresse_1, b.ville, b.code_postal
                FROM proprietaires p
                INNER JOIN biens b ON b.id_proprietaire = p.id
                WHERE p.id_tiers = ? LIMIT 50");
            $stP->execute([$tid]);
            while ($b = $stP->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($biens[$b['id']])) {
                    $b['role_code'] = 'proprietaire';
                    $b['quote_part'] = null;
                    $biens[$b['id']] = $b;
                }
            }
        } catch (Throwable $e) {}
        $r['biens'] = array_values($biens);

        // ─── 2. IMMEUBLES liés ────────────────────────────────────
        $immeubles = [];

        // 2.a — via tiers_roles (objet='immeuble')
        try {
            $stI = $pdo->prepare("SELECT DISTINCT i.id, i.nom_immeuble, i.adresse_1, i.ville, i.code_postal, tr.role_code
                FROM tiers_roles tr
                INNER JOIN immeubles i ON i.id = tr.id_objet
                WHERE tr.id_tiers = ? AND tr.objet_type = 'immeuble' AND tr.actif = 1
                LIMIT 50");
            $stI->execute([$tid]);
            while ($i = $stI->fetch(PDO::FETCH_ASSOC)) {
                $immeubles[$i['id']] = $i;
            }
        } catch (Throwable $e) {}

        // 2.b — via agency_mandant.id_tiers (legacy syndic)
        try {
            $stAm = $pdo->prepare("SELECT DISTINCT i.id, i.nom_immeuble, i.adresse_1, i.ville, i.code_postal, am.type_mandant
                FROM agency_mandant am
                INNER JOIN immeubles i ON i.id = am.id_immeuble
                WHERE am.id_tiers = ? LIMIT 50");
            $stAm->execute([$tid]);
            while ($im = $stAm->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($immeubles[$im['id']])) {
                    $im['role_code'] = $im['type_mandant'] ?? 'mandant';
                    $immeubles[$im['id']] = $im;
                }
            }
        } catch (Throwable $e) {}
        $r['immeubles'] = array_values($immeubles);

        // ─── 3. BAUX où ce tiers est locataire ────────────────────
        try {
            $stL = $pdo->prepare("SELECT bb.id AS bail_id, bb.id_bien, bb.statut, bb.bail_nature,
                b.reference_bien, b.adresse_1, b.ville
                FROM bien_baux bb LEFT JOIN biens b ON b.id = bb.id_bien
                WHERE bb.id_tiers_locataire = ? LIMIT 50");
            $stL->execute([$tid]);
            $r['baux'] = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $r['baux'] = []; }

        // ─── 4. Rôles globaux (objet_type IS NULL) ────────────────
        try {
            $stG = $pdo->prepare("SELECT DISTINCT role_code FROM tiers_roles
                WHERE id_tiers = ? AND objet_type IS NULL AND actif = 1");
            $stG->execute([$tid]);
            $r['roles_globaux'] = array_column($stG->fetchAll(PDO::FETCH_ASSOC), 'role_code');
        } catch (Throwable $e) { $r['roles_globaux'] = []; }

        $tiers[] = $r;
    }
    return $tiers;
}

$pageTitle = 'Tiers — Doublons & fusion';
$pageSubtitle = 'Super admin — Édition inline, biens liés, fusion';

$extraCss = <<<'CSS'
<style>
body { background:#f7f4ef; }
.tm-wrap { max-width:1500px; }
.tm-card { background:#fff; border-radius:10px; padding:16px 20px; margin-bottom:14px; box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff; }
.tm-card h3 { margin:0 0 10px; font-size:14px; }
.tm-search { display:flex; gap:8px; }
.tm-search input { flex:1; padding:8px 10px; border:1px solid #e3dfd8; border-radius:8px; font-family:inherit; }
.tm-btn { padding:8px 14px; border:none; border-radius:8px; background:#4878a6; color:#fff; cursor:pointer; font-weight:600; font-size:12.5px; }
.tm-btn.danger { background:#a8323b; }
.tm-btn.ghost  { background:#fff; color:#4878a6; box-shadow:4px 4px 10px #c8c4be,-4px -4px 10px #fff; }
.tm-btn.sm { padding:5px 10px; font-size:11px; }

.tm-group { background:#fafafa; border-left:4px solid #fb923c; padding:12px 14px; margin-bottom:14px; border-radius:6px; }
.tm-group.siren { border-color:#a8323b; background:#fef5f5; }
.tm-group.email { border-color:#fb923c; background:#fff9f3; }
.tm-group.raison{ border-color:#fbbf24; background:#fffdf5; }
.tm-group.nom   { border-color:#fbbf24; background:#fffdf5; }
.tm-group-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }

.tm-tiers-row { background:#fff; border-radius:8px; padding:10px 12px; margin-top:8px; display:grid;
    grid-template-columns: 32px 110px 1.5fr 1fr 110px 100px 1fr 100px; gap:10px; align-items:start; font-size:12px; border:1px solid #f0ece6; }
.tm-tiers-row > div { min-width:0; overflow:hidden; }
.tm-tiers-row .ref { font-family:'DM Mono',monospace; color:#4878a6; font-weight:700; padding-top:4px; min-width:0; }
.tm-tiers-row .col-label { display:block; font-size:9.5px; color:#9a9690; text-transform:uppercase; letter-spacing:.04em; margin-bottom:2px; }
.tm-tiers-row .tm-source { font-size:10px; color:#7a766f; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100px; display:block; }

.tm-edit { width:100%; border:1px solid transparent; background:transparent; padding:3px 5px; font-size:12px; font-family:inherit;
    border-radius:4px; transition:all .12s; }
.tm-edit:hover { background:#f4f1ec; border-color:#e3dfd8; }
.tm-edit:focus { background:#fff; border-color:#4878a6; outline:none; }
.tm-edit.dirty { background:#fff7e6 !important; border-color:#f59e0b !important; }
.tm-edit.saved { background:#d9f0db !important; }
.tm-edit.error { background:#fbe9e9 !important; }
.tm-edit strong { font-weight:700; }

.tm-save-row { background:#fff; color:#2d6a35; border:1px solid #2d6a35; padding:5px 10px; font-size:11px; font-weight:700;
    border-radius:6px; cursor:pointer; margin-top:4px; width:100%; }
.tm-save-row:hover { background:#2d6a35; color:#fff; }
.tm-save-row.has-changes { background:#fff7e6; color:#92400e; border-color:#f59e0b; animation: pulse 1.5s infinite; }
.tm-save-row.has-changes:hover { background:#f59e0b; color:#fff; }
.tm-save-row.saved { background:#2d6a35; color:#fff; border-color:#2d6a35; }
.tm-save-row:disabled { background:#f4f1ec; color:#c8c4be; border-color:#e3dfd8; cursor:not-allowed; animation:none; }

@keyframes pulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(245,158,11,.4); }
    50%     { box-shadow: 0 0 0 6px rgba(245,158,11,0); }
}

.tm-biens-list { font-size:10.5px; max-height:180px; overflow-y:auto; padding-right:4px; }
.tm-biens-list a { display:block; color:#4878a6; text-decoration:none; padding:2px 0; line-height:1.3; }
.tm-biens-list a:hover { text-decoration:underline; background:#fafafa; }
.tm-biens-list .role { color:#7a766f; font-size:9.5px; margin-left:4px; }
.tm-baux-list { font-size:10.5px; max-height:80px; overflow-y:auto; }
.tm-baux-list a { color:#2d6a35; }

.tm-stat-mini { display:inline-block; padding:1px 6px; border-radius:99px; background:#eef4fb; color:#2c5687;
    font-size:10px; font-weight:700; font-family:'DM Mono',monospace; margin-right:3px; }
.tm-source { font-size:10px; color:#7a766f; }
.tm-empty { padding:40px; text-align:center; color:#9a9690; }

/* Modale création indivision */
.indiv-backdrop { position:fixed; inset:0; background:rgba(40,38,36,.6); display:none;
    align-items:center; justify-content:center; z-index:9000; }
.indiv-backdrop.show { display:flex; }
.indiv-modal { background:#fff; border-radius:14px; padding:24px; width:560px; max-width:92vw; max-height:90vh; overflow:auto;
    box-shadow:0 20px 50px rgba(0,0,0,.3); }
.indiv-modal h3 { margin:0 0 8px; }
.indiv-modal label.field-lbl { display:block; font-size:11px; font-weight:700; color:#5a5650; margin:14px 0 4px; text-transform:uppercase; letter-spacing:.04em; }
.indiv-modal input.field-name { width:100%; padding:10px 12px; border:1px solid #e3dfd8; border-radius:8px; font-size:13px; font-family:inherit; box-sizing:border-box; }
.indiv-option { display:block; padding:12px 14px; margin:8px 0; border:2px solid #e3dfd8; border-radius:10px; cursor:pointer; transition:all .15s; }
.indiv-option:hover { border-color:#7c3aed; background:#fafaff; }
.indiv-option input[type=radio] { margin-right:8px; }
.indiv-option:has(input:checked) { border-color:#7c3aed; background:#f5f3ff; }
.indiv-option .opt-title { font-weight:700; color:#2c2a28; font-size:13px; }
.indiv-option .opt-desc { font-size:11.5px; color:#5a5650; margin-top:4px; }
.indiv-option .opt-tag { display:inline-block; padding:1px 6px; border-radius:4px; background:#ede9fe; color:#5b21b6; font-size:10px; font-weight:700; margin-left:4px; }
.indiv-foot { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }

@media (max-width: 1200px) {
    .tm-tiers-row { grid-template-columns: 32px 50px 1fr 1fr; row-gap:6px; }
    .tm-tiers-row > div:nth-child(n+5) { grid-column: 1 / -1; }
}
</style>
CSS;

include __DIR__ . '/../inc/agency_layout_top.php';
?>

<div class="tm-wrap">

<!-- Bandeau scope actif -->
<div class="tm-card" style="background:#eef4fb; border-left:4px solid #4878a6; padding:10px 16px; margin-bottom:14px;">
    <div style="display:flex; align-items:center; gap:12px; font-size:12.5px;">
        <strong>Périmètre de recherche :</strong>
        <span style="background:#fff; padding:3px 10px; border-radius:99px; font-weight:700; color:#2c5687;">
            <?= h($scopeLabel) ?>
        </span>
        <span style="color:#7a766f; font-size:11px;">
            <?php if ($isAdmin): ?>
                Toi en tant qu'admin → tu vois tous les tiers de toutes les sociétés/agences.
            <?php elseif ($isManager): ?>
                Tu vois tous les tiers de ta société (toutes ses agences).
            <?php else: ?>
                Tu vois uniquement les tiers de ton agence. (Pour voir d'autres agences, contacte un admin.)
            <?php endif; ?>
        </span>
    </div>
</div>

<!-- Recherche libre -->
<div class="tm-card">
    <h3>🔍 Recherche libre dans tous les tiers</h3>
    <form method="get" class="tm-search">
        <input type="text" name="q" placeholder="nom, raison sociale, email, SIREN…" value="<?= h($q) ?>" autofocus>
        <button class="tm-btn">Chercher</button>
        <?php if ($q !== ''): ?><a class="tm-btn ghost" href="<?= h(app_url('/admin/admin_tiers_merge.php')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($q !== '' && !empty($searchResults)):
        $resFull = _tiers_full($pdo, $searchResults);
    ?>
        <div style="margin-top:12px;">
            <strong><?= count($resFull) ?> résultat(s)</strong>
            <?php foreach ($resFull as $t): renderTiersRow($t, 'search', null); endforeach; ?>
        </div>
    <?php elseif ($q !== ''): ?>
        <div class="tm-empty">Aucun résultat.</div>
    <?php endif; ?>
</div>

<!-- Doublons détectés -->
<div class="tm-card">
    <h3>⚠️ Doublons détectés (<?= count($autoCandidates) ?> groupes)</h3>
    <p style="font-size:12px; color:#7a766f;">
        🔴 même SIREN (= doublon certain) · 🟠 même email · 🟡 raison sociale proche ou nom+prénom identique<br>
        <strong>Édition inline</strong> : clique sur un champ pour le modifier, valide en sortant (blur ou Entrée).<br>
        <strong>Fusion</strong> : coche le tiers à GARDER → bouton "Fusionner les autres dans celui-ci".
    </p>

    <?php if (empty($autoCandidates)): ?>
        <div class="tm-empty">✅ Aucun doublon détecté.</div>
    <?php else: ?>
        <?php foreach ($autoCandidates as $idx => $cand):
            $details = _tiers_full($pdo, $cand['ids']);
        ?>
        <div class="tm-group <?= h($cand['klass']) ?>" data-idx="<?= $idx ?>">
            <div class="tm-group-head">
                <div>
                    <strong><?= h($cand['type']) ?></strong> —
                    <code style="font-size:11px; background:#fff; padding:2px 6px; border-radius:3px;"><?= h($cand['key']) ?></code>
                    <span style="color:#7a766f; font-size:11px;">(<?= count($details) ?> tiers)</span>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="tm-btn ghost sm"
                            onclick="tmIgnoreGroup('<?= h($cand['klass']) ?>', <?= htmlspecialchars(json_encode($cand['key']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(array_column($details, 'id')), ENT_QUOTES) ?>)"
                            title="Marquer comme distincts intentionnels (ne plus afficher)">
                        ✗ Pas un doublon
                    </button>
                    <button type="button" class="tm-btn sm" style="background:#7c3aed; color:#fff;"
                            onclick="tmCreateIndivision(<?= htmlspecialchars(json_encode(array_column($details, 'id')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(array_map(fn($d) => trim((string)$d['prenom'] . ' ' . $d['nom']) ?: $d['raison_sociale'], $details)), ENT_QUOTES) ?>)"
                            title="Crée un tiers indivision regroupant ces tiers (Mr+Mme par ex)">
                        🤝 Créer indivision
                    </button>
                    <button type="button" class="tm-btn danger sm" onclick="tmFusion(<?= $idx ?>, <?= htmlspecialchars(json_encode(array_column($details, 'id')), ENT_QUOTES) ?>)">
                        🔀 Fusionner dans le tiers coché
                    </button>
                </div>
            </div>
            <?php foreach ($details as $t): renderTiersRow($t, 'group', $idx); endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Doublons ignorés (revisitables) -->
<?php if (!empty($ignoredCandidates)): ?>
<div class="tm-card">
    <h3>✗ Groupes marqués "Pas un doublon" (<?= count($ignoredCandidates) ?>)</h3>
    <p style="font-size:11px; color:#7a766f;">Ces groupes ne sont plus signalés comme doublons potentiels. Tu peux les <strong>réactiver</strong> à tout moment.</p>
    <?php foreach ($ignoredCandidates as $idx => $cand):
        $details = _tiers_full($pdo, $cand['ids']);
        $idsCsv = implode(',', array_map('intval', $cand['ids']));
    ?>
        <div class="tm-group <?= h($cand['klass']) ?>" style="opacity:.7;">
            <div class="tm-group-head">
                <div>
                    <strong>✗ <?= h($cand['type']) ?></strong> —
                    <code style="font-size:11px; background:#fff; padding:2px 6px; border-radius:3px;"><?= h($cand['key']) ?></code>
                    <span style="color:#7a766f; font-size:11px;">
                        (<?= count($details) ?> tiers) · ignoré le <?= h(date('d/m/y', strtotime($cand['ignored_at']))) ?>
                    </span>
                </div>
                <button type="button" class="tm-btn sm"
                        onclick="tmReactivateGroup('<?= h($cand['klass']) ?>', <?= htmlspecialchars(json_encode($cand['key']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cand['ids']), ENT_QUOTES) ?>)">
                    ↻ Réactiver
                </button>
            </div>
            <div style="font-size:11px; color:#5a5650;">
                Tiers :
                <?php foreach ($details as $t): ?>
                    <a href="#" style="margin-right:8px;">#<?= (int)$t['id'] ?> <?= h($t['raison_sociale'] ?: trim((string)$t['prenom'] . ' ' . $t['nom'])) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>

<!-- Modale création indivision -->
<div class="indiv-backdrop" id="indiv-modal" data-ids="[]">
    <div class="indiv-modal">
        <h3>🤝 Créer une indivision</h3>
        <div style="font-size:12px; color:#7a766f; margin-bottom:8px;">
            Indivisaires : <strong id="indiv-modal-names"></strong>
        </div>

        <label class="field-lbl">Nom de l'indivision</label>
        <input type="text" class="field-name" id="indiv-modal-name" placeholder="Indivision DUPONT / MARTIN">

        <label class="field-lbl">Mode de répartition des rôles propriétaire</label>

        <label class="indiv-option">
            <input type="radio" name="indiv-mode" value="individuel" checked>
            <span class="opt-title">👫 Garder les 2 propriétaires séparément, quote_part <span id="indiv-modal-qp">50%</span></span>
            <span class="opt-tag">RECOMMANDÉ pour Mr+Mme</span>
            <div class="opt-desc">
                Chaque personne reste propriétaire de manière individuelle (avec sa part).
                <strong>2 RIB possibles</strong>, courriers séparés, CRG individuel.
                L'indivision sert uniquement de regroupement visuel (via tiers_contacts).
            </div>
        </label>

        <label class="indiv-option">
            <input type="radio" name="indiv-mode" value="unifie">
            <span class="opt-title">🏛️ Indivision seule propriétaire à 100%</span>
            <div class="opt-desc">
                L'indivision devient le seul propriétaire. Les rôles individuels sont désactivés
                (gardés en historique). <strong>1 RIB unique</strong>, courriers groupés.
            </div>
        </label>

        <label class="indiv-option">
            <input type="radio" name="indiv-mode" value="mixte">
            <span class="opt-title">⚖️ Les 3 coexistent (indivision 100% + personnes 50/50)</span>
            <div class="opt-desc">
                Affichage souple : tu peux choisir au cas par cas qui recevoir.
                Attention : redondance (3 propriétaires affichés sur le bien).
            </div>
        </label>

        <label class="indiv-option">
            <input type="radio" name="indiv-mode" value="aucun">
            <span class="opt-title">📎 Regroupement uniquement (tiers_contacts)</span>
            <div class="opt-desc">
                Aucun rôle propriétaire modifié. L'indivision est créée juste pour grouper les personnes.
                À utiliser si tu veux configurer les rôles plus tard manuellement.
            </div>
        </label>

        <div style="font-size:11px; color:#7a766f; background:#f4f1ec; padding:10px; border-radius:6px; margin-top:12px;">
            ℹ️ <strong>Dans tous les cas</strong> : les fiches individuelles des indivisaires sont conservées
            (jamais supprimées). Tu peux toujours leur écrire séparément via leur fiche tiers personnelle.
        </div>

        <div class="indiv-foot">
            <button type="button" class="tm-btn ghost" onclick="tmCloseIndivModal()">Annuler</button>
            <button type="button" class="tm-btn" style="background:#7c3aed;" onclick="tmSubmitIndivision()">Créer l'indivision</button>
        </div>
    </div>
</div>

<?php
function renderTiersRow(array $t, string $context, ?int $idx): void {
    $rname = $context === 'group' ? ('dest_' . $idx) : ('dest_search_' . $t['id']);
    ?>
    <div class="tm-tiers-row">
        <div style="padding-top:4px;">
            <?php if ($context === 'group'): ?>
                <input type="radio" name="<?= h($rname) ?>" value="<?= (int)$t['id'] ?>" data-id="<?= (int)$t['id'] ?>">
            <?php endif; ?>
        </div>
        <div class="ref">
            #<?= (int)$t['id'] ?>
            <div class="tm-source"><?= h($t['source_creation'] ?? '') ?></div>
            <div class="tm-source"><?= $t['date_creation'] ? h(date('d/m/y', strtotime($t['date_creation']))) : '' ?></div>
        </div>
        <div>
            <span class="col-label">Raison sociale / Nom</span>
            <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="raison_sociale"
                   value="<?= h($t['raison_sociale'] ?? '') ?>" placeholder="SCI, SARL, etc.">
            <div style="display:flex; gap:4px; margin-top:3px;">
                <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="prenom" value="<?= h($t['prenom'] ?? '') ?>" placeholder="prénom" style="flex:0 0 40%;">
                <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="nom" value="<?= h($t['nom'] ?? '') ?>" placeholder="nom" style="flex:1;">
            </div>
            <div style="font-size:10px; color:#9a9690; margin-top:2px;"><?= h($t['type_tiers']) ?></div>
        </div>
        <div>
            <span class="col-label">SIREN</span>
            <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="siren" value="<?= h($t['siren'] ?? '') ?>" placeholder="9 chiffres" style="font-family:'DM Mono',monospace;">
            <span class="col-label" style="margin-top:6px;">Email</span>
            <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="email" value="<?= h($t['email'] ?? '') ?>" placeholder="email" type="email">
            <span class="col-label" style="margin-top:6px;">Téléphone</span>
            <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="telephone" value="<?= h($t['telephone'] ?? '') ?>">
        </div>
        <div>
            <span class="col-label">Ville</span>
            <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="ville" value="<?= h($t['ville'] ?? '') ?>">
            <input class="tm-edit" data-id="<?= (int)$t['id'] ?>" data-field="code_postal" value="<?= h($t['code_postal'] ?? '') ?>" placeholder="CP" style="margin-top:3px;">
        </div>
        <div>
            <span class="col-label">Stats</span>
            <div><span class="tm-stat-mini"><?= (int)$t['nb_roles'] ?> rôles</span></div>
            <div><span class="tm-stat-mini"><?= (int)$t['nb_baux_locataire'] ?> baux loc</span></div>
        </div>
        <div>
            <span class="col-label">Biens / Immeubles / Baux liés</span>
            <div class="tm-biens-list">
                <?php
                $nbBiens = count($t['biens'] ?? []);
                $nbImms  = count($t['immeubles'] ?? []);
                $nbBaux  = count($t['baux'] ?? []);
                $rolesGlobaux = $t['roles_globaux'] ?? [];

                if ($nbBiens === 0 && $nbImms === 0 && $nbBaux === 0):
                ?>
                    <span style="color:#c8c4be; font-style:italic;">Aucun bien/immeuble/bail lié</span>
                    <?php if (!empty($rolesGlobaux)): ?>
                        <div style="font-size:10px; color:#7a766f; margin-top:2px;">
                            Rôle(s) global(aux) : <?= h(implode(', ', $rolesGlobaux)) ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($nbBiens > 0): ?>
                        <div style="margin-bottom:3px;"><strong style="color:#2c5687; font-size:10px;">🏠 Biens (<?= $nbBiens ?>)</strong></div>
                        <?php foreach ($t['biens'] as $b): ?>
                            <a href="<?= h(app_url('/bien_detail.php?edit=' . $b['id'])) ?>" target="_blank" title="<?= h(trim((string)$b['adresse_1'] . ' ' . $b['code_postal'] . ' ' . $b['ville'])) ?>">
                                <?= h($b['reference_bien'] ?: '#' . $b['id']) ?>
                                <span style="color:#5a5650;">· <?= h($b['adresse_1'] ?: $b['ville']) ?></span>
                                <span class="role">[<?= h($b['role_code']) ?><?= !empty($b['quote_part']) ? ' ' . (int)$b['quote_part'] . '%' : '' ?>]</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($nbImms > 0): ?>
                        <div style="margin-top:5px; margin-bottom:3px;"><strong style="color:#7a3d52; font-size:10px;">🏢 Immeubles (<?= $nbImms ?>)</strong></div>
                        <?php foreach ($t['immeubles'] as $im): ?>
                            <a href="<?= h(app_url('/agency_immeuble_detail.php?id=' . $im['id'])) ?>" target="_blank" style="color:#7a3d52;" title="<?= h(trim((string)$im['adresse_1'] . ' ' . $im['code_postal'] . ' ' . $im['ville'])) ?>">
                                #<?= (int)$im['id'] ?>
                                <span style="color:#5a5650;">· <?= h($im['nom_immeuble'] ?: $im['adresse_1'] ?: $im['ville']) ?></span>
                                <span class="role">[<?= h($im['role_code']) ?>]</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($nbBaux > 0): ?>
                        <div style="margin-top:5px; margin-bottom:3px;"><strong style="color:#2d6a35; font-size:10px;">📋 Baux (<?= $nbBaux ?>)</strong></div>
                        <?php foreach ($t['baux'] as $bx): ?>
                            <a href="<?= h(app_url('/bien_detail.php?edit=' . $bx['id_bien'])) ?>" target="_blank" style="color:#2d6a35;">
                                Bail #<?= (int)$bx['bail_id'] ?>
                                <span style="color:#5a5650;">· <?= h($bx['reference_bien'] ?: '#' . $bx['id_bien']) ?></span>
                                <span class="role">[<?= h($bx['bail_nature']) ?> · <?= h($bx['statut']) ?>]</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;">
            <span class="col-label">Actions</span>
            <button type="button" class="tm-save-row" data-row-id="<?= (int)$t['id'] ?>" disabled>💾 Enregistrer</button>
            <button class="tm-btn ghost sm" style="margin-top:4px; width:100%;" onclick="window.open(<?= htmlspecialchars(json_encode(app_url('/bien_detail.php?edit=')), ENT_QUOTES) ?>, '_blank')" title="Recherche libre">🔎 Détails</button>
        </div>
    </div>
    <?php
}
?>

<script>
const APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;

// ── Édition inline : marque les champs comme "dirty" puis save au bouton ─
// Chaque ligne a son bouton 💾 qui s'active dès qu'un champ est modifié.
document.querySelectorAll('.tm-edit').forEach(input => {
    input.dataset.initial = input.value;
    input.addEventListener('input', () => {
        const dirty = input.value !== input.dataset.initial;
        input.classList.toggle('dirty', dirty);
        tmUpdateRowButton(input.dataset.id);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); tmSaveRow(input.dataset.id); }
    });
});

function tmUpdateRowButton(id) {
    const btn = document.querySelector('.tm-save-row[data-row-id="' + id + '"]');
    if (!btn) return;
    const dirtyFields = document.querySelectorAll('.tm-edit[data-id="' + id + '"].dirty');
    if (dirtyFields.length > 0) {
        btn.disabled = false;
        btn.classList.add('has-changes');
        btn.classList.remove('saved');
        btn.textContent = '💾 Enregistrer (' + dirtyFields.length + ')';
    } else {
        btn.disabled = true;
        btn.classList.remove('has-changes');
        btn.textContent = '💾 Enregistrer';
    }
}

// Click sur bouton 💾
document.querySelectorAll('.tm-save-row').forEach(btn => {
    btn.addEventListener('click', () => tmSaveRow(btn.dataset.rowId));
});

async function tmSaveRow(id) {
    const dirtyFields = document.querySelectorAll('.tm-edit[data-id="' + id + '"].dirty');
    if (dirtyFields.length === 0) return;
    const btn = document.querySelector('.tm-save-row[data-row-id="' + id + '"]');
    btn.disabled = true;
    btn.textContent = '⏳ Enregistrement...';

    let nbOk = 0, nbErr = 0;
    const errors = [];
    for (const input of dirtyFields) {
        try {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('field', input.dataset.field);
            fd.append('value', input.value);
            const res = await fetch(APP_BASE + '/api/admin_tiers_edit.php', { method:'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                nbOk++;
                input.classList.remove('dirty');
                input.classList.add('saved');
                input.dataset.initial = input.value;
                setTimeout(() => input.classList.remove('saved'), 1500);
            } else {
                nbErr++;
                input.classList.remove('dirty');
                input.classList.add('error');
                errors.push(input.dataset.field + ' : ' + (data.error || '?'));
            }
        } catch (e) {
            nbErr++;
            input.classList.add('error');
            errors.push(input.dataset.field + ' : réseau');
        }
    }
    if (nbErr === 0) {
        btn.textContent = '✓ Enregistré (' + nbOk + ')';
        btn.classList.add('saved');
        btn.classList.remove('has-changes');
        setTimeout(() => { btn.textContent = '💾 Enregistrer'; btn.classList.remove('saved'); btn.disabled = true; }, 2000);
    } else {
        btn.textContent = '⚠ ' + nbOk + ' ok / ' + nbErr + ' erreurs';
        btn.classList.remove('has-changes');
        alert('Erreurs :\n' + errors.join('\n'));
    }
}

// ── Marquer un groupe "Pas un doublon" ──────────────────────────
async function tmIgnoreGroup(criteriaType, criteriaKey, tiersIds) {
    const notes = prompt('Pourquoi ce groupe n\'est-il pas un doublon ?\n(ex: "Mr et Mme, 2 fiches séparées" ou "5 SCI du même gérant Pierre DUPONT")\n\nOptionnel — Annuler pour ignorer sans note.', '');
    if (notes === null) return; // Annulé
    try {
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('criteria_type', criteriaType);
        fd.append('criteria_key', criteriaKey);
        tiersIds.forEach(id => fd.append('tiers_ids[]', id));
        if (notes) fd.append('notes', notes);
        const res = await fetch(APP_BASE + '/api/admin_tiers_ignore_group.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { alert('Erreur : ' + (data.error || '?')); }
    } catch (e) { alert('Erreur réseau'); }
}

async function tmReactivateGroup(criteriaType, criteriaKey, tiersIds) {
    if (!confirm('Réactiver ce groupe comme doublon potentiel ?')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'remove');
        fd.append('criteria_type', criteriaType);
        fd.append('criteria_key', criteriaKey);
        tiersIds.forEach(id => fd.append('tiers_ids[]', id));
        const res = await fetch(APP_BASE + '/api/admin_tiers_ignore_group.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) { location.reload(); }
        else { alert('Erreur : ' + (data.error || '?')); }
    } catch (e) { alert('Erreur réseau'); }
}

// ── Création d'indivision ────────────────────────────────────────
function tmCreateIndivision(tiersIds, tiersNames) {
    const autoName = 'Indivision ' + tiersNames.map(n => (n || '').split(' ').pop().toUpperCase()).filter(Boolean).join(' / ');
    const quotePart = (100 / tiersIds.length).toFixed(2);
    document.getElementById('indiv-modal-names').textContent = tiersNames.join(' + ');
    document.getElementById('indiv-modal-name').value = autoName;
    document.getElementById('indiv-modal-qp').textContent = quotePart + '%';
    document.getElementById('indiv-modal').dataset.ids = JSON.stringify(tiersIds);
    document.getElementById('indiv-modal').classList.add('show');
}
function tmCloseIndivModal() { document.getElementById('indiv-modal').classList.remove('show'); }

async function tmSubmitIndivision() {
    const modal = document.getElementById('indiv-modal');
    const tiersIds = JSON.parse(modal.dataset.ids || '[]');
    const nom = document.getElementById('indiv-modal-name').value.trim();
    if (!nom) { alert('Donne un nom à l\'indivision.'); return; }
    const mode = document.querySelector('input[name="indiv-mode"]:checked').value;

    try {
        const fd = new FormData();
        fd.append('nom', nom);
        fd.append('mode', mode);
        tiersIds.forEach(id => fd.append('tiers_ids[]', id));
        const res = await fetch(APP_BASE + '/api/admin_tiers_create_indivision.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            alert('✓ Indivision #' + data.indivision_id + ' créée : ' + data.nom
                + '\n• ' + data.nb_indivisaires + ' indivisaire(s) liés via tiers_contacts'
                + '\n• Mode : ' + data.mode_label
                + (data.nb_biens_traites ? '\n• ' + data.nb_biens_traites + ' bien(s) traité(s)' : '\n• Aucun bien à traiter'));
            location.reload();
        } else {
            alert('Erreur : ' + (data.error || '?'));
        }
    } catch (e) { alert('Erreur réseau : ' + (e.message || e)); }
}

// ── Fusion ───────────────────────────────────────────────────────
async function tmFusion(groupIdx, allIds) {
    const radio = document.querySelector('input[name="dest_' + groupIdx + '"]:checked');
    if (!radio) { alert('Coche d\'abord le tiers à GARDER (la cible).'); return; }
    const destId = parseInt(radio.value, 10);
    const sources = allIds.filter(id => parseInt(id, 10) !== destId);
    if (sources.length === 0) { alert('Aucun source à fusionner.'); return; }
    if (!confirm('Fusionner ' + sources.length + ' tiers source(s) [' + sources.join(', ') + '] DANS le tiers #' + destId + ' ?\n\nLes sources seront supprimés, leurs liens transférés vers la cible.\n\nIrréversible.')) return;

    const results = [];
    for (const srcId of sources) {
        const fd = new FormData();
        fd.append('source_id', srcId); fd.append('destination_id', destId);
        try {
            const res = await fetch(APP_BASE + '/api/admin_tiers_merge_action.php', { method:'POST', body: fd });
            const data = await res.json();
            results.push((data.ok ? '✓' : '✗') + ' #' + srcId + (data.ok ? '' : ' (' + (data.error || '?') + ')'));
        } catch (e) { results.push('✗ #' + srcId + ' (réseau)'); }
    }
    alert('Fusion terminée :\n' + results.join('\n'));
    location.reload();
}
</script>

<?php include __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
