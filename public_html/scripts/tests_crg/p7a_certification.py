# -*- coding: utf-8 -*-
"""
P7A — DÉPENSES : QU'A SUPPORTÉ LE PROPRIÉTAIRE, SUR QUOI, ET À QUELLE MAILLE ?

⚠️ DÉPENSE ≠ APPEL LOCATAIRE. La même réalité économique peut apparaître comme dépense du
   propriétaire PUIS comme refacturation au locataire : ce sont deux événements, jamais un
   seul. P5A certifie ce qui est appelé au locataire — **P7A ne le reclasse ni ne le modifie**.
   Une facture de plomberie de 300 € côté propriétaire et 100 € refacturés au locataire
   coexistent, et ne s'annulent pas.

⚠️ DÉPENSE ≠ PAIEMENT. Une charge imprimée démontre une écriture documentaire, pas la date du
   virement fournisseur, ni son bénéficiaire effectif, ni un décaissement bancaire.

⚠️ DÉPENSE ≠ FLUX PROPRIÉTAIRE. « Loyers versés au propriétaire », « SOLDE MANDAT »,
   « Virement » sont des mouvements régie ↔ propriétaire : ils relèvent de P8 et sont écartés
   ici, jamais comptés comme charge supportée.

⚠️ ET LE PIÈGE DE P6 GUETTE ENCORE. Une même dépense réénoncée dans plusieurs CRG ne fait pas
   plusieurs dépenses. On distingue donc OCCURRENCE DOCUMENTAIRE et DÉPENSE MÉTIER UNIQUE, et
   deux lignes qui pourraient être la même sans que ce soit démontrable restent CANDIDAT
   DOUBLON — jamais fusionnées.

Aucun KPI de rentabilité, aucune récupérabilité juridique n'est décidée ici. Lecture seule.
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

DUMP = r'C:\tmp\p7a_depenses.json'



def certifier(agences):
    ok = True
    tout = {ag: [x for x in S.lignes(ag)] for ag in agences}

    entete('P7A — CE QUE LE DOCUMENT IMPRIME, ET CE QUI N’EST PAS UNE DÉPENSE')
    print('  %-16s %8s %8s %8s %10s %14s %22s' %
          ('PÉRIMÈTRE', 'lignes', 'P7A', 'P7B', 'à qualif.', 'dépôt garantie', 'HORS P7 (→ P8)'))
    for ag in agences:
        c = collections.Counter(x['cote'] for x in tout[ag])
        hors = sum(x['montant_source'] for x in tout[ag] if x['cote'] == 'hors')
        dg = sum(x['montant_source'] for x in tout[ag] if x['cote'] == 'dg')
        print('  %-16s %8d %8d %8d %10d %4d · %-7s %6d · %s' %
              (ag, len(tout[ag]), c['P7A'], c['P7B'], c['a_qualifier'],
               c['dg'], euros(dg), c['hors'], euros(hors)))
    print('  ⚠ LE DÉPÔT DE GARANTIE SORT DE P7 DANS LES DEUX SENS — décision Emmanuel. Ni')
    print('    dépense, ni travail, ni entretien, ni avoir : une restitution de passif.')
    print('    Les flux régie ↔ propriétaire sont écartés de même. Aucun n’entre dans un total.')

    entete('P7A — DÉPENSES PAR NATURE, SUR OBJETS MÉTIER (une dépense réénoncée compte UNE fois)')
    # ⚠️ LA BASE DE CALCUL EST ÉCRITE, JAMAIS SOUS-ENTENDUE. La BORNE BASSE compte chaque clé
    #    documentaire une seule fois ; la BORNE HAUTE somme les occurrences ; l’écart entre les
    #    deux est exactement ce que les réénoncés ajouteraient.
    # ⚠️ ET AUCUNE DES DEUX NE S’APPELLE « TOTAL MÉTIER ». `P7A-DEPENSE-12` réserve ce nom au
    #    chiffre exact, que le document ne démontre pas tant qu’un candidat doublon subsiste :
    #    baptiser « total métier » la borne basse ferait passer une hypothèse basse pour un
    #    résultat. Le test le dit donc lui-même, ligne à ligne, comme le référentiel.
    print(('  %-46s' + ' %15s' * len(agences)) % (('NATURE',) + tuple(agences)))
    gab = '  %-46s' + ' %15s' * len(agences)
    par = {}
    for ag in agences:
        for x in tout[ag]:
            if x['cote'] != 'P7A':
                continue
            par.setdefault((ag, x['nature']), []).append(x)
    natures = sorted({n for (a, n) in par}, key=lambda n: -sum(
        S.bases(par.get((a, n), []))['total_basse'] for a in agences))
    for n in natures:
        print(gab % ((n[:46],) + tuple(
            euros(S.bases(par.get((a, n), []))['total_basse']) for a in agences)))
    bornes = {a: S.bases([x for x in tout[a] if x['cote'] == 'P7A']) for a in agences}
    print(gab % (('BORNE BASSE — OBJETS MÉTIER UNIQUES DÉMONTRÉS',) + tuple(
        euros(bornes[a]['total_basse']) for a in agences)))
    print(gab % (('BORNE HAUTE — OCCURRENCES DOCUMENTAIRES',) + tuple(
        euros(bornes[a]['total_haute']) for a in agences)))
    # ⚠️ LA TROISIÈME LIGNE N’EST PAS UN TOTAL : C’EST SON ABSENCE, ÉCRITE. Un périmètre dont
    #    les deux bornes coïncident n’a plus de candidat doublon — son total métier est alors
    #    exact et s’affiche. Sinon la case dit NON DÉMONTRABLE, et le lecteur ne peut pas
    #    prendre une borne pour le chiffre.
    print(gab % (('TOTAL MÉTIER EXACT',) + tuple(
        euros(bornes[a]['total_basse'])
        if bornes[a]['total_basse'] == bornes[a]['total_haute'] else 'NON DÉMONTRABLE'
        for a in agences)))

    entete('P7A — LES BASES DE CALCUL, ET CE QUI RESTE INDÉTERMINÉ')
    print('  %-16s %12s %16s %18s %18s %18s' %
          ('PÉRIMÈTRE', 'occurrences', 'événements', 'indéterminées',
           'total borne basse', 'total borne haute'))
    for ag in agences:
        b = S.bases([x for x in tout[ag] if x['cote'] == 'P7A'])
        print('  %-16s %12d %16d %18d %18s %18s' %
              (ag, b['occurrences'], b['evenements'], b['indeterminees'],
               euros(b['total_basse']), euros(b['total_haute'])))
    print('  ⚠ UNE RESSEMBLANCE DOCUMENTAIRE N’EST PAS UNE PREUVE DE DOUBLON — rectification')
    print('    Emmanuel du 31/08/2026. Chaque ligne imprimée est un ÉVÉNEMENT DISTINCT, sauf')
    print('    preuve positive qu’elle réénonce le même événement. L’ancienne clé fusionnait')
    print('    195 317,76 € de lignes réellement imprimées ailleurs — souvent dans des CRG de')
    print('    périodes différentes. Ne restent indéterminées que les occurrences dont le')
    print('    lecteur n’a pas conservé la position.')

    entete('P7A — MAILLE ET PROVENANCE')
    print('  %-16s %12s %12s %12s %14s' %
          ('PÉRIMÈTRE', 'maille lot', 'immeuble', 'compte', 'provenance'))
    for ag in agences:
        d = [x for x in tout[ag] if x['cote'] == 'P7A']
        c = collections.Counter(x['maille_documentaire'] for x in d)
        # ⚠️ LA PROVENANCE EXIGE CE QUE LA MAILLE IMPLIQUE, PAS PLUS. `immeuble` était requis
        #    parce que P7 ne connaissait qu'une maille ; depuis la rectification du 31/08/2026,
        #    une charge du COMPTE MANDANT n'a légitimement pas d'immeuble — elle porte un
        #    compte. Exiger l'immeuble ferait rougir une provenance complète ; ne rien exiger
        #    laisserait passer une provenance vide. On exige donc le compte à sa place.
        complet = sum(1 for x in d
                      if all(x.get(k) not in (None, '') for k in S.CHAINE
                             if not (k == 'immeuble'
                                     and x['maille_documentaire'] == 'compte mandant'))
                      and isinstance(x['page'], int))
        if complet != len(d):
            ok = False
        print('  %-16s %12d %12d %12d %8d/%-5d%s' %
              (ag, c['lot'], c['immeuble'], c['compte mandant'], complet, len(d),
               '   ⚠' if complet != len(d) else ''))
    print('  ⚠ chez LYON et EMERY le lot est imprimé sur une ligne séparée que le lecteur ne')
    print('    rattache pas : la dépense reste à la maille IMMEUBLE, sans ventilation inventée.')

    entete('P7A — TROIS NIVEAUX D\u2019INDÉTERMINATION, JAMAIS UN SEUL SAC')
    # \u26a0\ufe0f FAMILLE DÉMONTRÉE \u2260 SOUS-NATURE DÉMONTRÉE \u2014 décision Emmanuel.
    #    « Honoraires Annexes » ne dit pas QUEL honoraire, mais il dit que c\u2019est un HONORAIRE.
    #    Le ranger avec « Local 0013 » perdrait ce que le document donne.
    print('  %-16s %28s %34s %26s' %
          ('PÉRIMÈTRE', 'A \u2014 famille + sous-nature', 'B \u2014 famille, sous-nature inconnue',
           'C \u2014 rien de démontrable'))
    tA = tB = tC = 0.0
    for ag in agences:
        A = [x for x in tout[ag] if x['cote'] in ('P7A', 'P7B')
             and 'sous-nature indéterminée' not in x['nature']]
        B = [x for x in tout[ag] if 'sous-nature indéterminée' in x['nature']]
        C = [x for x in tout[ag] if x['cote'] == 'a_qualifier']
        for lst, acc in ((A, 'A'), (B, 'B'), (C, 'C')):
            pass
        tA += sum(x['montant_source'] for x in A)
        tB += sum(x['montant_source'] for x in B)
        tC += sum(x['montant_source'] for x in C)
        print('  %-16s %6d \u00b7 %-17s %6d \u00b7 %-23s %6d \u00b7 %-15s' %
              (ag, len(A), euros(sum(x['montant_source'] for x in A)),
               len(B), euros(sum(x['montant_source'] for x in B)),
               len(C), euros(sum(x['montant_source'] for x in C))))
    print('  %-16s %6s   %-17s %6s   %-23s %6s   %-15s' %
          ('TOTAL', '', euros(tA), '', euros(tB), '', euros(tC)))
    print('  \u26a0 sommes d\u2019OCCURRENCES, pas de totaux métier : elles mesurent le résiduel')
    print('    documentaire, pas une charge.')
    inc = collections.Counter()
    for ag in agences:
        for x in tout[ag]:
            if x['cote'] == 'a_qualifier':
                inc[(x['entete_source'] or x['libelle_brut'])[:46]] += 1
    print('\n  Le vrai résiduel C, par libellé :')
    for k, v in inc.most_common(10):
        print('   %5d  %s' % (v, k))

    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps(
        [x for ag in agences for x in tout[ag] if x['cote'] == 'P7A'], ensure_ascii=False))
    print('\n  dépenses exportées → %s' % DUMP)
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP7A — DÉPENSES : %s'
          % ('OUI — nature, maille, provenance et réénoncés maîtrisés'
             if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
