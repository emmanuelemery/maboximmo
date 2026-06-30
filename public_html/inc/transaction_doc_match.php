<?php
// inc/transaction_doc_match.php — Logique de matching bien depuis une extraction IA
// Réutilisée par transaction_doc_preview_ia.php (lors de l'analyse) ET
// transaction_doc_rematch.php (re-match sans rappel IA).
declare(strict_types=1);

if (!function_exists('transaction_doc_match_bien')) {
    /**
     * Trouve les biens correspondant à une adresse extraite par IA.
     * @param array $data ia_data : doit contenir adresse_bien, ville, code_postal
     * @return array { match_biens: array, creation_needed: bool }
     */
    function transaction_doc_match_bien(PDO $pdo, array $data, bool $isManager, ?int $idSoc): array
    {
        $adresse = trim((string)($data['adresse_bien'] ?? ''));
        $ville   = trim((string)($data['ville'] ?? ''));
        $cp      = trim((string)($data['code_postal'] ?? ''));

        if ($adresse === '' && $ville === '' && $cp === '') {
            return ['match_biens' => null, 'creation_needed' => true];
        }

        // ═══════════════════════════════════════════════════════════════
        // FEATURE_ENTITY_MATCHER (Sprint 2C — 2026-05-23) :
        // Si ON → délégation au moteur central em_match_bien() avec mapping
        // du format de sortie pour rester compatible avec les appelants
        // (transaction_doc_preview_ia.php, transaction_doc_rematch.php).
        // Si OFF (défaut) → code legacy ci-dessous inchangé.
        // ═══════════════════════════════════════════════════════════════
        if (defined('FEATURE_ENTITY_MATCHER') && FEATURE_ENTITY_MATCHER) {
            require_once __DIR__ . '/entity_matcher.php';

            $scopeSoc = $isManager ? null : $idSoc;
            $r = em_match_bien($pdo, [
                'adresse_1'   => $adresse,
                'code_postal' => $cp,
                'ville'       => $ville,
            ], null, $scopeSoc);

            // Enrichir chaque match avec les champs supplémentaires attendus par
            // l'appelant legacy : proprio_nom, addr_hits, date_creation, etc.
            // On refait une mini-query pour récupérer ces champs.
            $matchesLegacy = [];
            $ids = array_map(static fn($m) => (int)$m['id'], $r['matches'] ?? []);
            $enrich = [];
            if (!empty($ids)) {
                try {
                    $in = implode(',', $ids);
                    $stE = $pdo->query("SELECT b.id, b.priorite_vente, b.id_proprietaire, b.date_creation,
                                               b.numero_lot, b.surface_habitable, b.designation,
                                               COALESCE(p.societe, CONCAT_WS(' ', p.prenom, p.nom)) AS proprio_nom
                                        FROM biens b
                                        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                                        WHERE b.id IN ({$in})");
                    foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $enrich[(int)$row['id']] = $row;
                    }
                } catch (Throwable $e) { error_log('[transaction_doc_match enrich] ' . $e->getMessage()); }
            }

            foreach (($r['matches'] ?? []) as $m) {
                $id = (int)$m['id'];
                $extra = $enrich[$id] ?? [];
                $matchesLegacy[] = [
                    'id'                => $id,
                    'reference_bien'    => $m['reference_bien'],
                    'adresse_1'         => $m['adresse_1'],
                    'ville'             => $m['ville'],
                    'code_postal'       => $m['code_postal'],
                    'designation'       => $m['designation'] ?: ($extra['designation'] ?? ''),
                    'priorite_vente'    => $extra['priorite_vente'] ?? null,
                    'id_proprietaire'   => $extra['id_proprietaire'] ?? null,
                    'surface_habitable' => $m['surface_habitable'] !== null ? $m['surface_habitable'] : ($extra['surface_habitable'] ?? null),
                    'date_creation'     => $extra['date_creation'] ?? null,
                    'numero_lot'        => $extra['numero_lot'] ?? null,
                    'proprio_nom'       => $extra['proprio_nom'] ?? '',
                    // Score normalisé 0-100 (le moteur retourne déjà sur cette échelle)
                    'score'             => $m['score'],
                    // addr_hits dérivé du match_type (mandat/refExt = 3, address_score = 2-3 selon reasons)
                    'addr_hits'         => in_array($m['match_type'], ['mandat_identique', 'reference_externe'], true) ? 3
                                          : (count(array_filter($m['reasons'], static fn($x) => str_contains($x, 'identique'))) >= 1 ? 2 : 1),
                    'reasons'           => $m['reasons'] ?? [],
                    'match_type'        => $m['match_type'] ?? '',
                ];
            }

            if (defined('FEATURE_ENTITY_MATCHER_LOG') && FEATURE_ENTITY_MATCHER_LOG) {
                error_log(sprintf(
                    '[entity_matcher A/B] transaction_doc_match_bien flag=ON count=%d top=%d type=%s',
                    count($matchesLegacy), $r['confidence'] ?? 0, $r['match_type'] ?? 'unknown'
                ));
            }

            $creationNeeded = empty($matchesLegacy) || (($matchesLegacy[0]['addr_hits'] ?? 0) === 0);
            return [
                'match_biens'     => !empty($matchesLegacy) ? array_slice($matchesLegacy, 0, 5) : null,
                'creation_needed' => $creationNeeded,
                // Champs additionnels (ignorés par appelants legacy) :
                '_via_entity_matcher'   => true,
                '_confidence'           => $r['confidence'] ?? 0,
                '_needs_user_validation'=> $r['needs_user_validation'] ?? false,
                '_can_create'           => $r['can_create'] ?? true,
            ];
        }
        // ═══════════════════════════════════════════════════════════════
        // CODE LEGACY (FEATURE_ENTITY_MATCHER = OFF, défaut) — INCHANGÉ
        // ═══════════════════════════════════════════════════════════════

        $stop = ['rue','avenue','boulevard','blv','blvd','bld','bd','place','chemin','allee','allée','impasse','route','voie','quai','cours','passage','square','parvis','lieu','dit','dite','dits','de','du','des','la','le','les','et','aux','en','sur','sous','d','l'];

        $numRue = '';
        if (preg_match('/^\s*(\d+)\b/', $adresse, $m)) $numRue = $m[1];

        $addrTokens = [];
        foreach (preg_split('~[\s,;\-_/]+~', mb_strtolower($adresse)) as $t) {
            $t = trim((string)$t);
            if (mb_strlen($t) < 3 || is_numeric($t)) continue;
            if (in_array($t, $stop, true)) continue;
            $addrTokens[] = $t;
        }
        $addrTokens = array_values(array_unique($addrTokens));

        // Pool : on filtre par CP si dispo, sinon par ville. Sinon on ouvre large.
        $whereParts = ['(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))'];
        $bind = [];
        if ($cp !== '') {
            $whereParts[] = 'b.code_postal = ?';
            $bind[] = $cp;
        } elseif ($ville !== '') {
            $whereParts[] = 'LOWER(b.ville) LIKE ?';
            $bind[] = '%' . mb_strtolower($ville) . '%';
        }
        if (!$isManager && $idSoc !== null) {
            $whereParts[] = '(b.id_societe = ? OR b.id_societe IS NULL)';
            $bind[]       = $idSoc;
        }
        $selectCols = 'b.id, b.reference_bien, b.adresse_1, b.ville, b.code_postal, b.designation, b.priorite_vente,
                       b.id_proprietaire, b.surface_habitable, b.date_creation, b.numero_lot,
                       COALESCE(p.societe, CONCAT_WS(" ", p.prenom, p.nom)) AS proprio_nom';
        $sql = 'SELECT ' . $selectCols . '
                FROM biens b
                LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                WHERE ' . implode(' AND ', $whereParts) . '
                LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $pool = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Si CP donné mais aucun bien dans le pool, fallback ville
        if (empty($pool) && $cp !== '' && $ville !== '') {
            $whereParts = ['(b.statut_bien IS NULL OR b.statut_bien NOT IN ("supprime","archive"))'];
            $bind = [];
            $whereParts[] = 'LOWER(b.ville) LIKE ?';
            $bind[] = '%' . mb_strtolower($ville) . '%';
            if (!$isManager && $idSoc !== null) {
                $whereParts[] = '(b.id_societe = ? OR b.id_societe IS NULL)';
                $bind[] = $idSoc;
            }
            $sql = 'SELECT ' . $selectCols . '
                    FROM biens b
                    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                    WHERE ' . implode(' AND ', $whereParts) . '
                    LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $pool = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Normalisation adresse (BLV/BD/B.→Boulevard, AV→Avenue, etc.) pour matcher
        // SIR-012 "15 BLV Y. FARGE" avec "15 Boulevard Yves Farge"
        $normAddr = function (string $s): string {
            $s = mb_strtolower($s);
            $s = preg_replace('/\b(bd|blv|blvd|bld|b\.)\b\.?/u', 'boulevard', $s);
            $s = preg_replace('/\b(av|ave|avn)\b\.?/u', 'avenue', $s);
            $s = preg_replace('/\b(r|rte)\b\.?/u', 'rue', $s);
            $s = preg_replace('/\b(pl)\b\.?/u', 'place', $s);
            $s = preg_replace('/\b(ch|chem)\b\.?/u', 'chemin', $s);
            $s = preg_replace('/\b(imp)\b\.?/u', 'impasse', $s);
            // Supprime les points et accents pour comparaison large
            $s = str_replace(['.', ','], ' ', (string)$s);
            return preg_replace('/\s+/', ' ', trim((string)$s));
        };

        // Scoring
        $scored = [];
        $now = time();
        foreach ($pool as $b) {
            $rawScore = 0; $addrHits = 0;
            $addrLow = $normAddr((string)($b['adresse_1'] ?? ''));
            $vilLow  = mb_strtolower((string)($b['ville'] ?? ''));
            $cpLow   = (string)($b['code_postal'] ?? '');
            $desLow  = mb_strtolower((string)($b['designation'] ?? ''));
            $refLow  = mb_strtolower((string)($b['reference_bien'] ?? ''));

            foreach ($addrTokens as $t) {
                if ($addrLow !== '' && str_contains($addrLow, $t)) { $rawScore += 50; $addrHits++; }
                elseif (str_contains($desLow, $t))                  $rawScore += 8;
                elseif (str_contains($refLow, $t))                  $rawScore += 12;
            }
            if ($numRue !== '' && $addrLow !== '' && preg_match('/(^|\D)' . preg_quote($numRue, '/') . '(\D|$)/', $addrLow)) {
                $rawScore += 30;
            }
            if ($cp !== '' && $cpLow === $cp) $rawScore += 12;
            if ($ville !== '' && $vilLow !== '' && str_contains($vilLow, mb_strtolower($ville))) $rawScore += 6;

            if ($rawScore <= 0) continue;

            // ─── Bonus / pénalités de pertinence ───────────────────────
            $bonus = 0;
            // +15 si le bien a un propriétaire rattaché (bien "réel" vs bien squelette)
            if (!empty($b['id_proprietaire'])) $bonus += 15;
            // +10 si surface renseignée
            if (!empty($b['surface_habitable']) && (float)$b['surface_habitable'] > 0) $bonus += 10;
            // +10 si numero_lot renseigné
            if (!empty($b['numero_lot'])) $bonus += 10;
            // +8 si référence "métier" (SIR-, REF-, etc.) plutôt que TMP-/AUTO-
            if (preg_match('/^(TMP|AUTO)-/i', $b['reference_bien'] ?? '')) $bonus -= 25; // pénalité brouillon/auto
            elseif (!empty($b['reference_bien']))                          $bonus += 8;

            // Pénalité forte si bien créé il y a < 2h (probablement créé par erreur récente)
            if (!empty($b['date_creation'])) {
                $ageSec = $now - strtotime((string)$b['date_creation']);
                if ($ageSec > 0 && $ageSec < 7200) $bonus -= 20;
            }

            $b['score'] = max(1, $rawScore + $bonus);
            $b['addr_hits'] = $addrHits;
            $scored[] = $b;
        }

        usort($scored, function ($x, $y) {
            if ($x['addr_hits'] !== $y['addr_hits']) return $y['addr_hits'] <=> $x['addr_hits'];
            return $y['score'] <=> $x['score'];
        });

        // Normalisation en %
        $maxScore = !empty($scored) ? max(50, $scored[0]['score']) : 100;
        foreach ($scored as &$s) {
            $s['score'] = min(100, (int)round(($s['score'] / $maxScore) * 100));
        }
        unset($s);

        $top = array_slice($scored, 0, 5);
        $bestAddrHits = !empty($top) ? (int)($top[0]['addr_hits'] ?? 0) : 0;

        return [
            'match_biens'     => !empty($top) ? $top : null,
            'creation_needed' => ($bestAddrHits === 0),
        ];
    }
}
