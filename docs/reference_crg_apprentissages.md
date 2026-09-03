# REGISTRE D'APPRENTISSAGE DE L'AGENT D'INTÉGRATION CRG

> **Ce fichier est la mémoire de l'agent, pas son carnet de notes.**
> Une entrée n'existe que si une **règle automatisée** et un **test** la portent. Un registre
> qui décrit sans automatiser est un recueil de bonnes intentions : le harnais vérifie donc
> que chaque entrée nomme un test qui existe **et qui l'exerce réellement**.

⚠️ **LE REGISTRE NE CONTIENT JAMAIS LA RÉPONSE PARTICULIÈRE.**
« Un lot peut commencer au bas d'une page, sa ligne `Locataire:` étant imprimée sur la
suivante » est un **apprentissage**. « Le lot 00170-004 est occupé par BALDYGA Axel » est une
**réponse**, et n'a rien à faire ici : elle ferait passer le prochain examen sans rien
comprendre. Les noms, comptes, pages et fichiers de corpus vivent dans les commentaires du
code et dans les tests — jamais dans une condition, jamais dans ce registre.

⚠️ **UNE PORTÉE EST OBLIGATOIRE, ET « AGENCE » N'EN EST PAS UNE.**
Une règle propre à une agence, un compte, une page ou une personne est **interdite**, sauf
différence documentaire démontrée — et alors sa portée est `VARIANTE`, pas « le cas de untel ».

| Portée | Ce qu'elle engage |
|---|---|
| `UNIVERSELLE` | vraie de tout CRG, quel que soit l'éditeur |
| `ÉDITEUR` | vraie de tout document d'un logiciel — ICS ou SPI |
| `VARIANTE` | vraie d'un gabarit d'agence, parce que son document diffère réellement |
| `MÉTIER` | une règle de gestion, arbitrée par Emmanuel |
| `OCR` | un défaut de reconnaissance, jamais une règle de lecture |

## Le gabarit d'une entrée

```
ID              APP-0001
phénomène       une phrase, du point de vue du document
preuve          où on l'a vu — corpus, pages (dans le registre : la localisation, pas la valeur)
abstraction     ce qu'on en retient une fois le cas oublié
portée          UNIVERSELLE | ÉDITEUR | VARIANTE | MÉTIER | OCR
règle           la décision automatisée, en une phrase
composant       le fichier et la fonction qui la portent
tests           les tests qui la prouvent — sur FIXTURE SYNTHÉTIQUE
corpus          où elle a été rejouée sans régression
limites         ce qu'elle ne couvre pas
commit          le hash
```

---

## APP-0001 · « pdftotext » désigne deux programmes différents

| | |
|---|---|
| **phénomène** | Deux binaires portent ce nom sur un même poste et ne rendent pas la même page : l'un place l'en-tête sur une ligne, l'autre le descend de deux, et l'un connaît `-table` que l'autre ignore. |
| **preuve** | 02/09/2026 — la même analyse lancée depuis la page et depuis le harnais rendait deux empreintes. |
| **abstraction** | L'identité d'un outil externe fait partie du résultat. Une lecture n'est reproductible que si le binaire l'est. |
| **portée** | `UNIVERSELLE` |
| **règle** | Le binaire est résolu une fois, son produit et sa version remontent jusqu'à l'écran, et `CRG_PDFTOTEXT` permet de l'imposer. Un lecteur qui n'est pas celui du corpus certifié déclenche « LECTURE DÉGRADÉE ». |
| **composant** | `crg_integration_phase0.pdftotext_exe()` |
| **tests** | `crgi_robustesse` — « les phases 0 et 3 lisent avec LE MÊME binaire » |
| **corpus** | VIENNE, EMERY T1/T2, LYON, CHAPONOST |
| **limites** | Ne dit rien de la qualité d'un rendu, seulement de son identité. |
| **commit** | `45f5ca4` |

## APP-0002 · Une capacité ne se déduit pas d'un nom de produit

| | |
|---|---|
| **phénomène** | `-table` est une option **Xpdf**, absente de poppler. La déduire du nom du produit a fait lancer une option inexistante, échouer, et retomber en silence sur `-layout`. |
| **preuve** | 03/09/2026 — la liste d'options imprimée par chaque binaire. |
| **abstraction** | **On demande à un outil ce qu'il sait faire ; on ne le déduit jamais de ce qu'il s'appelle.** Et un test qui encode la croyance du développeur valide la croyance, pas le monde. |
| **portée** | `UNIVERSELLE` |
| **règle** | La capacité est probée sur la liste d'options que le binaire imprime lui-même. |
| **composant** | `crg_integration_phase0.pdftotext_exe()` |
| **tests** | `crgi_robustesse` — « la capacité `-table` se PROBE, elle ne se déduit d'aucun nom de produit » |
| **corpus** | tous |
| **limites** | Le mode `-table` reste **inactif** avec poppler : à arbitrer. |
| **commit** | `bda6e82` |

## APP-0003 · L'enseigne se lit avant la structure

| | |
|---|---|
| **phénomène** | Deux agences d'un même logiciel impriment le même gabarit. Tester la structure d'abord ranga 224 CRG certifiés dans la mauvaise famille. |
| **preuve** | 02/09/2026 — le routeur certifié et l'intégrateur donnaient deux réponses opposées sur les deux mêmes documents. |
| **abstraction** | Le signe **spécifique** (l'enseigne) précède le signe **commun** (la structure). Et une agence n'est pas un format : l'éditeur l'est. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crg_format.famille_du_texte` est l'autorité unique, partagée par le routeur et l'intégrateur ; elle interroge l'enseigne, puis à défaut la structure. |
| **composant** | `crg_format.py` |
| **tests** | `crgi_robustesse` — « tout format reconnu par le RÉFÉRENTIEL l'est aussi par l'INTÉGRATEUR » · « la structure ne l'emporte jamais sur l'enseigne » |
| **corpus** | LYON, EMERY, VIENNE, CHAPONOST |
| **limites** | Un document sans enseigne lisible rend `inconnu` — et c'est une réponse. |
| **commit** | `45f5ca4` |

## APP-0004 · Un total n'est pas un mouvement

| | |
|---|---|
| **phénomène** | Une ligne de total imprimée en fin d'immeuble n'a pas de section à elle : elle hérite de celle restée ouverte au-dessus, et se retrouve comptée comme une charge ou un frais. |
| **preuve** | 03/09/2026 — 819 lignes sur un dépôt, dont 511 additionnées aux mouvements élémentaires qu'elles récapitulent. |
| **abstraction** | **`AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE`**, quelle que soit la section où l'agrégat tombe. Conservé pour preuve, jamais sommé. |
| **portée** | `ÉDITEUR` (SPI) |
| **règle** | Un libellé de total d'immeuble rend `AGREGAT (NON ADDITIONNABLE)` avant toute règle de section. |
| **composant** | `crg_integration_phase4.categoriser()` |
| **tests** | `crgi_robustesse` — « un Total de l'immeuble est un AGRÉGAT, dans quelque section qu'il tombe » · « n'alimente jamais P5, P7 ni P8 » |
| **corpus** | CHAPONOST ; sans effet sur VIENNE, qui n'en imprime aucune |
| **limites** | Ne vérifie pas que le total égale la somme recomposée — le rapport observé est de 3, et reste une question ouverte. |
| **commit** | `30d4570` |

## APP-0005 · Les libellés de section s'ajoutent, ils ne se remplacent pas

| | |
|---|---|
| **phénomène** | Chaque comptable nomme ses sections comme il l'entend : « Charges locatives » ici, « Charges Propriétaire » ailleurs, « Autres Honoraires » chez un troisième. |
| **preuve** | 03/09/2026 — cinq intitulés inconnus sur un dépôt, dont deux déjà couverts par des règles existantes. |
| **abstraction** | Une reconnaissance de vocabulaire est une **table ordonnée qui s'allonge**. Les entrées certifiées restent en tête, intouchables ; toute nouveauté s'ajoute en fin, où elle ne peut rien recouvrir. |
| **portée** | `UNIVERSELLE` |
| **règle** | `LIBELLES_SECTION` — premier motif qui accepte gagne ; ajout en fin de table, jamais réécriture. |
| **composant** | `crg_integration_phase4.LIBELLES_SECTION` |
| **tests** | `crgi_robustesse` — « la table des libellés de section s'AJOUTE, elle ne se remplace pas » |
| **corpus** | VIENNE, CHAPONOST |
| **limites** | Un intitulé inconnu reste inconnu : ses lignes sont isolées, jamais interprétées. |
| **commit** | `30d4570` |

## APP-0006 · Ce qui n'est démontré que dans un sens ne vaut que dans ce sens

| | |
|---|---|
| **phénomène** | Une section dont les débits sont compris porte parfois quelques crédits d'une autre nature. |
| **preuve** | 03/09/2026 — 4 débits de prime et 2 crédits, sous un même intitulé d'assurance. |
| **abstraction** | Une section peut n'avoir **qu'une seule colonne démontrée**. Le reste attend un arbitrage plutôt que d'hériter de la nature du sens connu. |
| **portée** | `UNIVERSELLE` |
| **règle** | `COLONNES_DEMONTREES` : hors des colonnes établies, la nature est `INDETERMINABLE` et le motif dit pourquoi. |
| **composant** | `crg_integration_phase4.COLONNES_DEMONTREES` |
| **tests** | `crgi_robustesse` — « le débit est une prime, le crédit attend un arbitrage » |
| **corpus** | CHAPONOST |
| **limites** | — |
| **commit** | `30d4570` |

## APP-0007 · Une page qui se termine ne fait pas disparaître un occupant

| | |
|---|---|
| **phénomène** | Quand le bloc d'un lot commence au bas d'une page, sa ligne `Locataire:` est imprimée sur la suivante, sous l'en-tête « Suite ». La continuation ne complétait que le solde. |
| **preuve** | 03/09/2026 — deux lots sur un corpus, un sur l'autre, dans deux CRG chacun. |
| **abstraction** | Une continuation de page porte de l'**information neuve**, pas seulement la fin d'un tableau. Et un objet démontré par une phase aval ne peut pas être inconnu de l'amont. |
| **portée** | `UNIVERSELLE` |
| **règle** | La continuation complète l'observation ouverte — **seulement si elle est vide**. Une observation nommée n'est jamais réécrite par sa suite. |
| **composant** | `crg_integration_phase3.observer()` |
| **tests** | `crgi_coherence` — « aucun lot ne perd son occupant parce qu'une page se termine » (contrôle **générique**, non nominatif) |
| **corpus** | VIENNE, CHAPONOST |
| **limites** | Si la continuation porte **plusieurs** occupants au-delà du premier, ils ne sont pas encore repris. |
| **commit** | `bda6e82` |

## APP-0008 · Un code de compte n'est jamais global

| | |
|---|---|
| **phénomène** | Un même code de compte existe dans deux systèmes et désigne deux mandants différents. |
| **preuve** | 03/09/2026 — trois codes partagés entre deux systèmes dans MBI. |
| **abstraction** | L'identité d'un compte est le couple **(code, système)**. Chercher par le code seul rattache silencieusement un document au mauvais mandant, et le faux rapprochement se propage à tout ce que les phases suivantes construisent dessus. |
| **portée** | `UNIVERSELLE` |
| **règle** | La confrontation filtre les candidats par le système que la famille du document désigne, et **nomme l'homonyme** au lieu de le taire. |
| **composant** | `crg_integration.php` — `CRGI_SYSTEME_DU_FORMAT`, `crgi_phase1()` |
| **tests** | `crgi_coherence` — « un code de compte ne se rapproche jamais hors de son système » |
| **corpus** | VIENNE (sans effet), CHAPONOST |
| **limites** | — |
| **commit** | `45f5ca4` |

## APP-0009 · Une annulation se déduit du schéma, jamais d'une liste

| | |
|---|---|
| **phénomène** | Une liste de tables écrite à la main ne survit pas à la migration suivante. |
| **preuve** | 02/09/2026 — 8 408 lignes d'analyse ont survécu à une annulation affichée comme complète. |
| **abstraction** | Un périmètre qui s'agrandit ne doit pas être énuméré. On interroge la structure : toute table portant la clé de l'objet appartient à l'objet. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crgi_tables_de_staging()` déduit du schéma la liste des tables à purger. |
| **composant** | `crg_integration.php` |
| **tests** | `crgi_annulation` — 5 contrôles, dont « le balayage se déduit du schéma, pas d'une liste écrite à la main » |
| **corpus** | tous |
| **limites** | — |
| **commit** | `948f149` |

---

## APP-0010 · Une position de capture n'est pas une position d'impression

| | |
|---|---|
| **phénomène** | Un motif dont la classe de caractères admet l'espace commence sa capture le plus à gauche possible : le texte capturé reste juste, mais sa **colonne** est celle du premier espace avalé, pas celle de la première lettre imprimée. |
| **preuve** | 03/09/2026 — un lecteur alignant un bloc adresse sous un repère de mise en page rejetait **tous** les noms d'un corpus entier, l'écart mesuré étant celui des espaces, pas celui des colonnes. |
| **abstraction** | Quand une position sert de repère géométrique, elle doit être celle du **texte visible**. Une capture qui commence par une espace est un repère faux qui a l'air vrai — et le défaut est invisible sous un extracteur plus avare en espaces. |
| **portée** | `UNIVERSELLE` |
| **règle** | L'ancrage se calcule sur la première lettre du groupe (`m.start(1)` recalé), et un nom de lieu ne peut pas commencer par une espace. |
| **composant** | `crg_integration_phase0.py` — `RE_VILLE_DATE`, `_proprietaire_lyon()` |
| **tests** | `crgi_robustesse` — « la ville ancre la colonne, quel que soit le nombre d'espaces devant elle », « un nom de ville ne commence jamais par une espace » ; épreuve associée : « l'ancrage pris au début de la CAPTURE » |
| **corpus** | ICS, sous les deux extracteurs disponibles |
| **limites** | Ne couvre pas les gabarits où le bloc adresse est séparé du repère par plus de cinq lignes : l'ancrage y est juste, mais la fenêtre de recherche s'arrête avant. |
| **commit** | `—` |

---

## APP-0011 · Un lecteur est un composant du résultat, pas un détail d'installation

| | |
|---|---|
| **phénomène** | Deux programmes différents répondent au même nom d'exécutable, avec des options et des sorties différentes. Choisir « le premier venu du PATH » fait dépendre le résultat métier de l'environnement. |
| **preuve** | 02/09 → 03/09/2026 — la même analyse produisait deux empreintes selon le point de lancement ; un mode inconnu du binaire présent provoquait un repli silencieux vers un autre mode. |
| **abstraction** | L'outil de lecture se **déclare** (produit, version, mode, capacités) et se **vérifie** au démarrage. Son absence est une panne, jamais une occasion de substituer. Une capacité se probe auprès de l'outil ; elle ne se déduit d'aucun nom. |
| **portée** | `UNIVERSELLE` |
| **règle** | `CRG_LECTEUR_CONTRAT` + `lecteur_resolu()` : produit et mode exigés, sinon `LecteurIndisponible`. Plus aucun repli implicite vers un autre extracteur. |
| **composant** | `crg_integration_phase0.py` |
| **tests** | `crgi_robustesse` — « le lecteur exigé est absent : panne explicite », « un mode que le binaire ne connaît pas est une panne », « le contrat de lecture est déclaré » ; épreuve associée : « le repli muet vers un autre lecteur » |
| **corpus** | tous |
| **limites** | Le contrat épingle un produit ; il ne garantit pas qu'une future version du même produit lira à l'identique. Renseigner `version` pour l'exiger. |
| **commit** | `—` |

---

## APP-0012 · Un conflit d'identité détecté sans question est une perte, pas un contrôle

| | |
|---|---|
| **phénomène** | Une même clé d'identité — démontrée par le document — porte deux valeurs différentes dans un même dépôt. Le moteur ne peut pas trancher : une ressemblance ne prouve pas une identité, et une différence ne prouve pas deux objets. |
| **preuve** | 03/09/2026 — un contrôle de cohérence virait au rouge sur un phénomène que rien ne permettait de résoudre, et l'écran d'arbitrage ne posait aucune question correspondante. Le moteur savait qu'il ne savait pas ; l'humain n'avait nulle part où trancher. |
| **abstraction** | `CONFLITS DÉTECTÉS = RÉSOLUS AVEC PREUVE + ARBITRABLES`. Un doute que le moteur ne peut pas lever doit **toujours** ouvrir une question portant les deux lectures et leurs preuves respectives. Un rouge permanent que personne ne peut résoudre n'est pas un garde-fou : c'est un bruit qu'on finit par ignorer. Corollaire : une mesure de proximité a le droit d'**ouvrir** la question, jamais de la fermer. |
| **portée** | `UNIVERSELLE` |
| **règle** | `CRGI_IDENTITES` déclare les populations d'identité ; `crgi_conflits_identite()` les balaie génériquement et rend chaque lecture avec son CRG et sa page ; `crgi_arbitrages()` en fait une famille de questions à trois issues. |
| **composant** | `crg_integration.php` |
| **tests** | `crgi_file_arbitrage` — « un conflit d'identité fabriqué devient une question, jamais une fusion » (**fixture synthétique**) ; `crgi_coherence` — « un conflit détecté mais invisible », « aucune identité n'est fusionnée sur une ressemblance » |
| **corpus** | rejoué sans régression sur les deux corpus en base |
| **limites** | Ne détecte que les conflits dont la clé est **démontrée par le document**. Deux objets que rien ne relie ne font pas conflit — et c'est voulu : les rapprocher serait une identité par approximation. |
| **commit** | `—` |

---

## APP-0013 · Une règle structurelle ne dépend jamais du nombre d'espaces produit par l'extracteur

| | |
|---|---|
| **phénomène** | Le texte extrait d'un PDF ne conserve pas un espacement stable : selon l'outil et le mode, une même colonne est serrée ou aérée, à l'extérieur **comme à l'intérieur** d'un champ. Une règle qui compte les espaces lit donc autre chose selon l'outil. |
| **preuve** | 03/09/2026 — mesuré sur quatre corpus, à contenu métier rigoureusement identique : un champ d'identité découpé sur « deux espaces ou plus » perdait son second mot dès que l'extracteur aérait la ligne ; un champ de lieu borné de la même façon perdait son complément, ou l'objet entier. |
| **abstraction** | La convention « deux espaces séparent deux colonnes » dit **où un champ commence**, jamais **où il finit** : la fin se lit dans le vocabulaire du document. Une tabulation vaut huit colonnes et non une. Et une abscisse ne se compare qu'à une **marge mesurée sur la même page**, jamais entre deux lignes rendues par des outils différents. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crg_texte` : `normaliser`, `en_colonnes`, `RE_DEBUT_CELLULE`, `depuis_la_colonne`, `sans_queue_tableau`, `ville_de`, `marge_gauche` + `ECART_COLONNE_DROITE`. Le texte brut, sa page et sa provenance restent la preuve : la normalisation sert à reconnaître, jamais à stocker. |
| **composant** | `crg_texte.py`, `crg_integration_phase0.py`, `crg_integration_lots.py` |
| **tests** | `crgi_robustesse` — « espaces multiples », « tabulations », « alignement différent », « immeuble SPI », « une ville n'avale jamais un débris de tableau », « deux espacements du MÊME contenu rendent le MÊME objet métier » (**fixtures synthétiques**) ; épreuves associées : « le découpage du nom sur deux espaces ou plus », « la ville en lettres seules, bornée par deux espaces » |
| **corpus** | quatre corpus, deux extracteurs, deux modes — écarts ramenés à trois artefacts d'extracteur documentés |
| **limites** | Ne corrige pas un extracteur qui insère une espace **au milieu d'un mot** : c'est une altération de la source, pas une question de mise en page. |
| **commit** | `—` |

---

## APP-0014 · Un bloc documentaire se parcourt jusqu'à une frontière, pas sur une fenêtre fixe

| | |
|---|---|
| **phénomène** | Le bloc utile se trouve à quatre lignes du repère sur un gabarit, à six sur un autre, et l'intervalle contient des lignes d'une **autre colonne** qu'il faut traverser sans s'y arrêter. |
| **preuve** | 03/09/2026 — une fenêtre de cinq lignes lisait un gabarit et rendait **zéro sur un corpus entier** pour l'autre. Le nombre cinq n'était pas une propriété du document : c'était celui qui marchait sur le premier gabarit examiné. |
| **abstraction** | `ARRÊTER` et `IGNORER` ne sont pas la même chose. On parcourt jusqu'à une **frontière démontrée** — la formule d'appel qui ouvre le corps, un champ d'en-tête, une ligne de tableau — en ignorant ce qui n'appartient pas au bloc. Un plafond de sécurité borne le parcours ; il n'est pas la règle, il empêche une page dégradée de faire remonter n'importe quoi. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crg_texte.bloc_borne(lignes, depart, frontiere, plafond)` ; les lecteurs déclarent leur frontière. |
| **composant** | `crg_texte.py`, `crg_integration_phase0.py` |
| **tests** | `crgi_robustesse` — « bloc utile décalé », « ligne parasite intermédiaire », « frontière structurelle », « absence réelle » ; épreuve associée : « la fenêtre fixe de cinq lignes » |
| **corpus** | les deux familles ICS, deux extracteurs, deux modes |
| **limites** | Suppose que le bloc est **aligné** : un bloc éclaté en trois fragments à trois abscisses reste arbitrable, pas devinable. |
| **commit** | `—` |

---

## APP-0015 · Le signal documentaire est plus large que la règle de lecture

| | |
|---|---|
| **phénomène** | Tant que « ce que la page annonce » et « ce que le moteur sait lire » sont le même motif, une ligne inanalysable **n'existe pas** : aucun compteur ne bouge, aucun arbitrage ne s'ouvre, et tous les contrôles de couverture restent verts. |
| **preuve** | 03/09/2026 — 23 immeubles annoncés par un seul document n'ont jamais été lus. Le dépôt a été intégré, contrôlé et validé sans qu'un seul indicateur ne bronche : les contrôles comparaient des populations qui, elles, n'avaient jamais reçu ces objets. |
| **abstraction** | Toute famille structurante a besoin de **deux** motifs : un motif de SIGNAL, volontairement large, qui dit qu'une ligne annonce l'objet ; et le motif de LECTURE, qui dit qu'on sait le construire. L'invariant est `SIGNAUX = OBJETS + NON TRANSFORMÉS`, et le troisième terme doit rester visible. Un contrôle bâti sur le seul motif de lecture est tautologique — vert par construction. |
| **portée** | `UNIVERSELLE` |
| **règle** | `RE_SIGNAL_IMMEUBLE` + `couverture_immeubles()` ; suite de harnais dédiée, exécutée sur les corpus réels **dans les deux modes**. |
| **composant** | `crg_integration_lots.py`, `tests_crg/crgi_couverture_amont.py` |
| **tests** | `crgi_robustesse` — « couverture amont : un signal reconnu n'est jamais perdu sans trace » (fixture synthétique, y compris un signal volontairement illisible) ; `crgi_couverture_amont` — 4 contrôles sur corpus réels |
| **corpus** | les deux corpus SPI, deux modes : 707 et 456 signaux, **0 non transformé** |
| **limites** | Un seul motif de signal existe à ce jour, pour les immeubles. Les autres familles structurantes restent à outiller de la même façon. |
| **commit** | `—` |

---

## APP-0016 · Un mode de lecture fait partie du résultat, au même titre que l'outil

| | |
|---|---|
| **phénomène** | Une phase demandait un mode d'extraction particulier et, quand le binaire ne le proposait pas, lisait **quand même** — dans un autre mode, sans le dire. |
| **preuve** | 03/09/2026 — huit immeubles et un occupant présents dans un mode, absents dans l'autre, sur le même document. Aucun contrôle aval ne pouvait le voir : la perte a lieu avant que les populations n'existent. |
| **abstraction** | Le mode se déclare avec le lecteur et se vérifie au démarrage. Son absence est une **panne**, jamais une occasion de lire autrement. Corollaire : ne pas garder l'ancienne fonction sous un nom de repli — une issue de secours conservée finit toujours par être reprise. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crg_integration_phase3.lire_pages()` lève `LecteurIndisponible` si `-table` n'est pas proposé ; l'import du lecteur `-layout` comme repli est supprimé. |
| **composant** | `crg_integration_phase3.py` |
| **tests** | `crgi_robustesse` — « un mode que le binaire ne connaît pas est une panne, pas un repli », « le lecteur exigé est absent : panne explicite » ; épreuve associée : « le repli muet vers un autre lecteur » |
| **corpus** | tous |
| **limites** | Le contrat n'exprime qu'un mode par phase ; une phase qui aurait besoin de deux lectures devrait les déclarer toutes deux. |
| **commit** | `—` |

---

## Ce que le registre ne contient pas, et pourquoi

Seize apprentissages, et **aucun ne nomme un lot, un occupant, un compte ou un fichier**. C'est
la condition pour que l'examen mesure quelque chose : si une règle a besoin du cas pour
fonctionner, elle n'a rien appris — elle a mémorisé. Chaque test ci-dessus s'exécute sur une
**fixture synthétique** (un en-tête, un bloc, une ligne fabriqués) précisément pour que le
souvenir du document ne puisse pas lui souffler la réponse.
