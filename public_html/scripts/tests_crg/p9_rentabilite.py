# -*- coding: utf-8 -*-
"""
P9 — RENTABILITÉ : BIEN / LOT → IMMEUBLE → COMPTE MANDANT → PROPRIÉTAIRE GLOBAL.

Trois rendements, à chacun des trois niveaux :
   A · THÉORIQUE       loyers appelés        ÷ valeur de référence
   B · BRUT ENCAISSÉ   loyers encaissés      ÷ valeur de référence
   C · NET TRÉSORERIE  sommes reversées      ÷ valeur de référence

⚠️ ON N'ADDITIONNE JAMAIS DES POURCENTAGES. À chaque niveau on réagrège les numérateurs ET le
   dénominateur, puis on recalcule le ratio. Une moyenne de taux pondérerait chaque bien à
   égalité, quelle que soit sa valeur : le résultat ne serait le rendement de personne.

⚠️ `RENDEMENT BRUT ENCAISSÉ ≠ TAUX DE RECOUVREMENT`. Un encaissement observé sur une période
   peut solder une créance antérieure — la passe précédente l'a démontré (identité
   `appelé − réglé = restant` tenue 762 fois sur 1 155). On ne l'impose donc nulle part ici.

⚠️ INTERDICTION DE VENTILER. Un flux démontré au compte mandant ne descend pas à l'immeuble ni
   au bien par prorata de loyer, de surface ou de valeur. Sans règle métier décidée, on écrit
   `NON DÉMONTRABLE` et on s'arrête.

⚠️ `PROPRIÉTAIRE ≠ COMPTE MANDANT`. Un propriétaire peut porter plusieurs comptes ; la vue
   globale les consolide sous une seule identité démontrée, sans jamais fusionner deux
   personnes ou sociétés distinctes.

⚠️ LA VALEUR DE RÉFÉRENCE N'EST PAS DANS LE CRG. Elle ne s'invente pas. Ce module certifie les
   NUMÉRATEURS et la RÈGLE D'AGRÉGATION ; le dénominateur est une donnée que MBI fournit.

Lecture seule. Aucune donnée P1→P8 modifiée.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p2_certification as P2   # noqa: E402
import p5a_certification as P5A   # noqa: E402
import p5a_numerateur as P5AN   # noqa: E402
import p5b_certification as P5B   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as P8A   # noqa: E402

LARG = 108
# ⚠️ QUATRE BARREAUX, PAS TROIS. Le COMPTE MANDANT est un niveau à part entière : c'est là que
#    P8A démontre les reversements. L'omettre renvoyait le net trésorerie « au propriétaire »
#    alors que la preuve est un cran plus bas — un renvoi doit nommer le PREMIER niveau où
#    l'indicateur est réellement démontrable, jamais le plus haut.
NIVEAUX = ('BIEN / LOT', 'IMMEUBLE', 'COMPTE MANDANT', 'PROPRIÉTAIRE GLOBAL')
OUI, NON = 'DÉMONTRABLE', 'NON DÉMONTRABLE'


def entete(t):
    print()
    print('=' * LARG)
    print(t)
    print('=' * LARG)


# ⚠️ LE NUMÉRATEUR N'EST PAS LE TOTAL DOCUMENTAIRE. `P5A-APPEL-15` retranche le report
#    (STOCK) et le dépôt de garantie (mouvement propriétaire) : 4 761 767,93 € documentaires
#    donnent 4 357 710,67 € réellement appelés au locataire. Bâtir un rendement théorique sur
#    le premier compterait des dépôts de garantie comme du loyer. P9 importe donc le calcul
#    certifié — il ne le refait pas.
FLUX = {}


def montant_appel(a):
    return FLUX[id(a)]


# ── LES QUATRE IDENTITÉS, TELLES QUE P2 ET P3 LES CERTIFIENT ──────────────────────────────
def k_prop(x):
    return P2.plat(x.get('proprietaire'))


def k_cpte(x):
    return (k_prop(x), str(x.get('compte') or ''))


def k_imm(x):
    return k_cpte(x) + (str(x.get('immeuble') or ''),)


def k_lot(x):
    return k_imm(x) + (str(x.get('lot') or ''),)


def main():
    ok = True

    # ⚠️ P5A ET P5B NE CONNAISSENT PAS LES RÉÉDITIONS — elles lisent chaque pièce de la GED.
    #    Consolider sans les neutraliser compterait deux fois SCI FOCH et MONSIEUR SABY YVES :
    #    deux pièces y énoncent la MÊME situation (même compte, même date d'arrêté) et portent
    #    les mêmes charges au centime. On applique donc la neutralisation de P7 — et on garde
    #    la trace de ce qu'elle retient, car la pièce écartée liste un trimestre d'appels
    #    entier là où l'autre s'arrête à la fin de période (arbitrage `p9-piece-qui-fait-foi`).
    # ⚠️ LES LECTEURS DÉDOUBLONNENT DÉSORMAIS À LA SOURCE. La RECTIFICATION CERTIFIÉE du
    #    01/09/2026 a porté la règle dans `P5A.appels()`, `P5B.reglements()` et
    #    `P6A.observations()` : P9 ne déduplique plus rien de son côté — il porterait sinon
    #    une seconde copie de la doctrine, avec le risque d'en diverger un jour.
    appels = [a for ag in AGENCES for a in P5A.appels(ag)]
    regles = [r for ag in AGENCES for r in P5B.reglements(ag)]
    P5AN.controler(appels)                            # fail closed sur le chiffre certifié
    for a, v in P5AN.flux_appeles(appels):
        FLUX[id(a)] = v
    charges = [x for ag in AGENCES for x in P7.lignes(ag) if x['cote'] in ('P7A', 'P7B')]
    tout8 = [x for ag in AGENCES for x in P8A.candidats(ag)
             if x['crg_fichier'] not in P7.REEDITIONS]
    verses = [x for x in tout8 if x['sens'] == 'REGIE_VERS_PROPRIETAIRE']
    recus = [x for x in tout8 if x['sens'] == 'PROPRIETAIRE_VERS_REGIE']

    # ══ 1 ═════════════════════════════════════════════════════════════════════════════════
    entete('1 — LA CHAÎNE : PROPRIÉTAIRE → COMPTE → IMMEUBLE → BIEN, ET RETOUR')
    chaine = collections.defaultdict(lambda: collections.defaultdict(
        lambda: collections.defaultdict(set)))
    for a in appels:
        chaine[k_prop(a)][str(a.get('compte') or '')][str(a.get('immeuble') or '')].add(
            str(a.get('lot') or ''))
    props = sorted(chaine)
    n_cpt = sum(len(v) for v in chaine.values())
    n_imm = sum(len(w) for v in chaine.values() for w in v.values())
    n_lot = sum(len(z) for v in chaine.values() for w in v.values() for z in w.values())
    print('  %d propriétaires → %d comptes → %d immeubles → %d biens'
          % (len(props), n_cpt, n_imm, n_lot))
    multi = {p: sorted(v) for p, v in chaine.items() if len(v) > 1}
    print('  propriétaires portant PLUSIEURS comptes : %d — consolidés, jamais fusionnés entre eux'
          % len(multi))
    for p in sorted(multi)[:4]:
        print('     %-42s %s' % (p[:42], ', '.join(multi[p])))
    # ⚠️ LA REMONTÉE DOIT ÊTRE EXACTE, PAS SEULEMENT LA DESCENTE. Si un bien remontait vers deux
    #    propriétaires, la consolidation globale le compterait deux fois.
    remonte = collections.defaultdict(set)
    for a in appels:
        remonte[k_lot(a)[1:]].add(k_prop(a))
    ambigus = {k: v for k, v in remonte.items() if len(v) > 1}
    print('  remontée bien → propriétaire : %d biens, dont %d ambigus'
          % (len(remonte), len(ambigus)))
    if ambigus:
        ok = False
        for k, v in list(ambigus.items())[:3]:
            print('     ⚠ %s → %s' % (k, v))

    # ══ 2 ═════════════════════════════════════════════════════════════════════════════════
    entete('2 — LES TROIS NUMÉRATEURS, ET LA MAILLE LA PLUS FINE QUE CHACUN DÉMONTRE')
    # ⚠️ LE NOM DU RENDEMENT N'EST PAS CELUI DE SON NUMÉRATEUR. « Loyers appelés » est une
    #    somme d'euros ; « théorique » est un taux. Les confondre à l'écran ferait lire un
    #    rendement là où il n'y a qu'un montant.
    num = (
        ('A · loyers appelés', appels, montant_appel, 'lot', 'P5A', 'théorique'),
        ('B · loyers encaissés', regles, lambda r: float(r['montant'] or 0), 'lot', 'P5B',
         'brut encaissé'),
        ('C · sommes reversées', verses, lambda x: x['montant_source'], 'compte mandant', 'P8A',
         'net trésorerie'),
    )
    print('  %-24s %6s %16s  %-16s %s' % ('NUMÉRATEUR', 'lignes', 'montant', 'maille', 'source'))
    for lib, lst, f, mai, src, _rd in num:
        print('  %-24s %6d %16s  %-16s %s' % (lib, len(lst), euros(sum(f(x) for x in lst)),
                                              mai, src))
    print('  %-24s %6d %16s  %-16s %s'
          % ('(propriétaire → régie)', len(recus), euros(sum(x['montant_source'] for x in recus)),
             'compte mandant', 'P8A'))
    print('  ⚠ CE DERNIER FLUX N’EST PAS SOUSTRAIT, ET CE N’EST PLUS UNE QUESTION OUVERTE :')
    print('    `P8B-SOLDE-08` interdit tout netting sans lien démontré. Les 14 versements du')
    print('    propriétaire s’affichent à côté des 668 reversements, jamais en déduction.')

    # ══ 3 ═════════════════════════════════════════════════════════════════════════════════
    entete('3 — LA MATRICE 4 NIVEAUX × 3 RENDEMENTS : le numérateur descend-il ?')
    print('  %-22s %-30s %-30s %s' % ('', 'A · THÉORIQUE', 'B · BRUT ENCAISSÉ',
                                      'C · NET TRÉSORERIE'))
    matrice = {}
    for niv, cle in (('BIEN / LOT', k_lot), ('IMMEUBLE', k_imm),
                     ('COMPTE MANDANT', k_cpte), ('PROPRIÉTAIRE GLOBAL', k_prop)):
        ligne = []
        for lib, lst, f, mai, src, _rd in num:
            # ⚠️ LE TEST N'EST PAS « AI-JE UN CHIFFRE ? » MAIS « LA SOURCE PORTE-T-ELLE CETTE
            #    MAILLE ? ». P8A est démontré au compte mandant : il y est disponible, il se
            #    consolide au propriétaire, et il ne descend ni à l'immeuble ni au bien.
            porte = (mai == 'lot') or niv in ('COMPTE MANDANT', 'PROPRIÉTAIRE GLOBAL')
            agr = collections.Counter()
            if porte:
                for x in lst:
                    agr[cle(x)] += f(x)
            matrice[(niv, lib)] = (porte, agr)
            ligne.append('%s %s' % (OUI if porte else NON,
                                    '(%d)' % len(agr) if porte else ''))
        print('  %-22s %-30s %-30s %s' % (niv, ligne[0], ligne[1], ligne[2]))
    print('  ⚠ LES SEULES CASES REFUSÉES SONT LA TRÉSORERIE SOUS LE COMPTE. `P8A` démontre un')
    print('    virement au compte mandant ; aucun CRG ne dit quelle part revient à tel')
    print('    immeuble ou à tel bien. La ventiler au prorata serait une invention.')

    # ══ 4 ═════════════════════════════════════════════════════════════════════════════════
    entete('4 — LA RÈGLE D’AGRÉGATION : on réagrège les montants, jamais les taux')
    # ⚠️ DÉMONSTRATION CHIFFRÉE DU PIÈGE. Sur une valeur de référence fictive et uniforme, la
    #    moyenne des taux des biens et le taux recalculé de l'immeuble divergent — parce que la
    #    moyenne pondère chaque bien à égalité au lieu de le pondérer par sa valeur.
    _, ap_lot = matrice[('BIEN / LOT', 'A · loyers appelés')]
    _, ap_imm = matrice[('IMMEUBLE', 'A · loyers appelés')]
    par_imm = collections.defaultdict(list)
    for k, v in ap_lot.items():
        par_imm[k[:3]].append(v)
    print('  méthode retenue : Σ numérateurs ÷ Σ valeurs, recalculé à chaque niveau')
    print('  méthode proscrite : moyenne des rendements des biens')
    # ⚠️ UNE VALEUR FICTIVE UNIFORME NE DÉMONTRE RIEN : les deux méthodes y coïncident par
    #    construction. On échelonne donc les valeurs — 100 k€, 200 k€, 300 k€… — comme un
    #    patrimoine réel, et l'écart apparaît.
    print('  valeurs fictives échelonnées 100 k€, 200 k€, 300 k€… par bien de l’immeuble')
    print('  %-30s %5s %12s %11s %11s %9s'
          % ('immeuble', 'biens', 'Σ appelé', 'RECALCULÉ', 'moyenne', 'écart'))
    pire = 0.0
    for k in sorted(par_imm, key=lambda z: -len(par_imm[z]))[:5]:
        v = sorted(par_imm[k], reverse=True)
        val = [100000.0 * (i + 1) for i in range(len(v))]
        recalc = 100.0 * sum(v) / sum(val)
        moyen = sum(100.0 * x / w for x, w in zip(v, val)) / len(v)
        pire = max(pire, abs(recalc - moyen))
        print('  %-30s %5d %12.2f %10.2f %% %10.2f %% %+8.2f pt'
              % (('%s / %s' % (k[1], k[2]))[:30], len(v), sum(v), recalc, moyen,
                 moyen - recalc))
    print('  ⚠ L’ÉCART ATTEINT %.2f POINTS SUR CES CINQ IMMEUBLES. La moyenne pondère chaque'
          % pire)
    print('    bien à égalité quelle que soit sa valeur : elle donne autant de poids à un')
    print('    garage qu’à un immeuble entier. Seule la première ligne est un rendement.')

    # ══ 5 ═════════════════════════════════════════════════════════════════════════════════
    entete('5 — LE DÉNOMINATEUR : ce que MBI doit fournir, et que le CRG n’a jamais')
    print('  Les 458 CRG n’impriment AUCUNE valeur de bien : ni prix d’acquisition, ni')
    print('  estimation, ni prix de vente. Les trois rendements sont donc, à ce jour :')
    print('     RENDEMENT NON CALCULABLE — VALEUR DE RÉFÉRENCE ABSENTE')
    print()
    print('  MBI doit fournir, PAR BIEN, quatre champs — et le même pour les trois rendements :')
    for c, ex in (('valeur', 'montant en €'),
                  ('nature_valeur', "prix d'acquisition · valeur actuelle · estimation · prix de vente"),
                  ('date_valeur', 'la date à laquelle cette valeur vaut'),
                  ('source_valeur', "d'où elle vient — acte, mandat, saisie, expertise")):
        print('     %-16s %s' % (c, ex))
    print('  ⚠ UNE VALEUR PAR BIEN SUFFIT AUX TROIS NIVEAUX : la valeur d’un immeuble est la')
    print('    somme de celles de ses biens, celle du patrimoine la somme des immeubles.')
    print('  ⚠ ET SI UNE SEULE MANQUE, LE NIVEAU AU-DESSUS N’EST PLUS EXHAUSTIF. Le rendement')
    print('    s’affiche alors avec son périmètre réel : « n biens sur N, soit x % de la')
    print('    valeur couverte »,')
    print('    jamais comme le rendement du patrimoine entier.')
    print('  couverture à préparer : %d biens · %d immeubles · %d propriétaires'
          % (n_lot, n_imm, len(props)))

    # ══ 6 ═════════════════════════════════════════════════════════════════════════════════
    entete('6 — LA PÉRIODE : une fenêtre de 12 mois est-elle disponible ?')
    dates = collections.defaultdict(set)
    for a in appels:
        dates[k_prop(a)].add(a.get('date_arrete'))
    for x in verses:
        dates[k_prop(x)].add(x.get('date_arrete'))

    def mois(d1, d2):
        a1, m1 = int(d1[:4]), int(d1[5:7])
        a2, m2 = int(d2[:4]), int(d2[5:7])
        return (a2 - a1) * 12 + (m2 - m1)

    couv = {}
    for p, ds in dates.items():
        ds = sorted(d for d in ds if d)
        couv[p] = mois(ds[0], ds[-1]) + 3 if len(ds) > 1 else 3
    douze = [p for p, m_ in couv.items() if m_ >= 12]
    # ⚠️ 307 ET NON 305 : deux propriétaires reçoivent un virement sans qu'aucun appel ne leur
    #    soit rattaché sur le corpus. Un écart de deux ne se laisse jamais sans explication.
    p_ap = {k_prop(a) for a in appels}
    p_fl = {k_prop(x) for x in verses}
    print('  propriétaires avec appels %d · avec reversement %d · les deux %d · union %d'
          % (len(p_ap), len(p_fl), len(p_ap & p_fl), len(p_ap | p_fl)))
    print('  propriétaires couverts sur 12 mois ou plus : %d / %d' % (len(douze), len(couv)))
    rep = collections.Counter(couv.values())
    for m_, n in sorted(rep.items()):
        print('     ~%2d mois de couverture : %3d propriétaires%s'
              % (m_, n, '   → RENDEMENT SUR LA PÉRIODE' if m_ < 12 else ''))
    print('  ⚠ AUCUNE ANNUALISATION SILENCIEUSE. Sous 12 mois, l’étiquette est « RENDEMENT SUR')
    print('    LA PÉRIODE » et la période est écrite. Multiplier un trimestre par 4 fabriquerait')
    print('    un chiffre que le document ne porte pas.')
    print('  ⚠ ET VIENNE EST MENSUEL : sa fenêtre se compte en mois, pas en trimestres.')

    # ══ 7 ═════════════════════════════════════════════════════════════════════════════════
    entete('7 — NON-DOUBLE-COMPTAGE DE LA CONSOLIDATION')
    # ⚠️ LE RISQUE PROPRE À CETTE PASSE : sommer des FLUX sur plusieurs périodes est légitime,
    #    mais un même bien vu deux fois SUR LA MÊME PÉRIODE serait compté deux fois.
    # ⚠️ LE CONTRÔLE PORTE SUR L'ÉVÉNEMENT, PAS SUR LE COUPLE (BIEN, DATE D'ARRÊTÉ). Sur
    #    `periode_crg` il renvoyait 0 en manquant le doublon ; sur (bien, date d'arrêté) il en
    #    signalait 32 qui n'en sont pas — ce sont les lots où la pièce complémentaire apporte
    #    des appels d'AUTRES périodes au même arrêté. Avril et mai sur un même lot ne sont pas
    #    un doublon : ce sont deux événements.
    vu = collections.defaultdict(set)
    for a in appels:
        vu[(k_lot(a), a.get('date_arrete'), P5A._ev_appel(a))].add(a.get('sha'))
    dbl = {k: v for k, v in vu.items() if len(v) > 1}
    print('  ÉVÉNEMENTS d’appel portés par plusieurs pièces : %d' % len(dbl))
    if dbl:
        ok = False
        for k, v in list(dbl.items())[:5]:
            print('     ⚠ %s → %d pièces' % (str(k)[:70], len(v)))
    # ⚠️ ET LES RÉÉDITIONS ? P7 les neutralise, mais P5A/P5B ne les connaissent pas : si elles
    #    portaient des appels, la consolidation propriétaire les compterait deux fois.
    ret_a = [x for ag in AGENCES for x in P5A.appels_retirees(ag)]
    ret_r = [x for ag in AGENCES for x in P5B.reglements_retirees(ag)]
    print('  appels retenus %d · retirés comme réénoncés %d' % (len(appels), len(ret_a)))
    print('  règlements retenus %d · retirés comme réénoncés %d' % (len(regles), len(ret_r)))
    print('  flux P8A : rééditions déjà exclues par P7 (%d pièces)' % len(P7.REEDITIONS))
    print('  ⚠ LA RÉÉDITION EST UNE PROPRIÉTÉ DE L’ÉVÉNEMENT, PAS DU PDF — `RECT-EVENEMENT-01`,')
    print('    figée le 01/09/2026. Sur les charges, FOCH et SABY sont identiques au centime ;')
    print('    sur les appels, la même pièce réénonce 42 événements et en apporte 75 que rien')
    print('    d’autre ne porte. Les lecteurs retirent les 42, gardent les 75, et n’éliminent')
    print('    aucune pièce.')
    print('  taux de recouvrement : ABSENT de ce module — le rendement B n’en est pas un.')

    # ══ 8 ═════════════════════════════════════════════════════════════════════════════════
    entete('8 — LA CARTE DES RENVOIS — décision Emmanuel du 01/09/2026')
    # ⚠️ LA MAILLE D'AFFICHAGE NE PEUT JAMAIS ÊTRE PLUS FINE QUE LA MAILLE DE LA PREUVE.
    #    Mais l'information ne disparaît pas pour autant : l'écran affiche ce qui est
    #    démontrable chez lui et RENVOIE au PREMIER niveau supérieur où l'indicateur complet
    #    est démontré. Ni case vide, ni « NON DÉMONTRABLE » jeté à l'utilisateur, ni surtout
    #    de ventilation inventée pour combler le trou.
    #    `ABSENCE DE VENTILATION ≠ ABSENCE DE CHARGE.`
    print('  ce que MBI affiche, écran par écran :')
    print()
    for niv in NIVEAUX:
        print('  ┌─ écran %s' % niv)
        for lib, _, _, _, _, nom in num:
            porte, _agr = matrice[(niv, lib)]
            if porte:
                print('  │  rendement %-18s : calculé ici' % nom)
            else:
                # ⚠️ LE PREMIER NIVEAU SUPÉRIEUR QUI SAIT, PAS LE PLUS HAUT. Pour le net
                #    trésorerie, la preuve est au COMPTE MANDANT : renvoyer au propriétaire
                #    ferait remonter l'utilisateur un cran trop loin.
                cible = next(n for n in NIVEAUX[NIVEAUX.index(niv) + 1:]
                             if matrice[(n, lib)][0])
                print('  │  rendement %-18s : disponible au niveau %s →' % (nom, cible.title()))
        print('  └─')
    print('  et pour les charges, qui expliquent ces rendements :')
    print('     écran BIEN — VIENNE       charges du bien : affichées (%s démontrées au lot)'
          % euros(sum(x['montant_source'] for x in charges
                      if x['maille_documentaire'] == 'lot')))
    print('     écran BIEN — autres       charges non ventilées : disponibles au niveau '
          'Immeuble →')
    print('     écran IMMEUBLE            charges de l’immeuble : affichées')
    print('                               charges non ventilées : disponibles au niveau '
          'Compte Mandant → (%s)'
          % euros(sum(x['montant_source'] for x in charges
                      if x['maille_documentaire'] == 'compte mandant')))
    print('  ⚠ CES 228 062,46 € NE SONT NI RÉPARTIS, NI CACHÉS, NI INTÉGRÉS au rendement du')
    print('    niveau inférieur. Ils restent au compte, nommés, atteignables en un clic.')
    print('  ⚠ ET UN RENVOI N’EST PAS UN AVEU D’IGNORANCE : il conduit à l’endroit où la')
    print('    preuve existe. C’est ce qui sépare « nous ne savons pas » de « pas à ce')
    print('    niveau-ci ».')


    entete('9 — VERDICT PAR NIVEAU ET PAR RENDEMENT')
    print('  %-22s %-22s %-22s %s' % ('NIVEAU', 'A · THÉORIQUE', 'B · BRUT ENCAISSÉ',
                                      'C · NET TRÉSORERIE'))
    for niv in NIVEAUX:
        cols = []
        for lib, _, _, _, _, nom in num:
            porte, agr = matrice[(niv, lib)]
            cols.append('numérateur OK' if porte else 'NON DÉMONTRABLE')
        print('  %-22s %-22s %-22s %s' % (niv, cols[0], cols[1], cols[2]))
    print()
    print('  ⚠ « numérateur OK » NE VEUT PAS DIRE « rendement calculable ». Il veut dire que le')
    print('    haut de la fraction est démontré à cette maille. Le bas — la valeur de référence')
    print('    — manque partout : les DOUZE cases restent VALEUR DE RÉFÉRENCE ABSENTE tant que')
    print('    MBI ne l’a pas fournie. C’est la seule chose qui manque, et elle ne vient')
    print('    pas du CRG.')

    entete('P9 CERTIFIABLE : %s'
           % ('OUI — 10 numérateurs sur 12 démontrés, les 2 autres renvoyés à leur niveau'
              if ok else 'NON — voir les ⚠'))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
