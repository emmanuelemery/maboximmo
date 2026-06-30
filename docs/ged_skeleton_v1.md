# Canon squelette GED MaBoxImmo — v1 (slugs alignés BDD)

> **Statut** : 🟢 PRÊT POUR SEED (validation finale user requise avant apply)
> **Version** : skeleton_v1
> **Date dernière révision** : 2026-05-23
> **Convention slugs** : `NN_nom_underscores` (alignée sur BDD existante — décision 2026-05-23)
>
> **Décisions sources** :
> - Architecture hybride C figée 2026-05-23 (`project_ged_architecture_hybride_c_2026-05-23.md`)
> - Arbitrage modèle N1 du 2026-05-12 (`project_ged_arbitrage_modele_n1_2026-05-12.md`)
> - V1 simplifiée 109 N3 (décision 2026-05-23)
> - Slugs alignés BDD (décision 2026-05-23 — option A : aucun UPDATE destructif)
> - Pivot relationnel Sprint 3D (`project_sprint3_persistance_relationnelle.md`)
>
> **Périmètre** : squelette physique stable N1 → N3, partagé entre tous tenants (`tenant_id = NULL`).
> N4 (Entité) / N5 (Année) / N6 (Mois) sont **virtualisés** — hors scope.

---

## 1. Conventions

### 1.1 Nomenclature des niveaux

| Niveau | Sémantique | Source de vérité |
|---|---|---|
| **N1** | **Métier** | racine `ged_folders` avec `parent_id IS NULL`, `module` rempli |
| **N2** | **Domaine** fonctionnel | `parent_id = id(N1)`, hérite du `module` parent |
| **N3** | **Type document** sémantique | `parent_id = id(N2)`, hérite du `module` parent |
| **N4** | **Entité métier** (BIEN, IMMEUBLE, TIERS, MANDAT, COPRO) | **VIRTUEL** — résolu via `ged_document_links` |
| **N5** | **Année** | **VIRTUEL** — calculé sur `ged_documents.metadata.date_doc` |
| **N6** | **Mois ou sous-type** fin | **VIRTUEL** — calculé sur metadata |

### 1.2 Codes et slugs (alignés BDD)

- **Préfixe numérique** sur `name_display` : `NN - Texte` (ex: `04 - Syndic`, `01 - Assemblée générale`)
- **Slug** : format `NN_nom_underscores` (ex: `04_syndic`, `01_assemblee_generale`, `01_kbis`)
  - Exception : N2 sous SYSTEME existants en BDD sans préfixe (`coffre_securise`, `corbeille`) — préservés tels quels pour idempotence
- **`name_canonical`** : SNAKE_CASE en MAJUSCULES (ex: `PV`, `BAIL`, `KBIS`)
- **`module`** : code UPPERCASE figé, hérité de la racine N1

### 1.3 Métadonnées système (toutes les rows seedées)

```json
metadata = {
  "seed_version":  "skeleton_v1",
  "seeded_at":     "2026-05-24",
  "canon_source":  "arbitrage_2026-05-12",
  "folder_kind":   "skeleton"
}
```

Flags BDD : `is_system = 1` · `tenant_id = NULL` · `scope = 'global'` · `source_type = 'auto'` · `created_by = NULL` · `folder_kind = 'skeleton'`

---

## 2. Synthèse volumétrique V1 (validée par dry-run CLI 2026-05-23)

| Niveau | Total canon | À INSERT | À SKIP (BDD existante) |
|---|---|---|---|
| N1 | 15 | 1 (`13_fournisseurs`) | 14 |
| N2 | 78 | 71 | 7 (5 MAILS + `coffre_securise` + `corbeille`) |
| N3 | 109 | 109 | 0 |
| **TOTAL squelette V1** | **202 rows** | **181 INSERT** | **21 SKIP** |

→ Cible 80-120 N3 respectée (109 N3) ✅
→ Total cible 202 rows ✅ (vs cible 400 max)

### Nodes BDD orphelins du canon V1 (non touchés, à archiver en V2)

| id | slug | nom | Pourquoi orphelin |
|---|---|---|---|
| 14 | `98_referentiel_tech` | 98 - Référentiel technique | Doit devenir N2 sous `02_referentiel/03_technique` |
| 15 | `99_parametrage_ged` | 99 - Paramétrage GED | Doit devenir N2 sous `99_systeme/parametrage` |
| 24 | `01_proprietaires` | 01 - Propriétaires (sous gestion_locative) | Devrait être virtualisé (entité N4), pas matérialisé |
| 25 | `99_a_classer_ia` | 99 - À classer (IA basse confiance) | Doit devenir N2 sous `00_a_classer_ia/03_basse_confiance` |

→ **4 nodes orphelins** (1 N2 + 3 N1) qui ne seront pas touchés par le seed V1.
→ Migration de consolidation V2 traitera ces cas séparément.

---

## 3. Détail N1 — 15 racines métier

| Code | name_display | slug | name_canonical | module | Statut |
|---|---|---|---|---|---|
| 00 | `00 - À classer (IA)` | `00_a_classer_ia` | `INBOX` | `INBOX` | SKIP (id=1) |
| 01 | `01 - Direction` | `01_direction` | `DIRECTION` | `DIRECTION` | SKIP (id=2) |
| 02 | `02 - Référentiel` | `02_referentiel` | `REFERENTIEL` | `REFERENTIEL` | SKIP (id=3) |
| 03 | `03 - Ressources humaines` | `03_rh` | `RH` | `RH` | SKIP (id=4) |
| 04 | `04 - Syndic` | `04_syndic` | `SYNDIC` | `SYNDIC` | SKIP (id=5) |
| 05 | `05 - Gestion locative` | `05_gestion_locative` | `GESTION_LOCATIVE` | `GESTION_LOCATIVE` | SKIP (id=6) |
| 06 | `06 - Transaction` | `06_transaction` | `TRANSACTION` | `TRANSACTION` | SKIP (id=7) |
| 07 | `07 - Comptabilité` | `07_comptabilite` | `COMPTABILITE` | `COMPTABILITE` | SKIP (id=8) |
| 08 | `08 - Juridique & Contentieux` | `08_juridique_contentieux` | `JURIDIQUE` | `JURIDIQUE` | SKIP (id=9) |
| 09 | `09 - Marketing & Communication` | `09_marketing_communication` | `MARKETING` | `MARKETING` | SKIP (id=10) |
| 10 | `10 - Modèles de documents` | `10_modeles_documents` | `MODELES` | `MODELES` | SKIP (id=11) |
| 11 | `11 - Mails & Communications` | `11_mails_communications` | `MAILS` | `MAILS` | SKIP (id=12) |
| 12 | `12 - Archives` | `12_archives` | `ARCHIVES` | `ARCHIVES` | SKIP (id=13) |
| **13** | **`13 - Fournisseurs`** | **`13_fournisseurs`** | **`FOURNISSEURS`** | **`FOURNISSEURS`** | **INSERT** ⭐ |
| 99 | `99 - Système` | `99_systeme` | `SYSTEME` | `SYSTEME` | SKIP (id=16) |

→ 14 SKIP + 1 INSERT = 15 N1 ✅

---

## 4. Détail N2 + N3 V1 par N1

> Convention : slug N2/N3 = `NN_nom_underscores` (préfixe positionnel + nom underscoré).
> Exception SYSTEME : N2 sans préfixe pour préserver les rows existantes `coffre_securise` et `corbeille`.

---

### N1.00 — `00_a_classer_ia` (INBOX)

| N2 slug | name_display | name_canonical | N3 V1 |
|---|---|---|---|
| `01_haute_confiance` | `01 - Haute confiance` | `HAUTE_CONFIANCE` | — |
| `02_confiance_moyenne` | `02 - Confiance moyenne` | `CONFIANCE_MOYENNE` | — |
| `03_basse_confiance` | `03 - Basse confiance` | `BASSE_CONFIANCE` | — |
| `04_erreurs_ocr` | `04 - Erreurs OCR` | `ERREURS_OCR` | — |
| `05_doublons` | `05 - Doublons détectés` | `DOUBLONS` | — |

**V1 : 5 N2 (INSERT), 0 N3.**

---

### N1.01 — `01_direction` (DIRECTION)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_identite_societe` | `01_kbis` · `02_statuts` |
| `02_garanties_pro` | `01_carte_professionnelle` · `02_rc_pro` · `03_garantie_financiere` |
| `03_gouvernance` | `01_pv_ag` |
| `04_comptes_annuels` | `01_bilan` · `02_liasse_fiscale` |
| `05_vie_sociale` | `01_decisions_associes` |

**V1 : 5 N2, 9 N3.**

---

### N1.02 — `02_referentiel` (REFERENTIEL)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_reglementation` | `01_loi` · `02_decret` |
| `02_baremes_indices` | `01_irl` · `02_bareme_honoraires` |
| `03_technique` | `01_documentation_logiciels` |

**V1 : 3 N2, 5 N3.**

---

### N1.03 — `03_rh` (RH)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_embauche` | `01_cv` · `02_contrat_de_travail` · `03_dpae` · `04_piece_identite` · `05_rib_salarie` |
| `02_contrats` | `01_cdi` · `02_cdd` · `03_avenant` |
| `03_paye` | `01_bulletin_de_paie` · `02_dsn` |
| `04_formation` | `01_convention_opco` · `02_attestation_formation` |
| `05_evaluation` | `01_entretien_annuel` |
| `06_conges` | `01_demande_conges` · `02_arret_maladie` |
| `07_sortie` | `01_solde_tout_compte` · `02_certificat_de_travail` |
| `08_medecine_travail` | `01_visite_medicale` |

**V1 : 8 N2, 18 N3.**

---

### N1.04 — `04_syndic` (SYNDIC)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_assemblee_generale` | `01_convocation` · `02_pv` |
| `02_comptabilite_copro` | `01_budget_previsionnel` · `02_appel_de_fonds` · `03_regularisation_annuelle` |
| `03_travaux` | `01_devis` · `02_marche_signe` · `03_pv_reception` · `04_facture` |
| `04_contrats_prestataires` | `01_contrat_entretien` · `02_attestation_assurance` |
| `05_sinistres` | `01_dde` · `02_expertise` · `03_indemnisation` |
| `06_diagnostics_immeuble` | `01_dta` · `02_dpe_collectif` · `03_carnet_entretien_immeuble` |
| `07_gouvernance_cs` | `01_election_cs` · `02_mandat_cs` |
| `08_carnet_entretien` | `01_fiche_intervention` · `02_ppt` |

**V1 : 8 N2, 21 N3.**

---

### N1.05 — `05_gestion_locative` (GESTION_LOCATIVE)

> NOTE : Le N2 `01_proprietaires` (id=24) existe déjà en BDD comme rangement par entité. Il reste **orphelin du canon V1** (devrait être virtualisé N4). Coexiste sans conflit avec mes nouveaux N2.

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_mandat_gestion` | `01_mandat_signe` · `02_avenant_mandat` |
| `02_bail` | `01_bail_signe` · `02_edl_entree` · `03_caution_garant` |
| `03_loyers` | `01_quittance` · `02_avis_echeance` · `03_regularisation_charges` |
| `04_fiscalite_proprio` | `01_taxe_fonciere` · `02_declaration_revenus_fonciers` · `03_crg_annuel` |
| `05_sinistres_locatifs` | `01_dde_locataire` |
| `06_sortie_locataire` | `01_preavis` · `02_edl_sortie` · `03_restitution_depot_garantie` |
| `07_caf_apl` | `01_attestation_caf` |

**V1 : 7 N2 (INSERT — slugs différents de `01_proprietaires` existant), 16 N3.**

---

### N1.06 — `06_transaction` (TRANSACTION)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_mandat_vente` | `01_mandat_exclusif` · `02_mandat_simple` |
| `02_acte` | `01_compromis` · `02_acte_authentique` |
| `03_diagnostics_transaction` | `01_dpe` · `02_erp_ernmt` |
| `04_acquereur` | `01_dossier_acquereur` · `02_accord_de_pret` |
| `05_suivi_vente` | `01_decompte_vendeur` · `02_quittance_honoraires` |

**V1 : 5 N2, 10 N3.**

---

### N1.07 — `07_comptabilite` (COMPTABILITE)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_banque` | `01_releve_bancaire` · `02_avis_de_virement` · `03_tlmc` |
| `02_recettes` | `01_facture_emise` · `02_note_honoraires` |
| `03_depenses` | `01_facture_fournisseur` · `02_justificatif_paiement` |
| `04_fiscalite_societe` | `01_tva` · `02_is` |
| `05_cloture` | `01_fec` |

**V1 : 5 N2, 10 N3.**

---

### N1.08 — `08_juridique_contentieux` (JURIDIQUE)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_procedures` | `01_assignation` · `02_jugement` |
| `02_contrats_generaux` | `01_contrat_societe` |
| `03_rgpd` | `01_registre_traitements` |
| `04_contentieux_locatif` | `01_commandement_de_payer` · `02_saisie` |

**V1 : 4 N2, 6 N3.**

---

### N1.09 — `09_marketing_communication` (MARKETING)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_affiches` | `01_affiche_a3` · `02_affiche_a4` |
| `02_supports_commerciaux` | `01_fiche_descriptive` |
| `03_annonces` | `01_annonce_ubiflow` · `02_annonce_externe` |
| `04_presse` | `01_article_presse` |
| `05_reseaux_sociaux` | `01_post_reseau_social` |

**V1 : 5 N2, 7 N3.**

---

### N1.10 — `10_modeles_documents` (MODELES — pas de N3 V1)

| N2 slug | N3 V1 |
|---|---|
| `01_modeles_syndic` | — |
| `02_modeles_gestion` | — |
| `03_modeles_transaction` | — |
| `04_modeles_compta` | — |
| `05_modeles_rh` | — |
| `06_modeles_juridique` | — |

**V1 : 6 N2, 0 N3.**

---

### N1.11 — `11_mails_communications` (MAILS — tous les N2 déjà en BDD)

| N2 slug | Statut |
|---|---|
| `01_entrants` | SKIP (id=17) |
| `02_sortants` | SKIP (id=18) |
| `03_archives` | SKIP (id=19) |
| `04_pieces_jointes` | SKIP (id=20) |
| `05_conversations_importantes` | SKIP (id=21) |

**V1 : 5 N2 (tous SKIP — 0 INSERT), 0 N3.**

---

### N1.12 — `12_archives` (ARCHIVES)

| N2 slug | N3 V1 |
|---|---|
| `01_mandats_clos` | — |
| `02_baux_resilies` | — |
| `03_copro_perdues` | — |
| `04_obsoletes` | — |

**V1 : 4 N2, 0 N3.**

---

### N1.13 — `13_fournisseurs` (FOURNISSEURS — NOUVEAU N1 ⭐)

| N2 slug | N3 V1 (slugs) |
|---|---|
| `01_referencement` | `01_fiche_fournisseur` · `02_kbis_fournisseur` |
| `02_contrats_cadres` | `01_contrat_cadre` · `02_grille_tarifaire` |
| `03_factures` | `01_facture_a_classer` · `02_facture_payee` |
| `04_qualite` | `01_reclamation` |

**V1 : 4 N2, 7 N3.**

---

### N1.99 — `99_systeme` (SYSTEME — `coffre_securise`/`corbeille` existent SANS préfixe)

| N2 slug | name_display | Statut |
|---|---|---|
| `coffre_securise` | Coffre sécurisé | SKIP (id=23) |
| `corbeille` | Corbeille | SKIP (id=22) |
| `parametrage` | Paramétrage GED | INSERT |
| `logs` | Logs & audit | INSERT |

**V1 : 4 N2 (2 SKIP + 2 INSERT), 0 N3.**

---

## 5. Récapitulatif volumétrie V1 par N1

| N1 | nb N2 INSERT | nb N2 SKIP | nb N3 | Sous-total |
|---|---|---|---|---|
| 00 INBOX | 5 | 0 | 0 | 5 |
| 01 DIRECTION | 5 | 0 | 9 | 14 |
| 02 REFERENTIEL | 3 | 0 | 5 | 8 |
| 03 RH | 8 | 0 | 18 | 26 |
| 04 SYNDIC | 8 | 0 | 21 | 29 |
| 05 GESTION_LOCATIVE | 7 | 0 | 16 | 23 |
| 06 TRANSACTION | 5 | 0 | 10 | 15 |
| 07 COMPTABILITE | 5 | 0 | 10 | 15 |
| 08 JURIDIQUE | 4 | 0 | 6 | 10 |
| 09 MARKETING | 5 | 0 | 7 | 12 |
| 10 MODELES | 6 | 0 | 0 | 6 |
| 11 MAILS | 0 | 5 | 0 | 5 |
| 12 ARCHIVES | 4 | 0 | 0 | 4 |
| 13 FOURNISSEURS | 4 | 0 | 7 | 11 |
| 99 SYSTEME | 2 | 2 | 0 | 4 |
| **TOTAL N2** | **71** | **7** | — | — |
| **TOTAL** | | | **109 N3** | **202 rows** |

→ **Sur les 202 rows canon : 21 SKIP (rows existantes BDD) + 181 INSERT (nouveaux).**

---

## 6. Règles d'évolution post-seed

(inchangées vs version précédente du doc)

### 6.1 Promotion d'un N3 de V2 → V1
- Sur demande terrain
- Vérification : type doc réellement utilisé ≥ 10 fois en 3 mois
- Migration additive `skeleton_v1.X`

### 6.2 Ajouter un node custom tenant
- `tenant_id IS NOT NULL`, `source_type='manual'`, `is_system=0`
- Visible uniquement pour ce tenant
- Si node custom devient générique → promotion vers V2 backlog puis V1.X

### 6.3 Modifier un node squelette V1
- Modification interdite par défaut (`is_system=1`)
- Renommage cosmétique autorisé via page admin dédiée
- Restructuration profonde → migration `skeleton_v2`

### 6.4 Archiver un node V1
- `UPDATE ged_folders SET is_archived = 1` (jamais DELETE)
- Docs liés restent accessibles

### 6.5 Cleanup des 4 nodes orphelins (V2)
Une migration séparée traitera :
- `98_referentiel_tech` (id=14) → re-parent comme N2 sous `02_referentiel`
- `99_parametrage_ged` (id=15) → archive (déjà couvert par nouveau `parametrage` sous SYSTEME)
- `99_a_classer_ia` (id=25) → archive (déjà couvert par `03_basse_confiance` sous INBOX)
- `01_proprietaires` (id=24) → archive (N4 virtualisé en couche assemblage)

---

## 7. Validation finale avant codage pages admin

### 7.1 Sur le canon V1
- [x] **Architecture hybride C** — validée 2026-05-23
- [x] **Squelette tenant_id = NULL** — validée 2026-05-23
- [x] **N3 décliné par N2** — validée 2026-05-23
- [x] **Allègement V1 à 109 N3** — validée 2026-05-23
- [x] **Option A : aligner slugs sur BDD** — validée 2026-05-23
- [x] **15 N1** (section 3) — alignés et validés
- [x] **78 N2** (section 4) — alignés et validés
- [x] **109 N3 V1** (section 4) — préfixés `NN_nom`
- [x] **Backlog V2** — 173 N3 reportés (encarts par N1 inchangés)

### 7.2 Dry-run CLI 2026-05-23 (vérifié)
- ✅ 181 INSERT prévus
- ✅ 21 SKIP (14 N1 + 5 MAILS N2 + 2 SYSTEME N2)
- ✅ 0 erreur
- ✅ 0 écriture BDD effective
- ✅ Total 202 rows cohérent avec canon

### 7.3 Reste à faire (Sprint 4A — sur ton GO)
- [ ] Coder `admin/admin_ged_seed_skeleton_v1.php` (UI dry-run + apply)
- [ ] Coder `admin/admin_ged_seed_skeleton_rollback.php`
- [ ] Tu valides le rendu UI
- [ ] Tu cliques toi-même "Appliquer (COMMIT)"
- [ ] Migration v2 ultérieure pour les 4 nodes orphelins

---

## 8. Annexes

### A. Backlog V2 complet (référence)

Les 173 N3 reportés en V2 sont listés dans les encarts `V2 backlog :` de la version maximaliste précédente du canon (commit antérieur à 2026-05-23). À promouvoir progressivement selon usage terrain (seuil ≥10 docs/3 mois).

**Méthodologie de promotion V2 → V1.X** :
1. Comptage usage réel sur `ged_documents` après 3 mois de prod
2. Seuil de promotion : ≥ 10 docs du même type sur 3 mois → candidat
3. Décision métier : admin valide ajout au seed
4. Migration additive `skeleton_v1.X` avec uniquement les nouveaux N3

### B. Historique des évolutions du canon

| Version | Date | Auteur | Changement |
|---|---|---|---|
| v0.1 | 2026-05-12 | Emmanuel + Claude | Arbitrage initial 15 N1 |
| v1.0 (maximaliste) | 2026-05-23 | Claude | Canon complet 282 N3 — refusé pour V1 |
| v1 (simplifié, kebab-case) | 2026-05-23 | Emmanuel + Claude | Allègement 109 N3, 173 N3 V2 |
| **v1 (slugs alignés BDD)** | **2026-05-23** | **Emmanuel + Claude** | **Format slug `NN_nom_underscores`, idempotence garantie** |
| v1.X | (futur) | | Promotion V2 → V1 selon usage |

### C. Glossaire

- **Squelette** : structure stable, partagée, mutualisée
- **Virtualisation** : assemblage à la volée d'un sous-arbre sans ligne BDD
- **Tenant** : société cliente (multi-tenant SaaS)
- **V1 (seed initial)** : ce qui est seedé en Sprint 4A
- **V2 (backlog)** : ce qui sera ajouté à la demande
- **SKIP** : node déjà en BDD avec slug+parent identiques, INSERT évité
- **N1/N2/N3** : niveaux matérialisés dans `ged_folders`
- **N4/N5/N6** : niveaux virtualisés (Entité / Année / Mois)

### D. Mapping module → couleur métier (rappel charte 2026-05-23)

| Module | Couleur charte |
|---|---|
| GESTION_LOCATIVE | bleu pétrole #2d5f6b |
| TRANSACTION | doré #eab308 |
| BIEN | vert amande #84a98c |
| IMMEUBLE | vert doux #7c9885 |
| PROPRIETAIRE/TIERS | pétrole cyan #0e7490 |

### E. Références croisées

- Architecture : `project_ged_architecture_hybride_c_2026-05-23.md`
- Pivot doc↔entité : `project_sprint3_persistance_relationnelle.md`
- Arbitrage N1 : `project_ged_arbitrage_modele_n1_2026-05-12.md`
- Synthèse maître : `project_ged_synthese_2026-05-03.md`
- Volumes portefeuille : `reference_volumes_portefeuille.md`
- Charte couleurs : `design_couleurs_metier_mbi.md`
- Migration : `public_html/inc/migrations/20260524_ged_seed_skeleton_v1.php`
