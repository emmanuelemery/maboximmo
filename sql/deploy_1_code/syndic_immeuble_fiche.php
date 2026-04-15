<?php
/*
 * syndic_immeuble_fiche.php
 * Rôle  : Fiche détaillée d'un immeuble — consultation + édition inline
 * Thème : Bleu-gris clair
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = current_role_id();
$pdo    = $GLOBALS['pdo'];

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: syndic_immeubles.php'); exit; }

// ── Chargement immeuble ───────────────────────────────────────
$stmt = $pdo->prepare("SELECT i.*, e.nom AS nom_etablissement, u.nom_complet AS nom_gestionnaire
    FROM immeubles i
    LEFT JOIN etablissements e ON i.id_etablissement = e.id
    LEFT JOIN users u ON i.gestionnaire = u.id
    WHERE i.id = ?");
$stmt->execute([$id]);
$imm = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$imm) { header('Location: syndic_immeubles.php'); exit; }

// ── Chargement infos fiche ────────────────────────────────────
$stmt2 = $pdo->prepare("SELECT * FROM immeubles_infos WHERE id_immeuble = ?");
$stmt2->execute([$id]);
$fiche = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Traitement POST (sauvegarde) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $roleId <= 2) {
    $fields = [
        'budget','date_ag_derniere','date_ag_prochaine','date_arrete_comptes',
        'travaux_cours','travaux_prevoir','interventions_cours','procedures',
        'boite_cles','particularites','contrats','debiteurs','copros','mail',
        'sinistres','honoraires_ht','honoraires_lot','bloc_note_ics','commentaires',
        'hono_2026','hono_2027','hono_2028','hono_2029','hono_2030',
        'ag_2025','ag_2026','ag_2027','ag_2028','ag_2029','ag_2030',
    ];
    $data = [];
    foreach ($fields as $f) {
        $val = $_POST[$f] ?? null;
        $data[$f] = ($val === '') ? null : $val;
    }

    if ($fiche) {
        $set  = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($data)));
        $stmt = $pdo->prepare("UPDATE immeubles_infos SET $set WHERE id_immeuble = :id_imm");
        $data['id_imm'] = $id;
        $stmt->execute($data);
    } else {
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $phd  = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $stmt = $pdo->prepare("INSERT INTO immeubles_infos ($cols, id_immeuble) VALUES ($phd, :id_imm)");
        $data['id_imm'] = $id;
        $stmt->execute($data);
    }

    // Reload et redirect
    header('Location: syndic_immeuble_fiche.php?' . http_build_query(array_merge($_GET, ['id' => $id, 'saved' => 1])));
    exit;
}

// ── Navigation prev/next (en respectant les filtres actifs) ──
$navConds  = [];
$navParams = [];
if (($v = $_GET['reference'] ?? '') !== '') { $navConds[] = 'i.reference LIKE ?'; $navParams[] = "%$v%"; }
if (($v = $_GET['nom']       ?? '') !== '') { $navConds[] = 'i.nom LIKE ?';       $navParams[] = "%$v%"; }
if (($v = $_GET['ville']     ?? '') !== '') { $navConds[] = 'i.ville LIKE ?';     $navParams[] = "%$v%"; }
if (($v = $_GET['type']      ?? '') !== '') { $navConds[] = 'i.type = ?';         $navParams[] = $v; }
if (($v = (int)($_GET['etablissement'] ?? 0)) > 0) { $navConds[] = 'i.id_etablissement = ?'; $navParams[] = $v; }
if (($v = (int)($_GET['gestionnaire']  ?? 0)) > 0) { $navConds[] = 'i.gestionnaire = ?';     $navParams[] = $v; }

$navWhere = $navConds ? ' WHERE ' . implode(' AND ', $navConds) : '';
$stmtNav  = $pdo->prepare("SELECT id FROM immeubles i $navWhere ORDER BY i.reference ASC");
$stmtNav->execute($navParams);
$allIds   = $stmtNav->fetchAll(PDO::FETCH_COLUMN);
$pos      = array_search((string)$id, array_map('strval', $allIds));
$prevId   = ($pos !== false && $pos > 0) ? $allIds[$pos - 1] : null;
$nextId   = ($pos !== false && $pos < count($allIds) - 1) ? $allIds[$pos + 1] : null;
$total    = count($allIds);

function buildNavUrl(int $newId): string {
    $p = $_GET; $p['id'] = $newId;
    return 'syndic_immeuble_fiche.php?' . http_build_query($p);
}

// Année courante pour retour AG
$anneeCourante = (int)date('Y');

$saved = (int)($_GET['saved'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fiche — <?= h($imm['nom']) ?> — Syndic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/theme-syndic.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; background: #ffffff; color: #1a1816; min-height: 100vh; display: flex; }
        .shell { width: 100%; min-height: 100vh; }
        .sb-content { margin-left: 220px; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        /* Topbar */
        .topbar { display: flex; align-items: center; gap: 10px; padding: 0 24px 0 20px; height: 56px; background: #ffffff; box-shadow: 0 4px 12px rgba(196,192,186,0.45); position: sticky; top: 0; z-index: 100; flex-shrink: 0; }
        .topbar-back { width: 34px; height: 34px; border-radius: 10px; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; text-decoration: none; }
        .topbar-back:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .topbar-back svg { width:15px; height:15px; stroke:#7a9ab8; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .topbar-gap { width: 40px; flex-shrink: 0; }
        .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; font-family: 'DM Mono', monospace; font-size: 12px; letter-spacing: 0.06em; color: #8a8680; margin-left: 40px; }
        .topbar-breadcrumb a { color: inherit; text-decoration: none; }
        .topbar-breadcrumb a:hover { color: #4878a6; }
        .topbar-breadcrumb .active { color: #4878a6; font-weight: 500; }
        .topbar-sep { color: #c8c4be; font-size: 16px; }
        .topbar-spacer { flex: 1; }
        .topbar-notif { position: relative; width: 36px; height: 36px; border-radius: 10px; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
        .topbar-notif svg { width:16px; height:16px; stroke:#8a8680; fill:none; stroke-width:1.6; }
        .topbar-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #6898bf, #4878a6); display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }

        /* Main */
        .main { flex: 1; overflow-y: auto; padding: 0 28px 40px; }
        .page-head { display: flex; align-items: center; justify-content: space-between; height: 80px; flex-shrink: 0; border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 20px; gap: 20px; }
        .page-head-module { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.22em; text-transform: uppercase; color: #a8a49e; margin-bottom: 3px; }
        .page-head-title { font-family: 'Sora', sans-serif; font-size: 20px; font-weight: 700; color: #1a1816; letter-spacing: -0.02em; max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .page-head-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: #4878a6; font-weight: 600; letter-spacing: 0.1em; margin-top: 1px; }
        .page-head-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

        /* sec-head */
        .sec-head { display: flex; align-items: center; gap: 14px; margin: 20px 0 10px; }
        .sec-txt { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0; background: linear-gradient(180deg,#8eb4d3 0%,#4878a6 40%,#25486a 70%,#6898bf 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .line-l { height: 1.5px; width: 28px; flex-shrink: 0; background: linear-gradient(90deg, transparent 0%, #25486a 40%, #8eb4d3 100%); border-radius: 2px; }
        .line-r { height: 1.5px; flex: 1; background: linear-gradient(90deg, #8eb4d3 0%, #4878a6 30%, #25486a 55%, transparent 100%); border-radius: 2px; }

        /* Boutons */
        .v2-btn { padding: 0 16px; height: 34px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.04em; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; font-weight: 600; color: #3a3830; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: box-shadow 0.15s; }
        .v2-btn:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .v2-btn.primary { background: #4878a6; color: #fff; box-shadow: 3px 3px 8px #c4c0ba,-3px -3px 8px #ffffff; }
        .v2-btn.success { background: #4a6038; color: #fff; }
        .v2-btn.warning { background: #e08020; color: #fff; }

        /* Nav prev/next */
        .imm-nav { display: flex; align-items: center; gap: 8px; }
        .nav-btn { width: 32px; height: 32px; border-radius: 10px; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; color: #4878a6; font-size: 14px; transition: box-shadow 0.12s; }
        .nav-btn:hover { box-shadow: 5px 5px 12px #c8cbd2, -5px -5px 12px #ffffff; }
        .nav-btn:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .nav-btn.disabled { opacity: 0.3; pointer-events: none; }
        .nav-pos { font-family: 'DM Mono', monospace; font-size: 11px; color: #8a8680; letter-spacing: 0.06em; min-width: 52px; text-align: center; }

        /* Section card */
        .fiche-section { background: #ffffff; border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; padding: 18px 22px; margin-bottom: 16px; }
        .fiche-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 12px; }
        .fiche-row:last-child { margin-bottom: 0; }
        .fiche-row.cols2 { grid-template-columns: 1fr 1fr; }
        .fiche-row.cols3 { grid-template-columns: 1fr 1fr 1fr; }
        .fiche-row.cols1 { grid-template-columns: 1fr; }

        .ff label { display: block; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: #a8a49e; margin-bottom: 5px; }
        .ff input, .ff textarea, .ff select {
            width: 100%; background: #ffffff;
            box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff;
            border: none; border-radius: 10px; padding: 8px 12px;
            font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
            outline: none; resize: vertical; box-sizing: border-box;
        }
        .ff input:focus, .ff textarea:focus, .ff select:focus {
            box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(72,120,166,0.3);
        }
        .ff input[readonly] { color: #8a8680; cursor: default; box-shadow: inset 2px 2px 4px #c4c0ba, inset -2px -2px 4px #f0ede8; }
        .ff textarea { min-height: 60px; line-height: 1.5; }

        /* Tableau honoraires */
        .hono-wrap { overflow-x: auto; margin-top: 10px; }
        .hono-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 660px; }
        .hono-table th { padding: 8px 10px; font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: #4878a6; border-bottom: 1px solid rgba(196,192,186,0.5); text-align: center; background: rgba(72,120,166,0.06); white-space: nowrap; }
        .hono-table th:first-child { text-align: left; }
        .hono-table td { padding: 8px 8px; border-bottom: 1px solid rgba(196,192,186,0.25); text-align: center; vertical-align: middle; }
        .hono-table td:first-child { text-align: left; font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680; letter-spacing: 0.06em; }
        .hono-table input[type=text] {
            width: 90px; text-align: right; background: #ffffff;
            box-shadow: inset 2px 2px 5px #c4c0ba, inset -2px -2px 5px #ffffff;
            border: none; border-radius: 8px; padding: 5px 8px;
            font-family: 'DM Mono', monospace; font-size: 11px; color: #1a1816; outline: none; box-sizing: border-box;
        }
        .hono-table input[type=text]:focus { box-shadow: inset 2px 2px 5px #c4c0ba, inset -2px -2px 5px #ffffff, 0 0 0 2px rgba(72,120,166,0.3); }
        .hono-table input.ro { color: #8a8680; cursor: default; box-shadow: inset 1px 1px 3px #c4c0ba, inset -1px -1px 3px #f0ede8; }
        .hono-table input[type=date] { width: 120px; font-size: 11px; }
        .evo-chip { display: inline-block; padding: 3px 8px; border-radius: 8px; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 600; background: #f0f0f0; color: #6a6660; white-space: nowrap; }
        .evo-chip.pos { background: #e0f0e8; color: #1a6035; }
        .evo-chip.neg { background: #fce8e6; color: #a03020; }

        /* Info immeuble header */
        .imm-header { background: #ffffff; border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; padding: 16px 20px; margin-bottom: 16px; display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
        .imm-header-left { }
        .imm-header-nom { font-size: 18px; font-weight: 700; color: #1a1816; }
        .imm-header-ref { font-family: 'DM Mono', monospace; font-size: 11px; color: #4878a6; font-weight: 600; margin-top: 2px; }
        .imm-header-meta { display: flex; gap: 16px; margin-top: 10px; flex-wrap: wrap; }
        .imm-meta-item { display: flex; flex-direction: column; gap: 1px; }
        .imm-meta-label { font-family: 'DM Mono', monospace; font-size: 8px; color: #a8a49e; text-transform: uppercase; letter-spacing: 0.1em; }
        .imm-meta-value { font-size: 13px; font-weight: 600; color: #1a1816; }
        .imm-header-actions { display: flex; gap: 8px; align-items: flex-start; }

        /* Retour AG widget */
        .ag-widget { display: flex; align-items: center; gap: 8px; }
        .ag-widget select { height: 32px; padding: 0 8px; background: #ffffff; box-shadow: inset 2px 2px 5px #c4c0ba, inset -2px -2px 5px #ffffff; border: none; border-radius: 8px; font-family: 'DM Mono', monospace; font-size: 11px; color: #1a1816; outline: none; }

        /* Alert success */
        .alert-saved { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 10px; background: #e0f0e8; color: #1a6035; border: 1px solid #b0d8c0; font-size: 13px; margin-bottom: 14px; }

        /* Disabled overlay */
        .readonly-overlay { pointer-events: none; opacity: 0.7; }
    </style>
</head>
<body>
<div class="shell">

    <?php include __DIR__ . '/sidebar_syndic.php'; ?>

    <div class="sb-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <button class="topbar-back" onclick="history.back()" title="Retour">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="topbar-back" onclick="history.forward()" title="Avancer">
                <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <nav class="topbar-breadcrumb">
                <span>Syndic</span>
                <span class="topbar-sep">›</span>
                <a href="syndic_immeubles.php?<?= h(http_build_query(array_diff_key($_GET, ['id'=>'','saved'=>'']))) ?>">Immeubles</a>
                <span class="topbar-sep">›</span>
                <span class="active"><?= h($imm['reference']) ?> — <?= h($imm['nom']) ?></span>
            </nav>

            <div class="topbar-spacer"></div>

            <!-- Nav prev/next -->
            <div class="imm-nav">
                <?php if ($prevId): ?>
                    <a href="<?= h(buildNavUrl((int)$prevId)) ?>" class="nav-btn" title="Immeuble précédent">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4878a6" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                <?php else: ?>
                    <span class="nav-btn disabled"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></span>
                <?php endif; ?>
                <span class="nav-pos"><?= ($pos !== false ? $pos + 1 : '—') ?> / <?= $total ?></span>
                <?php if ($nextId): ?>
                    <a href="<?= h(buildNavUrl((int)$nextId)) ?>" class="nav-btn" title="Immeuble suivant">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4878a6" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                <?php else: ?>
                    <span class="nav-btn disabled"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></span>
                <?php endif; ?>
            </div>

            <button class="topbar-notif" title="Notifications">
                <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            </button>
            <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? '?', 0, 1) . substr($_SESSION['nom'] ?? '', 0, 1)) ?></div>
        </header>

        <!-- MAIN -->
        <main class="main">

            <!-- PAGE HEAD -->
            <div class="page-head">
                <div style="min-width:0">
                    <div class="page-head-module">Fiche Immeuble</div>
                    <div class="page-head-title"><?= h($imm['nom']) ?></div>
                    <div class="page-head-ref">Réf. <?= h($imm['reference']) ?> · <?= (int)$imm['nb_lots'] ?> lots · <?= h($imm['ville']) ?></div>
                </div>
                <div class="page-head-actions">
                    <!-- Retour AG -->
                    <form action="syndic_retour_ag.php" method="get" target="_blank" class="ag-widget">
                        <input type="hidden" name="id_immeuble" value="<?= $id ?>">
                        <select name="annee">
                            <?php for ($a = $anneeCourante - 1; $a <= 2030; $a++): ?>
                                <option value="<?= $a ?>" <?= $a === $anneeCourante ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="v2-btn warning" style="height:32px;font-size:11px">
                            📋 Retour AG
                        </button>
                    </form>
                    <!-- PDF -->
                    <a href="syndic_pdf_fiche.php?id=<?= $id ?>" target="_blank" class="v2-btn" title="Exporter en PDF">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        PDF
                    </a>
                    <?php if ($roleId <= 2): ?>
                    <a href="syndic_immeuble_form.php?id=<?= $id ?>" class="v2-btn" title="Modifier les infos de base">
                        ✏️ Modifier
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($saved): ?>
            <div class="alert-saved">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Fiche enregistrée avec succès.
            </div>
            <?php endif; ?>

            <!-- HEADER IMMEUBLE -->
            <div class="imm-header">
                <div class="imm-header-left">
                    <div class="imm-header-nom"><?= h($imm['nom']) ?></div>
                    <div class="imm-header-ref"><?= h($imm['reference']) ?><?= $imm['immatriculation'] ? ' · Immat. ' . h($imm['immatriculation']) : '' ?></div>
                    <div class="imm-header-meta">
                        <div class="imm-meta-item">
                            <span class="imm-meta-label">Adresse</span>
                            <span class="imm-meta-value" style="font-size:12px"><?= h($imm['adresse']) ?>, <?= h($imm['code_postal']) ?> <?= h($imm['ville']) ?></span>
                        </div>
                        <div class="imm-meta-item">
                            <span class="imm-meta-label">Lots</span>
                            <span class="imm-meta-value"><?= (int)$imm['nb_lots'] ?></span>
                        </div>
                        <div class="imm-meta-item">
                            <span class="imm-meta-label">Établissement</span>
                            <span class="imm-meta-value" style="font-size:12px"><?= h($imm['nom_etablissement'] ?? '—') ?></span>
                        </div>
                        <div class="imm-meta-item">
                            <span class="imm-meta-label">Gestionnaire</span>
                            <span class="imm-meta-value" style="font-size:12px"><?= h($imm['nom_gestionnaire'] ?? '—') ?></span>
                        </div>
                        <?php if ($fiche['mail'] ?? ''): ?>
                        <div class="imm-meta-item">
                            <span class="imm-meta-label">Mail multidiffusion</span>
                            <span class="imm-meta-value" style="font-size:11px"><?= h($fiche['mail']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="imm-header-actions">
                    <a href="syndic_reunions.php?id_immeuble=<?= $id ?>" class="v2-btn" style="font-size:11px;height:30px">
                        🗓 Réunions
                    </a>
                    <a href="syndic_taches.php?id_immeuble=<?= $id ?>" class="v2-btn" style="font-size:11px;height:30px">
                        ✅ Tâches
                    </a>
                </div>
            </div>

            <?php $canEdit = ($roleId <= 2); ?>
            <form method="POST" id="fiche-form">

            <!-- ═══════════ SEC 1 : FINANCIER ═══════════ -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Financier & Honoraires</span>
                <div class="line-r"></div>
            </div>

            <div class="fiche-section">
                <div class="fiche-row cols3">
                    <div class="ff">
                        <label>Honoraires HT (annuel)</label>
                        <input type="text" name="honoraires_ht" id="hono_base"
                               value="<?= h($fiche['honoraires_ht'] ?? '') ?>"
                               placeholder="0.00" <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="ff">
                        <label>Honoraires / lot (calcul auto)</label>
                        <input type="text" id="honoraires_lot_display" readonly
                               value="<?= h($fiche['honoraires_lot'] ?? '') ?>" class="ro">
                        <input type="hidden" name="honoraires_lot" id="honoraires_lot">
                    </div>
                    <div class="ff">
                        <label>Budget copropriété</label>
                        <input type="text" name="budget" value="<?= h($fiche['budget'] ?? '') ?>"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>

                <!-- Tableau honoraires 2025–2030 -->
                <div class="sec-head" style="margin-top:14px">
                    <div class="line-l"></div>
                    <span class="sec-txt">Évolution honoraires 2025 → 2030</span>
                    <div class="line-r"></div>
                </div>
                <div class="hono-wrap">
                    <table class="hono-table">
                        <thead>
                            <tr>
                                <th>Ligne</th>
                                <th>2025 (réf.)</th>
                                <th>2026</th>
                                <th>2027</th>
                                <th>2028</th>
                                <th>2029</th>
                                <th>2030</th>
                            </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td>Honoraires HT</td>
                            <td><input type="text" id="h25_disp" class="ro" readonly></td>
                            <td><input type="text" name="hono_2026" id="h26" value="<?= h($fiche['hono_2026'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="text" name="hono_2027" id="h27" value="<?= h($fiche['hono_2027'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="text" name="hono_2028" id="h28" value="<?= h($fiche['hono_2028'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="text" name="hono_2029" id="h29" value="<?= h($fiche['hono_2029'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="text" name="hono_2030" id="h30" value="<?= h($fiche['hono_2030'] ?? '') ?>" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                        </tr>
                        <tr>
                            <td>Évolution</td>
                            <td>—</td>
                            <td><span class="evo-chip" id="evo_25_26">—</span></td>
                            <td><span class="evo-chip" id="evo_26_27">—</span></td>
                            <td><span class="evo-chip" id="evo_27_28">—</span></td>
                            <td><span class="evo-chip" id="evo_28_29">—</span></td>
                            <td><span class="evo-chip" id="evo_29_30">—</span></td>
                        </tr>
                        <tr>
                            <td>Tarif / lot</td>
                            <td><input type="text" id="lot25" class="ro" readonly></td>
                            <td><input type="text" id="lot26" class="ro" readonly></td>
                            <td><input type="text" id="lot27" class="ro" readonly></td>
                            <td><input type="text" id="lot28" class="ro" readonly></td>
                            <td><input type="text" id="lot29" class="ro" readonly></td>
                            <td><input type="text" id="lot30" class="ro" readonly></td>
                        </tr>
                        <tr>
                            <td>Date AG</td>
                            <td><input type="date" name="ag_2025" value="<?= h($fiche['ag_2025'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="date" name="ag_2026" value="<?= h($fiche['ag_2026'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="date" name="ag_2027" value="<?= h($fiche['ag_2027'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="date" name="ag_2028" value="<?= h($fiche['ag_2028'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="date" name="ag_2029" value="<?= h($fiche['ag_2029'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                            <td><input type="date" name="ag_2030" value="<?= h($fiche['ag_2030'] ?? '') ?>" style="width:120px" <?= !$canEdit ? 'readonly class="ro"' : '' ?>></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══════════ SEC 2 : DATES & AG ═══════════ -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Dates & Assemblées Générales</span>
                <div class="line-r"></div>
            </div>
            <div class="fiche-section">
                <div class="fiche-row cols3">
                    <div class="ff">
                        <label>Dernière AG</label>
                        <input type="date" name="date_ag_derniere" value="<?= h($fiche['date_ag_derniere'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="ff">
                        <label>Prochaine AG</label>
                        <input type="date" name="date_ag_prochaine" value="<?= h($fiche['date_ag_prochaine'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="ff">
                        <label>Arrêté de comptes</label>
                        <input type="date" name="date_arrete_comptes" value="<?= h($fiche['date_arrete_comptes'] ?? '') ?>" <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>
            </div>

            <!-- ═══════════ SEC 3 : TRAVAUX & SINISTRES ═══════════ -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Travaux & Sinistres</span>
                <div class="line-r"></div>
            </div>
            <div class="fiche-section">
                <div class="fiche-row cols2">
                    <div class="ff">
                        <label>Travaux en cours</label>
                        <textarea name="travaux_cours" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['travaux_cours'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Travaux à prévoir</label>
                        <textarea name="travaux_prevoir" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['travaux_prevoir'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="fiche-row cols2">
                    <div class="ff">
                        <label>Interventions en cours</label>
                        <textarea name="interventions_cours" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['interventions_cours'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Sinistres</label>
                        <textarea name="sinistres" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['sinistres'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ═══════════ SEC 4 : ADMINISTRATIF ═══════════ -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Administratif & Contacts</span>
                <div class="line-r"></div>
            </div>
            <div class="fiche-section">
                <div class="fiche-row cols2">
                    <div class="ff">
                        <label>Contrats à négocier / résilier</label>
                        <textarea name="contrats" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['contrats'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Procédures en cours</label>
                        <textarea name="procedures" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['procedures'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="fiche-row cols2">
                    <div class="ff">
                        <label>Contacts privilégiés (copros)</label>
                        <textarea name="copros" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['copros'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Débiteurs</label>
                        <textarea name="debiteurs" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['debiteurs'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="fiche-row cols2">
                    <div class="ff">
                        <label>Particularités</label>
                        <textarea name="particularites" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['particularites'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Mail multidiffusion</label>
                        <input type="text" name="mail" value="<?= h($fiche['mail'] ?? '') ?>" placeholder="president@cs.fr, …" <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>
                <div class="fiche-row cols1">
                    <div class="ff">
                        <label>Boîte à clés (quelles clés et où)</label>
                        <textarea name="boite_cles" class="auto-expand" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['boite_cles'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ═══════════ SEC 5 : NOTES ═══════════ -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt">Notes & Bloc-note</span>
                <div class="line-r"></div>
            </div>
            <div class="fiche-section">
                <div class="fiche-row cols2">
                    <div class="ff">
                        <label>Bloc-note ICS</label>
                        <textarea name="bloc_note_ics" class="auto-expand" rows="3" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['bloc_note_ics'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Commentaires</label>
                        <textarea name="commentaires" class="auto-expand" rows="4" <?= !$canEdit ? 'readonly' : '' ?>><?= h($fiche['commentaires'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px">
                <a href="syndic_immeubles.php" class="v2-btn">✕ Annuler</a>
                <button type="submit" class="v2-btn success">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Enregistrer la fiche
                </button>
            </div>
            <?php endif; ?>

            </form>

        </main>
    </div>
</div>

<script>
// Auto-expand textareas
function autoResize(el) { el.style.height='auto'; el.style.height=(el.scrollHeight)+'px'; }
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('textarea.auto-expand').forEach(function(el){
        el.addEventListener('input', () => autoResize(el));
        autoResize(el);
    });
});

// Calculs honoraires / lots
document.addEventListener('DOMContentLoaded', function () {
    const nbLots = <?= (int)$imm['nb_lots'] ?>;
    const hBase  = document.getElementById('hono_base');
    const hDisp  = document.getElementById('honoraires_lot_display');
    const hHid   = document.getElementById('honoraires_lot');
    const h25d   = document.getElementById('h25_disp');
    const h26    = document.getElementById('h26');
    const h27    = document.getElementById('h27');
    const h28    = document.getElementById('h28');
    const h29    = document.getElementById('h29');
    const h30    = document.getElementById('h30');

    const b2526 = document.getElementById('evo_25_26');
    const b2627 = document.getElementById('evo_26_27');
    const b2728 = document.getElementById('evo_27_28');
    const b2829 = document.getElementById('evo_28_29');
    const b2930 = document.getElementById('evo_29_30');

    const lots = [
        document.getElementById('lot25'),
        document.getElementById('lot26'),
        document.getElementById('lot27'),
        document.getElementById('lot28'),
        document.getElementById('lot29'),
        document.getElementById('lot30'),
    ];

    function n(v) {
        if (!v) return NaN;
        const x = parseFloat(String(v.value ?? v).replace(/\s/g,'').replace(',','.'));
        return isNaN(x) ? NaN : x;
    }
    function euro(x) { return isNaN(x)?'—': x.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
    function pct(a,b) { if(isNaN(a)||isNaN(b)||b===0) return ''; const v=(a/b-1)*100; return (v>=0?'+':'')+v.toFixed(1)+' %'; }
    function setEvo(el, a, b) {
        if(!el) return;
        const va=n(a), vb=n(b);
        if(isNaN(va)||isNaN(vb)) { el.textContent='—'; el.className='evo-chip'; return; }
        const diff=vb-va;
        el.textContent=(diff>=0?'+':'')+euro(diff)+(va!==0?' ('+pct(vb,va)+')':'');
        el.className='evo-chip '+(diff>=0?'pos':'neg');
    }
    function setLot(el, val) {
        if(!el) return;
        const v=typeof val==='number'?val:n(val);
        el.value = (!isNaN(v)&&nbLots>0) ? (v/nbLots).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '';
    }

    function compute() {
        const v25 = n(hBase);
        if(h25d) h25d.value = hBase.value;
        // honoraires/lot (champ courant)
        if (!isNaN(v25) && nbLots > 0) {
            const perLot = (v25/nbLots).toFixed(2);
            hDisp.value = perLot;
            hHid.value  = perLot;
        } else { hDisp.value=''; hHid.value=''; }

        setEvo(b2526, hBase, h26);
        setEvo(b2627, h26, h27);
        setEvo(b2728, h27, h28);
        setEvo(b2829, h28, h29);
        setEvo(b2930, h29, h30);

        setLot(lots[0], n(hBase));
        setLot(lots[1], n(h26));
        setLot(lots[2], n(h27));
        setLot(lots[3], n(h28));
        setLot(lots[4], n(h29));
        setLot(lots[5], n(h30));
    }

    [hBase, h26, h27, h28, h29, h30].forEach(el => el && el.addEventListener('input', compute));
    compute();
});
</script>
</body>
</html>
