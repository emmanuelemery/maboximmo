<?php
declare(strict_types=1);

/**
 * Bulk import de diagnostics (PDF) depuis un dossier local (récursif).
 *
 * Objectif :
 *  - analyser chaque PDF (texte ou scanné) via l'existant MaBoxImmo
 *  - regrouper par bien (adresse + CP + ville + lot/situation si dispo)
 *  - créer un bien brouillon + (optionnel) propriétaire + immeuble
 *  - attacher les PDFs (biens_documents) + tracer (dpe_diags)
 *  - produire un rapport CSV (orphans / ambigu / obsolètes)
 *
 * Usage :
 *   C:\xampp\php\php.exe scripts\diag_bulk_import.php --list-agences
 *   C:\xampp\php\php.exe scripts\diag_bulk_import.php --dir "C:\imports\diagnostics_lyon" --societe 1 --agence 2 --dry-run
 *   C:\xampp\php\php.exe scripts\diag_bulk_import.php --dir "C:\imports\diagnostics_lyon" --societe 1 --agence 2 --user 12 --apply
 *
 * Notes :
 *  - Par défaut : dry-run (analyse + rapport + cache) sans écriture BDD ni copie des PDFs.
 *  - Cache : uploads/_import_cache/diag_bulk/<sha1>.json (évite de repayer l'analyse IA à chaque relance).
 */

require_once __DIR__ . '/../config/db.php';

// Dépendances extraction/IA existantes
require_once __DIR__ . '/../inc/bien_import_parser.php';
require_once __DIR__ . '/../inc/bien_intake_ia.php';
require_once __DIR__ . '/../inc/bien_intake_ocr.php';
require_once __DIR__ . '/../inc/bien_intake_search.php';
require_once __DIR__ . '/../inc/bien_form_loader.php';

function stderr(string $s): void { fwrite(STDERR, $s); }

function parse_args(array $argv): array
{
    $args = [
        'dir' => '',
        'societe' => 0,
        'agence' => 0,
        'user' => 0,
        'apply' => false,
        'dry_run' => true,
        'limit' => 0,
        'force_type' => 'diag',
        'refresh_cache' => false,
        'list_agences' => false,
        'list_users' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $a = (string)$argv[$i];
        if ($a === '--apply') { $args['apply'] = true; $args['dry_run'] = false; continue; }
        if ($a === '--dry-run') { $args['dry_run'] = true; $args['apply'] = false; continue; }
        if ($a === '--refresh-cache') { $args['refresh_cache'] = true; continue; }
        if ($a === '--list-agences') { $args['list_agences'] = true; continue; }
        if ($a === '--list-users') { $args['list_users'] = true; continue; }

        $next = ($i + 1 < count($argv)) ? (string)$argv[$i + 1] : '';
        $eat = function () use (&$i) { $i++; };

        if ($a === '--dir' && $next !== '') { $args['dir'] = $next; $eat(); continue; }
        if ($a === '--societe' && ctype_digit($next)) { $args['societe'] = (int)$next; $eat(); continue; }
        if ($a === '--agence' && ctype_digit($next)) { $args['agence'] = (int)$next; $eat(); continue; }
        if ($a === '--user' && ctype_digit($next)) { $args['user'] = (int)$next; $eat(); continue; }
        if ($a === '--limit' && ctype_digit($next)) { $args['limit'] = (int)$next; $eat(); continue; }
        if ($a === '--force-type' && $next !== '') { $args['force_type'] = strtolower(trim($next)); $eat(); continue; }
    }

    return $args;
}

function load_openai_config_if_any(): void
{
    // Reprend le pattern de inc/bootstrap.php (sans les headers/HTTP).
    $candidates = [
        __DIR__ . '/../openai_config.php',
        __DIR__ . '/../maboximmo_openai_config.php',
        __DIR__ . '/../../openai_config.php',
        __DIR__ . '/../../maboximmo_openai_config.php',
        __DIR__ . '/../../u630423897/maboximmo_openai_config.php',
        __DIR__ . '/../../u630423897/openai_config.php',
        __DIR__ . '/../home/openai_config.php',
        '/home/u630423897/openai_config.php',
        '/home/u630423897/maboximmo_openai_config.php',
        '/home/u630423897/u630423897/maboximmo_openai_config.php',
        '/home/u630423897/u630423897/openai_config.php',
    ];
    foreach ($candidates as $c) {
        if (is_file($c) && is_readable($c)) {
            require_once $c;
            break;
        }
    }

    $key = getenv('OPENAI_API_KEY')
        ?: ($_ENV['OPENAI_API_KEY'] ?? '')
        ?: ($_SERVER['OPENAI_API_KEY'] ?? '')
        ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
    $GLOBALS['OPENAI_API_KEY'] = $key;
}

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0775, true)) {
        throw new RuntimeException("Impossible de créer le dossier: {$path}");
    }
}

function norm_key(string $s): string
{
    $s = trim(mb_strtolower($s));
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;
    // Translit basique (accents → ascii)
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (is_string($t) && $t !== '') $s = $t;
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?: $s;
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;
    return trim($s);
}

function build_address_key(array $fields): string
{
    $adr = (string)($fields['adresse_1'] ?? '');
    $cp = (string)($fields['code_postal'] ?? '');
    $ville = (string)($fields['ville'] ?? '');
    return norm_key($adr) . '|' . preg_replace('/\D/', '', $cp) . '|' . norm_key($ville);
}

function build_lot_key(array $fields): string
{
    $lot = trim((string)($fields['lot_principal'] ?? ''));
    if ($lot !== '') return 'lot:' . norm_key($lot);
    $situ = trim((string)($fields['adresse_situation'] ?? ''));
    if ($situ !== '') return 'situ:' . norm_key($situ);
    $etage = trim((string)($fields['etage'] ?? ''));
    if ($etage !== '') return 'etage:' . norm_key($etage);
    return 'unknown';
}

function apply_dpe_vierge_rules(string $docType, array &$fields): void
{
    $isDpeDoc = in_array($docType, ['dpe', 'dossier_diagnostics'], true);
    $noDpeValues = empty($fields['dpe_classe']) && empty($fields['ges_classe'])
        && empty($fields['dpe_valeur']) && empty($fields['ges_valeur']);
    if (!empty($fields['dpe_vierge']) || ($isDpeDoc && $noDpeValues)) {
        $fields['dpe_vierge'] = 1;
        if (empty($fields['dpe_classe'])) $fields['dpe_classe'] = 'vierge';
        if (empty($fields['ges_classe'])) $fields['ges_classe'] = 'vierge';
        if (!isset($fields['dpe_valeur']) || $fields['dpe_valeur'] === null || $fields['dpe_valeur'] === '') {
            $fields['dpe_valeur'] = 0;
        }
        if (!isset($fields['ges_valeur']) || $fields['ges_valeur'] === null || $fields['ges_valeur'] === '') {
            $fields['ges_valeur'] = 0;
        }
    }
}

function analyse_pdf_with_cache(string $pdfPath, string $cachePath, string $forceType, bool $refreshCache): array
{
    $size = (int)@filesize($pdfPath);
    $mtime = (int)@filemtime($pdfPath);

    if (!$refreshCache && is_file($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached) && ($cached['_file_size'] ?? null) === $size && ($cached['_file_mtime'] ?? null) === $mtime) {
            return $cached;
        }
    }

    $text = BienImportParser::extractText($pdfPath);
    $textLen = mb_strlen(trim($text));
    $usedOcr = false;
    $result = null;

    $isProbablyScanned = ($textLen < 200);
    if (!$isProbablyScanned) {
        $result = analyseBienIntakeIA($text, $forceType);
        if (!$result || empty($result['ok']) || (int)count(($result['fields'] ?? [])) < 3) {
            $isProbablyScanned = true;
        }
    }

    if ($isProbablyScanned) {
        try {
            $tmpDir = __DIR__ . '/../uploads/_ocr_tmp/bulk_' . time() . '_' . bin2hex(random_bytes(3));
            $images = BienIntakeOCR::pdfToImages($pdfPath, $tmpDir, 8);
            if (empty($images)) {
                throw new RuntimeException('Aucune image générée (pdftoppm/Imagick indisponible ?)');
            }
            $result = BienIntakeOCR::analyseImagesIA($images, $forceType);
            BienIntakeOCR::cleanupTmpDir($tmpDir);
            $usedOcr = true;
        } catch (Throwable $e) {
            $result = $result ?: ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'OCR échoué : ' . $e->getMessage()];
        }
    }

    $docType = (string)($result['doc_type'] ?? 'autre');
    $fields = (array)($result['fields'] ?? []);
    apply_dpe_vierge_rules($docType, $fields);
    $result['fields'] = $fields;

    $payload = [
        '_file_size' => $size,
        '_file_mtime' => $mtime,
        '_text_length' => $textLen,
        '_used_ocr' => $usedOcr,
        '_analysed_at' => date('c'),
        'ok' => (bool)($result['ok'] ?? false),
        'doc_type' => $docType,
        'doc_titre' => $result['doc_titre'] ?? null,
        'resume' => $result['resume'] ?? null,
        'error' => $result['error'] ?? null,
        'fields' => $fields,
    ];

    ensure_dir(dirname($cachePath));
    file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $payload;
}

function pick_merged_fields(array $docs): array
{
    $merged = [];
    // Priorité : doc avec plus de champs, mais on merge tout en "first non-empty wins"
    usort($docs, static fn($a, $b) => (count($b['fields'] ?? []) <=> count($a['fields'] ?? [])));
    foreach ($docs as $d) {
        $fields = (array)($d['fields'] ?? []);
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '') continue;
            if (!array_key_exists($k, $merged) || $merged[$k] === null || $merged[$k] === '') {
                $merged[$k] = $v;
            }
        }
    }
    return $merged;
}

function best_owner_fields(array $docs): array
{
    $counts = [];
    $owners = [];
    foreach ($docs as $d) {
        $f = (array)($d['fields'] ?? []);
        $soc = trim((string)($f['proprio_societe'] ?? ''));
        $nom = trim((string)($f['proprio_nom'] ?? ''));
        $pre = trim((string)($f['proprio_prenom'] ?? ''));
        $label = $soc !== '' ? $soc : trim($pre . ' ' . $nom);
        if ($label === '') continue;
        $k = norm_key($label);
        if ($k === '') continue;
        $counts[$k] = ($counts[$k] ?? 0) + 1;
        $owners[$k] = [
            'type_personne' => ($soc !== '' ? 'morale' : 'physique'),
            'civilite' => null,
            'nom' => ($soc !== '' ? ($nom ?: $soc) : ($nom ?: '')),
            'prenom' => ($soc !== '' ? null : ($pre ?: null)),
            'societe' => ($soc !== '' ? $soc : null),
            'email' => $f['proprio_email'] ?? null,
            'telephone' => $f['proprio_telephone'] ?? null,
            'adresse_1' => $f['proprio_adresse_1'] ?? null,
            'code_postal' => $f['proprio_code_postal'] ?? null,
            'ville' => $f['proprio_ville'] ?? null,
            '_label' => $label,
        ];
    }
    if (empty($counts)) return [];
    arsort($counts);
    $bestK = array_key_first($counts);
    return $owners[$bestK] ?? [];
}

function find_or_create_immeuble(PDO $pdo, int $societeId, int $agenceId, array $fields, bool $dryRun): int
{
    $adr = trim((string)($fields['adresse_1'] ?? ''));
    $cp = trim((string)($fields['code_postal'] ?? ''));
    $ville = trim((string)($fields['ville'] ?? ''));
    if ($adr === '' || $cp === '' || $ville === '') return 0;

    $stmt = $pdo->prepare("
        SELECT id FROM immeubles
        WHERE LOWER(TRIM(adresse_1)) = LOWER(TRIM(?))
          AND code_postal = ?
          AND LOWER(TRIM(ville)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$adr, $cp, $ville]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;
    if ($dryRun) return 0;

    $ins = $pdo->prepare("
        INSERT INTO immeubles
            (id_societe, id_agence, adresse_1, adresse_2, code_postal, ville, pays)
        VALUES
            (?,?,?,?,?,?,?)
    ");
    $ins->execute([
        $societeId ?: null,
        $agenceId ?: null,
        $adr,
        $fields['adresse_2'] ?? null,
        $cp,
        $ville,
        $fields['pays'] ?? 'France',
    ]);
    return (int)$pdo->lastInsertId();
}

function find_or_create_proprietaire(PDO $pdo, int $agenceId, array $ownerFields, bool $dryRun): int
{
    if (empty($ownerFields)) return 0;

    $needle = [
        'nom' => $ownerFields['nom'] ?? null,
        'prenom' => $ownerFields['prenom'] ?? null,
        'societe' => $ownerFields['societe'] ?? null,
        'email' => $ownerFields['email'] ?? null,
        'telephone' => $ownerFields['telephone'] ?? null,
    ];
    $cands = BienIntakeSearch::searchProprietaires($pdo, $needle, $agenceId, 1);
    if (!empty($cands[0]['id']) && (int)($cands[0]['score'] ?? 0) >= 80) {
        return (int)$cands[0]['id'];
    }
    if ($dryRun) return 0;

    $ins = $pdo->prepare("
        INSERT INTO proprietaires
            (id_agence, type_personne, civilite, nom, prenom, societe,
             email, telephone, adresse_1, code_postal, ville, actif)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1)
    ");
    $ins->execute([
        $agenceId ?: null,
        $ownerFields['type_personne'] ?? 'physique',
        $ownerFields['civilite'] ?? null,
        $ownerFields['nom'] ?? ($ownerFields['societe'] ?? 'Inconnu'),
        $ownerFields['prenom'] ?? null,
        $ownerFields['societe'] ?? null,
        $ownerFields['email'] ?? null,
        $ownerFields['telephone'] ?? null,
        $ownerFields['adresse_1'] ?? null,
        $ownerFields['code_postal'] ?? null,
        $ownerFields['ville'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function sync_bien_no_overwrite(PDO $pdo, int $bienId, array $fields): void
{
    $map = [
        'adresse_1'   => $fields['adresse_1']   ?? null,
        'adresse_2'   => $fields['adresse_2']   ?? null,
        'code_postal' => $fields['code_postal'] ?? null,
        'ville'       => $fields['ville']       ?? null,
        'dpe_classe' => $fields['dpe_classe'] ?? null,
        'ges_classe' => $fields['ges_classe'] ?? null,
        'dpe_valeur' => $fields['dpe_valeur'] ?? null,
        'ges_valeur' => $fields['ges_valeur'] ?? null,
        'dpe_valeur_conso_primaire' => $fields['dpe_valeur_conso_primaire'] ?? null,
        'dpe_valeur_conso_finale' => $fields['dpe_valeur_conso_finale'] ?? null,
        'date_indice_prix_energies' => $fields['date_indice_prix_energies'] ?? null,
        'altitude' => $fields['altitude'] ?? null,
        'dpe_date_realisation' => $fields['dpe_date_realisation'] ?? null,
        'dpe_version' => $fields['dpe_version'] ?? null,
        'dpe_vierge' => !empty($fields['dpe_vierge']) ? 1 : null,
        'dpe_reference_certificat' => $fields['dpe_reference_certificat'] ?? null,
        'montant_estime_depenses_min' => $fields['montant_estime_depenses_min'] ?? null,
        'montant_estime_depenses_max' => $fields['montant_estime_depenses_max'] ?? null,
        'surface_habitable' => $fields['surface_habitable'] ?? null,
        'surface_sejour' => $fields['surface_sejour'] ?? null,
        'surface_carrez' => $fields['surface_carrez'] ?? null,
        'nb_pieces' => $fields['nb_pieces'] ?? null,
        'nb_chambres' => $fields['nb_chambres'] ?? null,
        'nb_salles_bain' => $fields['nb_salles_bain'] ?? null,
        'nb_salles_eau' => $fields['nb_salles_eau'] ?? null,
        'nb_wc' => $fields['nb_wc'] ?? null,
        'etage' => $fields['etage'] ?? null,
        'annee_construction' => $fields['annee_construction'] ?? null,
        'chauffage_type' => $fields['chauffage_type'] ?? null,
        'chauffage_energie' => $fields['chauffage_energie'] ?? null,
        'eau_chaude_type' => $fields['eau_chaude_type'] ?? null,
        'double_vitrage' => !empty($fields['double_vitrage']) ? 1 : null,
        'volets_roulants' => !empty($fields['volets_roulants']) ? 1 : null,
        'menuiseries' => $fields['menuiseries'] ?? null,
        'zone_georisque' => !empty($fields['zone_georisque']) ? 1 : null,
        'designation' => $fields['designation'] ?? null,
        'reference_bien' => $fields['reference_bien'] ?? null,
    ];

    $boolCols = ['dpe_vierge','double_vitrage','volets_roulants','zone_georisque'];
    $setParts = [];
    $params = [':_id' => $bienId];
    foreach ($map as $col => $val) {
        if ($val === null || $val === '') continue;
        if (in_array($col, $boolCols, true)) {
            $setParts[] = "`$col` = CASE WHEN `$col` = 0 THEN :v_$col ELSE `$col` END";
        } else {
            $setParts[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
        }
        $params[":v_$col"] = $val;
    }

    if (!empty($fields['type_bien'])) {
        try {
            $tStmt = $pdo->prepare("SELECT id FROM types_bien WHERE code = ? LIMIT 1");
            $tStmt->execute([(string)$fields['type_bien']]);
            $tId = (int)$tStmt->fetchColumn();
            if ($tId > 0) {
                $setParts[] = "`id_type_bien` = COALESCE(NULLIF(`id_type_bien`, 1), :v_idtb)";
                $params[':v_idtb'] = $tId;
            }
        } catch (Throwable) {}
    }

    if (empty($setParts)) return;
    $pdo->prepare("UPDATE biens SET " . implode(', ', $setParts) . " WHERE id = :_id")->execute($params);
}

function insert_diag_and_doc(PDO $pdo, int $bienId, int $userId, string $docType, ?string $docTitre, array $fields, string $publicUrl, string $origName, int $size, string $mime): array
{
    $adresseDoc = [
        'adresse_1' => $fields['adresse_1'] ?? null,
        'code_postal' => $fields['code_postal'] ?? null,
        'ville' => $fields['ville'] ?? null,
    ];

    $score = min(100, count($fields) * 4);
    $pdo = db_reconnect_fresh();

    $diagId = 0;
    try {
        $stmtDiag = $pdo->prepare("
            INSERT INTO dpe_diags (
                id_bien, type_diag, est_diag_principal,
                date_diagnostic, dpe_classe, ges_classe, dpe_vierge,
                consommation_energie, emission_ges,
                conso_energie_primaire, conso_energie_finale,
                montant_depenses_min, montant_depenses_max,
                date_indice_prix, dpe_version, numero_ademe, numero_rapport,
                diagnostiqueur_nom, diagnostiqueur_societe,
                fichier_url, nom_fichier_original, taille_fichier_octets, mime_type,
                type_bien_detecte, adresse_detectee, code_postal_detecte, ville_detectee,
                etage_detecte, lot_detecte, annee_construction_detectee,
                surface_habitable_detectee, surface_carrez_detectee, surface_sejour_detectee,
                nb_pieces_detecte, nb_chambres_detecte, nb_salles_bain_detecte,
                nb_salles_eau_detecte, nb_wc_detecte,
                chauffage_type_detecte, chauffage_energie_detecte, eau_chaude_type_detecte,
                double_vitrage_detecte, volets_roulants_detecte, menuiseries_detectees,
                altitude_detectee,
                alerte_plomb_present, alerte_amiante_present, alerte_electricite_anomalies,
                alerte_gaz_anomalies, alerte_termites, alerte_zone_georisque,
                extraction_method, extraction_score, extraction_date,
                champs_extraits_json, resume_bailleur, commentaire,
                date_creation, date_modification
            ) VALUES (
                :id_bien, :type_diag, 0,
                :date, :dpec, :gesc, :vierge,
                :conso, :ges,
                :primaire, :finale,
                :depmin, :depmax,
                :indice, :ver, :ademe, :num_rapport,
                :diag_nom, :diag_soc,
                :url, :nom, :taille, :mime,
                :tb, :adr, :cp, :ville,
                :etage, :lot, :annee,
                :sh, :sc, :ss,
                :np, :nc, :nsb,
                :nse, :nwc,
                :ct, :ce, :ec,
                :dv, :vr, :menui,
                :alt,
                :a_plomb, :a_amiante, :a_elec,
                :a_gaz, :a_termites, :a_geo,
                'ia', :score, NOW(),
                :json, :resume, :titre,
                NOW(), NOW()
            )
        ");
        $stmtDiag->execute([
            ':id_bien' => $bienId,
            ':type_diag' => $docType,
            ':date' => $fields['dpe_date_realisation'] ?? ($fields['date_signature'] ?? null),
            ':dpec' => $fields['dpe_classe'] ?? null,
            ':gesc' => $fields['ges_classe'] ?? null,
            ':vierge' => !empty($fields['dpe_vierge']) ? 1 : 0,
            ':conso' => $fields['dpe_valeur'] ?? null,
            ':ges' => $fields['ges_valeur'] ?? null,
            ':primaire' => $fields['dpe_valeur_conso_primaire'] ?? null,
            ':finale' => $fields['dpe_valeur_conso_finale'] ?? null,
            ':depmin' => $fields['montant_estime_depenses_min'] ?? null,
            ':depmax' => $fields['montant_estime_depenses_max'] ?? null,
            ':indice' => $fields['date_indice_prix_energies'] ?? null,
            ':ver' => $fields['dpe_version'] ?? null,
            ':ademe' => $fields['dpe_reference_certificat'] ?? null,
            ':num_rapport' => $fields['diag_dossier_numero'] ?? $fields['numero_rapport'] ?? null,
            ':diag_nom' => $fields['diag_operateur_nom'] ?? $fields['operateur_nom'] ?? null,
            ':diag_soc' => $fields['diag_operateur_societe'] ?? $fields['operateur_societe'] ?? null,
            ':url' => $publicUrl,
            ':nom' => $origName,
            ':taille' => $size,
            ':mime' => $mime ?: 'application/pdf',
            ':tb' => $fields['type_bien'] ?? null,
            ':adr' => $adresseDoc['adresse_1'] ?? null,
            ':cp' => $adresseDoc['code_postal'] ?? null,
            ':ville' => $adresseDoc['ville'] ?? null,
            ':etage' => $fields['etage'] ?? null,
            ':lot' => $fields['lot_principal'] ?? null,
            ':annee' => $fields['annee_construction'] ?? null,
            ':sh' => $fields['surface_habitable'] ?? null,
            ':sc' => $fields['surface_carrez'] ?? null,
            ':ss' => $fields['surface_sejour'] ?? null,
            ':np' => $fields['nb_pieces'] ?? null,
            ':nc' => $fields['nb_chambres'] ?? null,
            ':nsb' => $fields['nb_salles_bain'] ?? null,
            ':nse' => $fields['nb_salles_eau'] ?? null,
            ':nwc' => $fields['nb_wc'] ?? null,
            ':ct' => $fields['chauffage_type'] ?? null,
            ':ce' => $fields['chauffage_energie'] ?? null,
            ':ec' => $fields['eau_chaude_type'] ?? null,
            ':dv' => !empty($fields['double_vitrage']) ? 1 : 0,
            ':vr' => !empty($fields['volets_roulants']) ? 1 : 0,
            ':menui' => $fields['menuiseries'] ?? null,
            ':alt' => $fields['altitude'] ?? null,
            ':a_plomb' => !empty($fields['plomb_present']) ? 1 : 0,
            ':a_amiante' => !empty($fields['amiante_present']) ? 1 : 0,
            ':a_elec' => !empty($fields['electricite_anomalies']) ? 1 : 0,
            ':a_gaz' => !empty($fields['gaz_anomalies']) ? 1 : 0,
            ':a_termites' => !empty($fields['termites']) ? 1 : 0,
            ':a_geo' => !empty($fields['zone_georisque']) ? 1 : 0,
            ':score' => $score,
            ':json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ':resume' => $fields['diag_resume_ia'] ?? null,
            ':titre' => $docTitre,
        ]);
        $diagId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // Non bloquant : on veut au moins garder le PDF dans biens_documents.
        stderr("[WARN] dpe_diags insert failed for {$origName}: " . $e->getMessage() . "\n");
    }

    // Archivage docs
    try {
        $docTypeMap = [
            'diag' => 'dpe',
            'dossier_complet' => 'dpe',
            'dossier_diagnostics' => 'dpe',
            'dpe' => 'dpe',
            'etat_risques' => 'diag',
        ];
        $bdType = $docTypeMap[$docType] ?? 'diag';
        $pdo = db_reconnect_fresh();
        $pdo->prepare("
            INSERT INTO biens_documents
                (id_bien, type_document, libelle, url_fichier, nom_original,
                 mime_type, taille_octets, date_document, id_user_upload,
                 visible_proprietaire, date_upload)
            VALUES
                (:id_bien, :type_document, :libelle, :url, :nom_orig, :mime,
                 :taille, :date_doc, :uid, 1, NOW())
        ")->execute([
            ':id_bien' => $bienId,
            ':type_document' => $bdType,
            ':libelle' => ($docTitre ?? '') !== '' ? $docTitre : ucfirst($bdType),
            ':url' => $publicUrl,
            ':nom_orig' => $origName,
            ':mime' => $mime ?: 'application/pdf',
            ':taille' => $size,
            ':date_doc' => $fields['dpe_date_realisation'] ?? ($fields['date_signature'] ?? null),
            ':uid' => $userId ?: null,
        ]);
    } catch (Throwable $e) {
        stderr("[WARN] biens_documents insert failed for {$origName}: " . $e->getMessage() . "\n");
    }

    return ['diag_id' => $diagId, 'score' => $score];
}

function write_csv(string $path, array $rows): void
{
    ensure_dir(dirname($path));
    $f = fopen($path, 'wb');
    if (!$f) throw new RuntimeException("Impossible d'écrire le CSV: {$path}");
    if (empty($rows)) { fclose($f); return; }
    $headers = array_keys($rows[0]);
    fputcsv($f, $headers, ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $h) $line[] = $r[$h] ?? '';
        fputcsv($f, $line, ';');
    }
    fclose($f);
}

// ──────────────────────────────────────────────────────────────
// Main
// ──────────────────────────────────────────────────────────────

$args = parse_args($argv);
load_openai_config_if_any();

$pdo = db();

if ($args['list_agences']) {
    $rows = $pdo->query("SELECT id, nom_agence, id_societe FROM agences WHERE actif=1 ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo (int)$r['id'] . "\t" . (string)$r['nom_agence'] . "\t(societe " . (int)($r['id_societe'] ?? 0) . ")\n";
    }
    exit(0);
}

if ($args['list_users']) {
    $ag = (int)$args['agence'];
    if ($ag <= 0) { stderr("Erreur: --agence requis avec --list-users\n"); exit(2); }
    $st = $pdo->prepare("SELECT id, prenom, nom, email FROM users WHERE actif=1 AND id_agence = ? ORDER BY nom, prenom");
    $st->execute([$ag]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        echo (int)$u['id'] . "\t" . trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) . "\t" . (string)($u['email'] ?? '') . "\n";
    }
    exit(0);
}

$dir = (string)$args['dir'];
if ($dir === '' || !is_dir($dir)) {
    stderr("Usage: php scripts/diag_bulk_import.php --dir <dossier> --societe <id> --agence <id> [--user <id>] [--apply]\n");
    stderr("Astuce: php scripts/diag_bulk_import.php --list-agences\n");
    exit(2);
}

$societeId = (int)$args['societe'];
$agenceId  = (int)$args['agence'];
$userId    = (int)$args['user'];

if ($societeId <= 0 || $agenceId <= 0) {
    stderr("Erreur: --societe et --agence sont requis (ids numériques)\n");
    exit(2);
}

$apiKey = (string)($GLOBALS['OPENAI_API_KEY'] ?? '');
if ($apiKey === '') {
    stderr("Erreur: OPENAI_API_KEY non configurée (env var ou openai_config.php)\n");
    exit(2);
}

$forceType = (string)$args['force_type'];
$validForce = ['bail','mandat','titre','diag','fiche','divers','auto'];
if (!in_array($forceType, $validForce, true)) $forceType = 'diag';

$cacheRoot = __DIR__ . '/../uploads/_import_cache/diag_bulk';
$reportRoot = __DIR__ . '/../uploads/_import_reports';
$runId = date('Ymd_His');
$csvPath = $reportRoot . "/diag_bulk_report_{$runId}.csv";

echo "Mode: " . ($args['apply'] ? "APPLY (écritures BDD + copie PDFs)\n" : "DRY-RUN (pas d'écriture)\n");
echo "Dir: {$dir}\n";
echo "Societe: {$societeId} / Agence: {$agenceId}" . ($userId ? " / User: {$userId}" : "") . "\n";
echo "Force type: {$forceType}\n";
echo "Cache: {$cacheRoot}\n";

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fi) {
    /** @var SplFileInfo $fi */
    if (!$fi->isFile()) continue;
    if (strtolower($fi->getExtension()) !== 'pdf') continue;
    $files[] = $fi->getPathname();
}
sort($files);
if ($args['limit'] > 0) $files = array_slice($files, 0, (int)$args['limit']);

echo "PDFs détectés: " . count($files) . "\n";
if (count($files) === 0) exit(0);

$rows = [];
$docs = []; // analyses par fichier

$n = 0;
foreach ($files as $path) {
    $n++;
    $sha1 = hash_file('sha1', $path) ?: '';
    if ($sha1 === '') continue;
    $cachePath = $cacheRoot . '/' . substr($sha1, 0, 2) . '/' . $sha1 . '.json';
    $a = analyse_pdf_with_cache($path, $cachePath, $forceType, (bool)$args['refresh_cache']);

    $fields = (array)($a['fields'] ?? []);
    $addrKey = build_address_key($fields);
    $lotKey = build_lot_key($fields);
    $docType = (string)($a['doc_type'] ?? 'autre');
    $docDate = (string)($fields['dpe_date_realisation'] ?? '');

    $docs[] = [
        'path' => $path,
        'sha1' => $sha1,
        'ok' => (int)!empty($a['ok']),
        'error' => (string)($a['error'] ?? ''),
        'doc_type' => $docType,
        'doc_titre' => (string)($a['doc_titre'] ?? ''),
        'used_ocr' => (int)!empty($a['_used_ocr']),
        'text_length' => (int)($a['_text_length'] ?? 0),
        'fields' => $fields,
        'addr_key' => $addrKey,
        'lot_key' => $lotKey,
        'doc_date' => $docDate,
        'imported' => (array)($a['import'] ?? []),
    ];

    echo "[{$n}/" . count($files) . "] " . basename($path) . " — " . ($a['ok'] ? "OK" : "ERR") . " — {$docType}\n";
}

// Groupement
$byAddr = [];
foreach ($docs as $d) {
    $k = $d['addr_key'];
    if ($k === '||') $k = ''; // pas d'adresse
    $byAddr[$k][] = $d;
}

$uploadDir = __DIR__ . '/../uploads/biens_docs';
if ($args['apply']) ensure_dir($uploadDir);

$groupIndex = 0;
foreach ($byAddr as $addrKey => $addrDocs) {
    if ($addrKey === '') {
        // Orphans sans adresse
        foreach ($addrDocs as $d) {
            $f = $d['fields'];
            $rows[] = [
                'group' => 'ORPHAN',
                'bien_id' => '',
                'immeuble_id' => '',
                'proprietaire_id' => '',
                'adresse_1' => (string)($f['adresse_1'] ?? ''),
                'code_postal' => (string)($f['code_postal'] ?? ''),
                'ville' => (string)($f['ville'] ?? ''),
                'lot_key' => $d['lot_key'],
                'doc_type' => $d['doc_type'],
                'doc_date' => $d['doc_date'],
                'file' => $d['path'],
                'ok' => (string)$d['ok'],
                'used_ocr' => (string)$d['used_ocr'],
                'error' => $d['error'],
                'status' => 'orphan_no_address',
                'obsolete' => '',
            ];
        }
        continue;
    }

    // Sous-groupes (lots/situation)
    $sub = [];
    foreach ($addrDocs as $d) $sub[$d['lot_key']][] = $d;

    foreach ($sub as $lotKey => $lotDocs) {
        $groupIndex++;
        $groupId = 'G' . str_pad((string)$groupIndex, 4, '0', STR_PAD_LEFT);

        $merged = pick_merged_fields($lotDocs);
        $ambiguous = ($lotKey === 'unknown' && count($lotDocs) > 1);

        $immeubleId = 0;
        $proprioId = 0;
        $bienId = 0;

        // Skip si déjà importé (tous les docs ont import.*)
        $allImported = true;
        foreach ($lotDocs as $d) {
            $imp = $d['imported'] ?? [];
            if (empty($imp['diag_id']) && empty($imp['bien_id'])) { $allImported = false; break; }
        }

        if ($args['apply'] && !$allImported && !$ambiguous) {
            try {
                // Crée / réutilise immeuble et propriétaire
                $immeubleId = find_or_create_immeuble($pdo, $societeId, $agenceId, $merged, false);
                $ownerFields = best_owner_fields($lotDocs);
                $proprioId = find_or_create_proprietaire($pdo, $agenceId, $ownerFields, false);

                $bienId = bien_form_create_draft($pdo, $societeId, $agenceId, null, $userId ?: null);

                // Link immeuble/proprio
                $pdo->prepare("UPDATE biens SET id_immeuble = ?, id_proprietaire = ? WHERE id = ?")
                    ->execute([$immeubleId ?: null, $proprioId ?: null, $bienId]);

                // Sync champs extraits
                sync_bien_no_overwrite($pdo, $bienId, $merged);
            } catch (Throwable $e) {
                stderr("[ERR] {$groupId} create/link failed: " . $e->getMessage() . "\n");
            }
        }

        // Détermination obsolète (dans le groupe seulement) : garde le plus récent par doc_type
        $latestByType = [];
        foreach ($lotDocs as $d) {
            $t = $d['doc_type'] ?: 'autre';
            $dt = $d['doc_date'];
            if ($dt === '') continue;
            if (!isset($latestByType[$t]) || $dt > ($latestByType[$t]['doc_date'] ?? '')) {
                $latestByType[$t] = $d;
            }
        }

        foreach ($lotDocs as $d) {
            $f = $d['fields'];
            $status = $ambiguous ? 'ambiguous_needs_split' : ($allImported ? 'already_imported' : ($args['apply'] ? 'imported_or_attempted' : 'dry_run'));

            $obsolete = '';
            $t = $d['doc_type'] ?: 'autre';
            if (isset($latestByType[$t]) && $latestByType[$t]['sha1'] !== $d['sha1'] && $d['doc_date'] !== '') {
                $obsolete = 'superseded_by_newer_same_type';
            }

            $diagId = '';
            $publicUrl = '';

            if ($args['apply'] && !$allImported && !$ambiguous && $bienId > 0) {
                $cachePath = $cacheRoot . '/' . substr($d['sha1'], 0, 2) . '/' . $d['sha1'] . '.json';
                $cacheData = json_decode((string)file_get_contents($cachePath), true) ?: [];
                if (!empty($cacheData['import']['diag_id']) && !empty($cacheData['import']['bien_id'])) {
                    // déjà importé via cache
                    $diagId = (string)$cacheData['import']['diag_id'];
                    $publicUrl = (string)($cacheData['import']['url'] ?? '');
                } else {
                    // copie + insert
                    $safe = 'bulkdiag_' . $bienId . '_' . substr($d['sha1'], 0, 12) . '.pdf';
                    $dest = $uploadDir . '/' . $safe;
                    $rel  = '/uploads/biens_docs/' . $safe;
                    if (!is_file($dest)) {
                        if (!@copy($d['path'], $dest)) {
                            $err = error_get_last()['message'] ?? 'copy_failed';
                            $rows[] = [
                                'group' => $groupId,
                                'bien_id' => (string)$bienId,
                                'immeuble_id' => (string)$immeubleId,
                                'proprietaire_id' => (string)$proprioId,
                                'adresse_1' => (string)($f['adresse_1'] ?? ''),
                                'code_postal' => (string)($f['code_postal'] ?? ''),
                                'ville' => (string)($f['ville'] ?? ''),
                                'lot_key' => $d['lot_key'],
                                'doc_type' => $d['doc_type'],
                                'doc_date' => $d['doc_date'],
                                'file' => $d['path'],
                                'ok' => (string)$d['ok'],
                                'used_ocr' => (string)$d['used_ocr'],
                                'error' => 'copy_failed: ' . $err,
                                'status' => 'error_copy',
                                'obsolete' => $obsolete,
                            ];
                            continue;
                        }
                    }
                    $mime = (string)(@mime_content_type($dest) ?: 'application/pdf');
                    $sz = (int)@filesize($dest);
                    $docTitre = $d['doc_titre'] !== '' ? $d['doc_titre'] : null;
                    $ins = insert_diag_and_doc($pdo, $bienId, $userId, $d['doc_type'], $docTitre, $f, $rel, basename($d['path']), $sz, $mime);
                    $diagId = (string)($ins['diag_id'] ?? '');
                    $publicUrl = $rel;

                    // écrit retour dans cache (idempotence)
                    $cacheData['import'] = [
                        'bien_id' => $bienId,
                        'diag_id' => $diagId !== '' ? (int)$diagId : 0,
                        'url' => $rel,
                        'imported_at' => date('c'),
                    ];
                    file_put_contents($cachePath, json_encode($cacheData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }

            $rows[] = [
                'group' => $groupId,
                'bien_id' => (string)$bienId,
                'immeuble_id' => (string)$immeubleId,
                'proprietaire_id' => (string)$proprioId,
                'adresse_1' => (string)($f['adresse_1'] ?? ''),
                'code_postal' => (string)($f['code_postal'] ?? ''),
                'ville' => (string)($f['ville'] ?? ''),
                'lot_key' => $d['lot_key'],
                'doc_type' => $d['doc_type'],
                'doc_date' => $d['doc_date'],
                'diag_id' => $diagId,
                'url' => $publicUrl,
                'file' => $d['path'],
                'ok' => (string)$d['ok'],
                'used_ocr' => (string)$d['used_ocr'],
                'error' => $d['error'],
                'status' => $status,
                'obsolete' => $obsolete,
            ];
        }
    }
}

write_csv($csvPath, $rows);
echo "Rapport: {$csvPath}\n";

