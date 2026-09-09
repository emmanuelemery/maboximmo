# LA RÉFÉRENCE — base `mbi` après l'import CRG du 09/09/2026

> Ces chiffres sont le juge de toute passe future. La requête qui les produit est figée dans
> `docs/v2_migrations/_reference_chiffres.sql` : **une comparaison n'a de sens que si la
> requête n'a pas bougé**. Sauvegarde de l'état :
> `/opt/mbi/sauvegardes/mbi_apres_import_crg_20260909.sql.gz`
>
> Source : 4 dépôts, **948 comptes rendus de gestion**, périodes 2025-T1 → 2026-T2.

## A · Par agence

| id | agence | société | comptes | propriétaires | immeubles | lots | occupés | vides | mandats | baux |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | REGIE EMERY VIENNE | Régie EMERY | 72 | 72 | 75 | **122** | 102 | 20 | 122 | 126 |
| 3 | REGIE EMERY LYON | Régie EMERY | 76 | 74 | 178 | **341** | 248 | 93 | 341 | 473 |
| 4 | REGIE EMERY CHAPONOST | Régie EMERY | 186 | 184 | 189 | **340** | 328 | 12 | 340 | 348 |
| 5 | EMERY IMMO RIOM | EMERY IMMOBILIER | 225 | 198 | 217 | **322** | 285 | 37 | 322 | 386 |
| | **TOTAL** | | **559** | | **659** | **1 125** | **963** | **162** | **1 125** | **1 333** |

Agences 2 (MIONS), 6 (CHAMALIERES), 7 (ST MARTIN LA PLAINE) et 8 (LOCA IMMO VILLEURBANNE) :
**aucun CRG dans les quatre dépôts** — elles existent au socle, sans patrimoine importé.

⚠️ `propriétaires` < `comptes` : un même tiers détient plusieurs comptes (RIOM, 198 pour 225).

## B · Natures de bien, par agence

| nature | usage | VIENNE | LYON | CHAPONOST | RIOM | **total** |
|---|---|---|---|---|---|---|
| appartement | habitation | 97 | 212 | 235 | 200 | **744** |
| maison | habitation | 2 | 7 | 45 | 72 | **126** |
| local_commercial | professionnel | 13 | 95 | 22 | 14 | **144** |
| garage | annexe | 9 | 2 | 27 | 28 | **66** |
| entrepot | professionnel | — | 12 | 1 | — | **13** |
| parking | annexe | — | 2 | 9 | 2 | **13** |
| bureau | professionnel | — | 5 | — | 2 | **7** |
| parties_communes | autre | — | — | — | 4 | **4** |
| habitation | habitation | — | 2 | — | — | **2** |
| panneau | autre | — | 2 | — | — | **2** |
| terrain | autre | — | 1 | 1 | — | **2** |
| cave | annexe | 1 | — | — | — | **1** |
| dependance | annexe | — | 1 | — | — | **1** |

**0 lot sans nature.** Vocabulaire : 22 natures, 4 usages (`biens_natures`).

## C · Totaux

| | | | |
|---|---|---|---|
| tiers | **1 782** | 154 morales · 1 628 physiques | |
| tiers_roles | **3 738** | mandants | 559 |
| immeubles | **659** | biens | 1 125 |
| baux | **1 333** | mandats | 1 125 |
| occupations | **1 333** | occupation_occupants | 1 334 |
| objet_codes | **2 412** | adresses / liens | **1 002 / 1 075** |

## D · Rôles, tous sur leur objet

| rôle | objet | lignes | tiers | objets |
|---|---|---|---|---|
| `locataire` | bail | 1 334 | 1 262 | 1 333 |
| `proprietaire` | bien | 1 125 | 496 | 1 125 |
| `proprietaire` | immeuble | 720 | 496 | 610 |
| `proprietaire` | mandant | 559 | 526 | 559 |

## E · Adresses — écrites une seule fois

| objet | liens | adresses distinctes |
|---|---|---|
| IMMEUBLE | 553 | 547 |
| TIERS | 511 | 465 |
| AGENCE | 8 | 8 |
| SOCIETE | 3 | 3 |

**1 002 valeurs pour 1 075 liens** — 45 adresses servent à plusieurs objets.
« 76 Rue de Verdun, 69100 VILLEURBANNE » en dessert **20** : une agence, une société,
des immeubles et des tiers.

## F · Les trous connus — mesurés, pas cachés

| | | pourquoi |
|---|---|---|
| immeubles sans adresse | **106** | le CRG imprime un nom de résidence, pas une voie |
| propriétaires sans adresse | **15** | 45 CRG sur 948 n'ont pas livré de bloc lisible |
| lots sans immeuble | **20** | le document ne les rattache pas |
| immeubles sans lot | **49** | lus, mais aucun lot ne s'y rattache |
| lots sans occupation | **2** | |
| locataires non joignables | **499** | 337 ont quitté · 162 en place dans un immeuble sans rue |

## G · Les dix invariants — tous à 0

`tiers sans rôle` · `biens sans compte` · `biens sans nature` · `biens sans mandat` ·
`comptes sans tiers` · `occupations sans bail` · `occupants sans tiers` ·
`immeubles sans agence` · `lignes d'import sans entrée au journal` ·
`liens d'adresse hors journal (hors socle)`

⚠️ **19 lignes sont hors journal, et c'est voulu** : 8 adresses et 11 liens AGENCE/SOCIETE
posés par la migration 0026. Ce sont des données de socle — une annulation d'import ne doit
JUSTEMENT pas les défaire.

## Contrôles externes passés

Trois portefeuilles vérifiés par Emmanuel de mémoire, **trois exacts** :

- **SCI EVEREST** — 6 lots, tous loués, 3 partis débiteurs sur des lots relouis ✓
- **SCI HIMMALAYA** — 2 entrepôts (CAUDAN, LA TRONCHE) ✓
- **SARL FOCH INVESTISSEMENTS** — 29 lots sur 17 immeubles, 17 appartements + 12 locaux ✓
