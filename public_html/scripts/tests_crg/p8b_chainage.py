# -*- coding: utf-8 -*-
"""
P8B — LE CHAÎNAGE DES SITUATIONS : le solde de clôture d'un CRG devient-il le report du suivant ?

⚠️ C'EST L'ÉQUATION LA PLUS FORTE QUE LE DOCUMENT PUISSE OFFRIR. Si elle tient, la suite des CRG
   d'un compte forme une chaîne comptable continue, et le solde de chaque arrêté est démontré
   par le précédent. Si elle ne tient pas, chaque CRG est une photographie isolée — et il ne
   faut surtout pas la reconstituer de force.

⚠️ ON NE TESTE QUE CE QUE LE DOCUMENT IMPRIME. Un CRG sans report imprimé ne peut ni confirmer
   ni infirmer la chaîne : il est compté à part, jamais rempli par déduction.

Lecture seule.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402

LARG = 112


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def n(v):
    try:
        return round(float(v), 2)
    except (TypeError, ValueError):
        return None


def signe(montant, sens):
    """Le solde ramené à une convention unique : positif = débiteur (le propriétaire doit)."""
    if montant is None:
        return None
    return abs(montant) if str(sens or '') == 'debiteur' else -abs(montant)


def main():
    # ── LA PLACE DU REPORT DANS LES TOTAUX ────────────────────────────────────────────────
    entete('OÙ LE REPORT SE TROUVE-T-IL ? (un cas lu en entier)')
    for ag in ('GROUPE SIR',):
        for d in documents(ag):
            m = d.get('meta') or {}
            if str(m.get('compte') or '') != '01040000' or m.get('periode_cle') != '2025-T1':
                continue
            mdt = d.get('mandat') or {}
            r, t, sf = mdt.get('report') or {}, mdt.get('totaux') or {}, mdt.get('solde_final') or {}
            deb_ops = sum(float(o.get('debit') or 0) for o in mdt.get('operations') or [])
            cre_ops = sum(float(o.get('credit') or 0) for o in mdt.get('operations') or [])
            deb_ch = sum(float(c.get('debit') or 0)
                         for im in d.get('immeubles') or [] for c in im.get('charges') or [])
            cre_ch = sum(float(c.get('credit') or 0)
                         for im in d.get('immeubles') or [] for c in im.get('charges') or [])
            print('  %s · %s' % (d.get('_nom_original'), m.get('periode_cle')))
            print('     report imprimé          %14s (%s)' % (euros(r.get('montant')),
                                                              r.get('sens')))
            print('     opérations de mandat    D %12s   C %12s' % (euros(deb_ops), euros(cre_ops)))
            print('     charges des immeubles   D %12s   C %12s' % (euros(deb_ch), euros(cre_ch)))
            print('     somme                   D %12s   C %12s'
                  % (euros(deb_ops + deb_ch), euros(cre_ops + cre_ch)))
            print('     totaux imprimés         D %12s   C %12s'
                  % (euros(t.get('debit')), euros(t.get('credit'))))
            print('     écart D                 %14s'
                  % euros(n(t.get('debit')) - deb_ops - deb_ch))
            print('     solde final imprimé     %14s (%s)' % (euros(sf.get('montant')),
                                                              sf.get('sens')))
            break

    # ── LE CHAÎNAGE ───────────────────────────────────────────────────────────────────────
    entete('LE SOLDE DE CLÔTURE D’UN CRG EST-IL LE REPORT DU SUIVANT ?')
    par_compte = collections.defaultdict(list)
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                continue
            m = d.get('meta') or {}
            c = str(m.get('compte') or '')
            if not c:
                continue
            mdt = d.get('mandat') or {}
            par_compte[(ag, c)].append({
                'periode': str(m.get('periode_cle') or ''),
                'arrete': str(m.get('date_arrete') or ''),
                'crg': d.get('_nom_original'),
                'report': signe(n((mdt.get('report') or {}).get('montant')),
                                (mdt.get('report') or {}).get('sens')),
                'final': signe(n((mdt.get('solde_final') or {}).get('montant')),
                               (mdt.get('solde_final') or {}).get('sens')),
            })

    verif = collections.Counter()
    ecarts = []
    for (ag, c), lst in par_compte.items():
        lst.sort(key=lambda x: x['periode'])
        for a, b in zip(lst, lst[1:]):
            if a['final'] is None or b['report'] is None:
                verif[(ag, 'report non imprimé')] += 1
                continue
            if abs(a['final'] - b['report']) < 0.005:
                verif[(ag, 'chaînage vérifié')] += 1
            else:
                verif[(ag, 'ÉCART')] += 1
                ecarts.append((ag, c, a['periode'], b['periode'], a['final'], b['report'],
                               round(b['report'] - a['final'], 2), b['crg']))

    print('  %-16s %18s %20s %18s' % ('PÉRIMÈTRE', 'chaînage vérifié', 'report non imprimé',
                                      'écart'))
    for ag in AGENCES:
        print('  %-16s %18d %20d %18d'
              % (ag, verif[(ag, 'chaînage vérifié')], verif[(ag, 'report non imprimé')],
                 verif[(ag, 'ÉCART')]))
    print('  %-16s %18d %20d %18d'
          % ('TOTAL', sum(v for k, v in verif.items() if k[1] == 'chaînage vérifié'),
             sum(v for k, v in verif.items() if k[1] == 'report non imprimé'),
             sum(v for k, v in verif.items() if k[1] == 'ÉCART')))

    entete('LES ÉCARTS DE CHAÎNAGE — %d' % len(ecarts))
    print('  %-13s %-10s %-9s %-9s %14s %14s %13s' %
          ('PÉRIMÈTRE', 'compte', 'de', 'à', 'clôture', 'report suivant', 'écart'))
    for e in sorted(ecarts, key=lambda x: -abs(x[6]))[:15]:
        print('  %-13s %-10s %-9s %-9s %14s %14s %13s'
              % (e[0][:13], e[1], e[2], e[3], euros(e[4]), euros(e[5]), euros(e[6])))

    # ── SEPTEO : QUELLE STRUCTURE ? ───────────────────────────────────────────────────────
    entete('SEPTEO — quelle situation comptable le document imprime-t-il ?')
    src = collections.Counter()
    ecarts_v = []
    for d in documents('VIENNE'):
        src[str(d.get('source_solde'))] += 1
        sc = n(d.get('solde_compte'))
        sfr = n(d.get('solde_final_recap'))
        if sc is not None and sfr is not None and abs(sc - sfr) >= 0.005:
            ecarts_v.append((d.get('_nom_original'), sc, sfr, round(sc - sfr, 2)))
    print('  provenance déclarée du solde :')
    for k, v in src.most_common():
        print('     %-46s %3d / 70' % (k, v))
    print('  solde_compte ≠ solde_final_recap : %d cas' % len(ecarts_v))
    for e in ecarts_v[:5]:
        print('     %-34s %12s vs %12s  écart %10s'
              % (str(e[0])[:34], euros(e[1]), euros(e[2]), euros(e[3])))


if __name__ == '__main__':
    main()
