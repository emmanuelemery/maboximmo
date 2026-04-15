# 🎯 Guide d'Implémentation du Système d'Abonnements MaBoxImmo

## 📊 Architecture

### Tables de base de données
```
subscription_plans
├── id (PK)
├── nom (Syndic, RH, Agency, Propriétaire Pro)
├── slug (syndic, rh, agency, proprietaire)
├── description
├── icone (emoji)
├── couleur (hex)
└── actif

user_subscriptions
├── id (PK)
├── id_user (FK)
├── id_plan (FK)
├── date_activation
├── date_expiration
├── statut (actif/suspendu/expiré)
└── timestamps
```

## 🚀 Utilisation

### 1. **Page d'accueil** (`landing.php`)
- Affiche tous les services (4 cartes)
- Vert (✓ Actif) si l'utilisateur est abonné
- Rouge (🔒 Verrouillé) sinon
- Buttons "Accéder" ou "Non disponible"

```php
// Accès: https://domain.com/landing.php
require_login(); // Obligatoire
```

### 2. **Helpers d'abonnements** (`inc/subscriptions.php`)

#### Vérifier l'accès à un service
```php
require_once 'inc/subscriptions.php';

// Au début de chaque page protégée
requireSubscription($pdo, current_user_id(), 'rh');
// Redirige vers landing.php si pas d'accès
```

#### Vérifier si l'utilisateur a un abonnement
```php
if (hasSubscription($pdo, $userId, 'agency')) {
    // Utilisateur a accès à Agency
}
```

#### Obtenir les abonnements actifs
```php
$subscriptions = getUserSubscriptions($pdo, $userId);
// Array de plans actifs avec slug, nom, description, etc.
```

#### Gérer les abonnements (Admin)
```php
// Donner accès
grantSubscription($pdo, $userId, 'rh');

// Révoquer accès
revokeSubscription($pdo, $userId, 'rh');
```

### 3. **Sidebars spécifiques**

Chaque service a sa propre sidebar:
- `inc/sidebar_syndic.php` - Service Syndic
- `inc/sidebar_rh.php` - Service RH (voir dashboard_rh.php)
- `inc/sidebar_agency.php` - Service Agency
- `inc/sidebar_proprietaire.php` - Service Propriétaire

**Utilisation dans les pages:**
```php
<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>
<aside class="mbi-sidebar">
    <!-- Navigation du service -->
</aside>
```

## 🔐 Sécurité

### Chaque page protégée doit commencer par:
```php
<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/subscriptions.php';

require_login();
requireSubscription($GLOBALS['pdo'], current_user_id(), 'service_slug');
// Page protégée...
?>
```

Remplacer `'service_slug'` par l'un de:
- `'syndic'`
- `'rh'`
- `'agency'`
- `'proprietaire'`

## 📋 Exemple Complet

### Dashboard RH protégé
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/subscriptions.php';

require_login();
requireSubscription($GLOBALS['pdo'], current_user_id(), 'rh');

$pdo = $GLOBALS['pdo'];
$userId = current_user_id();
?><!DOCTYPE html>
<html>
<head>...</head>
<body>
<?php require_once __DIR__ . '/inc/sidebar_rh.php'; ?>
<main class="mbi-main">
    <!-- Contenu RH -->
</main>
</body>
</html>
```

## 📱 Intégration avec les Pages Existantes

### pages à mettre à jour (ajouter la protection):
```
rh_salaires.php          → requireSubscription($pdo, $userId, 'rh')
rh_conges.php            → requireSubscription($pdo, $userId, 'rh')
rh_modeles.php           → requireSubscription($pdo, $userId, 'rh')
dashboard_agency.php     → requireSubscription($pdo, $userId, 'agency')
biens.php                → requireSubscription($pdo, $userId, 'agency')
dashboard_proprietaire.php → requireSubscription($pdo, $userId, 'proprietaire')
```

## 🎨 Styling des Sidebars

Tous les sidebars utilisent les même classes CSS (déjà définies dans les pages):
```css
.mbi-sidebar { /* Sidebar fixe */ }
.mbi-sidebar-section { /* Titre de section */ }
.mbi-nav { /* Liste navigation */ }
.mbi-nav li a { /* Liens */ }
.mbi-nav li a.active { /* Lien actif */ }
```

## 🔑 Fonctionnalités Admin

### Gérer les abonnements (à créer: `admin_subscriptions.php`)
```php
// Page d'administration pour attribuer/révoquer des abonnements
require_once 'inc/subscriptions.php';

if ($_POST['action'] === 'grant') {
    grantSubscription($pdo, $_POST['user_id'], $_POST['service']);
}
if ($_POST['action'] === 'revoke') {
    revokeSubscription($pdo, $_POST['user_id'], $_POST['service']);
}
```

## ✅ Checklist de Mise en Œuvre

- [ ] Créer les tables (exécuter `sql/subscriptions_schema.sql`)
- [ ] Inclure `inc/subscriptions.php` partout
- [ ] Ajouter `requireSubscription()` aux pages protégées
- [ ] Remplacer sidebars dans chaque service
- [ ] Mettre à jour `default.php` pour rediriger vers `landing.php`
- [ ] Créer page admin pour gérer les abonnements
- [ ] Tester chaque service avec/sans abonnement
- [ ] Configuration de la date d'expiration (optionnel)

## 🐛 Troubleshooting

### "Table 'mois_clos' doesn't exist"
→ Les tables sont créées automatiquement par `subscriptions.php`

### L'utilisateur ne voit rien après login
→ Vérifier que `landing.php` est la page de redirection après login

### Les sidebars ne s'affichent pas
→ Vérifier que les chemins sont corrects (`__DIR__ . '/inc/sidebar_X.php'`)

## 🚀 Prochaines Étapes

1. **Créer `admin_subscriptions.php`** pour gérer les abonnements
2. **Ajouter les expirations** avec cron job pour les mettre à jour
3. **Créer les tableaux de bord** propres à chaque service (ils existent déjà, juste à protéger)
4. **Ajouter les limits de permissions** par rôle dans chaque service

---

**Créé le:** 26 Mars 2026
**Portail:** MaBoxImmo v2.0
