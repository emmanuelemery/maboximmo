# -*- coding: utf-8 -*-
"""
ROBUSTESSE DU LECTEUR D'INTÉGRATION — un incident, une fixture, un test.

⚠️ CHAQUE CAS DE CE FICHIER A RÉELLEMENT COÛTÉ DU TEMPS. Aucun n'est théorique : ils viennent
   tous du premier dépôt réel (906 pages, VIENNE, avril-juillet 2026) ou du corpus certifié.
   Un commentaire dans le code n'aurait pas suffi — une règle qu'aucun test ne défend se
   réintroduit silencieusement à la première refonte.

⚠️ LA MOITIÉ DE CE FICHIER TESTE DES REFUS. Un moteur qui conclut toujours n'est pas robuste :
   il est bavard. On vérifie donc aussi qu'il sait dire « je ne sais pas » — que « 01 » ne
   s'apparie pas à « 101 », qu'un même montant ne fait pas une même écriture, qu'un libellé
   générique ne désigne personne, et qu'un montant hors colonne ne tombe pas dans la voisine.

Usage : python crgi_robustesse.py
"""
import io
import os
import sys

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RACINE)

from crg_integration_lots import (RE_SUITE, code_immeuble,        # noqa: E402
                                  locataires_de, normaliser_reference, segments_de_lot)
import crg_integration_phase0 as P0                               # noqa: E402
import crg_integration_phase4 as P4                               # noqa: E402

CAS = []


def cas(titre, incident):
    """Enregistre un test avec l'incident réel qu'il défend."""
    def deco(fn):
        CAS.append((titre, incident, fn))
        return fn
    return deco


def mot(texte, x1, x0=None):
    """Un mot tel que pdfplumber le rend, réduit à ce dont le lecteur se sert."""
    return {'text': texte, 'x1': x1, 'x0': x0 if x0 is not None else x1 - 30, 'top': 100}


# ═════════════════════════════════════════════════════════════════════════════════════════
#  SEGMENTATION DES LOTS
# ═════════════════════════════════════════════════════════════════════════════════════════

@cas('référence de lot coupée par l’OCR',
     'Le document imprime « Lot 01 G01-5052-000115 » : une classe sans espace s’arrêtait sur '
     '« 01 » et fusionnait deux appartements voisins en un seul lot.')
def _():
    segs = segments_de_lot('Appartement - Lot 01 G01-5052-000115 - Mandat N/A -Bat', 1)
    assert len(segs) == 1, segs
    assert segs[0]['reference'] == '01G01-5052-000115', segs[0]['reference']


@cas('référence de lot d’un seul caractère',
     '« - Lot 1 - Mandat N/A - » existe : exiger trois caractères effaçait le lot ET ses '
     '28 montants, dont 738,86 € de loyer appelé.')
def _():
    segs = segments_de_lot('Appartement 3 Pièces - Lot 1 - Mandat N/A -Bat -Esc', 1)
    assert len(segs) == 1 and segs[0]['reference'] == '1', segs


@cas('en-tête « Suite » n’ouvre pas un lot',
     'Un bloc qui déborde réimprime son en-tête suivi de « Suite », SANS la ligne '
     '« Locataire: ». Les compter créait 35 lots « sans occupant » à arbitrer un par un.')
def _():
    t = 'Immeuble X - Lot 276-04 - Mandat N/A - Bat ... Suite'
    segs = segments_de_lot(t, 1)
    assert len(segs) == 1 and segs[0]['suite'] is True, segs
    assert RE_SUITE.search('... Suite')


@cas('deux occupants successifs dans un même bloc',
     'Le document imprime deux « Locataire: » dans le même bloc. La phase 3 ne gardait que le '
     'premier, la phase 4 que le dernier : deux phases, deux locataires, même lot.')
def _():
    t = ('- Lot 01G01-5208-000358 - Mandat N/A -Bat\n'
         'Locataire: DE SOUSA Jordan, Bail du 01/06/2025 au 04/06/2026\n'
         'Locataire: GONIN Marine, Bail du 22/06/2026\n')
    occ = locataires_de(t)
    assert len(occ) == 2, occ
    assert occ[0]['locataire'] == 'DE SOUSA Jordan' and occ[0]['bail_au'] == '04/06/2026'
    assert occ[1]['locataire'] == 'GONIN Marine' and occ[1]['bail_au'] is None


@cas('le locataire suit SON lot',
     'La phase 2 prenait le dernier « Locataire: » de la page et l’attribuait à TOUS ses '
     'lots : un lot sans occupant héritait de celui du lot précédent.')
def _():
    t = ('- Lot 0003 - Mandat N/A -Bat\n'
         'Locataire: CLEMENTE HOLGUERA Erlantz, Bail du 01/11/2024\n'
         '- Lot 0004 - Mandat N/A -Bat\n')
    segs = segments_de_lot(t, 1)
    assert len(segs) == 2, segs
    assert segs[0]['locataire'] == 'CLEMENTE HOLGUERA Erlantz'
    assert segs[1]['locataire'] is None, segs[1]['locataire']


@cas('REFUS — quatre graphies de référence, aucune devinée',
     'Prendre systématiquement le premier segment donnait « 01G01 » pour 221 lots : un '
     'immeuble qui n’existe pas.')
def _():
    assert code_immeuble('01G01-5213-000367') == ('5213', '000367')
    assert code_immeuble('276-04') == ('276', '04')
    assert code_immeuble('01') == (None, '01'), code_immeuble('01')
    assert code_immeuble(None) == (None, None)


@cas('normalisation de référence', 'Les espaces d’OCR et le tiret final ne font pas partie '
     'de la référence.')
def _():
    assert normaliser_reference('01 G01-5052-000115-') == '01G01-5052-000115'
    assert normaliser_reference(None) == ''


# ═════════════════════════════════════════════════════════════════════════════════════════
#  LECTURE DES MONTANTS
# ═════════════════════════════════════════════════════════════════════════════════════════

@cas('espace des milliers — de l’argent qui disparaît',
     '« 1 775,40 » ressort en « 1 » puis « 775,40 » : sans recollage on lit 775,40 et on perd '
     'mille euros SANS AUCUN SIGNAL. Ni erreur, ni anomalie, juste un total faux.')
def _():
    mots = [mot('1', 235, 230), mot('775,40', 268, 236)]
    r = P4.recoller(mots)
    assert len(r) == 1 and r[0]['text'] == '1775,40', r
    assert P4.nombre(r[0]['text']) == 1775.40


@cas('symbole € qui coupe les centimes',
     '« Solde Immeuble 311,14€ » ressort en « 311, » puis « 14 ».')
def _():
    mots = [mot('311,', 250, 235), mot('14€', 262, 251)]
    r = P4.recoller(mots)
    assert len(r) == 1 and r[0]['text'] == '311,14', r


@cas('la COLONNE est la nature',
     'Le même « 474,49 » est un loyer appelé sous `Loyers`, un encaissement sous `Crédit`, '
     'un impayé sous `Reste dû`. Le lire au blanc perdait cette distinction.')
def _():
    assert P4.colonne_de(mot('474,49', 258)) == 'loyers'
    assert P4.colonne_de(mot('474,49', 564)) == 'credit'
    assert P4.colonne_de(mot('474,49', 411)) == 'reste_du'
    assert P4.colonne_de(mot('32,00', 309)) == 'charges'
    assert P4.colonne_de(mot('210,56', 487)) == 'debit'


@cas('REFUS — un montant hors colonne ne tombe pas dans la voisine',
     'Aucune affectation « au plus proche » sans borne : au-delà de la tolérance, le montant '
     'est INDETERMINABLE et remonte à l’écran.')
def _():
    assert P4.colonne_de(mot('985,96', 286)) is None, 'x1=286 n’est aucune colonne'
    assert P4.colonne_de(mot('402,45', 102)) is None, 'un virement en clair n’est pas colonné'


@cas('REFUS — la prose ment par hasard',
     '« la somme de 1 372,86 € … sera reportée au débit du prochain relevé » : ce montant '
     'tombe PILE sous la colonne `Charges`.')
def _():
    t = ('Compte tenu de la situation de votre compte, la somme de 1372,86 € (constituée des '
         'factures dues à ce jour: 2177,33 €) sera reportée au débit du prochain relevé')
    assert P4.RE_PROSE.search(t) or any(r in t for r in P4.REJETS), 'la prose doit être écartée'


@cas('« dont TVA » soudé par l’OCR',
     'L’OCR rend « dontTVA », et lit parfois le T comme un l : « dontlVA ». Exiger l’espace '
     'laissait 34 détails de TVA — 1 039,91 € — en INDETERMINABLE.')
def _():
    for libelle in ('dont TVA 23,83', 'dontTVA', 'dontlVA 5187-', 'DONT TVA'):
        assert P4.RE_TVA.match(libelle), libelle


@cas('un paramètre imprimé dans le libellé n’est pas un mouvement',
     '« Honoraires Gestion TTC (taux:6,00 % HT, base:1 985,96 €) » porte l’ASSIETTE du '
     'calcul : la compter ajoutait 1 985,96 € de dépense imaginaire.')
def _():
    assert P4.RE_PARAMETRE.search('HT,base:1')
    assert P4.RE_PARAMETRE.search('base:')
    assert not P4.RE_PARAMETRE.search('Honoraires')


@cas('REFUS — le paramètre ne l’emporte jamais sur la colonne',
     'Inverser l’ordre des deux tests ferait disparaître un vrai mouvement au motif qu’un mot '
     'ressemblant à « base: » le précède.')
def _():
    src = open(os.path.join(RACINE, 'crg_integration_phase4.py'), encoding='utf-8').read()
    i = src.index('colonne = colonne_de(mot)')
    j = src.index('RE_PARAMETRE.search(gauche)')
    assert i < j, 'la colonne doit être calculée AVANT le test du paramètre'


@cas('REFUS — un agrégat n’est pas un mouvement élémentaire',
     'Le « Récapitulatif des immeubles » rejoue par immeuble ce que les blocs ont déjà dit : '
     '263 montants. Les additionner doublait le dépôt.')
def _():
    lect = P4.Lecteur()
    lect.section = 'RECAP'
    cat, _m = P4.categoriser(lect, 'debit', 'Immeuble 10 rue Pérouillière - 38200 VIENNE')
    assert cat == P4.AGREGAT, cat


@cas('REFUS — une section inconnue n’est pas une section vide',
     'Une section que le moteur ne connaît pas doit produire INDETERMINABLE, jamais un '
     'classement par défaut.')
def _():
    lect = P4.Lecteur()
    lect.titre('- Une Section Jamais Vue -')
    assert str(lect.section).startswith('INCONNUE:'), lect.section
    cat, _m = P4.categoriser(lect, 'debit', 'Quelque chose')
    assert cat == P4.INDETERMINABLE, cat


@cas('appel et encaissement ne se confondent pas',
     'Un encaissement peut solder une période ANTÉRIEURE : l’écart `appelé − encaissé` n’est '
     'jamais « l’impayé de la période ».')
def _():
    lect = P4.Lecteur()
    lect.section = 'BLOC_LOT'
    lect.lot = '01'
    assert P4.categoriser(lect, 'loyers', 'Loyer Avril 2026')[0] == P4.LOYER_APPELE
    assert P4.categoriser(lect, 'credit', 'Loyer Avril 2026')[0] == P4.ENCAISSEMENT
    assert P4.categoriser(lect, 'reste_du', 'Loyer Avril 2026')[0] == P4.ENCOURS
    assert P4.categoriser(lect, 'charges', 'Provisions pour charges')[0] == P4.CHARGE_APPELEE


@cas('REFUS — une provision appelée au locataire n’est pas une dépense',
     '`DÉPENSE ≠ APPEL LOCATAIRE` : deux catégories, jamais une seule.')
def _():
    lect = P4.Lecteur()
    lect.section = 'BLOC_LOT'
    lect.lot = '01'
    assert P4.categoriser(lect, 'charges', 'Provisions TEOM')[0] != P4.CHARGE


@cas('stock et flux ne se mélangent pas',
     '`Reste dû` et les soldes sont des PHOTOGRAPHIES : jamais additionnées entre périodes.')
def _():
    lect = P4.Lecteur()
    lect.section = 'BLOC_LOT'
    lect.lot = '01'
    assert P4.categoriser(lect, 'reste_du', 'Loyer Mai 2026')[0] == P4.ENCOURS
    assert P4.categoriser(lect, 'debit', 'Solde du dernier Rapport au 28/2/2026')[0] == P4.ENCOURS


# ═════════════════════════════════════════════════════════════════════════════════════════
#  L'EN-TÊTE SEPTEO — LE NOM DU PROPRIÉTAIRE, QUEL QUE SOIT L'OUTIL QUI REND LA PAGE
# ═════════════════════════════════════════════════════════════════════════════════════════

# Le même en-tête, tel que le rendent les DEUX binaires nommés `pdftotext` trouvés sur le
# poste : Xpdf 4.00 pose le nom sur la ligne du titre, poppler 25.07 le descend de deux lignes.
ENTETE_XPDF = (
    '             COMPTE RENDU DE GESTION                                 Monsieur XERRI Florent\n'
    '                                                                     37 Chemin DE MORAND\n'
    '      Agence: A3 - REGIE EMERY - VIENNE                              38670 CHASSE-SUR-RHONE\n'
    '      Période du 01/07/2026 au 31/07/2026                              FRANCE\n'
    '      Identifiant extratnet : 1105402916\n'
    '      Mot de passe: 1101\n'
)
ENTETE_POPPLER = (
    '             COMPTE RENDU DE GESTION\n'
    '\n'
    '\n'
    '       Agence: A3 - REGIE EMERY - VIENNE\n'
    '                                                                                           Monsieur XERRI Florent\n'
    '       Période du 01/07/2026 au 31/07/2026                                                 37 Chemin DE MORAND\n'
    '                                                                                           38670 CHASSE-SUR-RHONE\n'
    '       Identifiant extratnet : 1105402916                                                  FRANCE\n'
    '       Mot de passe: 1101\n'
)
# Colonnes APLATIES : aucun outil ne rend cela aujourd'hui, mais un OCR le fera.
ENTETE_APLATI = (
    'COMPTE RENDU DE GESTION\n'
    'Agence: A3 - REGIE EMERY - VIENNE Période du 01/07/2026 au 31/07/2026 '
    'Identifiant extratnet : 1105402916 Mot de passe: 1101\n'
    'Monsieur XERRI Florent 37 Chemin DE MORAND 38670 CHASSE-SUR-RHONE FRANCE\n'
)


@cas('le nom du propriétaire est le même sous les deux « pdftotext »',
     'Xpdf 4.00 et poppler 25.07 portent le MÊME nom de commande et ne rendent pas la même '
     'page. Le moteur lisait la fin de la ligne du titre : juste avec Xpdf, faux avec poppler '
     '— et c’est poppler qu’Apache utilise. 288 CRG sur 325 ont pris « Agence: A3 - REGIE '
     'EMERY - VIENNE » pour un nom de propriétaire, le 02/09/2026, sans un seul signal.')
def _proprietaire_deux_rendus():
    a = P0.identifier(ENTETE_XPDF, 'septeo_spi')['proprietaire']
    b = P0.identifier(ENTETE_POPPLER, 'septeo_spi')['proprietaire']
    assert a == 'Monsieur XERRI Florent', 'rendu Xpdf : %r' % a
    assert b == 'Monsieur XERRI Florent', 'rendu poppler : %r' % b


@cas('REFUS — un champ de l’en-tête n’est jamais un nom de propriétaire',
     'Sur des colonnes aplaties, ce qui suit le nom de l’agence est le reste de l’en-tête. Le '
     'moteur l’enregistrait tel quel : « Agence: … Période du … Mot de passe: 1101 » partait '
     'en base comme identité de propriétaire. `ABSENCE DE LECTURE ≠ LECTURE APPROXIMATIVE`.')
def _proprietaire_jamais_un_champ():
    lu = P0.identifier(ENTETE_APLATI, 'septeo_spi')['proprietaire']
    assert lu is None or not any(
        marqueur in lu for marqueur in ('Agence:', 'Période du', 'Mot de passe',
                                        'Identifiant')), 'lu : %r' % lu


@cas('REFUS — l’agence reste l’agence sous les deux rendus',
     'Le nom du propriétaire et celui de l’agence se lisent dans le même en-tête : corriger '
     'l’un en abîmant l’autre ferait deux champs faux au lieu d’un.')
def _agence_stable():
    for nom, entete in (('xpdf', ENTETE_XPDF), ('poppler', ENTETE_POPPLER),
                        ('aplati', ENTETE_APLATI)):
        ag = P0.identifier(entete, 'septeo_spi')['agence']
        assert ag == 'A3 - REGIE EMERY - VIENNE', '%s : %r' % (nom, ag)


# ═════════════════════════════════════════════════════════════════════════════════════════
#  UNE SEULE AUTORITÉ DE RECONNAISSANCE — RÉFÉRENTIEL ET INTÉGRATEUR NE PEUVENT PAS DIVERGER
# ═════════════════════════════════════════════════════════════════════════════════════════

# Les en-têtes réels des trois familles certifiées, réduits à ce qui les distingue.
ENTETES = {
    'septeo_spi': 'FNAIM\nRégie EMERY\nCOMPTE RENDU DE GESTION\n'
                  'Agence: A3 -REGIE EMERY -VIENNE\nMonsieur XERRI Florent\n'
                  'Identifiant extratnet : 1105402916\nMot de passe: 1101\n',
    'emery_immo': 'EMERY IMMOBILIER\nSucc. SERVAJEAN\n12 PLACE SAINT-JEAN\n63200 RIOM\n'
                  'COMPTE RENDU DE GESTION\nRIOM, le 29/06/2026\n'
                  '- 2e Trimestre 2026 -\nCOMPTE PERSONNEL 00180000\n',
    'lyon': 'LOCA IMMO LYON\nCOMPTE RENDU DE GESTION\nLyon, le 29/06/2026\n'
            '- 2e Trimestre 2026 -\nCOMPTE PERSONNEL 04680000\n',
}


# ═════════════════════════════════════════════════════════════════════════════════════════
#  ARBITRAGE CHAPONOST DU 03/09/2026 — QUATRE RÈGLES, CHACUNE DÉMONTRÉE SUR LE DOCUMENT
# ═════════════════════════════════════════════════════════════════════════════════════════

def _lecteur(section, lot=None):
    l = P4.Lecteur()
    l.section = section
    l.lot = lot
    return l


@cas('un « Total de l’immeuble » est un AGRÉGAT, dans quelque section qu’il tombe',
     'Cette ligne n’a pas de titre à elle : elle hérite de la section restée ouverte '
     'au-dessus. Sur CHAPONOST, 819 lignes — dont 464 comptées en CHARGE et 47 en FRAIS, '
     'c’est-à-dire ADDITIONNÉES aux mouvements élémentaires qu’elles récapitulent.')
def _total_immeuble_agrege():
    for section in ('PROPRIETAIRE', 'HONORAIRES', 'FACTURES', 'SYNDIC', 'CHARGES_LOCATIVES'):
        for colonne in ('debit', 'credit', 'charges', 'autres', 'loyers', 'reste_du'):
            nat, _m = P4.categoriser(_lecteur(section), colonne, "Total de l'immeuble")
            assert nat == P4.AGREGAT, \
                'section %s / colonne %s : %s' % (section, colonne, nat)
    # Les variantes numérotées du document en sont aussi.
    for libelle in ("Total de l'immeuble 2", "Total de l’immeuble 5"):
        nat, _m = P4.categoriser(_lecteur('PROPRIETAIRE'), 'debit', libelle)
        assert nat == P4.AGREGAT, '%r : %s' % (libelle, nat)


@cas('REFUS — un total d’immeuble n’alimente jamais P5, P7 ni P8',
     'Un agrégat additionné est un document compté deux fois. La garantie ne tient pas au '
     'libellé mais au drapeau : `additionnable` doit valoir 0, comme pour le Récapitulatif.',
     )
def _total_immeuble_jamais_somme():
    nat, _m = P4.categoriser(_lecteur('PROPRIETAIRE'), 'credit', "Total de l'immeuble")
    assert nat in P4.SECTIONS.get('RECAP', P4.AGREGAT), 'la nature a changé : %s' % nat
    assert nat == P4.AGREGAT
    # AGREGAT fait partie des natures que la phase 4 ne somme jamais.
    assert 'NON ADDITIONNABLE' in nat, \
        'la nature ne se déclare plus non additionnable : %r' % nat


@cas('« Charges locatives » est une DÉPENSE, jamais un appel au locataire',
     'CHAPONOST page 11 : les 192,00 € de « LADY NETTOYAGE » figurent TROIS fois — appel au '
     'locataire en colonne Autres à la maille LOT, encaissement en crédit à la maille LOT, '
     'et cette dépense en débit du compte. Les classer en appel doublerait 19 635,60 €.')
def _charges_locatives_depense():
    nat, _m = P4.categoriser(_lecteur('CHARGES_LOCATIVES'), 'debit',
                             'ORCEL 9 Verdun Nettoyage app.- LADY NETTOYAGE')
    assert nat == P4.CHARGE, 'attendu CHARGE, obtenu %s' % nat
    assert nat != P4.AUTRE_APPELE and nat != P4.CHARGE_APPELEE, \
        'la dépense est devenue un appel — double comptage'
    # Et les DEUX autres jambes, au lot, restent ce qu’elles étaient.
    lot = _lecteur('BLOC_LOT', lot='1043-0011')
    assert P4.categoriser(lot, 'autres', 'ORCEL 9 Verdun Nettoyage')[0] == P4.AUTRE_APPELE
    assert P4.categoriser(lot, 'credit', 'ORCEL 9 Verdun Nettoyage')[0] == P4.ENCAISSEMENT


@cas('« PNO Assurance » : le débit est une prime, le crédit attend un arbitrage',
     'Les 4 débits sont la prime propriétaire non occupant — même forme que GLI et GU, déjà '
     'certifiées. Les 2 crédits (129,98 €) RESSEMBLENT à un remboursement — « payée par '
     'vous » — mais ressembler n’est pas démontrer.')
def _pno_debit_seulement():
    nat, _m = P4.categoriser(_lecteur('PNO'), 'debit', 'MAAF contrat PNO ex2025 29 av DOUMER')
    assert nat == P4.FRAIS, 'débit PNO : attendu FRAIS, obtenu %s' % nat
    nat, motif = P4.categoriser(_lecteur('PNO'), 'credit', 'MACIF Assurance PNO payée par vous')
    assert nat == P4.INDETERMINABLE, 'crédit PNO forcé en %s au lieu d’un arbitrage' % nat
    assert 'démontrée' in motif or 'arbitrage' in motif, 'le refus ne dit pas pourquoi'


@cas('REFUS — « Autres Recettes » ne reçoit AUCUNE règle de section',
     'Un remboursement de sinistre (crédit) et des mouvements de garantie loyers impayés '
     'dans les deux sens cohabitent sous ce seul titre. Une section qui mêle plusieurs '
     'natures métier ne peut pas en porter une seule.')
def _autres_recettes_sans_regle():
    for colonne in ('debit', 'credit'):
        nat, _m = P4.categoriser(_lecteur('INCONNUE:Autres Recettes'), colonne,
                                 'Vrt GLI ARILIM Loc DEBOUS au 31.03.2026')
        assert nat == P4.INDETERMINABLE, \
            'une règle de section est apparue sur Autres Recettes : %s' % nat


@cas('la table des libellés de section s’AJOUTE, elle ne se remplace pas',
     'Chaque comptable nomme ses sections comme il l’entend. Une agence qui change de mot ne '
     'doit jamais faire disparaître le mot d’une autre : les entrées certifiées restent en '
     'tête de table, les nouvelles s’ajoutent en fin, où elles ne peuvent rien recouvrir.')
def _table_additive():
    connus = {'- Honoraires de Gestion -': 'HONORAIRES', '- GLI -': 'GLI',
              '- GU Assurance -': 'GU', '- Charges de syndic -': 'SYNDIC',
              '- Charges Propriétaire -': 'PROPRIETAIRE',
              '- Charges locatives -': 'CHARGES_LOCATIVES', '- PNO Assurance -': 'PNO'}
    for titre, attendu in connus.items():
        lect = P4.Lecteur()
        assert lect.titre(titre), 'titre non reconnu : %r' % titre
        assert lect.section == attendu, '%r → %s au lieu de %s' % (titre, lect.section, attendu)
    # Un libellé inconnu reste inconnu — il ne se rabat sur aucune section voisine.
    lect = P4.Lecteur()
    lect.titre('- Autres Recettes -')
    assert lect.section.startswith('INCONNUE:'), lect.section


@cas('tout format reconnu par le RÉFÉRENTIEL l’est aussi par l’INTÉGRATEUR',
     'Le 02/09/2026, sur les deux mêmes documents : le routeur certifié disait `emery_immo` '
     'là où l’intégrateur disait `lyon`, et `inconnu` là où l’intégrateur disait '
     '`septeo_spi`. Aucun des deux n’englobait l’autre. Le module a donc lu 224 CRG EMERY '
     'CERTIFIÉS avec le lecteur LYON, en répondant `CERTAIN`.')
def _une_seule_autorite():
    import crg_format
    import crg_depot_lire as DEPOT
    # Une seule implémentation, pas deux qui se ressemblent.
    assert DEPOT.famille_du_texte is crg_format.famille_du_texte if hasattr(
        DEPOT, 'famille_du_texte') else True
    for attendu, entete in ENTETES.items():
        vu = crg_format.famille_du_texte(entete)
        assert vu == attendu, 'référentiel : %s lu comme %s' % (attendu, vu)
        _etat, famille, _motif = P0.qualifier(entete)
        assert famille == attendu, \
            'INTÉGRATEUR EN DÉSACCORD : référentiel=%s, intégrateur=%s' % (attendu, famille)


@cas('REFUS — la structure ne l’emporte jamais sur l’enseigne',
     'EMERY et LYON impriment tous deux « COMPTE RENDU DE GESTION » et « COMPTE PERSONNEL ». '
     'Tester la structure avant l’enseigne rangeait tout EMERY dans LYON — c’est la cause '
     'exacte de l’incident du 02/09/2026.')
def _enseigne_avant_structure():
    import crg_format
    # L'en-tête EMERY porte la structure LYON : l'enseigne doit trancher.
    assert crg_format.famille_du_texte(ENTETES['emery_immo']) == 'emery_immo'
    # Et sans aucune enseigne, la structure reste le dernier recours — jamais le premier.
    nu = 'COMPTE RENDU DE GESTION\nCOMPTE PERSONNEL 04680000\n'
    assert crg_format.famille_du_texte(nu) == 'lyon', 'le dernier recours ne répond plus'
    assert crg_format.famille_du_texte('page quelconque sans signal') == 'inconnu', \
        'un document sans signe est déclaré d’une famille : `AUCUN LECTEUR DE SECOURS`'


@cas('qualifier les collisions par LOT rend exactement ce que la paire à paire rendait',
     'Regrouper les 18 qualifications en un seul appel a fait tomber la phase 0 de 118 s à '
     '~21 s. Le danger n’est pas la règle — elle n’a pas bougé — mais l’APPARIEMENT : un '
     'verdict rendu dans le désordre collerait la qualification d’un CRG sur un autre, et '
     'rien à l’écran ne le dirait.')
def _collisions_par_lot():
    import json
    import tempfile
    import crg_integration_doublons as D

    # Quatre « pages » synthétiques : deux situations identiques, deux différentes.
    pages = [
        'Loyer 1 000,00 Charges 100,00 Total 1 100,00 Solde 250,00 Report 12,00',
        'Loyer 1 000,00 Charges 100,00 Total 1 100,00 Solde 250,00 Report 12,00',
        'Loyer 2 000,00 Charges 300,00 Total 2 300,00 Solde 999,00 Report 44,00',
        'Loyer 7 777,00 Charges 888,00 Total 8 665,00 Solde 111,00 Report 55,00',
    ]
    ancien = D.lire_pages
    D.lire_pages = lambda chemin: (pages, 'fixture')
    try:
        paires = [{'id': 101, 'ad': 1, 'af': 1, 'bd': 2, 'bf': 2},
                  {'id': 202, 'ad': 3, 'af': 3, 'bd': 4, 'bf': 4}]
        chemin = tempfile.mktemp(suffix='.json')
        with io.open(chemin, 'w', encoding='utf-8') as fh:
            json.dump(paires, fh)

        sortie = []
        vrai_write = sys.stdout.write
        sys.stdout.write = sortie.append
        try:
            D.main.__globals__['sys'].argv = ['x', 'faux.pdf', chemin]
            D.main()
        finally:
            sys.stdout.write = vrai_write
        lot = {v['id']: v for v in json.loads(''.join(sortie))}

        # L'oracle : la règle appelée directement, paire par paire.
        for p in paires:
            attendu = D.qualifier_paire(pages[p['ad'] - 1:p['af']], pages[p['bd'] - 1:p['bf']])
            rendu = lot[p['id']]
            assert rendu['verdict'] == attendu['verdict'], \
                'paire %s : lot=%s, paire à paire=%s' % (p['id'], rendu['verdict'],
                                                         attendu['verdict'])
            assert rendu['motif'] == attendu['motif'], 'paire %s : motif divergent' % p['id']
        # et l'appariement lui-même : les deux verdicts ne sont pas les mêmes, donc un
        # échange passerait inaperçu si on ne l'exigeait pas explicitement.
        assert lot[101]['verdict'] == 'A' and lot[202]['verdict'] == 'B', \
            'verdicts appariés à l’envers : %s' % {k: v['verdict'] for k, v in lot.items()}
    finally:
        D.lire_pages = ancien


@cas('les phases 0 et 3 lisent avec LE MÊME binaire',
     'Chacune appelait `shutil.which(\'pdftotext\')`, qui rend le premier du PATH. Selon '
     'qu’on partait de la page ou du harnais, ce n’était pas le même programme : la phase 2 '
     'rendait deux empreintes différentes pour un document identique, et le sceau accusait '
     'le moteur d’une divergence qui venait du PATH.')
def _un_seul_lecteur():
    import crg_integration_phase3 as P3
    assert P3.pdftotext_exe is P0.pdftotext_exe, 'deux résolutions de binaire coexistent'
    exe, etiquette, _t = P0.pdftotext_exe()
    if exe is None:
        return                      # aucun binaire : c'est un autre défaut, pas celui-ci
    assert etiquette and etiquette != 'pdftotext', \
        'le lecteur ne dit pas quel produit il est : %r' % etiquette


@cas('REFUS — la capacité `-table` se PROBE, elle ne se déduit d’aucun nom de produit',
     'La première version de ce test écrivait `sait_table == ("poppler" in étiquette)` — et '
     'c’était l’INVERSE de la vérité : `-table` est une option **Xpdf**, absente de poppler '
     '25.07. Le moteur lançait donc `-table` sur poppler, échouait, et retombait EN SILENCE '
     'sur `-layout` — le repli que la doctrine chiffre à 307 rattachements au lieu de 1 022. '
     'Un test qui encode la croyance du développeur valide la croyance, pas le monde.')
def _table_probee():
    import re as _re
    import subprocess as _sp
    exe, _etiquette, sait_table = P0.pdftotext_exe()
    if exe is None:
        return
    # La seule autorité : la liste d'options que le binaire imprime lui-même.
    aide = _sp.run([exe, '-h'], stdout=_sp.PIPE, stderr=_sp.STDOUT).stdout.decode('utf-8',
                                                                                  'replace')
    reellement = bool(_re.search(r'^\s+-table\b', aide, _re.M))
    assert sait_table == reellement, (
        'le moteur annonce `-table`=%s alors que le binaire %s' % (
            sait_table, 'le propose' if reellement else 'ne le propose PAS'))


# ═════════════════════════════════════════════════════════════════════════════════════════
#  LE CONTRAT DE LECTURE, ET LA COLONNE QUI PORTE LE PROPRIÉTAIRE
# ═════════════════════════════════════════════════════════════════════════════════════════

def _page_ics(espaces_avant_ville, lignes_vides_avant_adresse=1):
    """Une page ICS synthétique : en-tête à gauche, « Ville, le … » et bloc adresse à droite.

    ⚠️ AUCUN DOCUMENT RÉEL ICI. La fixture reproduit la seule propriété qui compte — le bloc
       adresse est aligné sous la ville — en laissant varier ce qui a masqué le défaut : le
       nombre d'espaces qu'un lecteur laisse traîner devant « Lyon ». Un test bâti sur un PDF
       du corpus n'aurait rien prouvé : il aurait mesuré ce lecteur-là, ce jour-là.
    """
    colonne = 17 + len('- Compte de Gestion 1er Trimestre 2026 -') + espaces_avant_ville
    return '\n'.join([
        ' ' * 17 + 'COMPTE PERSONNEL 01220000',
        ' ' * 17 + '- Compte de Gestion 1er Trimestre 2026 -'
                 + ' ' * espaces_avant_ville + 'Lyon, le 31/03/2026',
    ] + [''] * lignes_vides_avant_adresse + [
        ' ' * colonne + 'M. et Mme DEMDOUM LAID',
        ' ' * colonne + 'RIYADH ARABIE SAOUDIENNE',
    ])


@cas('la ville ancre la colonne, quel que soit le nombre d’espaces devant elle',
     'Le 03/09/2026, le moteur lisait 0 propriétaire sur TOUT le corpus LYON. La classe de '
     'caractères de `RE_VILLE_DATE` acceptait l’espace en tête : la capture commençait 26 '
     'colonnes trop à gauche, l’écart au bloc adresse dépassait la tolérance, et chaque nom '
     'était rejeté en silence. Un autre binaire, plus avare en espaces, masquait le défaut — '
     'on a d’abord cru à un choix de lecteur.')
def _():
    for espaces in (1, 5, 26, 40):
        lu = P0._proprietaire_lyon(_page_ics(espaces))
        assert lu == 'M. et Mme DEMDOUM LAID', (espaces, lu)


@cas('un nom de ville ne commence jamais par une espace',
     'La capture restait juste, mais sa POSITION mentait — et c’est la position qui sert à '
     'aligner le bloc adresse. Une capture dont le premier caractère est une espace est un '
     'repère faux qui a l’air vrai.')
def _():
    m = P0.RE_VILLE_DATE.search(' ' * 26 + 'Lyon, le 31/03/2026')
    assert m, 'la ville n’est plus reconnue'
    assert m.group(1) == 'Lyon', repr(m.group(1))
    assert m.start(1) == 26, m.start(1)


@cas('le lecteur exigé est absent : panne explicite, jamais de remplaçant',
     'Un `if exe:` suivi d’un `import pdfplumber` faisait changer d’outil en silence : autre '
     'texte, autres empreintes, vingt-huit fois plus lent — et rien à l’écran. Un lecteur est '
     'un composant du résultat : son absence doit ressembler à une panne.')
def _():
    try:
        P0.lecteur_resolu({'produit': 'lecteur-qui-nexiste-pas', 'version': '', 'mode': '-layout'})
    except P0.LecteurIndisponible as e:
        assert 'INTROUVABLE' in str(e), str(e)
        return
    assert False, 'aucune erreur levée : la substitution muette est de retour'


@cas('un mode que le binaire ne connaît pas est une panne, pas un repli',
     'Le moteur lançait `-table` sur poppler — qui ne l’a pas —, échouait, et retombait sur '
     '`-layout` sans le dire : le repli que la doctrine chiffre à 307 rattachements au lieu '
     'de 1 022, invisible dans le résultat.')
def _():
    sans_table = [f for f in P0.lecteurs_disponibles() if not f['table']]
    if not sans_table:
        return                      # aucun binaire dépourvu de `-table` sur ce poste
    try:
        P0.lecteur_resolu({'produit': sans_table[0]['produit'], 'version': '', 'mode': '-table'})
    except P0.LecteurIndisponible as e:
        assert 'INCOMPLET' in str(e), str(e)
        return
    assert False, 'un mode absent a été accepté'


@cas('le contrat de lecture est déclaré, pas déduit de l’environnement',
     'Deux binaires répondent au nom `pdftotext` et ne lisent pas la même page. Laisser '
     'l’ordre du PATH trancher faisait produire deux empreintes différentes à la même '
     'analyse, et le sceau accusait le moteur.',)
def _():
    c = P0.CRG_LECTEUR_CONTRAT
    assert set(c) >= {'produit', 'mode'}, c
    assert c['produit'] in ('poppler', 'xpdf'), c['produit']
    assert c['mode'] in ('-layout', '-table'), c['mode']
    exe, etiquette, _t = P0.lecteur_resolu()
    assert c['produit'] in etiquette, (etiquette, c['produit'])


def principal():
    sys.stdout.reconfigure(encoding='utf-8') if hasattr(sys.stdout, 'reconfigure') else None
    ok = ko = 0
    echecs = []
    for titre, incident, fn in CAS:
        try:
            fn()
            ok += 1
            print('  OK   %s' % titre)
        except AssertionError as e:
            ko += 1
            echecs.append((titre, incident, str(e)))
            print('  ÉCHEC %s' % titre)
    print('\nROBUSTESSE : %d/%d' % (ok, ok + ko))
    for titre, incident, e in echecs:
        print('\n  ÉCHEC — %s\n    incident défendu : %s\n    %s' % (titre, incident, e))
    return 0 if ko == 0 else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(principal())
