# CHARTE DE LECTURE DES COMPTES RENDUS DE GESTION

> **Toutes les règles appliquées pour lire, extraire et analyser un CRG en vue d'une
> intégration en masse.** Chaque règle vient d'un défaut mesuré sur le corpus réel, jamais
> d'une intention. Les spécificités d'agence sont signalées 🏢 partout où elles existent.

**Périmètre** — 4 agences, 2 éditeurs, ~960 comptes rendus.
**Statut au 12/09/2026** — lecture et bouclage *certifiés* ; **finances non certifiées**
(voir §10). **Dernière mesure de référence : 436 pièces.**

---

## §0 — LES QUATRE RÈGLES QUI GOUVERNENT TOUTES LES AUTRES

| | |
|---|---|
| **R0.1** | **Le document est la vérité, pas la formule.** Une identité comptable qui se vérifie ne prouve pas que les lignes sont bien nommées. `report + Σ soldes + Σ opérations = solde` réconciliait déjà à 0,00 € quand 342 894 € de versements au propriétaire étaient appelés « opérations diverses ». **Une somme juste ne prouve pas un nommage juste.** |
| **R0.2** | **Un objet ne se délimite JAMAIS sur une seule page.** Il faut regarder AVANT et APRÈS. La pagination de l'éditeur est indépendante de la structure métier : elle coupe où la place manque. |
| **R0.3** | **Ce qui n'est pas démontré est une QUESTION, jamais une déduction.** Une absence de CRG peut être un oubli, une vente ou une perte de gestion — indiscernables dans les données. Un lot vendu et un lot en contentieux s'impriment à l'identique. |
| **R0.4** | **Une règle écrite en prose ne garde rien : seul un contrôle qui REFUSE garde.** Trois défauts de la même famille ont été repayés en une seule journée parce que la règle existait en mémoire et que rien dans le code ne la faisait respecter. Tout apprentissage se solde par **un test qui rougit**. |

---

## §1 — AVANT DE LIRE : l'outillage

| | |
|---|---|
| **R1.1** | **Le binaire du contrat est celui qui sait faire `-table`.** Trois `pdftotext` homonymes cohabitent sur le poste (poppler 25.07, Xpdf 4.00 ×2) et **ne rendent pas la même page**. Le code choisit sur la CAPACITÉ, jamais sur l'ordre du `PATH`, remonte son étiquette à l'écran, et affiche « LECTURE DÉGRADÉE » sinon. `CRG_PDFTOTEXT` permet d'imposer un chemin. *Coût de l'oubli : 288 CRG sur 325 avec l'en-tête d'agence pris pour un nom de propriétaire, et deux empreintes de phase 2 inconciliables.* |
| **R1.2** | **`-table -enc UTF-8`, jamais `-layout`** pour la lecture en colonnes : `-layout` mélange les colonnes. `-layout` reste utile pour le bloc d'adressage (§4.1). |
| **R1.3** | **Tout script Python appelé en sous-processus écrit `ensure_ascii=True`**, y compris sur sa branche d'erreur. La sortie standard d'un fils est en cp1252 sous Windows : « ANDRÉ » revient `ANDR?`. *43 caractères mutilés dans 35 noms de propriétaire sur 411 — invisible à tout contrôle financier, puisque aucun montant ne bouge.* |
| **R1.4** | **Un rapport s'ÉCRIT dans un fichier UTF-8, il ne s'imprime pas sur la console.** La console Windows est en cp1252 et ne sait afficher ni `→` ni `≥` ni `é`. |
| **R1.5** | **La base n'est pas un garde-fou.** MySQL tourne ici sans `STRICT_TRANS_TABLES` : une valeur hors capacité est **écrêtée en silence**. `DECIMAL(12,2)` plafonne à `9 999 999 999,99` — toute ligne portant exactement cette valeur est un débordement, pas un montant. Le refus d'un montant absurde se fait **dans le lecteur**. |
| **R1.6** | **Le fichier peut être ancien, la lecture jamais.** Seule source autorisée d'un chiffre : le nouveau passage du PDF par un lecteur certifié. L'ancien import peut servir à RETROUVER un objet, jamais à fournir un montant, compléter une valeur, entrer dans une somme ou apparaître dans une statistique. Si le périmètre n'existe pas, la fonction rend une liste **vide**, jamais la totalité. |

---

## §2 — IDENTIFIER LE DOCUMENT

### 2.1 L'éditeur : `ÉDITEUR → MOTEUR → VARIANTE`

| éditeur | agences | moteur | variantes |
|---|---|---|---|
| **ICS** | REGIE EMERY LYON, EMERY IMMO (RIOM) | `crg_ics_core.py` | `lyon`, `emery_immo` |
| **SEPTEO SPI** | REGIE EMERY VIENNE, CHAPONOST | `parse_crg_septeo.py` | `septeo_spi` |

**R2.1** — La reconnaissance est unique : `crg_format.famille_du_texte`, **enseigne AVANT structure**.

**R2.2** — 🔴 **Le SYSTÈME suit l'AGENCE, pas le format lu.** `lyon` et `emery_immo` lisent le
**même référentiel de codes** : mettre la variante dans une clé d'objet dédoublait
**22 immeubles et 31 lots**. *Une variante de lecteur n'est pas un référentiel d'identifiants.*

### 2.2 L'agence

| | |
|---|---|
| **R2.3** 🏢 | **SEPTEO l'imprime en clair** : `Agence : A3 - REGIE EMERY - VIENNE`, 71/71 pièces. |
| **R2.4** 🏢 | **ICS ne la nomme jamais** — elle se déduit du code postal de l'en-tête (`69007` → LYON). |
| **R2.5** | **`A3` ne correspond à rien en base** (`agences.code_agence` de VIENNE vaut `38-1`). Une table de correspondance est indispensable, **jamais de déduction**. |
| **R2.6** | 🔴 **SANS AGENCE NE PEUT PAS EXISTER.** Un CRG est émis PAR une agence : nom, carte professionnelle et garantie financière sont imprimés en tête de chaque page. Un CRG sans agence est une donnée **fausse**, pas incomplète — et rien en aval ne redemande jamais l'agence. Garde : `tests/structure/test_ged_crg_sans_agence.php`. |
| **R2.7** | ⚠️ **L'ENSEIGNE N'EST PAS L'AGENCE.** `EMERY IMMOBILIER` est une **société** (#2) ; les agences sont `EMERY IMMO RIOM` (#5) et `EMERY IMMO CHAMALIERES` (#6). Confondre les deux fait passer 220 documents pour des anomalies — et choisir à la place de l'humain serait une invention : c'est un **arbitrage**. |

### 2.3 La période — DEUX clés, jamais une

| | |
|---|---|
| **R2.8** 🏢 | **LYON et EMERY IMMO émettent par TRIMESTRE. VIENNE (SEPTEO) émet par MOIS**, parfois irrégulier (`25/06/2025 → 30/04/2026`). Forcer le trimestre ferait comparer un mois de VIENNE à un trimestre de LYON sans que rien ne le signale dans un total. |
| **R2.9** | `periode_cle` = l'identité du document dans sa granularité (`2026-T1`, `2026-04`, `2026-03-15`) — elle nomme le fichier GED et distingue deux documents. `trimestre_cle` = le trimestre de rattachement, **toujours renseigné** — il groupe, compare et historise. `periode_type` dit laquelle est l'identité ; `periode_source` dit d'où elle vient. |
| **R2.10** | **Le trimestre se prend sur la CLÔTURE, jamais sur le début.** |
| **R2.11** | 🔴 **LE NOM DU FICHIER MENT.** `SCI_FOCH_2025_T2_TTAFD6F.pdf` imprime `1T2025` en page 1. Priorité de lecture : **clôture imprimée > libellé de période > date d'édition**. Le nom de fichier n'entre nulle part dans cette chaîne. |
| **R2.12** 🏢 | **Un CRG LOCA IMMO annonce sa période de trois façons** : `- 2e Trimestre 2026 -`, `3ème TRIM 2025`, `1T2025`. Ne lire que la première décalait `date_arrete` et `trimestre` d'un trimestre entier. |
| **R2.13** | ⚠️ **Ne jamais prendre la date qui suit le mot `Report`** : c'est la clôture du trimestre PRÉCÉDENT. |
| **R2.14** | **Le trimestre déclaré au chargement fait autorité.** Un CRG du 2e trimestre arrêté au 31/03 est **normal** — calendrier d'édition, comptable, week-end : 95 pièces sur 440 sont dans ce cas. **Ne jamais l'alerter, ne jamais le « corriger ».** |
| **R2.15** | `date_arrete` **date le STOCK, pas le document**. Un stock daté du trimestre d'avant **écrase la photographie précédente** — et un solde mal daté réconcilie toujours, donc rien ne le signale. Tout rapport trie sur `periode.fin`. |

### 2.4 Le compte mandant et le mandat

| | |
|---|---|
| **R2.16** 🏢 | ICS : `COMPTE PERSONNEL 01040000`. SEPTEO : `Identifiant extranet : 1105403390` (préfixe `11054` = l'agence). |
| **R2.17** | 🔴 **ÊTRE DANS UN CRG EST LA PREUVE DU MANDAT.** Le numéro de mandat n'est nulle part (SPI imprime littéralement `Mandat N/A`, ICS n'imprime pas le mot). Le compte rendu est **par définition** le rapport du mandataire à son mandant : sa seule existence atteste le mandat. *La nature d'un document est elle-même une donnée.* Le mandat ne se lit pas : il **se crée à l'intégration**, sur le compte mandant qui porte le lot. La règle atteste l'**existence** du mandat, pas ses termes. |
| **R2.18** | 🔐 **SÉCURITÉ** — le CRG SEPTEO imprime `Identifiant extranet` **et `Mot de passe` en clair, 71/71**. Tout CRG SPI versé en GED diffuse un mot de passe. |

---

## §3 — LIRE LA PAGE

### 3.1 La pagination

| | |
|---|---|
| **R3.1** | 🔴 **PAS de saut de page après chaque immeuble, ni après chaque locataire.** La coupure tombe où la place manque : au milieu d'un tableau, entre un en-tête et ses lignes, entre un nom et ses montants. L'éditeur réimprime l'en-tête suivi de « …Suite ». **Une continuation n'est pas un résidu, c'est la suite du même objet.** |
| **R3.2** | **Le remède est un PARCOURS, pas une consigne.** `parcours_immeubles()` traverse le document en continu et ne remet son état à zéro que quand le **code immeuble** change. Aucun lecteur ne doit re-boucler sur `pages`. *Coût mesuré sur la lecture des catégories, 4 385 lignes d'argent : page par page → 684 orphelines (15,6 %) · titre porté → 369 · marge lue sur le bloc → 12 · après les 4 pièges de continuation → 0.* Garde : `tests/structure/test_crg_lecture_traverse_les_pages.php`. |
| **R3.3** | ⚠️ Une page de continuation **réimprime l'en-tête `RECAPITULATIF DES OPERATIONS`** et ne porte parfois **que** la ligne de solde, ou **que** la ligne de totaux. Un bloc vide n'est pas une page sans intérêt : rejeter celle qui ne porte que ses `Totaux Généraux` fait disparaître un immeuble entier du tableau ET du total. |
| **R3.4** | ⚠️ Le **pied de page légal** (« Capital de 12 000 EUROS », « Garantie de 880 000 EUROS ») et l'**en-tête de régie** répétés en haut de chaque page fabriquaient 169 439 € d'écritures. `Powered by ICS ` se colle **devant** le titre, dans la marge gauche : le blanchir **avant de mesurer une colonne**, pas seulement avant de comparer un texte. |
| **R3.5** | 🏢 **SEPTEO : un CRG PAR IMMEUBLE.** Un mandant reçoit trois documents pour avril. `id_piece = AGENCE\|compte\|année\|période` leur donne la **même clé** : le dédoublonnage n'en gardait qu'un, et le `solde_compte` de chaque document est en réalité le solde de **son** immeuble. *Perte mesurée : 9 pièces, 11 immeubles, 11 lots.* → l'`id_piece` porte l'empreinte **pour VIENNE seulement**. **Ne jamais dédupliquer VIENNE sur compte + période** ; seul un doublon physique de même SHA se supprime. |

### 3.2 Les colonnes

| | |
|---|---|
| **R3.6** | 🔴 **UN MONTANT SANS SA COLONNE N'EST PAS UNE DONNÉE.** À gauche sous le locataire = les **APPELS** (loyers, charges, TEOM, terme, dépôt de garantie) · colonne **Débit** = les **DÉPENSES du lot** · colonne **Crédit** = les **ENCAISSEMENTS du locataire**. Un montant lu juste et nommé à l'envers passe tous les contrôles : le total est bon, le signe est bon, **seule la signification est inversée** — et une dette devient indiscernable d'un règlement du même montant. |
| **R3.7** | **Le récapitulatif a CINQ colonnes** : Débits · Crédits · Dont T.V.A. · Locatif · Déductible. Elles se lisent par **bandes d'en-tête**, et chaque nombre se range d'après son **bord droit**. |
| **R3.8** | 🔴 **L'EN-TÊTE N'EST PAS L'ÉTALON DE SA COLONNE.** Sur GROUPE SIR, `Débits` finit colonne 153 et ses montants vers 168 : un débit de 10 341,99 € tombait à 16 de son propre en-tête et à 9 de `Crédits` — tout l'immeuble basculait d'un coup. **La page porte son propre étalon : la ligne `Totaux Généraux`, dont les deux premiers montants SONT le débit et le crédit, à leur vraie colonne.** |
| **R3.9** | **UNE ligne de mouvement ne porte qu'UNE valeur dans le couple débit/crédit.** On prend la première qui tombe dans la fenêtre des deux colonnes et on s'arrête ; tout ce qui suit est un rappel du même mouvement (T.V.A., part locative, part déductible). *« Dont T.V.A. » n'est pas aligné comme les autres : sur SCI SMH sa valeur 5,00 finissait colonne 149, soit à 6 de `Crédits` (143) et à 7 de son propre en-tête (156).* |
| **R3.10** | **UNE seule valeur par colonne sur une ligne de totaux, jamais une somme.** Les additionner gonflait le loyer de **201 133,67 €** sur SIRES — exactement son solde antérieur. |
| **R3.11** | **Un nombre AVANT la première colonne est du LIBELLÉ** : année, référence d'avis, numéro de compte. Jamais un montant. *« Honoraires H.T. Avril 2026 » lu 2 026,00 € et le vrai montant 47,63 € rangé en TVA ; sur EVEREST T2, 76 035,92 € lus contre 6 698,05 € imprimés — et 2 610 lignes de `crg_ecritures` portent déjà 2024/2025/2026 en débit ou crédit.* |
| **R3.12** | **Les montants sont alignés à DROITE et débordent de leur en-tête : tolérance 12 caractères, on tranche au plus proche.** Une tolérance de 6 en rejette la moitié. |
| **R3.13** | **`31.03.2026` se lit comme un montant** si le motif ne refuse pas un point après les décimales. Motif du contrat : `(?<![\d.,])(-?\d{1,3}(?:\d{3})*\.\d{2})(?![.\d])`. |
| **R3.14** | **Une ligne de PDF porte PLUSIEURS colonnes.** `Agence: A3 - REGIE EMERY - VIENNE          38670 CHASSE-SUR-RHONE` : le code postal du propriétaire est sur la même ligne que le bloc de gauche. **On découpe la ligne en colonnes** (séparateur : 3 espaces ou plus), **on ne la juge pas sur son premier mot** — et une ligne d'une autre colonne ne FERME pas le bloc, elle se saute. *Coût : 511 CRG sur 512 annoncés sans adresse de propriétaire.* |
| **R3.15** | **Un total n'est jamais une écriture**, et le motif se cherche **partout dans le libellé**, pas en tête : la ligne imprimée est « **SCI** Solde créditeur en Euros au 30.06.2026 », le nom du propriétaire déborde. Un test `startswith` laissait passer 2 531,17 € de faux crédit. |
| **R3.16** | ⚠️ **`Solde` dit DEUX choses opposées dans le même document.** `Solde créditeur/débiteur en Euros au …` = le **résultat** de la page. `Solde au 30.06.2026` sous un titre de section = un **MOUVEMENT** — le solde de l'immeuble viré au compte du propriétaire, **12 182,25 €** sur un seul immeuble, sa plus grosse ligne. Seule la forme « en Euros » sépare les deux. |
| **R3.17** | Le **report d'ouverture** d'un immeuble n'a pas de titre de section mais **est compté dans ses Totaux Généraux**. L'exclure faisait manquer **15 013,16 €** sur un seul immeuble — et ce n'est pas un détail, c'est tout le passé du compte. *Un report n'est pas un solde : le solde est le RÉSULTAT de la page, le report en est la MATIÈRE.* |

---

## §4 — LES OBJETS

### 4.1 Le propriétaire

| | |
|---|---|
| **R4.1** | **L'adresse du propriétaire EST imprimée** — bloc d'adressage en haut de la première page de chaque compte rendu (c'est un document qu'on met sous enveloppe). **903 adresses sur 948 CRG (95 %)** : septeo 97 %, emery_immo 98 %, lyon 88 %. |
| **R4.2** | 🔴 **UNE LACUNE DE SORTIE N'EST PAS UNE LACUNE DE SOURCE.** Dire « le document ne le donne pas » est une affirmation **sur le document** : elle exige d'avoir ouvert la page. *La table `tiers` de la V2 est entrée avec 0 adresse sur 1 782 parce que le lecteur n'avait jamais capté le bloc.* |
| **R4.3** | **Un compte inconnu de MBI n'est pas une erreur, c'est un NOUVEAU** : il est reconnu à 100 %, comme nouveau. |

### 4.2 L'immeuble

| | |
|---|---|
| **R4.4** | 🔴 **LA CLÉ EST LE CODE, JAMAIS LE NOM.** Deux immeubles peuvent porter le même nom — SCI LOCA-VENTE a deux « AGENCE RIOM », codes `01540281` et `01540282`, soldes 5 499,80 € et 2 580,62 €. S'en servir comme clé fait **disparaître un immeuble entier** du tableau ET du total, **sans que rien ne proteste**. |
| **R4.5** | **Toute clé d'objet rattaché à un mandant porte `agence/compte/code`.** Sans le compte, un immeuble partagé par deux bailleurs obtient UNE clé pour DEUX comptes : le mandant perdant se retrouve sans aucun immeuble, et ses lots pointent vers une clé qui appartient à quelqu'un d'autre. *6 comptes de Vienne concernés.* |
| **R4.6** | **Un bâtiment partagé reçoit UN CODE PAR COMPTE** — les 4 premiers chiffres du code d'immeuble sont ceux du compte. 10 immeubles MBI sont désignés par deux codes → table `immeuble_codes_crg`, N codes → 1 immeuble. **Deux CRG de comptes DIFFÉRENTS visant le même immeuble = légitime. Deux CRG du MÊME compte = contradiction.** Pour les **lots**, c'est l'inverse : un bien n'a qu'un compte à la fois. |
| **R4.7** | **Le récapitulatif liste plus d'immeubles qu'il n'ouvre de sections de détail.** FOCH : 20 soldes, 18 pavés. Les deux manquants sont à 0,00 € avec leur adresse — **il ne s'est rien passé ce trimestre**. Le lecteur a raison de ne rien trouver ; les jeter faisait disparaître 14 bâtiments. Ils entrent avec `sans_detail: true` et leur `solde_recapitulatif`. **Leur absence de détail n'est pas une vente** — le CRG ne dit pas pourquoi il ne s'est rien passé : c'est une **question**. |
| **R4.8** | **Un immeuble sans code reste un immeuble.** 49 bâtiments de VIENNE et CHAPONOST ont une rue imprimée et **aucun numéro d'éditeur** ; les écarter faute de code perdait 109 immeubles. |
| **R4.9** | **Une adresse se filtre par la STRUCTURE du document, pas par la forme de la rue.** Exiger un numéro ou un type de voie rejetait `Chabrepine`, `LIEUDIT LE MONINSABLE`, `ZAC DU TISSOT` — des adresses valides. La bonne règle **nomme les en-têtes** (`SITUATION DES LOCATAIRES`, `RECAPITULATIF DES OPERATIONS`, noms d'agence), et filtre **AVANT** de s'en servir comme clé de repliement : deux immeubles partageant le même en-tête auraient fusionné. |
| **R4.10** | **Le nom d'un immeuble peut COMMENCER PAR UN CHIFFRE** (« 238 ROUTE DE VIENNE ») : ne jamais le filtrer là-dessus. |

### 4.3 Le lot et le bien

| | |
|---|---|
| **R4.11** | **Identité d'un lot = `compte × référence`**, jamais le numéro seul. |
| **R4.12** | 🔴 **UN LOT N'EST PAS UNE LIGNE.** Le CRG imprime **une ligne par locataire successif** : celui en place, puis chaque ancien resté débiteur. Prendre `occurrences[-1]` retenait souvent l'ancien. *EVEREST : 6 239,79 € appelés au lieu de 13 459,33 €, et les noms affichés étaient ceux des partis.* |
| **R4.13** | **Un lot de GESTION ≠ un lot de COPROPRIÉTÉ.** Un logement loué correspond très souvent à **plusieurs** lots de copro (le principal, plus la cave ou le garage) : un numéro de gestion → N lots de copro. Le lecteur ne lit que le numéro de **gestion**. Ne jamais rapprocher un lot sur son numéro nu, des deux côtés. |
| **R4.14** | **Un immeuble en copropriété porte les lots de PLUSIEURS bailleurs, tous légitimes.** L'import « adoptait » n'importe quel bien libre du même immeuble et gravait dessus son `code_crg`, son `numero_lot` et son `id_proprietaire` : une ligne `biens` servait à DEUX lots de DEUX bailleurs, et celui du premier disparaissait de sa vue 360. `reference_bien` est le **témoin** — l'adoption ne l'écrit pas. *Signature du défaut : supprimer et réimporter ne change RIEN.* |
| **R4.15** | **« Local » = COMMERCE. Seul le SILENCE vaut HABITATION.** Type lu sur 2 291 lots en libellé brut : `Local` / `Local commercial` / `Entrepot` → **LOCAL COMMERCIAL** · `Appartement` / `Appart. Tn` / `Studio` → **APPARTEMENT** · `Maison` → **MAISON** · `Garage` → **GARAGE** · *(rien d'écrit)* → **HABITATION**. |

### 4.4 Le locataire et l'occupation

| | |
|---|---|
| **R4.16** | 🔴 **`statut_locatif` est une DÉDUCTION, jamais une preuve** (`occupé si loyer_appelé > 0 sinon parti`). Un débiteur en place devenait un partant : **38 % d'occupation au lieu de 93,5 %**. Ne jamais l'employer. |
| **R4.17** | **`ABSENCE DANS UN NOUVEAU CRG ≠ DÉPART.`** Un départ n'est DÉMONTRÉ que si le lot est réénoncé à une période ultérieure **sans** cet occupant. Si le lot cesse simplement d'apparaître, c'est **À ARBITRER** : le CRG peut ne pas avoir été déposé. |
| **R4.18** | **Trois preuves seulement valent un départ** : une fin de bail imprimée, un loyer appelé couvrant la clôture, ou une succession sur le lot. |
| **R4.19** | **Un ancien nom porté n'est pas un départ.** Le CRG réimprime un locataire sorti tant que son solde n'est pas apuré — parfois des années. Une locataire lue « Du 01.01.09 Au 24.01.09 », montants à 0,00 €, dans un rapport de 2026, est partie il y a seize ans. *Comptée comme un départ, elle gonflait un dépôt de 23 à 93.* |
| **R4.20** | 🔴 **LA CHRONOLOGIE SE LIT SUR TOUTES LES PÉRIODES DU DÉPÔT, pas sur la dernière.** La suite `AVRIL : DUPONT · MAI : DUPONT · JUIN : MARTIN · JUILLET : MARTIN` démontre **QUAND** le titulaire a changé. C'est la succession documentaire qui fait la preuve, pas l'état final. |
| **R4.21** | **Un ancien locataire n'est JAMAIS supprimé, et sa dette lui reste attachée.** La créance d'un ancien ne passe jamais au suivant. *Sur le lot 01330187-0054 : YUSUF devait 85 € et SREOUNA, parti, 10 444 €. Additionner les deux faisait d'un locataire à jour un débiteur de 10 530 € — la relance serait partie à la mauvaise personne.* |
| **R4.22** | **Toute unité publiée est un OBJET, jamais une ligne.** Compter les lignes d'occupation multiplie tout par le nombre de PÉRIODES du dépôt — et **la cadence n'est pas la même partout** : un dépôt MENSUEL observe chaque lot 4 fois là où un trimestriel l'observe 1 fois, ce qui rend les agences incomparables. *440 « en place » publiés pour 108 lots, 93 partis pour 23.* |
| **R4.23** | ⚠️ **La phase 3 ne désigne PAS « le locataire actuel du lot ».** Elle qualifie chaque **observation**. Déduire le titulaire en prenant « la dernière ligne » désigne l'ancien débiteur quand il figure à la même date d'arrêté que l'occupant en place. *Manque identifié le 11/09/2026, non comblé.* |
| **R4.24** | 🏢 **L'appel de loyer a DEUX écritures.** ICS (LYON, RIOM) : `Du 01.01.26 Au 31.01.26`. SPI (VIENNE, CHAPONOST) : `TERME Avril 2026` / `TERME du 17/04/2026 au 30/04/2026`. Ne lire que la forme ICS laissait le compteur mort sur **882 occupations (31 %)** — CHAPONOST 402/402 et VIENNE 480/480 à zéro. |
| **R4.25** | **Un appel n'est pas forcément un loyer.** Un **dépôt de garantie vaut appel** (loyer gratuit jusqu'au 31.05, mais le DG a bien été demandé). `Gratuité de loyer`, `Remise sur Loyer` accompagnent un TERME sans l'annuler. Et le terme dépend du **type de bail**. |
| **R4.26** | **Une régularisation de l'an passé n'est pas un appel de la période.** Trois lignes `Du 01.01.25 Au 31.12.25` dans un CRG 2026-T1 faisaient croire à un locataire présent. **Un appel ne compte que si sa période RECOUPE celle du CRG.** |

---

## §5 — L'ARGENT

### 5.1 Les grandeurs qui ne se confondent jamais

> **R5.1** — 🔴 **TROIS NOTIONS, JAMAIS DES SYNONYMES :**
> 1. **SOLDE COMPTABLE DU CRG** — ce que le document imprime pour un compte, à une date ;
> 2. **IMPAYÉS LOCATAIRES** — ce que les locataires doivent. **Jamais une sortie d'argent** :
>    un impayé n'a pas été encaissé, il ne peut donc pas rendre une trésorerie négative ;
> 3. **TRÉSORERIE DISPONIBLE** — ce qu'un bailleur peut réellement retirer.
>
> Elles ne s'additionnent jamais. *Un chiffre juste sous un mot ambigu est un chiffre faux pour
> celui qui le lit* — « créances » a été renommé **« impayés locataires »** pour cette raison.

**R5.2** — **Trois niveaux, jamais l'un pour l'autre** : le **compte mandant** (sa situation
propre — il PEUT être débiteur, sans anomalie) · le **tiers / périmètre bailleur** (la somme de
ses comptes ; **on REGROUPE, on ne FUSIONNE pas**) · l'**agence**.

### 5.2 La composition d'un solde de compte

```
report d'entrée  +  Σ soldes d'immeuble  +  Σ opérations du récapitulatif  =  SOLDE DU COMPTE
```

| | |
|---|---|
| **R5.3** | Vérifié au centime sur **146 pièces LYON, écart 0,00 €**, et fermé à **100 % sur 404 pièces** LYON + EMERY_IMMO. |
| **R5.4** | 🔴 **LE RÉCAPITULATIF NE CONTIENT PAS LES ENCAISSEMENTS.** Les loyers encaissés sont déjà **nettés** dans le solde de chaque immeuble (réglé − charges − honoraires). Le récapitulatif ne porte que ce qui **dépasse** l'immeuble : reversements au propriétaire, compensations entre comptes, impôts, prêts, prestations. *Les y chercher donnait « encaissé : 0,00 € » sur un compte ayant reçu 339 732 € de loyers.* |
| **R5.5** | ⚠️ **LE REPORT EST DÉJÀ UNE LIGNE DES TOTAUX IMPRIMÉS.** L'ajouter une seconde fois compte le même euro deux fois — *105 pièces en faux écart avant correction.* |
| **R5.6** | ⚠️ **`mandat.totaux` n'est PAS le total des opérations** : c'est le **cumul général du compte**, report et soldes d'immeuble compris — il égale donc le solde à lui seul. L'employer comme « somme des opérations » puis y rajouter le report ne ferme plus que sur 3 pièces sur 404. |
| **R5.7** | **On ne force JAMAIS `encaissé − dépensé = solde immeuble`** : la différence est le **report interne** de chaque immeuble, que le document n'imprime nulle part. |
| **R5.8** | **Les honoraires se prennent PAR IMMEUBLE**, jamais sur le récapitulatif : ils sont déjà compris dans le solde de chaque immeuble reporté au récap. |
| **R5.9** 🏢 | **Les 61 pièces SEPTEO n'impriment ni report ni soldes d'immeuble** : le contrôle de composition y est **impossible**, et **se déclare tel** plutôt que de rendre 0,00 €. |

### 5.3 La page 1 — le contrôle croisé le plus fort

| | |
|---|---|
| **R5.10** | La régie **calcule elle-même la trésorerie nette de chaque immeuble et l'imprime en page 1**. Structure par immeuble : **deux lignes** (le NOM, puis l'ADRESSE) et **UN SEUL montant** = crédit − débit. Notre calcul doit y retomber au centime : `solde immeuble = encaissé locataires + crédits d'écritures − débits d'écritures`. *Vérifié 255/255 immeubles sur 20 documents.* |
| **R5.11** | **Les crédits d'écritures ne sont pas facultatifs** — remboursements du syndic, régularisations. Les oublier creusait le solde de 74,90 € et 68,42 € sur deux immeubles. |
| **R5.12** | **La page 1 porte des mouvements de niveau MANDAT, jamais des charges d'immeuble** : remboursement de prêt, acompte versé au bailleur, report du trimestre précédent. Ils vivent dans `crg_mandat` / `crg_mandat_lignes`. |
| **R5.13** | Le récapitulatif du mandat **déborde sur les pages suivantes** dès qu'il y a beaucoup d'immeubles. **Le discriminant : une page d'immeuble porte `Immeuble :`, le récapitulatif du compte non.** |

### 5.4 Stock ≠ flux

| | |
|---|---|
| **R5.14** | 🔴 **UN ENCOURS EST UN STOCK : on ne l'additionne JAMAIS entre deux arrêtés.** Le total « impayés LYON hors SIR = 952 643,66 € » était exactement cela — la somme de cinq arrêtés, avec 132 situations vues à plusieurs clôtures. À la seule date du 31/03/2026, l'encours vaut **478 608,43 €**. `crg_encours_cumuler()` lève une exception dès qu'on lui passe deux dates. |
| **R5.15** | **Un total d'agence n'est calculable qu'à DATE HOMOGÈNE** : sinon `TOTAL AGENCE NON CALCULABLE` (VIENNE 61/61 ✓ · EMERY 192/224 = 85,7 % ✗ · LYON 55/60 = 91,7 % ✗). |
| **R5.16** | **`total_du` porte l'ARRIÉRÉ** : `loyer appelé + provisions + taxes + divers + arriéré reporté`. Ce n'est **pas** un appel du trimestre. *48 595,60 € de « compléments appelés » affichés là où il y avait 6 065,17 € appelés et 42 530,43 € d'arriéré — quatre locataires à « loyer appelé 0,00 € », donc des lignes de dette pure.* Formule juste : `compléments = taxes + provisions + divers`, puis `arriéré = total_du − loyer_appelé − compléments`. |
| **R5.17** | **`total_du` n'est JAMAIS recalculé, il est LU.** Un lot porte un solde antérieur **créditeur** de −955,92 € que le total imprimé n'intègre pas : recalculer reviendrait à corriger le document. |
| **R5.18** 🏢 | **Le solde antérieur a DEUX faces** : il **entre** dans le total du trimestre du locataire, et **ne se cumule JAMAIS** d'un trimestre au suivant. « Additionnable » n'est donc pas un booléen — il manque cet état à l'écran d'arbitrage des libellés. |
| **R5.19** | **Le report EST le solde du trimestre précédent**, lui-même issu du précédent : toute la chaîne est déjà dans le document. Pour une **situation à date**, il faut **le dernier CRG de chaque compte** — zéro trimestre antérieur. *J'allais en réclamer 63 à 14 propriétaires ; il en fallait zéro.* Pour « combien avons-nous versé sur l'année », il faut **chaque** trimestre. |

### 5.5 Ce qui n'est pas une charge

| | |
|---|---|
| **R5.20** | 🔴 **Les charges du CRG sont gonflées par de la trésorerie.** Mesure : `CHARGE` = 3 528 006 €, soit **57 % du loyer appelé** — invraisemblable en soi. **677 mouvements / 2 353 174 € (66,7 %) ne sont pas des charges** : virements 44,7 % · compensations 14,5 % · trésorerie/remboursements 3,5 % · acomptes 1,8 % · libellé vide 2,2 %. Charge réelle ≈ **1 174 832 €**, soit **19 % du loyer appelé** — enfin plausible. |
| **R5.21** | **Le lecteur classe sur la FORME du libellé, pas sur le FAIT.** Le même reversement au propriétaire s'écrit `Reglement virement du <date>` (→ VERSEMENT) et `Vrt Règlement au <date>` (→ **CHARGE**, 449 mouvements, 415 380 €). Idem pour les compensations. |
| **R5.22** | **Il additionne des CRÉDITS dans un poste de dépense** : 200 lignes `section=HONORAIRES` + `colonne=credit`, 120 178 € — ce sont les **assiettes** sur lesquelles l'honoraire se calcule (« Loyer Avril 2026 SOUVY »), pas des dépenses. |
| **R5.23** | **Le bloc « charge du BIEN » passe AVANT le motif de virement** (ENGIE, EDF, ORANGE, salaires, assurances, copropriété, taxe foncière, honoraires de gestion), sinon « PRELVT ENGIE » bascule en versement au seul motif qu'un virement le porte. *30 prélèvements LYON attendaient ce piège.* |
| **R5.24** | **Les appels de fonds copropriété et travaux ne sont JAMAIS imputés aux locataires** : ils sont côté **dépenses du bailleur**. *FOCH : 7 648,21 € sur 9 550,47 € de charges.* |

### 5.6 Versements, compensations, apports

| | |
|---|---|
| **R5.25** | **Le versement au propriétaire a SEPT familles, toutes validées** : acompte périodique · acompte nommé · virement à sa banque · remboursement de prêt ou financement · affaire judiciaire (avocat, huissier, CARPA) · société du groupe · tiers personnel. **La destination ne change pas la nature : c'est son argent qui sort.** *1 929 945 €, 738 lignes.* |
| **R5.26** | **L'apport du bailleur : c'est le SENS qui tranche, pas le libellé.** « Votre chèque » est un versement d'acompte **en débit**, et un **apport de trésorerie en crédit** quand on demande au bailleur de renflouer. Un apport **augmente** le disponible : l'oublier fait crier au dépassement sur un compte qu'on vient de renflouer. |
| **R5.27** | 🔴 **COMPENSATION AU DÉBIT = acompte propriétaire. AU CRÉDIT = apport de trésorerie** reçu d'un autre bailleur du périmètre. Ce n'est pas un jeu d'écriture neutre : l'ignorer accusait SABY de **135 047 €** de versement en trop, alors que le dépassement réel est de 15 827 €. |
| **R5.28** | **Au niveau du PÉRIMÈTRE, les compensations s'annulent** (ce que SMH donne, SABY le reçoit). Les compter en consolidé gonflerait la trésorerie d'un groupe du montant de ses propres virements internes. **Mais elles n'effacent jamais un solde négatif** : le compte qui donne reste débiteur, et son déficit doit rester visible ligne à ligne. |
| **R5.29** | **Les règlements s'imputent sur les loyers LES PLUS ANCIENS** (anti-forclusion). Donc : **un lot qui n'appelle plus rien peut parfaitement encaisser** — le tableau des appels n'en montre rien — et **un encours qui BAISSE est un encaissement**, c'est sa seule trace. **L'absence d'appel ne prouve NI l'absence d'occupant, NI l'absence d'argent.** *10 périmètres muets dont l'encours baisse.* |
| **R5.30** | **Le compte d'attente** (`4700001200000000`, mis en place pour éviter les saisies) : la trésorerie quitte le compte du mandant pour n'y être plus saisissable. **Le CRG continue de la compter au mandant pendant que le compte 410 ne l'a plus** — d'où l'écart, sans qu'aucune source ne soit fausse. **La position d'un mandant = `410 + sa quote-part du compte d'attente`.** |

---

## §6 — LES CATÉGORIES DE CHARGES ET RECETTES

> **R6.1** — 🔴 **LA CATÉGORIE SE LIT SUR LE TITRE DE SECTION, jamais sur le libellé de la
> ligne.** *« Il faut prendre le titre des catégories et pas la peine d'aller dans le détail. »*
> Le libellé d'une ligne est du texte libre saisi par un gestionnaire : l'interpréter, c'est
> deviner. C'est ce qui explique que `autre` soit la plus grosse catégorie de `crg_ecritures`.

| | |
|---|---|
| **R6.2** | **Un titre est une ligne du bloc `RECAPITULATIF DES OPERATIONS` d'une PAGE D'IMMEUBLE, SANS MONTANT, posée À LA MARGE GAUCHE DU BLOC**, écrite soit `- Xxx -`, soit TOUT EN MAJUSCULES. |
| **R6.3** | **La marge se lit sur le BLOC** — le plus petit retrait qu'on y rencontre, celui de la colonne « tiers » que le titre partage — **jamais sur l'en-tête « Locataires »**, indenté de 16 à 26 selon les pages alors que le titre reste collé à 16. *S'y ancrer faisait perdre le titre d'un immeuble entier, et 25 lignes d'argent avec.* |
| **R6.4** | **Ce que la marge écarte, sans aucune liste noire** : les libellés de ligne en majuscules (`GARANTIE DE LOYER`, `CONTRAT ENTRETIEN P2` — colonnes 61-70) · les sous-titres de nature en casse mixte (`Assurance PNO`, `Appel de fonds` — colonnes 39-81) · les noms de locataires et d'immeubles (hors du bloc) · les postes d'appel de fonds (`CHARGES GENERALES`, `HONORAIRES SYNDIC` — autre page). |
| **R6.5** | **Deux niveaux de titre, à ne pas confondre** : le **grand titre** (la catégorie, à la marge) et le **sous-titre** (la nature, colonne ~69) qui partage sa colonne avec les commentaires postérieurs au mouvement. **S'en tenir au grand titre.** |
| **R6.6** | **La table est ADDITIVE, jamais réécrite.** On ne normalise que la **décoration** (tirets, étoiles, accents, casse). **Aucune liste d'autorisation : un titre inconnu est un titre, pas une erreur.** C'est la règle `ÉDITEUR → MOTEUR → VARIANTE` : on n'ajuste pas un paramètre, on ajoute un libellé, et ce qui marchait continue de marcher. |
| **R6.7** | 🏢 **La convention n'est PAS propre à une agence.** `emery_immo` écrit surtout en MAJUSCULES, `lyon` surtout en tirets, **mais les deux formes coexistent dans les deux corpus** — 6 documents `emery_immo` en tirets, et 154 immeubles `lyon` en majuscules pour la seule `TAXE FONCIERE`. **Lire la FORME, ne jamais brancher la lecture sur l'agence.** |
| **R6.8** | **Les honoraires de gestion, leur TVA, le total des règlements locataires et le report n'ont PAS de titre** : ils sont portés par le compte rendu, pas par une catégorie d'immeuble. Ils sont **nommés**, jamais jetés, et entrent dans le bouclage. |

### La table relevée — 24 titres sur 436 pièces (11/09/2026)

| titre canonique | docs | `emery_immo` | `lyon` |
|---|---:|---:|---:|
| `DEPENSES DEDUCTIBLES` | 278 | 129 | 149 |
| `DEPENSES DE SYNDIC` | 120 | 4 | 116 |
| `DEPENSES LOCATIVES` | 64 | 28 | 36 |
| `TAXE FONCIERE` | 48 | 2 | 46 |
| `DEPENSES COPRO` | 34 | 34 | — |
| `CHARGES COMMUNES GENERALES` | 33 | 3 | 30 |
| `DEPENSES NON DEDUCTIBLES` | 20 | 11 | 9 |
| `DEPENSES DIVERSES` | 18 | 1 | 17 |
| `CHARGES ASCENSEUR` | 15 | 1 | 14 |
| `CHARGES EAU FROIDE M3` | 9 | 9 | — |
| `CHARGES GENERALES` | 8 | 8 | — |
| `TAXES ORDURES MENAGERES` | 6 | — | 6 |
| `CHARGES COMMERCES TVA 20 %` | 6 | — | 6 |
| `DEPENSES HORS GESTION` | 5 | 2 | 3 |
| `AUTRES` | 4 | 4 | — |
| `ENTRETIEN COMMUNS` · `RECETTES DIVERSES` · `EAU FROIDE COMPTEUR 1` | 2 | | |
| `ENTRETIEN CHAUDIERE` · `GARANTIE DE LOYER` · `CONTRAT CHAUDIERE` · `CHARGES CHAUFFAGE GAZ` · `ENTRETIEN CLIMATISATION` · `CHARGES LOCATIVES GENERALES` | 1 | | |

🏢 Le format SEPTEO porte son propre vocabulaire, en tirets : `- Honoraires de Gestion -` ·
`- GU Assurance -` · `- GLI Assurance -` · `- PNO Assurance -` · `- Charges Propriétaire -` ·
`- Charges de syndic -` · `- Charges locatives -` · `- Autres Honoraires -` ·
`- Versement Propriétaires -`.

---

## §7 — LES CONTRÔLES QUI REFUSENT

> **R7.1** — 🔴 **FAIL-CLOSED.** *« Le solde des immeubles en détail et le total du CRG doivent
> ABSOLUMENT correspondre. Si le solde calculé diffère de celui donné dans le CRG, il faut
> recommencer. »* Un CRG qui ne boucle pas n'est pas un CRG à peu près bon : c'est un CRG dont
> la lecture est fausse — et tout ce qu'on en tire ensuite l'est aussi.

| contrôle | ce qu'il oppose | état mesuré |
|---|---|---|
| **C1 — bouclage du compte** | `Totaux Généraux` du récapitulatif **lus** → solde annoncé **lu** | 402/436 OK · 24 sans solde annoncé · 2 sans récapitulatif · **8 écarts réels** |
| **C2 — concordance par immeuble** | net de chaque immeuble **calculé** → son solde au récapitulatif **lu** | intégré à C1 |
| **C3 — bouclage des catégories** | Σ catégories + lignes sans titre → `Totaux Généraux` de l'immeuble | **436/436 au centime** |
| **C4 — réconciliation des écritures** | `débits imprimés = Σ débits` · `crédits imprimés = Σ crédits + TOTAL DES RÈGLEMENTS LOCATAIRES` | 149/149 blocs |
| **C5 — page 1** | `encaissé + crédits − débits` → solde imprimé par la régie | 255/255 immeubles |

| | |
|---|---|
| **R7.2** | **Chaque contrôle déclare ce qui est LU et ce qui est CALCULÉ.** Un contrôle qui oppose deux valeurs **calculées** vérifie seulement que le code est d'accord avec lui-même. |
| **R7.3** | **Trois états, pas deux** : `reconcilie` · `sans_operations` (un immeuble calme — rien à importer, rien à signaler) · `ecart` (bloquant). |
| **R7.4** | **Une identité comptable ne se réfute pas sur un seul essai.** Annoncer « formule fausse » a failli faire réécrire un contrôle qui marchait. |
| **R7.5** | 🔴 **PAS DE LISTE NOIRE.** *« Tu ne dois pas chercher, cela veut dire que cela ne fonctionne pas. »* Garder une ligne SAUF si elle figure dans une liste d'exclusions est sans fin : chaque document apporte un libellé imprévu. `Compensation Solde débiteur Mdt 1104` contenait « Solde d » et était jetée — **34 137,00 € perdus** sur GPE IMMO DR. **Le document publie ses propres totaux : on les lit, on ne les reconstitue pas.** |
| **R7.6** | **Un ordre de grandeur qui choque le métier vaut mieux qu'un contrôle technique vert.** *« On ne peut pas appeler + de charge que de loyer ??? »* — une phrase a trouvé ce que tous les contrôles laissaient passer. |
| **R7.7** | **Une phase ne s'ouvre qu'après validation de la précédente**, et une validation porte l'**empreinte** du résultat validé. Si l'analyse est rejouée et que le résultat change, la validation ne vaut plus. *Vérifié le 12/09/2026 : les 4 agences rendent une empreinte identique à celle scellée le 09/09.* |
| **R7.8** | ⚠️ **Une empreinte scellée sur un amont refondu ne vaut plus rien.** Les phases 4 et 5 portaient `VALIDEE` au 06/09 sur une phase 3 rejouée les 07-08/09 : à traiter comme **à refaire**. |

---

## §8 — DÉDOUBLONNAGE

| | |
|---|---|
| **R8.1** | **UN DOCUMENT PHYSIQUE = UNE CONTRIBUTION.** Le filtre est **en tête** du traitement : un `_sha` déjà vu → le JSON est ignoré, et les fichiers écartés sont **imprimés nommément**. En aval, deux copies identiques sont indiscernables par construction. *6 PDF extraits en 12 JSON : 199 lignes locataires en double, 946 mouvements, et sur un seul compte +1 421 685 € d'impayés.* |
| **R8.2** | **On dédoublonne sur `(agence LUE, compte, période)`**, jamais par dossier : le même CRG rangé chez LYON et chez EMERY IMMO passait pour deux documents. Un `.tmp` perd à égalité. |
| **R8.3** | 🔴 **LA RÉÉDITION EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER, PAS DU PDF.** Deux pièces d'une même situation peuvent être identiques au centime sur les **charges**, et porter 75 appels **complémentaires** absents partout ailleurs. Neutraliser les fichiers entiers perdait 57 962,14 € ; les lire entiers comptait 28 606,44 € deux fois. **Les deux voies étaient fausses.** On dédoublonne sur `(situation de gestion, événement métier)` — situation = `agence × compte × date d'arrêté`, **étendue à `immeuble` + `lot` chez `septeo_spi`**. On prend la multiplicité **maximale** par pièce, jamais la somme. |
| **R8.4** | ⚠️ **Vérifier après coup que les vraies lignes multiples survivent** : un lot peut porter deux lignes métier pour le même locataire et le même trimestre (ligne active + dette résiduelle), à des positions `y` différentes. *8 cas réels sur LYON.* |
| **R8.5** | **Un CRG marqué « MÊME CLÉ CONTENU DIFFÉRENT » n'est PAS un doublon** — c'est le cas FOCH/SABY, deux documents qui ne partagent qu'une page sur trois, donc **complémentaires**. Les filtrer sur `= "UNIQUE"` les écartait de l'inventaire, du patrimoine, des occupations **et de l'argent** : le CRG entier disparaissait. |

---

## §9 — 🏢 TABLEAU DES SPÉCIFICITÉS PAR AGENCE

| | **REGIE EMERY LYON** | **EMERY IMMO (RIOM)** | **REGIE EMERY VIENNE** | **CHAPONOST** |
|---|---|---|---|---|
| Éditeur / moteur | ICS · `lyon` | ICS · `emery_immo` | SEPTEO SPI | SEPTEO SPI |
| Agence imprimée | ❌ déduite du CP `69007` | ❌ déduite du CP | ✅ `Agence : A3 - …` | ✅ |
| Compte mandant | `COMPTE PERSONNEL 01040000` | idem | `Identifiant extranet 11054…` | idem |
| **Périodicité** | **trimestre** | **trimestre** | 🔴 **MOIS**, parfois irrégulier | 🔴 mois |
| Notations de période | **3 formes** | idem | `Période du … au …` | idem |
| Volume typique | ~33 pages | 2-4 pages | 1-4 pages | — |
| **1 CRG = ?** | 1 compte, N immeubles | 1 compte, N immeubles | 🔴 **1 IMMEUBLE** | 🔴 **1 IMMEUBLE** |
| Référence de lot | `code_immeuble` + `numero` | `04830243-0002` | `01G01-5213-000367` | idem |
| Forme de l'appel | `Du 01.01.26 Au 31.01.26` | idem | **`TERME Avril 2026`** | **`TERME …`** |
| Titre de catégorie | surtout **tirets** | surtout **MAJUSCULES** | tirets, vocabulaire propre | — |
| Période du lot | ligne séparée | 🏢 **sur la ligne du lot** | — | — |
| Ligne de totaux du lot | libellée | 🏢 **sans aucun libellé** | — | — |
| Report + soldes immeuble | ✅ imprimés | ✅ | ❌ **absents** | ❌ |
| Indivision | absente | absente | ✅ `quote-part 50/50`, **CRG par INDIVISAIRE** | — |
| Mot de passe extranet | — | — | 🔐 **en clair 71/71** | 🔐 |
| Colonnes lues (09/09) | complètes | ❌ ni solde antérieur, ni total, ni réglés | ❌ loyers/reste dû/débit/crédit | ❌ idem |
| Adresse propriétaire | 88 % | 98 % | 97 % | — |
| Historique traîné | non | non | 🔴 **oui** (1 facture dans 5 CRG) | — |

**R9.1** 🏢 — 🔴 **Le lecteur ne connaît vraiment que le format LYON.** Sur EMERY IMMO,
235 CRG ne portent **ni solde antérieur, ni total, ni réglés** ; sur CHAPONOST et VIENNE il ne
reste que `loyers`, `reste_du`, `debit`, `credit`. **Aucun solde de locataire n'est calculable
sur trois agences sur quatre.** Ce n'est pas un problème de classement de libellés : reclasser
ne fera jamais apparaître une colonne que le lecteur n'a pas vue. Le chantier est en amont — la
**variante d'éditeur**.

**R9.2** 🏢 — **Spécification des colonnes EMERY IMMO** : col. 3 appel de loyer · col. 4 taxe
(TVA), *s'additionne au loyer* · col. 5 provisions de charges · col. 6 lignes complémentaires.
**Le signe porte le sens en col. 6** : avec un `−` c'est un **crédit** (déduction du loyer ou
des charges), sans signe c'est un **débit** que le locataire doit.

**R9.3** 🏢 — **Ventilation EMERY IMMO** : le lecteur remplit `total_du`, `total_regle`,
`total_impaye` et tout le détail mensuel, mais laisse les **agrégats à 0**. **Quand `mois[]`
existe, sa somme fait foi.** *Taxes 0 → 1 365,30 € · provisions 0 → 55 006,46 € · divers
12,00 → 139 676,77 €. 213 comptes sur 224 touchés.* Un chiffre juste au total peut être faux
dans son détail.

**R9.4** 🏢 — **Ventilation SEPTEO** : partition **sans recouvrement** — `taxes` (taxe
foncière, TEOM) · `provisions` (charges, eau) · `divers` (tout le reste). Trois natures ne
tombaient dans aucun total (1 708,86 €) et `total_divers` reprenait une colonne déjà comptée
(12 lots). **La formule n'est pas un dogme** : 3 lots sur 111 vérifient `L+T+P+D = total_du`
**sans** le solde antérieur — **la vérité est le document, pas la formule**.

---

## §10 — CE QUI N'EST PAS CERTIFIÉ AUJOURD'HUI

| | |
|---|---|
| 🔴 **PHASE 4 (FINANCES)** | **Non certifiable. Ne rien sceller.** Surcompte mesuré **+19 à +26 %** par catégorie sur VIENNE : une facture ENGIE du 26/11/2025 apparaît dans **5 CRG** — ce ne sont pas des rééditions, donc le marqueur `reimpression` ne les attrape pas. Recouvrement CHARGE/FRAIS. Agrégats comptés comme dépenses. |
| 🔴 **`crg_ecritures`** | 26 711 lignes. Les **axes sont bons** (`id_immeuble`, `id_bien`, `numero_lot`, `niveau_affectation`, traçabilité jusqu'à `sha_ligne`) mais **167 lignes > 1 M€ totalisent 94 % du total** — des dates, des codes et du texte lus comme des montants. **Ne rien modifier, supprimer, plafonner ni exclure tant que la cause n'est pas établie sur le PDF.** Seulement 56 % des écritures « immeuble » portent un `id_immeuble`. |
| ⚠️ **Le détail locataires** | Le **nombre de colonnes VARIE** d'un document à l'autre (SMH porte une colonne non étiquetée entre `Divers` et `Total`) : la lecture par position décale tout. Et la colonne `Loyers` d'un TOTAUX porte le **cumul, arriérés compris** — 165 006,86 € sur un seul immeuble. **À refaire en reconnaissant chaque colonne par son EN-TÊTE, une par une.** |
| ⚠️ **Le titulaire d'un lot** | La phase 3 ne le désigne pas (R4.23). |
| ⚠️ **8 écarts de bouclage** | Tous à 1 immeuble, sur GROUPE SIR et FOCH. **À conserver comme écarts, pas à corriger.** |
| ⚠️ **P3 perd l'occupant** | d'un lot déclaré en bas d'une page et redéclaré en haut de la suivante — la première déclaration, vide, l'emporte. Constaté sur CHAPONOST **et** VIENNE. **Non corrigé** : corriger changerait une sortie certifiée. |
| ⚠️ **Deux numérotations de « phases »** | La **certification du lecteur** (P1 GED → P9 rentabilité) et le **module d'intégration** (`CRGI_PHASES` 0→5 : documents · inventaire · patrimoine · locataires/occupation · **finances** · bilan). **Ne jamais les confondre.** |

---

## §11 — MÉTHODE

| | |
|---|---|
| **R11.1** | **On ne pourra jamais déposer les CRG directement sur une page** : les paramètres de lecture ne peuvent pas être figés. Un import de masse se **conduit**, il ne se lance pas. |
| **R11.2** | **Mesurer avant d'annoncer**, et dire **OÙ** (local ? prod ? conteneur ?). « C'est corrigé » sans dire où n'a aucune valeur de vérité. |
| **R11.3** | **Corriger la RÉSOLUTION avant les DONNÉES.** Réparer les données sans réparer l'écrivain, c'est repayer la même facture au prochain lot. |
| **R11.4** | **Traiter une réalité métier ordinaire comme un bug est une faute** — au même titre que laisser une formule juste masquer un nommage faux. |
| **R11.5** | **Un défaut consigné mais non gardé se reproduit.** Un registre documente ; seule une **fonction** protège. |
| **R11.6** | **Toute absence sort dans un bloc « À TRANCHER »** avec son dernier CRG connu, son solde et ses impayés. Rien n'est classé tant que la question n'a pas de réponse. Les gestions perdues sont **déclarées**, jamais constatées par silence — et « vendu », « perte de gestion » et « oubli » sont **trois** causes distinctes. |

---

*Charte tenue à jour au fil des mesures. Toute règle ajoutée cite le défaut qui l'a provoquée
et le chiffre qui l'a démontré. Aucune règle n'entre ici comme une intention.*
