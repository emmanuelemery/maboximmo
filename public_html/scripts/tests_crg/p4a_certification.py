# -*- coding: utf-8 -*-
"""
P4A — LOCATAIRES : QUI, et rien d'autre.

⚠️ TROIS NOTIONS, JAMAIS FONDUES. L'OCCURRENCE est une apparition dans un CRG. L'IDENTITÉ est
   la personne, le couple ou la personne morale qu'elle désigne. L'OCCUPATION est la relation
   dans le temps entre cette identité et un bien — et elle appartient à P4B. Confondre les deux
   premières multiplie le fichier locataires par le nombre de trimestres ; confondre la
   deuxième et la troisième fait d'un débiteur parti un occupant actuel.

⚠️ ET UNE QUATRIÈME UNITÉ, QU'ON AVAIT LAISSÉE SE CONFONDRE AVEC L'OCCURRENCE : la SITUATION,
   c'est-à-dire le bloc de lot lu dans le CRG. Les deux ne coïncident pas :

     1 957 situations contrôlées   =   1 956 occurrences locataires   +   1 lot sans nom

   Le lot 190 de VIENNE (compte 1105404745) n'imprime AUCUNE ligne « Locataire: ». Un lot sans
   ligne locataire ne doit jamais devenir une occurrence locataire — sinon on crée un locataire
   que le document ne nomme pas. C'est ce 1 de différence, et il est à sa place (`P1-EXC-03`).
   Les natures se comptent donc sur les 1 956, pas sur les 1 957.

⚠️ REVOIR UN LOCATAIRE DANS PLUSIEURS CRG NE PROUVE PAS QU'IL OCCUPE ENCORE. Cela prouve
   seulement qu'il apparaît encore dans les documents — une dette, un crédit, une
   régularisation suffisent à l'y maintenir des années.

⚠️ ET UN NOM IDENTIQUE NE FAIT PAS UNE MÊME PERSONNE. La normalisation (casse, accents,
   ponctuation, espaces) sert à RECHERCHER ; elle ne fusionne rien et n'altère jamais le
   libellé source, conservé à l'octet près.

Lecture seule. Aucune écriture.
"""
import sys
import os
import re
import collections
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

# ── Chiffres de référence, mesurés le 30/08/2026 ───────────────────────────────────────────
ATTENDU = {
    'LYON hors SIR': dict(situations=287, occurrences=287),
    'EMERY IMMO':    dict(situations=384, occurrences=384),
    'VIENNE':        dict(situations=111, occurrences=110),   # le lot 190 n'a pas de locataire
    'GROUPE SIR':    dict(situations=1175, occurrences=1175),
}

# ⚠️ SEPTEO IMPRIME LES NOMS ESPACES COLLÉS sur 84 des 110 libellés de VIENNE
#    (« MILLERDEOLIVEIRAouBILLONBryanetElodie »). Une comparaison qui garde les espaces y voit
#    deux personnes différentes là où le document n'en nomme qu'une.
RE_MORALE = re.compile(r'\b(SCI|SC|SARL|SAS|SASU|SNC|SA|EURL|SCP|SCM|SCCV|GIE|GFA|ASSOCIATION|'
                       r'SYNDICAT|FONCIERE|CABINET|HOLDING|ETS|ETABLISSEMENTS|ENTREPRISE|'
                       r'BOUTIQUE|RESTAURANT|PHARMACIE|BANQUE|MUTUELLE|CULTUELLE)\b')
# ⚠️ CHEZ SEPTEO, LA RAISON SOCIALE EST COLLÉE AU NOM : « CULTUELLELESTEMOINS… », « SARLMARTEL… »,
#    « HATAYANBoutiqueGENTLEMAN ». Aucune frontière de mot n'existe, donc `\b` ne trouve rien —
#    et VIENNE affichait 110 personnes physiques, 0 morale. On rejoue donc les seuls termes assez
#    longs pour ne pas se confondre avec une syllabe de patronyme.
RE_MORALE_COLLEE = re.compile(r'(ASSOCIATION|CULTUELLE|SYNDICAT|FONCIERE|CABINET|HOLDING|'
                              r'BOUTIQUE|RESTAURANT|PHARMACIE|MUTUELLE|ETABLISSEMENTS|'
                              r'ENTREPRISE|SARL|EURL|SASU)')
RE_COUPLE = re.compile(r'\b(ET MME|ET MADAME|M ET MME|MR ET MME|EPOUX|EPOUSE|CONJOINTS|'
                       r'ET M|OU MME|OU M)\b')
RE_COTIT = re.compile(r'\bET\b|\bOU\b')
# ⚠️ ET LES SÉPARATEURS SONT DÉTRUITS PAR LA NORMALISATION. `plat()` remplace « & » et « // »
#    par des espaces : « BILLARDJustine//SCHULTZCélia » redevenait une personne seule. On les
#    lit donc sur le libellé BRUT — mais seulement entre deux lettres : chez LYON,
#    « RAMDANI MADJID///// » et « ZIZAH/// MOHAMMED » portent des barres de remplissage
#    d'impression, pas une cotitularité.
RE_SEP_BRUT = re.compile(r'[A-Za-z](&|/+)[A-Za-z]')
RE_LIAISON_COLLEE = re.compile(r'[a-z](et|ou)[A-Z]')
RE_INDIV = re.compile(r'\b(INDIVISION|IND|SUCCESSION|HOIRIE|HERITIERS?|CONSORTS)\b')


def plat(s):
    s = unicodedata.normalize('NFD', str(s or ''))
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    return ' '.join(re.sub(r'[^0-9A-Za-z ]+', ' ', s).split()).upper()


def colle(s):
    """La forme sans espaces : le seul terrain où « DUPONT Jean » et « DUPONTJean » se
       rencontrent. Sert UNIQUEMENT à rapprocher, jamais à réécrire."""
    return plat(s).replace(' ', '')


def nature(libelle):
    """La nature que la source DÉCLARE — jamais une déduction sur la ressemblance.

       ⚠️ ON NE DÉCOUPE PAS UN LIBELLÉ COLLECTIF. « M. ET MME X » reste une seule occurrence
          de nature `couple` : le document ne dit pas comment les titulaires sont organisés
          juridiquement, et inventer deux personnes serait aussi faux que n'en voir qu'une."""
    brut = str(libelle or '')
    u = plat(brut)
    if not u:
        return 'sans nom'
    if RE_INDIV.search(u):
        return 'indivision'
    if RE_MORALE.search(u) or RE_MORALE_COLLEE.search(u.replace(' ', '')):
        return 'personne morale'
    if RE_COUPLE.search(u):
        return 'couple'
    if RE_COTIT.search(u) or RE_SEP_BRUT.search(brut) or RE_LIAISON_COLLEE.search(brut):
        return 'cotitulaires'
    return 'personne physique'


def situations(nom):
    """Tout BLOC DE LOT lu dans un CRG, nommé ou non, avec sa provenance complète.

       ⚠️ LA PROVENANCE EST LA RAISON D'ÊTRE DE CETTE PHASE. Chaque ligne doit permettre le
          chemin inverse MBI → LOCATAIRE → CRG → PAGE, sans quoi rien n'est vérifiable."""
    for d in documents(nom):
        m = d.get('meta') or {}
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip()
            # ⚠️ LE CODE IMMEUBLE PORTE SON COMPTE EN PRÉFIXE : `01040087` = compte `0104` +
            #    immeuble `0087`. Deux comptes qui gèrent le même bâtiment impriment donc deux
            #    codes différents pour la MÊME adresse — `01040087` et `01210087`. Le rang seul
            #    permet de reconnaître le local physique, sans jamais toucher à la clé certifiée
            #    P3B, qui reste `(agence, code complet)`.
            rang = code[4:] if code.isdigit() and len(code) == 8 else code
            for lo in im.get('lots') or []:
                lib = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                yield {
                    'crg': d.get('_nom_original'), 'sha': d.get('_sha'),
                    'page': lo.get('page'), 'periode_cle': m.get('periode_cle'),
                    'date_arrete': m.get('date_arrete'),
                    'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                    'compte': str(m.get('compte') or ''),
                    'immeuble': code or ('NOM:' + plat(im.get('nom') or im.get('adresse'))),
                    'immeuble_rang': rang, 'immeuble_nom': plat(im.get('nom') or im.get('adresse')),
                    'lot': str(lo.get('numero_lot') or ''),
                    'libelle_locataire_source': lib,
                    'identite_proposee': colle(lib), 'nature': nature(lib),
                }


def occurrences(nom):
    """Les seules situations qui NOMMENT un locataire. Un lot muet n'en fabrique pas un."""
    return [s for s in situations(nom) if s['identite_proposee']]


CHAINE = ('proprietaire', 'compte', 'immeuble', 'lot')
PROVENANCE = ('crg', 'sha', 'page', 'periode_cle', 'date_arrete', 'proprietaire', 'compte',
              'immeuble', 'lot', 'libelle_locataire_source', 'identite_proposee')


def certifier(noms):
    ok = True
    tout = {}

    entete('P4A — DEUX UNITÉS DISTINCTES : SITUATIONS CONTRÔLÉES ≠ OCCURRENCES LOCATAIRES')
    print('  %-16s %11s %13s %10s %12s %11s' %
          ('PÉRIMÈTRE', 'situations', 'occurrences', 'lots muets', 'identités', 'provenance'))
    for nom in noms:
        sit = list(situations(nom))
        occ = tout[nom] = [s for s in sit if s['identite_proposee']]
        att = ATTENDU.get(nom, {})
        muets = len(sit) - len(occ)
        ids = {o['identite_proposee'] for o in occ}
        # ⚠️ PROVENANCE COMPLÈTE = les onze champs, pas « un lot existe ».
        complets = sum(1 for o in occ
                       if all(o.get(c) not in (None, '') for c in PROVENANCE)
                       and isinstance(o['page'], int))
        souci = (att.get('situations') not in (None, len(sit))
                 or att.get('occurrences') not in (None, len(occ))
                 or complets != len(occ))
        print('  %-16s %11d %13d %10d %12d %11d%s' %
              (nom, len(sit), len(occ), muets, len(ids), complets, '   ⚠' if souci else ''))
        if souci:
            ok = False
            if att.get('situations') not in (None, len(sit)):
                print('                 ⚠ attendu %d situations' % att['situations'])
            if att.get('occurrences') not in (None, len(occ)):
                print('                 ⚠ attendu %d occurrences' % att['occurrences'])
            if complets != len(occ):
                manque = collections.Counter(
                    c for o in occ for c in PROVENANCE if o.get(c) in (None, ''))
                print('                 ⚠ %d provenances incomplètes : %s'
                      % (len(occ) - complets, dict(manque)))
    tsit = sum(len(list(situations(n))) for n in noms)
    tocc = sum(len(tout[n]) for n in noms)
    print('  %-16s %11d %13d %10d' % ('TOTAL', tsit, tocc, tsit - tocc))
    print('  ⚠ l’écart est le lot VIENNE 190 (compte 1105404745), qui n’imprime aucune ligne')
    print('    « Locataire: » — `P1-EXC-03`. Un lot muet ne fabrique pas un locataire.')

    entete('P4A — OCCURRENCE ≠ IDENTITÉ')
    for nom in noms:
        occ = tout[nom]
        ids = collections.Counter(o['identite_proposee'] for o in occ)
        parId = collections.defaultdict(set)
        for o in occ:
            parId[o['identite_proposee']].add(o['periode_cle'])
        multiP = sum(1 for p in parId.values() if len(p) > 1)
        print('  %-16s %4d occurrences → %4d identités · revues plusieurs fois %4d · '
              'sur plusieurs périodes %4d'
              % (nom, len(occ), len(ids), sum(1 for n in ids.values() if n > 1), multiP))

    entete('P4A — NATURE DÉCLARÉE PAR LA SOURCE (sur les occurrences nommées)')
    print('  %-16s %10s %10s %13s %12s %11s %9s' %
          ('PÉRIMÈTRE', 'physique', 'couple', 'cotitulaires', 'morale', 'indivision', 'somme'))
    for nom in noms:
        c = collections.Counter(o['nature'] for o in tout[nom])
        s = (c['personne physique'] + c['couple'] + c['cotitulaires'] + c['personne morale']
             + c['indivision'])
        print('  %-16s %10d %10d %13d %12d %11d %9d%s' %
              (nom, c['personne physique'], c['couple'], c['cotitulaires'],
               c['personne morale'], c['indivision'], s,
               '   ⚠' if s != len(tout[nom]) else ''))
        if s != len(tout[nom]):
            ok = False

    entete('P4A — CANDIDATS / LIBELLÉS RAPPROCHÉS MULTI-COMPTES (aucune fusion)')
    # ⚠️ « IDENTITÉ MULTI-COMPTES » ÉTAIT UNE AFFIRMATION DE TROP. Un libellé identique sous
    #    deux comptes n'établit pas une même personne. Trois issues, jamais une seule :
    #      DÉMONTRÉE      le même lot physique — adresse imprimée identique ET même n° de lot —
    #                     figure sous deux comptes : c'est le même local, donc le même occupant.
    #      CANDIDAT       lots différents, mais raison sociale ou libellé porteur : plausible.
    #      INDÉTERMINABLE lots différents, personne physique : l'homonymie n'est pas exclue.
    print('  %-16s %12s %11s %11s %16s' %
          ('PÉRIMÈTRE', 'rapprochés', 'démontrée', 'candidat', 'indéterminable'))
    detail = []
    for nom in noms:
        parId = collections.defaultdict(list)
        for o in tout[nom]:
            parId[o['identite_proposee']].append(o)
        amb = {i: os_ for i, os_ in parId.items() if len({o['compte'] for o in os_}) > 1}
        c = collections.Counter()
        for i, os_ in amb.items():
            parLocal = collections.defaultdict(set)
            for o in os_:
                parLocal[(o['immeuble_rang'], o['immeuble_nom'], o['lot'])].add(o['compte'])
            if any(len(v) > 1 for v in parLocal.values()):
                verdict = 'démontrée'
            elif nature(os_[0]['libelle_locataire_source']) == 'personne morale':
                verdict = 'candidat'
            else:
                verdict = 'indéterminable'
            c[verdict] += 1
            detail.append((nom, verdict, os_[0]['libelle_locataire_source'],
                           sorted({o['compte'] for o in os_}),
                           sorted({(o['immeuble_nom'][:30], o['lot']) for o in os_})))
        print('  %-16s %12d %11d %11d %16d' %
              (nom, len(amb), c['démontrée'], c['candidat'], c['indéterminable']))
    print('  %-16s %12d %11d %11d %16d' %
          ('TOTAL', len(detail), sum(1 for d in detail if d[1] == 'démontrée'),
           sum(1 for d in detail if d[1] == 'candidat'),
           sum(1 for d in detail if d[1] == 'indéterminable')))
    for verdict in ('démontrée', 'candidat', 'indéterminable'):
        for nom, v, lib, comptes, locaux in detail:
            if v != verdict:
                continue
            print('    %-14s %-16s « %-28s » %s' % (verdict, nom, lib[:28], ', '.join(comptes)))
            print('    %-14s %-16s   %s' % ('', '', ' · '.join('%s lot %s' % x for x in locaux)[:104]))
    print('  → aucune fusion, aucune création : ces %d cas sont SIGNALÉS.' % len(detail))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP4A — LOCATAIRES : %s' % ('OUI — exhaustivité, unités et provenance démontrées'
                                       if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
