# -*- coding: utf-8 -*-
"""
P7 · LECTEUR DE SECTIONS — la strate documentaire que le lecteur figé ignorait.

⚠️ LE CRG A TROIS NIVEAUX, PAS DEUX. Le lecteur figé conserve la RUBRIQUE et le LIBELLÉ, mais
   jette la SECTION, imprimée entre tirets au-dessus d'elles :

       - Dépenses de Syndic -
       Appel de fonds
         AF GESTION-SDC 93 CHARLEMAGN …            368.28

   Or c'est souvent la SECTION qui démontre la famille quand le libellé ne dit rien : sur les
   187 lignes que ni la rubrique ni le libellé ne qualifiaient, 91 sont surplombées par une
   section explicite. « DEPOSE MOQUETTE + POSE SOL » ne contient pas le mot « travaux » — mais
   il est imprimé sous « - Dépenses déductibles - », ce qui démontre au moins qu'il s'agit
   d'une dépense du bien supportée par le propriétaire.

⚠️ ET UNE SECTION NE DIT PAS TOUJOURS LA SOUS-NATURE. « - Dépenses de Syndic - » démontre la
   copropriété ; « - Dépenses déductibles - » ne démontre qu'une QUALIFICATION FISCALE : la
   dépense est déductible, sans dire de quoi elle est faite. La section fait donc passer une
   ligne de « rien de démontrable » à « famille démontrée, sous-nature inconnue » — jamais à
   une sous-nature inventée.

Ce lecteur est GÉNÉRAL : il lit la structure de page, pas un vocabulaire. Un CRG inconnu du
même format en bénéficiera sans qu'une ligne de code change.

Lent : rouvre les PDF de LYON, EMERY et GROUPE SIR. Sortie → C:\\tmp\\p7_sections.json
"""
import sys
import os
import io
import re
import json
import glob
import collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import (documents, perimetre, entete, ERREURS_PDF,   # noqa: E402
                    exiger_non_vide)

sys.stdout.reconfigure(encoding='utf-8')
import pdfplumber   # noqa: E402

RACINES = {'LYON hors SIR': r'D:\CRG REGIE EMERY LYON', 'GROUPE SIR': r'D:\CRG REGIE EMERY LYON',
           'EMERY IMMO': r'D:\CRG EMERY IMMO'}
SORTIE = r'C:\tmp\p7_sections.json'

# ⚠️ CHAQUE FORMAT ENCADRE SA SECTION AUTREMENT, MAIS TOUS LA METTENT SEULE SUR SA LIGNE.
#    LYON et GROUPE SIR l'entourent de tirets : « - Dépenses de Syndic - ». EMERY l'imprime
#    en MAJUSCULES sans aucun encadrement — « DEPENSES DEDUCTIBLES », « CHARGES GENERALES » —
#    et parfois entre astérisques : « *** CHARGES GENERALES *** ». Chercher les seuls tirets
#    rendait EMERY totalement aveugle : 0 ligne située sur 951.
#
#    Ce qui fait une section n'est donc PAS son encadrement mais deux choses conjointes :
#    elle est SEULE SUR SA LIGNE, et son texte appartient au VOCABULAIRE DES SECTIONS. Sans
#    ce second filtre, toute raison sociale en capitales — « FRANCE AUTOMOBILES », « SCI
#    MALOUET » — deviendrait une section.
# ⚠️ DEUX NIVEAUX, PAS UN. Le premier passage confondait SECTION et RUBRIQUE : « Honoraires de
#    gestion HT » et « Taxe foncière » remontaient comme des sections, alors que ce sont des
#    rubriques — le niveau que le lecteur figé conserve DÉJÀ dans `entete`. Les nommer sections
#    aurait figé un vocabulaire faux dans la doctrine. La SECTION est le titre de PARTIE du
#    récapitulatif ; la RUBRIQUE est le titre de poste à l'intérieur. On indexe les deux.
SECTION_MOTS = (r"d[ée]penses?|recettes?|solde\s+mandat|garantie\s+de\s+loyer|autres?\s*$|"
                r"charges\s+(g[ée]n[ée]rales?|locatives?|ascenseur|eau)")
VOCABULAIRE = (r"d[ée]penses?|recettes?|honoraires?|r[ée]gularisations?|travaux|charges?|"
               r"garantie|solde\s+mandat|autres?|frais|assurances?|imp[oô]ts?|taxes?")
RE_SECTION = re.compile(
    r'^\s*(?:[-–*]{1,3}\s*)?((?:%s)[^-–*]{0,50}?)\s*(?:[-–*]{1,3})?\s*$' % VOCABULAIRE, re.I)
CLE = 26


# ═══════════════════════════════════════════════════════════════════════════════════════════
#  STRUCTURE OU VOCABULAIRE ? — l'expérience, et sa réponse
# ═══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ LA QUESTION A ÉTÉ POSÉE EXPÉRIMENTALEMENT : peut-on démontrer qu'une ligne est une section
#    SANS connaître son vocabulaire ? Mesuré sur douze CRG de deux formats :
#
#      SECTION    x0 médian  43   (marge gauche)   · 100 % sans montant · suivie d'une RUBRIQUE
#      rubrique   x0 médian 276   (indentée)       · 100 % sans montant · suivie de MONTANTS
#      autre      x0 médian 256                    ·  66 % sans montant
#
#    La géométrie CONFIRME donc la hiérarchie à trois niveaux — section en marge, rubrique
#    indentée, écritures chiffrées — mais elle ne SUFFIT PAS à détecter une section : le seul
#    critère « marge gauche + sans montant » rend 221 fausses candidates pour 26 vraies, et un
#    profil resserré (≤ 6 mots, suivie de montants sous trois lignes, hors filigrane) reste à
#    100 fausses pour 25 vraies — filigranes pivotés, en-têtes de lot, formules de politesse.
#
#    RÉPONSE : LA DÉTECTION EST HYBRIDE, et le vocabulaire y est une condition NÉCESSAIRE, pas
#    une simple confirmation sémantique. C'est une limitation réelle, consignée comme telle.
#
# ⚠️ D'OÙ CE CONTRÔLE D'ANOMALIE : une ligne qui a la FORME d'une section mais dont l'intitulé
#    est inconnu ne disparaît pas en silence — elle est signalée. Une future section
#    « IMMOBILISATIONS » sera vue comme SECTION POTENTIELLE NON RECONNUE — À QUALIFIER, et non
#    perdue. Elle n'est évidemment jamais classée P7A/P7B tant que son sens n'est pas démontré.
FILIGRANES = ('derewoP', 'yb', 'SCI')
RE_NON_SECTION = re.compile(r'^(Lot|COMPTE|Madame|Monsieur|Messieurs|Service|Que nous|TOTAUX?)\b')


def candidate_structurelle(texte, x0, mots, suivie_de_montants):
    """A la FORME d'une section, sans que son intitulé soit reconnu."""
    t = (texte or '').strip()
    if not t or len(t) > 56 or len(mots) > 6 or x0 > 60:
        return False
    if t in FILIGRANES or RE_NON_SECTION.match(t):
        return False
    if re.search(r'\d[\d\s]*[.,]\d{2}', t) or not suivie_de_montants:
        return False
    return est_section(t) is None


def est_section(ligne):
    """Une section : seule sur sa ligne, sans montant, et nommée par le vocabulaire.

       ⚠️ UNE LIGNE PORTANT UN MONTANT N'EST JAMAIS UNE SECTION — c'est une écriture. Sans
          cette garde, « Charges locatives appelées 40.60 » deviendrait un titre."""
    t = ligne.strip()
    if not t or len(t) > 56 or re.search(r'\d[\d\s]*[.,]\d{2}', t):
        return None
    m = RE_SECTION.match(t)
    if not m:
        return None
    titre = m.group(1).strip()
    # rend (titre, est-ce une VRAIE section) — la rubrique est indexée à part
    return titre, bool(re.match(r'^\s*(?:%s)' % SECTION_MOTS, titre, re.I))


def sections_du_pdf(chem):
    """Pour chaque ligne imprimée : la section qui la surplombe."""
    out = {}
    try:
        with pdfplumber.open(chem) as pdf:
            for n, pg in enumerate(pdf.pages, 1):
                # ⚠️ LA SECTION NE SE RÉINITIALISE PAS À CHAQUE PAGE : un bloc « Dépenses de
                #    Syndic » peut déborder. On la fait courir d'une page à l'autre, comme le
                #    document le fait — même raisonnement que pour le lot en P5A.
                for l in (pg.extract_text() or '').split('\n'):
                    s = est_section(l)
                    if s:
                        titre, vraie = s
                        sections_du_pdf.intitule = titre
                        if vraie:
                            sections_du_pdf.section = titre
                        continue
                    t = l.strip()
                    if t:
                        out.setdefault((n, t[:CLE]),
                                       (getattr(sections_du_pdf, 'section', None),
                                        getattr(sections_du_pdf, 'intitule', None)))
    except ERREURS_PDF:
        # ⚠️ SEUL UN DOCUMENT ILLISIBLE PASSE PAR ICI. Une erreur de notre code — le contrat de
        #    retour de `est_section()` violé, par exemple — doit REMONTER et rougir le harnais :
        #    c'est exactement le défaut qui avait produit un index vide passé pour un succès.
        return None
    return out


if __name__ == '__main__':
    table, c = {}, collections.Counter()
    for ag in perimetre():
        if ag not in RACINES:
            continue
        docs = documents(ag)
        for i, d in enumerate(docs, 1):
            f = glob.glob(os.path.join(RACINES[ag], '**', d.get('_nom_original')), recursive=True)
            if not f:
                c[(ag, 'pdf introuvable')] += 1
                continue
            sections_du_pdf.section = sections_du_pdf.intitule = None
            lues = sections_du_pdf(f[0])
            if lues is None:
                c[(ag, 'pdf illisible')] += 1
                continue
            for (page, cle), (sec, intit) in lues.items():
                if sec or intit:
                    table['%s|%d|%s' % (d.get('_sha'), page, cle)] = [sec, intit]
                    c[(ag, 'lignes situées')] += 1
            if i % 60 == 0:
                print('   %s %d/%d...' % (ag, i, len(docs)), flush=True)

    # ⚠️ UN INDEX VIDE SUR 458 CRG EST UNE PANNE, PAS UN RÉSULTAT.
    exiger_non_vide(table, 'l’index des sections', 1000)
    io.open(SORTIE, 'w', encoding='utf-8').write(json.dumps(table, ensure_ascii=False))
    entete('SECTIONS IMPRIMÉES — LA STRATE QUE LE LECTEUR FIGÉ IGNORAIT')
    for ag in perimetre():
        if ag in RACINES:
            print('  %-16s %8d lignes situées' % (ag, c[(ag, 'lignes situées')]))
    v = collections.Counter(x[0] for x in table.values() if x[0])
    print('\n  VOCABULAIRE DES SECTIONS :')
    for k, n in v.most_common(15):
        print('   %7d  %s' % (n, k))
    print('\n  %d lignes indexées → %s' % (len(table), SORTIE))
