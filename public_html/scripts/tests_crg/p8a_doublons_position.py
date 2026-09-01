# -*- coding: utf-8 -*-
"""
DEUX LIGNES IDENTIQUES : DEUX RÈGLEMENTS, OU UNE LIGNE LUE DEUX FOIS ?

⚠️ LA QUESTION D'EMMANUEL EST LA BONNE. « On peut avoir des règlements identiques, il faut
   juste vérifier que tu ne fais pas d'erreur à la lecture des lignes. » P7A comptait une clé
   répétée UNE SEULE FOIS en borne basse, au motif qu'une dépense peut être réénoncée d'un CRG
   à l'autre. Si ce sont en réalité des échéances distinctes, la borne basse n'est pas
   prudente : elle est FAUSSE.

⚠️ ET LE DOCUMENT TRANCHE, PAS L'INTUITION. Deux lignes imprimées à des POSITIONS DIFFÉRENTES
   (page, ordonnée) sont deux lignes ; deux occurrences à la MÊME position dans le MÊME PDF
   sont la même ligne comptée deux fois par le lecteur. On mesure donc, sans rien supposer :

   ① même sha + même page + même y   → DÉFAUT DE LECTURE, une seule dépense
   ② même sha, positions différentes → DEUX LIGNES IMPRIMÉES dans le même CRG
   ③ sha différents                  → RÉÉNONCÉ d'un CRG à l'autre, ou échéances successives

Lecture seule. P7A n'est pas modifiée.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402
import p7_socle as P7   # noqa: E402

LARG = 100


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def lignes_positionnees(agence):
    """Les lignes P7A/P7B avec leur position imprimée — ce que `p7_socle.lignes()` ne porte pas."""
    for d in documents(agence):
        m = d.get('meta') or {}
        fm = d.get('_format')
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            if fm == 'septeo_spi':
                for mv in im.get('mouvements') or []:
                    sec = mv.get('section')
                    nat, cote = P7.nature(sec, str(mv.get('libelle') or ''), sec)
                    if cote not in ('P7A', 'P7B'):
                        continue
                    yield {'agence': agence, 'compte': str(m.get('compte') or ''),
                           'immeuble': code, 'entete': str(sec or ''),
                           'libelle': str(mv.get('libelle') or ''),
                           'montant': round(float(mv.get('debit') or 0), 2),
                           'date': P4B.jour(mv.get('date')), 'sha': d.get('_sha'),
                           'page': mv.get('page'), 'y': None,
                           'crg': d.get('_nom_original'), 'periode': m.get('periode_cle')}
            else:
                for ch in im.get('charges') or []:
                    e, l = str(ch.get('entete') or ''), str(ch.get('libelle') or '')
                    sec = P7.section_de(d.get('_sha'), ch.get('page'), l, e)
                    nat, cote = P7.nature(e, l, sec)
                    if cote not in ('P7A', 'P7B'):
                        continue
                    yield {'agence': agence, 'compte': str(m.get('compte') or ''),
                           'immeuble': code, 'entete': e, 'libelle': l,
                           'montant': round(float(ch.get('debit') or 0), 2),
                           'date': None, 'sha': d.get('_sha'), 'page': ch.get('page'),
                           'y': round(float(ch.get('y') or 0), 1),
                           'crg': d.get('_nom_original'), 'periode': m.get('periode_cle')}


def main():
    total = collections.Counter()
    montants = collections.Counter()
    exemples = collections.defaultdict(list)

    for ag in AGENCES:
        par = collections.defaultdict(list)
        for x in lignes_positionnees(ag):
            par[(x['agence'], x['compte'], x['immeuble'], x['entete'],
                 x['libelle'], x['montant'], x['date'])].append(x)
        for cle, v in par.items():
            if len(v) < 2:
                continue
            positions = {(x['sha'], x['page'], x['y']) for x in v}
            shas = {x['sha'] for x in v}
            if len(positions) == 1:
                genre = '① DÉFAUT DE LECTURE — même PDF, même page, même ligne'
            elif len(shas) == 1:
                genre = '② DEUX LIGNES IMPRIMÉES dans le MÊME CRG'
            else:
                genre = '③ RÉPÉTÉ D’UN CRG À L’AUTRE'
            total[genre] += len(v) - 1
            montants[genre] += v[0]['montant'] * (len(v) - 1)
            if len(exemples[genre]) < 4:
                exemples[genre].append(v)

    entete('LES CLÉS RÉPÉTÉES DE P7A/P7B, JUGÉES PAR LA POSITION IMPRIMÉE')
    print('  %-52s %10s %16s' % ('GENRE', 'occur. en+', 'montant en jeu'))
    for g in sorted(total):
        print('  %-52s %10d %16s' % (g, total[g], euros(montants[g])))
    print()
    print('  %-52s %10d %16s' % ('TOTAL des occurrences répétées', sum(total.values()),
                                 euros(sum(montants.values()))))

    for g in sorted(exemples):
        entete(g)
        for v in exemples[g]:
            x = v[0]
            print('  ×%d  %-13s cpt %-9s %-30s %12s'
                  % (len(v), x['agence'][:13], x['compte'], x['libelle'][:30],
                     euros(x['montant'])))
            for y in v:
                print('        %-30s p.%-4s y=%-8s %s'
                      % (str(y['crg'])[:30], y['page'], y['y'], y['periode']))

    entete('CE QUE CELA CHANGE POUR LA BORNE BASSE DE P7A')
    # ⚠️ ON NE RECALCULE PAS P7A — ON MESURE CE QU'UNE AUTRE LECTURE DONNERAIT. La borne basse
    #    actuelle retire TOUTES les répétitions. Si seules les répétitions ① sont des défauts
    #    de lecture, la borne basse retire à tort ② et ③.
    faux = montants['① DÉFAUT DE LECTURE — même PDF, même page, même ligne']
    reels = sum(montants.values()) - faux
    print('  retiré aujourd’hui par la borne basse          : %s' % euros(sum(montants.values())))
    print('  dont vraies répétitions du lecteur (①)         : %s' % euros(faux))
    print('  dont lignes RÉELLEMENT imprimées deux fois (②③) : %s' % euros(reels))
    print()
    print('  ⚠️ Si ②③ sont des règlements distincts, ce montant est retiré À TORT de la borne')
    print('     basse. La décision appartient à Emmanuel : P7A est figée.')


if __name__ == '__main__':
    main()
