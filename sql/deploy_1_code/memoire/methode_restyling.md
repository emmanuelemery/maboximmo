# Méthode — Restyling d'une page existante en V2

## Règle absolue

**Conserver 100% des fonctionnalités. Changer uniquement le layout et la charte graphique.**

Avant de toucher au code, lister explicitement :
- Tous les handlers POST PHP
- Toutes les fonctions JavaScript
- Tous les liens (href, action=, window.location)
- Tous les boutons et formulaires
- Toutes les fonctionnalités (upload, download, navigation, modals...)

Vérifier après réécriture que chaque élément est toujours présent.

---

## Étapes

### 1. Archiver l'ancienne page
```bash
cp public_html/rh_xxx.php public_html/_archive/rh_xxx.php
```

### 2. Identifier la séparation PHP / HTML
- Toute la logique PHP (requêtes BDD, handlers POST, calculs) reste **intacte**
- Seul le bloc HTML (à partir de `<!doctype html>`) est réécrit

### 3. Remplacer le `<head>`
```html
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Titre — MaBoxImmo RH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/theme-rh.css">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <style>/* styles page-spécifiques V2 */</style>
    <script>/* toutes les fonctions JS conservées identiques */</script>
</head>
```

### 4. Remplacer le `<body>` avec le layout V2
```html
<body>
<div class="shell">
    <?php include __DIR__ . '/sidebar_rh.php'; ?>
    <div class="sb-content">
        <header class="topbar">
            <!-- back, forward, breadcrumb, spacer, clock, notif, avatar -->
        </header>
        <main class="main">
            <!-- page-head : titre + badges + boutons actions -->
            <!-- contenu spécifique -->
        </main>
    </div>
</div>
```

### 5. Vérifier les chemins
- Tous les `require_once` utilisent `__DIR__ . '/inc/...'` (pas de `../`)
- CSS : `href="css/..."` (pas `v2/css/`)
- Sidebar : `include __DIR__ . '/sidebar_rh.php'`
- Liens internes : relatifs depuis la racine `public_html/`

### 6. Vérifier les variables CSS
- Remplacer `var(--accent)` par `#4a6038`
- Remplacer `var(--ink)` par `#1a1816`
- Remplacer `var(--muted)` par `#8a8680`
- Remplacer `var(--bg)` par `#f0ede8`
- Remplacer `var(--stroke)` par `rgba(196,192,186,0.4)`

---

## Nommage des pages

Convention : `service_rubrique_action.php`

Exemples :
- `rh_dashboard.php`
- `rh_salaire_liste.php` → (nom actuel : `rh_salaires.php`)
- `rh_salaire_detail.php`
- `rh_conge_liste.php`
- `rh_user_ajouter.php`

Toujours en **underscore**, jamais de tiret, jamais de pluriel sur la rubrique.

---

## Structure des dossiers

```
public_html/
├── rh_salaires.php         ← pages V2 à la racine
├── rh_salaire_detail.php
├── rh_dashboard.php
├── sidebar_rh.php          ← sidebars à la racine
├── css/                    ← assets V2 (tokens, base, components, layout, themes)
├── js/                     ← scripts V2
├── _archive/               ← anciennes versions conservées
└── memoire/                ← ce dossier
```
