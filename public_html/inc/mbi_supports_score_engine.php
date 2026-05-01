<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_score_engine.php — Orchestration moteur de score commercial
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Module : Ma Box Communication (mbi_supports)
 *
 * Point d'entrée du moteur de score :
 *   1. Charge le bien + ses photos (avec contrôle de scope multi-tenant)
 *   2. Invoque la grille déterministe (mbi_supports_score_rules.php)
 *   3. Si mode hybride|ia → appelle Claude (mbi_supports_score_ia.php)
 *   4. INSERT dans bien_score_commercial (toujours une nouvelle ligne)
 *   5. Renvoie le résultat
 *
 * En cas d'erreur IA, fallback automatique sur le déterministe seul
 * (statut='calcule', confidence_score=null).
 *
 * En cas d'erreur totale, INSERT avec statut='erreur' et derniere_erreur
 * rempli — pas de blocage utilisateur, l'historique reste auditable.
 *
 * API publique :
 *   mbi_supports_score_calculer(int $id_bien, int $id_user, string $mode='hybride'): array
 *     → { ok:bool, score_id:int, score:?int, statut:string, erreur:?string, data:array }
 *
 *   mbi_supports_score_get_dernier(int $id_bien): ?array
 *   mbi_supports_score_historique(int $id_bien, int $limit=10): array
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/mbi_supports_score_rules.php';
require_once __DIR__ . '/mbi_supports_score_ia.php';

if (!function_exists('mbi_supports_score_calculer')) {

    /**
     * @param int    $id_bien
     * @param int    $id_user        User déclencheur
     * @param string $mode           'deterministe' | 'ia' | 'hybride'
     * @return array{ok:bool, score_id:int, score:?int, statut:string, erreur:?string, data:array}
     */
    function mbi_supports_score_calculer(int $id_bien, int $id_user, string $mode = 'hybride'): array
    {
        $modesValides = ['deterministe', 'ia', 'hybride'];
        if (!in_array($mode, $modesValides, true)) $mode = 'hybride';

        $pdo = $GLOBALS['pdo'] ?? db();

        // 1. Charge le bien + scope multi-tenant
        $bien = mbi_supports_score_load_bien($pdo, $id_bien);
        if ($bien === null) {
            return [
                'ok' => false, 'score_id' => 0, 'score' => null,
                'statut' => 'erreur', 'erreur' => 'bien_introuvable_ou_hors_scope',
                'data' => [],
            ];
        }

        // 2. Charge les photos
        $photos = mbi_supports_score_load_photos($pdo, $id_bien);

        // 3. Pré-INSERT en mode draft (audit même si plantage)
        $idSociete = (int)($bien['id_societe'] ?? 0);
        $idAgence  = (int)($bien['id_agence']  ?? 0);

        try {
            $scoreId = mbi_supports_score_insert_draft($pdo, $id_bien, $id_user, $idSociete, $idAgence, $mode);
        } catch (Throwable $e) {
            error_log('[mbi_supports_score INSERT draft] ' . $e->getMessage());
            return [
                'ok' => false, 'score_id' => 0, 'score' => null,
                'statut' => 'erreur', 'erreur' => 'insert_draft_fail: ' . $e->getMessage(),
                'data' => [],
            ];
        }

        // 4. Calcul déterministe
        try {
            $deterministe = mbi_supports_rules_calcul($bien, $photos);
        } catch (Throwable $e) {
            error_log('[mbi_supports_score rules] ' . $e->getMessage());
            mbi_supports_score_marquer_erreur($pdo, $scoreId, 'rules_engine: ' . $e->getMessage());
            return [
                'ok' => false, 'score_id' => $scoreId, 'score' => null,
                'statut' => 'erreur', 'erreur' => 'rules_engine_fail',
                'data' => [],
            ];
        }

        // 5. IA (si demandé)
        $iaData       = null;
        $iaModele     = null;
        $iaCout       = 0;
        $iaConfidence = null;
        $iaErreur     = null;

        if ($mode === 'ia' || $mode === 'hybride') {
            $ia = mbi_supports_ia_analyser($bien, $photos, $deterministe);
            if ($ia['ok']) {
                $iaData       = $ia['data'];
                $iaModele     = $ia['modele'];
                $iaCout       = (int)$ia['cout_centimes'];
                $iaConfidence = $ia['confidence'];
            } else {
                $iaErreur = $ia['erreur'] ?? 'ia_inconnue';
                error_log('[mbi_supports_score IA fallback] ' . $iaErreur);
                // Fallback automatique sur déterministe seul — pas de blocage
            }
        }

        // 6. UPDATE final
        try {
            mbi_supports_score_finaliser($pdo, $scoreId, $deterministe, $iaData, [
                'modele_ia'        => $iaModele ?? MBI_SUPPORTS_SCORE_MODEL,
                'cout_centimes'    => $iaCout,
                'confidence_score' => $iaConfidence,
                'derniere_erreur'  => $iaErreur,
            ]);
        } catch (Throwable $e) {
            error_log('[mbi_supports_score finaliser] ' . $e->getMessage());
            mbi_supports_score_marquer_erreur($pdo, $scoreId, 'finaliser: ' . $e->getMessage());
            return [
                'ok' => false, 'score_id' => $scoreId, 'score' => null,
                'statut' => 'erreur', 'erreur' => 'finaliser_fail',
                'data' => [],
            ];
        }

        return [
            'ok'       => true,
            'score_id' => $scoreId,
            'score'    => (int)$deterministe['total'],
            'statut'   => 'calcule',
            'erreur'   => $iaErreur, // peut être non-null si IA a fail mais déterministe a réussi
            'data'     => [
                'breakdown'  => $deterministe['breakdown'],
                'signaux'    => $deterministe['signaux'],
                'ia'         => $iaData,
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Récupération du dernier score / historique
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_score_get_dernier')) {
    function mbi_supports_score_get_dernier(int $id_bien): ?array
    {
        $pdo = $GLOBALS['pdo'] ?? db();
        try {
            $st = $pdo->prepare("
                SELECT * FROM bien_score_commercial
                WHERE id_bien = :id AND statut = 'calcule'
                ORDER BY date_calcul DESC, id DESC
                LIMIT 1
            ");
            $st->execute([':id' => $id_bien]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[mbi_supports_score get_dernier] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mbi_supports_score_historique')) {
    function mbi_supports_score_historique(int $id_bien, int $limit = 10): array
    {
        $pdo = $GLOBALS['pdo'] ?? db();
        try {
            $limit = max(1, min(100, $limit));
            $st = $pdo->prepare("
                SELECT id, score, statut, calcul_mode, niveau_urgence, angle_recommande,
                       confidence_score, modele_ia, cout_ia_centimes, date_calcul, derniere_erreur
                FROM bien_score_commercial
                WHERE id_bien = :id
                ORDER BY date_calcul DESC, id DESC
                LIMIT {$limit}
            ");
            $st->execute([':id' => $id_bien]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[mbi_supports_score historique] ' . $e->getMessage());
            return [];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers internes
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_score_load_bien')) {
    /**
     * Charge le bien avec contrôle de scope multi-tenant (cf. feedback_filtrage_multi_tenant).
     * Super admin (role=1) bypass le filtre société.
     * Returns null si bien introuvable ou hors scope.
     */
    function mbi_supports_score_load_bien(PDO $pdo, int $id_bien): ?array
    {
        $roleId    = (int)($_SESSION['id_role'] ?? 0);
        $idSocSess = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
        $isSuperAdmin = ($roleId === 1);

        try {
            $st = $pdo->prepare("SELECT * FROM biens WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id_bien]);
            $bien = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bien) return null;

            // Scope check : un user standard doit avoir id_societe = sa session
            if (!$isSuperAdmin && $idSocSess !== null) {
                $idSocBien = (int)($bien['id_societe'] ?? 0);
                if ($idSocBien > 0 && $idSocBien !== $idSocSess) {
                    return null; // hors scope
                }
            }
            return $bien;
        } catch (Throwable $e) {
            error_log('[mbi_supports_score load_bien] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mbi_supports_score_load_photos')) {
    function mbi_supports_score_load_photos(PDO $pdo, int $id_bien): array
    {
        try {
            $st = $pdo->prepare("SELECT * FROM bien_photos WHERE id_bien = :id ORDER BY id ASC");
            $st->execute([':id' => $id_bien]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[mbi_supports_score load_photos] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mbi_supports_score_insert_draft')) {
    function mbi_supports_score_insert_draft(PDO $pdo, int $id_bien, int $id_user, int $id_societe, int $id_agence, string $mode): int
    {
        $st = $pdo->prepare("
            INSERT INTO bien_score_commercial
              (id_bien, id_user, id_societe, id_agence, calcul_mode, statut,
               prompt_version, modele_ia, date_calcul)
            VALUES
              (:bien, :usr, :soc, :ag, :mode, 'draft',
               :pv, :mod, NOW())
        ");
        $st->execute([
            ':bien' => $id_bien,
            ':usr'  => $id_user,
            ':soc'  => $id_societe,
            ':ag'   => $id_agence,
            ':mode' => $mode,
            ':pv'   => MBI_SUPPORTS_SCORE_PROMPT_VERSION,
            ':mod'  => MBI_SUPPORTS_SCORE_MODEL,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('mbi_supports_score_finaliser')) {
    /**
     * @param array  $deterministe Sortie de mbi_supports_rules_calcul
     * @param ?array $iaData       Données IA normalisées (peut être null si fallback)
     * @param array  $meta         { modele_ia, cout_centimes, confidence_score, derniere_erreur }
     */
    function mbi_supports_score_finaliser(PDO $pdo, int $score_id, array $deterministe, ?array $iaData, array $meta): void
    {
        $score = (int)$deterministe['total'];

        $angle = $iaData['angle_recommande'] ?? null;
        $heroId = $iaData['photo_hero_id']   ?? null;
        $urgence = $iaData['niveau_urgence'] ?? null;

        $pointsForts   = isset($iaData['points_forts'])   ? json_encode($iaData['points_forts'],   JSON_UNESCAPED_UNICODE) : null;
        $pointsFaibles = isset($iaData['points_faibles']) ? json_encode($iaData['points_faibles'], JSON_UNESCAPED_UNICODE) : null;

        $breakdown = json_encode($deterministe['breakdown'], JSON_UNESCAPED_UNICODE);
        $snapshot  = json_encode([
            'signaux'        => $deterministe['signaux'] ?? [],
            'ia_commentaire' => $iaData['commentaire'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $st = $pdo->prepare("
            UPDATE bien_score_commercial SET
              statut              = 'calcule',
              score               = :score,
              score_breakdown_json= :bd,
              points_forts_json   = :pf,
              points_faibles_json = :pfa,
              angle_recommande    = :angle,
              photo_hero_id       = :hero,
              niveau_urgence      = :urg,
              data_snapshot_json  = :snap,
              confidence_score    = :conf,
              modele_ia           = :mod,
              cout_ia_centimes    = :cout,
              derniere_erreur     = :err
            WHERE id = :id
        ");
        $st->execute([
            ':score' => $score,
            ':bd'    => $breakdown,
            ':pf'    => $pointsForts,
            ':pfa'   => $pointsFaibles,
            ':angle' => $angle,
            ':hero'  => $heroId,
            ':urg'   => $urgence,
            ':snap'  => $snapshot,
            ':conf'  => $meta['confidence_score'] ?? null,
            ':mod'   => $meta['modele_ia'] ?? MBI_SUPPORTS_SCORE_MODEL,
            ':cout'  => (int)($meta['cout_centimes'] ?? 0),
            ':err'   => $meta['derniere_erreur'] ?? null,
            ':id'    => $score_id,
        ]);
    }
}

if (!function_exists('mbi_supports_score_marquer_erreur')) {
    function mbi_supports_score_marquer_erreur(PDO $pdo, int $score_id, string $msg): void
    {
        try {
            $st = $pdo->prepare("UPDATE bien_score_commercial SET statut='erreur', derniere_erreur=:e WHERE id=:id");
            $st->execute([':e' => mb_substr($msg, 0, 65000), ':id' => $score_id]);
        } catch (Throwable $e) {
            error_log('[mbi_supports_score marquer_erreur] ' . $e->getMessage());
        }
    }
}
