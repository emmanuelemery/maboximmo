# -*- coding: utf-8 -*-
"""
PHASE 2 — PROPRIÉTAIRES : les contrôles de certification, rejouables.

⚠️ CE TEST NE FIGE PAS L'ÉTAT DE LA BASE MBI, ET C'EST DÉLIBÉRÉ. Le rapprochement dépend du
   contenu de `tiers` et `proprietaires`, qui bougent légitimement — une fiche renommée, un
   tiers créé. Geler la sortie du rapprochement ferait passer au rouge une modification normale
   de la base, et l'on finirait par ignorer le harnais. Ce qui est figé ici, c'est ce qui
   appartient au LECTEUR et au CORPUS : l'identité que la source imprime.

   Le rapprochement, lui, se rejoue à la demande :
       php tests_crg/p2_rapprocher.php
   et ses arbitrages humains vivent dans `arbitrages/p2_proprietaires.json` — versionnés, parce
   qu'ils portent des décisions d'Emmanuel qu'aucun calcul ne doit pouvoir écraser.

Quatre contrôles :
   1. le propriétaire est extrait sur 100 % des CRG        (aucun perdu)
   2. aucun nom vide, mutilé, décoratif                     (aucun inventé)
   3. le nombre d'identités source distinctes est stable    (occurrence ≠ identité)
   4. la nature juridique déclarée par la source est stable (pas de glissement de typage)
"""
import sys
import os
import re
import collections
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

# ── Figé le 30/08/2026, validé par Emmanuel ────────────────────────────────────────────────
ATTENDU = {
    'LYON hors SIR': dict(occurrences=117, identites=60,
                          natures={'couple': 24, 'personne physique (M.)': 39,
                                   'personne morale': 36, 'personne physique (Mme)': 15,
                                   'indivision': 3}),
    'EMERY IMMO':    dict(occurrences=224, identites=198,
                          natures={'couple': 40, 'personne physique (M.)': 75,
                                   'personne physique (sans civilite)': 11,
                                   'personne physique (Mme)': 66, 'personne morale': 25,
                                   'indivision': 7}),
    'VIENNE':        dict(occurrences=70, identites=61,
                          natures={'personne physique (M.)': 28, 'personne physique (Mme)': 19,
                                   'personne morale': 14, 'personne physique (sans civilite)': 3,
                                   'indivision': 2, 'couple': 4}),
    'GROUPE SIR':    dict(occurrences=47, identites=14,
                          natures={'personne morale': 42, 'personne physique (M.)': 5}),
}

RE_SCI = re.compile(r'\b(SCI|SC|SARL|SAS|SASU|SNC|SA|EURL|SCP|SCM|GIE|GPE|GROUPE|ASSOCIATION|'
                    r'SYNDICAT|FONCIERE|CABINET|HOLDING)\b')
RE_INDIV = re.compile(r'\b(INDIVISION|IND|SUCCESSION|HOIRIE|HERITIERS?)\b')
RE_COUPLE = re.compile(r'\b(ET MME|ET MADAME|ET M|M ET MME|MR ET MME|EPOUX|CONJOINTS)\b|&')
RE_CIV_M = re.compile(r'^(M|MR|MONSIEUR)\b')
RE_CIV_F = re.compile(r'^(MME|MADAME|MLLE)\b')


def plat(s):
    s = unicodedata.normalize('NFD', str(s or ''))
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    return ' '.join(re.sub(r'[^0-9A-Za-z ]+', ' ', s).split()).upper()


def nature(nom):
    """La nature juridique que la source DÉCLARE — jamais une déduction sur la ressemblance."""
    u = plat(nom)
    if RE_INDIV.search(u):
        return 'indivision'
    if RE_SCI.search(u):
        return 'personne morale'
    if RE_COUPLE.search(u):
        return 'couple'
    if RE_CIV_F.match(u):
        return 'personne physique (Mme)'
    if RE_CIV_M.match(u):
        return 'personne physique (M.)'
    return 'personne physique (sans civilite)'


def nom_source(d):
    m = d.get('meta') or {}
    return str(m.get('proprietaire') or m.get('mandant_nom') or '').strip()


def certifier(noms):
    ok = True

    entete('PHASE 2 · LE PROPRIETAIRE EST-IL EXTRAIT, ET INTACT ?')
    for nom in noms:
        docs = documents(nom)
        att = ATTENDU.get(nom, {})
        vides = [d for d in docs if not nom_source(d)]
        mutiles = [d for d in docs if '�' in nom_source(d)]
        decor = [d for d in docs if sum(c in '*=_~' for c in nom_source(d)) >= 3]
        sansPage = [d for d in docs if not isinstance((d.get('meta') or {}).get('page'), int)]
        extraits = len(docs) - len(vides)
        att_occ = att.get('occurrences')
        souci = (vides or mutiles or decor or sansPage
                 or (att_occ is not None and len(docs) != att_occ))
        print('  %-14s %3d/%-3d extraits · vides %d · mutiles %d · decoratifs %d · sans page %d%s'
              % (nom, extraits, len(docs), len(vides), len(mutiles), len(decor), len(sansPage),
                 '' if not souci else '   ⚠ ECHEC'))
        if att_occ is not None and len(docs) != att_occ:
            print('                 ⚠ %d occurrences attendues, %d trouvees' % (att_occ, len(docs)))
        for d in (vides + mutiles + decor)[:3]:
            print('                 ⚠ %s : « %s »' % (d.get('_nom_original'), nom_source(d)))
        ok = ok and not souci

    entete('PHASE 2 · OCCURRENCE N EST PAS IDENTITE')
    for nom in noms:
        docs = documents(nom)
        att = ATTENDU.get(nom, {})
        ids = {plat(nom_source(d)) for d in docs if nom_source(d)}
        attendu = att.get('identites')
        souci = attendu is not None and len(ids) != attendu
        print('  %-14s %3d occurrences -> %3d identites distinctes%s'
              % (nom, len(docs), len(ids),
                 '' if not souci else '   ⚠ attendu %d' % attendu))
        ok = ok and not souci

    entete('PHASE 2 · LA NATURE JURIDIQUE DECLAREE PAR LA SOURCE')
    for nom in noms:
        docs = documents(nom)
        att = ATTENDU.get(nom, {}).get('natures')
        obtenu = collections.Counter(nature(nom_source(d)) for d in docs if nom_source(d))
        souci = att is not None and dict(obtenu) != att
        print('  %-14s %s%s' % (nom, dict(obtenu), '' if not souci else '   ⚠ ECART'))
        if souci:
            for k in set(list(att) + list(obtenu)):
                if att.get(k, 0) != obtenu.get(k, 0):
                    print('                 ⚠ %-34s attendu %d, obtenu %d'
                          % (k, att.get(k, 0), obtenu.get(k, 0)))
        ok = ok and not souci

    entete('PHASE 2 · LES ARBITRAGES HUMAINS SONT-ILS TOUJOURS LA ?')
    chemin = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                          'arbitrages', 'p2_proprietaires.json')
    if not os.path.exists(chemin):
        print('  ⚠ FICHIER D ARBITRAGES ABSENT — les decisions d Emmanuel ne sont plus versionnees')
        ok = False
    else:
        import json
        import io as _io
        a = json.load(_io.open(chemin, encoding='utf-8'))
        rendus = [k for k in a if not k.startswith('_')]
        vienne = len((a.get('_vienne') or {}).get('correspondances') or {})
        print('  %d arbitrages nominatifs + %d correspondances VIENNE' % (len(rendus), vienne))
        if len(rendus) < 8 or vienne < 8:
            print('  ⚠ des arbitrages ont disparu du fichier')
            ok = False
    return ok


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande and demande in tous else tous
    reussi = certifier(noms)
    print('\nPHASE 2 : %s' % ('OUI — tous les controles passent'
                              if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
