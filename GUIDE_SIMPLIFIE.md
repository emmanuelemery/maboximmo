# 🎯 Système Simplifié: Rôles = Services

## ❌ AVANT (Complexe)
- Tables séparées `subscription_plans` + `user_subscriptions`
- 4 sidebars différentes (`sidebar_syndic.php`, `sidebar_rh.php`, etc.)
- Système d'abonnement à gérer

## ✅ APRÈS (Simplifié)

### 🔑 Concept Principal
**Les rôles utilisateur déterminent directement les services accessibles**

```
Rôle ID = 1 (Admin)          → Accès à: Syndic + RH + Agency + Propriétaire
Rôle ID = 2 (Manager)        → Accès à: Syndic + RH + Agency
Rôle ID = 3 (Collaborateur)  → Accès à: RH
Rôle ID = 4 (Syndic)         → Accès à: Syndic
Rôle ID = 5 (Propriétaire)   → Accès à: Propriétaire
Rôle ID = 6 (Locataire)      → Accès à: Propriétaire
```

### 📁 Fichiers Clés

#### 1. `inc/roles_services.php` - Mapping Rôles ↔ Services
```php
function getAvailableServices($roleId)
    // Retourne: ['syndic' => {...}, 'rh' => {...}, 'agency' => {...}]

function hasServiceAccess($roleId, $service)
    // Vérifie si l'utilisateur peut accéder à 'rh', 'agency', etc.

function requireServiceAccess($roleId, $service)
    // Protège une page (redirect si pas d'accès)
```

#### 2. `inc/sidebar.php` - Une seule sidebar dynamique
```php
<?php require_once __DIR__ . '/inc/sidebar.php'; ?>
```
- S'adapte automatiquement au rôle
- Affiche uniquement les services accessibles
- Une navigation unique pour tous

#### 3. `landing.php` - Page d'accueil
- Affiche les services selon le rôle
- Cards vertes (✓ Accessible) si accès
- Aucun système d'abonnement

---

## 🚀 Utilisation

### Exemple: Protéger une page RH

**Avant (compliqué):**
```php
require_once 'inc/subscriptions.php';
requireSubscription($pdo, $userId, 'rh');
require_once 'inc/sidebar_rh.php';
```

**Après (simplifié):**
```php
require_once 'inc/roles_services.php';
$roleId = current_role_id();
requireServiceAccess($roleId, 'rh');
require_once 'inc/sidebar.php';
```

### Exemple: Vérifier l'accès
```php
$roleId = current_role_id();

if (hasServiceAccess($roleId, 'agency')) {
    // L'utilisateur peut accéder à Agency
}

// Ou directement dans un template:
<?php if (hasServiceAccess($roleId, 'rh')): ?>
    <!-- Afficher le contenu RH -->
<?php endif; ?>
```

### Exemple: Afficher les services accessibles
```php
$services = getAvailableServices($roleId);
foreach ($services as $slug => $config) {
    echo $config['nom'];        // "Agency", "RH", etc.
    echo $config['description']; // Description du service
    echo $config['dashboard'];   // dashboard_agency.php, etc.
}
```

---

## 📋 Checklist de Migration

- [ ] **Supprimer** les fichiers inutiles:
  - ❌ `sql/subscriptions_schema.sql`
  - ❌ `inc/subscriptions.php`
  - ❌ `inc/sidebar_syndic.php`
  - ❌ `inc/sidebar_rh.php`
  - ❌ `inc/sidebar_agency.php`
  - ❌ `inc/sidebar_proprietaire.php`

- [ ] **Créer/Modifier** les fichiers:
  - ✅ `inc/roles_services.php` (nouveau)
  - ✅ `inc/sidebar.php` (nouveau - remplace les 4 autres)
  - ✅ `landing.php` (mis à jour)

- [ ] **Mettre à jour** les pages existantes:
  ```
  dashboard_syndic.php       → Ajouter requireServiceAccess($roleId, 'syndic')
  dashboard_rh.php           → Ajouter requireServiceAccess($roleId, 'rh')
  dashboard_agency.php       → Ajouter requireServiceAccess($roleId, 'agency')
  dashboard_proprietaire.php → Ajouter requireServiceAccess($roleId, 'proprietaire')
  rh_salaires.php            → Ajouter requireServiceAccess($roleId, 'rh')
  rh_conges.php              → Ajouter requireServiceAccess($roleId, 'rh')
  // ... etc pour toutes les pages
  ```

- [ ] **Remplacer** les imports de sidebar:
  ```php
  // ❌ Avant
  <?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

  // ✅ Après
  <?php require_once __DIR__ . '/inc/sidebar.php'; ?>
  ```

- [ ] **Tester** chaque rôle:
  - Admin (roleId=1) voit tous les services
  - Manager (roleId=2) voit Agency+RH+Syndic
  - Collaborateur (roleId=3) voit RH
  - etc.

---

## 🔧 Personnaliser le Mapping

Éditer `inc/roles_services.php`, fonction `getRolesServices()`:

```php
$services = [
    1 => ['syndic', 'rh', 'agency', 'proprietaire'],  // Admin
    2 => ['syndic', 'rh', 'agency'],                  // Manager
    3 => ['rh'],                                       // Collaborateur
    4 => ['syndic'],                                   // Syndic
    5 => ['proprietaire'],                             // Propriétaire
    6 => ['proprietaire'],                             // Locataire
];
```

Ajouter des pages à chaque service dans `getServicePages()`:

```php
$pages = [
    'rh' => [
        ['url' => 'dashboard_rh.php', 'nom' => '⊞ Dashboard', 'icon' => '⊞'],
        ['url' => 'rh_salaires.php', 'nom' => '💶 Salaires', 'icon' => '💶'],
        ['url' => 'rh_conges.php', 'nom' => '🏖️ Congés', 'icon' => '🏖️'],
        // Ajouter vos pages ici
    ],
    // ...
];
```

---

## 💡 Avantages

✅ **Moins de code** - Pas de tables d'abonnement
✅ **Moins de fichiers** - 1 sidebar au lieu de 4
✅ **Utilise les rôles existants** - Pas de nouvelle logique
✅ **Flexible** - Changer les droits = modifier 2 fonctions
✅ **Plus maintenable** - Tout en un seul fichier

---

## ❓ FAQ

**Q: Et si je veux que certains users aient des accès spéciaux?**
A: Créer un rôle personnalisé (roleId=7, 8, etc.) et ajouter son mapping

**Q: Comment ajouter un nouvel utilisateur à un service?**
A: Changer son rôle (UPDATE users SET id_role = 3 WHERE id = X)

**Q: Les 4 services peuvent-ils être accessibles par d'autres pages?**
A: Oui, protéger chaque page avec `requireServiceAccess($roleId, 'service')`

---

**Mis à jour:** 26 Mars 2026
**Portail:** MaBoxImmo v2.1 (Système Simplifié)
