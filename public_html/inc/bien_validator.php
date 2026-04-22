<?php
declare(strict_types=1);

/**
 * inc/bien_validator.php
 *
 * Validation bien-level avant création d'annonce.
 *
 * Liste les champs bien-level (persistants pour TOUTES les annonces successives)
 * qui doivent être remplis avant de pouvoir passer le bien en `statut_bien = 'actif'`.
 * Les champs annonce-level (prix, loyer, mandat, honoraires…) ne sont PAS vérifiés
 * ici — ils seront validés au moment de la diffusion Ubiflow via inc/ubiflow_validator.php.
 *
 * Exemptions selon type de bien :
 *   - parking / stationnement / garage / box / terrain → pas de surface, pas de DPE,
 *     pas de pièces/chambres.
 *
 * Retourne un tableau de "checks" avec le statut de chaque règle :
 *   [
 *     ['key' => 'adresse',     'label' => 'Adresse complète',  'ok' => true,  'detail' => '19 Bd Yves Farge, 69007 Lyon'],
 *     ['key' => 'surface',     'label' => 'Surface habitable', 'ok' => false, 'detail' => 'Champ vide'],
 *     ...
 *   ]
 */

/**
 * Codes de types de bien qui ne requièrent PAS surface / DPE / pièces.
 */
function bien_validator_types_exempts_dpe_surface(): array
{
    return ['parking', 'stationnement', 'garage', 'box', 'terrain', 'terrain_agricole'];
}

/**
 * Charge un bien avec ses champs utiles pour la validation, y compris l'adresse
 * via JOIN immeubles (source canonique depuis 2026-04-20).
 */
function bien_validator_load(PDO $pdo, int $idBien): ?array
{
    if ($idBien <= 0) return null;
    try {
        $st = $pdo->prepare("
            SELECT
                b.id, b.statut_bien,
                b.id_type_bien, t.code AS type_bien_code, t.libelle AS type_bien_libelle,
                b.usage_bien,
                b.id_proprietaire, b.id_immeuble,
                b.surface_habitable, b.surface_totale,
                b.nb_pieces, b.nb_chambres,
                b.dpe_classe, b.ges_classe, b.dpe_vierge,
                COALESCE(i.adresse_1,   b.adresse_1)   AS adresse_1,
                COALESCE(i.code_postal, b.code_postal) AS code_postal,
                COALESCE(i.ville,       b.ville)       AS ville,
                (SELECT COUNT(*) FROM biens_photos bp WHERE bp.id_bien = b.id) AS nb_photos
            FROM biens b
            LEFT JOIN types_bien t ON t.id = b.id_type_bien
            LEFT JOIN immeubles i  ON i.id = b.id_immeuble
            WHERE b.id = ?
            LIMIT 1
        ");
        $st->execute([$idBien]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[bien_validator] load: ' . $e->getMessage());
        return null;
    }
}

/**
 * Effectue la checklist Ubiflow sur un bien.
 *
 * @return array{
 *   bien: ?array,
 *   type_code: string,
 *   exempt_dpe_surface: bool,
 *   checks: array<int, array{key:string,label:string,ok:bool,required:bool,detail:string}>,
 *   missing_required: array<int, string>,
 *   ok: bool
 * }
 */
function bien_validator_check(PDO $pdo, int $idBien): array
{
    $b = bien_validator_load($pdo, $idBien);
    if (!$b) {
        return ['bien' => null, 'type_code' => '', 'exempt_dpe_surface' => false, 'checks' => [], 'missing_required' => [], 'ok' => false];
    }

    $typeCode = strtolower((string)($b['type_bien_code'] ?? ''));
    $exempt = in_array($typeCode, bien_validator_types_exempts_dpe_surface(), true);
    $checks = [];

    // 1. Type de bien
    $checks[] = [
        'key' => 'type_bien',
        'label' => 'Type de bien',
        'required' => true,
        'ok' => !empty($b['id_type_bien']) && $typeCode !== '',
        'detail' => $typeCode !== '' ? (string)$b['type_bien_libelle'] : 'À choisir',
    ];

    // 2. Usage (habitation / commercial / pro / mixte)
    $usage = (string)($b['usage_bien'] ?? '');
    $checks[] = [
        'key' => 'usage',
        'label' => 'Usage du bien',
        'required' => true,
        'ok' => $usage !== '',
        'detail' => $usage !== '' ? $usage : 'À choisir',
    ];

    // 3. Adresse complète (via immeuble ou directement sur bien)
    $adr = trim((string)($b['adresse_1'] ?? ''));
    $cp  = trim((string)($b['code_postal'] ?? ''));
    $vil = trim((string)($b['ville'] ?? ''));
    $adrComplet = ($adr !== '' && $cp !== '' && $vil !== '');
    $checks[] = [
        'key' => 'adresse',
        'label' => 'Adresse complète (rue + CP + ville)',
        'required' => true,
        'ok' => $adrComplet,
        'detail' => $adrComplet ? sprintf('%s · %s %s', $adr, $cp, $vil) : 'Incomplète',
    ];

    // 4. Propriétaire rattaché
    $hasProprio = !empty($b['id_proprietaire']) && (int)$b['id_proprietaire'] > 0;
    $checks[] = [
        'key' => 'proprietaire',
        'label' => 'Propriétaire rattaché',
        'required' => true,
        'ok' => $hasProprio,
        'detail' => $hasProprio ? '#' . (int)$b['id_proprietaire'] : 'Aucun',
    ];

    // 5. Immeuble lié (canonical source adresse / GPS)
    $hasImmeuble = !empty($b['id_immeuble']) && (int)$b['id_immeuble'] > 0;
    $checks[] = [
        'key' => 'immeuble',
        'label' => 'Immeuble lié',
        'required' => true,
        'ok' => $hasImmeuble,
        'detail' => $hasImmeuble ? '#' . (int)$b['id_immeuble'] : 'À lier via la modal adresse',
    ];

    // 6. Surface habitable (sauf exemptions type)
    $surf = (float)($b['surface_habitable'] ?? 0);
    if ($surf <= 0) $surf = (float)($b['surface_totale'] ?? 0);
    $surfOk = $surf > 0;
    $checks[] = [
        'key' => 'surface',
        'label' => 'Surface habitable (ou totale)',
        'required' => !$exempt,
        'ok' => $exempt || $surfOk,
        'detail' => $surfOk ? number_format($surf, 2, ',', ' ') . ' m²' : ($exempt ? 'Non requis pour ce type' : 'Champ vide'),
    ];

    // 7. DPE (sauf exemptions)
    $dpe = (string)($b['dpe_classe'] ?? '');
    $dpeVierge = (int)($b['dpe_vierge'] ?? 0) === 1;
    $dpeOk = $dpe !== '' || $dpeVierge;
    $checks[] = [
        'key' => 'dpe',
        'label' => 'DPE (classe ou vierge justifié)',
        'required' => !$exempt,
        'ok' => $exempt || $dpeOk,
        'detail' => $dpeOk ? ($dpeVierge ? 'Vierge justifié' : 'Classe ' . $dpe) : ($exempt ? 'Non requis pour ce type' : 'Manquant'),
    ];

    // 8. Photos (au moins 1)
    $nbPhotos = (int)($b['nb_photos'] ?? 0);
    $checks[] = [
        'key' => 'photos',
        'label' => 'Au moins une photo',
        'required' => true,
        'ok' => $nbPhotos >= 1,
        'detail' => $nbPhotos > 0 ? $nbPhotos . ' photo(s)' : 'Aucune',
    ];

    // Calcul global
    $missing = [];
    foreach ($checks as $c) {
        if ($c['required'] && !$c['ok']) $missing[] = $c['key'];
    }

    return [
        'bien'               => $b,
        'type_code'          => $typeCode,
        'exempt_dpe_surface' => $exempt,
        'checks'             => $checks,
        'missing_required'   => $missing,
        'ok'                 => empty($missing),
    ];
}

/**
 * Passe le bien en statut actif si validation OK.
 * Renvoie ['ok' => bool, 'missing' => array, 'error' => ?string].
 */
function bien_validator_validate(PDO $pdo, int $idBien): array
{
    $r = bien_validator_check($pdo, $idBien);
    if (!$r['bien']) return ['ok' => false, 'error' => 'Bien introuvable', 'missing' => []];
    if (!$r['ok']) return ['ok' => false, 'error' => 'Champs obligatoires manquants', 'missing' => $r['missing_required']];

    // Normalisation au passage actif : référence définitive + désignation
    // (logique reprise de l'ex-auto_activate, déplacée ici pour le flow manuel)
    try {
        $st = $pdo->prepare("
            SELECT b.reference_bien, b.designation, b.id_agence, b.ville,
                   tb.code AS type_code
            FROM biens b
            LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
            WHERE b.id = ? LIMIT 1
        ");
        $st->execute([$idBien]);
        $info = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $sets = ["statut_bien = 'actif'", 'date_modification = NOW()'];
        $params = [':id' => $idBien];

        // Référence : génère la vraie si temporaire (TMP-*) ou vide
        $currentRef = (string)($info['reference_bien'] ?? '');
        $isTempRef  = ($currentRef === '' || str_starts_with($currentRef, 'TMP-'));
        if ($isTempRef && (int)($info['id_agence'] ?? 0) > 0) {
            try {
                require_once __DIR__ . '/ref_generator.php';
                $newRef = ref_generate_bien($pdo, [
                    'id_agence'      => (int)$info['id_agence'],
                    'type_bien_code' => (string)($info['type_code'] ?? ''),
                    'ville'          => (string)($info['ville']     ?? ''),
                    'user' => [
                        'nom'    => (string)($_SESSION['nom']    ?? ''),
                        'prenom' => (string)($_SESSION['prenom'] ?? ''),
                    ],
                ]);
                if (!empty($newRef)) {
                    $sets[] = 'reference_bien = :ref';
                    $params[':ref'] = $newRef;
                }
            } catch (Throwable $e) {
                error_log('[bien_validator] ref_generate: ' . $e->getMessage());
            }
        }

        // Désignation : remplace "Brouillon créé le" par "Bien créé le"
        $currentDes = (string)($info['designation'] ?? '');
        if (str_starts_with($currentDes, 'Brouillon créé le')) {
            $sets[] = 'designation = :des';
            $params[':des'] = 'Bien créé le ' . date('d/m/Y H:i');
        }

        $pdo->prepare("UPDATE biens SET " . implode(', ', $sets) . " WHERE id = :id")
            ->execute($params);
        return ['ok' => true, 'missing' => []];
    } catch (Throwable $e) {
        error_log('[bien_validator] validate UPDATE: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erreur SQL', 'missing' => []];
    }
}

/**
 * Dé-valide un bien (repasse à brouillon) pour permettre la modification
 * d'adresse ou de propriétaire.
 */
function bien_validator_invalidate(PDO $pdo, int $idBien): array
{
    try {
        $pdo->prepare("UPDATE biens SET statut_bien = 'brouillon', date_modification = NOW() WHERE id = ?")
            ->execute([$idBien]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[bien_validator] invalidate UPDATE: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erreur SQL'];
    }
}
