# -*- coding: utf-8 -*-
"""Le CRG imprime appele / regle / restant au LOT. Cette identite tient-elle ?"""
import collections, sys
sys.path.insert(0, r'c:\xampp\htdocs\MaBoxImmo2026\public_html\scripts\tests_crg')
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros
import p5a_certification as P5A
import p5b_certification as P5B
import p6a_certification as P6A

def m(a): return round(sum(float(c.get('montant') or 0) for c in a.get('composantes') or []), 2)
K = lambda x: (x['agence'], x['sha'], x['compte'], x['immeuble'], x['lot'], x['locataire'])

ap = collections.Counter(); re_ = collections.Counter(); res = {}
for ag in AGENCES:
    for a in P5A.appels(ag): ap[K(a)] += m(a)
    for r in P5B.reglements(ag): re_[K(r)] += float(r['montant'] or 0)
    for o in P6A.observations(ag):
        if o['qualification'] in ('débiteur / créance', 'solde nul démontré'):
            res[K(o)] = float(o['montant_source'] or 0)

tous = set(ap) | set(re_)
comm = [k for k in tous if k in res]
print('lots x CRG : appeles %d | regles %d | avec restant P6A %d | triplet complet %d'
      % (len(ap), len(re_), len(res), len(comm)))
egaux = [k for k in comm if abs(ap[k] - re_[k] - res[k]) < 0.01]
print('  appele - regle == restant :  %d / %d  (%.1f %%)'
      % (len(egaux), len(comm), 100.0*len(egaux)/len(comm) if comm else 0))
print()
print('== les ecarts : de quelle taille ? ==')
ec = sorted(((round(ap[k]-re_[k]-res[k],2), k) for k in comm), key=lambda z: -abs(z[0]))
print('  ecart total %s sur %d lots' % (euros(sum(e for e,_ in ec)), len(ec)))
for e, k in ec[:6]:
    print('   %+13.2f  appele %11.2f regle %11.2f restant %11.2f  %s'
          % (e, ap[k], re_[k], res[k], str((k[3],k[4],k[5]))[:44]))
print()
print('== par perimetre ==')
for agc in AGENCES:
    c2 = [k for k in comm if k[0]==agc]
    e2 = [k for k in c2 if abs(ap[k]-re_[k]-res[k]) < 0.01]
    print('  %-16s %5d triplets · identite tenue %5d (%5.1f %%)'
          % (agc, len(c2), len(e2), 100.0*len(e2)/len(c2) if c2 else 0))
