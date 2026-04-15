# 📱 Guide du Flux Utilisateur - MaBoxImmo

## Flux Général

```
┌─────────────────────────────────────────────────────────────────────┐
│                    VISITEUR NON CONNECTÉ                             │
│                                                                       │
│  1️⃣  Accès à accueil.php (page commerciale)                          │
│      ├─ Présentation des 4 services (Syndic, RH, Agency, Proprio)    │
│      ├─ Tarifs et fonctionnalités                                    │
│      ├─ Annonces immobilières                                        │
│      └─ Bouton "Se connecter"                                        │
│                                                                       │
│  2️⃣  Click "Se connecter" → login.php                                │
│      ├─ Formulaire email + mot de passe                              │
│      ├─ Vérification des credentials en base de données              │
│      └─ Remplissage de la session (id, prenom, nom, id_role, etc)    │
│                                                                       │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
                       ↓
┌─────────────────────────────────────────────────────────────────────┐
│                    UTILISATEUR CONNECTÉ                              │
│                                                                       │
│  3️⃣  Redirection vers landing.php (sélection des services)           │
│      ├─ Affiche les services accessibles selon le rôle               │
│      ├─ Rôle 1 (Admin) → Syndic + RH + Agency + Proprio             │
│      ├─ Rôle 2 (Manager) → Syndic + RH + Agency                     │
│      ├─ Rôle 3 (Collaborateur) → RH seulement                        │
│      ├─ Rôle 4 (Syndic) → Syndic seulement                           │
│      ├─ Rôle 5 (Propriétaire) → Proprio seulement                    │
│      └─ Rôle 6 (Locataire) → Proprio seulement                       │
│                                                                       │
│  4️⃣  Click sur un service → Dashboard correspondant                  │
│                                                                       │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📊 Dashboard RH (exemple pour rôle 3 - Collaborateur)

### Structure de la Page

```
┌────────────────────────────────────────────────────────────────┐
│  SIDEBAR RH                │  CONTENU PRINCIPAL                 │
│  ─────────────────────────────────────────────────────────────│
│  📊 RH Manager            │  📊 Dashboard RH                    │
│  ─────────────────────────│  Bienvenue [Prénom]                │
│  Accès Rapide             │                                     │
│  💶 Gestion Salaires   ←──┼─ NAVIGATION CARDS (4 grandes cards)│
│  🏖️  Gestion Congés    ←──┼─ ├─ 💶 Gestion Salaires             │
│  📁 Documents          ←──┼─ ├─ 🏖️  Gestion Congés              │
│  📧 Emails Équipe      ←──┼─ ├─ 📁 Documents RH                 │
│                           │  └─ 📧 Emails Équipe                │
│  Navigation               │                                     │
│  ⊞ Retour Agency          │  📊 CHIFFRES CLÉS (4 KPIs)          │
│  🚪 Déconnexion           │  ├─ Salaires: 5 (ce mois)           │
│                           │  ├─ Congés en attente: 2            │
│                           │  ├─ Congés approuvés: 3             │
│                           │  └─ Documents archivés: 12          │
│                           │                                     │
│                           │  📋 AGENDA DES TÂCHES RH (9 cartes) │
│                           │  ├─ 📅 Décompte congés (info)       │
│                           │  ├─ 💰 Saisie salaires (warning)    │
│                           │  ├─ ✓ Validation congés (warning)   │
│                           │  ├─ 📧 Mail solde congés (critique) │
│                           │  ├─ 📄 Documents obligatoires       │
│                           │  ├─ 🚗 Attestations assurance       │
│                           │  ├─ 🏥 Attestation mutuelle         │
│                           │  ├─ 👤 Entretiens individuels       │
│                           │  └─ ⚖️  Documents légaux             │
│                           │                                     │
└────────────────────────────────────────────────────────────────┘
```

### Sections du Dashboard

#### 1️⃣ **Navigation Cards (4 grandes cartes)**
- Chacune redirige vers un module spécifique
- Hover: animation et bordure lumineuse
- Style cohérent avec landing.php

#### 2️⃣ **Chiffres Clés (KPIs)**
- Salaires du mois courant
- Congés en attente de validation
- Congés approuvés ce mois
- Documents archivés total

#### 3️⃣ **Agenda des Tâches RH (9 tâches)**
- Décompte congés mensuel (info - vert)
- Saisie des salaires (warning - orange) - à partir du 25
- Validation demandes congés (warning - orange)
- Mail solde congés (critique - rouge) - fin mars
- Documents obligatoires (critique - rouge) - janvier
- Attestations assurance & carte grise (critique - rouge)
- Attestation mutuelle (critique - rouge) - pour non-adhérents
- Entretiens individuels (info - vert)
- Documents légaux obligatoires (critique - rouge)

---

## 🔐 Accès aux Services selon le Rôle

| Rôle | ID | Services Accessibles | Dashboard Principal |
|------|----|--------------------|-------------------|
| Admin | 1 | Syndic, RH, Agency, Proprio | landing.php (choix) |
| Manager | 2 | Syndic, RH, Agency | landing.php (choix) |
| Collaborateur | 3 | RH | dashboard_rh.php |
| Syndic | 4 | Syndic | dashboard_syndic.php |
| Propriétaire | 5 | Proprio | dashboard_proprietaire.php |
| Locataire | 6 | Proprio | dashboard_proprietaire.php |

---

## 🌐 Architecture Technique

### Points d'Entrée

- **`accueil.php`** - Page commerciale (visiteurs non connectés)
- **`login.php`** - Formulaire de connexion
- **`default.php`** - Redirection automatique (accueil.php ou landing.php)

### Pages Protégées

- **`landing.php`** - Portail de sélection des services (require_login)
- **`dashboard_rh.php`** - Dashboard RH (require_login)
- **`dashboard_agency.php`** - Dashboard Agency (require_login)
- **`dashboard_syndic.php`** - Dashboard Syndic (require_login)
- **`dashboard_proprietaire.php`** - Dashboard Propriétaire (require_login)

### Fonctions de Contrôle d'Accès

**Dans `inc/roles_services.php`:**

```php
getAvailableServices($roleId)      // Récupère les services accessibles
hasServiceAccess($roleId, $service) // Vérifie l'accès à un service
requireServiceAccess($roleId, $service) // Protège une page
getSidebarForRole($roleId)         // Détermine la sidebar
```

---

## 📝 Session Utilisateur

Après connexion réussie, `$_SESSION` contient:

```php
$_SESSION['id']           // ID utilisateur
$_SESSION['user_id']      // ID utilisateur (alias)
$_SESSION['username']     // Nom d'utilisateur
$_SESSION['email']        // Email
$_SESSION['prenom']       // Prénom
$_SESSION['nom']          // Nom de famille
$_SESSION['id_role']      // ID rôle (1-6)
$_SESSION['id_societe']   // ID société (si applicable)
$_SESSION['id_agence']    // ID agence (si applicable)
$_SESSION['available_accesses'] // Tableau des accès disponibles
```

---

## 🚀 Flux de Navigation Détaillé

### Scénario 1: Collaborateur (rôle 3)

```
1. accueil.php → "Se connecter"
   ↓
2. login.php (saisie email/mot de passe)
   ↓
3. Vérification BDD → rôle_id = 3
   ↓
4. landing.php → affiche "RH" uniquement
   ↓
5. Click "RH" → dashboard_rh.php
   ↓
6. Dashboard affiche:
   - 4 grandes cards (Salaires, Congés, Docs, Emails)
   - KPIs en dessous
   - Agenda des 9 tâches RH
```

### Scénario 2: Admin (rôle 1)

```
1. accueil.php → "Se connecter"
   ↓
2. login.php (saisie email/mot de passe)
   ↓
3. Vérification BDD → rôle_id = 1
   ↓
4. landing.php → affiche 4 services (Syndic, RH, Agency, Proprio)
   ↓
5. Admin peut choisir n'importe quel service
   - Click "RH" → dashboard_rh.php
   - Click "Agency" → dashboard_agency.php
   - Click "Syndic" → dashboard_syndic.php
   - Click "Proprio" → dashboard_proprietaire.php
```

---

## 🎨 Design et Couleurs

### Thème Sombre Cohérent

```css
--bg: #1a2a3a (fond principal)
--bg-soft: #232f3f (fond secondaire)
--sidebar: #1f2835 (sidebar)
--ink: #d0e4ff (texte)
--muted: #7a91a8 (texte grisé)
--accent: #66d9ff (accents cyan)
--accent-green: #7cf5d6 (accents verts)
--stroke: rgba(255,255,255,0.15) (bordures)
```

### Cartes de Service

- **Hover Effect**: Bordure lumineuse + légère élévation
- **Navigation Cards**: 240px min, large icône 36px
- **KPI Cards**: 160px min, valeur en grand
- **Task Cards**: Couleurs par priorité (info/warning/critical)

---

## ✅ Checklist de Vérification

- [ ] Accueil.php affiche bien les 4 services
- [ ] Login redirige vers landing.php
- [ ] Landing.php affiche les services selon le rôle
- [ ] Dashboard RH montre les 4 grandes cards
- [ ] Dashboard RH affiche les KPIs
- [ ] Dashboard RH affiche l'agenda des 9 tâches
- [ ] Sidebar RH donne accès aux modules (Salaires, Congés, Docs)
- [ ] Déconnexion fonctionne et retourne à accueil.php
- [ ] Responsive design fonctionne sur mobile

---

## 🔗 URLs Importantes

```
http://localhost/MaBoxImmo2026/public_html/accueil.php
→ Page commerciale

http://localhost/MaBoxImmo2026/public_html/login.php
→ Connexion

http://localhost/MaBoxImmo2026/public_html/landing.php
→ Portail de sélection (après connexion)

http://localhost/MaBoxImmo2026/public_html/dashboard_rh.php
→ Dashboard RH
```

---

**Créé le**: 2026-03-27
**Version**: 1.0
**État**: ✅ Production Ready
