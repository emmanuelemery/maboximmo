<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_entretien_catalog.php';
require_login();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Accès réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403); exit('Accès réservé aux managers et administrateurs.');
}

// --- Chargement entretien ---
$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('Identifiant manquant.'); }

try {
    $stmt = $pdo->prepare("
        SELECT e.*,
               u.prenom AS collab_prenom, u.nom AS collab_nom,
               u.fonction AS collab_poste
        FROM rh_entretiens e
        JOIN users u ON u.id = e.collaborateur_id
        WHERE e.id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500); exit('Erreur base de données.');
}

if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }

// Vérifier ownership (admin peut accéder à tous)
if ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId) {
    http_response_code(403); exit('Accès refusé.');
}

// Vérifier non archivé
if (in_array($entretien['statut'], ['archive'], true)) {
    http_response_code(403); exit('Cet entretien est archivé.');
}

// Mode lecture seule si terminé ou signé
$readOnly = in_array($entretien['statut'], ['termine', 'signe', 'finalise'], true);

// Mettre en cours si pas déjà (seulement si éditable)
if (!$readOnly && !in_array($entretien['statut'], ['en_cours', 'signe'], true)) {
    try {
        $pdo->prepare("UPDATE rh_entretiens SET statut = 'en_cours', updated_at = NOW() WHERE id = ?")
            ->execute([$entretienId]);
        $entretien['statut'] = 'en_cours';
    } catch (PDOException $e) { /* ignoré */ }
}

$rubriqueCourante = isset($_GET['rubrique']) ? max(1, min($nbRubriques, (int)$_GET['rubrique'])) : ((int)($entretien['rubrique_en_cours'] ?? 1) ?: 1);

// --- Chargement dynamique des rubriques et critères depuis la base ---
$rubriques = [];
$nbRubriques = 11; // fallback
try {
    $stmtRub = $pdo->prepare("SELECT * FROM rh_entretien_rubriques WHERE actif=1 ORDER BY ordre");
    $stmtRub->execute();
    $rubriquesRows = $stmtRub->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rubriquesRows)) {
        $stmtCrit = $pdo->prepare("SELECT * FROM rh_entretien_criteres WHERE actif=1 ORDER BY rubrique_id, ordre");
        $stmtCrit->execute();
        $allCriteres = $stmtCrit->fetchAll(PDO::FETCH_ASSOC);
        $critParRub = [];
        foreach ($allCriteres as $crit) {
            $critParRub[(int)$crit['rubrique_id']][] = $crit;
        }
        foreach ($rubriquesRows as $rub) {
            $rubriques[(int)$rub['ordre']] = array_merge($rub, [
                'criteres'    => $critParRub[(int)$rub['id']] ?? [],
                'manager_only'=> (bool)$rub['manager_only'],
            ]);
        }
        $nbRubriques = count($rubriques);
    }
} catch (PDOException $e) { /* tables pas encore créées */ }

// Fallback hardcodé si les tables n'existent pas encore
if (empty($rubriques)) {
    $rubriques = [
        1  => ['id'=>1,'nom'=>'Ouverture','ordre'=>1,'manager_only'=>true,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0,
               'criteres'=>[['id'=>1,'label'=>'Accueil et mise en confiance','type_champ'=>'etoiles','axe_radar'=>'relationnel','poids_score'=>0.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0],
                             ['id'=>2,'label'=>'Rappel objectifs entretien','type_champ'=>'etoiles','axe_radar'=>'organisation','poids_score'=>0.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0],
                             ['id'=>3,'label'=>'Validation du cadre','type_champ'=>'etoiles','axe_radar'=>'organisation','poids_score'=>0.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0]]],
        2  => ['id'=>2,'nom'=>'Bilan collaborateur','ordre'=>2,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>4,'label'=>'Bilan général de la période','type_champ'=>'etoiles_texte','axe_radar'=>'performance','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>5,'label'=>'Réalisations principales','type_champ'=>'etoiles_texte','axe_radar'=>'performance','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>6,'label'=>'Difficultés rencontrées','type_champ'=>'etoiles_texte','axe_radar'=>'adaptabilite','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        3  => ['id'=>3,'nom'=>'Valorisation','ordre'=>3,'manager_only'=>true,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0,
               'criteres'=>[['id'=>7,'label'=>'Reconnaissance des efforts','type_champ'=>'etoiles','axe_radar'=>'motivation','poids_score'=>0.8,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0],
                             ['id'=>8,'label'=>'Points forts identifiés','type_champ'=>'etoiles_texte','axe_radar'=>'potentiel','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0],
                             ['id'=>9,'label'=>'Contribution à l\'équipe','type_champ'=>'etoiles','axe_radar'=>'relationnel','poids_score'=>0.8,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0]]],
        4  => ['id'=>4,'nom'=>'Performance','ordre'=>4,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>10,'label'=>'Atteinte des objectifs','type_champ'=>'etoiles_texte','axe_radar'=>'performance','poids_score'=>1.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>11,'label'=>'Qualité du travail','type_champ'=>'etoiles_texte','axe_radar'=>'performance','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>12,'label'=>'Respect des délais','type_champ'=>'etoiles','axe_radar'=>'organisation','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        5  => ['id'=>5,'nom'=>'Comportement & Relationnel','ordre'=>5,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>13,'label'=>'Relationnel équipe','type_champ'=>'etoiles_texte','axe_radar'=>'relationnel','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>14,'label'=>'Respect des règles','type_champ'=>'etoiles','axe_radar'=>'engagement','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>15,'label'=>'Communication','type_champ'=>'etoiles_texte','axe_radar'=>'relationnel','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        6  => ['id'=>6,'nom'=>'Motivation','ordre'=>6,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>16,'label'=>'Niveau de motivation actuel','type_champ'=>'etoiles_texte','axe_radar'=>'motivation','poids_score'=>1.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>17,'label'=>'Sources de motivation','type_champ'=>'etoiles_texte','axe_radar'=>'motivation','poids_score'=>1.0,'seuil_alerte'=>1,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>18,'label'=>'Freins et facteurs de démotivation','type_champ'=>'bloc_motivation','axe_radar'=>'motivation','poids_score'=>1.2,'seuil_alerte'=>3,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        7  => ['id'=>7,'nom'=>'Adaptabilité','ordre'=>7,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>19,'label'=>'Capacité d\'adaptation au changement','type_champ'=>'etoiles_texte','axe_radar'=>'adaptabilite','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>20,'label'=>'Gestion du stress et des priorités','type_champ'=>'etoiles_texte','axe_radar'=>'adaptabilite','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>21,'label'=>'Polyvalence et prise d\'initiative','type_champ'=>'etoiles','axe_radar'=>'autonomie','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        8  => ['id'=>8,'nom'=>'Compétences & Formation','ordre'=>8,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>22,'label'=>'Maîtrise actuelle du poste','type_champ'=>'etoiles_texte','axe_radar'=>'maitrise_poste','poids_score'=>1.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>23,'label'=>'Besoins en formation','type_champ'=>'bloc_formation','axe_radar'=>'maitrise_poste','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>24,'label'=>'Projet d\'évolution professionnelle','type_champ'=>'bloc_evolution','axe_radar'=>'potentiel','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        9  => ['id'=>9,'nom'=>'Analyse RH','ordre'=>9,'manager_only'=>true,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0,
               'criteres'=>[['id'=>25,'label'=>'Potentiel d\'évolution','type_champ'=>'etoiles_texte','axe_radar'=>'potentiel','poids_score'=>1.5,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0],
                             ['id'=>26,'label'=>'Risques identifiés','type_champ'=>'etoiles_texte','axe_radar'=>'engagement','poids_score'=>1.2,'seuil_alerte'=>3,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0],
                             ['id'=>27,'label'=>'Adéquation poste / profil','type_champ'=>'etoiles_texte','axe_radar'=>'maitrise_poste','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>0,'visible_pdf'=>0]]],
        10 => ['id'=>10,'nom'=>'Plan d\'action','ordre'=>10,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>28,'label'=>'Objectifs période suivante','type_champ'=>'etoiles_texte','axe_radar'=>'performance','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>29,'label'=>'Actions concrètes','type_champ'=>'etoiles_texte','axe_radar'=>'organisation','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>30,'label'=>'Responsabilités et engagement','type_champ'=>'etoiles','axe_radar'=>'engagement','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
        11 => ['id'=>11,'nom'=>'Synthèse & Clôture','ordre'=>11,'manager_only'=>false,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1,
               'criteres'=>[['id'=>31,'label'=>'Points clés retenus','type_champ'=>'etoiles_texte','axe_radar'=>'performance','poids_score'=>1.0,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>32,'label'=>'Engagements mutuels','type_champ'=>'etoiles_texte','axe_radar'=>'engagement','poids_score'=>1.2,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1],
                             ['id'=>33,'label'=>'Suite du processus','type_champ'=>'etoiles','axe_radar'=>'organisation','poids_score'=>0.8,'seuil_alerte'=>2,'visible_manager'=>1,'visible_collaborateur'=>1,'visible_pdf'=>1]]],
    ];
    $nbRubriques = 11;
}

// Construire la map critère_id → axe_radar pour JS
$axeMapData = [];
foreach ($rubriques as $rub) {
    foreach ($rub['criteres'] as $crit) {
        if (!empty($crit['axe_radar'])) {
            $axeMapData[(int)$crit['id']] = $crit['axe_radar'];
        }
    }
}

// --- Charger les réponses existantes (manager) ---
$reponsesManager = [];
try {
    $stmtRep = $pdo->prepare("SELECT rubrique_id, critere_id, note, note_collaborateur, texte_final, reponse_type_id, visible_collaborateur FROM rh_entretien_reponses WHERE entretien_id = ?");
    $stmtRep->execute([$entretienId]);
    foreach ($stmtRep->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reponsesManager[$r['rubrique_id']][$r['critere_id']] = $r;
    }
} catch (PDOException $e) { /* table peut ne pas exister encore */ }

// --- Charger les réponses préalables du collaborateur ---
$reponsesCollab = [];
try {
    $stmtQ = $pdo->prepare("SELECT question_id, rubrique, note, texte, radio_choix FROM rh_entretien_questionnaire WHERE entretien_id = ?");
    $stmtQ->execute([$entretienId]);
    foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reponsesCollab[(int)$r['question_id']] = $r;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Charger les réponses types ---
$reponsesTypes = [];
try {
    $stmtRT = $pdo->prepare("SELECT id, rubrique_id, critere_id, note_cible, tonalite, texte,
                                       visible_manager, visible_collaborateur, visible_pdf
                             FROM rh_entretien_reponses_types WHERE actif = 1
                             ORDER BY rubrique_id, critere_id, ordre");
    $stmtRT->execute();
    foreach ($stmtRT->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reponsesTypes[$r['rubrique_id']][$r['critere_id']][] = $r;
    }
    // Mélanger les réponses types par critère (ordre aléatoire à chaque chargement)
    foreach ($reponsesTypes as &$rtRub) {
        foreach ($rtRub as &$rtCrit) {
            shuffle($rtCrit);
        }
    }
    unset($rtRub, $rtCrit);
} catch (PDOException $e) { /* ignoré */ }

// --- Charger les phrases manager ---
$phrasesManager = [];
try {
    $stmtPM = $pdo->prepare("SELECT id, categorie, texte FROM rh_entretien_phrases_manager WHERE actif = 1 ORDER BY categorie, ordre");
    $stmtPM->execute();
    foreach ($stmtPM->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $phrasesManager[$p['categorie']][] = $p;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Charger les scores actuels ---
$scores = [];
try {
    $stmtSc = $pdo->prepare("SELECT axe, score FROM rh_entretien_scores WHERE entretien_id = ?");
    $stmtSc->execute([$entretienId]);
    foreach ($stmtSc->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $scores[$s['axe']] = (float)$s['score'];
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Charger les alertes ---
$alertes = [];
try {
    $stmtAl = $pdo->prepare("SELECT type_alerte, niveau, message FROM rh_entretien_alertes WHERE entretien_id = ? AND resolu = 0 ORDER BY created_at DESC");
    $stmtAl->execute([$entretienId]);
    $alertes = $stmtAl->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// --- Charger les introductions et objectifs des rubriques ---
try {
    $stmtIntro = $pdo->query("SELECT ordre, introduction, objectif FROM rh_entretien_rubriques WHERE actif=1 ORDER BY ordre");
    foreach ($stmtIntro->fetchAll(PDO::FETCH_ASSOC) as $ri) {
        $rOrdre = (int)$ri['ordre'];
        if (isset($rubriques[$rOrdre])) {
            $rubriques[$rOrdre]['introduction'] = $ri['introduction'] ?? '';
            $rubriques[$rOrdre]['objectif']     = $ri['objectif'] ?? '';
        }
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Charger les synthèses existantes ---
$synthesesDispo = [];
try {
    $stmtSyn = $pdo->prepare("SELECT rubrique_id, synthese_manager, synthese_collab, tonalite FROM rh_entretien_syntheses WHERE entretien_id = ?");
    $stmtSyn->execute([$entretienId]);
    foreach ($stmtSyn->fetchAll(PDO::FETCH_ASSOC) as $syn) {
        $synthesesDispo[(int)$syn['rubrique_id']] = $syn;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Déterminer la société pour le catalogue multi-tenant ---
$entretienSocieteId = null;
try {
    // Chercher via le collaborateur de l'entretien
    $stmtSocId = $pdo->prepare("SELECT id_societe FROM users WHERE id = ? LIMIT 1");
    $stmtSocId->execute([(int)$entretien['collaborateur_id']]);
    $rowSoc = $stmtSocId->fetch(PDO::FETCH_ASSOC);
    if ($rowSoc && !empty($rowSoc['id_societe'])) {
        $entretienSocieteId = (int)$rowSoc['id_societe'];
    }
} catch (PDOException $e) { /* ignoré */ }
// Fallback : société de l'utilisateur connecté
if (!$entretienSocieteId) {
    $entretienSocieteId = current_societe_id();
}

// --- Charger les questions (rh_entretien_questions) et leurs options ---
$questionsParRubrique = [];
$questionOptions      = [];
$reponsesQuestions    = [];
try {
    if ($entretienSocieteId) {
        // Chargement multi-tenant via catalog
        $catalogData          = catalog_load_for_entretien($pdo, $entretienSocieteId);
        $questionsParRubrique = $catalogData['questions_par_rubrique'];
        $questionOptions      = $catalogData['options_par_question'];
    } else {
        // Fallback global (pas de société détectée)
        $stmtNQ = $pdo->query(
            "SELECT q.*, r.ordre AS r_ordre
             FROM rh_entretien_questions_user q
             JOIN rh_entretien_rubriques r ON r.id = q.rubrique_id
             WHERE q.actif = 1 ORDER BY r.ordre, q.ordre"
        );
        foreach ($stmtNQ->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $questionsParRubrique[(int)$q['r_ordre']][] = $q;
        }
        if (empty($questionsParRubrique)) {
            $stmtNQ2 = $pdo->query("SELECT * FROM rh_entretien_questions_user WHERE actif = 1 ORDER BY rubrique_id, ordre");
            foreach ($stmtNQ2->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $questionsParRubrique[(int)$q['rubrique_id']][] = $q;
            }
        }
        $stmtNO = $pdo->query("SELECT * FROM rh_entretien_question_options WHERE actif = 1 ORDER BY question_id, ordre");
        foreach ($stmtNO->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            $questionOptions[(int)$opt['question_id']][] = $opt;
        }
    }
    // Mélanger l'ordre d'affichage des options par question (stable par requête)
    foreach ($questionOptions as &$optArr) {
        shuffle($optArr);
    }
    unset($optArr);
    // Charger les variantes de formulation
    $optionVariantes = [];
    $stmtNV = $pdo->query("SELECT option_id, texte FROM rh_entretien_options_variantes WHERE actif = 1");
    foreach ($stmtNV->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $optionVariantes[(int)$v['option_id']][] = $v['texte'];
    }
    $stmtNR = $pdo->prepare("SELECT * FROM rh_entretien_reponses_questions WHERE entretien_id = ?");
    $stmtNR->execute([$entretienId]);
    foreach ($stmtNR->fetchAll(PDO::FETCH_ASSOC) as $rq) {
        $reponsesQuestions[(int)$rq['question_id']] = $rq;
    }
} catch (PDOException $e) { /* ignoré si tables manquantes */ }

// --- Statut auto-eval collab ---
$autoEvalSubmitted = !empty($entretien['auto_eval_submitted_at']);
$autoEvalCount = 0;
foreach ($reponsesManager as $rub) {
    foreach ($rub as $r) {
        if ($r['note_collaborateur'] !== null) $autoEvalCount++;
    }
}

// --- Données JS ---
$jsReadOnly        = json_encode($readOnly);
$jsEntretienId     = json_encode($entretienId);
$jsRubriqueCourante = json_encode($rubriqueCourante);
$jsReponsesManager = json_encode($reponsesManager);
$jsReponsesCollab  = json_encode($reponsesCollab);
$jsReponsesTypes   = json_encode($reponsesTypes);
$jsScores          = json_encode($scores ?: (object)[]);
$jsAlertes         = json_encode($alertes);
$jsRubriques       = json_encode(array_map(fn($r) => ['nom' => $r['nom'], 'criteres' => $r['criteres']], $rubriques));
$jsAxeMap          = json_encode($axeMapData);
$jsNbRubriques     = json_encode($nbRubriques);

// --- Layout variables ---
$layout_title       = 'Entretien — ' . ($entretien['collab_prenom'] ?? '') . ' ' . ($entretien['collab_nom'] ?? '');
$layout_module      = 'Ma Box RH';
$layout_sidebar     = 'rh_sidebar';
$layout_head_kpis   = '';
$layout_head_actions = '';

$layout_extra_css = '<meta name="csrf-token" content="' . h(csrf_token()) . '">'
    . '<link rel="stylesheet" href="css/theme-rh.css">'
    . '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>'
    . <<<'EXTRACSS'
<style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Sora', sans-serif;
            background: var(--bg-secondary);
            color: #1a1816;
            margin: 0; padding: 0;
            display: flex; height: 100vh; overflow: hidden;
        }

        /* Intégration sidebar */
        .tenir-main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        /* ===== TOP BAR ===== */
        .top-bar {
            background: var(--bg-primary);
            box-shadow: 0 4px 12px rgba(196,192,186,0.45);
            padding: 0 24px;
            height: 56px;
            display: flex; align-items: center; gap: 12px;
            flex-shrink: 0; z-index: 200;
        }
        .top-bar-title {
            font-weight: 700; font-size: 14px; color: #1a1816;
            font-family: 'Sora', sans-serif;
        }
        .top-bar-sub {
            font-family: 'DM Mono', monospace; font-size: 11px; color: #8a8680;
            letter-spacing: 0.04em;
        }
        .top-bar-status {
            margin-left: auto;
            display: flex; align-items: center; gap: 8px;
            font-family: 'DM Mono', monospace; font-size: 11px; color: #8a8680;
        }
        .save-dot {
            width: 8px; height: 8px; border-radius: 50%; background: #c8c4be;
            transition: background .3s;
        }
        .save-dot.saving { background: var(--btn-save-from); }
        .save-dot.saved  { background: #7a9060; }
        .save-dot.error  { background: #8a5040; }

        /* ===== MODE LECTURE SEULE ===== */
        <?php if ($readOnly): ?>
        .star-btn          { cursor: default !important; pointer-events: none; }
        .rt-btn            { cursor: default !important; pointer-events: none; opacity: .65; }
        textarea, input[type="text"] { background: var(--bg-secondary) !important; cursor: default !important; }
        .btn-finish        { display: none !important; }
        .col-right         { border-left: 3px solid #a5b4fc; }
        <?php endif; ?>

        /* steps */
        .step-btn {
            flex-shrink: 0;
            padding: 4px 10px;
            border-radius: 999px;
            border: none;
            background: var(--bg-primary);
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            font-size: 11px; font-weight: 500; cursor: pointer;
            color: #6a6660; font-family: 'Sora', sans-serif;
            transition: all .2s; white-space: nowrap;
        }
        .step-btn:hover      { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); }
        .step-btn.active     { background: var(--btn-save-from); color: #fff; box-shadow: 3px 3px 8px rgba(249,115,22,0.4); font-weight: 700; }
        .step-btn.done       { color: #4a6038; }
        .step-connector      { width: 12px; height: 1px; background: #e4e6ec; flex-shrink: 0; }

        /* ===== LAYOUT 3 COLONNES ===== */
        .layout {
            display: flex; flex: 1; overflow: hidden;
        }

        /* --- Colonne gauche : nav rubriques --- */
        .col-nav {
            width: 210px; flex-shrink: 0;
            background: #ede8e0;
            border-right: 1px solid #e4e6ec;
            box-shadow: 4px 0 12px rgba(196,192,186,0.3);
            overflow-y: auto;
            padding: 14px 10px;
        }
        .col-nav-title {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.2em; color: #b8922a;
            margin-bottom: 10px; padding: 0 8px;
        }
        .nav-rubrique-btn {
            display: flex; align-items: center; gap: 8px;
            width: 100%; padding: 8px 10px;
            border: none;
            background: #ede8e0;
            border-radius: 12px; cursor: pointer;
            font-family: 'Sora', sans-serif;
            font-size: 11px; font-weight: 500; color: #3a3830;
            text-align: left; transition: all .15s;
            margin-bottom: 4px;
            box-shadow: 4px 4px 9px #c8c4be, -4px -4px 9px #f5f2ed;
        }
        .nav-rubrique-btn:hover  { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 7px #f5f2ed; }
        .nav-rubrique-btn.active {
            box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 7px #f5f2ed;
            color: var(--btn-save-from); font-weight: 700;
        }
        .nav-rubrique-btn.done   { color: #4a6038; }
        .nav-rubrique-num {
            width: 20px; height: 20px; border-radius: 50%;
            background: #ede8e0;
            box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 4px #f5f2ed;
            color: #8a8680;
            font-family: 'DM Mono', monospace;
            font-size: 10px; font-weight: 500;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: all .15s;
        }
        .nav-rubrique-btn.active .nav-rubrique-num { background: var(--btn-save-from); color: #fff; box-shadow: 2px 2px 5px rgba(249,115,22,0.4); }
        .nav-rubrique-btn.done   .nav-rubrique-num { background: #7a9060; color: #fff; box-shadow: 2px 2px 5px rgba(122,144,96,0.4); }

        /* --- Colonne centre --- */
        .col-main {
            flex: 1; overflow-y: auto; padding: 20px 24px;
            min-width: 0; background: var(--bg-secondary);
        }
        .rubrique-panel { display: none; }
        .rubrique-panel.active { display: block; }

        .rubrique-heading {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 20px;
        }
        .rubrique-heading-num {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--btn-save-from); color: #fff;
            font-weight: 800; font-size: 14px;
            font-family: 'DM Mono', monospace;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 3px 3px 8px rgba(249,115,22,0.4), -2px -2px 6px rgba(255,255,255,0.5);
        }
        .rubrique-heading-name {
            font-size: 18px; font-weight: 700; color: #1a1816;
            font-family: 'Sora', sans-serif;
        }
        .rubrique-heading-badge {
            font-family: 'DM Mono', monospace;
            font-size: 9px; background: #fef3c7; color: #92400e;
            padding: 3px 8px; border-radius: 999px; font-weight: 600;
            letter-spacing: 0.06em;
        }

        /* Réponses collab */
        .collab-reponses-box {
            background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px;
            padding: 1rem 1.25rem; margin-bottom: 1.5rem;
        }
        .collab-reponses-title {
            font-size: .75rem; font-weight: 700; color: #0369a1;
            text-transform: uppercase; letter-spacing: .05em; margin-bottom: .5rem;
        }
        .collab-rep-item { font-size: .85rem; color: #0c4a6e; margin-bottom: .35rem; line-height: 1.5; }

        /* Critère block */
        .critere-block {
            background: var(--bg-primary);
            border: none;
            border-radius: 16px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            padding: 18px 20px;
            margin-bottom: 14px;
            transition: box-shadow .2s;
        }
        .critere-block:focus-within {
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light), 0 0 0 2px rgba(249,115,22,0.3);
        }

        /* Réponse collab inline dans la carte critère */
        .collab-inline {
            background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;
            padding: .6rem .9rem; margin-bottom: .75rem; font-size: .82rem; color: #0c4a6e;
            display: flex; gap: .5rem; align-items: flex-start;
        }
        .collab-inline-icon { flex-shrink: 0; font-size: .9rem; }
        .collab-inline-text { line-height: 1.5; }
        .collab-inline-note { font-weight: 700; color: #f59e0b; letter-spacing: .05em; }

        /* Phrases types */
        .rt-section-title {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.16em; color: #a8a49e;
            margin-bottom: 6px;
        }
        .rt-grid { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .rt-btn {
            background: var(--bg-primary);
            border: none;
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            border-radius: 8px;
            padding: 7px 12px;
            font-family: 'Sora', sans-serif; font-size: 12px;
            color: #3a3830;
            cursor: pointer;
            transition: all .15s;
            text-align: left; line-height: 1.4;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .rt-btn:hover { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); color: var(--btn-save-from); }
        .rt-btn.selected { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); color: var(--btn-save-from); font-weight: 600; }

        /* Phrases manager inline dans la rubrique */
        .phrases-panel {
            background: var(--bg-primary);
            border: none;
            box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
            border-radius: 16px; padding: 16px 18px; margin-bottom: 20px;
        }
        .phrases-panel-title {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.18em; color: #7c3aed; margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .phrases-grid { display: flex; flex-wrap: wrap; gap: 6px; }
        .phrase-chip {
            background: var(--bg-primary);
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            border: none;
            border-radius: 8px; padding: 5px 10px;
            font-family: 'Sora', sans-serif; font-size: 11px; color: #5b21b6;
            cursor: pointer; transition: all .15s; line-height: 1.4;
        }
        .phrase-chip:hover { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); }
        .phrase-chip.copied { color: #4a6038; }

        /* ===== CRITÈRE CARD ===== */
        .criteres-container { display: flex; flex-direction: column; gap: 1.25rem; }

        .critere-label {
            font-family: 'Sora', sans-serif;
            font-size: 13px; font-weight: 700; color: #1a1816;
            margin-bottom: 12px;
        }

        /* Stars */
        .note-stars { display: flex; gap: .3rem; margin-bottom: .65rem; }
        .star-btn {
            font-size: 1.6rem; background: none; border: none;
            cursor: pointer; color: #d1d5db; line-height: 1;
            transition: color .1s, transform .1s; padding: 0;
        }
        .star-btn:hover, .star-btn.active { color: #f59e0b; }
        .star-btn:hover { transform: scale(1.15); }

        /* Réponses types */
        .reponses-types {
            display: flex; flex-wrap: wrap; gap: .4rem;
            margin-bottom: .65rem;
        }
        .rt-btn {
            background: var(--bg-primary);
            border: none;
            border-radius: 6px;
            padding: .3rem .65rem;
            font-size: .75rem;
            color: #1a1816;
            cursor: pointer;
            transition: all .15s;
            text-align: left;
        }
        .rt-btn:hover { background: #fff7ed; border-color: var(--btn-save-from); color: var(--btn-save-from); }

        /* Textarea */
        .commentaire-input {
            width: 100%; border: none;
            border-radius: 10px; padding: 10px 14px;
            font-size: 13px; font-family: 'Sora', sans-serif;
            resize: vertical; min-height: 80px;
            color: #1a1816;
            background: var(--bg-primary);
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            transition: box-shadow .2s;
        }
        .commentaire-input:focus {
            outline: none;
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light), 0 0 0 2px rgba(249,115,22,0.25);
        }

        /* Actions critère */
        .critere-actions {
            display: flex; gap: 6px; margin-top: 8px;
        }
        .btn-action {
            padding: 4px 12px; border-radius: 999px;
            border: none;
            background: var(--bg-primary);
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            font-family: 'Sora', sans-serif; font-size: 11px;
            cursor: pointer; transition: all .15s; color: #6a6660;
        }
        .btn-action:hover { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); }
        .btn-ia    { color: #7c3aed; }
        .btn-ia:hover { color: #7c3aed; }
        .btn-vocal { color: #4a6038; }
        .btn-vocal.recording { color: #8a5040; animation: pulse 1s infinite; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.6; } }

        /* IA popup */
        .ia-popup {
            position: absolute; background: var(--bg-primary);
            border: none;
            border-radius: 10px; box-shadow: 0 8px 24px #f7f8fa;
            padding: 1rem; width: 320px; z-index: 500;
            display: none;
        }
        .ia-popup.show { display: block; }

        /* ===== BLOCS SPÉCIAUX ===== */
        .bloc-special {
            background: var(--bg-primary);
            box-shadow: inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light);
            border-radius: 14px; padding: 16px 18px; margin-top: 12px;
        }
        .bloc-special-title {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.16em; color: #c2410c; margin-bottom: 12px;
            display: flex; align-items: center; gap: 6px;
        }
        .bloc-special-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
        }
        .bloc-special-grid.full { grid-template-columns: 1fr; }
        .bloc-field { display: flex; flex-direction: column; gap: 4px; }
        .bloc-field label {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; color: #a8a49e;
            text-transform: uppercase; letter-spacing: 0.12em;
        }
        .bloc-field label .req { color: #8a5040; }
        .bloc-field select, .bloc-field textarea, .bloc-field input[type=text] {
            font-family: 'Sora', sans-serif; font-size: 12px;
            padding: 7px 10px;
            border: none; border-radius: 8px;
            background: var(--bg-primary);
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
            width: 100%; color: #1a1816;
        }
        .bloc-field textarea { resize: vertical; min-height: 60px; }
        .bloc-field select:focus, .bloc-field textarea:focus { outline: none; box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light), 0 0 0 2px rgba(249,115,22,0.2); }
        .bloc-validation-msg {
            font-size: .72rem; color: #ef4444; margin-top: .4rem; display: none;
        }
        .bloc-classification {
            margin-top: .75rem; padding: .65rem 1rem;
            background: #fff7ed; border-radius: 8px;
            font-size: .8rem; font-weight: 600; color: #c2410c;
        }
        .bloc-classification span { color: #1a1816; }
        .bloc-incoherence {
            margin-top: .6rem; padding: .5rem .85rem;
            background: #fef2f2; border: 1px solid #fecaca; border-radius: 7px;
            font-size: .75rem; color: #b91c1c; display: none;
        }
        .rt-tonalite {
            display: inline-block; font-size: .6rem; font-weight: 700;
            padding: .1rem .35rem; border-radius: 4px; margin-right: .3rem;
            vertical-align: middle; text-transform: uppercase; letter-spacing: .05em;
        }
        .ton-positive  { background: #dcfce7; color: #166534; }
        .ton-neutre    { background: #f1f5f9; color: #475569; }
        .ton-vigilance { background: #fef9c3; color: #854d0e; }
        .ton-corrective{ background: #fee2e2; color: #991b1b; }
        .ton-evolution { background: #ede9fe; color: #5b21b6; }
        .ia-popup-title { font-size: .75rem; font-weight: 700; color: #7c3aed; margin-bottom: .5rem; }
        .ia-popup-text  { font-size: .85rem; color: #1a1816; line-height: 1.5; margin-bottom: .75rem; }
        .ia-popup-actions { display: flex; gap: .5rem; }
        .btn-ia-insert {
            background: var(--btn-save-from); color: #fff; border: none;
            border-radius: 6px; padding: .35rem .75rem; font-size: .75rem;
            cursor: pointer;
        }
        .btn-ia-close {
            background: var(--bg-primary); border: none;
            border-radius: 6px; padding: .35rem .75rem; font-size: .75rem;
            cursor: pointer;
        }

        /* Navigation bas */
        .nav-bottom {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 28px; padding-top: 20px;
            border-top: 1px solid rgba(196,192,186,0.4);
        }
        .btn-nav {
            padding: 0 20px; height: 38px; border-radius: 999px;
            font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
            cursor: pointer; transition: all .2s; border: none;
        }
        .btn-prev {
            background: var(--bg-primary); color: #6a6660;
            box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
        }
        .btn-prev:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
        .btn-next {
            background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to)); color: #fff;
            box-shadow: 4px 4px 10px rgba(249,115,22,0.35), -2px -2px 6px rgba(255,255,255,0.5);
        }
        .btn-next:hover { box-shadow: 5px 5px 12px rgba(249,115,22,0.45), -2px -2px 6px rgba(255,255,255,0.6); }
        .btn-finish {
            background: linear-gradient(135deg, #4a6038, #7a9060); color: #fff; border: none;
            box-shadow: 4px 4px 10px rgba(74,96,56,0.35), -2px -2px 6px rgba(255,255,255,0.5);
        }
        .btn-finish:hover { opacity: .88; }

        /* --- Colonne droite --- */
        .col-right {
            width: 280px; flex-shrink: 0;
            background: #ede8e0;
            border-left: 1px solid #e4e6ec;
            box-shadow: -4px 0 12px rgba(196,192,186,0.2);
            overflow-y: auto;
            padding: 14px 12px;
            transition: width .3s, padding .3s;
        }
        .col-right.collapsed { width: 36px; padding: 12px 6px; overflow: hidden; }

        .col-right-toggle {
            display: flex; justify-content: flex-end; margin-bottom: 10px;
        }
        .btn-toggle-right {
            background: #ede8e0;
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 7px #f5f2ed;
            border: none; border-radius: 8px; padding: 4px 8px;
            cursor: pointer; font-size: 11px; color: #8a8680;
        }
        .btn-toggle-right:active { box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 4px #f5f2ed; }
        .col-right-content { min-width: 240px; }
        .col-right.collapsed .col-right-content { display: none; }

        .right-section-title {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; text-transform: uppercase;
            letter-spacing: 0.18em; color: #b8922a;
            margin-bottom: 8px;
        }

        /* Radar */
        .radar-wrap { margin-bottom: 18px; }
        .radar-wrap canvas { width: 100% !important; }

        /* Alertes */
        .alerte-item {
            background: rgba(204,92,88,0.1);
            border-radius: 10px; padding: 8px 12px;
            box-shadow: inset 2px 2px 5px rgba(204,92,88,0.15);
            font-family: 'Sora', sans-serif; font-size: 11px; color: #991b1b;
            margin-bottom: 6px; line-height: 1.4;
        }
        .alerte-item.warning {
            background: rgba(245,158,11,0.1);
            box-shadow: inset 2px 2px 5px rgba(245,158,11,0.15);
            color: #92400e;
        }
        .no-alerts {
            font-family: 'DM Mono', monospace; font-size: 10px;
            color: #a8a49e; font-style: italic;
        }

        /* Phrases manager côté droit */
        .phrases-categorie { margin-bottom: 12px; }
        .phrases-cat-title {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; color: #a8a49e;
            margin-bottom: 5px; text-transform: capitalize; letter-spacing: 0.08em;
        }
        .phrase-copy {
            display: block; width: 100%; text-align: left;
            background: #ede8e0;
            box-shadow: 3px 3px 6px #c8c4be, -3px -3px 6px #f5f2ed;
            border: none; border-radius: 8px; padding: 6px 10px;
            font-family: 'Sora', sans-serif; font-size: 11px;
            cursor: pointer; transition: all .15s;
            color: #3a3830; margin-bottom: 4px; line-height: 1.4;
        }
        .phrase-copy:hover { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px #f5f2ed; color: var(--btn-save-from); }
        .phrase-copy.copied { color: #4a6038; }

        @media (max-width: 900px) {
            .col-nav { display: none; }
            .col-right { display: none; }
        }

        /* ===== INTRO & SYNTHÈSE RUBRIQUE ===== */
        .rubrique-intro {
            background: rgba(54,87,125,0.08);
            box-shadow: inset 3px 3px 8px rgba(54,87,125,0.1);
            border-left: 4px solid #36577d;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
            font-family: 'Sora', sans-serif; font-size: 13px;
            color: #2f587d;
            line-height: 1.6;
            position: relative;
        }
        .rubrique-intro-icon {
            display: inline-block; margin-right: 6px;
        }
        .rubrique-intro-objectif {
            font-family: 'DM Mono', monospace;
            font-size: 9px; font-weight: 500; color: #36577d;
            text-transform: uppercase; letter-spacing: 0.1em;
            margin-top: 8px; display: block;
            opacity: .8;
        }

        .rubrique-synthese-wrap {
            margin-top: 28px;
            border-top: 1px solid rgba(196,192,186,0.4);
            padding-top: 1.25rem;
        }
        .rubrique-synthese {
            border-radius: 14px;
            padding: 14px 16px;
            font-family: 'Sora', sans-serif; font-size: 13px;
            line-height: 1.6;
            position: relative;
            transition: opacity .3s;
        }
        .rubrique-synthese.ton-positif {
            background: rgba(122,144,96,0.1);
            box-shadow: inset 3px 3px 8px rgba(122,144,96,0.12);
            border-left: 4px solid #7a9060;
            color: #2a4020;
        }
        .rubrique-synthese.ton-vigilance {
            background: rgba(245,158,11,0.1);
            box-shadow: inset 3px 3px 8px rgba(245,158,11,0.12);
            border-left: 4px solid #f59e0b;
            color: #78350f;
        }
        .rubrique-synthese.ton-neutre {
            background: var(--bg-primary);
            box-shadow: inset 3px 3px 8px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            border-left: 4px solid #8a8680;
            color: #3a3830;
        }
        .rubrique-synthese.ton-loading {
            background: var(--bg-primary);
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
            color: #a8a49e;
            opacity: .7;
        }
        .synthese-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 8px;
        }
        .synthese-label {
            font-size: .68rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: .08em;
        }
        .synthese-refresh-btn {
            background: none; border: none; cursor: pointer; font-size: .75rem;
            color: #8a8680; padding: .1rem .35rem;
            border-radius: 4px; transition: all .15s; opacity: .7;
        }
        .synthese-refresh-btn:hover { opacity: 1; background: var(--bg-primary); }
        .synthese-refresh-btn.spinning { animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .synthese-manager-text { margin-bottom: .5rem; }
        .synthese-collab-text {
            font-size: .82rem; font-style: italic; opacity: .85;
            border-top: 1px solid currentColor; border-top-opacity: .2;
            padding-top: .45rem; margin-top: .45rem;
        }
        .synthese-toggle-collab {
            font-size: .7rem; color: inherit; opacity: .7;
            cursor: pointer; text-decoration: underline; background: none; border: none;
        }

        /* ===== QUESTION CAROUSEL ===== */
        .q-carousel {
            background: var(--bg-primary);
            border: none;
            box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light), 0 0 0 2px rgba(249,115,22,0.2);
            border-radius: 20px;
            padding: 20px 22px 16px;
            margin-bottom: 22px;
        }
        .q-carousel-header {
            display: flex; align-items: center; gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .q-progress-bar {
            flex: 1; height: 4px; border-radius: 2px;
            background: #e4e6ec; overflow: hidden;
        }
        .q-progress-fill {
            height: 100%; border-radius: 2px;
            background: var(--btn-save-from);
            transition: width .4s ease;
        }
        .q-counter {
            font-size: .72rem; font-weight: 700; color: var(--btn-save-from);
            white-space: nowrap;
        }

        /* Stage & cards */
        .q-stage { position: relative; overflow: hidden; }
        .q-card { display: none; }
        .q-card.q-active {
            display: block;
            animation: qFadeSlide .3s ease;
        }
        .q-card.q-active.q-dir-back {
            animation: qFadeSlideBack .3s ease;
        }
        @keyframes qFadeSlide {
            from { opacity: 0; transform: translateX(28px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes qFadeSlideBack {
            from { opacity: 0; transform: translateX(-28px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* Question text */
        .q-card-question {
            font-size: 1.05rem; font-weight: 700;
            color: #1a1816; line-height: 1.45;
            margin-bottom: .5rem;
        }
        .q-card-desc {
            font-size: .82rem; color: #8a8680;
            margin-bottom: 1rem; line-height: 1.5;
        }

        /* Options */
        .q-options { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
        .q-opt-btn {
            background: var(--bg-primary);
            border: none;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            border-radius: 10px; padding: 9px 14px;
            font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
            cursor: pointer; text-align: left; line-height: 1.4;
            transition: all .15s; display: flex; align-items: flex-start; gap: 8px;
        }
        .q-opt-btn:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
        .q-opt-btn.q-opt-selected {
            box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            color: var(--btn-save-from); font-weight: 600;
        }
        /* Tonalité — indicateur coloré à gauche */
        .q-opt-btn::before { content:''; display:inline-block; width:6px; height:6px; border-radius:50%; margin-right:6px; flex-shrink:0; margin-top:5px; }
        .q-ton-positif::before  { background:#22c55e; }
        .q-ton-vigilance::before { background:#f59e0b; }
        .q-ton-neutre::before   { background:#94a3b8; }
        /* description sous la question */
        .q-card-desc { font-size:.82rem; color:#8a8680; margin-top:.2rem; margin-bottom:.6rem; font-style:italic; }
        .q-opt-autre-btn {
            background: transparent;
            border: 1.5px dashed #c8c4be;
            border-radius: 10px; padding: 7px 14px;
            font-family: 'Sora', sans-serif; font-size: 11px; color: #a8a49e;
            cursor: pointer; text-align: left; transition: all .15s;
        }
        .q-opt-autre-btn:hover, .q-opt-autre-btn.q-opt-selected {
            border-color: var(--btn-save-from); color: var(--btn-save-from);
        }
        .q-tag {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px; border-radius: 50%;
            font-size: .6rem; font-weight: 800; flex-shrink: 0; margin-top: .1rem;
        }
        .q-pos { background: #dcfce7; color: #166534; }
        .q-vig { background: #fef9c3; color: #854d0e; }
        .q-sol { background: #ede9fe; color: #5b21b6; }

        /* Autre réponse */
        .q-autre-wrap { margin-bottom: .75rem; }
        .q-autre-input {
            width: 100%; border: none;
            border-radius: 10px; padding: 9px 13px;
            font-size: 12px; font-family: 'Sora', sans-serif;
            resize: vertical; min-height: 64px;
            background: var(--bg-primary);
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            transition: box-shadow .2s; color: #1a1816;
        }
        .q-autre-input:focus { outline: none; box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light), 0 0 0 2px rgba(249,115,22,0.2); }

        /* Étoiles question */
        .q-stars {
            display: flex; gap: .35rem; margin: .75rem 0 .5rem;
            align-items: center;
        }
        .q-stars::before {
            content: 'Évaluation :';
            font-size: .72rem; color: #8a8680; font-weight: 600;
            margin-right: .25rem;
        }
        .q-star {
            font-size: 1.5rem; background: none; border: none;
            cursor: pointer; color: #d1d5db; line-height: 1; padding: 0;
            transition: color .1s, transform .1s;
        }
        .q-star:hover, .q-star.q-star-active { color: #f59e0b; }
        .q-star:hover { transform: scale(1.2); }

        /* Admin comment */
        .q-admin-comment {
            background: rgba(124,58,237,0.06); box-shadow: inset 2px 2px 5px rgba(124,58,237,0.1);
            border-radius: 12px; padding: 12px; margin: 12px 0 8px;
        }
        .q-admin-label {
            font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; color: #7c3aed;
            text-transform: uppercase; letter-spacing: 0.14em;
            margin-bottom: 6px;
        }
        .q-admin-ta {
            width: 100%; border: none;
            border-radius: 8px; padding: 8px 12px;
            font-size: 12px; font-family: 'Sora', sans-serif;
            resize: vertical; min-height: 56px;
            background: var(--bg-primary);
            box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light);
            color: #1a1816; transition: box-shadow .2s;
        }
        .q-admin-ta:focus { outline: none; box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light), 0 0 0 2px rgba(124,58,237,0.2); }

        /* Actions vocales */
        .q-actions {
            display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;
        }
        .q-btn-voice {
            padding: 4px 12px; border-radius: 999px;
            border: none;
            background: var(--bg-primary);
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            font-family: 'Sora', sans-serif;
            color: #4a6038; font-size: 11px; cursor: pointer;
            transition: all .15s;
        }
        .q-btn-voice:hover { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); }
        .q-btn-voice.q-recording { color: #8a5040; animation: pulse 1s infinite; }
        .q-btn-voice-admin { color: #7c3aed; }
        .q-btn-voice-admin:hover { color: #7c3aed; }

        /* Navigation footer */
        .q-nav-footer {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 14px; padding-top: 12px;
            border-top: 1px solid rgba(196,192,186,0.4);
        }
        .q-nav-btn {
            padding: 0 16px; height: 32px; border-radius: 999px; font-size: 11px;
            font-family: 'Sora', sans-serif;
            font-weight: 600; cursor: pointer; transition: all .15s;
            border: none;
            background: var(--bg-primary); color: #6a6660;
            box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        }
        .q-nav-btn:hover:not(:disabled) { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); }
        .q-nav-btn:disabled { opacity: .4; cursor: default; }
        .q-nav-btn.q-nav-next {
            background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to)); color: #fff;
            box-shadow: 3px 3px 8px rgba(249,115,22,0.35);
        }
        .q-nav-btn.q-nav-next:hover { opacity: .88; }
        .q-nav-btn.q-nav-next:disabled { background: var(--bg-primary); color: #a8a49e; box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); opacity: 1; }

        /* Dots */
        .q-dots { display: flex; gap: 6px; align-items: center; }
        .q-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #c8c4be; border: none; cursor: pointer;
            padding: 0; transition: all .2s;
            box-shadow: inset 1px 1px 2px #b8b4ae, inset -1px -1px 2px var(--bg-primary);
        }
        .q-dot.q-dot-active { background: var(--btn-save-from); width: 18px; border-radius: 4px; box-shadow: 2px 2px 4px rgba(249,115,22,0.3); }
        .q-dot.q-dot-done   { background: #7a9060; box-shadow: 2px 2px 4px rgba(122,144,96,0.3); }
    </style>
EXTRACSS;

ob_start();
?>

<div class="tenir-main">

<!-- Top bar -->
<div class="top-bar">
    <div>
        <div class="top-bar-title">
            Entretien — <?= h($entretien['collab_prenom'] . ' ' . $entretien['collab_nom']) ?>
        </div>
        <div class="top-bar-sub">
            <?= h($entretien['collab_poste'] ?? 'Collaborateur') ?>
            <?php if (!empty($entretien['date_entretien'])): ?>
                &bull; <?= h(date('d/m/Y', strtotime($entretien['date_entretien']))) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($readOnly): ?>
    <!-- Bannière lecture seule -->
    <div style="display:flex;align-items:center;gap:.5rem;font-size:.75rem;padding:.35rem .75rem;border-radius:8px;border:1.5px solid #a5b4fc;background:#eef2ff;color:#3730a3;font-weight:700;">
        🔒 Consultation uniquement —
        <?= $entretien['statut'] === 'signe' ? 'Entretien signé' : 'Entretien finalisé' ?>
    </div>
    <?php else: ?>
    <!-- Indicateur auto-éval collaborateur -->
    <div style="display:flex;align-items:center;gap:.5rem;font-size:.75rem;padding:.35rem .65rem;border-radius:8px;border:1px solid <?= $autoEvalSubmitted ? '#86efac' : '#fde68a' ?>;background:<?= $autoEvalSubmitted ? '#f0fdf4' : '#fefce8' ?>;color:<?= $autoEvalSubmitted ? '#166534' : '#92400e' ?>;">
        <?php if ($autoEvalSubmitted): ?>
            ✅ Auto-éval collab soumise (<?= $autoEvalCount ?> critères)
        <?php else: ?>
            ⏳ Auto-éval collab en attente
            <a href="rh_entretien_auto_eval.php?id=<?= $entretienId ?>" target="_blank"
               style="color:#0369a1;text-decoration:none;font-weight:700;margin-left:.35rem;">Voir →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="top-bar-status">
        <div class="save-dot" id="saveDot"></div>
        <span id="saveLabel">—</span>
        <a href="rh_entretien_liste.php" style="margin-left:14px;font-family:'DM Mono',monospace;font-size:11px;color:#8a8680;text-decoration:none;letter-spacing:0.04em;">← Liste</a>
    </div>
</div>

<!-- Layout -->
<div class="layout">

    <!-- Colonne nav gauche -->
    <nav class="col-nav">
        <div class="col-nav-title">Rubriques</div>
        <?php foreach ($rubriques as $rNum => $r): ?>
            <button class="nav-rubrique-btn <?= $rNum === $rubriqueCourante ? 'active' : '' ?>"
                    id="navBtn<?= $rNum ?>"
                    onclick="goToRubrique(<?= $rNum ?>)">
                <span class="nav-rubrique-num"><?= $rNum ?></span>
                <?= h($r['nom']) ?>
                <?php if (!empty($r['manager_only'])): ?>
                    <span style="font-size:.6rem;color:#7c3aed;margin-left:auto;">RH</span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <!-- Colonne principale -->
    <main class="col-main" id="colMain">

        <?php foreach ($rubriques as $rNum => $r): ?>
            <div class="rubrique-panel <?= $rNum === $rubriqueCourante ? 'active' : '' ?>" id="panel<?= $rNum ?>">

                <div class="rubrique-heading">
                    <div class="rubrique-heading-num"><?= $rNum ?></div>
                    <div class="rubrique-heading-name"><?= h($r['nom']) ?></div>
                    <?php if (!empty($r['manager_only'])): ?>
                        <span class="rubrique-heading-badge">Manager uniquement</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($r['introduction'])): ?>
                <div class="rubrique-intro">
                    <span class="rubrique-intro-icon">💡</span><?= h($r['introduction']) ?>
                    <?php if (!empty($r['objectif'])): ?>
                    <span class="rubrique-intro-objectif">🎯 <?= h($r['objectif']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php
                // Réponses collab pour cette rubrique (match par nom de rubrique)
                $collabRubriqueAnswers = [];
                foreach ($reponsesCollab as $qId => $qRep) {
                    $rubNom = trim($qRep['rubrique'] ?? '');
                    if (!empty($rubNom) && mb_stripos($r['nom'], $rubNom) !== false) {
                        $collabRubriqueAnswers[] = $qRep;
                    }
                }

                // Phrases manager pour cette rubrique
                $rubNomSlug = mb_strtolower(preg_replace('/[^a-z0-9]/i','_', $r['nom']));
                $rubPhrases = $phrasesManager[$rubNomSlug] ?? $phrasesManager[mb_strtolower($r['nom'])] ?? [];
                // Chercher aussi par mots clés partiels
                if (empty($rubPhrases)) {
                    foreach ($phrasesManager as $cat => $phs) {
                        if (mb_stripos($r['nom'], $cat) !== false || mb_stripos($cat, $r['nom']) !== false) {
                            $rubPhrases = array_merge($rubPhrases, $phs);
                        }
                    }
                }
                if (empty($rubPhrases)) {
                    // Fallback : toutes les phrases si aucune correspondance
                    foreach ($phrasesManager as $phs) { $rubPhrases = array_merge($rubPhrases, $phs); break; }
                }
                ?>

                <?php if (!empty($collabRubriqueAnswers)): ?>
                <div class="collab-reponses-box" style="margin-bottom:1.25rem">
                    <div class="collab-reponses-title">Réponses préalables du collaborateur</div>
                    <?php foreach ($collabRubriqueAnswers as $cq): ?>
                        <div class="collab-rep-item">
                            <?php if ($cq['note']): ?>
                                <strong>Autoévaluation :</strong>
                                <span style="color:#f59e0b;font-weight:700"><?= str_repeat('★', (int)$cq['note']) ?><?= str_repeat('☆', 5-(int)$cq['note']) ?></span>
                                <?php if ($cq['texte']): ?>&nbsp;— <?= h($cq['texte']) ?><?php endif; ?>
                            <?php elseif ($cq['radio_choix']): ?>
                                <strong><?= h(ucfirst($cq['radio_choix'])) ?></strong>
                                <?php if ($cq['texte']): ?> — <?= h($cq['texte']) ?><?php endif; ?>
                            <?php else: ?>
                                <?= h($cq['texte'] ?? '') ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($rubPhrases)): ?>
                <div class="phrases-panel">
                    <div class="phrases-panel-title">💬 Phrases manager — aide à la rédaction</div>
                    <div class="phrases-grid">
                        <?php foreach (array_slice($rubPhrases, 0, 12) as $p): ?>
                            <button type="button" class="phrase-chip"
                                    id="pchip_<?= $p['id'] ?>"
                                    onclick="copyPhrase(this, <?= h(json_encode($p['texte'])) ?>)">
                                <?= h(mb_strimwidth($p['texte'], 0, 90, '…')) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Questions pour cette rubrique
                $questionsForRub = $questionsParRubrique[$rNum] ?? [];
                if (empty($questionsForRub)) {
                    // Fallback : chercher par rubrique id
                    $questionsForRub = $questionsParRubrique[$r['id']] ?? [];
                }
                ?>
                <?php if (!empty($questionsForRub)): ?>
                <div class="q-carousel" id="qCarousel<?= $rNum ?>" data-rubrique="<?= $rNum ?>">

                    <div class="q-carousel-header">
                        <div class="q-progress-bar">
                            <div class="q-progress-fill" id="qProgress<?= $rNum ?>"
                                 style="width:<?= count($questionsForRub) > 0 ? round(100/count($questionsForRub)) : 100 ?>%"></div>
                        </div>
                        <span class="q-counter" id="qCounter<?= $rNum ?>">Question 1 / <?= count($questionsForRub) ?></span>
                    </div>

                    <div class="q-stage" id="qStage<?= $rNum ?>">
                    <?php foreach ($questionsForRub as $qi => $q):
                        $savedRep = $reponsesQuestions[(int)$q['id']] ?? null;
                        $opts     = $questionOptions[(int)$q['id']] ?? [];
                        // Pré-sélectionner une variante de texte par option (aléatoire, stable par rendu)
                        $optsTextes = [];
                        foreach ($opts as $optItem) {
                            $vars = $optionVariantes[(int)$optItem['id']] ?? [];
                            $optsTextes[(int)$optItem['id']] = !empty($vars)
                                ? $vars[array_rand($vars)]
                                : ($optItem['label'] ?? $optItem['texte'] ?? '');
                        }
                    ?>
                    <div class="q-card <?= $qi === 0 ? 'q-active' : '' ?>"
                         id="qCard<?= $rNum ?>_<?= $qi ?>"
                         data-q-index="<?= $qi ?>"
                         data-q-id="<?= (int)$q['id'] ?>">

                        <div class="q-card-question"><?= h($q['question_text'] ?: $q['label']) ?></div>
                        <?php if (!empty($q['description'])): ?>
                        <div class="q-card-desc"><?= h($q['description']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($opts)): ?>
                        <div class="q-options">
                            <?php foreach ($opts as $opt): ?>
                            <?php $ton = $opt['tonalite'] ?? 'neutre'; ?>
                            <button type="button"
                                    class="q-opt-btn q-ton-<?= h($ton) ?> <?= ($savedRep && (int)($savedRep['option_id'] ?? 0) === (int)$opt['id']) ? 'q-opt-selected' : '' ?>"
                                    data-opt-id="<?= (int)$opt['id'] ?>"
                                    data-tag="<?= h($opt['tag_analyse'] ?? '') ?>"
                                    data-tonalite="<?= h($ton) ?>"
                                    onclick="selectQOption(<?= $rNum ?>, <?= $qi ?>, <?= (int)$q['id'] ?>, <?= (int)$opt['id'] ?>, this)">
                                <?= h($optsTextes[(int)$opt['id']]) ?>
                            </button>
                            <?php endforeach; ?>
                            <button type="button"
                                    class="q-opt-autre-btn <?= ($savedRep && !empty($savedRep['texte_libre'])) ? 'q-opt-selected' : '' ?>"
                                    onclick="toggleQAutre(<?= $rNum ?>, <?= $qi ?>)">
                                ✏️ Autre réponse…
                            </button>
                        </div>
                        <div class="q-autre-wrap" id="qAutre<?= $rNum ?>_<?= $qi ?>"
                             style="<?= ($savedRep && !empty($savedRep['texte_libre'])) ? '' : 'display:none' ?>">
                            <textarea class="q-autre-input"
                                      id="qAutreTa<?= $rNum ?>_<?= $qi ?>"
                                      placeholder="Précisez votre réponse…" rows="2"
                                      oninput="saveQDebounced(<?= $rNum ?>, <?= $qi ?>, <?= (int)$q['id'] ?>)"><?= h($savedRep['texte_libre'] ?? '') ?></textarea>
                        </div>
                        <?php endif; ?>

                        <div class="q-stars" id="qStars<?= $rNum ?>_<?= $qi ?>">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button"
                                    class="q-star <?= ($savedRep && (int)($savedRep['note'] ?? 0) >= $s) ? 'q-star-active' : '' ?>"
                                    data-val="<?= $s ?>"
                                    onclick="setQStar(<?= $rNum ?>, <?= $qi ?>, <?= (int)$q['id'] ?>, <?= $s ?>)">★</button>
                            <?php endfor; ?>
                        </div>

                        <?php if ($roleId === 1): ?>
                        <div class="q-admin-comment">
                            <div class="q-admin-label">🔒 Note admin <span style="font-weight:400;opacity:.7">(non visible au manager)</span></div>
                            <textarea class="q-admin-ta"
                                      id="qAdminTa<?= $rNum ?>_<?= $qi ?>"
                                      placeholder="Observations internes…" rows="2"
                                      oninput="saveQDebounced(<?= $rNum ?>, <?= $qi ?>, <?= (int)$q['id'] ?>)"><?= h($savedRep['commentaire_admin'] ?? '') ?></textarea>
                        </div>
                        <?php endif; ?>

                        <div class="q-actions">
                            <button type="button"
                                    class="q-btn-voice"
                                    id="qVoice<?= $rNum ?>_<?= $qi ?>"
                                    onclick="startQVoice(<?= $rNum ?>, <?= $qi ?>, <?= (int)$q['id'] ?>, 'user')">🎤 Réponse</button>
                            <?php if ($roleId === 1): ?>
                            <button type="button"
                                    class="q-btn-voice q-btn-voice-admin"
                                    id="qVoiceAdmin<?= $rNum ?>_<?= $qi ?>"
                                    onclick="startQVoice(<?= $rNum ?>, <?= $qi ?>, <?= (int)$q['id'] ?>, 'admin')">🎤 Admin</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div><!-- .q-stage -->

                    <div class="q-nav-footer">
                        <button type="button" class="q-nav-btn q-nav-prev"
                                id="qPrev<?= $rNum ?>"
                                onclick="qNavigate(<?= $rNum ?>, -1)" disabled>← Précédente</button>
                        <div class="q-dots" id="qDots<?= $rNum ?>">
                            <?php for ($qi = 0; $qi < count($questionsForRub); $qi++): ?>
                            <button type="button"
                                    class="q-dot <?= $qi === 0 ? 'q-dot-active' : '' ?> <?= ($reponsesQuestions[($questionsForRub[$qi]['id'] ?? 0)] ?? null) ? 'q-dot-done' : '' ?>"
                                    onclick="qGoTo(<?= $rNum ?>, <?= $qi ?>)"></button>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="q-nav-btn q-nav-next"
                                id="qNext<?= $rNum ?>"
                                onclick="qNavigate(<?= $rNum ?>, 1)"
                                <?= count($questionsForRub) <= 1 ? 'disabled' : '' ?>>Suivante →</button>
                    </div>
                </div><!-- .q-carousel -->
                <?php endif; ?>

                <!-- Conteneur des critères — tous visibles -->
                <div class="criteres-container" id="criteresContainer<?= $rNum ?>">
                <?php foreach ($r['criteres'] as $c):
                    $repMgr       = $reponsesManager[$rNum][$c['id']] ?? [];
                    $rtsForCritere = $reponsesTypes[$rNum][$c['id']] ?? [];
                    $typeChamp    = $c['type_champ'] ?? 'etoiles_texte';
                    $visCollab    = !empty($repMgr['visible_collaborateur'])
                                        ? (bool)$repMgr['visible_collaborateur']
                                        : (bool)($c['visible_collaborateur'] ?? true);
                    $blocData     = [];
                    if (!empty($repMgr['texte_final']) && in_array($typeChamp, ['bloc_formation','bloc_motivation','bloc_charge','bloc_evolution'])) {
                        $decoded = json_decode($repMgr['texte_final'], true);
                        if (is_array($decoded)) $blocData = $decoded;
                    }
                    // Réponse collab spécifique à ce critère (par critere_id ou rubrique+ordre)
                    $collabForCritere = null;
                    foreach ($reponsesCollab as $qId => $qRep) {
                        if (isset($qRep['critere_id']) && (int)$qRep['critere_id'] === (int)$c['id']) {
                            $collabForCritere = $qRep; break;
                        }
                    }
                ?>
                <div class="critere-block"
                     id="critere_<?= $rNum ?>_<?= $c['id'] ?>">
                    <div class="critere-label">
                        <?= h($c['label']) ?>
                        <?php if (!empty($c['axe_radar'])): ?>
                            <span style="font-size:.62rem;background:#f1f5f9;color:#475569;padding:.1rem .4rem;border-radius:4px;margin-left:.4rem;font-weight:600;"><?= h($c['axe_radar']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['poids_score']) && (float)$c['poids_score'] >= 1.2): ?>
                            <span style="font-size:.6rem;background:#fef3c7;color:#92400e;padding:.1rem .35rem;border-radius:4px;margin-left:.25rem;font-weight:700;">×<?= $c['poids_score'] ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($collabForCritere): ?>
                    <div class="collab-inline">
                        <span class="collab-inline-icon">👤</span>
                        <span class="collab-inline-text">
                            <strong style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#0369a1">Réponse collaborateur :</strong>
                            <?php if ($collabForCritere['note']): ?>
                                <span class="collab-inline-note"><?= str_repeat('★',(int)$collabForCritere['note']) ?><?= str_repeat('☆',5-(int)$collabForCritere['note']) ?></span>
                            <?php endif; ?>
                            <?php if ($collabForCritere['radio_choix']): ?>
                                <em><?= h(ucfirst($collabForCritere['radio_choix'])) ?></em>
                            <?php endif; ?>
                            <?php if ($collabForCritere['texte']): ?>
                                — <?= h($collabForCritere['texte']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if ($typeChamp !== 'bloc_formation' && $typeChamp !== 'bloc_charge' && $typeChamp !== 'bloc_evolution'): ?>
                    <!-- Comparaison auto-éval collab vs manager -->
                    <?php
                    $noteCollab = isset($repMgr['note_collaborateur']) && $repMgr['note_collaborateur'] !== null
                                    ? (int)$repMgr['note_collaborateur'] : null;
                    $noteMgr    = ($repMgr['note'] ?? null) !== null ? (int)$repMgr['note'] : null;
                    $ecart      = ($noteCollab !== null && $noteMgr !== null) ? abs($noteCollab - $noteMgr) : null;
                    ?>
                    <?php if ($noteCollab !== null): ?>
                    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;padding:.4rem .6rem;background:#f0f9ff;border-radius:8px;border:1px solid #bae6fd;flex-wrap:wrap;">
                        <div style="font-size:.7rem;font-weight:700;color:#0369a1;min-width:60px;">👤 Collab</div>
                        <div style="color:#f59e0b;letter-spacing:.05em;font-size:1rem;">
                            <?= str_repeat('★', $noteCollab) . str_repeat('☆', 5 - $noteCollab) ?>
                            <span style="font-size:.7rem;color:#64748b;margin-left:.25rem;"><?= $noteCollab ?>/5</span>
                        </div>
                        <?php if ($noteMgr !== null && $ecart >= 2): ?>
                        <div style="font-size:.7rem;background:#fef3c7;color:#92400e;padding:.15rem .4rem;border-radius:4px;font-weight:700;">
                            ⚠️ Écart <?= $ecart ?> pts — à discuter
                        </div>
                        <?php endif; ?>
                        <div style="font-size:.7rem;font-weight:700;color:#0369a1;min-width:60px;margin-left:auto;">👔 Manager</div>
                        <div class="note-stars" id="stars_<?= $rNum ?>_<?= $c['id'] ?>" style="margin-bottom:0;">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <button type="button" class="star-btn <?= ($noteMgr ?? 0) >= $s ? 'active' : '' ?>"
                                        data-val="<?= $s ?>"
                                        onclick="setStar(<?= $rNum ?>, <?= $c['id'] ?>, <?= $s ?>)">★</button>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Pas encore d'auto-éval collab — affichage normal -->
                    <div class="note-stars" id="stars_<?= $rNum ?>_<?= $c['id'] ?>">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button" class="star-btn <?= ($noteMgr ?? 0) >= $s ? 'active' : '' ?>"
                                    data-val="<?= $s ?>"
                                    onclick="setStar(<?= $rNum ?>, <?= $c['id'] ?>, <?= $s ?>)">★</button>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" id="noteVal_<?= $rNum ?>_<?= $c['id'] ?>"
                           value="<?= h($repMgr['note'] ?? '') ?>">
                    <?php else: ?>
                    <input type="hidden" id="noteVal_<?= $rNum ?>_<?= $c['id'] ?>" value="">
                    <?php endif; ?>

                    <?php if (in_array($typeChamp, ['etoiles_texte','etoiles','texte','bloc_motivation'])): ?>
                    <!-- Réponses types — phrases proposées -->
                    <?php if (!empty($rtsForCritere)): ?>
                    <div class="rt-section-title">Phrases proposées <span style="font-weight:400;color:#8a8680">(cliquez pour insérer)</span></div>
                    <div class="rt-grid">
                        <?php
                        // Grouper par tonalité
                        $rtsByTon = [];
                        foreach ($rtsForCritere as $rt) {
                            $rtsByTon[$rt['tonalite'] ?? 'neutre'][] = $rt;
                        }
                        foreach ($rtsByTon as $ton => $rts): ?>
                        <div>
                            <?php foreach ($rts as $rt): ?>
                            <button type="button" class="rt-btn <?= ($repMgr['reponse_type_id'] ?? 0) == $rt['id'] ? 'selected' : '' ?>"
                                    data-note-cible="<?= h($rt['note_cible'] ?? '') ?>"
                                    data-tonalite="<?= h($ton) ?>"
                                    onclick="insererReponse(<?= $rNum ?>, <?= $c['id'] ?>, <?= $rt['id'] ?>, this)">
                                <span class="rt-tonalite ton-<?= h($ton) ?>" style="flex-shrink:0"><?= h($ton) ?></span>
                                <span><?= h($rt['texte']) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($typeChamp !== 'etoiles'): ?>
                    <!-- ======= BLOC FORMATION ======= -->
                    <?php if ($typeChamp === 'bloc_formation'): ?>
                    <div class="bloc-special" id="blocFormation_<?= $rNum ?>_<?= $c['id'] ?>">
                        <div class="bloc-special-title">📚 Analyse du besoin en formation</div>
                        <div class="bloc-special-grid">
                            <div class="bloc-field">
                                <label>Point faible identifié <span class="req">*</span></label>
                                <textarea id="bf_point_<?= $c['id'] ?>" rows="2"
                                    onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>)"
                                    placeholder="Ex : gestion des outils CRM…"><?= h($blocData['point_faible'] ?? '') ?></textarea>
                                <div class="bloc-validation-msg" id="bfv_point_<?= $c['id'] ?>">Champ obligatoire</div>
                            </div>
                            <div class="bloc-field">
                                <label>Situation concrète observée <span class="req">*</span></label>
                                <textarea id="bf_situation_<?= $c['id'] ?>" rows="2"
                                    onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>)"
                                    placeholder="Ex : erreurs répétées sur les rapports mensuels…"><?= h($blocData['situation'] ?? '') ?></textarea>
                                <div class="bloc-validation-msg" id="bfv_situation_<?= $c['id'] ?>">Champ obligatoire</div>
                            </div>
                            <div class="bloc-field">
                                <label>Fréquence observée</label>
                                <select id="bf_frequence_<?= $c['id'] ?>" onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="ponctuel" <?= ($blocData['frequence']??'')==='ponctuel'?'selected':'' ?>>Ponctuel</option>
                                    <option value="mensuel" <?= ($blocData['frequence']??'')==='mensuel'?'selected':'' ?>>Mensuel</option>
                                    <option value="hebdomadaire" <?= ($blocData['frequence']??'')==='hebdomadaire'?'selected':'' ?>>Hebdomadaire</option>
                                    <option value="quotidien" <?= ($blocData['frequence']??'')==='quotidien'?'selected':'' ?>>Quotidien / Systématique</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Impact réel <span class="req">*</span></label>
                                <select id="bf_impact_<?= $c['id'] ?>" onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="faible" <?= ($blocData['impact']??'')==='faible'?'selected':'' ?>>Faible</option>
                                    <option value="modere" <?= ($blocData['impact']??'')==='modere'?'selected':'' ?>>Modéré</option>
                                    <option value="important" <?= ($blocData['impact']??'')==='important'?'selected':'' ?>>Important sur performance</option>
                                    <option value="critique" <?= ($blocData['impact']??'')==='critique'?'selected':'' ?>>Critique / Bloquant</option>
                                </select>
                                <div class="bloc-validation-msg" id="bfv_impact_<?= $c['id'] ?>">Champ obligatoire</div>
                            </div>
                            <div class="bloc-field">
                                <label>Origine du besoin</label>
                                <select id="bf_origine_<?= $c['id'] ?>" onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="poste" <?= ($blocData['origine']??'')==='poste'?'selected':'' ?>>Exigence du poste</option>
                                    <option value="evolution" <?= ($blocData['origine']??'')==='evolution'?'selected':'' ?>>Évolution souhaitée</option>
                                    <option value="lacune" <?= ($blocData['origine']??'')==='lacune'?'selected':'' ?>>Lacune identifiée</option>
                                    <option value="reglementation" <?= ($blocData['origine']??'')==='reglementation'?'selected':'' ?>>Obligation réglementaire</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Solution proposée</label>
                                <select id="bf_solution_<?= $c['id'] ?>" onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="interne" <?= ($blocData['solution']??'')==='interne'?'selected':'' ?>>Accompagnement interne</option>
                                    <option value="tutorat" <?= ($blocData['solution']??'')==='tutorat'?'selected':'' ?>>Tutorat / Mentoring</option>
                                    <option value="groupe" <?= ($blocData['solution']??'')==='groupe'?'selected':'' ?>>Formation groupe</option>
                                    <option value="externe" <?= ($blocData['solution']??'')==='externe'?'selected':'' ?>>Formation externe</option>
                                    <option value="e_learning" <?= ($blocData['solution']??'')==='e_learning'?'selected':'' ?>>E-learning / MOOC</option>
                                </select>
                            </div>
                        </div>
                        <div class="bloc-special-grid full" style="margin-top:.6rem;">
                            <div class="bloc-field">
                                <label>Priorité</label>
                                <select id="bf_priorite_<?= $c['id'] ?>" onchange="saveBlocFormation(<?= $rNum ?>,<?= $c['id'] ?>);classifierFormation(<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="souhait" <?= ($blocData['priorite']??'')==='souhait'?'selected':'' ?>>Simple souhait</option>
                                    <option value="utile" <?= ($blocData['priorite']??'')==='utile'?'selected':'' ?>>Besoin utile</option>
                                    <option value="justifie" <?= ($blocData['priorite']??'')==='justifie'?'selected':'' ?>>Besoin justifié</option>
                                    <option value="prioritaire" <?= ($blocData['priorite']??'')==='prioritaire'?'selected':'' ?>>Besoin prioritaire</option>
                                    <option value="refuse" <?= ($blocData['priorite']??'')==='refuse'?'selected':'' ?>>Besoin refusé</option>
                                </select>
                            </div>
                        </div>
                        <div class="bloc-classification" id="bf_classif_<?= $c['id'] ?>" style="<?= empty($blocData['priorite'])?'display:none':'' ?>">
                            Classification : <span><?= h(ucfirst($blocData['priorite'] ?? '')) ?></span>
                        </div>
                    </div>
                    <!-- Textarea synthèse formation -->
                    <textarea class="commentaire-input" style="margin-top:.5rem;"
                              id="ta_<?= $rNum ?>_<?= $c['id'] ?>"
                              data-entretien="<?= $entretienId ?>"
                              data-rubrique="<?= $rNum ?>"
                              data-critere="<?= $c['id'] ?>"
                              placeholder="Synthèse et commentaire manager sur la demande de formation…"
                              rows="2"><?= h(!is_array($repMgr['texte_final'] ?? null) ? ($repMgr['texte_final'] ?? '') : '') ?></textarea>

                    <!-- ======= BLOC MOTIVATION ======= -->
                    <?php elseif ($typeChamp === 'bloc_motivation'): ?>
                    <div class="bloc-special" id="blocMotivation_<?= $rNum ?>_<?= $c['id'] ?>">
                        <div class="bloc-special-title">💡 Analyse motivation — Déclarée vs Observée</div>
                        <div class="bloc-special-grid">
                            <div class="bloc-field">
                                <label>Motivation déclarée (par le collaborateur)</label>
                                <select id="bm_declaree_<?= $c['id'] ?>" onchange="analyserMotivation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="5" <?= ($blocData['declaree']??'')==='5'?'selected':'' ?>>Très haute (5)</option>
                                    <option value="4" <?= ($blocData['declaree']??'')==='4'?'selected':'' ?>>Haute (4)</option>
                                    <option value="3" <?= ($blocData['declaree']??'')==='3'?'selected':'' ?>>Moyenne (3)</option>
                                    <option value="2" <?= ($blocData['declaree']??'')==='2'?'selected':'' ?>>Basse (2)</option>
                                    <option value="1" <?= ($blocData['declaree']??'')==='1'?'selected':'' ?>>Très basse (1)</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Motivation observée (par le manager)</label>
                                <select id="bm_observee_<?= $c['id'] ?>" onchange="analyserMotivation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="5" <?= ($blocData['observee']??'')==='5'?'selected':'' ?>>Très haute (5)</option>
                                    <option value="4" <?= ($blocData['observee']??'')==='4'?'selected':'' ?>>Haute (4)</option>
                                    <option value="3" <?= ($blocData['observee']??'')==='3'?'selected':'' ?>>Moyenne (3)</option>
                                    <option value="2" <?= ($blocData['observee']??'')==='2'?'selected':'' ?>>Basse (2)</option>
                                    <option value="1" <?= ($blocData['observee']??'')==='1'?'selected':'' ?>>Très basse (1)</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Freins identifiés</label>
                                <select id="bm_freins_<?= $c['id'] ?>" onchange="saveBlocMotivation(<?= $rNum ?>,<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="aucun" <?= ($blocData['freins']??'')==='aucun'?'selected':'' ?>>Aucun frein</option>
                                    <option value="charge" <?= ($blocData['freins']??'')==='charge'?'selected':'' ?>>Surcharge de travail</option>
                                    <option value="reconnaissance" <?= ($blocData['freins']??'')==='reconnaissance'?'selected':'' ?>>Manque de reconnaissance</option>
                                    <option value="evolution_bloquee" <?= ($blocData['freins']??'')==='evolution_bloquee'?'selected':'' ?>>Évolution bloquée</option>
                                    <option value="relationnel" <?= ($blocData['freins']??'')==='relationnel'?'selected':'' ?>>Problème relationnel</option>
                                    <option value="sens" <?= ($blocData['freins']??'')==='sens'?'selected':'' ?>>Perte de sens</option>
                                    <option value="autre" <?= ($blocData['freins']??'')==='autre'?'selected':'' ?>>Autre (préciser)</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Cohérence (analyse automatique)</label>
                                <div class="bloc-classification" id="bm_analyse_<?= $c['id'] ?>" style="padding:.5rem .85rem;margin-top:0;">
                                    <span id="bm_analyse_txt_<?= $c['id'] ?>">Remplir les champs ci-contre</span>
                                </div>
                            </div>
                        </div>
                        <div class="bloc-incoherence" id="bm_alerte_<?= $c['id'] ?>">
                            ⚠️ Incohérence détectée entre motivation déclarée et observée — vérification recommandée.
                        </div>
                    </div>
                    <textarea class="commentaire-input" style="margin-top:.5rem;"
                              id="ta_<?= $rNum ?>_<?= $c['id'] ?>"
                              data-entretien="<?= $entretienId ?>"
                              data-rubrique="<?= $rNum ?>"
                              data-critere="<?= $c['id'] ?>"
                              placeholder="Analyse et commentaire manager sur la motivation…"
                              rows="2"><?= h(!is_array($repMgr['texte_final'] ?? null) ? ($repMgr['texte_final'] ?? '') : '') ?></textarea>

                    <!-- ======= BLOC ÉVOLUTION ======= -->
                    <?php elseif ($typeChamp === 'bloc_evolution'): ?>
                    <div class="bloc-special">
                        <div class="bloc-special-title">🚀 Analyse du projet d'évolution</div>
                        <div class="bloc-special-grid">
                            <div class="bloc-field">
                                <label>Clarté du projet</label>
                                <select id="be_clarte_<?= $c['id'] ?>" onchange="classifierEvolution(<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="tres_clair" <?= ($blocData['clarte']??'')==='tres_clair'?'selected':'' ?>>Très clair et précis</option>
                                    <option value="clair" <?= ($blocData['clarte']??'')==='clair'?'selected':'' ?>>Clair dans les grandes lignes</option>
                                    <option value="flou" <?= ($blocData['clarte']??'')==='flou'?'selected':'' ?>>Flou / En réflexion</option>
                                    <option value="absent" <?= ($blocData['clarte']??'')==='absent'?'selected':'' ?>>Pas de projet défini</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Cohérence avec le profil actuel</label>
                                <select id="be_coherence_<?= $c['id'] ?>" onchange="classifierEvolution(<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="tres_coherent" <?= ($blocData['coherence']??'')==='tres_coherent'?'selected':'' ?>>Très cohérent</option>
                                    <option value="coherent" <?= ($blocData['coherence']??'')==='coherent'?'selected':'' ?>>Globalement cohérent</option>
                                    <option value="partiel" <?= ($blocData['coherence']??'')==='partiel'?'selected':'' ?>>Partiellement cohérent</option>
                                    <option value="incoherent" <?= ($blocData['coherence']??'')==='incoherent'?'selected':'' ?>>Peu cohérent avec le profil</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Faisabilité dans l'entreprise</label>
                                <select id="be_faisabilite_<?= $c['id'] ?>" onchange="classifierEvolution(<?= $c['id'] ?>)">
                                    <option value="">— Sélectionner —</option>
                                    <option value="oui_court" <?= ($blocData['faisabilite']??'')==='oui_court'?'selected':'' ?>>Oui, à court terme (&lt;12 mois)</option>
                                    <option value="oui_moyen" <?= ($blocData['faisabilite']??'')==='oui_moyen'?'selected':'' ?>>Oui, à moyen terme (12-24 mois)</option>
                                    <option value="conditionnel" <?= ($blocData['faisabilite']??'')==='conditionnel'?'selected':'' ?>>Conditionnel (dépend des performances)</option>
                                    <option value="non" <?= ($blocData['faisabilite']??'')==='non'?'selected':'' ?>>Non réaliste actuellement</option>
                                </select>
                            </div>
                            <div class="bloc-field">
                                <label>Classification automatique</label>
                                <div class="bloc-classification" id="be_classif_<?= $c['id'] ?>" style="padding:.5rem .85rem;margin-top:0;">
                                    <span id="be_classif_txt_<?= $c['id'] ?>">Remplir les critères</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <textarea class="commentaire-input" style="margin-top:.5rem;"
                              id="ta_<?= $rNum ?>_<?= $c['id'] ?>"
                              data-entretien="<?= $entretienId ?>"
                              data-rubrique="<?= $rNum ?>"
                              data-critere="<?= $c['id'] ?>"
                              placeholder="Analyse et commentaire manager sur le projet d'évolution…"
                              rows="2"><?= h(!is_array($repMgr['texte_final'] ?? null) ? ($repMgr['texte_final'] ?? '') : '') ?></textarea>

                    <?php else: ?>
                    <!-- Textarea standard (etoiles_texte, texte) -->
                    <div style="position:relative;">
                        <textarea class="commentaire-input"
                                  id="ta_<?= $rNum ?>_<?= $c['id'] ?>"
                                  data-entretien="<?= $entretienId ?>"
                                  data-rubrique="<?= $rNum ?>"
                                  data-critere="<?= $c['id'] ?>"
                                  placeholder="Commentaire…"
                                  rows="3"><?= h($repMgr['texte_final'] ?? '') ?></textarea>
                        <div class="ia-popup" id="iaPopup_<?= $rNum ?>_<?= $c['id'] ?>">
                            <div class="ia-popup-title">✨ Suggestion IA</div>
                            <div class="ia-popup-text" id="iaText_<?= $rNum ?>_<?= $c['id'] ?>">Chargement…</div>
                            <div class="ia-popup-actions">
                                <button class="btn-ia-insert" onclick="insertIaSuggestion(<?= $rNum ?>, <?= $c['id'] ?>)">Insérer</button>
                                <button class="btn-ia-close" onclick="closeIaPopup(<?= $rNum ?>, <?= $c['id'] ?>)">Fermer</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; /* != etoiles */ ?>

                    <!-- Actions -->
                    <div class="critere-actions">
                        <?php if (empty($r['manager_only'])): ?>
                        <label style="display:flex;align-items:center;gap:.3rem;font-size:.75rem;color:#8a8680;cursor:pointer;">
                            <input type="checkbox" id="vis_<?= $rNum ?>_<?= $c['id'] ?>"
                                   <?= $visCollab ? 'checked' : '' ?>
                                   onchange="triggerSave(<?= $rNum ?>, <?= $c['id'] ?>)">
                            Visible collab
                        </label>
                        <?php else: ?>
                        <span style="font-size:.72rem;color:#7c3aed;font-weight:600;">🔒 Manager uniquement</span>
                        <?php endif; ?>
                        <button type="button" class="btn-action btn-ia"
                                onclick="suggestIA(<?= $rNum ?>, <?= $c['id'] ?>)">✨ IA</button>
                        <button type="button" class="btn-action btn-vocal"
                                id="vocalBtn_<?= $rNum ?>_<?= $c['id'] ?>"
                                onclick="startVoice(<?= $rNum ?>, <?= $c['id'] ?>)">🎤</button>
                    </div>
                </div>
                <?php endforeach; ?>
                </div><!-- .criteres-container -->

                <?php
                $synExist  = $synthesesDispo[$rNum] ?? null;
                $synTon    = $synExist['tonalite'] ?? 'neutre';
                $synMgr    = $synExist['synthese_manager'] ?? '';
                $synCollab = $synExist['synthese_collab'] ?? '';
                ?>
                <div class="rubrique-synthese-wrap" id="syntheseWrap<?= $rNum ?>">
                    <div class="synthese-header">
                        <span class="synthese-label" style="color:#8a8680">🔍 Analyse de la rubrique</span>
                        <button type="button" class="synthese-refresh-btn"
                                id="synRefreshBtn<?= $rNum ?>"
                                onclick="refreshSynthese(<?= $rNum ?>)"
                                title="Générer / actualiser l'analyse">↻</button>
                    </div>
                    <div class="rubrique-synthese ton-<?= h($synTon) ?> <?= empty($synMgr) ? 'ton-loading' : '' ?>"
                         id="syntheseBox<?= $rNum ?>">
                        <?php if (!empty($synMgr)): ?>
                        <div class="synthese-manager-text" id="synMgr<?= $rNum ?>"><?= h($synMgr) ?></div>
                        <?php if (!empty($synCollab)): ?>
                        <div class="synthese-collab-text" id="synCollab<?= $rNum ?>"><?= h($synCollab) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span id="synMgr<?= $rNum ?>" style="font-style:italic;opacity:.6">Enregistrez des réponses pour générer l'analyse de cette rubrique…</span>
                        <span id="synCollab<?= $rNum ?>" style="display:none"></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Navigation rubrique -->
                <div class="nav-bottom" style="margin-top:2rem;padding-top:1.25rem;border-top:1px solid rgba(196,192,186,0.4);display:flex;align-items:center;justify-content:space-between;">
                    <?php if ($rNum > 1): ?>
                        <button type="button" class="btn-nav btn-prev" onclick="goToRubrique(<?= $rNum-1 ?>)">← <?= h($rubriques[$rNum-1]['nom'] ?? 'Précédent') ?></button>
                    <?php else: ?>
                        <a href="rh_entretien_liste.php" class="btn-nav btn-prev">← Retour à la liste</a>
                    <?php endif; ?>
                    <span style="font-size:.75rem;color:#8a8680;font-weight:600"><?= $rNum ?> / <?= $nbRubriques ?></span>
                    <?php if ($rNum < $nbRubriques): ?>
                        <button type="button" class="btn-nav btn-next" onclick="goToRubrique(<?= $rNum+1 ?>)"><?= h($rubriques[$rNum+1]['nom'] ?? 'Suivant') ?> →</button>
                    <?php else: ?>
                        <button type="button" class="btn-nav btn-finish" onclick="finaliserEntretien()">Finaliser l'entretien ✓</button>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>

    </main>

    <!-- Colonne droite -->
    <aside class="col-right" id="colRight">
        <div class="col-right-toggle">
            <button class="btn-toggle-right" onclick="toggleRight()" id="btnToggleRight">◀</button>
        </div>
        <div class="col-right-content">

            <!-- Radar -->
            <div class="radar-wrap">
                <div class="right-section-title">Scoring radar</div>
                <canvas id="radarChart" height="220"></canvas>
            </div>

            <!-- Alertes -->
            <div style="margin-bottom:1.25rem;">
                <div class="right-section-title">Alertes RH</div>
                <div id="alertesContainer">
                    <?php if (empty($alertes)): ?>
                        <div class="no-alerts">Aucune alerte détectée</div>
                    <?php else: ?>
                        <?php foreach ($alertes as $al): ?>
                            <div class="alerte-item <?= h($al['niveau'] === 'warning' ? 'warning' : '') ?>">
                                <?= h($al['message'] ?? $al['type_alerte']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Phrases manager -->
            <?php if (!empty($phrasesManager)): ?>
            <div>
                <div class="right-section-title">Phrases manager</div>
                <?php foreach ($phrasesManager as $cat => $phrases): ?>
                    <div class="phrases-categorie">
                        <div class="phrases-cat-title"><?= h($cat) ?></div>
                        <?php foreach ($phrases as $p): ?>
                            <button type="button" class="phrase-copy"
                                    id="phrase_<?= $p['id'] ?>"
                                    onclick="copyPhrase(this, <?= h(json_encode($p['texte'])) ?>)">
                                <?= h(mb_strimwidth($p['texte'], 0, 80, '…')) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </aside>
</div><!-- .layout -->
</div><!-- .tenir-main -->

<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const CSRF_HEADERS = CSRF_TOKEN ? {'X-CSRF-Token': CSRF_TOKEN} : {};
// --- Données PHP → JS ---
const READ_ONLY         = <?= $jsReadOnly ?>;
const ENTRETIEN_ID      = <?= $jsEntretienId ?>;
const RUBRIQUE_COURANTE = <?= $jsRubriqueCourante ?>;
const NB_RUBRIQUES      = <?= $jsNbRubriques ?>;
const REPONSES_MANAGER  = <?= $jsReponsesManager ?>;
const REPONSES_COLLAB   = <?= $jsReponsesCollab ?>;
const REPONSES_TYPES    = <?= $jsReponsesTypes ?>;
let   scores            = <?= $jsScores ?>;
let   alertes           = <?= $jsAlertes ?>;
const RUBRIQUES_DEF     = <?= $jsRubriques ?>;

let currentRubrique   = RUBRIQUE_COURANTE;
let rightCollapsed    = false;
let activeVoice       = null;
let activeRecognition = null;

// ===== QUESTION CAROUSEL STATE =====
const currentQIndex   = {};   // rubriqueId → current question index
const qSaveTimers     = {};   // debounce timers per question
let   activeQVoice    = null; // 'rub_qi_target' string
let   activeQRecog    = null;

// Initialiser data-note sur chaque conteneur d'étoiles question (depuis les classes active)
document.querySelectorAll('.q-stars').forEach(container => {
    const active = container.querySelectorAll('.q-star.q-star-active');
    if (active.length) container.dataset.note = active.length;
});

// Mapping critere_id → axe (data-driven depuis PHP)
const axeMap = <?= $jsAxeMap ?>;

// Labels lisibles pour les axes radar
const axeLabels = {
    performance:    'Performance',
    motivation:     'Motivation',
    relationnel:    'Relationnel',
    adaptabilite:   'Adaptabilité',
    autonomie:      'Autonomie',
    potentiel:      'Potentiel',
    organisation:   'Organisation',
    maitrise_poste: 'Maîtrise poste',
    digital:        'Digital',
    engagement:     'Engagement',
    fiabilite:      'Fiabilité',
    comportement:   'Comportement',
    competences:    'Compétences',
};

// Construire les axes uniques présents dans cette configuration
const radarAxes = [...new Set(Object.values(axeMap))];
const radarLabels = radarAxes.map(a => axeLabels[a] || a);

// ===== RADAR CHART — axes dynamiques =====
const radarCtx = document.getElementById('radarChart').getContext('2d');
const radarChart = new Chart(radarCtx, {
    type: 'radar',
    data: {
        labels: radarLabels,
        datasets: [{
            label: 'Score',
            data: radarAxes.map(a => (scores[a] || 0) * 5),
            backgroundColor: 'rgba(249,115,22,.15)',
            borderColor: 'rgba(249,115,22,.8)',
            pointBackgroundColor: 'rgba(249,115,22,1)',
            borderWidth: 2,
        }],
    },
    options: {
        responsive: true,
        scales: {
            r: {
                min: 0, max: 5,
                ticks: { stepSize: 1, font: { size: 9 } },
                pointLabels: { font: { size: 9 } },
            }
        },
        plugins: { legend: { display: false } },
    }
});

function updateRadar() {
    radarChart.data.datasets[0].data = radarAxes.map(a => (scores[a] || 0) * 5);
    radarChart.update();
}

// ===== NAVIGATION RUBRIQUES =====
function goToRubrique(n) {
    document.querySelectorAll('.rubrique-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-rubrique-btn').forEach(b => b.classList.remove('active'));

    const panel = document.getElementById('panel' + n);
    if (panel) panel.classList.add('active');
    const navBtn = document.getElementById('navBtn' + n);
    if (navBtn) navBtn.classList.add('active');

    currentRubrique = n;
    document.getElementById('colMain').scrollTop = 0;

    // Sauvegarder rubrique_en_cours
    fetch('api/rh_entretien_save.php', {
        method: 'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body: JSON.stringify({ entretien_id: ENTRETIEN_ID, rubrique_en_cours: n })
    });
}

// ===== BLOCS SPÉCIAUX — FORMATION =====
function saveBlocFormation(rubrique, critere) {
    const data = {
        point_faible: document.getElementById('bf_point_' + critere)?.value || '',
        situation:    document.getElementById('bf_situation_' + critere)?.value || '',
        frequence:    document.getElementById('bf_frequence_' + critere)?.value || '',
        impact:       document.getElementById('bf_impact_' + critere)?.value || '',
        origine:      document.getElementById('bf_origine_' + critere)?.value || '',
        solution:     document.getElementById('bf_solution_' + critere)?.value || '',
        priorite:     document.getElementById('bf_priorite_' + critere)?.value || '',
    };
    // Validation
    let valid = true;
    ['point', 'situation', 'impact'].forEach(f => {
        const el = document.getElementById('bf_' + f + '_' + critere);
        const msg = document.getElementById('bfv_' + f + '_' + critere);
        if (el && !el.value && msg) { msg.style.display = 'block'; valid = false; }
        else if (msg) msg.style.display = 'none';
    });
    if (!valid) return;
    // Stocker en JSON dans le texte_final
    const ta = document.getElementById('ta_' + rubrique + '_' + critere);
    if (ta) ta.dataset.blocJson = JSON.stringify(data);
    triggerSaveBlocJson(rubrique, critere, data);
    classifierFormation(critere);
}

function classifierFormation(critereId) {
    const priorite = document.getElementById('bf_priorite_' + critereId)?.value;
    const el = document.getElementById('bf_classif_' + critereId);
    if (!el) return;
    if (!priorite) { el.style.display = 'none'; return; }
    const labels = {souhait:'Simple souhait',utile:'Besoin utile',justifie:'Besoin justifié',prioritaire:'⭐ Besoin prioritaire',refuse:'❌ Besoin refusé'};
    el.style.display = '';
    el.querySelector('span').textContent = labels[priorite] || priorite;
}

// ===== BLOCS SPÉCIAUX — MOTIVATION =====
function analyserMotivation(rubrique, critere) {
    const declaree = parseInt(document.getElementById('bm_declaree_' + critere)?.value) || 0;
    const observee  = parseInt(document.getElementById('bm_observee_' + critere)?.value) || 0;
    const txtEl     = document.getElementById('bm_analyse_txt_' + critere);
    const alertEl   = document.getElementById('bm_alerte_' + critere);
    if (!declaree || !observee || !txtEl) { saveBlocMotivation(rubrique, critere); return; }
    const diff = declaree - observee;
    let analyse = '';
    if (Math.abs(diff) <= 1) {
        analyse = observee >= 4 ? '✅ Motivation cohérente et élevée' : observee >= 3 ? '🟡 Motivation cohérente — niveau moyen' : '🔴 Motivation cohérente mais basse — vigilance';
    } else if (diff > 1) {
        analyse = '⚠️ Déclaration surestimée — désengagement latent possible';
        if (alertEl) alertEl.style.display = 'block';
    } else {
        analyse = '🟡 Modestie déclarée — vérifier avec les comportements';
    }
    if (txtEl) txtEl.textContent = analyse;
    if (alertEl && Math.abs(diff) <= 1) alertEl.style.display = 'none';
    saveBlocMotivation(rubrique, critere);
}

function saveBlocMotivation(rubrique, critere) {
    const data = {
        declaree: document.getElementById('bm_declaree_' + critere)?.value || '',
        observee:  document.getElementById('bm_observee_' + critere)?.value || '',
        freins:    document.getElementById('bm_freins_' + critere)?.value || '',
    };
    triggerSaveBlocJson(rubrique, critere, data);
}

// ===== BLOCS SPÉCIAUX — ÉVOLUTION =====
function classifierEvolution(critereId) {
    const clarte      = document.getElementById('be_clarte_' + critereId)?.value;
    const coherence   = document.getElementById('be_coherence_' + critereId)?.value;
    const faisabilite = document.getElementById('be_faisabilite_' + critereId)?.value;
    const txtEl       = document.getElementById('be_classif_txt_' + critereId);
    if (!txtEl) return;
    let classif = 'Remplir les critères';
    if (clarte && coherence && faisabilite) {
        if (faisabilite === 'non' || coherence === 'incoherent') classif = '❌ Évolution non pertinente actuellement';
        else if (clarte === 'absent' || clarte === 'flou')        classif = '🔄 Évolution à construire';
        else if (faisabilite === 'oui_court' && coherence.startsWith('tres'))  classif = '✅ Évolution réaliste à court terme';
        else if (faisabilite.startsWith('conditionnel'))           classif = '🟡 Évolution conditionnelle';
        else                                                        classif = '🔵 Évolution réaliste à moyen terme';
    }
    txtEl.textContent = classif;
    const rubDom = document.querySelector('[data-critere="' + critereId + '"]');
    const rubrique = rubDom ? parseInt(rubDom.dataset.rubrique) : null;
    if (rubrique) {
        const data = {
            clarte, coherence, faisabilite, classif
        };
        triggerSaveBlocJson(rubrique, critereId, data);
    }
}

// ===== SAUVEGARDE BLOC JSON =====
function triggerSaveBlocJson(rubrique, critere, data) {
    if (READ_ONLY) return;
    setSaveState('saving');
    clearTimeout(saveTimers[rubrique + '_' + critere]);
    saveTimers[rubrique + '_' + critere] = setTimeout(() => {
        const ta   = document.getElementById('ta_' + rubrique + '_' + critere);
        const note = document.getElementById('noteVal_' + rubrique + '_' + critere);
        const vis  = document.getElementById('vis_' + rubrique + '_' + critere);
        const payload = {
            entretien_id:          ENTRETIEN_ID,
            rubrique_id:           rubrique,
            critere_id:            critere,
            note:                  note ? (parseInt(note.value) || null) : null,
            texte_final:           JSON.stringify(data),
            commentaire_libre:     ta ? ta.value : '',
            visible_collaborateur: vis && vis.checked ? 1 : 0,
        };
        fetch('api/rh_entretien_save.php', {
            method:  'POST',
            headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
            body:    JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) { setSaveState('saved', d.saved_at); if (d.scores) { scores = Object.assign(scores, d.scores); updateRadar(); } }
            else setSaveState('error');
        })
        .catch(() => setSaveState('error'));
    }, 800);
}

// ===== ÉTOILES =====
function setStar(rubrique, critere, val) {
    const container = document.getElementById('stars_' + rubrique + '_' + critere);
    container.querySelectorAll('.star-btn').forEach(s => {
        s.classList.toggle('active', parseInt(s.dataset.val) <= val);
    });
    document.getElementById('noteVal_' + rubrique + '_' + critere).value = val;
    triggerSave(rubrique, critere);
}

// ===== RÉPONSES TYPES =====
function insererReponse(rubrique, critere, rtId, btn) {
    const ta = document.getElementById('ta_' + rubrique + '_' + critere);
    // Chercher texte dans les données chargées
    const rts = REPONSES_TYPES[rubrique] && REPONSES_TYPES[rubrique][critere] ? REPONSES_TYPES[rubrique][critere] : [];
    const rt  = rts.find(r => r.id == rtId);
    if (rt && ta) {
        ta.value = ta.value ? ta.value + '\n' + rt.texte : rt.texte;
        triggerSave(rubrique, critere);
    }
}

// ===== SAUVEGARDE AUTO =====
const saveTimers = {};

document.querySelectorAll('.commentaire-input').forEach(ta => {
    ta.addEventListener('input', () => {
        const r = ta.dataset.rubrique;
        const c = ta.dataset.critere;
        setSaveState('saving');
        clearTimeout(saveTimers[r + '_' + c]);
        saveTimers[r + '_' + c] = setTimeout(() => {
            doSave(parseInt(r), parseInt(c));
        }, 2000);
    });
});

function triggerSave(rubrique, critere) {
    if (READ_ONLY) return;
    setSaveState('saving');
    clearTimeout(saveTimers[rubrique + '_' + critere]);
    saveTimers[rubrique + '_' + critere] = setTimeout(() => {
        doSave(rubrique, critere);
    }, 800);
}

function doSave(rubrique, critere) {
    const ta   = document.getElementById('ta_' + rubrique + '_' + critere);
    const note = document.getElementById('noteVal_' + rubrique + '_' + critere);
    const vis  = document.getElementById('vis_' + rubrique + '_' + critere);

    if (!ta) return;

    const payload = {
        entretien_id:         ENTRETIEN_ID,
        rubrique_id:          rubrique,
        critere_id:           critere,
        note:                 note ? (parseInt(note.value) || null) : null,
        texte_final:          ta.value,
        visible_collaborateur: vis && vis.checked ? 1 : 0,
    };

    fetch('api/rh_entretien_save.php', {
        method:  'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body:    JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            setSaveState('saved', data.saved_at);
            if (data.scores) {
                scores = Object.assign(scores, data.scores);
                updateRadar();
            }
            if (data.new_alerts && data.new_alerts.length > 0) {
                data.new_alerts.forEach(al => alertes.push(al));
                refreshAlertes();
            }
            // Marquer rubrique comme faite dans nav
            const navBtn = document.getElementById('navBtn' + rubrique);
            if (navBtn) navBtn.classList.add('done');
            const stepBtn = document.getElementById('stepBtn' + rubrique);
            if (stepBtn && !stepBtn.classList.contains('active')) stepBtn.classList.add('done');
            // Déclencher synthèse avec délai
            triggerSyntheseAfterSave(rubrique);
        } else {
            setSaveState('error');
        }
    })
    .catch(() => setSaveState('error'));
}

function setSaveState(state, time) {
    const dot   = document.getElementById('saveDot');
    const label = document.getElementById('saveLabel');
    dot.className = 'save-dot ' + state;
    if (state === 'saved')  label.textContent = 'Sauvegardé ' + (time ? 'à ' + time : '');
    if (state === 'saving') label.textContent = 'Sauvegarde…';
    if (state === 'error')  label.textContent = 'Erreur';
}

// ===== ALERTES =====
function refreshAlertes() {
    const container = document.getElementById('alertesContainer');
    if (alertes.length === 0) {
        container.innerHTML = '<div class="no-alerts">Aucune alerte détectée</div>';
        return;
    }
    container.innerHTML = alertes.map(al =>
        `<div class="alerte-item ${al.niveau === 'warning' ? 'warning' : ''}">${al.message || al.type || al.type_alerte || ''}</div>`
    ).join('');
}

// ===== MODE VOCAL =====
function startVoice(rubrique, critere) {
    const btn = document.getElementById('vocalBtn_' + rubrique + '_' + critere);
    const ta  = document.getElementById('ta_' + rubrique + '_' + critere);

    if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
        alert('La reconnaissance vocale n\'est pas supportée par ce navigateur.');
        return;
    }

    if (activeVoice === rubrique + '_' + critere && activeRecognition) {
        activeRecognition.stop();
        activeVoice = null;
        activeRecognition = null;
        btn.classList.remove('recording');
        btn.textContent = '🎤';
        return;
    }

    if (activeRecognition) { activeRecognition.stop(); }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const recognition = new SpeechRecognition();
    recognition.lang = 'fr-FR';
    recognition.continuous = false;
    recognition.interimResults = false;

    recognition.onstart = () => {
        btn.classList.add('recording');
        btn.textContent = '⏹';
        activeVoice = rubrique + '_' + critere;
        activeRecognition = recognition;
    };
    recognition.onresult = (e) => {
        const transcript = e.results[0][0].transcript;
        ta.value = ta.value ? ta.value + ' ' + transcript : transcript;
        triggerSave(rubrique, critere);
    };
    recognition.onend = () => {
        btn.classList.remove('recording');
        btn.textContent = '🎤';
        activeVoice = null;
        activeRecognition = null;
    };
    recognition.onerror = () => {
        btn.classList.remove('recording');
        btn.textContent = '🎤';
        activeVoice = null;
        activeRecognition = null;
    };
    recognition.start();
}

// ===== AIDE IA =====
function suggestIA(rubrique, critere) {
    const ta      = document.getElementById('ta_' + rubrique + '_' + critere);
    const popup   = document.getElementById('iaPopup_' + rubrique + '_' + critere);
    const textEl  = document.getElementById('iaText_' + rubrique + '_' + critere);
    const note    = document.getElementById('noteVal_' + rubrique + '_' + critere);
    const rubDef  = RUBRIQUES_DEF[rubrique];
    const critDef = rubDef ? (rubDef.criteres.find(c => c.id === critere) || {}) : {};

    // Contexte collab pour cette rubrique
    const collabTextes = Object.values(REPONSES_COLLAB)
        .filter(r => r.rubrique === (rubDef ? rubDef.nom : ''))
        .map(r => r.texte)
        .filter(Boolean)
        .join(' | ');

    popup.classList.add('show');
    textEl.textContent = 'Génération en cours…';

    fetch('api/rh_entretien_ia.php', {
        method:  'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body:    JSON.stringify({
            entretien_id:   ENTRETIEN_ID,
            rubrique_id:    rubrique,
            rubrique_nom:   rubDef ? rubDef.nom : '',
            critere_id:     critere,
            critere_label:  critDef.label || '',
            note:           note ? parseInt(note.value) : null,
            texte_actuel:   ta ? ta.value : '',
            reponses_collab: collabTextes,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.suggestion) {
            textEl.textContent = data.suggestion;
        } else {
            textEl.textContent = data.error || 'Aucune suggestion disponible.';
        }
    })
    .catch(() => { textEl.textContent = 'Erreur de connexion à l\'API IA.'; });
}

function insertIaSuggestion(rubrique, critere) {
    const ta     = document.getElementById('ta_' + rubrique + '_' + critere);
    const textEl = document.getElementById('iaText_' + rubrique + '_' + critere);
    if (ta && textEl) {
        ta.value = textEl.textContent;
        triggerSave(rubrique, critere);
    }
    closeIaPopup(rubrique, critere);
}

function closeIaPopup(rubrique, critere) {
    const popup = document.getElementById('iaPopup_' + rubrique + '_' + critere);
    if (popup) popup.classList.remove('show');
}

// Fermer popup IA au clic dehors
document.addEventListener('click', (e) => {
    if (!e.target.closest('.ia-popup') && !e.target.classList.contains('btn-ia')) {
        document.querySelectorAll('.ia-popup.show').forEach(p => p.classList.remove('show'));
    }
});

// ===== COPIER PHRASE MANAGER =====
function copyPhrase(btn, texte) {
    navigator.clipboard.writeText(texte).then(() => {
        btn.classList.add('copied');
        setTimeout(() => btn.classList.remove('copied'), 1500);
    }).catch(() => {
        // Fallback si clipboard API indisponible
        const ta = document.querySelector('.rubrique-panel.active .commentaire-input');
        if (ta) { ta.value += (ta.value ? '\n' : '') + texte; }
    });
}

// ===== TOGGLE PANNEAU DROIT =====
function toggleRight() {
    const col = document.getElementById('colRight');
    const btn = document.getElementById('btnToggleRight');
    rightCollapsed = !rightCollapsed;
    col.classList.toggle('collapsed', rightCollapsed);
    btn.textContent = rightCollapsed ? '▶' : '◀';
}

// ===== QUESTION CAROUSEL =====

function qGetCards(rubriqueId) {
    return Array.from(document.querySelectorAll(`#qStage${rubriqueId} .q-card`));
}

function qNavigate(rubriqueId, dir) {
    const cards = qGetCards(rubriqueId);
    if (!cards.length) return;
    const n    = currentQIndex[rubriqueId] ?? 0;
    const newN = Math.max(0, Math.min(cards.length - 1, n + dir));
    if (newN === n) return;
    qGoTo(rubriqueId, newN, dir < 0);
}

function qGoTo(rubriqueId, newN, goBack) {
    const cards = qGetCards(rubriqueId);
    if (!cards.length || newN < 0 || newN >= cards.length) return;
    const n = currentQIndex[rubriqueId] ?? 0;

    // Remove active from current
    if (cards[n]) {
        cards[n].classList.remove('q-active', 'q-dir-back');
    }
    // Activate new
    cards[newN].classList.remove('q-dir-back');
    if (goBack) cards[newN].classList.add('q-dir-back');
    cards[newN].classList.add('q-active');

    currentQIndex[rubriqueId] = newN;
    qUpdateNav(rubriqueId, newN, cards.length);
}

function qUpdateNav(rubriqueId, idx, total) {
    // Counter
    const counter = document.getElementById('qCounter' + rubriqueId);
    if (counter) counter.textContent = `Question ${idx + 1} / ${total}`;

    // Progress bar
    const fill = document.getElementById('qProgress' + rubriqueId);
    if (fill) fill.style.width = `${Math.round((idx + 1) * 100 / total)}%`;

    // Prev/Next buttons
    const prevBtn = document.getElementById('qPrev' + rubriqueId);
    const nextBtn = document.getElementById('qNext' + rubriqueId);
    if (prevBtn) prevBtn.disabled = idx === 0;
    if (nextBtn) nextBtn.disabled = idx === total - 1;

    // Dots
    const dots = document.querySelectorAll(`#qDots${rubriqueId} .q-dot`);
    dots.forEach((d, i) => d.classList.toggle('q-dot-active', i === idx));
}

function selectQOption(rubriqueId, qi, qId, optId, btn) {
    // Toggle selection (click again = deselect)
    const isSelected = btn.classList.contains('q-opt-selected');
    // Deselect all options in this question
    document.querySelectorAll(`#qCard${rubriqueId}_${qi} .q-opt-btn`).forEach(b => b.classList.remove('q-opt-selected'));
    if (!isSelected) btn.classList.add('q-opt-selected');
    saveQDebounced(rubriqueId, qi, qId);
}

function toggleQAutre(rubriqueId, qi) {
    const wrap = document.getElementById(`qAutre${rubriqueId}_${qi}`);
    const btn  = wrap?.previousElementSibling;
    if (!wrap) return;
    const isHidden = wrap.style.display === 'none';
    wrap.style.display = isHidden ? '' : 'none';
    if (btn) btn.classList.toggle('q-opt-selected', isHidden);
    if (isHidden) wrap.querySelector('textarea')?.focus();
}

function setQStar(rubriqueId, qi, qId, val) {
    const container = document.getElementById(`qStars${rubriqueId}_${qi}`);
    if (!container) return;
    container.querySelectorAll('.q-star').forEach(s => {
        s.classList.toggle('q-star-active', parseInt(s.dataset.val) <= val);
    });
    container.dataset.note = val;
    saveQNow(rubriqueId, qi, qId);
    // Auto-avance à la question suivante après un court délai
    setTimeout(() => qNavigate(rubriqueId, 1), 350);
}

function saveQDebounced(rubriqueId, qi, qId) {
    const key = `${rubriqueId}_${qi}`;
    clearTimeout(qSaveTimers[key]);
    qSaveTimers[key] = setTimeout(() => saveQNow(rubriqueId, qi, qId), 1200);
}

function saveQNow(rubriqueId, qi, qId) {
    const selectedOpt = document.querySelector(`#qCard${rubriqueId}_${qi} .q-opt-btn.q-opt-selected`);
    const optId       = selectedOpt ? parseInt(selectedOpt.dataset.optId) : null;
    const texteLibre  = document.getElementById(`qAutreTa${rubriqueId}_${qi}`)?.value || null;
    const adminTa     = document.getElementById(`qAdminTa${rubriqueId}_${qi}`)?.value || null;
    const starsEl     = document.getElementById(`qStars${rubriqueId}_${qi}`);
    const note        = starsEl?.dataset.note ? parseInt(starsEl.dataset.note) : null;

    setSaveState('saving');
    fetch('api/rh_entretien_save_question.php', {
        method:  'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body:    JSON.stringify({
            entretien_id:      ENTRETIEN_ID,
            question_id:       qId,
            option_id:         optId,
            texte_libre:       texteLibre,
            commentaire_admin: adminTa,
            note:              note,
        }),
    })
    .then(r => r.json())
    .then(d => {
        setSaveState(d.success ? 'saved' : 'error', d.saved_at);
        if (d.success) {
            // Marquer le dot comme fait
            const dots = document.querySelectorAll(`#qDots${rubriqueId} .q-dot`);
            if (dots[qi]) dots[qi].classList.add('q-dot-done');
            // Déclencher synthèse
            triggerSyntheseAfterSave(rubriqueId);
        }
    })
    .catch(() => setSaveState('error'));
}

// ===== VOCAL POUR QUESTIONS =====
function startQVoice(rubriqueId, qi, qId, target) {
    const key    = `${rubriqueId}_${qi}_${target}`;
    const btnId  = target === 'admin' ? `qVoiceAdmin${rubriqueId}_${qi}` : `qVoice${rubriqueId}_${qi}`;
    const btn    = document.getElementById(btnId);
    const taId   = target === 'admin' ? `qAdminTa${rubriqueId}_${qi}` : `qAutreTa${rubriqueId}_${qi}`;
    const ta     = document.getElementById(taId);

    if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
        alert('La reconnaissance vocale n\'est pas supportée par ce navigateur.');
        return;
    }

    // Si même bouton actif → arrêter
    if (activeQVoice === key && activeQRecog) {
        activeQRecog.stop();
        return;
    }
    if (activeQRecog) activeQRecog.stop();

    // Si target === 'user', montrer la zone texte libre si pas encore visible
    if (target === 'user') {
        const wrap = document.getElementById(`qAutre${rubriqueId}_${qi}`);
        if (wrap && wrap.style.display === 'none') {
            wrap.style.display = '';
        }
    }

    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    const rec = new SR();
    rec.lang = 'fr-FR';
    rec.continuous = false;
    rec.interimResults = false;

    rec.onstart = () => {
        btn?.classList.add('q-recording');
        if (btn) btn.textContent = '⏹ Stop';
        activeQVoice = key;
        activeQRecog = rec;
    };
    rec.onresult = (e) => {
        const t = e.results[0][0].transcript;
        if (ta) ta.value = ta.value ? ta.value + ' ' + t : t;
        saveQDebounced(rubriqueId, qi, qId);
    };
    rec.onend = () => {
        btn?.classList.remove('q-recording');
        if (btn) btn.textContent = target === 'admin' ? '🎤 Admin' : '🎤 Réponse';
        activeQVoice = null;
        activeQRecog = null;
    };
    rec.onerror = () => {
        btn?.classList.remove('q-recording');
        if (btn) btn.textContent = target === 'admin' ? '🎤 Admin' : '🎤 Réponse';
        activeQVoice = null;
        activeQRecog = null;
    };
    rec.start();
}

// ===== SYNTHÈSE RUBRIQUE =====
const synTimers = {};

function refreshSynthese(rubriqueId, delay) {
    if (delay) {
        clearTimeout(synTimers[rubriqueId]);
        synTimers[rubriqueId] = setTimeout(() => refreshSynthese(rubriqueId), delay);
        return;
    }

    const box = document.getElementById('syntheseBox' + rubriqueId);
    const btn = document.getElementById('synRefreshBtn' + rubriqueId);
    if (!box) return;

    // Indiquer le chargement
    box.className = 'rubrique-synthese ton-loading';
    box.querySelector('#synMgr' + rubriqueId).textContent = 'Analyse en cours…';
    if (btn) btn.classList.add('spinning');

    fetch('api/rh_entretien_synthese.php', {
        method:  'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body:    JSON.stringify({ entretien_id: ENTRETIEN_ID, rubrique_id: rubriqueId }),
    })
    .then(r => r.json())
    .then(data => {
        if (btn) btn.classList.remove('spinning');
        if (!data.success || !data.synthese_manager) {
            box.className = 'rubrique-synthese ton-loading';
            box.querySelector('#synMgr' + rubriqueId).innerHTML =
                '<span style="font-style:italic;opacity:.6">Enregistrez des réponses pour générer l\'analyse…</span>';
            return;
        }
        const ton = data.tonalite || 'neutre';
        box.className = 'rubrique-synthese ton-' + ton;

        const mgrEl    = document.getElementById('synMgr' + rubriqueId);
        const collabEl = document.getElementById('synCollab' + rubriqueId);

        if (mgrEl)    mgrEl.textContent    = data.synthese_manager;
        if (collabEl) {
            collabEl.textContent  = data.synthese_collab || '';
            collabEl.className    = 'synthese-collab-text';
            collabEl.style.display = data.synthese_collab ? '' : 'none';
        }
    })
    .catch(() => {
        if (btn) btn.classList.remove('spinning');
        box.className = 'rubrique-synthese ton-loading';
    });
}

// Déclencher la synthèse 30 s après chaque save sur la rubrique courante
const _origSetSaveState = setSaveState;
// Patch post-save pour déclencher la synthèse avec délai
function triggerSyntheseAfterSave(rubriqueId) {
    refreshSynthese(rubriqueId, 2000); // 2 s après save
}

// ===== FINALISER =====
function finaliserEntretien() {
    if (READ_ONLY) return;
    if (!confirm('Voulez-vous finaliser cet entretien ? Le statut passera à "finalise".')) return;
    fetch('api/rh_entretien_save.php', {
        method:  'POST',
        headers: Object.assign({'Content-Type': 'application/json'}, CSRF_HEADERS),
        body:    JSON.stringify({ entretien_id: ENTRETIEN_ID, action: 'finaliser' }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'rh_entretien_liste.php?msg=finalise';
        } else {
            alert(data.error || data.message || 'Erreur lors de la finalisation.');
        }
    })
    .catch(() => alert('Erreur réseau.'));
}

// ===== MODE LECTURE SEULE =====
if (READ_ONLY) {
    document.addEventListener('DOMContentLoaded', () => {
        // Désactiver tous les boutons étoiles, textareas, inputs, selects, boutons d'action
        document.querySelectorAll(
            '.star-btn, textarea, input[type="text"], input[type="checkbox"], select, .rt-btn, .btn-finish, .btn-nav.btn-finish, [onclick*="finaliser"], [onclick*="saveBlocFormation"], [onclick*="saveBlocMotivation"], [onclick*="saveBlocCharge"], [onclick*="saveBlocEvolution"], [onclick*="insererReponse"]'
        ).forEach(el => {
            el.disabled = true;
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.7';
            el.title = 'Entretien finalisé — lecture seule';
        });

        // Masquer le bouton Finaliser
        document.querySelectorAll('.btn-finish').forEach(el => el.style.display = 'none');

        // Ajouter un label read-only sur les textareas
        document.querySelectorAll('textarea').forEach(ta => {
            ta.setAttribute('readonly', 'readonly');
            ta.style.background = 'var(--bg-secondary)';
            ta.style.cursor = 'default';
        });
    });
}
</script>

<?php
$layout_content = ob_get_clean();
$layout_extra_js = '';

require_once __DIR__ . '/inc/layout_maboximmo.php';

