# -*- coding: utf-8 -*-
# ⚠️ MODULE HISTORIQUE — LA DÉCOUVERTE, PAS L'ÉTAT ACTUEL.
#    Il a servi à établir la régression FOCH/SABY le 01/09/2026, quand les lecteurs comptaient
#    encore deux fois les événements réénoncés. Depuis la RECTIFICATION CERTIFIÉE du même jour,
#    `P5A.appels()`, `P5B.reglements()` et `P6A.observations()` dédoublonnent à la source : ce
#    module ne trouve donc plus de doublon, et c'est le résultat attendu. La non-régression est
#    assurée par `p5_rectification_test.py`, pas ici. Ne pas lire ses chiffres comme un état
#    du corpus : ils décrivent une confrontation qui n'a plus lieu d'être.

"""
RECTIFICATION GROUPÉE P5A / P5B / P6A — FOCH & SABY, raisonnée en ÉVÉNEMENTS MÉTIER.

⚠️ LA DOCTRINE QUE CETTE PASSE ÉTABLIT
   **LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF. C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER
   QU'IL CONTIENT.** Une même paire de pièces est réédition pour les charges, réédition pour
   certains appels, et complémentaire pour d'autres. Neutraliser un fichier entier retire donc
   des événements qui n'existent nulle part ailleurs ; le garder entier en compte d'autres deux
   fois. La déduplication porte sur l'événement, jamais sur le porteur.

⚠️ ET « PRÉSENT DANS UNE PIÈCE ÉCARTÉE » NE VEUT PAS DIRE « ABSENT DE L'ANCIEN TOTAL ».
   P5A, P5B et P6A lisent CHAQUE pièce de la GED : elles n'écartent rien. L'ancien total
   contient donc déjà les 75 appels complémentaires — il compte seulement DEUX FOIS ceux qui
   sont réénoncés. C'est la confusion que cette passe lève, chiffre à l'appui.

Les quatre ensembles, pour chaque phase :
   A — événements comptés UNE SEULE FOIS dans l'ancien total
   B — occurrences EN TROP : l'événement était déjà compté ailleurs
   C — événements ABSENTS de l'ancien total et qui devraient y être
   D — exclusions certifiées (report / dépôt de garantie) portées par les occurrences de B

   NOUVEAU = ANCIEN − B + C ± D

Lecture seule. Aucun référentiel modifié : cette passe MESURE et PROPOSE.
"""
import collections
import io
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p5a_certification as P5A   # noqa: E402
import p5a_numerateur as P5AN   # noqa: E402
import p5b_certification as P5B   # noqa: E402
import p6a_certification as P6A   # noqa: E402

LARG = 106
PAIRES = (('FOCH 0108.pdf', 'FOCH.pdf'), ('SABY 0107-2.pdf', 'SABY 0107.pdf'))
QUATRE = {f for p in PAIRES for f in p}


def entete(t):
    print()
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def classer(occ, ev, montant):
    """Sépare A (comptées une fois), B (occurrences en trop) et C (manquantes).

    ⚠️ B N'EST PAS « CE QUE PORTE LA PIÈCE ÉCARTÉE ». C'est la seconde occurrence d'un
       événement déjà présent. On garde UNE occurrence par événement et on retire les autres.

    ⚠️ LE CHOIX DU JUMEAU NE DOIT RIEN CHANGER — ET ON LE VÉRIFIE AU LIEU DE L'ESPÉRER.
       Sur le lot SABY 0038, chacune des deux pièces porte la même paire de lignes et la
       qualification certifiée du « Divers » a couvert LES DEUX shas : les jumeaux valent
       825,00 € des deux côtés. Si un jour ils différaient, garder l'un plutôt que l'autre
       déplacerait le total en silence. Le contrôle `jumeaux_equivalents()` lève dans ce cas.
    """
    vus = collections.Counter()
    A, B = [], []
    for x in occ:
        k = ev(x)
        vus[k] += 1
        (A if vus[k] == 1 else B).append(x)
    return A, B


def bloc(nom, occ_tout, occ_quatre, ev, montant, ancien, note=''):
    """Une phase : ancien → doublons → manquants → nouveau, au centime."""
    entete('%s — LES QUATRE ENSEMBLES' % nom)
    A, B = classer(occ_quatre, ev, montant)
    evenements = {ev(x) for x in occ_quatre}
    # ⚠️ C SE PROUVE, IL NE SE SUPPOSE PAS. Un événement « manquant » serait un événement du
    #    périmètre FOCH/SABY qu'aucune occurrence lue ne porte. Comme les quatre pièces sont
    #    TOUTES lues par la phase, l'ensemble est vide — mais on le vérifie au lieu de l'affirmer.
    C = [k for k in evenements if not any(ev(x) == k for x in occ_tout)]
    mB = round(sum(montant(x) for x in B), 2)
    mC = 0.0
    print('  périmètre des 4 pièces : %d occurrences · %d événements uniques'
          % (len(occ_quatre), len(evenements)))
    print('  A — comptés une seule fois          %5d occurrences · %16s'
          % (len(A), euros(sum(montant(x) for x in A))))
    print('  B — occurrences EN TROP             %5d occurrences · %16s' % (len(B), euros(mB)))
    print('  C — absents de l’ancien total       %5d occurrences · %16s' % (len(C), euros(mC)))
    if C:
        print('     ⚠ %s' % C[:3])
    print()
    print('  ANCIEN TOTAL CERTIFIÉ               %16s' % euros(ancien))
    print('  − B (doublons)                      %16s' % euros(-mB))
    print('  + C (manquants)                     %16s' % euros(mC))
    if note:
        print('  %s' % note)
    return A, B, C, mB


def main():
    ok = True

    # ══ P5A ═══════════════════════════════════════════════════════════════════════════════
    tous_a = [a for ag in AGENCES for a in P5A.appels(ag)]
    P5AN.controler(tous_a)
    flux = {id(a): v for a, v in P5AN.flux_appeles(tous_a)}
    doc = P5AN.montant_documentaire

    def ev_a(a):
        return (str(a.get('compte')), str(a.get('immeuble')), str(a.get('lot')),
                str(a.get('locataire')), a.get('periode_appel_debut'),
                a.get('periode_appel_fin'), a.get('periode_appel_texte'), doc(a))

    q4_a = [a for a in tous_a if a['crg_fichier'] in QUATRE]
    A, B, C, mB = bloc('P5A — APPELS', tous_a, q4_a, ev_a, doc, P5AN.DOCUMENTAIRE)
    print('  = NOUVEAU TOTAL DOCUMENTAIRE        %16s' % euros(P5AN.DOCUMENTAIRE - mB))
    print()
    # ⚠️ D — LES EXCLUSIONS PORTÉES PAR LES DOUBLONS. Retirer une occurrence en trop retire
    #    aussi le report ou le dépôt de garantie qu'elle portait — or celui-ci avait DÉJÀ été
    #    retranché du flux appelé. Sans ce terme, on retrancherait deux fois la même chose.
    dB = round(sum(doc(a) - flux[id(a)] for a in B), 2)
    print('  D — exclusions (report / dépôt de garantie) portées par B : %s' % euros(dB))
    for a in B:
        if abs(doc(a) - flux[id(a)]) > 0.005:
            print('     %-16s lot %-6s %-24s documentaire %8.2f · dont exclu %8.2f'
                  % (a['crg_fichier'][:16], a['lot'], str(a.get('periode_appel_debut'))[:24],
                     doc(a), doc(a) - flux[id(a)]))
    print('     Cette occurrence porte un dépôt de garantie DÉJÀ retranché de l’ancien total.')
    print('     La retirer au montant documentaire retrancherait ce dépôt une seconde fois :')
    print('     on retire donc son FLUX (825,00 €), soit 150,00 documentaire + 675,00 de D.')
    # ⚠️ ET SI DEUX JUMEAUX N'AVAIENT PAS LE MÊME FLUX, garder l'un plutôt que l'autre
    #    déplacerait le total sans que rien ne le signale. Fail closed.
    par_ev = collections.defaultdict(list)
    for x in q4_a:
        par_ev[ev_a(x)].append(flux[id(x)])
    boiteux = {k: v for k, v in par_ev.items() if len(set(round(z, 2) for z in v)) > 1}
    print('  contrôle des jumeaux — flux divergents entre occurrences d’un même événement : %d'
          % len(boiteux))
    if boiteux:
        ok = False
        for k, v in list(boiteux.items())[:3]:
            print('     ⚠ %s → %s' % (str(k)[:64], v))
    print()
    print('  ANCIEN APPELÉ AU LOCATAIRE          %16s' % euros(P5AN.CERTIFIE))
    print('  − B                                 %16s' % euros(-mB))
    print('  + D (déjà retranché, à ne pas retirer deux fois) %s' % euros(dB))
    neuf_a = round(P5AN.CERTIFIE - mB + dB, 2)
    print('  = NOUVEL APPELÉ AU LOCATAIRE        %16s' % euros(neuf_a))
    # contrôle croisé : recalcul direct sur le corpus dédoublonné
    garde = {id(a) for a in tous_a} - {id(b) for b in B}
    direct = round(sum(flux[id(a)] for a in tous_a if id(a) in garde), 2)
    print('  contrôle croisé — recalcul direct    %16s   %s'
          % (euros(direct), 'OK' if abs(direct - neuf_a) < 0.01 else '⚠ ÉCART'))
    if abs(direct - neuf_a) >= 0.01:
        ok = False

    # ══ P5B ═══════════════════════════════════════════════════════════════════════════════
    tous_b = [r for ag in AGENCES for r in P5B.reglements(ag)]
    anc_b = round(sum(float(r['montant'] or 0) for r in tous_b), 2)
    mt_b = lambda r: round(float(r.get('montant') or 0), 2)   # noqa: E731

    def ev_b(r):
        return (str(r.get('compte')), str(r.get('immeuble')), str(r.get('lot')),
                str(r.get('locataire')), r.get('libelle_source'),
                r.get('periode_reglee_debut'), r.get('periode_reglee_fin'), mt_b(r))

    q4_b = [r for r in tous_b if r['crg_fichier'] in QUATRE]
    _, Bb, Cb, mBb = bloc('P5B — RÈGLEMENTS', tous_b, q4_b, ev_b, mt_b, anc_b)
    print('  = NOUVEAU TOTAL RÉGLÉ               %16s' % euros(anc_b - mBb))
    # ⚠️ LA LIGNE « TOTAUX » N'EST PAS UN ÉVÉNEMENT COMME LES AUTRES. P5B y inscrit le RESTE
    #    (`total réglé − Σ lignes lues`) : il dépend de ce que la pièce liste. Deux pièces de
    #    couverture différente produisent donc deux restes différents pour un même lot.
    restes = [r for r in Bb if str(r['libelle_source']).startswith('ligne « Totaux »')]
    print('  dont lignes « Totaux » (reste, dépendant de la couverture) : %d · %s'
          % (len(restes), euros(sum(mt_b(r) for r in restes))))
    par = collections.Counter()
    for r in q4_b:
        if str(r['libelle_source']).startswith('ligne « Totaux »'):
            par[(r['compte'], r['immeuble'], r['lot'], r['crg_fichier'])] += mt_b(r)
    print('  ⚠ CE RESTE EST À VÉRIFIER PIÈCE À PIÈCE avant toute écriture : %d lots concernés.'
          % len({k[:3] for k in par}))

    # ══ P6A ═══════════════════════════════════════════════════════════════════════════════
    tous_6 = [o for ag in AGENCES for o in P6A.observations(ag)]
    anc_6 = round(sum(float(o['montant_source'] or 0) for o in tous_6), 2)
    mt_6 = lambda o: round(float(o.get('montant_source') or 0), 2)   # noqa: E731

    def ev_6(o):
        return (str(o.get('compte')), str(o.get('immeuble')), str(o.get('lot')),
                str(o.get('locataire')), o.get('date_arrete'), o.get('qualification'), mt_6(o))

    q4_6 = [o for o in tous_6 if o['crg_fichier'] in QUATRE]
    _, B6, C6, mB6 = bloc(
        'P6A — ENCOURS', tous_6, q4_6, ev_6, mt_6, anc_6,
        note='  ⚠ P6A EST UN STOCK À UNE DATE. Ce « total » est une population, jamais un\n'
             '    montant métier : `reference_crg_encours_stock_jamais_cumule` interdit de\n'
             '    cumuler des photographies. Ce qui se corrige ici, c’est le DÉNOMBREMENT.')
    print('  = NOUVELLE POPULATION               %16s' % euros(anc_6 - mB6))
    d6 = collections.Counter(o['date_arrete'] for o in B6)
    print('  toutes à la même date d’arrêté : %s — deux photographies d’un même stock'
          % ', '.join('%s (%d)' % (k, v) for k, v in d6.items()))

    # ══ SYNTHÈSE ══════════════════════════════════════════════════════════════════════════
    entete('SYNTHÈSE — ancien → doublons → manquants → nouveau')
    print('  %-22s %16s %8s %14s %8s %10s %16s'
          % ('PHASE', 'ANCIEN', 'nb B', 'DOUBLONS', 'nb C', 'MANQUANTS', 'NOUVEAU'))
    for nom, anc, nb, mb, nc, mc, neuf in (
            ('P5A — documentaire', P5AN.DOCUMENTAIRE, len(B), mB, len(C), 0.0,
             P5AN.DOCUMENTAIRE - mB),
            ('P5A — appelé locataire', P5AN.CERTIFIE, len(B), mB - dB, len(C), 0.0, neuf_a),
            ('P5B — réglé', anc_b, len(Bb), mBb, len(Cb), 0.0, anc_b - mBb),
            ('P6A — population stock', anc_6, len(B6), mB6, len(C6), 0.0, anc_6 - mB6)):
        print('  %-22s %16s %8d %14s %8d %10s %16s'
              % (nom, euros(anc), nb, euros(mb), nc, euros(mc), euros(neuf)))
    print()
    print('  ⚠ C EST VIDE PARTOUT, ET C’EST LE POINT. Les 75 appels complémentaires étaient')
    print('    DÉJÀ dans l’ancien total : P5A lit toutes les pièces de la GED sans en écarter')
    print('    aucune. Ce qui était faux, c’est que 42 autres y figuraient DEUX FOIS. La')
    print('    rectification ne fait donc que retirer B — elle n’ajoute rien.')

    entete('RECTIFICATION CERTIFIABLE : %s' % ('OUI' if ok else 'NON — voir les ⚠'))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
