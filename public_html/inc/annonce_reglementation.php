<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Réglementation métier par type de mandat d'annonce immobilière
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Source unique de vérité utilisée par :
 *   - annonce_reglementations.php       (page référentielle métier interne)
 *   - annonce_nouvelle.php              (création d'annonce : affichage conditionnel + contrôles)
 *   - moteur de conformité (à venir)    (validation avant publication)
 *
 * Types de mandat reconnus :
 *   - 'vente'            : mandat de vente (transaction immobilière)
 *   - 'location_seule'   : mandat de location simple (l'agence trouve le locataire puis retrait)
 *   - 'gestion_locative' : mandat de gestion locative (responsabilité renforcée, décence, etc.)
 *
 * Niveaux de contrôle :
 *   - 'bloquant'   : publication impossible sans cette donnée
 *   - 'alerte'     : attention, point à vérifier (publication possible avec warning)
 *   - 'info'       : simple aide / recommandation
 */

if (!defined('MANDAT_TYPES_VALIDES')) {
    define('MANDAT_TYPES_VALIDES', ['vente', 'location_seule', 'gestion_locative']);
}

/**
 * Normalise un type de mandat depuis des sources variées (mandats.type_mandat,
 * annonces.type_transaction, saisie utilisateur).
 */
function annonce_normaliser_mandat(string $type): string
{
    $t = strtolower(trim($type));
    return match ($t) {
        'vente', 'sale', 'achat'            => 'vente',
        'location_seule', 'location', 'rent', 'rental' => 'location_seule',
        'gestion_locative', 'gestion', 'property_management', 'management' => 'gestion_locative',
        default => '',
    };
}

/**
 * Libellé humain d'un type de mandat.
 */
function annonce_mandat_label(string $type): string
{
    return match (annonce_normaliser_mandat($type)) {
        'vente'            => 'Mandat de vente',
        'location_seule'   => 'Mandat de location seule',
        'gestion_locative' => 'Mandat de gestion locative',
        default            => 'Mandat non précisé',
    };
}

/**
 * Couleur (hex) associée à un type de mandat, pour badges / bannières.
 */
function annonce_mandat_color(string $type): string
{
    return match (annonce_normaliser_mandat($type)) {
        'vente'            => '#b67c00',  // ambre — transaction
        'location_seule'   => '#36577d',  // bleu — location
        'gestion_locative' => '#c0392b',  // rouge — responsabilité renforcée
        default            => '#6a6660',
    };
}

/**
 * Règles communes à TOUS les mandats (socle légal minimum).
 * @return array{champs_bloquants:array,champs_alertes:array,mentions_interdites:array,points_vigilance:array,checklist:array}
 */
function annonce_regles_communes(): array
{
    return [
        'champs_bloquants' => [
            'type_bien'         => 'Type de bien obligatoire (appartement, maison…)',
            'adresse_1'         => 'Adresse du bien obligatoire',
            'code_postal'       => 'Code postal obligatoire',
            'ville'             => 'Ville obligatoire',
            'surface_habitable' => 'Surface habitable obligatoire (Loi Boutin / Carrez selon le cas)',
        ],
        'champs_alertes' => [
            'dpe_classe'           => 'Classe DPE fortement recommandée (obligatoire pour diffusion portails depuis 2011)',
            'ges_classe'           => 'Classe GES attendue en complément du DPE',
            'annee_construction'   => 'Année de construction — utile pour identifier la période réglementaire',
            'nb_pieces'            => 'Nombre de pièces — facteur de pertinence SEO et filtre portails',
        ],
        'mentions_interdites' => [
            'Toute mention discriminatoire (origine, sexe, âge, situation familiale, handicap, orientation sexuelle, religion, opinion politique).',
            'Formules manifestement trompeuses (ex: « vue mer » si non vérifiable).',
            'Clauses abusives ou contraires à l\'ordre public.',
        ],
        'points_vigilance' => [
            'La description doit être fidèle à la réalité du bien — exagération ou omission = risque juridique.',
            'Les photos doivent correspondre au bien proposé (pas de photo d\'un bien voisin ou d\'une autre époque).',
            'Toute erreur de prix / loyer doit être corrigée immédiatement après constat.',
        ],
        'checklist' => [
            'Adresse, type, surface renseignés',
            'DPE + GES renseignés ou justifiés absents',
            'Photos conformes et récentes',
            'Description sans mention discriminatoire ni trompeuse',
            'Honoraires / frais affichés conformément à la loi Hoguet',
        ],
    ];
}

/**
 * Règles SPÉCIFIQUES par type de mandat.
 * Chaque fonction renvoie le delta à ajouter aux règles communes.
 */
function annonce_regles_vente(): array
{
    return [
        'label'        => 'Mandat de vente',
        'color'        => annonce_mandat_color('vente'),
        'emoji'        => '🤝',
        'responsabilite' => 'standard',
        'champs_bloquants' => [
            'prix_vente'  => 'Prix de vente obligatoire (Loi Hoguet art. 6)',
            'numero_mandat' => 'Numéro de mandat obligatoire sur toute diffusion',
            'honoraires_charge' => 'Indication « honoraires à la charge du vendeur ou de l\'acquéreur » OBLIGATOIRE (arrêté 10/01/2017)',
        ],
        'champs_alertes' => [
            'prix_net_vendeur'              => 'Prix net vendeur (hors honoraires si charge acquéreur) à vérifier',
            'honoraires_pourcentage'        => 'Taux d\'honoraires % TTC — mention obligatoire sur l\'annonce si charge acquéreur',
            'surface_carrez'                => 'Surface Loi Carrez si copropriété (obligatoire)',
            'copro_lots_nb'                 => 'Nombre de lots de la copropriété (si applicable)',
            'copro_charges_annuelles'       => 'Quote-part des charges annuelles copropriété (Loi ALUR)',
            'copro_procedure_en_cours'      => 'Procédure copropriété en cours (à déclarer si applicable)',
            'dpe_classe'                    => 'DPE OBLIGATOIRE pour vente (sauf exceptions rares)',
            'montant_depenses_energie_min'  => 'Montant estimé des dépenses énergétiques (obligatoire depuis 2022)',
            'montant_depenses_energie_max'  => 'Montant estimé des dépenses énergétiques (borne haute)',
            'zone_georisque'                => 'Mention Géorisques / ERP (obligatoire depuis 2023)',
        ],
        'mentions_interdites' => [
            '« Sans frais d\'agence » alors que des honoraires sont dus.',
            'Omission du taux d\'honoraires si à la charge de l\'acquéreur.',
            'Mention « vue garantie » ou « valorisable » sans étude documentée.',
        ],
        'points_vigilance' => [
            'Loi Carrez : la surface affichée doit être la surface privative Carrez si copropriété (erreur > 5% = réduction du prix).',
            'Loi ALUR : le nombre de lots, la quote-part des charges, et les procédures en cours doivent être visibles.',
            'Arrêté honoraires 2017 : l\'annonce doit indiquer le taux % TTC et à qui les honoraires incombent.',
            'DPE : vérifier la validité (10 ans post-2021, expiration antérieure pour DPE 2011-2018).',
            'Géorisques / ERP : le propriétaire doit pouvoir fournir l\'état des risques à tout moment.',
        ],
        'checklist' => [
            'Prix de vente FAI + indication de la charge des honoraires',
            'Taux d\'honoraires en % TTC si charge acquéreur',
            'Surface Carrez si copropriété',
            'DPE + GES + montants dépenses énergétiques',
            'Mention Géorisques / ERP',
            'Numéro de mandat + date de signature',
            'Informations copropriété (lots, charges, procédures) si applicable',
        ],
    ];
}

function annonce_regles_location_seule(): array
{
    return [
        'label'        => 'Mandat de location seule',
        'color'        => annonce_mandat_color('location_seule'),
        'emoji'        => '🔑',
        'responsabilite' => 'standard',
        'champs_bloquants' => [
            'loyer_hc'               => 'Loyer hors charges obligatoire (Loi Hoguet)',
            'numero_mandat'          => 'Numéro de mandat obligatoire',
            'charges_locatives'      => 'Montant des charges (mensuel ou forfaitaire) obligatoire',
            'honoraires_locataire'   => 'Honoraires TTC à la charge du locataire (plafonnés par la loi ALUR)',
        ],
        'champs_alertes' => [
            'depot_garantie'           => 'Dépôt de garantie — max 1 mois de loyer HC (vide) / 2 mois (meublé)',
            'surface_habitable'        => 'Surface habitable Loi Boutin obligatoire',
            'zone_tendue'              => 'Zone tendue ? (détermine l\'encadrement des loyers)',
            'loyer_reference'          => 'Loyer de référence (zone encadrée : Paris, Lille, Lyon, Montpellier…)',
            'loyer_reference_majore'   => 'Loyer de référence majoré (plafond zone encadrée)',
            'complement_loyer'         => 'Complément de loyer (justifiable par caractéristiques particulières)',
            'date_indice_revision'     => 'Date de révision IRL si bail en cours',
            'dpe_classe'               => 'DPE obligatoire (interdiction G à partir 2025, F 2028, E 2034)',
            'honoraires_etat_des_lieux'=> 'Honoraires état des lieux plafonnés (3,03 €/m² toutes zones — plafond 2026)',
        ],
        'mentions_interdites' => [
            'Honoraires supérieurs au plafond légal (8,07 / 10,09 / 12,10 €/m² selon zone — plafonds 2026).',
            'Dépôt de garantie > 1 mois (vide) ou > 2 mois (meublé).',
            'Clauses discriminantes (« jeune actif uniquement », « pas d\'étudiants »…).',
            'Refus d\'établir un bail ou un état des lieux.',
        ],
        'points_vigilance' => [
            'Plafonnement des honoraires locataire (2026) : 8,07 €/m² (non tendue), 10,09 €/m² (tendue), 12,10 €/m² (très tendue).',
            'Encadrement des loyers applicable à Paris, Lille, Montpellier, Lyon, Villeurbanne, Bordeaux, Grenoble, Est Ensemble, Plaine Commune…',
            'Interdiction progressive de location des passoires thermiques (G, F, E).',
            'Décret décence : logement doit être sain, clos, couvert, bien aéré, et sans risque manifeste.',
        ],
        'checklist' => [
            'Loyer HC + charges + dépôt de garantie',
            'Honoraires locataire dans le plafond légal',
            'Surface habitable Loi Boutin',
            'DPE + classe énergie (décence)',
            'Zone tendue + encadrement si applicable',
            'Numéro de mandat + durée',
            'Mention plomb / amiante / électricité si ancien',
        ],
    ];
}

function annonce_regles_gestion_locative(): array
{
    // Gestion locative = location seule + obligations renforcées du gestionnaire
    $locationSeule = annonce_regles_location_seule();
    return [
        'label'          => 'Mandat de gestion locative',
        'color'          => annonce_mandat_color('gestion_locative'),
        'emoji'          => '🏢',
        'responsabilite' => 'RENFORCÉE',
        'champs_bloquants' => array_merge($locationSeule['champs_bloquants'], [
            'decence_ok'             => 'Le logement doit être confirmé comme DÉCENT (décret 30/01/2002 + 09/03/2017) — responsabilité engagée',
            'diagnostics_complets'   => 'Ensemble des diagnostics obligatoires doit être à jour (DPE, plomb, amiante, élec, gaz, ERP)',
        ]),
        'champs_alertes' => array_merge($locationSeule['champs_alertes'], [
            'etat_logement_interieur'     => 'État précis du logement (à renseigner par le gestionnaire)',
            'equipements_chauffage'       => 'Mode de chauffage + énergie (risque si obsolète)',
            'risque_plomb'                => 'Diagnostic plomb (CREP) si logement antérieur 1949',
            'risque_amiante'              => 'Diagnostic amiante si permis de construire antérieur 1997',
            'electricite_conformite'      => 'Diagnostic électricité (installations > 15 ans)',
            'gaz_conformite'              => 'Diagnostic gaz (installations > 15 ans)',
            'contrat_assurance_pno'       => 'Assurance propriétaire non occupant (à vérifier)',
        ]),
        'mentions_interdites' => array_merge($locationSeule['mentions_interdites'], [
            'Louer un logement non décent alors que le gestionnaire a connaissance des défauts.',
            'Omettre la mention d\'un diagnostic obligatoire (DPE, plomb, amiante…).',
            'Accepter un dépôt de garantie supérieur au plafond légal.',
        ]),
        'points_vigilance' => array_merge($locationSeule['points_vigilance'], [
            '⚠️ RESPONSABILITÉ RENFORCÉE : le gestionnaire est tenu à une obligation de conseil et de loyauté (arrêt Cass. civ. 3e, 24 juin 2015).',
            '⚠️ DÉCENCE : en cas de logement non décent, le bailleur ET le gestionnaire peuvent être condamnés (L. 6-1 de la loi du 6 juillet 1989).',
            '⚠️ RISQUES ASSURANTIELS : la responsabilité civile professionnelle de l\'agence peut être engagée en cas de défaut de diligence.',
            'Obligation de conseiller le bailleur sur les travaux nécessaires à la décence.',
            'Obligation de vérifier la validité et la complétude de tous les diagnostics avant mise en location.',
            'Obligation d\'informer le bailleur des obligations d\'assurance (PNO, copropriété).',
        ]),
        'checklist' => array_merge($locationSeule['checklist'], [
            '✓ DÉCENCE : logement confirmé décent (surface, équipements, absence de risques manifestes)',
            '✓ Diagnostics OBLIGATOIRES : DPE, plomb (si <1949), amiante (si <1997), élec + gaz (>15 ans), ERP',
            '✓ Assurance PNO du bailleur vérifiée',
            '✓ Clause de mandat de gestion dûment signée et à jour',
            '✓ Conseil écrit au bailleur si travaux nécessaires',
        ]),
    ];
}

/**
 * Retourne les règles complètes applicables à un type de mandat donné.
 * Fusionne les règles communes + les règles spécifiques.
 *
 * @param string $mandat  'vente' | 'location_seule' | 'gestion_locative'
 * @return array{
 *   mandat:string, label:string, color:string, emoji:string, responsabilite:string,
 *   champs_bloquants:array<string,string>,
 *   champs_alertes:array<string,string>,
 *   mentions_interdites:array<int,string>,
 *   points_vigilance:array<int,string>,
 *   checklist:array<int,string>,
 * }
 */
function annonce_regles_par_mandat(string $mandat): array
{
    $mandat = annonce_normaliser_mandat($mandat);
    $communes = annonce_regles_communes();
    $specifiques = match ($mandat) {
        'vente'            => annonce_regles_vente(),
        'location_seule'   => annonce_regles_location_seule(),
        'gestion_locative' => annonce_regles_gestion_locative(),
        default => [
            'label' => 'Mandat non précisé',
            'color' => '#6a6660', 'emoji' => '❓', 'responsabilite' => 'indéfinie',
            'champs_bloquants' => [], 'champs_alertes' => [],
            'mentions_interdites' => [], 'points_vigilance' => [], 'checklist' => [],
        ],
    };

    return [
        'mandat'              => $mandat,
        'label'               => (string)($specifiques['label'] ?? ''),
        'color'               => (string)($specifiques['color'] ?? '#6a6660'),
        'emoji'               => (string)($specifiques['emoji'] ?? ''),
        'responsabilite'      => (string)($specifiques['responsabilite'] ?? 'standard'),
        'champs_bloquants'    => array_merge($communes['champs_bloquants'],    $specifiques['champs_bloquants']    ?? []),
        'champs_alertes'      => array_merge($communes['champs_alertes'],      $specifiques['champs_alertes']      ?? []),
        'mentions_interdites' => array_merge($communes['mentions_interdites'], $specifiques['mentions_interdites'] ?? []),
        'points_vigilance'    => array_merge($communes['points_vigilance'],    $specifiques['points_vigilance']    ?? []),
        'checklist'           => array_merge($communes['checklist'],           $specifiques['checklist']           ?? []),
    ];
}

/**
 * Valide un ensemble de données d'annonce contre les règles d'un mandat.
 *
 * @param array  $data   Données d'annonce (clés = noms des champs)
 * @param string $mandat Type de mandat
 * @return array{
 *   ok:bool, bloquants:array<string>, alertes:array<string>,
 *   checklist_completee:int, checklist_totale:int
 * }
 */
function annonce_valider_conformite(array $data, string $mandat): array
{
    $regles = annonce_regles_par_mandat($mandat);
    $bloquants = [];
    $alertes   = [];

    foreach ($regles['champs_bloquants'] as $champ => $message) {
        $val = $data[$champ] ?? null;
        if ($val === null || $val === '' || (is_numeric($val) && (float)$val === 0.0 && !in_array($champ, ['decence_ok','diagnostics_complets'], true))) {
            $bloquants[] = $champ . ' : ' . $message;
        }
    }
    foreach ($regles['champs_alertes'] as $champ => $message) {
        $val = $data[$champ] ?? null;
        if ($val === null || $val === '') {
            $alertes[] = $champ . ' : ' . $message;
        }
    }

    $totChecklist = count($regles['checklist']);
    return [
        'ok'                 => empty($bloquants),
        'bloquants'          => $bloquants,
        'alertes'            => $alertes,
        'checklist_completee'=> max(0, $totChecklist - count($bloquants) - (int)round(count($alertes) * 0.5)),
        'checklist_totale'   => $totChecklist,
    ];
}

/**
 * Récupère le type de mandat courant d'un bien (lecture BDD).
 * Prend le mandat actif le plus récent (statut in actif, projet…).
 *
 * @return string  'vente' | 'location_seule' | 'gestion_locative' | '' (aucun)
 */
function annonce_mandat_du_bien(PDO $pdo, int $idBien): string
{
    if ($idBien <= 0) return '';
    try {
        $stmt = $pdo->prepare("
            SELECT type_mandat
            FROM mandats
            WHERE id_bien = ?
            ORDER BY FIELD(statut, 'actif', 'projet', 'archive') ASC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$idBien]);
        $t = (string)$stmt->fetchColumn();
        return annonce_normaliser_mandat($t);
    } catch (Throwable) {
        return '';
    }
}
