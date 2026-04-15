<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_admin();

$pageTitle = 'Organisation du développement - MaBoxImmo';
$bodyClass = '';
$appLayout = true;
$robots = 'noindex, nofollow';

// Action bar shortcuts
$actionbar = [
    'show_back' => false,
    'show_home' => false,
    'left' => [
        [
            'label' => 'Retour accueil',
            'url' => app_url('/default.php'),
        ],
        [
            'label' => 'Ajouter un bien',
            'url' => app_url('/bien_ajouter.php'),
            'primary' => true,
        ],
    ],
];

/* =========================================================
   OUTILS
========================================================= */
function intOrNull($value): ?int
{
    if ($value === '' || $value === null) {
        return null;
    }
    return (int)$value;
}

function buildQuery(array $overrides = [], array $remove = []): string
{
    $params = $_GET;

    foreach ($remove as $key) {
        unset($params[$key]);
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $query = http_build_query($params);
    return 'dev_organisation.php' . ($query !== '' ? '?' . $query : '');
}

function fetchOpenAIText(string $apiKey, string $model, string $instructions, string $input): array
{
    if ($apiKey === '') {
        return [
            'ok' => false,
            'text' => '',
            'error' => 'Clé API OpenAI absente. Ajoutez OPENAI_API_KEY côté serveur.',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'text' => '',
            'error' => 'cURL n’est pas activé sur le serveur.',
        ];
    }

    $payload = [
        'model' => $model,
        'instructions' => $instructions,
        'input' => $input,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'text' => '',
            'error' => 'Erreur cURL : ' . $curlErr,
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiError = '';
        if (is_array($data) && isset($data['error']['message'])) {
            $apiError = (string)$data['error']['message'];
        }

        return [
            'ok' => false,
            'text' => '',
            'error' => 'Erreur API OpenAI (' . $httpCode . ')' . ($apiError !== '' ? ' : ' . $apiError : ''),
        ];
    }

    $text = '';

    if (is_array($data)) {
        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            $text = trim($data['output_text']);
        }

        if ($text === '' && !empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $outputItem) {
                if (!empty($outputItem['content']) && is_array($outputItem['content'])) {
                    foreach ($outputItem['content'] as $contentItem) {
                        if (($contentItem['type'] ?? '') === 'output_text' && !empty($contentItem['text'])) {
                            $text .= ($text !== '' ? "\n\n" : '') . trim((string)$contentItem['text']);
                        }
                    }
                }
            }
        }
    }

    if ($text === '') {
        return [
            'ok' => false,
            'text' => '',
            'error' => 'Réponse OpenAI reçue, mais aucun texte exploitable n’a été trouvé.',
        ];
    }

    return [
        'ok' => true,
        'text' => trim($text),
        'error' => '',
    ];
}

function buildPromptForGeneration(array $data): array
{
    $titre = trim((string)($data['titre'] ?? ''));
    $rubrique = trim((string)($data['rubrique'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $objectif = trim((string)($data['objectif'] ?? ''));
    $fonctionnalites = trim((string)($data['fonctionnalites_prevues'] ?? ''));
    $typeElement = trim((string)($data['type_element'] ?? ''));
    $url = trim((string)($data['url_relative'] ?? ''));

    $instructions = <<<TXT
Tu es un rédacteur web expert en immobilier, UX, conversion commerciale et clarté.
Ta mission est d’écrire un texte prêt à afficher sur une page de portail immobilier.

Règles :
- écrire en français
- style professionnel, fluide, rassurant, moderne
- rester concret
- éviter les répétitions
- privilégier un texte clair et structuré
- ne pas ajouter de balises HTML
- proposer un texte directement exploitable sur le site
TXT;

    $input = <<<TXT
CONTEXTE PAGE

Titre : {$titre}
Type d'élément : {$typeElement}
Rubrique : {$rubrique}
URL relative : {$url}

Description :
{$description}

Objectif :
{$objectif}

Fonctionnalités prévues :
{$fonctionnalites}

Travail demandé :
Rédige un texte prêt à afficher sur cette page.
TXT;

    return [$instructions, $input];
}

function buildPromptForRewrite(array $data): array
{
    $titre = trim((string)($data['titre'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $objectif = trim((string)($data['objectif'] ?? ''));
    $fonctionnalites = trim((string)($data['fonctionnalites_prevues'] ?? ''));
    $texteActuel = trim((string)($data['proposition_chatgpt'] ?? ''));
    $demande = trim((string)($data['demande_modification_chatgpt'] ?? ''));

    $instructions = <<<TXT
Tu es un rédacteur web expert en immobilier et optimisation de contenu.
Tu réécris un texte existant selon une demande précise.

Règles :
- écrire en français
- conserver le sens utile du texte
- améliorer la lisibilité et la clarté
- ne pas mettre de balises HTML
- répondre directement avec la nouvelle version
TXT;

    $input = <<<TXT
PAGE : {$titre}

Description :
{$description}

Objectif :
{$objectif}

Fonctionnalités prévues :
{$fonctionnalites}

Texte actuel :
{$texteActuel}

Demande de modification :
{$demande}

Travail demandé :
Réécris le texte actuel en respectant la demande.
TXT;

    return [$instructions, $input];
}

function collectDescendants(array $itemsByParent, int $parentId): array
{
    $ids = [];

    if (!empty($itemsByParent[$parentId])) {
        foreach ($itemsByParent[$parentId] as $child) {
            $childId = (int)$child['id'];
            $ids[] = $childId;
            $ids = array_merge($ids, collectDescendants($itemsByParent, $childId));
        }
    }

    return $ids;
}

function badgeStatutClass(string $statut): string
{
    $map = [
        'idee'      => 'badge-grey',
        'a_faire'   => 'badge-blue',
        'en_cours'  => 'badge-orange',
        'a_tester'  => 'badge-purple',
        'termine'   => 'badge-green',
        'archive'   => 'badge-dark',
    ];
    return $map[$statut] ?? 'badge-grey';
}

function badgePrioriteClass(string $priorite): string
{
    $map = [
        'basse'   => 'badge-grey',
        'normale' => 'badge-blue',
        'haute'   => 'badge-orange',
        'urgente' => 'badge-red',
    ];
    return $map[$priorite] ?? 'badge-grey';
}

/* =========================================================
   ETAT INITIAL
========================================================= */
$message = '';
$messageType = 'success';

/* =========================================================
   CHARGEMENT DES DONNEES
========================================================= */
$stmt = $pdo->query("
    SELECT *
    FROM dev_organisation
    ORDER BY COALESCE(parent_id, 0) ASC, ordre_affichage ASC, titre ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allItems = $rows;

$itemsByParent = [];
$itemsById = [];

foreach ($rows as $row) {
    $parent = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
    $itemsByParent[$parent][] = $row;
    $itemsById[(int)$row['id']] = $row;
}

/* =========================================================
   FILTRES
========================================================= */
$filtreVue = isset($_GET['vue']) ? trim((string)$_GET['vue']) : 'tous';
$filtreElementId = isset($_GET['element_id']) ? (int)$_GET['element_id'] : 0;
$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'compact';

$rowsToRender = $rows;

if ($filtreVue === 'pages_maitres') {
    $rowsToRender = array_values(array_filter($rows, static function ($row) {
        return ($row['type_element'] ?? '') === 'page_maitre';
    }));
}

if ($filtreVue === 'branche' && $filtreElementId > 0 && isset($itemsById[$filtreElementId])) {
    $idsAutorises = [$filtreElementId];
    $idsAutorises = array_merge($idsAutorises, collectDescendants($itemsByParent, $filtreElementId));
    $idsAutorises = array_unique(array_map('intval', $idsAutorises));

    $rowsToRender = array_values(array_filter($rows, static function ($row) use ($idsAutorises) {
        return in_array((int)$row['id'], $idsAutorises, true);
    }));
}

$rowsToRenderIds = array_map(static fn($r) => (int)$r['id'], $rowsToRender);

/* =========================================================
   ELEMENT EN EDITION
========================================================= */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$parentPreset = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
$detailId = isset($_GET['detail']) ? (int)$_GET['detail'] : 0;

$editItem = [
    'id'                            => 0,
    'parent_id'                     => $parentPreset > 0 ? $parentPreset : null,
    'niveau'                        => 1,
    'type_element'                  => 'page_maitre',
    'rubrique'                      => '',
    'titre'                         => '',
    'slug'                          => '',
    'fichier_page'                  => '',
    'url_relative'                  => '',
    'description'                   => '',
    'commentaire_dev'               => '',
    'objectif'                      => '',
    'fonctionnalites_prevues'       => '',
    'proposition_chatgpt'           => '',
    'demande_modification_chatgpt'  => '',
    'ordre_affichage'               => 0,
    'statut'                        => 'idee',
    'priorite'                      => 'normale',
    'visible'                       => 1,
];

if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM dev_organisation WHERE id = ?");
    $stmtEdit->execute([$editId]);
    $found = $stmtEdit->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $editItem = $found;
    }
}

if ($editId === 0 && $parentPreset > 0 && isset($itemsById[$parentPreset])) {
    $parentItem = $itemsById[$parentPreset];
    $editItem['niveau'] = ((int)$parentItem['niveau']) + 1;
    $editItem['rubrique'] = (string)($parentItem['rubrique'] ?? '');
}

/* =========================================================
   TRAITEMENT POST
========================================================= */
if (is_post()) {
    $action = (string)post('action', '');

    if ($action === 'delete') {
        $idDelete = (int)post('id', 0);
        verify_csrf('dev_organisation_delete_' . $idDelete);
    } else {
        verify_csrf('dev_organisation_form');
    }

    $id                            = (int)post('id', '0');
    $parent_id                     = intOrNull($_POST['parent_id'] ?? null);
    $niveau                        = max(1, (int)post('niveau', '1'));
    $type_element                  = trim((string)post('type_element', 'page_maitre'));
    $rubrique                      = trim((string)post('rubrique', ''));
    $titre                         = trim((string)post('titre', ''));
    $slug                          = trim((string)post('slug', ''));
    $fichier_page                  = trim((string)post('fichier_page', ''));
    $url_relative                  = trim((string)post('url_relative', ''));
    $description                   = trim((string)post('description', ''));
    $commentaire_dev               = trim((string)post('commentaire_dev', ''));
    $objectif                      = trim((string)post('objectif', ''));
    $fonctionnalites_prevues       = trim((string)post('fonctionnalites_prevues', ''));
    $proposition_chatgpt           = trim((string)post('proposition_chatgpt', ''));
    $demande_modification_chatgpt  = trim((string)post('demande_modification_chatgpt', ''));
    $ordre_affichage               = (int)post('ordre_affichage', '0');
    $statut                        = trim((string)post('statut', 'idee'));
    $priorite                      = trim((string)post('priorite', 'normale'));
    $visible                       = isset($_POST['visible']) ? 1 : 0;

    $editItem = [
        'id'                           => $id,
        'parent_id'                    => $parent_id,
        'niveau'                       => $niveau,
        'type_element'                 => $type_element,
        'rubrique'                     => $rubrique,
        'titre'                        => $titre,
        'slug'                         => $slug,
        'fichier_page'                 => $fichier_page,
        'url_relative'                 => $url_relative,
        'description'                  => $description,
        'commentaire_dev'              => $commentaire_dev,
        'objectif'                     => $objectif,
        'fonctionnalites_prevues'      => $fonctionnalites_prevues,
        'proposition_chatgpt'          => $proposition_chatgpt,
        'demande_modification_chatgpt' => $demande_modification_chatgpt,
        'ordre_affichage'              => $ordre_affichage,
        'statut'                       => $statut,
        'priorite'                     => $priorite,
        'visible'                      => $visible,
    ];

    $typesAutorises = ['rubrique','page_maitre','sous_page','fonction','commentaire'];
    $statutsAutorises = ['idee','a_faire','en_cours','a_tester','termine','archive'];
    $prioritesAutorisees = ['basse','normale','haute','urgente'];

    if ($action === 'generate_ai') {
        [$instructions, $input] = buildPromptForGeneration($editItem);
        $ai = fetchOpenAIText($OPENAI_API_KEY, $OPENAI_TEXT_MODEL, $instructions, $input);

        if ($ai['ok']) {
            $editItem['proposition_chatgpt'] = $ai['text'];
            $message = 'Proposition ChatGPT générée.';
        } else {
            $message = $ai['error'];
            $messageType = 'error';
        }
    } elseif ($action === 'rewrite_ai') {
        if ($proposition_chatgpt === '') {
            $message = 'Aucun texte à réécrire dans "Proposition ChatGPT".';
            $messageType = 'error';
        } elseif ($demande_modification_chatgpt === '') {
            $message = 'Indiquez une demande de modification avant de réécrire.';
            $messageType = 'error';
        } else {
            [$instructions, $input] = buildPromptForRewrite($editItem);
            $ai = fetchOpenAIText($OPENAI_API_KEY, $OPENAI_TEXT_MODEL, $instructions, $input);

            if ($ai['ok']) {
                $editItem['proposition_chatgpt'] = $ai['text'];
                $message = 'Texte réécrit par ChatGPT.';
            } else {
                $message = $ai['error'];
                $messageType = 'error';
            }
        }
    } else {
        if ($titre === '') {
            $message = 'Le titre est obligatoire.';
            $messageType = 'error';
        } elseif (!in_array($type_element, $typesAutorises, true)) {
            $message = 'Type d’élément invalide.';
            $messageType = 'error';
        } elseif (!in_array($statut, $statutsAutorises, true)) {
            $message = 'Statut invalide.';
            $messageType = 'error';
        } elseif (!in_array($priorite, $prioritesAutorisees, true)) {
            $message = 'Priorité invalide.';
            $messageType = 'error';
        } else {
            if ($action === 'add' || $action === 'quick_add') {
                $sql = "INSERT INTO dev_organisation
                        (parent_id, niveau, type_element, rubrique, titre, slug, fichier_page, url_relative,
                         description, commentaire_dev, objectif, fonctionnalites_prevues, proposition_chatgpt,
                         demande_modification_chatgpt, ordre_affichage, statut, priorite, visible)
                        VALUES
                        (:parent_id, :niveau, :type_element, :rubrique, :titre, :slug, :fichier_page, :url_relative,
                         :description, :commentaire_dev, :objectif, :fonctionnalites_prevues, :proposition_chatgpt,
                         :demande_modification_chatgpt, :ordre_affichage, :statut, :priorite, :visible)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':parent_id'                    => $parent_id,
                    ':niveau'                       => $niveau,
                    ':type_element'                 => $type_element,
                    ':rubrique'                     => $rubrique !== '' ? $rubrique : null,
                    ':titre'                        => $titre,
                    ':slug'                         => $slug !== '' ? $slug : null,
                    ':fichier_page'                 => $fichier_page !== '' ? $fichier_page : null,
                    ':url_relative'                 => $url_relative !== '' ? $url_relative : null,
                    ':description'                  => $description !== '' ? $description : null,
                    ':commentaire_dev'              => $commentaire_dev !== '' ? $commentaire_dev : null,
                    ':objectif'                     => $objectif !== '' ? $objectif : null,
                    ':fonctionnalites_prevues'      => $fonctionnalites_prevues !== '' ? $fonctionnalites_prevues : null,
                    ':proposition_chatgpt'          => $proposition_chatgpt !== '' ? $proposition_chatgpt : null,
                    ':demande_modification_chatgpt' => $demande_modification_chatgpt !== '' ? $demande_modification_chatgpt : null,
                    ':ordre_affichage'              => $ordre_affichage,
                    ':statut'                       => $statut,
                    ':priorite'                     => $priorite,
                    ':visible'                      => $visible,
                ]);

                $newId = (int)$pdo->lastInsertId();

                redirect(buildQuery([
                    'ok' => 'ajout',
                    'edit' => $newId,
                    'mode' => $mode
                ], ['parent_id', 'err']));
            }

            if (($action === 'edit' || $action === 'quick_edit') && $id > 0) {
                $sql = "UPDATE dev_organisation SET
                            parent_id = :parent_id,
                            niveau = :niveau,
                            type_element = :type_element,
                            rubrique = :rubrique,
                            titre = :titre,
                            slug = :slug,
                            fichier_page = :fichier_page,
                            url_relative = :url_relative,
                            description = :description,
                            commentaire_dev = :commentaire_dev,
                            objectif = :objectif,
                            fonctionnalites_prevues = :fonctionnalites_prevues,
                            proposition_chatgpt = :proposition_chatgpt,
                            demande_modification_chatgpt = :demande_modification_chatgpt,
                            ordre_affichage = :ordre_affichage,
                            statut = :statut,
                            priorite = :priorite,
                            visible = :visible
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':parent_id'                    => $parent_id,
                    ':niveau'                       => $niveau,
                    ':type_element'                 => $type_element,
                    ':rubrique'                     => $rubrique !== '' ? $rubrique : null,
                    ':titre'                        => $titre,
                    ':slug'                         => $slug !== '' ? $slug : null,
                    ':fichier_page'                 => $fichier_page !== '' ? $fichier_page : null,
                    ':url_relative'                 => $url_relative !== '' ? $url_relative : null,
                    ':description'                  => $description !== '' ? $description : null,
                    ':commentaire_dev'              => $commentaire_dev !== '' ? $commentaire_dev : null,
                    ':objectif'                     => $objectif !== '' ? $objectif : null,
                    ':fonctionnalites_prevues'      => $fonctionnalites_prevues !== '' ? $fonctionnalites_prevues : null,
                    ':proposition_chatgpt'          => $proposition_chatgpt !== '' ? $proposition_chatgpt : null,
                    ':demande_modification_chatgpt' => $demande_modification_chatgpt !== '' ? $demande_modification_chatgpt : null,
                    ':ordre_affichage'              => $ordre_affichage,
                    ':statut'                       => $statut,
                    ':priorite'                     => $priorite,
                    ':visible'                      => $visible,
                    ':id'                           => $id,
                ]);

                redirect(buildQuery([
                    'ok' => 'modif',
                    'edit' => $id,
                    'mode' => $mode
                ], ['parent_id', 'err']));
            }

            if ($action === 'delete' && $id > 0) {
                $stmtEnfants = $pdo->prepare("SELECT COUNT(*) FROM dev_organisation WHERE parent_id = ?");
                $stmtEnfants->execute([$id]);
                $nbEnfants = (int)$stmtEnfants->fetchColumn();

                if ($nbEnfants > 0) {
                    redirect(buildQuery(['err' => 'enfants', 'mode' => $mode], ['ok']));
                }

                $stmt = $pdo->prepare("DELETE FROM dev_organisation WHERE id = ?");
                $stmt->execute([$id]);

                redirect(buildQuery(['ok' => 'suppression', 'mode' => $mode], ['edit', 'parent_id', 'err', 'detail']));
            }
        }
    }
}

/* =========================================================
   MESSAGES
========================================================= */
if ($message === '' && isset($_GET['ok'])) {
    $ok = (string)$_GET['ok'];
    if ($ok === 'ajout') {
        $message = 'Élément ajouté avec succès.';
    } elseif ($ok === 'modif') {
        $message = 'Élément modifié avec succès.';
    } elseif ($ok === 'suppression') {
        $message = 'Élément supprimé avec succès.';
    }
}

if ($message === '' && isset($_GET['err']) && $_GET['err'] === 'enfants') {
    $message = 'Suppression impossible : cet élément contient encore des sous-éléments.';
    $messageType = 'error';
}

require_once __DIR__ . '/inc/header.php';
?>

<style>
    .dev-wrap{max-width:1500px;margin:0 auto;}
    .page-title{font-size:32px;font-weight:700;margin:0 0 8px;}
    .page-subtitle{color:var(--text-soft);margin-bottom:20px;}

    .message{padding:14px 16px;border-radius:14px;margin-bottom:18px;font-weight:600;border:1px solid var(--border);background:var(--surface-soft);color:var(--text);}
    .message.success{background:rgba(90,154,138,0.18);color:#1f5a4d;border-color:rgba(90,154,138,0.35);}
    .message.error{background:rgba(200,90,90,0.16);color:#7b2d2d;border-color:rgba(200,90,90,0.35);}

    .topbar-dev{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:18px;
    }

    .top-actions{display:flex;gap:10px;flex-wrap:wrap;}

    .btn-secondary{color:var(--primary);}
    .btn-ai{color:var(--primary);}

    .layout{
        display:grid;
        grid-template-columns:380px 1fr;
        gap:22px;
        align-items:start;
    }

    .panel{
        background:var(--surface);
        border:1px solid var(--border);
        border-radius:22px;
        box-shadow:var(--shadow);
        padding:20px;
    }

    .panel h2{margin:0 0 16px;font-size:22px;}
    .panel h3{margin:0 0 14px;font-size:18px;}

    .form-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:12px;
    }

    .form-group{display:flex;flex-direction:column;gap:6px;}
    .form-group.full{grid-column:1 / -1;}

    .dev-wrap label{font-size:13px;font-weight:700;color:var(--text-soft);}

    .dev-wrap input[type="text"],
    .dev-wrap input[type="number"],
    .dev-wrap textarea,
    .dev-wrap select{
        width:100%;
        border:1px solid var(--border);
        border-radius:12px;
        padding:11px 13px;
        font-size:14px;
        background:var(--surface);
        color:var(--text);
    }

    .dev-wrap textarea{min-height:110px;resize:vertical;}
    .check-row{display:flex;align-items:center;gap:10px;padding-top:8px;}
    .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;}

    .filters-bar{
        display:grid;
        grid-template-columns:1fr 1fr 1fr auto auto;
        gap:10px;
        align-items:end;
        margin-bottom:18px;
    }

    .cards-grid{
        display:grid;
        grid-template-columns:repeat(auto-fill, minmax(270px, 1fr));
        gap:14px;
    }

    .dev-card{
        background:var(--surface);
        border:1px solid var(--border);
        border-radius:18px;
        box-shadow:var(--shadow);
        padding:14px;
    }

    .dev-card.depth-1{margin-left:16px;}
    .dev-card.depth-2{margin-left:32px;}
    .dev-card.depth-3{margin-left:48px;}
    .dev-card.depth-4{margin-left:64px;}

    .card-top{
        display:flex;
        justify-content:space-between;
        gap:10px;
        align-items:flex-start;
        margin-bottom:10px;
    }

    .card-title{
        font-size:16px;
        font-weight:700;
        line-height:1.25;
        margin-bottom:6px;
    }

    .card-meta{
        display:flex;
        flex-wrap:wrap;
        gap:6px;
        margin-bottom:8px;
    }

    .mini-tag{
        background:var(--surface-soft);
        color:var(--text-soft);
        border:1px solid var(--border);
        border-radius:999px;
        padding:4px 9px;
        font-size:11px;
        font-weight:700;
    }

    .badge{
        border-radius:999px;
        padding:5px 9px;
        font-size:11px;
        font-weight:700;
        color:#fff;
        display:inline-block;
    }

    .badge-grey{background:#95a1b2;}
    .badge-blue{background:#3a7bd5;}
    .badge-orange{background:#ef8d32;}
    .badge-green{background:#2fa36b;}
    .badge-purple{background:#7b61ff;}
    .badge-red{background:#d9534f;}
    .badge-dark{background:#4b5563;}

    .card-lines{
        display:flex;
        flex-direction:column;
        gap:6px;
        margin-bottom:12px;
        color:var(--text-soft);
        font-size:13px;
    }

    .card-line strong{color:var(--text);}

    .card-actions{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:10px;
    }

    .btn-small{
        border:1px solid var(--border);
        border-radius:10px;
        padding:7px 10px;
        font-size:12px;
        font-weight:700;
        text-decoration:none;
        cursor:pointer;
        display:inline-block;
        background:
            radial-gradient(circle at 25% 20%, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0) 55%),
            linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(232, 238, 246, 0.9)),
            var(--surface);
        color:var(--text);
        box-shadow:0 10px 18px rgba(15, 23, 42, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.8);
    }

    .btn-edit{color:#2457a8;}
    .btn-add{color:#228354;}
    .btn-delete{color:#c64040;}
    .btn-detail{color:#6a45da;}
    .inline-form{display:inline;}

    .detail-box{
        margin-top:18px;
        border-top:1px solid var(--border);
        padding-top:18px;
    }

    .detail-content{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px;
    }

    .detail-block{
        background:var(--surface-soft);
        border:1px solid var(--border);
        border-radius:14px;
        padding:12px 14px;
        min-height:72px;
    }

    .detail-block.full{grid-column:1 / -1;}
    .detail-block-title{
        font-size:12px;
        font-weight:700;
        color:var(--text-soft);
        margin-bottom:8px;
        text-transform:uppercase;
        letter-spacing:.02em;
    }

    .detail-text{
        font-size:14px;
        line-height:1.55;
        color:var(--text);
        white-space:pre-wrap;
    }

    .empty-state{
        padding:22px;
        border:2px dashed var(--border);
        border-radius:18px;
        text-align:center;
        color:var(--text-soft);
        background:var(--surface);
    }

    @media (max-width:1200px){
        .layout{grid-template-columns:1fr;}
    }

    @media (max-width:900px){
        .filters-bar{grid-template-columns:1fr 1fr;}
    }

    @media (max-width:700px){
        .form-grid,
        .detail-content{grid-template-columns:1fr;}
        .filters-bar{grid-template-columns:1fr;}
    }
</style>

<div class="app-shell">
    <?php include __DIR__ . '/inc/sidebar.php'; ?>
    <div class="main-panel">
        <main class="content-wrapper">
            <div class="dev-wrap">
    <div class="topbar-dev">
        <div>
            <h1 class="page-title">Organisation du développement</h1>
            <div class="page-subtitle">
                Vue rapide pour créer les pages du portail, puis détail si besoin.
            </div>
        </div>

        <div class="top-actions">
            <a href="<?= h(buildQuery(['mode' => 'compact'], ['ok', 'err'])) ?>" class="btn btn-secondary">Vue compacte</a>
            <a href="<?= h(buildQuery(['mode' => 'detail'], ['ok', 'err'])) ?>" class="btn btn-secondary">Vue détail</a>
            <a href="<?= h(buildQuery([], ['edit', 'detail', 'parent_id', 'ok', 'err'])) ?>" class="btn btn-secondary">Nouvelle fiche</a>
        </div>
    </div>

    <?php include __DIR__ . '/inc/actionbar.php'; ?>

    <?php if ($message !== ''): ?>
        <div class="message <?= $messageType === 'error' ? 'error' : 'success' ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <div class="layout">

        <div class="panel">
            <h2><?= ((int)$editItem['id'] > 0) ? 'Édition rapide' : 'Création rapide' ?></h2>

            <form method="post">
                <?= csrf_field('dev_organisation_form') ?>
                <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
                <input type="hidden" name="niveau" value="<?= h((string)$editItem['niveau']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="parent_id">Parent</label>
                        <select name="parent_id" id="parent_id">
                            <option value="">-- Aucun parent --</option>
                            <?php foreach ($allItems as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= ((string)$editItem['parent_id'] === (string)$item['id']) ? 'selected' : '' ?>>
                                    <?= str_repeat('— ', max(0, ((int)$item['niveau']) - 1)) . h($item['titre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="type_element">Type</label>
                        <select name="type_element" id="type_element">
                            <?php foreach (['rubrique','page_maitre','sous_page','fonction','commentaire'] as $type): ?>
                                <option value="<?= h($type) ?>" <?= $editItem['type_element'] === $type ? 'selected' : '' ?>>
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="titre">Titre</label>
                        <input type="text" name="titre" id="titre" value="<?= h((string)$editItem['titre']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="rubrique">Rubrique</label>
                        <input type="text" name="rubrique" id="rubrique" value="<?= h((string)$editItem['rubrique']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="slug">Slug</label>
                        <input type="text" name="slug" id="slug" value="<?= h((string)$editItem['slug']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="fichier_page">Fichier</label>
                        <input type="text" name="fichier_page" id="fichier_page" value="<?= h((string)$editItem['fichier_page']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="url_relative">URL relative</label>
                        <input type="text" name="url_relative" id="url_relative" value="<?= h((string)$editItem['url_relative']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="ordre_affichage">Ordre</label>
                        <input type="number" name="ordre_affichage" id="ordre_affichage" value="<?= h((string)$editItem['ordre_affichage']) ?>">
                    </div>

                    <div class="form-group">
                        <label for="statut">Statut</label>
                        <select name="statut" id="statut">
                            <?php foreach (['idee','a_faire','en_cours','a_tester','termine','archive'] as $s): ?>
                                <option value="<?= h($s) ?>" <?= $editItem['statut'] === $s ? 'selected' : '' ?>>
                                    <?= h($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="priorite">Priorité</label>
                        <select name="priorite" id="priorite">
                            <?php foreach (['basse','normale','haute','urgente'] as $p): ?>
                                <option value="<?= h($p) ?>" <?= $editItem['priorite'] === $p ? 'selected' : '' ?>>
                                    <?= h($p) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <div class="check-row">
                            <input type="checkbox" name="visible" id="visible" value="1" <?= ((int)$editItem['visible'] === 1) ? 'checked' : '' ?>>
                            <label for="visible" style="margin:0;">Élément visible</label>
                        </div>
                    </div>

                    <?php if ($mode === 'detail' || $detailId === (int)$editItem['id']): ?>
                        <div class="form-group full">
                            <label for="description">Description</label>
                            <textarea name="description" id="description"><?= h((string)$editItem['description']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label for="objectif">Objectif</label>
                            <textarea name="objectif" id="objectif"><?= h((string)$editItem['objectif']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label for="fonctionnalites_prevues">Fonctionnalités prévues</label>
                            <textarea name="fonctionnalites_prevues" id="fonctionnalites_prevues"><?= h((string)$editItem['fonctionnalites_prevues']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label for="proposition_chatgpt">Proposition ChatGPT</label>
                            <textarea name="proposition_chatgpt" id="proposition_chatgpt"><?= h((string)$editItem['proposition_chatgpt']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label for="demande_modification_chatgpt">Demande de modification ChatGPT</label>
                            <textarea name="demande_modification_chatgpt" id="demande_modification_chatgpt"><?= h((string)$editItem['demande_modification_chatgpt']) ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label for="commentaire_dev">Commentaires développement</label>
                            <textarea name="commentaire_dev" id="commentaire_dev"><?= h((string)$editItem['commentaire_dev']) ?></textarea>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <button type="submit" name="action" value="<?= ((int)$editItem['id'] > 0) ? 'quick_edit' : 'quick_add' ?>" class="btn btn-primary">
                        <?= ((int)$editItem['id'] > 0) ? 'Enregistrer' : 'Créer la page' ?>
                    </button>

                    <?php if ($mode === 'detail' || $detailId === (int)$editItem['id']): ?>
                        <button type="submit" name="action" value="generate_ai" class="btn btn-ai">Générer le texte ChatGPT</button>
                        <button type="submit" name="action" value="rewrite_ai" class="btn btn-ai">Réécrire selon ma demande</button>
                    <?php endif; ?>

                    <a href="<?= h(buildQuery(['mode' => $mode], ['edit', 'parent_id', 'detail', 'ok', 'err'])) ?>" class="btn btn-secondary">Réinitialiser</a>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>Pages du portail</h2>

            <form method="get" style="margin-bottom:18px;">
                <input type="hidden" name="mode" value="<?= h($mode) ?>">

                <div class="filters-bar">
                    <div class="form-group">
                        <label for="vue">Affichage</label>
                        <select name="vue" id="vue" onchange="toggleElementFilter()">
                            <option value="tous" <?= $filtreVue === 'tous' ? 'selected' : '' ?>>Toute l'arborescence</option>
                            <option value="pages_maitres" <?= $filtreVue === 'pages_maitres' ? 'selected' : '' ?>>Pages maîtres uniquement</option>
                            <option value="branche" <?= $filtreVue === 'branche' ? 'selected' : '' ?>>Une branche</option>
                        </select>
                    </div>

                    <div class="form-group" id="element-filter-group" style="<?= $filtreVue === 'branche' ? '' : 'display:none;' ?>">
                        <label for="element_id">Élément</label>
                        <select name="element_id" id="element_id">
                            <option value="">-- Sélectionner --</option>
                            <?php foreach ($allItems as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $filtreElementId === (int)$item['id'] ? 'selected' : '' ?>>
                                    <?= str_repeat('— ', max(0, ((int)$item['niveau']) - 1)) . h($item['titre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mode_switch">Mode</label>
                        <select id="mode_switch" onchange="changeMode(this.value)">
                            <option value="compact" <?= $mode === 'compact' ? 'selected' : '' ?>>Compact</option>
                            <option value="detail" <?= $mode === 'detail' ? 'selected' : '' ?>>Détail</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Filtrer</button>
                    <a href="dev_organisation.php?mode=<?= h($mode) ?>" class="btn btn-secondary">Réinitialiser</a>
                </div>
            </form>

            <?php if (empty($rowsToRender)): ?>
                <div class="empty-state">Aucun élément à afficher.</div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($rowsToRender as $row): ?>
                        <?php
                        $id = (int)$row['id'];
                        $depth = max(0, ((int)$row['niveau']) - 1);
                        $parentId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
                        $parentTitle = ($parentId > 0 && isset($itemsById[$parentId])) ? (string)$itemsById[$parentId]['titre'] : '';
                        ?>
                        <div class="dev-card depth-<?= min($depth, 4) ?>">
                            <div class="card-top">
                                <div style="min-width:0;">
                                    <div class="card-title"><?= h((string)$row['titre']) ?></div>
                                    <div class="card-meta">
                                        <span class="mini-tag"><?= h((string)$row['type_element']) ?></span>
                                        <?php if (!empty($row['rubrique'])): ?>
                                            <span class="mini-tag"><?= h((string)$row['rubrique']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
                                    <span class="badge <?= h(badgeStatutClass((string)$row['statut'])) ?>"><?= h((string)$row['statut']) ?></span>
                                    <span class="badge <?= h(badgePrioriteClass((string)$row['priorite'])) ?>"><?= h((string)$row['priorite']) ?></span>
                                </div>
                            </div>

                            <div class="card-lines">
                                <?php if ($parentTitle !== ''): ?>
                                    <div class="card-line"><strong>Parent :</strong> <?= h($parentTitle) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($row['fichier_page'])): ?>
                                    <div class="card-line"><strong>Fichier :</strong> <?= h((string)$row['fichier_page']) ?></div>
                                <?php endif; ?>

                                <?php if (!empty($row['url_relative'])): ?>
                                    <div class="card-line"><strong>URL :</strong> <?= h((string)$row['url_relative']) ?></div>
                                <?php endif; ?>

                                <div class="card-line"><strong>Visible :</strong> <?= (int)$row['visible'] === 1 ? 'Oui' : 'Non' ?></div>
                            </div>

                            <div class="card-actions">
                                <a class="btn-small btn-add" href="<?= h(buildQuery(['parent_id' => $id, 'mode' => $mode], ['edit', 'detail', 'ok', 'err'])) ?>">Sous-page</a>
                                <a class="btn-small btn-edit" href="<?= h(buildQuery(['edit' => $id, 'mode' => $mode], ['detail', 'ok', 'err'])) ?>">Éditer</a>
                                <a class="btn-small btn-detail" href="<?= h(buildQuery(['detail' => $id, 'mode' => 'detail'], ['edit', 'ok', 'err'])) ?>">Détail</a>

                                <form method="post" class="inline-form" onsubmit="return confirm('Supprimer cet élément ?');">
                                    <?= csrf_field('dev_organisation_delete_' . $id) ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="btn-small btn-delete">Supprimer</button>
                                </form>
                            </div>

                            <?php if ($detailId === $id || $mode === 'detail'): ?>
                                <div class="detail-box">
                                    <div class="detail-content">
                                        <div class="detail-block">
                                            <div class="detail-block-title">Description</div>
                                            <div class="detail-text"><?= h((string)($row['description'] ?? '')) ?></div>
                                        </div>

                                        <div class="detail-block">
                                            <div class="detail-block-title">Objectif</div>
                                            <div class="detail-text"><?= h((string)($row['objectif'] ?? '')) ?></div>
                                        </div>

                                        <div class="detail-block full">
                                            <div class="detail-block-title">Fonctionnalités prévues</div>
                                            <div class="detail-text"><?= h((string)($row['fonctionnalites_prevues'] ?? '')) ?></div>
                                        </div>

                                        <div class="detail-block full">
                                            <div class="detail-block-title">Commentaires développement</div>
                                            <div class="detail-text"><?= h((string)($row['commentaire_dev'] ?? '')) ?></div>
                                        </div>

                                        <div class="detail-block full">
                                            <div class="detail-block-title">Proposition ChatGPT</div>
                                            <div class="detail-text"><?= h((string)($row['proposition_chatgpt'] ?? '')) ?></div>
                                        </div>

                                        <div class="detail-block full">
                                            <div class="detail-block-title">Demande de modification ChatGPT</div>
                                            <div class="detail-text"><?= h((string)($row['demande_modification_chatgpt'] ?? '')) ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>
        </main>
    </div>
</div>

<script>
function toggleElementFilter() {
    const vue = document.getElementById('vue');
    const group = document.getElementById('element-filter-group');
    if (!vue || !group) return;
    group.style.display = (vue.value === 'branche') ? '' : 'none';
}

function changeMode(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('mode', value);
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', toggleElementFilter);
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>




