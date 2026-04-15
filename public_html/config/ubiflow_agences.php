<?php
declare(strict_types=1);

/**
 * CONFIGURATION UBIFLOW — MULTI-AGENCES
 * =====================================
 *
 * Définit les flux XML indépendants produits par `api/flux/ubiflow.php`
 * et déposés sur `ftp.ubiflow.net` par `api/flux/ubiflow_ftp.php`.
 *
 * Les `id_agence` correspondent à la table `agences` de MaBoxImmo.
 * Vérifiés en base le 2026-04-11 lors de l'audit V3.
 *
 * ═══ 5 AGENCES ACTIVES pour la diffusion Ubiflow ═══
 *   chaponost    (id=4)  → 69630  — Société 1 (Régie Emery)
 *   lyon         (id=3)  → 69007  — Société 1
 *   vienne       (id=1)  → 38200  — Société 1  (créée depuis l'audit V2)
 *   rio (Riom)   (id=5)  → 63200  — Société 2 (Emery Immo)
 *   chamalieres  (id=6)  → 63400  — Société 2
 *
 * Pour ajouter une agence :
 *   1. Créer l'agence dans MaBoxImmo (table `agences`).
 *   2. Ajouter une entrée ci-dessous avec le vrai id_agence + slug unique.
 *   3. Définir les credentials FTP dans config/db.php :
 *        define('UBIFLOW_FTP_USER_<SLUG_UC>', '...');
 *        define('UBIFLOW_FTP_PASS_<SLUG_UC>', '...');
 *   4. Obtenir la validation flux@ubiflow.net + accès FTP dédié.
 */

if (!function_exists('ubiflow_agences_all')) {
    function ubiflow_agences_all(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [
            // ═══════════════════════════════════════════════════════
            // 5 AGENCES ACTIVES — diffusion Ubiflow production
            // ═══════════════════════════════════════════════════════

            // Slugs standardisés : ville[_arrondissement] sans préfixe.
            // Les login_ftp reprennent le schéma option A (société + ville).
            // login_ftp = identifiant FTP Ubiflow réel (format ag{N})
            // → sert aussi de nom de fichier XML/ZIP : ag697116.xml, ag697116.zip
            // Credentials reçus le 13/04/2026 — stockés dans config/ubiflow_credentials.local.php

            'chaponost' => [
                'id_agence'   => 4,
                'login_ftp'   => 'ag697116',
                'nom'         => 'REGIE EMERY CHAPONOST',
                'code_postal' => '69630',
                'ville'       => 'CHAPONOST',
                'id_societe'  => 1,
                'actif'       => true,
            ],

            'lyon_07' => [
                'id_agence'   => 3,
                'login_ftp'   => 'ag697117',
                'nom'         => 'EMERY IMMOBILIER LYON',
                'code_postal' => '69007',
                'ville'       => 'LYON 07',
                'id_societe'  => 1,
                'actif'       => true,
            ],

            'vienne' => [
                'id_agence'   => 1,
                'login_ftp'   => 'ag383203',
                'nom'         => 'REGIE EMERY VIENNE',
                'code_postal' => '38200',
                'ville'       => 'VIENNE',
                'id_societe'  => 1,
                'actif'       => true,
            ],

            'riom' => [
                'id_agence'   => 5,
                'login_ftp'   => 'ag631369',
                'nom'         => 'EMERY IMMOBILIER RIOM',
                'code_postal' => '63200',
                'ville'       => 'RIOM',
                'id_societe'  => 2,
                'actif'       => true,
            ],

            // Chamalières : en attente de commande LeBonCoin (13/04/2026).
            // Passer actif=true et ajouter les credentials dès réception.
            'chamalieres' => [
                'id_agence'   => 6,
                'login_ftp'   => 'emery_immo_chamalieres', // à remplacer par ag{N} dès credentials reçus
                'nom'         => 'EMERY IMMO CHAMALIERES',
                'code_postal' => '63400',
                'ville'       => 'CHAMALIERES',
                'id_societe'  => 2,
                'actif'       => false,
            ],

            // ═══════════════════════════════════════════════════════
            // Agences présentes en DB mais non diffusées par défaut.
            // Basculer actif=true pour les inclure dans le mode --all.
            // ═══════════════════════════════════════════════════════

            'mions' => [
                'id_agence'   => 2,
                'login_ftp'   => 'regie_emery_mions',
                'nom'         => 'REGIE EMERY MIONS',
                'code_postal' => '69780',
                'ville'       => 'MIONS',
                'id_societe'  => 1,
                'actif'       => false,
            ],

            'saint_martin' => [
                'id_agence'   => 7,
                'login_ftp'   => 'st_martin_la_plaine',
                'nom'         => 'ST MARTIN LA PLAINE',
                'code_postal' => '42800',
                'ville'       => 'ST MARTIN LA PLAINE',
                'id_societe'  => 3,
                'actif'       => false,
            ],
        ];

        return $cache;
    }

    function ubiflow_agence_get(string $slug): ?array {
        $slug = strtolower(trim($slug));
        $all = ubiflow_agences_all();
        return $all[$slug] ?? null;
    }

    function ubiflow_agences_actives(): array {
        return array_filter(
            ubiflow_agences_all(),
            static fn($a) => !empty($a['actif']) && !empty($a['id_agence'])
        );
    }

    /**
     * Retourne le nom de fichier Ubiflow (sans extension) pour un slug donné.
     * Ex: get_ubiflow_filename('chaponost') → 'ag697116'
     * Utilisé pour le nommage des XML et ZIP : ag697116.xml, ag697116.zip
     */
    function get_ubiflow_filename(string $slug): ?string {
        $cfg = ubiflow_agence_get($slug);
        return $cfg ? ($cfg['login_ftp'] ?? null) : null;
    }
}

// Compat descendante
return ubiflow_agences_all();
