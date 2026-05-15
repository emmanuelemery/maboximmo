<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
require_super_admin();

// Vérification de l'accès Super Admin (par mot de passe)
$isSuperAdminVerified = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['superadmin_verified'])) {
    $isSuperAdminVerified = true;
}

// Si pas vérifié, afficher la modal de mot de passe
if (!$isSuperAdminVerified) {
    // Afficher la page avec la modal de mot de passe visible
    // Le contenu de la page sera masqué par CSS
}

$pdo = db();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$selectedTable = $_GET['table'] ?? null;

// Définition des tables par rubrique
$tables = [
    'RH' => [
        'users' => 'Utilisateurs',
        'roles' => 'Rôles',
        'societes' => 'Sociétés',
        'agences' => 'Agences',
        'conges' => 'Congés',
        'conges_soldes' => 'Soldes congés',
        'mois_clos' => 'Mois clos',
        'salaires' => 'Salaires',
        'salaires_documents' => 'Documents salaires',
        'mail_templates' => 'Templates emails',
    ],
    'Syndic' => [
        'immeubles' => 'Immeubles',
        'biens' => 'Biens',
        'types_bien' => 'Types de bien',
    ],
    'Agency' => [
        'registres_acces' => 'Accès registres',
        'agence_documents' => 'Documents agence',
        'subscription_plans' => 'Plans souscription',
        'user_subscriptions' => 'Souscriptions utilisateurs',
    ],
    'Système' => [
        'cache_listings' => 'Cache listings',
        'url_redirects' => 'Redirects URL',
        'dev_organisation' => 'Organisation dev',
        'email_verifications' => 'Vérifications emails',
        'sso_tokens' => 'Tokens SSO',
    ]
];

// Récupérer la structure de la table sélectionnée
$selectedTableLabel = '';
$tableColumns = [];
$tableRows = [];
$totalRows = 0;

if ($selectedTable) {
    // Validation du nom de table
    $found = false;
    foreach ($tables as $rubrique => $tablesInRubrique) {
        if (array_key_exists($selectedTable, $tablesInRubrique)) {
            $selectedTableLabel = $tablesInRubrique[$selectedTable];
            $found = true;
            break;
        }
    }

    if (!$found) {
        $selectedTable = null;
    } else {
        try {
            // Assertion défensive : nom de table = alphanumérique + underscore uniquement
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $selectedTable)) {
                throw new Exception('Nom de table invalide');
            }

            // Récupérer les colonnes
            $stmt = $pdo->prepare("
                SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION
            ");
            $stmt->execute([$selectedTable]);
            $tableColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Récupérer le total des lignes (table validée par whitelist + regex)
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$selectedTable}`");
            $totalRows = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            // Récupérer les premières lignes
            $offset = (int)($_GET['offset'] ?? 0);
            $limit = 50;
            $stmt = $pdo->query("SELECT * FROM `{$selectedTable}` LIMIT {$limit} OFFSET {$offset}");
            $tableRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $selectedTable = null;
        }
    }
}

// Gérer l'ouverture automatique d'une entité FK
$autoOpenRow = null;
if ($selectedTable && isset($_GET['open_id'])) {
    try {
        $openId = (int)$_GET['open_id'];
        $stmt = $pdo->prepare("SELECT * FROM `$selectedTable` WHERE id = ?");
        $stmt->execute([$openId]);
        $autoOpenRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $autoOpenRow = null;
    }
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Admin BDD — Base de données</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #1a2a3a;
            --bg-soft: #ffffff;
            --sidebar: #ffffff;
            --ink: #d0e4ff;
            --muted: #7a91a8;
            --accent: #4878a6;
            --accent-2: #ffd479;
            --accent-3: #4a6038;
            --card: #ffffff;
            --stroke: #f0f1f3;
            --glow: 0 20px 60px rgba(102,217,255,0.18);
            --r-lg: 22px;
            --r-md: 16px;
            --r-sm: 10px;
            --sidebar-w: 250px;
            --topbar-h: 64px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: "Manrope", system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        a { color: inherit; text-decoration: none; }

        .mbi-sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: var(--sidebar-w);
            background: var(--sidebar);
            border-right: 1px solid var(--stroke);
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }

        .mbi-sidebar-head {
            padding: 22px 20px 16px;
            border-bottom: 1px solid var(--stroke);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mbi-sidebar-brand {
            line-height: 1.2;
        }

        .mbi-sidebar-brand strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
        }

        .mbi-sidebar-brand span {
            font-size: 11px;
            color: var(--accent);
            font-weight: 600;
        }

        .mbi-sidebar-section {
            padding: 14px 14px 4px;
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .mbi-nav {
            padding: 6px 10px;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .mbi-nav li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--r-sm);
            font-size: 13.5px;
            font-weight: 500;
            color: var(--muted);
            transition: all 0.18s ease;
        }

        .mbi-nav li a:hover {
            background: #ffffff;
            color: var(--ink);
        }

        .mbi-nav li a.active {
            background: linear-gradient(135deg, rgba(72,120,166,0.1), rgba(28,77,255,0.10));
            color: var(--accent);
            border: 1px solid rgba(102,217,255,0.18);
        }

        .mbi-main {
            flex: 1;
            margin-left: var(--sidebar-w);
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .mbi-topbar {
            height: var(--topbar-h);
            background: linear-gradient(180deg, rgba(26,35,50,0.95) 0%, rgba(26,35,50,0.8) 100%);
            border-bottom: 1px solid var(--stroke);
            display: flex;
            align-items: center;
            padding: 0 30px;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .mbi-topbar h1 {
            font-size: 20px;
            font-weight: 700;
            flex: 1;
        }

        .mbi-container {
            flex: 1;
            padding: 30px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .bdd-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        .bdd-layout > * {
            min-height: 0;
        }

        .bdd-tables-card {
            background: var(--card);
            border: 1px solid var(--stroke);
            border-radius: var(--r-md);
            padding: 20px;
            overflow-y: auto;
            min-height: 0;
        }

        .rubrique-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            margin-top: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--stroke);
        }

        .rubrique-header:first-child {
            margin-top: 0;
        }

        .rubrique-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
        }

        .btn-show-hidden {
            background: transparent;
            border: 1px solid rgba(72,120,166,0.2);
            border-radius: 4px;
            color: var(--accent);
            font-size: 11px;
            padding: 4px 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 600;
        }

        .btn-show-hidden:hover {
            background: rgba(72,120,166,0.08);
            border-color: rgba(72,120,166,0.3);
        }

        .table-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
            border-radius: 8px;
            transition: all 0.18s ease;
        }

        .table-item.hidden {
            display: none;
        }

        .table-btn {
            flex: 1;
            padding: 10px 12px;
            background: transparent;
            border: 1px solid transparent;
            border-radius: 8px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .table-btn:hover {
            background: #ffffff;
            color: var(--ink);
        }

        .table-btn.active {
            background: linear-gradient(135deg, rgba(72,120,166,0.1), rgba(28,77,255,0.10));
            color: var(--accent);
            border-color: rgba(102,217,255,0.18);
        }

        .btn-hide-table {
            padding: 6px 10px;
            background: transparent;
            border: 1px solid rgba(255,107,122,0.2);
            border-radius: 6px;
            color: rgba(255,107,122,0.6);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 4px;
            flex-shrink: 0;
            font-weight: 600;
        }

        .btn-hide-table:hover {
            background: rgba(255,107,122,0.1);
            border-color: rgba(255,107,122,0.4);
            color: #ff9aab;
        }

        .bdd-table-editor {
            background: var(--card);
            border: 1px solid var(--stroke);
            border-radius: var(--r-md);
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .bdd-table-editor .editor-header {
            padding: 24px 24px 0;
            flex-shrink: 0;
        }

        /* All direct block children are fixed-height except the tab content */
        .bdd-table-editor .bdd-table-header,
        .bdd-table-editor .bdd-table-info,
        .bdd-table-editor .bdd-tabs {
            flex-shrink: 0;
            padding-left: 24px;
            padding-right: 24px;
        }

        .bdd-table-editor .bdd-table-header {
            padding-top: 24px;
        }

        .bdd-table-editor .table-scroll {
            overflow: auto;
            flex: 1;
            min-height: 0;
            padding-bottom: 24px;
            scrollbar-width: thin;
            scrollbar-color: rgba(72,120,166,0.2) transparent;
        }

        .bdd-table-editor .table-scroll::-webkit-scrollbar { height: 6px; width: 6px; }
        .bdd-table-editor .table-scroll::-webkit-scrollbar-track { background: transparent; }
        .bdd-table-editor .table-scroll::-webkit-scrollbar-thumb { background: rgba(72,120,166,0.2); border-radius: 3px; }

        .bdd-table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .bdd-table-editor h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .btn-add-row {
            padding: 10px 18px;
            background: linear-gradient(135deg, rgba(255,212,121,0.2), rgba(255,212,121,0.1));
            border: 1px solid rgba(255,212,121,0.4);
            border-radius: 8px;
            color: #ffd479;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-add-row:hover {
            background: linear-gradient(135deg, rgba(255,212,121,0.3), rgba(255,212,121,0.2));
            border-color: rgba(255,212,121,0.6);
            box-shadow: 0 8px 24px rgba(255,212,121,0.2);
            transform: translateY(-2px);
        }

        .bdd-table-info {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 24px;
        }

        .bdd-columns-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--stroke);
        }

        .column-item {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--stroke);
            padding: 12px;
            border-radius: 8px;
            font-size: 12px;
        }

        .column-name {
            font-weight: 600;
            color: var(--accent);
            margin-bottom: 4px;
        }

        .column-type {
            font-size: 11px;
            color: var(--muted);
        }

        .bdd-rows-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .bdd-rows-header h3 {
            font-size: 14px;
            font-weight: 600;
        }

        .bdd-rows-count {
            font-size: 12px;
            color: var(--muted);
        }

        table {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        table thead {
            border-bottom: 1px solid var(--stroke);
        }

        table th {
            text-align: left;
            padding: 10px 8px;
            font-weight: 600;
            color: var(--accent);
            background: rgba(72,120,166,0.04);
            position: sticky;
            top: 0;
            z-index: 2;
            min-width: 100px;
            white-space: nowrap;
        }

        .th-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .btn-column-config {
            background: transparent;
            border: 1px solid rgba(72,120,166,0.2);
            border-radius: 4px;
            color: rgba(102,217,255,0.6);
            font-size: 12px;
            padding: 3px 6px;
            cursor: pointer;
            transition: all 0.2s;
            opacity: 0;
        }

        table th:hover .btn-column-config {
            opacity: 1;
        }

        .btn-column-config:hover {
            background: rgba(72,120,166,0.1);
            border-color: rgba(102,217,255,0.6);
            color: var(--accent);
        }

        table td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--stroke);
            color: var(--ink);
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        table tbody tr {
            cursor: pointer;
        }

        table tbody tr:hover {
            background: rgba(72,120,166,0.1);
        }

        /* CHAMPS CLÉS ÉTRANGÈRES - Colorer juste le texte */
        .fk-value {
            cursor: pointer;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .fk-value:hover {
            opacity: 0.8;
        }

        .fk-user {
            color: #4878a6;
        }

        .fk-agence {
            color: #4a6038;
        }

        .fk-societe {
            color: #ffd479;
        }

        .fk-role {
            color: #ff9aab;
        }

        .fk-immeuble {
            color: #ffaa64;
        }

        .fk-bien {
            color: #ba64ff;
        }

        .fk-plan {
            color: #64c8ff;
        }

        .fk-default {
            color: #d0e4ff;
        }

        .action-btn {
            padding: 4px 8px;
            background: rgba(72,120,166,0.08);
            border: 1px solid rgba(72,120,166,0.12);
            border-radius: 4px;
            color: var(--accent);
            cursor: pointer;
            font-size: 11px;
            transition: all 0.18s ease;
        }

        .action-btn:hover {
            background: rgba(72,120,166,0.12);
            border-color: rgba(72,120,166,0.2);
        }

        .action-btn.danger {
            background: rgba(255,107,122,0.1);
            border-color: rgba(255,107,122,0.2);
            color: #ff6b7a;
        }

        .action-btn.danger:hover {
            background: rgba(255,107,122,0.2);
            border-color: rgba(255,107,122,0.3);
        }

        /* ONGLETS */
        .bdd-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--stroke);
            padding-bottom: 0;
        }

        .bdd-tab {
            padding: 12px 16px;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .bdd-tab:hover {
            color: var(--ink);
        }

        .bdd-tab.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        .bdd-tab-content {
            display: none;
        }

        .bdd-tab-content.active {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            padding: 0 24px;
        }

        /* Fixed-height elements inside data-tab */
        #data-tab .bdd-rows-header,
        #data-tab > div:not(.table-scroll) {
            flex-shrink: 0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: var(--muted);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        /* MODAL DÉTAILS */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 200;
            animation: fadeIn 0.2s ease;
        }

        .modal-overlay.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: var(--bg-soft);
            border: 1px solid var(--stroke);
            border-radius: var(--r-md);
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 32px;
            border-bottom: 1px solid var(--stroke);
            background: var(--bg-soft);
            position: sticky;
            top: 0;
            z-index: 10;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .modal-header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .modal-btn-header {
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .modal-btn-save-header {
            background: rgba(124,245,214,0.15);
            border-color: rgba(124,245,214,0.3);
            color: #4a6038;
        }

        .modal-btn-save-header:hover {
            background: rgba(124,245,214,0.25);
            border-color: rgba(124,245,214,0.5);
        }

        .modal-btn-delete-header {
            background: rgba(255,107,122,0.15);
            border-color: rgba(255,107,122,0.3);
            color: #ff9aab;
        }

        .modal-btn-delete-header:hover {
            background: rgba(255,107,122,0.25);
            border-color: rgba(255,107,122,0.5);
        }

        .modal-btn-close-header {
            background: transparent;
            border-color: var(--stroke);
            color: var(--muted);
        }

        .modal-btn-close-header:hover {
            background: #ffffff;
            border-color: var(--accent);
            color: var(--ink);
        }

        .modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            background: #ffffff;
            border: 1px solid var(--stroke);
            border-radius: 6px;
            color: var(--ink);
            font-family: inherit;
            font-size: 13px;
            transition: all 0.2s;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            background: #ffffff;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(72,120,166,0.08);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .field-type {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
        }

        /* MODAL CONFIGURATION COLONNE */
        .column-config-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 300;
            align-items: center;
            justify-content: center;
        }

        .column-config-modal.active {
            display: flex;
        }

        .column-config-content {
            background: var(--bg-soft);
            border: 1px solid var(--stroke);
            border-radius: var(--r-md);
            padding: 28px;
            max-width: 450px;
            width: 90%;
            animation: slideUp 0.3s ease;
        }

        .column-config-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--stroke);
        }

        .form-group-wrapper {
            position: relative;
        }

        .btn-copy-field {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: 1px solid rgba(72,120,166,0.12);
            border-radius: 4px;
            color: rgba(72,120,166,0.3);
            font-size: 12px;
            padding: 4px 8px;
            cursor: pointer;
            transition: all 0.2s;
            opacity: 0;
            pointer-events: none;
        }

        .form-group-wrapper:hover .btn-copy-field {
            opacity: 1;
            pointer-events: all;
        }

        .btn-copy-field:hover {
            background: rgba(72,120,166,0.1);
            border-color: rgba(72,120,166,0.3);
            color: var(--accent);
        }

        .form-group-wrapper.textarea-wrapper .btn-copy-field {
            top: 12px;
        }

        .config-option {
            margin-bottom: 20px;
        }

        .config-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .config-select,
        .config-input {
            width: 100%;
            padding: 10px 12px;
            background: #ffffff;
            border: 1px solid var(--stroke);
            border-radius: 6px;
            color: var(--ink);
            font-family: inherit;
            font-size: 13px;
            transition: all 0.2s;
        }

        .config-select:focus,
        .config-input:focus {
            outline: none;
            background: #ffffff;
            border-color: var(--accent);
        }

        .config-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--stroke);
        }

        .btn-apply,
        .btn-reset,
        .btn-close-config {
            flex: 1;
            padding: 10px 16px;
            border-radius: 6px;
            border: 1px solid;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-apply {
            background: rgba(124,245,214,0.15);
            border-color: rgba(124,245,214,0.3);
            color: #4a6038;
        }

        .btn-apply:hover {
            background: rgba(124,245,214,0.25);
            border-color: rgba(124,245,214,0.5);
        }

        .btn-reset {
            background: rgba(255,212,121,0.15);
            border-color: rgba(255,212,121,0.3);
            color: #ffd479;
        }

        .btn-reset:hover {
            background: rgba(255,212,121,0.25);
            border-color: rgba(255,212,121,0.5);
        }

        .btn-close-config {
            background: transparent;
            border-color: var(--stroke);
            color: var(--muted);
        }

        .btn-close-config:hover {
            background: #ffffff;
            border-color: var(--accent);
            color: var(--ink);
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1200px) {
            .bdd-layout {
                grid-template-columns: 1fr;
            }
            .bdd-tables-card {
                max-height: 300px;
            }
        }

        /* Password Modal Styles */
        .password-modal {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .password-modal.hidden {
            display: none;
        }

        .password-modal-content {
            background: var(--bg-soft);
            border: 1px solid var(--stroke);
            border-radius: var(--r-md);
            padding: 40px;
            max-width: 420px;
            width: 90%;
            box-shadow: var(--glow);
            animation: slideUp 0.3s ease;
        }

        .password-modal-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            text-align: center;
        }

        .password-modal-subtitle {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            margin-bottom: 32px;
        }

        .password-input-group {
            margin-bottom: 24px;
        }

        .password-input-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .password-input-field {
            width: 100%;
            padding: 12px 16px;
            background: #ffffff;
            border: 1px solid var(--stroke);
            border-radius: 8px;
            color: var(--ink);
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
        }

        .password-input-field:focus {
            outline: none;
            background: #ffffff;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(72,120,166,0.08);
        }

        .password-error {
            color: #ff9aab;
            font-size: 12px;
            margin-top: 8px;
            display: none;
        }

        .password-error.show {
            display: block;
        }

        .password-actions {
            display: flex;
            gap: 12px;
        }

        .btn-password-submit,
        .btn-password-cancel {
            flex: 1;
            padding: 12px 24px;
            border-radius: 8px;
            border: 1px solid;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-password-submit {
            background: rgba(72,120,166,0.1);
            border-color: rgba(72,120,166,0.2);
            color: var(--accent);
        }

        .btn-password-submit:hover:not(:disabled) {
            background: rgba(72,120,166,0.15);
            border-color: rgba(72,120,166,0.3);
        }

        .btn-password-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-password-cancel {
            background: transparent;
            border-color: var(--stroke);
            color: var(--muted);
        }

        .btn-password-cancel:hover {
            background: #ffffff;
            border-color: var(--accent);
            color: var(--ink);
        }

        /* Masquer le contenu si pas authentifié */
        body.password-required > .mbi-sidebar,
        body.password-required > .mbi-main {
            display: none;
        }
    </style>
</head>
<body<?php if (!$isSuperAdminVerified) echo ' class="password-required"'; ?> data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
    <!-- Password Modal for Super Admin -->
    <div class="password-modal<?php if ($isSuperAdminVerified) echo ' hidden'; ?>" id="passwordModal">
        <div class="password-modal-content">
            <div class="password-modal-title">⚙ Super Admin</div>
            <div class="password-modal-subtitle">Entrez votre mot de passe pour accéder à la gestion de la base de données</div>

            <div class="password-input-group">
                <label class="password-input-label">Mot de passe</label>
                <input type="password" id="adminPassword" class="password-input-field" placeholder="••••••••" autocomplete="off">
                <div class="password-error" id="passwordError">Mot de passe incorrect</div>
            </div>

            <div class="password-actions">
                <button class="btn-password-submit" onclick="verifyAdminPassword()">Accéder</button>
                <button class="btn-password-cancel" onclick="window.history.back()">Annuler</button>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/../inc/sidebar_agency.php'; ?>

    <main class="mbi-main">
        <header class="mbi-topbar">
            <h1>🗄 Base de données — Super Admin</h1>
        </header>

        <div class="mbi-container">
            <div class="bdd-layout">
                <!-- COLONNE GAUCHE : LISTE DES TABLES -->
                <div class="bdd-tables-card">
                    <?php foreach ($tables as $rubrique => $rubriqueTables): ?>
                        <div class="rubrique-header">
                            <h3 class="rubrique-title"><?php echo e($rubrique); ?></h3>
                            <button class="btn-show-hidden" onclick="showAllInRubrique('<?php echo e(str_replace("'", "\\'", $rubrique)); ?>')">➕ Afficher</button>
                        </div>
                        <?php foreach ($rubriqueTables as $tableName => $tableLabel): ?>
                            <div class="table-item" data-rubrique="<?php echo e($rubrique); ?>" data-table="<?php echo e($tableName); ?>">
                                <a href="?table=<?php echo urlencode($tableName); ?>"
                                   class="table-btn <?php echo ($selectedTable === $tableName) ? 'active' : ''; ?>">
                                    <?php echo e($tableLabel); ?>
                                </a>
                                <button class="btn-hide-table" onclick="hideTable('<?php echo e($tableName); ?>', '<?php echo e($rubrique); ?>')">−</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <!-- COLONNE DROITE : ÉDITEUR DE TABLE -->
                <div class="bdd-table-editor">
                    <?php if (!$selectedTable): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <h2 style="margin-bottom: 8px;">Sélectionnez une table</h2>
                            <p>Choisissez une table à gauche pour voir et modifier son contenu</p>
                        </div>
                    <?php else: ?>
                        <div class="bdd-table-header">
                            <h2><?php echo e($selectedTableLabel); ?></h2>
                            <?php if ($selectedTable === 'agences'): ?>
                                <a href="./admin_agences_codes.php"
                                   style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:linear-gradient(135deg,#243B5C,#1e3050);color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;margin-right:8px;">
                                    🔧 Éditer codes courts
                                </a>
                            <?php endif; ?>
                            <button class="btn-add-row" onclick="addNewRow('<?php echo e($selectedTable); ?>')">
                                ➕ Ajouter une ligne
                            </button>
                        </div>
                        <div class="bdd-table-info">
                            Table: <code><?php echo e($selectedTable); ?></code> — <?php echo e($totalRows); ?> lignes
                        </div>

                        <!-- ONGLETS -->
                        <div class="bdd-tabs">
                            <button class="bdd-tab" onclick="switchTab(event, 'columns-tab')">📋 Colonnes (<?php echo count($tableColumns); ?>)</button>
                            <button class="bdd-tab active" onclick="switchTab(event, 'data-tab')">📊 Données (<?php echo count($tableRows); ?>)</button>
                        </div>

                        <!-- TAB: COLONNES -->
                        <div id="columns-tab" class="bdd-tab-content">
                            <?php if (!empty($tableColumns)): ?>
                                <div class="bdd-columns-grid">
                                    <?php foreach ($tableColumns as $col): ?>
                                        <div class="column-item">
                                            <div class="column-name"><?php echo e($col['COLUMN_NAME']); ?></div>
                                            <div class="column-type"><?php echo e($col['COLUMN_TYPE']); ?></div>
                                            <div class="column-type">
                                                <?php echo ($col['IS_NULLABLE'] === 'YES') ? 'NULL' : 'NOT NULL'; ?>
                                                <?php if ($col['COLUMN_KEY'] === 'PRI'): ?>
                                                    | <span style="color: var(--accent-3);">PK</span>
                                                <?php elseif ($col['COLUMN_KEY'] === 'UNI'): ?>
                                                    | <span style="color: var(--accent-2);">UNIQUE</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 40px;">
                                    <p>Aucune colonne trouvée</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB: DONNÉES -->
                        <div id="data-tab" class="bdd-tab-content active">
                            <?php if (!empty($tableRows)): ?>
                                <div class="bdd-rows-header">
                                    <div>
                                        <h3>Données (<?php echo count($tableRows); ?> lignes affichées)</h3>
                                        <span class="bdd-rows-count">Total: <?php echo e($totalRows); ?> lignes</span>
                                    </div>
                                </div>

                                <!-- LÉGENDE DES CLÉS ÉTRANGÈRES -->
                                <div style="margin-bottom: 16px; padding: 12px; background: #f7f8fa; border-radius: 8px; border: 1px solid var(--stroke);">
                                    <div style="font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 8px; text-transform: uppercase;">Légende des clés étrangères</div>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; font-size: 12px;">
                                        <span><span style="display: inline-block; width: 12px; height: 12px; background: rgba(72,120,166,0.2); border-radius: 3px; margin-right: 6px;"></span>id_user</span>
                                        <span><span style="display: inline-block; width: 12px; height: 12px; background: rgba(124,245,214,0.3); border-radius: 3px; margin-right: 6px;"></span>id_agence</span>
                                        <span><span style="display: inline-block; width: 12px; height: 12px; background: rgba(255,212,121,0.3); border-radius: 3px; margin-right: 6px;"></span>id_societe</span>
                                        <span><span style="display: inline-block; width: 12px; height: 12px; background: rgba(255,154,171,0.3); border-radius: 3px; margin-right: 6px;"></span>id_role</span>
                                        <span><span style="display: inline-block; width: 12px; height: 12px; background: rgba(255,170,100,0.3); border-radius: 3px; margin-right: 6px;"></span>id_immeuble</span>
                                        <span><span style="display: inline-block; width: 12px; height: 12px; background: rgba(186,100,255,0.3); border-radius: 3px; margin-right: 6px;"></span>id_bien</span>
                                    </div>
                                </div>
                                <div class="table-scroll">
                                    <table>
                                        <thead>
                                            <tr>
                                                <?php foreach ($tableColumns as $col): ?>
                                                    <th>
                                                        <div class="th-header">
                                                            <span><?php echo e($col['COLUMN_NAME']); ?></span>
                                                            <button class="btn-column-config" onclick="openColumnConfig('<?php echo e($col['COLUMN_NAME']); ?>', '<?php echo e($selectedTable); ?>')">⚙</button>
                                                        </div>
                                                    </th>
                                                <?php endforeach; ?>
                                                <th style="width: 100px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tableRows as $row): ?>
                                                <tr onclick="editRow(<?php echo e(json_encode($row)); ?>, '<?php echo e($selectedTable); ?>', event)">
                                                    <?php foreach ($tableColumns as $col): ?>
                                                        <?php
                                                            $colName = $col['COLUMN_NAME'];
                                                            $isForeignKey = strpos($colName, 'id_') === 0;
                                                            $fkType = '';

                                                            if ($isForeignKey) {
                                                                $fkType = substr($colName, 3); // Enlever 'id_'
                                                            }

                                                            $cellValue = (string)($row[$colName] ?? '');
                                                            $displayValue = substr($cellValue, 0, 50);
                                                        ?>
                                                        <td title="<?php echo e($cellValue); ?>">
                                                            <?php if ($isForeignKey && $cellValue): ?>
                                                                <span class="fk-value fk-<?php echo e($fkType); ?>" onclick="goToForeignTable('<?php echo e($fkType); ?>', '<?php echo e($cellValue); ?>', event)">
                                                                    <?php echo e($displayValue); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <?php echo e($displayValue); ?>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 40px;">
                                    <div style="font-size: 32px; margin-bottom: 12px;">📭</div>
                                    <p>Aucune données dans cette table</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL CONFIGURATION DE COLONNE -->
    <div id="columnConfigModal" class="column-config-modal" onclick="closeColumnConfig(event)">
        <div class="column-config-content" onclick="event.stopPropagation()">
            <div class="column-config-title">
                Configuration de <strong id="configColumnName">colonne</strong>
            </div>

            <div class="config-option">
                <label class="config-label">Format</label>
                <select id="configFormat" class="config-select" onchange="updateFormatOptions()">
                    <option value="">-- Pas de formatage --</option>
                    <option value="number">Nombre</option>
                    <option value="currency">Devise (€)</option>
                    <option value="percentage">Pourcentage (%)</option>
                    <option value="date">Date</option>
                </select>
            </div>

            <div class="config-option" id="decimalsOption" style="display: none;">
                <label class="config-label">Nombre de décimales</label>
                <select id="configDecimals" class="config-select">
                    <option value="0">0</option>
                    <option value="1">1</option>
                    <option value="2" selected>2</option>
                    <option value="3">3</option>
                </select>
            </div>

            <div class="config-option" id="symbolOption" style="display: none;">
                <label class="config-label">Position du symbole</label>
                <select id="configSymbolPos" class="config-select">
                    <option value="before">Avant (€ 100)</option>
                    <option value="after" selected>Après (100 €)</option>
                </select>
            </div>

            <div class="config-option">
                <label class="config-label">Format personnalisé (optionnel)</label>
                <input type="text" id="configCustom" class="config-input" placeholder="Ex: #,##0.00" />
                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">Laissez vide pour formatage automatique</div>
            </div>

            <div class="config-actions">
                <button class="btn-apply" onclick="applyColumnConfig()">✓ Appliquer</button>
                <button class="btn-reset" onclick="resetColumnConfig()">↻ Réinitialiser</button>
                <button class="btn-close-config" onclick="closeColumnConfig()">Annuler</button>
            </div>
        </div>
    </div>

    <!-- MODAL DÉTAILS D'UNE LIGNE -->
    <div id="rowModal" class="modal-overlay" onclick="closeRowModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle">Détails</h2>
                <div class="modal-header-actions">
                    <button type="button" class="modal-btn-header modal-btn-save-header" onclick="saveRow()">✓ Enregistrer</button>
                    <button type="button" class="modal-btn-header modal-btn-delete-header" onclick="confirmDelete()">🗑 Supprimer</button>
                    <button type="button" class="modal-btn-header modal-btn-close-header" id="btnReturn" style="display: none;" onclick="returnToParentTable()">← Retour</button>
                    <button type="button" class="modal-btn-header modal-btn-close-header" onclick="closeRowModal()">✕ Fermer</button>
                </div>
            </div>
            <form id="rowForm" class="modal-body">
                <div id="formFields" class="form-grid"></div>
            </form>
        </div>
    </div>

    <script>
        let currentRow = null;
        let currentTable = null;
        let tableColumnsInfo = null;

        // Gestion de la configuration des colonnes (localStorage)
        const COLUMN_CONFIG_KEY = 'bdd_admin_column_config';

        function getColumnConfig() {
            const stored = localStorage.getItem(COLUMN_CONFIG_KEY);
            return stored ? JSON.parse(stored) : {};
        }

        function setColumnConfig(config) {
            localStorage.setItem(COLUMN_CONFIG_KEY, JSON.stringify(config));
        }

        function getColumnFormatConfig(table, column) {
            const config = getColumnConfig();
            return config[table] && config[table][column] ? config[table][column] : null;
        }

        // Formater une valeur selon sa configuration
        function formatCellValue(value, table, columnName) {
            const config = getColumnFormatConfig(table, columnName);
            if (!config || !value) return value;

            const num = parseFloat(value);
            if (isNaN(num)) return value;

            const decimals = config.decimals ? parseInt(config.decimals) : 0;

            switch (config.format) {
                case 'number':
                    return num.toLocaleString('fr-FR', {
                        minimumFractionDigits: decimals,
                        maximumFractionDigits: decimals
                    });

                case 'currency':
                    const formatted = num.toLocaleString('fr-FR', {
                        minimumFractionDigits: decimals,
                        maximumFractionDigits: decimals
                    });
                    return config.symbolPos === 'before' ? '€ ' + formatted : formatted + ' €';

                case 'percentage':
                    return num.toLocaleString('fr-FR', {
                        minimumFractionDigits: decimals,
                        maximumFractionDigits: decimals
                    }) + ' %';

                case 'date':
                    try {
                        return new Date(value).toLocaleDateString('fr-FR');
                    } catch (e) {
                        return value;
                    }

                default:
                    return value;
            }
        }

        // Gestion des tables masquées (localStorage)
        const HIDDEN_TABLES_KEY = 'bdd_admin_hidden_tables';

        function getHiddenTables() {
            const stored = localStorage.getItem(HIDDEN_TABLES_KEY);
            return stored ? JSON.parse(stored) : {};
        }

        function setHiddenTables(hidden) {
            localStorage.setItem(HIDDEN_TABLES_KEY, JSON.stringify(hidden));
        }

        function hideTable(tableName, rubrique) {
            const hidden = getHiddenTables();
            if (!hidden[rubrique]) hidden[rubrique] = [];
            if (!hidden[rubrique].includes(tableName)) {
                hidden[rubrique].push(tableName);
            }
            setHiddenTables(hidden);

            // Masquer visuellement
            const item = document.querySelector(`[data-table="${tableName}"]`);
            if (item) item.classList.add('hidden');
        }

        function showAllInRubrique(rubrique) {
            const hidden = getHiddenTables();
            if (hidden[rubrique]) {
                hidden[rubrique].forEach(tableName => {
                    const item = document.querySelector(`[data-table="${tableName}"]`);
                    if (item) item.classList.remove('hidden');
                });
                delete hidden[rubrique];
                setHiddenTables(hidden);
            }
        }

        // Appliquer les tables masquées au chargement
        function initializeHiddenTables() {
            const hidden = getHiddenTables();
            for (const [rubrique, tables] of Object.entries(hidden)) {
                tables.forEach(tableName => {
                    const item = document.querySelector(`[data-table="${tableName}"]`);
                    if (item) item.classList.add('hidden');
                });
            }
        }

        // Mapping des tables FK
        const fkTableMap = {
            'user': 'users',
            'agence': 'agences',
            'societe': 'societes',
            'role': 'roles',
            'immeuble': 'immeubles',
            'bien': 'biens',
            'plan': 'subscription_plans'
        };

        // Variables pour la modal de configuration
        let currentConfigTable = null;
        let currentConfigColumn = null;

        // Ouvrir la modal de configuration d'une colonne
        function openColumnConfig(columnName, table) {
            currentConfigTable = table;
            currentConfigColumn = columnName;

            const modal = document.getElementById('columnConfigModal');
            const titleEl = document.getElementById('configColumnName');
            const formatSelect = document.getElementById('configFormat');
            const decimalsInput = document.getElementById('configDecimals');
            const symbolPosSelect = document.getElementById('configSymbolPos');
            const customInput = document.getElementById('configCustom');

            titleEl.textContent = columnName;

            // Charger la configuration existante
            const config = getColumnFormatConfig(table, columnName);
            if (config) {
                formatSelect.value = config.format || '';
                decimalsInput.value = config.decimals || '0';
                symbolPosSelect.value = config.symbolPos || 'after';
                customInput.value = config.custom || '';
            } else {
                formatSelect.value = '';
                decimalsInput.value = '0';
                symbolPosSelect.value = 'after';
                customInput.value = '';
            }

            updateFormatOptions();
            modal.classList.add('active');
        }

        // Mettre à jour l'affichage des options selon le format
        function updateFormatOptions() {
            const format = document.getElementById('configFormat').value;
            const decimalsOption = document.getElementById('decimalsOption');
            const symbolOption = document.getElementById('symbolOption');

            decimalsOption.style.display = (format === 'number' || format === 'currency' || format === 'percentage') ? 'block' : 'none';
            symbolOption.style.display = (format === 'currency') ? 'block' : 'none';
        }

        // Appliquer la configuration
        function applyColumnConfig() {
            const format = document.getElementById('configFormat').value;
            const decimals = document.getElementById('configDecimals').value;
            const symbolPos = document.getElementById('configSymbolPos').value;
            const custom = document.getElementById('configCustom').value;

            const config = getColumnConfig();
            if (!config[currentConfigTable]) config[currentConfigTable] = {};

            config[currentConfigTable][currentConfigColumn] = {
                format: format || null,
                decimals: format ? decimals : '0',
                symbolPos: symbolPos,
                custom: custom
            };

            setColumnConfig(config);
            closeColumnConfig();
            location.reload(); // Recharger pour appliquer le formatage
        }

        // Réinitialiser la configuration
        function resetColumnConfig() {
            const config = getColumnConfig();
            if (config[currentConfigTable] && config[currentConfigTable][currentConfigColumn]) {
                delete config[currentConfigTable][currentConfigColumn];
                setColumnConfig(config);
                closeColumnConfig();
                location.reload();
            }
        }

        // Fermer la modal de configuration
        function closeColumnConfig(event) {
            if (event && event.target.id !== 'columnConfigModal') return;
            document.getElementById('columnConfigModal').classList.remove('active');
        }

        // Basculer entre les onglets
        function switchTab(event, tabId) {
            event.preventDefault();
            document.querySelectorAll('.bdd-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.bdd-tab-content').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        }

        // Ouvrir les détails d'une entité FK au clic sur sa valeur
        function goToForeignTable(fkType, fkValue, event) {
            event.stopPropagation(); // Éviter d'ouvrir la modal de la ligne parente
            const targetTable = fkTableMap[fkType];

            if (!targetTable || !fkValue) return;

            // Sauvegarder les infos de la table/ligne parent
            const currentTable = new URLSearchParams(window.location.search).get('table');
            const row = event.target.closest('tr');
            const parentRowId = row ? row.querySelector('td')?.textContent.trim() : null;

            if (currentTable) {
                sessionStorage.setItem('bdd_parent_table', currentTable);
                if (parentRowId) {
                    sessionStorage.setItem('bdd_parent_row_id', parentRowId);
                }
            }

            // Naviguer vers la table FK et charger l'entité
            window.location.href = '?table=' + encodeURIComponent(targetTable) + '&open_id=' + encodeURIComponent(fkValue);
        }

        // Ajouter une nouvelle ligne
        function addNewRow(table) {
            currentTable = table;

            // Récupérer la structure des colonnes
            fetch('api_table.php?action=columns&table=' + encodeURIComponent(table))
                .then(r => r.json())
                .then(data => {
                    tableColumnsInfo = data.columns;

                    // Créer une nouvelle ligne vide
                    const newRow = {};
                    tableColumnsInfo.forEach(col => {
                        newRow[col.COLUMN_NAME] = '';
                    });

                    currentRow = newRow;
                    openRowModal(newRow, table);
                })
                .catch(e => alert('Erreur: ' + e.message));
        }

        // Ouvrir la modal de détails au clic sur une ligne
        function editRow(row, table, event) {
            // Vérifier si le clic est sur une FK
            if (event && event.target) {
                const fkSpan = event.target.closest('.fk-value');
                if (fkSpan) {
                    // Le clic est sur une FK, ignorer (goToForeignTable s'en charge)
                    return;
                }
            }

            // Ouvrir la modal pour éditer la ligne
            currentRow = row;
            currentTable = table;

            // Récupérer la structure des colonnes pour le contexte
            fetch('api_table.php?action=columns&table=' + encodeURIComponent(table))
                .then(r => r.json())
                .then(data => {
                    tableColumnsInfo = data.columns;
                    openRowModal(row, table);
                })
                .catch(e => alert('Erreur: ' + e.message));
        }

        // Ouvrir la modal avec le formulaire
        function openRowModal(row, table) {
            const modal = document.getElementById('rowModal');
            const formFields = document.getElementById('formFields');
            const modalTitle = document.getElementById('modalTitle');

            // Déterminer le titre et si c'est une nouvelle ligne
            const isNew = !row.id || row.id === '';
            let titleText = isNew ? '➕ Nouvelle ligne' : ('Modifier — ID ' + row.id);
            modalTitle.textContent = titleText;

            // Construire le formulaire sur 2 colonnes
            formFields.innerHTML = '';
            for (const [key, value] of Object.entries(row)) {
                const colInfo = tableColumnsInfo.find(c => c.COLUMN_NAME === key);
                const type = colInfo ? colInfo.COLUMN_TYPE : 'text';
                const isLongText = type.includes('LONGTEXT') || type.includes('TEXT');
                const isPrimaryKey = colInfo && colInfo.COLUMN_KEY === 'PRI';

                const group = document.createElement('div');
                group.className = 'form-group' + (isLongText ? ' full-width' : '');

                const label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = key;

                let input;
                if (isLongText) {
                    input = document.createElement('textarea');
                    input.className = 'form-textarea';
                } else {
                    input = document.createElement('input');
                    input.className = 'form-input';
                    input.type = type.includes('INT') ? 'number' : (type.includes('DATE') ? 'date' : 'text');
                }

                input.id = 'field_' + key;
                input.name = key;
                input.value = value || '';

                // Désactiver la clé primaire
                if (isPrimaryKey) {
                    input.disabled = true;
                }

                const typeInfo = document.createElement('div');
                typeInfo.className = 'field-type';
                typeInfo.textContent = type;

                // Wrapper pour le bouton copier
                const wrapper = document.createElement('div');
                wrapper.className = 'form-group-wrapper' + (isLongText ? ' textarea-wrapper' : '');
                wrapper.style.position = 'relative';
                wrapper.style.marginBottom = isLongText ? '0' : '0';

                // Bouton copier
                const copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'btn-copy-field';
                copyBtn.textContent = '📋';
                copyBtn.title = 'Copier la valeur';
                copyBtn.onclick = function(e) {
                    e.preventDefault();
                    copyToClipboard(input.value, copyBtn);
                };

                wrapper.appendChild(input);
                wrapper.appendChild(copyBtn);

                group.appendChild(label);
                group.appendChild(wrapper);
                group.appendChild(typeInfo);
                formFields.appendChild(group);
            }

            // Désactiver le bouton Supprimer pour les nouvelles lignes
            const deleteBtn = document.querySelector('.modal-btn-delete-header');
            if (isNew) {
                deleteBtn.disabled = true;
                deleteBtn.style.opacity = '0.5';
                deleteBtn.style.cursor = 'not-allowed';
            } else {
                deleteBtn.disabled = false;
                deleteBtn.style.opacity = '1';
                deleteBtn.style.cursor = 'pointer';
            }

            // Afficher le bouton "Retour" si on revient d'une table parent
            const returnBtn = document.getElementById('btnReturn');
            const parentTable = sessionStorage.getItem('bdd_parent_table');
            if (parentTable) {
                returnBtn.textContent = '← Retour à ' + parentTable;
                returnBtn.style.display = 'block';
            } else {
                returnBtn.style.display = 'none';
            }

            // Afficher la modal
            modal.classList.add('active');
        }

        // Copier une valeur dans le presse-papiers
        function copyToClipboard(value, button) {
            if (!value) {
                button.textContent = '❌ Vide';
                setTimeout(() => {
                    button.textContent = '📋';
                }, 1500);
                return;
            }

            navigator.clipboard.writeText(value).then(() => {
                // Feedback visuel
                const originalText = button.textContent;
                button.textContent = '✓ Copié';
                button.style.borderColor = 'rgba(124,245,214,0.5)';
                button.style.color = '#4a6038';
                button.style.background = 'rgba(124,245,214,0.15)';

                setTimeout(() => {
                    button.textContent = originalText;
                    button.style.borderColor = '';
                    button.style.color = '';
                    button.style.background = '';
                }, 1500);
            }).catch(() => {
                button.textContent = '❌ Erreur';
                setTimeout(() => {
                    button.textContent = '📋';
                }, 1500);
            });
        }

        // Retour à la table parent
        function returnToParentTable() {
            const parentTable = sessionStorage.getItem('bdd_parent_table');
            if (parentTable) {
                sessionStorage.removeItem('bdd_parent_table');
                sessionStorage.removeItem('bdd_parent_row_id');
                window.location.href = '?table=' + encodeURIComponent(parentTable);
            }
        }

        // Scroller jusqu'à une ligne spécifique
        function scrollToRow(rowId) {
            if (!rowId) return;

            const rows = document.querySelectorAll('table tbody tr');
            for (let row of rows) {
                const firstCell = row.querySelector('td');
                if (firstCell && firstCell.textContent.trim() === rowId) {
                    // Highlight la ligne
                    row.style.backgroundColor = 'rgba(124,245,214,0.2)';
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });

                    // Enlever le highlight après 2 secondes
                    setTimeout(() => {
                        row.style.backgroundColor = '';
                    }, 2000);
                    break;
                }
            }
        }

        // Fermer la modal
        function closeRowModal(event) {
            if (event && event.target.id !== 'rowModal') return;
            document.getElementById('rowModal').classList.remove('active');
            currentRow = null;
            currentTable = null;
        }

        // Sauvegarder les modifications
        function saveRow() {
            if (!currentRow || !currentTable) return;

            const formData = {};
            document.querySelectorAll('#rowForm input, #rowForm textarea').forEach(input => {
                if (!input.disabled) {
                    formData[input.name] = input.value || null;
                }
            });

            // Vérifier si c'est une nouvelle ligne (pas d'id ou id vide)
            const isNew = !currentRow.id || currentRow.id === '';
            const action = isNew ? 'insert' : 'update';
            const url = 'api_table.php?action=' + action + '&table=' + encodeURIComponent(currentTable);

            // Pour UPDATE, ajouter l'id
            if (!isNew) {
                formData.id = currentRow.id;
            }

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.dataset.csrf },
                body: JSON.stringify(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(isNew ? '✓ Nouvelle ligne créée' : '✓ Enregistrement sauvegardé');
                    location.reload();
                } else {
                    alert('❌ Erreur: ' + (data.error || data.message || 'Inconnu'));
                }
            })
            .catch(e => alert('❌ Erreur réseau: ' + e.message));
        }

        // Première vérification de suppression
        function confirmDelete() {
            if (!currentRow || !currentRow.id) {
                alert('Impossible de supprimer: aucune ligne sélectionnée');
                return;
            }

            const modal = document.getElementById('rowModal');
            const firstConfirm = confirm('⚠️ Êtes-vous sûr de vouloir supprimer cet enregistrement?\n\nCette action ne peut pas être annulée.');

            if (!firstConfirm) {
                return;
            }

            // Double vérification
            const secondConfirm = confirm('🗑️ Confirmez la suppression:\n\n' + currentTable + ' ID: ' + currentRow.id + '\n\nClic "OK" pour supprimer définitivement.');

            if (!secondConfirm) {
                return;
            }

            // Procéder à la suppression
            deleteRowFromModal();
        }

        // Supprimer une ligne depuis la modal
        function deleteRowFromModal() {
            fetch('api_table.php?action=delete&table=' + encodeURIComponent(currentTable), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.body.dataset.csrf },
                body: JSON.stringify({ id: currentRow.id })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✓ Enregistrement supprimé');
                    location.reload();
                } else {
                    alert('❌ Erreur: ' + (data.error || data.message || 'Inconnu'));
                }
            })
            .catch(e => alert('❌ Erreur réseau: ' + e.message));
        }

        // Fermer la modal au clic sur le fond
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRowModal();
            }
        });

        // Appliquer le formatage aux cellules du tableau
        function applyTableFormatting(table) {
            const thead = document.querySelector('table thead tr');
            if (!thead) return;

            const headers = [];
            thead.querySelectorAll('th').forEach((th, index) => {
                const columnName = th.querySelector('.th-header span')?.textContent || th.textContent.trim();
                headers[index] = columnName;
            });

            document.querySelectorAll('table tbody tr').forEach(row => {
                row.querySelectorAll('td:not(:last-child)').forEach((td, index) => {
                    const columnName = headers[index];
                    const originalValue = td.textContent.trim();

                    // Ne pas formater les FK
                    if (!td.querySelector('.fk-value')) {
                        const formatted = formatCellValue(originalValue, table, columnName);
                        if (formatted !== originalValue) {
                            td.textContent = formatted;
                            td.title = originalValue;
                        }
                    }
                });
            });
        }

        // Ouvrir automatiquement une entité FK si open_id est dans l'URL
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser les tables masquées
            initializeHiddenTables();

            // Appliquer le formatage des colonnes
            const urlParams = new URLSearchParams(window.location.search);
            const selectedTable = urlParams.get('table');
            if (selectedTable) {
                applyTableFormatting(selectedTable);
            }

            // Scroller jusqu'à la ligne parent si on revient d'une FK
            const parentRowId = sessionStorage.getItem('bdd_parent_row_id');
            if (parentRowId && selectedTable === sessionStorage.getItem('bdd_parent_table')) {
                setTimeout(() => scrollToRow(parentRowId), 500);
            }
            if (urlParams.has('open_id')) {
                // Attendre que le tableau soit chargé, puis récupérer la ligne et l'ouvrir
                setTimeout(function() {
                    const table = urlParams.get('table');
                    const openId = parseInt(urlParams.get('open_id'));

                    // Chercher la ligne dans le tableau visible
                    const rows = document.querySelectorAll('table tbody tr');
                    for (let row of rows) {
                        const firstCell = row.querySelector('td');
                        if (firstCell && parseInt(firstCell.textContent.trim()) === openId) {
                            // Récupérer les données de la ligne et l'ouvrir
                            const cells = row.querySelectorAll('td');
                            const rowData = {};

                            // Récupérer les noms de colonnes du thead
                            const headers = document.querySelectorAll('table th');
                            headers.forEach((header, index) => {
                                if (cells[index]) {
                                    rowData[header.textContent.trim()] = cells[index].textContent.trim();
                                }
                            });

                            // Ouvrir la modal avec cette ligne
                            editRow(rowData, table);

                            // Nettoyer l'URL pour éviter de réouvrir à chaque reload
                            window.history.replaceState({}, document.title, '?table=' + encodeURIComponent(table));
                            break;
                        }
                    }
                }, 500);
            }
        });

        // Vérifier le mot de passe Super Admin
        function verifyAdminPassword() {
            const password = document.getElementById('adminPassword').value;
            const errorDiv = document.getElementById('passwordError');
            const submitBtn = document.querySelector('.btn-password-submit');

            if (!password) {
                errorDiv.textContent = 'Veuillez entrer un mot de passe';
                errorDiv.classList.add('show');
                return;
            }

            submitBtn.disabled = true;
            errorDiv.classList.remove('show');

            fetch('verify_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Mot de passe correct, recharger la page
                    location.reload();
                } else {
                    errorDiv.textContent = data.message || 'Mot de passe incorrect';
                    errorDiv.classList.add('show');
                    submitBtn.disabled = false;
                    document.getElementById('adminPassword').value = '';
                    document.getElementById('adminPassword').focus();
                }
            })
            .catch(e => {
                errorDiv.textContent = 'Erreur: ' + e.message;
                errorDiv.classList.add('show');
                submitBtn.disabled = false;
            });
        }

        // Permettre appuyer sur Entrée dans le champ de mot de passe
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('adminPassword');
            if (passwordField) {
                passwordField.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        verifyAdminPassword();
                    }
                });
                // Focus automatiquement sur le champ
                if (!document.querySelector('.password-modal.hidden')) {
                    passwordField.focus();
                }
            }
        });
    </script>
</body>
</html>
