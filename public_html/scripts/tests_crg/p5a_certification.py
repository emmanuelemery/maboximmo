# -*- coding: utf-8 -*-
"""
P5A — APPELS : QU'EST-CE QUI A ÉTÉ APPELÉ AU LOCATAIRE ?

⚠️ TROIS NIVEAUX, JAMAIS FONDUS.
     OCCURRENCE   une ligne réellement imprimée dans le CRG
     APPEL MÉTIER l'événement de facturation adressé au locataire pour UNE période
     COMPOSANTE   la nature financière qui le compose
   Les formats ne les organisent pas pareil, et c'est le document qui décide :
     lyon · emery_immo   une LIGNE porte une période et JUSQU'À QUATRE colonnes chiffrées
                         (Loyers · Taxes · Provisions · Divers) → 1 occurrence = 1 appel
                         métier = n composantes
     septeo_spi          une LIGNE porte UNE nature pour UNE période → n occurrences
                         regroupées par (lot, période) = 1 appel métier

⚠️ UNE COLONNE N'EST PAS UNE NATURE MÉTIER. Chez LYON, l'entête imprimé est
   « Locataires Période Loyers Taxes Provisions Divers Total Réglés Impayés » : les trois
   premières colonnes NOMMENT leur nature, on peut donc les lire. Mais « Divers » ne nomme
   rien — c'est un fourre-tout, et le lecteur `lyon` ne conserve PAS le libellé de ces lignes
   (`P4B-EXC-02`). Un montant en colonne Divers est donc À QUALIFIER, jamais « nature divers ».

⚠️ ET CHEZ SEPTEO, UNE LIGNE SANS MONTANT APPELÉ N'EST PAS UN APPEL. L'entête est
   « Période Loyers Charges Autres Restedû », plus une colonne de droite non intitulée qui
   porte le montant crédité au compte. Vérifié au PDF (positions x) : Loyers@234 · Charges@279
   · Autres@337 · Restedû@379 · crédit@540. Une ligne « Loyer Mars 2026 469,99 » dont l'unique
   montant est à x=540 ne CRÉE aucun appel : elle RÈGLE un appel antérieur. Elle appartient à
   P5B. La compter ici doublerait les loyers appelés.

⚠️ PÉRIODE DU CRG ≠ PÉRIODE DE L'APPEL. Un CRG arrêté au 30/04 contient des appels d'avril,
   des régularisations anciennes, des proratas, et parfois des appels de mai. Les deux
   temporalités sont conservées séparément.

⚠️ ET MONTANT ZÉRO ≠ ABSENCE D'APPEL (`P4B-OCCUPATION-05`). Une période appelée à 0 € de loyer
   avec des charges appelées est une GRATUITÉ, pas un silence.

Aucune créance, aucun encours, aucun reste dû n'est certifié ici : ce sont des STOCKS (P6).
Lecture seule. Aucune écriture MBI.
"""
import crg_evenement as EV   # noqa: E402
import sys
import os
import io
import re
import json
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete, euros   # noqa: E402
import p4b_certification as P4B   # noqa: E402  (jour, bornes_libelle, plat — socle certifié)

sys.stdout.reconfigure(encoding='utf-8')

DUMP = r'C:\tmp\p5a_appels.json'

# la ventilation des « Divers », produite par p5a_divers_libelles.py puis p5a_divers_nature.py
QUALIFIE = r'C:\tmp\p5a_divers_qualifie.json'

# les colonnes chiffrées de `lyon` / `emery_immo`, et la nature que leur ENTÊTE nomme
COLONNES = (('loyers', 'loyer'), ('taxes', 'taxe'), ('provisions', 'charges/provisions'),
            ('divers', 'à qualifier'))
RE_REGUL = re.compile(r'r[ée]gul', re.I)
RE_ANNEE = re.compile(r'\b(20\d{2})(?:\s*[-/]\s*(20\d{2}))?\b')


def texte_periode(libelle):
    """La période telle que le libellé la NOMME, quand elle n'est pas deux dates.

       ⚠️ UNE RÉGULARISATION ANNUELLE A UNE PÉRIODE, MÊME SANS DATES. « Régularisation pour
          charges 2024-2025 » couvre deux exercices : la déclarer « sans période » serait
          perdre une information que le document imprime."""
    m = RE_ANNEE.search(libelle or '')
    if not m:
        return None
    return m.group(1) + ('/' + m.group(2) if m.group(2) else '')


def pleine_periode(d, f):
    """La période couvre-t-elle un mois calendaire entier ? Sinon, c'est un prorata."""
    if not d or not f:
        return None
    return d[8:10] == '01' and P4B.tombe_en_fin_de_mois(f) and d[:7] == f[:7]


def nature_septeo(libelle, nature_lue):
    """La nature que le LIBELLÉ nomme — c'est lui qui parle, pas la colonne."""
    if RE_REGUL.search(libelle or ''):
        return 'régularisation'
    return {'loyer': 'loyer', 'charges': 'charges/provisions', 'teom': 'taxe',
            'taxe_fonciere': 'taxe', 'eau': 'charges/provisions'}.get(nature_lue, 'autre')


def _appels_brutes(agence):
    """Chaque APPEL MÉTIER du corpus, avec ses composantes et sa provenance complète."""
    for d in documents(agence):
        m = d.get('meta') or {}
        arrete = P4B.jour(m.get('date_arrete')) or P4B.jour(m.get('periode_fin'))
        base = {'agence': agence, 'format': d.get('_format'), 'crg_fichier': d.get('_nom_original'),
                'sha': d.get('_sha'), 'periode_crg': m.get('periode_cle'), 'date_arrete': arrete,
                'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                'compte': str(m.get('compte') or '')}
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            for rang, lo in enumerate(im.get('lots') or []):
                loc = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                # ⚠️ UN LOT PEUT PORTER DEUX BLOCS DE MÊME NOM DANS LE MÊME CRG. Page 7 du CRG
                #    2026-T1 de SARL GROUPE SIR, l'immeuble 01040076 imprime DEUX fois « lot 0001
                #    LA BOUCHERIE DU… » : l'un avec six lignes de période, l'autre avec un seul
                #    solde antérieur de 754,66 €. Une clé (immeuble, lot, locataire) les fusionne
                #    et compare les composantes du premier au total du second — c'était la cause
                #    de 6 des 9 « écarts inexpliqués » de GROUPE SIR. Le rang du bloc les sépare.
                commun = dict(base, immeuble=code, lot=str(lo.get('numero_lot') or ''),
                              locataire=loc, bloc=rang)

                if d.get('_format') == 'septeo_spi':
                    # ── SEPTEO : une ligne = une nature ; on regroupe par période appelée.
                    paquets = collections.OrderedDict()
                    for a in lo.get('appels') or []:
                        lib = str(a.get('libelle') or '')
                        deb, fin = P4B.bornes_libelle(lib)
                        # ⚠️ SEULES LES COLONNES APPELÉES FONT UN APPEL. Si aucune valeur ne
                        #    figure sous Loyers/Charges/Autres, la ligne ne facture rien :
                        #      un montant en colonne de droite  → un RÈGLEMENT (P5B)
                        #      un montant en « Reste dû » seul  → le RAPPEL D'UN IMPAYÉ ANTÉRIEUR
                        #    SEPTEO réénonce en effet chaque mois impayé du passé : le lot 000335
                        #    liste octobre 2025 → février 2026 sans rien facturer, puis appelle
                        #    mars et avril. Compter ces rappels ferait entrer tout l'arriéré —
                        #    un STOCK — dans les loyers appelés de la période.
                        colonnes = [a.get('loyers'), a.get('charges'), a.get('autres')]
                        if all(v is None for v in colonnes):
                            continue
                        montant = sum(v or 0 for v in colonnes)
                        cle = (deb, fin, texte_periode(lib) if not deb else None)
                        p = paquets.setdefault(cle, dict(commun, periode_appel_debut=deb,
                                                         periode_appel_fin=fin,
                                                         periode_appel_texte=cle[2],
                                                         composantes=[],
                                                         page=a.get('page'), niveau_source='lu'))
                        p['composantes'].append({'nature': nature_septeo(lib, a.get('nature')),
                                                 'montant': round(montant, 2),
                                                 'libelle_source': lib})
                    for p in paquets.values():
                        yield p
                else:
                    # ── LYON · EMERY : une ligne = une période, plusieurs colonnes nommées.
                    for r in lo.get('mois') or []:
                        comps = [{'nature': nat, 'montant': round(r.get(col) or 0, 2),
                                  'libelle_source': 'colonne « %s »' % col}
                                 for col, nat in COLONNES if col in r]
                        if not comps:
                            continue
                        deb, fin = P4B.jour(r.get('du')), P4B.jour(r.get('au'))
                        yield dict(commun, periode_appel_debut=deb, periode_appel_fin=fin,
                                   periode_appel_texte=None, composantes=comps,
                                   page=r.get('page'), niveau_source='lu')


def _doc_appel(a):
    return round(sum(float(c.get('montant') or 0) for c in a.get('composantes') or []), 2)


def _ev_appel(a):
    """L'événement métier d'un appel : quel lot, pour quelle période, de quel montant."""
    return (str(a.get('immeuble') or ''), str(a.get('lot') or ''), str(a.get('locataire') or ''),
            a.get('periode_appel_debut'), a.get('periode_appel_fin'),
            a.get('periode_appel_texte'), _doc_appel(a))


# ── RECTIFICATION CERTIFIÉE P5A / P5B / P6A — FOCH/SABY — 01/09/2026 ──────────────────────
# ⚠️ LA RÉÉDITION N'EST PAS UNE PROPRIÉTÉ DU PDF, C'EST UNE PROPRIÉTÉ DE L'ÉVÉNEMENT MÉTIER
#    QU'IL CONTIENT. Deux pièces couvrant la même situation de gestion réénoncent une partie
#    de leurs événements et se complètent pour le reste. Le lecteur les lit toutes — c'est
#    ici, et ici seulement, que le doublon est retiré : un événement réénoncé compte une fois,
#    un événement complémentaire reste compté, aucune pièce n'est éliminée.
def appels(agence):
    """Chaque APPEL MÉTIER du corpus, dédoublonné à l'événement."""
    brutes = list(_appels_brutes(agence))
    EV.divergences(brutes, _ev_appel, _doc_appel)
    retenues, _ = EV.dedupliquer(brutes, _ev_appel)
    return retenues


def appels_retirees(agence):
    """Les occurrences retirées comme réénoncées — conservées pour la preuve, jamais comptées."""
    return EV.dedupliquer(list(_appels_brutes(agence)), _ev_appel)[1]


def qualifier(a):
    """Les qualifications d'un appel — chacune lue, jamais supposée."""
    tot = round(sum(c['montant'] for c in a['composantes']), 2)
    deb, fin, arr = a['periode_appel_debut'], a['periode_appel_fin'], a['date_arrete']
    a['montant_total'] = tot
    a['periode_certaine'] = bool(deb and fin)
    a['prorata'] = pleine_periode(deb, fin) is False
    # ⚠️ APPEL FUTUR, JAMAIS IMPAYÉ. Un appel de mai dans un CRG arrêté au 30/04 est appelé
    #    en avance : le transformer en créance au 30/04 serait une invention (P6).
    a['futur'] = bool(deb and arr and deb > arr)
    # ⚠️ ET DÉBORDANT N'EST PAS FUTUR — arbitrage Emmanuel. Une période qui COMMENCE avant
    #    l'arrêté et FINIT après le franchit sans être future. On conserve la période et le
    #    montant ENTIERS tels qu'imprimés : proratiser soi-même la part d'après-arrêté serait
    #    fabriquer un chiffre que le document ne donne pas. Le partage, s'il faut le faire,
    #    appartiendra à P6 — et seulement si le CRG le fournit lui-même.
    a['debordant'] = bool(deb and fin and arr and deb <= arr < fin)
    a['avoir'] = tot < 0
    a['zero'] = tot == 0 and a['periode_certaine']
    a['a_qualifier'] = any(c['nature'] == 'à qualifier' and c['montant'] for c in a['composantes'])
    return a


CHAMPS = ('crg_fichier', 'sha', 'page', 'periode_crg', 'date_arrete', 'proprietaire', 'compte',
          'immeuble', 'lot', 'locataire', 'niveau_source')


def par_nature(lst):
    n = collections.Counter()
    for a in lst:
        for c in a['composantes']:
            n[c['nature']] += c['montant']
    return n


def certifier(agences):
    ok = True
    tout, dump = {}, []

    entete('P5A — UNITÉS : OCCURRENCE ≠ APPEL MÉTIER ≠ COMPOSANTE')
    print('  %-16s %-13s %12s %10s %12s %11s %11s' %
          ('PÉRIMÈTRE', 'format', 'occurrences', 'appels', 'composantes', 'provenance',
           'période lue'))
    for ag in agences:
        lst = tout[ag] = [qualifier(a) for a in appels(ag)]
        fm = collections.Counter(a['format'] for a in lst).most_common(1)[0][0] if lst else '-'
        comps = sum(len(a['composantes']) for a in lst)
        # une occurrence documentaire = une ligne imprimée
        occ = comps if fm == 'septeo_spi' else len(lst)
        # ⚠️ LA PROVENANCE DOIT ÊTRE PARFAITE — c'est elle qui rend le chiffre vérifiable.
        complet = sum(1 for a in lst if all(a.get(c) not in (None, '') for c in CHAMPS)
                      and isinstance(a['page'], int))
        # ⚠️ LA PÉRIODE, ELLE, EST UNE MESURE, PAS UN DÛ. Une régularisation dont le document
        #    n'imprime aucune période est un fait à consigner, pas un défaut de lecture.
        datee = sum(1 for a in lst if a['periode_appel_debut'] or a['periode_appel_texte'])
        print('  %-16s %-13s %12d %10d %12d %11d %11d%s' %
              (ag, fm, occ, len(lst), comps, complet, datee,
               '   ⚠' if complet != len(lst) else ''))
        if complet != len(lst):
            ok = False
            manque = collections.Counter(c for a in lst for c in CHAMPS if a.get(c) in (None, ''))
            print('                 ⚠ %d provenances incomplètes : %s'
                  % (len(lst) - complet, dict(manque)))
        if datee != len(lst):
            print('                 · %d appel(s) sans période imprimée (régularisations)'
                  % (len(lst) - datee))
        dump += lst

    entete('P5A — MONTANTS APPELÉS PAR NATURE (la nature vient du document, jamais de la colonne seule)')
    print('  %-16s %14s %14s %20s %16s %14s' %
          ('PÉRIMÈTRE', 'LOYER', 'TAXES', 'CHARGES/PROVISIONS', 'RÉGULARISATION', 'À QUALIFIER'))
    for ag in agences:
        n = par_nature(tout[ag])
        print('  %-16s %14s %14s %20s %16s %14s' %
              (ag, euros(n['loyer']), euros(n['taxe']), euros(n['charges/provisions']),
               euros(n['régularisation']), euros(n['à qualifier'])))
    n = par_nature([a for ag in agences for a in tout[ag]])
    print('  %-16s %14s %14s %20s %16s %14s' %
          ('TOTAL', euros(n['loyer']), euros(n['taxe']), euros(n['charges/provisions']),
           euros(n['régularisation']), euros(n['à qualifier'])))
    print('  ⚠ « à qualifier » = colonne Divers de LYON/EMERY : le montant est lu, sa NATURE')
    print('    MÉTIER ne l’est pas — le lecteur ne conserve pas le libellé de ces lignes.')

    # ── LA VENTILATION DES « DIVERS », UNE FOIS LEUR LIBELLÉ RÉCUPÉRÉ AU PDF ───────────────
    # ⚠️ ET LE RÉSULTAT DÉPLACE LE CHIFFRE PRINCIPAL. Sur les 462 491,38 € que la colonne
    #    « Divers » laissait « à qualifier », 140 034,30 € sont un SOLDE ANTÉRIEUR — un stock
    #    lu comme un flux — et 283 860,50 € des DÉPÔTS DE GARANTIE, mouvements propriétaire.
    #    Les laisser dans « appelé au locataire » aurait répété, à petite échelle, l'erreur que
    #    P5A vient de démontrer sur l'ancien compteur `due`.
    if os.path.exists(QUALIFIE):
        q = json.load(io.open(QUALIFIE, encoding='utf-8'))
        entete('P5A — LES « DIVERS » UNE FOIS LEUR LIBELLÉ RÉCUPÉRÉ AU PDF')
        par = collections.defaultdict(float)
        nb = collections.Counter()
        for x in q:
            par[x['nature_demontree']] += x['montant']
            nb[x['nature_demontree']] += 1
        print('  %-46s %16s %10s' % ('NATURE DÉMONTRÉE PAR LE LIBELLÉ', 'montant', 'lignes'))
        for n, v in sorted(par.items(), key=lambda kv: -abs(kv[1])):
            print('  %-46s %16s %10d' % (n, euros(v), nb[n]))
        # ⚠️ ON NE RETRANCHE QUE LES EXCLUSIONS ENCORE VIVANTES. Depuis la rectification
        #    FOCH/SABY, une ligne « Divers » qualifiée peut appartenir à une occurrence
        #    réénoncée, donc retirée : la retrancher quand même appliquerait un dépôt de
        #    garantie à un appel qui n'existe plus, et surestimait le flux de 675,00 €.
        #    Import tardif — `p5a_numerateur` importe ce module.
        import p5a_numerateur as NUM
        vivants = [a for ag in agences for a in appels(ag)]
        brut = round(sum(NUM.montant_documentaire(a) for a in vivants), 2)
        flux = round(sum(v for _, v in NUM.flux_appeles(vivants)), 2)
        entete('P5A — DU MONTANT DOCUMENTAIRE AU FLUX RÉELLEMENT APPELÉ AU LOCATAIRE')
        print('  %-52s %18s' % ('total documentaire des composantes', euros(brut)))
        print('  %-52s %18s' % ('− report / solde antérieur (STOCK, P6)',
                                euros(-par['report / solde antérieur — STOCK'])))
        print('  %-52s %18s' % ('− dépôt de garantie, exclusions vivantes',
                                euros(-(brut - flux - par['report / solde antérieur — STOCK']))))
        print('  %-52s %18s' % ('= APPELÉ AU LOCATAIRE — flux de la période', euros(flux)))
        print('  ⚠ les deux retraits sont MONTRÉS, jamais silencieux : le total documentaire')
        print('    reste lisible, et chaque ligne retirée porte son libellé source.')
        print('  ⚠ LE DÉPÔT DE GARANTIE VAUT 269 540,50 ET NON 268 865,50 : la ventilation')
        print('    ci-dessus liste les lignes TELLES QUE QUALIFIÉES, dont une appartient à une')
        print('    occurrence réénoncée que la rectification FOCH/SABY a retirée. Compter son')
        print('    exclusion sur un appel disparu surestimerait le flux de 675,00 €.')

    entete('P5A — FORMES PARTICULIÈRES')
    print('  %-16s %9s %8s %8s %11s %9s %14s' %
          ('PÉRIMÈTRE', 'proratas', 'à zéro', 'futurs', 'débordants', 'avoirs', 'mt avoirs'))
    for ag in agences:
        lst = tout[ag]
        av = [a for a in lst if a['avoir']]
        print('  %-16s %9d %8d %8d %11d %9d %14s' %
              (ag, sum(1 for a in lst if a['prorata']), sum(1 for a in lst if a['zero']),
               sum(1 for a in lst if a['futur']), sum(1 for a in lst if a['debordant']),
               len(av), euros(sum(a['montant_total'] for a in av))))
    print('  ⚠ FUTUR = la période COMMENCE après l’arrêté · DÉBORDANT = elle le FRANCHIT.')
    print('    Ni l’un ni l’autre n’est un impayé, et aucun montant débordant n’est proratisé.')
    deb = [a for ag in agences for a in tout[ag] if a['debordant']]
    for a in deb:
        print('     %-14s %-11s lot %-7s %-20s %s → %s (arrêté %s) %12s  p.%s'
              % (a['agence'], a['periode_crg'], a['lot'], a['locataire'][:20],
                 a['periode_appel_debut'], a['periode_appel_fin'], a['date_arrete'],
                 euros(a['montant_total']), a['page']))

    entete('P5A — CONTRÔLE : SOMME DES COMPOSANTES ↔ « Total » IMPRIMÉ DU LOT')
    # ⚠️ LE « Total » DU LOT N'EST PAS UN TOTAL D'APPELS. Le contrôle le démontre : là où la
    #    somme des composantes ne le rejoint pas, l'écart est EXACTEMENT le solde antérieur.
    #    Le document additionne donc un FLUX (ce qui a été appelé) et un STOCK (ce qui restait
    #    dû avant). Reprendre `total_du` comme « appelé » importerait l'arriéré dans le flux.
    #    Le calcul ne corrige jamais le document : il le qualifie.
    print('  %-16s %8s %11s %26s %12s %14s' %
          ('PÉRIMÈTRE', 'lots', 'égaux', 'écart = solde antérieur', 'inexpliqués', 'montant inexp.'))
    reste = []
    for ag in agences:
        parLot = collections.defaultdict(float)
        for a in tout[ag]:
            parLot[(a['sha'], a['immeuble'], a['lot'], a['bloc'])] += a['montant_total']
        c = collections.Counter()
        inexp = []
        for d in documents(ag):
            for im in d.get('immeubles') or []:
                cd = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
                for rang, lo in enumerate(im.get('lots') or []):
                    # ⚠️ LE RANG SEUL NE SUFFIT PAS : deux immeubles DISTINCTS partagent le
                    #    code « 5143 » dans un même CRG VIENNE, chacun avec un lot au rang 0.
                    #    Le numéro de lot les sépare ; le rang sépare les blocs d'un même lot.
                    k = (d.get('_sha'), cd, str(lo.get('numero_lot') or ''), rang)
                    if lo.get('total_du') is None or k not in parLot:
                        continue
                    som, tot = parLot[k], float(lo['total_du'])
                    sa = float(lo.get('solde_anterieur') or 0)
                    if abs(som - tot) < 0.01:
                        c['égaux'] += 1
                    elif abs(som + sa - tot) < 0.01:
                        c['solde'] += 1
                    else:
                        c['inexp'] += 1
                        inexp.append((str(lo.get('numero_lot') or ''),
                                      str(lo.get('locataire') or lo.get('locataire_nom') or ''),
                                      round(som + sa - tot, 2), d.get('_nom_original'),
                                      lo.get('page')))
        print('  %-16s %8d %11d %26d %12d %14s' %
              (ag, sum(c.values()), c['égaux'], c['solde'], c['inexp'],
               euros(sum(abs(x[2]) for x in inexp))))
        reste += [(ag,) + x for x in inexp[:3]]
    for e in reste[:8]:
        print('     %-14s lot %-7s %-20s %s  %s p.%s'
              % (e[0], e[1], e[2][:20], euros(e[3]).rjust(11), str(e[4])[:26], e[5]))
    print('  ⚠ un écart inexpliqué est une ANOMALIE À INSTRUIRE, jamais une correction silencieuse.')

    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps(dump, ensure_ascii=False))
    print('\n  %d appels exportés → %s' % (len(dump), DUMP))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP5A — APPELS : %s' % ('OUI — unités, natures, périodes et provenance démontrées'
                                   if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
