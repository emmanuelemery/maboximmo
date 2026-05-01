<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_pdf_generator.php — Générateur PDF Ma Box Communication
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Orchestre la génération d'un support PDF :
 *   1. Charge bien + photos + agence + style + dernier score
 *   2. Lance le moteur critique (option : force_export pour bypass)
 *   3. Sélectionne le template selon le type_support
 *   4. Génère le PDF via TCPDF
 *   5. Stocke fichier dans uploads/supports/drafts/
 *   6. INSERT dans mbi_supports_commerciaux (statut = draft)
 *
 * API publique :
 *   mbi_supports_pdf_generer(int $id_bien, string $type_support,
 *                            ?string $orientation_user = null,
 *                            array $options = []): array
 *
 *     $options peut contenir :
 *       - force_export    bool  : passer outre les blocs durs (réservé tests)
 *       - angle_marketing string: famille|investisseur|premium|premier_achat|generique|autre
 *       - id_user         int   : auteur (sinon current_user_id())
 *       - style_code      string: code style global à appliquer
 *
 *     Renvoie :
 *       {
 *         ok:bool, support_id:int, version:int, fichier_pdf:string,
 *         critique:array, statut:string, erreur:?string
 *       }
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/mbi_supports_helpers.php';
require_once __DIR__ . '/mbi_supports_critic_engine.php';
require_once __DIR__ . '/mbi_supports_score_engine.php';

if (!function_exists('mbi_supports_pdf_generer')) {

    function mbi_supports_pdf_generer(
        int $id_bien,
        string $type_support,
        ?string $orientation_user = null,
        array $options = []
    ): array {
        $pdo = $GLOBALS['pdo'] ?? db();
        $idUser = (int)($options['id_user'] ?? (function_exists('current_user_id') ? current_user_id() : 0));

        $typesValides = ['affiche_vitrine','fiche_client','fiche_visite_interne','dossier_presentation'];
        if (!in_array($type_support, $typesValides, true)) {
            return ['ok'=>false,'support_id'=>0,'version'=>0,'fichier_pdf'=>'',
                    'critique'=>[],'statut'=>'erreur','erreur'=>"type_support invalide : {$type_support}"];
        }

        // 1. Critique préalable (charge le bien + scope check)
        $critique = mbi_supports_critic_check($id_bien, $type_support);
        if (!$critique['ok']) {
            return ['ok'=>false,'support_id'=>0,'version'=>0,'fichier_pdf'=>'',
                    'critique'=>$critique,'statut'=>'erreur','erreur'=>'critique_ko'];
        }
        if (!$critique['peut_exporter'] && empty($options['force_export'])) {
            return ['ok'=>false,'support_id'=>0,'version'=>0,'fichier_pdf'=>'',
                    'critique'=>$critique,'statut'=>'refuse',
                    'erreur'=>'export_refuse_par_critique'];
        }

        $bien        = $critique['contexte']['bien']        ?? [];
        $photos      = $critique['contexte']['photos']      ?? [];
        $agence      = $critique['contexte']['agence']      ?? [];
        $negociateur = $critique['contexte']['negociateur'] ?? [];
        $mandat      = $critique['contexte']['mandat']      ?? null;
        $annonce     = $critique['contexte']['annonce']     ?? null;

        // Si une annonce existe pour le bien, on enrichit le bien effectif :
        // - bien.designation < annonce.titre / titre_ia / titre_seo (si dispo)
        // - bien.description < annonce.description / texte_ia (texte commercial rédigé)
        // Les surcharges éditeur restent prioritaires (appliquées plus loin).
        if (is_array($annonce)) {
            $titreA = trim((string)($annonce['titre_ia'] ?? $annonce['titre'] ?? $annonce['titre_seo'] ?? ''));
            if ($titreA !== '') $bien['_annonce_titre'] = $titreA;
            $descA = trim((string)($annonce['description'] ?? $annonce['texte_ia'] ?? ''));
            if ($descA !== '') {
                $bien['_annonce_description'] = $descA;
                // Le bien.description du PDF = description annonce si plus longue / non vide
                if (mb_strlen($descA) > mb_strlen((string)($bien['description'] ?? ''))) {
                    $bien['description'] = $descA;
                }
            }
        }

        // Si on régénère depuis un support source (édition), récupère ses surcharges
        $surcharges = [];
        $idSourceSupport = (int)($options['source_support_id'] ?? 0);
        if ($idSourceSupport > 0) {
            try {
                $st = $pdo->prepare("SELECT * FROM mbi_supports_commerciaux WHERE id = :id LIMIT 1");
                $st->execute([':id' => $idSourceSupport]);
                $surcharges = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) { $surcharges = []; }
        }
        // Surcharges directes via options (POST de l'éditeur)
        foreach (['titre_personnalise','accroche','description_personnalisee','photo_hero_id_personnalise'] as $k) {
            if (isset($options[$k]) && $options[$k] !== '') {
                $surcharges[$k] = $options[$k];
            }
        }
        if (!empty($surcharges)) {
            $bien = mbi_supports_appliquer_surcharges($bien, $surcharges);
        }

        // 2. Style applicable
        $idSociete = (int)($bien['id_societe'] ?? 0);
        $idAgence  = (int)($bien['id_agence']  ?? 0);
        $style = mbi_supports_resoudre_style(
            $idSociete ?: null,
            $idAgence  ?: null,
            $options['style_code'] ?? null
        );

        // 3. Dernier score (utile pour photo héro suggérée + angle)
        $dernierScore = mbi_supports_score_get_dernier($id_bien);
        // Photo héro : surcharge éditeur > photo IA suggérée
        $heroIdSugg = !empty($bien['_photo_hero_id_force'])
            ? (int)$bien['_photo_hero_id_force']
            : ($dernierScore && !empty($dernierScore['photo_hero_id']) ? (int)$dernierScore['photo_hero_id'] : null);
        $angle = $options['angle_marketing']
            ?? ($dernierScore['angle_recommande'] ?? null)
            ?? 'generique';
        $anglesValides = ['famille','investisseur','premium','premier_achat','generique','autre'];
        if (!in_array($angle, $anglesValides, true)) $angle = 'generique';

        // 4. Numéro de version + mode UPDATE si support source en draft
        // → Régénération sur un brouillon : on UPDATE la même ligne (pas de pollution
        //   d'historique). On crée une nouvelle ligne uniquement si le support source
        //   est validé OU si on génère depuis zéro.
        $modeUpdate = false;
        $version = 1;
        $supportId = 0;

        if ($idSourceSupport > 0) {
            try {
                $st = $pdo->prepare("SELECT statut, version FROM mbi_supports_commerciaux WHERE id = :id AND deleted_at IS NULL LIMIT 1");
                $st->execute([':id' => $idSourceSupport]);
                $sourceInfo = $st->fetch(PDO::FETCH_ASSOC);
                if ($sourceInfo && $sourceInfo['statut'] === 'draft') {
                    // On reste sur la même ligne en draft
                    $modeUpdate = true;
                    $supportId  = $idSourceSupport;
                    $version    = (int)$sourceInfo['version'];
                }
            } catch (Throwable) {}
        }

        if (!$modeUpdate) {
            // Nouvelle version : V+1 de la plus haute version validée du même type
            // (les brouillons ne comptent pas pour le numéro de version officiel)
            try {
                $st = $pdo->prepare("
                    SELECT COALESCE(MAX(version), 0) FROM mbi_supports_commerciaux
                    WHERE id_bien = :b AND type_support = :t
                      AND statut IN ('valide','diffuse','archive')
                      AND deleted_at IS NULL
                ");
                $st->execute([':b' => $id_bien, ':t' => $type_support]);
                $version = ((int)$st->fetchColumn()) + 1;
            } catch (Throwable) {
                $version = 1;
            }
        }

        $isInterne = ($type_support === 'fiche_visite_interne');
        $nomFichier = mbi_supports_nom_fichier($bien, $type_support, $version, $isInterne);

        // 5. Pré-INSERT en draft OU réutilisation de la ligne existante
        try {
            if ($modeUpdate) {
                // Met à jour les surcharges + nom_fichier sur la ligne existante
                $up = $pdo->prepare("
                    UPDATE mbi_supports_commerciaux SET
                      angle_marketing            = :angle,
                      orientation_user           = :brief,
                      titre_personnalise         = :titre,
                      accroche                   = :accroche,
                      description_personnalisee  = :desc,
                      photo_hero_id_personnalise = :hero,
                      nom_fichier                = :nom,
                      derniere_erreur            = NULL,
                      updated_at                 = NOW()
                    WHERE id = :id
                ");
                $up->execute([
                    ':angle'    => $angle,
                    ':brief'    => $orientation_user,
                    ':titre'    => $surcharges['titre_personnalise'] ?? null,
                    ':accroche' => $surcharges['accroche'] ?? null,
                    ':desc'     => $surcharges['description_personnalisee'] ?? null,
                    ':hero'     => $surcharges['photo_hero_id_personnalise'] ?? null,
                    ':nom'      => $nomFichier,
                    ':id'       => $supportId,
                ]);
            } else {
                $supportId = mbi_supports_pdf_insert_draft($pdo, [
                'id_bien'         => $id_bien,
                'id_annonce'      => null,
                'id_mandat'       => $mandat['id'] ?? null,
                'id_user'         => $idUser,
                'id_societe'      => $idSociete,
                'id_agence'       => $idAgence,
                'type_support'    => $type_support,
                'titre_support'   => mbi_supports_pdf_titre_defaut($bien, $type_support),
                'angle_marketing' => $angle,
                'orientation_user'=> $orientation_user,
                'score_commercial_id' => $dernierScore['id'] ?? null,
                'mentions_version'=> $critique['mentions_version'] ?? '—',
                'is_interne'      => $isInterne ? 1 : 0,
                'version'         => $version,
                'source_generation' => 'mixte',
                'nom_fichier'     => $nomFichier,
                // Surcharges propagées (pour édition continue)
                'titre_personnalise'         => $surcharges['titre_personnalise']         ?? null,
                'accroche'                   => $surcharges['accroche']                   ?? null,
                'description_personnalisee'  => $surcharges['description_personnalisee']  ?? null,
                'photo_hero_id_personnalise' => $surcharges['photo_hero_id_personnalise'] ?? null,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[mbi_supports_pdf draft] ' . $e->getMessage());
            return ['ok'=>false,'support_id'=>0,'version'=>$version,'fichier_pdf'=>'',
                    'critique'=>$critique,'statut'=>'erreur',
                    'erreur'=>'insert_draft_fail: ' . $e->getMessage()];
        }

        // 6. Génère le PDF
        try {
            $context = [
                'bien'        => $bien,
                'photos'      => $photos,
                'agence'      => $agence,
                'negociateur' => $negociateur,
                'mandat'      => $mandat,
                'annonce'     => $annonce,
                'style'       => $style,
                'critique'    => $critique,
                'score'       => $dernierScore,
                'angle'       => $angle,
                'orientation_user' => $orientation_user,
                'photo_hero_id_suggestion' => $heroIdSugg,
                'version'     => $version,
                'is_interne'  => $isInterne,
            ];

            // Crée le dossier de drafts si absent (idempotent)
            if (!is_dir(MBI_SUPPORTS_UPLOAD_DRAFT)) {
                @mkdir(MBI_SUPPORTS_UPLOAD_DRAFT, 0775, true);
            }
            $cheminAbsolu = MBI_SUPPORTS_UPLOAD_DRAFT . $nomFichier;
            mbi_supports_pdf_render($cheminAbsolu, $type_support, $context);

            // 7. Finalise — UPDATE statut + chemins
            $cheminRel = '/uploads/supports/drafts/' . $nomFichier;
            mbi_supports_pdf_finaliser($pdo, $supportId, [
                'statut'          => 'draft',
                'fichier_pdf_path'=> $cheminRel,
                'chemin_stockage' => $cheminRel,
                'storage_driver'  => 'local',
                'date_generation' => date('Y-m-d H:i:s'),
                'contenu_json'    => json_encode([
                    'angle' => $angle,
                    'orientation_user' => $orientation_user,
                    'snapshot_dpe' => [
                        'classe' => $bien['dpe_classe']  ?? null,
                        'statut' => $bien['dpe_statut']  ?? null,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'snapshot_dpe_classe' => $bien['dpe_classe']  ?? null,
                'snapshot_dpe_statut' => $bien['dpe_statut']  ?? null,
            ]);

            return [
                'ok'         => true,
                'support_id' => $supportId,
                'version'    => $version,
                'fichier_pdf'=> $cheminRel,
                'critique'   => $critique,
                'statut'     => 'draft',
                'erreur'     => null,
            ];
        } catch (Throwable $e) {
            error_log('[mbi_supports_pdf render] ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
                . "\n" . $e->getTraceAsString());
            mbi_supports_pdf_marquer_erreur($pdo, $supportId, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return [
                'ok'         => false,
                'support_id' => $supportId,
                'version'    => $version,
                'fichier_pdf'=> '',
                'critique'   => $critique,
                'statut'     => 'erreur',
                'erreur'     => 'render_fail: ' . $e->getMessage(),
            ];
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Render : choisit le template + délègue
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_pdf_render')) {
    function mbi_supports_pdf_render(string $cheminAbsolu, string $type_support, array $context): void
    {
        // Charge TCPDF
        $tcpdfPath = __DIR__ . '/../tcpdf/tcpdf.php';
        if (!is_file($tcpdfPath)) {
            throw new RuntimeException("TCPDF introuvable : {$tcpdfPath}");
        }
        require_once $tcpdfPath;

        // Charge le template
        $tplFile = match($type_support) {
            'affiche_vitrine'      => 'mbi_supports_tpl_affiche_vitrine.php',
            'fiche_client'         => 'mbi_supports_tpl_fiche_client.php',
            'fiche_visite_interne' => 'mbi_supports_tpl_fiche_visite_interne.php',
            'dossier_presentation' => 'mbi_supports_tpl_fiche_client.php', // V1 fallback
            default                => 'mbi_supports_tpl_fiche_client.php',
        };
        require_once __DIR__ . '/' . $tplFile;

        // Appelle le builder du template
        $fn = 'mbi_supports_tpl_' . str_replace('-','_', preg_replace('/^.*tpl_(.+)\.php$/', '$1', $tplFile)) . '_build';
        if (!function_exists($fn)) {
            throw new RuntimeException("Builder template introuvable : {$fn}");
        }
        $pdf = $fn($context);
        if (!($pdf instanceof TCPDF)) {
            throw new RuntimeException("Le builder n'a pas retourné un TCPDF");
        }
        // Sauvegarde sur disque
        $pdf->Output($cheminAbsolu, 'F');
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Helpers persistence
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_pdf_insert_draft')) {
    function mbi_supports_pdf_insert_draft(PDO $pdo, array $data): int
    {
        $cols = ['id_bien','id_annonce','id_mandat','id_user','id_societe','id_agence',
                 'type_support','titre_support','angle_marketing','orientation_user',
                 'score_commercial_id','mentions_version','is_interne','version',
                 'source_generation','nom_fichier',
                 'titre_personnalise','accroche','description_personnalisee','photo_hero_id_personnalise'];
        $placeholders = ':' . implode(', :', $cols);
        $sql = "INSERT INTO mbi_supports_commerciaux (`" . implode('`,`', $cols) . "`, statut, created_at, updated_at)
                VALUES ({$placeholders}, 'draft', NOW(), NOW())";
        $st = $pdo->prepare($sql);
        foreach ($cols as $c) {
            $st->bindValue(":{$c}", $data[$c] ?? null);
        }
        $st->execute();
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('mbi_supports_pdf_finaliser')) {
    function mbi_supports_pdf_finaliser(PDO $pdo, int $support_id, array $patch): void
    {
        $allowed = ['statut','fichier_pdf_path','fichier_image_path','chemin_stockage',
                    'storage_driver','contenu_json','date_generation',
                    'snapshot_dpe_classe','snapshot_dpe_statut','derniere_erreur'];
        $sets = [];
        $params = [':id' => $support_id];
        foreach ($patch as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = "`{$k}` = :{$k}";
                $params[":{$k}"] = $v;
            }
        }
        if (empty($sets)) return;
        $sets[] = "updated_at = NOW()";
        $sql = "UPDATE mbi_supports_commerciaux SET " . implode(', ', $sets) . " WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->execute($params);
    }
}

if (!function_exists('mbi_supports_pdf_marquer_erreur')) {
    function mbi_supports_pdf_marquer_erreur(PDO $pdo, int $support_id, string $msg): void
    {
        try {
            $st = $pdo->prepare("UPDATE mbi_supports_commerciaux
                                 SET statut='erreur', derniere_erreur=:e, updated_at=NOW()
                                 WHERE id=:id");
            $st->execute([':e' => mb_substr($msg, 0, 60000), ':id' => $support_id]);
        } catch (Throwable) {}
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Titre par défaut
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_pdf_titre_defaut')) {
    function mbi_supports_pdf_titre_defaut(array $bien, string $type_support): string
    {
        $designation = (string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien');
        $designation = mb_substr(trim($designation), 0, 150);
        return match($type_support) {
            'affiche_vitrine'      => 'Affiche vitrine — ' . $designation,
            'fiche_client'         => 'Fiche client — ' . $designation,
            'fiche_visite_interne' => 'Fiche visite interne — ' . $designation,
            'dossier_presentation' => 'Dossier de présentation — ' . $designation,
            default                => 'Support — ' . $designation,
        };
    }
}
