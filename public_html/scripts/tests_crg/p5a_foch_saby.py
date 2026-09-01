# -*- coding: utf-8 -*-
# ⚠️ MODULE HISTORIQUE — LA DÉCOUVERTE, PAS L'ÉTAT ACTUEL.
#    Il a servi à établir la régression FOCH/SABY le 01/09/2026, quand les lecteurs comptaient
#    encore deux fois les événements réénoncés. Depuis la RECTIFICATION CERTIFIÉE du même jour,
#    `P5A.appels()`, `P5B.reglements()` et `P6A.observations()` dédoublonnent à la source : ce
#    module ne trouve donc plus de doublon, et c'est le résultat attendu. La non-régression est
#    assurée par `p5_rectification_test.py`, pas ici. Ne pas lire ses chiffres comme un état
#    du corpus : ils décrivent une confrontation qui n'a plus lieu d'être.

"""
P5A — PASSE CIBLÉE FOCH / SABY : réénonciation ou complément ?

⚠️ LA QUESTION N'EST PAS « QUEL PDF FAIT FOI ». Une même paire de pièces peut être réédition
   pour une famille d'écritures et complémentaire pour une autre. La déduplication porte donc
   sur l'ÉVÉNEMENT MÉTIER DÉMONTRÉ — lot × période d'appel × montant — jamais sur le fichier.

⚠️ ET « ABSENT DE L'AUTRE PIÈCE » NE VEUT PAS DIRE « ABSENT DU CORPUS ». Un appel d'avril peut
   figurer dans un troisième CRG du même compte. Le test décisif est mené contre TOUT le
   corpus du compte, pas contre la seule pièce jumelle.

Lecture seule. Aucun référentiel modifié : ce module MESURE, il ne rectifie pas.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p5a_certification as P5A   # noqa: E402
import p5b_certification as P5B   # noqa: E402
import p6a_certification as P6A   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as P8A   # noqa: E402

LARG = 104
PAIRES = (('FOCH 0108.pdf', 'FOCH.pdf', '01080000'),
          ('SABY 0107-2.pdf', 'SABY 0107.pdf', '01070000'))


def entete(t):
    print()
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def mt(a):
    return round(sum(float(c.get('montant') or 0) for c in a.get('composantes') or []), 2)


def evenement(a):
    """L'événement métier : quel lot, pour quelle période, de quel montant."""
    return (str(a.get('immeuble') or ''), str(a.get('lot') or ''), str(a.get('locataire') or ''),
            a.get('periode_appel_debut'), a.get('periode_appel_fin'),
            a.get('periode_appel_texte'), mt(a))


def main():
    ok = True
    appels = [a for ag in AGENCES for a in P5A.appels(ag)]

    entete('1 — LES DEUX PAIRES, ÉVÉNEMENT PAR ÉVÉNEMENT')
    reenonces, nouveaux = [], []
    for ecarte, garde, compte in PAIRES:
        va = [a for a in appels if a['crg_fichier'] == ecarte]
        vb = [a for a in appels if a['crg_fichier'] == garde]
        eb = collections.Counter(evenement(a) for a in vb)
        vus = collections.Counter()
        rea, neu = [], []
        for a in va:
            e = evenement(a)
            if vus[e] < eb[e]:
                vus[e] += 1
                rea.append(a)
            else:
                neu.append(a)
        reenonces += rea
        nouveaux += neu
        print('  %s  (écartée par P7)  vs  %s  (conservée) — compte %s'
              % (ecarte, garde, compte))
        print('     écartée   %3d appels · %14s' % (len(va), euros(sum(mt(a) for a in va))))
        print('     conservée %3d appels · %14s' % (len(vb), euros(sum(mt(a) for a in vb))))
        print('     RÉÉNONCÉS %3d appels · %14s   (mêmes lot, période et montant)'
              % (len(rea), euros(sum(mt(a) for a in rea))))
        print('     NOUVEAUX  %3d appels · %14s' % (len(neu), euros(sum(mt(a) for a in neu))))
        # ⚠️ un événement de la pièce conservée que l'écartée ne porterait pas serait la preuve
        #    que la réédition n'est pas un sur-ensemble. On le vérifie explicitement.
        ea = collections.Counter(evenement(a) for a in va)
        manquants = [e for e, n in eb.items() if ea[e] < n]
        print('     présents SEULEMENT dans la conservée : %d' % len(manquants))
        if manquants:
            ok = False

    entete('2 — LE TEST DÉCISIF : ces appels « nouveaux » sont-ils absents de TOUT le corpus ?')
    # ⚠️ C'EST ICI QUE SE JOUE LA RECTIFICATION. Si un troisième CRG du même compte porte déjà
    #    ces appels, ils ne sont pas perdus et P5A reste inchangée.
    vraiment, ailleurs = [], []
    for a in nouveaux:
        e = evenement(a)
        autres = [b for b in appels
                  if b['compte'] == a['compte']
                  and b['crg_fichier'] not in {p[0] for p in PAIRES}
                  and evenement(b) == e]
        (ailleurs if autres else vraiment).append((a, autres))
    print('  appels « nouveaux » retrouvés dans un AUTRE CRG du même compte : %d · %s'
          % (len(ailleurs), euros(sum(mt(a) for a, _ in ailleurs))))
    for a, autres in ailleurs[:4]:
        print('     %-10s lot %-6s %-22s %10.2f  déjà dans %s'
              % (a['compte'], a['lot'], str(a.get('periode_appel_debut'))[:22], mt(a),
                 autres[0]['crg_fichier']))
    print('  appels ABSENTS DE TOUT LE CORPUS hors pièce écartée : %d · %s'
          % (len(vraiment), euros(sum(mt(a) for a, _ in vraiment))))
    par_per = collections.Counter()
    for a, _ in vraiment:
        par_per['%s → %s' % (a.get('periode_appel_debut'), a.get('periode_appel_fin'))] += mt(a)
    for p, v in sorted(par_per.items()):
        print('     %-32s %14s' % (p, euros(v)))

    entete('3 — LA PREUVE : où ces appels sont-ils imprimés ?')
    print('  %-16s %-6s %-5s %-22s %-14s %10s' % ('CRG', 'page', 'lot', 'période appelée',
                                                  'locataire', 'montant'))
    for a, _ in sorted(vraiment, key=lambda z: -mt(z[0]))[:8]:
        print('  %-16s %-6s %-5s %-22s %-14s %10.2f'
              % (a['crg_fichier'][:16], a.get('page'), a.get('lot'),
                 ('%s → %s' % (a.get('periode_appel_debut'), a.get('periode_appel_fin')))[:22],
                 str(a.get('locataire'))[:14], mt(a)))

    entete('4 — LE TOTAL P5A : ce qui changerait, et ce qui ne change pas')
    brut = sum(mt(a) for a in appels)
    doc_hors = sum(mt(a) for a in appels if a['crg_fichier'] in {p[0] for p in PAIRES})
    print('  total documentaire P5A tel que certifié          %16s' % euros(brut))
    print('     dont porté par les 2 pièces écartées par P7   %16s' % euros(doc_hors))
    print('     dont RÉÉNONCÉ (doublon avéré)                 %16s' % euros(sum(mt(a) for a in reenonces)))
    print('     dont NOUVEAU et absent du reste du corpus     %16s'
          % euros(sum(mt(a) for a, _ in vraiment)))
    print()
    print('  ⚠ LE TOTAL DOCUMENTAIRE P5A LES CONTIENT DÉJÀ TOUS : P5A lit chaque pièce de la')
    print('    GED sans neutraliser aucune réédition. Les 4 761 767,93 € — et donc les')
    print('    4 357 710,67 € d’appelé au locataire — comptent DEUX FOIS les événements')
    print('    réénoncés, et UNE FOIS les nouveaux.')
    print('  → correction à porter au total documentaire : %s' % euros(-sum(mt(a) for a in reenonces)))

    entete('5 — IMPACT SUR LES AUTRES PHASES')
    regles = [r for ag in AGENCES for r in P5B.reglements(ag)]
    enc = [o for ag in AGENCES for o in P6A.observations(ag)]
    for lib, lst, f in (('P5B — règlements', regles, lambda r: float(r['montant'] or 0)),
                        ('P6A — encours', enc, lambda o: float(o['montant_source'] or 0))):
        v = [x for x in lst if x['crg_fichier'] in {p[0] for p in PAIRES}]
        print('  %-22s lignes issues des 2 pièces : %4d · %14s'
              % (lib, len(v), euros(sum(f(x) for x in v))))
    n8 = [x for ag in AGENCES for x in P8A.candidats(ag)
          if x['crg_fichier'] in {p[0] for p in PAIRES}]
    print('  %-22s %s' % ('P8A — flux', 'rééditions déjà exclues ; %d ligne(s) résiduelle(s)' % len(n8)))
    print('  %-22s %s' % ('P7A / P7B', 'INCHANGÉES — charges identiques au centime dans les 2 pièces'))

    entete('VERDICT : %s' % ('RECTIFICATION P5A NÉCESSAIRE' if reenonces else 'P5A INCHANGÉE'))
    print('  réénoncés : %d appels · %s   → à retirer du total documentaire'
          % (len(reenonces), euros(sum(mt(a) for a in reenonces))))
    print('  nouveaux  : %d appels · %s   → à CONSERVER, ils n’existent nulle part ailleurs'
          % (len(vraiment), euros(sum(mt(a) for a, _ in vraiment))))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
