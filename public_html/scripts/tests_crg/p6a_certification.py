# -*- coding: utf-8 -*-
"""
P6A — CRÉANCES / ENCOURS : À LA DATE D'ARRÊTÉ, QUI DOIT COMBIEN, SUR QUEL LOT ?

⚠️ UN STOCK N'EXISTE PAS SANS SA DATE, ET DEUX STOCKS NE S'ADDITIONNENT JAMAIS. C'est le piège
   central de cette phase, et le corpus le rend spectaculaire : l'ancien compteur `encours` de
   GROUPE SIR vaut 7 561 145,94 € — mais c'est la SOMME DE SIX PHOTOGRAPHIES du même portefeuille,
   au 31/03/2025, 30/06/2025, 30/09/2025, 31/12/2025, 31/03/2026 et 30/06/2026. Le même impayé y
   est compté jusqu'à six fois. Mathématiquement exact, économiquement absurde.

     LYON hors SIR   952 643,66 €  =  480 248,69 (31/03/2026) + 472 394,97 (30/06/2026)
     EMERY IMMO      144 484,36 €  =  143 656,25 (31/03/2026) + 828,11 sur deux arrêtés isolés
     VIENNE           60 115,12 €  =  une seule date (30/04/2026) — le seul total légitime
     GROUPE SIR    7 561 145,94 €  =  six dates additionnées

   Ce module ne produit donc AUCUN total corpus. Deux consolidations seulement sont licites :
   à une DATE COMMUNE, ou par DERNIÈRE OBSERVATION CONNUE de chaque objet — nommées comme telles.

⚠️ LE PDF DIT LE STOCK, LA FORMULE NE FAIT QUE LE CONTRÔLER. On ne fabrique jamais
   `créance = appels − règlements` : P5B a démontré 0 mouvement daté et 36 % de règlements sans
   affectation à une période. Le stock lu est la colonne « Impayés » (lyon, emery_immo) ou
   « Reste dû » (septeo_spi).

⚠️ ET LE SIGNE NE FAIT PAS LA NATURE. La convention est démontrée au PDF : `MELINE Claire`
   imprime « 1970.44 2044.05 -73.61 » — Total, Réglés, Impayés — donc réglé PLUS que dû, et le
   négatif est une position créditrice. Mais un négatif reporté comme `GOIN Enora` « Solde
   Antérieur -444.01 » ne dit PAS pourquoi : il reste CRÉDITEUR — NATURE NON DÉMONTRÉE.

⚠️ ET LA DETTE NE DÉTERMINE JAMAIS L'OCCUPATION. Le statut vient de P4B, figée, et n'est jamais
   recalculé ici. Un ancien locataire garde sa créance ; elle n'en fait pas un occupant.

⚠️ ENFIN, LA CRÉANCE D'UN ANCIEN NE PASSE JAMAIS AU SUIVANT. Sur un lot où A a été remplacé par
   B, chaque solde reste attaché à son titulaire — jamais fusionné parce que le lot est le même.

Aucune antériorité n'est certifiée ici : elle relève de P6B. Lecture seule.
"""
import crg_evenement as EV   # noqa: E402
import sys
import os
import io
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402  (socle certifié : jour, colle, statut d'occupation)

sys.stdout.reconfigure(encoding='utf-8')

DUMP = r'C:\tmp\p6a_stocks.json'

LIBELLE = {'lyon': 'colonne « Impayés » de la ligne « Totaux » du lot',
           'emery_immo': 'colonne « Impayés » de la ligne « Totaux » du lot',
           'septeo_spi': 'colonne « Reste dû » des lignes d’appel'}


def statuts_p4b(agence):
    """Le statut d'occupation certifié en P4B, par (compte, immeuble, lot, identité, CRG).

       ⚠️ ON NE RECALCULE RIEN : P4B est figée, on la lit."""
    out = {}
    for b in P4B.analyser([agence])[agence]['blocs']:
        out[(b['compte'], b['immeuble'], b['lot'], b['identite'], b['sha'])] = (b['statut'],
                                                                                b['classe'])
    return out


def _observations_brutes(agence):
    """Une OBSERVATION DE STOCK par bloc de lot, à la date d'arrêté de son CRG."""
    p4b = statuts_p4b(agence)
    for d in documents(agence):
        m = d.get('meta') or {}
        fm = d.get('_format')
        arrete = P4B.jour(m.get('date_arrete')) or P4B.jour(m.get('periode_fin'))
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            for rang, lo in enumerate(im.get('lots') or []):
                lib = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                if fm == 'septeo_spi':
                    # ⚠️ SEPTEO N'IMPRIME PAS DE TOTAL D'ARRIÉRÉ AU LOT : il imprime le reste dû
                    #    LIGNE PAR LIGNE. Le total du lot est donc CALCULÉ, et déclaré tel quel.
                    cells = [(str(a.get('libelle') or ''), float(a['reste_du']))
                             for a in lo.get('appels') or [] if a.get('reste_du') is not None]
                    montant = round(sum(v for _, v in cells), 2) if cells else None
                    niveau = ('calculé — somme de %d cellules « Reste dû » imprimées' % len(cells)
                              if cells else 'absent')
                    comp = [{'libelle': l, 'montant': v} for l, v in cells]
                else:
                    v = lo.get('total_impaye')
                    montant = None if v is None else round(float(v), 2)
                    niveau = 'lu' if v is not None else 'absent'
                    comp = []

                # ── la qualification, démontrée par le document, jamais par le seul signe
                regle, du = lo.get('total_regle'), lo.get('total_du')
                if montant is None:
                    q = 'absence d’information'
                elif montant > 0:
                    q = 'débiteur / créance'
                elif montant == 0:
                    q = 'solde nul démontré'
                elif regle is not None and du is not None and float(regle) > float(du):
                    q = 'créditeur — trop-perçu démontré (réglé > appelé)'
                else:
                    q = 'créditeur — nature non démontrée'

                st = p4b.get((str(m.get('compte') or ''), code, str(lo.get('numero_lot') or ''),
                              P4B.colle(lib), d.get('_sha')))
                yield {
                    'agence': agence, 'format': fm, 'crg_fichier': d.get('_nom_original'),
                    'sha': d.get('_sha'), 'page': lo.get('page'),
                    'periode_crg': m.get('periode_cle'), 'date_arrete': arrete,
                    'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                    'compte': str(m.get('compte') or ''), 'immeuble': code,
                    'lot': str(lo.get('numero_lot') or ''), 'bloc': rang, 'locataire': lib,
                    'identite': P4B.colle(lib),
                    'statut_occupation_P4B': (st or ('non rattaché', ''))[0],
                    'classe_occupation_P4B': (st or ('', ''))[1],
                    'libelle_stock_source': LIBELLE.get(fm, ''), 'montant_source': montant,
                    'sens_documentaire': 'positif = dû par le locataire · négatif = créditeur',
                    'qualification': q,
                    'maille_documentaire': ('lot × locataire × date d’arrêté' if lib
                                            else 'lot × date d’arrêté — débiteur non démontrable'),
                    'niveau_source': niveau, 'composantes': comp,
                }


CHAINE = ('crg_fichier', 'sha', 'page', 'periode_crg', 'date_arrete', 'proprietaire', 'compte',
          'immeuble', 'lot', 'libelle_stock_source', 'sens_documentaire', 'qualification',
          'maille_documentaire', 'niveau_source')


def _ev_observation(o):
    """L'événement d'un stock : quel lot, à quelle date, de quelle qualification et montant.

    ⚠️ DEUX PIÈCES D'UNE MÊME SITUATION PHOTOGRAPHIENT LE MÊME STOCK. Les additionner
       doublerait une photographie — ce que `STOCK ≠ FLUX` interdit déjà par ailleurs.
    """
    return (str(o.get('immeuble') or ''), str(o.get('lot') or ''), str(o.get('locataire') or ''),
            o.get('date_arrete'), o.get('qualification'),
            round(float(o.get('montant_source') or 0), 2))


# ── RECTIFICATION CERTIFIÉE P5A / P5B / P6A — FOCH/SABY — 01/09/2026 ──────────────────────
# ⚠️ LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF, C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER
#    QU'IL CONTIENT. Deux pièces couvrant la même situation de gestion réénoncent une partie
#    de leurs événements et se complètent pour le reste. Le lecteur les lit toutes — c'est
#    ici, et ici seulement, que le doublon est retiré : un événement réénoncé compte une fois,
#    un événement complémentaire reste compté, aucune pièce n'est éliminée.
def observations(agence):
    """Une OBSERVATION DE STOCK par bloc de lot, dédoublonnée à l'événement."""
    brutes = list(_observations_brutes(agence))
    EV.divergences(brutes, _ev_observation, lambda o: o.get('montant_source'))
    retenues, _ = EV.dedupliquer(brutes, _ev_observation)
    return retenues


def observations_retirees(agence):
    """Les occurrences retirées comme réénoncées — conservées pour la preuve, jamais comptées."""
    return EV.dedupliquer(list(_observations_brutes(agence)), _ev_observation)[1]


def certifier(agences):
    ok = True
    tout = {}
    for ag in agences:
        tout[ag] = list(observations(ag))

    entete('P6A — LE STOCK EST UNE PHOTOGRAPHIE : UNE LIGNE PAR DATE D’ARRÊTÉ, JAMAIS DE SOMME')
    print('  %-16s %-12s %7s %6s %16s %6s %14s %6s %8s' %
          ('PÉRIMÈTRE', 'date arrêté', 'observ.', 'débit.', 'montant débiteur',
           'créd.', 'mt créditeur', 'nuls', 'absence'))
    for ag in agences:
        par = collections.defaultdict(list)
        for o in tout[ag]:
            par[o['date_arrete']].append(o)
        for dt in sorted(par, key=lambda x: x or ''):
            os_ = par[dt]
            deb = [o for o in os_ if o['qualification'] == 'débiteur / créance']
            cre = [o for o in os_ if o['qualification'].startswith('créditeur')]
            print('  %-16s %-12s %7d %6d %16s %6d %14s %6d %8d' %
                  (ag, dt, len(os_), len(deb), euros(sum(o['montant_source'] for o in deb)),
                   len(cre), euros(sum(o['montant_source'] for o in cre)),
                   sum(1 for o in os_ if o['qualification'] == 'solde nul démontré'),
                   sum(1 for o in os_ if o['qualification'] == 'absence d’information')))
    print('  ⚠ AUCUN TOTAL CORPUS ICI. Additionner ces lignes compterait le même impayé une fois')
    print('    par trimestre — c’est exactement ce que faisait l’ancien compteur `encours`.')

    entete('P6A — CONSOLIDATION LICITE : LA DERNIÈRE OBSERVATION CONNUE DE CHAQUE OBJET')
    # ⚠️ UN OBJET = (compte, immeuble, lot, identité). On ne retient que sa photographie la plus
    #    récente : aucun double comptage, et la date de chaque montant reste affichée.
    print('  %-16s %-24s %8s %16s %8s %14s' %
          ('PÉRIMÈTRE', 'dernière date observée', 'débit.', 'montant débiteur',
           'créd.', 'mt créditeur'))
    for ag in agences:
        dernier = {}
        for o in tout[ag]:
            k = (o['compte'], o['immeuble'], o['lot'], o['identite'])
            if k not in dernier or (o['date_arrete'] or '') > (dernier[k]['date_arrete'] or ''):
                dernier[k] = o
        os_ = list(dernier.values())
        deb = [o for o in os_ if o['qualification'] == 'débiteur / créance']
        cre = [o for o in os_ if o['qualification'].startswith('créditeur')]
        dates = sorted({o['date_arrete'] for o in os_ if o['date_arrete']})
        print('  %-16s %-24s %8d %16s %8d %14s' %
              (ag, '%s → %s' % (dates[0], dates[-1]) if dates else '—', len(deb),
               euros(sum(o['montant_source'] for o in deb)), len(cre),
               euros(sum(o['montant_source'] for o in cre))))
    print('  ⚠ Ces montants ne sont PAS à une date commune : chaque objet porte la sienne.')

    entete('P6A — LA CRÉANCE DES ANCIENS LOCATAIRES (occupation P4B consolidée, jamais recalculée)')
    # ⚠️ C'EST ICI QUE P4B PAIE. Le statut retenu est celui de l'OCCUPATION consolidée — « cette
    #    personne occupe-t-elle encore ce lot ? » — et non celui de la situation à un trimestre.
    #    Une créance d'ancien locataire ne se transfère jamais à l'occupant suivant.
    print('  %-16s %10s %16s %10s %16s %12s %16s' %
          ('PÉRIMÈTRE', 'actives', 'montant', 'archivées', 'montant', 'indéterm.', 'montant'))
    for ag in agences:
        occ = {}
        for o in P4B.analyser([ag])[ag]['occupations']:
            occ[(o['compte'], o['immeuble'], o['lot'], o['identite'])] = o['statut']
        dernier = {}
        for o in tout[ag]:
            k = (o['compte'], o['immeuble'], o['lot'], o['identite'])
            if k not in dernier or (o['date_arrete'] or '') > (dernier[k]['date_arrete'] or ''):
                dernier[k] = o
        c, m = collections.Counter(), collections.defaultdict(float)
        for k, o in dernier.items():
            if o['qualification'] != 'débiteur / créance':
                continue
            s = occ.get(k, 'non rattaché')
            c[s] += 1
            m[s] += o['montant_source']
        print('  %-16s %10d %16s %10d %16s %12d %16s' %
              (ag, c['actif'], euros(m['actif']), c['ancien'], euros(m['ancien']),
               c['indeterminable'], euros(m['indeterminable'])))
    print('  ⚠ dernière observation de chaque objet · une créance d’ancien locataire reste')
    print('    attachée à SON titulaire, jamais reportée sur l’occupant suivant du lot.')

    entete('P6A — À QUI EST RATTACHÉE LA CRÉANCE ? (statut d’occupation P4B, jamais recalculé)')
    print('  %-16s %22s %16s %24s %16s %22s %16s' %
          ('PÉRIMÈTRE', 'actifs débiteurs', 'montant', 'anciens débiteurs', 'montant',
           'indéterm. débiteurs', 'montant'))
    for ag in agences:
        deb = [o for o in tout[ag] if o['qualification'] == 'débiteur / créance']
        c = collections.Counter(o['statut_occupation_P4B'] for o in deb)
        m = collections.defaultdict(float)
        for o in deb:
            m[o['statut_occupation_P4B']] += o['montant_source']
        print('  %-16s %22d %16s %24d %16s %22d %16s' %
              (ag, c['actif'], euros(m['actif']), c['ancien'], euros(m['ancien']),
               c['indeterminable'], euros(m['indeterminable'])))
    print('  ⚠ toutes dates confondues — ces effectifs comptent des OBSERVATIONS, pas des dettes')
    print('    distinctes. La dette d’un ancien locataire ne le rend jamais occupant (P4B figée).')

    entete('P6A — MAILLE, PROVENANCE ET NIVEAU DE SOURCE')
    print('  %-16s %-46s %10s %12s' % ('PÉRIMÈTRE', 'stock lu dans', 'provenance', 'niveau'))
    for ag in agences:
        os_ = tout[ag]
        complet = sum(1 for o in os_ if all(o.get(c) not in (None, '') for c in CHAINE)
                      and isinstance(o['page'], int))
        if complet != len(os_):
            ok = False
        niv = collections.Counter(o['niveau_source'].split(' —')[0] for o in os_)
        print('  %-16s %-46s %6d/%-5d %s' %
              (ag, LIBELLE.get(os_[0]['format'], '') if os_ else '—', complet, len(os_),
               ' · '.join('%s ×%d' % (k, v) for k, v in niv.most_common())))
    print('  ⚠ SEPTEO n’imprime aucun total d’arriéré au lot : le sien est CALCULÉ par somme de')
    print('    ses cellules « Reste dû » imprimées, et déclaré comme tel.')

    entete('P6A — PREUVE VIENNE : LE STOCK VIENT DES « RESTE DÛ » IMPRIMÉS, DE RIEN D’AUTRE')
    # ⚠️ IL FAUT DÉMONTRER CE QUE LE CALCUL N'EST PAS. Si le stock VIENNE venait d'un
    #    `appels − règlements`, il vaudrait tout autre chose : la comparaison le prouve.
    if 'VIENNE' in agences:
        os_ = tout['VIENNE']
        cells = sum(len(o['composantes']) for o in os_)
        somme = sum(o['montant_source'] for o in os_ if o['montant_source'] is not None)
        appele = regle = 0.0
        for d in documents('VIENNE'):
            for im in d.get('immeubles') or []:
                for lo in im.get('lots') or []:
                    for a in lo.get('appels') or []:
                        appele += ((a.get('loyers') or 0) + (a.get('charges') or 0)
                                   + (a.get('autres') or 0))
                        regle += (a.get('credit') or 0)
        print('  unité élémentaire            cellule « Reste dû » imprimée sur une ligne d’appel')
        print('  cellules additionnées        %d, réparties sur %d blocs de lot'
              % (cells, sum(1 for o in os_ if o['composantes'])))
        print('  somme                        %s   au 30/04/2026, date d’arrêté unique de VIENNE'
              % euros(somme))
        print('  rattachement                 lot × locataire × date d’arrêté, %d/%d avec page'
              % (sum(1 for o in os_ if isinstance(o['page'], int)), len(os_)))
        print('  blocs sans aucune cellule    %d → ABSENCE D’INFORMATION, jamais « solde = 0 »'
              % sum(1 for o in os_ if not o['composantes']))
        print('  niveau de source             calculé — et enregistré comme tel')
        print('\n  CE QUE LE CALCUL N’EST PAS :')
        print('    appelé (P5A) %s − réglé (P5B) %s = %s'
              % (euros(appele), euros(regle), euros(appele - regle)))
        print('    stock lu des « Reste dû »                        = %s' % euros(somme))
        print('    → les deux diffèrent d’un facteur %.1f. Le stock VIENNE n’est donc PAS un'
              % (somme / (appele - regle)) if appele - regle else '')
        print('      `appels − règlements` : il porte l’arriéré des mois antérieurs, réénoncés')
        print('      ligne à ligne, que le CRG d’avril n’appelle plus.')

    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps([o for ag in agences for o in tout[ag]],
                                                          ensure_ascii=False))
    print('\n  %d observations de stock → %s' % (sum(len(v) for v in tout.values()), DUMP))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP6A — CRÉANCES / ENCOURS : %s'
          % ('OUI — chaque stock porte sa date, sa maille, son sens et sa provenance'
             if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
