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
                for colonne in ('loyers', 'taxes', 'provisions'):
                    montant = m.get(colonne) or 0.0
                    if not montant:
                        continue
                    out.append(_mvt(page, 'SITUATION DES LOCATAIRES', nom_im,
                                    lot.get('reference'), occupant, m.get('du'),
                                    libelle, colonne, montant, 'LOT'))
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


def _ligne_argent(ligne, page, immeuble, maille):
    """Une ligne à deux colonnes — débit ou crédit — telle que le lecteur l'a lue."""
    debit = ligne.get('debit') or 0.0
    credit = ligne.get('credit') or 0.0
    if not debit and not credit:
        return None
    return _mvt(page, _section_lisible(ligne.get('entete')), immeuble,
                None, None, None, normaliser(ligne.get('libelle') or ''),
                'debit' if debit else 'credit', debit or credit, maille)


def _mvt(page, section, immeuble, lot, locataire, date, libelle, colonne, montant, maille):
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
        'categorie': 'INDETERMINABLE',
        'maille': maille,
        'motif': 'Section d’un éditeur dont le vocabulaire n’est pas encore arbitré : la '
                 'nature de ce montant demande une décision, elle ne se déduit pas du libellé.',
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
