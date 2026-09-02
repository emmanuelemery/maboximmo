#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
parse_crg_emery.py — LE CRG D'**EMERY IMMOBILIER succ. SERVAJEAN**, variante `emery_immo`
du moteur ICS (RIOM, et CHAMALIÈRES dont la gestion est inactive).

    parser_version = emery-1.0     (inchangé — les extractions certifiées le portent en base)

⚠️ CE FICHIER N'EST PLUS UN LECTEUR : C'EST UNE ADRESSE. La lecture vit dans
   `crg_ics_core.py`, moteur du gabarit **ICS**, partagé avec LYON. Une agence n'est pas un
   format : LYON et EMERY tournent sur le MÊME logiciel et impriment le même gabarit —
   « RECAPITULATIF DES OPERATIONS », « SITUATION DES LOCATAIRES », « COMPTE PERSONNEL »,
   « Immeuble : ». C'est VIENNE (éditeur SPI) qui n'en partage aucun.

⚠️ LE NOM ET LE POINT D'ENTRÉE NE CHANGENT PAS : `crg_depot_lire.PARSEURS` et la doctrine P1
   nomment ce fichier et sa fonction `parse(chemin)`. Les certifications historiques EMERY
   restent traçables jusqu'ici.

⚠️ CE QUI RESTE PROPRE À EMERY IMMO est déclaré dans `crg_ics_core.VARIANTES['emery_immo']`,
   avec l'incident qui l'a fait naître :
     · ce format n'imprime AUCUNE ligne « Totaux » par lot — la ligne de montants nue EST le
       total du lot. Chercher le mot « Totaux » laissait 160 lots sur 385 sans contrôle.
     · il n'imprime aucun total par ligne mensuelle : le total d'un mois est la somme de ses
       colonnes — 3 × (417,58 + 61,00) = 1 435,74, exactement l'agrégat du lot.

Usage :  python parse_crg_emery.py <fichier.pdf>   → JSON sur la sortie standard
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from crg_ics_core import parse as _parse_ics   # noqa: E402

VARIANTE = 'emery_immo'


def parse(chemin):
    """Le CRG EMERY IMMO, lu par le moteur ICS dans sa variante `emery_immo`."""
    return _parse_ics(chemin, VARIANTE)


if __name__ == '__main__':
    if len(sys.argv) < 2:
        # ⚠️ LE MESSAGE NOMMAIT « parse_crg_geo.py » — vestige de la copie qui a créé ce
        #    fichier, et preuve de plus que les deux lecteurs n'en faisaient qu'un.
        print(json.dumps({'erreur': 'usage : parse_crg_emery.py <fichier.pdf>'}))
        sys.exit(1)
    # ⚠️ ensure_ascii=True, DÉLIBÉRÉMENT — voir parse_crg_septeo.py, qui porte cette règle depuis
    #    plus longtemps. La sortie standard d'un processus fils est en cp1252 sous Windows : les
    #    accents des noms de propriétaire y étaient détruits et revenaient en U+FFFD, jusque dans
    #    le nom de fichier GED. En pur ASCII échappé, rien ne se perd en route.
    try:
        print(json.dumps(parse(sys.argv[1]), ensure_ascii=True))
    except Exception as e:
        print(json.dumps({'erreur': str(e)}, ensure_ascii=True))
        sys.exit(1)
