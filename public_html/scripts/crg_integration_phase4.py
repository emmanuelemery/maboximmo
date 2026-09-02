# -*- coding: utf-8 -*-
"""
PHASE 4 — LIRE L'ARGENT D'UN CRG : chaque euro avec sa nature, sa maille et sa provenance.

⚠️ CE DOCUMENT NE SE LIT PAS AU BLANC, IL SE LIT AUX COLONNES. `-layout` fait dériver les
   montants d'une ligne à l'autre, `-table` les recolle mais ne dit plus dans QUELLE colonne
   ils étaient. Or ici la colonne EST la nature : le même « 474,49 » est un loyer appelé en
   colonne `Loyers`, un encaissement en colonne `Crédit`, un impayé en colonne `Reste dû`.
   On lit donc les coordonnées (pdfplumber, 0,07 s/page) et on affecte chaque montant à sa
   colonne par son BORD DROIT — les nombres sont cadrés à droite.

⚠️ LES COLONNES SONT UNE GRILLE RÉGULIÈRE, RELEVÉE SUR LES 794 PAGES RATTACHÉES :
   Loyers 258 · Charges 309 · Autres 360 · Reste dû 411 · Débit 487 · Crédit 564.
   Aucun montant n'est affecté « au plus proche » sans borne : au-delà de la tolérance il est
   déclaré INDETERMINABLE et remonté à l'écran. Un montant qu'on ne sait pas placer n'est
   jamais rangé dans la colonne d'à côté.

⚠️ LE RÉCAPITULATIF N'EST PAS UN MOUVEMENT. Il REJOUE par immeuble ce que les blocs ont déjà
   dit — 678 montants sur ce dépôt. `AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE` : on le lit, on le
   marque, et on ne l'additionne JAMAIS avec les mouvements.

⚠️ ET LA PROSE MENT PAR HASARD. « la somme de 1 372,86 € (constituée des factures dues à ce
   jour : 2 177,33 €) sera reportée… » : ce 1 372,86 tombe pile sous la colonne `Charges`.
   Une phrase n'est pas une ligne de tableau — les lignes de prose sont écartées d'abord.

Usage : python crg_integration_phase4.py <chemin.pdf> <plages.json>
Sortie : un tableau JSON — une entrée par plage, avec ses mouvements et ses anomalies.
"""
import hashlib
import json
import re
import sys

import pdfplumber

# ── la grille des colonnes, et ce que chacune veut dire ───────────────────────────────────
COLONNES = (('loyers', 258), ('charges', 309), ('autres', 360),
            ('reste_du', 411), ('debit', 487), ('credit', 564))
TOLERANCE = 12          # points ; au-delà, le montant n'est PAS affecté d'office
HAUTEUR_LIGNE = 3       # regroupement vertical des mots en lignes

ESP = '[\\d   ]'      # chiffres et toutes les espaces que produit l'extraction
RE_MONTANT = re.compile('^-?' + ESP + '{1,14},\\d{2}$')
RE_MILLIERS = re.compile(r'^-?\d{1,3}$')
RE_CENTAINES = re.compile(r'^\d{3},\d{2}$')
RE_TRONC = re.compile('^-?' + ESP + '+,$')
RE_DATE = re.compile(r'^(\d{2}/\d{2}/\d{4})$')
# Reprise de la phase 3 : la référence de lot porte des espaces d'OCR en son milieu.
# ⚠️ ET UNE RÉFÉRENCE D'UN SEUL CARACTÈRE EST UNE RÉFÉRENCE. Le motif de la phase 3 exigeait
#    trois caractères ; « - Lot 1 - Mandat N/A - » ne l'atteint pas. Quatre en-têtes du dépôt
#    en sont là, tous sur le compte 1105404745 : le bloc du lot ne s'ouvrait jamais, et ses
#    28 montants — dont 738,86 € de loyer appelé — tombaient en INDETERMINABLE. Un seuil de
#    longueur n'est pas un critère métier.
RE_LOT = re.compile(r'-\s*Lot\s+([0-9A-Za-z][0-9A-Za-z \-]{0,28}?)-?\s*[–-]\s*Mandat')
RE_LOC = re.compile(r'Locataire\s*:\s*(.+?)\s*,\s*Bail', re.I)
RE_SUITE = re.compile(r'\.{0,3}\s*Suite\s*$', re.I)
RE_SECTION = re.compile(r'^-\s*(.{3,50}?)\s*-$')
RE_VIREMENT = re.compile('^Virement\\s*:?\\s*(-?' + ESP + r'+,\d{2})\s*€?', re.I)
# ⚠️ L'OCR SOUDE « dont TVA » EN « dontTVA », et lit parfois le T comme un l — « dontlVA » (INTEG-LIRE-03). Exiger l'espace laissait
#    34 détails de TVA — 1 039,91 € — en INDETERMINABLE, comme s'ils étaient des mouvements
#    qu'on ne savait pas classer, alors que le document les désigne explicitement.
RE_TVA = re.compile(r'^dont\s*[TIl1]VA', re.I)
# ⚠️ UN PARAMÈTRE IMPRIMÉ DANS LE LIBELLÉ N'EST PAS UN MOUVEMENT. « Honoraires Gestion TTC
#    (taux:6,00 % HT, base:1 985,96 €) » porte l'ASSIETTE du calcul, pas une somme due : la
#    compter ajouterait 1 985,96 € de dépense imaginaire à ce compte.
RE_PARAMETRE = re.compile(r'(base|taux|quote-part)\s*:?\s*[\d ]*$', re.I)

# ⚠️ CES LIGNES NE SONT PAS DES LIGNES DE TABLEAU. Pied de page présent sur CHAQUE page,
#    en-tête de reprise, et les phrases de conclusion qui contiennent des montants.
REJETS = (
    'Agence REGIE EMERY', 'SARL REGIE EMERY', 'Carte Professionnelle',
    'Compte rendu de gestion', 'Nous vous prions', 'Compte tenu de la situation',
    'Identifiant extratnet', 'Mot de passe',
)
RE_PROSE = re.compile(r'sera report\w+ au (d[ée]bit|cr[ée]dit)|constitu[ée]e? des factures',
                      re.I)

# ── les catégories demandées, plus ce qui n'en est pas une ────────────────────────────────
LOYER_APPELE = 'LOYER APPELE'
CHARGE_APPELEE = 'CHARGE APPELEE AU LOCATAIRE'
AUTRE_APPELE = 'AUTRE APPELE AU LOCATAIRE'
ENCAISSEMENT = 'ENCAISSEMENT'
ENCOURS = 'ENCOURS'
CHARGE = 'CHARGE'
FRAIS = 'FRAIS ET ASSURANCES'
VERSEMENT = 'VERSEMENT PROPRIETAIRE'
SOLDE = 'SOLDE'
AGREGAT = 'AGREGAT (NON ADDITIONNABLE)'
DETAIL = 'DETAIL (NON ADDITIONNABLE)'
INDETERMINABLE = 'INDETERMINABLE'

# section imprimée par le document -> nature des lignes qu'elle contient
SECTIONS = {
    'HONORAIRES': FRAIS,
    'GLI': FRAIS,
    'GU': FRAIS,
    'FACTURES': CHARGE,
    'SYNDIC': CHARGE,
    'PROPRIETAIRE': CHARGE,
    'RECAP': AGREGAT,
    'INDIVISION': AGREGAT,
}


def nombre(txt):
    """« 1 775,40 » -> 1775.40, quelle que soit l'espace employée pour les milliers."""
    return float(re.sub('[   ]', '', txt).replace(',', '.'))


def colonne_de(mot):
    """La colonne d'un montant, par son BORD DROIT. `None` si aucune ne le revendique."""
    for nom, x in COLONNES:
        if abs(mot['x1'] - x) <= TOLERANCE:
            return nom
    return None


def lignes_de(page):
    """Les mots de la page regroupés en lignes, chaque ligne triée de gauche à droite."""
    groupes = {}
    for mot in page.extract_words():
        groupes.setdefault(round(mot['top'] / HAUTEUR_LIGNE), []).append(mot)
    return [sorted(groupes[k], key=lambda m: m['x0']) for k in sorted(groupes)]


def recoller(mots):
    """Recolle les nombres que l'extraction a coupés en deux.

    ⚠️ L'ESPACE DES MILLIERS COUPE LE MONTANT, ET C'EST DE L'ARGENT QUI DISPARAÎT. « 1 775,40 »
       ressort en « 1 » puis « 775,40 » : sans recollage on lit 775,40 et on perd mille euros
       SANS AUCUN SIGNAL — ni erreur, ni anomalie, juste un total faux. C'est le défaut le
       plus dangereux de cette lecture.

    ⚠️ ET LE SYMBOLE € COUPE LES CENTIMES. « Solde Immeuble 311,14€ » ressort en « 311, » puis
       « 14 ». Même conséquence, même remède.
    """
    sortie, i = [], 0
    while i < len(mots):
        m = dict(mots[i])
        m['text'] = m['text'].rstrip('€')
        suivant = mots[i + 1] if i + 1 < len(mots) else None
        if suivant:
            s = suivant['text'].rstrip('€')
            ecart = suivant['x0'] - m['x1']
            if RE_TRONC.match(m['text']) and re.match(r'^\d{2}$', s) and ecart < 6:
                sortie.append({**suivant, 'text': m['text'] + s, 'x0': m['x0']})
                i += 2
                continue
            if RE_MILLIERS.match(m['text']) and RE_CENTAINES.match(s) and 0 <= ecart < 6:
                sortie.append({**suivant, 'text': m['text'] + s, 'x0': m['x0']})
                i += 2
                continue
        sortie.append(m)
        i += 1
    return sortie


class Lecteur(object):
    """L'état de lecture d'un CRG : la section courante, et le lot courant s'il y en a un."""

    def __init__(self):
        self.section = None
        self.lot = None
        self.locataire = None
        self.immeuble = None

    def titre(self, texte):
        """Le document annonce-t-il ici une nouvelle section ? Renvoie True s'il le fait."""
        m = RE_SECTION.match(texte)
        if m:
            t = m.group(1).lower()
            self.section = ('HONORAIRES' if 'honoraires' in t else
                            'GLI' if t.startswith('gli') else
                            'GU' if t.startswith('gu') else
                            'SYNDIC' if 'syndic' in t else
                            'PROPRIETAIRE' if 'propri' in t else None)
            # ⚠️ UNE SECTION INCONNUE N'EST PAS UNE SECTION VIDE. On la retient sous son nom
            #    imprimé : ses lignes seront INDETERMINABLE et remonteront à l'écran.
            if self.section is None:
                self.section = 'INCONNUE:' + m.group(1)[:40]
            self.lot = self.locataire = None
            return True
        for depart, nom in (('Factures dues', 'FACTURES'), ('Récapitulatif', 'RECAP'),
                            ('Indivision', 'INDIVISION'), ('Quote-part', 'INDIVISION'),
                            ('Votre quote-part', 'INDIVISION')):
            if texte.startswith(depart):
                self.section = nom
                self.lot = self.locataire = None
                return True
        m = RE_LOT.search(texte)
        if m:
            # ⚠️ UN EN-TÊTE « SUITE » NE ROUVRE RIEN (règle de la phase 3) : le bloc continue.
            if not RE_SUITE.search(texte.strip()):
                self.section = 'BLOC_LOT'
                self.lot = m.group(1).replace(' ', '').rstrip('-')
                self.locataire = None
            return True
        if texte.startswith('Immeuble '):
            self.immeuble = texte[9:120].split('...')[0].strip()
            return True
        return False


def categoriser(lecteur, colonne, libelle):
    """La nature d'un montant : sa SECTION d'abord, sa COLONNE ensuite. Jamais le libellé seul.

    ⚠️ ON NE DÉDUIT JAMAIS UNE NATURE D'UN LIBELLÉ NI D'UN SIGNE. « Loyer » dans la colonne
       `Crédit` est un ENCAISSEMENT, pas un appel ; le même mot en colonne `Loyers` est un
       appel. Le vocabulaire aide à qualifier, il ne définit pas le périmètre.
    """
    if RE_TVA.match(libelle):
        return DETAIL, 'Détail du montant qui précède, pas un mouvement distinct.'
    if lecteur.section == 'BLOC_LOT':
        if libelle.lower().startswith('solde'):
            return ENCOURS, 'Solde du bloc du lot : une PHOTOGRAPHIE, jamais un flux.'
        return {
            'loyers': (LOYER_APPELE, 'Colonne « Loyers » du tableau d’appels du lot.'),
            'charges': (CHARGE_APPELEE,
                        'Colonne « Charges » : provision appelée AU LOCATAIRE — ce n’est pas '
                        'une dépense du propriétaire.'),
            'autres': (AUTRE_APPELE, 'Colonne « Autres » du tableau d’appels du lot.'),
            'credit': (ENCAISSEMENT,
                       'Colonne « Crédit » : somme reçue. Elle peut solder une période '
                       'ANTÉRIEURE — jamais un « encaissé de la période ».'),
            'reste_du': (ENCOURS,
                         'Colonne « Reste dû » : un STOCK, jamais additionné à un flux.'),
            'debit': (ENCOURS,
                      'Colonne « Débit » du bloc du lot : report du rapport précédent, donc '
                      'un STOCK.'),
        }.get(colonne, (INDETERMINABLE, 'Montant hors des colonnes du tableau d’appels.'))
    nature = SECTIONS.get(lecteur.section or '')
    if nature is None:
        return INDETERMINABLE, 'Section non reconnue : ' + str(lecteur.section)
    if libelle.lower().startswith('solde'):
        return SOLDE, 'Solde de section : un STOCK.'
    if nature == AGREGAT:
        return AGREGAT, ('Le récapitulatif rejoue par immeuble des montants déjà lus ailleurs.'
                         if lecteur.section == 'RECAP'
                         else 'Bloc d’indivision : quote-part et répartition, pas un mouvement '
                              'élémentaire de gestion.')
    if colonne in ('debit', 'credit', 'charges', 'autres'):
        return nature, 'Section « %s », colonne « %s ».' % (lecteur.section, colonne)
    return INDETERMINABLE, 'Montant hors des colonnes attendues de cette section.'


def lire(page, numero, lecteur):
    """Les mouvements et les anomalies d'une page."""
    mouvements, anomalies = [], []
    for bruts in lignes_de(page):
        mots = recoller(bruts)
        texte = ' '.join(m['text'] for m in mots).strip()
        if not texte or any(r in texte for r in REJETS) or RE_PROSE.search(texte):
            continue
        m = RE_LOC.search(texte)
        if m and lecteur.section == 'BLOC_LOT':
            lecteur.locataire = ' '.join(m.group(1).split())
        if lecteur.titre(texte):
            # ⚠️ UNE LIGNE DE TITRE PEUT PORTER SON PROPRE MONTANT, ET LES IGNORER EST UNE
            #    PERTE SILENCIEUSE. Dans le « Récapitulatif des immeubles », chaque ligne
            #    commence par « Immeuble … » — donc ressemble à un titre — et se termine par
            #    le total de cet immeuble : 164 montants sur 125 pages disparaissaient ainsi,
            #    sans erreur ni anomalie. On les lit donc comme les autres, avec la nature que
            #    leur section leur donne (ici AGRÉGAT, conservé mais jamais additionné).
            libelle = ' '.join(m['text'] for m in mots if not RE_MONTANT.match(m['text']))
            libelle = ' '.join(libelle.split())[:220]
            for mot in mots:
                if not RE_MONTANT.match(mot['text']):
                    continue
                categorie, motif = categoriser(lecteur, colonne_de(mot), libelle)
                maille = 'IMMEUBLE' if texte.startswith(('Immeuble', 'Solde Immeuble')) else None
                if categorie == INDETERMINABLE:
                    anomalies.append({'page': numero, 'texte': texte[:160],
                                      'montant': nombre(mot['text']),
                                      'x1': round(mot['x1'], 1), 'motif': motif,
                                      'section': lecteur.section})
                mouvements.append(_mvt(numero, lecteur, libelle, mot, categorie, motif,
                                       colonne_de(mot), maille))
            continue
        mv = RE_VIREMENT.match(texte)
        if mv:
            # ⚠️ LE VIREMENT N'EST PAS DANS UNE COLONNE. Il est écrit en clair dans le texte,
            #    318 fois sur ce dépôt. Le chercher dans la grille l'aurait fait disparaître.
            mot = next((m for m in mots if RE_MONTANT.match(m['text'])), None)
            if mot is not None:
                mouvements.append(_mvt(numero, lecteur, texte, mot, VERSEMENT,
                                       'Ligne « Virement » : somme effectivement versée au '
                                       'propriétaire.', 'inline', 'COMPTE'))
                continue
        date = next((RE_DATE.match(m['text']).group(1) for m in mots
                     if RE_DATE.match(m['text'])), None)
        libelle = ' '.join(m['text'] for m in mots
                           if not RE_MONTANT.match(m['text']) and not RE_DATE.match(m['text']))
        libelle = ' '.join(libelle.split())[:220]
        for i, mot in enumerate(mots):
            if not RE_MONTANT.match(mot['text']):
                continue
            colonne = colonne_de(mot)
            gauche = mots[i - 1]['text'] if i else ''
            # ⚠️ LE TEST DU PARAMÈTRE NE PASSE QU'APRÈS CELUI DE LA COLONNE. Un montant qui
            #    tombe dans une colonne EST une ligne du tableau, quoi qu'il y ait à sa
            #    gauche : inverser l'ordre ferait disparaître un vrai mouvement au motif
            #    qu'un mot ressemblant à « base: » le précède.
            if colonne is None and RE_PARAMETRE.search(gauche):
                mouvements.append(_mvt(numero, lecteur, libelle, mot, DETAIL,
                                       'Paramètre imprimé dans le libellé (« %s ») : c’est '
                                       'l’assiette du calcul, pas une somme due.' % gauche,
                                       'inline', None, date))
                continue
            categorie, motif = categoriser(lecteur, colonne, libelle)
            if categorie == INDETERMINABLE:
                anomalies.append({'page': numero, 'texte': texte[:160],
                                  'montant': nombre(mot['text']),
                                  'x1': round(mot['x1'], 1), 'motif': motif,
                                  'section': lecteur.section})
            mouvements.append(_mvt(numero, lecteur, libelle, mot, categorie, motif, colonne,
                                   None, date))
    return mouvements, anomalies


def _mvt(page, lecteur, libelle, mot, categorie, motif, colonne, maille=None, date=None):
    """Un mouvement, avec tout ce qui permet d'y revenir : sa page, sa colonne, sa maille."""
    if maille is None:
        maille = 'LOT' if lecteur.lot else 'COMPTE'
    return {
        'page': page,
        'section': lecteur.section,
        'immeuble': lecteur.immeuble,
        'lot': lecteur.lot if maille == 'LOT' else None,
        'locataire': lecteur.locataire if maille == 'LOT' else None,
        'date_piece': date,
        'libelle': ' '.join(libelle.split())[:220],
        'colonne': colonne or 'HORS COLONNE',
        'montant': nombre(mot['text']),
        'x1': round(mot['x1'], 1),
        'categorie': categorie,
        'maille': maille,
        'motif': motif,
        'reimpression': False,
    }


def main():
    if len(sys.argv) < 3:
        sys.stderr.write('usage : crg_integration_phase4.py <pdf> <plages.json>\n')
        return 2
    with open(sys.argv[2], encoding='utf-8') as fh:
        plages = json.load(fh)
    sortie = []
    # ⚠️ UNE PIÈCE, UNE LECTURE (INTEG-PERF-01). Le PDF pèse 587 Mo : on l'ouvre une fois et
    #    on ne relit jamais une page pour un second CRG.
    with pdfplumber.open(sys.argv[1]) as pdf:
        for p in plages:
            lecteur = Lecteur()
            mouvements, anomalies, vues = [], [], {}
            for n in range(int(p['debut']), int(p['fin']) + 1):
                page = pdf.pages[n - 1]
                mvt, ano = lire(page, n, lecteur)
                # ⚠️ UNE PAGE RÉIMPRIMÉE À L'IDENTIQUE DANS LE MÊME CRG N'EST PAS UN SECOND
                #    ÉVÉNEMENT. Trois CRG de ce dépôt (compte 1105403390, une indivision)
                #    contiennent leurs propres pages DEUX FOIS, caractère pour caractère : les
                #    compter doublerait l'argent de ce compte. La démonstration est ici la plus
                #    forte possible — le MÊME TEXTE, dans le MÊME CRG — et ce n'est jamais un
                #    dédoublonnage sur les montants : on ne compare aucune somme.
                empreinte = hashlib.md5(
                    re.sub(r'\s+', '', page.extract_text() or '').encode()).hexdigest()
                reimpression = empreinte in vues and len(empreinte) and (page.extract_text() or '')
                if reimpression:
                    for m in mvt:
                        m['reimpression'] = True
                        m['motif'] = ('Page réimprimée à l’identique de la page %d du même '
                                      'CRG : lue et conservée, JAMAIS additionnée. '
                                      % vues[empreinte]) + m['motif']
                else:
                    vues[empreinte] = n
                mouvements.extend(mvt)
                anomalies.extend(ano)
            sortie.append({'id': p['id'], 'mouvements': mouvements, 'anomalies': anomalies})
    sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
