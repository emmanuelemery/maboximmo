<?php
declare(strict_types=1);

/**
 * Charge les rubriques actives pour une société donnée.
 * Si la société n'a pas de config → retourne tout le catalogue global (societe_id IS NULL).
 * Si elle a une config → applique actif/ordre de sa config.
 * Inclut aussi ses propres rubriques personnalisées (societe_id = $societeId).
 */
function catalog_get_rubriques(PDO $pdo, int $societeId): array
{
    // Vérifier si la société a une config
    $stmtCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM rh_entretien_societe_rubriques WHERE societe_id = ?"
    );
    $stmtCheck->execute([$societeId]);
    $hasConfig = (int)$stmtCheck->fetchColumn() > 0;

    if (!$hasConfig) {
        // Pas de config : retourner tout le catalogue global + rubriques propres à la société
        $stmt = $pdo->prepare(
            "SELECT r.*, NULL AS soc_actif, NULL AS soc_ordre
             FROM rh_entretien_rubriques r
             WHERE r.actif = 1
               AND (r.societe_id IS NULL OR r.societe_id = ?)
             ORDER BY r.ordre"
        );
        $stmt->execute([$societeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Config existante : joindre avec la table de config
    $stmt = $pdo->prepare(
        "SELECT r.*,
                sr.actif AS soc_actif,
                sr.ordre AS soc_ordre
         FROM rh_entretien_rubriques r
         LEFT JOIN rh_entretien_societe_rubriques sr
               ON sr.rubrique_id = r.id AND sr.societe_id = ?
         WHERE r.actif = 1
           AND (r.societe_id IS NULL OR r.societe_id = ?)
           AND (sr.actif IS NULL OR sr.actif = 1)
         ORDER BY COALESCE(sr.ordre, r.ordre)"
    );
    $stmt->execute([$societeId, $societeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Charge les questions actives pour une société et une rubrique donnée.
 */
function catalog_get_questions(PDO $pdo, int $societeId, int $rubriqueId): array
{
    $stmtCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM rh_entretien_societe_questions sq
         JOIN rh_entretien_questions_user q ON q.id = sq.question_id
         WHERE sq.societe_id = ? AND q.rubrique_id = ?"
    );
    $stmtCheck->execute([$societeId, $rubriqueId]);
    $hasConfig = (int)$stmtCheck->fetchColumn() > 0;

    if (!$hasConfig) {
        $stmt = $pdo->prepare(
            "SELECT q.*, NULL AS soc_actif, NULL AS soc_ordre
             FROM rh_entretien_questions_user q
             WHERE q.actif = 1
               AND q.rubrique_id = ?
               AND (q.societe_id IS NULL OR q.societe_id = ?)
             ORDER BY q.ordre"
        );
        $stmt->execute([$rubriqueId, $societeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare(
        "SELECT q.*,
                sq.actif AS soc_actif,
                sq.ordre AS soc_ordre
         FROM rh_entretien_questions_user q
         LEFT JOIN rh_entretien_societe_questions sq
               ON sq.question_id = q.id AND sq.societe_id = ?
         WHERE q.actif = 1
           AND q.rubrique_id = ?
           AND (q.societe_id IS NULL OR q.societe_id = ?)
           AND (sq.actif IS NULL OR sq.actif = 1)
         ORDER BY COALESCE(sq.ordre, q.ordre)"
    );
    $stmt->execute([$societeId, $rubriqueId, $societeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Charge les options actives pour une société et une question donnée.
 */
function catalog_get_options(PDO $pdo, int $societeId, int $questionId): array
{
    $stmtCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM rh_entretien_societe_options so
         JOIN rh_entretien_question_options o ON o.id = so.option_id
         WHERE so.societe_id = ? AND o.question_id = ?"
    );
    $stmtCheck->execute([$societeId, $questionId]);
    $hasConfig = (int)$stmtCheck->fetchColumn() > 0;

    if (!$hasConfig) {
        $stmt = $pdo->prepare(
            "SELECT o.*, NULL AS soc_actif
             FROM rh_entretien_question_options o
             WHERE o.actif = 1
               AND o.question_id = ?
               AND (o.societe_id IS NULL OR o.societe_id = ?)
             ORDER BY o.ordre"
        );
        $stmt->execute([$questionId, $societeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare(
        "SELECT o.*,
                so.actif AS soc_actif
         FROM rh_entretien_question_options o
         LEFT JOIN rh_entretien_societe_options so
               ON so.option_id = o.id AND so.societe_id = ?
         WHERE o.actif = 1
           AND o.question_id = ?
           AND (o.societe_id IS NULL OR o.societe_id = ?)
           AND (so.actif IS NULL OR so.actif = 1)
         ORDER BY o.ordre"
    );
    $stmt->execute([$societeId, $questionId, $societeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Charge TOUTES les questions/rubriques/options pour un entretien.
 * Retourne: ['rubriques' => [...], 'questions_par_rubrique' => [...], 'options_par_question' => [...]]
 */
function catalog_load_for_entretien(PDO $pdo, int $societeId): array
{
    $rubriques = catalog_get_rubriques($pdo, $societeId);

    $questionsParRubrique = [];
    $optionsParQuestion   = [];

    foreach ($rubriques as $rub) {
        $rubriqueId = (int)$rub['id'];
        $rubOrdre   = (int)$rub['ordre'];
        $questions  = catalog_get_questions($pdo, $societeId, $rubriqueId);
        $questionsParRubrique[$rubOrdre] = $questions;

        foreach ($questions as $q) {
            $qId = (int)$q['id'];
            $optionsParQuestion[$qId] = catalog_get_options($pdo, $societeId, $qId);
        }
    }

    return [
        'rubriques'            => $rubriques,
        'questions_par_rubrique' => $questionsParRubrique,
        'options_par_question'   => $optionsParQuestion,
    ];
}

/**
 * Sauvegarde la config d'une société (activer/désactiver rubrique).
 */
function catalog_set_rubrique(PDO $pdo, int $societeId, int $rubriqueId, bool $actif, ?int $ordre = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO rh_entretien_societe_rubriques (societe_id, rubrique_id, actif, ordre)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE actif = VALUES(actif), ordre = COALESCE(VALUES(ordre), ordre)"
    );
    $stmt->execute([$societeId, $rubriqueId, $actif ? 1 : 0, $ordre]);
}

/**
 * Sauvegarde la config d'une société (activer/désactiver question).
 */
function catalog_set_question(PDO $pdo, int $societeId, int $questionId, bool $actif, ?int $ordre = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO rh_entretien_societe_questions (societe_id, question_id, actif, ordre)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE actif = VALUES(actif), ordre = COALESCE(VALUES(ordre), ordre)"
    );
    $stmt->execute([$societeId, $questionId, $actif ? 1 : 0, $ordre]);
}

/**
 * Retourne les suggestions disponibles pour une société
 * (questions d'autres sociétés avec is_suggestion=1, non encore adoptées).
 */
function catalog_get_suggestions(PDO $pdo, int $societeId): array
{
    $stmt = $pdo->prepare(
        "SELECT q.*, r.nom AS rubrique_nom
         FROM rh_entretien_questions_user q
         LEFT JOIN rh_entretien_rubriques r ON r.id = q.rubrique_id
         WHERE q.is_suggestion = 1
           AND q.actif = 1
           AND (q.societe_id IS NULL OR q.societe_id != ?)
           AND q.id NOT IN (
               SELECT sq.question_id
               FROM rh_entretien_societe_questions sq
               WHERE sq.societe_id = ?
           )
         ORDER BY r.ordre, q.ordre"
    );
    $stmt->execute([$societeId, $societeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Adopte une suggestion : copie la question dans la config de la société (activer dans sa liste).
 */
function catalog_adopt_suggestion(PDO $pdo, int $societeId, int $questionId): void
{
    // Vérifier que la question est bien une suggestion
    $stmtCheck = $pdo->prepare(
        "SELECT id FROM rh_entretien_questions_user WHERE id = ? AND is_suggestion = 1 AND actif = 1"
    );
    $stmtCheck->execute([$questionId]);
    if (!$stmtCheck->fetch()) {
        throw new RuntimeException("Question introuvable ou non disponible en suggestion.");
    }

    $stmt = $pdo->prepare(
        "INSERT INTO rh_entretien_societe_questions (societe_id, question_id, actif)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE actif = 1"
    );
    $stmt->execute([$societeId, $questionId]);
}
