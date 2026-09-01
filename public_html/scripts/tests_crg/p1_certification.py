# -*- coding: utf-8 -*-
"""
PHASE 1 — LES CONTRÔLES DE CERTIFICATION, REJOUABLES.

Quatre contrôles, sur un périmètre au choix :
   1. compteurs et montants        — rien n'a bougé
   2. périodes                     — identité certaine, jamais inventée
   3. traçabilité PDF → page       — chaque information sait d'où elle vient
   4. nommage GED                  — unique, déterministe, sans collision arbitraire

⚠️ UN CONTRÔLE QUI SE TROMPE DE CORPUS NE PROUVE RIEN. Deux fois pendant la Phase 1, un chemin
   resté sur l'ancien corpus a produit un tableau entièrement faux — une fois en annonçant des
   trous inexistants, une fois en les cachant. Le socle impose donc un corpus unique et le
   périmètre versionné ; aucun chemin n'est écrit ici.

Usage :  python p1_certification.py [périmètre]
         périmètre = « LYON hors SIR » | « EMERY IMMO » | « VIENNE » | « GROUPE SIR » | tous
"""
import sys
import os
import re
import collections
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, euros, entete   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

# ── Les compteurs figés le 30/08/2026, validés par Emmanuel ────────────────────────────────
ATTENDU = {
    'LYON hors SIR': dict(crg=117, immeubles=191, lots=287, locataires=287,
                          du=1478826.95, regle=526183.29, encours=952643.66),
    'EMERY IMMO':    dict(crg=224, immeubles=229, lots=384, locataires=384,
                          du=666892.52, regle=522408.16, encours=144484.36),
    'VIENNE':        dict(crg=70, immeubles=79, lots=111, locataires=110,
                          du=71605.80, regle=60998.99, encours=60115.12),
    # ⚠️ GROUPE SIR EST UN PÉRIMÈTRE CERTIFIÉ, validé par Emmanuel le 30/08/2026. Il a révélé
    #    quatre règles (P1-SIR-01 à -04) qu'aucune des trois autres agences ne pouvait montrer :
    #    un bandeau de vœux pris pour raison sociale, un « Trimestre ex 2026 » non reconnu, un
    #    CRG ni trimestriel ni mensuel, et un avis d'acompte qui n'est pas un CRG.
    'GROUPE SIR':    dict(crg=47, immeubles=439, lots=1175, locataires=1175,
                          du=10929244.94, regle=3368099.00, encours=7561145.94),
}

# ⚠️ `du`, `regle` et `encours` SONT DES COMPTEURS DE NON-RÉGRESSION, PAS DES CHIFFRES DE
#    GESTION. Ils servent à détecter une dérive de lecture. En particulier l'encours de GROUPE
#    SIR agrège six trimestres : le lire comme un encours d'agence sommerait un stock entre
#    périodes, ce qu'interdit P1-GED-11.

INTERDITS = re.compile(r'[\\/:*?"<>|\r\n\t]')
SOURCES_SURES = ('trimestre imprime', 'periode imprimee', 'cloture imprimee',
                 'periode du crg imprimee')


def plat(s):
    s = unicodedata.normalize('NFD', str(s or ''))
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    return ' '.join(INTERDITS.sub(' ', s).replace('-', ' ').split()).upper()


def a_page(o):
    """La page peut s'appeler `page` ou `page_debut` — LYON et EMERY nomment ainsi celle de
       l'immeuble, qui s'étend sur plusieurs pages."""
    if not isinstance(o, dict):
        return False
    return any(isinstance(o.get(c), int) and o[c] > 0 for c in ('page', 'page_debut'))


def mesures(docs):
    m = collections.Counter()
    m['crg'] = len(docs)
    for d in docs:
        for im in d.get('immeubles') or []:
            m['immeubles'] += 1
            for lo in im.get('lots') or []:
                m['lots'] += 1
                if (lo.get('locataire') or lo.get('locataire_nom') or '').strip():
                    m['locataires'] += 1
                m['du'] += round(float(lo.get('total_du') or 0), 2)
                m['regle'] += round(float(lo.get('total_regle') or 0), 2)
                m['encours'] += round(float(lo.get('total_impaye') or 0), 2)
    return m


def controle_compteurs(nom, docs):
    m = mesures(docs)
    att = ATTENDU.get(nom)
    print('  %-14s CRG %3d · immeubles %4d · lots %4d · locataires %4d'
          % (nom, m['crg'], m['immeubles'], m['lots'], m['locataires']))
    print('                 du %16s · regle %16s · encours %16s'
          % (euros(m['du']), euros(m['regle']), euros(m['encours'])))
    if att is None:
        print('                 (compteurs observes — perimetre non encore valide)')
        return True
    ok = True
    for cle, ref in att.items():
        obt = m[cle]
        ecart = abs(ref - obt) > (0.02 if isinstance(ref, float) else 0)
        if ecart:
            ok = False
            print('                 ⚠ ECART %s : attendu %s, obtenu %s' % (cle, ref, obt))
    return ok


def controle_periodes(nom, docs):
    src = collections.Counter()
    anomalies = []
    for d in docs:
        m = d.get('meta') or {}
        src[m.get('periode_source')] += 1
        if not m.get('periode_cle') or m.get('periode_source') not in SOURCES_SURES:
            anomalies.append(d.get('_nom_original'))
        if not m.get('trimestre_cle'):
            anomalies.append(d.get('_nom_original'))
    certains = len(docs) - len(set(anomalies))
    print('  %-14s periode certaine %3d/%-3d · sources %s'
          % (nom, certains, len(docs), dict(src)))
    for a in sorted(set(anomalies))[:5]:
        print('                 ⚠ %s' % a)
    return not anomalies


TYPES_PAGE = ('proprietaire', 'compte', 'periode', 'immeuble', 'lot', 'locataire',
              'loyer', 'depense', 'mouvement', 'operation', 'solde')


def controle_tracabilite(nom, docs):
    tot, avec = collections.Counter(), collections.Counter()

    def note(cle, o):
        tot[cle] += 1
        avec[cle] += 1 if a_page(o) else 0

    for d in docs:
        m = d.get('meta') or {}
        for cle in ('proprietaire', 'compte', 'periode'):
            note(cle, m)
        for im in d.get('immeubles') or []:
            note('immeuble', im)
            for lo in im.get('lots') or []:
                note('lot', lo)
                if (lo.get('locataire') or lo.get('locataire_nom') or '').strip():
                    note('locataire', lo)
                for r in (lo.get('mois') or []) + (lo.get('appels') or []):
                    note('loyer', r)
            for c in im.get('charges') or []:
                note('depense', c)
            for mv in im.get('mouvements') or []:
                note('mouvement', mv)
            for s in im.get('soldes_sections') or []:
                note('solde', s)
        man = d.get('mandat') or {}
        for op in man.get('operations') or []:
            note('operation', op)
        for s in man.get('soldes_immeubles') or []:
            note('solde', s)
        for cle in ('solde_final', 'report'):
            if isinstance(man.get(cle), dict):
                note('solde', man[cle])
    manque = [c for c in TYPES_PAGE if tot[c] and avec[c] != tot[c]]
    detail = ' · '.join('%s %d/%d' % (c, avec[c], tot[c]) for c in TYPES_PAGE if tot[c])
    print('  %-14s %s' % (nom, detail))
    for c in manque:
        print('                 ⚠ %s : %d elements sans page' % (c, tot[c] - avec[c]))
    return not manque


def nom_ged(d, niveau):
    m = d.get('meta') or {}
    ims = [str(im.get('code') or '').strip() or str(im.get('nom') or im.get('adresse') or '').strip()
           for im in d.get('immeubles') or []]
    ims = [x for x in ims if x]
    lots = sorted({str(lo.get('numero_lot') or '').strip()
                   for im in d.get('immeubles') or [] for lo in im.get('lots') or []
                   if str(lo.get('numero_lot') or '').strip()})
    b = ['CRG', plat(m.get('proprietaire') or m.get('mandant_nom'))[:40].strip() or 'PROPRIETAIRE INCONNU',
         plat(m.get('compte')) or 'COMPTE INCONNU']
    if niveau >= 2 and ims:
        b.append(plat(ims[0])[:26].strip())
    if niveau >= 3 and lots:
        b.append('LOT ' + '+'.join(lots[:3]))
    b.append(m.get('periode_cle') or 'PERIODE INDETERMINABLE')
    if niveau >= 4 and m.get('date_arrete'):
        b.append('ARRETE ' + str(m['date_arrete']).replace('/', '-'))
    return ' - '.join(b) + '.pdf'


def controle_nommage(nom, docs):
    final, restants, paliers = {}, list(docs), []
    for niveau in (1, 2, 3, 4):
        par = collections.defaultdict(list)
        for d in restants:
            par[nom_ged(d, niveau)].append(d)
        encore = []
        for n_, g in par.items():
            if len(g) == 1:
                final[g[0]['_sha']] = n_
            else:
                encore.extend(g)
        paliers.append('N%d:%d' % (niveau, len(par) - len({k for k, g in par.items() if len(g) > 1})))
        restants = encore
        if not restants:
            break
    noms = list(final.values())
    sales = sum(1 for n_ in noms if set('\\/:*?"<>|') & set(n_) or any(ord(c) > 127 for c in n_))
    ok = not restants and len(set(noms)) == len(docs) and not sales
    print('  %-14s %3d noms · %3d distincts · escalade %s · non conformes %d%s'
          % (nom, len(noms), len(set(noms)), '+'.join(paliers), sales,
             '' if ok else '   ⚠ ECHEC'))
    for d in restants[:3]:
        print('                 ⚠ sans nom unique : %s' % nom_ged(d, 4))
    return ok


def certifier(noms):
    ok = True
    for titre, f in (('COMPTEURS ET MONTANTS', controle_compteurs),
                     ('PERIODES', controle_periodes),
                     ('TRACABILITE PDF -> PAGE', controle_tracabilite),
                     ('NOMMAGE GED', controle_nommage)):
        entete('PHASE 1 · ' + titre)
        for nom in noms:
            docs = documents(nom)
            if not docs:
                print('  %-14s ⚠ AUCUNE EXTRACTION TROUVEE (corpus relu absent ?)' % nom)
                ok = False
                continue
            ok = f(nom, docs) and ok
    return ok


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande and demande in tous else tous
    reussi = certifier(noms)
    print('\nPHASE 1 : %s' % ('OUI — tous les controles passent' if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
