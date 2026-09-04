# -*- coding: utf-8 -*-
"""
L'IDENTITÉ TEMPORELLE D'UN CRG — sa propre période, et le trimestre où elle tombe.

⚠️ TOUS LES CRG NE COUVRENT PAS UN TRIMESTRE. LYON et EMERY en émettent un par trimestre de
   gestion — le document nomme lui-même « - 2e Trimestre 2026 - ». VIENNE, sur SEPTEO SPI, en
   émet un PAR MOIS : les 70 pièces de référence sont des relevés d'avril 2026, clôturés au
   30/04. Forcer « 2026-T2 » comme identité de ces documents ferait trois dégâts :
     · trois relevés mensuels d'un même compte porteraient le même nom GED chaque trimestre ;
     · « le CRG T2 du compte X » n'aurait plus de référent unique ;
     · et l'on comparerait un mois de VIENNE à un trimestre de LYON comme si c'était la même
       chose — l'erreur la plus coûteuse, parce qu'elle ne se voit pas dans un total.

⚠️ DEUX CLÉS, DEUX USAGES, ET ON NE LES CONFOND PAS :

     `periode_cle`    l'IDENTITÉ du document, dans sa propre granularité — « 2026-T1 » pour un
                      CRG trimestriel, « 2026-04 » pour un relevé mensuel. C'est elle qui
                      nomme le fichier GED et qui distingue deux documents.
     `trimestre_cle`  le TRIMESTRE DE RATTACHEMENT, toujours renseigné — « 2026-T2 ». C'est lui
                      qui permet de grouper, comparer et historiser à granularité commune.

   `periode_type` dit laquelle des deux est l'identité : 'trimestre' ou 'mois'. Un écran qui
   agrège doit lire `trimestre_cle` ; un écran qui désigne un document doit lire `periode_cle`.

⚠️ ET RIEN NE S'INVENTE. `periode_source` dit toujours d'où vient la période :
     'trimestre imprime'   le document nomme son trimestre (« - 2e Trimestre 2026 - »,
                           « 3ème TRIM 2025 », « 1T2025 ») — le cas le plus sûr.
     'periode imprimee'    le document imprime ses deux bornes (« Période du 01/04/2026 au
                           30/04/2026 ») — la granularité se lit dessus.
     'cloture imprimee'    ni l'un ni l'autre, mais une date de clôture en toutes lettres
                           (« CRG au 15.03.2026 »). Le document est alors daté par sa clôture
                           et rien de plus : sa granularité reste inconnue.
     'indeterminable'      aucune des trois. On ne remplit rien.

⚠️ LE TRIMESTRE SE PREND SUR LA CLÔTURE, JAMAIS SUR LE DÉBUT. Six relevés de VIENNE impriment
   une période qui déborde leur mois — l'un court du 25/06/2025 au 30/04/2026 parce qu'il
   rattrape des arriérés. C'est la clôture qui date le document ; le débordement est signalé
   par `periode_debordante`, il n'est pas effacé.

⚠️ `date_arrete` RESTE UNE MÉTADONNÉE COMPLÉMENTAIRE. Elle dit à quelle date exacte un solde
   ou un encours a été photographié — indispensable pour interpréter un stock, jamais
   suffisant pour dater le document. Les avoir confondues a déjà coûté 62 dates fausses.
"""
import re

_RE_JMA = re.compile(r'^(\d{1,2})/(\d{1,2})/(\d{4})$')


def _lire(v):
    """« 30/04/2026 » → (30, 4, 2026), ou None si ce n'est pas une date écrite ainsi."""
    m = _RE_JMA.match(str(v or '').strip())
    return (int(m.group(1)), int(m.group(2)), int(m.group(3))) if m else None


def cle_trimestre(annee, trimestre):
    """« 2026-T2 » — la clé de rattachement, commune à tous les formats."""
    if not annee or not trimestre:
        return None
    return '%04d-T%d' % (int(annee), int(trimestre))


def fin_de_trimestre(annee, trimestre):
    """Le dernier jour d'un trimestre, en ISO. « 2026, 2 » → « 2026-06-30 ».

    ⚠️ CE N'EST PAS UNE DEVINETTE, C'EST UN CALENDRIER. Un document qui imprime « - 2e
       Trimestre 2026 - » énonce sa période ; sa fin s'en déduit sans rien supposer. C'est la
       source la plus sûre pour une date d'arrêté, et le lecteur certifié la préfère depuis
       qu'une recherche de date libre a ramené celle d'un REPORT — un trimestre d'écart.

    ⚠️ ELLE VIT ICI PARCE QUE DEUX MOTEURS EN ONT BESOIN. Le lecteur ICS l'appliquait en
       ligne ; la phase 0, elle, ne la connaissait pas — et **235 CRG d'un corpus entier sont
       entrés sans date d'arrêté**. La clé d'identité d'une occupation devenait alors vide, et
       soixante faux conflits d'occupant en sont sortis. Une règle de calendrier recopiée
       dérive ; une règle absente ne dérive pas, elle fait pire.
    """
    if not annee or not trimestre:
        return None
    t = int(trimestre)
    if t not in (1, 2, 3, 4):
        return None
    return '%04d-%02d-%02d' % (int(annee), t * 3, 31 if t in (1, 4) else 30)


def cle_mois(annee, mois):
    """« 2026-04 » — l'identité d'un relevé mensuel."""
    if not annee or not mois:
        return None
    return '%04d-%02d' % (int(annee), int(mois))


def normaliser_periode(meta: dict) -> dict:
    """Poser `periode_type`, `periode_cle`, `trimestre_cle`, `annee`, `trimestre`, `mois`,
       `periode_source` — et compléter `date_arrete` quand le document l'imprime sans que le
       lecteur l'ait retenue. Ce qui a déjà été LU n'est jamais écrasé."""
    if not isinstance(meta, dict):
        return meta

    # ── La date d'arrêté, quand elle est imprimée sans avoir été retenue ────────────────
    #    SEPTEO écrit « Période du 01/04/2026 au 30/04/2026 » : la borne de fin EST la date de
    #    clôture du relevé, et aucun lecteur ne la reprenait.
    if not meta.get('date_arrete') and meta.get('periode_fin'):
        meta['date_arrete'] = meta['periode_fin']
        meta.setdefault('date_arrete_source', 'fin de periode imprimee')

    # ⚠️ DEUX CHAMPS, DEUX SENS. `periode_debut/fin` est la couverture imprimee d'un releve
    #    SEPTEO — mensuel, meme quand il rattrape des mois anterieurs. `crg_periode_debut/fin`
    #    est la periode PROPRE d'un CRG LYON/EMERY hors trimestre (« CRG du 13.05.26 au
    #    30.06.26 »). Les confondre transformerait les six releves debordants de VIENNE en
    #    documents pluri-mensuels, ce qu'ils ne sont pas.
    cdeb, cfin = _lire(meta.get('crg_periode_debut')), _lire(meta.get('crg_periode_fin'))
    if cdeb and cfin:
        _, mf, af = cfin
        meta.update({'periode_type': 'periode', 'periode_source': 'periode du crg imprimee',
                     'annee': af, 'mois': None, 'trimestre': (mf + 2) // 3,
                     'trimestre_cle': cle_trimestre(af, (mf + 2) // 3),
                     'periode_cle': '%04d-%02d-%02d_%04d-%02d-%02d'
                                    % (cdeb[2], cdeb[1], cdeb[0], af, mf, cfin[0]),
                     'periode_debordante': False})
        if not meta.get('date_arrete'):
            meta['date_arrete'] = meta['crg_periode_fin']
        return meta

    deb, fin = _lire(meta.get('periode_debut')), _lire(meta.get('periode_fin'))
    arr = _lire(meta.get('date_arrete'))

    # ── 1. Le document nomme son trimestre : c'est un CRG trimestriel. ─────────────────
    if meta.get('trimestre') and meta.get('annee') \
            and meta.get('date_arrete_source') == 'trimestre imprime':
        an, tr = int(meta['annee']), int(meta['trimestre'])
        meta.update({'periode_type': 'trimestre', 'periode_source': 'trimestre imprime',
                     'mois': None, 'trimestre_cle': cle_trimestre(an, tr),
                     'periode_cle': cle_trimestre(an, tr), 'periode_debordante': False})
        return meta

    # ── 2. Le document imprime ses bornes : sa granularité se lit dessus. ──────────────
    if deb and fin:
        _, mf, af = fin
        meta.update({'periode_type': 'mois', 'periode_source': 'periode imprimee',
                     'annee': af, 'mois': mf, 'trimestre': (mf + 2) // 3,
                     'trimestre_cle': cle_trimestre(af, (mf + 2) // 3),
                     'periode_cle': cle_mois(af, mf),
                     # le relevé rattrape des mois antérieurs : on le signale, on ne l'efface pas
                     'periode_debordante': (deb[2], deb[1]) != (af, mf)})
        return meta

    # ── 3. Seule la clôture est imprimée : le document est daté, sa granularité inconnue. ─
    if arr:
        j, m, a = arr
        meta.update({'periode_type': 'arrete', 'periode_source': 'cloture imprimee',
                     'annee': a, 'mois': m, 'trimestre': (m + 2) // 3,
                     'trimestre_cle': cle_trimestre(a, (m + 2) // 3),
                     'periode_cle': '%04d-%02d-%02d' % (a, m, j),
                     'periode_debordante': False})
        return meta

    # ── 4. Le document ne dit rien. On n'invente pas. ──────────────────────────────────
    meta.update({'periode_type': None, 'periode_source': 'indeterminable',
                 'periode_cle': None, 'trimestre_cle': None, 'periode_debordante': None})
    return meta
