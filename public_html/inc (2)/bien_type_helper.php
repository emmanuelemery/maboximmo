<?php
declare(strict_types=1);

/**
 * BIEN TYPE HELPER — résolution code → (id_bien_type, id_type_bien_legacy)
 *
 * Suite à la migration 20260430_bien_types, deux colonnes coexistent sur biens :
 *   - `id_bien_type`   → FK vers la nouvelle table `bien_types` (référentiel
 *                        unifié LBC/SeLoger/FNAIM, source de vérité)
 *   - `id_type_bien`   → FK historique vers `types_bien_legacy` (préservée
 *                        pour permettre un rollback du code sans migration BDD)
 *
 * Toutes les écritures du formulaire bien doivent passer par bien_type_resolve()
 * pour alimenter les DEUX colonnes en cohérence. Lecture UI → bien_types.
 *
 * Pour les codes ajoutés en bien_types qui n'existent pas en legacy (studio,
 * duplex, triplex, villa, mas, peniche, ferme, chalet, chateau, boutique,
 * atelier, cave, terrain_agricole), on retombe sur le code legacy le plus
 * proche sémantiquement pour rester compatible avec la FK historique.
 */

/**
 * Mapping de fallback : code bien_types → code types_bien_legacy équivalent.
 * Utilisé uniquement quand le code n'a pas d'entrée directe dans la legacy.
 */
const BIEN_TYPE_LEGACY_FALLBACK = [
    // Sous-types d'appartement absents en legacy
    'studio'           => 'appartement',
    'duplex'           => 'appartement',
    'triplex'          => 'appartement',
    'loft'             => 'appartement',
    // Sous-types de maison absents en legacy
    'villa'            => 'maison',
    'peniche'          => 'maison',
    'mas'              => 'maison',
    'ferme'            => 'maison',
    'chalet'           => 'maison',
    'chateau'          => 'maison',
    // Pro / commerce
    'boutique'         => 'local_commercial',
    'atelier'          => 'local_activite',
    // Annexes
    'cave'             => 'box',
    // Terrains
    'terrain_agricole' => 'terrain',
];

/**
 * Résout un code de type de bien vers les deux ids à écrire dans `biens`.
 *
 * @return array{id_bien_type:?int, id_type_bien:?int}
 */
function bien_type_resolve(PDO $pdo, string $code): array
{
    $code = strtolower(trim($code));
    if ($code === '') {
        return ['id_bien_type' => null, 'id_type_bien' => null];
    }

    // 1. Nouvelle table (source de vérité)
    $idBienType = null;
    try {
        $st = $pdo->prepare("SELECT id FROM bien_types WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $v = (int)($st->fetchColumn() ?: 0);
        $idBienType = $v > 0 ? $v : null;
    } catch (Throwable) {}

    // 2. Legacy direct (pour les 13 codes communs)
    $idTypeBien = null;
    try {
        $st = $pdo->prepare("SELECT id FROM types_bien_legacy WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $v = (int)($st->fetchColumn() ?: 0);
        $idTypeBien = $v > 0 ? $v : null;
    } catch (Throwable) {
        // types_bien_legacy peut ne pas exister sur des instances très anciennes
        // (avant la migration). Dans ce cas on essaie types_bien (avant rename).
        try {
            $st = $pdo->prepare("SELECT id FROM types_bien WHERE code = ? LIMIT 1");
            $st->execute([$code]);
            $v = (int)($st->fetchColumn() ?: 0);
            $idTypeBien = $v > 0 ? $v : null;
        } catch (Throwable) {}
    }

    // 3. Fallback legacy via mapping sémantique
    if ($idTypeBien === null && isset(BIEN_TYPE_LEGACY_FALLBACK[$code])) {
        $legacyCode = BIEN_TYPE_LEGACY_FALLBACK[$code];
        try {
            $st = $pdo->prepare("SELECT id FROM types_bien_legacy WHERE code = ? LIMIT 1");
            $st->execute([$legacyCode]);
            $v = (int)($st->fetchColumn() ?: 0);
            $idTypeBien = $v > 0 ? $v : null;
        } catch (Throwable) {
            try {
                $st = $pdo->prepare("SELECT id FROM types_bien WHERE code = ? LIMIT 1");
                $st->execute([$legacyCode]);
                $v = (int)($st->fetchColumn() ?: 0);
                $idTypeBien = $v > 0 ? $v : null;
            } catch (Throwable) {}
        }
    }

    return ['id_bien_type' => $idBienType, 'id_type_bien' => $idTypeBien];
}

/**
 * Liste tous les types actifs ordonnés (pour les dropdowns UI).
 *
 * @return array<int, array{id:int, code:string, libelle:string, categorie:string, icone:?string}>
 */
function bien_type_list_active(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT id, code, libelle, categorie, icone
             FROM bien_types
             WHERE actif = 1
             ORDER BY ordre_affichage ASC, libelle ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Throwable) {
        return [];
    }
}

/**
 * Récupère le libellé d'un bien_type par id (pour affichage).
 */
function bien_type_label_by_id(PDO $pdo, int $id): string
{
    if ($id <= 0) return '';
    try {
        $st = $pdo->prepare("SELECT libelle FROM bien_types WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

/**
 * Récupère le code d'un bien_type par id.
 */
function bien_type_code_by_id(PDO $pdo, int $id): string
{
    if ($id <= 0) return '';
    try {
        $st = $pdo->prepare("SELECT code FROM bien_types WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

/**
 * Renvoie l'id du type par défaut (premier actif dans l'ordre d'affichage,
 * en général "appartement"). Utilisé pour la création de brouillons.
 */
function bien_type_default_id(PDO $pdo): int
{
    try {
        $v = (int)$pdo->query(
            "SELECT id FROM bien_types WHERE actif = 1 ORDER BY ordre_affichage ASC, id ASC LIMIT 1"
        )->fetchColumn();
        return $v > 0 ? $v : 0;
    } catch (Throwable) {
        return 0;
    }
}
