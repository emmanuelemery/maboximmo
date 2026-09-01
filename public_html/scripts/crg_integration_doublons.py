# -*- coding: utf-8 -*-
"""
QUALIFIER LES « MÊME CLÉ, CONTENU DIFFÉRENT » — A, B ou C.

⚠️ LA TAILLE DU TEXTE NE QUALIFIE RIEN. Deux occurrences OCRisées du même document ne
   produisent jamais le même nombre de caractères : l'OCR n'est pas déterministe. Se fier à
   l'écart de longueur classerait « complémentaire » un simple bruit de reconnaissance.

⚠️ ON COMPARE CE QUI FAIT LA SITUATION MÉTIER : LES MONTANTS. Un compte rendu de gestion est
   un ensemble de sommes ; si deux occurrences portent exactement les mêmes, elles décrivent la
   même situation, quelles que soient leurs coquilles. Si elles en portent de différentes, ce
   sont deux événements — et les fondre détruirait de l'information.

   A — même situation, différences documentaires sans incidence métier
   B — situation complémentaire : les montants diffèrent
   C — impossible à déterminer : trop peu de matière pour trancher

⚠️ `DOCUMENT DISTINCT ≠ SITUATION MÉTIER DISTINCTE ≠ ÉVÉNEMENT MÉTIER DISTINCT.`
   `LA RÉÉDITION EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER, PAS DU PDF.`

Usage : python crg_integration_doublons.py <chemin.pdf> <p1d> <p1f> <p2d> <p2f>
Sortie : un objet JSON — verdict, motif, montants comparés.
"""
import collections
import json
import re
import sys

sys.path.insert(0, __file__.rsplit('\\', 1)[0] if '\\' in __file__ else '.')
from crg_integration_phase0 import lire_pages   # noqa: E402

RE_MONTANT = re.compile(r'-?\d{1,3}(?:[   ]\d{3})*,\d{2}')
# ⚠️ L'OCR CONFOND QUELQUES CARACTÈRES, ET TOUJOURS LES MÊMES. « 0 » et « O », « 1 » et « l ».
#    On ne « corrige » rien : on compare des montants, où seuls les chiffres comptent.
NETTOYER = str.maketrans({' ': '', ' ': '', ' ': ''})


def montants(textes):
    """Le multiensemble des sommes imprimées — ce qui fait la substance d'un CRG."""
    sac = collections.Counter()
    for t in textes:
        for m in RE_MONTANT.findall(t):
            sac[m.translate(NETTOYER)] += 1
    return sac


def qualifier_paire(textes_a, textes_b):
    a, b = montants(textes_a), montants(textes_b)
    total = sum(a.values()) + sum(b.values())
    # ⚠️ SANS MATIÈRE, PAS DE VERDICT. Deux extraits de quelques montants ne permettent pas
    #    d'affirmer qu'ils décrivent la même situation : c'est un cas C, pas un cas A.
    if sum(a.values()) < 5 or sum(b.values()) < 5:
        return {'verdict': 'C', 'motif': 'Trop peu de montants lus (%d et %d) pour trancher.'
                % (sum(a.values()), sum(b.values())),
                'communs': 0, 'seuls_a': sum(a.values()), 'seuls_b': sum(b.values())}
    communs = sum((a & b).values())
    seuls_a = sum((a - b).values())
    seuls_b = sum((b - a).values())
    if seuls_a == 0 and seuls_b == 0:
        return {'verdict': 'A',
                'motif': 'Montants strictement identiques (%d), les écarts sont des coquilles '
                         'd’OCR sans incidence métier.' % communs,
                'communs': communs, 'seuls_a': 0, 'seuls_b': 0}
    # Un écart minime sur un grand ensemble reste une différence de LECTURE, pas de situation.
    ecart = (seuls_a + seuls_b) / max(1, total)
    if ecart <= 0.03:
        return {'verdict': 'A',
                'motif': 'Montants concordants à %.1f %% près (%d communs, %d et %d isolés) : '
                         'l’écart relève de la reconnaissance, pas du métier.'
                         % (100 * ecart, communs, seuls_a, seuls_b),
                'communs': communs, 'seuls_a': seuls_a, 'seuls_b': seuls_b}
    return {'verdict': 'B',
            'motif': 'Montants réellement différents : %d communs, %d propres à la première '
                     'occurrence, %d à la seconde (%.1f %% d’écart).'
                     % (communs, seuls_a, seuls_b, 100 * ecart),
            'communs': communs, 'seuls_a': seuls_a, 'seuls_b': seuls_b}


def main():
    if len(sys.argv) < 6:
        sys.stderr.write('usage : crg_integration_doublons.py <pdf> <p1d> <p1f> <p2d> <p2f>\n')
        return 2
    chemin = sys.argv[1]
    p1d, p1f, p2d, p2f = (int(x) for x in sys.argv[2:6])
    textes, _ = lire_pages(chemin)
    a = textes[p1d - 1:p1f]
    b = textes[p2d - 1:p2f]
    sys.stdout.write(json.dumps(qualifier_paire(a, b), ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
