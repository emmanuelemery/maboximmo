<?php
/**
 * inc/immeuble_link.php — Résolution anti-doublon d'un immeuble depuis une adresse.
 *
 * Reprend la logique canonique de api/bien_autosave.php (modal d'adresse Google) :
 *   1. immeuble explicitement sélectionné dans le modal → on le réutilise (zéro doublon).
 *   2. sinon, recherche par adresse normalisée (adresse_1 + CP + ville) → réutilise si trouvé.
 *   3. sinon seulement, création d'un nouvel immeuble.
 *
 * → garantit qu'on ne crée JAMAIS un 2e immeuble pour une adresse déjà connue.
 */

declare(strict_types=1);

if (!function_exists('immeuble_resolve')) {
    /**
     * @param array $o {
     *   id_immeuble_selected?: int,   // id retourné par le modal (immeuble connu)
     *   adresse_1: string, adresse_2?: string, code_postal?: string, ville?: string,
     *   pays?: string, latitude?: float|null, longitude?: float|null,
     *   google_place_id?: string|null,
     *   id_societe?: int|null, id_agence?: int|null,
     * }
     * @return int id_immeuble (réutilisé ou créé), 0 si adresse insuffisante.
     */
    function immeuble_resolve(PDO $pdo, array $o): int {
        $adresse1 = trim((string)($o['adresse_1'] ?? ''));
        $cp       = trim((string)($o['code_postal'] ?? ''));
        $ville    = trim((string)($o['ville'] ?? ''));
        $selected = (int)($o['id_immeuble_selected'] ?? 0);

        // 1. Immeuble existant choisi dans le modal → réutilisation directe.
        if ($selected > 0) {
            $chk = $pdo->prepare("SELECT id FROM immeubles WHERE id = ? LIMIT 1");
            $chk->execute([$selected]);
            if ((int)$chk->fetchColumn() === $selected) return $selected;
        }

        if ($adresse1 === '') return 0;

        // 1bis. Anti-doublon par google_place_id (si fourni et colonne dispo).
        $placeId = trim((string)($o['google_place_id'] ?? ''));
        if ($placeId !== '') {
            try {
                $st = $pdo->prepare("SELECT id FROM immeubles WHERE google_place_id = ? LIMIT 1");
                $st->execute([$placeId]);
                $id = (int)$st->fetchColumn();
                if ($id > 0) return $id;
            } catch (Throwable) { /* colonne absente : on ignore */ }
        }

        // 2. Anti-doublon par adresse normalisée (adresse_1 + CP + ville).
        $st = $pdo->prepare("
            SELECT id FROM immeubles
             WHERE LOWER(TRIM(adresse_1)) = LOWER(TRIM(?))
               AND COALESCE(TRIM(code_postal),'') = COALESCE(TRIM(?),'')
               AND LOWER(TRIM(COALESCE(ville,''))) = LOWER(TRIM(?))
             LIMIT 1");
        $st->execute([$adresse1, $cp, $ville]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;

        // 3. Création (immeuble réellement nouveau).
        $lat = isset($o['latitude']) && $o['latitude'] !== '' ? (float)$o['latitude'] : null;
        $lng = isset($o['longitude']) && $o['longitude'] !== '' ? (float)$o['longitude'] : null;
        $hasPlaceCol = false;
        try { $hasPlaceCol = (bool)$pdo->query("SHOW COLUMNS FROM immeubles LIKE 'google_place_id'")->fetchColumn(); } catch (Throwable) {}

        $cols = ['id_societe','id_agence','adresse_1','adresse_2','code_postal','ville','pays','latitude','longitude','date_creation','date_modification'];
        $vals = [
            $o['id_societe'] ?? null,
            $o['id_agence'] ?? null,
            $adresse1,
            ($o['adresse_2'] ?? '') ?: null,
            $cp ?: null,
            $ville ?: null,
            ($o['pays'] ?? '') ?: 'France',
            $lat,
            $lng,
        ];
        // NOW() pour les deux dates : on ajoute en SQL, pas en param.
        $place = '?,?,?,?,?,?,?,?,?,NOW(),NOW()';
        if ($hasPlaceCol && $placeId !== '') { $cols[] = 'google_place_id'; $vals[] = $placeId; $place .= ',?'; }

        $sql = "INSERT INTO immeubles (`" . implode('`,`', $cols) . "`) VALUES ($place)";
        $pdo->prepare($sql)->execute($vals);
        return (int)$pdo->lastInsertId();
    }
}
