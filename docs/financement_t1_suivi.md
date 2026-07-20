# FINANCEMENT — Tranche 1 : document de suivi

Socle du **dossier financier collaboratif**. Respecte la « DÉCISION VALIDÉE » de
`financement_audit_architecture.md`. **Aucun débordement sur T2.**

## Schéma des tables (3, le minimum)

### `fin_dossier` — la tête
`id, id_societe, id_agence, type(financement_bancaire|dette_creancier|procedure_financiere),
libelle, id_tiers (propriétaire), id_societe_concernee (OU société), pilote_user_id,
statut(ouvert|en_cours|suspendu|clos), confidentialite(normal|confidentiel|restreint),
synthese, created_by, created_at, updated_at`.

### `fin_dossier_lien` — liens POLYMORPHES (référence, jamais de copie)
`id, id_societe, id_dossier, entity_type(CREANCIER_DOSSIER|CREANCIER_SAISIE|BIEN|IMMEUBLE|SOCIETE|TIERS|DOSSIER_VENTE),
entity_id, role_lien, note, created_by, created_at` — UK(id_dossier, entity_type, entity_id).

### `fin_dossier_acces` — ACL par dossier
`id, id_societe, id_dossier, identite_type(user|tiers|jeton), identite_id,
role_intervenant(proprietaire|avocat|comptable|notaire|banque|commissaire|regie|pilote),
niveau(lecture|contribution|validation|pilote), perimetre, actif` — UK(id_dossier, identite_type, identite_id).

**Historique** = table existante `audit_log` (via `AuditLog`), pas de table dédiée.
**Documents** = `ged_document_links` avec `entity_type='FIN'` (type canonique GED de FINANCEMENT ;
la catégorie du document est rangée dans `relation_type`). **Pas de table doc.**

## Fichiers créés
- `inc/migrations/20260721a_financement_socle.php` (3 tables, idempotente)
- `inc/financement.php` (helper : libellés, ACL/scope, CRUD, liens polymorphes + résolution,
  synthèse créancier lecture-seule, participants, GED, `fin_related_block()`)
- `financement_liste.php` (liste + modal de création)
- `financement_360.php` (cockpit 6 parties)
- `api/financement_save.php`, `api/financement_lien.php`, `api/financement_acces.php`, `api/financement_ged.php`

## Fichiers modifiés
- `inc/sidebar_agency.php` — entrée **💶 Financement** (super admin uniquement, T1)
- `creancier_dossier360.php`, `bien_360.php`, `immeuble_360.php`, `tiers_360.php` — bloc
  « Dossiers financiers » (via `fin_related_block()`, visible super admin, vide sinon)

## Routes & API
- Pages : `financement_liste.php`, `financement_360.php?id=<id>`
- API : `financement_save.php` (create/update), `financement_lien.php` (search+add+remove),
  `financement_acces.php` (search user/tiers + add/remove), `financement_ged.php` (search + attach + detach)

## Contrôles d'accès
- Pages & écritures API : super admin **ou** rôle 1/7. Lecture d'un dossier : `fin_can_view` =
  super admin, **ou** rôle 1/2/7 de la **même société**, **ou** `fin_dossier_acces` explicite.
- **Multi-tenant strict** : tout scopé `id_societe` ; `fin_scope_ok()` avant toute écriture.
- `require_login()` partout ; **aucun accès par identifiant devinable** (scope + login obligatoires).
- Recherche d'entités à lier : scopée société. CSRF (`verify_csrf_any('financement')`) sur toutes les écritures.

## Cockpit 6 parties (fiche 360)
1. **Synthèse** (fonctionnel : sujet, type, pilote, statut/confidentialité éditables, synthèse ;
   + biens/immeubles concernés : ajout/retrait)
2. **Documents & informations extraites** (fonctionnel : rattacher un doc GED existant + catégorie,
   ouvrir, retirer le lien ; l'extraction IA = T2)
3. **Montants & décomptes** → « Fonction disponible dans une prochaine tranche » (pas de données fictives)
4. **Créanciers, procédures & saisies** (fonctionnel : rattacher un dossier Créancier, **synthèse
   lecture-seule** — statut, risque, créancier principal, prochaine échéance — + « Ouvrir le dossier Créancier »)
5. **Paiements, échéances & actions** → « Fonction disponible dans une prochaine tranche »
6. **Participants & échanges** (fonctionnel : ajout/retrait de participants user/tiers avec rôle+niveau)

## Tests réalisés (DB locale `maboximmo`, société 3)
- Migration appliquée : 3 tables créées.
- Création d'un dossier → créateur automatiquement **pilote** (ACL).
- Liens BIEN + CREANCIER_DOSSIER : ajoutés, **résolus** (labels réels : « TMP-… », « SIR — Groupe SIR »).
- **Synthèse créancier** lue sans copie : statut=surveillance, risque=**rouge**, créancier principal=**SIP**.
- Participants : ajout OK.
- **GED** : attach (entity_type `FIN`, catégorie `decompte`) → visible par la requête de la fiche → detach OK.
- **Multi-tenant** : `fin_list(999)` = 0 (isolation confirmée).

## Points restant à traiter (hors T1)
- **Charger un NOUVEAU document** depuis la fiche (T1 fait « rattacher un document existant » ; le
  nouvel upload passera par le module FluxBox ciblant l'entité `FIN` — à câbler).
- Le bloc « Dossiers financiers » sur les fiches est **visible super admin** (déploiement restreint T1) ;
  à ouvrir aux rôles concernés quand le module sortira du périmètre super admin.
- Tout le reste = Tranches 2→6 (IA/extraction, décomptes, règlements, Excel, hypothèques, portail externe).

## Livrable T1 — vérifié
Créer un dossier ✓ · le retrouver dans la liste ✓ · ouvrir la fiche 360 ✓ · voir propriétaire/société ✓ ·
rattacher biens/immeubles ✓ · rattacher dossier(s) Créancier ✓ · rattacher/consulter documents GED ✓ ·
participants & droits internes ✓ · retrouver depuis les fiches proprio/bien/immeuble/créancier ✓ ·
accès sidebar réservé super admin ✓.

## Règle FIGÉE (2026-07-21)
- **`FIN` est le type GED canonique du module Financement** (`gdl_normalize_entity_type('FINANCEMENT') = 'FIN'`).
  → Ne plus utiliser `'FINANCEMENT'` dans les **nouvelles** liaisons `ged_document_links` : toujours **`FIN`**.

## Priorité post-T1 (avant le cœur de la T2)
« Le document remplit d'abord le dossier » → **charger un NOUVEAU document depuis la fiche** est prioritaire :
ouvrir le dossier → charger un document → **FluxBox ciblé `FIN` + id du dossier** → retrouvé automatiquement
dans la fiche → conservé dans la GED générale. À traiter en **mini-tranche T1.1** (ou intégré au périmètre T2
si cela ne le ralentit pas).
