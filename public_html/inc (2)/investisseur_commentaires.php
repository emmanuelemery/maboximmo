<?php
declare(strict_types=1);

/**
 * inc/investisseur_commentaires.php
 *
 * CRUD + intégration texte pour les commentaires orientés.
 *
 * Utilisation côté moteur d'interprétation :
 *   $comments = inv_comm_fetch($pdo, ['id_analyse' => $idAnalyse]);
 *   $overlay  = inv_comm_compile_overlay($comments);
 *   → texte à injecter dans la synthèse / les argumentaires
 *
 * Usage côté IA (prompt) :
 *   $directives = inv_comm_ia_directives($comments);
 *   → liste de directives (catégorie 'instruction_ia') destinées au prompt
 */

require_once __DIR__ . '/investisseur_helpers.php';

if (!function_exists('inv_comm_categories')) {
    function inv_comm_categories(): array {
        return [
            'force'          => 'Force',
            'faiblesse'      => 'Faiblesse',
            'risque'         => 'Risque',
            'opportunite'    => 'Opportunité',
            'instruction_ia' => 'Instruction IA (prompt)',
            'neutre'         => 'Note neutre',
        ];
    }
}

if (!function_exists('inv_comm_orientations')) {
    function inv_comm_orientations(): array {
        return ['positif' => 'Positif', 'neutre' => 'Neutre', 'negatif' => 'Négatif'];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CRUD
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_comm_save')) {
    function inv_comm_save(PDO $pdo, array $data, ?int $id = null): int {
        $sc = inv_current_scope();

        $clean = [
            'categorie'     => in_array($data['categorie'] ?? '', array_keys(inv_comm_categories()), true) ? $data['categorie'] : 'neutre',
            'orientation'   => in_array($data['orientation'] ?? '', array_keys(inv_comm_orientations()), true) ? $data['orientation'] : 'neutre',
            'poids'         => max(1, min(5, (int)($data['poids'] ?? 3))),
            'titre'         => trim((string)($data['titre'] ?? '')),
            'contenu'       => trim((string)($data['contenu'] ?? '')),
            'id_analyse'    => !empty($data['id_analyse']) ? (int)$data['id_analyse'] : null,
            'id_bien'       => !empty($data['id_bien'])    ? (int)$data['id_bien']    : null,
            'actif'         => !empty($data['actif']) ? 1 : (isset($data['actif']) && $data['actif'] === '0' ? 0 : 1),
        ];
        if ($clean['contenu'] === '') throw new RuntimeException("Le contenu du commentaire est requis.");
        if (!$clean['id_analyse'] && !$clean['id_bien']) {
            throw new RuntimeException("Le commentaire doit être rattaché à une analyse OU à un bien.");
        }

        if ($id === null) {
            $clean['id_societe'] = $sc['id_societe'];
            $clean['id_agence']  = $sc['id_agence'];
            $clean['id_user']    = $sc['id_user'];
            $cols = array_keys($clean);
            $ph   = array_map(fn($c) => ':' . $c, $cols);
            $sql = "INSERT INTO investisseur_commentaires (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
            $st  = $pdo->prepare($sql);
            foreach ($clean as $k => $v) $st->bindValue(':' . $k, $v);
            $st->execute();
            return (int)$pdo->lastInsertId();
        }

        // Update — scope verifié via inv_comm_load
        $existing = inv_comm_load($pdo, $id);
        if (!$existing) throw new RuntimeException("Commentaire introuvable ou hors périmètre.");

        $sets = [];
        foreach (array_keys($clean) as $c) $sets[] = "`$c` = :$c";
        $sql = "UPDATE investisseur_commentaires SET " . implode(',', $sets) . " WHERE id = :id_row";
        $st  = $pdo->prepare($sql);
        foreach ($clean as $k => $v) $st->bindValue(':' . $k, $v);
        $st->bindValue(':id_row', $id, PDO::PARAM_INT);
        $st->execute();
        return $id;
    }
}

if (!function_exists('inv_comm_load')) {
    function inv_comm_load(PDO $pdo, int $id): ?array {
        $scope = inv_scope_where();
        $sql = "SELECT * FROM investisseur_commentaires WHERE id = :id AND " . $scope['sql'] . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        foreach ($scope['params'] as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('inv_comm_fetch')) {
    /**
     * Récupère les commentaires (scope + filtres).
     * $filters : id_analyse, id_bien, categorie, actif (défaut 1), inclure_bien_via_analyse (bool)
     */
    function inv_comm_fetch(PDO $pdo, array $filters = []): array {
        $scope = inv_scope_where();
        $where = [$scope['sql']];
        $params = $scope['params'];

        $cond = [];
        if (!empty($filters['id_analyse'])) {
            $cond[] = 'id_analyse = :f_analyse';
            $params[':f_analyse'] = (int)$filters['id_analyse'];

            // Optionnel : inclure aussi les commentaires attachés au bien source
            if (!empty($filters['inclure_bien_via_analyse'])) {
                $st = $pdo->prepare("SELECT id_bien_source FROM investisseur_analyses WHERE id = :id LIMIT 1");
                $st->bindValue(':id', (int)$filters['id_analyse'], PDO::PARAM_INT);
                $st->execute();
                $ibs = (int)$st->fetchColumn();
                if ($ibs > 0) {
                    $cond[] = 'id_bien = :f_bien_via';
                    $params[':f_bien_via'] = $ibs;
                }
            }
        }
        if (!empty($filters['id_bien'])) {
            $cond[] = 'id_bien = :f_bien';
            $params[':f_bien'] = (int)$filters['id_bien'];
        }
        if (!empty($filters['categorie'])) {
            $cond[] = 'categorie = :f_cat';
            $params[':f_cat'] = $filters['categorie'];
        }
        if (array_key_exists('actif', $filters)) {
            $cond[] = 'actif = :f_actif';
            $params[':f_actif'] = $filters['actif'] ? 1 : 0;
        } else {
            $cond[] = 'actif = 1';
        }

        if (!empty($cond)) {
            // Les conditions d'inclusion sont en OR si on a id_analyse + id_bien_via
            $linkConds = array_filter($cond, fn($c) => strpos($c, 'id_analyse') !== false || strpos($c, 'id_bien') !== false);
            $others    = array_filter($cond, fn($c) => !in_array($c, $linkConds, true));
            if (count($linkConds) >= 2) {
                $where[] = '(' . implode(' OR ', $linkConds) . ')';
            } elseif (!empty($linkConds)) {
                $where[] = implode(' AND ', $linkConds);
            }
            foreach ($others as $o) $where[] = $o;
        }

        $sql = "SELECT * FROM investisseur_commentaires
                WHERE " . implode(' AND ', $where) . "
                ORDER BY poids DESC, created_at DESC";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('inv_comm_delete')) {
    function inv_comm_delete(PDO $pdo, int $id): bool {
        $row = inv_comm_load($pdo, $id);
        if (!$row) return false;
        $st = $pdo->prepare("DELETE FROM investisseur_commentaires WHERE id = :id");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        return $st->execute();
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// INTÉGRATION AU MOTEUR D'INTERPRÉTATION
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_comm_compile_overlay')) {
    /**
     * Compile les commentaires en un overlay textuel rangé par catégorie,
     * prêt à être injecté dans la synthèse ou les argumentaires.
     *
     * Retour :
     *   [
     *     'forces'        => ["Texte 1", "Texte 2"],
     *     'faiblesses'    => [...],
     *     'risques'       => [...],
     *     'opportunites'  => [...],
     *     'notes'         => [...],     // neutre
     *   ]
     */
    function inv_comm_compile_overlay(array $comments): array {
        $out = [
            'forces' => [], 'faiblesses' => [], 'risques' => [], 'opportunites' => [], 'notes' => [],
        ];
        foreach ($comments as $c) {
            if (empty($c['actif'])) continue;
            $line = trim((string)$c['contenu']);
            if ($line === '') continue;
            if (!empty($c['titre'])) $line = '[' . $c['titre'] . '] ' . $line;

            switch ($c['categorie']) {
                case 'force':       $out['forces'][]        = $line; break;
                case 'faiblesse':   $out['faiblesses'][]    = $line; break;
                case 'risque':      $out['risques'][]       = $line; break;
                case 'opportunite': $out['opportunites'][]  = $line; break;
                case 'instruction_ia':
                    // Non injecté dans le texte final — réservé au prompt IA
                    break;
                case 'neutre':
                default:
                    $out['notes'][] = $line;
            }
        }
        return $out;
    }
}

if (!function_exists('inv_comm_merge_into_texts')) {
    /**
     * Applique l'overlay des commentaires sur les blocs forces/faiblesses/risques/opportunites
     * générés par le moteur d'interprétation, en ajoutant les lignes conseiller au-dessus
     * pour qu'elles soient lues en priorité.
     */
    function inv_comm_merge_into_texts(array $baseTexts, array $overlay): array {
        $prefix = function (string $base, array $lines): string {
            if (empty($lines)) return $base;
            $head = "◉ Commentaires conseiller :\n• " . implode("\n• ", $lines);
            return $head . "\n\n" . $base;
        };
        $baseTexts['forces_txt']       = $prefix($baseTexts['forces_txt']       ?? '', $overlay['forces']);
        $baseTexts['faiblesses_txt']   = $prefix($baseTexts['faiblesses_txt']   ?? '', $overlay['faiblesses']);
        $baseTexts['risques_txt']      = $prefix($baseTexts['risques_txt']      ?? '', $overlay['risques']);
        $baseTexts['opportunites_txt'] = $prefix($baseTexts['opportunites_txt'] ?? '', $overlay['opportunites']);

        if (!empty($overlay['notes'])) {
            $notes = "\n\n— Notes terrain —\n• " . implode("\n• ", $overlay['notes']);
            foreach (['argumentaire_prudent','argumentaire_equilibre','argumentaire_offensif'] as $k) {
                if (!empty($baseTexts[$k])) $baseTexts[$k] .= $notes;
            }
        }
        return $baseTexts;
    }
}

if (!function_exists('inv_comm_ia_directives')) {
    /**
     * Extrait les directives "instruction_ia" pour alimenter un prompt LLM.
     * Chaque directive = tableau ['titre' => ..., 'texte' => ..., 'poids' => ...].
     */
    function inv_comm_ia_directives(array $comments): array {
        $out = [];
        foreach ($comments as $c) {
            if (($c['categorie'] ?? '') !== 'instruction_ia') continue;
            if (empty($c['actif'])) continue;
            $out[] = [
                'titre' => (string)($c['titre'] ?? ''),
                'texte' => (string)($c['contenu'] ?? ''),
                'poids' => (int)($c['poids'] ?? 3),
            ];
        }
        return $out;
    }
}

if (!function_exists('inv_comm_build_ia_prompt_preamble')) {
    /**
     * Construit le bloc à préfixer à un prompt LLM pour orienter l'analyse IA
     * selon les instructions conseiller. Sans impact si aucune directive.
     */
    function inv_comm_build_ia_prompt_preamble(array $comments): string {
        $dir = inv_comm_ia_directives($comments);
        if (empty($dir)) return '';
        usort($dir, fn($a, $b) => ($b['poids'] ?? 0) <=> ($a['poids'] ?? 0));
        $lines = array_map(function ($d) {
            $p = ($d['titre'] ? '[' . $d['titre'] . '] ' : '') . $d['texte'];
            return '- (poids ' . $d['poids'] . '/5) ' . $p;
        }, $dir);
        return "INSTRUCTIONS CONSEILLER (à prendre en compte en priorité absolue dans l'analyse générée) :\n"
             . implode("\n", $lines) . "\n\n";
    }
}
