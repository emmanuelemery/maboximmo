# AUDIT-MABOXIMMO — État des lieux technique

> Date : 2026-04-26
> Auteur : Claude (audit automatisé)
> Périmètre : `c:\xampp\htdocs\MaBoxImmo2026\`
> Modules SaaS satellites mentionnés : `ged-maboximmo` (GED) et `mail-maboximmo` (MAIL)
> Objectif : base factuelle pour décision de refonte d'architecture (cible Next.js + TypeScript + PostgreSQL + Prisma + multi-tenant + IA centralisée).

---

## 1. ENVIRONNEMENT D'EXÉCUTION

| Élément | Valeur détectée |
|---|---|
| OS | Windows 11 ARM64 — `MINGW64_NT-10.0-26200-ARM64 EMERY-EMMANUEL-01` |
| Shell | Git Bash (MSYS) + PowerShell |
| Node.js | `v24.14.1` |
| npm | `11.11.0` |
| pnpm / yarn | Non installés |
| PHP (CLI XAMPP) | `PHP 8.0.30 (cli) (built: Sep  1 2023) (ZTS Visual C++ 2019 x64)` — binaire `C:\xampp\php\php.exe` |
| PHP (PATH) | Non disponible directement (commande `php` introuvable hors XAMPP) |
| Python | `Python 3.14.4` |
| Composer | Non disponible globalement, mais `composer.phar` présent à la racine du dépôt + `vendor/` (713 KB) |
| Git | `git version 2.53.0.windows.3` |
| GitHub CLI | `gh version 2.89.0 (2026-03-26)` |
| Docker | **Non installé** sur la machine de dev |
| Espace disque | C: 458 GB total, **47 GB libres** (90 % utilisés) ; D: 896 GB libres ; G: 458 GB (411 GB utilisés) |
| Hébergement local | XAMPP (PHP 8.0, MariaDB locale supposée) — répertoire `c:\xampp\htdocs\MaBoxImmo2026\` |
| Hébergement prod | **Hostinger shared** (FTP `ftp.maboximmo.fr`, user `u630423897.maboximmo`) |
| Hébergement modules SaaS | VPS Hostinger `76.13.59.234` (GED + MAIL) |

> Remarque : la version de PHP locale (8.0.30) est **en fin de vie** depuis novembre 2023, mais PHP 8.0 reste utilisé en prod Hostinger (à confirmer). C: à 90 % d'occupation = vigilance.

---

## 2. STRUCTURE DU PROJET

### Arborescence sur 3 niveaux (extrait synthétique)

```
MaBoxImmo2026/
├── .git/                          (~801 MB)
├── .github/workflows/             deploy-dev.yml, deploy-prod.yml
├── public_html/                   (~756 MB) — racine applicative déployée
│   ├── admin/                     pages super admin + outils (18 fichiers)
│   ├── api/                       133 endpoints PHP REST-like
│   ├── assets/                    css/, js/ (additionnels)
│   ├── config/                    db.php, smtp.php, ubiflow_*.php
│   ├── css/                       33 feuilles CSS custom
│   ├── gestion/                   pages gestion locative SIR
│   ├── images/                    logos, icônes
│   ├── inc/                       89 fichiers communs (auth, csrf, mailer, IA, OCR…)
│   ├── js/                        16 scripts JS vanilla
│   ├── lib/phpmailer/             PHPMailer vendoré
│   ├── memoire/                   docs internes (charte, concept, restyling)
│   ├── modules/                   modules « agent » + « ged » (récents)
│   ├── scripts/                   CLI : import_all_crg.php, parse_crg.py, ubiflow_cron…
│   ├── sql/                       28 migrations SQL applicatives
│   ├── sql_migrations_tiers/      migrations architecture TIERS
│   ├── tcpdf/                     vendoré (~110 MB) — génération PDF
│   ├── templates/                 mail_nouveau_mdp.html, mail_onboarding.html
│   ├── uploads/                   ~483 MB de fichiers utilisateur (gitignored)
│   ├── vitrine/                   site public récent
│   ├── _archive/, _backups/       sauvegardes intégrées au repo
│   └── ~196 fichiers .php à la racine (pages métier)
├── sql/                           (~737 MB) — exports + migrations générales
├── docs/                          docs.zip, audit-projet.md
├── backups/                       2026-04-15, 2026-04-16
├── public_html__backup_20260323/  duplicat complet
├── sauvegarde MABOXIMMO 21032026/ snapshot ZIP extrait
├── sauvegarde REGISTRES SYNDICOFFICE 21032026/  ancien produit
├── sauvegarde TRUBOX 21032026/    ancien produit
├── u630423897/                    chemin Hostinger reproduit en local
├── vendor/                        ~713 KB (composer)
├── composer.json / composer.lock / composer.phar
├── db_config.php / db_config.php.template
├── maboximmo_backup_*.sql         dumps prod (~1.6 MB)
├── sauvegarde_bdd_*.sql           dumps datés
└── ~30 fichiers .md (audits, guides, design)
```

### Statistiques

| Métrique | Valeur |
|---|---|
| Fichiers PHP (public_html) | **713** |
| Fichiers SQL | 33 + 53 dans `sql/` |
| Fichiers CSS | 30 |
| Fichiers JS | 16 |
| Fichiers TS | **0** (aucun TypeScript) |
| Fichier Python | 1 (`scripts/parse_crg.py`) |
| Pages PHP racine `public_html/` | **196** |
| Endpoints API (`public_html/api/*.php`) | **133** |
| Fichiers `inc/` | 89 |
| Tables DB (dump complet) | **118** |

### Git

| Métrique | Valeur |
|---|---|
| Remote | `https://github.com/PIEM99/maboximmo.git` |
| Branche actuelle | `main` |
| Branches locales | `dev-emmanuel`, `develop`, `feature/ged-documents-category-id`, `fix/users-create-new-roles`, `main` |
| Branches distantes | `main`, `develop`, `dev-emmanuel`, `dev-PIEM`, `debug/landing-canari`, `debug/landing-roleid`, `hotfix/landing-modules-admin-roles`, `hotfix/landing-superadmin-modules`, `hotfix/opcache-reset-tool` |
| Total commits | **232** |
| Dernier commit | `2026-04-26 17:25:03 +0200 — 950a7a1 — debug: canari [TEST 26/04 14h] dans 'Vos services' pour vérifier upload FTP (#6)` |
| Taille `.git/` | **~801 MB** (très lourd — historique pollué de gros fichiers avant `.gitignore`) |
| Hooks Git | Aucun hook personnalisé installé (seulement les `.sample`) |

### `.gitignore`

Présent. Patterns clés :
- Credentials : `db_config.php`, `db_config_dev.php`, `.env*`, `config/smtp_local.php`, `config/ubiflow_credentials.local.php`
- Logs : `*.log`, `logs/`
- Uploads utilisateur : `public_html/uploads/`
- Backups & archives : `backups/`, `_archive/`, `_work/`, `sauvegarde*/`, `*backup*`, `public_html__backup*/`
- SQL : `*.sql` (avec exception explicite `!sql/migration_*.sql`)
- Note : `config/db.php` versionné volontairement (logique pure, pas de credentials).

### Documentation existante (~30 .md à la racine)

Très fournie côté docs métier/design (voir section 9), mais pas de `README.md` racine au sens « onboarding développeur » ; on a `README_START_HERE.md` et un `DEV_ENV.md`.

---

## 3. STACK TECHNIQUE DÉTECTÉE

### Langages (proportions sur `public_html/`)

| Extension | Nombre | Part |
|---|---:|---:|
| `.php` | 713 | **84,6 %** |
| `.sql` | 33 | 3,9 % |
| `.css` | 30 | 3,6 % |
| `.html` | 22 | 2,6 % |
| `.js` | 16 | 1,9 % |
| `.md` | 10 | 1,2 % |
| `.json` | 5 | 0,6 % |
| `.py` | 1 | 0,1 % |
| `.ts/.tsx` | **0** | 0 % |

> **Aucun TypeScript, aucun build front (pas de Vite/Webpack/Next).** Code 100 % PHP procédural avec JS vanilla pour les interactions.

### Frameworks / dépendances

- **Composer** (`composer.json`) — UNE seule dépendance : `smalot/pdfparser ^2.12` (parser PDF côté serveur)
- **Pas de framework PHP** : ni Laravel, ni Symfony, ni Slim. PHP procédural avec patterns d'inclusion (`require_once 'inc/bootstrap.php'`).
- **package.json** : aucun à la racine ni dans `public_html/`. Pas de pipeline Node.
- **Frontend** : HTML pur + JS vanilla. Pas de React/Vue/Svelte.
- **CSS** : custom (pas de Tailwind/Bootstrap détecté). Architecture CSS variables (`css/vars/`) + plusieurs fichiers thématiques (`bien_detail_v2.css`, `gestion.css`, `arbitrage.css`, `sidebar.css`, `liste_layout.css`, `maboximmo_v2.css`…).
- **PHPMailer** : vendoré dans `public_html/lib/phpmailer/` (pas via Composer).
- **TCPDF** : vendoré (110 MB) avec son propre `.git/`, indépendant de Composer.

### BDD

- **MySQL/MariaDB** (Hostinger) — DSN : `mysql:host={DB_HOST};dbname={DB_NAME};charset=utf8mb4`
- 118 tables détectées (dump `maboximmo_backup_complet.sql`)
- **Aucun PostgreSQL**, **aucun Prisma**, **aucune migration tool moderne** (pas de Phinx/Doctrine/Knex).
- Migrations = scripts SQL nus dans `sql/` et `public_html/sql/` (53 fichiers `migration_*.sql`), exécutées manuellement ou via `admin/admin_migrations.php` / `admin/admin_database.php`.

### ORM / couche d'accès

- **PDO natif** (pas d'ORM). Pattern singleton `db()` avec `PDO::ATTR_ERRMODE = EXCEPTION` et `PDO::ATTR_EMULATE_PREPARES = false`.
- Helpers maison : `db_keepalive()`, `db_reconnect_fresh()` pour gérer les `MySQL server has gone away` après appels OCR/IA longs.

### Build / packaging

- **Aucun système de build** (pas de Vite, Webpack, esbuild, Rollup).
- Le code source = artefact déployé tel quel via FTP.

---

## 4. ARCHITECTURE ACTUELLE

### Modules / sections fonctionnels (par dossier)

| Dossier | Rôle |
|---|---|
| `public_html/admin/` | Outils super admin : migrations, déploiement, paramétrage référentiels |
| `public_html/api/` | 133 endpoints AJAX/REST-like, regroupés par préfixe (`annonce_*`, `bien_*`, `bail_*`, `tiers_*`, `rh_*`…) |
| `public_html/inc/` | 89 fichiers : auth, csrf, mailers, IA (Claude/OpenAI), OCR, intake bail/mandat/diag, sidebars par rôle, helpers PDF, validators Ubiflow |
| `public_html/gestion/` | Gestion locative SIR (analyses, dashboard, patrimoine, upload CRG) |
| `public_html/vitrine/` | Site public (récent — non versionné encore d'après le `git status`) |
| `public_html/modules/` | Modules récents `agent/` et `ged/` (interface vers GED SaaS externe) |
| `public_html/scripts/` | CLI (cron Ubiflow, parser CRG Python, imports) |
| `public_html/templates/` | Mails HTML simples |
| `public_html/_archive/`, `_backups/` | Anciennes versions stockées dans le repo (~124 MB) |

### API

- **Style REST-like** : un fichier `.php` = un endpoint, pas de routeur central. Ex : `api/bien_create_draft.php`, `api/annonce_diffuser.php`.
- Pas d'OpenAPI/Swagger, pas de versionning (`/v1/…`).
- Réponses JSON manuelles (`json_encode`) — pas de standardisation des formats d'erreur.
- 133 fichiers PHP dans `api/`.

### Authentification

- **Sessions PHP natives** (`session_start()`, `$_SESSION['id_role']`, `$_SESSION['id_societe']`).
- Pas de JWT, pas d'OAuth interne.
- Mode test : `$_SESSION['test_role_id']` permet de simuler un rôle (vu dans `inc/auth.php`).
- Helpers : `current_role_id()`, `require_role_ids([1])`, `require_role_ids([1, 2])`.

### Multi-tenant

- **Présent mais implicite** : pas de `tenant_id` unique normalisé ; à la place colonnes `id_societe` et `id_agence` ajoutées au coup par coup.
- Helper centralisé : `inc/tenant_scope.php` (très bien documenté) qui expose `tenant_current_context()`, `tenant_agences_visibles()`, `tenant_societes_visibles()`, `tenant_resolve_filter()`.
- 54 occurrences de `tenant_id|id_societe|id_agence` dans 10 fichiers échantillonnés (réelle utilisation dispersée plus large).
- **Pas de Row-Level Security** au niveau base : le scoping se fait dans chaque requête PHP. Risque élevé d'oubli si on rate un fichier.

### Rôles / permissions

- Table `roles` (présente dans le dump).
- Codes role_id en dur dans le code (1 = super admin, 7, 8 = admins étendus — vu dans les commits récents).
- Pas de système RBAC avec scopes/permissions atomiques : tout passe par des `if ($_SESSION['id_role'] === 1)` éparpillés.

### Schéma BDD — tables principales (sur 118 totales)

| Domaine | Tables |
|---|---|
| Utilisateurs / orga | `users`, `roles`, `societes`, `agences`, `agences_zones`, `comptes_portail`, `sso_tokens`, `reset_tokens`, `email_verifications` |
| TIERS (architecture récente) | `tiers`, `tiers_roles`, `tiers_roles_codes`, `tiers_contacts`, `user_tiers` |
| Métier patrimoine | `biens`, `biens_caracteristiques`, `biens_versions`, `biens_tags`, `immeubles`, `immeubles_infos`, `proprietaires` |
| Annonces & diffusion | `annonces`, `annonces_photos`, `annonces_versions`, `annonces_tags`, `diffusion_portails`, `diffusion_portails_logs`, `cache_listings`, `favoris_annonces` |
| Locations & syndic | `baux`, `mandats` (via `reg_*`), `etat_des_lieux`, `honoraires_location`, `contrat_syndic`, `reunions`, `reunions_participants`, `retour_ag`, `retour_ag_points` |
| Comptabilité | `factures`, `factures_lignes`, `factures_paiements`, `factures_mails`, `estimations` |
| RH | `salaires`, `salaires_documents`, `salaires_validations_user`, `conges`, `conges_soldes`, `rh_documents`, `rh_documents_historique` |
| Tâches | `taches`, `taches_*` (12 tables associées) |
| Documents / GED | `documents`, `ged_buckets`, `taches_documents`, `salaires_documents` |
| Mails entrants/sortants | `emails_inbox`, `emails_attachments`, `inbound_emails`, `inbox_aliases`, `mail_aliases`, `mail_salaire_log` |
| SEO / vitrine | `seo_villes`, `seo_agences`, `seo_pages_libres`, `seo_programmes`, `seo_search_terms`, `villes`, `quartiers`, `programmes`, `programmes_lots` |
| Visites | `visites`, `visites_actions`, `visites_elements`, `visites_modeles*`, `visites_zones`, `visites_photos` |
| Registres (legacy SyndicOffice) | `reg_societes`, `reg_immeubles`, `reg_mandats`, `reg_users`, `registres_acces` |
| Imports | `import_jobs`, `import_job_lignes`, `imports_logiciels`, `bien_imports`, `bien_import_*` |
| DPE / diags | `dpe_diags` |
| Leads | `leads_annonces`, `leads_annonces_actions` |
| Logs | `search_logs`, `stats_annonces`, `dev_organisation` |

### Helpers communs

- `inc/bootstrap.php` — initialise sessions, headers sécurité (X-Frame-Options, CSP, etc.), charge configs IA, base path.
- `inc/security.php`, `inc/auth.php`, `inc/csrf.php`, `inc/SecurityGuard.php`, `inc/RateLimiter.php`, `inc/AuditLog.php`, `inc/CacheManager.php`, `inc/api_helpers.php`.
- Mailers spécialisés : `mailer.php`, `mailer_facture.php`, `mailer_tache.php`, `mailer_reunion.php`, `mailer_syndic_proposition.php`, `mailer_analyse_doc.php`.
- Sidebars par rôle : `sidebar_agency.php`, `sidebar_bailleur.php`, `sidebar_proprietaire.php`, `sidebar_syndic.php`, `sidebar_user.php`.

### Tests automatisés

- **AUCUN test automatisé détecté**. Pas de dossier `tests/`, pas de `phpunit.xml`, pas de `vitest.config`, pas de Pest, pas de Jest.
- Quelques fichiers `_tmp_test_*.php` à la racine (tests manuels).
- Risque qualité majeur sur 713 fichiers PHP sans aucun filet de sécurité.

---

## 5. INTÉGRATIONS PARTENAIRES DÉTECTÉES

| Partenaire | Statut | Fichiers principaux | Mode |
|---|---|---|---|
| **Google Maps / Places / Geocoding** | Partiel | `js/places.js`, `api/places_details.php`, `inc/bootstrap.php` (chargement `google_config.php`) | Centralisé via config externe |
| **Mindee (OCR)** | Partiel | `modules/agent/agent_ged.php`, `modules/agent/agent_ged_action.php`, `modules/ged/agent_functions.php`, `modules/agent/README.md` | Récent, dans nouveaux modules `agent/` (probablement piloté par GED SaaS) |
| **Anthropic (Claude)** | Present | `inc/bootstrap.php`, `api/ask_ia.php`, `anthropic_config.php` (placeholder `sk-ant-VOTRE_CLE_ICI`) | Centralisé : `$ANTHROPIC_API_KEY`, `$ANTHROPIC_MODEL = 'claude-sonnet-4-6'` |
| **OpenAI** | Present (massif) | 15+ fichiers : `inc/bien_intake_*.php`, `inc/dpe_ia_analyse.php`, `inc/arbitrage_ai.php`, `api/bail_analyze.php`, `api/admin_doc_analyze.php`, `api/agence_rib_analyze.php`, `api/bien_ai_generate.php`, `api/generate_annonce.php`, `api/societe_doc_analyze.php`, `api/chatgpt_draft.php`, `inc/rh_document_extractor.php`, `inc/ia_analyse.php`, `inc/bien_photo_analyser.php`, `api/import_crg.php`… | **Dispersé** : appels `curl` directs vers `api.openai.com` dans plusieurs fichiers, modèle `gpt-5` par défaut |
| **Mistral** | Absent | — | — |
| **Yousign** | Absent | — | — |
| **Stripe** | Absent | — | — |
| **Twilio / OVH SMS** | Absent | — | — |
| **Brevo / SendGrid / Resend** | Absent | — | — |
| **PHPMailer (SMTP custom)** | Present | `lib/phpmailer/`, `inc/mailer*.php`, `config/smtp.php`, `config/smtp_local.php`, `api/document_email_intake.php`, `api/send_mail_leave.php` | Vendoré, config SMTP locale gitignored |
| **Scaleway / OVH / AWS S3** | Absent | — | (uploads stockés en local sur Hostinger : `public_html/uploads/`) |
| **LOJJI** | Absent | — | — |
| **ICS (legacy syndic)** | Absent | — | — |
| **SEPTEO** | Absent | — | — |
| **INSEE / Pappers** | Absent | — | — |
| **ADEME (DPE)** | Partiel (interne) | `inc/dpe_ia_analyse.php`, `agency_analyse_dpe.php` | Pas d'API ADEME publique détectée — analyse DPE faite via OpenAI sur PDF |
| **DVF** | Absent | — | — |
| **Sentry** | Absent | — | (logs PHP uniquement) |
| **Plausible / Matomo / GA** | Absent | — | — |
| **Axeptio / cookies** | Absent | — | — |
| **Ubiflow (diffusion immo)** | Present (cœur) | `inc/ubiflow_validator.php`, `inc/ubiflow_build.php`, `config/ubiflow_agences.php`, `config/ubiflow_mapping.php`, `config/ubiflow_credentials.local.php`, `api/annonce_diffuser.php`, `api/flux/ubiflow_ftp.php`, `scripts/ubiflow_cron.sh`, `scripts/ubiflow_cron_19h.bat` | Centralisé, FTP custom + builders XML |
| **GED SaaS interne** | Present (en cours) | `public_html/modules/ged/`, `public_html/modules/agent/`, `bailleur_ged.php` | Pont vers `ged.maboximmo.fr` (repo séparé) |
| **MAIL SaaS interne** | Cité dans `MEMORY.md` | (pas encore branché côté code observé) | — |

---

## 6. GESTION DES SECRETS

### Fichiers de configuration sensibles

| Fichier | Statut |
|---|---|
| `db_config.php` | Présent à la racine du repo (gitignored). Contient les credentials DB de prod. |
| `db_config.php.template` | Présent (versionné), structure sans valeurs. |
| `db_config_dev.php` | Pas trouvé localement (gitignored). Fichier de credentials environnement dev. |
| `.env`, `.env.local`, `.env.production` | **Aucun fichier `.env` détecté.** Le projet n'utilise pas dotenv. |
| `config/smtp.php` | Versionné (logique). |
| `config/smtp_local.php` | Gitignored — credentials SMTP locaux. |
| `config/ubiflow_credentials.local.php` | Gitignored. |
| `config/ubiflow_credentials.example.php` | Versionné — modèle. |
| `anthropic_config.php` | **Versionné** à la racine de `public_html/` avec un placeholder `sk-ant-VOTRE_CLE_ICI` (pas de fuite, mais pattern fragile : un dev pourrait y coller la vraie clé et committer). |
| `app_config.php` | Versionné en racine (logique). |
| `openai_config.php` | Cherché par `bootstrap.php` à 12 emplacements (`/home/u630423897/openai_config.php`, `../openai_config.php`, etc.) — non trouvé dans le repo (présumé hors-repo, sur le serveur). |
| `google_config.php` | Idem (hors-repo, chargé optionnellement). |

### Clés en dur dans le code

- **`anthropic_config.php` (ligne 6)** : `define('ANTHROPIC_API_KEY', 'sk-ant-VOTRE_CLE_ICI');` — **placeholder, pas une vraie clé**, mais le fichier est versionné (à isoler ou gitignorer en attendant une vraie config).
- Aucun pattern `sk-[a-zA-Z]+` réel détecté dans le code.
- Aucun `password = '...'` codé en dur trouvé.

### Gestionnaire de secrets

- **Aucun** (pas de Doppler, Infisical, Vault, AWS Secrets Manager).
- Pattern actuel : credentials dans des fichiers PHP hors-repo, chargés à des chemins multiples (`/home/u630423897/...`) selon l'environnement.
- Côté CI : un seul secret GitHub Actions utilisé (`secrets.FTP_PASSWORD` pour le déploiement).

### Recommandation immédiate

- Renommer `anthropic_config.php` en `anthropic_config.example.php` et ajouter `anthropic_config.php` au `.gitignore`.
- Adopter `.env` + `phpdotenv` pour homogénéiser, ou rester sur les fichiers hors-repo mais documenter formellement.

---

## 7. CI/CD ET DÉPLOIEMENT

### Workflows GitHub Actions (`.github/workflows/`)

| Fichier | Trigger | Action |
|---|---|---|
| `deploy-prod.yml` | Push sur `main` ou `workflow_dispatch` | FTP-Deploy-Action v4.3.5 vers `ftp.maboximmo.fr:/public_html/` |
| `deploy-dev.yml` | Push sur `develop` ou `workflow_dispatch` | FTP-Deploy-Action vers `ftp.maboximmo.fr:/dev/` |

- Les deux workflows écrivent le SHA du commit dans `public_html/git_version.txt`.
- Exclusions FTP : `.git/`, `.github/`, `node_modules/`, `uploads/`, `tcpdf_cache/`, `db_config.php`, `config/smtp_local.php`, `config/ubiflow_credentials.local.php`.
- **Pas de tests, pas de lint, pas de build** dans la pipeline. Push direct.

### Dockerfile / docker-compose

- **Aucun** dans le projet MaBoxImmo (Docker non utilisé).
- Présents en revanche dans les modules SaaS satellites (`ged-maboximmo/docker-compose.dev.yml`, `mail-maboximmo/docker-compose.{yml,dev,prod}.yml`).

### Scripts de déploiement

- `_tmp_patch_bien_ajouter.ps1` (patch ponctuel à la racine).
- `composer-setup.php` à la racine (script d'installation Composer).
- Cron Ubiflow : `scripts/ubiflow_cron.sh` (Linux) et `scripts/ubiflow_cron_19h.bat` (Windows).
- Plusieurs scripts d'import à la racine : `import_conges_*.php`, `migrate_conges.php`.

### Mode de déploiement

- **Auto** : push `main` → prod, push `develop` → dev.
- **Manuel pour les migrations BDD** : aucun mécanisme automatique de migration de schéma. Les `migration_*.sql` sont exécutés à la main via `admin/admin_database.php` ou phpMyAdmin Hostinger.

---

## 8. QUALITÉ DU CODE

### Linters / formatters

- **Aucun** (pas de `.eslintrc`, `.prettierrc`, `phpstan.neon`, `phpcs.xml`, `pint.json`).
- Pas de pre-commit hook.
- Pas de CI quality gates.

### Conventions de nommage

- **PHP procédural** avec préfixes par domaine : `agency_*.php`, `bien_*.php`, `bail_*.php`, `bailleur_*.php`, `rh_*.php`, `tiers_*.php`. Cohérent dans l'ensemble.
- Mélange snake_case (BDD, fonctions PHP) et camelCase (sporadique en JS).
- Quelques duplications/variantes : `bien_ajouter.php` + `bien_ajouter_express.php` + `bien_creation.php` + `bien_intake.php` + `bien_detail.php` + `bien_detail_v2.php` + `bien_detail_ex.php` (pattern `_ex` documenté dans `feedback_snapshot_ex.md` = snapshot avant modif).

### TypeScript

- **Aucun**. JS vanilla uniquement.

### TODO / FIXME

- Seulement **4 occurrences** dans tout `public_html/*.php` — soit le code est étonnamment propre, soit les TODO sont gérés ailleurs (commits, GitHub Issues).

### Duplication visible

- Multiples versions de `rh_entretien_v3.php` jusqu'à `rh_entretien_v7.php` dans `inc/`.
- Multiples mailers très similaires (`mailer_facture`, `mailer_tache`, `mailer_reunion`…) — pourraient être un mailer générique paramétré.
- Sidebars dupliquées par rôle (5 fichiers `sidebar_*.php`).
- Création de tables `IF NOT EXISTS` éparpillée dans le code applicatif (vu dans `rh_salaires.php`, `rh_mails.php`, `inc/rh_doc_types.php` etc.) au lieu d'être centralisée dans des migrations.

---

## 9. DOCUMENTATION EXISTANTE

### Fichiers `.md` racine (~30 fichiers, sélection commentée)

| Fichier | Résumé |
|---|---|
| `README_START_HERE.md` | Point d'entrée probable — onboarding |
| `DEV_ENV.md` | Setup environnement dev local |
| `ARCHITECTURE_MABOXIMMO.md` | Vision architecture (peut-être obsolète) |
| `AUDIT_GLOBAL_MBI.md`, `AUDIT_MABOXIMMO.md`, `AUDIT_FONCTIONNEL_SEO_SECURITE.md`, `RH_AUDIT.md` | Audits antérieurs (différents périmètres) |
| `PLAN_ACTION_MBI.md` | Plan d'action consolidé |
| `BRIEF_CHARTE_COULEUR_MABOXIMMO.md`, `PALETTES_COULEURS_MABOXIMMO.md`, `DESIGN_HOME_PAGE.md`, `DESIGN_2_VERSIONS_LAYOUTS.md`, `BEFORE_AFTER_COMPARISON.md` | Design system, charte graphique |
| `CARTOGRAPHIE_PAGES_EXISTANTES.md` | Inventaire pages |
| `GANTT_*` (5 fichiers) | Refonte module Gantt |
| `GUIDE_FLUX_UTILISATEUR.md`, `GUIDE_SIMPLIFIE.md`, `GUIDE_IMPLEMENTATION_SEO_CACHE.md`, `GUIDE_SUBSCRIPTIONS.md` | Guides fonctionnels |
| `CHECKLIST_DEPLOIEMENT.md` | Procédure de déploiement |
| `CONGES_MODULE_SUMMARY.md`, `CONGES_FILES_DOCUMENTATION.txt`, `SETUP_LEAVE_DECOMPTE.md` | Module congés RH |
| `IMPORT_README.md`, `IMPORT_SUMMARY.md` | Imports CRG / biens |
| `SMTP_SETUP.md` | Config mail |
| `IMPLEMENTATION_VERIFICATION.md`, `DELIVERY_SUMMARY.txt`, `REDESIGN_SUMMARY.txt` | Suivi de livraisons |
| `_work/process_fusion_registres.md` | Notes de fusion legacy |
| `docs/docs/audit-projet.md` | Audit projet (probablement plus ancien) |
| `docs/guide_creation_bien.md` | Guide récent côté agent |
| `public_html/memoire/*` (5 fichiers) | Documentation interne : charte graphique, concept, mémo fonctionnalités, méthode restyling |
| `public_html/modules/agent/README.md` | Module agent (Mindee/OCR) |

> **Constat** : la doc est **abondante mais éparpillée** sans index unique ni cycle de vie (pas de date "obsolete", pas de hiérarchie). Risque : 50 % de la doc périmée sans qu'on sache laquelle.

---

## 10. POINTS DE FRICTION DÉTECTÉS

1. **Code 100 % PHP procédural non typé** → migration TS = réécriture intégrale. Impossible de "convertir" automatiquement les 713 fichiers PHP en TypeScript.
2. **Aucun test automatisé** → toute refonte casse à l'aveugle ce qui marchait. Filet de sécurité = zéro.
3. **Couplage fort fichier ↔ URL** : 196 pages PHP racine et 133 endpoints API = autant de routes implicites. Pas de routeur, pas d'inversion de contrôle. Refactor d'une route = potentiellement 196 fichiers à mettre à jour.
4. **Multi-tenant par convention applicative** (filtres `id_societe`/`id_agence` dans chaque requête) — sans contrainte BDD ni RLS. Une seule requête oubliée = leak inter-clients. Documenté comme « impératif » dans `MEMORY.md` mais non enforced.
5. **Fichier `anthropic_config.php` versionné avec placeholder** → pattern fragile, risque de fuite.
6. **Pages dupliquées en `_ex`/`v2`/`v3`/`v7`** : pratique de snapshot manuel (cf. `feedback_snapshot_ex.md`) qui pollue le repo et brouille la vérité de production.
7. **`.git/` à 801 MB** : historique pollué par d'anciens binaires/uploads. Cloner le repo sur un nouveau poste = lourd. Migrer = bonne occasion pour `git filter-repo`.
8. **Aucune migration tool** : exécution SQL manuelle, pas d'historique versionné de l'état du schéma.
9. **OpenAI dispersé dans 15+ fichiers** : pas de wrapper unique. Difficile de basculer de modèle, de logger les coûts, de gérer un fallback Anthropic ↔ OpenAI.
10. **Headers de sécurité corrects mais CSP très permissive** : `'unsafe-inline'` + `'unsafe-eval'` dans `script-src`, `connect-src 'self' https:` (toutes URLs HTTPS autorisées). Refonte = bonne occasion de durcir.
11. **PHP 8.0** en local et probablement en prod : EOL depuis nov. 2023, plus de patches sécurité. Hostinger propose PHP 8.2/8.3.
12. **Logique métier dans la vue** : la majorité des `.php` mélangent SQL, calcul métier, HTML. Pas de séparation MVC, donc rien de réutilisable côté API mobile/Next.js sans tout réécrire.
13. **Backups intégrés au repo** (`public_html__backup_20260323/`, `sauvegarde *`) → 124+ MB qui ne devraient pas y être.
14. **Branches à foison** (5 locales, 9 distantes dont `debug/landing-canari` actif) → workflow Git pas hygiénique, mais cela reflète des chantiers en cours.

---

## 11. CONNEXION GIT / GITHUB

| Élément | Valeur |
|---|---|
| Remote | `https://github.com/PIEM99/maboximmo.git` (organisation `PIEM99`, pas `emmanuelemery`) |
| Auteurs des commits | `emmanuel.emery@agence-emery.com`, `pe.emery.d@icloud.com` |
| Hooks Git locaux | Aucun (uniquement les `.sample` par défaut) |
| Dernières activités | PRs #2 → #6 sur landing/cards super admin, opcache reset Hostinger, debug role_id |
| Branche par défaut PR | `main` |

> Le repo est sur le compte `PIEM99` (collaborateur), pas sur `emmanuelemery`. Les modules SaaS satellites (`ged-maboximmo`, `mail-maboximmo`) sont eux sur `emmanuelemery`. Modèle hybride à clarifier en cas de refonte.

---

## 12. INVENTAIRE DES DONNÉES

### BDD

- **Type** : MySQL/MariaDB (driver PDO mysql, charset `utf8mb4`).
- **Hébergement prod** : Hostinger shared (host à valeur dans `db_config.php`, non lu pour respecter la consigne).
- **Hébergement dev** : Hostinger shared aussi (BDD distincte via `db_config_dev.php`).
- **Local XAMPP** : possible via override env (`DB_HOST`).
- **Nom de base prod (par défaut)** : `u630423897_maboximmo` (vu dans `db.php` ligne 43, valeur publique, pas un secret).

### Tables principales

- **118 tables** dans le dump complet (voir liste exhaustive Section 4).
- Domaines : utilisateurs/sociétés/agences, biens/immeubles/proprios, annonces/diffusion, baux/mandats/syndic, factures/honoraires, RH (salaires/congés), tâches, GED/documents, mails, SEO/vitrine, visites, registres legacy, imports, DPE.

### Migrations / seeds

- **53 fichiers** `migration_*.sql` dans `public_html/sql/` + `sql/deploy_1_code/sql/`.
- **25+ fichiers** `migration_*.sql` à la racine `sql/`.
- Exécutées manuellement (pas de tool de tracking, pas de table `migrations` détectée).
- Quelques migrations en PHP (`sql/rh_user_documents_migration.php`, `sql/rh_entretien_multitenant_migration.php`, `sql/rh_coordonnees_bancaires_migration.php`) avec scripts idempotents `CREATE TABLE IF NOT EXISTS`.
- Dernière vague significative : `migration_tiers_architecture_phase1.sql` (architecture TIERS).

### Sauvegardes

| Fichier | Taille | Date |
|---|---|---|
| `maboximmo_backup_complet.sql` | 1,6 MB | 2026-03-27 |
| `maboximmo_backup_hostinger.sql` | 1,6 MB | 2026-03-27 |
| `sauvegarde_bdd_20260328_221728.sql` | (présent) | 2026-03-28 |
| `sauvegarde_bdd_20260331_234512.sql` | (présent) | 2026-03-31 |
| `sql/maboximmo_export_2026-04-13.sql` | (présent) | 2026-04-13 |
| `sql/maboximmo_export_hostinger.sql` | (présent) | — |
| `backups/2026-04-15`, `backups/2026-04-16` | dossiers | — |
| `sauvegarde MABOXIMMO 21032026/`, `sauvegarde REGISTRES SYNDICOFFICE 21032026/`, `sauvegarde TRUBOX 21032026/` | snapshots produits | 2026-03-21 |

> Sauvegardes nombreuses mais **manuelles**, pas planifiées (pas de cron de dump détecté). Hostinger fournit ses propres backups quotidiens (non visibles depuis le code).

---

## 13. SYNTHÈSE FINALE

### A. Ce qui est solide et à conserver

1. **Modèle de données métier mature** : 118 tables couvrant un périmètre immobilier large et cohérent (transaction, gestion locative, syndic, RH, comptabilité). Ce schéma a une vraie valeur, à ne PAS réinventer — à porter sur PostgreSQL avec adaptations.
2. **Helper multi-tenant centralisé** (`inc/tenant_scope.php`) bien documenté : la logique de filtrage société/agence est claire, juste à transposer en RLS PostgreSQL ou middleware Prisma.
3. **Séparation dev/prod propre** : workflows GitHub Actions distincts, fichiers de credentials par environnement, branche `develop` pour `dev.maboximmo.fr`, branche `main` pour `maboximmo.fr`.
4. **Headers de sécurité HTTP en place** (`bootstrap.php`) — bonne base de départ.
5. **Intégrations Ubiflow rodées** (validators, builders, FTP, mapping agences) : à reprendre tel quel côté Next.js comme service spécialisé.
6. **Architecture TIERS récente** (tiers/users séparés, 956 tiers + 59 codes rôles) — moderne, bien pensée, à porter telle quelle.
7. **Documentation métier abondante** (charte graphique, audits, guides flux, design system) : encore plus précieuse pour une refonte que le code lui-même.
8. **Modules SaaS satellites déjà découplés** (GED, MAIL) : préfigurent l'architecture cible et facilitent la migration progressive.

### B. Ce qui manque (par rapport aux standards modernes)

| Besoin moderne | État actuel | Gap |
|---|---|---|
| API-first (OpenAPI/REST/tRPC) | Endpoints PHP isolés, sans contrat | Élevé |
| TypeScript end-to-end | 0 % TS | Total |
| ORM moderne (Prisma/Drizzle) | PDO brut | Élevé |
| Migrations versionnées | SQL nu manuel | Élevé |
| Multi-tenant enforced (RLS) | Convention applicative | Moyen |
| Tests automatisés | 0 | Total |
| CI quality gates (lint/test/build) | Aucun | Total |
| Observabilité (Sentry/logs structurés) | Aucun | Élevé |
| Analytics (Plausible/Matomo) | Aucun | Moyen |
| Cookies / RGPD (Axeptio) | Aucun | Élevé (obligation légale) |
| Stripe / billing | Aucun | À créer |
| Signature électronique (Yousign) | Aucun | Moyen |
| Stockage objet (S3) | Local Hostinger | Élevé (scalabilité) |
| Wrapper IA centralisé | OpenAI dispersé 15+ fichiers | Élevé |
| Secrets management | Fichiers hors-repo + 1 secret CI | Moyen |
| Front moderne (React/Next) | HTML+JS vanilla | Total |
| Design system codé (Tailwind/shadcn) | CSS custom | Élevé |

### C. Risques techniques actuels

1. **Risque de fuite multi-tenant** : tout repose sur l'application qui ajoute `WHERE id_societe = ?`. Une seule requête mal filtrée = leak de données entre clients (critique avec 956 tiers).
2. **PHP 8.0 en fin de vie** : plus de patches sécurité. Vulnérabilité passive croissante.
3. **Absence totale de tests** : chaque déploiement est un saut dans le vide. Régressions silencieuses garanties.
4. **Stockage uploads en local Hostinger** (483 MB) : sauvegardes fragiles, pas de versioning objet, pas de CDN. Si Hostinger crashe, perte potentielle.
5. **Repo Git à 801 MB** : ralentit clones et CI, et indique que des binaires / dumps ont été committés à un moment.
6. **Code mort / doublons** (`bien_ajouter`, `bien_creation`, `bien_intake`, `bien_detail`, `bien_detail_v2`, `bien_detail_ex`) : risque que le mauvais soit déployé.
7. **Dépendance Composer minimale** (1 lib) mais **~110 MB de TCPDF vendoré** avec son propre `.git/` : incohérent et lourd.
8. **CSP permissive** (`unsafe-inline`, `unsafe-eval`) : XSS possible en cas de faille d'échappement HTML.
9. **Pas de rate limiting infra** (seulement `inc/RateLimiter.php` applicatif) : exposition aux abus.
10. **Pas de plan de reprise** documenté : que faire si Hostinger tombe 48 h ?

### D. Estimation effort de migration vers Next.js + TS + PostgreSQL + Prisma + multi-tenant + IA centralisée

#### Note : **4 / 5** (refonte majeure, mais le modèle de données et la documentation sont précieux)

Justification :
- **Code applicatif** : ~85 % à réécrire (PHP procédural → TS/Next.js). 713 fichiers PHP sans tests = pas de portage automatique possible. Note 5/5 isolément.
- **Modèle de données** : portable à 80 % (MySQL → PostgreSQL + Prisma schema généré semi-auto). Note 2/5 isolément.
- **Logique métier** : doit être extraite manuellement page par page, mais elle est documentée. Note 4/5.
- **Intégrations** (Ubiflow, OpenAI, Anthropic, PHPMailer, Mindee, Google) : à recâbler dans des services TypeScript. Effort modéré (3/5).
- **Multi-tenant** : déjà pensé conceptuellement (helper centralisé) → portage en RLS PostgreSQL réaliste. Note 3/5.
- **Front** : aucun composant React existant, design system uniquement en CSS custom → reconstruction complète. Note 5/5.
- **Tests + CI/CD** : à créer ex nihilo. Note 5/5.

**Moyenne pondérée** ≈ **4 / 5**. Effort estimé : **6 à 12 mois** pour un sprint refonte sérieux à 1-2 développeurs full-time, avec maintenance parallèle du legacy pendant ~6 mois.

### E. Questions critiques pour valider l'architecture cible

1. **Quel modèle de tenancy PostgreSQL** ? (a) une seule base avec `tenant_id` + RLS Postgres, (b) un schéma par société (`SET search_path`), ou (c) une base par société (lourd) ? Selon les ~80 sociétés cibles et les exigences SLA, le choix change tout.
2. **Stratégie de migration des données** : big-bang (cutover unique) ou progressive (dual-write avec sync MySQL ↔ PostgreSQL via Debezium/CDC) ? Acceptable de geler l'app 48 h pour switcher ?
3. **Next.js App Router + Server Actions, ou API REST séparée** (Next.js front + NestJS/Hono back) ? Le second permet une app mobile native plus tard sans réécriture.
4. **Hébergement cible** : Vercel + Neon/Supabase (PaaS, rapide), VPS Hostinger existant + Coolify (cohérent avec GED/MAIL SaaS), ou AWS/Scaleway (plus cher mais souverain) ?
5. **Stockage fichiers** : Vercel Blob, S3 Scaleway (souverain FR), Cloudflare R2 (low cost), ou rester sur volumes Hostinger ? Impact RGPD si données nominatives quittent l'UE.
6. **Wrapper IA** : un service Node central (`@maboximmo/ai`) qui standardise Claude/OpenAI/Mindee avec routing par tâche + caching prompt + observabilité de coût. À quel point veut-on découpler du fournisseur ?
7. **Auth** : NextAuth.js, Clerk, Supabase Auth, ou réimplémenter sessions custom comme aujourd'hui ? Avec MFA + SSO Google pour les agents ?
8. **Design system codé** : Tailwind v4 + shadcn/ui (rapide, moderne) ou continuer le CSS custom existant porté en CSS modules ? Le repo `public_html/memoire/charte_graphique.md` doit guider ce choix.
9. **Coexistence pendant la migration** : le legacy PHP continue-t-il de tourner sur `maboximmo.fr` pendant que le nouveau Next.js sort sur `app.maboximmo.fr` ? Comment partager la session utilisateur (JWT cross-domain) ?
10. **Périmètre du V1 refondu** : on attaque tout l'écosystème (RH + métier + syndic + comptabilité + vitrine) ou un sous-périmètre (ex : agency_* + biens + annonces) en gardant le reste sur l'ancien ? Stratégie « strangler pattern » fortement recommandée.

---

*Audit généré automatiquement à partir d'une lecture statique du dépôt — aucune valeur de credential lue ni exposée. Pour audit dynamique (perfs, requêtes lentes, taille réelle BDD prod), prévoir un accès lecture à la BDD prod et aux logs Hostinger.*
