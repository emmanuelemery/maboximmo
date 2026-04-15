# RH_AUDIT.md — Audit complet du module RH
**Projet :** MaBoxImmo2026  
**Date d'audit :** 2026-04-05  
**Total fichiers RH identifiés :** 76

---

## 1. INVENTAIRE DES FICHIERS RH

### Pages principales (`public_html/`)

#### Core / Dashboard

| Fichier | Lignes | Dernière modif. | Styles inline | Bloc `<style>` |
|---|---|---|---|---|
| rh_dashboard.php | 26 | 2026-03-27 | 0 | 0 |
| rh_dashboard_admin.php | 304 | 2026-03-30 | ~15 | 0 |
| rh_dashboard_manager.php | 304 | 2026-03-30 | ~15 | 0 |
| rh_dashboard_user.php | 304 | 2026-03-30 | ~15 | 0 |

#### Entretiens / Interviews

| Fichier | Lignes | Dernière modif. | Styles inline | Bloc `<style>` |
|---|---|---|---|---|
| rh_entretien_tenir.php | 2570 | 2026-03-30 | ~39 | 1 |
| rh_entretien_admin.php | 1219 | 2026-03-30 | ~32 | 1 |
| rh_entretien_vue_collaborateur.php | 1132 | 2026-03-31 | ~5 | 2 |
| rh_entretien_questionnaire_collaborateur.php | 852 | 2026-03-30 | ~4 | 1 |
| rh_entretien_config_societe.php | 515 | 2026-03-30 | ~11 | 1 |
| rh_entretien_auto_eval.php | 471 | 2026-03-30 | ~5 | 1 |
| rh_entretien_signature.php | 467 | 2026-03-29 | 0 | 1 |
| rh_entretien_synthese.php | 454 | 2026-03-29 | ~20 | 1 |
| rh_entretien_dashboard.php | 420 | 2026-03-29 | ~3 | 1 |
| rh_entretien_generer_pdf.php | 416 | 2026-03-29 | 0 | 0 |
| rh_entretien_ajouter.php | 394 | 2026-03-29 | ~34 | 1 |
| rh_entretien_jobs_runner.php | 288 | 2026-03-29 | 0 | 0 |
| rh_entretien_liste.php | 268 | 2026-03-30 | ~9 | 1 |
| rh_entretien_detail.php | 502 | 2026-03-29 | ~15 | 1 |

#### Congés / Absences

| Fichier | Lignes | Dernière modif. | Styles inline | Bloc `<style>` |
|---|---|---|---|---|
| rh_conges_validation.php | 723 | 2026-03-29 | ~23 | 1 |
| rh_conges.php | 666 | 2026-03-31 | ~24 | 1 |
| rh_conges_historiq.php | 565 | 2026-03-28 | ~22 | 1 |
| rh_conges_edit.php | 234 | 2026-03-28 | ~2 | 1 |

#### Salaires / Paie

| Fichier | Lignes | Dernière modif. | Styles inline | Bloc `<style>` |
|---|---|---|---|---|
| rh_salaires.php | 1563 | 2026-04-02 | ~24 | 2 |
| rh_salaires_detail.php | 888 | 2026-04-02 | ~43 | 1 |
| rh_salaires_user.php | 744 | 2026-04-02 | ~20 | 1 |
| rh_salaires_historiq.php | 409 | 2026-03-29 | ~9 | 1 |
| rh_salaires_user_list.php | 221 | 2026-03-31 | ~4 | 1 |
| rh_exporter_ik_pdf.php | 246 | 2026-03-30 | 0 | 0 |

#### Employés / Profils

| Fichier | Lignes | Dernière modif. | Styles inline | Bloc `<style>` |
|---|---|---|---|---|
| rh_profil.php | 1095 | 2026-04-01 | ~11 | 1 |
| rh_user.php | 614 | 2026-04-01 | ~20 | 1 |
| rh_user_historiq.php | 300 | 2026-03-28 | ~5 | 1 |
| rh_user_add.php | 217 | 2026-03-28 | ~4 | 1 |

#### Documents & Divers

| Fichier | Lignes | Dernière modif. | Styles inline | Bloc `<style>` |
|---|---|---|---|---|
| rh_indemnite_km.php | 2008 | 2026-03-30 | ~66 | 1 |
| rh_mails.php | 1308 | 2026-03-28 | ~63 | 1 |
| rh_documents.php | 1102 | 2026-03-31 | ~26 | 1 |
| rh_mail_templates.php | 405 | 2026-03-28 | ~9 | 1 |
| rh_modeles.php | 432 | 2026-03-29 | ~5 | 1 |

---

### Fichiers includes / helpers (`public_html/inc/`)

| Fichier | Lignes | Rôle |
|---|---|---|
| rh_sidebar.php | 487 | Navigation sidebar principale (1 bloc `<style>` embarqué) |
| rh_entretien_v3.php | 738 | Logique entretien V3 — scoring de base, axes radar |
| rh_entretien_v4.php | 549 | Logique entretien V4 — améliorations de calcul |
| rh_entretien_v5.php | 586 | **MOTEUR ACTIF** — job queue, alertes, recalcul asynchrone |
| rh_entretien_v6.php | 276 | Calculs enrichis, fonctionnalités avancées |
| rh_entretien_v7.php | 57 | Synthèse intelligente (IA, conclusions automatiques) |
| rh_entretien_catalog.php | 234 | Catalogue de formulaires / templates d'entretien |
| rh_helpers.php | 359 | Fonctions communes RH |
| rh_salaires_conges_pdf.php | 338 | Génération PDF paie/congés |
| rh_salaires_parser.php | 170 | Parsing des données de paie |
| rh_sepa.php | 87 | Support virements SEPA |

---

### API Endpoints (`public_html/api/` — 14 fichiers)

| Fichier | Lignes | Rôle |
|---|---|---|
| rh_entretien_save.php | 333 | Sauvegarde principale (charge V3→V6 en séquence) |
| rh_entretien_synthesis.php | 288 | Génération de synthèse |
| rh_entretien_ia.php | 242 | Intégration IA |
| rh_entretien_sync.php | 239 | Synchronisation |
| rh_entretien_catalog_save.php | 182 | Sauvegarde catalogue |
| rh_entretien_collab_action.php | 170 | Actions collaborateur |
| rh_profil_upload_doc.php | 106 | Upload documents profil |
| rh_profil_save_field.php | 97 | Sauvegarde champs profil |
| rh_profil_upload_avatar.php | 81 | Upload avatar |
| rh_entretien_save_question.php | 86 | Sauvegarde questions |
| rh_entretien_prep_save.php | 58 | Sauvegarde préparation |
| rh_profil_delete_doc.php | 36 | Suppression document |
| rh_entretien_prep_sync.php | 27 | Sync préparation |

---

### Fichiers legacy / backup

- `public_html/v2/rh_dashboard.php` — ancienne version du dashboard
- `public_html/v2/sidebar_rh.php` — ancienne sidebar
- `sql/rh_*_migration.php` — 5 scripts de migration SQL

---

## 2. VERSION ACTIVE DE rh_entretien

### Résultat : **V5 est le moteur principal**, V3/V4 en support, V6/V7 en extension

| Version | Nb références | Rôle |
|---|---|---|
| **V5** | **88 refs** | **MOTEUR ACTIF** — job queue asynchrone, alertes, recalcul global |
| V3 | 46 refs | Scoring de base avec étoiles et axes radar |
| V4 | 41 refs | Améliorations des calculs V3 |
| V6 | 35 refs | Calculs enrichis, fonctionnalités supplémentaires |
| V7 | 15 refs | Synthèse IA (conclusions auto, suggestions manager) |

### Preuve d'usage

- `api/rh_entretien_save.php` charge toutes les versions V3→V6 en séquence à chaque sauvegarde
- `rh_entretien_jobs_runner.php` traite les jobs async de V5
- `rh_entretien_questionnaire_collaborateur.php` déclenche le processing V5
- V7 est utilisé uniquement pour la génération de synthèse IA (15 appels)

---

## 3. PAGE RH REPRÉSENTATIVE — `rh_entretien_tenir.php`

**Fichier le plus complexe du module** (2 570 lignes) — formulaire principal de conduite d'entretien.

### Données chargées (PHP, lignes 1–320)

- Authentification : manager/admin uniquement
- Chargement de l'entretien depuis la BDD
- Vérification des permissions + mode lecture seule
- 11 rubriques avec critères et règles de scoring
- Réponses manager et pré-réponses collaborateur
- Templates de réponses et bibliothèque de phrases manager
- Scores courants

### 11 rubriques d'entretien

1. Ouverture
2. Bilan collaborateur
3. Valorisation
4. Performance
5. Comportement & Relationnel
6. Motivation
7. Adaptabilité
8. Compétences & Formation
9. Analyse RH *(manager uniquement)*
10. Plan d'action
11. Synthèse & Clôture

### Structure HTML

```html
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
    <!-- Meta, police Manrope, theme-init.php -->
    <style>/* CSS embarqué */</style>
</head>
<body>
    <?php require_once 'inc/rh_sidebar.php'; ?>

    <div class="tenir-main">
        <div class="top-bar">
            <!-- Titre, date, bandeau lecture seule, statut auto-eval -->
        </div>

        <div class="layout">
            <nav class="col-nav">
                <!-- Boutons de navigation par rubrique -->
            </nav>

            <main class="col-main" id="colMain">
                <!-- 11 panneaux de rubrique (critères, réponses, scoring) -->
            </main>

            <aside class="col-meta">
                <!-- Radar chart, scores, alertes -->
            </aside>
        </div>
    </div>

    <!-- JS : Alpine.js + scripts métier -->
</body>
</html>
```

---

## 4. STRUCTURE DU LAYOUT ACTUEL

### Sidebar

```php
<?php require_once 'inc/rh_sidebar.php'; ?>
```

- Fichier : `public_html/inc/rh_sidebar.php` (487 lignes)
- Position : fixée à gauche, largeur 248 px
- Thème sombre avec sections colorées par rôle
- Multi-rôles : Admin / Manager / User
- Collapse automatique sous 900 px (responsive)
- CSS embarqué dans un bloc `<style>` (lignes 85–220)

### Topbar

Pas de fichier séparé — **intégrée directement dans chaque page** via un `<div class="top-bar">`.

### Structure HTML type

```html
<html lang="fr" data-theme="dark">
<body>
    <?php include 'inc/rh_sidebar.php'; ?>  <!-- sidebar fixe -->

    <div class="[page]-main">               <!-- ex: tenir-main, mbi-main -->
        <div class="top-bar">               <!-- barre titre de page -->
            ...
        </div>
        <div class="layout">               <!-- grille multi-colonnes -->
            <nav class="col-nav">...</nav>
            <main class="col-main">...</main>
            <aside class="col-meta">...</aside>   <!-- optionnel -->
        </div>
    </div>
</body>
```

### Classes CSS principales

| Classe | Rôle |
|---|---|
| `data-theme="dark"` | Thème sombre (attribut sur `<html>`) |
| `mbi-sidebar` | Sidebar fixe gauche (248 px) |
| `mbi-main` / `tenir-main` | Wrapper principal du contenu |
| `mbi-content` | Zone de contenu intérieure |
| `top-bar` | Barre titre / actions de page |
| `layout` | Grille principale (nav + main + aside) |
| `col-nav` | Colonne navigation gauche |
| `col-main` | Colonne contenu principal |
| `col-meta` | Colonne métadonnées droite |
| `sb-section` | En-tête de section sidebar |
| `sb-nav` | Élément de navigation sidebar |
| `sb-active` | Page active dans la sidebar |
| `sb-badge` | Badge de rôle (MGR, ADM, SUP) |

---

## 5. LISTE DES COMPOSANTS / SOUS-MODULES RH

### A. Entretiens (14 pages + 13 API)

| Page | Rôle |
|---|---|
| rh_entretien_tenir.php | **Conduite de l'entretien** (page centrale) |
| rh_entretien_auto_eval.php | Auto-évaluation collaborateur |
| rh_entretien_questionnaire_collaborateur.php | Questionnaire pré-entretien |
| rh_entretien_admin.php | Interface d'administration des entretiens |
| rh_entretien_liste.php | Liste filtrée de tous les entretiens |
| rh_entretien_synthese.php | Génération de synthèse |
| rh_entretien_vue_collaborateur.php | Vue résultats côté collaborateur |
| rh_entretien_detail.php | Détail d'un entretien |
| rh_entretien_ajouter.php | Création d'un nouvel entretien |
| rh_entretien_config_societe.php | Configuration entretiens par société |
| rh_entretien_signature.php | Signature numérique de clôture |
| rh_entretien_jobs_runner.php | Traitement des jobs asynchrones (V5) |
| rh_entretien_dashboard.php | Tableau de bord / analytics |
| rh_entretien_generer_pdf.php | Export PDF de l'entretien |

### B. Congés / Absences (4 pages)

| Page | Rôle |
|---|---|
| rh_conges.php | Demandes de congés / suivi |
| rh_conges_edit.php | Modification d'une demande |
| rh_conges_historiq.php | Historique des congés |
| rh_conges_validation.php | Validation / refus par manager |

### C. Salaires / Paie (6 pages)

| Page | Rôle |
|---|---|
| rh_salaires.php | Gestion paie (1 563 lignes) |
| rh_salaires_detail.php | Détail d'un bulletin de salaire |
| rh_salaires_user.php | Vue collaborateur de sa paie |
| rh_salaires_user_list.php | Liste des éléments de paie |
| rh_salaires_historiq.php | Historique paie |
| rh_exporter_ik_pdf.php | Export PDF indemnités kilométriques |

### D. Employés / Utilisateurs (4 pages)

| Page | Rôle |
|---|---|
| rh_user.php | Liste et gestion des employés |
| rh_user_add.php | Ajout d'un nouvel employé |
| rh_user_historiq.php | Historique de l'employé |
| rh_profil.php | Fiche profil (données personnelles, documents) |

### E. Documents (1 page)

| Page | Rôle |
|---|---|
| rh_documents.php | Archivage documents RH (contrats, certifications…) |

### F. Indemnités & Notes de frais (1 page)

| Page | Rôle |
|---|---|
| rh_indemnite_km.php | Calcul et suivi IK (2 008 lignes) |

### G. Communication / Mails (3 pages)

| Page | Rôle |
|---|---|
| rh_mails.php | Campagnes e-mail et historique (1 308 lignes) |
| rh_mail_templates.php | Gestion des templates d'e-mail |
| rh_modeles.php | Modèles génériques (courriers, documents) |

---

## 6. ANALYSE DES STYLES INLINE

### Classement par intensité

| Niveau | Fichiers concernés | Nb styles inline |
|---|---|---|
| **Lourd** | rh_indemnite_km, rh_mails, rh_salaires_detail | 43–66 |
| **Moyen** | rh_entretien_tenir, rh_entretien_ajouter, rh_conges | 20–39 |
| **Léger** | Dashboards, pages liste, pages historique | 0–15 |
| **Aucun** | PDF generators, rh_entretien_signature | 0 |

### Patterns récurrents

```html
style="display:flex; gap:12px; align-items:center"
style="margin:0; padding:8px 16px"
style="display:none"   <!-- toggles JS -->
style="background:rgba(255,255,255,0.05); border-radius:8px"
style="color:rgba(255,255,255,0.6)"
```

> **Note :** Toutes les pages ont exactement 1 bloc `<style>` embarqué pour les styles spécifiques à la page. Les styles inline servent principalement aux états dynamiques (JS) et aux micro-ajustements de layout.

---

## SYNTHÈSE GLOBALE

| Dimension | Valeur |
|---|---|
| Total fichiers RH | 76 |
| Pages PHP autonomes | 34 |
| Endpoints API | 14 |
| Fichiers helpers/inc | 11 |
| Fichiers legacy/backup | 17 |
| Sous-modules identifiés | 8 |
| Version entretien active | **V5** (avec V3/V4 en support, V6/V7 en extension) |
| Page la plus complexe | rh_entretien_tenir.php (2 570 lignes) |
| Fichier avec le + de styles inline | rh_indemnite_km.php (66) |
| Dernière modification récente | rh_salaires.php / rh_salaires_detail.php (2026-04-02) |
