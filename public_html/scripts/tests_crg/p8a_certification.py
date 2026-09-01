# -*- coding: utf-8 -*-
"""
P8A — CERTIFICATION DES FLUX ENTRE LA RÉGIE ET LE PROPRIÉTAIRE.

Ce que cette phase certifie : **ce que le CRG imprime des mouvements régie ↔ propriétaire**, et
rien de plus. Pas le solde du propriétaire — c'est P8B.

⚠️ LES SIX INÉGALITÉS DE P8A.
   `FLUX ≠ DÉPENSE` · `FLUX ≠ PAIEMENT D'UNE CHARGE` · `FLUX ≠ ENCAISSEMENT LOCATAIRE` ·
   `FLUX ≠ COMPENSATION` · `FLUX ≠ STOCK` · `OCCURRENCE DOCUMENTAIRE ≠ MOUVEMENT BANCAIRE`.
   Une compensation ne déplace aucun fonds ; une mise en compte d'attente est une écriture
   interne ; un solde est une photographie. Aucun n'entre dans un total de flux.

⚠️ AUCUN NETTING. Un acompte de 30 000 € et une compensation de 30 000 € restent deux lignes.
   Les soustraire donnerait zéro là où le document imprime deux faits.

⚠️ ET AUCUN BÉNÉFICIAIRE INVENTÉ. Quand le document ne nomme ni le sens ni le destinataire, la
   ligne reste indéterminable et part en arbitrage — elle n'est jamais rattachée par
   vraisemblance.

Lecture seule. Aucune écriture MBI, aucune PROD.
"""
import collections
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import AGENCES, euros   # noqa: E402
import p7_socle as P7   # noqa: E402
import p8a_socle as S   # noqa: E402

LARG = 100
DIRECTS = ('REGIE_VERS_PROPRIETAIRE', 'PROPRIETAIRE_VERS_REGIE')
INDIRECTS = ('REGIE_VERS_PROPRIETAIRE_INDIRECT', 'TIERS_VERS_PROPRIETAIRE',
             'PROPRIETAIRE_VERS_TIERS', 'REGIE_VERS_LOCATAIRE')


def entete(t):
    print('=' * LARG)
    print(t)
    print('=' * LARG)


def cle_ligne(x):
    return (x['sha'], x['page'], x.get('y'), x['section'], x['libelle_brut'],
            x['montant_source'])


def main():
    ok = True

    # ⚠️ LES RÉÉDITIONS SONT NEUTRALISÉES ICI AUSSI. Elles ne portent aujourd'hui aucun flux ;
    #    ne pas les exclure ferait dépendre P8A d'un hasard du corpus.
    tout = {ag: [x for x in S.candidats(ag)
                 if x['crg_fichier'] not in P7.REEDITIONS] for ag in AGENCES}
    flux = [x for ag in AGENCES for x in tout[ag] if x['sens'] in DIRECTS]
    indir = [x for ag in AGENCES for x in tout[ag] if x['sens'] in INDIRECTS]

    entete('P8A — LES FLUX RÉGIE ↔ PROPRIÉTAIRE, PAR PÉRIMÈTRE')
    print('  %-16s %10s %16s %10s %16s %16s'
          % ('PÉRIMÈTRE', 'régie→prop', 'montant', 'prop→régie', 'montant', 'total'))
    for ag in AGENCES:
        a = [x for x in tout[ag] if x['sens'] == 'REGIE_VERS_PROPRIETAIRE']
        b = [x for x in tout[ag] if x['sens'] == 'PROPRIETAIRE_VERS_REGIE']
        ma = sum(x['montant_source'] for x in a)
        mb = sum(x['montant_source'] for x in b)
        print('  %-16s %10d %16s %10d %16s %16s'
              % (ag, len(a), euros(ma), len(b), euros(mb), euros(ma + mb)))
    print('  %-16s %10d %16s %10d %16s %16s'
          % ('TOTAL', sum(1 for x in flux if x['sens'] == DIRECTS[0]),
             euros(sum(x['montant_source'] for x in flux if x['sens'] == DIRECTS[0])),
             sum(1 for x in flux if x['sens'] == DIRECTS[1]),
             euros(sum(x['montant_source'] for x in flux if x['sens'] == DIRECTS[1])),
             euros(sum(x['montant_source'] for x in flux))))

    entete('P8A — LES FAMILLES, ET LE SENS QU’ELLES PORTENT')
    print('  %-58s %6s %16s' % ('FAMILLE', 'lignes', 'montant'))
    par = collections.defaultdict(list)
    for x in flux:
        par[x['famille']].append(x)
    for f, v in sorted(par.items(), key=lambda kv: -sum(y['montant_source'] for y in kv[1])):
        print('  %-58s %6d %16s' % (f[:58], len(v), euros(sum(y['montant_source'] for y in v))))

    entete('P8A — LE SIGNE CONFIRME-T-IL LE SENS LU SUR LE LIBELLÉ ?')
    # ⚠️ LE SIGNE NE FONDE PAS LE SENS. Il le corrobore : une discordance est une anomalie à
    #    instruire, jamais une correction silencieuse.
    for s in DIRECTS:
        v = [x for x in flux if x['sens'] == s]
        att = 'debit' if s == 'REGIE_VERS_PROPRIETAIRE' else 'credit'
        conf = sum(1 for x in v if x[att])
        print('  %-26s %5d lignes · %d conforme(s) au signe attendu (%s)'
              % (s, len(v), conf, att))
        if conf != len(v):
            ok = False
            print('     ⚠ DISCORDANCE : %d ligne(s)' % (len(v) - conf))

    entete('P8A — LA CHAÎNE DE RECONSTRUCTION, LIGNE PAR LIGNE')
    manque = collections.Counter()
    for x in flux:
        if not x['proprietaire']:
            manque['propriétaire nommé'] += 1
        if not x['compte']:
            manque['compte mandant'] += 1
        if not x['crg_fichier'] or not isinstance(x['page'], int):
            manque['PDF et page'] += 1
        if not x['libelle_brut'].strip():
            manque['libellé brut'] += 1
        if not x['periode_crg']:
            manque['période du CRG'] += 1
        if not x['date_source']:
            manque['date du mouvement'] += 1
    print('  %d flux · chaque ligne doit porter : qui → vers qui → montant → sens → nature →'
          % len(flux))
    print('  période/date → compte/mandat → PDF → page → libellé brut.')
    print()
    for c in ('propriétaire nommé', 'compte mandant', 'PDF et page', 'libellé brut',
              'période du CRG', 'date du mouvement'):
        n = manque.get(c, 0)
        etat = 'complet' if not n else '%d manquant(s)' % n
        print('     %-22s %s' % (c, etat))
    dur = [c for c in ('propriétaire nommé', 'compte mandant', 'PDF et page', 'libellé brut',
                       'période du CRG') if manque.get(c)]
    if dur:
        ok = False
    print()
    print('  ⚠ LA DATE DU MOUVEMENT EST LA SEULE ABSENCE ADMISE : le CRG ne l’imprime pas')
    print('    toujours. Elle est alors DATE NON DÉMONTRABLE, jamais remplacée par la date')
    print('    d’arrêté — qui daterait le document, pas le virement.')

    entete('P8A — L’UNICITÉ : OCCURRENCE ≠ MOUVEMENT MÉTIER')
    b = S.bases(flux)
    print('  occurrences %d · objets certains %d · candidats doublons %d'
          % (b['occurrences'], b['objets_certains'], b['candidats_doublons']))
    print('  BORNE BASSE — FLUX UNIQUES DÉMONTRÉS      %s' % euros(b['total_basse']))
    print('  BORNE HAUTE — OCCURRENCES DOCUMENTAIRES   %s' % euros(b['total_haute']))
    print('  TOTAL MÉTIER EXACT                        %s'
          % (euros(b['total_basse']) if b['total_basse'] == b['total_haute']
             else 'NON DÉMONTRABLE'))

    entete('P8A — LES FLUX INDIRECTS, COMPTÉS À PART')
    # ⚠️ ILS NE S’ADDITIONNENT PAS AUX FLUX DIRECTS. Un loyer saisi par le Trésor réduit une
    #    dette du propriétaire sans qu’un euro ne transite par la régie : le mêler aux
    #    reversements ferait croire à une trésorerie qui n’a pas existé.
    print('  %-58s %6s %16s' % ('SENS', 'lignes', 'montant'))
    for s in INDIRECTS:
        v = [x for x in indir if x['sens'] == s]
        if v:
            print('  %-58s %6d %16s' % (s, len(v), euros(sum(y['montant_source'] for y in v))))
    print('  %-58s %6d %16s' % ('TOTAL INDIRECT — jamais additionné aux flux directs',
                                len(indir), euros(sum(x['montant_source'] for x in indir))))

    entete('P8A — CONTRÔLE DE NON-DOUBLE-COMPTAGE AVEC P7')
    # ⚠️ P7 ET P8A LISENT LES MÊMES CONTENEURS. Rien ne garantit a priori qu'une ligne ne soit
    #    pas comptée des deux côtés : on le vérifie sur la position imprimée.
    cles7 = {(x['sha'], x['page'], x.get('y'), x['entete_source'], x['libelle_brut'],
              x['montant_source'])
             for ag in AGENCES for x in P7.lignes(ag) if x['cote'] in ('P7A', 'P7B')}
    cles8 = {cle_ligne(x) for x in flux + indir}
    comm = cles7 & cles8
    print('  lignes certifiées P7A/P7B : %d · lignes P8A : %d · EN COMMUN : %d'
          % (len(cles7), len(cles8), len(comm)))
    if comm:
        ok = False
        for c in list(comm)[:5]:
            print('     ⚠ %s' % str(c)[:92])
    else:
        print('  aucune ligne comptée des deux côtés.')

    entete('P8A — CE QUI N’EST PAS UN FLUX, ET RESTE DEHORS')
    for s in ('ECRITURE_SANS_TRESORERIE', 'SORTIE_VERS_COMPTE_ATTENTE', 'sans objet (stock)',
              'sans objet (dépense)', 'SENS_INDETERMINABLE'):
        v = [x for ag in AGENCES for x in tout[ag] if x['sens'] == s]
        if v:
            print('  %-34s %5d lignes %16s' % (s, len(v),
                                               euros(sum(x['montant_source'] for x in v))))
    print('  ⚠ aucun de ces montants n’entre dans un total de flux propriétaire.')

    entete('P8A — INDÉTERMINABLES ET ARBITRAGES')
    ind = [x for ag in AGENCES for x in tout[ag] if x['sens'] == 'SENS_INDETERMINABLE']
    print('  %d ligne(s) indéterminables, %s' % (len(ind),
                                                 euros(sum(x['montant_source'] for x in ind))))
    for f, n in collections.Counter(x['famille'][:56] for x in ind).most_common(6):
        m = sum(x['montant_source'] for x in ind if x['famille'][:56] == f)
        print('     %-58s %4d %14s' % (f, n, euros(m)))

    entete('P8A : %s' % ('OUI — sens, unicité, provenance et frontières démontrés'
                         if ok else 'NON — voir les ⚠ ci-dessus'))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
