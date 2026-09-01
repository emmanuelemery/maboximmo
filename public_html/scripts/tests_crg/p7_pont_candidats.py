# -*- coding: utf-8 -*-
"""
LE PONT ENTRE LES DEUX COMPTAGES DE CANDIDATS P7A / P7B.

⚠️ DEUX CHIFFRES, DEUX DÉTECTEURS — ET C'EST LÀ LE VRAI DÉFAUT. Le premier comptage détectait
   les candidates avec `P7.nature()`, le vocabulaire CERTIFIÉ de P7 ; le second avec le
   vocabulaire de qualification du routage exhaustif. Deux vocabulaires, deux réponses. Avant
   d'arbitrer un montant, il faut donc rendre le passage de l'un à l'autre lisible ligne par
   ligne — et dire ce qui vient du changement de détecteur, ce qui vient de la neutralisation
   des rééditions, et ce qui vient des qualifications ajoutées par arbitrage.

    ANCIEN  = P7.nature() → cote P7A/P7B, hors paiements et hors non-charges
              + les 10 impôts que le vocabulaire P7 ne reconnaît pas
              + les 7 honoraires (VANEL, FACTURE CHIFFRES CONSEILS)
    NOUVEAU = routage exhaustif → destination CHARGE → P7A / P7B
              − les rééditions SABY 0107-2 / FOCH 0108
              + les 5 POUDEROUX et les 4 prestations régie qualifiés par arbitrage

Lecture seule. Aucune phase modifiée.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as S   # noqa: E402
import p7_routage_exhaustif as R   # noqa: E402

LARG = 124
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')
REEDITIONS = R.REEDITIONS

PAIEMENT = R.PAIEMENT
PAS_UNE_CHARGE = R.PAS_UNE_CHARGE
IMPOT_MANQUE = re.compile(r"\bsip\b|autres\s+imp[oô]ts|droits?\s+de\s+mutation", re.I)
HONO_MANQUE = re.compile(r"vanel|chiffres?\s*&?\s*conseils?", re.I)
POUD = re.compile(r"pouderoux|proc[ée]dure|r[ée]vocation", re.I)
RG = re.compile(r"^\s*r[ée]gie\s+emery\s+loca[- ]immo", re.I)


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def cle(x):
    return (x['crg'], x['page'], x['entete'], x['libelle'],
            round(x['debit'] or x['credit'], 2))


def ancien():
    """Le comptage précédent, reconstitué à l'identique."""
    a, b = [], []
    for x in R.operations():
        e, l = x['entete'], x['libelle']
        if PAIEMENT.search(l) or PAS_UNE_CHARGE.search(l) or PAS_UNE_CHARGE.search(e):
            continue
        _, cote = P7.nature(e, l)
        fam, _sens, _o = S.famille(e, l)
        regie = fam.startswith('PRESTATION FACTURÉE PAR LA RÉGIE')
        if cote == 'P7B' or regie:
            b.append(x)
        elif cote == 'P7A':
            a.append(x)
        elif IMPOT_MANQUE.search(e + ' ' + l):
            a.append(x)
        elif HONO_MANQUE.search(e + ' ' + l):
            b.append(x)
    return a, b


def nouveau():
    """Le comptage du routage exhaustif, rééditions neutralisées."""
    a, b = [], []
    for x in R.operations():
        d, _s = R.destination(x)
        t = x['entete'] + ' ' + x['libelle']
        if d == 'CHARGE → P7A':
            cible = a
        elif d == 'CHARGE → P7B':
            cible = b
        elif d == 'NON RECONNU — À QUALIFIER' and POUD.search(t):
            cible = a
        elif d == 'NON RECONNU — À QUALIFIER' and RG.search(x['libelle']):
            cible = b
        else:
            continue
        if x['crg'] in REEDITIONS:
            continue
        cible.append(x)
    return a, b


def pont(av, ap, nom):
    A = {cle(x): x for x in av}
    B = {cle(x): x for x in ap}
    sortis = [A[k] for k in A if k not in B]
    entres = [B[k] for k in B if k not in A]
    entete('%s — %d lignes / %s   →   %d lignes / %s'
           % (nom, len(av), euros(sum(x['debit'] or x['credit'] for x in av)),
              len(ap), euros(sum(x['debit'] or x['credit'] for x in ap))))
    for titre, lot in (('SORTIES', sortis), ('ENTRÉES', entres)):
        print('  %s : %d lignes, %s'
              % (titre, len(lot), euros(sum(x['debit'] or x['credit'] for x in lot))))
        par = collections.defaultdict(list)
        for x in lot:
            if x['crg'] in REEDITIONS:
                motif = 'réédition neutralisée (%s)' % x['crg']
            else:
                d, _ = R.destination(x)
                motif = d
            par[motif].append(x)
        for m, v in sorted(par.items(), key=lambda kv: -sum(y['debit'] or y['credit']
                                                           for y in kv[1])):
            print('     %-58s %3d %14s'
                  % (m[:58], len(v), euros(sum(y['debit'] or y['credit'] for y in v))))
            for y in sorted(v, key=lambda z: -(z['debit'] or z['credit']))[:4]:
                print('        %-9s %10s [%-22s] %s'
                      % (y['compte'], euros(y['debit'] or y['credit']),
                         y['entete'][:22], y['libelle'][:42]))
        print()
    d = (sum(x['debit'] or x['credit'] for x in ap)
         - sum(x['debit'] or x['credit'] for x in av))
    e = (sum(x['debit'] or x['credit'] for x in entres)
         - sum(x['debit'] or x['credit'] for x in sortis))
    print('  RÉCONCILIATION : écart net %s   =   entrées − sorties %s   → %s'
          % (euros(d), euros(e), 'EXACTE' if abs(d - e) < 0.005 else 'ÉCHEC'))


def main():
    aa, ab = ancien()
    na, nb = nouveau()
    pont(aa, na, 'P7A')
    print()
    pont(ab, nb, 'P7B')


if __name__ == '__main__':
    main()
