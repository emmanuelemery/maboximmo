<?php
/**
 * inc/mandat_registre.php — Extraction IA des mandats de gestion + registre des mandats.
 *
 * - mr_ged_doc_path()       : chemin physique d'un mandat PDF en GED (cascade comme ged_doc_serve).
 * - mr_extraire_mandat()    : envoie le PDF à Claude (Haiku), extrait les champs, calcule le statut,
 *                              et UPSERT dans mandats_registre.
 * - mr_calcul_statut()      : actif / terminé depuis dates + reconduction/résiliation (plafond 30 ans).
 * - mr_for_proprio()        : mandat(s) d'un propriétaire (fiche 360).
 * - mr_list_registre()      : le registre complet (admin).
 * - mr_mandats_a_extraire() : mandats GED (doc_type mandat_gestion) pas encore extraits.
 *
 * Réutilise les helpers IA bas niveau : mbi_supports_ia_anthropic_key / _extract_json / _estimer_cout
 * et agence_doc_ocr_resolve_local_or_fetch (cross-env).
 */
declare(strict_types=1);
require_once __DIR__ . '/mbi_supports_score_ia.php';
require_once __DIR__ . '/agence_doc_officiel_ocr.php';

if (!function_exists('mr_col_exists')) {
    /** Vrai si la colonne existe dans mandats_registre (cache statique). */
    function mr_col_exists(PDO $pdo, string $col): bool {
        static $cols = null;
        if ($cols === null) { try { $cols = $pdo->query("SHOW COLUMNS FROM mandats_registre")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $cols = []; } }
        return in_array($col, $cols, true);
    }
    /** Clause SQL excluant les doublons masqués (vide si la colonne n'existe pas encore). */
    function mr_filtre_doublon(PDO $pdo, string $alias = 'mr'): string {
        return mr_col_exists($pdo, 'is_doublon') ? " AND COALESCE($alias.is_doublon,0)=0 " : '';
    }
}

if (!function_exists('mr_ged_doc_path')) {
    /** Chemin physique absolu d'un document GED (mandat PDF), ou '' si introuvable. */
    function mr_ged_doc_path(PDO $pdo, int $docId): string {
        $st = $pdo->prepare("SELECT final_destination, metadata FROM ged_documents WHERE id=?");
        $st->execute([$docId]); $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d) return '';
        $publicHtml = dirname(__DIR__);
        // Source : metadata.source_path (chemin absolu posé par gus_commit_document)
        $meta = $d['metadata'] ? json_decode((string)$d['metadata'], true) : [];
        $sp = (string)($meta['source_path'] ?? '');
        if ($sp !== '' && is_file($sp)) return $sp;
        // Fallback : final_destination relatif
        if (!empty($d['final_destination'])) {
            $cand = $publicHtml . '/' . ltrim((string)$d['final_destination'], '/');
            if (is_file($cand)) return $cand;
        }
        if ($sp !== '') return $sp;   // chemin absolu d'un autre env → résolu plus loin via fetch
        return '';
    }
}

if (!function_exists('mr_docx_to_text')) {
    /** Extrait le texte d'un .docx en PHP pur (ZipArchive → word/document.xml). '' si échec. */
    function mr_docx_to_text(string $path): string {
        if (!class_exists('ZipArchive')) return '';
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') return '';
        // Paragraphes / sauts de ligne → \n, puis on retire les balises
        $xml = preg_replace('/<w:p[ >]/', "\n<w:p ", $xml);
        $xml = preg_replace('/<w:(br|tab)\b[^>]*>/', ' ', $xml);
        $txt = strip_tags($xml);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $txt = preg_replace("/[ \t]+/", ' ', $txt);
        $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
        return trim((string)$txt);
    }
    /** Extrait le texte d'un .doc binaire via antiword/catdoc si dispo (sinon ''). */
    function mr_doc_to_text(string $path): string {
        if (!function_exists('shell_exec')) return '';
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) return '';
        foreach (['antiword', 'catdoc'] as $bin) {
            $which = trim((string)@shell_exec('command -v ' . $bin . ' 2>/dev/null'));
            if ($which !== '') {
                $out = (string)@shell_exec($bin . ' ' . escapeshellarg($path) . ' 2>/dev/null');
                if (trim($out) !== '') return trim($out);
            }
        }
        return '';
    }
}

if (!function_exists('mr_extraction_prompt')) {
    function mr_extraction_prompt(): string {
        return "Tu es un assistant juridique spécialisé en gestion immobilière (loi Hoguet). "
            . "On te donne un MANDAT DE GESTION (gérance) en PDF. Extrais STRICTEMENT les données ci-dessous "
            . "et réponds UNIQUEMENT par un objet JSON valide, sans texte autour, sans ```.\n\n"
            . "Champs (mets null si absent) :\n"
            . "numero_mandat (string : si le document porte PLUSIEURS numéros de mandat, mets ICI le plus RÉCENT/élevé, ex \"971\"), "
            . "numero_mandat_alt (string : l'AUTRE numéro / ancien numéro si le document en porte un second, ex \"10\" ; sinon null), "
            . "mandataire (string, nom de l'agence), "
            . "mandant_noms (string, noms des propriétaires séparés par ' / '), "
            . "bien_designation (string), "
            . "date_effet (YYYY-MM-DD ; si non explicite = date de signature), "
            . "duree_initiale_ans (int), "
            . "duree_ferme (bool ; true si 'durée ferme' sans reconduction), "
            . "tacite_reconduction (bool), "
            . "periode_reconduction_ans (int ou null), "
            . "date_fin_theorique (YYYY-MM-DD ou null ; = date_effet + duree pour une durée ferme), "
            . "preavis_resiliation_mois (int), "
            . "date_resiliation (YYYY-MM-DD ou null si le mandat n'a pas été dénoncé), "
            . "hono_gestion_taux_ht (number, %), hono_gestion_taux_ttc (number, %), "
            . "hono_gestion_assiette (string, ex \"encaissements\"), "
            . "hono_location (string, ex \"1 mois de loyer\" ou null), "
            . "hono_location_remise_pct (number ou null), "
            . "hono_contentieux (string, ex \"NEANT\" ou montant), "
            . "hono_declaration_fiscale_eur (number ou null), "
            . "crg_periodicite (string ex \"trimestrielle\"), "
            . "travaux_seuil_autorisation (string : montant max de réparations/travaux que le mandataire peut engager SANS accord du propriétaire, ex \"1 loyer mensuel\" ou \"150 €\" ; null si absent), "
            . "confidence (int 0-100, ta confiance globale).";
    }
}

if (!function_exists('mr_calcul_statut')) {
    /** Calcule 'actif' | 'termine' | 'inconnu' depuis les données extraites. */
    function mr_calcul_statut(array $d): string {
        $today = date('Y-m-d');
        $effet = (string)($d['date_effet'] ?? '');
        // Résilié explicitement
        if (!empty($d['date_resiliation']) && $d['date_resiliation'] <= $today) return 'termine';
        if ($effet === '') return 'inconnu';
        // Durée ferme → fin = effet + durée (ou date_fin_theorique fournie)
        if (!empty($d['duree_ferme'])) {
            $fin = (string)($d['date_fin_theorique'] ?? '');
            if ($fin === '' && !empty($d['duree_initiale_ans'])) {
                $fin = date('Y-m-d', strtotime($effet . ' +' . (int)$d['duree_initiale_ans'] . ' years'));
            }
            if ($fin === '') return 'inconnu';
            return ($today < $fin) ? 'actif' : 'termine';
        }
        // Tacite reconduction → plafond légal 30 ans depuis la prise d'effet
        if (!empty($d['tacite_reconduction'])) {
            $plafond = date('Y-m-d', strtotime($effet . ' +30 years'));
            return ($today < $plafond) ? 'actif' : 'termine';
        }
        // Ni ferme ni tacite : actif jusqu'à la fin théorique si connue
        $fin = (string)($d['date_fin_theorique'] ?? '');
        if ($fin !== '') return ($today < $fin) ? 'actif' : 'termine';
        return 'inconnu';
    }
}

if (!function_exists('mr_extraire_mandat')) {
    /**
     * Extrait un mandat PDF (GED) via Claude et l'enregistre dans mandats_registre.
     * @return array{ok:bool, id?:int, statut?:string, cout_centimes?:int, error?:string, data?:array}
     */
    function mr_extraire_mandat(PDO $pdo, int $docId, int $proprioId = 0, int $tiersId = 0, string $modele = 'haiku'): array {
        $path = mr_ged_doc_path($pdo, $docId);
        if ($path === '') return ['ok'=>false, 'error'=>'fichier GED introuvable'];

        $resolved = agence_doc_ocr_resolve_local_or_fetch($path);
        if (($resolved['erreur'] ?? null) !== null) return ['ok'=>false, 'error'=>$resolved['erreur']];
        $local = $resolved['path']; $tmp = !empty($resolved['is_temp']) ? $local : null;
        if (!is_file($local)) { if ($tmp) @unlink($tmp); return ['ok'=>false, 'error'=>'fichier illisible']; }

        $apiKey = mbi_supports_ia_anthropic_key();
        if ($apiKey === '') { if ($tmp) @unlink($tmp); return ['ok'=>false, 'error'=>'clé API absente']; }

        $model = match (strtolower($modele)) {
            'sonnet' => 'claude-sonnet-4-6',
            'haiku'  => 'claude-haiku-4-5-20251001',
            default  => $modele,
        };
        // Choix de la source selon le format :
        //  - PDF / image          → bloc media (Claude rend les pages — plus cher)
        //  - .docx                → texte extrait en PHP pur (ZipArchive) — ~10× moins cher
        //  - .doc binaire         → antiword/catdoc si dispo, sinon on saute (à convertir)
        $mime = function_exists('mime_content_type') ? (mime_content_type($local) ?: '') : '';
        $ext  = strtolower((string)pathinfo($local, PATHINFO_EXTENSION));
        $contentBlocks = null;
        $voie = '';
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            $contentBlocks = [['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>base64_encode((string)file_get_contents($local))]],
                              ['type'=>'text','text'=>'Extrais le JSON du mandat.']]; $voie='pdf';
        } elseif (str_starts_with($mime, 'image/') || in_array($ext, ['jpg','jpeg','png','tiff','tif','webp'], true)) {
            $contentBlocks = [['type'=>'image','source'=>['type'=>'base64','media_type'=>($mime ?: 'image/jpeg'),'data'=>base64_encode((string)file_get_contents($local))]],
                              ['type'=>'text','text'=>'Extrais le JSON du mandat.']]; $voie='image';
        } elseif ($ext === 'docx') {
            $texte = mr_docx_to_text($local);
            if (mb_strlen($texte) > 200) { $contentBlocks = [['type'=>'text','text'=>"Voici le texte d'un mandat de gestion :\n\n".$texte]]; $voie='docx-texte'; }
        } elseif ($ext === 'doc') {
            $texte = mr_doc_to_text($local);
            if (mb_strlen($texte) > 200) { $contentBlocks = [['type'=>'text','text'=>"Voici le texte d'un mandat de gestion :\n\n".$texte]]; $voie='doc-texte'; }
        }
        if ($contentBlocks === null) {
            if ($tmp) @unlink($tmp);
            return ['ok'=>false, 'error'=>'format non lisible ('.($ext ?: $mime).') — .doc binaire sans antiword, à convertir en PDF', 'skip'=>true];
        }
        $payload = [
            'model' => $model, 'max_tokens' => 1500, 'temperature' => 0.0,
            'system' => mr_extraction_prompt(),
            'messages' => [['role'=>'user','content'=>$contentBlocks]],
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['x-api-key: '.$apiKey,'anthropic-version: 2023-06-01','anthropic-beta: pdfs-2024-09-25','content-type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT=>120]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        if ($tmp) @unlink($tmp);
        if ($raw === false || $code !== 200) return ['ok'=>false, 'error'=>"anthropic_http_$code: ".($cerr ?: substr((string)$raw,0,200))];

        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        $data = is_string($text) ? mbi_supports_ia_extract_json($text) : null;
        if (!is_array($data)) return ['ok'=>false, 'error'=>'json invalide', 'data'=>['raw'=>$text]];

        $cout = mbi_supports_ia_estimer_cout($body);
        $statut = mr_calcul_statut($data);

        // Fin théorique recalculée si manquante (durée ferme)
        $finTheo = $data['date_fin_theorique'] ?? null;
        if (!$finTheo && !empty($data['date_effet']) && !empty($data['duree_initiale_ans'])) {
            $finTheo = date('Y-m-d', strtotime($data['date_effet'].' +'.(int)$data['duree_initiale_ans'].' years'));
        }

        $cols = [
            'ged_document_id'=>$docId, 'id_proprietaire'=>$proprioId ?: null, 'id_tiers'=>$tiersId ?: null,
            'numero_mandat'=>$data['numero_mandat'] ?? null, 'numero_mandat_alt'=>$data['numero_mandat_alt'] ?? null, 'mandataire'=>$data['mandataire'] ?? null,
            'mandant_noms'=>$data['mandant_noms'] ?? null, 'bien_designation'=>$data['bien_designation'] ?? null,
            'date_effet'=>$data['date_effet'] ?? null, 'duree_initiale_ans'=>$data['duree_initiale_ans'] ?? null,
            'duree_ferme'=>!empty($data['duree_ferme'])?1:0, 'tacite_reconduction'=>!empty($data['tacite_reconduction'])?1:0,
            'periode_reconduction_ans'=>$data['periode_reconduction_ans'] ?? null, 'date_fin_theorique'=>$finTheo,
            'preavis_resiliation_mois'=>$data['preavis_resiliation_mois'] ?? null, 'date_resiliation'=>$data['date_resiliation'] ?? null,
            'hono_gestion_taux_ht'=>$data['hono_gestion_taux_ht'] ?? null, 'hono_gestion_taux_ttc'=>$data['hono_gestion_taux_ttc'] ?? null,
            'hono_gestion_assiette'=>$data['hono_gestion_assiette'] ?? null, 'hono_location'=>$data['hono_location'] ?? null,
            'hono_location_remise_pct'=>$data['hono_location_remise_pct'] ?? null, 'hono_contentieux'=>$data['hono_contentieux'] ?? null,
            'hono_declaration_fiscale_eur'=>$data['hono_declaration_fiscale_eur'] ?? null, 'crg_periodicite'=>$data['crg_periodicite'] ?? null,
            'travaux_seuil_autorisation'=>$data['travaux_seuil_autorisation'] ?? null,
            'statut'=>$statut, 'extraction_modele'=>$model, 'extraction_cout_centimes'=>$cout,
            'extraction_confidence'=>isset($data['confidence'])?(int)$data['confidence']:null,
            'extraction_raw'=>json_encode($data, JSON_UNESCAPED_UNICODE), 'extracted_at'=>date('Y-m-d H:i:s'),
        ];
        // Ne garder que les colonnes existantes (résilient si une migration n'est pas encore jouée)
        try { $existing = $pdo->query("SHOW COLUMNS FROM mandats_registre")->fetchAll(PDO::FETCH_COLUMN); $cols = array_intersect_key($cols, array_flip($existing)); } catch (Throwable $e) {}
        // UPSERT sur ged_document_id (UNIQUE)
        $names = array_keys($cols);
        $place = implode(',', array_map(fn($n)=>":$n", $names));
        $upd   = implode(',', array_map(fn($n)=>"`$n`=VALUES(`$n`)", array_filter($names, fn($n)=>$n!=='ged_document_id')));
        $sql = "INSERT INTO mandats_registre (`".implode('`,`',$names)."`) VALUES ($place) ON DUPLICATE KEY UPDATE $upd";
        $st = $pdo->prepare($sql);
        foreach ($cols as $k=>$v) $st->bindValue(":$k", $v);
        $st->execute();
        $id = (int)($pdo->lastInsertId() ?: 0);

        return ['ok'=>true, 'id'=>$id, 'statut'=>$statut, 'cout_centimes'=>$cout, 'voie'=>$voie, 'data'=>$data];
    }
}

if (!function_exists('mr_for_proprio')) {
    /** Le(s) mandat(s) extrait(s) d'un propriétaire (le plus récent en tête). */
    function mr_for_proprio(PDO $pdo, int $proprioId): array {
        $st = $pdo->prepare("SELECT * FROM mandats_registre WHERE id_proprietaire=? ORDER BY (statut='actif') DESC, date_effet DESC");
        $st->execute([$proprioId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('mr_mandats_a_extraire')) {
    /** Documents GED de type mandat_gestion sans extraction dans le registre. */
    function mr_mandats_a_extraire(PDO $pdo, int $limit = 500): array {
        $sql = "SELECT gd.id AS ged_document_id, gd.name_display,
                       gdl.entity_id AS tiers_id,
                       (SELECT p.id FROM proprietaires p WHERE p.id_tiers = gdl.entity_id LIMIT 1) AS proprio_id
                FROM ged_documents gd
                JOIN ged_document_links gdl ON gdl.document_id = gd.id AND gdl.entity_type='TIERS'
                WHERE gd.status='active' AND gd.document_type='mandat_gestion'
                  AND NOT EXISTS (SELECT 1 FROM mandats_registre mr WHERE mr.ged_document_id = gd.id)
                GROUP BY gd.id
                LIMIT " . (int)$limit;
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }
}

if (!function_exists('mr_proprios_sans_mandat')) {
    /**
     * Propriétaires CRG (périmètre 63-1/63-2 avec biens) SANS mandat au registre.
     * nb_doc_mandat > 0 → un PDF mandat existe en GED mais pas encore extrait (à extraire) ;
     * nb_doc_mandat = 0 → aucun mandat trouvé (vraiment manquant, à récupérer/scanner).
     */
    function mr_proprios_sans_mandat(PDO $pdo): array {
        $sql = "SELECT p.id, COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS nom,
                       a.code_agence, p.code_compte, p.id_tiers,
                       (SELECT COUNT(*) FROM biens b WHERE b.id_proprietaire=p.id) AS nb_biens,
                       (SELECT COUNT(*) FROM ged_documents gd
                          JOIN ged_document_links gdl ON gdl.document_id=gd.id AND gdl.entity_type='TIERS' AND gdl.entity_id=p.id_tiers
                          WHERE gd.status='active' AND gd.document_type='mandat_gestion') AS nb_doc_mandat
                FROM proprietaires p
                JOIN agences a ON a.id=p.id_agence
                WHERE p.actif=1 AND a.code_agence IN ('63-1','63-2')
                  AND EXISTS(SELECT 1 FROM biens b WHERE b.id_proprietaire=p.id)
                  AND NOT EXISTS(SELECT 1 FROM mandats_registre mr WHERE mr.id_proprietaire=p.id)
                ORDER BY nom";
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }
}

if (!function_exists('mr_audit_crg')) {
    /** Audit du rattachement mandats → propriétaires CRG. Retour : compteurs + lignes problématiques. */
    function mr_audit_crg(PDO $pdo): array {
        $stats = ['total'=>0,'avec_proprio'=>0,'avec_crg'=>0,'sans_proprio'=>0,'proprio_sans_crg'=>0];
        try {
            $r = $pdo->query("SELECT
                COUNT(*) total,
                SUM(mr.id_proprietaire IS NOT NULL) avec_proprio,
                SUM(mr.id_proprietaire IS NOT NULL AND EXISTS(SELECT 1 FROM crg_trimestres c WHERE c.id_proprietaire=mr.id_proprietaire)) avec_crg,
                SUM(mr.id_proprietaire IS NULL) sans_proprio
              FROM mandats_registre mr")->fetch(PDO::FETCH_ASSOC) ?: [];
            $stats['total']=(int)($r['total']??0); $stats['avec_proprio']=(int)($r['avec_proprio']??0);
            $stats['avec_crg']=(int)($r['avec_crg']??0); $stats['sans_proprio']=(int)($r['sans_proprio']??0);
            $stats['proprio_sans_crg']=$stats['avec_proprio']-$stats['avec_crg'];
        } catch (Throwable $e) {}
        // Lignes problématiques : pas de proprio, ou proprio sans CRG
        $probl = [];
        try {
            $probl = $pdo->query("SELECT mr.id, mr.numero_mandat, mr.id_proprietaire, mr.ged_document_id,
                       COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) proprio_nom,
                       (mr.id_proprietaire IS NULL) sans_proprio,
                       (mr.id_proprietaire IS NOT NULL AND NOT EXISTS(SELECT 1 FROM crg_trimestres c WHERE c.id_proprietaire=mr.id_proprietaire)) sans_crg
                FROM mandats_registre mr LEFT JOIN proprietaires p ON p.id=mr.id_proprietaire
                WHERE mr.id_proprietaire IS NULL
                   OR NOT EXISTS(SELECT 1 FROM crg_trimestres c WHERE c.id_proprietaire=mr.id_proprietaire)
                ORDER BY proprio_nom LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
        return ['stats'=>$stats, 'problematiques'=>$probl];
    }
}

if (!function_exists('mr_doublons')) {
    /**
     * Détecte les doublons de mandats (même mandat présent en plusieurs fichiers GED).
     * Clé de regroupement : numéro normalisé (chiffres seuls, ex 679/679G/679.G → 679),
     * sinon (proprio + date_effet) pour les mandats sans numéro.
     * Retour : [ ['cle'=>..., 'rows'=>[...]], ... ] uniquement les groupes de 2+.
     */
    function mr_doublons(PDO $pdo): array {
        $sql = "SELECT mr.*, COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS proprio_nom,
                   COALESCE(NULLIF(REGEXP_REPLACE(IFNULL(mr.numero_mandat,''),'[^0-9]',''),''),
                            CONCAT('P', IFNULL(mr.id_proprietaire,0), '_', IFNULL(mr.date_effet,'?'))) AS cle
                FROM mandats_registre mr
                LEFT JOIN proprietaires p ON p.id = mr.id_proprietaire
                ORDER BY cle, (mr.statut='actif') DESC,
                         (mr.date_effet IS NOT NULL) DESC, (mr.hono_gestion_taux_ttc IS NOT NULL) DESC, mr.id";
        try { $all = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; }
        $groups = [];
        // Les lignes déjà masquées (is_doublon=1) ne comptent plus : un groupe résolu disparaît de la liste.
        foreach ($all as $r) { if ((int)($r['is_doublon'] ?? 0) === 1) continue; $groups[$r['cle']][] = $r; }
        $dups = [];
        foreach ($groups as $cle => $rows) {
            if (count($rows) > 1) $dups[] = ['cle'=>$cle, 'rows'=>$rows];
        }
        return $dups;
    }
}

if (!function_exists('mr_doublons_masques')) {
    /** Lignes marquées comme doublon (is_doublon=1) — pour la liste « masquées » restaurables. */
    function mr_doublons_masques(PDO $pdo): array {
        if (!mr_col_exists($pdo, 'is_doublon')) return [];
        $sql = "SELECT mr.*, COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS proprio_nom
                FROM mandats_registre mr
                LEFT JOIN proprietaires p ON p.id = mr.id_proprietaire
                WHERE COALESCE(mr.is_doublon,0)=1
                ORDER BY mr.numero_mandat, mr.id";
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }
}

if (!function_exists('mr_list_registre')) {
    /** Le registre complet (ordonné par n° de mandat numérique puis date). */
    function mr_list_registre(PDO $pdo): array {
        $sql = "SELECT mr.*, p.nom AS proprio_nom_col,
                       COALESCE(NULLIF(p.societe,''),TRIM(CONCAT_WS(' ',p.prenom,p.nom))) AS proprio_nom
                FROM mandats_registre mr
                LEFT JOIN proprietaires p ON p.id = mr.id_proprietaire
                WHERE 1=1 " . mr_filtre_doublon($pdo) . "
                ORDER BY CAST(NULLIF(REGEXP_REPLACE(mr.numero_mandat,'[^0-9]',''),'') AS UNSIGNED), mr.date_effet";
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }
}
