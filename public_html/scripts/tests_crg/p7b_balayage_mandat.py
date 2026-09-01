# -*- coding: utf-8 -*-
"""
BALAYAGE EXHAUSTIF DE `mandat.operations` — quelles familles P7B s'y cachent encore ?

⚠️ ON A DÉCOUVERT LES SALAIRES PUIS LES HONORAIRES PARCE QUE LEURS MOTS NOUS ONT ATTIRÉS. Ce
   n'est pas une méthode. On applique donc à TOUTES les lignes du conteneur le classificateur
   CERTIFIÉ de P7 — `P7.nature()` — et on regarde ce qu'il en dit, sans partir d'un mot choisi
   d'avance.

⚠️ ET UN FRAIS SUPPORTÉ N'EST PAS SON PAIEMENT. `P7A-DEPENSE-02` est certifiée : une ligne
   portant un moyen de paiement, un bénéficiaire bancaire ou une référence d'avis décrit le
   RÈGLEMENT d'une charge, pas la charge. Ne pas faire cette distinction transformerait chaque
   virement en dépense supplémentaire — le double comptage que la rectification d'unicité vient
   précisément de combattre.

Lecture seule. P7B n'est pas modifiée, P8A n'est pas reprise.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402

LARG = 126
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')

# ⚠️ CE QUI NOMME UN RÈGLEMENT — mêmes marques que la passe de frontière P7A, inchangées.
PAIEMENT = re.compile(r"\bvirt\b|\bvrt\b|\bvir\b|virement|pr[ée]l[èe]v|prelvt|r[èe]glement|"
                      r"ch[èe]que|cheque|\bavis\s+n|fournisseurs?\s+divers|"
                      r"\bau\s+\d{1,2}[./]\d{1,2}[./]\d{2,4}", re.I)


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def operations():
    for ag in LYON:
        for d in documents(ag):
            m = d.get('meta') or {}
            for o in (d.get('mandat') or {}).get('operations') or []:
                e, l = str(o.get('entete') or ''), str(o.get('libelle') or '')
                nat, cote = P7.nature(e, l)
                yield {'agence': ag, 'compte': str(m.get('compte') or ''),
                       'periode': m.get('periode_cle'), 'crg': d.get('_nom_original'),
                       'page': o.get('page'), 'entete': e, 'libelle': l,
                       'debit': round(float(o.get('debit') or 0), 2),
                       'credit': round(float(o.get('credit') or 0), 2),
                       'nature': nat, 'cote': cote,
                       'paiement': bool(PAIEMENT.search(l))}


def main():
    tout = list(operations())

    entete('CE QUE LE CLASSIFICATEUR CERTIFIÉ DE P7 DIT DE TOUT LE CONTENEUR')
    print('  %d opérations de mandat balayées, sur les 458 CRG.' % len(tout))
    print()
    print('  %-14s %8s %16s   %s' % ('CÔTÉ P7', 'lignes', 'montant', 'dont portant une marque de règlement'))
    for c in ('P7A', 'P7B', 'dg', 'hors', 'a_qualifier'):
        v = [x for x in tout if x['cote'] == c]
        if not v:
            continue
        p = sum(1 for x in v if x['paiement'])
        print('  %-14s %8d %16s   %d / %d  (%.0f %%)'
              % (c, len(v), euros(sum(x['debit'] or x['credit'] for x in v)), p, len(v),
                 100.0 * p / len(v)))

    p7b = [x for x in tout if x['cote'] == 'P7B']
    entete('TOUTES LES LIGNES QUE P7 RECONNAÎT COMME UNE FAMILLE P7B — %d' % len(p7b))
    print('  %-9s %-9s %10s %-24s %-40s %-5s %s'
          % ('compte', 'période', 'montant', 'RUBRIQUE', 'LIBELLÉ', 'règl.', 'nature P7B lue'))
    for x in sorted(p7b, key=lambda y: (y['nature'], y['compte'], str(y['periode']))):
        print('  %-9s %-9s %10s %-24s %-40s %-5s %s'
              % (x['compte'], x['periode'], euros(x['debit'] or x['credit']),
                 x['entete'][:24], x['libelle'][:40],
                 'OUI' if x['paiement'] else 'non', x['nature'][:34]))

    entete('LES FAMILLES P7B RENCONTRÉES, ET LEUR PART DE RÈGLEMENTS')
    for n, v in sorted(collections.Counter().__class__(
            {k: [y for y in p7b if y['nature'] == k] for k in {y['nature'] for y in p7b}}).items(),
            key=lambda kv: -sum(z['debit'] or z['credit'] for z in kv[1])):
        charges = [z for z in v if not z['paiement']]
        print('  %-46s %3d lignes %13s   dont %d CHARGES (%s)'
              % (n[:46], len(v), euros(sum(z['debit'] or z['credit'] for z in v)),
                 len(charges), euros(sum(z['debit'] or z['credit'] for z in charges))))

    entete('VERDICT — Y A-T-IL D’AUTRES OCCURRENCES P7B QUE LES 10 ?')
    charges = [x for x in p7b if not x['paiement']]
    print('  lignes de famille P7B dans le conteneur          : %3d   %s'
          % (len(p7b), euros(sum(x['debit'] or x['credit'] for x in p7b))))
    print('  dont portant une marque de RÈGLEMENT (hors P7B)  : %3d   %s'
          % (len(p7b) - len(charges),
             euros(sum(x['debit'] or x['credit'] for x in p7b if x['paiement']))))
    print('  dont CHARGES sans marque de règlement            : %3d   %s'
          % (len(charges), euros(sum(x['debit'] or x['credit'] for x in charges))))
    print()
    rg = re.compile(r"^\s*r[ée]gie\s+emery\s+loca[- ]immo\b", re.I)
    connues = [x for x in charges if rg.search(x['libelle'])]
    autres = [x for x in charges if not rg.search(x['libelle'])]
    print('  parmi ces charges : %d sont les « RÉGIE EMERY LOCA-IMMO » déjà identifiées (%s)'
          % (len(connues), euros(sum(x['debit'] or x['credit'] for x in connues))))
    print('                      %d sont AUTRE CHOSE (%s)'
          % (len(autres), euros(sum(x['debit'] or x['credit'] for x in autres))))
    if autres:
        print()
        print('  ⚠️ CES LIGNES N’ÉTAIENT PAS DANS LES 10 :')
        for x in sorted(autres, key=lambda y: -(y['debit'] or y['credit'])):
            print('     %-13s %-9s %10s [%-24s] %-42s → %s'
                  % (x['agence'][:13], x['compte'], euros(x['debit'] or x['credit']),
                     x['entete'][:24], x['libelle'][:42], x['nature'][:30]))

    entete('POUR MÉMOIRE — LE CÔTÉ P7A DU MÊME CONTENEUR (aucune action)')
    p7a = [x for x in tout if x['cote'] == 'P7A']
    ch7a = [x for x in p7a if not x['paiement']]
    print('  %d lignes de famille P7A, dont %d sans marque de règlement (%s).'
          % (len(p7a), len(ch7a), euros(sum(x['debit'] or x['credit'] for x in ch7a))))
    print('  Emmanuel a déclaré P7A complète le 31/08/2026 : rien n’est proposé ici.')
    for n, k in collections.Counter(x['nature'][:44] for x in ch7a).most_common(6):
        print('     %-46s %d' % (n, k))


if __name__ == '__main__':
    main()
