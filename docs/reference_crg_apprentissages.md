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

## Ce que le registre ne contient pas, et pourquoi

Neuf apprentissages, et **aucun ne nomme un lot, un occupant, un compte ou un fichier**. C'est
la condition pour que l'examen mesure quelque chose : si une règle a besoin du cas pour
fonctionner, elle n'a rien appris — elle a mémorisé. Chaque test ci-dessus s'exécute sur une
**fixture synthétique** (un en-tête, un bloc, une ligne fabriqués) précisément pour que le
souvenir du document ne puisse pas lui souffler la réponse.
