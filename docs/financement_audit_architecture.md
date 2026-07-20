# FINANCEMENT — Audit d'architecture (aucun code)

> Objectif : dossier **collaboratif partagé** autour des financements, dettes et procédures
> financières d'un propriétaire. Pas un logiciel bancaire/comptable/juridique : un **espace de
> vérité partagée** entre propriétaire, avocat, expert‑comptable, notaire, Régie, (banque,
> commissaire de justice). Le **document est la source** ; la donnée garde toujours son **origine**.

---

## 0. Recommandation en une ligne (pour les pressés)

**Solution C, mais élevée d'un cran : ne pas construire « un module Financement », construire un
*moteur de dossier financier collaboratif* dont Financement est le premier TYPE.** On réutilise les
**primitives déjà inventées par le module Créancier** (lien polymorphe, staging IA, ACL par dossier,
échéancier, versements, fil de discussion) et on ajoute **une seule brique vraiment neuve et
structurante : un modèle de données *append‑only à provenance*** (le « fait »). C'est cette brique
qui résout d'un coup la traçabilité, le versioning, la validation multi‑métiers et le multi‑source —
et qui rend l'architecture *pérenne* (surendettement, succession, liquidation… sans refonte).

---

## 1. Architecture actuelle du module Créancier — ce qui est réutilisable

Le module Créancier a déjà résolu, proprement, la plupart des problèmes que Financement repose.
Principe affiché et respecté : **il ne possède rien, il agrège l'existant par liaison polymorphe**.

| Objet existant | Rôle | Réutilisable pour Financement ? |
|---|---|---|
| `creancier_dossier` | Tête de dossier (débiteur, risque, statut) | **Non tel quel** — sémantique « recouvrement/litige ». Sert de *patron*, pas de table. |
| `creancier_dossier_lien` | **Lien polymorphe** dossier↔entités (`entity_type` TIERS/BIEN/SOCIETE…, `role_dossier`, `montant_garanti`) | ✅ **Patron à réutiliser** (le mécanisme, pas la table) |
| `creancier_dossier_item` | Éléments typés (PROCEDURE/ECHEANCE/DETTE/RISQUE/DECISION/ACTION) | ✅ concept réutilisable |
| `creancier_doc_analyse` | **Staging extraction IA** (`donnees_json`, `confidence`, `statut` a_valider/valide/rejete) | ✅✅ **Cœur** — c'est déjà le modèle « document→données à valider » |
| `creancier_dossier_acces` | **ACL par dossier** (`id_user`, `niveau` lecture/edition/pilote) | ✅ patron des droits |
| `creancier_echeancier` | Trésorerie prévisionnelle (prevu/recu) | ✅ concept réutilisable |
| `creancier_versement` | Paiements rattachés | ✅ concept réutilisable |
| `creancier_dossier_message` | Fil de discussion du dossier | ✅ patron du chat collaboratif |
| `creancier_saisie` | Saisies (attribution loyer, immobilière…) | Spécifique recouvrement — **pas** dans Financement |

**API / écrans / patterns réutilisables :**
- Fiche 360 : `inc/fiche_360_layout.php` (header/breadcrumb/bannière) + `creancier_dossier360.php`
  (2 colonnes onglets + chat permanent) = **le cockpit unique** à cloner.
- Rendu 360 « descriptor‑driven » : `inc/fiche_360_descripteurs.php` (déclare table/attributs/échéances/pièces) — extensible.
- GED : `ged_document_links` (`entity_type` VARCHAR **libre** → `'FINANCEMENT'` sans migration GED),
  `gus_commit_document()`, `gdl_documents_for_entity()`.
- Référentiel personnes : `tiers` + `tiers_roles` (rôle `creancier` financier déjà présent ; **on
  réutilise `creancier`** pour le prêteur, décision validée — pas de nouveau rôle).
- Liens entités : `biens.id_proprietaire → proprietaires.id_tiers → tiers`, `biens.id_immeuble`.

**Risque de l'architecture actuelle (à connaître) :** les liens polymorphes n'ont **aucune FK dure**
(intégrité applicative uniquement) et le staging IA est **par module** (dupliqué : bailleur_baux_analyses,
creancier_doc_analyse…). Si Financement recrée *encore* un staging IA à lui, on aura 3 copies du même
patron. → argument fort pour **généraliser**, pas dupliquer (cf. §12).

---

## 2. Ce qui doit réellement être créé pour Financement

Uniquement ce qui n'existe pas :

1. **Une tête de dossier financier générique** (`fin_dossier`) — parce que `creancier_dossier` porte une
   sémantique de recouvrement (risque, saisies) qui ne colle pas à un financement sain.
2. **Le modèle « fait à provenance » (append‑only)** — la vraie nouveauté (voir §7 et §11). N'existe nulle part.
3. **Un staging d'import** (documents IA **et** Excel) alimentant des *faits candidats* — généralisation du
   patron `creancier_doc_analyse`.
4. **Une table de liaison biens/immeubles** au dossier (patron `dossier_vente_bien` / `creancier_dossier_lien`).

Tout le reste (personnes, GED, échéancier, versements, discussion, ACL, fiche 360) = **réutilisation de patrons**.

---

## 3. `financement_dossier` OU `creancier_dossier` ?

**Réponse : `fin_dossier` distinct — mais les deux se référencent.**

- `creancier_dossier` = **litige/recouvrement** (débiteur, niveau_risque vert/orange/rouge, saisies).
- `fin_dossier` = **gestion collaborative d'un financement** (souvent *sans* litige).
- Forcer un financement dans `creancier_dossier` : pollue les listes créancier, impose un « risque » non
  pertinent, casse dès qu'il n'y a pas de contentieux → **non**.
- **Lien de cycle de vie** : quand un financement dégénère (impayés, assignation), il **alimente** un
  `creancier_dossier` (le recouvrement). On stocke la relation (un `fin_dossier` ↔ N `creancier_dossier`).
  Les deux vivent, chacun sa sémantique. C'est la réponse propre à ton hésitation A/B/C.

---

## 4. Éviter la duplication (créanciers, saisies, échéanciers, paiements, événements, docs, discussions)

| Élément | Comment on NE duplique pas |
|---|---|
| Créanciers / prêteur / garant | = `tiers` (rôle `creancier`), rattachés au dossier par **lien polymorphe** (role_dossier=preteur/garant/…) |
| Saisies | On **réutilise `creancier_saisie`** en le rattachant au dossier créancier lié (pas de saisie dans Financement) |
| Échéanciers | Patron `creancier_echeancier` — soit table partagée, soit `fin_echeance` identique de structure |
| Paiements | Patron `creancier_versement` — un paiement est un **fait** (voir §7) à provenance |
| Événements | Table d'événements = **journal du dossier** (patron `creancier_dossier_message` + audit_log) |
| Documents | `ged_document_links` `entity_type='FINANCEMENT'` — **zéro table doc** |
| Discussions | `creancier_dossier_message` cloné (fil par dossier) |

Règle d'or : **une donnée financière n'est jamais un champ figé, c'est un *fait* daté et sourcé** (§7).

---

## 5. Droits (le point le plus sensible : intervenants EXTERNES)

Modèle : **ACL par dossier** (patron `creancier_dossier_acces`) + **rôle d'intervenant**.

`fin_dossier_acces` : `id_dossier`, `identite_type` (user | tiers | jeton), `identite_id`,
`role_intervenant` (proprietaire | avocat | comptable | notaire | banque | commissaire | regie),
`niveau` (lecture | contribution | validation | pilote), `perimetre` (tout | juridique | montants | garanties).

- **Propriétaire** : ne voit que SON dossier → patron « cage bailleur » (rôle 9/10, `enforce_scope`) +
  accès via `acl_grants` / jeton. Lecture + confirmation de paiements uniquement.
- **Avocat / notaire / comptable externes** : accès **par jeton scopé** (patron du lien de dépôt sécurisé
  `/p/…` déjà éprouvé) → un **portail dossier en lecture/validation ciblée**, sans compte MBI complet.
  L'avocat ne voit que ses dossiers ; le notaire uniquement les éléments « garanties ».
- **Le périmètre de validation découle du rôle** : comptable → valide les *montants* ; avocat → valide le
  *juridique* ; notaire → valide les *garanties* ; propriétaire → confirme les *paiements* ; régie → pilote.
  (C'est exactement le §6, résolu par le couple `role_intervenant` × `perimetre`.)

---

## 6. Validations (extrait → validé / contesté)

Chaque **fait** (§7) porte un **statut de validation par domaine** :
`statut` ∈ { extrait_ia, saisi, importe, **proposé**, **validé**, **contesté**, obsolète }.
`validé_par` (user/tiers), `valide_role` (comptable/avocat/notaire/proprietaire), `valide_at`, `motif_contestation`.

- Une info **extraite** par l'IA arrive `extrait_ia` (jaune, comme la doctrine tricolore MBI).
- Le **comptable** la passe `validé` (domaine montants) **ou** l'**avocat** la passe `contesté` (domaine juridique)
  avec motif → un **nouveau fait** peut être ajouté en réponse (jamais d'écrasement).
- La « valeur courante » d'un attribut = le **dernier fait validé** (ou le plus récent si aucun validé), les
  autres restent visibles dans l'historique. → cohérent avec la doctrine vert/jaune existante.

---

## 7. Versions (assignation 120k → jugement 110k → protocole 95k)

**C'est LE point qui décide de la robustesse.** On ne modélise **pas** un champ `montant` sur le dossier.
On modélise un **fait append‑only** :

`fin_fait` (le cœur du module) :
`id`, `id_societe`, `id_dossier`, `attribut` (ex. `montant_creance`, `taux`, `mensualite`, `capital_restant`,
`rang_hypotheque`…), `cible_type`/`cible_id` (BIEN/IMMEUBLE/DOSSIER/TIERS, polymorphe),
`valeur_num` / `valeur_txt` / `valeur_date`, `devise`,
**provenance** : `source_type` (ged | saisie | excel), `source_doc_id` (ged_documents.id), `source_page`,
`ia_confidence`, `import_batch_id`, `saisi_par`, `saisi_at`,
**validation** : `statut`, `validé_par`, `valide_role`, `valide_at`,
`remplace_fait_id` (chaînage de version, nullable), `actif` (1 = fait courant retenu).

- Assignation 120k, jugement 110k, protocole 95k = **3 faits** sur `attribut='montant_creance'`, chacun avec
  son document source, sa date, son niveau de confiance. **Aucun n'est écrasé.**
- La fiche affiche la **valeur retenue** (dernier validé) + un pictogramme « 3 versions » ouvrant l'historique
  daté et sourcé.
- Bonus : ce modèle est **event‑sourcing‑léger** → auditabilité totale, et il absorbe *n'importe quel* attribut
  futur sans migration (EAV à provenance).

---

## 8. Imports Excel (comptable) sans casser l'existant

Même pipeline que l'IA documentaire, **généralisé** :

`fin_import` (batch) : fichier, type (paiements | prets | echeanciers | soldes), déposé_par, date, statut.
`fin_import_ligne` : ligne brute + mapping → **produit des FAITS candidats** (`source_type='excel'`,
`import_batch_id`, `source_page`=n° ligne).

- Import **non destructif** : il **crée des faits**, ne modifie jamais un fait existant.
- **Réconciliation** par clé (n° prêt, date, montant) : si le fait existe déjà à l'identique → ignoré ;
  s'il diffère → nouveau fait proposé (le comptable arbitre). Idempotent.
- Le comptable a `role_intervenant='comptable'` + `niveau≥contribution` → droit d'importer, pas de piloter.

---

## 9. Liens avec les autres modules

- **GED** : `ged_document_links` `entity_type='FINANCEMENT'` (+ chaque **fait** pointe `source_doc_id`).
- **Propriétaire** : `tiers` (tête du dossier `id_tiers`).
- **Bien / Immeuble** : `fin_dossier_cible` (polymorphe BIEN/IMMEUBLE, patron `dossier_vente_bien`).
- **Créancier** : relation `fin_dossier ↔ creancier_dossier` (bascule en recouvrement).
- **Transaction / Vente** : un bien financé peut être en `dossier_vente` → on **lit** le lien (un bien vendu
  solde/purge un financement — information affichée, pas dupliquée).
- **Tableau de bord** : vues agrégées (encours total, impayés, procédures en cours) par société/agence.

---

## 10. La meilleure fiche 360 : UN cockpit

Un seul écran (patron `creancier_dossier360.php` + `fiche_360_layout.php`), 2 colonnes :
- **Gauche (onglets)** : Synthèse (attributs retenus + alertes) · Biens/Garanties · Prêts & échéances ·
  Procédures (assignation→jugement→protocole, en *timeline de faits*) · Documents (GED) · Intervenants.
- **Droite (fixe)** : intervenants + statut validations + **fil de discussion permanent**.
- Chaque donnée affichée est un **fait** → clic = son historique sourcé.

---

## 11. Documents analysés : chaque donnée montre son ADN

Directement porté par `fin_fait` (§7) : chaque valeur affiche **origine** (source_type), **document**
(source_doc_id → ouverture GED), **page** (source_page), **confiance IA** (ia_confidence),
**qui a validé** (validé_par + valide_role) et **quand** (valide_at). C'est natif, pas un ajout.

---

## 12. Challenge de l'architecture — ce que je remets en cause

1. **Ne pas nommer le besoin « Financement » au niveau des tables.** Le vrai objet est un
   **« dossier financier collaboratif »**. Financement en est le **type #1**. Sinon, chaque futur besoin
   (surendettement, succession…) refera un module.
2. **Le champ figé est l'ennemi.** La tentation « `montant`, `taux`, `mensualite` en colonnes du dossier »
   est un piège : impossible d'y loger la provenance, le versioning et la validation. → **modèle de faits**.
3. **Ne pas re‑créer un 3ᵉ staging IA.** Généraliser `*_doc_analyse` en **un** pipeline
   document→faits candidats réutilisable (créancier, bail, financement…).
4. **Externes = jetons scopés, pas des comptes.** Réutiliser le patron du lien sécurisé pour un *portail
   dossier* avocat/notaire/comptable.

### Pérennité (le point souvent oublié) — accueillir les dossiers futurs
Grâce au triptyque **`fin_dossier.type` + lien polymorphe + `fin_fait` (EAV à provenance)**, l'architecture
absorbe **sans refonte** :

| Futur dossier | Ce qui change | Ce qui NE change PAS |
|---|---|---|
| Surendettement | `type='surendettement'` + attributs (plan BDF, moratoire) | faits, provenance, validation, ACL, 360, GED |
| Liquidation | `type='liquidation'` + intervenant `mandataire` | idem |
| Succession | `type='succession'` + intervenants (notaire, héritiers via tiers) | idem |
| Redressement judiciaire | `type='redressement'` + attributs (plan, AJ) | idem |
| Restructuration de dettes | `type='restructuration'` | idem |

→ Un **nouveau type = de la configuration** (attributs déclarés, rôles autorisés), **pas une migration de fond**.
C'est ce qui distingue une architecture *pérenne* d'une architecture seulement *fonctionnelle*.

---

## Objets à créer (minimum) vs réutilisés

**À créer :** `fin_dossier` (tête + `type`), `fin_dossier_cible` (biens/immeubles polymorphe),
`fin_dossier_acces` (ACL + rôle intervenant + périmètre), **`fin_fait`** (faits append‑only à provenance/validation),
`fin_import` / `fin_import_ligne` (Excel + IA → faits candidats). Rôle GED `entity_type='FINANCEMENT'` (aucune migration GED).

**Réutilisés (aucune duplication) :** `tiers`/`tiers_roles` (rôle `creancier`), `ged_document_links` + `gus_commit_document`,
`fiche_360_layout` + patron `creancier_dossier360`, patrons `creancier_dossier_acces` / `_message` / `_echeancier` /
`_versement`, liens `biens.id_proprietaire` / `biens.id_immeuble`, `creancier_dossier` (référencé, pas absorbé),
`dossier_vente` (lu, pas dupliqué), pipeline staging IA (à généraliser).

**Migrations éventuelles :** création des tables `fin_*` (additives, idempotentes, `id_societe`) ; option
« généralisation du staging IA » en une phase séparée (refactor non bloquant).

---

## Plan de développement par tranches (post‑validation, sans code ici)

- **T0 — Décision d'architecture** (ce document) : valider *moteur de dossier financier + modèle de faits*.
- **T1 — Socle** : `fin_dossier` (+type), `fin_dossier_cible`, `fin_dossier_acces`, **`fin_fait`**. Multi‑tenant.
- **T2 — Cockpit 360** (lecture) : fiche unique, faits affichés avec provenance, biens/garanties, GED, discussion.
- **T3 — Saisie manuelle + validation** : ajout de faits, workflow extrait→validé/contesté par rôle.
- **T4 — Pipeline documents (IA)** : dépôt GED → extraction → faits candidats → validation.
- **T5 — Import Excel** : batch → lignes → faits candidats, réconciliation non destructive.
- **T6 — Intervenants externes** : portail jeton scopé (avocat/notaire/comptable/propriétaire).
- **T7 — Ponts** : bascule créancier (recouvrement), lecture transaction/vente, tableau de bord agrégé.

---

## Recommandation finale (argumentée)

Je retiens **la Solution C, généralisée en « moteur de dossier financier collaboratif »**, pour trois raisons :

1. **Zéro duplication** : on réutilise les primitives éprouvées du module Créancier (lien polymorphe, ACL,
   staging, échéancier, versements, discussion, 360) au lieu de les recopier.
2. **Robustesse** : le **modèle de faits append‑only à provenance** répond *nativement* à la traçabilité (11),
   au versioning (7), à la validation multi‑métiers (6) et au multi‑source GED/saisie/Excel (1, 8) — là où des
   champs figés sur un dossier échoueraient.
3. **Pérennité** : `type` + faits génériques accueillent surendettement, succession, liquidation, redressement,
   restructuration **par configuration**, pas par refonte.

Je **déconseille B** (absorber `creancier_dossier` : sémantique de recouvrement inadaptée, pollution des listes)
et **A** (indépendance totale : recopie exactement ce que Créancier a déjà résolu). C = le juste milieu qui
respecte l'existant *et* prépare l'avenir.

---

# DÉCISION VALIDÉE (Emmanuel) — parti pris définitif, orienté pragmatisme

Validation de l'orientation, avec une réserve : **rester très pragmatique** pour un module
utilisable vite. Le modèle de faits reste le **moteur invisible** ; il ne doit pas rendre
l'écran ni le développement abstraits.

## Parti retenu
Un **dossier financier collaboratif distinct**, relié au module Créancier, **sans fusion et
sans duplication**. Créancier reste le moteur spécialisé (procédures, saisies, échéanciers de
recouvrement, risques). Le dossier financier = l'espace commun régie / propriétaire / avocat /
comptable / notaire.

## Modèle HYBRIDE à 2 niveaux (le point clé de la réserve)
- **Niveau 1 — structures explicites** (tables + écrans compréhensibles) pour les objets
  principaux fréquents : dossier, participants, documents, décomptes, paiements, échéances,
  biens concernés, dossiers Créancier liés, imports Excel.
- **Niveau 2 — informations sourcées** (`fin_fait`) uniquement pour : infos extraites des
  documents, données variables selon le type de dossier, versions successives, validations,
  contradictions (assignation / jugement / protocole / décompte).
  → souplesse **sans** transformer tout le module en système générique.

## Vocabulaire À L'ÉCRAN (jamais « fait »)
`fin_fait` reste technique en base. À l'écran : « information extraite », « donnée du
document », « montant communiqué », « situation », « version », « donnée validée ».

## Tables
**À créer maintenant :** `fin_dossier`, `fin_dossier_lien`, `fin_dossier_acces`, `fin_fait`,
`fin_decompte`, `fin_reglement`, `fin_evenement`, `fin_import`, `fin_import_ligne`.
**À réutiliser :** `tiers`, `tiers_roles`, `ged_document_links`, `creancier_dossier`,
`creancier_saisie`, `creancier_versement`, `creancier_echeancier`, composants de fiche 360,
liens propriétaire/bien/immeuble.
**À NE PAS recréer :** table créanciers, table personnes, GED propre au financement, 2ᵉ gestion
des saisies, 2ᵉ gestion des versements de saisie, table générique biens/sociétés.

## `fin_dossier` — « Dossier financier » (nom fonctionnel)
`type` : financement bancaire · dette/créancier · procédure financière · restructuration ·
succession · liquidation · surendettement · autre.
**Au lancement : seulement 3 types** (Financement bancaire, Dette/créancier, Procédure
financière). Les autres : l'archi ne les empêche pas, mais **pas d'écran dédié** au départ.

## Lien Créancier — `fin_dossier_lien` (polymorphe)
Types : `CREANCIER_DOSSIER`, `CREANCIER_SAISIE`, `BIEN`, `IMMEUBLE`, `SOCIETE`, `TIERS`,
`DOSSIER_VENTE`. Un dossier financier ↔ 0, 1 ou N dossiers Créancier.
**Créancier n'est pas seulement lu** : depuis le cockpit, les habilités peuvent consulter
procédures/saisies/échéances/versements, ajouter document/observation/action/échéance, et
**ouvrir directement le dossier Créancier**. Les modifs complexes (saisie, procédure) restent
faites dans les écrans Créancier.

## Validations — simples (pas de workflow lourd)
Statuts suffisants : **Extrait automatiquement · À vérifier · Confirmé · Contesté · Remplacé ·
Informatif**. Domaine éventuel : juridique · comptable · notarial · financier · opérationnel.
Chaque donnée : origine · statut · (domaine) · personne · date. *Ex. Indemnité contractuelle
8 000 € — source assignation p.12 — contestée par l'avocat, motif « susceptible de réduction ».*

## Import Excel — sas obligatoire (jamais de modif directe)
dépôt → reconnaissance colonnes → aperçu → rapprochement (créanciers/paiements/prêts) →
doublons → validation → création d'infos sourcées → conservation du fichier. Chaque ligne
conserve : fichier source, feuille, n° de ligne, auteur, date, statut d'intégration.

## Accès externes — pragmatique
Portail par dossier **sécurisé**, avec au départ un **lien + authentification légère** (ne pas
figer « pas de comptes »). Exigences minimales : identifier la personne, historiser ses actions,
révocation immédiate, accès limité à un dossier, gestion des documents confidentiels, anti-transfert
du lien, expiration / auth complémentaire. Pour les pros récurrents (avocat, comptable) → **compte
externe réel** deviendra préférable plus tard.

## Cockpit unique — 6 parties (V1)
1. Synthèse · 2. Documents et informations extraites · 3. Montants et décomptes ·
4. Créanciers, procédures et saisies · 5. Paiements, échéances et actions ·
6. Participants et échanges.
Hypothèques/garanties : d'abord dans Synthèse ou Montants, onglet propre plus tard si confirmé.

## Plan pragmatique — 6 tranches
- **T1 Dossier collaboratif** : création, propriétaire, participants, accès, GED, liens (biens,
  immeubles, sociétés, dossier Créancier).
- **T2 Documents & extraction** : dépôt (assignations, décomptes, tableaux), staging IA, infos
  extraites (source/page/confiance), validation manuelle.
- **T3 Décomptes & montants** : principal, intérêts, agios, indemnités, frais, total, versions,
  contesté/validé.
- **T4 Créancier intégré** : saisies, échéanciers, versements, procédures, navigation vers le
  dossier Créancier.
- **T5 Excel & paiements** : import, rapprochement, paiements, doublons, historique.
- **T6 Portail externe** : avocat, comptable, notaire, propriétaire, validations par compétence,
  échanges et demandes de pièces.

## Cap de la V1 (besoin réel)
Charger les documents → en extraire soldes / indemnités / agios / frais → validation
collaborative → suivi des paiements → afficher les procédures Créancier liées.
