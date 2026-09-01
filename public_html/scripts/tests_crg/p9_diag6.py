# -*- coding: utf-8 -*-
import collections, sys
sys.path.insert(0, r'c:\xampp\htdocs\MaBoxImmo2026\public_html\scripts\tests_crg')
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros
import p5a_certification as P5A
import p5b_certification as P5B
import p7_socle as P7

def m(a): return round(sum(float(c.get('montant') or 0) for c in a.get('composantes') or []), 2)
I = lambda x: (x['agence'], x['sha'], x['compte'], x['immeuble'])

rev = collections.Counter(); ch = collections.Counter(); chc = collections.Counter()
for ag in AGENCES:
    for a in P5A.appels(ag): rev[I(a)] += m(a)
    for x in P7.lignes(ag):
        if x['cote'] not in ('P7A','P7B'): continue
        if x['maille_documentaire'] == 'compte mandant':
            chc[(x['agence'], x['sha'], x['compte'])] += x['montant_source']
        else:
            ch[I(x)] += x['montant_source']
print('== jointure IMMEUBLE x CRG ==')
print('   immeubles avec revenu %d | avec charge %d | LES DEUX %d'
      % (len(rev), len(ch), len(set(rev) & set(ch))))
print('   revenu sans charge %d | charge sans revenu %d'
      % (len(set(rev)-set(ch)), len(set(ch)-set(rev))))
print('   charges restees au COMPTE : %d comptes x CRG · %s'
      % (len(chc), euros(sum(chc.values()))))
com = set(rev) & set(ch)
print()
print('== les 6 immeubles ou l ecart revenu-charge est le plus fort ==')
for k in sorted(com, key=lambda k: -(rev[k]-ch[k]))[:3]:
    print('   %-40s revenu %12.2f  charges %11.2f  net %+12.2f'
          % (str((k[2],k[3]))[:40], rev[k], ch[k], rev[k]-ch[k]))
for k in sorted(com, key=lambda k: (rev[k]-ch[k]))[:3]:
    print('   %-40s revenu %12.2f  charges %11.2f  net %+12.2f'
          % (str((k[2],k[3]))[:40], rev[k], ch[k], rev[k]-ch[k]))
neg = [k for k in com if rev[k]-ch[k] < 0]
print('   immeubles a net NEGATIF : %d / %d' % (len(neg), len(com)))
