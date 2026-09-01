# -*- coding: utf-8 -*-
"""
DEUX PIÈCES, UNE SITUATION ? — audit structurel des CRG partageant compte et date d'arrêté.

⚠️ PIÈCE DOCUMENTAIRE DISTINCTE ≠ SITUATION MÉTIER DISTINCTE ≠ ÉCRITURE MÉTIER DISTINCTE.
   Deux SHA sont deux pièces GED, et le corpus certifié par P1 reste intact. Mais si les deux
   énoncent la MÊME situation au MÊME arrêté, leurs écritures communes ne font pas deux
   événements métier.

⚠️ ET 83 % DE CHARGES COMMUNES NE SUFFISENT PAS À DÉCLARER UN DOUBLON. Une version enrichie ou
   corrigée ressemble beaucoup à celle qu'elle remplace. On compare donc TOUT le document —
   propriétaire, immeubles, lots, locataires, appels, charges, opérations de mandat, soldes,
   pagination — et on distingue ce qui est commun, ce qui n'est que dans l'une, et ce qui est
   le MÊME MONTANT sous une rubrique différente.

Lecture seule. Aucun PDF supprimé, aucun corpus modifié, aucune phase touchée.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402

LARG = 116


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def dims(d):
    """Les dimensions comparables du document, chacune sous forme de multi-ensemble."""
    im, lots, ch, mv, ops = [], [], [], [], []
    for i in d.get('immeubles') or []:
        im.append((str(i.get('code') or ''), str(i.get('nom') or '')))
        for l in i.get('lots') or []:
            lots.append((str(l.get('numero_lot') or ''), str(l.get('locataire_nom') or ''),
                         round(float(l.get('loyer_appele') or 0), 2),
                         round(float(l.get('total_du') or 0), 2)))
        for c in i.get('charges') or []:
            ch.append((str(c.get('entete') or ''), str(c.get('libelle') or ''),
                       round(float(c.get('debit') or 0), 2),
                       round(float(c.get('credit') or 0), 2)))
        for m in i.get('mouvements') or []:
            mv.append((str(m.get('section') or ''), str(m.get('libelle') or ''),
                       round(float(m.get('debit') or 0), 2),
                       round(float(m.get('credit') or 0), 2)))
    for o in (d.get('mandat') or {}).get('operations') or []:
        ops.append((str(o.get('entete') or ''), str(o.get('libelle') or ''),
                    round(float(o.get('debit') or 0), 2),
                    round(float(o.get('credit') or 0), 2)))
    return {'immeubles': im, 'lots': lots, 'charges': ch, 'mouvements': mv,
            'opérations mandat': ops}


def cadre(d):
    m = d.get('meta') or {}
    mdt = d.get('mandat') or {}
    return {'propriétaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
            'compte': str(m.get('compte') or ''), 'arrêté': str(m.get('date_arrete') or ''),
            'période': str(m.get('periode_cle') or ''), 'pages': d.get('_pages'),
            'report': (mdt.get('report') or {}).get('montant'),
            'solde final': (mdt.get('solde_final') or {}).get('montant'),
            'totaux': mdt.get('totaux')}


def comparer(a, b, na, nb):
    entete('%s   ⟷   %s' % (na, nb))
    ca, cb = cadre(a), cadre(b)
    print('  %-16s %-44s %-44s' % ('CADRE', na[:44], nb[:44]))
    for k in ca:
        va, vb = str(ca[k])[:44], str(cb[k])[:44]
        print('  %-16s %-44s %-44s %s' % (k, va, vb, '' if va == vb else '  ← DIFFÈRE'))

    da, db = dims(a), dims(b)
    print()
    print('  %-20s %8s %8s %10s %10s %10s'
          % ('DIMENSION', 'v1', 'v2', 'communes', 'v1 seule', 'v2 seule'))
    verdicts = {}
    for k in da:
        A, B = collections.Counter(da[k]), collections.Counter(db[k])
        comm = sum((A & B).values())
        sa, sb = sum((A - B).values()), sum((B - A).values())
        if not da[k] and not db[k]:
            continue
        print('  %-20s %8d %8d %10d %10d %10d' % (k, len(da[k]), len(db[k]), comm, sa, sb))
        verdicts[k] = (len(da[k]), len(db[k]), comm, sa, sb)

    # ⚠️ MÊME MONTANT, RUBRIQUE DIFFÉRENTE : ni « commun » ni « nouveau ». C'est la même
    #    écriture que le lecteur a rattachée autrement — la trace d'une RÉÉDITION, pas d'un
    #    complément.
    for k in ('charges', 'opérations mandat'):
        A, B = collections.Counter(da[k]), collections.Counter(db[k])
        ra = collections.Counter((x[1], x[2], x[3]) for x in (A - B).elements())
        rb = collections.Counter((x[1], x[2], x[3]) for x in (B - A).elements())
        meme = sum((ra & rb).values())
        if meme:
            print()
            print('  %s : %d écriture(s) au MÊME libellé et MÊME montant, sous une rubrique'
                  ' différente' % (k, meme))
            for x in list((ra & rb).elements())[:4]:
                print('       « %-44s » %10s' % (str(x[0])[:44], euros(x[1] or x[2])))
        # montants strictement propres à l'une des versions
        pa = collections.Counter((x[1], x[2], x[3]) for x in (A - B).elements()) - rb
        pb = collections.Counter((x[1], x[2], x[3]) for x in (B - A).elements()) - ra
        if pa or pb:
            print()
            print('  %s : écritures RÉELLEMENT propres à une version' % k)
            for nom, p in ((na, pa), (nb, pb)):
                if p:
                    print('     seulement dans %s — %d ligne(s), %s'
                          % (nom[:32], sum(p.values()),
                             euros(sum(x[1] or x[2] for x in p.elements()))))
                    for x in list(p.elements())[:5]:
                        print('        « %-44s » %10s' % (str(x[0])[:44], euros(x[1] or x[2])))
    return verdicts


def main():
    idx = {}
    for ag in AGENCES:
        for d in documents(ag):
            idx[str(d.get('_nom_original'))] = d

    for na, nb in (('SABY 0107.pdf', 'SABY 0107-2.pdf'),
                   ('FOCH.pdf', 'FOCH 0108.pdf'),
                   ('CRG AVRIL 2026_391623.pdf', 'CRG AVRIL 2026_391624.pdf')):
        if na not in idx or nb not in idx:
            print('  %s / %s : absent du corpus' % (na, nb))
            continue
        comparer(idx[na], idx[nb], na, nb)
        print()


if __name__ == '__main__':
    main()
