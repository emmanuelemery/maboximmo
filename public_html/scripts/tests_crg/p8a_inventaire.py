# -*- coding: utf-8 -*-
"""
P8A — INVENTAIRE DOCUMENTAIRE DES FLUX RÉGIE ↔ PROPRIÉTAIRE.

PASSE D'INVENTAIRE, PAS DE CERTIFICATION. On regarde ce que les 458 CRG impriment réellement
entre la régie et le propriétaire, avant d'écrire la moindre règle.

Lecture seule. Aucune écriture MBI, aucune PROD.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 96


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def main():
    tout = {ag: list(S.candidats(ag)) for ag in AGENCES}

    entete('P8A — LES CONTENEURS BALAYÉS (P7 n’en lisait qu’un seul)')
    print('  %-16s %-28s %8s %16s' % ('PÉRIMÈTRE', 'CONTENEUR DU PIVOT', 'lignes', 'montant lu'))
    for ag in AGENCES:
        par = collections.defaultdict(list)
        for x in tout[ag]:
            par[x['conteneur']].append(x)
        for c, v in sorted(par.items(), key=lambda kv: -len(kv[1])):
            print('  %-16s %-28s %8d %16s'
                  % (ag, c, len(v), euros(sum(y['montant_source'] for y in v))))
        if not par:
            print('  %-16s %-28s %8d %16s' % (ag, '(aucune ligne candidate)', 0, euros(0)))

    entete('P8A — LES FAMILLES RÉELLEMENT OBSERVÉES')
    fam = collections.defaultdict(list)
    for ag in AGENCES:
        for x in tout[ag]:
            fam[x['famille']].append(x)
    print('  %-58s %6s %16s' % ('FAMILLE', 'lignes', 'montant'))
    for f, v in sorted(fam.items(), key=lambda kv: -sum(y['montant_source'] for y in kv[1])):
        print('  %-58s %6d %16s' % (f[:58], len(v), euros(sum(y['montant_source'] for y in v))))

    entete('P8A — LE SENS DU FLUX, LU SUR LE LIBELLÉ')
    # ⚠️ LE SIGNE NE FONDE PAS LE SENS, IL LE CONFIRME. On imprime les deux pour que la
    #    concordance — ou la discordance — soit visible plutôt que supposée.
    print('  %-30s %6s %16s %16s   %s'
          % ('SENS', 'lignes', 'somme débit', 'somme crédit', 'concordance signe/libellé'))
    for s, v in sorted(collections.Counter().__class__(
            {k: [y for ag in AGENCES for y in tout[ag] if y['sens'] == k]
             for k in {y['sens'] for ag in AGENCES for y in tout[ag]}}).items(),
            key=lambda kv: -len(kv[1])):
        d = sum(1 for y in v if y['debit'])
        c = sum(1 for y in v if y['credit'])
        if s == 'REGIE_VERS_PROPRIETAIRE':
            note = 'attendu débit : %d/%d' % (d, len(v))
        elif s == 'PROPRIETAIRE_VERS_REGIE':
            note = 'attendu crédit : %d/%d' % (c, len(v))
        else:
            note = '—'
        print('  %-30s %6d %16s %16s   %s'
              % (s, len(v), euros(sum(y['debit'] for y in v)),
                 euros(sum(y['credit'] for y in v)), note))

    entete('P8A — CE QUI EST FLUX RÉGIE ↔ PROPRIÉTAIRE, ET CE QUI N’EN EST PAS')
    # ⚠️ « TIERS » N'EST PAS UNE CATÉGORIE UNIQUE. Le loyer saisi par le Trésor et le loyer payé
    #    de la main à la main au propriétaire sont deux faits opposés : dans un cas il n'a rien
    #    reçu, dans l'autre il a tout reçu. Les additionner dirait le contraire du document.
    P8A = ('REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE')
    print('  %-16s %10s %10s %9s %9s %9s %9s %9s'
          % ('PÉRIMÈTRE', 'candidates', 'flux P8A', 'saisi', 'direct', 'vers P8B',
             'hors P8A', 'indéterm.'))
    for ag in AGENCES:
        L = tout[ag]
        n = lambda t: len([x for x in L if x['sens'] == t])   # noqa: E731
        print('  %-16s %10d %10d %9d %9d %9d %9d %9d'
              % (ag, len(L), len([x for x in L if x['sens'] in P8A]),
                 n('TIERS_SAISIE'), n('TIERS_VERS_PROPRIETAIRE'),
                 len([x for x in L if x['famille'] == S.STOCK_P8B]),
                 len([x for x in L if x['famille'] == S.HORS]), n('SENS_INDETERMINABLE')))

    entete('P8A — QUAND LE SIGNE DÉMENT LA RUBRIQUE HÉRITÉE')
    dem = [x for ag in AGENCES for x in tout[ag] if x['note_sens']]
    print('  %d ligne(s) où le sens venait d’une rubrique que la ligne contredit — '
          'déclassées en SENS_INDETERMINABLE :' % len(dem))
    for x in dem:
        print('     %-13s D=%-9s C=%-9s [%s] « %s »'
              % (x['agence'][:13], x['debit'], x['credit'], x['section'][:28],
                 x['libelle_brut'][:38]))

    entete('P8A — LES AGRÉGATS PLURIPÉRIODE (jamais un mouvement élémentaire)')
    ag_ = [x for a in AGENCES for x in tout[a] if x['agregat']]
    print('  %d ligne(s), %s — le libellé nomme une PLAGE de périodes :'
          % (len(ag_), euros(sum(x['montant_source'] for x in ag_))))
    for x in sorted(ag_, key=lambda y: -y['montant_source']):
        print('     %-13s %14s  %-30s « %s »'
              % (x['agence'][:13], euros(x['montant_source']), x['sens'][:30],
                 x['libelle_brut'][:44]))

    entete('P8A — LES TROIS BASES DE CALCUL, PAR SENS (doctrine P7A)')
    print('  %-16s %-26s %7s %9s %9s %15s %15s'
          % ('PÉRIMÈTRE', 'SENS', 'occur.', 'uniques', 'doublons', 'borne basse', 'borne haute'))
    for ag in AGENCES:
        for s in P8A:
            L = [x for x in tout[ag] if x['sens'] == s]
            if not L:
                continue
            b = S.bases(L)
            print('  %-16s %-26s %7d %9d %9d %15s %15s'
                  % (ag, s, b['occurrences'], b['objets_certains'], b['candidats_doublons'],
                     euros(b['total_basse']), euros(b['total_haute'])))
    glob = S.bases([x for ag in AGENCES for x in tout[ag] if x['sens'] in P8A])
    print('  %-43s %7d %9d %9d %15s %15s'
          % ('TOUS PÉRIMÈTRES — flux propriétaire', glob['occurrences'],
             glob['objets_certains'], glob['candidats_doublons'],
             euros(glob['total_basse']), euros(glob['total_haute'])))
    print('  BORNE BASSE — FLUX UNIQUES DÉMONTRÉS     %s' % euros(glob['total_basse']))
    print('  BORNE HAUTE — OCCURRENCES DOCUMENTAIRES  %s' % euros(glob['total_haute']))
    print('  TOTAL MÉTIER EXACT                       %s'
          % (euros(glob['total_basse']) if glob['total_basse'] == glob['total_haute']
             else 'NON DÉMONTRABLE'))

    entete('P8A — LA MAILLE, JAMAIS VENTILÉE')
    for ag in AGENCES:
        c = collections.Counter(x['maille_documentaire'] for x in tout[ag] if x['sens'] in P8A)
        print('  %-16s %s' % (ag, dict(c) or '—'))

    entete('P8A — LA DATE DU MOUVEMENT EST-ELLE IMPRIMÉE ?')
    print('  %-16s %10s %12s %12s   %s'
          % ('PÉRIMÈTRE', 'flux P8A', 'date lue', 'non démontr.', 'exemple de date lue'))
    for ag in AGENCES:
        L = [x for x in tout[ag] if x['sens'] in P8A]
        av = [x for x in L if x['date_source']]
        ex = av[0]['date_source'] + '  « ' + av[0]['libelle_brut'][:40] + ' »' if av else '—'
        print('  %-16s %10d %12d %12d   %s' % (ag, len(L), len(av), len(L) - len(av), ex))

    entete('P8A — LES CANDIDATS DOUBLONS, UN PAR UN')
    par = {}
    for ag in AGENCES:
        for x in tout[ag]:
            if x['sens'] in P8A:
                par.setdefault(S.cle(x), []).append(x)
    doubl = sorted([v for v in par.values() if len(v) > 1],
                   key=lambda v: -v[0]['montant_source'] * len(v))
    print('  %d clés vues plusieurs fois, %d occurrences, %s en jeu'
          % (len(doubl), sum(len(v) for v in doubl),
             euros(sum(v[0]['montant_source'] * (len(v) - 1) for v in doubl))))
    for v in doubl[:12]:
        x = v[0]
        print('   ×%-2d %-13s %-9s %-34s %12s   %s'
              % (len(v), x['agence'][:13], x['compte'], x['libelle_brut'][:34],
                 euros(x['montant_source']),
                 ' · '.join(sorted({'%s p.%s' % (y['crg_fichier'][:22], y['page']) for y in v}))[:44]))

    entete('P8A — LES INDÉTERMINABLES ET LES TIERS')
    for f in sorted({x['famille'] for ag in AGENCES for x in tout[ag]
                     if x['sens'] in ('SENS_INDETERMINABLE', 'TIERS_SAISIE',
                                      'TIERS_VERS_PROPRIETAIRE')}):
        L = [x for ag in AGENCES for x in tout[ag] if x['famille'] == f]
        print('  %-58s %5d  %14s' % (f[:58], len(L), euros(sum(x['montant_source'] for x in L))))
        for l, n in collections.Counter(re.sub(r'\d', '#', x['libelle_brut'])[:52]
                                        for x in L).most_common(4):
            print('        %4d  %s' % (n, l))

    entete('P8A — PROVENANCE : CHAQUE LIGNE PORTE-T-ELLE SA CHAÎNE ?')
    manques = collections.Counter()
    total = 0
    for ag in AGENCES:
        for x in tout[ag]:
            total += 1
            for c in S.CHAINE:
                if x.get(c) in (None, ''):
                    manques[c] += 1
    print('  %d lignes candidates · champs de provenance jamais renseignés :' % total)
    for c in S.CHAINE:
        n = manques.get(c, 0)
        if n:
            print('     %-22s absent sur %d/%d' % (c, n, total))
    for c in S.CHAINE:
        if not manques.get(c):
            print('     %-22s complet' % c)


if __name__ == '__main__':
    main()
