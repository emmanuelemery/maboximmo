# -*- coding: utf-8 -*-
"""
CONTRÔLE P7B — LES PRESTATIONS FACTURÉES PAR LA RÉGIE DANS LE COMPTE MANDANT.

⚠️ LA QUESTION N'EST PAS « SONT-CE DES HONORAIRES ? » MAIS « P7B LES A-T-IL DÉJÀ ? ». Si
   `mandat.operations` ne montre que le RÈGLEMENT d'un honoraire déjà certifié par P7B, les
   ajouter compterait deux fois la même charge — exactement le double comptage que la
   rectification d'unicité vient de combattre.

⚠️ ET UNE ÉGALITÉ DE MONTANT NE DÉMONTRE RIEN. Deux honoraires de 500,00 € sur le même compte
   peuvent être deux prestations différentes. On cherche une PREUVE POSITIVE DE RELATION :
   même compte ET même période ET une nature compatible — à défaut, on classe C.

   A — rémunération déjà présente dans P7B, dont le compte mandant ne montre que le règlement
   B — rémunération réellement absente de P7B → nouvelle charge P7B
   C — correspondance indéterminable

Lecture seule. P7B n'est pas modifiée, P8A n'est pas reprise.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 128
FAMILLE = 'PRESTATION FACTURÉE PAR LA RÉGIE'


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def mots(s):
    return {m for m in re.findall(r"[A-Za-zÀ-ÿ]{4,}", str(s or '').upper())
            if m not in ('REGIE', 'EMERY', 'LOCA', 'IMMO', 'POUR', 'DANS', 'AVEC')}


def main():
    cibles = [x for ag in AGENCES for x in S.candidats(ag)
              if x['famille'].startswith(FAMILLE)]

    # ── l'ensemble P7B certifié, indexé par compte ────────────────────────────────────────
    p7b = collections.defaultdict(list)
    n7b = 0
    for ag in AGENCES:
        for l in P7.lignes(ag):
            if l['cote'] != 'P7B':
                continue
            n7b += 1
            p7b[(ag, l['compte'])].append(l)

    entete('LES %d LIGNES, ET CE QUE P7B PORTE SUR LE MÊME COMPTE' % len(cibles))
    print('  P7B certifie %d lignes d’honoraires/assurances au total.' % n7b)
    print()
    print('  %-9s %-9s %10s %-26s %-42s %s'
          % ('compte', 'période', 'montant', 'RUBRIQUE', 'LIBELLÉ EXACT', 'PDF · page'))
    resultats = []
    for x in sorted(cibles, key=lambda y: (y['compte'], str(y['periode_crg']))):
        print('  %-9s %-9s %10s %-26s %-42s %s p.%s'
              % (x['compte'], x['periode_crg'], euros(x['montant_source']),
                 x['section'][:26], x['libelle_brut'][:42], str(x['crg_fichier'])[:22], x['page']))

        voisins = p7b.get((x['agence'], x['compte']), [])
        m = round(x['montant_source'], 2)
        # ① même montant, même compte
        meme_montant = [l for l in voisins if round(l['montant_source'], 2) == m]
        # ② même montant ET même période — la relation la plus forte disponible
        meme_periode = [l for l in meme_montant if l['periode_crg'] == x['periode_crg']]
        # ③ une nature compatible : des mots du libellé se retrouvent
        cle = mots(x['libelle_brut'])
        lexical = [l for l in voisins
                   if cle and len(cle & (mots(l['libelle_brut']) | mots(l['entete_source']))) >= 2]

        if meme_periode:
            classe, preuve = 'A', 'même compte + même période + même montant'
            temoins = meme_periode
        elif lexical and meme_montant:
            classe, preuve = 'A', 'même montant + libellé concordant'
            temoins = [l for l in lexical if round(l['montant_source'], 2) == m]
        elif meme_montant:
            classe, preuve = 'C', 'même montant seul — insuffisant'
            temoins = meme_montant
        elif lexical:
            classe, preuve = 'C', 'libellé concordant, montant différent'
            temoins = lexical
        else:
            classe, preuve = 'B', 'aucune ligne P7B rapprochable sur ce compte'
            temoins = []
        resultats.append((x, classe, preuve, temoins))
        print('       → %s · %s' % (classe, preuve))
        for l in temoins[:2]:
            print('           P7B : [%-24s] %-38s %10s (%s)'
                  % (l['entete_source'][:24], l['libelle_brut'][:38],
                     euros(l['montant_source']), l['periode_crg']))
        print('       P7B sur ce compte : %d ligne(s), montants %s'
              % (len(voisins), ', '.join(sorted({euros(l['montant_source'])
                                                 for l in voisins}))[:70] or '—'))
        print()

    entete('P7B CONNAÎT-IL DÉJÀ CETTE FAMILLE ? (prestations RÉGIE EMERY dans `charges`)')
    # ⚠️ LA VRAIE QUESTION N'EST PAS « LA FAMILLE EST-ELLE ABSENTE ? ». P7B lit `charges` et y
    #    trouve déjà des prestations facturées par la régie : ce ne serait donc pas une famille
    #    inconnue, mais des OCCURRENCES supplémentaires d'une famille déjà certifiée.
    RG = re.compile(r"r[ée]gie\s+emery\s+loca[- ]immo", re.I)
    deja = [l for ag in AGENCES for l in P7.lignes(ag)
            if l['cote'] == 'P7B' and RG.search(l['libelle_brut'])]
    print('  P7B certifie déjà %d prestations « RÉGIE EMERY LOCA-IMMO » lues dans `charges`,'
          % len(deja))
    print('  pour %s.' % euros(sum(l['montant_source'] for l in deja)))
    for ag in AGENCES:
        v = [l for l in deja if l['agence'] == ag]
        if v:
            print('     %-16s %4d lignes  %12s' % (ag, len(v), euros(sum(l['montant_source']
                                                                        for l in v))))
    print('  exemples :')
    for l in deja[:5]:
        print('     [%-26s] %-44s %10s (%s)'
              % (l['entete_source'][:26], l['libelle_brut'][:44],
                 euros(l['montant_source']), l['periode_crg']))

    entete('VERDICT')
    for c, nom in (('A', 'déjà présente dans P7B — le compte mandant n’en montre que le règlement'),
                   ('B', 'absente de P7B — nouvelle charge P7B'),
                   ('C', 'correspondance indéterminable')):
        v = [r for r in resultats if r[1] == c]
        print('  %s — %-62s %2d   %12s'
              % (c, nom, len(v), euros(sum(r[0]['montant_source'] for r in v))))
    b = [r for r in resultats if r[1] == 'B']
    if b:
        print()
        print('  IMPACT P7B POTENTIEL SI B EST RETENU : +%s sur %d lignes'
              % (euros(sum(r[0]['montant_source'] for r in b)), len(b)))
        par_ag = collections.Counter()
        for r in b:
            par_ag[r[0]['agence']] += r[0]['montant_source']
        for ag, mt in par_ag.most_common():
            print('     %-16s %12s' % (ag, euros(mt)))


if __name__ == '__main__':
    main()
