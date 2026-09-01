# -*- coding: utf-8 -*-
"""
P7B — FRAIS / ASSURANCES : CE QUE LA RÉGIE PRÉLÈVE ET CE QUE LE PROPRIÉTAIRE ASSURE.

⚠️ DÉPENSE ≠ FRAIS DE GESTION. P7A porte ce que le BIEN coûte ; P7B porte ce que la RÉGIE
   facture et ce que le propriétaire ASSURE. Une ligne ambiguë reste NATURE À QUALIFIER plutôt
   que d'être forcée dans l'une ou l'autre.

⚠️ ON NE FOND PAS LES FAMILLES D'HONORAIRES QUE LE DOCUMENT DISTINGUE. « Honoraires de gestion
   HT », « TVA sur hono. de gestion », « Frais sur DR », « Honoraires Revenus Fonciers » et
   « FRAIS DE DOSSIERS » sont imprimés séparément : les réunir sous « frais de gestion »
   perdrait ce que le CRG dit.

⚠️ ET « GARANTIE DES LOYERS » N'EST PAS AUTOMATIQUEMENT UNE PRIME GLI. P5 avait déjà démontré
   que « Honoraires garantie des loyers » est une CHARGE DU PROPRIÉTAIRE, jamais un règlement
   locataire. Mais le mot imprimé est « honoraires » : le document facture un service, il ne
   dit pas que c'est une prime d'assurance. On conserve donc sa famille propre, sans
   l'assimiler à une PNO ni à une police d'assurance.

⚠️ ASSIETTE × TAUX EST UN CONTRÔLE, PAS LA DONNÉE. La rubrique imprime parfois le calcul lui-
   même — « (*)920.00 x 4.00 % » — et le montant à côté. Le MONTANT IMPRIMÉ reste la donnée
   certifiée ; la multiplication ne sert qu'à vérifier.

⚠️ ET LA TVA NE SE RECONSTITUE PAS. Si le document imprime le HT et la TVA séparément, on garde
   les deux. S'il n'imprime qu'un TTC, on ne fabrique ni HT ni TVA.

⚠️ ENFIN, ON NE RECOMPTE PAS CE QUE P5A A DÉJÀ CERTIFIÉ. Un frais appelé AU LOCATAIRE appartient
   à P5A ; P7B ne compte que ce qui est prélevé au propriétaire.

Aucun KPI de rentabilité. Lecture seule.
"""
import sys
import os
import io
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import perimetre, entete, euros   # noqa: E402
import p7_socle as S   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

DUMP = r'C:\tmp\p7b_frais.json'
NATURES = [n for n, _ in S.P7B_MOTIFS] + [n for n, _, c in S.FAMILLES if c == 'P7B']
HONORAIRES = ('honoraires de gestion', 'TVA sur honoraires', 'frais de gestion',
              'autres honoraires')
ASSURANCES = ('garantie des loyers (GLI)', 'assurance PNO', 'autre assurance')


def certifier(agences):
    ok = True
    tout = {ag: [x for x in S.lignes(ag) if x['cote'] == 'P7B'] for ag in agences}

    entete('P7B — FRAIS ET ASSURANCES, PAR FAMILLE RÉELLEMENT IMPRIMÉE')
    gab = '  %-30s' + ' %15s' * len(agences)
    print(gab % (('FAMILLE',) + tuple(agences)))
    par = collections.defaultdict(float)
    nb = collections.Counter()
    for ag in agences:
        for x in tout[ag]:
            par[(ag, x['nature'])] += x['montant_source']
            nb[(ag, x['nature'])] += 1
    for n in NATURES:
        print(gab % ((n,) + tuple(
            euros(S.bases([x for x in tout[a] if x['nature'] == n])['total_basse'])
            for a in agences)))
    print(gab % (('— dont honoraires (total métier)',) + tuple(
        euros(S.bases([x for x in tout[a] if x['nature'] in HONORAIRES
                       or 'honoraires' in x['nature']])['total_basse']) for a in agences)))
    print(gab % (('— dont assurances (total métier)',) + tuple(
        euros(S.bases([x for x in tout[a] if x['nature'] in ASSURANCES
                       or 'assurance' in x['nature']])['total_basse']) for a in agences)))
    print('  ⚠ « garantie des loyers » garde sa famille propre : le document facture un')
    print('    HONORAIRE, il ne démontre pas une prime d’assurance.')

    entete('P7B — ASSIETTE × TAUX : UN CONTRÔLE, ET IL RÉVÈLE LE SENS DU MONTANT')
    # ⚠️ LE MONTANT IMPRIMÉ N'EST PAS TOUJOURS LE PRODUIT ATTENDU, ET C'EST INSTRUCTIF.
    #    LYON attache la rubrique « (*)920.00 x 4.00 % » à la ligne de TVA : 920 × 4 % = 36,80
    #    d'honoraires, dont la TVA à 20 % vaut 7,36 — le montant imprimé sur cette ligne.
    #    SEPTEO imprime « Honoraires Gestion TTC (taux:6,00 % HT, base:335,00 €) » et un débit
    #    de 24,12, soit 335 × 6 % = 20,10 HT porté à 24,12 TTC. Le calcul ne corrige rien : il
    #    dit si le montant imprimé est un HT, un TTC, ou la TVA seule.
    print('  %-16s %13s %13s %13s %13s %14s' %
          ('PÉRIMÈTRE', 'assiette+taux', '= HT', '= TTC', '= TVA seule', 'sans concordance'))
    for ag in agences:
        av = [x for x in tout[ag] if x['assiette'] and x['taux']]
        ht = [x for x in av if abs(x['assiette'] * x['taux'] / 100 - x['montant_source']) < 0.02]
        ttc = [x for x in av if abs(x['assiette'] * x['taux'] / 100 * 1.2
                                    - x['montant_source']) < 0.02]
        tva = [x for x in av if abs(x['assiette'] * x['taux'] / 100 * 0.2
                                    - x['montant_source']) < 0.02]
        reste = [x for x in av if x not in ht and x not in ttc and x not in tva]
        print('  %-16s %13d %13d %13d %13d %14d' %
              (ag, len(av), len(ht), len(ttc), len(tva), len(reste)))
    print('  %-16s %13s %13s %13s' %
          ('', 'avec taux TVA', 'avec TVA lue', 'assiette lue'))
    for ag in agences:
        print('  %-16s %13d %13d %13d' %
              (ag, sum(1 for x in tout[ag] if x['taux_tva'] is not None),
               sum(1 for x in tout[ag] if x['tva']),
               sum(1 for x in tout[ag] if x['assiette'] is not None)))

    entete('P7B — LES BASES DE CALCUL, ET CE QUI RESTE INDÉTERMINÉ')
    print('  %-16s %12s %16s %18s %18s %18s' %
          ('PÉRIMÈTRE', 'occurrences', 'événements', 'indéterminées',
           'total borne basse', 'total borne haute'))
    for ag in agences:
        b = S.bases(tout[ag])
        print('  %-16s %12d %16d %18d %18s %18s' %
              (ag, b['occurrences'], b['evenements'], b['indeterminees'],
               euros(b['total_basse']), euros(b['total_haute'])))
    print('  \u26a0 les montants du tableau des familles ci-dessus sont des BORNES BASSES.')
    print('    RECTIFICATION DU 31/08/2026 : `bases()` est commune aux deux côtés, la')
    print('    correction d’unicité de P7A s’y applique donc aussi. Chaque ligne imprimée est')
    print('    un ÉVÉNEMENT DISTINCT, sauf preuve positive de réénoncé.')

    entete('P7B — QUI SUPPORTE, ET PROVENANCE')
    print('  %-16s %16s %16s %14s' %
          ('PÉRIMÈTRE', 'supporté par', 'négatifs', 'provenance'))
    for ag in agences:
        neg = [x for x in tout[ag] if x['montant_source'] < 0]
        # ⚠️ MÊME AJUSTEMENT QU'EN P7A : une charge du COMPTE MANDANT n'a pas d'immeuble, elle
        #    a un compte. La provenance exige ce que la maille implique — ni plus, ni moins.
        complet = sum(1 for x in tout[ag]
                      if all(x.get(k) not in (None, '') for k in S.CHAINE
                             if not (k == 'immeuble'
                                     and x['maille_documentaire'] == 'compte mandant'))
                      and isinstance(x['page'], int))
        if complet != len(tout[ag]):
            ok = False
        print('  %-16s %16s %8d · %-9s %8d/%-5d%s' %
              (ag, 'propriétaire', len(neg), euros(sum(x['montant_source'] for x in neg)),
               complet, len(tout[ag]), '   ⚠' if complet != len(tout[ag]) else ''))
    print('  ⚠ un frais négatif n’est ni un remboursement, ni un avoir, ni une correction tant')
    print('    que le libellé ne le démontre pas : le signe seul ne fait pas la nature.')

    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps(
        [x for ag in agences for x in tout[ag]], ensure_ascii=False))
    print('\n  %d frais exportés → %s' % (sum(len(v) for v in tout.values()), DUMP))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP7B — FRAIS / ASSURANCES : %s'
          % ('OUI — familles distinguées, assiette/taux/TVA conservés, provenance complète'
             if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
