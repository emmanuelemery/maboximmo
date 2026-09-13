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
| **ACTION** | ⚠️ **Deux moteurs, aucun exploitable en l'état.** `taches` : **63 lignes**, rattachable seulement à `id_immeuble` / `id_projet` — **ni tiers, ni bien, ni bail, ni dossier** — sans assigné en propre (via `taches_utilisateurs`, 96 lignes) et **sans durée**. `pilotage_tasks` : **101 lignes**, mais c'est un **catalogue de procédures** (objectif, conditions d'entrée, fréquence, texte de procédure, notes légales), pas des actions ; les instances réelles = **7**, et `pilotage_task_assignments` = **0 ligne**. C'est pourtant `pilotage_*` que lit `ma_journee.php`. |
| **NOTE** | ❌ **N'existe pas comme objet.** Seulement `taches_commentaires` et `investisseur_commentaires`, accrochés à leur porteur. |
| **RELATION** | ⚠️ `tiers_roles` : **1 066 lignes / 962 tiers**. Mais **948 (89 %) ont `objet_type = NULL`** — `mandant` 645, `proprietaire` 234, `creancier` 63. **Elles ne mènent à aucun 360.** Seules 118 désignent un objet. |
| **CHRONOLOGIE transverse** | ❌ Aucune table d'événements commune. `taches_evenements` = **0 ligne**. |

**Ce que ça veut dire concrètement.** Le §19 de la doctrine — *« chaque relation mène
directement au 360 correspondant »* — ne tiendrait aujourd'hui que pour **11 % des
relations**. Le §11 — *temps de balle* — porterait sur zéro donnée. Et le §9 — *Ma journée
ne lit que les actions* — désignerait un moteur qui compte **7 instances et 0 assignation**.
⚠️ La Charte §6 dit déjà que Ma journée **n'est pas un troisième moteur de tâches** : il y
en a déjà **deux**. Créer un objet ACTION de plus en ferait un troisième — la question n'est
donc pas quoi créer, mais **lequel des deux devient le moteur, et ce qu'on fait de l'autre**.

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
