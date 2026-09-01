# -*- coding: utf-8 -*-
"""
P6B — ANTÉRIORITÉ / REPORTS : D'OÙ VIENT LE STOCK, ET DE QUAND DATE-T-IL ?

⚠️ L'ÂGE D'UNE CRÉANCE EST MOINS CERTIFIABLE QUE SON MONTANT, ET IL FAUT L'ASSUMER. P5B a
   démontré 0 mouvement de règlement daté et 1 596 547,33 € de règlements sans affectation à une
   période. Sans affectation, ventiler un solde final entre « ancien » et « courant » par simple
   soustraction serait une invention. On ne crée donc AUCUNE règle FIFO, LIFO, « mois courant
   d'abord » ou prorata : `AFFECTATION NON DÉMONTRÉE` reste non démontrée (P5B, figée).

⚠️ ET UN REPORT N'EST PAS UNE CRÉANCE FINALE. Solde antérieur 1 000, appels 500, règlements 800
   ne fait pas une créance de 1 000 : le report dit l'OUVERTURE, P6A lit la CLÔTURE, P6B raconte
   l'histoire entre les deux. Les deux ne se confondent jamais.

⚠️ CE QUE CHAQUE FORMAT DÉMONTRE VRAIMENT DE L'ANCIENNETÉ — et ils sont très inégaux :

     septeo_spi   LE SEUL À DATER CHAQUE EURO. Il réénonce chaque mois impayé du passé, ligne
                  par ligne, avec sa période d'origine : « Loyer Octobre 2025 … 325,35 » en
                  colonne « Reste dû ». C'est un véritable détail d'arriéré.
     lyon · SIR   la colonne « Impayés » est imprimée SUR CHAQUE LIGNE DE PÉRIODE : l'origine
                  d'un impayé né dans la période est donc démontrée, ligne par ligne.
     emery_immo   « Impayés » n'est imprimé qu'à la ligne « Totaux » du lot → l'origine par
                  période N'EST PAS DÉMONTRABLE, comme pour ses règlements (`P5B-EXC-01`).

⚠️ UN « SOLDE ANTÉRIEUR » PEUT PORTER SA PROPRE PÉRIODE — ou pas. Le corpus imprime les deux :
   « Du 01.04.12 Au 30.04.12 Solde Antérieur 4706.51 » date le report à AVRIL 2012, quatorze ans
   avant le CRG qui le porte ; « Solde Antérieur 3700.00 » n'en dit rien. On ne fabrique jamais
   la date manquante : ANTÉRIEUR À LA PÉRIODE DU CRG — DATE D'ORIGINE NON DÉMONTRABLE.

⚠️ ET UN RÉÉNONCÉ D'ARRIÉRÉ N'EST PAS UN NOUVEL APPEL (`P5A-APPEL-03`, figée). Il sert à DATER
   la dette, jamais à l'augmenter.

Aucune recouvrabilité, prescription, provision ou perte n'est certifiée ici. Lecture seule.
"""
import sys
import os
import io
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402
import p5a_certification as P5A   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

GEOMETRIE = r'C:\tmp\p5a_geometrie.json'
DUMP = r'C:\tmp\p6b_anteriorite.json'

REPORT = 'report d’ouverture'
REENONCE = 'réénoncé d’arriéré'
COURANT = 'impayé né sur la période — origine démontrée'


def periodes_report(sha, page, lot, montant, geo):
    """La période qu'un « Solde Antérieur » imprime, quand il en imprime une."""
    for l in geo.get((sha, page, lot), []):
        if 'solde antérieur' not in l['libelle'].lower():
            continue
        if montant is not None and abs((l['colonnes'].get('Divers') or [None])[0] or 0
                                       - montant) > 0.01:
            continue
        return l['du'], l['au']
    return None, None


def antecedents(agence, geo):
    """Chaque information d'antériorité que le document porte, avec sa certitude."""
    for d in documents(agence):
        m = d.get('meta') or {}
        fm = d.get('_format')
        arrete = P4B.jour(m.get('date_arrete')) or P4B.jour(m.get('periode_fin'))
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            for rang, lo in enumerate(im.get('lots') or []):
                lib = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                base = {'agence': agence, 'format': fm, 'crg_fichier': d.get('_nom_original'),
                        'sha': d.get('_sha'), 'page': lo.get('page'),
                        'periode_crg': m.get('periode_cle'), 'date_arrete': arrete,
                        'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                        'compte': str(m.get('compte') or ''), 'immeuble': code,
                        'lot': str(lo.get('numero_lot') or ''), 'bloc': rang, 'locataire': lib,
                        'identite': P4B.colle(lib), 'niveau_source': 'lu'}

                # ① LE REPORT D'OUVERTURE — « Solde Antérieur »
                sa = lo.get('solde_anterieur')
                if sa:
                    deb, fin = periodes_report(d.get('_sha'), lo.get('page'),
                                               str(lo.get('numero_lot') or ''), float(sa), geo)
                    yield dict(base, type_antecedent=REPORT, montant=round(float(sa), 2),
                               periode_origine=('%s → %s' % (deb, fin)) if deb else None,
                               date_origine=deb,
                               libelle_source='ligne « Solde Antérieur » du bloc',
                               certitude=('antérieur démontré — période imprimée' if deb else
                                          'antérieur démontré — date d’origine non démontrable'))

                # ② LE REPORT IMPRIMÉ COMME LIGNE DE PÉRIODE — la forme d'EMERY.
                #    ⚠️ EMERY N'A AUCUN CHAMP `solde_anterieur` : ses 384 blocs sont à zéro.
                #       Il imprime son report en LIGNE, dans la colonne « Divers » :
                #       « Du 01.04.12 Au 30.04.12 Solde Antérieur 4706.51 ». Ne lire que le champ
                #       faisait disparaître 135 191,76 € d'antériorité — et, avantage inattendu,
                #       cette forme DATE le report, ce que « Solde Antérieur 3700.00 » ne fait pas.
                for l in geo.get((d.get('_sha'), lo.get('page'),
                                  str(lo.get('numero_lot') or '')), []):
                    if 'solde antérieur' not in l['libelle'].lower():
                        continue
                    for v in l['colonnes'].get('Divers', []):
                        yield dict(base, type_antecedent=REPORT, montant=round(float(v), 2),
                                   periode_origine='%s → %s' % (l['du'], l['au']),
                                   date_origine=l['du'],
                                   libelle_source='ligne « Solde Antérieur » datée, colonne Divers',
                                   certitude='antérieur démontré — période imprimée')

                # ③ LE RÉÉNONCÉ D'ARRIÉRÉ — SEPTEO redit chaque mois impayé, avec sa période
                if fm == 'septeo_spi':
                    for a in lo.get('appels') or []:
                        if a.get('reste_du') is None:
                            continue
                        appele = [a.get('loyers'), a.get('charges'), a.get('autres')]
                        libe = str(a.get('libelle') or '')
                        deb, fin = P4B.bornes_libelle(libe)
                        neuf = any(v is not None for v in appele)
                        yield dict(base, type_antecedent=(COURANT if neuf else REENONCE),
                                   montant=round(float(a['reste_du']), 2),
                                   periode_origine=('%s → %s' % (deb, fin)) if deb else
                                                   P5A.texte_periode(libe),
                                   date_origine=deb, libelle_source=libe,
                                   certitude=('période d’origine démontrée' if deb or
                                              P5A.texte_periode(libe)
                                              else 'origine indéterminable'))
                else:
                    # ③ CHEZ LYON ET GROUPE SIR, « Impayés » EST IMPRIMÉ PAR LIGNE DE PÉRIODE :
                    #    l'origine d'un impayé né dans la période est donc démontrée.
                    #    ⚠️ PAS CHEZ EMERY : il ne l'imprime qu'au total du lot.
                    for r in lo.get('mois') or []:
                        if not r.get('impayes'):
                            continue
                        deb, fin = P4B.jour(r.get('du')), P4B.jour(r.get('au'))
                        yield dict(base, type_antecedent=COURANT,
                                   montant=round(float(r['impayes']), 2), page=r.get('page'),
                                   periode_origine=('%s → %s' % (deb, fin)) if deb else None,
                                   date_origine=deb,
                                   libelle_source='colonne « Impayés » de la ligne de période',
                                   certitude=('période d’origine démontrée' if deb
                                              else 'origine indéterminable'))


CHAINE = ('crg_fichier', 'sha', 'page', 'date_arrete', 'periode_crg', 'proprietaire', 'compte',
          'immeuble', 'lot', 'type_antecedent', 'montant', 'libelle_source', 'niveau_source',
          'certitude')


def certifier(agences):
    ok = True
    geo = collections.defaultdict(list)
    if os.path.exists(GEOMETRIE):
        for l in json.load(io.open(GEOMETRIE, encoding='utf-8')):
            geo[(l['sha'], l['page'], l['lot'])].append(l)

    tout = {ag: list(antecedents(ag, geo)) for ag in agences}

    entete('P6B — CE QUE CHAQUE FORMAT DÉMONTRE DE L’ANTÉRIORITÉ')
    print('  %-16s %-13s %9s %16s %11s %16s %11s %16s' %
          ('PÉRIMÈTRE', 'format', 'reports', 'montant', 'réénoncés', 'montant',
           'nés période', 'montant'))
    for ag in agences:
        c, m = collections.Counter(), collections.defaultdict(float)
        for a in tout[ag]:
            c[a['type_antecedent']] += 1
            m[a['type_antecedent']] += a['montant']
        fm = collections.Counter(a['format'] for a in tout[ag]).most_common(1)
        print('  %-16s %-13s %9d %16s %11d %16s %11d %16s' %
              (ag, fm[0][0] if fm else '—', c[REPORT], euros(m[REPORT]), c[REENONCE],
               euros(m[REENONCE]), c[COURANT], euros(m[COURANT])))
    print('  ⚠ EMERY n’imprime « Impayés » qu’au total du lot : aucun impayé n’y est daté par')
    print('    période. VIENNE, lui, réénonce chaque mois impayé — le seul à dater chaque euro.')

    entete('P6B — CLASSES D’ANTÉRIORITÉ (aucune classe d’âge fabriquée)')
    CL = ('période d’origine démontrée', 'antérieur démontré — période imprimée',
          'antérieur démontré — date d’origine non démontrable', 'origine indéterminable')
    print('  %-16s %28s %30s %34s %22s' %
          ('PÉRIMÈTRE', 'origine datée', 'antérieur — période imprimée',
           'antérieur — date inconnue', 'indéterminable'))
    for ag in agences:
        c, m = collections.Counter(), collections.defaultdict(float)
        for a in tout[ag]:
            c[a['certitude']] += 1
            m[a['certitude']] += a['montant']
        print('  %-16s %13d %14s %13d %16s %13d %20s %8d %13s' %
              ((ag,) + sum(((c[k], euros(m[k])) for k in CL), ())))
    print('  ⚠ pas de tranches 0-30 / 31-60 / 61-90 : elles supposeraient une affectation des')
    print('    règlements que P5B a démontrée NON DÉMONTRÉE. Aucun faux aging.')

    entete('P6B — DEUX NOTIONS QU’IL EST INTERDIT DE CONFONDRE')
    # ⚠️ LE PIÈGE DE P6A SE REJOUE ICI, ET IL FAILLIT PASSER. Un report est RÉÉNONCÉ à chaque
    #    CRG : additionner ses occurrences sur six trimestres compte le même arriéré six fois —
    #    exactement le défaut de l'ancien `encours`. « 8 384 657,72 € d'antériorité » aurait été
    #    le nouveau faux gros chiffre. On sépare donc, et on nomme.
    print('  %-16s %12s %20s %14s %22s' %
          ('PÉRIMÈTRE', 'occurrences', 'somme documentaire', 'objets', 'dernière observation'))
    tA = tB = 0.0
    for ag in agences:
        r = [a for a in tout[ag] if a['type_antecedent'] == REPORT]
        dern = {}
        for a in r:
            k = (a['compte'], a['immeuble'], a['lot'], a['identite'])
            if k not in dern or (a['date_arrete'] or '') > (dern[k]['date_arrete'] or ''):
                dern[k] = a
        sa = sum(a['montant'] for a in r)
        sb = sum(a['montant'] for a in dern.values())
        tA, tB = tA + sa, tB + sb
        print('  %-16s %12d %20s %14d %22s' % (ag, len(r), euros(sa), len(dern), euros(sb)))
    print('  %-16s %12s %20s %14s %22s' % ('TOTAL', '', euros(tA), '', euros(tB)))
    print('  ⚠ COLONNE 2 = UNE SOMME D’OCCURRENCES DOCUMENTAIRES, PAS UN STOCK. Elle compte le')
    print('    même report autant de fois qu’il est réénoncé. Seule la colonne 4 — dernière')
    print('    observation de chaque objet — est une antériorité sans double comptage temporel.')

    entete('P6B — LE REPORT N’EST PAS LA CRÉANCE FINALE')
    # ⚠️ ON MONTRE LES DEUX CÔTE À CÔTE, JAMAIS L'UN À LA PLACE DE L'AUTRE.
    print('  %-16s %-12s %18s %20s %18s' %
          ('PÉRIMÈTRE', 'date arrêté', 'report d’ouverture', 'stock final (P6A)', 'écart'))
    for ag in agences:
        rep = collections.defaultdict(float)
        for a in tout[ag]:
            if a['type_antecedent'] == REPORT:
                rep[a['date_arrete']] += a['montant']
        fin = collections.defaultdict(float)
        for d in documents(ag):
            dt = P4B.jour((d.get('meta') or {}).get('date_arrete'))
            for im in d.get('immeubles') or []:
                for lo in im.get('lots') or []:
                    v = lo.get('total_impaye')
                    if v is None and d.get('_format') == 'septeo_spi':
                        v = sum(x.get('reste_du') or 0 for x in lo.get('appels') or []) or None
                    if v:
                        fin[dt] += float(v)
        for dt in sorted(set(rep) | set(fin), key=lambda x: x or ''):
            print('  %-16s %-12s %18s %20s %18s' %
                  (ag, dt, euros(rep[dt]), euros(fin[dt]), euros(fin[dt] - rep[dt])))

    entete('P6B — PROVENANCE')
    print('  %-16s %12s %14s' % ('PÉRIMÈTRE', 'antécédents', 'provenance'))
    for ag in agences:
        complet = sum(1 for a in tout[ag] if all(a.get(c) not in (None, '') for c in CHAINE)
                      and isinstance(a['page'], int))
        if complet != len(tout[ag]):
            ok = False
        print('  %-16s %12d %8d/%-5d%s' % (ag, len(tout[ag]), complet, len(tout[ag]),
                                           '   ⚠' if complet != len(tout[ag]) else ''))

    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps([a for ag in agences for a in tout[ag]],
                                                          ensure_ascii=False))
    print('\n  %d antécédents → %s' % (sum(len(v) for v in tout.values()), DUMP))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP6B — ANTÉRIORITÉ / REPORTS : %s'
          % ('OUI — chaque antécédent porte son type, sa période démontrée ou son indétermination'
             if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
