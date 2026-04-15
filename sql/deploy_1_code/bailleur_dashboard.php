<?php
/*
 * bailleur_dashboard.php  (v2)
 * Rôle     : Dashboard Gestion Bailleur — design system v2 neumorphique
 * Date     : 2026-04-05
 *
 * Standalone : fonctionne sans connexion BDD (données de démo).
 */

// ── Page courante (pour la sidebar) ─────────────────────────────
$current_page = 'dashboard';

// ── Données de démo ──────────────────────────────────────────────
$biensGeres       = 23;
$loyersEnAttente  = 4;
$bauxExpirant     = 2;
$tauxOccupation   = 91;
$userName         = 'Admin Demo';

// ── Agenda des tâches Bailleur ───────────────────────────────────
$taskColumns = [

    // ── Colonne 1 : Locations & Loyers groupés ──
    'locations_loyers' => [
        'label'     => 'Locations & Loyers',
        'grouped'   => true,
        'subgroups' => [
            'locations' => [
                'label' => 'Locations',
                'tasks' => [
                    ['icon'=>'🔑','title'=>'État des lieux entrée/sortie','freq'=>'À chaque changement','desc'=>'Réaliser l\'état des lieux d\'entrée ou de sortie avec le locataire. Documenter photographiquement chaque pièce.','link'=>'../bailleur_edl.php','label'=>'Planifier','badge'=>['text'=>'→ À planifier','class'=>'badge-neutral']],
                    ['icon'=>'🏠','title'=>'Remise des clés','freq'=>'Entrée/sortie','desc'=>'Organiser la remise des clés avec le locataire entrant ou sortant. Vérifier le relevé des compteurs.','link'=>'../bailleur_biens.php','label'=>'Gérer','badge'=>['text'=>'✓ Requis','class'=>'badge-success']],
                ]
            ],
            'loyers' => [
                'label' => 'Loyers',
                'tasks' => [
                    ['icon'=>'📄','title'=>'Émission quittances','freq'=>'1er du mois','desc'=>'Émettre et envoyer les quittances de loyer à tous les locataires. Vérifier la réception des paiements.','link'=>'../bailleur_quittances.php','label'=>'Émettre','badge'=>['text'=>'📅 1er du mois','class'=>'badge-info']],
                    ['icon'=>'⚑','title'=>'Relance impayés','freq'=>'À tout moment','desc'=>'Envoyer les relances aux locataires en retard de paiement. Escalader vers mise en demeure si nécessaire.','link'=>'../bailleur_loyers.php?filtre=impayes','label'=>'Relancer','badge'=>['text'=>$loyersEnAttente.' en attente','class'=>'badge-danger']],
                ]
            ],
        ]
    ],

    // ── Colonne 2 : Baux & Contrats ──
    'baux_contrats' => [
        'label'   => 'Baux & Contrats',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'📝','title'=>'Rédaction bail','freq'=>'Nouvelle location','desc'=>'Rédiger le contrat de bail selon la réglementation en vigueur (loi Alur, bail meublé ou nu). Joindre les annexes obligatoires.','link'=>'../bailleur_baux.php?action=new','label'=>'Rédiger','badge'=>['text'=>'⚖️ Légal','class'=>'badge-info']],
            ['icon'=>'🔄','title'=>'Renouvellement bail','freq'=>'3 mois avant échéance','desc'=>'Adresser la proposition de renouvellement ou le congé au locataire 3 mois avant l\'échéance du bail.','link'=>'../bailleur_baux.php','label'=>'Renouveler','badge'=>['text'=>'2 à renouveler','class'=>'badge-warning']],
            ['icon'=>'📈','title'=>'Révision loyer IRL','freq'=>'Annuel','desc'=>'Calculer et appliquer la révision annuelle du loyer selon l\'Indice de Référence des Loyers (IRL) publié par l\'INSEE.','link'=>'../bailleur_loyers.php?action=revision','label'=>'Réviser','badge'=>['text'=>'📅 Annuel','class'=>'badge-info']],
            ['icon'=>'📬','title'=>'Congé locataire','freq'=>'Préavis légal','desc'=>'Gérer les préavis de départ des locataires : accusé de réception, planification EDL sortant, remise du dépôt de garantie.','link'=>'../bailleur_baux.php','label'=>'Traiter','badge'=>['text'=>'→ Suivi','class'=>'badge-neutral']],
        ]
    ],

    // ── Colonne 3 : Travaux & Entretien ──
    'travaux_entretien' => [
        'label'   => 'Travaux & Entretien',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'🔥','title'=>'Entretien chaudière','freq'=>'Annuel (obligatoire)','desc'=>'Planifier la visite annuelle obligatoire de la chaudière par un professionnel qualifié. Archiver l\'attestation.','link'=>'../bailleur_entretien.php','label'=>'Planifier','badge'=>['text'=>'⚖️ Obligatoire','class'=>'badge-danger']],
            ['icon'=>'🔧','title'=>'Réparations locatives','freq'=>'À la demande','desc'=>'Traiter les demandes de réparations des locataires. Distinguer réparations locatives (locataire) et grosses réparations (bailleur).','link'=>'../bailleur_travaux.php','label'=>'Gérer','badge'=>['text'=>'→ En cours','class'=>'badge-warning']],
            ['icon'=>'📋','title'=>'Devis artisans','freq'=>'Avant travaux','desc'=>'Solliciter plusieurs devis d\'artisans pour les travaux à la charge du bailleur. Comparer et sélectionner le prestataire.','link'=>'../bailleur_travaux.php','label'=>'Comparer','badge'=>['text'=>'📋 À comparer','class'=>'badge-neutral']],
            ['icon'=>'✅','title'=>'Réception travaux','freq'=>'Fin de chantier','desc'=>'Vérifier la bonne exécution des travaux avant de valider la facture. Établir le procès-verbal de réception.','link'=>'../bailleur_travaux.php','label'=>'Réceptionner','badge'=>['text'=>'✓ Requis','class'=>'badge-success']],
        ]
    ],

    // ── Colonne 4 : Fiscal & Admin ──
    'fiscal_admin' => [
        'label'   => 'Fiscal & Admin',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'🧾','title'=>'Déclaration revenus fonciers','freq'=>'Annuel (mai)','desc'=>'Préparer la déclaration des revenus fonciers (formulaire 2044). Rassembler toutes les quittances et charges.','link'=>'../bailleur_fiscal.php','label'=>'Préparer','badge'=>['text'=>'📅 Mai','class'=>'badge-danger']],
            ['icon'=>'💼','title'=>'Charges déductibles','freq'=>'Continu','desc'=>'Suivre et documenter les charges déductibles des revenus fonciers : travaux, intérêts d\'emprunt, assurances, frais de gestion.','link'=>'../bailleur_fiscal.php','label'=>'Gérer','badge'=>['text'=>'→ En cours','class'=>'badge-warning']],
            ['icon'=>'🛡','title'=>'Assurance PNO','freq'=>'Annuel','desc'=>'Vérifier que chaque bien est couvert par une assurance Propriétaire Non Occupant. Renouveler avant échéance.','link'=>'../bailleur_assurances.php','label'=>'Vérifier','badge'=>['text'=>'📅 Annuel','class'=>'badge-info']],
            ['icon'=>'📊','title'=>'Reporting propriétaire','freq'=>'Mensuel','desc'=>'Éditer le rapport mensuel pour chaque propriétaire : loyers encaissés, charges payées, solde net de gérance.','link'=>'../bailleur_reporting.php','label'=>'Générer','badge'=>['text'=>'📅 Mensuel','class'=>'badge-info']],
        ]
    ],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Bailleur — MaBoxImmo v2</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/tokens.css">
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/layout.css">
  <link rel="stylesheet" href="css/theme-rh.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg-secondary);
      color: #1a1816;
      min-height: 100vh;
      display: flex;
    }

    .shell { width: 100%; min-height: 100vh; }
    .sb-content {
      margin-left: 220px;
      display: flex;
      flex-direction: column;
      height: 100vh;
      overflow: hidden;
    }

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
      font-family: 'DM Mono', monospace; font-size: 9px;
      text-transform: uppercase; letter-spacing: 0.22em; color: #a8a49e; margin-bottom: 4px;
    }
    .page-head-row { display: flex; align-items: center; gap: 10px; }
    .page-head-title { font-family: 'Sora'; font-size: 20px; font-weight: 700; color: #1a1816; line-height: 1.2; }
    .badge-module {
      display: inline-flex; align-items: center;
      padding: 3px 11px; border-radius: 999px;
      background: #4e6e90; color: var(--bg-primary);
      font-family: 'DM Mono', monospace; font-size: 9px;
      letter-spacing: 0.12em; text-transform: uppercase;
    }

    .section-title { display: flex; align-items: center; gap: 14px; margin: 28px 0 16px; }
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
      box-shadow: 0 -1px 0 rgba(154,184,112,0.6), 0 1px 0 rgba(30,48,20,0.45), 0 0 5px rgba(74,96,56,0.35);
    }
    .line-r {
      height: 1.5px; flex: 1;
      background: linear-gradient(90deg, #9ab870 0%, #607848 30%, #4a6038 55%, transparent 100%);
      border-radius: 2px;
      box-shadow: 0 -1px 0 rgba(154,184,112,0.5), 0 1px 0 rgba(30,48,20,0.35), 0 0 5px rgba(74,96,56,0.3);
    }

    .kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 4px; }
    .kpi-card { background: var(--bg-primary); border-radius: 16px; box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light); padding: 14px 16px; display: flex; align-items: center; gap: 14px; }
    .kpi-bullet { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .kpi-body { flex: 1; }
    .kpi-label { font-size: 12px; font-weight: 600; color: #1a1816; letter-spacing: 0.02em; line-height: 1.3; }
    .kpi-delta { font-family: 'DM Mono', monospace; font-size: 9.5px; color: #a8a49e; margin-top: 3px; letter-spacing: 0.08em; }
    .kpi-value { font-family: 'DM Mono', monospace; font-size: 26px; font-weight: 500; flex-shrink: 0; line-height: 1; }

    .alerts-list { display: flex; flex-direction: column; gap: 10px; }
    .alert-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--bg-primary); border-radius: 14px; box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light); cursor: pointer; border: none; width: 100%; text-align: left; transition: box-shadow 0.15s; }
    .alert-item:active { box-shadow: inset 5px 5px 12px var(--shadow-dark), inset -5px -5px 12px var(--shadow-light); }
    .alert-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .alert-body { flex: 1; min-width: 0; }
    .alert-title { font-size: 12px; font-weight: 600; color: #1a1816; line-height: 1.3; }
    .alert-sub { font-size: 10.5px; color: #8a8680; margin-top: 2px; line-height: 1.4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .alert-time { font-family: 'DM Mono', monospace; font-size: 9px; color: #a8a49e; letter-spacing: 0.1em; flex-shrink: 0; }

    .tasks-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; align-items: start; }
    .tasks-col { display: flex; flex-direction: column; gap: 10px; }
    .tasks-subgroup { display: flex; flex-direction: column; gap: 10px; }
    .tasks-subgroup-title { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.22em; text-transform: uppercase; color: #7a9060; padding: 0 4px 6px; border-bottom: 1px solid rgba(196,192,186,0.5); }
    .tasks-subgroup-sep { height: 1px; background: linear-gradient(90deg, transparent, #c8c4be 20%, #c8c4be 80%, transparent); margin: 4px 0 10px; }

    .task-card { background: var(--bg-primary); border-radius: 20px; box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light); padding: 14px 16px 12px; display: flex; flex-direction: column; gap: 8px; }
    .task-header { display: flex; align-items: flex-start; gap: 10px; }
    .task-icon-wrap { width: 36px; height: 36px; border-radius: 50%; background: var(--bg-primary); box-shadow: inset 3px 3px 8px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; margin-top: 2px; }
    .task-title { font-size: 13px; font-weight: 600; color: #2f587d; line-height: 1.3; }
    .task-freq { font-size: 10px; color: #a0a09a; margin-top: 1px; font-family: 'DM Mono', monospace; letter-spacing: 0.06em; }
    .task-divider { height: 1px; background: linear-gradient(90deg, transparent, #ccc8c2 20%, #ccc8c2 80%, transparent); }
    .task-desc { font-size: 11px; color: #5a5650; line-height: 1.5; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .task-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
    .card-btn { padding: 0 12px; height: 28px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.06em; background: var(--bg-primary); box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); font-weight: 500; color: #8a8680; text-decoration: none; display: inline-flex; align-items: center; }

    .tasks-grid-list { display: none; grid-template-columns: repeat(4, 1fr); gap: 16px; align-items: start; }
    .tasks-grid-list.active { display: grid; }
    .tasks-grid.hidden { display: none; }
    .tasks-list-col { display: flex; flex-direction: column; gap: 6px; }
    .tasks-list-col-header { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.18em; color: #7a9060; font-family: 'DM Mono', monospace; padding: 0 6px 8px; border-bottom: 1px solid rgba(196,192,186,0.4); margin-bottom: 4px; }
    .task-row { display: flex; align-items: center; gap: 8px; padding: 8px 12px; min-height: 42px; border-radius: 10px; background: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light); }
    .task-row.done { opacity: 0.45; box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .task-row-icon { font-size: 16px; flex-shrink: 0; }
    .task-row-title { flex: 1; font-size: 12px; font-weight: 600; color: #2f587d; line-height: 1.3; }
    .task-row.done .task-row-title { text-decoration: line-through; color: #8a8680; }
    .task-row .badge { font-size: 9px; padding: 2px 7px; flex-shrink: 0; }

    .mini-toggle { display: flex; align-items: center; cursor: pointer; flex-shrink: 0; margin-left: 4px; }
    .mini-toggle input { display: none; }
    .mini-toggle-track { width: 34px; height: 18px; border-radius: 9px; background: var(--bg-primary); box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); position: relative; }
    .mini-toggle-track::after { content: ''; position: absolute; width: 12px; height: 12px; border-radius: 50%; background: var(--bg-primary); box-shadow: 2px 2px 4px var(--shadow-dark), -1px -1px 3px var(--shadow-light); top: 3px; left: 3px; transition: left 0.28s, background 0.25s; }
    .mini-toggle input:checked + .mini-toggle-track { background: #b2d4b7; box-shadow: inset 2px 2px 5px #8aac8f, inset -2px -2px 5px #d8f0dc; }
    .mini-toggle input:checked + .mini-toggle-track::after { left: 19px; background: #4a6038; }

    .view-toggle { display: flex; gap: 3px; background: var(--bg-primary); border-radius: 10px; box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); padding: 4px; flex-shrink: 0; }
    .view-btn { width: 32px; height: 28px; border-radius: 7px; border: none; background: transparent; cursor: pointer; color: #8a8680; display: flex; align-items: center; justify-content: center; }
    .view-btn svg { width: 16px; height: 16px; }
    .view-btn.active { background: var(--bg-primary); box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light); color: #2f587d; }

    .agenda-header { display: flex; align-items: center; gap: 12px; margin: 28px 0 16px; }
    .agenda-header .section-title { flex: 1; margin: 0; }

    .page-footer { margin-top: 40px; padding-top: 14px; border-top: 1px solid rgba(196,192,186,0.35); display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: #a8a49e; font-family: 'DM Mono', monospace; letter-spacing: 0.06em; }

    .topbar { display: flex; align-items: center; gap: 12px; padding: 0 28px 0 20px; height: 56px; background: var(--bg-primary); box-shadow: 0 4px 12px rgba(196,192,186,0.45); position: sticky; top: 0; z-index: 100; flex-shrink: 0; }
    .topbar-back { width: 34px; height: 34px; border-radius: 10px; background: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light); display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
    .topbar-back:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .topbar-back svg { width: 15px; height: 15px; stroke: #9aaa84; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .topbar-gap { width: 50px; flex-shrink: 0; }

    .nouveau-group { display: flex; background: var(--bg-primary); box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light); border-radius: 16px; padding: 8px 16px; gap: 14px; align-items: center; }
    .nouveau-label { font-family: 'Sora', sans-serif; font-size: 16px; font-weight: 600; color: #2e5471; padding: 0 12px 0 4px; white-space: nowrap; }
    .nouveau-sep { width: 1px; align-self: stretch; margin: 2px 4px; background: linear-gradient(180deg, transparent, #c8c4be 30%, #c8c4be 70%, transparent); }
    .nouveau-btn { width: 34px; height: 34px; border-radius: 10px; background: var(--bg-primary); box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative; text-decoration: none; }
    .nouveau-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .nouveau-btn svg { width: 15px; height: 15px; stroke: #6a6660; fill: none; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    .nouveau-btn[title]:hover::after { content: attr(title); position: absolute; bottom: -28px; left: 50%; transform: translateX(-50%); background: #2a2826; color: var(--bg-primary); font-family: 'DM Mono', monospace; font-size: 8px; letter-spacing: 0.08em; padding: 3px 7px; border-radius: 5px; white-space: nowrap; pointer-events: none; z-index: 200; }
    .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; font-family: 'DM Mono', monospace; font-size: 13px; letter-spacing: 0.06em; color: #8a8680; }
    .topbar-breadcrumb .active { color: #4a6038; font-weight: 500; font-size: 14px; }
    .topbar-sep { color: #c8c4be; font-size: 16px; }
    .topbar-spacer { flex: 1; }
    .topbar-date { font-family: 'DM Mono', monospace; font-size: 12px; color: #8a8680; letter-spacing: 0.06em; }
    .topbar-avatar { width: 32px; height: 32px; border-radius: 50%; background: #4e6e90; display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 10px; color: #ffffff; font-weight: 500; letter-spacing: 0.05em; box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); flex-shrink: 0; }
    .topbar-notif { position: relative; width: 36px; height: 36px; border-radius: 10px; background: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light); display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
    .topbar-notif svg { width: 16px; height: 16px; stroke: #8a8680; fill: none; stroke-width: 1.6; }
    .topbar-notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: #cc5c58; border: 2px solid var(--bg-primary); }

    .dashboard-row { display: grid; grid-template-columns: 1fr 1.4fr; gap: 36px; align-items: start; }
    .dashboard-col { display: flex; flex-direction: column; }
  </style>
</head>
<body>

<div class="shell">

  <?php include 'sidebar_bailleur.php'; ?>

  <div class="sb-content">

    <header class="topbar">

      <button class="topbar-back" onclick="history.back()" title="Retour">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </button>

      <div class="topbar-gap"></div>

      <nav class="topbar-breadcrumb">
        <span>Bailleur</span>
        <span class="topbar-sep">›</span>
        <span class="active">Dashboard</span>
      </nav>

      <div class="topbar-spacer"></div>

      <span class="topbar-date" id="topbar-clock"></span>

      <button class="topbar-notif" title="Notifications">
        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        <span class="topbar-notif-dot"></span>
      </button>

      <div class="topbar-avatar" title="<?= htmlspecialchars($userName) ?>">AD</div>

    </header>

  <main class="main">

    <div class="page-head">
      <div>
        <div class="page-head-module">Module</div>
        <div class="page-head-row">
          <h1 class="page-head-title">Gestion Bailleur</h1>
          <span class="badge-module">BAILLEUR</span>
        </div>
      </div>

      <div class="nouveau-group">
        <span class="nouveau-label">Nouveau</span>
        <div class="nouveau-sep"></div>
        <a href="../bailleur_biens.php?action=new" class="nouveau-btn" title="Bien">
          <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </a>
        <a href="../bailleur_locataires.php?action=new" class="nouveau-btn" title="Locataire">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="12" y1="14" x2="12" y2="20"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        </a>
        <a href="../bailleur_quittances.php?action=new" class="nouveau-btn" title="Quittance">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="12" x2="12" y2="18"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </a>
        <a href="../bailleur_baux.php?action=new" class="nouveau-btn" title="Bail">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/><polyline points="7 7 7 7"/></svg>
        </a>
      </div>

    </div>

    <div class="dashboard-row">

      <div class="dashboard-col">
        <div class="section-title">
          <div class="line-l"></div>
          <span class="sec-txt">Chiffres clés</span>
          <div class="line-r"></div>
        </div>
        <div class="kpi-grid">

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#4e6e90;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Biens gérés</div>
              <div class="kpi-delta">↑ +2 ce mois</div>
            </div>
            <div class="kpi-value" style="color:#4e6e90;"><?= $biensGeres ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#cc5c58;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Loyers en attente</div>
              <div class="kpi-delta">⚑ à relancer</div>
            </div>
            <div class="kpi-value" style="color:#cc5c58;"><?= $loyersEnAttente ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#c8883a;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Baux expirant</div>
              <div class="kpi-delta">dans 90 jours</div>
            </div>
            <div class="kpi-value" style="color:#c8883a;"><?= $bauxExpirant ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#7a9060;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Taux d'occupation</div>
              <div class="kpi-delta">% du parc</div>
            </div>
            <div class="kpi-value" style="color:#7a9060;"><?= $tauxOccupation ?></div>
          </div>

        </div>
      </div>

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
              <div class="alert-title">Loyer impayé · T. Rousseau</div>
              <div class="alert-sub">2 mois de retard · mise en demeure</div>
            </div>
            <span class="alert-time">urgent</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#c8883a;"></span>
            <div class="alert-body">
              <div class="alert-title">Bail à renouveler · Appt. 12 rue Verte</div>
              <div class="alert-sub">échéance 30 juin · J−86</div>
            </div>
            <span class="alert-time">J−86</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#cc5c58;"></span>
            <div class="alert-body">
              <div class="alert-title">DPE à renouveler · Maison 4 Chemin des Acacias</div>
              <div class="alert-sub">obligatoire · urgent</div>
            </div>
            <span class="alert-time">urgent</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#7a9060;"></span>
            <div class="alert-body">
              <div class="alert-title">Visite de contrôle · Loc. M. Lambert</div>
              <div class="alert-sub">planifier état des lieux intermédiaire · J−15</div>
            </div>
            <span class="alert-time">J−15</span>
          </button>

        </div>
      </div>

    </div>

    <div class="agenda-header">
      <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Agenda des tâches Bailleur</span>
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
      <span style="color:#4e6e90;">v2.0 · <?= date('d/m/Y') ?></span>
    </footer>

  </main>

  </div>

</div>

<script src="js/components.js"></script>
<script>
(function () {
  const btnCards  = document.getElementById('btn-cards');
  const btnList   = document.getElementById('btn-list');
  const viewCards = document.getElementById('view-cards');
  const viewList  = document.getElementById('view-list');

  function showCards() {
    viewCards.classList.remove('hidden'); viewList.classList.remove('active');
    btnCards.classList.add('active'); btnCards.setAttribute('aria-pressed','true');
    btnList.classList.remove('active'); btnList.setAttribute('aria-pressed','false');
  }
  function showList() {
    viewCards.classList.add('hidden'); viewList.classList.add('active');
    btnList.classList.add('active'); btnList.setAttribute('aria-pressed','true');
    btnCards.classList.remove('active'); btnCards.setAttribute('aria-pressed','false');
  }

  btnCards.addEventListener('click', showCards);
  btnList.addEventListener('click', showList);
  showList();

  function sortCol(col) {
    const rows = Array.from(col.querySelectorAll('.task-row'));
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

  (function () {
    const el = document.getElementById('topbar-clock');
    const jours = ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'];
    const mois  = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    function tick() {
      const now = new Date();
      const j  = jours[now.getDay()];
      const d  = String(now.getDate()).padStart(2, '0');
      const m  = mois[now.getMonth()];
      const y  = now.getFullYear();
      const hh = String(now.getHours()).padStart(2, '0');
      const mm = String(now.getMinutes()).padStart(2, '0');
      const ss = String(now.getSeconds()).padStart(2, '0');
      el.textContent = j + ' ' + d + ' ' + m + ' ' + y + '  —  ' + hh + ':' + mm + ':' + ss;
    }
    tick(); setInterval(tick, 1000);
  })();

  document.querySelectorAll('.alert-item').forEach(function (btn) {
    btn.addEventListener('mousedown', function () { this.style.boxShadow = 'inset 5px 5px 12px var(--shadow-dark), inset -5px -5px 12px var(--shadow-light)'; });
    btn.addEventListener('mouseup', function () { this.style.boxShadow = ''; });
    btn.addEventListener('mouseleave', function () { this.style.boxShadow = ''; });
  });
})();
</script>
</body>
</html>
