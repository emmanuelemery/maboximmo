# -*- coding: utf-8 -*-
"""
P7A · LA PASSE PDF SUR LE RÉSIDUEL INDÉTERMINABLE.

⚠️ UN LIBELLÉ NE SE LIT PAS SEUL. « DEPOSE MOQUETTE + POSE SOL » ne contient pas le mot
   « travaux », mais la SECTION qui le surplombe et les lignes qui l'entourent peuvent le
   démontrer. Ce module rouvre le PDF et rend, pour chaque ligne du résiduel, son contexte
   imprimé : section, rubrique réelle, lignes voisines, page.

⚠️ ET LA PASSE NE SERT PAS QU'À FAIRE ENTRER DES LIGNES DANS P7A. Elle doit aussi en SORTIR :
   « Déduction Virt SCP BUGEAUD s/vente » sent le flux de vente, donc P8, pas la dépense.

⚠️ ON NE CHERCHE PAS À RAMENER LE RÉSIDUEL À ZÉRO. Un résiduel documentaire réel, tracé ligne
   à ligne, est un résultat honnête.

Lent : rouvre les PDF portant une ligne du résiduel. Sortie → C:\\tmp\\p7a_residuel.json
"""
import sys
import os
import io
import re
import json
import glob
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import perimetre, entete, ERREURS_PDF   # noqa: E402
import p7_socle as S   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
ZIPS = glob.glob(r'D:\CRG REGIE EMERY VIENNE\*.zip')
SORTIE = r'C:\tmp\p7a_residuel.json'

# les intitulés de section du format `lyon` / `emery_immo`, imprimés entre tirets
RE_SECTION = re.compile(r'^\s*[-–]\s*(.{3,60}?)\s*[-–]\s*$')


def contexte(lignes, i, n=5):
    return [l.strip()[:110] for l in lignes[max(0, i - n):i + n + 1]]


def section_avant(lignes, i):
    """La dernière section imprimée au-dessus de la ligne — « - Dépenses de Syndic - »."""
    for k in range(i, -1, -1):
        m = RE_SECTION.match(lignes[k])
        if m:
            return m.group(1)
    return None


if __name__ == '__main__':
    residuel = [x for ag in perimetre() for x in S.lignes(ag) if x['cote'] == 'a_qualifier']
    print('résiduel à instruire : %d lignes' % len(residuel))

    par_pdf = collections.defaultdict(list)
    for x in residuel:
        par_pdf[(x['agence'], x['crg_fichier'])].append(x)

    out, manques = [], 0
    for (ag, nom), lst in sorted(par_pdf.items()):
        f = glob.glob(os.path.join(RACINES.get(ag, ''), '**', nom), recursive=True) \
            if ag in RACINES else []
        if not f:
            manques += len(lst)
            for x in lst:
                out.append(dict(x, section=None, contexte=[], pdf_lu=False))
            continue
        try:
            with pdfplumber.open(f[0]) as pdf:
                pages = {n: (p.extract_text() or '').split('\n')
                         for n, p in enumerate(pdf.pages, 1)}
        except ERREURS_PDF:
            manques += len(lst)
            continue
        for x in lst:
            lignes = pages.get(x['page'], [])
            cible = (x['libelle_brut'] or x['entete_source'] or '')[:26]
            i = next((k for k, l in enumerate(lignes) if cible and cible in l), None)
            if i is None:
                # le bloc peut déborder sur la page voisine
                for p2 in (x['page'] - 1, x['page'] + 1):
                    lignes = pages.get(p2, [])
                    i = next((k for k, l in enumerate(lignes) if cible and cible in l), None)
                    if i is not None:
                        break
            out.append(dict(x, section=section_avant(lignes, i) if i is not None else None,
                            contexte=contexte(lignes, i) if i is not None else [],
                            pdf_lu=i is not None))
        print('   %-14s %-34s %d ligne(s)' % (ag, nom[:34], len(lst)), flush=True)

    io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(out, ensure_ascii=False, indent=1))
    entete('CONTEXTE RETROUVÉ')
    print('  lignes du résiduel      %d' % len(out))
    print('  contexte PDF retrouvé   %d' % sum(1 for x in out if x['pdf_lu']))
    print('  PDF introuvable         %d' % manques)
    sec = collections.Counter(x['section'] for x in out if x['section'])
    print('\n  SECTIONS QUI SURPLOMBENT CES LIGNES :')
    for k, v in sec.most_common(12):
        print('   %5d  %s' % (v, k))
    print('\n  détail → %s' % SORTIE)
