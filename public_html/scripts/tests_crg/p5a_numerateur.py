# -*- coding: utf-8 -*-
"""
LE NUMÉRATEUR CERTIFIÉ DE P5A — « appelé au locataire », disponible au LOT.

⚠️ CE MODULE NE REDÉFINIT RIEN. Il rejoue exactement le retranchement que `P5A-APPEL-15`
   certifie — report / solde antérieur (STOCK) et dépôt de garantie (mouvement propriétaire) —
   à partir du même fichier de qualification, et il VÉRIFIE qu'il retombe au centime sur le
   total certifié. Si ce n'était pas le cas, il lèverait plutôt que de livrer un chiffre.

⚠️ POURQUOI IL EXISTE. `P5A.appels()` livre le montant DOCUMENTAIRE (4 761 767,93 €). Le flux
   appelé au locataire est 4 357 710,67 €. Bâtir une rentabilité théorique sur le premier
   reviendrait à compter des dépôts de garantie et des reports d'arriéré comme du loyer.
   Aucune phase aval ne doit refaire ce calcul dans son coin : elle importe celui-ci.
"""
import collections
import io
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import AGENCES   # noqa: E402
import p5a_certification as P5A   # noqa: E402

QUALIFIE = P5A.QUALIFIE
# ⚠️ LES DEUX SEULES NATURES QUE `P5A-APPEL-15` RETRANCHE. Ni les taxes, ni les
#    régularisations, ni les frais de procédure : eux sont bien appelés au locataire.
HORS_FLUX = ('report / solde antérieur — STOCK', 'dépôt de garantie — propriétaire/régie')
# ⚠️ CHIFFRES RECTIFIÉS LE 01/09/2026 (FOCH/SABY). Les anciens — 4 761 767,93 documentaires et
#    4 357 710,67 appelés — comptaient DEUX FOIS 42 appels réénoncés par une seconde pièce de
#    la même situation de gestion. Aucun événement n'a été ajouté : seuls des doublons ont été
#    retirés. Ces deux constantes sont la référence de non-régression du numérateur.
CERTIFIE = 4328429.23
DOCUMENTAIRE = 4733161.49


def montant_documentaire(a):
    """Le montant que le document imprime, toutes composantes confondues."""
    return round(sum(float(c.get('montant') or 0) for c in a.get('composantes') or []), 2)


def _cle(x, deb='periode_debut', fin='periode_fin'):
    return (x['sha'], str(x['immeuble']), str(x['lot']), str(x['locataire']),
            x.get(deb), x.get(fin))


def _exclusions():
    par = collections.defaultdict(float)
    for x in json.load(io.open(QUALIFIE, encoding='utf-8')):
        if x['nature_demontree'] in HORS_FLUX:
            par[_cle(x)] += float(x['montant'])
    return par


_EXCL = None


def exclusions():
    global _EXCL
    if _EXCL is None:
        _EXCL = _exclusions()
    return _EXCL


def flux_appeles(appels):
    """Rend `[(appel, flux appelé au locataire)]` pour la liste fournie.

    ⚠️ CHAQUE EXCLUSION EST CONSOMMÉE UNE SEULE FOIS. `P5A-APPEL-14` certifie que deux blocs
       peuvent porter le même lot et le même locataire dans un même CRG : la clé documentaire
       n'est donc PAS unique. La retrancher de chaque appel qui la partage sur-retranchait
       250 888,76 € et ramenait le flux à 4 106 821,91 €. On l'impute à un seul appel du lot —
       le total du lot, de l'immeuble et du propriétaire reste exact à tous les niveaux.
    """
    reste = dict(exclusions())
    sortie = []
    for a in appels:
        k = _cle(a, 'periode_appel_debut', 'periode_appel_fin')
        d = montant_documentaire(a)
        sortie.append((a, round(d - reste.pop(k, 0.0), 2)))
    # ⚠️ UNE EXCLUSION ORPHELINE N'EST TOLÉRÉE QUE SI SON JUMEAU L'A DÉJÀ APPLIQUÉE. La
    #    rectification FOCH/SABY retire des occurrences réénoncées ; la qualification « Divers »
    #    ayant couvert LES DEUX pièces, l'exclusion de l'occurrence retirée reste sans emploi.
    #    C'est légitime — mais seulement parce que la retenue porte la même. Toute autre
    #    exclusion sans appel serait un défaut de lecture, et elle lève.
    if reste:
        # ⚠️ LE CONTRÔLE SE BORNE AUX PIÈCES FOURNIES. Appelé sur un seul périmètre, ce module
        #    ne voit pas les exclusions des trois autres : les compter comme orphelines
        #    ferait échouer un appel parfaitement légitime.
        shas = {a.get('sha') for a in appels}
        reste = {k: v for k, v in reste.items() if k[0] in shas}
        appliques = {(k[1], k[2], k[3], k[4], k[5]) for k in exclusions() if k not in reste}
        veuves = [k for k in reste if (k[1], k[2], k[3], k[4], k[5]) not in appliques]
        if veuves:
            raise ValueError('EXCLUSION P5A NON RATTACHÉE / CERTIFICATION IMPOSSIBLE : %d clés'
                             % len(veuves))
    return sortie


def controler(appels):
    """Fail closed : si le retranchement ne retombe pas sur le chiffre certifié, on lève."""
    paires = flux_appeles(appels)
    doc = round(sum(montant_documentaire(a) for a in appels), 2)
    flux = round(sum(v for _, v in paires), 2)
    if abs(doc - DOCUMENTAIRE) > 0.01 or abs(flux - CERTIFIE) > 0.01:
        raise ValueError('NUMÉRATEUR P5A NON REPRODUIT / CERTIFICATION IMPOSSIBLE : '
                         'documentaire %.2f (attendu %.2f) · flux %.2f (attendu %.2f)'
                         % (doc, DOCUMENTAIRE, flux, CERTIFIE))
    return doc, flux


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    tous = [a for ag in AGENCES for a in P5A.appels(ag)]
    doc, flux = controler(tous)
    print('total documentaire   %14.2f' % doc)
    print('− report et dépôts   %14.2f' % (doc - flux))
    print('= appelé au locataire%14.2f   ✓ conforme au chiffre certifié P5A' % flux)
