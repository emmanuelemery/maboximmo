# -*- coding: utf-8 -*-
"""
CONTRÔLE AU PDF DES 12 LIGNES « SALAIRE » DU COMPTE MANDANT — arbitrage Emmanuel du 31/08/2026.

⚠️ ON NE QUALIFIE PAS UNE DÉPENSE SUR UN MOT TROUVÉ DANS UN CHAMP JSON. Emmanuel demande la
   vérification au document : chaque ligne est rouverte à SA page, et on imprime ce que le CRG
   imprime autour d'elle — la section qui la surplombe, la ligne elle-même, ses voisines.

La question posée est précise : **le document démontre-t-il une dépense de personnel supportée
par le mandant**, ou seulement le règlement de quelque chose d'autre ?

Lecture seule. Rien n'est modifié, P7A n'est pas touchée.
"""
import os
import re
import sys
import tempfile
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import documents, euros, ouvrir_pdf   # noqa: E402
import p4b_source_pdf as SRC   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 100
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')
SALAIRE = re.compile(r"\bsalaires?\b|\bpaie\b", re.I)


def main():
    cibles = []
    for ag in LYON:
        for x in S.candidats(ag):
            if x['conteneur'] != 'mandat.operations':
                continue
            if SALAIRE.search(x['libelle_brut']) or SALAIRE.search(x['section']):
                cibles.append(x)
    cibles.sort(key=lambda y: -y['montant_source'])

    print('=' * LARG)
    print('LES %d LIGNES « SALAIRE » DU COMPTE MANDANT — %s'
          % (len(cibles), euros(sum(x['montant_source'] for x in cibles))))
    print('=' * LARG)

    # index des documents pour retrouver le PDF
    par_sha = {}
    for ag in LYON:
        for d in documents(ag):
            par_sha[d['_sha']] = (ag, d)

    tmp = tempfile.mkdtemp()
    cache = {}
    for i, x in enumerate(cibles, 1):
        ag, d = par_sha[x['sha']]
        if x['sha'] not in cache:
            p = SRC.chemin(d, ag, None, tmp)
            cache[x['sha']] = SRC.pages_de(p) if p else {}
        pages = cache[x['sha']]
        print()
        print('─' * LARG)
        print('%2d. %-13s  compte %-10s  %12s   maille : %s'
              % (i, x['agence'], x['compte'], euros(x['montant_source']),
                 x['maille_documentaire']))
        print('    rubrique lue : « %s »' % x['section'])
        print('    libellé lu   : « %s »' % x['libelle_brut'])
        print('    PDF          : %s  page %s' % (x['crg_fichier'], x['page']))
        txt = pages.get(x['page']) or pages.get(str(x['page'])) or ''
        if not txt:
            print('    ⚠️ PAGE NON RELUE — le PDF n’a pas pu être rouvert : qualification'
                  ' impossible, la ligne reste INDÉTERMINABLE.')
            continue
        # ⚠️ ON MONTRE LE VOISINAGE, PAS SEULEMENT LA LIGNE. C'est la section qui la surplombe
        #    et les lignes adjacentes qui disent si le CRG la présente comme une dépense.
        lignes = [l.rstrip() for l in txt.split('\n')]
        idx = [k for k, l in enumerate(lignes)
               if SALAIRE.search(l) or (x['libelle_brut'][:18] and x['libelle_brut'][:18] in l)]
        if not idx:
            print('    ⚠️ LIBELLÉ INTROUVABLE DANS LE TEXTE DE LA PAGE.')
            continue
        for k in idx[:2]:
            print('    ── ce que le PDF imprime autour ──')
            for j in range(max(0, k - 4), min(len(lignes), k + 3)):
                marque = '  >>' if j == k else '    '
                if lignes[j].strip():
                    print('    %s %s' % (marque, lignes[j][:92]))


if __name__ == '__main__':
    main()
