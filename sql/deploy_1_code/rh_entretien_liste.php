<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$roleId = current_role_id();
$userId = current_user_id();

// Accès réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    exit('Accès réservé aux managers et administrateurs.');
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// --- Filtres GET ---
$filterStatut  = !empty($_GET['statut'])          ? (string)$_GET['statut']          : '';
$filterType    = !empty($_GET['type_entretien'])  ? (string)$_GET['type_entretien']  : '';
$filterAgence  = ($roleId === 1 && !empty($_GET['agence_id'])) ? (int)$_GET['agence_id'] : 0;

// Agences pour filtre admin
$agences = [];
if ($roleId === 1) {
    $agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
}

// --- Construction requête ---
$sql = "SELECT e.*,
          CONCAT(uc.prenom,' ',uc.nom) AS collab_nom,
          CONCAT(um.prenom,' ',um.nom) AS manager_nom,
          a.nom_agence
        FROM rh_entretiens e
        JOIN users uc ON e.collaborateur_id = uc.id
        JOIN users um ON e.manager_id = um.id
        LEFT JOIN agences a ON e.agence_id = a.id
        WHERE 1=1";

$params = [];

if ($roleId === 2) {
    $sql .= " AND e.manager_id = ?";
    $params[] = $userId;
}
if ($filterStatut !== '') {
    $sql .= " AND e.statut = ?";
    $params[] = $filterStatut;
}
if ($filterType !== '') {
    $sql .= " AND e.type_entretien = ?";
    $params[] = $filterType;
}
if ($roleId === 1 && $filterAgence > 0) {
    $sql .= " AND e.agence_id = ?";
    $params[] = $filterAgence;
}
$sql .= " ORDER BY e.date_planifiee DESC";

$entretiens = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entretiens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorMsg = 'Erreur de chargement des entretiens.';
}

// Compteurs par statut
$counts = ['total' => count($entretiens), 'en_cours' => 0, 'planifie' => 0, 'termine' => 0];
foreach ($entretiens as $e) {
    if ($e['statut'] === 'en_cours') $counts['en_cours']++;
    elseif (in_array($e['statut'], ['planifie','questionnaire_envoye'], true)) $counts['planifie']++;
    elseif (in_array($e['statut'], ['termine','signe','archive'], true)) $counts['termine']++;
}

$typesLabels = [
    'annuel'         => 'Entretien Annuel',
    'pro'            => 'Entretien Professionnel',
    'mi_annee'       => 'Mi-Année',
    'periode_essai'  => "Période d'Essai",
    'retour_absence' => 'Retour Absence',
];

$statutsLabels = [
    'planifie'               => ['label' => 'Planifié',             'color' => '#8a9880'],
    'questionnaire_envoye'   => ['label' => 'Questionnaire envoyé', 'color' => '#36577d'],
    'en_cours'               => ['label' => 'En cours',             'color' => '#b8922a'],
    'termine'                => ['label' => 'Terminé',              'color' => '#7a9060'],
    'signe'                  => ['label' => 'Signé',                'color' => '#4a6038'],
    'archive'                => ['label' => 'Archivé',              'color' => '#a8a49e'],
];

function statutBadge(string $statut, array $map): string {
    $s = $map[$statut] ?? ['label' => htmlspecialchars($statut, ENT_QUOTES), 'color' => '#a8a49e'];
    $col = $s['color'];
    $hex = ltrim($col, '#');
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    return '<span class="status-badge" style="--sc:'.h($col).';--sr:'.$r.';--sg:'.$g.';--sb:'.$b.'">'.h($s['label']).'</span>';
}

// ── Layout variables ──
$layout_title   = 'Entretiens — MaBoxImmo RH';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
<div class="ph-kpi"><span class="ph-kpi-val">' . $counts['total'] . '</span><span class="ph-kpi-lbl">Total</span></div>
<div class="ph-kpi"><span class="ph-kpi-val">' . ($counts['planifie'] + $counts['en_cours']) . '</span><span class="ph-kpi-lbl">En cours / Planifiés</span></div>
<div class="ph-kpi"><span class="ph-kpi-val">' . $counts['termine'] . '</span><span class="ph-kpi-lbl">Terminés / Signés</span></div>
';

$layout_head_actions = '
<a href="rh_entretien_ajouter.php" class="ph-btn primary">+ Nouvel Entretien</a>
' . ($roleId === 1 ? '<a href="rh_entretien_admin.php" class="ph-btn">Admin</a>' : '');

$layout_extra_css = <<<'CSS'
<style>
        /* ── Section title ── */
        .sec-head { display: flex; align-items: center; gap: 14px; margin: 18px 0 14px; }
        .sec-txt {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
            letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
            background: linear-gradient(180deg, #7a9060 0%, #4a6038 40%, #304828 70%, #607848 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .line-l {
            height: 1.5px; width: 28px; flex-shrink: 0;
            background: linear-gradient(90deg, transparent 0%, #304828 40%, #9ab870 100%);
            border-radius: 2px;
        }
        .line-r {
            height: 1.5px; flex: 1;
            background: linear-gradient(90deg, #9ab870 0%, #607848 30%, #4a6038 55%, transparent 100%);
            border-radius: 2px;
        }

        /* ── KPI mini-cards ── */
        .kpi-row { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
        .kpi-card {
            background: var(--bg-primary); border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            padding: 14px 18px; display: flex; align-items: center; gap: 12px;
            flex: 1; min-width: 130px;
        }
        .kpi-ico {
            width: 38px; height: 38px; border-radius: 10px; background: var(--bg-primary);
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .kpi-ico svg { width: 16px; height: 16px; fill: none; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
        .kpi-lbl { font-size: 11px; font-weight: 600; color: #2f587d; }
        .kpi-val { font-family: 'DM Mono', monospace; font-size: 18px; font-weight: 500; }
        .kpi-val.blue  { color: #36577d; }
        .kpi-val.green { color: #7a9060; }
        .kpi-val.amber { color: #b8922a; }
        .kpi-val.muted { color: #a8a49e; }

        /* ── Filtres ── */
        .filters-row {
            display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
            background: var(--bg-primary); border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            padding: 16px 20px; margin-bottom: 20px;
        }
        .fg { display: flex; flex-direction: column; gap: 5px; }
        .fg label {
            font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 500;
            letter-spacing: 0.18em; text-transform: uppercase; color: #a8a49e;
        }
        .fg select {
            height: 32px; padding: 0 10px;
            background: var(--bg-primary);
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            border: none; border-radius: 8px;
            font-family: 'Sora', sans-serif; font-size: 11px; color: #1a1816;
            cursor: pointer; outline: none; min-width: 160px;
        }
        .fg select:focus { box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light), 0 0 0 2px rgba(74,96,56,0.25); }
        .btn-reset {
            height: 32px; padding: 0 14px; border: none; border-radius: 8px;
            background: var(--bg-primary); cursor: pointer;
            box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 500; color: #8a5040;
            display: flex; align-items: center; gap: 6px; text-decoration: none;
            align-self: flex-end;
        }
        .btn-reset:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }

        /* ── Table card ── */
        .table-card {
            background: var(--bg-primary); border-radius: 20px;
            box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
            overflow: hidden;
        }
        .table-card-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 22px 14px;
            border-bottom: 1px solid rgba(196,192,186,0.4);
        }
        .table-card-title {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
            letter-spacing: 0.2em; text-transform: uppercase; color: #a8a49e;
        }
        .count-pill {
            font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.1em;
            background: var(--bg-primary);
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
            color: #36577d; padding: 3px 10px; border-radius: 999px;
        }
        .ent-table { width: 100%; border-collapse: collapse; }
        .ent-table thead { background: rgba(54,87,125,0.06); }
        .ent-table th {
            padding: 10px 16px; text-align: left;
            font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
            letter-spacing: 0.18em; text-transform: uppercase; color: #4a6038;
            border-bottom: 1px solid rgba(196,192,186,0.4); white-space: nowrap;
        }
        .ent-table td {
            padding: 12px 16px; border-bottom: 1px solid rgba(196,192,186,0.25);
            font-size: 12px; color: #1a1816; vertical-align: middle;
        }
        .ent-table tbody tr:last-child td { border-bottom: none; }
        .ent-table tbody tr:hover td { background: rgba(54,87,125,0.04); }

        .collab-name { font-weight: 600; font-size: 13px; color: #1a1816; }
        .manager-name { font-size: 11px; color: #6a6660; }
        .type-label {
            font-family: 'DM Mono', monospace; font-size: 9.5px; letter-spacing: 0.06em;
            color: #5a5650;
        }
        .date-val { font-family: 'DM Mono', monospace; font-size: 11px; color: #6a6660; }
        .agence-val { font-size: 11px; color: #8a8680; }

        /* ── Status badge ── */
        .status-badge {
            display: inline-block; padding: 3px 10px; border-radius: 999px;
            font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.08em; font-weight: 500;
            background: rgba(var(--sr), var(--sg), var(--sb), 0.12);
            color: var(--sc);
            box-shadow: inset 1px 1px 3px rgba(var(--sr), var(--sg), var(--sb), 0.15);
        }

        /* ── Action buttons ── */
        .actions-cell { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
        .act-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 999px; border: none; cursor: pointer;
            font-family: 'Sora', sans-serif; font-size: 10.5px; font-weight: 600;
            text-decoration: none; white-space: nowrap;
            background: var(--bg-primary);
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            color: #6a6660;
            transition: box-shadow 0.15s;
        }
        .act-btn:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
        .act-btn.amber { color: #b8922a; }
        .act-btn.blue  { color: #36577d; }
        .act-btn.green { color: #4a6038; }
        .act-btn.icon-only { padding: 5px 8px; }

        /* ── CTA button (header) ── */
        .btn-cta {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 0 18px; height: 36px; border-radius: 999px; border: none; cursor: pointer;
            font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
            background: linear-gradient(135deg, #4a6038, #7a9060);
            color: #ffffff;
            box-shadow: 4px 4px 10px rgba(74,96,56,0.4), -2px -2px 6px rgba(255,255,255,0.6);
            text-decoration: none;
        }
        .btn-cta:hover { box-shadow: 5px 5px 12px rgba(74,96,56,0.5), -2px -2px 6px rgba(255,255,255,0.7); }
        .btn-cta svg { width: 14px; height: 14px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; }

        .btn-admin {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 0 14px; height: 34px; border-radius: 8px; border: none; cursor: pointer;
            font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 500; color: #6a6660;
            text-decoration: none;
            background: var(--bg-primary);
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        }
        .btn-admin:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }

        /* ── Alerts ── */
        .msg {
            padding: 12px 16px; border-radius: 12px; font-size: 12px;
            margin-bottom: 16px; font-weight: 500;
        }
        .msg-success { background: rgba(122,144,96,0.15); color: #4a6038; box-shadow: inset 2px 2px 6px rgba(122,144,96,0.2); }
        .msg-error   { background: rgba(204,92,88,0.12);  color: #8a5040; box-shadow: inset 2px 2px 6px rgba(204,92,88,0.15); }

        /* ── Empty state ── */
        .empty-state {
            text-align: center; padding: 48px 24px;
            font-family: 'DM Mono', monospace; font-size: 11px;
            color: #a8a49e; letter-spacing: 0.08em;
        }
        .empty-state svg { width: 48px; height: 48px; stroke: #c8c4be; fill: none; stroke-width: 1.2; stroke-linecap: round; stroke-linejoin: round; margin-bottom: 14px; }

        @media (max-width: 900px) {
            .kpi-row { display: none; }
        }
</style>
CSS;

$layout_extra_js = '';

// ── Content ──
ob_start();
?>

            <?php if (!empty($_GET['success'])): ?>
                <div class="msg msg-success"><?=h($_GET['success'])?></div>
            <?php endif; ?>
            <?php if (!empty($errorMsg)): ?>
                <div class="msg msg-error"><?=h($errorMsg)?></div>
            <?php endif; ?>

            <!-- KPI mini-cards -->
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-ico">
                        <svg viewBox="0 0 24 24" stroke="#36577d"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div>
                        <div class="kpi-lbl">Total</div>
                        <div class="kpi-val blue"><?= $counts['total'] ?></div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-ico">
                        <svg viewBox="0 0 24 24" stroke="#b8922a"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="kpi-lbl">En cours / Planifiés</div>
                        <div class="kpi-val amber"><?= $counts['planifie'] + $counts['en_cours'] ?></div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-ico">
                        <svg viewBox="0 0 24 24" stroke="#4a6038"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="kpi-lbl">Terminés / Signés</div>
                        <div class="kpi-val green"><?= $counts['termine'] ?></div>
                    </div>
                </div>
            </div>

            <!-- Section title Filtres -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Filtres</span>
                <div class="line-r"></div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="filters-row">
                <div class="fg">
                    <label>Statut</label>
                    <select name="statut" onchange="this.form.submit()">
                        <option value="">Tous les statuts</option>
                        <?php foreach($statutsLabels as $val => $info): ?>
                            <option value="<?=h($val)?>" <?=$filterStatut===$val?'selected':''?>><?=h($info['label'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Type</label>
                    <select name="type_entretien" onchange="this.form.submit()">
                        <option value="">Tous les types</option>
                        <?php foreach($typesLabels as $val => $lbl): ?>
                            <option value="<?=h($val)?>" <?=$filterType===$val?'selected':''?>><?=h($lbl)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($roleId === 1): ?>
                <div class="fg">
                    <label>Agence</label>
                    <select name="agence_id" onchange="this.form.submit()">
                        <option value="">Toutes les agences</option>
                        <?php foreach($agences as $ag): ?>
                            <option value="<?=(int)$ag['id']?>" <?=$filterAgence===(int)$ag['id']?'selected':''?>><?=h($ag['nom_agence'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($filterStatut || $filterType || $filterAgence): ?>
                    <a href="rh_entretien_liste.php" class="btn-reset">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        Réinitialiser
                    </a>
                <?php endif; ?>
            </form>

            <!-- Section title Tableau -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Liste des entretiens</span>
                <div class="line-r"></div>
            </div>

            <!-- Tableau -->
            <div class="table-card">
                <div class="table-card-head">
                    <span class="table-card-title">Entretiens individuels</span>
                    <span class="count-pill"><?=count($entretiens)?> entretien<?=count($entretiens)>1?'s':''?></span>
                </div>
                <table class="ent-table">
                    <thead>
                        <tr>
                            <th>Collaborateur</th>
                            <th>Manager</th>
                            <th>Type</th>
                            <th>Date planifiée</th>
                            <?php if ($roleId === 1): ?><th>Agence</th><?php endif; ?>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($entretiens)): ?>
                        <tr>
                            <td colspan="<?=$roleId===1?7:6?>" style="padding:0">
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                    <div>Aucun entretien trouvé.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach($entretiens as $e): ?>
                        <tr>
                            <td><span class="collab-name"><?=h($e['collab_nom'])?></span></td>
                            <td><span class="manager-name"><?=h($e['manager_nom'])?></span></td>
                            <td><span class="type-label"><?=h($typesLabels[$e['type_entretien']] ?? $e['type_entretien'])?></span></td>
                            <td><span class="date-val"><?=h($e['date_planifiee'] ? date('d/m/Y', strtotime($e['date_planifiee'])) : '—')?></span></td>
                            <?php if ($roleId === 1): ?><td><span class="agence-val"><?=h($e['nom_agence'] ?? '—')?></span></td><?php endif; ?>
                            <td><?=statutBadge($e['statut'], $statutsLabels)?></td>
                            <td>
                                <div class="actions-cell">
                                <?php
                                $s = $e['statut'];
                                if (in_array($s, ['en_cours', 'questionnaire_envoye', 'planifie'], true)):
                                ?>
                                    <a href="rh_entretien_tenir.php?id=<?=(int)$e['id']?>" class="act-btn amber">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                        Conduire
                                    </a>
                                    <a href="rh_entretien_synthese.php?id=<?=(int)$e['id']?>" class="act-btn icon-only" title="Synthèse">
                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                                    </a>
                                <?php elseif(in_array($s, ['termine', 'signe', 'archive', 'finalise'], true)): ?>
                                    <a href="rh_entretien_tenir.php?id=<?=(int)$e['id']?>" class="act-btn blue">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        Consulter
                                    </a>
                                    <a href="rh_entretien_synthese.php?id=<?=(int)$e['id']?>" class="act-btn green">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                                        Synthèse
                                    </a>
                                    <a href="rh_entretien_detail.php?id=<?=(int)$e['id']?>" class="act-btn icon-only" title="Voir détail">
                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </a>
                                <?php else: ?>
                                    <span style="font-family:'DM Mono',monospace;font-size:10px;color:#c8c4be;">—</span>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
