# -*- coding: utf-8 -*-
"""
DÉDUPLICATION AU NIVEAU DE L'ÉVÉNEMENT MÉTIER — socle commun P5A / P5B / P6A.

⚠️ LA DOCTRINE, FIGÉE LE 01/09/2026
   **LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF. C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER
   QU'IL CONTIENT.** Un même document porte donc simultanément des événements réénoncés et des
   événements complémentaires : `FOCH 0108.pdf` réénonce 12 appels et en apporte 30 que rien
   d'autre ne porte. Éliminer le fichier entier perdrait les seconds ; le garder entier
   compterait les premiers deux fois.
   `DOCUMENT DISTINCT ≠ ÉVÉNEMENT MÉTIER DISTINCT`
   `MÊME PAIRE DE DOCUMENTS ≠ MÊME STATUT DE RÉÉDITION POUR TOUTES LES FAMILLES D'ÉVÉNEMENTS`

⚠️ ON NE COMPARE QUE CE QUI ÉNONCE LA MÊME CHOSE. Deux pièces ne sont confrontées que si elles
   couvrent la MÊME SITUATION DE GESTION — `agence · compte · date d'arrêté`, étendue à
   `immeuble` et `lot` chez `septeo_spi`, dont chaque pièce ne décrit qu'un immeuble. Sans
   cette extension, sept situations VIENNE seraient confrontées à tort.

⚠️ ET ON NE SOMME PAS LES MULTIPLICITÉS, ON PREND LA PLUS GRANDE. `P5A-APPEL-14` certifie que
   deux blocs peuvent porter le même lot et le même locataire DANS UN MÊME CRG : deux
   occurrences identiques au sein d'une pièce sont deux événements. La règle est donc : pour
   chaque événement, la situation en compte autant de fois que la pièce qui en énonce le plus.

⚠️ LA PIÈCE RETENUE EST LA PLUS COMPLÈTE, et le choix est déterministe : celle qui porte le
   plus d'occurrences pour la situation, départage par nom de fichier. Le contrôle
   `divergences()` lève si deux occurrences d'un même événement ne portent pas la même valeur —
   auquel cas le choix de la pièce déplacerait un total en silence.

Aucune provenance n'est perdue : les occurrences retenues gardent leur `crg_fichier`, `sha`,
`page` et leur position. La déduplication retire des DOUBLONS, jamais une trace documentaire.
"""
import collections

SEPTEO = 'septeo_spi'


def cle_situation(x):
    """La situation de gestion, telle que le référentiel la certifie."""
    base = (x.get('agence'), str(x.get('compte') or ''), x.get('date_arrete'))
    if x.get('format') == SEPTEO:
        # ⚠️ CHEZ SEPTEO, UNE PIÈCE = UN IMMEUBLE. Deux CRG du même compte au même arrêté y
        #    décrivent deux immeubles différents : ce ne sont pas deux énoncés d'une situation.
        return base + (str(x.get('immeuble') or ''), str(x.get('lot') or ''))
    return base


def dedupliquer(occurrences, cle_evenement):
    """Rend `(retenues, retirees)` — les doublons d'événement au sein d'une même situation.

    Une situation portée par une seule pièce n'est jamais touchée : le cas général du corpus.
    """
    par_situation = collections.defaultdict(lambda: collections.defaultdict(list))
    for x in occurrences:
        par_situation[cle_situation(x)][x.get('crg_fichier')].append(x)

    garde, retire = set(), []
    for pieces in par_situation.values():
        if len(pieces) < 2:
            for lst in pieces.values():
                garde.update(id(x) for x in lst)
            continue
        # la pièce la plus complète d'abord — déterministe, départage par nom
        ordre = sorted(pieces, key=lambda f: (-len(pieces[f]), str(f)))
        vus = collections.Counter()
        for f in ordre:
            compte = collections.Counter()
            for x in pieces[f]:
                k = cle_evenement(x)
                compte[k] += 1
                if compte[k] <= vus[k]:
                    retire.append(x)          # l'événement est déjà énoncé autant de fois
                else:
                    garde.add(id(x))
            for k, n in compte.items():
                vus[k] = max(vus[k], n)
    return [x for x in occurrences if id(x) in garde], retire


def divergences(occurrences, cle_evenement, valeur):
    """Deux occurrences d'un même événement doivent porter la même valeur. Sinon on lève."""
    par = collections.defaultdict(set)
    for x in occurrences:
        par[(cle_situation(x), cle_evenement(x))].add(round(float(valeur(x) or 0), 2))
    mauvais = {k: v for k, v in par.items() if len(v) > 1}
    if mauvais:
        k, v = next(iter(mauvais.items()))
        raise ValueError('ÉVÉNEMENT AUX VALEURS DIVERGENTES / CERTIFICATION IMPOSSIBLE : '
                         '%d cas, dont %s → %s' % (len(mauvais), k, sorted(v)))
    return len(par)
