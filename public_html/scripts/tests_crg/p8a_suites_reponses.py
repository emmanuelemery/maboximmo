# -*- coding: utf-8 -*-
"""
CE QUE LES RÉPONSES D'EMMANUEL OBLIGENT À VÉRIFIER — compte 01040000 puis tout le corpus.

⚠️ UNE EXPLICATION MÉTIER NE DEVIENT UNE RÈGLE QU'APRÈS AVOIR ÉTÉ CHERCHÉE PARTOUT. Emmanuel a
   expliqué la grammaire d'un compte ; ce programme mesure ce que cette grammaire implique sur
   les 458 CRG, sans rien modifier.

Cinq contrôles :
   ① LE CONTRÔLE QU'IL DEMANDE — les écritures de saisie s'équilibrent-elles ?
   ② « Honoraires de prestation REGIE EMERY » — combien P7B n'a jamais vus ?
   ③ Les lignes « / » sous une rubrique d'adresse — combien de fausses écritures ?
   ④ La taxe foncière échelonnée — la borne basse de P7A sous-compte-t-elle ?
   ⑤ Les loyers saisis (ATD) — P5 les a-t-il vus, lui qui ne lit pas non plus ce conteneur ?

Lecture seule. Aucune phase certifiée n'est touchée.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 100
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def ops_mandat():
    for ag in LYON:
        for d in documents(ag):
            m = d.get('meta') or {}
            for o in (d.get('mandat') or {}).get('operations') or []:
                yield ag, d, m, o


def main():
    tous = list(ops_mandat())

    # ── ① LE CONTRÔLE DEMANDÉ PAR EMMANUEL ────────────────────────────────────────────────
    entete('① « il faut vérifier que le montant est bien au débit également, sinon il y a un oubli »')
    SAISIE = re.compile(r"saisie|blocage", re.I)
    lot = [(ag, d, m, o) for ag, d, m, o in tous
           if SAISIE.search(str(o.get('libelle') or '')) or SAISIE.search(str(o.get('entete') or ''))]
    par_compte = collections.defaultdict(lambda: {'d': 0.0, 'c': 0.0, 'l': []})
    for ag, d, m, o in lot:
        k = (ag, str(m.get('compte') or ''))
        par_compte[k]['d'] += float(o.get('debit') or 0)
        par_compte[k]['c'] += float(o.get('credit') or 0)
        par_compte[k]['l'].append((m.get('periode_cle'), o))
    print('  %-14s %-10s %14s %14s %14s  %s'
          % ('PÉRIMÈTRE', 'compte', 'débit', 'crédit', 'écart', 'équilibré ?'))
    for (ag, c), v in sorted(par_compte.items(), key=lambda kv: -abs(kv[1]['d'] - kv[1]['c'])):
        ec = round(v['d'] - v['c'], 2)
        print('  %-14s %-10s %14s %14s %14s  %s'
              % (ag[:14], c, euros(v['d']), euros(v['c']), euros(ec),
                 'OUI' if abs(ec) < 0.01 else 'NON — écart de %s' % euros(abs(ec))))
        for per, o in sorted(v['l'], key=lambda x: str(x[0])):
            print('        %-9s [%-26s] %-44s D %12s  C %12s'
                  % (per, str(o.get('entete') or '')[:26], str(o.get('libelle') or '')[:44],
                     euros(o.get('debit')), euros(o.get('credit'))))

    # ── ② LES HONORAIRES DE LA RÉGIE, DANS LE COMPTE MANDANT ──────────────────────────────
    entete('② « prestations exceptionnelles facturées par la RÉGIE EMERY » — P7B ne les a jamais lues')
    HONO = re.compile(r"honoraires?|prestation|hono\s+compta", re.I)
    REGIE = re.compile(r"r[ée]gie\s+emery|loca[- ]immo", re.I)
    h = [(ag, m, o) for ag, d, m, o in tous
         if (HONO.search(str(o.get('entete') or '')) or HONO.search(str(o.get('libelle') or '')))
         and REGIE.search(str(o.get('libelle') or ''))]
    print('  %d écritures · %s' % (len(h), euros(sum(float(o.get('debit') or 0) for _, _, o in h))))
    for ag in LYON:
        v = [x for x in h if x[0] == ag]
        if v:
            print('     %-14s %4d écritures  %14s'
                  % (ag, len(v), euros(sum(float(o.get('debit') or 0) for _, _, o in v))))
    for l, n in collections.Counter(re.sub(r'\d', '#', str(o.get('libelle') or ''))[:56]
                                    for _, _, o in h).most_common(6):
        print('        %3d  %s' % (n, l))

    # ── ③ LES FAUSSES ÉCRITURES : ADRESSE D'IMMEUBLE SUR DEUX LIGNES ──────────────────────
    entete('③ « le nom de l’immeuble, puis son adresse — d’où une ligne vide » : de fausses écritures')
    VIDE = re.compile(r'^[\s/.\-]*$')
    f = [(ag, m, o) for ag, d, m, o in tous
         if VIDE.match(str(o.get('libelle') or ''))
         and not float(o.get('debit') or 0) and not float(o.get('credit') or 0)]
    print('  %d écritures sans libellé ni montant, sur %d opérations de mandat (%.1f %%)'
          % (len(f), len(tous), 100.0 * len(f) / max(len(tous), 1)))
    for ag in LYON:
        print('     %-14s %4d' % (ag, sum(1 for x in f if x[0] == ag)))
    print('  exemples de rubriques portées par ces lignes :')
    for e, n in collections.Counter(str(o.get('entete') or '')[:52] for _, _, o in f).most_common(6):
        print('        %3d  « %s »' % (n, e))

    # ── ④ LA TAXE FONCIÈRE ÉCHELONNÉE : LA BORNE BASSE SOUS-COMPTE-T-ELLE ? ───────────────
    entete('④ « paiement échelonné d’une taxe foncière » — 3 lignes identiques = 3 échéances, pas 1')
    # ⚠️ P7A COMPTE UNE CLÉ RÉPÉTÉE UNE SEULE FOIS EN BORNE BASSE, au motif qu'une dépense peut
    #    être réénoncée. Emmanuel dit l'inverse pour la taxe foncière : ce sont des ÉCHÉANCES
    #    distinctes. Si c'est vrai, la borne basse n'est pas prudente ici — elle est FAUSSE.
    TF = re.compile(r"taxe\s+fonci|imp[oô]t\s+foncier", re.I)
    for ag in AGENCES:
        lst = [x for x in P7.lignes(ag) if x['cote'] == 'P7A'
               and (TF.search(x['entete_source']) or TF.search(x['libelle_brut']))]
        if not lst:
            continue
        b = P7.bases(lst)
        print('  %-14s %4d occurrences · %d objets certains · %d clés répétées'
              % (ag, b['occurrences'], b['objets_certains'], b['candidats_doublons']))
        print('       borne basse %14s   borne haute %14s   écart %14s'
              % (euros(b['total_basse']), euros(b['total_haute']),
                 euros(b['total_haute'] - b['total_basse'])))

    # ── ⑤ LES LOYERS SAISIS : UN ENCAISSEMENT QUE P5 N'A PAS PU VOIR ──────────────────────
    entete('⑤ « le locataire paye son loyer mais c’est versé directement aux impôts »')
    atd = [(ag, m, o) for ag, d, m, o in tous if S.ATD.search(str(o.get('libelle') or ''))]
    ch_atd = [x for ag in AGENCES for x in S.candidats(ag)
              if x['conteneur'] != 'mandat.operations' and x['sens'] == 'TIERS_SAISIE']
    print('  dans `mandat.operations` (jamais lu par P5 ni P7) : %d écritures · %s'
          % (len(atd), euros(sum(float(o.get('debit') or 0) for _, _, o in atd))))
    print('  dans `immeubles[].charges` (lu par P7, écarté)     : %d écritures · %s'
          % (len(ch_atd), euros(sum(x['montant_source'] for x in ch_atd))))
    print()
    print('  ⚠️ P5 lit les APPELS et les ENCAISSEMENTS dans `immeubles[].lots`. Ces loyers-là')
    print('     sont imprimés ailleurs. La question de périmètre posée à P7A se pose donc')
    print('     AUSSI à P5 — et elle n’a pas encore été posée.')


if __name__ == '__main__':
    main()
