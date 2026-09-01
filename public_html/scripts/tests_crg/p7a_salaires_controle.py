# -*- coding: utf-8 -*-
"""
CONTRÔLE DOCUMENTAIRE DES ÉCRITURES « SALAIRE » — avant toute décision sur le périmètre P7A.

⚠️ NI L'UN NI L'AUTRE RÉFLEXE. Le mot « SALAIRE » ne fait pas une dépense P7A ; le conteneur
   `mandat.operations` ne l'en exclut pas non plus. On regarde ce que le document démontre :
   le voisinage de la ligne, sa maille, sa date, et l'existence — ou non — d'une dépense
   correspondante ailleurs dans le corpus.

⚠️ ET UN NOM DE PERSONNE N'EST PAS UN SALARIÉ. `VANEL NOUBOUWO EI` porte la mention « EI » —
   entreprise individuelle. Le document ne dit pas si c'est un salaire ou une facture de
   prestation ; on le signale au lieu de choisir.

Lecture seule. Aucune modification de P7A, aucune reprise de P8A.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402
import p7_socle as P7   # noqa: E402

LARG = 118

# ⚠️ ON CHERCHE LARGE, PUIS ON TRIE. Restreindre d'emblée à « salaire » aurait manqué les
#    charges sociales et les organismes, qui diraient si le CRG porte une vraie paie.
MOTS = re.compile(r"\bsalaires?\b|\bpaie\b|\bpaye\b|bulletin|urssaf|\bmsa\b|net\s+[àa]\s+payer|"
                  r"charges?\s+sociales?|cotisations?\s+sociales?|pole\s+emploi|"
                  r"p[ôo]le\s+emploi|retraite\s+compl|pr[ée]voyance|mutuelle\s+salari", re.I)


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def toutes_les_ecritures():
    """Chaque écriture du corpus, TOUS CONTENEURS, avec sa provenance et son voisinage."""
    for ag in AGENCES:
        for d in documents(ag):
            m = d.get('meta') or {}
            base = {'agence': ag, 'crg': d.get('_nom_original'), 'sha': d.get('_sha'),
                    'periode': m.get('periode_cle'), 'compte': str(m.get('compte') or ''),
                    'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or '')}
            ops = list((d.get('mandat') or {}).get('operations') or [])
            ops.sort(key=lambda o: (o.get('page') or 0, o.get('y') or 0))
            for i, o in enumerate(ops):
                yield dict(base, conteneur='mandat.operations', immeuble='', lot='',
                           maille='compte mandant', section=str(o.get('entete') or ''),
                           libelle=str(o.get('libelle') or ''),
                           debit=round(float(o.get('debit') or 0), 2),
                           credit=round(float(o.get('credit') or 0), 2),
                           page=o.get('page'), y=o.get('y'), rang=i, voisins=ops)
            for im in d.get('immeubles') or []:
                code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
                for ch in im.get('charges') or []:
                    yield dict(base, conteneur='immeubles[].charges', immeuble=code, lot='',
                               maille='immeuble', section=str(ch.get('entete') or ''),
                               libelle=str(ch.get('libelle') or ''),
                               debit=round(float(ch.get('debit') or 0), 2),
                               credit=round(float(ch.get('credit') or 0), 2),
                               page=ch.get('page'), y=ch.get('y'), rang=None, voisins=None)
                for mv in im.get('mouvements') or []:
                    yield dict(base, conteneur='immeubles[].mouvements', immeuble=code,
                               lot=str(mv.get('ref_lot') or ''), maille='lot/immeuble',
                               section=str(mv.get('section') or ''),
                               libelle=str(mv.get('libelle') or ''),
                               debit=round(float(mv.get('debit') or 0), 2),
                               credit=round(float(mv.get('credit') or 0), 2),
                               page=mv.get('page'), y=None, rang=None, voisins=None)
                for lot in im.get('lots') or []:
                    n = str(lot.get('locataire_nom') or '')
                    if MOTS.search(n):
                        yield dict(base, conteneur='immeubles[].lots', immeuble=code,
                                   lot=str(lot.get('numero_lot') or ''), maille='lot',
                                   section='(locataire)', libelle=n, debit=0.0, credit=0.0,
                                   page=lot.get('page'), y=None, rang=None, voisins=None)


def main():
    tout = list(toutes_les_ecritures())
    trouves = [x for x in tout if MOTS.search(x['libelle']) or MOTS.search(x['section'])]

    entete('OÙ LE MOT APPARAÎT-IL DANS LE CORPUS ? (tous conteneurs, 458 CRG)')
    for c, n in collections.Counter(x['conteneur'] for x in trouves).most_common():
        m = sum(x['debit'] or x['credit'] for x in trouves if x['conteneur'] == c)
        print('  %-28s %4d écritures   %14s' % (c, n, euros(m)))
    print('  %-28s %4d écritures   %14s'
          % ('TOTAL', len(trouves), euros(sum(x['debit'] or x['credit'] for x in trouves))))
    print()
    print('  périmètres concernés : %s' % ', '.join(sorted({x['agence'] for x in trouves})))
    print('  comptes concernés    : %s' % ', '.join(sorted({x['compte'] for x in trouves})))

    entete('LES ÉCRITURES, UNE PAR UNE')
    print('  %-11s %-9s %-9s %-26s %-40s %10s %-7s %s'
          % ('PÉRIM.', 'compte', 'période', 'RUBRIQUE', 'LIBELLÉ EXACT', 'montant', 'D/C', 'PDF · page'))
    for x in sorted(trouves, key=lambda y: (y['agence'], str(y['periode']), -(y['debit'] or y['credit']))):
        print('  %-11s %-9s %-9s %-26s %-40s %10s %-7s %s p.%s'
              % (x['agence'][:11], x['compte'], x['periode'], x['section'][:26],
                 x['libelle'][:40], euros(x['debit'] or x['credit']),
                 'débit' if x['debit'] else 'crédit', str(x['crg'])[:26], x['page']))

    entete('LE VOISINAGE DOCUMENTAIRE — ce que le CRG imprime autour')
    # ⚠️ LA GRAMMAIRE DU COMPTE SE LIT DANS LA SÉQUENCE. Une ligne isolée ne dit pas si elle
    #    appartient à un bloc de paie ou à une suite de virements de trésorerie.
    vus = set()
    for x in sorted(trouves, key=lambda y: -(y['debit'] or y['credit']))[:6]:
        if x['voisins'] is None or (x['sha'], x['rang']) in vus:
            continue
        vus.add((x['sha'], x['rang']))
        print('  ── %s · %s · p.%s' % (x['periode'], str(x['crg'])[:38], x['page']))
        for j in range(max(0, x['rang'] - 2), min(len(x['voisins']), x['rang'] + 3)):
            o = x['voisins'][j]
            marque = '>>' if j == x['rang'] else '  '
            print('     %s [%-26s] %-44s D %11s C %11s'
                  % (marque, str(o.get('entete') or '')[:26], str(o.get('libelle') or '')[:44],
                     euros(o.get('debit')), euros(o.get('credit'))))
        print()

    entete('B — UNE DÉPENSE P7A CORRESPONDANTE EXISTE-T-ELLE AILLEURS ?')
    p7 = collections.defaultdict(list)
    for ag in AGENCES:
        for l in P7.lignes(ag):
            if l['cote'] in ('P7A', 'P7B'):
                p7[(ag, l['compte'], round(l['montant_source'], 2))].append(l)
    corr = 0
    for x in trouves:
        k = (x['agence'], x['compte'], round(x['debit'] or x['credit'], 2))
        if p7.get(k):
            corr += 1
            print('  %-11s %-9s %10s  « %s »' % (x['agence'][:11], x['compte'],
                                                 euros(k[2]), x['libelle'][:40]))
            for l in p7[k][:2]:
                print('       ↳ P7A/P7B : [%s] « %s » (%s)'
                      % (l['entete_source'][:26], l['libelle_brut'][:40], l['periode_crg']))
    if not corr:
        print('  AUCUNE. Aucun montant de ces écritures ne se retrouve dans une dépense P7A/P7B')
        print('  du même compte : la dépense sous-jacente n’existe nulle part ailleurs.')

    entete('LE DOCUMENT DISTINGUE-T-IL SALAIRE, CHARGE SOCIALE, REMBOURSEMENT, TRANSFERT ?')
    familles = [
        ('un organisme social nommé (URSSAF, MSA, retraite…)',
         r"urssaf|\bmsa\b|retraite|pr[ée]voyance|pole\s+emploi|p[ôo]le\s+emploi|cotisation"),
        ('une période de paie imprimée (mois, année)',
         r"\b(janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[ûu]t|septembre|octobre|"
         r"novembre|d[ée]cembre)\b"),
        ('un nombre de bulletins (« 5 X SALAIRES »)', r"\d\s*x\s*salaires?"),
        ('une mention d’entreprise individuelle (« EI »)', r"\bei\b"),
        ('une annulation / un remboursement', r"annul|rembours|avoir"),
        ('un moyen de paiement bancaire (VIRT, VRT…)', r"\bvirt\b|\bvrt\b|virement"),
    ]
    for nom, motif in familles:
        mo = re.compile(motif, re.I)
        v = [x for x in trouves if mo.search(x['libelle']) or mo.search(x['section'])]
        print('  %-52s %3d / %d' % (nom, len(v), len(trouves)))
        for x in v[:2]:
            print('        « %s »' % x['libelle'][:70])

    entete('LA MAILLE — rattachées à un immeuble, ou seulement au compte ?')
    for m, n in collections.Counter(x['maille'] for x in trouves).most_common():
        print('  %-24s %3d' % (m, n))
    print('  écritures portant un immeuble ou un lot : %d'
          % sum(1 for x in trouves if x['immeuble'] or x['lot']))
    print('  écritures portant une date imprimée      : %d'
          % sum(1 for x in trouves if re.search(r'\d{1,2}[./]\d{1,2}[./]\d{2,4}', x['libelle'])))


if __name__ == '__main__':
    main()
