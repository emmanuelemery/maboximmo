# -*- coding: utf-8 -*-
"""
LECTURE MÉTIER GUIDÉE D'UN COMPTE MANDANT — compte 01040000, SARL GROUPE SIR.

⚠️ ON NE CLASSE RIEN ET ON NE DÉDUIT RIEN. Ce programme n'a qu'un but : restituer le compte
   DANS SON ORDRE DOCUMENTAIRE pour qu'Emmanuel puisse expliquer la grammaire réelle du compte
   de gestion. Toute qualification serait ici une pétition de principe — c'est exactement ce
   que la lecture guidée doit corriger.

⚠️ ORDRE DOCUMENTAIRE = période, puis page, puis position verticale. Le lecteur `lyon` conserve
   `y` : c'est ce qui permet de rendre les lignes dans l'ordre où l'œil les rencontre, et non
   dans l'ordre où le parseur les a produites.

Lecture seule. Aucune modification du lecteur, du référentiel ni des phases certifiées.
"""
import collections
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.stdout.reconfigure(encoding='utf-8')
from _socle import documents, euros   # noqa: E402

COMPTE = '01040000'
LARG = 118


def modele(o):
    """La FORME d'une écriture, dates et numéros retirés — sert uniquement à repérer les
       répétitions consécutives, jamais à qualifier."""
    l = str(o.get('libelle') or '')
    l = re.sub(r'\d{1,2}[./-]\d{1,2}[./-]\d{2,4}', '<date>', l)
    l = re.sub(r'n[°o]\s*[\d ]+', 'n<num>', l, flags=re.I)
    l = re.sub(r'\d[\d .,]*', '<n>', l)
    return re.sub(r'\s+', ' ', l).strip().upper()


def euro(v):
    v = float(v or 0)
    return euros(v) if v else ''


def main():
    docs = [d for d in documents('GROUPE SIR')
            if str((d.get('meta') or {}).get('compte') or '') == COMPTE]
    docs.sort(key=lambda d: str((d.get('meta') or {}).get('periode_cle')))

    meta0 = docs[0].get('meta') or {}
    print('=' * LARG)
    print('COMPTE MANDANT %s — %s' % (COMPTE, meta0.get('proprietaire') or ''))
    print('%d CRG, du %s au %s' % (len(docs),
                                   (docs[0].get('meta') or {}).get('periode_cle'),
                                   (docs[-1].get('meta') or {}).get('periode_cle')))
    print('=' * LARG)

    questions = []
    vus = set()

    for d in docs:
        m = d.get('meta') or {}
        mdt = d.get('mandat') or {}
        ops = list(mdt.get('operations') or [])
        print()
        print('█' * LARG)
        print('█  %s   ·   arrêté au %s   ·   %s   ·   %s pages'
              % (m.get('periode_cle'), m.get('date_arrete'),
                 d.get('_nom_original'), d.get('_pages')))
        print('█' * LARG)

        rep = mdt.get('report')
        if rep:
            print('  ┌─ REPORT D’OUVERTURE tel qu’imprimé')
            print('  │  date %s · montant %s · sens %s · page %s'
                  % (rep.get('date'), euros(rep.get('montant')), rep.get('sens'), rep.get('page')))
            if 'report' not in vus:
                vus.add('report')
                questions.append(('REPORT D’OUVERTURE',
                                  'Le compte s’ouvre sur un report daté, tantôt créditeur '
                                  'tantôt débiteur. Que représente-t-il concrètement, et vis-'
                                  'à-vis de qui ?'))

        if not ops:
            print('  (aucune opération de mandat sur cette période)')
        else:
            print()
            print('  %-30s %-52s %11s %11s %6s'
                  % ('SECTION / RUBRIQUE IMPRIMÉE', 'LIBELLÉ EXACT', 'DÉBIT', 'CRÉDIT', 'PAGE'))
            print('  ' + '─' * (LARG - 4))
            ops.sort(key=lambda o: (o.get('page') or 0, o.get('y') or 0))
            i, n = 0, len(ops)
            sec_prec = None
            while i < n:
                o = ops[i]
                sec = str(o.get('entete') or '')
                mod = modele(o)
                # regroupement des répétitions CONSÉCUTIVES du même modèle sous la même rubrique
                j = i
                while (j + 1 < n and modele(ops[j + 1]) == mod
                       and str(ops[j + 1].get('entete') or '') == sec):
                    j += 1
                bloc = ops[i:j + 1]
                if sec != sec_prec:
                    print('  ├─ « %s »' % (sec or '(pas de rubrique imprimée)'))
                    sec_prec = sec
                montrer = bloc if len(bloc) <= 3 else bloc[:3]
                for o2 in montrer:
                    print('  │  %-30s %-52s %11s %11s %6s'
                          % ('', str(o2.get('libelle') or '')[:52],
                             euro(o2.get('debit')), euro(o2.get('credit')), o2.get('page')))
                if len(bloc) > 3:
                    print('  │  %-30s … %d autres lignes du même modèle, total %s / %s'
                          % ('', len(bloc) - 3,
                             euros(sum(float(x.get('debit') or 0) for x in bloc)),
                             euros(sum(float(x.get('credit') or 0) for x in bloc))))
                if mod not in vus:
                    vus.add(mod)
                    questions.append(('« %s »   [rubrique : %s]'
                                      % (str(bloc[0].get('libelle') or '')[:60],
                                         sec or '—'), None))
                i = j + 1

        tot = mdt.get('totaux')
        sf = mdt.get('solde_final')
        si = mdt.get('soldes_immeubles')
        if si:
            print('  ├─ SOLDES D’IMMEUBLES imprimés : %s' % str(si)[:100])
            if 'si' not in vus:
                vus.add('si')
                questions.append(('SOLDES D’IMMEUBLES',
                                  'Le compte mandant récapitule aussi des soldes par immeuble. '
                                  'Comment s’articulent-ils avec le solde du compte ?'))
        if tot:
            print('  ├─ TOTAUX imprimés    : %s' % str(tot)[:100])
        if sf is not None:
            print('  └─ SOLDE FINAL imprimé : %s' % str(sf)[:100])
            if 'sf' not in vus:
                vus.add('sf')
                questions.append(('SOLDE FINAL DU COMPTE',
                                  'Que devient ce solde en pratique — versé, reporté, compensé ? '
                                  'Et sur quel délai ?'))

    # ⚠️ ON REGROUPE PAR FORME OBSERVÉE, PAS PAR SENS SUPPOSÉ. Émettre une question par libellé
    #    distinct en produisait 83 : c'est demander d'arbitrer des écritures, pas d'expliquer
    #    une grammaire. Le regroupement ci-dessous ne retient que ce qui est VISIBLE dans le
    #    texte — qui est nommé, quel verbe, quelle colonne — sans rien qualifier.
    FORMES = [
        ('« Loyers versés au propriétaire » + un libellé « DIRECT IMPOTS » / « (ATD) »',
         r"loyers?\s+vers[ée]s?\s+au\s+propri", None),
        ('« Honoraires de prestation » + « REGIE EMERY LOCA-IMMO … »',
         r"honoraires?\s+de\s+prestation|hono\s+compta", None),
        ('un FOURNISSEUR NOMMÉ + un n° de facture',
         r"n[°o]\s*\d", r"regie\s+emery|compensation|acompte"),
        ('« Taxe foncière » / « Autres Impôts » + « SIP <ville> »',
         r"taxe\s+fonci|autres\s+imp[oô]ts", None),
        ('« Rembt Prêt immobilier » + un virement banque',
         r"rembt\s+pr[êe]t|pr[êe]t\s+", None),
        ('« ACOMPTE PRO » / « ACOMPTE <ENTITÉ> » / « VRT <ENTITÉ>/<BANQUE> »',
         r"acompte|\bvrt\b|\bvirt\b|\bvir\b", r"compensation|saisie"),
        ('« COMPENSATION <X> PAR <Y> » et « Compensation Solde débiteur Mdt <n> »',
         r"compensation|compensat", None),
        ('« VIRT BLOCAGE SAISIE » / « VIRT MLV TOTALE SAISIE »',
         r"saisie", None),
        ('« Indemnité sinistre assurance » + « REVERSEMENT INDEMNITE … »',
         r"indemnit[ée]|sinistre", None),
        ('« TRNASFERT SUR SMH » / « VRT LOCA IMMO HOLDING » — vers une autre entité',
         r"transfert|trnasfert|holding", None),
        ('le préfixe « FOURNISSEURS DIVERS » devant un virement',
         r"fournisseurs?\s+divers", None),
        ('une rubrique qui est une ADRESSE, avec un libellé réduit à « / »',
         r"^[\s/.-]*$", None),
        ('« + SALAIRE SRB » / « Salaire » comme rubrique',
         r"salaire", None),
    ]
    FORMES = [(t, re.compile(p, re.I), re.compile(x, re.I) if x else None)
              for t, p, x in FORMES]

    corpus = []
    for d in docs:
        for o in (d.get('mandat') or {}).get('operations') or []:
            corpus.append((d, o))

    print()
    print('=' * LARG)
    print('❓ LES QUESTIONS — la grammaire du compte, pas les écritures une par une')
    print('=' * LARG)
    print('Emmanuel : pour chaque FORME ci-dessous — d’où vient l’argent, où va-t-il,')
    print('et qui le supporte réellement ?')
    print()
    k = 0
    restants = list(corpus)
    for titre, motif, exclu in FORMES:
        lot = [(d, o) for d, o in restants
               if (motif.search(str(o.get('entete') or '')) or motif.search(str(o.get('libelle') or '')))
               and not (exclu and (exclu.search(str(o.get('libelle') or ''))
                                   or exclu.search(str(o.get('entete') or ''))))]
        if not lot:
            continue
        restants = [x for x in restants if x not in lot]
        k += 1
        deb = sum(float(o.get('debit') or 0) for _, o in lot)
        cre = sum(float(o.get('credit') or 0) for _, o in lot)
        print('  %2d. %s' % (k, titre))
        print('      %d écritures · débit %s · crédit %s' % (len(lot), euros(deb), euros(cre)))
        for d, o in lot[:3]:
            print('      ex. [%s] « %s »  %s%s'
                  % (str(o.get('entete') or '')[:34], str(o.get('libelle') or '')[:48],
                     'D ' + euros(o.get('debit')) if float(o.get('debit') or 0) else '',
                     'C ' + euros(o.get('credit')) if float(o.get('credit') or 0) else ''))
        print()
    if restants:
        k += 1
        print('  %2d. LES ÉCRITURES QUI N’ENTRENT DANS AUCUNE FORME CI-DESSUS' % k)
        print('      %d écritures' % len(restants))
        for d, o in restants[:6]:
            print('      ex. [%s] « %s »'
                  % (str(o.get('entete') or '')[:34], str(o.get('libelle') or '')[:48]))
        print()
    for titre, aide in questions:
        if aide:
            k += 1
            print('  %2d. %s' % (k, titre))
            print('      %s' % aide)
            print()

    print('=' * LARG)
    print('❓ UNE QUESTION DE LECTURE, PAS DE MÉTIER')
    print('=' * LARG)
    print('  Sur ce compte, ce que le lecteur range en « rubrique » est très souvent une DATE')
    print('  ou un fragment : « au 24.02.2026 », « /Mdt 0104 SIR au 8.06.2026 »,')
    print('  « Acompte prop au 17.06.2026 ». Assemblées à leur ligne, elles se lisent comme la')
    print('  SUITE du libellé — « ACOMPTE PRO SIR/LCL au 24.02.2026 ».')
    print('  ❓ Confirmez-vous que sur ce format le CRG imprime le libellé sur DEUX fragments,')
    print('     et que la vraie rubrique est seulement celle qui nomme une famille')
    print('     (« Taxe foncière », « Soldes mandats », « Honoraires de prestation ») ?')


if __name__ == '__main__':
    main()
