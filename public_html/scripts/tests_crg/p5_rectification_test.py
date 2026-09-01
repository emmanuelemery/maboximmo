# -*- coding: utf-8 -*-
"""
NON-RÉGRESSION DE LA RECTIFICATION CERTIFIÉE P5A / P5B / P6A — FOCH/SABY — 01/09/2026.

⚠️ CE QUE CE TEST PROTÈGE
   **LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF. C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER
   QU'IL CONTIENT.** Deux pièces couvrant la même situation de gestion réénoncent une partie
   de leurs événements et se complètent pour le reste. Le lecteur retire les réénoncés, garde
   les compléments, et n'élimine aucune pièce.

⚠️ AUCUN ÉVÉNEMENT N'A ÉTÉ AJOUTÉ PAR LA RECTIFICATION. Les 75 appels complémentaires étaient
   déjà dans l'ancien total : les phases lisent toutes les pièces de la GED. Ce qui était faux,
   c'est que 42 autres y figuraient DEUX FOIS. `ANCIEN − B + C` avec **C vide**.

Ce test échoue si l'un des trois lecteurs recommence à compter deux fois, si les pièces
disparaissent de la GED, ou si un complément est perdu avec le doublon.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import crg_evenement as EV   # noqa: E402
import p5a_certification as P5A   # noqa: E402
import p5a_numerateur as P5AN   # noqa: E402
import p5b_certification as P5B   # noqa: E402
import p6a_certification as P6A   # noqa: E402

LARG = 100
QUATRE = {'FOCH 0108.pdf', 'FOCH.pdf', 'SABY 0107-2.pdf', 'SABY 0107.pdf'}
# ⚠️ LES RÉFÉRENCES D'AVANT LA RECTIFICATION, GARDÉES POUR MÉMOIRE. Elles ne servent qu'à
#    afficher l'écart : le test ne les compare jamais à autre chose qu'à lui-même.
AVANT = {'P5A documentaire': 4761767.93, 'P5A appelé au locataire': 4357710.67,
         'P5B réglé': 4477689.44, 'P6A population de stock': 8718389.08}
APRES = {'P5A documentaire': 4733161.49, 'P5A appelé au locataire': 4328429.23,
         'P5B réglé': 4458248.65, 'P6A population de stock': 8543869.03}
RETIREES = {'P5A': 42, 'P5B': 38, 'P6A': 55}


def entete(t):
    print()
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def main():
    ok = True
    appels = [a for ag in AGENCES for a in P5A.appels(ag)]
    regles = [r for ag in AGENCES for r in P5B.reglements(ag)]
    encours = [o for ag in AGENCES for o in P6A.observations(ag)]
    doc = P5AN.montant_documentaire

    entete('1 — LES TOTAUX RECTIFIÉS')
    obtenu = {
        'P5A documentaire': round(sum(doc(a) for a in appels), 2),
        'P5A appelé au locataire': round(sum(v for _, v in P5AN.flux_appeles(appels)), 2),
        'P5B réglé': round(sum(float(r['montant'] or 0) for r in regles), 2),
        'P6A population de stock': round(sum(float(o['montant_source'] or 0) for o in encours), 2),
    }
    print('  %-26s %16s %16s %16s' % ('', 'AVANT', 'APRÈS', 'obtenu'))
    for k in AVANT:
        bon = abs(obtenu[k] - APRES[k]) < 0.01
        ok = ok and bon
        print('  %-26s %16s %16s %16s   %s'
              % (k, euros(AVANT[k]), euros(APRES[k]), euros(obtenu[k]), 'OK' if bon else '⚠ ÉCART'))
    print('  ⚠ P6A EST UN STOCK. Cette somme est un DÉNOMBREMENT de population, jamais un')
    print('    montant métier ni un KPI : additionner des photographies reste interdit.')

    entete('2 — LES OCCURRENCES RETIRÉES COMME RÉÉNONCÉES')
    for lib, gen in (('P5A', P5A.appels_retirees), ('P5B', P5B.reglements_retirees),
                     ('P6A', P6A.observations_retirees)):
        v = [x for ag in AGENCES for x in gen(ag)]
        bon = len(v) == RETIREES[lib]
        ok = ok and bon
        print('  %-4s %3d retirées (attendu %d)   %s' % (lib, len(v), RETIREES[lib],
                                                         'OK' if bon else '⚠ ÉCART'))
        hors = {x['crg_fichier'] for x in v} - QUATRE
        if hors:
            ok = False
            print('     ⚠ retirées hors du périmètre FOCH/SABY : %s' % hors)

    entete('3 — PLUS AUCUN ÉVÉNEMENT N’EST ÉNONCÉ DEUX FOIS')
    for lib, lst, ev in (('P5A', appels, P5A._ev_appel), ('P5B', regles, P5B._ev_reglement),
                         ('P6A', encours, P6A._ev_observation)):
        vu = collections.defaultdict(set)
        for x in lst:
            vu[(EV.cle_situation(x), ev(x))].add(x['crg_fichier'])
        dbl = {k: v for k, v in vu.items() if len(v) > 1}
        ok = ok and not dbl
        print('  %-4s événements portés par plusieurs pièces d’une même situation : %d   %s'
              % (lib, len(dbl), 'OK' if not dbl else '⚠'))

    entete('4 — AUCUNE PIÈCE N’A DISPARU, AUCUN COMPLÉMENT N’EST PERDU')
    # ⚠️ LE PIÈGE QUE CETTE RECTIFICATION CORRIGE : neutraliser un fichier entier. On vérifie
    #    donc que les quatre pièces sont TOUJOURS lues, et que la plus complète survit.
    par = collections.Counter(a['crg_fichier'] for a in appels if a['crg_fichier'] in QUATRE)
    lues = {a['crg_fichier'] for ag in AGENCES for a in P5A._appels_brutes(ag)} & QUATRE
    print('  pièces toujours présentes dans le corpus : %d / 4' % len(lues))
    ok = ok and len(lues) == 4
    for f in sorted(QUATRE):
        print('     %-18s %3d appels retenus' % (f, par[f]))
    complets = par['FOCH 0108.pdf'] + par['SABY 0107-2.pdf']
    bon = complets == 117
    ok = ok and bon
    print('  la pièce la plus complète de chaque situation porte les 117 événements : %s'
          % ('OK' if bon else '⚠ %d' % complets))

    entete('RECTIFICATION P5A / P5B / P6A : %s' % ('TENUE' if ok else 'ROMPUE'))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
