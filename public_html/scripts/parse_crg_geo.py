#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
parse_crg_geo.py — LE CRG DE **LOCA IMMO LYON**, variante `lyon` du moteur ICS.

    parser_version = geometric-1.0     (inchangé — 517 extractions le portent en base)

⚠️ CE FICHIER N'EST PLUS UN LECTEUR : C'EST UNE ADRESSE. La lecture vit dans
   `crg_ics_core.py`, moteur du gabarit **ICS**, partagé avec EMERY IMMO. Les deux lecteurs
   étaient auparavant deux fichiers de mille lignes portant EXACTEMENT les mêmes douze
   fonctions, séparés par 96 lignes : 95 % de code forké. Une correction du fonctionnement
   commun devait être faite deux fois — ou l'était une seule, et ils divergeaient un peu plus.

⚠️ LE NOM ET LE POINT D'ENTRÉE NE CHANGENT PAS. `crg_depot_lire.PARSEURS`, `import_crg_batch`
   et la doctrine P1 nomment ce fichier et sa fonction `parse(chemin)` : les certifications
   historiques LYON restent traçables jusqu'ici. Ce qui a changé est où le code habite,
   jamais ce qu'il lit — démontré fichier par fichier sur le corpus LYON complet.

⚠️ CE QUI RESTE PROPRE À LYON est déclaré dans `crg_ics_core.VARIANTES['lyon']`, avec
   l'incident qui l'a fait naître : la ligne de montants nue y est un dégât du filigrane
   « Powered by SCI » (LYON imprime, lui, une vraie ligne « Totaux »), et ses totaux mensuels
   sont IMPRIMÉS — les recomposer l'avait fait passer de 0 à 326 écarts.

Usage :  python parse_crg_geo.py <fichier.pdf>   → JSON sur la sortie standard
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from crg_ics_core import parse as _parse_ics   # noqa: E402

VARIANTE = 'lyon'


def parse(chemin):
    """Le CRG LYON, lu par le moteur ICS dans sa variante `lyon`."""
    return _parse_ics(chemin, VARIANTE)


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'erreur': 'usage : parse_crg_geo.py <fichier.pdf>'}))
        sys.exit(1)
    # ⚠️ ensure_ascii=True, DÉLIBÉRÉMENT — la même leçon que le lecteur SEPTEO a apprise avant
    #    nous. Sous Windows la sortie standard d'un processus fils est en cp1252 : « ANDRÉ »
    #    y partait en octet 0xC9 et revenait en U+FFFD chez l'appelant. 35 noms de propriétaire
    #    sur 411 arrivaient ainsi mutilés — « MONSIEUR VANTALON ANDR<?> » — et le nom de fichier
    #    GED qui s'en déduit avec eux. En pur ASCII échappé, le JSON traverse n'importe quel
    #    encodage sans une perte.
    try:
        print(json.dumps(parse(sys.argv[1]), ensure_ascii=True))
    except Exception as e:
        print(json.dumps({'erreur': str(e)}, ensure_ascii=True))
        sys.exit(1)
