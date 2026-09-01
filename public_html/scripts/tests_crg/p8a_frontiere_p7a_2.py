# -*- coding: utf-8 -*-
"""
CONTRÔLE DE FRONTIÈRE P8A ↔ P7A — SECONDE PASSE : DÉPENSE OU PAIEMENT ?

⚠️ LA PREMIÈRE PASSE A MONTRÉ 31 LIGNES DE NATURE ABSENTE DE P7A (prêt, salaire). Avant de
   crier à la remise en cause, deux questions décident, et elles sont documentaires :

   ① CES LIGNES DÉCRIVENT-ELLES UNE DÉPENSE OU SON PAIEMENT ? « ENGIE-GDF SUEZ PRELVT ENGIE au
      20.04.2026 » nomme un PRÉLÈVEMENT, « SIP LYON 1 Avis n°… » nomme le règlement d'un AVIS.
      Si le conteneur est un journal de règlements, il ne concurrence pas P7A : il en paie les
      lignes. `DÉPENSE ≠ PAIEMENT`.

   ② LE VOCABULAIRE « PRÊT » ET « SALAIRE » EXISTE-T-IL DANS `immeubles[].charges` ? S'il y est,
      P7A a eu la famille sous les yeux et l'a traitée. S'il n'y est nulle part, alors ces
      natures ne sont imprimées QUE dans le compte mandant.

Lecture seule. P7A n'est pas touchée.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, documents, euros   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 96
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')

# ⚠️ CE QUI NOMME UN RÈGLEMENT, PAS UNE DÉPENSE : un moyen de paiement, un bénéficiaire
#    bancaire, une date d'exécution, une référence d'avis. Une ligne de dépense n'en a pas
#    besoin — elle dit ce qui est dû, pas comment il a été payé.
PAIEMENT = re.compile(r"\bvirt\b|\bvrt\b|\bvir\b|virement|pr[ée]l[èe]v|prelvt|\bpr[ée]lvt\b|"
                      r"r[èe]glement|reglement|ch[èe]que|cheque|\bavis\s+n|pay[ée]|"
                      r"fournisseurs?\s+divers|\bau\s+\d{1,2}[./]\d{1,2}[./]\d{2,4}", re.I)
PRET = re.compile(r"\bpr[êe]ts?\b|emprunt|[ée]ch[ée]ance\s+pr", re.I)
SALAIRE = re.compile(r"\bsalaires?\b|\bpaie\b|bulletin\s+de\s+paie", re.I)


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def main():
    dep = [x for ag in LYON for x in S.candidats(ag)
           if x['conteneur'] == 'mandat.operations' and x['sens'] == 'sans objet (dépense)']

    entete('① CES LIGNES DÉCRIVENT-ELLES UNE DÉPENSE, OU SON RÈGLEMENT ?')
    paye = [x for x in dep if PAIEMENT.search(x['libelle_brut'])]
    print('  %d des %d lignes portent une marque de RÈGLEMENT dans leur libellé — %s'
          % (len(paye), len(dep), euros(sum(x['montant_source'] for x in paye))))
    print('  soit %.1f %% des lignes et %.1f %% du montant.'
          % (100.0 * len(paye) / max(len(dep), 1),
             100.0 * sum(x['montant_source'] for x in paye)
             / max(sum(x['montant_source'] for x in dep), 0.01)))
    print()
    print('  marques observées :')
    for m, n in collections.Counter(
            (PAIEMENT.search(x['libelle_brut']).group(0).upper().strip()
             if PAIEMENT.search(x['libelle_brut']) else '(aucune')
            for x in dep).most_common(12):
        print('     %-22s %4d' % (m, n))
    print()
    print('  LES LIGNES SANS AUCUNE MARQUE DE RÈGLEMENT :')
    for x in sorted([x for x in dep if not PAIEMENT.search(x['libelle_brut'])],
                    key=lambda y: -y['montant_source'])[:14]:
        print('     %-13s %12s  [%-24s] %s' % (x['agence'][:13], euros(x['montant_source']),
              x['section'][:24], x['libelle_brut'][:42]))

    entete('② COMPARAISON DE FORME AVEC LES LIGNES CERTIFIÉES PAR P7A')
    p7l = [x for ag in AGENCES for x in P7.lignes(ag) if x['cote'] in ('P7A', 'P7B')]
    p7p = [x for x in p7l if PAIEMENT.search(x['libelle_brut'])]
    print('  lignes P7A/P7B portant une marque de règlement : %d / %d  (%.1f %%)'
          % (len(p7p), len(p7l), 100.0 * len(p7p) / max(len(p7l), 1)))
    print('  lignes `mandat.operations` en portant une      : %d / %d  (%.1f %%)'
          % (len(paye), len(dep), 100.0 * len(paye) / max(len(dep), 1)))
    print()
    print('  ⚠️ Deux conteneurs, deux grammaires : l’un dit CE QUI EST DÛ, l’autre COMMENT IL A')
    print('     ÉTÉ PAYÉ — bénéficiaire bancaire, moyen de paiement, date d’exécution.')

    entete('③ « PRÊT » ET « SALAIRE » EXISTENT-ILS DANS `immeubles[].charges` ?')
    for nom, motif in (('prêt / emprunt', PRET), ('salaire / paie', SALAIRE)):
        hits = []
        for ag in AGENCES:
            for d in documents(ag):
                for im in d.get('immeubles') or []:
                    for ch in im.get('charges') or []:
                        t = '%s %s' % (ch.get('entete') or '', ch.get('libelle') or '')
                        if motif.search(t):
                            hits.append((ag, d.get('_nom_original'), ch.get('page'),
                                         str(ch.get('entete') or ''), str(ch.get('libelle') or ''),
                                         float(ch.get('debit') or 0)))
        print('  %-18s dans `immeubles[].charges` : %d ligne(s)' % (nom, len(hits)))
        for h in hits[:8]:
            print('       %-13s p.%-4s [%-26s] %-34s %10s'
                  % (h[0][:13], h[2], h[3][:26], h[4][:34], euros(h[5])))
        if hits:
            # ce que P7A en a fait
            c = collections.Counter()
            for ag in AGENCES:
                for x in P7.lignes(ag):
                    if motif.search('%s %s' % (x['entete_source'], x['libelle_brut'])):
                        c[(x['cote'], x['nature'][:44])] += 1
            print('       → P7A/P7B les a classées :')
            for (cote, nat), n in c.most_common(6):
                print('           %-6s %-46s %d' % (cote, nat, n))
        print()

    entete('VERDICT')
    graves = [x for x in dep
              if (PRET.search(x['libelle_brut']) or SALAIRE.search(x['libelle_brut']))
              and not PAIEMENT.search(x['libelle_brut'])]
    print('  Lignes de nature « prêt » ou « salaire » SANS marque de règlement : %d   %s'
          % (len(graves), euros(sum(x['montant_source'] for x in graves))))
    for x in sorted(graves, key=lambda y: -y['montant_source']):
        print('     %-13s %12s  [%-24s] %s' % (x['agence'][:13], euros(x['montant_source']),
              x['section'][:24], x['libelle_brut'][:44]))


if __name__ == '__main__':
    main()
