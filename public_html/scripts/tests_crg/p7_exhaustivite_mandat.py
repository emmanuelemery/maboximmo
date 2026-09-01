# -*- coding: utf-8 -*-
"""
EXHAUSTIVITÉ P7A ET P7B — le même contrôle des deux côtés, et le routage des 1 049 opérations.

⚠️ SOURCE TECHNIQUE ≠ NATURE MÉTIER. `immeubles[].charges` et `mandat.operations` sont deux
   SOURCES, pas deux catégories. Une ligne se classe par ce qu'elle est — dépense P7A, frais
   P7B, paiement, flux propriétaire P8A, stock P8B, compensation — jamais par le conteneur où
   le lecteur l'a trouvée. C'est le défaut de conception que ce contrôle doit clore.

⚠️ ET L'ORDRE DE LECTURE EST UNE RÈGLE. ① fonction syntaxique de la ligne ② nature de
   l'écriture ③ seulement ensuite, les mots de son OBJET. `REGIE EMERY LOCA-IMMO GESTION DES
   PRETS` : « PRETS » décrit l'objet de la prestation, pas la nature de l'écriture.

⚠️ ENFIN, L'ABSENCE DE MARQUE DE RÈGLEMENT NE FAIT PAS UNE DÉPENSE. Il faut démontrer ① que la
   ligne exprime bien une charge ② qu'elle n'est pas déjà représentée ailleurs.

Lecture seule. Ni P7A ni P7B ne sont modifiées, P8A n'est pas reprise.
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

LARG = 122
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')

PAIEMENT = re.compile(r"\bvirt\b|\bvrt\b|\bvir\b|virement|pr[ée]l[èe]v|prelvt|r[èe]glement|"
                      r"ch[èe]que|cheque|\bavis\s+n|fournisseurs?\s+divers|"
                      r"\bau\s+\d{1,2}[./]\d{1,2}[./]\d{2,4}", re.I)

# ⚠️ CE QUI N'EXPRIME PAS UNE CHARGE, MÊME SOUS UNE RUBRIQUE DE CHARGE. Une indemnité perçue,
#    un trop-perçu remboursé, une compensation : le mot de la rubrique dit la famille, pas le
#    sens. Emmanuel : « l'indemnité est reversée au locataire », « la compensation est une
#    écriture sans mouvement de fonds ».
PAS_UNE_CHARGE = re.compile(r"indemnit|remb\w*\s+\w*\s*trop\s+pay|trop\s+pay|compensation|"
                            r"compensat|\bavoir\b|restitution|rejet", re.I)


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
                fam, sens, _ = S.famille(e, l)
                yield {'agence': ag, 'compte': str(m.get('compte') or ''),
                       'periode': m.get('periode_cle'), 'crg': d.get('_nom_original'),
                       'page': o.get('page'), 'entete': e, 'libelle': l,
                       'debit': round(float(o.get('debit') or 0), 2),
                       'credit': round(float(o.get('credit') or 0), 2),
                       'nature7': nat, 'cote7': cote, 'fam8': fam, 'sens8': sens,
                       'paiement': bool(PAIEMENT.search(l)),
                       'charge': not PAS_UNE_CHARGE.search(l) and not PAS_UNE_CHARGE.search(e)}


def index_p7(cote):
    idx = collections.defaultdict(list)
    for ag in AGENCES:
        for l in P7.lignes(ag):
            if l['cote'] == cote:
                idx[(ag, l['compte'])].append(l)
    return idx


def abc(lignes, idx, titre):
    """A = déjà représentée · B = nouvelle charge démontrée · C = indéterminable."""
    res = []
    for x in lignes:
        if not x['charge']:
            res.append((x, 'HORS', 'n’exprime pas une charge (indemnité, trop-perçu, compensation)'))
            continue
        if x['paiement']:
            res.append((x, 'HORS', 'porte une marque de règlement — FRAIS ≠ PAIEMENT DU FRAIS'))
            continue
        voisins = idx.get((x['agence'], x['compte']), [])
        m = round(x['debit'] or x['credit'], 2)
        # ⚠️ PREUVE POSITIVE EXIGÉE : même compte ET même période ET même montant.
        exact = [l for l in voisins
                 if round(l['montant_source'], 2) == m and l['periode_crg'] == x['periode']]
        memem = [l for l in voisins if round(l['montant_source'], 2) == m]
        if exact:
            res.append((x, 'A', 'même compte + même période + même montant'))
        elif memem:
            res.append((x, 'C', 'même montant, période différente — ni preuve ni réfutation'))
        else:
            res.append((x, 'B', 'aucune ligne rapprochable sur ce compte'))
    entete(titre)
    for c in ('A', 'B', 'C', 'HORS'):
        v = [r for r in res if r[1] == c]
        print('  %-5s %3d lignes  %14s' % (c, len(v), euros(sum(r[0]['debit'] or r[0]['credit']
                                                                for r in v))))
    return res


def main():
    tout = list(operations())
    print('  %d opérations de mandat sur les 458 CRG.\n' % len(tout))

    # ── P7B ────────────────────────────────────────────────────────────────────────────────
    cand7b = [x for x in tout if x['cote7'] == 'P7B'
              or x['fam8'].startswith('PRESTATION FACTURÉE PAR LA RÉGIE')]
    r7b = abc(cand7b, index_p7('P7B'), 'P7B — %d candidates' % len(cand7b))
    entete('P7B — LE DÉTAIL DES B, PAR FAMILLE')
    b7b = [r for r in r7b if r[1] == 'B']
    par = collections.defaultdict(list)
    for x, _, _ in b7b:
        cle = ('RÉGIE EMERY' if re.search(r"r[ée]gie\s+emery", x['libelle'], re.I) else
               'CHIFFRES & CONSEILS' if re.search(r"chiffres", x['libelle'], re.I) else
               'SOFRAS CONSEIL' if re.search(r"sofras", x['libelle'], re.I) else
               'L2E ÉTUDES EXPERTISES' if re.search(r"\bl2e\b", x['libelle'], re.I) else
               'autre')
        par[cle].append(x)
    for k, v in sorted(par.items(), key=lambda kv: -sum(y['debit'] or y['credit'] for y in kv[1])):
        print('  %-26s %3d  %14s' % (k, len(v), euros(sum(y['debit'] or y['credit'] for y in v))))

    # ── P7A ────────────────────────────────────────────────────────────────────────────────
    cand7a = [x for x in tout if x['cote7'] == 'P7A'
              and not x['fam8'].startswith('PRESTATION FACTURÉE PAR LA RÉGIE')]
    r7a = abc(cand7a, index_p7('P7A'), 'P7A — %d candidates' % len(cand7a))
    entete('P7A — LE DÉTAIL PAR FAMILLE (A / B / C / HORS)')
    print('  %-46s %5s %5s %5s %5s   %14s' % ('FAMILLE P7A LUE', 'A', 'B', 'C', 'HORS', 'montant B'))
    fam = collections.defaultdict(lambda: collections.Counter())
    mb = collections.Counter()
    for x, c, _ in r7a:
        fam[x['nature7'][:46]][c] += 1
        if c == 'B':
            mb[x['nature7'][:46]] += x['debit'] or x['credit']
    for n in sorted(fam, key=lambda k: -mb[k]):
        c = fam[n]
        print('  %-46s %5d %5d %5d %5d   %14s'
              % (n, c['A'], c['B'], c['C'], c['HORS'], euros(mb[n])))
    print()
    print('  LES B DE P7A, UNE PAR UNE :')
    for x, c, _ in sorted([r for r in r7a if r[1] == 'B'],
                          key=lambda r: -(r[0]['debit'] or r[0]['credit'])):
        print('     %-13s %-9s %-8s %10s [%-22s] %s'
              % (x['agence'][:13], x['compte'], x['periode'],
                 euros(x['debit'] or x['credit']), x['entete'][:22], x['libelle'][:44]))

    # ── LE ROUTAGE COMPLET ─────────────────────────────────────────────────────────────────
    entete('LES 1 049 OPÉRATIONS SONT-ELLES TOUTES ROUTÉES ?')
    P8A = ('REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE',
           'REGIE_VERS_PROPRIETAIRE_INDIRECT', 'TIERS_VERS_PROPRIETAIRE',
           'PROPRIETAIRE_VERS_TIERS', 'REGIE_VERS_LOCATAIRE')
    routes = collections.Counter()
    montants = collections.Counter()
    dansP7 = {id(x) for x, c, _ in r7a + r7b}
    for x in tout:
        if x['fam8'] == S.STOCK_P8B:
            r = 'STOCK PROPRIÉTAIRE → P8B'
        elif x['sens8'] == 'ECRITURE_SANS_TRESORERIE':
            r = 'COMPENSATION — écriture sans trésorerie'
        elif x['sens8'] in P8A:
            r = 'FLUX PROPRIÉTAIRE → P8A'
        elif id(x) in dansP7:
            r = 'FAMILLE P7A / P7B — objet du présent contrôle'
        elif x['sens8'] == 'SENS_INDETERMINABLE':
            r = 'INDÉTERMINABLE — assumé'
        else:
            r = 'AUTRE DÉPENSE DU COMPTE MANDANT — hors P7/P8A'
        routes[r] += 1
        montants[r] += x['debit'] or x['credit']
    for r, n in routes.most_common():
        print('  %-52s %5d %16s' % (r, n, euros(montants[r])))
    print('  %-52s %5d %16s' % ('TOTAL', sum(routes.values()),
                                euros(sum(montants.values()))))
    print()
    print('  aucune opération sans catégorie : %s'
          % ('OUI' if sum(routes.values()) == len(tout) else 'NON'))


if __name__ == '__main__':
    main()
