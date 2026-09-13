# CHARTE OFFICIELLE DE CONCEPTION MBI V2

**Doctrine produit, architecture, UX, IA et développement**
*Version 1.2 — 13/09/2026 — document normatif, gravé dans le dépôt*
<!-- L'en-tête disait encore 1.0 alors que le journal §17 portait déjà une révision 1.1 :
     deux vérités pour le même fait. Aligné le 13/09/2026. -->

> 📐 **Écrans d'accueil et pages 360** : lire aussi
> [`DOCTRINE_ACCUEIL_360_MBI.md`](DOCTRINE_ACCUEIL_360_MBI.md), normative, qui précise et
> étend §2, §3, §4, §6 et §9.

---

> **MBI doit comprendre la situation avant de demander à l'utilisateur de naviguer.
> Il supprime les clics nécessaires pour trouver le travail et conserve les gestes
> nécessaires pour décider, valider et préserver son travail.**

---

## Où cette charte se situe

| Document | Rôle | Mutabilité |
|---|---|---|
| [`CONSTITUTION_MABOXIMMO.md`](CONSTITUTION_MABOXIMMO.md) — *Le Manifeste* | **Pourquoi** MaBoxImmo existe. Les 10 lois fondamentales, les 4 moteurs, le test ultime en 5 questions. | Corps **immuable** (figé le 27/06/2026). Seule l'annexe technique suit le code. |
| **Ce document** — *La Charte de conception V2* | **Comment** on conçoit chaque écran, chaque table, chaque automatisation. | Évolutive, mais **jamais par exception silencieuse** (cf. §16). |

Les deux se complètent et ne se contredisent pas : le Manifeste donne la promesse
(« chaque clic demandé est une dette, le logiciel doit la rembourser immédiatement »),
la Charte donne la méthode pour la tenir.

⚠️ **Périmètre.** Cette charte gouverne la conception de **MBI V2**. Les travaux en cours
sur l'existant (Hostinger V1) restent régis par leurs propres contraintes ; lorsqu'un
écran V1 est repris en profondeur, on applique la charte et **on signale les écarts**
plutôt que de les laisser s'installer.

---

## 0. Statut de la charte

Cette charte est **normative**. Une nouvelle fonctionnalité doit être compatible avec
elle avant d'être considérée comme terminée.

- Une décision locale ne doit pas créer une **exception silencieuse** à la doctrine
  générale. Si une règle doit évoluer, la modification doit être explicite, argumentée
  et validée.
- Les **cas métier réels priment sur les abstractions**. On ne crée pas une structure de
  données, un écran ou un workflow uniquement parce qu'il pourrait être utile un jour.
- **La simplicité visible est une exigence** : la complexité appartient au modèle et au
  moteur, pas à l'utilisateur.

---

## 1. Charte Architecture & BDD

### 1.1 Une seule vérité pour un même fait

- Un même fait métier ne doit pas être stocké à plusieurs endroits sans raison démontrée.
- Éviter les doublons de tables, de champs, de vocabulaires et d'états. **Un affichage
  peut être dérivé ; il ne doit pas devenir une seconde vérité.**
- Une **identité** est séparée de ses **rôles**. Un rôle décrit une fonction ; le côté, la
  spécialité ou l'état sont des attributs lorsqu'ils ne constituent pas réellement un rôle.
- Ne pas créer de **pseudo-types pour représenter un état** : `projet` / `signé` relève
  d'un état ou d'une version, pas nécessairement d'un nouveau type documentaire.

### 1.2 Modéliser au moment du besoin réel

- **Pas de table spéculative.** Une table est créée lorsqu'un besoin réel ne peut plus
  être représenté proprement par le modèle existant.
- Le **modèle minimal correct** est préférable au modèle exhaustif anticipé.
- Les **dettes de modèle connues sont documentées explicitement** plutôt que masquées par
  une structure prématurée.
- Les **référentiels fermés** sont utilisés lorsque le vocabulaire doit rester contrôlé
  et testable.

### 1.2 bis — Une seule vérité vaut aussi pour le RENDU

La règle « un fait, un endroit » ne s'arrête pas à la base : **un même fait ne doit pas
être *affiché* par deux morceaux de code différents.**

> **Incident fondateur.** Le bloc « Date et signatures » était écrit **deux fois** — une
> version dans le générateur du bail commercial, une autre dans celui du bail habitation.
> Aucune des deux ne savait afficher plus d'**une** signature par qualité. Le deuxième
> colocataire signait donc réellement — en base, horodaté, opposable — et **n'apparaissait
> nulle part dans l'acte**. À la lecture du seul PDF, on aurait conclu qu'il n'avait
> jamais signé. Corrigé par `inc/bail_signature_bloc.php` : un seul bloc, appelé par les
> deux générateurs, jamais recopié.

- Un affichage partagé par plusieurs surfaces (écran, mail, PDF, page publique) s'écrit
  **une fois** et s'appelle *n* fois.
- Recopier un rendu, c'est garantir qu'on en corrigera **un sur deux** — et le plus grave
  est que la copie oubliée **n'affiche pas une erreur, elle affiche une contre-vérité**.
- Corollaire : avant d'écrire un composant d'affichage, chercher s'il existe déjà. La
  deuxième copie est toujours moins chère à écrire qu'à réparer.

### 1.3 Développement vertical

Ordre de construction privilégié :

1. cas métier réels et invariants ;
2. modèle minimal ;
3. tests et contrôles de périmètre ;
4. lecture réelle ;
5. écriture autorisée ;
6. interface ;
7. usage navigateur réel ;
8. puis enrichissement.

> MBI ne construit pas cinquante écrans avant de vérifier le modèle.
> **Une verticale métier doit pouvoir être prouvée de bout en bout.**

### 1.4 Périmètres, droits et sécurité

- Le périmètre utilisateur est une **propriété structurelle de l'accès aux données**, pas
  un simple filtre d'écran.
- Les lectures **et** les écritures doivent être autorisées avant l'opération.
  **Un identifiant deviné ne doit jamais permettre d'agir hors périmètre.**
- Les enfants d'un objet héritent du périmètre de leur parent lorsque cela correspond au
  modèle.
- Les contrôles de sécurité doivent être testés **dans les deux directions** : accès
  légitime *et* tentative hors périmètre.

---

## 2. Charte UX/UI — la navigation doit s'effacer

### 2.1 Doctrine du clic

À chaque nouveau parcours, poser d'abord deux questions :
**« Peut-on supprimer le premier clic ? »** puis **« Peut-on supprimer le deuxième ? »**

- Un clic qui ne correspond **ni à une décision, ni à une validation, ni à une sécurité,
  ni à une intention réelle de navigation** doit être remis en question.
- Le parcours traditionnel `Module > Fonction > Liste > Filtres > Recherche > Objet >
  Action` **n'est pas la cible MBI**.
- La cible est `Situation > Objet pertinent > Action` ; lorsque possible :
  `« Que s'est-il passé ? » > MBI comprend > propose > l'utilisateur valide`.
- Les filtres et recherches **restent disponibles pour l'exploration**, mais ne doivent
  pas être le chemin obligatoire vers le travail.

### 2.2 Montrer le travail, pas les fonctions

- Les vues de pilotage remontent ce qui nécessite une attention : échéance, décision
  attendue, attente, anomalie, risque, **absence d'action**.
- Un KPI est utile s'il permet de **comprendre ou sélectionner** une situation ; il n'est
  pas décoratif.
- Les listes denses et les tableaux ne doivent pas devenir la réponse automatique à un
  besoin métier.
- La plomberie technique, les codes et les mécanismes internes **restent invisibles**.

### 2.3 Choix métier sans dropdown

- Pour les sélections métier, MBI privilégie les **boutons de sélection, la recherche et
  les choix contextualisés**. Les menus déroulants massifs ne doivent pas être la
  grammaire principale de l'application.
- Une dette UX héritée peut subsister temporairement (ex. certains référentiels
  documentaires), **mais elle ne devient pas une exception doctrinale**.

### 2.4 Mobile-first et sobriété

- Les pages sont conçues pour rester compréhensibles **sur petit écran** avant d'être
  enrichies sur grand écran.
- Pas de sidebar lourde imposée lorsque la navigation peut être plus simple.
- Ombres, animations et couleurs **servent la compréhension** ; elles ne doivent pas
  créer du bruit.
- Les modales et composants d'interaction doivent rester **cohérents d'un module à
  l'autre**.

---

## 3. Charte Full-Web & continuité du travail

MBI V2 est **full-web par principe**. Le navigateur est une **capacité de la plateforme**,
pas un obstacle à contourner.

- Chaque objet important, notamment les pages 360, doit disposer d'une **URL autonome**
  capable de reconstruire son contexte.
- ⚠️ **Cela vaut aussi pour les niveaux contextuels** (panneaux latéraux, détails en
  cascade) *(13/09/2026)*. Un panneau qui n'existe que dans l'état JavaScript de la page ne
  s'ouvre pas dans un second onglet, ne se déplace pas sur un second écran, ne se partage
  pas et ne survit pas à un rechargement — et il rend impossible la conservation du
  contexte au retour, exigée sur mobile.
- MBI exploite **onglets, fenêtres, historique, liens et capacités multi-écrans** du
  navigateur au lieu de recréer un pseudo-desktop.
- Une page doit pouvoir être **ouverte dans un nouvel onglet, détachée dans une fenêtre et
  déplacée sur un second écran** sans mécanisme métier spécifique.
- MBI ne doit pas dépendre d'un client lourd pour offrir une expérience riche.

### 3.1 CHAÎNE et préservation du fil

- **CHAÎNE représente la continuité du travail** et le contexte courant.
- Un `+` dans CHAÎNE doit permettre d'ouvrir un **nouveau contexte MBI dans un nouvel
  onglet sans perdre le contexte courant**.
- Réduire les clics ne signifie pas empêcher le multitâche : MBI **supprime les clics de
  navigation inutiles mais facilite les gestes qui préservent le travail**.
- Ne pas créer un second système de workspaces si le navigateur et CHAÎNE couvrent déjà
  le besoin.

---

## 4. Charte des pages 360

> 📐 Les écrans d'**accueil** et les **360** ont leur doctrine détaillée :
> [`DOCTRINE_ACCUEIL_360_MBI.md`](DOCTRINE_ACCUEIL_360_MBI.md) (13/09/2026), normative
> elle aussi, qui précise et étend §2, §4, §6 et §9. À lire avant de dessiner une 360.

Avant toute nouvelle page 360, **quatre questions sont obligatoires** :
**l'objet est-il un référentiel ou un dossier ?** quelle frise raconte l'objet — **s'il en
faut une** ? quels onglets métier sont réellement nécessaires ? quelles évolutions
nécessitent une validation humaine ?

### 4.0 Référentiel ou dossier — la question posée en premier

Les deux familles ne se racontent pas de la même façon.

- **Référentiel** (*Tiers, Bien, Immeuble*) : existe durablement, sans début, sans étape
  courante, sans clôture. → **Identité → Relations → Chronologie → Contenus → Situation.**
  **Pas de frise métier.**
- **Dossier** (*Créancier, Sinistre, Vente, procédure…*) : possède une ouverture, des
  étapes et une issue. → **Contexte → Relations → Frise → Chronologie → Contenus → Suite
  → Clôture.**

> **Jamais de frise sur un accueil.**
> **360 référentiel = relations + chronologie. 360 dossier = frise métier + chronologie.**

### 4.1 La frise EST la chronologie *(sur un dossier)*

⚠️ Cette règle vise les **dossiers**. Sur un **référentiel**, il n'y a pas de frise : la
chronologie n'y double donc rien, elle est la seule narration possible.

- **Ne pas créer un onglet Chronologie en doublon de la frise.**
- La frise est **pilotée par les données et les faits** ; ce n'est pas un stepper rigide
  décoratif.
- Le **passé** représente les faits établis. Le **présent** situe le dossier. Le **futur**
  représente ce qui est attendu ou envisagé.
- La trajectoire future peut évoluer ; **le passé ne doit pas être réécrit** pour faire
  correspondre le dossier à un processus théorique.

### 4.2 Onglets permanents

Tout 360 possède des **familles permanentes** — nommées ainsi depuis le 13/09/2026 :

**Échanges** · **Documents** · **Relations** · **Stratégie**

- **Échanges** (ex-*Communication*) : mails, SMS, appels, courriers.
- **Relations** (ex-*Participants*) : plus large que les personnes — une relation vise un
  **objet** (bien, bail, mandat, dossier) et **mène directement à son 360**. Une relation
  qui ne désigne aucun objet ne mène nulle part et n'a pas sa place dans cette zone.
- **Stratégie** : permanent sur un **dossier** uniquement. **Un référentiel n'a pas de
  trajectoire** — pas de Stratégie sur un Tiers, un Bien ou un Immeuble.

Des onglets métier sont ajoutés **uniquement** lorsqu'ils portent une information
spécifique nécessaire. Exemple : *Finances* dans Contentieux — ou sur un Tiers qui porte
réellement de l'argent. *Finances n'est jamais permanent.*

> La **Vue 360** doit permettre de comprendre **80 % de la situation sans changer
> d'onglet**. Les onglets approfondissent, ils ne cachent pas l'essentiel.

### 4.3 Stratégie

- Stratégie porte **l'objectif courant, la trajectoire souhaitée, les options et les
  prochaines étapes envisagées**.
- Une nouvelle information peut conduire MBI à **proposer** une évolution de la frise
  future ou de l'organisation métier du 360.
- Toute modification structurante est **proposée puis validée humainement**.
- Stratégie **ne peut pas supprimer les onglets permanents**.
- **L'IA ne modifie jamais silencieusement la trajectoire structurante d'un dossier.**

---

## 5. Charte IA & « Que s'est-il passé ? »

L'IA n'est **ni un module isolé ni un bouton marketing**. Elle est une **couche
d'interprétation** entre les entrées du monde réel et le modèle métier MBI.

**Chaîne cible :**

```
mail / document / phrase / événement
   -> compréhension
   -> rapprochement avec les objets métier
   -> faits structurés
   -> conséquences
   -> frise
   -> stratégie
   -> échéances / réveils
   -> actions proposées
```

- **Le moteur IA est remplaçable.** La valeur durable est la BDD métier, les relations,
  les règles, les invariants et l'historique.
- L'IA peut reconnaître tiers, immeubles, biens, baux, dossiers, dates, montants,
  intentions et événements.
- **Le backend MBI effectue le rapprochement** avec les identifiants et les objets réels
  **avant toute conséquence métier**.
- Le niveau d'automatisation dépend du **risque** : lecture et proposition peuvent être
  automatiques ; **les conséquences structurantes exigent validation**.
- En cas d'ambiguïté entre plusieurs objets plausibles, **MBI demande confirmation** au
  lieu de choisir silencieusement.

### 5.1 « Que s'est-il passé ? » — porte d'entrée universelle

- L'utilisateur **ne doit pas systématiquement rechercher un dossier** avant de déclarer
  un événement.
- Il décrit ce qui vient d'arriver ; **MBI cherche le contexte**, propose le rattachement
  et les conséquences.
- Le composant doit rester **accessible et visible dans la coquille** lorsqu'il est
  pertinent.
- Dans un dossier ouvert, le contexte est déjà connu. À l'accueil d'un module, MBI doit
  **inférer la cible** et confirmer **uniquement** en cas d'ambiguïté.
- Une déclaration peut devenir **un fait, une échéance, une décision attendue, un
  document, une communication ou une proposition d'évolution stratégique** ; elle ne doit
  pas être réduite systématiquement à une tâche.

---

## 6. Charte Ma journée

- Ma journée est **transversale**. Elle n'est **pas un troisième moteur de tâches**.
- ⚠️ **Ma journée ne lit que les ACTIONS** *(règle resserrée le 13/09/2026)*. Une **attente**
  dont la balle est chez un tiers extérieur **n'y entre pas** : rien ne m'incombe tant que
  la balle n'est pas revenue. Elle y entre au moment où elle **engendre une action**, c'est
  à dire quand quelqu'un de notre organisation doit faire quelque chose.
  *Avant cette révision, la charte disait « agrège faits datés, échéances, réveils,
  attentes, risques et actions » — assez large pour remplir Ma journée de lignes sur
  lesquelles personne ne peut agir, ce qui est la façon la plus sûre de la faire ignorer.*
- Les faits, échéances, réveils, attentes et risques **restent visibles là où ils vivent** —
  accueils, cards, 360 — et **alimentent le calcul** des actions et des relances.
- Elle doit présenter **ce qui mérite réellement l'attention**, plutôt que demander à
  l'utilisateur de parcourir chaque module.
- La **priorité** résulte de la situation métier, de l'urgence, de l'échéance, du risque
  et du rôle de l'utilisateur.
- Le parcours cible peut partir d'une question ou d'un événement, enrichir progressivement
  le contexte, proposer des vérifications IA puis des actions.
- Une donnée de Contentieux, Patrimoine, GED, mail ou autre module **alimente** Ma journée
  **sans créer une vérité parallèle**.

---

## 7. Charte Mail / Communication / entrées externes

- Le mail est une **entrée intelligente**, pas le propriétaire de la vérité métier.
- Un message peut être analysé, classé, rapproché d'un contexte et transformé en
  **proposition** de faits ou d'actions.
- Une fois le contexte reconnu, **le module métier concerné devient propriétaire du
  suivi**.
- **Éviter la double saisie** entre mail, dossier, GED, tiers et tâches.
- Conserver une **traçabilité suffisante** entre la communication source et les
  conséquences produites.
- L'automatisation progresse par **niveaux de confiance et de risque** ; une action
  sensible ne doit pas être exécutée silencieusement.

---

## 8. Charte GED & documents

- La GED est **centrale et partagée** par les modules ; **ne pas recréer une GED
  spécifique dans chaque métier**.
- Un document doit pouvoir être **lié aux objets métier pertinents sans duplication
  physique inutile**.
- Le **nommage automatique est déterministe** et ne doit pas inventer une information
  absente ou ambiguë.
- L'extraction IA/OCR **enrichit** le document et **propose** des rattachements ; elle ne
  crée pas de vérité non vérifiée.
- Le dépôt documentaire doit être **accessible depuis les parcours métier** sans provoquer
  de double saisie.

---

## 9. Charte métier : faits, attentes, décisions et actions

### 9.0 Trois objets qu'on ne fusionne jamais *(13/09/2026)*

| Objet | Ce qu'il dit | Porte |
|---|---|---|
| **ATTENTE** | Quelque chose est attendu **d'un porteur**. C'est la vérité de la situation. | objet attendu · **porteur actuel** · origine · date d'ouverture · dernier passage de balle · échéance / relance · contexte · statut · résolution |
| **ACTION** | Quelqu'un **de notre organisation** doit faire quelque chose. | assigné · échéance · priorité · durée · état |
| **NOTE / INTENTION** | Information ou intention **sans engagement actuel**. | texte · rattachement · date |

- ⚠️ **Le porteur d'une attente n'est pas un booléen `nous / eux`** : c'est une entité
  réelle (Dupont, le notaire X, l'assureur Y, Régie Emery). « Chez nous / chez eux » est
  **calculé** selon le point de vue de celui qui regarde (§11.3).
- **Le passage de balle est un événement daté et historisé** : il nourrit la chronologie
  sans aucune saisie supplémentaire, et rend mesurable le **temps passé chez nous**.
- ⚠️ **Ne jamais forcer une note dans le moteur d'attentes** : ça fabrique un porteur qui
  n'a rien demandé et une échéance que personne n'a promise (cf. §10, les valeurs
  inconnues restent inconnues).
- Détail complet et cas d'usage : [`DOCTRINE_ACCUEIL_360_MBI.md`](DOCTRINE_ACCUEIL_360_MBI.md) §7 à §11.

### 9.1 Principes généraux

- **Distinguer ce qui s'est passé de ce que l'on veut faire.**
- Un **fait** appartient au passé établi ; une **action** appartient à l'intention ; une
  **échéance ou un réveil** représente une attente temporelle.
- Un état vague tel que « en attente » **ne suffit pas** à piloter un dossier actif : il
  faut une **date certaine, un réveil ou une décision attendue** lorsque le métier l'exige.
- **« Ne rien faire » peut être une stratégie valide** si elle possède une règle de réveil.
- Les corrections historiques doivent **préserver la traçabilité** plutôt que réécrire
  silencieusement les faits.
- Les **décisions sont des faits métier** lorsqu'elles se produisent ; elles ne nécessitent
  pas automatiquement une table autonome.

---

## 10. Charte de conception par cas réels

- Les écrans et le modèle sont testés contre des **dossiers réels complexes**, pas
  uniquement contre des cas idéaux.
- **Les valeurs inconnues restent inconnues** : le logiciel ne doit pas inventer une date,
  un montant, un propriétaire, un rôle ou une relation pour compléter l'interface.
- Les **cas limites deviennent des tests permanents** lorsqu'ils révèlent un invariant
  important.
- Un nouveau concept est justifié par **au moins un besoin réel démontré** et, idéalement,
  par **plusieurs usages avant mutualisation**.

---

## 11. Charte de développement et de livraison

- **Audit avant code** : vérifier ce qui existe réellement dans la coquille, les
  composants, les tables, les registres et les routes **avant de recréer**.
- **Réutiliser la coquille commune** ; ne pas construire un second layout pour un module.
- Chaque **écriture sensible** doit être précédée d'une **autorisation de périmètre**.
- Les tests doivent couvrir **modèle, contraintes, périmètres, routes et parcours
  navigateur** significatifs.
- Tester aux dimensions **desktop et mobile** ; une correction desktop ne doit pas casser
  le mobile.
- **Ne pas annoncer une fonctionnalité comme terminée** si seule l'interface existe sans
  plomberie réelle, **ou inversement**.
- Les **dettes connues sont nommées explicitement** et ne doivent pas devenir des
  comportements officiels par oubli.

### 11.1 Prouver plutôt qu'affirmer

**Un défaut d'interface ne proteste pas.** Le lint passe, la suite est verte, et l'écran
est faux. Ces quatre règles viennent toutes d'incidents réels et coûteux.

**a) Le rendu est la vérité, pas le markup.**
Vérifier que le HTML contient l'élément ne prouve rien. Il faut mesurer ce qui est
**réellement peint et atteignable**.

> Un modal a rendu ses boutons **inatteignables** : le fond était en `position:fixed`
> (donc il ne défile pas) et la carte n'avait ni `max-height` ni `overflow`. Tout était
> servi, rien n'était cliquable. **Ajouter un champ à un modal, c'est risquer d'en
> expulser le bouton.** Le contrôle qui l'attrape, à la taille d'écran réelle :
> `document.elementFromPoint(cx, cy) === bouton` — `getBoundingClientRect()` seul dit où
> est l'élément, pas s'il est atteignable.

**b) La présence ne vaut rien, c'est l'ORDRE qui compte.**

> « Je ne peux pas reprendre le mandat » — le bouton existait. **Onze mille octets plus
> bas**, sous un bandeau d'alerte et un formulaire utiles dans un seul cas sur cent. Le
> geste quotidien passe **avant** la paperasse d'exception. Se mesure aux octets, pas à
> l'œil : `grep -abo "<le geste>" page.html` contre `grep -abo "<l'exception>" page.html`.

**c) Toute règle qui refuse doit être prouvée sur la population réelle.**

> Une garde interdisait de diffuser une annonce pendant le délai de rétractation. Code
> juste, règle juste, **relue**. Elle n'a jamais gardé personne : la colonne qu'elle
> interrogeait valait `NULL` sur **728 mandats sur 728**, parce qu'aucun écran ne la
> demandait. **Une colonne dont dépend un refus doit avoir au moins un écrivain.**
> Avant d'annoncer qu'une règle protège, **compter combien de lignes elle atteint**.

**d) Mesurer avant d'annoncer, et corriger par migration, jamais à la main.**

- Un chiffre s'énonce après l'avoir compté **sur toute la base**, pas sur un échantillon
  ni sur un souvenir.
- Une correction de données passée à la main **ne se rejoue pas** sur les autres
  environnements : elle doit être une migration, ou un correctif de **code** qui vaut
  partout. Quand le choix existe, **corriger la résolution plutôt que les données** :
  c'est non destructif et ça vaut pour tous les environnements sans rien rejouer.
- Avant la première modification d'un fichier dans une session, en figer une copie
  (`foo_exN.php`) et **la livrer avec** : le retour arrière doit être possible **sur le
  serveur**, sans re-téléverser depuis un poste. ⚠️ Toujours **lister les `_ex*` existants
  avant de copier** — l'écrasement est silencieux et irrécupérable.

### 11.2 Un refus nomme sa cause

C'est la Loi 7 du Manifeste — *« le logiciel explique toujours ce qu'il vient de faire »* —
appliquée au moment où il **refuse**.

- **Pour l'utilisateur** : un refus dit **pourquoi** et **quoi faire ensuite**. « Document
  indisponible » n'est pas un message, c'est un haussement d'épaules.
- **Pour le développeur** : une fonction d'autorisation qui rend `null` pour six raisons
  différentes rend le diagnostic impossible.

> Des documents s'affichaient « indisponibles ». Le premier réflexe — les droits — était
> faux : l'autorisation répondait **oui**. C'était la résolution du chemin qui échouait,
> cinquième cause sur six, toutes rendant le même `null` muet. Une heure perdue à chercher
> du mauvais côté.

- **Distinguer explicitement l'INTERDIT de l'INDISPONIBLE** : « vous n'y avez pas droit »
  et « le fichier n'est pas là » appellent deux gestes opposés.
- Un écran qui **pré-teste** la disponibilité et l'annonce vaut mieux qu'un écran qui offre
  un lien mort. **Une page qui n'annonce pas l'indisponibilité n'est pas une page qui
  marche** : elle est seulement moins honnête.

### 11.3 Un état affiché est CALCULÉ, jamais un drapeau figé

Un voyant, un badge, un statut se **dérivent des faits** au moment de l'affichage. Un
drapeau posé en base « pour aller plus vite » finit toujours par mentir : il survit à la
correction du fait qu'il résume.

Corollaire de §1.1 — *un affichage peut être dérivé, il ne doit pas devenir une seconde
vérité*. Si le calcul coûte trop cher, c'est le modèle qu'il faut revoir, pas la vérité
qu'il faut dupliquer.

---

## 12. Règles de non-régression produit

| Principe | Interdit sans décision explicite |
|---|---|
| **Full-web** | Introduire une dépendance à un client lourd pour un besoin que le web sait couvrir. |
| **Clics** | Ajouter une étape de navigation sans démontrer sa nécessité. |
| **Dropdown métier** | Revenir aux sélecteurs massifs comme grammaire principale. |
| **Frise** | Créer une Chronologie parallèle à la frise **d'un dossier** · poser une frise sur un **référentiel** ou sur un **accueil**. |
| **360** | Supprimer Échanges, Documents ou Relations · supprimer Stratégie **d'un dossier**. |
| **Attente / action** | Fusionner attente et action · réduire le porteur à un booléen `nous/eux` · faire lire autre chose que des **actions** à Ma journée · forcer une **note** dans le moteur d'attentes. |
| **IA** | Laisser l'IA modifier silencieusement une vérité ou une stratégie structurante. |
| **BDD** | Dupliquer un fait pour faciliter un écran. |
| **Ma journée** | Créer un troisième système de tâches. |
| **GED** | Recréer un stockage documentaire par module. |
| **CHAÎNE** | Casser le contexte courant pour consulter un autre objet lorsque l'ouverture parallèle suffit. |
| **Rendu partagé** | Recopier un bloc d'affichage déjà écrit ailleurs (écran, mail, PDF, page publique). |
| **Refus muet** | Refuser, griser ou masquer sans dire **pourquoi** ni **quoi faire ensuite**. |
| **Règle non prouvée** | Annoncer qu'une règle protège sans avoir compté les lignes qu'elle atteint réellement. |
| **Drapeau figé** | Stocker en base un état qui peut être calculé à partir des faits. |
| **Correction manuelle** | Réparer des données à la main sans migration ni correctif de code rejouable. |
| **Chiffre non mesuré** | Énoncer un volume, un taux ou un « c'est corrigé » sans l'avoir compté sur toute la base — et sans dire **où**. |

---

## 13. Checklist obligatoire avant toute nouvelle page

- [ ] Quel problème réel cette page résout-elle ?
- [ ] Peut-on supprimer le **premier** clic ? Le **deuxième** ?
- [ ] L'utilisateur doit-il réellement chercher, ou MBI peut-il lui **apporter** la situation ?
- [ ] Quel est l'**objet métier pivot** et où se trouve la vérité ?
- [ ] Existe-t-il **déjà** une table, un composant, une route ou un registre qui couvre le besoin ?
- [ ] Quel est le **périmètre d'accès** en lecture et en écriture ?
- [ ] S'il s'agit d'un 360 : **référentiel ou dossier** ? *(§4.0 — question posée en premier)*
- [ ] S'il s'agit d'un **dossier** : **quelle est sa frise** ? S'il s'agit d'un **référentiel** : aucune frise, quelle **chronologie** ?
- [ ] S'il s'agit d'un 360 : quels **onglets métier** en plus de Échanges, Documents, Relations (+ Stratégie si dossier) ?
- [ ] Chaque **relation** affichée désigne-t-elle un objet, et mène-t-elle à son 360 ?
- [ ] Ce que l'écran montre est-il une **attente**, une **action** ou une **note** ? *(ne jamais les mélanger — §9.0)*
- [ ] S'il y a une attente : **qui est le porteur réel**, et « chez nous / chez eux » est-il **calculé** ?
- [ ] S'il s'agit d'un accueil : le **premier niveau permet-il de TRAITER** sans ouvrir le 360 ?
- [ ] Chaque **niveau contextuel** ouvert a-t-il une **URL** qui le reconstruit ? *(§3)*
- [ ] Sur mobile, le **retour restitue-t-il exactement l'état précédent** — recherche, filtre, scroll, sélection ?
- [ ] Quelle partie du **futur** peut évoluer par Stratégie, et quelles évolutions doivent être validées ?
- [ ] « Que s'est-il passé ? » peut-il **éviter une navigation ou une saisie** supplémentaire ?
- [ ] L'information doit-elle **alimenter Ma journée** ?
- [ ] Un document ou un mail peut-il être **rattaché sans double saisie** ?
- [ ] Y a-t-il un **dropdown métier** remplaçable par recherche / boutons contextualisés ?
- [ ] La page possède-t-elle une **URL autonome** et peut-elle vivre dans un autre onglet/écran ?
- [ ] Le comportement reste-t-il clair **en mobile** ?
- [ ] Quels **cas réels et cas limites** prouvent que le modèle est correct ?
- [ ] Quels **tests** empêchent une régression future ?

**Et avant de dire « c'est fait » :**

- [ ] Le geste principal est-il **atteignable** — mesuré à la taille d'écran réelle, pas
      seulement présent dans le HTML ?
- [ ] Le geste quotidien passe-t-il **avant** la paperasse d'exception dans l'ordre de la page ?
- [ ] Si un écran **refuse** quelque chose : dit-il **pourquoi** et **quoi faire ensuite** ?
      Distingue-t-il l'**interdit** de l'**indisponible** ?
- [ ] Si une règle protège : sur **combien de lignes réelles** mord-elle aujourd'hui ?
      Les colonnes dont elle dépend ont-elles un **écrivain** ?
- [ ] Un **affichage partagé** existe-t-il déjà pour ce que j'allais écrire ?
- [ ] Les chiffres annoncés ont-ils été **comptés sur toute la base** — et ai-je dit **où** ?
- [ ] Une correction de données est-elle **rejouable** (migration ou correctif de code) ?
- [ ] La copie `_exN` du fichier modifié est-elle figée **et livrée** avec lui ?

---

## 14. Test final : une fonctionnalité est-elle vraiment MBI ?

Avant validation, on doit pouvoir répondre **OUI** à chacune de ces affirmations :

1. Elle **réduit** ou n'augmente pas inutilement le nombre de clics.
2. Elle s'appuie sur la **vérité métier existante** au lieu d'en créer une seconde.
3. Elle respecte le **full-web** et la continuité de travail.
4. Elle est compréhensible **sans exposer la plomberie**.
5. Elle respecte les **périmètres et autorisations**.
6. Elle fonctionne **sur cas réel**, pas seulement sur démonstration.
7. Elle distingue **faits, attentes, décisions et actions**.
8. Elle permet à l'IA de **proposer** sans lui abandonner les décisions structurantes.
9. Elle s'intègre à la frise, aux 360, à Ma journée et à la GED **seulement lorsque cela a
   un sens métier**.
10. Elle est **testée** et ne transforme pas une dette temporaire en doctrine.
11. Son geste principal est **atteignable**, vérifié au rendu réel — pas seulement présent.
12. Quand elle **refuse**, elle dit pourquoi et quoi faire ensuite.
13. Ce qu'on en annonce a été **mesuré**, et on a dit **sur quel environnement**.

> *À rapprocher du **test ultime en 5 questions** du Manifeste
> ([`CONSTITUTION_MABOXIMMO.md`](CONSTITUTION_MABOXIMMO.md), §« Le test ultime ») : les deux
> s'appliquent, l'un sur la valeur rendue au collaborateur, l'autre sur la conformité de
> conception.*

---

## 15. Constitution MBI V2

MBI n'est pas conçu comme une **collection de modules et de formulaires**. Il est conçu
comme un **système métier** qui connaît les objets, leurs relations, ce qui s'est
réellement passé, ce qui est attendu et ce que l'utilisateur cherche à obtenir.

L'utilisateur **ne doit pas apprendre l'organisation interne du logiciel** pour travailler.
MBI doit utiliser son contexte, ses données et l'IA pour rapprocher l'information du bon
objet, faire émerger ce qui mérite l'attention et proposer la prochaine action.

> **La frise explique. La stratégie oriente. Les onglets détaillent.
> « Que s'est-il passé ? » capte le réel. Ma journée priorise. CHAÎNE préserve le fil.
> La BDD métier conserve la vérité. L'humain garde la décision.**

**Principe constitutionnel :**

> **MBI supprime les clics nécessaires pour trouver le travail et conserve les gestes
> nécessaires pour décider, valider et préserver son travail.**

---

## 16. Instruction à l'agent de développement

Cette charte est conservée dans le dépôt comme **document normatif**.

**Avant toute évolution significative, l'agent de développement doit la relire et
vérifier explicitement que sa proposition ne contredit aucune règle.**

En cas de contradiction, il **ne doit pas contourner la règle silencieusement** : il doit
**signaler le conflit**, expliquer le besoin métier réel et **demander une décision** avant
modification.

Une décision nouvelle validée qui modifie la doctrine doit conduire à **mettre à jour cette
charte dans le même cycle de développement**, afin que le logiciel et sa doctrine ne
divergent jamais.

---

## 17. Journal des révisions

| Version | Date | Ce qui change |
|---|---|---|
| 1.2 | 13/09/2026 | **Doctrine Accueil & 360** ([`DOCTRINE_ACCUEIL_360_MBI.md`](DOCTRINE_ACCUEIL_360_MBI.md)) figée et répercutée ici. §4.0 *référentiel ou dossier* — la première question avant toute 360, et **pas de frise sur un référentiel** · §4.1 recadrée sur les dossiers · §4.2 permanents renommés **Échanges** (ex-Communication) et **Relations** (ex-Participants), **Stratégie réservée aux dossiers**, *Finances* jamais permanent · §3 URL autonome **étendue aux niveaux contextuels** · §9.0 **ATTENTE / ACTION / NOTE**, trois objets qu'on ne fusionne jamais, le porteur est réel et non un booléen · ⚠️ **§6 RESTRICTION : Ma journée ne lit que les ACTIONS** — une attente dont la balle est chez un tiers n'y entre plus. Conséquence architecturale ouverte : les objets transverses (attente, action, passage de balle, note, relation) doivent exister **avant** de dessiner le 360 Tiers ; état réel mesuré dans `PROJET_GLOBAL_MBI.md`. |
| 1.1 | 11/09/2026 | **Ajouts, aucune suppression.** §1.2 bis *une seule vérité vaut aussi pour le RENDU* · §11.1 *prouver plutôt qu'affirmer* (le rendu est la vérité · l'ORDRE compte, pas la présence · toute règle qui refuse se prouve sur la population réelle · mesurer avant d'annoncer, corriger par migration) · §11.2 *un refus nomme sa cause*, et distingue l'interdit de l'indisponible · §11.3 *un état affiché est calculé, jamais un drapeau figé*. Sept lignes ajoutées aux non-régressions, huit à la checklist, trois au test final. Chaque règle porte l'incident réel dont elle est née. |
| 1.0 | Septembre 2026 | Rassemblement des 7 chartes en un document unique et normatif. Ajout officiel des quatre principes issus des échanges de septembre : **doctrine du clic** (supprimer le 1er puis le 2e), **préservation du fil de travail** (le `+` de CHAÎNE ouvre un nouveau contexte dans un nouvel onglet), **exploiter le full-web plutôt que réinventer le navigateur**, **doctrine 360 enrichie** (la frise EST la chronologie ; permanents = Communication / Documents / Participants / Stratégie). Élévation de la phrase constitutionnelle au rang de test de cohérence de toutes les décisions MBI V2. |

*Les principes historiques qu'elle consolide étaient déjà posés : full-web, mobile-first,
interface légère, plomberie invisible, pas de dropdown métier, dossier 360 avec shell
commun, CHAÎNE, « Que s'est-il passé ? » permanent, frise pilotée par les faits et jamais
un simple stepper, distinction fait / action / attente / risque, IA qui comprend et propose
mais humain qui valide, Ma journée comme couche transversale de pilotage, et richesse
fonctionnelle invisible tant qu'elle n'est pas nécessaire. La doctrine d'août —
« l'humain informe MBI → MBI analyse/pilote → la frise situe → les onglets détaillent » —
en est la formulation d'origine ; cette charte la rend précise et exploitable écran par
écran.*

---

**MBI V2 — Charte officielle de conception — Septembre 2026**
