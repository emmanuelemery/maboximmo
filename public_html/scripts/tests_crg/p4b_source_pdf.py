# -*- coding: utf-8 -*-
"""
P4B · CONTRÔLE DOCUMENTAIRE — ce que le PDF dit vraiment de la sortie d'un locataire.

Deux questions, une seule ouverture des PDF :

  ① LES SUCCESSIONS SONT-ELLES VRAIES ? Pour chaque succession A → B retenue, on rouvre le CRG
    à la page déclarée et on vérifie que LES DEUX noms y sont imprimés. Une succession déduite
    d'un nom mal lu ferait basculer un locataire présent dans l'historique.

  ② LE DOCUMENT DIT-IL DES CHOSES QUE LE LECTEUR JETTE ? Le lecteur `lyon` ne garde d'une ligne
    « Divers » que ses colonnes chiffrées : le libellé est perdu. On cherche donc, au PDF, ce
    qui pourrait dater une fin de location.

    ⚠️ ET « DÉPÔT DE GARANTIE REVERSÉ » N'EN EST PAS UNE — arbitrage Emmanuel. Sur LYON, le
       dépôt de garantie est reversé AU PROPRIÉTAIRE : c'est un mouvement entre la régie et le
       mandant, qui peut survenir à n'importe quel moment et ne dit rien du locataire. Les 46
       lignes trouvées au premier passage étaient donc une fausse piste, et le terme est retiré
       des marqueurs. Ce qui daterait vraiment une sortie, c'est une CLÔTURE DE COMPTE
       LOCATAIRE — un solde de compte, un arrêté de compte, un décompte de sortie.

Lent (458 PDF) : à lancer à la demande, hors harnais courant.
"""
import sys
import os
import io
import re
import json
import glob
import zipfile
import tempfile
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, ouvrir_pdf   # noqa: E402
import p4b_certification as P4B   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
ZIPS = glob.glob(r'D:\CRG REGIE EMERY VIENNE\*.zip')
SORTIE = r'C:\tmp\p4b_source.json'

# ⚠️ CE QU'ON CHERCHE : UNE CLÔTURE DE COMPTE LOCATAIRE, pas un mouvement de trésorerie.
#    Le dépôt de garantie est volontairement ABSENT de cette liste (cf. en-tête).
SORTIE_MOTS = re.compile(
    r'(solde\s+de\s+compte|solde\s+de\s+tout\s+compte|compte\s+sold[ée]|cl[oô]tur\w*\s+'
    r'(de\s+)?compte|arr[eê]t[ée]?\s+de\s+compte|d[ée]compte\s+de\s+sortie|'
    r'[ée]tat\s+des\s+lieux\s+de\s+sortie|cong[ée]\s|d[ée]part\s+(du\s+)?locataire|'
    r'locataire\s+parti|fin\s+de\s+bail|sorti[e]?\s+le\b|r[ée]sili)', re.I)
LOT = re.compile(r'^\s*Lot\s+([0-9A-Za-z]+)\b')


def chemin(d, ag, zf, tmp):
    nom = d.get('_nom_original')
    fich = str(d.get('_fichier') or nom)
    if ag == 'VIENNE':
        interne = next((n for n in zf.namelist() if n.endswith(fich.split(' :: ')[-1])), None)
        return zf.extract(interne, tmp) if interne else None
    racine = RACINES.get(ag)
    direct = os.path.join(racine, fich.replace('/', '\\'))
    if os.path.exists(direct):
        return direct
    t = glob.glob(os.path.join(racine, '**', nom), recursive=True)
    return t[0] if t else None


def pages_de(p):
    # ⚠️ FAIL CLOSED : seules les erreurs de DOCUMENT sont absorbées, jamais un bug de lecture.
    return ouvrir_pdf(p) or {}


if __name__ == '__main__':
    tout = P4B.analyser(list(perimetre().keys()))
    # ⚠️ A ET B NE SONT PAS TOUJOURS DANS LE MÊME PDF. Une succession entre deux trimestres a
    #    son ancien titulaire dans un CRG et son repreneur dans un autre : chercher les deux
    #    dans le même document déclarait « absent » les successions inter-périodes, c'est-à-dire
    #    précisément celles qui prouvent l'historique. Chaque nom se vérifie donc dans SON PDF.
    successions = []
    shas = set()
    for ag, v in tout.items():
        for o in v['occupations']:
            if o['classe'] != 'B — succession démontrée':
                continue
            rep = [x for x in v['lots'][(ag, o['compte'], o['immeuble'], o['lot'])]
                   if x['identite'] != o['identite'] and x['cov_debut'] and o['cov_fin']
                   and x['cov_debut'] >= o['cov_fin']]
            if not rep:
                continue
            successions.append((ag, o, rep[0]))
            shas.add(o['sources'][0]['sha'])
            shas.add(rep[0]['sources'][0]['sha'])
    textes = {}

    zf = zipfile.ZipFile(ZIPS[0]) if ZIPS else None
    tmp = tempfile.mkdtemp()
    c = collections.Counter()
    controles, mentions = [], []

    for ag in perimetre():
        docs = documents(ag)
        for i, d in enumerate(docs, 1):
            p = chemin(d, ag, zf, tmp)
            pages = pages_de(p) if p else {}
            if not pages:
                c[(ag, 'pdf illisible')] += 1
                continue

            # ① on met de côté le texte des CRG qui portent une succession
            if d.get('_sha') in shas:
                textes[d.get('_sha')] = pages

            # ② les mentions de sortie que le lecteur ne conserve pas
            for n, t in pages.items():
                lot = loc = None
                for ligne in t.split('\n'):
                    m = LOT.match(ligne)
                    if m:
                        lot, loc = m.group(1), None
                        continue
                    if lot and loc is None and ligne.strip() and not ligne[0].isdigit() \
                            and not ligne.strip().lower().startswith(('du ', 'totaux', 'solde')):
                        loc = ligne.strip()[:44]
                    if SORTIE_MOTS.search(ligne):
                        c[(ag, 'mentions de sortie')] += 1
                        mentions.append({'agence': ag, 'pdf': d.get('_nom_original'), 'page': n,
                                         'lot': lot, 'locataire': loc, 'ligne': ligne.strip()[:130]})
            if i % 60 == 0:
                print('   %s %d/%d...' % (ag, i, len(docs)), flush=True)

    def imprime(nom, sha, page):
        """Le nom est-il littéralement imprimé — d'abord à la page déclarée, puis ailleurs
           dans le même document (un lot à cheval sur deux pages y échappe autrement)."""
        pages = textes.get(sha) or {}
        n = P4B.plat(nom)
        if n and n in P4B.plat(pages.get(page, '')):
            return 'page déclarée'
        for p2, t2 in pages.items():
            if n and n in P4B.plat(t2):
                return 'page %d' % p2
        return ''

    for ag, o, r in successions:
        sA, sB = o['sources'][0], r['sources'][0]
        ouA = imprime(o['libelle'], sA['sha'], sA['page'])
        ouB = imprime(r['libelle'], sB['sha'], sB['page'])
        c[(ag, 'successions controlees')] += 1
        if ouA and ouB:
            c[(ag, 'successions confirmees')] += 1
        controles.append({'agence': ag, 'compte': o['compte'], 'immeuble': o['immeuble'],
                          'lot': o['lot'],
                          'A': o['libelle'], 'A_periode': [o['cov_debut'], o['cov_fin']],
                          'A_pdf': sA['pdf'], 'A_page': sA['page'], 'A_imprime': ouA,
                          'B': r['libelle'], 'B_periode': [r['cov_debut'], r['cov_fin']],
                          'B_pdf': sB['pdf'], 'B_page': sB['page'], 'B_imprime': ouB})

    io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(
        {'successions': controles, 'mentions_de_sortie': mentions}, ensure_ascii=False, indent=1))

    entete('① SUCCESSIONS A → B — LES DEUX NOMS SONT-ILS IMPRIMÉS ?')
    print('  %-16s %14s %14s' % ('PÉRIMÈTRE', 'contrôlées', 'confirmées'))
    for ag in perimetre():
        print('  %-16s %14d %14d' % (ag, c[(ag, 'successions controlees')],
                                     c[(ag, 'successions confirmees')]))
    faux = [x for x in controles if not (x['A_imprime'] and x['B_imprime'])]
    print('  → %d succession(s) dont un nom n’est pas retrouvé au PDF' % len(faux))
    for x in faux[:5]:
        print('     lot %-7s A « %s » %s p.%s → %s | B « %s » %s p.%s → %s'
              % (x['lot'], x['A'][:20], x['A_pdf'][:26], x['A_page'], x['A_imprime'] or 'ABSENT',
                 x['B'][:20], x['B_pdf'][:26], x['B_page'], x['B_imprime'] or 'ABSENT'))

    entete('② UNE CLÔTURE DE COMPTE LOCATAIRE EST-ELLE IMPRIMÉE QUELQUE PART ?')
    print('  %-16s %14s' % ('PÉRIMÈTRE', 'lignes'))
    for ag in perimetre():
        print('  %-16s %14d' % (ag, c[(ag, 'mentions de sortie')]))
    ech = collections.Counter(re.sub(r'[\d.,%€-]+', '', m['ligne']).strip()[:58] for m in mentions)
    for k, v in ech.most_common(8):
        print('     %4d  %s' % (v, k))
    print('\n  ⚠ COMPTÉES, PAS UTILISÉES. Le dépôt de garantie est exclu de cette recherche : il')
    print('    est reversé AU PROPRIÉTAIRE, et ne date aucune sortie de locataire.')
    print('\ndétail → %s' % SORTIE)
