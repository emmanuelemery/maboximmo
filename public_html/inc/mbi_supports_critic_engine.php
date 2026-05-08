<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_critic_engine.php — Moteur critique (mentions légales)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Module : Ma Box Communication (mbi_supports)
 *
 * Vérifie qu'un bien + un type de support respectent les mentions légales
 * obligatoires (bloc dur) et signale les manquements qualitatifs (alertes).
 *
 * Pilotage data-driven via la version active de mbi_supports_mentions_versions.
 *
 * API publique :
 *   mbi_supports_critic_check(int $id_bien, string $type_support): array
 *     → {
 *         ok:bool,                          // true si lecture OK (pas de blocage technique)
 *         peut_exporter:bool,               // true si aucun bloc dur en violation
 *         mentions_version:?string,         // ex: "2026-05-01.v1"
 *         blocs_durs_violes: array<{        // À CORRIGER avant export
 *           code:string, libelle:string, niveau:'bloc_dur',
 *           detail:?string, champ:?string
 *         }>,
 *         alertes: array<{                  // Avertissement, pas bloquant
 *           code:string, libelle:string, niveau:'alerte', detail:?string
 *         }>,
 *         mentions_textes: array<string,string>, // ex: dpe_en_cours → texte
 *         contexte: array                   // bien, mandat, agence chargés (pour debug)
 *       }
 *
 * Principe de l'évaluation :
 *   Pour chaque règle du JSON, on a soit :
 *     - {champ, predicat}     → évalué génériquement (gt:N, not_null, in:…, …)
 *     - {regle: 'identifiant'} → évalué par la fonction PHP correspondante
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_critic_check')) {

    /**
     * @param int    $id_bien
     * @param string $type_support  affiche_vitrine|fiche_client|fiche_visite_interne|dossier_presentation|email|reseaux_sociaux
     * @return array
     */
    function mbi_supports_critic_check(int $id_bien, string $type_support): array
    {
        $pdo = $GLOBALS['pdo'] ?? db();

        // 1. Charge la version active des mentions
        $version = mbi_supports_critic_load_version_active($pdo);
        if ($version === null) {
            return [
                'ok' => false,
                'peut_exporter' => false,
                'mentions_version' => null,
                'blocs_durs_violes' => [['code'=>'MENTIONS_NON_CONFIGUREES','libelle'=>'Aucune version de mentions légales active','niveau'=>'bloc_dur','detail'=>null,'champ'=>null]],
                'alertes' => [],
                'mentions_textes' => [],
                'contexte' => [],
            ];
        }

        $regles = json_decode((string)$version['regles_json'], true);
        if (!is_array($regles)) {
            return [
                'ok' => false,
                'peut_exporter' => false,
                'mentions_version' => $version['version'] ?? null,
                'blocs_durs_violes' => [['code'=>'MENTIONS_JSON_INVALIDE','libelle'=>'JSON des règles invalide','niveau'=>'bloc_dur','detail'=>null,'champ'=>null]],
                'alertes' => [],
                'mentions_textes' => [],
                'contexte' => [],
            ];
        }

        // 2. Charge le contexte métier (bien, mandat, agence, photos)
        $ctx = mbi_supports_critic_load_contexte($pdo, $id_bien);
        if ($ctx === null) {
            return [
                'ok' => false,
                'peut_exporter' => false,
                'mentions_version' => $version['version'] ?? null,
                'blocs_durs_violes' => [['code'=>'BIEN_INTROUVABLE','libelle'=>'Bien introuvable ou hors scope','niveau'=>'bloc_dur','detail'=>null,'champ'=>null]],
                'alertes' => [],
                'mentions_textes' => [],
                'contexte' => [],
            ];
        }

        $blocsDurs = [];
        $alertes   = [];

        // ── Détection contexte transaction (vente / location) ─────────────
        // Le seed V1 cible la vente. Pour les biens en location, certaines
        // règles vente-only n'ont aucun sens (PRIX_DEFINI sur biens.prix_vente,
        // honoraires charge acquéreur/vendeur, prix hors honoraires, etc.).
        // → on les skippe et on ajoute une règle LOYER_DEFINI à la place.
        $txTransaction = strtolower(trim((string)(
            ($ctx['annonce']['type_transaction'] ?? null)
            ?? ($ctx['bien']['type_transaction']  ?? null)
            ?? ''
        )));
        $estLocation   = ($txTransaction === 'location');
        $ctx['_tx_transaction'] = $txTransaction; // dispo pour les règles custom

        // Codes spécifiques VENTE qui n'ont pas de sens en location.
        $codesVenteOnly = [
            'PRIX_DEFINI',
            'AFFICHE_PRIX_HONORAIRES',
            'HONO_PRIX_HONO_INCLUS',
            'HONO_PRIX_HORS_HONO',
            'HONO_INCOHERENCE_PRIX',
            'HONO_TVA_NON_APPLICABLE',
        ];

        // Helper : applique le filtre transaction avant d'évaluer une règle
        $evalAvecFiltre = function (array $r, string $niveau) use (&$blocsDurs, &$alertes, $ctx, $estLocation, $codesVenteOnly): void {
            if ($estLocation && in_array((string)($r['code'] ?? ''), $codesVenteOnly, true)) {
                return;
            }
            mbi_supports_critic_eval($r, $ctx, $niveau, $blocsDurs, $alertes);
        };

        // 3. Bloc dur transverse
        foreach (($regles['transverse']['bloc_dur'] ?? []) as $r) {
            $evalAvecFiltre($r, 'bloc_dur');
        }
        // 3.b Alertes transverses
        foreach (($regles['transverse']['alertes'] ?? []) as $r) {
            $evalAvecFiltre($r, 'alerte');
        }

        // 3.c Règles spécifiques LOCATION (ajoutées dynamiquement) :
        // - LOYER_DEFINI : annonces.loyer doit être > 0
        if ($estLocation) {
            $loyer = (float)(
                ($ctx['annonce']['loyer']    ?? 0)
                ?: ($ctx['annonce']['loyer_cc'] ?? 0)
            );
            if ($loyer <= 0) {
                $blocsDurs['LOYER_DEFINI'] = [
                    'code'    => 'LOYER_DEFINI',
                    'libelle' => 'Loyer renseigné (HC ou CC)',
                    'niveau'  => 'bloc_dur',
                    'detail'  => 'annonces.loyer doit être > 0 pour une location',
                    'champ'   => 'annonces.loyer',
                ];
            }
            $depot = (float)($ctx['annonce']['depot_garantie'] ?? 0);
            if ($depot <= 0) {
                $blocsDurs['DEPOT_GARANTIE_DEFINI'] = [
                    'code'    => 'DEPOT_GARANTIE_DEFINI',
                    'libelle' => 'Dépôt de garantie',
                    'niveau'  => 'bloc_dur',
                    'detail'  => 'Dépôt de garantie doit être renseigné en location',
                    'champ'   => 'annonces.depot_garantie',
                ];
            }
        }

        // 4. Bloc dur spécifique au type_support
        $perType = $regles['par_type_support'][$type_support] ?? null;
        if (is_array($perType)) {
            foreach (($perType['bloc_dur'] ?? []) as $r) {
                $evalAvecFiltre($r, 'bloc_dur');
            }
            // Bloc dur conditionnel : si en copropriété
            if (!empty($perType['bloc_dur_si_copro']) && $ctx['est_copro']) {
                foreach ($perType['bloc_dur_si_copro'] as $r) {
                    $evalAvecFiltre($r, 'bloc_dur');
                }
            }
        }

        // 5. Spécifiques transverses (DPE, honoraires, copro, risques, mandat, carte pro)
        // 5.a Honoraires (bloc_dur appliqués sur tous supports publics — sauf interne qui est exonéré)
        $isInterne = ($type_support === 'fiche_visite_interne');
        if (!$isInterne) {
            foreach (($regles['specifiques']['honoraires']['bloc_dur'] ?? []) as $r) {
                $evalAvecFiltre($r, 'bloc_dur');
            }
            foreach (($regles['specifiques']['honoraires']['alertes'] ?? []) as $r) {
                $evalAvecFiltre($r, 'alerte');
            }
        }

        // 5.b Copropriété (si en copro et pas interne)
        if (!$isInterne && $ctx['est_copro']) {
            foreach (($regles['specifiques']['copropriete']['bloc_dur_si_en_copro'] ?? []) as $r) {
                $evalAvecFiltre($r, 'bloc_dur');
            }
            foreach (($regles['specifiques']['copropriete']['alertes'] ?? []) as $r) {
                $evalAvecFiltre($r, 'alerte');
            }
        }

        // 5.c Risques (bloc dur seulement sur certains supports)
        $risquesBlocDur = $regles['specifiques']['risques']['bloc_dur_par_support'][$type_support] ?? [];
        foreach ($risquesBlocDur as $r) {
            $evalAvecFiltre($r, 'bloc_dur');
        }
        if (!$isInterne) {
            foreach (($regles['specifiques']['risques']['alertes'] ?? []) as $r) {
                $evalAvecFiltre($r, 'alerte');
            }
        }

        // 5.d Mandat de diffusion (transverse, sauf interne)
        if (!$isInterne) {
            foreach (($regles['specifiques']['mandat_diffusion']['bloc_dur'] ?? []) as $r) {
                $evalAvecFiltre($r, 'bloc_dur');
            }
        }

        // 5.e Carte pro (uniquement supports publics)
        $supportsPublics = ['affiche_vitrine','fiche_client','dossier_presentation','email','reseaux_sociaux'];
        if (in_array($type_support, $supportsPublics, true)) {
            foreach (($regles['specifiques']['carte_pro']['bloc_dur_supports_publics'] ?? []) as $r) {
                $evalAvecFiltre($r, 'bloc_dur');
            }
        }

        // 6. Mentions textes à afficher (DPE en_cours / non_soumis…)
        $mentionsTextes = mbi_supports_critic_textes_a_afficher($regles, $ctx);

        // 7. Dédoublonnage par code
        $blocsDurs = mbi_supports_critic_dedup($blocsDurs);
        $alertes   = mbi_supports_critic_dedup($alertes);

        return [
            'ok'                => true,
            'peut_exporter'     => empty($blocsDurs),
            'mentions_version'  => $version['version'] ?? null,
            'blocs_durs_violes' => array_values($blocsDurs),
            'alertes'           => array_values($alertes),
            'mentions_textes'   => $mentionsTextes,
            'contexte'          => $ctx,
        ];
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Chargement et helpers
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_critic_load_version_active')) {
    function mbi_supports_critic_load_version_active(PDO $pdo): ?array
    {
        try {
            $st = $pdo->query("SELECT * FROM mbi_supports_mentions_versions WHERE actif = 1 ORDER BY id DESC LIMIT 1");
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[critic_load_version_active] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mbi_supports_critic_load_contexte')) {
    /**
     * Charge bien + photos + mandat actif + agence + négociateur.
     * Returns null si bien introuvable / hors scope.
     * @return array|null
     */
    function mbi_supports_critic_load_contexte(PDO $pdo, int $id_bien): ?array
    {
        // Scope check (super admin role=1 bypass)
        $roleId    = (int)($_SESSION['id_role'] ?? 0);
        $idSocSess = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
        $isSuperAdmin = ($roleId === 1);

        try {
            $st = $pdo->prepare("SELECT * FROM biens WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id_bien]);
            $bien = $st->fetch(PDO::FETCH_ASSOC);
            if (!$bien) return null;
            if (!$isSuperAdmin && $idSocSess !== null) {
                $idSocBien = (int)($bien['id_societe'] ?? 0);
                if ($idSocBien > 0 && $idSocBien !== $idSocSess) return null;
            }
        } catch (Throwable $e) {
            error_log('[critic_load_contexte bien] ' . $e->getMessage());
            return null;
        }

        // Photos
        try {
            $st = $pdo->prepare("SELECT * FROM biens_photos WHERE id_bien = :id ORDER BY id ASC");
            $st->execute([':id' => $id_bien]);
            $photos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { $photos = []; }

        // Mandat actif
        $mandat = null;
        try {
            $st = $pdo->prepare("
                SELECT * FROM mandats
                WHERE id_bien = :id
                  AND (statut = 'actif' OR statut = 'en_cours' OR statut IS NULL)
                ORDER BY id DESC LIMIT 1
            ");
            $st->execute([':id' => $id_bien]);
            $mandat = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) { $mandat = null; }

        // Annonce active (priorité aux statuts actifs ; fallback dernière non-brouillon)
        $annonce = null;
        try {
            $st = $pdo->prepare("
                SELECT * FROM annonces
                WHERE id_bien = :id
                ORDER BY
                  CASE statut
                    WHEN 'actif' THEN 1
                    WHEN 'active' THEN 1
                    WHEN 'publie' THEN 2
                    WHEN 'publiee' THEN 2
                    WHEN 'diffuse' THEN 3
                    WHEN 'diffusee' THEN 3
                    ELSE 9
                  END,
                  date_modification DESC, id DESC
                LIMIT 1
            ");
            $st->execute([':id' => $id_bien]);
            $annonce = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) { $annonce = null; }

        // Agence — chargement enrichi avec colonnes officielles depuis societes
        // (refactor 2026-05-08 : KBIS/CPI/RCP/GF = niveau société, agences héritent
        // via JOIN au runtime au lieu d'une réplication N→1).
        require_once __DIR__ . '/agence_load_with_societe_docs.php';
        $agence = null;
        $idAgence = (int)($bien['id_agence'] ?? 0);
        if ($idAgence > 0) {
            $agence = agence_load_with_societe_docs($pdo, $idAgence);
        }

        // Négociateur (user)
        $negociateur = null;
        $idNego = (int)($bien['id_user_actuel'] ?? $bien['id_user_negociateur'] ?? 0);
        if ($idNego > 0) {
            try {
                // Récupère telephone_pro (diffusion) — pas telephone (perso, jamais diffusé)
                $st = $pdo->prepare("SELECT id, nom, prenom, email, telephone_pro FROM users WHERE id = :id LIMIT 1");
                $st->execute([':id' => $idNego]);
                $negociateur = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) { $negociateur = null; }
        }

        // Détecte la copropriété
        $estCopro = (int)($bien['bien_en_copropriete'] ?? $bien['copropriete'] ?? $bien['est_copro'] ?? $bien['en_copropriete'] ?? 0) === 1;

        return [
            'bien'        => $bien,
            'photos'      => $photos,
            'mandat'      => $mandat,
            'annonce'     => $annonce,
            'agence'      => $agence,
            'negociateur' => $negociateur,
            'est_copro'   => $estCopro,
        ];
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Évaluation des règles
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_critic_eval')) {
    /**
     * Évalue une règle. Si la règle est violée, ajoute au tableau cible.
     * @param array $regle      {code, libelle?, champ?, predicat?, regle?}
     * @param array $ctx        Contexte chargé
     * @param string $niveau    'bloc_dur' | 'alerte'
     * @param array &$blocsDurs Tableau cible si bloc_dur (modifié par référence)
     * @param array &$alertes   Tableau cible si alerte
     */
    function mbi_supports_critic_eval(array $regle, array $ctx, string $niveau, array &$blocsDurs, array &$alertes): void
    {
        $code   = (string)($regle['code'] ?? 'UNKNOWN');
        $champ  = $regle['champ']    ?? null;
        $pred   = $regle['predicat'] ?? null;
        $rid    = $regle['regle']    ?? null;

        $valide = true;
        $detail = null;

        if ($champ !== null && $pred !== null) {
            $valeur = mbi_supports_critic_get_field($champ, $ctx);
            $valide = mbi_supports_critic_predicat($valeur, $pred);
            if (!$valide) {
                $detail = "Champ {$champ} = " . (is_scalar($valeur) ? var_export($valeur, true) : 'null') . " — prédicat « {$pred} » non satisfait";
            }
        } elseif ($rid !== null) {
            [$valide, $detail] = mbi_supports_critic_regle_custom($rid, $ctx);
        } else {
            // Règle mal formée : on l'ignore silencieusement (log)
            error_log("[critic_eval] règle sans champ/predicat ni regle : {$code}");
            return;
        }

        if (!$valide) {
            $entry = [
                'code'    => $code,
                'libelle' => mbi_supports_critic_libelle($code),
                'niveau'  => $niveau,
                'detail'  => $detail,
                'champ'   => $champ,
            ];
            if ($niveau === 'bloc_dur') $blocsDurs[$code] = $entry;
            else                        $alertes[$code]   = $entry;
        }
    }
}

if (!function_exists('mbi_supports_critic_get_field')) {
    /**
     * Lit un champ dotté style "biens.prix_vente" depuis le contexte.
     */
    function mbi_supports_critic_get_field(string $path, array $ctx)
    {
        $parts = explode('.', $path);
        $racine = array_shift($parts);
        $map = [
            'biens'       => $ctx['bien'] ?? [],
            'mandats'     => $ctx['mandat'] ?? [],
            'agences'     => $ctx['agence'] ?? [],
            'users'       => $ctx['negociateur'] ?? [],
        ];
        $cur = $map[$racine] ?? null;
        if (!is_array($cur)) return null;
        foreach ($parts as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
            $cur = $cur[$k];
        }
        return $cur;
    }
}

if (!function_exists('mbi_supports_critic_predicat')) {
    /**
     * Évalue un prédicat textuel sur une valeur scalaire.
     * Supportés : gt:N, ge:N, lt:N, le:N, eq:V, in:a,b,c, not_null, not_empty
     */
    function mbi_supports_critic_predicat($valeur, string $predicat): bool
    {
        $predicat = trim($predicat);

        if ($predicat === 'not_null') {
            return $valeur !== null && $valeur !== '';
        }
        if ($predicat === 'not_empty') {
            if ($valeur === null) return false;
            if (is_array($valeur)) return !empty($valeur);
            return trim((string)$valeur) !== '';
        }

        if (preg_match('/^(gt|ge|lt|le|eq):(.+)$/', $predicat, $m)) {
            $op = $m[1]; $cible = $m[2];
            if (!is_numeric($valeur)) return false;
            $v = (float)$valeur;
            $c = is_numeric($cible) ? (float)$cible : 0.0;
            return match ($op) {
                'gt' => $v >  $c,
                'ge' => $v >= $c,
                'lt' => $v <  $c,
                'le' => $v <= $c,
                'eq' => $v == $c,
            };
        }

        if (str_starts_with($predicat, 'in:')) {
            $liste = array_map('trim', explode(',', substr($predicat, 3)));
            return in_array((string)$valeur, $liste, true);
        }

        // Prédicat inconnu → considère valide pour ne pas bloquer
        return true;
    }
}

if (!function_exists('mbi_supports_critic_regle_custom')) {
    /**
     * Règles "custom" hardcodées (vérifications complexes nécessitant le contexte global).
     * Renvoie [valide:bool, detail:?string].
     *
     * @return array{0:bool, 1:?string}
     */
    function mbi_supports_critic_regle_custom(string $rid, array $ctx): array
    {
        $bien    = $ctx['bien']        ?? [];
        $photos  = $ctx['photos']      ?? [];
        $mandat  = $ctx['mandat']      ?? null;
        $agence  = $ctx['agence']      ?? null;
        $nego    = $ctx['negociateur'] ?? null;

        switch ($rid) {
            // ── DPE / GES (fallback compatible schéma sans dpe_statut) ────
            case 'dpe_statut_valide':
                $statut = strtolower(trim((string)($bien['dpe_statut'] ?? '')));
                if (in_array($statut, ['present','en_cours','non_soumis'], true)) {
                    return [true, null];
                }
                // Fallback : si pas de champ dpe_statut mais dpe_classe rempli → considéré "present"
                $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
                if ($dpe !== '' && $dpe !== '—' && $dpe !== 'N/A') {
                    return [true, null];
                }
                if ($statut === 'manquant' || $statut === '') {
                    return [false, 'Statut DPE non renseigné (et aucune classe DPE)'];
                }
                return [false, "Statut DPE invalide : « {$statut} »"];

            // ── Mandat / autorisation ─────────────────────────────────────
            case 'mandat_actif_existe':
                if (!is_array($mandat) || empty($mandat)) return [false, 'Aucun mandat actif rattaché'];
                return [true, null];

            case 'autorisation_diffusion_signee':
                // RÈGLE MÉTIER 2026-05-08 (user) — autorisation IMPLICITE pour :
                //   1. Bien en LOCATION : si on a le bien en location chez nous,
                //      l'autorisation de diffusion va de soi (sinon on ne l'aurait
                //      pas en location). Pas besoin de mandat pour valider.
                //   2. Mandat de gestion locative ou de gestion : idem, le mandat
                //      engage le bailleur sur la diffusion.
                $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
                if (str_contains($typeTr, 'location')) {
                    return [true, null];
                }
                if (is_array($mandat) && !empty($mandat)) {
                    $autoris = $mandat['autorisation_diffusion'] ?? $mandat['autorisation_publication'] ?? null;
                    if (!empty($autoris) && (int)$autoris !== 0) return [true, null];
                    $typeM = strtolower((string)($mandat['type'] ?? $mandat['type_mandat'] ?? $mandat['nature'] ?? ''));
                    if (str_contains($typeM, 'gestion') || str_contains($typeM, 'location')) {
                        return [true, null];
                    }
                }
                return [false, is_array($mandat) ? 'Autorisation de diffusion non signée' : 'Mandat absent'];

            case 'autorisation_diffusion_couvre_canaux':
                // Même règle : location → OK auto, sinon vérifie le mandat
                $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
                if (str_contains($typeTr, 'location')) {
                    return [true, null];
                }
                if (is_array($mandat) && !empty($mandat)) {
                    $autoris = $mandat['autorisation_diffusion'] ?? $mandat['autorisation_publication'] ?? null;
                    if (!empty($autoris)) return [true, null];
                    $typeM = strtolower((string)($mandat['type'] ?? $mandat['type_mandat'] ?? $mandat['nature'] ?? ''));
                    if (str_contains($typeM, 'gestion') || str_contains($typeM, 'location')) {
                        return [true, null];
                    }
                }
                return [false, is_array($mandat) ? 'Périmètre de diffusion à vérifier sur le mandat' : 'Mandat absent'];

            case 'mandat_numero_registre':
                if (!is_array($mandat)) return [false, 'Mandat absent'];
                $num = $mandat['numero_registre'] ?? $mandat['numero'] ?? '';
                $ok = is_string($num) && trim($num) !== '';
                return [$ok, $ok ? null : 'Numéro de registre du mandat manquant'];

            case 'duree_mandat_proposee':
                if (!is_array($mandat)) return [false, 'Mandat absent'];
                $duree = $mandat['duree_mois'] ?? $mandat['duree'] ?? null;
                return [!empty($duree), 'Durée de mandat proposée non définie'];

            // ── Photos ────────────────────────────────────────────────────
            case 'au_moins_une_photo_exploitable':
                $n = 0;
                foreach ($photos as $p) {
                    if ((bool)($p['exploitable'] ?? true)) $n++;
                }
                $ok = $n >= 1;
                return [$ok, $ok ? null : 'Aucune photo exploitable trouvée'];

            case 'luminosite_photo_hero_basse':
                // V1 : pas de mesure de luminosité côté serveur — toujours OK (pas d'alerte fausse)
                return [true, null];

            case 'nb_photos_lt_5':
                $n = 0;
                foreach ($photos as $p) if ((bool)($p['exploitable'] ?? true)) $n++;
                return [$n >= 5, $n >= 5 ? null : "Seulement {$n} photos exploitables (< 5 recommandées)"];

            // ── Description ───────────────────────────────────────────────
            case 'description_lt_200':
                $d = (string)($bien['description'] ?? $bien['descriptif'] ?? $bien['descriptif_long'] ?? '');
                $len = mb_strlen(trim($d));
                return [$len >= 200, $len >= 200 ? null : "Description trop courte ({$len} chars)"];

            case 'description_generique':
                // Heuristique simple : description très courte OU pas de mots concrets
                $d = mb_strtolower((string)($bien['description'] ?? $bien['descriptif'] ?? ''));
                $motsConcrets = ['cuisine','chambre','salon','salle','jardin','terrasse','balcon','exposition','parquet','vue'];
                $hits = 0;
                foreach ($motsConcrets as $m) if (str_contains($d, $m)) $hits++;
                return [$hits >= 3, $hits >= 3 ? null : 'Description peu spécifique'];

            // ── DPE / GES ─────────────────────────────────────────────────
            case 'dpe_E_F_G_sans_angle':
                $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
                if (!in_array($dpe, ['E','F','G'], true)) return [true, null];
                // L'angle "marketing" est traité côté score IA — V1 : alerte simple si E/F/G
                return [false, "DPE {$dpe} — un angle énergétique (rénovation, MaPrimeRénov'…) est conseillé"];

            case 'dpe_ges_affiches_ou_motif':
                $dpe = (string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '');
                $statut = (string)($bien['dpe_statut'] ?? '');
                if ($dpe !== '' || in_array($statut, ['en_cours','non_soumis'], true)) return [true, null];
                return [false, 'Étiquettes DPE/GES manquantes (et pas de motif structuré)'];

            // ── Honoraires (vente / location selon le contexte) ──────────────
            case 'honoraires_renseignes':
            case 'honoraires_montant':
                $tx = strtolower(trim((string)($ctx['_tx_transaction'] ?? '')));
                if ($tx === 'location') {
                    // Honoraires location ALUR (annonces.honoraires_location_bail / etat_des_lieux)
                    $hBail = (float)($ctx['annonce']['honoraires_location_bail']  ?? 0);
                    $hEdl  = (float)($ctx['annonce']['honoraires_etat_des_lieux'] ?? 0);
                    if ($hBail > 0 || $hEdl > 0) return [true, null];
                    return [false, 'Honoraires location ALUR non renseignés (bail + état des lieux)'];
                }
                // Vente / entreprise — schéma honoraires_montant + honoraires_inclus + honoraires_detail
                $hMontant = $bien['honoraires_montant'] ?? $bien['montant_honoraires'] ?? $bien['honoraires'] ?? null;
                if ($hMontant !== null && (float)$hMontant > 0) return [true, null];
                $hInclus  = trim((string)($bien['honoraires_inclus']  ?? ''));
                $hDetail  = trim((string)($bien['honoraires_detail']  ?? ''));
                $ok = ($hInclus !== '') || ($hDetail !== '');
                return [$ok, $ok ? null : 'Honoraires non renseignés (ni montant, ni inclus, ni détail)'];

            case 'honoraires_charge_definie':
                // Non applicable en location (l'ALUR définit la charge réglementairement)
                if (strtolower(trim((string)($ctx['_tx_transaction'] ?? ''))) === 'location') {
                    return [true, null];
                }
                $c = strtolower(trim((string)($bien['honoraires_charge'] ?? $bien['honoraires_a_charge'] ?? '')));
                if (in_array($c, ['acquereur','acheteur','vendeur','partage','partagee','partagés'], true)) {
                    return [true, null];
                }
                $hInclus = trim((string)($bien['honoraires_inclus'] ?? ''));
                if ($hInclus !== '') return [true, null];
                return [false, 'Charge des honoraires non définie (acquéreur/vendeur/partagée)'];

            case 'prix_avec_honoraires':
                // Non applicable en location
                if (strtolower(trim((string)($ctx['_tx_transaction'] ?? ''))) === 'location') {
                    return [true, null];
                }
                $prix = $bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0;
                return [(float)$prix > 0, 'Prix de présentation honoraires inclus à afficher'];

            case 'prix_hors_honoraires_si_charge_acq':
                // Non applicable en location
                if (strtolower(trim((string)($ctx['_tx_transaction'] ?? ''))) === 'location') {
                    return [true, null];
                }
                $charge = strtolower(trim((string)($bien['honoraires_charge'] ?? '')));
                $inclus = strtolower(trim((string)($bien['honoraires_inclus'] ?? '')));
                $estChargeAcq = in_array($charge, ['acquereur','acheteur'], true)
                             || str_contains($inclus, 'acqu')
                             || str_contains($inclus, 'achet');
                if (!$estChargeAcq) return [true, null];
                $prixHors = $bien['prix_hors_honoraires'] ?? $bien['prix_net_vendeur'] ?? null;
                if (!empty($prixHors)) return [true, null];
                return [true, 'Idéalement afficher aussi le prix hors honoraires (charge acquéreur)'];

            case 'tva_mention_particulier':
                // V1 : mention attendue sur le support → toujours OK côté data
                return [true, null];

            case 'honoraires_coherence_prix':
                $prix    = (float)($bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0);
                $hono    = (float)($bien['honoraires_montant'] ?? 0);
                $net     = (float)($bien['prix_hors_honoraires'] ?? $bien['prix_net_vendeur'] ?? 0);
                if ($prix <= 0 || $net <= 0 || $hono <= 0) return [true, null];
                $ok = abs(($net + $hono) - $prix) < max(50.0, $prix * 0.005);
                return [$ok, $ok ? null : 'Incohérence prix vs (net vendeur + honoraires)'];

            case 'honoraires_bareme':
            case 'honoraires_bareme_accessible':
                $ok = !empty($agence['bareme_url'] ?? $agence['bareme_honoraires_url'] ?? '');
                return [$ok, $ok ? null : 'Lien barème honoraires non configuré pour l\'agence'];

            case 'honoraires_detail':
                // Détail = montant explicite OU honoraires_detail rempli OU honoraires_inclus rempli
                $hMontant = (float)($bien['honoraires_montant'] ?? 0);
                $hDetail  = trim((string)($bien['honoraires_detail'] ?? ''));
                $hInclus  = trim((string)($bien['honoraires_inclus'] ?? ''));
                $ok = $hMontant > 0 || $hDetail !== '' || $hInclus !== '';
                return [$ok, $ok ? null : 'Détail des honoraires manquant'];

            // ── Agence / négociateur / carte pro ──────────────────────────
            case 'negociateur_rattache':
                $ok = is_array($nego) && !empty($nego['id']);
                return [$ok, $ok ? null : 'Aucun négociateur rattaché'];

            case 'negociateur_coordonnees_completes':
                if (!is_array($nego)) return [false, 'Négociateur absent'];
                // Le téléphone diffusable = telephone_pro (pas le perso)
                $ok = !empty($nego['email']) && !empty($nego['telephone_pro']);
                return [$ok, $ok ? null : 'Email ou téléphone PRO du négociateur manquant'];

            case 'negociateur_presentation_dossier':
                // V1 : présentation = quelques champs minimaux
                if (!is_array($nego)) return [false, 'Négociateur absent'];
                return [!empty($nego['nom']) && !empty($nego['prenom']), 'Nom / prénom du négociateur incomplets'];

            case 'agence_nom_visible':
                $ok = !empty($agence['nom_agence'] ?? $agence['nom'] ?? '');
                return [$ok, $ok ? null : 'Nom d\'agence manquant'];

            case 'agence_coordonnees_completes':
                if (!is_array($agence)) return [false, 'Agence absente'];
                $champsObl = ['adresse','ville','code_postal','telephone'];
                $manquants = [];
                foreach ($champsObl as $c) {
                    if (empty($agence[$c])) $manquants[] = $c;
                }
                return [empty($manquants), $manquants ? 'Manquants : ' . implode(', ', $manquants) : null];

            case 'agence_presentation_dossier':
                $ok = !empty($agence['nom_agence'] ?? $agence['nom'] ?? '');
                return [$ok, $ok ? null : 'Présentation agence (nom) manquante'];

            case 'carte_pro_valide':
                if (!is_array($agence)) return [false, 'Agence absente'];
                $num = (string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? '');
                $val = $agence['carte_pro_validite'] ?? null;
                if (trim($num) === '') return [false, 'Numéro de carte professionnelle manquant'];
                if ($val !== null && $val !== '') {
                    try {
                        $expire = new DateTimeImmutable((string)$val);
                        if ($expire < new DateTimeImmutable()) return [false, 'Carte professionnelle expirée'];
                    } catch (Throwable) { /* on accepte si date non parsable */ }
                }
                return [true, null];

            case 'carte_pro_numero':
                $num = (string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? '');
                $ok = trim($num) !== '';
                return [$ok, $ok ? null : 'Numéro de carte pro manquant'];

            case 'carte_pro_cci':
                $cci = (string)($agence['carte_pro_cci'] ?? $agence['cci'] ?? '');
                $ok = trim($cci) !== '';
                return [$ok, $ok ? null : 'CCI émettrice de la carte pro manquante'];

            case 'carte_pro_validite':
                $val = $agence['carte_pro_validite'] ?? null;
                if (empty($val)) return [false, 'Date de validité carte pro manquante'];
                try {
                    $expire = new DateTimeImmutable((string)$val);
                    if ($expire < new DateTimeImmutable()) return [false, 'Carte pro expirée'];
                    return [true, null];
                } catch (Throwable) {
                    return [false, 'Date de validité carte pro illisible'];
                }

            case 'carte_pro_garant_financier':
                $g = (string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? '');
                $ok = trim($g) !== '';
                return [$ok, $ok ? null : 'Garant financier non renseigné'];

            case 'carte_pro_rc_pro':
                $rc = (string)($agence['rc_pro'] ?? $agence['assurance_rc_pro'] ?? '');
                $ok = trim($rc) !== '';
                return [$ok, $ok ? null : 'Assurance RC professionnelle non renseignée'];

            // ── ERP / risques ─────────────────────────────────────────────
            case 'erp_disponible':
                $ok = !empty($bien['erp_present'] ?? $bien['erp'] ?? $bien['etat_risques_url'] ?? '');
                return [$ok, $ok ? null : 'État des risques (ERP) non disponible / non renseigné'];

            case 'zone_sismique_3_plus':
                $z = (int)($bien['zone_sismique'] ?? 0);
                return [$z < 3, $z < 3 ? null : 'Zone sismique forte — mention recommandée'];

            case 'zone_aleas_argile_fort':
                $a = strtolower((string)($bien['aleas_argile'] ?? ''));
                $ok = $a !== 'fort';
                return [$ok, $ok ? null : 'Aléa argile fort — mention recommandée'];

            case 'zone_plan_inondation':
                $p = (bool)($bien['plan_inondation'] ?? false);
                return [!$p, $p ? 'Plan inondation actif — mention recommandée' : null];

            // ── Copropriété ───────────────────────────────────────────────
            case 'copro_nb_lots':
                $n = (int)($bien['copro_nb_lots'] ?? $bien['nb_lots_copro'] ?? 0);
                return [$n > 0, $n > 0 ? null : 'Nombre de lots de la copropriété non renseigné'];

            case 'copro_quote_part_charges':
                $c = (float)($bien['copro_charges_annuelles'] ?? $bien['quote_part_charges'] ?? 0);
                return [$c > 0, $c > 0 ? null : 'Quote-part des charges courantes non renseignée'];

            case 'copro_procedures_l611':
                if (!array_key_exists('copro_procedures_l611', $bien) && !array_key_exists('procedures_l611', $bien)) {
                    return [false, 'Mention présence/absence procédures L.611-1 manquante'];
                }
                return [true, null];

            case 'copro_travaux_votes_communiques':
                return [true, null]; // V1 : alerte volontairement neutre

            case 'copro_fonds_travaux_alur':
                return [true, null]; // V1 : alerte volontairement neutre

            // ── Stratégie / dossier ───────────────────────────────────────
            case 'strategie_diffusion_definie':
                return [true, 'Stratégie de diffusion à confirmer dans le dossier']; // V1 : note dans le dossier

            case 'info_precontractuelle':
                return [true, null]; // mention statique embarquée par le template

            // ── Email / RGPD ──────────────────────────────────────────────
            case 'email_signature_agence':
                return [true, null];
            case 'email_lien_desinscription':
                return [true, null];
            case 'email_mention_rgpd':
                return [true, null];

            // ── Fiche visite interne ──────────────────────────────────────
            case 'interne_marquage':
            case 'interne_acces_reserve':
            case 'interne_nom_fichier':
            case 'interne_journalisation':
                // Géré par le template + le contrôleur de download (Lot 5/6)
                return [true, null];

            default:
                error_log("[critic_regle_custom] règle inconnue : {$rid}");
                return [true, null]; // ne bloque pas
        }
    }
}

if (!function_exists('mbi_supports_critic_libelle')) {
    /**
     * Libellé human-friendly pour chaque code de règle.
     */
    function mbi_supports_critic_libelle(string $code): string
    {
        $map = [
            'MANDAT_ACTIF'              => 'Mandat actif rattaché au bien',
            'AUTORISATION_DIFFUSION'    => 'Autorisation de diffusion signée',
            'PRIX_DEFINI'               => 'Prix de vente renseigné (> 0)',
            'SURFACE_DEFINIE'           => 'Surface habitable renseignée',
            'TYPE_BIEN_DEFINI'          => 'Type de bien renseigné',
            'ADRESSE_VILLE'             => 'Ville renseignée',
            'HONORAIRES_RENSEIGNES'     => 'Honoraires renseignés',
            'AGENCE_RATTACHEE'          => 'Agence rattachée',
            'NEGOCIATEUR_RATTACHE'      => 'Négociateur rattaché',
            'CARTE_PRO'                 => 'Carte professionnelle valide',
            'PHOTO_EXPLOITABLE'         => 'Au moins une photo exploitable',
            'DPE_STATUT_VALIDE'         => 'Statut DPE renseigné (présent / en cours / non soumis)',
            'PHOTO_PRINCIPALE_SOMBRE'   => 'Photo principale lisible',
            'PHOTOS_PEU_NOMBREUSES'     => 'Au moins 5 photos exploitables',
            'DESCRIPTION_FAIBLE'        => 'Description ≥ 200 caractères',
            'DESCRIPTION_GENERIQUE'     => 'Description spécifique au bien',
            'DPE_DEFAVORABLE_NON_TRAITE'=> 'DPE défavorable traité dans l\'angle marketing',
            'AFFICHE_DPE_GES_CLASSE'    => 'Affichage des étiquettes DPE/GES',
            'AFFICHE_PRIX_HONORAIRES'   => 'Prix avec et sans honoraires affichés',
            'AFFICHE_AGENCE_NOM'        => 'Nom de l\'agence visible',
            'AFFICHE_CARTE_PRO'         => 'Numéro de carte pro mentionné',
            'AFFICHE_COPRO_LOTS'        => 'Nombre de lots de la copropriété',
            'AFFICHE_COPRO_CHARGES'     => 'Quote-part annuelle des charges',
            'AFFICHE_COPRO_PROCEDURES'  => 'Mention procédures L.611-1',
            'FICHE_RISQUES_ERP'         => 'État des risques (ERP) disponible',
            'FICHE_DPE_DETAIL'          => 'Bloc DPE/GES détaillé',
            'FICHE_HONORAIRES_DETAIL'   => 'Détail des honoraires',
            'FICHE_AGENCE_COORDONNEES'  => 'Coordonnées agence complètes',
            'FICHE_NEGOCIATEUR_COORDONNEES'=>'Coordonnées négociateur complètes',
            'FICHE_MENTION_INFORMATION' => 'Information précontractuelle',
            'COPRO_NB_LOTS'             => 'Nombre de lots de la copropriété',
            'COPRO_QUOTE_PART_CHARGES'  => 'Quote-part des charges',
            'COPRO_PROCEDURES_L611'     => 'Procédures L.611-1',
            'COPRO_TRAVAUX_VOTES'       => 'Travaux votés communiqués',
            'COPRO_FONDS_TRAVAUX'       => 'Fonds de travaux ALUR',
            'RISQUES_ERP_DISPONIBLE'    => 'ERP joint ou disponible sur demande',
            'RISQUES_ZONE_SISMIQUE'     => 'Zone sismique 3+',
            'RISQUES_ZONAGE_ARGILE'     => 'Aléa argile',
            'RISQUES_INONDATION'        => 'Plan inondation',
            'AUTORISATION_DIFFUSION_PERIMETRE'=>'Périmètre autorisation de diffusion',
            'CARTE_PRO_NUMERO'          => 'Numéro de carte pro',
            'CARTE_PRO_CCI'             => 'CCI émettrice',
            'CARTE_PRO_VALIDITE'        => 'Carte pro non expirée',
            'CARTE_PRO_GARANT_FINANCIER'=> 'Garant financier mentionné',
            'CARTE_PRO_RC_PRO'          => 'Assurance RC pro mentionnée',
            'HONO_MONTANT'              => 'Montant honoraires',
            'HONO_CHARGE'               => 'Charge des honoraires (acquéreur/vendeur/partagée)',
            'HONO_PRIX_HONO_INCLUS'     => 'Prix honoraires inclus',
            'HONO_PRIX_HORS_HONO'       => 'Prix hors honoraires (si charge acquéreur)',
            'HONO_TVA_NON_APPLICABLE'   => 'Mention TVA pour particuliers',
            'HONO_INCOHERENCE_PRIX'     => 'Cohérence prix / honoraires',
            'HONO_BAREME_AGENCE'        => 'Barème agence accessible',
            'INTERNE_FILIGRANE'         => 'Filigrane "INTERNE — NE PAS DIFFUSER"',
            'INTERNE_BADGE_ROUGE'       => 'Badge rouge en en-tête',
            'INTERNE_HORS_PUBLIC'       => 'Téléchargement réservé agence',
            'INTERNE_NOM_FICHIER'       => 'Suffixe "_INTERNE" dans le nom de fichier',
            'INTERNE_JOURNALISATION'    => 'Journalisation des téléchargements',
            'DOSSIER_AGENCE_PRESENTATION'=>'Présentation agence',
            'DOSSIER_NEGOCIATEUR_PRESENTATION'=>'Présentation négociateur',
            'DOSSIER_STRATEGIE_DIFFUSION'=>'Stratégie de diffusion',
            'DOSSIER_HONORAIRES_BAREME' => 'Barème honoraires (dossier)',
            'DOSSIER_DUREE_MANDAT_PROPOSEE'=>'Durée de mandat proposée',
            'MANDAT_NUMERO_REGISTRE'    => 'Numéro de registre du mandat',
            'EMAIL_EXPEDITEUR_AGENCE'   => 'Signature complète de l\'agence',
            'EMAIL_DESINSCRIPTION'      => 'Lien de désinscription',
            'EMAIL_RGPD_MENTION'        => 'Mention RGPD',
        ];
        return $map[$code] ?? $code;
    }
}

if (!function_exists('mbi_supports_critic_textes_a_afficher')) {
    /**
     * Renvoie les mentions textes spécifiques à apposer sur le support
     * (selon le statut DPE par exemple).
     * @return array<string,string>
     */
    function mbi_supports_critic_textes_a_afficher(array $regles, array $ctx): array
    {
        $textes = [];
        $bien = $ctx['bien'] ?? [];
        $statut = (string)($bien['dpe_statut'] ?? '');
        $matriceTextes = $regles['specifiques']['dpe_ges']['mentions_textes'] ?? [];
        if ($statut === 'en_cours' && !empty($matriceTextes['dpe_en_cours'])) {
            $textes['dpe_en_cours'] = $matriceTextes['dpe_en_cours'];
        }
        if ($statut === 'non_soumis' && !empty($matriceTextes['dpe_non_soumis_R126_15'])) {
            $textes['dpe_non_soumis'] = $matriceTextes['dpe_non_soumis_R126_15'];
        }
        return $textes;
    }
}

if (!function_exists('mbi_supports_critic_dedup')) {
    function mbi_supports_critic_dedup(array $items): array
    {
        // Items indexés par code → array_values en sortie
        $out = [];
        foreach ($items as $code => $item) {
            if (is_int($code)) {
                $key = $item['code'] ?? uniqid('rule_', true);
            } else {
                $key = $code;
            }
            $out[$key] = $item;
        }
        return $out;
    }
}
