<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mailer.php';

$pageTitle = 'Créer un compte agence - Ma Box Agency';
$bodyClass = 'theme-agency-signup';
$robots = 'noindex, nofollow';
$includeGooglePlaces = true;
$includeGoogleMapsJs = true;

$errors = [];
$warnings = [];
$success = '';
$debugLink = '';
$view = 'form';
$draftKey = 'agency_signup_draft';
$draft = $_SESSION[$draftKey] ?? null;
$recapData = [];
$missingDocsData = [];

$captchaWords = [
    'maison', 'agence', 'immobilier', 'mandat', 'appartement',
    'location', 'vente', 'copro', 'visite', 'portail',
];

if (!isset($_SESSION['signup_captcha']) || (!is_post() && isset($_GET['refresh']))) {
    $word = $captchaWords[array_rand($captchaWords)];
    $_SESSION['signup_captcha'] = $word;
}

$captchaWord = (string)($_SESSION['signup_captcha'] ?? '');

$slugify = static function (string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    $value = preg_replace('~[^\pL\d]+~u', '-', $value);
    $value = trim((string)$value, '-');
    $value = preg_replace('~-+~', '-', (string)$value);
    return $value !== '' ? $value : null;
};

if (is_post()) {
    $action = (string)post('action', 'prepare');

    if ($action === 'confirm') {
        verify_csrf('agence_inscription_confirm');

        if (empty($draft['data'])) {
            $errors[] = 'Votre session a expiré. Merci de recommencer.';
            $view = 'form';
        } else {
            $data = $draft['data'];
            $uploads = $draft['uploads'] ?? [];
            $missingDocs = $draft['missing_docs'] ?? [];

            if (!empty($missingDocs)) {
                $warnings[] = 'La diffusion d’annonce ne sera pas possible sans les documents (logo, KBIS, carte pro). Vous pourrez les ajouter plus tard.';
            }

            $moved = [];
            $missingTables = [];
            $requiredTables = ['societes', 'agences', 'users', 'email_verifications', 'roles'];
            if (!empty($uploads)) {
                $requiredTables[] = 'agence_documents';
            }

            foreach ($requiredTables as $table) {
                try {
                    $check = $pdo->prepare('SHOW TABLES LIKE :table');
                    $check->execute([':table' => $table]);
                    if (!$check->fetchColumn()) {
                        $missingTables[] = $table;
                    }
                } catch (Throwable $e) {
                    $missingTables[] = $table;
                }
            }

            if (!empty($missingTables)) {
                $errors[] = 'Configuration base de données incomplète. Tables manquantes : ' . implode(', ', $missingTables) . '.';
                if (in_array('email_verifications', $missingTables, true)) {
                    $errors[] = 'Appliquez le patch `schema_patch_002_agence_portail.sql`.';
                }
                if (in_array('agence_documents', $missingTables, true)) {
                    $errors[] = 'Appliquez le patch `schema_patch_003_agence_documents.sql`.';
                }
                if (APP_DEBUG) {
                    $errors[] = 'Mode debug : vérifiez la base active et les migrations.';
                }
                $view = 'confirm';
            } else {
                $roleId = 2;
                $roleCheck = $pdo->prepare('SELECT id FROM roles WHERE id = :id LIMIT 1');
                $roleCheck->execute([':id' => $roleId]);
                if (!$roleCheck->fetchColumn()) {
                    $errors[] = 'Le rôle agence (id 2) est manquant dans la table roles.';
                    $errors[] = 'Ajoutez ce rôle ou ajustez l’id_role utilisé pour les agences.';
                    $view = 'confirm';
                } else {
                    try {
                $pdo->beginTransaction();

                $insertSociete = $pdo->prepare("
                    INSERT INTO societes
                        (nom, raison_sociale, siret, tva_intracom, adresse_1, adresse_2, code_postal, ville, pays, telephone, email, site_web, slug, actif, date_creation, date_modification)
                    VALUES
                        (:nom, :raison_sociale, :siret, :tva_intracom, :adresse_1, :adresse_2, :code_postal, :ville, :pays, :telephone, :email, :site_web, :slug, 0, NOW(), NOW())
                ");

                $insertSociete->execute([
                    ':nom' => $data['societe_nom'] ?? '',
                    ':raison_sociale' => $data['societe_nom'] ?? '',
                    ':siret' => $data['societe_siret'] ?? null,
                    ':tva_intracom' => $data['societe_tva'] ?? null,
                    ':adresse_1' => $data['societe_adresse_1'] ?? null,
                    ':adresse_2' => $data['societe_adresse_2'] ?? null,
                    ':code_postal' => $data['societe_code_postal'] ?? null,
                    ':ville' => $data['societe_ville'] ?? null,
                    ':pays' => $data['societe_pays'] ?? 'France',
                    ':telephone' => $data['societe_telephone'] ?? null,
                    ':email' => $data['societe_email'] ?? null,
                    ':site_web' => $data['societe_site'] ?? null,
                    ':slug' => $slugify((string)($data['societe_nom'] ?? '')),
                ]);

                $societeId = (int)$pdo->lastInsertId();

                // Duplication automatique du paramétrage de base pour la nouvelle société
                require_once __DIR__ . '/inc/societe_duplication.php';
                dupliquerParametrageSociete($pdo, $societeId);

                $insertAgence = $pdo->prepare("
                    INSERT INTO agences
                        (id_societe, nom_agence, adresse_1, adresse_2, code_postal, ville, pays, telephone, email, slug, actif, date_creation, date_modification)
                    VALUES
                        (:id_societe, :nom_agence, :adresse_1, :adresse_2, :code_postal, :ville, :pays, :telephone, :email, :slug, 0, NOW(), NOW())
                ");

                $insertAgence->execute([
                    ':id_societe' => $societeId,
                    ':nom_agence' => $data['agence_nom'] ?? '',
                    ':adresse_1' => $data['agence_adresse_1'] ?? null,
                    ':adresse_2' => $data['agence_adresse_2'] ?? null,
                    ':code_postal' => $data['agence_code_postal'] ?? null,
                    ':ville' => $data['agence_ville'] ?? null,
                    ':pays' => $data['agence_pays'] ?? 'France',
                    ':telephone' => $data['agence_telephone'] ?? null,
                    ':email' => $data['agence_email'] ?? null,
                    ':slug' => $slugify((string)($data['agence_nom'] ?? '')),
                ]);

                $agenceId = (int)$pdo->lastInsertId();

                $hash = password_hash((string)($data['password'] ?? ''), PASSWORD_DEFAULT);
                $insertUser = $pdo->prepare("
                    INSERT INTO users
                        (id_role, id_societe, id_agence, nom, prenom, email, telephone, mot_de_passe, actif, date_creation, date_modification)
                    VALUES
                        (:id_role, :id_societe, :id_agence, :nom, :prenom, :email, :telephone, :mot_de_passe, 0, NOW(), NOW())
                ");

                $insertUser->execute([
                    ':id_role' => $roleId,
                    ':id_societe' => $societeId,
                    ':id_agence' => $agenceId,
                    ':nom' => $data['user_nom'] ?? '',
                    ':prenom' => $data['user_prenom'] ?? null,
                    ':email' => $data['user_email'] ?? '',
                    ':telephone' => $data['user_telephone'] ?? null,
                    ':mot_de_passe' => $hash,
                ]);

                $userId = (int)$pdo->lastInsertId();

                try {
                    if (file_exists(__DIR__ . '/inc/ged_glossary.php')) {
                        require_once __DIR__ . '/inc/ged_glossary.php';
                        ged_glossary_sync_entity('societe', $societeId, (string)($data['societe_nom'] ?? ''), 'societes', [], $pdo);
                        ged_glossary_sync_entity('agence', $agenceId, (string)($data['agence_nom'] ?? ''), 'agences', [], $pdo);
                        $label = trim((string)($data['user_prenom'] ?? '') . ' ' . (string)($data['user_nom'] ?? '')) ?: ('User#' . $userId);
                        ged_glossary_sync_entity('user', $userId, $label, 'users', ['user_id' => $userId], $pdo);
                    }
                } catch (Throwable) {}

                if (!empty($uploads)) {
                    $moveTemp = static function (array $temp, string $destDir, string $prefix): array {
                        if (!is_dir($destDir)) {
                            if (!mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                                throw new RuntimeException('Création du dossier uploads impossible.');
                            }
                        }

                        $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $temp['ext'];
                        $destPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                        if (!@rename($temp['temp_path'], $destPath)) {
                            if (!@copy($temp['temp_path'], $destPath)) {
                                throw new RuntimeException('Déplacement du fichier impossible.');
                            }
                            @unlink($temp['temp_path']);
                        }

                        return [
                            'filename' => $filename,
                            'path' => $destPath,
                        ];
                    };

                    $uploadDir = __DIR__ . '/uploads/agences/' . $societeId;
                    $uploadUrlBase = '/uploads/agences/' . $societeId;

                    $insertDoc = $pdo->prepare("
                        INSERT INTO agence_documents
                            (id_societe, id_agence, type_document, fichier_url, fichier_nom, fichier_mime, fichier_taille, created_at)
                        VALUES
                            (:id_societe, :id_agence, :type_document, :fichier_url, :fichier_nom, :fichier_mime, :fichier_taille, NOW())
                        ON DUPLICATE KEY UPDATE
                            fichier_url = VALUES(fichier_url),
                            fichier_nom = VALUES(fichier_nom),
                            fichier_mime = VALUES(fichier_mime),
                            fichier_taille = VALUES(fichier_taille),
                            created_at = NOW()
                    ");

                    foreach (['logo' => 'logo', 'kbis' => 'kbis', 'carte_pro' => 'carte_pro'] as $key => $type) {
                        if (empty($uploads[$key])) {
                            continue;
                        }
                        $saved = $moveTemp($uploads[$key], $uploadDir, $type);
                        $moved[] = $saved['path'];
                        $url = $uploadUrlBase . '/' . $saved['filename'];

                        if ($type === 'logo') {
                            $pdo->prepare("UPDATE agences SET logo_url = :logo WHERE id = :id")
                                ->execute([':logo' => $url, ':id' => $agenceId]);
                        }

                        $insertDoc->execute([
                            ':id_societe' => $societeId,
                            ':id_agence' => $agenceId,
                            ':type_document' => $type,
                            ':fichier_url' => $url,
                            ':fichier_nom' => $uploads[$key]['original'] ?? '',
                            ':fichier_mime' => $uploads[$key]['mime'] ?? '',
                            ':fichier_taille' => $uploads[$key]['size'] ?? 0,
                        ]);
                    }
                }

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 48);

                $insertToken = $pdo->prepare("
                    INSERT INTO email_verifications (user_id, token_hash, expires_at, ip_address)
                    VALUES (:user_id, :token_hash, :expires_at, :ip)
                ");
                $insertToken->execute([
                    ':user_id' => $userId,
                    ':token_hash' => $tokenHash,
                    ':expires_at' => $expiresAt,
                    ':ip' => client_ip(),
                ]);

                $pdo->commit();

                $verifyUrl = app_url('/agence_verification.php?token=' . urlencode($token));
                $message = "Bonjour,\n\n"
                    . "Merci pour votre inscription sur Ma Box Agency.\n"
                    . "Pour activer votre compte agence, cliquez sur le lien ci-dessous :\n\n"
                    . $verifyUrl . "\n\n"
                    . "Ce lien est valable 48 heures.\n\n"
                    . "Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.\n";

                $mailOk = send_mail((string)($data['user_email'] ?? ''), 'Activez votre compte Ma Box Agency', $message);

                if ($mailOk) {
                    $success = 'Création validée. Un e-mail de validation vient de vous être envoyé.';
                } else {
                    $success = 'Création validée. L’e-mail de validation n’a pas pu être envoyé.';
                    if (APP_DEBUG) {
                        $debugLink = $verifyUrl;
                    }
                }

                $recapData = $data;
                $missingDocsData = $missingDocs;
                unset($_SESSION[$draftKey], $_SESSION['signup_captcha']);
                $draft = null;
                $view = 'success';
                    } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!empty($moved)) {
                    foreach ($moved as $path) {
                        if (is_file($path)) {
                            @unlink($path);
                        }
                    }
                }
                $errors[] = 'Erreur lors de la création du compte. Merci de réessayer.';
                if (APP_DEBUG) {
                    $errors[] = 'Détail technique : ' . $e->getMessage();
                }
                error_log('[agence_inscription] ' . $e->getMessage());
                $view = 'confirm';
                    }
                }
            }
        }
    } elseif ($action === 'edit') {
        verify_csrf('agence_inscription_edit');
        if (!empty($draft['data'])) {
            $_POST = $draft['data'];
        }
        $view = 'form';
    } else {
        verify_csrf('agence_inscription');

        $societeNom = trim((string)post('societe_nom', ''));
        $societeEmail = trim((string)post('societe_email', ''));
        $societeTelephone = trim((string)post('societe_telephone', ''));
        $societeAdresse1 = trim((string)post('societe_adresse_1', ''));
        $societeAdresse2 = trim((string)post('societe_adresse_2', ''));
        $societeCp = trim((string)post('societe_code_postal', ''));
        $societeVille = trim((string)post('societe_ville', ''));
        $societePays = trim((string)post('societe_pays', 'France'));
        $societeSiret = trim((string)post('societe_siret', ''));
        $societeTva = trim((string)post('societe_tva', ''));
        $societeSite = trim((string)post('societe_site', ''));

        $agenceNom = trim((string)post('agence_nom', ''));
        $agenceEmail = trim((string)post('agence_email', ''));
        $agenceTelephone = trim((string)post('agence_telephone', ''));
        $agenceAdresse1 = trim((string)post('agence_adresse_1', ''));
        $agenceAdresse2 = trim((string)post('agence_adresse_2', ''));
        $agenceCp = trim((string)post('agence_code_postal', ''));
        $agenceVille = trim((string)post('agence_ville', ''));
        $agencePays = trim((string)post('agence_pays', 'France'));

        $userNom = trim((string)post('user_nom', ''));
        $userPrenom = trim((string)post('user_prenom', ''));
        $userEmail = trim((string)post('user_email', ''));
        $userTelephone = trim((string)post('user_telephone', ''));
        $password = (string)post('password', '');
        $passwordConfirm = (string)post('password_confirm', '');

        $captchaInput = trim((string)post('captcha_word', ''));

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
        ];

        $validateUpload = static function (string $key, string $label, array $allowedMimes, int $maxSize, bool $required = false) use (&$errors, $mimeToExt): ?array {
            if (!isset($_FILES[$key])) {
                if ($required) {
                    $errors[] = "Le fichier \"{$label}\" est obligatoire.";
                }
                return null;
            }

            $file = $_FILES[$key];
            if (!is_array($file)) {
                $errors[] = "Le fichier \"{$label}\" est invalide.";
                return null;
            }

            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $errors[] = "Le fichier \"{$label}\" est obligatoire.";
                }
                return null;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = "Le fichier \"{$label}\" n’a pas pu être téléversé.";
                return null;
            }

            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > $maxSize) {
                $errors[] = "Le fichier \"{$label}\" dépasse la taille autorisée.";
                return null;
            }

            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $errors[] = "Le fichier \"{$label}\" est invalide.";
                return null;
            }

            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = (string)finfo_file($finfo, $tmp);
                    finfo_close($finfo);
                }
            }
            if ($mime === '') {
                $mime = (string)($file['type'] ?? '');
            }

            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = "Le fichier \"{$label}\" doit être au format autorisé.";
                return null;
            }

            $ext = $mimeToExt[$mime] ?? 'dat';
            $original = (string)($file['name'] ?? '');
            $original = preg_replace('~[^a-zA-Z0-9._-]+~', '_', $original);

            return [
                'tmp_name' => $tmp,
                'mime' => $mime,
                'size' => $size,
                'ext' => $ext,
                'original' => $original,
            ];
        };

        if ($societeNom === '') {
            $errors[] = 'Le nom de la société est obligatoire.';
        }
        if ($agenceNom === '') {
            $errors[] = 'Le nom de l’agence est obligatoire.';
        }
        if ($userNom === '') {
            $errors[] = 'Le nom du responsable est obligatoire.';
        }
        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Un e-mail valide est obligatoire pour le compte.';
        }
        $passwordLen = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($password === '' || $passwordLen < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Les mots de passe ne correspondent pas.';
        }
        $captchaInputNormalized = function_exists('mb_strtolower') ? mb_strtolower($captchaInput) : strtolower($captchaInput);
        $captchaWordNormalized = function_exists('mb_strtolower') ? mb_strtolower($captchaWord) : strtolower($captchaWord);
        if ($captchaWord === '' || $captchaInputNormalized !== $captchaWordNormalized) {
            $errors[] = 'Le mot de vérification est incorrect.';
        }

        if ($userEmail !== '') {
            $st = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $st->execute([':email' => $userEmail]);
            if ($st->fetchColumn()) {
                $errors[] = 'Cet e-mail est déjà utilisé.';
            }
        }

        $missingDocs = [];
        $logoUpload = $validateUpload('logo_agence', 'Logo agence', ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml'], 3 * 1024 * 1024);
        $kbisUpload = $validateUpload('kbis', 'KBIS', ['application/pdf','image/jpeg','image/png','image/webp'], 6 * 1024 * 1024);
        $carteUpload = $validateUpload('carte_pro', 'Carte professionnelle', ['application/pdf','image/jpeg','image/png','image/webp'], 6 * 1024 * 1024);

        if ($logoUpload === null) $missingDocs[] = 'logo';
        if ($kbisUpload === null) $missingDocs[] = 'kbis';
        if ($carteUpload === null) $missingDocs[] = 'carte_pro';

        if (!$errors) {
            $tempDir = __DIR__ . '/uploads/tmp/agency_signup/' . session_id();
            if (is_dir($tempDir)) {
                foreach (glob($tempDir . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            } else {
                @mkdir($tempDir, 0775, true);
            }

            $saveTemp = static function (array $upload, string $prefix, string $tempDir): array {
                $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $upload['ext'];
                $destPath = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($upload['tmp_name'], $destPath)) {
                    throw new RuntimeException('Téléversement temporaire impossible.');
                }
                return [
                    'temp_path' => $destPath,
                    'mime' => $upload['mime'],
                    'size' => $upload['size'],
                    'ext' => $upload['ext'],
                    'original' => $upload['original'],
                ];
            };

            $uploads = [];
            try {
                if ($logoUpload) $uploads['logo'] = $saveTemp($logoUpload, 'logo', $tempDir);
                if ($kbisUpload) $uploads['kbis'] = $saveTemp($kbisUpload, 'kbis', $tempDir);
                if ($carteUpload) $uploads['carte_pro'] = $saveTemp($carteUpload, 'carte_pro', $tempDir);
            } catch (Throwable $e) {
                $errors[] = 'Impossible de stocker les documents temporairement.';
            }

            if (!$errors) {
                $_SESSION[$draftKey] = [
                    'data' => [
                        'societe_nom' => $societeNom,
                        'societe_email' => $societeEmail,
                        'societe_telephone' => $societeTelephone,
                        'societe_adresse_1' => $societeAdresse1,
                        'societe_adresse_2' => $societeAdresse2,
                        'societe_code_postal' => $societeCp,
                        'societe_ville' => $societeVille,
                        'societe_pays' => $societePays,
                        'societe_siret' => $societeSiret,
                        'societe_tva' => $societeTva,
                        'societe_site' => $societeSite,
                        'agence_nom' => $agenceNom,
                        'agence_email' => $agenceEmail,
                        'agence_telephone' => $agenceTelephone,
                        'agence_adresse_1' => $agenceAdresse1,
                        'agence_adresse_2' => $agenceAdresse2,
                        'agence_code_postal' => $agenceCp,
                        'agence_ville' => $agenceVille,
                        'agence_pays' => $agencePays,
                        'user_nom' => $userNom,
                        'user_prenom' => $userPrenom,
                        'user_email' => $userEmail,
                        'user_telephone' => $userTelephone,
                        'password' => $password,
                    ],
                    'uploads' => $uploads,
                    'missing_docs' => $missingDocs,
                    'created_at' => time(),
                ];
                $draft = $_SESSION[$draftKey];
                $view = 'confirm';

                if (!empty($missingDocs)) {
                    $warnings[] = 'La diffusion d’annonce ne sera pas possible sans les documents (logo, KBIS, carte pro). Vous pourrez les ajouter plus tard.';
                }
            }
        }
    }
}

include __DIR__ . '/inc/header.php';

$recap = !empty($recapData) ? $recapData : ($draft['data'] ?? []);
$missingDocs = !empty($missingDocsData) ? $missingDocsData : ($draft['missing_docs'] ?? []);
?><?php if ($view === 'confirm'): ?>
<section class="signup-wrap">
    <div class="signup-header">
        <h1>Confirmer la création</h1>
        <p>Vérifiez les informations avant validation définitive.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
        <div class="alert info"><?= h(implode(' ', $warnings)) ?></div>
    <?php endif; ?>

    <div class="signup-section section-societe">
        <div class="section-head">
            <span class="section-step">1</span>
            <h3>Récapitulatif société</h3>
        </div>
        <div class="recap-grid">
            <div><span>Nom</span><strong><?= h((string)($recap['societe_nom'] ?? '')) ?></strong></div>
            <div><span>Email</span><strong><?= h((string)($recap['societe_email'] ?? '')) ?></strong></div>
            <div><span>Téléphone</span><strong><?= h((string)($recap['societe_telephone'] ?? '')) ?></strong></div>
            <div class="full"><span>Adresse</span><strong><?= h(trim((string)($recap['societe_adresse_1'] ?? '') . ' ' . (string)($recap['societe_code_postal'] ?? '') . ' ' . (string)($recap['societe_ville'] ?? ''))) ?></strong></div>
        </div>
    </div>

    <div class="signup-section section-agence">
        <div class="section-head">
            <span class="section-step">2</span>
            <h3>Récapitulatif agence</h3>
        </div>
        <div class="recap-grid">
            <div><span>Nom</span><strong><?= h((string)($recap['agence_nom'] ?? '')) ?></strong></div>
            <div><span>Email</span><strong><?= h((string)($recap['agence_email'] ?? '')) ?></strong></div>
            <div><span>Téléphone</span><strong><?= h((string)($recap['agence_telephone'] ?? '')) ?></strong></div>
            <div class="full"><span>Adresse</span><strong><?= h(trim((string)($recap['agence_adresse_1'] ?? '') . ' ' . (string)($recap['agence_code_postal'] ?? '') . ' ' . (string)($recap['agence_ville'] ?? ''))) ?></strong></div>
        </div>
    </div>

    <div class="signup-section section-admin">
        <div class="section-head">
            <span class="section-step">3</span>
            <h3>Administrateur</h3>
        </div>
        <div class="recap-grid">
            <div><span>Nom</span><strong><?= h((string)($recap['user_nom'] ?? '')) ?></strong></div>
            <div><span>Prénom</span><strong><?= h((string)($recap['user_prenom'] ?? '')) ?></strong></div>
            <div><span>Email</span><strong><?= h((string)($recap['user_email'] ?? '')) ?></strong></div>
            <div><span>Téléphone</span><strong><?= h((string)($recap['user_telephone'] ?? '')) ?></strong></div>
        </div>
    </div>

    <div class="signup-section section-docs">
        <div class="section-head">
            <span class="section-step">4</span>
            <h3>Documents</h3>
        </div>
        <div class="recap-grid">
            <div><span>Logo</span><strong><?= in_array('logo', $missingDocs, true) ? 'Non fourni' : 'Fourni' ?></strong></div>
            <div><span>KBIS</span><strong><?= in_array('kbis', $missingDocs, true) ? 'Non fourni' : 'Fourni' ?></strong></div>
            <div><span>Carte pro</span><strong><?= in_array('carte_pro', $missingDocs, true) ? 'Non fourni' : 'Fourni' ?></strong></div>
        </div>
    </div>

    <div class="signup-actions">
        <form method="post">
            <?= csrf_field('agence_inscription_confirm') ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn-primary">Confirmer la création</button>
        </form>
        <form method="post">
            <?= csrf_field('agence_inscription_edit') ?>
            <input type="hidden" name="action" value="edit">
            <button type="submit" class="btn-outline">Reprendre la modification</button>
        </form>
    </div>
</section>

<?php elseif ($view === 'success'): ?>
<section class="signup-wrap">
    <div class="signup-header">
        <h1>Création effectuée</h1>
        <p>Votre société a bien été créée.</p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert success"><?= h($success) ?></div>
        <?php if ($debugLink !== ''): ?>
            <div class="alert info">Lien de validation (mode debug) : <a href="<?= h($debugLink) ?>"><?= h($debugLink) ?></a></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
        <div class="alert info"><?= h(implode(' ', $warnings)) ?></div>
    <?php endif; ?>

    <div class="signup-section section-societe">
        <div class="section-head">
            <span class="section-step">1</span>
            <h3>Récapitulatif</h3>
        </div>
        <div class="recap-grid">
            <div><span>Société</span><strong><?= h((string)($recap['societe_nom'] ?? '')) ?></strong></div>
            <div><span>Agence</span><strong><?= h((string)($recap['agence_nom'] ?? '')) ?></strong></div>
            <div><span>Admin</span><strong><?= h((string)($recap['user_email'] ?? '')) ?></strong></div>
        </div>
    </div>
</section>

<?php else: ?>
<section class="signup-wrap">
    <div class="signup-header">
        <h1>Créer votre compte agence</h1>
        <p>Rejoignez Ma Box Agency, gérez vos biens et activez l’accès REGISTRE.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="signup-form" enctype="multipart/form-data">
        <?= csrf_field('agence_inscription') ?>
        <input type="hidden" name="action" value="prepare">

        <div class="signup-section section-societe">
            <div class="section-head">
                <span class="section-step">1</span>
                <h3>Votre société</h3>
            </div>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Nom de la société *</label>
                    <input type="text" name="societe_nom" class="input-lg" required value="<?= h((string)post('societe_nom', '')) ?>">
                </div>
                <div class="form-group">
                    <label>E-mail société</label>
                    <input type="email" name="societe_email" value="<?= h((string)post('societe_email', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Téléphone société</label>
                    <input type="text" name="societe_telephone" value="<?= h((string)post('societe_telephone', '')) ?>">
                </div>
                <div class="form-group">
                    <label>SIRET</label>
                    <input type="text" name="societe_siret" value="<?= h((string)post('societe_siret', '')) ?>">
                </div>
                <div class="form-group">
                    <label>TVA intracom</label>
                    <input type="text" name="societe_tva" value="<?= h((string)post('societe_tva', '')) ?>">
                </div>
                <div class="form-group full">
                    <label>Recherche d’adresse (société)</label>
                    <input
                        type="text"
                        id="societe_recherche"
                        name="societe_recherche"
                        class="input-lg"
                        placeholder="Ex: 10 rue de la Paix, Paris"
                        data-places-input
                        data-places-endpoint="<?= h(app_url('/api/places_autocomplete.php')) ?>"
                        data-places-details-endpoint="<?= h(app_url('/api/places_details.php')) ?>"
                        data-places-geocode-endpoint="<?= h(app_url('/api/geocode_address.php')) ?>"
                        data-places-street1="societe_adresse_1"
                        data-places-street2="societe_adresse_2"
                        data-places-postal="societe_code_postal"
                        data-places-city="societe_ville"
                        data-places-country="societe_pays"
                        data-places-country-code="fr"
                        autocomplete="off"
                    >
                </div>
                <div class="form-group full">
                    <label>Adresse</label>
                    <input type="text" id="societe_adresse_1" name="societe_adresse_1" class="input-md" value="<?= h((string)post('societe_adresse_1', '')) ?>">
                </div>
                <div class="form-group full">
                    <label>Complément d’adresse</label>
                    <input type="text" id="societe_adresse_2" name="societe_adresse_2" value="<?= h((string)post('societe_adresse_2', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Code postal</label>
                    <input type="text" id="societe_code_postal" name="societe_code_postal" value="<?= h((string)post('societe_code_postal', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Ville</label>
                    <input type="text" id="societe_ville" name="societe_ville" class="input-md" value="<?= h((string)post('societe_ville', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Pays</label>
                    <input type="text" id="societe_pays" name="societe_pays" value="<?= h((string)post('societe_pays', 'France')) ?>">
                </div>
                <div class="form-group">
                    <label>Site web</label>
                    <input type="text" name="societe_site" value="<?= h((string)post('societe_site', '')) ?>">
                </div>
            </div>
        </div>

        <div class="signup-section section-agence">
            <div class="section-head">
                <span class="section-step">2</span>
                <h3>Votre agence</h3>
            </div>
            <p class="section-hint">Si votre société n’a qu’un seul établissement, reprenez les mêmes informations que la société (pas besoin de créer une agence distincte).</p>
            <div class="section-actions">
                <button type="button" class="btn-outline" id="copy-societe-agence">Pas d’autre établissement</button>
            </div>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Recherche d’adresse (agence)</label>
                    <input
                        type="text"
                        id="agence_recherche"
                        name="agence_recherche"
                        class="input-lg"
                        placeholder="Ex: 10 rue de la Paix, Paris"
                        data-places-input
                        data-places-endpoint="<?= h(app_url('/api/places_autocomplete.php')) ?>"
                        data-places-details-endpoint="<?= h(app_url('/api/places_details.php')) ?>"
                        data-places-geocode-endpoint="<?= h(app_url('/api/geocode_address.php')) ?>"
                        data-places-street1="agence_adresse_1"
                        data-places-street2="agence_adresse_2"
                        data-places-postal="agence_code_postal"
                        data-places-city="agence_ville"
                        data-places-country="agence_pays"
                        data-places-country-code="fr"
                        autocomplete="off"
                    >
                </div>
                <div class="form-group full">
                    <label>Nom de l’agence *</label>
                    <input type="text" name="agence_nom" class="input-lg" required value="<?= h((string)post('agence_nom', '')) ?>">
                </div>
                <div class="form-group">
                    <label>E-mail agence</label>
                    <input type="email" name="agence_email" value="<?= h((string)post('agence_email', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Téléphone agence</label>
                    <input type="text" name="agence_telephone" value="<?= h((string)post('agence_telephone', '')) ?>">
                </div>
                <div class="form-group full">
                    <label>Adresse</label>
                    <input type="text" id="agence_adresse_1" name="agence_adresse_1" class="input-md" value="<?= h((string)post('agence_adresse_1', '')) ?>">
                </div>
                <div class="form-group full">
                    <label>Complément d’adresse</label>
                    <input type="text" id="agence_adresse_2" name="agence_adresse_2" value="<?= h((string)post('agence_adresse_2', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Code postal</label>
                    <input type="text" id="agence_code_postal" name="agence_code_postal" value="<?= h((string)post('agence_code_postal', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Ville</label>
                    <input type="text" id="agence_ville" name="agence_ville" class="input-md" value="<?= h((string)post('agence_ville', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Pays</label>
                    <input type="text" id="agence_pays" name="agence_pays" value="<?= h((string)post('agence_pays', 'France')) ?>">
                </div>
            </div>
        </div>

        <div class="signup-section section-admin">
            <div class="section-head">
                <span class="section-step">3</span>
                <h3>Administrateur du compte</h3>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="user_nom" class="input-md" required value="<?= h((string)post('user_nom', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Prénom</label>
                    <input type="text" name="user_prenom" value="<?= h((string)post('user_prenom', '')) ?>">
                </div>
                <div class="form-group">
                    <label>E-mail *</label>
                    <input type="email" name="user_email" class="input-lg" required value="<?= h((string)post('user_email', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" name="user_telephone" value="<?= h((string)post('user_telephone', '')) ?>">
                </div>
                <div class="form-group">
                    <label>Mot de passe *</label>
                    <input type="password" name="password" class="input-md" required>
                </div>
                <div class="form-group">
                    <label>Confirmer le mot de passe *</label>
                    <input type="password" name="password_confirm" class="input-md" required>
                </div>
            </div>
        </div>

        <div class="signup-section section-captcha">
            <div class="section-head">
                <span class="section-step">4</span>
                <h3>Vérification anti‑robot</h3>
            </div>
            <div class="captcha-box">
                <div class="captcha-word"><?= h($captchaWord !== '' ? $captchaWord : 'agence') ?></div>
                <div class="captcha-input">
                    <label>Recopiez ce mot *</label>
                    <input type="text" name="captcha_word" class="input-md" required>
                </div>
                <a class="captcha-refresh" href="<?= h(app_url('/agence_inscription.php?refresh=1')) ?>">Changer le mot</a>
            </div>
        </div>
        <div class="signup-section section-docs">
            <div class="section-head">
                <span class="section-step">5</span>
                <h3>Documents</h3>
            </div>
            <p class="section-hint">La diffusion d’annonce ne sera pas possible sans ces documents, mais vous pourrez les ajouter plus tard.</p>
            <div class="drop-grid">
                <div class="dropzone" data-dropzone>
                    <input type="file" name="logo_agence" accept="image/*">
                    <div class="dz-title">Logo agence</div>
                    <div class="dz-help">Glissez ou cliquez pour téléverser (JPG, PNG, WEBP, SVG). Max 3 Mo.</div>
                    <div class="dz-file" data-file>Fichier non sélectionné</div>
                </div>
                <div class="dropzone" data-dropzone>
                    <input type="file" name="kbis" accept="application/pdf,image/*">
                    <div class="dz-title">KBIS</div>
                    <div class="dz-help">PDF ou image. Max 6 Mo.</div>
                    <div class="dz-file" data-file>Fichier non sélectionné</div>
                </div>
                <div class="dropzone" data-dropzone>
                    <input type="file" name="carte_pro" accept="application/pdf,image/*">
                    <div class="dz-title">Carte professionnelle</div>
                    <div class="dz-help">PDF ou image. Max 6 Mo.</div>
                    <div class="dz-file" data-file>Fichier non sélectionné</div>
                </div>
            </div>
        </div>

        <div class="signup-actions">
            <button type="submit" class="btn-primary">Préparer la création</button>
            <a href="<?= h(app_url('/login.php')) ?>" class="btn-outline">Déjà un compte ? Se connecter</a>
        </div>
    </form>
</section>
<?php endif; ?>

<?php include __DIR__ . '/inc/footer.php'; ?>

<style>
.theme-agency-signup .page-shell{max-width:1280px}
.theme-agency-signup{background:radial-gradient(1200px 600px at 20% -10%, rgba(13,59,102,0.12), transparent 60%), radial-gradient(900px 500px at 90% 0%, rgba(255,196,80,0.12), transparent 55%), #f4f6fb}
.signup-wrap{max-width:1240px;margin:0 auto;padding:10px 0 26px;display:grid;gap:18px}
.signup-header h1{margin:0 0 6px;font-size:32px;color:#0f172a}
.signup-header p{margin:0;color:#475569;font-weight:500}
.signup-form{display:grid;gap:16px}
.signup-section{border-radius:16px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,0.10);border:1px solid rgba(15,23,42,0.08)}
.section-societe{background:linear-gradient(135deg, rgba(13,59,102,0.06), #ffffff)}
.section-agence{background:linear-gradient(135deg, rgba(10, 44, 82, 0.08), #ffffff)}
.section-admin{background:linear-gradient(135deg, rgba(255,196,80,0.12), #ffffff)}
.section-captcha{background:linear-gradient(135deg, rgba(99,102,241,0.10), #ffffff)}
.section-docs{background:linear-gradient(135deg, rgba(16,185,129,0.10), #ffffff)}
.section-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.section-step{width:32px;height:32px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-weight:800;color:#0f172a;background:linear-gradient(135deg,#ffd479,#4878a6)}
.signup-section h3{margin:0;font-size:18px;color:#0f172a}
.section-hint{margin:0 0 10px;color:#4b5563;font-size:13px}
.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.form-group{display:grid;gap:5px}
.form-group.full{grid-column:1 / -1}
.form-group label{font-weight:700;color:#0f172a;font-size:12.5px}
.form-group input{padding:8px 10px;border:1px solid #d6dbe5;border-radius:10px;background:#fff}
.form-group input:focus{outline:none;border-color:#0b6ef3;box-shadow:0 0 0 3px rgba(11,110,243,0.12)}
.input-lg{padding:12px 14px;font-size:15px;font-weight:600}
.input-md{padding:10px 12px}
.signup-actions{display:flex;gap:10px;flex-wrap:wrap}
.section-actions{display:flex;justify-content:flex-end;margin:0 0 10px}
.alert{padding:12px 14px;border-radius:10px}
.alert.success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}
.alert.error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}
.alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
.alert ul{margin:0;padding-left:18px}
.captcha-box{display:grid;gap:12px;grid-template-columns:160px 1fr auto;align-items:center}
.captcha-word{font-weight:700;font-size:18px;letter-spacing:.04em;text-transform:uppercase;background:#f1f5f9;border-radius:12px;padding:12px 14px;text-align:center;color:#0f172a}
.captcha-input label{font-weight:600;color:#17324d;font-size:13px}
.captcha-input input{width:100%;padding:10px 12px;border:1px solid #d9dee7;border-radius:10px}
.captcha-refresh{font-size:13px;color:#0b6ef3;text-decoration:none}
.captcha-refresh:hover{text-decoration:underline}
.drop-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.dropzone{border:2px dashed #b8c3d6;border-radius:14px;padding:14px;background:#ffffff;display:grid;gap:6px;cursor:pointer;transition:.2s ease}
.dropzone:hover{border-color:#0b6ef3;background:#f4f8ff}
.dropzone.is-dragover{border-color:#0b6ef3;background:#eaf2ff}
.dropzone input{display:none}
.dz-title{font-weight:800;color:#0f172a;font-size:13px}
.dz-help{font-size:12px;color:#64748b}
.dz-file{font-size:12.5px;color:#0f172a;background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:7px 9px}
.recap-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.recap-grid .full{grid-column:1 / -1}
.recap-grid span{display:block;font-size:12px;color:#64748b;margin-bottom:4px}
.recap-grid strong{font-size:14px;color:#0f172a}
@media (max-width: 820px){
  .form-grid{grid-template-columns:1fr}
  .captcha-box{grid-template-columns:1fr}
  .drop-grid{grid-template-columns:1fr}
  .recap-grid{grid-template-columns:1fr}
}
</style>

<script>
document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type="file"]');
    const fileLabel = zone.querySelector('[data-file]');
    if (!input || !fileLabel) return;

    const updateLabel = () => {
        if (input.files && input.files[0]) {
            fileLabel.textContent = input.files[0].name;
        } else {
            fileLabel.textContent = 'Fichier non sélectionné';
        }
    };

    zone.addEventListener('click', () => input.click());
    input.addEventListener('change', updateLabel);

    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        zone.classList.add('is-dragover');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-dragover'));
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('is-dragover');
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
            input.files = e.dataTransfer.files;
            updateLabel();
        }
    });
});

const copyButton = document.getElementById('copy-societe-agence');
if (copyButton) {
    copyButton.addEventListener('click', () => {
        const map = [
            ['societe_nom', 'agence_nom'],
            ['societe_email', 'agence_email'],
            ['societe_telephone', 'agence_telephone'],
            ['societe_adresse_1', 'agence_adresse_1'],
            ['societe_adresse_2', 'agence_adresse_2'],
            ['societe_code_postal', 'agence_code_postal'],
            ['societe_ville', 'agence_ville'],
            ['societe_pays', 'agence_pays'],
        ];

        map.forEach(([from, to]) => {
            const source = document.querySelector(`[name="${from}"]`) || document.getElementById(from);
            const target = document.querySelector(`[name="${to}"]`) || document.getElementById(to);
            if (source && target) {
                target.value = source.value;
            }
        });

        const srcSearch = document.getElementById('societe_recherche');
        const dstSearch = document.getElementById('agence_recherche');
        if (srcSearch && dstSearch) {
            if (srcSearch.value.trim() !== '') {
                dstSearch.value = srcSearch.value;
            } else {
                const addr1 = document.getElementById('societe_adresse_1')?.value || '';
                const cp = document.getElementById('societe_code_postal')?.value || '';
                const city = document.getElementById('societe_ville')?.value || '';
                dstSearch.value = [addr1, cp, city].filter(Boolean).join(' ');
            }
        }
    });
}
</script>
