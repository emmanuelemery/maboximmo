# DOCTRINE ACCUEIL & 360 — MaBoxImmo

**Comment se conçoivent les écrans d'accueil et les pages 360 de tous les modules**
*Version 1.0 — 13/09/2026 — document normatif, gravé dans le dépôt*

---

> **Ne jamais mélanger ATTENTE, ACTION et NOTE.**
> **Raisonner mobile-first dès le composant, pas après.**

---

## Où cette doctrine se situe

| Document | Rôle |
|---|---|
| [`CONSTITUTION_MABOXIMMO.md`](CONSTITUTION_MABOXIMMO.md) | **Pourquoi** MBI existe. Corps immuable. |
| [`CHARTE_CONCEPTION_MBI_V2.md`](CHARTE_CONCEPTION_MBI_V2.md) | **Comment** on conçoit, toutes pages confondues. Normative. |
| **Ce document** | **Comment** se conçoivent spécifiquement les **Accueil** et les **360**. Précise et étend §2, §4, §6 et §9 de la Charte. |
| [`PROJET_GLOBAL_MBI.md`](PROJET_GLOBAL_MBI.md) | **Quoi** — l'état réel mesuré et le reste à faire. |

⚠️ Cette doctrine **ne remplace pas** la Charte : elle l'affine. En cas de désaccord
apparent, la Charte prime et l'écart se signale (Charte §0, §16). Les points où cette
doctrine **fait évoluer** la Charte sont listés au §24 — ils ont été portés dans la Charte
elle-même dans le même cycle, comme l'exige `CLAUDE.md`.

---

## 1. Deux niveaux pour tous les objets

Tous les modules suivent la même logique :

> **ACCUEIL → 360**

Mais ils n'ont pas la même fonction.

- **Accueil = trouver + détecter + prioriser + traiter rapidement.**
- **360 = comprendre + relier + retracer + agir avec le contexte.**

**L'accueil n'est jamais un « petit 360 ».** Si un accueil commence à ressembler à une
fiche réduite, c'est qu'il a cessé de faire son travail.

---

## 2. Deux familles d'objets

### Référentiels — *Tiers, Bien, Immeuble*

Ils existent durablement. Ils n'ont ni début, ni étape courante, ni clôture.

> **Identité → Relations → Chronologie → Contenus → Situation actuelle**

**Pas de frise métier.**

### Dossiers — *Créancier, Sinistre, Vente, procédure…*

Ils ont une ouverture, une évolution, des étapes et une issue.

> **Contexte → Relations → Frise métier → Chronologie → Contenus → Suite → Clôture**

Ici la frise a tout son sens.

### Règle

> **Jamais de frise sur un accueil.**
> **360 référentiel = relations + chronologie.**
> **360 dossier = frise métier + chronologie.**

⚠️ **Précision par rapport à la Charte §4.1** (« la frise EST la chronologie »). Cette règle
visait les **dossiers**, et elle y reste entière : pas d'onglet Chronologie en doublon de la
frise. Sur un **référentiel**, il n'y a pas de frise — la chronologie n'y double donc rien,
elle est la seule narration possible. Les deux règles ne se contredisent pas : elles ne
parlent pas de la même famille d'objets.

---

## 3. L'accueil Tiers

Il ne doit surtout pas devenir un annuaire de quinze colonnes. La question posée est :

> **Qui est-ce que je cherche, et qu'est-ce qui mérite mon attention ?**

L'écran tient sur trois choses : **recherche universelle · pilotage · « Que s'est-il
passé ? »**.

---

## 4. La recherche EST l'accueil

En haut : *🔎 Rechercher un tiers, téléphone, email, adresse, bien, immeuble, bail,
dossier…*

On retrouve Dupont par son nom, **mais aussi par son téléphone, son appartement, ou
n'importe quoi auquel il est relié**. Avant toute frappe, on peut montrer les tiers
récemment consultés.

Dès la frappe de `Dup`, les résultats apparaissent — **pas de page intermédiaire** :

```
DUPONT Jean      Propriétaire · 2 biens · 1 bail
DUPONT Sophie    Locataire · Résidence X
```

Et sans résultat :

```
Aucun tiers correspondant.
+ Créer « Dup… »
```

**La saisie existante préremplit la création.** Retaper ce qu'on vient d'écrire est une
dette au sens du Manifeste.

---

## 5. Création minimale, enrichissement progressif

> **Identifier suffisamment → créer → enrichir progressivement.**

On ne demande pas quinze informations pour créer un tiers. Mais **avant** la création, MBI
cherche les rapprochements : nom proche, téléphone identique, email identique, adresse,
société. On limite les doublons **sans** imposer un formulaire lourd.

---

## 6. Les doublons sont une surveillance permanente

La détection ne s'arrête pas à la création :

```
Doublon probable — confiance élevée
Jean DUPONT / DUPONT Jean · même téléphone · emails différents
[ Comparer ]  [ Fusionner ]  [ Deux personnes différentes ]
```

Cela alimente naturellement la situation **À vérifier** (§12).

---

## 7. Le concept central : l'ATTENTE

> **Une attente signifie que quelque chose est attendu de quelqu'un.**

Elle porte notamment : **objet attendu · porteur actuel · origine · date d'ouverture ·
date du dernier passage de balle · échéance ou relance · contexte · statut · résolution.**

⚠️ **Le porteur n'est pas un booléen `nous / eux`.** Le porteur est **réel** : Dupont, le
notaire X, l'assureur Y, Régie Emery, l'entreprise Z. « Chez nous / chez eux » est
**calculé** selon le point de vue de celui qui regarde — conformément à la Charte §11.3
(*un état affiché est calculé, jamais un drapeau figé en base*).

---

## 8. « J'attends » et « Je dois » ne sont pas la même chose

### J'attends sa réponse

J'ai demandé une attestation à Dupont. La balle est **chez Dupont**. Je n'ai rien à faire
aujourd'hui. MBI surveille :

```
Attestation attendue · balle chez Dupont depuis 3 jours · relance le 16 septembre
```

**Cela n'alimente pas Ma journée** tant qu'aucune action ne m'incombe.

### Je dois lui répondre

Dupont a envoyé l'attestation et attend ma validation. La balle revient **chez nous**.
L'attente existe toujours, mais elle **engendre une ACTION** :

```
Vérifier l'attestation · Annaelle · 5 min · échéance aujourd'hui
```

**Celle-ci entre dans Ma journée.**

---

## 9. Une attente n'est jamais une tâche

Trois objets **distincts**, qu'on ne fusionne pas pour se simplifier la vie :

| Objet | Ce qu'il dit | Porte |
|---|---|---|
| **ATTENTE** | Quelque chose est attendu d'un porteur. C'est **la vérité de la situation**. | objet · porteur · origine · échéance · statut |
| **ACTION** | Quelqu'un **de notre organisation** doit faire quelque chose. | assigné · échéance · priorité · durée · état |
| **NOTE / INTENTION** | Information ou intention **sans engagement actuel**. | texte · rattachement · date |

> **Ma journée lit les ACTIONS, jamais les dossiers ni les attentes.**

Exemple de note : *« Penser à demander au propriétaire s'il veut refaire la cuisine au
prochain départ. »* Ce n'est ni une attente ni forcément une action. On conserve
l'information ; elle pourra devenir une action plus tard.

⚠️ **Ne jamais forcer une note dans le moteur d'attentes.** Une note transformée d'office
en attente fabrique un porteur qui n'a rien demandé, et une échéance que personne n'a
promise — exactement le genre de valeur inventée que la Charte §10 interdit.

---

## 10. Le passage de balle est un événement métier

Chaque changement de porteur est **daté et historisé** :

```
10/09 09:42   Balle → Régie Emery   Dupont répond.
10/09 14:18   Balle → Dupont        Annaelle demande une nouvelle attestation.
13/09         Balle chez Dupont depuis 3 jours.
16/09         Seuil de relance atteint.
```

Cela nourrit la chronologie sans aucune saisie supplémentaire.

---

## 11. Le temps de balle

Conséquence directe du modèle, et **sans demander une seule statistique aux
collaborateurs** :

```
Temps chez nous : 7 h 42     Temps extérieur : 6 j 13 h
```

Et surtout : **depuis combien de temps la balle est-elle chez nous ?** C'est un indicateur
réel de qualité de service. Agrégeable ensuite : temps moyen de réponse, situations chez
nous > 24 h, temps extérieur, temps avant passage de balle.

---

## 12. Les cards de l'accueil

**Pas huit KPI.** Trois cards principales, une quatrième seulement si l'usage la justifie.

| Card | Ce qu'elle montre |
|---|---|
| **CHEZ NOUS** | Situations dont nous sommes actuellement porteurs. ⚠️ Le travail correspondant est porté par les **actions** associées, pas par la card. |
| **CHEZ EUX** | Situations où nous attendons un tiers extérieur. **Aucune charge de travail immédiate** jusqu'à la relance prévue. |
| **À VÉRIFIER** | Décision ou vérification nécessaire : doublon probable, identité douteuse, RIB incohérent, donnée contradictoire. |
| *(EN RETARD)* | **Seulement** si l'expérience démontre qu'elle apporte une information réellement différente. Sinon on reste à trois. |

---

## 13. Cliquer sur une card ne change pas de page

> **Niveau 1 = TRAITER · Niveau 2 = COMPRENDRE · 360 = APPROFONDIR**

Sur desktop, la card ouvre un **niveau contextuel** latéral. **Il ne faut surtout pas
imposer trois clics avant d'agir.** Dès le premier niveau :

```
DUPONT Jean — 5 jours
Attestation d'assurance attendue
[ Relancer ]  [ Reçu ]  [ ••• ]
```

Je relance **immédiatement**. Je n'ouvre le niveau suivant que si je veux savoir *pourquoi*
cette attente existe, et le 360 seulement s'il me faut le contexte complet.

---

## 14. Cascade desktop

> **Accueil → panneau ~50 % → détail contextuel ~1/3 → 360 si nécessaire**

⚠️ **Les proportions 50 % / ⅓ ne font pas partie du modèle.** Ce n'est qu'un rendu
desktop. Le composant réel s'appelle un **niveau contextuel** — c'est lui qu'on
implémente, pas une largeur.

### 14.1 Un niveau contextuel a une URL

Ajouté au titre de la **Charte §3** (*full-web*), que cette doctrine ne peut pas contourner :
chaque niveau ouvert doit être **reconstructible par son URL**. Un panneau latéral qui
n'existe que dans l'état JavaScript de la page ne s'ouvre pas dans un second onglet, ne se
déplace pas sur un second écran, ne se partage pas, et ne survit pas à un rechargement.
C'est aussi ce qui rend techniquement possible la conservation du contexte du §15.

---

## 15. Mobile-first

Sur téléphone, **aucune réduction artificielle du desktop**. La même navigation devient :

> **Accueil → écran contextuel plein écran → détail plein écran → 360**

Navigation empilée. Et surtout :

> **Le retour restitue exactement l'état précédent** — recherche, filtre, scroll, card
> ouverte, sélection.

Je traite Dupont → retour → je retrouve exactement ma liste des 14, au même endroit.
**Cette conservation du contexte est obligatoire**, pas un raffinement.

---

## 16. Traitement en lot

Une card doit permettre d'**agir**, jamais d'être un compteur décoratif.

*14 réponses extérieures en retard de relance.* Je sélectionne 8 personnes. MBI prépare
**8 relances individualisées**, en utilisant le contexte de chaque attente. Je contrôle,
j'envoie. Chaque envoi **alimente la chronologie, constitue un fait, modifie le cas échéant
le passage de balle, et recalcule la prochaine relance.**

---

## 17. « Que s'est-il passé ? » sur l'accueil

Brique fondamentale de MBI, **pas réservée aux dossiers**. Depuis l'accueil Tiers :

> *« J'ai eu Dupont au téléphone, il m'envoie l'attestation demain. »*

MBI interprète : **tiers** Dupont · **fait** appel téléphonique · **attendu** attestation ·
**porteur** Dupont · **échéance** demain.

Après validation humaine le cas échéant : le fait entre dans la chronologie · l'attente est
créée ou modifiée · la balle change éventuellement de porteur · l'action précédente peut
être clôturée · la relance future est positionnée. **Sans ouvrir le 360.**

---

## 18. Le 360 Tiers — bandeau identité

> **Qui est-il ? À quoi est-il relié ? Que savons-nous de lui ? Que s'est-il passé ?
> Où est la balle actuellement ?**

Bandeau : nom / société · coordonnées · adresse · rôles · alertes.
Actions rapides adaptées : **Appeler · Mail · SMS · Document · Modifier**.

---

## 19. Les relations structurent le Tiers

```
PROPRIÉTAIRE     Bien Lyon 6            →
BAILLEUR         Bail X                 →
COPROPRIÉTAIRE   Résidence Les Cèdres   →
MANDANT          Mandat 458             →
DÉBITEUR         Dossier créancier 124  →
```

**Chaque relation mène directement au 360 correspondant.** Un tiers cesse d'être une fiche
isolée : il devient **un nœud du réseau MaBoxImmo**.

⚠️ Une relation qui ne désigne pas d'objet ne mène nulle part et n'a pas sa place dans
cette zone. Voir l'état mesuré dans `PROJET_GLOBAL_MBI.md`.

---

## 20. La chronologie du Tiers

Pas de fausse frise d'états. Une **chronologie vivante** :

```
2024 ─── 2025 ─── 2026 ─── Aujourd'hui
```

Elle reçoit : mails · SMS · appels · documents · signatures · changements de coordonnées ·
relations · baux · mandats · paiements · événements dossier · **passages de balle**.

Filtres : **Tout | Échanges | Documents | Relations | Finances | Dossiers**

---

## 21. Les onglets

Volontairement simple pour commencer :

**Vue 360 | Relations | Échanges | Documents | Finances**

> **La Vue 360 doit permettre de comprendre 80 % de la situation sans changer d'onglet.**

Les onglets servent à **approfondir**, jamais à cacher l'essentiel.

⚠️ **Correspondance avec la Charte §4.2.** *Participants* devient **Relations** (plus large :
la relation vise un objet, pas seulement une personne), *Communication* devient
**Échanges**. **Stratégie reste permanent sur les dossiers** — objectif courant, trajectoire,
options, prochaines étapes — et **n'existe pas sur un référentiel** : un Tiers n'a pas de
trajectoire. *Finances* n'est pas permanent : il s'ajoute quand l'objet porte réellement de
l'argent (Charte §4.2, onglets métier justifiés par un besoin démontré).

---

## 22. La doctrine, en une page

> **Tous les modules : Accueil → 360.**
> **Accueil = chercher + détecter + prioriser + traiter.**
> **360 = comprendre + relier + retracer + agir.**
> **La recherche est le cœur de l'accueil.**
> **Jamais de frise sur un accueil.**
> **Référentiel = relations + chronologie.**
> **Dossier = frise métier + chronologie.**
> **Attente = quelque chose est attendu d'un porteur.**
> **Action = notre organisation doit faire quelque chose.**
> **Note = information ou intention sans engagement actuel.**
> **Ma journée ne lit que les actions.**
> **Le passage de balle est un événement daté.**
> **Le temps passé chez nous devient mesurable.**
> **Les KPI sont peu nombreux et actionnables.**
> **Premier niveau = traiter ; deuxième = comprendre ; 360 = approfondir.**
> **Desktop et mobile : même logique, présentation différente.**
> **Mobile = navigation plein écran empilée, contexte intégralement conservé.**
> **Tout ce qui circule nourrit la connaissance sans double saisie.**

---

## 23. Conséquence architecturale — à traiter AVANT de dessiner

> **Avant même de dessiner définitivement le 360 Tiers, les objets transverses
> `attente`, `action`, `événement / passage de balle`, `note` et `relation` doivent être
> parfaitement définis. Sinon l'interface sera belle, et on découvrira en la développant
> que le modèle ne sait pas porter ce qu'elle raconte.**

Ce n'est pas une précaution de principe : l'état réel a été **mesuré le 13/09/2026** et il
confirme le risque. Le détail chiffré, avec ce qui existe et ce qui manque, est dans
[`PROJET_GLOBAL_MBI.md`](PROJET_GLOBAL_MBI.md) — une idée n'entre dans la feuille de route
qu'avec l'état réel de ce qui existe, jamais comme un vœu.

---

## 24. Ce que cette doctrine fait évoluer dans la Charte

Portée dans `CHARTE_CONCEPTION_MBI_V2.md` **dans le même cycle**, comme l'exige
`CLAUDE.md` (« un conflit avec la Charte se signale et s'arbitre — jamais contourné en
silence ») :

| Règle de la Charte | Évolution | Nature |
|---|---|---|
| §4 — *trois questions avant toute 360*, dont « quelle frise ? » | La première question devient **« référentiel ou dossier ? »**. La frise n'est obligatoire que pour un dossier. | **Précision** |
| §4.1 — *la frise EST la chronologie* | Vaut pour les **dossiers**. Sur un référentiel, la chronologie ne double aucune frise. | **Précision** |
| §4.2 — *permanents : Communication · Documents · Participants · Stratégie* | Renommage **Communication → Échanges**, **Participants → Relations**. **Stratégie** reste permanent sur les dossiers, **disparaît des référentiels**. | **Modification** |
| §6 — *Ma journée agrège faits datés, échéances, réveils, attentes, risques et actions* | **Ma journée ne lit que les ACTIONS.** Une attente dont la balle est chez un tiers n'y entre pas. | ⚠️ **Restriction — le changement le plus net** |
| §9 — *un état vague tel que « en attente » ne suffit pas* | L'ATTENTE devient un **objet de premier rang** avec un **porteur réel**, ce qui satisfait enfin cette exigence. | **Renforcement** |
| §3 — *URL autonome* | Étendu explicitement aux **niveaux contextuels**, pas seulement aux 360. | **Précision** |

---

**MaBoxImmo — Doctrine Accueil & 360 — 13/09/2026**
