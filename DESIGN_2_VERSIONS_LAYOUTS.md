# 🎨 2 VERSIONS DE LAYOUTS - MABOXIMMO

**Concept:** Navigation horizontale (pas de sidebar permanent) + accès selon rôle

---

## 📋 DEUX VERSIONS DISTINCTES

### VERSION 1️⃣: PAGE COMMERCIALE (Clients)

**Pour:** Agences, Bailleurs, Syndics
**Accès:** Rubriques générales uniquement (Ma Box Immo, Ma Box Pro, etc.)
**Navigation:** Horizontale en en-tête + menu footer

```
┌─────────────────────────────────────────────────────────────┐
│ LOGO         [Menu horizontal]        [Login/Profil]        │
│ MABOXIMMO    • Ma Box Immo            [Déconnexion]         │
│              • Ma Box Agency                                  │
│              • Ma Box Syndic                                  │
│              • Ma Box Pro                                     │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                                                               │
│                    HERO SECTION                              │
│                   "Ma Box Immo"                              │
│              Discover our properties...                       │
│                  [Search Annonces]                           │
│                                                               │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│              BIENS MIS EN AVANT POUR VOUS                    │
│            ┌────────┐  ┌────────┐  ┌────────┐               │
│            │ Carte  │  │ Carte  │  │ Carte  │               │
│            │ Bien 1 │  │ Bien 2 │  │ Bien 3 │               │
│            └────────┘  └────────┘  └────────┘               │
│                                                               │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  MA BOX AGENCY              │  MA BOX SYNDIC                 │
│  ├─ RH Services            │  ├─ Gestion Copro             │
│  ├─ Mandats Gérés          │  ├─ Documents                 │
│  ├─ Immeubles              │  ├─ Assemblées                │
│  └─ Organisation           │  └─ Interventions             │
│                             │                                │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  MA BOX PRO                 │  MA BOX IDÉES                  │
│  ├─ Mes Biens              │  ├─ Suggestions               │
│  ├─ Documents              │  ├─ Améliorations             │
│  ├─ Suivi Loyers           │  └─ Forum Feedback            │
│  └─ Révisions              │                                │
│                                                               │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│              MA BOX COMMUNITY                                │
│  ├─ Actualités  ├─ Conseils  ├─ Partenaires  ├─ Réseaux    │
│                                                               │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  TESTIMONIALES CLIENTS (Photos + Avis)                       │
│  "Excellente plateforme pour gérer mes biens"               │
│                                                               │
├─────────────────────────────────────────────────────────────┤
│                     FOOTER                                   │
│  Contact | Conditions | RGPD | © 2026 MABOXIMMO            │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

**Caractéristiques:**
- ✅ Navigation horizontale simple
- ✅ Aucune sidebar
- ✅ Focus sur les 6 boîtes principales
- ✅ Sections empilées verticalement
- ✅ Full-width responsive
- ✅ Approchable pour les clients

---

### VERSION 2️⃣: PAGE ADMIN (Complet)

**Pour:** Administrateurs MABOXIMMO
**Accès:** TOUT (Utilisateurs, Sociétés, Agences, SEO, Logs, etc.)
**Navigation:** Sidebar + Topbar

```
┌────────────────────────────────────────────────────────────┐
│ ☰ [Toggle]  MABOXIMMO ADMIN        [Notifs] [User] ⚙️      │
└────────────────────────────────────────────────────────────┘

SIDEBAR (200px, collapsible)       MAIN CONTENT
┌──────────────────┐               ┌──────────────────────────┐
│ 🏠 Dashboard     │               │ ADMIN DASHBOARD          │
│                  │               │                          │
│ 👥 Utilisateurs  │               │ ┌─ ALERTES SYSTÈME       │
│ • Créer user     │               │ │ • Failed logins: 2     │
│ • Éditer user    │               │ │ • Backup échoué        │
│ • Rôles          │               │ │ • Quota DB: 85%        │
│                  │               │                          │
│ 🏢 Sociétés      │               │ ┌─ INDICATEURS ADMIN     │
│ • Créer          │               │ │ • Users: 48            │
│ • Lister         │               │ │ • Sociétés: 8          │
│ • Éditer         │               │ │ • Agences: 24          │
│                  │               │ │ • Annonces: 1,243      │
│ 🏪 Agences       │               │                          │
│ • Créer          │               │ ┌─ ACTIONS RAPIDES       │
│ • Lister         │               │ │ [Import données]        │
│ • Zones          │               │ │ [Export rapport]        │
│                  │               │ │ [Backup BD]             │
│ 🔍 SEO           │               │ │ [Logs système]          │
│ • Landing pages  │               │                          │
│ • Search terms   │               │ ┌─ MONITORING            │
│ • Redirects      │               │ │ CPU: 32%                │
│ • Domains        │               │ │ RAM: 58%                │
│                  │               │ │ BD: 125GB               │
│ 🎨 Branding      │               │                          │
│ • Couleurs       │               │ ┌─ DERNIÈRES ACTIONS     │
│ • Logos          │               │ │ • Jean C. login 10mn   │
│ • Templates      │               │ │ • Import: 450 annonces │
│                  │               │ │ • Backup complété      │
│ 📋 Logs          │               │                          │
│ • Système        │               │                          │
│ • Utilisateurs   │               │                          │
│ • BD             │               │                          │
│                  │               │                          │
│ 💾 Sauvegardes   │               │                          │
│ • Auto           │               │                          │
│ • Manuel         │               │                          │
│ • Restaurer      │               │                          │
│                  │               │                          │
│ 🚪 Déconnecter  │               │                          │
└──────────────────┘               └──────────────────────────┘
```

**Caractéristiques:**
- ✅ Sidebar navigable (collapsible)
- ✅ Tous les modules d'admin
- ✅ Monitoring système
- ✅ Actions critiques (backup, import)
- ✅ Logs détaillés
- ✅ Gestion complète

---

## 🔐 SYSTÈME DE RÔLES & ACCÈS

```
RÔLE: ADMIN MABOXIMMO
├─ Accès: PAGE ADMIN (sidebar complet)
├─ Modules: Utilisateurs, Sociétés, Agences, SEO, Logs, Branding
└─ Actions: Créer/Éditer/Supprimer tout

RÔLE: ADMIN AGENCE
├─ Accès: PAGE AGENCE (sidebar partiel)
├─ Modules: RH, Mandats, Immeubles, Équipe, Analytics
└─ Actions: Créer/Éditer/Supprimer dans son agence

RÔLE: AGENT IMMOBILIER
├─ Accès: PAGE COMMERCIALE
├─ Modules: Ma Box Immo (visibilité complète), Ma Box Agency (sa partie)
└─ Actions: Créer annonces, voir mandats, gérer leads

RÔLE: BAILLEUR PARTICULIER
├─ Accès: PAGE COMMERCIALE
├─ Modules: Ma Box Pro (mes biens), Ma Box Immo (recherche)
└─ Actions: Consulter biens, documents, suivi loyers

RÔLE: SYNDIC BÉNÉVOLE
├─ Accès: PAGE COMMERCIALE
├─ Modules: Ma Box Syndic (sa copro), Ma Box Community
└─ Actions: Consulter documents, assemblées, votes
```

---

## 🌐 NAVIGATION RESPONSIF

### Desktop (1200+px)
```
VERSION COMMERCIALE:
- En-tête horizontal simple
- Menu déroulant au clic sur "Menu"
- Contenu full-width

VERSION ADMIN:
- Sidebar fixe 200px
- Contenu: calc(100% - 200px)
```

### Tablet (768-1199px)
```
VERSION COMMERCIALE:
- En-tête sticky
- Menu hamburger ☰ (overlay)
- Contenu full-width

VERSION ADMIN:
- Sidebar collapsible (toggle ☰)
- Contenu: fluide
```

### Mobile (<768px)
```
VERSION COMMERCIALE:
- En-tête sticky
- Menu hamburger ☰ (overlay)
- Contenu full-width stacked

VERSION ADMIN:
- Sidebar OFF par défaut
- Menu hamburger ☰ pour ouvrir sidebar overlay
- Topbar sticky avec logo + toggle
- Contenu full-width
```

---

## 🔄 FLUX DE NAVIGATION

### ACCUEIL (Public, pas loggé)
```
maboximmo.fr
     ↓
[LOGIN]
```

### APRÈS LOGIN

```
Si Admin MABOXIMMO:
    login ↓ PAGE ADMIN (sidebar + dashboard admin)

Si Admin Agence:
    login ↓ PAGE AGENCE (sidebar partiel + dashboard agence)

Si Agent:
    login ↓ PAGE COMMERCIALE + barre admin privée top

Si Bailleur:
    login ↓ PAGE COMMERCIALE + icon "Mes accès"

Si Syndic:
    login ↓ PAGE COMMERCIALE + accès Ma Box Syndic
```

---

## 📐 HEADER/TOPBAR COMMUN

### Version COMMERCIALE
```
┌───────────────────────────────────────────────────────┐
│ 🔗 LOGO           [Menu hamburger ☰]  [User ▼]       │
│ MABOXIMMO                                [Déco]       │
│                                                        │
│ Menu déroulant (au clic ☰):                          │
│ • Ma Box Immo                                         │
│ • Ma Box Agency                                       │
│ • Ma Box Syndic                                       │
│ • Ma Box Pro                                          │
│ • Ma Box Idées                                        │
│ • Ma Box Community                                    │
└───────────────────────────────────────────────────────┘
```

### Version ADMIN
```
┌───────────────────────────────────────────────────────┐
│ ☰ [Sidebar toggle]  🏢 ADMIN      [🔔] [👤 ▼] [⚙️]  │
│                                                        │
│ Sidebar toggle = collapse/expand sidebar (200px)      │
│ Notif = 🔔 système + utilisateurs                    │
│ User = dropdown (profil, settings, logout)           │
│ Settings = ⚙️ (paramètres admin)                     │
└───────────────────────────────────────────────────────┘
```

---

## 🎨 INTEGRATION COULEURS (Palette 1 recommandée)

### VERSION COMMERCIALE
```
En-tête: Bleu pétrole #0d6b7d (fond)
         Blanc #ffffff (texte + logo)
         Vert kaki #8aac7a (accents, boutons)

Hero:    Blanc #ffffff (fond)
         Bleu pétrole #0d6b7d (titre)

Cards:   Vert kaki #8aac7a (fond)
         Bleu pétrole #0d6b7d (text primaire)
         Gris #6b7280 (text secondaire)

Boutons: Vert kaki #8aac7a (principal)
         Bleu pétrole #0d6b7d (secondaire)

Section alternée: Beige #faf7f2 (fond)

Footer:  Bleu pétrole #0d6b7d (fond)
         Blanc #ffffff (texte)
         Doré #c9a86a (accents liens)
```

### VERSION ADMIN
```
Sidebar: Bleu pétrole foncé #0d5563 (fond)
         Blanc #ffffff (texte)
         Vert kaki #8aac7a (hover items)

Topbar:  Bleu pétrole #0d6b7d (fond)
         Blanc #ffffff (texte + icones)

Content: Blanc #ffffff (fond)
         Gris #6b7280 (text secondaire)
         Bleu pétrole #0d6b7d (accents liens)

Cards:   Blanc #ffffff (fond)
         Border gris léger #e5e7eb
         Bleu pétrole #0d6b7d (titles)

Alertes: Orange #f59e0b (warning)
         Rouge #ef4444 (danger)
         Vert #10b981 (success)
         Bleu #0ea5e9 (info)
```

---

## ✅ RÉSUMÉ

| Aspect | Version Commerciale | Version Admin |
|--------|------------------|--------------|
| **Navigation** | Horizontale en-tête | Sidebar + Topbar |
| **Sidebar** | ❌ Non | ✅ Oui (200px) |
| **Accès** | 6 boîtes + Community | Tout (Utilisateurs, Logs, etc.) |
| **Utilisateurs** | Agents, Bailleurs, Syndics | Admins MABOXIMMO |
| **Focus** | Contenu métier | Gestion système |
| **Layout** | Full-width stacked | 2 colonnes |
| **Menu** | Hamburger simple | Sidebar navigable |

---

## 🚀 PROCHAINES ÉTAPES

1. **Tu choisis les 1-2 palettes couleurs?**
2. **Je crée les 2 HTML/CSS skeletons** (Commerciale + Admin)
3. **On intègre les sections** (Hero, Cards, Services, etc.)
4. **On teste le responsive** (Desktop, Tablet, Mobile)
5. **On adapte les pages existantes** dans cette structure

Ça te convient? 👍

