# -*- coding: utf-8 -*-
"""
BANC D'ESSAI DES LECTEURS PDF — POPPLER CONTRE XPDF, À RÈGLES MÉTIER CONSTANTES.

⚠️ CE BANC EXISTE PARCE QUE J'AI CHOISI UN LECTEUR SUR SON NOM. Le 02/09/2026 j'ai écrit
   `sait_table = 'poppler' in version` : c'était l'inverse de la vérité — `-table` est une
   option **Xpdf**. Le moteur lançait donc une option inexistante, échouait, et retombait en
   silence sur `-layout`. On ne choisit plus un lecteur par raisonnement : on le mesure.

⚠️ SEULE L'EXTRACTION CHANGE. Même code, mêmes documents, mêmes règles. Ce banc ne touche à
   aucune doctrine métier : il compte ce que chaque binaire rend LISIBLE, et rien d'autre.

⚠️ ET IL NE COMPARE PAS DES VÉRITÉS, IL COMPARE DES CAPACITÉS DE LECTURE. Un lecteur qui
   trouve plus de lots n'a pas forcément raison ; un lecteur qui perd des occupants a
   forcément tort. Les deux colonnes se lisent ensemble, jamais l'une seule.

Usage : python bench_lecteur.py [--complet]
        (par défaut : un échantillon borné, pour trancher sans traiter tout le corpus)
"""
import io
import json
import os
import re
import subprocess
import sys
import time

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RACINE)

import crg_integration_phase0 as P0                      # noqa: E402
from crg_format import famille_du_texte                  # noqa: E402
from crg_integration_lots import segments_de_lot         # noqa: E402
import crg_integration_phase4 as P4                      # noqa: E402

# ── LES DEUX CANDIDATS, ET CE QU'ILS SAVENT VRAIMENT FAIRE ────────────────────────────────
CANDIDATS = [
    ('poppler', r'C:\poppler\Library\bin\pdftotext.exe'),
    ('xpdf',    r'C:\Program Files\Git\clangarm64\bin\pdftotext.exe'),
]

# ⚠️ LE HOLDOUT N'ENTRE JAMAIS DANS UN BANC D'ESSAI. Mesurer un lecteur sur les documents
#    réservés à l'examen de généralisation les brûlerait avant l'heure.
HOLDOUT = 'CRG HOLDOUT'

ECHANTILLON = [
    # (nom, dossier ou fichier, nb de fichiers max, plage de pages ou None)
    ('LYON',      r'D:\CRG REGIE EMERY LYON',                          14, None),
    ('EMERY',     r'D:\CRG EMERY IMMO',                                14, None),
    ('VIENNE',    r'D:\CRG REGIE EMERY VIENNE\CRG AVRIL A JUILLET 2026.pdf', 1, (1, 60)),
    ('CHAPONOST', r'D:\CRG CHAPONOST\CRG AVRIL A JUILLET 2026 (2).pdf',      1, (1, 60)),
]

RE_MONTANT = re.compile(r'-?\d{1,3}(?:[\s\u00a0]\d{3})*[.,]\d{2}')
RE_SECTION_TITRE = re.compile(r'^\s*-\s*(.+?)\s*-\s*$', re.M)


def options(exe):
    """Ce que ce binaire propose — demandé, jamais supposé (leçon APP-0002)."""
    try:
        aide = subprocess.run([exe, '-h'], stdout=subprocess.PIPE,
                              stderr=subprocess.STDOUT).stdout.decode('utf-8', 'replace')
    except OSError:
        return set()
    return {m.group(1) for m in re.finditer(r'^\s+-(\w+)', aide, re.M)}


def version(exe):
    try:
        v = subprocess.run([exe, '-v'], stdout=subprocess.PIPE,
                           stderr=subprocess.STDOUT).stdout.decode('utf-8', 'replace')
    except OSError:
        return '?'
    return ' '.join(v.split('\n')[0].split()[-1:]) or '?'


def extraire(exe, chemin, mode, plage):
    """Le texte page par page, dans le mode demandé. Rend (pages, secondes) ou (None, 0)."""
    cmd = [exe, '-' + mode, '-enc', 'UTF-8']
    if plage:
        cmd += ['-f', str(plage[0]), '-l', str(plage[1])]
    cmd += [chemin, '-']
    t = time.time()
    r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        return None, 0.0
    pages = r.stdout.decode('utf-8', 'replace').split('\f')
    if pages and not pages[-1].strip():
        pages.pop()
    return pages, time.time() - t


def mesurer(pages):
    """Ce que ces pages rendent lisible — sans jamais juger si c'est juste."""
    m = dict(pages=len(pages), pages_avec_texte=0, caracteres=0, montants=0,
             familles=set(), crg=0, proprietaires=0, sections=0, sections_inconnues=set(),
             lots=0, lots_avec_occupant=0, continuations=0)
    for texte in pages:
        if texte.strip():
            m['pages_avec_texte'] += 1
        m['caracteres'] += len(texte)
        m['montants'] += len(RE_MONTANT.findall(texte))
        fam = famille_du_texte(texte)
        if fam != 'inconnu':
            m['familles'].add(fam)
        etat, famille, _motif = P0.qualifier(texte)
        if etat in ('DEBUT', 'ENTETE_REGIE'):
            m['crg'] += 1
            info = P0.identifier(texte, famille)
            if info.get('proprietaire'):
                m['proprietaires'] += 1
        for t in RE_SECTION_TITRE.findall(texte):
            m['sections'] += 1
            lect = P4.Lecteur()
            lect.titre('- %s -' % t.strip())
            if str(lect.section or '').startswith('INCONNUE:'):
                m['sections_inconnues'].add(t.strip()[:40])
        for seg in segments_de_lot(texte, 0):
            m['lots'] += 1
            if seg['suite']:
                m['continuations'] += 1
            if seg.get('locataire'):
                m['lots_avec_occupant'] += 1
    m['familles'] = sorted(m['familles'])
    m['sections_inconnues'] = sorted(m['sections_inconnues'])
    return m


def fichiers_de(chemin, maxi):
    if os.path.isfile(chemin):
        return [chemin]
    tout = sorted(f for f in
                  (os.path.join(chemin, x) for x in os.listdir(chemin))
                  if f.lower().endswith('.pdf') and HOLDOUT not in f)
    return tout[:maxi]


def principal():
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')

    print('BANC D\'ESSAI DES LECTEURS — règles métier constantes\n')
    dispo = []
    for nom, exe in CANDIDATS:
        if not os.path.isfile(exe):
            print('  %-9s ABSENT : %s' % (nom, exe))
            continue
        opts = options(exe)
        dispo.append((nom, exe, opts))
        print('  %-9s v%-10s options utiles : %s' % (
            nom, version(exe),
            ' '.join('-' + o for o in ('layout', 'table', 'fixed', 'raw') if o in opts)))
    if len(dispo) < 2:
        print('\nIl faut deux binaires pour comparer.')
        return 1

    resultats = {}
    for corpus, chemin, maxi, plage in ECHANTILLON:
        fichiers = fichiers_de(chemin, maxi)
        print('\n══ %s — %d fichier(s)%s' % (
            corpus, len(fichiers), ' pages %d-%d' % plage if plage else ''))
        for nom, exe, opts in dispo:
            # ⚠️ CHAQUE LECTEUR EST ÉVALUÉ DANS SON MEILLEUR MODE DISPONIBLE, pas dans un
            #    mode commun au plus faible : c'est la capacité réelle qu'on compare.
            for mode in ('layout', 'table'):
                if mode not in opts:
                    continue
                tot = dict(pages=0, pages_avec_texte=0, caracteres=0, montants=0, crg=0,
                           proprietaires=0, sections=0, lots=0, lots_avec_occupant=0,
                           continuations=0, secondes=0.0)
                inconnues, familles, echecs = set(), set(), 0
                for f in fichiers:
                    pages, secs = extraire(exe, f, mode, plage)
                    if pages is None:
                        echecs += 1
                        continue
                    m = mesurer(pages)
                    for k in tot:
                        if k != 'secondes':
                            tot[k] += m.get(k, 0)
                    tot['secondes'] += secs
                    inconnues |= set(m['sections_inconnues'])
                    familles |= set(m['familles'])
                cle = '%s/%s' % (nom, mode)
                tot['sections_inconnues'] = sorted(inconnues)
                tot['familles'] = sorted(familles)
                tot['echecs'] = echecs
                resultats.setdefault(corpus, {})[cle] = tot
                print('   %-16s pages %3d/%-3d · %6d car · %5d montants · %3d CRG · '
                      '%3d proprio · %3d sections (%d inconnues) · %4d lots '
                      '(%3d occupés, %2d suites) · %.1fs%s'
                      % (cle, tot['pages_avec_texte'], tot['pages'], tot['caracteres'],
                         tot['montants'], tot['crg'], tot['proprietaires'], tot['sections'],
                         len(inconnues), tot['lots'], tot['lots_avec_occupant'],
                         tot['continuations'], tot['secondes'],
                         ' · %d ÉCHEC(S)' % echecs if echecs else ''))

    with io.open(os.path.join(RACINE, 'tests_crg', 'bench_lecteur.json'), 'w',
                 encoding='utf-8') as fh:
        json.dump(resultats, fh, ensure_ascii=False, indent=1)
    print('\nrésultats : tests_crg/bench_lecteur.json')
    return 0


if __name__ == '__main__':
    sys.exit(principal())
