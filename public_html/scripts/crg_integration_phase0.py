# -*- coding: utf-8 -*-
"""
PHASE 0 — RECONSTRUIRE LES CRG LOGIQUES D'UN PDF, AVANT TOUTE LECTURE MÉTIER.

⚠️ `FICHIER PHYSIQUE ≠ CRG MÉTIER`. Un PDF déposé peut contenir zéro, un, ou deux cents comptes
   rendus, de plusieurs agences et de plusieurs mois. Rien ici ne suppose qu'un fichier vaut un
   CRG, une agence ou une période : tout est DÉTECTÉ page par page, et ce qui ne se détecte pas
   se déclare.

⚠️ CHAQUE PAGE EST AFFECTÉE, OU DÉCLARÉE NON AFFECTÉE. Jamais rattachée « par défaut » au CRG
   précédent. Une page orpheline visible se corrige ; une page absorbée en silence contamine un
   compte rendu entier sans que personne ne le sache. C'est pourquoi la sortie porte
   `pages_affectees` ET `pages_non_affectees` : leur somme doit retomber sur le nombre de pages.

⚠️ FAIL CLOSED. On n'attrape jamais `Exception`. Les seules erreurs tolérées sont celles qu'un
   DOCUMENT peut provoquer — fichier absent, PDF corrompu. Une `TypeError` vient de notre code :
   elle doit remonter et faire rougir l'appelant, pas se déguiser en « 0 CRG détecté ».

⚠️ CE MOTEUR NE LIT AUCUN MONTANT. Il découpe et il identifie : agence, période, arrêté,
   propriétaire, compte. La lecture métier reste le travail des lecteurs certifiés P1→P9, et
   elle n'a lieu qu'après validation humaine de cette phase.

Sortie : un seul objet JSON sur la sortie standard.
Usage : python crg_integration_phase0.py <chemin.pdf>
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from crg_format import famille_du_texte   # noqa: E402  — l'autorité unique de reconnaissance
from crg_periode import fin_de_trimestre  # noqa: E402  — l'autorité unique de période
from crg_texte import (ECART_COLONNE_DROITE, RE_DEBUT_CELLULE, bloc_borne,   # noqa: E402
                       colonne_du_texte, contient_montant, depuis_la_colonne,
                       en_colonnes, est_champ_entete, est_ligne_de_tableau,
                       marge_gauche, normaliser)

# ── Les seules erreurs qu'un DOCUMENT peut provoquer ──────────────────────────────────────
try:
    from pdfminer.pdfparser import PDFSyntaxError
    from pdfminer.psparser import PSException
    ERREURS_PDF = (OSError, PDFSyntaxError, PSException)
except ImportError:                       # pdfminer absent : on garde au moins les erreurs OS
    ERREURS_PDF = (OSError,)


# ══════════════════════════════════════════════════════════════════════════════════════════
#  LES SIGNAUX IMPRIMÉS — relevés sur des CRG réels, jamais devinés
# ══════════════════════════════════════════════════════════════════════════════════════════
#
#  septeo_spi (VIENNE, CHAPONOST…), page 1, lecture `-layout` :
#      COMPTE RENDU DE GESTION                    SCI GEORGE BLS
#      Agence : A3 - REGIE EMERY - VIENNE             65 Rue VICTOR HUGO
#      Période du 01/04/2026 au 30/04/2026            38200 VIENNE
#      Identifiant extratnet : 1105406704
#  et sur ses pages suivantes, un bandeau de rappel :
#      Compte rendu de gestion ROSIER … du 01/04/2026 au 30/04/2026 Page 2
#
#  lyon / emery_immo, page 1 : l'en-tête de la régie (raison sociale, carte professionnelle,
#  garantie financière), puis, sur une même ligne :
#      COMPTE PERSONNEL 01040000        COMPTE RENDU DE GESTION
#      - 2e Trimestre 2026 -
#      - Compte de Gestion 2e Trimestre ex 2026 -   Lyon, le 29/06/2026

RE_TITRE = re.compile(r'COMPTE\s+RENDU\s+DE\s+GESTION', re.I)
RE_AGENCE = re.compile(r'Agence\s*:\s*(.+)')
RE_EXTRANET = re.compile(r'Identifiant\s+extra?n?tnet\s*:\s*(\d+)')
RE_PERIODE = re.compile(r'P[ée]riode\s+du\s+(\d{2}/\d{2}/\d{4})\s+au\s+(\d{2}/\d{2}/\d{4})')
# ⚠️ L'OCR SOUDE LES MOTS. Le bandeau de suite s'imprime « … au 31/05/2026 Page 2 » sur le PDF
#    natif, mais « Page2 » après passage à l'OCR. Exiger une espace a laissé 222 pages de suite
#    orphelines sur le document réel — un défaut d'une seule espace, invisible à la lecture.
RE_BANDEAU = re.compile(r'Compte\s+rendu\s+de\s+gestion\s+.*\bPage\s*\d+', re.I)
RE_CARTE = re.compile(r'Carte\s+professionnelle|Garantie\s+Financi[èe]re', re.I)
# ⚠️ UN NOM DE VILLE NE COMMENCE PAS PAR UNE ESPACE. La classe autorisait l'espace en tête et
#    le moteur, cherchant au plus à gauche, avalait jusqu'à trente espaces AVANT « Lyon ». La
#    capture restait juste, mais sa POSITION mentait de vingt-six colonnes — et c'est sur cette
#    position que la lecture du propriétaire s'aligne. Le premier caractère doit être une
#    lettre : la position redevient alors celle du texte imprimé.
RE_VILLE_DATE = re.compile(
    r'([A-Za-zÉÈÀÂÎÔÛéèàâîôû][A-Za-zÉÈÀÂÎÔÛéèàâîôû\'\- ]{2,29}),\s*le\s+(\d{2}/\d{2}/\d{4})')
# ⚠️ RELEVÉ SUR LE DOCUMENT, PAS DEVINÉ. `-layout` fait apparaître, sur la ligne du titre,
#    « COMPTE PERSONNEL 01040000 » : c'est le compte mandant de la famille lyon.
RE_COMPTE_LYON = re.compile(r'COMPTE\s+PERSONNEL\s+(\d{6,10})', re.I)
RE_TRIMESTRE = re.compile(r'-\s*(\d)\s*(?:er|ère|e|ème)?\s+Trimestre\s+(\d{4})\s*-', re.I)
# ⚠️ LE MÊME DOCUMENT ÉCRIT AUSSI « 1T2025 » SUR SA PROPRE LIGNE. Ne chercher que la forme
#    longue laissait huit CRG sans période — donc non validables — pour un défaut de lecture,
#    pas une lacune du document.
RE_TRIM_COMPACT = re.compile(r'^\s*(\d)T(\d{4})\s*$', re.M)
# ⚠️ ET UNE TROISIÈME GRAPHIE : « 2ème TRIM 2025 ». Le même logiciel écrit son trimestre de
#    trois façons différentes selon les millésimes. Chacune est relevée sur le document ;
#    aucune n'est supposée.
RE_TRIM_ABREGE = re.compile(r'^\s*(\d)\s*(?:er|ère|e|ème)?\s+TRIM\.?\s+(\d{4})\s*$', re.I | re.M)
# ⚠️ L'ÉCART EST FAIT D'ESPACES, PAS DE « BLANC ». `\s{2,}` inclut le saut de ligne : quand
#    l'extracteur ne préserve pas les colonnes, « COMPTE RENDU DE GESTION » se retrouve seul
#    sur sa ligne et le motif saute à la SUIVANTE — il a lu « Agence: A3 - REGIE EMERY -
#    VIENNE » comme nom de propriétaire sur 288 des 325 CRG du dépôt du 02/09/2026, sans
#    qu'aucun contrôle ne bronche : le découpage, lui, était parfaitement juste.
#    `[ \t]{2,}` dit ce qu'on veut dire — la gouttière entre deux colonnes de LA MÊME LIGNE.
RE_PROPRIO_SEPTEO = re.compile(r'COMPTE\s+RENDU\s+DE\s+GESTION[ \t]{2,}(\S.*?)\s*$',
                               re.I | re.M)
# Les champs que l'en-tête imprime lui-même : là où on les voit, on ne lit pas un nom.
RE_ENTETE_CHAMPS = re.compile(r'P[ée]riode\s+du\b|Identifiant\s+extra?n?tnet|Mot\s+de\s+passe',
                              re.I)
# ⚠️ UN CRG N'ARRIVE PAS SEUL. Quand la régie est aussi syndic, l'envoi contient des APPELS DE
#    FONDS de copropriété — 33 dans le document réel, sur une centaine de pages. Ce ne sont pas
#    des CRG, et ce ne sont pas non plus des pages perdues : les laisser « non affectées »
#    ferait ressembler un découpage juste à un défaut. On les NOMME et on les met de côté.
#    ⚠️ Le titre est exigé EN CAPITALES : le corps des lettres écrit « appel de fonds concernant
#       la résidence citée en référence », qui n'ouvre aucun document.
RE_APPEL_TITRE = re.compile(r'\bAPPEL\s+DE\s+FONDS\b')
RE_APPEL_SUITE = re.compile(r'-\s*Appel\s+de\s+fonds\s*-', re.I)

# ══════════════════════════════════════════════════════════════════════════════════════════
#  LES AUTRES DOCUMENTS DU DÉPÔT — nommés, jamais « aucun signal »
# ══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ UN DÉPÔT NE CONTIENT PAS QUE DES CRG, ET CE N'EST PAS UN DÉFAUT. Une enveloppe réelle
#    porte le compte rendu, l'appel de fonds de la copropriété, et les FACTURES des
#    prestataires. Tant que ces pages ressortent « aucun signal de CRG », un dépôt
#    parfaitement lu ressemble à un découpage raté — et personne ne peut distinguer la page
#    qu'on a su écarter de celle qu'on a manquée.
#
# ⚠️ TABLE ADDITIVE. Un type de document s'AJOUTE ici. On ne retire jamais : une reconnaissance
#    acquise qui disparaît fait retomber des pages dans le silence.
#
# ⚠️ ET LE TITRE SE RECONNAÎT PAR SA POSITION, PAS PAR SA CASSE. `APPEL DE FONDS` n'était
#    accepté qu'en capitales, pour éviter la phrase « appel de fonds concernant la résidence »
#    du corps d'une lettre. Le garde-fou était le mauvais : le corpus EMERY imprime son titre
#    « Appel de Fonds », en casse mixte — et sept pages sont restées sans nom. Ce qui distingue
#    un titre d'une phrase, c'est qu'il est EN TÊTE DE PAGE, pas qu'il crie.
LIGNES_DE_TITRE = 12          # un titre de document vit dans les premières lignes de sa page
# Une page qui porte moins que cela ne dit rien : c'est un verso, un séparateur, un artefact.
CARACTERES_PAGE_VIDE = 40

# ⚠️ LES BANDEAUX DE SECTION D'UN CRG — TABLE ADDITIVE. Le corps d'un CRG ICS n'imprime ni
#    bandeau de suite ni numéro de page : ses pages n'étaient rattachées que par CONTIGUÏTÉ.
#    Tant qu'aucun autre document ne s'intercale, cela suffit ; dès qu'un appel de fonds se
#    glisse au milieu, la contiguïté est rompue et il faut un signal pour reprendre. Ces
#    bandeaux en sont un, et ils sont imprimés : « SITUATION DES LOCATAIRES - 1er Trimestre ».
SECTIONS_CRG = [
    re.compile(r'SITUATION\s+DES\s+LOCATAIRES', re.I),
    re.compile(r'COMPTE\s+DE\s+GESTION', re.I),
]

# (clé, motif de titre, message du bilan, libellé avec son article — pour les signaux de suite)
DOCUMENTS_JOINTS = [
    ('appel_de_fonds', re.compile(r'\bAppel\s+de\s+fonds\b', re.I),
     'APPEL DE FONDS — document de syndic, hors CRG', 'un appel de fonds'),
    ('facture', re.compile(r'\bFacture\s*(?:N°|n°|no\b|N\b)', re.I),
     'FACTURE — pièce d’un prestataire jointe au dépôt, hors CRG', 'une facture'),
    # ⚠️ AJOUTÉ le 04/09/2026 — la table s'AJOUTE, elle ne se réécrit pas. Une lettre d'acompte
    #    annonce un virement au propriétaire entre deux comptes rendus. Elle était déclarée
    #    « ILLISIBLE » alors que le document avait été parfaitement lu, ligne à ligne : il
    #    n'était simplement pas un CRG. Le mot accusait le moteur d'une panne inexistante.
    ('avis_acompte',
     re.compile(r'Information\s+Acompte|Avis\s+d.?acompte|Virement\s+acompte', re.I),
     'AVIS D’ACOMPTE — lettre de versement au propriétaire, hors CRG', 'un avis d’acompte'),
]
LIBELLE_JOINT = {cle: libelle for cle, _m, _msg, libelle in DOCUMENTS_JOINTS}


def _titre_de_document(texte):
    """Le type de document annoncé EN TÊTE de cette page, s'il y en a un.

    ⚠️ ON NE LIT QUE LE HAUT DE LA PAGE. Chercher dans le texte entier ferait d'une facture
       citée au milieu d'un relevé de charges un document à part — et couperait le CRG en deux.
    """
    tete = '\n'.join(texte.split('\n')[:LIGNES_DE_TITRE])
    for cle, motif, message, _libelle in DOCUMENTS_JOINTS:
        if motif.search(tete):
            return cle, message
    return None, None


def jour(fr):
    """« 01/04/2026 » → « 2026-04-01 ». Rien d'autre n'est accepté."""
    if not fr:
        return None
    m = re.match(r'^(\d{2})/(\d{2})/(\d{4})$', fr.strip())
    return '%s-%s-%s' % (m.group(3), m.group(2), m.group(1)) if m else None


def periode_cle(debut, fin):
    """La clé de période, telle que le référentiel la nomme.

    ⚠️ ON N'INVENTE PAS UN TRIMESTRE. VIENNE est MENSUEL : un CRG du 01/04 au 30/04 est
       « 2026-04 », pas « 2026-T2 ». Écrire un trimestre là où le document dit un mois
       fusionnerait trois situations de gestion en une seule.
    """
    if not (debut and fin):
        return None
    ad, md, jd = debut.split('-')
    af, mf, jf = fin.split('-')
    # ⚠️ UN MOIS N'EST UN MOIS QUE S'IL COMMENCE LE 1er ET FINIT LE DERNIER JOUR.
    if ad == af and md == mf:
        return '%s-%s' % (ad, md)
    # ⚠️ ET UN TRIMESTRE N'EST UN TRIMESTRE QUE S'IL LE COUVRE EN ENTIER. Le document réel
    #    imprime « Période du 01/04/2026 au 31/05/2026 » 246 fois — deux mois, pas un
    #    trimestre. La règle précédente ne regardait que l'appartenance au même trimestre :
    #    elle donnait « 2026-T2 » à avril-mai COMME au trimestre complet, fusionnant deux
    #    situations de gestion distinctes sous une seule clé. C'est exactement ce que
    #    `reference_crg_periode_deux_cles` interdit.
    if ad == af and int(md) in (1, 4, 7, 10) and int(mf) == int(md) + 2 and jd == '01':
        dernier = {3: '31', 6: '30', 9: '30', 12: '31'}[int(mf)]
        if jf == dernier:
            return '%s-T%d' % (ad, (int(md) - 1) // 3 + 1)
    return '%s_%s' % (debut, fin)


# ═══ LE CONTRAT DE LECTURE — DÉCLARÉ ICI, VÉRIFIÉ À CHAQUE DÉMARRAGE ════════════════════════
#
# ⚠️ LE LECTEUR EST UN COMPOSANT DU RÉSULTAT, PAS UN DÉTAIL D'INSTALLATION. Deux programmes
#    répondent au nom `pdftotext` — poppler et Xpdf (Glyph & Cog) — et ils ne lisent pas la
#    même page. Mesuré le 03/09/2026 sur le corpus LYON : même nombre de CRG et de montants,
#    mais 306 649 caractères contre 279 215, et surtout 0 propriétaire lu contre 12. Laisser
#    l'ordre du PATH décider revenait à laisser l'environnement modifier le résultat métier.
#
# ⚠️ CHANGER CETTE DÉCLARATION CHANGE CE QUE MBI LIT. C'est une décision d'Emmanuel, prise
#    sur mesure, jamais un ajustement d'opportunité. `version` peut rester vide pour n'exiger
#    qu'un produit ; la renseigner épingle aussi la version.
CRG_LECTEUR_CONTRAT = {
    'produit': 'xpdf',       # décidé par Emmanuel le 03/09/2026, après mesure
    'version': '4.',         # préfixe : la 4.x, pas une 3.x qui lirait autrement
    'mode':    '-layout',    # ⚠️ PAS `-table` : le mode reste celui du corpus certifié
}

_PDFTOTEXT = []          # [(chemin, étiquette, sait_table)] — résolu une fois par processus.


def pdftotext_exe():
    """Le binaire `pdftotext` à employer, choisi sur sa CAPACITÉ et non sur l'ordre du PATH.

    ⚠️ DEUX PROGRAMMES DIFFÉRENTS RÉPONDENT À CE NOM, ET ILS NE LISENT PAS LA MÊME PAGE.
       Sur le poste du 02/09/2026 : `poppler 25.07` dans le PATH d'Apache, `Xpdf 4.00` (Glyph
       & Cog) en tête du PATH du shell. Ils ne placent pas l'en-tête aux mêmes colonnes, et
       Xpdf ne connaît pas `-table` — l'option dont la phase 3 tire 1 022 rattachements au
       lieu de 307. Résultat : la MÊME analyse, lancée depuis la page ou depuis le harnais,
       produisait deux empreintes différentes, et le sceau accusait le moteur.

    ⚠️ ON NE CHOISIT DONC PAS LE PREMIER VENU, ET PAS NON PLUS LE PLUS CAPABLE. Le corpus
       certifié a été lu avec **poppler** : changer de binaire changerait ses sorties. Le
       choix est donc PINGLÉ sur poppler tant qu'Emmanuel n'en décide pas autrement, et
       `CRG_PDFTOTEXT` impose un chemin quand l'exploitant veut trancher lui-même.

    ⚠️ CONSÉQUENCE ASSUMÉE ET À ARBITRER : `-table` est une option **Xpdf**, que poppler n'a
       pas. Le mode tableau de la phase 3 — celui que la doctrine chiffre à 1 022
       rattachements contre 307 — n'est donc PAS actif. Il l'était encore moins avant, quand
       le code lançait `-table` sur poppler et retombait sur `-layout` sans le dire.
    """
    if _PDFTOTEXT:
        return _PDFTOTEXT[0]
    _PDFTOTEXT.append(lecteur_resolu())
    return _PDFTOTEXT[0]


def _inspecter(exe):
    """Ce qu'un binaire EST, demandé à lui-même : produit, version, capacités.

    ⚠️ ON DEMANDE AU BINAIRE CE QU'IL SAIT FAIRE, ON NE LE DÉDUIT PAS DE SON NOM. La première
       version écrivait `sait_table = 'poppler' in version` — et c'était l'INVERSE de la
       vérité : `-table` est une option **Xpdf**, que poppler n'a pas. Le moteur lançait donc
       `-table` sur poppler, échouait, et retombait EN SILENCE sur `-layout`.
    """
    try:
        v = subprocess.run([exe, '-v'], stdout=subprocess.PIPE,
                           stderr=subprocess.STDOUT).stdout.decode('utf-8', 'replace')
    except OSError:
        return None
    produit = ('poppler' if 'poppler' in v.lower() else
               'xpdf' if 'glyph' in v.lower() else 'inconnu')
    m = re.search(r'version\s+([0-9][0-9.]*)', v)
    try:
        aide = subprocess.run([exe, '-h'], stdout=subprocess.PIPE,
                              stderr=subprocess.STDOUT).stdout.decode('utf-8', 'replace')
    except OSError:
        aide = ''
    return {
        'chemin':  exe,
        'produit': produit,
        'version': m.group(1) if m else '',
        'table':   bool(re.search(r'^\s+-table\b', aide, re.M)),
        'layout':  bool(re.search(r'^\s+-layout\b', aide, re.M)),
    }


def lecteurs_disponibles():
    """Tous les `pdftotext` atteignables, inspectés. Sert au contrôle et au diagnostic."""
    vus, trouves = set(), []
    impose = os.environ.get('CRG_PDFTOTEXT')
    chemins = [impose] if impose else []
    for dossier in os.environ.get('PATH', '').split(os.pathsep):
        for nom in ('pdftotext.exe', 'pdftotext'):
            chemins.append(os.path.join(dossier, nom))
    for p in chemins:
        if not p or not os.path.isfile(p) or p.lower() in vus:
            continue
        vus.add(p.lower())
        fiche = _inspecter(p)
        if fiche:
            trouves.append(fiche)
    return trouves


class LecteurIndisponible(RuntimeError):
    """Le lecteur exigé par le contrat n'est pas là — et rien d'autre ne le remplace."""


class LectureImpossible(ValueError):
    """Le document n'a pas pu être lu — AVEC LA CAUSE, parce qu'elle commande l'action.

    ⚠️ « ILLISIBLE » CONFONDAIT TROIS ÉTATS ET ALARMAIT À TORT. Un PDF corrompu se répare,
       une numérisation s'océrise, un document étranger se range : trois actions, un seul
       mot. La cause voyage donc avec l'erreur, et l'appelant la traduit en état. Une cause
       qu'il ne connaît pas retombe sur `ILLISIBLE` — le plus alarmant, et c'est voulu :
       on ne minimise jamais ce qu'on ne comprend pas.
    """

    def __init__(self, message, cause=None):
        super().__init__(message)
        self.cause = cause


def lecteur_resolu(contrat=None):
    """Le lecteur du CONTRAT, ou une erreur explicite. Jamais un remplaçant choisi tout seul.

    ⚠️ AUCUNE BASCULE SILENCIEUSE. L'ancienne version prenait « le premier venu » du PATH, en
       épinglant poppler s'il passait par là. Deux postes, deux PATH, deux lectures — et la
       même analyse produisait deux empreintes, le sceau accusant le moteur. Un lecteur n'est
       pas un détail d'environnement : c'est un composant du résultat, au même titre qu'une
       règle métier. Il se DÉCLARE, et son absence est une panne, pas une occasion de bricoler.

    ⚠️ LE MODE FAIT PARTIE DU CONTRAT. `-table` et `-layout` ne lisent pas la même page. Exiger
       un mode que le binaire présent ne connaît pas est une panne, pas un repli.

    ⚠️ `CRG_LECTEUR` PERMET DE MESURER UN AUTRE PRODUIT, DÉLIBÉRÉMENT — un banc d'essai doit
       pouvoir comparer. Mais pendant un examen, le contrat est figé et le runner le vérifie :
       cette porte sert à mesurer, jamais à dépanner en silence.
    """
    c = dict(contrat or CRG_LECTEUR_CONTRAT)
    voulu = os.environ.get('CRG_LECTEUR')
    if voulu:
        # ⚠️ CHANGER DE PRODUIT LIBÈRE L'ÉPINGLAGE DE VERSION. Le contrat épingle « xpdf 4.x » ;
        #    garder ce « 4. » en demandant poppler exigeait un poppler 4, qui n'existe pas — la
        #    porte de mesure échouait en annonçant une absence de lecteur. Une version épinglée
        #    n'a de sens que pour le produit qu'elle accompagne.
        c['produit'] = voulu.strip().lower()
        c['version'] = ''
    trouves = lecteurs_disponibles()
    for f in trouves:
        if f['produit'] != c['produit']:
            continue
        if c.get('version') and not f['version'].startswith(c['version']):
            continue
        if c['mode'] == '-table' and not f['table']:
            raise LecteurIndisponible(
                'LECTEUR INCOMPLET — %s %s (%s) ne connaît pas l’option « -table » exigée par '
                'le contrat de lecture.' % (f['produit'], f['version'], f['chemin']))
        return (f['chemin'], '%s %s' % (f['produit'], f['version']), f['table'])

    raise LecteurIndisponible(
        'LECTEUR INTROUVABLE — le contrat exige « %s%s » en mode « %s ». Trouvés : %s. '
        'Aucune substitution n’est faite : un autre binaire ne lit pas la même page, et une '
        'bascule muette rendrait les empreintes incomparables. Installez le lecteur attendu, '
        'ou imposez son chemin par CRG_PDFTOTEXT.'
        % (c['produit'], (' ' + c['version']) if c.get('version') else '', c['mode'],
           ', '.join('%s %s (%s)' % (f['produit'], f['version'], f['chemin'])
                     for f in trouves) or 'aucun'))


def lire_pages(chemin):
    """Le texte de chaque page, dans l'ordre, et le nom de l'outil qui l'a produit.

    ⚠️ LE CHOIX DE L'OUTIL DÉCIDE SI LA PAGE EST EXPLOITABLE EN LIGNE. Mesuré sur un document
       réel de 914 pages : pdfplumber **360 s**, pypdf **177 s**, `pdftotext` **13 s**. Une
       analyse de dix minutes n'est pas une page d'administration, c'est un traitement de
       nuit. On prend donc `pdftotext -layout`, qui sépare les pages par un saut de page
       (\\x0c) et respecte les colonnes — indispensable pour lire « COMPTE PERSONNEL 01040000 »
       à gauche et « COMPTE RENDU DE GESTION » à droite sur la même ligne.

    ⚠️ ET S'IL EST ABSENT, ON LE DIT. Le repli pdfplumber existe — l'hébergement mutualisé n'a
       pas toujours le binaire — mais il est vingt-huit fois plus lent : le taire ferait passer
       une installation incomplète pour une lenteur inexplicable.
    """
    # ⚠️ PLUS DE REPLI MUET. Ici vivait un `if exe:` suivi d'un `import pdfplumber` : lecteur
    #    absent, le moteur changeait d'outil sans le dire et rendait un texte différent, vingt-
    #    huit fois plus lentement. Le contrat échoue désormais à voix haute — voir
    #    `lecteur_resolu()`. Une panne d'installation doit ressembler à une panne.
    exe, etiquette, _table = pdftotext_exe()
    mode = CRG_LECTEUR_CONTRAT['mode']
    r = subprocess.run([exe, mode, '-enc', 'UTF-8', chemin, '-'],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        raise ValueError('pdftotext a échoué (code %d) : %s'
                         % (r.returncode, r.stderr.decode('utf-8', 'replace')[:200]))
    pages = r.stdout.decode('utf-8', 'replace').split('\x0c')
    if pages and not pages[-1].strip():
        pages.pop()
    # ⚠️ L'ÉTIQUETTE PORTE LE PRODUIT, SA VERSION ET LE MODE, PAS « pdftotext ». Deux lectures
    #    d'un même document par deux binaires homonymes ne sont pas la même lecture, et c'est
    #    la seule ligne qui permette de s'en apercevoir.
    return pages, 'pdftotext (%s %s)' % (etiquette or 'version inconnue', mode)


def qualifier(texte):
    """Ce que la page montre : début d'un CRG, suite, autre document, ou rien de reconnaissable."""
    cle, message = _titre_de_document(texte)
    if cle:
        return 'HORS_CRG', cle, message
    if RE_APPEL_SUITE.search(texte):
        return 'HORS_CRG_SUITE', 'appel_de_fonds', 'suite d’un appel de fonds'
    # ⚠️ LA FAMILLE NE SE DÉCIDE PLUS ICI. Ce fichier avait sa propre reconnaissance, qui ne
    #    connaissait que `lyon` et `septeo_spi` — alors que le référentiel certifie TROIS
    #    familles depuis le 30/08 et possède un lecteur pour chacune. Résultat : un CRG
    #    EMERY IMMO lu avec le lecteur LYON, annoncé `CERTAIN`, et zéro montant reconnu.
    #    `crg_format.famille_du_texte` est désormais la seule autorité, partagée avec
    #    `crg_depot_lire`. Ce qui reste ici est l'ÉTAT de la page dans le dépôt — début,
    #    suite, hors CRG — que nul lecteur certifié ne détermine, parce qu'aucun ne lit
    #    autre chose qu'un document déjà isolé.
    famille = famille_du_texte(texte)
    if famille == 'septeo_spi' and RE_EXTRANET.search(texte) and RE_TITRE.search(texte):
        return 'DEBUT', 'septeo_spi', 'en-tête complet : titre, agence, identifiant extranet'
    if famille == 'emery_immo' and RE_TITRE.search(texte) and RE_COMPTE_LYON.search(texte):
        # ⚠️ EMERY IMPRIME « COMPTE PERSONNEL » COMME LYON. C'est l'enseigne qui les sépare,
        #    jamais la structure : c'est en testant la structure d'abord qu'on a rangé
        #    224 CRG EMERY certifiés dans la famille LYON.
        return 'ENTETE_REGIE', 'emery_immo', 'en-tête EMERY IMMO : titre et compte personnel'
    if famille == 'lyon' and RE_TITRE.search(texte) and RE_COMPTE_LYON.search(texte):
        # ⚠️ CANDIDAT, PAS DÉBUT. Chez `lyon` l'en-tête de régie se RÉPÈTE sur toutes les pages
        #    de garde d'un même CRG : les pages 16 à 19 du document d'essai le portent quatre
        #    fois. Le prendre pour un début a fabriqué 22 CRG là où il n'y en avait qu'un.
        #    C'est la RUPTURE qui fait le début — voir `analyser()`.
        #
        # ⚠️ ET LE MARQUEUR EST « COMPTE PERSONNEL », PAS « CARTE PROFESSIONNELLE ». La
        #    première version se contentait de la carte professionnelle : or TOUTE agence
        #    immobilière française l'imprime en pied de page. Sur le document VIENNE, cela a
        #    fabriqué 222 faux débuts `lyon` à partir de pieds de page. Le numéro de compte
        #    personnel, lui, n'appartient qu'à cette famille de CRG.
        return 'ENTETE_REGIE', 'lyon', 'en-tête lyon : titre et compte personnel'
    if RE_BANDEAU.search(texte):
        return 'SUITE', None, 'bandeau « Compte rendu de gestion … Page N »'
    # ⚠️ EN TÊTE DE PAGE, comme un titre de document : un bandeau de section cité au milieu
    #    d'un tableau de charges ne rouvre pas une suite de CRG.
    tete = '\n'.join(texte.split('\n')[:LIGNES_DE_TITRE])
    for motif in SECTIONS_CRG:
        if motif.search(tete):
            return 'SUITE', None, 'bandeau de section du compte rendu'
    if RE_TITRE.search(texte):
        # ⚠️ LE TITRE SEUL NE SUFFIT PAS. Il apparaît aussi dans un courrier d'accompagnement
        #    ou sur une page de garde. On le signale sans ouvrir un CRG sur cette seule base.
        return 'INDETERMINABLE', None, 'titre présent, mais ni agence ni identifiant'
    return 'INCONNU', None, 'aucun signal de CRG'


def identifier(texte, format_detecte):
    """Agence, période, arrêté, propriétaire, compte — lus, jamais déduits."""
    d = {'agence': None, 'periode_debut': None, 'periode_fin': None, 'date_arrete': None,
         'proprietaire': None, 'compte': None, 'format': format_detecte, 'immeuble': None}

    m = RE_AGENCE.search(texte)
    if m:
        d['agence'], reste = _couper_agence(m.group(1))
        # ⚠️ SUR UN DOCUMENT OCRISÉ, LE PROPRIÉTAIRE ARRIVE ICI. L'OCR aplatit les deux
        #    colonnes sur une seule ligne : « Agence : A3 - REGIE EMERY - VIENNE Monsieur
        #    XERRI Florent ». Ce qui suit l'agence est donc le début du bloc adresse.
        #
        # ⚠️ MAIS CE QUI SUIT L'AGENCE N'EST PAS TOUJOURS UN NOM. Quand l'extracteur replie
        #    tout l'en-tête sur une ligne, on lit « … - VIENNE Période du 01/07/2026 au
        #    31/07/2026 Identifiant extratnet : 1105402916 Mot de passe: 1101 » — et ce
        #    galimatias partait en base comme nom de propriétaire. Un champ du document ne
        #    peut pas être le nom de quelqu'un : quand on les reconnaît, ON NE LIT RIEN.
        #    `ABSENCE DE LECTURE ≠ LECTURE APPROXIMATIVE`.
        if reste and not RE_ENTETE_CHAMPS.search(reste):
            d['proprietaire'] = reste
    m = RE_PERIODE.search(texte)
    if m:
        d['periode_debut'] = jour(m.group(1))
        d['periode_fin'] = jour(m.group(2))
        # ⚠️ LA DATE D'ARRÊTÉ EST LA FIN DE PÉRIODE TANT QUE LE DOCUMENT N'EN IMPRIME PAS
        #    D'AUTRE. C'est une lecture, pas un calcul : on ne la déplace jamais.
        d['date_arrete'] = d['periode_fin']

    m = RE_EXTRANET.search(texte) or RE_COMPTE_LYON.search(texte)
    if m:
        d['compte'] = m.group(1)

    if format_detecte == 'septeo_spi':
        d['proprietaire'] = _proprietaire_septeo(texte) or d['proprietaire']
    else:
        m = (RE_TRIMESTRE.search(texte) or RE_TRIM_COMPACT.search(texte)
             or RE_TRIM_ABREGE.search(texte))
        if m:
            # ⚠️ « Lyon, le 29/06/2026 » EST UNE DATE D'ÉDITION, PAS UN ARRÊTÉ : la confondre
            #    daterait la situation de gestion sur l'humeur de l'imprimante. Mais ne rien
            #    poser du tout était pire, et je l'ai laissé faire : **235 CRG d'un corpus
            #    entier sont entrés sans date d'arrêté**, là où l'autre famille n'en avait pas
            #    un seul. La clé d'identité d'une occupation devenait alors vide, et soixante
            #    faux conflits d'occupant en sont sortis — soixante questions sur des gens qui
            #    n'ont rien à voir les uns avec les autres.
            #
            # ⚠️ LE TRIMESTRE IMPRIMÉ, LUI, EST UNE ÉNONCIATION. « - 2e Trimestre 2026 - » dit
            #    la période ; sa fin s'en déduit par le calendrier, sans rien supposer. C'est
            #    la règle que le lecteur certifié applique déjà, et elle vit désormais dans
            #    l'autorité de période — recopiée, elle aurait dérivé.
            d['periode_cle_imprimee'] = '%s-T%s' % (m.group(2), m.group(1))
            d['date_arrete'] = fin_de_trimestre(m.group(2), m.group(1))
            d['date_arrete_source'] = 'trimestre imprimé'
        m = RE_VILLE_DATE.search(texte)
        if m:
            d['date_edition'] = jour(m.group(2))
            d['proprietaire'] = _proprietaire_lyon(texte)
    return d


def _proprietaire_septeo(texte):
    """Le propriétaire d'un CRG `septeo_spi` : la première ligne du bloc adresse, à droite.

    ⚠️ DEUX OUTILS PORTENT LE NOM `pdftotext`, ET ILS NE RENDENT PAS LA MÊME PAGE. Xpdf 4.00
       met « COMPTE RENDU DE GESTION » et « Monsieur XERRI Florent » SUR LA MÊME LIGNE ;
       poppler 25.07 met le titre seul et descend le nom de deux lignes. Le moteur lisait la
       fin de la ligne du titre : juste avec l'un, faux avec l'autre — et c'est celui d'Apache
       qui était faux. Résultat le 02/09/2026 : 288 CRG sur 325 ont pris « Agence: A3 - REGIE
       EMERY - VIENNE » pour un nom de propriétaire, sans qu'aucun contrôle ne bronche.

    ⚠️ ON LIT LA COLONNE, PAS LA LIGNE. Le bloc adresse est dans la colonne de DROITE de
       l'en-tête ; c'est vrai des deux rendus, et c'est une propriété de l'impression, pas une
       supposition. La marge de gauche se mesure sur le document (« Agence: … »), elle n'est
       pas écrite en dur : on retient le premier fragment situé nettement à sa droite.

    ⚠️ ET UN CHAMP DE L'EN-TÊTE N'EST JAMAIS UN NOM. « Période du … », « Identifiant
       extratnet : … », « Mot de passe: … » sont écartés explicitement. S'il ne reste rien,
       on ne rend RIEN : `ABSENCE DE LECTURE ≠ LECTURE APPROXIMATIVE`.
    """
    lignes = texte.split('\n')
    depart = next((i for i, l in enumerate(lignes) if RE_TITRE.search(l)), None)
    if depart is None:
        return None

    # La marge de gauche du bloc d'en-tête, relevée sur le document lui-même.
    marge = None
    for ligne in lignes[depart:depart + 14]:
        m = RE_AGENCE.search(ligne)
        if m:
            marge = m.start()
            break
    if marge is None:
        marge = len(lignes[depart]) - len(lignes[depart].lstrip())

    # ⚠️ ON PREND TOUT CE QUI EST À DROITE DE LA COLONNE, PAS « LA PREMIÈRE CELLULE ». La
    #    version précédente découpait la ligne sur « deux espaces ou plus » et gardait le
    #    premier fragment. Cela suppose que l'extracteur n'aère jamais l'intérieur d'une
    #    cellule — il l'aère : mesuré le 03/09/2026, « Madame CAISSE  Corinne » se coupait en
    #    deux et le prénom disparaissait. Seize noms tronqués sur un corpus, dix-neuf sur un
    #    autre, sans un signal. Un champ d'identité amputé n'est pas une lecture partielle :
    #    c'est une autre personne.
    # ⚠️ UN BLOC ADRESSE EST PLUSIEURS LIGNES ALIGNÉES — c'est ce qui le distingue d'un résidu.
    #    Deux essais ont échoué avant celui-ci, et chacun disait quelque chose :
    #      · « le premier fragment au-delà du seuil » prenait, sur une page dont l'extracteur
    #        avait éclaté l'en-tête en trois morceaux, le débris du milieu (« DUII nn ») ;
    #      · « le fragment le plus à droite » prenait une colonne de montants du tableau
    #        (« 108, 18 », « 611, 12 ») — car la colonne la plus à droite d'un en-tête n'est
    #        pas toujours l'adresse.
    #    Ce qui identifie le bloc adresse n'est ni sa position ni son rang : c'est qu'il tient
    #    sur PLUSIEURS lignes à la même abscisse. Un débris est seul sur la sienne.
    colonnes = {}
    for brute in lignes[depart:depart + 14]:
        # ⚠️ UNE LIGNE DU TABLEAU N'EST PAS UN BLOC ADRESSE — SAUF CELLE DU TITRE. La ligne
        #    « COMPTE RENDU DE GESTION » porte le nom du propriétaire à sa droite sur une
        #    partie des gabarits ; l'écarter parce qu'elle contient un mot du vocabulaire de
        #    tableau faisait remonter la LIGNE SUIVANTE du bloc adresse — donc la rue au lieu
        #    du nom. Dix-huit « propriétaires » qui étaient des adresses.
        if est_ligne_de_tableau(brute) and not RE_TITRE.search(brute):
            continue
        ligne = en_colonnes(brute)
        for m in RE_DEBUT_CELLULE.finditer(ligne):
            if m.start() < marge + ECART_COLONNE_DROITE:
                continue
            fragment = depuis_la_colonne(ligne, m.start())
            if fragment and not RE_ENTETE_CHAMPS.search(fragment):
                colonnes.setdefault(m.start(), []).append(fragment)
            break
    if not colonnes:
        return None
    # Le plus de lignes l'emporte ; à égalité, la plus à gauche — l'adresse précède le tableau.
    colonne = min(colonnes, key=lambda c: (-len(colonnes[c]), c))
    return colonnes[colonne][0]


def _proprietaire_lyon(texte):
    """Le propriétaire d'un CRG `lyon` : le bloc adresse, à droite de « Ville, le … ».

    ⚠️ LA LIGNE SUIVANTE N'EST PAS TOUJOURS LE PROPRIÉTAIRE. Un CRG de décembre intercale
       « ******** BONNES FETES DE FIN D'ANNEE ********* », un autre laisse remonter
       « COMPTE PERSONNEL 04680000 ». Prendre la première ligne venue a produit ces deux
       noms-là, qui n'en sont pas.

    ⚠️ ON SE SERT DE LA COLONNE, PARCE QUE `-layout` LA PRÉSERVE. Le bloc adresse est aligné
       sous « Ville, le … » : on ne retient qu'une ligne commençant à la même abscisse, à
       quelques espaces près. C'est une propriété de la mise en page, pas une devinette.

    ⚠️ LA COLONNE EST CELLE DU TEXTE IMPRIMÉ, JAMAIS CELLE DU DÉBUT DE LA CAPTURE. Le
       03/09/2026, ce lecteur rendait **0 propriétaire sur tout le corpus LYON** : la capture
       commençait vingt-six colonnes trop à gauche, l'écart au bloc adresse dépassait la
       tolérance, et chaque nom était rejeté en silence. Un banc d'essai de lecteurs l'a
       révélé — un autre binaire, plus avare en espaces, masquait le défaut. On prend donc la
       position du GROUPE, et on la recale sur sa première lettre : ce que la page montre.
    """
    lignes = texte.split('\n')
    for i, ligne in enumerate(lignes):
        m = RE_VILLE_DATE.search(ligne)
        if not m:
            continue
        # ⚠️ « NETTEMENT À DROITE DE LA MARGE », PAS « ALIGNÉ SUR L'ANCRE ». Aligner le bloc
        #    adresse sur la colonne du repère supposait que deux LIGNES gardent leurs positions
        #    relatives d'un extracteur à l'autre. Elles ne les gardent pas : mesuré le
        #    03/09/2026, le même repère est colonne 140 sous un rendu et colonne 43 sous
        #    l'autre, tandis que le bloc reste, lui, colonne 140 puis 87. Le seuil se mesure
        #    donc sur la marge du document — la seule chose qui ne bouge pas.
        seuil = marge_gauche(lignes[:i + 12]) + ECART_COLONNE_DROITE
        for _j, suivante in bloc_borne(lignes, i, _fin_entete_ics):
            if not suivante.strip() or est_ligne_de_tableau(suivante):
                continue
            # ⚠️ UNE LIGNE DE L'AUTRE COLONNE N'EST PAS UNE FRONTIÈRE. L'en-tête ICS est sur
            #    DEUX colonnes : mentions légales et « COMPTE PERSONNEL » à gauche, date et
            #    bloc adresse à droite. On ignore la colonne de gauche et on continue — la
            #    fenêtre de cinq lignes, elle, s'arrêtait dessus et rendait zéro.
            if colonne_du_texte(suivante) < seuil:
                continue
            candidat = normaliser(suivante)
            if candidat.startswith('*') or RE_COMPTE_LYON.search(candidat):
                continue
            if est_champ_entete(candidat) or contient_montant(candidat):
                continue
            return candidat
        return None
    return None


def _fin_entete_ics(ligne):
    """La frontière basse de l'en-tête ICS : là où le corps de la lettre commence.

    ⚠️ C'EST LA STRUCTURE QUI BORNE, PAS UN NOMBRE DE LIGNES. Le bloc adresse se trouve à
       quatre lignes du repère sur un gabarit, à SIX sur un autre : une fenêtre fixe de cinq
       lisait l'un et rendait **zéro** sur l'autre — 14 propriétaires perdus sur un corpus de
       14 documents, sans un signal.

    ⚠️ ARRÊTER ET IGNORER NE SONT PAS LA MÊME CHOSE. L'en-tête ICS tient sur DEUX colonnes :
       mentions légales et « COMPTE PERSONNEL » à gauche, date et bloc adresse à droite. Ma
       première version faisait de « COMPTE PERSONNEL » une frontière — elle arrêtait donc le
       parcours sur une ligne de l'AUTRE colonne, une ligne avant le bloc cherché, et
       reproduisait exactement le défaut qu'elle devait corriger. Seule la formule d'appel
       ferme l'en-tête ; tout le reste s'ignore et on continue.
    """
    return bool(RE_CIVILITE_CORPS.match(normaliser(ligne)))


# La formule d'appel qui ouvre le corps de la lettre : elle clôt le bloc d'en-tête.
RE_CIVILITE_CORPS = re.compile(r'(Madame|Monsieur|Messieurs|Mesdames)\s*,', re.I)


def _couper_agence(brut):
    """Sépare « A3 - REGIE EMERY - VIENNE » de ce qui la suit sur la même ligne.

    ⚠️ DEUX MISES EN PAGE, UNE SEULE RÈGLE. Sur un PDF natif, `-layout` sépare les colonnes
       par plusieurs espaces et il suffit de couper là. Sur un PDF OCRisé, les colonnes sont
       APLATIES : « Agence : A3 - REGIE EMERY - VIENNE Monsieur XERRI Florent » tient sur une
       ligne, et l'agence héritait du nom du propriétaire — deux champs faux d'un coup.

    ⚠️ ON COUPE SUR LA CASSE, PAS SUR UN DICTIONNAIRE DE CIVILITÉS. Le nom d'agence est en
       capitales, le bloc adresse commence par un mot capitalisé (« Monsieur », « Madame »,
       un prénom). Une liste de civilités raterait le premier cas non prévu ; la casse, elle,
       est une propriété de l'impression. Une agence au nom en casse mixte serait tronquée —
       aucune des huit agences MBI n'est dans ce cas, et la coupure resterait visible.
    """
    # ⚠️ ON COUPE LES COLONNES AVANT DE NORMALISER, PAS APRÈS. La normalisation écrasait les
    #    espaces multiples — c'est-à-dire le séparateur de colonnes lui-même — et la coupure
    #    par colonnes ne pouvait plus jamais s'appliquer. Elle était du code mort, et tout
    #    retombait sur l'heuristique de casse : « … - VIENNE BASIE » pour agence, « Mona »
    #    pour propriétaire.
    original = str(brut or '').replace('	', '    ')
    par_colonnes = [p for p in re.split(r'\s{2,}', original.strip()) if p.strip()]
    if len(par_colonnes) > 1:
        return (' '.join(par_colonnes[0].split()).strip(),
                ' '.join(' '.join(par_colonnes[1:]).split()).strip() or None)
    brut = ' '.join(original.split())
    mots = brut.split(' ')
    for i, mot in enumerate(mots):
        # ⚠️ LA CASSE NE SUFFIT PAS : LE BLOC ADRESSE COMMENCE SOUVENT PAR UN CODE POSTAL.
        #    « Agence: A3 - REGIE EMERY - VIENNE 38200 VIENNE » est tout en capitales et
        #    chiffres : rien ne coupait, et le libellé d'agence héritait de l'adresse du
        #    propriétaire — 53 variantes d'une seule agence sur le document réel.
        # ⚠️ ET LE CODE POSTAL COLLE PARFOIS À SA VILLE. « 42130AILLEUX » n'est pas cinq
        #    chiffres exactement : exiger `^\d{5}$` laissait passer l'adresse entière dans le
        #    nom d'agence. De même, un mot en minuscules — « chez » — n'appartient jamais à un
        #    nom d'agence, qui s'imprime en capitales.
        if i and (re.match(r'^[A-ZÉÈÀÂÎÔÛ][a-zéèàâîôûç]', mot)
                  or re.match(r'^\d{5}', mot)
                  or re.match(r'^[a-zéèàâîôûç]', mot)):
            return ' '.join(mots[:i]).strip(), ' '.join(mots[i:]).strip() or None
    return brut, None


def certifier(info, signal_motif):
    """Le niveau de certitude, et POURQUOI.

    ⚠️ UN CRG MAL DÉCOUPÉ QUI SE PRÉSENTE COMME CERTAIN EST PIRE QU'UN CRG SIGNALÉ
       INDÉTERMINABLE : le second se corrige, le premier se propage.
    """
    a_periode = bool(info.get('periode_debut') or info.get('periode_cle_imprimee'))
    manque = []
    if not info.get('agence') and info.get('format') == 'septeo_spi':
        manque.append('agence')
    if not info.get('compte'):
        manque.append('compte')
    if not a_periode:
        manque.append('période')
    if not manque:
        return 'CERTAIN', signal_motif + ' ; compte et période lus'
    if info.get('compte') or a_periode:
        return 'PROBABLE', signal_motif + ' ; manque : ' + ', '.join(manque)
    return 'INDETERMINABLE', signal_motif + ' ; manque : ' + ', '.join(manque)


def analyser(chemin):
    """Découpe le PDF en CRG logiques. Rend un objet sérialisable, jamais un texte."""
    if not os.path.isfile(chemin):
        raise ValueError('PDF INTROUVABLE / ANALYSE IMPOSSIBLE : %s' % chemin)

    textes, outil = lire_pages(chemin)

    # UN DOCUMENT SANS COUCHE TEXTE N'EST PAS UN DOCUMENT SANS CRG. Le vrai document de
    # 906 pages est un « Microsoft: Print To PDF » : chaque page est une image, et
    # `pdftotext` en tire ZERO caractere. Sans ce controle, le moteur rendait « 0 CRG
    # detecte » — un resultat rassurant pour une panne totale de lecture. C'est
    # exactement ce que la doctrine FAIL CLOSED interdit depuis P7.
    caracteres = sum(len(x.strip()) for x in textes)
    if textes and caracteres == 0:
        # ⚠️ ET LA CAUSE SE NOMME, ELLE NE SE DEVINE PAS DANS LE MESSAGE. « Illisible » et
        #    « lisible par l'oeil mais pas par la machine » appellent deux actions
        #    differentes — reparer, ou lancer un OCR. Sans cause typee, l'appelant les
        #    confondait sous le mot le plus alarmant, et quatre numerisations passaient pour
        #    une panne du moteur.
        raise LectureImpossible(
            'AUCUNE COUCHE TEXTE / LECTURE IMPOSSIBLE — les %d pages de ce PDF sont des '
            'images (document scanne ou imprime en PDF). Aucun signal ne peut y etre lu sans '
            'OCR. Ce n est pas un document sans CRG : c est un document illisible en l etat.'
            % len(textes), cause='SANS COUCHE TEXTE')

    pages, crgs, courant = [], [], None
    precedent_entete = False
    # ⚠️ CE QUI N'EST PAS UN CRG N'EST PAS POUR AUTANT UNE PAGE PERDUE. `hors_crg` porte le
    #    document de syndic en cours : ses pages sont nommées, comptées à part, et ne
    #    rejoignent JAMAIS un compte rendu de gestion. Sans lui, un appel de fonds inséré au
    #    milieu d'un CRG serait avalé par ce CRG — le pire des deux mondes.
    hors_crg = None
    for no, texte in enumerate(textes, 1):
        etat, fmt, motif = qualifier(texte)
        if etat in ('DEBUT', 'ENTETE_REGIE'):
            hors_crg = None
        if etat == 'HORS_CRG':
            # ⚠️ UN DOCUMENT INSÉRÉ SUSPEND LE CRG, IL NE LE CLÔT PAS. On écrivait ici
            #    « le CRG en cours est terminé » — et une enveloppe réelle a montré le
            #    contraire : compte rendu, appel de fonds glissé au milieu, PUIS la suite du
            #    même compte rendu. Refermer le CRG a laissé quatre pages de situation
            #    locative orphelines, étiquetées « avant le premier en-tête » alors qu'elles
            #    venaient après. Le CRG est mis en attente ; il ne reprend que sur un signal
            #    de CRG démontré, jamais sur la simple contiguïté.
            hors_crg = fmt or 'document joint'
            pages.append({'page_no': no, 'crg_index': None, 'signal': motif})
            precedent_entete = False
            continue
        if etat == 'HORS_CRG_SUITE' or (hors_crg and not texte.strip()):
            hors_crg = hors_crg or 'appel_de_fonds'
            pages.append({'page_no': no, 'crg_index': None,
                          'signal': motif if etat == 'HORS_CRG_SUITE'
                          else 'page blanche (verso de '
                               + LIBELLE_JOINT.get(hors_crg, 'un document joint') + ')'})
            precedent_entete = False
            continue
        if hors_crg and etat == 'INCONNU':
            # ⚠️ UN DOCUMENT JOINT A LUI AUSSI DES PAGES DE SUITE. On ne continuait que les
            #    pages BLANCHES : une facture de deux pages laissait sa seconde sans nom, un
            #    appel de fonds son récapitulatif. Le document est ouvert, la page ne porte
            #    aucun signal de compte rendu : elle lui appartient — et le signal DIT que
            #    c'est de la contiguïté, pas une preuve imprimée. Le CRG, lui, reprend dès
            #    qu'un de ses propres bandeaux réapparaît.
            #
            # ⚠️ ET UNE PAGE QUASI VIDE N'OUVRE RIEN. Le corpus en porte qui ne contiennent
            #    qu'un sigle de trois lettres — un artefact de numérisation.
            libelle = LIBELLE_JOINT.get(hors_crg, 'un document joint')
            vide = len(normaliser(texte)) < CARACTERES_PAGE_VIDE
            pages.append({'page_no': no, 'crg_index': None,
                          'signal': ('page quasi vide, dans ' + libelle) if vide else
                                    ('page contiguë d’' + libelle
                                     + ' — aucun marqueur de suite imprimé')})
            precedent_entete = False
            continue
        if etat == 'DEBUT':
            info = identifier(texte, fmt)
            cle = info.get('periode_cle_imprimee') or periode_cle(info['periode_debut'],
                                                                  info['periode_fin'])
            # ⚠️ L'EN-TÊTE SEPTEO SE RÉPÈTE SUR CHAQUE PAGE DU MÊME CRG. Sur le document réel,
            #    365 pages le portent pour ~220 comptes rendus : le compte 1105406704 le
            #    répète aux pages 443, 445 et 447. Traiter chaque en-tête comme un début
            #    fabriquait un CRG par page. Un en-tête qui répète LE MÊME compte ET LA MÊME
            #    période que le CRG en cours en est la suite, pas un nouveau.
            if (courant is not None
                    and courant.get('format') == 'septeo_spi'
                    and info.get('compte')
                    and info['compte'] == courant.get('compte')
                    and cle == courant.get('periode_cle')):
                courant['page_fin'] = no
                courant.setdefault('_textes', []).append(texte)
                pages.append({'page_no': no, 'crg_index': len(crgs) - 1,
                              'signal': 'en-tête répété — même compte, même période'})
                precedent_entete = False
                continue
            certitude, raison = certifier(info, motif)
            courant = {k: v for k, v in info.items() if k != 'periode_cle_imprimee'}
            courant.update(page_debut=no, page_fin=no, periode_cle=cle,
                           certitude=certitude, motif=raison)
            courant['_textes'] = [texte]
            crgs.append(courant)
            pages.append({'page_no': no, 'crg_index': len(crgs) - 1, 'signal': motif})
        elif etat == 'ENTETE_REGIE':
            # Début SEULEMENT si la page précédente ne portait pas déjà l'en-tête : c'est la
            # rupture avec le corps du CRG précédent qui ouvre le suivant.
            if precedent_entete and courant is not None:
                courant['page_fin'] = no
                courant.setdefault('_textes', []).append(texte)
                pages.append({'page_no': no, 'crg_index': len(crgs) - 1,
                              'signal': 'page de garde répétée du même CRG'})
            else:
                info = identifier(texte, fmt)
                certitude, raison = certifier(info, motif)
                cle = info.get('periode_cle_imprimee')
                courant = {k: v for k, v in info.items() if k != 'periode_cle_imprimee'}
                courant.update(page_debut=no, page_fin=no, periode_cle=cle,
                               certitude=certitude, motif=raison)
                crgs.append(courant)
                pages.append({'page_no': no, 'crg_index': len(crgs) - 1, 'signal': motif})
        elif etat == 'SUITE' and courant is not None:
            # Un signal de CRG démontré referme le document inséré : le compte rendu reprend.
            hors_crg = None
            courant['page_fin'] = no
            courant.setdefault('_textes', []).append(texte)
            pages.append({'page_no': no, 'crg_index': len(crgs) - 1, 'signal': motif})
        elif not texte.strip() and courant is not None:
            # ⚠️ UNE PAGE BLANCHE EST LE VERSO DE LA PRÉCÉDENTE, PAS UNE PAGE PERDUE. Le
            #    document imprimé en PDF en compte 221 : les laisser non affectées ferait
            #    afficher « 221 pages sans CRG » sur un document parfaitement découpé. Elle
            #    rejoint le CRG en cours, et son signal dit qu'elle est vide.
            courant['page_fin'] = no
            courant.setdefault('_textes', []).append(texte)
            pages.append({'page_no': no, 'crg_index': len(crgs) - 1,
                          'signal': 'page blanche (verso)'})
        elif (courant is not None and hors_crg is None
                and courant.get('format') in ('lyon', 'emery_immo')):
            # ⚠️ ICI LE DOCUMENT N'IMPRIME AUCUN MARQUEUR DE SUITE, ET ON LE DIT. Chez `lyon`,
            #    le corps du rapport ne porte ni bandeau ni numéro de page : la page appartient
            #    au CRG ouvert parce qu'un rapport imprimé est CONTIGU, pas parce qu'un signal
            #    l'a prouvé. Le signal l'écrit, pour que personne ne lise « démontré » là où il
            #    faut lire « déduit de la contiguïté ».
            courant['page_fin'] = no
            courant.setdefault('_textes', []).append(texte)
            pages.append({'page_no': no, 'crg_index': len(crgs) - 1,
                          'signal': 'page contiguë — aucun marqueur de suite imprimé'})
        else:
            # ⚠️ NI RATTACHÉE, NI OUBLIÉE. Une page sans signal reconnaissable reste visible
            #    dans le bilan, avec son numéro : c'est la seule façon d'affirmer « 0 page
            #    perdue » sans mentir.
            pages.append({'page_no': no, 'crg_index': None,
                          # ⚠️ « AVANT LE PREMIER EN-TÊTE » ÉTAIT FAUX DÈS QU'UN DOCUMENT
                          #    S'INTERCALAIT : la page venait APRÈS, et le bilan accusait un
                          #    découpage qui n'avait rien fait de mal. Un signal doit décrire
                          #    ce qui est, pas ce qu'on suppose.
                          'signal': motif + (' (avant le premier en-tête)'
                                             if courant is None and not crgs
                                             else ' (après un document joint)' if hors_crg
                                             else ' (hors CRG)')})
        precedent_entete = (etat == 'ENTETE_REGIE')

    # UNE CLE NE PROUVE PAS UNE REEDITION — Emmanuel, 01/09/2026. `compte x periode x arrete`
    # est un excellent CONTROLE, jamais une identite metier universelle. Avant qu'une
    # occurrence puisse etre dite reenoncee, il faut que son CONTENU concorde : sinon une cle
    # identique ecraserait un CRG complementaire, ce qui est precisement le piege que la
    # rectification FOCH/SABY a mis au jour. On calcule donc une empreinte du texte lu.
    for c in crgs:
        lu = ' '.join(' '.join((c.pop('_textes', None) or [])).split())
        c['empreinte'] = hashlib.sha256(lu.encode('utf-8')).hexdigest() if lu else None
        c['caracteres_lus'] = len(lu)

    affectees = sum(1 for p in pages if p['crg_index'] is not None)
    # ⚠️ TROIS SORTS POSSIBLES POUR UNE PAGE, ET ILS SE COMPTENT SÉPARÉMENT : rattachée à un
    #    CRG, appartenant à un document identifié qui n'est pas un CRG, ou vraiment sans
    #    identification. Fondre les deux derniers ferait passer 100 appels de fonds
    #    parfaitement reconnus pour 100 pages en échec de lecture.
    hors = sum(1 for p in pages
               if p['crg_index'] is None and 'appel de fonds' in p['signal'].lower())
    return {
        'chemin': chemin,
        'outil': outil,
        'nb_pages': len(pages),
        'pages': pages,
        'crgs': crgs,
        'stats': {
            'pages_analysees': len(pages),
            'pages_affectees': affectees,
            'pages_hors_crg': hors,
            'pages_non_affectees': len(pages) - affectees - hors,
            'caracteres': sum(len(t.strip()) for t in textes),
            'crg_detectes': len(crgs),
            'crg_certains': sum(1 for c in crgs if c['certitude'] == 'CERTAIN'),
            'crg_probables': sum(1 for c in crgs if c['certitude'] == 'PROBABLE'),
            'crg_indeterminables': sum(1 for c in crgs if c['certitude'] == 'INDETERMINABLE'),
            'chevauchements': _chevauchements(crgs),
        },
    }


def _chevauchements(crgs):
    """Deux CRG ne peuvent pas revendiquer la même page. On le vérifie au lieu de l'affirmer."""
    vues, doubles = set(), 0
    for c in crgs:
        for p in range(c['page_debut'], c['page_fin'] + 1):
            if p in vues:
                doubles += 1
            vues.add(p)
    return doubles


def main():
    if len(sys.argv) < 2:
        sys.stderr.write('usage : crg_integration_phase0.py <chemin.pdf>\n')
        return 2
    try:
        resultat = analyser(sys.argv[1])
    except ValueError as e:
        # Une lecture impossible se declare ; elle ne se deguise pas en resultat vide — et
        # elle dit POURQUOI, parce que la cause commande l'action a mener.
        sortie = {'erreur': str(e)}
        if getattr(e, 'cause', None):
            sortie['cause'] = e.cause
        sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
        return 1
    except ERREURS_PDF as e:
        sys.stdout.write(json.dumps({'erreur': 'PDF ILLISIBLE / ANALYSE IMPOSSIBLE : %s' % e},
                                    ensure_ascii=False))
        return 1
    sys.stdout.write(json.dumps(resultat, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
