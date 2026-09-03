# -*- coding: utf-8 -*-
"""
BASE DE COMPARAISON DE LECTURE — ce que les primitives lisent, avant et après un chantier.

⚠️ CE FICHIER EXISTE PARCE QU'ON NE PEUT PAS CLASSER UNE DIFFÉRENCE QU'ON N'A PAS MESURÉE
   AVANT. « Tout est vert » ne dit pas si une correction a récupéré une information ou si
   elle en a perdu une autre au passage. On fige donc l'état, on corrige, on rejoue, et
   CHAQUE écart doit être nommé : `CORRECTION DE LECTURE` ou `RÉGRESSION`.

⚠️ IL MESURE LES PRIMITIVES, PAS LE PIPELINE. Les phases écrivent en base et dépendent de
   validations humaines ; les primitives, elles, sont des fonctions pures d'un texte. C'est
   au niveau des fonctions que se compare une lecture.

⚠️ LE HOLDOUT N'ENTRE JAMAIS ICI. Mesurer sur les documents réservés à l'examen les
   brûlerait avant l'heure.

Usage : python lecture_baseline.py <sortie.json> [--extracteur poppler|xpdf] [--mode -layout|-table]
"""
import json
import os
import subprocess
import sys

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RACINE)

import crg_integration_phase0 as P0                     # noqa: E402
from crg_format import famille_du_texte                 # noqa: E402
from crg_integration_lots import (immeubles_de,         # noqa: E402
                                  locataires_de, segments_de_lot)

HOLDOUT = 'CRG HOLDOUT'

# (nom, chemin, nb de fichiers max, plage de pages ou None)
CORPUS = [
    ('LYON',      r'D:\CRG REGIE EMERY LYON',                                14, None),
    ('EMERY',     r'D:\CRG EMERY IMMO',                                      14, None),
    # ⚠️ LE DOCUMENT ENTIER, PAS UN EXTRAIT CHOISI. Restreindre la plage aux pages où l'on
    #    sait qu'un défaut se manifeste reviendrait à sélectionner l'échantillon sur la
    #    réponse — et la base ne mesurerait plus que ce qu'on cherchait déjà.
    ('VIENNE',    r'D:\CRG REGIE EMERY VIENNE\CRG AVRIL A JUILLET 2026.pdf',  1, None),
    ('CHAPONOST', r'D:\CRG CHAPONOST\CRG AVRIL A JUILLET 2026 (2).pdf',       1, None),
]


def pages_de(exe, mode, chemin, plage):
    cmd = [exe, mode, '-enc', 'UTF-8']
    if plage:
        cmd += ['-f', str(plage[0]), '-l', str(plage[1])]
    r = subprocess.run(cmd + [chemin, '-'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        raise RuntimeError('%s a échoué : %s' % (mode, r.stderr.decode('utf-8', 'replace')[:120]))
    pages = r.stdout.decode('utf-8', 'replace').split('\x0c')
    if pages and not pages[-1].strip():
        pages.pop()
    return pages


def fichiers_de(chemin, maxi):
    if os.path.isfile(chemin):
        return [chemin]
    tout = sorted(os.path.join(chemin, x) for x in os.listdir(chemin)
                  if x.lower().endswith('.pdf') and HOLDOUT not in x)
    return tout[:maxi]


def mesurer(exe, mode, nom, chemin, maxi, plage):
    """Ce que les primitives lisent — objets et identités, jamais des totaux d'ambiance."""
    immeubles, villes, lots, occupants, proprios, familles = set(), set(), set(), set(), set(), set()
    pages_lues = 0
    for f in fichiers_de(chemin, maxi):
        for i, texte in enumerate(pages_de(exe, mode, f, plage), 1):
            pages_lues += 1
            fam = famille_du_texte(texte)
            if fam != 'inconnu':
                familles.add(fam)
            for imm in immeubles_de(texte, i):
                immeubles.add((imm['nom'], imm['code_postal']))
                villes.add(imm['ville'])
            for seg in segments_de_lot(texte, i):
                lots.add(seg['reference'])
                # `segments_de_lot` rend déjà l'occupant démontré ; `locataires_de` travaille
                # sur le texte brut d'un segment, pas sur le segment lui-même.
                if seg.get('locataire'):
                    occupants.add(seg['locataire'])
            # ⚠️ LE PROPRIÉTAIRE NE SE LIT QUE SUR UNE PAGE QUI OUVRE UN CRG. Ma première
            #    version le lisait sur TOUTES les pages, suites comprises : la mesure faisait
            #    remonter des fragments de tableau (« 00030- ») comme noms de propriétaires,
            #    et j'ai passé un temps certain à corriger le moteur pour un défaut de
            #    l'instrument. Un outil de mesure faux coûte plus cher qu'une absence d'outil.
            # ⚠️ ET « DEBUT » N'EST PAS LE SEUL ÉTAT QUI OUVRE UN CRG. ICS ouvre sur
            #    `ENTETE_REGIE` — n'accepter que `DEBUT` a fait tomber deux corpus entiers à
            #    zéro propriétaire, cette fois par excès de sévérité de l'instrument. Un outil
            #    de mesure se vérifie sur les deux bords : ni trop bavard, ni trop strict.
            etat, _quoi, _pourquoi = P0.qualifier(texte)
            if etat in ('DEBUT', 'ENTETE_REGIE'):
                d = P0.identifier(texte, fam if fam != 'inconnu' else 'lyon')
                if d.get('proprietaire'):
                    proprios.add(d['proprietaire'])
    return {
        'pages': pages_lues,
        'familles': sorted(familles),
        'immeubles': sorted('%s|%s' % x for x in immeubles),
        'villes': sorted(villes),
        'lots': sorted(lots),
        'occupants': sorted(occupants),
        'proprietaires': sorted(proprios),
    }


def principal():
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    if len(sys.argv) < 2:
        sys.stderr.write(__doc__)
        return 2
    sortie = sys.argv[1]
    produit = 'xpdf'
    mode = '-layout'
    for i, a in enumerate(sys.argv):
        if a == '--extracteur' and i + 1 < len(sys.argv):
            produit = sys.argv[i + 1]
        if a == '--mode' and i + 1 < len(sys.argv):
            mode = sys.argv[i + 1]
    os.environ['CRG_LECTEUR'] = produit
    exe, etiquette, _t = P0.lecteur_resolu()
    print('lecteur : %s (%s) %s' % (etiquette, exe, mode))

    tout = {'_lecteur': etiquette, '_mode': mode}
    for nom, chemin, maxi, plage in CORPUS:
        if not os.path.exists(chemin):
            print('  %-10s ABSENT — %s' % (nom, chemin))
            continue
        tout[nom] = mesurer(exe, mode, nom, chemin, maxi, plage)
        m = tout[nom]
        print('  %-10s %4d pages · %3d immeubles · %3d lots · %3d occupants · %3d propriétaires'
              % (nom, m['pages'], len(m['immeubles']), len(m['lots']),
                 len(m['occupants']), len(m['proprietaires'])))
    # ⚠️ `ensure_ascii=False` : une base de comparaison écrite en séquences d'échappement
    #    rendrait « ANDRÉ » illisible, et un écart d'accent invisible.
    with open(sortie, 'w', encoding='utf-8') as fh:
        json.dump(tout, fh, ensure_ascii=False, indent=1, sort_keys=True)
    print('→ %s' % sortie)
    return 0


if __name__ == '__main__':
    sys.exit(principal())
