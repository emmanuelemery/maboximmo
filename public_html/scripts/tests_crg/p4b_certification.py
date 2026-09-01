# -*- coding: utf-8 -*-
"""
P4B — OCCUPATION : QUI OCCUPAIT CE LOT, ET QUAND.

⚠️ LA RÈGLE MÉTIER QUI FAIT BASCULER UN LOCATAIRE DANS L'HISTORIQUE — arbitrage Emmanuel :

      MÊME LOT + CHANGEMENT CHRONOLOGIQUE DU TITULAIRE DES APPELS DE LOYERS
                        = SUCCESSION LOCATIVE DÉMONTRÉE

   Quatre conditions, toutes exigées : ① le même lot ② A est bien titulaire d'appels de loyer
   ③ B l'est aussi ④ les appels de B commencent APRÈS la fin des appels de A. Si A n'a jamais
   été appelé dans le corpus, on ne peut pas démontrer qu'il a cessé de l'être : INDÉTERMINABLE.

⚠️ ARCHIVER N'EST PAS SUPPRIMER. B ne remplace jamais A : les deux relations coexistent sur le
   lot, A en occupation archivée, B en occupation active. Un ancien locataire qui traîne encore
   un impayé reste rattaché au bien — c'est même la seule raison pour laquelle sa dette pourra
   plus tard être réclamée à la bonne personne.

⚠️ ET LE DÉBIT NE DÉTERMINE JAMAIS L'OCCUPATION, DANS AUCUN SENS. Un débiteur dont le départ
   est démontré reste PARTI ; un solde à zéro ne prouve aucune sortie. CRÉANCE ≠ OCCUPATION.
   Aucun montant n'entre dans une qualification : les appels servent ici de PREUVE TEMPORELLE
   (« un loyer a été appelé à ce nom pour cette période »), jamais de valeur financière.

⚠️ ET « statut » IMPRIMÉ PAR LES LECTEURS N'EST PAS CETTE QUALIFICATION. Les trois lecteurs
   posent `lot['statut'] = occupe si loyer_appele > 0 sinon parti` — une heuristique de montant,
   exactement ce que la règle métier interdit. Elle n'est ni lue ni certifiée ici.

⚠️ INDÉTERMINABLE EST UN RÉSULTAT CORRECT. L'objectif n'est pas d'atteindre 100 % d'ACTIF ou
   d'ANCIEN : c'est de ne jamais affirmer ce que le document ne dit pas.

⚠️ ET QUAND PLUS PERSONNE N'EST APPELÉ, C'EST LA FORME DE LA DERNIÈRE PÉRIODE QUI TRANCHE —
   arbitrage Emmanuel, resserré le 30/08/2026. Aucun seuil en mois n'est posé : « 1 mois = parti »
   comme « 2 mois = parti » serait une invention. Ce qui parle, c'est le document lui-même :

     appel arrêté EN COURS DE MOIS   + poursuite démontrée du suivi du lot après cette date
                                     = CESSATION D'OCCUPATION DÉMONTRÉE selon la règle métier.
                                     ⚠️ C'est une DÉDUCTION MÉTIER, pas une lecture : le CRG
                                     n'imprime aucun décompte de départ. On constate seulement
                                     que la facturation s'arrête en cours de mois et que le
                                     document continue de suivre le lot.
     appel arrêté SUR UN MOIS PLEIN  appel mensuel ordinaire. Il faut PLUS D'UN MOIS de recul du
                                     document, sinon l'appel suivant peut n'être pas encore émis :
                                     INDÉTERMINABLE.

   7 occupations basculent ainsi de ARCHIVÉE à INDÉTERMINABLE. On ne perd rien — le prochain CRG
   leur donnera le recul qui manque. Dans le doute : INDÉTERMINABLE.

⚠️ ET CHAQUE FORMAT A SES PREUVES. Une règle vraie chez SEPTEO ne l'est pas chez LYON :

     septeo_spi   date de début de bail (110/111) · date de FIN de bail (6/111) ·
                  période de loyer dans le libellé de l'appel
     lyon         AUCUNE date de bail imprimée. Seules bornes : `du`/`au` des lignes mensuelles
     emery_immo   AUCUNE date de bail imprimée. Idem

   (vérifié : les PDF LYON/EMERY ne portent ni « départ », ni « fin de bail », ni « congé ».)

⚠️ ET UN STATUT SANS DATE NE VEUT RIEN DIRE. Deux niveaux, jamais confondus :
     SITUATION   lot × locataire × PÉRIODE — statut à la date d'arrêté de CE CRG
     OCCUPATION  lot × locataire — l'historique consolidé, statut à la dernière clôture du lot

Lecture seule. Aucune écriture MBI. Aucune finance certifiée ici.
"""
import sys
import os
import io
import re
import json
import datetime
import collections
import unicodedata

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents, perimetre, entete   # noqa: E402

sys.stdout.reconfigure(encoding='utf-8')

DUMP = r'C:\tmp\p4b_occupations.json'
VALIDATIONS = r'C:\tmp\p4b_validations.json'

MOIS = {'janvier': 1, 'fevrier': 2, 'mars': 3, 'avril': 4, 'mai': 5, 'juin': 6, 'juillet': 7,
        'aout': 8, 'septembre': 9, 'octobre': 10, 'novembre': 11, 'decembre': 12}
_JOURS = (31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31)


def plat(s):
    s = unicodedata.normalize('NFD', str(s or ''))
    s = ''.join(c for c in s if unicodedata.category(c) != 'Mn')
    return ' '.join(re.sub(r'[^0-9A-Za-z ]+', ' ', s).split()).upper()


def colle(s):
    """L'identité au sens P4A : normalisée pour RAPPROCHER, jamais pour réécrire."""
    return plat(s).replace(' ', '')


def jour(v):
    """Une date imprimée, sous l'une des formes rencontrées, en AAAA-MM-JJ."""
    s = str(v or '').strip()
    for p, o in ((r'^(\d{2})/(\d{2})/(\d{4})$', (3, 2, 1)),
                 (r'^(\d{4})-(\d{2})-(\d{2})', (1, 2, 3)),
                 (r'^(\d{2})\.(\d{2})\.(\d{2})$', None)):
        m = re.match(p, s)
        if not m:
            continue
        if o is None:
            return '20%s-%s-%s' % (m.group(3), m.group(2), m.group(1))
        return '%s-%s-%s' % (m.group(o[0]), m.group(o[1]), m.group(o[2]))
    return None


def decale(d, n):
    # ⚠️ UNE DATE QUI NE SE DÉCALE PAS FAUSSERAIT SILENCIEUSEMENT LA SOUSTRACTION D'INTERVALLES.
    #    On n'attrape donc que ce qu'une DONNÉE malformée peut provoquer, et on le rend visible
    #    en laissant la date intacte — le contrôle de couverture s'en apercevra.
    try:
        return str(datetime.date(*map(int, d.split('-'))) + datetime.timedelta(days=n))
    except (ValueError, TypeError, AttributeError):
        return d


def soustraire(pos, neg):
    """Les périodes réellement appelées : les intervalles facturés, PRIVÉS des avoirs.

       ⚠️ UN AVOIR N'EST PAS TOUJOURS UNE SORTIE. Le même document annule le début d'un mois
          quand le locataire ENTRE le 7, et la fin d'un mois quand il PART le 12. Traiter tout
          avoir comme une fin de période faisait d'un entrant un partant — et inventait une
          succession à l'envers. Seule la soustraction d'intervalles lit ce que le document dit."""
    out = []
    for d, f in pos:
        morceaux = [(d, f)]
        for nd, nf in neg:
            suite = []
            for a, b in morceaux:
                if nf < a or nd > b:
                    suite.append((a, b))
                    continue
                if nd > a:
                    suite.append((a, decale(nd, -1)))
                if nf < b:
                    suite.append((decale(nf, 1), b))
            morceaux = suite
        out += morceaux
    return [(a, b) for a, b in out if a <= b]


def fin_mois(a, num):
    d = 29 if (num == 2 and a % 4 == 0 and (a % 100 or a % 400 == 0)) else _JOURS[num - 1]
    return '%04d-%02d-%02d' % (a, num, d)


def tombe_en_fin_de_mois(d):
    """Le dernier appel s'arrête-t-il sur un mois PLEIN, ou en cours de mois ?

       ⚠️ C'EST LA FORME DE LA DERNIÈRE PÉRIODE QUI PORTE LE SIGNAL, PAS SA DISTANCE À LA
          CLÔTURE. Un loyer appelé « du 1er au 19 » est un DÉCOMPTE DE DÉPART : le document
          arrête la facturation en cours de mois, ce qu'il ne fait pas pour un occupant qui
          reste. Un loyer appelé jusqu'au 31 est un appel mensuel ordinaire — le suivant peut
          simplement ne pas être encore émis."""
    return decale(d, 1)[5:7] != d[5:7]


def mois_de_recul(fin, cloture):
    """Combien de mois calendaires le document continue de courir après le dernier appel.

       ⚠️ EN MOIS CALENDAIRES, JAMAIS EN JOURS. « 31 jours » aurait été un seuil arbitraire ;
          la question réelle est « le CRG couvre-t-il un mois de plus, ou davantage ? »."""
    if not fin or not cloture:
        return 0
    return (int(cloture[:4]) - int(fin[:4])) * 12 + (int(cloture[5:7]) - int(fin[5:7]))


def bornes_libelle(lib):
    """Les bornes d'un appel SEPTEO, lues dans son libellé.

       ⚠️ « Solde du dernier Rapport au 31/3/2026 » N'EST PAS UN APPEL DE LOYER : c'est le
          report du relevé précédent. Le retenir ferait d'une ligne comptable une preuve
          d'occupation."""
    if re.match(r'^\s*(solde|report)', lib, re.I):
        return None, None
    m = re.search(r'\bdu\s+(\d{1,2})/(\d{1,2})/(\d{4})\s+au\s+(\d{1,2})/(\d{1,2})/(\d{4})', lib, re.I)
    if m:
        return ('%04d-%02d-%02d' % (int(m.group(3)), int(m.group(2)), int(m.group(1))),
                '%04d-%02d-%02d' % (int(m.group(6)), int(m.group(5)), int(m.group(4))))
    p = plat(lib).lower()
    for nom, num in MOIS.items():
        mm = re.search(r'\b' + nom + r'\s+(\d{4})\b', p)
        if mm:
            a = int(mm.group(1))
            return '%04d-%02d-01' % (a, num), fin_mois(a, num)
    m = re.search(r'\bau\s+(\d{1,2})/(\d{1,2})/(\d{4})', lib, re.I)
    if m:
        j = '%04d-%02d-%02d' % (int(m.group(3)), int(m.group(2)), int(m.group(1)))
        return j, j
    return None, None


def couverture(lo):
    """La période RÉELLEMENT APPELÉE au nom de ce locataire sur ce lot : (début, fin).

       ⚠️ LE MONTANT DU LOYER NE DÉCIDE PAS — arbitrage Emmanuel. Un locataire peut avoir
          plusieurs mois de GRATUITÉ à son entrée : loyer à 0 €, charges et taxes appelées
          quand même. Exiger `loyer > 0` le rendait invisible, donc « parti ». Ce qui fait
          preuve, c'est qu'une période soit APPELÉE à son nom — loyer, taxe ou provision.
          Mesuré : 8 lignes de loyer à 0 € avec charges appelées (KAYREVAN, janvier et
          février 2026 : loyer 0 €, taxes 380 €, provisions 650 €).

       ⚠️ MAIS « DIVERS » RESTE DEHORS. C'est la colonne des refacturations, régularisations,
          dépôts de garantie et factures locatives : elle ne dit rien de l'occupation, et une
          facture de réparation ne prouve pas qu'on habite encore le logement.

       ⚠️ ET UN AVOIR RETRANCHE EXACTEMENT SA PROPRE PÉRIODE. Le mois entier appelé puis
          crédité du 13 au 31 laisse une couverture au 12 ; celui crédité du 1er au 6 laisse
          une couverture qui commence le 7. C'est le document qui le dit, pas nous."""
    pos, neg = [], []
    for m in lo.get('mois') or []:            # lyon · emery_immo — les colonnes SONT le libellé
        if not any(k in m for k in ('loyers', 'taxes', 'provisions')):
            continue
        v = (m.get('loyers') or 0) + (m.get('taxes') or 0) + (m.get('provisions') or 0)
        d, a = jour(m.get('du')), jour(m.get('au'))
        if not d or not a or not v:
            continue
        (pos if v > 0 else neg).append((d, a))
    for ap in lo.get('appels') or []:         # septeo_spi — la période est dans le libellé
        v = (ap.get('loyers') or 0) + (ap.get('charges') or 0) + (ap.get('autres') or 0)
        if not v:
            v = (ap.get('credit') or 0) - (ap.get('debit') or 0)
        d, a = bornes_libelle(str(ap.get('libelle') or ''))
        if not d or not a or not v:
            continue
        (pos if v > 0 else neg).append((d, a))
    reste = soustraire(pos, neg)
    if not reste:
        return None, None
    return min(a for a, _ in reste), max(b for _, b in reste)


def impaye(lo):
    """Ce que le document imprime encore comme dû sur ce bloc. CONSTAT, PAS CERTIFICATION :
       ni la nature, ni l'origine, ni l'antériorité de cette somme ne relèvent de P4."""
    for k in ('total_impaye',):
        v = lo.get(k)
        if isinstance(v, (int, float)) and v > 0:
            return float(v)
    return 0.0


# ── Lecture du corpus ──────────────────────────────────────────────────────────────────────
def blocs(agence):
    """Tout bloc de lot lu dans un CRG, avec sa provenance complète.

       ⚠️ UN BLOC N'EST PAS UN LOT. Un lot qui a changé de main dans le trimestre imprime
          plusieurs blocs, un par titulaire : c'est précisément là que se lit la succession."""
    for d in documents(agence):
        m = d.get('meta') or {}
        cloture = jour(m.get('date_arrete')) or jour(m.get('periode_fin'))
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip()
            for lo in im.get('lots') or []:
                lib = str(lo.get('locataire') or lo.get('locataire_nom') or '').strip()
                cd, cf = couverture(lo)
                yield {
                    'agence': agence, 'format': d.get('_format'), 'sha': d.get('_sha'),
                    'pdf': d.get('_nom_original'), 'page': lo.get('page'),
                    'periode': m.get('periode_cle'), 'cloture': cloture,
                    'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                    'compte': str(m.get('compte') or ''),
                    'immeuble': code or ('NOM:' + plat(im.get('nom') or im.get('adresse'))),
                    'adresse': str(im.get('adresse') or im.get('nom') or ''),
                    'lot': str(lo.get('numero_lot') or ''),
                    'libelle': lib, 'identite': colle(lib),
                    'bail_debut': jour(lo.get('bail_debut')), 'bail_fin': jour(lo.get('bail_fin')),
                    'cov_debut': cd, 'cov_fin': cf, 'impaye': impaye(lo),
                }


def cle_lot(b):
    return (b['agence'], b['compte'], b['immeuble'], b['lot'])


# ── Les deux qualifications ────────────────────────────────────────────────────────────────
def qualifier(bail_fin, cov_debut, cov_fin, cloture, voisins, suivi_apres=None):
    """(statut, classe, preuve). `voisins` = (identité, cov_debut, cov_fin, bail_fin) des AUTRES
       titulaires du même lot dans le périmètre temporel considéré. `suivi_apres` = la dernière
       clôture d'un CRG qui suit encore ce lot APRÈS le dernier appel de ce locataire."""
    # ① LA DATE DE FIN DE BAIL IMPRIMÉE — la seule preuve directe de sortie du corpus.
    if bail_fin:
        if cloture and bail_fin < cloture:
            return 'ancien', 'A — preuve directe', 'fin de bail imprimée au ' + bail_fin
        return 'actif', 'actif', 'bail courant jusqu au ' + bail_fin

    # ② UN LOYER APPELÉ À CE NOM QUI COUVRE LA CLÔTURE — occupation démontrée à cette date.
    if cov_fin and cloture and cov_fin >= cloture:
        return 'actif', 'actif', 'loyer appelé à ce nom jusqu au ' + cov_fin

    # ③ LA SUCCESSION — règle métier Emmanuel, quatre conditions réunies.
    #    ⚠️ A DOIT AVOIR ÉTÉ TITULAIRE DES APPELS. Sans cela, il n'y a pas de « changement de
    #       titulaire » à démontrer : seulement un nom qui figure au document, souvent parce
    #       qu'il traîne une dette. Ce cas est INDÉTERMINABLE, jamais une sortie.
    #    ⚠️ ET LE JOUR DE PASSATION APPARTIENT AUX DEUX. Le document facture A « jusqu'au 19/02 »
    #       et B « à partir du 19/02 » : exiger que B commence STRICTEMENT après aurait rejeté
    #       les relèves les plus nettes du corpus. `>=` accepte la passation, jamais un
    #       chevauchement — deux cotitulaires facturés du même trimestre ne le franchissent pas.
    if cov_fin:
        for ident, vd, vf, vbf in voisins:
            if vd and vd >= cov_fin:
                return ('ancien', 'B — succession démontrée',
                        'appels repris par « %s » à partir du %s' % (ident, vd))

    # ④ LA CESSATION DES APPELS — arbitrage Emmanuel du 30/08/2026, resserré le même jour.
    #    Les appels à ce nom s'arrêtent et le document continue de suivre le lot sans jamais
    #    le rappeler. Ce n'est pas la disparition d'un nom : c'est la régie qui rend encore
    #    compte du lot et n'y appelle plus personne sous ce nom.
    #
    #    ⚠️ AUCUN SEUIL EN MOIS. Emmanuel a refusé « 1 mois = parti » comme « 2 mois = parti » :
    #       un seuil en durée serait une invention. C'est la FORME de la dernière période
    #       appelée qui tranche, et le recul du document qui la confirme :
    #
    #         appel arrêté EN COURS DE MOIS  + poursuite démontrée du suivi du lot après cette
    #                                          date = CESSATION D'OCCUPATION DÉMONTRÉE selon la
    #                                          règle métier. ⚠️ DÉDUCTION MÉTIER, PAS LECTURE :
    #                                          le CRG n'imprime aucun décompte de départ.
    #         appel arrêté SUR UN MOIS PLEIN → appel mensuel ordinaire. Il faut que le document
    #                                          coure PLUS D'UN MOIS au-delà, sinon l'appel
    #                                          suivant peut n'être pas encore émis :
    #                                          INDÉTERMINABLE.
    #
    #       7 occupations basculent ainsi de ARCHIVÉE à INDÉTERMINABLE. On ne perd rien : le
    #       prochain CRG leur donnera le recul qui manque. Dans le doute, INDÉTERMINABLE.
    if cov_fin and suivi_apres:
        if not tombe_en_fin_de_mois(cov_fin):
            return ('ancien', 'C — cessation démontrée',
                    'appels arrêtés en cours de mois au %s, lot encore suivi au %s — cessation '
                    'démontrée selon la règle métier'
                    % (cov_fin, suivi_apres))
        if mois_de_recul(cov_fin, suivi_apres) > 1:
            return ('ancien', 'C — cessation démontrée',
                    'appels arrêtés au %s (mois plein) ; lot encore suivi %d mois après, '
                    'au %s' % (cov_fin, mois_de_recul(cov_fin, suivi_apres), suivi_apres))
        return ('indeterminable', 'INDÉTERMINABLE',
                'appels arrêtés au %s sur un mois plein, un seul mois de recul (%s) — '
                'l appel suivant peut ne pas être émis' % (cov_fin, suivi_apres))

    # ⑤ ET C'EST TOUT. Un appel qui s'arrête sans que le lot soit revu ne prouve RIEN :
    #    un locataire quittancé au trimestre dont l'appel suivant n'est pas encore émis serait
    #    déclaré parti à tort.
    if not cov_fin and not cov_debut:
        return 'indeterminable', 'INDÉTERMINABLE', 'aucun appel ni date de bail imprimés'
    return ('indeterminable', 'INDÉTERMINABLE',
            'appels jusqu au %s, aucun repreneur ni CRG postérieur — absence, pas preuve'
            % cov_fin)


def analyser(agences):
    tout = {}
    for ag in agences:
        bs = list(blocs(ag))
        parLot = collections.defaultdict(list)
        for b in bs:
            parLot[cle_lot(b)].append(b)

        # ⚠️ LA CESSATION SE JUGE SUR TOUTE LA VIE DU LOCATAIRE, PAS SUR UN SEUL CRG. Le dernier
        #    appel connu à ce nom est le MAXIMUM sur l'ensemble du corpus : sans cela, un
        #    locataire appelé au T1 puis de nouveau au T3 passerait pour parti au T1.
        finId, clotures = {}, collections.defaultdict(set)
        for b in bs:
            if b['cloture']:
                clotures[cle_lot(b)].add(b['cloture'])
            if b['identite'] and b['cov_fin']:
                k = (cle_lot(b), b['identite'])
                finId[k] = max(finId.get(k, ''), b['cov_fin'])

        def suivi_apres(b):
            """La dernière clôture d'un CRG qui suit encore ce lot APRÈS le dernier appel connu
               à ce nom — ou None si le lot n'a jamais été revu depuis."""
            f = finId.get((cle_lot(b), b['identite']))
            if not f:
                return None
            plus = [c for c in clotures[cle_lot(b)] if c > f]
            return max(plus) if plus else None

        # ── NIVEAU 1 · SITUATION : lot × locataire × PÉRIODE, jugée à la clôture de CE CRG.
        for b in bs:
            voisins = [(x['identite'], x['cov_debut'], x['cov_fin'], x['bail_fin'])
                       for x in parLot[cle_lot(b)]
                       if x['sha'] == b['sha'] and x['identite'] and x['identite'] != b['identite']]
            b['statut'], b['classe'], b['preuve'] = qualifier(
                b['bail_fin'], b['cov_debut'], b['cov_fin'], b['cloture'], voisins, suivi_apres(b))
            if not b['identite']:
                b['statut'], b['classe'] = 'indeterminable', 'INDÉTERMINABLE'
                b['preuve'] = 'aucun locataire imprimé sur ce lot'

        # ── NIVEAU 2 · OCCUPATION : lot × locataire, consolidée sur tout le corpus.
        occ = {}
        for b in bs:
            if not b['identite']:
                continue
            k = cle_lot(b) + (b['identite'],)
            o = occ.setdefault(k, {'agence': ag, 'compte': b['compte'], 'immeuble': b['immeuble'],
                                   'adresse': b['adresse'], 'lot': b['lot'],
                                   'identite': b['identite'], 'libelle': b['libelle'],
                                   'proprietaire': b['proprietaire'], 'periodes': set(),
                                   'sources': [], 'bail_debut': None, 'bail_fin': None,
                                   'cov_debut': None, 'cov_fin': None, 'impaye': 0.0})
            o['periodes'].add(b['periode'])
            o['sources'].append({'pdf': b['pdf'], 'page': b['page'], 'periode': b['periode'],
                                 'sha': b['sha'], 'cloture': b['cloture']})
            for c, cmp_ in (('bail_debut', min), ('cov_debut', min), ('bail_fin', max),
                            ('cov_fin', max)):
                if b[c]:
                    o[c] = b[c] if o[c] is None else cmp_(o[c], b[c])
            o['impaye'] = max(o['impaye'], b['impaye'])

        # la dernière clôture CONNUE du lot : la date à laquelle on juge l'historique
        dernier = {}
        for b in bs:
            k = cle_lot(b)
            if b['cloture']:
                dernier[k] = max(dernier.get(k, ''), b['cloture'])
        parLotOcc = collections.defaultdict(list)
        for k, o in occ.items():
            parLotOcc[k[:4]].append(o)
        for k, o in occ.items():
            voisins = [(x['identite'], x['cov_debut'], x['cov_fin'], x['bail_fin'])
                       for x in parLotOcc[k[:4]] if x['identite'] != o['identite']]
            o['cloture_ref'] = dernier.get(k[:4])
            plus = [c for c in clotures[k[:4]] if o['cov_fin'] and c > o['cov_fin']]
            o['suivi_apres'] = max(plus) if plus else None
            o['statut'], o['classe'], o['preuve'] = qualifier(
                o['bail_fin'], o['cov_debut'], o['cov_fin'], o['cloture_ref'], voisins,
                o['suivi_apres'])
        tout[ag] = {'blocs': bs, 'occupations': list(occ.values()), 'lots': parLotOcc}
    return tout


# ── Restitution ────────────────────────────────────────────────────────────────────────────
def certifier(agences):
    ok = True
    tout = analyser(agences)

    CL = ('actif', 'A — preuve directe', 'B — succession démontrée',
          'C — cessation démontrée', 'INDÉTERMINABLE')
    ENT = ('ACTIFS', 'ANC. « A »', 'ANC. « B »', 'ANC. « C »', 'INDÉT.')

    entete('P4B · NIVEAU 1 — SITUATIONS (lot × locataire × période), à la date d’arrêté du CRG')
    print('  %-16s %9s %8s %10s %10s %10s %9s %9s' %
          (('PÉRIMÈTRE', 'situat.') + ENT + ('sans date',)))
    for ag in agences:
        bs = tout[ag]['blocs']
        c = collections.Counter(b['classe'] for b in bs)
        sansDate = sum(1 for b in bs if not b['cloture'])
        print('  %-16s %9d %8d %10d %10d %10d %9d %9d%s' %
              ((ag, len(bs)) + tuple(c[x] for x in CL) + (sansDate, '   ⚠' if sansDate else '')))
        if sansDate:
            ok = False
    print('  ⚠ « A » fin de bail imprimée · « B » appels repris par un autre titulaire ·')
    print('    « C » appels arrêtés EN COURS DE MOIS, ou sur mois plein avec plus d’un mois de')
    print('    recul du document. Mois plein + un seul mois de recul → INDÉTERMINABLE.')

    entete('P4B · NIVEAU 2 — OCCUPATIONS (lot × locataire), à la dernière clôture connue du lot')
    print('  %-16s %9s %8s %10s %10s %10s %9s' % (('PÉRIMÈTRE', 'occupat.') + ENT))
    tot = collections.Counter()
    for ag in agences:
        os_ = tout[ag]['occupations']
        c = collections.Counter(o['classe'] for o in os_)
        tot.update(c)
        tot['occupations'] += len(os_)
        print('  %-16s %9d %8d %10d %10d %10d %9d' %
              ((ag, len(os_)) + tuple(c[x] for x in CL)))
    print('  %-16s %9d %8d %10d %10d %10d %9d' %
          (('TOTAL', tot['occupations']) + tuple(tot[x] for x in CL)))

    entete('P4B — L’ANCIEN LOCATAIRE N’EST JAMAIS ÉCRASÉ')
    for ag in agences:
        os_ = tout[ag]['occupations']
        arch = [o for o in os_ if o['statut'] == 'ancien']
        avecDette = [o for o in arch if o['impaye'] > 0]
        indetDette = [o for o in os_ if o['statut'] == 'indeterminable' and o['impaye'] > 0]
        # lots portant à la fois une occupation archivée et une occupation active : la
        # structure BIEN → { A archivée, B active } que la doctrine exige.
        coex = sum(1 for k, v in tout[ag]['lots'].items()
                   if any(o['statut'] == 'ancien' for o in v)
                   and any(o['statut'] == 'actif' for o in v))
        print('  %-16s archivées %4d · dont portant encore un impayé imprimé %4d · '
              'lots A archivée + B active %4d' % (ag, len(arch), len(avecDette), coex))
        print('  %-16s indéterminables portant encore un impayé imprimé : %d '
              '(présents au document, date de sortie inconnue)' % ('', len(indetDette)))

    entete('P4B — SUCCESSIONS ET MULTI-LOCATAIRES')
    for ag in agences:
        bs, os_ = tout[ag]['blocs'], tout[ag]['occupations']
        multiCRG = len({(b['sha'],) + cle_lot(b) for b in bs
                        if sum(1 for x in bs if x['sha'] == b['sha'] and cle_lot(x) == cle_lot(b)
                               and x['identite']) > 1})
        multiLot = sum(1 for k, v in tout[ag]['lots'].items() if len(v) > 1)
        succ = sum(1 for o in os_ if o['classe'] == 'B — succession démontrée')
        # un lot dont le titulaire des appels change d'une période à l'autre
        inter = 0
        for k, v in tout[ag]['lots'].items():
            paires = [(o['cov_debut'], o['cov_fin'], o['identite']) for o in v if o['cov_fin']]
            if len(paires) > 1 and len({p[2] for p in paires}) > 1:
                paires.sort()
                if any(paires[i][0] > paires[i - 1][1] for i in range(1, len(paires))):
                    inter += 1
        print('  %-16s lots à plusieurs titulaires dans UN MÊME CRG %4d · lots à plusieurs '
              'locataires sur le corpus %4d' % (ag, multiCRG, multiLot))
        print('  %-16s successions démontrées %4d · lots où le titulaire des appels change '
              'chronologiquement %4d' % ('', succ, inter))

    entete('P4B — LES PREUVES RÉELLEMENT DISPONIBLES, FORMAT PAR FORMAT')
    print('  %-16s %-14s %14s %16s %14s' %
          ('PÉRIMÈTRE', 'format', 'date de bail', 'période appelée', 'aucune'))
    for ag in agences:
        bs = tout[ag]['blocs']
        fm = collections.Counter(b['format'] for b in bs).most_common(1)[0][0]
        print('  %-16s %-14s %14d %16d %14d' %
              (ag, fm, sum(1 for b in bs if b['bail_fin'] or b['bail_debut']),
               sum(1 for b in bs if b['cov_fin']), sum(1 for b in bs if not b['cov_fin']
                   and not b['bail_fin'] and not b['bail_debut'])))

    # ── LA FILE DES DEMANDES DE VALIDATION ────────────────────────────────────────────────
    # ⚠️ CE QUI N'EST PAS DÉMONTRABLE NE SE DEVINE PAS : IL SE DEMANDE. Chaque indéterminable
    #    part vers l'écran de validation avec ce que le document dit VRAIMENT — dernier appel,
    #    impayé, voisins de lot, pages sources — pour qu'un humain tranche une fois, et que la
    #    réponse devienne une donnée, jamais une heuristique cachée dans le moteur.
    entete('P4B — DEMANDES DE VALIDATION (ce que le moteur refuse de trancher seul)')
    MOTIFS = {
        'jamais appelé, présent au document':
            lambda o: not o['cov_fin'],
        'mois plein + un seul mois de recul':
            lambda o: o['cov_fin'] and o['suivi_apres'] and tombe_en_fin_de_mois(o['cov_fin']),
        'appels cessés, lot jamais revu depuis':
            lambda o: o['cov_fin'] and not o['suivi_apres'],
    }
    file_ = []
    print('  %-16s %10s %16s %26s %18s' % ('PÉRIMÈTRE', 'à valider', 'jamais appelé',
          'mois plein + 1 mois', 'lot jamais revu'))
    for ag in agences:
        c = collections.Counter()
        for o in tout[ag]['occupations']:
            if o['statut'] != 'indeterminable':
                continue
            motif = next((m for m, f in MOTIFS.items() if f(o)), 'autre')
            c[motif] += 1
            file_.append({'agence': ag, 'motif': motif, 'compte': o['compte'],
                          'immeuble': o['immeuble'], 'adresse': o['adresse'], 'lot': o['lot'],
                          'proprietaire': o['proprietaire'], 'locataire': o['libelle'],
                          'dernier_appel': o['cov_fin'], 'premier_appel': o['cov_debut'],
                          'impaye_imprime': round(o['impaye'], 2),
                          'cloture_lot': o['cloture_ref'],
                          'periodes': sorted(x for x in o['periodes'] if x),
                          'sources': o['sources'],
                          'autres_locataires_du_lot': sorted(
                              {x['libelle'] for x in
                               tout[ag]['lots'][(ag, o['compte'], o['immeuble'], o['lot'])]
                               if x['identite'] != o['identite']})})
        print('  %-16s %10d %16d %26d %18d' %
              (ag, sum(c.values()), c['jamais appelé, présent au document'],
               c['mois plein + un seul mois de recul'],
               c['appels cessés, lot jamais revu depuis']))
    io.open(VALIDATIONS, 'w', encoding='utf-8').write(
        json.dumps(file_, ensure_ascii=False, indent=1))
    print('  → %d demandes → %s' % (len(file_), VALIDATIONS))

    entete('P4B — LIMITES DU CORPUS')
    for ag in agences:
        per = {b['periode'] for b in tout[ag]['blocs'] if b['periode']}
        if len(per) < 2:
            print('  %-16s une seule période (%s) → évolution d’occupation NON DÉMONTRABLE '
                  'SUR CE CORPUS' % (ag, ', '.join(sorted(per))))
        else:
            print('  %-16s %d périodes → évolution observable' % (ag, len(per)))
    print('\n  ⚠ Aucune valeur financière n’entre dans ces qualifications. Un impayé est ici un')
    print('    CONSTAT de rattachement, jamais une créance certifiée : nature, origine et')
    print('    antériorité relèvent des phases financières ultérieures.')

    # les successions, exportées pour le contrôle documentaire page par page
    exp = []
    for ag in agences:
        for o in tout[ag]['occupations']:
            if o['classe'] == 'B — succession démontrée':
                e = dict(o)
                e['periodes'] = sorted(x for x in o['periodes'] if x)
                e['repreneurs'] = [
                    {'identite': x['identite'], 'libelle': x['libelle'],
                     'cov_debut': x['cov_debut'], 'cov_fin': x['cov_fin'],
                     'sources': x['sources']}
                    for x in tout[ag]['lots'][(ag, o['compte'], o['immeuble'], o['lot'])]
                    if x['identite'] != o['identite'] and x['cov_debut']
                    and o['cov_fin'] and x['cov_debut'] >= o['cov_fin']]
                exp.append(e)
    io.open(DUMP, 'w', encoding='utf-8').write(json.dumps(exp, ensure_ascii=False, indent=1))
    print('\n  successions exportées pour contrôle documentaire → %s (%d)' % (DUMP, len(exp)))
    return ok, tout


if __name__ == '__main__':
    demande = ' '.join(sys.argv[1:]).strip()
    tous = list(perimetre().keys())
    noms = [demande] if demande in tous else tous
    reussi, _ = certifier(noms)
    print('\nP4B — OCCUPATION : %s' % ('OUI — chaque statut porte sa date et sa preuve'
                                       if reussi else 'NON — voir les ⚠ ci-dessus'))
    sys.exit(0 if reussi else 1)
