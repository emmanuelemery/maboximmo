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

## APP-0017 · Une enveloppe contient plusieurs documents, et chacun a des pages de suite

| | |
|---|---|
| **phénomène** | Un dépôt réel ne porte pas que des comptes rendus : l'enveloppe contient aussi l'appel de fonds de la copropriété et les factures des prestataires. Ces documents s'intercalent — parfois **au milieu** d'un compte rendu, qui reprend ensuite. |
| **preuve** | 03/09/2026, première passe d'apprentissage sur un corpus ICS : 27 pages ressortaient « aucun signal de CRG », dont quatre étiquetées « avant le premier en-tête » alors qu'elles venaient **après**. Un dépôt parfaitement lu ressemblait à un découpage raté. |
| **abstraction** | Trois règles, et elles se tiennent : ❶ un document joint se reconnaît à son **titre en tête de page**, dans une table déclarée et additive — jamais à sa casse, car « Appel de Fonds » et « APPEL DE FONDS » sont le même titre ; ❷ un document joint **suspend** le compte rendu, il ne le clôt pas : le CRG reprend sur un de ses propres bandeaux ; ❸ un document joint **a lui aussi des pages de suite** — et le signal doit dire quand elles lui sont rattachées par contiguïté plutôt que par une preuve imprimée. |
| **portée** | `UNIVERSELLE` |
| **règle** | `DOCUMENTS_JOINTS` + `SECTIONS_CRG` (tables additives), `_titre_de_document()`, et l'état `hors_crg` qui suspend au lieu de fermer. Une page quasi vide appartient au document ouvert. |
| **composant** | `crg_integration_phase0.py` |
| **tests** | à écrire sur fixture synthétique — voir « limites » |
| **corpus** | un corpus ICS complet : 721 pages, 681 rattachées, **40 nommées, 0 sans nom** |
| **limites** | ⚠️ Cet apprentissage n'est **pas encore porté par un test** : il est vérifié sur corpus, pas sur fixture. Tant que ce test n'existe pas, l'entrée est incomplète au regard de la règle du registre. |
| **commit** | `—` |

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
| **commit** | `—` |

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
| **tests** | à écrire — voir « limites » |
| **corpus** | corpus ICS complet : immeubles 0 → 236, lots 0 → 396, occupations 0 → 396, mouvements 106 → 3 221 |
| **limites** | ⚠️ Pas encore porté par un test sur fixture. ⚠️ Et la **qualification** ICS n'est pas faite : les 3 221 montants sortent `INDETERMINABLE` et remontent en arbitrage — c'est voulu (`NE JAMAIS DÉDUIRE UNE NATURE D'UN LIBELLÉ`), mais cela laisse 143 groupes à trancher, dont **5 couvrent 87 %** des montants. |
| **commit** | `—` |

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
| **tests** | à écrire — voir « limites » |
| **corpus** | corpus ICS : 149 → 143 groupes, 491 lignes rassemblées sous une seule question au lieu de six |
| **limites** | ⚠️ Pas encore porté par un test sur fixture. Ne reconnaît que la forme date ; d'autres intitulés sans valeur de section restent à observer. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | corpus ICS : mouvements qualifiés 0 → 1 778 (49,8 %) |
| **limites** | Ne vaut que pour les colonnes dont l'en-tête annonce une nature. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | trois dépôts : occupations en arbitrage 254 → 0, 302 → 6, 5 → 5 |
| **limites** | La chronologie inter-périodes reste indéterminée, et doit le rester : cette règle écrit une occupation, jamais une entrée ni un départ. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | trois dépôts : **0 perte silencieuse** partout |
| **limites** | Seule la famille des immeubles déclare aujourd'hui sa couverture ; les autres ne groupent pas encore. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | trois dépôts : 397 → 63, 37 → 17, 87 → 49 questions |
| **limites** | Les familles individuelles ne se replient pas, et c'est voulu — elles resteront le gros du reste. |
| **commit** | `—` |

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
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | un dépôt : 235 CRG sans arrêté → 0 |
| **limites** | Ne couvre que le trimestre imprimé ; un document qui ne nomme ni période ni trimestre reste sans arrêté, et c'est honnête. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | un dépôt : 60 conflits d'identité → 0, lots 396 → 332 (les doublons d'impression cessent d'être comptés) |
| **limites** | Suppose que le document imprime les occupants dans l'ordre chronologique. Deux blocs à la même hauteur exacte resteraient indépartageables. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | un dépôt : 2 comptes rendus rendus à la chaîne |
| **limites** | Un troisième statut de collision qui apparaîtrait demain serait inclus par défaut — c'est voulu : mieux vaut examiner un document de trop que d'en perdre un. |
| **commit** | `—` |

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
| **tests** | à écrire sur fixture |
| **corpus** | un dépôt : 5 « illisibles » → 3 sans texte + 1 hors CRG + 1 avis d'acompte nommé |
| **limites** | Le seuil de « sans texte » est un nombre de caractères ; un scan portant un filigrane textuel passerait pour lisible. |
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

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
| **commit** | `—` |

---

## Ce que le registre ne contient pas, et pourquoi

Quarante-trois apprentissages, et **aucun ne nomme un lot, un occupant, un compte ou un fichier**. C'est
la condition pour que l'examen mesure quelque chose : si une règle a besoin du cas pour
fonctionner, elle n'a rien appris — elle a mémorisé. Chaque test ci-dessus s'exécute sur une
**fixture synthétique** (un en-tête, un bloc, une ligne fabriqués) précisément pour que le
souvenir du document ne puisse pas lui souffler la réponse.
