# -*- coding: utf-8 -*-
"""
P5A · LECTURE GÉOMÉTRIQUE DES LIGNES DE SITUATION — arbitrage Emmanuel du 30/08/2026.

⚠️ LIRE LES NOMBRES DANS L'ORDRE EST FAUX : LES CELLULES VIDES NE S'IMPRIMENT PAS. La seule
   lecture fidèle passe par les positions x. L'entête donne les bandes, relevées au PDF :

     Locataires@63 · Période@184 · Loyers@275 · Taxes@331 · Provisions@378 · Divers@493
     · Total@659 · Réglés@711 · Impayés@763

   Un montant appartient à la colonne dont le début d'entête est le plus grand encore inférieur
   ou égal à son x0. Vérifié : « 93.19@623 » tombe en Divers (bande 493→659), « 825.18@673 » en
   Total, « 0.00@736 » en Réglés, « 825.18@782 » en Impayés.

⚠️ ET LE LIBELLÉ N'EST PAS TOUJOURS APRÈS LES DATES. Le corpus imprime les deux formes :

     Du 19.05.25 Au 19.05.25 Frais d'Huissier 73.18        (libellé APRÈS)
     Rappel de Loyer Du 15.09.25 Au 30.09.25 4.97          (libellé AVANT)

   La première version ne lisait que la première forme — d'où des « libellé non retrouvé » et
   des attributions ambiguës. On prend ici TOUS les mots non numériques de la ligne, quel que
   soit leur côté.

⚠️ ET ON N'AFFECTE JAMAIS UN MONTANT À UN LIBELLÉ PAR ORDRE SUPPOSÉ. Si la géométrie ne
   démontre pas l'attribution, la ligne reste INDÉTERMINABLE — ATTRIBUTION DOCUMENTAIRE AMBIGUË,
   avec son montant et sa provenance.

Lent : rouvre les PDF de LYON, EMERY et GROUPE SIR. VIENNE n'a pas de colonne « Divers ».
"""
import sys
import os
import io
import re
import json
import glob
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, ERREURS_PDF   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
SORTIE = r'C:\tmp\p5a_geometrie.json'

COLONNES = ('Loyers', 'Taxes', 'Provisions', 'Divers', 'Total', 'Réglés', 'Impayés')
RE_DATE = re.compile(r'^\d{2}\.\d{2}\.\d{2}$')
RE_NOMBRE = re.compile(r'^-?\d[\d\s]*[.,]\d{2}$')


def jj(d):
    j, m, a = d.split('.')
    return '20%s-%s-%s' % (a, m, j)


def par_ligne(mots, tolerance=3.0):
    """Les mots regroupés en LIGNES VISUELLES, pas en `top` exacts.

       ⚠️ UN LIBELLÉ PEUT ÊTRE IMPRIMÉ QUELQUES POINTS PLUS BAS QUE SES MONTANTS. Chez EMERY,
          « Solde Antérieur » est à top=202 quand son 4 706,51 € est à top=200 : un regroupement
          par `top` arrondi les sépare, et 304 libellés sur 306 disparaissaient."""
    groupes = []
    for m in sorted(mots, key=lambda z: (z['top'], z['x0'])):
        if groupes and abs(m['top'] - groupes[-1][0]) <= tolerance:
            groupes[-1][1].append(m)
        else:
            groupes.append([m['top'], [m]])
    return {round(t): ms for t, ms in groupes}


def bandes(mots):
    """Les bornes gauches des colonnes, lues sur la ligne d'entête de la page."""
    for top, ms in par_ligne(mots).items():
        txt = {m['text'] for m in ms}
        if 'Loyers' in txt and 'Divers' in txt and 'Total' in txt:
            return {m['text']: m['x0'] for m in ms if m['text'] in COLONNES}
    return None


def colonne(x, bornes):
    """La colonne d'un montant : la dernière dont l'entête commence avant lui."""
    candidat, meilleur = None, -1
    for nom, x0 in bornes.items():
        if x0 <= x and x0 > meilleur:
            candidat, meilleur = nom, x0
    return candidat


def lignes_de_page(pg, secours=None, lot_initial=None):
    """Chaque ligne « Du … Au … » de la page : période, libellé, et montants PAR COLONNE.

       ⚠️ L'ENTÊTE N'EST PAS RÉPÉTÉ SUR LES PAGES DE CONTINUATION. La page 117 du CRG 2025-T2 de
          SARL GROUPE SIR n'en porte pas, et toute la page devenait illisible — dont les
          8 297,33 € de `FIDAL`. On repart alors des bandes de la dernière page qui en avait :
          la mise en page est la même d'une page à l'autre du même document."""
    mots = pg.extract_words()
    trouvees = bandes(mots)
    bornes = trouvees if (trouvees and 'Divers' in trouvees) else secours
    if not bornes or 'Divers' not in bornes:
        return [], lot_initial
    # ⚠️ LE LOT N'EST PAS RÉPÉTÉ SUR LES PAGES DE CONTINUATION. Le bloc « Lot 0003 » de FIDAL
    #    commence page 116 et déborde page 117 : lire la page 117 seule laissait toutes ses
    #    lignes sans lot, dont 8 297,33 € de solde de charges. Le lot courant se transmet donc
    #    de page en page, comme le document le fait lui-même.
    par = par_ligne(mots)
    out, lot = [], lot_initial
    for top in sorted(par):
        ms = sorted(par[top], key=lambda z: z['x0'])
        textes = [m['text'] for m in ms]
        if textes[:1] == ['Lot']:
            lot = textes[1] if len(textes) > 1 else lot
            continue
        dates = [m for m in ms if RE_DATE.match(m['text'])]
        # ⚠️ UNE DATE PEUT ÊTRE ILLISIBLE DANS LE DOCUMENT LUI-MÊME. Le CRG 2026-T1 de SARL
        #    GROUPE SIR imprime « Du Na.je Au 01.11.24 Solde de charges 14.97 » : la date de
        #    début est un fragment du nom du locataire. Le lecteur figé jette la ligne ENTIÈRE
        #    et perd ses 14,97 € — c'est l'écart HMAIRIA. Le montant et le libellé, eux, sont
        #    parfaitement lisibles : on garde la ligne et on déclare la date manquante.
        if not dates or 'Du' not in textes or 'Au' not in textes:
            continue
        illisible = len(dates) < 2
        nombres = [m for m in ms if RE_NOMBRE.match(m['text'])]
        # ⚠️ TOUT MOT NI DATE NI NOMBRE NI MOT-OUTIL EST DU LIBELLÉ, AVANT COMME APRÈS.
        libelle = ' '.join(m['text'] for m in ms
                           if m['text'] not in ('Du', 'Au')
                           and not RE_DATE.match(m['text'])
                           and not RE_NOMBRE.match(m['text'])
                           and m['text'] not in ('derewoP', 'yb'))
        cols = {}
        for m in nombres:
            c = colonne(m['x0'], bornes)
            if c:
                cols.setdefault(c, []).append(float(m['text'].replace(' ', '').replace(',', '.')))
        out.append({'top': top, 'lot': lot,
                    'du': None if illisible else jj(dates[0]['text']),
                    'au': jj(dates[-1]['text']),
                    'date_debut_illisible': illisible,
                    'libelle': libelle.strip(), 'colonnes': {k: v for k, v in cols.items()}})
    return out, lot


if __name__ == '__main__':
    lignes, c = [], collections.Counter()
    for ag in perimetre():
        if ag == 'VIENNE':
            continue
        docs = documents(ag)
        for i, d in enumerate(docs, 1):
            pages = {r.get('page') for im in d.get('immeubles') or []
                     for lo in im.get('lots') or [] for r in lo.get('mois') or []
                     if r.get('divers')}
            if not pages:
                continue
            t = glob.glob(os.path.join(RACINES[ag], '**', d.get('_nom_original')), recursive=True)
            if not t:
                c[(ag, 'pdf introuvable')] += 1
                continue
            try:
                with pdfplumber.open(t[0]) as pdf:
                    secours = None
                    for n in range(1, len(pdf.pages) + 1):
                        b = bandes(pdf.pages[n - 1].extract_words())
                        if b and 'Divers' in b:
                            secours = b
                            break
                    # ⚠️ ON PARCOURT TOUTES LES PAGES DANS L'ORDRE, même celles sans « Divers » :
                    #    c'est le seul moyen de savoir quel lot court sur une page de continuation.
                    #    On ne CONSERVE que les lignes des pages qui nous intéressent.
                    lot = None
                    voulues = {x for x in pages if isinstance(x, int)}
                    for n in range(1, len(pdf.pages) + 1):
                        lus, lot = lignes_de_page(pdf.pages[n - 1], secours, lot)
                        if n not in voulues:
                            continue
                        for l in lus:
                            l.update(agence=ag, sha=d.get('_sha'), page=n,
                                     crg_fichier=d.get('_nom_original'))
                            lignes.append(l)
                            c[(ag, 'lignes lues')] += 1
            except ERREURS_PDF:
                # ⚠️ un PDF corrompu est un fait documentaire, COMPTÉ. Un bug de lecture, lui,
                #    remonte : `Exception` aurait confondu les deux.
                c[(ag, 'pdf illisible')] += 1
            if i % 60 == 0:
                print('   %s %d/%d...' % (ag, i, len(docs)), flush=True)

    io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(lignes, ensure_ascii=False))
    entete('LECTURE GÉOMÉTRIQUE — LIGNES « Du … Au … » DES PAGES PORTANT UN « DIVERS »')
    print('  %-16s %14s %18s %18s' %
          ('PÉRIMÈTRE', 'lignes lues', 'avec un Divers', 'avec un libellé'))
    for ag in perimetre():
        if ag == 'VIENNE':
            continue
        ls = [l for l in lignes if l['agence'] == ag]
        print('  %-16s %14d %18d %18d' %
              (ag, len(ls), sum(1 for l in ls if 'Divers' in l['colonnes']),
               sum(1 for l in ls if l['libelle'])))
    print('\n  %d lignes → %s' % (len(lignes), SORTIE))
