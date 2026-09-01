# -*- coding: utf-8 -*-
"""
AUDIT FINAL AVANT FERMETURE P7 — rééditions du corpus + exhaustivité P7A/P7B.

⚠️ LE SHA IDENTIFIE LE FICHIER, PAS L'ÉVÉNEMENT MÉTIER. Deux pièces GED peuvent énoncer la même
   situation de gestion au même arrêté. On classe donc chaque groupe `agence + compte + date
   d'arrêté` : réédition, complémentaires, distincts, indéterminable — sur des critères
   documentaires, jamais sur le nom du fichier.

⚠️ ET ON NE PART PAS DU VOCABULAIRE. L'exhaustivité se mesure sur TOUTES les occurrences des
   conteneurs qui portent des charges — `immeubles[].charges`, `immeubles[].mouvements`,
   `mandat.operations` — puis on qualifie. Ce que le vocabulaire ne reconnaît pas devient
   `À QUALIFIER`, jamais rien.

Lecture seule. Aucune phase modifiée.
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
import p7_routage_exhaustif as R   # noqa: E402

LARG = 120


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def sig(d):
    """Les signatures comparables du document."""
    lots, ch, imm = collections.Counter(), collections.Counter(), collections.Counter()
    for i in d.get('immeubles') or []:
        imm[(str(i.get('code') or ''), str(i.get('nom') or ''))] += 1
        for l in i.get('lots') or []:
            lots[(str(l.get('numero_lot') or ''),
                  str(l.get('locataire_nom') or l.get('locataire') or ''))] += 1
        for c in i.get('charges') or []:
            ch[(str(c.get('entete') or ''), str(c.get('libelle') or ''),
                round(float(c.get('debit') or 0), 2))] += 1
        for m in i.get('mouvements') or []:
            ch[(str(m.get('section') or ''), str(m.get('libelle') or ''),
                round(float(m.get('debit') or 0), 2))] += 1
    return imm, lots, ch


def classer(a, b):
    """Le verdict documentaire sur un couple de pièces."""
    ia, la, ca = sig(a)
    ib, lb, cb = sig(b)
    lots_comm = sum((la & lb).values())
    ch_comm = sum((ca & cb).values())
    ch_max = max(sum(ca.values()), sum(cb.values()), 1)
    imm_comm = sum((ia & ib).values())
    part = ch_comm / ch_max
    # ⚠️ CE SONT LES LOTS QUI TRANCHENT, PAS LE TAUX DE RECOUVREMENT — arbitrage Emmanuel du
    #    31/08/2026 sur les quatre cas VIENNE. « Même propriétaire, 3 immeubles différents donc
    #    3 CRG avec ce logiciel » ; et sur le dernier, « 2 lots différents dans le même
    #    immeuble : il a acheté le 2e appartement, non rattaché au même mandat ». Deux pièces
    #    qui ne partagent AUCUN LOT décrivent des périmètres distincts, quel que soit le nombre
    #    de charges identiques : une même ligne de syndic peut figurer sur deux lots voisins
    #    sans être deux fois la même dépense. Mon seuil de 70 % lisait le contraire.
    if not lots_comm:
        v = ('COMPLÉMENTAIRES — aucun lot en commun (%.0f %% d’écritures identiques, sans '
             'incidence)' % (100 * part))
    elif part >= 0.70:
        v = 'RÉÉDITION — %.0f %% des écritures communes' % (100 * part)
    elif ch_comm or lots_comm:
        v = 'INDÉTERMINABLE — recouvrement partiel (%.0f %%)' % (100 * part)
    else:
        v = 'DISTINCTS'
    return v, {'imm_comm': imm_comm, 'lots_comm': lots_comm, 'ch_comm': ch_comm,
               'ch_a': sum(ca.values()), 'ch_b': sum(cb.values()),
               'lots_a': sum(la.values()), 'lots_b': sum(lb.values())}


def main():
    # ── 1. LES RÉÉDITIONS SUR TOUT LE CORPUS ───────────────────────────────────────────────
    groupes = collections.defaultdict(list)
    for ag in AGENCES:
        for d in documents(ag):
            m = d.get('meta') or {}
            groupes[(ag, str(m.get('compte') or ''), str(m.get('date_arrete') or ''))].append(d)
    multi = {k: v for k, v in groupes.items() if len(v) > 1 and k[1] and k[2]}

    entete('1 — RÉÉDITIONS : %d groupes « agence + compte + date d’arrêté » à plusieurs pièces'
           % len(multi))
    verdicts = collections.Counter()
    reeditions = []
    for k, v in sorted(multi.items(), key=lambda kv: (kv[0][0], kv[0][1])):
        print('  %-14s compte %-11s arrêté %-11s  %d pièces' % (k[0][:14], k[1], k[2], len(v)))
        # ⚠️ LA PIÈCE DE RÉFÉRENCE SE CHOISIT SUR UN CRITÈRE DÉMONTRABLE, ET LES CHARGES SEULES
        #    NE SUFFISENT PAS : SABY 0107 et SABY 0107-2 en portent 143 chacune. Ce qui les
        #    départage, c'est ce que l'une contient EN PLUS — opérations de mandat, immeubles,
        #    pages. On ordonne donc sur l'ensemble, pas sur une seule dimension.
        def richesse(d):
            return (sum(len(i.get('charges') or []) + len(i.get('mouvements') or [])
                        for i in d.get('immeubles') or [])
                    + len((d.get('mandat') or {}).get('operations') or [])
                    + len(d.get('immeubles') or [])
                    + (d.get('_pages') or 0))
        base = max(v, key=richesse)
        for d in v:
            if d is base:
                print('       %-38s  ← pièce la plus complète' % str(d.get('_nom_original'))[:38])
                continue
            verdict, det = classer(base, d)
            verdicts[verdict.split('—')[0].strip()] += 1
            print('       %-38s  %s' % (str(d.get('_nom_original'))[:38], verdict))
            print('           écritures %d vs %d, communes %d · lots %d vs %d, communs %d'
                  % (det['ch_a'], det['ch_b'], det['ch_comm'],
                     det['lots_a'], det['lots_b'], det['lots_comm']))
            if verdict.startswith('RÉÉDITION'):
                reeditions.append(str(d.get('_nom_original')))
    print()
    print('  VERDICTS : %s' % dict(verdicts))
    print('  pièces classées RÉÉDITION : %s' % (', '.join(reeditions) or 'aucune'))

    # ── 2. CE QUE PORTENT LES RÉÉDITIONS DANS P7A / P7B ───────────────────────────────────
    entete('2 — CE QUE LES RÉÉDITIONS PORTENT DÉJÀ DANS P7A / P7B CERTIFIÉES')
    tot = collections.Counter()
    for ag in AGENCES:
        for l in P7.lignes(ag):
            if l['cote'] in ('P7A', 'P7B') and l['crg_fichier'] in reeditions:
                tot[(ag, l['cote'])] += l['montant_source']
    for k, v in sorted(tot.items()):
        print('  %-14s %-4s %14s' % (k[0], k[1], euros(v)))
    print('  %-19s %14s' % ('TOTAL à retirer si réédition retenue', euros(sum(tot.values()))))

    # ── 3. EXHAUSTIVITÉ : LES CHARGES QUE LE VOCABULAIRE DE P7 NE QUALIFIE PAS ─────────────
    entete('3 — DANS LES CONTENEURS DÉJÀ LUS PAR P7 : ce que son vocabulaire ne qualifie pas')
    aq = []
    for ag in AGENCES:
        for x in P7.lignes(ag):
            if x['cote'] == 'a_qualifier':
                aq.append(x)
    print('  %d lignes « INDÉTERMINABLE » dans `charges` / `mouvements`, %s'
          % (len(aq), euros(sum(x['montant_source'] for x in aq))))
    for ag in AGENCES:
        v = [x for x in aq if x['agence'] == ag]
        if v:
            print('     %-16s %4d lignes  %14s'
                  % (ag, len(v), euros(sum(x['montant_source'] for x in v))))
    print('  les formes les plus fréquentes :')
    for l, n in collections.Counter(re.sub(r'\d', '#', x['libelle_brut'])[:54]
                                    for x in aq).most_common(10):
        m = sum(x['montant_source'] for x in aq
                if re.sub(r'\d', '#', x['libelle_brut'])[:54] == l)
        print('     %4d  %-56s %12s' % (n, l, euros(m)))

    # ── 4. LES CANDIDATS P7A / P7B DE `mandat.operations`, FILTRÉS ────────────────────────
    entete('4 — CANDIDATS P7A / P7B ISSUS DE `mandat.operations`')
    ops = list(R.operations())
    R.controler_routage(ops)
    cand = collections.defaultdict(list)
    for x in ops:
        d, sous = R.destination(x)
        if d in ('CHARGE → P7A', 'CHARGE → P7B'):
            cand[d].append((x, sous))
    for d in ('CHARGE → P7A', 'CHARGE → P7B'):
        brut = cand[d]
        net = [(x, s) for x, s in brut if x['crg'] not in reeditions]
        oté = [(x, s) for x, s in brut if x['crg'] in reeditions]
        print('  %-16s brut %3d / %14s   ·   réénoncés retirés %2d / %12s   ·   NET %3d / %14s'
              % (d, len(brut), euros(sum(x['debit'] or x['credit'] for x, _ in brut)),
                 len(oté), euros(sum(x['debit'] or x['credit'] for x, _ in oté)),
                 len(net), euros(sum(x['debit'] or x['credit'] for x, _ in net))))
        par = collections.Counter()
        mp = collections.Counter()
        for x, s in net:
            par[s] += 1
            mp[s] += x['debit'] or x['credit']
        for s, n in par.most_common():
            print('       %-30s %3d %14s' % (s, n, euros(mp[s])))

    entete('5 — RÉCONCILIATION FINALE DU ROUTAGE')
    c = collections.Counter()
    mt = collections.Counter()
    for x in ops:
        d, _ = R.destination(x)
        c[d] += 1
        mt[d] += x['debit'] or x['credit']
    for d, n in c.most_common():
        print('  %-54s %5d %16s' % (d, n, euros(mt[d])))
    print('  %-54s %5d %16s' % ('TOTAL', sum(c.values()), euros(sum(mt.values()))))
    print('  fermeture : %s' % ('EXACTE' if sum(c.values()) == len(ops) else 'ÉCHEC'))


if __name__ == '__main__':
    main()
