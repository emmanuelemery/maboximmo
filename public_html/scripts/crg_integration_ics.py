# -*- coding: utf-8 -*-
"""
LE PONT ICS — les phases d'intégration lisent par le LECTEUR CERTIFIÉ, jamais par un second.

⚠️ CE FICHIER EXISTE PARCE QUE LES PHASES 2, 3 ET 4 NE CONNAISSAIENT QU'UNE SEULE GRAMMAIRE.
   Mesuré le 03/09/2026, première passe d'apprentissage sur un corpus ICS complet : la phase 0
   identifiait 235 comptes rendus, 226 comptes mandants et 199 propriétaires — puis les phases
   suivantes rendaient **0 immeuble, 0 lot, 0 occupation**. L'empreinte de la phase 3 était
   celle de la chaîne vide. Rien n'était en panne : les primitives d'intégration lisent
   « - Lot 4000-06 – Mandat N/A », qui est le vocabulaire SPI. Un document ICS imprime
   « Lot 0003 Appart. T4 » dans un tableau, et n'a jamais été vu.

⚠️ ON NE RÉÉCRIT PAS LA GRAMMAIRE ICS, ON L'APPELLE. `crg_ics_core` est le moteur certifié de
   cette famille, avec ses variantes déclarées. En recopier les motifs ici créerait une seconde
   autorité qui divergerait de la première à la première correction — exactement le défaut qui
   a fait voir 98 lots à une phase et 124 à une autre.

⚠️ ET ON NE PROMEUT AUCUNE DONNÉE AU-DELÀ DE CE QU'ELLE DÉMONTRE. Le lecteur pose un `statut`
   de lot (« occupé », « parti ») déduit d'un montant : `P4B-OCCUPATION-16` le déclare NON
   CERTIFIÉ et non opposable. Il n'est pas repris ici. De même, les périodes `du`/`au` des
   lignes de loyer sont des périodes d'APPEL, pas des dates de bail : les rendre comme
   `bail_du` fabriquerait une chronologie que le document n'écrit pas.
"""
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import crg_ics_core as ICS                                  # noqa: E402
from crg_texte import normaliser                            # noqa: E402

# La variante à employer selon la famille reconnue par l'autorité unique.
VARIANTE = {'lyon': 'lyon', 'emery_immo': 'emery_immo'}

# Ce qui, dans une ligne de mois, décrit la LIGNE et non un montant : tout le reste est une
# colonne du tableau, quel que soit son nom — voir `mouvements()`.
STRUCTURE_MOIS = {'du', 'au', 'page', 'y', 'colle_a_l_intitule'}


def lecture(chemin, famille):
    """Le document, lu UNE fois par le moteur certifié de sa variante."""
    return ICS.parse(chemin, VARIANTE.get(famille, 'lyon'))


def patrimoine(doc, debut=1, fin=None):
    """Les immeubles et les lots que le document imprime, pour la phase 2.

    ⚠️ LA PAGE EST CELLE DU LECTEUR, SANS DÉCALAGE. Elle est déjà absolue dans le document :
       y ajouter l'offset du CRG a déjà envoyé un lien de preuve à la page 1809 d'un PDF de
       906 pages.
    """
    immeubles, lots = [], []
    for im in doc.get('immeubles') or []:
        page = int(im.get('page_debut') or 0)
        if not _dans(page, debut, fin):
            continue
        immeubles.append({
            'nom': normaliser(im.get('nom') or ''),
            'code_postal': im.get('code_postal') or '',
            'ville': normaliser(im.get('ville') or ''),
            'page': page,
            'code': im.get('code') or None,
        })
        for lot in im.get('lots') or []:
            lots.append({
                'reference': lot.get('reference') or '',
                'code_immeuble': im.get('code') or None,
                'numero': lot.get('numero_lot') or None,
                'libelle': normaliser(lot.get('type_bien') or '')[:120],
                'locataire': normaliser(lot.get('locataire_nom') or '') or None,
                'page': int(lot.get('page') or page),
            })
    return immeubles, lots


def occupations(doc, debut=1, fin=None):
    """Les occupations DÉMONTRÉES, pour la phase 3.

    ⚠️ UN OCCUPANT NOMMÉ EST UNE PREUVE ; SA DATE DE BAIL N'EN EST PAS UNE. Le document ICS
       imprime le nom du locataire en tête de son bloc de lot, et des périodes d'APPEL mois
       par mois. Rendre la première période comme date d'entrée écrirait une chronologie que
       le document n'énonce nulle part — `ABSENCE ≠ DÉPART DÉMONTRÉ` vaut aussi à l'entrée.

    ⚠️ LE `statut` DU LECTEUR N'EST PAS REPRIS. Il est déduit d'un montant de loyer appelé :
       `P4B-OCCUPATION-16` le déclare non certifié et non opposable.
    """
    obs = []
    for im in doc.get('immeubles') or []:
        for lot in im.get('lots') or []:
            page = int(lot.get('page') or im.get('page_debut') or 0)
            if not _dans(page, debut, fin):
                continue
            nom = normaliser(lot.get('locataire_nom') or '') or None
            if not nom:
                continue
            obs.append({
                'lot': lot.get('reference') or '',
                'locataire': nom,
                'bail_du': None,
                'bail_au': None,
                'rang': 0,
                # ⚠️ L'IMPAYÉ EST UN STOCK IMPRIMÉ, pas un solde reconstruit : on le rend tel
                #    quel quand le lecteur l'a lu, et on dit d'où il vient.
                'solde': lot.get('total_impaye') if lot.get('totaux_lus') else None,
                'solde_source': 'LUE' if lot.get('totaux_lus') else 'NON DEMONTRABLE',
                'page': page,
            })
    return obs


def mouvements(doc, debut=1, fin=None):
    """Les montants que le document imprime, pour la phase 4 — SANS nature.

    ⚠️ AUCUNE QUALIFICATION N'EST DÉDUITE ICI, ET C'EST VOULU. La table qui donne une nature à
       une section est celle de `crg_integration_phase4`, arbitrée section par section sur des
       documents SPI. Les en-têtes ICS sont d'un autre vocabulaire — « Entretien des communs »,
       « SOLDE MANDAT » — et les y faire entrer par ressemblance serait exactement ce que la
       doctrine interdit : `NE JAMAIS DÉDUIRE UNE NATURE D'UN LIBELLÉ`.

    ⚠️ CE N'EST PAS UNE LACUNE, C'EST LE CIRCUIT NORMAL. Ces montants sortent
       `INDETERMINABLE`, remontent dans la file d'arbitrage, s'y regroupent par section ×
       colonne × maille — une décision pour des centaines de lignes — et la table des libellés
       de section, qui est ADDITIVE, retient la réponse pour les dépôts suivants.
    """
    out = []
    for im in doc.get('immeubles') or []:
        nom_im = normaliser(im.get('nom') or '')
        for lot in im.get('lots') or []:
            page_lot = int(lot.get('page') or im.get('page_debut') or 0)
            occupant = normaliser(lot.get('locataire_nom') or '') or None
            for m in lot.get('mois') or []:
                page = int(m.get('page') or page_lot)
                if not _dans(page, debut, fin):
                    continue
                libelle = 'Du %s Au %s' % (m.get('du') or '?', m.get('au') or '?')
                # ⚠️ LES COLONNES SE DÉDUISENT, ELLES NE S'ÉNUMÈRENT PAS. La première version
                #    listait « loyers, taxes, provisions » : la colonne « Divers » du même
                #    tableau était perdue, et avec elle treize lots dont TOUT l'argent était
                #    là — 79,23 €, 36,36 €, 100,14 €… Le lot existait en phase 2 et en phase 3,
                #    et disparaissait en phase 4 sans qu'aucun contrôle ne puisse le voir
                #    autrement que par un écart de dénombrement. Le document décide de ses
                #    colonnes ; une liste écrite à la main perd toujours la suivante.
                for colonne, montant in sorted(m.items()):
                    if colonne in STRUCTURE_MOIS or not isinstance(montant, (int, float)):
                        continue
                    if not montant:
                        continue
                    categorie, motif = _nature_de_colonne(colonne)
                    out.append(_mvt(page, 'SITUATION DES LOCATAIRES', nom_im,
                                    lot.get('reference'), occupant, m.get('du'),
                                    libelle, colonne, montant, 'LOT', categorie, motif))
        for ch in im.get('charges') or []:
            page = int(ch.get('page') or im.get('page_debut') or 0)
            if not _dans(page, debut, fin):
                continue
            out.append(_ligne_argent(ch, page, nom_im, 'IMMEUBLE'))
    for op in (doc.get('mandat') or {}).get('operations') or []:
        page = int(op.get('page') or 0)
        if not _dans(page, debut, fin):
            continue
        out.append(_ligne_argent(op, page, None, 'COMPTE'))
    return [m for m in out if m]


# Un intitulé de section qui n'est qu'une date, ou une référence de pièce, n'en est pas un.
_RE_ENTETE_DATE = re.compile(r'^\(?\s*(?:du|le|au)?\s*\d{1,2}[./-]\d{1,2}[./-]\d{2,4}', re.I)


def _section_lisible(entete):
    """L'intitulé de section, ou l'aveu qu'il n'y en a pas.

    ⚠️ UNE DATE N'EST PAS UNE SECTION, ET LA PRENDRE POUR TELLE FABRIQUE DES QUESTIONS VIDES.
       Le lecteur rattache parfois une ligne d'argent à l'intitulé qui la précède, qui peut
       être une date de règlement. La file d'arbitrage se retrouvait alors à demander la nature
       de « la section Du 15.03.2026 » — cinq fois, pour cinq dates différentes, sur un même
       phénomène. Un regroupement d'arbitrage ne vaut que si sa clé a un sens : on rassemble
       donc ces lignes sous un aveu unique plutôt que sous des intitulés qui n'en sont pas.
    """
    nue = normaliser(entete or '')
    if not nue or _RE_ENTETE_DATE.match(nue):
        return '(section non imprimée)'
    return nue


# ══════════════════════════════════════════════════════════════════════════════════════════
#  LA COLONNE EST LA NATURE — règle CERTIFIÉE, pas une déduction de libellé
# ══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ J'AI FAILLI FAIRE ARBITRER 1 775 LIGNES QUI ÉTAIENT DÉJÀ TRANCHÉES. Le tableau d'appels
#    d'un lot porte ses colonnes en en-tête, et `crg_integration_phase4` les qualifie depuis
#    P5A : « Loyers » est un loyer appelé, « Charges » une provision appelée AU LOCATAIRE,
#    « Reste dû » un encours. J'ai tout marqué INDETERMINABLE « par prudence » — et la
#    prudence, ici, revenait à demander à Emmanuel de re-décider une doctrine déjà écrite.
#    `NE JAMAIS DÉDUIRE UNE NATURE D'UN LIBELLÉ` interdit de lire le TEXTE d'une ligne ; la
#    COLONNE d'un tableau, elle, est une structure imprimée, et c'est tout le contraire.
#
# ⚠️ « DIVERS » RESTE INDÉTERMINABLE, ET C'EST LA MÊME DOCTRINE QUI LE DIT. `P5A-APPEL-02` :
#    l'en-tête nomme Loyers, Taxes et Provisions — « Divers » ne nomme rien. Une colonne dont
#    l'intitulé n'annonce aucune nature ne peut pas en donner une.
NATURE_DE_COLONNE = {
    # Les colonnes structurelles du tableau du lot, quand le gabarit les imprime.
    'total':      ('AGREGAT (NON ADDITIONNABLE)',
                   'Colonne « Total » : elle récapitule les lignes du lot. Lue et conservée '
                   'pour contrôle, JAMAIS additionnée aux mouvements élémentaires.'),
    'regles':     ('ENCAISSEMENT',
                   'Colonne « Réglé » : somme reçue. Elle peut solder une période ANTÉRIEURE.'),
    'loyers':     ('LOYER APPELE',
                   'Colonne « Loyers » du tableau d’appels du lot — règle certifiée P5A.'),
    'provisions': ('CHARGE APPELEE AU LOCATAIRE',
                   'Colonne « Provisions » : provision appelée AU LOCATAIRE. Ce n’est pas une '
                   'dépense du propriétaire.'),
    'taxes':      ('AUTRE APPELE AU LOCATAIRE',
                   'Colonne « Taxes » du tableau d’appels du lot.'),
}


def _nature_de_colonne(colonne):
    """La nature que la COLONNE démontre, ou l’aveu qu’elle n’en démontre aucune."""
    return NATURE_DE_COLONNE.get(colonne, (
        'INDETERMINABLE',
        'Colonne « %s » : son intitulé n’annonce aucune nature — `P5A-APPEL-02`. Le montant '
        'est lu, conservé et rattaché à son lot ; sa nature demande un arbitrage.' % colonne))


# ══════════════════════════════════════════════════════════════════════════════════════════
#  LES FAMILLES DE DÉPENSE — on fait confiance à l'imputation du comptable
# ══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ DÉCISION MÉTIER D'EMMANUEL, 04/09/2026 : « je ne veux pas le détail des charges, je veux
#    les familles ; nous partons du principe que nous faisons confiance aux imputations des
#    CRG ». Le comptable a déjà classé sa ligne en la posant sous une section, ou en la
#    nommant. On lit CETTE imputation ; on ne la refait pas, et on ne la fait pas arbitrer.
#
# ⚠️ CE N'EST PAS « DÉDUIRE UNE NATURE D'UN LIBELLÉ ». La règle interdite consistait à lire le
#    texte d'une ligne pour lui INVENTER une nature que le document ne donnait pas. Ici, le
#    document DONNE son imputation — c'est le professionnel qui l'a écrite —, et Emmanuel a
#    tranché qu'on lui fait confiance. La différence est celle entre deviner et lire.
#
# ⚠️ TABLE ADDITIVE, ET ORDONNÉE. La première famille qui reconnaît l'emporte : « Honoraires
#    garantie des loyers » est un HONORAIRE, pas un loyer. Le `categorie()` du lecteur, lui,
#    cherchait « loyer » n'importe où et se trompait sur cette ligne précise — il n'est donc
#    pas employé.
FAMILLES = [
    # ⚠️ LA TVA AVANT LES HONORAIRES : « TVA sur hono. de gestion » contient « hono. », et la
    #    famille des honoraires l'attraperait la première. Une TVA sur honoraires est bien un
    #    frais, mais la nommer par sa propre famille garde la distinction que le comptable a
    #    écrite. L'ORDRE de cette table est une règle, pas une commodité.
    (r'\bT\.?V\.?A\b',                            'FRAIS ET ASSURANCES', 'TVA sur honoraires'),
    (r'honoraire|\bhono\.',                       'FRAIS ET ASSURANCES', 'honoraires'),
    # ⚠️ « DÉGÂTS DES EAUX » EST UN SINISTRE, PAS UNE CONSOMMATION D'EAU. La famille des
    #    fluides l'attrapait sur le mot « eaux » : un contresens qui aurait rangé une
    #    indemnisation dans les charges courantes. L'ordre de la table répare cela.
    (r'assurance|sinistre|assureur|\bGLI\b|d[ée]g[aâ]ts?\s+des\s+eaux'
     r'|garantie\s+de\s+loyer|garantie\s+loyer',   'FRAIS ET ASSURANCES', 'assurances'),
    (r'taxe|imp[oô]t|fonci[èe]re|\bTEOM\b|ordures', 'IMPOTS ET TAXES',   'impôts et taxes'),
    (r'huissier|avocat|contentieux'
     r'|frais\s+de\s+dossier|recouvrement',        'FRAIS ET ASSURANCES', 'frais de procédure'),
    (r'travaux|r[ée]paration|remplacement|pose\s+de'
     r'|menuiserie|plomberie|peinture|serrure',    'CHARGE',              'travaux'),
    (r'entretien|nettoyage|m[ée]nage|chaudi[èe]re'
     r'|ascenseur|espaces?\s+verts',               'CHARGE',              'entretien'),
    (r'\beaux?\b|[ée]lectricit|\bgaz\b|chauffage'
     r'|\bconso\b|compteur',                       'CHARGE',              'fluides'),
    (r'appels?\s+de\s+fonds?|syndic|copropri'
     r'|charges?\s+courant|charges?\s+locative',    'CHARGE',              'charges de copropriété'),
    (r'solde\s+mandat|r[èe]glement\s+virement'
     r'|versement\s+propri',                       'VERSEMENT PROPRIETAIRE',
                                                  'versement au propriétaire'),
]
_FAMILLES = [(re.compile(m, re.I), cat, nom) for m, cat, nom in FAMILLES]


def famille_de(*textes):
    """La famille que le comptable a imputée — en-tête d'abord, libellé ensuite.

    ⚠️ L'EN-TÊTE PASSE AVANT LE LIBELLÉ. Quand le comptable a posé une section, c'est SA
       classification ; le libellé de la ligne n'est que le nom du fournisseur ou de la pièce.
       Quand il n'y a pas de section, le libellé est la seule imputation dont on dispose.
    """
    for texte in textes:
        nue = normaliser(texte or '')
        if not nue:
            continue
        for motif, categorie, nom in _FAMILLES:
            if motif.search(nue):
                return categorie, nom
    return None, None


def _ligne_argent(ligne, page, immeuble, maille):
    """Une ligne à deux colonnes — débit ou crédit — telle que le lecteur l'a lue."""
    debit = ligne.get('debit') or 0.0
    credit = ligne.get('credit') or 0.0
    if not debit and not credit:
        return None
    entete = ligne.get('entete')
    libelle = normaliser(ligne.get('libelle') or '')
    colonne = 'debit' if debit else 'credit'
    categorie, famille = famille_de(entete, libelle)
    if categorie:
        motif = 'Famille « %s », imputée par le compte rendu lui-même.' % famille
    elif colonne == 'debit':
        # ⚠️ UN DÉBIT DU COMPTE MANDANT EST UNE CHARGE DU BIEN. C'est ce que le document
        #    IMPUTE en le portant dans cette colonne ; sa famille fine n'est simplement pas
        #    imprimée. Laisser ces lignes en arbitrage revenait à faire re-décider ce que le
        #    professionnel a déjà décidé.
        categorie, motif = 'CHARGE', ('Débit du compte mandant : le document l’impute en '
                                      'charge. Sa famille fine n’est pas imprimée.')
    else:
        categorie, motif = 'INDETERMINABLE', (
            'Crédit dont le document ne dit ni la section ni la famille : une entrée d’argent '
            'se qualifie, elle ne se suppose pas.')
    return _mvt(page, _section_lisible(entete), immeuble,
                None, None, None, libelle, colonne, debit or credit, maille,
                categorie, motif)


def _mvt(page, section, immeuble, lot, locataire, date, libelle, colonne, montant, maille,
         categorie='INDETERMINABLE', motif=None):
    return {
        'page': page,
        # ⚠️ LA SECTION EST PRÉFIXÉE `INCONNUE:` COMME CELLES QUE LE MOTEUR SPI NE SAIT PAS
        #    NOMMER. C'est ce préfixe que la file d'arbitrage regroupe : le vocabulaire d'un
        #    nouvel éditeur emprunte donc le chemin déjà éprouvé, sans règle nouvelle.
        'section': section if section.startswith('INCONNUE:') else 'INCONNUE:' + section,
        'immeuble': immeuble,
        'lot': lot,
        'locataire': locataire,
        'date_piece': date,
        'libelle': libelle[:220],
        'colonne': colonne,
        'montant': round(float(montant), 2),
        'x1': 0.0,
        'categorie': categorie,
        'maille': maille,
        'motif': motif or ('Section d’un éditeur dont le vocabulaire n’est pas encore arbitré : '
                           'la nature de ce montant demande une décision, elle ne se déduit '
                           'pas du libellé.'),
        'reimpression': False,
    }


def _dans(page, debut, fin):
    return page >= (debut or 1) and (fin is None or page <= fin)


def main():
    """Usage : crg_integration_ics.py <pdf> <famille> <plages.json> <patrimoine|occupations>"""
    if len(sys.argv) < 5:
        sys.stderr.write(main.__doc__ + '\n')
        return 2
    chemin, famille, fichier_plages, quoi = sys.argv[1:5]
    doc = lecture(chemin, famille)
    with open(fichier_plages, encoding='utf-8') as fh:
        plages = json.load(fh)
    sortie = []
    for p in plages:
        debut, fin = int(p['debut']), int(p['fin'])
        if quoi == 'patrimoine':
            immeubles, lots = patrimoine(doc, debut, fin)
            sortie.append({'id': p['id'], 'immeubles': immeubles, 'lots': lots})
        elif quoi == 'mouvements':
            sortie.append({'id': p['id'], 'mouvements': mouvements(doc, debut, fin),
                           'anomalies': []})
        else:
            sortie.append({'id': p['id'], 'observations': occupations(doc, debut, fin)})
    # ⚠️ `ensure_ascii=True` — voir `P1-TEC-01`. Sous Windows la sortie d'un sous-processus
    #    est en cp1252 : « ANDRÉ » revenait `ANDR�` chez l'appelant.
    sys.stdout.write(json.dumps(sortie, ensure_ascii=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
