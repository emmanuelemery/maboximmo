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

⚠️ « JE N'AI RIEN LU » N'EST PAS « JE NE SAIS PAS TRANCHER ». Le séparateur décimal de
   l'éditeur ICS est le POINT — `35052.60` — quand celui de SPI est la virgule. Une règle
   écrite sur le premier éditeur rencontré rendait « trop peu de montants lus (0 et 0) »
   sur TOUS les documents de l'autre : quatre collisions classées indéterminables alors
   qu'aucun montant n'avait été regardé. Un cas C doit donc dire LEQUEL des deux il est,
   sinon un défaut de lecture se déguise en prudence métier.

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

GROUPEUR = '  \xa0'
# ⚠️ DEUX ÉCRITURES DU MÊME MONTANT, ET AUCUNE N’EST « LA BONNE ». SPI imprime `1 234,56`,
#    ICS imprime `1234.56`. On reconnaît les deux — la première branche pour la forme groupée,
#    la seconde pour la forme continue — et on refuse ce qui touche un autre chiffre, sinon la
#    date `27.01.26` fournirait le faux montant `01.26`.
RE_MONTANT = re.compile(
    r'(?<![\d.,])-?\d{1,3}(?:[' + GROUPEUR + r']\d{3})+[.,]\d{2}(?!\d|[.,]\d)'
    r'|(?<![\d.,])-?\d+[.,]\d{2}(?!\d|[.,]\d)'
)
# ⚠️ L'OCR CONFOND QUELQUES CARACTÈRES, ET TOUJOURS LES MÊMES. « 0 » et « O », « 1 » et « l ».
#    On ne « corrige » rien : on compare des montants, où seuls les chiffres comptent.
NETTOYER = str.maketrans({c: '' for c in GROUPEUR})
# En dessous, il n'y a pas de quoi affirmer que deux extraits décrivent la même situation.
SEUIL_MATIERE = 5
# Un extrait plus long que cela porte forcément des sommes : n'en lire AUCUNE est un défaut de
# lecture, jamais une propriété du document.
SEUIL_TEXTE_PORTEUR = 200


def montants(textes):
    """Le multiensemble des sommes imprimées — ce qui fait la substance d'un CRG."""
    sac = collections.Counter()
    for t in textes:
        for m in RE_MONTANT.findall(t):
            # La clé est canonique : `1 234,56` et `1234.56` désignent la même somme, et deux
            # éditeurs ne doivent pas produire deux clés pour un seul montant.
            sac[m.translate(NETTOYER).replace(',', '.')] += 1
    return sac


def defaut_de_lecture(textes, cote):
    """Ce qui manque est-il dans le document, ou dans notre façon de le lire ?"""
    caracteres = sum(len(t) for t in textes)
    if not textes:
        return ('LECTURE : aucune page pour la %s — les pages demandées sont hors du document'
                % cote)
    if caracteres >= SEUIL_TEXTE_PORTEUR and not montants(textes):
        return ('LECTURE : %d caractères lus pour la %s, aucun montant reconnu — format de '
                'montant non couvert' % (caracteres, cote))
    return None


def qualifier_paire(textes_a, textes_b):
    # ⚠️ ON DIT D'ABORD SI ON A LU. Un « je ne sais pas trancher » rendu sur un texte qu'on
    #    n'a pas su lire fait passer un défaut de moteur pour une prudence métier.
    manque = (defaut_de_lecture(textes_a, 'première occurrence')
              or defaut_de_lecture(textes_b, 'seconde occurrence'))
    if manque:
        return {'verdict': 'C', 'motif': manque + ' : la comparaison n’a pas eu lieu.',
                'communs': 0, 'seuls_a': 0, 'seuls_b': 0}
    a, b = montants(textes_a), montants(textes_b)
    total = sum(a.values()) + sum(b.values())
    # ⚠️ SANS MATIÈRE, PAS DE VERDICT. Deux extraits de quelques montants ne permettent pas
    #    d'affirmer qu'ils décrivent la même situation : c'est un cas C, pas un cas A.
    if sum(a.values()) < SEUIL_MATIERE or sum(b.values()) < SEUIL_MATIERE:
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
    """Deux appels possibles, UN SEUL calcul.

    ⚠️ UNE PAIRE PAR PROCESSUS RELISAIT TOUT LE DOCUMENT. `lire_pages()` extrait les 906 pages
       du PDF pour n'en découper que deux extraits de quelques pages — et la phase 0 lançait
       ce processus DIX-HUIT fois : 103 s des 118 s de la phase, pour 6 s de lecture utile.
       Le mode par lot ouvre le document UNE fois et qualifie toutes les paires. Le verdict
       ne change pas d'un caractère : `qualifier_paire` n'est pas touchée.

    ⚠️ LES DEUX OCCURRENCES NE SONT PAS TOUJOURS DANS LE MÊME PDF. Sur LYON, le même compte
       rendu est classé dans deux dossiers — `GPE IMMO DR` et `GPE SIR` : une seule lecture
       découpait alors les pages de la SECONDE occurrence dans le document de la PREMIÈRE,
       et comparait un extrait avec lui-même. Chaque paire dit donc de quel document vient
       chaque côté ; le cache garde la promesse d'UNE lecture par document.

    Usage : crg_integration_doublons.py <pdf> <paires.json>          (lot)
            crg_integration_doublons.py <pdf> <p1d> <p1f> <p2d> <p2f> (une paire, historique)
    """
    if len(sys.argv) == 3:
        with open(sys.argv[2], encoding='utf-8') as fh:
            paires = json.load(fh)
        lu = {}

        def pages_de(chemin):
            if chemin not in lu:
                lu[chemin] = lire_pages(chemin)[0]
            return lu[chemin]

        defaut = sys.argv[1]
        sortie = []
        for p in paires:
            ta = pages_de(p.get('a_pdf') or defaut)
            tb = pages_de(p.get('b_pdf') or defaut)
            r = qualifier_paire(ta[int(p['ad']) - 1:int(p['af'])],
                                tb[int(p['bd']) - 1:int(p['bf'])])
            r['id'] = p['id']
            sortie.append(r)
        sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
        return 0

    if len(sys.argv) < 6:
        sys.stderr.write('usage : crg_integration_doublons.py <pdf> <paires.json>\n'
                         '        crg_integration_doublons.py <pdf> <p1d> <p1f> <p2d> <p2f>\n')
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
