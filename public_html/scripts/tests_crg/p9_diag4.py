# -*- coding: utf-8 -*-
import collections, sys
sys.path.insert(0, r'c:\xampp\htdocs\MaBoxImmo2026\public_html\scripts\tests_crg')
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros
import p5a_certification as P5A
import p5b_certification as P5B
import p6a_certification as P6A

def m(a): return round(sum(float(c.get('montant') or 0) for c in a.get('composantes') or []), 2)
ap = [a for ag in AGENCES for a in P5A.appels(ag)]
re_= [r for ag in AGENCES for r in P5B.reglements(ag)]
en = [o for ag in AGENCES for o in P6A.observations(ag)]

print('== A. identite du lecteur : detail + reste == total du lot ? ==')
print('   (par construction : reste = total_regle - somme des lignes ; rien ne se compte 2x)')
print()
print('== B. le lot DEPARTEMENT DU : le << reste >> solde-t-il un arriere ? ==')
k = ('01040185', '0188')
for lst, lib, f in ((ap,'appels',m), (re_,'reglements',lambda x: float(x['montant'] or 0))):
    v = [x for x in lst if (x['immeuble'], x['lot']) == k and x['periode_crg']=='2025-T1']
    for x in v[:3] if lib=='appels' else v:
        print('   %-11s %-52s %12.2f' % (lib, str(x.get('libelle_source') or x.get('bloc'))[:52], f(x)))
    if lib=='appels': print('   ... %d appels, total %.2f' % (len(v), sum(f(x) for x in v)))
v6 = [o for o in en if (o['immeuble'], o['lot']) == k]
for o in v6[:6]:
    print('   ENCOURS    %-22s %-24s %12s' % (o['date_arrete'], str(o['qualification'])[:24],
                                              o['montant_source']))

print()
print('== C. le taux, restreint a ce qui est AFFECTE A UNE PERIODE des deux cotes ==')
aff = [r for r in re_ if r['affectation'] == 'démontrée']
ind = [r for r in re_ if r['affectation'] != 'démontrée']
print('   reglements affectes a une periode : %5d  %16s' % (len(aff), euros(sum(float(r['montant'] or 0) for r in aff))))
print('   affectes au LOT seulement         : %5d  %16s' % (len(ind), euros(sum(float(r['montant'] or 0) for r in ind))))
print()
for ag in AGENCES:
    a2 = [x for x in ap if x['agence']==ag]
    r2 = [x for x in aff if x['agence']==ag]
    ma = sum(m(x) for x in a2); mr = sum(float(x['montant'] or 0) for x in r2)
    print('   %-16s appele %14s | regle-affecte %14s | %6.1f %%'
          % (ag, euros(ma), euros(mr), 100*mr/ma if ma else 0))
