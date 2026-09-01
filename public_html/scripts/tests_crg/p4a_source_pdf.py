# -*- coding: utf-8 -*-
"""
P4A · EXHAUSTIVITÉ DOCUMENTAIRE DES LOCATAIRES — le PDF, page par page.

⚠️ UN TOTAL JUSTE NE PROUVE RIEN. On rouvre chaque CRG à la page déclarée du lot et on vérifie
   que le nom retenu y est LITTÉRALEMENT imprimé. Un nom peut être présent, lisible, et faux :
   une adresse, un fragment de ligne, une bannière — la Phase 1 en a fait l'expérience.

Lent (458 PDF) : à lancer à la demande, pas dans le harnais courant.
"""
import sys
import os
import io
import re
import json
import glob
import zipfile
import tempfile
import collections
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, ERREURS_PDF   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
ZIPS = glob.glob(r'D:\CRG REGIE EMERY VIENNE\*.zip')
SORTIE = r'C:\tmp\p4a_source.json'


def plat(s):
    s = unicodedata.normalize('NFD', str(s or ''))
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    return ' '.join(re.sub(r'[^0-9A-Za-z ]+', ' ', s).split()).upper()


def colle(s):
    """SEPTEO imprime parfois les noms espaces collés : on compare aussi sans les espaces."""
    return plat(s).replace(' ', '')


def chemin(d, ag, zf, tmp):
    nom = d.get('_nom_original')
    fich = str(d.get('_fichier') or nom)
    if ag == 'VIENNE':
        interne = next((n for n in zf.namelist() if n.endswith(fich.split(' :: ')[-1])), None)
        return zf.extract(interne, tmp) if interne else None
    racine = RACINES.get(ag)
    direct = os.path.join(racine, fich.replace('/', '\\'))
    if os.path.exists(direct):
        return direct
    t = glob.glob(os.path.join(racine, '**', nom), recursive=True)
    return t[0] if t else None


zf = zipfile.ZipFile(ZIPS[0]) if ZIPS else None
tmp = tempfile.mkdtemp()
lignes = []
c = collections.Counter()

for ag in perimetre():
    docs = documents(ag)
    for i, d in enumerate(docs, 1):
        p = chemin(d, ag, zf, tmp)
        pages = {}
        if p and os.path.exists(p):
            try:
                with pdfplumber.open(p) as pdf:
                    for n, pg in enumerate(pdf.pages, 1):
                        pages[n] = plat(pg.extract_text() or '')
            except ERREURS_PDF as e:
                c[(ag, 'pdf illisible')] += 1
        else:
            c[(ag, 'pdf introuvable')] += 1
        m = d.get('meta') or {}
        for im in d.get('immeubles') or []:
            for lo in im.get('lots') or []:
                nom = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                page = lo.get('page')
                c[(ag, 'occurrences')] += 1
                anom = []
                if not nom:
                    anom.append('SANS NOM')
                if '\ufffd' in nom:
                    anom.append('MUTILE')
                if sum(ch in '*=_~' for ch in nom) >= 3:
                    anom.append('DECORATIF')
                txt = pages.get(page, '')
                # ⚠️ ON CHERCHE D'ABORD SUR LA PAGE DECLAREE, puis dans tout le document :
                #    un lot a cheval sur deux pages imprime son locataire sur l'une des deux.
                present = bool(nom) and (plat(nom) in txt or colle(nom) in txt.replace(' ', ''))
                ou = 'page declaree' if present else ''
                if nom and not present:
                    for n2, t2 in pages.items():
                        if plat(nom) in t2 or colle(nom) in t2.replace(' ', ''):
                            present, ou = True, 'page %d' % n2
                            break
                if nom and not present and pages:
                    anom.append('ABSENT DU PDF')
                if present:
                    c[(ag, 'verifiees')] += 1
                if anom:
                    c[(ag, 'anomalies')] += 1
                lignes.append({'agence': ag, 'pdf': d.get('_nom_original'), 'sha': d.get('_sha'),
                               'page': page, 'trouve': ou, 'periode': m.get('periode_cle'),
                               'date_arrete': m.get('date_arrete'),
                               'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                               'compte': str(m.get('compte') or ''),
                               'immeuble': str(im.get('code') or im.get('nom') or ''),
                               'lot': str(lo.get('numero_lot') or ''),
                               'libelle_locataire_source': nom, 'anomalies': anom})
        if i % 50 == 0:
            print('   %s %d/%d...' % (ag, i, len(docs)), flush=True)

io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(lignes, ensure_ascii=False))
print('\n%-16s %12s %12s %11s' % ('PÉRIMÈTRE', 'occurrences', 'vérifiées', 'anomalies'))
for ag in perimetre():
    print('%-16s %12d %12d %11d' % (ag, c[(ag, 'occurrences')], c[(ag, 'verifiees')],
                                    c[(ag, 'anomalies')]))
print('%-16s %12d %12d %11d' % ('TOTAL',
      sum(c[(a, 'occurrences')] for a in perimetre()),
      sum(c[(a, 'verifiees')] for a in perimetre()),
      sum(c[(a, 'anomalies')] for a in perimetre())))
print('\ndétail -> %s' % SORTIE)
