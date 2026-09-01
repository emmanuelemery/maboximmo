# -*- coding: utf-8 -*-
"""
BLOC 3 — STRUCTURE DE GESTION : la recette, sur la définition qui compte.

⚠️ LA QUESTION N'EST PLUS « LE CRG RETROUVE-T-IL L'ANCIEN OBJET MBI ? » — le local est un banc
   d'essai, le VPS partira d'une base vide. La question certifiable est :

       en partant de rien, plusieurs CRG successifs reconnaissent-ils DURABLEMENT le même
       compte, le même immeuble et le même bien, sans jamais les dupliquer ?

   C'est cela que les trois sous-phases prouvent, chacune indépendamment :

     P3A  COMPTES      un compte est stable, unique par agence, et un propriétaire peut en
                       porter plusieurs sans se dédoubler
     P3B  IMMEUBLES    un immeuble revu à chaque période reste UN immeuble
     P3C  LOTS/BIENS   un lot revu à chaque période reste UN bien, dans le bon immeuble

⚠️ ET L'ORDRE COMPTE. Les CRG sont rejoués du plus ancien au plus récent, comme le VPS les
   recevra : c'est le seul ordre où « le trimestre suivant » a un sens, et le seul où une
   duplication se voit.

⚠️ LES LOCATAIRES NE SONT PAS CERTIFIÉS ICI. Le moteur les manipule techniquement, mais aucune
   règle de création, de fusion ou d'identification de locataire n'est doctrine tant que la
   phase LOCATAIRE → OCCUPATION n'a pas été traitée.

Lecture seule. Aucune écriture, ni locale, ni VPS.
"""
import sys
import os
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

# ── Chiffres de référence, mesurés le 30/08/2026 sur les 458 CRG ───────────────────────────
ATTENDU = {
    'LYON hors SIR': dict(crg=117, proprietaires=60, comptes=60,
                          occ_immeuble=191, immeubles=98, occ_lot=287, lots=128),
    'EMERY IMMO':    dict(crg=224, proprietaires=198, comptes=224,
                          occ_immeuble=229, immeubles=229, occ_lot=384, lots=321),
    'VIENNE':        dict(crg=70, proprietaires=61, comptes=61,
                          occ_immeuble=79, immeubles=76, occ_lot=111, lots=111),
    'GROUPE SIR':    dict(crg=47, proprietaires=14, comptes=16,
                          occ_immeuble=439, immeubles=129, occ_lot=1175, lots=213),
}


def plat(s):
    return ' '.join(str(s or '').split()).upper()


def cles(d):
    """Les identités que le moteur doit reconnaître, et rien d'autre."""
    m = d.get('meta') or {}
    compte = str(m.get('compte') or '')
    prop = plat(m.get('proprietaire') or m.get('mandant_nom'))
    periode = m.get('periode_cle')
    for im in d.get('immeubles') or []:
        code = str(im.get('code') or '').strip()
        nom = plat(im.get('nom') or im.get('adresse'))
        # ⚠️ SANS LE COMPTE, DEUX MANDANTS PARTAGEANT UN IMMEUBLE SE CONFONDRAIENT.
        cim = (compte, code or ('NOM:' + nom))
        pageIm = im.get('page') if isinstance(im.get('page'), int) else im.get('page_debut')
        yield ('immeuble', cim, periode, pageIm, prop, compte)
        for lo in im.get('lots') or []:
            clot = (compte, cim[1], str(lo.get('numero_lot') or ''))
            yield ('lot', clot, periode, lo.get('page'), prop, compte)


def mesurer(nom):
    docs = documents(nom)
    # l'ordre chronologique, comme le VPS les recevra
    docs.sort(key=lambda d: ((d.get('meta') or {}).get('trimestre_cle') or '',
                             (d.get('meta') or {}).get('periode_cle') or ''))
    props, comptes = {}, {}
    objets = {'immeuble': {}, 'lot': {}}
    occ = collections.Counter()
    sansPage = collections.Counter()
    chaine_rompue = 0
    for d in docs:
        m = d.get('meta') or {}
        p = plat(m.get('proprietaire') or m.get('mandant_nom'))
        c = str(m.get('compte') or '')
        if p:
            props.setdefault(p, set()).add(c)
        if c:
            comptes.setdefault(c, set()).add(p)
        for genre, cle, periode, page, prop, cpt in cles(d):
            occ[genre] += 1
            objets[genre].setdefault(cle, {'periodes': set(), 'proprios': set(), 'sha': set()})
            objets[genre][cle]['periodes'].add(periode)
            objets[genre][cle]['proprios'].add(prop)
            objets[genre][cle]['sha'].add(d.get('_sha'))
            if not isinstance(page, int):
                sansPage[genre] += 1
            if not (prop and cpt and cle[1] and (genre == 'immeuble' or cle[2])):
                chaine_rompue += 1
    return dict(docs=docs, props=props, comptes=comptes, objets=objets, occ=occ,
                sansPage=sansPage, chaine_rompue=chaine_rompue)


def certifier(noms):
    ok = True

    entete('P3A — COMPTES MANDANTS')
    print('  %-16s %6s %13s %9s %11s %14s' %
          ('PÉRIMÈTRE', 'CRG', 'propriétaires', 'comptes', 'multi-cpt', 'cpt partagés'))
    mes = {}
    for nom in noms:
        r = mes[nom] = mesurer(nom)
        att = ATTENDU.get(nom, {})
        multi = sum(1 for cs in r['props'].values() if len(cs) > 1)
        # ⚠️ UN COMPTE PORTÉ PAR DEUX PROPRIÉTAIRES DANS UN MÊME PÉRIMÈTRE SERAIT UNE FAUTE.
        partages = sum(1 for ps in r['comptes'].values() if len(ps) > 1)
        souci = (att.get('crg') not in (None, len(r['docs']))
                 or att.get('proprietaires') not in (None, len(r['props']))
                 or att.get('comptes') not in (None, len(r['comptes']))
                 or partages > 0)
        print('  %-16s %6d %13d %9d %11d %14d%s' % (nom, len(r['docs']), len(r['props']),
              len(r['comptes']), multi, partages, '   ⚠' if souci else ''))
        if souci:
            ok = False
            for k, v in (('crg', len(r['docs'])), ('proprietaires', len(r['props'])),
                         ('comptes', len(r['comptes']))):
                if att.get(k) not in (None, v):
                    print('                 ⚠ %s : attendu %d, obtenu %d' % (k, att[k], v))

    for phase, genre, titre in (('P3B', 'immeuble', 'IMMEUBLES'), ('P3C', 'lot', 'LOTS / BIENS')):
        entete('%s — %s' % (phase, titre))
        print('  %-16s %12s %10s %8s %14s %12s' %
              ('PÉRIMÈTRE', 'occurrences', 'objets', 'ratio', 'multi-période', 'sans page'))
        for nom in noms:
            r = mes[nom]
            att = ATTENDU.get(nom, {})
            n_occ, n_obj = r['occ'][genre], len(r['objets'][genre])
            multiP = sum(1 for o in r['objets'][genre].values() if len(o['periodes']) > 1)
            sp = r['sansPage'][genre]
            aOcc = att.get('occ_' + ('immeuble' if genre == 'immeuble' else 'lot'))
            aObj = att.get('immeubles' if genre == 'immeuble' else 'lots')
            souci = (aOcc not in (None, n_occ)) or (aObj not in (None, n_obj)) or sp > 0
            print('  %-16s %12d %10d %8s %14d %12d%s' % (nom, n_occ, n_obj,
                  ('×%.2f' % (n_occ / n_obj)) if n_obj else '-', multiP, sp, '   ⚠' if souci else ''))
            if souci:
                ok = False
                if aOcc not in (None, n_occ):
                    print('                 ⚠ occurrences : attendu %d, obtenu %d' % (aOcc, n_occ))
                if aObj not in (None, n_obj):
                    print('                 ⚠ objets : attendu %d, obtenu %d' % (aObj, n_obj))

    entete('LES INVARIANTS DU BLOC')
    tot = collections.Counter()
    for nom in noms:
        r = mes[nom]
        tot['chaine'] += r['chaine_rompue']
        tot['occ_im'] += r['occ']['immeuble']; tot['im'] += len(r['objets']['immeuble'])
        tot['occ_lot'] += r['occ']['lot']; tot['lot'] += len(r['objets']['lot'])
        # un objet ne doit jamais changer de propriétaire entre deux périodes
        for genre in ('immeuble', 'lot'):
            tot['migrants'] += sum(1 for o in r['objets'][genre].values() if len(o['proprios']) > 1)
    print('  CHAÎNE        propriétaire → compte → immeuble → lot rompue : %d fois' % tot['chaine'])
    print('  STABILITÉ     immeubles %d occurrences → %d objets · lots %d → %d'
          % (tot['occ_im'], tot['im'], tot['occ_lot'], tot['lot']))
    print('  APPARTENANCE  objets ayant changé de propriétaire entre périodes : %d' % tot['migrants'])
    print('  PAGE          objets sans page source : %d'
          % sum(r['sansPage'][g] for r in mes.values() for g in ('immeuble', 'lot')))
    if tot['chaine'] or tot['migrants']:
        ok = False

    entete('⚠ NON CERTIFIÉ PAR CE BLOC')
    print('  Les LOCATAIRES sont manipulés techniquement par le moteur mais aucune règle de')
    print('  création, fusion ou identification ne les concernant n\'est doctrine à ce stade.')
    print('  Les VALEURS FINANCIÈRES ne sont pas contrôlées ici : un loyer est un flux, un')
    print('  encours un stock à une date (P1-GED-11). Phase dédiée.')
    return ok


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi = certifier(noms)
    print('\nBLOC 3 — STRUCTURE : %s' % ('OUI — les trois sous-phases passent'
                                         if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
