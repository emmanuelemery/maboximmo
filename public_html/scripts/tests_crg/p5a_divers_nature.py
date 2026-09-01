# -*- coding: utf-8 -*-
"""
P5A · QUALIFICATION DES LIGNES « DIVERS » — à partir de la lecture GÉOMÉTRIQUE du PDF.

⚠️ CHAQUE LIGNE IMPRIMÉE PORTE SON PROPRE LIBELLÉ ET SON PROPRE MONTANT. C'est ce qui lève
   l'ambiguïté : la première version joignait sur (page, lot, période) et, quand plusieurs
   lignes partageaient ces trois clés, ne savait plus quel libellé allait avec quel montant —
   101 lignes restaient « attribution ambiguë ». La géométrie donne les deux ensemble, alors on
   apparie SUR LE MONTANT, qui est la seule correspondance que le document démontre.

⚠️ ON NE CLASSE QUE CE QUE LE LIBELLÉ DÉMONTRE. Chaque motif ci-dessous est un terme réellement
   imprimé dans le corpus. Ce qui ne correspond à rien reste INDÉTERMINABLE, et `libelle_source`
   est conservé dans tous les cas.

⚠️ ET SI LA GÉOMÉTRIE NE TRANCHE PAS — deux lignes de même période ET de même montant sur le
   même lot, avec des libellés de natures différentes — la ligne reste
   INDÉTERMINABLE — ATTRIBUTION DOCUMENTAIRE AMBIGUË. On ne départage jamais par l'ordre supposé.

⚠️ « Solde Antérieur » est un REPORT (stock). Le DÉPÔT DE GARANTIE est un mouvement
   propriétaire/régie. Ni l'un ni l'autre n'est un appel au locataire.

Lecture seule.
"""
import sys
import os
import io
import re
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

GEOMETRIE = r'C:\tmp\p5a_geometrie.json'
SORTIE = r'C:\tmp\p5a_divers_qualifie.json'

MOTIFS = [
    ('report / solde antérieur — STOCK', r"solde\s+ant[ée]rieur"),
    ('dépôt de garantie — propriétaire/régie',
     r"d[ée]p[oô]t\s+de\s+garantie|rembt\s*d\.?\s*g|remboursement\s+d\.?\s*g\.?"),
    ('régularisation', r"solde\s+de\s+charges|r[ée]gularisation"),
    ('taxe', r"taxe\s+ordures|imp[oô]t\s+foncier|taxes?\s+fonci"),
    ('loyer', r"\b(rappel|remise)\s+de\s+loyer\b"),
    ('charges / provisions',
     r"charges\s+locatives|charges\s+immeuble|provisions?\s*/?\s*chauffage|"
     r"provision\s+assurance|consommation\s+(eau|minuterie|[ée]lectricit)|contrat\s+chaudi|"
     r"contrat\s+sba|contrat\s+nettoyage|maintenance\s+porte|"
     r"entretien\s+(chauffage|nettoyage|divers|ramonage|espaces|ma[çc]onnerie)|"
     r"facture\s+([ée]lectricit|plomberie|serrurerie|peinture|[ée]vier|petites)|"
     r"assurance\s+[àa]\s+la\s+charge|rappel\s+provisions"),
    ('frais de procédure',
     r"frais\s+d.huissier|frais\s+de\s+commandement|article\s+700|p[ée]nalit"),
    # ⚠️ CES TROIS-LÀ SONT NOMMÉS SANS AMBIGUÏTÉ PAR LEUR LIBELLÉ, et rien de plus n'en est
    #    déduit : « Remb LOYER TROP VERSE » dit un remboursement de loyer, « ERREUR ECRITURE »
    #    dit une correction comptable, « Facture … » dit une refacturation. On s'arrête là.
    ('remboursement de loyer trop perçu', r"remb\w*\s+loyer\s+trop"),
    ('correction d’écriture', r"erreur\s+[ée]criture"),
    ('refacturation locative',
     r"^facture\b|remb\s+facture|fourniture\s+d|[ée]metteur|badge|plaques|interphone|"
     r"entretien\b|entr\.\s*chauf"),
]
MOTIFS = [(n, re.compile(p, re.I)) for n, p in MOTIFS]
NOMS = [n for n, _ in MOTIFS]
AMBIGU = 'INDÉTERMINABLE — attribution documentaire ambiguë'
SANS = 'INDÉTERMINABLE — libellé sans nature démontrable'
ABSENT = 'INDÉTERMINABLE — ligne non retrouvée au PDF'
NATURES = NOMS + [SANS, AMBIGU, ABSENT]


def nature(libelle):
    for nom, motif in MOTIFS:
        if motif.search(libelle or ''):
            return nom
    return SANS


if __name__ == '__main__':
    # ① les lignes lues géométriquement, indexées par (sha, page, lot, montant Divers)
    geo = collections.defaultdict(list)
    for l in json.load(io.open(GEOMETRIE, encoding='utf-8')):
        for v in l['colonnes'].get('Divers', []):
            geo[(l['sha'], l['page'], l['lot'], round(v, 2))].append(l)

    # ② les montants `divers` certifiés par le lecteur, auxquels on joint la ligne imprimée
    lignes = []
    for ag in perimetre():
        if ag == 'VIENNE':
            continue
        for d in documents(ag):
            m = d.get('meta') or {}
            for im in d.get('immeubles') or []:
                for lo in im.get('lots') or []:
                    for r in lo.get('mois') or []:
                        if not r.get('divers'):
                            continue
                        cle = (d.get('_sha'), r.get('page'), str(lo.get('numero_lot') or ''),
                               round(float(r['divers']), 2))
                        cand = geo.get(cle, [])
                        # on resserre encore par la période quand elle discrimine
                        exact = [x for x in cand if x['au'] == P4B.jour(r.get('au'))] or cand
                        nats = {nature(x['libelle']) for x in exact}
                        if not exact:
                            nat, lib = ABSENT, None
                        elif len(nats) == 1:
                            nat, lib = nats.pop(), ' | '.join(sorted({x['libelle'] for x in exact}))
                        else:
                            nat, lib = AMBIGU, ' | '.join(sorted({x['libelle'] for x in exact}))
                        lignes.append({'agence': ag, 'crg_fichier': d.get('_nom_original'),
                                       'sha': d.get('_sha'), 'page': r.get('page'),
                                       'periode_crg': m.get('periode_cle'),
                                       'compte': str(m.get('compte') or ''),
                                       'immeuble': str(im.get('code') or ''),
                                       'lot': str(lo.get('numero_lot') or ''),
                                       'locataire': str(lo.get('locataire_nom') or ''),
                                       'periode_debut': P4B.jour(r.get('du')),
                                       'periode_fin': P4B.jour(r.get('au')),
                                       'montant': round(float(r['divers']), 2),
                                       'libelle_source': lib, 'nature_demontree': nat})
    io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(lignes, ensure_ascii=False))

    ags = [a for a in perimetre() if a != 'VIENNE']
    par = collections.defaultdict(float)
    nb = collections.Counter()
    for x in lignes:
        par[(x['agence'], x['nature_demontree'])] += x['montant']
        nb[x['nature_demontree']] += 1

    entete('LES 462 491,38 € « À QUALIFIER » — VENTILATION PAR LECTURE GÉOMÉTRIQUE')
    print('  %-46s %15s %15s %15s %8s' %
          ('NATURE DÉMONTRÉE PAR LE LIBELLÉ', 'LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR', 'lignes'))
    for n in NATURES:
        print('  %-46s %15s %15s %15s %8d' %
              (n, euros(par[(ags[0], n)]), euros(par[(ags[1], n)]), euros(par[(ags[2], n)]), nb[n]))
    print('  %-46s %15s %15s %15s %8d' %
          ('TOTAL', euros(sum(par[(ags[0], n)] for n in NATURES)),
           euros(sum(par[(ags[1], n)] for n in NATURES)),
           euros(sum(par[(ags[2], n)] for n in NATURES)), len(lignes)))
    qual = sum(v for (a, n), v in par.items() if n in NOMS)
    reste = sum(v for (a, n), v in par.items() if n not in NOMS)
    nq = sum(nb[n] for n in NOMS)
    print('\n  QUALIFIÉ : %s sur %d lignes   ·   RESTE : %s sur %d lignes'
          % (euros(qual), nq, euros(reste), len(lignes) - nq))

    entete('CE QUI RÉSISTE ENCORE À LA GÉOMÉTRIE')
    inc = collections.Counter()
    for x in lignes:
        if x['nature_demontree'] in (SANS, AMBIGU, ABSENT):
            inc[(x['nature_demontree'][16:38], re.sub(r'\d+', '#', str(x['libelle_source']))[:46])] += 1
    for (n, k), v in inc.most_common(18):
        print('  %5d  %-24s %s' % (v, n, k))
    print('\n  %d lignes → %s' % (len(lignes), SORTIE))
