# -*- coding: utf-8 -*-
"""
LES VISUELS DE LA LECTURE GUIDÉE — rend les pages de CRG en image et localise chaque écriture.

⚠️ ON NE FAIT PAS CONFIANCE AU `y` DU PARSEUR POUR POSITIONNER UN SURLIGNAGE. Chaque lecteur a
   sa convention d'origine (haut ou bas de page) et l'échelle dépend du rendu. On relocalise
   donc chaque ligne DANS LE PDF avec pdfplumber, on prend la boîte des mots réellement
   imprimés, et on la normalise en 0–1 : le navigateur peut alors afficher l'image à n'importe
   quelle taille, le surlignage reste sur la ligne.

⚠️ ET ON N'IMPRIME QUE CE QUI EST DEMANDÉ. Seules les pages qui portent des opérations de
   mandat sont rendues — pas les 550 pages des sept CRG.

Produit : `public_html/img/crg_lecture/*.png` + `public_html/data/crg_lecture_01040000.json`.
Lecture seule côté corpus. Aucune écriture MBI, aucune PROD.
"""
import collections
import io
import zipfile
import unicodedata
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import documents, euros   # noqa: E402
import p4b_source_pdf as SRC   # noqa: E402
import pdfplumber   # noqa: E402
import pypdfium2 as pdfium   # noqa: E402

COMPTE = '01040000'

# ⚠️ LES ARBITRAGES OUVERTS VIENNENT D'AUTRES COMPTES — ILS VONT SUR LA MÊME PAGE.
#    Une écriture isolée sur un compte EMERY n'a pas besoin de sa propre lecture guidée : elle
#    a besoin d'être VUE, à sa page, avec sa question. On les rattache donc à l'écran existant
#    plutôt que d'ouvrir un second écran par ligne à trancher.
ARBITRAGES = [
    {'crg': '5 RUE DES PRADES', 'compte': '06790000', 'agence': 'EMERY IMMO',
     'libelle': 'Réserve',
     'aide': 'Une ligne « Réserve » de 1 000,00 € sous une rubrique qui est une adresse. '
             'Réserve de trésorerie gardée pour le propriétaire, provision pour travaux, '
             'ou autre chose ?'},
]

# ⚠️ CERTAINS ARBITRAGES PORTENT SUR DES DOCUMENTS ENTIERS, PAS SUR UNE LIGNE. Les quatre cas
#    VIENNE opposent DEUX CRG du même compte au même arrêté, dont les écritures se recouvrent
#    partiellement : la question ne se lit pas sur une écriture mais en comparant les deux
#    pièces. On les met donc côte à côte dans la même question — les flèches passent de l'une à
#    l'autre, et le visuel fait le travail qu'aucun tableau ne ferait.
ARBITRAGES_DOCS = [
    {'agence': 'VIENNE', 'compte': '1105404745',
     'crgs': ['CRG AVRIL 2026_391619.pdf', 'CRG AVRIL 2026_391618.pdf']},
    {'agence': 'VIENNE', 'compte': '1105406704',
     'crgs': ['CRG AVRIL 2026_391591.pdf', 'CRG AVRIL 2026_391592.pdf',
              'CRG AVRIL 2026_391593.pdf']},
    {'agence': 'VIENNE', 'compte': '1105407792',
     'crgs': ['CRG AVRIL 2026_391602.pdf', 'CRG AVRIL 2026_391603.pdf']},
    # ⚠️ P8B — LE SEUL POINT QUE LE DOCUMENT NE TRANCHE PAS. Sur 33 immeubles (4 %), le solde
    #    imprimé est INFÉRIEUR à `règlements − charges` : de l'argent encaissé n'apparaît pas
    #    dans le solde de l'immeuble. Une explication métier est probable — un acompte déjà
    #    reversé en cours de trimestre — mais le CRG ne l'écrit pas, et je ne l'inventerai pas.
    {'agence': 'LYON hors SIR', 'compte': '01540000',
     'crgs': ['LOCAVENTE 1.pdf'],
     'titre': 'ARBITRAGE P8B — un solde d’immeuble inférieur à ses encaissements',
     'aide': 'Sur l’immeuble AGENCE RIOM : règlements locataires 7 026,09 €, charges '
             '252,93 €, mais le solde d’immeuble imprimé n’est que de 1 666,89 €. Il manque '
             '5 106,27 € entre ce qui est encaissé et ce que le solde retient. Acompte déjà '
             'reversé au propriétaire en cours de trimestre, autre retenue, ou lecture '
             'incomplète de ma part ? 33 immeubles sur 834 sont dans ce cas.'},
]
AIDE_DOCS = ('Ces CRG portent le MÊME compte et la MÊME date d’arrêté, et leurs écritures se '
             'recouvrent partiellement (10 à 33 %). Trop peu pour des rééditions, trop pour des '
             'pièces indépendantes. En les regardant : est-ce la même situation rééditée, ou '
             'des pièces complémentaires portant des lots différents ?')

# ⚠️ LES ARBITRAGES P8A VIENNENT ICI, PAS DANS UNE PAGE À PART. Ces trois questions portent sur
#    des ÉCRITURES précises : elles se répondent en voyant la ligne dans son CRG, entourée, avec
#    ses voisines — exactement ce que cet écran sait faire. Une file de décisions sans visuel
#    obligerait à rouvrir le PDF à côté ; c'est le travail que la pagination devait supprimer.
ARBITRAGES_P8A = [
    {'famille': 'ACOMPTE PROPRIÉTAIRE — par section',
     'titre': 'ARBITRAGE — un CRÉDIT sous « Soldes mandats »',
     'aide': 'La rubrique annonce un acompte AU propriétaire, mais le montant est au CRÉDIT : '
             'l’argent entre au lieu de sortir. Remboursement d’un acompte versé en trop, '
             'apport du propriétaire, ou restitution d’une saisie ? Tant que le sens n’est pas '
             'démontré, ces lignes n’entrent pas dans le total des flux.'},
    {'famille': 'MOUVEMENT DÉPÔT DE GARANTIE',
     'titre': 'ARBITRAGE — un chèque au crédit sous « Reversement D.G. au prop. »',
     'aide': 'Le libellé ne nomme qu’un numéro de chèque ; la rubrique dit « au prop. » alors '
             'que le montant est au crédit. Dépôt encaissé du locataire, reversement au '
             'propriétaire annulé, ou autre mouvement de dépôt ?'},
    {'famille': 'REJET DE VIREMENT',
     'titre': 'ARBITRAGE — un virement rejeté (compte clôturé)',
     'aide': 'Le rejet est imprimé sans référence au virement d’origine. Annule-t-il un flux '
             'déjà compté dans un CRG précédent — auquel cas le compter aussi ferait un doublon '
             '— ou n’a-t-il jamais eu de contrepartie ?'},
]
RACINE = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                      '..', '..'))          # public_html
IMG = os.path.join(RACINE, 'img', 'crg_lecture')
DATA = os.path.join(RACINE, 'data')
ECHELLE = 2.0                                                # ~144 dpi

# Les formes, dans l'ordre où elles sont présentées à Emmanuel.
FORMES = [
    ('Loyers versés au propriétaire — libellé « DIRECT IMPOTS » / « (ATD) »',
     r"loyers?\s+vers[ée]s?\s+au\s+propri", None,
     'Le CRG titre « Loyers versés au propriétaire », mais la ligne dit que le loyer est '
     'allé aux impôts. Où est passé l’argent, et le propriétaire a-t-il reçu quelque chose ?'),
    ('Honoraires de prestation — « REGIE EMERY LOCA-IMMO … »',
     r"honoraires?\s+de\s+prestation|hono\s+compta", None,
     'La régie se facture des prestations au compte du mandant. Sont-ce des honoraires de '
     'gestion ordinaires, ou des missions facturées à part ?'),
    ('Un fournisseur nommé + un n° de facture',
     r"n[°o]\s*\d", r"regie\s+emery|compensation|acompte",
     'Ces lignes portent un fournisseur et une référence de facture. Est-ce la DÉPENSE, son '
     'PAIEMENT, ou les deux à la fois ?'),
    ('Taxe foncière / Autres Impôts — « SIP <ville> »',
     r"taxe\s+fonci|autres\s+imp[oô]ts", None,
     'Le même montant revient plusieurs fois par trimestre. Sont-ce des échéances, des '
     'rappels du même avis, ou plusieurs biens ?'),
    ('Rembt Prêt immobilier — un virement vers une banque',
     r"rembt\s+pr[êe]t|\bpr[êe]t\b", None,
     'Le CRG titre « Rembt Prêt immobilier ». Le montant contient-il des intérêts, et le CRG '
     'sépare-t-il jamais capital et intérêts ?'),
    ('ACOMPTE PRO / ACOMPTE <ENTITÉ> / VRT <ENTITÉ>/<BANQUE>',
     r"acompte|\bvrt\b|\bvirt\b|\bvir\b", r"compensation|saisie",
     'C’est la forme la plus lourde du compte. Que veut dire « ACOMPTE PRO », et à qui '
     'appartient la banque qui suit le « / » — au propriétaire, à la régie, au groupe ?'),
    ('COMPENSATION <X> PAR <Y> · Compensation Solde débiteur Mdt <n>',
     r"compensation|compensat", None,
     'Un mandat est compensé par un autre. De l’argent bouge-t-il vraiment, ou est-ce une '
     'écriture entre comptes ?'),
    ('VIRT BLOCAGE SAISIE · VIRT MLV TOTALE SAISIE',
     r"saisie", None,
     'Deux montants très lourds, l’un au débit l’autre au crédit. Que s’est-il passé ?'),
    ('Indemnité sinistre assurance — « REVERSEMENT INDEMNITE … »',
     r"indemnit[ée]|sinistre", None,
     'Une indemnité d’assurance apparaît au DÉBIT du compte. Qui la reçoit ?'),
    ('TRNASFERT SUR SMH — vers une autre entité',
     r"transfert|trnasfert|holding", None,
     'Un transfert vers une autre société du groupe. Est-ce un mouvement du propriétaire, '
     'ou une opération interne à la régie ?'),
    ('Le préfixe « FOURNISSEURS DIVERS » devant un virement',
     r"fournisseurs?\s+divers", None,
     'Ce préfixe revient très souvent. Désigne-t-il un compte comptable, un journal, '
     'ou le bénéficiaire réel ?'),
    ('Une rubrique qui est une ADRESSE, avec un libellé réduit à « / »',
     r"^[\s/.-]*$", None,
     'Ces lignes n’ont pas de montant. Sont-ce des titres d’immeuble, ou des lignes que le '
     'lecteur a mal découpées ?'),
    ('« + SALAIRE SRB » / « Salaire » comme rubrique',
     r"salaire", None,
     'Un salaire au compte du mandant. Qui est l’employeur, et pourquoi le CRG le porte-t-il ?'),
]


def normalise(s):
    return re.sub(r'\s+', ' ', re.sub(r'[^a-zA-Z0-9À-ÿ ]', ' ', str(s or ''))).strip().upper()


def _bande(mots, ancres, page_h, page_w):
    """La bande horizontale complète qui contient ces mots — c'est la LIGNE imprimée."""
    haut = min(m['top'] for m in ancres)
    bas = max(m['bottom'] for m in ancres)
    meme = [m for m in mots if m['top'] < bas and m['bottom'] > haut]
    x0, x1 = min(m['x0'] for m in meme), max(m['x1'] for m in meme)
    return {'x': round(x0 / page_w, 5), 'y': round(haut / page_h, 5),
            'w': round((x1 - x0) / page_w, 5), 'h': round((bas - haut) / page_h, 5)}


def _cle(s):
    """⚠️ LE DÉCOUPAGE EN MOTS DU PDF N'EST PAS LE NÔTRE. Le document imprime « VRT SIR/BP
       AURA » en trois jetons dont l'un contient une barre ; découper le libellé sur les
       séparateurs produisait quatre morceaux qui ne correspondaient à aucun jeton, et le
       repère retombait sur la rubrique au lieu de la ligne. On compare donc des SUITES DE
       CARACTÈRES, séparateurs retirés de part et d'autre."""
    return re.sub(r'[^A-Z0-9]', '', str(s or '').upper())


def boite(mots, libelle, page_h, page_w):
    """La boîte des mots imprimés qui composent ce libellé, normalisée en 0–1."""
    cible = _cle(libelle)[:14]
    if len(cible) < 5:
        return None
    for i in range(len(mots)):
        acc = ''
        for j in range(i, min(i + 8, len(mots))):
            acc += _cle(mots[j]['text'])
            if acc.startswith(cible) or cible.startswith(acc) and len(acc) >= len(cible):
                if acc.startswith(cible):
                    return _bande(mots, mots[i:j + 1], page_h, page_w)
            if len(acc) >= len(cible):
                break
    return None


def boite_par_montant(mots, montant, page_h, page_w):
    """⚠️ LE REPLI EST LE MONTANT, PAS UN À-PEU-PRÈS SUR LE TEXTE. Un libellé peut être coupé
       par un retour à la ligne ou porter un tiret que le PDF sépare ; le montant, lui, est
       imprimé d'un bloc et se retrouve tel quel. On n'accepte le repli que si le montant
       n'apparaît QU'UNE FOIS sur la page — sinon on ne saurait pas laquelle surligner."""
    if not montant:
        return None
    formes = {('%.2f' % montant), ('%.2f' % montant).replace('.', ','),
              format(montant, ',.2f').replace(',', ' '),
              format(montant, ',.2f').replace(',', ' ').replace('.', ',')}
    trouves = [m for m in mots if m['text'].strip() in formes]
    if len(trouves) != 1:
        return None
    return _bande(mots, trouves, page_h, page_w)


def main():
    os.makedirs(IMG, exist_ok=True)
    os.makedirs(DATA, exist_ok=True)

    docs = [d for d in documents('GROUPE SIR')
            if str((d.get('meta') or {}).get('compte') or '') == COMPTE]
    docs.sort(key=lambda d: str((d.get('meta') or {}).get('periode_cle')))

    crgs, lignes = [], []
    for d in docs:
        m = d.get('meta') or {}
        ops = list((d.get('mandat') or {}).get('operations') or [])
        ops.sort(key=lambda o: (o.get('page') or 0, o.get('y') or 0))
        pages_voulues = sorted({o.get('page') for o in ops if o.get('page')})
        chemin = SRC.chemin(d, 'GROUPE SIR', None, None)
        if not chemin or not os.path.exists(chemin):
            print('  ⚠️ PDF introuvable : %s' % d.get('_nom_original'))
            continue
        sha = d.get('_sha')[:12]
        rendu = {}
        pdf = pdfium.PdfDocument(chemin)
        with pdfplumber.open(chemin) as pp:
            for p in pages_voulues:
                if p < 1 or p > len(pdf):
                    continue
                nom = '%s_p%d.png' % (sha, p)
                dest = os.path.join(IMG, nom)
                if not os.path.exists(dest):
                    img = pdf[p - 1].render(scale=ECHELLE).to_pil()
                    img.save(dest, 'PNG', optimize=True)
                page = pp.pages[p - 1]
                rendu[p] = (nom, page.extract_words(), page.height, page.width,
                            round(page.width / page.height, 4))
        pdf.close()

        crgs.append({'sha': sha, 'periode': m.get('periode_cle'),
                     'date_arrete': m.get('date_arrete'),
                     'fichier': d.get('_nom_original'), 'pages': d.get('_pages'),
                     'report': (d.get('mandat') or {}).get('report'),
                     'solde_final': (d.get('mandat') or {}).get('solde_final'),
                     'totaux': (d.get('mandat') or {}).get('totaux'),
                     'images': {str(p): {'src': 'img/crg_lecture/' + v[0], 'ratio': v[4]}
                                for p, v in rendu.items()}})

        for o in ops:
            p = o.get('page')
            if p not in rendu:
                continue
            nom, mots, h, w, ratio = rendu[p]
            # ⚠️ L'ORDRE DES REPLIS COMPTE. Le libellé désigne LA LIGNE ; le montant aussi.
            #    La rubrique, elle, surplombe plusieurs lignes : s'y rabattre surlignait
            #    « Soldes mandats » au lieu du virement qu'Emmanuel doit voir. Elle ne sert
            #    donc qu'en tout dernier recours, et seulement si rien d'autre n'existe.
            lib = str(o.get('libelle') or '')
            bb = boite(mots, lib, h, w)
            if bb is None:
                bb = boite_par_montant(
                    mots, round(float(o.get('debit') or 0) or float(o.get('credit') or 0), 2),
                    h, w)
            if bb is None:
                bb = boite(mots, str(o.get('entete') or ''), h, w)
            lignes.append({
                'sha': sha, 'periode': m.get('periode_cle'), 'page': p,
                'section': str(o.get('entete') or ''), 'libelle': lib,
                'debit': round(float(o.get('debit') or 0), 2),
                'credit': round(float(o.get('credit') or 0), 2),
                'boite': bb, 'fichier': d.get('_nom_original'),
            })

    # ── répartition des lignes dans les formes, sans recouvrement ──────────────────────────
    restants = list(range(len(lignes)))
    questions = []
    for titre, p, x, aide in FORMES:
        mo, ex = re.compile(p, re.I), (re.compile(x, re.I) if x else None)
        pris = [i for i in restants
                if (mo.search(lignes[i]['section']) or mo.search(lignes[i]['libelle']))
                and not (ex and (ex.search(lignes[i]['libelle'])
                                 or ex.search(lignes[i]['section'])))]
        if not pris:
            continue
        restants = [i for i in restants if i not in pris]
        questions.append({'titre': titre, 'aide': aide, 'lignes': pris,
                          'debit': round(sum(lignes[i]['debit'] for i in pris), 2),
                          'credit': round(sum(lignes[i]['credit'] for i in pris), 2)})
    if restants:
        questions.append({
            'titre': 'Les écritures qui n’entrent dans aucune forme ci-dessus',
            'aide': 'Ces lignes n’ont pas de forme commune. Que sont-elles ?',
            'lignes': restants,
            'debit': round(sum(lignes[i]['debit'] for i in restants), 2),
            'credit': round(sum(lignes[i]['credit'] for i in restants), 2)})

    questions.append({'titre': 'Le REPORT d’ouverture et le SOLDE FINAL',
                      'aide': 'Le solde final de chaque CRG devient le report du suivant, et le '
                              'compte reste débiteur sur toute la période. Que représente ce '
                              'solde, vis-à-vis de qui, et que devient-il en pratique ?',
                      'lignes': [], 'debit': 0, 'credit': 0, 'cadre': True})

    # ── LES ARBITRAGES OUVERTS VENUS D'AUTRES COMPTES ─────────────────────────────────────
    # ⚠️ TOUJOURS À LA FIN, JAMAIS INTERCALÉS. Les réponses d'Emmanuel sont enregistrées par
    #    RANG de question : insérer une question au milieu décalerait toutes les suivantes et
    #    afficherait sa réponse sous la mauvaise. On n'ajoute donc qu'après les existantes.
    for a in ARBITRAGES:
        cible = None
        for d in documents(a['agence']):
            if str((d.get('meta') or {}).get('compte') or '') != a['compte']:
                continue
            for o in (d.get('mandat') or {}).get('operations') or []:
                if a['libelle'].upper() in str(o.get('libelle') or '').upper():
                    cible = (d, o)
                    break
            if cible:
                break
        if not cible:
            print('  ⚠️ arbitrage introuvable : %s / %s' % (a['compte'], a['libelle']))
            continue
        d, o = cible
        m = d.get('meta') or {}
        chemin = SRC.chemin(d, a['agence'], None, None)
        if not chemin or not os.path.exists(chemin):
            print('  ⚠️ PDF introuvable pour l’arbitrage %s' % a['libelle'])
            continue
        sha = d.get('_sha')[:12]
        p = o.get('page') or 1
        nom = '%s_p%d.png' % (sha, p)
        dest = os.path.join(IMG, nom)
        pdf = pdfium.PdfDocument(chemin)
        with pdfplumber.open(chemin) as pp:
            if not os.path.exists(dest):
                pdf[p - 1].render(scale=ECHELLE).to_pil().save(dest, 'PNG', optimize=True)
            page = pp.pages[p - 1]
            mots, h, w = page.extract_words(), page.height, page.width
        pdf.close()
        deb, cre = float(o.get('debit') or 0), float(o.get('credit') or 0)
        bb = boite(mots, str(o.get('libelle') or ''), h, w) \
            or boite_par_montant(mots, round(deb or cre, 2), h, w) \
            or boite(mots, str(o.get('entete') or ''), h, w)
        crgs.append({'sha': sha, 'periode': m.get('periode_cle'),
                     'date_arrete': m.get('date_arrete'),
                     'fichier': d.get('_nom_original'), 'pages': d.get('_pages'),
                     'report': None, 'solde_final': None, 'totaux': None,
                     'arbitrage': True, 'images': {str(p): {'src': 'img/crg_lecture/' + nom,
                                         'ratio': round(w / h, 4)}}})
        lignes.append({'sha': sha, 'periode': m.get('periode_cle'), 'page': p,
                       'section': str(o.get('entete') or ''),
                       'libelle': str(o.get('libelle') or ''),
                       'debit': round(deb, 2), 'credit': round(cre, 2), 'boite': bb,
                       'fichier': d.get('_nom_original')})
        questions.append({
            'titre': '🔴 ARBITRAGE — « %s » sur le compte %s (%s)'
                     % (a['libelle'], a['compte'], a['agence']),
            'aide': a['aide'], 'lignes': [len(lignes) - 1],
            'debit': round(deb, 2), 'credit': round(cre, 2)})

    # ── LES ARBITRAGES P8A — DES ÉCRITURES, VUES DANS LEUR CRG ────────────────────────────
    import p8a_socle as S8
    import p7_socle as P7S
    par_sha = {}
    for ag in ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR'):
        for dd in documents(ag):
            par_sha[dd['_sha']] = (ag, dd)
    ind = [x for ag in ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')
           for x in S8.candidats(ag)
           if x['crg_fichier'] not in P7S.REEDITIONS
           and x['sens'] == 'SENS_INDETERMINABLE']

    for a in ARBITRAGES_P8A:
        lot = [x for x in ind if x['famille'].startswith(a['famille'])]
        if not lot:
            continue
        rangs = []
        for x in sorted(lot, key=lambda z: -z['montant_source']):
            ag, d = par_sha.get(x['sha'], (None, None))
            if not d:
                continue
            chemin = SRC.chemin(d, ag, None, None)
            if not chemin or not os.path.exists(chemin):
                continue
            sha = x['sha'][:12]
            p = x['page'] or 1
            fich = '%s_p%d.png' % (sha, p)
            dest = os.path.join(IMG, fich)
            pdf = pdfium.PdfDocument(chemin)
            if p > len(pdf):
                pdf.close()
                continue
            with pdfplumber.open(chemin) as pp:
                if not os.path.exists(dest):
                    pdf[p - 1].render(scale=ECHELLE).to_pil().save(dest, 'PNG', optimize=True)
                pg = pp.pages[p - 1]
                mots, h, w = pg.extract_words(), pg.height, pg.width
            pdf.close()
            bb = (boite(mots, x['libelle_brut'], h, w)
                  or boite_par_montant(mots, x['montant_source'], h, w)
                  or boite(mots, x['section'], h, w))
            m = d.get('meta') or {}
            if not any(c['sha'] == sha and str(p) in c['images'] for c in crgs):
                crgs.append({'sha': sha, 'periode': m.get('periode_cle'),
                             'date_arrete': m.get('date_arrete'),
                             'fichier': x['crg_fichier'], 'pages': d.get('_pages'),
                             'report': None, 'solde_final': None, 'totaux': None,
                             'arbitrage': True,
                             'images': {str(p): {'src': 'img/crg_lecture/' + fich,
                                                 'ratio': round(w / h, 4)}}})
            else:
                for c in crgs:
                    if c['sha'] == sha:
                        c['images'][str(p)] = {'src': 'img/crg_lecture/' + fich,
                                               'ratio': round(w / h, 4)}
            lignes.append({'sha': sha, 'periode': x['periode_crg'], 'page': p,
                           'section': x['section'], 'libelle': x['libelle_brut'],
                           'debit': x['debit'], 'credit': x['credit'], 'boite': bb,
                           'fichier': x['crg_fichier']})
            rangs.append(len(lignes) - 1)
        if rangs:
            questions.append({
                'titre': '🔴 %s' % a['titre'], 'aide': a['aide'], 'lignes': rangs,
                'debit': round(sum(lignes[i]['debit'] for i in rangs), 2),
                'credit': round(sum(lignes[i]['credit'] for i in rangs), 2)})

    # ── LES ARBITRAGES QUI PORTENT SUR DES DOCUMENTS ENTIERS (VIENNE) ─────────────────────
    # ⚠️ LES CRG VIENNE VIVENT DANS DES ZIP. On les extrait dans un dossier temporaire le temps
    #    de les rendre en image ; rien n'est modifié dans le corpus.
    import zipfile
    import tempfile
    tmpz = tempfile.mkdtemp()
    zips = [zipfile.ZipFile(z) for z in SRC.ZIPS]
    # ⚠️ TOUS LES PÉRIMÈTRES, PAS SEULEMENT VIENNE. L'index ne couvrait que les CRG SEPTEO
    #    parce que les premiers arbitrages « document entier » venaient de là ; l'écart de
    #    solde P8B porte sur un CRG LYON, qui restait donc introuvable — et la question
    #    disparaissait sans un mot.
    par_nom = {}
    for _ag in ('LYON hors SIR', 'EMERY IMMO', 'VIENNE', 'GROUPE SIR'):
        for d in documents(_ag):
            par_nom[str(d.get('_nom_original'))] = (_ag, d)

    for a in ARBITRAGES_DOCS:
        rangs = []
        for nom in a['crgs']:
            trouve = par_nom.get(nom)
            if not trouve:
                print('  ⚠️ CRG introuvable : %s' % nom)
                continue
            ag_doc, d = trouve
            chemin = None
            if ag_doc == 'VIENNE':
                for zf in zips:
                    chemin = SRC.chemin(d, 'VIENNE', zf, tmpz)
                    if chemin and os.path.exists(chemin):
                        break
                    chemin = None
            else:
                chemin = SRC.chemin(d, ag_doc, None, None)
                if chemin and not os.path.exists(chemin):
                    chemin = None
            if not chemin:
                print('  ⚠️ PDF introuvable dans les archives : %s' % nom)
                continue
            m = d.get('meta') or {}
            sha = d.get('_sha')[:12]
            pdf = pdfium.PdfDocument(chemin)
            npages = min(len(pdf), 3)
            images = {}
            with pdfplumber.open(chemin) as pp:
                for p in range(1, npages + 1):
                    fich = '%s_p%d.png' % (sha, p)
                    dest = os.path.join(IMG, fich)
                    if not os.path.exists(dest):
                        pdf[p - 1].render(scale=ECHELLE).to_pil().save(dest, 'PNG', optimize=True)
                    pg = pp.pages[p - 1]
                    images[str(p)] = {'src': 'img/crg_lecture/' + fich,
                                      'ratio': round(pg.width / pg.height, 4)}
            pdf.close()
            crgs.append({'sha': sha, 'periode': m.get('periode_cle'),
                         'date_arrete': m.get('date_arrete'), 'fichier': nom,
                         'pages': d.get('_pages'), 'report': None, 'solde_final': None,
                         'totaux': None, 'arbitrage': True, 'images': images})
            lots = [str(l.get('locataire') or l.get('locataire_nom') or '')
                    for im in d.get('immeubles') or [] for l in im.get('lots') or []]
            nb = sum(len(im.get('mouvements') or []) + len(im.get('charges') or [])
                     for im in d.get('immeubles') or [])
            # ⚠️ PAS DE SURLIGNAGE ICI : la question porte sur la PIÈCE, pas sur une ligne.
            lignes.append({'sha': sha, 'periode': m.get('periode_cle'), 'page': 1,
                           'section': '%d écriture(s) · %d lot(s)' % (nb, len(lots)),
                           'libelle': '%s — %s' % (nom, ', '.join(lots)[:60] or 'sans locataire'),
                           'debit': 0.0, 'credit': 0.0, 'boite': None, 'fichier': nom,
                           # ⚠️ SANS REPÈRE PAR CONCEPTION : la question porte sur la
                           #    pièce entière. Le drapeau évite de compter ces entrées
                           #    comme des écritures que le lecteur n'aurait pas su placer.
                           'piece_entiere': True})
            rangs.append(len(lignes) - 1)
        # ⚠️ UNE ENTRÉE PEUT PORTER SA PROPRE QUESTION. Les cas VIENNE partagent la même —
        #    comparer deux pièces — mais l'écart de solde P8B est d'une autre nature : lui
        #    imposer le libellé des rééditions le rendrait incompréhensible.
        if rangs:
            questions.append({
                'titre': '🔴 ' + (a.get('titre') or
                                  'ARBITRAGE — %d CRG pour le compte %s au même arrêté (%s)'
                                  % (len(rangs), a['compte'], a['agence'])),
                'aide': a.get('aide') or AIDE_DOCS,
                'lignes': rangs, 'debit': 0, 'credit': 0})
    for zf in zips:
        zf.close()

    # ⚠️ CHAQUE QUESTION PORTE UNE CLÉ STABLE — et c'est le rang qui devient accessoire.
    #    Les réponses étaient indexées par POSITION : insérer trois questions au milieu a
    #    déplacé les réponses VIENNE sous des questions qu'Emmanuel n'avait jamais vues. Deux
    #    fois le même défaut, sur deux écrans. Une clé dérivée du titre ne bouge pas quand la
    #    file grandit ; l'ordre d'ajout cesse d'être une contrainte à retenir.
    def _cle_question(titre):
        s = unicodedata.normalize('NFD', str(titre))
        s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
        s = re.sub(r'[^a-zA-Z0-9]+', '-', s).strip('-').lower()
        return s[:70] or 'question'
    vues = {}
    for q in questions:
        c = _cle_question(q['titre'])
        n = vues.get(c, 0)
        vues[c] = n + 1
        q['cle'] = c if not n else '%s-%d' % (c, n + 1)

    # ⚠️ L'IMAGE MONTRE LA LIGNE, LE PDF DONNE LE DOCUMENT. Ne rendre que la page portant
    #    l'écriture rendait les questions incompréhensibles : un solde d'immeuble se lit sur
    #    plusieurs pages, un récapitulatif se compare à son détail. On indexe donc le PDF
    #    SOURCE de chaque CRG affiché, pour que l'écran puisse l'ouvrir en entier — toutes
    #    pages accessibles — sans avoir à rendre 550 images.
    index_pdf = {}
    _fp = os.path.join(DATA, 'crg_pdf_index.json')
    if os.path.exists(_fp):
        index_pdf = json.load(io.open(_fp, encoding='utf-8'))
    _par_sha = {}
    for _ag in ('LYON hors SIR', 'EMERY IMMO', 'VIENNE', 'GROUPE SIR'):
        for _d in documents(_ag):
            _par_sha[str(_d.get('_sha'))[:12]] = (_ag, _d)
    _zf = [zipfile.ZipFile(z) for z in SRC.ZIPS]
    for c in crgs:
        if c['sha'] in index_pdf:
            continue
        t_ = _par_sha.get(c['sha'])
        if not t_:
            continue
        _ag, _d = t_
        _ch = None
        if _ag == 'VIENNE':
            for z in _zf:
                _ch = SRC.chemin(_d, 'VIENNE', z, tmpz)
                if _ch and os.path.exists(_ch):
                    break
                _ch = None
        else:
            _ch = SRC.chemin(_d, _ag, None, None)
            if _ch and not os.path.exists(_ch):
                _ch = None
        if _ch:
            index_pdf[c['sha']] = {'chemin': os.path.abspath(_ch), 'nom': c['fichier']}
    for z in _zf:
        z.close()
    io.open(_fp, 'w', encoding='utf-8').write(
        json.dumps(index_pdf, ensure_ascii=False, indent=1))
    print('  index PDF : %d pièces consultables page à page' % len(index_pdf))
    for c in crgs:
        c['pdf'] = c['sha'] in index_pdf

    sortie ={'compte': COMPTE, 'proprietaire': (docs[0].get('meta') or {}).get('proprietaire'),
              'crgs': crgs, 'lignes': lignes, 'questions': questions}
    f = os.path.join(DATA, 'crg_lecture_%s.json' % COMPTE)
    io.open(f, 'w', encoding='utf-8').write(json.dumps(sortie, ensure_ascii=False, indent=1))

    sans = sum(1 for l in lignes if not l['boite'] and not l.get('piece_entiere'))
    print('  %d CRG · %d écritures · %d questions' % (len(crgs), len(lignes), len(questions)))
    print('  images : %s' % IMG)
    print('  données : %s' % f)
    print('  écritures NON localisées dans le PDF : %d / %d' % (sans, len(lignes)))
    if sans:
        for l in [x for x in lignes if not x['boite'] and not x.get('piece_entiere')][:6]:
            print('     %s p.%s « %s »' % (l['periode'], l['page'], l['libelle'][:50]))


if __name__ == '__main__':
    main()
