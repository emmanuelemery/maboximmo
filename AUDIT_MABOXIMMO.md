# AUDIT TECHNIQUE MABOXIMMO
**Date** : 2026-04-03  
**Répertoire analysé** : `public_html/`  
**Auditeur** : Claude Code (claude-sonnet-4-6)

---

## ÉTAPE 1 — CARTOGRAPHIE DE LA STRUCTURE

### Architecture globale

| Dimension | Valeur |
|-----------|--------|
| **Framework front** | Vanilla JavaScript (DOM manipulation directe — pas de React/Vue/Angular) |
| **Framework back** | PHP 7.2+ avec PDO (MySQL) |
| **Routing** | Routing PHP direct — fichiers `.php` servis directement, `api/` pour les endpoints |
| **Design System / Lib composants** | Aucune librairie externe (pas Bootstrap, Tailwind, MUI) — CSS 100% propriétaire |
| **Bundler** | Aucun (pas Webpack, Vite, etc.) |
| **Tests** | Aucun test automatisé identifié |

### Arborescence principale

```
public_html/
├── css/                   11 fichiers CSS (4 014 lignes)
│   └── vars/              4 fichiers variables thématiques (137 lignes)
├── js/                    4 fichiers JS (1 660 lignes)
├── api/                   72 endpoints PHP
├── inc/                   34 fichiers include/helpers
├── admin/                 12 fichiers d'administration (3 829 lignes)
├── config/                fichiers de configuration
├── lib/phpmailer/         Envoi de mails
├── sql/                   Fichiers migration
├── pages/                 Pages dynamiques
├── services/              Services métier
├── images/, icons/        Assets statiques
├── *.php                  93 pages PHP à la racine
└── *.html                 2 maquettes statiques
```

### Fichiers de pages (93 fichiers PHP à la racine)

**Pages d'authentification / accueil**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `landing.php` | 937 | Page d'accueil marketing |
| `default.php` | 916 | Redirection selon rôle |
| `login.php` | 123 | Authentification principale |
| `login_agency.php` | — | Auth agence |
| `access_choice.php` | 83 | Choix de rôle |
| `auth.php` | 5 | Middleware auth |
| `login_traitement.php` | 94 | Traitement POST login |

**Module RH**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `rh_profil.php` | 1 095 | Profil collaborateur |
| `rh_salaires.php` | 1 563 | Tableau de bord paie |
| `rh_salaires_detail.php` | 888 | Détail fiche de paie |
| `rh_salaires_user_list.php` | 221 | Liste des users par salaire |
| `rh_conges.php` | — | Calendrier congés |
| `rh_conges_edit.php` | 234 | Édition congé |
| `rh_documents.php` | 1 102 | Documents collaborateurs |
| `rh_mails.php` | 1 308 | Gestion modèles mails |
| `rh_indemnite_km.php` | 2 008 | Indemnités kilométriques |
| `rh_user_add.php` | 217 | Création utilisateur RH |
| `rh_user_historiq.php` | 300 | Historique modifications user |
| `rh_entretien_tenir.php` | 2 570 | Conduite entretien |
| `rh_entretien_admin.php` | 1 219 | Admin entretiens |
| `rh_entretien_vue_collaborateur.php` | 1 132 | Vue collaborateur entretien |
| `rh_entretien_questionnaire_collaborateur.php` | 852 | Questionnaire auto-évaluation |
| `rh_entretien_liste.php` | 268 | Liste entretiens |
| `rh_entretien_jobs_runner.php` | 288 | Runner jobs entretiens |
| `rh_dashboard.php` | 26 | Tableau de bord RH (redirect) |
| `rh_dashboard_admin.php` | 304 | Dashboard admin RH |
| `rh_dashboard_manager.php` | 304 | Dashboard manager RH |

**Module Biens immobiliers**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `bien_ajouter.php` | 2 534 | Création/édition d'un bien |
| `bien_liste.php` | 754 | Liste des biens avec filtres |
| `bien_recherche.php` | 73 | Recherche publique |
| `portail.php` | 1 625 | Portail public immobilier |

**Module Agence**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `agence_portail.php` | 243 | Portail agence |
| `agence_inscription.php` | 1 036 | Inscription agence |
| `agence_user_create.php` | 171 | Création user agence |
| `dashboard_agency.php` | 1 406 | Dashboard agence |

**Administration**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `societe.php` | 2 016 | Gestion sociétés (multi-société) |
| `societe_super_admin.php` | 514 | Super-admin sociétés |
| `admin_user_create.php` | 204 | Création user admin |
| `admin_registres_access.php` | 145 | Gestion accès registres |
| `parametrage.php` | 106 | Paramétrage général |
| `design-system.php` | 2 355 | Éditeur Design System |
| `admin/admin_database.php` | 2 157 | Admin base de données |
| `admin/param_types_bien.php` | 213 | Paramétrage types de bien |
| `admin/param_chauffage.php` | 376 | Paramétrage chauffage |
| `admin/param_dependances.php` | 275 | Paramétrage dépendances |
| `admin/param_vues.php` | 193 | Paramétrage vues |

**Exports PDF**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `exporter_conges_pdf.php` | 201 | Export congés annuel PDF |
| `exporter_conges_mois_pdf.php` | 284 | Export congés mensuel PDF |
| `exporter_conges_mois_pdf_v2.php` | 284 | Export congés mensuel v2 |
| `exporter_conges_user_annuel_pdf.php` | 190 | Export congés par user |
| `rh_exporter_ik_pdf.php` | 246 | Export IK PDF |
| `exporter_salaires_conges_pdf.php` | 45 | Export salaires+congés |
| `debug_pdf_export.php` | 112 | Debug exports PDF |

**Pages Dev/Debug/Utilitaires (à retirer en production)**

| Fichier | Lignes | Rôle |
|---------|--------|------|
| `dev.php` | 117 | Page développeur |
| `dev_organisation.php` | 1 208 | Dev organisation |
| `debug_session.php` | 10 | Debug session |
| `test_db.php` | 10 | Test connexion BDD |
| `test_seo_cache.php` | 163 | Test cache SEO |
| `diagnostic_conges.php` | 57 | Diagnostic congés |
| `EXEMPLE_PAGE_PROTEGEE.php` | 93 | Exemple page protégée |
| `hash.php` | 2 | Utilitaire hash |

---

## ÉTAPE 2 — AUDIT CSS GLOBAL

### Fichiers CSS principaux

| Fichier | Lignes | Variables CSS (--) | !important | Imports externes | Dette CSS |
|---------|--------|-------------------|------------|------------------|-----------|
| `css/variables.css` | 146 | ✅ 99 variables globales | 0 | Aucun | **FAIBLE** |
| `css/style.css` | 1 469 | ✅ Utilise variables | 1 | `variables.css` | **MOYEN** |
| `css/style-maboximmo.css` | 386 | ❌ Aucune variable propre | 8 | Google Fonts | **ÉLEVÉ** |
| `css/minicard.css` | 274 | ❌ Pas de variables | 0 | Aucun | **FAIBLE** |
| `css/theme-rh.css` | 971 | ✅ Variables `--rh-*` | 1 | `vars/rh.css`, Google Fonts | **MOYEN** |
| `css/theme-pro.css` | 256 | ✅ Variables | 0 | Aucun | **FAIBLE** |
| `css/theme-agency.css` | 256 | ✅ Variables | 0 | Aucun | **FAIBLE** |
| `css/theme-syndic.css` | 256 | ✅ Variables | 0 | Aucun | **FAIBLE** |

### Fichiers de variables thématiques (`css/vars/`)

| Fichier | Lignes | Thème clair | Thème sombre |
|---------|--------|------------|-------------|
| `vars/rh.css` | 35 | ✅ | ✅ |
| `vars/agency.css` | 34 | ✅ | ✅ |
| `vars/syndic.css` | 34 | ✅ | ✅ |
| `vars/proprietaire.css` | 34 | ✅ | ✅ |

### Résumé CSS global

| Métrique | Valeur |
|----------|--------|
| **Total lignes CSS** | 4 151 lignes |
| **Total !important** | 10 occurrences (0.24% du total CSS) |
| **Variables CSS globales** | 99 variables (`variables.css`) |
| **Variables thématiques** | ~40 variables supplémentaires (vars/*.css) |
| **Styles inline dans pages PHP** | 908 occurrences sur 51 fichiers |
| **Architecture CSS** | `variables.css` → `style.css` → `theme-*.css` → `vars/*.css` |

### Observations spécifiques

- **`style.css`** : Typo ligne 262 : `var(--font-size-1xl)` — variable inexistante (probablement `--font-size-xl`). Serait silencieusement ignorée.
- **`style-maboximmo.css`** : 8 `!important` signalent un couplage fort ou des surcharges mal contrôlées. Pas de variables propres. À fusionner ou refactoriser.
- **`portail.php`** : Contient un bloc `<style>` inline significatif (760+ lignes de styles dans le HTML).
- **Styles inline** : 908 attributs `style=""` répartis dans 51 fichiers PHP — forte dispersion.

---

## ÉTAPE 3 — ANALYSE PAGE PAR PAGE

| Page | Lignes | CSS lié | Logique JS/API | Complexité UI | Dette CSS | Recommandation |
|------|--------|---------|----------------|--------------|-----------|----------------|
| `landing.php` | 937 | `style.css`, `variables.css` | Animations CSS, scroll | MOYENNE | MOYEN | **MIGRER** |
| `default.php` | 916 | `style.css` | Redirect PHP pur | FAIBLE | FAIBLE | **MIGRER** |
| `login.php` | 123 | `style.css` | Form POST | FAIBLE | FAIBLE | **MIGRER** |
| `access_choice.php` | 83 | `style.css` | Redirect | FAIBLE | FAIBLE | **MIGRER** |
| `portail.php` | 1 625 | `<style>` inline ~760L + `style.css` | Filtres GET, liste biens | ÉLEVÉE | **CRITIQUE** | **RÉÉCRIRE TOTAL** |
| `bien_recherche.php` | 73 | `style.css` | Filtres GET | FAIBLE | FAIBLE | **MIGRER** |
| `bien_liste.php` | 754 | `style.css`, `variables.css` | Filtres GET, pagination | MOYENNE | MOYEN | **MIGRER** |
| `bien_ajouter.php` | 2 534 | `style.css`, Google Maps | Google Places, upload, validation | **CRITIQUE** | ÉLEVÉ | **RÉÉCRIRE TOTAL** |
| `rh_profil.php` | 1 095 | `theme-rh.css`, `variables.css` | Fetch API, upload docs/avatar | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_salaires.php` | 1 563 | `theme-rh.css` | Calculs SEPA, CSV, Fetch API | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_salaires_detail.php` | 888 | `theme-rh.css` | Affichage fiche paie | MOYENNE | MOYEN | **MIGRER** |
| `rh_conges.php` | — | `theme-rh.css` | Calendrier, Fetch API CRUD | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_conges_edit.php` | 234 | `theme-rh.css` | Form POST | FAIBLE | FAIBLE | **MIGRER** |
| `rh_documents.php` | 1 102 | `theme-rh.css` | Upload/download docs, Fetch API | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_mails.php` | 1 308 | `theme-rh.css` | Éditeur templates, envoi mail | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_indemnite_km.php` | 2 008 | `theme-rh.css`, inline | Carte, calculs IK | **CRITIQUE** | ÉLEVÉ | **RÉÉCRIRE TOTAL** |
| `rh_entretien_tenir.php` | 2 570 | `theme-rh.css` | Formulaire long, multi-sections | **CRITIQUE** | ÉLEVÉ | **RÉÉCRIRE TOTAL** |
| `rh_entretien_admin.php` | 1 219 | `theme-rh.css` | Liste + actions | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_entretien_vue_collaborateur.php` | 1 132 | `theme-rh.css` | Lecture seule | MOYENNE | MOYEN | **MIGRER** |
| `rh_entretien_questionnaire_collaborateur.php` | 852 | `theme-rh.css` | Questionnaire auto-évaluation | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `rh_dashboard_admin.php` | 304 | `theme-rh.css` | Stats, tableaux | MOYENNE | FAIBLE | **MIGRER** |
| `rh_dashboard_manager.php` | 304 | `theme-rh.css` | Stats, tableaux | MOYENNE | FAIBLE | **MIGRER** |
| `rh_user_add.php` | 217 | `theme-rh.css` | Form création user | FAIBLE | FAIBLE | **MIGRER** |
| `rh_user_historiq.php` | 300 | `theme-rh.css` | Timeline modifications | MOYENNE | FAIBLE | **MIGRER** |
| `dashboard_agency.php` | 1 406 | `theme-agency.css` | Stats agence | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `agence_inscription.php` | 1 036 | `style.css` | Form multi-étapes | ÉLEVÉE | MOYEN | **RÉÉCRIRE UI** |
| `agence_portail.php` | 243 | `style.css` | Liste annonces | FAIBLE | FAIBLE | **MIGRER** |
| `societe.php` | 2 016 | `style.css`, inline | Gestion multi-société, duplication | ÉLEVÉE | ÉLEVÉ | **RÉÉCRIRE UI** |
| `societe_super_admin.php` | 514 | `style.css` | Admin cross-sociétés | MOYENNE | MOYEN | **MIGRER** |
| `parametrage.php` | 106 | `style.css` | Paramétrage général | FAIBLE | FAIBLE | **MIGRER** |
| `design-system.php` | 2 355 | Inline + CSS | Édition variables CSS, upload | ÉLEVÉE | ÉLEVÉ | **RÉÉCRIRE TOTAL** |
| `admin/admin_database.php` | 2 157 | `style.css` | Gestion tables BDD, CRUD | ÉLEVÉE | ÉLEVÉ | **RÉÉCRIRE TOTAL** |
| `admin/param_types_bien.php` | 213 | `minicard.css` | Minicard CRUD | FAIBLE | FAIBLE | **MIGRER** |
| `admin/param_chauffage.php` | 376 | `minicard.css` | Minicard CRUD | FAIBLE | FAIBLE | **MIGRER** |
| `admin/param_dependances.php` | 275 | `minicard.css` | Minicard CRUD | FAIBLE | FAIBLE | **MIGRER** |
| `admin/param_vues.php` | 193 | `minicard.css` | Minicard CRUD | FAIBLE | FAIBLE | **MIGRER** |

### Répartition des recommandations

| Recommandation | Nombre de pages | % |
|----------------|----------------|---|
| **MIGRER** | 20 | 55% |
| **RÉÉCRIRE UI** | 10 | 28% |
| **RÉÉCRIRE TOTAL** | 6 | 17% |

---

## ÉTAPE 4 — COMPOSANTS PARTAGÉS

| Composant | Fichier de définition | Lignes | Nb utilisations | Couplage CSS | Recommandation |
|-----------|----------------------|--------|-----------------|-------------|----------------|
| **Sidebar RH** | `inc/rh_sidebar.php` | 487 | 14+ pages RH | FORT (classes inline dynamiques) | **REFACTORISER** |
| **Sidebar général** | `inc/sidebar.php` | 299 | 10+ pages | MOYEN | **REFACTORISER** |
| **Sidebar agence** | `inc/sidebar_agency.php` | 432 | 4+ pages | FORT | **REFACTORISER** |
| **Sidebar propriétaire** | `inc/sidebar_proprietaire.php` | 42 | 2 pages | FAIBLE | **GARDER** |
| **Sidebar syndic** | `inc/sidebar_syndic.php` | 36 | 2 pages | FAIBLE | **GARDER** |
| **Header/Topbar** | `inc/header.php` | 141 | Toutes les pages | MOYEN (`style.css`) | **GARDER** |
| **Footer** | `inc/footer.php` | 42 | Pages publiques | FAIBLE | **GARDER** |
| **ActionBar** | `inc/actionbar.php` | 64 | Inconnu | FAIBLE | **GARDER** |
| **Mini-Cards** | `css/minicard.css` + `js/minicard.js` | 274 + 232 | 4 pages admin | FAIBLE (bien isolé) | **GARDER** |
| **Role Switcher** | `inc/role_switcher.php` | 55 | Inconnu | FAIBLE | **GARDER** |
| **Thème init** | `inc/theme-init.php` | 7 | Toutes les pages | FAIBLE | **GARDER** |
| **Auth** | `inc/auth.php` | 169 | Toutes les pages protégées | Aucun | **GARDER** |
| **CSRF** | `inc/csrf.php` | 56 | Toutes les pages avec formulaire | Aucun | **GARDER** |
| **Mailer** | `inc/mailer.php` | 96 | Pages envoi mail | Aucun | **GARDER** |
| **CacheManager** | `inc/CacheManager.php` | 198 | Via bootstrap | Aucun | **GARDER** |
| **Roles/Services** | `inc/roles_services.php` | 185 | Via bootstrap | Aucun | **GARDER** |
| **RH Helpers** | `inc/rh_helpers.php` | 359 | Pages RH | Aucun | **GARDER** |
| **SEPA** | `inc/rh_sepa.php` | 87 | Salaires | Aucun | **GARDER** |
| **Import mapper/parser** | `inc/bien_import_mapper.php` + `inc/bien_import_parser.php` | 690 + 286 | API import | Aucun | **GARDER** |
| **Entretiens v3-v7** | `inc/rh_entretien_v3.php` à `v7.php` | 738+586+549+276+57 | Entretiens | Aucun | **REFACTORISER** (5 versions actives !) |
| **`bien_import.js`** | `js/bien_import.js` | 1 017 | Page import bien | MOYEN (modales inline) | **REFACTORISER** |
| **`places.js`** | `js/places.js` | 393 | `bien_ajouter.php` | FAIBLE | **GARDER** |

### Point d'attention critique

Le composant **`inc/rh_entretien_v*.php`** existe en **7 versions** (v3 à v7 + variantes). C'est une accumulation de versions sans nettoyage des anciennes. La version active n'est pas clairement identifiable sans traçage des includes.

---

## ÉTAPE 5 — ENTITÉS BDD ET APPELS API

### Tables principales identifiées

| Table | Champs clés identifiés | Pages principales |
|-------|----------------------|-------------------|
| **users** | id, prenom, nom, email, telephone, adresse, date_naissance, nationalite, num_secu, civilite, permis_conduire, vehicule_*, indemnite_km, iban, bic, date_entree, date_sortie, fonction, type_contrat, temps_travail, id_agence, id_societe | 15+ pages |
| **biens** | id, reference_bien, designation, type_transaction, statut, surface_habitable, nb_pieces, nb_chambres, loyer_hc, charges_locataire, prix_vente, dpe_classe, date_creation, id_immeuble, id_type_bien, id_societe | `bien_*.php` |
| **immeubles** | id, adresse_1, adresse_2, code_postal, ville, pays, latitude, longitude, google_place_id | `bien_*.php` |
| **societes** | id, nom, id_agence | 15+ pages |
| **agences** | id, nom_agence | 6+ pages |
| **types_bien** | id, code, libelle | `bien_*.php`, `admin/` |
| **proprietaires** | id, ... | `bien_*.php` |
| **conges** | id, id_user, date_debut, date_fin, type, statut | `rh_conges*.php`, API congés |
| **salaires** | id, id_user, mois, montant, ... | `rh_salaires*.php` |
| **modele_salaire** | id, ... | API salaires |
| **rh_bank_history** | id, id_user, changed_by, changed_at, changed_fields, old_values, new_values | `rh_profil.php` |
| **bien_imports** | id, ... | `api/bien_import_*.php` |
| **bien_vues** | id_bien, ... | `bien_ajouter.php` |
| **bien_dependances_exterieurs** | id_bien, ... | `bien_ajouter.php` |
| **bien_types_chauffage** | id_bien, ... | `bien_ajouter.php` |
| **bien_energies** | id_bien, ... | `bien_ajouter.php` |
| **annonces** | id, ... | `bien_liste.php` (count) |

### Endpoints API (72 fichiers dans `api/`)

| Groupe fonctionnel | Endpoints | Volume |
|--------------------|-----------|--------|
| **Biens — import** | `bien_import_upload`, `bien_import_creer`, `bien_import_action`, `import_feedback` | 4 |
| **Biens — IA** | `bien_ai_generate`, `chatgpt_draft` | 2 |
| **Biens — géolocalisation** | `geocode_address`, `places_autocomplete`, `places_details` | 3 |
| **Propriétaires** | `proprietaire_chercher`, `proprietaire_creer` | 2 |
| **Congés** | `create_conge`, `delete_conge`, `update_conge`, `validate_conge`, `reject_conge`, `get_conge_detail`, `import_conges`, `seed_conges` | 8 |
| **Salaires** | `save_modele_salaire`, `toggle_modele_salaire`, `create_missing_salaries` | 3 |
| **Utilisateurs** | `update_user`, `update_user_password`, `assign_user_colors`, `rh_profil_save_field`, `rh_profil_upload_avatar` | 5 |
| **Documents RH** | `upload_user_doc`, `delete_user_doc`, `download_user_doc`, `rename_user_doc` | 4 |
| **Mails** | `get_mail_template`, `get_mail_templates`, `save_mail_template`, `delete_mail_template`, `upload_mail_attachment`, `delete_mail_attachment`, `send_mail_*` (×3) | 9 |
| **Entretiens RH** | `rh_entretien_*` (8 endpoints) | 8 |
| **Indemnités KM** | `ik_*` (9 endpoints) | 9 |
| **Migrations** | `run_migration`, `migrate_*` | 4 |
| **Autres** | Divers utilitaires | ~11 |

### Couplages forts identifiés

| Couplage | Description | Pages affectées | Sévérité |
|----------|-------------|-----------------|---------|
| **SQL direct dans pages** | Requêtes PDO directement dans les fichiers de vue — aucune couche service/repository | 40+ pages | ÉLEVÉE |
| **Styles inline dynamiques** | Classes CSS générées conditionnellement en PHP (`echo $class`) | 20+ pages | MOYENNE |
| **Logique métier dans HTML** | Calculs, transformations et formatage mélangés au HTML dans les pages PHP | 10+ pages | ÉLEVÉE |
| **Design System éditable** | `design-system.php` utilise `file_put_contents()` pour écrire les fichiers CSS — couplage système de fichiers/UI | 1 page | **CRITIQUE** |
| **Entretiens multi-versions** | 5 versions actives de `rh_entretien_v*.php` avec logique dupliquée | Toutes pages entretien | ÉLEVÉE |

---

## ÉTAPE 6 — SYNTHÈSE ET PLAN DE MIGRATION

### Score global de récupérabilité

**Score : 62 / 100**

| Dimension | Score | Commentaire |
|-----------|-------|-------------|
| Architecture CSS | 70/100 | Système de variables bien pensé, hiérarchie propre |
| Couverture fonctionnelle | 85/100 | Modules complets, API bien découpée |
| Qualité du code JS | 50/100 | Vanilla JS fonctionnel mais non structuré |
| Qualité du code PHP | 45/100 | Monolithe sans séparation des responsabilités |
| Modularité/Réutilisabilité | 40/100 | Duplication importante, couplages forts |
| Testabilité | 15/100 | Aucun test, couplage fort rend les tests très difficiles |
| Sécurité | 65/100 | PDO prepared statements, CSRF, auth — mais design-system.php expose des risques |
| Maintenabilité | 45/100 | Pages > 2 000 lignes, logique éparpillée |

---

### Estimation : Migration progressive vs Réécriture complète

#### Option A — Migration progressive (Design Tokens + Refactorisation CSS)

| | |
|--|--|
| **Durée estimée** | 3 à 5 mois (en parallèle du développement fonctionnel) |
| **Risque global** | MOYEN |
| **Effort** | Élevé sur les 36 pages MIGRER, très élevé sur les 6 RÉÉCRIRE TOTAL |
| **Gain immédiat** | Design cohérent, dark/light mode, maintenance CSS simplifiée |
| **Périmètre CSS** | Remplacer les styles inline (908 occurrences), refactoriser `style-maboximmo.css`, migrer les tokens |

#### Option B — Réécriture complète

| | |
|--|--|
| **Durée estimée** | 6 à 10 mois |
| **Risque global** | ÉLEVÉ |
| **Effort** | Total — recoder 93 pages + 72 API + 34 includes |
| **Gain** | Architecture moderne, testabilité, composants réutilisables |
| **Recommandé si** | Décision de migrer vers React/Vue + API REST complète |

---

### Ordre de migration recommandé (du plus simple au plus complexe)

| Priorité | Pages | Raison | Recommandation |
|----------|-------|--------|----------------|
| 1 | Pages de paramétrage admin (`param_*.php` ×4) | Petites pages, composant minicard déjà isolé | MIGRER |
| 2 | Pages auth (`login.php`, `access_choice.php`) | Simples, peu de logique | MIGRER |
| 3 | Dashboards RH (`rh_dashboard_*.php`) | Logique légère, CSS propre | MIGRER |
| 4 | Pages liste/détail (`bien_liste.php`, `rh_salaires_detail.php`) | Peu de JS, CSS récupérable | MIGRER |
| 5 | Pages RH moyennes (`rh_conges_edit.php`, `rh_user_*.php`) | Formulaires simples | MIGRER |
| 6 | `rh_profil.php`, `rh_documents.php`, `rh_mails.php` | Upload, Fetch API — logique récupérable | RÉÉCRIRE UI |
| 7 | `dashboard_agency.php`, `agence_inscription.php` | UI dense mais logique saine | RÉÉCRIRE UI |
| 8 | `societe.php`, `rh_salaires.php` | Complexe mais modulable | RÉÉCRIRE UI |
| 9 | `rh_entretien_tenir.php`, `rh_indemnite_km.php` | Pages > 2000L, couplage fort | RÉÉCRIRE TOTAL |
| 10 | `bien_ajouter.php` | 2534L, Google Maps, wizard requis | RÉÉCRIRE TOTAL |
| 11 | `portail.php` | CSS inline massif, refonte complète requise | RÉÉCRIRE TOTAL |
| 12 | `design-system.php`, `admin_database.php` | Outils internes à repenser | RÉÉCRIRE TOTAL |

---

### 3 principaux risques de la migration progressive

| # | Risque | Probabilité | Impact |
|---|--------|------------|--------|
| 1 | **Régression visuelle silencieuse** — Les 908 styles inline contredisent les tokens migrés sur des pages non encore traitées, créant des incohérences visuelles difficiles à détecter sans tests visuels. | ÉLEVÉE | ÉLEVÉ |
| 2 | **Duplication de l'effort sur les RÉÉCRIRE TOTAL** — Migrer d'abord le CSS d'une page comme `bien_ajouter.php` pour la réécrire 2 mois plus tard représente du travail doublement jeté. | MOYENNE | MOYEN |
| 3 | **Résistance au refactoring CSS par page** — Sans framework front (React/Vue), les tokens migrés dans les fichiers CSS n'empêchent pas les développeurs de continuer à écrire des styles inline dans les nouvelles pages. La dette se reconstituera. | ÉLEVÉE | ÉLEVÉ |

---

### 3 principaux risques de la réécriture complète

| # | Risque | Probabilité | Impact |
|---|--------|------------|--------|
| 1 | **Perte de logique métier non documentée** — La logique de calcul SEPA, des barèmes IK, des entretiens versionnés est enfouie dans les pages PHP. Une réécriture sans reverse-engineering minutieux risque de perdre des règles métier critiques. | ÉLEVÉE | CRITIQUE |
| 2 | **Effet tunnel** — Un projet de 6 à 10 mois sans livraison intermédiaire expose au risque de ne jamais atteindre la parité fonctionnelle, de voir les besoins évoluer en cours de route, et de perdre la confiance des utilisateurs. | MOYENNE | CRITIQUE |
| 3 | **Sous-estimation de la surface à couvrir** — 93 pages + 72 endpoints + 34 composants. Les pages de debug/dev masquent la frontière production/dev. Un chiffrage basé sur la liste brute des fichiers sera systématiquement optimiste. | ÉLEVÉE | ÉLEVÉ |

---

### Recommandation finale

**Approche recommandée : Migration hybride ciblée**

Ni migration CSS globale, ni réécriture complète — mais une **stratégie par module** :

1. **Module CSS (immédiat, 2-4 semaines)** : Consolider les design tokens existants (`variables.css` est déjà une bonne base). Éliminer `style-maboximmo.css` par fusion dans `style.css`. Réduire les 908 styles inline sur les pages prioritaires. C'est du gain net sans risque.

2. **Module Paramétrage et Admin (1 mois)** : Pages légères, bon ROI. Migrer les 4 pages `param_*.php`, `parametrage.php`, `societe_super_admin.php`.

3. **Module RH — pages simples (1-2 mois)** : Migrer les 8-10 pages RH légères (dashboards, listes, formulaires simples). Elles partagent `theme-rh.css` déjà propre.

4. **Module RH — pages complexes (2-3 mois)** : Réécrire l'UI (pas la logique) de `rh_profil.php`, `rh_salaires.php`, `rh_documents.php`. La logique PHP est saine — seul le rendu est à refaire.

5. **Module Biens (3-4 mois)** : Réécrire `bien_ajouter.php` en wizard multi-étapes. C'est la page la plus complexe du projet et la plus critique fonctionnellement.

6. **Module Portail/Landing (4-5 mois)** : Réécriture UI complète du portail public — CSS inline critique.

**Justification** : Le système de variables CSS existant (`variables.css` + `vars/*.css` + `theme-*.css`) est l'actif le plus précieux du projet. Il constitue une base solide pour un design token system. La réécriture de ce socle est inutile. En revanche, la logique applicative (SQL direct dans les vues, pages > 2 000 lignes) ne mérite pas d'être migrée page par page : les modules fonctionnellement les plus complexes gagneront davantage à être réécrits avec une architecture propre (séparation service/vue) qu'à être migré CSS par CSS.

---

## ANNEXE — CHIFFRES DE RÉFÉRENCE

| Métrique | Valeur |
|----------|--------|
| Total fichiers PHP (pages) | 93 |
| Total fichiers PHP (API) | 72 |
| Total fichiers include | 34 |
| Total fichiers CSS | 11 (4 014 lignes) + 4 vars (137 lignes) = **4 151 lignes** |
| Total fichiers JS | 4 (1 660 lignes) |
| Total !important dans CSS | **10** |
| Styles inline (attribut style="") | **908 occurrences dans 51 fichiers** |
| Page PHP la plus longue | `rh_entretien_tenir.php` — 2 570 lignes |
| Composant include le plus complexe | `inc/bien_import_mapper.php` — 690 lignes |
| Nombre de versions entretien actives | 5 (v3 à v7) |
| Tables BDD identifiées | 17+ |
| API endpoints | 72 |

---

*Audit généré le 2026-04-03 — MaBoxImmo2026/public_html*
