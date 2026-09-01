# -*- coding: utf-8 -*-
"""
CHOIX DU COMPTE MANDANT À FAIRE LIRE PAR EMMANUEL.

⚠️ ON NE CHOISIT PAS « UN COMPTE AU HASARD ». On cherche celui où COEXISTENT le plus de familles
   qui nous posent problème — un compte riche vaut mieux que trois comptes pauvres, parce que
   c'est la GRAMMAIRE du compte qu'Emmanuel doit pouvoir expliquer, pas des lignes isolées.

Lecture seule. Aucune classification, aucune déduction.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import documents, euros   # noqa: E402

FAMILLES = [
    ('ACOMPTE PRO',            r"acomptes?\s+pro"),
    ('VIRT / VRT',             r"\bvirt\b|\bvrt\b|\bvir\b|virement"),
    ('DIRECT IMPOTS / ATD',    r"direct\s+(aux\s+)?imp[oô]ts|\batd\b"),
    ('paiement direct prop.',  r"direct\s+pro(pri[ée]taire)?\b|pay[ée]e?\s+par\s+vous"),
    ('fournisseur / dépense',  r"fournisseurs?\s+divers|facture|\bfact\b|engie|\bsip\b|"
                               r"imp[oô]t|taxe|honoraires?|travaux|assuranc"),
    ('remboursement de prêt',  r"\bpr[êe]ts?\b|emprunt"),
    ('salaire',                r"\bsalaires?\b|\bpaie\b"),
    ('compensation',           r"compensation|compensat|extourne"),
    ('solde mandat',           r"soldes?\s+mandats?|solde\s+d[ée]biteur|^\s*solde\s+au"),
    ('reversement propr.',     r"^\s*r[èe]glement\s+(virement|ch[èe]que|cheque)|"
                               r"loyers?\s+vers[ée]s?\s+au\s+propri"),
    ('apport propriétaire',    r"\bvotre\b"),
]
FAMILLES = [(n, re.compile(p, re.I)) for n, p in FAMILLES]


def main():
    # ⚠️ UN COMPTE N'EST PAS UN DOCUMENT. Le compte mandant vit sur plusieurs CRG successifs :
    #    c'est SA grammaire qu'Emmanuel doit pouvoir expliquer, et elle ne se voit entière
    #    qu'en réunissant ses périodes.
    par_compte = collections.defaultdict(lambda: {'ops': 0, 'fam': set(), 'crg': [], 'p': set()})
    for d in documents('GROUPE SIR'):
        ops = (d.get('mandat') or {}).get('operations') or []
        if not ops:
            continue
        meta = d.get('meta') or {}
        c = str(meta.get('compte') or '')
        e = par_compte[c]
        e['ops'] += len(ops)
        e['crg'].append((meta.get('periode_cle'), d.get('_nom_original')))
        e['p'].add(meta.get('periode_cle'))
        for o in ops:
            t = '%s %s' % (o.get('entete') or '', o.get('libelle') or '')
            for n, mo in FAMILLES:
                if mo.search(t):
                    e['fam'].add(n)

    scores = sorted(par_compte.items(), key=lambda kv: (-len(kv[1]['fam']), -kv[1]['ops']))
    print('=' * 100)
    print('LES COMPTES MANDANT GROUPE SIR, TOUTES PÉRIODES RÉUNIES')
    print('=' * 100)
    print('  %-10s %-5s %-7s %-5s  %s' % ('compte', 'fam.', 'lignes', 'CRG', 'familles présentes'))
    for c, e in scores[:8]:
        print('  %-10s %-5d %-7d %-5d  %s'
              % (c, len(e['fam']), e['ops'], len(e['crg']), ', '.join(sorted(e['fam']))))
    print()
    c, e = scores[0]
    toutes = {n for n, _ in FAMILLES}
    print('  → RETENU : compte %s  ·  %d opérations  ·  %d CRG  ·  périodes %s'
          % (c, e['ops'], len(e['crg']), ', '.join(sorted(str(x) for x in e['p']))))
    print('    familles manquantes : %s' % (', '.join(sorted(toutes - e['fam'])) or 'AUCUNE'))
    print('    les CRG de ce compte, dans l’ordre :')
    for per, nom in sorted(e['crg'], key=lambda x: str(x[0])):
        print('       %-9s %s' % (per, nom))


if __name__ == '__main__':
    main()
