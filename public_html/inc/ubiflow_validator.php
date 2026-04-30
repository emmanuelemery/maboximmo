<?php
declare(strict_types=1);

/**
 * UBIFLOW VALIDATOR — vérification de la complétude d'un bien pour diffusion
 *
 * Deux niveaux de règles fusionnés :
 *   1. UBIFLOW (vert)        — minimum strict imposé par le format Ubiflow
 *                              et les obligations légales (DPE, ALUR, ERP).
 *                              Codé en dur, intouchable.
 *   2. ADMIN SOCIÉTÉ (bleu pétrole) — règles supplémentaires définies par
 *                              l'admin via la table `societe_champs_obligatoires`.
 *
 * La fonction principale `ubiflow_check_completude()` retourne un tableau
 * structuré listant les manques, par catégorie, avec un statut global :
 *   - 'ok'           → diffusable
 *   - 'warning'      → diffusable mais des champs admin non bloquants manquent
 *   - 'incomplet'    → non diffusable (manques côté Ubiflow ou champs admin bloquants)
 */

const UBIFLOW_CHECK_OK         = 'ok';
const UBIFLOW_CHECK_WARNING    = 'warning';
const UBIFLOW_CHECK_INCOMPLET  = 'incomplet';

const UBIFLOW_SOURCE_UBIFLOW   = 'ubiflow';   // vert
const UBIFLOW_SOURCE_ADMIN     = 'admin';     // bleu pétrole

/**
 * Définition du minimum Ubiflow (codé en dur).
 *
 * Chaque entrée :
 *   - key     : nom de colonne (biens.* ou annonces.*)
 *   - label   : libellé court à afficher
 *   - scope   : 'bien' | 'annonce'
 *   - section : catégorie d'affichage (identification, dpe, alur, …)
 *   - required_when : closure($bien, $annonce) → bool ; ou null = toujours
 */
function ubiflow_minimum_rules(): array
{
    return [
        // ── Identification de l'annonce ──
        ['key' => 'reference_bien',     'label' => 'Référence du bien',          'scope' => 'bien',    'section' => 'identification'],
        ['key' => 'designation',        'label' => 'Désignation commerciale',     'scope' => 'bien',    'section' => 'identification'],
        ['key' => 'description',        'label' => 'Description / texte annonce', 'scope' => 'annonce', 'section' => 'identification', 'min_len' => 100],

        // ── Négociateur attribué (obligatoire pour diffusion portails) ──
        // LBC affiche le contact négociateur sur la page de l'annonce. Sans
        // user attribué, l'export ne peut pas remplir le bloc <contact> et
        // l'annonce remonterait au contact agence générique. Refus de diffusion.
        ['key' => 'id_user',            'label' => 'Négociateur attribué (mobile + email visibles sur LBC)',
         'scope' => 'annonce', 'section' => 'identification',
         'required_when' => fn($b,$a) => (int)($a['visible_portails'] ?? 0) === 1],

        // ── Localisation ──
        ['key' => 'code_postal',        'label' => 'Code postal',                 'scope' => 'imm',     'section' => 'localisation'],
        ['key' => 'ville',              'label' => 'Ville',                       'scope' => 'imm',     'section' => 'localisation'],

        // ── Caractéristiques de base ──
        ['key' => 'id_type_bien',       'label' => 'Type de bien',                'scope' => 'bien',    'section' => 'caracteristiques'],
        ['key' => 'surface_habitable',  'label' => 'Surface habitable',           'scope' => 'bien',    'section' => 'caracteristiques',
         'required_when' => fn($b,$a) => in_array(($b['_type_bien_code'] ?? ''), ['appartement','maison','immeuble'], true)],
        ['key' => 'nb_pieces',          'label' => 'Nombre de pièces',            'scope' => 'bien',    'section' => 'caracteristiques',
         'required_when' => fn($b,$a) => in_array(($b['_type_bien_code'] ?? ''), ['appartement','maison'], true)],
        ['key' => 'annee_construction', 'label' => 'Année de construction',       'scope' => 'bien',    'section' => 'caracteristiques',
         'required_when' => fn($b,$a) => !in_array(($b['_type_bien_code'] ?? ''), ['terrain','parking','garage','box'], true)],
        ['key' => 'nb_wc',              'label' => 'Nombre de WC',                'scope' => 'bien',    'section' => 'caracteristiques',
         'required_when' => fn($b,$a) => in_array(($b['_type_bien_code'] ?? ''), ['appartement','maison'], true)],

        // ── Transaction ──
        ['key' => 'type_transaction',   'label' => 'Type de transaction (vente/location)', 'scope' => 'annonce', 'section' => 'transaction'],
        ['key' => 'prix',               'label' => 'Prix de vente',               'scope' => 'annonce', 'section' => 'transaction',
         'required_when' => fn($b,$a) => ($a['type_transaction'] ?? '') === 'vente'],
        ['key' => 'loyer',              'label' => 'Loyer mensuel',               'scope' => 'annonce', 'section' => 'transaction',
         'required_when' => fn($b,$a) => ($a['type_transaction'] ?? '') === 'location'],

        // ── DPE (obligations 2011 / 2021 / 2022) ──
        // Skipped quand le DPE est marqué "vierge" (valeurs chiffrées non requises)
        // Et pour les types sans obligation DPE légale : parking, stationnement, garage, box, terrain
        ['key' => 'dpe_classe',         'label' => 'Classe énergétique (DPE)',    'scope' => 'bien',    'section' => 'dpe',
         'required_when' => fn($b,$a) => !in_array(($b['_type_bien_code'] ?? ''), ['parking','stationnement','garage','box','terrain'], true)],
        ['key' => 'ges_classe',         'label' => 'Classe GES',                  'scope' => 'bien',    'section' => 'dpe',
         'required_when' => fn($b,$a) => (int)($b['dpe_vierge'] ?? 0) !== 1
             && !in_array(($b['_type_bien_code'] ?? ''), ['parking','stationnement','garage','box','terrain'], true)],
        ['key' => 'dpe_valeur',         'label' => 'Valeur DPE (kWh/m²/an)',      'scope' => 'bien',    'section' => 'dpe',
         'required_when' => fn($b,$a) => (int)($b['dpe_vierge'] ?? 0) !== 1
             && !in_array(($b['_type_bien_code'] ?? ''), ['parking','stationnement','garage','box','terrain'], true)],
        ['key' => 'ges_valeur',         'label' => 'Valeur GES (CO₂/m²/an)',      'scope' => 'bien',    'section' => 'dpe',
         'required_when' => fn($b,$a) => (int)($b['dpe_vierge'] ?? 0) !== 1
             && !in_array(($b['_type_bien_code'] ?? ''), ['parking','stationnement','garage','box','terrain'], true)],
        ['key' => 'dpe_date_realisation','label' => 'Date de réalisation DPE',    'scope' => 'bien',    'section' => 'dpe',
         'required_when' => fn($b,$a) => !in_array(($b['_type_bien_code'] ?? ''), ['parking','stationnement','garage','box','terrain'], true)],

        // ── ALUR copropriété (uniquement si bien en copro) ──
        ['key' => 'copro_nb_lots',      'label' => 'Nombre de lots de la copropriété', 'scope' => 'bien', 'section' => 'alur',
         'required_when' => fn($b,$a) => (int)($b['bien_en_copropriete'] ?? 0) === 1],
        ['key' => 'copro_quote_part_charges', 'label' => 'Quote-part charges annuelles', 'scope' => 'bien', 'section' => 'alur',
         'required_when' => fn($b,$a) => (int)($b['bien_en_copropriete'] ?? 0) === 1],

        // ── Honoraires ALUR (vente avec honoraires acquéreur) ──
        ['key' => 'alur_pourcentage_honoraires_ttc', 'label' => '% TTC honoraires acquéreur', 'scope' => 'annonce', 'section' => 'alur',
         'required_when' => fn($b,$a) => ($a['type_transaction'] ?? '') === 'vente' && (int)($a['honoraires_charge_acquereur'] ?? 0) === 1],
        ['key' => 'url_tarifs_publics', 'label' => 'URL du barème d\'honoraires (obligation arrêté 10/01/2017)', 'scope' => 'annonce', 'section' => 'alur',
         'required_when' => fn($b,$a) => ($a['type_transaction'] ?? '') === 'vente'],

        // ── Photos ──
        ['key' => '_photos_count',      'label' => 'Au moins 1 photo',            'scope' => 'meta',    'section' => 'photos'],
    ];
}

/**
 * Vérifie la complétude d'un bien pour la diffusion Ubiflow.
 *
 * @param array      $bien        Ligne biens (chargée via bien_form_load_record).
 * @param array      $annonce     Ligne annonces principale (peut être vide).
 * @param int        $photosCount Nombre de photos rattachées.
 * @param PDO|null   $pdo         Pour charger les règles admin (peut être null).
 * @param int|null   $idSociete   Société pour scopage des règles admin.
 *
 * @return array {
 *     status      : 'ok' | 'warning' | 'incomplet',
 *     missing     : [ ['key','label','source','section','blocking'], ... ],
 *     score       : int (0-100),
 *     totals      : ['ubiflow' => int, 'admin' => int]
 * }
 */
function ubiflow_check_completude(array $bien, array $annonce, int $photosCount = 0, ?PDO $pdo = null, ?int $idSociete = null): array
{
    $missing      = [];
    $totalChecks  = 0;
    $passed       = 0;

    // Helper : test "valeur présente"
    $isPresent = static function ($v): bool {
        if ($v === null) return false;
        if (is_string($v) && trim($v) === '') return false;
        if (is_numeric($v) && (float)$v == 0.0) return false;
        return true;
    };

    // ── 1. Règles Ubiflow (codées en dur) ──
    foreach (ubiflow_minimum_rules() as $rule) {
        // Filtre conditionnel
        if (isset($rule['required_when']) && is_callable($rule['required_when'])) {
            if (!$rule['required_when']($bien, $annonce)) {
                continue;
            }
        }

        $totalChecks++;
        $key = $rule['key'];
        $value = null;

        switch ($rule['scope']) {
            case 'bien':    $value = $bien[$key]    ?? null; break;
            case 'annonce': $value = $annonce[$key] ?? null; break;
            case 'imm':     $value = $bien['_imm_' . $key] ?? ($bien[$key] ?? null); break;
            case 'meta':
                if ($key === '_photos_count') $value = $photosCount > 0 ? 1 : null;
                break;
        }

        $present = $isPresent($value);
        if ($present && isset($rule['min_len']) && mb_strlen((string)$value) < (int)$rule['min_len']) {
            $present = false;
        }

        if ($present) {
            $passed++;
        } else {
            $missing[] = [
                'key'      => $key,
                'label'    => $rule['label'],
                'source'   => UBIFLOW_SOURCE_UBIFLOW,
                'section'  => $rule['section'],
                'blocking' => true,
            ];
        }
    }

    // ── 2. Règles admin (chargées depuis societe_champs_obligatoires) ──
    if ($pdo !== null && $idSociete !== null) {
        try {
            $stmt = $pdo->prepare("
                SELECT champ, scope, contexte, libelle_affiche, message_aide, obligatoire_diffusion
                FROM societe_champs_obligatoires
                WHERE id_societe = :soc AND actif = 1
            ");
            $stmt->execute([':soc' => $idSociete]);
            $adminRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $adminRules = [];
        }

        $typeTrans = $annonce['type_transaction'] ?? '';
        $isCopro   = (int)($bien['bien_en_copropriete'] ?? 0) === 1;

        foreach ($adminRules as $r) {
            // Filtre par contexte si défini
            $ctx = $r['contexte'] ?? null;
            if ($ctx !== null && $ctx !== '') {
                if ($ctx === 'vente'    && $typeTrans !== 'vente')    continue;
                if ($ctx === 'location' && $typeTrans !== 'location') continue;
                if ($ctx === 'copro'    && !$isCopro)                  continue;
            }

            $totalChecks++;
            $champ = $r['champ'];
            $scope = $r['scope'];
            $value = $scope === 'annonce' ? ($annonce[$champ] ?? null) : ($bien[$champ] ?? null);

            if ($isPresent($value)) {
                $passed++;
            } else {
                $missing[] = [
                    'key'      => $champ,
                    'label'    => $r['libelle_affiche'] ?: $champ,
                    'source'   => UBIFLOW_SOURCE_ADMIN,
                    'section'  => 'admin',
                    'blocking' => (int)$r['obligatoire_diffusion'] === 1,
                    'aide'     => $r['message_aide'] ?? null,
                ];
            }
        }
    }

    // ── Calcul du statut global ──
    $hasBlockingMiss = false;
    $hasWarningMiss  = false;
    foreach ($missing as $m) {
        if ($m['blocking']) $hasBlockingMiss = true;
        else                $hasWarningMiss  = true;
    }

    $status = UBIFLOW_CHECK_OK;
    if ($hasBlockingMiss)       $status = UBIFLOW_CHECK_INCOMPLET;
    elseif ($hasWarningMiss)    $status = UBIFLOW_CHECK_WARNING;

    $score = $totalChecks > 0 ? (int) round(($passed / $totalChecks) * 100) : 100;

    return [
        'status'  => $status,
        'missing' => $missing,
        'score'   => $score,
        'totals'  => [
            'ubiflow' => count(array_filter($missing, fn($m) => $m['source'] === UBIFLOW_SOURCE_UBIFLOW)),
            'admin'   => count(array_filter($missing, fn($m) => $m['source'] === UBIFLOW_SOURCE_ADMIN)),
        ],
    ];
}

/**
 * Renvoie le nombre de photos rattachées à une annonce.
 *
 * CORRECTION #5 (audit V2 2026-04-11) :
 *  - Le précédent code utilisait `$pdo->prepare(...)->execute([$id])` qui
 *    retourne un booléen, pas un entier → `(int)` toujours 0 ou 1.
 *  - Le validator lisait `biens_photos` tandis que l'exporter Ubiflow lit
 *    `annonces_photos` → deux tables différentes pour la même donnée, d'où
 *    une incohérence entre le compte affiché (côté bien) et ce qui partait
 *    réellement dans le flux (côté annonce).
 *
 * Cette fonction est désormais ALIGNÉE sur `annonces_photos` (la table
 * utilisée par l'exporter). Elle expose deux signatures :
 *   - ubiflow_count_photos($pdo, $idAnnonce) — compte les photos d'une annonce
 *   - ubiflow_count_photos_bien($pdo, $idBien) — compte les photos de tout
 *     le bien via biens_photos (utilisé par le validator de complétude en
 *     amont de la création d'annonce).
 */
function ubiflow_count_photos(PDO $pdo, int $idAnnonce): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM annonces_photos WHERE id_annonce = ?");
        $stmt->execute([$idAnnonce]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Compte les photos de la bibliothèque d'un bien (biens_photos),
 * utilisée AVANT création d'annonce pour valider la complétude.
 */
function ubiflow_count_photos_bien(PDO $pdo, int $idBien): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM biens_photos WHERE id_bien = ?");
        $stmt->execute([$idBien]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}
