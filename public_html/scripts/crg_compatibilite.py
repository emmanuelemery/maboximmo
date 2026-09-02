# -*- coding: utf-8 -*-
"""
COMPATIBILITÉ D'UN NOUVEAU CRG — à passer AVANT tout import.

⚠️ CE LANCEUR EXISTE POUR QU'UN DÉPÔT INCONNU NE PRODUISE JAMAIS SILENCIEUSEMENT UNE DONNÉE
   FAUSSE. Il ne cherche pas à réussir : il cherche à savoir s'il SAIT lire. Une structure
   qu'il ne reconnaît pas doit être ISOLÉE et nommée, jamais interprétée par approximation.

⚠️ IL N'ÉCRIT RIEN. Ni base, ni staging, ni fichier. Il lit le PDF et rend un verdict.

Trois verdicts, et un seul est un feu vert :
    COMPATIBLE — IMPORT POSSIBLE
    COMPATIBLE AVEC EXCEPTIONS ISOLÉES
    NOUVELLE STRUCTURE — ANALYSE NÉCESSAIRE

Usage : python crg_compatibilite.py <chemin.pdf> [--json]
"""
import collections
import json
import os
import re
import sys

RACINE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, RACINE)

import crg_integration_phase0 as P0            # noqa: E402
import crg_integration_phase4 as P4            # noqa: E402
from crg_integration_lots import (immeubles_de, locataires_de,   # noqa: E402
                                  segments_de_lot)
from crg_integration_phase3 import lire_pages as lire_tableau     # noqa: E402

# Les sections que le moteur sait nommer. Toute autre est une STRUCTURE NOUVELLE.
SECTIONS_CONNUES = ('Honoraires de Gestion', 'Charges de syndic', 'Charges Propriétaire',
                    'GLI Assurance', 'GU Assurance', 'Factures dues', 'Récapitulatif',
                    'Indivision', 'Quote-part', 'Votre quote-part')
# ⚠️ LE TITRE DE SECTION PEUT ÊTRE INDENTÉ. Exiger le tiret en colonne 0 ne reconnaissait
#    AUCUNE section sur le document réel — le lanceur annonçait « 0 section reconnue » sur un
#    dépôt qui en porte des centaines, et son verdict ne valait rien.
#
# ⚠️ ET LA FIN DE LIGNE PORTE UN RETOUR CHARIOT. Un test sur une ligne isolée passait
#    — `splitlines()` l’enlève — alors que le fichier entier échouait : le « $ » de
#    MULTILINE s’arrête devant lui. Il faut l’admettre explicitement.
# ⚠️ ET UNE LIGNE DÉCORATIVE N'EST PAS UNE SECTION. Le document trace des filets en tirets
#    — « ------ suffisante) --------------- » — que le motif prenait pour un titre : le
#    lanceur criait « STRUCTURE NOUVELLE » sur un dépôt parfaitement lisible. Un titre
#    porte des lettres et ne commence pas par une suite de tirets.
RE_SECTION = re.compile(r'^[ \t]*-(?!-)\s*([^-].{2,48}?)\s*-[ \t\r]*$', re.M)
RE_TITRE_PLAUSIBLE = re.compile(r'[A-Za-z\u00c0-\u00ff]{3}')
RE_MONTANT = re.compile(r'-?[\d  ]{1,14},\d{2}')


def analyser(chemin):
    """Ce que le lecteur sait et ne sait pas faire de ce document."""
    # ⚠️ ON LIT COMME LES PHASES LISENT, PAS AUTREMENT. La phase 0 reconnaît ses signaux en
    #    `-layout` ; les phases 3 et 4 lisent les lots et les montants en `-table`. Un lanceur
    #    qui n'emploierait qu'un seul mode annoncerait une couverture que l'import ne tiendrait
    #    pas — 41 « lots sans occupant » qui n'existent que dans le mauvais mode.
    textes, mode = P0.lire_pages(chemin)
    tableau, mode_tableau = lire_tableau(chemin)
    if len(tableau) != len(textes):
        tableau = textes
    r = {
        'fichier': os.path.basename(chemin),
        'mode_lecture': '%s + %s' % (mode, mode_tableau),
        'pages': len(textes),
        'document': collections.Counter(),
        'formats': collections.Counter(),
        'periodes': collections.Counter(),
        'sections_connues': collections.Counter(),
        'sections_nouvelles': collections.Counter(),
        'couverture': {},
        'exceptions': [],
    }
    lots, locataires, immeubles, comptes = set(), set(), set(), set()
    lots_sans_occupant = pages_sans_signal = 0
    montants = montants_hors_colonne = 0

    for i, texte in enumerate(textes, 1):
        # ── ce que la page annonce ────────────────────────────────────────────────────────
        etat, fmt, _motif = P0.qualifier(texte)
        r['document'][etat] += 1
        if fmt:
            r['formats'][fmt] += 1
        if etat in ('INCONNU', 'INDETERMINABLE') and texte.strip():
            pages_sans_signal += 1
        d = P0.identifier(texte, fmt or 'septeo_spi')
        if d.get('periode_debut') and d.get('periode_fin'):
            r['periodes'][P0.periode_cle(d['periode_debut'], d['periode_fin'])] += 1
        if d.get('compte'):
            comptes.add(d['compte'])

        vue = tableau[i - 1] if i - 1 < len(tableau) else texte
        # ── les sections : connues, ou NOUVELLES ──────────────────────────────────────────
        for m in RE_SECTION.finditer(vue):
            titre = m.group(1)
            if '---' in titre or not RE_TITRE_PLAUSIBLE.search(titre):
                continue
            if any(k.lower() in titre.lower() for k in SECTIONS_CONNUES):
                r['sections_connues'][titre] += 1
            else:
                r['sections_nouvelles'][titre] += 1

        # ── le patrimoine ─────────────────────────────────────────────────────────────────
        for imm in immeubles_de(vue, i):
            immeubles.add((imm['nom'].upper(), imm['code_postal']))
        for seg in segments_de_lot(vue, i):
            if seg['suite']:
                continue
            lots.add(seg['reference'])
            occ = locataires_de(seg['texte'])
            if not occ:
                lots_sans_occupant += 1
            for o in occ:
                if o['locataire']:
                    locataires.add(o['locataire'])
        montants += len(RE_MONTANT.findall(vue))

    r['couverture'] = {
        'comptes': len(comptes), 'immeubles': len(immeubles), 'lots': len(lots),
        'locataires': len(locataires), 'lots sans occupant imprimé': lots_sans_occupant,
        'pages porteuses de texte sans signal reconnu': pages_sans_signal,
        'montants repérés dans le texte': montants,
    }

    # ── ce qui empêcherait de conclure ────────────────────────────────────────────────────
    if not r['formats']:
        r['exceptions'].append('AUCUN FORMAT RECONNU — le document n’annonce ni en-tête '
                               'septeo ni en-tête lyon. Structure inconnue.')
    if r['sections_nouvelles']:
        for titre, n in r['sections_nouvelles'].most_common():
            r['exceptions'].append('SECTION INCONNUE « %s » (%d fois) — sa nature métier n’est '
                                   'pas démontrable : elle serait isolée, jamais interprétée.'
                                   % (titre, n))
    if pages_sans_signal:
        r['exceptions'].append('%d pages portent du texte sans aucun signal de CRG reconnu.'
                               % pages_sans_signal)
    if montants and not lots:
        r['exceptions'].append('Des montants sont imprimés mais AUCUN bloc de lot n’est '
                               'reconnu : la maille de la preuve serait perdue.')
    if r['mode_lecture'].startswith('OCR') or montants == 0:
        r['exceptions'].append('Aucun montant lisible : le document est probablement une image '
                               'sans couche texte. `UN DOCUMENT SANS COUCHE TEXTE N’EST PAS UN '
                               'DOCUMENT SANS CRG` — il demande un OCR avant import.')

    graves = [e for e in r['exceptions']
              if e.startswith(('AUCUN FORMAT', 'SECTION INCONNUE', 'Aucun montant'))]
    if graves:
        r['verdict'] = 'NOUVELLE STRUCTURE — ANALYSE NÉCESSAIRE'
    elif r['exceptions']:
        r['verdict'] = 'COMPATIBLE AVEC EXCEPTIONS ISOLÉES'
    else:
        r['verdict'] = 'COMPATIBLE — IMPORT POSSIBLE'
    return r


def rendre(r):
    print('COMPATIBILITÉ — %s' % r['fichier'])
    print('  lecture : %s · %d pages' % (r['mode_lecture'], r['pages']))
    print('\nDOCUMENT')
    for k, n in r['document'].most_common():
        print('    %-16s %d pages' % (k, n))
    print('    formats  : %s' % (', '.join('%s ×%d' % (f, n)
                                           for f, n in r['formats'].most_common()) or '—'))
    print('    périodes : %s' % (', '.join('%s ×%d' % (p, n)
                                           for p, n in r['periodes'].most_common(6)) or '—'))
    print('\nCOUVERTURE')
    for k, v in r['couverture'].items():
        print('    %-46s %d' % (k, v))
    print('\nSTRUCTURE')
    print('    sections reconnues : %d' % sum(r['sections_connues'].values()))
    if r['sections_nouvelles']:
        for t, n in r['sections_nouvelles'].most_common():
            print('    NOUVELLE : « %s » ×%d' % (t, n))
    else:
        print('    aucune section inconnue')
    if r['exceptions']:
        print('\nEXCEPTIONS')
        for e in r['exceptions']:
            print('    · %s' % e)
    print('\nVERDICT : %s' % r['verdict'])


def main():
    if len(sys.argv) < 2:
        sys.stderr.write('usage : python crg_compatibilite.py <chemin.pdf> [--json]\n')
        return 2
    r = analyser(sys.argv[1])
    if '--json' in sys.argv:
        sys.stdout.write(json.dumps(r, ensure_ascii=False, default=dict))
    else:
        rendre(r)
    return 0 if r['verdict'].startswith('COMPATIBLE') else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
