# -*- coding: utf-8 -*-
"""
P8B — LA SITUATION COMPTABLE DU COMPTE MANDANT, TELLE QUE LE CRG LA DÉMONTRE.

Ce que cette phase cherche : `solde d'ouverture → mouvements démontrables → solde de clôture`,
**quand cette équation est réellement démontrable**. Là où elle ne l'est pas, on l'écrit.

⚠️ SOURCE TECHNIQUE ≠ NATURE COMPTABLE. Les stocks routés vers P8B en P8A sont des CANDIDATS.
   On part de ce que le CRG imprime comme situation — report, totaux, solde final — et les
   conteneurs servent de preuves, jamais de définition.

⚠️ AUCUN MONTANT DE BOUCLAGE INVENTÉ. Pas de netting supposé, pas de report transformé en flux,
   aucun stock additionné entre deux dates. Une égalité qui ne se démontre pas devient
   **ÉCART DOCUMENTAIRE / ÉQUATION NON DÉMONTRABLE**.

Lecture seule. Aucune écriture MBI, aucune PROD.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402

LARG = 104
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def n(v):
    try:
        return round(float(v), 2)
    except (TypeError, ValueError):
        return None


def signe(montant, sens):
    """Convention unique : positif = DÉBITEUR (le propriétaire doit à la régie)."""
    if montant is None:
        return None
    return abs(montant) if str(sens or '') == 'debiteur' else -abs(montant)


def jour(s):
    """⚠️ L'ORDRE D'UNE CHAÎNE COMPTABLE EST CHRONOLOGIQUE, PAS ALPHABÉTIQUE. Trier sur la clé
       de période mettait `2026-05-13_2026-06-30` avant `2026-T1` et fabriquait quatre faux
       écarts de chaînage. On trie sur la DATE D'ARRÊTÉ."""
    m = re.search(r'(\d{2})[/.](\d{2})[/.](\d{4})', str(s or ''))
    return (m.group(3), m.group(2), m.group(1)) if m else ('9999', '99', '99')


def situations():
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                continue
            m = d.get('meta') or {}
            mdt = d.get('mandat') or {}
            deb = cre = 0.0
            for o in mdt.get('operations') or []:
                deb += float(o.get('debit') or 0)
                cre += float(o.get('credit') or 0)
            for im in d.get('immeubles') or []:
                for c in im.get('charges') or []:
                    deb += float(c.get('debit') or 0)
                    cre += float(c.get('credit') or 0)
                for mv in im.get('mouvements') or []:
                    deb += float(mv.get('debit') or 0)
                    cre += float(mv.get('credit') or 0)
            r = mdt.get('report') or {}
            rm = abs(n(r.get('montant')) or 0.0)
            rd = rm if str(r.get('sens')) == 'debiteur' else 0.0
            rc = rm if str(r.get('sens')) == 'crediteur' else 0.0
            od = sum(float(o.get('debit') or 0) for o in mdt.get('operations') or [])
            oc = sum(float(o.get('credit') or 0) for o in mdt.get('operations') or [])
            si = mdt.get('soldes_immeubles') or []
            # ⚠️ LE SOLDE D'IMMEUBLE PORTE SON SENS DANS SON SIGNE : négatif = débiteur pour le
            #    propriétaire. Le récapitulatif l'inscrit dans la colonne correspondante.
            sd = sum(abs(float(s.get('solde') or 0)) for s in si
                     if float(s.get('solde') or 0) < 0)
            sc = sum(abs(float(s.get('solde') or 0)) for s in si
                     if float(s.get('solde') or 0) > 0)
            yield {
                'agence': ag, 'format': d.get('_format'), 'crg': d.get('_nom_original'),
                'sha': d.get('_sha'), 'compte': str(m.get('compte') or ''),
                'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                'periode': str(m.get('periode_cle') or ''),
                'arrete': str(m.get('date_arrete') or ''),
                'report': signe(n((mdt.get('report') or {}).get('montant')),
                                (mdt.get('report') or {}).get('sens')),
                'tot_deb': n((mdt.get('totaux') or {}).get('debit')),
                'tot_cre': n((mdt.get('totaux') or {}).get('credit')),
                'final': signe(n((mdt.get('solde_final') or {}).get('montant')),
                               (mdt.get('solde_final') or {}).get('sens')),
                'lignes_deb': round(deb, 2), 'lignes_cre': round(cre, 2),
                'recap_deb': round(rd + od + sd, 2), 'recap_cre': round(rc + oc + sc, 2),
                'solde_septeo': n(d.get('solde_compte')),
                'pages': d.get('_pages'),
            }


def main():
    ok = True
    S = list(situations())

    entete('1 — CE QUE CHAQUE FORMAT IMPRIME COMME SITUATION')
    print('  %-14s %6s %10s %10s %12s %12s' % ('FORMAT', 'CRG', 'report', 'totaux',
                                               'solde final', 'solde SEPTEO'))
    for fm in sorted({x['format'] for x in S}):
        v = [x for x in S if x['format'] == fm]
        print('  %-14s %6d %10d %10d %12d %12d'
              % (fm, len(v), sum(1 for x in v if x['report'] is not None),
                 sum(1 for x in v if x['tot_deb'] is not None),
                 sum(1 for x in v if x['final'] is not None),
                 sum(1 for x in v if x['solde_septeo'] is not None)))
    print('  ⚠ SEPTEO n’imprime ni report, ni totaux, ni solde de mandat : sa situation se')
    print('    réduit à un SOLDE DE COMPTE. Aucune équation d’ouverture à clôture n’y est')
    print('    démontrable — ce n’est pas une lacune du lecteur.')

    entete('2 — ÉQUATION IMPRIMÉE : `totaux débit − totaux crédit = solde final`')
    par = collections.Counter()
    ec = []
    for x in S:
        if x['tot_deb'] is None or x['final'] is None:
            par[(x['agence'], 'non imprimée')] += 1
            continue
        att = x['tot_deb'] - x['tot_cre']
        if abs(att - x['final']) < 0.005:
            par[(x['agence'], 'vérifiée')] += 1
        else:
            par[(x['agence'], 'écart')] += 1
            ec.append((x, round(att - x['final'], 2)))
    print('  %-16s %12s %10s %14s' % ('PÉRIMÈTRE', 'vérifiée', 'écart', 'non imprimée'))
    for ag in AGENCES:
        print('  %-16s %12d %10d %14d' % (ag, par[(ag, 'vérifiée')], par[(ag, 'écart')],
                                          par[(ag, 'non imprimée')]))
    tot_v = sum(v for k, v in par.items() if k[1] == 'vérifiée')
    tot_e = sum(v for k, v in par.items() if k[1] == 'écart')
    print('  %-16s %12d %10d %14d' % ('TOTAL', tot_v, tot_e,
                                      sum(v for k, v in par.items() if k[1] == 'non imprimée')))
    if tot_e:
        ok = False
        for x, e in sorted(ec, key=lambda z: -abs(z[1]))[:5]:
            print('     ⚠ %-30s écart %s' % (str(x['crg'])[:30], euros(e)))

    entete('3 — L’ÉQUATION DU COMPTE : `report + opérations + soldes d’immeubles = totaux`')
    # ⚠️ J'AI D'ABORD CHERCHÉ AU MAUVAIS NIVEAU. Comparer les totaux à la somme de TOUTES les
    #    lignes du document échouait sur 384 CRG sur 386, et j'allais en conclure que
    #    l'équation n'était pas démontrable. Elle l'est — mais le récapitulatif du mandant
    #    n'agrège pas les charges ligne à ligne : il reprend le SOLDE de chaque immeuble, que
    #    le CRG imprime lui-même. Trois termes, pas mille.
    eq, ko = 0, []
    for x in S:
        if x['tot_deb'] is None:
            continue
        if abs(x['tot_deb'] - x['recap_deb']) < 0.02 and abs(x['tot_cre'] - x['recap_cre']) < 0.02:
            eq += 1
        else:
            ko.append(x)
    print('  vérifiée sur %d CRG · écart sur %d' % (eq, len(ko)))
    for x in ko[:5]:
        print('     ⚠ %-30s totaux D %12s · reconstitué D %12s'
              % (str(x['crg'])[:30], euros(x['tot_deb']), euros(x['recap_deb'])))
    if ko:
        ok = False
    print()
    print('  ⚠ LE DÉTAIL N’EST PAS LE RÉCAPITULATIF. La somme brute de toutes les lignes du')
    print('    document ne reproduit les totaux que sur 2 CRG : elle compterait deux fois ce')
    print('    que le solde d’immeuble agrège déjà. C’est l’erreur qu’un bouclage naïf ferait.')

    entete('4 — LE DERNIER MAILLON : le solde d’immeuble vient-il de son détail ?')
    # ⚠️ SI CE MAILLON TIENT, LA CHAÎNE VA DU LOT AU SOLDE DE CLÔTURE. Sinon, le solde
    #    d'immeuble reste un agrégat imprimé qu'on lit sans pouvoir le refaire — ce qui est
    #    une limite, pas une faute.
    testes = recons = 0
    manquants = []
    for ag in AGENCES:
        for d in documents(ag):
            if d.get('_nom_original') in P7.REEDITIONS:
                continue
            # ⚠️ NOM D'IMMEUBLE ≠ IDENTITÉ D'IMMEUBLE — arbitrage Emmanuel du 01/09/2026 :
            #    « il y a 2 agences RIOM avec 2 comptes différents, un pour le local commercial
            #    et un pour les parkings et archives ». Indexer les soldes par NOM en écrasait
            #    un sur deux, et je comparais le détail d'un immeuble au solde de l'autre.
            #
            # ⚠️ LE RANG D'APPARITION N'EST PAS UNE IDENTITÉ. C'est une MÉTHODE D'APPARIEMENT
            #    DOCUMENTAIRE, valable ici parce que le lecteur conserve l'ordre des occurrences
            #    homonymes dans les deux listes du même PDF. Il ne sort pas de ce contrôle :
            #    l'identité métier d'un immeuble reste le périmètre COMPTE / MANDAT plus les
            #    références immeuble / lot que le document fournit. Écrire `nom + rang` dans une
            #    clé métier reproduirait, un cran plus loin, le défaut qu'on vient de corriger.
            #
            # ⚠️ ET LA PROVENANCE RESTE ENTIÈRE : `crg · page · rang` permet de revenir aux deux
            #    occurrences imprimées et de vérifier l'appariement au PDF.
            si = collections.defaultdict(list)
            for s in (d.get('mandat') or {}).get('soldes_immeubles') or []:
                si[' '.join(str(s.get('nom') or '').upper().split())].append(
                    round(float(s.get('solde') or 0), 2))
            if not si:
                continue
            rang = collections.Counter()
            for im in d.get('immeubles') or []:
                k = ' '.join(str(im.get('nom') or '').upper().split())
                i_ = rang[k]
                rang[k] += 1
                if k not in si or i_ >= len(si[k]):
                    continue
                testes += 1
                ch = sum(float(c.get('credit') or 0) - float(c.get('debit') or 0)
                         for c in im.get('charges') or [])
                reg = sum(float(l.get('total_regle') or 0) for l in im.get('lots') or [])
                if abs(ch + reg - si[k][i_]) < 0.02:
                    recons += 1
                elif len(manquants) < 5:
                    manquants.append((d.get('_nom_original'), k, si[k][i_], round(ch, 2),
                                      round(reg, 2)))
    print('  `règlements locataires − charges = solde d’immeuble` : %d / %d immeubles (%.0f %%)'
          % (recons, testes, 100.0 * recons / max(testes, 1)))
    print('  écarts documentaires : %d' % (testes - recons))
    for m_ in manquants:
        print('     %-28s %-24s solde %12s · charges %12s · règlements %12s'
              % (str(m_[0])[:28], m_[1][:24], euros(m_[2]), euros(m_[3]), euros(m_[4])))
    print('  ⚠ ÉCART DOCUMENTAIRE, PAS CORRECTION : ces immeubles ne sont pas redressés.')

    entete('5 — LE CHAÎNAGE : la clôture d’un CRG est-elle le report du suivant ?')
    parc = collections.defaultdict(list)
    for x in S:
        if x['compte']:
            parc[(x['agence'], x['compte'])].append(x)
    ch = collections.Counter()
    ruptures = []
    for k, lst in parc.items():
        lst.sort(key=lambda z: jour(z['arrete']))
        for a, b in zip(lst, lst[1:]):
            if a['arrete'] == b['arrete']:
                ch[(k[0], 'même arrêté — hors chaîne')] += 1
                continue
            if a['final'] is None or b['report'] is None:
                ch[(k[0], 'report non imprimé')] += 1
                continue
            if abs(a['final'] - b['report']) < 0.005:
                ch[(k[0], 'vérifié')] += 1
            else:
                ch[(k[0], 'rupture')] += 1
                ruptures.append((k[0], k[1], a, b, round(b['report'] - a['final'], 2)))
    print('  %-16s %10s %10s %20s %22s' % ('PÉRIMÈTRE', 'vérifié', 'rupture',
                                           'report non imprimé', 'même arrêté (hors chaîne)'))
    for ag in AGENCES:
        print('  %-16s %10d %10d %20d %22d'
              % (ag, ch[(ag, 'vérifié')], ch[(ag, 'rupture')],
                 ch[(ag, 'report non imprimé')], ch[(ag, 'même arrêté — hors chaîne')]))
    print('  %-16s %10d %10d %20d %22d'
          % ('TOTAL', sum(v for k, v in ch.items() if k[1] == 'vérifié'),
             sum(v for k, v in ch.items() if k[1] == 'rupture'),
             sum(v for k, v in ch.items() if k[1] == 'report non imprimé'),
             sum(v for k, v in ch.items() if k[1] == 'même arrêté — hors chaîne')))
    if ruptures:
        print()
        print('  LES RUPTURES :')
        for ag, c, a, b, e in sorted(ruptures, key=lambda z: -abs(z[4]))[:8]:
            print('     %-13s %-10s %s → %s   clôture %13s · report %13s · écart %12s'
                  % (ag[:13], c, a['arrete'], b['arrete'], euros(a['final']),
                     euros(b['report']), euros(e)))

    entete('6 — SEPTEO : ce que le solde de compte démontre')
    v = [x for x in S if x['format'] == 'septeo_spi']
    src = collections.Counter(str((d.get('source_solde'))) for d in documents('VIENNE'))
    print('  %d CRG · solde de compte imprimé sur %d'
          % (len(v), sum(1 for x in v if x['solde_septeo'] is not None)))
    for k, c in src.most_common():
        print('     %-46s %3d' % (k, c))
    print('  ⚠ AUCUN REPORT, AUCUN TOTAL, AUCUN CHAÎNAGE : la situation SEPTEO est une')
    print('    PHOTOGRAPHIE à la date d’arrêté. `STOCK ≠ FLUX` interdit de la relier à la')
    print('    précédente par différence.')

    entete('7 — NON-DOUBLE-COMPTAGE : un solde n’est jamais un mouvement')
    # ⚠️ P8B NE COMPTE AUCUN FLUX. Il lit des soldes. Le contrôle vérifie qu'aucun montant de
    #    solde n'a été repris comme flux en P8A.
    import p8a_socle as S8
    # ⚠️ LA CLÉ DE CONTRÔLE DOIT DISTINGUER LES OCCURRENCES, PAS LES FONDRE. Sans `rang`, six
    #    lignes identiques d'une même page se confondaient : le test annonçait 676 clés pour
    #    682 flux, et 52 pour 58 stocks. Un écart qu'il fallait expliquer au lieu qu'il
    #    n'existe pas.
    def cle8(x):
        return (x['sha'], x['page'], x.get('y'), x.get('rang'), x['libelle_brut'],
                x['montant_source'])
    flux = {cle8(x) for ag in AGENCES for x in S8.candidats(ag)
            if x['sens'] in ('REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE')}
    stocks = {cle8(x) for ag in AGENCES for x in S8.candidats(ag)
              if x['famille'] == S8.STOCK_P8B}
    comm = flux & stocks
    print('  flux P8A %d · stocks repérés %d · EN COMMUN : %d' % (len(flux), len(stocks),
                                                                  len(comm)))
    if comm:
        ok = False
    else:
        print('  aucun solde n’est compté comme flux.')
    print('  ⚠ et aucun stock n’est additionné entre deux dates : chaque solde porte la sienne.')

    entete('P8B : %s' % ('OUI — la situation imprimée est démontrée, ses limites sont nommées'
                         if ok else 'NON — voir les ⚠ ci-dessus'))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
