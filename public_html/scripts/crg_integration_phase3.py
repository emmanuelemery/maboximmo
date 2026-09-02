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

# ⚠️ L'OCR COUPE LES RÉFÉRENCES EN DEUX. « Lot 01 G01-5052-000115 » porte une espace au
#    milieu de sa référence. Une classe sans espace s'arrêtait sur « 01 » : quatre-vingt-dix-
#    neuf références se retrouvaient tronquées à deux caractères, et deux lots différents
#    devenaient le MÊME lot. La chronologie y voyait alors une succession de locataires là où
#    il n'y avait que deux appartements voisins.
# ⚠️ ET UNE RÉFÉRENCE D'UN SEUL CARACTÈRE EST UNE RÉFÉRENCE. Exiger trois caractères a fait
#    disparaître « - Lot 1 - Mandat N/A - » : quatre en-têtes du dépôt, tous sur le compte
#    1105404745, dont le bloc ne s'ouvrait donc JAMAIS. La phase prétendait inventorier les
#    lots ; elle en ignorait quatre. Un seuil de longueur n'est pas un critère métier.
RE_LOT = re.compile(r'-\s*Lot\s+([0-9A-Za-z][0-9A-Za-z \-]{0,28}?)-?\s*[–-]\s*Mandat')
RE_LOC = re.compile(r'Locataire\s*:\s*(.+?)\s*,\s*Bail\s+du\s+(\d{2}/\d{2}/\d{4})', re.I)
RE_LOC_SANS_DATE = re.compile(r'Locataire\s*:\s*(.+?)\s*,\s*Bail', re.I)
# ⚠️ UN EN-TÊTE DE LOT MARQUÉ « Suite » N'OUVRE PAS UNE OBSERVATION. Quand le bloc d'un lot
#    déborde sur la page suivante, le document réimprime son en-tête et le termine par
#    « Suite » — sans réimprimer la ligne « Locataire: », qui figure sur la première page du
#    bloc. Les compter comme des observations créait 34 lots « sans occupant », qu'il fallait
#    ensuite arbitrer un par un. Ce n'était pas l'OCR : le document le DIT, en toutes lettres.
RE_SUITE = re.compile(r'\.{0,3}\s*Suite\s*$', re.I)
# ⚠️ RETENU SEULEMENT QUAND LE DOCUMENT LES IMPRIME SUR LA MÊME LIGNE. Sur ce corpus, cela
#    n'arrive jamais — la constante existe pour que le moteur sache lire un document qui le
#    ferait, sans jamais suppléer celui qui ne le fait pas.
RE_SOLDE_COLLE = re.compile(r'Solde\s+(-?[\d  ]+,\d{2})\s*€')


def jour(fr):
    m = re.match(r'^(\d{2})/(\d{2})/(\d{4})$', (fr or '').strip())
    return '%s-%s-%s' % (m.group(3), m.group(2), m.group(1)) if m else None


def observer(textes, page_base):
    """Une observation par lot : son occupant, sa date de bail, sa page.

    ⚠️ LE LOCATAIRE EST CELUI QUI SUIT LE LOT, PAS LE DERNIER RENCONTRÉ SUR LA PAGE. On
       découpe le texte au niveau de chaque « - Lot … - Mandat » et on ne lit que le segment
       qui lui appartient : sans cela, un lot sans ligne locataire hériterait de l'occupant du
       lot précédent — et le document ferait dire à un bail ce qu'il ne dit pas.
    """
    obs = []
    for i, texte in enumerate(textes):
        page = page_base + i
        # les espaces internes sont un artefact d'OCR : la référence n'en porte jamais
        bornes = [(m.start(), m.group(1).replace(' ', '').rstrip('-'))
                  for m in RE_LOT.finditer(texte)]
        for k, (debut, ref) in enumerate(bornes):
            fin = bornes[k + 1][0] if k + 1 < len(bornes) else len(texte)
            segment = texte[debut:fin]
            entete = segment.splitlines()[0] if segment.splitlines() else ''
            if RE_SUITE.search(entete.strip()):
                # Continuation du même lot : on complète l'observation ouverte plus haut,
                # on n'en crée pas une seconde qui paraîtrait inoccupée.
                for precedente in reversed(obs):
                    if precedente['lot'] == ref:
                        ms = RE_SOLDE_COLLE.search(segment)
                        if ms and precedente['solde'] is None:
                            precedente['solde'] = (ms.group(1).replace(' ', '')
                                                   .replace(' ', '').replace(',', '.'))
                            precedente['solde_source'] = 'LUE'
                        break
                continue
            m = RE_LOC.search(segment)
            if m:
                locataire, bail = ' '.join(m.group(1).split()), jour(m.group(2))
            else:
                m2 = RE_LOC_SANS_DATE.search(segment)
                locataire = ' '.join(m2.group(1).split()) if m2 else None
                bail = None
            ms = RE_SOLDE_COLLE.search(segment)
            obs.append({
                'lot': ref,
                'locataire': locataire,
                'bail_du': bail,
                'solde': ms.group(1).replace(' ', '').replace(' ', '').replace(',', '.')
                         if ms else None,
                'solde_source': 'LUE' if ms else 'NON DEMONTRABLE',
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
