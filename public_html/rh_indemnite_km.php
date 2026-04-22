<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';

require_login();

$pdo      = $GLOBALS['pdo'] ?? null;
$roleId   = current_role_id();
$userId   = current_user_id();
$agenceId = current_agence_id();

if (!$pdo) { http_response_code(500); exit('Erreur DB'); }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Charger l'utilisateur connecté ───────────────────────────────────────────
$stmtUser = $pdo->prepare("
    SELECT u.id, u.nom, u.prenom, u.vehicule_nom, u.vehicule_puissance_fiscale,
           u.id_agence,
           a.nom_agence, a.adresse_1 as agence_adresse, a.ville as agence_ville,
           a.code_postal as agence_cp, a.latitude as agence_lat, a.longitude as agence_lng
    FROM users u
    LEFT JOIN agences a ON a.id = u.id_agence
    WHERE u.id = ?
");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

// ── Domicile de l'utilisateur ─────────────────────────────────────────────────
$stmtDom = $pdo->prepare("SELECT * FROM rh_user_domicile WHERE id_user = ?");
$stmtDom->execute([$userId]);
$domicile = $stmtDom->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Liste de toutes les agences (pour changer le point de départ) ─────────────
$agences = $pdo->query("SELECT id, nom_agence, adresse_1, code_postal, ville, latitude, longitude FROM agences WHERE actif=1 ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);

// ── Sessions IK de cet utilisateur ───────────────────────────────────────────
$stmtSessions = $pdo->prepare("
    SELECT s.*,
           SUM(COALESCE(l.km_aller,0) + COALESCE(l.km_retour,0)) as total_km,
           COUNT(CASE WHEN l.id IS NOT NULL THEN 1 END) as nb_lignes
    FROM rh_ik_sessions s
    LEFT JOIN rh_ik_lignes l ON l.id_session = s.id
    WHERE s.id_user = ?
    GROUP BY s.id
    ORDER BY s.mois_deplacements DESC
");
$stmtSessions->execute([$userId]);
$sessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);
if ($sessions) {
    foreach ($sessions as &$s) {
        $s['mois_paie_bloque'] = rh_is_salary_month_closed($pdo, $s['mois_paie'] ?? '');
    }
    unset($s);
}

// ── Lignes par session ────────────────────────────────────────────────────────
$lignesParSession = [];
if ($sessions) {
    $ids = implode(',', array_column($sessions, 'id'));
    $stmtLignes = $pdo->query("
        SELECT l.*, a.nom_agence as depart_agence_nom
        FROM rh_ik_lignes l
        LEFT JOIN agences a ON a.id = l.id_agence_depart
        WHERE l.id_session IN ($ids)
        ORDER BY l.id_session, COALESCE(l.date_deplacement,'9999-12-31'), l.ordre, l.id
    ");
    foreach ($stmtLignes->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $lignesParSession[$ligne['id_session']][] = $ligne;
    }
}

// ── Motifs métier ─────────────────────────────────────────────────────────────
$motifs = [
    // Gestion / Location
    'Location', 'Visite', 'EDL', 'Rendez-vous locataire', 'Rendez-vous propriétaire',
    'Rendez-vous copropriétaire', 'Intervention prestataire', 'Sinistre',
    // Vente / Transaction
    'Vente', 'Estimation',
    // Syndic
    'Réunion syndic', 'Assemblée générale', 'Conseil syndical', 'Visite immeuble',
    // RH
    'Réunion RH', 'Formation', 'Entretien collaborateur', 'Recrutement',
    // Autre
    'Déplacement pro', 'Autre',
];

// ── Calcul récap global ───────────────────────────────────────────────────────
$totalGlobalKm = 0;
$totalGlobalDeps = 0;
foreach ($sessions as $s) {
    $totalGlobalKm  += (float)($s['total_km'] ?? 0);
    $totalGlobalDeps += (int)($s['nb_lignes'] ?? 0);
}

// ── Mois courant par défaut ───────────────────────────────────────────────────
$moisDefaut = date('Y-m');
$moisPaieDefaut = date('Y-m', strtotime('+1 month'));

// ── Noms des mois en français ─────────────────────────────────────────────────
$moisFr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
function moisLabel(string $ym): string {
    global $moisFr;
    [$y, $m] = explode('-', $ym);
    return ($moisFr[(int)$m] ?? $m) . ' ' . $y;
}

// ── Clé Google pour Places JS ────────────────────────────────────────────────
$googleConfigPaths = [
    __DIR__ . '/../u630423897/google_config.php',
    __DIR__ . '/google_config.php',
    __DIR__ . '/../google_config.php',
];
foreach ($googleConfigPaths as $p) { if (file_exists($p)) { require_once $p; break; } }
$gKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : ($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');

// ══════════════════════════════════════════════════════════════════════════════
//  LAYOUT VARIABLES
// ══════════════════════════════════════════════════════════════════════════════
$_userName = trim(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''));
$layout_title   = 'Indemnités KM — ' . h($_userName);
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#2f587d">' . count($sessions) . '</div><div class="ph-kpi-lbl">Sessions</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">' . $totalGlobalDeps . '</div><div class="ph-kpi-lbl">Déplacements</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">' . number_format($totalGlobalKm, 1, ',', ' ') . '</div><div class="ph-kpi-lbl">Total KM</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">' . h($user['nom_agence'] ?? '—') . '</div><div class="ph-kpi-lbl">Agence</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">' . h($user['vehicule_nom'] ?? '—') . '</div><div class="ph-kpi-lbl">Véhicule</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#2f587d;font-weight:700">' . h($user['prenom'] . ' ' . $user['nom']) . '</div><div class="ph-kpi-lbl">Collaborateur</div></div>
';

$layout_head_actions = '
    <a href="rh_salaires.php" class="ph-btn" title="Retour salaires">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Salaires
    </a>
    <button class="ph-btn primary" onclick="createSession()">+ Session</button>
    <button class="ph-btn" onclick="document.getElementById(\'domicile-modal\').classList.add(\'open\')">Domicile</button>
    <span class="ph-btn dispo">attente</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
        /* ── IK V2 : layout ───────────────────────────────────────────────── */
        .ik-page { display: flex; flex-direction: column; gap: 0; }

        .page-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        /* Section head */
        .sec-head { display:flex; align-items:center; gap:14px; margin:15px 0 10px; }
        .sec-txt { font-family:'DM Mono',monospace; font-size:10px; font-weight:500; letter-spacing:0.28em; text-transform:uppercase; white-space:nowrap; flex-shrink:0; background:linear-gradient(180deg,#7a9060 0%,#4a6038 40%,#304828 70%,#607848 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .line-l { height:1px; width:28px; flex-shrink:0; background:linear-gradient(90deg,transparent 0%,#304828 40%,#9ab870 100%); border-radius:2px; }
        .line-r { height:1px; flex:1; background:linear-gradient(90deg,#9ab870 0%,#607848 30%,#4a6038 55%,transparent 100%); border-radius:2px; }

        /* Boutons V2 */
        .v2-btn {
            display: inline-flex; align-items: center; gap: 6px;
            height: 32px; padding: 0 14px; border-radius: 999px; border: none; cursor: pointer;
            background: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 600; color: #3a3830;
            transition: all .15s; white-space: nowrap; text-decoration: none;
        }
        .v2-btn:hover { box-shadow: 2px 2px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light); }
        .v2-btn:disabled { opacity: .45; cursor: not-allowed; }
        .v2-btn.primary { background: #36577d; color: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light); }
        .v2-btn.success { background: #36577d; color: var(--bg-primary); }
        .v2-btn.danger  { background: #8a5040; color: var(--bg-primary); }

        /* Compat anciens boutons → V2 */
        .btn-rh { display: inline-flex; align-items: center; gap: 6px;
            height: 32px; padding: 0 14px; border-radius: 999px; border: none; cursor: pointer;
            background: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 600; color: #3a3830;
            transition: all .15s; white-space: nowrap; }
        .btn-rh:disabled { opacity: .45; cursor: not-allowed; }
        .btn-rh-primary { background: #36577d; color: var(--bg-primary); }
        .btn-rh-outline { background: var(--bg-primary); color: #6e6b65; }
        .btn-rh-pdf     { background: #8a5040; color: var(--bg-primary); }
        .btn-rh-danger  { background: #8a5040; color: var(--bg-primary); }

        /* Bloc récap */
        .ik-recap-bar {
            display: flex; gap: 0; flex-wrap: wrap;
            background: #eae6e0; border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light), 0 0 0 1px rgba(196,192,186,0.25);
            padding: 16px 20px; align-items: center;
        }
        .ik-recap-stat {
            display: flex; flex-direction: column; gap: 2px;
            padding: 0 20px; border-right: 1px solid rgba(196,192,186,0.4);
        }
        .ik-recap-stat:first-child { padding-left: 4px; }
        .ik-recap-stat:last-child { border-right: none; }
        .ik-recap-stat label { font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase;
            letter-spacing: .2em; color: #a8a49e; }
        .ik-recap-stat strong { font-family: 'DM Mono', monospace; font-size: 20px; color: #36577d; }
        .ik-recap-stat span   { font-size: 11px; color: #a8a49e; font-family: 'Sora', sans-serif; }
        .ik-paie-alert {
            margin-left: auto; background: #eae6e0;
            border: 1px solid rgba(196,192,186,0.5);
            color: #36577d; padding: 10px 18px; border-radius: 12px;
            font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500; line-height: 1.6;
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
        }

        /* Session card */
        .ik-session {
            background: #eae6e0; border-radius: 16px; overflow: hidden; margin-bottom: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light), 0 0 0 1px rgba(196,192,186,0.25);
        }
        .ik-session-header {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; background: var(--bg-primary);
            border-bottom: 1px solid rgba(196,192,186,0.3); flex-wrap: wrap;
        }
        .ik-session-header h3 { margin: 0; font-size: 13px; font-weight: 500; flex: 1;
            font-family: 'DM Mono', monospace; color: #1a1816; word-break: keep-all; }
        .ik-session-header h3 .ik-h3-reporter { display: inline-block; }
        .ik-session-header h3 em { font-style: normal; font-weight: 400; color: #6e6b65; }
        .ik-session-header h3 .sess-total-km { font-weight: 700; color: #1a1816; }
        .ik-session-paie {
            font-size: 11px; color: #6e6b65; background: var(--bg-secondary);
            padding: 4px 10px; border-radius: 20px;
            border: 1px solid rgba(196,192,186,0.4);
            font-family: 'DM Mono', monospace;
        }
        .ik-session-paie strong { color: #1a1816; }

        /* Tableau */
        .ik-table-wrap { overflow-x: auto; position: relative; }
        .ik-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 750px; }
        .ik-table thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--bg-primary);
            padding: 8px 4px; text-align: center;
            font-family: 'DM Mono', monospace; font-size: 8.5px; font-weight: 500;
            text-transform: uppercase; letter-spacing: .12em; color: #4a6038; font-weight: 700;
            border-bottom: 2px solid rgba(196,192,186,0.6);
            white-space: nowrap;
        }
        .ik-table thead th:first-child { text-align: left; padding-left: 8px; }
        .ik-table td {
            padding: 5px 4px; border-bottom: 1px solid rgba(196,192,186,0.4);
            vertical-align: middle; text-align: center;
            font-family: 'Sora', sans-serif; font-size: 11px;
        }
        .ik-table td:first-child { text-align: left; padding-left: 8px; }
        /* Alternance bleu/vert comme salary-table */
        .ik-table tbody tr:nth-child(even):not(.ik-row-empty) td { background: rgba(54,87,125,0.13); }
        .ik-table tbody tr:nth-child(odd):not(.ik-row-empty) td  { background: rgba(54,87,125,0.07); }
        .ik-table tr:hover td { background: rgba(54,87,125,0.22) !important; }
        .ik-table tr.ik-row-empty td { opacity: .4; background: transparent !important; }

        /* Cellules de saisie */
        .ik-input {
            width: 100%;
            background: var(--bg-primary);
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
            border: 1px solid rgba(196,192,186,0.5);
            color: #1a1816; font-family: 'Sora', sans-serif;
            font-size: 12px; padding: 4px 8px; border-radius: 8px;
            transition: border .15s, box-shadow .15s;
        }
        .ik-input:focus {
            outline: none; border-color: #36577d;
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed, 0 0 0 2px rgba(54,87,125,0.15);
        }
        .ik-date-wrap { position: relative; width: 66px; }
        .ik-date-display {
            width: 66px; min-width: 66px; padding: 5px 4px; cursor: pointer;
            font-family: 'DM Mono', monospace; font-size: 11px; color: #1a1816;
            background: var(--bg-primary); border: 1px solid rgba(196,192,186,0.5); border-radius: 8px;
            box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 4px #f0ece6;
            white-space: nowrap; overflow: hidden; text-overflow: clip; text-align: center;
        }
        .ik-date-display.empty { color: #a8a49e; }
        .ik-date-hidden { position: absolute; opacity: 0; top: 0; left: 0; width: 66px; height: 100%; cursor: pointer; }
        .ik-input[type=date] { display: none; }
        .ik-input[type=number] { width: 70px; text-align: right; }
        select.ik-input { cursor: pointer; }

        /* Champ immeuble avec autocomplete — dropdown vert amande lisible */
        .ik-imm-wrap, .ik-depart-search { position: relative; min-width: 170px; }
        .ik-imm-results, .ik-depart-imm-results {
            position: fixed; z-index: 9999;
            background: #dcecd4;            /* vert amande pâle */
            border: 1px solid #87a777;       /* bordure verte plus marquée */
            border-radius: 10px; max-height: 260px; overflow-y: auto;
            box-shadow: 0 10px 32px rgba(54,87,125,0.30);
            margin-top: 4px;
            min-width: 280px;
        }
        .ik-imm-item, .ik-depart-imm-item {
            padding: 9px 13px; cursor: pointer;
            border-bottom: 1px solid rgba(135,167,119,0.35);
            transition: background .1s;
            color: #1a1816;
        }
        .ik-imm-item:last-child, .ik-depart-imm-item:last-child { border-bottom: none; }
        .ik-imm-item:hover, .ik-imm-item-active,
        .ik-depart-imm-item:hover, .ik-depart-imm-item-active { background: #c5dfb6; }
        .ik-imm-item-active, .ik-depart-imm-item-active {
            border-left: 3px solid #36577d; padding-left: 10px;
        }
        .ik-imm-item strong, .ik-depart-imm-item strong {
            display: block; font-size: 12px; font-family: 'Sora', sans-serif; color: #1a1816;
        }
        .ik-imm-item small, .ik-depart-imm-item small {
            color: #5a7045; font-size: 10px; font-family: 'DM Mono', monospace;
        }
        /* Item spécial "Saisir adresse libre" — visuellement distinct */
        .ik-imm-item-google {
            background: #fff7e6; border-top: 2px solid #f59e0b;
            color: #7c2d12; font-weight: 600; font-size: 12px;
            display: flex; align-items: center; gap: 8px;
        }
        .ik-imm-item-google:hover { background: #fde7b8; }
        /* Mode adresse libre actif (input passe en bordure orange) */
        .ik-imm-input.is-free-address {
            border: 2px solid #f59e0b !important;
            background: #fff7e6;
        }

        /* Cases Aller / Retour cliquables */
        .ik-km-cell { min-width: 80px; }
        .ik-km-toggle-wrap { display:flex; align-items:center; gap:4px; }
        .ik-km-toggle {
            width:28px; height:28px; border:none; border-radius:8px; cursor:pointer;
            font-size:13px; font-weight:700; flex-shrink:0; transition:all .15s;
            background:var(--bg-primary); color:#a8a49e;
            display:flex; align-items:center; justify-content:center;
        }
        .ik-km-toggle.active {
            background:rgba(54,87,125,0.20); color:#36577d;
            box-shadow:2px 2px 6px var(--shadow-dark);
        }
        .ik-km-input-val {
            width:52px; font-size:12px; display:none;
        }
        .ik-km-input-val.visible { display:block; }
        .ik-km-btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 58px; height: 30px;
            background: var(--bg-primary); border: 1px dashed rgba(196,192,186,0.6);
            border-radius: 8px; cursor: pointer; font-size: 11px; color: #a8a49e;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            transition: all .15s; user-select: none; gap: 4px;
            font-family: 'DM Mono', monospace; font-weight: 500;
        }
        .ik-km-btn.active {
            background: var(--bg-primary); border: 1px solid #36577d; color: #36577d;
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
        }
        .ik-km-btn:hover { border-color: #36577d; color: #36577d; }
        .ik-km-manual { display: none; }
        .ik-km-manual.visible { display: block; }

        /* Total ligne */
        .ik-total-cell { font-family: 'DM Mono', monospace; font-weight: 700; color: #36577d; text-align: right; min-width: 60px; }

        /* Boutons d'action sur ligne */
        .ik-action-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 8px; border: 1px solid transparent;
            cursor: pointer; transition: all .15s; background: transparent;
            font-size: 13px;
        }
        .ik-action-btn:hover { transform: scale(1.1); }
        .ik-btn-copy:hover { background: rgba(74,96,56,.12); border-color: #36577d; }
        .ik-btn-del:hover  { background: rgba(204,92,88,.12); border-color: #8a5040; }

        /* Formulaire nouvelle session */
        .ik-add-session-form {
            background: #eae6e0; border: 2px dashed rgba(196,192,186,0.5); margin-bottom: 16px;
            border-radius: 16px; padding: 24px; text-align: center;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        }
        .ik-add-session-form h4 { margin: 0 0 16px; font-size: 13px; color: #6e6b65;
            font-family: 'DM Mono', monospace; text-transform: uppercase; letter-spacing: .1em; }
        /* Layout 2 colonnes Nouvelle session / Actions */
        .ik-top-grid {
            display: grid;
            grid-template-columns: 3fr 6fr;
            gap: 0 10%;
            align-items: start;
            margin-bottom: 20px;
        }
        .ik-top-col-new {}
        .ik-top-col-actions {}

        /* Pattern Pills sélecteur Mois/Année */
        .ik-selector-row {
            display: flex; align-items: center; gap: 10px; margin-bottom: 2px;
        }
        .ik-selector-label {
            font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600;
            color: #a8a49e; letter-spacing: .18em; min-width: 36px; text-transform: uppercase;
        }
        .ik-pills-wrap {
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        #ik-top-new .ik-pills-wrap {
            flex-wrap: nowrap;
        }
        .ik-pill {
            font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 400;
            padding: 6px 0; border-radius: 20px; border: none; cursor: pointer;
            background: #eae6e0;
            box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            color: #4a4743; transition: all .15s; white-space: nowrap;
            width: 80px; text-align: center;
        }
        .ik-pill:hover { color: #1a1816; box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 8px var(--shadow-light); }
        .ik-pill.ik-pill-selected {
            box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            color: #1a1816; font-weight: 700;
        }
        .ik-pill.ik-pill-exists {
            color: #b8b4ae; cursor: default;
            box-shadow: inset 1px 1px 3px var(--shadow-dark), inset -1px -1px 3px var(--shadow-light);
        }
        .ik-pill.ik-pill-create {
            width: 40px; font-size: 20px; font-weight: 700;
            color: #36577d; line-height: 1;
        }
        .ik-pill.ik-pill-create:hover { color: #2b4466; }
        .ik-pill.ik-pill-more {
            width: 44px; display: flex; align-items: center; justify-content: center; gap: 2px;
            font-size: 11px; color: #6e6b65;
        }
        /* Dropdown overflow */
        .ik-pill-overflow { position: relative; }
        .ik-pill-dropdown {
            display: none; position: absolute; top: calc(100% + 6px); left: 0; z-index: 200;
            background: #eae6e0; border-radius: 12px; padding: 6px;
            box-shadow: 6px 6px 16px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            min-width: 140px; flex-direction: column; gap: 2px;
        }
        .ik-pill-dropdown.open { display: flex; }
        .ik-dd-item {
            font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 400;
            padding: 6px 10px; border-radius: 8px; border: none; cursor: pointer;
            background: transparent; color: #4a4743; text-align: left; transition: background .1s;
        }
        .ik-dd-item:hover { background: rgba(196,192,186,0.35); }
        .ik-dd-item.ik-pill-exists { color: #b8b4ae; cursor: default; }

        /* Actions list */
        .ik-actions-list {
            display: flex; flex-direction: column; gap: 5px;
        }
        .ik-action-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; padding: 5px 10px;
        }
        .ik-action-label {
            font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500;
            color: #4a4743; display: flex; align-items: center; gap: 5px;
        }
        .ik-action-btns { display: flex; gap: 6px; }

        /* Bouton icône rond */
        .v2-btn-icon {
            width: 30px; height: 30px; border-radius: 8px; border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            background: #eae6e0;
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            color: #6e6b65; transition: all .15s;
        }
        .v2-btn-icon:hover { box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light); color: #1a1816; }
        .v2-btn-icon:active { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); }
        .v2-btn-icon.danger { color: #8a5040; }
        .v2-btn-blue-pastel { background: #7baed6; color: #fff; box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light); }
        .v2-btn-blue-pastel:hover { background: #5e9ac8; }
        .v2-btn-blue-pastel:disabled { opacity: .5; cursor: not-allowed; }
        .v2-btn-icon.danger:hover { color: #a03030; }
        .v2-btn-icon:disabled { opacity: .4; cursor: not-allowed; box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light); }

        .ik-add-session-inputs {
            display: flex; gap: 12px; justify-content: flex-start; flex-wrap: nowrap; align-items: flex-end;
        }
        .ik-add-session-inputs label { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
            color: #a8a49e; display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .2em; }
        .ik-field { display: flex; flex-direction: column; }
        .ik-field input, .ik-field select {
            background: var(--bg-primary); border: 1px solid rgba(196,192,186,0.5);
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
            color: #1a1816; padding: 8px 12px; border-radius: 8px;
            font-family: 'Sora', sans-serif; font-size: 12px; min-width: 160px;
        }

        .ik-session-locked .ik-session-header { background: rgba(204,92,88,.06); }
        .ik-session-lock-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px;
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
            color: #8a5040; background: rgba(204,92,88,0.12); border: 1px solid rgba(204,92,88,0.3);
        }
        .ik-session-locked .ik-table-wrap { opacity: .75; }
        .ik-session-locked .ik-input:disabled,
        .ik-session-locked select:disabled { opacity: .6; cursor: not-allowed; }
        .ik-session-locked .ik-action-btn:disabled,
        .ik-session-locked .btn-rh:disabled { opacity: .45; cursor: not-allowed; transform: none; }

        /* Indicateur de sauvegarde */
        .ik-save-indicator {
            position: fixed; bottom: 20px; right: 20px; z-index: 999;
            padding: 8px 18px; border-radius: 999px; font-size: 11px; font-weight: 500;
            background: #36577d; color: var(--bg-primary); opacity: 0;
            font-family: 'DM Mono', monospace;
            transition: opacity .3s; pointer-events: none;
        }
        .ik-save-indicator.visible { opacity: 1; }

        /* Modal */
        .ik-modal-backdrop {
            display: none; position: fixed; inset: 0; z-index: 900;
            background: rgba(26,24,22,0.45); backdrop-filter: blur(4px);
            align-items: center; justify-content: center;
        }
        .ik-modal-backdrop.open { display: flex; }
        .ik-modal {
            background: var(--bg-primary); border-radius: 18px; padding: 24px;
            max-width: 480px; width: 100%;
            box-shadow: 6px 6px 18px #c0bcb6, -4px -4px 10px var(--shadow-light);
        }
        .ik-modal h3 { margin: 0 0 16px; font-size: 15px; font-family: 'Sora', sans-serif;
            font-weight: 700; color: #1a1816; }
        .ik-modal .form-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .ik-modal .form-row label { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
            color: #a8a49e; text-transform: uppercase; letter-spacing: .2em; }
        .ik-modal .form-row input {
            background: var(--bg-primary); border: 1px solid rgba(196,192,186,0.5);
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
            color: #1a1816; padding: 8px 12px; border-radius: 8px;
            font-family: 'Sora', sans-serif; font-size: 13px;
        }
        .ik-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

        /* Champ départ select */
        .ik-depart-wrap { min-width: 160px; }
        .ik-depart-select {
            width: 100%;
            background: var(--bg-primary); border: 1px solid rgba(196,192,186,0.5);
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
            color: #1a1816; font-family: 'Sora', sans-serif;
            font-size: 12px; padding: 4px 8px; border-radius: 8px; cursor: pointer;
        }
        .ik-depart-select:focus { outline: none; border-color: #36577d; }
        .ik-depart-search { margin-top: 4px; position: relative; }
        .ik-depart-imm-results {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 99;
            background: var(--bg-primary); border-radius: 10px; max-height: 200px; overflow-y: auto;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light); margin-top: 4px;
        }
        .ik-depart-imm-item {
            padding: 7px 11px; cursor: pointer; border-bottom: 1px solid rgba(196,192,186,0.3);
            font-size: 11px; font-family: 'Sora', sans-serif;
        }
        .ik-depart-imm-item:last-child { border-bottom: none; }
        .ik-depart-imm-item:hover, .ik-depart-imm-item.active { background: rgba(196,192,186,0.3); }
        .ik-depart-imm-item strong { display: block; font-size: 11px; color: #1a1816; }
        .ik-depart-imm-item small  { color: #a8a49e; font-size: 10px; font-family: 'DM Mono', monospace; }

        /* Ligne active */
        .ik-row-active td {
            background: rgba(54,87,125,.05) !important;
            box-shadow: inset 0 -2px 0 #36577d, inset 0 2px 0 #36577d;
        }
        .ik-row-active td:first-child { box-shadow: inset 0 -2px 0 #36577d, inset 0 2px 0 #36577d, inset 2px 0 0 #36577d; }
        .ik-row-active td:last-child  { box-shadow: inset 0 -2px 0 #36577d, inset 0 2px 0 #36577d, inset -2px 0 0 #36577d; }

        /* Champ adresse libre Google Places */
        .ik-depart-addr-wrap { margin-top: 4px; position: relative; }
        .pac-container { z-index: 10000 !important; font-family: 'Sora', sans-serif; font-size: 12px; }
        /* places.js dropdown — doit passer au-dessus du modal backdrop (z-index:900) */
        .places-dropdown {
            position: absolute;
            z-index: 10000 !important;
            background: #f5f2ee;
            border: 1px solid var(--shadow-dark);
            border-radius: 10px;
            box-shadow: 0 8px 28px rgba(0,0,0,0.18);
            overflow: hidden;
            max-height: 260px;
            overflow-y: auto;
        }
        .places-item {
            padding: 10px 14px;
            font-size: 13px;
            color: #3a3733;
            cursor: pointer;
            border-bottom: 1px solid #f0f1f3;
            transition: background .12s;
            line-height: 1.4;
            font-family: 'Sora', sans-serif;
        }
        .places-item:last-child { border-bottom: none; }
        .places-item:hover, .places-item.active {
            background: var(--bg-primary);
            color: #3d5a80;
        }

        /* Domicile info dans page-head */
        .domicile-info {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; color: #6e6b65; font-family: 'Sora', sans-serif;
        }
        .domicile-info strong { color: #1a1816; font-weight: 600; }

        /* Bloc paie notice */
        .sess-paie-notice {
            background: rgba(54,87,125,.07); border-bottom: 1px solid rgba(196,192,186,0.3);
            padding: 10px 20px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .sess-paie-notice span { font-family: 'DM Mono', monospace; font-size: 11px; color: #36577d; font-weight: 500; }
        .sess-paie-notice .locked-warn { color: #8a5040; }

        /* Bouton ajouter ligne zone */
        .sess-add-row-bar {
            padding: 10px 20px; display: flex; gap: 10px; align-items: center;
            background: #eae6e0;
        }
        .ik-save-status { font-size: 11px; color: #a8a49e; font-family: 'DM Mono', monospace; }

        /* Select modal */
        .modal-select-v2 {
            background: var(--bg-primary); border: 1px solid rgba(196,192,186,0.5);
            box-shadow: inset 2px 2px 5px #c0bbb5, inset -2px -2px 5px #f5f2ed;
            color: #1a1816; padding: 8px 12px; border-radius: 8px;
            font-family: 'Sora', sans-serif; font-size: 13px; width: 100%;
        }
</style>
EXTRACSS;

// Google Maps script
if ($gKey) {
    $layout_extra_css .= '<style>.gm-script-placeholder { display: none; }</style>';
    $layout_extra_css .= '<script src="https://maps.googleapis.com/maps/api/js?key=' . htmlspecialchars($gKey) . '&libraries=places&callback=onGoogleReady" async defer></script>';
}

$layout_extra_js = '';

// ══════════════════════════════════════════════════════════════════════════════
//  CONTENT (ob_start)
// ══════════════════════════════════════════════════════════════════════════════
ob_start();
?>

<div class="ik-page">

    <!-- Domicile info bar -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;padding:8px 0;">
        <?php if (!empty($domicile['adresse'])): ?>
        <div class="domicile-info">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6e6b65" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <strong><?=h($domicile['adresse'])?>, <?=h($domicile['code_postal'])?> <?=h($domicile['ville'])?></strong>
            <?php if (empty($domicile['latitude'])): ?>
            <span style="color:#8a5040;font-size:10px;font-family:'DM Mono',monospace;">coords manquantes</span>
            <?php endif; ?>
            <button class="v2-btn" onclick="document.getElementById('domicile-modal').classList.add('open')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Modifier
            </button>
        </div>
        <?php else: ?>
        <button class="v2-btn primary" onclick="document.getElementById('domicile-modal').classList.add('open')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Enregistrer mon domicile
        </button>
        <?php endif; ?>
        <span style="font-family:'DM Mono',monospace;font-size:11px;color:#8a8680;">
            <?=h($user['prenom'].' '.$user['nom'])?> · <?=h($user['nom_agence'] ?? '')?>
            <?php if ($user['vehicule_nom']): ?> · <?=h($user['vehicule_nom'])?><?php if ($user['vehicule_puissance_fiscale']): ?> (<?=h((string)$user['vehicule_puissance_fiscale'])?>CV)<?php endif; ?><?php endif; ?>
        </span>
    </div>

        <!-- ── Nouvelle session + Actions ────────────────────────────────── -->
        <?php
        $anneeEnCours   = (int)date('Y');
        $moisEnCours    = (int)date('n');
        $existingMois   = array_column($sessions, 'mois_deplacements');
        // map session par mois_deplacements pour lookup rapide
        $sessionsMap = [];
        foreach ($sessions as $sA) { $sessionsMap[$sA['mois_deplacements']] = $sA; }
        $nomsCourtsMois = ['Jan','Fév','Mar','Avr','Mai','Jui','Jul','Aoû','Sep','Oct','Nov','Déc'];
        $nomsLongsMois  = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        // Mois M-1, M, M+1 pour Nouvelle session
        $pillsMoisNew = [];
        for ($delta = -1; $delta <= 1; $delta++) {
            $mo = $moisEnCours + $delta;
            $yo = $anneeEnCours;
            if ($mo < 1) { $mo += 12; $yo--; }
            if ($mo > 12) { $mo -= 12; $yo++; }
            $pillsMoisNew[] = ['val' => sprintf('%d-%02d', $yo, $mo), 'label' => $nomsCourtsMois[$mo-1]];
        }
        ?>
        <div class="ik-top-grid">
            <!-- Colonne gauche : Nouvelle session (3/10) -->
            <div class="ik-top-col-new" id="ik-top-new">
                <div class="section-header"><div class="section-title">
                    <div class="line-l"></div>
                    <span class="sec-txt">Nouvelle session</span>
                    <div class="line-r"></div>
                </div></div>
                <!-- Ligne MOIS : M-1, M, M+1 + bouton Création -->
                <div class="ik-selector-row">
                    <span class="ik-selector-label">MOIS</span>
                    <div class="ik-pills-wrap">
                        <?php foreach ($pillsMoisNew as $pm):
                            $exists = in_array($pm['val'], $existingMois);
                            $isCurrent = ($pm['val'] === $moisDefaut);
                            $cls = $exists ? ' ik-pill-exists' : ($isCurrent ? ' ik-pill-selected' : '');
                        ?>
                        <button type="button" class="ik-pill<?=$cls?>" data-val="<?=$pm['val']?>"
                            onclick="selectNewMoisPill(this)"><?=$pm['label']?></button>
                        <?php endforeach; ?>
                        <input type="hidden" id="new-mois-dep" value="<?=h($moisDefaut)?>">
                        <input type="hidden" id="new-mois-paie" value="<?=h($moisDefaut)?>">
                        <button class="ik-pill ik-pill-create" onclick="createSession()">+</button>
                        <span id="new-session-msg" style="font-family:'DM Mono',monospace;font-size:11px;color:#8a5040;display:none;align-items:center;gap:5px">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Session déjà existante
                        </span>
                    </div>
                </div>
            </div>

            <!-- Colonne droite : Actions (6/10) -->
            <div class="ik-top-col-actions" id="ik-top-actions">
                <div class="section-header"><div class="section-title">
                    <div class="line-l"></div>
                    <span class="sec-txt">Actions</span>
                    <div class="line-r"></div>
                </div></div>
                <?php
                // Années disponibles : n-2, n-1, n
                $anneesActions = [$anneeEnCours - 2, $anneeEnCours - 1, $anneeEnCours];
                // Mois à afficher : M-1, M, M+1 de l'année sélectionnée (défaut = année en cours)
                ?>
                <!-- Ligne ANNÉE -->
                <div class="ik-selector-row">
                    <span class="ik-selector-label">ANNÉE</span>
                    <div class="ik-pills-wrap" id="action-annee-pills">
                        <?php foreach ($anneesActions as $ya):
                            $cls = ($ya === $anneeEnCours) ? ' ik-pill-selected' : '';
                        ?>
                        <button type="button" class="ik-pill<?=$cls?>" data-val="<?=$ya?>"
                            onclick="selectActionAnnee(this)"><?=$ya?></button>
                        <?php endforeach; ?>
                        <div class="ik-pill-overflow">
                            <button type="button" class="ik-pill ik-pill-more" onclick="toggleActionAnneeDropdown(this)">…&nbsp;<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button>
                            <div class="ik-pill-dropdown" id="action-annee-dropdown">
                                <?php for ($ya2 = $anneeEnCours - 3; $ya2 >= $anneeEnCours - 6; $ya2--): ?>
                                <button type="button" class="ik-dd-item" data-val="<?=$ya2?>"
                                    onclick="selectActionAnnee(this,true)"><?=$ya2?></button>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Ligne MOIS + résultat inline (dynamique via JS) -->
                <div class="ik-selector-row" style="margin-top:8px;width:100%;">
                    <span class="ik-selector-label">MOIS</span>
                    <div class="ik-pills-wrap" id="action-mois-pills"><!-- renderActionMoisPills() --></div>
                    <!-- Résultat inline : boutons ou message -->
                    <div id="action-buttons-zone" style="display:none;align-items:center;gap:8px;margin-left:auto;">
                        <span id="action-locked-msg" style="display:none;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#36577d;margin-left:auto;text-align:right;">Votre calcul n'est plus modifiable</span>
                        <button class="v2-btn-icon" id="action-btn-pdf" onclick="exportPdf(window._actionSessId)" title="Export PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </button>
                        <button class="v2-btn-icon danger" id="action-btn-del" onclick="deleteSession(window._actionSessId)" title="Supprimer">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <span id="action-no-session" style="display:none;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#36577d;margin-left:auto;text-align:right;">Pas de fiche de calcul IK</span>
                </div>
            </div>
        </div>

        <!-- ── Sessions existantes ────────────────────────────────────────── -->
        <?php foreach ($sessions as $sess):
            $sessId   = (int)$sess['id'];
            $lignes   = $lignesParSession[$sessId] ?? [];
            $sessLocked = !empty($sess['mois_paie_bloque']);
            $totalKm  = array_sum(array_map(fn($l) => (float)$l['km_aller'] + (float)$l['km_retour'], $lignes));
            $nbLignes = count(array_filter($lignes, fn($l) => $l['destination_label'] || $l['km_aller'] || $l['km_retour']));
        ?>
        <div class="ik-session<?= $sessLocked ? ' ik-session-locked' : '' ?>" id="session-<?=$sessId?>" data-locked="<?= $sessLocked ? '1' : '0' ?>">
            <div class="ik-session-header">
                <h3>Déplacements de <?=moisLabel($sess['mois_deplacements'])?> — Intégrer dans : <?=moisLabel($sess['mois_paie'])?><wbr> — <span class="ik-h3-reporter">Reporter <span class="sess-total-km"><?=number_format($totalKm,1,',',' ')?></span> km</span></h3>
                <?php if ($sessLocked): ?>
                <span class="ik-session-lock-badge">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Mois de paie clôturé
                </span>
                <?php endif; ?>
                <div style="display:flex;gap:8px;">
                    <?php if ($sessLocked): ?>
                    <button class="v2-btn" disabled title="Mois clôturé par l'admin">Mois paie</button>
                    <button class="v2-btn v2-btn-blue-pastel" disabled>Valider en paie</button>
                    <?php else: ?>
                    <button class="v2-btn" onclick="editSessionMois(<?=$sessId?>, '<?=h($sess['mois_paie'])?>')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Mois paie
                    </button>
                    <button class="v2-btn v2-btn-blue-pastel" onclick="clotureSession(<?=$sessId?>)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Valider en paie
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ik-session-body">
            <div class="ik-table-wrap">
                <table class="ik-table" id="table-<?=$sessId?>">
                    <thead>
                        <tr>
                            <th style="width:66px;">Date</th>
                            <th style="min-width:150px;">Point de départ</th>
                            <th style="min-width:170px;">Immeuble / Destination</th>
                            <th style="min-width:180px;">Motif</th>
                            <th style="width:80px;text-align:center;">Aller</th>
                            <th style="width:80px;text-align:center;">Retour</th>
                            <th style="min-width:120px;">Observation</th>
                            <th style="width:70px;text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-<?=$sessId?>">
                        <?php
                        // Afficher les lignes existantes
                        foreach ($lignes as $idx => $ligne):
                            $kmTotal = (float)$ligne['km_aller'] + (float)$ligne['km_retour'];
                            $isEmpty = !$ligne['destination_label'] && !$ligne['km_aller'] && !$ligne['km_retour'] && !$ligne['date_deplacement'];
                        ?>
                        <tr class="ik-row<?=$isEmpty?' ik-row-empty':''?>"
                            data-ligne-id="<?=(int)$ligne['id']?>"
                            data-session-id="<?=$sessId?>"
                            data-dist="<?=h((string)($ligne['distance_calculee'] ?? ''))?>"
                            data-depart-lat="<?=h((string)($ligne['depart_lat'] ?? ''))?>"
                            data-depart-lng="<?=h((string)($ligne['depart_lng'] ?? ''))?>"
                            data-dest-lat="<?=h((string)($ligne['destination_lat'] ?? ''))?>"
                            data-dest-lng="<?=h((string)($ligne['destination_lng'] ?? ''))?>">
                            <td>
                                <?php
                                $dRaw = $ligne['date_deplacement'] ?? '';
                                $dFmt = '';
                                if ($dRaw) { $dt = DateTime::createFromFormat('Y-m-d', $dRaw); $dFmt = $dt ? $dt->format('j/n/y') : ''; }
                                ?>
                                <div class="ik-date-wrap">
                                    <div class="ik-date-display<?=$dFmt?'':' empty'?>" onclick="this.nextElementSibling.showPicker?.()||this.nextElementSibling.click()"><?=$dFmt ?: 'jj/m/aa'?></div>
                                    <input type="date" class="ik-date-hidden ik-date" value="<?=h($dRaw)?>"
                                        onchange="onDateChange(this)">
                                </div>
                            </td>
                            <td class="ik-depart-wrap">
                                <?php
                                $isImmDepart = ($ligne['type_depart'] === 'immeuble');
                                $departImmRealId = $isImmDepart ? (int)($ligne['id_agence_depart'] ?? 0) : 0;
                                ?>
                                <select class="ik-depart-select ik-depart" onchange="onDepartChange(this)">
                                    <optgroup label="Agences">
                                    <?php foreach ($agences as $ag): ?>
                                    <option value="agence:<?=(int)$ag['id']?>"
                                        data-lat="<?=h((string)($ag['latitude']??''))?>"
                                        data-lng="<?=h((string)($ag['longitude']??''))?>"
                                        data-label="<?=h(trim($ag['nom_agence'].' – '.($ag['adresse_1']??'').' '.($ag['ville']??'')))?>"
                                        <?=($ligne['type_depart']==='agence' && (int)$ligne['id_agence_depart']===(int)$ag['id'])?'selected':''?>>
                                        <?php if ((int)$ag['id'] === (int)$user['id_agence']): ?>★ <?php endif;?>
                                        <?=h($ag['nom_agence'])?>
                                    </option>
                                    <?php endforeach; ?>
                                    </optgroup>
                                    <?php if ($domicile && $domicile['latitude']): ?>
                                    <optgroup label="Domicile">
                                    <option value="domicile:0"
                                        data-lat="<?=h((string)$domicile['latitude'])?>"
                                        data-lng="<?=h((string)$domicile['longitude'])?>"
                                        data-label="Domicile – <?=h(($domicile['adresse']??'').' '.($domicile['ville']??''))?>"
                                        <?=$ligne['type_depart']==='domicile'?'selected':''?>>
                                        🏠 Mon domicile
                                    </option>
                                    </optgroup>
                                    <?php endif; ?>
                                    <optgroup label="Autre">
                                    <option value="immeuble:<?=$departImmRealId?>"
                                        data-lat="<?=h((string)($ligne['depart_lat']??''))?>"
                                        data-lng="<?=h((string)($ligne['depart_lng']??''))?>"
                                        data-label="<?=h($ligne['depart_label']??'')?>"
                                        <?=$isImmDepart?'selected':''?>>
                                        <?=$isImmDepart && !empty($ligne['depart_label']) ? h($ligne['depart_label']) : '🔍 Autre immeuble…'?>
                                    </option>
                                    <option value="adresse:0"
                                        data-lat="<?=h((string)($ligne['depart_lat']??''))?>"
                                        data-lng="<?=h((string)($ligne['depart_lng']??''))?>"
                                        data-label="<?=h($ligne['depart_label']??'')?>"
                                        <?=($ligne['type_depart']==='adresse')?'selected':''?>>
                                        ✍️ Adresse libre…
                                    </option>
                                    </optgroup>
                                </select>
                                <?php
                                    $departImmId = $departImmRealId;
                                ?>
                                <button type="button" class="ik-depart-edit-btn"
                                    style="display:<?=$departImmId>0?'flex':'none'?>;width:22px;height:22px;border:none;background:transparent;cursor:pointer;align-items:center;justify-content:center;color:#a8a49e;flex-shrink:0;"
                                    data-imm-id="<?=$departImmId?>"
                                    onclick="openEditImmeuble(parseInt(this.dataset.immId))"
                                    title="Modifier l'immeuble">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <?php $isAdresseDepart = ($ligne['type_depart'] === 'adresse'); ?>
                                <div class="ik-depart-search" style="<?=$isImmDepart?'display:block':'display:none'?>">
                                    <input type="text" class="ik-input ik-depart-imm-input"
                                        value="<?=h($isImmDepart ? ($ligne['depart_label']??'') : '')?>"
                                        placeholder="Immeuble de départ…"
                                        autocomplete="off"
                                        oninput="onDepartImmInput(this)"
                                        onkeydown="onDepartImmKeydown(this,event)"
                                        onblur="onDepartImmBlur(this)"
                                        ondblclick="openEditImmeuble(parseInt(this.closest('.ik-depart-search').querySelector('.ik-depart-imm-id').value))"
                                        title="Double-clic pour modifier l'immeuble">
                                    <input type="hidden" class="ik-depart-imm-id" value="<?=$departImmRealId?>">
                                    <input type="hidden" class="ik-depart-imm-lat" value="<?=h((string)($ligne['depart_lat']??''))?>">
                                    <input type="hidden" class="ik-depart-imm-lng" value="<?=h((string)($ligne['depart_lng']??''))?>">
                                    <div class="ik-depart-imm-results" style="display:none;"></div>
                                </div>
                                <div class="ik-depart-addr-wrap" style="<?=$isAdresseDepart?'display:block':'display:none'?>">
                                    <input type="text" class="ik-input ik-depart-addr-input"
                                        value="<?=h($isAdresseDepart ? ($ligne['depart_label']??'') : '')?>"
                                        placeholder="Saisir une adresse…"
                                        autocomplete="off">
                                    <input type="hidden" class="ik-depart-addr-lat" value="<?=h((string)($isAdresseDepart ? ($ligne['depart_lat']??'') : ''))?>">
                                    <input type="hidden" class="ik-depart-addr-lng" value="<?=h((string)($isAdresseDepart ? ($ligne['depart_lng']??'') : ''))?>">
                                </div>
                            </td>
                            <td>
                                <div class="ik-imm-wrap">
                                    <input type="text" class="ik-input ik-imm-input"
                                        value="<?=h($ligne['destination_label'] ?? '')?>"
                                        placeholder="Rechercher immeuble…"
                                        autocomplete="off"
                                        oninput="onImmInput(this)"
                                        onkeydown="onImmKeydown(this,event)"
                                        onblur="onImmBlur(this)"
                                        ondblclick="openEditImmeuble(parseInt(this.closest('.ik-imm-wrap').querySelector('.ik-imm-id').value))"
                                        title="Double-clic pour modifier l'immeuble">
                                    <input type="hidden" class="ik-imm-id" value="<?=(int)($ligne['id_immeuble'] ?? 0)?>">
                                    <input type="hidden" class="ik-imm-lat" value="<?=h((string)($ligne['destination_lat'] ?? ''))?>">
                                    <input type="hidden" class="ik-imm-lng" value="<?=h((string)($ligne['destination_lng'] ?? ''))?>">
                                    <div class="ik-imm-results" style="display:none;"></div>
                                </div>
                            </td>
                            <td>
                                <select class="ik-input ik-motif" onchange="onFieldChange(this)">
                                    <option value="">— Motif —</option>
                                    <?php foreach ($motifs as $motif): ?>
                                    <option value="<?=h($motif)?>" <?=$ligne['motif']===$motif?'selected':''?>>
                                        <?=h($motif)?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <?php
                                $valAller  = $ligne['km_aller']!==null ? number_format((float)$ligne['km_aller'],1,'.','') : ($ligne['distance_calculee'] ? number_format((float)$ligne['distance_calculee'],1,'.','') : '');
                                $valRetour = $ligne['km_retour']!==null ? number_format((float)$ligne['km_retour'],1,'.','') : ($ligne['distance_calculee'] ? number_format((float)$ligne['distance_calculee'],1,'.','') : '');
                                $allerActive  = $ligne['km_aller']!==null  || $ligne['distance_calculee'] ? ' active' : '';
                                $retourActive = $ligne['km_retour']!==null || $ligne['distance_calculee'] ? ' active' : '';
                            ?>
                            <td class="ik-km-cell">
                                <div class="ik-km-toggle-wrap">
                                    <button type="button" class="ik-km-toggle<?=$allerActive?>" onclick="toggleKm(this,'aller')" title="Aller">↗</button>
                                    <input type="number" step="0.1" min="0"
                                        class="ik-input ik-km-aller-input<?=$allerActive ? ' visible' : ' ik-km-input-val'?>"
                                        value="<?=$valAller?>" placeholder="km"
                                        onchange="onKmChange(this,'aller')">
                                </div>
                            </td>
                            <td class="ik-km-cell">
                                <div class="ik-km-toggle-wrap">
                                    <button type="button" class="ik-km-toggle<?=$retourActive?>" onclick="toggleKm(this,'retour')" title="Retour">↙</button>
                                    <input type="number" step="0.1" min="0"
                                        class="ik-input ik-km-retour-input<?=$retourActive ? ' visible' : ' ik-km-input-val'?>"
                                        value="<?=$valRetour?>" placeholder="km"
                                        onchange="onKmChange(this,'retour')">
                                </div>
                            </td>
                            <td>
                                <input type="text" class="ik-input ik-obs"
                                    value="<?=h($ligne['observation'] ?? '')?>"
                                    placeholder="Observation…"
                                    onchange="onFieldChange(this)">
                            </td>
                            <td style="text-align:center;white-space:nowrap;">
                                <?php if ($sessLocked): ?>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <?php else: ?>
                                <button type="button" class="ik-action-btn ik-btn-copy" title="Copier cette ligne"
                                    onclick="copyLigne(this)">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                </button>
                                <button type="button" class="ik-action-btn ik-btn-del" title="Supprimer"
                                    onclick="deleteLigne(this)">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Les lignes vides sont ajoutées en JS via ensureEmptyRows -->
                    </tbody>
                </table>
            </div>
            <div class="sess-add-row-bar">
                <span class="ik-save-status"></span>
            </div>
            </div>
        </div>
        <?php endforeach; ?>

        

    </div><!-- ik-page -->

<!-- ── Indicateur de sauvegarde ─────────────────────────────────────────────── -->
<div class="ik-save-indicator" id="save-indicator">Enregistré</div>

<!-- ── Modal domicile ───────────────────────────────────────────────────────── -->
<div class="ik-modal-backdrop" id="domicile-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal">
        <h3>Mon adresse de domicile</h3>
        <p style="font-size:12px;color:#6e6b65;margin:0 0 16px;font-family:'Sora',sans-serif;">
            Cette adresse sera utilisée comme point de départ pour les trajets domicile → immeuble.
        </p>
        <div class="form-row">
            <label>Adresse</label>
            <input type="text" id="dom-adresse" value="<?=h($domicile['adresse'] ?? '')?>" placeholder="12 rue de la Paix">
        </div>
        <div style="display:flex;gap:10px;">
            <div class="form-row" style="flex:0 0 100px;">
                <label>Code postal</label>
                <input type="text" id="dom-cp" value="<?=h($domicile['code_postal'] ?? '')?>" placeholder="69000">
            </div>
            <div class="form-row" style="flex:1;">
                <label>Ville</label>
                <input type="text" id="dom-ville" value="<?=h($domicile['ville'] ?? '')?>" placeholder="Lyon">
            </div>
        </div>
        <p style="font-size:12px;color:#6e6b65;margin:8px 0 0;font-family:'Sora',sans-serif;">
            Les coordonnées GPS seront calculées automatiquement à la sauvegarde via l'API de géocodage.
        </p>
        <div class="ik-modal-actions">
            <button class="v2-btn" onclick="document.getElementById('domicile-modal').classList.remove('open')">Annuler</button>
            <button class="v2-btn primary" onclick="saveDomicile()">Enregistrer</button>
        </div>
    </div>
</div>

<!-- ── Modal changement mois de paie ────────────────────────────────────────── -->
<div class="ik-modal-backdrop" id="mois-paie-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal" style="max-width:400px;">
        <h3>Changer le mois de paie</h3>
        <p style="font-size:12px;color:#6e6b65;margin:0 0 20px;font-family:'Sora',sans-serif;">
            Sélectionnez le mois et l'année dans lesquels ces IK doivent être intégrées en paie.
        </p>
        <div style="display:flex;gap:12px;align-items:flex-end;">
            <div class="form-row" style="flex:1;">
                <label>Mois</label>
                <select id="modal-mois-select" class="modal-select-v2">
                    <option value="01">Janvier</option>
                    <option value="02">Février</option>
                    <option value="03">Mars</option>
                    <option value="04">Avril</option>
                    <option value="05">Mai</option>
                    <option value="06">Juin</option>
                    <option value="07">Juillet</option>
                    <option value="08">Août</option>
                    <option value="09">Septembre</option>
                    <option value="10">Octobre</option>
                    <option value="11">Novembre</option>
                    <option value="12">Décembre</option>
                </select>
            </div>
            <div class="form-row" style="flex:0 0 110px;">
                <label>Année</label>
                <select id="modal-annee-select" class="modal-select-v2">
                    <?php
                    $year = (int)date('Y');
                    for ($y = $year - 1; $y <= $year + 2; $y++) {
                        echo "<option value=\"$y\">$y</option>";
                    }
                    ?>
                </select>
            </div>
        </div>
        <input type="hidden" id="modal-sess-id" value="">
        <div class="ik-modal-actions">
            <button class="v2-btn" onclick="document.getElementById('mois-paie-modal').classList.remove('open')">Annuler</button>
            <button class="v2-btn primary" onclick="saveNewMoisPaie()">Valider</button>
        </div>
    </div>
</div>

<!-- ── Modal création immeuble ───────────────────────────────────────────────── -->
<div class="ik-modal-backdrop" id="immeuble-create-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal" style="max-width:620px;position:relative;">
        <!-- Croix fermer -->
        <button type="button" onclick="document.getElementById('immeuble-create-modal').classList.remove('open')"
            style="position:absolute;top:12px;right:12px;width:28px;height:28px;border:none;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#a8a49e;border-radius:50%;transition:background .15s;"
            onmouseover="this.style.background='var(--bg-primary)'" onmouseout="this.style.background='transparent'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding-right:36px;">
            <h3 style="margin:0;">Créer un nouvel immeuble</h3>
            <!-- Toggle Rapide / Pro -->
            <div style="display:flex;gap:0;background:var(--bg-primary);border-radius:20px;padding:3px;">
                <button type="button" id="ci-mode-rapide" onclick="setCiMode('rapide')"
                    style="border:none;cursor:pointer;padding:5px 14px;border-radius:18px;font-size:11px;font-weight:600;font-family:'Sora',sans-serif;background:#3d5a80;color:#fff;transition:all .2s;">
                    Rapide
                </button>
                <button type="button" id="ci-mode-pro" onclick="setCiMode('pro')"
                    style="border:none;cursor:pointer;padding:5px 14px;border-radius:18px;font-size:11px;font-weight:600;font-family:'Sora',sans-serif;background:transparent;color:#6e6b65;transition:all .2s;">
                    Pro
                </button>
            </div>
        </div>
        <p style="font-size:12px;color:#6e6b65;margin:0 0 14px;font-family:'Sora',sans-serif;">
            L'immeuble sera ajouté à la base de données et sélectionné automatiquement.
        </p>

        <!-- Champs communs (toujours visibles) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">

            <!-- Champ recherche adresse Google (places.js) -->
            <div class="form-row" style="grid-column:1/-1;">
                <label>Rechercher l'adresse <span style="color:#a8a49e;font-weight:400;">(Google Maps)</span></label>
                <input type="text" id="ci-places-search"
                    data-places-input
                    data-places-endpoint="<?=h(app_url('/api/places_autocomplete.php'))?>"
                    data-places-details-endpoint="<?=h(app_url('/api/places_details.php'))?>"
                    data-places-geocode-endpoint="<?=h(app_url('/api/geocode_address.php'))?>"
                    data-places-street1="ci-adresse"
                    data-places-postal="ci-cp"
                    data-places-city="ci-ville"
                    data-places-country="ci-pays"
                    data-places-lat="ci-lat"
                    data-places-lng="ci-lng"
                    data-places-place-id="ci-place-id"
                    data-places-formatted="ci-adresse-formatee"
                    data-places-country-code="fr"
                    placeholder="Tapez une adresse…"
                    autocomplete="off"
                    style="background:#fff;">
                <small style="color:#a8a49e;font-size:10px;font-family:'DM Mono',monospace;">
                    Sélectionnez dans la liste pour remplir automatiquement adresse, CP, ville et GPS
                </small>
            </div>

            <div class="form-row" style="grid-column:1/-1;">
                <label>Nom de l'immeuble *</label>
                <input type="text" id="ci-nom" placeholder="Ex : Le Chamois">
            </div>
            <div class="form-row" style="grid-column:1/-1;">
                <label>Adresse *</label>
                <input type="text" id="ci-adresse" placeholder="Rempli automatiquement ou saisie libre">
            </div>
            <div class="form-row">
                <label>Code postal</label>
                <input type="text" id="ci-cp" placeholder="69000" maxlength="10">
            </div>
            <div class="form-row">
                <label>Ville</label>
                <input type="text" id="ci-ville" placeholder="Lyon">
            </div>
            <div class="form-row">
                <label>Type d'immeuble</label>
                <select id="ci-type" class="modal-select-v2" onchange="onCiTypeChange()">
                    <option value="">— Choisir —</option>
                    <option value="Immeuble">Immeuble</option>
                    <option value="SDC">SDC (Syndicat de copropriété)</option>
                    <option value="Appartement">Appartement</option>
                    <option value="Maison">Maison</option>
                    <option value="Local commercial">Local commercial</option>
                    <option value="Garage">Garage</option>
                </select>
            </div>
            <div class="form-row">
                <label>Agence</label>
                <select id="ci-agence" class="modal-select-v2">
                    <?php foreach ($agences as $ag): ?>
                    <option value="<?=(int)$ag['id']?>"<?=(int)$ag['id']===(int)$user['id_agence']?' selected':''?>>
                        <?=h($ag['nom_agence'])?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Bloc Rapide : nb lots + syndic (conditionnel selon type) -->
        <div id="ci-bloc-rapide" style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="form-row" id="ci-row-lots" style="display:none;">
                <label>Nombre de lots</label>
                <input type="number" id="ci-nb-lots" min="1" placeholder="Ex : 12">
            </div>
            <div class="form-row" id="ci-row-syndic" style="display:none;">
                <label>Type de syndic</label>
                <select id="ci-syndic-type" class="modal-select-v2">
                    <option value="">— Choisir —</option>
                    <option value="professionnel">Professionnel</option>
                    <option value="benevole">Bénévole</option>
                    <option value="cooperatif">Coopératif</option>
                    <option value="auto">Auto-géré</option>
                </select>
            </div>
        </div>

        <!-- Bloc Pro : champs détaillés (masqué par défaut) -->
        <div id="ci-bloc-pro" style="display:none;margin-top:10px;">
            <div style="height:1px;background:linear-gradient(90deg,var(--shadow-dark),transparent);margin:6px 0 12px;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <div class="form-row">
                    <label>Nb de lots</label>
                    <input type="number" id="ci-nb-lots-pro" min="1" placeholder="12">
                </div>
                <div class="form-row">
                    <label>Nb d'étages</label>
                    <input type="number" id="ci-nb-etages" min="0" placeholder="5">
                </div>
                <div class="form-row">
                    <label>Année construction</label>
                    <input type="number" id="ci-annee" min="1800" max="2030" placeholder="1978">
                </div>
                <div class="form-row">
                    <label>Syndic</label>
                    <select id="ci-syndic-type-pro" class="modal-select-v2">
                        <option value="">— Choisir —</option>
                        <option value="professionnel">Professionnel</option>
                        <option value="benevole">Bénévole</option>
                        <option value="cooperatif">Coopératif</option>
                        <option value="auto">Auto-géré</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Gestionnaire syndic</label>
                    <input type="text" id="ci-gest-syndic" placeholder="Nom / société">
                </div>
                <div class="form-row">
                    <label>Contact syndic</label>
                    <input type="text" id="ci-contact-syndic" placeholder="Tél ou email">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
                <div class="form-row">
                    <label>Digicode</label>
                    <input type="text" id="ci-digicode" placeholder="Ex : A1234">
                </div>
                <div class="form-row">
                    <label>Interphone / badge</label>
                    <input type="text" id="ci-interphone" placeholder="Ex : DUPONT">
                </div>
                <div class="form-row">
                    <label>Nb ascenseurs</label>
                    <input type="number" id="ci-nb-asc" min="0" placeholder="1">
                </div>
                <div class="form-row">
                    <label>Nb parkings</label>
                    <input type="number" id="ci-nb-parking" min="0" placeholder="0">
                </div>
            </div>
            <div class="form-row" style="margin-top:10px;">
                <label>Notes / remarques</label>
                <textarea id="ci-notes" rows="2" style="resize:vertical;" placeholder="Informations complémentaires…"></textarea>
            </div>
        </div>

        <p style="font-size:11px;color:#a8a49e;margin:12px 0 0;font-family:'DM Mono',monospace;">
            Gestionnaire : <strong><?=h($user['prenom'].' '.$user['nom'])?></strong> &nbsp;·&nbsp;
            Société : <strong><?=h($user['nom_agence'] ?? '')?></strong>
        </p>
        <input type="hidden" id="ci-lat" value="">
        <input type="hidden" id="ci-lng" value="">
        <input type="hidden" id="ci-place-id" value="">
        <input type="hidden" id="ci-adresse-formatee" value="">
        <input type="hidden" id="ci-pays" value="">
        <input type="hidden" id="ci-callback" value="">
        <input type="hidden" id="ci-tr-session" value="">
        <input type="hidden" id="ci-tr-ligne" value="">
        <div class="ik-modal-actions">
            <button class="v2-btn primary" onclick="createImmeuble()">Créer et sélectionner</button>
        </div>
    </div>
</div>

<!-- ── Modal édition immeuble (double-clic) ──────────────────────────────── -->
<div class="ik-modal-backdrop" id="immeuble-edit-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal" style="max-width:620px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <h3 style="margin:0;">Fiche immeuble</h3>
            <span id="ei-id-badge" style="font-size:10px;font-family:'DM Mono',monospace;color:#a8a49e;"></span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="form-row" style="grid-column:1/-1;">
                <label>Nom de l'immeuble *</label>
                <input type="text" id="ei-nom" placeholder="Ex : Le Chamois">
            </div>
            <div class="form-row" style="grid-column:1/-1;">
                <label>Adresse *</label>
                <input type="text" id="ei-adresse" placeholder="Ex : 12 rue de la Paix">
            </div>
            <div class="form-row">
                <label>Code postal</label>
                <input type="text" id="ei-cp" maxlength="10">
            </div>
            <div class="form-row">
                <label>Ville</label>
                <input type="text" id="ei-ville">
            </div>
            <div class="form-row">
                <label>Type d'immeuble</label>
                <select id="ei-type" class="modal-select-v2">
                    <option value="">— Choisir —</option>
                    <option value="Immeuble">Immeuble</option>
                    <option value="SDC">SDC (Syndicat de copropriété)</option>
                    <option value="Appartement">Appartement</option>
                    <option value="Maison">Maison</option>
                    <option value="Local commercial">Local commercial</option>
                    <option value="Garage">Garage</option>
                </select>
            </div>
            <div class="form-row">
                <label>Statut</label>
                <select id="ei-statut" class="modal-select-v2">
                    <option value="actif">Actif</option>
                    <option value="inactif">Inactif</option>
                    <option value="archive">Archivé</option>
                </select>
            </div>
        </div>
        <div style="height:1px;background:linear-gradient(90deg,var(--shadow-dark),transparent);margin:12px 0;"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
            <div class="form-row">
                <label>Nb de lots</label>
                <input type="number" id="ei-nb-lots" min="1" placeholder="—">
            </div>
            <div class="form-row">
                <label>Nb d'étages</label>
                <input type="number" id="ei-nb-etages" min="0" placeholder="—">
            </div>
            <div class="form-row">
                <label>Année construction</label>
                <input type="number" id="ei-annee" min="1800" max="2030" placeholder="—">
            </div>
            <div class="form-row">
                <label>Syndic</label>
                <select id="ei-syndic-type" class="modal-select-v2">
                    <option value="">— Choisir —</option>
                    <option value="professionnel">Professionnel</option>
                    <option value="benevole">Bénévole</option>
                    <option value="cooperatif">Coopératif</option>
                    <option value="auto">Auto-géré</option>
                </select>
            </div>
            <div class="form-row">
                <label>Syndic actuel</label>
                <input type="text" id="ei-syndic-actuel" placeholder="Nom / société">
            </div>
            <div class="form-row">
                <label>Nb parkings</label>
                <input type="number" id="ei-nb-parking" min="0" placeholder="—">
            </div>
        </div>
        <div class="form-row" style="margin-top:10px;">
            <label>Commentaire</label>
            <textarea id="ei-commentaire" rows="2" style="resize:vertical;"></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
            <div class="form-row">
                <label>Latitude <span style="color:#a8a49e;font-weight:400;font-size:10px;">(GPS)</span></label>
                <input type="number" id="ei-lat" step="0.0000001" placeholder="Ex : 45.7488" style="font-family:'DM Mono',monospace;font-size:12px;">
            </div>
            <div class="form-row">
                <label>Longitude <span style="color:#a8a49e;font-weight:400;font-size:10px;">(GPS)</span></label>
                <input type="number" id="ei-lng" step="0.0000001" placeholder="Ex : 4.8467" style="font-family:'DM Mono',monospace;font-size:12px;">
            </div>
        </div>
        <p style="font-size:11px;color:#a8a49e;margin:10px 0 0;font-family:'DM Mono',monospace;" id="ei-meta"></p>
        <input type="hidden" id="ei-id" value="">
        <div class="ik-modal-actions">
            <button type="button" class="v2-btn" onclick="document.getElementById('immeuble-edit-modal').classList.remove('open')"
                style="display:flex;align-items:center;gap:6px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Fermer
            </button>
            <button type="button" class="v2-btn primary" onclick="saveEditImmeuble()">Enregistrer</button>
        </div>
    </div>
</div>

<script>
// ── Données passées depuis PHP ────────────────────────────────────────────────
function toggleKpi() {
    const extra = document.getElementById('kpi-extra');
    const btn   = document.getElementById('kpi-toggle-btn');
    const open  = extra.style.display === 'none';
    extra.style.display = open ? 'flex' : 'none';
    btn.classList.toggle('open', open);
}

const IK_CONFIG = {
    agenceDefautId:  <?=(int)($user['id_agence'] ?? 0)?>,
    agenceDefautLat: <?=json_encode($user['agence_lat'] ?? null)?>,
    agenceDefautLng: <?=json_encode($user['agence_lng'] ?? null)?>,
    motifs: <?=json_encode($motifs)?>,
    domicile: <?=json_encode(($domicile && !empty($domicile['latitude'])) ? [
        'lat'   => (float)$domicile['latitude'],
        'lng'   => (float)$domicile['longitude'],
        'label' => 'Domicile – ' . ($domicile['adresse'] ?? '') . ' ' . ($domicile['ville'] ?? ''),
    ] : null)?>,
    agences: <?=json_encode(array_map(fn($a) => [
        'id'    => (int)$a['id'],
        'nom'   => $a['nom_agence'],
        'lat'   => $a['latitude']  ? (float)$a['latitude']  : null,
        'lng'   => $a['longitude'] ? (float)$a['longitude'] : null,
        'label' => trim($a['nom_agence'] . ' – ' . ($a['adresse_1'] ?? '') . ' ' . ($a['ville'] ?? '')),
    ], $agences))?>,
};

// ── Utilitaires ───────────────────────────────────────────────────────────────
let saveTimer = null;
let autosaveInterval = null;

function showSaved() {
    const ind = document.getElementById('save-indicator');
    ind.classList.add('visible');
    setTimeout(() => ind.classList.remove('visible'), 2200);
}

function getRow(el) {
    return el.closest('tr');
}

function getSessionId(el) {
    return parseInt(getRow(el)?.dataset?.sessionId || el.closest('.ik-session')?.id?.replace('session-','') || 0);
}

function isSessionLocked(sessionId) {
    const sess = document.getElementById('session-' + sessionId);
    return sess?.dataset?.locked === '1';
}

function isRowLocked(tr) {
    return isSessionLocked(parseInt(tr.dataset.sessionId));
}

function disableLockedSessions() {
    document.querySelectorAll('.ik-session[data-locked="1"]').forEach(sess => {
        sess.querySelectorAll('input, select, textarea, button').forEach(el => {
            if (el.classList.contains('keep-active')) return;
            el.disabled = true;
            el.setAttribute('aria-disabled', 'true');
        });
    });
}

function rowToData(tr) {
    const d = {
        id:             parseInt(tr.dataset.ligneId || 0),
        id_session:     parseInt(tr.dataset.sessionId),
        date_deplacement: tr.querySelector('.ik-date')?.value || null,
        destination_label: tr.querySelector('.ik-imm-input')?.value || '',
        destination_ville: tr.querySelector('.ik-ville')?.value || '',
        id_immeuble:    parseInt(tr.querySelector('.ik-imm-id')?.value || 0) || null,
        destination_lat: parseFloat(tr.querySelector('.ik-imm-lat')?.value) || null,
        destination_lng: parseFloat(tr.querySelector('.ik-imm-lng')?.value) || null,
        motif:          tr.querySelector('.ik-motif')?.value || '',
        observation:    tr.querySelector('.ik-obs')?.value || '',
        distance_calculee: parseFloat(tr.dataset.dist) || null,
        depart_lat:     parseFloat(tr.dataset.departLat) || null,
        depart_lng:     parseFloat(tr.dataset.departLng) || null,
    };

    // Départ
    const departSel = tr.querySelector('.ik-depart');
    if (departSel) {
        const val = departSel.value; // "agence:3", "domicile:0", "immeuble:0"
        const [type, idVal] = val.split(':');
        d.type_depart = type;
        d.id_agence_depart = (type === 'agence') ? (parseInt(idVal) || null) : null;
        // Pour type=immeuble, stocker l'ID dans id_agence_depart via id_depart_immeuble
        if (type === 'immeuble') d.id_depart_immeuble = parseInt(idVal) || null;

        if (type === 'immeuble') {
            const dSearch = tr.querySelector('.ik-depart-search');
            d.depart_lat   = parseFloat(dSearch?.querySelector('.ik-depart-imm-lat')?.value) || null;
            d.depart_lng   = parseFloat(dSearch?.querySelector('.ik-depart-imm-lng')?.value) || null;
            d.depart_label = dSearch?.querySelector('.ik-depart-imm-input')?.value || '';
            d.id_depart_immeuble = parseInt(dSearch?.querySelector('.ik-depart-imm-id')?.value) || null;
        } else if (type === 'adresse') {
            const aWrap = tr.querySelector('.ik-depart-addr-wrap');
            d.depart_lat   = parseFloat(aWrap?.querySelector('.ik-depart-addr-lat')?.value) || null;
            d.depart_lng   = parseFloat(aWrap?.querySelector('.ik-depart-addr-lng')?.value) || null;
            d.depart_label = aWrap?.querySelector('.ik-depart-addr-input')?.value || '';
        } else {
            const opt = departSel.options[departSel.selectedIndex];
            d.depart_label = opt?.dataset?.label || '';
            d.depart_lat   = parseFloat(opt?.dataset?.lat) || null;
            d.depart_lng   = parseFloat(opt?.dataset?.lng) || null;
        }
        tr.dataset.departLat = d.depart_lat || '';
        tr.dataset.departLng = d.depart_lng || '';
    }

    // KM
    const allerVal  = parseFloat(tr.querySelector('.ik-km-aller-input')?.value) || null;
    const retourVal = parseFloat(tr.querySelector('.ik-km-retour-input')?.value) || null;
    d.km_aller  = allerVal;
    d.km_retour = retourVal;

    return d;
}

async function saveLigne(tr) {
    if (isRowLocked(tr)) return;
    const data = rowToData(tr);
    // Ne pas sauvegarder les lignes vraiment vides
    if (!data.date_deplacement && !data.destination_label && !data.km_aller && !data.km_retour) {
        return;
    }
    try {
        const r = await fetch('api/ik_save_ligne.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        const json = await r.json();
        if (json.ok && json.id && !data.id) {
            tr.dataset.ligneId = json.id;
        }
        showSaved();
        updateTotals(parseInt(tr.dataset.sessionId));
    } catch(e) { console.error('Erreur sauvegarde:', e); }
}

function scheduleSave(tr) {
    if (isRowLocked(tr)) return;
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => saveLigne(tr), 800);
}

// ── Événements de champ ────────────────────────────────────────────────────────
function onDateChange(input) {
    // Mettre à jour l'affichage jj/m/aa
    const display = input.previousElementSibling;
    if (input.value) {
        const [y, m, d] = input.value.split('-').map(Number);
        const yy = String(y).slice(-2);
        display.textContent = `${d}/${m}/${yy}`;
        display.classList.remove('empty');
    } else {
        display.textContent = 'jj/m/aa';
        display.classList.add('empty');
    }
    onFieldChange(input);
}

function markRowActive(tr) {
    if (!tr) return;
    const wasEmpty = tr.classList.contains('ik-row-empty');
    tr.classList.remove('ik-row-empty');
    if (wasEmpty) {
        const sessId = parseInt(tr.dataset.sessionId);
        if (sessId) {
            const tbody = document.getElementById('tbody-' + sessId);
            if (tbody && tbody.querySelectorAll('.ik-row-empty').length === 0) {
                tbody.appendChild(buildEmptyRow(sessId));
            }
        }
    }
}

function onFieldChange(el) {
    const tr = getRow(el);
    if (isRowLocked(tr)) return;
    markRowActive(tr);
    scheduleSave(tr);
}

function onDepartChange(el) {
    const tr   = getRow(el);
    if (isRowLocked(tr)) return;
    const opt  = el.options[el.selectedIndex];
    const val  = el.value;
    el.dataset.prevVal = val; // mémoriser pour rollback si besoin // "agence:3", "domicile:0", "immeuble:0"
    const type = val.split(':')[0];
    const searchDiv = tr.querySelector('.ik-depart-search');

    const addrWrap = tr.querySelector('.ik-depart-addr-wrap');

    // Cacher les deux zones par défaut
    if (searchDiv) searchDiv.style.display = 'none';
    if (addrWrap)  addrWrap.style.display  = 'none';

    // Bouton édition départ
    const editBtn = tr.querySelector('.ik-depart-edit-btn');

    if (type === 'immeuble') {
        if (searchDiv) searchDiv.style.display = 'block';
        tr.dataset.departLat = opt?.dataset?.lat || '';
        tr.dataset.departLng = opt?.dataset?.lng || '';
        // Afficher bouton édition si immeuble avec ID connu
        const immId = parseInt(el.value.split(':')[1]) || 0;
        if (editBtn) { editBtn.dataset.immId = immId; editBtn.style.display = immId > 0 ? 'flex' : 'none'; }
    } else if (type === 'adresse') {
        // Ouvrir le modal création immeuble directement (comme pour la destination)
        const sessId  = tr.dataset.sessionId || '';
        const ligneId = tr.dataset.ligneId   || '';
        openCreateImmeuble('depart', '', '', sessId, ligneId);
        // Remettre le select sur la valeur précédente pour ne pas rester sur "adresse"
        el.value = el.dataset.prevVal || (el.options[0]?.value || '');
    } else {
        if (editBtn) { editBtn.style.display = 'none'; editBtn.dataset.immId = '0'; }
        // Agence ou domicile : réinitialiser les champs cachés
        if (searchDiv) { searchDiv.querySelector('.ik-depart-imm-input').value = ''; searchDiv.querySelector('.ik-depart-imm-lat').value = ''; searchDiv.querySelector('.ik-depart-imm-lng').value = ''; searchDiv.querySelector('.ik-depart-imm-results').style.display = 'none'; }
        if (addrWrap)  { addrWrap.querySelector('.ik-depart-addr-input').value = ''; addrWrap.querySelector('.ik-depart-addr-lat').value = ''; addrWrap.querySelector('.ik-depart-addr-lng').value = ''; }
        tr.dataset.departLat = opt?.dataset?.lat || '';
        tr.dataset.departLng = opt?.dataset?.lng || '';
    }

    // Recalculer distance si destination déjà renseignée
    const immLat = parseFloat(tr.querySelector('.ik-imm-lat')?.value);
    const immLng = parseFloat(tr.querySelector('.ik-imm-lng')?.value);
    if (immLat && immLng && tr.dataset.departLat && tr.dataset.departLng) {
        recalcDistance(tr, parseFloat(tr.dataset.departLat), parseFloat(tr.dataset.departLng), immLat, immLng);
    }
    markRowActive(tr);
    scheduleSave(tr);
}

// ── Recherche d'immeuble comme point de DÉPART ────────────────────────────────
let departImmTimer = null;
function onDepartImmInput(input) {
    clearTimeout(departImmTimer);
    const wrap    = input.closest('.ik-depart-search');
    const results = wrap.querySelector('.ik-depart-imm-results');
    const q = input.value.trim();
    if (q.length < 2) { results.style.display = 'none'; return; }
    results._activeIdx = -1;
    departImmTimer = setTimeout(async () => {
        const r = await fetch('api/ik_search_immeubles.php?q=' + encodeURIComponent(q));
        const items = await r.json();
        results._activeIdx = -1;
        const tr = wrap.closest('tr');
        const sessId = tr?.dataset?.sessionId || '';
        results.innerHTML = items.map(it => `
            <div class="ik-depart-imm-item"
                data-lat="${it.latitude||''}" data-lng="${it.longitude||''}"
                data-label="${escHtml(it.nom_immeuble)} – ${escHtml(it.adresse)}"
                onmousedown="event.preventDefault()"
                onclick="selectDepartImmeuble(this)">
                <strong>${escHtml(it.nom_immeuble)}</strong>
                <small>${escHtml(it.adresse)} ${escHtml(it.code_postal)} ${escHtml(it.ville)}</small>
            </div>`).join('');
        results.innerHTML += `<div class="ik-depart-imm-item" style="border-top:1px solid rgba(196,192,186,0.4);color:#36577d;font-weight:600;font-family:'DM Mono',monospace;font-size:11px;"
            onmousedown="event.preventDefault()"
            onclick="openCreateImmeuble('depart', '${escHtml(q)}', '', '${sessId}')">
            + Créer « ${escHtml(q)} » dans la base…</div>`;
        results.style.display = 'block';
    }, 280);
}

function onDepartImmKeydown(input, event) {
    const wrap    = input.closest('.ik-depart-search');
    const results = wrap.querySelector('.ik-depart-imm-results');
    const items   = results.querySelectorAll('.ik-depart-imm-item');
    if (!items.length || results.style.display === 'none') return;
    let idx = results._activeIdx ?? -1;
    if (event.key === 'ArrowDown')  { event.preventDefault(); idx = Math.min(idx + 1, items.length - 1); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); idx = Math.max(idx - 1, 0); }
    else if (event.key === 'Enter')   { event.preventDefault(); if (idx >= 0) selectDepartImmeuble(items[idx]); return; }
    else if (event.key === 'Escape')  { results.style.display = 'none'; results._activeIdx = -1; return; }
    else return;
    results._activeIdx = idx;
    items.forEach((it, i) => it.classList.toggle('active', i === idx));
    if (items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
}

function onDepartImmBlur(input) {
    setTimeout(() => {
        const wrap = input.closest('.ik-depart-search');
        if (wrap) { wrap.querySelector('.ik-depart-imm-results').style.display = 'none'; }
    }, 150);
}

// ── Modal création immeuble ───────────────────────────────────────────────────
function openCreateImmeuble(callback, searchQuery, address, sessId, ligneId) {
    // Essayer de décomposer "12 rue de la Paix 69000 Lyon" → adresse + cp + ville
    const modal = document.getElementById('immeuble-create-modal');
    document.getElementById('ci-nom').value      = '';
    document.getElementById('ci-adresse').value  = '';
    document.getElementById('ci-places-search').value = searchQuery;
    document.getElementById('ci-cp').value       = '';
    document.getElementById('ci-ville').value    = '';
    document.getElementById('ci-lat').value      = '';
    document.getElementById('ci-lng').value      = '';
    document.getElementById('ci-callback').value  = callback;
    document.getElementById('ci-tr-session').value = sessId;
    document.getElementById('ci-tr-ligne').value   = ligneId || '';

    // Essayer d'extraire CP + ville depuis la query
    const cpMatch = searchQuery.match(/\b(\d{5})\b\s*(.*)/);
    if (cpMatch) {
        document.getElementById('ci-cp').value    = cpMatch[1];
        document.getElementById('ci-ville').value = cpMatch[2].trim();
    }

    // Réinitialiser mode à chaque ouverture
    setCiMode('rapide');
    document.getElementById('ci-type').value = '';
    document.getElementById('ci-row-lots').style.display   = 'none';
    document.getElementById('ci-row-syndic').style.display = 'none';
    document.getElementById('ci-nb-lots').value = '';
    document.getElementById('ci-syndic-type').value = '';
    // Réinitialiser hidden GPS
    ['ci-lat','ci-lng','ci-place-id','ci-adresse-formatee','ci-pays'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    modal.classList.add('open');
    setTimeout(() => {
        const ciSearch = document.getElementById('ci-places-search');
        if (ciSearch) { ciSearch.focus(); ciSearch.select(); }
    }, 50);
}

async function createImmeuble() {
    const nom     = document.getElementById('ci-nom').value.trim();
    const adresse = document.getElementById('ci-adresse').value.trim();
    const cp      = document.getElementById('ci-cp').value.trim();
    const ville   = document.getElementById('ci-ville').value.trim();
    const type    = document.getElementById('ci-type').value;
    const agence  = parseInt(document.getElementById('ci-agence').value) || 0;
    const callback= document.getElementById('ci-callback').value;
    const sessId  = document.getElementById('ci-tr-session').value;
    const ligneId = document.getElementById('ci-tr-ligne').value;

    if (!nom || !adresse) { alert('Le nom et l\'adresse sont obligatoires.'); return; }

    // Collecter champs selon le mode
    const payload = {
        nom_immeuble: nom, adresse_1: adresse, code_postal: cp, ville,
        pays: document.getElementById('ci-pays').value || 'France',
        type_immeuble: type, id_agence: agence,
        latitude:          parseFloat(document.getElementById('ci-lat').value)          || null,
        longitude:         parseFloat(document.getElementById('ci-lng').value)          || null,
        google_place_id:   document.getElementById('ci-place-id').value                || null,
        adresse_formatee:  document.getElementById('ci-adresse-formatee').value        || null,
    };
    if (ciMode === 'rapide') {
        payload.nb_lots     = parseInt(document.getElementById('ci-nb-lots').value)      || null;
        payload.syndic_type = document.getElementById('ci-syndic-type').value            || null;
    } else {
        payload.nb_lots          = parseInt(document.getElementById('ci-nb-lots-pro').value)    || null;
        payload.nb_niveaux       = parseInt(document.getElementById('ci-nb-etages').value)      || null;
        payload.annee_construction = parseInt(document.getElementById('ci-annee').value)        || null;
        payload.syndic_type      = document.getElementById('ci-syndic-type-pro').value          || null;
        payload.syndic_actuel    = [document.getElementById('ci-gest-syndic').value.trim(), document.getElementById('ci-contact-syndic').value.trim()].filter(Boolean).join(' — ') || null;
        payload.digicode         = document.getElementById('ci-digicode').value.trim()          || null;
        payload.interphone       = document.getElementById('ci-interphone').value.trim()        || null;
        payload.nb_stationnements = parseInt(document.getElementById('ci-nb-parking').value)    || null;
        payload.presence_ascenseur = parseInt(document.getElementById('ci-nb-asc').value) > 0 ? 1 : 0;
        payload.commentaire      = document.getElementById('ci-notes').value.trim()             || null;
    }

    const btn = document.querySelector('#immeuble-create-modal .v2-btn.primary');
    btn.disabled = true; btn.textContent = 'Création…';

    try {
        const resp = await fetch('api/ik_create_immeuble.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (!data.ok) { alert('Erreur : ' + (data.error || 'inconnue')); return; }

        document.getElementById('immeuble-create-modal').classList.remove('open');

        // Trouver la ligne cible : par ligneId exact, sinon ligne active, sinon première de la session
        const tr = (ligneId ? document.querySelector(`tr[data-ligne-id="${ligneId}"]`) : null)
                || document.querySelector('tr.ik-row-active')
                || (sessId ? document.querySelector(`tr[data-session-id="${sessId}"]`) : null);
        if (!tr) return;

        let lat = data.latitude  || null;
        let lng = data.longitude || null;

        // Géocoder si nécessaire
        if (!lat && adresse) {
            const geo = await geocodeSave('immeuble', data.id);
            if (geo) { lat = geo.lat; lng = geo.lng; }
        }

        if (callback === 'dest') {
            const wrap = tr.querySelector('.ik-imm-wrap');
            if (wrap) {
                wrap.querySelector('.ik-imm-input').value  = `${nom} - ${adresse}`;
                wrap.querySelector('.ik-imm-id').value     = data.id;
                wrap.querySelector('.ik-imm-lat').value    = lat || '';
                wrap.querySelector('.ik-imm-lng').value    = lng || '';
                wrap.querySelector('.ik-imm-results').style.display = 'none';
                const villeInput = tr.querySelector('.ik-ville');
                if (villeInput && ville) villeInput.value = ville;
                tr.dataset.destLat = lat || ''; tr.dataset.destLng = lng || '';
            }
        } else { // 'depart'
            const label = `${nom} - ${adresse}`;
            // Ajouter une option dans le select depart et la sélectionner
            const sel = tr.querySelector('.ik-depart');
            if (sel) {
                const optVal = `immeuble:${data.id}`;
                let opt = sel.querySelector(`option[value="${optVal}"]`);
                if (!opt) {
                    opt = document.createElement('option');
                    opt.value = optVal;
                    sel.appendChild(opt);
                }
                opt.textContent = label;
                opt.dataset.lat   = lat || '';
                opt.dataset.lng   = lng || '';
                opt.dataset.label = label;
                sel.value = optVal;
            }
            // Masquer le bloc de recherche — le select affiche déjà le bon label
            const searchDiv = tr.querySelector('.ik-depart-search');
            if (searchDiv) {
                searchDiv.style.display = 'none';
                const hiddenId = searchDiv.querySelector('.ik-depart-imm-id');
                if (hiddenId) hiddenId.value = data.id;
            }
            const editBtnD = tr.querySelector('.ik-depart-edit-btn');
            if (editBtnD) { editBtnD.dataset.immId = data.id; editBtnD.style.display = 'flex'; }
            tr.dataset.departLat = lat || '';
            tr.dataset.departLng = lng || '';
        }

        markRowActive(tr);
        scheduleSave(tr);

        // Recalculer la distance si les deux points sont connus
        const dLat = parseFloat(tr.dataset.departLat), dLng = parseFloat(tr.dataset.departLng);
        const aLat = parseFloat(tr.dataset.destLat),   aLng = parseFloat(tr.dataset.destLng);
        if (dLat && dLng && aLat && aLng) recalcDistance(tr, dLat, dLng, aLat, aLng);

    } finally {
        btn.disabled = false; btn.textContent = 'Créer et sélectionner';
    }
}

async function selectDepartImmeuble(item) {
    const wrap = item.closest('.ik-depart-search');
    const tr   = item.closest('tr');
    const label = item.dataset.label;
    let lat  = parseFloat(item.dataset.lat) || 0;
    let lng  = parseFloat(item.dataset.lng) || 0;
    if ((!lat || !lng) && item.dataset.id) {
        const geo = await geocodeSave('immeuble', item.dataset.id);
        if (geo) { lat = geo.lat; lng = geo.lng; }
    }

    wrap.querySelector('.ik-depart-imm-input').value = label;
    wrap.querySelector('.ik-depart-imm-lat').value   = lat;
    wrap.querySelector('.ik-depart-imm-lng').value   = lng;
    wrap.querySelector('.ik-depart-imm-results').style.display = 'none';
    // Stocker l'id dans le hidden et afficher le bouton édition
    const immIdVal = parseInt(item.dataset.id) || 0;
    const hiddenId = wrap.querySelector('.ik-depart-imm-id');
    if (hiddenId) hiddenId.value = immIdVal;
    const editBtn = tr.querySelector('.ik-depart-edit-btn');
    if (editBtn) { editBtn.dataset.immId = immIdVal; editBtn.style.display = immIdVal > 0 ? 'flex' : 'none'; }

    // Mettre à jour le dataset du tr et l'option du select
    tr.dataset.departLat = lat;
    tr.dataset.departLng = lng;
    const sel = tr.querySelector('.ik-depart');
    if (sel) {
        // Mettre à jour l'option avec le vrai ID et label
        const newVal = `immeuble:${immIdVal}`;
        let opt = sel.querySelector(`option[value="${newVal}"]`) || sel.querySelector('option[value="immeuble:0"]');
        if (opt) {
            opt.value = newVal;
            opt.dataset.lat = lat; opt.dataset.lng = lng; opt.dataset.label = label;
            opt.textContent = label || '🔍 Autre immeuble…';
            sel.value = newVal;
        }
    }

    // Recalculer distance vers la destination si elle existe
    const immLat = parseFloat(tr.querySelector('.ik-imm-lat')?.value);
    const immLng = parseFloat(tr.querySelector('.ik-imm-lng')?.value);
    if (lat && lng && immLat && immLng) {
        recalcDistance(tr, parseFloat(lat), parseFloat(lng), immLat, immLng);
    }
    markRowActive(tr);
    scheduleSave(tr);
    wrap.querySelector('.ik-depart-imm-input').focus();
}

// ── Recherche immeuble ─────────────────────────────────────────────────────────
let immSearchTimer = null;
function onImmInput(input) {
    clearTimeout(immSearchTimer);
    const wrap = input.closest('.ik-imm-wrap');
    const results = wrap.querySelector('.ik-imm-results');
    const q = input.value.trim();
    if (q.length < 2) { results.style.display = 'none'; results._activeIdx = -1; return; }
    immSearchTimer = setTimeout(async () => {
        const r = await fetch('api/ik_search_immeubles.php?q=' + encodeURIComponent(q));
        const items = await r.json();
        results._activeIdx = -1;
        const tr = wrap.closest('tr');
        const sessId  = tr?.dataset?.sessionId || '';
        const ligneId = tr?.dataset?.ligneId   || '';
        results.innerHTML = items.map(it => `
            <div class="ik-imm-item"
                data-id="${it.id}"
                data-label="${escHtml(it.nom_immeuble)} - ${escHtml(it.adresse)}"
                data-ville="${escHtml(it.ville)}"
                data-lat="${it.latitude||''}" data-lng="${it.longitude||''}"
                onmousedown="event.preventDefault()"
                onclick="selectImmeuble(this)">
                <strong>${escHtml(it.nom_immeuble)}${it.reference_immeuble ? ` <span style="font-family:'DM Mono',monospace;font-size:10px;color:#a8a49e;font-weight:400;">#${escHtml(it.reference_immeuble)}</span>` : ''}</strong>
                <small>${escHtml(it.adresse)} ${escHtml(it.code_postal)} ${escHtml(it.ville)}</small>
            </div>`).join('');
        // Bouton "Créer cet immeuble"
        results.innerHTML += `<div class="ik-imm-item" style="border-top:1px solid rgba(196,192,186,0.4);color:#36577d;font-weight:600;font-family:'DM Mono',monospace;font-size:11px;"
            onmousedown="event.preventDefault()"
            onclick="openCreateImmeuble('dest', '${escHtml(q)}', '', '${sessId}', '${ligneId}')">
            + Créer « ${escHtml(q)} » dans la base…</div>`;
        // Bouton "Saisir adresse libre via Google" (fallback si pas d'immeuble correspondant)
        results.innerHTML += `<div class="ik-imm-item ik-imm-item-google"
            onmousedown="event.preventDefault()"
            onclick="switchImmToFreeAddress(this)">
            🔍 Saisir adresse libre via Google</div>`;
        results.style.display = 'block';
        positionImmResults(input, results);
    }, 280);
}

// Bascule l'input destination en mode "adresse libre Google"
function switchImmToFreeAddress(item) {
    const wrap  = item.closest('.ik-imm-wrap');
    const input = wrap.querySelector('.ik-imm-input');
    const results = wrap.querySelector('.ik-imm-results');
    const tr = item.closest('tr');
    if (!input || !tr) return;
    // Visuel : bordure orange + placeholder explicite
    input.classList.add('is-free-address');
    input.placeholder = 'Adresse libre (Google)…';
    // Reset id immeuble (on n'est plus sur un immeuble en BDD)
    const idEl = wrap.querySelector('.ik-imm-id');
    if (idEl) idEl.value = '0';
    // Cache la dropdown
    results.style.display = 'none';
    // Branche Google Places
    attachPlacesToImmDest(input, tr);
    // Refocus + sélection pour que l'utilisateur tape directement
    input.focus();
    input.select();
}

// Branche Google Places Autocomplete sur le champ destination (équivalent de attachPlacesAutocomplete pour le départ)
function attachPlacesToImmDest(input, tr) {
    if (!googleReady || !window.google?.maps?.places) {
        console.warn('[IK] Google Places pas prêt — ré-essayer dans 500ms');
        setTimeout(() => attachPlacesToImmDest(input, tr), 500);
        return;
    }
    if (input._placesInitDest) return;
    input._placesInitDest = true;
    const ac = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: 'fr' },
        fields: ['geometry', 'formatted_address'],
    });
    ac.addListener('place_changed', () => {
        const place = ac.getPlace();
        if (!place.geometry) return;
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();
        const wrap = input.closest('.ik-imm-wrap');
        const latEl = wrap.querySelector('.ik-imm-lat');
        const lngEl = wrap.querySelector('.ik-imm-lng');
        const idEl  = wrap.querySelector('.ik-imm-id');
        if (latEl) latEl.value = lat;
        if (lngEl) lngEl.value = lng;
        if (idEl)  idEl.value  = '0';
        // Adresse formatée comme valeur visible
        if (place.formatted_address) input.value = place.formatted_address;
        // Recalc distance si départ a déjà des coordonnées
        const departLat = parseFloat(tr.dataset.departLat);
        const departLng = parseFloat(tr.dataset.departLng);
        if (departLat && departLng && lat && lng) recalcDistance(tr, departLat, departLng, lat, lng);
        markRowActive(tr);
        scheduleSave(tr);
    });
}

function positionImmResults(input, results) {
    const rect = input.getBoundingClientRect();
    results.style.top   = (rect.bottom + 2) + 'px';
    results.style.left  = rect.left + 'px';
    results.style.width = rect.width + 'px';
}

function onImmKeydown(input, event) {
    const wrap    = input.closest('.ik-imm-wrap');
    const results = wrap.querySelector('.ik-imm-results');
    const items   = results.querySelectorAll('.ik-imm-item');
    if (!items.length || results.style.display === 'none') return;

    let idx = results._activeIdx ?? -1;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        idx = Math.min(idx + 1, items.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        idx = Math.max(idx - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (idx >= 0 && items[idx]) selectImmeuble(items[idx]);
        return;
    } else if (event.key === 'Escape') {
        results.style.display = 'none';
        results._activeIdx = -1;
        return;
    } else { return; }

    results._activeIdx = idx;
    items.forEach((it, i) => it.classList.toggle('ik-imm-item-active', i === idx));
    if (items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
}

function onImmBlur(input) {
    // Délai suffisant pour laisser le click sur un item se déclencher
    setTimeout(() => {
        const wrap = input.closest('.ik-imm-wrap');
        if (wrap) {
            const results = wrap.querySelector('.ik-imm-results');
            results.style.display = 'none';
            results._activeIdx = -1;
        }
    }, 150);
}

async function selectImmeuble(item) {
    const wrap = item.closest('.ik-imm-wrap');
    const tr   = item.closest('tr');
    wrap.querySelector('.ik-imm-input').value = item.dataset.label;
    wrap.querySelector('.ik-imm-id').value    = item.dataset.id;
    const results = wrap.querySelector('.ik-imm-results');
    results.style.display = 'none';
    results._activeIdx = -1;

    const villeInput = tr.querySelector('.ik-ville');
    if (villeInput && item.dataset.ville) villeInput.value = item.dataset.ville;

    markRowActive(tr);

    // Obtenir les coordonnées (géocoder si manquantes)
    let lat = parseFloat(item.dataset.lat) || 0;
    let lng = parseFloat(item.dataset.lng) || 0;
    if ((!lat || !lng) && item.dataset.id) {
        const geo = await geocodeSave('immeuble', item.dataset.id);
        if (geo) { lat = geo.lat; lng = geo.lng; }
    }
    wrap.querySelector('.ik-imm-lat').value = lat || '';
    wrap.querySelector('.ik-imm-lng').value = lng || '';

    // Recalculer distance si départ disponible
    let dLat = parseFloat(tr.dataset.departLat);
    let dLng = parseFloat(tr.dataset.departLng);
    // Fallback : lire depuis l'option sélectionnée du select départ
    if ((!dLat || !dLng)) {
        const sel = tr.querySelector('.ik-depart');
        const opt = sel?.options[sel.selectedIndex];
        dLat = parseFloat(opt?.dataset?.lat) || 0;
        dLng = parseFloat(opt?.dataset?.lng) || 0;
        // Fallback 2 : lire depuis le bloc départ immeuble
        if (!dLat || !dLng) {
            dLat = parseFloat(tr.querySelector('.ik-depart-imm-lat')?.value) || 0;
            dLng = parseFloat(tr.querySelector('.ik-depart-imm-lng')?.value) || 0;
        }
        if (dLat) tr.dataset.departLat = dLat;
        if (dLng) tr.dataset.departLng = dLng;
    }
    if (dLat && dLng && lat && lng) recalcDistance(tr, dLat, dLng, lat, lng);

    scheduleSave(tr);
    wrap.querySelector('.ik-imm-input').focus();
}

// ── Géocodage à la volée + sauvegarde en base ─────────────────────────────────
async function geocodeSave(type, id) {
    try {
        const r = await fetch(`api/ik_geocode_save.php?type=${type}&id=${id}`);
        const j = await r.json();
        return j.ok ? { lat: j.lat, lng: j.lng } : null;
    } catch(e) { return null; }
}

// ── Distance à vol d'oiseau (fallback immédiat) ───────────────────────────────
function haversine(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2-lat1)*Math.PI/180;
    const dLng = (lng2-lng1)*Math.PI/180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2;
    return Math.round(R * 2 * Math.asin(Math.sqrt(a)) * 10) / 10;
}

// ── Distance routière via Google (async) ─────────────────────────────────────
async function getRoadDistance(lat1, lng1, lat2, lng2) {
    try {
        const r = await fetch(`api/ik_distance_road.php?olat=${lat1}&olng=${lng1}&dlat=${lat2}&dlng=${lng2}`);
        const j = await r.json();
        return j.ok ? j.km : haversine(lat1, lng1, lat2, lng2);
    } catch(e) { return haversine(lat1, lng1, lat2, lng2); }
}

// ── Appliquer une distance calculée sur une ligne ─────────────────────────────
function applyDist(tr, dist) {
    if (!dist) return;
    tr.dataset.dist = dist;
    ['aller', 'retour'].forEach(dir => {
        const input = tr.querySelector(`.ik-km-${dir}-input`);
        const btn   = input?.closest('.ik-km-toggle-wrap')?.querySelector('.ik-km-toggle');
        if (!input || !btn) return;
        // Activer et remplir seulement si le bouton est déjà actif OU si le champ est vide
        if (btn.classList.contains('active') || !input.value) {
            input.value = dist.toFixed(1);
            btn.classList.add('active');
            input.classList.add('visible');
            input.classList.remove('ik-km-input-val');
        }
    });
    updateTotalLigne(tr);
}

// ── Recalculer distance (haversine immédiat + routier async en fond) ──────────
function recalcDistance(tr, lat1, lng1, lat2, lng2) {
    if (!lat1 || !lng1 || !lat2 || !lng2) return;
    // 1. Affichage immédiat avec Haversine
    const hDist = haversine(lat1, lng1, lat2, lng2);
    applyDist(tr, hDist);
    // 2. Remplacement silencieux par la distance routière Google
    getRoadDistance(lat1, lng1, lat2, lng2).then(rDist => {
        if (rDist && Math.abs(rDist - hDist) > 0.05) applyDist(tr, rDist);
    });
}

// ── Toggle Aller / Retour ──────────────────────────────────────────────────────
function toggleKm(btn, direction) {
    const tr      = getRow(btn);
    if (isRowLocked(tr)) return;
    const isAller = direction === 'aller';
    const input   = btn.closest('.ik-km-toggle-wrap').querySelector(isAller ? '.ik-km-aller-input' : '.ik-km-retour-input');

    if (btn.classList.contains('active')) {
        // Désactiver
        btn.classList.remove('active');
        input.value = '';
        input.classList.remove('visible');
        input.classList.add('ik-km-input-val');
        updateTotalLigne(tr);
        scheduleSave(tr);
        return;
    }

    // Tenter de recalculer la distance si elle n'est pas encore en cache
    let dist = parseFloat(tr.dataset.dist) || 0;
    if (!dist) {
        const dLat = parseFloat(tr.dataset.departLat);
        const dLng = parseFloat(tr.dataset.departLng);
        const iLat = parseFloat(tr.querySelector('.ik-imm-lat')?.value);
        const iLng = parseFloat(tr.querySelector('.ik-imm-lng')?.value);
        if (dLat && dLng && iLat && iLng) {
            dist = haversine(dLat, dLng, iLat, iLng);
            tr.dataset.dist = dist;
        }
    }

    // Activer avec la distance calculée
    btn.classList.add('active');
    if (dist > 0) input.value = dist.toFixed(1);
    input.classList.add('visible');
    input.classList.remove('ik-km-input-val');
    updateTotalLigne(tr);
    scheduleSave(tr);
}

function onKmChange(input, direction) {
    const tr = getRow(input);
    if (isRowLocked(tr)) return;
    updateTotalLigne(tr);
    scheduleSave(tr);
}

function updateTotalLigne(tr) {
    const aller  = parseFloat(tr.querySelector('.ik-km-aller-input')?.value) || 0;
    const retour = parseFloat(tr.querySelector('.ik-km-retour-input')?.value) || 0;
    const total  = aller + retour;
    const span   = tr.querySelector('.ik-total-ligne');
    if (span) span.textContent = total > 0 ? formatKm(total) : '—';
    updateTotals(parseInt(tr.dataset.sessionId));
}

function formatKm(v) {
    return parseFloat(v).toFixed(1).replace('.', ',');
}

// ── Totaux de session ──────────────────────────────────────────────────────────
function updateTotals(sessionId) {
    const tbody = document.getElementById('tbody-' + sessionId);
    if (!tbody) return;
    let total = 0, nbDeps = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
        const a = parseFloat(tr.querySelector('.ik-km-aller-input')?.value) || 0;
        const r = parseFloat(tr.querySelector('.ik-km-retour-input')?.value) || 0;
        total += a + r;
        if (a || r || tr.querySelector('.ik-imm-input')?.value) nbDeps++;
    });
    const card = document.getElementById('session-' + sessionId);
    if (card) {
        card.querySelectorAll('.sess-total-km').forEach(el => {
            el.textContent = formatKm(total) + ' km';
        });
    }
    updateGlobalRecap();
}

// ── Récapitulatif global (barre en haut) ──────────────────────────────────────
function updateGlobalRecap() {
    let totalKm = 0, totalDeps = 0;
    document.querySelectorAll('.ik-table tbody').forEach(tbody => {
        tbody.querySelectorAll('tr').forEach(tr => {
            const a = parseFloat(tr.querySelector('.ik-km-aller-input')?.value) || 0;
            const r = parseFloat(tr.querySelector('.ik-km-retour-input')?.value) || 0;
            totalKm += a + r;
            if (a || r || tr.querySelector('.ik-imm-input')?.value) totalDeps++;
        });
    });
    const elKm   = document.getElementById('stat-total-km');
    const elDeps = document.getElementById('stat-nb-deps');
    const elAlert = document.getElementById('paie-alert-global');
    if (elKm)  elKm.textContent  = formatKm(totalKm);
    if (elDeps) elDeps.textContent = totalDeps;
    if (elAlert && document.querySelectorAll('.ik-session').length === 1) {
        const moisPaie = document.querySelector('.ik-session-paie strong')?.textContent || '…';
        elAlert.innerHTML = `À intégrer dans la paie de : <strong>${moisPaie}</strong><br>Reporter champ <em>nb de KM</em> : <strong>${formatKm(totalKm)} km</strong>`;
    }
}

// ── Actions lignes ─────────────────────────────────────────────────────────────
async function deleteLigne(btn) {
    const tr = getRow(btn);
    if (isRowLocked(tr)) { alert('Mois de paie clôturé : modification impossible.'); return; }
    const id = parseInt(tr.dataset.ligneId || 0);
    if (!confirm('Supprimer ce déplacement ?')) return;
    if (id > 0) {
        await fetch('api/ik_delete_ligne.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id})
        });
    }
    const sessId = parseInt(tr.dataset.sessionId);
    tr.remove();
    updateTotals(sessId);
    ensureEmptyRows(sessId);
    showSaved();
}

function copyLigne(btn) {
    const tr      = getRow(btn);
    if (isRowLocked(tr)) { alert('Mois de paie clôturé : modification impossible.'); return; }
    const tbody   = tr.closest('tbody');
    const sessId  = parseInt(tr.dataset.sessionId);
    const newTr   = buildEmptyRow(sessId);

    // Copier les valeurs
    const copyFields = ['.ik-depart', '.ik-motif', '.ik-ville'];
    copyFields.forEach(sel => {
        const src = tr.querySelector(sel);
        const dst = newTr.querySelector(sel);
        if (src && dst) dst.value = src.value;
    });

    // Copier immeuble
    ['.ik-imm-input','.ik-imm-id','.ik-imm-lat','.ik-imm-lng'].forEach(sel => {
        const src = tr.querySelector(sel);
        const dst = newTr.querySelector(sel);
        if (src && dst) dst.value = src.value;
    });

    newTr.dataset.dist     = tr.dataset.dist || '';
    newTr.dataset.departLat = tr.dataset.departLat || '';
    newTr.dataset.departLng = tr.dataset.departLng || '';
    newTr.classList.remove('ik-row-empty');
    tbody.insertBefore(newTr, tr.nextSibling);
    ensureEmptyRows(sessId);
}

function toggleSession(sessId, btn) {
    const el = document.getElementById('session-' + sessId);
    if (!el) return;
    const expanded = el.classList.toggle('expanded');
    if (btn) {
        btn.textContent = expanded ? 'Réduire' : 'Développer';
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

async function clotureSession(sessId) {
    if (!confirm('Envoyer le total des KM de cette session en paie ?')) return;
    try {
        const r = await fetch('api/ik_cloture_session.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: sessId})
        });
        const json = await r.json();
        if (!json.ok) { alert('Erreur : ' + (json.error || 'inconnue')); return; }
        // Feedback visuel court avant redirection vers la fiche salaire
        const btn = document.querySelector(`#session-${sessId} .v2-btn-blue-pastel`);
        if (btn) {
            btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ' + (json.total_km ?? '') + ' km · ' + (json.total_ik ?? '—') + ' € — redirection…';
            btn.disabled = true;
        }
        // Redirection auto vers le détail du salaire pour vérifier les champs remplis
        setTimeout(() => {
            if (json.redirect_url) window.location.href = json.redirect_url;
        }, 800);
    } catch(e) { alert('Erreur : ' + e.message); }
}

async function deleteSession(sessId) {
    if (isSessionLocked(sessId)) { alert('Mois de paie clôturé : modification impossible.'); return; }
    if (!confirm('Supprimer cette session et toutes ses lignes ?')) return;
    try {
        const r = await fetch('api/ik_delete_session.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: sessId})
        });
        const json = await r.json();
        if (!json.ok) {
            alert('Erreur : ' + (json.error || 'inconnue'));
            return;
        }
        window.location.reload();
    } catch (e) {
        alert('Erreur : ' + e.message);
    }
}

// ── Construction des options du select départ depuis IK_CONFIG ────────────────
function buildDepartOptions(selectedType = 'agence', selectedId = IK_CONFIG.agenceDefautId) {
    let html = '<optgroup label="Agences">';
    for (const ag of IK_CONFIG.agences) {
        const sel = (selectedType === 'agence' && ag.id === selectedId) ? 'selected' : '';
        const star = ag.id === IK_CONFIG.agenceDefautId ? '★ ' : '';
        html += `<option value="agence:${ag.id}"
            data-lat="${ag.lat ?? ''}" data-lng="${ag.lng ?? ''}"
            data-label="${escHtml(ag.label)}" ${sel}>${star}${escHtml(ag.nom)}</option>`;
    }
    html += '</optgroup>';
    if (IK_CONFIG.domicile) {
        const sel = selectedType === 'domicile' ? 'selected' : '';
        html += `<optgroup label="Domicile">
            <option value="domicile:0"
                data-lat="${IK_CONFIG.domicile.lat}" data-lng="${IK_CONFIG.domicile.lng}"
                data-label="${escHtml(IK_CONFIG.domicile.label)}" ${sel}>
                🏠 Mon domicile</option>
            </optgroup>`;
    }
    const selImm  = selectedType === 'immeuble' ? 'selected' : '';
    const selAddr = selectedType === 'adresse'  ? 'selected' : '';
    html += `<optgroup label="Autre">
        <option value="immeuble:0" data-lat="" data-lng="" data-label="" ${selImm}>🔍 Autre immeuble…</option>
        <option value="adresse:0"  data-lat="" data-lng="" data-label="" ${selAddr}>✍️ Adresse libre…</option>
        </optgroup>`;
    return html;
}

// ── Construction d'une ligne vide ──────────────────────────────────────────────
function buildEmptyRow(sessionId) {
    const tr = document.createElement('tr');
    tr.className = 'ik-row ik-row-empty';
    tr.dataset.sessionId = sessionId;
    tr.dataset.ligneId   = '0';
    tr.dataset.dist      = '';
    tr.dataset.departLat = IK_CONFIG.agenceDefautLat ?? '';
    tr.dataset.departLng = IK_CONFIG.agenceDefautLng ?? '';

    const motifOpts = ['<option value="">— Motif —</option>',
        ...IK_CONFIG.motifs.map(m => `<option value="${escHtml(m)}">${escHtml(m)}</option>`)
    ].join('');

    tr.innerHTML = `
        <td><div class="ik-date-wrap">
            <div class="ik-date-display empty" onclick="this.nextElementSibling.showPicker?.()||this.nextElementSibling.click()">jj/m/aa</div>
            <input type="date" class="ik-date-hidden ik-date" onchange="onDateChange(this)">
        </div></td>
        <td class="ik-depart-wrap">
            <select class="ik-depart-select ik-depart" onchange="onDepartChange(this)">
                ${buildDepartOptions()}
            </select>
            <div class="ik-depart-search" style="display:none;">
                <input type="text" class="ik-input ik-depart-imm-input" placeholder="Immeuble de départ…"
                    autocomplete="off" oninput="onDepartImmInput(this)"
                    onkeydown="onDepartImmKeydown(this,event)" onblur="onDepartImmBlur(this)">
                <input type="hidden" class="ik-depart-imm-lat" value="">
                <input type="hidden" class="ik-depart-imm-lng" value="">
                <div class="ik-depart-imm-results" style="display:none;"></div>
            </div>
            <div class="ik-depart-addr-wrap" style="display:none;">
                <input type="text" class="ik-input ik-depart-addr-input" placeholder="Saisir une adresse…" autocomplete="off">
                <input type="hidden" class="ik-depart-addr-lat" value="">
                <input type="hidden" class="ik-depart-addr-lng" value="">
            </div>
        </td>
        <td>
            <div class="ik-imm-wrap">
                <input type="text" class="ik-input ik-imm-input" placeholder="Rechercher immeuble…"
                    autocomplete="off" oninput="onImmInput(this)"
                    onkeydown="onImmKeydown(this,event)" onblur="onImmBlur(this)">
                <input type="hidden" class="ik-imm-id" value="0">
                <input type="hidden" class="ik-imm-lat" value="">
                <input type="hidden" class="ik-imm-lng" value="">
                <div class="ik-imm-results" style="display:none;"></div>
            </div>
        </td>
        <td><select class="ik-input ik-motif" onchange="onFieldChange(this)">${motifOpts}</select></td>
        <td class="ik-km-cell">
            <div class="ik-km-toggle-wrap">
                <button type="button" class="ik-km-toggle" onclick="toggleKm(this,'aller')" title="Aller">↗</button>
                <input type="number" step="0.1" min="0" class="ik-input ik-km-aller-input ik-km-input-val" placeholder="km" onchange="onKmChange(this,'aller')" style="width:52px;">
            </div>
        </td>
        <td class="ik-km-cell">
            <div class="ik-km-toggle-wrap">
                <button type="button" class="ik-km-toggle" onclick="toggleKm(this,'retour')" title="Retour">↙</button>
                <input type="number" step="0.1" min="0" class="ik-input ik-km-retour-input ik-km-input-val" placeholder="km" onchange="onKmChange(this,'retour')" style="width:52px;">
            </div>
        </td>
        <td><input type="text" class="ik-input ik-obs" placeholder="Observation…" onchange="onFieldChange(this)"></td>
        <td style="text-align:center;white-space:nowrap;">
            <button type="button" class="ik-action-btn ik-btn-copy" title="Copier" onclick="copyLigne(this)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
            <button type="button" class="ik-action-btn ik-btn-del" title="Supprimer" onclick="deleteLigne(this)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
        </td>`;
    return tr;
}

function addEmptyRow(sessionId) {
    if (isSessionLocked(sessionId)) { alert('Mois de paie clôturé : modification impossible.'); return; }
    const tbody = document.getElementById('tbody-' + sessionId);
    tbody.appendChild(buildEmptyRow(sessionId));
}

function ensureEmptyRows(sessionId) {
    if (isSessionLocked(sessionId)) return;
    const tbody = document.getElementById('tbody-' + sessionId);
    if (!tbody) return;
    const emptyRows = tbody.querySelectorAll('.ik-row-empty').length;
    for (let i = emptyRows; i < 3; i++) {
        tbody.appendChild(buildEmptyRow(sessionId));
    }
}

// ── Sessions existantes (pour vérification doublon) ───────────────────────────
const EXISTING_SESSIONS = <?=json_encode(array_column($sessions, 'mois_deplacements'))?>;

// ── Sessions map pour Actions ──────────────────────────────────────────────────
const SESSIONS_MAP = {};
<?php foreach ($sessions as $sA): ?>
SESSIONS_MAP[<?=json_encode($sA['mois_deplacements'])?>] = {
    id: <?=(int)$sA['id']?>,
    locked: <?=empty($sA['mois_paie_bloque'])?'false':'true'?>,
    label: <?=json_encode(moisLabel($sA['mois_deplacements']))?>
};
<?php endforeach; ?>

// ── Helpers pills ──────────────────────────────────────────────────────────────
function setNewMoisDep(val) {
    document.getElementById('new-mois-dep').value = val;
    // mois_paie = mois en cours par défaut
    const now = new Date();
    document.getElementById('new-mois-paie').value =
        now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
}

// ── Nouvelle session : sélection mois ─────────────────────────────────────────
function selectNewMoisPill(btn, closeDropdown) {
    if (btn.classList.contains('ik-pill-exists')) return;
    document.querySelectorAll('#ik-top-new .ik-pill:not(.ik-pill-more)').forEach(p => p.classList.remove('ik-pill-selected'));
    document.querySelectorAll('#ik-top-new .ik-dd-item').forEach(p => p.classList.remove('ik-pill-selected'));
    btn.classList.add('ik-pill-selected');
    setNewMoisDep(btn.dataset.val);
    if (closeDropdown) document.getElementById('new-mois-dropdown').classList.remove('open');
}

function toggleNewMoisDropdown(btn) {
    document.getElementById('new-mois-dropdown').classList.toggle('open');
}

// ── Actions : sélection année ──────────────────────────────────────────────────
let _actionAnnee = <?=$anneeEnCours?>;

function selectActionAnnee(btn, closeDropdown) {
    // Désélectionner tous les pills ANNÉE (wrap + dropdown)
    document.querySelectorAll('#action-annee-pills .ik-pill:not(.ik-pill-more)').forEach(p => p.classList.remove('ik-pill-selected'));
    document.querySelectorAll('#action-annee-dropdown .ik-dd-item').forEach(p => p.classList.remove('ik-pill-selected'));
    btn.classList.add('ik-pill-selected');
    _actionAnnee = parseInt(btn.dataset.val);
    if (closeDropdown) document.getElementById('action-annee-dropdown').classList.remove('open');
    renderActionMoisPills();
    hideActionButtons();
}

function toggleActionAnneeDropdown(btn) {
    document.getElementById('action-annee-dropdown').classList.toggle('open');
}

// ── Actions : rendu pills mois ─────────────────────────────────────────────────
const MOIS_COURTS = ['Jan','Fév','Mar','Avr','Mai','Jui','Jul','Aoû','Sep','Oct','Nov','Déc'];

function renderActionMoisPills() {
    const now = new Date();
    const curM = now.getMonth() + 1; // 1-12
    const wrap = document.getElementById('action-mois-pills');
    if (!wrap) return;

    // Mois visibles : M-1, M, M+1 pour l'année sélectionnée
    const visible = [];
    for (let d = -1; d <= 1; d++) {
        let mo = curM + d, yo = _actionAnnee;
        if (mo < 1) { mo += 12; yo--; }
        if (mo > 12) { mo -= 12; yo++; }
        visible.push(sprintf2('%d-%02d', yo, mo));
    }

    let html = '';
    visible.forEach(val => {
        const sess = SESSIONS_MAP[val];
        const cls = sess ? ' ik-pill-exists' : '';
        const mo = parseInt(val.split('-')[1]);
        html += `<button type="button" class="ik-pill${cls}" data-val="${val}" onclick="selectActionMoisPill(this)">${MOIS_COURTS[mo-1]}</button>`;
    });

    // "..." dropdown avec tous les mois de l'année sélectionnée non affichés
    let ddHtml = '';
    for (let mo2 = 12; mo2 >= 1; mo2--) {
        const val2 = sprintf2('%d-%02d', _actionAnnee, mo2);
        if (visible.includes(val2)) continue;
        const sess2 = SESSIONS_MAP[val2];
        const cls2 = sess2 ? ' ik-pill-exists' : '';
        ddHtml += `<button type="button" class="ik-dd-item${cls2}" data-val="${val2}" onclick="selectActionMoisPill(this,true)">${MOIS_COURTS[mo2-1]} ${_actionAnnee}</button>`;
    }
    html += `<div class="ik-pill-overflow">
        <button type="button" class="ik-pill ik-pill-more" onclick="toggleActionMoisDropdown(this)">…&nbsp;<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></button>
        <div class="ik-pill-dropdown" id="action-mois-dropdown">${ddHtml}</div>
    </div>`;
    wrap.innerHTML = html;
}

function toggleActionMoisDropdown(btn) {
    document.getElementById('action-mois-dropdown')?.classList.toggle('open');
}

function selectActionMoisPill(btn, closeDropdown) {
    const val = btn.dataset.val;
    const sess = SESSIONS_MAP[val];
    document.querySelectorAll('#action-mois-pills .ik-pill:not(.ik-pill-more), #action-mois-pills .ik-dd-item').forEach(p => p.classList.remove('ik-pill-selected'));
    btn.classList.add('ik-pill-selected');
    if (closeDropdown) document.getElementById('action-mois-dropdown')?.classList.remove('open');
    if (sess) showActionButtons(sess);
    else showNoSession();
}

function showActionButtons(sess) {
    window._actionSessId = sess.id;
    document.getElementById('action-buttons-zone').style.display = 'flex';
    document.getElementById('action-no-session').style.display = 'none';
    const lockedMsg = document.getElementById('action-locked-msg');
    const btnDel    = document.getElementById('action-btn-del');
    if (sess.locked) {
        lockedMsg.textContent = 'Votre calcul n\'est plus modifiable';
        lockedMsg.style.color = '#36577d';
        lockedMsg.style.display = 'inline';
        btnDel.style.display = 'none';
    } else {
        lockedMsg.textContent = 'Session en cours et active';
        lockedMsg.style.color = '#36577d';
        lockedMsg.style.display = 'inline';
        btnDel.style.display = 'flex';
    }
}

function showNoSession() {
    window._actionSessId = null;
    document.getElementById('action-buttons-zone').style.display = 'none';
    document.getElementById('action-no-session').style.display = 'inline';
}

function hideActionButtons() {
    window._actionSessId = null;
    document.getElementById('action-buttons-zone').style.display = 'none';
    document.getElementById('action-no-session').style.display = 'none';
    document.getElementById('action-locked-msg').style.display = 'none';
    document.getElementById('action-btn-del').style.display = 'flex';
}

function sprintf2(fmt, ...args) {
    let i = 0;
    return fmt.replace(/%d|%02d/g, m => m === '%02d' ? String(args[i++]).padStart(2,'0') : args[i++]);
}

// ── Init au chargement ─────────────────────────────────────────────────────────
(function initPills() {
    renderActionMoisPills();
    // Sélectionner le mois en cours dans "Nouvelle session" si disponible
    const def = document.getElementById('new-mois-dep')?.value;
    if (def) {
        const pill = document.querySelector(`#ik-top-new .ik-pill[data-val="${def}"]`);
        if (pill && !pill.classList.contains('ik-pill-exists')) pill.classList.add('ik-pill-selected');
    }
})();

// ── Créer une nouvelle session ─────────────────────────────────────────────────
async function createSession() {
    // Lire le mois directement depuis la pill sélectionnée (fiable)
    const selectedPill = document.querySelector('#ik-top-new .ik-pill.ik-pill-selected');
    const moisDep  = selectedPill ? selectedPill.dataset.val : document.getElementById('new-mois-dep').value;
    const now = new Date();
    const moisPaie = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    const msg      = document.getElementById('new-session-msg');
    if (!moisDep || !moisPaie) { alert('Veuillez renseigner les deux mois.'); return; }

    // Vérifier si une session existe déjà pour ce mois
    const moisDepFull = moisDep + '-01';
    const existe = EXISTING_SESSIONS.some(m => m && m.startsWith(moisDep));
    if (existe) {
        msg.style.display = 'inline-flex';
        setTimeout(() => msg.style.display = 'none', 3000);
        return;
    }
    msg.style.display = 'none';

    const r = await fetch('api/ik_save_session.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({mois_deplacements: moisDep, mois_paie: moisPaie, vehicule_info: '<?=addslashes($user['vehicule_nom'] ?? '')?>'})
    });
    const json = await r.json();
    if (json.ok) {
        window.location.reload();
    } else {
        alert('Erreur : ' + (json.error || 'inconnue'));
    }
}

// ── Modifier le mois de paie d'une session ────────────────────────────────────
function editSessionMois(sessId, currentMoisPaie) {
    // Pré-remplir les selects avec la valeur actuelle
    const [curY, curM] = (currentMoisPaie || '').split('-');
    const selMois  = document.getElementById('modal-mois-select');
    const selAnnee = document.getElementById('modal-annee-select');
    if (curM) selMois.value  = curM;
    if (curY) selAnnee.value = curY;
    document.getElementById('modal-sess-id').value = sessId;
    document.getElementById('mois-paie-modal').classList.add('open');
}

async function saveNewMoisPaie() {
    const sessId = document.getElementById('modal-sess-id').value;
    const mois   = document.getElementById('modal-mois-select').value;
    const annee  = document.getElementById('modal-annee-select').value;
    const newMois = annee + '-' + mois;
    const r = await fetch('api/ik_save_session.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: parseInt(sessId), mois_deplacements: '0000-00', mois_paie: newMois})
    });
    const json = await r.json();
    if (json.ok) window.location.reload();
}

// ── Export PDF ─────────────────────────────────────────────────────────────────
function exportPdf(sessId) {
    window.open('rh_exporter_ik_pdf.php?session_id=' + sessId, '_blank');
}

// ── Sauvegarde domicile ────────────────────────────────────────────────────────
async function saveDomicile() {
    const adresse = document.getElementById('dom-adresse').value.trim();
    const cp      = document.getElementById('dom-cp').value.trim();
    const ville   = document.getElementById('dom-ville').value.trim();
    if (!adresse || !ville) { alert('Adresse et ville obligatoires.'); return; }

    // Géocodage via l'API existante du projet
    let lat = null, lng = null;
    try {
        const geoR = await fetch('api/geocode_address.php?address=' + encodeURIComponent(adresse + ' ' + cp + ' ' + ville));
        const geo = await geoR.json();
        if (geo.lat) { lat = geo.lat; lng = geo.lng; }
    } catch(e) {}

    const r = await fetch('api/ik_save_domicile.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({adresse, code_postal: cp, ville, latitude: lat, longitude: lng})
    });
    const json = await r.json();
    if (json.ok) {
        document.getElementById('domicile-modal').classList.remove('open');
        showSaved();
        setTimeout(() => window.location.reload(), 700);
    }
}

// ── Sauvegarde auto ────────────────────────────────────────────────────────────
function autosaveAll() {
    document.querySelectorAll('.ik-table tbody tr.ik-row:not(.ik-row-empty)').forEach(tr => {
        if (isRowLocked(tr)) return;
        saveLigne(tr);
    });
}

autosaveInterval = setInterval(autosaveAll, 30000);

window.addEventListener('beforeunload', () => {
    autosaveAll();
});

// ── Initialisation : remplir tr.dataset.departLat/Lng depuis l'option sélectionnée ──
document.querySelectorAll('.ik-table tbody tr[data-session-id]').forEach(tr => {
    const sel = tr.querySelector('.ik-depart');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    if (opt?.dataset?.lat) { tr.dataset.departLat = opt.dataset.lat; tr.dataset.departLng = opt.dataset.lng; }
    // Fallback : immeuble départ ou adresse libre
    if (!tr.dataset.departLat) {
        const dLat = tr.querySelector('.ik-depart-imm-lat')?.value || tr.querySelector('.ik-depart-addr-lat')?.value;
        const dLng = tr.querySelector('.ik-depart-imm-lng')?.value || tr.querySelector('.ik-depart-addr-lng')?.value;
        if (dLat) { tr.dataset.departLat = dLat; tr.dataset.departLng = dLng; }
    }
});

// ── Initialisation : s'assurer que chaque session a 3 lignes vides ────────────
document.addEventListener('DOMContentLoaded', async () => {

    // ── Étape 0 : géocoder le domicile si adresse connue mais coords manquantes ─
    <?php if (!empty($domicile['adresse']) && empty($domicile['latitude'])): ?>
    {
        const geo = await geocodeSave('domicile', <?=(int)$userId?>);
        if (geo && IK_CONFIG.domicile === null) {
            IK_CONFIG.domicile = { lat: geo.lat, lng: geo.lng, label: 'Domicile – <?=addslashes(($domicile['adresse']??'').' '.($domicile['ville']??''))?>' };
            // Ajouter l'option domicile dans tous les selects
            document.querySelectorAll('.ik-depart').forEach(sel => {
                if (!sel.querySelector('option[value="domicile:0"]')) {
                    const og = document.createElement('optgroup'); og.label = 'Domicile';
                    const op = document.createElement('option'); op.value = 'domicile:0';
                    op.dataset.lat = geo.lat; op.dataset.lng = geo.lng;
                    op.dataset.label = IK_CONFIG.domicile.label; op.textContent = '🏠 Mon domicile';
                    og.appendChild(op); sel.insertBefore(og, sel.querySelector('optgroup[label="Autre"]'));
                }
            });
        }
    }
    <?php endif; ?>

    // ── Étape 1 : géocoder les agences sans coords ─────────────────────────────
    for (const ag of IK_CONFIG.agences) {
        if (!ag.lat || !ag.lng) {
            const geo = await geocodeSave('agence', ag.id);
            if (geo) {
                ag.lat = geo.lat; ag.lng = geo.lng;
                if (ag.id === IK_CONFIG.agenceDefautId) {
                    IK_CONFIG.agenceDefautLat = geo.lat;
                    IK_CONFIG.agenceDefautLng = geo.lng;
                }
                // Propager dans tous les selects du DOM
                document.querySelectorAll(`.ik-depart option[value="agence:${ag.id}"]`).forEach(opt => {
                    opt.dataset.lat = geo.lat; opt.dataset.lng = geo.lng;
                });
            }
        }
    }

    // ── Étape 2 : pour chaque ligne existante ──────────────────────────────────
    for (const tr of document.querySelectorAll('.ik-table tbody tr.ik-row')) {
        const sel = tr.querySelector('.ik-depart');
        if (!sel) continue;

        // Synchroniser departLat/Lng depuis le select (maintenant que les agences sont géocodées)
        const type = sel.value.split(':')[0];
        if (type === 'immeuble') {
            const dSearch = tr.querySelector('.ik-depart-search');
            tr.dataset.departLat = dSearch?.querySelector('.ik-depart-imm-lat')?.value || '';
            tr.dataset.departLng = dSearch?.querySelector('.ik-depart-imm-lng')?.value || '';
        } else {
            const opt = sel.options[sel.selectedIndex];
            tr.dataset.departLat = opt?.dataset?.lat || '';
            tr.dataset.departLng = opt?.dataset?.lng || '';
        }

        // Géocoder l'immeuble de destination si coords absentes
        const immId  = parseInt(tr.querySelector('.ik-imm-id')?.value) || 0;
        let   immLat = parseFloat(tr.querySelector('.ik-imm-lat')?.value) || 0;
        let   immLng = parseFloat(tr.querySelector('.ik-imm-lng')?.value) || 0;
        if (immId && (!immLat || !immLng)) {
            const geo = await geocodeSave('immeuble', immId);
            if (geo) {
                immLat = geo.lat; immLng = geo.lng;
                tr.querySelector('.ik-imm-lat').value = geo.lat;
                tr.querySelector('.ik-imm-lng').value = geo.lng;
            }
        }

        // Calculer la distance routière si les deux côtés sont disponibles
        const dLat = parseFloat(tr.dataset.departLat);
        const dLng = parseFloat(tr.dataset.departLng);
        if (dLat && dLng && immLat && immLng) {
            recalcDistance(tr, dLat, dLng, immLat, immLng);
        }
    }

    // ── Étape 3 : lignes vides ─────────────────────────────────────────────────
    document.querySelectorAll('[id^="tbody-"]').forEach(tbody => {
        const sessId = parseInt(tbody.id.replace('tbody-', ''));
        ensureEmptyRows(sessId);
    });

    disableLockedSessions();
});

// ── Bordure dorée : ligne en cours d'édition ──────────────────────────────────
document.addEventListener('focusin', e => {
    const tr = e.target.closest('.ik-table tbody tr');
    if (!tr) return;
    document.querySelectorAll('.ik-row-active').forEach(r => r.classList.remove('ik-row-active'));
    tr.classList.add('ik-row-active');
});
document.addEventListener('focusout', e => {
    setTimeout(() => {
        const focused = document.activeElement?.closest('.ik-table tbody tr');
        if (!focused) {
            document.querySelectorAll('.ik-row-active').forEach(r => r.classList.remove('ik-row-active'));
        }
    }, 100);
});

// ── Google Places Autocomplete sur champs adresse libre ───────────────────────
let googleReady = false;
function onGoogleReady() {
    googleReady = true;
    // Attacher Places à tous les champs adresse déjà visibles dans les lignes
    document.querySelectorAll('.ik-depart-addr-input').forEach(input => {
        if (!input._placesInit && input.closest('.ik-depart-addr-wrap')?.style.display !== 'none') {
            const tr = input.closest('tr');
            if (tr) attachPlacesAutocomplete(input, tr);
        }
    });
    // Réinitialiser places.js avec le service Google disponible
    if (typeof window.initPlacesAutocomplete === 'function') window.initPlacesAutocomplete();
}

// ── Modal Créer immeuble — mode Rapide / Pro ──────────────────────────────────
let ciMode = 'rapide';

function setCiMode(mode) {
    ciMode = mode;
    const blocPro    = document.getElementById('ci-bloc-pro');
    const blocRapide = document.getElementById('ci-bloc-rapide');
    const btnR = document.getElementById('ci-mode-rapide');
    const btnP = document.getElementById('ci-mode-pro');
    if (mode === 'pro') {
        blocPro.style.display    = 'block';
        blocRapide.style.display = 'none';
        btnP.style.background = '#3d5a80'; btnP.style.color = '#fff';
        btnR.style.background = 'transparent'; btnR.style.color = '#6e6b65';
    } else {
        blocPro.style.display    = 'none';
        blocRapide.style.display = 'grid';
        btnR.style.background = '#3d5a80'; btnR.style.color = '#fff';
        btnP.style.background = 'transparent'; btnP.style.color = '#6e6b65';
        onCiTypeChange(); // mettre à jour visibilité lots/syndic
    }
}

function onCiTypeChange() {
    if (ciMode !== 'rapide') return;
    const type = document.getElementById('ci-type').value;
    const showLots   = ['Immeuble','Appartement','Local commercial'].includes(type);
    const showSyndic = ['Immeuble','Appartement'].includes(type);
    document.getElementById('ci-row-lots').style.display   = showLots   ? '' : 'none';
    document.getElementById('ci-row-syndic').style.display = showSyndic ? '' : 'none';
}


// ── Modal édition immeuble (double-clic) ──────────────────────────────────────
async function openEditImmeuble(immId) {
    if (!immId || immId <= 0) return;
    const modal = document.getElementById('immeuble-edit-modal');
    document.getElementById('ei-id').value = immId;
    document.getElementById('ei-id-badge').textContent = '#' + immId;
    // Vider les champs
    ['ei-nom','ei-adresse','ei-cp','ei-ville','ei-commentaire','ei-syndic-actuel'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    ['ei-nb-lots','ei-nb-etages','ei-annee','ei-nb-parking'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    document.getElementById('ei-type').value = '';
    document.getElementById('ei-statut').value = 'actif';
    document.getElementById('ei-syndic-type').value = '';
    document.getElementById('ei-meta').textContent = 'Chargement…';
    modal.classList.add('open');

    try {
        const r = await fetch(`api/ik_get_immeuble.php?id=${immId}`);
        const d = await r.json();
        if (!d.ok) { document.getElementById('ei-meta').textContent = 'Erreur chargement'; return; }
        const im = d.immeuble;
        document.getElementById('ei-nom').value          = im.nom_immeuble   || '';
        document.getElementById('ei-adresse').value      = im.adresse_1      || '';
        document.getElementById('ei-cp').value           = im.code_postal    || '';
        document.getElementById('ei-ville').value        = im.ville          || '';
        document.getElementById('ei-type').value         = im.type_immeuble  || '';
        document.getElementById('ei-statut').value       = im.statut_immeuble|| 'actif';
        document.getElementById('ei-nb-lots').value      = im.nb_lots        || '';
        document.getElementById('ei-nb-etages').value    = im.nb_niveaux     || '';
        document.getElementById('ei-annee').value        = im.annee_construction || '';
        document.getElementById('ei-syndic-type').value  = im.mode_gestion   || '';
        document.getElementById('ei-syndic-actuel').value= im.syndic_actuel  || '';
        document.getElementById('ei-nb-parking').value   = im.nb_stationnements || '';
        document.getElementById('ei-commentaire').value  = im.commentaire    || '';
        document.getElementById('ei-lat').value          = im.latitude  ? parseFloat(im.latitude).toFixed(7)  : '';
        document.getElementById('ei-lng').value          = im.longitude ? parseFloat(im.longitude).toFixed(7) : '';
        document.getElementById('ei-meta').textContent   = `Créé le ${im.date_creation?.substring(0,10) || '—'} · Modifié le ${im.date_modification?.substring(0,10) || '—'}`;
    } catch(e) {
        document.getElementById('ei-meta').textContent = 'Erreur réseau';
    }
}

async function saveEditImmeuble() {
    const immId = parseInt(document.getElementById('ei-id').value);
    if (!immId) return;
    const btn = document.querySelector('#immeuble-edit-modal .v2-btn.primary');
    btn.disabled = true; btn.textContent = 'Enregistrement…';
    try {
        const resp = await fetch('api/ik_update_immeuble.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                id:                immId,
                nom_immeuble:      document.getElementById('ei-nom').value.trim(),
                adresse_1:         document.getElementById('ei-adresse').value.trim(),
                code_postal:       document.getElementById('ei-cp').value.trim(),
                ville:             document.getElementById('ei-ville').value.trim(),
                type_immeuble:     document.getElementById('ei-type').value,
                statut_immeuble:   document.getElementById('ei-statut').value,
                nb_lots:           parseInt(document.getElementById('ei-nb-lots').value)    || null,
                nb_niveaux:        parseInt(document.getElementById('ei-nb-etages').value)  || null,
                annee_construction:parseInt(document.getElementById('ei-annee').value)      || null,
                mode_gestion:      document.getElementById('ei-syndic-type').value          || null,
                syndic_actuel:     document.getElementById('ei-syndic-actuel').value.trim() || null,
                nb_stationnements: parseInt(document.getElementById('ei-nb-parking').value) || null,
                commentaire:       document.getElementById('ei-commentaire').value.trim()   || null,
                latitude:          parseFloat(document.getElementById('ei-lat').value)      || null,
                longitude:         parseFloat(document.getElementById('ei-lng').value)      || null,
            })
        });
        const data = await resp.json();
        if (!data.ok) { alert('Erreur : ' + (data.error || 'inconnue')); return; }
        document.getElementById('immeuble-edit-modal').classList.remove('open');
        showSaved();
        // Mettre à jour le label dans les champs de la page
        document.querySelectorAll('.ik-imm-id').forEach(hiddenId => {
            if (parseInt(hiddenId.value) === immId) {
                const nom = document.getElementById('ei-nom').value.trim();
                const adr = document.getElementById('ei-adresse').value.trim();
                const label = `${nom} - ${adr}`;
                const wrap = hiddenId.closest('.ik-imm-wrap');
                if (wrap) wrap.querySelector('.ik-imm-input').value = label;
            }
        });
    } catch(e) { alert('Erreur réseau'); }
    finally { btn.disabled = false; btn.textContent = 'Enregistrer'; }
}

function attachPlacesAutocomplete(input, tr) {
    if (!googleReady || !window.google?.maps?.places) return;
    input._placesInit = true;
    const ac = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: 'fr' },
        fields: ['geometry', 'formatted_address'],
    });
    ac.addListener('place_changed', () => {
        const place = ac.getPlace();
        if (!place.geometry) return;
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();
        const wrap = input.closest('.ik-depart-addr-wrap');
        wrap.querySelector('.ik-depart-addr-lat').value = lat;
        wrap.querySelector('.ik-depart-addr-lng').value = lng;
        tr.dataset.departLat = lat;
        tr.dataset.departLng = lng;
        // Mettre à jour l'option du select
        const sel = tr.querySelector('.ik-depart');
        if (sel) {
            const opt = sel.querySelector('option[value="adresse:0"]');
            if (opt) { opt.dataset.lat = lat; opt.dataset.lng = lng; opt.dataset.label = place.formatted_address; }
        }
        // Recalculer distance vers la destination si elle existe
        const immLat = parseFloat(tr.querySelector('.ik-imm-lat')?.value);
        const immLng = parseFloat(tr.querySelector('.ik-imm-lng')?.value);
        if (lat && lng && immLat && immLng) recalcDistance(tr, lat, lng, immLat, immLng);
        markRowActive(tr);
        scheduleSave(tr);
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
</script>
<script src="<?=h(app_url('/js/places.js'))?>"></script>
<script>
// places.js se déclenche au DOMContentLoaded — si déjà passé, on force l'init
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    if (typeof window.initPlacesAutocomplete === 'function') window.initPlacesAutocomplete();
}
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
