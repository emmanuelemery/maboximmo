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
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Indemnités Kilométriques – Ma Box RH</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme-rh.css">
    <?php include __DIR__ . '/inc/theme-init.php'; ?>
    <?php
    // Clé Google pour Places JS
    $googleConfigPaths = [
        __DIR__ . '/../u630423897/google_config.php',
        __DIR__ . '/google_config.php',
        __DIR__ . '/../google_config.php',
    ];
    foreach ($googleConfigPaths as $p) { if (file_exists($p)) { require_once $p; break; } }
    $gKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : ($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');
    ?>
    <?php if ($gKey): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?=htmlspecialchars($gKey)?>&libraries=places&callback=onGoogleReady" async defer></script>
    <?php endif; ?>
    <style>
        /* ── IK : layout & variables ───────────────────────────────────────── */
        .ik-page { display: flex; flex-direction: column; gap: 24px; }

        /* Bloc récap en haut */
        .ik-recap-bar {
            display: flex; gap: 16px; flex-wrap: wrap;
            background: var(--rh-card); border: 1px solid var(--rh-stroke);
            border-radius: 14px; padding: 16px 20px; align-items: center;
        }
        .ik-recap-stat {
            display: flex; flex-direction: column; gap: 2px;
            padding: 0 16px; border-right: 1px solid var(--rh-stroke);
        }
        .ik-recap-stat:last-child { border-right: none; }
        .ik-recap-stat label { font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--rh-muted); }
        .ik-recap-stat strong { font-size: 20px; font-weight: 800; color: var(--rh-ink); }
        .ik-recap-stat span   { font-size: 12px; color: var(--rh-muted); }
        .ik-paie-alert {
            margin-left: auto; background: var(--rh-accent);
            color: #fff; padding: 10px 18px; border-radius: 10px;
            font-weight: 700; font-size: 13px; line-height: 1.5;
        }

        /* Session card */
        .ik-session {
            background: var(--rh-card); border: 1px solid var(--rh-stroke);
            border-radius: 16px; overflow: hidden;
        }
        .ik-session-header {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; background: var(--rh-stroke-soft);
            border-bottom: 1px solid var(--rh-stroke); flex-wrap: wrap;
        }
        .ik-session-header h3 { margin: 0; font-size: 15px; font-weight: 700; flex: 1; }
        .ik-session-total {
            font-size: 22px; font-weight: 800; color: var(--rh-accent);
            min-width: 90px; text-align: right;
        }
        .ik-session-paie {
            font-size: 12px; color: var(--rh-muted); background: var(--rh-bg);
            padding: 4px 10px; border-radius: 20px; border: 1px solid var(--rh-stroke);
        }
        .ik-session-paie strong { color: var(--rh-ink); }

        /* Tableau */
        .ik-table-wrap {
            overflow-x: auto; position: relative;
        }
        .ik-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
            min-width: 900px;
        }
        .ik-table thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--rh-stroke-soft);
            padding: 10px 8px; text-align: left;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .4px; color: var(--rh-muted);
            border-bottom: 2px solid var(--rh-stroke);
            white-space: nowrap;
        }
        .ik-table td {
            padding: 6px 8px; border-bottom: 1px solid var(--rh-stroke);
            vertical-align: middle;
        }
        .ik-table tr:hover td { background: var(--rh-stroke-soft); }
        .ik-table tr.ik-row-empty td { opacity: .45; }

        /* Cellules de saisie */
        .ik-input {
            width: 100%; background: transparent; border: 1px solid transparent;
            color: var(--rh-ink); font-family: Manrope, sans-serif;
            font-size: 13px; padding: 4px 6px; border-radius: 6px;
            transition: border .15s, background .15s;
        }
        .ik-input:focus {
            outline: none; border-color: var(--rh-accent);
            background: var(--rh-bg);
        }
        .ik-input[type=date] { min-width: 120px; }
        .ik-input[type=number] { width: 70px; text-align: right; }
        select.ik-input { cursor: pointer; }

        /* Champ immeuble avec autocomplete */
        .ik-imm-wrap { position: relative; min-width: 170px; }
        .ik-imm-results {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 99;
            background: var(--rh-card); border: 1px solid var(--rh-stroke);
            border-radius: 10px; max-height: 220px; overflow-y: auto;
            box-shadow: 0 8px 24px rgba(0,0,0,.25); margin-top: 4px;
        }
        .ik-imm-item {
            padding: 8px 12px; cursor: pointer; border-bottom: 1px solid var(--rh-stroke);
            transition: background .1s;
        }
        .ik-imm-item:last-child { border-bottom: none; }
        .ik-imm-item:hover, .ik-imm-item-active { background: var(--rh-stroke-soft); }
        .ik-imm-item-active { border-left: 3px solid var(--rh-accent); padding-left: 9px; }
        .ik-imm-item strong { display: block; font-size: 13px; }
        .ik-imm-item small { color: var(--rh-muted); font-size: 11px; }

        /* Cases Aller / Retour cliquables */
        .ik-km-cell { min-width: 70px; }
        .ik-km-btn {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 58px; height: 30px;
            background: var(--rh-stroke-soft); border: 1px dashed var(--rh-stroke);
            border-radius: 6px; cursor: pointer; font-size: 12px; color: var(--rh-muted);
            transition: all .15s; user-select: none; gap: 4px;
            font-family: Manrope, sans-serif; font-weight: 600;
        }
        .ik-km-btn.active {
            background: rgba(var(--rh-accent-rgb, 102,217,255),.15);
            border-color: var(--rh-accent); color: var(--rh-accent); border-style: solid;
        }
        .ik-km-btn:hover { border-color: var(--rh-accent); color: var(--rh-accent); }
        .ik-km-manual { display: none; }
        .ik-km-manual.visible { display: block; }

        /* Total ligne */
        .ik-total-cell { font-weight: 700; color: var(--rh-accent); text-align: right; min-width: 60px; }

        /* Boutons d'action sur ligne */
        .ik-action-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 6px; border: 1px solid transparent;
            cursor: pointer; transition: all .15s; background: transparent;
            font-size: 14px;
        }
        .ik-action-btn:hover { transform: scale(1.15); }
        .ik-btn-copy:hover { background: rgba(16,185,129,.15); border-color: #10b981; }
        .ik-btn-del:hover  { background: rgba(239, 68, 68,.15); border-color: #ef4444; }

        /* Bouton ajouter session */
        .ik-add-session-form {
            background: var(--rh-card); border: 2px dashed var(--rh-stroke);
            border-radius: 16px; padding: 24px; text-align: center;
        }
        .ik-add-session-form h4 { margin: 0 0 16px; font-size: 15px; color: var(--rh-muted); }
        .ik-add-session-inputs {
            display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; align-items: flex-end;
        }
        .ik-add-session-inputs label { font-size: 12px; font-weight: 700; color: var(--rh-muted);
            display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px; }
        .ik-field { display: flex; flex-direction: column; }
        .ik-field input, .ik-field select {
            background: var(--rh-bg); border: 1px solid var(--rh-stroke);
            color: var(--rh-ink); padding: 8px 12px; border-radius: 8px;
            font-family: Manrope, sans-serif; font-size: 13px; min-width: 160px;
        }

        /* Bouton principal */
        .btn-rh {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 18px; border-radius: 9px; font-weight: 700;
            font-size: 13px; border: none; cursor: pointer;
            font-family: Manrope, sans-serif; transition: all .15s;
        }
        .btn-rh-primary { background: var(--rh-accent); color: #fff; }
        .btn-rh-primary:hover { opacity: .88; }
        .btn-rh-outline { background: transparent; border: 1px solid var(--rh-stroke);
            color: var(--rh-muted); }
        .btn-rh-outline:hover { border-color: var(--rh-accent); color: var(--rh-accent); }
        .btn-rh-pdf { background: #ef4444; color: #fff; }
        .btn-rh-pdf:hover { opacity: .88; }
        .btn-rh-danger { background: #ef4444; color: #fff; }
        .btn-rh-danger:hover { opacity: .88; }

        .ik-session-locked .ik-session-header { background: rgba(239, 68, 68, .08); }
        .ik-session-lock-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700; color: #ef4444;
            background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35);
        }
        .ik-session-locked .ik-table-wrap { opacity: .75; }
        .ik-session-locked .ik-input:disabled,
        .ik-session-locked select:disabled {
            opacity: .6; cursor: not-allowed;
        }
        .ik-session-locked .ik-action-btn:disabled,
        .ik-session-locked .btn-rh:disabled {
            opacity: .45; cursor: not-allowed; transform: none;
        }

        /* Indicateur de sauvegarde */
        .ik-save-indicator {
            position: fixed; bottom: 20px; right: 20px; z-index: 999;
            padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700;
            background: #10b981; color: #fff; opacity: 0;
            transition: opacity .3s; pointer-events: none;
        }
        .ik-save-indicator.visible { opacity: 1; }

        /* Modal domicile */
        .ik-modal-backdrop {
            display: none; position: fixed; inset: 0; z-index: 900;
            background: rgba(0,0,0,.55); align-items: center; justify-content: center;
        }
        .ik-modal-backdrop.open { display: flex; }
        .ik-modal {
            background: var(--rh-card); border-radius: 16px; padding: 28px;
            max-width: 480px; width: 100%; border: 1px solid var(--rh-stroke);
        }
        .ik-modal h3 { margin: 0 0 16px; font-size: 16px; }
        .ik-modal .form-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .ik-modal .form-row label { font-size: 12px; font-weight: 700; color: var(--rh-muted);
            text-transform: uppercase; letter-spacing: .4px; }
        .ik-modal .form-row input {
            background: var(--rh-bg); border: 1px solid var(--rh-stroke);
            color: var(--rh-ink); padding: 8px 12px; border-radius: 8px;
            font-family: Manrope, sans-serif; font-size: 13px;
        }
        .ik-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

        /* Champ départ select */
        .ik-depart-wrap { min-width: 160px; }
        .ik-depart-select {
            width: 100%; background: transparent; border: 1px solid transparent;
            color: var(--rh-ink); font-family: Manrope, sans-serif;
            font-size: 12px; padding: 4px 6px; border-radius: 6px; cursor: pointer;
        }
        .ik-depart-select:focus { outline: none; border-color: var(--rh-accent); background: var(--rh-bg); }
        /* Champ recherche immeuble de départ (affiché si "Autre immeuble" sélectionné) */
        .ik-depart-search { margin-top: 4px; position: relative; }
        .ik-depart-imm-results {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 99;
            background: var(--rh-card); border: 1px solid var(--rh-stroke);
            border-radius: 10px; max-height: 200px; overflow-y: auto;
            box-shadow: 0 8px 24px rgba(0,0,0,.25); margin-top: 4px;
        }
        .ik-depart-imm-item {
            padding: 7px 11px; cursor: pointer; border-bottom: 1px solid var(--rh-stroke);
            font-size: 12px;
        }
        .ik-depart-imm-item:last-child { border-bottom: none; }
        .ik-depart-imm-item:hover, .ik-depart-imm-item.active { background: var(--rh-stroke-soft); }
        .ik-depart-imm-item strong { display: block; font-size: 12px; }
        .ik-depart-imm-item small  { color: var(--rh-muted); font-size: 11px; }

        /* Section titre */
        .ik-section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .6px; color: var(--rh-muted); padding: 8px 20px 4px;
        }
        /* Ligne active : contour doré */
        .ik-row-active td {
            background: rgba(251,191,36,.07) !important;
            box-shadow: inset 0 -2px 0 #f59e0b, inset 0 2px 0 #f59e0b;
        }
        .ik-row-active td:first-child { box-shadow: inset 0 -2px 0 #f59e0b, inset 0 2px 0 #f59e0b, inset 2px 0 0 #f59e0b; }
        .ik-row-active td:last-child  { box-shadow: inset 0 -2px 0 #f59e0b, inset 0 2px 0 #f59e0b, inset -2px 0 0 #f59e0b; }
        /* Champ adresse libre Google Places */
        .ik-depart-addr-wrap { margin-top: 4px; position: relative; }
        .pac-container { z-index: 9999 !important; font-family: Manrope, sans-serif; font-size: 13px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/inc/rh_sidebar.php'; ?>
<main class="mbi-main">
    <div class="mbi-topbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:22px;">🚗</span>
            <div>
                <h1 style="margin:0;font-size:18px;font-weight:800;">Indemnités Kilométriques</h1>
                <p style="margin:0;font-size:13px;color:var(--rh-muted);">
                    <?=h($user['prenom'].' '.$user['nom'])?> &nbsp;·&nbsp;
                    <?=h($user['nom_agence'] ?? 'Agence non définie')?>
                    <?php if ($user['vehicule_nom']): ?>
                    &nbsp;·&nbsp; 🚗 <?=h($user['vehicule_nom'])?>
                    <?php if ($user['vehicule_puissance_fiscale']): ?>
                    (<?=h((string)$user['vehicule_puissance_fiscale'])?>CV)
                    <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <?php if (!empty($domicile['adresse'])): ?>
            <span style="font-size:12px;color:var(--rh-muted);display:flex;align-items:center;gap:8px;">
                🏠 <span style="color:var(--rh-ink);font-weight:600;"><?=h($domicile['adresse'])?>, <?=h($domicile['code_postal'])?> <?=h($domicile['ville'])?></span>
                <?php if (empty($domicile['latitude'])): ?>
                <span style="color:#f59e0b;font-size:11px;">⚠️ Coords manquantes</span>
                <?php endif; ?>
                <button class="btn-rh" style="padding:3px 10px;font-size:11px;background:transparent;border:1px solid var(--rh-stroke);color:var(--rh-muted);"
                    onclick="document.getElementById('domicile-modal').classList.add('open')">✏️ Modifier</button>
            </span>
            <?php else: ?>
            <button class="btn-rh" style="background:#38bdf8;color:#fff;font-weight:800;font-size:13px;padding:10px 20px;border:none;border-radius:10px;box-shadow:0 2px 12px rgba(56,189,248,.4);"
                onclick="document.getElementById('domicile-modal').classList.add('open')">
                🏠 Enregistrer mon domicile
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="mbi-content ik-page">

        <!-- ── Récapitulatif global ─────────────────────────────────────────── -->
        <?php
        $totalGlobalKm = 0;
        $totalGlobalDeps = 0;
        foreach ($sessions as $s) {
            $totalGlobalKm  += (float)($s['total_km'] ?? 0);
            $totalGlobalDeps += (int)($s['nb_lignes'] ?? 0);
        }
        ?>
        <div class="ik-recap-bar" id="ik-recap-global">
            <div class="ik-recap-stat">
                <label>Sessions</label>
                <strong><?=count($sessions)?></strong>
                <span>mois de déplacements</span>
            </div>
            <div class="ik-recap-stat">
                <label>Déplacements</label>
                <strong id="stat-nb-deps"><?=$totalGlobalDeps?></strong>
                <span>lignes saisies</span>
            </div>
            <div class="ik-recap-stat">
                <label>Total KM</label>
                <strong id="stat-total-km"><?=number_format($totalGlobalKm,1,',',' ')?></strong>
                <span>kilomètres</span>
            </div>
            <div class="ik-paie-alert" id="paie-alert-global">
                <?php if (count($sessions) === 1): ?>
                📋 À intégrer dans la paie de : <strong><?=moisLabel($sessions[0]['mois_paie'])?></strong><br>
                Reporter dans le champ paie <em>nb de KM</em> : <strong><?=number_format($totalGlobalKm,1,',',' ')?> km</strong>
                <?php elseif (count($sessions) > 1): ?>
                📋 <?=count($sessions)?> sessions · voir détail ci-dessous
                <?php else: ?>
                📋 Aucune session IK pour le moment
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Nouvelle session ────────────────────────────────────────────── -->
        <div class="ik-add-session-form">
            <h4>➕ Démarrer un nouveau mois de déplacements</h4>
            <div class="ik-add-session-inputs">
                <div class="ik-field">
                    <label>Mois des déplacements</label>
                    <input type="month" id="new-mois-dep" value="<?=h($moisDefaut)?>">
                </div>
                <div class="ik-field">
                    <label>Mois de paie d'intégration</label>
                    <input type="month" id="new-mois-paie" value="<?=h($moisPaieDefaut)?>">
                </div>
                <div class="ik-field">
                    <label>Véhicule</label>
                    <input type="text" id="new-vehicule"
                        value="<?=h($user['vehicule_nom'] ?? '')?>"
                        placeholder="Ex: Renault Clio 5CV">
                </div>
                <button class="btn-rh btn-rh-primary" onclick="createSession()">
                    🚀 Créer cette session
                </button>
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
                <span style="font-size:20px;">🗓️</span>
                <h3>Déplacements de <?=moisLabel($sess['mois_deplacements'])?></h3>
                <?php if ($sessLocked): ?>
                <span class="ik-session-lock-badge">🔒 Mois de paie clôturé</span>
                <?php endif; ?>
                <span class="ik-session-paie">
                    Intégration paie : <strong><?=moisLabel($sess['mois_paie'])?></strong>
                    &nbsp;—&nbsp; Reporter champ <em>nb de KM</em> : <strong class="sess-total-km"><?=number_format($totalKm,1,',',' ')?> km</strong>
                </span>
                <span class="ik-session-total"><span class="sess-total-km"><?=number_format($totalKm,1,',',' ')?></span> km</span>
                <div style="display:flex;gap:8px;">
                    <button class="btn-rh btn-rh-outline keep-active ik-session-toggle" onclick="toggleSession(<?=$sessId?>, this)" aria-expanded="false">
                        ➕ Développer
                    </button>
                    <button class="btn-rh btn-rh-pdf keep-active" onclick="exportPdf(<?=$sessId?>)">
                        📄 PDF
                    </button>
                    <?php if ($sessLocked): ?>
                    <button class="btn-rh btn-rh-outline" disabled title="Mois clôturé par l'admin">
                        ✏️ Mois paie
                    </button>
                    <?php else: ?>
                    <button class="btn-rh btn-rh-outline" onclick="editSessionMois(<?=$sessId?>, '<?=h($sess['mois_paie'])?>')">
                        ✏️ Mois paie
                    </button>
                    <button class="btn-rh btn-rh-danger" onclick="deleteSession(<?=$sessId?>)">
                        🗑 Supprimer
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bloc paie très visible -->
            <div style="background:rgba(var(--rh-accent-rgb,102,217,255),.08);border-bottom:1px solid var(--rh-stroke);padding:10px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span style="font-size:13px;font-weight:700;color:var(--rh-accent);">
                    📋 À intégrer dans la paie de : <?=moisLabel($sess['mois_paie'])?>
                </span>
                <span style="font-size:13px;font-weight:700;color:var(--rh-ink);">
                    Reporter dans le champ paie <em>nb de KM</em> =
                    <strong class="sess-total-km" style="color:var(--rh-accent)"><?=number_format($totalKm,1,',',' ')?> km</strong>
                </span>
                <?php if ($sessLocked): ?>
                <span style="font-size:12px;font-weight:700;color:#ef4444;">
                    🔒 Mois clôturé par l'admin — modifications désactivées
                </span>
                <?php endif; ?>
            </div>

            <div class="ik-session-body">
            <div class="ik-table-wrap">
                <table class="ik-table" id="table-<?=$sessId?>">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th style="min-width:150px;">Point de départ</th>
                            <th style="min-width:170px;">Immeuble / Destination</th>
                            <th style="width:100px;">Ville</th>
                            <th style="width:140px;">Motif</th>
                            <th style="width:80px;text-align:center;">Aller</th>
                            <th style="width:80px;text-align:center;">Retour</th>
                            <th style="width:70px;text-align:right;">Total</th>
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
                                <input type="date" class="ik-input ik-date" value="<?=h($ligne['date_deplacement'] ?? '')?>"
                                    onchange="onFieldChange(this)">
                            </td>
                            <td class="ik-depart-wrap">
                                <?php
                                $isImmDepart = ($ligne['type_depart'] === 'immeuble');
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
                                    <option value="immeuble:0"
                                        data-lat="<?=h((string)($ligne['depart_lat']??''))?>"
                                        data-lng="<?=h((string)($ligne['depart_lng']??''))?>"
                                        data-label="<?=h($ligne['depart_label']??'')?>"
                                        <?=$isImmDepart?'selected':''?>>
                                        🔍 Autre immeuble…
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
                                <?php $isAdresseDepart = ($ligne['type_depart'] === 'adresse'); ?>
                                <div class="ik-depart-search" style="<?=$isImmDepart?'display:block':'display:none'?>">
                                    <input type="text" class="ik-input ik-depart-imm-input"
                                        value="<?=h($isImmDepart ? ($ligne['depart_label']??'') : '')?>"
                                        placeholder="Immeuble de départ…"
                                        autocomplete="off"
                                        oninput="onDepartImmInput(this)"
                                        onkeydown="onDepartImmKeydown(this,event)"
                                        onblur="onDepartImmBlur(this)">
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
                                        onblur="onImmBlur(this)">
                                    <input type="hidden" class="ik-imm-id" value="<?=(int)($ligne['id_immeuble'] ?? 0)?>">
                                    <input type="hidden" class="ik-imm-lat" value="<?=h((string)($ligne['destination_lat'] ?? ''))?>">
                                    <input type="hidden" class="ik-imm-lng" value="<?=h((string)($ligne['destination_lng'] ?? ''))?>">
                                    <div class="ik-imm-results" style="display:none;"></div>
                                </div>
                            </td>
                            <td>
                                <input type="text" class="ik-input ik-ville" value="<?=h($ligne['destination_ville'] ?? '')?>"
                                    placeholder="Ville" onchange="onFieldChange(this)">
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
                            <td class="ik-km-cell">
                                <button type="button"
                                    class="ik-km-btn ik-aller<?=$ligne['km_aller']!==null?' active':''?>"
                                    onclick="toggleKm(this,'aller')"
                                    title="Cliquer pour remplir / re-cliquer pour retirer">
                                    <?=$ligne['km_aller']!==null ? number_format((float)$ligne['km_aller'],1,',',' ') : '↗'?>
                                </button>
                                <input type="number" step="0.1" min="0"
                                    class="ik-input ik-km-manual ik-km-aller-input<?=$ligne['km_aller']!==null?' visible':''?>"
                                    value="<?=$ligne['km_aller']!==null ? number_format((float)$ligne['km_aller'],1,'.',''): ''?>"
                                    onchange="onKmChange(this,'aller')" style="width:62px;">
                            </td>
                            <td class="ik-km-cell">
                                <button type="button"
                                    class="ik-km-btn ik-retour<?=$ligne['km_retour']!==null?' active':''?>"
                                    onclick="toggleKm(this,'retour')"
                                    title="Cliquer pour remplir / re-cliquer pour retirer">
                                    <?=$ligne['km_retour']!==null ? number_format((float)$ligne['km_retour'],1,',',' ') : '↙'?>
                                </button>
                                <input type="number" step="0.1" min="0"
                                    class="ik-input ik-km-manual ik-km-retour-input<?=$ligne['km_retour']!==null?' visible':''?>"
                                    value="<?=$ligne['km_retour']!==null ? number_format((float)$ligne['km_retour'],1,'.',''): ''?>"
                                    onchange="onKmChange(this,'retour')" style="width:62px;">
                            </td>
                            <td class="ik-total-cell">
                                <span class="ik-total-ligne"><?=$kmTotal>0 ? number_format($kmTotal,1,',',' ') : '—'?></span>
                            </td>
                            <td>
                                <input type="text" class="ik-input ik-obs"
                                    value="<?=h($ligne['observation'] ?? '')?>"
                                    placeholder="Observation…"
                                    onchange="onFieldChange(this)">
                            </td>
                            <td style="text-align:center;white-space:nowrap;">
                                <?php if ($sessLocked): ?>
                                <span style="font-size:12px;color:var(--rh-muted);">🔒</span>
                                <?php else: ?>
                                <button type="button" class="ik-action-btn ik-btn-copy" title="Copier cette ligne"
                                    onclick="copyLigne(this)">📋</button>
                                <button type="button" class="ik-action-btn ik-btn-del" title="Supprimer"
                                    onclick="deleteLigne(this)">🗑</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Les lignes vides sont ajoutées en JS via ensureEmptyRows -->
                    </tbody>
                </table>
            </div>
            <div style="padding:10px 20px;display:flex;gap:10px;align-items:center;">
                <?php if ($sessLocked): ?>
                <button class="btn-rh btn-rh-outline" disabled title="Mois clôturé par l'admin">+ Ajouter une ligne</button>
                <?php else: ?>
                <button class="btn-rh btn-rh-outline" onclick="addEmptyRow(<?=$sessId?>)">+ Ajouter une ligne</button>
                <?php endif; ?>
                <span class="ik-save-status" style="font-size:12px;color:var(--rh-muted);"></span>
            </div>
            </div>
        </div>
        <?php endforeach; ?>

        

    </div><!-- mbi-content -->
</main>

<!-- ── Indicateur de sauvegarde ─────────────────────────────────────────────── -->
<div class="ik-save-indicator" id="save-indicator">✅ Enregistré</div>

<!-- ── Modal domicile ───────────────────────────────────────────────────────── -->
<div class="ik-modal-backdrop" id="domicile-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal">
        <h3>🏠 Mon adresse de domicile</h3>
        <p style="font-size:13px;color:var(--rh-muted);margin:0 0 16px;">
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
        <p style="font-size:12px;color:var(--rh-muted);margin:8px 0 0;">
            Les coordonnées GPS seront calculées automatiquement à la sauvegarde via l'API de géocodage.
        </p>
        <div class="ik-modal-actions">
            <button class="btn-rh btn-rh-outline" onclick="document.getElementById('domicile-modal').classList.remove('open')">Annuler</button>
            <button class="btn-rh btn-rh-primary" onclick="saveDomicile()">💾 Enregistrer</button>
        </div>
    </div>
</div>

<!-- ── Modal changement mois de paie ────────────────────────────────────────── -->
<div class="ik-modal-backdrop" id="mois-paie-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal" style="max-width:400px;">
        <h3>📅 Changer le mois de paie</h3>
        <p style="font-size:13px;color:var(--rh-muted);margin:0 0 20px;">
            Sélectionnez le mois et l'année dans lesquels ces IK doivent être intégrées en paie.
        </p>
        <div style="display:flex;gap:12px;align-items:flex-end;">
            <div class="form-row" style="flex:1;">
                <label>Mois</label>
                <select id="modal-mois-select" style="background:var(--rh-bg);border:1px solid var(--rh-stroke);color:var(--rh-ink);padding:8px 12px;border-radius:8px;font-family:Manrope,sans-serif;font-size:14px;width:100%;">
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
                <select id="modal-annee-select" style="background:var(--rh-bg);border:1px solid var(--rh-stroke);color:var(--rh-ink);padding:8px 12px;border-radius:8px;font-family:Manrope,sans-serif;font-size:14px;width:100%;">
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
            <button class="btn-rh btn-rh-outline" onclick="document.getElementById('mois-paie-modal').classList.remove('open')">Annuler</button>
            <button class="btn-rh btn-rh-primary" onclick="saveNewMoisPaie()">✅ Valider</button>
        </div>
    </div>
</div>

<!-- ── Modal création immeuble ───────────────────────────────────────────────── -->
<div class="ik-modal-backdrop" id="immeuble-create-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="ik-modal" style="max-width:560px;">
        <h3>🏢 Créer un nouvel immeuble</h3>
        <p style="font-size:12px;color:var(--rh-muted);margin:0 0 16px;">
            L'immeuble sera ajouté à la base de données et sélectionné automatiquement.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="form-row" style="grid-column:1/-1;">
                <label>Nom de l'immeuble *</label>
                <input type="text" id="ci-nom" placeholder="Ex : Le Chamois">
            </div>
            <div class="form-row" style="grid-column:1/-1;">
                <label>Adresse *</label>
                <input type="text" id="ci-adresse" placeholder="Ex : 12 rue de la Paix">
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
                <select id="ci-type" style="background:var(--rh-bg);border:1px solid var(--rh-stroke);color:var(--rh-ink);padding:8px 12px;border-radius:8px;font-family:Manrope,sans-serif;font-size:13px;">
                    <option value="">— Choisir —</option>
                    <option value="Immeuble">Immeuble</option>
                    <option value="Appartement">Appartement</option>
                    <option value="Maison">Maison</option>
                    <option value="Local commercial">Local commercial</option>
                    <option value="Garage">Garage</option>
                </select>
            </div>
            <div class="form-row">
                <label>Agence</label>
                <select id="ci-agence" style="background:var(--rh-bg);border:1px solid var(--rh-stroke);color:var(--rh-ink);padding:8px 12px;border-radius:8px;font-family:Manrope,sans-serif;font-size:13px;">
                    <?php foreach ($agences as $ag): ?>
                    <option value="<?=(int)$ag['id']?>"<?=(int)$ag['id']===(int)$user['id_agence']?' selected':''?>>
                        <?=h($ag['nom_agence'])?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p style="font-size:11px;color:var(--rh-muted);margin:12px 0 0;">
            👤 Gestionnaire : <strong><?=h($user['prenom'].' '.$user['nom'])?></strong> &nbsp;·&nbsp;
            Société : <strong><?=h($user['nom_agence'] ?? '')?></strong>
        </p>
        <input type="hidden" id="ci-lat" value="">
        <input type="hidden" id="ci-lng" value="">
        <input type="hidden" id="ci-callback" value=""> <!-- 'dest' ou 'depart' -->
        <input type="hidden" id="ci-tr-session" value="">
        <div class="ik-modal-actions">
            <button class="btn-rh btn-rh-outline" onclick="document.getElementById('immeuble-create-modal').classList.remove('open')">Annuler</button>
            <button class="btn-rh btn-rh-primary" onclick="createImmeuble()">🏢 Créer et sélectionner</button>
        </div>
    </div>
</div>

<script>
// ── Données passées depuis PHP ────────────────────────────────────────────────
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
        d.id_agence_depart = (type === 'agence' && idVal) ? parseInt(idVal) : null;

        if (type === 'immeuble') {
            const dSearch = tr.querySelector('.ik-depart-search');
            d.depart_lat   = parseFloat(dSearch?.querySelector('.ik-depart-imm-lat')?.value) || null;
            d.depart_lng   = parseFloat(dSearch?.querySelector('.ik-depart-imm-lng')?.value) || null;
            d.depart_label = dSearch?.querySelector('.ik-depart-imm-input')?.value || '';
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
    const allerBtn = tr.querySelector('.ik-aller');
    const retourBtn = tr.querySelector('.ik-retour');
    const allerInput = tr.querySelector('.ik-km-aller-input');
    const retourInput = tr.querySelector('.ik-km-retour-input');

    d.km_aller  = allerBtn?.classList.contains('active') ? (parseFloat(allerInput?.value) || null) : null;
    d.km_retour = retourBtn?.classList.contains('active') ? (parseFloat(retourInput?.value) || null) : null;

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
function onFieldChange(el) {
    const tr = getRow(el);
    if (isRowLocked(tr)) return;
    tr.classList.remove('ik-row-empty');
    scheduleSave(tr);
}

function onDepartChange(el) {
    const tr   = getRow(el);
    if (isRowLocked(tr)) return;
    const opt  = el.options[el.selectedIndex];
    const val  = el.value; // "agence:3", "domicile:0", "immeuble:0"
    const type = val.split(':')[0];
    const searchDiv = tr.querySelector('.ik-depart-search');

    const addrWrap = tr.querySelector('.ik-depart-addr-wrap');

    // Cacher les deux zones par défaut
    if (searchDiv) searchDiv.style.display = 'none';
    if (addrWrap)  addrWrap.style.display  = 'none';

    if (type === 'immeuble') {
        if (searchDiv) searchDiv.style.display = 'block';
        tr.dataset.departLat = opt?.dataset?.lat || '';
        tr.dataset.departLng = opt?.dataset?.lng || '';
    } else if (type === 'adresse') {
        if (addrWrap) {
            addrWrap.style.display = 'block';
            const addrInput = addrWrap.querySelector('.ik-depart-addr-input');
            tr.dataset.departLat = addrWrap.querySelector('.ik-depart-addr-lat')?.value || '';
            tr.dataset.departLng = addrWrap.querySelector('.ik-depart-addr-lng')?.value || '';
            // Attacher Google Places si disponible et pas encore fait
            if (addrInput && !addrInput._placesInit) attachPlacesAutocomplete(addrInput, tr);
        }
    } else {
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
    tr.classList.remove('ik-row-empty');
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
        results.innerHTML += `<div class="ik-depart-imm-item" style="border-top:2px solid var(--rh-stroke);color:var(--rh-accent);font-weight:700;"
            onmousedown="event.preventDefault()"
            onclick="openCreateImmeuble('depart', '${escHtml(q)}', '', '${sessId}')">
            ➕ Créer « ${escHtml(q)} » dans la base…</div>`;
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
function openCreateImmeuble(callback, searchQuery, address, sessId) {
    // Essayer de décomposer "12 rue de la Paix 69000 Lyon" → adresse + cp + ville
    const modal = document.getElementById('immeuble-create-modal');
    const nomDefault = searchQuery.replace(/\s+\d{5}\s+\S.*$/, '').trim() || searchQuery;
    document.getElementById('ci-nom').value      = nomDefault;
    document.getElementById('ci-adresse').value  = address || searchQuery;
    document.getElementById('ci-cp').value       = '';
    document.getElementById('ci-ville').value    = '';
    document.getElementById('ci-lat').value      = '';
    document.getElementById('ci-lng').value      = '';
    document.getElementById('ci-callback').value = callback;   // 'dest' ou 'depart'
    document.getElementById('ci-tr-session').value = sessId;

    // Essayer d'extraire CP + ville depuis la query
    const cpMatch = searchQuery.match(/\b(\d{5})\b\s*(.*)/);
    if (cpMatch) {
        document.getElementById('ci-cp').value    = cpMatch[1];
        document.getElementById('ci-ville').value = cpMatch[2].trim();
    }

    modal.classList.add('open');
    setTimeout(() => document.getElementById('ci-nom').focus(), 50);
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

    if (!nom || !adresse) { alert('Le nom et l\'adresse sont obligatoires.'); return; }

    const btn = document.querySelector('#immeuble-create-modal .btn-rh-primary');
    btn.disabled = true; btn.textContent = '⏳ Création…';

    try {
        const resp = await fetch('api/ik_create_immeuble.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nom_immeuble: nom, adresse_1: adresse, code_postal: cp, ville, type_immeuble: type, id_agence: agence })
        });
        const data = await resp.json();
        if (!data.ok) { alert('Erreur : ' + (data.error || 'inconnue')); return; }

        document.getElementById('immeuble-create-modal').classList.remove('open');

        // Trouver la ligne cible via sessId
        const tr = document.querySelector(`tr[data-session-id="${sessId}"]`) ||
                   document.querySelector('tr.ik-row-active') ||
                   document.querySelector('tr[data-row-id]');
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
                wrap.querySelector('.ik-dest-ville').value = ville || '';
                wrap.querySelector('.ik-imm-results').style.display = 'none';
                tr.dataset.destLat = lat || ''; tr.dataset.destLng = lng || '';
            }
        } else { // 'depart'
            const wrap = tr.querySelector('.ik-depart-search');
            if (wrap) {
                wrap.querySelector('.ik-depart-imm-input').value = `${nom} - ${adresse}`;
                wrap.querySelector('.ik-depart-imm-lat').value   = lat || '';
                wrap.querySelector('.ik-depart-imm-lng').value   = lng || '';
                wrap.querySelector('.ik-depart-imm-results').style.display = 'none';
                tr.dataset.departLat = lat || ''; tr.dataset.departLng = lng || '';
            }
        }

        tr.classList.remove('ik-row-empty');
        scheduleSave(tr);

        // Recalculer la distance si les deux points sont connus
        const dLat = parseFloat(tr.dataset.departLat), dLng = parseFloat(tr.dataset.departLng);
        const aLat = parseFloat(tr.dataset.destLat),   aLng = parseFloat(tr.dataset.destLng);
        if (dLat && dLng && aLat && aLng) recalcDistance(tr, dLat, dLng, aLat, aLng);

    } finally {
        btn.disabled = false; btn.textContent = '🏢 Créer et sélectionner';
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

    // Mettre à jour le dataset du tr et l'option du select
    tr.dataset.departLat = lat;
    tr.dataset.departLng = lng;
    const sel = tr.querySelector('.ik-depart');
    if (sel) {
        const opt = sel.querySelector('option[value="immeuble:0"]');
        if (opt) { opt.dataset.lat = lat; opt.dataset.lng = lng; opt.dataset.label = label; }
    }

    // Recalculer distance vers la destination si elle existe
    const immLat = parseFloat(tr.querySelector('.ik-imm-lat')?.value);
    const immLng = parseFloat(tr.querySelector('.ik-imm-lng')?.value);
    if (lat && lng && immLat && immLng) {
        recalcDistance(tr, parseFloat(lat), parseFloat(lng), immLat, immLng);
    }
    tr.classList.remove('ik-row-empty');
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
        const sessId = tr?.dataset?.sessionId || '';
        results.innerHTML = items.map(it => `
            <div class="ik-imm-item"
                data-id="${it.id}"
                data-label="${escHtml(it.nom_immeuble)} - ${escHtml(it.adresse)}"
                data-ville="${escHtml(it.ville)}"
                data-lat="${it.latitude||''}" data-lng="${it.longitude||''}"
                onmousedown="event.preventDefault()"
                onclick="selectImmeuble(this)">
                <strong>${escHtml(it.nom_immeuble)}</strong>
                <small>${escHtml(it.adresse)} ${escHtml(it.code_postal)} ${escHtml(it.ville)}</small>
            </div>`).join('');
        // Bouton "Créer cet immeuble"
        results.innerHTML += `<div class="ik-imm-item" style="border-top:2px solid var(--rh-stroke);color:var(--rh-accent);font-weight:700;"
            onmousedown="event.preventDefault()"
            onclick="openCreateImmeuble('dest', '${escHtml(q)}', '', '${sessId}')">
            ➕ Créer « ${escHtml(q)} » dans la base…</div>`;
        results.style.display = 'block';
    }, 280);
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

    tr.classList.remove('ik-row-empty');

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
    const dLat = parseFloat(tr.dataset.departLat);
    const dLng = parseFloat(tr.dataset.departLng);
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
    const allerBtn    = tr.querySelector('.ik-aller');
    const retourBtn   = tr.querySelector('.ik-retour');
    const allerInput  = tr.querySelector('.ik-km-aller-input');
    const retourInput = tr.querySelector('.ik-km-retour-input');
    if (allerBtn?.classList.contains('active')) {
        allerInput.value = dist;
        allerBtn.textContent = formatKm(dist);
    }
    if (retourBtn?.classList.contains('active')) {
        retourInput.value = dist;
        retourBtn.textContent = formatKm(dist);
    }
    if (allerBtn?.classList.contains('active') || retourBtn?.classList.contains('active')) {
        updateTotalLigne(tr);
    }
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
    const input   = tr.querySelector(isAller ? '.ik-km-aller-input' : '.ik-km-retour-input');

    if (btn.classList.contains('active')) {
        // Désactiver
        btn.classList.remove('active');
        btn.textContent = isAller ? '↗' : '↙';
        input.value = '';
        input.classList.remove('visible');
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
    input.value = dist > 0 ? dist : '';
    input.classList.add('visible');
    btn.textContent = dist > 0 ? formatKm(dist) : (isAller ? '↗' : '↙');
    updateTotalLigne(tr);
    scheduleSave(tr);
}

function onKmChange(input, direction) {
    const tr  = getRow(input);
    if (isRowLocked(tr)) return;
    const val = parseFloat(input.value) || 0;
    const btn = tr.querySelector(direction === 'aller' ? '.ik-aller' : '.ik-retour');
    btn.textContent = val > 0 ? formatKm(val) : (direction === 'aller' ? '↗' : '↙');
    updateTotalLigne(tr);
    scheduleSave(tr);
}

function updateTotalLigne(tr) {
    const allerInput = tr.querySelector('.ik-km-aller-input');
    const retourInput= tr.querySelector('.ik-km-retour-input');
    const allerBtn   = tr.querySelector('.ik-aller');
    const retourBtn  = tr.querySelector('.ik-retour');
    const aller  = allerBtn?.classList.contains('active')  ? (parseFloat(allerInput?.value) || 0) : 0;
    const retour = retourBtn?.classList.contains('active') ? (parseFloat(retourInput?.value) || 0) : 0;
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
        const allerBtn   = tr.querySelector('.ik-aller');
        const retourBtn  = tr.querySelector('.ik-retour');
        const allerInput = tr.querySelector('.ik-km-aller-input');
        const retourInput= tr.querySelector('.ik-km-retour-input');
        const a = allerBtn?.classList.contains('active')  ? (parseFloat(allerInput?.value) || 0) : 0;
        const r = retourBtn?.classList.contains('active') ? (parseFloat(retourInput?.value) || 0) : 0;
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
            const allerBtn   = tr.querySelector('.ik-aller');
            const retourBtn  = tr.querySelector('.ik-retour');
            const allerInput = tr.querySelector('.ik-km-aller-input');
            const retourInput= tr.querySelector('.ik-km-retour-input');
            const a = allerBtn?.classList.contains('active')  ? (parseFloat(allerInput?.value) || 0) : 0;
            const r = retourBtn?.classList.contains('active') ? (parseFloat(retourInput?.value) || 0) : 0;
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
        elAlert.innerHTML = `📋 À intégrer dans la paie de : <strong>${moisPaie}</strong><br>Reporter dans le champ paie <em>nb de KM</em> : <strong>${formatKm(totalKm)} km</strong>`;
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
        btn.textContent = expanded ? '➖ Réduire' : '➕ Développer';
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
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
        const el = document.getElementById('session-' + sessId);
        if (el) el.remove();
        const count = document.querySelectorAll('.ik-session').length;
        const elSessions = document.querySelector('#ik-recap-global .ik-recap-stat strong');
        if (elSessions) elSessions.textContent = count;
        updateGlobalRecap();
        const elAlert = document.getElementById('paie-alert-global');
        if (elAlert) {
            if (count === 0) {
                elAlert.innerHTML = '📋 Aucune session IK pour le moment';
            } else if (count > 1) {
                elAlert.innerHTML = '📋 ' + count + ' sessions · voir détail ci-dessous';
            }
        }
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
        <td><input type="date" class="ik-input ik-date" onchange="onFieldChange(this)"></td>
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
        <td><input type="text" class="ik-input ik-ville" placeholder="Ville" onchange="onFieldChange(this)"></td>
        <td><select class="ik-input ik-motif" onchange="onFieldChange(this)">${motifOpts}</select></td>
        <td class="ik-km-cell">
            <button type="button" class="ik-km-btn ik-aller" onclick="toggleKm(this,'aller')" title="Clic = remplir / reclic = retirer">↗</button>
            <input type="number" step="0.1" min="0" class="ik-input ik-km-manual ik-km-aller-input" onchange="onKmChange(this,'aller')" style="width:62px;">
        </td>
        <td class="ik-km-cell">
            <button type="button" class="ik-km-btn ik-retour" onclick="toggleKm(this,'retour')" title="Clic = remplir / reclic = retirer">↙</button>
            <input type="number" step="0.1" min="0" class="ik-input ik-km-manual ik-km-retour-input" onchange="onKmChange(this,'retour')" style="width:62px;">
        </td>
        <td class="ik-total-cell"><span class="ik-total-ligne">—</span></td>
        <td><input type="text" class="ik-input ik-obs" placeholder="Observation…" onchange="onFieldChange(this)"></td>
        <td style="text-align:center;white-space:nowrap;">
            <button type="button" class="ik-action-btn ik-btn-copy" title="Copier" onclick="copyLigne(this)">📋</button>
            <button type="button" class="ik-action-btn ik-btn-del" title="Supprimer" onclick="deleteLigne(this)">🗑</button>
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

// ── Créer une nouvelle session ─────────────────────────────────────────────────
async function createSession() {
    const moisDep  = document.getElementById('new-mois-dep').value;
    const moisPaie = document.getElementById('new-mois-paie').value;
    const vehicule = document.getElementById('new-vehicule').value;
    if (!moisDep || !moisPaie) { alert('Veuillez renseigner les deux mois.'); return; }

    const r = await fetch('api/ik_save_session.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({mois_deplacements: moisDep, mois_paie: moisPaie, vehicule_info: vehicule})
    });
    const json = await r.json();
    if (json.ok) {
        // Recharger la page pour afficher la nouvelle session
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
    // Attacher Places à tous les champs adresse déjà visibles
    document.querySelectorAll('.ik-depart-addr-input').forEach(input => {
        if (!input._placesInit && input.closest('.ik-depart-addr-wrap')?.style.display !== 'none') {
            const tr = input.closest('tr');
            if (tr) attachPlacesAutocomplete(input, tr);
        }
    });
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
        tr.classList.remove('ik-row-empty');
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
</body>
</html>
