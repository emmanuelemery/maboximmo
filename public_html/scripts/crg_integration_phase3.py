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
from crg_integration_phase0 import lire_pages as _lire_layout   # noqa: E402


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
    exe = shutil.which('pdftotext')
    if exe:
        r = subprocess.run([exe, '-table', '-enc', 'UTF-8', chemin, '-'],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if r.returncode == 0:
            pages = r.stdout.decode('utf-8', 'replace').split('')
            if pages and not pages[-1].strip():
                pages.pop()
            return pages, 'pdftotext -table'
    return _lire_layout(chemin)

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
