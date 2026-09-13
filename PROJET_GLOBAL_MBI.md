# PROJET GLOBAL MBI — feuille de route vivante

*Dernière mise à jour : 13/09/2026*

---

## Ce que ce document est, et ce qu'il n'est pas

| Document | Question à laquelle il répond |
|---|---|
| [`CONSTITUTION_MABOXIMMO.md`](CONSTITUTION_MABOXIMMO.md) | **Pourquoi** MBI existe |
| [`CHARTE_CONCEPTION_MBI_V2.md`](CHARTE_CONCEPTION_MBI_V2.md) | **Comment** on conçoit |
| **Ce document** | **Quoi** — ce qui est en chantier, inscrit, en dette, livré |
| [`PLAN_ACTION_MBI.md`](PLAN_ACTION_MBI.md) | Plan de remédiation **technique** du 20/04/2026 (sécurité, refactoring). ⚠️ Figé depuis mai — ne pas le confondre avec cette feuille de route. |

### La règle d'entrée

> **Une idée entre ici avec l'état RÉEL de ce qui existe déjà, mesuré — jamais comme un
> vœu.**

C'est l'application directe de la Charte §11 *Audit avant code* et §11.1 *Prouver plutôt
qu'affirmer*. Dans ce dépôt, la plupart des « fonctionnalités à construire » se sont
révélées **construites à 80 % et jamais branchées**. Écrire « à faire » sans avoir cherché,
c'est se condamner à réécrire ce qui dort.

Chaque entrée porte donc quatre rubriques : **le besoin · ce qui existe (mesuré) · ce qui
manque · le premier pas**.

---

## 🔨 Chantiers en cours

### C1 — Parcours de signature du mandat de vente

**Le besoin.** Un mail porte l'information précontractuelle dans son corps ; le vendeur
valide ; **la même page** lui présente ensuite le projet de mandat ; il reconnaît l'avoir
lu et accepte ; **la première acceptation grave le numéro de registre, qui s'inscrit sur le
PDF et fige les termes** ; code SMS ; nom et signature (ordinateur ou téléphone) ; les
vendeurs suivants lisent la pièce numérotée **et déjà signée** ; quand tous ont signé,
c'est à nous. **Signature à distance ou en présentiel, toujours les deux.**

**Ce qui existe (vérifié le 11/09/2026).**

| Brique | |
|---|---|
| Page publique par jeton, 2 colonnes, lecture intégrale obligatoire, journal horodaté + IP | ✅ `p/mandat_signature.php` |
| Un jeton par vendeur, tous créés d'un coup | ✅ `msig_create_for_vendeurs()` |
| Signature **présentielle** : pad Pointer Events + photo-preuve | ✅ `inc/mandat_sign_presentiel.php` |
| OTP SMS | ✅ existe (migration `20260815c_sms_otp` + `inc/bail_ceremonie.php` + module OVH) — à transplanter |
| Gravure du numéro au registre | ✅ `inc/mandat_officialisation.php` |
| **Verrou des termes dès la gravure** | ✅ posé et testé le 11/09 |
| Lieu de conclusion demandé et refusé s'il manque | ✅ livré le 11/09 |

**Ce qui manque.** Le mail (info précontractuelle + lien de dépôt si pièces manquantes) ·
la page à **deux états** sur une seule URL · la renonciation au délai cochée par le vendeur
(la colonne `execution_anticipee` est prête) · **la signature apposée dans le PDF** · l'ordre
« vendeurs d'abord, nous en dernier » · **le rendu partagé de la liste des signataires**
(card, mail, page, PDF — écrit une fois, cf. Charte §1.2 bis).

**Premier pas.** Le rendu partagé des signataires, puis la page à deux états qui l'utilise.

⚠️ **Obstacle mesuré : seuls 7 vendeurs sur 48 ont un mobile exploitable.** La colonne
`tiers.mobile` est **vide partout** ; les portables dorment dans `telephone`. Règle à
appliquer : chercher dans `mobile` → `telephone` → `telephone_secondaire` et retenir le
premier qui ressemble à un mobile français. Les SCI signent par leur **représentant**, dont
le mobile est sur une autre fiche. **La cérémonie doit vérifier les mobiles AVANT d'envoyer
quoi que ce soit** — découvrir le problème devant le client est le pire moment.

---

## 💡 Idées inscrites

### I0 — Les objets transverses des Accueil & 360 : ATTENTE, ACTION, PASSAGE DE BALLE, NOTE, RELATION

**Origine.** Doctrine Accueil & 360 figée le 13/09/2026
([`DOCTRINE_ACCUEIL_360_MBI.md`](DOCTRINE_ACCUEIL_360_MBI.md)). Sa conclusion §23 :
*« avant même de dessiner définitivement le 360 Tiers, ces objets doivent être parfaitement
définis, sinon l'interface sera belle et on découvrira en la développant que le modèle ne
sait pas porter ce qu'elle raconte. »*

**Ce n'est pas une précaution de principe.** État mesuré le **13/09/2026 sur la base locale
`maboximmo`** (le local fait autorité sur le schéma) :

| Objet de la doctrine | Ce qui existe réellement |
|---|---|
| **ATTENTE** | ❌ **N'existe pas.** Aucune table. Aucune colonne « porteur ». |
| **PASSAGE DE BALLE** | ❌ **N'existe pas.** Rien ne date un changement de porteur — donc *temps de balle* et *temps chez nous* ne sont aujourd'hui calculables sur rien. |
| **ACTION** | ⚠️ **Deux moteurs.** `taches` : **63 lignes**, rattachable seulement à `id_immeuble` / `id_projet` — **ni tiers, ni bien, ni bail, ni dossier** — sans assigné en propre (via `taches_utilisateurs`, 96 lignes) et **sans durée**. `pilotage_tasks` : **101 lignes**, mais c'est un **catalogue de procédures** (objectif, conditions d'entrée, fréquence, texte de procédure, notes légales), pas des actions ; instances réelles = **7**, `pilotage_task_assignments` = **0 ligne**. Autopsie complète au §I0 bis. |
| **NOTE** | ❌ **N'existe pas comme objet.** Seulement `taches_commentaires` et `investisseur_commentaires`, accrochés à leur porteur. |
| **RELATION** | ⚠️ `tiers_roles` : **1 066 lignes / 962 tiers**. Mais **948 (89 %) ont `objet_type = NULL`** — `mandant` 645, `proprietaire` 234, `creancier` 63. **Elles ne mènent à aucun 360.** Seules 118 désignent un objet. |
| **CHRONOLOGIE transverse** | ❌ Aucune table d'événements commune. `taches_evenements` = **0 ligne**. |

**Ce que ça veut dire concrètement.** Le §19 de la doctrine — *« chaque relation mène
directement au 360 correspondant »* — ne tiendrait aujourd'hui que pour **11 % des
relations**. Le §11 — *temps de balle* — porterait sur zéro donnée.
⚠️ La Charte §6 dit déjà que Ma journée **n'est pas un troisième moteur de tâches** : il y
en a déjà **deux**. Créer un objet ACTION de plus en ferait un troisième — la question n'est
donc pas quoi créer, mais **lequel des deux devient le moteur, et ce qu'on fait de l'autre**.

> 🔴 **CORRECTION du 13/09/2026.** La première version de ce constat affirmait que
> `ma_journee.php` lisait `pilotage_*`. **C'est faux.** `ma_journee.php` (151 lignes) ne lit
> **aucune** des deux familles : c'est un LAB de traitement de demande, explicitement en
> lecture seule (« AUCUNE ÉCRITURE MÉTIER (V0) »), et `api/ma_journee_analyse.php` ne
> contient aucun `FROM`. L'erreur venait d'un `grep` qui groupait `ma_journee.php` avec
> `inc/pilotage*.php` : les correspondances appartenaient au second.
> **Conséquence, favorable :** faire lire les ACTIONS à Ma journée n'est pas une bascule
> risquée, c'est une construction sur du vide. Il n'y a rien à casser.

**Ordre de traitement proposé** — du plus structurant au plus dérivé, chacun mesurable :

1. **RELATION** : donner un objet aux 948 rôles orphelins, ou les déclarer globaux
   explicitement. Sans ça, aucun 360 référentiel ne peut relier quoi que ce soit.
   *(Voir `reference_v2_role_se_pose_sur_un_objet` : le même défaut a été constaté côté V2.)*
2. **ACTION** : trancher entre `taches` et `pilotage_*`, puis rendre l'objet rattachable à
   n'importe quel objet métier (tiers, bien, bail, mandat, dossier) avec assigné, échéance,
   priorité, durée, état.
3. **ATTENTE + PASSAGE DE BALLE** : le porteur réel et son historique daté. C'est de là que
   sortent les cards *CHEZ NOUS / CHEZ EUX*, les relances et le temps de balle.
4. **NOTE** : le plus simple, et le seul à ne rien déclencher — à faire en dernier, mais à
   ne pas oublier, sinon les notes finiront forcées dans les attentes (interdit, §9.0).

**Non chiffré à ce stade** : le volume de travail. Rien n'a été estimé, aucune migration
écrite, aucun écran dessiné. Ce qui précède est un **constat**, pas un plan.

---

### I0 bis — Autopsie `taches` vs `pilotage_tasks` *(13/09/2026, base locale)*

**Le nœud à défaire avant tout le reste.** Mesuré table par table, ligne par ligne, et
croisé avec les écrivains réels dans le code.

#### Ce que chaque famille est devenue

| | `taches` *(12 tables)* | `pilotage_*` *(17 tables)* |
|---|---|---|
| **Nature** | Objet **opérationnel** | **Référentiel de procédures** |
| **Origine des données** | **Créées dans MBI par de vrais utilisateurs** — `migrated_at` NULL sur 63/63 | **Seed** — les 101 lignes portent toutes `created_at = 2026-07-18 00:08:10`, à la seconde près |
| **Période de vie** | 12/10/2025 → 17/03/2026, dernière trace au journal le **25/03/2026** | 18/07/2026, **une seule matinée** |
| **Volume vivant** | 63 tâches · 96 assignations · 97 journal · 42 documents | 101 procédures · 112 postes · 57 items de checklist · 46 déclencheurs |
| **Volume opérationnel** | — | **7 instances · 0 assignation · 0 historique · 0 version** |
| **Interface** | `agency_taches.php` (373 l.) + `agency_tache_detail.php` (790 l.), atteignables depuis 4 tableaux de bord | 6 endpoints API + 4 helpers, **aucune page de liste** |
| **Écrivains** | **1 fichier** | **11 fichiers** |

> 🔑 **Chaque famille est peuplée exactement du côté où elle est censée régner, et vide de
> l'autre.** Les 5 tables « modèle » de `taches` — `taches_modeles`, `taches_modeles_items`,
> `taches_modele_role_map`, `taches_regles`, `taches_projets` — sont **toutes à 0 ligne**,
> parce que `pilotage_tasks` occupe déjà ce terrain. Et les 4 tables opérationnelles de
> `pilotage` — `instances`, `assignments`, `history`, `versions` — sont **vides ou seedées**,
> parce que `taches` occupe déjà celui-là. **Le partage s'est fait tout seul ; il reste à
> l'assumer.**

#### Les deux anomalies symétriques

- **`pilotage` : le code existe, l'usage jamais.** `pilotage_task_assignments` = **0 ligne
  pour 3 écrivains**, `pilotage_task_history` = **0 ligne pour 2 écrivains**. On a écrit de
  quoi assigner et historiser, personne ne s'en est jamais servi. À l'inverse
  `pilotage_task_instances` = 7 lignes pour **0 écrivain hors seed** : ces 7 instances sont
  des artefacts du 18/07, pas de la production.
- **`taches` : les données existent, le code a disparu.** `taches_logs` (41 lignes),
  `taches_chat_messages` (22), `taches_emails` (13) ne sont **référencées nulle part dans le
  code** — pas un `FROM`, pas une mention. Les fonctionnalités ont été retirées, les données
  sont restées orphelines.

#### Réponses aux six questions posées

1. **`taches` devient-il l'objet opérationnel canonique ?** → **Oui.** C'est le seul qui ait
   vécu : cinq mois d'usage réel, une interface atteignable, un journal. **Ce qui lui manque
   est précis** : il ne sait se rattacher qu'à `id_immeuble` / `id_projet` — ni tiers, ni
   bien, ni bail, ni mandat, ni dossier — et il n'a **pas de durée**.
2. **`pilotage_tasks` devient-il officiellement le référentiel de procédures ?** → **Oui, et
   il l'est déjà** : `objective`, `expected_result`, `entry_conditions`, `required_level`,
   `frequency_type`, `trigger_event`, `estimated_duration_minutes`, `procedure_text`,
   `legal_notes`, `automation_level`, `validated_by`. Ce vocabulaire décrit un **modèle**,
   jamais une action à faire aujourd'hui.
3. **Répartition des satellites** → le tableau ci-dessus la donne : elle est déjà effective
   dans les données. Rien à arbitrer, seulement à entériner.
4. **Qui lit et écrit** → `taches` : 2 fichiers. `pilotage` : 12. Le déséquilibre du code est
   l'inverse exact de celui des données.
5. **Bascule de `ma_journee.php`** → **il n'y a pas de bascule.** Il ne lit aucune des deux
   familles (voir la correction ci-dessus). C'est une construction, sans risque de
   régression.
6. **Données à migrer / conserver / abandonner** → à trancher : les **7 instances** et les
   **7 `automation_runs`** du 18/07 sont des artefacts de test ; les **5 tables modèle vides**
   de `taches` n'ont ni donnée ni écrivain ; les **76 lignes orphelines** (`taches_logs`,
   `chat_messages`, `emails`) sont un historique sans code — à conserver ou à archiver, pas
   à faire revivre à l'aveugle.

**Aucune migration livrée.** Cette autopsie établit le constat.

#### 🔒 Verdict figé le 13/09/2026 — base de travail

| | |
|---|---|
| **`taches`** | **ACTION opérationnelle canonique** |
| **`pilotage_tasks`** | **Référentiel de procédures / modèles d'actions** |
| **Ma journée** | Futur consommateur des **actions**, jamais des procédures |
| **Troisième moteur** | ❌ **Aucune création.** La Charte §6 l'interdit, et il y en a déjà deux. |
| **ATTENTE / PASSAGE DE BALLE** | ❌ **Aucune migration** tant que l'extension de `taches` n'est pas définie. |

#### ⏸️ Les trois reliquats — à TRAITER, pas à supprimer au passage

⚠️ **Aucun nettoyage opportuniste.** Chacun fait l'objet d'une **décision séparée**, précédée
d'une **vérification de valeur historique**. Une donnée sans code n'est pas une donnée sans
valeur : c'est souvent la seule trace de ce qui s'est passé. *(Charte §9 — les corrections
historiques préservent la traçabilité plutôt que de réécrire silencieusement les faits.)*

| Reliquat | Volume | Ce qu'il faut établir avant de décider |
|---|---|---|
| `pilotage_task_instances` + `pilotage_automation_runs` | **7 + 7** | Sont-elles bien les artefacts du seed du 18/07, ou l'une d'elles a-t-elle été créée à la main depuis ? |
| Tables modèle vides de `taches` — `taches_modeles`, `taches_modeles_items`, `taches_modele_role_map`, `taches_regles`, `taches_projets` | **0 ligne, 0 écrivain** | ✅ Vérifié le 13/09 : **0 tâche sur 63 ne pointe vers un projet** (`id_projet` vide partout), donc `taches_projets` n'est référencée par aucune donnée. Le contrôle est fait ; **la décision reste à prendre**. |
| Lignes orphelines — `taches_logs`, `taches_chat_messages`, `taches_emails` | **41 + 22 + 13 = 76** | Que racontent-elles réellement ? Un échange client conservé nulle part ailleurs n'est pas un déchet. Archiver plutôt que supprimer, en cas de doute. |

---

### I0 ter — 🔨 PROCHAIN CHANTIER : `taches` → ACTION transverse MBI

**Objet.** Faire évoluer `taches` de « tâche immeuble/projet » vers **l'ACTION transverse
MBI** au sens de la Doctrine Accueil & 360 §9.

**Ce qui est à DÉFINIR — et seulement à définir. Aucune migration codée à ce stade.**

1. **Rattachement générique aux objets 360.** Aujourd'hui `id_immeuble` + `id_projet`
   uniquement — et **mesuré le 13/09 : 42 tâches sur 63 portent un immeuble, 0 portent un
   projet, et 21 (33 %) ne sont rattachées à RIEN.** Un tiers des actions existantes n'a
   déjà nulle part où vivre : le rattachement générique n'est pas un confort, c'est ce qui
   manque. Cible : accrocher une action à un **tiers, bien, bail, mandat, immeuble,
   dossier**.
   ⚠️ **Ne pas inventer une troisième convention.** Le couple `objet_type` / `id_objet`
   (ou `entite_type`) est déjà utilisé par **15 tables de cette base** — `tiers_roles`,
   `communications`, `ged_analyses`, `fluxbox_ancres`, `bail_signatures`,
   `mandat_signatures`, `sms_envois`, `crgi_journal`… S'y aligner, et se méfier des **deux
   orthographes** déjà constatées (`BIEN` vs `bien`, qui ont produit une jointure vide sur
   3 738 lignes).
2. **Durée.** Absente de `taches`. Présente comme `estimated_duration_minutes` sur
   `pilotage_tasks` : une action **instanciée depuis une procédure** peut en hériter.
3. **Assignation.** Aujourd'hui via `taches_utilisateurs` (96 lignes, avec un `role` et un
   `cc_par_defaut`). Décider si l'assigné principal remonte en colonne sur `taches`, ou si
   la table de liaison reste la vérité.
4. **Statut et échéances.** `statut` est un texte libre, 5 valeurs constatées le 13/09 :
   `terminée` 27 · `à faire` 20 · `archivée` 13 · `en cours` 2 · **chaîne vide** 1
   *(une chaîne vide, pas un NULL — le piège classique : `IS NOT NULL` la laisse passer)*.
   À cadrer. `date_echeance` et `date_cloture` existent déjà.
5. **Lien futur avec une ATTENTE.** Définir **comment une attente engendre une action** et
   **comment la clôture de l'action referme ou fait progresser l'attente** — c'est ce lien,
   et lui seul, qui débloquera le chantier ATTENTE / PASSAGE DE BALLE.
6. **Raccordement de Ma journée.** À concevoir proprement : elle ne dépend aujourd'hui
   d'**aucun** des deux moteurs, donc **aucune logique de migration fonctionnelle n'est à
   préserver**. C'est une construction, pas une reprise.

**Hors périmètre de ce chantier** : toute migration BDD, tout écran, et les trois reliquats
ci-dessus.

### I1 — Envoi de gros fichiers : la page client qui remplace WeTransfer

**Le besoin.** Envoyer des dizaines de Mo à un tiers — **typiquement les pièces au notaire
pour préparer un compromis** — via une page client soignée, avec un texte commercial qui
présente le service, un lien à **durée définie**, et un parcours en trois gestes : *action →
destinataire → documents → mail → envoyé*.

**Ce qui existe (vérifié le 11/09/2026) — bien plus qu'attendu.**

La table **`dossier_vente_partage`** (migration du 27/07) porte déjà :

```
role_destinataire  enum('acquereur','notaire','commercialisateur')
docs_json · inclure_photos · token(48) · expires_at · revoked_at · nb_vues · last_view_at
```

Le **notaire est littéralement dans le schéma**. Et avec :
- `dossier_vente_partage.php` — la page publique (415 lignes) ;
- `api/dossier_vente_partage_action.php` — création / révocation ;
- `api/dossier_vente_doc.php` — servage des documents **par jeton** ;
- l'écran dans l'onglet **Documents** : une case par document et par destinataire, un
  sélecteur **30 j / 90 j / 1 an / sans expiration**, un compteur de vues, une révocation.

**⚠️ Et la table contient ZÉRO ligne.** Jamais utilisée.

**Ce qui manque — et explique probablement le zéro.**
1. **Aucun mail n'est envoyé.** Il faut copier le lien à la main. C'est exactement le geste
   que la Charte §2.1 demande de supprimer.
2. Seuls les **documents déjà dans le dossier** sont partageables — pas de dépôt à la volée.
3. C'est **enfermé dans le dossier de vente** ; le besoin est une action générale.
4. C'est **enterré** dans l'onglet Documents (Charte §11.1 b : l'ordre compte, pas la présence).

**Obstacle technique réel, à lever en premier.** « Plusieurs dizaines de Mo » se heurte à la
configuration PHP, pas au code. En local : `upload_max_filesize = 60M`,
`post_max_size = 64M`. **Sur le mutualisé Hostinger, à vérifier et pas toujours
modifiable.** Prévoir aussi une barre de progression : 40 Mo depuis une agence, ce n'est pas
instantané, et un formulaire qui semble figé est un formulaire qu'on abandonne.

**Premier pas.** Brancher le **mail** sur ce qui existe : destinataire choisi parmi les
tiers du dossier (le notaire y est déjà), documents cochés, durée, envoi. Petit chantier sur
une fondation posée, qui couvre le cas d'usage principal.
**Ensuite seulement** : dépôt à la volée hors dossier, page client habillée, action générale.

---

### I2 — La doctrine suit le code, dans tous les dépôts

**Le besoin.** Le 11/09/2026, j'ai travaillé une journée entière sur ce dépôt **sans
connaître ni le Manifeste ni la Charte** — ils étaient pourtant à la racine. Une doctrine
qu'il faut penser à citer est une doctrine qu'on oublie.

**Ce qui existe.** Depuis le 11/09, ce dépôt porte un **`CLAUDE.md`** chargé automatiquement
à chaque session : la phrase constitutionnelle, le renvoi vers les deux documents,
les non-négociables en clair, « prouver plutôt qu'affirmer », et les règles de travail du
dépôt (snapshots `_exN`, déploiement, migrations, tests structurels).

**Ce qui manque.** Le code MBI vit dans **plusieurs dépôts** : `ged-maboximmo`, et un dépôt
par service V2 sous `/opt/mbx/<service>/` sur le VPS. Aucun ne porte sa doctrine.

**Premier pas.** Un `CLAUDE.md` court dans chaque dépôt, qui renvoie à la Charte et ne
répète que ce qui est propre à ce dépôt. La doctrine voyage avec le code au lieu de vivre
dans un seul endroit que personne ne rouvre.

---

## 🧾 Dettes et rattrapages

*Choses connues, chiffrées, non traitées. Une dette nommée n'est pas une dette oubliée —
mais une dette qui reste ici trop longtemps devient une doctrine par défaut (Charte §11).*

| # | Dette | Mesure | Remède |
|---|---|---|---|
| **D1** | `ged_documents.final_destination` **ment** sur 223 lignes : le résolveur rattrape, mais `ged_doc_has_own_copy()` (`inc/ged_durable.php`) s'y trompe encore | 223 lignes, toutes CRG | Réaligner les chemins sur l'empreinte, avec export des anciennes valeurs. Prêt, jamais lancé. |
| **D2** | Dossiers de vente sans mentions légales imprimables | **13** biens sans société **ni** agence · **2** sur LOCA IMMO Holding, qui ne porte aucune mention | Rattacher les biens ; compléter ou déplacer les 2 dossiers. **Saisie, pas code.** |
| **D3** | Documents GED sans fichier | **120** lignes sur 905, de mai–juillet, `final_destination` vide depuis l'origine | Décider : purge, archivage, ou retrouver les fichiers. |
| **D4** | `SSO_RETURN_ALLOWLIST` contient encore les deux URL `connex`, supprimé le 10/09 | sans effet nuisible | Nettoyer — demande de relancer `mbx-sso-v2`, dont le code vit sur le VPS **sans git**. |
| **D5** | Mobiles des vendeurs | **7 sur 48** exploitables | Règle de lecture à trois colonnes (cf. C1) + saisie. |

---

## ✅ Livré récemment

*On garde la trace de ce qui est passé en production : c'est ce qui permet de dire « c'est
corrigé » **en disant où** (Charte §11.1 d).*

**11/09/2026** — dossier `C:\tmp\deploy_mandat_ged_2026-09-11`
- Lieu de conclusion demandé et **refusé s'il manque** ; la garde de diffusion cesse d'être
  inerte (elle protégeait **0 mandat sur 728**).
- Verrou des termes **dès la gravure du numéro**, plus seulement à la signature.
- Card Mandat réordonnée : les commandes passent **avant** le bloc registre.
- Modals `.dvm` bornés en hauteur — leurs boutons étaient devenus inatteignables.
- Résolution GED par empreinte : **562 → 785 documents servables sur 905**.
- Mentions légales du mandat : RGPD déduit → **27 → 36 dossiers complets sur 51**.
- Nom GED court (5 dernières parties) affiché dès le dépôt du mandat signé.

**11/09/2026 (2ᵉ lot)** — même dossier `deploy_mandat_ged_2026-09-11`
- **Ubiflow — on sait enfin quand une annonce est partie.** `date_derniere_sync` existait
  et valait NULL partout. Les **quatre** chemins de dépôt (cron 19h, envoi manuel,
  diffusion d'une annonce, redéploiement d'agence) appellent désormais un écrivain unique.
  ⚠️ On n'horodate que si le dépôt a **réellement transmis** : un XML identique n'est pas
  redéposé, et la colonne dit alors « rien n'est parti depuis N jours ».
- **Console SQL en lecture seule** (`admin/admin_sql_console.php`) — interroger la prod sans
  phpMyAdmin. Quatre verrous, dont une transaction `READ ONLY` : c'est MySQL qui refuse
  l'écriture, pas seulement le filtre.
- Nom GED court (5 dernières parties) au dépôt du mandat signé · verrou des termes dès la
  gravure du numéro de registre.

**11/09/2026 — diagnostic, sans correctif** : l'annonce `L-MC-CHAM-993` n'était plus remontée
sur leboncoin depuis 16 jours. **Rien n'est cassé côté MBI** — elle est dans le XML, déposé
le jour même. Elle n'a simplement **pas changé depuis le 10/08**, et un fichier identique
n'est pas redéposé. La mécanique de remontée est chez Ubiflow/leboncoin.

**10/09/2026** — `connex.maboximmo.com` supprimé. S'y connecter réussissait puis bouclait au
login, sans erreur : le cookie est host-only et ne suivait pas sur `mbi.maboximmo.com`.

---

## Comment ce document vit

- Une idée entre avec **ce qui existe déjà, mesuré**. Pas de « à construire » sans audit.
- Un chantier livré descend dans **Livré récemment**, avec la date et le dossier de
  déploiement — pas seulement « fait ».
- Une dette qui traîne se **rediscute**, elle ne se recopie pas d'une version à l'autre.
- Ce document **ne remplace pas** la Charte : il dit quoi faire, elle dit comment le faire.
