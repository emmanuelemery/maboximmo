<?php
/**
 * inc/onedrive_classer.php — Moteur de classement OneDrive → GED, scope PROPRIÉTAIRE.
 *
 * Parcourt le dossier OneDrive d'un propriétaire (service Gestion) et propose le
 * rattachement de chaque document (mandat / bail / EDL / DPE) au bien / bail / proprio
 * créés par le CRG. Deux temps : oc_scan_proprio() = propositions (dry-run, n'écrit rien),
 * puis oc_commit_proposal() = téléchargement + classement GED (gus_commit_document).
 *
 * Règles (validées) :
 *   1) dossier LOCATAIRES/<loc> ↔ bail courant (intersection de tokens) → bien/bail
 *   2) sinon proprio à 1 bien → ce bien (capte l'historique des locataires)
 *   3) sinon → pile (ambigu) ; mandat → proprio ; DPE → bien.
 *
 * Pré-requis : Microsoft Graph configuré (inc/microsoft_graph.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/microsoft_graph.php';
require_once __DIR__ . '/ged_document_links.php';
require_once __DIR__ . '/ged_doc_naming_v3.php';   // aperçu du nom canonique (glossaire V3.1)

if (!function_exists('oc_norm')) {
    function oc_norm($s): string {
        $s = mb_strtolower(trim((string)$s), 'UTF-8');
        $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ÿ'=>'y']);
        return preg_replace('/\s+/', ' ', $s);
    }
    function oc_tokens($s): array { return array_values(array_filter(explode(' ', oc_norm($s)), fn($t) => mb_strlen($t) >= 4)); }
    function oc_type(string $name): string {
        $s = oc_norm($name);
        // Mandat de GESTION uniquement — exclure les mandats de prélèvement SEPA / RIB.
        if (preg_match('/mandat/', $s) && !preg_match('/prelev|plvt|\bsepa\b/', $s)) return 'MANDAT';
        if (preg_match('/\bbail|baux/', $s))                  return 'BAIL';
        if (preg_match('/^edl|edle|edl-|etat des lieux/', $s))return 'EDL';
        if (preg_match('/\bdpe\b|ddt/', $s))                  return 'DPE';
        return '';
    }
    /** Base OneDrive « PROPRIETAIRES » selon l'agence du proprio (RIOM par défaut). */
    function oc_base_for_agence(string $codeAgence): string {
        // 63-* = EMERY IMMO (RIOM gestion) ; à étendre (LYON 02_…) plus tard.
        return '01_SERVICE_GESTION/05_RIOM GESTION/0001 - NOUVEAUX DOSSIERS ONEDRIVE/00 - PROPRIETAIRES';
    }
    // Listing PAGINÉ (la base PROPRIETAIRES a 200+ dossiers ; sans pagination, plafond 100
    // → les propriétaires après la position 100 étaient « introuvables »).
    function oc_children(string $path): array {
        try {
            if (function_exists('graph_list_all_children_by_path')) return graph_list_all_children_by_path($path);
            return graph_list_children_by_path($path);
        } catch (Throwable $e) { return []; }
    }
    /** EDL entrée vs sortie depuis le nom de fichier (mêmes règles que l'orchestrateur VA). */
    function oc_edl_sens(string $name): string {
        $s = oc_norm($name);
        if (preg_match('/\bedls\b|edl-s|edl s|\bsortie\b|\bdepart\b/', $s)) return 'sortie';
        return 'entree';
    }
    /** Dossier « regroupement » sans valeur d'identité (à ignorer dans le label locataire). */
    function oc_is_generic_folder(string $name): bool {
        $s = trim(oc_norm($name));
        return (bool)preg_match('/^(en cours|en-cours|sortis?|sortie|sorties|archiv\w*|termin\w*|actifs?|baux|locataires?|\d+\s*[-_]\s*\w*)$/', $s);
    }
    /** Label locataire = segments du chemin après LOCATAIRES (hors génériques) + nom de fichier. */
    function oc_loc_label(string $rel, string $name): string {
        $keep = []; $afterLoc = false;
        foreach (explode('/', $rel) as $p) {
            $n = oc_norm($p); if ($n === '') continue;
            if (strpos($n, 'locataire') !== false) { $afterLoc = true; continue; }
            if ($afterLoc && !oc_is_generic_folder($p)) $keep[] = $p;
        }
        $base = preg_replace('/\.(pdf|docx?|odt|jpe?g|png|tiff?)$/i', '', $name);
        return trim(implode(' ', $keep) . ' ' . $base);
    }
    /** Pièce personnelle/sensible d'un locataire — à NE PAS importer en GED (reste dans OneDrive). */
    function oc_is_personal(string $name): bool {
        $s = oc_norm($name);
        return (bool)preg_match(
            '/bulletin.*salaire|fiche.*paie|\bpaie\b|\bsalaire|'
          . 'carte.*identit|\bcni\b|\bidentit|passeport|titre.*sejour|'
          . 'avis.*impot|\bimpot|imposition|'
          . 'contrat.*travail|'
          . 'releve.*(bancaire|compte)|\brib\b|releve.*identite.*bancaire|prelev|\bplvt\b|justif.*domicile|\bcaf\b|allocation/',
            $s
        );
    }
    /** Type de diagnostic (glossaire) depuis le nom de fichier, sinon ''. */
    function oc_diag_type(string $name): string {
        $s = oc_norm($name);
        if (preg_match('/\bdpe\b|ddt|perf.*energ|energ.*perf/', $s)) return 'dpe';
        if (preg_match('/amiante/', $s))                            return 'diagnostic_amiante';
        if (preg_match('/plomb|crep/', $s))                         return 'diagnostic_plomb';
        if (preg_match('/\belec|electri|installation.*electr/', $s)) return 'diagnostic_elec';
        if (preg_match('/\bgaz\b/', $s))                            return 'diagnostic_gaz';
        if (preg_match('/termite|parasitaire|etat parasitaire/', $s)) return 'diagnostic_termites';
        if (preg_match('/\berp\b|ernmt|etat des risques|risques.*pollution/', $s)) return 'erp_ernmt';
        if (preg_match('/carrez|surface.*habitable|loi carrez|mesurage/', $s)) return 'surface_carrez';
        return '';
    }
    /** doc_type GED final (glossaire) d'un item ; EDL et DIAG ventilés par sous-type. */
    function oc_doc_type(string $type, string $name): string {
        if ($type === 'EDL')  return oc_edl_sens($name) === 'sortie' ? 'edl_sortie' : 'edl_entree';
        if ($type === 'DIAG') return oc_diag_type($name) ?: 'autre';
        return ['MANDAT'=>'mandat_gestion','BAIL'=>'bail_signe','DPE'=>'dpe','TF'=>'taxe_fonciere'][$type] ?? 'autre';
    }
    /** Taxe foncière / impôt foncier (document PRINCIPAL du bien). */
    function oc_is_tf(string $name): bool { return (bool)preg_match('/\btf\b|fonci/', oc_norm($name)); }
    /** n2/n3 slugs glossaire (gestion locative) pour un doc_type. */
    function oc_slugs(string $docType): array {
        return [
            'mandat_gestion'     =>['01_mandat_gestion','01_mandat_signe'],
            'bail_signe'         =>['02_bail','01_bail_signe'],
            'edl_entree'         =>['02_bail','02_edl_entree'],
            'edl_sortie'         =>['02_bail','03_edl_sortie'],
            'dpe'                =>['03_bien','01_dpe'],
            'erp_ernmt'          =>['03_bien','02_erp_ernmt'],
            'diagnostic_amiante' =>['03_bien','03_amiante'],
            'diagnostic_plomb'   =>['03_bien','04_plomb_crep'],
            'diagnostic_elec'    =>['03_bien','05_electricite'],
            'diagnostic_gaz'     =>['03_bien','06_gaz'],
            'diagnostic_termites'=>['03_bien','07_termites'],
            'surface_carrez'     =>['03_bien','08_surface_carrez'],
            'taxe_fonciere'      =>['03_bien','10_taxe_fonciere'],
        ][$docType] ?? ['00_onedrive','99_autre'];
    }
}

if (!function_exists('oc_find_proprio_folder')) {
    /** Trouve le dossier OneDrive du proprio (match nom) sous la base. */
    function oc_find_proprio_folder(PDO $pdo, int $proprioId, string $base): ?string {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(societe,''), TRIM(CONCAT_WS(' ', prenom, nom))) AS nom, nom AS nomseul FROM proprietaires WHERE id = ?");
        $st->execute([$proprioId]); $r = $st->fetch(PDO::FETCH_ASSOC); if (!$r) return null;
        $cands = array_filter([oc_norm($r['nom']), oc_norm($r['nomseul'])]);
        foreach (oc_children($base) as $it) {
            if (!isset($it['folder'])) continue;
            $fn = oc_norm($it['name']);
            foreach ($cands as $c) { if ($c !== '' && ($fn === $c || str_contains($fn, $c) || str_contains($c, $fn))) return (string)$it['name']; }
        }
        return null;
    }
}

if (!function_exists('oc_eligible_proprios')) {
    /** Propriétaires dans le périmètre OneDrive (RIOM 63-1/63-2) avec au moins un bien. */
    function oc_eligible_proprios(PDO $pdo): array {
        $sql = "SELECT p.id, COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS nom,
                       a.code_agence,
                       (SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire=p.id) AS nb_biens,
                       (SELECT COUNT(*) FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE b.id_proprietaire=p.id) AS nb_baux
                FROM proprietaires p
                JOIN agences a ON a.id=p.id_agence
                WHERE p.actif=1 AND a.code_agence IN ('63-1','63-2')
                HAVING nb_biens > 0
                ORDER BY nom";
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }
}

if (!function_exists('oc_proprio_folder_url')) {
    /** URL web OneDrive du dossier d'un propriétaire (pour l'ouvrir directement). */
    function oc_proprio_folder_url(PDO $pdo, int $proprioId): array {
        $info = $pdo->prepare("SELECT a.code_agence FROM proprietaires p LEFT JOIN agences a ON a.id=p.id_agence WHERE p.id=?");
        $info->execute([$proprioId]); $code = (string)($info->fetchColumn() ?: '');
        graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');
        $base   = oc_base_for_agence($code);
        $folder = oc_find_proprio_folder($pdo, $proprioId, $base);
        if (!$folder) return ['ok'=>false, 'error'=>'Dossier OneDrive introuvable pour ce propriétaire'];
        $path    = trim($base . '/' . $folder, '/');
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        try {
            $res  = graph_request('GET', graph_drive_prefix() . '/root:/' . $encoded);
            $data = graph_unwrap($res, 'Lecture dossier (item)');
            $url  = (string)($data['webUrl'] ?? '');
            if ($url === '') return ['ok'=>false, 'error'=>'URL OneDrive indisponible'];
            return ['ok'=>true, 'url'=>$url, 'folder'=>$folder];
        } catch (Throwable $e) { return ['ok'=>false, 'error'=>$e->getMessage()]; }
    }
}

if (!function_exists('oc_scan_proprio')) {
    /**
     * Retourne ['ok','proprio','folder','base','items'=>[...]] (dry-run, n'écrit rien).
     * Chaque item : {type, doc_type, name, item_id, web_url, target, bien_id, bail_id, locataire, status, reason}
     */
    function oc_scan_proprio(PDO $pdo, int $proprioId): array {
        $info = $pdo->prepare("SELECT p.id, COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) nom, p.id_tiers, a.code_agence
                               FROM proprietaires p LEFT JOIN agences a ON a.id=p.id_agence WHERE p.id=?");
        $info->execute([$proprioId]); $prop = $info->fetch(PDO::FETCH_ASSOC);
        if (!$prop) return ['ok'=>false, 'error'=>'Propriétaire introuvable'];
        graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');
        $base = oc_base_for_agence((string)($prop['code_agence'] ?? ''));
        $folder = oc_find_proprio_folder($pdo, $proprioId, $base);
        if (!$folder) return ['ok'=>false, 'error'=>'Dossier OneDrive introuvable pour ce propriétaire', 'base'=>$base, 'proprio'=>$prop];

        // Biens + baux courants du proprio
        $biens = $pdo->prepare("SELECT id, reference_bien, id_immeuble FROM biens WHERE id_proprietaire=?"); $biens->execute([$proprioId]);
        $biensArr = $biens->fetchAll(PDO::FETCH_ASSOC);
        // Immeuble unique du proprio (pour rattacher la TF à l'IMMEUBLE, pas au bien :
        // une même TF couvre tous les lots d'un immeuble).
        $immSet = [];
        foreach ($biensArr as $bb) { if (!empty($bb['id_immeuble'])) $immSet[(int)$bb['id_immeuble']] = 1; }
        $oneImm = count($immSet) === 1 ? (int)array_key_first($immSet) : 0;
        // Documents déjà IGNORÉS (ne plus reproposer ni rescanner)
        $ignored = [];
        try { foreach ($pdo->query("SELECT item_id FROM onedrive_classer_ignore") as $r) { $ignored[(string)$r['item_id']] = 1; } }
        catch (Throwable $e) { /* table absente → rien d'ignoré */ }
        // Baux ACTIFS uniquement (le bail courant = celui du CRG/quittancement). Les anciens
        // locataires (sortis) ne sont PAS importés → restent accessibles via le bouton OneDrive.
        $baux = $pdo->prepare("SELECT bb.id bail_id, bb.id_bien, bb.locataire_nom FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE b.id_proprietaire=? AND bb.statut='actif'");
        $baux->execute([$proprioId]); $bauxArr = $baux->fetchAll(PDO::FETCH_ASSOC);
        $oneBien = count($biensArr) === 1 ? (int)$biensArr[0]['id'] : 0;

        $matchBail = function(string $locFolder) use ($bauxArr): array {
            $lt = oc_tokens($locFolder); if (!$lt) return [];
            $hits = [];
            foreach ($bauxArr as $b) { if (array_intersect($lt, oc_tokens($b['locataire_nom']))) $hits[] = $b; }
            return $hits;
        };
        $items = [];
        $seenHash = [];   // dédup OneDrive : on ne charge pas 2× le même fichier (même contenu)
        $propNom = (string)($prop['nom'] ?? '');
        $add = function(string $type, array $f, string $path, string $target, int $bienId, int $bailId, ?string $loc, string $status, string $reason, int $immId = 0) use (&$items, &$seenHash, $propNom, $ignored) {
            $name = (string)($f['name'] ?? '');
            $iid  = (string)($f['id'] ?? '');
            if ($iid !== '' && isset($ignored[$iid])) return;   // doc ignoré → ni proposé ni rescanné
            // ── Dédup AVANT chargement : hash de fichier OneDrive (Graph), sinon nom+taille ──
            $hashes = $f['file']['hashes'] ?? [];
            $hkey = (string)($hashes['quickXorHash'] ?? $hashes['sha256Hash'] ?? $hashes['sha1Hash'] ?? '');
            if ($hkey === '') $hkey = oc_norm($name) . '|' . (string)($f['size'] ?? '');
            if (isset($seenHash[$hkey])) return;   // doublon OneDrive → ignoré
            $seenHash[$hkey] = 1;
            $dt   = oc_doc_type($type, $name);
            // Nom humain (name_display) lisible : « TYPE — locataire/proprio (réf) »
            $libType = ['mandat_gestion'=>'Mandat','bail_signe'=>'Bail','edl_entree'=>'EDL entrée','edl_sortie'=>'EDL sortie',
                'dpe'=>'DPE','erp_ernmt'=>'ERP/ERNMT','diagnostic_amiante'=>'Amiante','diagnostic_plomb'=>'Plomb/CREP',
                'diagnostic_elec'=>'Électricité','diagnostic_gaz'=>'Gaz','diagnostic_termites'=>'Termites','surface_carrez'=>'Surface Carrez',
                'taxe_fonciere'=>'Taxe foncière'][$dt] ?? $type;
            if ($dt === 'autre') {
                // Divers : on garde le nom d'origine ET le chemin OneDrive (organigramme conservé).
                $bn = preg_replace('/\.(pdf|docx?|odt|xlsx?|pptx?|txt|csv|rtf)$/i', '', $name);
                $display = ($path !== '' ? $path . ' / ' : '') . $bn;
            } else {
                $qui  = $target === 'PROPRIO' ? $propNom : (string)($loc ?? '');
                $ref  = $bailId > 0 ? ('bail '.$bailId) : ($bienId > 0 ? ('bien '.$bienId) : '');
                $year = preg_match('/\b(20\d{2})\b/', $name, $mY) ? ' '.$mY[1] : '';   // année depuis le nom OneDrive
                $display = trim($libType.$year.($qui!==''?' — '.$qui:'').($ref!==''?' ('.$ref.')':''));
            }
            // Aperçu du nom canonique (glossaire V3.1) ; date_doc inconnue → segment '-'
            [$n2,$n3] = oc_slugs($dt);
            $cible = ($target === 'PROPRIO') ? ['TIERS', 0] : ['BIEN', $bienId];
            $nameCanon = gdn_v3_build(['n1_slug'=>'05_gestion_locative','n2_slug'=>$n2,'n3_slug'=>$n3,
                'date_doc'=>null,'type_doc'=>$dt,'source_filename'=>$name,'user_id'=>0,
                'entity_type'=>$cible[0],'entity_id'=>$cible[1]]);
            $items[] = ['type'=>$type,'doc_type'=>$dt,'name'=>$name,'name_display'=>$display,'name_canon'=>$nameCanon,
                'item_id'=>(string)($f['id']??''),'web_url'=>(string)($f['webUrl']??''),'path'=>$path,'target'=>$target,
                'bien_id'=>$bienId,'imm_id'=>$immId,'bail_id'=>$bailId,'locataire'=>$loc,'status'=>$status,'reason'=>$reason];
        };

        // ── PARCOURS RÉCURSIF COMPLET du dossier proprio ──
        // Les structures OneDrive sont hétérogènes : LOCATAIRES/<loc>, LOCATAIRES/EN COURS/<loc>,
        // DIAGNOSTICS/, 3 - PROPRIETAIRE/DIAGNOSTICS/, PROPRIETAIRE/01_MANDAT/, etc.
        // On descend partout (sauf PHOTOS) et on classe chaque fichier par son nom + son chemin.
        $seenMandat = [];
        $walk = function(string $rel, int $depth) use (&$walk, $base, $folder, $add, $matchBail, $oneBien, &$seenMandat, $proprioId) {
            if ($depth > 5) return;
            $abs = "$base/$folder" . ($rel === '' ? '' : "/$rel");
            foreach (oc_children($abs) as $f) {
                $name = (string)($f['name'] ?? '');
                if (isset($f['folder'])) {
                    if (oc_norm($name) === 'photos') continue;           // inutile de descendre dans les photos
                    $walk($rel === '' ? $name : "$rel/$name", $depth + 1);
                    continue;
                }
                $relU       = oc_norm($rel);
                $isLocPath  = strpos($relU, 'locataire') !== false;
                $isDiagPath = strpos($relU, 'diagnostic') !== false;

                // 1) MANDAT (par nom) → propriétaire, dédupliqué
                if (oc_type($name) === 'MANDAT') {
                    $key = oc_norm(preg_replace('/\.(pdf|docx?|odt)$/i', '', $name));
                    if (isset($seenMandat[$key])) continue; $seenMandat[$key] = 1;
                    $add('MANDAT', $f, $rel, 'PROPRIO', 0, 0, null, 'certain', 'mandat → propriétaire #'.$proprioId);
                    continue;
                }
                // 2) DIAGNOSTIC (dossier DIAGNOSTICS ou nom reconnu, hors branche locataire) → bien
                $dt = oc_diag_type($name);
                if ($dt !== '' && ($isDiagPath || !$isLocPath)) {
                    if ($oneBien > 0) $add('DIAG', $f, $rel, 'BIEN', $oneBien, 0, null, 'certain', $dt.' → bien unique');
                    else              $add('DIAG', $f, $rel, 'PILE', 0, 0, null, 'pile', $dt.' + multi-biens');
                    continue;
                }
                // 2b) TAXE FONCIÈRE → rattachée à l'IMMEUBLE (une TF couvre tous les lots).
                if (oc_is_tf($name)) {
                    if ($oneImm > 0)       $add('TF', $f, $rel, 'IMB', 0, 0, null, 'certain', 'taxe foncière → immeuble unique', $oneImm);
                    elseif ($oneBien > 0)  $add('TF', $f, $rel, 'BIEN', $oneBien, 0, null, 'certain', 'taxe foncière → bien unique (sans immeuble)');
                    else                   $add('TF', $f, $rel, 'PILE', 0, 0, null, 'pile', 'taxe foncière + multi-immeubles');
                    continue;
                }
                // 3) BAIL / EDL d'ENTRÉE → uniquement le locataire ACTIF (match sur bail actif).
                //    EDL de SORTIE = locataire parti → ignoré. Bail/EDL d'un ancien locataire = pas
                //    de bail actif correspondant → ignoré (reste accessible via le bouton OneDrive).
                $t = oc_type($name);
                if ($t === 'EDL' && oc_edl_sens($name) === 'sortie') {
                    continue; // état des lieux de sortie → locataire sorti, on ne prend pas
                }
                if ($t === 'BAIL' || $t === 'EDL') {
                    $loc = oc_loc_label($rel, $name);
                    $h = $matchBail($loc);   // $bauxArr = baux ACTIFS seulement
                    if (count($h) === 1)      $add($t, $f, $rel, 'BAIL', (int)$h[0]['id_bien'], (int)$h[0]['bail_id'], $h[0]['locataire_nom'], 'certain', 'bail actif ↔ locataire');
                    elseif (count($h) > 1)    $add($t, $f, $rel, 'PILE', 0, 0, $loc, 'pile', count($h).' baux actifs correspondent');
                    // sinon : aucun bail actif → ancien locataire, on n'importe pas
                    continue;
                }
                // Tout le reste (divers, pièces perso, factures…) → IGNORÉ : on ne prend QUE les
                // documents de base (mandat, DPE/diagnostics, TF, bail, EDL). Le reste reste dans OneDrive.
            }
        };
        $walk('', 0);

        return ['ok'=>true, 'proprio'=>$prop, 'folder'=>$folder, 'base'=>$base, 'items'=>$items,
                'nb_biens'=>count($biensArr), 'nb_baux'=>count($bauxArr)];
    }
}

if (!function_exists('oc_scan_bien')) {
    /**
     * Scan scopé à UN bien : reprend le scan du propriétaire puis retient ce qui
     * concerne ce bien — pour les NOUVEAUX docs et les LOUPÉS (pile du scan proprio).
     * Sur la fiche bien, l'ambiguïté se lève : un item « pile » dont le locataire
     * matche un bail de CE bien devient certain (bail) ; sinon il est rattaché au
     * bien lui-même (historique). Le mandat (scope proprio) est exclu.
     * Retour : même forme que oc_scan_proprio (ok, proprio, items, …) + 'bien'.
     */
    function oc_scan_bien(PDO $pdo, int $bienId): array {
        $bq = $pdo->prepare("SELECT id, id_proprietaire, reference_bien FROM biens WHERE id=?");
        $bq->execute([$bienId]); $bien = $bq->fetch(PDO::FETCH_ASSOC);
        if (!$bien) return ['ok'=>false, 'error'=>'Bien introuvable'];
        $pid = (int)$bien['id_proprietaire'];
        if ($pid <= 0) return ['ok'=>false, 'error'=>'Bien sans propriétaire'];

        $scan = oc_scan_proprio($pdo, $pid);
        if (empty($scan['ok'])) return $scan;

        // Baux de CE bien (pour relever l'ambiguïté de la pile)
        $bb = $pdo->prepare("SELECT id AS bail_id, locataire_nom FROM bien_baux WHERE id_bien=?");
        $bb->execute([$bienId]); $bauxBien = $bb->fetchAll(PDO::FETCH_ASSOC);
        $rematch = function(?string $loc) use ($bauxBien): array {
            $lt = oc_tokens((string)$loc); if (!$lt) return [];
            $hits = []; foreach ($bauxBien as $b) { if (array_intersect($lt, oc_tokens($b['locataire_nom']))) $hits[] = $b; }
            return $hits;
        };

        $items = [];
        foreach ($scan['items'] as $it) {
            if (($it['type'] ?? '') === 'MANDAT') continue;            // scope propriétaire, pas bien
            $tgt = $it['target'] ?? '';
            // 1) déjà rattaché à CE bien → on garde tel quel
            if (in_array($tgt, ['BIEN','BAIL'], true) && (int)($it['bien_id'] ?? 0) === $bienId) {
                $items[] = $it; continue;
            }
            // 2) loupé (pile) → on tente de le rattacher à CE bien
            if (($it['status'] ?? '') === 'pile') {
                $h = $rematch($it['locataire'] ?? null);
                if (count($h) === 1) {
                    $it['target']='BAIL'; $it['bien_id']=$bienId; $it['bail_id']=(int)$h[0]['bail_id'];
                    $it['status']='certain'; $it['reason']='loupé → bail de ce bien ('.$h[0]['locataire_nom'].')';
                } else {
                    $it['target']='BIEN'; $it['bien_id']=$bienId; $it['bail_id']=0;
                    $it['status']='certain'; $it['reason']='loupé → rattaché à ce bien'.(count($h)>1?' (plusieurs baux, bien seul)':'');
                }
                $items[] = $it; continue;
            }
            // 3) certain mais pour un AUTRE bien → on ignore (pas le périmètre de cette fiche)
        }
        return ['ok'=>true, 'proprio'=>$scan['proprio'], 'folder'=>$scan['folder'], 'base'=>$scan['base'],
                'bien'=>$bien, 'nb_baux_bien'=>count($bauxBien), 'items'=>$items];
    }
}

if (!function_exists('oc_scan_bail')) {
    /**
     * Scan scopé à UN bail : uniquement LE BAIL et l'EDL D'ENTRÉE du locataire de ce bail.
     * (Pas d'EDL sortie, pas de mandat, pas de DPE — strict périmètre du bail.)
     * Retient les docs dont le locataire OneDrive matche le locataire de ce bail.
     * Retour : même forme + 'bail'.
     */
    function oc_scan_bail(PDO $pdo, int $bailId): array {
        $bq = $pdo->prepare("SELECT bb.id, bb.id_bien, bb.locataire_nom, b.id_proprietaire, b.reference_bien
                             FROM bien_baux bb JOIN biens b ON b.id = bb.id_bien WHERE bb.id = ?");
        $bq->execute([$bailId]); $bail = $bq->fetch(PDO::FETCH_ASSOC);
        if (!$bail) return ['ok'=>false, 'error'=>'Bail introuvable'];
        $pid    = (int)$bail['id_proprietaire'];
        $bienId = (int)$bail['id_bien'];

        $scan = oc_scan_proprio($pdo, $pid);
        if (empty($scan['ok'])) return $scan;

        $lt = oc_tokens((string)$bail['locataire_nom']);
        $items = [];
        foreach ($scan['items'] as $it) {
            // Strict : seulement le bail signé et l'EDL d'entrée
            if (!in_array($it['doc_type'] ?? '', ['bail_signe','edl_entree'], true)) continue;
            // Doit concerner CE locataire : soit déjà ce bail, soit intersection de tokens
            $okBail = ((int)($it['bail_id'] ?? 0) === $bailId);
            $okLoc  = $lt && array_intersect($lt, oc_tokens((string)($it['locataire'] ?? '')));
            if (!$okBail && !$okLoc) continue;
            // Rattacher fermement à ce bail/bien
            $it['target']='BAIL'; $it['bien_id']=$bienId; $it['bail_id']=$bailId;
            $it['status']='certain';
            $it['reason']= $okBail ? 'doc du bail' : 'locataire du bail ('.$bail['locataire_nom'].')';
            $items[] = $it;
        }
        return ['ok'=>true, 'proprio'=>$scan['proprio'], 'folder'=>$scan['folder'], 'base'=>$scan['base'],
                'bail'=>$bail, 'items'=>$items];
    }
}

if (!function_exists('oc_commit_proposal')) {
    /** Télécharge le fichier OneDrive et le classe en GED selon la cible. */
    function oc_commit_proposal(PDO $pdo, array $it, int $proprioId, int $userId): array {
        $itemId = (string)($it['item_id'] ?? ''); if ($itemId === '') return ['ok'=>false,'error'=>'item_id manquant'];
        if (($it['status'] ?? '') !== 'certain')   return ['ok'=>false,'error'=>'non certain (pile)'];

        // ── Anti-doublon sémantique DPE : un bien ne doit avoir qu'UN DPE en GED.
        // Le dédoublonnage par hash ne couvre que le fichier identique ; ici on bloque
        // aussi un 2e DPE arrivant sous un autre nom (re-scan/export). On ne télécharge
        // même pas (économie réseau). Compté comme « déjà présent » (deduplicated).
        if ((string)($it['doc_type'] ?? '') === 'dpe' && (int)($it['bien_id'] ?? 0) > 0) {
            try {
                $stDpe = $pdo->prepare("SELECT 1 FROM ged_documents gd
                                          JOIN ged_document_links gdl ON gdl.document_id=gd.id
                                               AND gdl.entity_type='BIEN' AND gdl.entity_id=?
                                         WHERE gd.status='active'
                                           AND gd.document_type IN ('dpe','DPE','DIAG_DPE','DIAG')
                                         LIMIT 1");
                $stDpe->execute([(int)$it['bien_id']]);
                if ($stDpe->fetchColumn()) {
                    return ['ok'=>true, 'deduplicated'=>true, 'skipped_existing'=>true, 'doc_id'=>null];
                }
            } catch (Throwable $e) { /* table absente → on laisse passer */ }
        }

        graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');
        $dl = graph_download_file_content($itemId);
        if (empty($dl['ok']) || !isset($dl['content'])) return ['ok'=>false,'error'=>'téléchargement OneDrive échoué : '.($dl['error'] ?? '?')];
        $name = (string)($it['name'] ?? ($dl['name'] ?? 'doc.pdf'));
        $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) ?: 'pdf';

        // Contexte société/agence depuis le bien (ou via le proprio pour un mandat).
        require_once __DIR__ . '/bien_scope_resolver.php';
        $bienId = (int)($it['bien_id'] ?? 0);
        $rv = bien_resolve_soc_age($pdo, $bienId);
        $socId = $rv['societe_id']; $ageId = $rv['agence_id'];

        // Stockage PERMANENT (même convention que les uploaders : storage_fluxbox/{soc}/{Y}/{m})
        // → metadata.source_path pointe vers un fichier réellement servable (sinon viewer 404).
        $hash = hash('sha256', (string)$dl['content']) ?: '';
        $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_fluxbox'
                    . DIRECTORY_SEPARATOR . ((int)$socId ?: 0) . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
        if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?: ('doc.' . $ext);
        $tmp  = $storageDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . substr($hash, 0, 8) . '_' . $safe;
        if (file_put_contents($tmp, $dl['content']) === false) return ['ok'=>false,'error'=>'écriture stockage GED échouée'];
        $immId = 0;
        if ($bienId > 0) { $b=$pdo->prepare("SELECT id_immeuble FROM biens WHERE id=?"); $b->execute([$bienId]); $immId=(int)$b->fetchColumn(); }
        $tiersId = 0;
        $tp = $pdo->prepare("SELECT id_tiers FROM proprietaires WHERE id=?"); $tp->execute([$proprioId]); $tiersId=(int)$tp->fetchColumn();

        $links = [];
        if (($it['target'] ?? '') === 'IMB') {
            // TF / doc d'immeuble → lien principal IMMEUBLE (couvre tous les lots).
            $immTarget = (int)($it['imm_id'] ?? 0) ?: $immId;
            if ($immTarget > 0) $links[] = ['entity_type'=>'IMB','entity_id'=>$immTarget,'relation_type'=>'main','is_validated'=>true,'validated_by'=>$userId];
            if ($tiersId > 0)   $links[] = ['entity_type'=>'TIERS','entity_id'=>$tiersId,'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId];
        } elseif (($it['target'] ?? '') === 'PROPRIO') {
            if ($tiersId > 0) $links[] = ['entity_type'=>'TIERS','entity_id'=>$tiersId,'relation_type'=>'main','is_validated'=>true,'validated_by'=>$userId];
        } else {
            if ($bienId > 0) $links[] = ['entity_type'=>'BIEN','entity_id'=>$bienId,'relation_type'=>'main','is_validated'=>true,'validated_by'=>$userId];
            if (!empty($it['bail_id'])) $links[] = ['entity_type'=>'BAIL','entity_id'=>(int)$it['bail_id'],'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId];
            if ($immId > 0) $links[] = ['entity_type'=>'IMB','entity_id'=>$immId,'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId];
            if ($tiersId > 0) $links[] = ['entity_type'=>'TIERS','entity_id'=>$tiersId,'relation_type'=>'reference','is_validated'=>true,'validated_by'=>$userId];
        }
        if (!$links) { @unlink($tmp); return ['ok'=>false,'error'=>'aucune entité cible']; }

        [$n2, $n3] = oc_slugs((string)$it['doc_type']);
        $ctx = ['tenant_id'=>$socId,'societe_id'=>$socId,'agence_id'=>$ageId,'document_type'=>$it['doc_type'],
                'source_module'=>'05_GESTION_LOCATIVE','security_level'=>'interne','created_by'=>$userId,
                'metadata_extra'=>['origin'=>'onedrive_import'],   // marqueur → suppression/recommencer ciblée
                'name_display'=>($it['name_display'] ?? ''),   // nom humain lisible (locataire + réf)
                'naming_ctx'=>['upload_date'=>'now','n1_slug'=>'05_gestion_locative','n2_slug'=>$n2,'n3_slug'=>$n3,
                    'date_doc'=>null,'type_doc'=>$it['doc_type'],'source_filename'=>$name,'user_id'=>$userId,
                    'entity_type'=>($it['target']==='PROPRIO'?'TIERS':($it['target']==='IMB'?'IMB':'BIEN')),
                    'entity_id'=>($it['target']==='PROPRIO'?$tiersId:($it['target']==='IMB'?((int)($it['imm_id']??0)?:$immId):$bienId))]];

        $res = gus_commit_document($pdo, ['path_on_disk'=>$tmp,'name_original'=>$name,'hash_sha256'=>$hash,
            'mime_type'=>($dl['mime_type'] ?? 'application/pdf'),'size_bytes'=>(int)($dl['size'] ?? @filesize($tmp) ?: 0)], $ctx, $links);
        if (!empty($res['ok']) && !empty($it['bail_id'])) {
            try { $pdo->prepare("UPDATE ged_documents SET id_bail=? WHERE id=?")->execute([(int)$it['bail_id'],(int)$res['doc_id']]); } catch (Throwable $e) {}
        }
        return $res;
    }
}
