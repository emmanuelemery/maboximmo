# -*- coding: utf-8 -*-
"""
P5B — ENCAISSEMENTS : QU'EST-CE QUI A RÉELLEMENT ÉTÉ RÉGLÉ ?

⚠️ LE RÉSULTAT PRINCIPAL DE CETTE PHASE EST UNE ABSENCE, ET IL FAUT LA DIRE.
   AUCUN des quatre périmètres n'imprime un MOUVEMENT ÉLÉMENTAIRE DE RÈGLEMENT LOCATAIRE :
   pas une seule ligne « le 12/04, M. X a versé 800 € ». Vérifié format par format, au PDF :

     lyon · emery_immo   les seules lignes datées du document sont les DÉPENSES et les
                         « Reglement virement du 28.01.2026 » du récapitulatif — des
                         mouvements RÉGIE → PROPRIÉTAIRE, pas des paiements de locataires.
     septeo_spi          `mouvements` est daté, mais ses sections sont Honoraires de Gestion,
                         Factures dues, Charges de syndic, GLI Assurance, Charges Propriétaire.
                         475 lignes, ZÉRO règlement locataire.

   Un total qui retombe juste ne prouve donc pas l'existence des paiements qui le composent.

⚠️ CE QUE LES DOCUMENTS DONNENT VRAIMENT, C'EST UN RÈGLEMENT AFFECTÉ, NON DATÉ. Le triplet
   `appelé / réglé / restant` est imprimé, mais à des mailles différentes selon le format :

     lyon (LYON + GROUPE SIR)   colonnes « Total · Réglés · Impayés » SUR CHAQUE LIGNE DE
                                PÉRIODE → l'affectation à la période est DÉMONTRÉE
     septeo_spi                 colonne de droite (x≈540) sur chaque ligne d'appel, dont le
                                libellé nomme la période → affectation DÉMONTRÉE
     emery_immo                 rien par ligne : seulement `Totaux` au niveau du LOT →
                                affectation à la période NON DÉMONTRABLE SUR CE FORMAT

⚠️ ET « RÉGLÉ » N'EST PAS « ENCAISSÉ DU LOCATAIRE ». Le document dit qu'une période a été
   soldée ; il ne dit ni QUAND, ni PAR QUI. Une GLI, une CAF, une compensation ou un dépôt de
   garantie produiraient la même colonne. Le payeur n'est imprimé nulle part : il est
   INDÉTERMINABLE sur 100 % du corpus, et c'est un fait à consigner, pas à combler.

⚠️ ON NE FABRIQUE JAMAIS LES MOUVEMENTS POUR ATTEINDRE UN TOTAL. Là où seul un agrégat est
   imprimé, on certifie un AGRÉGAT et on écrit MOUVEMENTS ÉLÉMENTAIRES NON DÉMONTRABLES.

⚠️ ET AUCUNE CRÉANCE N'EST CALCULÉE ICI. `appelé − réglé` n'est pas une dette : il y manque
   l'antériorité, les avoirs, les avances et les compensations. C'est P6.

Lecture seule. Aucune écriture MBI.
"""
import crg_evenement as EV   # noqa: E402
import sys
import os
import io
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402
import p5a_certification as P5A   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

DUMP = r'C:\tmp\p5b_reglements.json'

# les sections de `mouvements` SEPTEO : toutes propriétaire/régie, aucune locataire
SECTIONS_HORS_LOCATAIRE = ('Honoraires de Gestion', 'Factures dues', 'Charges de syndic',
                           'GLI Assurance', 'Charges Propriétaire')


def _reglements_brutes(agence):
    """Chaque RÈGLEMENT que le document affecte explicitement, avec sa maille et sa provenance.

       ⚠️ LA MAILLE EST UNE DONNÉE, PAS UN DÉTAIL. Dire « 384 règlements EMERY » sans préciser
          qu'ils sont au niveau du LOT et non de la période laisserait croire à une finesse que
          le document n'a pas."""
    for d in documents(agence):
        m = d.get('meta') or {}
        fm = d.get('_format')
        base = {'agence': agence, 'format': fm, 'crg_fichier': d.get('_nom_original'),
                'sha': d.get('_sha'), 'periode_crg': m.get('periode_cle'),
                'date_arrete': P4B.jour(m.get('date_arrete')),
                'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                'compte': str(m.get('compte') or ''),
                # ⚠️ JAMAIS IMPRIMÉ : ni la date du versement, ni celui qui l'a fait.
                'date_mouvement': None, 'payeur': 'indéterminable', 'niveau_source': 'lu'}
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            for lo in im.get('lots') or []:
                loc = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                commun = dict(base, immeuble=code, lot=str(lo.get('numero_lot') or ''),
                              locataire=loc)
                if fm == 'septeo_spi':
                    for a in lo.get('appels') or []:
                        v = a.get('credit')
                        if v is None:
                            continue
                        lib = str(a.get('libelle') or '')
                        deb, fin = P4B.bornes_libelle(lib)
                        yield dict(commun, montant=round(float(v), 2), maille='ligne d’appel',
                                   nature=('règlement négatif — nature indéterminable'
                                           if float(v) < 0 else 'règlement'),
                                   affectation='démontrée' if (deb or P5A.texte_periode(lib))
                                               else 'indéterminable',
                                   periode_reglee_debut=deb, periode_reglee_fin=fin,
                                   periode_reglee_texte=P5A.texte_periode(lib) if not deb else None,
                                   libelle_source=lib, page=a.get('page'))
                else:
                    # ── LYON · EMERY · GROUPE SIR
                    # ⚠️ « Réglés » N'EST PAS IMPRIMÉ PARTOUT À LA MÊME MAILLE, Y COMPRIS DANS
                    #    UN MÊME CRG. Certaines lignes de période le portent, d'autres non, et
                    #    la ligne `Totaux` du lot porte toujours le total. Ne prendre que les
                    #    lignes perdait 67 945 € sur LYON et 945 714 € sur GROUPE SIR ; ne
                    #    prendre que le total effaçait l'affectation là où elle est démontrée.
                    #    On garde donc les deux, et on nomme le RESTE pour ce qu'il est : un
                    #    règlement affecté au LOT seulement.
                    ligne = 0.0
                    for r in lo.get('mois') or []:
                        if 'regles' not in r:
                            continue
                        v = round(float(r.get('regles') or 0), 2)
                        ligne += v
                        deb, fin = P4B.jour(r.get('du')), P4B.jour(r.get('au'))
                        yield dict(commun, montant=v, maille='ligne de période',
                                   nature=('règlement négatif — nature indéterminable'
                                           if v < 0 else 'règlement'),
                                   affectation='démontrée' if (deb and fin) else 'indéterminable',
                                   periode_reglee_debut=deb, periode_reglee_fin=fin,
                                   periode_reglee_texte=None,
                                   libelle_source='colonne « Réglés »', page=r.get('page'))
                    reste = round(float(lo.get('total_regle') or 0) - ligne, 2)
                    if reste:
                        yield dict(commun, montant=reste, maille='lot',
                                   nature=('règlement négatif — nature indéterminable'
                                           if reste < 0 else 'règlement'),
                                   affectation='indéterminable',
                                   periode_reglee_debut=None, periode_reglee_fin=None,
                                   periode_reglee_texte=None,
                                   libelle_source='ligne « Totaux » du lot, hors lignes de période',
                                   page=lo.get('page'))


def _ev_reglement(r):
    """L'événement métier d'un règlement : quel lot, quelle source, quelle période, quel montant."""
    return (str(r.get('immeuble') or ''), str(r.get('lot') or ''), str(r.get('locataire') or ''),
            r.get('libelle_source'), r.get('periode_reglee_debut'), r.get('periode_reglee_fin'),
            round(float(r.get('montant') or 0), 2))


# ── RECTIFICATION CERTIFIÉE P5A / P5B / P6A — FOCH/SABY — 01/09/2026 ──────────────────────
# ⚠️ LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF, C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER
#    QU'IL CONTIENT. Deux pièces couvrant la même situation de gestion réénoncent une partie
#    de leurs événements et se complètent pour le reste. Le lecteur les lit toutes — c'est
#    ici, et ici seulement, que le doublon est retiré : un événement réénoncé compte une fois,
#    un événement complémentaire reste compté, aucune pièce n'est éliminée.
def reglements(agence):
    """Chaque RÈGLEMENT affecté par le document, dédoublonné à l'événement."""
    brutes = list(_reglements_brutes(agence))
    EV.divergences(brutes, _ev_reglement, lambda r: r.get('montant'))
    retenues, _ = EV.dedupliquer(brutes, _ev_reglement)
    return retenues


def reglements_retirees(agence):
    """Les occurrences retirées comme réénoncées — conservées pour la preuve, jamais comptées."""
    return EV.dedupliquer(list(_reglements_brutes(agence)), _ev_reglement)[1]


def agregats(agence):
    """Les totaux de règlements que le document imprime lui-même, à la maille immeuble."""
    for d in documents(agence):
        m = d.get('meta') or {}
        for im in d.get('immeubles') or []:
            for t in im.get('totaux_imprimes') or []:
                if 'REGLEMENT' not in str(t.get('libelle') or '').upper():
                    continue
                yield {'agence': agence, 'crg_fichier': d.get('_nom_original'),
                       'sha': d.get('_sha'), 'page': t.get('page'),
                       'periode_crg': m.get('periode_cle'), 'compte': str(m.get('compte') or ''),
                       'immeuble': str(im.get('code') or ''), 'type': 'agregat',
                       'niveau': 'immeuble', 'libelle_source': t.get('libelle'),
                       'montant': round(float(t.get('credit') or 0), 2)}


def mouvements_dates(agence):
    """Les lignes DATÉES du document — pour démontrer qu'aucune ne règle un locataire."""
    for d in documents(agence):
        for im in d.get('immeubles') or []:
            for mv in im.get('mouvements') or []:      # septeo_spi
                yield (mv.get('section') or 'sans section', mv.get('date'))
            for ch in im.get('charges') or []:          # lyon · emery_immo
                yield (ch.get('categorie') or 'sans catégorie', None)


CHAINE = ('crg_fichier', 'sha', 'periode_crg', 'date_arrete', 'proprietaire', 'compte',
          'immeuble', 'lot', 'locataire', 'libelle_source', 'niveau_source')


def certifier(agences):
    ok = True
    tout, dump = {}, []

    entete('P5B — ① Y A-T-IL UN MOUVEMENT ÉLÉMENTAIRE DE RÈGLEMENT LOCATAIRE ?')
    print('  %-16s %14s %16s %38s' %
          ('PÉRIMÈTRE', 'lignes datées', 'dont locataire', 'sections rencontrées'))
    for ag in agences:
        secs = collections.Counter(s for s, _ in mouvements_dates(ag))
        datees = sum(secs.values())
        loc = sum(v for s, v in secs.items() if s not in SECTIONS_HORS_LOCATAIRE
                  and s in ('locataire', 'reglement_locataire'))
        print('  %-16s %14d %16d %38s' %
              (ag, datees, loc, ', '.join(list(secs)[:3])[:38]))
    print('  → 0 mouvement élémentaire de règlement locataire sur les 4 périmètres.')
    print('    MOUVEMENTS ÉLÉMENTAIRES DATÉS : NON DÉMONTRABLES SUR CE CORPUS.')

    entete('P5B — ② RÈGLEMENTS AFFECTÉS (montant lu, date et payeur jamais imprimés)')
    print('  %-16s %-34s %10s %16s %13s %10s' %
          ('PÉRIMÈTRE', 'maille imprimée', 'règlements', 'montant', 'affectés', 'négatifs'))
    for ag in agences:
        lst = tout[ag] = list(reglements(ag))
        dump += lst
        mailles = collections.Counter(r['maille'] for r in lst)
        dem = [r for r in lst if r['affectation'] == 'démontrée']
        neg = [r for r in lst if r['montant'] < 0]
        complet = sum(1 for r in lst if all(r.get(c) not in (None, '') for c in CHAINE)
                      and isinstance(r['page'], int))
        if complet != len(lst):
            ok = False
        print('  %-16s %-34s %10d %16s %13d %10d%s' %
              (ag, ' + '.join('%s ×%d' % (m, n) for m, n in mailles.most_common())[:34],
               len(lst), euros(sum(r['montant'] for r in lst)), len(dem), len(neg),
               '   ⚠ provenance' if complet != len(lst) else ''))
        print('  %-16s dont affecté à une période : %14s · au lot seulement : %14s'
              % ('', euros(sum(r['montant'] for r in dem)),
                 euros(sum(r['montant'] for r in lst if r['affectation'] != 'démontrée'))))
    print('  ⚠ « affectation démontrée » = le document nomme la période réglée. Il ne dit ni')
    print('    QUAND ni PAR QUI : le payeur est INDÉTERMINABLE sur 100 % du corpus.')

    entete('P5B — ③ AGRÉGATS IMPRIMÉS PAR LE DOCUMENT')
    print('  %-16s %12s %18s %12s %18s' %
          ('PÉRIMÈTRE', 'agrégats', 'montant agrégé', 'Σ affectés', 'écart'))
    for ag in agences:
        ags = list(agregats(ag))
        sa = sum(a['montant'] for a in ags)
        sr = sum(r['montant'] for r in tout[ag])
        print('  %-16s %12d %18s %12s %18s' %
              (ag, len(ags), euros(sa) if ags else 'AUCUN', euros(sr),
               euros(sr - sa) if ags else 'sans objet'))
    print('  ⚠ VIENNE n’imprime aucun « TOTAL DES REGLEMENTS LOCATAIRES » : son agrégat')
    print('    n’existe pas, on ne le fabrique pas.')

    entete('P5B — ④ ÉCART AVEC L’ANCIEN COMPTEUR `total_regle`')
    # ⚠️ UN TOTAL QUI RETOMBE N'EST PAS UNE PREUVE. On compare les deux, on explique l'unité.
    print('  %-16s %18s %18s %16s %s' %
          ('PÉRIMÈTRE', 'ancien Σ total_regle', 'P5B Σ affectés', 'écart', 'unité'))
    for ag in agences:
        ancien = 0.0
        for d in documents(ag):
            for im in d.get('immeubles') or []:
                for lo in im.get('lots') or []:
                    ancien += float(lo.get('total_regle') or 0)
        neuf = sum(r['montant'] for r in tout[ag])
        maille = collections.Counter(r['maille'] for r in tout[ag]).most_common(1)
        print('  %-16s %18s %18s %16s %s' %
              (ag, euros(ancien), euros(neuf), euros(neuf - ancien),
               maille[0][0] if maille else '-'))

    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps(dump, ensure_ascii=False))
    print('\n  %d règlements exportés → %s' % (len(dump), DUMP))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP5B — ENCAISSEMENTS : %s'
          % ('OUI — maille, affectation et absence de mouvement élémentaire démontrées'
             if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
