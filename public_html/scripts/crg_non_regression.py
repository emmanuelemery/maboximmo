# -*- coding: utf-8 -*-
"""
NON-RÉGRESSION CUMULATIVE DU LECTEUR CRG — une commande, toutes les phases certifiées.

    python crg_non_regression.py                → toutes les phases, tous les périmètres
    python crg_non_regression.py "VIENNE"       → toutes les phases, un périmètre

⚠️ LES PHASES S'EMPILENT, ELLES NE SE REMPLACENT PAS. Une modification du lecteur VIENNE ne
   doit pas pouvoir casser en silence une règle GED ou propriétaire validée des mois plus tôt.
   Chaque phase certifiée ajoute ses contrôles ici, et **aucune n'en retire**.

⚠️ UNE PHASE VERTE NE DOIT JAMAIS RENDRE ROUGE UNE PHASE ANTÉRIEURE. Si c'est le cas, ce n'est
   pas au test de céder : il faut signaler « RÉGRESSION / REMISE EN CAUSE PHASE X » avec la
   preuve PDF, et attendre l'arbitrage.

La doctrine que ces tests prouvent vit dans `docs/reference_crg_certification.md`. Le code
applique la règle, le référentiel l'explique, les tests la prouvent — les trois doivent rester
cohérents, et toute divergence est un défaut à signaler.
"""
import os
import subprocess
import sys

# ⚠️ LA CONSOLE WINDOWS EST EN cp1252 : un simple « 🟢 » y fait exploser l'affichage.
#    C'est le pendant, cote sortie, du piege `ensure_ascii` de P1-TEC-01.
sys.stdout.reconfigure(encoding='utf-8')

ICI = os.path.dirname(os.path.abspath(__file__))

# ⚠️ TROIS NATURES DE CONTROLE, ET ON NE LES CONFOND PAS. Presenter un test technique comme
#    une phase certifiee ferait croire que les objets qu'il manipule le sont — or le moteur
#    cree deja des locataires, dont aucune regle n'est doctrine a ce stade.
PHASES_CERTIFIEES = [
    ('PHASE 1 — GED / SOURCE / PERIODE / PAGE', 'p1_certification.py'),
    ('PHASE 2 — PROPRIETAIRES', 'p2_certification.py'),
    ('P3A COMPTES · P3B IMMEUBLES · P3C LOTS — STRUCTURE', 'p3_certification.py'),
    ('P4A — LOCATAIRES', 'p4a_certification.py'),
    ('P4B — OCCUPATION', 'p4b_certification.py'),
    ('P5A — APPELS', 'p5a_certification.py'),
    ('P5B — ENCAISSEMENTS', 'p5b_certification.py'),
    ('P6A — CRÉANCES / ENCOURS', 'p6a_certification.py'),
    ('P6B — ANTÉRIORITÉ / REPORTS', 'p6b_certification.py'),
    # ⚠️ LA GRAMMAIRE DES SECTIONS EST DANS LE HARNAIS, PAS SA RÉGÉNÉRATION. `p7_sections.py`
    #    rouvre les 458 PDF et met une vingtaine de minutes : un harnais qu'on ne peut pas
    #    lancer ne protège rien. Ce qui est rejoué à chaque fois, c'est que le détecteur
    #    reconnaît toujours les mêmes FORMES documentaires et que l'index porte toujours ce
    #    qu'il portait — comme `p4a_source_pdf.py` et `p5a_divers_libelles.py`, l'extraction
    #    reste une commande à la demande : `python p7_sections.py`.
    ('P7 — GRAMMAIRE DES SECTIONS', 'p7_sections_test.py'),
    ('P7A — DÉPENSES', 'p7a_certification.py'),
    ('P7B — FRAIS / ASSURANCES', 'p7b_certification.py'),
    ('P7 — RECTIFICATION : RÉÉDITIONS ET EXHAUSTIVITÉ', 'p7_rectification_test.py'),
    ('P8A — FLUX PROPRIÉTAIRE', 'p8a_certification.py'),
    ('P8B — COMPTABILITÉ / SOLDE', 'p8b_certification.py'),
    # ⚠️ RECTIFICATION CERTIFIÉE, PAS UNE PHASE — validée par Emmanuel le 01/09/2026.
    #    Elle protège les trois lecteurs contre le retour du double comptage FOCH/SABY.
    ('P5A/P5B/P6A — RECTIFICATION FOCH / SABY', 'p5_rectification_test.py'),
    ('P9 — RENTABILITÉ / ANALYTIQUE', 'p9_certification.py'),
    ('P9 — RENTABILITÉ PAR NIVEAU', 'p9_rentabilite.py'),
]
# ⚠️ P1 → P9 CERTIFIÉES, sur validation explicite d'Emmanuel (P4 le 30/08/2026 ; P5, P6 et P7
#    le 31/08/2026 ; P8A le 31/08/2026 ; P8B, la rectification FOCH/SABY et P9 le 01/09/2026).
# ⚠️ UNE PHASE N'EST JAMAIS CERTIFIÉE PARCE QUE SON TEST PASSE. Elle l'est après la phrase
#    « Emmanuel valide la Phase X » — le vert du harnais ne la remplace jamais. Le bloc en
#    cours est vide : rien n'est ouvert.
BLOC_EN_COURS = []
TESTS_TECHNIQUES = [
    ("MOTEUR D'INTEGRATION — DEPUIS ZERO", 'p4_integration_depuis_zero.py'),
]

PHASES = [(t, os.path.join(ICI, 'tests_crg', f))
          for groupe in (PHASES_CERTIFIEES, BLOC_EN_COURS, TESTS_TECHNIQUES)
          for t, f in groupe]
ETIQUETTE = {t: e for groupe, e in ((PHASES_CERTIFIEES, '🟢 CERTIFIEE'),
                                    (BLOC_EN_COURS, '🟠 EN COURS — non certifiee'),
                                    (TESTS_TECHNIQUES, '⚙ TEST TECHNIQUE — hors certification'))
             for t, _ in groupe}


def main():
    perim = ' '.join(sys.argv[1:]).strip()
    echecs = []
    for titre, script in PHASES:
        if not os.path.exists(script):
            print('\n### %s : test absent (%s)' % (titre, os.path.basename(script)))
            echecs.append(titre)
            continue
        # ⚠️ VIDER LE TAMPON AVANT D'APPELER LE FILS. Sinon l'en-tête du parent sort APRÈS la
        #    sortie du test, et l'on attribue les résultats d'une phase à la suivante.
        print('\n' + '#' * 96)
        print('### %s   [%s]' % (titre, ETIQUETTE.get(titre, '')))
        print('#' * 96, flush=True)
        cmd = [sys.executable, script] + ([perim] if perim else [])
        r = subprocess.run(cmd)
        if r.returncode != 0:
            echecs.append(titre)

    print('\n' + '=' * 96)
    if echecs:
        print('NON-REGRESSION : ECHEC sur %d phase(s) — %s' % (len(echecs), ', '.join(echecs)))
        print('Ne pas conclure « tests verts ». Un test rouge sur une phase certifiee est une')
        print('REGRESSION : signaler la phase, la preuve PDF et l\'impact, puis attendre l\'arbitrage.')
    else:
        print('NON-REGRESSION : toutes les phases certifiees passent.')
    print('=' * 96)
    return 1 if echecs else 0


if __name__ == '__main__':
    sys.exit(main())
