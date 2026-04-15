# 📋 CARTOGRAPHIE COMPLÈTE DES PAGES EXISTANTES

**Date:** 2026-03-24
**État:** Audit complet (REGISTRES + TRUBOX)

---

## 📊 STATISTIQUES GLOBALES

| Source | Type | Nombre | Modèle |
|--------|------|--------|--------|
| **REGISTRES** | Pages PHP | 124+ | Legacy RH + Mandats |
| **TRUBOX** | Pages PHP | 48+ | RH simplifiée + Tableau de bord |
| **MABOXIMMO** | Pages PHP | 12+ | Annonces + API |
| **Total Pages** | | **184+** | À classifier |

---

## 🗂️ CLASSIFICATION PAR MODULE

### 📊 MA BOX AGENCY (RH + Mandats + Organisation)

#### Authenticification & Accès
```
✅ REGISTRES:
   - auth.php (authentification)
   - acces_refuse.php (erreur 403)
   - admin_set_password.php (reset password)
   - cs_login.php (login custom)

✅ TRUBOX:
   - login.php (login RH)
   - logout.php (logout)
   - index.php (redirection)
```

#### Dashboard / Accueil
```
✅ REGISTRES:
   - dashboard.php (vue générale)

✅ TRUBOX:
   - dashboard.php (RH dashboard)
```

#### Organisation / RH
```
✅ REGISTRES (complexe):
   - ajouter_collaborateur.php (création agent)
   - collaborateur_conges.php (gestion congés)
   - creation_salaire_user.php (création salaire)

✅ TRUBOX (simplifié):
   - organigramme.php (structure RH)
   - salaire.php (fiche salaire)
   - user_creation.php (création user)
   - user_edit.php (édition user)
   - ajax/rh_org_list.php (liste orga)
   - ajax/rh_org_save.php (sauvegarde orga)
   - ajax/rh_save.php (sauvegarde RH)
   - cards/rh_user.php (card user)
```

**⚠️ OBSERVATION:** TRUBOX = version simplifiée de REGISTRES
→ À fusionner: garder logique REGISTRES, UI TRUBOX

#### Mandats (cœur métier)
```
✅ REGISTRES:
   - ajouter_mandat.php (création)
   - details_mandat.php (consultation)
   - details_mandat_admin.php (vue admin)
   - edition_mandat.php (édition)
   - ajouter_mandant.php (création mandant)
   - detail_mandant.php (fiche mandant)
```

#### Réunions / Assemblées
```
✅ REGISTRES:
   - ajouter_reunion.php (création réunion)
   - detail_reunion.php (fiche réunion)
   - annulation_reunion.php (annulation)
   - ajouter_point_odj.php (point ordre du jour)
   - ajout_point_odj.php (alternative)
   - ajout_point_odj_ajax.php (AJAX ODJ)
   - detail_point.php (point détail)
   - compte_rendu.php (CR réunion)
   - create_tache_from_point.php (tâche depuis point)
   - export_pdf_registres.php (export réunion)
```

#### Tâches
```
✅ REGISTRES:
   - create_tache_from_point.php (tâche depuis ODJ)
   - (autres pages tâches probables)
```

#### Immeubles / Syndic
```
✅ REGISTRES:
   - ajouter_immeuble.php (création immeuble)
   - ajax_info_immeuble.php (AJAX infos)
   - ajouter_etablissement.php (création établissement)
   - etablissements.php (liste établissements)
   - ajouter_contrat.php (contrat immeuble)
   - contrats_syndic.php (liste contrats syndic)
```

#### Salaires / Comptabilité
```
✅ REGISTRES:
   - creation_salaire_user.php
   - enregistrer_champ_salaire.php

✅ TRUBOX:
   - salaire.php (gestion salaire)
   - cards/salaire_actions.php
   - ajax/rh_save.php
   - includes/ui/filters_salaire.php
```

#### Factures
```
✅ REGISTRES:
   - ajouter_facture.php (création facture)
```

#### Gestion Collaborateurs
```
✅ REGISTRES:
   - envoyer_cv_direct.php
   - envoyer_cr_direct.php
   - export_recap_conges.php

✅ TRUBOX:
   - ajax/users_cards.php
   - ajax/users_rh_cards.php
   - ajax/user_color_update.php
   - cards/access.php
```

---

### 🏢 MA BOX SYNDIC (Copropriétés + Documents)

#### Copropriétés / Immeubles
```
✅ REGISTRES:
   - ajouter_immeuble.php
   - ajouter_etablissement.php
   - etablissements.php
   - ajouter_contrat.php
   - contrats_syndic.php
   - ajax_info_immeuble.php
```

#### Documents
```
✅ REGISTRES:
   - export_pdf.php (export documents)
   - export_pdf_registres.php
   - delete_photo.php (suppression photo)
```

---

### 🎯 MA BOX IMMO (Annonces + Biens)

#### Annonces
```
✅ MABOXIMMO:
   - bien_recherche.php (recherche)
   - bien_ajouter.php (création)
   - Api endpoints pour annonces
```

#### Leads
```
✅ MABOXIMMO (probable):
   - Gestion leads (crée lors des recherches)
```

---

### 👨 MA BOX PRO (Bailleur particulier)

#### Espace Bailleur
```
✅ MABOXIMMO:
   - proprietaire_documents.php (mes documents)
   - proprietaire_biens.php (mes biens)
   - proprietaire_contrats.php (mes baux)
```

---

### 🏛️ ADMINISTRATION

#### Utilisateurs
```
✅ REGISTRES:
   - admin_set_password.php (reset password admin)

✅ TRUBOX:
   - user_creation.php
   - user_edit.php
   - ajax/users_cards.php
```

#### Sociétés / Agences
```
✅ REGISTRES:
   - ajouter_societe.php (création société)
   - ajouter_etablissement.php (création agence/établissement)
   - etablissements.php (liste)

✅ TRUBOX:
   - societe.php (page société)
   - includes/ui/societe_filters.php
```

#### Rôles & Permissions
```
✅ TRUBOX:
   - cards/access.php (gestion accès)
   - ajax/users_cards.php
```

---

## 🔧 COMPOSANTS PARTAGÉS (Includes/UI)

### TRUBOX (Moderne)
```
✅ includes/ui/sidebar.php (navigation)
✅ includes/ui/topbar.php (en-tête)
✅ includes/ui/filters.php (filtres génériques)
✅ includes/ui/filters_organigramme.php (filtres RH)
✅ includes/ui/filters_salaire.php (filtres salaire)
✅ includes/ui/session_adapter.php (gestion session)
✅ includes/ui/topbar_organigramme.php
✅ includes/ui/topbar_salaire.php
```

### TRUBOX (API & Config)
```
✅ api/agenda_today.php (API agenda)
✅ ajax/rh_org_list.php (API orga)
✅ ajax/rh_org_save.php (API sauvegarde)
✅ config/db.php (BD config)
✅ config/config.php (config générale)
✅ includes/auth.php
✅ includes/config.php
✅ includes/db.php
✅ includes/init.php
✅ includes/http.php
✅ includes/agenda_oauth_config.php
✅ oauth/google_callback.php (intégration Google)
```

---

## 📑 PAGES À CLASSIFICATION INCERTAINE

```
❓ REGISTRES:
   - emails_entrants.php (inbox emails)
   - go_registres.php (TRUBOX: redirection vers registres)
   - (nombreuses pages non listées)
```

---

## 🎯 ORGANISATION CIBLE MABOXIMMO

```
public_html/
├── index.php (HOME page)
│
├── dashboard/
│   └── index.php (TB Accueil)
│
├── ma-box-agency/
│   ├── rh/
│   │   ├── collaborateurs/
│   │   ├── congés/
│   │   └── salaires/
│   ├── mandats/
│   │   ├── liste.php
│   │   ├── creation.php
│   │   ├── details.php
│   │   └── renouvellement.php
│   ├── organisation/
│   │   ├── taches.php
│   │   ├── reunions.php
│   │   └── calendrier.php
│   └── immeubles/
│       ├── liste.php
│       ├── fiche.php
│       └── documents.php
│
├── ma-box-immo/
│   ├── biens/
│   ├── annonces-location/
│   ├── annonces-transaction/
│   ├── proprietaires/
│   ├── locataires/
│   ├── leads/
│   └── visites/
│
├── ma-box-syndic/
│   ├── coproprietes/
│   ├── documents/
│   ├── assemblees/
│   └── interventions/
│
├── ma-box-pro/
│   ├── mes-biens/
│   ├── mes-documents/
│   ├── revision-loyers/
│   └── suivi/
│
├── admin/
│   ├── utilisateurs/
│   ├── roles/
│   ├── societes/
│   ├── agences/
│   └── seo/
│
├── api/
│   ├── rh/
│   ├── mandats/
│   ├── immeubles/
│   └── immo/
│
├── inc/
│   ├── sidebar.php
│   ├── topbar.php
│   ├── auth.php
│   ├── db.php
│   └── CacheManager.php (NOUVEAU)
│
└── css/
    └── style.php (design cohérent)
```

---

## 🚀 STRATÉGIE D'INTÉGRATION

### ÉTAPE 1: FUSION COMPOSANTS UI
```
✅ Prendre sidebar + topbar de TRUBOX (moderne)
✅ Adapter pour MABOXIMMO structure
✅ Ajouter Ma Box Immo / Syndic / Pro
```

### ÉTAPE 2: PAGES RH / MANDATS
```
✅ Copier pages REGISTRES (logique métier)
✅ Restyle avec UI TRUBOX
✅ Adapter BD (fusion tables)
```

### ÉTAPE 3: PAGES IMMO
```
✅ Pages MABOXIMMO déjà là
✅ Intégrer dans structure globale
✅ Ajouter Ma Box Pro (nouveauté)
```

### ÉTAPE 4: PAGES SYNDIC
```
✅ Créer depuis pages immeuble REGISTRES
✅ Adapter pour syndic bénévole
```

---

## 🎨 PROCHAINE ÉTAPE

**HOME PAGE DESIGN** qui unifie:
- ✅ Dashboard (alertes, raccourcis, indicateurs)
- ✅ Navigation (sidebar vers 6 boîtes)
- ✅ Design cohérent (TRUBOX modern + REGISTRES robuste)

---

## 📌 FICHIERS À CONSERVER ABSOLUMENT

```
🔒 REGISTRES:
   - Toute logique mandats (métier critique)
   - RH complexe (salaires, congés, contrats)
   - Mandants + Immeubles (données clients)

🔒 TRUBOX:
   - UI sidebar + topbar (moderne)
   - Filtres génériques (réutilisable)
   - OAuth Google (intégration utile)

🔒 MABOXIMMO:
   - Annonces + API (portail)
   - SEO tables (cache + redirects)
   - Propriétaires/locataires (data immo)
```

---

## ✅ STATUT

| Phase | Task | Status |
|-------|------|--------|
| 0 | Cartographie pages | ✅ DONE |
| 0 | Classification modules | ✅ DONE |
| 0 | Analyse composants | ✅ DONE |
| 1 | Design HOME page | ⏳ NEXT |
| 2 | Intégration code | 🔄 Après validation |
| 3 | Tests fonctionnels | 🔄 Après intégration |

