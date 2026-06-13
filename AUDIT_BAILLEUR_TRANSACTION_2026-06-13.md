# AUDIT BAILLEUR ↔ TRANSACTION — MaBoxImmo
**Date : 2026-06-13** · Source : code réel `public_html` + BDD locale `maboximmo` (volumes réels)

> Objectif : architecture cible d'un **espace Transaction** complet, sans dupliquer données / pages / fonctions, en conservant le **module Bailleur** existant. Principe directeur : **zéro ressaisie**.

---

## 0. SYNTHÈSE EN 10 LIGNES (TL;DR)

1. Le **référentiel métier est déjà bon** : `biens` / `immeubles` / `proprietaires` / `tiers` + `tiers_roles` (polymorphe). **Ne RIEN dupliquer là-dedans.**
2. **`tiers_roles_codes` contient DÉJÀ** `vendeur`, `acquereur`, `notaire`, `prospect_acquereur`, `prospect_vendeur`, `partenaire_apporteur`… → **tous les acteurs de la vente existent déjà**. Aucune table « contacts vente » à créer.
3. La **GED est centralisée** (`ged_documents` + `ged_document_links` polymorphe, 432 docs / 936 liens). Les tables `*_documents` legacy sont en voie d'extinction. **Une seule GED, c'est acquis.**
4. Le **relais Bailleur↔Transaction** existe et fonctionne (statut `vendu` bidirectionnel via hooks — cf. `RELAIS_TRANSACTION_BAILLEUR.md`).
5. Le cycle de vente est **mûr jusqu'à OFFRE** (`leads_annonces.statut_offre`), puis **vide** : pas de COMPROMIS, pas d'ACTE, pas de NOTAIRE relié, pas de FACTURE d'honoraires auto.
6. **Il MANQUE une seule brique structurante** : un **`dossier_vente`** (table pivot légère) qui relie bien + acteurs (via `tiers_roles`) + docs (via `ged_document_links`) + étapes.
7. **Doublon réel à trancher** : 2 tables factures (`factures` 7 lignes + `agency_facture` 5 lignes). À fusionner.
8. **Incohérence GED à corriger** : `ged_document_links.entity_type` mélange `IMB` (88) et `IMMEUBLE` (212). À normaliser.
9. **OneDrive** = classement seulement (40%), **Google Drive** = stockage opérationnel. OneDrive comme *source* lecture, pas second stockage.
10. **Portails externes** : socle token existant (`investisseur_partages`) réutilisable pour acquéreur/vendeur/notaire. Pas de nouveau système d'auth à inventer.

---

## A. ARCHITECTURE ACTUELLE

### A.1 Inventaire technique des tables (volumes réels BDD)

#### Référentiel cœur (à conserver tel quel — source de vérité)
| Table | Rôle | Clés | Volume |
|---|---|---|---|
| `proprietaires` | Propriétaire / bailleur (vue dénormalisée) | `id`, `id_tiers`, `id_agence`, `id_proprietaire_parent` (SCI mère) | **281** |
| `biens` | Lot / bien physique (≈230 colonnes : descriptif, DPE, prix, statut commercial) | `id`, `id_immeuble`, `id_proprietaire`, `id_tiers`, `id_agence`, `id_societe` | **1 173** |
| `immeubles` | Immeuble / copropriété | `id`, `id_proprietaire`, `id_agence`, `vendu`, `statut_immeuble` | **1 456** |
| `tiers` | **Référentiel universel personnes/sociétés** | `id`, `type_tiers`, `id_societe`, `id_agence` | **925** |
| `tiers_roles` | **Pivot polymorphe rôle↔objet** (`role_code`, `objet_type`, `id_objet`) | `id_tiers`, `role_code`, `id_objet` | **927** |
| `tiers_roles_codes` | Catalogue des 59 rôles métier | `code` | **59** |

> ⚠️ **`tiers_roles` n'est aujourd'hui utilisé que pour 3 rôles** : `locataire`, `mandant`, `proprietaire`. Les codes `vendeur`/`acquereur`/`notaire` existent dans le catalogue **mais ne sont pas encore peuplés**. C'est exactement le levier du dossier de vente.

#### Bailleur / Gestion locative
| Table | Rôle | Volume |
|---|---|---|
| `baux` (legacy) + `bien_baux` (V2 riche) | Baux locatifs | 474 / **511** |
| `crg_trimestres` / `crg_situations_locataires` / `crg_ecritures` | Comptes-rendus de gestion (source patrimoine) | 313 / 2 225 / **9 093** |
| `locataires_statuts` | Statut locataire (impayés) | 331 |
| `bien_prix` | Historique prix/scénarios (vente, loyer estimé) | 117 |
| `user_proprietaires` | Périmètre user→propriétaires (accès) | 23 |
| `investisseur_partages` | Tokens de partage externe (portail propriétaire) | 0 (socle prêt) |

#### Transaction / commercialisation
| Table | Rôle | Volume |
|---|---|---|
| `annonces` | Annonce de diffusion (vente/location) | 66 |
| `leads_annonces` | **Leads ET offres** (`prix_propose`, `statut_offre`, `financement_type`) | 0 |
| `mandats` | Registre des mandats (⚠️ colonne `id_user`, pas `created_by`) | **160** |
| `mandats_log` / `mandats_agences_ext` | Journal / mandats inter-agences | 61 / 0 |
| `bien_score_commercial`, `visites*` | Score, visites | faible |
| `transaction_chargement_staging` | Upload par lot avant matching IA | 2 |
| `vw_transactions` | **VUE agrégée** bien+annonce+offres+docs+`statut_transaction` calculé | vue |

> Le « tableau Transaction » actuel (`transaction_index.php`) **lit `vw_transactions`** — bonne approche, pas de table redondante.

#### GED (centralisée — ACQUIS)
| Table | Rôle | Volume |
|---|---|---|
| `ged_documents` | **Source unique documents** (uuid, name_*, storage_provider, hash) | **432** |
| `ged_document_links` | **Lien polymorphe doc↔entité** (`entity_type`,`entity_id`,`link_role`) | **936** |
| `ged_folders` / `ged_level_codes` / `ged_codes_glossaire` | Arbo + nomenclature V3.1 | 53 / 1 487 / 1 055 |
| `fluxbox_documents` / `fluxbox_cartes` / `fluxbox_ia_usage` | Ingestion + anti-doublon SHA256 + plafond IA 75€ | 20 / 24 / 18 |
| **legacy** `biens_documents` (87), `immeubles_documents` (0), `bailleur_documents` (0), `taches_documents` (42) | Déprécié / en extinction | — |

`ged_document_links.entity_type` réel : `BIEN` 452 · `IMMEUBLE` 212 · `TIERS` 193 · `IMB` 88 · `BAIL` 5.
→ **Incohérence `IMB` vs `IMMEUBLE` à normaliser.**

#### Factures (DOUBLON à trancher)
| Table | Rôle | Volume |
|---|---|---|
| `factures` + `factures_lignes` | Facturation générique (lien `id_mandat`, `id_immeuble`, `id_tiers`) | 7 / 8 |
| `agency_facture` + `agency_facture_ligne` | Facturation « agency » (établissement) | 5 / 7 |

→ **Deux systèmes de facturation parallèles.** Aucun ne gère explicitement les **honoraires de vente** ni le lien vers le dossier/mandat de transaction.

### A.2 Inventaire des pages (synthèse)

| Domaine | Pages matures | Statut |
|---|---|---|
| **Bailleur** | `bailleur_dashboard`, `bailleur_patrimoine_actif`, `bailleur_immeubles`, `bailleur_ged`, `bailleur_revision_loyer`, `bailleur_crg_audit`, `bailleur_sci_organigramme` + actions (`bailleur_mettre_en_vente`, `bailleur_save_vente`…) | ✅ Complet |
| **Transaction** | `transaction_index` (dashboard vw_transactions), `transaction_portefeuilles*` (hub bailleur), `transaction_chargement` (upload IA) | ✅ Mature côté amont |
| **Annonce** | `annonce_liste`, `annonce_creation`, `annonce_photos`, `annonce_reglementations` | ✅ |
| **Bien** | `bien_360` (vue 360 : baux+offres+docs+checklist), `bien_detail`, `bien_creation` | ✅ |
| **Mandats** | `agency_mandats`, `agency_registre_fiche`, `agency_registre_form` | ✅ Registre légal OK |
| **Factures** | `agency_factures`, `agency_facture_form`, `agency_honoraires_config`, `agency_pdf_facture` | ✅ syndic / ⚠️ vente |
| **GED** | `ged_dashboard`, `modules/ged/ged_*`, `doc_upload_review` (pipeline unifié), FluxBox | ✅ |
| **Portails externes** | `p/investisseur.php` (token RO), `p/upload.php`, `agence_portail.php`, `access_choice.php` | ✅ socle propriétaire/agence |
| **MANQUANTES** | `transaction_dossier.php`, `transaction_compromis.php`, `transaction_acte.php`, portails acquéreur/notaire | ❌ à créer |

### A.3 Cartographie des données — « ne jamais ressaisir »

| Donnée | Déjà disponible dans | Ne PAS redemander en Transaction |
|---|---|---|
| Identité / coordonnées vendeur | `proprietaires` + `tiers` | ✅ |
| Descriptif complet du bien (surfaces, DPE, étage…) | `biens` (~230 col.) | ✅ |
| Adresse / copropriété | `immeubles` | ✅ |
| Mandat de vente (n°, dates, honoraires, exclusivité) | `mandats` | ✅ |
| Prix / estimation / scénarios | `biens.prix_*`, `bien_prix`, `estimation_agence_*` | ✅ |
| Bail / locataire en place (bien occupé) | `bien_baux`, `baux` | ✅ |
| Diagnostics (DPE, ERP, dossiers) | `biens.dpe_*`, `dpe_diags`, GED | ✅ |
| Documents (mandat, DPE, titre, compromis…) | `ged_documents` + `ged_document_links` | ✅ |
| Acquéreur / offre | `leads_annonces` (+ futur `tiers` rôle `acquereur`) | ✅ |
| Notaire | `tiers` rôle `notaire` (catalogue prêt) | ✅ |

**Conclusion : ~95% des données d'un dossier de vente existent déjà.** Le travail = **relier**, pas ressaisir.

### A.4 Cycle métier — état réel

```
PROPRIÉTAIRE      ✅ proprietaires / tiers
   ↓
BIEN EN GESTION   ✅ biens (+ baux si occupé)
   ↓
ESTIMATION        ✅ bien_prix / estimation_agence_* / bailleur_save_vente
   ↓
MANDAT DE VENTE   ✅ mandats (registre légal complet)
   ↓
COMMERCIALISATION ✅ annonces + diffusion + transaction_index
   ↓
OFFRE             🟡 leads_annonces.statut_offre (saisie OK, pas de fiche acquéreur tiers)
   ↓
COMPROMIS         ❌ INEXISTANT (ni page, ni suivi signature, ni notaire)
   ↓
ACTE              ❌ INEXISTANT (hook acte→vendu existe, mais aucune page de pilotage)
   ↓
FACTURE HONORAIRES ❌ pas de génération auto, pas de lien mandat/dossier
```

**Redondances détectées :** (1) `factures` vs `agency_facture` ; (2) `baux` vs `bien_baux` (migration en cours) ; (3) legacy `*_documents` vs GED ; (4) `IMB`/`IMMEUBLE` dans les liens GED.

---

## B. ARCHITECTURE CIBLE

### B.1 Principe : le DOSSIER DE VENTE comme pivot léger

Plutôt que de dupliquer vendeur/bien/acquéreur/notaire/docs, le dossier **agrège par référence** ce qui existe déjà.

```
                    ┌──────────────────────────────┐
                    │   dossier_vente  (NOUVEAU)    │  ← 1 seule table pivot
                    │  id, id_bien, id_mandat,      │
                    │  etape (enum), prix_*,         │
                    │  date_compromis, date_acte,    │
                    │  honoraires_montant, id_societe│
                    └──────────────┬───────────────┘
        ┌──────────────┬───────────┼────────────┬──────────────┐
        ▼              ▼           ▼            ▼              ▼
   biens(id_bien)  mandats     tiers_roles   ged_document_   leads_annonces
   immeubles       (existant)  (vendeur,     links(entity_   (offre source)
   proprietaires               acquereur,    type='DOSSIER')
   (déjà liés)                 notaire)      docs du dossier
```

- **Acteurs** → `tiers_roles` avec `objet_type='dossier_vente'`, `id_objet=dossier.id`, `role_code ∈ {vendeur, acquereur, notaire, notaire_acquereur, partenaire_apporteur}`. **Aucune nouvelle table contacts.**
- **Documents** → `ged_document_links` avec un nouvel `entity_type='DOSSIER'` (offre, compromis, acte, factures). **GED unique conservée.**
- **Offre** → `leads_annonces` reste la source ; le dossier se crée à l'**acceptation** d'une offre.
- **Étapes** → colonne `etape` (`offre_acceptee → compromis → acte → solde`) + dates jalons. Le hook acte→`biens.vendu` existant alimente le dossier.

### B.2 Espace Bailleur vs Espace Transaction (séparation des rôles)

| | **Espace BAILLEUR** | **Espace TRANSACTION** |
|---|---|---|
| Orientation | Propriétaire / patrimoine | Commercial / vente |
| Utilisateur | Gestionnaire, propriétaire (portail) | Négociateur / commercial |
| Entrée | `bailleur_patrimoine_actif` | `transaction_index` (vw_transactions) |
| Données | CRG, loyers, immeubles, scénarios prix | dossiers de vente, offres, étapes notariales |
| Bascule | bouton **« Mettre en vente »** (déjà là) → crée annonce + (cible) ouvre/relie dossier_vente | reçoit le bien **sans ressaisie** |
| Partagé | Bien, mandat, GED, tiers, prix | idem (mêmes tables) |

La bascule **`bailleur_mettre_en_vente.php` existe déjà** : il suffit de l'étendre pour amorcer le `dossier_vente`.

### B.3 Portails externes — un socle, plusieurs vues

Réutiliser le mécanisme **token** (`investisseur_partages` + `p/*.php`) déjà en place :

| Portail | Voit | Réutilise |
|---|---|---|
| Vendeur | son dossier : avancement, offres transmises, docs partagés | token RO existant |
| Acquéreur | dossier côté achat : compromis, pièces à fournir, échéances | même socle |
| Notaire vendeur / acquéreur | pièces du dossier, dépôt de documents | token + dépôt GED |
| Agence partenaire | bien partagé en inter-cabinet (`mandats_agences_ext`) | token |

**Tous pointent sur le même `dossier_vente`** → pas de copie de données, traçabilité d'accès native (`investisseur_partages.consulte_*`).

### B.4 OneDrive / GED — éviter le doublon de stockage

- **Google Drive = stockage de référence** (`storage_provider='google_drive'`, opérationnel).
- **OneDrive = source en lecture/classement** (`inc/onedrive_classer.php`) : on **référence** le fichier OneDrive et on **classe** (lien GED), on ne recopie pas.
- **Anti-doublon = hash SHA256** déjà en place (FluxBox N1). Le lien polymorphe permet à 1 doc d'être rattaché à bien + immeuble + dossier sans duplication physique.

---

## C → G. RECOMMANDATIONS

### C. Tables à CONSERVER (socle, ne pas toucher)
`biens`, `immeubles`, `proprietaires`, `tiers`, `tiers_roles`, `tiers_roles_codes`, `mandats`, `bien_baux`, `bien_prix`, `ged_documents`, `ged_document_links`, `leads_annonces`, `annonces`, `crg_*`, `investisseur_partages`, `vw_transactions`.

### D. Tables à FUSIONNER / NETTOYER
| Action | Détail | Risque |
|---|---|---|
| **Facturation** | Choisir **une** base (`factures` recommandée : déjà reliée `id_mandat`/`id_tiers`) ; migrer `agency_facture` ; ajouter `id_dossier_vente` + type `honoraires_vente` | Moyen — peu de lignes (12) |
| **Baux** | Finir migration `baux` → `bien_baux`, garder vue de compat | Faible |
| **Documents legacy** | Éteindre `biens_documents`/`bailleur_documents`/`immeubles_documents` au profit de `ged_document_links` | Faible (volumes faibles) |
| **Normaliser GED** | Unifier `entity_type` `IMB`→`IMMEUBLE` (88 lignes) | Faible |

### E. Pages à CONSERVER
Tout le module Bailleur, `transaction_index`/`portefeuilles*`/`chargement`, `bien_360`, annonces, `agency_mandats`/registre, GED + FluxBox, `doc_upload_review`, portails `p/*`.

### F. Pages à CRÉER (espace Transaction)
1. **`transaction_dossier.php`** — fiche **Dossier de vente unique** (timeline + acteurs via tiers_roles + docs GED + offres + factures). *Pièce maîtresse.*
2. **`transaction_dossier_acteur.php`** (ou modal) — rattacher acquéreur/notaire depuis `tiers` (création tiers si absent, jamais ressaisie si existant).
3. **`transaction_compromis.php`** — dépôt compromis + jalons signature → `etape='compromis'`.
4. **`transaction_acte.php`** — dépôt acte → marque `biens.vendu` (hook existant) + déclenche facture honoraires.
5. **`p/dossier_vente.php`** — portail externe paramétrable par rôle (vendeur / acquéreur / notaire / partenaire) sur socle token.
6. (API) `transaction_dossier_create.php`, `transaction_dossier_etape.php`, `transaction_honoraires_generate.php`.

### G. Priorités de développement
| Prio | Lot | Contenu | Pré-requis |
|---|---|---|---|
| **P0** | Pivot | Migration `dossier_vente` + `entity_type='DOSSIER'` GED + peuplement `tiers_roles` (vendeur auto depuis propriétaire) | aucun |
| **P0** | Fiche | `transaction_dossier.php` (lecture seule d'abord : agrège l'existant) + bouton depuis `bien_360`/`transaction_index` | P0 pivot |
| **P1** | Acteurs | Rattachement acquéreur (depuis offre acceptée `leads_annonces`→tiers) + notaire | P0 |
| **P1** | Étapes | Compromis + Acte (pages + jalons), branchement hook acte→vendu existant | P0 |
| **P2** | Facturation | Unifier factures + génération **honoraires de vente** auto sur barème `agency_honoraires_config` | D-fusion |
| **P2** | Portails | `p/dossier_vente.php` multi-rôles | P0/P1 |
| **P3** | Nettoyage | Migrations legacy docs + baux + normalisation `IMB` | continu |

---

## OBJECTIF FINAL — atteint par cette cible
- ✅ Espace **Bailleur** orienté propriétaire/patrimoine — *conservé tel quel*.
- ✅ Espace **Transaction** orienté commercial/vente — *bâti sur `dossier_vente` + l'existant*.
- ✅ **GED unique** — *déjà en place, à consolider*.
- ✅ **Dossier de vente unique** — *1 table pivot légère, 0 duplication d'acteurs/docs*.
- ✅ **Ressaisie minimale absolue** — *bien, vendeur, mandat, prix, docs, acquéreur, notaire tous référencés depuis l'existant*.
