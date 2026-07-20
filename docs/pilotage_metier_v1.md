# Pilotage Métier — V1 (Service Location)

Module pilote de recensement et répartition des missions par service métier.
Conçu multi-services dès l'origine ; seul le **Service Location** est alimenté en V1.

## Ce qui est livré (Tranches 1 + 2)

| Livrable | Fichier |
|---|---|
| Migration (10 tables `pilotage_*`) | `inc/migrations/20260717c_pilotage_metier.php` |
| Référentiel + seed idempotent | `inc/pilotage_seed.php` |
| Runner de seed (admin) | `admin/pilotage_seed.php` |
| Accès données + réaffectation | `inc/pilotage.php` |
| API réaffectation exécutant | `api/pilotage_assign.php` |
| API détail mission (modal) | `api/pilotage_task.php` |
| Page 3 colonnes + modal | `pilotage_service_location.php` |
| Entrée sidebar (managers) | `inc/sidebar_agency.php` |

## Conventions réutilisées (pas recréées)

- **Multi-tenant** : `id_societe` (+ `id_agence`). PAS de `tenant_id`. Filtrage sur chaque requête.
- **Collaborateurs** : table `users` (`prenom, nom, fonction, id_societe`). Aucun nom codé en dur : le seed résout les prénoms dynamiquement dans la société.
- **Auth / droits** : `require_login()`, `current_role_id/societe_id()`, réaffectation réservée rôles 1/2/7 + super admin.
- **GED** : rattachement via `ged_entity_type` (ex. `BAIL`) — brancheable sur `gus_commit_document()` en Tranche 4.
- **Lien sécurisé** : réutilisera `dr_create_request()` + `/p/document_depot.php` (Tranche 4). Le champ `default_doc_template` pointe déjà `candidat_locataire`.

## Modèle de données (clés)

- `pilotage_services` / `pilotage_categories` : arborescence (unique par `id_societe`+`slug`).
- `pilotage_tasks` : **mission** (tricolore `documentation_status` red/yellow/green ; `automation_level`). Slug **déterministe** (translittération manuelle, stable Windows/Linux → idempotence cross-env).
- `pilotage_task_assignments` : rôles multiples (`executor/validator/supervisor/backup/informed`), `is_primary`. Clé unique (task,user,role).
- `pilotage_task_checklist_items` / `_steps` / `_resources` / `_legal_rules`.
- `pilotage_task_instances` : **action réelle** (occurrence liée à un dossier) — structure prête, alimentée en Tranche 5 (Ma journée). `dedup_key` unique anti-doublon.
- `pilotage_task_history` : journal (réaffectations, validations).

## Règles importantes

- **Idempotence** : le seed ne recrée rien (upsert par slug) et **ne réécrase jamais une mission passée en vert** par un humain.
- **Réaffectation par glisser-déposer** : change l'exécutant principal **sans supprimer** validateur/superviseur (vérifié en test).
- **Missions non attribuées** : si le prénom du collaborateur n'existe pas dans `users`, la mission apparaît en colonne 3 « Non attribuées » (comportement voulu).

## Vérifié en test (DB locale `maboximmo`)

- Migration + re-migration idempotentes ; 10 tables créées.
- Seed : 2 services, 24 catégories, **97 missions**, 112 affectations (société de test avec Claudine/PE/Ludivine/Juliette/Colin).
- Réaffectation → nouvel exécutant principal, validateur conservé, historique écrit.
- « Préparer un bail » : procédure + 8 items checklist + 4 étapes + rattachement GED `BAIL`.

## Reste à connecter (tranches suivantes)

- **T3** : modal — édition procédure/checklist, validation tricolore (boutons Valider / Repasser en jaune), onglet Historique complet.
- **T4** : bouton « Envoyer un lien de chargement » → `dr_create_request()` ; affichage pièces reçues/manquantes par personne (candidat/garant).
- **T5** : Planning métier + Ma journée (génération d'instances depuis fréquences + échéances + liens expirants), dont la **mission RH mensuelle salaire** (instance individuelle le 22, ouvre le module Salaires existant, statuts À préparer→Validé définitivement, vue responsable).
- **Permissions granulaires** `pilotage.*` (actuellement : gating par rôle + `acl_grants` à brancher).

## Mise en route

1. `admin/admin_migrations.php` → appliquer `20260717c_pilotage_metier`.
2. `admin/pilotage_seed.php` → exécuter le seed pour la société (idempotent).
3. Sidebar → **Service Location** (visible managers/admins).

---

## MàJ Tranche 3 + corrections (affectation par poste, modal opérationnel, i18n FR)

### Affectation par POSTE (plus de prénom en dur)
- Migration `20260717d_pilotage_postes.php` : `pilotage_task_postes` (affectation canonique par poste), `pilotage_poste_mapping` (poste→compte MBI réel), `pilotage_task_versions` (versioning tricolore).
- Le seed affecte les missions à 5 **postes** (responsable, gestionnaire, accueil, assistante_location, assistant_polyvalent). Aucune résolution floue : `admin/pilotage_seed.php` propose un **écran de prévisualisation** (postes trouvés/non trouvés, missions existantes vs référentiel, affectations conservées) puis un bouton **« Confirmer l'initialisation »**. Le mapping crée les affectations sur l'`id` réel du compte, et **ne réécrase jamais** une personnalisation manuelle (test : `skipped_existing_primary`).

### Libellés 100 % français (source unique)
- `pilotage_labels()` / `pilotage_L()` (PHP) + miroir `PL_L` / `L()` (JS) : fréquence, automatisation, rôle, statut, priorité. Repli lisible si code inconnu. Plus aucune valeur technique (`on_demand`…) à l'écran.

### Modal opérationnel (Tranche 3)
- `api/pilotage_task_save.php` (save_fields + validate/reopen, downgrade vert→jaune versionné), `api/pilotage_task_items.php` (checklist/étapes CRUD + reorder + duplication), `api/pilotage_assignment.php` (rôles multiples, exécutant principal sans perte des autres rôles).
- Onglets : **Vue rapide** (objectif, grille synthèse compacte 4 colonnes, organisation, résultat attendu / points de vigilance, « Travailler dans MBI », bouton « Modifier la synthèse »), Procédure (éditable), Checklist (éditable), Juridique, Comptabilité, Documents & GED, **Historique** (versions + journal).
- En-tête : badges en toutes lettres (statut, fréquence, niveau, automatisation) ; validation tricolore réservée aux habilités (`pilotage_can_validate`).
- Champ `expected_result` ajouté (migration `20260717e`).

### Écart 96/97 élucidé
96 missions **Location** + 1 mission **RH** transversale (salaire) = 97 au total. La page Location affiche 96, ce qui est correct.

---

## Tranche 4 — Mission de référence « Proposer un mandat » + moteur d'automatisations

### Modèle d'automatisations (déclaratif, jamais de PHP en base)
- Migration `20260717f` : `pilotage_task_automations` (trigger + condition JSON + action + exec_mode), `pilotage_automation_runs` (journal), colonnes `step_key`/`group_label`/`entry_conditions`. Migration `20260718a` : `mbi_journey_json`.
- Moteur `inc/pilotage_automations.php` : `pilotage_automation_fire()` n'exécute que des actions **non engageantes** (créer action/relance/validation/notif, lancer la mission suivante) ; `exec_mode='validation_required'` ⇒ crée une **demande en attente** (jamais d'action juridiquement engageante auto). Conditions évaluées par un mini-évaluateur sûr (field/op/value, all/any). Anti-doublon par `dedup_key`.
- 4 niveaux d'exécution : Informatif / Assisté / Automatique / Soumis à validation.

### Mission de référence « Proposer un mandat » (modèle des autres)
- 12 étapes (clé stable + responsable + résultat + note d'automatisation), 49 points de checklist en 5 groupes, 8 règles juridiques (jaune), notes compta, conditions d'entrée, objectif/résultat/vigilance, 8 automatisations.
- **Missions interconnectées** créées comme cibles : « Préparer le mandat de gestion », « Faire signer le mandat », « Enregistrer le mandat au registre », « Entrer le bien en gestion ». (Nommage : « Proposer un mandat » conservé + missions aval, cf. choix Emmanuel.)
- **Manuel opératoire MBI** (`mbi_journey_json`) : 9 étapes Tiers→Immeuble→Bien→Mandat→Documents→Proposition→Signature→Registre→Activation, avec routes **réelles auditées** (`tiers_nouveau.php`, `agency_immeuble_form.php`, `bien_creation.php`, `agency_mandats.php`, `document_request_new.php`, `admin/admin_registre_mandats.php`) + aide contextuelle. Rendu dans la section « Travailler dans MBI » de la Vue rapide.

### UI (modal 8 onglets)
Vue rapide · Procédure · Checklist · Juridique · Comptabilité · Documents & GED · **Automatisations** (QUAND/SI/ALORS, activer/désactiver, délai, « Lancer (test) », dernière exécution) · Historique.
- **Contrôle préalable à la validation** (readiness) affiché avant le bouton Valider : Procédure / Checklist / Juridique contrôlé / Affectations / Liens MBI / Automatisations testées.
- Carte : compteurs étapes / automatisations / dossiers en cours.

### Testé (DB locale)
Seed mission de référence complet ; readiness correct ; `status_changed 'acceptee'` → lance « Préparer le mandat » (instance créée, **dédoublonnée** au rejeu) ; garde-fou `validation_required` → demande en attente sans exécution.

### Reste explicitement en attente (Tranche 5)
Déclenchement runtime sur `document_received` / `deadline_reached` (hooks GED + cron), synchro live des statuts du parcours MBI, page **« Ma journée »** consommant `pilotage_task_instances`, connecteur de signature pour le mandat de gestion (le mécanisme interne token existe pour vente/bail).
