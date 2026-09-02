# -*- coding: utf-8 -*-
"""
LA FAMILLE D'UN CRG — UNE SEULE AUTORITÉ, POUR TOUT LE PROJET.

⚠️ CE FICHIER EXISTE PARCE QUE DEUX MOTEURS AVAIENT CHACUN LEUR IDÉE DU MONDE, ET CHACUN SON
   ANGLE MORT. Le 02/09/2026, sur les deux mêmes documents :

       document                            routeur certifié      module d'intégration
       CRG EMERY IMMO (RIOM, T2 2026)      emery_immo  ✔          lyon  ✘
       dépôt VIENNE (906 pages)            inconnu     ✘          septeo_spi  ✔

   Aucun des deux n'englobait l'autre. Le module d'intégration a donc lu un CRG EMERY —
   CERTIFIÉ depuis le 30/08, avec son lecteur `parse_crg_emery.py` — en croyant lire du LYON,
   et il a répondu `CERTAIN`. Il n'a pas rencontré un format inconnu : il a appelé le mauvais
   lecteur. C'est l'incident `P2-EXC-01(b)` pris dans l'autre sens.

⚠️ LA CAUSE DE L'ANGLE MORT CERTIFIÉ TENAIT EN UNE ESPACE. Le routeur cherchait la chaîne
   littérale « AGENCE : » ; le dépôt imprime « Agence: ». Une espace, et 906 pages devenaient
   illisibles pour le référentiel. On compare donc sur un motif, jamais sur une chaîne figée.

⚠️ L'ORDRE DES TESTS EST UNE RÈGLE, PAS UNE COMMODITÉ. Le nom de l'enseigne est SPÉCIFIQUE ;
   la structure (« COMPTE PERSONNEL » + un titre) est COMMUNE à LYON et à EMERY. Tester la
   structure d'abord classait tout EMERY en LYON — c'est exactement ce qui s'est produit.
   On interroge donc l'enseigne, puis seulement à défaut la structure.

⚠️ ET ON NE DEVINE JAMAIS. Un en-tête qui ne porte aucun des trois signes rend `inconnu`, et
   `inconnu` est une réponse : `AUCUN LECTEUR DE SECOURS` (doctrine `crg_depot_lire`). Un
   lecteur de repli n'est pas une prudence, c'est une version inférieure qui attend son heure.

⚠️ ET CES TROIS NOMS DÉSIGNENT DES AGENCES, PAS DES ÉDITEURS. Le parc n'utilise que DEUX
   logiciels : **ICS** (LOCA IMMO LYON et EMERY IMMO succ. SERVAJEAN) et **SPI** (VIENNE et
   CHAPONOST). Mesuré sur les documents : LYON et EMERY impriment le MÊME gabarit —
   « RECAPITULATIF DES OPERATIONS », « SITUATION DES LOCATAIRES », « COMPTE PERSONNEL »,
   « Immeuble : » ; VIENNE n'en imprime aucun. C'est cette confusion agence/éditeur qui a
   permis à EMERY de passer inaperçu : l'intégrateur croyait couvrir « le format LYON »
   alors qu'il couvrait une agence sur deux du même logiciel.

   Les étiquettes ci-dessous restent celles du référentiel certifié — les renommer serait
   modifier une doctrine figée. Mais elles se lisent ainsi :

    ÉDITEUR ICS ┬ lyon        → parse_crg_geo.py     LOCA IMMO LYON (19 bd Yves Farge)
                └ emery_immo  → parse_crg_emery.py   EMERY IMMOBILIER succ. SERVAJEAN (RIOM)
    ÉDITEUR SPI ─ septeo_spi  → parse_crg_septeo.py  VIENNE · CHAPONOST (aucun CRG fourni)

⚠️ DEUX LECTEURS POUR UN SEUL ÉDITEUR. `parse_crg_geo.py` et `parse_crg_emery.py` portent
   EXACTEMENT les douze mêmes fonctions, aucune n'appartient à l'un seul, et 96 lignes sur
   2 054 les séparent — 95 % de code identique, forké. Ils ne partagent EN REVANCHE aucune
   fonction avec le lecteur SPI. La duplication n'est donc pas entre le référentiel et
   l'intégrateur : elle est DANS le référentiel, entre deux variantes d'agence du même
   logiciel. Les fusionner toucherait des règles de lecture certifiées (P1 en nomme trois) :
   c'est un arbitrage, pas un correctif, et il n'est pas pris ici.
"""
import re

FAMILLES = ('lyon', 'emery_immo', 'septeo_spi')

# L'enseigne — spécifique, donc interrogée en premier.
RE_SEPTEO = re.compile(r'AGENCE\s*:')
RE_EXTRANET = re.compile(r'EXTRA')
RE_EMERY = re.compile(r'EMERY\s+IMMOBILIER|SAINT[-\s]JEAN')
RE_LYON = re.compile(r'LOCA\s+IMMO|YVES\s+FARGE')
# La structure — commune à plusieurs éditeurs, donc interrogée en dernier ressort.
RE_TITRE = re.compile(r'COMPTE\s+RENDU\s+DE\s+GESTION')
RE_COMPTE_PERSONNEL = re.compile(r'COMPTE\s+PERSONNEL\s+\d{6,10}')


def famille_du_texte(texte):
    """La famille que le document DÉMONTRE, ou `inconnu`.

    Le texte attendu est celui de la première page — entier ou réduit à son en-tête : les
    trois signes distinguent correctement dans les deux cas, ce qui est vérifié par le
    harnais. Rendre la même réponse aux deux appelants est tout l'objet de ce fichier.
    """
    u = (texte or '').upper()
    if RE_SEPTEO.search(u) and RE_EXTRANET.search(u):
        return 'septeo_spi'
    if RE_EMERY.search(u):
        return 'emery_immo'
    if RE_LYON.search(u):
        return 'lyon'
    # ⚠️ DERNIER RECOURS, ET IL NE TRANCHE QU'ENTRE CE QUI RESTE. « COMPTE PERSONNEL » sous un
    #    titre de CRG appartient à la famille `lyon` — mais seulement une fois EMERY écarté,
    #    car EMERY l'imprime aussi. Sans enseigne lisible, c'est la seule marque qui reste.
    if RE_TITRE.search(u) and RE_COMPTE_PERSONNEL.search(u):
        return 'lyon'
    return 'inconnu'
