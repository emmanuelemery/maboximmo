# -*- coding: utf-8 -*-
"""
PHASE 3 — LIRE L'OCCUPATION D'UN CRG : quel lot, quel locataire, depuis quel bail.

⚠️ CE MOTEUR NE CONCLUT RIEN SUR UNE SEULE PÉRIODE. Il relève une observation par lot et par
   CRG ; la succession locative se démontre sur la SUITE de ces observations, et ce travail
   appartient à PHP, qui voit toutes les périodes à la fois.

⚠️ L'ENCOURS N'EST PAS LISIBLE SUR CE DÉPÔT, ET ON LE DIT. `pdftotext` détache « Solde » de son
   montant **1 018 fois sur 1 018** — en lecture brute comme en `-layout`, sur le document
   océrisé comme sur un CRG natif. Le rapprocher par proximité serait une devinette : sur les
   soldes détachés, seuls 485 ont un montant en euros à moins de 400 caractères, et rien ne
   prouve que ce soit le bon. On rend donc `solde = None` avec `NON DEMONTRABLE`, plutôt qu'un
   chiffre plausible. Une lecture géométrique (pdfplumber, ~6 min sur ce document) le
   restituerait — c'est une décision, pas un défaut à masquer.

⚠️ ET UN LOT SANS LIGNE « Locataire: » N'EST PAS UN LOT VACANT. C'est un lot dont le document
   ne dit rien : `locataire = None`, et le verdict le signalera.

Usage : python crg_integration_phase3.py <chemin.pdf> <plages.json>
Sortie : un tableau JSON — une entrée par plage, avec ses observations.
"""
import shutil
import subprocess
import json
import re
import sys

sys.path.insert(0, __file__.rsplit('\\', 1)[0] if '\\' in __file__ else '.')
# ⚠️ ON N'IMPORTE PLUS `lire_pages` COMME REPLI. Le garder sous le nom `_lire_layout` faisait
#    de la dégradation silencieuse une porte toujours ouverte : le prochain `except` l'aurait
#    reprise. Ce qui reste importé, c'est le CONTRAT de lecteur, pas une issue de secours.
from crg_integration_phase0 import LecteurIndisponible   # noqa: E402
from crg_integration_phase0 import pdftotext_exe                # noqa: E402


def lire_pages(chemin):
    """Le texte de chaque page, lu en mode TABLEAU.

    ⚠️ LE MODE DE LECTURE DÉCIDE DE CE QU'ON PEUT LIRE, ET J'AI CONCLU TROP VITE. En
       `-layout`, « Solde » se retrouve seul sur sa ligne 774 fois sur 1 142 : j'en avais
       déduit que l'encours n'était « pas démontrable ». C'était faux. Le même document lu
       en `-table` — un mode que ce `pdftotext` propose et que je n'avais pas essayé —
       rattache le montant à son solde **1 022 fois**, et n'en détache plus que 307.

    ⚠️ ET ON NE CHANGE PAS LE MODE DE LA PHASE 0. Son découpage est scellé ; le relire
       autrement en périmerait la validation. La phase 3 lit pour son propre besoin.
    """
    # ⚠️ LE MÊME BINAIRE QUE LA PHASE 0, CHOISI SUR SA CAPACITÉ. `shutil.which` rendait le
    #    premier `pdftotext` du PATH : sous le harnais c'était Xpdf 4.00, qui ne connaît pas
    #    `-table` — le repli `-layout` se déclenchait EN SILENCE et la phase 2 rendait une
    #    autre empreinte que depuis la page. Le sceau le voyait ; personne ne savait pourquoi.
    # ⚠️ PLUS DE REPLI MUET VERS `-layout`. Ce `if … : … return _lire_layout(chemin)` était la
    #    plus coûteuse des dégradations silencieuses du module : quand le binaire ne connaissait
    #    pas `-table`, la phase lisait quand même — autrement — et personne ne le savait.
    #    Mesuré le 03/09/2026 sur un seul dépôt : **huit immeubles** et un occupant présents dans
    #    un mode, absents dans l'autre. Aucun contrôle aval ne pouvait le voir : la perte a lieu
    #    AVANT que les populations n'existent. Un mode de lecture fait partie du résultat ;
    #    son absence est une panne, pas une occasion de lire autrement.
    exe, etiquette, sait_table = pdftotext_exe()
    if not sait_table:
        raise LectureIndisponible(
            'MODE « -table » INDISPONIBLE — le lecteur en service est %s, qui ne le propose '
            'pas. Les phases 2, 3 et 4 lisent en mode tableau : lire autrement changerait le '
            'patrimoine et les occupations sans que rien ne le signale. Installez le lecteur '
            'déclaré par CRG_LECTEUR_CONTRAT, ou changez ce contrat en connaissance de cause.'
            % (etiquette or 'inconnu'))
    r = subprocess.run([exe, '-table', '-enc', 'UTF-8', chemin, '-'],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if r.returncode != 0:
        raise LectureIndisponible(
            'pdftotext -table a échoué (code %d) : %s'
            % (r.returncode, r.stderr.decode('utf-8', 'replace')[:200]))
    pages = r.stdout.decode('utf-8', 'replace').split('')
    if pages and not pages[-1].strip():
        pages.pop()
    return pages, 'pdftotext (%s -table)' % (etiquette or 'version inconnue')

# ⚠️ LA SEGMENTATION DES LOTS N'APPARTIENT PLUS À CETTE PHASE. Elle vit dans
#    `crg_integration_lots.py`, partagée par les phases 2, 3 et 4 : c'est parce que chacune
#    avait son propre motif que la phase 2 ne voyait que 98 lots là où celle-ci en démontrait
#    124. Une population commune se lit à un seul endroit.
from crg_integration_lots import segments_de_lot   # noqa: E402

# ⚠️ UN EN-TÊTE DE LOT MARQUÉ « Suite » N'OUVRE PAS UNE OBSERVATION. Quand le bloc d'un lot
#    déborde sur la page suivante, le document réimprime son en-tête et le termine par
#    « Suite » — sans réimprimer la ligne « Locataire: », qui figure sur la première page du
#    bloc. Les compter comme des observations créait 34 lots « sans occupant », qu'il fallait
#    ensuite arbitrer un par un. Ce n'était pas l'OCR : le document le DIT, en toutes lettres.
RE_SOLDE_COLLE = re.compile(r'Solde\s+(-?[\d  ]+,\d{2})\s*€')


def jour(fr):
    m = re.match(r'^(\d{2})/(\d{2})/(\d{4})$', (fr or '').strip())
    return '%s-%s-%s' % (m.group(3), m.group(2), m.group(1)) if m else None


def observer(textes, page_base):
    """Les observations d'occupation d'une plage de pages.

    ⚠️ UNE OBSERVATION PAR OCCUPANT, PAS PAR LOT. Un bloc peut en porter plusieurs :
       « Locataire: DE SOUSA Jordan, Bail du 01/06/2025 au 04/06/2026 » puis
       « Locataire: GONIN Marine, Bail du 22/06/2026 ». Ne garder que le premier faisait
       disparaître huit successions que le document DÉMONTRE, et la phase 4, qui gardait le
       dernier, attribuait l'argent à l'autre occupant : deux phases, deux locataires, sur la
       même page et le même lot.

    ⚠️ LE LOCATAIRE EST CELUI QUI SUIT SON LOT, PAS LE DERNIER RENCONTRÉ SUR LA PAGE. La
       segmentation vient de `crg_integration_lots`, partagée avec les phases 2 et 4.
    """
    obs = []
    for i, texte in enumerate(textes):
        page = page_base + i
        for seg in segments_de_lot(texte, page):
            ref = seg['reference']
            if seg['suite']:
                # Continuation du même lot : on complète l'observation ouverte plus haut, on
                # n'en crée pas une seconde qui paraîtrait inoccupée.
                for precedente in reversed(obs):
                    if precedente['lot'] == ref:
                        ms = RE_SOLDE_COLLE.search(seg['texte'])
                        if ms and precedente['solde'] is None:
                            precedente['solde'] = (ms.group(1).replace(' ', '')
                                                   .replace(' ', '').replace(',', '.'))
                            precedente['solde_source'] = 'LUE'
                        # ⚠️ ET L'OCCUPANT AUSSI SE LIT SUR LA CONTINUATION. Quand le bloc
                        #    d'un lot commence au BAS d'une page, sa ligne « Locataire: » ne
                        #    tient pas dessus : elle est imprimée sur la page suivante, sous
                        #    l'en-tête « Suite ». Cette branche ne complétait que le solde :
                        #    l'observation restait sans occupant alors que le document le
                        #    NOMME, et la phase 4 le lisait — d'où un occupant connu en aval
                        #    et inconnu en amont, ce que la frontière inter-phases interdit.
                        #    Constaté sur CHAPONOST (BALDYGA Axel p325→326, LECOEUVRE Arnaud
                        #    p326→327) ET sur VIENNE (MONCHANIN Francoise, lot 09 du compte
                        #    1105404307, dans deux CRG) : ce n'est pas un défaut de format,
                        #    c'est une page qui se termine.
                        # ⚠️ ON NE COMPLÈTE QUE CE QUI EST VIDE. Une observation déjà nommée
                        #    n'est jamais réécrite par sa continuation : le premier nom lu
                        #    reste celui du lot, et une succession demeure une observation à
                        #    part entière — jamais un écrasement silencieux.
                        if not precedente.get('locataire') and seg.get('locataire'):
                            precedente['locataire'] = seg['locataire']
                            if precedente.get('bail_du') is None:
                                precedente['bail_du'] = jour(seg.get('bail_du'))
                            if precedente.get('bail_au') is None:
                                precedente['bail_au'] = jour(seg.get('bail_au'))
                        break
                continue
            ms = RE_SOLDE_COLLE.search(seg['texte'])
            solde = (ms.group(1).replace(' ', '').replace(' ', '').replace(',', '.')
                     if ms else None)
            occupants = seg['occupants'] or [{'locataire': None, 'bail_du': None,
                                              'bail_au': None}]
            for rang, o in enumerate(occupants):
                obs.append({
                    'lot': ref,
                    'locataire': o['locataire'],
                    'bail_du': jour(o['bail_du']),
                    # ⚠️ « AU … » EST UNE FIN DE BAIL IMPRIMÉE : le départ est dit par le
                    #    document, on ne le déduit plus d'une absence.
                    'bail_au': jour(o['bail_au']),
                    'rang': rang,
                    # Le solde du bloc appartient au bloc, pas à un occupant en particulier :
                    # on ne le donne qu'au premier, sans quoi on le compterait deux fois.
                    'solde': solde if rang == 0 else None,
                    'solde_source': 'LUE' if (ms and rang == 0) else 'NON DEMONTRABLE',
                    'page': page,
                })
    return obs


def main():
    if len(sys.argv) < 3:
        sys.stderr.write('usage : crg_integration_phase3.py <pdf> <plages.json>\n')
        return 2
    textes, _ = lire_pages(sys.argv[1])
    with open(sys.argv[2], encoding='utf-8') as fh:
        plages = json.load(fh)
    sortie = []
    for p in plages:
        a, b = int(p['debut']), int(p['fin'])
        sortie.append({'id': p['id'], 'observations': observer(textes[a - 1:b], a)})
    sys.stdout.write(json.dumps(sortie, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(main())
