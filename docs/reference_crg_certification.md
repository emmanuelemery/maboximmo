# RÉFÉRENTIEL DE CERTIFICATION DU LECTEUR CRG

> **Ce fichier est la doctrine officielle du lecteur CRG.**
> Le code applique la règle · ce référentiel explique la règle · les tests prouvent la règle.
> Les trois doivent rester cohérents. Si le code et le référentiel divergent, **c'est un défaut à signaler**, pas un écart à tolérer.

Une phase n'est **jamais** certifiée parce que mes tests sont verts. Elle le devient **uniquement**
après la phrase « Emmanuel valide la Phase X ». Tant qu'elle n'est pas prononcée, la phase reste
🟠 EN COURS, quels que soient les résultats.

| Phase | Objet | Statut |
|---|---|---|
| **P1 — GED** | source, périmètre, période, nommage, page — **4 périmètres, GROUPE SIR compris** | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **P2 — PROPRIÉTAIRES** | identité des propriétaires ↔ TIERS / PROPRIÉTAIRE MBI — **4 périmètres** | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **P3A — COMPTES MANDANTS** | reconnaissance durable du compte au fil des CRG | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **P3B — IMMEUBLES** | propriétaire → compte → immeuble, stable entre périodes | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **P3C — LOTS / BIENS** | … → lot / bien, sans duplication | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **MOTEUR — INTÉGRATION DEPUIS ZÉRO** | idempotence · accumulation · stabilité · ordre | ⚙ **TEST TECHNIQUE TRANSVERSAL** — jamais une phase métier |
| **P4A — LOCATAIRES** | qui — identité, sans occupation | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **P4B — OCCUPATION** | qui occupait quoi, et quand | 🟢 **CERTIFIÉE / FIGÉE** — 30/08/2026 |
| **P5A — APPELS** | ce qui a été appelé au locataire | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P5B — ENCAISSEMENTS** | ce qui a réellement été réglé | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P6A — CRÉANCES / ENCOURS** | quel stock reste dû, par qui, à quelle date | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P6B — ANTÉRIORITÉ / REPORTS** | d'où vient ce stock et de quand il date | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P7A — DÉPENSES** | ce que le propriétaire supporte sur le bien | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P7B — FRAIS / ASSURANCES** | ce que la régie prélève et ce qui est assuré | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P8A — FLUX PROPRIÉTAIRE** | ce qui circule entre la régie et le propriétaire | 🟢 **CERTIFIÉE / FIGÉE** — 31/08/2026 |
| **P8B — COMPTABILITÉ / SOLDE** | la situation du compte mandant, telle que le CRG la démontre | 🟢 **CERTIFIÉE / FIGÉE** — 01/09/2026 |
| **P9 — RENTABILITÉ / ANALYTIQUE** | ce que les données certifiées permettent de mesurer sur la performance, et à quelle maille | 🟢 **CERTIFIÉE / FIGÉE** — 01/09/2026 |
| **MODULE D'INTÉGRATION** | lire un dépôt réel et le confronter à MBI, sans rien y écrire | 🟠 **EN CONSTRUCTION** — phases 0 à 2 livrées le 01/09/2026 |
| **P10** | à ouvrir | ⬜ non commencée |

Chaque règle porte son origine (`P1-GED-…`, `P2-PROP-…`) : on doit toujours savoir **quand et
pourquoi** elle a été introduite.

---

# PHASE 1 — GED / SOURCE / PÉRIODE / PAGE 🟢

**Date de certification : 30/08/2026 — VALIDÉ PAR EMMANUEL.**

## A. Objet de la phase

Certifier la **source documentaire** avant toute analyse métier : que le corpus de CRG soit
exhaustif et sans doublon, que chaque document ait une identité GED unique et stable, et que
chaque information extraite sache de quel PDF et de quelle page elle vient.

## B. Règles métier certifiées

| Réf. | Règle | Statut |
|---|---|---|
| **P1-GED-01** | L'agence se détermine **par l'en-tête du PDF**, jamais par le dossier Windows. Un CRG LYON rangé chez EMERY reste un CRG LYON. | CERTIFIÉE |
| **P1-GED-02** | Le **format** se lit dans l'en-tête de l'agence ; la **nature** du document se lit dans son `COMPTE PERSONNEL`. Un document sans compte personnel n'est pas un CRG (avis d'acompte, balance, extrait). | CERTIFIÉE |
| **P1-GED-03** | **LYON = CRG trimestriel.** Le document nomme son trimestre. | CERTIFIÉE |
| **P1-GED-04** | **EMERY = CRG trimestriel**, plus quelques relevés datés hors trimestre. | CERTIFIÉE |
| **P1-GED-05** | **VIENNE (SEPTEO SPI) = CRG MENSUEL.** Le rattacher à un trimestre ne le transforme pas en document trimestriel. | CERTIFIÉE |
| **P1-GED-06** | **`periode_cle` = identité documentaire** (`2026-T1`, `2026-04`, `2026-03-15`, `2026-05-13_2026-06-30`). C'est elle qui nomme le fichier et distingue deux documents. | CERTIFIÉE |
| **P1-GED-07** | **`trimestre_cle` = rattachement analytique**, toujours renseigné (`2026-T2`). C'est lui qui groupe, compare et historise. | CERTIFIÉE |
| **P1-GED-08** | **`date_arrete` = date du stock**, métadonnée complémentaire. Elle date un solde ou un encours, **jamais** le document. | CERTIFIÉE |
| **P1-GED-09** | Le trimestre se prend **sur la clôture, jamais sur le début**. | CERTIFIÉE |
| **P1-GED-10** | **Une période débordante se conserve.** `periode_debordante = true` signale un relevé qui rattrape des mois antérieurs ; l'information n'est jamais effacée. | CERTIFIÉE |
| **P1-GED-11** | **STOCK ≠ FLUX.** Qu'un CRG soit rattaché à `2026-T2` ne signifie pas que tout son contenu financier appartient à ce trimestre. | CERTIFIÉE |
| **P1-GED-12** | **PDF + SHA + page = provenance documentaire.** Toute donnée extraite doit pouvoir y revenir. | CERTIFIÉE |
| **P1-GED-13** | **Un montant calculé n'est pas un montant lu.** Il doit rendre ses composants, chacun avec sa page. | CERTIFIÉE |
| **P1-GED-14** | Deux CRG de même compte, période et solde ne sont **pas** des doublons s'ils décrivent un contenu métier différent. La comparaison porte sur les **lots, locataires et totaux** — jamais sur `date_arrete`, qui peut être mal lue. | CERTIFIÉE |
| **P1-GED-15** | Une **copie SHA stricte** ne crée jamais une seconde pièce GED. Une **réédition** est identifiée comme telle, jamais supprimée en silence. | CERTIFIÉE |
| **P1-GED-16** | **SIR n'est pas une exclusion, c'est un périmètre.** Les mêmes règles s'y appliquent ; le résultat s'additionne. | CERTIFIÉE |
| **P1-GED-17** | Ce que la Phase 1 démontre est une **« identité source disponible et pageable pour futur rattachement MBI »** — jamais un rattachement certifié. | CERTIFIÉE |
| **P1-SIR-01** | Une **ligne décorative ou de circonstance ne désigne personne**. Sur les relevés du 4ᵉ trimestre, un bandeau « ******** BONNES FETES DE FIN D'ANNEE ********* » s'intercale avant la raison sociale : 4 CRG portaient les vœux de l'agence comme propriétaire. On écarte sur la **forme** — rappel de période, titre de relevé, ≥3 caractères `*=_-~.`, moins de trois lettres — jamais sur une liste de mots. | CERTIFIÉE |
| **P1-SIR-02** | Le trimestre peut être **nommé dans le titre du document et nulle part ailleurs**, avec `ex` intercalé : « - Compte de Gestion 2e Trimestre ex 2026 - ». Motif dédié, et non élargissement du motif général — un motif large attraperait « report du 1er trimestre 2026 » dans le corps. | CERTIFIÉE |
| **P1-SIR-03** | Un CRG peut n'être **ni trimestriel ni mensuel**. Quand un mandat entre ou sort en cours de trimestre, l'en-tête imprime sa période propre : « CRG du 13.05.26 au 30.06.26 ». C'est le `periode_type = 'periode'`, clé `2026-05-13_2026-06-30`, rattaché à son trimestre de clôture. Champ distinct (`crg_periode_debut/fin`) de celui de SEPTEO : les confondre transformerait les relevés débordants de VIENNE en documents pluri-mensuels. | CERTIFIÉE |
| **P1-SIR-04** | Un document au format d'une agence **n'est pas forcément un CRG**. « Information Acompte / Virement acompte » porte l'en-tête LOCA IMMO sans `COMPTE PERSONNEL` : c'est un NON_CRG, il n'entre pas dans les références. Application directe de P1-GED-02. | CERTIFIÉE |

## C. Règles techniques certifiées

**Lecteurs — ÉDITEUR → MOTEUR → VARIANTE.** *Taxonomie rectifiée le 02/09/2026 : une agence n'est pas un format. Le parc n'utilise que **deux logiciels**, et les agences se répartissent dessus. Aucune règle de lecture n'a changé — seule leur organisation.*

| Éditeur | Moteur | Variante | Agence | `parser_version` |
|---|---|---|---|---|
| **ICS** | `crg_ics_core.py` | `lyon` | LOCA IMMO LYON | `geometric-1.0` |
| **ICS** | `crg_ics_core.py` | `emery_immo` | EMERY IMMO succ. SERVAJEAN (RIOM · CHAMALIÈRES) | `emery-1.0` |
| **SPI** | `parse_crg_septeo.py` | — | VIENNE · CHAPONOST *(aucun CRG fourni à ce jour)* | — |

**Traçabilité des certifications historiques** : `parse_crg_geo.py` et `parse_crg_emery.py` **existent toujours**, avec le même nom, la même fonction `parse(chemin)` et la même ligne de commande — ce sont désormais les **adresses** des deux variantes ICS. Toute certification LYON ou EMERY antérieure au 02/09/2026 reste donc traçable jusqu'à son lecteur, et les `parser_version` inscrites dans les 517 extractions ne bougent pas.

**Pourquoi la factorisation** : les deux lecteurs ICS portaient **exactement les mêmes douze fonctions**, aucune propre à l'un seul, et **96 lignes sur 2 054** les séparaient — 95 % de code forké. Une correction du fonctionnement commun devait être faite deux fois. Les quatre divergences réelles ont été inventoriées une par une : trois sont des **variantes documentaires démontrées** (déclarées dans `crg_ics_core.VARIANTES`, chacune avec l'incident qui l'a fait naître), la quatrième s'est révélée **sans effet** sur LYON et est donc devenue commune.

**Reconnaissance** — `crg_format.famille_du_texte` est l'**autorité unique**, partagée par `crg_depot_lire.py` (routeur) et `crg_integration_phase0.py` (intégrateur). Elle interroge **l'enseigne avant la structure** : LYON et EMERY impriment tous deux « COMPTE RENDU DE GESTION » et « COMPTE PERSONNEL », et tester la structure d'abord rangeait 224 CRG EMERY certifiés dans la famille LYON.

**Partagé** — `crg_periode.py` (contrat de période : recopié trois fois, il aurait dérivé trois fois).

| Réf. | Règle technique | Statut |
|---|---|---|
| **P1-TEC-01** | Tout lecteur appelé en sous-processus écrit son JSON en **`ensure_ascii=True`**, branche d'erreur comprise. Sous Windows la sortie d'un fils est en cp1252 : « ANDRÉ » y revenait `ANDR\ufffd`. Côté appelant : décoder en UTF-8 `errors='replace'`, se rabattre sur cp1252. | CERTIFIÉE |
| **P1-TEC-02** | Une identité (mandant, propriétaire) se repère **par sa position géométrique ou sa forme**, jamais par son rang dans le texte. Une ligne intercalaire suffit à décaler « la ligne d'après ». | CERTIFIÉE |
| **P1-TEC-03** | Ne désignent personne : un rappel de période, un titre de relevé, une ligne décorative (≥3 caractères `*=_-~.`), une ligne de moins de trois lettres. | CERTIFIÉE |
| **P1-TEC-04** | Une référence de lot peut contenir des espaces (`Lot 4000 - 06`) ; chaque segment est élagué. | CERTIFIÉE |
| **P1-TEC-05** | Chez SEPTEO la période d'un appel est **dans le libellé**, sous deux formes : « Loyer Avril 2026 » et « Loyer du 01/04/2026 au 30/06/2026 ». | CERTIFIÉE |
| **P1-TEC-06** | Une ligne « Solde du dernier Rapport au … » n'est **pas** un appel de loyer. | CERTIFIÉE |
| **P1-TEC-07** | Chaque objet extrait porte sa page : `page` (lot, immeuble, appel, charge, mouvement, opération, solde, report, en-tête) ou `page_debut`/`page_fin` (immeuble LYON/EMERY). | CERTIFIÉE |
| **P1-TEC-08** | **Nommage GED, escalade minimale** : `CRG - PROPRIETAIRE - COMPTE - PERIODE` → `+ IMMEUBLE` → `+ LOT` → `+ ARRETE`. Un niveau ne se déclenche que sur **collision avérée**. Jamais de `(1)`, `(2)` : un suffixe arbitraire dépend de l'ordre de traitement. | CERTIFIÉE |
| **P1-TEC-09** | Le nom GED est **déterministe, ASCII, sans caractère interdit** `\ / : * ? " < > \|`, et le nom original reste conservé en métadonnée. | CERTIFIÉE |
| **P1-TEC-10** | Un total agence n'existe **qu'à date homogène** : sinon `TOTAL AGENCE NON CALCULABLE À DATE HOMOGÈNE`. | CERTIFIÉE |

## D. Chiffres de référence

Corpus figé au 30/08/2026, **quatre périmètres** :

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR | **Total** |
|---|---:|---:|---:|---:|---:|
| CRG de référence | 117 | 224 | 70 | 47 | **458** |
| Immeubles | 191 | 229 | 79 | 439 | **938** |
| Lots | 287 | 384 | 111 | 1 175 | **1 957** |
| Locataires nommés | 287 | 384 | 110 | 1 175 | **1 956** |
| Total dû | 1 478 826,95 € | 666 892,52 € | 71 605,80 € | 10 929 244,94 € | — |
| Réglé | 526 183,29 € | 522 408,16 € | 60 998,99 € | 3 368 099,00 € | — |
| Périodes certaines | 117/117 | 224/224 | 70/70 | 47/47 | **458/458** |
| Noms GED distincts | 117 | 224 | 70 | 47 | **458** |
| Couverture temporelle | 2026-T1→T2 | 2026-T1 | 2026-04 | 2025-T1→2026-T2 | |

⚠️ **Les colonnes « total dû » et « réglé » sont des compteurs de non-régression, pas des chiffres de gestion.** Elles servent à détecter une dérive de lecture. Un total inter-agences n'est pas produit, et **l'encours n'est pas totalisé** : GROUPE SIR couvre six trimestres et LYON deux — les additionner sommerait un stock entre périodes, ce qu'interdit P1-GED-11.

⚠️ **LE PÉRIMÈTRE CRG N'EST PAS LE PORTEFEUILLE MBI, ET C'EST NORMAL.** Un périmètre CRG peut recouvrir deux agences MBI — « EMERY IMMO » couvre RIOM (5, 135 comptes) **et CHAMALIERES** (6, 87 comptes, `gestion_active=0`, invisible des filtres). Et une agence MBI peut n'avoir aucun CRG : **CHAPONOST (agence 4, 204 immeubles, 261 biens, 29 mandats) n'en a aucun — ses CRG n'ont jamais été fournis. Ne pas les chercher.** Filtrer un rapprochement sur une seule agence par périmètre écartait tout CHAMALIERES et le déclarait « nouveau ».

**Inventaire physique** : 506 PDF = 458 références + copies SHA strictes + rééditions + NON_CRG, chaque catégorie dénombrée par périmètre.

**Homogénéité des dates** : VIENNE 61/61 comptes au 30/04/2026 ✅ · EMERY 222/224 au 31/03 (99,1 %) ❌ · LYON 59/60 au 31/03 (98,3 %) et 58/60 au 30/06 ❌.

## E. Exceptions connues

| Réf. | Exception |
|---|---|
| **P1-EXC-01** | **2 CRG EMERY hors trimestre** — « CRG au 15.02.2026 » et « au 15.03.2026 ». Type `arrete`, rattachés à `2026-T1`. |
| **P1-EXC-02** | **6 relevés VIENNE à période débordante**, dont un du 25/06/2025 au 30/04/2026. Type `mois`, `2026-04`, `periode_debordante = true`. |
| **P1-EXC-03** | **1 lot VIENNE sans locataire imprimé** (lot 190, compte 1105404745) : ligne de report, pas une situation locative. |
| **P1-EXC-04** | **84 des 110 noms de locataires VIENNE sont imprimés espaces collés** (`MILLERDEOLIVEIRAouBILLON…`). Lus, non perdus — mais tout rapprochement futur sur le nom exigera une normalisation. |
| **P1-EXC-05** | **4 références existent en deux fichiers de noms différents** (même SHA) : une seule pièce GED. |
| **P1-EXC-06** | **`acompte au propriétaire` absent chez VIENNE** : le format SEPTEO mensuel n'itemise pas le versement au bailleur, il imprime « Solde de votre compte » (70/70). Ce n'est pas un manque du lecteur. |
| **P1-EXC-07** | **« Solde Antérieur » EMERY — information correctement lue et pageable, QUALIFICATION FINANCIÈRE NON CERTIFIÉE.** Le PDF imprime 103 lignes « Solde Antérieur » (140 272,82 €) dans la colonne *Divers* ; le lecteur les restitue à l'euro près, avec leur page. **La classification en *Divers* n'est PAS validée** et ne doit être invoquée nulle part comme certifiée. La nature de ce montant relève de la **phase dédiée à l'antériorité / au report**, pas de la Phase 1 — qui certifie que l'information existe, est correctement retrouvée et traçable à sa source, et rien d'autre. |
| **P1-EXC-08** | **Un NON_CRG peut porter l'en-tête d'une agence** : « Information Acompte / Virement acompte » (LOCA IMMO, sans `COMPTE PERSONNEL`). Écarté des références par P1-SIR-04. |

## F. Limites — ce que la Phase 1 ne certifie PAS

- **Aucun rapprochement avec les objets MBI** (tiers, propriétaires, immeubles, biens, baux). La phase certifie que l'identité source existe et est pageable, **pas** qu'elle correspond à un objet MBI.
- **Aucune certification métier des comptes mandants.**
- **Aucune interprétation financière** : ni rentabilité, ni encours agrégé, ni comparaison inter-période.
- **Aucun écran, aucune écriture en base.**

## G. Preuves / tests reproductibles

```
php  public_html/scripts/crg_non_regression.php      (ou python crg_non_regression.py)
```

| Test | Ce qu'il prouve |
|---|---|
| `tests_crg/p1_nonreg.py` | Compteurs et montants inchangés, agence par agence |
| `tests_crg/p1_periodes.py` | 411/411 périodes certaines, 0 anomalie, 411 noms GED distincts |
| `tests_crg/p1_tracabilite.py` | Page source conservée pour les 16 types d'information |
| `tests_crg/p1_echantillon_pdf.py` | La période lue est celle que le PDF imprime, un échantillon par cas |

Le **périmètre certifié** est versionné dans `tests_crg/corpus/` (SHA par SHA). Le corpus relu se régénère depuis les PDF vers `C:\tmp\p1_json`.

## H. Statut

**🟢 CERTIFIÉE et FIGÉE le 30/08/2026 — VALIDÉ PAR EMMANUEL.**

Le périmètre GROUPE SIR y a été **ajouté et validé le 30/08/2026** : ses règles P1-SIR-01 à P1-SIR-04 et l'exception P1-EXC-08 font partie intégrante de la Phase 1 certifiée. Le périmètre certifié comprend désormais **LYON hors SIR + EMERY IMMO + VIENNE + GROUPE SIR**.

Une phase ultérieure peut **exploiter** ces règles, jamais les modifier en silence. Si elle démontre qu'une règle certifiée est fausse : **STOP**, signaler `RÉGRESSION / REMISE EN CAUSE PHASE 1` avec ancienne règle, nouvelle observation, preuve PDF, impact et proposition — puis attendre la décision d'Emmanuel.

---

# PHASE 2 — PROPRIÉTAIRES 🟢

**Date de certification : 30/08/2026 — VALIDÉ PAR EMMANUEL.**

**Objet** : pour chaque propriétaire imprimé sur un CRG, décider s'il faut réutiliser un TIERS et un PROPRIÉTAIRE MBI existants, rattacher une fiche à un TIERS existant, créer une identité nouvelle, ou demander un arbitrage — **sans doublon, sans fusion abusive, sans perte de traçabilité**.

**Simulation uniquement.** Base **locale** `maboximmo` en lecture seule. Aucun INSERT, UPDATE ou DELETE.

## Règles certifiées

| Réf. | Règle | Statut |
|---|---|---|
| **P2-PROP-01** | Le **compte personnel imprimé sur le CRG** se retrouve dans `proprietaires.code_compte` (348/393 remplis, 8 caractères pour LYON/EMERY, 10-11 pour VIENNE). C'est le rapprochement le plus discriminant disponible — mais **jamais seul** : il doit être corroboré par le nom. Il ne certifie pas le compte mandant, qui a sa propre phase. | CERTIFIÉE |
| **P2-PROP-02** | **On ne crée jamais une fiche propriétaire sur un compte qui en porte déjà une**, même sous un libellé faux. Sans ce garde-fou, 3 identités VIENNE auraient fabriqué un doublon à côté d'une fiche existante mal nommée. | CERTIFIÉE |
| **P2-PROP-03** | **Un sixième cas existe, absent du modèle initial** : la fiche PROPRIÉTAIRE est présente et certaine, mais **rattachée à aucun TIERS** (`id_tiers IS NULL`). 112 des 393 fiches MBI sont dans cet état ; 100 de nos identités source y tombent. Ce n'est ni A, ni B, ni C : c'est `REUTILISER_PROPRIETAIRE_RATTACHER_TIERS`. | CERTIFIÉE |
| **P2-PROP-04** | **La nature juridique interdit la fusion inter-nature.** Une SCI n'est jamais une personne physique, une indivision jamais un indivisaire. Seule tolérance : couple ↔ personne physique, parce que MBI modélise « MR & MME SABY » en un seul tiers. | CERTIFIÉE |
| **P2-PROP-05** | Un libellé MBI **commençant par un chiffre** est presque toujours une adresse rangée dans un champ de nom : on le signale, on ne le compare pas sérieusement. | CERTIFIÉE |
| **P2-PROP-07** | **La forme juridique n'appartient pas au nom.** « SCI CB FINANCES » est la SCI `CB FINANCES` ; « SCI FILJAE » est `FILJAE`. La forme va dans `forme_juridique`, la raison sociale dans son champ. Sans cette règle, la SCI et sa raison sociale se comparaient comme deux natures incompatibles. | CERTIFIÉE |
| **P2-PROP-08** | **Une personne physique imprimée sur le compte d'une société en est le REPRÉSENTANT, jamais le propriétaire.** « M. Salim BARKATI » est le gérant de `SCI M.A.N.S. IMMOBILIERE` ; « SCI DUMAZ 100 ECLAIRS » agglutine la forme, le gérant (`DUMAZ`) et la raison sociale (`100 ECLAIRS`). Le représentant a sa place dans `tiers_contacts`. | CERTIFIÉE |
| **P2-PROP-09** | **Une concordance de compte ne se jette jamais sur un score de nom.** Le seuil écartait « M. et Mme SABY Yves et Christelle » face à la fiche « MR & MME SABY » du **même** compte 01460000 — un mot commun sur trois — et renvoyait vers un homonyme d'un autre compte. Un compte qui concorde produit toujours un candidat : il emporte la décision ou part en arbitrage, jamais à la poubelle. | CERTIFIÉE |
| **P2-PROP-10** | **Un arbitrage rendu ne se recalcule pas.** Il est écrit avec son motif et prime sur toute heuristique — c'est ce qui rend la décision relisible dans six mois : *« pourquoi ce propriétaire est-il le TIERS #5 ? Parce qu'Emmanuel l'a tranché le 30/08/2026, et voici sa phrase. »* | CERTIFIÉE |
| **P2-PROP-06** | La normalisation (casse, accents, espaces, ponctuation) sert **à trouver, jamais à fusionner**. Le libellé source du CRG est conservé à l'octet près. Le fuzzy ne produit que des **candidats**. | CERTIFIÉE |

## Exception certifiée

| Réf. | Exception | Statut |
|---|---|---|
| **P2-EXC-01** | **9 fiches propriétaires MBI portent un nom faux, de DEUX causes distinctes.** **(a) huit du lecteur SEPTEO** — des adresses (« 37 Rue du buisset », « 71 Rue Edouard Girerd », « 7 Rue du 11 Novembre », « 25, Route de Bérardier », « 2 Place Pierre Semard », « Apt 10 C Résidence les jardins »), un fragment (« Luc, Consolaci »), un libellé aberrant (« Ambassade de France »). **(b) une du lecteur EMERY** — `#1244 « - 1er Trimestre 2026 - »`, compte 01510000 agence 5, créée le 03/06/2026 : c'est le CRG **LYON** mal rangé dans le dossier EMERY (P1-GED-01) et lu par le mauvais lecteur, qui a pris le rappel de période pour la raison sociale. Toutes ont le bon `code_compte` et aucun `id_tiers`. **Extension consignée le 30/08/2026 : elle n'invalide aucune règle P2 et ne modifie aucun arbitrage** — `#1244` n'était visée par aucun, et `P2-PROP-05` (libellé commençant par un chiffre = adresse) reste vraie, elle ne couvrait simplement pas ce libellé-ci. | CERTIFIÉE — correction en base à faire ultérieurement |

## Arbitrages rendus par Emmanuel le 30/08/2026

| Identité CRG | Décision | Motif |
|---|---|---|
| M. et Mme SABY Yves et Christelle | tiers #5 / fiche #11 | « Mr et Mme sont différents que Yves SABY » — le couple est une identité distincte |
| SCI CB FINANCES | fiche #1326, raison sociale `CB FINANCES`, forme `SCI` | « enlève la civilité pour la mettre au bon endroit » |
| SCI FILJAE | fiche #244, raison sociale `FILJAE`, forme `SCI` | « filjae = sci = civilité » |
| M. Salim BARKATI | fiche #1395 `SCI M.A.N.S. IMMOBILIERE` ; BARKATI = **gérant** | « Salim est le gérant de SCI M.A.N.S » |
| Madame HERR SOPHIE | fiche #1331 existante, rattacher compte 02210000 | A — la fiche MBI **est** cette personne |
| Madame TAVIER LILIANE | fiche #1330 existante, rattacher compte 02110000 | A — idem |
| SCI DUMAZ 100 ECLAIRS | fiche #1156, raison sociale `SCI 100 ECLAIRS` ; DUMAZ = **gérant** | « c'est le gérant de la sci eclair » |
| GPE IMMO DR | tiers #3, fiches #9 #21 #292 #293 **groupées** | « le même propriétaire avec des articulations différentes du nom » |
| Les 8 fiches VIENNE (P2-EXC-01) | fiche conservée, **renommée** d'après le CRG | « vienne OK sur tout » |

⚠️ **`Monsieur TAVIER REMI` (01420000) est une création réelle**, distincte de `Madame TAVIER LILIANE` : le patronyme commun n'a pas provoqué de fusion.

## D. Chiffres de référence

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR |
|---|---:|---:|---:|---:|
| Occurrences propriétaire | 117 | 224 | 70 | 47 |
| Correctement extraites | **117/117** | **224/224** | **70/70** | **47/47** |
| Identités source distinctes | 60 | 198 | 61 | 14 |
| Créations de TIERS évitées | 53 | 196 | 52 | 13 |
| Nouveaux TIERS nécessaires | 1 | 1 | 1 | 0 |
| Cas à arbitrer | 6 | 1 | 8 | 1 |

## Limites — ce que la Phase 2 ne certifie PAS

- **Aucune écriture en base.** Les décisions sont simulées ; rien n'est créé, fusionné ni renommé.
- **Les comptes mandants ne sont pas certifiés.** `proprietaires.code_compte` a servi d'**indice de rapprochement** ; sa certification métier appartient à la Phase 3.
- **L'état de MBI n'est pas figé.** Le rapprochement dépend de `tiers` et `proprietaires`, qui bougent légitimement. Le harnais fige ce qui appartient au lecteur et au corpus — l'identité que la source imprime — jamais la sortie du rapprochement, sous peine de passer au rouge sur une modification normale de la base.

## Restes à traiter — ne bloquent pas la certification

| Réf. | À faire | Quand |
|---|---|---|
| **P2-RESTE-01** | Grouper les 4 fiches GROUPE SIR (#9, #21, #292, #293) sur le tiers #3. **Aucune fusion automatique.** | ultérieurement |
| **P2-RESTE-02** | Corriger en base le nom des 8 fiches VIENNE de `P2-EXC-01`. Le bon nom est certifié, page à l'appui ; l'écriture reste à faire. | ultérieurement |

## G. Preuves / tests reproductibles

```
python public_html/scripts/crg_non_regression.py     → P1 + P2
php    public_html/scripts/tests_crg/p2_rapprocher.php   → rejoue le rapprochement MBI
```

| Test | Ce qu'il prouve |
|---|---|
| `tests_crg/p2_certification.py` | 458/458 propriétaires extraits et intacts · occurrence ≠ identité · nature juridique stable · arbitrages toujours versionnés |
| `tests_crg/p2_source_pdf.py` | Relit les 458 PDF et confronte le nom retenu au texte imprimé de sa page (lent, à la demande) |
| `tests_crg/arbitrages/p2_proprietaires.json` | **Les décisions d'Emmanuel, versionnées** — aucun calcul ne doit pouvoir les écraser |

## H. Statut

**🟢 CERTIFIÉE et FIGÉE le 30/08/2026 — VALIDÉ PAR EMMANUEL.**

Ses contrôles sont désormais une **non-régression obligatoire** pour toutes les phases suivantes.

---

# BLOC 3 — STRUCTURE DE GESTION 🟢

**Date de certification : 30/08/2026 — VALIDÉ PAR EMMANUEL.**

Les trois sous-phases sont certifiées **séparément** : regrouper l'exécution n'a jamais voulu dire regrouper la certification. Aucune écriture MBI n'a été faite.

## P3A — COMPTES MANDANTS

| Réf. | Règle | Statut |
|---|---|---|
| **P3A-COMPTE-01** | **Un compte mandant est unique PAR AGENCE, pas globalement.** `02200000` est CREMER Lisa en agence 5 **et** SCI KIBLEPI LEVRAULT en agence 3. La clé est `(agence, code_compte)`. Vérifié : aucune décision de Phase 2 n'était fausse — le nom avait discriminé à 100 % — mais c'était par chance, pas par construction. | CERTIFIÉE |
| **P3A-COMPTE-02** | **Le compte est un périmètre de gestion, pas une identité.** Un propriétaire peut en porter plusieurs (25 cas, jusqu'à 3). Deux comptes ne se fusionnent **jamais** au motif qu'ils appartiennent au même TIERS. | CERTIFIÉE |
| **P3A-COMPTE-03** | **Les zéros initiaux font partie du compte.** `01880000` n'est pas `1880000`. Longueurs constatées : 8 caractères (LYON, EMERY, SIR), 10-11 (VIENNE). | CERTIFIÉE |
| **P3A-COMPTE-04** | **MBI porte une fiche PROPRIÉTAIRE par COMPTE**, pas par identité — c'est le modèle attendu. Mais chaque fiche a reçu **son propre TIERS** : `DA CUNHA Manuela` en a 2, `SAINT ROCH Marie Francoise` 3. **24 identités portent 50 tiers : 26 sont en trop.** Le TIERS est censé être l'identité globale. | CERTIFIÉE |

## P3B — IMMEUBLES

| Réf. | Règle | Statut |
|---|---|---|
| **P3B-IMMEUBLE-01** | **Une référence d'immeuble est unique par agence.** Un index global appariait un code SEPTEO de VIENNE avec l'immeuble d'une autre agence et annonçait des rapprochements inexistants. | CERTIFIÉE |
| **P3B-IMMEUBLE-02** | **VIENNE porte DEUX RÉFÉRENTIELS.** SEPTEO numérote `5040`, `4000`, `0067` ; MBI numérote dans la plage SDC `3000-3999`. Le code ne rapproche pas — **l'adresse le fait**, sur 62 immeubles / 76. | CERTIFIÉE |
| **P3B-IMMEUBLE-03** | **Le code d'immeuble SEPTEO se lit dans la référence de ses lots** (`01G01-5052-000105` → immeuble `5052`), le bandeau ne l'imprimant pas. Reporté seulement si les lots s'accordent : deux codes sous un même bandeau signaleraient une fusion, et il faut la voir. 61 immeubles sur 76 récupèrent ainsi leur code. | CERTIFIÉE |
| **P3B-IMMEUBLE-04** | **Occurrence ≠ immeuble physique.** 938 occurrences → 532 immeubles distincts. Le rapport varie avec la profondeur d'historique : ×1,00 (EMERY, 1 trimestre) à ×3,40 (GROUPE SIR, 6 trimestres). | CERTIFIÉE |
| **P3B-IMMEUBLE-05** | **Un même libellé ne fait pas un même immeuble** : 52 libellés désignent plusieurs immeubles distincts (« LE DAUPHINE » sous 3 comptes, « 79 BOURDONNAIS LYON 9 » sous 3). L'identité est `(compte, code)`, jamais le nom. | CERTIFIÉE |

## P3C — LOTS / BIENS

| Réf. | Règle | Statut |
|---|---|---|
| **P3C-LOT-01** | **On résout l'immeuble d'abord, le lot ensuite** — l'ordre de la chaîne métier. Chercher le lot par le code d'immeuble du CRG échouait sur VIENNE. | CERTIFIÉE |
| **P3C-LOT-02** | **La clé d'un bien est `(agence, immeuble, lot)`**, jamais le numéro de lot seul : « 0001 » existe dans presque tous les immeubles. | CERTIFIÉE |
| **P3C-LOT-03** | **Occurrence ≠ bien physique.** 1 957 occurrences → 773 biens distincts (×5,52 sur GROUPE SIR). | CERTIFIÉE |
| **P3C-LOT-04** | ⚠️ **VIENNE : deux référentiels au niveau du lot aussi.** MBI numérote `L001`, `L002` ; SEPTEO `000105`, `000317`. **76 lots ne sont pas appariables avec certitude.** Sur les 57 immeubles résolus par adresse, **38 permettent un appariement certain (1 lot ↔ 1 bien)** et **19 sont ambigus**. **ARBITRAGE REQUIS.** | CERTIFIÉE |

## La définition qui certifie le bloc

⚠️ **La question n'est plus « le CRG retrouve-t-il l'ancien objet MBI ? »** — le local est un banc d'essai, le VPS partira d'une base vide. La question certifiable est : **en partant de rien, plusieurs CRG successifs reconnaissent-ils durablement le même compte, le même immeuble et le même bien, sans jamais les dupliquer ?** Les CRG sont rejoués **du plus ancien au plus récent**, comme le VPS les recevra : c'est le seul ordre où « le trimestre suivant » a un sens, et le seul où une duplication se voit.

## Chiffres de référence — mesurés le 30/08/2026

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR | **Total** |
|---|---:|---:|---:|---:|---:|
| CRG | 117 | 224 | 70 | 47 | **458** |
| Propriétaires (identités) | 60 | 198 | 61 | 14 | **333** |
| Comptes mandants | 60 | 224 | 61 | 16 | **361** |
| Occurrences d'immeuble | 191 | 229 | 79 | 439 | **938** |
| **Immeubles** | 98 | 229 | 76 | 129 | **532** |
| Occurrences de lot | 287 | 384 | 111 | 1 175 | **1 957** |
| **Lots / biens** | 128 | 321 | 111 | 213 | **773** |
| Ratio occurrence → objet | ×1,95 / ×2,24 | ×1,00 / ×1,20 | ×1,04 / ×1,00 | ×3,40 / ×5,52 | |

**Invariants vérifiés sur les 458 :** chaîne propriétaire → compte → immeuble → lot rompue **0 fois** · objets ayant changé de propriétaire entre périodes **0** · objets sans page source **0** · comptes portés par deux propriétaires dans un même périmètre **0** · propriétaires multi-comptes préservés **25** · objets revus sur plusieurs périodes **528** (93 + 115 immeubles, 123 + 197 lots).

## Limites explicitement consignées

⚠️ **La stabilité multi-périodes n'est démontrée que là où le corpus le permet.** Elle l'est sur **LYON hors SIR** (93 immeubles et 123 lots revus sur 2 trimestres) et sur **GROUPE SIR** (115 et 197, sur 6 trimestres). Elle **n'est PAS démontrée sur EMERY IMMO** (un seul trimestre) ni sur **VIENNE** (un seul mois) : il n'y a rien à y démontrer, faute de seconde période. **Cela ne remet pas en cause la certification de leur structure sur le corpus disponible** — mais le jour où un T2 EMERY arrivera, c'est là que la stabilité se vérifiera vraiment.

⚠️ **Ce que le bloc NE certifie PAS.** Les **locataires** sont manipulés techniquement par le moteur (971 dans la simulation) mais **aucune règle** de création, fusion ou identification les concernant n'est doctrine — phase `LOCATAIRE → OCCUPATION` à venir. Les **valeurs financières** ne sont pas contrôlées ici : un loyer est un flux, un encours un stock à une date (`P1-GED-11`) — phase dédiée.


| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR |
|---|---:|---:|---:|---:|
| Comptes distincts | 60 | 224 | 61 | 16 |
| Comptes connus MBI / nouveaux | 54 / 6 | 223 / 1 | 55 / 6 | 14 / 2 |
| Immeubles distincts | 98 | 229 | 76 | 129 |
| Immeubles rapprochés / nouveaux | 73 / 25 | 226 / 3 | 71 / 5 | 121 / 8 |
| Biens distincts | 128 | 321 | 111 | 213 |
| Biens rapprochés / nouveaux | 93 / 35 | 309 / 12 | 35 / **76** | 213 / 0 |

**Chaîne PROPRIÉTAIRE → COMPTE → IMMEUBLE → LOT : 1 957 / 1 957 occurrences complètes, 1 957 / 1 957 avec leur page source.**

---

# BLOC 4 — LOCATAIRES & OCCUPATION 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 30/08/2026.** 25 règles figées. Aucune écriture MBI. **Aucune valeur financière n'est certifiée par ce bloc** : la dette relève de P5.

> **Révision du 30/08/2026 — arbitrage métier Emmanuel sur la succession.** La première version de
> P4B qualifiait « parti » tout locataire dont le lot portait, à la même période, un autre nom
> appelé jusqu'à la clôture — **384 situations**. Cette règle ne démontrait pas le *changement de
> titulaire des appels* : elle constatait une cohabitation de noms sur un lot. La règle métier
> l'a remplacée. Les chiffres ci-dessous sont **recalculés**, pas hérités.
>
> **Trois arbitrages Emmanuel du 30/08/2026, appliqués :** ① le **dépôt de garantie reversé n'est PAS une preuve de sortie** — sur LYON il est reversé AU PROPRIÉTAIRE ; ce qui daterait une sortie serait une **clôture de compte locataire** ② la **cessation durable des appels** sur un lot encore suivi, sans repreneur, **vaut sortie** ③ le **montant du loyer ne décide pas** : un locataire peut avoir plusieurs mois de **gratuité**, et une ligne peut être une refacturation ; ce qui n'est pas démontrable part vers une **page de demandes de validation**.

## P4A — LOCATAIRES

| Réf. | Règle | Statut |
|---|---|---|
| **P4A-LOCATAIRE-01** | **Quatre unités, jamais fondues.** La **situation** est un bloc de lot lu dans un CRG · l'**occurrence** est une situation qui NOMME un locataire · l'**identité** est la personne, le couple ou la personne morale désignée · l'**occupation** est la relation dans le temps, et relève de P4B. Mesuré : **1 957 situations → 1 956 occurrences → 914 identités**. Les confondre multiplierait le fichier locataires par le nombre de trimestres. | CERTIFIÉE |
| **P4A-LOCATAIRE-02** | **Un lot sans ligne locataire ne fabrique pas un locataire.** Le lot 190 de VIENNE (compte 1105404745) n'imprime aucune ligne « Locataire: » : c'est **l'écart exact entre 1 957 et 1 956**, et il est à sa place (`P1-EXC-03`). Les natures se comptent donc sur les **1 956**, jamais sur les 1 957. | CERTIFIÉE |
| **P4A-LOCATAIRE-03** | **Revoir un locataire dans plusieurs CRG ne prouve pas qu'il occupe encore.** Cela prouve seulement qu'il apparaît encore dans les documents — une dette suffit à l'y maintenir des années. | CERTIFIÉE |
| **P4A-LOCATAIRE-04** | **La normalisation sert à rechercher, jamais à fusionner.** Casse, accents, ponctuation, espaces sont neutralisés pour rapprocher ; le libellé source est conservé à l'octet près. **SEPTEO imprime 84 noms sur 110 espaces collés** (`MILLERDEOLIVEIRAouBILLONBryanetElodie`) : sans comparaison sans-espaces, une même personne compterait double. | CERTIFIÉE |
| **P4A-LOCATAIRE-05** | **Un même libellé sous deux comptes n'est PAS une identité multi-comptes** — c'est un **CANDIDAT / LIBELLÉ RAPPROCHÉ**, et il se tranche en trois issues, jamais une seule. **DÉMONTRÉE** : le même local physique — **même rang d'immeuble ET même nom d'immeuble ET même n° de lot** — figure sous deux comptes ; c'est le même occupant du même local, quel que soit le mandat (**8 cas**). **CANDIDAT** : locaux différents mais raison sociale porteuse (**2 cas** : `CULTUELLE LES TÉMOINS…` sous 3 comptes VIENNE, `EURLOG S.L.U`). **INDÉTERMINABLE** : locaux différents et personne physique — l'homonymie n'est pas exclue (**9 cas**). **Aucune fusion, aucune création.** | CERTIFIÉE |
| **P4A-LOCATAIRE-06** | **Le code immeuble porte son compte en préfixe.** `01040087` = compte `0104` + immeuble `0087` ; `01210087` désigne **le même bâtiment** (238 route de Vienne) sous un autre mandat. Ce rang sert **uniquement** à reconnaître un local physique en P4A : la clé certifiée **P3B reste `(agence, code complet)` et n'est pas touchée**. | CERTIFIÉE |
| **P4A-LOCATAIRE-07** | **On ne découpe pas un libellé collectif.** « M. ET MME X » reste **une** occurrence de nature `couple` : le document ne dit pas comment les titulaires sont organisés juridiquement, et inventer deux personnes serait aussi faux que n'en voir qu'une. Natures : **1 835 personnes physiques · 67 cotitulaires · 54 personnes morales · 0 couple · 0 indivision = 1 956**. | CERTIFIÉE |
| **P4A-LOCATAIRE-08** | **Les séparateurs sont détruits par la normalisation, donc on les lit sur le libellé BRUT.** `plat()` remplace `&` et `//` par des espaces : `BILLARDJustine//SCHULTZCélia` redevenait une personne seule, et VIENNE affichait 110 physiques et 0 morale. On lit donc `&`, `/`, et les liaisons collées `…etX` / `…ouX` sur la source — **mais seulement entre deux lettres** : chez LYON, `RAMDANI MADJID/////` et `ZIZAH/// MOHAMMED` portent des barres de **remplissage d'impression**, pas une cotitularité. | CERTIFIÉE |
| **P4A-LOCATAIRE-09** | **Chaque occurrence conserve sa provenance complète** : `crg` · `sha` · `page` · `periode_cle` · `date_arrete` · propriétaire · compte · immeuble · lot · libellé source · identité proposée. Mesuré : **1 956/1 956**. Le chemin inverse `MBI → LOCATAIRE → CRG → PAGE` est donc toujours possible. | CERTIFIÉE |

## P4B — OCCUPATION

| Réf. | Règle | Statut |
|---|---|---|
| **P4B-OCCUPATION-01** | **LA RÈGLE DE SUCCESSION — arbitrage métier Emmanuel.** `MÊME LOT + CHANGEMENT CHRONOLOGIQUE DU TITULAIRE DES APPELS DE LOYERS = SUCCESSION LOCATIVE DÉMONTRÉE`. **Quatre conditions, toutes exigées** : ① le même lot ② A est titulaire d'appels ③ B l'est aussi ④ les appels de B commencent **le jour où ceux de A s'arrêtent, ou après**. Alors B = occupation active, **A = occupation archivée**. | CERTIFIÉE |
| **P4B-OCCUPATION-02** | **A doit avoir ÉTÉ titulaire des appels.** Sans cela il n'y a pas de « changement de titulaire » à démontrer : seulement un nom présent au document, le plus souvent parce qu'il traîne une dette. | CERTIFIÉE |
| **P4B-OCCUPATION-03** | **Le jour de passation appartient aux deux.** Le document facture A « jusqu'au 19/02 » et B « à partir du 19/02 ». Exiger que B commence **strictement** après rejetterait les relèves les plus nettes du corpus ; `≥` accepte la passation et **jamais un chevauchement** — deux cotitulaires facturés du même trimestre ne le franchissent pas. | CERTIFIÉE |
| **P4B-OCCUPATION-04** | **Ce qui ne suffit JAMAIS à conclure une succession** : un autre nom quelque part dans le CRG · un changement de nom sans appels correspondants · l'ordre des lignes · la simple disparition d'un nom · une dette · un solde · un encaissement · une donnée MBI. | CERTIFIÉE |
| **P4B-OCCUPATION-05** | **LE MONTANT DU LOYER NE DÉCIDE PAS — arbitrage Emmanuel.** Un locataire peut avoir **plusieurs mois de gratuité** à son entrée : loyer à 0 €, charges et taxes appelées quand même. Et une ligne à montant positif peut être une **refacturation locative**, pas un loyer. Ce qui fait preuve, c'est qu'une **période soit appelée à son nom** — loyer, taxe ou provision, quel qu'en soit le montant. La colonne **« Divers » reste dehors** : refacturations, régularisations, dépôts de garantie. Mesuré : **8 lignes de loyer à 0 € avec charges appelées** (`KAYREVAN`, janvier et février 2026 : loyer 0 €, taxes 380 €, provisions 650 €) — invisibles, donc « partis », sous l'ancienne règle `loyer > 0`. | CERTIFIÉE |
| **P4B-OCCUPATION-06** | **LA CESSATION DES APPELS — arbitrage Emmanuel, resserré.** Les appels à ce nom s'arrêtent et le document continue de suivre le lot sans jamais le rappeler. **AUCUN SEUIL EN MOIS n'est posé** — « 1 mois = parti » comme « 2 mois = parti » serait une invention. C'est la **forme de la dernière période appelée** qui tranche : ① **arrêt des appels EN COURS DE MOIS + poursuite démontrée du suivi du lot après cette date = cessation d'occupation démontrée selon la règle métier Emmanuel** — c'est une **déduction métier, pas une lecture** : le CRG n'imprime aucun décompte de départ, on constate seulement que la facturation s'arrête en cours de mois et que le document continue de suivre le lot : **ARCHIVÉ** ② **appel arrêté SUR UN MOIS PLEIN** = appel mensuel ordinaire — il faut **plus d'un mois** de recul du document, sinon l'appel suivant peut n'être pas encore émis : **INDÉTERMINABLE**. **43 occupations archivées** à ce titre. **Dans le doute : INDÉTERMINABLE.** | CERTIFIÉE |
| **P4B-OCCUPATION-07** | **ARCHIVER N'EST PAS SUPPRIMER.** Le lot porte `LOCATAIRE A → occupation archivée (+ solde éventuel conservé)` **ET** `LOCATAIRE B → occupation active`. Jamais `BIEN → B` avec disparition de A. Mesuré : **15 lots** portent simultanément une occupation archivée et une occupation active ; **27 occupations archivées portent encore un impayé imprimé**, et **275 indéterminables aussi**. | CERTIFIÉE |
| **P4B-OCCUPATION-08** | **Le débit ne détermine l'occupation dans aucun sens.** `DÉBIT ≠ ACTIF` ; `ANCIEN + DÉBIT` reste `ANCIEN`. Les appels servent de **preuve temporelle**, jamais de valeur financière. **P4 ne certifie pas la dette** : nature, origine, antériorité et montant relèvent des phases financières ultérieures — on constate seulement qu'un ancien locataire peut encore porter un solde, et **à qui il est rattaché**. | CERTIFIÉE |
| **P4B-OCCUPATION-09** | **La hiérarchie des preuves, dans cet ordre.** ① **date de fin de bail imprimée** ② **période appelée à ce nom couvrant la date d'arrêté** ③ **succession démontrée** (`-01`) ④ **cessation durable des appels** (`-06`) ⑤ rien d'autre → INDÉTERMINABLE. L'ordre compte : ② passe avant ④, sans quoi un locataire appelé jusqu'à la clôture serait archivé par une lecture ultérieure. | CERTIFIÉE |
| **P4B-OCCUPATION-10** | **Un avoir retranche EXACTEMENT sa propre période — ni plus, ni moins.** Le même document annule le début d'un mois quand le locataire **entre** le 7, et la fin d'un mois quand il **part** le 12. Traiter tout avoir comme une fin de période a fait d'un entrant un partant, et inventé une succession à l'envers (lot 0043, `HAMDI ALI`). Seule la **soustraction d'intervalles** lit ce que le document dit. **30 blocs portent un avoir.** | CERTIFIÉE |
| **P4B-OCCUPATION-11** | **Chaque format a ses preuves, et on ne généralise jamais.** `septeo_spi` imprime les dates de bail (début 110/111, **fin 6/111**) et la période dans le libellé de l'appel · `lyon` et `emery_immo` **n'impriment aucune date de bail, ni « départ », ni « congé », ni « clôture de compte »** (vérifié au PDF) : leur seule trace est le tableau de périodes appelées, **absent sur 96 blocs de LYON et 653 de GROUPE SIR**. | CERTIFIÉE |
| **P4B-OCCUPATION-12** | **Un appel qui s'arrête sans que le lot soit revu ne prouve RIEN.** Si aucune date d'arrêté ne suit le dernier appel, l'appel suivant peut simplement ne pas être encore émis : c'est la limite exacte de `-06`. **INDÉTERMINABLE est un résultat correct** — on ne cherche pas à en réduire le nombre. | CERTIFIÉE |
| **P4B-OCCUPATION-13** | **CE QUI N'EST PAS DÉMONTRABLE NE SE DEVINE PAS : IL SE DEMANDE.** Chaque indéterminable part vers l'**écran de demandes de validation** avec ce que le document dit vraiment — dernier appel, impayé imprimé, autres locataires du lot, pages sources. Un humain tranche **une fois**, et la réponse devient une **donnée**, jamais une heuristique cachée dans le moteur. **288 demandes** : 281 *jamais appelé, présent au document* et 7 *mois plein + un seul mois de recul*. Export : `C:\tmp\p4b_validations.json`. | CERTIFIÉE |
| **P4B-OCCUPATION-14** | **Deux niveaux, tous deux datés, jamais confondus.** La **SITUATION** = `lot × locataire × période`, jugée à la date d'arrêté de CE CRG — « qui occupait ce lot à telle période ? ». L'**OCCUPATION** = `lot × locataire`, consolidée sur tout le corpus et jugée à la **dernière clôture connue du lot** — elle porte l'historique. Un locataire actif en T1 et archivé en T3 est les deux, à sa date. **0 situation sans date.** | CERTIFIÉE |
| **P4B-OCCUPATION-15** | **La dernière ligne n'est pas l'occupant actuel.** Un lot peut présenter plusieurs locataires dans un même CRG (**388 blocs**, **156 lots sur le corpus**) : cotitulaires, occupations successives, ancien locataire à solde. La qualification vient d'une preuve documentaire, jamais de l'ordre des lignes. | CERTIFIÉE |
| **P4B-OCCUPATION-16** | **Le `statut` imprimé par les lecteurs n'est PAS cette qualification.** Les trois lecteurs posent `lot['statut'] = occupe si loyer_appele > 0 sinon parti` — une heuristique de **montant**, exactement ce que `-05` et `-08` interdisent. Elle n'est ni lue, ni certifiée, ni opposable. | CERTIFIÉE |

## Exceptions et angles morts

| Réf. | Constat | Statut |
|---|---|---|
| **P4B-EXC-01** | **Le dépôt de garantie reversé n'est PAS une preuve de sortie** — arbitrage Emmanuel. Le premier passage avait relevé 46 lignes `Dépot de garantie reversé` / `Rembt D G reversé` et les avait présentées comme des mentions de fin de location. **C'est faux : sur LYON le dépôt est reversé AU PROPRIÉTAIRE**, mouvement entre la régie et le mandant, sans rapport avec la date de sortie du locataire. Le terme est retiré des marqueurs. **Ce qui daterait une sortie serait une clôture de compte locataire** — solde de compte, arrêté de compte, décompte de sortie. **Recherché sur les 458 PDF : AUCUNE.** Les 28 lignes trouvées sont toutes du bruit de pièces jointes — conditions générales EDF (« Vous pouvez résilier votre contrat à tout moment »), clauses d'assurance, et surtout **2 avis VIENNE de résiliation du MANDAT DE GESTION** (« vous pouvez résilier votre mandat n°145 jusqu'au 03/05/2026 ») — côté propriétaire, jamais côté locataire, et un piège parfait pour une lecture naïve. | CLOS |
| **P4B-EXC-02** | **Le lecteur `lyon` ne conserve pas le libellé des lignes « Divers »** — seulement leurs colonnes chiffrées. Tant qu'aucune clôture de compte n'est trouvée au PDF, la recherche exhaustive de `P4B-EXC-01` montre qu'**aucune clôture de compte locataire n'est imprimée nulle part** : cette perte ne masque donc aucune preuve de sortie. Elle resterait à corriger le jour où un format en imprimerait une. | SANS CONSÉQUENCE DÉMONTRÉE |
| **P4B-EXC-03** | **4 avoirs annulent le loyer couvrant la clôture, sans repreneur nommé** (sur 30 blocs portant un avoir ; les 26 autres sont des prorata d'ENTRÉE, cf. `P4B-OCCUPATION-10`). | OUVERT |
| **P4B-EXC-04** | **Les 7 cessations les plus fragiles sont passées en INDÉTERMINABLE** — décision Emmanuel. Un dernier appel en **fin de mois pleine** suivi d'**un seul mois** de silence : `FIHEL REGINA` (31/05 → 30/06, 604,03 € impayés), `TAY Suan`, `FAITOUT Noémie`, `PEZET DIDIER`, `SAS HOOKAH DOME` (3 409,51 €), `DELMAS Emmanuelle`, `HATAYAN Boutique GENTLEMAN` (8 175,46 €). **On ne perd rien** : le prochain CRG leur donnera le recul qui manque, et elles basculeront alors avec une preuve. **50 → 43 archivées par cessation.** |
| **P4B-EXC-05** | **`BERTHELOT AG` (GROUPE SIR, 01040000 / 01040117 / lot 0083)** : un loyer appelé le 09/06/2026 pour 09→30/06, **intégralement crédité le même jour** (−1 878,80 € contre 1 466,67 € appelés), plus 6 000 € en « divers ». Couverture nette **nulle** : ni titulaire d'appels, ni repreneur. Part en demande de validation. Le prédécesseur `SP LYON 7` est, lui, archivé par cessation durable — dernier appel au 31/12/2025, lot suivi jusqu'au 30/06/2026, **15 427,22 € toujours rattachés à son nom**. | OUVERT |

## Chiffres certifiés — 30/08/2026

**NIVEAU 1 · SITUATIONS** — `lot × locataire × période`, à la date d'arrêté du CRG

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR | **Total** |
|---|---:|---:|---:|---:|---:|
| Situations | 287 | 384 | 111 | 1 175 | **1 957** |
| **ACTIFS** | 181 | 287 | 106 | 508 | **1 082** |
| **A — preuve directe** (fin de bail) | 0 | 0 | 3 | 0 | **3** |
| **B — succession démontrée** | 2 | 7 | 0 | 3 | **12** |
| **C — cessation démontrée** | 7 | 16 | 0 | 10 | **33** |
| **INDÉTERMINABLES** | 97 | 74 | 2 | 654 | **827** |
| Sans date d'arrêté | 0 | 0 | 0 | 0 | **0** |

**NIVEAU 2 · OCCUPATIONS** — `lot × locataire`, à la dernière clôture connue du lot

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR | **Total** |
|---|---:|---:|---:|---:|---:|
| Occupations | 148 | 384 | 110 | 324 | **966** |
| **ACTIVES** | 87 | 287 | 106 | 136 | **616** |
| **ARCHIVÉES « A »** — preuve directe | 0 | 0 | 3 | 0 | **3** |
| **ARCHIVÉES « B »** — succession | 3 | 7 | 0 | 6 | **16** |
| **ARCHIVÉES « C »** — cessation démontrée | 9 | 16 | 0 | 18 | **43** |
| **INDÉTERMINABLES** | 49 | 74 | 1 | 164 | **288** |
| Archivées portant encore un impayé | 6 | 1 | 3 | 17 | **27** |
| Indéterminables portant encore un impayé | 45 | 67 | 1 | 162 | **275** |
| Lots « A archivée + B active » | 3 | 6 | 0 | 6 | **15** |

**Trajet des 387 « PARTIS » de la première version** : **3 en A** · **16 en B** · **43 en C** · le reste en INDÉTERMINABLE, faute d'un ancien titulaire des appels démontré. **62 occupations archivées**, chacune datée et prouvée.

### Les 16 successions démontrées A → B

| Périmètre | Compte · immeuble · lot | A — appels | B — appels | PDF · page |
|---|---|---|---|---|
| LYON hors SIR | 01130000 · 01130061 · 0007 | `FLEURY MAXIME` 01/01→12/05/2026 | `TASSET MORGAN` 15/05→30/06/2026 | `DMC.pdf` p.2 |
| LYON hors SIR | 01330000 · 01330218 · 0043 | `GUENIFA KERYAN` 01/01→31/03/2026 | `HAMDI ALI` 07/04→30/06/2026 | `202606300260000000001310P.pdf` p.13 |
| LYON hors SIR | 02320000 · 02320307 · 0257 | `MAKANGU Lossi` 01/01→18/03/2026 | `ATTAR NASSIM` 20/03→30/06/2026 | `Madame_DUMONT_Alexandra_2026_T1_TTA52F0.pdf` p.2 |
| EMERY IMMO | 10470000 · 10470657 · 0001 | `LAGEON Aurélien` 01/01→19/02/2026 | `LE THIEC Régis` 19/02→31/03/2026 | `TTAA024.tmp.pdf` p.2 |
| EMERY IMMO | 08330000 · 08330490 · 0001 | `COMBES LEFEBVRE` 01/01→04/02/2026 | `BOULET Laurent` 27/02→31/03/2026 | `TTAB861.tmp.pdf` p.2 |
| EMERY IMMO | 06940000 · 06940377 · 0002 | `BRAS Yannick` 01/01→31/01/2026 | `SARRE Alain` 03/02→31/03/2026 | `TTAF859.tmp.pdf` p.2 |
| EMERY IMMO | 09790000 · 09790596 · 0001 | `MIRAMON Lexane` 01/01→01/01/2026 | `MARTIAL Kevin` 23/01→31/03/2026 | `TTA9E6.tmp.pdf` p.2 |
| EMERY IMMO | 04940000 · 04940261 · 0004 | `LEMOINE Benoit` 01/12/2025→14/02/2026 | `FAITOUT Noémie` 26/02→28/02/2026 | `TTAF10F.tmp.pdf` p.3 |
| EMERY IMMO | 04940000 · 04940261 · 0009 | `MAILFAIT Cécile` 01/01→12/02/2026 | `CHARPOTIER Julie` 13/02→31/03/2026 | `TTAF10F.tmp.pdf` p.4 |
| EMERY IMMO | 07090000 · 07090395 · 0001 | `COLOMBIER-LAVAGUE` 01/01→16/02/2026 | `YENGO Mathieu` 17/02→31/03/2026 | `TTA3313.tmp.pdf` p.2 |
| GROUPE SIR | 01080000 · 01080134 · 0106 | `GHALEM Djema` 01/01→31/10/2025 | `VINCENT Gilles` 14/11/2025→30/06/2026 | `SCI_FOCH_2025_T2_TTAFD6F.pdf` p.4 |
| GROUPE SIR | 01080000 · 01080137 · 0086 | `BOUGHALEM SANA` 01/01/2025→31/03/2026 | `HAMDADA SANA` 01/04→30/06/2026 | `SCI_FOCH_2025_T2_TTAFD6F.pdf` p.9 |
| GROUPE SIR | 01040000 · 01040091 · 0001 | `OLIVIER FERRARD` 01/01→31/07/2025 | `DRIVE SCHOOL` 01/08/2025→30/06/2026 | `SARL_GROUPE_SIR_2026_T1_TTA12F0.pdf` p.17 |
| GROUPE SIR | 01040000 · 01040185 · 0188 | `DEPARTEMENT DU…` 01/01→30/09/2025 | `MAIRIE DE SAINT-PRIEST` 15/10/2025→30/06/2026 | `SARL_GROUPE_SIR_2026_T1_TTA12F0.pdf` p.29 |
| GROUPE SIR | 01040000 · 01040088 · 8000 | `KILOUTOU` 01/01→30/06/2025 | `ARICI` 01/10/2025→30/06/2026 | `SARL_GROUPE_SIR_2025_T1_TTAF40E.pdf` p.15 |
| GROUPE SIR | 01210000 · 01210103 · 0031 | `SEVEN 7` 18/12/2025→14/01/2026 | `VYV 3 SUD EST` 15/01→30/06/2026 | `SARL_SIRES_2026_T1_TTA8D25.pdf` p.13 |

**Contrôle documentaire (`p4b_source_pdf.py`, 458 PDF rouverts) : 16/16 successions dont LES DEUX noms sont retrouvés littéralement au PDF, 0 anomalie** — chacun dans SON document : A dans le CRG où ses appels s'arrêtent, B dans celui où les siens commencent.

Les dix premières sont des successions **dans un même CRG** ; les suivantes s'étalent **sur plusieurs périodes** — le titulaire des appels change d'un trimestre à l'autre, et l'ancien reste attaché au lot.

## Limites du corpus

⚠️ **VIENNE ne contient qu'une période** (2026-04) : l'évolution d'occupation y est **NON DÉMONTRABLE SUR CE CORPUS**, et aucune succession n'y est observable. LYON (2 périodes), EMERY (3) et GROUPE SIR (7) permettent de l'observer.

⚠️ **GROUPE SIR concentre l'incertitude** : **164 indéterminables sur 324 occupations**, parce que **653 de ses blocs ne portent aucune période appelée**. Vérifié au PDF : le document imprime bien le lot et le locataire, mais seulement un `Solde Antérieur`, une régularisation de charges ou un dépôt de garantie. Ce n'est **pas** un défaut de lecture.

⚠️ **Aucune preuve directe de sortie hors SEPTEO.** Les 3 seules occupations archivées par preuve directe sont VIENNE. LYON et EMERY n'ont que la succession et la cessation durable.

## Tests

```
python public_html/scripts/tests_crg/p4a_certification.py        # unités, identités, provenance
python public_html/scripts/tests_crg/p4b_certification.py        # situations, occupations, successions, file de validation
python public_html/scripts/tests_crg/p4a_source_pdf.py           # lent — 458 PDF, noms au PDF
python public_html/scripts/tests_crg/p4b_source_pdf.py           # lent — successions + clôture de compte
python public_html/scripts/crg_non_regression.py                 # tout le harnais
```

---

# BLOC 5 — APPELS & ENCAISSEMENTS 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 31/08/2026.** 26 règles figées. Aucune écriture MBI. **Aucune créance, aucun encours, aucun reste dû n'est certifié ici** — ce sont des STOCKS, ils relèvent de P6.

> ### ⚠️ LES DEUX COMPTEURS HISTORIQUES, ET CE QU'ILS SONT VRAIMENT
>
> **`due` = 13 146 570,21 € NE REPRÉSENTE PAS LES APPELS DE LA PÉRIODE.** Il mélange au moins un **FLUX D'APPELS** et un **STOCK / SOLDE ANTÉRIEUR**. `due` reste un **compteur technique historique** : il ne devra **plus jamais** être présenté dans MBI comme « montant appelé » sans définition. ⚠️ Les **8 384 657,72 €** de composante antérieure ne sont **pas certifiés comme stock ici** : P5 constate seulement leur nature hors flux courant, **P6 devra les certifier indépendamment**.
>
> **`paid` = 4 477 689,44 € retombait numériquement avant la rectification FOCH/SABY — cela n'en fait pas une liste de paiements.** P5B démontre **0 mouvement élémentaire daté** et **0 payeur identifié**, pour **2 767 règlements documentaires** de granularités différentes selon les formats. **Le niveau de preuve doit toujours accompagner le montant.**
>
> **Le résultat qui commande tous les autres.** L'ancien compteur `due` valait **13 146 570,21 €**. Ce n'est **pas** un montant appelé : c'est un **flux d'appels additionné d'un stock d'arriéré (8 384 657,72 €)**. **64 % du chiffre était de l'antériorité**, pas de la facturation de la période. `13 146 570,21 − 8 384 657,72 = 4 761 912,49` : l'ancien compteur comptait le flux **doublons compris**, comme P5A avant sa rectification. Face au documentaire rectifié de **4 733 161,49 €**, l'écart de **28 751,00 €** se décompose exactement en **28 606,44 € de doublons FOCH/SABY** et **144,56 €** pour les 9 lots de `P5A-EXC-02`.

## Doctrine figée — les quatre inégalités du Bloc 5

| | |
|---|---|
| **APPEL ≠ ENCAISSEMENT ≠ AFFECTATION ≠ SOLDE** | quatre événements distincts. Un appel de 800 €, un règlement de 500 € et un reste dû de 1 700 € ne se fabriquent jamais l'un à partir de l'autre. |
| **STOCK ≠ FLOW** | un appel et un règlement sont des FLUX sur une période ; une créance, un solde, un report antérieur sont des PHOTOGRAPHIES à une date. Ils ne s'additionnent pas. |
| **AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE** | un total imprimé certifie un total. Il ne prouve l'existence d'aucun des mouvements qui le composeraient. |
| **INDÉTERMINABLE ≠ ERREUR** | lorsque l'information est réellement absente ou ambiguë au document **et reste traçable**, l'indétermination est un résultat correct. |

### Les deux compteurs historiques, définitivement qualifiés

**`due` n'est PAS le montant appelé.** Il vaut 13 146 570,21 € et mélange un **flux courant** et un **stock / report antérieur** de 8 384 657,72 €. **Il ne doit plus jamais servir de définition métier de « l'appelé ».** Le montant appelé au locataire est **4 328 429,23 €** (4 357 710,67 € avant la rectification FOCH/SABY du 01/09/2026).

**`paid` n'est PAS une liste de paiements élémentaires.** Il vaut 4 477 689,44 € — **4 458 248,65 € après la rectification FOCH/SABY** — et c'est un **agrégat documentaire dont la granularité dépend du format** — ligne de période chez LYON et GROUPE SIR, ligne d'appel chez VIENNE, **lot seulement chez EMERY**. **0 mouvement élémentaire daté, 0 payeur identifié.** Le niveau de preuve accompagne toujours le montant.

⚠️ Les **8 384 657,72 €** de composante antérieure **ne sont pas certifiés comme stock ici** : P5 constate seulement leur nature hors flux courant. **P6 devra les certifier indépendamment**, et ne jamais repartir de `due − paid`.

## P5A — APPELS

⚠️ **Frontière avec P7A** : une refacturation au locataire et la dépense correspondante du propriétaire sont **deux événements distincts**, jamais compensés — voir *Frontière P5A ↔ P7A* au Bloc 7.

| Réf. | Règle | Statut |
|---|---|---|
| **P5A-APPEL-01** | **Trois niveaux, et les formats ne les organisent pas pareil.** `OCCURRENCE` = une ligne imprimée · `APPEL MÉTIER` = la facturation adressée au locataire pour UNE période · `COMPOSANTE` = la nature qui le compose. Chez `lyon`/`emery_immo` une ligne porte une période et jusqu'à quatre colonnes → **1 occurrence = 1 appel = n composantes**. Chez `septeo_spi` une ligne porte UNE nature → **n occurrences regroupées par (lot, période) = 1 appel**. Mesuré : **4 371 occurrences → 4 216 appels → 10 197 composantes**. | CERTIFIÉE |
| **P5A-APPEL-02** | **Une colonne n'est pas une nature métier — et le libellé se récupère.** L'entête `lyon` nomme Loyers, Taxes et Provisions ; **« Divers » ne nomme rien**. Le libellé EST imprimé (« Du 19.05.25 Au 19.05.25 Frais d'Huissier 73.18 ») mais le lecteur le jette. Sur arbitrage Emmanuel il est **récupéré au PDF par un lecteur de certification séparé** — les extractions figées sur lesquelles P1 → P4B sont certifiées **ne changent pas d'un octet**. Récupérer n'est pas qualifier : la nature n'est posée que lorsque le libellé la démontre, et `libelle_source` est conservé dans tous les cas. **604 libellés retrouvés sur 636 lignes.** | CERTIFIÉE |
| **P5A-APPEL-03** | **Chez SEPTEO, une ligne sans montant appelé n'est PAS un appel.** Positions x vérifiées au PDF : `Loyers@234 · Charges@279 · Autres@337 · Reste dû@379 · crédit@540`. Si rien ne figure sous Loyers/Charges/Autres, la ligne ne facture rien : un montant **à droite** est un **règlement** (P5B) ; un montant en **« Reste dû » seul** est le **rappel d'un impayé antérieur** — un STOCK. Le lot 000335 liste ainsi octobre 2025 → février 2026 sans rien facturer, puis appelle mars et avril. **Les compter aurait fait entrer tout l'arriéré dans les loyers appelés** : 402 → 308 occurrences VIENNE après correction. | CERTIFIÉE |
| **P5A-APPEL-04** | **Période du CRG ≠ période de l'appel.** Les deux temporalités sont conservées séparément. | CERTIFIÉE |
| **P5A-APPEL-05** | **Une régularisation annuelle a une période, même sans deux dates.** « Régularisation pour charges 2024-2025 » est daté par son libellé. **150/153 appels VIENNE** portent une période ; **3** n'en impriment aucune, et c'est un constat, pas un défaut. | CERTIFIÉE |
| **P5A-APPEL-06** | **Un prorata garde ses dates.** Jamais de mensualisation artificielle. **999 proratas** (159 · 411 · 25 · 404). | CERTIFIÉE |
| **P5A-APPEL-07** | **Un appel futur n'est jamais un impayé.** **NON OBSERVÉ DANS LE CORPUS** : aucun appel ne commence après la date d'arrêté. **18 appels la franchissent** (2 · 1 · 2 · 13) — ils sont conservés tels quels, pas découpés. | CERTIFIÉE |
| **P5A-APPEL-08** | **Montant zéro ≠ absence d'appel** (`P4B-OCCUPATION-05`). **155 appels à zéro** portant une période. | CERTIFIÉE |
| **P5A-APPEL-09** | **Un avoir conserve son signe, son montant et sa période.** **202 avoirs, −137 534,83 €.** Il n'est ni un paiement, ni un appel positif. | CERTIFIÉE |
| **P5A-APPEL-10** | **Le « Total » imprimé du lot n'est PAS un total d'appels.** Contrôle : sur 1 224 lots, **871 égaux**, **353 dont l'écart est EXACTEMENT le solde antérieur** et **3 inexpliqués pour 716,97 €**. Le document additionne donc un **FLUX** et un **STOCK**. Reprendre `total_du` comme « appelé » importe l'arriéré dans la période — c'est l'erreur des anciens compteurs. | CERTIFIÉE |
| **P5A-APPEL-12** | **DÉBORDANT N'EST PAS FUTUR — arbitrage Emmanuel.** `FUTUR` = la période **commence** après la date d'arrêté (**0 cas dans le corpus**). `DÉBORDANT` = elle le **franchit** (**18 cas** : 2 LYON · 1 EMERY · 2 VIENNE · 13 GROUPE SIR). Période et montant sont conservés **entiers** ; **aucun prorata n'est calculé** — le partage n'appartiendra à P6 que si le CRG le fournit lui-même. Contrôle PDF : `DECAUX PUBLICITE Du 01.01.26 Au 30.06.26 2549.00` est un vrai appel semestriel, tandis que `MOTO FEELING 74 Du 01.02.26 Au 31.01.36 Dépot de garantie reversé 23550.00` et `LIBRAIRIE DE LA MADELEINE Du 17.03.26 Au 16.03.35 5000.00` ne sont **pas des appels** : leur « période » est la **durée du bail**. | CERTIFIÉE |
| **P5A-APPEL-13** | **Le libellé ne corrige pas que la nature : il corrige des montants.** Un libellé contenant un nombre contamine les colonnes chiffrées du lecteur — « Article 700 » a produit une provision de 700,00 €, « Parking + 2 voitures » une de 2,00 €. **Deux des trois écarts de contrôle restants viennent de là.** | CERTIFIÉE |
| **P5A-APPEL-14** | **Deux blocs peuvent porter le même lot et le même locataire dans un même CRG**, et **deux immeubles distincts peuvent partager un code**. Toute clé de contrôle doit donc porter le **rang du bloc** en plus de `(sha, immeuble, lot)`. Sans cela, on compare les composantes d'un bloc au total d'un autre — c'était l'origine de 8 des 9 écarts « inexpliqués » de GROUPE SIR. | CERTIFIÉE |
| **P5A-APPEL-15** | **Le total documentaire n'est pas le flux appelé au locataire.** Après récupération des libellés et **rectification FOCH/SABY du 01/09/2026** : **4 733 161,49 € documentaires − 135 191,76 € de report (STOCK) − 269 540,50 € de dépôts de garantie (mouvement propriétaire) = 4 328 429,23 € réellement appelés au locataire**. Les deux retraits sont **montrés**, jamais silencieux, et chaque ligne retirée conserve son libellé source. **Seules les exclusions VIVANTES sont retranchées** : une ligne qualifiée portée par une occurrence réénoncée, donc retirée, ne s'applique plus — la compter surestimait le flux de 675,00 €. | CERTIFIÉE |
| **P5A-APPEL-11** | **Provenance complète, 4 174/4 174** (4 216 avant rectification FOCH/SABY) : `crg_fichier · sha · page · periode_crg · date_arrete · proprietaire · compte · immeuble · lot · locataire · periode_appel_debut/fin ou texte · nature · montant · libelle_source · niveau_source`. | CERTIFIÉE |

## P5B — ENCAISSEMENTS

| Réf. | Règle | Statut |
|---|---|---|
| **P5B-ENCAISSEMENT-01** | **AUCUN FORMAT N'IMPRIME DE MOUVEMENT ÉLÉMENTAIRE DE RÈGLEMENT LOCATAIRE.** Pas une ligne « le 12/04, M. X a versé 800 € » sur les 458 CRG. **MOUVEMENTS ÉLÉMENTAIRES DATÉS : NON DÉMONTRABLES SUR CE CORPUS.** | CERTIFIÉE |
| **P5B-ENCAISSEMENT-02** | **Les lignes datées du document sont toutes propriétaire/régie.** `septeo_spi` : 280 lignes datées, sections *Honoraires de Gestion · Factures dues · Charges de syndic · GLI Assurance · Charges Propriétaire* — **zéro locataire**. `lyon`/`emery_immo` : les « Reglement virement du 28.01.2026 » du récapitulatif sont des versements **régie → propriétaire**. **MOUVEMENT COMPTABLE ≠ ENCAISSEMENT LOCATAIRE.** | CERTIFIÉE |
| **P5B-ENCAISSEMENT-03** | **Ce que les documents donnent, c'est un RÈGLEMENT AFFECTÉ, NON DATÉ.** Le triplet `appelé / réglé / restant` est imprimé — mais la **maille change avec le format**, et cette maille est une donnée : `lyon` (LYON + GROUPE SIR) **ligne de période** · `septeo_spi` **ligne d'appel** · `emery_immo` **lot seulement**. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-04** | **« Réglé » n'est pas « encaissé du locataire ».** Le document dit qu'une période a été soldée ; il ne dit ni QUAND, ni PAR QUI. Une GLI, une CAF, une compensation produiraient la même colonne. **Payeur INDÉTERMINABLE sur 100 % du corpus** — un fait à consigner, pas à combler. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-05** | **« Réglés » n'est pas imprimé partout à la même maille, y compris dans un même CRG.** Certaines lignes de période le portent, d'autres non ; la ligne `Totaux` du lot porte toujours le total. Ne prendre que les lignes perdait **67 945 € sur LYON et 945 714 € sur GROUPE SIR** ; ne prendre que le total effaçait l'affectation là où elle est démontrée. **On garde les deux et on nomme le reste : règlement affecté au LOT seulement.** | CERTIFIÉE |
| **P5B-ENCAISSEMENT-06** | **On ne fabrique jamais de mouvements pour atteindre un total.** Là où seul un agrégat est imprimé, on certifie un **AGRÉGAT**. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-07** | **L'agrégat imprimé est « TOTAL DES REGLEMENTS LOCATAIRES », à la maille immeuble** — 627 lignes (158 · 220 · 0 · 249). **VIENNE n'en imprime aucun** : son agrégat n'existe pas, on ne le fabrique pas. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-08** | **Tiers payeur, rejet, remboursement, extourne : NON OBSERVÉS DANS LE CORPUS.** Recherche au PDF de `CAF · APL · ALS · GLI · VISALE · Action Logement · FSL · rejet · remboursement · extourne · contre-passation` : seule remonte « Honoraires garantie des loyers », **une charge du propriétaire**, jamais un paiement de tiers. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-09** | **52 règlements négatifs → `RÈGLEMENT NÉGATIF — NATURE INDÉTERMINABLE`** (22 · 2 · 0 · 28), décision Emmanuel. **Le signe ne démontre ni rejet, ni remboursement, ni avoir, ni extourne, ni correction.** Ils restent dans P5B avec montant signé, propriétaire, compte, immeuble, lot, locataire, période si démontrable, PDF, page et granularité — et **aucun traitement métier supplémentaire**. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-10** | **Aucune créance n'est calculée ici.** `appelé − réglé` n'est pas une dette : il y manque l'antériorité, les avoirs, les avances et les compensations. C'est P6. | CERTIFIÉE |
| **P5B-ENCAISSEMENT-11** | **Provenance complète, 2 767/2 767** (2 805 avant rectification FOCH/SABY), avec `maille` et `affectation` explicites, `date_mouvement = null` et `payeur = indéterminable` — **écrits, jamais laissés vides par omission**. | CERTIFIÉE |

## Exceptions

| Réf. | Constat | Statut |
|---|---|---|
| **P5A-EXC-01** | **26 lignes / 9 894,12 € restent INDÉTERMINABLES — limite documentaire acceptée, individuellement tracée.** 9 lignes non retrouvées au PDF (9 166,82 €) · 15 dont le libellé ne démontre aucune nature (2 509,30 € : « Plaques/MAJ Non interphone », « Facture divers », « SCI ») · 2 réellement ambiguës (−1 782,00 € : `ERREUR ECRITURE` et `Remb LOYER TROP VERSE` sur la même période et le même montant). Chacune conserve montant, compte, immeuble, lot, locataire, période, PDF et page. **Aucune qualification artificielle supplémentaire n'est recherchée : INDÉTERMINABLE ≠ ERREUR.** Les 462 491,38 € de départ sont qualifiés à **97,9 %** — 452 597,26 € sur 610 lignes. |
| **P5A-EXC-02** | **ÉLUCIDÉE — les anciens écarts GROUPE SIR sont expliqués.** 47 454,83 € sur 9 lots → **0**. Six venaient d'une **collision de clé de contrôle** (deux blocs « lot 0001 LA BOUCHERIE DU… » sur la même page), deux de **deux immeubles VIENNE partageant le code `5143`**, et les trois derniers de **défauts de lecture démontrés au PDF** : « Article 700 » lu comme une provision de 700 € · « Parking + 2 voitures » comme une de 2 € · et `HMAIRIA` −14,97 €, où le document imprime `Du Na.je Au 01.11.24 Solde de charges 14.97` — **la date de début est illisible dans la source** (un fragment du nom du locataire) et le lecteur figé jette la ligne entière. **HMAIRIA n'est plus une exception** : 14,97 € de *solde de charges / régularisation*, date de début illisible, cause démontrée. |
| **P5B-EXC-01** | **EMERY IMMO — LIMITE STRUCTURELLE ACCEPTÉE, décision Emmanuel.** 522 408,16 €, soit **100 % de son montant réglé**, sont **démontrés au LOT** ; leur **affectation à une période n'est pas démontrable** — le format n'imprime « Réglés » qu'à la ligne `Totaux`. Ni une erreur, ni une affectation à inventer, et **aucune demande de validation humaine**. |
| **P5B-EXC-02** | **1 596 547,33 € (36 % du total réglé) ne sont affectés à aucune période** — 76 700,65 (LYON) · 522 408,16 (EMERY) · 10,00 (VIENNE) · 997 428,52 (GROUPE SIR). | OUVERT |

## Chiffres observés — non validés

### P5A — appels

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR | **Total** |
|---|---:|---:|---:|---:|---:|
| Occurrences | 734 | 1 360 | 308 | 1 969 | **4 371** |
| **Appels métier** | 734 | 1 360 | 153 | 1 927 | **4 174** |
| Composantes | 1 786 | 3 468 | 308 | 4 635 | **10 197** |
| LOYER | 478 118,03 | 470 843,99 | 66 380,75 | 2 382 628,21 | **3 397 970,98** |
| TAXES | 27 379,02 | 1 365,30 | 1 274,55 | 399 696,90 | **429 715,77** |
| CHARGES / PROVISIONS | 51 255,86 | 55 006,46 | 2 791,08 | 332 824,33 | **441 877,73** |
| RÉGULARISATIONS | 0,00 | 0,00 | 2 672,38 | 0,00 | **2 672,38** |
| Colonne « Divers » (ventilée ci-dessous) | 4 943,87 | 139 676,77 | 0,00 | 317 816,95 | **462 437,59** |
| **Total documentaire** | 561 696,78 | 666 892,52 | 71 605,80 | 3 432 966,39 | **4 733 161,49** |
| — dont report / solde antérieur (STOCK) | 0,00 | −135 191,76 | 0,00 | 0,00 | **−135 191,76** |
| — dont dépôt de garantie (propriétaire), exclusions vivantes | +10 570,00 | −12,00 | 0,00 | −280 098,50 | **−269 540,50** |
| **= APPELÉ AU LOCATAIRE (flux)** | 572 266,78 | 531 688,76 | 71 605,80 | 3 152 867,89 | **4 328 429,23** |

**Ventilation des 462 437,59 € de la colonne « Divers »**, après récupération du libellé au PDF — **lignes telles que qualifiées**, dont une appartient à une occurrence réénoncée retirée depuis, d'où un dépôt de garantie effectivement retranché de **269 540,50 €** (`RECT-EVENEMENT-05`) : dépôt de garantie **268 865,50** · report / solde antérieur **135 191,76** · taxe **21 753,04** · régularisation **13 522,00** · charges/provisions **5 914,34** · frais de procédure **5 577,13** · refacturation locative **2 137,08** · correction d'écriture **1 782,00** · loyer **−2 102,04** · remboursement de loyer trop perçu **−43,55** · **indéterminable 9 894,12**.
| Proratas · à zéro · futurs | 159 · 0 · 0 | 411 · 154 · 0 | 25 · 0 · 0 | 404 · 1 · 0 | **999 · 155 · 0** |
| **Débordants** (franchissent l'arrêté) | 2 | 1 | 2 | 13 | **18** |
| Avoirs (nombre · montant) | 44 · −26 167,12 | 76 · −13 638,27 | 8 · −1 704,49 | 74 · −96 024,95 | **202 · −137 534,83** |
| Provenance complète | 734/734 | 1 360/1 360 | 153/153 | 1 927/1 927 | **4 174/4 174** |

### P5B — encaissements

| | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR | **Total** |
|---|---:|---:|---:|---:|---:|
| **Mouvements élémentaires datés** | 0 | 0 | 0 | 0 | **0** |
| Règlements affectés | 609 | 315 | 275 | 1 568 | **2 767** |
| Montant | 526 183,29 | 522 408,16 | 60 998,99 | 3 348 658,21 | **4 458 248,65** |
| Maille imprimée | ligne de période | **lot** | ligne d'appel | ligne de période | — |
| — affectés à une période | 538 → 449 482,64 | 0 → 0,00 | 274 → 60 988,99 | 1 353 → 2 370 670,48 | **2 165 → 2 881 142,11** |
| — au lot seulement | 76 700,65 | 522 408,16 | 10,00 | 997 428,52 | **1 596 547,33** |
| Payeur identifié | 0 | 0 | 0 | 0 | **0** |
| Négatifs à qualifier | 22 | 2 | 0 | 28 | **52** |
| Agrégats imprimés | 158 | 220 | **0** | 249 | **627** |
| Montant agrégé | 526 183,29 | 522 408,16 | aucun | 3 368 099,00 | **4 416 690,45** |
| Écart Σ affectés ↔ agrégat | 0,00 | 0,00 | sans objet | 0,00 | **0,00** |
| Provenance complète | 609/609 | 315/315 | 275/275 | 1 568/1 568 | **2 767/2 767** |

## Différences avec les anciens compteurs

| Ancien | Valeur | P5 certifié | Différence de définition |
|---|---:|---:|---|
| `due` | **13 146 570,21** | **4 733 161,49** appelés | l'ancien additionnait le **flux** appelé et le **stock** d'arriéré : **8 384 657,72 € de solde antérieur** y étaient inclus |
| `paid` | **4 477 689,44** | **4 458 248,65** réglés | l'écart de **19 440,79 €** est celui des 38 règlements réénoncés (rectification FOCH/SABY) ; avant elle les deux totaux coïncidaient, mais **leur signification jamais** : 0 mouvement daté, 0 payeur identifié, et **36 % non affectés à une période** |

## Limites du corpus

⚠️ **Aucun encaissement daté, nulle part.** La question « qui a payé, quand » n'a pas de réponse documentaire dans ces CRG. Toute date de paiement affichée un jour dans MBI viendrait d'une autre source.

⚠️ **EMERY ne descend pas sous le lot.** 522 408,16 € réglés sans aucune affectation à une période.

⚠️ **VIENNE n'imprime aucun agrégat de règlements** — seul le détail par ligne d'appel existe.

⚠️ **Tiers payeur, rejet, remboursement, extourne : NON OBSERVÉS.**

## Tests

```
python public_html/scripts/tests_crg/p5a_certification.py      # unités, natures, périodes, contrôle des totaux
python public_html/scripts/tests_crg/p5b_certification.py      # mailles, affectation, agrégats, écart aux anciens compteurs
python public_html/scripts/crg_non_regression.py               # tout le harnais, P1 → P5B
```

---

# BLOC 6 — CRÉANCES / ENCOURS & ANTÉRIORITÉ 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 31/08/2026.** 23 règles figées. Aucune écriture MBI. **Aucune recouvrabilité, prescription, provision ni perte n'est certifiée ici.**

> ### ⚠️ LE CHIFFRE QUI COMMANDE TOUT LE BLOC
>
> **L'ancien compteur `encours` de GROUPE SIR vaut 7 561 145,94 € — et c'est la somme de SIX PHOTOGRAPHIES du même portefeuille.** 761 416,78 (31/03/2025) + 770 076,98 (30/06) + 793 623,91 (30/09) + 825 167,55 (31/12) + 2 047 946,32 (31/03/2026) + 2 362 914,40 (30/06/2026). **Le même impayé y est compté jusqu'à six fois.** Mathématiquement exact, économiquement absurde.
>
> | ancien `encours` | composition | dernière observation de chaque objet |
> |---|---|---:|
> | LYON hors SIR **952 643,66** | 2 dates additionnées | **486 104,31** |
> | EMERY IMMO **144 484,36** | 3 dates, dont 2 arrêtés isolés | **145 815,90** |
> | VIENNE **60 115,12** | **une seule date** — le seul total légitime | **60 115,12** |
> | GROUPE SIR **7 561 145,94** | **6 dates additionnées** | **2 222 855,04** |
>
> **GROUPE SIR surestimait de 71 %, LYON de 49 %.**

## P6A — CRÉANCES / ENCOURS

| Réf. | Règle | Statut |
|---|---|---|
| **P6A-CREANCE-01** | **UN STOCK N'EXISTE PAS SANS SA DATE, ET DEUX STOCKS NE S'ADDITIONNENT JAMAIS.** L'unité est l'**OBSERVATION DE STOCK LOCATIF** = `lot × locataire × date d'arrêté`. Le même montant au 31/03 et au 30/06 sont **deux observations**, pas deux créances. **Aucun total corpus n'est produit.** | CERTIFIÉE |
| **P6A-CREANCE-02** | **LA VUE PAR DÉFAUT EST « DERNIÈRE SITUATION CONNUE » — décision Emmanuel.** C'est la seule vue de couverture complète, donc la seule utile au gestionnaire. Elle porte ce nom et **jamais « Encours au 30/06/2026 »** lorsque les dates diffèrent ; chaque montant conserve **sa propre date d'arrêté**, son CRG, son PDF/page et sa maille. La vue **« à une date commune »** reste une analyse secondaire et **doit toujours afficher son taux de couverture** — au 30/06/2026, GROUPE SIR ne couvre que **372 lots sur 533** : aucun KPI ne doit laisser croire à une couverture intégrale. | CERTIFIÉE |
| **P6A-CREANCE-03** | **Le PDF dit le stock ; la formule ne fait que le contrôler.** On ne fabrique jamais `créance = appels − règlements` ni `due − paid` : P5B a démontré **0 mouvement daté** et **1 596 547,33 € de règlements sans affectation**. Le stock lu est la colonne **« Impayés »** (`lyon`, `emery_immo`) ou **« Reste dû »** (`septeo_spi`). | CERTIFIÉE |
| **P6A-CREANCE-04** | **Le signe ne fait pas la nature, et la convention se démontre.** Vérifié au PDF : `MELINE Claire` imprime « 1970.44 2044.05 -73.61 » — Total, Réglés, Impayés — donc **réglé plus que dû**, le négatif est une position créditrice. Mais un négatif reporté (`GOIN Enora`, « Solde Antérieur -444.01 ») ne dit pas pourquoi : il reste **CRÉDITEUR — NATURE NON DÉMONTRÉE**. Seul un bloc où `Réglés > Total` porte un **trop-perçu démontré**. | CERTIFIÉE |
| **P6A-CREANCE-05** | **La maille du stock change avec le format, et c'est une donnée.** `lyon` et `emery_immo` impriment un total d'impayé **à la ligne « Totaux » du lot** → *lu*. `septeo_spi` n'imprime **aucun total d'arriéré au lot** : le sien est la **somme de ses cellules « Reste dû » imprimées** → déclaré **calculé**, avec ses composantes. | CERTIFIÉE |
| **P6A-CREANCE-06** | **La dette ne détermine jamais l'occupation.** Le statut vient de **P4B, figée**, et n'est jamais recalculé. Un ancien locataire garde sa créance ; elle n'en fait pas un occupant. | CERTIFIÉE |
| **P6A-CREANCE-07** | **La créance d'un ancien locataire ne passe jamais au suivant.** Sur un lot où A a été remplacé par B, chaque solde reste attaché à son titulaire — jamais fusionné parce que le lot est le même. Mesuré : **21 occupations archivées portent 370 175,93 € de créance** (LYON 7 018,80 · EMERY 422,42 · VIENNE 7 219,44 · SIR 355 515,27). | CERTIFIÉE |
| **P6A-CREANCE-08** | **Un solde nul imprimé n'est pas une absence d'information.** `lyon` et `emery_immo` impriment toujours la cellule « Impayés », fût-elle à 0,00 → **SOLDE NUL DÉMONTRÉ** (521 observations). `septeo_spi` n'imprime rien quand rien n'est dû → **ABSENCE D'INFORMATION** (71 blocs), jamais « solde = 0 ». | CERTIFIÉE |
| **P6A-CREANCE-09** | **On ne compense jamais une dette par un crédit.** Chaque observation reste attachée à son lot, son locataire et sa date. Le brut débiteur et le brut créditeur sont donnés séparément ; le net ne masque jamais le brut. | CERTIFIÉE |
| **P6A-CREANCE-10** | **Un lot sans locataire démontré ne fabrique pas de débiteur.** Le stock y reste **STOCK AU LOT — DÉBITEUR NON DÉMONTRABLE**, avec sa provenance. | CERTIFIÉE |
| **P6A-CREANCE-11** | **Provenance complète, 1 902/1 902** (1 957 avant rectification FOCH/SABY) : `crg_fichier · sha · page · periode_crg · date_arrete · proprietaire · compte · immeuble · lot · locataire · statut_occupation_P4B · libelle_stock_source · montant_source · sens_documentaire · qualification · maille_documentaire · niveau_source`. | CERTIFIÉE |

## P6B — ANTÉRIORITÉ / REPORTS

| Réf. | Règle | Statut |
|---|---|---|
| **P6B-ANTERIORITE-01** | **L'âge d'une créance est moins certifiable que son montant, et il faut l'assumer.** P5B a démontré 0 mouvement daté et 36 % de règlements sans affectation : ventiler un solde final entre « ancien » et « courant » par soustraction serait une invention. | CERTIFIÉE |
| **P6B-ANTERIORITE-02** | **Aucune règle d'affectation n'est créée** — ni FIFO, ni LIFO, ni « mois courant d'abord », ni prorata. `AFFECTATION NON DÉMONTRÉE` (P5B, figée) reste non démontrée. | CERTIFIÉE |
| **P6B-ANTERIORITE-03** | **Aucun aging fabriqué.** Pas de tranches 0-30 / 31-60 / 61-90 : elles supposeraient l'affectation qui n'existe pas. Les classes sont documentaires : *période d'origine démontrée* · *antérieur démontré — période imprimée* · *antérieur démontré — date d'origine non démontrable* · *origine indéterminable*. | CERTIFIÉE |
| **P6B-ANTERIORITE-04** | **Un report n'est pas une créance finale.** Solde antérieur 1 000 + appels 500 − règlements 800 ne fait pas une créance de 1 000 : le report dit l'**ouverture**, P6A lit la **clôture**, P6B raconte l'histoire entre les deux. Les deux sont montrés côte à côte, jamais l'un à la place de l'autre. | CERTIFIÉE |
| **P6B-ANTERIORITE-05** | **Un réénoncé d'arriéré n'est pas un nouvel appel** (`P5A-APPEL-03`, figée). Il sert à **dater** la dette, jamais à l'augmenter. `septeo_spi` réénonce chaque mois impayé du passé, ligne par ligne, avec sa période : **93 réénoncés, 43 636,68 €**. | CERTIFIÉE |
| **P6B-ANTERIORITE-06** | **Les formats sont profondément inégaux devant l'ancienneté, et on ne les force pas.** `septeo_spi` date **chaque euro** · `lyon` et GROUPE SIR impriment « Impayés » **sur chaque ligne de période**, donc l'origine d'un impayé né dans la période est démontrée · `emery_immo` ne l'imprime **qu'au total du lot** : **aucun impayé n'y est daté par période**. | CERTIFIÉE |
| **P6B-ANTERIORITE-07** | **EMERY imprime son report comme une LIGNE DE PÉRIODE, pas comme un champ.** Ses 384 blocs ont `solde_anterieur = 0` ; le report est dans la colonne « Divers » : « Du 01.04.12 Au 30.04.12 Solde Antérieur 4706.51 ». Ne lire que le champ faisait disparaître **toute** l'antériorité d'EMERY. Et cette forme **date le report**, ce que « Solde Antérieur 3700.00 » de LYON ne fait jamais. | CERTIFIÉE |
| **P6B-ANTERIORITE-08** | **On ne fabrique jamais une date manquante.** Quand seul « Solde Antérieur » est imprimé sans période : **ANTÉRIEUR À LA PÉRIODE DU CRG — DATE D'ORIGINE NON DÉMONTRABLE** (1 091 antécédents, 8 383 988,19 €). | CERTIFIÉE |
| **P6B-ANTERIORITE-09** | **LE PIÈGE DE P6A SE REJOUE SUR L'ANTÉRIORITÉ, ET IL A FAILLI PASSER.** Un report est **réénoncé à chaque CRG** : additionner ses occurrences sur six trimestres compte le même arriéré six fois — exactement le défaut de l'ancien `encours`. **Deux notions, deux noms, jamais confondues** : ① **SOMME DOCUMENTAIRE DES OCCURRENCES DE REPORT** = 1 259 lignes, **8 592 239,69 €** — *ce n'est pas un stock* ② **ANTÉRIORITÉ DE LA DERNIÈRE OBSERVATION CONNUE** = 518 objets, **2 901 572,85 €** — la seule sans double comptage temporel. **1 nombre = 1 définition.** | CERTIFIÉE |
| **P6B-ANTERIORITE-11** | **Aucun chaînage déductif — décision Emmanuel.** On ne reconstruit jamais la date d'origine d'une dette entre deux CRG successifs : ni FIFO, ni affectation supposée des règlements, ni `stock T2 − stock T1 = dette née entre les deux` présenté comme vérité documentaire. Une reconstruction déductive pourra exister plus tard comme **analyse**, jamais comme certification. **L'indétermination de la date n'empêche pas P6B d'être certifiable** : `ANTÉRIEUR DÉMONTRÉ — DATE D'ORIGINE NON DÉMONTRABLE` est une information certifiable en soi. | CERTIFIÉE |
| **P6B-ANTERIORITE-10** | **L'historique de stock n'est pas un journal comptable.** Une série T1 1 000 → T2 700 → T3 900 démontre l'**évolution du stock observé**. Elle ne démontre ni quels paiements l'ont réduit, ni quels appels l'ont augmenté. On conserve **HISTORIQUE DE STOCK**, sans inventer le journal. | CERTIFIÉE |
| **P6B-ANTERIORITE-12** | **Provenance complète, 2 112/2 112**, chaque antécédent portant `type_antecedent · periode_origine · date_origine · montant · libelle_source · certitude`. | CERTIFIÉE |

## Chiffres certifiés — 31/08/2026

### P6A — le stock, une ligne par date d'arrêté

| Périmètre | date d'arrêté | observ. | débiteurs | montant débiteur | créditeurs | montant créditeur | nuls | absence |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| LYON hors SIR | 2026-03-31 | 146 | 77 | 480 436,88 | 1 | −188,19 | 68 | 0 |
| LYON hors SIR | 2026-06-30 | 141 | 76 | 472 777,31 | 1 | −382,34 | 64 | 0 |
| EMERY IMMO | 2026-02-15 | 3 | 1 | 800,11 | 0 | 0,00 | 2 | 0 |
| EMERY IMMO | 2026-03-15 | 2 | 1 | 28,00 | 0 | 0,00 | 1 | 0 |
| EMERY IMMO | 2026-03-31 | 379 | 100 | 144 987,79 | 10 | −1 331,54 | 269 | 0 |
| VIENNE | 2026-04-30 | 111 | 40 | 60 115,12 | 0 | 0,00 | 0 | 71 |
| GROUPE SIR | 2025-03-31 | 121 | 99 | 762 768,83 | 1 | −1 352,05 | 21 | 0 |
| GROUPE SIR | 2025-06-30 | 123 | 99 | 771 429,03 | 1 | −1 352,05 | 23 | 0 |
| GROUPE SIR | 2025-09-30 | 124 | 104 | 793 623,91 | 0 | 0,00 | 20 | 0 |
| GROUPE SIR | 2025-12-31 | 129 | 105 | 826 519,60 | 1 | −1 352,05 | 23 | 0 |
| GROUPE SIR | 2026-03-31 | 306 | 232 | 2 077 452,45 | 2 | −29 506,13 | 72 | 0 |
| GROUPE SIR | 2026-06-30 | 372 | 284 | 2 376 907,41 | 2 | −13 993,01 | 86 | 0 |

⚠️ **Ces lignes ne s'additionnent pas.** Provenance **1 902/1 902**.

### P6A — consolidation par dernière observation connue de chaque objet

| Périmètre | dates couvertes | débiteurs | montant débiteur | créditeurs | montant créditeur |
|---|---|---:|---:|---:|---:|
| LYON hors SIR | 31/03 → 30/06/2026 | 77 | **486 104,31** | 1 | −382,34 |
| EMERY IMMO | 15/02 → 31/03/2026 | 102 | **145 815,90** | 10 | −1 331,54 |
| VIENNE | 30/04/2026 | 40 | **60 115,12** | 0 | 0,00 |
| GROUPE SIR | 31/03/2025 → 30/06/2026 | 247 | **2 222 855,04** | 2 | −13 993,01 |

⚠️ **Pas à une date commune** : chaque objet porte la sienne.

### P6A — à qui la créance est rattachée (occupation P4B consolidée)

| Périmètre | actives | montant | **archivées** | **montant** | indéterm. | montant |
|---|---:|---:|---:|---:|---:|---:|
| LYON hors SIR | 29 | 174 643,80 | 3 | **7 018,80** | 45 | 304 441,71 |
| EMERY IMMO | 34 | 45 674,47 | 1 | **422,42** | 67 | 99 719,01 |
| VIENNE | 36 | 44 720,22 | 3 | **7 219,44** | 1 | 8 175,46 |
| GROUPE SIR | 71 | 568 785,94 | 14 | **355 515,27** | 162 | 1 298 553,83 |

### P6B — antériorité

| Périmètre | reports | montant | réénoncés | montant | nés sur la période | montant |
|---|---:|---:|---:|---:|---:|---:|
| LYON hors SIR | 159 | 916 174,25 | 0 | 0,00 | 125 | 109 797,97 |
| EMERY IMMO | 165 | 207 581,97 | 0 | 0,00 | **0** | 0,00 |
| VIENNE | 3 | 669,53 | 93 | 43 636,68 | 99 | 16 478,44 |
| GROUPE SIR | 932 | 7 467 813,94 | 0 | 0,00 | 536 | 1 081 269,27 |

| Périmètre | origine datée | antérieur — période imprimée | antérieur — date inconnue | origine indéterminable |
|---|---:|---:|---:|---:|
| LYON hors SIR | 125 · 109 797,97 | 0 | 159 · 916 174,25 | 0 |
| EMERY IMMO | 0 | **165 · 207 581,97** | 0 | 0 |
| VIENNE | 189 · 59 625,05 | 0 | 3 · 669,53 | 3 · 490,07 |
| GROUPE SIR | 536 · 1 081 269,27 | 0 | 932 · 7 467 813,94 | 0 |

## Exceptions

| Réf. | Constat | Statut |
|---|---|---|
| **P6A-EXC-01** | **VIENNE : 71 blocs sans aucune information de stock.** `septeo_spi` n'imprime « Reste dû » que lorsqu'il reste quelque chose à devoir. **ABSENCE D'INFORMATION, jamais « solde = 0 »** — la différence compte pour P7. | OUVERT |
| **P6A-EXC-02** | **STOCK VIENNE CALCULÉ À PARTIR DE COMPOSANTES DOCUMENTAIRES « RESTE DÛ » CERTIFIÉES — formalisé.** Unité élémentaire : la **cellule « Reste dû » imprimée sur une ligne d'appel**. **192 cellules réparties sur 40 blocs de lot = 60 115,12 €** au 30/04/2026, date d'arrêté unique de VIENNE ; rattachement `lot × locataire × date`, **111/111 avec page**. **Preuve que ce n'est PAS un `appels − règlements`** : appelé 71 605,80 − réglé 60 998,99 = **10 606,81 €**, quand le stock lu vaut **60 115,12 €** — un **facteur 5,7**. L'écart est l'arriéré des mois antérieurs, réénoncé ligne à ligne, que le CRG d'avril n'appelle plus. `niveau_source = calculé` reste enregistré. | FORMALISÉ |
| **P6B-EXC-01** | **EMERY : aucun impayé daté par période.** Le format n'imprime « Impayés » qu'au total du lot — **0 impayé né sur la période démontrable**, exactement comme ses règlements (`P5B-EXC-01`). Son antériorité, en revanche, est **la mieux datée du corpus**. | LIMITE STRUCTURELLE |
| **P6B-EXC-02** | **L'antériorité sans date d'origine, correctement définie.** Le chiffre de **8 384 657,72 €** annoncé au premier passage était une **somme de 1 094 occurrences documentaires** réénoncées sur jusqu'à six dates — il aurait été le nouveau faux gros chiffre, à l'image des 7,56 M€ d'`encours`. Ramené à la **dernière observation de chaque objet** : **376 objets, 2 730 149,54 €** (LYON 485 610,77 · GROUPE SIR 2 243 869,24 · VIENNE 669,53) — **67 % de moins**. Le document imprime « Solde Antérieur » sans période et **aucune date n'est fabriquée**. | OUVERT |

## Tests

```
python public_html/scripts/tests_crg/p6a_certification.py     # stocks datés, qualification, statut P4B
python public_html/scripts/tests_crg/p6b_certification.py     # reports, réénoncés, classes d'antériorité
python public_html/scripts/crg_non_regression.py              # tout le harnais, P1 → P6B
```

---

# BLOC 7 — DÉPENSES / FRAIS / ASSURANCES 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 31/08/2026.** Aucune écriture MBI. **Aucun KPI de rentabilité, aucune récupérabilité juridique n'est décidé ici.**

## Doctrine transverse — la hiérarchie de lecture

> **`SECTION → RUBRIQUE → LIBELLÉ → CONTEXTE → INDÉTERMINABLE`**
>
> ① la **section imprimée** démontre la **famille** ② le **libellé explicite** précise la **sous-nature**, et **prime sur une rubrique héritée ou périmée** ③ le **vocabulaire** enrichit, sans devenir une vérité documentaire ④ le **contexte du bloc** quand une relation structurelle est démontrable ⑤ **indéterminable** quand rien de documentaire ne va plus loin.

⚠️ **SECTION ≠ SOUS-NATURE.** `- Dépenses déductibles -` démontre une **catégorie comptable imprimée** — ni travaux, ni entretien, ni taxe. Elle fait passer une ligne de *rien de démontrable* à *famille démontrée, sous-nature inconnue*, **jamais** à une sous-nature inventée. `- Dépenses de Syndic -`, elle, démontre la copropriété.

⚠️ **`- Dépenses Locatives -` NE DEVIENT JAMAIS UN APPEL LOCATAIRE P5A.** C'est une dépense présentée dans la partie dépenses du CRG. **P5A est figée : rien de P7 ne la modifie.**

### La détection des sections est HYBRIDE — et la limitation est assumée

Mesuré sur douze CRG de deux formats : **SECTION x0 médian 43** (marge gauche) · **rubrique x0 médian 276** (indentée) · les deux sans montant, la section suivie d'une rubrique, la rubrique suivie des écritures. **La géométrie confirme la hiérarchie à trois niveaux — elle ne suffit pas à détecter une section** : « marge gauche + sans montant » rend **221 fausses candidates pour 26 vraies**, un profil resserré **100 pour 25**.

**LIMITATION CONSIGNÉE** : *la détection des sections dépend d'un vocabulaire observé sur les formats certifiés. Une section future portant un intitulé inconnu ne sera pas reconnue automatiquement.*

**MÉCANISME DE SÉCURITÉ** : une ligne ayant la **forme** d'une section sans intitulé reconnu est signalée **`SECTION POTENTIELLE NON RECONNUE — À QUALIFIER`**, jamais ignorée en silence. Vérifié : `IMMOBILISATIONS` est détectée, `derewoP` et `Lot 0012 Appartement` rejetés. Une candidate n'est **jamais** classée P7A/P7B tant que son sens n'est pas démontré.

### FAIL CLOSED — une panne ne doit jamais ressembler à un résultat

⚠️ **CE DÉFAUT A ÉTÉ VÉCU.** `est_section()` rendait une chaîne quand son appelant attendait un couple ; le `except Exception: return {}` qui l'entourait a transformé cette erreur de programmation en **index vide, présenté comme un résultat exploitable**. Le harnais est resté vert. Un CRG illisible et un lecteur en panne rendaient exactement la même chose : rien.

**RÈGLE FIGÉE** : **on n'attrape jamais `Exception`.** On absorbe les seules erreurs qu'un **document** peut provoquer (`ERREURS_PDF` : `OSError`, `PDFSyntaxError`, `PSException`), et on les **compte**. Une `TypeError`, une `ValueError`, une `AttributeError` viennent de **notre** code : elles remontent et rougissent le harnais. **Un résultat vide sur un corpus non vide est une panne, pas un constat** (`exiger_non_vide`). Toute panne produit `ERREUR LECTEUR / CERTIFICATION IMPOSSIBLE`, **jamais un chiffre métier**.

**Neuf gestionnaires audités** : 3 requalifiés **B — panne technique** (`p7_sections`, `p4b_source_pdf.pages_de`, `p4b_certification.decale`), 6 conservés **A — documentaires et mesurés**, tous resserrés. **Plus aucun `except Exception` dans la chaîne de certification.**

## Arbitrages Emmanuel du 31/08/2026 — les trois derniers indéterminables

> **① UN TRANSFERT ENTRE ENTITÉS EST UNE COMPENSATION.** `transfert GPE IMMO vers GPE SIR (1104)`,
> 30 000,00 € : **aucun fonds ne bouge**. Le motif est testé **après** celui du compte d'attente —
> `TRANSFER CPT ATTENTE` est une mise en compte d'attente, pas une compensation.

> **② UNE ÉCRITURE QUI NE NOMME QU'UNE FORME SOCIALE EST UN FINANCEMENT ENGAGÉ POUR LE
> PROPRIÉTAIRE.** `SABY-SCI ST JEAN SAM`, 3 343,64 € : « financement pour SIR d'une autre SCI
> que nous ne gérons pas ». Même logique que l'échéance de prêt — la régie n'aurait pas à le
> faire. ⚠️ **Testé en dernier**, et **après la rubrique** : `POUDEROUX n°202512-0012 - SCI SAONE
> & SABY`, sous la rubrique `Procédure Tribunal`, est un frais d'avocat, pas un financement. Une
> forme sociale dans un libellé n'est pas une nature.

> **③ SEPTEO ÉMET UN CRG PAR IMMEUBLE — ET PAR MANDAT.** La règle certifiée
> *« SEPTEO : 1 CRG par immeuble »* se précise : *« Même propriétaire, 3 immeubles différents donc
> 3 CRG car 3 adresses »*, et *« 2 lots différents dans le même immeuble : il a acheté le 2ᵉ
> appartement, non rattaché au même mandat »*. **Ce sont les LOTS qui départagent, jamais le taux
> de recouvrement des écritures** : deux pièces sans lot commun décrivent des périmètres
> distincts, même si une ligne de syndic identique figure sur les deux. Le seuil de 70 % que
> j'avais posé lisait l'inverse et rendait quatre groupes indéterminables.
>
> **CONSÉQUENCE POUR L'INTÉGRATION** : le périmètre d'un propriétaire réunit **plusieurs CRG au
> même arrêté**. Les compter comme un seul document perdrait des immeubles ; les traiter comme
> des situations séparées éclaterait son périmètre.

**RÉSULTAT.** Sur les 11 groupes à plusieurs pièces — **12 comparaisons** — : **10
complémentaires**, **2 rééditions** (`SABY 0107-2.pdf`, `FOCH 0108.pdf`). Plus aucun groupe
indéterminable. Les 1 049 opérations du compte mandant sont routées vers **neuf destinations, sans
aucune ligne « à qualifier »**, et la file d'arbitrage est **vide**.

## ⚠️ RECTIFICATION DE PÉRIMÈTRE — P7A / P7B, le 31/08/2026

> **Deux corrections en une : ce que le lecteur lisait deux fois, et ce qu'il ne lisait pas.**

### ① UNE PIÈCE GED N'EST PAS UN ÉVÉNEMENT MÉTIER

**ANOMALIE.** `SABY 0107-2.pdf` et `FOCH 0108.pdf` énoncent la **même situation de gestion** que
leur jumelle au même arrêté : même compte, même report, **83 % d'écritures identiques**, et des
écarts qui ne portent que sur la formulation de la rubrique — `Honoraires H.T. Juin 2026` ⟷
`Honoraires H.T.`. Les lire toutes les deux comptait **32 145,84 €** deux fois.

**RÈGLE.** `SITUATION DE GESTION = agence · compte · date d'arrêté` — et pour SEPTEO,
`+ immeuble · lot`. Plusieurs pièces GED peuvent énoncer une même situation : l'une est la
**version de référence**, les autres des **rééditions**. La référence se choisit sur un critère
démontrable — celle qui contient les écritures de l'autre — jamais sur le nom du fichier.
**`sha` reste l'identité de la PIÈCE, jamais celle de l'ÉVÉNEMENT.** Les deux PDF restent en GED ;
c'est le lecteur qui ne lit la situation qu'une fois.

**PREUVE.** Balayage des 458 CRG : **11 groupes** partagent `agence + compte + date d'arrêté`,
soit **12 comparaisons** (un groupe VIENNE porte trois pièces). Verdicts : **2 rééditions
démontrées**, **6 complémentaires** — VIENNE imprime un CRG par **lot**, pas par immeuble —,
**4 indéterminables** (recouvrement de 10 à 33 %), envoyées en arbitrage.

### ② LE CONTENEUR TECHNIQUE NE DÉFINIT PAS LE PÉRIMÈTRE MÉTIER

**ANOMALIE.** P7 ne lisait que `immeubles[].charges` et `immeubles[].mouvements`. Le **compte
courant du mandant** — `mandat.operations` — portait des charges réelles qu'aucune règle
n'excluait : seulement l'ignorance d'une structure.

**RÈGLE.** `SOURCE TECHNIQUE ≠ NATURE MÉTIER`. Une occurrence se classe par ce qu'elle est, jamais
par le conteneur où le lecteur l'a trouvée. **Et `DÉPENSE ≠ PAIEMENT DE LA DÉPENSE`** : les
122 lignes portant une marque de règlement restent hors P7.

**IMPACT.** **73 charges / 228 062,46 €** entrent en P7 — **44 lignes / 124 706,86 €** en P7A
(impôts 26, procédure/avocat/huissier 8, fluides 5, syndic 5) et **29 lignes / 103 355,60 €** en
P7B (prestations de la régie 14, honoraires 13, assurances 2).

### ③ LES TOTAUX RECTIFIÉS

| Côté | Périmètre | Certifié avant | − rééditions | + exhaustivité | **Rectifié** |
|---|---|---|---|---|---|
| P7A | LYON hors SIR | 113 352,62 | — | +4 089,00 | **117 441,62** |
| P7A | EMERY IMMO | 84 871,67 | — | — | **84 871,67** |
| P7A | VIENNE | 14 315,62 | — | — | **14 315,62** |
| P7A | GROUPE SIR | 700 947,55 | −25 069,22 | +120 617,86 | **796 496,19** |
| **P7A** | **TOTAL** | **913 487,46** | **−25 069,22** | **+124 706,86** | **1 013 125,10** |
| P7B | LYON hors SIR | 58 616,26 | — | — | **58 616,26** |
| P7B | EMERY IMMO | 52 085,53 | — | — | **52 085,53** |
| P7B | VIENNE | 7 295,31 | — | — | **7 295,31** |
| P7B | GROUPE SIR | 374 096,93 | −7 076,62 | +103 355,60 | **470 375,91** |
| **P7B** | **TOTAL** | **492 094,03** | **−7 076,62** | **+103 355,60** | **588 373,01** |

VIENNE conserve sa fourchette : borne haute **14 471,34**, écart **155,72 €** indéterminé.

### ④ TROIS RÈGLES DE MÉTHODE, NÉES DE CET AUDIT

> **L'ORDRE DE LECTURE.** `fonction syntaxique de la ligne → nature de l'opération → objet du
> libellé → vocabulaire en appui`. Quatre fois le mot de l'OBJET a été lu comme la NATURE :
> `GESTION DES PRETS` pris pour un prêt, `HONO GESTION ATD` et `CPTE ATTENTE ATD` pour des loyers
> saisis, `GESTION ATD/CONTENTIEUX` pour des frais d'avocat.

> **LE VOCABULAIRE NE DÉFINIT PAS LE PÉRIMÈTRE DE RECHERCHE.** Il **qualifie** une écriture déjà
> détectée ; il ne décide jamais laquelle mérite d'être examinée. `Autres Impôts` et
> `Prestation comp.` étaient absents des motifs certifiés : trois balayages successifs ont trouvé
> davantage parce qu'ils partaient des mots. **Détection exhaustive → structure → qualification.**

> **UNE FAMILLE DÉTECTÉE DOIT AVOIR UNE DESTINATION EXPLICITE.** Faute de quoi elle retombe
> silencieusement dans une autre par vocabulaire — 35 000 € de compte d'attente reclassés en
> charge P7A. Le routeur **échoue** (`ERREUR DE ROUTAGE / CERTIFICATION IMPOSSIBLE`) plutôt que de
> se rabattre. Il contrôle **la famille ET le sens** : le sens seul est partagé par plusieurs
> familles, et le premier garde-fou était trop étroit pour cette raison.

### ⑤ DANS LE DOUTE, ARBITRAGE — JAMAIS INTERPRÉTATION

> Si la nature métier n'est pas démontrable après lecture du contexte, l'écriture devient
> **`INDÉTERMINABLE — ARBITRAGE DEMANDÉ`** : elle part dans la file d'arbitrage avec sa preuve,
> son PDF **ouvert à la page exacte**, son contexte imprimé et une **question fermée à deux ou
> trois choix** — puis le balayage continue. **Une question par RÈGLE MÉTIER, jamais par ligne.**
> La file est elle-même fail-closed : aucune ligne à qualifier n'y échappe.

**RÉCONCILIATION.** Les **1 049 opérations** du compte mandant sont routées vers dix destinations,
**5 001 854,68 €**, fermeture exacte. Harnais rejoué : **14/14**.

## ⚠️ RECTIFICATION DE RÈGLE FIGÉE — `P7A-DEPENSE-11/12`, le 31/08/2026

> **Ce n'est pas une amélioration : c'est la correction d'une règle précédemment certifiée.**

**ANCIENNE RÈGLE.** Deux lignes partageant `agence · compte · immeuble · rubrique · libellé ·
montant · date` étaient tenues pour **une seule dépense réénoncée**. La borne basse n'en comptait
qu'une.

**ANOMALIE DÉMONTRÉE.** La clé confondait « même écriture apparente » et « même événement
métier ». Sur **195 473,48 €** ainsi retirés de la borne basse, **195 317,76 € correspondaient à
des lignes réellement imprimées ailleurs** — le plus souvent dans des CRG de **périodes
différentes**.

**PREUVE.** Les 744 occurrences fusionnées ont été reclassées, position imprimée à l'appui :

| Classe | Occurrences | Montant |
|---|---|---|
| **A — événements distincts démontrés** | 1 168 | 291 215,74 € |
| **B — réénoncés du même événement démontrés** | **0** | **0,00 €** |
| **C — identité métier indéterminable** | 4 | 155,72 € |

`TVA/Honoraires 6,91 €` apparaissait quatre fois sur le compte `01910000` : deux lignes au T1
(`y` 357,7 et 367,3), deux au T2 (`y` 309,7 et 319,3). **Quatre honoraires trimestriels, pas un
réénoncé.** Aucune preuve positive de réénoncé n'a été trouvée dans les 458 CRG. Les 4 occurrences
de classe C sont deux paires VIENNE (`Budget prévisionnel 2026`, `Fonds Travaux ALUR`) dont le
lecteur SEPTEO ne conserve pas l'ordonnée : **on ne tranche pas, on le dit.**

**RÈGLE CORRIGÉE.** Voir `P7A-DEPENSE-11` et `P7A-DEPENSE-12` ci-dessous.

**IMPACT.** Aucune autre règle P1→P7 n'est touchée. Harnais rejoué : **13/13**, seuls les blocs
P7A et P7B changent.

| Périmètre | Côté | Ancienne borne basse | Nouvelle | Écart |
|---|---|---|---|---|
| LYON hors SIR | P7A | 92 905,87 | **113 352,62** | +20 446,75 |
| EMERY IMMO | P7A | 84 736,77 | **84 871,67** | +134,90 |
| VIENNE | P7A | 14 310,54 | **14 315,62** | +5,08 |
| GROUPE SIR | P7A | 534 803,49 | **700 947,55** | +166 144,06 |
| LYON hors SIR | P7B | 57 547,99 | **58 616,26** | +1 068,27 |
| EMERY IMMO | P7B | 52 085,53 | **52 085,53** | 0,00 |
| VIENNE | P7B | 7 255,31 | **7 295,31** | +40,00 |
| GROUPE SIR | P7B | 366 618,23 | **374 096,93** | +7 478,70 |
| **TOTAL** | | **1 210 263,73** | **1 405 581,49** | **+195 317,76** |

⚠️ **`bases()` est commune à P7A et P7B** : la correction s'applique donc aux deux côtés. C'est
une conséquence directe de la même rectification, pas un second changement.

## Frontière P5A ↔ P7A — la doctrine réunie

> ### ⚠️ DÉPENSE PROPRIÉTAIRE ≠ REFACTURATION LOCATAIRE
>
> Une dépense supportée par le propriétaire et sa refacturation éventuelle au locataire sont
> **deux événements comptables distincts**.
>
> **P7A constate la dépense supportée.** **P5A constate l'appel adressé au locataire** — dont
> 2 137,08 € de refacturations locatives identifiées parmi les « Divers ».
>
> **Leur coexistence ne démontre ni leur correspondance, ni une compensation, ni la
> récupérabilité juridique de la dépense.** Aucun netting entre P5 et P7 : une facture de
> plomberie de 300 € côté propriétaire et 100 € refacturés au locataire restent 300 € et 100 €,
> jamais 200 €.
>
> La relation analytique éventuelle entre les deux relève d'une phase ultérieure.

Cette frontière ne crée aucune règle nouvelle : elle **réunit** ce que `P5A-APPEL-01`,
`P5A-APPEL-02` et `P7A-DEPENSE-01` certifient déjà chacun de leur côté. Elle est référencée
depuis les deux blocs pour qu'un lecteur arrivant par l'un ou par l'autre y aboutisse.

## P7A — DÉPENSES

| Réf. | Règle | Statut |
|---|---|---|
| **P7A-DEPENSE-01** | **DÉPENSE ≠ APPEL LOCATAIRE.** Une même réalité peut apparaître comme dépense du propriétaire **puis** comme refacturation au locataire : deux événements, jamais un seul. **P5A ne peut être ni reclassée ni modifiée par P7.** | CERTIFIÉE |
| **P7A-DEPENSE-02** | **DÉPENSE ≠ PAIEMENT.** Une charge imprimée démontre une **écriture documentaire**, pas la date du virement fournisseur, ni son bénéficiaire effectif, ni un décaissement bancaire. | CERTIFIÉE |
| **P7A-DEPENSE-03** | **DÉPENSE ≠ FLUX PROPRIÉTAIRE.** `Loyers versés au propriétaire`, `Soldes mandats`, `Virement`, `Reversement au propriétaire` sont des mouvements régie ↔ propriétaire : **332 439,22 €** écartés vers P8, jamais comptés comme charge supportée. | CERTIFIÉE |
| **P7A-DEPENSE-04** | **DÉPÔT DE GARANTIE ≠ DÉPENSE, DANS LES DEUX SENS — décision Emmanuel.** `Reversement D.G. au prop.` est un flux propriétaire ; `remb DG locataire` est la **restitution d'un passif détenu pour le locataire**. Ni dépense, ni travail, ni entretien, ni avoir, ni remboursement de dépense. Les y laisser ferait dire un jour que le propriétaire a « dépensé » 2 000 € parce qu'il a rendu 2 000 € de dépôt. **9 lignes, 709,81 €, hors de tout total P7.** Le **sens** se lit sur le LIBELLÉ, pas sur la rubrique. | CERTIFIÉE |
| **P7A-DEPENSE-05** | **La hiérarchie de lecture** `section → rubrique → libellé → contexte → indéterminable` s'applique à toute ligne de charge. | CERTIFIÉE |
| **P7A-DEPENSE-06** | **LIBELLÉ EXPLICITE > RUBRIQUE HÉRITÉE.** La rubrique d'EMERY déborde sur la ligne suivante : `Reversement D.G. au prop.` puis `Honoraires H.T. 46.17`. Tester la rubrique d'abord classait **cinq honoraires en dépôt de garantie**. Quand le libellé nomme lui-même sa famille, **il l'emporte** — règle générale, jamais un correctif de ligne. | CERTIFIÉE |
| **P7A-DEPENSE-07** | **SECTION = FAMILLE DÉMONTRÉE, PAS NÉCESSAIREMENT SOUS-NATURE.** `DEPOSE MOQUETTE + POSE SOL` sous `- Dépenses déductibles -` devient *dépense du bien, sous-nature indéterminée* — **jamais « travaux »**. | CERTIFIÉE |
| **P7A-DEPENSE-08** | **Une section peut faire SORTIR une ligne de P7.** `SOLDE MANDAT` est un **titre de section** chez EMERY : les **5 072 lignes** qu'il surplombe sont des flux propriétaire. La section décide donc aussi du **côté**, pas seulement de la famille. | CERTIFIÉE |
| **P7A-DEPENSE-09** | **Le délimiteur change avec le format, la fonction non.** LYON et GROUPE SIR encadrent de tirets, EMERY imprime en majuscules nues ou entre astérisques. Une section est **seule sur sa ligne, sans montant, et nommée par le vocabulaire des sections** — les deux conditions sont nécessaires. Chercher les seuls tirets rendait **EMERY aveugle : 0 ligne située sur 951**. | CERTIFIÉE |
| **P7A-DEPENSE-10** | **Le vocabulaire est un niveau SECONDAIRE de qualification.** `P7A_MOTIFS` et `P7B_MOTIFS` enrichissent ; ils ne remplacent jamais la structure documentaire et ne transforment pas une liste de mots observés en vérité. | CERTIFIÉE |
| **P7A-DEPENSE-11** | **UNE RESSEMBLANCE DOCUMENTAIRE N'EST PAS UNE PREUVE DE DOUBLON MÉTIER.** `compte + rubrique + libellé + montant` ne suffit **jamais** à fusionner deux dépenses. **Chaque occurrence documentaire est un ÉVÉNEMENT DISTINCT, sauf preuve positive qu'elle réénonce le même événement** — l'absence de différence démontrable ne prouve rien. Ce qui démontre la distinction : la **période du CRG**, la date éventuelle, la séquence documentaire, la page, les références, le contexte. ⚠️ **La coordonnée `y` n'est pas une identité métier** : elle corrobore seulement que deux lignes sont réellement imprimées. | **CERTIFIÉE — RECTIFIÉE le 31/08/2026** |
| **P7A-DEPENSE-12** | **ON NE FABRIQUE JAMAIS UN TOTAL EXACT POUR SUPPRIMER UNE INCERTITUDE.** Le total est **exact** quand toutes les occurrences sont départageables, et une **FOURCHETTE** dès qu'il en reste une qui ne l'est pas — fût-elle de 155,72 €. **BORNE BASSE** = les événements distincts, comptés une fois chacun. **BORNE HAUTE** = borne basse + les occurrences **indéterminées** susceptibles d'être distinctes. Une occurrence est indéterminée quand le lecteur n'a pas conservé sa position et qu'on ne peut donc dire si c'est une ligne lue deux fois ou deux lignes identiques. Mesuré : **2 occurrences, 155,72 €, VIENNE** — seul reliquat du corpus. | **CERTIFIÉE — RECTIFIÉE le 31/08/2026** |
| **P7A-DEPENSE-13** | **MAILLE IMMEUBLE ≠ IMPUTATION LOT.** Chez LYON et EMERY le lot est imprimé sur une ligne séparée que le lecteur ne rattache pas : la dépense reste à la maille **immeuble**. Une facture de toiture de 4 000 € n'est **jamais** ventilée en 4 × 1 000 €. VIENNE descend au lot via `ref_lot`. | CERTIFIÉE |
| **P7A-DEPENSE-14** | **Le signe ne fait pas la nature.** Une dépense négative n'est pas automatiquement un avoir fournisseur : sans démonstration par le libellé, elle reste **DÉPENSE NÉGATIVE — NATURE INDÉTERMINABLE**. | CERTIFIÉE |
| **P7A-DEPENSE-15** | **TROIS NIVEAUX D'INDÉTERMINATION, JAMAIS UN SEUL SAC.** **A** famille + sous-nature démontrées · **B** famille démontrée, sous-nature inconnue · **C** rien de démontrable. `Honoraires Annexes` est en B, `Local 0013` en C. | CERTIFIÉE |
| **P7A-DEPENSE-16** | **Provenance complète** : `crg_fichier · sha · page · periode_crg · date_arrete · proprietaire · compte · immeuble · lot · maille_documentaire · date_source · entete_source · section_imprimee · libelle_brut · montant_source · nature · supporte_par · niveau_source`. | CERTIFIÉE |

## P7B — FRAIS / ASSURANCES

| Réf. | Règle | Statut |
|---|---|---|
| **P7B-FRAIS-01** | **DÉPENSE ≠ FRAIS DE GESTION.** P7A porte ce que le **bien** coûte, P7B ce que la **régie** facture et ce que le propriétaire **assure**. P7B est testé **avant** P7A : l'inverse rangerait toute la rémunération de la régie dans les charges d'entretien. | CERTIFIÉE |
| **P7B-FRAIS-02** | **On ne fond pas les familles d'honoraires que le document distingue.** `Honoraires de gestion HT`, `TVA sur hono. de gestion`, `Frais sur DR`, `Honoraires Revenus Fonciers`, `FRAIS DE DOSSIERS` sont imprimés séparément et le restent. | CERTIFIÉE |
| **P7B-FRAIS-03** | **« Garantie des loyers » n'est PAS automatiquement une prime GLI.** P5 avait démontré que `Honoraires garantie des loyers` est une **charge du propriétaire**, jamais un règlement locataire. Mais le mot imprimé est **honoraires** : le document facture un service, il ne démontre pas une police d'assurance. Famille propre conservée. | CERTIFIÉE |
| **P7B-FRAIS-04** | **ASSIETTE × TAUX EST UN CONTRÔLE, PAS LA DONNÉE — et il révèle le sens du montant.** Vérifié sur **160 lignes, 100 % concordantes** : chez LYON, EMERY et GROUPE SIR les **92** lignes portant le calcul donnent `assiette × taux × 20 %` — la rubrique `(*)920.00 x 4.00 %` est attachée à la **ligne de TVA** ; chez VIENNE les **68** donnent `× 1,20`, le montant imprimé étant un **TTC**. **Zéro sans concordance.** Le montant imprimé reste la donnée certifiée. | CERTIFIÉE |
| **P7B-FRAIS-05** | **Deux taux différents, jamais le même champ.** `(*)920.00 x 4.00 %` porte le taux d'**honoraires** et son assiette ; `( 20.00 % )` porte le taux de **TVA**. Les confondre faisait échouer tout contrôle. | CERTIFIÉE |
| **P7B-FRAIS-06** | **La TVA ne se reconstitue pas.** HT et TVA imprimés séparément sont conservés tels quels ; si seul un TTC est imprimé, **ni HT ni TVA ne sont fabriqués**, et réciproquement. | CERTIFIÉE |
| **P7B-FRAIS-07** | **On ne recompte pas ce que P5A a certifié.** Un frais appelé **au locataire** appartient à P5A ; P7B ne compte que ce qui est prélevé **au propriétaire**. | CERTIFIÉE |
| **P7B-FRAIS-08** | **OCCURRENCE ≠ FRAIS MÉTIER UNIQUE**, et **un frais négatif n'est ni un remboursement, ni un avoir, ni une correction** tant que le libellé ne le démontre pas. Provenance complète, `assiette · taux · taux_tva · tva` conservés quand ils sont imprimés. | CERTIFIÉE |

## Exceptions et limites documentaires acceptées

| Réf. | Constat | Statut |
|---|---|---|
| **P7A-EXC-01** | **8 lignes / 3 368,36 € réellement indéterminables** — `CHARGES LOCATIVES` ×3, `réf. : 259+03769 G` ×2, `menage communs`, `Charges Propriétaire`, `LOYER DUBOST DIRECT IMPOT`. Ni section, ni rubrique, ni libellé ne démontrent une famille. Chacune tracée avec PDF et page. **Le système sait qu'il ne sait pas, et le conserve au lieu de l'inventer.** | LIMITE ACCEPTÉE |
| **P7A-EXC-02** | **137 lignes / 75 163,52 € en FAMILLE DÉMONTRÉE — SOUS-NATURE NON DÉMONTRABLE.** Ce n'est **pas une erreur** : l'information est exploitable par MBI et plus saine qu'une fausse précision. | LIMITE ACCEPTÉE |
| **P7A-EXC-03** | **9 mouvements de dépôt de garantie, 709,81 €, hors P7** — 3 vers le propriétaire, 1 vers le locataire, 5 requalifiés en honoraires (rubrique périmée). Conservés avec provenance pour P8. | HORS P7 |
| **P7A-EXC-04** | **332 439,22 € de flux régie ↔ propriétaire écartés**, dont 310 256,68 € de `Loyers versés au propriétaire` chez GROUPE SIR. **Premier contrôle documentaire de P8A.** | HORS P7 |
| **P7-EXC-05** | **La détection des sections dépend d'un vocabulaire observé.** Une section future à intitulé inconnu n'est pas reconnue automatiquement, mais **signalée** `SECTION POTENTIELLE NON RECONNUE — À QUALIFIER`. Jamais ignorée. | LIMITE ACCEPTÉE |

## Chiffres certifiés — 31/08/2026

### P7A — dépenses

⚠️ **Les deux bornes portent leur nom.** `P7A-DEPENSE-12` interdit d'appeler « total métier » une borne basse. Depuis la **rectification du 31/08/2026**, les occurrences ne sont plus fusionnées sur une ressemblance : le total métier exact **est démontré** sur LYON hors SIR, EMERY IMMO et GROUPE SIR, et reste **NON DÉMONTRABLE sur VIENNE**, où 155,72 € d'occurrences ne sont pas départageables.

| Nature | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR |
|---|---:|---:|---:|---:|
| charges de copropriété / syndic | 60 478,53 | 15 869,85 | 6 727,64 | 173 571,77 |
| taxe propriétaire | 2 203,00 | 0,00 | 0,00 | 111 182,00 |
| travaux | 10 326,60 | 1 486,35 | 1 759,62 | 76 718,12 |
| fluides | 4 864,65 | 11 801,39 | 428,49 | 69 334,61 |
| dépense du bien — section imprimée | 1 924,18 | 16 314,77 | 0,00 | 49 216,58 |
| entretien / maintenance | 3 098,37 | 36 461,39 | 3 303,52 | 6 743,92 |
| copropriété / syndic — section imprimée | 470,06 | 0,00 | 0,00 | 27 416,64 |
| procédure / contentieux | 2 864,63 | 0,00 | 0,00 | 11 645,79 |
| facture fournisseur | 4 765,94 | 2 803,02 | 1 791,27 | 4 609,67 |
| dépense locative — section imprimée | 1 909,91 | 0,00 | 0,00 | 4 364,39 |
| diagnostic | 0,00 | 0,00 | 300,00 | 0,00 |
| **BORNE BASSE — ÉVÉNEMENTS DOCUMENTAIRES DISTINCTS** | **117 441,62** | **84 871,67** | **14 315,62** | **796 496,19** |
| **BORNE HAUTE — + OCCURRENCES INDÉTERMINÉES** | **117 441,62** | **84 871,67** | **14 471,34** | **796 496,19** |
| **TOTAL MÉTIER EXACT** | **117 441,62** | **84 871,67** | NON DÉMONTRABLE | **796 496,19** |

### P7B — frais et assurances (totaux métier)

| Famille | LYON hors SIR | EMERY IMMO | VIENNE | GROUPE SIR |
|---|---:|---:|---:|---:|
| honoraires de gestion | 24 452,78 | 30 358,42 | 4 506,07 | 168 841,50 |
| TVA sur honoraires | 4 392,41 | 6 071,70 | 0,00 | 32 680,33 |
| frais de gestion | 2 849,80 | 0,00 | 1 919,00 | 2 566,48 |
| autres honoraires | 1 393,62 | 6 995,55 | 0,00 | 39 102,50 |
| garantie des loyers | 0,00 | 8 659,86 | 830,24 | 0,00 |
| assurance PNO | 8 908,00 | 0,00 | 0,00 | 13 616,00 |
| autre assurance | 15 551,38 | 0,00 | 0,00 | 109 811,42 |

### La passe documentaire

**188 lignes / 100 298,05 € → 8 lignes / 3 368,36 €** — **96,6 % du montant résolu**, sans un seul correctif de ligne. **42 123 lignes situées** par le lecteur de sections.

## Tests

---

# BLOC 8 — FLUX PROPRIÉTAIRE 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 31/08/2026.** Ce bloc certifie **ce que le CRG imprime des mouvements
entre la régie et le propriétaire** — pas le solde du propriétaire, qui relève de P8B.

## Les six inégalités de P8A

> `FLUX PROPRIÉTAIRE ≠ DÉPENSE` · `≠ PAIEMENT D'UNE CHARGE` · `≠ ENCAISSEMENT LOCATAIRE` ·
> `≠ COMPENSATION` · `≠ STOCK` · `OCCURRENCE DOCUMENTAIRE ≠ MOUVEMENT BANCAIRE`.
>
> **`COMPENSATION = AUCUN MOUVEMENT DE FONDS`** — 107 écritures, 1 760 422,27 €, jamais dans un
> total. **`PAS DE NETTING SANS LIEN DÉMONTRÉ`** · **`DOUTE = ARBITRAGE, PAS D'HYPOTHÈSE`** ·
> **`SOURCE TECHNIQUE ≠ NATURE MÉTIER`**.

## Les chiffres de référence

| | Occurrences | Montant |
|---|---|---|
| **FLUX CERTAINS** | **682** | **1 438 325,56 €** |
| régie → propriétaire | 668 | 1 396 391,57 € |
| propriétaire → régie | 14 | 41 933,99 € |
| **objets uniques** | **682** | **0 candidat doublon** |

| Périmètre | régie → prop | prop → régie |
|---|---|---|
| LYON hors SIR | 161 · 244 637,44 | 2 · 5 121,56 |
| EMERY IMMO | 428 · 286 278,72 | 11 · 20 812,43 |
| GROUPE SIR | 79 · 865 475,41 | 1 · 16 000,00 |
| **VIENNE** | **0** | **0** |

⚠️ **VIENNE N'IMPRIME AUCUN FLUX PROPRIÉTAIRE ÉLÉMENTAIRE.** Son CRG ne porte que le solde de
clôture. C'est un **fait documentaire**, pas une panne du lecteur — et le champ que le lecteur
SEPTEO nomme `virement` vaut le solde du compte dans 58 cas sur 60.

## Les règles

| Réf. | Règle | Statut |
|---|---|---|
| **P8A-FLUX-01** | **LE SENS SE LIT SUR LE LIBELLÉ, LE SIGNE NE FAIT QUE LE CONFIRMER.** Mesuré : **668/668 débits** pour `régie → propriétaire`, **14/14 crédits** pour l'inverse. Une discordance est une **anomalie à instruire**, jamais une correction silencieuse. | CERTIFIÉE |
| **P8A-FLUX-02** | **LA SECTION IMPRIMÉE « SOLDES MANDATS » DÉMONTRE L'ACOMPTE PROPRIÉTAIRE** — arbitrage Emmanuel : *« tous les soldes mandat sont des acomptes pro »*. Le vocabulaire ne suffisait pas : `ACOMPTE PRO`, `VRT SIR/BPAURA` et `virement SABY A CARRIER PICHIT SANDRA` sont la même chose sans partager un mot. **Mais un libellé qui se nomme lui-même l'emporte** : une taxe sous cette section reste une taxe. | CERTIFIÉE |
| **P8A-FLUX-03** | **`ACOMPTE PROPRIÉTAIRE ≠ VERSEMENT AU PROPRIÉTAIRE.`** Hors de cette section, la nature est nommée mais le **bénéficiaire bancaire n'est pas démontré** : `ACOMPTE PRO - SIR/BPAURA` nomme une banque, pas un destinataire. **8 lignes / 84 804,00 €** portent leur nature et restent hors du total. | CERTIFIÉE |
| **P8A-FLUX-04** | **CE QUE LA RÉGIE PAIE À LA PLACE DU PROPRIÉTAIRE EST UN FLUX PROPRIÉTAIRE** — échéances de prêt (24 · 79 153,08 €), salaires de son personnel (4 · 15 140,16 €), financement d'une entité hors gestion (1 · 3 343,64 €). *« Nous n'aurions pas à le faire normalement. »* **Aucun n'est une dépense P7A.** | CERTIFIÉE |
| **P8A-FLUX-05** | **TROIS SENS OPPOSÉS DERRIÈRE UN MÊME MOT.** `DIRECT IMPÔTS` / `(ATD)` = loyer **saisi par le Trésor**, reversé au propriétaire **indirectement** ; `DIRECT PRO(PRIÉTAIRE)` = payé **au** propriétaire hors régie ; `payée par vous` = payé **par** le propriétaire. Les confondre dirait l'inverse du document une fois sur deux. | CERTIFIÉE |
| **P8A-FLUX-06** | **LES FLUX INDIRECTS NE S'ADDITIONNENT JAMAIS AUX FLUX DIRECTS.** **55 lignes / 346 153,25 €** — dont 45 loyers saisis pour 342 416,99 €. Un loyer saisi réduit une dette sans qu'un euro transite par la régie : le mêler aux reversements ferait croire à une trésorerie qui n'a pas existé. | CERTIFIÉE |
| **P8A-FLUX-07** | **`MLV` = MAINLEVÉE.** `VIRT MLV TOTALE SAISIE` 222 678,22 € au crédit est la **restitution d'une somme saisie** — *« l'huissier nous rend l'argent qu'il a pris »*. Ni acompte, ni apport : une entrée de trésorerie sans contrepartie à compenser. | CERTIFIÉE |
| **P8A-FLUX-08** | **LE DÉPÔT DE GARANTIE AU CRÉDIT REMONTE VERS LA RÉGIE** — *« à RIOM nous reversons les DG au propriétaire ; quand il faut les rendre au départ du locataire, nous demandons au propriétaire un remboursement »*. Un chèque au crédit sous `Reversement D.G. au prop.` est donc `propriétaire → régie`. | CERTIFIÉE |
| **P8A-FLUX-09** | **LA MISE EN COMPTE D'ATTENTE EST UNE ÉCRITURE INTERNE**, jamais un flux propriétaire — **4 occurrences / 56 000,00 €**. ⚠️ *Limitation consignée* : `réserve` et `attente` ne désignent rien d'autre **dans ce corpus** ; un fonds de réserve de copropriété futur devrait être requalifié. | CERTIFIÉE |
| **P8A-FLUX-10** | **PROVENANCE COMPLÈTE, MAILLE COMPRISE.** Chaque flux porte `qui → vers qui → montant → sens → nature → période → compte/mandat → PDF → page → libellé brut`. La **maille est le COMPTE MANDANT** : jamais ventilée sur les immeubles ni les lots. | CERTIFIÉE |
| **P8A-FLUX-11** | **AUCUNE LIGNE COMPTÉE DEUX FOIS.** Contrôle sur la position imprimée : **0 ligne commune** entre les 5 283 lignes P7A/P7B et les 734 lignes P8A. P5 lit les lots, P6 les stocks : conteneurs disjoints. | CERTIFIÉE |

## Exceptions et limites

| Réf. | Limite | Statut |
|---|---|---|
| **P8A-EXC-01** | **4 rejets de virement** (931,61 D / 412,00 C) annulent un flux compté ailleurs — arbitrage Emmanuel — **mais n'impriment aucune référence au mouvement d'origine**. Ils restent hors total : le total certain peut donc être **supérieur au flux net réel**. **Aucun netting inventé.** | LIMITE ACCEPTÉE |
| **P8A-EXC-02** | **24 lignes / 514 290,57 €** restent hors flux certains faute de sens ou de bénéficiaire démontrable — 16 `VIRT <ENTITÉ>/<BANQUE>` (429 486,57 €) et 8 `ACOMPTE PRO` hors section (84 804,00 €). *« Ne déduis jamais la qualité de bénéficiaire du seul fait qu'une banque apparaît. »* | LIMITE ACCEPTÉE |
| **P8A-EXC-03** | **DATE DU MOUVEMENT NON DÉMONTRABLE** quand le CRG ne l'imprime pas : jamais remplacée par la date d'arrêté, qui daterait le document et non le virement. | LIMITE ACCEPTÉE |
| **P8A-EXC-04** | **Le blocage puis la restitution des comptes SIR** (248 209,16 € débités, 222 678,22 € restitués) est conservé tel qu'imprimé, sans reconstitution : *« gardons tout, mais ne bloquons pas, cela fera l'objet de questions lors des prochaines intégrations »*. | OUVERT |

## Preuves reproductibles

```
python public_html/scripts/tests_crg/p8a_certification.py   # la phase entière
python public_html/scripts/tests_crg/p7_routage_exhaustif.py # 1 049 opérations, fermeture exacte
```

---

# BLOC 8B — COMPTABILITÉ / SOLDE 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 01/09/2026.** Ce bloc certifie **la situation du compte mandant telle
que le CRG la démontre** — pas une comptabilité reconstruite.

## Les inégalités de P8B

> `SOLDE ≠ FLUX` · `REPORT ≠ MOUVEMENT` · `STOCK ≠ FLUX` ·
> `TOTAL DE CONTRÔLE ≠ ÉCRITURE ÉLÉMENTAIRE` · `COMPENSATION ≠ MOUVEMENT DE TRÉSORERIE` ·
> **`UN MÊME MONTANT IMPRIMÉ À PLUSIEURS ENDROITS ≠ PLUSIEURS ÉVÉNEMENTS`**.
>
> **`AUCUN BOUCLAGE ARTIFICIEL`** · **`AUCUN NETTING SANS LIEN DÉMONTRÉ`** ·
> **`ÉCART DOCUMENTAIRE ≠ ERREUR À CORRIGER`**.

## Le périmètre

**386 situations comptables hors VIENNE** = 458 CRG certifiés − 70 VIENNE − **2 rééditions
neutralisées**. Aucun CRG ne disparaît : deux pièces énoncent la même situation qu'une autre et
ne sont comptées qu'une fois. Les **70 CRG VIENNE** ont une structure documentaire différente,
réduite au solde de compte qu'ils impriment.

## Les équations démontrées

| Équation | Résultat |
|---|---|
| `totaux débit − totaux crédit = solde final` | **386 / 386** |
| `report + opérations de mandat + soldes d'immeubles = totaux` | **386 / 386** |
| `règlements locataires − charges = solde d'immeuble` | **803 / 834** |
| chaînage : clôture d'un CRG = report du suivant | **43 contrôles, 0 rupture** |

| Contrôle de non-double-comptage | |
|---|---|
| flux P8A — **chiffre certifié de P8A** | **682** |
| stocks — **population observée en P8B pour ce contrôle**, pas un chiffre P8A | 58 |
| occurrences communes | **0** |

## Les règles

| Réf. | Règle | Statut |
|---|---|---|
| **P8B-SOLDE-01** | **LE DÉTAIL N'EST PAS LE RÉCAPITULATIF.** Le récapitulatif du mandant n'agrège pas les charges ligne à ligne : il reprend le **solde de chaque immeuble**, que le CRG imprime lui-même. Comparer les totaux à la somme de toutes les lignes échoue sur 384 CRG sur 386 et compterait deux fois ce que ce solde agrège déjà. **Trois termes, pas mille.** | CERTIFIÉE |
| **P8B-SOLDE-02** | **LA CHAÎNE EST DÉMONTRÉE, PAS RECONSTITUÉE.** Le solde de clôture d'un CRG devient le report du suivant : **43 contrôles, aucune rupture**. Un CRG sans report imprimé ne confirme ni n'infirme la chaîne — il est compté à part, **jamais rempli par déduction**. | CERTIFIÉE |
| **P8B-SOLDE-03** | **L'ORDRE D'UNE CHAÎNE COMPTABLE EST CHRONOLOGIQUE.** Trier sur la clé de période plaçait `2026-05-13_2026-06-30` avant `2026-T1` et fabriquait **quatre fausses ruptures**. On trie sur la **date d'arrêté**. | CERTIFIÉE |
| **P8B-SOLDE-04** | **SEPTEO N'IMPRIME PAS DE SITUATION, MAIS UNE PHOTOGRAPHIE.** Ni report, ni totaux, ni solde de mandat : seulement un **solde de compte** à la date d'arrêté, dont la provenance est déclarée (`Solde de votre compte`, `Solde final du récapitulatif`, `ligne Virement`…). `STOCK ≠ FLUX` **interdit de relier deux photographies par différence**. Ce n'est pas une lacune du lecteur. | CERTIFIÉE |
| **P8B-SOLDE-05** | **NOM D'IMMEUBLE ≠ IDENTITÉ D'IMMEUBLE.** Deux immeubles peuvent porter le même nom sur des comptes différents — *« 2 agences RIOM, une pour le local commercial, une pour les parkings et archives »*. Indexer les soldes par nom en écrasait un sur deux et comparait le détail d'un immeuble au solde de son homonyme. **L'identité métier reste le périmètre compte / mandat plus les références immeuble / lot que le document fournit.** | CERTIFIÉE |
| **P8B-SOLDE-06** | **LE RANG D'APPARITION N'EST PAS UNE CLÉ MÉTIER.** C'est une **méthode d'appariement documentaire**, valable parce que le lecteur conserve l'ordre des occurrences homonymes dans les deux listes du même PDF. **Il ne sort jamais du contrôle qui l'emploie** : l'écrire dans une clé métier reproduirait, un cran plus loin, le défaut de `P8B-SOLDE-05`. La provenance `crg · page · rang` permet de revenir aux occurrences imprimées. | CERTIFIÉE |
| **P8B-SOLDE-07** | **UNE CLÉ DE CONTRÔLE DOIT DISTINGUER LES OCCURRENCES, PAS LES FONDRE.** Faute de porter l'ordonnée des lignes de mandat et le rang des `reversements`, le contrôle comparait **676 clés pour 682 flux** et **52 pour 58 stocks** — un écart qu'il aurait fallu expliquer au lieu qu'il n'existe pas. **Un chiffre à justifier est souvent un défaut d'outil, pas une propriété du corpus.** | CERTIFIÉE |
| **P8B-SOLDE-08** | **AUCUN BOUCLAGE ARTIFICIEL.** Là où une égalité ne se démontre pas, elle est écrite `ÉCART DOCUMENTAIRE`. Aucun montant d'ajustement, aucun netting supposé, aucun report transformé en flux, aucun stock additionné entre deux dates. | CERTIFIÉE |

## Exception et limite

| Réf. | Limite | Statut |
|---|---|---|
| **P8B-EXC-01** | **31 immeubles sur 834** ne reconstituent pas leur solde depuis leur détail. Trois formes observées : des charges **exactement doubles** du solde (`RUE PAUL CHEVRET`, −7 266,00 contre −3 633,00) — vraisemblablement un immeuble découpé en deux entrées portant les mêmes charges ; un solde imprimé à **0,00** malgré des règlements (`3 COURS DR LONG`, `26 BIS CAZENEUVE`) ; et des cas isolés. **Conservés avec leur provenance PDF/page, jamais redressés pour atteindre 100 %.** | LIMITE ACCEPTÉE |

## Preuve reproductible

---

# RECTIFICATION CERTIFIÉE P5A / P5B / P6A — FOCH/SABY 🟢 FIGÉE

**Validée par Emmanuel le 01/09/2026.** Ce n'est **pas une phase** : c'est une rectification de
trois phases figées, née de la certification P9 et traitée en une seule fois.

## La doctrine qu'elle établit

> **LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF.
> C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER QU'IL CONTIENT.**
>
> Un même document contient donc simultanément des événements **réénoncés** et des événements
> **complémentaires**.
>
> `DOCUMENT DISTINCT ≠ ÉVÉNEMENT MÉTIER DISTINCT`
> `MÊME PAIRE DE DOCUMENTS ≠ MÊME STATUT DE RÉÉDITION POUR TOUTES LES FAMILLES D'ÉVÉNEMENTS`

## Ce qui a été découvert

`FOCH 0108.pdf` et `SABY 0107-2.pdf` couvrent la **même situation de gestion** que `FOCH.pdf` et
`SABY 0107.pdf` — même compte, même date d'arrêté. P7 les traitait comme des rééditions et
neutralisait les fichiers entiers ; P5A, P5B et P6A les lisaient intégralement. Or les deux
pièces ne sont pas dans le même rapport selon la famille d'écritures :

| Famille | Rapport entre les deux pièces |
|---|---|
| **charges** (P7A/P7B) | réédition **stricte** — 9 285,68 = 9 285,68 et 40 860,16 = 40 860,16 au centime |
| **appels** (P5A) | 42 réénoncés **et 75 complémentaires** — avril et début mai, absents de tout le corpus |
| **règlements** (P5B) | 38 réénoncés |
| **encours** (P6A) | 55 réénoncés — deux photographies d'un même stock au 2026-06-30 |

Neutraliser le fichier entier perdait les 75 compléments ; le lire entier comptait 42 appels
deux fois. **Aucune des deux voies n'était juste.**

## Les quatre ensembles, et l'équation

| Ensemble | Définition | P5A |
|---|---|---:|
| **A** | événements comptés une seule fois | 117 |
| **B** | occurrences **en trop** | 42 |
| **C** | événements **absents** de l'ancien total | **0** |
| **D** | exclusions certifiées portées par B | −675,00 € |

> `NOUVEAU = ANCIEN − B + C ± D`

**C est vide, et c'est le point.** Les 75 appels complémentaires étaient **déjà** dans l'ancien
total : les phases lisent toutes les pièces de la GED. Ce qui était faux, c'est que 42 autres y
figuraient **deux fois**. La rectification ne fait que **retirer B** — elle n'ajoute rien.

## Les totaux rectifiés

| PHASE | ANCIEN | DOUBLONS RETIRÉS | MANQUANTS AJOUTÉS | NOUVEAU |
|---|---:|---:|---:|---:|
| P5A — documentaire | 4 761 767,93 | 42 · 28 606,44 | 0 · 0,00 | **4 733 161,49** |
| P5A — appelé au locataire | 4 357 710,67 | 42 · 29 281,44 | 0 · 0,00 | **4 328 429,23** |
| P5B — réglé | 4 477 689,44 | 38 · 19 440,79 | 0 · 0,00 | **4 458 248,65** |
| P6A — population de stock | 8 718 389,08 | 55 · 174 520,05 | 0 · 0,00 | **8 543 869,03** |

**P7A · P7B · P8A · P8B : INCHANGÉES.** ⚠️ Et la ligne P6A est un **DÉNOMBREMENT DE
POPULATION**, jamais un montant métier ni un KPI : `reference_crg_encours_stock_jamais_cumule`
interdit toujours d'additionner des photographies.

## Les règles

| Réf. | Règle | Statut |
|---|---|---|
| **RECT-EVENEMENT-01** | **LA DÉDUPLICATION PORTE SUR L'ÉVÉNEMENT MÉTIER DÉMONTRÉ, JAMAIS SUR LE FICHIER.** Un événement réénoncé est compté **une seule fois** ; un événement complémentaire **reste compté** ; **aucun PDF n'est éliminé** parce qu'une partie de son contenu est une réédition. La provenance documentaire complète — `crg_fichier · sha · page · position` — est conservée sur chaque occurrence retenue. | CERTIFIÉE |
| **RECT-EVENEMENT-02** | **ON NE CONFRONTE QUE CE QUI ÉNONCE LA MÊME CHOSE.** Deux pièces ne sont comparées que si elles couvrent la même **situation de gestion** — `agence · compte · date d'arrêté`, **étendue à `immeuble` et `lot` chez `septeo_spi`** dont chaque pièce ne décrit qu'un immeuble. Sans cette extension, **sept situations VIENNE** seraient confrontées à tort ; avec elle, **0 occurrence VIENNE n'est retirée**. | CERTIFIÉE |
| **RECT-EVENEMENT-03** | **ON NE SOMME PAS LES MULTIPLICITÉS, ON PREND LA PLUS GRANDE.** `P5A-APPEL-14` certifie que deux blocs peuvent porter le même lot et le même locataire **dans un même CRG** : deux occurrences identiques au sein d'une pièce sont **deux événements**. Une situation compte donc chaque événement autant de fois que la pièce qui en énonce le plus. | CERTIFIÉE |
| **RECT-EVENEMENT-04** | **LA PIÈCE RETENUE EST LA PLUS COMPLÈTE, ET LE CHOIX EST DÉTERMINISTE** — celle qui porte le plus d'occurrences pour la situation, départagée par nom de fichier. Un contrôle **lève** si deux occurrences d'un même événement ne portent pas la même valeur : le choix de la pièce déplacerait alors un total en silence. **0 divergence sur le corpus.** | CERTIFIÉE |
| **RECT-EVENEMENT-05** | **UNE EXCLUSION S'APPLIQUE À UN APPEL VIVANT, JAMAIS À UN APPEL RETIRÉ.** La qualification « Divers » ayant couvert **les deux pièces**, l'exclusion de l'occurrence réénoncée reste sans emploi. La retrancher quand même appliquait un dépôt de garantie à un appel disparu et **surestimait le flux de 675,00 €** — d'où un dépôt de garantie à **269 540,50 €** et non 268 865,50 €. Une exclusion orpheline **sans jumeau retenu** lève. | CERTIFIÉE |

## Preuve reproductible

---

# BLOC 9 — RENTABILITÉ / ANALYTIQUE 🟢 CERTIFIÉ / FIGÉ

**Validé par Emmanuel le 01/09/2026.** Ce bloc certifie **les numérateurs, les règles
d'agrégation, les règles de maille et les formules** — **jamais une valeur immobilière**, que le
CRG n'imprime nulle part.

## Les inégalités de P9

> `REVENU ≠ ENCAISSEMENT ≠ TRÉSORERIE ≠ RÉSULTAT ≠ RENTABILITÉ ≠ STOCK` ·
> `DÉPENSE ≠ PAIEMENT` · `APPEL ≠ ENCAISSEMENT` · `FLUX PROPRIÉTAIRE ≠ REVENU` ·
> `COMPENSATION ≠ TRÉSORERIE` · `SOLDE ≠ PERFORMANCE` · `VALEUR DU BIEN ≠ REVENU` ·
> **`RENDEMENT BRUT ENCAISSÉ ≠ TAUX DE RECOUVREMENT`**.

## La hiérarchie métier

> **BIEN / LOT → IMMEUBLE → COMPTE MANDANT → PROPRIÉTAIRE GLOBAL**

Elle commande les agrégations **et** les renvois d'écran. Le **compte mandant** en est un niveau
à part entière : c'est là que P8A démontre les reversements. Sans lui, un écran renvoyait
« au propriétaire » une donnée prouvée un cran plus bas.

**305 propriétaires → 334 comptes → 458 immeubles → 686 biens**, remontée **686/686 sans
ambiguïté** : aucun bien ne remonte vers deux propriétaires. **26 propriétaires portent
plusieurs comptes** — consolidés sous une identité démontrée, jamais fusionnés entre eux.

## Les trois rendements

| | Formule | Numérateur certifié |
|---|---|---:|
| **A — THÉORIQUE** | `loyers appelés ÷ valeur de référence × 100` | **4 328 429,23 €** (P5A) |
| **B — BRUT ENCAISSÉ** | `loyers encaissés ÷ valeur de référence × 100` | **4 458 248,65 €** (P5B) |
| **C — NET TRÉSORERIE** | `reversements démontrés ÷ valeur de référence × 100` | **1 396 391,57 €** (P8A) |

## La matrice — 4 niveaux × 3 rendements, 10 cases sur 12

| ÉCRAN | A · THÉORIQUE | B · BRUT ENCAISSÉ | C · NET TRÉSORERIE |
|---|---|---|---|
| **BIEN / LOT** | 686 | 663 | *disponible au niveau Compte Mandant →* |
| **IMMEUBLE** | 458 | 448 | *disponible au niveau Compte Mandant →* |
| **COMPTE MANDANT** | 334 | 329 | **207** |
| **PROPRIÉTAIRE GLOBAL** | 305 | 300 | 191 |

## Les règles

| Réf. | Règle | Statut |
|---|---|---|
| **P9-RENTA-01** | **LA HIÉRARCHIE EST `BIEN / LOT → IMMEUBLE → COMPTE MANDANT → PROPRIÉTAIRE GLOBAL`.** Elle sert aux agrégations comme aux renvois. Le **compte mandant** n'est pas un détail technique : c'est la maille où P8A démontre les reversements, et l'omettre faisait remonter l'utilisateur un cran trop loin. | CERTIFIÉE |
| **P9-RENTA-02** | **TROIS RENDEMENTS DISTINCTS, JAMAIS FONDUS EN UN SEUL.** Théorique (ce que le patrimoine devait produire), brut encaissé (ce qui est entré), net trésorerie (ce que le propriétaire a réellement reçu). Ils s'affichent **côte à côte** ; aucun ne se déduit d'un autre. | CERTIFIÉE |
| **P9-RENTA-03** | **ON RÉAGRÈGE LES MONTANTS ET LES VALEURS, PUIS ON RECALCULE LE RATIO — JAMAIS DE MOYENNE DE POURCENTAGES.** Une moyenne pondère chaque bien à égalité quelle que soit sa valeur : elle donne autant de poids à un garage qu'à un immeuble entier. Sur des valeurs échelonnées, l'écart avec le taux recalculé atteint **4,31 points**. La moyenne ne redevient juste que par accident. | CERTIFIÉE |
| **P9-RENTA-04** | **LA MAILLE D'AFFICHAGE NE PEUT JAMAIS ÊTRE PLUS FINE QUE LA MAILLE DE LA PREUVE.** Un écran affiche ce qu'il sait démontrer et **renvoie vers le PREMIER niveau supérieur** où l'indicateur complet est démontré — `disponible au niveau X →`. Ni case vide, ni mention technique jetée à l'utilisateur, **ni ventilation inventée pour combler le trou**. | CERTIFIÉE |
| **P9-RENTA-05** | **ABSENCE DE VENTILATION ≠ ABSENCE DE CHARGE.** Les charges démontrées au lot s'affichent au lot (**17 304,81 €**, VIENNE) ; celles démontrées à l'immeuble y restent ; les **228 062,46 €** (73 lignes) démontrés au seul compte y restent aussi. L'écran inférieur signale `charges non ventilées — disponibles au niveau X →`. **Ni réparties, ni cachées, ni intégrées** artificiellement à un rendement inférieur. | CERTIFIÉE |
| **P9-RENTA-06** | **LE NUMÉRATEUR A EST LE FLUX APPELÉ CERTIFIÉ, JAMAIS LE TOTAL DOCUMENTAIRE.** `P5A-APPEL-15` retranche report et dépôts de garantie : bâtir un rendement sur les 4 733 161,49 € documentaires compterait des dépôts de garantie comme du loyer. P9 **importe** ce calcul et **lève** s'il ne retombe pas au centime sur **4 328 429,23 €**. | CERTIFIÉE |
| **P9-RENTA-07** | **`B > A` N'EST PAS UNE ANOMALIE, ET N'AUTORISE AUCUN TAUX DE RECOUVREMENT.** Un encaissement observé sur une période peut solder une créance antérieure : sur 1 155 lots × CRG, `appelé − réglé = restant` ne tient que **762 fois (66 %)**, et le rapport monte à **156 % sur 2025-T1**. Les deux rendements s'impriment, **jamais leur quotient**. | CERTIFIÉE |
| **P9-RENTA-08** | **AUCUN NETTING DANS LE NUMÉRATEUR C.** Les **14 flux propriétaire → régie (41 933,99 €)** s'affichent à côté des 668 reversements, **jamais en déduction** : `P8B-SOLDE-08` interdit tout netting sans lien démontré. | CERTIFIÉE |
| **P9-RENTA-09** | **LA VALEUR DE RÉFÉRENCE N'EST PAS UNE DONNÉE CRG.** Les 458 CRG n'impriment ni prix d'acquisition, ni estimation, ni prix de vente. MBI doit fournir **par bien** `valeur · nature_valeur · date_valeur · source_valeur`, **la même pour les trois rendements**. Sans elle : `RENDEMENT NON CALCULABLE — VALEUR DE RÉFÉRENCE ABSENTE`. Et si une seule manque, le niveau supérieur **n'est plus exhaustif** : il s'affiche avec son périmètre réel, jamais comme le rendement du patrimoine entier. | CERTIFIÉE |
| **P9-RENTA-10** | **AUCUNE ANNUALISATION SILENCIEUSE.** Sous 12 mois de couverture, l'étiquette est `RENDEMENT SUR LA PÉRIODE` et la période est écrite. Multiplier un trimestre par quatre fabriquerait un chiffre que le document ne porte pas. ⚠️ Et **VIENNE est mensuel** : sa fenêtre se compte en mois. | CERTIFIÉE |
| **P9-RENTA-11** | **CE QUE P9 REFUSE DE MESURER, ET CE N'EST PAS UNE LACUNE DU LECTEUR.** `TAUX D'ENCAISSEMENT D'UNE PÉRIODE : NON DÉMONTRABLE` (le réglé éteint des périodes antérieures) · `VARIATION D'ENCOURS : NON DÉMONTRABLE` (`P8B-SOLDE-04` interdit de relier deux photographies par différence) · `TRÉSORERIE NETTE : NON DÉMONTRABLE` (ni date, ni payeur, ni preuve de décaissement) · `RENDEMENT SUR VALEUR DU BIEN : NON DÉMONTRABLE PAR LE CRG SEUL`. **Aucune valeur théorique de 5 % ou 8 % n'est inventée.** | CERTIFIÉE |
| **P9-RENTA-12** | **CE QUI N'ENTRE DANS AUCUN INDICATEUR.** Compensations **1 760 422,27 €** · comptes d'attente **56 000,00 €** · stocks et soldes **126 334,50 €** · loyers saisis **342 416,99 €** · sens non démontré **514 290,57 €**. Ni revenu, ni trésorerie, ni charge : **ils ne rejoignent aucun ratio**. | CERTIFIÉE |

## Contrôles de non-double-comptage

| Contrôle | Résultat |
|---|---|
| événements d'appel portés par plusieurs pièces | **0** |
| occurrences communes charges P7 / flux P8A | **0** |
| appel et règlement additionnés en un « revenu total » | **jamais produit** |
| encours P6A sommé à un flux | **jamais** |
| ventilation compte → immeuble ou immeuble → lot | **aucune** |

⚠️ **P9 ne produit aucun « revenu total ».** Un appel est un **dû**, un règlement son
**extinction** : les sommer doublerait le revenu.

## Exception

| Réf. | Limite | Statut |
|---|---|---|
| **P9-EXC-01** | **3 propriétaires sur 307 seulement atteignent 12 mois de couverture** — 250 en ont ~3, 54 en ont ~6. Les rendements courants sortiront donc massivement en `RENDEMENT SUR LA PÉRIODE`. C'est une propriété du corpus de certification, pas une limite du moteur. | LIMITE ACCEPTÉE |

## Preuve reproductible

---

# MODULE D'INTÉGRATION CRG — RÈGLES ÉPROUVÉES SUR CORPUS RÉEL 🟢 FIGÉ

## P0 → P5 — VALIDÉES PAR EMMANUEL

| phase | objet | statut | empreinte |
|---|---|---|---|
| **P0** | découpage documentaire | ✅ VALIDÉE | `1514aa9b7d7e` |
| **P1** | inventaire face à MBI | ✅ VALIDÉE | `bbf225db7a96` |
| **P2** | patrimoine confronté | ✅ VALIDÉE | `e2c57a111bfb` |
| **P3** | locataires et occupation | ✅ VALIDÉE | `00f169433913` |
| **P4** | finances | ✅ VALIDÉE | `664796213421` |
| **P5 — BILAN AVANT INTÉGRATION** | ce qui SERAIT écrit dans MBI | **✅ VALIDÉ PAR EMMANUEL — 02/09/2026** | `093d43874a06` |

⚠️ **LE RÉFÉRENTIEL EST FIGÉ.** On ne le rouvre pas pour une anomalie d'un futur import.
La marche à suivre pour les prochains CRG est fixée : **test de compatibilité → lecture par
le référentiel → contrôles de couverture → confrontation MBI → isolation des exceptions →
intégration des éléments validés.**

| ce qui arrive | ce qu'on fait | ce qu'on NE fait PAS |
|---|---|---|
| une structure documentaire inconnue | fixture + règle **locale** si elle est démontrable + test | aucune recertification générale |
| une anomalie technique | correction + test + harnais | aucune recertification métier |
| une donnée non démontrable | **arbitrage**, et le reste continue | on ne bloque pas le corpus |
| une contradiction RÉELLE d'une doctrine certifiée | **STOP RÉGRESSION** | seule situation qui rouvre la certification concernée |

⚠️ **CE QUE CES QUATRE JOURS ONT PRODUIT N'EST PAS UN LECTEUR QUI FONCTIONNE SUR UN FICHIER.**
C'est un système qui contrôle aussi **qu'il n'a rien oublié** : `EXACTITUDE ≠ EXHAUSTIVITÉ`.
Un moteur exact sur ce qu'il traite et aveugle sur le reste avait produit 98 lots au lieu de
124, 53 agences au lieu d'une, et un bilan d'intégration bâti sur ces chiffres — sans qu'un
seul test ne bronche.

**Ouvert le 01/09/2026.** Ces règles ne viennent pas du banc de certification : elles ont été
**arrachées à un document réel** — 906 pages, VIENNE, avril à juillet 2026, déposé par Emmanuel
depuis la page d'administration. Chacune corrige un défaut qui s'est produit.

⚠️ **CE BLOC NE CERTIFIE AUCUN MONTANT.** Il régit la LECTURE d'un dépôt et sa CONFRONTATION à
MBI. Les phases P1→P9 restent la seule autorité sur ce que les CRG démontrent.

## Les règles

| Réf. | Règle | Statut |
|---|---|---|
| **INTEG-LIRE-01** | **UN DOCUMENT SANS COUCHE TEXTE N'EST PAS UN DOCUMENT SANS CRG.** Le dépôt réel était un « Microsoft: Print To PDF » : 906 pages d'images, **zéro caractère**. Le moteur a rendu « 0 CRG détecté » — un résultat rassurant pour une panne totale de lecture, exactement ce que `FAIL CLOSED` interdit depuis P7. Une lecture impossible se **déclare** : pièce `ILLISIBLE`, phase `BLOQUÉE`, cause écrite. | ÉPROUVÉE |
| **INTEG-LIRE-02** | **L'OUTIL DE LECTURE DÉCIDE SI LA PAGE EST EXPLOITABLE EN LIGNE.** Mesuré sur 914 pages : pdfplumber **360 s**, pypdf **177 s**, `pdftotext -layout` **13 s**. Et pour un PDF image, l'OCR d'une **bande d'en-tête** suffit à la phase 0 : **0,65 s/page**, soit ~10 min pour 906 pages au lieu de plusieurs heures. | ÉPROUVÉE |
| **INTEG-LIRE-03** | **L'OCR SOUDE LES MOTS ET APLATIT LES COLONNES.** Le bandeau de suite s'imprime « Page 2 » et s'océrise « **Page2** » : exiger l'espace a laissé **222 pages de suite orphelines**. Et « Agence : A3 - REGIE EMERY - VIENNE » se retrouve collé au nom du propriétaire ou à son code postal, d'où **53 libellés d'agence** pour une seule agence. On coupe sur la casse **et** sur le code postal. | ÉPROUVÉE |
| **INTEG-DECOUPE-01** | **C'EST LA RUPTURE QUI OUVRE UN CRG, PAS L'EN-TÊTE.** L'en-tête se répète : chez `septeo_spi` sur **chaque page** du même compte rendu (pages 443, 445, 447 pour un seul CRG), chez `lyon` sur toutes ses pages de garde. Le prendre pour un début a fabriqué **550 CRG là où il y en a 325**. Un en-tête qui répète le même compte ET la même période est une suite. | ÉPROUVÉE |
| **INTEG-DECOUPE-02** | **« CARTE PROFESSIONNELLE » N'IDENTIFIE AUCUNE AGENCE.** Toute agence immobilière française l'imprime en pied de page : s'en servir comme signal d'en-tête a fabriqué **222 faux débuts** à partir des pieds de page VIENNE. Le marqueur de la famille `lyon` est « **COMPTE PERSONNEL** nnnnnnnn ». | ÉPROUVÉE |
| **INTEG-DECOUPE-03** | **UN TRIMESTRE N'EN EST UN QUE S'IL LE COUVRE EN ENTIER.** Le document imprime « Période du 01/04/2026 au 31/05/2026 » **246 fois** — deux mois. La règle qui regardait la seule appartenance au trimestre donnait `2026-T2` à avril-mai **comme** au trimestre complet, fusionnant deux situations de gestion. Un mois commence le 1er et finit le dernier jour ; un trimestre aussi. | ÉPROUVÉE |
| **INTEG-DECOUPE-04** | **UN CRG N'ARRIVE PAS SEUL.** Quand la régie est aussi syndic, l'envoi contient des **APPELS DE FONDS** de copropriété — 33 documents, 112 pages sur ce dépôt. Ce ne sont ni des CRG ni des pages perdues : ils sont **nommés et rangés hors périmètre**. Trois sorts pour une page, jamais deux : rattachée à un CRG, hors périmètre identifié, ou réellement non identifiée. | ÉPROUVÉE |
| **INTEG-DECOUPE-05** | **UNE PAGE BLANCHE EST LE VERSO DE LA PRÉCÉDENTE.** L'impression en PDF en produit **221**. Les laisser non affectées afficherait « 221 pages sans CRG » sur un découpage juste. | ÉPROUVÉE |
| **INTEG-IDENT-01** | **LA PHASE 0 RÉPOND ENTIÈREMENT, OU ELLE NE SE VALIDE PAS.** « Quel CRG → quelle agence → quelle période → quel compte → quelles pages ». Renvoyer l'agence à la phase suivante reviendrait à valider un découpage documentaire sans avoir terminé l'identification documentaire. Une agence ou une période indéterminable **bloque la validation**. | ÉPROUVÉE |
| **INTEG-IDENT-02** | **TROIS PROVENANCES, JAMAIS CONFONDUES.** `LUE` — le PDF l'imprime. `RAPPROCHÉE` — MBI la désigne **sans ambiguïté** depuis le compte mandant. `INDÉTERMINABLE`. Afficher une déduction comme une lecture ferait passer une conclusion pour un fait du document. | ÉPROUVÉE |
| **INTEG-IDENT-03** | **UNE RÉFÉRENCE LOCALE N’EST JAMAIS UNE IDENTITÉ GLOBALE : L’IDENTITÉ MÉTIER DOIT INCLURE SON PÉRIMÈTRE DE PORTÉE.** Le CRG imprime « - Lot 01 - Mandat N/A - » : ce numéro vaut **dans son immeuble**, et le document ne prétend nulle part qu’il soit unique. Grouper la chronologie sur la seule `lot_reference` a fusionné l’appartement 01 de RAYNAL (occupant SANCHEZ, stable sur 4 arrêtés) et l’appartement 01 de MARTINEZ (occupant CHAYNARD, stable sur 5) : **2 références sur 121 portaient 9 des 13 mouvements détectés, tous fabriqués** — dont l’unique « départ démontré » de la phase, alors que l’occupant est encore là au 31/07. Ici l’identité est `compte × référence`. La même exigence vaut partout ailleurs : un numéro de lot, de bâtiment, d’escalier ou de ligne ne s’emploie **jamais seul** comme clé. | ÉPROUVÉE |
| **INTEG-DOUBLON-01** | **UNE CLÉ SIGNALE, ELLE NE QUALIFIE PAS.** `compte × période × arrêté` a levé 18 collisions ; la comparaison des **montants** a montré que **5 étaient des réénonciations** et **13 des situations complémentaires** — mêmes compte, immeuble et période, mais **des lots différents**. Les fondre aurait détruit treize comptes rendus. `DOCUMENT ≠ SITUATION MÉTIER ≠ ÉVÉNEMENT MÉTIER`. | ÉPROUVÉE |
| **INTEG-DOUBLON-02** | **LA TAILLE DU TEXTE NE QUALIFIE RIEN.** Deux OCR d'un même document ne rendent jamais le même nombre de caractères. Seules les **sommes imprimées** font la situation — et une empreinte calculée sur moins de 200 caractères ne démontre rien. | ÉPROUVÉE |
| **INTEG-CONFRONT-01** | **AUCUN RAPPROCHEMENT APPROXIMATIF NE CRÉE UNE IDENTITÉ.** Une correspondance n'est retenue que si elle est **exacte après normalisation** ; une ressemblance donne `À ARBITRER` avec les candidats **nommés**. `TIERS ≠ PROPRIÉTAIRE ≠ COMPTE MANDANT` : un nouveau compte ne fait pas un nouveau propriétaire, et un libellé qui change n'est pas une identité qui change. | ÉPROUVÉE |
| **INTEG-CONFRONT-02** | **UN MÊME OBJET INDEXÉ DEUX FOIS N'EST PAS UNE AMBIGUÏTÉ.** Indexer chaque immeuble sous sa référence **et** son code CRG, puis sous son nom **et** son adresse, l'a fait apparaître en double : **61 immeubles** sont partis à l'arbitrage pour ce seul défaut. Les candidats se dédoublonnent par identifiant avant d'être comptés. | ÉPROUVÉE |
| **INTEG-CONFRONT-03** | **`ABSENT DU NOUVEAU CORPUS ≠ À SUPPRIMER DE MBI.`** Les **69** situations que MBI connaît et que le dépôt ne rapporte pas sont listées **à titre d'information**, sans qu'aucune action ne soit proposée. Un CRG non redéposé ne dit rien du mandat. | ÉPROUVÉE |
| **INTEG-PERF-01** | **UNE PIÈCE, UNE LECTURE.** Le moteur de patrimoine relisait le PDF **à chaque CRG** : 266 lectures d'un document de 587 Mo, et l'analyse ne finissait jamais. Envoyer toutes les plages d'un coup la ramène à **26 secondes**. | ÉPROUVÉE |
| **INTEG-COUV-01** | **EXACTITUDE ≠ EXHAUSTIVITÉ.** La phase 2 était JUSTE sur les 98 lots qu’elle traitait et FAUSSE sur la population : le document en imprime **124**. Elle a été scellée, validée, et le bilan d’intégration s’est construit sur son chiffre amputé — rien ne l’a signalé, il a fallu compter à la main. Toute phase démontre désormais **`ATTENDUE = EXAMINÉE + EXCLUE EXPLICITEMENT`** et **`POPULATION INEXPLIQUÉE = 0`**, sans quoi elle ne se valide pas. L’attendu ne se mesure jamais sur soi-même : il vient des phases qui lisent la même population. | ÉPROUVÉE |
| **INTEG-COUV-02** | **UNE PHASE AVAL NE DOIT JAMAIS CONNAÎTRE UN OBJET QUE L’AMONT IGNORE.** Les frontières comparent les **identités**, pas des totaux, et nomment les absentes. Certaines sont des **égalités** (un lot connu de la phase 3 doit l’être de la phase 2), d’autres des **inclusions démontrées** (la phase 4 refuse de nommer un occupant quand le bloc en porte deux, elle en nomme donc moins). Le contrôle s’exécute à chaque passage `Pn → Pn+1`, jamais au bilan final : c’est en attendant la phase 5 qu’on a perdu 26 lots. | ÉPROUVÉE |
| **INTEG-COUV-03** | **UNE POPULATION COMMUNE SE LIT À UN SEUL ENDROIT.** Trois phases lisaient les lots chacune avec son propre motif : la plus ancienne ignorait les blocs « Suite », les références d’un seul caractère et les espaces d’OCR. La segmentation vit dans `crg_integration_lots.py`, et les phases 2, 3 et 4 y lisent la même chose. Une règle dupliquée est une règle qui divergera. | ÉPROUVÉE |
| **INTEG-COUV-04** | **UNE ÉTAPE QUI NE TOURNE QU’À LA MAIN N’EXISTE PAS.** `crgi_qualifier_collisions()` n’avait AUCUN appelant : elle n’avait jamais été lancée que manuellement. Le premier replay de la phase 0 l’a donc silencieusement perdue, et 18 CRG sont restés sur « MÊME CLÉ, CONTENU DIFFÉRENT » — ni uniques, ni réénonciations, donc invisibles pour toutes les phases suivantes. Toute étape appartient au moteur. | ÉPROUVÉE |
| **INTEG-OCCUP-04** | **UN BLOC DE LOT PEUT PORTER PLUSIEURS OCCUPANTS SUCCESSIFS, ET LE DOCUMENT LE DIT.** « Locataire: DE SOUSA Jordan, Bail du 01/06/2025 **au 04/06/2026** » puis « Locataire: GONIN Marine, Bail du 22/06/2026 », même page, même lot — 8 blocs du dépôt. La phase 3 ne gardait que le premier, la phase 4 que le dernier : deux phases, deux locataires, et l’argent attribué au mauvais occupant. Une observation par occupant ; et quand un bloc en porte plusieurs, la phase 4 **n’en désigne AUCUN**. | ÉPROUVÉE |
| **INTEG-OCCUP-05** | **UNE FIN DE BAIL IMPRIMÉE EST UNE PREUVE — BORNÉE À L’ARRÊTÉ.** « Bail du … **au** … » figure 45 fois : le départ est ÉCRIT, plus déduit d’une absence. Mais 13 congés sont datés APRÈS la date d’arrêté et décrivent un occupant **toujours en place** ; sans cette borne, 42 faux anciens locataires. `ABSENCE ≠ DÉPART` reste vrai — la mention imprimée s’y ajoute, elle ne s’y substitue pas. | ÉPROUVÉE |
| **INTEG-RAPPRO-01** | **`CONTRIBUTIF ≠ NOUVEAU`, ET `OBSERVÉ ≠ À CRÉER`.** 1 330 mouvements portaient sur des situations que MBI possède déjà : ce n’étaient pas 1 330 décisions humaines, mais 1 330 confrontations que le moteur n’avait pas faites. La règle de preuve est en couches, le montant en DERNIER : situation, puis libellé (le staging est le **préfixe** du libellé MBI, qui recopie la ligne entière montant compris), puis lot (le lot MBI est le **suffixe** du lot document — « 01 » pour « 276-01 », jamais une inclusion libre qui confondrait « 01 » et « 101 »), puis le sens et le montant. Un libellé générique comme « Solde » ne désigne rien. `MÊME MONTANT ≠ MÊME ÉCRITURE` : aucune fusion automatique. | ÉPROUVÉE |
| **INTEG-RAPPRO-02** | **NORMALISATION N’EST PAS RAPPROCHEMENT APPROXIMATIF.** MBI enregistre les occupants soudés — « ALOUILotfi », « BERRUYERThierry » : comparer « ALOUI LOTFI » à « ALOUILOTFI » ne rapprochait qu’UN locataire sur 119, et déclarer les 118 autres « à créer » aurait fabriqué **88 doublons**. On ignore accents, casse et séparateurs, parce que la différence est démontrée — **aucune autre**. Deux noms qui diffèrent d’une lettre restent deux noms. | ÉPROUVÉE |
| **INTEG-ECRAN-03** | **UN ARBITRAGE QU’ON NE PEUT PAS ENREGISTRER N’EST PAS UN ARBITRAGE.** L’écran posait sept décisions, listait leurs choix fermés et leurs conséquences — et n’offrait **aucun champ pour répondre**. C’est un constat qu’on relit indéfiniment, pas une décision. Chaque ligne porte désormais son choix et sa précision libre, enregistrés **immédiatement** — un bouton « enregistrer » en bas d’une page de 90 lignes perd la moitié des réponses au premier rechargement. Un choix hors de ceux que la règle propose est REFUSÉ, et retirer sa décision EST une décision. **DÉCIDER N’EST PAS INTÉGRER** : la réponse vit en staging, datée et signée ; le plan continue de décrire ce que le DOCUMENT démontre. | ÉPROUVÉE |
| **INTEG-ECRAN-04** | **UNE CHAÎNE JAVASCRIPT NON FERMÉE TUE TOUS LES BOUTONS, EN SILENCE.** Un `join('` ouvert sur deux lignes a suffi : le navigateur abandonne le bloc `<script>` ENTIER, plus aucun écouteur ne s’attache, la page reste parfaitement belle — et « Valider le bilan » ne répond plus. Aucune erreur PHP, aucune ligne de log, aucun test au rouge : le module était mort côté client. Le harnais vérifie donc que le JS de la page **se compile** et que **chaque bouton porte son écouteur**. `CE QUI N’EST PAS SUR LA PAGE N’EXISTE PAS` — et un bouton qui ne répond pas n’est pas sur la page. | ÉPROUVÉE |
| **INTEG-COUV-05** | **UN COMPTEUR GLOBAL MASQUE UNE POPULATION : LE PLAN BOUCLE FAMILLE PAR FAMILLE.** Deux grands totaux peuvent se refermer alors qu’une famille en perd la moitié. Les 5 successions locatives étaient comptées **deux fois** — `CRÉER` pour l’occupation entrante, `ARCHIVER` pour la sortante — alors que ces deux actions portent sur des OBSERVATIONS DIFFÉRENTES : 483 verdicts pour 478 observations, invisibles dans le total général. Chaque famille compare désormais sa population source **sur sa propre maille** — objets pour les objets, observations pour les observations, mouvements pour les mouvements — et exige `ÉCART = 0`. Une occupation observée sur sept périodes n’est pas sept objets à écrire. | ÉPROUVÉE |
| **INTEG-TEST-02** | **LE MULTI-CORPUS REPOSE SUR DES FIXTURES, PAS SUR LE REJEU DE TOUS LES PDF HISTORIQUES — ET ON LE DIT.** Les suites éprouvent les règles `septeo_spi` et `lyon` sur des fixtures minimales représentatives ; les PDF du corpus certifié ne sont pas tous présents sur le poste. C’est donc une **excellente non-régression sur les cas connus**, et non une preuve qu’un format futur sera reconnu. C’est exactement pour cela que `crg_compatibilite.py` existe : le prochain fichier doit dire **très tôt** « compatible » ou « nouvelle structure », au lieu de faire découvrir le problème après trois jours. | LIMITE ASSUMÉE |
| **INTEG-TEST-01** | **UN TEST QUI N’A JAMAIS ÉTÉ VU ROUGE NE PROUVE PAS QU’IL SAIT DÉTECTER L’ERREUR.** Un harnais entièrement vert peut n’être qu’un harnais qui ne regarde rien — l’empreinte de la phase 2 existait et n’était jamais appelée. Chaque incident possède sa fixture et son test ; et l’**ÉPREUVE DES TESTS** réintroduit le bug en mémoire, exige que le test échoue, puis restaure. Un test resté vert sur son propre bug est signalé MUET. | ÉPROUVÉE |
| **INTEG-SCEAU-01** | **UNE EMPREINTE DE CERTIFICATION SCELLE LE CONTENU MÉTIER, JAMAIS L’IDENTITÉ TECHNIQUE DE SES LIGNES DE STAGING.** Les empreintes des phases 2, 3 et 4 portaient l’`id` d’`AUTO_INCREMENT` ; or ces phases **suppriment et réinsèrent** leurs lignes à chaque analyse. Une relecture **strictement identique** décalait donc les identifiants et faisait varier l’empreinte : la phase se serait déclarée **périmée sans qu’un seul fait métier ait changé**. Un sceau qui crie au loup ne vaut pas mieux qu’un sceau muet. Une empreinte sérialise le contenu démontré — référence, verdict, montant, nature, maille, provenance, page — puis **trie les lignes**, pour que l’ordre de lecture n’ait lui non plus aucune influence. Et elle s’éprouve sur **trois** essais, pas deux : **replay identique → non périmée**, **modification → périmée**, **restauration → non périmée**. C’est le premier essai qui manquait. | ÉPROUVÉE |
| **INTEG-ECRAN-01** | **CE QUI N'EST PAS SUR LA PAGE N'EXISTE PAS.** Une étape menée au terminal puis racontée n'est pas livrée. La qualification des collisions et l'inventaire ont dû être rebranchés sur l'écran. Et un bilan placé sous un tableau de 325 lignes est **invisible** : le détail se replie, le parcours reste en tête. | ÉPROUVÉE |
| **INTEG-ECRAN-02** | **UNE VALIDATION APPARTIENT À EMMANUEL.** Elle ne se pose ni au terminal ni « pour gagner du temps ». Une phase validée porte sa date, son auteur et **l'empreinte du résultat examiné** : si l'analyse est rejouée et que le résultat change, la validation est marquée périmée. | ÉPROUVÉE |

## Ce que le premier dépôt réel a produit

| | |
|---|---:|
| pages analysées · rattachées · hors périmètre · non identifiées | **906 · 794 · 112 · 0** |
| CRG documentaires → situations → réénonciations | **325 → 266 → 59** |
| agence lue — une seule, après correction de la découpe | **A3 - REGIE EMERY - VIENNE**, 325 / 325 |
| comptes · propriétaires lus · déjà dans MBI · absents | **72 · 72 · 45 · 27** |
| immeubles : objets · identiques · modifiés · nouveaux · à arbitrer | **80 · 38 · 2 · 7 · 33** |
| lots : **identités `compte × référence`** · identiques · nouveaux | **124 · 35 · 89** |
| — dont deux références portées chacune par deux comptes distincts | **122 références, 124 identités** |
| occupations · locataires · dont plusieurs occupants dans un bloc | **478 · 122 · 8 blocs** |
| mouvements financiers · indéterminable · réimpressions | **7099 · 1 · 118** |
| rapprochement MBI : déjà présents · nouveaux · à arbitrer · contradictions | **416 · 873 · 23 · 17** |
| écritures dans les données métier de MBI | **0** |

⚠️ **CHAPONOST N'EST PAS DANS CE DÉPÔT.** Les 620 occurrences du nom sont la ligne de pied de
page « SARL REGIE EMERY siège social 10 place Maréchal Foch 69630 CHAPONOST » — le **siège
social**, pas une agence gestionnaire. L'en-tête `Agence:` porte `A3 - REGIE EMERY - VIENNE` sur
les 325 CRG.

## Preuve reproductible

La page d'administration elle-même :
`admin/admin_crg_integration.php` → déposer → analyser → valider phase par phase.
```
python public_html/scripts/tests_crg/p9_certification.py
python public_html/scripts/tests_crg/p9_rentabilite.py
```

```
python public_html/scripts/tests_crg/p5_rectification_test.py
```

Modules historiques conservés — ils documentent la découverte, pas l'état actuel :
`p5a_foch_saby.py` et `p5_rectification_foch_saby.py`.

```
python public_html/scripts/tests_crg/p8b_certification.py
```

```
python public_html/scripts/tests_crg/p7_sections_test.py    # grammaire + hiérarchie + fail-closed
python public_html/scripts/tests_crg/p7a_certification.py   # dépenses, mailles, unicité
python public_html/scripts/tests_crg/p7b_certification.py   # frais, assiette/taux/TVA
python public_html/scripts/tests_crg/p7_sections.py         # lent — régénère l'index des sections
python public_html/scripts/tests_crg/p7a_residuel_pdf.py    # lent — contexte PDF du résiduel
python public_html/scripts/crg_non_regression.py            # tout le harnais, P1 → P7B
```
