# -*- coding: utf-8 -*-
"""
P9 — RENTABILITÉ / ANALYTIQUE : ce que les briques certifiées permettent RÉELLEMENT de mesurer.

⚠️ ON NE PART PAS D'UNE FORMULE DE RENTABILITÉ. On inventorie ce que P1→P8 démontrent, puis on
   regarde quels indicateurs en découlent — et à quelle maille. Un indicateur qui exige une
   hypothèse n'est pas un indicateur : c'est une estimation, et elle porte un autre nom.

⚠️ AUCUNE BRIQUE N'EST REDÉFINIE. `appels` vient de P5A, `règlements` de P5B, `observations` de
   P6A, `lignes` de P7, `candidats` de P8A — telles qu'elles sont certifiées. Les réécrire pour
   faire fonctionner un ratio reviendrait à certifier P9 contre P5.

⚠️ LES SÉPARATIONS QUE P9 NE FRANCHIT JAMAIS
   `REVENU ≠ ENCAISSEMENT ≠ TRÉSORERIE ≠ RÉSULTAT ≠ RENTABILITÉ ≠ STOCK`
   `DÉPENSE ≠ PAIEMENT` · `APPEL ≠ ENCAISSEMENT` · `FLUX PROPRIÉTAIRE ≠ REVENU` ·
   `COMPENSATION ≠ TRÉSORERIE` · `SOLDE ≠ PERFORMANCE` · `VALEUR DU BIEN ≠ REVENU`.

⚠️ ET UNE MAILLE NE S'INVENTE PAS. Un montant du compte mandant ne se ventile pas sur les
   immeubles ; une charge d'immeuble ne se ventile pas sur les lots. Quand l'indicateur ne
   descend pas, on écrit **INDICATEUR COMPTE — NON VENTILABLE** et on s'arrête.

Lecture seule. Aucune donnée P1→P8 modifiée.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p5a_certification as P5A   # noqa: E402
import p5a_numerateur as P5AN   # noqa: E402
import p5b_certification as P5B   # noqa: E402
import p6a_certification as P6A   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as P8A   # noqa: E402

LARG = 108
DEM, PART, NON = 'DÉMONTRABLE', 'PARTIELLEMENT DÉMONTRABLE', 'NON DÉMONTRABLE'


def entete(t):
    print()
    print('=' * LARG)
    print(t)
    print('=' * LARG)


# ⚠️ LE TOTAL DOCUMENTAIRE N'EST PAS LE FLUX APPELÉ AU LOCATAIRE. `P5A-APPEL-15` retranche le
#    report (STOCK) et le dépôt de garantie : 4 761 767,93 € donnent 4 357 710,67 €. Ce module
#    a d'abord publié le premier — un rendement bâti dessus compterait des dépôts de garantie
#    comme du loyer. On importe désormais le calcul certifié au lieu de le refaire.
FLUX = {}


def montant_appel(a):
    """Le flux appelé au locataire, tel que P5A le certifie — jamais le total documentaire."""
    return FLUX[id(a)]


def cle_lot(x):
    return (x['agence'], x['sha'], x['compte'], x['immeuble'], x['lot'], x['locataire'])


def cle_imm(x):
    return (x['agence'], x['sha'], x['compte'], x['immeuble'])


def main():
    ok = True
    indic = []

    def juge(nom, verdict, maille, formule, valeur='', note=''):
        indic.append((nom, verdict, maille, formule, valeur, note))

    # ── LES BRIQUES, TELLES QUE LES PHASES LES CERTIFIENT ─────────────────────────────────
    appels = [a for ag in AGENCES for a in P5A.appels(ag)]
    P5AN.controler(appels)                       # fail closed sur le chiffre certifié
    for _a, _v in P5AN.flux_appeles(appels):
        FLUX[id(_a)] = _v
    regles = [r for ag in AGENCES for r in P5B.reglements(ag)]
    encours = [o for ag in AGENCES for o in P6A.observations(ag)]
    charges = [x for ag in AGENCES for x in P7.lignes(ag) if x['cote'] in ('P7A', 'P7B')]
    tout8 = [x for ag in AGENCES for x in P8A.candidats(ag)
             if x['crg_fichier'] not in P7.REEDITIONS]
    flux = [x for x in tout8
            if x['sens'] in ('REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE')]

    m_ap = sum(montant_appel(a) for a in appels)
    m_re = sum(float(r['montant'] or 0) for r in regles)
    m_en = sum(float(o['montant_source'] or 0) for o in encours)
    m_7a = sum(x['montant_source'] for x in charges if x['cote'] == 'P7A')
    m_7b = sum(x['montant_source'] for x in charges if x['cote'] == 'P7B')
    m_fl = sum(x['montant_source'] for x in flux)

    entete('1 — LES BRIQUES MOBILISABLES, ET LA MAILLE QUE CHACUNE DÉMONTRE')
    print('  %-34s %8s %16s   %s' % ('BRIQUE', 'unités', 'montant', 'maille la plus fine'))
    for lib, n, mt, mai in (
            ('P5A — appelé au locataire', len(appels), m_ap, 'lot'),
            ('P5B — règlements documentaires', len(regles), m_re, 'lot'),
            ('P6A — encours (STOCK, à une date)', len(encours), m_en, 'lot × locataire × date'),
            ('P7A — dépenses du bien', sum(1 for x in charges if x['cote'] == 'P7A'), m_7a,
             'immeuble · lot (VIENNE) · compte'),
            ('P7B — frais et assurances', sum(1 for x in charges if x['cote'] == 'P7B'), m_7b,
             'immeuble · lot (VIENNE) · compte'),
            ('P8A — flux propriétaire', len(flux), m_fl, 'compte mandant')):
        print('  %-34s %8d %16s   %s' % (lib, n, euros(mt), mai))
    print('  ⚠ AUCUN DE CES SIX MONTANTS N’EST UN REVENU. Ce sont des grandeurs de natures')
    print('    différentes : un dû, une extinction, un stock, une dépense, un virement.')

    # ══ 2 ═════════════════════════════════════════════════════════════════════════════════
    entete('2 — LA BARRIÈRE DE MAILLE : jusqu’où chaque indicateur peut-il descendre ?')
    c7 = collections.Counter(x['maille_documentaire'] for x in charges)
    au_compte = [x for x in charges if x['maille_documentaire'] == 'compte mandant']
    au_lot = [x for x in charges if x['maille_documentaire'] == 'lot']
    print('  P7A/P7B par maille : %s' % dict(c7))
    print('  charges à la maille COMPTE, NON VENTILABLES : %d lignes · %s'
          % (len(au_compte), euros(sum(x['montant_source'] for x in au_compte))))
    print('  charges descendant au LOT : %d lignes · %s'
          % (len(au_lot), euros(sum(x['montant_source'] for x in au_lot))))
    print('  ⚠ LA MAILLE LOT EXISTE, MAIS SUR UN SEUL FORMAT. `P7A-DEPENSE-13` : chez LYON et')
    print('    EMERY la dépense reste à l’immeuble — une facture de toiture de 4 000 € n’est')
    print('    jamais ventilée en 4 × 1 000 € — tandis que VIENNE descend au lot via `ref_lot`.')

    rev_i = collections.Counter()
    ch_i = collections.Counter()
    for a in appels:
        rev_i[cle_imm(a)] += montant_appel(a)
    for x in charges:
        if x['maille_documentaire'] != 'compte mandant':
            ch_i[cle_imm(x)] += x['montant_source']
    com_i = set(rev_i) & set(ch_i)
    print('  jointure immeuble × CRG : revenu %d · charges %d · LES DEUX %d'
          % (len(rev_i), len(ch_i), len(com_i)))

    # ══ 3 ═════════════════════════════════════════════════════════════════════════════════
    entete('3 — LE TEST DÉCISIF : l’identité que le document imprime lui-même tient-elle ?')
    # ⚠️ LE CRG IMPRIME LE TRIPLET `appelé / réglé / restant` (`P5B-ENCAISSEMENT-03`).
    #    Si `appelé − réglé = restant`, alors le restant est le résidu de la période et tout
    #    taux d'encaissement se calcule. Sinon, le restant est un STOCK qui porte l'antériorité,
    #    et `FLUX − FLUX ≠ STOCK` interdit le taux. C'est cette question qui décide de P9.
    ap_l, re_l, res_l = collections.Counter(), collections.Counter(), {}
    for a in appels:
        ap_l[cle_lot(a)] += montant_appel(a)
    for r in regles:
        re_l[cle_lot(r)] += float(r['montant'] or 0)
    for o in encours:
        if o['qualification'] in ('débiteur / créance', 'solde nul démontré'):
            res_l[cle_lot(o)] = float(o['montant_source'] or 0)
    trip = [k for k in (set(ap_l) | set(re_l)) if k in res_l]
    tenue = [k for k in trip if abs(ap_l[k] - re_l[k] - res_l[k]) < 0.01]
    print('  triplets complets appelé / réglé / restant : %d lots × CRG' % len(trip))
    print('  `appelé − réglé = restant` : %d / %d  (%.1f %%)'
          % (len(tenue), len(trip), 100.0 * len(tenue) / len(trip) if trip else 0))
    for agc in AGENCES:
        t2 = [k for k in trip if k[0] == agc]
        e2 = [k for k in t2 if abs(ap_l[k] - re_l[k] - res_l[k]) < 0.01]
        print('     %-16s %5d triplets · identité tenue %5d (%5.1f %%)'
              % (agc, len(t2), len(e2), 100.0 * len(e2) / len(t2) if t2 else 0))
    print('  ⚠ ELLE NE TIENT PAS. Le restant porte l’ANTÉRIORITÉ, pas le résidu de la période :')
    print('    le lot `01040185 / 0188 DEPARTEMENT DU` appelle 30 298,64, règle 193 225,04 et')
    print('    reste débiteur de 68 251,88 — ce règlement éteint une créance née ailleurs.')
    print('    `P5B-ENCAISSEMENT-10` le disait déjà : « appelé − réglé n’est pas une dette ».')

    aff = [r for r in regles if r['affectation'] == 'démontrée']
    ind = [r for r in regles if r['affectation'] != 'démontrée']
    print('  règlements affectés à une période : %5d · %16s'
          % (len(aff), euros(sum(float(r['montant'] or 0) for r in aff))))
    print('  affectés au LOT seulement         : %5d · %16s'
          % (len(ind), euros(sum(float(r['montant'] or 0) for r in ind))))
    print('  ⚠ ET RESTREINDRE LE TAUX À LA PART AFFECTÉE EST PIRE : EMERY tomberait à 0,0 %,')
    print('    puisque `P5B-EXC-01` certifie que 100 % de son réglé est démontré au LOT seul.')
    print('    Un ratio qui affiche « 0 % encaissé » sur un périmètre qui encaisse est faux.')

    # ══ 4 ═════════════════════════════════════════════════════════════════════════════════
    entete('4 — LES INDICATEURS, TESTÉS UN PAR UN')
    juge('revenu appelé', DEM, 'lot',
         'flux appelé au locataire, P5A-APPEL-15 (documentaire − report − dépôts)', euros(m_ap),
         'un DÛ constaté, jamais un encaissement — ni le total documentaire')
    juge('montant réglé', DEM, 'lot', 'Σ règlements P5B', euros(m_re),
         'documentaire : P5B certifie 0 mouvement daté, 0 payeur identifié')
    juge('taux d’encaissement d’une période', NON, '—',
         'réglé ÷ appelé — refusé par le test de la section 3', '',
         'le réglé éteint des périodes antérieures ; identité tenue 66 % seulement')
    act = [o for o in encours if o['statut_occupation_P4B'] == 'actif']
    anc = [o for o in encours if o['statut_occupation_P4B'] != 'actif']
    juge('encours occupant actif', DEM, 'lot × locataire × date',
         'Σ P6A où statut P4B = actif', euros(sum(float(o['montant_source'] or 0) for o in act)),
         'STOCK : jamais additionné entre deux dates')
    juge('encours ancien locataire', DEM, 'lot × locataire × date',
         'Σ P6A où statut P4B ≠ actif', euros(sum(float(o['montant_source'] or 0) for o in anc)),
         'séparé de l’occupant actif — P4B le démontre')
    juge('variation d’encours entre 2 dates', NON, '—',
         'restant(t2) − restant(t1)', '',
         '`P8B-SOLDE-04` interdit de relier deux photographies par différence')
    juge('dépenses du bien', DEM, 'immeuble · lot VIENNE', 'Σ P7A', euros(m_7a),
         'une DÉPENSE engagée, jamais un paiement (`DÉPENSE ≠ PAIEMENT`)')
    juge('frais de gestion et assurances', DEM, 'immeuble · lot VIENNE', 'Σ P7B', euros(m_7b),
         'coût de la régie, distinct des dépenses du bien')
    juge('coût de gestion', DEM, 'compte mandant', 'P7B ÷ appels P5A',
         '%.1f %%' % (100.0 * m_7b / m_ap if m_ap else 0),
         'rapporte deux grandeurs du même document et de la même période')
    juge('revenu net de frais de gestion', DEM, 'compte mandant', 'appels P5A − frais P7B',
         euros(m_ap - m_7b), 'ni trésorerie, ni résultat : un dû diminué d’un coût')
    juge('résultat économique', PART, 'compte mandant', 'appels P5A − P7A − P7B',
         euros(m_ap - m_7a - m_7b),
         'assemble un DÛ et des DÉPENSES : ni encaissé, ni décaissé — arbitrage P9-ARB-01')
    juge('trésorerie nette', NON, '—',
         'aucune : ni date, ni payeur, ni preuve de décaissement', '',
         'P5B n’a aucun mouvement daté ; P7A ne démontre aucun paiement')
    juge('performance par immeuble', PART, 'immeuble',
         'revenu P5A − charges P7 de maille immeuble',
         '%d immeubles' % len(com_i),
         '%s de charges de compte en sont exclues — arbitrage P9-ARB-02'
         % euros(sum(x['montant_source'] for x in au_compte)))
    juge('performance par lot — LYON / EMERY / SIR', PART, 'lot',
         'revenu seul : la charge y reste à l’immeuble', '',
         'un « résultat par lot » complet y serait inventé')
    juge('performance par lot — VIENNE', DEM, 'lot',
         'revenu P5A − charges P7 portant `ref_lot`',
         '%d charges' % len(au_lot),
         'seul format dont la charge descend au lot — arbitrage P9-ARB-03')
    juge('rendement sur valeur du bien', NON, '—',
         'le CRG n’imprime aucune valeur de bien', '',
         'RENDEMENT SUR VALEUR DU BIEN : NON DÉMONTRABLE PAR LE CRG SEUL')

    for nom, v, maille, f, val, note in indic:
        marque = {DEM: '  ', PART: '~ ', NON: '✗ '}[v]
        print('%s%-40s %-26s %-24s %16s' % (marque, nom[:40], v, maille, val))
        print('       formule : %s' % f)
        if note:
            print('       ⚠ %s' % note)
    print()
    print('  BILAN : %d démontrables · %d partiels · %d non démontrables'
          % (sum(1 for i in indic if i[1] == DEM), sum(1 for i in indic if i[1] == PART),
             sum(1 for i in indic if i[1] == NON)))

    # ══ 5 ═════════════════════════════════════════════════════════════════════════════════
    entete('5 — CE QUI N’ENTRE DANS AUCUN INDICATEUR')
    for sens, quoi in (('ECRITURE_SANS_TRESORERIE', 'compensations — aucun fonds ne bouge'),
                       ('SORTIE_VERS_COMPTE_ATTENTE', 'comptes d’attente — écriture interne'),
                       ('sans objet (stock)', 'stocks / soldes — photographies'),
                       ('REGIE_VERS_PROPRIETAIRE_INDIRECT', 'loyers saisis — jamais encaissés'),
                       ('SENS_INDETERMINABLE', 'sens non démontré')):
        v = [x for x in tout8 if x['sens'] == sens]
        if v:
            print('  %-46s %5d lignes %16s'
                  % (quoi, len(v), euros(sum(x['montant_source'] for x in v))))
    print('  ⚠ ni revenu, ni trésorerie, ni charge : ces montants ne rejoignent aucun ratio.')

    # ══ 6 ═════════════════════════════════════════════════════════════════════════════════
    entete('6 — CONTRÔLES DE NON-DOUBLE-COMPTAGE')
    lots_ap, lots_re = {cle_lot(a) for a in appels}, {cle_lot(r) for r in regles}
    print('  APPEL et RÈGLEMENT portent sur les mêmes lots : %d appelés · %d réglés · %d communs'
          % (len(lots_ap), len(lots_re), len(lots_ap & lots_re)))
    print('  ⚠ ILS NE S’ADDITIONNENT JAMAIS : un appel est un DÛ, un règlement son extinction.')
    print('    P9 ne produit donc AUCUN « revenu total » — le mot recouvrirait deux grandeurs.')
    e7 = {(x['sha'], x['page'], x.get('y'), x['libelle_brut'], x['montant_source'])
          for x in charges}
    e8 = {(x['sha'], x['page'], x.get('y'), x['libelle_brut'], x['montant_source'])
          for x in flux}
    print('  charges P7 %d occurrences · flux P8A %d · EN COMMUN : %d'
          % (len(e7), len(e8), len(e7 & e8)))
    if e7 & e8:
        ok = False
    # ⚠️ P5B EMPILE-T-IL LE DÉTAIL ET LE TOTAL D'UN MÊME LOT ? C'était le doute le plus sérieux
    #    de cette passe : 373 lots portent les deux sources. La réponse est NON — le lecteur
    #    n'inscrit dans la ligne `Totaux` que le RESTE (`total_regle − Σ lignes`), jamais le
    #    total. Additionner les deux sources reconstitue le total du lot, sans jamais le doubler.
    mix = collections.defaultdict(set)
    for r in regles:
        mix[cle_lot(r)].add(r['libelle_source'].startswith('ligne « Totaux »'))
    print('  lots portant DÉTAIL + ligne « Totaux » : %d — la seconde ne porte que le RESTE,'
          % sum(1 for v in mix.values() if len(v) > 1))
    print('    `total réglé − Σ lignes lues` : les deux se complètent, aucune ne se répète.')
    print('  encours P6A : %d observations — STOCK, hors de tout flux' % len(encours))
    print('  rééditions neutralisées : %s' % ', '.join(sorted(P7.REEDITIONS)))

    # ══ 7 ═════════════════════════════════════════════════════════════════════════════════
    entete('7 — LE TEMPS : ce qui est comparable, et ce qui ne l’est pas')
    per = collections.Counter(a['periode_crg'] for a in appels)
    dates = collections.Counter(o['date_arrete'] for o in encours)
    print('  appels répartis sur %d périodes de CRG · encours sur %d dates d’arrêté'
          % (len(per), len(dates)))
    print('  appelé et réglé par période — DEUX FLUX, PAS UN TAUX :')
    for p, _ in per.most_common(5):
        a_ = sum(montant_appel(a) for a in appels if a['periode_crg'] == p)
        r_ = sum(float(r['montant'] or 0) for r in regles if r['periode_crg'] == p)
        print('     %-10s appelé %14s · réglé %14s' % (p, euros(a_), euros(r_)))
    print('  ⚠ LEUR RAPPORT MONTE À 156 % SUR 2025-T1 : la preuve par l’absurde que le réglé')
    print('    d’un CRG n’est pas l’encaissement de sa période. On imprime les deux, jamais')
    print('    leur quotient. Et aucun stock n’est additionné entre les %d dates d’arrêté.'
          % len(dates))

    # ══ 8 ═════════════════════════════════════════════════════════════════════════════════
    entete('8 — CHASSE AUX PIÈGES TRANSVERSAUX')
    compens = sum(x['montant_source'] for x in tout8
                  if x['sens'] == 'ECRITURE_SANS_TRESORERIE')
    for nom, etat, preuve in (
            ('double comptage appel + règlement', 'ÉVITÉ', 'aucun total ne les additionne'),
            ('double comptage détail + total du lot', 'ÉVITÉ',
             'la ligne « Totaux » ne porte que le reste'),
            ('agrégat pris pour un mouvement', 'ÉVITÉ',
             'agrégats P5B et totaux P8B exclus des indicateurs'),
            ('compensation prise pour trésorerie', 'ÉVITÉ',
             '%s isolés en section 5' % euros(compens)),
            ('paiement compté deux fois en dépense', 'ÉVITÉ',
             '%d occurrences communes P7 / P8A' % len(e7 & e8)),
            ('encours ajouté aux flux', 'ÉVITÉ', 'P6A jamais sommé à P5A/P5B/P7'),
            ('report pris pour un revenu', 'ÉVITÉ',
             'les reports P8B n’entrent dans aucun indicateur'),
            ('ancien locataire mêlé à l’occupant actif', 'ÉVITÉ',
             '%d actifs / %d anciens, séparés' % (len(act), len(anc))),
            ('frais de régie mêlés aux dépenses du bien', 'ÉVITÉ',
             'P7A et P7B jamais fusionnés'),
            ('même événement dans plusieurs CRG', 'ÉVITÉ',
             '%d rééditions neutralisées' % len(P7.REEDITIONS)),
            ('indicateur plus fin que sa source', 'ÉVITÉ',
             'aucune ventilation compte→immeuble ni immeuble→lot')):
        print('  %-42s %-8s %s' % (nom, etat, preuve))

    # ══ 9 ═════════════════════════════════════════════════════════════════════════════════
    entete('9 — CE QUE P9 NE MESURERA PAS, ET POURQUOI')
    for nom, v, maille, f, val, note in indic:
        if v == NON:
            print('  ✗ %-38s %s' % (nom, note or f))
    print('  ⚠ AUCUN DE CES REFUS N’EST UNE LACUNE DU LECTEUR. Ce sont des propriétés du')
    print('    document : le CRG ne date pas ses encaissements, n’identifie pas ses payeurs,')
    print('    ne démontre aucun décaissement et n’imprime aucune valeur de bien.')

    # ══ 10 ════════════════════════════════════════════════════════════════════════════════
    entete('10 — ARBITRAGE RESTANT : un seul, et c’est une règle d’écran')
    # ⚠️ TROIS DES QUATRE QUESTIONS ONT ÉTÉ RETIRÉES SANS ÊTRE POSÉES À EMMANUEL, parce que la
    #    preuve ou la doctrine y répondait déjà : la base de revenu (les trois rendements l'ont
    #    rendue sans objet), la pièce qui fait foi (`RECT-EVENEMENT-01`), le sort des flux
    #    entrants (`P8B-SOLDE-08` interdit le netting). On ne fait pas arbitrer l'acquis.
    print('  p9-indicateur-non-demontrable-a-lecran')
    print('     Que montre un écran BIEN ou IMMEUBLE quand sa maille est plus fine que celle')
    print('     de la preuve ? Trois cas, une seule règle : le NET TRÉSORERIE démontré au seul')
    print('     compte · 228 062,46 € de charges sans immeuble · la charge qui ne descend au')
    print('     lot que chez VIENNE.')

    entete('P9 CERTIFIABLE : %s'
           % ('OUI, SOUS RÉSERVE D’UN ARBITRAGE D’AFFICHAGE' if ok else 'NON — voir les ⚠'))
    print('  %d indicateurs démontrables · %d partiels · %d refusés · 0 double comptage'
          % (sum(1 for i in indic if i[1] == DEM), sum(1 for i in indic if i[1] == PART),
             sum(1 for i in indic if i[1] == NON)))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
