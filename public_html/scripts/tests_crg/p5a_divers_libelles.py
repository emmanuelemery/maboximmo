# -*- coding: utf-8 -*-
"""
P5A · RÉCUPÉRATION DU LIBELLÉ DES LIGNES « DIVERS » — arbitrage Emmanuel du 30/08/2026.

⚠️ 462 491,38 € NE PEUVENT PAS RESTER « À QUALIFIER » PARCE QUE LE LECTEUR JETTE UN LIBELLÉ QUE
   LE PDF IMPRIME. Le lecteur `lyon` ne conserve d'une ligne que ses colonnes chiffrées. Or la
   ligne imprimée est de la forme :

       Du 19.05.25 Au 19.05.25 Frais d'Huissier 73.18

   Le libellé est là, entre les dates et les montants. On le récupère ici SANS toucher aux
   lecteurs figés : les extractions JSON sur lesquelles P1 → P4B sont certifiées ne changent
   pas d'un octet. C'est un lecteur de certification supplémentaire, pas une modification du
   socle — la seule voie qui récupère la vérité documentaire sans risquer une régression.

⚠️ RÉCUPÉRER N'EST PAS QUALIFIER. Ce script produit le libellé BRUT. La nature métier ne sera
   déduite que lorsque le libellé la DÉMONTRE, et le libellé source est conservé dans tous les
   cas. Aucun classement par mot-clé approximatif sans contrôle PDF.

⚠️ ET LE LIBELLÉ N'EST PAS QU'UNE ÉTIQUETTE : IL CORRIGE DES MONTANTS. Deux écarts de contrôle
   inexpliqués s'expliquent par lui — « Du 04.06.25 Au 04.06.25 Article 700 105.98 » : le
   lecteur a pris le 700 d'« Article 700 » pour une provision. Idem pour « Parking + 2
   voitures », dont le 2 est devenu 2,00 €. Le libellé perdu ne coûtait pas seulement du sens,
   il polluait les colonnes.

Lent (les PDF de LYON, EMERY et GROUPE SIR). VIENNE n'a pas de colonne « Divers ».
"""
import sys
import os
import io
import re
import json
import glob
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, euros, ERREURS_PDF   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
SORTIE = r'C:\tmp\p5a_divers.json'

# « Du 19.05.25 Au 19.05.25 Frais d'Huissier 73.18 » — dates, libellé, puis la file de montants.
RE_LIGNE = re.compile(
    r'^\s*Du\s+(\d{2}\.\d{2}\.\d{2})\s+Au\s+(\d{2}\.\d{2}\.\d{2})\s+(.*?)\s*'
    r'((?:-?\d[\d\s]*[.,]\d{2}\s*)+)$')
RE_LOT = re.compile(r'^\s*Lot\s+([0-9A-Za-z]+)\b')


def jj(d):
    """« 19.05.25 » → « 2025-05-19 », la forme du socle certifié."""
    j, m, a = d.split('.')
    return '20%s-%s-%s' % (a, m, j)


def chemin(nom, agence):
    racine = RACINES.get(agence)
    if not racine:
        return None
    t = glob.glob(os.path.join(racine, '**', nom), recursive=True)
    return t[0] if t else None


def lignes_du_pdf(chem):
    """Toutes les lignes « Du … Au … libellé montants » du document, par page et par lot."""
    out = []
    try:
        with pdfplumber.open(chem) as pdf:
            for n, pg in enumerate(pdf.pages, 1):
                lot = None
                for l in (pg.extract_text() or '').split('\n'):
                    m = RE_LOT.match(l)
                    if m:
                        lot = m.group(1)
                        continue
                    m = RE_LIGNE.match(l)
                    if not m:
                        continue
                    libelle = m.group(3).strip()
                    # ⚠️ UNE LIGNE SANS LIBELLÉ EST UN APPEL ORDINAIRE (loyer/taxes/provisions).
                    #    Seules celles qui PORTENT un texte nous intéressent ici.
                    if libelle:
                        out.append({'page': n, 'lot': lot, 'du': jj(m.group(1)),
                                    'au': jj(m.group(2)), 'libelle': libelle,
                                    'montants': m.group(4).strip()})
    except ERREURS_PDF as e:
        return [{'erreur': str(e)}]
    return out


if __name__ == '__main__':
    trouve, c = [], collections.Counter()
    for ag in perimetre():
        if ag == 'VIENNE':
            continue
        docs = documents(ag)
        for i, d in enumerate(docs, 1):
            # les lignes « Divers » que le lecteur a vues, sans leur libellé
            attendues = set()
            for im in d.get('immeubles') or []:
                for lo in im.get('lots') or []:
                    for r in lo.get('mois') or []:
                        if r.get('divers'):
                            attendues.add((r.get('page'), str(lo.get('numero_lot') or ''),
                                           r.get('du'), r.get('au')))
            c[(ag, 'lignes divers attendues')] += len(attendues)
            if not attendues:
                continue
            chem = chemin(d.get('_nom_original'), ag)
            if not chem:
                c[(ag, 'pdf introuvable')] += 1
                continue
            for l in lignes_du_pdf(chem):
                if 'erreur' in l:
                    c[(ag, 'pdf illisible')] += 1
                    break
                cle = (l['page'], l['lot'], l['du'], l['au'])
                if cle not in attendues:
                    continue
                c[(ag, 'libellés retrouvés')] += 1
                trouve.append(dict(l, agence=ag, sha=d.get('_sha'),
                                   crg_fichier=d.get('_nom_original')))
            if i % 60 == 0:
                print('   %s %d/%d...' % (ag, i, len(docs)), flush=True)

    io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(trouve, ensure_ascii=False))

    entete('LIGNES « DIVERS » — LIBELLÉ RETROUVÉ AU PDF')
    print('  %-16s %22s %22s' % ('PÉRIMÈTRE', 'lignes attendues', 'libellés retrouvés'))
    for ag in perimetre():
        if ag == 'VIENNE':
            print('  %-16s %22s %22s' % (ag, 'pas de colonne Divers', '—'))
            continue
        print('  %-16s %22d %22d' % (ag, c[(ag, 'lignes divers attendues')],
                                     c[(ag, 'libellés retrouvés')]))

    entete('LE VOCABULAIRE RÉELLEMENT IMPRIMÉ (avant tout classement)')
    voc = collections.Counter(re.sub(r'\d+', '#', t['libelle'])[:56] for t in trouve)
    for k, v in voc.most_common(30):
        print('  %5d  %s' % (v, k))
    print('\n  %d libellés → %s' % (len(trouve), SORTIE))
