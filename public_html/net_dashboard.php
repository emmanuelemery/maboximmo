<?php
/*
 * net_dashboard.php  (v2)
 * Rôle     : Dashboard Diffusion Net — design system v2 neumorphique
 * Date     : 2026-04-05
 *
 * Standalone : fonctionne sans connexion BDD (données de démo).
 */

// ── Page courante (pour la sidebar) ─────────────────────────────
$current_page = 'dashboard';

// ── Données de démo ──────────────────────────────────────────────
$annoncesPubliees = 134;
$leadsDuMois      = 28;
$portailsActifs   = 6;
$tauxDeClic       = 34;
$userName         = 'Admin Demo';

// ── Agenda des tâches Net ─────────────────────────────────────────
$taskColumns = [

    // ── Colonne 1 : Annonces ──
    'annonces' => [
        'label'   => 'Annonces',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'✍️','title'=>'Création et mise en ligne','freq'=>'À chaque nouveau bien','desc'=>'Rédiger l\'annonce immobilière, sélectionner les photos, compléter les caractéristiques du bien et publier sur les portails.','link'=>'../net_annonces.php?action=new','label'=>'Créer','badge'=>['text'=>'→ À publier','class'=>'badge-warning']],
            ['icon'=>'🔍','title'=>'Vérification qualité','freq'=>'Hebdomadaire','desc'=>'Contrôler la qualité des annonces publiées : texte, photos, prix, DPE, diagnostics. Corriger les anomalies détectées.','link'=>'../net_annonces.php','label'=>'Vérifier','badge'=>['text'=>'📋 Hebdo','class'=>'badge-info']],
            ['icon'=>'🚀','title'=>'Rafraîchissement boost','freq'=>'Tous les 7 jours','desc'=>'Rafraîchir les annonces pour les remonter dans les résultats de recherche. Activer les options de mise en avant si budget disponible.','link'=>'../net_annonces.php','label'=>'Booster','badge'=>['text'=>'→ En cours','class'=>'badge-warning']],
            ['icon'=>'📦','title'=>'Archivage vendu/loué','freq'=>'À chaque transaction','desc'=>'Dépublier et archiver les annonces des biens vendus ou loués. Mettre à jour les statistiques de diffusion.','link'=>'../net_annonces.php?filtre=archiver','label'=>'Archiver','badge'=>['text'=>'✓ Auto','class'=>'badge-success']],
        ]
    ],

    // ── Colonne 2 : Portails & Diffusion ──
    'portails_diffusion' => [
        'label'   => 'Portails & Diffusion',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'🔌','title'=>'Vérification connexions API','freq'=>'Quotidien','desc'=>'Contrôler que les connexions API avec chaque portail sont actives. Renouveler les tokens expirés immédiatement.','link'=>'../net_portails.php','label'=>'Vérifier','badge'=>['text'=>'⚠️ Surveiller','class'=>'badge-danger']],
            ['icon'=>'💲','title'=>'Mise à jour tarifs','freq'=>'En cas de changement','desc'=>'Répercuter les modifications de prix sur l\'ensemble des portails. Vérifier la cohérence entre tous les canaux de diffusion.','link'=>'../net_portails.php','label'=>'Synchroniser','badge'=>['text'=>'→ En cours','class'=>'badge-warning']],
            ['icon'=>'📊','title'=>'Rapport de diffusion','freq'=>'Mensuel','desc'=>'Compiler le rapport mensuel de diffusion : nombre de publications, impressions, clics, leads générés par portail.','link'=>'../net_reporting.php','label'=>'Rapport','badge'=>['text'=>'📅 Mensuel','class'=>'badge-info']],
            ['icon'=>'🌐','title'=>'Nouveaux portails','freq'=>'Veille continue','desc'=>'Identifier et évaluer les nouveaux portails immobiliers. Négocier les contrats et intégrer les flux XML.','link'=>'../net_portails.php?action=new','label'=>'Explorer','badge'=>['text'=>'📋 Veille','class'=>'badge-neutral']],
        ]
    ],

    // ── Colonne 3 : Leads & Suivi ──
    'leads_suivi' => [
        'label'   => 'Leads & Suivi',
        'grouped' => false,
        'tasks'   => [
            ['icon'=>'📥','title'=>'Qualification leads entrants','freq'=>'Quotidien','desc'=>'Analyser et qualifier chaque lead entrant : source, bien demandé, budget, urgence. Prioriser les contacts à rappeler.','link'=>'../net_leads.php','label'=>'Qualifier','badge'=>['text'=>'28 ce mois','class'=>'badge-info']],
            ['icon'=>'📞','title'=>'Relance sous 24h','freq'=>'Quotidien','desc'=>'Rappeler systématiquement tout lead entrant dans les 24h. Un lead non relancé sous 24h a 10x moins de chances de convertir.','link'=>'../net_leads.php?filtre=relance','label'=>'Relancer','badge'=>['text'=>'⚠️ Urgent','class'=>'badge-danger']],
            ['icon'=>'👤','title'=>'Transfert négociateur','freq'=>'Après qualification','desc'=>'Transférer les leads qualifiés au négociateur dédié selon la zone géographique et le type de bien.','link'=>'../net_leads.php','label'=>'Transférer','badge'=>['text'=>'→ À affecter','class'=>'badge-warning']],
            ['icon'=>'📈','title'=>'Reporting conversion','freq'=>'Mensuel','desc'=>'Analyser le taux de conversion leads → visites → compromis par source et par portail. Optimiser l\'allocation budgétaire.','link'=>'../net_reporting.php','label'=>'Analyser','badge'=>['text'=>'📅 Mensuel','class'=>'badge-info']],
        ]
    ],

    // ── Colonne 4 : Médias & Stats groupés ──
    'medias_stats' => [
        'label'     => 'Médias & Stats',
        'grouped'   => true,
        'subgroups' => [
            'medias' => [
                'label' => 'Médias',
                'tasks' => [
                    ['icon'=>'📷','title'=>'Shooting photo','freq'=>'Nouveau bien','desc'=>'Organiser le shooting photographique professionnel de chaque bien. Retoucher et sélectionner les 10 meilleures photos.','link'=>'../net_medias.php?action=shooting','label'=>'Planifier','badge'=>['text'=>'→ À planifier','class'=>'badge-neutral']],
                    ['icon'=>'📐','title'=>'Plan 2D/3D','freq'=>'Sur demande','desc'=>'Faire réaliser le plan 2D ou la visite virtuelle 3D des biens premium. Intégrer à l\'annonce pour maximiser l\'engagement.','link'=>'../net_medias.php?action=plan','label'=>'Commander','badge'=>['text'=>'→ Option','class'=>'badge-neutral']],
                    ['icon'=>'🎬','title'=>'Visite virtuelle','freq'=>'Biens premium','desc'=>'Intégrer la visite virtuelle 360° dans les annonces des biens haut de gamme. Taux de contact multiplié par 3.','link'=>'../net_medias.php?action=virtuel','label'=>'Intégrer','badge'=>['text'=>'✓ Premium','class'=>'badge-success']],
                ]
            ],
            'stats' => [
                'label' => 'Stats',
                'tasks' => [
                    ['icon'=>'📋','title'=>'Rapport mensuel portails','freq'=>'Mensuel','desc'=>'Extraire et consolider les statistiques mensuelles de chaque portail : vues, contacts, taux de clic, coût par lead.','link'=>'../net_stats.php','label'=>'Extraire','badge'=>['text'=>'📅 Mensuel','class'=>'badge-info']],
                    ['icon'=>'🔬','title'=>'Analyse taux conversion','freq'=>'Mensuel','desc'=>'Analyser le taux de transformation à chaque étape du tunnel : vue annonce → contact → visite → offre → vente.','link'=>'../net_stats.php','label'=>'Analyser','badge'=>['text'=>'📅 Mensuel','class'=>'badge-info']],
                    ['icon'=>'🏆','title'=>'Benchmark concurrence','freq'=>'Trimestriel','desc'=>'Comparer les performances de diffusion avec les agences concurrentes. Identifier les axes d\'amélioration.','link'=>'../net_stats.php?action=benchmark','label'=>'Comparer','badge'=>['text'=>'📅 Trimest.','class'=>'badge-neutral']],
                ]
            ],
        ]
    ],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Diffusion Net — MaBoxImmo v2</title>
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
    .page-head-module { font-family: 'DM Mono', monospace; font-size: 9px; text-transform: uppercase; letter-spacing: 0.22em; color: #a8a49e; margin-bottom: 4px; }
    .page-head-row { display: flex; align-items: center; gap: 10px; }
    .page-head-title { font-family: 'Sora'; font-size: 20px; font-weight: 700; color: #1a1816; line-height: 1.2; }
    .badge-module {
      display: inline-flex; align-items: center;
      padding: 3px 11px; border-radius: 999px;
      background: #3a7ab8; color: var(--bg-primary);
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
    .topbar-avatar { width: 32px; height: 32px; border-radius: 50%; background: #3a7ab8; display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 10px; color: #ffffff; font-weight: 500; letter-spacing: 0.05em; box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); flex-shrink: 0; }
    .topbar-notif { position: relative; width: 36px; height: 36px; border-radius: 10px; background: var(--bg-primary); box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light); display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
    .topbar-notif svg { width: 16px; height: 16px; stroke: #8a8680; fill: none; stroke-width: 1.6; }
    .topbar-notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: #cc5c58; border: 2px solid var(--bg-primary); }

    .dashboard-row { display: grid; grid-template-columns: 1fr 1.4fr; gap: 36px; align-items: start; }
    .dashboard-col { display: flex; flex-direction: column; }
  </style>
</head>
<body>

<div class="shell">

  <?php include 'sidebar_net.php'; ?>

  <div class="sb-content">

    <header class="topbar">

      <button class="topbar-back" onclick="history.back()" title="Retour">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </button>

      <div class="topbar-gap"></div>

      <nav class="topbar-breadcrumb">
        <span>Net</span>
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
          <h1 class="page-head-title">Diffusion Net</h1>
          <span class="badge-module">NET</span>
        </div>
      </div>

      <div class="nouveau-group">
        <span class="nouveau-label">Nouveau</span>
        <div class="nouveau-sep"></div>
        <a href="../net_annonces.php?action=new" class="nouveau-btn" title="Annonce">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="12" x2="12" y2="18"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </a>
        <a href="../net_portails.php?action=new" class="nouveau-btn" title="Portail">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
        </a>
        <a href="../net_leads.php?action=new" class="nouveau-btn" title="Lead">
          <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        </a>
        <a href="../net_medias.php?action=new" class="nouveau-btn" title="Média">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
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
            <div class="kpi-bullet" style="background:#3a7ab8;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Annonces publiées</div>
              <div class="kpi-delta">sur 6 portails</div>
            </div>
            <div class="kpi-value" style="color:#3a7ab8;"><?= $annoncesPubliees ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#c8883a;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Leads du mois</div>
              <div class="kpi-delta">↑ +12 vs mois préc.</div>
            </div>
            <div class="kpi-value" style="color:#c8883a;"><?= $leadsDuMois ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#7a9060;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Portails actifs</div>
              <div class="kpi-delta">tous connectés</div>
            </div>
            <div class="kpi-value" style="color:#7a9060;"><?= $portailsActifs ?></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-bullet" style="background:#cc5c58;"></div>
            <div class="kpi-body">
              <div class="kpi-label">Taux de clic</div>
              <div class="kpi-delta">‰ moyenne portails</div>
            </div>
            <div class="kpi-value" style="color:#cc5c58;"><?= $tauxDeClic ?></div>
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
              <div class="alert-title">Annonce expirante · Réf. IMM-2089 · villa 5p</div>
              <div class="alert-sub">expire demain · urgent</div>
            </div>
            <span class="alert-time">urgent</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#c8883a;"></span>
            <div class="alert-body">
              <div class="alert-title">Lead sans réponse · contact site</div>
              <div class="alert-sub">depuis 48h · relancer · J+2</div>
            </div>
            <span class="alert-time">J+2</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#cc5c58;"></span>
            <div class="alert-body">
              <div class="alert-title">Portail déconnecté · SeLoger</div>
              <div class="alert-sub">token expiré · urgent</div>
            </div>
            <span class="alert-time">urgent</span>
          </button>

          <button class="alert-item">
            <span class="alert-dot" style="background:#7a9060;"></span>
            <div class="alert-body">
              <div class="alert-title">Photos manquantes · 4 annonces</div>
              <div class="alert-sub">qualité insuffisante · à traiter · cette semaine</div>
            </div>
            <span class="alert-time">cette sem.</span>
          </button>

        </div>
      </div>

    </div>

    <div class="agenda-header">
      <div class="section-title">
        <div class="line-l"></div>
        <span class="sec-txt">Agenda des tâches Net</span>
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
      <span style="color:#3a7ab8;">v2.0 · <?= date('d/m/Y') ?></span>
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
