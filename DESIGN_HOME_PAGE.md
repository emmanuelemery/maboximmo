# 🎨 DESIGN HOME PAGE - MABOXIMMO

**Philosophie:** Dashboard professionnel moderne + Navigation claire vers 6 modules

---

## 📐 LAYOUT GLOBAL

```
┌─────────────────────────────────────────────────────────────────┐
│  LOGO MABOXIMMO   [Recherche]              [Notifs] [Profil] ☰  │
│  (sidebar toggle)                                                 │
├────────────────────────────────────────────────────────────────┤
│                                                                   │
│  SIDEBAR FIXE     │  CONTENU PRINCIPAL                           │
│  (240px)         │  (fluid)                                      │
│                   │                                              │
│                   │  ╔════════════════════════════════════════╗ │
│                   │  ║  DASHBOARD - ACCUEIL                   ║ │
│                   │  ╠════════════════════════════════════════╣ │
│                   │  ║                                        ║ │
│                   │  ║ 🔔 ALERTES (Section 1)                 ║ │
│                   │  ║ ├─ ⚠️  Mandat expire: -5 jours         ║ │
│                   │  ║ ├─ 📞 Appel à faire: Jean Dupont       ║ │
│                   │  ║ └─ 📬 Nouveau lead: Appt Lyon 3e       ║ │
│                   │  ║                                        ║ │
│                   │  ║ 🎯 RACCOURCIS RAPIDES (Section 2)      ║ │
│                   │  ║ ┌──────────┬──────────┬──────────┐    ║ │
│                   │  ║ │ ➕ Créer │ ➕ Nouveau│ 📋 Voir │    ║ │
│                   │  ║ │ Annonce  │  Mandat  │  Tâches │    ║ │
│                   │  ║ └──────────┴──────────┴──────────┘    ║ │
│                   │  ║ ┌──────────┬──────────┐              ║ │
│                   │  ║ │ 📅 RDV   │ 📊 Stats│              ║ │
│                   │  ║ │ Auj.     │ Mois    │              ║ │
│                   │  ║ └──────────┴──────────┘              ║ │
│                   │  ║                                        ║ │
│                   │  ║ 📊 INDICATEURS (Section 3)            ║ │
│                   │  ║ ┌──────────────┬──────────────┐       ║ │
│                   │  ║ │ Annonces     │ Mandats      │       ║ │
│                   │  ║ │ 🔥 24 actives│ 156 gérés    │       ║ │
│                   │  ║ │ ↑ 5 cette s. │ 12 renou.    │       ║ │
│                   │  ║ └──────────────┴──────────────┘       ║ │
│                   │  ║ ┌──────────────┬──────────────┐       ║ │
│                   │  ║ │ Leads        │ Utilisateurs │       ║ │
│                   │  ║ │ 18 ce mois   │ 8 actifs     │       ║ │
│                   │  ║ │ +8% vs mois  │ 2 admins     │       ║ │
│                   │  ║ └──────────────┴──────────────┘       ║ │
│                   │  ║                                        ║ │
│                   │  ║ 📰 ACTUALITÉS (Section 4)             ║ │
│                   │  ║ ├─ [24/03] Nouveau feature SEO        ║ │
│                   │  ║ ├─ [22/03] Mise à jour plateforme    ║ │
│                   │  ║ └─ [20/03] Tip: Optimiser vos annonces║ │
│                   │  ║                                        ║ │
│                   │  ╚════════════════════════════════════════╝ │
│                   │                                              │
└───────────────────────────────────────────────────────────────┘
```

---

## 🎯 SIDEBAR NAVIGATION

### Version BUREAU (240px fixe)

```
┌──────────────────┐
│ 📊 MABOXIMMO     │  (Logo + Titre)
├──────────────────┤
│ 🏠 Accueil       │  → Dashboard
├──────────────────┤
│ 🏢 Ma Box Agency │
│  ├─ 👥 RH        │
│  ├─ 📋 Mandats   │
│  ├─ 🏢 Immeubles │
│  └─ 📅 Org.      │
├──────────────────┤
│ 🏠 Ma Box Immo   │
│  ├─ 📍 Biens     │
│  ├─ 📢 Annonces  │
│  ├─ 👤 Proprio   │
│  └─ 📞 Leads     │
├──────────────────┤
│ 🏛️ Ma Box Syndic│
│  ├─ 🏘️ Copro     │
│  ├─ 📄 Docs      │
│  └─ 🤝 Asmblées │
├──────────────────┤
│ 👨 Ma Box Pro    │
│  ├─ 🏠 Mes biens │
│  └─ 📊 Suivi     │
├──────────────────┤
│ 💡 Ma Box Idées  │
├──────────────────┤
│ 🌍 Ma Box Comm.  │
├──────────────────┤
│ ⚙️ Admin        │
├──────────────────┤
│ 🚪 Déconnexion  │
└──────────────────┘
```

### Version MOBILE (collapse menu)

```
☰ SIDEBAR TOGGLE
   └─ Affiche sidebar overlay
```

---

## 📱 RESPONSIVE

### Desktop (1200+px)
- Sidebar fixe 240px
- Contenu: calc(100% - 240px)
- 4 colonnes pour KPI

### Tablet (768-1199px)
- Sidebar fixe 200px
- Contenu: calc(100% - 200px)
- 2 colonnes pour KPI

### Mobile (<768px)
- Sidebar cachée (toggle)
- Contenu: 100%
- 1 colonne KPI
- Sections empilées

---

## 🎨 THÈME COULEURS (À VALIDER)

### Option MODERNE (Sombre)
```
Fond:          #0f172a (bleu très sombre)
Sidebar:       #1a2542 (gris bleu)
Accent:        #0ea5e9 (bleu ciel)
Success:       #10b981 (vert)
Warning:       #f59e0b (orange)
Danger:        #ef4444 (rouge)
Texte primaire: #f1f5f9 (gris clair)
Texte second.: #cbd5e1 (gris moyen)
Cards:         #1e293b (gris sombre)
```

### Option CLASSIQUE (Clair)
```
Fond:          #ffffff (blanc)
Sidebar:       #f3f4f6 (gris très clair)
Accent:        #2563eb (bleu)
Success:       #059669 (vert)
Warning:       #d97706 (orange)
Danger:        #dc2626 (rouge)
Texte primaire: #1f2937 (gris foncé)
Texte second.: #6b7280 (gris moyen)
Cards:         #ffffff (blanc)
Border:        #e5e7eb (gris léger)
```

**À TESTER:** Préférences de ton branding

---

## 📦 COMPOSANTS DÉTAIL

### SECTION 1: ALERTES (Dynamique)

```
┌─ 🔔 ALERTES (3 max, scroll si plus)
│
├─ ⚠️ [Type: Mandat]
│  "Mandat DUPONT expire: -5 jours"
│  [Voir détail →]
│
├─ 📞 [Type: Tâche]
│  "Appel client Jean Dupont à faire"
│  [Marquer fait →]
│
└─ 📬 [Type: Lead]
   "Nouveau lead: Appt 3e étage, 65m²"
   [Voir lead →]
```

**Données:**
- Mandats expirant < 10 jours
- Tâches du jour non faites
- Nouveaux leads (< 24h)

---

### SECTION 2: RACCOURCIS (Personnalisables)

```
4 gros boutons cliquables:

┌─────────────┐  ┌─────────────┐
│ ➕ Nouvelle  │  │ ➕ Nouveau  │
│   Annonce   │  │   Mandat    │
│             │  │             │
│ [Icône]     │  │ [Icône]     │
└─────────────┘  └─────────────┘

┌─────────────┐  ┌─────────────┐
│ 📋 Mes      │  │ 👥 Mes      │
│   Tâches    │  │   Leads     │
│             │  │             │
│ [Icône]     │  │ [Icône]     │
└─────────────┘  └─────────────┘
```

**À PERSONNALISER par user:**
- Admin voit: Users, Sociétés, Import
- Agent voit: Annonce, Mandat, Leads, Tâches
- Bailleur voit: Mes annonces, Mes docs, Suivi

---

### SECTION 3: KPI INDICATEURS (Statistiques)

```
4 cartes avec chiffres clés:

┌─ Annonces Actives
│  Chiffre: 24
│  Variation: ↑ 5 cette semaine (+26%)
│  Sous-stats:
│    - 15 location
│    - 9 vente
│    - 0 syndic

├─ Mandats Gérés
│  Chiffre: 156
│  Variation: ↑ 3 ce mois (+2%)
│  Sous-stats:
│    - 12 en renouvellement
│    - 2 à signer

├─ Leads (Ce mois)
│  Chiffre: 18
│  Variation: +8% vs mois dernier
│  Sous-stats:
│    - 7 qualifiés
│    - 11 en cours

└─ Utilisateurs Actifs
   Chiffre: 12
   Variation: 2 admins, 8 agents
   Sous-stats:
    - 2 derniers 7 jours inactifs
```

---

### SECTION 4: ACTUALITÉS (Fil simple)

```
┌─ 📰 ACTUALITÉS & MISES À JOUR
│
├─ [24/03] 🎉 Nouveau: Génération landing pages auto
│  "Créez automatiquement vos pages de recherche"
│  [Lire plus →]
│
├─ [22/03] 🔧 Mise à jour SEO Cache
│  "Performance 400x plus rapide!"
│  [Détails →]
│
└─ [20/03] 💡 Tip du jour
   "Optimisez vos annonces avec 3 photos de qualité"
   [Conseil complet →]
```

**Source:**
- Blog MABOXIMMO (articles manuels)
- Notifications système (auto)
- Tips aléatoires

---

## 🎯 FLOW UTILISATEUR (Personas)

### Admin MABOXIMMO
```
Voir: Users, Sociétés, Import, SEO
Raccourcis: Créer user, Import données, Logs
KPI: Total users, Total annonces, Total mandats, Derniers imports
```

### Agent Immobilier
```
Voir: Annonces, Mandats, Leads, Clients
Raccourcis: Créer annonce, Nouveau mandat, Mes leads, Tâches
KPI: Mes annonces, Mes mandats, Mes leads, Mes tâches
```

### Bailleur (Ma Box Pro)
```
Voir: Mes biens, Documents, Suivi, Révision
Raccourcis: Ajouter bien, Voir documents, Suivi loyer
KPI: Mes biens, Mes documents, Suivi loyer, Derniers mouvements
```

### Syndic Bénévole
```
Voir: Copropriétés, Documents, Assemblées
Raccourcis: Créer document, Assembée, Votes
KPI: Immeubles gérés, Documents, Décisions, Interventions
```

---

## 🎨 TYPOGRAPHIE & SPACING

### Polices
```
Titres H1:      30px, bold, #primary
Titres H2:      24px, bold, #secondary
Titres H3:      18px, semi-bold
Body text:      14px, regular
Small text:     12px, regular
```

### Spacing
```
Padding:  16px (standard), 24px (large)
Margin:   8px (small), 16px (standard), 24px (large)
Border:   1px, #border-color
Radius:   8px (small cards), 12px (large)
```

### Ombres (Subtle)
```
Card shadows:    0 1px 3px rgba(0,0,0,0.1)
Hover shadows:   0 4px 12px rgba(0,0,0,0.15)
```

---

## 🔄 INTERACTIVITÉ

### Hover States
```
- Boutons: Fond + teinte plus claire
- Cards: Légère ombre + border
- Links: Underline + couleur accent
```

### Animations
```
- Fade in: 300ms ease-in
- Slide: 250ms ease-out
- Hover: 150ms ease-in-out
```

---

## 📌 ÉLÉMENTS DYNAMIQUES (À METTRE À JOUR)

```
✅ Affichage en temps réel:
   - Alertes (via CacheManager)
   - KPI (requête BD rapide)
   - Actualités (depuis table blog)

✅ Personnalisation:
   - Raccourcis par rôle
   - Couleurs par société
   - Onglets par module accessible
```

---

## 🚀 INTÉGRATION TECHNIQUE

### Structure HTML (Skeleton)
```
<html>
  <head>
    <title>MABOXIMMO Dashboard</title>
    <link rel="stylesheet" href="/css/style.css">
  </head>
  <body class="layout-sidebar">

    <!-- Topbar -->
    <header class="topbar">
      <div class="topbar-left">
        <button id="sidebar-toggle">☰</button>
        <input type="search" placeholder="Recherche...">
      </div>
      <div class="topbar-right">
        <button class="notif-bell">🔔 <span class="badge">3</span></button>
        <div class="user-menu">👤 [Nom] ▼</div>
      </div>
    </header>

    <div class="layout-container">

      <!-- Sidebar -->
      <aside class="sidebar">
        <!-- Navigation items -->
      </aside>

      <!-- Main Content -->
      <main class="main-content">
        <div class="dashboard">

          <!-- Section 1: Alertes -->
          <section class="dashboard-section alerts">...</section>

          <!-- Section 2: Raccourcis -->
          <section class="dashboard-section shortcuts">...</section>

          <!-- Section 3: KPI -->
          <section class="dashboard-section kpi">...</section>

          <!-- Section 4: Actualités -->
          <section class="dashboard-section news">...</section>

        </div>
      </main>

    </div>

    <script src="/js/app.js"></script>
  </body>
</html>
```

### PHP Backend (Logique)
```php
<?php
require_once './inc/bootstrap.php';

// 1. Récupérer alertes
$alerts = getAlerts($pdo, $userId);

// 2. Récupérer raccourcis (selon rôle)
$shortcuts = getShortcuts($pdo, $userRole);

// 3. Récupérer KPI
$kpi = getKPI($pdo, $userId);

// 4. Récupérer actualités
$news = getNews($pdo, 5);

// 5. Render
echo render('dashboard', [
    'alerts' => $alerts,
    'shortcuts' => $shortcuts,
    'kpi' => $kpi,
    'news' => $news
]);
?>
```

---

## ✅ PROCHAINES ÉTAPES

1. **Tu confirmes design?**
   - Couleurs OK?
   - Layout OK?
   - Sections OK?

2. **Si oui:**
   - Je crée CSS + HTML skeleton
   - Tu fournis logo/branding
   - On intègre données réelles

3. **Alors on peut:**
   - Copier pages existantes
   - Les intégrer dans la structure
   - Tester tout ensemble

