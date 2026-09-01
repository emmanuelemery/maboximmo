# -*- coding: utf-8 -*-
"""
CONTRÔLE DE FRONTIÈRE P8A ↔ P7A — les dépenses payées depuis le compte mandant.

⚠️ LA QUESTION N'EST PAS « P7 LISAIT-IL CE CONTENEUR ? » mais « CES DÉPENSES EXISTENT-ELLES
   AILLEURS ? ». Répondre « hors P7 parce que P7 ne lisait pas `mandat.operations` » serait
   circulaire : le périmètre d'un lecteur ne prouve pas le périmètre du document.

Trois issues possibles, et une seule est grave :
   ① MÊME DÉPENSE RÉÉNONCÉE      → P7A intacte, on documente la relation
   ② PAIEMENT D'UNE DÉPENSE CONNUE → P7A intacte : `DÉPENSE ≠ PAIEMENT` est déjà certifié
   ③ DÉPENSE MÉTIER ABSENTE DE P7A → STOP — REMISE EN CAUSE P7A

Ne modifie rien. Lecture seule. P7A n'est pas touchée.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 96
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def norme(l):
    """Le libellé débarrassé de ce qui varie d'une énonciation à l'autre."""
    l = re.sub(r'\d{1,2}[./-]\d{1,2}[./-]\d{2,4}', ' ', str(l or ''))
    l = re.sub(r'n[°o]\s*[\d ]+', ' ', l, flags=re.I)
    l = re.sub(r'[^a-zA-ZÀ-ÿ ]+', ' ', l)
    return re.sub(r'\s+', ' ', l).strip().upper()[:34]


def main():
    # ── ce que P7A/P7B ont certifié ────────────────────────────────────────────────────────
    p7 = collections.defaultdict(list)
    p7_par_sha = collections.defaultdict(list)
    p7_par_compte = collections.defaultdict(list)
    n7 = 0
    for ag in AGENCES:
        for x in P7.lignes(ag):
            if x['cote'] not in ('P7A', 'P7B'):
                continue
            n7 += 1
            p7[(ag, x['compte'], round(x['montant_source'], 2))].append(x)
            p7_par_sha[(x['sha'], round(x['montant_source'], 2))].append(x)
            p7_par_compte[(ag, x['compte'])].append(x)

    # ── les dépenses vues dans le compte courant du mandant ────────────────────────────────
    dep = [x for ag in LYON for x in S.candidats(ag)
           if x['conteneur'] == 'mandat.operations' and x['sens'] == 'sans objet (dépense)']

    entete('FRONTIÈRE P8A ↔ P7A — LE PÉRIMÈTRE COMPARÉ')
    print('  lignes de dépense certifiées par P7A/P7B (conteneur `immeubles[].charges`) : %d' % n7)
    print('  lignes de dépense vues dans `mandat.operations`, jamais lues par P7          : %d'
          % len(dep))
    print('  montant en jeu                                                              : %s'
          % euros(sum(x['montant_source'] for x in dep)))

    # ── le rapprochement, du plus fort au plus faible ──────────────────────────────────────
    classe = collections.defaultdict(list)
    for x in dep:
        m = round(x['montant_source'], 2)
        k_sha = (x['sha'], m)
        k_cpt = (x['agence'], x['compte'], m)
        nl = norme(x['libelle_brut'])
        voisins = p7_par_compte.get((x['agence'], x['compte']), [])
        if p7_par_sha.get(k_sha):
            classe['① MÊME CRG, MÊME MONTANT — la dépense est dans P7A du même document'].append(x)
        elif p7.get(k_cpt):
            classe['② MÊME COMPTE, MÊME MONTANT — dépense connue de P7A, autre énonciation'].append(x)
        elif nl and any(nl and nl in norme(y['libelle_brut']) or norme(y['libelle_brut']) in nl
                        for y in voisins if norme(y['libelle_brut'])):
            classe['③ MÊME COMPTE, LIBELLÉ CONCORDANT — montant différent'].append(x)
        elif nl and any(nl and (nl in norme(y['entete_source']) or norme(y['entete_source']) in nl)
                        for y in voisins if norme(y['entete_source'])):
            classe['④ MÊME COMPTE, RUBRIQUE P7A CONCORDANTE'].append(x)
        else:
            classe['⑤ AUCUN RAPPROCHEMENT — candidat DÉPENSE ABSENTE DE P7A'].append(x)

    entete('LE RAPPROCHEMENT DOCUMENTAIRE, LIGNE À LIGNE')
    for c in sorted(classe):
        v = classe[c]
        print('  %-66s %4d %14s' % (c[:66], len(v), euros(sum(y['montant_source'] for y in v))))

    inconnues = classe['⑤ AUCUN RAPPROCHEMENT — candidat DÉPENSE ABSENTE DE P7A']

    entete('LES CANDIDATS « ABSENTS DE P7A » — NATURE RÉELLE')
    # ⚠️ UNE DÉPENSE DU MANDANT N'EST PAS FORCÉMENT UNE DÉPENSE DU BIEN. Un prêt immobilier, un
    #    salaire, un honoraire de comptable, un impôt de société sont des charges du PROPRIÉTAIRE
    #    ou de son entité — P7A certifie ce que le bien supporte. La question n'est donc pas
    #    seulement « P7A l'a-t-il vue ? » mais « P7A DEVAIT-il la voir ? ».
    NATURES = [
        ('impôt / taxe du propriétaire', r"\bsip\b|imp[oô]t|taxe\s+fonci|\btf\s*20|avis\s+n"),
        ('remboursement de prêt', r"pr[êe]t\b|pret\b|emprunt"),
        ('salaire / paie', r"salaire|paie\b|\bei\b"),
        ('honoraires de conseil / expertise', r"honoraires?|chiffres?\s*&?\s*conseils?|avocat|"
                                              r"comptab|prestation"),
        ('fluides', r"engie|gdf|\bedf\b|\beau\b|[ée]lectric"),
        ('assurance', r"assuranc"),
        ('facture fournisseur', r"fournisseurs?\s+divers|facture|\bfact\b"),
        ('travaux / syndic', r"travaux|syndic|copropri"),
    ]
    NATURES = [(n, re.compile(p, re.I)) for n, p in NATURES]
    par_nature = collections.defaultdict(list)
    for x in inconnues:
        for n, mo in NATURES:
            if mo.search(x['libelle_brut']) or mo.search(x['section']):
                par_nature[n].append(x)
                break
        else:
            par_nature['non qualifiée'].append(x)
    for n, v in sorted(par_nature.items(), key=lambda kv: -sum(y['montant_source'] for y in kv[1])):
        print('  %-38s %4d %14s' % (n, len(v), euros(sum(y['montant_source'] for y in v))))
        for l, k in collections.Counter(re.sub(r'\d', '#', y['libelle_brut'])[:56]
                                        for y in v).most_common(3):
            print('        %3d  %s' % (k, l))

    entete('CES NATURES EXISTENT-ELLES DANS P7A, AILLEURS DANS LE CORPUS ?')
    # ⚠️ LA PREUVE DÉCISIVE. Si P7A certifie déjà des « taxe foncière » et des « prêt », alors le
    #    document les imprime dans les DEUX conteneurs et P7A n'a pas un trou de périmètre : il a
    #    un autre point de vue sur la même famille. Si une nature n'existe QUE dans le compte
    #    mandant, alors P7A ne l'a jamais vue — et c'est une remise en cause.
    natures_p7 = collections.Counter()
    for ag in AGENCES:
        for x in P7.lignes(ag):
            if x['cote'] in ('P7A', 'P7B'):
                natures_p7[x['nature']] += 1
    print('  %-38s %10s   %s' % ('NATURE VUE DANS LE COMPTE MANDANT', 'lignes', 'PRÉSENTE DANS P7A ?'))
    CORRESP = {
        'impôt / taxe du propriétaire': 'taxe propriétaire',
        'remboursement de prêt': None,
        'salaire / paie': None,
        'honoraires de conseil / expertise': 'autres honoraires',
        'fluides': 'fluides',
        'assurance': 'autre assurance',
        'facture fournisseur': 'facture fournisseur',
        'travaux / syndic': 'travaux',
    }
    for n, v in sorted(par_nature.items(), key=lambda kv: -sum(y['montant_source'] for y in kv[1])):
        cible = CORRESP.get(n, '—')
        if cible is None:
            etat = 'NON — aucune nature P7A équivalente'
        elif cible == '—':
            etat = '—'
        else:
            k = sum(c for nat, c in natures_p7.items() if cible in nat)
            etat = ('OUI — %d lignes P7A « %s »' % (k, cible)) if k else \
                   'NON — aucune ligne P7A « %s »' % cible
        print('  %-38s %10d   %s' % (n, len(v), etat))

    entete('VERDICT DU CONTRÔLE DE FRONTIÈRE')
    graves = [x for n, v in par_nature.items() for x in v if CORRESP.get(n, '—') is None]
    print('  Lignes rapprochées de P7A (① à ④)                    : %4d   %14s'
          % (len(dep) - len(inconnues),
             euros(sum(x['montant_source'] for x in dep) -
                   sum(x['montant_source'] for x in inconnues))))
    print('  Lignes sans rapprochement mais de nature connue de P7A : %4d   %14s'
          % (len(inconnues) - len(graves),
             euros(sum(x['montant_source'] for x in inconnues) -
                   sum(x['montant_source'] for x in graves))))
    print('  Lignes de nature ABSENTE du vocabulaire P7A            : %4d   %14s'
          % (len(graves), euros(sum(x['montant_source'] for x in graves))))
    print()
    for x in sorted(graves, key=lambda y: -y['montant_source'])[:12]:
        print('     %-13s %12s  [%-22s] %s' % (x['agence'][:13], euros(x['montant_source']),
              x['section'][:22], x['libelle_brut'][:44]))


if __name__ == '__main__':
    main()
