# -*- coding: utf-8 -*-
"""
LA SEULE AUTORITÉ SUR « OÙ COMMENCE UN LOT » DANS UN CRG.

⚠️ CE MODULE EXISTE PARCE QUE TROIS PHASES LISAIENT LES LOTS CHACUNE DE SON CÔTÉ. La phase 2
   en voyait 98, les phases 3 et 4 en démontraient 124 : vingt-six lots que le document imprime
   disparaissaient du patrimoine, et le bilan d'intégration s'est construit sur le chiffre
   amputé sans que rien ne le signale. Ce n'était pas une erreur de calcul — c'était trois
   moteurs qui n'avaient pas les mêmes yeux.

⚠️ `EXACTITUDE ≠ EXHAUSTIVITÉ.` La phase 2 était juste sur les 98 lots qu'elle traitait. Elle
   était fausse sur la population. Une phase n'est valide que si elle démontre les deux — et le
   moyen le plus sûr d'y parvenir est que toutes les phases lisent la MÊME chose.

Toute phase qui a besoin de savoir quels lots porte un CRG passe par ici. Aucune ne redéfinit
son propre motif.
"""
import re

# ⚠️ L'OCR COUPE LES RÉFÉRENCES EN DEUX. « Lot 01 G01-5052-000115 » porte une espace au milieu
#    de sa référence : une classe sans espace s'arrêtait sur « 01 » et fusionnait deux
#    appartements voisins en un seul lot.
#
# ⚠️ ET UNE RÉFÉRENCE D'UN SEUL CARACTÈRE EST UNE RÉFÉRENCE. « - Lot 1 - Mandat N/A - » existe
#    sur ce corpus ; exiger trois caractères effaçait le lot et ses montants. Un seuil de
#    longueur n'est pas un critère métier.
RE_LOT = re.compile(r'-\s*Lot\s+([0-9A-Za-z][0-9A-Za-z \-]{0,28}?)-?\s*[–-]\s*Mandat')

# ⚠️ UN EN-TÊTE DE LOT MARQUÉ « SUITE » N'OUVRE PAS UN LOT. Quand le bloc déborde sur la page
#    suivante, le document réimprime l'en-tête et le termine par « Suite », SANS réimprimer la
#    ligne « Locataire: ». Les compter créait des lots « sans occupant » à arbitrer un par un.
RE_SUITE = re.compile(r'\.{0,3}\s*Suite\s*$', re.I)

# ⚠️ UN BLOC DE LOT PEUT PORTER PLUSIEURS OCCUPANTS SUCCESSIFS, ET LE DOCUMENT LE DIT :
#    « Locataire: DE SOUSA Jordan, Bail du 01/06/2025 au 04/06/2026 » suivi de
#    « Locataire: GONIN Marine, Bail du 22/06/2026 ». Huit blocs de ce dépôt sont dans ce cas.
#    La phase 3 ne gardait que le PREMIER, la phase 4 que le DERNIER : la succession était
#    perdue par l'une et mal attribuée par l'autre, sur la même page et le même lot.
#
# ⚠️ ET « AU … » EST UNE FIN DE BAIL IMPRIMÉE. 56 lignes du dépôt la portent. C'est le document
#    qui démontre le départ — on ne le déduit plus d'une absence.
RE_LOCATAIRE = re.compile(r'Locataire\s*:\s*(.+?)\s*,\s*Bail'
                          r'(?:\s+du\s+(\d{2}/\d{2}/\d{4}))?'
                          r'(?:\s+au\s+(\d{2}/\d{2}/\d{4}))?', re.I)


def locataires_de(segment):
    """TOUS les occupants annoncés dans un bloc de lot, dans l'ordre d'impression.

    Chacun avec sa date de bail et, quand le document l'imprime, sa date de FIN.
    """
    sortie = []
    for m in RE_LOCATAIRE.finditer(segment or ''):
        sortie.append({
            'locataire': ' '.join(m.group(1).split()),
            'bail_du': m.group(2),
            'bail_au': m.group(3),
        })
    return sortie


# « Immeuble 45, rue Druge - 38200 VIENNE » · « Immeuble LE PERPIGNAN - 38200 VIENNE »
RE_IMMEUBLE = re.compile(r'^\s*Immeuble\s+(.+?)\s*[-–]\s*(\d{5})\s+'
                         r'([A-ZÉÈÀÂÎÔÛa-zéèàâîôû\'\- ]+?)(?:\s{2,}.*)?$', re.M)


def normaliser_reference(brute):
    """La référence telle qu'on l'emploie partout : sans les espaces d'OCR, sans tiret final."""
    return (brute or '').replace(' ', '').rstrip('-')


def code_immeuble(ref_lot):
    """L'immeuble que porte une référence de lot — ou rien, si elle ne le porte pas.

    ⚠️ ON NE DEVINE PAS. Quatre graphies coexistent sur le même corpus :
           `3062-0187`         → immeuble 3062, lot 0187
           `276-04`            → immeuble 276,  lot 04
           `01G01-5213-000367` → immeuble 5213, lot 000367  (le 1er segment est un portefeuille)
           `01S01-0075-000009` → immeuble 0075, lot 000009
       Une référence qui n'en suit aucune rend `None` : un immeuble inventé contaminerait tout
       le rapprochement qui suit.
    """
    if not ref_lot:
        return None, None
    parts = ref_lot.split('-')
    if len(parts) == 3:
        return parts[1], parts[2]
    if len(parts) == 2:
        return parts[0], parts[1]
    return None, ref_lot


def segments_de_lot(texte, page):
    """Découpe le texte d'une page en blocs de lot, un par en-tête qui n'est pas une « Suite ».

    Rend une liste de dictionnaires : `reference`, `libelle`, `locataire`, `bail_du`, `page`,
    `texte` (le segment), `suite` (True si l'en-tête ne fait que continuer le lot précédent).

    ⚠️ LE LOCATAIRE EST CELUI QUI SUIT SON LOT, PAS LE DERNIER RENCONTRÉ SUR LA PAGE. La
       phase 2 prenait le dernier « Locataire: » de la page et l'attribuait à TOUS ses lots :
       un lot sans ligne locataire héritait de l'occupant du lot précédent, et le document
       finissait par dire ce qu'il ne dit pas.
    """
    bornes = [(m.start(), m.group(1)) for m in RE_LOT.finditer(texte)]
    sortie = []
    for k, (debut, brute) in enumerate(bornes):
        fin = bornes[k + 1][0] if k + 1 < len(bornes) else len(texte)
        segment = texte[debut:fin]
        lignes = segment.splitlines()
        entete = lignes[0].strip() if lignes else ''
        suite = bool(RE_SUITE.search(entete))
        # Le libellé du bien précède l'en-tête sur la même ligne : « Appartement 3 Pièces - ».
        avant = texte[:debut].rsplit('\n', 1)[-1] if debut else ''
        occupants = locataires_de(segment)
        sortie.append({
            'reference': normaliser_reference(brute),
            'libelle': ' '.join(avant.split())[:120],
            'locataire': occupants[0]['locataire'] if occupants else None,
            'bail_du': occupants[0]['bail_du'] if occupants else None,
            'bail_au': occupants[0]['bail_au'] if occupants else None,
            'occupants': occupants,
            'page': page,
            'texte': segment,
            'suite': suite,
        })
    return sortie


def immeubles_de(texte, page):
    """Les immeubles annoncés sur une page, avec leur page d'apparition."""
    sortie = []
    for m in RE_IMMEUBLE.finditer(texte):
        sortie.append({
            'nom': ' '.join(m.group(1).split()),
            'code_postal': m.group(2),
            'ville': ' '.join(m.group(3).split()),
            'page': page,
            'code': None,
        })
    return sortie
