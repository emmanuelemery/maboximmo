# -*- coding: utf-8 -*-
"""
LA FILE D'ARBITRAGE — ce que le document ne permet pas de trancher part en question, pas en devinette.

⚠️ DANS LE DOUTE, ON NE S'ACHARNE PAS SUR UN LIBELLÉ. Règle de méthode du 31/08/2026 : si la
   nature métier n'est pas démontrable après lecture du contexte, on crée une DEMANDE
   D'ARBITRAGE — avec sa preuve, son PDF et sa page — et on continue le balayage. La ligne
   devient `INDÉTERMINABLE — ARBITRAGE DEMANDÉ` : elle ne bloque rien, ne disparaît pas, n'est
   pas classée artificiellement, et reste traçable jusqu'à décision.

⚠️ UNE QUESTION PAR RÈGLE MÉTIER, JAMAIS PAR LIGNE. Douze taxes du même type posent UNE
   question. C'est la règle qui manque, pas la ligne.

⚠️ ET LA QUESTION EST FERMÉE. « Que faire de cette ligne ? » ne se répond pas ; « financement,
   dépense de gestion, ou autre mouvement ? » se répond en trois secondes.

Produit `data/crg_arbitrages.json` et `data/crg_pdf_index.json`.
Lecture seule côté corpus. Aucune phase modifiée.
"""
import collections
import io
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p4b_source_pdf as SRC   # noqa: E402
import p7_routage_exhaustif as R   # noqa: E402

RACINE = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
DATA = os.path.join(RACINE, 'data')

# ── LES RÈGLES MÉTIER ENCORE INDÉMONTRABLES ────────────────────────────────────────────────
#    Chacune regroupe toutes ses lignes ; on n'en montre que deux ou trois en exemple.
REGLES = [
    {'cle': 'transfert-inter-entites',
     'question': 'Un virement entre deux entités du groupe, sans contrepartie nommée : '
                 'est-ce une compensation sans mouvement de fonds, un versement au '
                 'propriétaire, ou un mouvement de trésorerie interne à la régie ?',
     'choix': ['compensation — aucun fonds ne bouge',
               'versement au propriétaire (acompte)',
               'trésorerie interne régie — hors périmètre propriétaire'],
     'doute': 'La rubrique ne nomme ni bénéficiaire ni nature ; le libellé nomme deux entités '
              'sans dire laquelle reçoit.',
     'regle_liee': 'P8A — sens du flux lu sur le libellé',
     'test': lambda x: 'transfert' in x['libelle'].lower()
                       or 'transfer' in x['libelle'].lower()},
    {'cle': 'mouvement-entite-tierce',
     'question': 'Un mouvement nommant une société que la régie ne gère pas, sous une rubrique '
                 'qui n’est qu’une date : financement pour le compte du propriétaire, '
                 'dépense de gestion, ou opération hors périmètre ?',
     'choix': ['financement pour le propriétaire — flux P8A',
               'dépense de gestion — P7A',
               'hors périmètre de la gestion'],
     'doute': 'Le libellé nomme une entité tierce sans qualifier l’opération, et la rubrique '
              'ne porte qu’une date.',
     'regle_liee': 'P8A — flux propriétaire',
     'test': lambda x: bool(re.search(r"\b(sci|sarl|sas|sa|holding)\b", x['libelle'], re.I))},
]

# ⚠️ LA FILE ELLE-MÊME EST FAIL-CLOSED. Une ligne qui ne relève d'aucune règle d'arbitrage ne
#    doit pas s'évaporer entre les mailles : elle tombe dans une question générique, avec sa
#    preuve et son PDF, plutôt que de disparaître d'un rapport qui se dirait complet.
REGLE_RESIDUELLE = {
    'cle': 'nature-non-demontrable',
    'question': 'Le document ne nomme ni la nature ni le bénéficiaire de cette écriture. '
                'À quelle famille appartient-elle ?',
    'choix': ['charge du bien — P7A', 'frais de gestion — P7B',
              'flux propriétaire — P8A', 'autre / hors périmètre'],
    'doute': 'Aucune règle d’arbitrage existante ne couvre cette forme.',
    'regle_liee': '—',
    'test': lambda x: True,
}


def contexte(d, o, n=2):
    """Les lignes voisines, telles que le CRG les imprime."""
    ops = list((d.get('mandat') or {}).get('operations') or [])
    ops.sort(key=lambda z: (z.get('page') or 0, z.get('y') or 0))
    try:
        i = ops.index(o)
    except ValueError:
        return []
    return [{'section': str(z.get('entete') or ''), 'libelle': str(z.get('libelle') or ''),
             'debit': round(float(z.get('debit') or 0), 2),
             'credit': round(float(z.get('credit') or 0), 2), 'cible': z is o}
            for z in ops[max(0, i - n):i + n + 1]]


def main():
    os.makedirs(DATA, exist_ok=True)
    index, arbitrages = {}, []

    # ── l'index des PDF : sha → chemin, uniquement pour le corpus certifié ─────────────────
    docs = {}
    for ag in ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR'):
        for d in documents(ag):
            docs[d['_sha']] = (ag, d)

    ops = list(R.operations())
    R.controler_routage(ops)

    # les lignes que le routage laisse à qualifier
    restants = []
    for x in ops:
        dest, _ = R.destination(x)
        if dest == 'NON RECONNU — À QUALIFIER':
            restants.append(x)

    restant_a_placer = list(restants)
    for regle in REGLES + [REGLE_RESIDUELLE]:
        lot = [x for x in restant_a_placer if regle['test'](x)]
        restant_a_placer = [x for x in restant_a_placer if x not in lot]
        if not lot:
            continue
        exemples = []
        for x in sorted(lot, key=lambda z: -(z['debit'] or z['credit']))[:3]:
            sha = next((s for s, (a, d) in docs.items()
                        if str(d.get('_nom_original')) == x['crg']), None)
            ag, d = docs.get(sha, (None, None))
            o = None
            if d:
                for z in (d.get('mandat') or {}).get('operations') or []:
                    if (str(z.get('libelle') or '') == x['libelle']
                            and str(z.get('entete') or '') == x['entete']):
                        o = z
                        break
            if sha and sha not in index and d:
                chemin = SRC.chemin(d, ag, None, None)
                if chemin and os.path.exists(chemin):
                    index[sha] = {'chemin': os.path.abspath(chemin),
                                  'nom': str(d.get('_nom_original'))}
            m = (d.get('meta') or {}) if d else {}
            exemples.append({
                'agence': x['agence'], 'compte': x['compte'],
                'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                'periode': x['periode'], 'date_arrete': str(m.get('date_arrete') or ''),
                'crg': x['crg'], 'sha': sha, 'page': x['page'],
                'section': x['entete'], 'libelle': x['libelle'],
                'debit': x['debit'], 'credit': x['credit'],
                'montant': round(x['debit'] or x['credit'], 2),
                'contexte': contexte(d, o) if (d and o) else [],
            })
        arbitrages.append({
            'cle': regle['cle'], 'question': regle['question'], 'choix': regle['choix'],
            'doute': regle['doute'], 'regle_liee': regle['regle_liee'],
            'lignes': len(lot),
            'montant': round(sum(x['debit'] or x['credit'] for x in lot), 2),
            'exemples': exemples,
        })

    # ── LES RÉÉDITIONS PARTIELLES — un arbitrage de RÈGLE, pas de ligne ───────────────────
    # ⚠️ QUATRE GROUPES VIENNE SE RECOUVRENT PARTIELLEMENT (10 à 33 % d'écritures communes).
    #    Ni rééditions franches ni compléments francs : c'est le SEUIL qui manque, et un seuil
    #    ne s'invente pas. Une seule question pour les quatre.
    import audit_final_p7 as AF   # noqa: E402
    groupes = collections.defaultdict(list)
    for ag in AGENCES:
        for d in documents(ag):
            m = d.get('meta') or {}
            groupes[(ag, str(m.get('compte') or ''),
                     str(m.get('date_arrete') or ''))].append(d)
    partiels = []
    for k, v in groupes.items():
        if len(v) < 2 or not k[1] or not k[2]:
            continue
        base = max(v, key=lambda d: (
            sum(len(i.get('charges') or []) + len(i.get('mouvements') or [])
                for i in d.get('immeubles') or [])
            + len((d.get('mandat') or {}).get('operations') or [])
            + len(d.get('immeubles') or []) + (d.get('_pages') or 0)))
        for d in v:
            if d is base:
                continue
            verdict, det = AF.classer(base, d)
            if verdict.startswith('INDÉTERMINABLE'):
                partiels.append({'agence': k[0], 'compte': k[1], 'date_arrete': k[2],
                                 'reference': str(base.get('_nom_original')),
                                 'crg': str(d.get('_nom_original')),
                                 'verdict': verdict, 'detail': det})
    if partiels:
        arbitrages.append({
            'cle': 'reedition-partielle',
            'question': 'Deux CRG du même compte au même arrêté, dont les écritures se '
                        'recouvrent partiellement (10 à 33 %) : rééditions de la même '
                        'situation, ou pièces complémentaires portant des lots différents ?',
            'choix': ['rééditions — ne compter qu’une fois les écritures communes',
                      'pièces complémentaires — tout compter',
                      'à examiner cas par cas'],
            'doute': 'Le recouvrement est trop faible pour une réédition, trop fort pour des '
                     'pièces indépendantes. Aucun seuil ne peut être posé sans décision métier.',
            'regle_liee': 'P7A-DEPENSE-11 — unicité de l’événement métier',
            'lignes': len(partiels), 'montant': 0.0,
            'exemples': [{'agence': p['agence'], 'compte': p['compte'], 'proprietaire': '',
                          'periode': '', 'date_arrete': p['date_arrete'],
                          'crg': p['crg'], 'sha': None, 'page': 1,
                          'section': 'référence : ' + p['reference'],
                          'libelle': p['verdict'], 'debit': 0.0, 'credit': 0.0, 'montant': 0.0,
                          'contexte': [{'section': '', 'libelle':
                                        'écritures %d vs %d, communes %d · lots %d vs %d, '
                                        'communs %d'
                                        % (p['detail']['ch_a'], p['detail']['ch_b'],
                                           p['detail']['ch_comm'], p['detail']['lots_a'],
                                           p['detail']['lots_b'], p['detail']['lots_comm']),
                                        'debit': 0.0, 'credit': 0.0, 'cible': True}]}
                         for p in partiels[:3]],
        })

    # ── P8A : CE QUE LE DOCUMENT NE PERMET PAS DE TRANCHER SUR LE SENS DU FLUX ─────────────
    # ⚠️ UNE QUESTION PAR RÈGLE, ET SEULEMENT CE QU'EMMANUEL N'A PAS DÉJÀ TRANCHÉ. Les
    #    `VIRT <ENTITÉ>/<BANQUE>` sont déjà arbitrés — « ne déduis jamais la qualité de
    #    bénéficiaire du seul fait qu'une banque apparaît » — et les saisies aussi
    #    (« gardons tout, mais ne bloquons pas »). On ne les repose donc pas.
    import p8a_socle as S8   # noqa: E402
    import p7_socle as P7S   # noqa: E402
    ind = [x for ag in AGENCES for x in S8.candidats(ag)
           if x['crg_fichier'] not in P7S.REEDITIONS and x['sens'] == 'SENS_INDETERMINABLE']
    REGLES_P8A = [
        ('credit-sous-soldes-mandats',
         'Un CRÉDIT sous la section « Soldes mandats », dont la rubrique annonce un acompte au '
         'propriétaire : remboursement d’un acompte versé en trop, apport du propriétaire, '
         'ou restitution d’une saisie ?',
         ['remboursement d’un acompte versé en trop', 'apport du propriétaire',
          'restitution / autre mouvement'],
         'La rubrique dit « acompte au propriétaire », le signe dit l’inverse. Le sens venant '
         'd’une rubrique héritée, il n’est pas démontré.',
         'P8A — le signe ne fonde pas le sens, mais peut le démentir',
         lambda x: x['famille'].startswith('ACOMPTE PROPRIÉTAIRE — par section')),
        ('depot-garantie-sens-non-demontre',
         'Un chèque porté au CRÉDIT sous une rubrique « Reversement D.G. au prop. » : '
         'restitution du dépôt par le locataire, ou reversement au propriétaire annulé ?',
         ['dépôt encaissé du locataire', 'reversement au propriétaire annulé',
          'autre mouvement de dépôt'],
         'Le libellé ne nomme qu’un numéro de chèque ; la rubrique dit « au prop. » alors que '
         'le montant est au crédit.',
         'P8A — dépôt de garantie, sens lu sur le libellé',
         lambda x: x['famille'].startswith('MOUVEMENT DÉPÔT DE GARANTIE')),
        ('rejet-de-virement',
         'Un virement rejeté (compte clôturé) : annule-t-il un flux déjà compté au CRG '
         'précédent, ou n’a-t-il jamais eu de contrepartie ?',
         ['annule un flux déjà compté ailleurs', 'aucune contrepartie — écriture isolée',
          'à examiner cas par cas'],
         'Le rejet est imprimé sans référence au virement d’origine : rien ne dit s’il annule '
         'une ligne déjà certifiée.',
         'P8A — occurrence documentaire ≠ mouvement bancaire',
         lambda x: x['famille'].startswith('REJET DE VIREMENT')),
    ]
    for cle, question, choix, doute, liee, test in REGLES_P8A:
        lot = [x for x in ind if test(x)]
        if not lot:
            continue
        exemples = []
        for x in sorted(lot, key=lambda z: -z['montant_source'])[:3]:
            sha = x['sha']
            ag, d = docs.get(sha, (None, None))
            if sha and sha not in index and d:
                ch = SRC.chemin(d, ag, None, None)
                if ch and os.path.exists(ch):
                    index[sha] = {'chemin': os.path.abspath(ch),
                                  'nom': str(d.get('_nom_original'))}
            exemples.append({
                'agence': x['agence'], 'compte': x['compte'],
                'proprietaire': x['proprietaire'], 'periode': x['periode_crg'],
                'date_arrete': str(x['date_arrete'] or ''), 'crg': x['crg_fichier'],
                'sha': sha, 'page': x['page'], 'section': x['section'],
                'libelle': x['libelle_brut'], 'debit': x['debit'], 'credit': x['credit'],
                'montant': x['montant_source'], 'contexte': []})
        arbitrages.append({'cle': cle, 'question': question, 'choix': choix, 'doute': doute,
                           'regle_liee': liee, 'lignes': len(lot),
                           'montant': round(sum(x['montant_source'] for x in lot), 2),
                           'exemples': exemples})

    sortie = {'genere_pour': 'audit transversal P7 · certification P8A',
              'arbitrages': arbitrages}
    io.open(os.path.join(DATA, 'crg_arbitrages.json'), 'w', encoding='utf-8').write(
        json.dumps(sortie, ensure_ascii=False, indent=1))
    io.open(os.path.join(DATA, 'crg_pdf_index.json'), 'w', encoding='utf-8').write(
        json.dumps(index, ensure_ascii=False, indent=1))

    print('  %d règle(s) en arbitrage, %d ligne(s), %s'
          % (len(arbitrages), sum(a['lignes'] for a in arbitrages),
             euros(sum(a['montant'] for a in arbitrages))))
    for a in arbitrages:
        print('     %-34s %2d ligne(s) %12s  · %d exemple(s) avec PDF'
              % (a['cle'], a['lignes'], euros(a['montant']), len(a['exemples'])))
    print('  index PDF : %d pièces' % len(index))
    print('  lignes à qualifier non placées dans la file : %d  → %s'
          % (len(restant_a_placer),
             'AUCUNE, la file est fermée' if not restant_a_placer else 'ÉCHEC'))


if __name__ == '__main__':
    main()
