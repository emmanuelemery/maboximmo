# -*- coding: utf-8 -*-
"""
P8B — CE QUE CHAQUE FORMAT IMPRIME COMME STRUCTURE COMPTABLE.

⚠️ ON NE PART PAS DES CONTENEURS. Les 58 stocks routés vers P8B sont des CANDIDATS, pas la
   définition de la comptabilité. On regarde d'abord ce que le CRG IMPRIME comme situation :
   un solde d'ouverture, des mouvements, des totaux, un solde de clôture — et on mesure
   ensuite quelles égalités il permet réellement de démontrer.

⚠️ ET AUCUNE FORMULE PRÉCONÇUE. `report + débits − crédits = solde final` est une hypothèse à
   VÉRIFIER format par format, pas un cadre à imposer. Si elle ne tient pas, c'est l'équation
   qui est fausse, pas le document.

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


def main():
    entete('CE QUE CHAQUE FORMAT IMPRIME — présence des éléments comptables')
    champs = collections.defaultdict(collections.Counter)
    total = collections.Counter()
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                continue
            fm = d.get('_format')
            total[fm] += 1
            m = d.get('mandat') or {}
            presents = {
                'report (ouverture)': (m.get('report') or {}).get('montant') is not None,
                'totaux débit/crédit': bool(m.get('totaux')),
                'solde final': (m.get('solde_final') or {}).get('montant') is not None,
                'soldes par immeuble': bool(m.get('soldes_immeubles')),
                'contrôle trésorerie': bool(d.get('controle_tresorerie')),
                'solde_compte (SEPTEO)': d.get('solde_compte') is not None,
                'solde reporté (SEPTEO)': d.get('solde_reporte') is not None,
                'solde final récap (SEPTEO)': d.get('solde_final_recap') is not None,
                'soldes de sections (SEPTEO)': any(im.get('soldes_sections')
                                                   for im in d.get('immeubles') or []),
                'solde immeuble (SEPTEO)': any(im.get('solde_immeuble') is not None
                                               for im in d.get('immeubles') or []),
            }
            for k, v in presents.items():
                if v:
                    champs[fm][k] += 1
    print('  %-30s %s' % ('ÉLÉMENT IMPRIMÉ',
                          ' '.join('%18s' % f for f in sorted(total))))
    for k in ('report (ouverture)', 'totaux débit/crédit', 'solde final',
              'soldes par immeuble', 'contrôle trésorerie', 'solde_compte (SEPTEO)',
              'solde reporté (SEPTEO)', 'solde final récap (SEPTEO)',
              'soldes de sections (SEPTEO)', 'solde immeuble (SEPTEO)'):
        print('  %-30s %s' % (k, ' '.join('%13d/%-4d' % (champs[f][k], total[f])
                                          for f in sorted(total))))

    # ── L'ÉQUATION DU FORMAT `lyon` ────────────────────────────────────────────────────────
    entete('ÉQUATION TESTÉE — `totaux.débit − totaux.crédit = solde final` (formats lyon/emery)')
    # ⚠️ HYPOTHÈSE À VÉRIFIER, PAS À IMPOSER. Le report figure-t-il DANS les totaux, ou
    #    s'ajoute-t-il ? On teste les deux lectures et on laisse le corpus trancher.
    res = collections.defaultdict(lambda: {'ok': 0, 'ko': 0, 'incomplet': 0, 'ecarts': []})
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                continue
            m = d.get('mandat') or {}
            t, sf = m.get('totaux') or {}, m.get('solde_final') or {}
            deb, cre, fin = n(t.get('debit')), n(t.get('credit')), n(sf.get('montant'))
            if deb is None or cre is None or fin is None:
                res[ag]['incomplet'] += 1
                continue
            sens = str(sf.get('sens') or '')
            attendu = deb - cre if sens == 'debiteur' else cre - deb
            if abs(attendu - fin) < 0.005:
                res[ag]['ok'] += 1
            else:
                res[ag]['ko'] += 1
                res[ag]['ecarts'].append((d.get('_nom_original'), sens, deb, cre, fin,
                                          round(attendu - fin, 2)))
    print('  %-16s %8s %8s %10s   %s' % ('PÉRIMÈTRE', 'vérifiée', 'écart', 'incomplet',
                                         'plus gros écarts'))
    for ag in AGENCES:
        r = res[ag]
        gros = sorted(r['ecarts'], key=lambda x: -abs(x[5]))[:1]
        note = ('%s sur %s' % (euros(gros[0][5]), str(gros[0][0])[:26])) if gros else ''
        print('  %-16s %8d %8d %10d   %s' % (ag, r['ok'], r['ko'], r['incomplet'], note))

    entete('LES ÉCARTS, EN DÉTAIL')
    for ag in AGENCES:
        for e in sorted(res[ag]['ecarts'], key=lambda x: -abs(x[5]))[:6]:
            print('  %-14s %-32s sens=%-10s D %13s  C %13s  solde %13s  écart %12s'
                  % (ag[:14], str(e[0])[:32], e[1], euros(e[2]), euros(e[3]), euros(e[4]),
                     euros(e[5])))

    # ── LE REPORT EST-IL DANS LES TOTAUX ? ────────────────────────────────────────────────
    entete('LE REPORT D’OUVERTURE EST-IL COMPRIS DANS LES TOTAUX IMPRIMÉS ?')
    dans, hors, sans = 0, 0, 0
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                continue
            m = d.get('mandat') or {}
            r, t = m.get('report') or {}, m.get('totaux') or {}
            rep, deb, cre = n(r.get('montant')), n(t.get('debit')), n(t.get('credit'))
            if rep is None or deb is None:
                sans += 1
                continue
            # somme des mouvements imprimés, report exclu
            mv = sum(float(o.get('debit') or 0)
                     for o in m.get('operations') or [])
            mv += sum(float(c.get('debit') or 0)
                      for im in d.get('immeubles') or [] for c in im.get('charges') or [])
            if abs(deb - mv - abs(rep)) < 0.02:
                dans += 1
            else:
                hors += 1
    print('  totaux INCLUANT le report      : %d CRG' % dans)
    print('  totaux ne l’incluant pas       : %d CRG' % hors)
    print('  report ou totaux non imprimés  : %d CRG' % sans)


if __name__ == '__main__':
    main()
