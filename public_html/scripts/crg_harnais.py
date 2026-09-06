# -*- coding: utf-8 -*-
"""
LE HARNAIS COMPLET DU RÉFÉRENTIEL CRG.

Cinq suites, et aucune ne remplace les autres :

    CERTIFICATION MÉTIER   ce que les CRG démontrent — P1 à P9, sur le banc certifié.
    ROBUSTESSE LECTEUR     un incident, une fixture, un test. Moitié de refus.
    ÉPREUVE DES TESTS      on réintroduit les bugs et on exige que les tests virent au rouge.
    REPLAY / IDEMPOTENCE   relecture identique = même résultat, même empreinte.
    COUVERTURE / COHÉRENCE ATTENDUE = EXAMINÉE + EXCLUE, et les frontières entre phases.
    RAPPROCHEMENT MBI      les règles d'appariement, et surtout ce qu'elles refusent.
    ÉCRAN ET ARBITRAGE     la page se rend, son JS se charge, et on peut répondre.
    MULTI-CORPUS           une règle d'un éditeur ne mord pas sur un autre.
    ANNULATION             un import annulé ne laisse RIEN — le PDF redevient inconnu.

⚠️ LE VERT NE SE NÉGOCIE PAS EN ABAISSANT LES EXIGENCES. Si une suite passe au rouge, c'est le
   défaut qu'on corrige, pas le test. Et un harnais entièrement vert ne prouve rien tant que
   l'ÉPREUVE DES TESTS n'a pas montré que chacun sait détecter son bug.

⚠️ AUCUNE SUITE N'ÉCRIT DANS LES DONNÉES MÉTIER. `REPLAY` rejoue les moteurs sur le staging
   `crgi_*` et restaure ce qu'il a modifié ; les autres ne font que lire.

Usage : python crg_harnais.py [--rapide]   (`--rapide` saute REPLAY, qui rejoue les moteurs)
"""
import os
import re
import subprocess
import sys
import time

RACINE = os.path.dirname(os.path.abspath(__file__))
PHP = os.environ.get('CRG_PHP', r'C:\xampp\php\php.exe')
PYTHON = sys.executable

SUITES = [
    ('CERTIFICATION MÉTIER', [PYTHON, os.path.join(RACINE, 'crg_non_regression.py')],
     r'NON-REGRESSION\s*:\s*toutes'),
    ('ROBUSTESSE LECTEUR', [PYTHON, os.path.join(RACINE, 'tests_crg', 'crgi_robustesse.py')],
     r'ROBUSTESSE\s*:\s*(\d+)/(\d+)'),
    ('ÉPREUVE DES TESTS',
     [PYTHON, os.path.join(RACINE, 'tests_crg', 'crgi_epreuve_des_tests.py')],
     r'ÉPREUVE DES TESTS\s*:\s*(\d+)/(\d+)'),
    ('COUVERTURE / COHÉRENCE',
     [PHP, '-d', 'max_execution_time=0', os.path.join(RACINE, 'tests_crg', 'crgi_coherence.php')],
     r'COHÉRENCE\s*:\s*(\d+)/(\d+)'),
    ('ÉCRAN ET ARBITRAGE',
     [PHP, '-d', 'max_execution_time=0', os.path.join(RACINE, 'tests_crg', 'crgi_ecran.php')],
     r'ÉCRAN\s*:\s*(\d+)/(\d+)'),
    ('FILE D\'ARBITRAGE',
     [PHP, '-d', 'max_execution_time=0',
      os.path.join(RACINE, 'tests_crg', 'crgi_file_arbitrage.php')],
     r'FILE ARBITRAGE\s*:\s*(\d+)/(\d+)'),
    ('RAPPROCHEMENT MBI',
     [PHP, '-d', 'max_execution_time=0',
      os.path.join(RACINE, 'tests_crg', 'crgi_rapprochement.php')],
     r'RAPPROCHEMENT\s*:\s*(\d+)/(\d+)'),
    ('COUVERTURE AMONT',
     [PYTHON, os.path.join(RACINE, 'tests_crg', 'crgi_couverture_amont.py')],
     r'COUVERTURE AMONT\s*:\s*(\d+)/(\d+)'),
    ('MULTI-CORPUS', [PYTHON, os.path.join(RACINE, 'tests_crg', 'crgi_multicorpus.py')],
     r'MULTI-CORPUS\s*:\s*(\d+)/(\d+)'),
    ('DÉTECTEURS QUALITÉ MBI',
     [PHP, '-d', 'max_execution_time=0',
      os.path.join(RACINE, 'tests_crg', 'crgq_detecteurs.php')],
     r'DÉTECTEURS QUALITÉ\s*:\s*(\d+)/(\d+)'),
    ('NOUVEAUTÉ ≠ EXCLUSION',
     [PHP, '-d', 'max_execution_time=0',
      os.path.join(RACINE, 'tests_crg', 'crgi_nouveaute_pas_exclusion.php')],
     r'NOUVEAUTÉ ≠ EXCLUSION\s*:\s*(\d+)/(\d+)'),
    ('ANNULATION / RÉVERSIBILITÉ',
     [PHP, '-d', 'max_execution_time=0',
      os.path.join(RACINE, 'tests_crg', 'crgi_annulation.php')],
     r'ANNULATION\s*:\s*(\d+)/(\d+)'),
    ('REPLAY / IDEMPOTENCE',
     [PHP, '-d', 'max_execution_time=0', os.path.join(RACINE, 'tests_crg', 'crgi_replay.php')],
     r'REPLAY\s*:\s*(\d+)/(\d+)'),
]


def lancer(nom, commande, motif):
    t = time.time()
    p = subprocess.run(commande, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    sortie = p.stdout.decode('utf-8', 'replace')
    m = re.search(motif, sortie)
    if not m:
        # ⚠️ UNE SUITE MUETTE N'EST PAS UNE SUITE VERTE. Si la ligne de résultat manque, la
        #    suite a échoué avant d'arriver au bout : on ne l'interprète pas favorablement.
        return nom, None, None, p.returncode, time.time() - t, sortie[-900:]
    if m.groups():
        return nom, int(m.group(1)), int(m.group(2)), p.returncode, time.time() - t, sortie
    # ⚠️ LE BANC MÉTIER NE COMPTE PAS SES TESTS DANS SA LIGNE FINALE. Rendre « 1/1 » ferait
    #    passer dix-neuf contrôles pour un seul : on compte ses en-têtes de section.
    n = len(re.findall(r'(?m)^### ', sortie)) or 1
    return nom, n, n, p.returncode, time.time() - t, sortie


def main():
    rapide = '--rapide' in sys.argv
    resultats = []
    for nom, commande, motif in SUITES:
        if rapide and nom.startswith('REPLAY'):
            print('  … %-24s SAUTÉE (--rapide)' % nom)
            continue
        nom, ok, total, code, duree, sortie = lancer(nom, commande, motif)
        resultats.append((nom, ok, total, code, duree, sortie))
        if ok is None:
            print('  ✗ %-24s MUETTE — aucune ligne de résultat (code %s, %.0f s)'
                  % (nom, code, duree))
        else:
            print('  %s %-24s %d/%d  (%.0f s)'
                  % ('✓' if ok == total and code == 0 else '✗', nom, ok, total, duree))

    print('\n' + '=' * 78)
    dur = 0
    for nom, ok, total, code, duree, _s in resultats:
        dur += duree
        print('%-26s %s' % (nom, 'MUETTE' if ok is None else '%d/%d' % (ok, total)))
    print('=' * 78)
    rouges = [r for r in resultats if r[1] is None or r[1] != r[2] or r[3] != 0]
    if rouges:
        print('\nSUITES EN ÉCHEC : %d' % len(rouges))
        for nom, ok, total, code, _d, sortie in rouges:
            print('\n──── %s ────' % nom)
            for ligne in sortie.splitlines():
                if 'ÉCHEC' in ligne or 'MUET' in ligne or 'incident défendu' in ligne:
                    print('  ' + ligne.strip()[:150])
        return 1
    print('\nTOUT EST VERT — %.0f s' % dur)
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
