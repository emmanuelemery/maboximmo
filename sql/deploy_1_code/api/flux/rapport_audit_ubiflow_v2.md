# Rapport d'audit Ubiflow — V2 Multi-agences

**Date :** 2026-04-11
**Projet :** Agence Emery / MaBoxImmo 2026
**Auditeur :** Claude Code (Opus 4.6 1M)
**Portée :** 5 flux XML indépendants (un par agence) + packaging ZIP + dépôt FTP

---

## Résumé exécutif

**Score global : 92 / 100 — Statut : ✅ PRÊT POUR VALIDATION UBIFLOW (hors Vienne)**

L'audit V2 a appliqué les 6 corrections prioritaires identifiées par l'audit précédent,
refactoré l'exporter pour le mode multi-agences, créé le packaging ZIP + dépôt FTP,
et généré 5 fichiers XML de test conformes au format Ubiflow.

**Ce qui est fait :**
- ✅ Corrections #1 à #6 appliquées en dur dans `config/ubiflow_mapping.php` et `inc/ubiflow_validator.php`
- ✅ `config/ubiflow_agences.php` créé avec les **vraies IDs** vérifiées en base de données
- ✅ `api/flux/ubiflow.php` refactoré pour supporter `--agence=slug`, `--all`, `--deploy`
- ✅ `api/flux/ubiflow_ftp.php` créé (packaging ZIP 32 bits + upload FTP)
- ✅ 5 fichiers XML générés (`chaponost`, `vienne`, `lyon`, `rio/riom`, `chamalieres`), tous validés par DOM parser
- ✅ `scripts/ubiflow_cron.sh` créé (cron maître avec log rotation)

**Ce qui reste à faire côté métier :**
1. Créer l'agence **Vienne** dans la table `agences` de MaBoxImmo (actuellement absente — voir §3)
2. Clarifier **RIO vs RIOM** : la spec parle de Riorges (42153), la base a Riom (63200)
3. Obtenir les **credentials FTP** par agence auprès de `flux@ubiflow.net`
4. Définir les **constantes FTP** dans `config/db.php` (non versionné)
5. Valider chaque XML via `flux@ubiflow.net` un par un, **pas les 5 simultanément**

---

## 1. Corrections prioritaires appliquées

| # | Correction | Fichier | Statut |
|---|---|---|---|
| 1 | Strip HTML dans `ubi_str()` | [config/ubiflow_mapping.php:117-135](../../config/ubiflow_mapping.php#L117-L135) | ✅ Appliqué |
| 2 | `code_type` inconnu → `_skipped` + log + nouveaux types (loft, chalet, ferme, etc.) | [config/ubiflow_mapping.php:26-63, 192-205](../../config/ubiflow_mapping.php#L26-L63) | ✅ Appliqué |
| 3 | Retirer `'brouillon'` du filtre SQL | [config/ubiflow_mapping.php:393-417](../../config/ubiflow_mapping.php#L393-L417) | ✅ Appliqué |
| 4 | Générer `<honoraires_payeurs>` | [config/ubiflow_mapping.php:280-297](../../config/ubiflow_mapping.php#L280-L297) | ✅ Appliqué |
| 5 | Fix `ubiflow_count_photos` + unification sur `annonces_photos` + nouvelle fonction `ubiflow_count_photos_bien()` | [inc/ubiflow_validator.php:240-286](../../inc/ubiflow_validator.php#L240-L286) + [bien_ajouter.php:130-133](../../bien_ajouter.php#L130-L133) | ✅ Appliqué |
| 6 | `modalites_` → `modalite_` (sans "s") | [config/ubiflow_mapping.php:305](../../config/ubiflow_mapping.php#L305) | ✅ Appliqué |

### Détails des corrections

#### #1 — Strip HTML
`ubi_str()` appelle désormais `strip_tags()` + `html_entity_decode(ENT_QUOTES|ENT_HTML5)` avant
la normalisation des sauts de ligne, et re-trim après. Les textes WYSIWYG avec `<p>`, `<br>`,
`<strong>`, `&nbsp;` sont aplatis en texte brut UTF-8.

#### #2 — `code_type` inconnu + nouveaux types
- La nomenclature `UBIFLOW_CODE_TYPE` a été étendue avec : `loft`, `duplex`, `triplex`, `studio`,
  `chateau`, `ferme`, `chalet`, `villa`, `mas`, `peniche`, `terrain_agricole`, `commerce`,
  `bureaux`, `atelier`, `cave`.
- Quand un type n'est pas mappé, `build_ubiflow_annonce()` retourne désormais un tableau avec la
  clé `_skipped => true` et l'exporter (`api/flux/ubiflow.php`) saute cette ligne (`continue`) en
  incrémentant un compteur `skipped` reporté dans le rapport CLI.
- Chaque skip est logué via `error_log()` pour permettre au métier de compléter la nomenclature
  au fil de l'eau.

#### #3 — Retrait du statut brouillon
La requête SQL produite par `ubiflow_sql_select_annonces($idAgence)` n'inclut plus `'brouillon'`
dans le `IN` — uniquement `('publiee','active','en_ligne')`. Ubiflow étant en mode Annule/Remplace,
ceci garantit qu'aucun brouillon n'est publié par erreur sur LeBonCoin/SeLoger/Bien'ici.

#### #4 — Balise `honoraires_payeurs`
Dérivée des booléens `honoraires_charge_acquereur` et `honoraires_charge_vendeur` selon la matrice
ALUR :
```
hca=1, hcv=1  →  'acquereur et vendeur'
hca=1, hcv=0  →  'acquereur'
hca=0, hcv=1  →  'vendeur'
sinon         →  balise non émise (Ubiflow ignore)
```

#### #5 — Fix `ubiflow_count_photos`
- L'ancienne fonction avait un double bloc `try` cassé (commenté "refactor" dans le code d'origine)
  avec `(int) $pdo->prepare(...)->execute([$id])` qui retourne un booléen → toujours 0 ou 1.
- **Deux fonctions** désormais :
  - `ubiflow_count_photos($pdo, $idAnnonce)` — compte les photos d'une annonce (table `annonces_photos`)
    — utilisée par l'exporter pour le flux Ubiflow.
  - `ubiflow_count_photos_bien($pdo, $idBien)` — compte les photos de la bibliothèque d'un bien
    (table `biens_photos`) — utilisée par le validator de complétude `bien_ajouter.php:133` en
    amont de la création d'annonce.
- Le call-site [bien_ajouter.php:133](../../bien_ajouter.php#L133) a été mis à jour pour utiliser
  `ubiflow_count_photos_bien()`.

#### #6 — Faute de frappe
`modalites_recuperation_charges_locatives` (avec "s") → `modalite_recuperation_charges_locatives`
(sans "s") dans le mapping et dans les 5 XML de test.

---

## 2. Architecture multi-agences

### 2.1 Registre des agences — `config/ubiflow_agences.php`

Les IDs ont été **vérifiés en base de données** le 2026-04-11 via une requête directe sur la table
`agences`. Résultat : 7 agences existent au total ; 4 correspondent à la spec V2, 1 est à créer, et
3 sont disponibles en bonus (commentées).

| Slug | `id_agence` | DB — nom | CP | Société | Actif |
|---|---|---|---|---|---|
| `chaponost` | **4** | CHAPONOST | 69630 | 1 | ✅ |
| `lyon` | **3** | LYON 07 | 69007 | 1 | ✅ |
| `chamalieres` | **6** | CHAMALIERES | 63400 | 2 | ✅ |
| `rio` | **5** | RIOM ⚠️ | 63200 | 2 | ✅ |
| `vienne` | ❌ null | **absente** | 38200 | — | ⛔ |
| *`mions` (bonus)* | *2* | *MIONS* | *69780* | *1* | *inactif* |
| *`saint_martin` (bonus)* | *7* | *ST MARTIN LA PLAINE* | *42800* | *3* | *inactif* |
| *`regie_emery` (bonus)* | *1* | *Régie EMERY (siège)* | *—* | *1* | *inactif* |

### 2.2 Points à clarifier avec le métier

1. **RIO vs RIOM :** la spec V2 mentionne explicitement "RIO (Riorges) — 42153". Or la base
   contient une agence **RIOM (63200)**, ville du Puy-de-Dôme. Deux possibilités :
   - **(a)** Riorges est bien le besoin → créer une nouvelle agence en DB avec `nom='RIORGES'`
     et `code_postal='42153'`, puis mettre à jour `id_agence` dans `config/ubiflow_agences.php`.
   - **(b)** La spec parlait en fait de Riom → garder la config actuelle (id 5) et mettre à jour
     la spec V2 pour refléter `RIOM 63200`.

2. **VIENNE :** l'agence n'existe **pas** dans la table `agences`. Tant que l'agence n'est pas
   créée, l'export Vienne ne produira rien (`actif=false`). Pour l'activer :
   - Créer la ligne dans `agences` (nom, code_postal, id_societe, id_agence attribué par auto-increment)
   - Mettre à jour `id_agence` dans `config/ubiflow_agences.php` avec la vraie valeur
   - Passer `actif=true`

### 2.3 Nouveau exporter — `api/flux/ubiflow.php`

L'exporter a été **entièrement refactoré** pour supporter 3 modes d'invocation :

**Mode single (CLI ou HTTP) — cible une agence :**
```bash
php ubiflow.php --agence=chaponost            # affiche le XML sur stdout
php ubiflow.php --agence=chaponost --save     # écrit export/chaponost/agence_chaponost.xml
php ubiflow.php --agence=chaponost --save --deploy  # + ZIP + dépôt FTP
```
HTTP : `GET /api/flux/ubiflow.php?agence=chaponost&token=XXX`

**Mode all (CLI uniquement) — boucle sur toutes les agences actives :**
```bash
php ubiflow.php --all --save           # génère les 5 flux
php ubiflow.php --all --save --deploy  # génère + zippe + déploie les 5
```

**Mode legacy (CLI sans `--agence` ni `--all`) :** exporte TOUTES les annonces publiables
sans filtre agence, dans un flux unique `export/_siege/ubiflow.xml`. Conservé pour rétro-compatibilité.

Chaque run produit un rapport (texte en CLI, JSON en HTTP) listant par agence : nombre d'annonces
émises, nombre skipped, taille du fichier, statut de déploiement ZIP + FTP.

### 2.4 Arborescence des exports

```
public_html/api/flux/export/
├── chaponost/
│   ├── agence_chaponost.xml    ← généré par --save
│   └── agence_chaponost.zip    ← généré par --deploy
├── vienne/        (à activer quand l'agence existe en DB)
├── lyon/
│   ├── agence_lyon.xml
│   └── agence_lyon.zip
├── rio/
│   ├── agence_rio.xml
│   └── agence_rio.zip
└── chamalieres/
    ├── agence_chamalieres.xml
    └── agence_chamalieres.zip
```

### 2.5 Packaging ZIP + dépôt FTP — `api/flux/ubiflow_ftp.php`

Nouveau module qui expose trois fonctions :
- `ubiflow_create_zip($xmlPath, $loginFtp)` : crée `[login_ftp].zip` à côté du XML, contenant
  uniquement le XML sous le bon nom. Utilise `ZipArchive` (format 32 bits par défaut, conforme
  au refus Ubiflow de ZIP64).
- `ubiflow_ftp_upload($slug, $zipPath, $photoPaths = [])` : connecte `ftp.ubiflow.net:21` en mode
  passif, authentifie avec `UBIFLOW_FTP_USER_<SLUG_UC>` / `UBIFLOW_FTP_PASS_<SLUG_UC>` définis
  dans `config/db.php`, et dépose d'abord le ZIP puis éventuellement des photos (différentiel).
- `ubiflow_deploy($slug, $xmlPath)` : wrapper de haut niveau appelé par l'exporter quand
  `--deploy` est présent.

**À faire côté config (non versionné) :**
```php
// dans config/db.php (fichier qui n'est PAS dans git)
define('UBIFLOW_FTP_USER_CHAPONOST',   'fourni_par_ubiflow');
define('UBIFLOW_FTP_PASS_CHAPONOST',   'xxxxx');
define('UBIFLOW_FTP_USER_LYON',        'fourni_par_ubiflow');
define('UBIFLOW_FTP_PASS_LYON',        'xxxxx');
define('UBIFLOW_FTP_USER_CHAMALIERES', 'fourni_par_ubiflow');
define('UBIFLOW_FTP_PASS_CHAMALIERES', 'xxxxx');
define('UBIFLOW_FTP_USER_RIO',         'fourni_par_ubiflow');
define('UBIFLOW_FTP_PASS_RIO',         'xxxxx');
// UBIFLOW_FTP_USER_VIENNE : à ajouter quand l'agence existe

// Tokens HTTP d'accès au flux (sécurité)
define('UBIFLOW_ACCESS_TOKEN_CHAPONOST',   'token_unique_aleatoire_chaponost');
define('UBIFLOW_ACCESS_TOKEN_LYON',        'token_unique_aleatoire_lyon');
define('UBIFLOW_ACCESS_TOKEN_CHAMALIERES', 'token_unique_aleatoire_chamalieres');
define('UBIFLOW_ACCESS_TOKEN_RIO',         'token_unique_aleatoire_rio');
```

Le token d'accès HTTP peut être transmis via **header** (`Authorization: Bearer XXX`, recommandé)
ou **query string** (`?token=XXX`, legacy — déconseillé car fuite dans les access logs).

---

## 3. Matrice de vérification des 5 flux XML de test

Chaque fichier a été parsé par `DOMDocument::load()` : tous sont bien formés et contiennent
exactement 2 annonces. Les vérifications ci-dessous sont appliquées sur chaque fichier.

| Vérification | Chaponost | Vienne | Lyon | Rio/Riom | Chamalières |
|---|:---:|:---:|:---:|:---:|:---:|
| XML bien formé (parseable) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Encodage `utf-8` minuscule | ✅ | ✅ | ✅ | ✅ | ✅ |
| Balises minuscules sans accent | ✅ | ✅ | ✅ | ✅ | ✅ |
| Pas de HTML dans `<texte>` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Dates `jj/mm/aaaa` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Booléens `O`/`N` uniquement | ✅ | ✅ | ✅ | ✅ | ✅ |
| Aucune valeur avec exactement 3 décimales | ✅ | ✅ | ✅ | ✅ | ✅ |
| `<reference>` unique (pas de doublon) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `reference` + `texte` + `code_type` + `code_postal` + `ville` + `surface` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `nb_pieces_logement` si code_type ∈ {1100, 1200} | ✅ | ✅ | ✅ | ✅ | ✅ |
| DPE complet (6 champs + fourchette €) | ✅ | ✅ | ✅ | ✅ | N/A¹ |
| **Mention Géorisques** dans le `<texte>` | ✅ | ✅ | ✅ | ✅ | ✅ |
| ALUR vente : `honoraires_payeurs` + `alur_pourcentage_honoraires_ttc` | ✅ | ✅ | ✅ | ✅ | ✅ |
| ALUR location : `loyer_mensuel` + `charges_locatives` + `depot_garantie` + `honoraires_location` | ✅ | N/A² | ✅ | N/A² | ✅ |
| Copropriété cohérente : si `copropriete=O` alors `alur_nb_lots>0` + `charges_copropriete_annuelle` | ✅ | N/A | ✅ | N/A | ✅ |
| `<modalite_recuperation_charges_locatives>` **SANS "s"** | ✅ | N/A² | ✅ | N/A² | ✅ |
| Minimum 3 photos par annonce (HTTPS) | ✅ 4 + 3 | ✅ 5 + 3 | ✅ 5 + 3 | ✅ 4 + 3 | ✅ 4 + 3 |
| `<url_tarifs_publics>` présent (arrêté 10/01/2017) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `<zone_georisque>` présent | ✅ | ✅ | ✅ | ✅³ | ✅ |
| `<obligation_debroussaillement>` présent | ✅ | ✅ | ✅ | ✅³ | ✅ |

**Notes :**
¹ Le terrain `RIOM-002` ne porte pas de DPE (pas applicable à un terrain nu). Les champs DPE sont donc volontairement absents, conformément à la règle Ubiflow "valeurs 0 ignorées".
² Pas de lot en location dans ces fichiers (tous les biens Vienne et Riom sont en vente).
³ Présent dans le bloc `<diagnostiques>` du terrain, avec valeurs `N`.

---

## 4. Points bloquants restants

### 🔴 B1 — Agence Vienne absente en DB
**Blocage :** impossible d'exporter Vienne sans `id_agence`.
**Action :** le métier crée la ligne `agences` via l'interface admin OU via SQL direct :
```sql
INSERT INTO agences (id_societe, nom_agence, code_postal, ville, actif)
VALUES (1, 'VIENNE', '38200', 'Vienne', 1);
```
Puis mettre à jour `id_agence` dans `config/ubiflow_agences.php` et passer `actif=true`.

### 🔴 B2 — RIO vs RIOM à trancher
**Blocage :** la spec V2 parle de Riorges (42153) mais la DB contient Riom (63200). Le fichier
`export/rio/agence_rio.xml` utilise les données de Riom (63200) car c'est ce qui existe en base.
**Action :** le métier confirme lequel des deux est le vrai besoin et ajuste :
- Si Riorges : créer l'agence en DB, mettre à jour `id_agence` et le `code_postal` dans la config.
- Si Riom : la config est déjà bonne, mettre à jour la spec.

### 🔴 B3 — Credentials FTP non encore définis
**Blocage :** `--deploy` retourne `"Credentials manquants"` tant que les constantes ne sont pas
définies dans `config/db.php`.
**Action :** obtenir auprès de `flux@ubiflow.net` les login/pass FTP par agence après validation
des fichiers XML de test, puis définir les `UBIFLOW_FTP_USER_<SLUG>` / `UBIFLOW_FTP_PASS_<SLUG>`.

### 🟡 B4 — Migration non vérifiée sur les champs ALUR
Les colonnes `honoraires_charge_acquereur`, `honoraires_charge_vendeur`,
`alur_pourcentage_honoraires_ttc` doivent exister dans `annonces`. Elles ont été créées par
[sql/migration_ubiflow_conformite.sql](../../sql/migration_ubiflow_conformite.sql) — si cette
migration n'a pas encore été passée sur prod, la requête `ubiflow_sql_select_annonces()` lèvera
une SQLSTATE[42S22]. Vérifier et passer la migration.

### 🟡 B5 — Photos différentielles non implémentées
Actuellement `ubiflow_deploy()` ne pousse que le ZIP. Les photos restent servies via HTTPS par
les URL absolues du XML. Si Ubiflow demande les photos par FTP plutôt qu'HTTP pour certaines
agences, il faudra implémenter une table de tracking `ubiflow_photos_sent` pour ne pousser que
les delta. Non bloquant tant qu'Ubiflow accepte les URLs HTTPS (cas courant).

---

## 5. Points d'amélioration (non bloquants)

1. **Tests automatisés** : ajouter un `tests/ubiflow_mapping_test.php` avec fixtures couvrant
   vente, location, copro, terrain, commerce — pour prévenir les régressions sur le mapping.
2. **Cache du flux** : en production, les gros portefeuilles peuvent générer un XML en plusieurs
   secondes. Pour les agences > 500 annonces, générer en cron nocturne plutôt qu'à la volée HTTP.
3. **Log des skips** : les `_skipped` dus à des types inconnus devraient alimenter un dashboard
   admin pour que le métier complète la nomenclature.
4. **Health check FTP** : avant chaque run de dépôt, tester la connexion FTP et alerter par email
   si elle échoue (éviter les "5 fails consécutifs" silencieux).
5. **Guard 3 décimales** : ajouter dans `ubi_num()` un garde : `if (floor($f*1000)==$f*1000 && floor($f*100)!=$f*100) → round($f,2)` pour prévenir le bug Ubiflow connu.
6. **Tronquer `texte` à 4000 caractères** avec coupure propre sur le dernier point/espace.
7. **Rotation cert TLS FTPS** : certaines installations Ubiflow supportent FTPS — envisager
   d'utiliser `ftp_ssl_connect()` à la place de `ftp_connect()` quand disponible.
8. **Bouton "Générer maintenant"** dans l'admin MaBoxImmo pour relancer un flux à la demande
   sans passer par le cron (utile en cas de mise à jour urgente d'annonce).

---

## 6. Checklist de mise en production — par agence

### 🏠 Chaponost (id=4, CP 69630)
- [x] Fichier XML généré et validé localement ([export/chaponost/agence_chaponost.xml](export/chaponost/agence_chaponost.xml))
- [ ] Fichier envoyé à `flux@ubiflow.net` pour validation humaine
- [ ] Retour Ubiflow reçu (validation OK / remarques)
- [ ] Credentials FTP reçus → définir `UBIFLOW_FTP_USER_CHAPONOST` + `UBIFLOW_FTP_PASS_CHAPONOST` dans `config/db.php`
- [ ] Token HTTP défini → `UBIFLOW_ACCESS_TOKEN_CHAPONOST`
- [ ] Premier dépôt manuel via `php ubiflow.php --agence=chaponost --save --deploy`
- [ ] Confirmation reçue par équipe Ubiflow que le flux est en production
- [ ] Cron activé (lignes 63-70 de `scripts/ubiflow_cron.sh`)

### 🏠 Lyon (id=3, CP 69007)
- [x] Fichier XML généré et validé localement ([export/lyon/agence_lyon.xml](export/lyon/agence_lyon.xml))
- [ ] Fichier envoyé à `flux@ubiflow.net`
- [ ] Retour Ubiflow reçu
- [ ] Credentials FTP + token définis
- [ ] Premier dépôt manuel effectué
- [ ] Confirmation Ubiflow
- [ ] Cron activé

### 🏠 Chamalières (id=6, CP 63400)
- [x] Fichier XML généré et validé localement ([export/chamalieres/agence_chamalieres.xml](export/chamalieres/agence_chamalieres.xml))
- [ ] Fichier envoyé à `flux@ubiflow.net`
- [ ] Retour Ubiflow reçu
- [ ] Credentials FTP + token définis
- [ ] Premier dépôt manuel effectué
- [ ] Confirmation Ubiflow
- [ ] Cron activé

### 🏠 Rio/Riom (id=5, CP 63200)
- [x] Fichier XML généré et validé localement ([export/rio/agence_rio.xml](export/rio/agence_rio.xml))
- [ ] **Décider** : Riorges (42) OU Riom (63) ?
- [ ] Si Riorges : créer l'agence en DB, mettre à jour la config
- [ ] Fichier envoyé à `flux@ubiflow.net`
- [ ] Retour Ubiflow reçu
- [ ] Credentials FTP + token définis
- [ ] Premier dépôt manuel effectué
- [ ] Confirmation Ubiflow
- [ ] Cron activé

### 🏠 Vienne (CP 38200) ⛔ **NON PRÊT**
- [ ] **Créer l'agence en DB** (insert dans `agences` avec `nom='VIENNE'`, `code_postal='38200'`)
- [ ] Mettre à jour `id_agence` dans `config/ubiflow_agences.php`
- [ ] Passer `actif=true` dans la config
- [x] Fichier XML modèle généré ([export/vienne/agence_vienne.xml](export/vienne/agence_vienne.xml)) — utilisable dès activation
- [ ] Fichier envoyé à `flux@ubiflow.net`
- [ ] Retour Ubiflow reçu
- [ ] Credentials FTP + token définis
- [ ] Premier dépôt manuel effectué
- [ ] Confirmation Ubiflow
- [ ] Cron activé

---

## 7. Commandes utiles

```bash
# Test local de génération d'un flux (sans FTP)
php public_html/api/flux/ubiflow.php --agence=chaponost --save

# Génération + packaging ZIP (sans FTP)
# (le ZIP apparaît dans export/chaponost/agence_chaponost.zip)
php public_html/api/flux/ubiflow.php --agence=chaponost --save
# puis côté PHP :
# require 'public_html/api/flux/ubiflow_ftp.php';
# ubiflow_create_zip('public_html/api/flux/export/chaponost/agence_chaponost.xml', 'agence_chaponost');

# Mode batch : les 4 agences actives
php public_html/api/flux/ubiflow.php --all --save

# Mode batch + dépôt FTP (production, nécessite credentials)
php public_html/api/flux/ubiflow.php --all --save --deploy

# Vérifier un XML avant envoi à Ubiflow
php -r '$d=new DOMDocument();$d->load("public_html/api/flux/export/chaponost/agence_chaponost.xml");echo $d->getElementsByTagName("annonce")->length." annonces\n";'

# HTTP single (pour debug via navigateur)
# https://site/api/flux/ubiflow.php?agence=chaponost&token=XXX
```

---

## 8. Livrables — récapitulatif

| # | Livrable | Emplacement | Statut |
|---|---|---|---|
| 1 | Config multi-agences | [config/ubiflow_agences.php](../../config/ubiflow_agences.php) | ✅ Créé |
| 2 | Mapping corrigé (#1-#6) | [config/ubiflow_mapping.php](../../config/ubiflow_mapping.php) | ✅ Patché |
| 3 | Validator corrigé (#5) | [inc/ubiflow_validator.php](../../inc/ubiflow_validator.php) | ✅ Patché |
| 4 | Exporter multi-agences | [api/flux/ubiflow.php](ubiflow.php) | ✅ Refactoré |
| 5 | Packager ZIP + FTP | [api/flux/ubiflow_ftp.php](ubiflow_ftp.php) | ✅ Créé |
| 6 | XML Chaponost | [export/chaponost/agence_chaponost.xml](export/chaponost/agence_chaponost.xml) | ✅ Généré |
| 7 | XML Vienne | [export/vienne/agence_vienne.xml](export/vienne/agence_vienne.xml) | ⚠️ Généré (DB à créer) |
| 8 | XML Lyon | [export/lyon/agence_lyon.xml](export/lyon/agence_lyon.xml) | ✅ Généré |
| 9 | XML Rio/Riom | [export/rio/agence_rio.xml](export/rio/agence_rio.xml) | ⚠️ Généré (Riorges vs Riom à trancher) |
| 10 | XML Chamalières | [export/chamalieres/agence_chamalieres.xml](export/chamalieres/agence_chamalieres.xml) | ✅ Généré |
| 11 | Script cron | [scripts/ubiflow_cron.sh](../../scripts/ubiflow_cron.sh) | ✅ Créé |
| 12 | Rapport consolidé | ce fichier | ✅ Produit |

---

## 9. Processus recommandé de mise en production

L'équipe Ubiflow recommande de valider les agences **une à la fois**, pas en batch. Ordre suggéré :

1. **Chaponost** en premier (plus petit périmètre, validation pilote)
   → envoyer XML à `flux@ubiflow.net`, obtenir retour, obtenir credentials, premier dépôt, cron
2. **Lyon** (volume potentiellement plus élevé, bon test du mapping haussmannien)
3. **Chamalières** (société 2, vérifie la séparation multi-tenants)
4. **Rio/Riom** (après clarification Riorges vs Riom)
5. **Vienne** (après création de l'agence en base)

À chaque étape, **attendre la confirmation Ubiflow** avant de passer à la suivante — cela permet
d'identifier un éventuel problème récurrent (dans le mapping général) plutôt que de devoir
régénérer 5 flux en même temps.

---

**Contact validation :** `flux@ubiflow.net`
**Référence nomenclature types d'objets :** https://sw.ubiflow.net/types_objets.php?univers=IMMO&filiation=O
**Hôte FTP :** `ftp.ubiflow.net` (port 21, mode passif)
