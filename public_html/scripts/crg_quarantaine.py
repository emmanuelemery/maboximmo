# -*- coding: utf-8 -*-
"""
LA QUARANTAINE — l'autorité unique sur ce que l'agent n'a PAS le droit de lire.

⚠️ CE FICHIER EXISTE PARCE QUE TROIS OUTILS PROTÉGEAIENT LE HOLDOUT CHACUN DE SON CÔTÉ, ET
   TOUS DE LA MÊME FAÇON FRAGILE : `if 'CRG HOLDOUT' not in nom_de_fichier`. Cette garde
   suppose qu'Emmanuel nommera ses fichiers d'une façon précise que personne ne lui a dite.
   S'il isole ses documents dans un dossier « À NE PAS LIRE », ou s'il les laisse en place
   sous leur nom d'origine, la garde ne voit rien et l'agent lit son propre examen.

⚠️ UN HOLDOUT LU UNE SEULE FOIS EST BRÛLÉ POUR TOUJOURS. Il n'existe aucune façon de
   « désapprendre » : le score de généralisation qu'il devait mesurer devient définitivement
   sans valeur, et rien à l'écran ne le dira. C'est pourquoi la protection doit être
   TOLÉRANTE — elle reconnaît plusieurs conventions — et CENTRALE : un seul endroit à
   vérifier, un seul endroit à corriger.

⚠️ ELLE EXCLUT SUR LE CHEMIN ENTIER, PAS SUR LE NOM DU FICHIER. Un fichier au nom parfaitement
   ordinaire, rangé dans un dossier en quarantaine, est en quarantaine. C'est le cas le plus
   probable en pratique : on met des documents de côté, on ne les renomme pas un par un.

⚠️ ET ELLE NE DIT JAMAIS CE QU'ELLE PROTÈGE. `contenu_quarantaine()` n'existe pas, et ne doit
   pas être écrit. Lister le HOLDOUT — même sans l'ouvrir — donnerait déjà à l'agent la
   composition de son examen : combien de documents, de quelles agences, de quelles périodes.
"""
import os

# ── LES CONVENTIONS RECONNUES ────────────────────────────────────────────────────────────
# ⚠️ TABLE ADDITIVE. Une convention s'AJOUTE ici ; aucune ne se retire, sous peine de rendre
#    lisible un corpus qui avait été mis de côté sous l'ancienne.
MARQUEURS = (
    'holdout',
    'ne pas lire',
    'ne_pas_lire',
    'reserve examen',
    'quarantaine',
)


def est_en_quarantaine(chemin):
    """Ce chemin — fichier OU l'un de ses dossiers parents — est-il mis de côté ?

    ⚠️ ON REGARDE TOUT LE CHEMIN. Un fichier au nom banal dans un dossier réservé est
       réservé : c'est ainsi qu'on met des documents de côté dans la vraie vie.
    """
    entier = str(chemin or '').replace('\\', '/').lower()
    return any(m in entier for m in MARQUEURS)


def pdfs_lisibles(racine, maxi=None):
    """Les PDF qu'un outil a le droit de lire sous cette racine, quarantaine exclue.

    ⚠️ TOUTE ÉNUMÉRATION DE CORPUS PASSE PAR ICI. Une seule fonction à relire pour savoir ce
       que l'agent peut voir — et une seule à corriger le jour où la convention change.
    """
    if est_en_quarantaine(racine):
        return []
    if os.path.isfile(racine):
        return [] if est_en_quarantaine(racine) else [racine]
    trouves = []
    for dossier, sous_dossiers, fichiers in os.walk(racine):
        # On n'entre même pas dans un dossier en quarantaine : ni listing, ni statistique.
        sous_dossiers[:] = [d for d in sous_dossiers
                            if not est_en_quarantaine(os.path.join(dossier, d))]
        if est_en_quarantaine(dossier):
            continue
        for f in fichiers:
            p = os.path.join(dossier, f)
            if f.lower().endswith('.pdf') and not est_en_quarantaine(p):
                trouves.append(p)
    trouves.sort()
    return trouves[:maxi] if maxi else trouves
