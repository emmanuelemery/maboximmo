<?php
/*
 * rh_dashboard.php  (v2)
 * Rôle     : Dashboard RH — design system v2 neumorphique
 * Dépend   : css/tokens.css, css/base.css, css/components.css,
 *            css/layout.css, css/theme-rh.css, js/components.js
 * Date     : 2026-04-05
 *
 * Standalone : fonctionne sans connexion BDD (données de démo).
 * En production, décommenter le bloc PDO et les requêtes réelles.
 */

// ── Page courante (pour la sidebar) ─────────────────────────────
$current_page = 'dashboard';

// ── Données de démo ──────────────────────────────────────────────
$pendingLeavesCount  = 5;
$activeStaffCount    = 23;
$recruitingCount     = 3;
$interviewsLeft      = 4;
$userName            = 'Admin Demo';

/*
// ── Requêtes réelles (production) ─────────────────────────────
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();
$userName = ($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '');
try {
    $pendingLeavesCount = (int)$pdo->query("SELECT COUNT(*) FROM conges WHERE statut='en_attente'")->fetchColumn();
    $activeStaffCount   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE actif=1")->fetchColumn();
    $recruitingCount    = (int)$pdo->query("SELECT COUNT(*) FROM recrutements WHERE statut='en_cours'")->fetchColumn();
    $interviewsLeft     = (int)$pdo->query("SELECT COUNT(*) FROM entretiens WHERE statut='planifie'")->fetchColumn();
} catch (Exception $e) {}
*/

// ── Agenda des tâches RH ─────────────────────────────────────────
// Structure : colonnes simples + colonnes groupées (subgroups)
$taskColumns = [

    // ── Colonne 1 : Salaires + Congés groupés ──
    'sal_conges' => [
        'label'     => 'Salaires & Congés',
        'grouped'   => true,
        'subgroups' => [
            'salaires' => [
                'label' => 'Salaires',
                'tasks' => [
                    ['icon'=>'💰','title'=>'Saisie des salaires','freq'=>'À partir du 25','desc'=>'Commencer la saisie des fiches de paie à partir du 25. Utiliser les modèles pour pré-remplissage automatique.','link'=>'../rh_salaires.php','label'=>'Gérer','badge'=>['text'=>'→ En cours','class'=>'badge-warning']],
                    ['icon'=>'📈','title'=>'Vérification barème IK','freq'=>'Chaque juillet','desc'=>'Vérifier le barème légal des indemnités kilométriques et mettre à jour les montants si nécessaire.','link'=>'../rh_salaires.php','label'=>'Voir','badge'=>['text'=>'📅 Juillet','class'=>'badge-info']],
                ]
            ],
            'conges' => [
                'label' => 'Congés',
                'tasks' => [
                    ['icon'=>'📅','title'=>'Décompte mensuel','freq'=>'Chaque mois','desc'=>'Vérifier le décompte des jours de congés par collaborateur. Mise à jour automatique le 1er de chaque mois.','link'=>'../rh_conges.php','label'=>'Voir','badge'=>['text'=>'✓ Auto','class'=>'badge-success']],
                    ['icon'=>'✓','title'=>'Validation demandes','freq'=>'À tout moment','desc'=>'Examiner et valider/refuser les demandes de congés en attente. Notifier les collaborateurs.','link'=>'../rh_conges_validation.php','label'=>'Valider','badge'=>['text'=>$pendingLeavesCount.' en attente','class'=>'badge-warning']],
                ]
            ],
        ]
    ],

    // ── Colonne 2 : Entretiens ──
    'entretiens' => [
        'label'   => 'Entretiens',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'👤','title'=>'Entretiens annuels','freq'=>'Annuel','desc'=>'Planifier et conduire les entretiens individuels annuels avec chaque collaborateur pour évaluer performance et attentes.','link'=>'../rh_entretien_liste.php','label'=>'Planifier','badge'=>['text'=>'📋 À planifier','class'=>'badge-neutral']],
            ['icon'=>'🗓','title'=>'Entretiens professionnels','freq'=>'Tous les 2 ans','desc'=>'Réaliser l\'entretien professionnel obligatoire (formation, évolution, compétences) tous les deux ans.','link'=>'../rh_entretien_liste.php','label'=>'Suivi','badge'=>['text'=>'⚖️ Légal','class'=>'badge-info']],
            ['icon'=>'📝','title'=>'Compte-rendu & archivage','freq'=>'Après chaque entretien','desc'=>'Saisir et archiver le compte-rendu signé par les deux parties dans le dossier collaborateur.','link'=>'../rh_entretien_liste.php','label'=>'Archiver','badge'=>['text'=>'✓ Requis','class'=>'badge-success']],
        ]
    ],

    // ── Colonne 3 : Documents ──
    'documents' => [
        'label'   => 'Documents',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'📄','title'=>'Documents obligatoires','freq'=>'Janvier & renouvellement','desc'=>'Collecter : copie carte grise + attestation assurance, attestation mutuelle, certificat médical si applicable.','link'=>'../rh_documents.php','label'=>'Documents','badge'=>['text'=>'📅 Janvier','class'=>'badge-danger']],
            ['icon'=>'🚗','title'=>'Assurance & Carte Grise','freq'=>'Janvier (avant le 31)','desc'=>'Récupérer auprès de chaque collaborateur les attestations d\'assurance automobile et copies de carte grise à jour.','link'=>'../rh_users_docs.php','label'=>'Vérifier','badge'=>['text'=>'📅 Janvier','class'=>'badge-danger']],
            ['icon'=>'🏥','title'=>'Attestation mutuelle','freq'=>'Janvier — non-adhérents','desc'=>'Collecter attestations de mutuelle pour collaborateurs non adhérents à la mutuelle de la société.','link'=>'../rh_documents.php','label'=>'Documents','badge'=>['text'=>'📅 Janvier','class'=>'badge-danger']],
            ['icon'=>'⚖️','title'=>'Documents légaux','freq'=>'Suivi continu','desc'=>'S\'assurer que tous les dossiers légaux sont à jour : contrats, visites médicales, formations obligatoires.','link'=>'../rh_users_docs.php','label'=>'Documents','badge'=>['text'=>'⚠️ Vérifier','class'=>'badge-danger']],
        ]
    ],

    // ── Colonne 4 : Emails ──
    'emails' => [
        'label'   => 'Emails',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'📧','title'=>'Mail solde congés','freq'=>'Fin janvier de chaque année','desc'=>'Envoyer à tous les collaborateurs le solde des congés mis à jour. Rappeler les règles de prise de congés.','link'=>'../rh_mails.php','label'=>'Envoyer mail','badge'=>['text'=>'📅 Janvier','class'=>'badge-danger']],
        ]
    ],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard RH — MaBoxImmo v2</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/tokens.css">
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/layout.css">
  <link rel="stylesheet" href="css/theme-rh.css">
  <style>
    /* ════════════════════════════════════════════════
       RESET & BASE
    ════════════════════════════════════════════════ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg-secondary);
      color: #1a1816;
      min-height: 100vh;
      display: flex;
    }

    /* ════════════════════════════════════════════════
       LAYOUT SHELL
    ════════════════════════════════════════════════ */
    .shell {
      width: 100%;
      min-height: 100vh;
    }
    /* Zone contenu décalée de la sidebar fixe (272px) */
    .sb-content {
      margin-left: 220px;
      display: flex;
      flex-direction: column;
      height: 100vh;
      overflow: hidden;
    }

    /* Sidebar gérée par sidebar_rh.php */

    /* ════════════════════════════════════════════════
       MAIN CONTENT
    ════════════════════════════════════════════════ */
    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
      padding: 15px 36px 40px;
      gap: 0;
      overflow-y: auto;
      overflow-x: hidden;
    }

    /* ── En-tête page ── */
    .page-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin: 0 -36px 20px;
      padding: 10px 36px 12px;
      background: var(--bg-secondary);
      position: sticky;
      top: 0;
      z-index: 50;
      box-shadow: none;
      border-bottom: 1px solid rgba(196,192,186,0.25);
    }
    .page-head-module {
      font-family: 'DM Mono', monospace;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: 0.22em;
      color: #a8a49e;
      margin-bottom: 4px;
    }
    .page-head-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .page-head-title {
      font-family: 'Sora'; font-size: 20px; font-weight: 700;
      color: #1a1816; line-height: 1.2;
    }
    .badge-rh {
      display: inline-flex; align-items: center;
      padding: 3px 11px; border-radius: 999px;
      background: #36577d; color: #ffffff;
      font-family: 'DM Mono', monospace; font-size: 9px;
      letter-spacing: 0.12em; text-transform: uppercase;
    }
    .page-head-date {
      font-family: 'DM Mono', monospace;
      font-size: 10px; color: #a8a49e;
      letter-spacing: 0.1em;
      padding-top: 6px;
    }

    /* ── Section title ── */
    .section-title {
      display: flex; align-items: center; gap: 14px;
      margin: 28px 0 16px;
    }
    .sec-txt {
      font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
      letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
      background: linear-gradient(180deg, #7aadc8 0%, #36577d 50%, #2b4466 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .line-l {
      height: 1px; width: 28px; flex-shrink: 0;
      background: linear-gradient(90deg, transparent 0%, #7aadc8 100%);
      border-radius: 2px;
    }
    .line-r {
      height: 1px; flex: 1;
      background: linear-gradient(90deg, #7aadc8 0%, #b0cce2 60%, transparent 100%);
      border-radius: 2px;
    }

    /* ── KPI 2×2 ── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
      margin-bottom: 4px;
    }
    .kpi-card {
      background: var(--bg-primary);
      border-radius: 16px;
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      padding: 14px 16px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .kpi-bullet {
      width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
    }
    .kpi-body { flex: 1; }
    .kpi-label {
      font-size: 12px; font-weight: 600; color: #1a1816;
      letter-spacing: 0.02em; line-height: 1.3;
    }
    .kpi-delta {
      font-family: 'DM Mono', monospace;
      font-size: 9.5px; color: #a8a49e; margin-top: 3px;
      letter-spacing: 0.08em;
    }
    .kpi-value {
      font-family: 'DM Mono', monospace;
      font-size: 26px; font-weight: 500;
      flex-shrink: 0; line-height: 1;
    }

    /* ── Alertes ── */
    .alerts-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .alert-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      background: var(--bg-primary);
      border-radius: 14px;
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      cursor: pointer;
      border: none;
      width: 100%;
      text-align: left;
      transition: box-shadow 0.15s;
    }
    .alert-item:active {
      box-shadow: inset 5px 5px 12px var(--shadow-dark), inset -5px -5px 12px var(--shadow-light);
    }
    .alert-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    }
    .alert-body { flex: 1; min-width: 0; }
    .alert-title {
      font-size: 12px; font-weight: 600; color: #1a1816;
      line-height: 1.3;
    }
    .alert-sub {
      font-size: 10.5px; color: #8a8680; margin-top: 2px;
      line-height: 1.4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .alert-time {
      font-family: 'DM Mono', monospace;
      font-size: 9px; color: #a8a49e;
      letter-spacing: 0.1em; flex-shrink: 0;
    }

    /* ── Agenda tâches ── */
    .tasks-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      align-items: start;
    }
    .tasks-col { display: flex; flex-direction: column; gap: 10px; }

    /* Sous-groupes dans une colonne groupée */
    .tasks-subgroup { display: flex; flex-direction: column; gap: 10px; }
    .tasks-subgroup-title {
      font-family: 'DM Mono', monospace;
      font-size: 9px; font-weight: 500;
      letter-spacing: 0.22em; text-transform: uppercase;
      color: #7a9060; padding: 0 4px 6px;
      border-bottom: 1px solid rgba(196,192,186,0.5);
    }
    .tasks-subgroup-sep {
      height: 1px;
      background: linear-gradient(90deg, transparent, #c8c4be 20%, #c8c4be 80%, transparent);
      margin: 4px 0 10px;
    }

    .task-card {
      background: var(--bg-primary);
      border-radius: 20px;
      box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
      padding: 14px 16px 12px;
      display: flex; flex-direction: column; gap: 8px;
    }
    .task-header { display: flex; align-items: flex-start; gap: 10px; }
    .task-icon-wrap {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; flex-shrink: 0;
      transition: box-shadow .18s;
    }
    .task-icon-wrap:hover {
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
    }
    .task-title { font-size: 13px; font-weight: 600; color: #2f587d; line-height: 1.3; }
    .task-freq {
      font-size: 10px; color: #a0a09a; margin-top: 1px;
      font-family: 'DM Mono', monospace; letter-spacing: 0.06em;
    }
    .task-divider {
      height: 1px;
      background: linear-gradient(90deg, transparent, #ccc8c2 20%, #ccc8c2 80%, transparent);
    }
    .task-desc {
      font-size: 11px; color: #5a5650; line-height: 1.5;
      overflow: hidden; display: -webkit-box;
      -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    }
    .task-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
    .card-btn {
      padding: 0 12px; height: 28px; border-radius: 999px;
      cursor: pointer; border: none; outline: none;
      font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.06em;
      background: var(--bg-primary);
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      font-weight: 500; color: #8a8680;
      text-decoration: none; display: inline-flex; align-items: center;
    }

    /* ── Vue liste tâches ── */
    .tasks-grid-list {
      display: none;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      align-items: start;
    }
    .tasks-grid-list.active { display: grid; }
    .tasks-grid.hidden { display: none; }
    .tasks-list-col { display: flex; flex-direction: column; gap: 6px; }
    .tasks-list-col-header {
      font-size: 10px; font-weight: 600; text-transform: uppercase;
      letter-spacing: 0.18em; color: #7a9060;
      font-family: 'DM Mono', monospace;
      padding: 0 6px 8px;
      border-bottom: 1px solid rgba(196,192,186,0.4);
      margin-bottom: 4px;
    }
    .task-row {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 12px; min-height: 42px;
      border-radius: 10px; background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
    }
    .task-row.done {
      opacity: 0.45;
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
    }
    .task-row-icon { font-size: 16px; flex-shrink: 0; }
    .task-row-title { flex: 1; font-size: 12px; font-weight: 600; color: #2f587d; line-height: 1.3; }
    .task-row.done .task-row-title { text-decoration: line-through; color: #8a8680; }
    .task-row .badge,
    .task-card .badge { font-size: 9px; padding: 2px 7px; flex-shrink: 0; background: #ffffff; box-shadow: inset 1px 1px 3px var(--shadow-dark), inset -1px -1px 3px var(--shadow-light); }

    /* mini toggle */
    .mini-toggle { display: flex; align-items: center; cursor: pointer; flex-shrink: 0; margin-left: 4px; }
    .mini-toggle input { display: none; }
    .mini-toggle-track {
      width: 34px; height: 18px; border-radius: 9px;
      background: var(--bg-primary);
      box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
      position: relative;
    }
    .mini-toggle-track::after {
      content: ''; position: absolute; width: 12px; height: 12px;
      border-radius: 50%; background: #7aadc8;
      box-shadow: 2px 2px 4px #5a8daa, -1px -1px 3px #a0cde0;
      top: 3px; left: 3px; transition: left 0.28s, background 0.25s;
    }
    .mini-toggle input:checked + .mini-toggle-track { background: #f0c4c4; box-shadow: inset 2px 2px 5px #cc8888, inset -2px -2px 5px #fce8e8; }
    .mini-toggle input:checked + .mini-toggle-track::after { left: 19px; background: #cc5c58; }

    /* toggle vue */
    .view-toggle {
      display: flex; gap: 3px; background: var(--bg-primary);
      border-radius: 10px;
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      padding: 4px; flex-shrink: 0;
    }
    .view-btn {
      width: 32px; height: 28px; border-radius: 7px; border: none;
      background: transparent; cursor: pointer;
      color: #8a8680; display: flex; align-items: center; justify-content: center;
    }
    .view-btn svg { width: 16px; height: 16px; }
    .view-btn.active { background: var(--bg-primary); box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light); color: #2f587d; }

    .agenda-header {
      display: flex; align-items: center; gap: 12px;
      margin: 28px 0 16px;
    }
    .agenda-header .section-title { flex: 1; margin: 0; }

    /* footer */
    .page-footer {
      margin-top: 40px;
      padding-top: 14px;
      border-top: 1px solid rgba(196,192,186,0.35);
      display: flex; align-items: center; justify-content: space-between;
      font-size: 11px; color: #a8a49e;
      font-family: 'DM Mono', monospace; letter-spacing: 0.06em;
    }

    /* ── Topbar ── */
    .topbar {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0 28px 0 20px;
      height: 56px;
      background: var(--bg-primary);
      box-shadow: 0 4px 12px rgba(196,192,186,0.45);
      position: sticky;
      top: 0;
      z-index: 100;
      flex-shrink: 0;
    }
    /* Bouton retour */
    .topbar-back {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; border: none; flex-shrink: 0;
      color: #8a8680;
    }
    .topbar-back:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .topbar-back svg { width: 15px; height: 15px; stroke: #9aaa84; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .topbar-gap { width: 50px; flex-shrink: 0; }

    /* Toolbar "Nouveau" dans le page-head */
    .nouveau-group {
      display: flex;
      background: var(--bg-primary);
      box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      border-radius: 16px;
      padding: 8px 16px;
      gap: 14px;
      align-items: center;
    }
    .nouveau-label {
      font-family: 'Sora', sans-serif;
      font-size: 16px; font-weight: 600;
      color: #2e5471;
      padding: 0 12px 0 4px; white-space: nowrap;
    }
    .nouveau-sep {
      width: 1px; align-self: stretch; margin: 2px 4px;
      background: linear-gradient(180deg, transparent, #c8c4be 30%, #c8c4be 70%, transparent);
    }
    .nouveau-btn {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      position: relative; text-decoration: none;
    }
    .nouveau-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .nouveau-btn svg { width: 15px; height: 15px; stroke: #6a6660; fill: none; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    .nouveau-btn[title]:hover::after {
      content: attr(title);
      position: absolute; bottom: -28px; left: 50%; transform: translateX(-50%);
      background: #2a2826; color: #ffffff;
      font-family: 'DM Mono', monospace; font-size: 8px; letter-spacing: 0.08em;
      padding: 3px 7px; border-radius: 5px; white-space: nowrap;
      pointer-events: none; z-index: 200;
    }
    .topbar-breadcrumb {
      display: flex;
      align-items: center;
      gap: 8px;
      font-family: 'DM Mono', monospace;
      font-size: 13px;
      letter-spacing: 0.06em;
      color: #8a8680;
    }
    .topbar-breadcrumb .active {
      color: #4a6038;
      font-weight: 500;
      font-size: 14px;
    }
    .topbar-sep { color: #c8c4be; font-size: 16px; }
    .topbar-spacer { flex: 1; }
    .topbar-date {
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      color: #8a8680;
      letter-spacing: 0.06em;
    }
    .topbar-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: #36577d;
      display: flex; align-items: center; justify-content: center;
      font-family: 'DM Mono', monospace; font-size: 10px;
      color: #ffffff; font-weight: 500; letter-spacing: 0.05em;
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      flex-shrink: 0;
    }
    .topbar-notif {
      position: relative;
      width: 36px; height: 36px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; border: none; flex-shrink: 0;
    }
    .topbar-notif svg { width: 16px; height: 16px; stroke: #8a8680; fill: none; stroke-width: 1.6; }
    .topbar-notif-dot {
      position: absolute; top: 6px; right: 6px;
      width: 7px; height: 7px; border-radius: 50%;
      background: #cc5c58; border: 2px solid #ffffff;
    }

    /* two-col layout for KPI + Alerts */
    .dashboard-row {
      display: grid;
      grid-template-columns: 1fr 1.4fr;
      gap: 36px;
      align-items: start;
    }
    .dashboard-col { display: flex; flex-direction: column; }
  </style>
</head>
<body>

<div class="shell">

  <!-- ════════ SIDEBAR ════════ -->
  <?php include __DIR__ . '/sidebar_rh.php'; ?>

  <!-- ════════ MAIN WRAPPER ════════ -->
  <div class="sb-content">

    <!-- ── TOPBAR ── -->
    <header class="topbar">

      <!-- Retour (extrême gauche) -->
      <button class="topbar-back" onclick="history.back()" title="Retour">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </button>

      <!-- Espace 50px -->
      <div class="topbar-gap"></div>

      <!-- Breadcrumb -->
      <nav class="topbar-breadcrumb">
        <span>RH</span>
        <span class="topbar-sep">›</span>
        <span class="active">Dashboard</span>
      </nav>

      <div class="topbar-spacer"></div>

      <!-- Date + heure -->
      <span class="topbar-date" id="topbar-clock"></span>

      <!-- Notif -->
      <button class="topbar-notif" title="Notifications">
        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <?php if ($pendingLeavesCount > 0): ?>
          <span class="topbar-notif-dot"></span>
        <?php endif; ?>
      </button>

      <!-- Avatar -->
      <div class="topbar-avatar" title="<?= htmlspecialchars($userName) ?>">AD</div>

    </header>

  <!-- ════════ MAIN ════════ -->
  <main class="main">

    <!-- ── En-tête ── -->
    <div class="page-head">
      <div>
        <div class="page-head-module">Module</div>
        <div class="page-head-row">
          <h1 class="page-head-title">Ressources humaines</h1>
          <span class="badge-rh">RH</span>
        </div>
      </div>

      <!-- Toolbar "Nouveau" -->
      <div class="nouveau-group">
        <span class="nouveau-label">Nouveau</span>
        <div class="nouveau-sep"></div>
        <a href="rh_user_add.php" class="nouveau-btn" title="Collaborateur">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="12" y1="14" x2="12" y2="20"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        </a>
        <a href="rh_conges.php?action=new" class="nouveau-btn" title="Congé">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8" y2="14"/></svg>
        </a>
        <a href="rh_documents.php?action=upload" class="nouveau-btn" title="Document">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="12" x2="12" y2="18"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </a>
        <a href="rh_mails.php?action=new" class="nouveau-btn" title="Mail">
          <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        </a>
        <a href="rh_entretien_liste.php?action=new" class="nouveau-btn" title="Entretien">
          <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        </a>
      </div>

    </div>

    <!-- ── BLOC 1 : KPIs + Alertes ── -->
    <div class="dashboard-row">

      <!-- KPI 2×2 -->
      <div class="dashboard-col">
        <div class="section-title">
          <div class="line-l"></div>
          <span class="sec-txt">Chiffres clés</span>
          <div class="line-r"></div>
        </div>
        <div class="kpi-grid">

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#cc5c58;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Congés en attente</div>
              <div class="kpi-delta">⚑ à valider</div>
            </div>
            <div class="kpi-value" style="color:#cc5c58;"><?= $pendingLeavesCount ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#36577d;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Effectif actif</div>
              <div class="kpi-delta">↑ +1 ce mois</div>
            </div>
            <div class="kpi-value" style="color:#36577d;"><?= $activeStaffCount ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#7a9060;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Recrutements en cours</div>
              <div class="kpi-delta">postes en cours</div>
            </div>
            <div class="kpi-value" style="color:#7a9060;"><?= $recruitingCount ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#c8883a;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Entretiens restants</div>
              <div class="kpi-delta">dont 1 cette semaine</div>
            </div>
            <div class="kpi-value" style="color:#c8883a;"><?= $interviewsLeft ?></div>
          </div>

        </div>
      </div>

      <!-- Alertes -->
      <div class="dashboard-col">
        <div class="section-title">
          <div class="line-l"></div>
          <span class="sec-txt">À traiter aujourd'hui</span>
          <div class="line-r"></div>
        </div>
        <div class="alerts-list">

          <button class="alert-item">
            <span class="alert-dot" style="background:#cc5c58;"></span>
            <div class="alert-body">
              <div class="alert-title">Congés à valider — 2 demandes</div>
              <div class="alert-sub">L. Martin · M. Dubois · en attente de validation</div>
            </div>
            <span class="alert-time">urgent</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#c8883a;"></span>
            <div class="alert-body">
              <div class="alert-title">Entretien à tenir — cette semaine</div>
              <div class="alert-sub">P. Lefebvre · entretien annuel · jeudi 10 avr.</div>
            </div>
            <span class="alert-time">J−4</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#cc5c58;"></span>
            <div class="alert-body">
              <div class="alert-title">Document à renouveler</div>
              <div class="alert-sub">M. Bernard · attestation assurance · expire le 15 avr.</div>
            </div>
            <span class="alert-time">urgent</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#7a9060;"></span>
            <div class="alert-body">
              <div class="alert-title">Fin de période d'essai</div>
              <div class="alert-sub">R. Moreau · décision renouvellement · échéance 30 avr.</div>
            </div>
            <span class="alert-time">J−25</span>
          </button>

        </div>
      </div>

    </div>
    <!-- /BLOC 1 -->

    <!-- ── BLOC 2 : Agenda des tâches RH ── -->
    <div class="agenda-header">
      <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Agenda des tâches RH</span>
        <div class="line-r"></div>
      </div>
      <div class="view-toggle" id="view-toggle" role="group" aria-label="Mode d'affichage">
        <button class="view-btn" id="btn-cards" title="Vue cartes" aria-pressed="false">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/>
            <rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/>
          </svg>
        </button>
        <button class="view-btn active" id="btn-list" title="Vue liste" aria-pressed="true">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="1" y1="4" x2="15" y2="4"/><line x1="1" y1="8" x2="15" y2="8"/>
            <line x1="1" y1="12" x2="15" y2="12"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Vue CARDS -->
    <div class="tasks-grid" id="view-cards" role="list">
      <?php foreach ($taskColumns as $colKey => $col): ?>
        <div class="tasks-col">

          <?php if (!empty($col['grouped'])): ?>
            <?php foreach ($col['subgroups'] as $sgKey => $sg): ?>
              <div class="tasks-subgroup">
                <div class="tasks-subgroup-title"><?= htmlspecialchars($sg['label']) ?></div>
                <?php foreach ($sg['tasks'] as $task): ?>
                  <article class="task-card" role="listitem">
                    <div class="task-header">
                      <div class="task-icon-wrap" aria-hidden="true"><?= $task['icon'] ?></div>
                      <div style="flex:1;">
                        <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                        <div class="task-freq"><?= htmlspecialchars($task['freq']) ?></div>
                      </div>
                      <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                    </div>
                    <div class="task-divider"></div>
                    <p class="task-desc"><?= htmlspecialchars($task['desc']) ?></p>
                    <div class="task-footer">
                      <a href="<?= htmlspecialchars($task['link']) ?>" class="card-btn"><?= htmlspecialchars($task['label']) ?> →</a>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
              <?php if ($sgKey !== array_key_last($col['subgroups'])): ?>
                <div class="tasks-subgroup-sep"></div>
              <?php endif; ?>
            <?php endforeach; ?>

          <?php else: ?>
            <?php foreach ($col['tasks'] as $task): ?>
              <article class="task-card" role="listitem">
                <div class="task-header">
                  <div class="task-icon-wrap" aria-hidden="true"><?= $task['icon'] ?></div>
                  <div style="flex:1;">
                    <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                    <div class="task-freq"><?= htmlspecialchars($task['freq']) ?></div>
                  </div>
                  <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                </div>
                <div class="task-divider"></div>
                <p class="task-desc"><?= htmlspecialchars($task['desc']) ?></p>
                <div class="task-footer">
                  <a href="<?= htmlspecialchars($task['link']) ?>" class="card-btn"><?= htmlspecialchars($task['label']) ?> →</a>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>

        </div>
      <?php endforeach; ?>
    </div>

    <!-- Vue LISTE -->
    <div class="tasks-grid-list" id="view-list" role="list">
      <?php foreach ($taskColumns as $colKey => $col): ?>
        <div class="tasks-list-col" data-col="<?= $colKey ?>">
          <div class="tasks-list-col-header"><?= htmlspecialchars($col['label']) ?></div>

          <?php if (!empty($col['grouped'])): ?>
            <?php foreach ($col['subgroups'] as $sg): ?>
              <div style="font-family:'DM Mono',monospace;font-size:8px;letter-spacing:0.18em;text-transform:uppercase;color:#a8a49e;padding:6px 6px 4px;margin-top:4px;"><?= htmlspecialchars($sg['label']) ?></div>
              <?php foreach ($sg['tasks'] as $task): ?>
                <div class="task-row" data-done="false" role="listitem">
                  <span class="task-row-icon" aria-hidden="true"><?= $task['icon'] ?></span>
                  <span class="task-row-title" title="<?= htmlspecialchars($task['title']) ?>"><?= htmlspecialchars($task['title']) ?></span>
                  <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                  <label class="mini-toggle" title="Marquer comme fait"><input type="checkbox"><span class="mini-toggle-track"></span></label>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>

          <?php else: ?>
            <?php foreach ($col['tasks'] as $task): ?>
              <div class="task-row" data-done="false" role="listitem">
                <span class="task-row-icon" aria-hidden="true"><?= $task['icon'] ?></span>
                <span class="task-row-title" title="<?= htmlspecialchars($task['title']) ?>"><?= htmlspecialchars($task['title']) ?></span>
                <span class="badge <?= $task['badge']['class'] ?>"><?= htmlspecialchars($task['badge']['text']) ?></span>
                <label class="mini-toggle" title="Marquer comme fait"><input type="checkbox"><span class="mini-toggle-track"></span></label>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

        </div>
      <?php endforeach; ?>
    </div>

    <footer class="page-footer">
      <span>© <?= date('Y') ?> MaBoxImmo — Tous droits réservés</span>
      <span style="color:#7a9060;">v2.0 · <?= date('d/m/Y') ?></span>
    </footer>

  </main>
  <!-- /MAIN -->

  </div><!-- /MAIN WRAPPER -->

</div>
<!-- /SHELL -->

<script src="js/components.js"></script>
<script>
(function () {
  const btnCards  = document.getElementById('btn-cards');
  const btnList   = document.getElementById('btn-list');
  const viewCards = document.getElementById('view-cards');
  const viewList  = document.getElementById('view-list');

  function showCards() {
    viewCards.classList.remove('hidden');
    viewList.classList.remove('active');
    btnCards.classList.add('active');    btnCards.setAttribute('aria-pressed','true');
    btnList.classList.remove('active'); btnList.setAttribute('aria-pressed','false');
  }
  function showList() {
    viewCards.classList.add('hidden');
    viewList.classList.add('active');
    btnList.classList.add('active');    btnList.setAttribute('aria-pressed','true');
    btnCards.classList.remove('active'); btnCards.setAttribute('aria-pressed','false');
  }

  btnCards.addEventListener('click', showCards);
  btnList.addEventListener('click', showList);
  showList();

  function sortCol(col) {
    const rows  = Array.from(col.querySelectorAll('.task-row'));
    const undone = rows.filter(r => r.dataset.done !== 'true');
    const done   = rows.filter(r => r.dataset.done === 'true');
    [...undone, ...done].forEach(r => col.appendChild(r));
  }

  document.querySelectorAll('.mini-toggle input').forEach(function (cb) {
    cb.addEventListener('change', function () {
      const row = this.closest('.task-row');
      const col = this.closest('.tasks-list-col');
      row.dataset.done = this.checked ? 'true' : 'false';
      row.classList.toggle('done', this.checked);
      sortCol(col);
    });
  });

  /* Horloge FR */
  (function () {
    const el = document.getElementById('topbar-clock');
    const jours  = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
    const mois   = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    function tick() {
      const now = new Date();
      const j   = jours[now.getDay()];
      const d   = String(now.getDate()).padStart(2, '0');
      const m   = mois[now.getMonth()];
      const y   = now.getFullYear();
      const hh  = String(now.getHours()).padStart(2, '0');
      const mm  = String(now.getMinutes()).padStart(2, '0');
      const ss  = String(now.getSeconds()).padStart(2, '0');
      el.textContent = j + ' ' + d + ' ' + m + ' ' + y + '  —  ' + hh + ':' + mm + ':' + ss;
    }
    tick();
    setInterval(tick, 1000);
  })();

  /* Effet enfoncement alert-item au clic */
  document.querySelectorAll('.alert-item').forEach(function (btn) {
    btn.addEventListener('mousedown', function () {
      this.style.boxShadow = 'inset 5px 5px 12px var(--shadow-dark), inset -5px -5px 12px var(--shadow-light)';
    });
    btn.addEventListener('mouseup', function () {
      this.style.boxShadow = '';
    });
    btn.addEventListener('mouseleave', function () {
      this.style.boxShadow = '';
    });
  });
})();
</script>
</body>
</html>
