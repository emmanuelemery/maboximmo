# -*- coding: utf-8 -*-
"""
RECTIFICATION P7A-DEPENSE-11/12 — reclassement des occurrences fusionnées à tort.

⚠️ ANCIENNE RÈGLE : deux lignes partageant `agence · compte · immeuble · rubrique · libellé ·
   montant · date` étaient tenues pour UNE dépense réénoncée, et la borne basse n'en comptait
   qu'une. ANOMALIE DÉMONTRÉE : sur 195 473,48 € ainsi retirés, 195 317,76 € correspondent à des
   lignes réellement imprimées à des endroits différents — souvent dans des CRG de PÉRIODES
   DIFFÉRENTES. La clé confondait « même écriture apparente » et « même événement métier ».

⚠️ LE PRINCIPE S'INVERSE — décision Emmanuel du 31/08/2026 : chaque occurrence documentaire est
   un ÉVÉNEMENT DISTINCT, sauf PREUVE POSITIVE qu'elle réénonce le même événement. L'absence de
   différence démontrable ne prouve rien.

⚠️ ET LA COORDONNÉE Y N'EST PAS UNE IDENTITÉ MÉTIER. Elle ne sert qu'à corroborer que deux
   lignes sont réellement imprimées. Ce qui démontre la distinction, c'est d'abord la PÉRIODE du
   CRG, puis la séquence documentaire — page et position ne viennent qu'ensuite.

   A — ÉVÉNEMENTS DISTINCTS DÉMONTRÉS      périodes de CRG différentes, ou deux lignes
                                           distinctes du même document
   B — RÉÉNONCÉS DÉMONTRÉS                 la MÊME ligne imprimée, comptée deux fois : même
                                           document, même page, même position
   C — IDENTITÉ MÉTIER INDÉTERMINABLE      le document ne permet pas de trancher (position non
                                           conservée par le lecteur)

Lecture seule : ce programme MESURE le reclassement, il ne modifie pas P7A.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402
import p7_socle as P7   # noqa: E402

LARG = 100
A = 'A — ÉVÉNEMENTS DISTINCTS DÉMONTRÉS'
B = 'B — RÉÉNONCÉS DU MÊME ÉVÉNEMENT DÉMONTRÉS'
C = 'C — IDENTITÉ MÉTIER INDÉTERMINABLE'


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def lignes_positionnees(agence):
    """Les lignes P7A/P7B avec période, page et position — ce que `lignes()` ne porte pas."""
    for d in documents(agence):
        m = d.get('meta') or {}
        fm = d.get('_format')
        base = {'agence': agence, 'compte': str(m.get('compte') or ''),
                'sha': d.get('_sha'), 'periode': m.get('periode_cle'),
                'crg': d.get('_nom_original')}
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            if fm == 'septeo_spi':
                for mv in im.get('mouvements') or []:
                    sec = mv.get('section')
                    nat, cote = P7.nature(sec, str(mv.get('libelle') or ''), sec)
                    if cote not in ('P7A', 'P7B'):
                        continue
                    yield dict(base, cote=cote, immeuble=code, entete=str(sec or ''),
                               libelle=str(mv.get('libelle') or ''),
                               montant=round(float(mv.get('debit') or 0), 2),
                               date=P4B.jour(mv.get('date')), page=mv.get('page'), y=None)
            else:
                for ch in im.get('charges') or []:
                    e, l = str(ch.get('entete') or ''), str(ch.get('libelle') or '')
                    sec = P7.section_de(d.get('_sha'), ch.get('page'), l, e)
                    nat, cote = P7.nature(e, l, sec)
                    if cote not in ('P7A', 'P7B'):
                        continue
                    yield dict(base, cote=cote, immeuble=code, entete=e, libelle=l,
                               montant=round(float(ch.get('debit') or 0), 2), date=None,
                               page=ch.get('page'),
                               y=None if ch.get('y') is None else round(float(ch['y']), 1))


def classer(v):
    """La classe d'un groupe d'occurrences partageant l'ancienne clé.

       ⚠️ ON CHERCHE UNE PREUVE DE RÉÉNONCÉ, PAS UNE ABSENCE DE DIFFÉRENCE. Deux occurrences ne
          sont le même événement que si le document les imprime au même endroit du même
          document — c'est-à-dire s'il n'y a qu'une ligne, lue deux fois."""
    par_position = collections.defaultdict(list)
    for x in v:
        par_position[(x['sha'], x['page'], x['y'])].append(x)
    classes = []
    for (sha, page, y), lot in par_position.items():
        if len(lot) == 1:
            classes.append((A, lot))
        elif y is None:
            # position non conservée : impossible de dire si c'est une ligne ou deux
            classes.append((C, lot))
        else:
            classes.append((B, lot))
    return classes


def main():
    stats = collections.Counter()
    montants = collections.Counter()
    exemples = collections.defaultdict(list)
    avant = collections.defaultdict(lambda: {'basse': 0.0, 'haute': 0.0, 'occ': 0})
    apres = collections.defaultdict(lambda: {'basse': 0.0, 'haute': 0.0, 'occ': 0})

    for ag in AGENCES:
        toutes = list(lignes_positionnees(ag))
        par_ancienne_cle = collections.defaultdict(list)
        for x in toutes:
            par_ancienne_cle[(x['agence'], x['compte'], x['immeuble'], x['entete'],
                              x['libelle'], x['montant'], x['date'])].append(x)

        for cle, v in par_ancienne_cle.items():
            cote = v[0]['cote']
            # ── ce que faisait l'ANCIENNE règle : une clé = une dépense
            avant[(ag, cote)]['basse'] += v[0]['montant']
            avant[(ag, cote)]['haute'] += sum(x['montant'] for x in v)
            avant[(ag, cote)]['occ'] += len(v)
            # ── ce que donne la NOUVELLE : chaque ligne imprimée est un événement
            for classe, lot in classer(v):
                if len(v) > 1:
                    stats[classe] += len(lot)
                    montants[classe] += lot[0]['montant'] * len(lot) if classe == A \
                        else lot[0]['montant']
                    if len(exemples[classe]) < 3 and len(v) > 1:
                        exemples[classe].append(lot)
                if classe == C:
                    apres[(ag, cote)]['basse'] += lot[0]['montant']
                    apres[(ag, cote)]['haute'] += sum(x['montant'] for x in lot)
                elif classe == B:
                    apres[(ag, cote)]['basse'] += lot[0]['montant']
                    apres[(ag, cote)]['haute'] += lot[0]['montant']
                else:
                    apres[(ag, cote)]['basse'] += sum(x['montant'] for x in lot)
                    apres[(ag, cote)]['haute'] += sum(x['montant'] for x in lot)
                apres[(ag, cote)]['occ'] += len(lot)

    entete('LE RECLASSEMENT DES OCCURRENCES AUTREFOIS FUSIONNÉES')
    print('  %-46s %10s %16s' % ('CLASSE', 'occurrences', 'montant'))
    for k in (A, B, C):
        print('  %-46s %10d %16s' % (k, stats[k], euros(montants[k])))

    for k in (B, C):
        if not exemples[k]:
            continue
        entete(k)
        for lot in exemples[k]:
            x = lot[0]
            print('  ×%d %-13s %-30s %10s' % (len(lot), x['agence'][:13],
                                              x['libelle'][:30], euros(x['montant'])))
            for y in lot:
                print('       %-32s p.%-4s y=%-8s %s'
                      % (str(y['crg'])[:32], y['page'], y['y'], y['periode']))

    entete('P7A / P7B — ANCIENS PUIS NOUVEAUX MONTANTS, PAR PÉRIMÈTRE')
    print('  %-16s %-5s %10s %16s %16s %16s'
          % ('PÉRIMÈTRE', 'côté', 'occur.', 'ancienne basse', 'nouvelle basse', 'écart'))
    tot_a = tot_n = 0.0
    for (ag, cote) in sorted(avant, key=lambda k: (k[1], k[0])):
        a, n = avant[(ag, cote)], apres[(ag, cote)]
        print('  %-16s %-5s %10d %16s %16s %16s'
              % (ag, cote, n['occ'], euros(a['basse']), euros(n['basse']),
                 euros(n['basse'] - a['basse'])))
        tot_a += a['basse']
        tot_n += n['basse']
    print('  %-16s %-5s %10s %16s %16s %16s'
          % ('TOTAL', '', '', euros(tot_a), euros(tot_n), euros(tot_n - tot_a)))

    entete('LE TOTAL EST-IL EXACT, OU RESTE-T-IL UNE FOURCHETTE ?')
    hb = sum(v['haute'] for v in apres.values())
    bb = sum(v['basse'] for v in apres.values())
    print('  BORNE BASSE  — A et B comptés une fois chacun            %16s' % euros(bb))
    print('  BORNE HAUTE  — + les occurrences C possiblement distinctes %14s' % euros(hb))
    if abs(hb - bb) < 0.005:
        print('  TOTAL MÉTIER EXACT                                       %16s' % euros(bb))
    else:
        print('  TOTAL MÉTIER EXACT                                        NON DÉMONTRABLE')
        print('  incertitude résiduelle : %s (classe C)' % euros(hb - bb))


if __name__ == '__main__':
    main()
