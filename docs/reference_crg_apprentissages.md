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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `1ba597f` |

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
| **commit d’introduction** | `3c2101c` |

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
| **commit d’introduction** | `3c2101c` |

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
| **commit d’introduction** | `3c2101c` |

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
| **commit d’introduction** | `a394f8a` |

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
| **commit d’introduction** | `a394f8a` |

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
| **commit d’introduction** | `a394f8a` |

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
| **commit d’introduction** | `a394f8a` |

---

## APP-0017 · Une enveloppe contient plusieurs documents, et chacun a des pages de suite

| | |
|---|---|
| **phénomène** | Un dépôt réel ne porte pas que des comptes rendus : l'enveloppe contient aussi l'appel de fonds de la copropriété et les factures des prestataires. Ces documents s'intercalent — parfois **au milieu** d'un compte rendu, qui reprend ensuite. |
| **preuve** | 03/09/2026, première passe d'apprentissage sur un corpus ICS : 27 pages ressortaient « aucun signal de CRG », dont quatre étiquetées « avant le premier en-tête » alors qu'elles venaient **après**. Un dépôt parfaitement lu ressemblait à un découpage raté. |
| **abstraction** | Trois règles, et elles se tiennent : ❶ un document joint se reconnaît à son **titre en tête de page**, dans une table déclarée et additive — jamais à sa casse, car « Appel de Fonds » et « APPEL DE FONDS » sont le même titre ; ❷ un document joint **suspend** le compte rendu, il ne le clôt pas : le CRG reprend sur un de ses propres bandeaux ; ❸ un document joint **a lui aussi des pages de suite** — et le signal doit dire quand elles lui sont rattachées par contiguïté plutôt que par une preuve imprimée. |
| **portée** | `UNIVERSELLE` |
| **règle** | `DOCUMENTS_JOINTS` + `SECTIONS_CRG` (tables additives), `_titre_de_document()`, et l'état `hors_crg` qui suspend au lieu de fermer. Une page quasi vide appartient au document ouvert. |
| **composant** | `crg_integration_phase0.py` |
| **tests** | COUVERT INDIRECTEMENT — `crgi_multicorpus.py` éprouve la segmentation par document et les pages de suite ; `crgi_couverture_amont.py` exige `signaux = objets + non transformés` sur les corpus entiers, ce qu'une enveloppe mal découpée romprait. |
| **corpus** | un corpus ICS complet : 721 pages, 681 rattachées, **40 nommées, 0 sans nom** |
| **limites** | ⚠️ Cet apprentissage n'est **pas encore porté par un test** : il est vérifié sur corpus, pas sur fixture. Tant que ce test n'existe pas, l'entrée est incomplète au regard de la règle du registre. |
| **commit d’introduction** | `97ddf80` |

---

## APP-0018 · Un contrôle ne se cale pas sur « le dernier dépôt »

| | |
|---|---|
| **phénomène** | Huit contrôles prenaient pour référence l'import le plus RÉCENT. Tant qu'un seul dépôt vivait à la fois, cela revenait au même que « le dépôt analysé ». |
| **preuve** | 03/09/2026 — la première passe d'apprentissage a ouvert un nouvel import, analysé jusqu'à la phase 0 et pas au-delà. Huit contrôles ont viré au rouge en cherchant des mouvements, des arbitrages et des sceaux dans un dépôt qui n'en avait pas encore. Le moteur n'avait rien fait ; l'instrument regardait ailleurs. |
| **abstraction** | Un instrument de mesure a ses propres hypothèses, et elles vieillissent comme les autres. « Le dernier » est une hypothèse sur l'usage, pas une propriété du sujet : ce qu'un contrôle veut, c'est le dépôt le plus **avancé**, et cela se mesure — nombre de phases validées. Corollaire éprouvé le même jour : une fixture ne force jamais un identifiant dans un espace `AUTO_INCREMENT` partagé. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crgi_import_reference()` classe par nombre de phases validées puis par récence ; les six suites qui suivaient l'import courant l'emploient. |
| **composant** | `crg_integration.php`, suites `tests_crg/*.php` |
| **tests** | le harnais lui-même : il reste vert alors qu'un dépôt à peine analysé existe en base |
| **corpus** | tous |
| **limites** | À égalité de phases validées, on retombe sur la récence — ce qui reste une convention. |
| **commit d’introduction** | `97ddf80` |

---

## APP-0019 · Une phase d'intégration lit par le moteur certifié de la famille, pas par le sien

| | |
|---|---|
| **phénomène** | Les phases 2, 3 et 4 ne connaissaient qu'une seule grammaire documentaire. Devant un document d'une autre famille, elles ne se trompaient pas : elles ne voyaient **rien**. |
| **preuve** | 03/09/2026, première passe d'apprentissage sur un corpus complet d'une famille jamais intégrée : la phase 0 identifiait 235 comptes rendus, 226 comptes mandants et 199 propriétaires, puis les phases suivantes rendaient **0 immeuble, 0 lot, 0 occupation** et 106 mouvements. L'empreinte de la phase 3 était celle de la **chaîne vide**. Aucune alerte : un zéro n'est pas une erreur. |
| **abstraction** | `ÉDITEUR → MOTEUR → VARIANTE` vaut pour l'INTÉGRATION comme pour la certification. Une phase ne possède pas de grammaire propre : elle route vers le moteur certifié de la famille, et se contente d'en traduire la sortie dans son contrat. Recopier les motifs du lecteur dans la phase créerait une seconde autorité — le défaut qui a fait voir 98 lots à une phase et 124 à une autre. |
| **portée** | `UNIVERSELLE` |
| **règle** | `crgi_commande_lecture()` route sur `crgi_crg.format` ; `crg_integration_ics.py` traduit la sortie de `crg_ics_core` vers les contrats des phases 2, 3 et 4. Aucune règle de lecture n'y est réécrite. |
| **composant** | `crg_integration.php`, `crg_integration_ics.py` |
| **tests** | COUVERT INDIRECTEMENT — `crgi_multicorpus.py` vérifie que chaque famille est lue par son moteur certifié, et `crgi_coherence.php` que les trois phases comptent la même population : une lecture par le mauvais moteur romprait ces égalités. |
| **corpus** | corpus ICS complet : immeubles 0 → 236, lots 0 → 396, occupations 0 → 396, mouvements 106 → 3 221 |
| **limites** | ⚠️ Pas encore porté par un test sur fixture. ⚠️ Et la **qualification** ICS n'est pas faite : les 3 221 montants sortent `INDETERMINABLE` et remontent en arbitrage — c'est voulu (`NE JAMAIS DÉDUIRE UNE NATURE D'UN LIBELLÉ`), mais cela laisse 143 groupes à trancher, dont **5 couvrent 87 %** des montants. |
| **commit d’introduction** | `652804e` |

---

## APP-0020 · Une clé de regroupement d'arbitrage doit avoir un sens

| | |
|---|---|
| **phénomène** | Un lecteur rattache une ligne d'argent à l'intitulé qui la précède. Cet intitulé n'est pas toujours une section : c'est parfois une date de règlement. |
| **preuve** | 03/09/2026 — la file d'arbitrage demandait la nature de « la section *Du 15.03.2026* », puis de « *Du 15.01.2026* », puis de trois autres dates : cinq questions vides pour un seul et même phénomène, et 143 groupes là où il y en avait 138 de réels. |
| **abstraction** | Un regroupement ne vaut que par sa clé. Quand l'intitulé qui sert de clé n'a manifestement pas la forme d'une section — une date, une référence de pièce, rien du tout —, on ne le propage pas : on rassemble ces lignes sous un **aveu unique** (« section non imprimée »). Fragmenter une question en cinq copies datées coûte cinq décisions humaines pour une seule connaissance. |
| **portée** | `UNIVERSELLE` |
| **règle** | `_section_lisible()` : un intitulé vide ou de forme date devient « (section non imprimée) ». |
| **composant** | `crg_integration_ics.py` |
| **tests** | COUVERT INDIRECTEMENT — `crgi_multicorpus.py` vérifie que chaque famille est lue par son moteur certifié, et `crgi_coherence.php` que les trois phases comptent la même population : une lecture par le mauvais moteur romprait ces égalités. |
| **corpus** | corpus ICS : 149 → 143 groupes, 491 lignes rassemblées sous une seule question au lieu de six |
| **limites** | ⚠️ Pas encore porté par un test sur fixture. Ne reconnaît que la forme date ; d'autres intitulés sans valeur de section restent à observer. |
| **commit d’introduction** | `652804e` |

---

## APP-0021 · La prudence qui fait re-décider une doctrine déjà écrite n'est pas de la prudence

| | |
|---|---|
| **phénomène** | Devant le vocabulaire d'un éditeur nouveau, tout marquer `INDETERMINABLE` paraît la position sûre. Elle ne l'est pas quand une partie de ce vocabulaire est **structurelle** et déjà tranchée. |
| **preuve** | 04/09/2026 — 1 775 lignes envoyées en arbitrage alors que leur nature était certifiée depuis P5A : le tableau d'appels d'un lot porte ses colonnes en en-tête, et « Loyers » y est un loyer appelé, « Provisions » une provision appelée au locataire. Emmanuel : « 3 886 écritures à valider ? c'est moi qui dois tout faire ? ». |
| **abstraction** | `NE JAMAIS DÉDUIRE UNE NATURE D'UN LIBELLÉ` interdit de lire le **texte** d'une ligne. La **colonne** d'un tableau est l'inverse : une structure imprimée, déjà arbitrée. Confondre les deux transforme une doctrine protectrice en machine à produire du travail humain. Avant de poser une question, vérifier qu'elle n'a pas déjà sa réponse ailleurs dans le référentiel. |
| **portée** | `UNIVERSELLE` |
| **règle** | `NATURE_DE_COLONNE` dans le pont ICS reprend la table certifiée `BLOC_LOT` de la phase 4. « Divers » reste indéterminable — `P5A-APPEL-02` : son intitulé ne nomme rien. |
| **composant** | `crg_integration_ics.py` |
| **tests** | NON TESTABLE PAR NATURE — porte sur la conduite du travail (ne pas re-décider une doctrine écrite), pas sur un comportement du code. Maîtrise : relecture du référentiel avant toute règle nouvelle. |
| **corpus** | corpus ICS : mouvements qualifiés 0 → 1 778 (49,8 %) |
| **limites** | Ne vaut que pour les colonnes dont l'en-tête annonce une nature. |
| **commit d’introduction** | `6d31d1f` |

---

## APP-0022 · Une même preuve ne peut pas recevoir deux traitements selon son voisinage

| | |
|---|---|
| **phénomène** | Deux branches successives du même code traitaient différemment une preuve identique : un lot vu à **plusieurs** périodes voyait sa première période acceptée d'office — « l'occupant y est déjà en place » ; le même lot vu à **une seule** période devenait un arbitrage. |
| **preuve** | 04/09/2026 — **254 questions** sur un dépôt, **302** sur un autre : la plus grosse famille d'arbitrage du projet, pour une information que le document donne en clair. Le nombre de périodes qui SUIVENT ne change rien à ce que la première énonce. |
| **abstraction** | Ce qu'un document démontre ne dépend pas de ce qui l'entoure. Quand deux situations portent la même preuve, elles reçoivent le même traitement — sinon l'une des deux branches est fausse, et c'est presque toujours la plus bavarde. Corollaire : `ABSENCE ≠ DÉPART DÉMONTRÉ` interdit de conclure à une entrée ou à un départ ; il n'interdit pas d'écrire l'occupation **à sa date d'arrêté**, qui est imprimée. Ne rien écrire n'est pas plus prudent que d'écrire ce qui est lu. |
| **portée** | `MÉTIER` |
| **règle** | Une occupation vue à une seule période est écrite `IDENTIQUE`, avec un motif qui dit explicitement que la chronologie reste indéterminée. |
| **composant** | `crg_integration.php` — `crgi_qualifier_occupation()` |
| **tests** | COUVERT — `crgi_coherence.php` : « une même preuve reçoit le même traitement, quel que soit son voisinage ». Deux oracles ont dû être abandonnés avant celui-ci : comparer les STATUTS de deux populations accusait le moteur à tort, car un lot vu une seule fois peut légitimement être mis en attente pour une AUTRE preuve — son compte continue d'être rendu sans lui. Le contrôle porte donc sur le MOTIF : une observation reconnue première est écrite `IDENTIQUE`, jamais mise en attente pour cette raison-là. |
| **corpus** | trois dépôts : occupations en arbitrage 254 → 0, 302 → 6, 5 → 5 |
| **limites** | La chronologie inter-périodes reste indéterminée, et doit le rester : cette règle écrit une occupation, jamais une entrée ni un départ. |
| **commit d’introduction** | `6d31d1f` |

---

## APP-0023 · Un indicateur qui compte une question groupée comme des pertes se fait ignorer

| | |
|---|---|
| **phénomène** | Le KPI des pertes silencieuses marquait chaque objet en attente absent de la file. Une question qui en couvre plusieurs ne référence qu'une cible : tous les autres membres du groupe passaient pour perdus. |
| **preuve** | 04/09/2026 — **89 pertes silencieuses** annoncées sur un dépôt où les 126 objets étaient tous posés, sous 37 questions. |
| **abstraction** | Un regroupement doit déclarer sa **couverture**, sinon tout indicateur qui compte à l'unité le lira comme une perte. Et un indicateur central qui crie au loup se fait ignorer aussi sûrement qu'un indicateur muet — c'est le pire des deux, parce qu'on croit le surveiller. |
| **portée** | `UNIVERSELLE` |
| **règle** | Chaque ligne d'arbitrage groupée porte `couvre` — la liste des objets qu'elle représente ; le pilotage les marque tous visibles. |
| **composant** | `crg_integration.php`, `crgi_pilotage.php` |
| **tests** | COUVERT — `crgi_file_arbitrage.php` : « une perte silencieuse est un objet SANS question, pas un objet en attente », et `crgi_epreuve_du_compteur.php` éprouve le compteur par injection. |
| **corpus** | trois dépôts : **0 perte silencieuse** partout |
| **limites** | Seule la famille des immeubles déclare aujourd'hui sa couverture ; les autres ne groupent pas encore. |
| **commit d’introduction** | `6d31d1f` |

---

## APP-0024 · Une file est une suite de QUESTIONS, pas une suite de lignes

| | |
|---|---|
| **phénomène** | La file affichait une entrée par ligne à décider. Quand trois cents lignes posent rigoureusement la même question, elle en affichait trois cents. |
| **preuve** | 04/09/2026 — **397 entrées** sur un dépôt, dont 337 demandaient « quelle est la nature des montants de cette colonne ? ». Emmanuel : « les arbitrages doivent se limiter à une trentaine environ à chaque fois ». |
| **abstraction** | Une question répétée trois cents fois n'est pas trois cents questions : c'est une question et un défaut de présentation. On replie sur le **phénomène** — ce qui a la même réponse —, la représentante gardant sa preuve pour que « voir dans le CRG » reste vrai. **Mais on ne replie que ce qui a la même réponse** : un homonyme d'immeuble et un conflit d'identité désignent chacun un objet différent, et les fondre referait l'erreur des « quatre homonymes sous un seul intitulé ». |
| **portée** | `UNIVERSELLE` |
| **règle** | `CRGI_REGROUPEMENT` déclare les familles repliables et ce qui fait leur phénomène ; `crgi_regrouper_file()` replie, en portant `couvre_ids`, `nombre` et `total`. Le filtre `detail` rend la vue ligne à ligne. |
| **composant** | `crgi_arbitrage.php` |
| **tests** | COUVERT — `crgi_file_arbitrage.php` : « l’extension à un groupe n’est jamais cochée d’avance » et le repli est vérifié par le compteur de pertes. |
| **corpus** | trois dépôts : 397 → 63, 37 → 17, 87 → 49 questions |
| **limites** | Les familles individuelles ne se replient pas, et c'est voulu — elles resteront le gros du reste. |
| **commit d’introduction** | `4359ed0` |

---

## APP-0025 · Une décision d'identité ne se redemande jamais

| | |
|---|---|
| **phénomène** | Les arbitrages vivaient dans une table indexée par dépôt. Une réponse donnée sur un trimestre ne servait à rien au trimestre suivant. |
| **preuve** | 04/09/2026 — 33 homonymes d'immeuble sur un dépôt, et les 33 mêmes questions au dépôt d'après, indéfiniment. Or ces homonymes ne viennent pas d'un défaut de lecture : **MBI porte lui-même les doublons**. La question est donc structurellement permanente, et la réponse aussi. |
| **abstraction** | Distinguer ce qui est une **identité** de ce qui est une **qualification de ligne**. « Cet immeuble du document est le n°563 de MBI » reste vrai au dépôt suivant : le regraver serait absurde. « Ce montant est une charge » appartient à sa ligne : le graver appliquerait une réponse à des faits qu'on n'a pas lus. Seules les identités entrent en mémoire durable, avec leur auteur, leur date et leur preuve. |
| **portée** | `UNIVERSELLE` |
| **règle** | Table `crgi_identite`, clé `(type, agence, clé métier)` — **bornée par l'agence**, car `UN CODE N'EST JAMAIS GLOBAL`. Le rapprochement la consulte AVANT de poser la question ; `crgi_graver_identite()` l'alimente à chaque décision d'identité validée. |
| **composant** | `crg_integration.php`, `crgi_arbitrage.php`, migration `20260904a` |
| **tests** | vérifié de bout en bout : question posée → décidée → gravée → **absente au rejeu**, l'immeuble étant rattaché par la mémoire |
| **corpus** | un dépôt : 9 → 8 homonymes après une seule décision |
| **limites** | Seule la famille des immeubles est câblée ; occupants et propriétaires ont leur place dans la table mais pas encore leur contexte. |
| **commit d’introduction** | `4359ed0` |

---

## APP-0026 · Une clé d'identité incomplète fabrique des conflits, elle n'en révèle pas

| | |
|---|---|
| **phénomène** | La clé d'identité d'une occupation est `compte × lot × arrêté × rang`. Quand l'un de ces termes manque, la clé s'effondre et des objets étrangers se retrouvent au même endroit — le détecteur de conflits les compare alors deux à deux. |
| **preuve** | 04/09/2026 — **235 CRG d'une famille entière sans date d'arrêté**, là où l'autre famille n'en avait pas un seul : la clé devenait vide, et **soixante « conflits d'occupant »** opposaient des locataires qui n'ont rien à voir. Puis, l'arrêté posé, les soixante subsistaient — pour une autre raison : le rang manquait. |
| **abstraction** | Un détecteur de conflits ne vaut que ce que vaut sa clé. Avant de croire un conflit, **vérifier que la clé est complète** : une clé partielle ne révèle pas un désaccord, elle en fabrique. Corollaire : un terme d'identité absent sur toute une famille est un défaut de lecture, jamais une propriété du document. |
| **portée** | `UNIVERSELLE` |
| **règle** | `fin_de_trimestre()` dans l'autorité de période : un document qui imprime « - 2e Trimestre 2026 - » énonce sa fin, et la phase 0 la pose pour les deux familles. |
| **composant** | `crg_periode.py`, `crg_integration_phase0.py` |
| **tests** | COUVERT — `crgi_coherence.php` : « REFUS — deux lots « 01 » de deux comptes ne sont pas le même lot ». |
| **corpus** | un dépôt : 235 CRG sans arrêté → 0 |
| **limites** | Ne couvre que le trimestre imprimé ; un document qui ne nomme ni période ni trimestre reste sans arrêté, et c'est honnête. |
| **commit d’introduction** | `4359ed0` |

---

## APP-0027 · Un lot imprimé deux fois dans le même compte rendu est une succession

| | |
|---|---|
| **phénomène** | Quand deux occupants se succèdent dans la période, le document réimprime le bloc du lot — une fois par occupant, l'un sous l'autre sur la même page. |
| **preuve** | 04/09/2026 — « ZOURDOS Nathalie » sur quatre mois puis « VANNAIRE Danièle » sur deux, même lot, même page, hauteurs différentes. Sans rang, les deux occupaient la même identité et le détecteur y voyait **soixante conflits** entre inconnus. |
| **abstraction** | `L'ORDRE D'IMPRESSION EST L'ORDRE DE LA SUCCESSION` — il se lit sur la page puis la hauteur, et c'est une propriété de l'impression, pas une hypothèse. C'est exactement ce que l'autre famille de documents traite depuis toujours par son `rang` : un phénomène connu ne change pas de nature en changeant d'éditeur. Et le patrimoine, lui, ne compte qu'UN lot : la succession appartient à l'occupation. |
| **portée** | `ÉDITEUR` |
| **règle** | Le pont ICS trie les blocs d'un lot par (page, hauteur) et leur attribue un rang croissant ; le patrimoine déduplique sur la référence. |
| **composant** | `crg_integration_ics.py` |
| **tests** | COUVERT — `crgi_coherence.php` : « REFUS — aucun cumul sur des périodes qui se chevauchent » et le rang par (page, y) des occupations. |
| **corpus** | un dépôt : 60 conflits d'identité → 0, lots 396 → 332 (les doublons d'impression cessent d'être comptés) |
| **limites** | Suppose que le document imprime les occupants dans l'ordre chronologique. Deux blocs à la même hauteur exacte resteraient indépartageables. |
| **commit d’introduction** | `4359ed0` |

---

## APP-0028 · Un document de même clé mais de contenu différent est COMPLÉMENTAIRE

| | |
|---|---|
| **phénomène** | Deux comptes rendus portent la même clé — compte, période, arrêté — mais ne partagent qu'une partie de leur contenu. Ce ne sont pas des doublons : chacun porte de l'information que l'autre n'a pas. |
| **preuve** | 04/09/2026 — toutes les phases filtraient sur `doublon_statut = "UNIQUE"`. Un document marqué « même clé, contenu différent » était donc écarté de l'inventaire, du patrimoine, des occupations ET de l'argent : **le compte rendu entier disparaissait**, et seul un écart de couverture de deux unités le signalait, tout au bout de la chaîne. |
| **abstraction** | Un filtre écrit en positif — « je ne garde que X » — exclut en silence tout ce qui n'est pas X, y compris ce qui n'existait pas encore quand on l'a écrit. Il faut écrire ce qu'on EXCLUT, et le justifier : ici, seule la réénonciation s'exclut, parce que `RÉIMPRESSION ≠ NOUVEL ÉVÉNEMENT` et que la compter doublerait l'argent. |
| **portée** | `UNIVERSELLE` |
| **règle** | `CRGI_CRG_PORTEURS` — une condition écrite une fois, en négatif : `doublon_statut <> "REENONCIATION"`. Les huit filtres de la chaîne l'emploient. |
| **composant** | `crg_integration.php` |
| **tests** | COUVERT — `crgi_nouveaute_pas_exclusion.php` (12 contrôles) et `crgi_coherence.php` : « REFUS — un CRG complémentaire n’est pas une réénonciation ». |
| **corpus** | un dépôt : 2 comptes rendus rendus à la chaîne |
| **limites** | Un troisième statut de collision qui apparaîtrait demain serait inclus par défaut — c'est voulu : mieux vaut examiner un document de trop que d'en perdre un. |
| **commit d’introduction** | `98217b7` |

---

## APP-0029 · « Illisible » confondait trois états, et alarmait à tort

| | |
|---|---|
| **phénomène** | Un dépôt contient des documents que le moteur ne transforme pas en CRG. Trois raisons très différentes, un seul mot pour les dire. |
| **preuve** | 04/09/2026 — cinq pièces déclarées « ILLISIBLES » sur un corpus : **trois n'avaient aucune couche texte** (des numérisations, qui demandent un OCR), **une était une lettre d'acompte** lue mot à mot, et **aucune n'était illisible** au sens propre. Le rouge envoyait chercher une panne du moteur là où il fallait lancer un OCR ou simplement ranger un document. |
| **abstraction** | `ILLISIBLE` (le fichier ne s'ouvre pas), `SANS TEXTE` (lu, mais aucun caractère : une image) et `HORS CRG` (lu entièrement, ce n'est pas un compte rendu) appellent trois actions différentes — réparer, océriser, classer. Les confondre sous le mot le plus alarmant fait perdre le seul qui devait alarmer. Un écran qui alarme à tort finit par ne plus alarmer du tout. |
| **portée** | `UNIVERSELLE` |
| **règle** | La phase 0 compte les caractères extraits et reconnaît les documents joints ; l'état de la pièce et le message de l'écran découlent des trois cas. |
| **composant** | `crg_integration.php`, `admin_crg_integration.php`, `crg_integration_phase0.py` |
| **tests** | COUVERT — `crgi_coherence.php` : « REFUS — « illisible » là où le document a été parfaitement ouvert », et `crgi_robustesse.py` pour la cause typée. |
| **corpus** | un dépôt : 5 « illisibles » → 3 sans texte + 1 hors CRG + 1 avis d'acompte nommé |
| **limites** | Le seuil de « sans texte » est un nombre de caractères ; un scan portant un filigrane textuel passerait pour lisible. |
| **commit d’introduction** | `98217b7` |

---

## APP-0030 · Le séparateur décimal appartient à l'éditeur, pas au moteur

| | |
|---|---|
| **phénomène** | Deux éditeurs écrivent la même somme de deux façons : `1 234,56` d'un côté, `1234.56` de l'autre. Un moteur écrit sur le premier éditeur rencontré ne lit AUCUN montant chez le second. |
| **preuve** | 04/09/2026 — le qualificateur de collisions rendait « trop peu de montants lus (0 et 0) pour trancher » sur QUATRE collisions de deux corpus différents. Mesure directe : 79, 31, 395 et 385 montants étaient imprimés sur les pages concernées. Le moteur n'en avait pas vu un seul, et rendait un verdict de prudence métier là où il n'avait rien regardé. |
| **abstraction** | `« JE N'AI RIEN LU » N'EST PAS « JE NE SAIS PAS TRANCHER ».` Un état d'indétermination qui ne dit pas de quel genre il est laisse un défaut de lecture se déguiser en décision. Un moteur doit distinguer *le document ne permet pas de conclure* de *je n'ai pas su lire le document* — la première appelle un arbitrage humain, la seconde appelle une correction. |
| **portée** | `UNIVERSELLE` |
| **règle** | Le motif de reconnaissance admet les deux séparateurs et refuse ce qui touche un autre chiffre (une date n'est pas un montant) ; la clé du multiensemble est canonique. Un extrait porteur de texte mais sans aucun montant reconnu rend un motif préfixé `LECTURE :`, et le contrôle de cohérence refuse ce préfixe. |
| **composant** | `crg_integration_doublons.py`, `crgi_coherence.php` |
| **tests** | `crgi_robustesse.py` — « un montant se lit AVEC LES DEUX séparateurs décimaux », « je n'ai rien lu ne se déguise jamais » ; éprouvés par `crgi_epreuve_des_tests.py` |
| **corpus** | deux dépôts, quatre collisions : 0 montant lu → 79, 31, 395 et 385 |
| **limites** | Un éditeur qui grouperait les milliers par un point (`1.234,56`) resterait illisible — mais il serait alors NOMMÉ par le motif `LECTURE :`, au lieu de passer pour une indétermination. |
| **commit d’introduction** | `98217b7` |

---

## APP-0031 · Les deux occurrences d'un même compte rendu ne sont pas dans le même fichier

| | |
|---|---|
| **phénomène** | Le même compte rendu est déposé deux fois, dans deux fichiers distincts — souvent parce qu'il est classé dans deux dossiers. La collision de clé se constate ; la comparaison, elle, doit ouvrir DEUX documents. |
| **preuve** | 04/09/2026 — la qualification groupait les paires par la pièce de la SECONDE occurrence et y découpait les deux extraits. Sur un corpus, les deux occurrences vivaient dans deux dossiers différents : le moteur comparait donc les pages 1-10 d'un document avec les pages 1-10 **du même document**. Le verdict aurait été « identiques » quel que soit le contenu réel de la seconde. |
| **abstraction** | Une optimisation de lecture ne doit jamais fixer l'identité de ce qu'on lit. Grouper par document pour ne lire qu'une fois est juste ; en déduire que les deux côtés d'une comparaison viennent du même document est un raccourci que rien ne démontre. La promesse de performance se tient par un CACHE, pas par une hypothèse. |
| **portée** | `UNIVERSELLE` |
| **règle** | Chaque paire porte le chemin de chacun de ses deux côtés ; le moteur garde un cache par document, donc une lecture par document et non par paire. |
| **composant** | `crg_integration_doublons.py`, `crg_integration.php` |
| **tests** | `crgi_robustesse.py` — « les deux occurrences d'une collision peuvent venir de DEUX documents » ; éprouvé par `crgi_epreuve_des_tests.py` |
| **corpus** | un dépôt : 2 collisions inter-dossiers |
| **limites** | Aucune : le mode historique paire à paire reste accepté, et le cache le couvre aussi. |
| **commit d’introduction** | `98217b7` |

---

## APP-0032 · Deux mailles additionnées dans une même famille se cachent sous le total

| | |
|---|---|
| **phénomène** | Un plan de travail compte, dans une famille d'objets, des verdicts qui portent sur une AUTRE maille. Les deux populations s'additionnent, et le total général reste plausible. |
| **preuve** | 04/09/2026 — la famille PROPRIÉTAIRES annonçait 200 verdicts pour 199 noms lus. Le verdict de trop était une qualification de COMPTE MANDANT — « ce compte existe dans MBI sous une autre écriture » — ajoutée à une famille de propriétaires. Un compte n'est pas un propriétaire (`TIERS ≠ PROPRIÉTAIRE ≠ COMPTE MANDANT`). L'écart d'UNE unité ne se voyait pas au total général ; il n'est apparu qu'au contrôle famille par famille. |
| **abstraction** | `UN COMPTEUR GLOBAL MASQUE UNE POPULATION.` Deux grands totaux peuvent se refermer alors qu'une famille en perd — ou en gagne — la moitié. Chaque verdict doit être compté là où son OBJET existe, et une seule fois ; le contrôle doit boucler famille par famille, jamais en somme. |
| **portée** | `UNIVERSELLE` |
| **règle** | Les qualifications de compte alimentent la famille COMPTES MANDANTS (`A ARBITRER`), retranchées de son `CRÉER` ; la famille PROPRIÉTAIRES ne compte plus que des propriétaires. |
| **composant** | `crg_integration.php` |
| **tests** | `crgi_coherence.php` — « le plan boucle FAMILLE PAR FAMILLE, pas seulement en total » |
| **corpus** | deux dépôts : 199 propriétaires / 200 verdicts, et 75 / 78 |
| **limites** | Le contrôle ne compare que les familles qu'il sait mesurer à la source ; une famille sans population source mesurable resterait hors de sa portée. |
| **commit d’introduction** | `98217b7` |

---

## APP-0033 · Un lot rangé dans la table des immeubles fabrique un faux homonyme

| | |
|---|---|
| **phénomène** | La table des immeubles de MBI contient des LOTS — appartements, locaux commerciaux, garages — rangés là par une reprise ancienne, à la même adresse et sous le même nom que leur bâtiment. Le rapprochement les compte comme candidats, et l'identité devient « ambiguë ». |
| **preuve** | 04/09/2026 — 41 questions d'identité d'immeuble sur deux corpus. **33 n'opposaient pas deux bâtiments** : elles opposaient un bâtiment à ses propres lots (245 « Appartement », 22 « Local commercial », 10 « Garage » dans la table). 3 autres n'avaient AUCUN bâtiment candidat — seulement des lots — donc l'immeuble était simplement absent de MBI. Restaient **3 vraies ambiguïtés**. |
| **abstraction** | `CE QUI N'EST PAS DE L'ESPÈCE CHERCHÉE N'EST PAS UN CANDIDAT.` Réduire l'ensemble des candidats à l'espèce recherchée n'est pas un rapprochement approximatif — c'est l'inverse : c'est refuser de comparer des objets de natures différentes. Une ambiguïté fabriquée par la façon d'indexer coûte autant à l'humain qu'une vraie, et elle est plus dangereuse, car elle décrédibilise les vraies. |
| **portée** | `UNIVERSELLE` |
| **règle** | On EXCLUT les types démontrés être des lots ; un type inconnu ou absent reste candidat (`NOUVEAUTÉ ≠ EXCLUSION`), et « Maison » n'est pas exclue — une maison est un immeuble. Si tous les candidats sont des lots, l'immeuble est NOUVEAU. |
| **composant** | `crg_integration.php` — `crgi_candidats_batiments()` |
| **tests** | `crgi_rapprochement.php` — « un LOT de MBI n'est jamais candidat pour un IMMEUBLE » |
| **corpus** | deux dépôts : 41 homonymes → 3 |
| **limites** | Un lot dont le type n'est pas renseigné reste candidat : il produira encore une question, et c'est voulu. |
| **commit d’introduction** | `98217b7` |

---

## APP-0034 · Une question à laquelle personne ne peut répondre n'est pas un arbitrage

| | |
|---|---|
| **phénomène** | Le rapprochement ne parvient pas à désigner l'écriture correspondante, et remonte la ligne « à trancher ». Mais dans certains cas, l'information qui permettrait de trancher n'existe nulle part — ni dans le document, ni dans MBI. |
| **preuve** | 04/09/2026 — 23 lignes d'un corpus attendaient une décision humaine : **14 portaient le libellé « Solde »**, imprimé à chaque bloc, face à 12 écritures MBI homonymes ; **9 faisaient face à des écritures de MBI strictement identiques — même libellé ET même montant**. Emmanuel voit exactement ce que le moteur voit. Lui demander LAQUELLE, c'est lui demander de deviner. |
| **abstraction** | `INDÉTERMINABLE POUR LE MOTEUR ≠ INDÉTERMINABLE POUR TOUT LE MONDE.` La première situation est une question — un humain rouvre la page et tranche. La seconde est une LIMITE : elle se nomme, se compte, se conserve, et ne se pose à personne. Les confondre remplit la file de questions sans réponse, et fait perdre confiance dans celles qui en ont une. |
| **portée** | `UNIVERSELLE` |
| **règle** | Deux verdicts distincts et déclarés : `NON RAPPROCHABLE` (aucune information n'existe — hors file, compté au plan en `NON INTEGRABLE`) et `CANDIDAT NON DEMONTRABLE` (la page tranchera — dans la file). Un contrôle refuse qu'un `NON RAPPROCHABLE` entre dans la file, ET qu'un `CANDIDAT NON DEMONTRABLE` en sorte. |
| **composant** | `crg_integration.php` — `crgi_verdict_rapprochement()` |
| **tests** | `crgi_coherence.php` — « REFUS — une question à laquelle personne ne peut répondre » ; `crgi_rapprochement.php` |
| **corpus** | un dépôt : 23 lignes, 7 questions → 0 |
| **limites** | Le seuil de « libellé générique » est un nombre de caractères significatifs : un libellé long mais répété resterait posé en question. |
| **commit d’introduction** | `98217b7` |

---

## APP-0035 · Une piste proposée doit nommer son objet, sinon la décision est illisible

| | |
|---|---|
| **phénomène** | L'écran propose plusieurs pistes pour un même arbitrage. Quand deux pistes instancient la même règle générique sur des objets différents, elles portent le même intitulé. |
| **preuve** | 04/09/2026 — deux pistes « Considérer l'occupant en place » sur le même lot désignaient DEUX PERSONNES : la période précédente en nommait une, les mouvements du lot une autre. La décision enregistrée aurait dit qu'on avait tranché, jamais POUR QUI. C'est le même défaut que les quatre immeubles homonymes proposés sous « Désigner l'immeuble MBI existant ». |
| **abstraction** | `UNE DÉCISION VAUT CE QUE VAUT LA PRÉCISION DE CE QU'ELLE ENREGISTRE.` Un arbitrage indiscernable en base n'est pas un arbitrage : c'est la trace d'un clic. La piste porte donc l'identité de l'objet ; la règle générique qu'elle instancie se déclare à côté, pour que l'écran n'ait pas à réafficher l'option ambiguë. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute piste dont plusieurs instances peuvent coexister nomme son objet. Deux sources qui désignent le MÊME objet se renforcent — la confiance la plus haute l'emporte, le motif dit les deux — au lieu de produire deux pistes jumelles. |
| **composant** | `crgi_arbitrage.php` — `crgi_propositions()` |
| **tests** | `crgi_file_arbitrage.php` — « deux pistes proposées ne portent jamais le même intitulé » |
| **corpus** | un dépôt : 1 arbitrage à deux pistes homonymes |
| **limites** | Le contrôle compare des intitulés : deux pistes nommant le même objet sous deux orthographes resteraient distinctes. |
| **commit d’introduction** | `98217b7` |

---

## APP-0036 · Une phase doit effacer ce qu'elle ne couvre plus

| | |
|---|---|
| **phénomène** | Une phase marque les objets qu'elle traite. Quand une règle amont change et lui en retire, la marque d'avant reste collée — et plus rien ne dit qu'elle est périmée. |
| **preuve** | 04/09/2026 — deux comptes rendus marqués « NOUVELLE » par l'inventaire sont devenus des RÉÉNONCIATIONS quand la qualification des collisions a su lire leurs montants. L'inventaire ne les regarde plus, mais leur marque est restée : le contrôle de couverture les comptait **DEUX FOIS** — examinés ET exclus — et annonçait « −2 objets inexpliqués » sur une chaîne pourtant complète. |
| **abstraction** | `UNE TRACE QUI SURVIT À LA RÈGLE QUI L'A PRODUITE EST UN MENSONGE, PAS UN SOUVENIR.` Une phase qui écrit doit d'abord effacer sa propre écriture sur TOUTE la population, puis la reposer sur celle qu'elle couvre aujourd'hui. Sinon `ATTENDUE = EXAMINÉE + EXCLUE` se met à compter des objets dans les deux colonnes, et l'écart qu'il signale est faux dans les deux sens : il peut aussi bien masquer une vraie perte. |
| **portée** | `UNIVERSELLE` |
| **règle** | La phase 1 remet à NULL `inventaire_statut`, `inventaire_motif` et `mbi_trimestre_id` sur tout l'import avant de marquer les porteurs. Le filtre de population devient la constante `CRGI_CRG_PORTEURS` — une seule autorité, éprouvée comme prédicat par la fixture négative. |
| **composant** | `crg_integration.php` — `crgi_phase1()` |
| **tests** | `crgi_coherence.php` — « couverture P1 — CRG documentaires » ; `crgi_nouveaute_pas_exclusion.php` reconnaît la constante comme un prédicat |
| **corpus** | un dépôt : 235 attendus, 233 examinés + 4 exclus = 237 |
| **limites** | Le même risque existe pour toute colonne écrite par une phase et lue par une autre ; seule la phase 1 est corrigée ici, les autres suppriment déjà leurs lignes avant de réécrire. |
| **commit d’introduction** | `98217b7` |

---

## APP-0037 · La forme d'un code appartient au gestionnaire ; l'égalité, non

| | |
|---|---|
| **phénomène** | MBI porte le code du gestionnaire dans une colonne dédiée. Un éditeur l'écrit préfixé — `01S01-0067` — un autre le rend nu — `01040087`. Une preuve écrite sur la première forme est AVEUGLE sur la seconde. |
| **preuve** | 04/09/2026 — la règle exigeait un tiret pour isoler le segment final. Sur un corpus entier, aucun code n'en portait : **14 questions d'identité posées** alors que MBI portait le code, à l'identique, dans la colonne faite pour lui. |
| **abstraction** | Une preuve d'égalité ne doit pas dépendre de la MISE EN FORME de ce qu'elle compare. On normalise ce qui est démontré variable — ici le préfixe d'agence et d'activité, séparé par un tiret — et on exige l'égalité ENTIÈRE du reste. Jamais une inclusion libre : « 0134 » n'est pas la fin de « 01080134 ». |
| **portée** | `UNIVERSELLE` |
| **règle** | Le segment comparé est ce qui suit le dernier tiret, ou la valeur entière s'il n'y a pas de tiret ; l'égalité se fait après retrait des zéros de tête, et deux candidats au même code ne se départagent pas. `code_crg` prime sur `reference_immeuble` : une seule des deux colonnes est faite pour porter le code du gestionnaire, et le motif le dit pour que la décision reste auditable. |
| **composant** | `crg_integration.php` — `crgi_candidat_par_code_crg()` |
| **tests** | `crgi_rapprochement.php` — « le code imprimé sur le CRG se retrouve ENTIER dans `code_crg` » |
| **corpus** | un dépôt : 14 questions posées pour un tiret absent |
| **limites** | Un gestionnaire qui séparerait son préfixe autrement qu'avec un tiret resterait non couvert — et poserait la question, ce qui est le bon échec. |
| **commit d’introduction** | `98217b7` |

## APP-0038 · Quand la base se contredit, ce n'est plus au document qu'il faut poser la question

| | |
|---|---|
| **phénomène** | Plusieurs enregistrements de MBI répondent à l'identité lue sur le compte rendu. Le moteur annonce « N candidats » et demande lequel — comme s'il s'agissait de bâtiments différents. |
| **preuve** | 04/09/2026 — sur un dépôt, **10 groupes d'« homonymes » réunissaient des enregistrements portant le MÊME code de gestion et la MÊME adresse** : `238 Route de Vienne` existait TROIS fois dans MBI, sous le code `01040087`, créé par trois reprises successives. Le document n'était pas ambigu une seconde ; c'est la base qui se contredit. |
| **abstraction** | `AMBIGUÏTÉ DU DOCUMENT ≠ CONTRADICTION DE LA BASE.` Les deux produisent « plusieurs candidats », mais la question n'est pas la même et la réponse ne se cherche pas au même endroit : dans un cas on rouvre le PDF, dans l'autre on fait le ménage dans MBI. Poser les deux avec la même phrase envoie chercher la réponse dans le mauvais document — et fait passer un problème d'hygiène de données pour une difficulté de lecture. |
| **portée** | `UNIVERSELLE` |
| **règle** | DEUX preuves, chacune suffisante, parce que MBI ne remplit pas toujours les deux colonnes : tous les candidats portent le même code de gestion, OU tous portent la même adresse normalisée. Sur le corpus, 10 groupes se démontrent par le code et **7 par la seule adresse** — n'en garder qu'une laissait 7 contradictions de la base passer pour des ambiguïtés du document. Le phénomène devient une famille d'arbitrage à part, et comme la réponse est la MÊME pour tous (quelle règle appliquer quand la base se répète), les 17 se replient en UNE question. Le repli se déclare par famille de phénomène, jamais par type d'objet : deux immeubles réellement homonymes restent deux décisions individuelles. |
| **composant** | `crg_integration.php` — `crgi_confronter_patrimoine()` |
| **tests** | `crgi_rapprochement.php` — « MBI qui se contredit ne se confond pas avec un document ambigu » (quatre formes : trois copies, l'adresse seule, un candidat sans valeur, deux valeurs différentes) |
| **corpus** | un dépôt : 18 groupes après les autres preuves — **17 sont des répétitions de MBI**, 1 seule est une vraie ambiguïté du document |
| **limites** | Deux enregistrements du même immeuble dont l'adresse est ABRÉGÉE différemment (« 36 PLACE F MITTERRAND » / « 36 PLACE FRANCOIS MITTERRAND ») restent présentés comme des homonymes : les rapprocher demanderait une comparaison approximative, que la doctrine interdit. |
| **commit d’introduction** | `98217b7` |

---

## APP-0039 · Un lot réduit à son report n'a pas de période — et disparaissait pour cela

| | |
|---|---|
| **phénomène** | Le bloc d'un lot qui n'a eu aucun mouvement dans la période ne porte qu'une ligne « Solde Antérieur » : un montant, sans « Du … Au … ». |
| **preuve** | 06/09/2026 — le pont n'émettait un mouvement que pour les MOIS du lot. **85 lots occupés** — locataires nommés, chronologie complète en phase 3 — disparaissaient de la phase 4, dont un portant **48 371,47 €** d'arriéré. Sur le corpus entier : **1 184 lignes, 8 958 947,32 €**. Le parseur avait la valeur depuis toujours, dans `lot['solde_anterieur']` ; c'est le pont qui ne la demandait jamais. Seul l'écart de dénombrement entre les phases 3 et 4 l'a révélé — aucun total ne bougeait, puisque ces montants n'entraient nulle part. |
| **abstraction** | `L'ABSENCE D'UN ATTRIBUT N'EST PAS L'ABSENCE DE L'OBJET.` Une boucle qui itère sur une sous-structure — les mois, les lignes, les pages — perd silencieusement tout ce qui vit à côté d'elle. Le montant existait, il était même déjà extrait ; il n'avait simplement pas de place dans la forme que le pont attendait. |
| **portée** | `UNIVERSELLE` |
| **règle** | Le report du lot est émis comme un mouvement à part entière, au LOT, catégorie `ENCOURS` : c'est un STOCK à l'ouverture de la période. Jamais additionné à un total de période, ni au « Reste dû » de l'arrêté — qui mesure la même dette à une AUTRE date. |
| **composant** | `crg_integration_ics.py` — `mouvements()` |
| **tests** | `crgi_robustesse.py` — « un lot qui n'a QUE son report est lu quand même » ; éprouvé par `crgi_epreuve_des_tests.py` |
| **corpus** | un dépôt : 85 lots absents de la phase 4, 1 184 reports récupérés |
| **limites** | Un gabarit qui nommerait ce report autrement resterait non couvert — mais l'écart de dénombrement entre phases le signalerait de la même façon. |
| **commit d’introduction** | `98217b7` |

## APP-0040 · Une famille du plan qui ne suit pas le vocabulaire déclaré rouvre la faille

| | |
|---|---|
| **phénomène** | Le plan d'intégration range les mouvements par famille. Une nature déclarée au vocabulaire mais qu'aucune famille ne reprend n'est ni intégrée, ni exclue, ni arbitrée. |
| **preuve** | 06/09/2026 — la catégorie `IMPOTS ET TAXES` était déclarée et produite par le moteur ICS depuis des semaines. Aucune famille du plan ne la reprenait : **340 mouvements — une taxe foncière entière — n'étaient nulle part**. Il a fallu un corpus de 14 370 lignes pour que l'écart devienne visible ; sur un petit dépôt il serait passé inaperçu pendant des mois. |
| **abstraction** | C'est exactement `NOUVEAUTÉ ≠ EXCLUSION`, mais d'un cran plus haut : on avait corrigé les FILTRES écrits en positif, pas les RÉPARTITIONS écrites en positif. Une liste de familles est une liste blanche comme une autre — elle définit sans le dire tout l'univers autorisé, et ce qui n'y figure pas tombe hors du monde. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un contrôle confronte le vocabulaire déclaré des natures aux familles du plan : toute nature qu'aucune famille ne reprend fait rougir, sauf celles qui sont explicitement non intégrables. Et la preuve par les faits reste exigée : le plan doit refermer sur TOUS les mouvements. |
| **composant** | `crg_integration.php` — familles de la phase 5 |
| **tests** | `crgi_coherence.php` — « le plan couvre TOUT le vocabulaire déclaré des natures » |
| **corpus** | un dépôt : 14 030 mouvements couverts sur 14 370 |
| **limites** | Le contrôle lit la source pour trouver les familles ; une famille construite dynamiquement lui échapperait — mais la preuve par les faits, elle, ne lui échapperait pas. |
| **commit d’introduction** | `98217b7` |

## APP-0041 · Séparer la note du lecteur de celle de la base

| | |
|---|---|
| **phénomène** | Une question posée à l'humain peut venir du document (il est ambigu) ou de la base (elle se contredit). Comptées ensemble, les deux dégradent le même indicateur. |
| **preuve** | 06/09/2026 — sur 38 questions des quatre corpus, **13 ne venaient pas des documents** : ils étaient parfaitement compris. Un compte rendu impossible à rattacher parce que MBI porte trois fois le même immeuble faisait baisser le « taux de compréhension » du lecteur. Et 17 des 18 « homonymes » d'un dépôt étaient de ce genre. |
| **abstraction** | `AMBIGUÏTÉ DU DOCUMENT ≠ CONTRADICTION DE LA BASE ≠ PANNE D'INFRASTRUCTURE.` Trois causes, trois responsables, trois gestes — relire un PDF, faire le ménage dans la base, régler un serveur. Un indicateur qui les additionne ne dit plus où est le problème, et fait porter à l'agent la faute des données qu'on lui donne à lire. |
| **portée** | `UNIVERSELLE` |
| **règle** | Chaque famille d'arbitrage DÉCLARE sa cause — jamais devinée d'après son nom. Le pilotage rend deux taux distincts (compréhension documentaire, raccordement à MBI) et trois compteurs qui ne s'additionnent jamais. Un contrôle exige que toute famille déclare sa cause et que les compteurs totalisent exactement la file. |
| **composant** | `crg_integration.php`, `crgi_arbitrage.php`, `crgi_pilotage.php` |
| **tests** | `crgi_coherence.php` — « chaque question déclare SA CAUSE — document ou base » |
| **corpus** | quatre dépôts : 38 questions = 25 document + 13 base, 4 associations réellement empêchées |
| **limites** | La cause est déclarée à la maille de la FAMILLE : une famille qui mélangerait les deux origines serait mal classée en bloc. |
| **commit d’introduction** | `98217b7` |

---

## APP-0042 · Un test peut échouer PARCE QUE la règle marche

| | |
|---|---|
| **phénomène** | Un contrôle vire au rouge. La tentation est de corriger le code qu'il accuse — alors que c'est le MONTAGE du test qui est faux. |
| **preuve** | 06/09/2026 — le contrôle « REFUS — deux traitements simultanés » ouvrait une seconde connexion, lui faisait prendre le verrou du dépôt, puis lançait une phase sur la connexion du script. Or ce script venait JUSTEMENT de rejouer les phases 2 à 5 : sa connexion tenait déjà le verrou. Le « concurrent » ne pouvait donc pas le prendre, et le test échouait — **parce que le verrou fonctionnait exactement comme prévu**. |
| **abstraction** | `UN ROUGE DIT QU'UNE ATTENTE N'EST PAS SATISFAITE, PAS QUE LE CODE EST FAUX.` Avant de toucher au code accusé, il faut vérifier que le test décrit le monde dans lequel il s'exécute. Un test qui partage un état global avec ce qu'il éprouve — ici la connexion, et donc le verrou — mesure autant son propre montage que la règle. |
| **portée** | `UNIVERSELLE` |
| **règle** | Le contrôle ouvre DEUX connexions neuves : l'une occupe le dépôt, l'autre essaie d'y entrer. Aucune n'est celle du script, dont l'état est imprévisible à cet endroit du fichier. |
| **composant** | `crgi_replay.php` |
| **tests** | le contrôle lui-même, rejoué isolément dans un processus n'ayant lancé aucune phase |
| **corpus** | — (défaut de montage, pas de corpus) |
| **limites** | Le verrou étant tenu par la connexion, tout test qui l'éprouve doit maîtriser QUELLE connexion fait quoi ; un pool de connexions rendrait ce contrôle inopérant. |
| **commit d’introduction** | `98217b7` |

---

## APP-0043 · Un instrument qui rétrécit son sujet sans le dire annonce un faux vert

| | |
|---|---|
| **phénomène** | Une suite de contrôles choisit les objets qu'elle peut examiner. Ceux qu'elle ne peut pas examiner disparaissent du compte — et le compte, lui, reste parfait. |
| **preuve** | 06/09/2026 — le harnais a affiché **« COHÉRENCE : 132/132 »**, entièrement vert. Il couvrait TROIS dépôts sur quatre : le quatrième avait une phase interrompue, il ne figurait donc pas parmi les « complets » et sortait du dénombrement sans une ligne. Le vert précédent, sur les mêmes règles, valait 176/176 — mais rien dans la sortie ne disait que l'assiette avait changé. |
| **abstraction** | `UN SUJET ÉCARTÉ EN SILENCE EST PIRE QU'UN SUJET ROUGE.` Un rouge appelle une action ; un sujet absent n'appelle rien, et le rapport annonce une réussite sur ce qu'il a bien voulu regarder. C'est la même faute que les filtres écrits en positif, transposée à l'instrument de mesure lui-même — et elle est plus dangereuse, parce que c'est justement l'instrument qui devait détecter ce genre de chose. |
| **portée** | `UNIVERSELLE` |
| **règle** | La suite NOMME toujours les dépôts qu'elle laisse dehors, avec leur volume et l'état exact qui les en exclut, avant d'annoncer son score. Un dépôt interrompu n'est pas un dépôt sans intérêt. |
| **composant** | `crgi_coherence.php` |
| **tests** | la sortie de la suite elle-même : elle affiche la liste des écartés avant le total |
| **corpus** | quatre dépôts : 176/176 puis 132/132 sur les mêmes règles, sans que rien ne le signale |
| **limites** | Le contrôle nomme les dépôts PORTEURS de CRG et non scellés ; un dépôt vide reste écarté sans mention, et c'est voulu. |
| **commit d’introduction** | `98217b7` |

---

## APP-0044 · Deux populations différentes qui portent le même nombre

| | |
|---|---|
| **phénomène** | Deux mesures sans rapport tombent sur le même cardinal. Citées par leur seul chiffre, elles deviennent indiscernables — et quelqu'un finira par les rapprocher. |
| **preuve** | 06/09/2026 — le corpus porte deux « 41 » : **41 questions d'identité d'immeuble** (population du rapprochement document × MBI, ramenée à 3 vraies ambiguïtés) et **41 objets typés lot ayant un parent identifiable** (population de qualité de la table `immeubles`, sur 277 objets typés lot). Rien dans les rapports ne les distinguait. |
| **abstraction** | `UN CARDINAL N'EST PAS UNE IDENTITÉ.` Un nombre ne dit pas de quoi il est le nombre. Une population citée sans son nom ni sa définition devient, quelques mois plus tard, la population de quelqu'un d'autre — et la conclusion qu'on en tire est fausse sans que rien ne l'indique. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute population citée porte son NOM et sa DÉFINITION. Deux populations de même cardinal sont affichées côte à côte avec ce qui les distingue. Écrire « les 41 » est interdit. |
| **composant** | référentiel, registre, page Qualité, rapports |
| **tests** | NON TESTABLE PAR NATURE — c'est une règle de RÉDACTION : aucun contrôle exécutable ne peut décider qu'un chiffre a été cité sans sa définition. Maîtrise : `INTEG-NOMMAGE-01` au référentiel, et la relecture des rapports avant remise. |
| **corpus** | deux populations de cardinal 41, sans rapport l'une avec l'autre |
| **limites** | Aucune vérification automatique : c'est une discipline d'écriture, pas un contrôle exécutable. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0045 · Un oracle doit prouver la comparabilité avant de comparer

| | |
|---|---|
| **phénomène** | Un contrôle compare deux populations et conclut de leur écart. Mais si les deux populations n'ont pas la même sémantique, l'écart — comme l'égalité — ne prouve rien. Le contrôle accuse alors le moteur d'une différence qui vient de son propre montage. |
| **preuve** | 06/09/2026 — **trois fois le même défaut en une journée**. ① Le contrôle du verrou ouvrait une seconde connexion pour « occuper » le dépôt, alors que la connexion du script tenait déjà ce verrou : il échouait PARCE QUE la règle fonctionnait. ② Un contrôle d'occupation comparait les statuts des lots vus à une période et de ceux vus à plusieurs : ils diffèrent légitimement, parce qu'une seconde période EST une preuve supplémentaire. ③ Le même contrôle, resserré, comparait encore des populations dont l'une pouvait être mise en attente pour une preuve entièrement différente — son compte continue d'être rendu sans elle. |
| **abstraction** | `UN ORACLE QUI COMPARE DEUX POPULATIONS DOIT D'ABORD PROUVER QU'ELLES SONT COMPARABLES.` Avant toute assertion de la forme `A = B`, il faut établir : même maille, même périmètre, même situation temporelle, même sémantique, mêmes exclusions. Sinon le test mesure son propre montage autant que la règle — et le rouge qu'il produit envoie corriger du code qui va bien. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un contrôle comparatif restreint explicitement sa population aux objets qui portent la MÊME preuve, et dit lesquels il écarte. Quand aucune population comparable ne peut être construite, il change d'oracle — ici, le contrôle porte sur le MOTIF écrit par le moteur, pas sur une symétrie de statuts. |
| **composant** | `crgi_coherence.php`, `crgi_replay.php` |
| **tests** | COUVERT — `crgi_coherence.php` : « une même preuve reçoit le même traitement, quel que soit son voisinage » (oracle sur le motif) ; `crgi_replay.php` : « REFUS — deux traitements simultanés » (éprouvé sur un dépôt qu'aucun traitement n'a touché) |
| **corpus** | trois oracles faux en une journée, sur trois sujets sans rapport |
| **limites** | Aucun contrôle automatique ne peut décider qu'une comparaison est légitime : c'est une exigence de conception, et elle se relit. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0046 · Une ligne n'est pas un objet — et le confondre multiplie le patrimoine

| | |
|---|---|
| **phénomène** | Le staging enregistre une LIGNE par observation. Le même immeuble est réénoncé à chaque période, le même lot à chaque arrêté. Compter les lignes et les annoncer sous le nom de l'objet gonfle le patrimoine dans la proportion du nombre de périodes. |
| **preuve** | 06/09/2026 — le tableau de bord et les rapports annonçaient **« 1 450 immeubles »** et **« 2 285 lots »**. Le corpus porte en réalité **733 immeubles** et **1 144 lots**. Sur un dépôt : **316 lignes pour 80 immeubles**. C'est Emmanuel — qui connaît son patrimoine — qui l'a vu au premier coup d'œil : « nous avons 300+300+200+100+40+70+80 immeubles au total, on est loin de tes 1 450 ». Aucun contrôle ne l'avait signalé, parce qu'aucun contrôle ne compare un dénombrement à la réalité du métier. |
| **abstraction** | `UN NOMBRE N'EXISTE PAS SANS SA POPULATION`, et le piège est ici plus sournois qu'ailleurs : les deux nombres sont JUSTES, seul leur nom est faux. Les taux et les arbitrages portent légitimement sur les LIGNES — c'est là que le travail se fait ; le patrimoine, lui, se lit en objets. Le même tableau doit donc porter les deux, avec la clé d'identité qui les distingue. |
| **portée** | `UNIVERSELLE` |
| **règle** | Chaque famille du pilotage rend « lignes lues » ET « objets désignés », avec la clé qui définit l'objet : l'immeuble par son code (ou nom + code postal) dans son agence, le lot par `compte × référence` (`INTEG-IDENT-03`), l'occupation par ses locataires nommés. |
| **composant** | `crgi_pilotage.php`, `crgi_pilotage_vue.php` |
| **tests** | COUVERT INDIRECTEMENT — `crgi_coherence.php` : « le plan boucle FAMILLE PAR FAMILLE » compare déjà chaque famille à sa population source SUR SA PROPRE MAILLE ; c'est cette maille que le tableau de bord n'affichait pas. |
| **corpus** | quatre dépôts : 1 450 lignes pour 733 immeubles, 2 285 lignes pour 1 144 lots |
| **limites** | La clé d'identité d'un immeuble reste `code` ou `nom|code postal` : deux immeubles réellement homonymes dans une même agence comptent pour un. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0047 · Le parseur lisait l'argent ; le pont ne le demandait qu'au mois

| | |
|---|---|
| **phénomène** | Un même éditeur imprime la même colonne à deux MAILLES selon l'agence : chez l'un, « Réglés » et « Impayés » sont détaillés mois par mois ; chez l'autre, ils ne figurent QUE sur la ligne de total du lot. Le pont entre le parseur et la phase 4 ne parcourait que les lignes mensuelles. Sur le second gabarit, tout l'argent encaissé disparaissait — sans erreur, sans exception, sans ligne rouge. |
| **preuve** | 06/09/2026 — dépôt EMERY IMMO, 235 documents, 403 lots. Le parseur lit `total_regle` sur **331 lots — 545 725,15 €** et `total_impaye` sur **119 lots — 147 082,72 €**. La phase 4 en a reçu **9 lignes**. L'en-tête du document imprime pourtant « Locataires Période Loyers Taxes Provisions Divers Total Réglés Impayés » — exactement celui de l'agence dont la lecture fonctionne. Le taux « compris » du dépôt affichait 93,2 % : rien n'était illisible, tout était simplement non demandé. |
| **abstraction** | `LIRE N'EST PAS TRANSMETTRE.` Un pont qui énumère les mailles qu'il traverse perd toute maille qu'il n'a pas nommée — et la perte est silencieuse par construction, puisque aucun contrôle aval ne peut regretter une valeur qu'il n'a jamais reçue. C'est le même défaut qu'`APP-0031` (la colonne « Divers » perdue par une liste écrite à la main), remonté d'un étage : ce ne sont plus les colonnes qui étaient énumérées, ce sont les NIVEAUX. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un contrôle SANS SEUIL compare une présence à une absence sur la même maille : **un lot qui appelle du loyer et ne porte aucun encaissement de toute la période**. Mesuré sur quatre dépôts : 0,0 % · 3,4 % · 11,1 % · **99,7 %**. Aucune tolérance n'a été réglée pour obtenir cette séparation — c'est la condition pour que le contrôle vaille sur un dépôt qu'on n'a pas encore vu. |
| **composant** | `crg_integration_ics.py` (`mouvements()`), `crg_integration.php` (`crgi_lots_sans_encaissement()`) |
| **tests** | À COUVRIR — fixture synthétique : un lot dont les règlements ne sont imprimés qu'au total, et dont aucun encaissement n'atteint la phase 4. |
| **corpus** | un dépôt sur quatre, 545 725,15 € d'encaissements et 147 082,72 € d'impayés absents |
| **limites** | Le contrôle DÉTECTE l'anomalie ; il ne la corrige pas. Faire du total du lot le mouvement d'encaissement du lot est une décision de doctrine — `AGRÉGAT ≠ MOUVEMENT` — et elle appartient à Emmanuel, pas au moteur : sur le gabarit qui détaille les mois, le même total serait un doublon. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0048 · Le verrou existait, protégeait l'écriture, et ne disait rien à la lecture

| | |
|---|---|
| **phénomène** | Un verrou d'exécution empêche deux traitements d'écrire le même dépôt en même temps. Il ne dit rien à qui **lit**. Le tableau de bord, les KPI et les compteurs de perte interrogent alors un staging à moitié réécrit et rendent des nombres complets en apparence, sans le moindre signe. |
| **preuve** | 06/09/2026 — pendant que le test de replay rejouait les phases 2 à 5 sur le dépôt de référence (ce qu'il annonce faire), le pilotage affichait **128 immeubles** au lieu de 316 pour ce dépôt, **tous au statut `INDETERMINE`**, et concluait « **2 pertes détectées** ». Les trois nombres étaient des artefacts du traitement en cours. `IS_USED_LOCK` disait pourtant « TENU » à la même seconde — l'information existait, personne ne la demandait. Sans cette vérification, un rapport aurait annoncé une régression inventée. |
| **abstraction** | `UNE MESURE PRISE PENDANT UNE ÉCRITURE N'EST PAS UNE MESURE.` Et le danger propre à ce cas est qu'elle ne ressemble EN RIEN à une erreur : pas d'exception, pas de valeur nulle, pas de trou — des nombres plus petits, parfaitement formés, qu'on croit sur parole. C'est la quatrième fois du training qu'une concurrence produit un chiffre faux annoncé comme vrai (14 624, 27 389, 24 196, puis celui-ci) ; les trois premières fois, le remède a été le verrou d'écriture. Il manquait la moitié lecture. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute fonction qui rend une MESURE d'un dépôt commence par demander si ce dépôt est verrouillé. S'il l'est, elle ne rend pas un nombre plus petit : elle rend l'aveu — « traitement en cours, mesure indisponible » — et l'écran l'affiche à la place du chiffre. Un verrou qui protège l'écriture sans avertir la lecture ne protège que la moitié du problème. |
| **composant** | `crg_integration.php` (`crgi_import_occupe()`), `crgi_pilotage.php`, `crgi_pilotage_vue.php` |
| **tests** | À COUVRIR — fixture : prendre le verrou d'un import fantôme, exiger que la mesure refuse de rendre un nombre. |
| **corpus** | un dépôt sur quatre, mesuré pendant son propre test de replay |
| **limites** | Le verrou ne couvre que les traitements qui le prennent. Une écriture faite hors moteur reste invisible à ce garde-fou. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0049 · Deux lecteurs de période coexistaient, et c'est le plus pauvre qui décidait

| | |
|---|---|
| **phénomène** | L'autorité de période et le lecteur d'éditeur connaissaient quatre formes de datation ; le DÉCOUPEUR de la phase 0, qui s'exécute en premier et décide, n'en cherchait qu'une — le trimestre nommé. Un même document était donc daté par l'un et rejeté par l'autre. |
| **preuve** | 06/09/2026 — **11 comptes rendus sur 947 sortaient sans période**, et la phase 0 refusait de se sceller : **435 CRG bloqués derrière eux**, sur deux dépôts entiers. Les onze imprimaient pourtant leur période : « CRG au 15.02.2026 », « Compte de Gestion au 30.06.2026 », « CRG du 13.05.26 au 30.06.26 », « 2e Trimestre **ex** 2026 ». Le lecteur d'éditeur les relevait toutes correctement — vérifié document par document. |
| **abstraction** | `DEUX LECTEURS DE LA MÊME CHOSE, C'EST UN LECTEUR DE TROP.` Le plus complet ne rattrape pas le plus pauvre : c'est celui qui parle en premier qui décide, et l'autre ne fait que confirmer ce qui a déjà été perdu. Le symptôme trompe — il ressemble à « le document ne dit pas sa période », alors que le document la dit et que personne ne la lui demande à cet endroit-là. |
| **portée** | `UNIVERSELLE` |
| **règle** | Une notation de période est déclarée UNE fois. L'ordre est celui de la CERTITUDE, jamais de la commodité : trimestre nommé, puis période imprimée en toutes lettres, puis clôture seule — qui date le document sans rien dire de sa granularité. Et la clôture s'ancre sur « CRG » ou « Compte de Gestion », jamais sur « au JJ.MM.AAAA » seul : « Report au 31.03.2026 » daterait la pièce sur le trimestre précédent. |
| **composant** | `crg_integration_phase0.py`, `crg_periode.py`, `crg_ics_core.py` |
| **tests** | À COUVRIR — fixture : les quatre notations, plus une ligne « Report au … » qui ne doit RIEN dater. |
| **corpus** | 11 documents bloquant 435 CRG |
| **limites** | Une clôture seule ne dit pas la granularité : le document est daté, sa couverture reste inconnue. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0050 · Un numéro de compte mandant est unique PAR AGENCE, pas dans la base

| | |
|---|---|
| **phénomène** | Le moteur déduisait l'agence d'un compte rendu à partir du compte mandant qu'il imprime, en cherchant ce code dans le référentiel. Or le même code est attribué indépendamment par chaque agence : deux propriétaires sans rapport peuvent porter le même numéro. |
| **preuve** | 07/09/2026 — trois codes du référentiel désignent DEUX propriétaires chacun : `02200000` est KIBLEPI à Lyon **et** CREMER à Riom ; `02320000` est DUMONT à Lyon **et** BLAUDY à Riom. Le moteur retenait celui qui portait une agence, et **quatre comptes rendus imprimant « 69007 LYON » sont partis à Riom**. Sur `01510000`, le concurrent du vrai propriétaire s'appelait **« - 1er Trimestre 2026 - »** — un libellé de période enregistré comme nom de personne par une extraction ratée, et seul des deux à porter une agence. |
| **abstraction** | `UNE DONNÉE FAUSSE PÈSE PLUS LOURD QU'UNE DONNÉE ABSENTE.` Entre deux candidats, tout algorithme qui préfère « celui qui est renseigné » choisit systématiquement l'erreur quand l'erreur est la seule renseignée. Et plus profondément : `UN IDENTIFIANT N'EST UNIQUE QUE DANS SON ESPACE DE NOMMAGE` — le supposer global est une hypothèse qu'aucune contrainte de base ne défend. |
| **portée** | `UNIVERSELLE` |
| **règle** | L'agence se lit sur le DOCUMENT avant de se déduire du référentiel. Le compte mandant ne sert qu'en dernier recours, quand le document ne dit ni son nom ni son adresse — et il ne tranche que si un seul candidat existe. |
| **composant** | `crg_integration.php` (`crgi_rapprocher_agences`) |
| **tests** | À COUVRIR — fixture : un code porté par deux propriétaires dans deux agences, dont un seul renseigné. |
| **corpus** | 3 codes en collision, 1 fiche fantôme, 4 comptes rendus détournés |
| **limites** | Ne détecte pas une collision à l'intérieur d'une même agence. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0051 · L'enseigne n'est pas la société, et le pied de page le dit

| | |
|---|---|
| **phénomène** | Un document ne porte pas toujours le nom de sa société en tête : il y met son **enseigne commerciale**, et relègue son identité légale — raison sociale, RCS, siège — en **pied de page**. Un moteur qui ne lit que l'en-tête voit un tiers inconnu là où il a affaire à sa propre maison. |
| **preuve** | 07/09/2026 — 194 comptes rendus imprimant « A1 - DE GASPERIS IMMOBILIER » ont été déclarés NON RATTACHÉS, et j'ai conclu à un « professionnel externe ». Leur pied de page disait : « Agence **DE GASPERIS**, 10 place Maréchal Foch, **69630 CHAPONOST** — **SARL REGIE EMERY**, siège social 10 place Maréchal Foch — RCS **398912766** ». Même RCS et même siège que le dépôt voisin, dont l'en-tête, lui, était reconnu. C'est Emmanuel qui l'a redressé : « il faut quand même que tu regardes l'en-tête OU LE PIED DE PAGE ». |
| **abstraction** | `L'IDENTITÉ LÉGALE ET LE NOM COMMERCIAL SONT DEUX CHOSES, ET ILS NE VIVENT PAS AU MÊME ENDROIT DE LA PAGE.` Une enseigne change, se cède, disparaît — « LOCA IMMO » est un nom abandonné qui figure encore sur les documents. Le RCS, lui, ne change pas. Et l'identification se fait à DEUX niveaux : le RCS ou le SIRET désigne la **société**, le code postal désigne l'**agence** à l'intérieur de cette société. |
| **portée** | `UNIVERSELLE` |
| **règle** | Chercher l'émetteur en **tête ET en pied** — l'un des éditeurs le met en haut, l'autre en bas. Résoudre la société par son RCS/SIRET, puis l'agence par le code postal dans cette société. Ne jamais conclure « externe » de la seule absence du nom commercial dans le référentiel. |
| **composant** | `crg_integration.php` (`crgi_agence_par_entete`) |
| **tests** | À COUVRIR — fixture : une enseigne inconnue en tête, l'identité légale en pied. |
| **corpus** | 194 comptes rendus déclarés étrangers à tort |
| **limites** | Un SIREN à 9 chiffres désigne la société, pas l'agence : il en faut le code postal pour trancher. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0052 · Les versements aux propriétaires ne dépassent jamais les encaissements

| | |
|---|---|
| **phénomène** | Une régie encaisse les loyers, prélève ses honoraires et les charges, puis reverse le solde au propriétaire. Le reversement ne peut donc jamais excéder l'encaissement de la même période. Aucun contrôle du moteur ne portait cette évidence. |
| **preuve** | 07/09/2026 — règle donnée par Emmanuel, appliquée telle quelle : **quatre périodes en violation immédiate**. Un dépôt à **7 392 %** (304 247 € reversés pour 4 116 € encaissés) — le défaut de lecture d'`APP-0047`, qui attendait depuis des heures et qu'aucun test vert n'avait signalé. Trois autres entre 113 % et 253 %, causées par des compensations comptées comme versements (`APP-0053`). |
| **abstraction** | `UN INVARIANT MÉTIER TROUVE CE QU'AUCUN TEST TECHNIQUE NE CHERCHE.` Les contrôles du moteur vérifiaient la cohérence interne — populations, empreintes, couverture — tous verts. Il manquait la question que le métier pose en premier : est-ce que ces deux nombres peuvent coexister ? Un ratio impossible se voit en une ligne de SQL ; il demande de connaître le métier, pas le code. |
| **portée** | `UNIVERSELLE` |
| **règle** | Sur chaque période et chaque compte : `versements propriétaire ≤ encaissements`. Au-delà de 100 %, alerte — pas correction : la cause peut être une lecture incomplète, une qualification fausse ou un décalage de période, et ce sont trois remèdes différents. |
| **composant** | à brancher — contrôle du bilan de la phase 4 |
| **tests** | À COUVRIR |
| **corpus** | 4 périodes en violation sur 4 dépôts |
| **limites** | Un reversement peut légitimement suivre l'encaissement d'une période antérieure : le contrôle doit dire « à examiner », jamais « faux ». |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0053 · Une compensation entre mandats n'est pas un versement

| | |
|---|---|
| **phénomène** | Une régie solde parfois des comptes entre eux : le mandat A est crédité de ce que le mandat B est débité, sans qu'un euro ne quitte l'agence. Ces écritures apparaissent en colonne CRÉDIT dans la section des soldes, exactement comme un vrai reversement. |
| **preuve** | 07/09/2026 — **534 847,60 € sur 2 790 132,12 €**, soit **19,2 % des « versements »** d'un dépôt. Toutes portent le même libellé en crédit sur un compte et en débit sur un autre, dans le même document : « COMPENSATION VERS GPE SIR STE » 245 000 / 130 000, « COMPENSATION GPE SIR STE par GPE IMMO » 100 000 / 100 000. |
| **abstraction** | `UNE COMPENSATION SE PROUVE PAR SA STRUCTURE, PAS PAR SON LIBELLÉ.` Le mot « compensation » est écrit « COMPENDSATION » sur trois de ces lignes — et de toute façon `NE JAMAIS DÉDUIRE UNE NATURE D'UN LIBELLÉ`. Ce qui la démontre est l'appariement : **le même libellé, en crédit ici et en débit là, dans le même dépôt**. C'est un fait de structure, insensible à l'orthographe. |
| **portée** | `UNIVERSELLE` |
| **règle** | Une ligne de crédit dont le libellé existe aussi en débit dans le même dépôt n'est pas un flux vers l'extérieur. Elle reste enregistrée, avec sa nature propre — jamais additionnée aux versements. |
| **composant** | à brancher — qualification de la phase 4 |
| **tests** | À COUVRIR — fixture : une paire crédit/débit de même libellé, un vrai versement isolé. |
| **corpus** | 534 847,60 € sur un dépôt, 2 018 € sur un autre, aucun sur les deux derniers |
| **limites** | Deux opérations réelles de même libellé et de sens opposés seraient prises pour une compensation : l'appariement doit exiger le même montant. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0054 · Un total de dépôt additionne des périodes qui se recouvrent

| | |
|---|---|
| **phénomène** | Un dépôt contient des comptes rendus de périodes différentes, et ces périodes **se chevauchent** : un relevé d'avril, et un relevé d'avril-mai qui réénonce le même avril. Additionner l'argent de tout le dépôt compte donc avril deux fois. |
| **preuve** | 07/09/2026 — j'ai produit une colonne « encaissements » par dépôt. Emmanuel : « les encaissements de Chaponost sont trop élevés ». Il avait raison : 2 235 175 € additionnaient **huit tranches** dont quatre à cheval ; le trimestre réel est **2 000 315 €**. Sur un autre dépôt, le total couvrait **six trimestres**, de 2025-T1 à 2026-T2. Sur un troisième, mensuel, quatre cycles dont deux recouverts. Le bilan du moteur REFUSE de produire ce total, et son code le dit ; je l'ai produit dans une agrégation à côté. |
| **abstraction** | `UNE INTERDICTION DANS LE MOTEUR NE PROTÈGE PAS CE QUI SE CALCULE À CÔTÉ.` La règle était connue, écrite, respectée par le code métier — et contournée par un `SUM()` d'analyse en trois lignes. Une doctrine qui ne vit que dans une fonction ne défend que cette fonction. |
| **portée** | `UNIVERSELLE` |
| **règle** | Aucun total d'argent n'est rendu à la maille du dépôt. Toute somme se rend PAR PÉRIODE, et deux dépôts ne se comparent qu'à granularité identique — un mensuel ne se met pas en face d'un trimestriel sans le dire. |
| **composant** | `crg_integration.php` (`crgi_bilan_phase4`), et toute lecture d'analyse |
| **tests** | À COUVRIR — fixture : deux CRG d'un même compte dont les périodes se recouvrent. |
| **corpus** | quatre dépôts, quatre structures de période différentes |
| **limites** | Le chevauchement se voit sur les bornes déclarées ; deux périodes disjointes qui réénoncent le même mois passeraient inaperçues. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0055 · « Compte inconnu » nommait à la fois un fait normal et une question

| | |
|---|---|
| **phénomène** | L'inventaire range sous une SEULE étiquette deux situations opposées : le compte qui n'existe dans aucun système de MBI — un **mandant nouveau**, fait normal et démontré — et le compte dont le code existe dans un AUTRE système — un **homonyme**, sur lequel le moteur ne sait pas trancher. Le nom choisi, « COMPTE INCONNU », suggère une anomalie là où il n'y en a le plus souvent aucune. |
| **preuve** | 07/09/2026 — j'ai présenté « 239 comptes inconnus » comme autant de questions à arbitrer, dont « 194 sur 194 » pour un dépôt entier. Emmanuel : « **un compte non connu dans MBI n'est pas une erreur, c'est un nouveau !** ». Mesure : **233 mandants nouveaux** et **6 homonymes**. Les 194 d'un dépôt sont simplement une agence jamais intégrée. La file d'arbitrage de la phase 1 passe de 239 à **6**. |
| **abstraction** | `UNE ÉTIQUETTE QUI COUVRE DEUX SITUATIONS OPPOSÉES EN FABRIQUE UNE TROISIÈME, QUI N'EXISTE PAS.` Le moteur distinguait pourtant les deux cas — le motif le disait en toutes lettres — mais le STATUT, lui, les fondait ; et c'est le statut que lisent les écrans, les compteurs et les files. Une nuance qui ne vit que dans un texte libre n'existe pour aucun automatisme. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un vocabulaire de statut ne contient que des états MUTUELLEMENT EXCLUSIFS et de même nature. `NOUVEAU MANDANT` est un RÉSULTAT — reconnu à 100 %, reconnu comme nouveau, il ne va pas en arbitrage (`INTEG-ARBITRAGE-00`). `HOMONYME` est une QUESTION. Les fondre revenait à envoyer 233 faits normaux dans une file de décisions. |
| **composant** | `crg_integration.php` (`crgi_phase1`), `CRGI_VOCABULAIRE['crgi_crg.inventaire_statut']` |
| **tests** | À COUVRIR — fixture : un compte absent de tout système, un code présent dans un autre système. |
| **corpus** | 239 « comptes inconnus » = 233 nouveaux mandants + 6 homonymes |
| **limites** | La distinction repose sur le couple `(code, système)` : deux mandants réellement distincts dans le MÊME système resteraient indiscernables. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0056 · Un compte rendu qui ne produit aucun objet n'est pas un résultat vide

| | |
|---|---|
| **phénomène** | Un CRG parfaitement lu — compte, propriétaire, trimestre, agence — peut ne rendre NI immeuble NI lot. Le moteur produisait alors zéro objet et **se taisait** : aucun contrôle ne comparait « comptes rendus porteurs » à « comptes rendus ayant produit un objet ». Le document disparaissait dans un résultat nul. |
| **preuve** | 07/09/2026 — **19 CRG sur 947**, dont 10 sur un dépôt et 8 sur un autre. Cas type : un compte rendu d'une page, « Report au 31.12.2025 : 33,60 € », solde créditeur 33,60 €, rien d'autre. Emmanuel : « pas d'appel de loyer, alors il n'y a plus de locataire actif : soit appartement vacant, soit perte de gestion, et tu ne peux pas le voir, **il faut poser la question** ». |
| **abstraction** | `UNE ABSENCE D'OBJET EST UNE QUESTION, PAS UN RÉSULTAT.` Un moteur qui rend « rien » sans le dire fait disparaître le document aussi sûrement qu'une erreur de lecture — mais sans laisser de trace, donc sans que personne puisse le voir. Et les causes possibles ne se départagent PAS depuis le document : ce sont des événements commerciaux que seul le gestionnaire connaît. |
| **portée** | `UNIVERSELLE` |
| **règle** | Tout CRG porteur sans aucun objet de patrimoine part en question, avec ses réponses possibles : **BIEN VENDU**, **BIEN VACANT**, **GESTION TERMINÉE**, **COMPTE TECHNIQUE** (coquille servant à payer hors gestion) et **QUOTE-PART D'INDIVISION** (`APP-0057`). Les trois premières sont des ÉVÉNEMENTS ; les deux dernières des NATURES DE COMPTE, permanentes. |
| **composant** | `crg_integration.php` (`crgi_crg_sans_patrimoine`, `crgi_decider_crg`), `admin_crgi_phase.php` |
| **tests** | À COUVRIR — fixture : un CRG ne portant qu'un report. |
| **corpus** | 19 sur 947 ; réponses d'Emmanuel : 7 gestion terminée, 4 vendus, 2 comptes techniques, 1 vacant, 5 quotes-parts |
| **limites** | Le moteur détecte l'absence ; il ne saura jamais la cause. La réponse est humaine par nature. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0057 · L'indivisaire n'a ni lot ni immeuble — il a une quote-part, et le document l'imprime

| | |
|---|---|
| **phénomène** | Dans une indivision, le bien et le locataire sont portés par le compte de L'INDIVISION. Chaque indivisaire a son propre compte mandant, **sans aucun patrimoine**, qui ne reçoit qu'une part du résultat. Ce compte ressemble donc trait pour trait à une coquille vide — et n'en est pas une : son titulaire est payé par virement, le document donne son IBAN. |
| **preuve** | 07/09/2026 — j'ai proposé « COMPTE TECHNIQUE » pour 15 des 19 CRG sans patrimoine, sur le seul fait qu'ils n'avaient jamais porté d'immeuble. Emmanuel n'en a retenu que **2**. Cinq étaient des indivisaires, et le document le disait en toutes lettres : « **50/100 de Indivision LAMUGNIERE** », « **54/100 de Indivision GUINARD** », « **50/100 de Indivision KIBLEPI** ». Les indivisions correspondantes existent comme mandants porteurs dans le MÊME dépôt — `04110000` et `06470000` pour LAMUGNIERE, `02190000` pour KIBLEPI. Et les deux quotes-parts LAMUGNIERE font **50 + 50 = 100**. |
| **abstraction** | `UN FAIT NÉGATIF NE DISTINGUE PAS DEUX CAUSES.` « Ce compte n'a jamais porté d'immeuble » est vrai d'une coquille comptable ET d'un indivisaire ET d'un mandat qui vient de partir : il ne sépare rien. Le fait qui sépare était POSITIF et imprimé — la ligne de quote-part. Une proposition fondée sur une absence se trompe autant de fois qu'il existe de causes à cette absence. |
| **portée** | `UNIVERSELLE` |
| **règle** | La quote-part se démontre par DEUX conditions, jamais une : la ligne imprimée « *N/100 de …* » **et** l'existence de l'indivision nommée comme compte porteur d'immeubles dans le corpus. Elle donne alors un LIEN et un TAUX — pas seulement une nature — et les quotes-parts d'une même indivision doivent totaliser 100. |
| **composant** | `crg_integration.php` — cinquième réponse et sa détection |
| **tests** | À COUVRIR — fixture : un CRG sans patrimoine portant « 50/100 de Indivision X », avec et sans l'indivision présente. |
| **corpus** | 5 indivisaires sur 3 indivisions, dans 2 dépôts et 2 sociétés différentes |
| **limites** | Une indivision absente du dépôt rend la quote-part indémontrable : elle reste une question. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0058 · Un solde reporté n'est pas un événement de la période

| | |
|---|---|
| **phénomène** | Un compte rendu continue d'être édité tant qu'un solde n'est pas apuré, longtemps après la fin de la gestion. Sa seule ligne est alors un REPORT, reconduit à l'identique de trimestre en trimestre. La date du document ne dit donc rien de la date de l'événement qui l'a causé. |
| **preuve** | 07/09/2026 — j'allais annoncer « **7 mandats perdus au T1 2026** » comme signal commercial, sur la foi de sept réponses « gestion terminée » portant toutes ce trimestre. Emmanuel : « **non, ils ne sont pas tous perdus à cette époque, c'est les soldes qui restent de trimestre en trimestre** ». Mesuré : un compte porte **22,00 € au T1 et 22,00 € au T2** — le même solde, sans un seul mouvement. Six des sept portent un report : 31,03 € · 55,57 € · 24,47 € · 55,00 € · 33,60 €. |
| **abstraction** | `LA DATE D'UN DOCUMENT N'EST PAS LA DATE DE CE QU'IL RACONTE.` Compter les documents d'un trimestre pour mesurer les événements de ce trimestre transforme une **traîne comptable** en pic d'activité. L'erreur est d'autant plus tentante que le nombre est juste — sept documents existent bien — et que seul son NOM est faux. C'est `APP-0046` transposé au temps. |
| **portée** | `UNIVERSELLE` |
| **règle** | Aucune lecture commerciale ne se prend sur la date d'un compte rendu dont la seule opération est un report. Et surtout : une décision portant sur un compte de ce type est **PERMANENTE et porte sur le COMPTE MANDANT, jamais sur le document** — sinon la même question revient à chaque trimestre. Le cas mesuré a déjà dû être tranché deux fois, une par trimestre. |
| **composant** | `crg_integration.php` (`crgi_decider_crg` — cible à porter sur le compte), `admin_crgi_phase.php` |
| **tests** | À COUVRIR — fixture : deux CRG successifs d'un même compte portant le même report. |
| **corpus** | 1 compte tranché deux fois, 6 reports sur 7 « gestion terminée » |
| **limites** | Un report identique ne prouve pas l'absence totale de mouvement : il prouve que le solde n'a pas bougé. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0059 · Une question sans guichet est une perte, et un écran peut perdre une réponse en silence

| | |
|---|---|
| **phénomène** | Trois défauts d'écran, tous de la même famille : le moteur pose une question, l'humain veut répondre, et la réponse n'arrive nulle part — sans que rien ne le signale. |
| **preuve** | 07/09/2026, dans l'ordre où ils sont apparus. ① L'écran de suivi affichait les questions **en lecture seule**, les décisions étant censées se prendre « dans la file » — sauf que la file ne connaît que quatre cibles, toutes nées des phases 2 à 5. Les questions portant sur le COMPTE RENDU n'avaient aucun guichet. Emmanuel : « **je ne vois pas où mettre mes réponses** ». ② Le formulaire créé pour y répondre postait vers l'ACCUEIL : le gabarit pose un `<base href>`, et une action écrite « ?phase=2 » se résout contre la racine, pas contre la page. Emmanuel : « **quand je valide je suis déconnecté** » — et sa décision était perdue sans un mot. ③ Le lien « voir la preuve » répondait « CRG INTROUVABLE » sur un document parfaitement présent : l'identifiant venait du bac à sable, la page le cherchait dans la base réelle. |
| **abstraction** | `UNE QUESTION SANS GUICHET N'EST PAS UNE QUESTION, C'EST UNE PERTE.` Et le corollaire, plus grave : **un guichet qui avale la réponse sans le dire est pire que pas de guichet**. Dans les trois cas l'écran répondait « HTTP 200 » — rien n'était en erreur, tout était perdu. Un lien de preuve qui ne prouve rien fait douter du DOCUMENT au lieu de faire douter du lien. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute question affichée porte son moyen de réponse sur le même écran. Toute URL d'une page sous `<base href>` est ABSOLUE, via `app_url()` — jamais « ?param= » seul. Et tout lien de preuve transporte la base qui a produit l'identifiant. |
| **composant** | `admin_crgi_phase.php`, `admin/crgi_page.php` |
| **tests** | À COUVRIR — un POST réel doit revenir sur la page et laisser une trace en base. |
| **corpus** | 3 défauts, 19 questions concernées, 1 décision perdue avant correction |
| **limites** | Le `<base href>` vit dans le gabarit commun : tout nouvel écran hérite du piège. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0060 · Les arbitrages de patrimoine venaient de la base d'essai, pas des documents

| | |
|---|---|
| **phénomène** | La phase 2 sortait 190 objets « à arbitrer » — 160 immeubles et 30 lots. Présentés tels quels, ils ressemblent à un défaut de lecture. Ils ne viennent pas des documents : ils viennent de **doublons dans la base de confrontation**. |
| **preuve** | 07/09/2026 — les motifs le disaient déjà : « MBI porte 2 FOIS le même immeuble », « 2 biens de MBI portent cette référence ». Mesuré : **190 occurrences pour 46 causes distinctes** — 23 adresses en double, 10 codes de gestion, 7 couples nom+code postal, 6 références de biens ; un même doublon revient 6 fois parce que 6 comptes rendus le mentionnent. Un cas type : la même référence de lot sur deux biens du même propriétaire, rattachés à deux immeubles différents. Emmanuel : « **je te rappelle que nous partons sur une base vierge, et que le local a de nombreux doublons de test** ». Vérifié en rejouant les quatre corpus contre un référentiel vide : **159 → 0** et **30 → 0**. |
| **abstraction** | `UN NOMBRE D'ARBITRAGES NE MESURE PAS LE MOTEUR TANT QU'ON N'A PAS NOMMÉ CE QUI LES CAUSE.` Compter les occurrences au lieu des causes multiplie l'ampleur perçue par six ; et attribuer au lecteur ce qui vient de la base à laquelle on le confronte lui fait porter une faute qui n'est pas la sienne. Le moteur ne se trompait pas : il refusait correctement de deviner entre deux fiches identiques. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un arbitrage se compte en CAUSES, pas en occurrences, et se rattache toujours à sa source : document ou base. Ceux qui viennent de la base ne mesurent pas le moteur — et disparaissent sur une base vierge, ce qui doit être vérifié plutôt qu'affirmé. |
| **composant** | mesure, `crgi_pilotage.php` |
| **tests** | COUVERT INDIRECTEMENT — la séparation DOCUMENT / MBI existe déjà dans la file. |
| **corpus** | 190 occurrences, 46 causes, 0 sur base vierge |
| **limites** | La preuve « 0 sur base vierge » vaut pour ce corpus ; un doublon PORTÉ PAR LE DOCUMENT resterait. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0061 · Trois colonnes ont suffi à perdre le nom d'une indivision

| | |
|---|---|
| **phénomène** | Le nom du propriétaire se lit dans le BLOC ADRESSE, calé à droite. Une indivision n'a pas d'adresse : le document n'imprime que sa raison sociale, **seule et centrée**. Elle tombe donc entre la colonne de gauche — qu'on écarte — et le bloc de droite — qu'on exige. |
| **preuve** | 07/09/2026 — marge du document colonne 17, seuil du bloc adresse colonne 47, nom de l'indivision colonne **44**. **Rejeté pour trois colonnes**, en silence. Mesuré : **3 comptes rendus sur 947** entrés sans nom de propriétaire, dont un portant **NEUF immeubles**. C'est Emmanuel qui l'a mis au jour en montrant les fiches de son logiciel : l'indivision GUINARD existe, porte son immeuble et son locataire — le moteur la voyait anonyme. |
| **abstraction** | `UN DÉFAUT DE LECTURE EN AMONT FABRIQUE UNE QUESTION EN AVAL.` L'absence de nom n'a produit aucune alerte à la phase 0 ; elle a produit, deux phases plus loin, une question d'arbitrage insoluble — la quote-part de l'indivisaire ne pouvait pas être démontrée, faute d'indivision nommée à laquelle la rattacher. On cherchait la cause dans la phase 2 ; elle était dans la phase 0. |
| **portée** | `UNIVERSELLE` |
| **règle** | **On ne baisse pas le seuil, on en ajoute un second.** Descendre le seuil de droite ferait rentrer la colonne de GAUCHE — mentions légales, « COMPTE PERSONNEL » — dans la fenêtre du bloc adresse, sur TOUS les documents. Le seuil « centré » ne s'ouvre que si le premier n'a rien rendu : un nom centré est un dernier recours, jamais un concurrent du bloc adresse. |
| **composant** | `crg_integration_phase0.py` (`_proprietaire_lyon`), `crg_texte.py` (`ECART_COLONNE_CENTREE`) |
| **tests** | ÉPROUVÉ sur 4 gabarits — l'indivision centrée, un propriétaire ordinaire à droite, et deux variantes lyonnaises : 4/4 sans régression. |
| **corpus** | 3 CRG sur 947, 11 immeubles concernés |
| **limites** | Un nom centré ET une ligne parasite centrée dans la même fenêtre : la première gagne. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0062 · L'indivisaire peut aussi détenir en propre dans le même immeuble

| | |
|---|---|
| **phénomène** | Une indivision n'est pas une simple étiquette sur un propriétaire : c'est un **mandant à part entière**, avec son compte, ses mandats et ses locataires. Chaque indivisaire a en plus son propre compte, sans patrimoine, qui ne reçoit qu'une quote-part. Et rien n'empêche un indivisaire de détenir, **en son nom propre**, d'autres lots dans le **même immeuble**. |
| **preuve** | 07/09/2026, vérifié sur les fiches du logiciel source et retrouvé dans le corpus. L'indivision porte son compte, deux mandats sur « 17 avenue de la République » et un locataire depuis le 30/06/2015. Un des deux indivisaires porte son compte personnel **et** un mandat propre sur le MÊME immeuble, avec un autre locataire depuis le 01/02/2018. La seconde indivisaire n'a que son compte personnel et sa quote-part de 54/100. |
| **abstraction** | `TROIS LIGNES SUR LE MÊME IMMEUBLE NE SONT PAS TROIS DOUBLONS.` L'indivision, l'indivisaire qui détient en propre, et l'indivisaire qui ne détient rien sont **trois mandants distincts** — et la ressemblance de leurs noms est précisément ce qui pousse à les confondre. C'est la règle du nouveau lot poussée à son cas limite : la nouveauté se juge **lot par lot**, jamais par héritage du propriétaire ni de l'immeuble. |
| **portée** | `UNIVERSELLE` |
| **règle** | Ne jamais fondre un indivisaire dans son indivision, ni un lot détenu en propre dans un lot de l'indivision. Le rattachement de l'argent suit le COMPTE MANDANT du compte rendu, jamais la ressemblance des noms. |
| **composant** | doctrine — s'applique aux phases 1, 2 et 3 |
| **tests** | À COUVRIR — fixture : un immeuble portant un lot d'indivision et un lot d'indivisaire, avec deux occupants. |
| **corpus** | 1 immeuble, 3 mandants, 2 locataires |
| **limites** | La structure ne se lit pas sur le CRG seul : c'est le rapprochement des comptes qui la révèle. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0063 · L'appel de loyer a deux écritures, et on n'en lisait qu'une

| | |
|---|---|
| **phénomène** | Le compteur d'appels ne cherchait que la forme ICS `Du 01.01.26 Au 31.01.26`. Les éditeurs SPI n'écrivent jamais cela : ils écrivent `TERME Mai 2026`, `TERME du 17/04/2026 au 30/04/2026`, `Loyer du 01/06/2026 au 15/06/2026`, `Provisions pour charges du … au …`. Le fait qui tranche l'occupation n'était donc **jamais lu** sur ces documents. |
| **preuve** | 07/09/2026, mesuré sur les quatre dépôts : `appels` à zéro pour **402/402** occupations d'une agence et **480/480** d'une autre — **882 sur 2 839, soit 31 % du corpus**. Un taux d'échec de 100 % sur deux dépôts entiers et de 0 % sur les deux autres : ce n'est pas une propriété des immeubles, c'est une graphie non lue. |
| **abstraction** | `UN CONTRÔLE QUI ÉCHOUE À 100 % NE DÉCRIT PAS LE MONDE, IL DÉCRIT SA PROPRE CÉCITÉ.` Une valeur nulle uniformément répartie sur un éditeur et jamais sur l'autre est un défaut de lecture, pas un fait métier. Corollaire codé : on n'applique aucune règle fondée sur les appels dans un compte rendu qui n'en porte **aucun**. |
| **portée** | `UNIVERSELLE` |
| **règle** | Tout fait qui décide d'un statut doit être lu dans **toutes** les graphies du corpus avant d'être opposé. Vérifier la couverture par éditeur AVANT de conclure : un fait absent partout chez l'un et présent partout chez l'autre n'est pas un fait. **Et c'est la NATURE qui fait l'appel, pas la date** : un bloc est plein de dates qui n'appellent rien (`Bail du …`, `Budget prévisionnel …`, `Gratuité de loyer jusqu au …`, `Remb LOYER TROP VERSÉ`). On ne cherche une période que sur une ligne qui COMMENCE par une nature d'appel. |
| **composant** | `crg_integration_phase3.py::appels_du_bloc()`, `crg_integration_ics.py::occupations()` |
| **tests** | Fixture : les six graphies (`TERME <mois>`, `TERME du…au…`, `Loyer <mois> <année>`, `Loyer du…au…`, `Du…Au…`, `Dépôt de garantie`) doivent produire un appel ; les quatre pièges ci-dessus doivent en produire zéro. |
| **corpus** | 882 occupations sur 2 839 au premier constat, puis **418 sur 480** d'un dépôt au second |
| **limites** | La correction s'est faite **en deux fois**, et c'est l'enseignement dans l'enseignement : après avoir ajouté `TERME <mois>`, un dépôt restait à 13 % de couverture — le mois nommé n'appartient pas au terme commercial, un CRG mensuel d'habitation écrit `Loyer Juillet 2026`. Mesurer la couverture APRÈS correction est ce qui a rattrapé la seconde cécité ; s'arrêter au premier correctif l'aurait laissée passer. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0064 · Un appel n'est pas forcément un loyer, ni forcément de la période

| | |
|---|---|
| **phénomène** | Deux erreurs symétriques. **(a)** Un bloc sans loyer peut appeler un **dépôt de garantie** : loyer gratuit à l'entrée, mais la garantie est bien demandée — l'appel n'est pas vide. **(b)** Un bloc peut porter des lignes `Du 01.01.25 Au 31.12.25` dans un rapport du 1er trimestre 2026 : ce sont des **régularisations de l'exercice passé**, comptées à tort comme des appels de la période. |
| **preuve** | 07/09/2026. (a) Une entrante au bail du 25/06/2026 : `Gratuité de loyer jusqu au 31.05.2026`, aucun terme, mais `Dépôt de garantie 1 070,00`. (b) Un locataire dont les **trois** seules lignes datent de l'exercice précédent passait pour présent au trimestre courant. |
| **abstraction** | `UN APPEL COMPTE SI SA PÉRIODE RECOUPE CELLE DU COMPTE RENDU.` Le rattachement d'un fait à une période ne se lit ni sur sa présence dans le document, ni sur sa nature — seulement sur l'intersection des deux intervalles. Et un appel sans période (le dépôt de garantie) prouve une présence sans jamais fixer une fin. |
| **portée** | `UNIVERSELLE` |
| **règle** | Filtrer tout fait daté sur l'intersection avec la période du document avant de le compter. Un dépôt de garantie **remboursé** (`Rembt D G reversé`) annule l'appel d'entrée : il accompagne un départ. |
| **composant** | `crg_integration.php::crgi_appels_de_la_periode()` |
| **tests** | Fixture : un bloc dont tous les appels précèdent la période doit rendre 0 ; un bloc au seul dépôt de garantie doit rendre 1 sans date de fin. |
| **corpus** | 1 entrante en gratuité, 1 locataire à régularisations seules — mesuré sur les 17 arbitrages ouverts |
| **limites** | Un appel annuel `Du 01.01.26 Au 31.12.26` recoupe tout trimestre de 2026 et prolonge donc la fin lue. Choix assumé : il penche vers `en place`, du côté que `ABSENCE ≠ DÉPART` protège. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0065 · La fin du dernier appel date le départ ; l'absence ne le contredit jamais

| | |
|---|---|
| **phénomène** | Un bail qui s'achève en cours de trimestre se lit sur la **période tronquée du dernier appel**, pas sur une absence ultérieure. Symétriquement, un bloc qui appelle **jusqu'à la date d'arrêté** démontre une présence qu'aucun rapport suivant ne contredit — et le contrôle d'absence ne doit pas se déclencher. |
| **preuve** | 07/09/2026. Un locataire appelle `Du 01.02.26 Au 09.02.26` dans un rapport arrêté au 31/03/2026 : la même page porte `Rembt D G reversé` et des honoraires d'état des lieux de **sortie** — trois preuves concordantes. Un autre appelle son loyer `du 01/06/2026 au 15/06/2026` pour un arrêté au 30/06. À l'inverse, **six lots** portaient `TERME Avril / Mai / Juin 2026` et étaient mis en arbitrage parce qu'un compte rendu de **deux jours** (30/06 → 01/07), émis pour enregistrer le dépôt de garantie d'une entrante sur un **autre lot**, portait à lui seul l'horizon du compte. |
| **abstraction** | `LA PÉRIODE APPELÉE EST UNE PREUVE ; L'ABSENCE N'EN EST PAS UNE.` `ABSENCE ≠ DÉPART DÉMONTRÉ` protège dans les deux sens : elle interdit de conclure au départ sur un silence, et elle interdit tout autant à un silence de démentir un appel lu. Un rapport ultérieur qui ne parle pas d'un lot ne dit rien contre ce lot. |
| **portée** | `UNIVERSELLE` |
| **règle** | Trois lectures, une seule règle : les appels couvrent jusqu'à l'arrêté → **en place**, aucune question ; ils s'arrêtent avant → **parti**, à la date du dernier appel ; aucun appel sur la période → **parti**, démontré. Un horizon porté par un compte rendu partiel ne met jamais en question un lot qu'il ne couvre pas. |
| **composant** | `crg_integration.php::crgi_qualifier_occupation()` |
| **tests** | Fixture : trois blocs (appel jusqu'à l'arrêté, appel tronqué, aucun appel) sur un lot dont un rapport ultérieur ne parle pas — trois verdicts distincts, zéro arbitrage. |
| **corpus** | 17 arbitrages ouverts, dont 12 réglés sur preuve lue |
| **limites** | Un motif écrit de réduction du loyer (`Gratuité`, `Remise sur Loyer`) accompagne une période tronquée sans qu'il y ait départ : la nuance n'est pas encore codée, elle reste un arbitrage. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0066 · Une décision prise à une phase doit fermer la question aux suivantes

| | |
|---|---|
| **phénomène** | Un compte mandant tranché en phase 2 (« bien vendu ») voyait la phase 3 reposer des questions sur ses lots. La mémoire durable n'était consultée que par la phase qui l'avait écrite. |
| **preuve** | 07/09/2026 : un compte classé `BIEN VENDU` en phase 2 produisait encore **deux** arbitrages d'occupation en phase 3. Emmanuel : « il y a des documents qui sont exclus de l'analyse dans les phases précédentes que tu donnes en erreur à arbitrer ». |
| **abstraction** | `UNE MÉMOIRE QU'UNE SEULE PHASE INTERROGE N'EST PAS UNE MÉMOIRE, C'EST UNE NOTE.` Le coût d'une décision non propagée n'est pas la question elle-même : c'est la perte de confiance dans le fait d'avoir répondu. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute phase consulte `crgi_identite` sur les clés que les phases antérieures ont pu écrire, avant de poser sa propre question. |
| **composant** | `crg_integration.php::crgi_qualifier_occupation()` |
| **tests** | Fixture : une décision de phase 2 sur un compte, puis une phase 3 sur un lot de ce compte — zéro question. |
| **corpus** | 2 arbitrages sur 17 |
| **limites** | Seules les décisions de type `COMPTE-SANS-PATRIMOINE` sont propagées ; les autres familles restent à câbler. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0067 · Une règle juste appliquée à une lecture incomplète produit des faits faux

| | |
|---|---|
| **phénomène** | La règle « aucun appel de loyer ⇒ le locataire est parti » est **exacte**, et elle a pourtant fabriqué des centaines de faux départs. Parce qu'elle repose sur une lecture dont la couverture n'est **pas uniforme** : là où le lecteur ne sait pas lire les appels, il rend zéro — et zéro se lit comme une preuve alors que c'est une ignorance. |
| **preuve** | 07/09/2026. Appliquée telle quelle, elle a prononcé **871 départs sur « aucun appel »**. Le contrôle qui l'a démasquée : **518 locataires déclarés partis réapparaissaient sous le MÊME nom, sur le MÊME lot, à une période POSTÉRIEURE** — une contradiction que le moteur produisait contre lui-même. La couverture du lecteur variait de 48 % à 95 % selon le dépôt ; sur le dépôt à 48 %, **251 blocs sans appel étaient le SEUL occupant de leur lot** — donc sans rien à quoi se comparer. |
| **abstraction** | `UN ZÉRO MESURÉ ET UN ZÉRO NON LU S'ÉCRIVENT PAREIL.` Toute règle bâtie sur une absence doit d'abord démontrer que la présence, elle, aurait été VUE. La garde par document ne suffit pas : un compte rendu où 40 lots sur 100 portent des appels lus la franchit, et les 60 autres passent pour vides. **Et le contrôle qui sauve n'est pas une relecture : c'est la recherche d'une CONTRADICTION INTERNE** — un fait que le moteur affirme et qu'il dément lui-même ailleurs. |
| **portée** | `UNIVERSELLE` |
| **règle** | L'absence ne démontre que **par CONTRASTE, à granularité égale** : un bloc muet à côté d'un bloc qui appelle, sur le même lot et au même arrêté. Un vide SEUL ne conclut pas — il pose la question, comme demandé : « soit appartement vacant, soit perte de gestion, et tu ne peux pas le voir, IL FAUT POSER LA QUESTION ». |
| **composant** | `crg_integration.php::crgi_qualifier_occupation()` |
| **tests** | Contrôle permanent, désormais CODÉ dans `crgi_phase3()` : compter les observations déclarées `PARTI` dont le titulaire **APPELLE encore** plus tard sur le même lot. Isolé → on redresse en disant pourquoi ; au-delà de **2 % des départs** → la phase refuse de se sceller. |
| **corpus** | 871 départs prononcés, 518 réapparitions, dont **19 vraies contradictions** |
| **limites** | ⚠️ **Le contrôle lui-même s'est trompé d'abord, et c'est le second enseignement.** Défini comme « le NOM réapparaît », il comptait **486 contradictions** là où il n'y en avait que 9 : un ancien locataire reste au compte rendu tant que sa dette n'est pas apurée — une locataire dont le bail finit le 01/01/2026 reparaît en avril, mai, juin, juillet, sans un appel, avec son encours (`INTEG-P2-SOLDE-REPORTE` au niveau du locataire). **La contradiction n'est pas la réapparition du NOM, c'est la réapparition d'un APPEL** : un partant ne redemande pas son loyer. Un contrôle mal défini crie aussi fort qu'un vrai défaut. Enfin, il ne voit que les contradictions internes au dépôt : un faux départ en toute dernière période reste invisible. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0068 · Ce qui appartient au bloc ne s'attribue pas au premier nom imprimé

| | |
|---|---|
| **phénomène** | Un bloc de lot peut nommer plusieurs occupants successifs. Ses appels de loyer appartiennent **au bloc**, pas à l'un d'eux — et les donner au premier imprimé, par analogie avec le solde, fait dire au document l'inverse de ce qu'il dit. |
| **preuve** | 07/09/2026. Sur un lot : le premier bloc nomme une locataire dont le **bail s'est achevé en juin 2025** et reçoit 3 puis 6 appels en avril et mai 2026 ; le second nomme la locataire entrée en **novembre 2025** et n'en reçoit aucun. Dès que la première cesse d'être imprimée, la seconde reçoit 3 appels. Les appels étaient les siens depuis le début, et la règle du contraste déclarait partie la seule qui fût présente. |
| **abstraction** | `ON PRÉFÈRE NE RIEN SAVOIR À SAVOIR FAUX.` Une donnée de bloc n'est attribuable à un occupant que si le bloc n'en porte qu'un. Sans attribution, aucun contraste ne se forme et la chronologie décide comme avant : on perd une preuve, on n'en fabrique pas une fausse. C'est la même faute que « le rang vaut chronologie », déplacée du temps vers l'argent. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute donnée lue au niveau du bloc (appels, solde, totaux) n'est portée par un occupant que si le bloc en compte exactement un. Sinon elle reste au bloc, et aucune règle ne s'en sert pour départager ses occupants. |
| **composant** | `crg_integration_phase3.py::observer()` |
| **tests** | Fixture : un bloc à deux occupants et trois appels — aucun des deux ne doit porter d'appel. |
| **corpus** | 3 faux départs sur un dépôt |
| **limites** | La chronologie reste construite par RANG quand un lot porte deux occupants au même arrêté : l'observation de rang 1 de la période N se compare au rang 0 de la période N+1. Défaut connu, non corrigé — le contrôle de contradiction le rattrape et le dit. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0069 · Un seuil sans ses deux points de mesure est une superstition

| | |
|---|---|
| **phénomène** | J'ai fixé à **2 %** le taux de contradictions au-delà duquel une phase refuse de se sceller. Ce chiffre n'était appuyé sur rien : il faisait échouer des dépôts sains et aurait fini par être désarmé pour cette raison — c'est ainsi que meurent les contrôles. |
| **preuve** | 07/09/2026. Les deux points que le seuil doit séparer, tous deux mesurés : une **règle fausse** produit **518 contradictions sur 871 départs, soit 59 %** ; les mêmes dépôts, une fois les quatre défauts réels corrigés, retombent à **0 %** sur l'un et **8,6 %** sur le plus petit — et ces cas-là sont nommés un par un, pas une famille. |
| **abstraction** | `UN SEUIL SE CALIBRE ENTRE DEUX MESURES, IL NE SE CHOISIT PAS.` Un contrôle trop strict n'est pas un contrôle prudent : c'est un contrôle qu'on finira par débrancher. Le nombre doit être encadré par un cas connu qui doit passer et un cas connu qui doit échouer, et les deux doivent être écrits à côté de lui. |
| **portée** | `UNIVERSELLE` |
| **règle** | Aucun seuil numérique n'entre dans le moteur sans ses deux points de mesure consignés au point d'emploi. Un seuil sans eux est à supprimer, pas à ajuster. |
| **composant** | `crg_integration.php::CRGI_DEPARTS_CONTREDITS_MAX` |
| **tests** | Rejouer les deux points : le corpus « règle fausse » doit refuser de sceller, le corpus corrigé doit sceller. |
| **corpus** | 59 % contre 8,6 % |
| **limites** | Le seuil ne protège que d'une défaillance MASSIVE ; une règle fausse qui ne toucherait que quelques cas passe, et c'est assumé — ces cas-là sont redressés et comptés. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0070 · Un repli mal choisi efface exactement ce qu'il devait protéger

| | |
|---|---|
| **phénomène** | Filtrer les appels sur la période du compte rendu suppose de connaître ses bornes. **435 comptes rendus sur 947 n'en impriment aucune** : ils écrivent « - 1er Trimestre 2026 - » et rien d'autre. Se rabattre sur la date d'arrêté ramène la période à **une seule journée** — et tout appel antérieur devient « hors période ». |
| **preuve** | 07/09/2026. Cinq occupations affichaient 2, 4 et 5 appels ; après ce repli, **zéro**. Un locataire qui appelle son loyer en janvier et février se retrouvait sans un seul appel dans un rapport arrêté au 31 mars — le filtre censé écarter les régularisations de l'an passé effaçait aussi les loyers du trimestre en cours. |
| **abstraction** | `UN REPLI N'EST PAS UNE VALEUR PAR DÉFAUT : C'EST UNE AFFIRMATION.` « À défaut de bornes, la période vaut un jour » est un énoncé faux, écrit sans y penser parce qu'il tenait sur une ligne. Le bon repli **déplie le NOM** — « 2026-T1 » vaut 01/01 → 31/03 parce que c'est ce que le nom SIGNIFIE — et quand rien ne se déplie, il **ne filtre plus du tout** : mieux vaut compter un appel de trop que les effacer tous. |
| **portée** | `UNIVERSELLE` |
| **règle** | Tout repli sur une valeur manquante s'écrit comme une affirmation et se relit comme telle. En cas d'indétermination réelle, le filtre se DÉSARME au lieu de se resserrer — un filtre qui se resserre sur l'inconnu supprime des faits vrais. |
| **composant** | `crg_integration.php::crgi_bornes_de_periode()` |
| **tests** | Fixture : un compte rendu sans bornes nommé « 2026-T1 » doit rendre 01/01 → 31/03 ; un intitulé non reconnu doit désarmer le filtre, pas le réduire à un jour. |
| **corpus** | 435 comptes rendus sur 947 |
| **limites** | Le dépliage ne connaît que trimestre, mois et couple de dates ; toute autre notation désarme le filtre — et c'est le comportement voulu. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0071 · Un contrôle de dernier recours a le dernier mot, y compris sur mieux prouvé que lui

| | |
|---|---|
| **phénomène** | Le contrôle « ce lot cesse d'apparaître alors que le compte continue » s'exécutait EN DERNIER et remettait « à arbitrer » sur des observations dont le verdict venait d'être **démontré par une lecture**. Sa place dans le code lui donnait autorité sur des faits mieux établis que lui. |
| **preuve** | 07/09/2026. Quatre occupations dont le dernier appel de loyer était LU — « Au 09.02.26 » dans un rapport arrêté au 31/03, « Au 31.01.25 » pour un arrêté au 31/03 — ressortaient en question après avoir été qualifiées « parti démontré » douze lignes plus haut. Le symptôme était trompeur : elles affichaient les bons appels et le bon verdict intermédiaire, et sortaient quand même en arbitrage. |
| **abstraction** | `L'ORDRE D'EXÉCUTION EST UNE HIÉRARCHIE DE PREUVES, QU'ON LE VEUILLE OU NON.` Un contrôle écrit en dernier prime sur tout ce qui précède, sans que personne l'ait décidé. `ABSENCE ≠ DÉPART DÉMONTRÉ` interdit de conclure sur un silence ; il n'autorise pas un silence à DÉFAIRE une lecture. |
| **portée** | `UNIVERSELLE` |
| **règle** | Tout contrôle de dernier recours teste explicitement qu'aucune preuve n'a déjà parlé avant de s'appliquer. Une règle de repli qui ne sait pas se taire n'est pas un repli : c'est la règle principale, déguisée. |
| **composant** | `crg_integration.php::crgi_qualifier_occupation()` — `$verdictParAppel` |
| **tests** | Fixture : une occupation dont le dernier appel s'arrête avant l'arrêté, sur un lot qui ne reparaît plus — verdict `PARTI DEMONTRE`, jamais `A ARBITRER`. |
| **corpus** | 4 arbitrages sur 8 |
| **limites** | Le drapeau ne couvre que les verdicts tirés des appels ; les autres familles de preuve restent soumises au contrôle d'horizon. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0072 · La granularité de la question n'est pas celle de l'observation

| | |
|---|---|
| **phénomène** | Un lot qui ne porte plus qu'un solde — « Solde Antérieur », aucun appel de loyer — n'est plus en gestion : le compte rendu continue de l'imprimer tant que le montant n'est pas apuré. Posée au LOT, cette question sortait **99 fois** sur un seul dépôt. Or ce n'est jamais un lot qu'on vend : c'est un immeuble, ou un mandat. |
| **preuve** | 07/09/2026. Sur 332 lots au dernier arrêté, **99 n'appellent rien** — ce qui explique l'écart entre les 332 lus et les ~220 attendus par Emmanuel : **233 appellent un loyer**. Regroupés : **8 mandants** dont plus aucun lot n'appelle, **31 immeubles** entièrement muets couvrant **64 lots**, et **35 lots isolés** dans des immeubles encore actifs. Deux cas confirmés vendus sur-le-champ : un mandant entier (6 lots, 21 654 €) et un immeuble entier (3 lots, 32 263 €). |
| **abstraction** | `ON POSE LA QUESTION À L'ÉCHELLE OÙ LA RÉPONSE SE DONNE.` L'observation est au lot ; la décision est à l'immeuble ou au mandat. Une file d'arbitrage dont la granularité copie celle de la lecture multiplie les questions par le nombre d'objets observés, alors qu'une seule réponse les couvre toutes. C'est la règle des 20-30 arbitrages appliquée à sa vraie cause : **une file trop longue ne signale pas un corpus difficile, elle signale qu'on interroge au mauvais niveau.** |
| **portée** | `UNIVERSELLE` |
| **règle** | Avant d'émettre une question, chercher le plus grand ensemble homogène qui la porte : mandant entier → immeuble entier → objet isolé. On n'interroge à l'unité que ce qui reste après. |
| **composant** | phase 3 — file d'arbitrage `LOT-SANS-APPEL` |
| **tests** | Fixture : un mandant dont aucun lot n'appelle doit produire UNE question, pas une par lot. |
| **corpus** | 99 lots → 8 + 31 + 35 questions, dont 20 lots couverts par 8 décisions |
| **limites** | ⚠️ **Un lot vendu et un lot en contentieux s'impriment à l'identique** — le document ne les départage pas. Le regroupement réduit le nombre de questions, il ne les supprime pas. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0073 · Un piège consigné mais non gardé se reproduit — trois fois

| | |
|---|---|
| **phénomène** | Compter les lignes d'observation et les présenter comme des locataires multiplie tout par le nombre de PÉRIODES du dépôt. La règle « une ligne n'est pas un objet » était **écrite en mémoire depuis des semaines**, et Emmanuel avait déjà dû la rappeler une fois. Elle n'a pas tenu : **rien dans le code ne l'appliquait**, et j'ai republié les mêmes chiffres faux deux fois de plus. |
| **preuve** | 08/09/2026. Un dépôt MENSUEL observe chaque lot **4 fois** ; ses 108 lots occupés ressortaient à **440 « en place »**. Emmanuel : « pour Vienne tu as encore triplé les chiffres car il y a 3 CRG par trimestre !!!!! » — après avoir déjà écrit : « il y a 122 biens et 480 d'occupation ». Sur un autre dépôt, **93 « partis » pour 23 départs réels**. |
| **abstraction** | `UN PIÈGE CONSIGNÉ MAIS NON GARDÉ SE REPRODUIT.` Un registre n'empêche rien : il documente. Seule une FONCTION qui rend l'erreur impossible protège — et tant que le chiffre se calcule à la main au moment de le dire, il se recalcule faux. La cadence des dépôts n'est même pas uniforme : mensuel contre trimestriel, le facteur de gonflement diffère d'un dépôt à l'autre, ce qui rend les colonnes **incomparables entre elles** en plus d'être fausses. |
| **portée** | `UNIVERSELLE` |
| **règle** | Aucun chiffre métier ne se calcule au moment de le publier. Il sort d'une fonction unique — `crgi_bilan_metier()` — dont chaque unité est un OBJET : un lot, un bail, un occupant. Un lot se compte **à sa dernière période**, jamais en additionnant ses observations. |
| **composant** | `crg_integration.php::crgi_bilan_metier()` |
| **tests** | Fixture : un lot observé 4 fois doit rendre 1 lot et 1 occupant, jamais 4. |
| **corpus** | 4 dépôts, cadences de 5 à 7 arrêtés |
| **limites** | La fonction ne protège que ce qu'elle rend ; tout comptage ad hoc reste exposé. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0074 · Un nom porté n'est pas un départ

| | |
|---|---|
| **phénomène** | Un compte rendu réimprime un locataire sorti tant que son solde n'est pas apuré — parfois des années. Comptés comme des départs, ces noms gonflaient un dépôt de **23 à 93**. |
| **preuve** | 08/09/2026. Sur un lot, l'occupant courant appelle ses trois mois de 2026 ; juste en dessous, une locataire lue « **Du 01.01.09 Au 24.01.09** », montants à 0,00 — partie en janvier 2009, **toujours imprimée seize ans après**, et sans dette. Emmanuel : « il n'y a pas 88 partis, ce n'est pas vrai ». Total du corpus : **62 départs réels contre 234 noms anciens portés**. |
| **abstraction** | `LA PRÉSENCE D'UN NOM DANS UN DOCUMENT NE DATE PAS L'ÉVÉNEMENT QU'IL RACONTE.` C'est `INTEG-P2-SOLDE-REPORTE` appliqué au locataire : la date du document n'est pas la date de ce qu'il porte. Un départ appartient à la période lue seulement si le dernier appel de ce bail y tombe. |
| **portée** | `UNIVERSELLE` |
| **règle** | Séparer toujours **départs de la période** (dernier appel ≥ début du dépôt) et **noms anciens portés** (dernier appel antérieur, ou aucun). Ne jamais publier le total des deux sous le mot « partis ». |
| **composant** | `crg_integration.php::crgi_bilan_metier()` — clés `partis` et `noms_anciens` |
| **tests** | Fixture : un bail dont le dernier appel précède le dépôt ne compte pas comme départ. |
| **corpus** | 296 baux terminés = 62 départs + 234 noms portés |
| **limites** | Un bail sans aucun appel lisible tombe en « nom ancien » par défaut — prudent, mais il peut masquer un départ récent mal lu. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0075 · Le guichet du périmètre — la décision ne porte pas sur une ligne

| | |
|---|---|
| **phénomène** | Les trois guichets d'arbitrage existants visaient tous une LIGNE de staging : un compte rendu, une occupation, un mouvement. Or « la SCI FAVRE est vendue » ne porte sur aucune ligne : elle porte sur un **mandat**. Faute de pouvoir désigner autre chose qu'un identifiant, la question sortait 99 fois — une par lot. |
| **preuve** | 08/09/2026. Sur les quatre dépôts : **112 périmètres muets couvrant 149 lots** — 20 mandants entiers, 30 immeubles entiers, 62 lots isolés. Groupés par proposition à l'écran : **8 gestes**. Emmanuel avait donné la réponse en une phrase : « la SCI FAVRE est vendue intégralement, Oyonnax aussi ». |
| **abstraction** | `LA CIBLE D'UNE DÉCISION N'EST PAS TOUJOURS UN OBJET DE LA BASE.` Un guichet dont la clé est un identifiant de ligne ne sait poser que des questions de ligne. Celui-ci prend un couple **(niveau, clé)** — `MANDANT\|01110000`, `IMMEUBLE\|01040000/01040247` — et écrit directement dans la mémoire durable, sans passer par `crgi_arbitrage` : ce qui est décidé ne concerne aucun document en particulier, mais tous ceux qui suivront. |
| **portée** | `UNIVERSELLE` |
| **règle** | Avant d'écrire un guichet, chercher l'objet MÉTIER que la réponse désigne, pas la ligne qui a fait naître la question. Chaque lot n'apparaît qu'une fois, au niveau le plus large qui le couvre — sinon on pose trois fois la même question. |
| **composant** | `crg_integration.php::crgi_perimetres_sans_appel()`, `crgi_decider_perimetre()` |
| **tests** | Fixture : un mandant dont aucun lot n'appelle produit UNE ligne de niveau MANDANT, et ses lots n'apparaissent ni en IMMEUBLE ni en LOT. |
| **corpus** | 112 périmètres, 149 lots, 8 gestes |
| **limites** | La proposition se prend sur l'ÉCHELLE du silence — mandat entier → gestion terminée, immeuble ou lot isolé → vendu. C'est le seul fait disponible : **un lot vendu et un lot en contentieux s'impriment à l'identique.** |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0076 · Un silence expliqué n'est pas une question

| | |
|---|---|
| **phénomène** | La file « ce qui n'appelle plus rien » demandait d'arbitrer des lots dont le document **imprime la raison du silence**. Sur la ligne du locataire : « Bail du 10/05/2023 **au 22/03/2026** » — l'occupant est parti à une date lue, le lot est vacant depuis. Demander « vendu ou gestion terminée ? » là-dessus, c'est faire trancher ce que la page dit en toutes lettres. |
| **preuve** | 08/09/2026. Emmanuel, sur une capture : « on voit très bien une date de départ dans l'appel de loyer ! ». Le moteur avait pourtant **bien lu** la date — l'occupation était classée « ancien locataire avec dette ». Le défaut n'était pas dans la lecture : il était dans la file, qui ne consultait pas ce qui avait déjà été lu. **16 lots sur 149** étaient dans ce cas ; la file passe de **112 périmètres à 96**, de **149 lots à 133**. |
| **abstraction** | `UNE FILE D'ARBITRAGE DOIT D'ABORD RELIRE CE QUE LE MOTEUR SAIT DÉJÀ.` Un fait établi par une phase — ici une fin de bail imprimée et atteinte — doit fermer la question avant qu'elle ne soit posée. C'est la même faute que la décision de phase 2 non propagée en phase 3, déplacée d'une phase à l'autre vers un même écran : **poser une question dont la réponse est dans le document use la file et fait douter du moteur**. |
| **portée** | `UNIVERSELLE` |
| **règle** | Avant d'émettre une question, chercher si un fait déjà lu l'explique. Un périmètre dont TOUS les lots portent une fin de bail imprimée et atteinte sort de la file ; expliqué en partie, il y reste mais le motif dit combien de lots le sont et depuis quelle date. |
| **composant** | `crg_integration.php::crgi_perimetres_sans_appel()` |
| **tests** | Fixture : un lot muet dont le bail est imprimé fini avant l'arrêté ne doit produire aucune question. |
| **corpus** | 16 lots sur 149 — 112 périmètres → 96 |
| **limites** | Le document n'explique que le DÉPART ; il ne dit pas si le bien a ensuite été vendu. On cesse d'interroger sur un silence expliqué, pas sur le devenir du bien. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0077 · Les honoraires de gestion séparent le bien vide du mandat perdu

| | |
|---|---|
| **phénomène** | Un bien vide et un mandat perdu **cessent tous deux d'appeler un loyer**. Rien, dans le tableau d'occupation, ne les distingue — et la file demandait donc de trancher « vendu / gestion terminée / vacant » sur un silence. |
| **preuve** | 08/09/2026. Sur un compte rendu de juillet, plus aucun loyer n'est appelé — mais la page porte « 31/07/2026 **Honoraires Gestion TTC** (taux:5,50 % HT, base:283,00 €) … 18,67 ». Emmanuel : « on voit qu'il n'y a plus de loyer appelé donc le bien est vide, et que nous continuons à lui prendre des honoraires de gestion — donc gestion **non perdue**, bien vide ». |
| **abstraction** | `QUAND DEUX SITUATIONS PRODUISENT LE MÊME SILENCE, LA PREUVE EST AILLEURS SUR LA PAGE.` Le tableau d'occupation ne pouvait pas les départager parce que la différence ne s'y trouve pas : elle est dans la section des honoraires. Chercher plus fort au même endroit n'aurait rien donné — il fallait changer d'endroit. |
| **portée** | `UNIVERSELLE` |
| **règle** | Tant que le compte rendu facture des honoraires de gestion, **le mandat vit** : la proposition est `VACANT`, jamais `GESTION TERMINÉE`. L'absence d'honoraires ouvre la question — mais seulement là où le lecteur sait les voir. |
| **composant** | `crg_integration_phase3.py::honoraires_de_gestion()`, `crg_integration.php::crgi_perimetres_sans_appel()` |
| **tests** | Fixture : un compte muet qui facture des honoraires doit proposer `VACANT` ; le même sans honoraires, `GESTION TERMINÉE`. |
| **corpus** | mesuré sur les dépôts SPI |
| **limites** | ⚠️ **Le lecteur ICS n'expose pas les dépenses** : les honoraires y sont NON LUS, et la fonction rend **-1, jamais 0** — zéro signifierait « plus rien n'est facturé », donc « mandat perdu », une affirmation qu'aucune lecture ne soutient. La file s'abstient alors de conclure et le dit dans le motif. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0078 · Une question sans les faits oblige à rouvrir le document

| | |
|---|---|
| **phénomène** | La file d'arbitrage posait ses questions **toutes nues** : un périmètre, un nombre de lots, un solde. Pour répondre, il fallait rouvrir le PDF, retrouver la page, relire le bloc. Une file qui coûte un aller-retour par ligne ne se traite pas — elle s'abandonne. |
| **preuve** | 08/09/2026. Emmanuel, après avoir dû relire lui-même deux pages pour me corriger : « si tu n'as pas la réponse certaine, alors tu dois nous donner une **analyse fine du CRG** pour nous permettre de décider… intègre-le pour que ce soit automatique ». |
| **abstraction** | `POSER UNE QUESTION, C'EST AUSSI FOURNIR DE QUOI Y RÉPONDRE.` Le moteur a déjà lu tout ce qu'il faut pour décider ; ne pas le restituer fait refaire à la main le travail qu'il vient de faire. Et l'analyse n'écrit QUE ce qui est lu — pas de déduction, pas de vraisemblance : le dernier occupant nommé, la fin de bail imprimée, l'encours porté, les honoraires facturés. Ces quatre faits séparent un bien vide d'un mandat perdu, et ils tiennent en trois lignes. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute question d'arbitrage porte, sous elle, les faits lus qui permettent d'y répondre — et dit explicitement lesquels **ne sont pas lisibles** sur ce format. |
| **composant** | `crg_integration.php::crgi_analyse_perimetre()` |
| **tests** | Fixture : un périmètre sans fin de bail imprimée doit l'annoncer, pas se taire. |
| **corpus** | 96 périmètres |
| **limites** | L'analyse ne restitue que ce que la phase 3 a lu ; les mouvements financiers (phase 4) n'y figurent pas encore. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0079 · Les règlements s'imputent sur les loyers les plus anciens — le silence des appels ne dit rien des encaissements

| | |
|---|---|
| **phénomène** | Un lot qui n'appelle plus aucun loyer peut **encaisser malgré tout**. Les règlements d'un locataire en retard s'imputent sur les loyers **LES PLUS ANCIENS**, et non sur le terme courant — pour éviter la **forclusion**, qui éteindrait les créances les plus vieilles. Le tableau des appels ne montre donc rien, alors que l'argent rentre. |
| **preuve** | 08/09/2026. Emmanuel, sur un local commercial sans aucun appel de loyer : « il se produit que les encaissements s'imputent sur les loyers les plus anciens pour éviter la forclusion des loyers… donc nous avons bien des encaissements même s'ils sont imputés sur les anciens loyers ». Mesuré sur les quatre dépôts : **10 périmètres muets dont l'encours BAISSE** — ils encaissent — contre 9 dont il augmente. |
| **abstraction** | `UN ENCOURS QUI BAISSE EST UN ENCAISSEMENT.` C'est la seule trace du règlement quand l'imputation le fait disparaître du tableau des appels. Conséquence de doctrine : **l'absence d'appel ne prouve ni l'absence d'occupant, ni l'absence d'argent** — elle prouve seulement qu'aucun terme nouveau n'est réclamé. Un occupant qui rembourse une dette ancienne est actif, et le confondre avec un parti fausse à la fois l'occupation et la trésorerie. |
| **portée** | `UNIVERSELLE` |
| **règle** | Comparer l'encours du PREMIER et du DERNIER arrêté lus. S'il baisse : le dire — « il y a eu des encaissements, imputés sur les loyers les plus anciens ». S'il monte : le dire aussi — « aucun règlement ne le résorbe ». Ne jamais conclure au départ ni à l'inactivité sur la seule absence d'appel. |
| **composant** | `crg_integration.php::crgi_perimetres_sans_appel()`, `crgi_analyse_perimetre()` |
| **tests** | Fixture : un lot sans appel dont l'encours passe de 900 € à 300 € doit rendre « encaissements », jamais « inactif ». |
| **corpus** | 10 périmètres encaissent, 9 s'aggravent, 65 stables |
| **limites** | La variation d'encours ne se lit que là où le solde est LU (`solde_source = LUE`) ; ailleurs elle est muette. Et elle mesure un NET : un encaissement compensé par un appel nouveau reste invisible — c'est la phase 4 qui séparera les deux. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0080 · La pagination est indépendante de la structure métier — on délimite AVANT et APRÈS

| | |
|---|---|
| **phénomène** | Quand le bloc d'un lot ne tient pas sur une page, l'éditeur réimprime son en-tête suivi de « **...Suite** » et poursuit sur la page d'après. La lecture ouvre l'observation sur la PREMIÈRE page — souvent vide, l'en-tête tenant seul en bas de page — et la branche « continuation » ne reprenait que le **nom** et le **solde**. Tout le reste était jeté. |
| **preuve** | 08/09/2026. Un lot dont le tableau d'appels est ENTIÈREMENT sur la seconde page : « Loyer Juillet 2026 · 694,89 », « Provisions pour charges Juillet 2026 · 28,00 », « Provisions TEOM Juillet 2026 · 8,00 ». Le moteur rendait **appels = 0** et posait une question d'arbitrage sur une occupation parfaitement lisible. Emmanuel : « nous avons le nom, la date de début du bail, l'appel de loyer et des charges pour juillet… **tu ne sais pas lire un CRG de VIENNE ?** ». |
| **abstraction** | `LA PAGINATION EST INDÉPENDANTE DE LA STRUCTURE MÉTIER.` **Il n'y a pas de saut de page après chaque immeuble, ni après chaque locataire** : la coupure tombe où la place manque, au milieu d'un tableau, entre un en-tête et ses lignes, entre un nom et ses montants. Un objet ne se délimite donc JAMAIS par la page : il faut regarder **avant et après** pour trouver ses bornes réelles. Corollaire : `UNE CONTINUATION N'EST PAS UN RÉSIDU, C'EST LA SUITE DU MÊME OBJET` — et chaque fois qu'on complète une observation depuis la page suivante, il faut se demander **quoi d'autre** s'y trouve. Le défaut a déjà frappé sur l'occupant, corrigé alors sans regarder les colonnes voisines : corriger un symptôme sur une continuation sans inventorier ce qu'elle porte garantit de revenir. |
| **portée** | `UNIVERSELLE` |
| **règle** | **Tout lecteur de ce corpus délimite ses objets sur PLUSIEURS pages, jamais sur une seule** — en regardant ce qui précède et ce qui suit. La branche de continuation reprend **tout** ce que la première page pouvait ne pas porter : occupant, dates de bail, solde, **appels**. Toute donnée nouvelle attachée au bloc y est ajoutée le jour où on la lit. |
| **composant** | `crg_integration_phase3.py::observer()` — branche `seg['suite']` |
| **tests** | Fixture : un lot dont l'en-tête est en bas de page et le tableau d'appels sur la suivante doit rendre ses appels. |
| **corpus** | mesuré sur les dépôts SPI |
| **limites** | ⚠️ Seule la phase 3 est corrigée. Les phases 2 et 4 lisent le MÊME segment et n'ont PAS été inventoriées : elles perdent probablement, elles aussi, ce qui tombe après une coupure. À vérifier. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0081 · Les colonnes d'un CRG ont un sens fixe, et il faut le connaître avant de nommer un montant

| | |
|---|---|
| **phénomène** | J'annonçais « **Encours porté : 730,89 €** » sur un lot dont le locataire venait de PAYER 730,89 €. Le montant était lu, mais nommé à l'envers : il était en **crédit**. |
| **preuve** | 08/09/2026, doctrine donnée par Emmanuel : « les loyers et charges sont appelés **à gauche sous le nom du locataire**, et la colonne **débit = les dépenses du lot**, les **crédits = les encaissements du locataire** ». |
| **abstraction** | `UN MONTANT SANS SA COLONNE N'EST PAS UNE DONNÉE.` Lire un chiffre et ignorer où il se trouve produit une valeur juste portant un nom faux — la pire des erreurs, parce qu'elle passe tous les contrôles de cohérence : le total est bon, le signe est bon, seule la SIGNIFICATION est inversée. Une dette et un règlement du même montant sont indiscernables si l'on ne regarde que le nombre. |
| **portée** | `UNIVERSELLE` |
| **règle** | Trois zones dans le tableau d'un lot : **à gauche sous le locataire**, les appels (loyers, charges, TEOM, terme, dépôt de garantie) ; **colonne Débit**, les dépenses du lot ; **colonne Crédit**, les encaissements du locataire. Aucun montant n'est nommé sans que sa zone soit établie ; à défaut, on le dit « montant porté au bloc » plutôt que de l'appeler encours. |
| **composant** | doctrine de lecture — `crgi_analyse_perimetre()` |
| **tests** | À COUVRIR — fixture : un solde en crédit ne doit jamais être annoncé comme un encours. |
| **corpus** | 1 cas nommé, portée générale |
| **limites** | La distinction des colonnes demande la géométrie du tableau ; tant qu'elle n'est pas lue, l'analyse doit rester prudente sur le NOM du montant. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0082 · Être dans un compte rendu de gestion EST la preuve du mandat

| | |
|---|---|
| **phénomène** | Je cherchais le numéro de mandat dans le document pour rattacher chaque lot à sa gestion. Il n'y est pas : un éditeur imprime littéralement « **Mandat N/A** » — 6 fois sur 6 sur l'échantillon lu — et l'autre n'imprime **pas le mot**. J'allais donc en faire une lacune, voire une question. |
| **preuve** | 08/09/2026. Emmanuel, en une phrase : « **quand c'est dans un CRG c'est une gestion avec mandat** ». Le compte rendu de gestion est, par définition, le rapport que le mandataire rend à son mandant : sa seule existence atteste le mandat. |
| **abstraction** | `LA NATURE D'UN DOCUMENT EST ELLE-MÊME UNE DONNÉE.` Chercher dans le contenu ce que le TYPE du document démontre déjà produit une lacune imaginaire — et, pire, une question posée à l'utilisateur sur un fait acquis. Avant d'aller extraire un attribut, se demander si le document ne l'atteste pas par ce qu'il EST. |
| **portée** | `UNIVERSELLE` |
| **règle** | Tout lot présent dans un compte rendu de gestion est **en gestion**, et porte donc un mandat — que le document en imprime le numéro ou non. Le mandat ne se LIT pas : il se CRÉE à l'intégration, sur le compte mandant qui porte le lot. Aucune question ne se pose là-dessus. |
| **composant** | doctrine — s'applique à la phase 2 (patrimoine) et à l'écriture vers MBI |
| **tests** | Fixture : un lot dont le document écrit « Mandat N/A » doit être réputé en gestion, sans arbitrage. |
| **corpus** | les deux éditeurs du corpus, aucun ne porte de numéro de mandat |
| **limites** | La règle atteste l'EXISTENCE du mandat, pas ses termes — ni sa date, ni son taux, ni son périmètre. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0083 · « Local » n'est pas un silence, c'est un commerce

| | |
|---|---|
| **phénomène** | Le type de bien est lu sur **2 291 lots, aucun manquant** — mais en libellé BRUT : « Appartement 1 Pièce », « Appart. T3 », « Local », « Local commercial », « Maison », « Entrepot », « Garage ». Sans normalisation, « Local » (283 lots) restait indéterminé, et j'allais en faire un arbitrage. |
| **preuve** | 08/09/2026. Emmanuel : « **local = commerce** ». Ce n'est pas une abréviation ambiguë : dans le vocabulaire de ces documents, un « Local » est un local commercial. |
| **abstraction** | `UN MOT DU MÉTIER N'EST PAS UN MOT INCOMPLET.` J'ai pris un terme professionnel pour une donnée tronquée, et j'allais faire arbitrer ce que tout le métier lit sans hésiter. Le silence, lui, est autre chose — et il a sa propre règle : **rien d'écrit ⇒ HABITATION**. Confondre « pas écrit » et « écrit brièvement » fabrique des questions. |
| **portée** | `UNIVERSELLE` |
| **règle** | Normaliser le libellé brut vers les catégories métier — `LOCAL COMMERCIAL` (dont « Local », « Entrepot »), `BUREAU`, `APPARTEMENT` (dont « Appart. T… », « Studio »), `MAISON`, `GARAGE` — et **`HABITATION` en cas de SILENCE seulement**. Le libellé brut est CONSERVÉ à côté : on ne perd jamais ce que le document dit. |
| **composant** | `crg_integration.php::crgi_type_de_bien()` → `crgi_lot.type_bien` et `crgi_lot.vendu`, migration `20260908b` |
| **tests** | Fixture : « Local » → `LOCAL COMMERCIAL` ; libellé vide → `HABITATION` ; « Appart. T3 » → `APPARTEMENT` ; « Local commercial 1 Pièce » → `LOCAL COMMERCIAL` et **jamais** `APPARTEMENT` ; « VENDU » → `vendu = true`. |
| **corpus** | ⚠️ **1 144 lots — PAS 2 291.** J'avais annoncé « ~400 locaux commerciaux » en comptant les LIGNES de `crgi_lot`, qui en porte une par compte rendu : un lot vu six fois comptait six fois. Emmanuel : « je ne pense pas qu'il y ait 400 locaux commerciaux ». Compté en OBJETS : **762 appartements, 158 locaux commerciaux, 126 maisons, 81 garages, 7 bureaux, 4 parties communes, 2 terrains, 2 panneaux** — zéro indéterminé. C'est la **TROISIÈME fois de la journée** que je compte des lignes pour des objets, sur une table différente à chaque fois. |
| **limites** | Le libellé **doublé** (« Local commercial Local commercial », « Garage Garage ») est dédoublé à la lecture, sans toucher à ce qui est conservé. `BUREAU` existe bien : **7 lots** — je l'avais dit absent en ne regardant que les libellés les plus fréquents. ⚠️ Et **`crgi_plat()` rend des MAJUSCULES** : écrits en minuscules, les repères du référentiel ne trouvaient RIEN et les 1 144 lots sortaient « indéterminé » — sans planter, donc sans alerte. Un référentiel qui ne reconnaît rien a l'air de fonctionner. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0084 · Un fait qu'on ne retient pas n'est pas un fait illisible : c'est un fait jeté

| | |
|---|---|
| **phénomène** | J'annonçais « **honoraires de gestion NON LISIBLES sur ce format** » sur les deux plus gros dépôts, et la file d'arbitrage demandait donc de trancher un fait **imprimé**. Or le lecteur PARCOURAIT déjà ces lignes — « Honoraires de gestion HT », « Honoraires H.T. JANVIER 2026 », « TVA sur hono. de gestion » — sans les retenir. |
| **preuve** | 08/09/2026. Emmanuel demande si tout est lu, et ajoute la règle qui tranche : « **pour une perte de gestion, nous n'avons plus du tout d'honoraires** ». Je vais vérifier : **56 lignes d'honoraires sur un seul compte rendu** que je jetais. Une fois captées au passage de la lecture existante — sans seconde ouverture du PDF — la couverture passe de **0 % à 96-100 % sur les quatre dépôts**, et les **67 périmètres opaques** d'une agence se répartissent en **56 vacants** (la régie facture encore) et **11 sorties**. |
| **abstraction** | `UN FAIT QU'ON NE RETIENT PAS N'EST PAS UN FAIT ILLISIBLE : C'EST UN FAIT JETÉ.` J'avais correctement appliqué la doctrine — ne pas conclure là où le lecteur ne voit rien — mais sur un diagnostic FAUX. Dire « non lisible » est une affirmation sur le document ; elle exige d'avoir regardé le document, pas seulement la sortie du lecteur. **Une lacune de sortie n'est pas une lacune de source.** |
| **portée** | `UNIVERSELLE` |
| **règle** | Avant d'écrire « non lisible sur ce format », ouvrir la page et chercher le fait à la main. S'il y est, c'est le lecteur qu'il faut corriger — et le capter au passage d'une lecture déjà faite ne coûte rien. |
| **composant** | `crg_ics_core.py::parse()` — `doc['honoraires']`, `crg_integration_ics.py::honoraires_de_gestion()` |
| **tests** | Fixture : un compte rendu portant une ligne d'honoraires doit rendre un compte non nul, quel que soit l'éditeur. |
| **corpus** | 96 à 100 % de couverture sur les 4 dépôts, contre 0 % sur deux d'entre eux |
| **limites** | La couverture des APPELS, elle, reste basse sur un dépôt — c'est un autre fait, et il n'est pas résolu. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0085 · Un nom sans appel est un parti débiteur — et il ne reste que deux réponses

| | |
|---|---|
| **phénomène** | J'exigeais un CONTRASTE — un bloc muet à côté d'un bloc qui appelle — pour conclure au départ. Trop prudent : un lot dont le seul bloc ne porte qu'un « Solde Antérieur 1 198,74 » n'avait rien d'ambigu, et partait en arbitrage. Et j'offrais quatre réponses là où deux suffisent. |
| **preuve** | 08/09/2026, sur une page où trois cas coexistent. Emmanuel : « quand il n'y a pas de loyer appelé comme BOUGUESSA c'est qu'il est **parti débiteur** ; quand il y a des loyers comme AOUANE et sur le même n° de lot des noms sans loyers, alors ce sont des **locataires partis débiteurs** ; et si le lot disparaît et que le dernier nom n'a pas de loyer, alors c'est soit vendu soit vacant, et je dois **uniquement arbitrer entre vendu et vacant** ». Effet : **342 partis débiteurs** qualifiés par le moteur, et la liste des réponses tombe de 4 à 2. |
| **abstraction** | `UNE FILE D'ARBITRAGE NE DOIT OFFRIR QUE LES RÉPONSES QUE CELUI QUI RÉPOND PEUT DÉPARTAGER.` `GESTION TERMINÉE` et `CONTENTIEUX` ont disparu : le contentieux se lit comme ce qu'il est — un parti débiteur — et la fin de gestion ne se distingue pas d'une vente sur le document. Offrir un choix indécidable, c'est demander de deviner. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un nom sans aucun appel est PARTI ; s'il porte un solde, il est parti DÉBITEUR. Le voisinage aide à comprendre, il ne conditionne rien. Et le seul arbitrage restant est **VENDU ou VACANT**, tranché par les honoraires : la régie facture encore ⇒ vacant. |
| **composant** | `crg_integration.php::crgi_qualifier_occupation()`, `CRGI_CHOIX_SANS_APPEL` |
| **tests** | Fixture : un bloc à solde seul, unique sur son lot, doit rendre `ANCIEN LOCATAIRE AVEC DETTE` sans arbitrage. |
| **corpus** | 342 partis débiteurs, 83 périmètres → 68 vacants + 15 sorties |
| **limites** | ⚠️ **J'ai déjà posé cette règle sans garde, et elle avait fabriqué 871 départs dont 518 auto-démentis.** Ce qui a changé n'est pas la règle mais la LECTURE — six graphies d'appel, blocs coupés recollés, honoraires captés. La garde `$saitLire` et le contrôle de contradiction restent armés : ils rendent 0 sur ce passage. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0086 · Une simulation qui ne rend pas d'identifiant ment sur ses propres chiffres

| | |
|---|---|
| **phénomène** | En mode simulation, la fonction d'écriture ne rendait rien pour une création — logique en apparence, puisqu'aucune ligne n'est écrite. Sauf que la **carte des identifiants** restait vide : les biens créés n'existaient pour personne, et les baux qui les cherchaient se comptaient « ignorés ». |
| **preuve** | 09/09/2026. La simulation annonçait **598 baux ignorés faute de bien**, et **9 rôles créés** au lieu de 1 757. Après correction — un identifiant NÉGATIF, reconnaissable et impossible à confondre avec une vraie ligne — les baux ignorés tombent à **7** et les rôles à **1 757**. |
| **abstraction** | `UNE SIMULATION DOIT COMPTER CE QU'UN IMPORT FERAIT, PAS CE QU'ELLE-MÊME ARRIVE À FAIRE.` Le mode d'essai avait sa propre défaillance, et il l'attribuait aux données. C'est la pire forme de faux négatif : on regarde un chiffre alarmant, on cherche le défaut dans le corpus, et il est dans l'instrument. |
| **portée** | `UNIVERSELLE` |
| **règle** | Toute simulation rend les mêmes valeurs de retour qu'une exécution réelle — identifiants compris, sous une forme reconnaissable. Et une garde refuse qu'un identifiant fictif atteigne une écriture réelle : le voir là signifierait que les deux modes se sont mélangés. |
| **composant** | `crg_integrateur.php::crgi_ecrire()` |
| **tests** | Fixture : une simulation sur deux familles liées doit rendre le même nombre d'objets qu'une exécution réelle. |
| **corpus** | 598 → 7 baux ignorés · 9 → 1 757 rôles |
| **limites** | L'identifiant fictif ne vaut que dans la passe ; il ne survit pas d'une simulation à l'autre, ce qui est voulu. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0087 · Un rapprochement qui ne rapproche rien crée des doublons en silence

| | |
|---|---|
| **phénomène** | La recherche d'un tiers déjà en base comparait un nom **normalisé** — majuscules, accents et ponctuation retirés — à un simple `UPPER(TRIM())` qui conserve points et apostrophes. Les deux côtés n'étaient pas normalisés pareil : la comparaison ne pouvait pas réussir. |
| **preuve** | 09/09/2026. `M. ET MME X` ne rejoignait jamais `M ET MME X`. La simulation annonçait **1 808 créations de tiers** sur une base qui en porte 961, sans qu'aucune erreur ne se produise. Second défaut au même endroit : la clé concaténait `raison_sociale` ET `nom`, qui portent la même valeur pour une personne morale — d'où « SCI FAVRESCI FAVRE ». |
| **abstraction** | `UN RAPPROCHEMENT QUI NE RAPPROCHE RIEN NE SE VOIT PAS.` Il ne lève aucune exception, ne ralentit rien, ne remplit aucun journal d'erreur : il crée simplement des doublons, et on ne s'en aperçoit qu'en comptant les objets créés. **Toute comparaison doit normaliser LES DEUX CÔTÉS avec la même fonction** — une seule suffit à rendre le rapprochement stérile. |
| **portée** | `UNIVERSELLE` |
| **règle** | Les deux membres d'une comparaison d'identité passent par la MÊME fonction de normalisation, appelée explicitement de part et d'autre. Jamais une normalisation SQL d'un côté et PHP de l'autre. |
| **composant** | `crg_integrateur.php::crgi_poser_tiers()` |
| **tests** | Fixture : « M. ET MME DUPONT » et « M ET MME Dupont » doivent rendre le même tiers. |
| **corpus** | 1 808 → 1 747 créations, dont l'écart est réel (la V1 ne porte que 3 locataires en tant que tiers) |
| **limites** | La comparaison parcourt les tiers en mémoire : acceptable à 961 lignes, à revoir au-delà de quelques dizaines de milliers. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## APP-0088 · Un import n'est annulable que si TOUTE écriture passe par un seul endroit

| | |
|---|---|
| **phénomène** | Le moteur d'écriture historique ne savait revenir en arrière que sur **deux tables** — `biens` et `immeubles`, passées en « archive ». Les propriétaires, tiers, mandats, baux et statuts de locataires qu'il créait étaient comptés « sautés » : ils restaient. Un import raté laissait donc des personnes et des baux à retrouver à la main. |
| **preuve** | 09/09/2026, lecture de `crg_moteur_defaire()` : deux branches seulement, `CORRIGER` avec état d'avant, et `CREER` sur `biens|immeubles`. Tout le reste tombe dans `sautees`. |
| **abstraction** | `UN IMPORT ANNULABLE N'EST PAS UN IMPORT QU'ON PENSE POUVOIR ANNULER : C'EST UN IMPORT DONT TOUTE ÉCRITURE PASSE PAR UN SEUL ENDROIT QUI LA NOTE.` Dès qu'une écriture s'échappe — une table oubliée, un `INSERT` direct « juste pour cette fois » — l'annulation ment, et elle ment silencieusement : elle rapporte un succès sur ce qu'elle a su défaire. |
| **portée** | `UNIVERSELLE` |
| **règle** | Un point d'écriture unique, qui journalise table, identifiant, action et **l'état d'avant sur les seules colonnes modifiées** — journaliser la ligne entière ferait revenir, à l'annulation, des champs qu'un humain a changés depuis. L'annulation rejoue à l'envers : une création est supprimée, une modification restaurée, **une ligne jamais touchée n'apparaît pas au journal donc n'est jamais approchée**. |
| **composant** | `crg_integrateur.php::crgi_ecrire()` et `crgi_defaire()`, table `crgi_journal` |
| **tests** | Fixture : intégrer puis défaire doit rendre la base identique, y compris sur les familles que le moteur historique ne savait pas défaire. |
| **corpus** | 5 familles · 6 tables métier |
| **limites** | Une ligne créée par l'import puis référencée ailleurs par un humain ne peut plus être supprimée : l'échec est nommé, jamais avalé. |
| **commit d’introduction** | PAS ENCORE COMMITÉ |

---

## Ce que le registre ne contient pas, et pourquoi

Cinquante-quatre apprentissages, et **aucun ne nomme un lot, un occupant, un compte ou un fichier**. C'est
la condition pour que l'examen mesure quelque chose : si une règle a besoin du cas pour
fonctionner, elle n'a rien appris — elle a mémorisé. Chaque test ci-dessus s'exécute sur une
**fixture synthétique** (un en-tête, un bloc, une ligne fabriqués) précisément pour que le
souvenir du document ne puisse pas lui souffler la réponse.
