<?php
declare(strict_types=1);

/**
 * bien_auto_activate.php — Auto-activation d'un bien brouillon
 *
 * Règle métier (2026-04-20) : un bien passe automatiquement du statut
 * `brouillon` à `actif` dès qu'il dispose :
 *   - d'une adresse (biens.adresse_1 non vide)
 *   - d'un propriétaire (biens.id_proprietaire > 0)
 *
 * Cette bascule est déclenchée côté serveur par les endpoints qui
 * modifient le bien (bien_autosave, dpe_diag_update, tiers_create +
 * liaison) pour offrir une UX fluide : pas de clic manuel requis sur
 * le statut.
 *
 * L'auto-activation ne s'applique QUE si le statut courant est
 * 'brouillon' — on ne touche pas aux biens déjà actifs / archivés /
 * vendus / loués.
 */

if (!function_exists('bien_maybe_activate')) {
    /**
     * @param PDO $pdo
     * @param int $bienId
     * @return bool true si le bien vient de passer en 'actif' via cette fonction
     *
     * Lors du passage brouillon -> actif, on en profite pour normaliser :
     *  - reference_bien : si NULL ou commence par "TMP-" -> on génère une
     *    vraie référence via ref_generate_bien (pattern agence, ex:
     *    APT-LYO-26-0042-JDU) qui consomme une séquence propre
     *  - designation : si commence par "Brouillon créé le" -> remplace par
     *    "Bien créé le <date>" (l'utilisateur peut ensuite la customiser)
     */
    function bien_maybe_activate(PDO $pdo, int $bienId): bool {
        if ($bienId <= 0) return false;
        try {
            $st = $pdo->prepare("
                SELECT b.statut_bien, b.adresse_1, b.id_proprietaire,
                       b.reference_bien, b.designation, b.id_agence, b.ville,
                       tb.code AS type_code
                FROM biens b
                LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
                WHERE b.id = ?
                LIMIT 1
            ");
            $st->execute([$bienId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;

            $statut  = (string)($row['statut_bien'] ?? '');
            $adresse = trim((string)($row['adresse_1'] ?? ''));
            $proprio = (int)($row['id_proprietaire'] ?? 0);

            if ($statut !== 'brouillon') return false;   // Ne touche qu'aux brouillons
            if ($adresse === '')        return false;
            if ($proprio <= 0)          return false;

            // ── Normalisation reference_bien ────────────────────────
            //    Si ref temporaire (TMP-...) ou NULL, on génère la vraie ref
            //    via le pattern de l'agence. Sinon on laisse.
            $currentRef = (string)($row['reference_bien'] ?? '');
            $newRef     = null;
            $isTempRef  = ($currentRef === '' || str_starts_with($currentRef, 'TMP-'));
            if ($isTempRef && (int)$row['id_agence'] > 0) {
                try {
                    require_once __DIR__ . '/ref_generator.php';
                    $generated = ref_generate_bien($pdo, [
                        'id_agence'      => (int)$row['id_agence'],
                        'type_bien_code' => (string)($row['type_code'] ?? ''),
                        'ville'          => (string)($row['ville']     ?? ''),
                        'user'           => [
                            'nom'    => (string)($_SESSION['nom']    ?? ''),
                            'prenom' => (string)($_SESSION['prenom'] ?? ''),
                        ],
                    ]);
                    if ($generated !== '') $newRef = $generated;
                } catch (Throwable $e) {
                    error_log('[bien_maybe_activate ref_generate] ' . $e->getMessage());
                }
            }

            // ── Normalisation designation ───────────────────────────
            $currentDes = (string)($row['designation'] ?? '');
            $newDes     = null;
            if (str_starts_with($currentDes, 'Brouillon créé le')) {
                $newDes = 'Bien créé le ' . date('d/m/Y H:i');
            }

            // ── UPDATE groupé ────────────────────────────────────────
            $sets   = ["statut_bien = 'actif'", 'date_modification = NOW()'];
            $params = [];
            if ($newRef !== null) {
                $sets[]           = 'reference_bien = :ref';
                $params[':ref']   = $newRef;
            }
            if ($newDes !== null) {
                $sets[]           = 'designation = :des';
                $params[':des']   = $newDes;
            }
            $params[':id'] = $bienId;

            $sql = "UPDATE biens SET " . implode(', ', $sets)
                 . " WHERE id = :id AND statut_bien = 'brouillon'";
            $pdo->prepare($sql)->execute($params);

            return true;
        } catch (Throwable $e) {
            error_log('[bien_maybe_activate] ' . $e->getMessage());
            return false;
        }
    }
}
