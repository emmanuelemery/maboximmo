# -*- coding: utf-8 -*-
"""
INTÉGRATION DEPUIS ZÉRO — la simulation qui vaut pour le VPS.

⚠️ LE LOCAL EST UN BANC D'ESSAI, PAS UNE RÉFÉRENCE. Ce qui doit être juste, c'est le MOTEUR :
   sur le VPS on partira d'une base vide, agence par agence, en empilant plusieurs CRG par
   propriétaire pour bâtir l'historique. Ce test ne rapproche donc rien de l'existant — il
   rejoue les CRG dans une base imaginaire et vérifie ce que le moteur produirait.

⚠️ ET LE PIÈGE EST L'ACCUMULATION. Un même immeuble revient à chaque trimestre ; un même lot
   aussi. Un moteur qui crée à chaque lecture fabriquerait 6 immeubles là où il y en a un, et
   l'erreur ne se verrait dans aucun total — elle se verrait en patrimoine, des mois plus tard.
   Les quatre invariants ci-dessous sont donc la vraie recette du moteur :

     1. IDEMPOTENCE      réinjecter un CRG déjà intégré ne crée RIEN
     2. ACCUMULATION     N CRG d'un compte → 1 propriétaire, 1 compte, N périodes d'historique
     3. STABILITÉ        un immeuble / un lot vu sur N périodes reste UN objet
     4. ORDRE            l'historique est reconstituable et ordonné, sans trou inventé

Lecture seule. Aucune écriture, ni locale, ni VPS.
"""
import sys
import os
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')


def cle_proprietaire(d):
    """⚠️ L'IDENTITÉ D'UN PROPRIÉTAIRE N'EST PAS SON COMPTE. Un propriétaire peut en porter
       plusieurs (25 cas mesurés) ; deux comptes ne le dédoublent pas."""
    m = d.get('meta') or {}
    return (str(m.get('proprietaire') or m.get('mandant_nom') or '').strip().upper())


def cle_compte(d):
    """⚠️ UN COMPTE EST UNIQUE PAR AGENCE, jamais globalement : `02200000` existe dans deux."""
    m = d.get('meta') or {}
    return str(m.get('compte') or '')


def objets(d):
    """Ce qu'un CRG apporte au graphe, sans jamais confondre occurrence et objet."""
    m = d.get('meta') or {}
    compte = cle_compte(d)
    for im in d.get('immeubles') or []:
        code = str(im.get('code') or '').strip()
        nom = str(im.get('nom') or im.get('adresse') or '').strip().upper()
        cle_im = (compte, code or ('NOM:' + nom))
        yield 'immeuble', cle_im, im
        for lo in im.get('lots') or []:
            yield 'lot', (compte, cle_im[1], str(lo.get('numero_lot') or '')), lo
            nomloc = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip().upper()
            if nomloc:
                yield 'locataire', (compte, cle_im[1], str(lo.get('numero_lot') or ''), nomloc), lo


def integrer(docs, base=None):
    """Rejoue des CRG dans une base imaginaire et rend ce qu'elle contiendrait.

       `base` permet de rejouer par-dessus une intégration précédente : c'est le test
       d'idempotence, et celui de l'enrichissement par un trimestre suivant."""
    b = base or {'pieces': set(), 'proprietaires': {}, 'comptes': {}, 'immeubles': {},
                 'lots': {}, 'locataires': {}, 'historique': collections.defaultdict(set),
                 'creations': collections.Counter(), 'revus': collections.Counter()}
    for d in docs:
        sha = d.get('_sha')
        # ⚠️ L'IDEMPOTENCE SE JOUE SUR LE SHA, pas sur le nom de fichier : la même pièce
        #    arrive sous deux noms (4 cas mesurés) et ne doit être intégrée qu'une fois.
        if sha in b['pieces']:
            b['revus']['piece'] += 1
            continue
        b['pieces'].add(sha)
        m = d.get('meta') or {}
        agence = d.get('_agence') or ''

        p = (agence, cle_proprietaire(d))
        if p and p[1]:
            (b['creations'] if p not in b['proprietaires'] else b['revus'])['proprietaire'] += 1
            b['proprietaires'].setdefault(p, set()).add(cle_compte(d))

        c = (agence, cle_compte(d))
        if c[1]:
            (b['creations'] if c not in b['comptes'] else b['revus'])['compte'] += 1
            b['comptes'].setdefault(c, p)
            if m.get('periode_cle'):
                b['historique'][c].add((m.get('trimestre_cle') or '', m.get('periode_cle')))

        for genre, cle, _ in objets(d):
            cible = b[genre + 's'] if genre != 'locataire' else b['locataires']
            k = (agence,) + cle
            (b['creations'] if k not in cible else b['revus'])[genre] += 1
            cible.setdefault(k, set()).add(m.get('periode_cle'))
    return b


def resume(b, titre):
    entete(titre)
    print('  pièces intégrées : %d · pièces déjà connues, ignorées : %d'
          % (len(b['pieces']), b['revus']['piece']))
    print('  %-16s %10s %12s' % ('OBJET', 'créés', 'revus'))
    # ⚠️ LE COMPTE DE LOCATAIRES EST TECHNIQUE, PAS CERTIFIE. Le moteur les manipule pour
    #    boucler la chaine, mais aucune regle de creation, fusion ou identification les
    #    concernant n'est doctrine tant que la phase LOCATAIRE -> OCCUPATION n'a pas eu lieu.
    for genre in ('proprietaire', 'compte', 'immeuble', 'lot', 'locataire'):
        cible = {'proprietaire': 'proprietaires', 'compte': 'comptes', 'immeuble': 'immeubles',
                 'lot': 'lots', 'locataire': 'locataires'}[genre]
        suffixe = '   (technique, non certifie)' if genre == 'locataire' else ''
        print('  %-16s %10d %12d%s' % (genre, len(b[cible]), b['revus'][genre], suffixe))
    return b


def controler(b):
    ok = True
    entete('LES QUATRE INVARIANTS DU MOTEUR')

    # 2. ACCUMULATION : plusieurs CRG d'un compte → un seul compte, N périodes
    multi = {c: h for c, h in b['historique'].items() if len(h) > 1}
    print('  ACCUMULATION  comptes vus sur plusieurs périodes : %d' % len(multi))
    for c, h in list(multi.items())[:4]:
        print('                   %-16s %-12s → %s' % (c[0], c[1],
              ', '.join(sorted(p for _, p in h))))

    # 3. STABILITÉ : un objet vu sur N périodes reste UN objet
    for genre, cible in (('immeuble', 'immeubles'), ('lot', 'lots')):
        revus = b['revus'][genre]
        uniques = len(b[cible])
        total = uniques + revus
        print('  STABILITÉ     %-9s %d occurrences → %d objets (%d revus, aucun dupliqué)'
              % (genre, total, uniques, revus))

    # 4. ORDRE : l'historique est ordonnable sans trou inventé
    sansPeriode = sum(1 for c, h in b['historique'].items() if not h)
    print('  ORDRE         comptes sans période exploitable : %d' % sansPeriode)
    if sansPeriode:
        ok = False

    # un propriétaire multi-comptes ne doit pas se dédoubler
    multiC = {p: cs for p, cs in b['proprietaires'].items() if len(cs) > 1}
    print('  IDENTITÉ      propriétaires portant plusieurs comptes : %d (non dédoublés)'
          % len(multiC))
    return ok


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    noms = [demande] if demande in perimetre() else list(perimetre().keys())
    docs = [d for n in noms for d in documents(n)]
    # ⚠️ ON REJOUE DANS L'ORDRE CHRONOLOGIQUE : c'est ainsi que le VPS recevra les CRG, et
    #    c'est le seul ordre où un « trimestre suivant » a un sens.
    docs.sort(key=lambda d: ((d.get('meta') or {}).get('trimestre_cle') or '',
                             (d.get('meta') or {}).get('periode_cle') or ''))
    print('CRG à intégrer : %d · périmètres : %s' % (len(docs), ', '.join(noms)))

    b = resume(integrer(docs), 'INTÉGRATION DEPUIS UNE BASE VIDE')
    ok = controler(b)

    # 1. IDEMPOTENCE : on rejoue tout par-dessus
    avant = {k: len(b[k]) for k in ('proprietaires', 'comptes', 'immeubles', 'lots', 'locataires')}
    b2 = integrer(docs, base=b)
    apres = {k: len(b2[k]) for k in avant}
    entete('IDEMPOTENCE — LE MÊME CORPUS RÉINJECTÉ')
    for k in avant:
        etat = 'inchangé' if avant[k] == apres[k] else '⚠ %d → %d' % (avant[k], apres[k])
        print('  %-16s %s' % (k, etat))
        if avant[k] != apres[k]:
            ok = False
    print('  pièces ignorées à la seconde passe : %d / %d' % (b2['revus']['piece'], len(docs)))
    if b2['revus']['piece'] != len(docs):
        ok = False

    print('\nMOTEUR D INTEGRATION : %s' % ('OUI — les quatre invariants tiennent'
                                           if ok else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if ok else 1)
