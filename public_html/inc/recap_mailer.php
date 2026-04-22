<?php
declare(strict_types=1);

/**
 * inc/recap_mailer.php
 *
 * Helpers pour le cron matinal de récapitulatif annonces :
 *   - recap_window_from_now()        → calcule la fenêtre (24h ou 72h si lundi)
 *   - recap_user_is_en_conges()      → check table conges, statut='validé'
 *   - recap_collect_user_activity()  → stats + listes annonces par user
 *   - recap_collect_candidates()     → users à notifier (activité OU annonces actives/brouillon)
 *   - recap_collect_global()         → synthèse globale pour le super-admin
 *   - recap_build_email_user()       → HTML mail personnalisé
 *   - recap_build_email_global()     → HTML mail récap global
 */

if (!function_exists('recap_window_from_now')) {
    function recap_window_from_now(?DateTimeImmutable $now = null): array
    {
        $now   = $now ?? new DateTimeImmutable('now');
        $dow   = (int)$now->format('N'); // 1 = lundi, 7 = dimanche
        $hours = ($dow === 1) ? 72 : 24;  // lundi : inclure ven + sam + dim
        $start = $now->sub(new DateInterval('PT' . $hours . 'H'));
        return [
            'start'        => $start,
            'end'          => $now,
            'hours'        => $hours,
            'start_sql'    => $start->format('Y-m-d H:i:s'),
            'end_sql'      => $now->format('Y-m-d H:i:s'),
            'label_fr'     => ($hours === 72 ? 'des 72 dernières heures (week-end inclus)' : 'des dernières 24 heures'),
            'date_label'   => $now->format('d/m/Y'),
            'day_fr'       => recap_day_fr($now),
        ];
    }
}

if (!function_exists('recap_day_fr')) {
    function recap_day_fr(DateTimeImmutable $d): string
    {
        $days = ['','lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
        $months = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        return $days[(int)$d->format('N')] . ' ' . (int)$d->format('j') . ' ' . $months[(int)$d->format('n')];
    }
}

if (!function_exists('recap_user_is_en_conges')) {
    function recap_user_is_en_conges(PDO $pdo, int $userId, string $dateSql): bool
    {
        try {
            $st = $pdo->prepare("
                SELECT 1 FROM conges
                WHERE id_user = :u
                  AND statut = 'validé'
                  AND date_debut <= :d AND date_fin >= :d
                LIMIT 1
            ");
            $st->execute([':u' => $userId, ':d' => substr($dateSql, 0, 10)]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[recap_mailer] recap_user_is_en_conges: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('recap_collect_user_activity')) {
    /**
     * Retourne pour un user : stats + listes d'annonces dans la fenêtre.
     *
     * @return array{
     *   diffusees:array, nouvelles:array, archivees:array, supprimees:array,
     *   en_cours:array, counts:array
     * }
     */
    function recap_collect_user_activity(PDO $pdo, int $userId, array $window): array
    {
        $result = [
            'diffusees'  => [],
            'nouvelles'  => [],
            'archivees'  => [],
            'supprimees' => [],
            'en_cours'   => [],
            'counts'     => [
                'diffusees' => 0, 'nouvelles' => 0, 'archivees' => 0, 'supprimees' => 0, 'en_cours' => 0,
            ],
        ];
        try {
            // Diffusées hier (passage à etat_publication='diffusee' OU date_mise_en_ligne dans la fenêtre)
            $stD = $pdo->prepare("
                SELECT a.id, a.titre, a.type_transaction, a.loyer_cc, a.loyer, a.prix,
                       COALESCE(b.ville, '') AS ville, a.date_mise_en_ligne
                FROM annonces a
                LEFT JOIN biens b ON b.id = a.id_bien
                WHERE a.id_user = :u
                  AND a.date_mise_en_ligne BETWEEN :s AND :e
                  AND (a.etat_publication IN ('diffusee','publiee') OR a.statut IN ('publiee','active','en_ligne'))
                ORDER BY a.date_mise_en_ligne DESC
            ");
            $stD->execute([':u' => $userId, ':s' => $window['start_sql'], ':e' => $window['end_sql']]);
            $result['diffusees'] = $stD->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Nouvelles annonces créées dans la fenêtre
            $stN = $pdo->prepare("
                SELECT a.id, a.titre, a.type_transaction, a.loyer, a.prix,
                       COALESCE(b.ville, '') AS ville, a.date_creation
                FROM annonces a
                LEFT JOIN biens b ON b.id = a.id_bien
                WHERE a.id_user = :u
                  AND a.date_creation BETWEEN :s AND :e
                ORDER BY a.date_creation DESC
            ");
            $stN->execute([':u' => $userId, ':s' => $window['start_sql'], ':e' => $window['end_sql']]);
            $result['nouvelles'] = $stN->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Archivées dans la fenêtre (via versions ou heuristique sur etat_publication)
            // Heuristique simple : etat_publication = 'archivee' et date_modification dans la fenêtre
            $stA = $pdo->prepare("
                SELECT a.id, a.titre, a.type_transaction, a.loyer, a.prix,
                       COALESCE(b.ville, '') AS ville, a.date_modification
                FROM annonces a
                LEFT JOIN biens b ON b.id = a.id_bien
                WHERE a.id_user = :u
                  AND a.etat_publication IN ('archivee','archived')
                  AND a.date_modification BETWEEN :s AND :e
                ORDER BY a.date_modification DESC
            ");
            $stA->execute([':u' => $userId, ':s' => $window['start_sql'], ':e' => $window['end_sql']]);
            $result['archivees'] = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Supprimées : via trigger annonces_versions
            $stS = $pdo->prepare("
                SELECT id_annonce, snapshot_json, date_creation
                FROM annonces_versions
                WHERE type_action = 'delete'
                  AND id_user = :u
                  AND date_creation BETWEEN :s AND :e
                ORDER BY date_creation DESC
            ");
            $stS->execute([':u' => $userId, ':s' => $window['start_sql'], ':e' => $window['end_sql']]);
            foreach ($stS->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $snap = json_decode((string)$row['snapshot_json'], true) ?: [];
                $result['supprimees'][] = [
                    'id'               => (int)($row['id_annonce'] ?? 0),
                    'titre'            => (string)($snap['titre'] ?? '(sans titre)'),
                    'type_transaction' => (string)($snap['type_transaction'] ?? ''),
                    'prix'             => $snap['prix'] ?? null,
                    'loyer'            => $snap['loyer'] ?? null,
                    'date_suppression' => (string)$row['date_creation'],
                ];
            }

            // En cours (brouillon ou diffusée actuellement)
            $stE = $pdo->prepare("
                SELECT a.id, a.titre, a.type_transaction, a.etat_publication, a.statut,
                       a.loyer, a.prix, COALESCE(b.ville, '') AS ville
                FROM annonces a
                LEFT JOIN biens b ON b.id = a.id_bien
                WHERE a.id_user = :u
                  AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archivee','archived'))
                ORDER BY a.date_modification DESC
                LIMIT 20
            ");
            $stE->execute([':u' => $userId]);
            $result['en_cours'] = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Annonces diffusées depuis > 5 jours : suggestion de rafraîchir (SEO)
            $stR = $pdo->prepare("
                SELECT a.id, a.titre, a.type_transaction, a.loyer, a.loyer_cc, a.prix,
                       COALESCE(b.ville, '') AS ville,
                       a.date_mise_en_ligne,
                       DATEDIFF(NOW(), a.date_mise_en_ligne) AS nb_jours
                FROM annonces a
                LEFT JOIN biens b ON b.id = a.id_bien
                WHERE a.id_user = :u
                  AND a.visible_portails = 1
                  AND a.statut IN ('publiee','active','en_ligne')
                  AND a.date_mise_en_ligne IS NOT NULL
                  AND a.date_mise_en_ligne < DATE_SUB(NOW(), INTERVAL 5 DAY)
                ORDER BY a.date_mise_en_ligne ASC
                LIMIT 20
            ");
            $stR->execute([':u' => $userId]);
            $result['a_rafraichir'] = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $result['counts'] = [
                'diffusees'    => count($result['diffusees']),
                'nouvelles'    => count($result['nouvelles']),
                'archivees'    => count($result['archivees']),
                'supprimees'   => count($result['supprimees']),
                'en_cours'     => count($result['en_cours']),
                'a_rafraichir' => count($result['a_rafraichir']),
            ];
        } catch (Throwable $e) {
            error_log('[recap_mailer] recap_collect_user_activity: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('recap_collect_candidates')) {
    /**
     * Retourne la liste des users à notifier :
     *   - ont une activité dans la fenêtre OU
     *   - ont une annonce non-archivée actuellement attribuée (id_user)
     *
     * @return array<array{id:int,email:string,prenom:string,nom:string}>
     */
    function recap_collect_candidates(PDO $pdo, array $window): array
    {
        try {
            // ⚠️ PDO avec prepared statements natives MySQL ne supporte PAS la
            // réutilisation d'un placeholder nommé plusieurs fois dans une même
            // requête → chaque occurrence doit avoir son propre nom (:s1, :s2…).
            $st = $pdo->prepare("
                SELECT DISTINCT u.id, u.email, u.prenom, u.nom
                FROM users u
                WHERE u.actif = 1
                  AND u.email IS NOT NULL AND u.email != ''
                  AND (
                    EXISTS (
                      SELECT 1 FROM annonces a
                      WHERE a.id_user = u.id
                        AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archivee','archived'))
                    )
                    OR EXISTS (
                      SELECT 1 FROM annonces a
                      WHERE a.id_user = u.id
                        AND (
                          a.date_mise_en_ligne BETWEEN :s1 AND :e1
                          OR a.date_creation   BETWEEN :s2 AND :e2
                          OR (a.etat_publication IN ('archivee','archived') AND a.date_modification BETWEEN :s3 AND :e3)
                        )
                    )
                    OR EXISTS (
                      SELECT 1 FROM annonces_versions v
                      WHERE v.id_user = u.id
                        AND v.type_action = 'delete'
                        AND v.date_creation BETWEEN :s4 AND :e4
                    )
                  )
                ORDER BY u.nom, u.prenom
            ");
            $st->execute([
                ':s1' => $window['start_sql'], ':e1' => $window['end_sql'],
                ':s2' => $window['start_sql'], ':e2' => $window['end_sql'],
                ':s3' => $window['start_sql'], ':e3' => $window['end_sql'],
                ':s4' => $window['start_sql'], ':e4' => $window['end_sql'],
            ]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[recap_mailer] recap_collect_candidates: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('recap_collect_global')) {
    function recap_collect_global(PDO $pdo, array $window): array
    {
        $data = ['users' => [], 'totals' => [
            'diffusees' => 0, 'nouvelles' => 0, 'archivees' => 0, 'supprimees' => 0,
            'users_actifs' => 0, 'users_total' => 0, 'agences' => 0,
        ]];
        try {
            $candidates = recap_collect_candidates($pdo, $window);
            $data['totals']['users_total'] = count($candidates);

            $agencesSet = [];
            foreach ($candidates as $u) {
                $act = recap_collect_user_activity($pdo, (int)$u['id'], $window);
                $actTotal = $act['counts']['diffusees'] + $act['counts']['nouvelles']
                          + $act['counts']['archivees'] + $act['counts']['supprimees'];
                $data['totals']['diffusees']  += $act['counts']['diffusees'];
                $data['totals']['nouvelles']  += $act['counts']['nouvelles'];
                $data['totals']['archivees']  += $act['counts']['archivees'];
                $data['totals']['supprimees'] += $act['counts']['supprimees'];
                if ($actTotal > 0) $data['totals']['users_actifs']++;

                // Récup agence
                $stAg = $pdo->prepare("
                    SELECT ag.nom_agence, ag.id AS ag_id
                    FROM annonces a LEFT JOIN agences ag ON ag.id = a.id_agence
                    WHERE a.id_user = ? ORDER BY a.date_modification DESC LIMIT 1
                ");
                $stAg->execute([(int)$u['id']]);
                $agRow = $stAg->fetch(PDO::FETCH_ASSOC) ?: [];
                $agNom = (string)($agRow['nom_agence'] ?? '—');
                if (!empty($agRow['ag_id'])) $agencesSet[(int)$agRow['ag_id']] = true;

                $data['users'][] = [
                    'id'        => (int)$u['id'],
                    'prenom'    => (string)$u['prenom'],
                    'nom'       => (string)$u['nom'],
                    'agence'    => $agNom,
                    'counts'    => $act['counts'],
                ];
            }
            $data['totals']['agences'] = count($agencesSet);

            // Points vigilance globaux
            $stBr = $pdo->query("
                SELECT COUNT(*) FROM annonces a
                WHERE (a.etat_publication = 'brouillon' OR a.statut = 'brouillon')
                  AND a.date_creation < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $data['vigilance']['brouillons_vieux_7j'] = (int)$stBr->fetchColumn();

            $stP = $pdo->query("
                SELECT COUNT(DISTINCT a.id) FROM annonces a
                WHERE a.visible_portails = 1
                  AND (SELECT COUNT(*) FROM annonces_photos ap WHERE ap.id_annonce = a.id) = 0
            ");
            $data['vigilance']['annonces_sans_photos'] = (int)$stP->fetchColumn();

            // Annonces diffusées > 5 jours (SEO)
            $stAnc = $pdo->query("
                SELECT COUNT(*) FROM annonces
                WHERE visible_portails = 1
                  AND statut IN ('publiee','active','en_ligne')
                  AND date_mise_en_ligne IS NOT NULL
                  AND date_mise_en_ligne < DATE_SUB(NOW(), INTERVAL 5 DAY)
            ");
            $data['vigilance']['annonces_anciennes_5j'] = (int)$stAnc->fetchColumn();
        } catch (Throwable $e) {
            error_log('[recap_mailer] recap_collect_global: ' . $e->getMessage());
        }
        return $data;
    }
}

// ═══════════════════════════════════════════════════════════════
//   TEMPLATES HTML
// ═══════════════════════════════════════════════════════════════

if (!function_exists('recap_css_inline')) {
    function recap_css_inline(): array
    {
        return [
            'wrap'     => 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:0 auto;padding:0;color:#0f172a;background:#f8fafc;',
            'card'     => 'background:#fff;border-radius:12px;padding:24px;margin:16px;box-shadow:0 1px 3px rgba(0,0,0,.08);',
            'h1'       => 'margin:0 0 6px;font-size:20px;font-weight:800;color:#0f172a;',
            'subtitle' => 'margin:0 0 20px;color:#64748b;font-size:13px;',
            'greeting' => 'font-size:15px;line-height:1.5;color:#334155;margin:0 0 22px;',
            'section'  => 'margin:26px 0 10px;padding:10px 14px;background:#f1f5f9;border-left:4px solid #0ea5e9;border-radius:6px;font-size:13px;font-weight:700;color:#0f172a;',
            'item'     => 'padding:12px 14px;margin:8px 0;background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;',
            'btn'      => 'display:inline-block;padding:8px 14px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:6px;font-size:12px;font-weight:600;margin-top:6px;',
            'btnGreen' => 'display:inline-block;padding:8px 14px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;font-size:12px;font-weight:600;margin-top:6px;',
            'btnGrey'  => 'display:inline-block;padding:8px 14px;background:#64748b;color:#fff;text-decoration:none;border-radius:6px;font-size:12px;font-weight:600;margin-top:6px;',
            'kpiRow'   => 'display:table;width:100%;border-collapse:separate;border-spacing:8px;margin:12px -4px;',
            'kpi'      => 'display:table-cell;text-align:center;padding:14px 8px;background:#f1f5f9;border-radius:8px;vertical-align:middle;',
            'kpiNb'    => 'font-size:24px;font-weight:800;color:#0f172a;display:block;',
            'kpiLbl'   => 'font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;display:block;margin-top:2px;',
            'footer'   => 'text-align:center;padding:20px;color:#94a3b8;font-size:11px;border-top:1px solid #e5e7eb;margin-top:30px;',
            'ending'   => 'margin:30px 0 0;font-size:14px;color:#334155;line-height:1.6;',
            'table'    => 'width:100%;border-collapse:collapse;font-size:12px;margin:10px 0;',
            'th'       => 'background:#f1f5f9;padding:8px 10px;text-align:left;font-weight:700;color:#475569;font-size:11px;text-transform:uppercase;border-bottom:2px solid #cbd5e1;',
            'td'       => 'padding:8px 10px;border-bottom:1px solid #e5e7eb;',
        ];
    }
}

if (!function_exists('recap_format_prix')) {
    function recap_format_prix(array $ann): string
    {
        $trans = (string)($ann['type_transaction'] ?? '');
        if ($trans === 'vente' && (float)($ann['prix'] ?? 0) > 0) {
            return number_format((float)$ann['prix'], 0, ',', ' ') . ' €';
        }
        if (!empty($ann['loyer_cc']) && (float)$ann['loyer_cc'] > 0) {
            return number_format((float)$ann['loyer_cc'], 0, ',', ' ') . ' € CC';
        }
        if (!empty($ann['loyer']) && (float)$ann['loyer'] > 0) {
            return number_format((float)$ann['loyer'], 0, ',', ' ') . ' € HC';
        }
        return '';
    }
}

if (!function_exists('recap_annonce_url')) {
    function recap_annonce_url(int $annonceId): string
    {
        // Il faut un id_bien — on fait un SELECT rapide pour construire l'URL.
        // Simplification : on pointe vers le dashboard avec un query param search.
        if (function_exists('app_url')) {
            return rtrim(app_url(''), '/') . '/annonce_liste.php?q=%23' . $annonceId;
        }
        return 'https://maboximmo.fr/annonce_liste.php?q=%23' . $annonceId;
    }
}

if (!function_exists('recap_bien_url_for_annonce')) {
    function recap_bien_url_for_annonce(PDO $pdo, int $annonceId): string
    {
        try {
            $st = $pdo->prepare("SELECT id_bien FROM annonces WHERE id = ? LIMIT 1");
            $st->execute([$annonceId]);
            $idBien = (int)($st->fetchColumn() ?: 0);
            if ($idBien > 0) {
                $base = function_exists('app_url') ? rtrim(app_url(''), '/') : 'https://maboximmo.fr';
                return $base . '/bien_detail.php?edit=' . $idBien . '&section=annonce';
            }
        } catch (Throwable $e) {}
        return recap_annonce_url($annonceId);
    }
}

if (!function_exists('recap_build_email_user')) {
    /**
     * Construit le HTML du mail individuel pour un commercial.
     */
    function recap_build_email_user(PDO $pdo, array $user, array $activity, array $window): string
    {
        $s = recap_css_inline();
        $prenom = htmlspecialchars((string)$user['prenom']);
        $title  = '🏠 Bonjour ' . $prenom . ' — votre récap MaBoxImmo du matin';
        $counts = $activity['counts'];

        $renderList = function(array $items) use ($s, $pdo) {
            if (empty($items)) return '<div style="color:#94a3b8;font-style:italic;padding:8px 14px;">Aucune</div>';
            $out = '';
            foreach ($items as $a) {
                $id    = (int)($a['id'] ?? $a['id_annonce'] ?? 0);
                $titre = htmlspecialchars((string)($a['titre'] ?: '(sans titre)'));
                $ville = htmlspecialchars((string)($a['ville'] ?? ''));
                $prix  = htmlspecialchars(recap_format_prix($a));
                $url   = recap_bien_url_for_annonce($pdo, $id);
                $out .= '<div style="' . $s['item'] . '">';
                $out .= '<div style="font-weight:700;font-size:13px;color:#0f172a;">' . $titre . '</div>';
                $out .= '<div style="font-size:11px;color:#64748b;margin-top:3px;">';
                if ($ville !== '') $out .= '📍 ' . $ville . ' ';
                if ($prix !== '')  $out .= ' · ' . $prix;
                $out .= ' · #' . $id . '</div>';
                $out .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="' . $s['btn'] . '">Voir l\'annonce →</a>';
                $out .= '</div>';
            }
            return $out;
        };

        $renderDeleted = function(array $items) use ($s) {
            if (empty($items)) return '<div style="color:#94a3b8;font-style:italic;padding:8px 14px;">Aucune</div>';
            $out = '';
            foreach ($items as $a) {
                $titre = htmlspecialchars((string)($a['titre'] ?: '(sans titre)'));
                $id    = (int)$a['id'];
                $date  = htmlspecialchars((string)$a['date_suppression']);
                $out .= '<div style="' . $s['item'] . 'opacity:.7;background:#fef2f2;">';
                $out .= '<div style="font-weight:700;font-size:13px;color:#991b1b;">' . $titre . '</div>';
                $out .= '<div style="font-size:11px;color:#64748b;margin-top:3px;">#' . $id . ' · supprimée le ' . $date . '</div>';
                $out .= '</div>';
            }
            return $out;
        };

        $html  = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title></head>';
        $html .= '<body style="' . $s['wrap'] . '">';
        $html .= '<div style="' . $s['card'] . '">';
        $html .= '<h1 style="' . $s['h1'] . '">' . htmlspecialchars($title) . '</h1>';
        $html .= '<div style="' . $s['subtitle'] . '">Récap ' . htmlspecialchars($window['label_fr']) . ' — ' . htmlspecialchars($window['date_label']) . '</div>';

        $html .= '<p style="' . $s['greeting'] . '">Bonjour ' . $prenom . ',<br><br>'
               . 'J\'espère que vous avez passé une bonne nuit. Voici votre récapitulatif '
               . 'd\'activité sur MaBoxImmo ' . htmlspecialchars($window['label_fr']) . ', pour bien préparer votre journée.</p>';

        // KPI row
        $html .= '<div style="' . $s['kpiRow'] . '">';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#166534;">' . $counts['diffusees'] . '</span><span style="' . $s['kpiLbl'] . '">Diffusées</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#0369a1;">' . $counts['nouvelles'] . '</span><span style="' . $s['kpiLbl'] . '">Nouvelles</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#78350f;">' . $counts['archivees'] . '</span><span style="' . $s['kpiLbl'] . '">Archivées</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#991b1b;">' . $counts['supprimees'] . '</span><span style="' . $s['kpiLbl'] . '">Supprimées</span></div>';
        $html .= '</div>';

        if ($counts['diffusees'] > 0) {
            $html .= '<div style="' . $s['section'] . '">🚀 Annonces diffusées (portails)</div>';
            $html .= $renderList($activity['diffusees']);
        }
        if ($counts['nouvelles'] > 0) {
            $html .= '<div style="' . $s['section'] . '">✨ Nouvelles annonces</div>';
            $html .= $renderList($activity['nouvelles']);
        }
        if ($counts['archivees'] > 0) {
            $html .= '<div style="' . $s['section'] . '">📦 Annonces archivées</div>';
            $html .= $renderList($activity['archivees']);
        }
        if ($counts['supprimees'] > 0) {
            $html .= '<div style="' . $s['section'] . '">🗑️ Annonces supprimées</div>';
            $html .= $renderDeleted($activity['supprimees']);
        }

        // ⏰ Annonces à rafraîchir (>5j diffusion sans modif — conseil SEO)
        if (!empty($activity['a_rafraichir']) && $counts['a_rafraichir'] > 0) {
            $html .= '<div style="' . $s['section'] . 'border-left-color:#f59e0b;background:#fef3c7;color:#78350f;">⏰ Annonces diffusées depuis plus de 5 jours — à rafraîchir</div>';
            $html .= '<div style="background:#fffbeb;padding:12px 14px;border-radius:8px;border:1px solid #fcd34d;font-size:12px;color:#78350f;line-height:1.5;margin:8px 0 12px;">'
                   . '💡 <strong>Pourquoi rafraîchir ?</strong> Pour favoriser le référencement sur les portails, il est conseillé de <strong>republier une nouvelle annonce</strong> après 5 à 7 jours de diffusion. '
                   . '<br><br>⚠️ <strong>Important</strong> : avant de republier, <strong>changez les photos</strong> (réorganisez ou remplacez les principales) et <strong>réécrivez le texte</strong>. '
                   . 'Les portails détectent les doublons par similarité photo et texte — sans modification, la nouvelle annonce risque d\'être masquée ou déréférencée.'
                   . '</div>';
            foreach ($activity['a_rafraichir'] as $a) {
                $id    = (int)$a['id'];
                $titre = htmlspecialchars((string)($a['titre'] ?: '(sans titre)'));
                $jours = (int)($a['nb_jours'] ?? 0);
                $ville = htmlspecialchars((string)($a['ville'] ?? ''));
                $prix  = htmlspecialchars(recap_format_prix($a));
                $url   = recap_bien_url_for_annonce($pdo, $id);
                $html .= '<div style="' . $s['item'] . 'border-left:4px solid #f59e0b;">';
                $html .= '<div style="font-weight:700;font-size:13px;color:#0f172a;">' . $titre . '</div>';
                $html .= '<div style="font-size:11px;color:#78350f;margin-top:3px;font-weight:600;">⏰ Diffusée depuis ' . $jours . ' jour' . ($jours > 1 ? 's' : '') . '</div>';
                $html .= '<div style="font-size:11px;color:#64748b;margin-top:2px;">';
                if ($ville !== '') $html .= '📍 ' . $ville . ' ';
                if ($prix !== '')  $html .= ' · ' . $prix;
                $html .= ' · #' . $id . '</div>';
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="' . $s['btn'] . 'background:#f59e0b;">Rafraîchir cette annonce →</a>';
                $html .= '</div>';
            }
        }

        if (array_sum($counts) === $counts['en_cours']) {
            // Aucune activité — on affiche les en cours
            $html .= '<div style="' . $s['section'] . '">📋 Vos annonces en cours</div>';
            $html .= '<p style="font-size:12px;color:#64748b;margin:8px 0 0;">Aucune activité sur la période — voici vos annonces actives à suivre :</p>';
            $html .= $renderList(array_slice($activity['en_cours'], 0, 10));
        }

        // 💡 Conseils du jour
        $html .= '<div style="' . $s['section'] . 'background:#ecfdf5;border-left-color:#16a34a;color:#065f46;">💡 Bon réflexe du jour</div>';
        $html .= '<div style="background:#f0fdf4;padding:14px 16px;border-radius:8px;border:1px solid #86efac;font-size:12px;color:#065f46;line-height:1.6;margin:8px 0;">'
               . '🔍 <strong>Vérifiez régulièrement que vos annonces sont bien en ligne</strong> sur les portails (LeBonCoin, SeLoger, Bien\'ici…). Un problème de passerelle Ubiflow peut arriver — une vérification visuelle sur les supports permet de détecter rapidement tout dysfonctionnement et de nous alerter si besoin. '
               . '<br><br>📊 Un plan de contrôle hebdomadaire (10 min par semaine) évite qu\'une annonce reste dans les limbes.'
               . '</div>';

        $html .= '<p style="' . $s['ending'] . '">Je vous souhaite une <strong>belle journée</strong> pleine de belles visites et de signatures 🏠<br><br>'
               . 'À bientôt sur MaBoxImmo,<br><em>L\'équipe MaBoxImmo</em></p>';
        $html .= '</div>'; // card

        $html .= '<div style="' . $s['footer'] . '">Mail envoyé automatiquement à 9h00. Vous recevez ce message parce que vous gérez des annonces sur MaBoxImmo.<br>Question : <a href="mailto:emmanuel.emery@regie-emery.com" style="color:#0ea5e9;">emmanuel.emery@regie-emery.com</a></div>';
        $html .= '</body></html>';
        return $html;
    }
}

if (!function_exists('recap_build_email_global')) {
    function recap_build_email_global(array $globalData, array $window): string
    {
        $s = recap_css_inline();
        $title = '📊 Récap global MaBoxImmo — ' . $window['day_fr'];
        $t = $globalData['totals'];

        $html  = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title></head>';
        $html .= '<body style="' . $s['wrap'] . '">';
        $html .= '<div style="' . $s['card'] . '">';
        $html .= '<h1 style="' . $s['h1'] . '">' . htmlspecialchars($title) . '</h1>';
        $html .= '<div style="' . $s['subtitle'] . '">Portefeuille complet MaBoxImmo — ' . htmlspecialchars($window['label_fr']) . '</div>';

        $html .= '<p style="' . $s['greeting'] . '">Bonjour Emmanuel,<br><br>'
               . 'J\'espère que tu as passé une bonne nuit. Voici le récapitulatif global des activités '
               . 'annonces sur l\'ensemble du portefeuille ' . htmlspecialchars($window['label_fr']) . '.</p>';

        // KPI row
        $html .= '<div style="' . $s['kpiRow'] . '">';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#166534;">' . $t['diffusees'] . '</span><span style="' . $s['kpiLbl'] . '">Diffusées</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#0369a1;">' . $t['nouvelles'] . '</span><span style="' . $s['kpiLbl'] . '">Nouvelles</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#78350f;">' . $t['archivees'] . '</span><span style="' . $s['kpiLbl'] . '">Archivées</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#991b1b;">' . $t['supprimees'] . '</span><span style="' . $s['kpiLbl'] . '">Supprimées</span></div>';
        $html .= '</div>';
        $html .= '<div style="' . $s['kpiRow'] . '">';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#0f172a;">' . $t['users_actifs'] . ' / ' . $t['users_total'] . '</span><span style="' . $s['kpiLbl'] . '">Users actifs / total</span></div>';
        $html .= '<div style="' . $s['kpi'] . '"><span style="' . $s['kpiNb'] . 'color:#0f172a;">' . $t['agences'] . '</span><span style="' . $s['kpiLbl'] . '">Agences impliquées</span></div>';
        $html .= '</div>';

        // Table des users
        $html .= '<div style="' . $s['section'] . '">👥 Détail par commercial</div>';
        $html .= '<table style="' . $s['table'] . '"><thead><tr>';
        $html .= '<th style="' . $s['th'] . '">Commercial</th>';
        $html .= '<th style="' . $s['th'] . '">Agence</th>';
        $html .= '<th style="' . $s['th'] . 'text-align:center;">Diff.</th>';
        $html .= '<th style="' . $s['th'] . 'text-align:center;">Nouv.</th>';
        $html .= '<th style="' . $s['th'] . 'text-align:center;">Arch.</th>';
        $html .= '<th style="' . $s['th'] . 'text-align:center;">Suppr.</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($globalData['users'] as $u) {
            $total = array_sum([$u['counts']['diffusees'], $u['counts']['nouvelles'], $u['counts']['archivees'], $u['counts']['supprimees']]);
            $inactif = $total === 0 ? 'color:#94a3b8;' : '';
            $html .= '<tr>';
            $html .= '<td style="' . $s['td'] . $inactif . 'font-weight:600;">' . htmlspecialchars($u['prenom'] . ' ' . $u['nom']) . '</td>';
            $html .= '<td style="' . $s['td'] . $inactif . 'font-size:11px;color:#64748b;">' . htmlspecialchars($u['agence']) . '</td>';
            $html .= '<td style="' . $s['td'] . 'text-align:center;font-weight:700;color:#166534;">' . $u['counts']['diffusees'] . '</td>';
            $html .= '<td style="' . $s['td'] . 'text-align:center;">' . $u['counts']['nouvelles'] . '</td>';
            $html .= '<td style="' . $s['td'] . 'text-align:center;">' . $u['counts']['archivees'] . '</td>';
            $html .= '<td style="' . $s['td'] . 'text-align:center;">' . $u['counts']['supprimees'] . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Vigilance
        if (!empty($globalData['vigilance'])) {
            $html .= '<div style="' . $s['section'] . '">⚠️ Points de vigilance</div>';
            $html .= '<ul style="color:#78350f;font-size:12px;line-height:1.8;">';
            if (($globalData['vigilance']['brouillons_vieux_7j'] ?? 0) > 0) {
                $html .= '<li><strong>' . (int)$globalData['vigilance']['brouillons_vieux_7j'] . '</strong> annonce(s) en brouillon depuis plus de 7 jours</li>';
            }
            if (($globalData['vigilance']['annonces_sans_photos'] ?? 0) > 0) {
                $html .= '<li><strong>' . (int)$globalData['vigilance']['annonces_sans_photos'] . '</strong> annonce(s) visibles portails mais sans photo (bloque diffusion)</li>';
            }
            if (($globalData['vigilance']['annonces_anciennes_5j'] ?? 0) > 0) {
                $html .= '<li><strong>' . (int)$globalData['vigilance']['annonces_anciennes_5j'] . '</strong> annonce(s) diffusées depuis > 5 jours — à rafraîchir pour SEO (nouvelles photos + texte)</li>';
            }
            $html .= '</ul>';
        }

        // Conseil global
        $html .= '<div style="' . $s['section'] . 'background:#ecfdf5;border-left-color:#16a34a;color:#065f46;">💡 Rappel général</div>';
        $html .= '<div style="background:#f0fdf4;padding:14px 16px;border-radius:8px;border:1px solid #86efac;font-size:12px;color:#065f46;line-height:1.6;margin:8px 0;">'
               . '🔍 <strong>Vérification portails</strong> : programmer un contrôle hebdomadaire manuel sur LeBonCoin, SeLoger, Bien\'ici pour détecter d\'éventuels problèmes de passerelle Ubiflow.'
               . '<br>📸 <strong>SEO annonces anciennes</strong> : pour les biens >5 jours, republier une nouvelle annonce avec photos réorganisées et texte différent.'
               . '</div>';

        $dashUrl = function_exists('app_url') ? rtrim(app_url(''), '/') : 'https://maboximmo.fr';
        $html .= '<p style="' . $s['ending'] . '"><a href="' . htmlspecialchars($dashUrl . '/agency_dashboard.php', ENT_QUOTES) . '" style="' . $s['btnGreen'] . '">Ouvrir le dashboard complet</a></p>';

        $html .= '<p style="' . $s['ending'] . '">Belle journée à toi Emmanuel,<br><em>L\'équipe MaBoxImmo</em></p>';
        $html .= '</div>';
        $html .= '<div style="' . $s['footer'] . '">Envoi automatique chaque matin à 9h00 — récap global Super Admin.</div>';
        $html .= '</body></html>';
        return $html;
    }
}
