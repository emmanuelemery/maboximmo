# Référentiel des mentions légales — Supports commerciaux

> **⚠️ AVERTISSEMENT — Document fonctionnel, pas un avis juridique**
>
> Ce référentiel structure les règles que **l'assistant critique** du module
> *Ma Box Communication* (`mbi_supports`) appliquera avant tout export d'un
> support commercial.
>
> Il s'appuie sur l'état du droit français immobilier connu de la rédaction
> (loi Hoguet, loi ALUR, loi Climat & Résilience, dispositions DPE 2022/2025,
> arrêtés honoraires, code de la copropriété, code de la consommation).
>
> **Avant toute mise en production**, ce document doit être **relu et validé**
> par un juriste / notaire / avocat spécialisé. Le code applicatif piloté par
> ce référentiel pourra continuer à fonctionner, mais la version active dans
> la table `mbi_supports_mentions_versions` ne devra basculer
> `valide_juridiquement = 1` qu'après cette relecture externe.

---

## 1. Métadonnées de version

| Champ | Valeur |
|---|---|
| Version | `2026-05-01.v1` |
| Statut | Brouillon fonctionnel — non validé juridiquement |
| Périmètre couvert | Vente (V1) — Copropriété — DPE/GES — Honoraires |
| Périmètre prévu (V2) | Location, états des risques renforcés, RS encadrés |
| Dernière révision | 2026-05-01 |

---

## 2. Distinction **Bloc dur** / **Alerte**

| Niveau | Comportement | Exemples |
|---|---|---|
| **Bloc dur** | Export **refusé** tant que la règle n'est pas satisfaite. | Carte professionnelle absente, prix nul, autorisation de diffusion non signée. |
| **Alerte** | Export autorisé, message visible journalisé, validation utilisateur requise. | Photo principale sombre, description très courte, DPE défavorable non commenté. |

> Les blocs durs sont des contraintes **légales** ou **contractuelles** (mandat).
> Les alertes sont des contraintes **qualitatives commerciales**.

---

## 3. Sources de référence

- Loi n° 70-9 du 2 janvier 1970 (loi Hoguet) et décret n° 72-678
- Loi n° 2014-366 du 24 mars 2014 (loi ALUR)
- Loi n° 2021-1104 du 22 août 2021 (Climat & Résilience)
- Décret n° 2020-1610 et arrêtés DPE des 31 mars / 8 octobre 2021
- Code de la consommation (information précontractuelle)
- Code de la copropriété (loi du 10 juillet 1965, modifiée)
- Code de l'environnement, art. L125-5 (état des risques)
- Arrêté du 10 janvier 2017 (information honoraires)

> Ces sources sont **indicatives**. Le seed JSON du `regles_json` ne cite pas
> directement ces références : elles sont conservées dans le champ
> `source_reference` de la table `mbi_supports_mentions_versions`.

---

## 4. Mentions transverses (tout support, vente V1)

### 4.1 Bloc dur

| Code règle | Description | Champ source |
|---|---|---|
| `MANDAT_ACTIF` | Un mandat actif rattaché au bien (statut signé, période valide). | `mandats.statut`, `mandats.date_debut`, `mandats.date_fin` |
| `AUTORISATION_DIFFUSION` | Autorisation de diffusion signée par le mandant (ou statut équivalent). | `mandats.autorisation_diffusion` |
| `PRIX_DEFINI` | Prix > 0 renseigné. | `biens.prix_vente` |
| `SURFACE_DEFINIE` | Surface habitable > 0 renseignée. | `biens.surface_habitable` |
| `TYPE_BIEN_DEFINI` | Type de bien renseigné (appartement, maison, etc.). | `biens.id_type_bien` |
| `ADRESSE_VILLE` | Au minimum ville renseignée (l'adresse complète peut être masquée). | `biens.ville`, `biens.code_postal` |
| `HONORAIRES_RENSEIGNES` | Montant + charge (acquéreur/vendeur) explicites. | `biens.honoraires_*` |
| `AGENCE_RATTACHEE` | Agence rattachée valide. | `biens.id_agence` → `agences` |
| `NEGOCIATEUR_RATTACHE` | Négociateur rattaché valide. | `biens.id_user_negociateur` |
| `CARTE_PRO` | Numéro de carte professionnelle de l'agence renseigné et non expiré. | `agences.carte_pro_numero`, `agences.carte_pro_validite` |
| `PHOTO_EXPLOITABLE` | Au moins une photo exploitable (présente, non corrompue, dimensions ≥ seuil). | `bien_photos` |
| `DPE_STATUT_VALIDE` | `dpe_statut ∈ {present, en_cours, non_soumis}` — `manquant` est bloquant. | `biens.dpe_statut` |

### 4.2 Alertes

| Code | Description |
|---|---|
| `PHOTO_PRINCIPALE_SOMBRE` | Luminosité moyenne photo héro < seuil. |
| `PHOTOS_PEU_NOMBREUSES` | Moins de 5 photos exploitables. |
| `DESCRIPTION_FAIBLE` | Moins de 200 caractères de descriptif. |
| `DESCRIPTION_GENERIQUE` | Score IA "générique" élevé (peu de spécificité au bien). |
| `DPE_DEFAVORABLE_NON_TRAITE` | DPE classé E/F/G sans angle marketing dédié ou commentaire. |
| `PRIX_HORS_FOURCHETTE_MARCHE` | Prix > marché ±15 % (V2 — comparatif marché actif). |

---

## 5. Mentions par type de support

### 5.1 `affiche_vitrine`

**Bloc dur supplémentaire (en plus de §4.1)** :

| Code | Description |
|---|---|
| `AFFICHE_DPE_GES_CLASSE` | Étiquettes DPE et GES affichées (ou motif équivalent — voir §6). |
| `AFFICHE_PRIX_HONORAIRES` | Prix avec et sans honoraires + charge claire. |
| `AFFICHE_AGENCE_NOM` | Nom commercial de l'agence visible. |
| `AFFICHE_CARTE_PRO` | Numéro carte pro mentionné. |
| `AFFICHE_COPRO_LOTS` | Si copropriété → nombre total de lots de la copropriété. |
| `AFFICHE_COPRO_CHARGES` | Si copropriété → quote-part annuelle moyenne charges courantes. |
| `AFFICHE_COPRO_PROCEDURES` | Si copropriété → mention présence/absence procédures L611-1 ss. |

**Alertes** :

- `AFFICHE_QR_RECOMMANDE` (V2) : QR tracking absent.
- `AFFICHE_PHOTO_HERO_OBLIGATOIRE` : pas de photo héro choisie.

### 5.2 `fiche_client` (PDF remis aux acquéreurs potentiels)

Reprend tout ce qui est dans `affiche_vitrine`, avec ajouts :

| Code | Description |
|---|---|
| `FICHE_RISQUES_ERP` | État des risques et pollutions (ERP) joint ou mention "ERP disponible sur demande". |
| `FICHE_DPE_DETAIL` | Bloc complet DPE/GES (classe, étiquette, montant énergie min/max). |
| `FICHE_HONORAIRES_DETAIL` | Détail charge honoraires + mode de calcul. |
| `FICHE_AGENCE_COORDONNEES` | Adresse complète, téléphone, email, RCS, carte pro. |
| `FICHE_NEGOCIATEUR_COORDONNEES` | Nom, prénom, statut (salarié/agent commercial), email, téléphone. |
| `FICHE_MENTION_INFORMATION` | Information précontractuelle (loi consommation). |
| `FICHE_DROIT_RETRACTATION` | Mention droit de rétractation si vente conclue à distance. |

### 5.3 `fiche_visite_interne` (jamais diffusée)

| Code | Description |
|---|---|
| `INTERNE_FILIGRANE` | Filigrane "INTERNE — NE PAS DIFFUSER" sur chaque page. |
| `INTERNE_BADGE_ROUGE` | Badge rouge visible en en-tête. |
| `INTERNE_HORS_PUBLIC` | Verrou export : `is_interne=1` → téléchargement réservé rôles agence/admin. |
| `INTERNE_NOM_FICHIER` | Suffixe `_INTERNE` obligatoire dans `nom_fichier`. |
| `INTERNE_JOURNALISATION` | Journaliser chaque téléchargement (qui / quand). |

> Une fiche interne **n'a pas** à porter les mentions légales destinées au
> public (carte pro, ERP…) puisqu'elle n'est pas un support commercial diffusable.
> Elle reste néanmoins soumise au RGPD si elle contient des données nominatives.

### 5.4 `dossier_presentation` (prise de mandat — V3 prévu)

Bloc dur de prise de mandat (placeholder, à durcir en V3) :

- `DOSSIER_AGENCE_PRESENTATION`
- `DOSSIER_NEGOCIATEUR_PRESENTATION`
- `DOSSIER_STRATEGIE_DIFFUSION`
- `DOSSIER_HONORAIRES_BAREME`
- `DOSSIER_DUREE_MANDAT_PROPOSEE`

### 5.5 `email` (V3 prévu)

Bloc dur :

- `EMAIL_EXPEDITEUR_AGENCE` (signature complète, RCS, carte pro)
- `EMAIL_DESINSCRIPTION` (lien désinscription si prospection)
- `EMAIL_RGPD_MENTION` (mention finalité + droits)

### 5.6 `reseaux_sociaux` (V4 prévu)

À cadrer en V4 — règles plateforme + mentions condensées + lien vers fiche
complète obligatoire (les obligations légales restent dues même en publication
courte).

---

## 6. Mentions DPE / GES

### 6.1 Statut structuré

Le champ `biens.dpe_statut` (introduit par la migration `05`) prend l'une des
valeurs suivantes, qui pilotent le bloc dur :

| Statut | Bloc dur | Alerte | Mention obligatoire sur le support |
|---|---|---|---|
| `present` | Non | Si E/F/G non commenté | Étiquettes DPE+GES + valeurs |
| `en_cours` | Non | Toujours (rappel) | « DPE en cours de réalisation à la date d'édition du support — sera communiqué dès réception. » |
| `non_soumis` | Non | Toujours | « Bien non soumis au DPE en application de l'article R.126-15 du code de la construction et de l'habitation. » |
| `manquant` | **OUI** | — | Export bloqué tant que le statut n'est pas qualifié. |

### 6.2 Précisions

- Pour les biens classés F ou G ("passoires thermiques"), une **alerte
  qualitative** invite à traiter l'angle énergétique dans l'angle marketing
  (ex. travaux de rénovation déjà chiffrés, éligibilité MaPrimeRénov').
- Les valeurs énergétiques (kWh/m²/an, kgCO₂/m²/an) doivent être affichées
  pour le DPE et le GES sur l'`affiche_vitrine` et la `fiche_client`.

---

## 7. Mentions honoraires (vente V1)

### 7.1 Bloc dur

| Code | Description |
|---|---|
| `HONO_MONTANT` | Montant en €. |
| `HONO_CHARGE` | Charge clairement identifiée (acquéreur / vendeur / partagée). |
| `HONO_PRIX_HONO_INCLUS` | Prix de présentation = prix charges acquéreur incluses. Mention obligatoire. |
| `HONO_PRIX_HORS_HONO` | Si honoraires charge acquéreur, afficher aussi le prix hors honoraires. |
| `HONO_TVA_NON_APPLICABLE` | Pour les particuliers : pas de TVA additionnelle (mention claire). |

### 7.2 Alertes

- `HONO_INCOHERENCE_PRIX` : si `prix_total ≠ prix_net_vendeur + honoraires`.
- `HONO_BAREME_AGENCE` : si pas de barème agence accessible (lien ou mention "Barème consultable en agence et sur site internet").

---

## 8. Mentions copropriété (vente V1)

### 8.1 Bloc dur si bien en copropriété

| Code | Description |
|---|---|
| `COPRO_NB_LOTS` | Nombre total de lots de la copropriété. |
| `COPRO_QUOTE_PART_CHARGES` | Quote-part annuelle des charges courantes (€). |
| `COPRO_PROCEDURES_L611` | Mention présence ou absence de procédures L.611-1 et suivantes (administration provisoire, redressement…). |

### 8.2 Alertes

- `COPRO_TRAVAUX_VOTES` : alerte si travaux votés non communiqués sur le support.
- `COPRO_FONDS_TRAVAUX` : alerte si non mention du fonds de travaux ALUR (5 % min).

---

## 9. État des risques (ERP)

| Code | Niveau | Description |
|---|---|---|
| `RISQUES_ERP_DISPONIBLE` | Bloc dur sur `fiche_client` et `dossier_presentation` | ERP joint ou explicitement disponible sur demande. |
| `RISQUES_ZONE_SISMIQUE` | Alerte | Si zone sismique 3+, mention recommandée. |
| `RISQUES_ZONAGE_ARGILE` | Alerte | Si zone aléa argile fort, mention recommandée. |
| `RISQUES_INONDATION` | Alerte | Si plan inondation actif, mention recommandée. |

> Sur l'`affiche_vitrine`, l'ERP n'est pas affiché — un renvoi explicite suffit
> ("ERP disponible en agence").

---

## 10. Mandat & autorisation de diffusion

| Code | Niveau | Description |
|---|---|---|
| `MANDAT_TYPE` | Information | Le type de mandat (simple/exclusif/semi-exclusif) **n'est pas** une mention obligatoire sur les supports publics. Stocké en interne. |
| `MANDAT_NUMERO_REGISTRE` | Bloc dur sur `dossier_presentation` (V3) | Numéro de registre des mandats. |
| `AUTORISATION_DIFFUSION_PERIMETRE` | Bloc dur | Vérifier que les canaux de diffusion sont couverts par l'autorisation (vitrine / portails / RS). |

---

## 11. Carte professionnelle

| Code | Niveau | Description |
|---|---|---|
| `CARTE_PRO_NUMERO` | Bloc dur tous supports publics | Numéro complet (ex. CPI 1234 2025 000 123 456). |
| `CARTE_PRO_CCI` | Bloc dur tous supports publics | CCI émettrice. |
| `CARTE_PRO_VALIDITE` | Bloc dur | Date de validité non dépassée à la `date_generation` du support. |
| `CARTE_PRO_GARANT_FINANCIER` | Bloc dur tous supports publics | Mention du garant financier (loi Hoguet). |
| `CARTE_PRO_RC_PRO` | Bloc dur tous supports publics | Mention de l'assurance responsabilité civile professionnelle. |

---

## 12. [V2] Location — placeholder

Couverture prévue en V2 (non bloquante en V1) :

- Plafonds ALUR honoraires location (zone tendue, surface, cf.
  `feedback_honoraires_plafond_alur`).
- Encadrement des loyers (Paris, Lille, Lyon, Plaine commune…).
- Caution / dépôt de garantie : montant max selon nature.
- Diagnostics location (ERP, DPE, plomb, amiante, électricité, gaz).
- Mention "honoraires charge locataire" plafonnée + "honoraires bailleur"
  séparée.

> Quand le périmètre location sera couvert, une nouvelle version sera créée
> dans `mbi_supports_mentions_versions` avec `concerne_location = 1`.

---

## 13. Structure JSON cible (seed `regles_json`)

Le seed inséré en Lot 2 dans `mbi_supports_mentions_versions` aura cette
structure :

```json
{
  "version": "2026-05-01.v1",
  "transverse": {
    "bloc_dur": [
      { "code": "MANDAT_ACTIF",         "champ": "mandats.statut" },
      { "code": "AUTORISATION_DIFFUSION","champ": "mandats.autorisation_diffusion" },
      { "code": "PRIX_DEFINI",          "champ": "biens.prix_vente",          "predicat": "> 0" },
      { "code": "SURFACE_DEFINIE",      "champ": "biens.surface_habitable",   "predicat": "> 0" },
      { "code": "TYPE_BIEN_DEFINI",     "champ": "biens.id_type_bien",        "predicat": "not_null" },
      { "code": "ADRESSE_VILLE",        "champ": "biens.ville",               "predicat": "not_empty" },
      { "code": "HONORAIRES_RENSEIGNES","champs": ["biens.honoraires_montant","biens.honoraires_charge"] },
      { "code": "AGENCE_RATTACHEE",     "champ": "biens.id_agence" },
      { "code": "NEGOCIATEUR_RATTACHE", "champ": "biens.id_user_negociateur" },
      { "code": "CARTE_PRO",            "champs": ["agences.carte_pro_numero","agences.carte_pro_validite"] },
      { "code": "PHOTO_EXPLOITABLE",    "regle": "au_moins_une_photo_exploitable" },
      { "code": "DPE_STATUT_VALIDE",    "champ": "biens.dpe_statut", "predicat": "in:present,en_cours,non_soumis" }
    ],
    "alertes": [
      { "code": "PHOTO_PRINCIPALE_SOMBRE",    "regle": "luminosite_photo_hero_lt", "seuil": 0.35 },
      { "code": "PHOTOS_PEU_NOMBREUSES",      "regle": "nb_photos_lt", "seuil": 5 },
      { "code": "DESCRIPTION_FAIBLE",         "regle": "longueur_description_lt", "seuil": 200 },
      { "code": "DESCRIPTION_GENERIQUE",      "regle": "ia_score_generique_gt", "seuil": 0.7 },
      { "code": "DPE_DEFAVORABLE_NON_TRAITE", "regle": "dpe_in_E_F_G_sans_angle_dedie" }
    ]
  },
  "par_type_support": {
    "affiche_vitrine": {
      "bloc_dur": ["AFFICHE_DPE_GES_CLASSE","AFFICHE_PRIX_HONORAIRES","AFFICHE_AGENCE_NOM","AFFICHE_CARTE_PRO"],
      "bloc_dur_si_copro": ["AFFICHE_COPRO_LOTS","AFFICHE_COPRO_CHARGES","AFFICHE_COPRO_PROCEDURES"]
    },
    "fiche_client": {
      "bloc_dur": ["FICHE_RISQUES_ERP","FICHE_DPE_DETAIL","FICHE_HONORAIRES_DETAIL",
                   "FICHE_AGENCE_COORDONNEES","FICHE_NEGOCIATEUR_COORDONNEES",
                   "FICHE_MENTION_INFORMATION"]
    },
    "fiche_visite_interne": {
      "bloc_dur": ["INTERNE_FILIGRANE","INTERNE_BADGE_ROUGE","INTERNE_HORS_PUBLIC",
                   "INTERNE_NOM_FICHIER","INTERNE_JOURNALISATION"],
      "exonerations": "mentions_publiques_non_applicables"
    },
    "dossier_presentation": {
      "bloc_dur": ["DOSSIER_AGENCE_PRESENTATION","DOSSIER_NEGOCIATEUR_PRESENTATION",
                   "DOSSIER_STRATEGIE_DIFFUSION","DOSSIER_HONORAIRES_BAREME",
                   "DOSSIER_DUREE_MANDAT_PROPOSEE","MANDAT_NUMERO_REGISTRE"]
    },
    "email":            { "bloc_dur": ["EMAIL_EXPEDITEUR_AGENCE","EMAIL_DESINSCRIPTION","EMAIL_RGPD_MENTION"] },
    "reseaux_sociaux":  { "bloc_dur": [], "todo": "À cadrer V4" }
  },
  "specifiques": {
    "dpe_ges": {
      "matrice_statut": {
        "present":     { "bloc_dur": false, "alerte_si_E_F_G_non_traite": true },
        "en_cours":    { "bloc_dur": false, "mention_obligatoire": "dpe_en_cours" },
        "non_soumis":  { "bloc_dur": false, "mention_obligatoire": "dpe_non_soumis_R126_15" },
        "manquant":    { "bloc_dur": true,  "code": "DPE_STATUT_VALIDE" }
      }
    },
    "honoraires": {
      "bloc_dur": ["HONO_MONTANT","HONO_CHARGE","HONO_PRIX_HONO_INCLUS","HONO_PRIX_HORS_HONO","HONO_TVA_NON_APPLICABLE"],
      "alertes":  ["HONO_INCOHERENCE_PRIX","HONO_BAREME_AGENCE"]
    },
    "copropriete": {
      "bloc_dur_si_en_copro": ["COPRO_NB_LOTS","COPRO_QUOTE_PART_CHARGES","COPRO_PROCEDURES_L611"],
      "alertes":               ["COPRO_TRAVAUX_VOTES","COPRO_FONDS_TRAVAUX"]
    },
    "risques": {
      "bloc_dur_par_support": { "fiche_client": ["RISQUES_ERP_DISPONIBLE"], "dossier_presentation": ["RISQUES_ERP_DISPONIBLE"] },
      "alertes":               ["RISQUES_ZONE_SISMIQUE","RISQUES_ZONAGE_ARGILE","RISQUES_INONDATION"]
    },
    "mandat_diffusion": {
      "bloc_dur": ["AUTORISATION_DIFFUSION_PERIMETRE"]
    },
    "carte_pro": {
      "bloc_dur_supports_publics": ["CARTE_PRO_NUMERO","CARTE_PRO_CCI","CARTE_PRO_VALIDITE",
                                    "CARTE_PRO_GARANT_FINANCIER","CARTE_PRO_RC_PRO"]
    }
  },
  "perimetre": {
    "concerne_vente": true,
    "concerne_location": false,
    "concerne_copro": true
  }
}
```

---

## 14. Workflow d'application

1. Le moteur critique (`inc/mbi_supports_critic_engine.php`) charge la version
   active de `mbi_supports_mentions_versions` (`actif = 1`).
2. Il itère le bloc dur **transverse** + les blocs durs du **type de support**
   demandé + les blocs spécifiques (copro, DPE, honoraires…) selon la nature
   du bien.
3. Toute règle de bloc dur non satisfaite → export refusé, message clair à
   l'utilisateur indiquant **quelle** mention manque et **où la corriger**.
4. Les alertes sont remontées dans une zone visible mais n'empêchent pas
   l'export — l'utilisateur valide explicitement avant génération du PDF.
5. La `mentions_version` utilisée est inscrite sur le support généré
   (`mbi_supports_commerciaux.mentions_version`) → un support produit en mai
   reste audité avec ses règles d'origine, même si une nouvelle version est
   activée plus tard.

---

## 15. Points à valider juridiquement (avant `valide_juridiquement = 1`)

- ✅ / ❌ Liste exhaustive des mentions DPE pour chaque support.
- ✅ / ❌ Formulation exacte des mentions copropriété (lots / charges / procédures L611-1).
- ✅ / ❌ Honoraires : formulation des prix avec/sans honoraires.
- ✅ / ❌ Carte professionnelle : composition exacte de la mention.
- ✅ / ❌ ERP : seuil de mention obligatoire vs disponible sur demande selon support.
- ✅ / ❌ Mentions assurance / garant financier : formulation conforme.
- ✅ / ❌ Périmètre RGPD pour la fiche visite interne (données nominatives prospects).

> Toute modification après validation juridique → nouvelle version
> (ex. `2026-05-01.v2`), pas de mutation in-place de `regles_json`.
