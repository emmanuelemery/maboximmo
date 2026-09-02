#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
crg_ics_core.py — LE MOTEUR DE LECTURE DE L'ÉDITEUR **ICS**, ET SES VARIANTES D'AGENCE.

    LOCA IMMO LYON  → variante `lyon`        (parser_version geometric-1.0)
    EMERY IMMO RIOM → variante `emery_immo`  (parser_version emery-1.0)

⚠️ UNE AGENCE N'EST PAS UN FORMAT. Le parc n'utilise que DEUX logiciels : **ICS** (LYON et
   EMERY IMMO) et **SPI** (VIENNE, et CHAPONOST le jour où ses CRG existeront). Mesuré sur les
   documents : LYON et EMERY impriment le même gabarit — « RECAPITULATIF DES OPERATIONS »,
   « SITUATION DES LOCATAIRES », « COMPTE PERSONNEL », « Immeuble : » ; VIENNE n'en imprime
   aucun. Ce fichier est le moteur du gabarit ICS ; il n'a rien à voir avec SPI, avec lequel
   il ne partage AUCUNE fonction.

⚠️ CE FICHIER EXISTE PARCE QU'IL Y EN AVAIT DEUX. `parse_crg_geo.py` et `parse_crg_emery.py`
   portaient EXACTEMENT les douze mêmes fonctions, aucune propre à l'un seul, et 96 lignes sur
   2 054 les séparaient : 95 % de code forké. Une correction du fonctionnement commun devait
   être faite deux fois — ou l'était une seule, et les deux lecteurs divergeaient un peu plus.

⚠️ LA FACTORISATION N'EFFACE AUCUNE RÈGLE CERTIFIÉE. Les quatre divergences réelles ont été
   inventoriées une par une et sont devenues des VARIANTES DÉCLARÉES, visibles dans
   `VARIANTES` ci-dessous, chacune gardant le commentaire qui porte son incident d'origine :

     `ligne_nue`   ce qu'est une ligne de montants SANS libellé.
                   LYON  : un dégât du filigrane « Powered by SCI » → on la recolle au mois
                           précédent. LYON imprime, lui, une vraie ligne « Totaux ».
                   EMERY : le TOTAL DU LOT — ce format n'imprime aucune ligne « Totaux ».
                   Les deux règles sont vraies, chacune sur son gabarit ; garder les deux
                   attribuait 2,00 € au mois de mars de trois locataires.

     `total_mois`  d'où vient le total d'une ligne mensuelle.
                   LYON  : il est IMPRIMÉ — le recomposer l'a fait passer de 0 à 326 écarts.
                   EMERY : il n'est pas imprimé, il EST la somme de ses colonnes —
                           3 × (417,58 + 61,00) = 1 435,74, exactement l'agrégat du lot.

     `parser_version`  l'identité du lecteur, inscrite dans `crg_extractions` pour 517
                   extractions déjà certifiées : elle ne bouge pas.

   La quatrième — la récupération du premier mois resté collé à l'intitulé du lot — s'est
   révélée SANS EFFET sur LYON (ses interlignes sont plus larges, la fusion ne s'y produit
   jamais) : elle est donc COMMUNE, et c'est démontré par le gel des sorties, pas supposé.

POURQUOI CE PARSEUR EXISTE
--------------------------
Le précédent lisait le PDF en texte plat, puis devinait la colonne d'un montant d'après sa
position dans la phrase. Sur un tableau dont les libellés se replient, un montant part d'un
cran et change de colonne sans que rien ne le signale. Mesuré sur le lot 0054 du CRG EVEREST
T2 2026 : « réglé » valait 2 857,77 € au lieu de 2 771,98, et l'impayé 0,00 au lieu de 85,79.
Un impayé réel affiché à zéro — c'est le contentieux qui se trompe.

Ici, aucune colonne n'est déduite d'un ordre de lecture. Chaque nombre est rangé d'après son
ABSCISSE, et l'on sait pour chacun : page, x, y, colonne, bloc, lot, locataire.

COMMENT LES COLONNES SONT TROUVÉES
----------------------------------
Pas par les en-têtes. Mesuré : le titre « Divers » finit à x=514,8 alors que ses valeurs sont
alignées à x=640,8 — 126 points plus loin. Se fier au titre déplacerait la colonne entière.

Les colonnes sont donc apprises SUR LES DONNÉES. Tous les nombres du tableau des locataires
sont relevés, leurs bords droits regroupés en grappes, et ces grappes nommées **depuis la
droite** dans l'ordre de l'en-tête : Impayés, Réglés, Total, Divers, Provisions, Taxes,
Loyers. Une grappe surnuméraire à gauche est le solde antérieur, que l'en-tête ne nomme pas.

Le tableau se calibre ainsi page par page : une mise en page qui bouge d'un trimestre à
l'autre est suivie sans intervention.

CE QUE LES EXPRESSIONS RÉGULIÈRES FONT ENCORE — ET CE QU'ELLES NE FONT PLUS
--------------------------------------------------------------------------
Elles reconnaissent : compte personnel, code immeuble, numéro de lot, nom de locataire,
dates, titres de section. Elles ne décident plus JAMAIS de la colonne d'un montant.

Usage :  python parse_crg_geo.py <fichier.pdf>   → JSON sur la sortie standard
"""

import sys, json, re, unicodedata

# ⚠️ LE TRIMESTRE EST LE MÊME CONTRAT POUR LES TROIS LECTEURS, donc il vit dans un
#    seul fichier. Recopié trois fois, il aurait dérivé trois fois.
from crg_periode import normaliser_periode

try:
    import pdfplumber
except ImportError:
    print(json.dumps({'erreur': 'pdfplumber absent'}), file=sys.stdout)
    sys.exit(1)

TOL_LIGNE   = 2.6      # points : deux mots sur la même ligne visuelle
TOL_COLONNE = 4.0      # points : deux nombres dans la même colonne

RE_COMPTE    = re.compile(r'COMPTE\s+PERSONNEL\s+(\d{6,10})', re.I)
# ⚠️ LE TRIMESTRE S'ÉCRIT DE DEUX FAÇONS DANS LE STOCK. « - 2e Trimestre 2026 - » entre
#    tirets, mais aussi « 3ème TRIM 2025 » sans tiret et abrégé. N'attendre que la
#    première forme laissait quatre pièces du compte 02300000 sans période lue, et le
#    lecteur se rabattait alors sur une date libre — celle du report.
# ⚠️ « ex » PEUT S'INTERCALER ENTRE LE TRIMESTRE ET SON ANNÉE. GROUPE SIR OYONNAX
#    n'imprime que « - Compte de Gestion 2e Trimestre ex 2026 - » : sans ce « ex » optionnel,
#    le trimestre n'était pas reconnu et le document retombait sur une date libre.
RE_TRIMESTRE = re.compile(r'-\s*(\d)\s*(?:er|e|ème)?\s+Trimestre\s+(?:ex\s+)?(\d{4})\s*-', re.I)
RE_TRIM_ABR  = re.compile(r'(\d)\s*(?:er|e|ème|eme)?\s*TRIM\.?\s+(\d{4})', re.I)
# ⚠️ LE DOCUMENT NOMME PARFOIS SON TRIMESTRE DANS SON PROPRE TITRE, ET NULLE PART AILLEURS.
#    GROUPE SIR OYONNAX n'imprime que « - Compte de Gestion 2e Trimestre ex 2026 - » : le chiffre
#    ne suit pas le tiret, donc RE_TRIMESTRE ne le voit pas, et la piece retombait sur une date
#    libre en perdant son trimestre. On vise ce libelle precisement, plutot que d'elargir
#    RE_TRIMESTRE — un motif large attraperait « report du 1er trimestre 2026 » dans le corps.
RE_TRIM_GESTION = re.compile(r'Compte\s+de\s+Gestion\s+(\d)\s*(?:er|e|ème|eme)?\s+Trimestre'
                             r'\s+(?:ex\s+)?(\d{4})', re.I)
# Et une troisieme forme, compacte : « 1T2025 ». Bornee pour ne pas mordre dans un
# numero de compte ou une reference : un seul chiffre, un T, une annee en 20xx.
RE_TRIM_CPT  = re.compile(r'(?<![0-9])([1-4])T(20[0-9]{2})(?![0-9])')
RE_DATE      = re.compile(r'le\s+(\d{2})/(\d{2})/(\d{4})')          # date d'ÉDITION
RE_ARRETE    = re.compile(r'au\s+(\d{2})[./](\d{2})[./](\d{4})', re.I)  # date d'ARRÊTÉ
# La date d'arrêté, telle que le document la nomme lui-même — jamais celle du report.
RE_ARRETE_GESTION = re.compile(r'Compte\s+de\s+Gestion\s+au\s+(\d{2})[./](\d{2})[./](\d{4})', re.I)
# ⚠️ UN CRG N'EST PAS TOUJOURS UN TRIMESTRE ENTIER. Quand un mandat entre ou sort en cours
#    de trimestre, l'en-tete imprime sa periode reelle : « CRG du 13.05.26 au 30.06.26 » ou
#    « - CRG 13.05.26 AU 30.06.26 - ». Deux pieces du perimetre GROUPE SIR sont dans ce cas ;
#    sans ce motif elles ne gardaient que leur date de cloture et perdaient leur debut.
RE_CRG_PERIODE = re.compile(r'\bCRG\s+(?:du\s+)?(\d{2})\.(\d{2})\.(\d{2})\s+AU\s+'
                            r'(\d{2})\.(\d{2})\.(\d{2})', re.I)
RE_IMMEUBLE  = re.compile(r'Immeuble\s*:\s*(\d{6,10})')
RE_LOT       = re.compile(r'^Lot\s+(\w+)\s*(.*)$', re.I)
RE_PERIODE   = re.compile(r'Du\s+(\d{2})\.(\d{2})\.(\d{2,4})\s+Au\s+(\d{2})\.(\d{2})\.(\d{2,4})')
# ⚠️ UNE CORRECTION N'A PAS DE DATE DE FIN. « Du 12.01.26 Au   Remb LOYER TROP VERSE -891,00 »,
# « Du 12.01.26 Au   ERREUR ECRITURE 1782,00 » : la régie corrige une écriture passée, et la
# colonne « Au » reste vide. Le motif fermé ne les reconnaissait pas et elles tombaient dans le
# vide — ni période, ni nom de locataire, ni rien. Sur OSTY LENA, six lignes de correction se
# compensant à +891 € manquaient : 3 225 € lus contre 4 116 € imprimés au bas de son compte.
# ⚠️ ET LE GARDE-FOU DOIT VISER UNE DATE, PAS UN CHIFFRE. « Au(?!\s*\d) » rejetait
# « Du 12.01.26 Au   891,00 … » — une correction dont le montant suit immédiatement la colonne
# vide. Deux lignes sur six manquaient encore. On n'écarte donc que ce qui ressemble à une date.
RE_PERIODE_OUVERTE = re.compile(r'Du\s+(\d{2})\.(\d{2})\.(\d{2,4})\s+Au(?!\s*\d{2}\.\d{2}\.)')
RE_NOMBRE    = re.compile(r'^-?\d{1,3}(?:[   ]?\d{3})*(?:[.,]\d{1,2})?$')
RE_CP_VILLE  = re.compile(r'^(\d{5})\s+(.+)$')

COLONNES = ['loyers', 'taxes', 'provisions', 'divers', 'total', 'regles', 'impayes']

# ⚠️ LA VARIANTE NE DÉCRIT QUE CE QUE LES DOCUMENTS FONT DIFFÉREMMENT — jamais une préférence.
#    Chaque clé porte un incident mesuré, rappelé au point d'emploi. Une variante ne s'ajoute
#    pas parce qu'une agence est différente : elle s'ajoute quand son gabarit l'est.
VARIANTES = {
    'lyon': {
        'parser_version': 'geometric-1.0',
        # LYON imprime une vraie ligne « Totaux » par lot. Une ligne de montants NUE y est donc
        # un dégât du filigrane « Powered by SCI », rendu à l'envers en diagonale.
        'ligne_nue': 'filigrane',
        # …et ses totaux mensuels SONT imprimés : les recomposer l'a fait passer à 326 écarts.
        'total_mois': 'imprime',
    },
    'emery_immo': {
        'parser_version': 'emery-1.0',
        # EMERY IMMO n'imprime AUCUNE ligne « Totaux » : la ligne de montants nue EST le total
        # du lot. Chercher le mot « Totaux » laissait 160 lots sur 385 sans contrôle.
        'ligne_nue': 'total_du_lot',
        # …et aucun total par ligne mensuelle : il est la somme de ses colonnes.
        'total_mois': 'recompose',
    },
}


def nombre(txt):
    """Le texte est-il un montant, et lequel ?  Rend None si ce n'en est pas un."""
    t = txt.strip().replace(' ', '').replace(' ', '')
    if not RE_NOMBRE.match(t):
        return None
    try:
        return float(t.replace(',', '.'))
    except ValueError:
        return None


def sans_accent(s):
    return ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')


def lignes_de(page):
    """Les mots d'une page, regroupés en lignes visuelles. Le texte pivoté est écarté :
       le filigrane « Powered by ICS » remonte à l'envers et polluerait les libellés."""
    mots = [m for m in page.extract_words(extra_attrs=['upright']) if m.get('upright', True)]
    mots.sort(key=lambda m: (m['top'], m['x0']))
    lignes, courante, y = [], [], None
    for m in mots:
        if y is None or abs(m['top'] - y) <= TOL_LIGNE:
            courante.append(m)
            y = m['top'] if y is None else y
        else:
            lignes.append(sorted(courante, key=lambda w: w['x0']))
            courante, y = [m], m['top']
    if courante:
        lignes.append(sorted(courante, key=lambda w: w['x0']))
    return [{'y': l[0]['top'], 'mots': l, 'texte': ' '.join(w['text'] for w in l)} for l in lignes]


def bandes_entete(ligne_entete):
    """Les colonnes, définies par les BANDES de l'en-tête.

       Chaque titre ouvre sa colonne à son bord GAUCHE ; la colonne court jusqu'au bord gauche
       du titre suivant, la dernière jusqu'au bord de la page. Ce qui tombe à gauche de
       « Loyers » est le solde antérieur, que l'en-tête ne nomme pas.

       Le bord DROIT des titres ne sert à rien : mesuré, « Divers » finit à x=514,8 quand ses
       valeurs sont alignées à x=640,8. C'est le bord gauche du titre SUIVANT qui borne la
       colonne, pas la fin du mot.

       Une première version nommait les colonnes en comptant les grappes de valeurs depuis la
       droite. Elle marchait sur une page bien remplie et se décalait dès qu'une page en
       présentait un jeu différent : sur l'immeuble 01100146 de SMH, 34 lots retombaient à
       zéro. Les bandes, elles, ne dépendent d'aucun comptage.
    """
    titres = {'loyers': 'loyer', 'taxes': 'taxe', 'provisions': 'provision',
              'divers': 'divers', 'total': 'total', 'regles': 'regl', 'impayes': 'impay'}
    trouves = []
    for m in ligne_entete['mots']:
        t = sans_accent(m['text']).lower()
        for cle, amorce in titres.items():
            if t.startswith(amorce) and cle not in [c for c, _ in trouves]:
                trouves.append((cle, m['x0']))
                break
    trouves.sort(key=lambda c: c[1])
    if not trouves:
        return []
    bandes = []
    for i, (cle, x0) in enumerate(trouves):
        fin_bande = trouves[i + 1][1] if i + 1 < len(trouves) else 1e9
        bandes.append((cle, x0, fin_bande))
    # tout ce qui précède la première colonne nommée
    bandes.insert(0, ('solde_anterieur', -1e9, trouves[0][1]))
    return bandes


def valeurs(ligne, bandes):
    """Range les nombres d'une ligne dans leur colonne, d'après leur bord droit.

       Un montant est aligné à droite dans sa colonne : c'est donc x1 qui le situe, jamais
       l'ordre dans lequel il a été lu. Une colonne vide n'est pas imprimée — et cela ne
       décale rien, puisque rien ne dépend du rang."""
    out = {}
    for m in ligne['mots']:
        v = nombre(m['text'])
        if v is None:
            continue
        for cle, x0, x1 in bandes:
            if x0 <= m['x1'] < x1:
                out[cle] = {'v': v, 'x': m['x1'], 'y': m['top']}
                break
    return out


# ═══════════════════════════════════════════════════════════════════════════════════════
# LE RÉCAPITULATIF DES OPÉRATIONS — lu par bandes, comme les lots
# ═══════════════════════════════════════════════════════════════════════════════════════
#
# La première version prenait le nombre le plus À GAUCHE pour le montant et le plus à DROITE
# pour la TVA. Aucune notion de colonne. Résultat mesuré sur EVEREST T2 : « Honoraires H.T.
# Avril 2026 » lu à 2 026,00 € — l'ANNÉE du libellé — et le vrai montant, 47,63 €, rangé en
# TVA. Sur les cinq immeubles : 76 035,92 € lus contre 6 698,05 € imprimés.
#
# L'en-tête nomme pourtant ses colonnes :
#
#     RECAPITULATIF DES OPERATIONS    Débits   Crédits   Dont T.V.A.   Locatif   Déductible
#                                      @548     @601        @647        @710       @759
#
# Même règle que pour les lots : le titre ouvre sa colonne à son bord GAUCHE, la ferme au bord
# gauche du suivant, et chaque nombre est rangé d'après son bord DROIT (montants alignés à
# droite). Ce qui tombe AVANT la première colonne n'est pas un montant : c'est du libellé,
# année et référence d'avis comprises.
#
# Vérifié sur 15 documents et 165 immeubles : 149 blocs exploitables sur 149 se réconcilient
# AU CENTIME avec les « Totaux Généraux » imprimés. Les 16 autres n'ont aucun récapitulatif.

COLS_RECAP = [('debit', 'debit'), ('credit', 'credit'), ('tva', 'dont'),
              ('locatif', 'locatif'), ('deductible', 'deductible')]


def bandes_recap(ligne_entete):
    """Bandes de colonnes du récapitulatif, tirées de son en-tête."""
    trouves = []
    for m in sorted(ligne_entete['mots'], key=lambda w: w['x0']):
        t = sans_accent(m['text']).lower().strip('.')
        for cle, amorce in COLS_RECAP:
            if t.startswith(amorce) and cle not in [c for c, _ in trouves]:
                trouves.append((cle, m['x0']))
                break
    trouves.sort(key=lambda c: c[1])
    b = []
    for i, (cle, x0) in enumerate(trouves):
        b.append((cle, x0, trouves[i + 1][1] if i + 1 < len(trouves) else 1e9))
    return b


# Un total, un sous-total, un report ou un solde n'est JAMAIS une écriture : il sert à
# réconcilier, pas à comptabiliser. L'importer le compterait deux fois.
#
# LE MOTIF SE CHERCHE PARTOUT DANS LE LIBELLÉ, PAS SEULEMENT EN TÊTE. Sur l'immeuble
# 01330224, la ligne imprimée est « SCI Solde créditeur en Euros au 30.06.2026 » : le nom du
# propriétaire déborde dans la colonne des libellés et précède le mot « Solde ». Un test en
# tête laissait passer 2 531,17 € de faux crédit, et l'immeuble ne se réconciliait plus.
# ⚠️ « SOLDE CREDITEUR » CHERCHÉ N'IMPORTE OÙ RAMASSAIT DE VRAIES ÉCRITURES.
# Le motif avait été posé pour « SCI Solde créditeur en Euros au 30.06.2026 », où le nom du
# propriétaire précède le mot « Solde ». Mais il attrapait aussi
# « OSTY Rembourst solde créditeur 12.01.26 891,00 » — un remboursement bien réel, rangé
# parmi les totaux et donc absent des débits : 691,16 € lus contre 1 582,16 € imprimés, et
# 98 immeubles d'EMERY IMMO en écart. On exige donc « EN EUROS », qui n'apparaît que sur la
# vraie ligne de solde. Un motif large ne protège pas mieux : il se trompe simplement plus souvent.
MOTIFS_TOTAL = ('TOTAUX GENERAUX', 'TOTAL GENERAL', 'TOTAL DES REGLEMENTS',
                'SOLDE CREDITEUR EN EUROS', 'SOLDE DEBITEUR EN EUROS', 'SOLDE EN EUROS',
                'SOUS TOTAL', 'SOUS-TOTAL', 'S/TOTAL', 'REPORT A NOUVEAU', 'RECAPITULATIF')


def est_total(libelle):
    u = ' '.join(sans_accent(libelle).upper().split())
    if any(m in u for m in MOTIFS_TOTAL):
        return True
    return u.startswith(('TOTAL', 'TOTAUX', 'SOLDE', 'REPORT', 'RECAPITULATIF'))


def est_parasite(libelle):
    """Un libellé sans la moindre lettre n'est pas une écriture : c'est un séparateur ou un
       artefact de découpe. Vu sur pièce : des lignes « / » portant 30,00 € en Déductible."""
    return not any(c.isalpha() for c in libelle)


RE_SOLDE_IMM = re.compile(r'^SOLDE\s+AU\s+[\d.\/]+\s+(.+)$')
RE_SOLDE_FIN = re.compile(r'^SOLDE\s+(DEBITEUR|CREDITEUR)\s+EN\s+EUROS')
RE_REPORT    = re.compile(r'^REPORT\s+AU\s+([\d.\/]+)')


# Le pied de page légal, répété sur CHAQUE page. Ses nombres — le capital social, le montant
# de la garantie financière, le numéro de carte professionnelle, le code postal du garant —
# tombent dans la bande des débits et fabriquaient 169 439,00 € d'écritures imaginaires sur un
# récapitulatif de mandat qui s'étalait sur deux pages. Mesuré.
MENTIONS_PIED = ('CAPITAL DE', 'CARTE(S) PROFESSIONNELLE', 'CARTE PROFESSIONNELLE',
                 'SIRET :', 'APE :', 'TVA : FR', 'DELIVREE PAR', 'DELIVREE(S) PAR')

# ⚠️ « GARANTIE DE » CHERCHÉ EN SOUS-CHAÎNE AVALAIT DE VRAIES CHARGES.
# Le motif visait le pied de page « Garantie de 880 000 EUROS Délivrée par GALIAN ». Mais
# « Honoraires garantie des loyers » le contient aussi — « GARANTIE DE » est un préfixe de
# « GARANTIE DES ». Trois lignes par immeuble disparaissaient ainsi, sans trace : 725,49 €
# sur « 2 STADE », et 97 immeubles d'EMERY IMMO en écart. On exige donc le montant qui suit,
# que seul le pied de page porte. Deuxième fois qu'un motif large se trompe dans ce fichier ;
# la leçon est la même qu'avec « SOLDE CREDITEUR ».
# ⚠️ ET BORNÉE. La première écriture, `GARANTIE\s+DE\s+[\d\s]+\s*EUROS`, faisait se
# chevaucher `[\d\s]+` et `\s*` : sur un libellé long sans « EUROS », le moteur d'expressions
# régulières essaie toutes les découpes possibles et ne finit jamais. Huit CRG lyonnais sont
# partis en délai d'attente de trois minutes. Un quantificateur non borné derrière un autre
# qui accepte les mêmes caractères, c'est un piège à retour arrière — on borne, toujours.
RE_PIED_GARANTIE = re.compile(r'GARANTIE DE \d[\d ]{0,15}EUROS')


def est_pied_de_page(libelle):
    u = ' '.join(sans_accent(libelle).upper().split())
    if RE_PIED_GARANTIE.search(u):
        return True
    return any(m in u for m in MENTIONS_PIED)


def lire_mandat(pages_lignes):
    """LE RÉCAPITULATIF DU MANDAT — page 1 du CRG.

       C'est la page que personne ne lisait, et c'est la plus utile : la régie y calcule
       elle-même LA TRÉSORERIE NETTE PAR IMMEUBLE.

           Solde au 30.06.2026 LES BALCONS DE LEA        921.34
           Solde au 30.06.2026 35 REPUBLIQUE      55.00
           ...
           Totaux Généraux                     9924.37   7670.10   1067.55
           Solde débiteur en Euros au 30.06.2026  2254.27

       Notre propre calcul (encaissé + crédits − débits) doit y retomber AU CENTIME. Vérifié
       5 immeubles sur 5 sur EVEREST T2. Si les deux divergent, aucune rentabilité ne doit
       s'afficher : c'est le contrôle le plus fort qu'on puisse poser sur ces chiffres.

       ⚠️ Cette page porte aussi des mouvements de niveau MANDAT qui ne sont PAS des charges
          d'immeuble : remboursement de prêt (du FINANCEMENT), acompte versé au bailleur (une
          DISTRIBUTION, c'est-à-dire le résultat lui-même), report du trimestre précédent. Les
          confondre avec des charges écraserait la rentabilité sans raison économique — sur
          EVEREST T2, 6 405,27 € de prêt et 3 000,00 € d'acompte.

       ⚠️ ON S'ARRÊTE AU SOLDE FINAL. Après lui viennent les mentions légales du pied de page,
          dont les nombres — « Capital de 12 000 EUROS », « Garantie de 880 000 EUROS » —
          tombent par accident dans la bande des débits et fabriqueraient 169 439 € d'écritures
          imaginaires.

       Trois colonnes ici (Débits, Crédits, Dont T.V.A.), là où les pages d'immeuble en ont
       cinq : les bandes se lisent de la même façon, elles sont simplement moins nombreuses.
    """
    # ⚠️ LE RÉCAPITULATIF DU MANDAT DÉBORDE SUR LES PAGES SUIVANTES dès qu'un mandat
    #    compte beaucoup d'immeubles. Mesuré : un CRG de 18 immeubles n'en listait que 17 en
    #    page 1, et ses « Totaux Généraux » comme son solde final tombaient page 2 — donc
    #    jamais lus, donc aucune réconciliation possible. On enchaîne les pages jusqu'au
    #    solde final, et l'en-tête de colonnes de la première fait autorité pour toutes.
    #    `pages_lignes` : liste de (no_page, lignes).
    lignes, no_page = None, None
    i_rec, bandes = None, None
    entete_regie = set()
    suite = []
    for np, lg in pages_lignes:
        if i_rec is None:
            for i, l in enumerate(lg):
                if 'CAPITULATIF' in sans_accent(l['texte']).upper():
                    i_rec, no_page, bandes = i, np, bandes_recap(l)
                    break
            if i_rec is None:
                continue
            if not bandes:
                return None
            # ⚠️ L'EN-TÊTE DE LA RÉGIE SE RÉPÈTE EN HAUT DE CHAQUE PAGE.
            #    « LOCA IMMO / 19 BOULEVARD YVES FARGE / 69007 LYON » — et ce « 19 » tombe
            #    dans la bande des crédits, fabriquant une écriture de 19,00 € par page de
            #    continuation. Mesuré : +19,00 € sur un mandat de deux pages, +57,00 € sur un
            #    de quatre. Plutôt que de coder l'adresse de la régie en dur, on relève ce qui
            #    précède le récapitulatif sur la première page : c'est exactement l'en-tête,
            #    et il sera identique sur les suivantes.
            entete_regie = {' '.join(sans_accent(x['texte']).upper().split()) for x in lg[:i_rec]}
            entete_regie.discard('')
            suite.extend((np, l) for l in lg[i_rec + 1:])
        else:
            suite.extend((np, l) for l in lg
                         if ' '.join(sans_accent(l['texte']).upper().split()) not in entete_regie)
    if i_rec is None or not bandes:
        return None
    x_premiere = bandes[0][1]

    m = {'report': None, 'operations': [], 'soldes_immeubles': [],
         'totaux': None, 'solde_final': None,
         'colonnes': [(c, round(a, 1), (round(b, 1) if b < 1e8 else None)) for c, a, b in bandes]}
    entete = None

    for np_l, l in suite:
        vals, mots_libelle = {}, []
        for mot in l['mots']:
            v = nombre(mot['text'])
            if v is None or mot['x1'] < x_premiere:
                mots_libelle.append(mot['text'])
                continue
            for cle, a, b in bandes:
                if a <= mot['x1'] < b:
                    vals[cle] = v
                    break
            else:
                mots_libelle.append(mot['text'])
        libelle = ' '.join(mots_libelle).strip()
        if not libelle:
            continue

        if not vals:                       # une ligne sans montant qualifie les suivantes
            if len(libelle) > 3 and not libelle.startswith('-') and any(c.isalpha() for c in libelle):
                entete = libelle
            continue

        if est_pied_de_page(libelle):
            continue
        u = ' '.join(sans_accent(libelle).upper().split())
        deb, cre = vals.get('debit', 0.0), vals.get('credit', 0.0)

        if RE_SOLDE_FIN.match(u):
            m['solde_final'] = {'libelle': libelle, 'sens': 'debiteur' if deb else 'crediteur',
                                'montant': deb or cre, 'page': np_l}
            break                          # ⚠️ tout ce qui suit est le pied de page

        if 'TOTAUX GENERAUX' in u or 'TOTAL GENERAL' in u:
            m['totaux'] = {'debit': deb, 'credit': cre, 'tva': vals.get('tva', 0.0)}
            continue

        mr = RE_REPORT.match(u)
        if mr:
            # ⚠️ LE REPORT AUSSI PORTE SA PAGE. C'etait le dernier element du document
            #    a n'en avoir aucune : 30 pieces LYON et 30 EMERY affichaient un report
            #    dont l'ecran ne savait pas ou l'ouvrir.
            m['report'] = {'date': mr.group(1), 'montant': cre - deb,
                           'page': np_l,
                           'sens': 'crediteur' if cre else 'debiteur'}
            continue

        mi = RE_SOLDE_IMM.match(u)
        if mi:
            m['soldes_immeubles'].append({
                'nom': libelle.split(None, 3)[-1].strip() if len(libelle.split()) > 3 else libelle,
                'nom_normalise': mi.group(1).strip(),
                'solde': round(cre - deb, 2),          # + créditeur (dû au bailleur), − débiteur
                'page': np_l})
            continue

        m['operations'].append({
            'libelle': libelle, 'entete': entete, 'categorie': categorie(entete or libelle),
            'debit': deb, 'credit': cre, 'tva': vals.get('tva', 0.0),
            'niveau': 'mandat', 'page': np_l, 'y': l['y']})

    # ── LA RÉCONCILIATION DE LA PAGE ─────────────────────────────────────────────
    if m['totaux']:
        sd = sum(o['debit'] for o in m['operations']) \
             + sum(-s['solde'] for s in m['soldes_immeubles'] if s['solde'] < 0) \
             + (abs(m['report']['montant']) if m['report'] and m['report']['sens'] == 'debiteur' else 0.0)
        sc = sum(o['credit'] for o in m['operations']) \
             + sum(s['solde'] for s in m['soldes_immeubles'] if s['solde'] > 0) \
             + (m['report']['montant'] if m['report'] and m['report']['sens'] == 'crediteur' else 0.0)
        m['reconciliation'] = {
            'etat': 'reconcilie' if abs(sd - m['totaux']['debit']) < 0.005
                                 and abs(sc - m['totaux']['credit']) < 0.005 else 'ecart',
            'debits_lus': round(sd, 2), 'debits_imprimes': m['totaux']['debit'],
            'credits_lus': round(sc, 2), 'credits_imprimes': m['totaux']['credit'],
            'ecart_debits': round(sd - m['totaux']['debit'], 2),
            'ecart_credits': round(sc - m['totaux']['credit'], 2)}
    else:
        m['reconciliation'] = {'etat': 'sans_total_imprime'}
    return m


def categorie(libelle):
    l = sans_accent(libelle).lower()
    for cle, cat in [('taxe fonciere', 'taxe_fonciere'), ('honoraire', 'honoraires_ht'),
                     ('tva', 'tva_honoraires'), ('assurance', 'assurance'), ('syndic', 'syndic'),
                     ('huissier', 'huissier'), ('avocat', 'avocat'), ('travaux', 'travaux'),
                     ('depot de garantie', 'depot_garantie'), ('loyer', 'loyer')]:
        if cle in l:
            return cat
    return 'autre'


def parse(chemin, variante='lyon'):
    """Lit un CRG du gabarit ICS. `variante` nomme l'agence, et ne change QUE ce que son
       document imprime différemment — voir `VARIANTES`."""
    if variante not in VARIANTES:
        raise ValueError('VARIANTE ICS INCONNUE : %r — on ne devine pas un gabarit.' % variante)
    var = VARIANTES[variante]
    doc = {'meta': {}, 'immeubles': [], 'parser_version': var['parser_version']}
    imm_par_code = {}
    # UN LOT PEUT ENJAMBER UNE PAGE. Son bloc commence en bas d'une page et sa ligne
    # « Totaux » figure en haut de la suivante. Repartir de zéro à chaque page laissait ces
    # lots à 0 : mesuré, 3 lots sur 26 de l'immeuble 01040117, soit 3 994,35 € de règlements
    # perdus sur un seul CRG. Le lot courant traverse donc, tant que l'immeuble ne change pas.
    lot_courant = None
    code_imm_precedent = None
    pages_mandat = []          # les pages du récapitulatif, avant le premier immeuble

    with pdfplumber.open(chemin) as pdf:
        doc['meta']['nb_pages'] = len(pdf.pages)

        for no_page, page in enumerate(pdf.pages, start=1):
            lignes = lignes_de(page)
            textes = [l['texte'] for l in lignes]

            # ── En-tête du document, page 1 ────────────────────────────────
            if no_page == 1:
                joint = ' \n '.join(textes)
                # ⚠️ LA PROVENANCE DE L'EN-TÊTE SE STOCKE, ELLE NE SE DEVINE PAS. Le
                #    bloc propriétaire / compte / période est lu page 1 par construction,
                #    mais tant que la page n'est pas écrite aucun écran ne peut ouvrir le
                #    PDF au bon endroit sans réimplémenter cette hypothèse de son côté.
                doc['meta']['page'] = no_page
                # La periode propre du CRG, quand il n'est ni trimestriel ni mensuel.
                mp = RE_CRG_PERIODE.search(joint)
                if mp:
                    doc['meta']['crg_periode_debut'] = '%s/%s/20%s' % (mp.group(1), mp.group(2), mp.group(3))
                    doc['meta']['crg_periode_fin'] = '%s/%s/20%s' % (mp.group(4), mp.group(5), mp.group(6))
                m = RE_COMPTE.search(joint)
                if m:
                    doc['meta']['compte'] = m.group(1)
                # LA PÉRIODE. Deux formulations coexistent dans le stock :
                #   « - 2e Trimestre 2026 - »                          → explicite
                #   « - Compte de Gestion au 30.06.2026 - Lyon, le 24/07/2026 »
                # Dans la seconde, le trimestre se déduit de la date d'ARRÊTÉ (30.06 → T2).
                # ⚠️ Surtout pas de la date qui suit « le » : c'est la date d'ÉDITION, souvent
                # postée le mois suivant. La confondre fabriquerait un T3 fantôme qui masquerait
                # le vrai T2 dans tous les tableaux de bord.
                m = (RE_TRIMESTRE.search(joint) or RE_TRIM_GESTION.search(joint)
                     or RE_TRIM_ABR.search(joint) or RE_TRIM_CPT.search(joint))
                if m:
                    doc['meta']['trimestre'] = int(m.group(1))
                    doc['meta']['annee'] = int(m.group(2))
                # ⚠️ « Report au 31.03.2026 » N'EST PAS LA DATE D'ARRÊTÉ, C'EST CELLE DU TRIMESTRE
                #    PRÉCÉDENT. `RE_ARRETE` cherchait « au JJ.MM.AAAA » dans TOUT le texte de la
                #    page 1 : sur les CRG qui impriment leur report avant leur solde — quatre
                #    pièces du compte 02300000 — la première occurrence était celle du report, et
                #    `date_arrete` sortait décalée d'un trimestre entier.
                # ⚠️ LA SOURCE LA PLUS SÛRE EST LE TRIMESTRE IMPRIMÉ. Le document écrit lui-même
                #    « - 2e Trimestre 2026 - » : la fin du trimestre s'en déduit sans rien deviner.
                #    Chercher une date « Solde ... au » ne marche pas — des lignes d'opération en
                #    portent aussi (« Solde débiteur Mdt ... au 02.04.2026 ») et on retombe dans le
                #    même travers. On ne se rabat sur une date libre qu'en dernier ressort, et
                #    jamais sur celle qui suit le mot « Report ».
                ma = RE_ARRETE_GESTION.search(joint)
                if ma:
                    doc['meta']['date_arrete'] = '%s/%s/%s' % (ma.group(1), ma.group(2), ma.group(3))
                    # « - Compte de Gestion au 30.06.2026 - » n'imprime pas son trimestre : il se
                    # deduit de la date d'arrete, qui est ici nommee sans ambiguite.
                    if 'trimestre' not in doc['meta']:
                        doc['meta']['trimestre'] = (int(ma.group(2)) + 2) // 3
                        doc['meta']['annee'] = int(ma.group(3))
                        doc['meta']['periode_source'] = 'date_arrete'
                elif doc['meta'].get('trimestre') and doc['meta'].get('annee'):
                    tr, an = int(doc['meta']['trimestre']), int(doc['meta']['annee'])
                    doc['meta']['date_arrete'] = '%02d/%02d/%04d' % (
                        31 if tr in (1, 4) else 30, tr * 3, an)
                    doc['meta']['date_arrete_source'] = 'trimestre imprime'
                else:
                    for cand in RE_ARRETE.finditer(joint):
                        if 'report' in joint[max(0, cand.start() - 24):cand.start()].lower():
                            continue
                        doc['meta']['date_arrete'] = '%s/%s/%s' % (
                            cand.group(1), cand.group(2), cand.group(3))
                        if 'trimestre' not in doc['meta']:
                            doc['meta']['trimestre'] = (int(cand.group(2)) + 2) // 3
                            doc['meta']['annee'] = int(cand.group(3))
                            doc['meta']['periode_source'] = 'date_arrete'
                        break
                me = RE_DATE.search(joint)
                if me:
                    doc['meta']['date_edition'] = '%s/%s/%s' % (me.group(1), me.group(2), me.group(3))
                    doc['meta'].setdefault('date_arrete', doc['meta']['date_edition'])
                for i, t in enumerate(textes):
                    if RE_COMPTE.search(t):
                        suite = [textes[j].strip() for j in range(i + 1, min(i + 9, len(textes)))]
                        # ⚠️ LE NOM DU PROPRIÉTAIRE N'EST PAS « LA LIGNE D'APRÈS ». Sur les
                        #    relevés du 4e trimestre, trois lignes s'intercalent avant lui :
                        #    « 4T2025 », « RELEVE COMPTE GESTION 4e trim 2025 », puis une
                        #    bannière « ******** BONNES FETES DE FIN D'ANNEE ********* ».
                        #    Quatre CRG du périmètre GROUPE SIR portaient ainsi les vœux de
                        #    l'agence comme raison sociale — et le nom de fichier GED avec.
                        #    On écarte donc sur la FORME, pas sur une liste de mots : un
                        #    rappel de période, un titre de relevé, une ligne décorative ou
                        #    une ligne sans trois lettres ne désignent personne.
                        def _est_un_nom(s):
                            u = s.upper()
                            if 'COMPTE' in u or 'GESTION' in u or 'RELEVE' in u:
                                return False
                            if (RE_TRIMESTRE.search(s) or RE_TRIM_ABR.search(s)
                                    or RE_TRIM_CPT.search(s)):
                                return False
                            if sum(c in '*=_-~.' for c in s) >= 3:
                                return False
                            return sum(c.isalpha() for c in s) >= 3
                        suite = [s for s in suite if s and _est_un_nom(s)]
                        if suite:
                            doc['meta']['proprietaire'] = suite[0]
                            for s in suite[1:]:
                                mv = RE_CP_VILLE.match(s)
                                if mv:
                                    doc['meta']['proprietaire_cp'] = mv.group(1)
                                    doc['meta']['proprietaire_ville'] = mv.group(2)
                                    break
                        break

            # ── Quel immeuble cette page décrit-elle ? ─────────────────────
            code_imm, i_imm = None, None
            for i, t in enumerate(textes):
                m = RE_IMMEUBLE.search(t)
                if m:
                    code_imm, i_imm = m.group(1), i
                    break
            # Tant qu'aucun immeuble n'a commencé, la page appartient au récapitulatif
            # du mandat. On les accumule et on ne le lit qu'une fois, au premier immeuble.
            if not code_imm:
                if not doc['immeubles']:
                    pages_mandat.append((no_page, lignes))
                continue
            if pages_mandat and 'mandat' not in doc:
                pages_mandat.append((no_page, lignes))     # le solde final peut être sur
                doc['mandat'] = lire_mandat(pages_mandat)  # la page où l'immeuble commence
                pages_mandat = []

            if code_imm not in imm_par_code:
                nom, adr, cp, ville = '', '', '', ''
                for t in textes[i_imm + 1:i_imm + 6]:
                    t = t.strip()
                    if not t:
                        continue
                    mv = RE_CP_VILLE.match(t)
                    if mv and not cp:
                        cp, ville = mv.group(1), mv.group(2)
                    elif not nom:
                        nom = t
                    elif not adr:
                        adr = t
                imm_par_code[code_imm] = {'code': code_imm, 'nom': nom, 'adresse': adr or nom,
                                          'code_postal': cp, 'ville': ville,
                                          'page_debut': no_page, 'page_fin': no_page,
                                          'lots': [], 'charges': []}
                doc['immeubles'].append(imm_par_code[code_imm])
            imm = imm_par_code[code_imm]
            imm['page_fin'] = no_page
            if code_imm != code_imm_precedent:
                lot_courant = None

            # ── Le tableau des locataires ─────────────────────────────────
            i_entete = None
            for i, t in enumerate(textes):
                if 'Loyers' in t and ('Impay' in t or 'Regl' in sans_accent(t)):
                    i_entete = i
                    break
            # ⚠️ Le récapitulatif se cherche INDÉPENDAMMENT du tableau des locataires.
            # Une page peut ne porter que lui — c'est le cas quand le tableau s'est terminé
            # à la page précédente. La chercher « après l'en-tête » faisait alors ignorer la
            # page entière, et le « TOTAL DES REGLEMENTS LOCATAIRES » avec elle : l'immeuble
            # 01100132 de SMH perdait ses 13 018,35 € de règlements imprimés.
            i_rec = None
            for i, t in enumerate(textes):
                if 'RECAPITULATIF' in sans_accent(t).upper() and (i_entete is None or i > i_entete):
                    i_rec = i
                    break
            i_fin = i_rec if i_rec is not None else len(lignes)

            if i_entete is not None:
                bandes = bandes_entete(lignes[i_entete])
                if code_imm != code_imm_precedent:
                    lot_courant = None

                for l in lignes[i_entete + 1:i_fin]:
                    t = l['texte'].strip()
                    ml = RE_LOT.match(t)
                    if ml:
                        lot_courant = {'numero_lot': ml.group(1), 'type_bien': ml.group(2).strip() or 'Appartement',
                                       'reference': '%s-%s' % (code_imm, ml.group(1)),
                                       'locataire_nom': '', 'page': no_page, 'y': l['y'],
                                       'mois': [], 'solde_anterieur': 0.0,
                                       'loyer_appele': 0.0, 'loyer_mensuel': 0.0,
                                       'total_taxes': 0.0, 'total_provisions': 0.0, 'total_divers': 0.0,
                                       'total_du': 0.0, 'total_regle': 0.0, 'total_impaye': 0.0,
                                       'totaux_lus': False}
                        # ⚠️ LA LIGNE DU LOT PEUT AVOIR AVALÉ SON PREMIER MOIS. Chez EMERY IMMO
                        # les interlignes sont serrés — mesuré : « Lot 0001 Appart. T2 » à
                        # y=119,70 et « Du 01.01.26 Au 31.01.26 417,58 … » à y=122,00, deux
                        # points trois d'écart. Le regroupement des mots en lignes les fond en
                        # une seule, reconnue comme ligne de lot : le premier mois de CHAQUE lot
                        # était perdu. On le récupère ici.
                        # ⚠️ CE TRAITEMENT EST COMMUN AUX DEUX AGENCES, ET C'EST DÉMONTRÉ, PAS
                        # SUPPOSÉ : chez LYON les interlignes sont plus larges, la fusion ne s'y
                        # produit jamais, `RE_PERIODE` ne trouve donc rien sur une ligne de lot,
                        # et le bloc est sans effet. Le gel des sorties LYON avant/après
                        # factorisation le confirme fichier par fichier.
                        mp0 = RE_PERIODE.search(t)
                        # `v` n'est calculé que plus bas dans la boucle : on le fait ici.
                        v = valeurs(l, bandes) if bandes else {}
                        if mp0 and v:
                            m0 = {'du': '20%s-%s-%s' % (mp0.group(3)[-2:], mp0.group(2), mp0.group(1)),
                                  'au': '20%s-%s-%s' % (mp0.group(6)[-2:], mp0.group(5), mp0.group(4)),
                                  'page': no_page, 'y': l['y'], 'colle_a_l_intitule': True}
                            for c in COLONNES:
                                if c in v:
                                    m0[c] = v[c]['v']
                            lot_courant['mois'].append(m0)
                            if 'loyers' in v:
                                lot_courant['loyer_mensuel'] = max(lot_courant['loyer_mensuel'],
                                                                   v['loyers']['v'])
                            # Le type de bien s'arrête avant la période.
                            lot_courant['type_bien'] = (
                                t[:mp0.start()].split(None, 2)[2].strip()
                                if len(t[:mp0.start()].split(None, 2)) > 2 else 'Appartement')
                        imm['lots'].append(lot_courant)
                        continue
                    if lot_courant is None:
                        continue

                    v = valeurs(l, bandes) if bandes else {}

                    # ⚠️ LA BANDE « solde_anterieur » PART DE MOINS L'INFINI — elle ramasse
                    # donc tout chiffre du LIBELLÉ. « SP LYON 7 » : le 7 y tombait, la ligne
                    # passait pour porter un montant, et le nom du locataire n'était jamais
                    # retenu. Seize lots lyonnais restaient anonymes alors que le PDF les nomme.
                    # Pour reconnaître un nom, on ne regarde donc que les VRAIES colonnes.
                    v_colonnes = {k: x for k, x in v.items() if k != 'solde_anterieur'}
                    # nom du locataire : la ligne sans montant en colonne qui suit le lot
                    if not v_colonnes and not lot_courant['locataire_nom'] and t and not RE_PERIODE.search(t):
                        if not t.lower().startswith(('totaux', 'total', 'solde')):
                            lot_courant['locataire_nom'] = t
                        continue

                    mp = RE_PERIODE.search(t)
                    if mp and v:
                        mois = {'du': '20%s-%s-%s' % (mp.group(3)[-2:], mp.group(2), mp.group(1)),
                                'au': '20%s-%s-%s' % (mp.group(6)[-2:], mp.group(5), mp.group(4)),
                                'page': no_page, 'y': l['y']}
                        for c in COLONNES:
                            if c in v:
                                mois[c] = v[c]['v']
                        lot_courant['mois'].append(mois)
                        if 'loyers' in v:
                            lot_courant['loyer_mensuel'] = max(lot_courant['loyer_mensuel'], v['loyers']['v'])
                        continue

                    # ⚠️ LE FILIGRANE COUPE LES LIGNES EN DEUX. « Powered by SCI » est imprimé
                    # en diagonale au milieu de la page ; ses mots (« SCI », « yb », « derewoP »)
                    # s'intercalent entre les colonnes et scindent la ligne. Chez LAAD, le mois
                    # de décembre garde ses loyers et ses charges, et son total de 302,27 € se
                    # retrouve seul sur la ligne suivante. Une ligne de montants SANS libellé
                    # n'existe pas dans ce document : elle appartient à la précédente.
                    # ══ LA SEULE DIVERGENCE OÙ LES DEUX RÈGLES S'EXCLUENT ═══════════════════
                    # Une ligne de montants SANS libellé n'a pas le même sens d'un gabarit à
                    # l'autre, et il n'y a pas de règle unique : chacune est vraie chez elle.
                    # Les garder toutes deux attribuait 2,00 € au mois de mars de trois
                    # locataires ; n'en garder qu'une perdait 160 lots sur 385.
                    if var['ligne_nue'] == 'filigrane':
                        # LYON — LA RÈGLE VISE LE FILIGRANE, PAS LA BRIÈVETÉ. « libellé de moins
                        # de quatre lettres » était trop large : elle absorbait des lignes
                        # réelles et faisait chuter la réconciliation des lots de 385/385 à
                        # 317/385. On ne reconnaît donc que les mots du filigrane lui-même —
                        # « Powered by SCI », imprimé en diagonale et rendu à l'envers.
                        if v and lot_courant['mois'] and not RE_PERIODE.search(t):
                            reste = re.sub(r'[\d\s.,-]', '', t)
                            if reste == '' or reste.upper() in ('SCI', 'YB', 'DEREWOP', 'POWERED'):
                                dernier = lot_courant['mois'][-1]
                                for c in COLONNES:
                                    if c in v and dernier.get(c) in (None, 0.0):
                                        dernier[c] = v[c]['v']
                                continue
                    else:
                        # EMERY IMMO — IL N'IMPRIME AUCUNE LIGNE « Totaux » PAR LOT. Après les
                        # mois vient une ligne de chiffres NUE, sans le moindre intitulé :
                        #     Du 01.01.26 Au 31.01.26   417,58  0,00  61,00
                        #     Du 02…                    417,58  0,00  61,00
                        #     Du 03…                    417,58  0,00  61,00
                        #     1 435,74   1 435,74            ← le total du lot, et les réglés
                        # 3 × (417,58 + 61,00) = 1 435,74 au centime. Le lecteur qui cherche le
                        # mot « Totaux » ne trouvait rien : 160 lots sur 385 restaient sans
                        # référence de contrôle.
                        if (v and lot_courant['mois'] and not lot_courant['totaux_lus']
                                and not RE_PERIODE.search(t)
                                and not re.sub(r'[\d\s.,-]', '', t).strip()):
                            for c, k in (('total', 'total_du'), ('regles', 'total_regle'),
                                         ('impayes', 'total_impaye'), ('loyers', 'loyer_appele'),
                                         ('taxes', 'total_taxes'),
                                         ('provisions', 'total_provisions'),
                                         ('divers', 'total_divers')):
                                if c in v:
                                    lot_courant[k] = v[c]['v']
                            if 'total' in v:
                                lot_courant['totaux_lus'] = True
                                lot_courant['totaux_sans_intitule'] = True
                            continue

                    mo = RE_PERIODE_OUVERTE.search(t)
                    if mo and v:
                        # Une correction : datée, sans fin, et elle compte dans le total du lot.
                        corr = {'du': '20%s-%s-%s' % (mo.group(3)[-2:], mo.group(2), mo.group(1)),
                                'au': None, 'correction': True,
                                'libelle': t.strip(), 'page': no_page, 'y': l['y']}
                        for c in COLONNES:
                            if c in v:
                                corr[c] = v[c]['v']
                        lot_courant['mois'].append(corr)
                        continue

                    if 'solde' in sans_accent(t).lower() and 'anterieur' in sans_accent(t).lower():
                        if 'solde_anterieur' in v:
                            lot_courant['solde_anterieur'] = v['solde_anterieur']['v']
                        elif 'loyers' in v:
                            lot_courant['solde_anterieur'] = v['loyers']['v']
                        for c, k in [('total', 'total_du'), ('regles', 'total_regle'), ('impayes', 'total_impaye')]:
                            if c in v:
                                lot_courant[k] = v[c]['v']
                        continue

                    # LA LIGNE « Totaux » DU LOT — c'est elle qui fait foi.
                    # Attention : le document en imprime DEUX qui commencent pareil. Celle du
                    # lot, puis le « TOTAUX » général de l'immeuble, qui cumule tous les lots.
                    # Prendre la seconde donnait au locataire parti les chiffres de tout
                    # l'immeuble. On retient donc la PREMIÈRE et on ignore les suivantes.
                    if sans_accent(t).lower().startswith('totaux') and v and not lot_courant['totaux_lus']:
                        lot_courant['totaux_lus'] = True
                        for c, k in [('loyers', 'loyer_appele'), ('taxes', 'total_taxes'),
                                     ('provisions', 'total_provisions'), ('divers', 'total_divers'),
                                     ('total', 'total_du'), ('regles', 'total_regle'),
                                     ('impayes', 'total_impaye')]:
                            if c in v:
                                lot_courant[k] = v[c]['v']
                        if 'solde_anterieur' in v and not lot_courant['solde_anterieur']:
                            lot_courant['solde_anterieur'] = v['solde_anterieur']['v']
                        continue

            # ── Les écritures, après « RÉCAPITULATIF DES OPÉRATIONS » ──────
            if i_rec is not None:
                bandes_r = bandes_recap(lignes[i_rec])
                if not bandes_r:
                    bandes_r = []
                imm['bandes_recap'] = [(c, round(a, 1), (round(b, 1) if b < 1e8 else None))
                                       for c, a, b in bandes_r]
                x_premiere = bandes_r[0][1] if bandes_r else 1e9
                # LA RÉGIE ÉCRIT ELLE-MÊME LA NATURE DE CHAQUE GROUPE.
                # « Taxe foncière », « Appel de fonds », « Fonds de Travaux (ALUR) »,
                # « Travaux payés au syndic », « Avance de trésorerie »… Ces lignes portent un
                # libellé et AUCUN montant : le lecteur les ignorait. Elles qualifient
                # pourtant les lignes qui suivent, et valent mieux que de deviner la catégorie
                # par sous-chaîne — la règle actuelle range 46 % des écritures en « autre ».
                entete_nature = None
                for l in lignes[i_rec + 1:]:
                    vals, mots_libelle = {}, []
                    for m in l['mots']:
                        v = nombre(m['text'])
                        # AVANT la première colonne, un nombre est du LIBELLÉ : une année, une
                        # référence d'avis, un numéro de compte. Jamais un montant.
                        if v is None or m['x1'] < x_premiere:
                            mots_libelle.append(m['text'])
                            continue
                        for cle, a, b in bandes_r:
                            if a <= m['x1'] < b:
                                vals[cle] = v
                                break
                        else:
                            mots_libelle.append(m['text'])
                    libelle = ' '.join(mots_libelle).strip()
                    if not libelle:
                        continue
                    if not vals:                       # une ligne sans montant qualifie les suivantes
                        if len(libelle) > 3 and not libelle.startswith('-')                            and any(c.isalpha() for c in libelle) and not est_pied_de_page(libelle):
                            entete_nature = libelle
                        continue
                    entree = {
                        'libelle': libelle, 'entete': entete_nature,
                        'categorie': categorie(entete_nature or libelle),
                        'debit': vals.get('debit', 0.0), 'credit': vals.get('credit', 0.0),
                        'tva': vals.get('tva', 0.0), 'locatif': vals.get('locatif', 0.0),
                        'deductible': vals.get('deductible', 0.0),
                        # `montant` reste le DÉBIT, pour les lecteurs qui ne connaissent que
                        # cette clé. Il porte enfin la bonne valeur.
                        'montant': vals.get('debit', 0.0),
                        'page': no_page, 'y': l['y'],
                    }
                    # UN TOTAL N'EST PAS UNE ÉCRITURE. L'importer le compterait deux fois — et
                    # c'est ce que faisait le parseur en texte plat, qui a rangé cinq fois
                    # « TOTAL DES REGLEMENTS LOCATAIRES » parmi les charges. On les met à part :
                    # ils servent à la réconciliation, pas à la comptabilité.
                    if est_pied_de_page(libelle):
                        continue
                    if est_total(libelle):
                        imm.setdefault('totaux_imprimes', []).append(entree)
                    elif est_parasite(libelle):
                        imm.setdefault('parasites', []).append(entree)
                    else:
                        imm['charges'].append(entree)

                # ── LA RÉCONCILIATION, ÉCRITE DANS LE DOCUMENT LUI-MÊME ────────────────
                # Débits imprimés  = somme des débits lus.
                # Crédits imprimés = somme des crédits lus + TOTAL DES RÈGLEMENTS LOCATAIRES,
                # qui est un crédit du document et non une écriture de charge.
                tg = None
                regl = 0.0
                report_d = report_c = 0.0
                for t in imm.get('totaux_imprimes', []):
                    u = sans_accent(t['libelle']).upper()
                    if 'TOTAUX GENERAUX' in u or 'TOTAL GENERAL' in u:
                        tg = t
                    if 'TOTAL DES REGLEMENTS' in u:
                        regl += t.get('credit', 0.0)
                    # ⚠️ UN IMMEUBLE PEUT PORTER SON PROPRE REPORT.
                    # « Report au 30.09.2025  4984.15 » en tête du récapitulatif de l'immeuble
                    # 01040247 (RUE PAUL CHEVRET). Ce n'est pas une écriture — c'est le solde du
                    # trimestre précédent — mais les « Totaux Généraux » de l'immeuble LE
                    # COMPTENT : 13 591,40 € d'écritures lues + 4 984,15 € de report = 18 575,55 €
                    # imprimés, au centime. L'écarter de la réconciliation la faisait échouer,
                    # et le solde de trésorerie manquait d'autant.
                    # Il reste rangé à part : ce n'est pas une charge d'exploitation, et le
                    # compter comme telle doublerait le déficit de l'immeuble.
                    # ⚠️ « Soldes immeubles / Solde au 30.06.2026 » EST UN MOUVEMENT, PAS UN
                    # RÉCAPITULATIF. Les CRG réédités en juillet 2026 balaient le solde de
                    # chaque immeuble vers le compte du mandant, et cette ligne de reversement
                    # entre dans les « Totaux Généraux ». Rangée parmi les totaux, elle
                    # manquait aux débits lus : 4 995,71 € lus contre 20 008,87 € imprimés sur
                    # 172 CHALLEMEL LACOUR — et l'écart valait exactement le solde reversé,
                    # 15 013,16 €. Quarante-huit immeubles lyonnais décrochaient ainsi.
                    # ⚠️ On exclut le solde FINAL (« Solde débiteur en Euros au … »), qui lui
                    # est bien un récapitulatif : le compter doublerait tout.
                    if (re.match(r'^SOLDE AU \d', u)
                            and 'EN EUROS' not in u):
                        report_d += t.get('debit', 0.0)
                        report_c += t.get('credit', 0.0)
                        imm.setdefault('reversements', []).append(
                            {'libelle': t['libelle'], 'debit': t.get('debit', 0.0),
                             'credit': t.get('credit', 0.0), 'page': t.get('page')})
                    if u.startswith('REPORT AU') or u.startswith('REPORT '):
                        report_d += t.get('debit', 0.0)
                        report_c += t.get('credit', 0.0)
                        imm['report'] = {'libelle': t['libelle'], 'debit': t.get('debit', 0.0),
                                         'credit': t.get('credit', 0.0), 'page': t.get('page')}
                sd = sum(c.get('debit', 0.0) for c in imm['charges']) + report_d
                sc = sum(c.get('credit', 0.0) for c in imm['charges']) + report_c
                if tg is not None:
                    ok = (abs(sd - tg.get('debit', 0.0)) < 0.005
                          and abs((sc + regl) - tg.get('credit', 0.0)) < 0.005)
                    imm['reconciliation'] = {
                        'etat': 'reconcilie' if ok else 'ecart',
                        'debits_lus': round(sd, 2), 'debits_imprimes': round(tg.get('debit', 0.0), 2),
                        'credits_lus': round(sc + regl, 2), 'credits_imprimes': round(tg.get('credit', 0.0), 2),
                        'ecart_debits': round(sd - tg.get('debit', 0.0), 2),
                        'ecart_credits': round((sc + regl) - tg.get('credit', 0.0), 2),
                    }
                elif not imm['charges'] and not imm.get('totaux_imprimes'):
                    # Un immeuble sans récapitulatif n'a aucune opération au trimestre. Rien à
                    # importer, rien à réconcilier — et surtout rien à signaler.
                    imm['reconciliation'] = {'etat': 'sans_operations'}
                else:
                    imm['reconciliation'] = {'etat': 'sans_total_imprime',
                                             'debits_lus': round(sd, 2), 'credits_lus': round(sc, 2)}

            code_imm_precedent = code_imm

    if pages_mandat and 'mandat' not in doc:
        doc['mandat'] = lire_mandat(pages_mandat)

    # ── LE CONTRÔLE CROISÉ : notre trésorerie contre celle de la régie ────
    # La page 1 donne le solde que LA RÉGIE a calculé pour chaque immeuble. Le nôtre se
    # reconstitue depuis les pages de détail :
    #
    #     solde = encaissé locataires + crédits d'écritures − débits d'écritures
    #
    # Les deux doivent tomber au centime. Vérifié 5/5 sur EVEREST T2. Les crédits
    # d'écritures — remboursements du syndic, régularisations — ne sont pas facultatifs :
    # les oublier creusait le solde de 74,90 € et 68,42 € sur deux immeubles.
    #
    # Si un immeuble ne concorde pas, aucune rentabilité ne doit s'afficher pour lui : ce
    # n'est pas une approximation, c'est que nous n'avons pas lu ce que le document dit.
    if doc.get('mandat') and doc['mandat'].get('soldes_immeubles'):
        par_nom = {}
        for sm in doc['mandat']['soldes_immeubles']:
            par_nom[sans_accent(sm['nom_normalise']).upper().strip()] = sm['solde']
        controles = []
        for im in doc['immeubles']:
            enc = sum(t.get('credit', 0.0) for t in im.get('totaux_imprimes', [])
                      if 'TOTAL DES REGLEMENTS' in sans_accent(t['libelle']).upper())
            deb = sum(c.get('debit', 0.0) for c in im.get('charges', []))
            cre = sum(c.get('credit', 0.0) for c in im.get('charges', []))
            # Le report du trimestre précédent pèse sur le solde imprimé : sans lui, notre
            # trésorerie s'écartait de 4 984,15 € sur l'immeuble 01040247.
            rep = im.get('report') or {}
            notre = round(enc + cre + rep.get('credit', 0.0)
                          - deb - rep.get('debit', 0.0), 2)
            nom = sans_accent(im.get('nom') or '').upper().strip()
            regie = None
            for k, v in par_nom.items():
                if k[:18] == nom[:18] or (nom and nom[:18] in k):
                    regie = v
                    break
            controles.append({
                'immeuble': im['code'], 'nom': im.get('nom'),
                'encaisse': round(enc, 2), 'credits': round(cre, 2), 'debits': round(deb, 2),
                'solde_calcule': notre, 'solde_regie': regie,
                'ecart': round(notre - regie, 2) if regie is not None else None,
                'etat': 'inconnu' if regie is None
                        else ('concorde' if abs(notre - regie) < 0.005 else 'ecart')})
        doc['controle_tresorerie'] = controles

    # ── Ce que le document affirme, pour la réconciliation ────────────────
    regl = 0.0
    for im in doc['immeubles']:
        for c in im.get('totaux_imprimes', []):
            if 'TOTAL DES REGLEMENTS' in sans_accent(c['libelle']).upper():
                # ⚠️ LES RÈGLEMENTS SONT UN CRÉDIT, PAS UN DÉBIT.
                # Cette somme lisait `montant` — devenu un alias du DÉBIT depuis la lecture
                # par colonnes. Les règlements locataires n'ont pas de débit : le total
                # tombait donc à 0,00 € et l'encaissé du document disparaissait en silence.
                regl += float(c.get('credit', 0) or 0)
    doc['totaux_document'] = {'total_reglements_locataires': round(regl, 2)}

    # ── Cohérence par lot, quand le PDF donne les deux valeurs ────────────
    for im in doc['immeubles']:
        for lot in im['lots']:
            # Le total d'un lot, c'est ses mois PLUS son report. Comparer les seuls mois au
            # total déclenchait un avertissement sur tout lot ayant un solde antérieur —
            # 90 fichiers du stock signalés pour rien.
            # ⚠️ UN SOLDE ANTÉRIEUR CRÉDITEUR N'ENTRE PAS DANS LE TOTAL DÛ. Quand le
            # locataire est en avance, la régie porte son solde en NÉGATIF et le total dû du
            # trimestre ne le comprend pas : il apparaît dans les RÉGLÉS. L'ajouter faisait
            # apparaître un écart égal, au centime, au solde lui-même — SREOUNA ABDALLAH
            # −955,92 € et LAAD −545,20 €, deux lots pourtant lus sans une erreur.
            # ⚠️ DEUX CONVENTIONS D'IMPRESSION, ET UN CRITÈRE POUR LES DISTINGUER.
            # Un solde antérieur DÉBITEUR entre toujours dans le total dû. Un solde CRÉDITEUR,
            # lui, y entre parfois seulement : chez SREOUNA ABDALLAH le total dû vaut la somme
            # des mois et le crédit figure dans les réglés ; chez ATV MEUBLES le total dû vaut
            # −28 154,08 €. Or une somme de mois ne peut pas être négative : si le total dû
            # l'est, c'est que le crédit y est compris. Le signe du total tranche donc, sans
            # qu'on ait à deviner ni à ajuster après coup.
            ant = lot['solde_anterieur'] or 0.0
            # ⚠️ NE PAS RECOMPOSER LE TOTAL D'UN MOIS À PARTIR DE SES COLONNES.
            # Essayé le 25/08 pour rattraper le format EMERY IMMO, où les totaux ne sont
            # imprimés qu'une fois pour trois mois : la somme des colonnes n'est PAS le total
            # dû — provisions et taxes s'y comptent différemment selon les lots. Lyon est passé
            # de 0 à 326 écarts. Ce qui n'est pas imprimé ne se devine pas : le lot devient
            # `non_controlable`, ce qui est la vérité, plutôt que faussement vert ou faussement rouge.
            # ⚠️ D'OÙ VIENT LE TOTAL D'UN MOIS — ET CE N'EST PAS LA MÊME RÉPONSE PARTOUT.
            # LYON l'IMPRIME : le recomposer l'a fait passer de 0 à 326 écarts. EMERY IMMO ne
            # l'imprime pas ; le total d'un mois EST la somme de ses colonnes — vérifié :
            # 3 × (417,58 + 61,00) = 1 435,74, exactement l'agrégat du lot.
            def _total_mois(m):
                if m.get('total') is not None or var['total_mois'] == 'imprime':
                    return m.get('total') or 0.0
                return round(sum((m.get(c) or 0.0)
                                 for c in ('loyers', 'taxes', 'provisions', 'divers')), 2)
            mois_somme = sum(_total_mois(m) for m in lot['mois'])
            inclut_ant = (ant > 0) or ((lot['total_du'] or 0.0) < 0)
            somme = round(mois_somme + (ant if inclut_ant else 0.0), 2)

            # ⚠️ UN CONTRÔLE QUI NE S'EXÉCUTE PAS NE VAUT PAS UN SUCCÈS. La condition
            # « if somme and total_du » sautait en silence tout lot dont la somme valait zéro :
            # 160 lots d'EMERY IMMO sur 385 ressortaient « réconciliés » sans avoir jamais été
            # mesurés. C'est exactement le défaut qu'on traque depuis deux jours — et il était
            # dans le contrôle lui-même. Trois états, jamais d'implicite.
            if not lot['totaux_lus'] or lot['total_du'] is None:
                lot['controle_lot'] = 'non_controlable'
                lot['motif_non_controle'] = "le document n'imprime pas de total pour ce lot"
            # ⚠️ UN TOTAL DE ZÉRO EST UN RÉSULTAT, PAS UNE ABSENCE. Troisième fois que je
            # bute là-dessus. KPOKOU ADJO : 234,50 € de loyer et −234,50 € de solde de charges,
            # total dû 0,00 € — le compte est juste, et la somme lue vaut zéro elle aussi. MOREL
            # MANDY : 1 165,33 € de loyer annulés par une remise. Écarter ces lots faute de
            # « somme non nulle » revenait à refuser de contrôler précisément ceux qui tombent
            # juste. La seule vraie raison de ne pas contrôler, c'est que le document
            # n'imprime AUCUN total — et cela, `totaux_lus` le dit déjà.
            else:
                lot['ecart_total_lot'] = round(somme - lot['total_du'], 2)
                lot['controle_lot'] = ('reconcilie' if abs(lot['ecart_total_lot']) < 0.005
                                       else 'ecart')
            lot['statut'] = 'occupe' if lot['loyer_appele'] > 0 else 'parti'
    # L'identité temporelle du document : année + trimestre + clé canonique, avec la
    # source dont elle vient. La date d'arrêté reste une métadonnée complémentaire.
    normaliser_periode(doc.setdefault('meta', {}))
    return doc


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'erreur': 'usage : crg_ics_core.py <fichier.pdf> [variante]'}))
        sys.exit(1)
    _variante = sys.argv[2] if len(sys.argv) > 2 else 'lyon'
    # ⚠️ ensure_ascii=True, DÉLIBÉRÉMENT — la même leçon que le lecteur SEPTEO a apprise avant
    #    nous. Sous Windows la sortie standard d'un processus fils est en cp1252 : « ANDRÉ »
    #    y partait en octet 0xC9 et revenait en U+FFFD chez l'appelant. 35 noms de propriétaire
    #    sur 411 arrivaient ainsi mutilés — « MONSIEUR VANTALON ANDR<?> » — et le nom de fichier
    #    GED qui s'en déduit avec eux. En pur ASCII échappé, le JSON traverse n'importe quel
    #    encodage sans une perte.
    try:
        print(json.dumps(parse(sys.argv[1], _variante), ensure_ascii=True))
    except Exception as e:
        print(json.dumps({'erreur': str(e)}, ensure_ascii=True))
        sys.exit(1)
