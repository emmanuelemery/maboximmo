<?php
declare(strict_types=1);

/**
 * inc/rh_bulletins_lib.php — Bulletins de paie : regroupement, découpe, rangement.
 *
 * Trois besoins, une seule source de vérité :
 *   1. rhb_depots()          — les PDF déposés par le comptable, un par agence
 *                              (`rh_salaires_comparaisons`, type='bulletins').
 *   2. rhb_zip()             — les rassembler en UN fichier téléchargeable.
 *   3. rhb_pages_par_salarie() + rhb_extraire_pages()
 *                            — découper le PDF d'agence en un bulletin par salarié.
 *
 * OUTILLAGE : aucune dépendance nouvelle.
 *   · `smalot/pdfparser` donne le texte PAGE PAR PAGE (détection du salarié) ;
 *   · l'API d'import de mPDF (qui embarque le lecteur FPDI) extrait les pages.
 *   Poppler/`shell_exec` ne sont PAS utilisés : ils manquent sur l'hébergement
 *   mutualisé de production.
 *
 * ⚠️ CONFIDENTIALITÉ — la règle qui prime sur tout le reste :
 *   un bulletin attribué au mauvais salarié est une fuite de rémunération.
 *   La détection ne « choisit » jamais entre deux candidats : toute page
 *   ambiguë ou muette part dans le lot « à vérifier », jamais dans le dossier
 *   d'un salarié probable. Voir rhb_pages_par_salarie().
 *
 * ⚠️ IDENTIFIANTS — ne PAS utiliser rh_load_expected_map()['id_user'] : son
 *   SELECT `u.id, …, s.*` laisse `salaires.id` écraser `u.id`, donc ce champ
 *   vaut un salaires.id. Ici les salariés sont lus DIRECTEMENT dans `users`.
 */

if (!function_exists('rhb_ascii')) {
    /** MAJUSCULES sans accents ni ponctuation, espaces compressés — pour comparer des noms. */
    function rhb_ascii(string $s): string
    {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, [
            'À'=>'A','Â'=>'A','Ä'=>'A','Á'=>'A','Ã'=>'A','Å'=>'A',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'Î'=>'I','Ï'=>'I','Í'=>'I',
            'Ô'=>'O','Ö'=>'O','Ó'=>'O','Õ'=>'O',
            'Ù'=>'U','Û'=>'U','Ü'=>'U','Ú'=>'U',
            'Ç'=>'C','Ñ'=>'N','Œ'=>'OE','Æ'=>'AE',
            '’'=>' ', '\''=>' ', '-'=>' ', '_'=>' ', '.'=>' ',
        ]);
        $s = preg_replace('/[^A-Z0-9 ]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}

if (!function_exists('rhb_slug')) {
    /** Fragment de nom de fichier sûr (pas d'accent, pas d'espace, pas de séparateur). */
    function rhb_slug(string $s, int $max = 40): string
    {
        $s = rhb_ascii($s);
        $s = str_replace(' ', '-', $s);
        $s = preg_replace('/-+/', '-', $s) ?? $s;
        $s = trim($s, '-');
        if ($s === '') $s = 'sans-nom';
        return mb_substr($s, 0, $max);
    }
}

if (!function_exists('rhb_chemin_absolu')) {
    /**
     * Résout un `file_path` stocké vers un chemin serveur lisible.
     * Les valeurs en base sont hétérogènes : chemins absolus Windows hérités,
     * chemins web « /uploads/… », chemins relatifs. On essaie les formes connues
     * plutôt que d'en imposer une (aucune migration de données nécessaire).
     */
    function rhb_chemin_absolu(?string $stocke): ?string
    {
        $p = trim((string)$stocke);
        if ($p === '') return null;
        $racine = dirname(__DIR__);                       // …/public_html
        $essais = [
            $p,
            $racine . '/' . ltrim(str_replace('\\', '/', $p), '/'),
            $racine . '/uploads/' . ltrim(str_replace('\\', '/', $p), '/'),
        ];
        foreach ($essais as $e) {
            $e = str_replace('\\', '/', $e);
            if (is_file($e) && is_readable($e)) return $e;
        }
        return null;
    }
}

if (!function_exists('rhb_depots')) {
    /**
     * Les dépôts de bulletins d'un mois : LE PLUS RÉCENT par agence.
     *
     * Le comptable redépose parfois un fichier corrigé : ne prendre que le dernier
     * évite de livrer deux versions du même bulletin dans le ZIP.
     *
     * @param int $societeId 0 = toutes les sociétés (périmètre admin)
     * @param int $agenceId  0 = toutes les agences
     * @return array<int,array> lignes + 'chemin' (absolu ou null) + libellés société/agence
     */
    function rhb_depots(PDO $pdo, int $societeId, int $mois, int $annee, int $agenceId = 0): array
    {
        $sql = "SELECT c.id, c.id_societe, c.id_agence, c.mois, c.annee, c.file_name, c.file_path, c.created_at,
                       s.nom AS societe_nom, a.nom_agence AS agence_nom
                  FROM rh_salaires_comparaisons c
             LEFT JOIN societes s ON s.id = c.id_societe
             LEFT JOIN agences  a ON a.id = c.id_agence
                 WHERE c.type = 'bulletins' AND c.mois = ? AND c.annee = ?
                   AND c.file_path IS NOT NULL AND c.file_path <> ''";
        $args = [$mois, $annee];
        if ($societeId > 0) { $sql .= " AND c.id_societe = ?"; $args[] = $societeId; }
        if ($agenceId  > 0) { $sql .= " AND c.id_agence  = ?"; $args[] = $agenceId;  }
        // created_at DESC puis id DESC : le plus récent d'abord, on ne garde que lui.
        $sql .= " ORDER BY c.id_societe, c.id_agence, c.created_at DESC, c.id DESC";

        $st = $pdo->prepare($sql);
        $st->execute($args);

        $vus = []; $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cle = $r['id_societe'] . ':' . $r['id_agence'];
            if (isset($vus[$cle])) continue;             // déjà pris = version plus récente
            $vus[$cle] = true;
            $r['chemin'] = rhb_chemin_absolu($r['file_path']);
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('rhb_nom_fichier_depot')) {
    /**
     * Nom lisible d'un dépôt dans le ZIP : « 2026-04_Regie-Emery_LYON.pdf ».
     * Le nom d'origine du comptable (« Bulletins définitifs 1er envoi.pdf ») ne dit
     * ni la société ni l'agence — inexploitable une fois les fichiers rassemblés.
     */
    function rhb_nom_fichier_depot(array $depot): string
    {
        return sprintf('%04d-%02d_%s_%s.pdf',
            (int)$depot['annee'], (int)$depot['mois'],
            rhb_slug((string)($depot['societe_nom'] ?? 'societe'), 28),
            rhb_slug((string)($depot['agence_nom']  ?? 'agence'),  28));
    }
}

if (!function_exists('rhb_zip')) {
    /**
     * Rassemble les dépôts dans un ZIP.
     * @return array{ok:bool, ajoutes:int, manquants:array<int,string>, error:?string}
     */
    function rhb_zip(array $depots, string $zipPath): array
    {
        $out = ['ok' => false, 'ajoutes' => 0, 'manquants' => [], 'error' => null];
        if (!class_exists('ZipArchive')) { $out['error'] = 'Extension ZIP absente du serveur.'; return $out; }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $out['error'] = 'Création du ZIP impossible.'; return $out;
        }
        $noms = [];
        foreach ($depots as $d) {
            $etiquette = trim(((string)($d['societe_nom'] ?? '')) . ' — ' . ((string)($d['agence_nom'] ?? '')));
            if (empty($d['chemin'])) { $out['manquants'][] = $etiquette; continue; }
            $nom = rhb_nom_fichier_depot($d);
            // Deux agences homonymes ne doivent pas s'écraser dans l'archive.
            if (isset($noms[$nom])) { $nom = preg_replace('/\.pdf$/i', '', $nom) . '_' . (int)$d['id'] . '.pdf'; }
            $noms[$nom] = true;
            if ($zip->addFile($d['chemin'], $nom)) $out['ajoutes']++;
            else $out['manquants'][] = $etiquette;
        }
        $zip->close();
        $out['ok'] = $out['ajoutes'] > 0;
        if (!$out['ok'] && !$out['error']) $out['error'] = 'Aucun fichier de bulletins lisible sur le serveur.';
        return $out;
    }
}

if (!function_exists('rhb_societe_utilisateur')) {
    /**
     * Société de rattachement d'un utilisateur, lue dans `users` — la source dont
     * rh_salaires.php se sert déjà. La session peut en diverger (rattachement
     * modifié depuis la connexion) ; s'y fier ferait refuser un téléchargement
     * pourtant légitime, ou l'autoriser sur le mauvais périmètre.
     * Repli sur la session si la lecture échoue.
     */
    function rhb_societe_utilisateur(PDO $pdo, int $userId): int
    {
        if ($userId > 0) {
            try {
                $st = $pdo->prepare("SELECT id_societe FROM users WHERE id = ? LIMIT 1");
                $st->execute([$userId]);
                $v = (int)($st->fetchColumn() ?: 0);
                if ($v > 0) return $v;
            } catch (Throwable $e) { /* repli session */ }
        }
        return (int)($_SESSION['id_societe'] ?? 0);
    }
}

if (!function_exists('rhb_salaries')) {
    /**
     * Salariés d'une société, lus DIRECTEMENT dans `users` (voir l'avertissement
     * en tête de fichier sur rh_load_expected_map).
     *
     * @return array<int,array{id:int,matricule:string,nom:string,prenom:string,id_agence:int,complet:string}>
     */
    function rhb_salaries(PDO $pdo, int $societeId, int $agenceId = 0): array
    {
        $sql = "SELECT id, COALESCE(matricule_paie,'') AS matricule, COALESCE(nom,'') AS nom,
                       COALESCE(prenom,'') AS prenom, COALESCE(id_agence,0) AS id_agence,
                       COALESCE(num_secu,'') AS num_secu
                  FROM users
                 WHERE actif = 1 AND est_salarie = 1";
        $args = [];
        if ($societeId > 0) { $sql .= " AND id_societe = ?"; $args[] = $societeId; }
        if ($agenceId  > 0) { $sql .= " AND id_agence  = ?"; $args[] = $agenceId;  }
        $sql .= " ORDER BY nom, prenom";
        $st = $pdo->prepare($sql);
        $st->execute($args);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['id']        = (int)$r['id'];
            $r['id_agence'] = (int)$r['id_agence'];
            $r['complet']   = trim($r['prenom'] . ' ' . $r['nom']);
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('rhb_pages_texte')) {
    /**
     * Texte de CHAQUE page, avec repli.
     *
     * `smalot/pdfparser` sait découper en pages, mais selon la structure du PDF son
     * getText() par page peut revenir vide, ou renvoyer le même texte pour toutes.
     * Les deux cas ruinent la détection sans lever la moindre erreur — c'est ce qui
     * produisait « un seul bulletin » : la page 1 identifiée, toutes les suivantes
     * muettes et rattachées à elle.
     *
     * On mesure donc la qualité de l'extraction, et si elle est mauvaise on
     * découpe le PDF page à page (mPDF/FPDI) pour lire chaque page isolément.
     *
     * @return array{ok:bool,error:?string,pages:array<int,string>,methode:string,diagnostic:array}
     */
    function rhb_pages_texte(string $pdfPath): array
    {
        $out = ['ok'=>false, 'error'=>null, 'pages'=>[], 'methode'=>'', 'diagnostic'=>[]];
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists('Smalot\\PdfParser\\Parser')) { $out['error'] = 'Lecteur PDF indisponible (smalot/pdfparser).'; return $out; }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($pdfPath);
            $pages  = $pdf->getPages();
        } catch (Throwable $e) { $out['error'] = 'Lecture du PDF impossible : ' . $e->getMessage(); return $out; }

        $total = count($pages);
        if ($total === 0) { $out['error'] = 'PDF sans page exploitable (scanné ?).'; return $out; }

        $textes = [];
        foreach ($pages as $i => $pg) $textes[$i + 1] = (string)@$pg->getText();

        // Qualité : combien de pages portent un texte exploitable, et sont-elles distinctes ?
        $nonVides = 0;
        foreach ($textes as $t) if (mb_strlen(trim($t)) >= 40) $nonVides++;
        $distinctes = count(array_unique(array_map(fn($t) => mb_substr(trim($t), 0, 400), $textes)));

        $out['diagnostic'] = ['pages'=>$total, 'pages_avec_texte'=>$nonVides, 'textes_distincts'=>$distinctes];

        $mauvais = ($nonVides < max(1, (int)ceil($total * 0.6)))     // trop de pages muettes
                || ($total > 1 && $distinctes <= 1);                 // toutes identiques
        if (!$mauvais) {
            $out['ok'] = true; $out['pages'] = $textes; $out['methode'] = 'smalot-direct';
            return $out;
        }

        // ── Repli : isoler chaque page dans un PDF d'une page, puis la relire ──
        // Coûteux (une passe mPDF par page) mais c'est la seule façon de récupérer
        // un PDF dont la couche texte n'est pas découpable en l'état.
        if ($total > 250) {
            $out['ok'] = true; $out['pages'] = $textes; $out['methode'] = 'smalot-direct (repli refusé : trop de pages)';
            return $out;
        }
        $tmp = sys_get_temp_dir() . '/mbi_pgtxt_' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0777, true);
        $recup = 0;
        try {
            for ($n = 1; $n <= $total; $n++) {
                $unePage = $tmp . '/p' . $n . '.pdf';
                $ex = rhb_extraire_pages($pdfPath, [$n], $unePage);
                if (!$ex['ok']) continue;
                try {
                    $p1 = (new \Smalot\PdfParser\Parser())->parseFile($unePage);
                    $t  = (string)$p1->getText();
                    if (mb_strlen(trim($t)) >= 40) { $textes[$n] = $t; $recup++; }
                } catch (Throwable $e) { /* page illisible : elle restera non attribuée */ }
                @unlink($unePage);
            }
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
        $out['diagnostic']['pages_recuperees_par_repli'] = $recup;
        $out['ok'] = true; $out['pages'] = $textes;
        $out['methode'] = 'repli page-a-page (' . $recup . '/' . $total . ')';
        return $out;
    }
}

if (!function_exists('rhb_empreintes')) {
    /**
     * Empreintes de recherche d'un jeu de salariés, de la plus sûre à la moins sûre.
     *
     * Le NIR (numéro de sécurité sociale) est LE bon identifiant : obligatoire sur
     * chaque bulletin, unique, assez long pour exclure toute collision. Il est
     * comparé sur la suite des CHIFFRES de la page, ce qui rend la reconnaissance
     * insensible à la mise en forme (« 1 70 01 69 286 002 5 » = « 1700169286002 »).
     * On tronque à 13 chiffres — le NIR sans sa clé — pour que les deux écritures se
     * rejoignent quel que soit le côté où la clé manque.
     */
    function rhb_empreintes(array $salaries): array
    {
        $emp = [];
        foreach ($salaries as $s) {
            $nom = rhb_ascii((string)$s['nom']); $prenom = rhb_ascii((string)$s['prenom']);
            $mat = rhb_ascii((string)$s['matricule']);
            $formes = [];
            if ($nom !== '' && $prenom !== '') { $formes[] = "$nom $prenom"; $formes[] = "$prenom $nom"; }

            /* Nom et prénom pris SÉPARÉMENT, en plus de la séquence d'un seul tenant.
               Un bulletin écrit « EMERY  Lola » sur deux colonnes, « Mme Lola EMERY »
               ou « EMERY Eva Lola » : exiger la suite exacte fait manquer tous ces
               cas. On demande donc que les deux jetons soient présents, où qu'ils
               soient. Trois salariés nommés EMERY restent distingués par leur prénom ;
               une page qui les cite tous devient ambiguë, donc non attribuée — ce qui
               est le comportement voulu. */
            $tokNom    = '';
            $tokPrenom = '';
            foreach (explode(' ', $nom) as $t)    { if (mb_strlen($t) >= 3) { $tokNom = $t; break; } }
            foreach (explode(' ', $prenom) as $t) { if (mb_strlen($t) >= 3) { $tokPrenom = $t; break; } }

            $nir = preg_replace('/\D+/', '', (string)($s['num_secu'] ?? '')) ?? '';
            $nir = mb_strlen($nir) >= 13 ? mb_substr($nir, 0, 13) : '';

            $emp[] = [
                's'        => $s,
                'nir'      => $nir,
                'tokNom'   => $tokNom,
                'tokPrenom'=> $tokPrenom,
                // ≥ 3 caractères : recherché librement dans la page.
                'mat'    => (mb_strlen($mat) >= 3 ? $mat : ''),
                // Tout matricule, même « 2 » ou « 14 », reste exploitable s'il est
                // ÉTIQUETÉ (« Matricule : 0014 »). Hors étiquette il se confondrait
                // avec n'importe quel montant — d'où les deux traitements séparés.
                'matEtq' => $mat,
                'formes' => $formes,
            ];
        }
        return $emp;
    }
}

if (!function_exists('rhb_identifier')) {
    /**
     * Quels salariés sont identifiables dans ce texte ?
     *
     * Fonction PARTAGÉE par la détection (découpe) et par le contrôle avant envoi :
     * les deux doivent raisonner exactement pareil, sans quoi un PDF jugé conforme
     * à la découpe pourrait être refusé à l'envoi, ou l'inverse.
     *
     * @return array{nir:array<int,int>, mat:array<int,int>, nom:array<int,int>}
     *         indices dans $emp, dédoublonnés.
     */
    function rhb_identifier(string $brut, array $emp): array
    {
        $txt      = rhb_ascii($brut);
        $chiffres = preg_replace('/\D+/', '', $brut) ?? '';
        $parNir = []; $parMat = []; $parNom = [];

        foreach ($emp as $i => $e) {
            if ($e['nir'] !== '' && str_contains($chiffres, $e['nir'])) $parNir[] = $i;

            if ($e['mat'] !== '' && preg_match('/(?<![A-Z0-9])' . preg_quote($e['mat'], '/') . '(?![A-Z0-9])/', $txt)) {
                $parMat[] = $i;
            } elseif ($e['matEtq'] !== '') {
                // Étiquette + zéros de tête éventuels : « MATRICULE : 0014 » → 14.
                $re = '/(?:MATRICULE|MATRIC|N\s*MATRICULE|IMMATRICULATION)[^A-Z0-9]{0,6}0*'
                    . preg_quote($e['matEtq'], '/') . '(?![0-9])/';
                if (preg_match($re, $txt)) $parMat[] = $i;
            }

            $trouveNom = false;
            foreach ($e['formes'] as $f) {
                if ($f !== '' && str_contains($txt, $f)) { $trouveNom = true; break; }
            }
            // Repli : nom ET prénom présents séparément, chacun en mot entier.
            if (!$trouveNom && $e['tokNom'] !== '' && $e['tokPrenom'] !== '') {
                $mot = fn(string $t): bool => (bool)preg_match('/(?<![A-Z])' . preg_quote($t, '/') . '(?![A-Z])/', $txt);
                if ($mot($e['tokNom']) && $mot($e['tokPrenom'])) $trouveNom = true;
            }
            if ($trouveNom) $parNom[] = $i;
        }
        return [
            'nir' => array_values(array_unique($parNir)),
            'mat' => array_values(array_unique($parMat)),
            'nom' => array_values(array_unique($parNom)),
        ];
    }
}

if (!function_exists('rhb_controle_pdf')) {
    /**
     * CONTRÔLE AVANT ENVOI — le garde-fou qui rend « aucun mélange » vérifiable.
     *
     * Relit le PDF qui va réellement partir et exige DEUX conditions :
     *   1. il porte l'identité du destinataire (NIR, matricule ou nom) ;
     *   2. il ne porte l'identité d'AUCUN autre salarié.
     * Faute de quoi l'envoi est refusé. Ce n'est pas une relecture de l'attribution :
     * c'est une vérification indépendante du fichier final, faite juste avant de
     * le confier au serveur de messagerie.
     *
     * @return array{ok:bool, motif:string, titulaire:bool, autres:array<int,string>}
     */
    function rhb_controle_pdf(string $pdfPath, int $idUser, array $salaries): array
    {
        $out = ['ok'=>false, 'motif'=>'', 'titulaire'=>false, 'autres'=>[]];
        if (!is_file($pdfPath)) { $out['motif'] = 'PDF introuvable.'; return $out; }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists('Smalot\\PdfParser\\Parser')) { $out['motif'] = 'Lecteur PDF indisponible.'; return $out; }

        try {
            $texte = (string)(new \Smalot\PdfParser\Parser())->parseFile($pdfPath)->getText();
        } catch (Throwable $e) { $out['motif'] = 'PDF illisible : ' . $e->getMessage(); return $out; }
        if (mb_strlen(trim($texte)) < 40) { $out['motif'] = 'PDF sans texte exploitable — contrôle impossible.'; return $out; }

        $emp = rhb_empreintes($salaries);
        $vus = rhb_identifier($texte, $emp);

        $indexTitulaire = null;
        foreach ($emp as $i => $e) if ((int)$e['s']['id'] === $idUser) { $indexTitulaire = $i; break; }
        if ($indexTitulaire === null) { $out['motif'] = 'Salarié hors du périmètre analysé.'; return $out; }

        $tous = array_unique(array_merge($vus['nir'], $vus['mat'], $vus['nom']));
        $out['titulaire'] = in_array($indexTitulaire, $tous, true);
        foreach ($tous as $i) {
            if ($i === $indexTitulaire) continue;
            $out['autres'][] = (string)$emp[$i]['s']['complet'];
        }

        if (!$out['titulaire']) {
            $out['motif'] = "Le PDF ne porte pas l'identité du destinataire.";
            return $out;
        }
        if ($out['autres']) {
            $out['motif'] = "Le PDF mentionne aussi : " . implode(', ', $out['autres']) . '.';
            return $out;
        }
        $out['ok'] = true; $out['motif'] = 'Contrôle passé.';
        return $out;
    }
}

if (!function_exists('rhb_pages_par_salarie')) {
    /**
     * Attribue chaque page du PDF d'agence à un salarié.
     *
     * MÉTHODE, du plus sûr au moins sûr :
     *   1. MATRICULE de paie présent sur la page, et un seul → certitude.
     *   2. « NOM PRENOM » ou « PRENOM NOM » présent, et un seul salarié → sûr.
     *   3. Page muette qui SUIT une page attribuée → « suite présumée » : c'est le
     *      cas d'un bulletin sur 2 pages dont la seconde ne réaffiche pas le nom.
     *
     * Toute page où PLUSIEURS salariés sont détectés, ou aucune des règles ci-dessus,
     * est laissée NON ATTRIBUÉE. On ne départage jamais deux candidats : la page part
     * dans « à vérifier » et sera traitée à la main.
     *
     * @return array{ok:bool, error:?string, pages:int,
     *               par_salarie:array<int,array{id_user:int,nom:string,matricule:string,pages:array<int,int>,presume:bool}>,
     *               non_attribuees:array<int,int>, detail:array<int,array>}
     */
    function rhb_pages_par_salarie(string $pdfPath, array $salaries): array
    {
        $out = ['ok'=>false,'error'=>null,'pages'=>0,'par_salarie'=>[],'non_attribuees'=>[],
                'detail'=>[], 'methode'=>'', 'diagnostic'=>[], 'alerte'=>null];
        if (!is_file($pdfPath)) { $out['error'] = 'PDF introuvable sur le serveur.'; return $out; }

        $lecture = rhb_pages_texte($pdfPath);
        if (!$lecture['ok']) { $out['error'] = $lecture['error']; return $out; }
        $textes            = $lecture['pages'];
        $out['pages']      = count($textes);
        $out['methode']    = $lecture['methode'];
        $out['diagnostic'] = $lecture['diagnostic'];
        if ($out['pages'] === 0) { $out['error'] = 'PDF sans page exploitable.'; return $out; }

        // Empreintes partagées avec le contrôle avant envoi (voir rhb_empreintes).
        $emp = rhb_empreintes($salaries);

        /* En-tête qui ouvre un NOUVEAU bulletin. C'est le repère structurant : une
           page qui le porte ne peut pas être la suite de la précédente. Sans lui,
           une chaîne de pages muettes absorbait tout le document dans un seul
           bulletin — le défaut constaté en production. */
        $RE_ENTETE = '/BULLETIN DE (PAIE|SALAIRE|PAYE)|BULLETIN DE PAIEMENT|BULLETIN DE REMUNERATION/';
        /* …sauf quand l'en-tête est explicitement celui d'une CONTINUATION
           (« BULLETIN DE PAIE (suite) »), que plusieurs éditeurs de paie répètent
           en haut de la seconde page. La prendre pour un nouveau bulletin
           détacherait la page de son titulaire. */
        $RE_SUITE = '/BULLETIN DE (?:PAIE|SALAIRE|PAYE)[^A-Z0-9]{0,12}(?:SUITE|CONT)/';
        // Un bulletin dépasse rarement 3 pages : au-delà, l'héritage n'est plus une
        // déduction mais une supposition. On préfère « à vérifier » à une erreur.
        $MAX_SUITE = 3;

        $analyse = [];
        foreach ($textes as $n => $brut) {
            $txt = rhb_ascii($brut);
            $vus = rhb_identifier($brut, $emp);      // mêmes règles qu'au contrôle d'envoi
            $analyse[$n] = [
                'entete'  => (bool)preg_match($RE_ENTETE, $txt) && !preg_match($RE_SUITE, $txt),
                'nir'     => $vus['nir'],
                'mat'     => $vus['mat'],
                'nom'     => $vus['nom'],
                'vide'    => mb_strlen(trim($txt)) < 40,
                'extrait' => mb_substr(trim(preg_replace('/\s+/', ' ', $txt) ?? ''), 0, 70),
            ];
        }

        $attrib = []; $suite = 0; $courant = null;
        foreach ($analyse as $n => $a) {
            // Du plus sûr au moins sûr : NIR, puis matricule, puis nom.
            $candidat = null;
            if (count($a['nir']) === 1)      $candidat = ['i'=>$a['nir'][0], 'via'=>'n° sécu'];
            elseif (count($a['mat']) === 1)  $candidat = ['i'=>$a['mat'][0], 'via'=>'matricule'];
            elseif (count($a['nom']) === 1)  $candidat = ['i'=>$a['nom'][0], 'via'=>'nom'];

            if ($candidat !== null) {
                $attrib[$n] = $candidat + ['presume'=>false];
                $courant = $candidat['i']; $suite = 0;
                continue;
            }
            // Plusieurs candidats : on ne départage JAMAIS.
            if (count($a['nir']) > 1 || count($a['mat']) > 1 || count($a['nom']) > 1) {
                $attrib[$n] = null; $courant = null; $suite = 0; continue;
            }
            /* Aucun candidat. Suite du bulletin précédent seulement si la page ne
               rouvre pas un bulletin et que la chaîne reste courte. */
            if ($courant !== null && !$a['entete'] && $suite < $MAX_SUITE) {
                $attrib[$n] = ['i'=>$courant, 'via'=>'suite', 'presume'=>true];
                $suite++;
            } else {
                $attrib[$n] = null; $courant = null; $suite = 0;
            }
        }

        foreach ($attrib as $n => $a) {
            $out['detail'][$n] = [
                'entete'     => $analyse[$n]['entete'],
                'nir'        => count($analyse[$n]['nir']),
                'matricules' => count($analyse[$n]['mat']),
                'noms'       => count($analyse[$n]['nom']),
                'vide'       => $analyse[$n]['vide'],
                'extrait'    => $analyse[$n]['extrait'],
                'via'        => $a['via'] ?? null,
            ];
            if ($a === null) { $out['non_attribuees'][] = $n; continue; }
            $s = $emp[$a['i']]['s'];
            $uid = (int)$s['id'];
            if (!isset($out['par_salarie'][$uid])) {
                $out['par_salarie'][$uid] = ['id_user'=>$uid, 'nom'=>$s['complet'],
                                             'matricule'=>(string)$s['matricule'], 'pages'=>[], 'presume'=>false];
            }
            $out['par_salarie'][$uid]['pages'][] = $n;
            if (!empty($a['presume'])) $out['par_salarie'][$uid]['presume'] = true;
        }

        /* Garde-fou de vraisemblance : un gros PDF qui n'accoucherait que d'un ou
           deux bulletins signale une extraction de texte défaillante, pas une paie
           à deux salariés. Mieux vaut le dire que livrer un résultat faux. */
        $nbEntetes = 0;
        foreach ($analyse as $a) if ($a['entete']) $nbEntetes++;
        $nbSal = count($out['par_salarie']);
        if ($out['pages'] >= 4 && $nbSal <= 2 && $out['pages'] > $nbSal * 3) {
            $out['alerte'] = "Attribution douteuse : {$out['pages']} pages pour {$nbSal} salarié(s) reconnu(s)"
                           . ($nbEntetes ? " ({$nbEntetes} en-tête(s) de bulletin repéré(s))" : " (aucun en-tête « BULLETIN DE PAIE » repéré)")
                           . '. Vérifiez le détail par page avant de classer.';
        }
        if ($nbEntetes > 0 && $nbSal > 0 && $nbEntetes > $nbSal * 2) {
            $out['alerte'] = "{$nbEntetes} bulletins semblent présents mais {$nbSal} salarié(s) seulement ont été reconnus."
                           . ' Les noms du PDF ne correspondent probablement pas à ceux de la fiche salarié.';
        }
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('rhb_extraire_pages')) {
    /**
     * Écrit un PDF ne contenant que $pages (numéros 1-indexés) de $src.
     * Passe par l'API d'import de mPDF (lecteur FPDI embarqué) : ni Poppler ni
     * shell_exec, absents de l'hébergement mutualisé.
     */
    function rhb_extraire_pages(string $src, array $pages, string $dest): array
    {
        $out = ['ok'=>false, 'error'=>null, 'pages'=>0];
        if (!is_file($src))  { $out['error'] = 'Source introuvable.'; return $out; }
        if (!$pages)         { $out['error'] = 'Aucune page à extraire.'; return $out; }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists('Mpdf\\Mpdf')) { $out['error'] = 'mPDF indisponible.'; return $out; }

        $tmp = sys_get_temp_dir() . '/mbi_mpdf';
        if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
        try {
            $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmp]);
            $total = $mpdf->SetSourceFile($src);
            sort($pages);
            foreach ($pages as $n) {
                $n = (int)$n;
                if ($n < 1 || $n > $total) continue;
                $tpl = $mpdf->ImportPage($n);
                $mpdf->AddPage();
                $mpdf->UseTemplate($tpl);
                $out['pages']++;
            }
            if ($out['pages'] === 0) { $out['error'] = 'Aucune page valide.'; return $out; }
            $mpdf->Output($dest, \Mpdf\Output\Destination::FILE);
            $out['ok'] = is_file($dest) && filesize($dest) > 300;
            if (!$out['ok']) $out['error'] = 'PDF produit vide.';
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}
