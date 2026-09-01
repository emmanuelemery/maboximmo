# -*- coding: utf-8 -*-
"""
SOCLE P8A — LES FLUX ENTRE LA RÉGIE ET LE PROPRIÉTAIRE, LUS COMME LE DOCUMENT LES IMPRIME.

⚠️ ON NE PART PAS DE CE QUE P7 A MIS DE CÔTÉ. P7 ne lisait qu'un seul conteneur du pivot,
   `immeubles[].charges` : ses 332 439,22 € « hors P7 » sont ce qui a débordé dans le bloc des
   charges, pas l'inventaire des flux propriétaire. Le vrai gisement du format `lyon` est
   `mandat.operations` — le COMPTE COURANT DU MANDANT — que P7 n'a jamais ouvert. Les deux
   conteneurs ne partagent AUCUNE ligne : contrôle fait, 0 clé commune sur les trois périmètres.

⚠️ ET LE NOM D'UN CHAMP DU LECTEUR N'EST PAS UNE PREUVE DOCUMENTAIRE. Deux lecteurs différents
   ont baptisé « flux » ce que le PDF imprime comme un STOCK :
     · `immeubles[].reversements` (GROUPE SIR) ne contient que « Solde au 30.06.2026 » ;
     · `virement` (VIENNE) vaut le solde du compte dans 58 cas sur 60, et sa provenance déclarée
       est « ligne Solde de votre compte ».
   Se fier au nom du champ aurait certifié des soldes en virements. On lit le LIBELLÉ.

⚠️ LE COMPTE MANDANT N'EST PAS UN COMPTE DE REVERSEMENT. Chez GROUPE SIR il porte aussi des
   impôts, ENGIE, des salaires, des honoraires d'avocat : des DÉPENSES payées depuis le compte
   du mandant. Elles ne sont ni P7 (qui n'a pas lu ce conteneur) ni P8A. Elles sont nommées.

⚠️ LE SENS SE LIT SUR LE LIBELLÉ, JAMAIS SUR LE SIGNE. Mesuré : « Règlement virement/chèque »
   = 577 lignes, 577 débits, 0 crédit ; « votre » = 8 lignes, 0 débit, 8 crédits. Le signe
   CONFIRME la lecture sans exception, il ne la fonde pas.

⚠️ ET UN LOYER « DIRECT IMPOTS » N'EST PAS UN REVERSEMENT. « (ATD) », « DIRECT IMPOTS » (avis à
   tiers détenteur), « DIRECT PROPRIETAIRE », « payée par vous » : l'argent n'a jamais transité
   par la régie, ou il est allé au Trésor. Les compter en reversement au propriétaire ferait
   dire que le propriétaire a reçu ce qui lui a été saisi.

Lecture seule. Aucune écriture MBI, aucune PROD.
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _socle import documents   # noqa: E402
import p4b_certification as P4B   # noqa: E402
import p7_socle as P7   # noqa: E402

RE_DATE = re.compile(r'(\d{1,2})[./](\d{1,2})[./](\d{2,4})')

# ── Le vocabulaire imprimé ─────────────────────────────────────────────────────────────────
# ⚠️ DEUX « DIRECT » QUI N'ONT RIEN À VOIR. « DIRECT IMPÔTS » / « (ATD) » : le loyer a été SAISI
#    par le Trésor — le propriétaire n'a rien reçu. « DIRECT PROPRIÉTAIRE » : le locataire a payé
#    le propriétaire de la main à la main — il a tout reçu, mais pas par la régie. Les ranger
#    ensemble ferait dire au CRG l'inverse de ce qu'il imprime dans un cas sur deux.
#
# ⚠️ ET « DIRECT PRO » EST LA MÊME CHOSE QUE « DIRECT PROPRIÉTAIRE ». Le corpus imprime les deux
#    (5 fois abrégé, 1 fois en toutes lettres) sur le même compte et le même locataire. Ne
#    reconnaître que la forme longue classait cinq lignes en indéterminables.
ATD = re.compile(r"direct\s+(aux\s+)?imp[oô]ts|\(\s*atd\s*\)|\batd\b|"
                 r"vers[ée]\s+direct\s+imp", re.I)
DIRECT_PROP = re.compile(r"direct\s+pro(pri[ée]taire)?\b", re.I)

# ⚠️ « PAYÉE PAR VOUS » EST L'INVERSE DE « DIRECT PROPRIÉTAIRE ». « LOYER BOUGHALEM DIRECT
#    PROPRIETAIRE » : le locataire a payé le propriétaire. « CITYA APF 1er trim 2026 payé par
#    vous » : le propriétaire a payé la copropriété. Le même mot « direct » recouvre deux sens
#    opposés — l'un fait entrer l'argent chez le propriétaire, l'autre l'en fait sortir.
PAYE_PAR_VOUS = re.compile(r"pay[ée]e?\s+par\s+vous", re.I)

# ⚠️ UN LIBELLÉ QUI NOMME UNE PLAGE DE PÉRIODES EST UN AGRÉGAT. « LOYERS DIRECT IMPOTS 4T22 A
#    3T24 » porte 193 225,04 € pour huit trimestres sur UNE ligne : ce n'est pas un mouvement
#    élémentaire, et le rattacher à la période du CRG serait faux.
PLAGE = re.compile(r"\d\s*t\s*\d{2}\s*(?:a|à|-|/)\s*\d\s*t\s*\d{2}|"
                   r"\d{2}/\d{2}\s*-\s*\d{2}/\d{2}|\(\s*\d{2}\s*-\s*\d{2}/\d{4}\s*\)", re.I)
REJET = re.compile(r"^\s*rejet\b|rejet\s+virement|cpte\s+cl[oô]tur", re.I)

# ⚠️ « MLV » = MAINLEVÉE — arbitrage Emmanuel du 31/08/2026 : « mainlevée sur la saisie huissier
#    sur le compte : l'huissier nous rend de l'argent qu'il a pris avant ». `VIRT MLV TOTALE
#    SAISIE` 222 678,22 € au crédit n'est donc ni un acompte, ni un apport du propriétaire :
#    c'est la RESTITUTION d'une somme saisie. Elle entre en trésorerie sans être un flux
#    régie ↔ propriétaire, et n'a pas de contrepartie à compenser.
MAINLEVEE = re.compile(r"\bmlv\b|mainlev[ée]e?", re.I)
# ⚠️ « TRANSFERT EST UNE COMPENSATION COMME LES AUTRES » — arbitrage Emmanuel, confirmé le
#    31/08/2026 sur `transfert GPE IMMO vers GPE SIR (1104)` (30 000 €) : aucun fonds ne bouge.
#    Le motif vient APRÈS celui du compte d'attente : « TRANSFER CPT ATTENTE » est une mise en
#    compte d'attente, pas une compensation — encore le mot de l'objet contre la nature.
COMPENS = re.compile(r"compensation|compensat\.|extourne|transfert?\b|transfer\b|"
                     r"solde\s+d[ée]biteur\s+m(an)?d(a)?t", re.I)

# ⚠️ « FINANCEMENT POUR SIR D'UNE AUTRE SCI QUE NOUS NE GÉRONS PAS » — arbitrage Emmanuel.
#    Une écriture qui ne nomme QU'UNE FORME SOCIALE, sans charge ni bénéficiaire qualifiés, est
#    un financement engagé pour le compte du propriétaire : la régie n'aurait pas à le faire,
#    comme pour les échéances de prêt. Testé en DERNIER, juste avant l'indéterminable : toute
#    qualification démontrée l'emporte sur cette lecture de repli.
ENTITE = re.compile(r"\b(sci|sarl|sas|sasu|holding|snc)\b", re.I)
STOCK = re.compile(r"^\s*solde\s+au\b|solde\s+de\s+votre\s+compte|"
                   r"solde\s+du\s+dernier\s+rapport|^\s*solde\s+final", re.I)
VOTRE = re.compile(r"\bvotre\b", re.I)
REGLT = re.compile(r"^\s*r[èe]glement\s+(virement|ch[èe]que|cheque)", re.I)
DG = re.compile(r"\bd\.?\s*g\.?\b|d[ée]p[oô]t\s*(de\s*)?garantie|revers\w*\s*d\.?\s*g", re.I)
VERS_LOC = re.compile(r"locataire", re.I)
LOYERS_P = re.compile(r"loyers?\s+vers[ée]s?\s+au\s+propri[ée]taire", re.I)
ACOMPTE = re.compile(r"acompte\s*/?\s*loyers?|acompte\s+propri[ée]taire|"
                     r"remise\s+au\s+propri[ée]taire", re.I)

# ⚠️ « ACOMPTE PRO » PORTE SA NATURE, PAS SON SENS — arbitrage Emmanuel. Le corpus démontre
#    `PRO` = `PROP` = PROPRIÉTAIRE : sur le compte 01040000, les rubriques `Acompte prop au
#    17.06.2026` et `Acompte pro BQ POP` coexistent, et `prop.` = propriétaire est déjà du
#    vocabulaire P7 certifié (`Reversement D.G. au prop.`).
#
# ⚠️ MAIS `ACOMPTE PROPRIÉTAIRE ≠ VERSEMENT AU PROPRIÉTAIRE`. Hors de la section « Soldes
#    mandats », le bénéficiaire bancaire n'est pas démontré : `ACOMPTE PRO - SIR/BPAURA` nomme
#    une banque, pas un destinataire. La NATURE est donc nommée et le SENS reste indéterminable
#    — la ligne n'entre pas dans le total des flux. Nommer sans conclure vaut mieux que ranger
#    en « ni sens ni bénéficiaire démontrés » une écriture dont on connaît la nature.
ACOMPTE_PRO = re.compile(r"ac(?:om)?pte\s+prop?\b", re.I)
SECT_MDT = re.compile(r"soldes?\s+mandats?|soldes?\s+immeubles?", re.I)
VIDE = re.compile(r"^[\s/.-]*$")

# ── ARBITRAGE EMMANUEL DU 31/08/2026 ───────────────────────────────────────────────────────
# ⚠️ « TOUS LES SOLDES MANDAT SONT DES ACOMPTES PRO. » La section imprimée « Soldes mandats »
#    démontre la famille : la régie avance de la trésorerie au propriétaire sans attendre le
#    CRG. Le vocabulaire ne suffisait pas — « ACOMPTE PRO », « VRT SIR/BPAURA » et
#    « virement SABY A CARRIER PICHIT SANDRA » sont la même chose sans partager un seul mot.
#
# ⚠️ MAIS UN LIBELLÉ QUI SE NOMME LUI-MÊME L'EMPORTE TOUJOURS — c'est la hiérarchie certifiée en
#    P7. Une taxe, une assurance ou un fournisseur nommés sous « Soldes mandats » restent ce que
#    leur libellé dit : sinon la section avalerait les dépenses, et « acompte propriétaire »
#    deviendrait le fourre-tout que cette phase cherche justement à éviter.
SECT_ACOMPTE = re.compile(r"soldes?\s+mandats?", re.I)

# ⚠️ « TOUT REMBOURSEMENT DE PRÊT EST COMME UN VERSEMENT DIRECT AU PROPRIÉTAIRE, car nous
#    n'aurions pas à le faire normalement. » La régie paie l'échéance à la place du mandant :
#    c'est de la trésorerie qui sort pour lui. Ce n'est toujours PAS une dépense P7A — la
#    décision d'exclusion du 31/08 tient — mais c'en est un flux propriétaire.
PRET = re.compile(r"\bpr[êe]ts?\b|emprunt|[ée]ch[ée]ance\s+pr|rembt\s+pr[êe]t", re.I)

# ⚠️ « NOUS PAYONS DIRECTEMENT À SA PLACE CAR IL NE VEUT PAS FAIRE LE VIREMENT LUI-MÊME » —
#    arbitrage Emmanuel du 31/08/2026. Un salaire réglé par la régie pour le compte du mandant
#    est un ACOMPTE PROPRIÉTAIRE, pas une charge du bien : il ne rejoint donc JAMAIS P7A.
#    Le document le confirmait déjà : sur les 12 écritures du corpus, aucun organisme social,
#    aucune date, aucun rattachement à un immeuble, et deux virements groupant plusieurs
#    bulletins (« 5 X SALAIRES JUIN »). C'est un règlement de trésorerie, pas une paie.
#
# ⚠️ ET LA QUESTION « SALARIÉ OU PRESTATAIRE ? » DEVIENT SANS OBJET. Le bloc `VANEL NOUBOUWO
#    EI … VANEL CONSEILS n°202601` hésitait entre salaire et facture : dans les deux cas c'est
#    une obligation du mandant réglée à sa place. La règle tranche sans avoir à trancher cela.
#
# ⚠️ `\bpaye\b` EST EXCLU À DESSEIN : « REMB TROP PAYE ASSURANCE » n'est pas une paie. Le filet
#    large de la passe d'analyse avait ramené deux faux amis ; la règle, elle, reste étroite.
SALAIRE = re.compile(r"\bsalaires?\b|\bpaies?\b|bulletin\s+de\s+paie", re.I)

# ⚠️ ET LE MOT « SALAIRE » NE FAIT PAS UNE PAIE — correction Emmanuel du 31/08/2026.
#    « VANEL NOUBOUWO EI … Salaire Avril 2026 » est une PRESTATION COMPTABLE EXTERNE, une
#    facture. J'avais rangé les six lignes en salaire parce que le libellé le disait : c'était
#    l'erreur exacte que la doctrine interdit — prendre un mot pour une nature.
#    Ce qui démasque le prestataire : la rubrique « Prestation … », la forme juridique (« EI »),
#    une raison sociale de conseil, un numéro de facture, ou le nom de la régie elle-même —
#    « tout ce qui est classé dans honoraires, ce sont des prestations facturées par la RÉGIE
#    EMERY » (Emmanuel). Un vrai salaire ne porte aucune de ces marques.
# ⚠️ « RÉGIE EMERY » SEULEMENT, JAMAIS « LOCA IMMO » SEUL : `VRT LOCA IMMO HOLDING` désigne la
#    holding du propriétaire, pas la régie. Élargir le motif aurait transformé un apport en
#    compte courant en facture de prestation.
PRESTATAIRE = re.compile(r"prestation|\bei\b|conseils?\b|n[°o]\s*\d|r[ée]gie\s+emery", re.I)

# ⚠️ « RÉGIE EMERY DANS UN LIBELLÉ ≠ AUTOMATIQUEMENT HONORAIRE » — Emmanuel. Le premier motif
#    ramassait trois lignes qui n'en sont pas : `VIR REGIE EMERY BP de Loca Immo Gestion`
#    (25 000 €), `VIRT REGIE EMERY Récup. trésorerie` (30 000 €) et `REGIE EMERY-SDC 288 CRS
#    ZOLA` (712,32 € au crédit). Un virement VERS la régie et le SYNDIC « RÉGIE EMERY-SDC » ne
#    sont pas des prestations facturées PAR la régie.
#
# ⚠️ CE QUI FAIT LA PREUVE, C'EST LA COMBINAISON : la régie nommée EN TÊTE du libellé, comme
#    fournisseur — jamais précédée d'un verbe de virement — et suivie d'un service.
REGIE_FACTURE = re.compile(r"^\s*r[ée]gie\s+emery\s+loca[- ]immo\b", re.I)
REGIE_NON = re.compile(r"\bvir\w*\s+r[ée]gie|r[ée]gie\s+emery\s*-\s*sdc", re.I)

# ⚠️ « UNE ÉCRITURE INTERNE QUI NOUS PERMET DE METTRE DE L'ARGENT EN COMPTE D'ATTENTE : C'EST
#    UNE SORTIE DE TRÉSORERIE » — Emmanuel. L'argent quitte le compte du mandant sans aller au
#    propriétaire et sans payer de charge : ni P7A, ni P7B, ni reversement. Rare — 4 occurrences
#    sur les 458 CRG, 56 000,00 € — mais présentes sur EMERY comme sur SIR, comme annoncé.
#
# ⚠️ ET ELLE PASSE AVANT L'ATD, POUR LA MÊME RAISON QUE LA RÉGIE. « CPTE ATTENTE ATD pour TF et
#    IMPOTS SIR » contient « ATD » : le motif d'ATD s'en emparait et en faisait un loyer saisi
#    de 35 000 €. Un compte d'attente CONSTITUÉ POUR des ATD n'est pas un ATD. Troisième fois
#    que le mot de l'OBJET est lu comme la NATURE — la règle d'ordre est désormais systématique.
#
# ⚠️ LIMITE CONSIGNÉE : « réserve » et « attente » ne désignent rien d'autre dans ce corpus
#    (vérifié : 4 occurrences, toutes de cette nature). Un « fonds de réserve » de copropriété
#    futur devrait être requalifié — il serait pris ici à tort.
ATTENTE = re.compile(r"\br[ée]serve\b|\battente\b", re.I)

# ⚠️ NOMMÉES, PAS ABSORBÉES. Ces dépenses transitent par le compte mandant ; les taire les
#    ferait disparaître de toute phase.
DEPENSE = re.compile(r"\bsip\b|imp[oô]t|taxe\s+fonci|\btf\s*20|engie|gdf|\bedf\b|"
                     r"pr[êe]t\b|honoraires?|salaire|avocat|assurance|fournisseurs?\s+divers|"
                     r"prestation|syndic|facture|\bfact\b|travaux", re.I)

STOCK_P8B = 'STOCK PROPRIÉTAIRE — renvoyé en P8B'
HORS = 'DÉPENSE PAYÉE DEPUIS LE COMPTE MANDANT — hors P8A'
INDET = 'INDÉTERMINABLE — ni sens ni bénéficiaire démontrés'


def ligne_sans_ecriture(entete, libelle, debit, credit):
    """⚠️ UNE LIGNE VIDE N'EST PAS UNE ÉCRITURE INDÉTERMINABLE — Emmanuel : « il y a 2 lignes,
       le nom de l'immeuble du progiciel et l'adresse de l'immeuble, d'où une ligne vide ».
       Mon premier test exigeait que la RUBRIQUE soit vide elle aussi : or c'est justement elle
       qui porte l'adresse. 28 lignes à 0,00 € gonflaient les indéterminables — un résiduel
       inventé par le test, pas par le document. Ce qui fait une non-écriture, c'est un libellé
       vide ET aucun montant."""
    return (VIDE.match(str(libelle or '')) is not None
            and not float(debit or 0) and not float(credit or 0))


def famille(entete, libelle):
    """(famille, sens, origine). L'ORDRE EST LA RÈGLE, et c'est celle de P7 : un libellé qui se
       nomme lui-même l'emporte toujours sur une rubrique héritée ou périmée.

       `origine` dit SUR QUOI le sens a été lu — « libellé » ou « rubrique ». C'est ce qui
       permet ensuite de ne faire confiance au signe que là où il peut trancher.

       (1) un STOCK reste un stock, où qu'il soit imprimé  (2) un rejet annule le mouvement
       (3) une saisie par le Trésor n'est pas un versement (4) un paiement direct au
       propriétaire n'est pas un versement de la régie     (5) une compensation n'est pas un
       virement   (6) le dépôt de garantie porte son sens  (7) « votre » = le propriétaire
       verse      (8) « Règlement » = la régie verse       (9) à défaut, la rubrique."""
    e, l = str(entete or ''), str(libelle or '')
    # ⚠️ UNE LIGNE VIDE N'EST PAS UNE ÉCRITURE INDÉTERMINABLE. C'est l'adresse d'un immeuble
    #    imprimée sur une seconde ligne — Emmanuel l'a confirmé. La ranger avec les écritures
    #    dont on ignore le sens gonflait les indéterminables de 29 lignes à 0,00 €.
    if VIDE.match(l) and VIDE.match(e):
        return 'LIGNE VIDE — adresse d’immeuble, aucune écriture', 'SANS_ECRITURE', 'aucune'
    if STOCK.search(l):
        return STOCK_P8B, 'sans objet (stock)', 'libellé'
    if MAINLEVEE.search(l):
        return ('RESTITUTION DE SAISIE (mainlevée) — entrée de trésorerie, pas un flux '
                'propriétaire', 'RESTITUTION_SAISIE', 'libellé')
    # ⚠️ « ANNULE UN FLUX DÉJÀ COMPTÉ AILLEURS » — arbitrage Emmanuel. Un virement rejeté prouve
    #    que le mouvement d'origine N'A PAS EU LIEU : `OCCURRENCE DOCUMENTAIRE ≠ MOUVEMENT
    #    BANCAIRE`. Mais le rejet n'imprime aucune référence au virement qu'il annule — on ne
    #    peut donc pas le retrancher sans deviner lequel. Le rejet reste dehors, et la
    #    conséquence est CONSIGNÉE plutôt que compensée : un netting à l'aveugle serait pire
    #    qu'une incertitude nommée.
    if REJET.search(l) or REJET.search(e):
        return ('REJET DE VIREMENT — annule un flux compté ailleurs, non rapprochable',
                'REJET_NON_RAPPROCHE', 'libellé')
    if ATTENTE.search(l) or ATTENTE.search(e):
        return ('MISE EN COMPTE D’ATTENTE / RÉSERVE — écriture interne, sortie de trésorerie',
                'SORTIE_VERS_COMPTE_ATTENTE', 'libellé' if ATTENTE.search(l) else 'rubrique')

    # ⚠️ LA RÉGIE NOMMÉE EN FOURNISSEUR PASSE AVANT TOUT MOT-CLÉ CONTENU DANS SA PRESTATION.
    #    Défaut mesuré : « REGIE EMERY LOCA-IMMO GESTION DES PRETS » était pris pour un
    #    remboursement de prêt, « HONO GESTION ATD depuis 2023 » et « GESTION ATD/CONTENTIEUX »
    #    pour des loyers saisis. Quatre honoraires — 16 300,00 € — classés en flux propriétaire
    #    parce qu'un mot de leur OBJET avait été lu comme leur NATURE. Des honoraires de gestion
    #    des ATD ne sont pas un ATD ; des honoraires de gestion des prêts ne sont pas un prêt.
    #    C'est la hiérarchie certifiée en P7 : le libellé qui se nomme lui-même l'emporte.
    if REGIE_FACTURE.search(l) and not REGIE_NON.search(l):
        return ('PRESTATION FACTURÉE PAR LA RÉGIE — hors P8A, rattachement P7B à arbitrer',
                'sans objet (dépense)', 'libellé')

    # ⚠️ ARBITRAGE EMMANUEL — LE LOYER SAISI EST REVERSÉ AU PROPRIÉTAIRE, INDIRECTEMENT.
    #    « Le loyer est versé au propriétaire et aussitôt donné en acompte pro, sans tenir
    #    compte de la saisie effective. » Le locataire a bien payé, la dette du propriétaire a
    #    bien diminué : ce n'est pas de la trésorerie, mais ce n'est pas non plus « il n'a rien
    #    reçu », comme je l'avais écrit. Catégorie distincte et NOMMÉE, parce qu'elle sert aux
    #    validations de dossiers contentieux.
    if ATD.search(l):
        return ('LOYER SAISI — REVERSÉ AU PROPRIÉTAIRE INDIRECTEMENT (contentieux)',
                'REGIE_VERS_PROPRIETAIRE_INDIRECT', 'libellé')
    if PAYE_PAR_VOUS.search(l):
        return ('DÉPENSE PAYÉE DIRECTEMENT PAR LE PROPRIÉTAIRE — hors compte de gestion',
                'PROPRIETAIRE_VERS_TIERS', 'libellé')
    if DIRECT_PROP.search(l):
        return ('PAYÉ DIRECTEMENT AU PROPRIÉTAIRE — n’a jamais transité par la régie',
                'TIERS_VERS_PROPRIETAIRE', 'libellé')
    if PRET.search(l) or PRET.search(e):
        return ('REMBOURSEMENT DE PRÊT PAYÉ POUR LE PROPRIÉTAIRE — jamais une dépense P7A',
                'REGIE_VERS_PROPRIETAIRE', 'libellé' if PRET.search(l) else 'rubrique')
    if (SALAIRE.search(l) or SALAIRE.search(e)) \
            and not (PRESTATAIRE.search(l) or PRESTATAIRE.search(e)):
        return ('SALAIRE PAYÉ À LA PLACE DU PROPRIÉTAIRE — acompte propriétaire',
                'REGIE_VERS_PROPRIETAIRE', 'libellé' if SALAIRE.search(l) else 'rubrique')
    # ⚠️ « UNE ÉCRITURE ENTRE COMPTES SANS AUCUN MOUVEMENT DE FONDS RÉEL » — Emmanuel. Elle
    #    reste donc hors des flux de trésorerie, même sous « Soldes mandats ». Confondre une
    #    compensation avec un acompte ferait croire à un versement qui n'a jamais eu lieu.
    if COMPENS.search(l):
        return ('COMPENSATION ENTRE MANDATS — aucun mouvement de fonds réel',
                'ECRITURE_SANS_TRESORERIE', 'libellé')
    if DG.search(l):
        loc = bool(VERS_LOC.search(l))
        return ('MOUVEMENT DÉPÔT DE GARANTIE (vers le %s)'
                % ('locataire' if loc else 'propriétaire'),
                'REGIE_VERS_LOCATAIRE' if loc else 'REGIE_VERS_PROPRIETAIRE', 'libellé')
    if VOTRE.search(l):
        return 'APPORT DU PROPRIÉTAIRE', 'PROPRIETAIRE_VERS_REGIE', 'libellé'
    if REGLT.search(l):
        return 'REVERSEMENT AU PROPRIÉTAIRE', 'REGIE_VERS_PROPRIETAIRE', 'libellé'
    if ACOMPTE.search(l):
        return 'ACOMPTE / REMISE AU PROPRIÉTAIRE', 'REGIE_VERS_PROPRIETAIRE', 'libellé'
    if ACOMPTE.search(e):
        return 'ACOMPTE / REMISE AU PROPRIÉTAIRE', 'REGIE_VERS_PROPRIETAIRE', 'rubrique'
    if (ACOMPTE_PRO.search(l) or ACOMPTE_PRO.search(e)) and not SECT_ACOMPTE.search(e):
        return ('ACOMPTE PROPRIÉTAIRE — bénéficiaire bancaire non démontré',
                'SENS_INDETERMINABLE', 'libellé')

    # ⚠️ AVANT DE CROIRE UNE RUBRIQUE, ON DEMANDE À P7 CE QUE LE LIBELLÉ DIT DE LUI-MÊME. La
    #    rubrique « Reversement D.G. au prop. » déborde sur les lignes suivantes — P7 l'a
    #    démontré et certifié : « Honoraires H.T. » et « TVA/Honoraires » se retrouvaient
    #    classés en dépôt de garantie. On réutilise sa hiérarchie figée plutôt que de recopier
    #    son vocabulaire ici : une famille P7 nommée par le libellé est une DÉPENSE, jamais un
    #    flux propriétaire.
    for _nom, _motif in P7.P7B_MOTIFS + P7.P7A_MOTIFS:
        if _motif.search(l):
            return ('DÉPENSE NOMMÉE PAR SON LIBELLÉ (%s) — hors P8A' % _nom,
                    'sans objet (dépense)', 'libellé')

    if DG.search(e):
        loc = bool(VERS_LOC.search(l))
        return ('MOUVEMENT DÉPÔT DE GARANTIE (vers le %s)'
                % ('locataire' if loc else 'propriétaire'),
                'REGIE_VERS_LOCATAIRE' if loc else 'REGIE_VERS_PROPRIETAIRE', 'rubrique')
    if LOYERS_P.search(e):
        return ('REVERSEMENT AU PROPRIÉTAIRE — loyers versés, par rubrique',
                'REGIE_VERS_PROPRIETAIRE', 'rubrique')
    # ⚠️ UNE FACTURE DE LA RÉGIE SE NOMME, ELLE NE SE RANGE PAS EN « INDÉTERMINABLE ».
    #    « REGIE EMERY LOCA-IMMO ACCOMPAGNEMENT VENTE » sous une rubrique « + SALAIRE SRB » est
    #    une prestation facturée par la régie — Emmanuel : « tout ce qui est classé dans
    #    honoraires, ce sont des prestations diverses exceptionnelles facturées par la RÉGIE
    #    EMERY ». Ce n'est ni un salaire ni un flux propriétaire. Son rattachement à P7B reste
    #    à arbitrer : ce conteneur n'a jamais été lu par P7B.
    if DEPENSE.search(l):
        return HORS, 'sans objet (dépense)', 'libellé'

    # ⚠️ EN DERNIER, ET SEULEMENT EN DERNIER : LA SECTION « Soldes mandats ». Tout ce qui reste
    #    sous cette section est un acompte propriétaire — arbitrage Emmanuel. Elle est testée
    #    APRÈS le libellé, jamais avant : une taxe, une assurance ou un fournisseur nommés sous
    #    cette section restent ce que leur libellé dit. C'est la hiérarchie certifiée en P7.
    if SECT_ACOMPTE.search(e):
        return ('ACOMPTE PROPRIÉTAIRE — par section imprimée « Soldes mandats »',
                'REGIE_VERS_PROPRIETAIRE', 'rubrique')
    # ⚠️ LA RUBRIQUE FAIT FOI QUAND LE LIBELLÉ NE NOMME PAS SA FAMILLE — Emmanuel : « pour les
    #    autres lignes, il faut tenir compte du titre de la rubrique ». Sans ce test,
    #    « POUDEROUX n°202512-0012 - SCI SAONE & SABY », sous la rubrique « Procédure Tribunal
    #    activités écon. », partait en financement parce que son libellé contient « SCI » : une
    #    forme sociale lue comme une nature, alors que la rubrique dit la famille.
    for _nom, _motif in P7.P7B_MOTIFS + P7.P7A_MOTIFS:
        if _motif.search(e):
            return ('DÉPENSE NOMMÉE PAR SA RUBRIQUE (%s) — hors P8A' % _nom,
                    'sans objet (dépense)', 'rubrique')
    if ENTITE.search(l):
        return ('FINANCEMENT ENGAGÉ POUR LE PROPRIÉTAIRE — entité hors gestion',
                'REGIE_VERS_PROPRIETAIRE', 'libellé')
    return INDET, 'SENS_INDETERMINABLE', 'aucune'


def sens_confronte_au_signe(famille, sens, origine, debit, credit):
    """Le sens tient-il face au signe ? (sens, note)

       ⚠️ LE SIGNE NE FONDE JAMAIS LE SENS — mais il peut le CONTREDIRE. Quand le sens est lu
          sur le LIBELLÉ, le signe ne fait que confirmer : mesuré 577/577 débits pour
          « Règlement virement » et 8/8 crédits pour « votre », il n'infirme rien.
          Quand le sens vient d'une RUBRIQUE HÉRITÉE, c'est autre chose : deux chèques
          « Mme SERVAJEAN » portés au CRÉDIT se trouvaient sous la rubrique périmée
          « Reversement D.G. au prop. ». Affirmer là un versement AU propriétaire à partir
          d'une rubrique que la ligne dément, c'est inventer un sens. On ne le fait pas."""
    if origine != 'rubrique':
        return sens, ''
    # ⚠️ LE DÉPÔT DE GARANTIE AU CRÉDIT VA DANS L'AUTRE SENS — arbitrage Emmanuel : « à RIOM
    #    nous reversons les DG au propriétaire, donc quand il faut rendre le DG au départ du
    #    locataire, nous demandons au propriétaire de faire un remboursement ». Le chèque au
    #    crédit sous « Reversement D.G. au prop. » est le propriétaire qui restitue : l'argent
    #    remonte vers la régie, qui le rendra au locataire. Testé AVANT la règle des soldes
    #    mandats, sinon la même condition de signe l'absorberait.
    if 'DÉPÔT DE GARANTIE' in famille and credit and not debit:
        return 'PROPRIETAIRE_VERS_REGIE', 'dépôt restitué par le propriétaire'
    if sens == 'REGIE_VERS_PROPRIETAIRE' and credit and not debit:
        # ⚠️ « RESTITUTION / AUTRE MOUVEMENT » — arbitrage Emmanuel. Ces crédits sous « Soldes
        #    mandats » recouvrent trois causes distinctes qu'il a nommées : une mainlevée de
        #    saisie, un propriétaire qui verse pour payer des charges faute de trésorerie, une
        #    régularisation d'erreur de la régie. Aucune n'est un versement AU propriétaire :
        #    l'argent ENTRE. Le sens reste donc non démontré ligne à ligne — mais la famille est
        #    nommée, au lieu de rester « ni sens ni bénéficiaire ».
        return 'ENTREE_SOUS_SOLDES_MANDATS', 'rubrique héritée démentie par le signe'
    if sens == 'PROPRIETAIRE_VERS_REGIE' and debit and not credit:
        return 'SENS_INDETERMINABLE', 'rubrique héritée démentie par le signe'
    return sens, ''


def date_du_libelle(l):
    """La date IMPRIMÉE dans le libellé — « Reglement virement du 28.01.2026 ».

       ⚠️ JAMAIS `date_arrete`. La date d'arrêté du CRG n'est pas la date du virement : P1 l'a
          déjà démontré pour la période. Sans date dans le libellé, elle est NON DÉMONTRABLE."""
    m = RE_DATE.search(str(l or ''))
    if not m:
        return None
    j, mo, a = m.groups()
    a = ('20' + a) if len(a) == 2 else a
    try:
        return '%04d-%02d-%02d' % (int(a), int(mo), int(j))
    except ValueError:
        return None


def _candidate(e, l):
    return bool(LOYERS_P.search(e) or DG.search(e) or DG.search(l) or VOTRE.search(l)
                or REGLT.search(l) or ACOMPTE.search(e) or ACOMPTE.search(l)
                or REJET.search(e) or SECT_MDT.search(e))


def candidats(agence):
    """Toutes les lignes candidates, TOUS CONTENEURS DU PIVOT — pas seulement ceux de P7."""
    for d in documents(agence):
        m = d.get('meta') or {}
        base = {'agence': agence, 'format': d.get('_format'),
                'crg_fichier': d.get('_nom_original'), 'sha': d.get('_sha'),
                'periode_crg': m.get('periode_cle'),
                'date_arrete': P4B.jour(m.get('date_arrete')),
                'proprietaire': str(m.get('proprietaire') or m.get('mandant_nom') or ''),
                'compte': str(m.get('compte') or ''), 'niveau_source': 'lu'}

        # (1) LE COMPTE COURANT DU MANDANT — le conteneur que P7 n'a jamais ouvert
        for o in (d.get('mandat') or {}).get('operations') or []:
            deb, cre = float(o.get('debit') or 0), float(o.get('credit') or 0)
            f, s, org = famille(o.get('entete'), o.get('libelle'))
            if ligne_sans_ecriture(o.get('entete'), o.get('libelle'), deb, cre):
                f, s, org = ('LIGNE VIDE — adresse d’immeuble, aucune écriture',
                             'SANS_ECRITURE', 'aucune')
            s, note = sens_confronte_au_signe(f, s, org, deb, cre)
            yield dict(base, conteneur='mandat.operations', immeuble='', lot='',
                       maille_documentaire='compte mandant', page=o.get('page'),
                       section=str(o.get('entete') or ''),
                       libelle_brut=str(o.get('libelle') or ''),
                       debit=round(deb, 2), credit=round(cre, 2),
                       montant_source=round(deb or cre, 2), famille=f, sens=s,
                       origine_sens=org, note_sens=note,
                       # ⚠️ L'ORDONNÉE MANQUAIT AUX LIGNES DE MANDAT. Sans elle, deux écritures
                       #    identiques d'une même page se confondaient dans toute clé de
                       #    contrôle : le test de non-double-comptage comparait 676 clés pour
                       #    682 flux réels, et l'écart devait s'expliquer au lieu de ne pas
                       #    exister. Le lecteur la conserve — il suffisait de la porter.
                       y=None if o.get('y') is None else round(float(o['y']), 1),
                       agregat=bool(PLAGE.search(str(o.get('libelle') or ''))),
                       date_source=date_du_libelle(o.get('libelle')))

        for im in d.get('immeubles') or []:
            code = str(im.get('code') or '').strip() or ('NOM:' + P4B.plat(im.get('nom')))
            # (2) les charges dont la rubrique ou le libellé nomme un flux propriétaire
            for ch in im.get('charges') or []:
                e, l = str(ch.get('entete') or ''), str(ch.get('libelle') or '')
                if not _candidate(e, l):
                    continue
                deb, cre = float(ch.get('debit') or 0), float(ch.get('credit') or 0)
                f, s, org = famille(e, l)
                if ligne_sans_ecriture(e, l, deb, cre):
                    f, s, org = ('LIGNE VIDE — adresse d’immeuble, aucune écriture',
                                 'SANS_ECRITURE', 'aucune')
                s, note = sens_confronte_au_signe(f, s, org, deb, cre)
                yield dict(base, conteneur='immeubles[].charges', immeuble=code, lot='',
                           maille_documentaire='immeuble', page=ch.get('page'), section=e,
                           libelle_brut=l, debit=round(deb, 2), credit=round(cre, 2),
                           montant_source=round(deb or cre, 2), famille=f, sens=s,
                           origine_sens=org, note_sens=note, agregat=bool(PLAGE.search(l)),
                           date_source=date_du_libelle(l))
            # (3) le bloc que GROUPE SIR nomme `reversements` — et qui n'en contient aucun
            # ⚠️ CE CONTENEUR N'A NI ORDONNÉE NI RÉFÉRENCE. Plusieurs « Solde au
            #    30.06.2026 » d'une même page y sont indiscernables. On porte donc leur
            #    RANG dans le conteneur — non pas comme une identité, mais pour qu'un
            #    contrôle puisse compter des occurrences sans les fondre.
            for _rg, r in enumerate(im.get('reversements') or []):
                deb, cre = float(r.get('debit') or 0), float(r.get('credit') or 0)
                f, s, org = famille('', r.get('libelle'))
                s, note = sens_confronte_au_signe(f, s, org, deb, cre)
                yield dict(base, conteneur='immeubles[].reversements', immeuble=code, lot='',
                           maille_documentaire='immeuble', page=r.get('page'), section='',
                           libelle_brut=str(r.get('libelle') or ''),
                           debit=round(deb, 2), credit=round(cre, 2),
                           montant_source=round(deb or cre, 2), famille=f, sens=s,
                           origine_sens=org, note_sens=note, rang=_rg,
                           agregat=bool(PLAGE.search(str(r.get('libelle') or ''))),
                           date_source=date_du_libelle(r.get('libelle')))
            # (4) les mouvements SEPTEO
            for mv in im.get('mouvements') or []:
                l, sec = str(mv.get('libelle') or ''), str(mv.get('section') or '')
                if not _candidate(sec, l):
                    continue
                deb, cre = float(mv.get('debit') or 0), float(mv.get('credit') or 0)
                f, s, org = famille(sec, l)
                s, note = sens_confronte_au_signe(f, s, org, deb, cre)
                lot = str(mv.get('ref_lot') or '').strip().rstrip('-')
                yield dict(base, conteneur='immeubles[].mouvements', immeuble=code, lot=lot,
                           maille_documentaire='lot' if lot else 'immeuble',
                           page=mv.get('page'), section=sec, libelle_brut=l,
                           debit=round(deb, 2), credit=round(cre, 2),
                           montant_source=round(deb or cre, 2), famille=f, sens=s,
                           origine_sens=org, note_sens=note, agregat=bool(PLAGE.search(l)),
                           date_source=P4B.jour(mv.get('date')))


CHAINE = ('crg_fichier', 'sha', 'page', 'periode_crg', 'date_arrete', 'proprietaire', 'compte',
          'immeuble', 'lot', 'section', 'libelle_brut', 'montant_source', 'sens', 'famille',
          'maille_documentaire', 'date_source', 'niveau_source')


def cle(x):
    """L'identité d'un flux — ce que le document imprime, jamais un rapprochement flou."""
    return (x['agence'], x['compte'], x['immeuble'], x['section'], x['libelle_brut'],
            x['debit'], x['credit'], x['date_source'])


def bases(lst):
    """Les trois bases, jamais confondues — même doctrine que P7A."""
    par = {}
    for x in lst:
        par.setdefault(cle(x), []).append(x)
    certains = [v[0] for v in par.values() if len(v) == 1]
    doubl = [v for v in par.values() if len(v) > 1]
    return {'occurrences': len(lst), 'objets_certains': len(certains),
            'candidats_doublons': len(doubl),
            'occurrences_en_doublon': sum(len(v) for v in doubl),
            'total_basse': round(sum(x['montant_source'] for x in certains)
                                 + sum(v[0]['montant_source'] for v in doubl), 2),
            'total_haute': round(sum(x['montant_source'] for x in lst), 2)}
