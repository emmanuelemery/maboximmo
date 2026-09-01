# -*- coding: utf-8 -*-
"""
CONTRÔLE DE FRONTIÈRE P8A ↔ P7A — TROISIÈME PASSE : LES PREUVES, NOMMÉES.

① le relevé exhaustif des lignes « prêt » et « salaire » du compte mandant, avec fichier et page
② l'équivalence `ACOMPTE PRO` = `ACOMPTE PROPRIÉTAIRE` est-elle DÉMONTRÉE par le corpus ?

Lecture seule. P7A n'est pas touchée, aucune règle n'est modifiée.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 96
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')
PRET = re.compile(r"\bpr[êe]ts?\b|emprunt|[ée]ch[ée]ance\s+pr", re.I)
SALAIRE = re.compile(r"\bsalaires?\b|\bpaie\b", re.I)


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def main():
    tout = {ag: list(S.candidats(ag)) for ag in LYON}

    entete('① LES LIGNES « PRÊT » ET « SALAIRE » DU COMPTE MANDANT — RELEVÉ EXHAUSTIF')
    for nom, motif in (('REMBOURSEMENT DE PRÊT', PRET), ('SALAIRE / PAIE', SALAIRE)):
        L = [x for ag in LYON for x in tout[ag]
             if x['conteneur'] == 'mandat.operations'
             and (motif.search(x['libelle_brut']) or motif.search(x['section']))]
        print('  %s — %d lignes, %s' % (nom, len(L), euros(sum(x['montant_source'] for x in L))))
        print('     %-13s %11s %-24s %-40s %s'
              % ('PÉRIMÈTRE', 'montant', 'RUBRIQUE IMPRIMÉE', 'LIBELLÉ', 'CRG · page'))
        for x in sorted(L, key=lambda y: -y['montant_source']):
            print('     %-13s %11s %-24s %-40s %s p.%s'
                  % (x['agence'][:13], euros(x['montant_source']), x['section'][:24],
                     x['libelle_brut'][:40], x['crg_fichier'][:26], x['page']))
        print()

    entete('LA RUBRIQUE « Rembt Prêt immobilier » EST-ELLE UNE VRAIE RUBRIQUE IMPRIMÉE ?')
    # ⚠️ UNE RUBRIQUE IMPRIMÉE N'EST PAS UN MOT TROUVÉ DANS UN LIBELLÉ. Si le CRG titre lui-même
    #    « Rembt Prêt immobilier », alors le document reconnaît cette famille de charge — et le
    #    fait qu'elle n'apparaisse jamais dans `immeubles[].charges` devient significatif.
    c = collections.Counter()
    for ag in LYON:
        for x in tout[ag]:
            if PRET.search(x['section']) or SALAIRE.search(x['section']):
                c[(x['agence'], x['section'][:40])] += 1
    for (ag, sec), n in c.most_common():
        print('  %-13s « %-40s »  %d ligne(s)' % (ag[:13], sec, n))

    entete('② `ACOMPTE PRO` = `ACOMPTE PROPRIÉTAIRE` ? CE QUE LE CORPUS DÉMONTRE')
    # ⚠️ ON CHERCHE UNE DÉMONSTRATION, PAS UNE VRAISEMBLANCE. Trois preuves possibles :
    #    la forme longue existe-t-elle ? sur le même compte ? sous la même rubrique ?
    formes = collections.Counter()
    for ag in AGENCES:
        for x in S.candidats(ag):
            for m in re.finditer(r"acomptes?\s+\w+", x['libelle_brut'], re.I):
                formes[m.group(0).upper()] += 1
            for m in re.finditer(r"acomptes?\s+\w+", x['section'], re.I):
                formes['[rubrique] ' + m.group(0).upper()] += 1
    print('  toutes les formes « ACOMPTE … » imprimées dans le corpus :')
    for f, n in formes.most_common():
        print('     %4d  %s' % (n, f))
    print()
    ap = [x for ag in AGENCES for x in S.candidats(ag)
          if re.search(r"acomptes?\s+pro\b", x['libelle_brut'], re.I)]
    apl = [x for ag in AGENCES for x in S.candidats(ag)
           if re.search(r"acomptes?\s+propri[ée]taire", x['libelle_brut'], re.I)
           or re.search(r"acomptes?\s+propri[ée]taire", x['section'], re.I)]
    print('  « ACOMPTE PRO » (abrégé)          : %d lignes  %s' % (len(ap), euros(sum(x['montant_source'] for x in ap))))
    print('  « ACOMPTE PROPRIÉTAIRE » (long)   : %d lignes  %s' % (len(apl), euros(sum(x['montant_source'] for x in apl))))
    print()
    print('  LES LIGNES « ACOMPTE PRO », UNE PAR UNE :')
    print('     %-13s %11s %-10s %-26s %-38s %s'
          % ('PÉRIMÈTRE', 'montant', 'compte', 'RUBRIQUE', 'LIBELLÉ', 'sens actuel'))
    for x in sorted(ap, key=lambda y: -y['montant_source']):
        print('     %-13s %11s %-10s %-26s %-38s %s'
              % (x['agence'][:13], euros(x['montant_source']), x['compte'], x['section'][:26],
                 x['libelle_brut'][:38], x['sens']))
    print()
    comptes_ap = {x['compte'] for x in ap}
    comptes_apl = {x['compte'] for x in apl}
    print('  comptes portant « ACOMPTE PRO »           : %s' % (sorted(comptes_ap) or '—'))
    print('  comptes portant « ACOMPTE PROPRIÉTAIRE »  : %s' % (sorted(comptes_apl) or '—'))
    print('  comptes portant LES DEUX formes           : %s'
          % (sorted(comptes_ap & comptes_apl) or 'AUCUN'))
    print()
    if comptes_ap & comptes_apl:
        print('  → ÉQUIVALENCE DÉMONTRÉE sur un même compte.')
    else:
        print('  → ÉQUIVALENCE NON DÉMONTRÉE PAR LE CORPUS. La forme longue n’apparaît sur aucun')
        print('    des comptes qui portent la forme abrégée : le rapprochement resterait une')
        print('    supposition de lecteur, pas une lecture. Contrairement à « DIRECT PRO », où')
        print('    « DIRECT PROPRIETAIRE » est imprimé sur le MÊME compte et le MÊME locataire.')


if __name__ == '__main__':
    main()
