<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if ($roleId !== 1) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs.');
}

// ============================================================
// TRAITEMENT POST (avant tout HTML)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Toggle actif (réponse JSON) ---
    if ($action === 'toggle_actif') {
        header('Content-Type: application/json');
        $table = $_POST['table'] ?? '';
        $id    = (int)($_POST['id'] ?? 0);
        $actif = ($_POST['actif'] ?? '0') === '1' ? 1 : 0;
        $allowed = ['rh_entretien_rubriques','rh_entretien_criteres','rh_entretien_questions_user','rh_entretien_question_options'];
        if (in_array($table, $allowed, true) && $id > 0) {
            try {
                $pdo->prepare("UPDATE $table SET actif=? WHERE id=?")->execute([$actif, $id]);
                echo json_encode(['success' => true]); exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
            }
        }
        echo json_encode(['success' => false]); exit;
    }

    // --- Rubrique ---
    if ($action === 'save_rubrique') {
        $id    = (int)($_POST['id'] ?? 0);
        $nom   = trim($_POST['nom'] ?? '');
        $code  = trim($_POST['code'] ?? '');
        $ordre = (int)($_POST['ordre'] ?? 0);
        $mgr   = isset($_POST['manager_only']) ? 1 : 0;
        $vc    = isset($_POST['visible_collaborateur']) ? 1 : 0;
        $vpdf  = isset($_POST['visible_pdf']) ? 1 : 0;
        $tabRet = $_POST['tab_return'] ?? 'rubriques';
        if ($nom && $code) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE rh_entretien_rubriques SET nom=?,code=?,ordre=?,manager_only=?,visible_collaborateur=?,visible_pdf=? WHERE id=?")
                        ->execute([$nom,$code,$ordre,$mgr,$vc,$vpdf,$id]);
                } else {
                    $pdo->prepare("INSERT INTO rh_entretien_rubriques (nom,code,ordre,manager_only,visible_manager,visible_collaborateur,visible_pdf,actif) VALUES (?,?,?,?,1,?,?,1)")
                        ->execute([$nom,$code,$ordre,$mgr,$vc,$vpdf]);
                }
                header("Location: rh_entretien_admin.php?tab=$tabRet&msg=ok"); exit;
            } catch (PDOException $e) {
                $postError = 'Erreur: ' . $e->getMessage();
            }
        }
    }

    // --- Critère ---
    if ($action === 'save_critere') {
        $id    = (int)($_POST['id'] ?? 0);
        $rubId = (int)($_POST['rubrique_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $code  = trim($_POST['code'] ?? '');
        $type  = $_POST['type_champ'] ?? 'etoiles_texte';
        $axe   = trim($_POST['axe_radar'] ?? '');
        $poids = (float)($_POST['poids_score'] ?? 1.0);
        $seuil = (int)($_POST['seuil_alerte'] ?? 2);
        $ordre = (int)($_POST['ordre'] ?? 0);
        $vm    = isset($_POST['visible_manager']) ? 1 : 0;
        $vc    = isset($_POST['visible_collaborateur']) ? 1 : 0;
        $vpdf  = isset($_POST['visible_pdf']) ? 1 : 0;
        $tabRet = $_POST['tab_return'] ?? 'criteres';
        if ($label && $code && $rubId) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE rh_entretien_criteres SET rubrique_id=?,label=?,code=?,type_champ=?,axe_radar=?,poids_score=?,seuil_alerte=?,ordre=?,visible_manager=?,visible_collaborateur=?,visible_pdf=? WHERE id=?")
                        ->execute([$rubId,$label,$code,$type,$axe?:null,$poids,$seuil,$ordre,$vm,$vc,$vpdf,$id]);
                } else {
                    $pdo->prepare("INSERT INTO rh_entretien_criteres (rubrique_id,label,code,type_champ,axe_radar,poids_score,seuil_alerte,ordre,visible_manager,visible_collaborateur,visible_pdf,actif) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)")
                        ->execute([$rubId,$label,$code,$type,$axe?:null,$poids,$seuil,$ordre,$vm,$vc,$vpdf]);
                }
                header("Location: rh_entretien_admin.php?tab=$tabRet&msg=ok"); exit;
            } catch (PDOException $e) {
                $postError = 'Erreur: ' . $e->getMessage();
            }
        }
    }

    // --- Réponse type ---
    if ($action === 'save_rt') {
        $id     = (int)($_POST['id'] ?? 0);
        $rubId  = (int)($_POST['rubrique_id'] ?? 0);
        $critId = (int)($_POST['critere_id'] ?? 0);
        $note   = (isset($_POST['note_cible']) && $_POST['note_cible'] !== '') ? (int)$_POST['note_cible'] : null;
        $ton    = $_POST['tonalite'] ?? 'neutre';
        $texte  = trim($_POST['texte'] ?? '');
        $ordre  = (int)($_POST['ordre'] ?? 0);
        $vm     = isset($_POST['visible_manager']) ? 1 : 0;
        $vc     = isset($_POST['visible_collaborateur']) ? 1 : 0;
        $vpdf   = isset($_POST['visible_pdf']) ? 1 : 0;
        $tabRet = $_POST['tab_return'] ?? 'reponses_types';
        if ($texte && $critId) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE rh_entretien_reponses_types SET rubrique_id=?,critere_id=?,note_cible=?,tonalite=?,texte=?,ordre=?,visible_manager=?,visible_collaborateur=?,visible_pdf=? WHERE id=?")
                        ->execute([$rubId,$critId,$note,$ton,$texte,$ordre,$vm,$vc,$vpdf,$id]);
                } else {
                    $pdo->prepare("INSERT INTO rh_entretien_reponses_types (rubrique_id,critere_id,note_cible,tonalite,texte,ordre,actif,visible_manager,visible_collaborateur,visible_pdf) VALUES (?,?,?,?,?,?,1,?,?,?)")
                        ->execute([$rubId,$critId,$note,$ton,$texte,$ordre,$vm,$vc,$vpdf]);
                }
                header("Location: rh_entretien_admin.php?tab=$tabRet&msg=ok"); exit;
            } catch (PDOException $e) {
                $postError = 'Erreur: ' . $e->getMessage();
            }
        }
    }

    // --- Phrase manager ---
    if ($action === 'save_phrase') {
        $id    = (int)($_POST['id'] ?? 0);
        $cat   = $_POST['categorie'] ?? 'ouverture';
        $texte = trim($_POST['texte'] ?? '');
        $ordre = (int)($_POST['ordre'] ?? 0);
        $tabRet = $_POST['tab_return'] ?? 'phrases';
        if ($texte) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE rh_entretien_phrases_manager SET categorie=?,texte=?,ordre=? WHERE id=?")
                        ->execute([$cat,$texte,$ordre,$id]);
                } else {
                    $pdo->prepare("INSERT INTO rh_entretien_phrases_manager (categorie,texte,ordre,actif) VALUES (?,?,?,1)")
                        ->execute([$cat,$texte,$ordre]);
                }
                header("Location: rh_entretien_admin.php?tab=$tabRet&msg=ok"); exit;
            } catch (PDOException $e) {
                $postError = 'Erreur: ' . $e->getMessage();
            }
        }
    }

    // --- Question user ---
    if ($action === 'save_question') {
        $id       = (int)($_POST['id'] ?? 0);
        $rubId    = (int)($_POST['rubrique_id'] ?? 0);
        $label    = trim($_POST['label'] ?? '');
        $qText    = trim($_POST['question_text'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $type     = $_POST['type_champ'] ?? 'texte';
        $ordre    = (int)($_POST['ordre'] ?? 0);
        $tabRet   = $_POST['tab_return'] ?? 'rubriques';
        if ($label && $rubId) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE rh_entretien_questions_user SET rubrique_id=?,label=?,question_text=?,description=?,type_champ=?,ordre=? WHERE id=?")
                        ->execute([$rubId,$label,$qText,$desc,$type,$ordre,$id]);
                } else {
                    $pdo->prepare("INSERT INTO rh_entretien_questions_user (rubrique_id,label,question_text,description,type_champ,ordre,actif) VALUES (?,?,?,?,?,?,1)")
                        ->execute([$rubId,$label,$qText,$desc,$type,$ordre]);
                }
                header("Location: rh_entretien_admin.php?tab=$tabRet&msg=ok"); exit;
            } catch (PDOException $e) {
                $postError = 'Erreur: ' . $e->getMessage();
            }
        }
    }

    // --- Suppression générique ---
    if ($action === 'delete') {
        $table  = $_POST['table'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        $tabRet = $_POST['tab_return'] ?? 'rubriques';
        $allowed = ['rh_entretien_rubriques','rh_entretien_criteres','rh_entretien_reponses_types','rh_entretien_phrases_manager','rh_entretien_questions_user'];
        if (in_array($table, $allowed, true) && $id > 0) {
            try {
                $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
                header("Location: rh_entretien_admin.php?tab=$tabRet&msg=deleted"); exit;
            } catch (PDOException $e) {
                $postError = 'Erreur: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================
// CHARGEMENT DES DONNÉES
// ============================================================
$tab = $_GET['tab'] ?? 'rubriques';
$msg = $_GET['msg'] ?? '';

$rubriques     = [];
$criteresByRub = [];
$questionsByRub = [];
$optionsByQ    = [];
$rtByCrit      = [];
$phrasesByCat  = [];

try {
    $rubriques = $pdo->query("SELECT * FROM rh_entretien_rubriques ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);

    $criteresRaw = $pdo->query("SELECT * FROM rh_entretien_criteres WHERE actif=1 ORDER BY rubrique_id, ordre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($criteresRaw as $c) $criteresByRub[(int)$c['rubrique_id']][] = $c;

    $questionsRaw = $pdo->query("SELECT * FROM rh_entretien_questions_user WHERE actif=1 ORDER BY rubrique_id, ordre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($questionsRaw as $q) $questionsByRub[(int)$q['rubrique_id']][] = $q;

    $optionsRaw = $pdo->query("SELECT * FROM rh_entretien_question_options WHERE actif=1 ORDER BY question_id, ordre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($optionsRaw as $o) $optionsByQ[(int)$o['question_id']][] = $o;

    $rtRaw = $pdo->query("SELECT rt.*, c.label AS crit_label FROM rh_entretien_reponses_types rt LEFT JOIN rh_entretien_criteres c ON c.id=rt.critere_id WHERE rt.actif=1 ORDER BY rt.rubrique_id, rt.critere_id, rt.note_cible, rt.ordre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rtRaw as $rt) $rtByCrit[(int)$rt['critere_id']][] = $rt;

    $phrasesRaw = $pdo->query("SELECT * FROM rh_entretien_phrases_manager WHERE actif=1 ORDER BY categorie, ordre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($phrasesRaw as $p) $phrasesByCat[$p['categorie']][] = $p;
} catch (PDOException $e) { /* ignoré si tables pas encore créées */ }

// Référentiels
$axesOptions = ['performance','motivation','relationnel','adaptabilite','autonomie','potentiel','organisation','maitrise_poste','digital','engagement','fiabilite','comportement','competences'];
$typesChamp  = ['etoiles','etoiles_texte','bloc_formation','bloc_motivation','bloc_charge','bloc_evolution','texte'];
$tonalites   = ['positive','neutre','vigilance','corrective','evolution'];
$categoriesPhrases = ['ouverture','transition','valorisation','recadrage','motivation','projection','cloture'];

$csrfToken = csrf_token();

// ============================================================
// LAYOUT VARIABLES
// ============================================================
$layout_title       = 'Catalogue Global — Entretiens';
$layout_module      = 'Ma Box RH';
$layout_sidebar     = 'rh_sidebar';
$layout_head_kpis   = '';
$layout_head_actions = '<a href="rh_entretien_liste.php" class="ph-btn ph-btn-outline" style="font-size:13px">&#8592; Retour liste</a>';

$layout_extra_css = <<<'EXTRACSS'
<style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
        .mbi-sidebar{position:fixed;left:0;top:0;width:250px;height:100vh;background:var(--sidebar);border-right:1px solid var(--stroke);overflow-y:auto;padding:12px 0}
        .mbi-sidebar-head{padding:10px 12px;border-bottom:1px solid var(--stroke);margin-bottom:12px}
        .mbi-sidebar-brand strong{font-size:14px;display:block}
        .mbi-sidebar-brand span{font-size:11px;color:var(--muted)}
        .mbi-sidebar-section{padding:12px;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--ink);margin:16px 8px 10px;background:rgba(72,120,166,0.06);border-left:3px solid rgba(72,120,166,0.2);border-radius:4px;letter-spacing:0.5px}
        .mbi-nav{list-style:none}
        .mbi-nav li a{display:flex;align-items:center;gap:4px;padding:6px 12px;color:var(--muted);text-decoration:none;font-size:15px;transition:all 0.2s}
        .mbi-nav li a:hover{color:var(--ink);background:#ffffff}
        .mbi-nav li a.active{color:var(--accent);background:rgba(72,120,166,0.08)}
        .mbi-main{margin-left:250px;flex:1;display:flex;flex-direction:column}
        .mbi-topbar{height:64px;background:var(--bg-soft);border-bottom:1px solid var(--stroke);display:flex;align-items:center;justify-content:space-between;padding:0 30px}
        .mbi-topbar h1{font-size:18px;color:var(--ink)}
        .mbi-content{flex:1;padding:30px;overflow-y:auto}
        @media(max-width:900px){.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}}

        /* Tabs */
        .cfg-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--stroke);padding-bottom:0;flex-wrap:wrap}
        .cfg-tab{padding:10px 20px;font-size:13px;font-weight:600;border:none;background:transparent;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;font-family:inherit}
        .cfg-tab.active{color:var(--accent);border-bottom-color:var(--accent)}

        /* Accordion rubriques */
        .rubrique-card{background:#ffffff;border:1px solid var(--stroke);border-radius:12px;margin-bottom:12px;overflow:hidden}
        .rubrique-header{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;transition:background .15s}
        .rubrique-header:hover{background:rgba(72,120,166,0.04)}
        .rubrique-title{flex:1;font-weight:600;font-size:14px;color:var(--ink)}
        .rubrique-meta{font-size:11px;color:var(--muted);margin-left:8px}
        .rubrique-body{display:none;padding:0 18px 16px;border-top:1px solid var(--stroke)}
        .rubrique-body.open{display:block}

        /* Toggle switch */
        .toggle{position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0}
        .toggle input{opacity:0;width:0;height:0;position:absolute}
        .toggle-track{position:absolute;inset:0;background:#ffffff;border-radius:22px;transition:background .2s;cursor:pointer}
        .toggle input:checked + .toggle-track{background:rgba(102,217,255,0.45)}
        .toggle-track::after{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:3px;left:3px;transition:transform .2s;box-shadow:0 1px 4px #f7f8fa}
        .toggle input:checked + .toggle-track::after{transform:translateX(18px)}

        /* Section headers inside rubrique-body */
        .section-head{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.6px;margin:14px 0 8px;display:flex;align-items:center;gap:8px}
        .section-head::after{content:'';flex:1;height:1px;background:var(--stroke-soft)}

        /* Critère list in accordion */
        .critere-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--stroke-soft)}
        .critere-row:last-child{border-bottom:none}
        .critere-label{flex:1;font-size:13px;font-weight:600;color:var(--ink)}

        /* Question list */
        .question-row{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--stroke-soft)}
        .question-row:last-child{border-bottom:none}
        .question-info{flex:1}
        .question-label{font-size:13px;font-weight:600;color:var(--ink)}
        .question-text{font-size:11px;color:var(--muted);margin-top:2px}

        /* Table */
        .data-table{width:100%;border-collapse:collapse;font-size:12px}
        .data-table th{background:rgba(0,0,0,0.2);padding:8px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#4a6038;border-bottom:1px solid var(--stroke)}
        .data-table td{padding:8px 12px;border-bottom:1px solid var(--stroke-soft);vertical-align:middle}
        .data-table tr:hover td{background:rgba(102,217,255,0.03)}

        /* Section rubrique in tabs 2/3 */
        .rub-section{margin-bottom:28px}
        .rub-section-title{font-size:13px;font-weight:700;color:var(--ink);padding:8px 12px;background:rgba(102,217,255,0.07);border-left:3px solid rgba(72,120,166,0.25);border-radius:4px;margin-bottom:10px}

        /* Sub-accordion for critères in tab 3 */
        .crit-accordion{background:rgba(20,30,45,0.6);border:1px solid var(--stroke-soft);border-radius:8px;margin-bottom:8px;overflow:hidden}
        .crit-acc-header{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;user-select:none}
        .crit-acc-header:hover{background:rgba(102,217,255,0.04)}
        .crit-acc-body{display:none;padding:0 14px 12px;border-top:1px solid var(--stroke-soft)}
        .crit-acc-body.open{display:block}

        /* Stars */
        .stars{color:#ffd479;font-size:13px;letter-spacing:1px}
        .stars-empty{color:rgba(255,212,121,0.25)}

        /* Tonalite badges */
        .ton-positive{background:rgba(124,245,214,0.15);border:1px solid rgba(124,245,214,0.3);color:#4a6038}
        .ton-neutre{background:#ffffff;border:1px solid #f0f1f3;color:var(--muted)}
        .ton-vigilance{background:rgba(255,212,121,0.15);border:1px solid rgba(255,212,121,0.3);color:#ffd479}
        .ton-corrective{background:rgba(255,107,122,0.15);border:1px solid rgba(255,107,122,0.3);color:#ff6b7a}
        .ton-evolution{background:rgba(186,148,255,0.15);border:1px solid rgba(186,148,255,0.3);color:#ba94ff}

        /* Badges */
        .badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;text-transform:uppercase;letter-spacing:.3px}
        .badge-axe{background:rgba(186,148,255,0.15);border:1px solid rgba(186,148,255,0.3);color:#ba94ff}
        .badge-count{display:inline-block;background:rgba(72,120,166,0.1);border:1px solid rgba(72,120,166,0.2);color:var(--accent);font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px}

        /* Buttons */
        .btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;border:none;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit;transition:all .15s}
        .btn-primary{background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:var(--accent)}
        .btn-primary:hover{background:rgba(102,217,255,0.33)}
        .btn-success{background:rgba(124,245,214,0.2);border:1px solid rgba(124,245,214,0.4);color:#4a6038}
        .btn-success:hover{background:rgba(124,245,214,0.33)}
        .btn-danger{background:rgba(255,107,122,0.15);border:1px solid rgba(255,107,122,0.4);color:#ff6b7a}
        .btn-danger:hover{background:rgba(255,107,122,0.25)}
        .btn-ghost{background:#ffffff;border:1px solid var(--stroke);color:var(--muted)}
        .btn-ghost:hover{background:#ffffff;color:var(--ink)}
        .btn-sm{padding:4px 10px;font-size:11px}
        .btn-add{margin-top:12px;padding:6px 14px;background:rgba(255,212,121,0.12);border:1px dashed rgba(255,212,121,0.4);color:#ffd479;border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit;transition:all .15s}
        .btn-add:hover{background:rgba(255,212,121,0.22)}
        .btn-add-rub{display:block;width:100%;margin-top:16px;padding:10px;background:rgba(72,120,166,0.06);border:1px dashed rgba(102,217,255,0.35);color:var(--accent);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;text-align:center;transition:all .15s}
        .btn-add-rub:hover{background:rgba(72,120,166,0.1)}

        /* Phrase category tabs */
        .cat-tabs{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap}
        .cat-tab{padding:6px 14px;font-size:12px;font-weight:600;border:1px solid var(--stroke);border-radius:20px;background:transparent;color:var(--muted);cursor:pointer;font-family:inherit;transition:all .15s}
        .cat-tab.active{background:rgba(72,120,166,0.1);border-color:rgba(72,120,166,0.25);color:var(--accent)}

        /* Phrase card */
        .phrase-card{background:#ffffff;border:1px solid var(--stroke-soft);border-radius:8px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:flex-start;gap:12px}
        .phrase-text{flex:1;font-size:13px;color:var(--ink);line-height:1.5}
        .phrase-ordre{font-size:10px;color:var(--muted);margin-top:3px}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center}
        .modal-overlay.open{display:flex}
        .modal-box{background:var(--bg-soft);border:1px solid var(--stroke);border-radius:14px;padding:24px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto}
        .modal-box h3{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:18px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
        .form-group input,.form-group textarea,.form-group select{width:100%;background:#ffffff;border:1px solid var(--stroke);border-radius:7px;padding:8px 12px;color:var(--ink);font-family:inherit;font-size:13px}
        .form-group select option{background:var(--bg-soft);color:var(--ink)}
        .form-group textarea{min-height:80px;resize:vertical}
        .form-group input:focus,.form-group textarea:focus,.form-group select:focus{outline:none;border-color:rgba(72,120,166,0.3)}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
        .form-check{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink);cursor:pointer}
        .form-check input[type=checkbox]{width:auto;cursor:pointer}
        .form-checks{display:flex;gap:16px;flex-wrap:wrap;margin-top:4px}
        .modal-actions{display:flex;gap:10px;margin-top:20px;justify-content:flex-end}

        /* Toast */
        .toast{position:fixed;bottom:24px;right:24px;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
        .toast.show{opacity:1;transform:translateY(0)}
        .toast.ok{background:rgba(124,245,214,0.2);border:1px solid rgba(124,245,214,0.5);color:#4a6038}
        .toast.err{background:rgba(255,107,122,0.2);border:1px solid rgba(255,107,122,0.5);color:#ff6b7a}

        /* Alerts */
        .msg-ok{background:rgba(124,245,214,0.12);border:1px solid rgba(124,245,214,0.35);color:#4a6038;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
        .msg-del{background:rgba(255,107,122,0.12);border:1px solid rgba(255,107,122,0.35);color:#ff6b7a;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
        .empty-state{text-align:center;color:var(--muted);padding:40px 20px;font-size:13px;font-style:italic}

        /* RT preview row */
        .rt-row{display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--stroke-soft)}
        .rt-row:last-child{border-bottom:none}
        .rt-stars{min-width:80px;font-size:13px}
        .rt-preview{flex:1;font-size:12px;color:var(--ink);line-height:1.4}
        .rt-ton{min-width:80px}
    </style>
EXTRACSS;

ob_start();
?>

        <?php if ($msg === 'ok'): ?><div class="msg-ok">✅ Enregistrement effectué avec succès.</div><?php endif; ?>
        <?php if ($msg === 'deleted'): ?><div class="msg-del">🗑️ Suppression effectuée.</div><?php endif; ?>
        <?php if (!empty($postError)): ?><div class="msg-del"><?= h($postError) ?></div><?php endif; ?>

        <!-- TABS -->
        <div class="cfg-tabs">
            <button class="cfg-tab <?= $tab === 'rubriques' ? 'active' : '' ?>" onclick="switchTab('rubriques')">
                📋 Rubriques &amp; Questions
            </button>
            <button class="cfg-tab <?= $tab === 'criteres' ? 'active' : '' ?>" onclick="switchTab('criteres')">
                ⭐ Critères &amp; Notes
            </button>
            <button class="cfg-tab <?= $tab === 'reponses_types' ? 'active' : '' ?>" onclick="switchTab('reponses_types')">
                💬 Réponses types
            </button>
            <button class="cfg-tab <?= $tab === 'phrases' ? 'active' : '' ?>" onclick="switchTab('phrases')">
                🗣️ Phrases manager
            </button>
        </div>

        <!-- ======================================================
             TAB 1 — RUBRIQUES & QUESTIONS
        ====================================================== -->
        <div id="tab-rubriques" class="tab-pane" style="<?= $tab !== 'rubriques' ? 'display:none' : '' ?>">

            <?php if (empty($rubriques)): ?>
                <div class="empty-state">Aucune rubrique trouvée.</div>
            <?php else: foreach ($rubriques as $rub):
                $rubId    = (int)$rub['id'];
                $rubActif = (bool)(int)($rub['actif'] ?? 1);
                $critList = $criteresByRub[$rubId] ?? [];
                $qList    = $questionsByRub[$rubId] ?? [];
            ?>
            <div class="rubrique-card" id="rub-<?= $rubId ?>">
                <div class="rubrique-header" onclick="toggleAccordion(<?= $rubId ?>)">
                    <label class="toggle" onclick="event.stopPropagation()" title="Activer/désactiver cette rubrique">
                        <input type="checkbox" <?= $rubActif ? 'checked' : '' ?>
                               onchange="toggleActif(this,'rh_entretien_rubriques',<?= $rubId ?>)">
                        <span class="toggle-track"></span>
                    </label>
                    <span class="rubrique-title">
                        <?= h($rub['nom']) ?>
                        <span class="rubrique-meta">[<?= h($rub['code']) ?>]</span>
                    </span>
                    <span class="badge-count"><?= count($critList) ?> critères</span>
                    <span class="badge-count" style="background:rgba(255,212,121,0.15);border-color:rgba(255,212,121,0.3);color:#ffd479"><?= count($qList) ?> questions</span>
                    <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();openModalRubrique(<?= $rubId ?>)" title="Modifier">✏️</button>
                    <span id="arrow-<?= $rubId ?>" style="color:var(--muted);font-size:12px;transition:transform .2s">▼</span>
                </div>

                <div class="rubrique-body" id="body-<?= $rubId ?>">

                    <div class="section-head">Critères notés</div>
                    <?php if (empty($critList)): ?>
                        <p style="font-size:12px;color:var(--muted);margin:4px 0 8px">Aucun critère pour cette rubrique.</p>
                    <?php else: foreach ($critList as $crit):
                        $cId = (int)$crit['id'];
                    ?>
                    <div class="critere-row">
                        <span class="critere-label"><?= h($crit['label']) ?></span>
                        <?php if (!empty($crit['axe_radar'])): ?>
                        <span class="badge badge-axe"><?= h($crit['axe_radar']) ?></span>
                        <?php endif; ?>
                        <span style="font-size:11px;color:var(--muted)">poids <?= h($crit['poids_score']) ?></span>
                        <button class="btn btn-ghost btn-sm" onclick="openModalCritere(<?= $cId ?>)" title="Modifier">✏️</button>
                    </div>
                    <?php endforeach; endif; ?>
                    <button class="btn-add" onclick="openModalCritereNew(<?= $rubId ?>)">+ Ajouter un critère</button>

                    <div class="section-head" style="margin-top:18px">Questions discussion</div>
                    <?php if (empty($qList)): ?>
                        <p style="font-size:12px;color:var(--muted);margin:4px 0 8px">Aucune question pour cette rubrique.</p>
                    <?php else: foreach ($qList as $q):
                        $qId    = (int)$q['id'];
                        $qActif = (bool)(int)($q['actif'] ?? 1);
                    ?>
                    <div class="question-row" id="q-<?= $qId ?>">
                        <label class="toggle" onclick="event.stopPropagation()" title="Activer/désactiver">
                            <input type="checkbox" <?= $qActif ? 'checked' : '' ?>
                                   onchange="toggleActif(this,'rh_entretien_questions_user',<?= $qId ?>)">
                            <span class="toggle-track"></span>
                        </label>
                        <div class="question-info">
                            <div class="question-label"><?= h($q['label']) ?></div>
                            <?php if (!empty($q['question_text'])): ?>
                            <div class="question-text"><?= h($q['question_text']) ?></div>
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-ghost btn-sm" onclick="openModalQuestion(<?= $qId ?>)" title="Modifier">✏️</button>
                        <form method="post" onsubmit="return confirm('Supprimer cette question ?')" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="table" value="rh_entretien_questions_user">
                            <input type="hidden" name="id" value="<?= $qId ?>">
                            <input type="hidden" name="tab_return" value="rubriques">
                            <button class="btn btn-danger btn-sm" type="submit">🗑</button>
                        </form>
                    </div>
                    <?php endforeach; endif; ?>
                    <button class="btn-add" onclick="openModalQuestionNew(<?= $rubId ?>)">+ Ajouter une question</button>

                </div>
            </div>
            <?php endforeach; endif; ?>

            <button class="btn-add-rub" onclick="openModalRubrique(0)">+ Nouvelle rubrique</button>
        </div>

        <!-- ======================================================
             TAB 2 — CRITÈRES & NOTES
        ====================================================== -->
        <div id="tab-criteres" class="tab-pane" style="<?= $tab !== 'criteres' ? 'display:none' : '' ?>">

            <?php if (empty($rubriques)): ?>
                <div class="empty-state">Aucune rubrique.</div>
            <?php else: foreach ($rubriques as $rub):
                $rubId    = (int)$rub['id'];
                $critList = $criteresByRub[$rubId] ?? [];
            ?>
            <div class="rub-section">
                <div class="rub-section-title"><?= h($rub['nom']) ?></div>
                <?php if (empty($critList)): ?>
                    <p style="font-size:12px;color:var(--muted);padding:4px 0">Aucun critère.</p>
                <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ordre</th>
                            <th>Label</th>
                            <th>Code</th>
                            <th>Axe radar</th>
                            <th>Poids</th>
                            <th>Seuil alerte</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($critList as $crit):
                        $cId = (int)$crit['id'];
                    ?>
                    <tr>
                        <td><?= h($crit['ordre']) ?></td>
                        <td style="font-weight:600"><?= h($crit['label']) ?></td>
                        <td><code style="font-size:10px;color:var(--muted)"><?= h($crit['code']) ?></code></td>
                        <td><?php if (!empty($crit['axe_radar'])): ?><span class="badge badge-axe"><?= h($crit['axe_radar']) ?></span><?php endif; ?></td>
                        <td><?= h($crit['poids_score']) ?></td>
                        <td><?= h($crit['seuil_alerte']) ?></td>
                        <td>
                            <div style="display:flex;gap:6px">
                                <button class="btn btn-primary btn-sm" onclick="openModalCritere(<?= $cId ?>)">✏️ Modifier</button>
                                <form method="post" onsubmit="return confirm('Supprimer ce critère ?')" style="display:inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="table" value="rh_entretien_criteres">
                                    <input type="hidden" name="id" value="<?= $cId ?>">
                                    <input type="hidden" name="tab_return" value="criteres">
                                    <button class="btn btn-danger btn-sm" type="submit">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <button class="btn-add" style="margin-top:10px" onclick="openModalCritereNew(<?= $rubId ?>)">+ Ajouter un critère</button>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- ======================================================
             TAB 3 — RÉPONSES TYPES
        ====================================================== -->
        <div id="tab-reponses_types" class="tab-pane" style="<?= $tab !== 'reponses_types' ? 'display:none' : '' ?>">

            <?php if (empty($rubriques)): ?>
                <div class="empty-state">Aucune rubrique.</div>
            <?php else: foreach ($rubriques as $rub):
                $rubId    = (int)$rub['id'];
                $critList = $criteresByRub[$rubId] ?? [];
                if (empty($critList)) continue;
            ?>
            <div class="rub-section">
                <div class="rub-section-title"><?= h($rub['nom']) ?></div>

                <?php foreach ($critList as $crit):
                    $cId     = (int)$crit['id'];
                    $rtList  = $rtByCrit[$cId] ?? [];
                    $accId   = 'crit-acc-' . $cId;
                ?>
                <div class="crit-accordion">
                    <div class="crit-acc-header" onclick="toggleCritAcc('<?= $accId ?>')">
                        <span style="flex:1;font-size:13px;font-weight:600;color:var(--ink)"><?= h($crit['label']) ?></span>
                        <?php if (!empty($crit['axe_radar'])): ?>
                        <span class="badge badge-axe"><?= h($crit['axe_radar']) ?></span>
                        <?php endif; ?>
                        <span class="badge-count"><?= count($rtList) ?> réponse(s)</span>
                        <span style="color:var(--muted);font-size:12px;margin-left:8px">▼</span>
                    </div>
                    <div class="crit-acc-body" id="<?= $accId ?>">
                        <?php if (empty($rtList)): ?>
                            <p style="font-size:12px;color:var(--muted);padding:4px 0">Aucune réponse type.</p>
                        <?php else: foreach ($rtList as $rt):
                            $rtId  = (int)$rt['id'];
                            $note  = (int)($rt['note_cible'] ?? 0);
                            $ton   = $rt['tonalite'] ?? 'neutre';
                        ?>
                        <div class="rt-row">
                            <div class="rt-stars">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <span class="<?= $s <= $note ? '' : 'stars-empty' ?>" style="color:<?= $s <= $note ? '#ffd479' : 'rgba(255,212,121,0.2)' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <div class="rt-ton">
                                <span class="badge ton-<?= h($ton) ?>"><?= h($ton) ?></span>
                            </div>
                            <div class="rt-preview"><?= h(mb_substr($rt['texte'], 0, 120)) ?><?= mb_strlen($rt['texte']) > 120 ? '…' : '' ?></div>
                            <div style="display:flex;gap:6px;flex-shrink:0">
                                <button class="btn btn-primary btn-sm" onclick="openModalRt(<?= $rtId ?>)">✏️</button>
                                <form method="post" onsubmit="return confirm('Supprimer cette réponse ?')" style="display:inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="table" value="rh_entretien_reponses_types">
                                    <input type="hidden" name="id" value="<?= $rtId ?>">
                                    <input type="hidden" name="tab_return" value="reponses_types">
                                    <button class="btn btn-danger btn-sm" type="submit">🗑</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                        <button class="btn-add" style="margin-top:8px" onclick="openModalRtNew(<?= $rubId ?>,<?= $cId ?>)">+ Ajouter une réponse</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- ======================================================
             TAB 4 — PHRASES MANAGER
        ====================================================== -->
        <div id="tab-phrases" class="tab-pane" style="<?= $tab !== 'phrases' ? 'display:none' : '' ?>">

            <!-- Catégorie tabs -->
            <div class="cat-tabs">
                <?php foreach ($categoriesPhrases as $cat): ?>
                <button class="cat-tab <?= $cat === 'ouverture' ? 'active' : '' ?>"
                        onclick="switchCat('<?= $cat ?>')">
                    <?= ucfirst(h($cat)) ?>
                    <?php $cnt = count($phrasesByCat[$cat] ?? []); if ($cnt > 0): ?>
                    <span class="badge-count"><?= $cnt ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($categoriesPhrases as $cat): ?>
            <div id="cat-<?= $cat ?>" class="cat-pane" style="<?= $cat !== 'ouverture' ? 'display:none' : '' ?>">
                <?php $catPhrases = $phrasesByCat[$cat] ?? []; ?>
                <?php if (empty($catPhrases)): ?>
                    <div class="empty-state">Aucune phrase dans cette catégorie.</div>
                <?php else: foreach ($catPhrases as $ph):
                    $phId = (int)$ph['id'];
                ?>
                <div class="phrase-card">
                    <div style="min-width:30px;font-size:11px;color:var(--muted);padding-top:2px">#<?= h($ph['ordre']) ?></div>
                    <div class="phrase-text"><?= h($ph['texte']) ?></div>
                    <div style="display:flex;gap:6px;flex-shrink:0">
                        <button class="btn btn-primary btn-sm" onclick="openModalPhrase(<?= $phId ?>)">✏️</button>
                        <form method="post" onsubmit="return confirm('Supprimer cette phrase ?')" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="table" value="rh_entretien_phrases_manager">
                            <input type="hidden" name="id" value="<?= $phId ?>">
                            <input type="hidden" name="tab_return" value="phrases">
                            <button class="btn btn-danger btn-sm" type="submit">🗑</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                <button class="btn-add" onclick="openModalPhraseNew('<?= $cat ?>')">+ Ajouter une phrase</button>
            </div>
            <?php endforeach; ?>
        </div>




<!-- ============================================================
     MODAL RUBRIQUE
============================================================ -->
<div class="modal-overlay" id="modalRubrique">
    <div class="modal-box">
        <h3 id="modalRubTitre">Rubrique</h3>
        <form method="post" id="formRubrique">
            <input type="hidden" name="action" value="save_rubrique">
            <input type="hidden" name="id" id="rubId" value="0">
            <input type="hidden" name="tab_return" value="rubriques">
            <div class="form-row">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom" id="rubNom" required placeholder="ex: Performance">
                </div>
                <div class="form-group">
                    <label>Code *</label>
                    <input type="text" name="code" id="rubCode" required placeholder="ex: performance">
                </div>
            </div>
            <div class="form-group" style="max-width:120px">
                <label>Ordre</label>
                <input type="number" name="ordre" id="rubOrdre" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Visibilité</label>
                <div class="form-checks">
                    <label class="form-check"><input type="checkbox" name="manager_only" id="rubMgr"> Manager uniquement</label>
                    <label class="form-check"><input type="checkbox" name="visible_collaborateur" id="rubVc" checked> Visible collaborateur</label>
                    <label class="form-check"><input type="checkbox" name="visible_pdf" id="rubVpdf" checked> Visible PDF</label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modalRubrique')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL CRITÈRE
============================================================ -->
<div class="modal-overlay" id="modalCritere">
    <div class="modal-box">
        <h3 id="modalCritTitre">Critère</h3>
        <form method="post" id="formCritere">
            <input type="hidden" name="action" value="save_critere">
            <input type="hidden" name="id" id="critId" value="0">
            <input type="hidden" name="tab_return" value="criteres">
            <div class="form-row">
                <div class="form-group">
                    <label>Rubrique *</label>
                    <select name="rubrique_id" id="critRubId" required>
                        <option value="">-- Rubrique --</option>
                        <?php foreach ($rubriques as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= h($r['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type champ</label>
                    <select name="type_champ" id="critType">
                        <?php foreach ($typesChamp as $tc): ?>
                        <option value="<?= h($tc) ?>"><?= h($tc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Label *</label>
                    <input type="text" name="label" id="critLabel" required placeholder="ex: Maîtrise du poste">
                </div>
                <div class="form-group">
                    <label>Code *</label>
                    <input type="text" name="code" id="critCode" required placeholder="ex: maitrise_poste">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Axe radar</label>
                    <select name="axe_radar" id="critAxe">
                        <option value="">-- Aucun --</option>
                        <?php foreach ($axesOptions as $ax): ?>
                        <option value="<?= h($ax) ?>"><?= h($ax) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ordre</label>
                    <input type="number" name="ordre" id="critOrdre" value="0" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Poids score</label>
                    <input type="number" name="poids_score" id="critPoids" value="1.0" step="0.1" min="0">
                </div>
                <div class="form-group">
                    <label>Seuil alerte</label>
                    <input type="number" name="seuil_alerte" id="critSeuil" value="2" min="1" max="5">
                </div>
            </div>
            <div class="form-group">
                <label>Visibilité</label>
                <div class="form-checks">
                    <label class="form-check"><input type="checkbox" name="visible_manager" id="critVm" checked> Manager</label>
                    <label class="form-check"><input type="checkbox" name="visible_collaborateur" id="critVc" checked> Collaborateur</label>
                    <label class="form-check"><input type="checkbox" name="visible_pdf" id="critVpdf" checked> PDF</label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modalCritere')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL RÉPONSE TYPE
============================================================ -->
<div class="modal-overlay" id="modalRt">
    <div class="modal-box">
        <h3 id="modalRtTitre">Réponse type</h3>
        <form method="post" id="formRt">
            <input type="hidden" name="action" value="save_rt">
            <input type="hidden" name="id" id="rtId" value="0">
            <input type="hidden" name="tab_return" value="reponses_types">
            <div class="form-row">
                <div class="form-group">
                    <label>Rubrique</label>
                    <select name="rubrique_id" id="rtRubId">
                        <option value="">-- Rubrique --</option>
                        <?php foreach ($rubriques as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= h($r['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Critère *</label>
                    <select name="critere_id" id="rtCritId" required>
                        <option value="">-- Critère --</option>
                        <?php foreach ($criteresRaw as $c): ?>
                        <option value="<?= $c['id'] ?>" data-rub="<?= $c['rubrique_id'] ?>"><?= h($c['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Note cible (1-5)</label>
                    <select name="note_cible" id="rtNote">
                        <option value="">-- Toutes --</option>
                        <?php for ($n = 1; $n <= 5; $n++): ?>
                        <option value="<?= $n ?>"><?= $n ?> étoile<?= $n > 1 ? 's' : '' ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tonalité</label>
                    <select name="tonalite" id="rtTon">
                        <?php foreach ($tonalites as $ton): ?>
                        <option value="<?= h($ton) ?>"><?= h($ton) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Texte *</label>
                <textarea name="texte" id="rtTexte" required placeholder="Texte de la réponse type..."></textarea>
            </div>
            <div class="form-group" style="max-width:120px">
                <label>Ordre</label>
                <input type="number" name="ordre" id="rtOrdre" value="0" min="0">
            </div>
            <div class="form-group">
                <label>Visibilité</label>
                <div class="form-checks">
                    <label class="form-check"><input type="checkbox" name="visible_manager" id="rtVm" checked> Manager</label>
                    <label class="form-check"><input type="checkbox" name="visible_collaborateur" id="rtVc" checked> Collaborateur</label>
                    <label class="form-check"><input type="checkbox" name="visible_pdf" id="rtVpdf" checked> PDF</label>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modalRt')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL PHRASE
============================================================ -->
<div class="modal-overlay" id="modalPhrase">
    <div class="modal-box">
        <h3 id="modalPhraseTitre">Phrase manager</h3>
        <form method="post" id="formPhrase">
            <input type="hidden" name="action" value="save_phrase">
            <input type="hidden" name="id" id="phraseId" value="0">
            <input type="hidden" name="tab_return" value="phrases">
            <div class="form-group">
                <label>Catégorie</label>
                <select name="categorie" id="phraseCat">
                    <?php foreach ($categoriesPhrases as $cat): ?>
                    <option value="<?= h($cat) ?>"><?= ucfirst(h($cat)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Texte *</label>
                <textarea name="texte" id="phraseTexte" required placeholder="Texte de la phrase suggérée..."></textarea>
            </div>
            <div class="form-group" style="max-width:120px">
                <label>Ordre</label>
                <input type="number" name="ordre" id="phraseOrdre" value="0" min="0">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modalPhrase')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODAL QUESTION
============================================================ -->
<div class="modal-overlay" id="modalQuestion">
    <div class="modal-box">
        <h3 id="modalQTitre">Question</h3>
        <form method="post" id="formQuestion">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="id" id="qId" value="0">
            <input type="hidden" name="tab_return" value="rubriques">
            <div class="form-group">
                <label>Rubrique *</label>
                <select name="rubrique_id" id="qRubId" required>
                    <option value="">-- Rubrique --</option>
                    <?php foreach ($rubriques as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= h($r['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Libellé *</label>
                <input type="text" name="label" id="qLabel" required placeholder="ex: Satisfaction globale au poste">
            </div>
            <div class="form-group">
                <label>Texte de la question</label>
                <textarea name="question_text" id="qText" placeholder="Comment évaluez-vous…"></textarea>
            </div>
            <div class="form-group">
                <label>Description / consigne</label>
                <textarea name="description" id="qDesc" placeholder="Aide à l'animateur…"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Type champ</label>
                    <select name="type_champ" id="qType">
                        <?php foreach ($typesChamp as $tc): ?>
                        <option value="<?= h($tc) ?>"><?= h($tc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ordre</label>
                    <input type="number" name="ordre" id="qOrdre" value="0" min="0">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeModal('modalQuestion')">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<?php
$layout_content = ob_get_clean();

$csrfToken_json = json_encode($csrfToken);
$rubriques_json = json_encode(array_values($rubriques));
$criteres_json  = json_encode(array_values($criteresRaw ?? []));
$questions_json = json_encode(array_values($questionsRaw ?? []));
$rt_json        = json_encode(array_values($rtRaw ?? []));
$phrases_json   = json_encode(array_values($phrasesRaw ?? []));

$layout_extra_js = <<<EXTRAJS
<script>
const CSRF = {$csrfToken_json};

// Data for modal pre-fill
const RUBRIQUES = {$rubriques_json};
const CRITERES  = {$criteres_json};
const QUESTIONS = {$questions_json};
const RT_DATA   = {$rt_json};
const PHRASES   = {$phrases_json};

// ── Tab switching ──────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.cfg-tab').forEach(b => b.classList.remove('active'));
    const pane = document.getElementById('tab-' + name);
    if (pane) pane.style.display = '';
    document.querySelectorAll('.cfg-tab').forEach(b => {
        if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + name + "'")) b.classList.add('active');
    });
    // Update URL without reload
    const url = new URL(location.href);
    url.searchParams.set('tab', name);
    history.replaceState(null, '', url.toString());
}

// ── Category switching (Tab 4) ─────────────────────────────────
function switchCat(cat) {
    document.querySelectorAll('.cat-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
    const pane = document.getElementById('cat-' + cat);
    if (pane) pane.style.display = '';
    document.querySelectorAll('.cat-tab').forEach(b => {
        if (b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + cat + "'")) b.classList.add('active');
    });
}

// ── Accordion (Tab 1) ──────────────────────────────────────────
function toggleAccordion(rubId) {
    const body  = document.getElementById('body-' + rubId);
    const arrow = document.getElementById('arrow-' + rubId);
    if (!body) return;
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    if (arrow) arrow.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Sub-accordion (Tab 3) ──────────────────────────────────────
function toggleCritAcc(id) {
    const body = document.getElementById(id);
    if (!body) return;
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    const arrow = body.previousElementSibling.querySelector('span:last-child');
    if (arrow) arrow.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Toggle actif ───────────────────────────────────────────────
function toggleActif(checkbox, table, id) {
    const actif = checkbox.checked ? '1' : '0';
    const fd = new FormData();
    fd.append('action', 'toggle_actif');
    fd.append('table', table);
    fd.append('id', id);
    fd.append('actif', actif);
    fetch(location.pathname, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(d => {
        if (d.success) showToast(checkbox.checked ? 'Activé' : 'Désactivé', 'ok');
        else { checkbox.checked = !checkbox.checked; showToast('Erreur', 'err'); }
    })
    .catch(() => { checkbox.checked = !checkbox.checked; showToast('Erreur réseau', 'err'); });
}

// ── Modal helpers ──────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});

// ── Modal Rubrique ─────────────────────────────────────────────
function openModalRubrique(id) {
    const rub = id > 0 ? RUBRIQUES.find(r => +r.id === id) : null;
    document.getElementById('modalRubTitre').textContent = id > 0 ? '✏️ Modifier la rubrique' : '➕ Nouvelle rubrique';
    document.getElementById('rubId').value        = id > 0 ? id : 0;
    document.getElementById('rubNom').value       = rub ? rub.nom : '';
    document.getElementById('rubCode').value      = rub ? rub.code : '';
    document.getElementById('rubOrdre').value     = rub ? rub.ordre : 0;
    document.getElementById('rubMgr').checked     = rub ? !!+rub.manager_only : false;
    document.getElementById('rubVc').checked      = rub ? !!+rub.visible_collaborateur : true;
    document.getElementById('rubVpdf').checked    = rub ? !!+rub.visible_pdf : true;
    openModal('modalRubrique');
}

// ── Modal Critère ──────────────────────────────────────────────
function openModalCritere(id) {
    const c = CRITERES.find(x => +x.id === id);
    if (!c) return;
    document.getElementById('modalCritTitre').textContent = '✏️ Modifier le critère';
    document.getElementById('critId').value     = id;
    setSelect('critRubId', c.rubrique_id);
    document.getElementById('critLabel').value  = c.label || '';
    document.getElementById('critCode').value   = c.code || '';
    setSelect('critType', c.type_champ);
    setSelect('critAxe', c.axe_radar || '');
    document.getElementById('critPoids').value  = c.poids_score || 1;
    document.getElementById('critSeuil').value  = c.seuil_alerte || 2;
    document.getElementById('critOrdre').value  = c.ordre || 0;
    document.getElementById('critVm').checked   = !!+c.visible_manager;
    document.getElementById('critVc').checked   = !!+c.visible_collaborateur;
    document.getElementById('critVpdf').checked = !!+c.visible_pdf;
    openModal('modalCritere');
}

function openModalCritereNew(rubId) {
    document.getElementById('modalCritTitre').textContent = '➕ Nouveau critère';
    document.getElementById('critId').value     = 0;
    setSelect('critRubId', rubId);
    document.getElementById('critLabel').value  = '';
    document.getElementById('critCode').value   = '';
    setSelect('critType', 'etoiles_texte');
    setSelect('critAxe', '');
    document.getElementById('critPoids').value  = 1;
    document.getElementById('critSeuil').value  = 2;
    document.getElementById('critOrdre').value  = 0;
    document.getElementById('critVm').checked   = true;
    document.getElementById('critVc').checked   = true;
    document.getElementById('critVpdf').checked = true;
    openModal('modalCritere');
}

// ── Modal Réponse type ─────────────────────────────────────────
function openModalRt(id) {
    const rt = RT_DATA.find(x => +x.id === id);
    if (!rt) return;
    document.getElementById('modalRtTitre').textContent = '✏️ Modifier la réponse type';
    document.getElementById('rtId').value     = id;
    setSelect('rtRubId', rt.rubrique_id || '');
    setSelect('rtCritId', rt.critere_id);
    setSelect('rtNote', rt.note_cible || '');
    setSelect('rtTon', rt.tonalite || 'neutre');
    document.getElementById('rtTexte').value  = rt.texte || '';
    document.getElementById('rtOrdre').value  = rt.ordre || 0;
    document.getElementById('rtVm').checked   = !!+rt.visible_manager;
    document.getElementById('rtVc').checked   = !!+rt.visible_collaborateur;
    document.getElementById('rtVpdf').checked = !!+rt.visible_pdf;
    openModal('modalRt');
}

function openModalRtNew(rubId, critId) {
    document.getElementById('modalRtTitre').textContent = '➕ Nouvelle réponse type';
    document.getElementById('rtId').value     = 0;
    setSelect('rtRubId', rubId);
    setSelect('rtCritId', critId);
    setSelect('rtNote', '');
    setSelect('rtTon', 'neutre');
    document.getElementById('rtTexte').value  = '';
    document.getElementById('rtOrdre').value  = 0;
    document.getElementById('rtVm').checked   = true;
    document.getElementById('rtVc').checked   = true;
    document.getElementById('rtVpdf').checked = true;
    openModal('modalRt');
}

// ── Modal Phrase ───────────────────────────────────────────────
function openModalPhrase(id) {
    const p = PHRASES.find(x => +x.id === id);
    if (!p) return;
    document.getElementById('modalPhraseTitre').textContent = '✏️ Modifier la phrase';
    document.getElementById('phraseId').value    = id;
    setSelect('phraseCat', p.categorie);
    document.getElementById('phraseTexte').value = p.texte || '';
    document.getElementById('phraseOrdre').value = p.ordre || 0;
    openModal('modalPhrase');
}

function openModalPhraseNew(cat) {
    document.getElementById('modalPhraseTitre').textContent = '➕ Nouvelle phrase';
    document.getElementById('phraseId').value    = 0;
    setSelect('phraseCat', cat || 'ouverture');
    document.getElementById('phraseTexte').value = '';
    document.getElementById('phraseOrdre').value = 0;
    openModal('modalPhrase');
}

// ── Modal Question ─────────────────────────────────────────────
function openModalQuestion(id) {
    const q = QUESTIONS.find(x => +x.id === id);
    if (!q) return;
    document.getElementById('modalQTitre').textContent = '✏️ Modifier la question';
    document.getElementById('qId').value    = id;
    setSelect('qRubId', q.rubrique_id);
    document.getElementById('qLabel').value = q.label || '';
    document.getElementById('qText').value  = q.question_text || '';
    document.getElementById('qDesc').value  = q.description || '';
    setSelect('qType', q.type_champ || 'texte');
    document.getElementById('qOrdre').value = q.ordre || 0;
    openModal('modalQuestion');
}

function openModalQuestionNew(rubId) {
    document.getElementById('modalQTitre').textContent = '➕ Nouvelle question';
    document.getElementById('qId').value    = 0;
    setSelect('qRubId', rubId);
    document.getElementById('qLabel').value = '';
    document.getElementById('qText').value  = '';
    document.getElementById('qDesc').value  = '';
    setSelect('qType', 'texte');
    document.getElementById('qOrdre').value = 0;
    openModal('modalQuestion');
}

// ── Utility ────────────────────────────────────────────────────
function setSelect(elId, val) {
    const s = document.getElementById(elId);
    if (!s) return;
    const strVal = String(val ?? '');
    for (let i = 0; i < s.options.length; i++) {
        if (s.options[i].value == strVal) { s.selectedIndex = i; return; }
    }
    s.selectedIndex = 0;
}

// ── Toast ──────────────────────────────────────────────────────
let toastTimer = null;
function showToast(msg, type = 'ok') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
}

// ── Auto-show toast on page load ───────────────────────────────
(function() {
    const p = new URLSearchParams(location.search);
    const m = p.get('msg');
    if (m === 'ok') showToast('Enregistrement effectué', 'ok');
    else if (m === 'deleted') showToast('Suppression effectuée', 'err');
})();
</script>
EXTRAJS;

require_once __DIR__ . '/inc/layout_maboximmo.php';
