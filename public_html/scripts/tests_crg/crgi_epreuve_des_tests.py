# -*- coding: utf-8 -*-
"""
L'ÉPREUVE DES TESTS — on réintroduit les bugs, et on vérifie que les tests virent au rouge.

⚠️ UN TEST QUI N'A JAMAIS ÉTÉ VU ROUGE NE PROUVE PAS QU'IL SAIT DÉTECTER L'ERREUR. Un harnais
   entièrement vert peut n'être qu'un harnais qui ne regarde rien — c'est exactement ce qui
   s'est produit avec l'empreinte de la phase 2, qui existait et n'était jamais appelée.

Ce fichier remet en place, une par une, la version FAUTIVE du code, relance le test qui la
défend, et exige qu'il échoue. Puis il restaure. Si un test reste vert alors que son bug est
revenu, c'est LE TEST qui est en cause, et il est signalé comme tel.

⚠️ IL NE MODIFIE AUCUN FICHIER. Tout se passe en mémoire, sur les objets déjà importés.

Usage : python crgi_epreuve_des_tests.py
"""
import os
import re
import sys

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, RACINE)
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import crg_format as FMT                  # noqa: E402
import crg_integration_doublons as DBL    # noqa: E402
import crg_integration_lots as LOTS       # noqa: E402
import crg_integration_phase0 as P0       # noqa: E402
import crg_integration_phase4 as P4       # noqa: E402
import crgi_robustesse as R               # noqa: E402


def test_nomme(fragment):
    """Le test de la suite de robustesse dont le titre contient ce fragment."""
    for titre, _incident, fn in R.CAS:
        if fragment.lower() in titre.lower():
            return titre, fn
    raise LookupError('aucun test ne porte « %s »' % fragment)


def vire_au_rouge(fragment):
    """Le test échoue-t-il ? On n'attrape QUE l'assertion : une erreur d'un autre genre
    signalerait que le test s'est cassé, pas qu'il a détecté quelque chose."""
    _titre, fn = test_nomme(fragment)
    try:
        fn()
    except AssertionError:
        return True
    return False


# ⚠️ UN `from X import y` CRÉE UNE SECONDE LIAISON. Patcher `X.y` ne touche alors pas le test
#    qui a importé `y` directement : la mutation restait sans effet et l'épreuve concluait à
#    tort que le test était MUET. On remplace donc le symbole PARTOUT où il est lié.
def poser_partout(nom, valeur, remettre):
    for module in (DBL, FMT, LOTS, P0, P4, R):
        if hasattr(module, nom):
            ancien = getattr(module, nom)
            remettre((lambda m, n, a: lambda: setattr(m, n, a))(module, nom, ancien))
            setattr(module, nom, valeur)


EPREUVES = []


def epreuve(fragment, bug):
    def deco(fn):
        EPREUVES.append((fragment, bug, fn))
        return fn
    return deco


@epreuve('référence de lot coupée', 'la classe de caractères sans espace')
def _(remettre):
    poser_partout('RE_LOT', re.compile(r'-\s*Lot\s+([0-9A-Za-z][0-9A-Za-z\-]{0,28}?)-?\s*[–-]\s*Mandat'), remettre)


@epreuve('un seul caractère', 'le seuil de trois caractères')
def _(remettre):
    poser_partout('RE_LOT', re.compile(r'-\s*Lot\s+([0-9A-Za-z][0-9A-Za-z \-]{2,28}?)-?\s*[–-]\s*Mandat'), remettre)


@epreuve('Suite', 'le motif de continuation qui ne reconnaît plus rien')
def _(remettre):
    poser_partout('RE_SUITE', re.compile(r'^JAMAIS$'), remettre)


@epreuve('deux occupants successifs', 'ne garder que le premier « Locataire: »')
def _(remettre):
    premier = LOTS.locataires_de
    poser_partout('locataires_de', lambda seg: premier(seg)[:1], remettre)


@epreuve('les deux « pdftotext »', 'relire le nom sur la LIGNE du titre au lieu de la COLONNE')
def _(remettre):
    # L'implémentation d'avant le 02/09/2026 : juste sous Xpdf, fausse sous poppler.
    ancien = re.compile(r'COMPTE\s+RENDU\s+DE\s+GESTION\s{2,}(\S.*?)\s*$', re.I | re.M)

    def ligne_du_titre(texte):
        m = ancien.search(texte)
        return ' '.join(m.group(1).split()) if m else None

    poser_partout('_proprietaire_septeo', ligne_du_titre, remettre)


@epreuve('n’est jamais un nom', 'ne plus reconnaître les champs de l’en-tête')
def _(remettre):
    poser_partout('RE_ENTETE_CHAMPS', re.compile(r'^\bJAMAIS\b$'), remettre)


@epreuve('reconnu par le RÉFÉRENTIEL', 'l’intégrateur redevient aveugle à EMERY IMMO')
def _(remettre):
    def deux_familles_seulement(texte):
        """La reconnaissance d'avant le 02/09/2026 : la structure d'abord, EMERY nulle part."""
        u = (texte or '').upper()
        if 'AGENCE:' in u.replace(' ', '') and 'EXTRA' in u:
            return 'septeo_spi'
        if 'COMPTE RENDU DE GESTION' in u and 'COMPTE PERSONNEL' in u:
            return 'lyon'
        return 'inconnu'

    poser_partout('famille_du_texte', deux_familles_seulement, remettre)


@epreuve('ne l’emporte jamais sur l’enseigne', 'remettre la structure avant l’enseigne')
def _(remettre):
    def structure_dabord(texte):
        u = (texte or '').upper()
        if 'COMPTE RENDU DE GESTION' in u and 'COMPTE PERSONNEL' in u:
            return 'lyon'
        if 'EMERY IMMOBILIER' in u:
            return 'emery_immo'
        return 'inconnu'

    poser_partout('famille_du_texte', structure_dabord, remettre)


@epreuve('par LOT', 'apparier les verdicts du lot dans le désordre')
def _(remettre):
    import json as _json
    import sys as _sys

    def lot_decale():
        """Le mode par lot, mais les identifiants décalés d'un cran : chaque CRG hérite du
        verdict de son voisin. La règle est intacte, l'appariement est faux."""
        with open(_sys.argv[2], encoding='utf-8') as fh:
            paires = _json.load(fh)
        textes, _ = DBL.lire_pages(_sys.argv[1])
        rendus = [DBL.qualifier_paire(textes[int(p['ad']) - 1:int(p['af'])],
                                      textes[int(p['bd']) - 1:int(p['bf'])]) for p in paires]
        sortie = []
        for i, p in enumerate(paires):
            r = dict(rendus[(i + 1) % len(rendus)])
            r['id'] = p['id']
            sortie.append(r)
        _sys.stdout.write(_json.dumps(sortie, ensure_ascii=False))
        return 0

    poser_partout('main', lot_decale, remettre)


@epreuve('espace des milliers', 'le recollage des milliers retiré')
def _(remettre):
    poser_partout('RE_MILLIERS', re.compile(r'^JAMAIS$'), remettre)


@epreuve('symbole €', 'le recollage des centimes retiré')
def _(remettre):
    poser_partout('RE_TRONC', re.compile(r'^JAMAIS$'), remettre)


@epreuve('hors colonne', 'la tolérance élargie jusqu’à happer la colonne voisine')
def _(remettre):
    poser_partout('TOLERANCE', 60, remettre)


@epreuve('dont TVA', 'exiger l’espace dans « dont TVA »')
def _(remettre):
    poser_partout('RE_TVA', re.compile(r'^dont\s+TVA', re.I), remettre)


@epreuve('agrégat', 'le Récapitulatif traité comme une section de mouvements')
def _(remettre):
    ancien = dict(P4.SECTIONS)
    remettre(lambda: P4.SECTIONS.update(ancien))
    P4.SECTIONS['RECAP'] = P4.CHARGE


@epreuve('section inconnue', 'une section inconnue rangée par défaut')
def _(remettre):
    poser_partout('RE_SECTION', re.compile(r'^JAMAIS$'), remettre)


@epreuve('paramètre imprimé', 'le motif de paramètre qui ne reconnaît plus « base: »')
def _(remettre):
    poser_partout('RE_PARAMETRE', re.compile(r'^JAMAIS$'), remettre)


def principal():
    ok = 0
    muets = []
    for fragment, bug, poser in EPREUVES:
        restaurations = []
        poser(restaurations.append)
        try:
            rouge = vire_au_rouge(fragment)
        finally:
            for r in reversed(restaurations):
                r()
        titre, fn = test_nomme(fragment)
        # après restauration, le test doit être redevenu vert
        fn()
        if rouge:
            ok += 1
            print('  OK    « %s » détecte : %s' % (titre[:52], bug))
        else:
            muets.append((titre, bug))
            print('  MUET  « %s » ne voit PAS : %s' % (titre[:52], bug))
    print('\nÉPREUVE DES TESTS : %d/%d' % (ok, len(EPREUVES)))
    for titre, bug in muets:
        print('\n  TEST MUET — « %s »\n    le bug réintroduit n’a pas été détecté : %s'
              % (titre, bug))
    return 0 if not muets else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(principal())
