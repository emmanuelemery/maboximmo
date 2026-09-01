# -*- coding: utf-8 -*-
"""
AUDIT TRANSVERSAL — ÉTAPE 1 : LE RECENSEMENT EXHAUSTIF DU PIVOT.

⚠️ CONTENEUR = SOURCE, PAS CLASSIFICATION. Aucune structure technique ne correspond par principe
   à une phase métier. Avant de router quoi que ce soit, il faut savoir CE QUE LE PIVOT CONTIENT
   — toutes les clés, à tous les niveaux, sur les 458 CRG, sans en présumer l'usage.

⚠️ ET ON NE CHERCHE PAS PAR VOCABULAIRE. On part des structures, on les compte, puis on les
   classe. Une structure inconnue est signalée, jamais ignorée.

Lecture seule.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents   # noqa: E402

LARG = 118


def parcourir(o, chemin, listes, scalaires, prof=0):
    """Descend dans la structure et compte ce qu'on y trouve, sans rien présumer."""
    if prof > 6:
        return
    if isinstance(o, dict):
        for k, v in o.items():
            parcourir(v, chemin + '.' + str(k) if chemin else str(k),
                      listes, scalaires, prof + 1)
    elif isinstance(o, list):
        if o and isinstance(o[0], dict):
            listes[chemin] += len(o)
            cles = set()
            for e in o:
                if isinstance(e, dict):
                    cles |= set(e)
            listes_cles[chemin] |= cles
            for e in o[:60]:
                parcourir(e, chemin + '[]', listes, scalaires, prof + 1)
        elif o:
            listes[chemin + ' (valeurs)'] += len(o)
    elif o not in (None, '', 0, 0.0, False):
        scalaires[chemin] += 1


listes_cles = collections.defaultdict(set)


def main():
    listes = collections.Counter()
    scalaires = collections.Counter()
    par_format = collections.defaultdict(collections.Counter)
    ndocs = 0

    for ag in AGENCES:
        for d in documents(ag):
            ndocs += 1
            fm = d.get('_format')
            l2, s2 = collections.Counter(), collections.Counter()
            parcourir(d, '', l2, s2)
            listes.update(l2)
            scalaires.update(s2)
            for k, v in l2.items():
                par_format[fm][k] += v

    print('=' * LARG)
    print('RECENSEMENT DU PIVOT — %d documents' % ndocs)
    print('=' * LARG)
    print()
    print('LES COLLECTIONS D’ENREGISTREMENTS (ce qui porte des occurrences)')
    print('  %-46s %10s   %s' % ('CHEMIN', 'occurr.', 'formats'))
    for k, n in listes.most_common():
        if '[]' in k or '(valeurs)' in k:
            continue
        fmts = [f for f in par_format if par_format[f].get(k)]
        print('  %-46s %10d   %s' % (k, n, ', '.join(sorted(fmts))))

    print()
    print('LES CHAMPS DE CHAQUE COLLECTION')
    for k in sorted(listes_cles):
        if '[]' in k:
            continue
        print('  %-34s %s' % (k, ', '.join(sorted(listes_cles[k]))[:78]))

    print()
    print('LES SCALAIRES NON VIDES AU NIVEAU DOCUMENT (candidats stocks / contrôles)')
    for k, n in scalaires.most_common(40):
        if k.count('.') > 1:
            continue
        print('  %-52s %6d documents' % (k, n))


if __name__ == '__main__':
    main()
