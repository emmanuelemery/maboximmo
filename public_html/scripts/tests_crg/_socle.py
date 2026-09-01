# -*- coding: utf-8 -*-
"""
SOCLE COMMUN DES TESTS DE CERTIFICATION DU LECTEUR CRG.

⚠️ CES TESTS NE VIVENT PAS DANS UN DOSSIER TEMPORAIRE. Ils ont été écrits pendant la Phase 1
   dans un scratchpad de session — ils auraient disparu avec elle, et la certification avec
   eux. Une phase certifiée dont on ne sait plus rejouer les contrôles n'est pas certifiée,
   c'est un souvenir.

⚠️ LE PÉRIMÈTRE CERTIFIÉ EST UNE DONNÉE DE RÉFÉRENCE, PAS UN ARTEFACT. Les trois listes de
   `corpus/` figent QUELS PDF composent le corpus des 411 références — SHA par SHA. Sans elles,
   « 411 CRG » ne veut plus rien dire : n'importe quel dossier un peu différent donnerait un
   autre chiffre, et la non-régression comparerait deux ensembles distincts en croyant mesurer
   une dérive.

Le corpus RELU (les JSON produits par les lecteurs) reste, lui, en zone de travail : il se
régénère à volonté depuis les PDF, c'est sa raison d'être.
"""
import io
import json
import os

ICI = os.path.dirname(os.path.abspath(__file__))

# Les JSON produits par les lecteurs — régénérables, donc hors dépôt.
# Plusieurs dossiers : le périmètre normal et GROUPE SIR se régénèrent séparément.
CORPUS_RELU = [c for c in os.environ.get(
    'CRG_CORPUS_RELU', r'C:\tmp\p1_json;C:\tmp\p1_sir_json').split(';') if c]

# Le périmètre certifié — versionné avec les tests.
CORPUS_REF = os.path.join(ICI, 'corpus')

# ⚠️ GROUPE SIR EST UN PÉRIMÈTRE, PAS UNE EXCLUSION. Les mêmes règles s'y appliquent et son
#    résultat s'additionne. Le traiter à part a révélé quatre défauts qu'aucune des trois
#    autres agences ne pouvait montrer — dont un bandeau de vœux pris pour une raison sociale.
AGENCES = ('LYON hors SIR', 'EMERY IMMO', 'VIENNE', 'GROUPE SIR')
FICHIERS_PERIMETRE = {'LYON hors SIR': 'lyon_hors_sir.json',
                      'EMERY IMMO': 'emery_immo.json',
                      'VIENNE': 'vienne.json',
                      'GROUPE SIR': 'groupe_sir.json'}


def perimetre(agence=None):
    """Les SHA du périmètre certifié, par agence ou tous."""
    out = {}
    for ag, f in FICHIERS_PERIMETRE.items():
        if agence and ag != agence:
            continue
        chemin = os.path.join(CORPUS_REF, f)
        out[ag] = {l[0]: l for l in json.load(io.open(chemin, encoding='utf-8'))}
    return out


def documents(agence=None):
    """Les extractions relues, restreintes au périmètre certifié.

       ⚠️ ON NE PREND JAMAIS « tout ce qui traîne dans le dossier ». Un JSON surnuméraire
          gonflerait les compteurs sans qu'aucun contrôle ne s'en aperçoive."""
    voulus = set()
    for ag, m in perimetre(agence).items():
        voulus |= set(m)
    out, vus = [], set()
    for dossier in CORPUS_RELU:
        if not os.path.isdir(dossier):
            continue
        for nom in sorted(os.listdir(dossier)):
            if not nom.endswith('.json'):
                continue
            d = json.load(io.open(os.path.join(dossier, nom), encoding='utf-8'))
            sha = d.get('_sha')
            if sha in voulus and sha not in vus:
                vus.add(sha)
                out.append(d)
    return out


def euros(v):
    return format(round(float(v or 0), 2), ',.2f').replace(',', ' ')


def entete(titre):
    print('\n' + '=' * 96)
    print(titre)
    print('=' * 96)


# ═══════════════════════════════════════════════════════════════════════════════════════════
#  FAIL CLOSED — UNE PANNE TECHNIQUE NE DOIT JAMAIS RESSEMBLER À UN RÉSULTAT MÉTIER
# ═══════════════════════════════════════════════════════════════════════════════════════════
#
# ⚠️ CE DÉFAUT A ÉTÉ VÉCU, ET IL EST PIRE QU'UN PLANTAGE. `est_section()` rendait une chaîne
#    quand son appelant attendait un couple ; le `except Exception: return {}` qui l'entourait a
#    transformé cette erreur de programmation en INDEX VIDE, présenté ensuite comme un résultat
#    exploitable. Le harnais est resté vert. Un CRG illisible et un lecteur en panne rendaient
#    exactement la même chose : rien.
#
# ⚠️ LA RÈGLE : ON N'ATTRAPE JAMAIS `Exception`. On attrape les seules erreurs qu'un DOCUMENT
#    peut provoquer — fichier absent, PDF corrompu. Une `TypeError`, une `ValueError`, une
#    `AttributeError` viennent de NOTRE code : elles doivent remonter et rougir le harnais.
#
# ⚠️ ET UN RÉSULTAT VIDE SUR UN CORPUS NON VIDE EST UNE PANNE, pas un constat. `exiger_non_vide`
#    le dit bruyamment.

class ErreurLecteur(Exception):
    """CERTIFICATION IMPOSSIBLE — le lecteur est en panne, aucun chiffre ne doit sortir."""


ERREURS_DOCUMENT = (OSError, IOError, ValueError, KeyError, IndexError)
try:                                             # les exceptions propres à pdfminer/pdfplumber
    from pdfminer.pdfparser import PDFSyntaxError
    from pdfminer.psparser import PSException
    ERREURS_PDF = (OSError, IOError, PDFSyntaxError, PSException)
except Exception:                                # noqa: BLE001 — socle optionnel
    ERREURS_PDF = (OSError, IOError)


def ouvrir_pdf(chemin, compteur=None, cle=None):
    """Ouvre un PDF et rend ses pages en texte, ou None si LE DOCUMENT est en cause.

       ⚠️ SEULES LES ERREURS DE DOCUMENT SONT ABSORBÉES, et elles sont COMPTÉES. Tout le reste
          — donc tout bug de lecture — remonte."""
    import pdfplumber
    try:
        with pdfplumber.open(chemin) as pdf:
            return {n: (p.extract_text() or '') for n, p in enumerate(pdf.pages, 1)}
    except ERREURS_PDF:
        if compteur is not None:
            compteur[cle or 'pdf illisible'] += 1
        return None


def exiger_non_vide(valeur, quoi, attendu_min=1):
    """Un résultat vide sur un corpus non vide est une PANNE, jamais un constat."""
    n = len(valeur) if hasattr(valeur, '__len__') else (0 if valeur is None else 1)
    if n < attendu_min:
        raise ErreurLecteur(
            'ERREUR LECTEUR / CERTIFICATION IMPOSSIBLE — %s est vide (%d < %d attendu). '
            'Aucun chiffre métier ne peut être produit dans cet état.' % (quoi, n, attendu_min))
    return valeur
