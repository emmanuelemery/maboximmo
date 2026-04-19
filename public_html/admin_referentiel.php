<?php
// admin_referentiel.php — Référentiel MaBoxImmo (Lexique & Architecture)
// Page de documentation interne super admin
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = (int)current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux super administrateurs.');
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$current_page = 'admin_referentiel';

// ── Définitions du référentiel ──────────────────────────────────────
$hierarchy = [
    [
        'niveau' => 1,
        'nom'    => 'Service',
        'desc'   => 'Plus haut niveau fonctionnel du portail. Une grande brique de l\'écosystème MaBoxImmo.',
        'exemples' => ['Ma Box Immo', 'Ma Box Agency', 'Ma Box RH', 'Ma Box Syndic'],
        'color'  => '#2d5f6b',
    ],
    [
        'niveau' => 2,
        'nom'    => 'Module',
        'desc'   => 'Grande fonctionnalité métier à l\'intérieur d\'un service.',
        'exemples' => ['Mandats', 'Biens', 'Mandants', 'Tâches', 'Réunions', 'Documents', 'Congés', 'Salaires'],
        'color'  => '#4878a6',
    ],
    [
        'niveau' => 3,
        'nom'    => 'Page',
        'desc'   => 'Écran précis qui appartient à un module.',
        'exemples' => ['Liste des mandats', 'Ajouter un mandat', 'Détail mandat', 'Fiche immeuble'],
        'color'  => '#6b8e6f',
    ],
    [
        'niveau' => 4,
        'nom'    => 'Composant',
        'desc'   => 'Élément d\'interface à l\'intérieur d\'une page.',
        'exemples' => ['Tableau', 'Formulaire', 'Bloc filtres', 'Carte', 'Sidebar', 'Topbar', 'Bouton', 'Onglet', 'Modale'],
        'color'  => '#c97b2e',
    ],
];

$lexique = [
    'Acteurs' => [
        ['terme' => 'Mandant',       'def' => 'Personne physique ou morale qui confie un mandat à un professionnel.'],
        ['terme' => 'Mandataire',    'def' => 'Professionnel qui agit au nom et pour le compte du mandant.'],
        ['terme' => 'Acquéreur',     'def' => 'Personne physique ou morale qui achète un bien immobilier.'],
        ['terme' => 'Locataire',     'def' => 'Personne qui loue un bien en qualité de preneur à bail.'],
        ['terme' => 'Copropriétaire','def' => 'Propriétaire d\'un lot dans un immeuble en copropriété.'],
    ],
    'Mandats' => [
        ['terme' => 'Mandat',             'def' => 'Contrat autorisant un professionnel à agir pour le compte d\'un mandant.'],
        ['terme' => 'Mandat de vente',    'def' => 'Mandat autorisant la mise en vente d\'un bien.'],
        ['terme' => 'Mandat de location', 'def' => 'Mandat autorisant la mise en location d\'un bien.'],
        ['terme' => 'Mandat de gestion',  'def' => 'Mandat confiant la gestion locative au mandataire.'],
        ['terme' => 'Mandat de syndic',   'def' => 'Mandat confiant l\'administration d\'une copropriété.'],
    ],
    'Biens & structure immobilière' => [
        ['terme' => 'Bien immobilier', 'def' => 'Unité immobilière (appartement, maison, local, terrain…) pouvant faire l\'objet d\'une transaction.'],
        ['terme' => 'Immeuble',        'def' => 'Bâtiment regroupant un ou plusieurs lots.'],
        ['terme' => 'Lot',             'def' => 'Fraction d\'un immeuble en copropriété, numérotée et identifiée.'],
        ['terme' => 'Dépendance',      'def' => 'Espace annexe d\'un bien : cave, garage, place de parking, grenier…'],
    ],
    'Données juridiques & commerciales' => [
        ['terme' => 'Annonce immobilière', 'def' => 'Publication commerciale d\'un bien à la vente ou à la location.'],
        ['terme' => 'DPE',                 'def' => 'Diagnostic de Performance Énergétique — classe énergétique et GES du bien.'],
        ['terme' => 'Surface habitable',   'def' => 'Surface de plancher des pièces, hors combles, caves, garages et balcons.'],
        ['terme' => 'Surface Carrez',      'def' => 'Surface privative en copropriété calculée selon la loi Carrez.'],
        ['terme' => 'Bail',                'def' => 'Contrat de location entre bailleur et locataire.'],
        ['terme' => 'Honoraires',          'def' => 'Rémunération du professionnel (vente, location ou gestion).'],
        ['terme' => 'Loyer',               'def' => 'Somme versée périodiquement par le locataire au bailleur.'],
        ['terme' => 'Charges',             'def' => 'Frais liés à l\'occupation ou à la copropriété (récupérables ou non).'],
        ['terme' => 'Dépôt de garantie',   'def' => 'Somme versée par le locataire à l\'entrée dans les lieux, restituable.'],
    ],
    'Concepts MaBoxImmo' => [
        ['terme' => 'Service',       'def' => 'Niveau 1 — plus haut niveau fonctionnel (Ma Box Agency, Ma Box RH…).'],
        ['terme' => 'Module',        'def' => 'Niveau 2 — fonctionnalité métier dans un service (Mandats, Biens…).'],
        ['terme' => 'Page',          'def' => 'Niveau 3 — écran précis (liste, détail, formulaire).'],
        ['terme' => 'Composant',     'def' => 'Niveau 4 — élément d\'interface (tableau, formulaire, bouton…).'],
        ['terme' => 'Société',       'def' => 'Entité juridique regroupant une ou plusieurs agences.'],
        ['terme' => 'Établissement', 'def' => 'Agence physique rattachée à une société.'],
        ['terme' => 'Utilisateur',   'def' => 'Personne disposant d\'un compte et d\'un rôle dans MaBoxImmo.'],
        ['terme' => 'Rôle',          'def' => 'Profil d\'accès (super admin, manager, collaborateur, bailleur…).'],
        ['terme' => 'Historique',    'def' => 'Trace horodatée des actions réalisées sur une entité.'],
        ['terme' => 'Statut',        'def' => 'État d\'une entité (actif, archivé, en attente, publié…).'],
    ],
];

$nommage = [
    ['cle' => 'service',   'val' => 'agency · rh · immo · syndic',                    'note' => 'Identifiant interne du service'],
    ['cle' => 'module',    'val' => 'mandats · biens · reunions · taches · factures', 'note' => 'Identifiant interne du module (pluriel)'],
    ['cle' => 'page',      'val' => 'liste · detail · fiche · dashboard · form',      'note' => 'Type de page'],
    ['cle' => 'action',    'val' => 'ajouter · modifier · supprimer · voir',          'note' => 'Verbe d\'action'],
    ['cle' => 'fichier',   'val' => '<code>agency_mandats.php</code> · <code>agency_mandat_form.php</code>', 'note' => 'Format : {service}_{module}[_{type}].php'],
    ['cle' => 'variable',  'val' => 'snake_case · préfixe métier explicite',           'note' => '<code>$id_mandat</code>, <code>$nb_immeubles</code>'],
    ['cle' => 'classe CSS','val' => 'kebab-case · préfixe module',                    'note' => '<code>.mandat-card</code>, <code>.bien-grid</code>'],
];

$bonnes_pratiques = [
    [
        'ico' => 'shield',
        'titre' => 'Ne pas appeler Ma Box Agency un "module"',
        'desc' => 'Ma Box Agency est un SERVICE. Les modules sont à l\'intérieur : Mandats, Biens, Tâches, etc.',
    ],
    [
        'ico' => 'layers',
        'titre' => 'Ne pas mélanger page et module',
        'desc' => 'Un module regroupe plusieurs pages. "Liste des mandats" est une page du module Mandats — pas un module elle-même.',
    ],
    [
        'ico' => 'target',
        'titre' => 'Préférer les noms précis aux termes vagues',
        'desc' => 'Éviter "section", "rubrique", "partie". Utiliser Service / Module / Page / Composant.',
    ],
    [
        'ico' => 'anchor',
        'titre' => 'Garder une structure stable',
        'desc' => 'Ne pas renommer un module sans mettre à jour la doc, les routes et les références dans le code.',
    ],
    [
        'ico' => 'book',
        'titre' => 'Documenter avant d\'étendre',
        'desc' => 'Nouveau module ou nouveau service ? Mettre à jour cette page de référence en premier.',
    ],
    [
        'ico' => 'lock',
        'titre' => 'Respecter le cloisonnement',
        'desc' => 'Chaque user voit uniquement son agence (au maximum sa société). Filtrer par id_societe et id_agence.',
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Référentiel MaBoxImmo — Lexique & Architecture</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; background: var(--bg-secondary); color: #1a1816; min-height: 100vh; display: flex; }

        .sb-content { margin-left: 220px; flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        /* ── Topbar (même pattern que les autres pages admin) ── */
        .topbar {
            display: flex; align-items: center; gap: 10px;
            padding: 0 24px 0 20px; height: 56px;
            background: var(--bg-primary);
            box-shadow: 0 4px 12px rgba(196,192,186,0.45);
            flex-shrink: 0; position: sticky; top: 0; z-index: 100;
        }
        .topbar-back {
            width: 34px; height: 34px; border-radius: 10px; background: var(--bg-primary);
            box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; flex-shrink: 0;
        }
        .topbar-back:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
        .topbar-back svg { width:15px; height:15px; stroke:#9aaa84; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .topbar-breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-family: 'DM Mono', monospace; font-size: 13px;
            letter-spacing: 0.06em; color: #8a8680; margin-left: 50px;
        }
        .topbar-breadcrumb .active { color: #a85858; font-weight: 600; font-size: 14px; }
        .topbar-sep { color: #c8c4be; font-size: 16px; }
        .topbar-spacer { flex: 1; }
        .topbar-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: #a85858;
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 10px; color: #fff; font-weight: 500;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light); flex-shrink: 0;
        }

        .main { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 0 32px 60px; }
        .wrap { max-width: 1180px; margin: 0 auto; }

        /* ── Hero ── */
        .hero {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 30px; padding: 30px 0 26px;
            border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 32px;
        }
        .hero-text { flex: 1; min-width: 0; }
        .hero-kicker {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
            letter-spacing: 0.22em; text-transform: uppercase; color: #a8a49e;
            margin-bottom: 10px;
        }
        .hero-title {
            font-size: 30px; font-weight: 800; color: #2d5f6b;
            letter-spacing: -0.02em; line-height: 1.1; margin-bottom: 8px;
        }
        .hero-sub { font-size: 14px; color: #6a6864; max-width: 720px; line-height: 1.55; }
        .admin-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 999px;
            background: rgba(168,88,88,0.1); border: 1px solid rgba(168,88,88,0.2);
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600;
            color: #a85858; letter-spacing: 0.08em; text-transform: uppercase;
            flex-shrink: 0; white-space: nowrap;
        }

        /* ── Sections ── */
        .section { margin-bottom: 48px; }
        .section-head {
            display: flex; align-items: center; gap: 12px; margin-bottom: 18px;
            padding-bottom: 12px; border-bottom: 1px dashed rgba(196,192,186,0.5);
        }
        .section-num {
            width: 30px; height: 30px; border-radius: 50%;
            background: #2d5f6b; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700;
            flex-shrink: 0;
        }
        .section-title {
            font-size: 18px; font-weight: 700; color: #2c2a28; letter-spacing: -0.01em;
        }
        .section-desc { font-size: 13px; color: #6a6864; margin-bottom: 18px; line-height: 1.55; }

        /* ── Block "pourquoi" ── */
        .why {
            background: var(--bg-primary); border-radius: 18px;
            padding: 24px 28px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border-left: 4px solid #2d5f6b;
        }
        .why ul { list-style: none; padding: 0; margin: 10px 0 0; }
        .why li {
            padding: 5px 0; font-size: 13px; color: #4a4844;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .why li::before {
            content: '▹'; color: #2d5f6b; font-weight: 700; flex-shrink: 0;
        }

        /* ── Hiérarchie 4 niveaux ── */
        .hierarchy-grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
            position: relative;
        }
        .level-card {
            background: var(--bg-primary); border-radius: 18px;
            padding: 20px 18px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border-top: 4px solid currentColor;
            display: flex; flex-direction: column; gap: 10px;
            position: relative;
        }
        .level-card::before {
            content: attr(data-n);
            position: absolute; top: -14px; left: 16px;
            width: 32px; height: 32px; border-radius: 50%;
            background: currentColor;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        }
        .level-name {
            font-size: 20px; font-weight: 800; letter-spacing: -0.02em;
            color: currentColor;
            margin-top: 4px;
        }
        .level-desc { font-size: 12px; color: #6a6864; line-height: 1.5; min-height: 54px; }
        .level-examples { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 4px; }
        .level-tag {
            font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
            padding: 3px 9px; border-radius: 20px;
            background: color-mix(in srgb, currentColor 12%, transparent);
            color: currentColor;
        }

        /* ── Exemple concret ── */
        .example-card {
            background: var(--bg-primary); border-radius: 18px;
            padding: 22px 26px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        }
        .example-flow { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .flow-item {
            display: inline-flex; flex-direction: column; gap: 2px;
            padding: 12px 18px; border-radius: 14px;
            background: #fff;
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        }
        .flow-level {
            font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
            letter-spacing: 0.16em; text-transform: uppercase;
        }
        .flow-val { font-size: 14px; font-weight: 700; color: #2c2a28; }
        .flow-arrow {
            color: #a8a49e; font-size: 20px; font-weight: 300;
            display: flex; align-items: center;
        }

        /* ── Lexique ── */
        .lex-group {
            background: var(--bg-primary); border-radius: 16px;
            margin-bottom: 14px; overflow: hidden;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        }
        .lex-head {
            padding: 14px 22px;
            background: linear-gradient(90deg, rgba(45,95,107,0.06), transparent);
            font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 700;
            letter-spacing: 0.14em; text-transform: uppercase; color: #2d5f6b;
            border-bottom: 1px solid rgba(196,192,186,0.3);
        }
        .lex-table { width: 100%; }
        .lex-row {
            display: grid; grid-template-columns: 220px 1fr;
            gap: 16px;
            padding: 11px 22px;
            border-bottom: 1px solid rgba(196,192,186,0.2);
            align-items: start;
        }
        .lex-row:last-child { border-bottom: none; }
        .lex-term { font-size: 13px; font-weight: 700; color: #1a1816; }
        .lex-def  { font-size: 13px; color: #4a4844; line-height: 1.55; }

        /* ── Nommage ── */
        .naming-card {
            background: var(--bg-primary); border-radius: 18px;
            padding: 4px 0;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
        }
        .naming-row {
            display: grid; grid-template-columns: 140px 1.5fr 1fr;
            gap: 18px;
            padding: 12px 22px;
            border-bottom: 1px solid rgba(196,192,186,0.2);
            align-items: center;
        }
        .naming-row:last-child { border-bottom: none; }
        .naming-key {
            font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 700;
            color: #a85858; text-transform: uppercase; letter-spacing: 0.1em;
        }
        .naming-val { font-family: 'DM Mono', monospace; font-size: 12px; color: #2c2a28; }
        .naming-val code {
            background: rgba(45,95,107,0.08); padding: 2px 8px; border-radius: 6px;
            font-family: 'DM Mono', monospace; font-size: 12px; color: #2d5f6b;
        }
        .naming-note { font-size: 12px; color: #6a6864; }

        /* ── Bonnes pratiques ── */
        .practices-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .practice {
            background: var(--bg-primary); border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            display: flex; gap: 14px; align-items: flex-start;
            border-left: 3px solid #2d5f6b;
        }
        .practice-ico {
            width: 40px; height: 40px; border-radius: 11px;
            background: rgba(45,95,107,0.1);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .practice-ico svg { width: 18px; height: 18px; stroke: #2d5f6b; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .practice-titre { font-size: 13px; font-weight: 700; color: #1a1816; margin-bottom: 4px; }
        .practice-desc { font-size: 12px; color: #6a6864; line-height: 1.55; }

        /* ── Couleurs hiérarchie ── */
        .c-service   { color: #2d5f6b; }
        .c-module    { color: #4878a6; }
        .c-page      { color: #6b8e6f; }
        .c-composant { color: #c97b2e; }

        @media (max-width: 1100px) {
            .hierarchy-grid { grid-template-columns: repeat(2, 1fr); }
            .practices-grid { grid-template-columns: 1fr; }
            .lex-row { grid-template-columns: 160px 1fr; }
            .naming-row { grid-template-columns: 110px 1.5fr 1fr; }
        }
        @media (max-width: 700px) {
            .hierarchy-grid { grid-template-columns: 1fr; }
            .hero { flex-direction: column; align-items: flex-start; gap: 14px; }
            .lex-row { grid-template-columns: 1fr; gap: 4px; padding: 12px 18px; }
            .lex-term { color: #2d5f6b; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/sidebar_rh.php'; ?>

<div class="sb-content">

    <header class="topbar">
        <button class="topbar-back" onclick="history.back()" title="Retour">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button class="topbar-back" onclick="history.forward()" title="Avancer">
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <nav class="topbar-breadcrumb">
            <span>Admin</span>
            <span class="topbar-sep">›</span>
            <span class="active">Référentiel</span>
        </nav>
        <div class="topbar-spacer"></div>
        <div class="topbar-avatar"><?= strtoupper(substr((string)($_SESSION['prenom'] ?? '?'), 0, 1) . substr((string)($_SESSION['nom'] ?? ''), 0, 1)) ?></div>
    </header>

    <main class="main">
        <div class="wrap">

            <!-- HERO -->
            <div class="hero">
                <div class="hero-text">
                    <div class="hero-kicker">Référentiel interne · Documentation</div>
                    <h1 class="hero-title">Référentiel MaBoxImmo — Lexique & Architecture</h1>
                    <p class="hero-sub">
                        Document de référence pour unifier le vocabulaire, l'architecture fonctionnelle et les conventions de nommage utilisés dans le portail.
                        À consulter avant tout développement, toute nouvelle page ou toute génération assistée par IA.
                    </p>
                </div>
                <div class="admin-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Super Admin
                </div>
            </div>

            <!-- 1 · POURQUOI -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">1</div>
                    <div class="section-title">Pourquoi cette page existe</div>
                </div>
                <div class="why">
                    <p style="font-size:13px;color:#4a4844;line-height:1.6;">
                        MaBoxImmo regroupe plusieurs services, dizaines de modules, centaines de pages. Sans terminologie claire, la confusion s'installe.
                        Cette page est la <strong>source de vérité</strong> à laquelle se référer pour :
                    </p>
                    <ul>
                        <li><strong>Unifier les termes</strong> entre métier, UX et technique.</li>
                        <li><strong>Éviter les confusions</strong> (service vs module, page vs composant…).</li>
                        <li><strong>Faciliter les développements</strong> grâce à des conventions stables.</li>
                        <li><strong>Garantir la cohérence</strong> UX, métier et documentaire.</li>
                        <li><strong>Fiabiliser les prompts IA</strong> utilisés pour générer des pages ou du code.</li>
                    </ul>
                </div>
            </section>

            <!-- 2 · ARCHITECTURE OFFICIELLE -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">2</div>
                    <div class="section-title">Architecture officielle — 4 niveaux</div>
                </div>
                <p class="section-desc">
                    Toute fonctionnalité du portail se situe sur l'un de ces quatre niveaux. La hiérarchie est stricte et descendante.
                </p>
                <div class="hierarchy-grid">
                    <?php foreach ($hierarchy as $h): ?>
                    <div class="level-card" data-n="<?= $h['niveau'] ?>" style="color:<?= $h['color'] ?>">
                        <div class="level-name"><?= h($h['nom']) ?></div>
                        <div class="level-desc" style="color:#6a6864"><?= h($h['desc']) ?></div>
                        <div class="level-examples">
                            <?php foreach ($h['exemples'] as $ex): ?>
                                <span class="level-tag"><?= h($ex) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 3 · EXEMPLE CONCRET -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">3</div>
                    <div class="section-title">Exemple concret</div>
                </div>
                <div class="example-card">
                    <div class="example-flow">
                        <div class="flow-item">
                            <span class="flow-level c-service">Service</span>
                            <span class="flow-val">Ma Box Agency</span>
                        </div>
                        <span class="flow-arrow">›</span>
                        <div class="flow-item">
                            <span class="flow-level c-module">Module</span>
                            <span class="flow-val">Mandats</span>
                        </div>
                        <span class="flow-arrow">›</span>
                        <div class="flow-item">
                            <span class="flow-level c-page">Page</span>
                            <span class="flow-val">Liste des mandats</span>
                        </div>
                        <span class="flow-arrow">›</span>
                        <div class="flow-item">
                            <span class="flow-level c-composant">Composants</span>
                            <span class="flow-val">Filtres · Tableau · Bouton · Pagination</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 4 · LEXIQUE -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">4</div>
                    <div class="section-title">Lexique métier</div>
                </div>
                <p class="section-desc">
                    Définitions courtes et opérationnelles des termes métier utilisés dans MaBoxImmo.
                </p>
                <?php foreach ($lexique as $groupe => $items): ?>
                <div class="lex-group">
                    <div class="lex-head"><?= h($groupe) ?></div>
                    <div class="lex-table">
                        <?php foreach ($items as $it): ?>
                        <div class="lex-row">
                            <div class="lex-term"><?= h($it['terme']) ?></div>
                            <div class="lex-def"><?= h($it['def']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </section>

            <!-- 5 · RÈGLES DE NOMMAGE -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">5</div>
                    <div class="section-title">Règles de nommage</div>
                </div>
                <p class="section-desc">
                    Conventions à respecter pour les noms de fichiers PHP, de variables, de classes CSS et dans les prompts IA.
                </p>
                <div class="naming-card">
                    <?php foreach ($nommage as $n): ?>
                    <div class="naming-row">
                        <div class="naming-key"><?= h($n['cle']) ?></div>
                        <div class="naming-val"><?= $n['val'] /* contient <code> volontaire */ ?></div>
                        <div class="naming-note"><?= $n['note'] /* idem */ ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 6 · BONNES PRATIQUES -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">6</div>
                    <div class="section-title">Bonnes pratiques</div>
                </div>
                <p class="section-desc">
                    Les réflexes à garder en tête avant d'ajouter un service, un module ou une page.
                </p>
                <div class="practices-grid">
                    <?php foreach ($bonnes_pratiques as $bp): ?>
                    <div class="practice">
                        <div class="practice-ico">
                            <?php switch ($bp['ico']):
                                case 'shield': ?><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><?php break; ?>
                                <?php case 'layers': ?><svg viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg><?php break; ?>
                                <?php case 'target': ?><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg><?php break; ?>
                                <?php case 'anchor': ?><svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="3"/><line x1="12" y1="22" x2="12" y2="8"/><path d="M5 12H2a10 10 0 0020 0h-3"/></svg><?php break; ?>
                                <?php case 'book': ?><svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg><?php break; ?>
                                <?php case 'lock': ?><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg><?php break; ?>
                            <?php endswitch; ?>
                        </div>
                        <div>
                            <div class="practice-titre"><?= h($bp['titre']) ?></div>
                            <div class="practice-desc"><?= h($bp['desc']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- 7 · ARCHITECTURE DONNÉES (TIERS) -->
            <section class="section">
                <div class="section-head">
                    <div class="section-num">7</div>
                    <div class="section-title">Architecture données — les Tiers</div>
                </div>
                <p class="section-desc">
                    Toute personne ou entité externe (propriétaire, bailleur, locataire, mandant, prestataire, notaire…) est <strong>saisie une seule fois</strong> dans la table <code>tiers</code>.
                    Les rôles métier sont ajoutés via <code>tiers_roles</code>. Les comptes applicatifs (<code>users</code>) restent séparés et reliés via <code>user_tiers</code>.
                </p>
                <div class="example-card" style="margin-bottom:18px;">
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#a85858;letter-spacing:0.14em;text-transform:uppercase;margin-bottom:10px;">Règle fondamentale</div>
                    <div style="font-size:14px;color:#1a1816;line-height:1.6;">
                        <strong style="color:#2d5f6b;">users</strong> = identité <strong>applicative</strong> (auth, rôles app) ·
                        <strong style="color:#4878a6;">tiers</strong> = identité <strong>métier</strong> (source unique) ·
                        <strong style="color:#6b8e6f;">tiers_roles</strong> = affectations contextuelles multiples
                    </div>
                </div>

                <div class="lex-group">
                    <div class="lex-head">Tables de l'écosystème tiers</div>
                    <div class="lex-table">
                        <div class="lex-row">
                            <div class="lex-term">tiers</div>
                            <div class="lex-def">Racine métier. Personnes physiques, morales, entités juridiques, indivisions, syndicats de copropriétaires. Un tiers = une identité unique avec tous ses contacts et coordonnées.</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">tiers_roles</div>
                            <div class="lex-def">Affectations d'un tiers dans des rôles métier. Un même tiers peut avoir N rôles sur N objets (bien, immeuble, mandat, bail…). Dates de début/fin pour l'historisation.</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">tiers_roles_codes</div>
                            <div class="lex-def">Référentiel des ~59 codes de rôle (proprietaire, bailleur, locataire, coproprietaire, notaire, artisan, garant, syndic…). Catégorisés : acteur_immo, juridique, prestataire, financier, crm, contact.</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">tiers_contacts</div>
                            <div class="lex-def">Personnes physiques rattachées à une entité morale (gérant SCI, associé, président CS, contact comptable…). Avec qualité, priorité et canal de communication principal.</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">user_tiers</div>
                            <div class="lex-def">Pont entre un compte applicatif et une fiche métier. Type de lien : <code>self</code> (collaborateur = tiers), <code>extranet_bailleur</code>, <code>extranet_coproprio</code>, <code>extranet_locataire</code>, <code>extranet_prestataire</code>.</div>
                        </div>
                    </div>
                </div>

                <div class="lex-group" style="margin-top:14px;">
                    <div class="lex-head">Exemples concrets — un tiers, plusieurs rôles</div>
                    <div class="lex-table">
                        <div class="lex-row">
                            <div class="lex-term">M. Dupont Jean</div>
                            <div class="lex-def">1 tiers · [proprietaire, bien #12] · [bailleur, bien #45] · [coproprietaire, immeuble #7, quote_part 8.00] · [membre_cs, immeuble #7]</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">SCI Dupont</div>
                            <div class="lex-def">1 tiers (personne_morale) · [proprietaire, immeuble #3] · 2 tiers_contacts (M. Jean Dupont - gerant, Mme Marie Dupont - associee)</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">Locataire qui devient acquéreur</div>
                            <div class="lex-def">1 tiers · [locataire, bail #88, date_fin 2026-03-01] · [acquereur, bien #55, date_debut 2026-03-15]</div>
                        </div>
                        <div class="lex-row">
                            <div class="lex-term">Copropriétaire avec extranet</div>
                            <div class="lex-def">1 tiers · [coproprietaire, immeuble #9] · 1 user · user_tiers.type_lien=extranet_coproprio</div>
                        </div>
                    </div>
                </div>

                <div class="example-card" style="margin-top:18px;">
                    <div style="font-family:'DM Mono',monospace;font-size:10px;color:#6b8e6f;letter-spacing:0.14em;text-transform:uppercase;margin-bottom:10px;">Règle de développement</div>
                    <div style="font-size:13px;color:#4a4844;line-height:1.65;">
                        Toute <strong>nouvelle page</strong> qui gère des personnes ou entités écrit dans <code>tiers</code> + <code>tiers_roles</code> — jamais dans les tables legacy.
                        Les pages existantes (<code>agency_proprietaires</code>, <code>agency_mandants</code>, <code>admin_bailleurs</code>) migrent progressivement vers l'architecture tiers sans casse.
                    </div>
                </div>
            </section>

            <!-- Pied de page documentaire -->
            <div style="margin-top: 40px; padding: 18px 22px; text-align:center; font-family:'DM Mono',monospace; font-size:10px; color:#a8a49e; letter-spacing: 0.12em; text-transform: uppercase;">
                · Document vivant · À mettre à jour lors de tout ajout de service ou module ·
            </div>

        </div>
    </main>
</div>

</body>
</html>
