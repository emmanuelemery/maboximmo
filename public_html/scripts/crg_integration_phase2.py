# -*- coding: utf-8 -*-
"""
PHASE 2 — EXTRAIRE LE PATRIMOINE D'UN CRG : immeubles et lots.

⚠️ CE MOTEUR NE LIT AUCUN MONTANT ET NE RAPPROCHE RIEN. Il relève ce que le document imprime :
   quels immeubles, quels lots, quels locataires. La confrontation avec MBI est un travail de
   PHP, sur des données lues — pas une déduction faite au passage.

⚠️ LE CODE D'IMMEUBLE SE LIT DANS LA RÉFÉRENCE DE LOT, ET IL FAUT SAVOIR OÙ. Quatre graphies
   coexistent sur le même corpus :
       `3062-0187`            → immeuble 3062, lot 0187
       `276-04`               → immeuble 276,  lot 04
       `01G01-5213-000367`    → immeuble 5213, lot 000367
       `01S01-0075-000009`    → immeuble 0075, lot 000009
   Prendre systématiquement le premier segment donnerait « 01G01 » pour deux cent vingt et un
   lots — un immeuble qui n'existe pas.

⚠️ ET UN LOT SANS IMMEUBLE RESTE UN LOT SANS IMMEUBLE. On ne le rattache pas au dernier
   immeuble rencontré « parce qu'il est juste au-dessus » : c'est ainsi qu'on déplace un bien
   d'un immeuble à un autre sans que personne ne s'en aperçoive.

Usage : python crg_integration_phase2.py <chemin.pdf> <p_debut> <p_fin>
Sortie : un objet JSON — immeubles, lots.
"""
import json
import re
import sys

sys.path.insert(0, __file__.rsplit('\\', 1)[0] if '\\' in __file__ else '.')
from crg_integration_phase0 import lire_pages   # noqa: E402

# « Immeuble 45, rue Druge - 38200 VIENNE »  ·  « Immeuble LE PERPIGNAN - 38200 VIENNE »
RE_IMMEUBLE = re.compile(r'^\s*Immeuble\s+(.+?)\s*[-–]\s*(\d{5})\s+([A-ZÉÈÀÂÎÔÛa-zéèàâîôû\'\- ]+?)'
                         r'(?:\s{2,}.*)?$', re.M)
# « Appartement 3 Pièces - Lot 3062-0187 – Mandat N/A »
RE_LOT = re.compile(r'^\s*(.*?)\s*-\s*Lot\s+([0-9A-Za-z][0-9A-Za-z-]{2,23}?)-?\s*[–-]\s*Mandat',
                    re.M)
RE_LOT_SIMPLE = re.compile(r'\bLot\s+([0-9A-Za-z][0-9A-Za-z-]{2,23}?)-?\b')
RE_LOCATAIRE = re.compile(r'Locataire\s*:\s*(.+?)\s*,\s*Bail', re.I)


def code_immeuble(ref_lot):
    """L'immeuble que porte une référence de lot — ou rien, si elle ne le porte pas.

    ⚠️ ON NE DEVINE PAS. Une référence qui ne suit aucune des quatre graphies observées rend
       `None` : un immeuble inventé contaminerait tout le rapprochement qui suit.
    """
    if not ref_lot:
        return None, None
    parts = ref_lot.split('-')
    if len(parts) == 3:
        # `01G01-5213-000367` : le premier segment est un code de portefeuille, pas l'immeuble.
        return parts[1], parts[2]
    if len(parts) == 2:
        return parts[0], parts[1]
    return None, ref_lot


def extraire(textes):
    """Les immeubles et les lots imprimés, avec la page où ils apparaissent."""
    immeubles, lots = {}, []
    for i, texte in enumerate(textes, 1):
        for m in RE_IMMEUBLE.finditer(texte):
            nom = ' '.join(m.group(1).split())
            cp, ville = m.group(2), ' '.join(m.group(3).split())
            cle = (nom.upper(), cp)
            if cle not in immeubles:
                immeubles[cle] = {'nom': nom, 'code_postal': cp, 'ville': ville,
                                  'page': i, 'code': None}
        locataire = None
        for m in RE_LOCATAIRE.finditer(texte):
            locataire = ' '.join(m.group(1).split())
        for m in RE_LOT.finditer(texte):
            libelle = ' '.join(m.group(1).split())
            ref = m.group(2).rstrip('-')
            code_imm, num = code_immeuble(ref)
            lots.append({'reference': ref, 'code_immeuble': code_imm, 'numero': num,
                         'libelle': libelle[:120], 'locataire': locataire, 'page': i})
    # ⚠️ LE CODE D'IMMEUBLE VIENT DES LOTS, pas d'une ligne « Immeuble ». Le document n'imprime
    #    pas le code à côté du nom : on le rattache par l'ordre d'apparition sur la page, et on
    #    laisse `None` là où la page ne permet pas de conclure.
    for imm in immeubles.values():
        candidats = {lo['code_immeuble'] for lo in lots
                     if lo['page'] == imm['page'] and lo['code_immeuble']}
        imm['code'] = candidats.pop() if len(candidats) == 1 else None
    return list(immeubles.values()), lots


def main():
    """Une pièce, UNE lecture, tous ses CRG.

    ⚠️ LE DÉFAUT QUE CELA CORRIGE ÉTAIT MAJEUR. La première version prenait un seul
       intervalle de pages et relisait le PDF à chaque appel : sur un document de 587 Mo et
       266 CRG, cela faisait deux cent soixante-six lectures complètes pour un travail qui en
       demande UNE. L'analyse ne finissait pas.
    """
    if len(sys.argv) < 3:
        sys.stderr.write('usage : crg_integration_phase2.py <pdf> <plages.json>\n'
                         '        plages.json = [{"id":1,"debut":1,"fin":2}, …]\n')
        return 2
    textes, _ = lire_pages(sys.argv[1])
    with open(sys.argv[2], encoding='utf-8') as fh:
        plages = json.load(fh)
    sortie = []
    for p in plages:
        immeubles, lots = extraire(textes[int(p['debut']) - 1:int(p['fin'])])
        sortie.append({'id': p['id'], 'immeubles': immeubles, 'lots': lots})
    sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
