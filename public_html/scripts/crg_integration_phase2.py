# -*- coding: utf-8 -*-
"""
PHASE 2 — EXTRAIRE LE PATRIMOINE D'UN CRG : immeubles et lots.

⚠️ CE MOTEUR NE LIT AUCUN MONTANT ET NE RAPPROCHE RIEN. Il relève ce que le document imprime :
   quels immeubles, quels lots, quels locataires. La confrontation avec MBI est un travail de
   PHP, sur des données lues — pas une déduction faite au passage.

⚠️ IL NE DÉCIDE PLUS SEUL OÙ COMMENCE UN LOT. Il partageait autrefois son propre motif, plus
   ancien que celui des phases 3 et 4 : il ignorait les blocs « Suite », les références d'un
   seul caractère et les espaces d'OCR au milieu d'une référence. Résultat : **98 lots là où
   les phases 3 et 4 en démontraient 124** — vingt-six lots imprimés par le document et absents
   du patrimoine, sans le moindre signal. `EXACTITUDE ≠ EXHAUSTIVITÉ` : il était juste sur ce
   qu'il traitait, et faux sur la population. La segmentation vit désormais dans
   `crg_integration_lots.py`, et les trois phases y lisent la même chose.

⚠️ IL LIT EN MODE TABLEAU, COMME LES PHASES 3 ET 4. Le mode `-layout` fait dériver les lignes
   et sépare l'en-tête de lot de son occupant. Chaque phase lit dans le mode qui démontre SA
   donnée ; celui de la phase 0 reste scellé et n'est pas touché.

Usage : python crg_integration_phase2.py <chemin.pdf> <plages.json>
Sortie : un tableau JSON — une entrée par plage, avec ses immeubles et ses lots.
"""
import json
import sys

sys.path.insert(0, __file__.rsplit('\\', 1)[0] if '\\' in __file__ else '.')
from crg_integration_lots import (code_immeuble, immeubles_de,   # noqa: E402
                                  segments_de_lot)
from crg_integration_phase3 import lire_pages                    # noqa: E402


def extraire(textes, page_base=1):
    """Les immeubles et les lots imprimés, avec la page où ils apparaissent."""
    immeubles, lots = {}, []
    for i, texte in enumerate(textes):
        page = page_base + i
        for imm in immeubles_de(texte, page):
            cle = (imm['nom'].upper(), imm['code_postal'])
            if cle not in immeubles:
                immeubles[cle] = imm
        for seg in segments_de_lot(texte, page):
            # ⚠️ UN EN-TÊTE « SUITE » NE ROUVRE PAS UN LOT : il continue le précédent. Le
            #    compter ajouterait une occurrence sans occupant, et le patrimoine ferait
            #    croire à un lot vacant qui n'existe pas.
            if seg['suite']:
                continue
            code_imm, num = code_immeuble(seg['reference'])
            lots.append({'reference': seg['reference'], 'code_immeuble': code_imm,
                         'numero': num, 'libelle': seg['libelle'],
                         'locataire': seg['locataire'], 'page': page})
    # ⚠️ LE CODE D'IMMEUBLE VIENT DES LOTS, pas d'une ligne « Immeuble ». Le document n'imprime
    #    pas le code à côté du nom : on le rattache par l'ordre d'apparition sur la page, et on
    #    laisse `None` là où la page ne permet pas de conclure. `UN LOT SANS IMMEUBLE RESTE UN
    #    LOT SANS IMMEUBLE` — on ne le rattache jamais au dernier immeuble rencontré.
    for imm in immeubles.values():
        candidats = {lo['code_immeuble'] for lo in lots
                     if lo['page'] == imm['page'] and lo['code_immeuble']}
        imm['code'] = candidats.pop() if len(candidats) == 1 else None
    return list(immeubles.values()), lots


def main():
    """Une pièce, UNE lecture, tous ses CRG (`INTEG-PERF-01`)."""
    if len(sys.argv) < 3:
        sys.stderr.write('usage : crg_integration_phase2.py <pdf> <plages.json>\n'
                         '        plages.json = [{"id":1,"debut":1,"fin":2}, …]\n')
        return 2
    textes, _ = lire_pages(sys.argv[1])
    with open(sys.argv[2], encoding='utf-8') as fh:
        plages = json.load(fh)
    sortie = []
    for p in plages:
        debut, fin = int(p['debut']), int(p['fin'])
        immeubles, lots = extraire(textes[debut - 1:fin], debut)
        sortie.append({'id': p['id'], 'immeubles': immeubles, 'lots': lots})
    sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
