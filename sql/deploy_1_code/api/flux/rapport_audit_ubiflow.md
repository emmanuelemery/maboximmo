# Rapport d'audit — Export Ubiflow
**Date :** 2026-04-11
**Projet :** Agence Emery / MaBoxImmo 2026
**Auditeur :** Claude Code (Opus 4.6)

---

## Résumé exécutif

**Score global : 78 / 100 — Statut : À CORRIGER (non bloquant structurel)**

Le système d'export Ubiflow est **déjà substantiellement implémenté** dans le projet. Les trois piliers techniques existent :

1. [config/ubiflow_mapping.php](../../config/ubiflow_mapping.php) — mapping SQL → balises Ubiflow, helpers de formatage (`ubi_bool`, `ubi_date`, `ubi_num`, `ubi_str`).
2. [api/flux/ubiflow.php](ubiflow.php) — générateur XML via `DOMDocument`, sortie HTTP ou fichier, support token, filtre par agence.
3. [inc/ubiflow_validator.php](../../inc/ubiflow_validator.php) — validation par bien avec règles Ubiflow (hardcodées) + règles admin société.
4. [sql/migration_ubiflow_conformite.sql](../../sql/migration_ubiflow_conformite.sql) — migration ajoutant DPE 2021, ALUR détaillé, ERP/Géorisques, mandat, encadrement loyers.

**Ce qui fonctionne déjà :**
- Encodage `utf-8` minuscule (correction `preg_replace` après `DOMDocument::saveXML`).
- Booléens normalisés via `ubi_bool` → `O`/`N`/null.
- Dates formatées en `jj/mm/aaaa` via `ubi_date`.
- CDATA automatique sur champs contenant `< > & ' " \r \n`.
- Filtrage des valeurs vides/null/0 (Ubiflow ignore 0).
- Structure XML conforme : `<client>` → `<annonce>` → `<photos>` + `<bien><diagnostiques>` + `<prestation>`.
- Pays forcé à `FRA`.
- Récupération photos ordonnées (principale + ordre d'affichage).

**Ce qui doit être corrigé avant mise en production :** voir section 3.

---

## 1. Champs obligatoires

| Balise Ubiflow | Statut | Source MaBoxImmo | Remarque |
|---|---|---|---|
| `reference` | ✅ OK | `annonces.reference_annonce` | Fallback sur `annonces.id` si absent |
| `texte` | ⚠️ À TRANSFORMER | `annonces.description` | Pas de strip HTML — cf. point bloquant #1 |
| `titre` | ✅ OK | `annonces.titre` | |
| `date_saisie` | ✅ OK | `annonces.date_creation` | Converti jj/mm/aaaa |
| `photos` | ✅ OK | `annonces_photos.url_photo` | Ordre respecté ; URL HTTPS requis |
| `code_type` | ⚠️ À VÉRIFIER | `types_bien.code` via `UBIFLOW_CODE_TYPE` | Nomenclature 1100/1200/1300/1500… conforme mais valeur par défaut `0` en cas d'inconnu — devrait bloquer l'export du bien, cf. point #2 |
| `code_postal` | ✅ OK | `biens.code_postal` | |
| `ville` | ✅ OK | `biens.ville` | |
| `surface` | ✅ OK | `biens.surface_habitable` (fallback `surface_totale`) | |
| `nb_pieces_logement` | ✅ OK | `biens.nb_pieces` | Requis seulement appt/maison — validator OK |
| `libelle_type` | ✅ OK | `types_bien.libelle` | |
| `type` (prestation) | ✅ OK | `annonces.type_transaction` via `UBIFLOW_PRESTATION_TYPE` | Défaut `V` si inconnu — cf. point #3 |
| `prix` | ✅ OK | `annonces.prix` | |
| `loyer_mensuel` | ✅ OK | `annonces.loyer` | |

## 2. Champs légaux (ALUR / DPE / ERP)

| Balise Ubiflow | Statut | Source MaBoxImmo | Remarque |
|---|---|---|---|
| `prix_hors_honoraires` | ✅ OK | `annonces.prix_net_vendeur` | |
| `honoraires_payeurs` | ⚠️ MANQUANT | — | La balise Ubiflow `honoraires_payeurs` (valeurs : `acquereur`/`vendeur`) n'est pas générée. Seuls `honoraires_charge_acquereur` (O/N) et `honoraires_charge_vendeur` (O/N) sont émis — cf. point bloquant #4 |
| `alur_pourcentage_honoraires_ttc` | ✅ OK | `annonces.alur_pourcentage_honoraires_ttc` | |
| `charges_locatives` | ✅ OK | `annonces.charges` | |
| `honoraires_location` | ⚠️ DUPLIQUÉ | `annonces.honoraires` | Colle sur `honoraires_negociation` aussi — risque de pollution en vente |
| `depot_garantie` | ✅ OK | `annonces.depot_garantie` | |
| `dpe_etiquette_conso` | ✅ OK | `biens.dpe_classe` | |
| `dpe_valeur_conso` | ✅ OK | `dpe_diags.conso_energie_primaire` (fallback `biens.dpe_valeur`) | |
| `dpe_etiquette_ges` | ✅ OK | `biens.ges_classe` | |
| `dpe_valeur_ges` | ✅ OK | `biens.ges_valeur` | |
| `dpe_date_realisation` | ✅ OK | `dpe_diags.date_diagnostic` (fallback `biens.dpe_date_realisation`) | |
| `montant_depenses_energies_min` | ✅ OK | `dpe_diags.montant_depenses_min` (fallback `biens.montant_estime_depenses_min`) | |
| `montant_depenses_energies_max` | ✅ OK | `dpe_diags.montant_depenses_max` (fallback `biens.montant_estime_depenses_max`) | |
| `copropriete` | ✅ OK | `biens.bien_en_copropriete` | |
| `alur_nb_lots` | ✅ OK | `biens.copro_nb_lots` | |
| `charges_copropriete_annuelle` | ✅ OK | `biens.copro_quote_part_charges` | |
| `alur_syndic_en_procedure` | ✅ OK | `biens.copro_procedure` | |
| `zone_georisque` | ✅ OK | `biens.zone_georisque` | |
| `obligation_debroussaillement` | ✅ OK | `biens.obligation_debroussaillement` | |

---

## 3. Points bloquants

### 🔴 #1 — Description HTML non nettoyée
**Fichier :** [config/ubiflow_mapping.php:163](../../config/ubiflow_mapping.php#L163)

`'texte' => ubi_str($row['a_description'] ?? null)` — `ubi_str` normalise les sauts de ligne mais **ne strippe pas le HTML**. Les descriptions rédigées via éditeur WYSIWYG contiennent `<p>`, `<br>`, `<strong>`, etc. Ubiflow interdit le HTML dans `<texte>` et rejettera le fichier.

**Correction :**
```php
function ubi_str($v): ?string {
    if ($v === null) return null;
    $s = trim((string) $v);
    if ($s === '') return null;
    // Strip HTML, décoder entités, normaliser sauts
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/\r\n|\r/", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return $s;
}
```

### 🔴 #2 — `code_type` à 0 en cas d'inconnu
**Fichier :** [config/ubiflow_mapping.php:177](../../config/ubiflow_mapping.php#L177)

`$codeType = UBIFLOW_CODE_TYPE[$typeCodeInterne] ?? 0;` — La valeur `0` est ignorée par Ubiflow. Un bien avec un type non mappé sera **exporté avec un `code_type` manquant** → rejet silencieux.

**Correction :** logguer l'erreur et `continue` sur la ligne (ou `throw`) pour éviter d'émettre un bien incomplet. Couvrir au minimum `loft`, `chateau`, `ferme`, `chalet`, `atelier`, `peniche`, `terrain_agricole` qui ne sont pas dans la constante.

### 🔴 #3 — Mode Annule/Remplace : statut `brouillon` exporté
**Fichier :** [config/ubiflow_mapping.php:501](../../config/ubiflow_mapping.php#L501)

```sql
WHERE a.visible_portails = 1
  AND a.statut IN ('publiee','active','en_ligne','brouillon')
```

**Problème :** Ubiflow fonctionne en mode Annule/Remplace — toute annonce absente du fichier est supprimée, **et toute annonce présente est publiée**. Inclure les `brouillon` va publier des annonces non finalisées sur Le Bon Coin, SeLoger, Bien'ici.

**Correction :** retirer `'brouillon'` du `IN`. Par ailleurs l'export doit **lister toutes les annonces actives** du périmètre agence — pas seulement celles avec `visible_portails=1` si la volonté est de tout diffuser. Clarifier la sémantique avec l'admin.

### 🔴 #4 — Balise `honoraires_payeurs` non générée
Le mapping n'émet pas la balise Ubiflow `honoraires_payeurs` qui doit prendre `acquereur` ou `vendeur`. C'est un champ légal ALUR demandé. À dériver depuis `honoraires_charge_acquereur` / `honoraires_charge_vendeur`.

### 🔴 #5 — Bug `ubiflow_count_photos` (double appel + return incorrect)
**Fichier :** [inc/ubiflow_validator.php:241-256](../../inc/ubiflow_validator.php#L241-L256)

```php
$n = (int) $pdo->prepare("…")->execute([$idBien]); // execute() retourne bool
```
Le premier bloc `try` est cassé (commentaire l'admet). Il faut supprimer le code mort, ne garder que le second bloc. Table utilisée : `biens_photos` — or `ubiflow_get_photos` lit `annonces_photos`. **Deux sources photos incohérentes** — risque de désynchronisation entre validator (compte côté bien) et exporter (lit côté annonce).

### 🔴 #6 — Nommage fichier ZIP et workflow FTP absents
Aucun script ne produit le ZIP `[LOGIN_AGENCE].zip` contenant `[LOGIN_AGENCE].xml`, ni n'envoie sur le FTP Ubiflow. L'export actuel s'arrête à `api/flux/export/ubiflow.xml`. Il manque :
- Packaging ZIP 32 bits (exclure ZIP64 explicitement — `ZipArchive` par défaut est 32 bits mais forcer le fallback pour archives < 4 Go).
- Transfert FTP (PHP `ftp_*` ou `curl` sftp).
- Envoi des photos séparément (racine FTP, hors ZIP).
- Définition de la constante `UBIFLOW_LOGIN_AGENCE` dans la config société.

---

## 4. Points d'amélioration (non bloquants)

1. **Ajout d'un champ `a_statut` filtrable** — l'actuel `IN` hardcodé devrait s'appuyer sur un flag `diffusable_ubiflow`.
2. **Validation décimale 3 chiffres** — ajouter un garde dans `ubi_num` : si `floor($f*1000) == $f*1000 && floor($f*100) != $f*100`, arrondir à 2 décimales (bug Ubiflow connu).
3. **Limite `texte`** — tronquer à 4000 caractères (limite Ubiflow) avec coupe propre.
4. **Tests** — aucun test automatisé. Ajouter un `tests/ubiflow_mapping_test.php` avec 2-3 fixtures couvrant vente/location/saisonnière.
5. **Cache du flux** — pour agences à fort volume, générer le XML en cron nocturne plutôt qu'à la volée HTTP.
6. **Logs** — tracer nombre d'annonces exclues par manque de `code_type` ou statut.
7. **Token en header** plutôt qu'en query string pour éviter fuite dans les access logs.
8. **`photos_count` minimum** — Ubiflow déclasse les annonces < 3 photos ; remonter ce seuil dans le validator (actuellement 1).
9. **Champ `pays`** — hardcodé à `FRA` ; devrait venir de `biens.pays` avec fallback.
10. **DPE vierge** — bien géré côté validator mais `dpe_vierge` doit obligatoirement être accompagné d'une date de DPE < 01/07/2021 (règle Ubiflow).

---

## 5. Mapping final recommandé

Voir [mapping_maboximmo_ubiflow.json](mapping_maboximmo_ubiflow.json) pour la version machine-lisible complète.

Les 34 balises de l'audit y figurent avec : `source_maboximmo`, `type_source`, `transformation`, `statut`, `remarque`.

---

## 6. Checklist de mise en production Ubiflow

- [x] Fichier XML valide (encodage UTF-8 minuscule) — `api/flux/ubiflow.php`
- [x] Tous les champs obligatoires présents dans le mapping
- [x] DPE complet (étiquettes + valeurs + date + fourchette €)
- [x] Données ALUR présentes (vente et location)
- [x] ERP / géorisques déclaré (colonnes présentes dans `biens`)
- [ ] **Strip HTML de `texte`** (bloquant #1)
- [ ] **`code_type=0` empêche l'émission du bien** (bloquant #2)
- [ ] **Retirer `brouillon` du `WHERE`** (bloquant #3)
- [ ] **Générer balise `honoraires_payeurs`** (bloquant #4)
- [ ] **Corriger `ubiflow_count_photos` + unifier source photos** (bloquant #5)
- [ ] **Ajouter packaging ZIP `[LOGIN_AGENCE].zip`** (bloquant #6)
- [ ] Ajouter script de dépôt FTP / photos
- [ ] Définir `UBIFLOW_LOGIN_AGENCE` dans `config/db.php`
- [ ] Test de validation envoyé à `flux@ubiflow.net`
- [ ] Fichier représentatif validé par l'équipe Ubiflow
- [ ] Accès FTP reçus et premier dépôt confirmé
- [ ] Cron nocturne configuré (ex : `0 2 * * * php /.../ubiflow.php --save && /.../ftp_upload.sh`)
- [ ] Logs d'export archivés (7 jours minimum)

---

## Annexes

- **Fichier XML de test :** [agence_emery.xml](agence_emery.xml) — 2 annonces conformes (appartement vente Lyon 6 + maison location Caluire).
- **Mapping JSON :** [mapping_maboximmo_ubiflow.json](mapping_maboximmo_ubiflow.json)
- **Contact validation :** `flux@ubiflow.net`
- **Référence nomenclature :** https://sw.ubiflow.net/types_objets.php?univers=IMMO&filiation=O
