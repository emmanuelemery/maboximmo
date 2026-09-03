# -*- coding: utf-8 -*-
"""
COUVERTURE DOCUMENTAIRE AMONT — ce que la page ANNONCE doit finir quelque part.

⚠️ CE CONTRÔLE EXISTE PARCE QUE LES AUTRES NE PEUVENT PAS VOIR CE QU'IL VOIT. Tous les
   contrôles de couverture du module comparent des POPULATIONS déjà créées : attendus,
   examinés, exclus, inexpliqués. Ils sont aveugles à la perte qui se produit AVANT — quand
   une ligne que le document imprime n'est jamais transformée en objet. Cette ligne-là ne
   manque à personne : aucun compteur ne bouge, aucun arbitrage ne s'ouvre, et le KPI
   « pertes silencieuses » reste à zéro pendant qu'on perd du patrimoine.

⚠️ MESURÉ LE 03/09/2026, AVANT CORRECTION : 23 immeubles ANNONCÉS par un seul document, et
   jamais lus — parce que leur ville portait un numéro d'arrondissement. Le dépôt entier a
   été intégré, contrôlé et validé sans que rien ne le signale.

⚠️ LE SIGNAL EST VOLONTAIREMENT PLUS LARGE QUE LA RÈGLE. `RE_SIGNAL_IMMEUBLE` reconnaît qu'une
   ligne ANNONCE un immeuble ; `RE_IMMEUBLE` décide si le moteur sait le LIRE. Confondre les
   deux rendrait ce contrôle tautologique — il serait toujours vert, par construction.

⚠️ ET IL TOURNE DANS LES DEUX MODES. Une couverture vraie dans un mode et fausse dans l'autre
   est une couverture fausse : c'est exactement la façon dont le défaut s'est caché.

Usage : python crgi_couverture_amont.py
"""
import os
import subprocess
import sys

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RACINE)

import crg_integration_phase0 as P0                        # noqa: E402
from crg_integration_lots import couverture_immeubles      # noqa: E402

from crg_quarantaine import est_en_quarantaine   # noqa: E402

# Les corpus de contrôle — jamais le HOLDOUT, qui doit rester non lu jusqu'à son examen.
CORPUS = [
    ('VIENNE',    r'D:\CRG REGIE EMERY VIENNE\CRG AVRIL A JUILLET 2026.pdf'),
    ('CHAPONOST', r'D:\CRG CHAPONOST\CRG AVRIL A JUILLET 2026 (2).pdf'),
]
MODES = ('-layout', '-table')


def pages(exe, mode, chemin):
    r = subprocess.run([exe, mode, '-enc', 'UTF-8', chemin, '-'],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        raise RuntimeError('%s : %s' % (mode, r.stderr.decode('utf-8', 'replace')[:150]))
    p = r.stdout.decode('utf-8', 'replace').split('\x0c')
    if p and not p[-1].strip():
        p.pop()
    return p


def principal():
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    exe, etiquette, sait_table = P0.lecteur_resolu()
    print('COUVERTURE DOCUMENTAIRE AMONT — lecteur %s' % etiquette)
    ok = ko = 0
    echecs = []
    for nom, chemin in CORPUS:
        if est_en_quarantaine(chemin):
            continue
        if not os.path.exists(chemin):
            print('  ABSENT %s — %s' % (nom, chemin))
            continue
        for mode in MODES:
            if mode == '-table' and not sait_table:
                print('  IGNORÉ %-10s %-8s le lecteur ne propose pas ce mode' % (nom, mode))
                continue
            signaux = objets = muets = 0
            exemples = []
            for texte in pages(exe, mode, chemin):
                s, o, m = couverture_immeubles(texte)
                signaux += len(s)
                objets += len(o)
                muets += len(m)
                for x in m:
                    if len(exemples) < 3:
                        exemples.append(' '.join(x.split())[:100])
            # ⚠️ L'INVARIANT : SIGNAUX = OBJETS + NON TRANSFORMÉS, et le troisième terme = 0.
            #    Un signal non transformé n'est pas toujours un défaut — mais il doit toujours
            #    être VU. Tant qu'il n'est pas expliqué, il compte comme une perte.
            juste = (signaux == objets + muets) and muets == 0
            print('  %-6s %-10s %-8s signaux %4d = objets %4d + non transformés %3d'
                  % ('OK' if juste else 'ÉCHEC', nom, mode, signaux, objets, muets))
            if juste:
                ok += 1
            else:
                ko += 1
                echecs.append((nom, mode, muets, exemples))
    print('\nCOUVERTURE AMONT : %d/%d' % (ok, ok + ko))
    for nom, mode, muets, exemples in echecs:
        print('\n  ÉCHEC — %s %s : %d ligne(s) annoncée(s) et jamais transformée(s)'
              % (nom, mode, muets))
        for x in exemples:
            print('    · %s' % x)
    return 0 if ko == 0 else 1


if __name__ == '__main__':
    sys.exit(principal())
