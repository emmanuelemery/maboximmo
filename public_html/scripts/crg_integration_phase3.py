# -*- coding: utf-8 -*-
"""
PHASE 3 — LIRE L'OCCUPATION D'UN CRG : quel lot, quel locataire, depuis quel bail.

⚠️ CE MOTEUR NE CONCLUT RIEN SUR UNE SEULE PÉRIODE. Il relève une observation par lot et par
   CRG ; la succession locative se démontre sur la SUITE de ces observations, et ce travail
   appartient à PHP, qui voit toutes les périodes à la fois.

⚠️ L'ENCOURS N'EST PAS LISIBLE SUR CE DÉPÔT, ET ON LE DIT. `pdftotext` détache « Solde » de son
   montant **1 018 fois sur 1 018** — en lecture brute comme en `-layout`, sur le document
   océrisé comme sur un CRG natif. Le rapprocher par proximité serait une devinette : sur les
   soldes détachés, seuls 485 ont un montant en euros à moins de 400 caractères, et rien ne
   prouve que ce soit le bon. On rend donc `solde = None` avec `NON DEMONTRABLE`, plutôt qu'un
   chiffre plausible. Une lecture géométrique (pdfplumber, ~6 min sur ce document) le
   restituerait — c'est une décision, pas un défaut à masquer.

⚠️ ET UN LOT SANS LIGNE « Locataire: » N'EST PAS UN LOT VACANT. C'est un lot dont le document
   ne dit rien : `locataire = None`, et le verdict le signalera.

Usage : python crg_integration_phase3.py <chemin.pdf> <plages.json>
Sortie : un tableau JSON — une entrée par plage, avec ses observations.
"""
import shutil
import subprocess
import json
import re
import sys

sys.path.insert(0, __file__.rsplit('\\', 1)[0] if '\\' in __file__ else '.')
# ⚠️ ON N'IMPORTE PLUS `lire_pages` COMME REPLI. Le garder sous le nom `_lire_layout` faisait
#    de la dégradation silencieuse une porte toujours ouverte : le prochain `except` l'aurait
#    reprise. Ce qui reste importé, c'est le CONTRAT de lecteur, pas une issue de secours.
from crg_integration_phase0 import LecteurIndisponible   # noqa: E402
from crg_integration_phase0 import pdftotext_exe                # noqa: E402


def lire_pages(chemin):
    """Le texte de chaque page, lu en mode TABLEAU.

    ⚠️ LE MODE DE LECTURE DÉCIDE DE CE QU'ON PEUT LIRE, ET J'AI CONCLU TROP VITE. En
       `-layout`, « Solde » se retrouve seul sur sa ligne 774 fois sur 1 142 : j'en avais
       déduit que l'encours n'était « pas démontrable ». C'était faux. Le même document lu
       en `-table` — un mode que ce `pdftotext` propose et que je n'avais pas essayé —
       rattache le montant à son solde **1 022 fois**, et n'en détache plus que 307.

    ⚠️ ET ON NE CHANGE PAS LE MODE DE LA PHASE 0. Son découpage est scellé ; le relire
       autrement en périmerait la validation. La phase 3 lit pour son propre besoin.
    """
    # ⚠️ LE MÊME BINAIRE QUE LA PHASE 0, CHOISI SUR SA CAPACITÉ. `shutil.which` rendait le
    #    premier `pdftotext` du PATH : sous le harnais c'était Xpdf 4.00, qui ne connaît pas
    #    `-table` — le repli `-layout` se déclenchait EN SILENCE et la phase 2 rendait une
    #    autre empreinte que depuis la page. Le sceau le voyait ; personne ne savait pourquoi.
    # ⚠️ PLUS DE REPLI MUET VERS `-layout`. Ce `if … : … return _lire_layout(chemin)` était la
    #    plus coûteuse des dégradations silencieuses du module : quand le binaire ne connaissait
    #    pas `-table`, la phase lisait quand même — autrement — et personne ne le savait.
    #    Mesuré le 03/09/2026 sur un seul dépôt : **huit immeubles** et un occupant présents dans
    #    un mode, absents dans l'autre. Aucun contrôle aval ne pouvait le voir : la perte a lieu
    #    AVANT que les populations n'existent. Un mode de lecture fait partie du résultat ;
    #    son absence est une panne, pas une occasion de lire autrement.
    exe, etiquette, sait_table = pdftotext_exe()
    if not sait_table:
        raise LectureIndisponible(
            'MODE « -table » INDISPONIBLE — le lecteur en service est %s, qui ne le propose '
            'pas. Les phases 2, 3 et 4 lisent en mode tableau : lire autrement changerait le '
            'patrimoine et les occupations sans que rien ne le signale. Installez le lecteur '
            'déclaré par CRG_LECTEUR_CONTRAT, ou changez ce contrat en connaissance de cause.'
            % (etiquette or 'inconnu'))
    r = subprocess.run([exe, '-table', '-enc', 'UTF-8', chemin, '-'],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        raise LectureIndisponible(
            'pdftotext -table a échoué (code %d) : %s'
            % (r.returncode, r.stderr.decode('utf-8', 'replace')[:200]))
    pages = r.stdout.decode('utf-8', 'replace').split('')
    if pages and not pages[-1].strip():
        pages.pop()
    return pages, 'pdftotext (%s -table)' % (etiquette or 'version inconnue')

# ⚠️ LA SEGMENTATION DES LOTS N'APPARTIENT PLUS À CETTE PHASE. Elle vit dans
#    `crg_integration_lots.py`, partagée par les phases 2, 3 et 4 : c'est parce que chacune
#    avait son propre motif que la phase 2 ne voyait que 98 lots là où celle-ci en démontrait
#    124. Une population commune se lit à un seul endroit.
from crg_integration_lots import segments_de_lot   # noqa: E402

# ⚠️ UN EN-TÊTE DE LOT MARQUÉ « Suite » N'OUVRE PAS UNE OBSERVATION. Quand le bloc d'un lot
#    déborde sur la page suivante, le document réimprime son en-tête et le termine par
#    « Suite » — sans réimprimer la ligne « Locataire: », qui figure sur la première page du
#    bloc. Les compter comme des observations créait 34 lots « sans occupant », qu'il fallait
#    ensuite arbitrer un par un. Ce n'était pas l'OCR : le document le DIT, en toutes lettres.
RE_SOLDE_COLLE = re.compile(r'Solde\s+(-?[\d  ]+,\d{2})\s*€')


def jour(fr):
    m = re.match(r'^(\d{2})/(\d{2})/(\d{4})$', (fr or '').strip())
    return '%s-%s-%s' % (m.group(3), m.group(2), m.group(1)) if m else None


# ═══════════════════════════════════════════════════════════════════════════════════════════
# LES APPELS DU BLOC — CE QUI DIT QU'UN LOCATAIRE EST EN PLACE
# ═══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ L'APPEL DE LOYER A DEUX ÉCRITURES, ET ON N'EN LISAIT QU'UNE. Le compteur cherchait
#    « Du 01.01.26 Au 31.01.26 », la forme ICS. SPI n'écrit jamais cela : il écrit « TERME
#    Avril 2026 », « TERME du 17/04/2026 au 30/04/2026 », « Loyer du 01/06/2026 au
#    15/06/2026 ». Résultat mesuré le 07/09/2026 sur `mbi_bis` : `appels` à zéro pour
#    **CHAPONOST 402/402 et VIENNE 480/480**, soit 882 occupations sur 2 839 — 31 % du corpus
#    dont le fait qui tranche n'était jamais lu. Emmanuel : « il est indiqué TERME = Loyer !
#    il faut donc vérifier tous les termes qui peuvent être utilisés sur d'autres types de
#    bail que l'habitation ».
#
# ⚠️ ET UN APPEL N'EST PAS FORCÉMENT UN LOYER. MEYNADE Carole (CHAPONOST, lot 000255-01) :
#    « Gratuité de loyer jusqu au 31.05.2026 », donc aucun terme — mais « Dépôt de garantie
#    MEYNADE Carole 25/06/2026 ». Emmanuel : « il y a un loyer gratuit, mais nous lui avons au
#    moins demandé le dépôt de garantie, donc l'appel n'est pas vide ». Un bloc qui appelle un
#    dépôt de garantie appelle quelque chose : le locataire entre, il n'est pas absent.
#
# ⚠️ LA LIGNE « Locataire: … Bail du … au … » N'EST PAS UN APPEL. Elle porte les dates du BAIL,
#    et les prendre pour un appel ferait dire au bloc qu'il appelle jusqu'à la fin du bail —
#    soit exactement l'inverse de ce qu'on cherche à démontrer. Elle est retirée du texte
#    avant toute recherche.

# ⚠️ C'EST LA NATURE QUI FAIT L'APPEL, PAS LA DATE. Un bloc est plein de dates qui n'appellent
#    rien : « Bail du 05/08/2024 », « Budget prévisionnel 2026 - 3ème trimestre », « Gratuité
#    de loyer jusqu au 31.05.2026 », « Remise sur Loyer ». On ne cherche donc une période QUE
#    sur une ligne qui COMMENCE par une nature d'appel — et cette liste est la seule chose à
#    rouvrir quand un nouvel éditeur entre dans le corpus.
RE_NATURE_APPEL = re.compile(
    r'^\s*(TERME|Loyers?|Provisions?|Charges?\s+locatives|Taxes?\s+|TEOM'
    r'|Ordures\s+m[ée]nag[èe]res|Redevance|Indemnit[ée]\s+d.occupation)', re.I)

# « du 01/06/2026 au 15/06/2026 » — la forme à dates explicites.
# ⚠️ `\s` TRAVERSE LES SAUTS DE LIGNE, ET C'EST VOULU : `pdftotext` coupe régulièrement entre
#    « au » et sa date (« Provisions pour charges du 01/06/2026 au \n 15/06/2026 »).
RE_APPEL_PERIODE = re.compile(
    r'\bdu\s+(\d{2})/(\d{2})/(\d{4})\s+au\s+(\d{2})/(\d{2})/(\d{4})', re.I)

MOIS_FR = {'janvier': 1, 'fevrier': 2, 'février': 2, 'mars': 3, 'avril': 4, 'mai': 5,
           'juin': 6, 'juillet': 7, 'aout': 8, 'août': 8, 'septembre': 9, 'octobre': 10,
           'novembre': 11, 'decembre': 12, 'décembre': 12}
FIN_DE_MOIS = (31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)

# ⚠️ « Loyer Juillet 2026 » EST UN APPEL AUTANT QUE « TERME Mai 2026 ». Le mois nommé n'est pas
#    réservé au terme commercial : un CRG mensuel d'habitation écrit « Loyer Juillet 2026 »,
#    « Provisions Ordures ménagères Juillet 2026 », « Provisions pour charges Juillet 2026 ».
#    N'avoir cherché le mois que derrière « TERME » laissait **418 occupations sur 480** d'un
#    dépôt sans un seul appel lu — le même aveuglement que la forme ICS, un cran plus loin.
RE_MOIS_NOMME = re.compile(r'\b(' + '|'.join(MOIS_FR) + r')\s+(\d{4})\b', re.I)

# ⚠️ UN DÉPÔT DE GARANTIE APPELÉ EST UN APPEL, UN DÉPÔT REMBOURSÉ EN EST L'INVERSE.
#    « Rembt D G reversé » accompagne un départ ; le confondre avec l'appel d'entrée
#    retiendrait en place un locataire qui vient de partir.
RE_DEPOT_GARANTIE = re.compile(r'D[ée]p[ôo]t\s+de\s+garantie', re.I)
# ⚠️ « Reversement dépôt de garantie » CONTIENT « dépôt de garantie » — et c'est son contraire.
#    Une locataire sortante a été comptée « en place » sur cette seule ligne : le mot qui compte
#    est celui qui PRÉCÈDE. Les éditeurs écrivent « Rembt D G reversé », « Reversement dépôt de
#    garantie », « Restitution du dépôt de garantie », « Solde dépôt de garantie ».
RE_DG_REVERSE = re.compile(
    r'(Rembt|Rembours\w*|Restitution|Reversement|Solde)\s+(du\s+|de\s+)?'
    r'(D\.?\s?G\.?|d[ée]p[ôo]t\s+de\s+garantie)|D\.?\s?G\.?\s+revers', re.I)
# La ligne qui porte les dates DU BAIL, jamais un appel.
RE_LIGNE_LOCATAIRE = re.compile(r'^.*Locataire\s*:.*$', re.M | re.I)


def _fin_de_mois(an, mois):
    if mois == 2 and (an % 4 == 0 and (an % 100 != 0 or an % 400 == 0)):
        return 29
    return FIN_DE_MOIS[mois - 1]


def appels_du_bloc(texte):
    """Les périodes appelées par un bloc de lot, en dates ISO — jamais celles du bail.

    Rend une liste de couples ``[du, au]``. PHP décidera lesquels RECOUPENT la période du
    compte rendu : une régularisation « du 01/01/2025 au 31/12/2025 » imprimée dans un
    rapport du 1er trimestre 2026 n'appelle rien pour ce trimestre-là.
    """
    t = RE_LIGNE_LOCATAIRE.sub(' ', texte or '')
    lignes = t.split('\n')
    periodes = []
    for i, ligne in enumerate(lignes):
        if not RE_NATURE_APPEL.match(ligne):
            continue
        # ⚠️ LA FENÊTRE DÉBORDE D'UNE LIGNE, PARCE QUE LA DATE DÉBORDE. `pdftotext` renvoie
        #    « … du 01/06/2026 au » et laisse « 15/06/2026 » sur la ligne suivante. La ligne
        #    suivante ne sera pas relue pour elle-même : elle ne commence par aucune nature.
        fenetre = ligne + ' ' + (lignes[i + 1] if i + 1 < len(lignes) else '')
        m = RE_APPEL_PERIODE.search(fenetre)
        if m:
            periodes.append(['%s-%s-%s' % (m.group(3), m.group(2), m.group(1)),
                             '%s-%s-%s' % (m.group(6), m.group(5), m.group(4))])
            continue
        # ⚠️ LE MOIS SE CHERCHE SUR LA LIGNE SEULE, JAMAIS SUR LA FENÊTRE : sinon un
        #    « Loyer Juillet 2026 » sans montant s'approprierait l'août de la ligne d'après.
        m = RE_MOIS_NOMME.search(ligne)
        if m:
            mois = MOIS_FR[m.group(1).lower()]
            an = int(m.group(2))
            periodes.append(['%04d-%02d-01' % (an, mois),
                             '%04d-%02d-%02d' % (an, mois, _fin_de_mois(an, mois))])
    # ⚠️ LE DÉPÔT DE GARANTIE N'A PAS DE PÉRIODE : il vaut à la date de l'appel, qu'on ne
    #    connaît pas toujours. On le rend comme une période NULLE — un appel dont on sait
    #    qu'il existe sans savoir jusqu'où il porte. PHP le comptera comme présence sur la
    #    période lue, sans jamais en faire une date de fin.
    depots = (len(RE_DEPOT_GARANTIE.findall(t))
              - len(RE_DG_REVERSE.findall(t)))
    return periodes, max(depots, 0)


# ═══════════════════════════════════════════════════════════════════════════════════════════
# LES HONORAIRES DE GESTION — LA PREUVE QUE LE MANDAT VIT ENCORE
# ═══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ UN BIEN VIDE ET UN MANDAT PERDU SE RESSEMBLENT — SAUF SUR CE POINT. Les deux cessent
#    d'appeler un loyer. Mais tant que la régie facture ses HONORAIRES DE GESTION sur ce
#    compte, le mandat n'est pas perdu : on gère un bien vide. Emmanuel, 08/09/2026 : « on voit
#    qu'il n'y a plus de loyer appelé donc le bien est vide, et que nous continuons à lui
#    prendre des honoraires de gestion : donc gestion NON perdue, bien vide ».
#
# ⚠️ ET LA LIGNE NOMME SOUVENT LE LOT. « 31/07/2026 Honoraires Gestion TTC (taux:5,50 %
#    HT,base:283,00 €) … 01G01-5028-000030 » : la colonne « Logement » porte la référence. Quand
#    elle est là, la preuve vaut pour CE lot ; sinon elle vaut pour le compte rendu entier.
RE_HONORAIRES = re.compile(r'Honoraires?\s+(de\s+)?Gestion', re.I)
RE_REF_LOT_LIBRE = re.compile(r'\b(\d{2,5}[A-Z]?\d{0,3}-\d{2,5}-\d{3,6}|\d{4,6}-\d{2,4})\b')


def honoraires_de_gestion(textes):
    """Les honoraires de gestion lus dans une plage : (combien, sur quels lots).

    ⚠️ ON NE CHERCHE PAS DANS LES BLOCS DE LOT. Ces lignes vivent dans la section
       « - Honoraires de Gestion - » du compte rendu, hors des tableaux d'occupation : les
       chercher au niveau du lot ne les aurait jamais trouvées.
    """
    n = 0
    lots = set()
    for texte in textes:
        lignes = (texte or '').splitlines()
        for i, ligne in enumerate(lignes):
            if not RE_HONORAIRES.search(ligne):
                continue
            n += 1
            # La référence peut déborder sur les deux lignes suivantes : le PDF empile
            # « 01G01- / 5028- / 000030 » dans une colonne étroite.
            fenetre = ' '.join(lignes[i:i + 3]).replace('- ', '-').replace(' -', '-')
            for m in RE_REF_LOT_LIBRE.finditer(fenetre):
                lots.add(m.group(1))
    return n, sorted(lots)


def observer(textes, page_base):
    """Les observations d'occupation d'une plage de pages.

    ⚠️ UNE OBSERVATION PAR OCCUPANT, PAS PAR LOT. Un bloc peut en porter plusieurs :
       « Locataire: DE SOUSA Jordan, Bail du 01/06/2025 au 04/06/2026 » puis
       « Locataire: GONIN Marine, Bail du 22/06/2026 ». Ne garder que le premier faisait
       disparaître huit successions que le document DÉMONTRE, et la phase 4, qui gardait le
       dernier, attribuait l'argent à l'autre occupant : deux phases, deux locataires, sur la
       même page et le même lot.

    ⚠️ LE LOCATAIRE EST CELUI QUI SUIT SON LOT, PAS LE DERNIER RENCONTRÉ SUR LA PAGE. La
       segmentation vient de `crg_integration_lots`, partagée avec les phases 2 et 4.
    """
    obs = []
    for i, texte in enumerate(textes):
        page = page_base + i
        for seg in segments_de_lot(texte, page):
            ref = seg['reference']
            if seg['suite']:
                # Continuation du même lot : on complète l'observation ouverte plus haut, on
                # n'en crée pas une seconde qui paraîtrait inoccupée.
                for precedente in reversed(obs):
                    if precedente['lot'] == ref:
                        ms = RE_SOLDE_COLLE.search(seg['texte'])
                        if ms and precedente['solde'] is None:
                            precedente['solde'] = (ms.group(1).replace(' ', '')
                                                   .replace(' ', '').replace(',', '.'))
                            precedente['solde_source'] = 'LUE'
                        # ⚠️ ET LES APPELS AUSSI SONT SUR LA CONTINUATION — ILS Y SONT MÊME
                        #    PRESQUE TOUJOURS. Quand un lot est coupé par une page, l'en-tête
                        #    se réimprime avec « ...Suite » et TOUT le tableau des appels part
                        #    sur la seconde page : « Loyer Juillet 2026 », « Provisions pour
                        #    charges Juillet 2026 », « Provisions TEOM Juillet 2026 ». Cette
                        #    branche ne reprenait que le nom et le solde : les appels étaient
                        #    JETÉS, et le lot ressortait muet alors que le document appelle son
                        #    loyer en toutes lettres — d'où une question d'arbitrage sur une
                        #    occupation parfaitement lisible. Emmanuel, 08/09/2026 : « nous
                        #    avons le nom, la date de début du bail, l'appel de loyer et des
                        #    charges pour juillet — tu ne sais pas lire un CRG de VIENNE ? ».
                        #    C'est la même faute que l'occupant perdu sur un lot coupé, sur une
                        #    autre colonne du même tableau.
                        pers, deps = appels_du_bloc(seg['texte'])
                        if pers or deps:
                            precedente['appels_periodes'] = (
                                list(precedente.get('appels_periodes') or []) + pers)
                            precedente['appels_sans_periode'] = (
                                int(precedente.get('appels_sans_periode') or 0) + deps)
                        # ⚠️ ET L'OCCUPANT AUSSI SE LIT SUR LA CONTINUATION. Quand le bloc
                        #    d'un lot commence au BAS d'une page, sa ligne « Locataire: » ne
                        #    tient pas dessus : elle est imprimée sur la page suivante, sous
                        #    l'en-tête « Suite ». Cette branche ne complétait que le solde :
                        #    l'observation restait sans occupant alors que le document le
                        #    NOMME, et la phase 4 le lisait — d'où un occupant connu en aval
                        #    et inconnu en amont, ce que la frontière inter-phases interdit.
                        #    Constaté sur CHAPONOST (BALDYGA Axel p325→326, LECOEUVRE Arnaud
                        #    p326→327) ET sur VIENNE (MONCHANIN Francoise, lot 09 du compte
                        #    1105404307, dans deux CRG) : ce n'est pas un défaut de format,
                        #    c'est une page qui se termine.
                        # ⚠️ ON NE COMPLÈTE QUE CE QUI EST VIDE. Une observation déjà nommée
                        #    n'est jamais réécrite par sa continuation : le premier nom lu
                        #    reste celui du lot, et une succession demeure une observation à
                        #    part entière — jamais un écrasement silencieux.
                        if not precedente.get('locataire') and seg.get('locataire'):
                            precedente['locataire'] = seg['locataire']
                            if precedente.get('bail_du') is None:
                                precedente['bail_du'] = jour(seg.get('bail_du'))
                            if precedente.get('bail_au') is None:
                                precedente['bail_au'] = jour(seg.get('bail_au'))
                        break
                continue
            ms = RE_SOLDE_COLLE.search(seg['texte'])
            solde = (ms.group(1).replace(' ', '').replace(' ', '').replace(',', '.')
                     if ms else None)
            # ⚠️ CE QUE LE BLOC APPELLE, ET JUSQU'OÙ. C'est le seul fait qui dise si un
            #    locataire est en place : « pas de loyer ou charge appelé c'est qu'il est
            #    parti » (Emmanuel, 07/09/2026).
            #
            # ⚠️ MAIS LES APPELS APPARTIENNENT AU BLOC, PAS À UN OCCUPANT — ET QUAND LE BLOC EN
            #    PORTE PLUSIEURS, ILS NE SONT ATTRIBUABLES À PERSONNE. J'ai d'abord donné le
            #    tout au premier imprimé, par analogie avec le solde. La preuve que c'est faux
            #    tient en une chronologie : sur un lot, le premier bloc nomme une locataire
            #    dont le BAIL S'EST ACHEVÉ EN JUIN 2025 et reçoit 3 puis 6 appels en avril et
            #    mai 2026 ; le second nomme la locataire entrée en NOVEMBRE 2025 et n'en reçoit
            #    aucun — puis, dès que la première cesse d'être imprimée, la seconde en reçoit
            #    3. Les appels étaient les siens depuis le début. Attribuer par rang, c'est
            #    faire dire au document l'inverse de ce qu'il dit.
            #
            # ⚠️ ON PRÉFÈRE NE RIEN SAVOIR À SAVOIR FAUX. Sans attribution, aucun contraste ne
            #    se forme et la chronologie décide comme avant : on perd une preuve, on n'en
            #    fabrique pas une fausse.
            periodes, depots = appels_du_bloc(seg['texte'])
            occupants = seg['occupants'] or [{'locataire': None, 'bail_du': None,
                                              'bail_au': None}]
            partageable = len(occupants) <= 1
            for rang, o in enumerate(occupants):
                obs.append({
                    'appels_periodes': periodes if partageable else [],
                    'appels_sans_periode': depots if partageable else 0,
                    'lot': ref,
                    'locataire': o['locataire'],
                    'bail_du': jour(o['bail_du']),
                    # ⚠️ « AU … » EST UNE FIN DE BAIL IMPRIMÉE : le départ est dit par le
                    #    document, on ne le déduit plus d'une absence.
                    'bail_au': jour(o['bail_au']),
                    'rang': rang,
                    # Le solde du bloc appartient au bloc, pas à un occupant en particulier :
                    # on ne le donne qu'au premier, sans quoi on le compterait deux fois.
                    'solde': solde if rang == 0 else None,
                    'solde_source': 'LUE' if (ms and rang == 0) else 'NON DEMONTRABLE',
                    'page': page,
                })
    return obs


def main():
    if len(sys.argv) < 3:
        sys.stderr.write('usage : crg_integration_phase3.py <pdf> <plages.json>\n')
        return 2
    textes, _ = lire_pages(sys.argv[1])
    with open(sys.argv[2], encoding='utf-8') as fh:
        plages = json.load(fh)
    sortie = []
    for p in plages:
        a, b = int(p['debut']), int(p['fin'])
        nh, lh = honoraires_de_gestion(textes[a - 1:b])
        sortie.append({'id': p['id'], 'observations': observer(textes[a - 1:b], a),
                       'honoraires': nh, 'honoraires_lots': lh})
    sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
