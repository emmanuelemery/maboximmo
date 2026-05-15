<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo          = $GLOBALS['pdo'];
$roleId       = current_role_id();
$isSuperAdmin = function_exists('is_super_admin') ? is_super_admin() : false;

// Super Admin peut consulter/modifier n'importe quelle société via ?sa_id=X
$saOverride = $isSuperAdmin ? (int)($_GET['sa_id'] ?? 0) : 0;

if ($saOverride > 0) {
    // Vérifier que la société existe
    $chk = $pdo->prepare("SELECT id FROM societes WHERE id = ? LIMIT 1");
    $chk->execute([$saOverride]);
    if (!$chk->fetchColumn()) {
        http_response_code(404);
        exit('Société introuvable.');
    }
    $societeId = $saOverride;
} else {
    if ($roleId !== 1 && $roleId !== 2) {
        http_response_code(403);
        exit('Accès réservé aux administrateurs et managers.');
    }
    $societeId = current_societe_id();
    if (!$societeId) {
        http_response_code(400);
        exit('Aucune société associée à votre compte.');
    }
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors  = [];
$success = '';

// Message flash après Post-Redirect-Get d'un upload réussi
if (!empty($_GET['uploaded'])) {
    $success = 'Document ajouté.';
}

// ── Charger les données actuelles ──────────────────────────────
$soc = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
    $stmt->execute([$societeId]);
    $soc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $errors[] = 'Impossible de charger les données : ' . $e->getMessage();
}

// Décoder les activités carte T (JSON → tableau)
$carteTActivites = [];
if (!empty($soc['carte_t_activites'])) {
    $decoded = json_decode((string)$soc['carte_t_activites'], true);
    if (is_array($decoded)) $carteTActivites = $decoded;
}
if (empty($carteTActivites)) {
    // Valeurs par défaut
    $carteTActivites = ['transaction', 'gestion', 'syndic'];
}

// ── Charger toutes les sociétés (pour le sélecteur super-admin) ──
$allSocietes = [];
if ($isSuperAdmin) {
    try {
        $stmtAll = $pdo->query("SELECT id, nom FROM societes ORDER BY nom ASC");
        $allSocietes = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* ignoré */ }
}

// ── Charger les agences de la société ─────────────────────────
$agences = [];
try {
    $stmtAg = $pdo->prepare("SELECT * FROM agences WHERE id_societe = ? ORDER BY ordre_affichage ASC, nom_agence ASC");
    $stmtAg->execute([$societeId]);
    $agences = $stmtAg->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// ── Charger les documents liés à la société ────────────────────
$docs = [];
try {
    $stmtDocs = $pdo->prepare("
        SELECT * FROM documents
        WHERE id_societe = ? AND id_agence IS NULL AND id_user IS NULL
        ORDER BY categorie_document, date_creation DESC
    ");
    $stmtDocs->execute([$societeId]);
    $docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// ── Charger les couvertures (attestations RCP + garanties financières) ──
// On ne charge que les versions ACTIVES pour l'affichage principal. Les
// anciennes versions (renouvellements archivés) sont disponibles via la
// requête $couvHistorique plus bas pour le panneau "Historique" déroulant.
$couvertures = [];
$couvHistorique = []; // [type][activite] = array de versions archivées
try {
    // Versions actives
    $stmtCov = $pdo->prepare("
        SELECT c.*, d.titre AS doc_titre, d.nom_fichier AS doc_nom
        FROM societes_couvertures c
        LEFT JOIN documents d ON d.id = c.id_document_source
        WHERE c.id_societe = ? AND c.est_active = 1
        ORDER BY c.type, FIELD(c.activite,'transaction','gestion','syndic','location','neuf','multi')
    ");
    $stmtCov->execute([$societeId]);
    $couvertures = $stmtCov->fetchAll(PDO::FETCH_ASSOC);

    // Versions archivées (historique de renouvellement)
    $stmtHist = $pdo->prepare("
        SELECT c.*, d.titre AS doc_titre, d.nom_fichier AS doc_nom
        FROM societes_couvertures c
        LEFT JOIN documents d ON d.id = c.id_document_source
        WHERE c.id_societe = ? AND c.est_active = 0
        ORDER BY c.type, c.activite, c.version_num DESC
    ");
    $stmtHist->execute([$societeId]);
    foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $couvHistorique[$h['type']][$h['activite']][] = $h;
    }
} catch (PDOException $e) { /* table pas encore migrée */ }

// Indexer par (type → activite) pour accès rapide dans le rendu
$couvByType = ['rcp' => [], 'garantie_financiere' => []];
foreach ($couvertures as $c) {
    $couvByType[$c['type']][$c['activite']] = $c;
}

// ── Grouper les docs par catégorie ────────────────────────────
$docsByCat = [];
foreach ($docs as $doc) {
    $cat = $doc['categorie_document'] ?: 'autre';
    $docsByCat[$cat][] = $doc;
}

// ── Traitement POST ────────────────────────────────────────────
if (is_post()) {
    verify_csrf('rh_societe');
    $tab = trim((string)post('active_tab', 'juridique'));

    // Détection AJAX : header X-Requested-With OU paramètre ajax=1
    // En mode AJAX, les handlers retournent un JSON court au lieu de
    // continuer le rendu HTML complet de la page.
    $isAjax = (!empty($_POST['ajax']))
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

    $ajaxRespond = static function (array $payload) use ($isAjax): void {
        if (!$isAjax) return;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (post('action') === 'update_societe') {

        $fields = [
            // Identité
            'nom'                 => trim((string)post('nom', '')),
            'raison_sociale'      => trim((string)post('raison_sociale', '')),
            'forme_juridique'     => trim((string)post('forme_juridique', '')),
            'capital_social'      => post('capital_social', '') !== '' ? (float)post('capital_social') : null,
            // Immatriculation
            'siret'               => trim((string)post('siret', '')),
            'siren'               => trim((string)post('siren', '')),
            'tva_intracom'        => trim((string)post('tva_intracom', '')),
            'code_ape'            => trim((string)post('code_ape', '6831Z')) ?: '6831Z',
            'convention_collective' => trim((string)post('convention_collective', 'IMMOBILIER')) ?: 'IMMOBILIER',
            // Carte pro
            'numero_carte_t'           => trim((string)post('numero_carte_t', '')),
            'cci_carte_t'              => trim((string)post('cci_carte_t', '')),
            'carte_t_activites'        => json_encode(array_values(array_filter((array)($_POST['carte_t_activites'] ?? []), fn($v) => is_string($v) && $v !== ''))),
            'carte_t_date_expiration'  => trim((string)post('carte_t_date_expiration', '')) !== '' ? trim((string)post('carte_t_date_expiration', '')) : null,
            // Finance & garanties → désormais gérées via societes_couvertures
            // (alimentée par l'analyse IA des documents, cf. onglet Documents).
            // Les anciennes colonnes `garantie_financiere` et `assurance_rcp`
            // de la table `societes` ne sont plus mises à jour via le form.
            // Coordonnées
            'adresse_1'           => trim((string)post('adresse_1', '')),
            'adresse_2'           => trim((string)post('adresse_2', '')),
            'code_postal'         => trim((string)post('code_postal', '')),
            'ville'               => trim((string)post('ville', '')),
            'pays'                => trim((string)post('pays', 'France')),
            'telephone'           => trim((string)post('telephone', '')),
            'email'               => trim((string)post('email', '')),
            'comptable_email'     => trim((string)post('comptable_email', '')),
            'comptable_nom'       => trim((string)post('comptable_nom', '')),
            'comptable_telephone' => trim((string)post('comptable_telephone', '')),
            'comptable_societe'   => trim((string)post('comptable_societe', '')),
            'site_web'            => trim((string)post('site_web', '')),
            // Réseaux sociaux
            'facebook_url'        => trim((string)post('facebook_url', '')),
            'instagram_url'       => trim((string)post('instagram_url', '')),
            'linkedin_url'        => trim((string)post('linkedin_url', '')),
            'youtube_url'         => trim((string)post('youtube_url', '')),
            'google_business_url' => trim((string)post('google_business_url', '')),
            // Contenu
            'description'         => trim((string)post('description', '')),
            'mention_legale'      => trim((string)post('mention_legale', '')),
            // Modules activés (admin/super-admin uniquement)
            'module_rh'           => isset($_POST['module_rh']) ? 1 : 0,
            'module_agency'       => isset($_POST['module_agency']) ? 1 : 0,
            'module_syndic'       => isset($_POST['module_syndic']) ? 1 : 0,
            'module_gestion'      => isset($_POST['module_gestion']) ? 1 : 0,
            'module_bailleur'     => isset($_POST['module_bailleur']) ? 1 : 0,
        ];

        if ($fields['nom'] === '') {
            $errors[] = 'Le nom de la société est obligatoire.';
        }

        if (empty($errors)) {
            try {
                $setClauses = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($fields)));
                $stmt = $pdo->prepare("UPDATE societes SET $setClauses WHERE id = :id");
                $fields['id'] = $societeId;
                $stmt->execute($fields);

                // Recharger
                $stmt2 = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
                $stmt2->execute([$societeId]);
                $soc = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
                $decoded2 = json_decode((string)($soc['carte_t_activites'] ?? '[]'), true);
                $carteTActivites = is_array($decoded2) ? $decoded2 : ['transaction', 'gestion', 'syndic'];

                // Hook glossaire GED : sync label société (et crée si absent)
                try {
                    if (file_exists(__DIR__ . '/inc/ged_glossary.php') && !empty($soc['nom'])) {
                        require_once __DIR__ . '/inc/ged_glossary.php';
                        ged_glossary_sync_entity('societe', $societeId, (string)$soc['nom'], 'societes', [], $pdo);
                    }
                } catch (Throwable) {}

                $success = 'Informations enregistrées.';
                $ajaxRespond(['ok' => true, 'message' => $success, 'scope' => 'societe']);
            } catch (PDOException $e) {
                $errors[] = 'Erreur SQL : ' . $e->getMessage();
                $ajaxRespond(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else {
            $ajaxRespond(['ok' => false, 'error' => implode(' · ', $errors)]);
        }
    }

    // ── Upload document ──────────────────────────────────────
    if (post('action') === 'upload_doc' && isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $tab = 'documents';
        $file     = $_FILES['doc_file'];
        $catDoc   = trim((string)post('doc_categorie', 'autre'));
        $titreDoc = trim((string)post('doc_titre', ''));
        $descDoc  = trim((string)post('doc_description', ''));
        $dateExp  = trim((string)post('doc_expiration', ''));

        $allowedMimes = [
            'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $maxSize  = 15 * 1024 * 1024; // 15 Mo

        if (!in_array($mimeType, $allowedMimes, true)) {
            $errors[] = 'Type de fichier non autorisé. PDF, JPG, PNG, WEBP, DOC, DOCX uniquement.';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'Fichier trop volumineux (max 15 Mo).';
        } else {
            // ─── Dédup par hash MD5 ─────────────────────────────────
            // Refuse un upload si exactement le même fichier (même MD5) a déjà
            // été uploadé sur cette société dans la même catégorie. Évite les
            // doublons causés par F5/refresh/double-clic.
            $md5 = @md5_file($file['tmp_name']) ?: '';
            $duplicateFound = false;
            if ($md5 !== '') {
                try {
                    // On récupère les docs société et on compare leur MD5 sur disque.
                    // Pas idéal sur de gros volumes mais suffisant ici (petite vol.).
                    $stmtDup = $pdo->prepare("
                        SELECT id, chemin_fichier, taille_octets
                        FROM documents
                        WHERE id_societe = :soc
                          AND id_agence IS NULL AND id_user IS NULL
                          AND taille_octets = :size
                          AND (categorie_document = :cat OR (:cat IS NULL AND categorie_document IS NULL))
                    ");
                    $stmtDup->execute([
                        ':soc'  => $societeId,
                        ':size' => (int)$file['size'],
                        ':cat'  => $catDoc !== '' ? $catDoc : null,
                    ]);
                    foreach ($stmtDup->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                        $candAbs = __DIR__ . '/' . ltrim((string)$cand['chemin_fichier'], '/');
                        if (is_file($candAbs) && md5_file($candAbs) === $md5) {
                            $duplicateFound = true;
                            $errors[] = 'Ce document est déjà présent (doublon détecté par empreinte MD5 — id=' . (int)$cand['id'] . ').';
                            break;
                        }
                    }
                } catch (Throwable) { /* tolérant */ }
            }

            if (!$duplicateFound) {
                $uploadDir = __DIR__ . '/uploads/societes/' . $societeId . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $safeName = time() . '_' . preg_replace('/[^a-z0-9_\-\.]/i', '_', basename($file['name']));
                $destPath = $uploadDir . $safeName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    try {
                        $stmtDoc = $pdo->prepare("
                            INSERT INTO documents
                                (id_societe, type_document, categorie_document, nom_fichier, chemin_fichier,
                                 mime_type, taille_octets, titre, description, date_expiration)
                            VALUES
                                (:id_societe, 'societe', :categorie, :nom_fichier, :chemin,
                                 :mime, :taille, :titre, :desc, :expiration)
                        ");
                        $stmtDoc->execute([
                            ':id_societe' => $societeId,
                            ':categorie'  => $catDoc !== '' ? $catDoc : null,
                            ':nom_fichier'=> $safeName,
                            ':chemin'     => 'uploads/societes/' . $societeId . '/' . $safeName,
                            ':mime'       => $mimeType,
                            ':taille'     => $file['size'],
                            ':titre'      => $titreDoc !== '' ? $titreDoc : basename($file['name']),
                            ':desc'       => $descDoc !== '' ? $descDoc : null,
                            ':expiration' => $dateExp !== '' ? $dateExp : null,
                        ]);
                        $success = 'Document ajouté.';

                        // ── Post-Redirect-Get : évite que F5 re-soumette le POST
                        // et crée un doublon. On redirige vers la même page avec
                        // un flag en query-string pour afficher le message flash.
                        $qs = [
                            'tab' => 'documents',
                            'uploaded' => '1',
                        ];
                        if ($isSuperAdmin && $societeId !== current_societe_id()) {
                            $qs['sa_id'] = $societeId;
                        }
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($qs));
                        exit;
                    } catch (PDOException $e) {
                        $errors[] = 'Erreur enregistrement document : ' . $e->getMessage();
                        @unlink($destPath);
                    }
                } else {
                    $errors[] = 'Impossible de déplacer le fichier uploadé.';
                }
            }
        }
    }

    // ── Créer une agence ──────────────────────────────────────
    if (post('action') === 'create_agence') {
        $tab = 'agences';
        $nomAg = trim((string)post('ag_nom_agence', ''));
        if ($nomAg === '') {
            $errors[] = 'Le nom de l\'agence est obligatoire.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO agences
                        (id_societe, nom_agence, nom_commercial, code_agence, type_agence, type_etab,
                         transaction_active, location_active, gestion_active, syndic_active, neuf_active,
                         adresse_1, adresse_2, code_postal, ville, pays,
                         telephone, email, email_contact, email_facturation, site_web,
                         siret, siren_siret, rcs, tva_intracom,
                         iban, bic, banque_nom, titulaire_compte,
                         actif, ordre_affichage)
                    VALUES
                        (:id_societe, :nom_agence, :nom_commercial, :code_agence, :type_agence, :type_etab,
                         :transaction_active, :location_active, :gestion_active, :syndic_active, :neuf_active,
                         :adresse_1, :adresse_2, :code_postal, :ville, :pays,
                         :telephone, :email, :email_contact, :email_facturation, :site_web,
                         :siret, :siren_siret, :rcs, :tva_intracom,
                         :iban, :bic, :banque_nom, :titulaire_compte,
                         1, 0)
                ")->execute([
                    ':id_societe'          => $societeId,
                    ':nom_agence'          => $nomAg,
                    ':nom_commercial'      => trim((string)post('ag_nom_commercial', '')) ?: null,
                    ':code_agence'         => trim((string)post('ag_code_agence', '')) ?: null,
                    ':type_agence'         => trim((string)post('ag_type_agence', '')) ?: null,
                    ':type_etab'           => in_array(post('ag_type_etab'), ['principal','secondaire'], true) ? post('ag_type_etab') : 'secondaire',
                    ':transaction_active'  => post('ag_transaction_active') ? 1 : 0,
                    ':location_active'     => post('ag_location_active') ? 1 : 0,
                    ':gestion_active'      => post('ag_gestion_active') ? 1 : 0,
                    ':syndic_active'       => post('ag_syndic_active') ? 1 : 0,
                    ':neuf_active'         => post('ag_neuf_active') ? 1 : 0,
                    ':adresse_1'           => trim((string)post('ag_adresse_1', '')) ?: null,
                    ':adresse_2'           => trim((string)post('ag_adresse_2', '')) ?: null,
                    ':code_postal'         => trim((string)post('ag_code_postal', '')) ?: null,
                    ':ville'               => trim((string)post('ag_ville', '')) ?: null,
                    ':pays'                => trim((string)post('ag_pays', 'France')) ?: 'France',
                    ':telephone'           => trim((string)post('ag_telephone', '')) ?: null,
                    ':email'               => trim((string)post('ag_email', '')) ?: null,
                    ':email_contact'       => trim((string)post('ag_email_contact', '')) ?: null,
                    ':email_facturation'   => trim((string)post('ag_email_facturation', '')) ?: null,
                    ':site_web'            => trim((string)post('ag_site_web', '')) ?: null,
                    ':siret'               => trim((string)post('ag_siret', '')) ?: '',
                    ':siren_siret'         => trim((string)post('ag_siren_siret', '')) ?: null,
                    ':rcs'                 => trim((string)post('ag_rcs', '')) ?: null,
                    ':tva_intracom'        => trim((string)post('ag_tva_intracom', '')) ?: null,
                    ':iban'                => trim((string)post('ag_iban', '')) ?: null,
                    ':bic'                 => trim((string)post('ag_bic', '')) ?: null,
                    ':banque_nom'          => trim((string)post('ag_banque_nom', '')) ?: null,
                    ':titulaire_compte'    => trim((string)post('ag_titulaire_compte', '')) ?: null,
                ]);
                $newAgId = (int)$pdo->lastInsertId();
                $success = 'Agence créée.';

                // Hook glossaire GED : crée/sync le code glossaire pour cette agence
                try {
                    if (file_exists(__DIR__ . '/inc/ged_glossary.php')) {
                        require_once __DIR__ . '/inc/ged_glossary.php';
                        ged_glossary_sync_entity('agence', $newAgId, $nomAg, 'agences', [
                            'code_existant' => trim((string)post('ag_code_agence', '')),
                        ], $pdo);
                    }
                } catch (Throwable) { /* hook non bloquant */ }

                // Recharger
                $stmtAg->execute([$societeId]);
                $agences = $stmtAg->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $errors[] = 'Erreur création agence : ' . $e->getMessage();
            }
        }
    }

    // ── Modifier une agence ───────────────────────────────────
    if (post('action') === 'update_agence') {
        $tab   = 'agences';
        $agId  = (int)post('ag_id', 0);
        if ($agId > 0) {
            try {
                $pdo->prepare("
                    UPDATE agences SET
                        nom_agence          = :nom_agence,
                        nom_commercial      = :nom_commercial,
                        code_agence         = :code_agence,
                        type_agence         = :type_agence,
                        type_etab           = :type_etab,
                        transaction_active  = :transaction_active,
                        location_active     = :location_active,
                        gestion_active      = :gestion_active,
                        syndic_active       = :syndic_active,
                        neuf_active         = :neuf_active,
                        adresse_1           = :adresse_1,
                        adresse_2           = :adresse_2,
                        code_postal         = :code_postal,
                        ville               = :ville,
                        pays                = :pays,
                        telephone           = :telephone,
                        email               = :email,
                        email_contact       = :email_contact,
                        email_facturation   = :email_facturation,
                        site_web            = :site_web,
                        siret               = :siret,
                        siren_siret         = :siren_siret,
                        rcs                 = :rcs,
                        tva_intracom        = :tva_intracom,
                        iban                = :iban,
                        bic                 = :bic,
                        banque_nom          = :banque_nom,
                        titulaire_compte    = :titulaire_compte,
                        actif               = :actif
                    WHERE id = :id AND id_societe = :id_societe
                ")->execute([
                    ':nom_agence'          => trim((string)post('ag_nom_agence', '')),
                    ':nom_commercial'      => trim((string)post('ag_nom_commercial', '')) ?: null,
                    ':code_agence'         => trim((string)post('ag_code_agence', '')) ?: null,
                    ':type_agence'         => trim((string)post('ag_type_agence', '')) ?: null,
                    ':type_etab'           => in_array(post('ag_type_etab'), ['principal','secondaire'], true) ? post('ag_type_etab') : 'secondaire',
                    ':transaction_active'  => post('ag_transaction_active') ? 1 : 0,
                    ':location_active'     => post('ag_location_active') ? 1 : 0,
                    ':gestion_active'      => post('ag_gestion_active') ? 1 : 0,
                    ':syndic_active'       => post('ag_syndic_active') ? 1 : 0,
                    ':neuf_active'         => post('ag_neuf_active') ? 1 : 0,
                    ':adresse_1'           => trim((string)post('ag_adresse_1', '')) ?: null,
                    ':adresse_2'           => trim((string)post('ag_adresse_2', '')) ?: null,
                    ':code_postal'         => trim((string)post('ag_code_postal', '')) ?: null,
                    ':ville'               => trim((string)post('ag_ville', '')) ?: null,
                    ':pays'                => trim((string)post('ag_pays', 'France')) ?: 'France',
                    ':telephone'           => trim((string)post('ag_telephone', '')) ?: null,
                    ':email'               => trim((string)post('ag_email', '')) ?: null,
                    ':email_contact'       => trim((string)post('ag_email_contact', '')) ?: null,
                    ':email_facturation'   => trim((string)post('ag_email_facturation', '')) ?: null,
                    ':site_web'            => trim((string)post('ag_site_web', '')) ?: null,
                    ':siret'               => trim((string)post('ag_siret', '')) ?: '',
                    ':siren_siret'         => trim((string)post('ag_siren_siret', '')) ?: null,
                    ':rcs'                 => trim((string)post('ag_rcs', '')) ?: null,
                    ':tva_intracom'        => trim((string)post('ag_tva_intracom', '')) ?: null,
                    ':iban'                => trim((string)post('ag_iban', '')) ?: null,
                    ':bic'                 => trim((string)post('ag_bic', '')) ?: null,
                    ':banque_nom'          => trim((string)post('ag_banque_nom', '')) ?: null,
                    ':titulaire_compte'    => trim((string)post('ag_titulaire_compte', '')) ?: null,
                    ':actif'               => post('ag_actif') ? 1 : 0,
                    ':id'                  => $agId,
                    ':id_societe'          => $societeId,
                ]);
                $success = 'Agence mise à jour.';

                // Hook glossaire GED : sync label (et crée si pas encore présent)
                try {
                    if (file_exists(__DIR__ . '/inc/ged_glossary.php')) {
                        require_once __DIR__ . '/inc/ged_glossary.php';
                        ged_glossary_sync_entity('agence', $agId, trim((string)post('ag_nom_agence', '')), 'agences', [
                            'code_existant' => trim((string)post('ag_code_agence', '')),
                        ], $pdo);
                    }
                } catch (Throwable) {}

                // Recharger
                $stmtAg->execute([$societeId]);
                $agences = $stmtAg->fetchAll(PDO::FETCH_ASSOC);
                $ajaxRespond(['ok' => true, 'message' => $success, 'scope' => 'agence', 'ag_id' => $agId]);
            } catch (PDOException $e) {
                $errors[] = 'Erreur mise à jour agence : ' . $e->getMessage();
                $ajaxRespond(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else {
            $ajaxRespond(['ok' => false, 'error' => 'ag_id manquant']);
        }
    }

    // ── Sauvegarde du commentaire d'analyse d'un document ─────
    if (post('action') === 'save_doc_comment') {
        $docId   = (int)post('doc_id', 0);
        $comment = trim((string)post('comment', ''));
        if ($docId > 0) {
            try {
                $pdo->prepare("
                    UPDATE documents
                    SET analysis_comment = :c
                    WHERE id = :id AND id_societe = :soc AND type_document = 'societe'
                ")->execute([
                    ':c'   => $comment !== '' ? $comment : null,
                    ':id'  => $docId,
                    ':soc' => $societeId,
                ]);
            } catch (Throwable) { /* silencieux, migration peut manquer */ }
        }
        // Réponse minimale (souvent appelé en AJAX)
        $ajaxRespond(['ok' => true, 'scope' => 'doc_comment', 'doc_id' => $docId]);
    }

    // ── Suppression document ─────────────────────────────────
    if (post('action') === 'delete_doc') {
        $tab   = 'documents';
        $docId = (int)post('doc_id', 0);
        if ($docId > 0) {
            try {
                $stmtFetch = $pdo->prepare("SELECT chemin_fichier FROM documents WHERE id = ? AND id_societe = ? LIMIT 1");
                $stmtFetch->execute([$docId, $societeId]);
                $docRow = $stmtFetch->fetch(PDO::FETCH_ASSOC);
                if ($docRow) {
                    $filePath = __DIR__ . '/' . $docRow['chemin_fichier'];
                    if (file_exists($filePath)) @unlink($filePath);
                    $pdo->prepare("DELETE FROM documents WHERE id = ? AND id_societe = ?")->execute([$docId, $societeId]);
                    $success = 'Document supprimé.';
                    // Recharger
                    $stmtDocs2 = $pdo->prepare("SELECT * FROM documents WHERE id_societe = ? AND id_agence IS NULL AND id_user IS NULL ORDER BY categorie_document, date_creation DESC");
                    $stmtDocs2->execute([$societeId]);
                    $docs = $stmtDocs2->fetchAll(PDO::FETCH_ASSOC);
                    $docsByCat = [];
                    foreach ($docs as $doc) { $docsByCat[$doc['categorie_document'] ?: 'autre'][] = $doc; }
                }
            } catch (PDOException $e) {
                $errors[] = 'Erreur suppression : ' . $e->getMessage();
            }
        }
    }
}

$activeTab = trim((string)post('active_tab', $_GET['tab'] ?? 'juridique'));
$validTabs = ['juridique', 'financier', 'metier', 'coordonnees', 'marque', 'documents', 'agences'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'juridique';

// Après auto-save d'agence, ag_goto indique quelle agence afficher
$agGoto = trim((string)post('ag_goto', ''));

// Labels catégories docs
$docCatLabels = [
    'kbis'           => 'Kbis',
    'statuts'        => 'Statuts',
    'carte_t'        => 'Carte T',
    'garantie'       => 'Garantie financière',
    'assurance_rcp'  => 'Assurance RCP',
    'rib'            => 'RIB / IBAN',
    'contrat'        => 'Contrats',
    'administratif'  => 'Documents administratifs',
    'autre'          => 'Autres documents',
];

$pageTitle = 'Fiche société';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?> — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/tokens.css">
  <link rel="stylesheet" href="css/base.css">
  <link rel="stylesheet" href="css/components.css">
  <link rel="stylesheet" href="css/layout.css">
  <link rel="stylesheet" href="css/theme-rh.css">
  <style>
    /* ════════════════════════════════════════
       BASE
    ════════════════════════════════════════ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg-secondary);
      color: #1a1816;
      display: flex; min-height: 100vh;
      font-size: 14px; line-height: 1.6;
    }
    a { color: inherit; text-decoration: none; }

    /* ── MAIN ── */
    .rs-main {
      margin-left: 220px; flex: 1;
      display: flex; flex-direction: column;
      height: 100vh; overflow: hidden;
    }

    /* ── TOPBAR ── */
    .rs-topbar {
      background: var(--bg-primary);
      box-shadow: 0 4px 12px rgba(196,192,186,0.45);
      padding: 0 24px;
      height: 56px;
      display: flex; align-items: center; gap: 10px;
      position: sticky; top: 0; z-index: 100; flex-shrink: 0;
    }
    .topbar-back {
      width: 34px; height: 34px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; border: none; flex-shrink: 0;
    }
    .topbar-back:active { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .topbar-back svg { width:15px; height:15px; stroke:#9aaa84; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .topbar-breadcrumb {
      display: flex; align-items: center; gap: 8px;
      font-family: 'DM Mono', monospace; font-size: 13px;
      letter-spacing: 0.06em; color: #8a8680; margin-left: 12px;
    }
    .topbar-breadcrumb a { color: #8a8680; text-decoration: none; }
    .topbar-breadcrumb a:hover { color: #4a6038; }
    .topbar-breadcrumb .active { color: #36577d; font-weight: 600; font-size: 14px; }
    .topbar-sep { color: #c8c4be; font-size: 16px; }
    .topbar-spacer { flex: 1; }
    .topbar-notif {
      position: relative; width: 36px; height: 36px; border-radius: 10px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; border: none; flex-shrink: 0;
    }
    .topbar-notif svg { width:16px; height:16px; stroke:#8a8680; fill:none; stroke-width:1.6; }
    .topbar-notif-dot {
      position: absolute; top: 6px; right: 6px;
      width: 7px; height: 7px; border-radius: 50%;
      background: #cc5c58; border: 2px solid var(--bg-primary);
    }
    .topbar-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: #36577d;
      display: flex; align-items: center; justify-content: center;
      font-family: 'DM Mono', monospace; font-size: 10px;
      color: #fff; font-weight: 500;
      box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      flex-shrink: 0;
    }
    .rs-topbar-badge {
      margin-left: 8px; padding: 3px 10px; border-radius: 999px;
      font-size: 11px; font-weight: 700;
      background: rgba(232,121,249,.2); color: #e879f9;
      border: 1px solid rgba(232,121,249,.4);
    }

    /* ── BARRE ONGLETS ── */
    .rs-tabs-wrap {
      background: var(--bg-primary);
      box-shadow: 0 2px 8px rgba(196,192,186,0.35);
      padding: 0 24px;
      display: flex; flex-direction: row;
      overflow-x: auto; scrollbar-width: none;
      flex-shrink: 0; z-index: 49;
    }
    .rs-tabs-wrap::-webkit-scrollbar { display: none; }
    /* alias pour l'ancien sélecteur id */
    .rs-tabs { display: contents; }

    .rs-tab {
      display: flex; align-items: center; gap: 6px;
      padding: 14px 16px;
      border: none; border-bottom: 2.5px solid transparent;
      background: none; cursor: pointer; white-space: nowrap;
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
      letter-spacing: 0.06em; color: #8a8680;
      transition: color .18s, border-color .18s;
    }
    .rs-tab:hover { color: #36577d; }
    .rs-tab.active { color: #36577d; border-bottom-color: #36577d; }
    .rs-tab .tab-dot {
      width: 6px; height: 6px; border-radius: 50%;
      background: transparent; transition: background .18s;
    }
    .rs-tab.done .tab-dot { background: #7a9060; }
    .rs-tab-count {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 18px; height: 18px; padding: 0 5px;
      border-radius: 999px; font-size: 10px; font-weight: 700;
      background: #36577d; color: var(--bg-primary); margin-left: 4px;
    }

    /* ── SCROLL ZONE ── */
    .rs-scroll {
      flex: 1; overflow-y: auto; overflow-x: hidden;
      padding: 0 24px 40px;
    }

    /* ── AGENCES SUB-NAV ── */
    .ag-subnav {
      display: flex; flex-wrap: wrap; gap: 8px;
      padding: 20px 0 16px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 24px;
    }
    .ag-subtab {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 20px;
      border: 1.5px solid var(--border);
      background: transparent; color: var(--muted);
      font-size: 13px; font-weight: 500; cursor: pointer;
      transition: all .15s;
    }
    .ag-subtab:hover { background: rgba(54,87,125,0.08); color: #36577d; border-color: rgba(54,87,125,0.3); }
    .ag-subtab.active {
      background: #36577d; border-color: #36577d;
      color: var(--bg-primary); font-weight: 600;
      box-shadow: 4px 4px 10px rgba(54,87,125,0.35), -2px -2px 5px var(--shadow-light);
    }
    .ag-subtab-dot {
      width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
    }
    .dot-actif   { background: #7a9060; }
    .dot-inactif { background: #c8c4be; }
    .ag-subtab-badge {
      font-family: 'DM Mono', monospace;
      font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
      padding: 1px 6px; border-radius: 4px;
      background: rgba(255,255,255,.25);
    }
    .ag-subtab-new {
      border-style: dashed;
      color: #7a9060; border-color: rgba(122,144,96,0.5);
    }
    .ag-subtab-new:hover { background: rgba(122,144,96,0.1); border-color: #7a9060; color: #4a6038; }
    .ag-subtab-new.active { background: #7a9060; border-color: #7a9060; color: #fff; }

    /* ── AGENCES PANELS ── */
    .ag-panel { display: none; }
    .ag-panel.active { display: block; }

    .ag-form-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 20px;
    }
    .ag-form-title { font-size: 16px; font-weight: 700; color: #1a1816; font-family: 'Sora', sans-serif; }
    .ag-form-sub   { font-size: 12px; color: #8a8680; margin-top: 2px; font-family: 'DM Mono', monospace; }

    .ag-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    @media (max-width: 700px) { .ag-grid { grid-template-columns: 1fr; } }
    .ag-card { margin: 0 !important; }

    /* Activités chips */
    .ag-activites { display: flex; flex-wrap: wrap; gap: 8px; }
    .ag-act-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px; border-radius: 999px; cursor: pointer;
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
      letter-spacing: 0.04em;
      background: var(--bg-primary);
      box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      color: #8a8680; border: none;
      transition: all .15s;
    }
    .ag-act-chip input { display: none; }
    .ag-act-chip.checked {
      background: rgba(122,144,96,0.15);
      box-shadow: inset 2px 2px 5px rgba(122,144,96,0.2), inset -2px -2px 5px rgba(255,255,255,0.8);
      color: #4a6038; font-weight: 600;
    }

    /* Toggle actif */
    .ag-toggle { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
    .ag-toggle input { display: none; }
    .ag-toggle-track {
      width: 40px; height: 22px; border-radius: 11px;
      background: var(--bg-primary);
      box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      transition: background .2s; position: relative;
    }
    .ag-toggle-track::after {
      content: ''; position: absolute; top: 3px; left: 3px;
      width: 16px; height: 16px; border-radius: 50%;
      background: #a8a49e; transition: transform .2s, background .2s;
      box-shadow: 2px 2px 4px var(--shadow-dark);
    }
    .ag-toggle input:checked ~ .ag-toggle-track::after { transform: translateX(18px); background: #7a9060; }
    .ag-toggle-label { font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 600; color: #8a8680; }
    .ag-toggle input:checked ~ .ag-toggle-label { color: #4a6038; }

    /* ── CONTENT ── */
    .rs-content { padding: 24px 0; max-width: 900px; margin: 0 auto; }

    /* ── PANEL ── */
    .rs-panel { display: none; }
    .rs-panel.active { display: block; }

    /* ── SECTION CARD ── */
    .rs-card {
      background: var(--bg-primary);
      border-radius: 20px;
      box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
      padding: 24px 28px;
      margin-bottom: 20px;
    }

    .rs-card-title {
      font-family: 'Sora', sans-serif;
      font-size: 14px; font-weight: 700; color: #1a1816;
      margin-bottom: 5px;
    }

    .rs-card-desc {
      font-family: 'DM Mono', monospace;
      font-size: 11px; color: #8a8680;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid rgba(196,192,186,0.35);
      line-height: 1.6;
    }

    /* ── GRID FIELDS ── */
    .rs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .rs-grid.cols-1 { grid-template-columns: 1fr; }
    .rs-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
    .rs-field { display: flex; flex-direction: column; gap: 5px; }
    .rs-field.span-2 { grid-column: span 2; }

    .rs-label {
      font-family: 'DM Mono', monospace;
      font-size: 10px; font-weight: 500; text-transform: uppercase;
      letter-spacing: 0.1em; color: #8a8680;
      display: flex; align-items: center; gap: 5px;
    }
    .rs-label .req { color: #cc5c58; font-size: 13px; line-height: 1; }

    .rs-input, .rs-select, .rs-textarea {
      background: var(--bg-primary);
      border: none;
      box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      border-radius: 10px; padding: 10px 14px;
      color: #1a1816;
      font-family: 'Sora', sans-serif;
      font-size: 13px; outline: none;
      transition: box-shadow .2s;
      width: 100%;
    }
    .rs-input:focus, .rs-select:focus, .rs-textarea:focus {
      box-shadow: inset 4px 4px 9px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light),
                  0 0 0 2px rgba(54,87,125,0.18);
    }
    .rs-input::placeholder, .rs-textarea::placeholder { color: #b8b4ae; }
    .rs-textarea { min-height: 110px; resize: vertical; line-height: 1.6; }
    .rs-hint {
      font-family: 'DM Mono', monospace;
      font-size: 10px; color: #a8a49e; margin-top: 3px; letter-spacing: 0.03em;
    }

    /* ── INPUT ICON ── */
    .rs-input-wrap { position: relative; }
    .rs-input-wrap .rs-input { padding-left: 38px; }
    .rs-input-icon {
      position: absolute; left: 12px; top: 50%;
      transform: translateY(-50%); font-size: 15px; pointer-events: none;
    }

    /* ── ALERT ── */
    .rs-alert {
      padding: 13px 18px; border-radius: 14px;
      font-size: 13px; font-weight: 600; margin-bottom: 20px;
    }
    .rs-alert.error {
      background: rgba(204,92,88,0.08);
      box-shadow: inset 3px 3px 8px rgba(204,92,88,0.12);
      border-left: 4px solid #cc5c58; color: #8a3030;
    }
    .rs-alert.success {
      background: rgba(122,144,96,0.1);
      box-shadow: inset 3px 3px 8px rgba(122,144,96,0.15);
      border-left: 4px solid #7a9060; color: #2a4020;
    }

    /* ── SAVE BUTTON ── */
    .rs-save-row {
      display: flex; align-items: center; justify-content: flex-end; gap: 12px;
      margin-top: 24px; padding-top: 20px;
      border-top: 1px solid rgba(196,192,186,0.35);
    }

    .rs-btn-save {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 0 28px; height: 42px; border-radius: 999px; border: none;
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; transition: box-shadow .2s;
      background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to)); color: #fff;
      box-shadow: 5px 5px 12px rgba(249,115,22,0.4), -2px -2px 7px rgba(255,255,255,0.5);
    }
    .rs-btn-save:hover { box-shadow: 6px 6px 16px rgba(249,115,22,0.5), -2px -2px 7px rgba(255,255,255,0.6); }

    .rs-btn-sec {
      display: inline-flex; align-items: center;
      padding: 0 20px; height: 42px; border-radius: 999px;
      background: var(--bg-primary);
      box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
      color: #8a8680; font-family: 'Sora', sans-serif;
      font-size: 13px; font-weight: 600; border: none; cursor: pointer;
      transition: box-shadow .18s;
    }
    .rs-btn-sec:hover { box-shadow: 5px 5px 12px var(--shadow-dark), -5px -5px 12px var(--shadow-light); color: #1a1816; }

    /* ── BADGE STATUT ── */
    .rs-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px; border-radius: 999px;
      font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 700;
      letter-spacing: 0.04em;
    }
    .rs-badge.ok   { background: rgba(122,144,96,0.12); color: #4a6038; }
    .rs-badge.warn { background: rgba(184,108,40,0.1); color: #8a5020; border: 1px solid rgba(184,108,40,0.25); }
    .rs-badge.red  { background: rgba(204,92,88,0.1); color: #8a3030; }

    /* ── SECTION DIVIDER ── */
    .rs-divider { height: 1px; background: rgba(196,192,186,0.4); margin: 20px 0; }

    /* ── DOCUMENT LIST ── */
    .rs-doc-cat-title {
      font-family: 'DM Mono', monospace;
      font-size: 10px; font-weight: 500; text-transform: uppercase;
      letter-spacing: 0.14em; color: #8a8680;
      padding: 14px 0 8px;
      border-bottom: 1px solid rgba(196,192,186,0.35);
      margin-bottom: 10px;
    }

    /* ── ANALYSE IA DES DOCUMENTS ── */
    .rs-doc-analysis {
      padding: 10px 18px 14px;
      background: rgba(106,76,168,0.04);
      border-left: 3px solid rgba(106,76,168,0.3);
      border-radius: 0 12px 12px 0;
      margin: 0 0 10px 32px;
      transition: background .2s;
    }
    .rs-doc-analysis-bar {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .rs-doc-analysis-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 99px;
      font-family: 'DM Mono', monospace;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.04em;
    }
    .rs-doc-analyze-btn {
      background: linear-gradient(135deg, #6a4ca8, #8a6cc8) !important;
      color: #fff !important;
      border: none !important;
    }
    .rs-doc-analyze-btn:hover {
      box-shadow: 0 4px 12px rgba(106,76,168,0.35) !important;
    }
    .rs-doc-analysis-comment {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px dashed rgba(106,76,168,0.25);
    }
    .rs-doc-analysis-textarea {
      width: 100%;
      min-height: 56px;
      max-height: 200px;
      padding: 8px 12px;
      margin-top: 4px;
      border-radius: 8px;
      border: 1px solid rgba(106,76,168,0.25);
      font-family: 'Sora', sans-serif;
      font-size: 12px;
      line-height: 1.5;
      color: #2a2622;
      background: #fff;
      resize: vertical;
      outline: none;
    }
    /* ── Panneau "Détails de l'analyse" repliable ── */
    .rs-doc-analysis-details {
      margin-top: 10px;
      padding: 12px 14px;
      border-top: 1px dashed rgba(106,76,168,0.25);
      background: rgba(255,255,255,0.45);
      border-radius: 8px;
      font-size: 11px;
      line-height: 1.5;
      color: #2a2622;
    }
    .rs-doc-analysis-details-section {
      margin-bottom: 10px;
    }
    .rs-doc-analysis-details-section:last-child { margin-bottom: 0; }
    .rs-doc-analysis-details-title {
      font-family: 'DM Mono', monospace;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .rs-doc-analysis-fields {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 6px 14px;
    }
    .rs-doc-analysis-field {
      display: flex;
      gap: 8px;
      padding: 4px 8px;
      border-radius: 5px;
      font-size: 11px;
      align-items: baseline;
    }
    .rs-doc-analysis-field.applied {
      background: rgba(122,144,96,0.12);
      border-left: 2px solid #7a9060;
    }
    .rs-doc-analysis-field.skipped {
      background: rgba(168,164,158,0.10);
      border-left: 2px solid #a8a49e;
      opacity: 0.75;
    }
    .rs-doc-analysis-field.extracted {
      background: rgba(54,87,125,0.08);
      border-left: 2px solid #36577d;
    }
    .rs-doc-analysis-field .key {
      font-family: 'DM Mono', monospace;
      font-size: 10px;
      color: #6a6560;
      font-weight: 700;
      flex-shrink: 0;
      min-width: 110px;
    }
    .rs-doc-analysis-field .val {
      flex: 1;
      font-weight: 600;
      color: #1a1816;
      word-break: break-word;
    }
    .rs-doc-analysis-field.skipped .val {
      color: #6a6560;
      font-weight: 400;
      font-style: italic;
    }
    .rs-doc-analysis-empty {
      color: #8a8680;
      font-style: italic;
      padding: 6px 8px;
      font-size: 11px;
    }
    .rs-doc-analysis-textarea:focus {
      border-color: #6a4ca8;
      box-shadow: 0 0 0 3px rgba(106,76,168,0.1);
    }

    .rs-doc-row {
      display: flex; align-items: center; gap: 14px;
      padding: 12px 16px; border-radius: 14px;
      background: var(--bg-primary);
      box-shadow: 5px 5px 12px var(--shadow-dark), -5px -5px 12px var(--shadow-light);
      margin-bottom: 10px;
      transition: box-shadow .15s;
    }
    .rs-doc-row:hover { box-shadow: 7px 7px 16px var(--shadow-dark), -7px -7px 16px var(--shadow-light); }

    .rs-doc-icon { font-size: 22px; flex-shrink: 0; }

    .rs-doc-info { flex: 1; min-width: 0; }
    .rs-doc-name { font-size: 13px; font-weight: 600; color: #1a1816; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rs-doc-meta { font-family: 'DM Mono', monospace; font-size: 10px; color: #8a8680; margin-top: 2px; }

    .rs-doc-expiry {
      font-family: 'DM Mono', monospace;
      font-size: 10px; font-weight: 700; padding: 2px 9px;
      border-radius: 999px; white-space: nowrap; letter-spacing: 0.04em;
    }
    .rs-doc-expiry.ok      { background: rgba(122,144,96,0.12); color: #4a6038; }
    .rs-doc-expiry.warn    { background: rgba(184,108,40,0.1); color: #8a5020; }
    .rs-doc-expiry.expired { background: rgba(204,92,88,0.1); color: #8a3030; }

    .rs-doc-actions { display: flex; gap: 6px; flex-shrink: 0; }

    .rs-doc-btn {
      padding: 5px 12px; border-radius: 999px;
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
      cursor: pointer; border: none;
      background: var(--bg-primary);
      box-shadow: 3px 3px 6px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
      color: #8a8680; transition: all .15s;
    }
    .rs-doc-btn:hover { box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 10px var(--shadow-light); color: #1a1816; }
    .rs-doc-btn.danger  { color: #8a3030; }
    .rs-doc-btn.danger:hover { background: rgba(204,92,88,0.08); }

    /* ── UPLOAD ZONE ── */
    .rs-upload-zone {
      border: 2px dashed rgba(196,192,186,0.6); border-radius: 16px;
      padding: 28px 20px; text-align: center; cursor: pointer;
      transition: all .2s; background: rgba(196,192,186,0.08);
    }
    .rs-upload-zone:hover, .rs-upload-zone.drag-over {
      border-color: #36577d; background: rgba(54,87,125,0.05);
    }
    .rs-upload-icon { font-size: 32px; margin-bottom: 8px; opacity: .7; }
    .rs-upload-text { font-family: 'DM Mono', monospace; font-size: 12px; color: #8a8680; }
    .rs-upload-text strong { color: #36577d; }

    .rs-upload-form {
      background: rgba(196,192,186,0.1);
      box-shadow: inset 3px 3px 8px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      border-radius: 16px; padding: 20px;
      margin-top: 16px;
    }

    /* ── ACTIVITÉS CARTE T ── */
    .carte-activites-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 10px;
    }

    .activite-chip {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 12px 14px;
      border-radius: 14px;
      background: var(--bg-primary);
      box-shadow: 5px 5px 12px var(--shadow-dark), -5px -5px 12px var(--shadow-light);
      cursor: pointer;
      transition: all .18s;
      user-select: none; border: none;
    }
    .activite-chip:hover { box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light); }
    .activite-chip.checked {
      box-shadow: inset 3px 3px 8px rgba(122,144,96,0.2), inset -3px -3px 8px var(--shadow-light);
      background: rgba(122,144,96,0.06);
    }
    .activite-chip input[type="checkbox"] { display: none; }

    .activite-chip-content { flex: 1; min-width: 0; }
    .activite-chip-header {
      display: flex; align-items: center; gap: 7px;
      margin-bottom: 3px;
    }
    .activite-check {
      width: 18px; height: 18px; border-radius: 5px;
      background: var(--bg-primary);
      box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 800; color: transparent;
      flex-shrink: 0; transition: all .15s;
    }
    .activite-chip.checked .activite-check {
      background: #7a9060;
      box-shadow: inset 2px 2px 4px rgba(74,96,56,0.3), inset -2px -2px 4px rgba(255,255,255,0.2);
      color: #fff;
    }
    .activite-label {
      font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600; color: #1a1816;
    }
    .activite-default-badge {
      margin-left: auto;
      font-family: 'DM Mono', monospace;
      font-size: 9px; font-weight: 700;
      padding: 2px 7px; border-radius: 99px;
      background: rgba(122,144,96,0.12); color: #4a6038;
      letter-spacing: .5px; text-transform: uppercase; flex-shrink: 0;
    }
    .activite-desc {
      font-family: 'DM Mono', monospace; font-size: 11px; color: #8a8680; line-height: 1.4;
    }

    /* ── SA SELECTOR (admin multi-société) ── */
    .sa-selector {
      display: flex; align-items: center; gap: 10px;
      background: rgba(232,121,249,0.08);
      box-shadow: inset 3px 3px 8px rgba(232,121,249,0.1);
      border-left: 4px solid #e879f9;
      border-radius: 14px; padding: 12px 18px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .sa-selector label {
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500;
      letter-spacing: 0.08em; color: #8a6098; text-transform: uppercase; flex-shrink: 0;
    }
    .sa-selector select {
      background: var(--bg-primary);
      box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
      border: none; border-radius: 10px; padding: 7px 14px;
      font-family: 'Sora', sans-serif; font-size: 13px; color: #1a1816;
      cursor: pointer; outline: none; flex: 1; min-width: 200px;
    }
    .sa-selector .sa-go {
      display: inline-flex; align-items: center;
      padding: 0 18px; height: 36px; border-radius: 999px; border: none;
      font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 600;
      cursor: pointer; background: #e879f9; color: #fff;
      box-shadow: 4px 4px 10px rgba(232,121,249,0.4), -2px -2px 6px rgba(255,255,255,0.5);
      transition: box-shadow .2s; letter-spacing: 0.06em;
    }
    .sa-selector .sa-go:hover { box-shadow: 5px 5px 14px rgba(232,121,249,0.5), -2px -2px 5px var(--shadow-light); }

    /* ── RESPONSIVE ── */
    @media (max-width: 900px) {
      .rs-main { margin-left: 0; }
      .rs-grid { grid-template-columns: 1fr; }
      .rs-field.span-2 { grid-column: span 1; }
      .rs-content { padding: 16px 16px 80px; }
      .carte-activites-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

<div class="rs-main">

  <!-- TOPBAR -->
  <header class="rs-topbar">
    <button class="topbar-back" onclick="history.back()" title="Retour">
      <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <button class="topbar-back" onclick="history.forward()" title="Avancer">
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
    <nav class="topbar-breadcrumb">
      <a href="rh_dashboard.php">RH</a>
      <span class="topbar-sep">›</span>
      <span class="active">
        <?= h($soc['nom'] ?? 'Fiche société') ?>
        <?php if ($saOverride > 0): ?>
        <span class="rs-topbar-badge">SA</span>
        <?php endif; ?>
      </span>
    </nav>
    <div class="topbar-spacer"></div>
    <button class="topbar-notif" title="Notifications">
      <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      <span class="topbar-notif-dot"></span>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom']??'?',0,1).substr($_SESSION['nom']??'',0,1)) ?></div>
  </header>

  <!-- ONGLETS -->
  <div class="rs-tabs-wrap" id="rsTabs">
  <nav class="rs-tabs">
    <button class="rs-tab <?= $activeTab === 'juridique'   ? 'active' : '' ?>" data-tab="juridique"   onclick="switchTab('juridique')">🏛️ Identité juridique <span class="tab-dot"></span></button>
    <button class="rs-tab <?= $activeTab === 'financier'   ? 'active' : '' ?>" data-tab="financier"   onclick="switchTab('financier')">💶 Financier <span class="tab-dot"></span></button>
    <button class="rs-tab <?= $activeTab === 'metier'      ? 'active' : '' ?>" data-tab="metier"      onclick="switchTab('metier')">🏠 Métier & Licences <span class="tab-dot"></span></button>
    <button class="rs-tab <?= $activeTab === 'coordonnees' ? 'active' : '' ?>" data-tab="coordonnees" onclick="switchTab('coordonnees')">📍 Coordonnées <span class="tab-dot"></span></button>
    <button class="rs-tab <?= $activeTab === 'marque'      ? 'active' : '' ?>" data-tab="marque"      onclick="switchTab('marque')">🎨 Marque & Réseaux <span class="tab-dot"></span></button>
    <button class="rs-tab <?= $activeTab === 'documents'   ? 'active' : '' ?>" data-tab="documents"   onclick="switchTab('documents')">📎 Documents <span class="tab-dot"></span></button>
    <button class="rs-tab <?= $activeTab === 'agences'     ? 'active' : '' ?>" data-tab="agences"     onclick="switchTab('agences')">🏪 Agences <span class="rs-tab-count"><?= count($agences) ?></span> <span class="tab-dot"></span></button>
    <?php if ($roleId === 1 || $isSuperAdmin): ?>
    <button class="rs-tab <?= $activeTab === 'modules'     ? 'active' : '' ?>" data-tab="modules"     onclick="switchTab('modules')">⚙️ Modules <span class="tab-dot"></span></button>
    <?php endif; ?>
  </nav>
  </div>

  <!-- SCROLL ZONE -->
  <div class="rs-scroll">
  <div class="rs-content">

    <?php if (!empty($errors)): ?>
      <div class="rs-alert error">⚠ <?= h(implode(' · ', $errors)) ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="rs-alert success">✓ <?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($isSuperAdmin && count($allSocietes) > 1): ?>
    <div class="sa-selector">
      <label>Société</label>
      <select onchange="if(this.value) location.href='societe.php?sa_id='+this.value">
        <?php foreach ($allSocietes as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $societeId ? 'selected' : '' ?>>
          <?= h($s['nom']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php if ($saOverride > 0): ?>
      <a href="societe_super_admin.php" class="sa-go">← Toutes les sociétés</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // ╔══════════════════════════════════════════════════════════╗
    // ║  FORMULAIRE PRINCIPAL (sections 1-5)                    ║
    // ╚══════════════════════════════════════════════════════════╝
    $v = $soc; // alias raccourci
    ?>

    <form method="post" id="mainForm">
      <?= csrf_field('rh_societe') ?>
      <input type="hidden" name="action" value="update_societe">
      <input type="hidden" name="active_tab" id="active_tab_hidden" value="<?= h($activeTab) ?>">

      <!-- ══════════════ ONGLET 1 — IDENTITÉ JURIDIQUE ══════════════ -->
      <div class="rs-panel <?= $activeTab === 'juridique' ? 'active' : '' ?>" id="panel-juridique">

        <div class="rs-card">
          <div class="rs-card-title">Dénomination sociale</div>
          <div class="rs-card-desc">Nom officiel, forme et immatriculation de la société.</div>

          <div class="rs-grid">
            <div class="rs-field">
              <label class="rs-label"><span class="req">*</span> Nom commercial</label>
              <input type="text" name="nom" class="rs-input" value="<?= h($v['nom'] ?? '') ?>" placeholder="ex. Agence Dupont Immobilier" required>
            </div>
            <div class="rs-field">
              <label class="rs-label">Raison sociale</label>
              <input type="text" name="raison_sociale" class="rs-input" value="<?= h($v['raison_sociale'] ?? '') ?>" placeholder="ex. SAS DUPONT IMMOBILIER">
            </div>
            <div class="rs-field">
              <label class="rs-label">Forme juridique</label>
              <select name="forme_juridique" class="rs-select">
                <?php
                $formes = ['', 'SAS', 'SARL', 'EURL', 'SA', 'SNC', 'EI', 'SASU', 'SCI', 'Autre'];
                foreach ($formes as $f):
                ?>
                <option value="<?= h($f) ?>" <?= ($v['forme_juridique'] ?? '') === $f ? 'selected' : '' ?>>
                  <?= $f === '' ? '— Choisir —' : $f ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="rs-field">
              <label class="rs-label">Capital social (€)</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">💶</span>
                <input type="number" name="capital_social" class="rs-input" step="0.01" min="0"
                       value="<?= h($v['capital_social'] ?? '') ?>" placeholder="ex. 10000">
              </div>
            </div>
          </div>

          <div class="rs-divider"></div>

          <div class="rs-grid cols-3">
            <div class="rs-field">
              <label class="rs-label">SIRET</label>
              <input type="text" name="siret" class="rs-input" value="<?= h($v['siret'] ?? '') ?>"
                     placeholder="14 chiffres" maxlength="20" pattern="[0-9 ]+"
                     oninput="autoFillSiren(this.value)">
              <span class="rs-hint">14 chiffres sans espace</span>
            </div>
            <div class="rs-field">
              <label class="rs-label">SIREN</label>
              <input type="text" name="siren" id="input_siren" class="rs-input" value="<?= h($v['siren'] ?? '') ?>"
                     placeholder="9 chiffres" maxlength="20">
              <span class="rs-hint">Auto-rempli depuis le SIRET</span>
            </div>
            <div class="rs-field">
              <label class="rs-label">TVA intracommunautaire</label>
              <input type="text" name="tva_intracom" class="rs-input" value="<?= h($v['tva_intracom'] ?? '') ?>"
                     placeholder="ex. FR12 123456789">
            </div>
          </div>

          <div class="rs-divider"></div>

          <div class="rs-grid cols-2">
            <div class="rs-field">
              <label class="rs-label">Code APE / NAF</label>
              <input type="text" name="code_ape" class="rs-input"
                     value="<?= h($v['code_ape'] ?? '6831Z') ?>"
                     placeholder="ex. 6831Z" maxlength="10">
              <span class="rs-hint">Agences immobilières = <strong>6831Z</strong> (défaut)</span>
            </div>
            <div class="rs-field">
              <label class="rs-label">Convention collective</label>
              <input type="text" name="convention_collective" class="rs-input"
                     value="<?= h($v['convention_collective'] ?? 'IMMOBILIER') ?>"
                     placeholder="ex. IMMOBILIER" maxlength="190">
              <span class="rs-hint">CCN Immobilier (IDCC 1527) par défaut</span>
            </div>
          </div>
        </div>

      </div><!-- /panel-juridique -->


      <!-- ══════════════ ONGLET 2 — FINANCIER ══════════════ -->
      <div class="rs-panel <?= $activeTab === 'financier' ? 'active' : '' ?>" id="panel-financier">

        <!-- Synthèse conformité Loi Hoguet (calculée depuis societes_couvertures) -->
        <?php
          $nbRcp = count($couvByType['rcp'] ?? []);
          $nbGf  = count($couvByType['garantie_financiere'] ?? []);
          $hasRcp = $nbRcp > 0;
          $hasGf  = $nbGf  > 0;
        ?>
        <div class="rs-card">
          <div class="rs-card-title">Conformité Loi Hoguet</div>
          <div class="rs-card-desc">
            La société doit justifier d'une <strong>garantie financière</strong> (si elle détient des fonds)
            et d'une <strong>assurance RCP</strong> (obligatoire pour toutes les activités d'intermédiation
            immobilière — art. 49 décret Hoguet). Les couvertures sont renseignées automatiquement en
            analysant vos attestations dans l'onglet <strong>📎 Documents</strong>.
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;">
            <div style="padding:14px 16px;border-radius:10px;box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light);">
              <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:0.08em;text-transform:uppercase;color:#7a9060;font-weight:700;">💰 Garantie financière</div>
              <?php if ($hasGf): ?>
                <div style="margin-top:6px;font-size:13px;color:#166534;font-weight:700;">
                  ✓ <?= $nbGf ?> attestation<?= $nbGf > 1 ? 's' : '' ?>
                </div>
              <?php else: ?>
                <div style="margin-top:6px;font-size:12px;color:#92400e;">
                  ⚠ Non renseignée — uploadez l'attestation dans Documents
                </div>
              <?php endif; ?>
            </div>
            <div style="padding:14px 16px;border-radius:10px;box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light);">
              <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:0.08em;text-transform:uppercase;color:#36577d;font-weight:700;">🛡️ Assurance RCP</div>
              <?php if ($hasRcp): ?>
                <div style="margin-top:6px;font-size:13px;color:#166534;font-weight:700;">
                  ✓ <?= $nbRcp ?> attestation<?= $nbRcp > 1 ? 's' : '' ?>
                </div>
              <?php else: ?>
                <div style="margin-top:6px;font-size:12px;color:#991b1b;">
                  ✕ Non renseignée — obligatoire (décret Hoguet)
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ══ DÉTAIL DES COUVERTURES PAR ACTIVITÉ (multi-attestations) ══ -->
        <div class="rs-card" style="margin-top:18px;">
          <div class="rs-card-title">Couvertures détaillées par activité</div>
          <div class="rs-card-desc">
            Une société peut exercer plusieurs activités et avoir une attestation différente pour
            chacune (Transaction, Gestion, Syndic). Les lignes ci-dessous sont alimentées automatiquement
            à l'analyse IA d'une attestation dans l'onglet <strong>📎 Documents</strong>.
          </div>

          <?php
            $activitesLabels = [
              'transaction' => '🤝 Transaction',
              'gestion'     => '🏢 Gestion locative',
              'syndic'      => '🏛️ Syndic de copropriété',
              'location'    => '🔑 Location',
              'neuf'        => '✨ Neuf / VEFA',
              'multi'       => '🌐 Toutes activités',
            ];
            $typesLabels = [
              'rcp'                 => ['RCP', '🛡️', '#36577d'],
              'garantie_financiere' => ['Garantie financière', '💰', '#7a9060'],
            ];
          ?>

          <?php foreach ($typesLabels as $tk => [$tLabel, $tIcon, $tColor]):
            $lignes = $couvByType[$tk] ?? [];
          ?>
          <div style="margin-top:18px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
              <span style="font-size:16px;"><?= $tIcon ?></span>
              <strong style="color:<?= $tColor ?>;font-family:'Sora',sans-serif;font-size:14px;"><?= h($tLabel) ?></strong>
              <span style="font-size:10px;font-family:'DM Mono',monospace;color:#a8a49e;">
                <?= count($lignes) ?> attestation<?= count($lignes) > 1 ? 's' : '' ?>
              </span>
            </div>

            <?php if (empty($lignes)): ?>
            <div class="rs-badge warn" style="font-size:11px;">
              Aucune <?= h(strtolower($tLabel)) ?> renseignée — uploadez une attestation dans <strong>Documents</strong>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($lignes as $activite => $c):
                $expired = false; $expireLabel = '';
                if (!empty($c['date_expiration'])) {
                    try {
                        $exp = new DateTime($c['date_expiration']);
                        $now = new DateTime();
                        $expired = $exp < $now;
                        $diffJ = (int)$now->diff($exp)->days;
                        $expireLabel = $expired
                            ? '✕ Expirée le ' . $exp->format('d/m/Y')
                            : ($diffJ <= 60 ? '⚠ Expire le ' . $exp->format('d/m/Y') : '✓ Valide jusqu\'au ' . $exp->format('d/m/Y'));
                    } catch (Throwable) {}
                }
              ?>
              <?php
                $history = $couvHistorique[$tk][$activite] ?? [];
                $histCount = count($history);
                $covKey = $tk . '_' . $activite;
              ?>
              <div data-cov-row style="display:grid;grid-template-columns:160px 1fr auto;gap:14px;padding:12px 14px;background:var(--bg-primary);border-radius:10px;box-shadow:inset 2px 2px 5px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light);align-items:center;">
                <div>
                  <div style="font-family:'DM Mono',monospace;font-size:9px;color:<?= $tColor ?>;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">
                    <?= h($activitesLabels[$activite] ?? $activite) ?>
                  </div>
                  <div style="font-size:13px;font-weight:700;color:var(--ink);margin-top:2px;">
                    <?= h($c['compagnie'] ?: '—') ?>
                    <?php if ((int)($c['version_num'] ?? 1) > 1): ?>
                      <span style="font-family:'DM Mono',monospace;font-size:9px;color:<?= $tColor ?>;background:rgba(0,0,0,0.05);padding:1px 6px;border-radius:99px;margin-left:4px;">v<?= (int)$c['version_num'] ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div style="font-size:11px;color:var(--muted);line-height:1.6;">
                  <?php if ($c['numero_police']): ?>
                  Police <strong><?= h($c['numero_police']) ?></strong><br>
                  <?php endif; ?>
                  <?php if ($c['montant'] && $c['montant'] > 0): ?>
                  Montant : <strong><?= number_format((float)$c['montant'], 0, ',', ' ') ?> €</strong><br>
                  <?php endif; ?>
                  <?php if ($c['doc_titre']): ?>
                  Source : <em><?= h($c['doc_titre']) ?></em>
                  <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
                  <?php if ($expireLabel): ?>
                  <span class="rs-badge <?= $expired ? 'red' : (($diffJ ?? 999) <= 60 ? 'warn' : 'ok') ?>">
                    <?= h($expireLabel) ?>
                  </span>
                  <?php endif; ?>
                  <?php if ($histCount > 0): ?>
                  <button type="button" onclick="covToggleHistory('<?= $covKey ?>')"
                          style="font-size:10px;font-family:'DM Mono',monospace;padding:3px 10px;border:1px solid <?= $tColor ?>55;background:rgba(0,0,0,0.02);color:<?= $tColor ?>;border-radius:99px;cursor:pointer;">
                    📜 Historique (<?= $histCount ?>)
                  </button>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($histCount > 0): ?>
              <!-- Panneau historique repliable -->
              <div id="covHist_<?= h($covKey) ?>" style="display:none;margin-left:20px;margin-top:-4px;padding:10px 14px;background:rgba(0,0,0,0.03);border-left:2px dashed <?= $tColor ?>66;border-radius:0 8px 8px 0;">
                <div style="font-family:'DM Mono',monospace;font-size:9px;color:<?= $tColor ?>;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:6px;">
                  📜 Versions archivées (<?= $histCount ?>)
                </div>
                <?php foreach ($history as $hv):
                    $hvExpLabel = '';
                    if (!empty($hv['date_expiration'])) {
                        try {
                            $hvExp = new DateTime($hv['date_expiration']);
                            $hvExpLabel = $hvExp->format('d/m/Y');
                        } catch (Throwable) {}
                    }
                    $hvArchive = '';
                    if (!empty($hv['date_archive'])) {
                        try {
                            $hvArc = new DateTime($hv['date_archive']);
                            $hvArchive = $hvArc->format('d/m/Y');
                        } catch (Throwable) {}
                    }
                ?>
                <div style="display:grid;grid-template-columns:60px 1fr auto;gap:10px;padding:6px 8px;font-size:11px;color:#6a6560;border-bottom:1px dashed rgba(0,0,0,0.05);align-items:center;">
                  <span style="font-family:'DM Mono',monospace;font-size:10px;color:<?= $tColor ?>;font-weight:700;">v<?= (int)$hv['version_num'] ?></span>
                  <span>
                    <strong><?= h($hv['compagnie'] ?: '—') ?></strong>
                    <?php if ($hv['numero_police']): ?> · <?= h($hv['numero_police']) ?><?php endif; ?>
                    <?php if ($hv['montant']): ?> · <?= number_format((float)$hv['montant'], 0, ',', ' ') ?> €<?php endif; ?>
                    <?php if ($hvExpLabel): ?><br><span style="font-size:10px;color:#8a8680;">exp. <?= $hvExpLabel ?></span><?php endif; ?>
                  </span>
                  <span style="font-size:10px;color:#a8a49e;font-style:italic;">
                    <?php if ($hvArchive): ?>archivée le <?= $hvArchive ?><?php endif; ?>
                  </span>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

      </div><!-- /panel-financier -->


      <!-- ══════════════ ONGLET 3 — MÉTIER & LICENCES ══════════════ -->
      <div class="rs-panel <?= $activeTab === 'metier' ? 'active' : '' ?>" id="panel-metier">

        <?php
        // Toutes les activités possibles (Loi Hoguet + décret 2015)
        $allActivites = [
            'transaction'       => ['label' => 'Transaction immobilière',    'desc' => 'Achat, vente, échange de biens immobiliers (art. 1 Loi Hoguet)', 'default' => true],
            'gestion'           => ['label' => 'Gestion locative',           'desc' => 'Administration de biens, encaissement des loyers pour compte de tiers',          'default' => true],
            'syndic'            => ['label' => 'Syndic de copropriété',      'desc' => 'Administration d\'immeubles en copropriété (art. 1-1 Loi Hoguet)',              'default' => true],
            'location_saisonniere' => ['label' => 'Location saisonnière',    'desc' => 'Location meublée de courte durée, résidences de tourisme',                       'default' => false],
            'marchand_listes'   => ['label' => 'Marchand de listes',         'desc' => 'Vente de listes ou fichiers de biens à louer ou à vendre (art. 6 Loi Hoguet)',  'default' => false],
            'viager'            => ['label' => 'Viager',                     'desc' => 'Transaction en viager occupé ou libre',                                          'default' => false],
            'commerces_fonds'   => ['label' => 'Fonds de commerce',          'desc' => 'Cession, achat, vente, location-gérance de fonds de commerce',                  'default' => false],
            'locaux_commerciaux'=> ['label' => 'Locaux commerciaux',         'desc' => 'Bail commercial, 3-6-9, cession de droit au bail',                               'default' => false],
            'terrain_neuf'      => ['label' => 'VEFA / Promotion',           'desc' => 'Vente en état futur d\'achèvement, commercialisation de programmes neufs',       'default' => false],
            'expertise'         => ['label' => 'Expertise immobilière',      'desc' => 'Estimation, évaluation vénale ou locative d\'actifs immobiliers',                'default' => false],
        ];

        $ct         = trim($v['numero_carte_t'] ?? '');
        $ctCci      = trim($v['cci_carte_t'] ?? '');
        $ctExpDate  = $v['carte_t_date_expiration'] ?? '';

        // Calcul statut expiration
        $ctExpStatus = 'ok';
        $ctExpTxt    = '';
        if ($ctExpDate) {
            $exp  = new DateTime($ctExpDate);
            $now  = new DateTime();
            $diff = (int)$now->diff($exp)->days;
            if ($exp < $now)      { $ctExpStatus = 'red';  $ctExpTxt = '✕ Expirée le ' . $exp->format('d/m/Y'); }
            elseif ($diff <= 90)  { $ctExpStatus = 'warn'; $ctExpTxt = '⚠ Expire le ' . $exp->format('d/m/Y') . ' (dans ' . $diff . ' jours)'; }
            else                  { $ctExpStatus = 'ok';   $ctExpTxt = '✓ Valide jusqu\'au ' . $exp->format('d/m/Y'); }
        }
        ?>

        <div class="rs-card">
          <div class="rs-card-title">🪪 Carte Professionnelle (Carte T)</div>
          <div class="rs-card-desc">
            Délivrée par la CCI — obligatoire pour exercer (Loi Hoguet n°70-9 du 2 janvier 1970).
            Renouvelable tous les 3 ans. Doit figurer sur tous les documents contractuels.
          </div>

          <!-- Identification carte -->
          <div class="rs-grid cols-3">
            <div class="rs-field">
              <label class="rs-label"><span class="req">*</span> Numéro de Carte T</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">🪪</span>
                <input type="text" name="numero_carte_t" class="rs-input"
                       value="<?= h($ct) ?>" placeholder="ex. CPI 6901 2024 XXXXX"
                       oninput="updateCarteStatus()">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">CCI émettrice</label>
              <input type="text" name="cci_carte_t" class="rs-input"
                     value="<?= h($ctCci) ?>" placeholder="ex. CCI de Lyon — Rhône">
            </div>
            <div class="rs-field">
              <label class="rs-label">Date d'expiration</label>
              <input type="date" name="carte_t_date_expiration" class="rs-input"
                     value="<?= h($ctExpDate) ?>" style="color-scheme:light;"
                     oninput="updateCarteStatus()">
              <span class="rs-hint">Renouvellement tous les 3 ans à la CCI</span>
            </div>
          </div>

          <!-- Statut carte -->
          <div id="carte-status" style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php if ($ct === ''): ?>
            <span class="rs-badge red" id="badge-numero">✕ Numéro non renseigné — obligatoire</span>
            <?php else: ?>
            <span class="rs-badge ok" id="badge-numero">✓ <?= h($ct) ?></span>
            <?php endif; ?>
            <?php if ($ctExpTxt): ?>
            <span class="rs-badge <?= $ctExpStatus ?>" id="badge-expiry"><?= h($ctExpTxt) ?></span>
            <?php else: ?>
            <span class="rs-badge warn" id="badge-expiry" style="display:none;"></span>
            <?php endif; ?>
          </div>

          <div class="rs-divider"></div>

          <!-- Activités autorisées -->
          <div style="margin-bottom:12px;">
            <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:4px;">Activités couvertes par la carte</div>
            <div style="font-size:12px;color:var(--muted);margin-bottom:16px;">
              Cochez les mentions portées sur votre carte T — elles figurent sur vos contrats et mandats.<br>
              <strong style="color:var(--accent);">Transaction, Gestion et Syndic</strong> sont activés par défaut.
            </div>

            <div class="carte-activites-grid">
              <?php foreach ($allActivites as $code => $act):
                $checked = in_array($code, $carteTActivites, true);
              ?>
              <label class="activite-chip <?= $checked ? 'checked' : '' ?>" id="chip-<?= h($code) ?>">
                <input type="checkbox" name="carte_t_activites[]" value="<?= h($code) ?>"
                       <?= $checked ? 'checked' : '' ?>
                       onchange="updateActiviteChip(this)">
                <div class="activite-chip-content">
                  <div class="activite-chip-header">
                    <span class="activite-check">✓</span>
                    <span class="activite-label"><?= h($act['label']) ?></span>
                    <?php if ($act['default']): ?>
                    <span class="activite-default-badge">Défaut</span>
                    <?php endif; ?>
                  </div>
                  <div class="activite-desc"><?= h($act['desc']) ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Aperçu mentions légales -->
          <div id="mentions-preview" style="margin-top:18px;padding:14px 16px;background:rgba(102,217,255,0.04);border:1px solid rgba(72,120,166,0.1);border-radius:var(--r-sm);">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:8px;">Aperçu mention légale (à reprendre sur mandats et contrats)</div>
            <div id="mentions-text" style="font-size:12px;color:var(--ink);line-height:1.7;font-style:italic;"></div>
          </div>

          <div style="margin-top:16px;padding:14px 16px;background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:var(--r-sm);font-size:12px;color:#795548;line-height:1.6;">
            💡 <strong>Rappel légal :</strong> La carte T doit être affichée dans les locaux (art. 7 Loi Hoguet).
            La mention obligatoire sur les mandats : <em>"Carte professionnelle n° [NUMÉRO] délivrée par la CCI [VILLE]"</em>.
            Chaque activité exercée doit figurer sur la carte ou sur une habilitation.
          </div>
        </div>

      </div><!-- /panel-metier -->


      <!-- ══════════════ ONGLET 4 — COORDONNÉES ══════════════ -->
      <div class="rs-panel <?= $activeTab === 'coordonnees' ? 'active' : '' ?>" id="panel-coordonnees">

        <div class="rs-card">
          <div class="rs-card-title">Siège social</div>
          <div class="rs-card-desc">Adresse officielle du siège de la société.</div>

          <div class="rs-grid">
            <div class="rs-field span-2">
              <label class="rs-label">Adresse ligne 1</label>
              <input type="text" name="adresse_1" class="rs-input"
                     value="<?= h($v['adresse_1'] ?? '') ?>" placeholder="N° et nom de rue">
            </div>
            <div class="rs-field span-2">
              <label class="rs-label">Adresse ligne 2</label>
              <input type="text" name="adresse_2" class="rs-input"
                     value="<?= h($v['adresse_2'] ?? '') ?>" placeholder="Bât., étage, lieu-dit…">
            </div>
            <div class="rs-field">
              <label class="rs-label">Code postal</label>
              <input type="text" name="code_postal" class="rs-input"
                     value="<?= h($v['code_postal'] ?? '') ?>" placeholder="69001" maxlength="10">
            </div>
            <div class="rs-field">
              <label class="rs-label">Ville</label>
              <input type="text" name="ville" class="rs-input"
                     value="<?= h($v['ville'] ?? '') ?>" placeholder="Lyon">
            </div>
            <div class="rs-field">
              <label class="rs-label">Pays</label>
              <input type="text" name="pays" class="rs-input"
                     value="<?= h($v['pays'] ?? 'France') ?>">
            </div>
          </div>
        </div>

        <div class="rs-card">
          <div class="rs-card-title">Contact</div>
          <div class="rs-card-desc">Téléphone, e-mail et site web de la structure.</div>

          <div class="rs-grid cols-3">
            <div class="rs-field">
              <label class="rs-label">Téléphone</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">📞</span>
                <input type="tel" name="telephone" class="rs-input"
                       value="<?= h($v['telephone'] ?? '') ?>" placeholder="04 XX XX XX XX">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">E-mail</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">✉️</span>
                <input type="email" name="email" class="rs-input"
                       value="<?= h($v['email'] ?? '') ?>" placeholder="contact@agence.fr">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">📊 E-mail comptable
                <span style="font-weight:400;color:#94a3b8;font-size:11px;">(envoi PDF salaires/congés)</span>
              </label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">📊</span>
                <input type="email" name="comptable_email" class="rs-input"
                       value="<?= h($v['comptable_email'] ?? '') ?>" placeholder="comptable@cabinet-comptable.fr">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">👤 Nom du comptable</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">👤</span>
                <input type="text" name="comptable_nom" class="rs-input"
                       value="<?= h($v['comptable_nom'] ?? '') ?>" placeholder="ex. Marie-Charlotte SAGNOL" maxlength="120">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">📞 Tél. comptable</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">📞</span>
                <input type="tel" name="comptable_telephone" class="rs-input"
                       value="<?= h($v['comptable_telephone'] ?? '') ?>" placeholder="ex. 04 73 00 00 00" maxlength="50">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">🏢 Cabinet comptable</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">🏢</span>
                <input type="text" name="comptable_societe" class="rs-input"
                       value="<?= h($v['comptable_societe'] ?? '') ?>" placeholder="ex. In Extenso" maxlength="190">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">Site web</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">🌐</span>
                <input type="url" name="site_web" class="rs-input"
                       value="<?= h($v['site_web'] ?? '') ?>" placeholder="https://www.agence.fr">
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-coordonnees -->


      <!-- ══════════════ ONGLET 5 — MARQUE & RÉSEAUX ══════════════ -->
      <div class="rs-panel <?= $activeTab === 'marque' ? 'active' : '' ?>" id="panel-marque">

        <div class="rs-card">
          <div class="rs-card-title">Présentation publique</div>
          <div class="rs-card-desc">Description de l'agence affichée sur le portail et les annonces.</div>

          <div class="rs-grid cols-1">
            <div class="rs-field">
              <label class="rs-label">Description</label>
              <textarea name="description" class="rs-textarea"
                        placeholder="Présentez votre agence en quelques phrases : spécialités, secteur géographique, valeurs…"><?= h($v['description'] ?? '') ?></textarea>
              <span class="rs-hint">Affichée sur votre page agence — min. recommandé 100 caractères.</span>
            </div>
            <div class="rs-field">
              <label class="rs-label">Mentions légales</label>
              <textarea name="mention_legale" class="rs-textarea" style="min-height:80px;"
                        placeholder="Mentions légales spécifiques à la société (optionnel)."><?= h($v['mention_legale'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <div class="rs-card">
          <div class="rs-card-title">Réseaux sociaux & Google Business</div>
          <div class="rs-card-desc">URLs de vos profils pour les liens depuis le portail.</div>

          <div class="rs-grid">
            <div class="rs-field">
              <label class="rs-label">Facebook</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">👤</span>
                <input type="url" name="facebook_url" class="rs-input"
                       value="<?= h($v['facebook_url'] ?? '') ?>" placeholder="https://facebook.com/…">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">Instagram</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">📷</span>
                <input type="url" name="instagram_url" class="rs-input"
                       value="<?= h($v['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/…">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">LinkedIn</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">💼</span>
                <input type="url" name="linkedin_url" class="rs-input"
                       value="<?= h($v['linkedin_url'] ?? '') ?>" placeholder="https://linkedin.com/…">
              </div>
            </div>
            <div class="rs-field">
              <label class="rs-label">YouTube</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">▶️</span>
                <input type="url" name="youtube_url" class="rs-input"
                       value="<?= h($v['youtube_url'] ?? '') ?>" placeholder="https://youtube.com/…">
              </div>
            </div>
            <div class="rs-field span-2">
              <label class="rs-label">Google Business</label>
              <div class="rs-input-wrap">
                <span class="rs-input-icon">🗺️</span>
                <input type="url" name="google_business_url" class="rs-input"
                       value="<?= h($v['google_business_url'] ?? '') ?>" placeholder="https://maps.google.com/…">
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panel-marque -->

    </form><!-- /mainForm -->


    <!-- ══════════════ ONGLET 6 — DOCUMENTS ══════════════ -->
    <div class="rs-panel <?= $activeTab === 'documents' ? 'active' : '' ?>" id="panel-documents">

      <!-- Bandeau actions admin (super admin uniquement) -->
      <?php if ((int)current_role_id() === 1): ?>
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:18px;">✏️</span>
        <div style="flex:1;min-width:240px;">
          <div style="font-size:13px;font-weight:700;color:#92400e;">Saisie manuelle des couvertures</div>
          <div style="font-size:11px;color:#a16207;margin-top:2px;">Ouvre un éditeur qui charge ce qui est extrait par l'IA et te laisse compléter les champs manquants — utile si l'OCR a échoué ou pour saisir en masse plusieurs sociétés.</div>
        </div>
        <a href="admin/admin_societes_docs_officiels.php?sa_id=<?= (int)$societeId ?>"
           style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#0ea5e9;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:13px;white-space:nowrap;">
          ✏️ Saisie manuelle →
        </a>
      </div>
      <?php endif; ?>

      <?php
        // Helper de rendu de la panel "Analyse IA" sous chaque document.
        // Affiche : badge statut + bouton (ré)analyser + textarea commentaire.
        // Labels jolis des champs extraits — alignés sur la liste de
        // api/societe_doc_analyze.php pour un affichage lisible dans le
        // panneau "Voir les champs".
        $fieldLabels = [
            'nom'                    => 'Nom commercial',
            'raison_sociale'         => 'Raison sociale',
            'forme_juridique'        => 'Forme juridique',
            'capital_social'         => 'Capital social',
            'siret'                  => 'SIRET',
            'siren'                  => 'SIREN',
            'tva_intracom'           => 'TVA intracom',
            'code_ape'               => 'Code APE',
            'convention_collective'  => 'Convention collective',
            'numero_carte_t'         => 'N° carte T',
            'cci_carte_t'            => 'CCI carte T',
            'carte_t_date_expiration'=> 'Expiration carte T',
            'adresse_1'              => 'Adresse 1',
            'adresse_2'              => 'Adresse 2',
            'code_postal'            => 'Code postal',
            'ville'                  => 'Ville',
            'pays'                   => 'Pays',
            'telephone'              => 'Téléphone',
            'email'                  => 'Email',
            'site_web'               => 'Site web',
            'rib_emetteur_nom'       => 'Titulaire RIB',
            'rib_emetteur_iban'      => 'IBAN',
            'rib_emetteur_bic'       => 'BIC',
            '__couverture'           => 'Couverture (RCP/garantie)',
            'couverture_type'        => 'Type couverture',
            'couverture_activite'    => 'Activité couverte',
            'couverture_compagnie'   => 'Compagnie',
            'couverture_numero_police' => 'N° police',
            'couverture_montant'     => 'Montant',
            'couverture_date_debut'  => 'Date début',
            'couverture_date_expiration' => 'Date expiration',
        ];

        $renderAnalysisBlock = static function(array $doc) use ($fieldLabels): void {
            $docId      = (int)$doc['id'];
            $analyzed   = !empty($doc['analyzed_at']);
            $fields     = (int)($doc['analysis_fields_filled'] ?? 0);
            $hasError   = !empty($doc['analysis_error']);
            $comment    = (string)($doc['analysis_comment'] ?? '');
            $dateLabel  = $analyzed ? (new DateTime($doc['analyzed_at']))->format('d/m/Y H:i') : '';

            // Parse le JSON d'analyse pour afficher les détails
            $analysisData = null;
            if (!empty($doc['analysis_json'])) {
                $analysisData = json_decode((string)$doc['analysis_json'], true);
                if (!is_array($analysisData)) $analysisData = null;
            }

            // État visuel
            if ($hasError) {
                $pillBg = '#fee2e2'; $pillBorder = '#991b1b'; $pillIcon = '⚠';
                $pillText = 'Analyse en erreur — ' . htmlspecialchars(mb_substr((string)$doc['analysis_error'], 0, 70));
                $btnLabel = '🔄 Réessayer';
            } elseif ($analyzed && $fields > 0) {
                $pillBg = '#dcfce7'; $pillBorder = '#166534'; $pillIcon = '✓';
                $pillText = "Analysé · {$fields} champ" . ($fields > 1 ? 's' : '') . " rempli" . ($fields > 1 ? 's' : '') . " · {$dateLabel}";
                $btnLabel = '🔄 Réanalyser';
            } elseif ($analyzed) {
                $pillBg = '#f0ebe3'; $pillBorder = '#8a8680'; $pillIcon = 'ℹ';
                $pillText = "Analysé · aucun nouveau champ · {$dateLabel}";
                $btnLabel = '🔄 Réanalyser';
            } else {
                $pillBg = '#fef3c7'; $pillBorder = '#92400e'; $pillIcon = '○';
                $pillText = 'Non analysé';
                $btnLabel = '🤖 Analyser avec l\'IA';
            }

            // Helper local pour rendre une valeur (avec dates et nombres formatés)
            $fmt = static function($v) {
                if ($v === null || $v === '') return '—';
                $s = (string)$v;
                return mb_strlen($s) > 80 ? mb_substr($s, 0, 77) . '…' : $s;
            };
        ?>
        <div class="rs-doc-analysis" data-doc-id="<?= $docId ?>">
          <div class="rs-doc-analysis-bar">
            <span class="rs-doc-analysis-pill"
                  style="background:<?= $pillBg ?>;color:<?= $pillBorder ?>;border:1px solid <?= $pillBorder ?>55;">
              <?= $pillIcon ?> <?= htmlspecialchars($pillText) ?>
            </span>
            <button type="button" class="rs-doc-btn rs-doc-analyze-btn" onclick="docAnalyze(<?= $docId ?>, this)">
              <?= $btnLabel ?>
            </button>
            <?php if ($analysisData): ?>
            <button type="button" class="rs-doc-btn" onclick="docToggleDetails(<?= $docId ?>)" title="Voir les champs extraits">
              👁️ Voir les champs
            </button>
            <?php endif; ?>
            <button type="button" class="rs-doc-btn" onclick="docToggleComment(<?= $docId ?>)" title="Commenter l'analyse">
              💬 Commenter
            </button>
          </div>

          <?php if ($analysisData): ?>
          <!-- Panneau détails repliable (fermé par défaut) -->
          <div class="rs-doc-analysis-details" id="docDetails_<?= $docId ?>" style="display:none;">
            <?php
              $applied  = $analysisData['applied']   ?? [];
              $skipped  = $analysisData['skipped']   ?? [];
              $extracted= $analysisData['extracted'] ?? [];
              $extractedOnly = [];
              foreach ($extracted as $k => $v) {
                  if (isset($applied[$k]) || isset($skipped[$k])) continue;
                  if (str_starts_with($k, 'couverture_')) continue;
                  $extractedOnly[$k] = $v;
              }
            ?>

            <!-- APPLIED : champs effectivement écrits en base -->
            <?php if (!empty($applied)): ?>
            <div class="rs-doc-analysis-details-section">
              <div class="rs-doc-analysis-details-title" style="color:#166534;">
                ✓ Champs appliqués <span style="color:#a8a49e;font-weight:400;">(<?= count($applied) ?>)</span>
              </div>
              <div class="rs-doc-analysis-fields">
                <?php foreach ($applied as $k => $v): ?>
                <div class="rs-doc-analysis-field applied">
                  <span class="key"><?= htmlspecialchars($fieldLabels[$k] ?? $k) ?></span>
                  <span class="val"><?= htmlspecialchars($fmt($v)) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- SKIPPED : champs extraits mais ignorés car déjà remplis -->
            <?php if (!empty($skipped)): ?>
            <div class="rs-doc-analysis-details-section">
              <div class="rs-doc-analysis-details-title" style="color:#8a8680;">
                ⊘ Champs ignorés (déjà remplis) <span style="color:#a8a49e;font-weight:400;">(<?= count($skipped) ?>)</span>
              </div>
              <div class="rs-doc-analysis-fields">
                <?php foreach ($skipped as $k => $reason): ?>
                <div class="rs-doc-analysis-field skipped">
                  <span class="key"><?= htmlspecialchars($fieldLabels[$k] ?? $k) ?></span>
                  <span class="val"><?= htmlspecialchars($fmt($reason)) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- EXTRACTED-ONLY : valeurs vues par l'IA mais non appliquées (hors skipped) -->
            <?php if (!empty($extractedOnly)): ?>
            <div class="rs-doc-analysis-details-section">
              <div class="rs-doc-analysis-details-title" style="color:#36577d;">
                ℹ Autres valeurs vues dans le document <span style="color:#a8a49e;font-weight:400;">(<?= count($extractedOnly) ?>)</span>
              </div>
              <div class="rs-doc-analysis-fields">
                <?php foreach ($extractedOnly as $k => $v): ?>
                <div class="rs-doc-analysis-field extracted">
                  <span class="key"><?= htmlspecialchars($fieldLabels[$k] ?? $k) ?></span>
                  <span class="val"><?= htmlspecialchars($fmt($v)) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- COUVERTURE : détails de l'attestation si le doc en est une -->
            <?php
              $covFields = array_filter($extracted ?? [], fn($v, $k) => str_starts_with($k, 'couverture_') && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
              if (!empty($covFields)):
            ?>
            <div class="rs-doc-analysis-details-section">
              <div class="rs-doc-analysis-details-title" style="color:#6a4ca8;">
                🛡️ Couverture détectée (écrite dans societes_couvertures)
              </div>
              <div class="rs-doc-analysis-fields">
                <?php foreach ($covFields as $k => $v): ?>
                <div class="rs-doc-analysis-field" style="background:rgba(106,76,168,0.08);border-left:2px solid #6a4ca8;">
                  <span class="key"><?= htmlspecialchars($fieldLabels[$k] ?? $k) ?></span>
                  <span class="val"><?= htmlspecialchars($fmt($v)) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (empty($applied) && empty($skipped) && empty($extractedOnly) && empty($covFields)): ?>
            <div class="rs-doc-analysis-empty">Aucune donnée extraite lors de la dernière analyse.</div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="rs-doc-analysis-comment" id="docComment_<?= $docId ?>" style="<?= $comment !== '' ? '' : 'display:none;' ?>">
            <label style="font-size:11px;color:#8a8680;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">
              💬 Instruction pour l'IA (optionnel)
            </label>
            <textarea class="rs-doc-analysis-textarea" data-doc-comment="<?= $docId ?>"
                      placeholder="Ex : « Cherche surtout le numéro de carte T et la date d'expiration », « Vérifie le capital social en page 2 »…"><?= htmlspecialchars($comment) ?></textarea>
            <div style="font-size:10px;color:#a8a49e;margin-top:4px;">L'instruction est sauvegardée automatiquement et envoyée à l'IA à la prochaine analyse.</div>
          </div>
        </div>
        <?php };
      ?>

      <?php if (empty($docs)): ?>
      <div class="rs-card" style="text-align:center;padding:40px;color:var(--muted);">
        <div style="font-size:40px;margin-bottom:12px;">📂</div>
        <div style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:6px;">Aucun document</div>
        <div style="font-size:13px;">Ajoutez votre Kbis, statuts, carte T, RCP, garantie financière…</div>
      </div>
      <?php else: ?>
      <?php foreach ($docCatLabels as $catKey => $catLabel):
        if (empty($docsByCat[$catKey])) continue; ?>
      <div class="rs-card">
        <div class="rs-card-title"><?= h($catLabel) ?></div>
        <div style="margin-top:14px;">
        <?php foreach ($docsByCat[$catKey] as $doc):
          $ext  = strtolower(pathinfo($doc['nom_fichier'], PATHINFO_EXTENSION));
          $icon = match($ext) {
            'pdf'            => '📄',
            'jpg','jpeg','png','webp' => '🖼️',
            'doc','docx'     => '📝',
            default          => '📎',
          };
          $taille = $doc['taille_octets'] > 1024*1024
            ? round($doc['taille_octets'] / 1024 / 1024, 1) . ' Mo'
            : round($doc['taille_octets'] / 1024) . ' Ko';

          // Statut expiration
          $expiryHtml = '';
          if ($doc['date_expiration']) {
            $exp   = new DateTime($doc['date_expiration']);
            $now   = new DateTime();
            $diff  = (int)$now->diff($exp)->days;
            $isPast = $exp < $now;
            $cls  = $isPast ? 'expired' : ($diff <= 60 ? 'warn' : 'ok');
            $txt  = $isPast
              ? '✕ Expiré le ' . $exp->format('d/m/Y')
              : ($diff <= 60 ? '⚠ Expire le ' . $exp->format('d/m/Y') : '✓ Valide jusqu\'au ' . $exp->format('d/m/Y'));
            $expiryHtml = '<span class="rs-doc-expiry ' . $cls . '">' . h($txt) . '</span>';
          }
        ?>
        <div class="rs-doc-row">
          <span class="rs-doc-icon"><?= $icon ?></span>
          <div class="rs-doc-info">
            <div class="rs-doc-name"><?= h($doc['titre'] ?: $doc['nom_fichier']) ?></div>
            <div class="rs-doc-meta">
              <?= h($taille) ?> · Ajouté le <?= (new DateTime($doc['date_creation']))->format('d/m/Y') ?>
              <?php if ($doc['description']): ?> · <?= h($doc['description']) ?><?php endif; ?>
            </div>
          </div>
          <?= $expiryHtml ?>
          <div class="rs-doc-actions">
            <a href="<?= h($doc['chemin_fichier']) ?>" target="_blank" class="rs-doc-btn">👁 Voir</a>
            <a href="<?= h($doc['chemin_fichier']) ?>" download class="rs-doc-btn">⬇ Télécharger</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce document ?');">
              <?= csrf_field('rh_societe') ?>
              <input type="hidden" name="action"       value="delete_doc">
              <input type="hidden" name="active_tab"   value="documents">
              <input type="hidden" name="doc_id"       value="<?= (int)$doc['id'] ?>">
              <button type="submit" class="rs-doc-btn danger">✕</button>
            </form>
          </div>
        </div>
        <?php $renderAnalysisBlock($doc); ?>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php // Autres docs sans catégorie connue
      $autresDocs = $docsByCat['autre'] ?? [];
      $knownKeys = array_keys($docCatLabels);
      foreach ($docsByCat as $cat => $catDocs) {
          if (!in_array($cat, $knownKeys, true)) $autresDocs = array_merge($autresDocs, $catDocs);
      }
      if (!empty($autresDocs)): ?>
      <div class="rs-doc-cat-title">Autres documents</div>
      <?php foreach ($autresDocs as $doc): ?>
      <div class="rs-doc-row">
        <span class="rs-doc-icon">📎</span>
        <div class="rs-doc-info">
          <div class="rs-doc-name"><?= h($doc['titre'] ?: $doc['nom_fichier']) ?></div>
          <div class="rs-doc-meta">Ajouté le <?= (new DateTime($doc['date_creation']))->format('d/m/Y') ?></div>
        </div>
        <div class="rs-doc-actions">
          <a href="<?= h($doc['chemin_fichier']) ?>" target="_blank" class="rs-doc-btn">👁 Voir</a>
          <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ?');">
            <?= csrf_field('rh_societe') ?>
            <input type="hidden" name="action"     value="delete_doc">
            <input type="hidden" name="active_tab" value="documents">
            <input type="hidden" name="doc_id"     value="<?= (int)$doc['id'] ?>">
            <button type="submit" class="rs-doc-btn danger">✕</button>
          </form>
        </div>
      </div>
      <?php $renderAnalysisBlock($doc); ?>
      <?php endforeach; endif; ?>

      <?php endif; // /if empty docs ?>


      <!-- FORMULAIRE UPLOAD -->
      <div class="rs-card" style="margin-top:24px;">
        <div class="rs-card-title">Ajouter un document</div>
        <div class="rs-card-desc">PDF, JPG, PNG, WEBP, DOC, DOCX — 15 Mo max par fichier.</div>

        <form method="post" enctype="multipart/form-data" id="uploadForm">
          <?= csrf_field('rh_societe') ?>
          <input type="hidden" name="action"     value="upload_doc">
          <input type="hidden" name="active_tab" value="documents">

          <!-- Zone drop -->
          <div class="rs-upload-zone" id="uploadZone"
               ondragover="event.preventDefault();this.classList.add('drag-over')"
               ondragleave="this.classList.remove('drag-over')"
               ondrop="handleDocDrop(event)"
               onclick="document.getElementById('docFileInput').click()">
            <div class="rs-upload-icon">📎</div>
            <div class="rs-upload-text">
              Glissez un fichier ici ou <strong>cliquez pour parcourir</strong><br>
              <span style="font-size:12px;">PDF · JPG · PNG · WEBP · DOC · DOCX — 15 Mo max</span>
            </div>
          </div>
          <input type="file" id="docFileInput" name="doc_file" style="display:none"
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                 onchange="showUploadMeta(this)">

          <!-- Métadonnées upload (masquées jusqu'au choix de fichier) -->
          <div class="rs-upload-form" id="uploadMeta" style="display:none;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
              <span style="font-size:24px;" id="uploadFileIcon">📄</span>
              <div>
                <div style="font-weight:600;font-size:14px;" id="uploadFileName">—</div>
                <div style="font-size:12px;color:var(--muted);" id="uploadFileSize">—</div>
              </div>
              <button type="button" onclick="clearUpload()" style="margin-left:auto;background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px;" title="Annuler">✕</button>
            </div>

            <div class="rs-grid">
              <div class="rs-field">
                <label class="rs-label">Catégorie</label>
                <select name="doc_categorie" class="rs-select">
                  <option value="">— Non catégorisé —</option>
                  <option value="kbis">Kbis</option>
                  <option value="statuts">Statuts de la société</option>
                  <option value="carte_t">Carte T (carte professionnelle)</option>
                  <option value="garantie">Garantie financière</option>
                  <option value="assurance_rcp">Assurance RCP</option>
                  <option value="rib">RIB / IBAN</option>
                  <option value="contrat">Contrat</option>
                  <option value="autre">Autre</option>
                </select>
              </div>
              <div class="rs-field">
                <label class="rs-label">Titre du document</label>
                <input type="text" name="doc_titre" class="rs-input" id="uploadDocTitre"
                       placeholder="ex. Kbis 2024, RCP AXA 2025…">
              </div>
              <div class="rs-field">
                <label class="rs-label">Date d'expiration</label>
                <input type="date" name="doc_expiration" class="rs-input"
                       style="color-scheme:light;">
                <span class="rs-hint">Optionnel — une alerte s'affichera 60 jours avant</span>
              </div>
              <div class="rs-field">
                <label class="rs-label">Note / description</label>
                <input type="text" name="doc_description" class="rs-input"
                       placeholder="Commentaire optionnel…">
              </div>
            </div>

            <div class="rs-save-row" style="border:none;margin-top:16px;">
              <button type="button" onclick="clearUpload()" class="rs-btn-sec">Annuler</button>
              <button type="submit" class="rs-btn-save">⬆ Envoyer le document</button>
            </div>
          </div>
        </form>
      </div>

    </div><!-- /panel-documents -->


    <!-- ══════════════ ONGLET 7 — AGENCES ══════════════ -->
    <div class="rs-panel <?= $activeTab === 'agences' ? 'active' : '' ?>" id="panel-agences">

      <!-- Sous-nav agences -->
      <div class="ag-subnav" id="agSubnav">
        <?php foreach ($agences as $i => $ag): ?>
        <button
          class="ag-subtab <?= ($agGoto === 'ag-'.(int)$ag['id'] || ($agGoto === '' && $i === 0)) ? 'active' : '' ?>"
          data-agtab="ag-<?= (int)$ag['id'] ?>"
          onclick="switchAgTab('ag-<?= (int)$ag['id'] ?>')"
          type="button"
        >
          <span class="ag-subtab-dot <?= $ag['actif'] ? 'dot-actif' : 'dot-inactif' ?>"></span>
          <?= h($ag['nom_agence']) ?>
          <?php if ($ag['type_etab'] === 'principal'): ?>
          <span class="ag-subtab-badge">Siège</span>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
        <button class="ag-subtab ag-subtab-new <?= empty($agences) ? 'active' : '' ?>" data-agtab="ag-new" onclick="switchAgTab('ag-new')" type="button">
          + Nouvelle agence
        </button>
      </div>

      <!-- Panels par agence -->
      <?php foreach ($agences as $i => $ag): ?>
      <div class="ag-panel <?= ($agGoto === 'ag-'.(int)$ag['id'] || ($agGoto === '' && $i === 0)) ? 'active' : '' ?>" id="ag-<?= (int)$ag['id'] ?>">
        <form method="post" class="ag-form">
          <?= csrf_field('rh_societe') ?>
          <input type="hidden" name="action"      value="update_agence">
          <input type="hidden" name="active_tab"  value="agences">
          <input type="hidden" name="ag_id"       value="<?= (int)$ag['id'] ?>">

          <!-- En-tête agence -->
          <div class="ag-form-header">
            <div>
              <div class="ag-form-title"><?= h($ag['nom_agence']) ?></div>
              <?php if ($ag['ville']): ?><div class="ag-form-sub"><?= h($ag['ville']) ?></div><?php endif; ?>
            </div>
            <label class="ag-toggle">
              <input type="checkbox" name="ag_actif" value="1" <?= $ag['actif'] ? 'checked' : '' ?> onchange="this.form.submit()">
              <span class="ag-toggle-track"></span>
              <span class="ag-toggle-label"><?= $ag['actif'] ? 'Active' : 'Inactive' ?></span>
            </label>
          </div>

          <div class="ag-grid">

            <!-- ── Identité ── -->
            <div class="rs-card ag-card">
              <div class="rs-card-title">Identité</div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Nom de l'agence *</label>
                  <input type="text" name="ag_nom_agence" value="<?= h($ag['nom_agence']) ?>" required>
                </div>
                <div class="rs-field">
                  <label>Nom commercial</label>
                  <input type="text" name="ag_nom_commercial" value="<?= h($ag['nom_commercial'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Code agence</label>
                  <input type="text" name="ag_code_agence" value="<?= h($ag['code_agence'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Type d'agence</label>
                  <input type="text" name="ag_type_agence" placeholder="Ex: franchisé, succursale…" value="<?= h($ag['type_agence'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Type d'établissement</label>
                  <select name="ag_type_etab">
                    <option value="principal"   <?= ($ag['type_etab'] ?? '') === 'principal'   ? 'selected' : '' ?>>Siège / Principal</option>
                    <option value="secondaire"  <?= ($ag['type_etab'] ?? 'secondaire') === 'secondaire' ? 'selected' : '' ?>>Secondaire</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- ── Activités ── -->
            <div class="rs-card ag-card">
              <div class="rs-card-title">Activités exercées</div>
              <div class="ag-activites">
                <?php
                $activitesAg = [
                    'transaction_active' => 'Transaction',
                    'location_active'    => 'Location',
                    'gestion_active'     => 'Gestion locative',
                    'syndic_active'      => 'Syndic',
                    'neuf_active'        => 'Neuf / VEFA',
                ];
                foreach ($activitesAg as $field => $label):
                ?>
                <label class="ag-act-chip <?= $ag[$field] ? 'checked' : '' ?>">
                  <input type="checkbox" name="ag_<?= $field ?>" value="1" <?= $ag[$field] ? 'checked' : '' ?> onchange="toggleChip(this)">
                  <?= h($label) ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- ── Adresse ── -->
            <div class="rs-card ag-card">
              <div class="rs-card-title">Adresse</div>
              <div class="rs-field-row">
                <div class="rs-field rs-field-full">
                  <label>Adresse ligne 1</label>
                  <input type="text" name="ag_adresse_1" value="<?= h($ag['adresse_1'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field rs-field-full">
                  <label>Adresse ligne 2</label>
                  <input type="text" name="ag_adresse_2" value="<?= h($ag['adresse_2'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Code postal</label>
                  <input type="text" name="ag_code_postal" value="<?= h($ag['code_postal'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Ville</label>
                  <input type="text" name="ag_ville" value="<?= h($ag['ville'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Pays</label>
                  <input type="text" name="ag_pays" value="<?= h($ag['pays'] ?? 'France') ?>">
                </div>
              </div>
            </div>

            <!-- ── Contact ── -->
            <div class="rs-card ag-card">
              <div class="rs-card-title">Contact</div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Téléphone</label>
                  <input type="tel" name="ag_telephone" value="<?= h($ag['telephone'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Email public</label>
                  <input type="email" name="ag_email" value="<?= h($ag['email'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Email contact interne</label>
                  <input type="email" name="ag_email_contact" value="<?= h($ag['email_contact'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Email facturation</label>
                  <input type="email" name="ag_email_facturation" value="<?= h($ag['email_facturation'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field rs-field-full">
                  <label>Site web</label>
                  <input type="url" name="ag_site_web" placeholder="https://" value="<?= h($ag['site_web'] ?? '') ?>">
                </div>
              </div>
            </div>

            <!-- ── Juridique ── -->
            <div class="rs-card ag-card">
              <div class="rs-card-title">Immatriculation</div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>SIRET</label>
                  <input type="text" name="ag_siret" value="<?= h($ag['siret'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>SIREN / SIRET siège</label>
                  <input type="text" name="ag_siren_siret" value="<?= h($ag['siren_siret'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>RCS</label>
                  <input type="text" name="ag_rcs" value="<?= h($ag['rcs'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>TVA intracommunautaire</label>
                  <input type="text" name="ag_tva_intracom" value="<?= h($ag['tva_intracom'] ?? '') ?>">
                </div>
              </div>
            </div>

            <!-- ── Bancaire ── -->
            <div class="rs-card ag-card">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px;">
                <div>
                  <div class="rs-card-title" style="margin:0;">Coordonnées bancaires</div>
                  <div class="rs-card-desc" style="margin-top:2px;">
                    Uploadez un RIB pour remplir automatiquement les 4 champs via IA.
                  </div>
                </div>
                <div>
                  <input type="file" id="rib_file_<?= (int)$ag['id'] ?>" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none;"
                         onchange="ribAnalyze(<?= (int)$ag['id'] ?>, this)">
                  <button type="button"
                          id="ribBtn_<?= (int)$ag['id'] ?>"
                          onclick="document.getElementById('rib_file_<?= (int)$ag['id'] ?>').click()"
                          style="background:linear-gradient(135deg,#6a4ca8,#8a6cc8);color:#fff;border:none;padding:8px 14px;border-radius:8px;font-family:'Sora',sans-serif;font-size:12px;font-weight:700;cursor:pointer;box-shadow:0 3px 8px rgba(106,76,168,0.3);white-space:nowrap;">
                    📎 Scanner un RIB
                  </button>
                </div>
              </div>
              <div id="ribStatus_<?= (int)$ag['id'] ?>" style="display:none;font-size:11px;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-family:'DM Mono',monospace;"></div>

              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Titulaire du compte</label>
                  <input type="text" name="ag_titulaire_compte" value="<?= h($ag['titulaire_compte'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>Nom de la banque</label>
                  <input type="text" name="ag_banque_nom" value="<?= h($ag['banque_nom'] ?? '') ?>">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field rs-field-full">
                  <label>IBAN</label>
                  <input type="text" name="ag_iban" placeholder="FR76 …" value="<?= h($ag['iban'] ?? '') ?>">
                </div>
                <div class="rs-field">
                  <label>BIC / SWIFT</label>
                  <input type="text" name="ag_bic" value="<?= h($ag['bic'] ?? '') ?>">
                </div>
              </div>
            </div>

          </div><!-- /.ag-grid -->

        </form>
      </div><!-- /.ag-panel -->
      <?php endforeach; ?>

      <!-- Panel nouvelle agence -->
      <div class="ag-panel <?= empty($agences) ? 'active' : '' ?>" id="ag-new">
        <form method="post" class="ag-form">
          <?= csrf_field('rh_societe') ?>
          <input type="hidden" name="action"     value="create_agence">
          <input type="hidden" name="active_tab" value="agences">

          <div class="ag-form-header">
            <div class="ag-form-title">Nouvelle agence</div>
          </div>

          <div class="ag-grid">

            <div class="rs-card ag-card">
              <div class="rs-card-title">Identité</div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Nom de l'agence *</label>
                  <input type="text" name="ag_nom_agence" required placeholder="Ex : Agence Lyon Centre">
                </div>
                <div class="rs-field">
                  <label>Nom commercial</label>
                  <input type="text" name="ag_nom_commercial">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Code agence</label>
                  <input type="text" name="ag_code_agence">
                </div>
                <div class="rs-field">
                  <label>Type d'agence</label>
                  <input type="text" name="ag_type_agence" placeholder="Ex: franchisé, succursale…">
                </div>
                <div class="rs-field">
                  <label>Type d'établissement</label>
                  <select name="ag_type_etab">
                    <option value="secondaire">Secondaire</option>
                    <option value="principal">Siège / Principal</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="rs-card ag-card">
              <div class="rs-card-title">Activités exercées</div>
              <div class="ag-activites">
                <label class="ag-act-chip checked">
                  <input type="checkbox" name="ag_transaction_active" value="1" checked onchange="toggleChip(this)"> Transaction
                </label>
                <label class="ag-act-chip checked">
                  <input type="checkbox" name="ag_location_active" value="1" checked onchange="toggleChip(this)"> Location
                </label>
                <label class="ag-act-chip">
                  <input type="checkbox" name="ag_gestion_active" value="1" onchange="toggleChip(this)"> Gestion locative
                </label>
                <label class="ag-act-chip">
                  <input type="checkbox" name="ag_syndic_active" value="1" onchange="toggleChip(this)"> Syndic
                </label>
                <label class="ag-act-chip">
                  <input type="checkbox" name="ag_neuf_active" value="1" onchange="toggleChip(this)"> Neuf / VEFA
                </label>
              </div>
            </div>

            <div class="rs-card ag-card">
              <div class="rs-card-title">Adresse</div>
              <div class="rs-field-row">
                <div class="rs-field rs-field-full">
                  <label>Adresse ligne 1</label>
                  <input type="text" name="ag_adresse_1">
                </div>
              </div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Code postal</label>
                  <input type="text" name="ag_code_postal">
                </div>
                <div class="rs-field">
                  <label>Ville</label>
                  <input type="text" name="ag_ville">
                </div>
                <div class="rs-field">
                  <label>Pays</label>
                  <input type="text" name="ag_pays" value="France">
                </div>
              </div>
            </div>

            <div class="rs-card ag-card">
              <div class="rs-card-title">Contact</div>
              <div class="rs-field-row">
                <div class="rs-field">
                  <label>Téléphone</label>
                  <input type="tel" name="ag_telephone">
                </div>
                <div class="rs-field">
                  <label>Email</label>
                  <input type="email" name="ag_email">
                </div>
              </div>
            </div>

          </div><!-- /.ag-grid -->

          <div class="rs-save-row">
            <button type="submit" class="rs-btn-save">➕ Créer cette agence</button>
          </div>
        </form>
      </div><!-- /#ag-new -->

    </div><!-- /panel-agences -->

    <?php if ($roleId === 1 || $isSuperAdmin): ?>
    <div class="rs-panel <?= $activeTab === 'modules' ? 'active' : '' ?>" id="panel-modules">
      <div class="rs-card">
        <div class="rs-card-title">Modules activés pour cette société</div>
        <div class="rs-card-sub">Cochez les modules auxquels les collaborateurs de cette société auront accès. Les utilisateurs ne verront que les sections activées dans leur sidebar.</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px;">

          <label style="display:flex;align-items:center;gap:12px;padding:16px;background:<?= (int)($soc['module_rh'] ?? 0) ? '#f0fdf4' : '#fafafa' ?>;border:1px solid <?= (int)($soc['module_rh'] ?? 0) ? '#86efac' : '#e5e5e5' ?>;border-radius:12px;cursor:pointer;">
            <input type="checkbox" name="module_rh" value="1" form="mainForm" <?= (int)($soc['module_rh'] ?? 0) ? 'checked' : '' ?> style="width:20px;height:20px;">
            <div>
              <div style="font-weight:600;font-size:14px;">👥 RH</div>
              <div style="font-size:12px;color:#666;">Profil, congés, salaires, documents, entretiens</div>
            </div>
          </label>

          <label style="display:flex;align-items:center;gap:12px;padding:16px;background:<?= (int)($soc['module_agency'] ?? 0) ? '#f0fdf4' : '#fafafa' ?>;border:1px solid <?= (int)($soc['module_agency'] ?? 0) ? '#86efac' : '#e5e5e5' ?>;border-radius:12px;cursor:pointer;">
            <input type="checkbox" name="module_agency" value="1" form="mainForm" <?= (int)($soc['module_agency'] ?? 0) ? 'checked' : '' ?> style="width:20px;height:20px;">
            <div>
              <div style="font-weight:600;font-size:14px;">🏠 Agency</div>
              <div style="font-size:12px;color:#666;">Biens, annonces, mandats, diffusion portails</div>
            </div>
          </label>

          <label style="display:flex;align-items:center;gap:12px;padding:16px;background:<?= (int)($soc['module_syndic'] ?? 0) ? '#f0fdf4' : '#fafafa' ?>;border:1px solid <?= (int)($soc['module_syndic'] ?? 0) ? '#86efac' : '#e5e5e5' ?>;border-radius:12px;cursor:pointer;">
            <input type="checkbox" name="module_syndic" value="1" form="mainForm" <?= (int)($soc['module_syndic'] ?? 0) ? 'checked' : '' ?> style="width:20px;height:20px;">
            <div>
              <div style="font-weight:600;font-size:14px;">🏢 Syndic</div>
              <div style="font-size:12px;color:#666;">Immeubles, assemblées, registres, copropriété</div>
            </div>
          </label>

          <label style="display:flex;align-items:center;gap:12px;padding:16px;background:<?= (int)($soc['module_gestion'] ?? 0) ? '#f0fdf4' : '#fafafa' ?>;border:1px solid <?= (int)($soc['module_gestion'] ?? 0) ? '#86efac' : '#e5e5e5' ?>;border-radius:12px;cursor:pointer;">
            <input type="checkbox" name="module_gestion" value="1" form="mainForm" <?= (int)($soc['module_gestion'] ?? 0) ? 'checked' : '' ?> style="width:20px;height:20px;">
            <div>
              <div style="font-weight:600;font-size:14px;">📊 Gestion locative</div>
              <div style="font-size:12px;color:#666;">CRG, patrimoine, baux, quittances</div>
            </div>
          </label>

          <label style="display:flex;align-items:center;gap:12px;padding:16px;background:<?= (int)($soc['module_bailleur'] ?? 0) ? '#f0fdf4' : '#fafafa' ?>;border:1px solid <?= (int)($soc['module_bailleur'] ?? 0) ? '#86efac' : '#e5e5e5' ?>;border-radius:12px;cursor:pointer;">
            <input type="checkbox" name="module_bailleur" value="1" form="mainForm" <?= (int)($soc['module_bailleur'] ?? 0) ? 'checked' : '' ?> style="width:20px;height:20px;">
            <div>
              <div style="font-weight:600;font-size:14px;">🏠 Bailleur</div>
              <div style="font-size:12px;color:#666;">Patrimoine, CRG, GED, locataires, encaissements</div>
            </div>
          </label>

        </div>
        <div style="margin-top:24px;text-align:right;">
          <button type="submit" form="mainForm" class="rs-btn primary" style="padding:10px 28px;background:#4a6038;color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer;">💾 Enregistrer les modules</button>
        </div>
      </div>
    </div><!-- /panel-modules -->
    <?php endif; ?>

  </div><!-- /rs-content -->
  </div><!-- /rs-scroll -->
</div><!-- /rs-main -->

<script>
// ── CARTE T — ACTIVITÉS ─────────────────────────────
const ACTIVITE_LABELS = {
  transaction:        'Transaction immobilière',
  gestion:            'Gestion locative',
  syndic:             'Syndic de copropriété',
  location_saisonniere: 'Location saisonnière',
  marchand_listes:    'Marchand de listes',
  viager:             'Viager',
  commerces_fonds:    'Fonds de commerce',
  locaux_commerciaux: 'Locaux commerciaux',
  terrain_neuf:       'VEFA / Promotion',
  expertise:          'Expertise immobilière',
};

function updateActiviteChip(checkbox) {
  const chip = checkbox.closest('.activite-chip');
  if (checkbox.checked) chip.classList.add('checked');
  else chip.classList.remove('checked');
  generateMentionLegale();
}

function generateMentionLegale() {
  const numero = document.querySelector('[name="numero_carte_t"]')?.value?.trim() || '[N° CARTE T]';
  const cci    = document.querySelector('[name="cci_carte_t"]')?.value?.trim()    || '[CCI]';
  const checked = Array.from(document.querySelectorAll('input[name="carte_t_activites[]"]:checked'))
                       .map(cb => ACTIVITE_LABELS[cb.value] || cb.value);

  const el = document.getElementById('mentions-text');
  if (!el) return;

  if (checked.length === 0) {
    el.textContent = '— Sélectionnez au moins une activité —';
    return;
  }

  const activitesStr = checked.join(', ').toLowerCase();
  el.innerHTML = `Carte professionnelle n° <strong>${numero}</strong> — ${activitesStr}.<br>`
    + `Délivrée par la <strong>CCI ${cci}</strong>.`;
}

function updateCarteStatus() {
  const numero = document.querySelector('[name="numero_carte_t"]')?.value?.trim() || '';
  const badgeNumero = document.getElementById('badge-numero');
  if (badgeNumero) {
    if (numero) {
      badgeNumero.className = 'rs-badge ok';
      badgeNumero.textContent = '✓ ' + numero;
    } else {
      badgeNumero.className = 'rs-badge red';
      badgeNumero.textContent = '✕ Numéro non renseigné — obligatoire';
    }
  }

  const expInput = document.querySelector('[name="carte_t_date_expiration"]')?.value;
  const badgeExp = document.getElementById('badge-expiry');
  if (badgeExp && expInput) {
    const exp  = new Date(expInput);
    const now  = new Date();
    const diff = Math.ceil((exp - now) / 86400000);
    if (exp < now) {
      badgeExp.className = 'rs-badge red';
      badgeExp.textContent = '✕ Expirée le ' + exp.toLocaleDateString('fr-FR');
      badgeExp.style.display = '';
    } else if (diff <= 90) {
      badgeExp.className = 'rs-badge warn';
      badgeExp.textContent = '⚠ Expire le ' + exp.toLocaleDateString('fr-FR') + ' (dans ' + diff + ' j.)';
      badgeExp.style.display = '';
    } else {
      badgeExp.className = 'rs-badge ok';
      badgeExp.textContent = '✓ Valide jusqu\'au ' + exp.toLocaleDateString('fr-FR');
      badgeExp.style.display = '';
    }
  }
  generateMentionLegale();
}

// ── ONGLETS ──────────────────────────────────────────
async function switchTab(id) {
  // Auto-save AJAX avant de changer d'onglet (flush si dirty)
  const hiddenInput = document.getElementById('active_tab_hidden');
  if (hiddenInput) hiddenInput.value = id;
  if (window.__autoSave) {
    await window.__autoSave.flushAll();
  }
  _activateTab(id);
}

function _activateTab(id) {
  document.querySelectorAll('.rs-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.rs-tab').forEach(t => t.classList.remove('active'));
  const panel = document.getElementById('panel-' + id);
  const tab   = document.querySelector('.rs-tab[data-tab="' + id + '"]');
  if (panel) panel.classList.add('active');
  if (tab)   tab.classList.add('active');
}

// ── AUTO-REMPLIR SIREN depuis SIRET ─────────────────
function autoFillSiren(siret) {
  const clean = siret.replace(/\D/g, '');
  const sirenEl = document.getElementById('input_siren');
  if (sirenEl && clean.length >= 9) {
    sirenEl.value = clean.substring(0, 9);
  }
}

// ── UPLOAD — affichage métadonnées ──────────────────
function showUploadMeta(input) {
  if (!input.files || input.files.length === 0) return;
  const file    = input.files[0];
  const meta    = document.getElementById('uploadMeta');
  const icon    = document.getElementById('uploadFileIcon');
  const name    = document.getElementById('uploadFileName');
  const size    = document.getElementById('uploadFileSize');
  const titre   = document.getElementById('uploadDocTitre');
  const zone    = document.getElementById('uploadZone');

  const ext = file.name.split('.').pop().toLowerCase();
  const iconMap = { pdf:'📄', jpg:'🖼️', jpeg:'🖼️', png:'🖼️', webp:'🖼️', doc:'📝', docx:'📝' };
  icon.textContent  = iconMap[ext] || '📎';
  name.textContent  = file.name;
  size.textContent  = file.size > 1024*1024
    ? (file.size/1024/1024).toFixed(1) + ' Mo'
    : Math.round(file.size/1024) + ' Ko';

  // Pré-remplir le titre avec le nom du fichier sans extension
  if (titre && titre.value === '') {
    titre.value = file.name.replace(/\.[^/.]+$/, '').replace(/[_-]/g, ' ');
  }

  meta.style.display = '';
  zone.style.display = 'none';
}

function clearUpload() {
  document.getElementById('docFileInput').value = '';
  document.getElementById('uploadMeta').style.display = 'none';
  document.getElementById('uploadZone').style.display = '';
}

function handleDocDrop(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.remove('drag-over');
  const dt = e.dataTransfer;
  if (dt.files && dt.files.length > 0) {
    const input = document.getElementById('docFileInput');
    // Créer un DataTransfer pour assigner les fichiers
    const dt2 = new DataTransfer();
    dt2.items.add(dt.files[0]);
    input.files = dt2.files;
    showUploadMeta(input);
  }
}

// ── Marquer onglets complétés (tab dots) ────────────
function updateTabDots() {
  const checks = {
    juridique:   () => document.querySelector('[name="nom"]')?.value?.trim() !== '',
    // Financier complété = au moins 1 couverture renseignée dans la card
    // "Couvertures détaillées par activité" (marquée data-cov-row).
    financier:   () => document.querySelectorAll('#panel-financier [data-cov-row]').length > 0,
    metier:      () => document.querySelector('[name="numero_carte_t"]')?.value?.trim() !== '',
    coordonnees: () => document.querySelector('[name="adresse_1"]')?.value?.trim() !== '',
    marque:      () => document.querySelector('[name="description"]')?.value?.trim().length > 20,
    documents:   () => document.querySelectorAll('.rs-doc-row').length > 0,
  };
  Object.entries(checks).forEach(([id, fn]) => {
    const tab = document.querySelector('.rs-tab[data-tab="' + id + '"]');
    if (!tab) return;
    try { tab.classList.toggle('done', fn()); } catch(e) {}
  });
}

// ── AGENCES SUB-NAV ─────────────────────────────────
async function switchAgTab(id) {
  // Auto-save AJAX avant de changer d'agence (flush si dirty)
  if (window.__autoSave) {
    await window.__autoSave.flushAll();
  }
  _activateAgTab(id);
}

function _activateAgTab(id) {
  document.querySelectorAll('.ag-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.ag-subtab').forEach(t => t.classList.remove('active'));
  const panel = document.getElementById(id);
  const tab   = document.querySelector('.ag-subtab[data-agtab="' + id + '"]');
  if (panel) panel.classList.add('active');
  if (tab)   tab.classList.add('active');
}

// ── ACTIVITÉS AGENCES CHIPS ──────────────────────────
function toggleChip(cb) {
  const chip = cb.closest('.ag-act-chip');
  if (chip) chip.classList.toggle('checked', cb.checked);
}

// ══════════════════════════════════════════════════════════
// ANALYSE IA DES DOCUMENTS — module
// docAnalyze(id, btn) lance l'analyse, met à jour le badge et recharge
// la page pour rafraîchir les champs société remplis.
// docToggleComment(id) ouvre/ferme la zone de commentaire.
// Le commentaire est sauvegardé à la frappe (debounce 1s) via un POST
// partiel vers le même endpoint d'analyse (avec un flag save_comment).
// ══════════════════════════════════════════════════════════
(function(){
  const CSRF_DOC = '<?= htmlspecialchars(csrf_token("rh_societe"), ENT_QUOTES) ?>';
  // Société actuellement consultée (tient compte de l'override super-admin sa_id).
  // Tous les appels AJAX (analyse, save_doc_comment) doivent embarquer cette
  // valeur pour que l'endpoint utilise la bonne société quand l'admin consulte
  // une société qui n'est pas la sienne.
  const SOC_OVERRIDE = <?= (int)$societeId ?>;

  window.docToggleComment = function(id) {
    const box = document.getElementById('docComment_' + id);
    if (!box) return;
    box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
    if (box.style.display === 'block') {
      box.querySelector('textarea')?.focus();
    }
  };

  // Ouvre/ferme le panneau "Voir les champs" sous un document analysé.
  window.docToggleDetails = function(id) {
    const box = document.getElementById('docDetails_' + id);
    if (!box) return;
    box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
  };

  // Ouvre/ferme le panneau "Historique" sous une ligne de couverture.
  // `key` = "rcp_gestion" ou "garantie_financiere_transaction" etc.
  window.covToggleHistory = function(key) {
    const box = document.getElementById('covHist_' + key);
    if (!box) return;
    box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
  };

  // ── Scanner un RIB et pré-remplir les 4 champs bancaires d'une agence ──
  // Upload du fichier → endpoint d'analyse → remplissage des inputs
  // ag_titulaire_compte / ag_banque_nom / ag_iban / ag_bic. L'auto-save
  // debouncé (déjà en place sur .ag-form) persiste ensuite la ligne.
  window.ribAnalyze = async function(agId, input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    if (file.size > 8 * 1024 * 1024) {
      alert('⚠ Fichier trop volumineux (max 8 Mo)');
      input.value = '';
      return;
    }

    const btn    = document.getElementById('ribBtn_' + agId);
    const status = document.getElementById('ribStatus_' + agId);
    const origLabel = btn ? btn.textContent : '';

    const setStatus = (msg, level) => {
      if (!status) return;
      const palette = {
        loading: 'background:#e0e7ff;color:#3730a3;border-left:3px solid #3730a3;',
        success: 'background:#dcfce7;color:#166534;border-left:3px solid #166534;',
        error:   'background:#fee2e2;color:#991b1b;border-left:3px solid #991b1b;',
      };
      status.style.cssText = 'font-size:11px;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-family:"DM Mono",monospace;display:block;' + (palette[level] || '');
      status.innerHTML = msg;
    };

    if (btn) { btn.disabled = true; btn.textContent = '⏳ Analyse…'; }
    setStatus('⟳ Envoi du RIB à l\'IA…', 'loading');

    try {
      const fd = new FormData();
      fd.append('csrf_token', '<?= htmlspecialchars(csrf_token("rh_societe"), ENT_QUOTES) ?>');
      fd.append('id_agence', String(agId));
      fd.append('soc_override', String(SOC_OVERRIDE));
      fd.append('rib_file', file);

      const r = await fetch('api/agence_rib_analyze.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'Erreur analyse');

      // Cherche les inputs dans le même form d'agence (celui qui contient le
      // bouton RIB). Le file input est dans le form d'agence — on remonte.
      const form = input.closest('form.ag-form');
      if (!form) throw new Error('Formulaire agence introuvable');

      const fieldsMap = {
        'titulaire': 'ag_titulaire_compte',
        'banque':    'ag_banque_nom',
        'iban':      'ag_iban',
        'bic':       'ag_bic',
      };
      const ex = d.extracted || {};
      const filled = [];
      for (const [src, tgt] of Object.entries(fieldsMap)) {
        if (ex[src]) {
          const el = form.querySelector(`[name="${tgt}"]`);
          if (el) {
            el.value = ex[src];
            // Trigger l'auto-save du form (qui écoute 'input')
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            filled.push(tgt.replace('ag_', ''));
            // Highlight visuel éphémère
            el.style.transition = 'background-color 1.5s';
            el.style.backgroundColor = '#dcfce7';
            setTimeout(() => { el.style.backgroundColor = ''; }, 2000);
          }
        }
      }

      if (filled.length === 0) {
        setStatus('⚠ Aucun champ n\'a pu être extrait du RIB — vérifiez que le fichier est lisible', 'error');
      } else {
        setStatus('✓ ' + filled.length + ' champ(s) rempli(s) : ' + filled.join(', ') + ' — sauvegarde auto en cours', 'success');
        setTimeout(() => { if (status) status.style.display = 'none'; }, 5000);
      }
    } catch (err) {
      setStatus('❌ ' + (err.message || 'Erreur réseau'), 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = origLabel; }
      input.value = ''; // reset file input pour permettre re-upload du même fichier
    }
  };

  window.docAnalyze = async function(docId, btn) {
    const analysisEl = btn.closest('.rs-doc-analysis');
    const pill       = analysisEl?.querySelector('.rs-doc-analysis-pill');
    const commentTa  = analysisEl?.querySelector('textarea[data-doc-comment]');
    const comment    = commentTa ? commentTa.value.trim() : '';

    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Analyse en cours…';
    if (pill) {
      pill.style.background = '#e0e7ff';
      pill.style.color      = '#3730a3';
      pill.style.border     = '1px solid #3730a355';
      pill.textContent      = '⟳ Analyse avec ChatGPT…';
    }

    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF_DOC);
      fd.append('doc_id', String(docId));
      fd.append('soc_override', String(SOC_OVERRIDE));
      if (comment) fd.append('comment', comment);

      const r = await fetch('api/societe_doc_analyze.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'Erreur analyse');

      // Feedback immédiat sur le pill
      if (pill) {
        if (d.fields_filled > 0) {
          pill.style.background = '#dcfce7';
          pill.style.color      = '#166534';
          pill.style.border     = '1px solid #16653455';
          pill.textContent      = '✓ Analysé · ' + d.fields_filled + ' champ' + (d.fields_filled > 1 ? 's' : '') + ' rempli' + (d.fields_filled > 1 ? 's' : '');
        } else {
          pill.style.background = '#f0ebe3';
          pill.style.color      = '#8a8680';
          pill.style.border     = '1px solid #8a868055';
          pill.textContent      = 'ℹ Analysé · aucun nouveau champ';
        }
      }

      // Toast global pour détails
      const appliedCount = Object.keys(d.applied || {}).length;
      const skippedCount = Object.keys(d.skipped || {}).length;
      const msg =
        '<strong>✓ Analyse terminée</strong><br>'
        + appliedCount + ' champ(s) rempli(s) · '
        + skippedCount + ' déjà présent(s)';
      if (typeof showDocToast === 'function') showDocToast(msg, 'success');

      // Recharge la page après 1,5s pour faire apparaître les valeurs dans
      // les onglets Identité/Coordonnées/etc. sans effacer l'onglet courant.
      setTimeout(() => window.location.reload(), 1500);
    } catch (e) {
      if (pill) {
        pill.style.background = '#fee2e2';
        pill.style.color      = '#991b1b';
        pill.style.border     = '1px solid #991b1b55';
        pill.textContent      = '⚠ ' + (e.message || 'Erreur');
      }
      btn.disabled = false;
      btn.innerHTML = origText;
      if (typeof showDocToast === 'function') showDocToast('❌ ' + (e.message || 'Erreur'), 'error');
    }
  };

  // Toast minimal (ré-utilise le badge autoSave s'il existe, sinon crée)
  window.showDocToast = function(msg, level) {
    let el = document.getElementById('docAnalyzeToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'docAnalyzeToast';
      el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:10000;padding:14px 18px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.25);font-family:Sora,sans-serif;font-size:13px;max-width:380px;line-height:1.4;transition:opacity .3s;';
      document.body.appendChild(el);
    }
    const palette = {
      success: 'background:#edf5e7;color:#2a4020;border-left:4px solid #7a9060;',
      error:   'background:#fef2f2;color:#7a2020;border-left:4px solid #a85858;',
    };
    el.style.cssText += palette[level] || palette.success;
    el.innerHTML = msg;
    el.style.opacity = '1';
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 5000);
  };

  // Auto-save du commentaire (debounce 800ms)
  const commentTimers = new Map();
  document.addEventListener('input', (ev) => {
    const ta = ev.target.closest('[data-doc-comment]');
    if (!ta) return;
    const docId = ta.dataset.docComment;
    if (commentTimers.has(docId)) clearTimeout(commentTimers.get(docId));
    commentTimers.set(docId, setTimeout(async () => {
      try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_DOC);
        fd.append('action', 'save_doc_comment');
        fd.append('doc_id', docId);
        fd.append('comment', ta.value);
        // Propage l'override super-admin pour hit le bon scope dans societe.php
        fd.append('ajax', '1');
        // Ajoute sa_id en query-string si super-admin consulte une autre société
        const url = window.location.pathname + window.location.search;
        await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      } catch (e) { /* silencieux */ }
    }, 800));
  });
})();

// ══════════════════════════════════════════════════════════
// AUTO-SAVE — module global
// Ecoute les événements input/change sur #mainForm et .ag-form,
// debounce 800ms, POST AJAX vers la même URL avec le flag ajax=1.
// Indicateur visuel flottant en haut à droite de la page.
// ══════════════════════════════════════════════════════════
(function(){
  const DEBOUNCE_MS = 800;
  const BADGE_ID    = 'autoSaveBadge';
  // Timers debouncés et promesses en attente, indexés par form
  const timers   = new WeakMap(); // form → timeout id
  const pending  = new Map();     // form → Promise (save en cours)

  function ensureBadge() {
    let el = document.getElementById(BADGE_ID);
    if (el) return el;
    el = document.createElement('div');
    el.id = BADGE_ID;
    el.style.cssText = [
      'position:fixed','top:80px','right:24px','z-index:9998',
      'padding:9px 14px','border-radius:999px',
      "font-family:'Sora',sans-serif",'font-size:11px','font-weight:700',
      'letter-spacing:0.04em','box-shadow:0 6px 20px rgba(0,0,0,0.15)',
      'background:#f5f1ea','color:#8a8680','border:1px solid #e2dcd2',
      'transition:all .25s','pointer-events:none','display:none'
    ].join(';');
    document.body.appendChild(el);
    return el;
  }

  function setStatus(status) {
    const el = ensureBadge();
    el.style.display = '';
    const states = {
      idle:    { bg:'#f5f1ea', col:'#a8a49e', txt:'' },
      dirty:   { bg:'#fef3c7', col:'#92400e', txt:'● Modifications en attente…' },
      saving:  { bg:'#e0e7ff', col:'#3730a3', txt:'⟳ Enregistrement…' },
      saved:   { bg:'#dcfce7', col:'#166534', txt:'✓ Enregistré' },
      error:   { bg:'#fee2e2', col:'#991b1b', txt:'⚠ Erreur — vérifiez votre connexion' },
    };
    const s = states[status] || states.idle;
    el.style.background = s.bg;
    el.style.color      = s.col;
    el.style.border     = '1px solid ' + s.col + '33';
    el.textContent      = s.txt;
    if (!s.txt) el.style.display = 'none';
    if (status === 'saved') {
      setTimeout(() => {
        if (el.textContent === s.txt) el.style.display = 'none';
      }, 2200);
    }
  }

  async function doSave(form) {
    // Construit un FormData à partir du form + flag ajax
    const fd = new FormData(form);
    fd.set('ajax', '1');
    setStatus('saving');
    try {
      // Préserve la query string (sa_id pour super-admin qui consulte une
      // autre société) — sinon le handler perdrait l'override et tenterait
      // de sauver sur la société "réelle" du user.
      const url = window.location.pathname + window.location.search;
      const r = await fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'Erreur serveur');
      setStatus('saved');
      form.dataset.dirty = '';
    } catch (e) {
      console.error('[auto-save]', e);
      setStatus('error');
    } finally {
      pending.delete(form);
    }
  }

  function scheduleSave(form) {
    form.dataset.dirty = '1';
    setStatus('dirty');
    if (timers.has(form)) clearTimeout(timers.get(form));
    const t = setTimeout(() => {
      timers.delete(form);
      const p = doSave(form);
      pending.set(form, p);
    }, DEBOUNCE_MS);
    timers.set(form, t);
  }

  function handleEvent(ev) {
    const target = ev.target;
    if (!target || !target.form) return;
    const form = target.form;
    // On n'auto-save que mainForm et les ag-form d'édition (pas create_agence
    // qui doit rester en soumission explicite pour ne pas créer des lignes vides)
    if (form.id !== 'mainForm' && !(form.classList.contains('ag-form') && form.querySelector('input[name="action"]')?.value === 'update_agence')) {
      return;
    }
    // Ignore les inputs de recherche ou file / submit / button
    if (['file','submit','button','reset'].includes(target.type)) return;
    scheduleSave(form);
  }

  // Flush : force la sauvegarde immédiate des forms dirty + attend les pending
  async function flushAll() {
    // Déclenche un save immédiat pour chaque timer en attente
    document.querySelectorAll('form#mainForm, form.ag-form').forEach(form => {
      if (timers.has(form)) {
        clearTimeout(timers.get(form));
        timers.delete(form);
        const p = doSave(form);
        pending.set(form, p);
      }
    });
    // Attend toutes les promesses en cours
    if (pending.size > 0) {
      await Promise.allSettled([...pending.values()]);
    }
  }

  // Expose
  window.__autoSave = { flushAll, scheduleSave, setStatus };

  // Attache les listeners
  document.addEventListener('input',  handleEvent);
  document.addEventListener('change', handleEvent);

  // Flush avant quitter la page si modifs en cours
  window.addEventListener('beforeunload', (ev) => {
    const hasDirty = document.querySelector('form[data-dirty="1"]');
    if (hasDirty) {
      ev.preventDefault();
      ev.returnValue = '';
    }
  });
})();

document.addEventListener('DOMContentLoaded', () => {
  updateTabDots();
  setInterval(updateTabDots, 3000);
  generateMentionLegale();
  updateCarteStatus();
});
</script>
</body>
</html>
<?php

// ── Helper : bouton Enregistrer ───────────────────────────────
