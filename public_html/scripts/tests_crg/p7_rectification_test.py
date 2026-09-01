# -*- coding: utf-8 -*-
"""
NON-RÉGRESSION DE LA RECTIFICATION P7 DU 31/08/2026 — rééditions et exhaustivité.

Deux acquis à protéger, et ils tirent en sens contraire :

  ① UNE SITUATION RÉÉDITÉE NE SE LIT QU'UNE FOIS. `SABY 0107-2.pdf` et `FOCH 0108.pdf` énoncent
    la même situation que leur jumelle au même arrêté. Les deux PDF restent en GED ; le lecteur
    n'en lit qu'un. Si l'un des deux redevenait lisible, 32 145,84 € seraient comptés deux fois.

  ② LE CONTENEUR NE DÉFINIT PAS LE PÉRIMÈTRE. Les charges du compte courant du mandant
    appartiennent à P7 comme celles des immeubles. Si `mandat.operations` cessait d'être lu,
    228 062,46 € disparaîtraient sans qu'aucun contrôle ne rougisse.

⚠️ CE TEST GARDE LES DEUX EN MÊME TEMPS, parce qu'une correction peut défaire l'autre.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402

ATTENDU = {
    ('P7A', 'LYON hors SIR'): 117441.62, ('P7A', 'EMERY IMMO'): 84871.67,
    ('P7A', 'VIENNE'): 14315.62, ('P7A', 'GROUPE SIR'): 796496.19,
    ('P7B', 'LYON hors SIR'): 58616.26, ('P7B', 'EMERY IMMO'): 52085.53,
    ('P7B', 'VIENNE'): 7295.31, ('P7B', 'GROUPE SIR'): 470375.91,
}
LARG = 96


def main():
    ok = True
    print('=' * LARG)
    print('P7 · RECTIFICATION DU 31/08/2026 — RÉÉDITIONS ET EXHAUSTIVITÉ')
    print('=' * LARG)

    # ── ① les rééditions sont bien présentes en GED, et bien absentes de la lecture ────────
    presentes = set()
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                presentes.add(d['_nom_original'])
    print('  pièces rééditées TOUJOURS dans le corpus GED   : %d / %d  %s'
          % (len(presentes), len(P7.REEDITIONS),
             'OK' if presentes == P7.REEDITIONS else 'ÉCHEC — une pièce a disparu du corpus'))
    ok &= presentes == P7.REEDITIONS

    lues = [x for ag in AGENCES for x in P7.lignes(ag)
            if x['crg_fichier'] in P7.REEDITIONS]
    print('  lignes lues depuis ces pièces                  : %d  %s'
          % (len(lues), 'OK' if not lues else 'ÉCHEC — double comptage rétabli'))
    ok &= not lues

    # ── ② le compte courant du mandant est bien lu ────────────────────────────────────────
    mdt = [x for ag in AGENCES for x in P7.lignes(ag)
           if x['maille_documentaire'] == 'compte mandant' and x['cote'] in ('P7A', 'P7B')]
    m7a = sum(x['montant_source'] for x in mdt if x['cote'] == 'P7A')
    m7b = sum(x['montant_source'] for x in mdt if x['cote'] == 'P7B')
    print('  charges lues dans `mandat.operations`          : %d lignes · P7A %s · P7B %s'
          % (len(mdt), euros(m7a), euros(m7b)))
    assez = len(mdt) >= 70 and (m7a + m7b) > 200000
    print('  le conteneur est-il toujours lu ?              : %s'
          % ('OK' if assez else 'ÉCHEC — les charges du mandant ont disparu'))
    ok &= assez

    # ── ③ les bornes basses, périmètre par périmètre ──────────────────────────────────────
    print()
    print('  %-5s %-16s %16s %16s   %s' % ('CÔTÉ', 'PÉRIMÈTRE', 'attendu', 'obtenu', 'verdict'))
    for (cote, ag), attendu in sorted(ATTENDU.items()):
        b = P7.bases([x for x in P7.lignes(ag) if x['cote'] == cote])
        bon = abs(b['total_basse'] - attendu) < 0.005
        ok &= bon
        print('  %-5s %-16s %16s %16s   %s'
              % (cote, ag, euros(attendu), euros(b['total_basse']),
                 'OK' if bon else 'ÉCHEC'))

    print()
    print('=' * LARG)
    print('P7 · RECTIFICATION : %s' % ('OUI — rééditions neutralisées, compte mandant lu'
                                       if ok else 'NON — voir les ÉCHEC ci-dessus'))
    print('=' * LARG)
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
