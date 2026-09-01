# -*- coding: utf-8 -*-
"""
SOCLE COMMUN P7 — LA LIGNE DE DÉPENSE, LUE COMME LE DOCUMENT L'IMPRIME.

⚠️ ON NE PART PAS DES CATÉGORIES DU LECTEUR. Le champ `categorie` est une déduction : il range
   99 345,21 € de LYON sous « autre » et fond des familles que le PDF distingue. Le document,
   lui, imprime une RUBRIQUE puis un LIBELLÉ :

       - Dépenses déductibles -
       Assurance PNO
         REGIE EMERY LOCA-IMMO ANNEE 2026        90.00   90.00

   La nature se lit donc sur `entete` OU sur `libelle`, selon le format : LYON et GROUPE SIR
   nomment la rubrique, tandis qu'EMERY y met souvent une DATE et met la nature dans le libellé.

⚠️ ET LA RUBRIQUE D'EMERY DÉBORDE SUR LA LIGNE SUIVANTE. Le document imprime :

       Reversement D.G. au prop.
         Mme SERVAJEAN Chèque n°0134434 27.01.26      646.00
       Honoraires H.T.                                 46.17

   Le lecteur attache la rubrique périmée « Reversement D.G. au prop. » à la ligne
   « Honoraires H.T. ». Tester la rubrique d'abord classait donc un honoraire en dépôt de
   garantie. **Quand le LIBELLÉ nomme lui-même sa famille, c'est lui qui gagne.**

⚠️ LE DÉPÔT DE GARANTIE SORT DE P7 DANS LES DEUX SENS — décision Emmanuel. « Reversement D.G.
   au prop. » est un flux propriétaire (P8) ; « remb DG locataire » est la RESTITUTION D'UN
   PASSIF détenu pour le locataire. Ni l'un ni l'autre n'est une dépense, un travail, un
   entretien, un avoir fournisseur ou un remboursement de dépense. Les laisser en P7A ferait
   dire un jour que le propriétaire a « dépensé » 2 000 € parce qu'il a rendu 2 000 € de dépôt.

⚠️ ET CERTAINES LIGNES NE SONT PAS DES DÉPENSES DU TOUT. « Loyers versés au propriétaire »,
   « Soldes mandats », « Virement » : flux régie ↔ propriétaire, réservés à P8.

⚠️ ENFIN, FAMILLE DÉMONTRÉE ≠ SOUS-NATURE DÉMONTRÉE. « Honoraires Annexes » ne dit pas QUEL
   honoraire, mais il dit que c'est un HONORAIRE. Le ranger avec « Local 0013 » dans un même
   sac « indéterminable » perdrait une information que le document donne.

⚠️ ET UNE MAILLE NE S'INVENTE PAS. Chez LYON et EMERY le lot est imprimé sur une ligne séparée
   que le lecteur ne rattache pas : la dépense reste à la maille IMMEUBLE, jamais ventilée.

Lecture seule.
"""
import io
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents   # noqa: E402
import p4b_certification as P4B   # noqa: E402

# ── Le dépôt de garantie : hors P7 dans les deux sens ───────────────────────────────────────
RE_DG = re.compile(r"d[ée]p[oô]t\s*(de\s*)?garantie|\bd\.?\s*g\.?\b|\bcaution\b|"
                   r"revers\w*\s*d\.?\s*g", re.I)
DG_VERS_PROP = re.compile(r"au\s+prop|propri[ée]taire|revers", re.I)
DG_VERS_LOC = re.compile(r"locataire|restitu|\bremb\w*\b", re.I)

# ── Les flux régie ↔ propriétaire : hors P7 également ───────────────────────────────────────
HORS_P7 = re.compile(
    r"loyers?\s+vers[ée]s?\s+au\s+propri[ée]taire|soldes?\s+mandats?|solde\s+de\s+votre\s+compte|"
    r"virement|r[èe]glement\s+virement|acompte\s+propri[ée]taire|remise\s+au\s+propri[ée]taire",
    re.I)

# ── P7B : la rémunération de la régie et les assurances ────────────────────────────────────
P7B_MOTIFS = [
    ('honoraires de gestion', r"honoraires?\s+de\s+gestion|honoraires?\s+h\.?t\.?\b|"
                              r"honoraires?\s+gestion"),
    ('TVA sur honoraires', r"tva\s*(sur|/)\s*(hono|frais)"),
    ('frais de gestion', r"frais\s+de\s+gestion|frais\s+sur\s+dr\b|"
                         r"honoraires?\s+revenus\s+fonciers"),
    ('autres honoraires', r"honoraires?\s+(de\s+)?(location|technique|administrat|diagnostic|"
                          r"relance|dossier|prestation|g[ée]om[èe]tre|expert|architecte|"
                          r"vacation)|frais\s+de\s+dossiers?"),
    ('garantie des loyers (GLI)', r"garantie\s+de[s]?\s+loyer|\bgli\b|"
                                  r"loyers?\s+impay[ée]s?\s+assur"),
    ('assurance PNO', r"assurance\s+pno|\bpno\b|propri[ée]taire\s+non\s+occupant"),
    ('autre assurance', r"assurance|multirisque"),
]

# ── P7A : ce que le propriétaire supporte sur le bien ──────────────────────────────────────
P7A_MOTIFS = [
    ('charges de copropriété / syndic',
     r"appels?\s+de\s+fonds?|charges?\s+(de\s+)?syndic|solde\s+de\s+charges|"
     r"fonds\s+de\s+travaux|fds\s+tvx|loi\s+alur|charges?\s+courant|budget\s+pr[ée]visionnel|"
     r"charges?\s+de\s+copropri[ée]t|r[ée]gularisation\s+copropri|fonds\s+de\s+roulement|"
     r"provisions?\s*/?\s*charges|avance\s+de\s+tr[ée]sorerie|appel\s+travaux|quote[- ]part|"
     r"par\s+(le\s+)?syndic|\bapf\b|amendement\s+budget"),
    ('travaux', r"travaux|\btrx\b|r[ée]novation|r[ée]fection|ma[çc]onnerie|peinture|toiture|"
                r"couverture|menuiserie|fen[êe]tre|volet|carrelage|isolation|ravalement"),
    ('taxe propriétaire', r"taxe\s+fonci[èe]re|imp[oô]t\s+foncier|taxe\s+d.habitation|"
                          r"taxe\s+bureaux|\bcfe\b|taxe\s+ordures|\bteom\b|taxe\s+balayage"),
    ('diagnostic', r"diagnostic|\bernt\b|\bdpe\b|amiante|plomb\b"),
    ('entretien / maintenance',
     r"entretien|maintenance|contrat\s+(entretien|chaudi|nettoyage|sba)|ramonage|"
     r"espaces\s+verts|nettoyage|chaudi[èe]re|ascenseur|d[ée]g[âa]ts\s+des\s+eaux|sinistre|"
     r"curage|d[ée]sinfection"),
    ('procédure / contentieux',
     r"huissier|avocat|commandement|proc[ée]dure|contentieux|article\s+700|greffe|"
     r"assignation|expulsion"),
    ('fluides', r"\bedf\b|\bgdf\b|engie|[ée]lectricit|eau\s+froide|conso\w*\s+eau|\bgaz\b"),
    ('facture fournisseur', r"^facture|facture\s+(n|du|au)\b|\bfact\.?\s*n|r[ée]paration|"
                            r"plomberie|serrurerie|d[ée]pannage|fourniture"),
    ('remboursement / avoir', r"rembours|\bavoir\b|restitution"),
]

# ── Familles résiduelles : le document nomme la FAMILLE sans dire la sous-nature ────────────
#    ⚠️ « Honoraires Annexes » démontre HONORAIRES. Ne pas le reconnaître le jetterait dans le
#       même sac que « Local 0013 », qui ne démontre rien du tout.
FAMILLES = [
    ('autres honoraires — sous-nature indéterminée', r"honoraire", 'P7B'),
    ('autre assurance — sous-nature indéterminée', r"assuranc", 'P7B'),
    ('charges de copropriété / syndic — sous-nature indéterminée', r"syndic|copropri", 'P7A'),
    ('facture fournisseur — sous-nature indéterminée', r"factur", 'P7A'),
    ('travaux — sous-nature indéterminée', r"chantier|ouvrage", 'P7A'),
]

P7B_MOTIFS = [(n, re.compile(p, re.I)) for n, p in P7B_MOTIFS]
P7A_MOTIFS = [(n, re.compile(p, re.I)) for n, p in P7A_MOTIFS]
FAMILLES = [(n, re.compile(p, re.I), c) for n, p, c in FAMILLES]

RE_ASSIETTE = re.compile(r'\(\*\)\s*([\d\s]*[.,]?\d*)\s*x\s*([\d.,]+)\s*%')
RE_TVA_TAUX = re.compile(r'\(\s*([\d.,]+)\s*%\s*\)')

# ── LA HIÉRARCHIE DE LECTURE — arbitrage Emmanuel du 31/08/2026 ────────────────────────────
#    ① STRUCTURE / SECTION IMPRIMÉE   démontre la FAMILLE
#    ② LIBELLÉ EXPLICITE DE LA LIGNE  précise la SOUS-NATURE, et l'emporte sur une rubrique
#                                     héritée ou périmée
#    ③ VOCABULAIRE / MOTIFS           enrichit, sans devenir une vérité documentaire
#    ④ CONTEXTE DU BLOC               quand une relation structurelle est démontrable
#    ⑤ INDÉTERMINABLE                 quand rien de documentaire ne va plus loin
#
# ⚠️ SECTION ≠ SOUS-NATURE. « - Dépenses déductibles - » démontre une CATÉGORIE COMPTABLE
#    IMPRIMÉE, pas une nature : ni travaux, ni entretien, ni taxe. Elle fait passer une ligne de
#    « rien de démontrable » à « famille démontrée, sous-nature inconnue ». « - Dépenses de
#    Syndic - », elle, démontre bien la copropriété.
#
# ⚠️ ET « - Dépenses Locatives - » NE DEVIENT JAMAIS UN APPEL LOCATAIRE P5A. C'est une dépense
#    présentée dans la partie dépenses du CRG, jusqu'à ce que le document démontre son rôle
#    exact. P5A est figée : rien de P7 ne la modifie.
SECTIONS = [
    # ⚠️ UNE SECTION PEUT AUSSI SORTIR LA LIGNE DE P7. « SOLDE MANDAT » chez EMERY est un titre
    #    de section, pas un libellé : les lignes qu'il surplombe sont des flux propriétaire.
    (r"solde\s+mandat", 'HORS P7 — flux régie ↔ propriétaire (P8), par section imprimée', 'hors'),
    (r"garantie\s+de\s+loyer", 'garantie des loyers (GLI) — section imprimée', 'P7B'),
    (r"d[ée]penses?\s+(de\s+)?(syndic|copro)|syndic|copropri",
     'charges de copropriété / syndic — section imprimée', 'P7A'),
    (r"d[ée]penses?\s+locatives?",
     'dépense locative — section imprimée, sous-nature indéterminée', 'P7A'),
    (r"honoraires?", 'honoraires — section imprimée, sous-nature indéterminée', 'P7B'),
    (r"d[ée]penses?\s+(d[ée]ductibles?|non\s+d[ée]ductibles?|diverses?|hors\s+gestion)|"
     r"d[ée]penses?", 'dépense du bien — section imprimée, sous-nature indéterminée', 'P7A'),
    (r"travaux", 'travaux — section imprimée', 'P7A'),
]
SECTIONS = [(re.compile(x, re.I), n, c) for x, n, c in SECTIONS]

INDEX_SECTIONS = {}
_f = r'C:\tmp\p7_sections.json'
if os.path.exists(_f):
    import json as _json
    INDEX_SECTIONS = _json.load(io.open(_f, encoding='utf-8'))


def section_de(sha, page, libelle, entete_):
    """La SECTION imprimée au-dessus de cette ligne — le titre de PARTIE du récapitulatif.

       ⚠️ SECTION \u2260 RUBRIQUE. « - Dépenses de Syndic - » est une section ; « Honoraires de
          gestion HT » ou « Taxe foncière » sont des rubriques, le niveau que le lecteur figé
          conserve déjà dans `entete`. Les confondre gonflerait le vocabulaire des sections
          d\'intitulés qui n\'en sont pas. L\'index en porte les deux ; on ne prend ici que la
          section, la rubrique étant déjà disponible."""
    for cle in (libelle, entete_):
        if not cle:
            continue
        v = INDEX_SECTIONS.get('%s|%s|%s' % (sha, page, str(cle)[:26]))
        if v:
            return v[0] if isinstance(v, list) else v
    return None


def famille_section(section):
    if not section:
        return None, None
    for motif, nom, cote in SECTIONS:
        if motif.search(section):
            return nom, cote
    return None, None


DG_LIBELLE = 'MOUVEMENT DÉPÔT DE GARANTIE — HORS P7'
INDETERMINABLE = 'INDÉTERMINABLE — ni rubrique ni libellé ne démontrent une famille'


def nombre(s):
    # ⚠️ EXCEPTION LÉGITIME ET MESURÉE : un champ peut ne pas être numérique, et None est traité
    #    en aval. Mais on ne capture que ce qu'une DONNÉE peut provoquer — pas `Exception`.
    try:
        return float(str(s).replace(' ', '').replace(',', '.'))
    except (ValueError, TypeError):
        return None


def sens_depot(libelle, entete):
    """Le sens du mouvement — et c'est le LIBELLÉ qui le dit, pas la rubrique.

       ⚠️ « Reversement D.G. au prop. » suivi de « M. LEMOINE remb DG locataire » : la rubrique
          dit propriétaire, la ligne dit locataire. C'est la ligne qui décrit le mouvement."""
    if re.search(r"locataire", libelle, re.I):
        return 'vers le locataire'
    if re.search(r"au\s+prop|propri[ée]taire", libelle, re.I):
        return 'vers le propriétaire'
    if DG_VERS_PROP.search(entete):
        return 'vers le propriétaire'
    if DG_VERS_LOC.search(entete):
        return 'vers le locataire'
    return 'sens indéterminé'


def _famille_nommee(texte, motifs):
    for nom, motif in motifs:
        if motif.search(texte):
            return nom
    return None


def nature(entete, libelle, section=None):
    """(nature, côté). Le côté vaut P7A · P7B · dg · hors · a_qualifier.

       ⚠️ L'ORDRE EST LA RÈGLE. ① un dépôt de garantie NOMMÉ DANS LE LIBELLÉ sort toujours
          ② une rubrique périmée ne l'emporte jamais sur un libellé qui se nomme lui-même
          ③ P7B avant P7A, sans quoi toute la rémunération de la régie tomberait dans les
          charges d'entretien ④ à défaut, la FAMILLE seule, sous-nature déclarée inconnue."""
    ent, lib = entete or '', libelle or ''
    paire = '%s %s' % (ent, lib)

    # ① le dépôt de garantie, nommé par le libellé : hors P7, quel que soit son sens
    if RE_DG.search(lib):
        return '%s (%s)' % (DG_LIBELLE, sens_depot(lib, ent)), 'dg'

    # ② rubrique de dépôt de garantie : le libellé peut nommer sa propre famille et l'emporter
    if RE_DG.search(ent):
        n = _famille_nommee(lib, P7B_MOTIFS)
        if n:
            return n, 'P7B'
        n = _famille_nommee(lib, P7A_MOTIFS)
        if n:
            return n, 'P7A'
        return '%s (%s)' % (DG_LIBELLE, sens_depot(lib, ent)), 'dg'

    if HORS_P7.search(paire):
        return 'HORS P7 — flux régie ↔ propriétaire (P8)', 'hors'

    # ③ LE LIBELLÉ SEUL, quand il nomme explicitement sa sous-nature : il l'emporte sur une
    #    rubrique héritée, et c'est une règle générale, pas un correctif de ligne.
    n = _famille_nommee(lib, P7B_MOTIFS)
    if n:
        return n, 'P7B'
    n = _famille_nommee(lib, P7A_MOTIFS)
    if n:
        return n, 'P7A'

    # ④ la paire rubrique + libellé
    n = _famille_nommee(paire, P7B_MOTIFS)
    if n:
        return n, 'P7B'
    n = _famille_nommee(paire, P7A_MOTIFS)
    if n:
        return n, 'P7A'

    # ⑤ LA SECTION IMPRIMÉE — la structure démontre la famille quand aucun mot ne la nomme
    n, cote = famille_section(section)
    if n:
        return n, cote

    # ⑥ le vocabulaire résiduel
    for nom, motif, cote in FAMILLES:
        if motif.search(paire):
            return nom, cote
    return INDETERMINABLE, 'a_qualifier'


# ── RECTIFICATION P7 DU 31/08/2026 — RÉÉDITIONS ET EXHAUSTIVITÉ ────────────────────────────
# ⚠️ UNE PIÈCE GED N'EST PAS UN ÉVÉNEMENT MÉTIER. `SABY 0107-2.pdf` et `FOCH 0108.pdf` énoncent
#    la même situation de gestion que leur jumelle au même arrêté : même compte, même report,
#    83 % d'écritures identiques, et des écarts qui ne portent que sur la formulation de la
#    rubrique (`Honoraires H.T. Juin 2026` ⟷ `Honoraires H.T.`). Les lire toutes les deux
#    comptait 32 145,84 € deux fois.
#
# ⚠️ LES DEUX PDF RESTENT EN GED — décision Emmanuel. On ne supprime aucune pièce : c'est le
#    LECTEUR qui ne lit qu'une fois la situation. `sha` reste l'identité du document, jamais
#    celle de l'écriture métier.
REEDITIONS = {'SABY 0107-2.pdf', 'FOCH 0108.pdf'}

# ⚠️ ET LE CONTENEUR NE DÉFINIT PAS LE PÉRIMÈTRE MÉTIER. P7 ne lisait que `immeubles[].charges`
#    et `immeubles[].mouvements` ; le compte courant du mandant portait des charges réelles —
#    impôts, procédures, fluides, syndic, honoraires, assurances — qu'aucune règle n'excluait,
#    seulement l'ignorance d'une structure. Elles entrent ici, avec leur maille propre.
def _charges_du_mandat(d, base):
    """Les charges imprimées dans le compte courant du mandant."""
    import p7_routage_exhaustif as ROUT   # noqa: E402  (import tardif : dépendance croisée)
    for o in (d.get('mandat') or {}).get('operations') or []:
        x = {'agence': base['agence'], 'compte': base['compte'],
             'entete': str(o.get('entete') or ''), 'libelle': str(o.get('libelle') or ''),
             'debit': round(float(o.get('debit') or 0), 2),
             'credit': round(float(o.get('credit') or 0), 2),
             'periode': base['periode_crg'], 'crg': base['crg_fichier'], 'page': o.get('page')}
        dest, sous = ROUT.destination(x)
        if dest not in ('CHARGE → P7A', 'CHARGE → P7B'):
            continue
        yield dict(base, immeuble='', lot='', maille_documentaire='compte mandant',
                   date_source=None, entete_source=x['entete'], libelle_brut=x['libelle'],
                   montant_source=x['debit'] or x['credit'], credit=x['credit'],
                   nature=str(sous or 'sous-nature indéterminée'),
                   cote='P7A' if dest.endswith('P7A') else 'P7B',
                   section_imprimee=None, y=None if o.get('y') is None else round(float(o['y']), 1),
                   assiette=None, taux=None, taux_tva=None, tva=nombre(o.get('tva')),
                   page=o.get('page'), fournisseur_source=None, reference_source=None,
                   supporte_par='propriétaire')


def lignes(agence):
    """Chaque ligne de charge imprimée, avec sa provenance et sa maille documentaire."""
    for d in documents(agence):
        if d.get('_nom_original') in REEDITIONS:
            continue
        m = d.get('meta') or {}
        fm = d.get('_format')
        base = {'agence': agence, 'format': fm, 'crg_fichier': d.get('_nom_original'),
                'sha': d.get('_sha'), 'periode_crg': m.get('periode_cle'),
                'date_arrete': P4B.jour(m.get('date_arrete')),
                'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                'compte': str(m.get('compte') or ''), 'niveau_source': 'lu'}
        for x in _charges_du_mandat(d, base):
            yield x
        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            if fm == 'septeo_spi':
                for mv in im.get('mouvements') or []:
                    lib = str(mv.get('libelle') or '')
                    sec = mv.get('section')
                    nat, cote = nature(sec, lib, sec)
                    lot = str(mv.get('ref_lot') or '').strip().rstrip('-')
                    yield dict(base, immeuble=code, lot=lot,
                               maille_documentaire='lot' if lot else 'immeuble',
                               date_source=P4B.jour(mv.get('date')),
                               entete_source=mv.get('section'), libelle_brut=lib,
                               montant_source=round(float(mv.get('debit') or 0), 2),
                               credit=round(float(mv.get('credit') or 0), 2),
                               nature=nat, cote=cote, section_imprimee=sec,
                               # ⚠️ LE LECTEUR SEPTEO NE CONSERVE PAS L'ORDONNÉE. On l'écrit
                               #    donc à None, explicitement : deux lignes identiques sur la
                               #    même page y resteront INDÉTERMINÉES plutôt que d'être
                               #    fusionnées d'office. Une lacune connue vaut mieux qu'un
                               #    chiffre exact obtenu par oubli.
                               y=None,
                               assiette=nombre(mv.get('base')), taux=nombre(mv.get('taux')),
                               taux_tva=None, tva=nombre(mv.get('tva')), page=mv.get('page'),
                               fournisseur_source=None, reference_source=None,
                               supporte_par='propriétaire')
            else:
                for ch in im.get('charges') or []:
                    ent, lib = str(ch.get('entete') or ''), str(ch.get('libelle') or '')
                    sec = section_de(d.get('_sha'), ch.get('page'), lib, ent)
                    nat, cote = nature(ent, lib, sec)
                    a = RE_ASSIETTE.search(ent)
                    t = RE_TVA_TAUX.search(lib)
                    yield dict(base, immeuble=code, lot='',
                               maille_documentaire='immeuble',
                               date_source=None, entete_source=ent, libelle_brut=lib,
                               montant_source=round(float(ch.get('debit') or 0), 2),
                               credit=round(float(ch.get('credit') or 0), 2),
                               nature=nat, cote=cote, section_imprimee=sec,
                               y=None if ch.get('y') is None else round(float(ch['y']), 1),
                               # ⚠️ DEUX TAUX DIFFÉRENTS, JAMAIS LE MÊME CHAMP : « (*)920.00 x
                               #    4.00 % » donne le taux d'HONORAIRES et son assiette,
                               #    « ( 20.00 % ) » celui de la TVA.
                               assiette=nombre(a.group(1)) if a else None,
                               taux=nombre(a.group(2)) if a else None,
                               taux_tva=nombre(t.group(1)) if t else None,
                               tva=nombre(ch.get('tva')), page=ch.get('page'),
                               fournisseur_source=None, reference_source=None,
                               supporte_par='propriétaire')


CHAINE = ('crg_fichier', 'sha', 'page', 'periode_crg', 'date_arrete', 'proprietaire', 'compte',
          'immeuble', 'maille_documentaire', 'libelle_brut', 'nature', 'supporte_par',
          'niveau_source')


def cle_evenement(x):
    """L'identité d'un ÉVÉNEMENT — c'est-à-dire d'une LIGNE IMPRIMÉE.

       ⚠️ RECTIFICATION DE RÈGLE FIGÉE — décision Emmanuel du 31/08/2026, `P7A-DEPENSE-11/12`.
          L'ancienne clé — `agence · compte · immeuble · rubrique · libellé · montant · date` —
          tenait pour UNE dépense réénoncée deux lignes qui se ressemblaient. Mesuré sur le
          corpus : sur 195 473,48 € ainsi retirés de la borne basse, **195 317,76 €** étaient
          des lignes réellement imprimées ailleurs — le plus souvent dans des CRG de PÉRIODES
          DIFFÉRENTES. `TVA/Honoraires 6,91 €` apparaissait quatre fois : deux lignes au T1,
          deux au T2. Quatre honoraires trimestriels, pas un seul réénoncé.

       ⚠️ LE PRINCIPE S'INVERSE. Une RESSEMBLANCE DOCUMENTAIRE N'EST PAS UNE PREUVE DE DOUBLON :
          chaque occurrence est un événement distinct **sauf preuve positive du contraire**.
          L'absence de différence démontrable ne prouve rien.

       ⚠️ ET `y` N'EST PAS UNE IDENTITÉ MÉTIER. Ce qui distingue deux événements, c'est d'abord
          la PÉRIODE du CRG, puis la séquence documentaire ; la position ne fait que corroborer
          que deux lignes sont bien imprimées. Elle entre dans la clé à ce seul titre — comme
          preuve d'existence, jamais comme sens."""
    return (x['agence'], x['sha'], x['page'], x.get('y'), x['entete_source'],
            x['libelle_brut'], x['montant_source'], x['date_source'])


def bases(lst):
    """Les bases de calcul, jamais confondues.

       OCCURRENCES   toutes les lignes imprimées
       ÉVÉNEMENTS    les lignes distinctes du document — l'unité métier
       INDÉTERMINÉES les occurrences que le document ne permet pas de départager : le lecteur
                     n'a pas conservé leur position, on ne peut donc pas dire si c'est une
                     ligne lue deux fois ou deux lignes identiques.

       ⚠️ ON NE FABRIQUE PAS UN TOTAL EXACT POUR SUPPRIMER UNE INCERTITUDE. Tant qu'il reste une
          occurrence indéterminée, le total reste une FOURCHETTE — fût-elle de 155,72 €."""
    par = {}
    for x in lst:
        par.setdefault(cle_evenement(x), []).append(x)
    basse = haute = 0.0
    indeterminees = 0
    for cle, v in par.items():
        basse += v[0]['montant_source']
        if len(v) == 1:
            haute += v[0]['montant_source']
        elif cle[3] is None:
            # position non conservée : une ligne lue deux fois, ou deux lignes ? indécidable.
            indeterminees += len(v) - 1
            haute += sum(x['montant_source'] for x in v)
        else:
            # même document, même page, même position : c'est la MÊME ligne imprimée.
            haute += v[0]['montant_source']
    return {
        'occurrences': len(lst),
        'evenements': len(par),
        'indeterminees': indeterminees,
        'total_basse': round(basse, 2),
        'total_haute': round(haute, 2),
    }
