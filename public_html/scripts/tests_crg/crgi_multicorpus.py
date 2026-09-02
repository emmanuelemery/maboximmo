# -*- coding: utf-8 -*-
"""
MULTI-CORPUS — une règle propre à un éditeur ne doit jamais s'appliquer à un autre.
═══════════════════════════════════════════════════════════════════════════════════════════

⚠️ CE FICHIER EXISTE PARCE QUE LES RÈGLES SONT NÉES D'UN SEUL DOCUMENT. Chacune a été
   arrachée au dépôt VIENNE `septeo_spi` : l'en-tête qui se répète, le bandeau « Page N », le
   bloc « Suite », les colonnes à leur x. Rien ne garantissait qu'elles ne mordraient pas sur
   les CRG `lyon`, qui vivent dans le même corpus — l'import n°3 en porte 16 à côté de 7
   `septeo_spi`.

⚠️ CHAQUE RÈGLE PORTE SA PORTÉE :
       TRANSVERSALE — vraie de tout CRG, quel que soit l'éditeur ;
       ÉDITEUR      — vraie du logiciel qui a produit le document ;
       FORMAT       — vraie d'une mise en page donnée ;
       AGENCE       — vraie d'un émetteur.
   Une règle FORMAT appliquée hors de son format ne produit pas une erreur : elle produit une
   donnée fausse, silencieusement. C'est exactement ce que « Carte professionnelle » avait
   fait — 222 faux débuts de CRG à partir d'un pied de page que TOUTE agence imprime.

Usage : python crgi_multicorpus.py
"""
import os
import sys

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RACINE)

import crg_integration_phase0 as P0     # noqa: E402
import crg_integration_phase4 as P4     # noqa: E402
from crg_integration_lots import segments_de_lot   # noqa: E402

CAS = []


def cas(titre, portee, incident):
    def deco(fn):
        CAS.append((titre, portee, incident, fn))
        return fn
    return deco


# ── FIXTURES MINIMALES, UNE PAR ÉDITEUR ───────────────────────────────────────────────────
SEPTEO = """                                                                        FNAIM
Régie EMERY
             COMPTE RENDU DE GESTION
Agence: A3 - REGIE EMERY - VIENNE                        BASIE Mona
Période du 01/03/2026 au 31/05/2026                      30, Rue Marchande
Identifiant extratnet : 1105403390                       38200 VIENNE
Mot de passe : 3523
Immeuble 71 RUE MARCHANDE - 38200 VIENNE
Local commercial 1 Pièce - Lot 01G01-5213-000367- Mandat N/A - Bat - Esc
Locataire: DAKHOUCH Hind, Bail du 03/05/2019
         Carte Professionnelle CP/ 6901 2018 000 031 709 - APE 6831 Z
"""

LYON = """                          COMPTE RENDU DE GESTION
                          - 1er Trimestre 2026 -
COMPTE PERSONNEL 01220000
Monsieur DUPONT Jean
Lyon, le 29/06/2026
         Carte Professionnelle CP/ 6901 2018 000 031 709 - APE 6831 Z
"""

PIED_SEUL = """         Agence REGIE EMERY _ NEI _ 31 rue Victor Hugo 38200 VIENNE
         SARL REGIE EMERY siège social 10 place Maréchal Foch 69630 CHAPONOST
         Carte Professionnelle CP/ 6901 2018 000 031 709 - APE 6831 Z
"""


@cas('un début septeo se reconnaît à ses trois signaux', 'FORMAT',
     'Titre + agence + identifiant extranet. Deux sur trois ne suffisent pas.')
def _():
    etat, fmt, _m = P0.qualifier(SEPTEO)
    assert (etat, fmt) == ('DEBUT', 'septeo_spi'), (etat, fmt)


@cas('REFUS — un pied de page n’ouvre aucun CRG', 'TRANSVERSALE',
     'Toute agence immobilière française imprime « Carte professionnelle » en pied de page. '
     'S’en servir a fabriqué 222 faux débuts `lyon` sur le document VIENNE.')
def _():
    etat, fmt, _m = P0.qualifier(PIED_SEUL)
    assert etat == 'INCONNU', (etat, fmt)


@cas('un en-tête lyon est un CANDIDAT, pas un début', 'FORMAT',
     'Chez `lyon` l’en-tête de régie se RÉPÈTE sur toutes les pages de garde d’un même CRG : '
     'le prendre pour un début a fabriqué 22 CRG là où il n’y en avait qu’un.')
def _():
    etat, fmt, _m = P0.qualifier(LYON)
    assert (etat, fmt) == ('ENTETE_REGIE', 'lyon'), (etat, fmt)


@cas('REFUS — le trimestre imprimé ne s’applique pas à septeo', 'ÉDITEUR',
     'La lecture du trimestre et de la date d’édition appartient à `lyon`. L’appliquer à '
     'septeo daterait la situation de gestion sur l’humeur de l’imprimante.')
def _():
    d = P0.identifier(LYON, 'lyon')
    assert d.get('periode_cle_imprimee') == '2026-T1', d.get('periode_cle_imprimee')
    assert d.get('date_edition') == '2026-06-29', d.get('date_edition')
    # le même texte lu comme septeo ne doit produire NI trimestre NI date d'édition
    d2 = P0.identifier(LYON, 'septeo_spi')
    assert 'periode_cle_imprimee' not in d2, d2
    assert 'date_edition' not in d2, d2


@cas('REFUS — la lecture du propriétaire septeo ne mord pas sur lyon', 'ÉDITEUR',
     'Chez septeo le propriétaire suit le titre sur la même ligne ; chez lyon il se lit '
     'autrement. Croiser les deux attribuerait un CRG au mauvais mandant.')
def _():
    d = P0.identifier(SEPTEO, 'septeo_spi')
    assert d['proprietaire'] and 'BASIE' in d['proprietaire'], d['proprietaire']
    assert d['compte'] == '1105403390', d['compte']
    d2 = P0.identifier(LYON, 'lyon')
    assert d2['compte'] == '01220000', d2['compte']


@cas('la période lue vaut pour les deux éditeurs', 'TRANSVERSALE',
     '« Période du … au … » et l’arrêté qui en découle ne dépendent d’aucun format.')
def _():
    d = P0.identifier(SEPTEO, 'septeo_spi')
    assert d['periode_debut'] == '2026-03-01' and d['periode_fin'] == '2026-05-31', d
    assert d['date_arrete'] == d['periode_fin']


@cas('REFUS — un trimestre n’en est un que s’il le couvre en entier', 'TRANSVERSALE',
     'Le document imprime « Période du 01/04/2026 au 31/05/2026 » 246 fois : l’étiqueter '
     '`2026-T2` fabriquait un trimestre à partir de deux mois.')
def _():
    assert P0.periode_cle('2026-04-01', '2026-06-30') == '2026-T2'
    assert P0.periode_cle('2026-04-01', '2026-05-31') != '2026-T2'
    assert P0.periode_cle('2026-04-01', '2026-04-30') == '2026-04'


@cas('la segmentation des lots vaut pour tout CRG qui en imprime', 'TRANSVERSALE',
     'Le motif « - Lot … - Mandat » ne dépend pas de l’éditeur ; ce qui en dépendait, '
     'c’était d’avoir trois moteurs différents pour le lire.')
def _():
    segs = segments_de_lot(SEPTEO, 1)
    assert len(segs) == 1 and segs[0]['reference'] == '01G01-5213-000367', segs
    assert segs[0]['locataire'] == 'DAKHOUCH Hind', segs[0]['locataire']
    # un CRG lyon qui n'imprime pas de bloc de lot n'en produit aucun — et pas une erreur
    assert segments_de_lot(LYON, 1) == []


@cas('REFUS — la grille de colonnes est propre à sa mise en page', 'FORMAT',
     'Les x relevés (258/309/360/411/487/564) valent pour cette mise en page. Un montant '
     'qui n’y tombe pas devient INDETERMINABLE — il n’est jamais rangé dans la voisine.')
def _():
    assert P4.colonne_de({'text': '1,00', 'x1': 258, 'x0': 230}) == 'loyers'
    for x in (200, 286, 340, 440, 520, 600):
        assert P4.colonne_de({'text': '1,00', 'x1': x, 'x0': x - 30}) is None, x


# ═════════════════════════════════════════════════════════════════════════════════════════
#  LE LANCEUR DE COMPATIBILITÉ DOIT SAVOIR DIRE NON
# ═════════════════════════════════════════════════════════════════════════════════════════

import crg_compatibilite as COMPAT   # noqa: E402


def _analyser(pages):
    """Fait analyser des pages fabriquées, sans PDF ni écriture."""
    vrai0, vrai3 = COMPAT.P0.lire_pages, COMPAT.lire_tableau
    COMPAT.P0.lire_pages = lambda _c: (pages, 'fixture')
    COMPAT.lire_tableau = lambda _c: (pages, 'fixture')
    try:
        return COMPAT.analyser('fixture.pdf')
    finally:
        COMPAT.P0.lire_pages, COMPAT.lire_tableau = vrai0, vrai3


@cas('le lanceur reconnaît un dépôt lisible', 'TRANSVERSALE',
     'Un CRG septeo complet, avec ses sections connues, doit passer.')
def _():
    page = SEPTEO + chr(10).join(['- Honoraires de Gestion -',
                                  '30/04/2026 Honoraires Revenus Fonciers 20,00', ''])
    r = _analyser([page])
    assert r['verdict'] == 'COMPATIBLE — IMPORT POSSIBLE', (r['verdict'], r['exceptions'])


@cas('REFUS — une section inconnue arrête le lanceur', 'TRANSVERSALE',
     'Une structure dont la nature métier n’est pas démontrable doit être ISOLÉE et nommée, '
     'jamais interprétée par approximation.')
def _():
    page = SEPTEO + chr(10).join(['- Provision Exceptionnelle Travaux -',
                                  '30/04/2026 Quelque chose 20,00', ''])
    r = _analyser([page])
    assert r['verdict'] == 'NOUVELLE STRUCTURE — ANALYSE NÉCESSAIRE', r['verdict']
    assert any('Provision Exceptionnelle' in e for e in r['exceptions']), r['exceptions']


@cas('REFUS — un document sans couche texte ne conclut pas', 'TRANSVERSALE',
     '`UN DOCUMENT SANS COUCHE TEXTE N’EST PAS UN DOCUMENT SANS CRG` : le premier dépôt réel '
     'était un « Print To PDF » de 906 pages d’images. Annoncer « 0 CRG » aurait été un '
     'mensonge.')
def _():
    r = _analyser(['', '   ', ''])
    assert r['verdict'] == 'NOUVELLE STRUCTURE — ANALYSE NÉCESSAIRE', r['verdict']
    assert any('couche texte' in e for e in r['exceptions']), r['exceptions']


@cas('REFUS — des montants sans bloc de lot perdent la maille', 'TRANSVERSALE',
     '`LA MAILLE D’AFFICHAGE NE PEUT JAMAIS ÊTRE PLUS FINE QUE LA MAILLE DE LA PREUVE` : des '
     'montants qu’on ne sait rattacher à rien ne s’intègrent pas.')
def _():
    page = SEPTEO.replace('Local commercial 1 Pièce - Lot 01G01-5213-000367- Mandat N/A - Bat - Esc',
                          '') + chr(10) + 'Loyer Avril 2026 474,49' + chr(10)
    r = _analyser([page])
    assert r['couverture']['lots'] == 0, r['couverture']
    assert any('maille de la preuve' in e for e in r['exceptions']), r['exceptions']


def principal():
    ok = ko = 0
    echecs = []
    portees = {}
    for titre, portee, incident, fn in CAS:
        portees[portee] = portees.get(portee, 0) + 1
        try:
            fn()
            ok += 1
            print('  OK   [%-12s] %s' % (portee, titre))
        except AssertionError as e:
            ko += 1
            echecs.append((titre, portee, incident, str(e)))
            print('  ÉCHEC [%-12s] %s' % (portee, titre))
    print('\nMULTI-CORPUS : %d/%d   (%s)'
          % (ok, ok + ko, ' · '.join('%s %d' % (p, n) for p, n in sorted(portees.items()))))
    for titre, portee, incident, e in echecs:
        print('\n  ÉCHEC — [%s] %s\n    incident défendu : %s\n    %s'
              % (portee, titre, incident, e))
    return 0 if ko == 0 else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(principal())
