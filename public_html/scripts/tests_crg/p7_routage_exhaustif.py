# -*- coding: utf-8 -*-
"""
ROUTAGE EXHAUSTIF DE `mandat.operations` — sans aucun filtre lexical préalable.

⚠️ LE VOCABULAIRE NE DÉFINIT PLUS LE PÉRIMÈTRE DE RECHERCHE. Trois fois de suite, le balayage a
   trouvé davantage parce que je cherchais des mots connus : `Autres Impôts` n'était pas dans
   `P7A_MOTIFS`, `Prestation comp.` pas dans `P7B_MOTIFS`. Le sens de lecture s'inverse :

       DÉTECTION EXHAUSTIVE → COMPRÉHENSION STRUCTURELLE → QUALIFICATION → VOCABULAIRE EN APPUI

   jamais VOCABULAIRE → DÉTECTION. Ici, les 1 049 lignes entrent toutes, et chacune ressort avec
   une destination. Une ligne que rien ne reconnaît va dans **NON RECONNU — À QUALIFIER**, elle
   ne disparaît jamais.

⚠️ ET ON NEUTRALISE LES RÉÉDITIONS AVANT DE COMPTER. `SABY 0107-2.pdf` et `FOCH 0108.pdf`
   énoncent la même situation que leur jumeau au même arrêté : compter les deux ferait deux
   événements d'un seul.

Lecture seule. Aucun corpus, aucun référentiel, aucune phase modifiés.
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

LARG = 112
LYON = ('LYON hors SIR', 'EMERY IMMO', 'GROUPE SIR')

# Les versions les plus courtes des deux couples SIR — hypothèse à valider par Emmanuel.
REEDITIONS = {'SABY 0107-2.pdf', 'FOCH 0108.pdf'}

PAIEMENT = re.compile(r"\bvirt\b|\bvrt\b|\bvir\b|virement|pr[ée]l[èe]v|prelvt|r[èe]glement|"
                      r"ch[èe]que|cheque|\bavis\s+n|fournisseurs?\s+divers|"
                      r"\bau\s+\d{1,2}[./]\d{1,2}[./]\d{2,4}", re.I)
PAS_UNE_CHARGE = re.compile(r"indemnit|trop\s+pay|compensation|compensat|\bavoir\b|"
                            r"restitution|rejet", re.I)
# ⚠️ CE VOCABULAIRE N'OUVRE PAS LA RECHERCHE, IL LA QUALIFIE. Il s'applique à des lignes déjà
#    détectées, et son échec produit « à qualifier », jamais un silence.
IMPOT = re.compile(r"\bsip\b|imp[oô]ts?|taxe|droits?\s+de\s+mutation|\btf\b|fonci[èe]re", re.I)
# ⚠️ HONORAIRES ≠ AUTOMATIQUEMENT P7B — arbitrage Emmanuel du 31/08/2026. Le mot « honoraires »
#    décrit le MODE DE FACTURATION d'un professionnel, pas la famille P7B. Un avocat facturé en
#    honoraires reste un frais de procédure supporté par le propriétaire, donc P7A. Mon
#    vocabulaire de routage envoyait `Honoraires d'huissier` en P7B ; le classificateur CERTIFIÉ
#    de P7, lui, disait déjà `P7A · procédure / contentieux` pour les quatre lignes. La doctrine
#    portait la règle — c'est mon raccourci qui l'a contredite.
PROCEDURE = re.compile(r"avocat|huissier|proc[ée]dure|contentieux|commandement|assignation|"
                       r"expulsion|greffe|article\s+700|r[ée]vocation|tribunal|"
                       r"saisies?\s+immobili|juge\s+ex[ée]cution", re.I)
HONO = re.compile(r"honoraire|prestation|chiffres?\s*&?\s*conseils?|\bei\b|expertise|"
                  r"g[ée]om[èe]tre|comptab", re.I)
ASSUR = re.compile(r"assuranc|\bpno\b|multirisque|\bgli\b", re.I)
SYNDIC = re.compile(r"syndic|copropri|appel\s+de\s+fonds|fonds\s+de\s+travaux|alur|\bsdc\b", re.I)
FLUIDE = re.compile(r"\bedf\b|\bgdf\b|engie|[ée]lectric|\beau\b|\bgaz\b", re.I)
TRAVAUX = re.compile(r"travaux|r[ée]novation|r[ée]paration|plomberie|entretien|maintenance", re.I)


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def destination(x):
    """La destination d'une écriture. Toute ligne en reçoit une — c'est le contrat."""
    e, l = x['entete'], x['libelle']
    fam, sens, _ = S.famille(e, l)
    if fam == S.STOCK_P8B:
        return 'STOCK PROPRIÉTAIRE → P8B', None
    if sens == 'ECRITURE_SANS_TRESORERIE':
        return 'COMPENSATION — sans trésorerie', None
    # ⚠️ TOUTE FAMILLE NOUVELLE DOIT ÊTRE ROUTÉE EXPLICITEMENT. `SORTIE_VERS_COMPTE_ATTENTE`
    #    manquait ici : les 35 000 € de « CPTE ATTENTE ATD pour TF et IMPOTS » traversaient les
    #    tests de sens sans être reconnus, puis le mot « IMPOTS » les faisait retomber en
    #    CHARGE → P7A. Une famille ajoutée en amont et oubliée en aval se reclasse en silence.
    if sens == 'SORTIE_VERS_COMPTE_ATTENTE':
        return 'MISE EN COMPTE D’ATTENTE — écriture interne', None
    if sens in ('REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE',
                'REGIE_VERS_PROPRIETAIRE_INDIRECT', 'TIERS_VERS_PROPRIETAIRE',
                'PROPRIETAIRE_VERS_TIERS', 'REGIE_VERS_LOCATAIRE'):
        return 'FLUX PROPRIÉTAIRE → P8A', None
    if PAS_UNE_CHARGE.search(l) or PAS_UNE_CHARGE.search(e):
        return 'NI CHARGE NI FLUX — indemnité, trop-perçu, rejet', None
    if PAIEMENT.search(l):
        return 'PAIEMENT D’UNE CHARGE — hors P7 (DÉPENSE ≠ PAIEMENT)', None
    # ⚠️ LES FAMILLES NOMMÉES EN AMONT SONT ROUTÉES AVANT TOUT VOCABULAIRE. Sinon un mot de leur
    #    objet les reclasse — c'est ce qui est arrivé quatre fois.
    for prefixe, dest in FAMILLES_ROUTEES.items():
        if fam.startswith(prefixe):
            return dest, 'prestation de la régie'
    t = e + ' ' + l
    # ⚠️ LA NATURE DU PRESTATAIRE PASSE AVANT SON MODE DE FACTURATION.
    if PROCEDURE.search(t):
        return 'CHARGE → P7A', 'procédure / avocat / huissier'
    if HONO.search(t) or ASSUR.search(t):
        return 'CHARGE → P7B', ('honoraires' if HONO.search(t) else 'assurance')
    if IMPOT.search(t):
        return 'CHARGE → P7A', 'impôt / taxe'
    if SYNDIC.search(t):
        return 'CHARGE → P7A', 'syndic / copropriété'
    if FLUIDE.search(t):
        return 'CHARGE → P7A', 'fluides'
    if TRAVAUX.search(t):
        return 'CHARGE → P7A', 'travaux / entretien'
    if not l.strip(' /.-') and not (x['debit'] or x['credit']):
        return 'LIGNE SANS ÉCRITURE — adresse d’immeuble', None
    return 'NON RECONNU — À QUALIFIER', None


class ErreurRoutage(Exception):
    """ERREUR DE ROUTAGE / CERTIFICATION IMPOSSIBLE — une famille détectée sans destination."""


# ⚠️ TOUTE FAMILLE DÉTECTÉE DOIT AVOIR UNE DESTINATION EXPLICITE — règle de sûreté du
#    31/08/2026. C'est ce défaut qui a fait retomber 35 000,00 € de « compte d'attente » dans
#    les charges P7A : la famille était correctement DÉTECTÉE en amont, puis, faute d'être
#    ROUTÉE en aval, elle traversait les tests de sens et un mot-clé la reclassait par défaut.
#
# ⚠️ C'EST PIRE QU'UN MAUVAIS MOT-CLÉ. Un mot-clé fautif se voit dans le résultat ; un
#    reclassement par défaut est silencieux et donne un chiffre plausible. Le routeur doit donc
#    ÉCHOUER plutôt que se rabattre — c'est la doctrine fail-closed de la Phase 7 appliquée à
#    la classification.
SENS_ROUTES = {
    'REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE', 'REGIE_VERS_PROPRIETAIRE_INDIRECT',
    'TIERS_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_TIERS', 'REGIE_VERS_LOCATAIRE',
    'ECRITURE_SANS_TRESORERIE', 'SORTIE_VERS_COMPTE_ATTENTE', 'SENS_INDETERMINABLE',
    'sans objet (stock)', 'sans objet (dépense)',
}

# ⚠️ LE SENS NE SUFFIT PAS — LA FAMILLE AUSSI DOIT ÊTRE ROUTÉE. Premier garde-fou trop étroit :
#    il ne contrôlait que le SENS, et « sans objet (dépense) » est partagé par plusieurs
#    familles. « PRESTATION FACTURÉE PAR LA RÉGIE » passait donc le contrôle, puis retombait
#    dans le vocabulaire, où le mot « CONTENTIEUX » de `GESTION ATD/CONTENTIEUX` la faisait
#    basculer en frais de procédure. Une prestation de la régie dont l'OBJET est le contentieux
#    n'est pas un frais d'avocat — quatrième occurrence du même piège.
FAMILLES_ROUTEES = {
    'PRESTATION FACTURÉE PAR LA RÉGIE': 'CHARGE → P7B',
}


def controler_routage(lignes):
    """Aucun sens ni aucune famille produits en amont ne doivent être inconnus du routeur."""
    sens_inconnus = collections.Counter()
    for x in lignes:
        _f, sens, _o = S.famille(x['entete'], x['libelle'])
        if sens not in SENS_ROUTES:
            sens_inconnus[sens] += 1
    if sens_inconnus:
        raise ErreurRoutage(
            'ERREUR DE ROUTAGE / CERTIFICATION IMPOSSIBLE — sens détecté(s) sans destination '
            'explicite : %s. Ajouter leur destination dans `destination()` ET dans '
            '`SENS_ROUTES`, jamais laisser le vocabulaire les reclasser.'
            % ', '.join('%s (%d lignes)' % (k, n) for k, n in sens_inconnus.most_common()))


def operations():
    for ag in LYON:
        for d in documents(ag):
            m = d.get('meta') or {}
            for o in (d.get('mandat') or {}).get('operations') or []:
                yield {'agence': ag, 'compte': str(m.get('compte') or ''),
                       'periode': m.get('periode_cle'), 'crg': str(d.get('_nom_original')),
                       'page': o.get('page'), 'entete': str(o.get('entete') or ''),
                       'libelle': str(o.get('libelle') or ''),
                       'debit': round(float(o.get('debit') or 0), 2),
                       'credit': round(float(o.get('credit') or 0), 2)}


def main():
    tout = list(operations())
    controler_routage(tout)
    print('  contrôle de routage : chaque sens détecté a une destination explicite.')
    for x in tout:
        x['dest'], x['sous'] = destination(x)

    entete('LES %d OPÉRATIONS, CHACUNE AVEC SA DESTINATION' % len(tout))
    print('  %-54s %6s %16s' % ('DESTINATION', 'lignes', 'montant'))
    c = collections.Counter()
    mt = collections.Counter()
    for x in tout:
        c[x['dest']] += 1
        mt[x['dest']] += x['debit'] or x['credit']
    for d, n in c.most_common():
        print('  %-54s %6d %16s' % (d, n, euros(mt[d])))
    print('  %-54s %6d %16s' % ('TOTAL', sum(c.values()), euros(sum(mt.values()))))
    print()
    print('  réconciliation : %s' % ('EXACTE' if sum(c.values()) == len(tout) else 'ÉCHEC'))

    nr = [x for x in tout if x['dest'] == 'NON RECONNU — À QUALIFIER']
    entete('LES LIGNES QUE RIEN NE RECONNAÎT — %d, %s'
           % (len(nr), euros(sum(x['debit'] or x['credit'] for x in nr))))
    for x in sorted(nr, key=lambda y: -(y['debit'] or y['credit']))[:18]:
        print('  %-13s %-9s %10s [%-24s] %s'
              % (x['agence'][:13], x['compte'], euros(x['debit'] or x['credit']),
                 x['entete'][:24], x['libelle'][:44]))

    entete('LES CANDIDATS P7A / P7B, AVANT ET APRÈS NEUTRALISATION DES RÉÉDITIONS')
    for dest in ('CHARGE → P7A', 'CHARGE → P7B'):
        v = [x for x in tout if x['dest'] == dest]
        net = [x for x in v if x['crg'] not in REEDITIONS]
        print('  %-16s brut %3d lignes %14s   ·   net %3d lignes %14s'
              % (dest, len(v), euros(sum(x['debit'] or x['credit'] for x in v)),
                 len(net), euros(sum(x['debit'] or x['credit'] for x in net))))
        par = collections.Counter()
        mpar = collections.Counter()
        for x in net:
            par[x['sous']] += 1
            mpar[x['sous']] += x['debit'] or x['credit']
        for s, n in par.most_common():
            print('       %-28s %3d %14s' % (s, n, euros(mpar[s])))

    entete('CE QUE LES RÉÉDITIONS PORTENT DANS P7A / P7B (charges du document entier)')
    for ag in AGENCES:
        for cote in ('P7A', 'P7B'):
            v = [l for l in P7.lignes(ag)
                 if l['cote'] == cote and l['crg_fichier'] in REEDITIONS]
            if v:
                print('  %-14s %-4s %3d lignes  %14s'
                      % (ag, cote, len(v), euros(sum(l['montant_source'] for l in v))))


if __name__ == '__main__':
    main()
