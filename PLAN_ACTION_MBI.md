# PLAN D'ACTION MABOXIMMO — 2026-04-20

> Plan d'exécution tiré de l'audit complet [AUDIT_GLOBAL_MBI.md](AUDIT_GLOBAL_MBI.md).
> Chaque action porte : **pourquoi**, **fichiers/tables impactés**, **effort**, **dépendances**, **risques si non fait**, **métrique de succès**.
> Le plan suit une logique de fondations → mutualisation → refonte → scalabilité. Les phases 0 et 1 sont sans négociation.

---

## PHASE 0 — IMMÉDIAT (SÉCURITÉ / RGPD / FONDATIONS CRITIQUES)

**Durée cible : 2 semaines.** Aucune nouvelle feature ne doit démarrer tant que la phase 0 n'est pas close.

### 0.1 Supprimer les fichiers de test exposés en production
- **Pourquoi** : `test_db_connection.php` et `test_db_hosts.php` exposent hostname MySQL, version, nombre de lignes de chaque table à un visiteur non authentifié → reconnaissance préparatoire pour attaque.
- **Fichiers** : `public_html/test_db_connection.php`, `public_html/test_db_hosts.php`, `public_html/_debug_photos_annonce.php` (vérifier), `public_html/_tmp_*` (purge complète).
- **Effort** : 30 min.
- **Dépendances** : aucune.
- **Risque si non fait** : énumération BDD, préparation injection/DoS ciblés.
- **Métrique de succès** : `curl -s https://maboximmo.fr/test_db_connection.php` retourne 404 ou 401.

### 0.2 Durcir les cookies de session
- **Pourquoi** : `session_start()` est lancé sans flags `HttpOnly`, `Secure`, `SameSite` dans `inc/bootstrap.php:4-6`. Une XSS = vol de session immédiat.
- **Fichiers** : `inc/bootstrap.php` (ajouter `ini_set('session.cookie_httponly','1');`, `ini_set('session.cookie_secure','1');`, `ini_set('session.cookie_samesite','Lax');` AVANT `session_start()`), `inc/auth.php` (appel `session_regenerate_id(true)` après login dans `login.php`).
- **Effort** : 1h + tests régression login/logout.
- **Dépendances** : aucune.
- **Risque si non fait** : hijack session via XSS ou HTTP downgrade.
- **Métrique de succès** : inspecteur navigateur montre `HttpOnly`, `Secure`, `SameSite=Lax` sur `PHPSESSID`.

### 0.3 Corriger le chemin hardcodé du 404 en `.htaccess`
- **Pourquoi** : `.htaccess:39` pointe `/MaBoxImmo2026/public_html/404.php` — fonctionne en local XAMPP, **casse en prod** (domaine `maboximmo.fr` n'a pas ce préfixe).
- **Fichiers** : `public_html/.htaccess:39` → `ErrorDocument 404 /404.php`.
- **Effort** : 10 min + vérif prod.
- **Dépendances** : aucune.
- **Risque si non fait** : 404 natif Apache affiché en prod (UX + SEO dégradés).
- **Métrique de succès** : `curl -I https://maboximmo.fr/xyzinexistante` retourne 404 + body du `404.php` personnalisé.

### 0.4 Corriger les 10 IDOR / fuites cross-tenant confirmés
- **Pourquoi** : endpoints listés ci-dessous permettent à un user d'une société A de lire/supprimer des données de société B.
- **Fichiers et patches** :
  - `api/places_details.php:83-96` — ajouter `require_login()` + `WHERE id_societe = :societe`.
  - `admin_bailleurs.php:32-43` — ajouter `WHERE t.id_societe = :societe OR current_role_id() = 7`.
  - `api/tiers_delete.php:43-76` — avant DELETE, vérifier `WHERE id_tiers IN (SELECT id FROM tiers WHERE id_societe = :societe)`.
  - `api/bien_intake_link.php:45,79,96,165` — scope proprietaires/immeubles par société.
  - `api/ik_get_immeuble.php:20`, `api/ik_get_distance.php:58` — filtre société sur immeubles.
  - `api/bien_intake_action.php:42` — vérifier `$societeId === (int)$bienRow['id_societe']` systématiquement, 403 sinon.
  - `api/get_mail_template.php:18-27` — `WHERE id_societe = :societe`.
  - `api/bien_express_create.php:46` — scope `id_agence` en plus de `id_societe`.
  - `api/proprietaire_creer.php:72-76` — scope sur détection doublon.
- **Effort** : 1 à 2 semaines (30 endpoints à revoir au total incluant audit complet).
- **Dépendances** : 0.2.
- **Risque si non fait** : fuite de données entre agences concurrentes = incident majeur RGPD + commercial.
- **Métrique de succès** : audit script qui parcourt les endpoints et vérifie présence d'un filtre tenant → 100% OK.

### 0.5 Cloisonner physiquement les uploads (migration rapide de sécurité)
- **Pourquoi** : `public_html/uploads/` contient avatars, biens_docs, mandats, rh_docs, crg, bailleur_docs, inbox, mails mélangés sans cloisonnement tenant. URL devinable = LFI.
- **Fichiers / opérations** :
  1. Créer structure `uploads/s_{id_societe}/a_{id_agence}/{type}/...`.
  2. Script de migration `scripts/migrate_uploads_cloisonnement.php` qui déplace les fichiers existants et met à jour les `url_fichier` en BDD.
  3. Créer `api/file_serve.php` unique qui vérifie l'appartenance avant de streamer le fichier.
  4. Mettre à jour `.htaccess` pour bloquer l'accès HTTP direct à `uploads/s_*` (autoriser seulement `uploads/public/medias/*`).
- **Effort** : 2 semaines (implémentation + script migration + test sur dev).
- **Dépendances** : 0.4.
- **Risque si non fait** : exfiltration de baux/mandats/DPE/documents RH.
- **Métrique de succès** : `curl https://maboximmo.fr/uploads/s_1/a_1/mandats/xxx.pdf` → 403 ; seul `api/file_serve.php?d={id}` fonctionne avec session valide et scope.

### 0.6 Neutraliser `unsafe-inline` + `unsafe-eval` dans la CSP
- **Pourquoi** : `inc/bootstrap.php:17` rend la CSP inefficace → toute XSS exécute.
- **Fichiers** : `inc/bootstrap.php` (générer un `$cspNonce = bin2hex(random_bytes(16))` par requête, publier dans header CSP `script-src 'self' 'nonce-{$nonce}' https:`), patcher toutes les balises `<script>` inline pour porter `nonce="<?= $cspNonce ?>"`.
- **Effort** : 3 jours (inventaire scripts inline + patch + régression).
- **Dépendances** : aucune.
- **Risque si non fait** : toute XSS = RCE dans le navigateur.
- **Métrique de succès** : scan OWASP ZAP "CSP unsafe-inline" = OK.

### 0.7 Audit historique git pour fuite de secrets
- **Pourquoi** : vérifier que `db_config*.php`, `openai_config.php`, `anthropic_config.php`, `ubiflow_credentials.local.php` n'ont jamais été commités.
- **Opérations** : `git log -p --all -- "*db_config*" "*openai_config*" "*anthropic_config*" "ubiflow_credentials.local.php"`. Si trouvés : rotation immédiate des clés/mots de passe + `git filter-repo` pour purger l'historique (prévenir force-push main à tout le monde).
- **Effort** : 1h audit + 1 jour si rotation nécessaire.
- **Dépendances** : aucune.
- **Risque si non fait** : clés exposées = coût financier (OpenAI/Anthropic) + accès BDD.
- **Métrique de succès** : commande grep retourne 0 ligne.

### 0.8 Finaliser migration TIERS via système `admin_migrations.php`
- **Pourquoi** : les scripts `migration_tiers_*.sql` du 19/04 ont été exécutés manuellement → pas dans la table `_migrations_applied` → risque de réexécution partielle ou d'oubli sur nouveau environnement.
- **Fichiers** : créer `inc/migrations/20260419_tiers_architecture.php`, `20260419_tiers_roles_seed.php`, `20260419_tiers_fk_legacy.php` qui exposent le SQL des 3 fichiers existants, avec garde idempotente.
- **Effort** : 2h.
- **Dépendances** : aucune.
- **Risque si non fait** : régression à la prochaine réinitialisation ou migration mal synchronisée prod/dev.
- **Métrique de succès** : `admin_migrations.php` liste les 3 migrations appliquées.

---

## PHASE 1 — QUICK WINS (ROI immédiat, 2-4 semaines, en parallèle des chantiers de phase 2)

### 1.1 Ajouter Strict-Transport-Security
- **Pourquoi** : absence de HSTS = downgrade HTTP possible.
- **Fichiers** : `inc/bootstrap.php` (`header('Strict-Transport-Security: max-age=31536000; includeSubDomains');` conditionnel sur HTTPS).
- **Effort** : 15 min.

### 1.2 Helper `img_tag()` avec lazy-loading et dimensions
- **Pourquoi** : `<img>` sans `width/height` = layout shift ; sans `loading="lazy"` = LCP dégradé.
- **Fichiers** : nouveau `inc/view_helpers.php::img_tag($src, $alt, $w, $h, $lazy=true)` ; remplacement progressif.
- **Effort** : 2h + migration sur 10 pages prioritaires (1 jour).
- **Métrique** : Lighthouse LCP < 2.5 s sur `bien_liste.php`.

### 1.3 Injecter JSON-LD RealEstateListing + Organization sur vitrine
- **Pourquoi** : 0 donnée structurée, rich snippets Google inaccessibles.
- **Fichiers** : `vitrine/index.php` (dans `<head>` pour agence, dans détail annonce pour bien).
- **Effort** : 1 jour.
- **Métrique** : Google Rich Results Test valide 100% sur 5 URL échantillons.

### 1.4 FULLTEXT + index dates manquants
- **Pourquoi** : recherche annonces = scan complet ; filtres sur périodes = slow.
- **Migration SQL** :
  ```sql
  ALTER TABLE annonces ADD FULLTEXT idx_ft_annonces (titre_seo, description);
  ALTER TABLE biens ADD FULLTEXT idx_ft_biens (designation, description);
  ALTER TABLE baux ADD INDEX idx_baux_date_fin (date_fin);
  ALTER TABLE mandats ADD INDEX idx_mandats_date_debut (date_debut);
  ALTER TABLE salaires ADD INDEX idx_salaires_date (date_salaire);
  ALTER TABLE rh_entretiens ADD INDEX idx_entretien_date (date_entretien);
  ```
- **Effort** : 2h (migration + régression recherche).
- **Métrique** : `EXPLAIN SELECT` sur recherche annonces = Index/FULLTEXT (pas ALL).

### 1.5 Ajouter `rel="next/prev"` et canonical sur pagination
- **Pourquoi** : duplicate content côté Google sur pages 2+.
- **Fichiers** : `bien_liste.php`, `bien_recherche.php`, listes annonces vitrine.
- **Effort** : 1h.

### 1.6 Ajouter `<main>` dans layouts
- **Pourquoi** : accessibilité + SEO.
- **Fichiers** : `inc/layout_maboximmo.php` (remplacer `<div class="mbi-layout-main">` par `<main class="mbi-layout-main">`), `inc/agency_layout_bottom.php` fermeture, autres.
- **Effort** : 30 min.

### 1.7 Mesurer hit-rate CacheManager
- **Pourquoi** : on ne sait pas si le cache sert à quelque chose.
- **Fichiers** : `inc/CacheManager.php` (ajouter rapport 24h via cron ou page `admin_cache_stats.php`).
- **Effort** : 2h.

### 1.8 Purger fichiers temporaires racine
- **Pourquoi** : hygiène, surface d'attaque.
- **Fichiers** : `public_html/_tmp_*`, `_debug_*`, `_backups/`, `_archive/` (déplacer hors webroot ou supprimer ; déjà git-ignorés).
- **Effort** : 30 min.

### 1.9 Finaliser la suppression de `bien_ajouter.php`
- **Pourquoi** : page condamnée selon mémoire projet, liens vestigiaux restants.
- **Fichiers** : grep `bien_ajouter.php` dans `public_html/` → repointer vers `bien_detail.php` (sans `?edit`), supprimer fichier.
- **Effort** : 1 jour (inventaire + patch + test).

### 1.10 Whitelist unique extensions/MIME via constante `UPLOAD_POLICY`
- **Pourquoi** : 25 endpoints codent leur propre whitelist → incohérence (20 Mo vs 15 Mo).
- **Fichiers** : nouveau `inc/upload_policy.php` avec `UPLOAD_POLICIES['photo']`, `UPLOAD_POLICIES['document']`, etc.
- **Effort** : 1h (adoption progressive via UploadManager en phase 2).

---

## PHASE 2 — STRUCTURATION COURT TERME (6-10 semaines)

### 2.1 Créer `inc/TenantScope.php` (middleware d'isolement tenant)
- **Pourquoi** : point unique d'application du filtre `id_societe` + `id_agence` ; fin des oublis.
- **API cible** :
  ```php
  TenantScope::boot();  // lit session
  TenantScope::societe(); // int
  TenantScope::agence(); // int|null
  TenantScope::owns($objetType, $id); // bool + 403 automatique
  TenantScope::whereClause($alias = ''); // "AND id_societe = :societe AND (id_agence = :agence OR :agence IS NULL)"
  ```
- **Fichiers impactés** : `inc/TenantScope.php` (nouveau), `inc/bootstrap.php` (bootstrap de TenantScope), refactor pilote sur 10 endpoints critiques de Phase 0.4.
- **Effort** : 1 semaine dev + 3 semaines refactor des 120 endpoints.
- **Dépendances** : Phase 0.
- **Métrique** : audit script = 100% endpoints api/ utilisent TenantScope.

### 2.2 Créer `inc/UploadManager.php`
- **Pourquoi** : supprimer duplication 25×, centraliser politique sécurité, coupler au cloisonnement tenant.
- **API cible** :
  ```php
  UploadManager::store($fileField, 'document', ['objet_type' => 'bien', 'id_objet' => $bienId]);
  // → stocke dans uploads/s_X/a_Y/document/... + enregistre dans documents_unifies
  UploadManager::serve($documentId); // endpoint file_serve.php
  ```
- **Fichiers** : nouveau `inc/UploadManager.php`, `inc/upload_policy.php`, `api/file_serve.php`, refactor 25 endpoints upload.
- **Effort** : 3 semaines.
- **Dépendances** : 2.1.
- **Métrique** : 0 `move_uploaded_file()` hors `inc/UploadManager.php` (grep).

### 2.3 Créer `inc/ApiResponse.php`
- **Pourquoi** : 74 endpoints répondent `{ok}`, 2 répondent `{success}`. Helper `api_helpers.php` écrit et ignoré.
- **API cible** :
  ```php
  ApiResponse::ok(['data' => $x])->send();
  ApiResponse::error('Message', 403)->send();
  ApiResponse::validation($errors)->send();
  ```
- **Fichiers** : remplacer `inc/api_helpers.php`, refactor 74 endpoints.
- **Effort** : 1 semaine.
- **Métrique** : 0 `json_encode` dans `api/*.php` hors `ApiResponse`.

### 2.4 Créer `inc/AuthMiddleware.php`
- **Pourquoi** : 220 `require auth.php` + `require_login()` dupliqués, risque d'oubli.
- **API cible** : entry-point unique dans `api/*.php` :
  ```php
  require_once __DIR__.'/../inc/AuthMiddleware.php';
  AuthMiddleware::requireLogin()->requireRole([1,2])->requireCsrf();
  ```
- **Effort** : 1 semaine.
- **Métrique** : 0 endpoint API sans appel AuthMiddleware.

### 2.5 `inc/MailerFactory.php`
- **Pourquoi** : 5 mailers redéfinissent SMTP.
- **Fichiers** : fusionner `inc/mailer_*.php` sous `MailerFactory::create('facture'|'tache'|...)` → instance PHPMailer préconfigurée + templates.
- **Effort** : 3 jours.

### 2.6 `inc/PdfGenerator.php` (classe de base TCPDF)
- **Pourquoi** : 6 PDF redéfinissent Header/Footer/branding.
- **Fichiers** : classe abstraite `BasePdf extends TCPDF` avec Header/Footer standard + logo ; refactor des 6 fichiers `agency_pdf_*.php`.
- **Effort** : 1 semaine.

### 2.7 `inc/FormValidator.php`
- **Pourquoi** : validation dupliquée 100×.
- **API cible** : fluent validator + règles déclaratives.
- **Effort** : 1 semaine.

### 2.8 Adoption systématique `AuditLog`
- **Pourquoi** : `inc/AuditLog::log()` écrit, utilisé ~5× ; 30 `error_log` sauvages.
- **Fichiers** : remplacer progressivement les `error_log` par `AuditLog::log()`.
- **Effort** : 1 semaine (parallèle aux refactors 2.2-2.6).

### 2.9 Durcir session (timeout + regeneration)
- **Pourquoi** : timeout 8h excessif.
- **Fichiers** : `inc/auth.php:8,42` → 60 min ; session_regenerate_id après élévation de privilèges.
- **Effort** : 2h.

### 2.10 Rotation CSRF token après action sensible
- **Pourquoi** : token réutilisé → si XSS, vol et replay.
- **Fichiers** : `inc/csrf.php` (ajouter `regenerate_after_action()`) ; appel dans endpoints sensibles.
- **Effort** : 1 jour.

### 2.11 Composer + autoloader PSR-4
- **Pourquoi** : prérequis à l'extensibilité ; permet d'avoir `vendor/` propre pour PHPMailer / TCPDF.
- **Fichiers** : `composer.json`, renommer `inc/*.php` en classes `MBI\Inc\*` autoloadées, requirer dans bootstrap.
- **Effort** : 2 semaines.

---

## PHASE 3 — CHANTIERS MOYEN TERME (10-20 semaines)

### 3.1 Phase 5 TIERS — bascule code `id_proprietaire` / `id_mandant` → `id_tiers`
- **Pourquoi** : état intermédiaire dangereux depuis 2026-04-19.
- **Découpage par sprint (1 sprint = 1 table + son code client)** :
  1. `biens` → `bien_detail.php`, `bien_ajouter*` (supprimé), `dashboard_proprietaire.php`, `arbitrage_*`, `api/bien_*`.
  2. `baux` → `agency_reunion_*`, `bail_*`.
  3. `mandats` → `agency_mandat*`, `mandat_*`.
  4. `crg_trimestres` → `upload_crg`, `bailleur_crg_audit`, `dashboard_proprietaire`.
  5. `documents` → `admin_documents`, `bailleur_documents`.
  6. `user_proprietaires` → déjà migré via `user_tiers`.
  7. `factures` → `agency_factures`, `agency_facture_form`.
  8. `reg_mandats` → `agency_registres`.
- **Puis** : `ALTER TABLE ... DROP FOREIGN KEY fk_legacy ... DROP COLUMN id_proprietaire` ; enfin `DROP TABLE proprietaires / mandants / agency_mandant`.
- **Effort** : 8 semaines (1 sem/table + cleanup).
- **Métrique** : `grep id_proprietaire public_html` = 0 ; tables legacy DROP.

### 3.2 Unification GED (pivot `documents_unifies`)
- **Pourquoi** : 7 tables documents, pivot `documents` à 9 FK nullable, pas de `id_societe`.
- **Plan** :
  1. Créer `documents_unifies` (`id`, `objet_type` ENUM, `id_objet` NOT NULL, `id_societe` NOT NULL, `id_agence`, `titre`, `chemin_fichier`, `mime_type`, `taille`, `version`, `created_at`, `deleted_at`, `meta` JSON).
  2. Script migration des 7 tables existantes → `documents_unifies`.
  3. Mettre à jour UploadManager pour écrire dans `documents_unifies`.
  4. Maintenir vues SQL `v_biens_documents`, `v_rh_documents` pour compatibilité lecture progressive.
  5. Retirer les tables anciennes après validation.
- **Effort** : 4 semaines.
- **Dépendances** : 2.2 UploadManager.

### 3.3 Content Hub villes
- **Pourquoi** : `seo_villes` existe, non exploitée, 0 landing page.
- **Plan** :
  1. Remplir `seo_villes` pour les 100 villes principales (descriptions, meta, image_hero).
  2. Créer `public_html/villes/{slug}.php` dynamique qui liste annonces de la ville.
  3. Ajouter au sitemap + maillage interne.
- **Effort** : 3 semaines.
- **Métrique** : 100+ URLs indexées sur `site:maboximmo.fr/villes/`.

### 3.4 Vitrine agences premium
- **Pourquoi** : vitrine actuelle minimale, potentiel SEO fort.
- **Plan** : refonte `vitrine/index.php` avec JSON-LD Organization + LocalBusiness + RealEstateListing par annonce, OG dynamiques (photo 1ère annonce), meta titre dynamique `{annonce} — {agence} — {ville}`, avis agence.
- **Effort** : 3 semaines.

### 3.5 Unification référentiels via vues SQL
- **Pourquoi** : chaos `base_*` / `societe_*` / standalone.
- **Plan** :
  - Vues : `v_types_bien_effective`, `v_vues_effective`, `v_chauffage_effective` qui font UNION `base_` + override `societe_`.
  - Le code applicatif lit uniquement les `v_*`.
  - Retirer `bien_vues`, `types_bien`, autres standalone non utilisés.
- **Effort** : 1 semaine.

### 3.6 Ajouter `deleted_at` universel
- **Pourquoi** : soft-delete par `actif=0` sans horodatage = pas d'audit destruction.
- **Plan** : ALTER TABLE sur les tables métier critiques (biens, annonces, baux, mandats, tiers, documents, immeubles) ajoutant `deleted_at DATETIME NULL` + trigger BEFORE UPDATE SET deleted_at si `actif` passe à 0.
- **Effort** : 1 semaine.

### 3.7 Minification + bundling CSS
- **Pourquoi** : 21 fichiers CSS chargés en parallèle.
- **Plan** : script PHP de bundling (concat + minify) produisant `assets/css/build/base.css`, `build/theme.css`, `build/layout.css` ; cache busting par hash.
- **Effort** : 1 semaine.

### 3.8 Tests PHPUnit socle
- **Pourquoi** : 0 test.
- **Couverture cible** :
  - `inc/TenantScope.php` — 90%.
  - `inc/UploadManager.php` — 85%.
  - `inc/AuthMiddleware.php` + `csrf.php` — 90%.
  - `inc/ApiResponse.php` — 90%.
  - `inc/FormValidator.php` — 85%.
  - Smoke tests sur 20 endpoints API critiques.
- **Effort** : 4 semaines.
- **CI** : GitHub Actions lint PHP + phpunit sur PR.

### 3.9 Purge inline-style top 5 pages
- **Pourquoi** : 3 745 occurrences `style="..."`, top 5 = 293 à elles seules.
- **Plan** : `rh_indemnite_km.php` (92), `rh_profil.php` (61), `rh_profil_prov.php` (61), `agency_reunion_tenir.php` (40), `rh_entretien_tenir.php` (39) → extraire dans `theme-rh.css` / `theme-agency.css`.
- **Effort** : 2 jours par page soit ~2 semaines.

---

## PHASE 4 — LONG TERME (6-12 mois)

### 4.1 Réorganisation par service
- **Pourquoi** : 218 fichiers PHP racine = impraticable.
- **Plan** : sous-dossiers `immo/`, `agency/`, `syndic/`, `rh/`, `admin/`, `extranet/`, `vitrine/`. Redirections 301 via `.htaccess` pour URLs legacy.
- **Effort** : 8 semaines, à découper par service.

### 4.2 Extranets Phase 4
- **Pourquoi** : promis dans la vision TIERS, non démarré.
- **Plan** : `user_tiers.type_lien = 'extranet_bailleur'` + pages dédiées + permissions strictes.
- **Effort** : 12 semaines pour les 4 extranets (bailleur, copro, locataire, prestataire).

### 4.3 Observabilité
- **Pourquoi** : pas d'APM, pas de vision temps réel.
- **Plan** : Sentry (ou équiv) pour erreurs PHP + JS ; dashboard ops minimal (hit-rate cache, nb requêtes/j, endpoints lents).
- **Effort** : 2 semaines.

### 4.4 PWA mobile
- **Pourquoi** : expérience mobile à améliorer (responsive partiel).
- **Effort** : 4 semaines.

### 4.5 Partitioning BDD
- **Pourquoi** : `search_logs`, `audit_log`, `annonces_versions` croîtront sans borne.
- **Plan** : `PARTITION BY RANGE (YEAR(date_creation))` + cron d'archivage.
- **Effort** : 2 semaines.

### 4.6 Content hub SEO avancé
- **Pourquoi** : au-delà des villes, développer guides / calculettes / blog.
- **Effort** : 8 semaines continues.

### 4.7 Email delivrability hardening
- **Pourquoi** : SPF/DKIM/DMARC à valider côté DNS Hostinger ; warmup IP si envois > 500/j.
- **Effort** : 1 semaine + monitoring continu.

### 4.8 Registre RGPD + politique de purge
- **Pourquoi** : pas de registre documenté, pas de purge automatique.
- **Plan** : `REGISTRE_TRAITEMENTS.md` à la racine ; cron purge `search_logs > 2 ans`, `audit_log > 5 ans`, `inbound_emails > 1 an`.
- **Effort** : 1 semaine.

---

## RÉCAPITULATIF DE SÉQUENCE

```
[Semaines 1-2]  Phase 0 (sécurité critique) — BLOQUANT
[Semaines 3-6]  Phase 1 (quick wins) ∥ début Phase 2.1-2.2 (TenantScope, UploadManager)
[Semaines 7-12] Phase 2 (services communs, Composer)
[Semaines 13-20] Phase 3.1 (TIERS code) + 3.2 (GED) + 3.5 (référentiels) + 3.6 (deleted_at)
[Semaines 21-26] Phase 3.3 (content hub villes) + 3.4 (vitrine premium) + 3.7 (CSS) + 3.8 (tests)
[Mois 7-12]     Phase 4 (réorg, extranets, APM, PWA, partitioning, registre RGPD)
```

## MÉTRIQUES GLOBALES DE SUIVI

| Métrique | Baseline 2026-04-20 | Cible Q3 2026 | Cible Q4 2026 |
|---|---|---|---|
| Score audit global | 57 / 100 | 72 | 85 |
| IDOR cross-tenant confirmés | 10 | 0 | 0 |
| Fichiers debug/test exposés | 2 | 0 | 0 |
| LOC dupliquées | ~2 500 | ~500 | <100 |
| Couverture tests (`inc/` critique) | 0% | 70% | 85% |
| Index FULLTEXT en place | 0 | 2 | 2 |
| Pages avec JSON-LD | 0 | 5 | 20 |
| Tables documents | 7 | 2 | 2 |
| Tables legacy (proprietaires/mandants/agency_mandant) | 3 | 0 | 0 |
| Inline styles racine | 3 745 | 2 000 | 500 |
| Lighthouse score vitrine (mobile) | n/a | 75 | 90 |

---

## RÈGLES D'EXÉCUTION

1. **Phase 0 = bloquante.** Aucune nouvelle feature avant la clôture.
2. **Chaque action porte sa métrique** : pas de sortie "fait" sans preuve mesurable.
3. **Un sprint = un commit granulaire** (voir règle mémoire `feedback_protection_code.md`).
4. **Tests sur dev uniquement** (`feedback_deploiement_dev_prod.md`), puis migration listée au moment du merge develop → main.
5. **Plan + validation avant code** (`feedback_expliquer_avant_coder.md`) — ce document tient lieu de plan pour les phases 0 à 2 ; validation EMERY attendue avant démarrage.
6. **Toute correction IDOR doit faire l'objet d'un test régression** avant fermeture.

---

## ZONES À CLARIFIER AVEC EMERY

- **Page de référence visuelle** : la mémoire désigne `v2/rh_dashboard.php` mais le dossier `v2/` n'existe pas. Quelle est la page réelle qui sert de standard V2 aujourd'hui ?
- **Ubiflow credentials** : noms d'agences exposés dans `config/ubiflow_credentials.example.php` — intentionnel ou à anonymiser ?
- **SMTP / DNS** : statut SPF / DKIM / DMARC côté Hostinger à confirmer.
- **Cookies RGPD** : bannière de consentement présente sur le site public ? Non vérifié dans l'audit.
- **Backups prod** : processus Hostinger documenté en interne ou à formaliser dans le repo ?
- **Registre des traitements** : existe-t-il en dehors du repo ou à créer ?
- **Phase 4 extranets** : priorité business (ouverture à partenaires) vs priorité technique (stabilisation) — quel tempo souhaité ?

---

**Fin du plan.** Ce document est un compagnon vivant : il doit être mis à jour à chaque fin de phase pour refléter l'état réel.
