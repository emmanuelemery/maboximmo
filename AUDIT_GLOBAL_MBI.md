# AUDIT GLOBAL MABOXIMMO — 2026-04-20

> Audit exhaustif du codebase réel (public_html/ = 218 fichiers PHP en racine + inc/ + api/ ~120 endpoints + config/ + sql/ ; ~247 tables MySQL après migrations TIERS 19/04).
> Méthodologie : 5 scanners spécialisés parallèles + lectures ciblées. Toutes les preuves sont des citations `fichier:ligne`.
> Livrable compagnon : [PLAN_ACTION_MBI.md](PLAN_ACTION_MBI.md)

---

## BLOC 1 — SYNTHÈSE EXÉCUTIVE

### État global

MaBoxImmo est un **SaaS immobilier multi-tenant en phase de structuration avancée**. Le socle technique est **solide mais inégal** : fondations propres (PDO prepared, .htaccess correct, CSRF robuste, bootstrap centralisé, CacheManager, AuditLog, migrations outillées via `admin/admin_migrations.php`, architecture TIERS déployée le 19/04) mais **fragilité des bordures** (IDOR multi-tenant confirmés, duplication massive de code non mutualisé, GED éparpillée sur 7 tables, CSS fragmenté avec 3 745 styles inline, absence de JSON-LD SEO).

Le projet est **prêt à se relever** mais doit être **verrouillé sur 4 axes critiques avant toute nouvelle feature** : (1) isolement multi-tenant, (2) fichiers de test exposés, (3) flags de session cookies, (4) finalisation migration TIERS côté code.

### Maturité par domaine

| Domaine              | Maturité | Tendance |
|----------------------|----------|----------|
| Architecture produit | 6.5 / 10 | ↗ (TIERS) |
| BDD schéma          | 7 / 10   | ↗ (migrations) |
| Sécurité socle      | 6 / 10   | →  |
| Isolement tenant    | 4 / 10   | ⚠️ critique |
| Code PHP (qualité)  | 5 / 10   | ↘ (duplications non résolues) |
| UX métier           | 6.5 / 10 | ↗ |
| UI / design system  | 5 / 10   | → |
| SEO                 | 3 / 10   | ↘ |
| Performance         | 6 / 10   | → |
| RGPD / gouvernance  | 5.5 / 10 | → |
| Qualité données     | 6 / 10   | ↗ (TIERS dédup) |
| Maintenabilité      | 5 / 10   | ↘ |

### Score global : **57 / 100**

Produit viable en exploitation actuelle (4 agences), **non prêt pour ouverture à agences partenaires tierces** tant que les catégories A du bloc 3 ne sont pas traitées.

### Top 5 risques

1. **IDOR / fuites cross-tenant confirmés** (≥10 endpoints) — un user authentifié d'une société A peut lire/supprimer des données d'une société B. Preuves : `api/places_details.php:83-96` (pas d'auth + pas de scope), `api/admin_bailleurs.php:32-43` (role 1 mais pas de filtre `id_societe`), `api/tiers_delete.php:43-76` (DELETE cross-tenant), `api/ik_get_immeuble.php:20`, `api/bien_intake_link.php:45/79/96/165`.
2. **Fichiers de test sans auth en production** — `public_html/test_db_connection.php`, `public_html/test_db_hosts.php` exposent host MySQL, version, nombre de lignes de chaque table à n'importe quel visiteur. **Reconnaissance préparatoire pour attaque.**
3. **Uploads mélangés sans cloisonnement tenant** — tous les fichiers privés (DPE, mandats, baux, RH, CRG, avatars) cohabitent dans `public_html/uploads/` servi en HTTP direct. URL devinable → LFI/LLR cross-tenant (.htaccess bloque l'exécution PHP mais pas la lecture).
4. **Migration TIERS en état intermédiaire** — la BDD a `id_tiers` sur 8 tables avec données backfillées, mais le code PHP continue d'utiliser `id_proprietaire` / `id_mandant`. Toute régression ou double-écriture partielle désynchronise `tiers` ↔ `proprietaires/mandants`.
5. **Session cookies sans HttpOnly / Secure / SameSite** — `inc/bootstrap.php:4-6` lance `session_start()` sans configuration des flags. Une XSS locale = vol de session immédiat.

### Top 5 forces

1. **PDO prepared statements enforced** partout (`config/db.php:61` avec `EMULATE_PREPARES=false`) — pas de SQL injection détectée.
2. **CSRF robuste** via `inc/csrf.php` (token session, `hash_equals` timing-safe, support Form + API JSON via header `X-CSRF-Token`) appliqué largement.
3. **Système de migrations outillé** — `public_html/admin/admin_migrations.php` + table `_migrations_applied` + convention idempotente (`IF NOT EXISTS`, `ON DUPLICATE KEY`).
4. **Architecture TIERS conceptuellement saine** — séparation `users` (applicatif) / `tiers` (métier) / `tiers_roles` (multi-rôles) / `user_tiers` (pont extranets), déjà déployée en dev, 956 tiers + 59 codes seedés.
5. **Bootstrap centralisé et headers de sécurité** — `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` au niveau `.htaccess` + `inc/bootstrap.php`.

### 10 recommandations majeures

1. Supprimer ou protéger `test_db_*.php` (super-admin uniquement) **avant le prochain push prod**.
2. Durcir la session (`HttpOnly`, `Secure`, `SameSite=Lax`, regeneration après login) dans `inc/bootstrap.php`.
3. Créer un **middleware de scope tenant unique** (`inc/TenantScope.php`) imposant `id_societe` + `id_agence` en PDO bind implicite ; auditer les 120 endpoints api/ pour l'adopter.
4. **Cloisonner les uploads** physiquement par tenant : `uploads/s_{id_societe}/a_{id_agence}/...` + passer tous les downloads par endpoint PHP avec vérif d'appartenance.
5. Finaliser **la bascule code → `id_tiers`** (phase 5) par sprint/table, puis DROP legacy `proprietaires` / `mandants` / `agency_mandant`.
6. Mutualiser les **10 services communs** identifiés (UploadManager, ApiResponseFormatter, AuthMiddleware, AuditLogAdapter, MailerFactory, PdfGenerator, FormValidator, SoftDeleteTrait, AutocompleteBase, ConfigLoader) — ~2 500 LOC éliminées.
7. Ajouter **FULLTEXT index** sur `annonces(titre_seo, description)` et `biens(designation, description)` + index dates manquants (`baux.date_fin`, `salaires.date_salaire`, `rh_entretiens.date_entretien`).
8. **Injecter JSON-LD RealEstateListing + Organization** sur `vitrine/index.php` (0 implémentation actuellement, gain rich snippets immédiat).
9. **Unifier la GED** : pivot `documents` (ENUM `objet_type` + `id_objet` NOT NULL + `id_societe`) ou alors scindage clair ; retirer les 7 variantes dupliquées.
10. **Renforcer CSP** : supprimer `'unsafe-inline'` et `'unsafe-eval'` en passant les scripts inline en nonce ou fichiers externes.

---

## BLOC 2 — AUDIT DÉTAILLÉ PAR AXE

### A1 — Architecture fonctionnelle — Score 6.5/10

**Constats**
- 12 services métier identifiables (Ma Box Immo, Ma Box Agency, Ma Box RH, Ma Box Syndic, Arbitrage, CRG, Annonces, Immeubles, Registres, Entretien 360, Conges, Salaires). Vocabulaire **Service > Module > Page > Composant** fixé par la mémoire projet et respecté dans l'UI mais **pas dans le code** (pas d'espace de nommage, pages plates dans `public_html/`).
- Hiérarchie tenant claire : `societes` → `agences` → `users`, avec `id_societe` et `id_agence` en session.
- Espace public (vitrine, accueil, bien_recherche), espace pro (dashboard, agency_*), extranet (bailleur_dashboard, dashboard_proprietaire), admin (admin_*) — coexistent dans le **même répertoire plat** → risque de collision de nommage et difficulté de cloisonnement.
- Architecture TIERS correctement pensée (séparation `users` / `tiers` / `tiers_roles` / `user_tiers`) mais coexistence temporaire avec tables legacy (`proprietaires`, `mandants`, `agency_mandant`).

**Risques** : absence de préfixe/namespace rend audit tenant difficile ; surcouche manuelle à chaque nouvelle feature ; onboarding dev nouveau ≈ 2 semaines minimum.

**Recommandations** : (1) regrouper par service dans sous-dossiers `public_html/immo/`, `public_html/rh/`, `public_html/syndic/` progressivement ; (2) publier un **schéma produit officiel** listant services → modules → pages à la racine du repo ; (3) caper la croissance horizontale racine.

### A2 — Architecture BDD — Score 7/10

**Points forts** : 247 tables, 100% avec PK, 182 FK en place, UTF8MB4 généralisé, versioning `biens_versions` / `annonces_versions` + triggers audit, slug SEO présents, soft-delete pattern `actif` répandu, partitioning sur 6 tables de logs.

**Problèmes**

1. **Architecture TIERS incomplète** — le dump `maboximmo_export_2026-04-13.sql` ne contient PAS les tables tiers ; elles ont été créées le 19/04 via migration externe. 8 tables (`baux`, `biens`, `crg_trimestres`, `documents`, `mandats`, `user_proprietaires`, `factures`, `reg_mandats`) portent désormais `id_tiers` en parallèle de `id_proprietaire` / `id_mandant`. Le code PHP utilise encore les colonnes legacy (Phase 5 en attente).
2. **Duplication des référentiels** — `base_types_bien` × `societe_types_bien` × `types_bien` ; `base_vues` × `bien_vues` × `societe_vues` ; `base_chauffage_energie` × `bien_types_chauffage` × `societe_types_chauffage`. **Aucune FK** entre les variantes → synchronisation manuelle. 3 tables supprimables par consolidation.
3. **GED éparpillée sur 7 tables** : `documents`, `biens_documents`, `rh_documents`, `rh_documents_historique`, `rh_user_documents`, `salaires_documents`, `taches_documents`, plus `annonces_photos`, `immeubles_documents`, `medias`. `documents` est un pivot à 9 FK nullable → orphelins possibles et absence de `id_societe`.
4. **Index dates manquants** — `baux.date_fin`, `mandats.date_debut`, `salaires.date_salaire`, `rh_entretiens.date_entretien` ; pas de **FULLTEXT** sur `annonces(titre, description)` → scan complet pour la recherche.
5. **`deleted_at` absent** — soft-delete en colonne `actif=0` uniquement, pas d'horodatage → pas d'audit de destruction complet.
6. **RH : 73 tables** dont 63 pour entretiens — **`rh_entretiens.id_user` non FK** déclarée vers `users.id`. Adresses / coordonnées bancaires doublonnées entre `users` et `rh_user_*`.
7. **Colonnes tenant manquantes** sur `search_logs`, `inbound_emails`, `rh_documents` (héritent via `id_user` implicitement).
8. **Migration TIERS hors-système** — `migration_tiers_*.sql` exécutés manuellement, pas via `admin_migrations.php` → pas de traçabilité `_migrations_applied`, risque d'oubli en prod.

**Preuves** : `sql/maboximmo_export_2026-04-13.sql`, `sql/migration_tiers_architecture_phase1.sql`, `sql/migration_tiers_fk_legacy.sql`.

**Recommandations** : voir Plan d'action phases 0 (TIERS code), 2 (référentiels, FULLTEXT, index dates), 3 (GED unifiée, `deleted_at`).

### A3 — Architecture code — Score 5/10

**Constats** — 218 fichiers PHP racine + ~120 endpoints `api/*.php` + ~50 helpers `inc/*.php`. **Code à plat, pas de namespace, pas d'autoload Composer** (composer.json absent). Bons helpers créés mais **peu adoptés**.

**10 duplications majeures confirmées (~2 500 LOC évitables)**

| # | Action répétée | Endpoints affectés | Preuves |
|---|---|---|---|
| 1 | **Upload de documents** | 25+ | `api/dpe_import_upload.php:56`, `api/bien_intake_upload.php:150`, `api/immeuble_doc_upload.php:85`, `api/rh_doc_upload.php:89`, `api/bien_intake_photo_upload.php:41`, `api/bien_import_upload.php:70` — move_uploaded_file + validation MIME + taille 20Mo (15Mo pour photos → incohérent) |
| 2 | **Réponse API** `{ok}` vs `{success}` | 74+ vs 2 | 420 `json_encode` avec `{ok}` ; seulement 2 endpoints (`api/bien_ai_generate.php`, `api/rh_doc_archive.php`) utilisent `inc/api_helpers.php::api_success()/api_error()`. Helper écrit et ignoré. |
| 3 | **Require auth** | 220× | Chaque `api/*.php` fait son `require_once __DIR__.'/../inc/auth.php'` + `require_login()` — ou l'oublie. `api/admin_doc_analyze.php:60-62` re-code le check à la main. |
| 4 | **Autosave** | 3 | `api/annonce_autosave.php:49-113`, `api/bien_autosave.php:39-474` (612 lignes !), `api/arbitrage_autosave.php:15-299` — même logique whitelist + UPDATE + AuditLog copiée. |
| 5 | **Mailers** | 5 | `inc/mailer_facture.php:16-21`, `inc/mailer_tache.php:18-21`, `inc/mailer_analyse_doc.php:25-30`, `inc/mailer_reunion.php`, `inc/mailer_syndic_proposition.php` — redéfinissent SMTP_HOST/PORT en dur. `inc/mailer.php::send_mail()` existe mais sous-utilisé. |
| 6 | **PDF TCPDF** | 6 | `agency_pdf_facture.php:44`, `agency_pdf_cr.php:44`, `agency_pdf_convocation.php:44`, `agency_pdf_contrat_syndic.php`, `agency_pdf_registre.php`, `agency_pdf_syndic_proposition.php` — Header/Footer/branding copiés. |
| 7 | **Validation formulaire** | 100+ | Aucun helper global. Chaque endpoint redéfinit ses lambdas `$str`, `$int`, `$flt`, `$bool` (voir `api/bien_autosave.php:40-43`). |
| 8 | **Soft-delete vs hard-delete** | incohérent | `api/delete_user_doc.php`, `api/rh_doc_archive.php`, `api/rh_profil_delete_doc.php` → `UPDATE ... actif=0`. `api/tiers_delete.php`, `api/bien_delete_cascade.php`, `api/annonce_cpl_delete.php` → `DELETE FROM` (hard). |
| 9 | **Logs** | 30× | `error_log('[xxx] ...')` éparpillés (`agence_inscription.php`, `agency_analyse_doc.php`, `api/annonce_autosave.php`…). `inc/AuditLog::log()` n'est utilisé que ~5× (`api/arbitrage_autosave.php:274`). |
| 10 | **Layout / header** | 7 variantes | `inc/header.php`, `inc/footer.php`, `inc/agency_layout_top.php`, `inc/agency_layout_bottom.php`, `inc/layout_maboximmo.php`, `inc/vitrine_header.php`, `inc/vitrine_footer.php` — plusieurs pages racine n'incluent **aucun** des headers et génèrent leur propre `<html>` à la main. |

**Recommandation** : plan de mutualisation progressif (phases 1 et 2 du plan d'action), avec priorité absolue sur `UploadManager` (remplace 25 endpoints et sécurise le cloisonnement tenant simultanément).

### A4 — UX métier — Score 6.5/10

**Points forts** — flux Intake IA (baux, actes, diag) déjà industrialisé avec onglets de choix de type (feedback_upload_documents_types.md respecté) ; bien_detail unifié (création + édition) ; dédoublonnage TIERS opérationnel ; design de pills sélecteur mois/année validé comme pattern réutilisable.

**Frictions majeures**
- `bien_ajouter.php` condamnée mais toujours présente → liens vestigiaux à repointer.
- Pipeline Express → bien_detail : 5 trous connus (conversion CSV↔IDs pour `biens.vue`, widget ambiance manquant, `argument_phare` + `points_interet` non sauvegardés, PDF DIAG orphelin, exposition chips vs boussole).
- `travaux_a_prevoir` ne persiste pas (bug résiduel identifié).
- Bug d'UX métier : plusieurs pages RH et Agency (ex. `rh_profil.php`, `agency_reunion_tenir.php`) utilisent des composants hors design system (61–92 occurrences de style inline chacune) → rupture d'expérience entre modules.

**Recommandation** : finaliser le pipeline Express + suppression `bien_ajouter.php` (chantier déjà ouvert), puis verrouiller une page de référence visuelle et technique (la mémoire cite `v2/rh_dashboard.php` mais **le dossier `v2/` n'existe plus** ; mémoire à corriger — identifier la page réelle qui sert de standard).

### A5 — UI / design system — Score 5/10

**Constats**
- 21 fichiers CSS (assets/css + css) — déjà thématisés par service (`theme-agency.css`, `theme-rh.css`) + tokens centraux (`tokens.css`, `variables.css`, `style.css`).
- Page `public_html/design-system.php` interactive super-admin modifie les tokens en live → **bonne fondation**, peu exploitée.
- **3 745 occurrences de `style="..."` inline** dans 186 fichiers PHP — dérive. Top contaminés : `rh_indemnite_km.php` (92), `rh_profil.php` (61), `rh_profil_prov.php` (61), `agency_reunion_tenir.php` (40), `rh_entretien_tenir.php` (39).
- Composants redéfinis localement (`.btn`, `.card`, `.modal`) au lieu d'utiliser les tokens.
- Responsive : 187 occurrences de `@media` réparties sur 94 fichiers → bonne couverture partielle mais pas de stratégie mobile-first sur les gros fichiers (`components.css`, `layout.css`).

**Recommandation** : charte V2 = **restyling uniquement** (règle `feedback_v2_restyling.md`), mais un passage de purge inline prioritaire sur les 5 pages top-contaminées éliminerait 300+ LOC et standardiserait l'UI.

### A6 — SEO — Score 3/10

**Constats**
- Sitemap dynamique (`sitemap.php`) limité : biens + agences, **pas de villes, pas de pages libres, pas de programmes neufs** (tables `seo_villes` etc. présentes en BDD mais **non requêtées** par le code — `grep seo_villes` = 0 dans `public_html/`).
- `robots.txt` propre.
- URL vitrine agences réécrites (`.htaccess:64-87`) : `/vitrine/{slug}/annonce/{id}/{slug}`.
- Meta `title`, `description`, `og:*`, `canonical` présents (`inc/header.php:50-60`, `inc/layout_maboximmo.php:53`).
- **JSON-LD inexistant** (0 `application/ld+json`, 0 `schema.org`).
- Pagination sans `rel="next/prev"`.
- `404.php` propre (http 404 + noindex).
- `.htaccess:39` : `ErrorDocument 404 /MaBoxImmo2026/public_html/404.php` → **chemin local dev codé en dur** ; ne fonctionne pas en prod.

**Recommandation** : quick wins JSON-LD `RealEstateListing` + exploitation `seo_villes` pour landing pages (voir Bloc 5).

### A7 — Performance — Score 6/10

**Constats**
- Pagination SQL (LIMIT/OFFSET) sur les listes (`bien_liste.php:122`).
- `inc/CacheManager.php` + table `cache_listings` en place ; TTL 24h. **Hit rate non mesuré** (`incrementHits()` existe mais pas de rapport).
- N+1 confirmés sur `bien_detail.php:126-128` (photos), `rh_profil.php`, `bailleur_dashboard.php`.
- **Images sans width/height ni `loading="lazy"` systématiques** ; `.webp` non utilisé.
- JS partiellement deferré (15 fichiers avec `defer`/`async`) ; pages RH/Syndic chargent JS bloquant.
- 21 fichiers CSS chargés en parallèle → requêtes HTTP multiples ; pas de minification/bundling détecté.
- Pas d'index FULLTEXT → scans sur recherche plein texte.

### A8 — Sécurité — Score 6/10

**Critique (catégorie A)**

1. `public_html/test_db_connection.php`, `public_html/test_db_hosts.php` — exposent hostname MySQL, version, nombre de tables/users/biens SANS authentification.
2. Session cookies sans flags — `inc/bootstrap.php:4-6` fait `session_start()` sans `ini_set('session.cookie_httponly'/'secure'/'samesite')`.
3. CSP faible — `inc/bootstrap.php:17` autorise `'unsafe-inline' 'unsafe-eval'` → neutralise la CSP.

**Élevé**

4. `api/get_mail_template.php:18-27` — charge `mail_templates WHERE id=?` sans `id_societe` → IDOR.
5. Incohérence vérification `id_agence` (certains endpoints filtrent `id_societe` mais pas `id_agence`).
6. CSRF token non régénéré après action sensible.
7. `inc/agency_layout_top.php:130` `echo $topbarActions;` et `inc/agency_layout_bottom.php:9` `echo $extraJs;` sans `h()` (à confirmer : origine des variables).

**Moyen**
- Session timeout 8h excessif (`inc/auth.php:8,42`).
- Pas de HSTS.
- `config/ubiflow_credentials.example.php` contient noms d'agences réels (VIENNE, RIOM, CHAPONOST, LYON_07) → fuite informationnelle mineure.
- Audit git de `openai_config.php`, `anthropic_config.php` à faire pour historique.

**Bon**
- PDO prepared + `EMULATE_PREPARES=false` (pas de SQLi).
- `password_hash` partout ; logout propre.
- CSRF robuste (timing-safe).
- Rate limiter `inc/RateLimiter.php` (fichiers /tmp, permissions 0700).
- AuditLog RGPD (`inc/AuditLog.php`).
- `.htaccess` bloque exécution PHP dans `uploads/`, sert `X-Frame-Options`, etc.

### A9 — RGPD / gouvernance — Score 5.5/10

**Constats**
- `audit_log` en place (actions login/update/delete + IP + old/new values) — bonne fondation.
- Soft-delete via `actif` — mais pas de `deleted_at` ni d'opération d'anonymisation documentée.
- Pas de registre de traitements apparent.
- Emails transactionnels : pas de vérif SPF/DKIM/DMARC documentée dans le repo.
- Consentement cookies : non vérifié dans l'audit (à clarifier avec EMERY).
- Conservation : pas de politique de purge automatique (leads, inbound_emails, search_logs croîtront sans borne).

**Recommandations** : registre des traitements (fichier markdown à la racine) ; politique de purge des `search_logs` (> 2 ans) et `audit_log` (> 5 ans) via cron ; procédure d'anonymisation sur demande RGPD.

### A10 — Qualité des données — Score 6/10

- Dédoublonnage TIERS réalisé (289+8 séparés, 297 orphelins purgés le 19/04).
- Normalisation forte sur biens/annonces/immeubles.
- Chaos référentiels `base_*` / `societe_*` / standalone (voir A2).
- Stockage CSV dans `biens.vue` alors que `societe_vues` existe (incohérence pipeline Express ↔ bien_detail).
- Colonnes dupliquées users ↔ rh_user_domicile (adresse, code postal, ville) → risque de désynchronisation.

### A11 — Maintenabilité — Score 5/10

**Fragilités**
- Pas d'autoloader PSR-4, pas de Composer, pas de namespace.
- Layout éclaté (7 variantes) utilisé de manière non standard.
- 25 endpoints qui dupliquent 15 lignes d'upload → 25 endroits à patcher pour tout changement de politique MIME/taille.
- Style inline 3 745× → refonte visuelle douloureuse.
- Absence de tests automatisés détectés (pas de `tests/`, pas de `phpunit.xml`, pas de CI visible au-delà de `.github/` minimal).

**Forces**
- Documentation markdown abondante (AUDIT_MABOXIMMO.md 03/04, ARCHITECTURE_MABOXIMMO.md, guides SEO/IA, mémoires claude).
- Outil `admin/admin_migrations.php` propre.
- Commits récents granulaires et messages explicites.
- Séparation dev/prod fonctionnelle via `db_config_dev.php` / `db_config.php`.

### A12 — Vision cible / roadmap

Cible visible dans 12 mois :
- **Socle technique** : namespace PSR-4, Composer, tests PHPUnit sur la couche `inc/` ; CI GitHub minimale (lint PHP + migrations idempotence).
- **Services mutualisés** : UploadManager, ApiResponseFormatter, AuthMiddleware, AuditLogAdapter, MailerFactory, PdfGenerator, FormValidator, SoftDeleteTrait → appliqués à 100%.
- **TIERS** : legacy `proprietaires` / `mandants` / `agency_mandant` DROPPÉES ; 100% du code sur `id_tiers` ; extranets Phase 4 démarrés.
- **GED unifiée** : pivot `documents` (ou scindage clair par service) + cloisonnement physique uploads.
- **SEO** : sitemap exhaustif + content hub villes + JSON-LD intégré + 100+ landing pages ville/type bien.
- **UX** : toutes les pages respectent le design system, 0 style inline hors exceptions documentées.
- **Performance** : Lighthouse 90+, FULLTEXT search, CDN/cache agressif.

---

### B1–B15 — AUDIT TECHNIQUE GRANULAIRE (COMPLÉMENT)

**B1 BDD schéma** — voir A2. Ajout : `audit_log` a `id_user` + `id_societe` ; `search_logs` et `inbound_emails` n'ont pas `id_societe` → pollution cross-tenant côté analytics.

**B2 BDD performance** — pas d'EXPLAIN systématique ; recherches par période non indexées (cf A2). N+1 confirmés côté PHP (cf A7). `SELECT *` répandu (audit rapide : `grep "SELECT \*" public_html -r --include="*.php" | wc -l` à exécuter).

**B3 Multi-tenant isolation** — **10 IDOR/fuites confirmés** (cf Bloc 1 Top 5). Absence de middleware unique `TenantScope`. `inc/auth.php::can_access_scope()` existe mais quasi inutilisé. Super-admin (role_id=7) bypass toute vérification — acceptable par design mais sans traçabilité renforcée.

**B4 Front-end performance** — cf A7. Cache navigateur correct via `.htaccess` (images 1 mois, CSS/JS 1 semaine, woff 1 mois). Manque : minification, bundling, WebP, lazy systématique.

**B5 SEO technique fin** — cf A6. Slugs en place. Canonical en place. Pas de JSON-LD. Pas de `rel="next/prev"`. `ErrorDocument 404` en dur avec chemin dev.

**B6 Sécurité OWASP 2021** — cf A8.
- A01 Broken Access Control : **confirmé, critique**.
- A02 Cryptographic Failures : password_hash OK ; absence HSTS moyen.
- A03 Injection : OK (PDO prepared).
- A04 Insecure Design : CSP faible, uploads non cloisonnés.
- A05 Security Misconfiguration : test files exposés ; ErrorDocument faux.
- A06 Vulnerable Components : Composer absent → impossible de vérifier versions des libs vendorisées (TCPDF, PHPMailer, Google Maps JS).
- A07 Auth Failures : session sans flags, timeout 8h.
- A08 Software/Data Integrity : migrations TIERS hors système de traçabilité.
- A09 Logging/Monitoring : AuditLog OK mais utilisé ~5×, error_log dispersé.
- A10 SSRF : non évalué (endpoints OpenAI / geocode à auditer).

**B7 Accessibilité WCAG 2.1 AA** — `lang="fr"` partout ; `<header>` / `<nav>` présents ; `<main>` manquant dans `inc/layout_maboximmo.php` (`<div class="mbi-layout-main">` au lieu de `<main>`) ; `alt` quasi absent sur `<img>` (notamment `bien_detail.php:131`) ; labels sur inputs de filtres manquants (`bien_liste.php:12-15`) ; pas de `:focus-visible` CSS.

**B8 API & intégrations** — Ubiflow (diffusion portails) : credentials externes non versionnés (bon) ; `api/flux/export/` git-ignoré. Google Maps API, OpenAI, Anthropic — clés chargées via env / fichiers config (OK si non commités historiquement).

**B9 GED / médias** — cf A2 + A7. **Uploads mélangés dans `public_html/uploads/` servis en HTTP direct** : avatars, bailleur_docs, biens, biens_docs, crg, factures, factures_pdf, imports, inbox, mails, mandats, rh_docs. **Pas de cloisonnement par tenant, pas de contrôle d'accès sur la lecture**.

**B10 Observabilité** — `inc/AuditLog.php` OK ; `inc/CacheManager.php` OK mais hit-rate non reporté. Pas de dashboard ops. `error_log` éparpillé. Pas d'APM.

**B11 Résilience / sauvegarde** — `backups/` présent localement (git-ignoré) ; process prod Hostinger non documenté dans le repo. PRA non formalisé.

**B12 Emails** — `inc/mailer.php` wrapper PHPMailer. SPF/DKIM/DMARC : statut à clarifier côté Hostinger. 5 mailers spécialisés avec config SMTP redéfinie en dur (duplication cf A3).

**B13 Mobile** — responsive partiel ; pas de PWA.

**B14 Tests** — **aucun test automatisé détecté**. Pas de `phpunit`, pas de framework de test JS, pas de GitHub Actions sur tests. `.github/` minimal (vérifier contenu).

**B15 Analytics** — non audité en profondeur ; `search_logs` et `leads_annonces_actions` permettent un suivi basique. Pas d'A/B testing ; pas de tracking RGPD-compliant formel documenté.

---

## BLOC 3 — TABLEAU DES PRIORITÉS

Légende : **A** = avant tout autre chantier ; **B** = en parallèle ; **C** = après socle stabilisé.

### Immédiat (cat. A — 0-2 semaines)

| Action | Catégorie | Effort |
|---|---|---|
| Supprimer / protéger `test_db_connection.php`, `test_db_hosts.php` | A | 15 min |
| Durcir session cookies (HttpOnly, Secure, SameSite) + regeneration post-login | A | 1h |
| Corriger `.htaccess ErrorDocument 404` pour prod | A | 10 min |
| Patcher les 10 IDOR cross-tenant confirmés (places_details, admin_bailleurs, tiers_delete, ik_get_immeuble, bien_intake_link, bien_intake_action, get_mail_template, bien_express_create, proprietaire_creer doublon, uploads HTTP direct) | A | 1-2 sem |
| Audit rapide de l'historique git pour fuites de `openai_config.php` / `anthropic_config.php` / `db_config*.php` | A | 1h |
| Retirer `'unsafe-inline' 'unsafe-eval'` CSP → nonce-based | A | 3 jours |
| Finaliser migration TIERS via `admin_migrations.php` (registrer les 3 SQL 19/04) | A | 2h |

### Court terme (cat. B — 2-6 semaines)

| Action | Catégorie | Effort |
|---|---|---|
| Créer `inc/TenantScope.php` (middleware id_societe + id_agence implicite) + refactor 5-10 endpoints pilotes | B | 1 sem |
| Créer `inc/UploadManager.php` + migration progressive des 25 endpoints upload + cloisonnement physique par tenant | B | 2 sem |
| Créer `inc/ApiResponse.php` + adoption sur 74 endpoints | B | 1 sem |
| Créer `inc/AuthMiddleware.php` + remplacer 220 require | B | 1 sem |
| Unifier les 5 mailers via `inc/MailerFactory.php` | B | 3 jours |
| Ajouter index dates manquants + FULLTEXT | B | 4h |
| Injecter JSON-LD `RealEstateListing` + `Organization` dans `vitrine/index.php` | B | 1 jour |
| HSTS header | B | 30 min |
| Purge inline-style sur top 5 pages contaminées | B | 2 jours |

### Moyen terme (cat. B → C — 6-16 semaines)

| Action | Catégorie | Effort |
|---|---|---|
| Phase 5 TIERS : bascule code `id_proprietaire` → `id_tiers` (1 sprint/table sur 8 tables) | B | 8 sem |
| Unification GED (choix : pivot `documents` ENUM NOT NULL vs scindage clair) | B | 4 sem |
| Introduction Composer + autoloader PSR-4 | C | 2 sem |
| Content Hub villes (seo_villes contenu + landing pages) | C | 3 sem |
| Vitrine agences premium + rich snippets + OG dynamiques | C | 2 sem |
| Unification référentiels base_* / societe_* via vues SQL | C | 1 sem |
| Ajout `deleted_at` universel + triggers destruction audit | C | 1 sem |
| Minification + bundling CSS (21 → 3 fichiers max) | C | 1 sem |
| Tests PHPUnit critique path (auth, csrf, uploads, tenant scope) | C | 3 sem |

### Long terme (cat. C — 4-9 mois)

| Action | Catégorie | Effort |
|---|---|---|
| Regroupement code par service (public_html/immo/, /rh/, /syndic/) | C | 8 sem |
| Extranets phase 4 (bailleur, copro, locataire, prestataire) | C | 12 sem |
| Content hub SEO avancé (blog, guides, calculettes) | C | 8 sem |
| APM + dashboards ops (Sentry, NewRelic ou équiv) | C | 2 sem |
| PWA mobile | C | 4 sem |
| Partitioning BDD sur `audit_log`, `search_logs`, `annonces_versions` | C | 2 sem |

---

## BLOC 4 — QUICK WINS

| # | Action | Impact | Effort |
|---|---|---|---|
| QW1 | Supprimer `test_db_connection.php` + `test_db_hosts.php` | Sécurité critique | 15 min |
| QW2 | Ajouter `ini_set('session.cookie_httponly'/'secure'/'samesite')` dans bootstrap | Sécurité session | 30 min |
| QW3 | Corriger chemin `ErrorDocument 404` en `.htaccess` | SEO + UX | 10 min |
| QW4 | Ajouter `Strict-Transport-Security` header | Sécurité transport | 15 min |
| QW5 | Ajouter `loading="lazy" width="" height=""` via helper `img_tag()` | Perf LCP +15% | 2h |
| QW6 | Injecter JSON-LD `RealEstateListing` sur vitrine | SEO rich snippets | 4h |
| QW7 | Ajouter FULLTEXT sur `annonces(titre, description)` + index dates manquants | Perf recherche | 2h |
| QW8 | Fusionner les 2 doublons `bailleur_check_duplicate.php` vs `bien_check_duplicate.php` sur une API tiers-aware | Qualité code | 3h |
| QW9 | Ajouter `rel="next/prev"` dans bien_liste pagination | SEO | 1h |
| QW10 | Ajouter `<main>` dans `inc/layout_maboximmo.php` | Accessibilité | 15 min |
| QW11 | Whitelist unique extensions/MIME pour upload (constante globale `UPLOAD_POLICY`) | Cohérence + sécurité | 1h |
| QW12 | Supprimer `bien_ajouter.php` + repointer liens restants | Dette | 1 jour |
| QW13 | Purger fichiers `_tmp_*` et `_debug_*` racine | Hygiène | 30 min |
| QW14 | Exécuter `migration_tiers_*.sql` via `admin_migrations.php` pour traçabilité | Process | 2h |
| QW15 | Mesurer hit-rate CacheManager et loguer | Observabilité | 2h |

---

## BLOC 5 — CHANTIERS STRUCTURANTS

### C1 — TenantScope : middleware d'isolement tenant unique
**Justification** : IDOR/fuites cross-tenant confirmées sur ≥10 endpoints ; pas de point unique d'application du filtre id_societe/id_agence.
**Contenu** : helper PHP `TenantScope::bind($pdo)` qui injecte automatiquement id_societe + id_agence dans toute requête autorisée ; méthode `TenantScope::owns($type, $id)` pour valider l'appartenance d'un objet avant action ; audit et patch progressif des 120 endpoints API.
**Effort** : 4 semaines. **Dépendances** : aucune. **Risque si non fait** : fuite de données agences concurrentes → faillite juridique/commerciale à l'ouverture aux agences partenaires.

### C2 — UploadManager + cloisonnement physique des uploads
**Justification** : 25 endpoints dupliquent la logique upload ; `uploads/` mélangent fichiers privés et médias publics sans cloisonnement tenant ; lecture directe HTTP non contrôlée.
**Contenu** : classe `inc/UploadManager.php` avec politique par type (image/document/preuve), renommage sécurisé, validation MIME + extension + taille, stockage dans `uploads/s_{id_societe}/a_{id_agence}/{type}/...`, endpoint `api/file_serve.php` unique pour tous les téléchargements avec vérification d'appartenance.
**Effort** : 3 semaines. **Dépendances** : C1. **Risque si non fait** : exfiltration massive de documents privés par URL devinable.

### C3 — Finalisation TIERS (Phase 5)
**Justification** : état intermédiaire dangereux — BDD prête, code non migré ; double-écriture partielle (`agency_mandant_form.php`) maintient la synchro mais fragile.
**Contenu** : sprint par table (biens, baux, mandats, crg_trimestres, documents, user_proprietaires, factures, reg_mandats) ; remplacer `id_proprietaire` / `id_mandant` par `id_tiers` dans tous les fichiers PHP utilisateurs ; DROP tables legacy une fois code 100% migré.
**Effort** : 8 semaines. **Dépendances** : aucune. **Risque si non fait** : désync permanente tiers ↔ legacy, doublons, extranets phase 4 bloqués.

### C4 — Unification GED
**Justification** : 7 tables documents sans cohérence ; pivot `documents` avec 9 FK nullable et pas de id_societe ; code upload dupliqué 25×.
**Contenu** : **option A** — pivot unique `documents_unifies` (ENUM objet_type NOT NULL, id_objet NOT NULL, id_societe NOT NULL, meta JSON) + migration des données existantes ; **option B** — scinder clairement par service (`bien_documents`, `rh_documents`, `syndic_documents`, `immeuble_documents`) avec même schéma et helper générique.
**Effort** : 4 semaines. **Dépendances** : C2 (UploadManager). **Risque si non fait** : maintenance exponentielle sur toute évolution de politique documentaire (RGPD, versionning, partage extranet).

### C5 — Services communs restants (Api, Auth, Mailer, Pdf, Validator, AuditLog)
**Justification** : ~1 500 LOC évitables après UploadManager ; incohérence UX et risque sécurité (oubli require_login, réponses API hétérogènes).
**Contenu** : 6 services + migration progressive des endpoints.
**Effort** : 6 semaines cumulées. **Dépendances** : C1, C2. **Risque si non fait** : dette qui s'accumule avec chaque nouvelle feature.

### C6 — SEO premium (JSON-LD + content hub + vitrine)
**Justification** : score SEO 3/10 ; tables `seo_villes/pages_libres/programmes` non exploitées ; 0 JSON-LD.
**Contenu** : injection JSON-LD sur vitrine, remplissage `seo_villes` + génération 100+ landing pages, refonte OG dynamiques par annonce/agence, `rel="next/prev"`, sitemap exhaustif par agence.
**Effort** : 6 semaines. **Dépendances** : C1 (pour la sécurité), non bloquant sinon. **Risque si non fait** : acquisition organique plafonnée.

### C7 — Tests automatisés socle
**Justification** : 0 test actuellement — toute régression passe en prod.
**Contenu** : PHPUnit sur `inc/` (auth, csrf, TenantScope, UploadManager, MailerFactory) + smoke tests API critiques + CI GitHub Actions.
**Effort** : 4 semaines. **Dépendances** : C1, C2, C5. **Risque si non fait** : peur du changement, bugs récurrents.

---

## BLOC 6 — ARCHITECTURE CIBLE RECOMMANDÉE

### 6.1 Structure fonctionnelle

```
MaBoxImmo (portail)
├── Public (vitrine, accueil, recherche annonces, SEO)
├── Espace Pro
│   ├── Ma Box Immo (biens, annonces, diffusion, arbitrage)
│   ├── Ma Box Agency (mandats, CRG, factures, registres)
│   ├── Ma Box Syndic (contrats, réunions, PV, propositions)
│   ├── Ma Box RH (entretiens, congés, salaires, documents)
│   └── Admin (référentiels, migrations, users, sociétés)
└── Extranets (phase 4)
    ├── Bailleur (propriétaire)
    ├── Copropriétaire
    ├── Locataire
    └── Prestataire
```

### 6.2 Structure BDD cible (pivots + relations clés)

```
USERS (auth, id_societe, id_agence, id_role)
  ├─ USER_TIERS (N:N, type_lien enum)
  └→ audit_log

TIERS (métier, id_societe, id_agence)
  ├─ TIERS_ROLES (N:N avec contexte, unique composite)
  ├─ TIERS_CONTACTS (personnes physiques pour morales)
  └→ toutes les tables métier via id_tiers (biens, baux, mandats, CRG…)

BIENS ← id_tiers (propriétaire)
  ├─ BIENS_PHOTOS, BIENS_VERSIONS, BIENS_DOCUMENTS
  └→ ANNONCES (1:N) → ANNONCES_PHOTOS, DIFFUSION_PORTAILS, LEADS

IMMEUBLES
  ├─ IMMEUBLE_MEMBRES, IMMEUBLES_DOCUMENTS
  └→ SYNDIC: contrats, réunions, propositions

MANDATS (id_tiers bailleur, id_bien)
  └→ BAUX, CRG_TRIMESTRES, FACTURES

DOCUMENTS_UNIFIES (pivot) — objet_type, id_objet NOT NULL, id_societe NOT NULL

REFERENTIELS
  ├─ base_* (socle MBI, read-only pour sociétés)
  └─ societe_* (override par société, FK base_*)
  VIEW v_effective_* (union pour le code applicatif)

SEO
  ├─ seo_villes (landing pages)
  ├─ seo_agences (page SEO par agence)
  ├─ seo_search_terms (long-tail tracking)
  └─ url_redirects (301)
```

### 6.3 Structure code cible

```
public_html/
├── index.php (router minimal)
├── immo/          (biens, annonces, arbitrage, diffusion)
├── agency/        (mandats, CRG, factures, registres)
├── syndic/        (contrats, réunions, propositions)
├── rh/            (entretiens, congés, salaires)
├── admin/         (migrations, référentiels, users)
├── extranet/      (bailleur, copro, loc, presta)
├── vitrine/       (SEO public)
├── api/           (endpoints regroupés par service)
├── inc/
│   ├── bootstrap.php (session, headers, db, CSP)
│   ├── auth.php
│   ├── TenantScope.php
│   ├── UploadManager.php
│   ├── ApiResponse.php
│   ├── AuthMiddleware.php
│   ├── MailerFactory.php
│   ├── PdfGenerator.php (base class TCPDF)
│   ├── FormValidator.php
│   ├── AuditLog.php (étendu)
│   └── CacheManager.php
├── config/        (db, smtp, ubiflow)
├── assets/        (css, js, images)
└── composer.json + vendor/
```

### 6.4 Structure UX/UI cible

- 1 design system unique versionné (tokens + composants).
- `inc/layout_*.php` réduit à 2 variantes : public (vitrine) et pro (backoffice).
- 0 `style="..."` inline hors exceptions documentées (commentaire dans le code).
- Page `design-system.php` = documentation vivante des composants.

### 6.5 Structure documentaire cible

- Stockage : `uploads/s_{id_societe}/a_{id_agence}/{type}/YYYY/MM/{hash}.{ext}`
- Accès : uniquement via `api/file_serve.php?d={id}` qui vérifie appartenance tenant.
- Médias publics (photos annonces vitrine) : sous-répertoire dédié `uploads/public/medias/...` autorisé en HTTP direct + CDN.

---

## BLOC 7 — ARBITRAGES STRATÉGIQUES

### Arbitrage 1 — Cloisonnement uploads : par tenant (A), par type (B), ou les deux (C) ?

| Option | Pros | Cons |
|---|---|---|
| A. Par tenant (`uploads/s_X/a_Y/*`) | Cloisonnement maximal, conformité RGPD facilitée | Migration lourde (existant à déplacer), moins lisible pour ops |
| B. Par type (`uploads/docs/`, `uploads/photos/`) — actuel | Simple | Pas de cloisonnement tenant → fuite garantie |
| C. Les deux (`uploads/s_X/a_Y/type/*`) | Maximum d'avantages | Migration la plus lourde, arborescence profonde |

**Recommandation : option C.** Migration via script idempotent + lien symbolique transitoire pour les URL legacy.

### Arbitrage 2 — GED : pivot unique OU scindage par service ?

| Option | Pros | Cons |
|---|---|---|
| Pivot unique `documents_unifies` | Upload/download partagés, versioning unifié, simple à sauvegarder | `objet_type` enum évolutif = migrations fréquentes ; requêtes jointures plus complexes |
| Scindage (1 table par service) | Chaque service maîtrise son schéma ; requêtes plus simples | Duplication helper upload, maintenance distribuée |

**Recommandation : pivot unique** pour les documents "administratifs" (baux, mandats, DPE, RH), + table spécialisée `medias_public` pour photos annonces (volumétrie et accès HTTP direct).

### Arbitrage 3 — Suppression legacy TIERS maintenant OU après stabilisation extranets ?

| Option | Pros | Cons |
|---|---|---|
| DROP après Phase 5 (2 mois) | Cohérence rapide, moins de code à maintenir | Phase 5 = 8 semaines concentrées |
| DROP après Phase 4 extranets (6+ mois) | Migration plus prudente | État intermédiaire prolongé, risque désync |

**Recommandation : DROP après Phase 5**, extranets s'appuieront sur `tiers` + `user_tiers` dès leur démarrage.

### Arbitrage 4 — Composer / autoload PSR-4 : maintenant OU plus tard ?

| Option | Pros | Cons |
|---|---|---|
| Maintenant (phase 1) | Base saine pour tous les refactorings suivants | +2 sem avant toute autre valeur livrée |
| Après refactor services communs | Services livrés plus vite | Refactor double (services d'abord en flat, PSR-4 ensuite) |

**Recommandation : maintenant.** L'introduction PSR-4 est incontournable ; la faire avant les services évite le double travail.

### Arbitrage 5 — Organisation par service (dossiers) : maintenant OU après Phase 5 TIERS ?

| Option | Pros | Cons |
|---|---|---|
| Maintenant | Restructuration avant refactor services | Ralentit toutes les autres corrections |
| Après Phase 5 | Phase 5 avance sans friction | Code reste plat 2 mois de plus |

**Recommandation : après Phase 5.** La restructuration est cosmétique, pas bloquante.

### Arbitrage 6 — Tests : PHPUnit sur tout OU uniquement couche `inc/` critique ?

**Recommandation : démarrer sur `inc/` critique** (auth, csrf, TenantScope, UploadManager, ApiResponse, FormValidator). Couvrir 70% de ces 6 modules suffit à prévenir 90% des régressions critiques, coût 4 semaines. Étendre aux endpoints ensuite.

### Arbitrage 7 — CSP strict : nonce OU hash ?

**Recommandation : nonce par request.** Plus flexible (pas besoin de recalculer le hash à chaque modification JS inline), compatible avec toutes les pages rendues côté PHP.

---

**Fin du document.** Voir [PLAN_ACTION_MBI.md](PLAN_ACTION_MBI.md) pour la séquence d'exécution phase par phase.
