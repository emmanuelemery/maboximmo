# MaBoxImmo — instructions de session

> **MBI supprime les clics nécessaires pour trouver le travail et conserve les gestes
> nécessaires pour décider, valider et préserver son travail.**

---

## 🏛️ La doctrine est dans le dépôt — la lire, pas la deviner

| Fichier | Rôle |
|---|---|
| **[`CHARTE_CONCEPTION_MBI_V2.md`](CHARTE_CONCEPTION_MBI_V2.md)** | **COMMENT** on conçoit. Normative. 17 sections + checklist obligatoire avant toute nouvelle page. |
| **[`CONSTITUTION_MABOXIMMO.md`](CONSTITUTION_MABOXIMMO.md)** | **POURQUOI** MBI existe. 10 lois, 4 moteurs, test ultime en 5 questions. Corps immuable. |
| **[`PROJET_GLOBAL_MBI.md`](PROJET_GLOBAL_MBI.md)** | **QUOI** — feuille de route vivante : chantiers en cours, idées inscrites, dettes chiffrées, livraisons. Une idée y entre avec l'état RÉEL de ce qui existe, mesuré — jamais comme un vœu. |
| **[`DOCTRINE_ACCUEIL_360_MBI.md`](DOCTRINE_ACCUEIL_360_MBI.md)** | **COMMENT on conçoit un ACCUEIL et un 360.** Normative, 24 sections. Deux familles d'objets (référentiel vs dossier), la recherche EST l'accueil, **ATTENTE ≠ ACTION ≠ NOTE**, le porteur de balle est réel, **Ma journée ne lit que les actions**, 3 cards, niveau contextuel avec URL, mobile-first dès le composant. **À relire AVANT de dessiner un accueil ou une 360.** |
| **[`CHARTE_LECTURE_CRG.md`](CHARTE_LECTURE_CRG.md)** | **COMMENT ON LIT UN CRG.** Normative, 11 sections. Toutes les règles de lecture, d'extraction et d'analyse, **avec les spécificités par agence** (ICS `lyon` / `emery_immo`, SEPTEO SPI VIENNE / CHAPONOST). Chaque règle cite le défaut mesuré qui l'a provoquée. **À relire AVANT toute reprise du chantier CRG.** |

**Relire la Charte AVANT toute évolution significative** — nouvel écran, nouveau module,
nouvelle table, nouvelle automatisation. Vérifier explicitement que la proposition ne
contredit aucune règle.

⚠️ **Un conflit avec la Charte se SIGNALE et s'arbitre — jamais contourné en silence.**
Si le métier impose une évolution de doctrine : expliquer le besoin réel, demander la
décision, puis **mettre à jour la Charte dans le même cycle de développement**.

### Les non-négociables, à avoir en tête sans rouvrir le fichier

- **Doctrine du clic** — supprimer le 1er, puis chercher à supprimer le 2e. Un clic ne
  survit que s'il est *décision, validation, sécurité ou vraie intention de navigation*.
- Cible `Situation → Objet → Action`. Jamais `Module > Liste > Filtres > Objet`.
- **Pas de dropdown** pour les choix métier : boutons, recherche, choix contextualisés.
- **Full-web** : chaque objet important a une **URL autonome**. On exploite onglets,
  fenêtres et double écran du navigateur — on ne refabrique pas un desktop.
  Le `+` de CHAÎNE ouvre un **nouveau contexte dans un nouvel onglet**, sans détruire le courant.
- **Pages 360** : d'abord **référentiel ou dossier ?** Un **dossier** a une frise, et la
  **frise EST la chronologie** (jamais d'onglet Chronologie en doublon). Un **référentiel**
  (Tiers, Bien, Immeuble) **n'a pas de frise** : relations + chronologie.
  Permanents = **Échanges · Documents · Relations · Stratégie** (*Stratégie sur les dossiers
  seulement*), plus les seuls onglets métier nécessaires. Le passé est factuel ; le futur
  évolue **après validation humaine**.
- **ATTENTE ≠ ACTION ≠ NOTE.** Une attente dit que quelque chose est attendu d'un **porteur
  réel** (pas un booléen nous/eux). Une action est ce que **notre** organisation doit faire.
  Une note n'engage rien. **Ma journée ne lit que les ACTIONS.**
- **IA** : couche d'interprétation, jamais un bouton. Elle **propose**, l'humain **valide**
  dès que la conséquence est structurante. En cas d'ambiguïté, elle **demande**.
- **Une seule vérité par fait** — *et cela vaut aussi pour le RENDU* : un affichage partagé
  (écran, mail, PDF, page publique) s'écrit **une fois** et s'appelle *n* fois.
- **Ma journée** n'est pas un 3ᵉ moteur de tâches · **le mail** n'est pas propriétaire de la
  vérité métier · **la GED est centrale**, jamais recréée par module.
- **Un refus nomme sa cause** et distingue l'**interdit** de l'**indisponible**.
- **Un état affiché est CALCULÉ**, jamais un drapeau figé en base.

⚠️ **Périmètre** : la Charte gouverne **MBI V2**. Les chantiers Hostinger V1 gardent leurs
contraintes ; quand un écran V1 est repris en profondeur, on applique la Charte et **on
signale les écarts**.

---

## 🔬 Prouver plutôt qu'affirmer

**Un défaut d'interface ne proteste pas** : le lint passe, la suite est verte, l'écran est faux.

- **Le rendu est la vérité, pas le markup.** Un élément présent dans le HTML peut être
  inatteignable. Le vérifier au rendu réel, à la taille d'écran réelle
  (`document.elementFromPoint(cx, cy) === bouton`).
- **L'ORDRE compte, pas la présence.** Un bouton peut exister 11 000 octets trop bas.
  Se mesure : `grep -abo "<le geste>" page.html` contre `grep -abo "<l'exception>" page.html`.
- **Toute règle qui refuse se prouve sur la population réelle.** Une colonne dont dépend un
  refus doit avoir au moins un **écrivain**, sinon la garde est décorative.
- **Mesurer avant d'annoncer** : un chiffre se compte sur toute la base, et on dit **où**
  (local ? prod ? conteneur ?). « C'est corrigé » sans dire OÙ n'a aucune valeur de vérité.

---

## 🛠️ Règles de travail sur ce dépôt

- **Le local est la source de vérité** ; la prod en est une copie. Aucune table, colonne ou
  fichier créé directement en prod.
- **Corriger par migration ou par code, jamais à la main** : une correction manuelle ne se
  rejoue pas sur les autres environnements. Quand le choix existe, corriger la **résolution**
  plutôt que les **données** — non destructif, et valable partout sans rien rejouer.
- **Snapshot `_exN` avant la première modification d'un fichier dans une session**, livré
  avec lui (retour arrière possible **sur le serveur**, par simple renommage).
  ⚠️🔥 **Lister les `_ex*` existants dans une commande SÉPARÉE, et LIRE le résultat avant
  de copier.** `cp` écrase en silence, et les `_exN` ne sont pas suivis par git.
  *Vécu deux fois le 11/09/2026 : `ls … || echo "(aucun)"` puis `cp` **dans la même
  commande** — le `ls` affichait bien les snapshots existants, mais le `cp` s'exécutait
  dans la foulée, sans que personne puisse s'arrêter.* Un contrôle dont on ne peut pas
  tenir compte n'est pas un contrôle.
  Récupération, dans l'ordre : `find C:	mp -name "<fichier>_ex*.php"` (les lots de
  déploiement en gardent une copie), puis `git ls-files` (certains `_exN` sont tracés),
  puis reconstruction par retrait du bloc ajouté.
- **Jamais `sed -i` sur un fichier du projet.** Éditer par motif exact, avec vérification
  d'unicité.
- **Déploiement** : un dossier `C:\tmp\deploy_<nom>_<date>\public_html\` dont on téléverse le
  **contenu**. Un chantier = **un seul** dossier, jamais un second daté. Annoncer le chemin
  complet et la liste des fichiers à chaque mise à jour.
- **Tests structurels** : `tests/structure/*.php`. Un apprentissage se solde par **un test
  qui rougit** sur le code d'avant et passe sur le code d'après.
- **Jamais de commit ni de push sans demande explicite.**

---

## 🗂️ Repères

- Base locale : MySQL `maboximmo` sur `127.0.0.1` (`db_config.php`, gitignoré).
  Tests : `mbi_test`, **jamais** `mbi`.
- Migrations : `public_html/inc/migrations/`, jouées depuis `admin/admin_migrations.php`.
  ⚠️ **Jamais de clause `AFTER`** dans un `ALTER` de migration (passe en local, tombe en
  bloc en prod avec `1054 Unknown column`).
- Le VPS (`76.13.59.234`) porte **tout le `.com` / V2** ; `maboximmo.fr` reste sur le
  mutualisé Hostinger. Ce ne sont pas le même environnement.
