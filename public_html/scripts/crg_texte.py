# -*- coding: utf-8 -*-
"""
LECTURE STRUCTURELLE — les primitives qui rendent une règle indépendante de l'espacement.

⚠️ CE FICHIER EXISTE PARCE QUE TROIS RÈGLES DE LECTURE DÉPENDAIENT DU NOMBRE EXACT D'ESPACES
   QUE L'EXTRACTEUR AVAIT PRODUIT — et qu'aucune ne le disait. Mesuré le 03/09/2026 sur les
   quatre corpus, à contenu métier rigoureusement identique :

     · un bloc adresse cherché dans une fenêtre de cinq lignes : **0 propriétaire lu** sur un
       corpus entier dans un mode, **14** dans l'autre ;
     · un nom découpé sur « deux espaces ou plus » : « Madame CAISSE Corinne » devenait
       « Madame CAISSE » dès que l'extracteur aérait la ligne ;
     · un nom de ville dont la classe refusait les chiffres : « LYON 08 » — un arrondissement,
       pas une faute — faisait perdre l'immeuble entier, ou rendait une ville vide.

⚠️ CE QUI EST EN CAUSE N'EST PAS L'EXTRACTEUR. `poppler -layout` et `xpdf -layout` rendent
   exactement la même chose sur les quatre corpus, à une troncature près. C'est le MODE qui
   change l'espacement — et donc les règles qui s'appuient dessus. Une règle métier ne doit
   pas changer de résultat parce qu'une colonne a été aérée.

⚠️ NORMALISER N'EST PAS RÉÉCRIRE. Ces fonctions servent à RECONNAÎTRE une structure. Le texte
   brut, sa page et sa provenance restent la preuve : on ne remplace jamais la preuve par sa
   version normalisée.

⚠️ ET ON N'ÉLARGIT PAS « JUSQU'À TROUVER QUELQUE CHOSE ». Une fenêtre fixe est remplacée par
   une frontière DOCUMENTAIRE — un titre, un champ d'en-tête, un début de tableau — assortie
   d'un plafond de sécurité. Le plafond n'est pas la règle : c'est le garde-fou qui empêche
   une page dégradée de faire remonter n'importe quoi de trente lignes plus bas.
"""
import re

# ── LE VOCABULAIRE DU DOCUMENT — DÉCLARÉ, ET ADDITIF ─────────────────────────────────────
# ⚠️ ON AJOUTE, ON NE REMPLACE JAMAIS. Chaque comptable imprime ses propres libellés : le jour
#    où un document apporte une queue de tableau inconnue, elle s'AJOUTE ici. Réécrire cette
#    table ferait disparaître une reconnaissance acquise sans que rien ne le signale.
QUEUE_TABLEAU = [
    r'\.{2,}\s*Suite\b',
    r'\bSuite\b',
    r'\bD[ée]bit\b',
    r'\bCr[ée]dit\b',
    r'\bD[ée]penses\b',
    r'\bRecettes\b',
    r'\bPage\s+\d+\b',
    # ⚠️ AJOUTÉ le 03/09/2026 — la table s'AJOUTE, elle ne se réécrit pas. « Solde » est un
    #    libellé de tableau, jamais un nom de lieu : sans lui, trois « villes » du corpus
    #    s'appelaient « VIENNE Solde », « CHAPONOST Solde », « NEUVILLE-SUR-SAONE Solde » —
    #    et dans UN SEUL mode de lecture, ce qui rendait l'écart d'autant plus difficile à voir.
    r'\bSolde\b',
]

# Les champs de l'en-tête : ce ne sont jamais le nom de quelqu'un ni un lieu.
CHAMPS_ENTETE = [
    r'P[ée]riode\s+du\b',
    r'Identifiant\s+extra?n?tnet',
    r'Mot\s+de\s+passe',
    r'Compte\s+personnel\b',
    r'Compte\s+rendu\s+de\s+gestion\b',
]

_RE_QUEUE = re.compile('|'.join(QUEUE_TABLEAU), re.I)
_RE_CHAMPS = re.compile('|'.join(CHAMPS_ENTETE), re.I)
# Le début d'une cellule de mise en page : le document sépare ses colonnes par ≥ 2 espaces.
# ⚠️ CETTE CONVENTION DIT OÙ UN CHAMP COMMENCE, JAMAIS OÙ IL FINIT. En mode tableau
#    l'extracteur aère aussi l'INTÉRIEUR d'une cellule : s'en servir comme fin de champ
#    coupait « Madame CAISSE  Corinne » en deux et faisait disparaître le prénom.
RE_DEBUT_CELLULE = re.compile(r'(?:(?<=\s\s)|^)\S')
_RE_CELLULE = RE_DEBUT_CELLULE
# Un montant imprimé : sert de frontière, jamais de nature.
_RE_MONTANT = re.compile(r'-?\d{1,3}(?:[\s ]\d{3})*[.,]\d{2}')
_RE_ESPACES = re.compile(r'[\s   ]+')


# ⚠️ L'ÉCART QUI SÉPARE LES DEUX COLONNES D'UN EN-TÊTE — relatif à la marge du document, jamais
#    une colonne absolue. Mesuré le 03/09/2026 : sur un même document, le même repère se trouve
#    colonne 140 sous un extracteur et colonne 43 sous l'autre, parce que chacun a son modèle de
#    largeur de caractère. Aligner deux LIGNES entre elles est donc faux hors d'un seul rendu ;
#    « nettement à droite de la marge » reste vrai partout.
ECART_COLONNE_DROITE = 30


def marge_gauche(lignes):
    """La marge du bloc : la plus petite indentation d'une ligne qui porte du texte."""
    indents = [colonne_du_texte(l) for l in lignes if l.strip()]
    return min(indents) if indents else 0


def normaliser(texte):
    """Le même texte, ramené à un espacement canonique. POUR COMPARER, PAS POUR STOCKER.

    ⚠️ ELLE ABSORBE AUSSI LES ESPACES INSÉCABLES. Un `\\u00a0` au milieu d'un nom se compare
       comme une espace ordinaire — sinon deux lectures du même nom restent « différentes »
       pour une raison que personne ne peut voir à l'écran.
    """
    return _RE_ESPACES.sub(' ', (texte or '')).strip()


def en_colonnes(ligne):
    """La ligne telle qu'elle s'AFFICHE : tabulations converties en colonnes.

    ⚠️ UNE TABULATION EST UN CARACTÈRE ET HUIT COLONNES. Mesurer une abscisse sans la
       convertir place un champ précédé de tabulations trente colonnes trop à gauche — et
       un extracteur qui sépare ses colonnes par des tabulations rend alors zéro.
    """
    return (ligne or '').expandtabs(8)


def colonne_du_texte(ligne):
    """La colonne où commence le texte VISIBLE — pas celle où commence une capture.

    ⚠️ UNE POSITION QUI COMPTE LES ESPACES DE TÊTE EST UN REPÈRE FAUX QUI A L'AIR VRAI. Voir
       `APP-0010` : un ancrage pris au début d'une capture avalant vingt-six espaces faisait
       rejeter TOUS les noms d'un corpus.
    """
    ligne = en_colonnes(ligne)
    return len(ligne) - len(ligne.lstrip())


def sans_queue_tableau(texte):
    """Le texte débarrassé de la queue d'en-tête de tableau que le document imprime à droite.

    ⚠️ UNE FRONTIÈRE SE NOMME, ELLE NE SE COMPTE PAS. La règle précédente s'arrêtait « après
       deux espaces » : elle marchait dans un mode et pas dans l'autre, parce que l'extracteur
       décide seul du nombre d'espaces. Le vocabulaire du document, lui, ne change pas.
    """
    m = _RE_QUEUE.search(texte or '')
    return (texte[:m.start()] if m else (texte or ''))


def est_ligne_de_tableau(ligne):
    """Cette ligne appartient-elle au TABLEAU plutôt qu'à l'en-tête ?

    ⚠️ UNE LIGNE DE TABLEAU N'EST PAS UN BLOC ADRESSE, MÊME BIEN PLACÉE. Un bandeau d'immeuble
       porte à sa droite un fragment qui ressemble à un champ d'identité — « LYON 08 » — et
       une règle purement géométrique le retenait comme nom de propriétaire. Le vocabulaire du
       document tranche : là où il imprime « Débit », « Crédit » ou « ...Suite », il tabule.
    """
    return bool(_RE_QUEUE.search(ligne or ''))


def est_champ_entete(texte):
    """Ce fragment est-il un champ de l'en-tête plutôt qu'une donnée ?"""
    return bool(_RE_CHAMPS.search(texte or ''))


def contient_montant(texte):
    """Un montant dans un champ d'identité est le signe qu'on a débordé sur le tableau."""
    return bool(_RE_MONTANT.search(texte or ''))


def depuis_la_colonne(ligne, colonne, tolerance=6):
    """Tout ce que la ligne porte À PARTIR d'une colonne, jusqu'à sa fin. Puis normalisé.

    ⚠️ TOUT, PAS « LA PREMIÈRE CELLULE ». Découper sur « deux espaces ou plus » supposait que
       l'extracteur n'aère jamais l'intérieur d'une cellule. Il l'aère : en mode tableau,
       « Madame CAISSE  Corinne » se coupait en deux et le prénom disparaissait — une perte
       silencieuse, dans un champ d'identité.

    ⚠️ ON S'ARRÊTE À LA PREMIÈRE FRONTIÈRE DOCUMENTAIRE. Prendre jusqu'au bout de la ligne
       sans borne ferait entrer un champ d'en-tête ou un montant dans un nom.

    ⚠️ ET LE DÉBUT RESTE UNE FRONTIÈRE DE CELLULE. Ma première version tranchait la chaîne au
       caractère près : « COMPTE RENDU DE GESTION » devenait « ESTION », et le lecteur rendait
       ce galimatias comme nom de propriétaire. Le NUMÉRO de colonne dit où l'on veut regarder ;
       c'est le document, par ses séparateurs, qui dit où un champ COMMENCE.
    """
    ligne = en_colonnes(ligne)
    depart = None
    for m in _RE_CELLULE.finditer(ligne):
        if m.start() >= colonne - tolerance:
            depart = m.start()
            break
    if depart is None:
        return ''
    fragment = sans_queue_tableau(ligne[depart:])
    m = _RE_CHAMPS.search(fragment)
    if m:
        fragment = fragment[:m.start()]
    m = _RE_MONTANT.search(fragment)
    if m:
        fragment = fragment[:m.start()]
    return normaliser(fragment)


# Une commune : des lettres, éventuellement suivies d'un arrondissement à deux chiffres.
# ⚠️ « LYON 08 » EST UNE VILLE. Refuser les chiffres faisait perdre l'immeuble entier ; les
#    accepter sans borne faisait entrer « VIENNE 1 022, 13 » et « CHAPONOST Solde » dans le
#    champ. Ce motif dit ce qu'une commune PEUT être, il ne compte aucun espace.
_RE_VILLE = re.compile(r"^([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'’\-]*(?:[ '\-][A-Za-zÀ-ÿ'’\-]+)*)"
                       r"(?:\s+(\d{1,2}))?$")


def ville_de(fragment):
    """Le nom de commune que porte ce fragment — ou rien, s'il n'en porte pas.

    ⚠️ `ABSENCE DE LECTURE ≠ LECTURE APPROXIMATIVE`. Quand la queue du bandeau laisse passer
       des débris de tableau — un solde, une référence de lot, une ligne de pointillés —, on
       ne rogne pas jusqu'à obtenir quelque chose de présentable : on ne nomme pas la ville.
       L'immeuble, lui, existe quand même : perdre sa ville n'est pas perdre l'immeuble.
    """
    m = _RE_VILLE.match(normaliser(sans_queue_tableau(fragment)))
    if not m:
        return ''
    return (m.group(1) + ' ' + m.group(2)) if m.group(2) else m.group(1)


def bloc_borne(lignes, depart, frontiere, plafond=15):
    """Parcourt un bloc documentaire à partir d'un repère, JUSQU'À UNE FRONTIÈRE DÉMONTRÉE.

    Rend les couples `(index, ligne)` du bloc, la frontière exclue.

    ⚠️ CECI REMPLACE `lignes[i+1:i+6]`. Cinq n'était pas une propriété du document : c'était
       le nombre qui marchait sur le premier gabarit examiné. Sur un autre gabarit, le bloc
       utile était à SIX lignes — et la lecture rendait zéro, sans un mot.

    ⚠️ LE PLAFOND N'EST PAS LA RÈGLE. La règle est la frontière ; le plafond empêche une page
       dégradée, où aucune frontière n'est reconnue, de faire remonter une ligne quelconque
       de très loin. Un bloc qui atteint le plafond est un bloc qu'on n'a pas su borner : la
       lecture s'arrête, elle ne devine pas.
    """
    for i in range(depart + 1, min(depart + 1 + plafond, len(lignes))):
        ligne = lignes[i]
        if frontiere(ligne):
            return
        yield i, ligne
