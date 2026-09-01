# -*- coding: utf-8 -*-
"""
P9 — publication des demandes d'arbitrage sur `crg_arbitrages.php`.

⚠️ UNE QUESTION PAR RÈGLE MÉTIER, JAMAIS PAR LIGNE. Aucune ne porte sur un libellé : toutes
   portent sur ce qu'un indicateur a le droit d'affirmer, et sur l'écran où il s'affiche.

⚠️ LES RÉPONSES DÉJÀ DONNÉES SONT INDEXÉES PAR `cle`, pas par rang. On remplace le bloc P9 et
   on laisse intactes les questions des passes précédentes — aucune réponse ne change de
   destinataire.

⚠️ DEUX QUESTIONS ONT ÉTÉ RETIRÉES, CHACUNE POUR UNE RAISON DIFFÉRENTE.
   `p9-base-de-revenu` : la doctrine des trois rendements l'a rendue sans objet — l'appelé et
   le réglé ne sont plus deux candidats à un même indicateur, ce sont les numérateurs de DEUX
   rendements distincts, publiés côte à côte.
   `p9-piece-qui-fait-foi` : ce n'était pas une décision métier. Le documentaire distingue
   lui-même la réénonciation du complément — lot × période × montant — donc on déduplique
   l'ÉVÉNEMENT et on conserve les 75 appels nouveaux. `RECT-EVENEMENT-01` l'a figé depuis.
   `p9-flux-entrant-proprietaire` : la doctrine y répond déjà. `P8B-SOLDE-08` interdit tout
   netting sans lien démontré ; soustraire les 14 flux propriétaire → régie (41 933,99 €) des
   668 reversements en serait un. Ils s'affichent donc à côté, jamais en déduction.
   Et `p9-charges-de-compte` et `p9-performance-par-lot` n'étaient qu'une seule règle vue sous
   deux angles : que montre un écran dont la maille est plus fine que celle de la preuve ?
   Elles fusionnent en `p9-indicateur-non-demontrable-a-lecran`.

⚠️ ON NE FAIT PAS TRANCHER UNE QUESTION QUE LA PREUVE OU LA DOCTRINE TRANCHE DÉJÀ. Des quatre
   questions ouvertes, une seule appelle encore une décision métier.
"""
import io
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as P8A   # noqa: E402

ARB = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                   '..', '..', 'data', 'crg_arbitrages.json')
# ⚠️ TOUTE CLÉ P9 ABSENTE DE `NEUVES` DISPARAÎT DE LA PAGE. C'est ainsi qu'une question rendue
#    sans objet par une doctrine se retire — jamais en laissant traîner une question morte.
PREFIXE = 'p9-'


def ex(x, **plus):
    """Un exemple porte toujours de quoi rouvrir le PDF à la bonne page."""
    d = dict(agence=x.get('agence'), compte=x.get('compte'),
             proprietaire=x.get('proprietaire'), periode=x.get('periode_crg'),
             date_arrete=x.get('date_arrete'), crg=x.get('crg_fichier'),
             sha=x.get('sha'), page=x.get('page'), contexte=[])
    d.update(plus)
    return d


def main():
    charges = [x for ag in AGENCES for x in P7.lignes(ag) if x['cote'] in ('P7A', 'P7B')]
    au_compte = [x for x in charges if x['maille_documentaire'] == 'compte mandant']
    au_lot = [x for x in charges if x['maille_documentaire'] == 'lot']
    recus = [x for ag in AGENCES for x in P8A.candidats(ag)
             if x['crg_fichier'] not in P7.REEDITIONS
             and x['sens'] == 'PROPRIETAIRE_VERS_REGIE']

    neuves = [
        {
            'cle': 'p9-indicateur-non-demontrable-a-lecran',
            'question': ('Sur un écran BIEN ou IMMEUBLE, que faire d’un rendement dont le '
                         'numérateur n’est pas démontré à cette maille ?'),
            'choix': [
                'afficher les trois, en marquant NON DÉMONTRABLE ce qui ne descend pas',
                'n’afficher que les rendements démontrés à ce niveau, sans case vide',
                'renvoyer au niveau où l’indicateur existe — le bien pointe vers son immeuble',
            ],
            'doute': ('Trois cas concrets, une seule règle. ① Le NET TRÉSORERIE n’est démontré '
                      'qu’au compte mandant : ni la fiche bien ni la fiche immeuble ne peuvent '
                      'l’afficher. ② 228 062,46 € de charges (73 lignes) sont imprimées au '
                      'compte sans immeuble : les ventiler serait une invention, les taire '
                      'embellit les 702 immeubles joignables. ③ Hors VIENNE, la charge reste à '
                      'l’immeuble (`P7A-DEPENSE-13`) : une fiche bien y afficherait un revenu '
                      'sans charge. La question est la même partout — que montre-t-on quand la '
                      'maille de l’écran est plus fine que celle de la preuve ?'),
            'regle_liee': 'P9 — INDICATEUR COMPTE — NON VENTILABLE · P7A-DEPENSE-13',
            'lignes': len(au_compte) + len(au_lot),
            'montant': round(sum(x['montant_source'] for x in au_compte), 2),
            'exemples': ([ex(x, immeuble=None, lot=None, section=x.get('entete_source'),
                             libelle='charge au compte, sans immeuble — ' + x['libelle_brut'],
                             debit=x['montant_source'], credit=0.0,
                             montant=x['montant_source'])
                          for x in sorted(au_compte, key=lambda z: -z['montant_source'])[:2]]
                         + [ex(x, immeuble=x.get('immeuble'), lot=x.get('lot'),
                               section=x.get('entete_source'),
                               libelle='charge descendue au lot (VIENNE) — ' + x['libelle_brut'],
                               debit=x['montant_source'], credit=0.0,
                               montant=x['montant_source'])
                            for x in sorted(au_lot, key=lambda z: -z['montant_source'])[:2]]),
        },
    ]

    d = json.load(io.open(ARB, encoding='utf-8'))
    garde = [q for q in d['arbitrages'] if not q['cle'].startswith(PREFIXE)]
    retire = [q['cle'] for q in d['arbitrages']
              if q['cle'].startswith(PREFIXE) and q['cle'] not in {n['cle'] for n in neuves}]
    d['arbitrages'] = garde + neuves
    d['genere_pour'] = ('audit transversal P7 · certification P8A · '
                        'P9 rentabilité à trois niveaux')
    io.open(ARB, 'w', encoding='utf-8').write(json.dumps(d, ensure_ascii=False, indent=1))
    print('arbitrages hors P9 conservés : %d' % len(garde))
    print('questions P9 retirées (sans objet) : %s' % (', '.join(retire) or 'aucune'))
    for q in neuves:
        print('  %-30s %d exemples · %d lignes · %.2f €'
              % (q['cle'], len(q['exemples']), q['lignes'], q['montant']))
    return 0


if __name__ == '__main__':
    sys.exit(main())
