<?php
declare(strict_types=1);
/**
 * maboxoffice.php — MABOXOFFICE · Le Bureau Documentaire (superadmin).
 * Front de tri devant FluxBox + GED. Design 3 colonnes : file | lire | décider.
 * Toute la donnée vit dans fluxbox_documents / ged_documents (aucun silo).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/maboxoffice_ocr.php';
require_once __DIR__ . '/inc/maboxoffice_match.php';
require_once __DIR__ . '/inc/ged_naming_v3.php';
if (!is_super_admin()) { http_response_code(403); exit('Accès réservé au super administrateur.'); }

$pdo  = $GLOBALS['pdo'];
$csrf = csrf_token('default');
$SELF = app_url('/maboxoffice.php');
$tenant = (int)($_SESSION['id_societe'] ?? 0);

$BACS = [
    'non_classe'  => ['📥', 'Non classé'],
    'societe'     => ['🏢', 'Société'],
    'fournisseur' => ['🚚', 'Fournisseur'],
    'client'      => ['👤', 'Client'],
    'immeuble'    => ['🏘️', 'Immeuble'],
    'rh'          => ['🧑‍💼', 'RH'],
    'personnel'   => ['🔒', 'Personnel'],
    'facture'     => ['🧾', 'Factures'],
];
$STATUTS = [
    'nouveau'    => ['#6b7280', 'À classer'],
    'a_payer'    => ['#c0492f', 'À payer'],
    'a_traiter'  => ['#1d4ed8', 'À traiter'],
    'urgent'     => ['#dc2626', 'Urgent'],
    'en_attente' => ['#1d4ed8', 'En attente'],
    'paye'       => ['#2e8b47', 'Payé'],
    'doublon'    => ['#6b7280', 'Doublon'],
    'ignore'     => ['#9ca3af', 'Ignoré'],
    'traite'     => ['#2e8b47', 'Traité'],
];

// ── Actions (POST + PRG) ─────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('default');
    $action = $_POST['action'] ?? '';
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if (!$ids && isset($_POST['id'])) $ids = [(int)$_POST['id']];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'bac' && isset($BACS[$_POST['bac'] ?? ''])) {
            $pdo->prepare("UPDATE fluxbox_documents SET mbo_bac=? WHERE id IN ($in)")->execute(array_merge([$_POST['bac']], $ids));
            // Reprise facilitée : on fige le libellé/commentaire saisis (le nom GED se
            // reconstruit à l'identique depuis l'entité liée + le libellé).
            if (isset($_POST['libelle']) || isset($_POST['commentaire'])) {
                $pdo->prepare("UPDATE fluxbox_documents SET mbo_libelle=?, mbo_commentaire=? WHERE id IN ($in)")
                    ->execute(array_merge([trim((string)($_POST['libelle'] ?? '')) ?: null, trim((string)($_POST['commentaire'] ?? '')) ?: null], $ids));
            }
            if (isset($_POST['refdate'])) {
                $rd = trim((string)$_POST['refdate']); if ($rd !== '' && preg_match('/^\d{4}-\d{2}$/', $rd)) $rd .= '-01';
                $rd = ($rd !== '' && strtotime($rd)) ? date('Y-m-d', strtotime($rd)) : null;
                $pdo->prepare("UPDATE fluxbox_documents SET mbo_ref_salaire=? WHERE id IN ($in)")->execute(array_merge([$rd], $ids));
            }
        } elseif ($action === 'statut' && isset($STATUTS[$_POST['statut'] ?? ''])) {
            $pdo->prepare("UPDATE fluxbox_documents SET mbo_statut=? WHERE id IN ($in)")->execute(array_merge([$_POST['statut']], $ids));
            // « À payer » / « En attente » → orientation automatique dans le bac Factures.
            if (in_array($_POST['statut'], ['a_payer','en_attente'], true)) {
                $pdo->prepare("UPDATE fluxbox_documents SET mbo_bac='facture' WHERE id IN ($in)")->execute($ids);
            }
        } elseif ($action === 'savemeta') {
            $pdo->prepare("UPDATE fluxbox_documents SET mbo_libelle=?, mbo_commentaire=? WHERE id IN ($in)")
                ->execute(array_merge([trim((string)($_POST['libelle'] ?? '')) ?: null, trim((string)($_POST['commentaire'] ?? '')) ?: null], $ids));
            if (isset($_POST['categorie'])) {
                $cat = preg_replace('/[^a-z_]/', '', strtolower((string)$_POST['categorie'])) ?: null;
                try { $pdo->prepare("UPDATE fluxbox_documents SET mbo_rh_categorie=? WHERE id IN ($in)")->execute(array_merge([$cat], $ids)); } catch (Throwable) {}
            }
            // Date de référence (position 9) : mois de paye OU date complète (mandat/bail/contrat…).
            if (isset($_POST['refdate'])) {
                $rd = trim((string)$_POST['refdate']); // 'YYYY-MM-DD' ou 'YYYY-MM'
                if ($rd !== '' && preg_match('/^\d{4}-\d{2}$/', $rd)) $rd .= '-01';
                $rd = ($rd !== '' && strtotime($rd)) ? date('Y-m-d', strtotime($rd)) : null;
                $pdo->prepare("UPDATE fluxbox_documents SET mbo_ref_salaire=? WHERE id IN ($in)")->execute(array_merge([$rd], $ids));
            }
        } elseif ($action === 'delete') {
            // FILET : avant d'ôter la ligne de la pile, on sauve une copie durable pour tout
            // doc GED qui en dépend encore → la suppression ne peut plus orpheliner la GED.
            require_once __DIR__ . '/inc/ged_durable.php';
            foreach ($ids as $fid) { try { ged_ensure_durable_from_fluxbox($pdo, (int)$fid); } catch (Throwable) {} }
            $pdo->prepare("DELETE FROM mbo_mail_captures WHERE fluxbox_document_id IN ($in)")->execute($ids);
            $pdo->prepare("DELETE FROM fluxbox_documents WHERE id IN ($in)")->execute($ids);
        } elseif ($action === 'dedup_keep') {
            // « Je garde ce doc » (doublon déjà en GED) : renomme l'existant si modifié,
            // et retire le doublon de la pile en le liant au doc GED existant.
            $gedId   = (int)($_POST['ged_id'] ?? 0);
            $newName = trim((string)($_POST['ged_name'] ?? ''));
            if ($gedId > 0 && $newName !== '') {
                try { $pdo->prepare("UPDATE ged_documents SET name_display=? WHERE id=?")->execute([$newName, $gedId]); } catch (Throwable) {}
            }
            $pdo->prepare("UPDATE fluxbox_documents SET mbo_statut='traite', mbo_classe_at=NOW(), mbo_ged_document_id=? WHERE id IN ($in)")
                ->execute(array_merge([$gedId ?: null], $ids));
        } elseif ($action === 'ged_delete') {
            // « Je conserve celui-ci » en vue MBO : on supprime l'ancien doc GED (+ ses liens)
            // pour le remplacer par le document de la pile (classé juste après côté JS).
            $gedId = (int)($_POST['ged_id'] ?? 0);
            if ($gedId > 0) {
                try { $pdo->prepare("DELETE FROM ged_document_links WHERE document_id=?")->execute([$gedId]); } catch (Throwable) {}
                try { $pdo->prepare("UPDATE ged_documents SET status='deleted' WHERE id=?")->execute([$gedId]); } catch (Throwable) {}
            }
        } elseif ($action === 'setentity' && in_array($_POST['entity_type'] ?? '', ['BAIL','BIEN','IMB','TIERS'], true) && (int)($_POST['entity_id'] ?? 0) > 0) {
            $pdo->prepare("UPDATE fluxbox_documents SET mbo_entity_type=?, mbo_entity_id=?, mbo_entity_label=?, mbo_entity_score=999 WHERE id IN ($in)")
                ->execute(array_merge([$_POST['entity_type'], (int)$_POST['entity_id'], trim((string)($_POST['entity_label'] ?? '')) ?: null], $ids));
        }
    }
    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit; }
    header('Location: ' . $SELF . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']));
    exit;
}

// ── Filtres ──────────────────────────────────────────────────────────────────
$histo = isset($_GET['histo']);
$fBac = $_GET['bac'] ?? '';
$fStat = $_GET['statut'] ?? '';   // filtre statut (ex. « à classer »)
$q    = trim((string)($_GET['q'] ?? ''));
$where = [$histo ? 'mbo_classe_at IS NOT NULL' : 'mbo_classe_at IS NULL']; $args = [];
if ($tenant > 0) { $where[] = 'tenant_id = ?'; $args[] = $tenant; }
if (isset($BACS[$fBac]))   { $where[] = 'mbo_bac = ?'; $args[] = $fBac; }
if ($fStat === 'a_classer') { $where[] = "mbo_statut = 'nouveau'"; }   // « À classer » = nouveau
if ($q !== '')             { $where[] = 'fichier_nom LIKE ?'; $args[] = '%' . $q . '%'; }
$sqlWhere = 'WHERE ' . implode(' AND ', $where);
$sort = (($_GET['sort'] ?? '') === 'asc') ? 'ASC' : 'DESC';       // tri par date
// Dans le bac Factures : à payer (rouge) d'abord, puis en attente (bleu).
$order = $histo ? "mbo_classe_at $sort, id $sort"
       : ($fBac === 'facture' ? "FIELD(mbo_statut,'a_payer','en_attente'), id $sort" : "COALESCE(first_seen_at, created_at) $sort, id $sort");

$docs = $pdo->prepare("SELECT f.id, f.tenant_id, f.fichier_nom, f.mime_type, f.taille_octets, f.source_type,
                              f.mbo_bac, f.mbo_statut, f.mbo_type_propose, f.mbo_metier, f.mbo_date_doc, f.mbo_ref_salaire, f.mbo_rh_categorie, f.mbo_societe_id, f.mbo_agence_id,
                              f.mbo_entity_type, f.mbo_entity_id, f.mbo_entity_label,
                              f.mbo_libelle, f.mbo_commentaire, SUBSTRING(f.ocr_text,1,240) AS ocr_excerpt,
                              f.mbo_ia_analyse, f.mbo_ia_action_type, f.mbo_ia_action_label,
                              f.mbo_classe_at, f.mbo_ged_document_id,
                              f.first_seen_at, f.created_at,
                              (SELECT COUNT(*) FROM fluxbox_documents x WHERE x.id<>f.id
                                 AND ((f.hash_sha256<>'' AND x.hash_sha256=f.hash_sha256) OR x.fichier_nom=f.fichier_nom)) AS dup_cnt,
                              (SELECT COUNT(*) FROM ged_documents g WHERE f.hash_sha256<>'' AND g.hash_sha256=f.hash_sha256
                                 AND g.tenant_id=f.tenant_id AND g.status IN ('active','archived')) AS ged_dup,
                              (SELECT g.id FROM ged_documents g WHERE f.hash_sha256<>'' AND g.hash_sha256=f.hash_sha256
                                 AND g.tenant_id=f.tenant_id AND g.status IN ('active','archived') ORDER BY g.id ASC LIMIT 1) AS ged_dup_id,
                              (SELECT g.name_display FROM ged_documents g WHERE f.hash_sha256<>'' AND g.hash_sha256=f.hash_sha256
                                 AND g.tenant_id=f.tenant_id AND g.status IN ('active','archived') ORDER BY g.id ASC LIMIT 1) AS ged_dup_name
                       FROM fluxbox_documents f " . str_replace(['mbo_classe_at','tenant_id','mbo_bac','fichier_nom'], ['f.mbo_classe_at','f.tenant_id','f.mbo_bac','f.fichier_nom'], $sqlWhere) . "
                       ORDER BY $order LIMIT 300");
$docs->execute($args);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);

$tenantCond = $tenant > 0 ? 'tenant_id = ' . $tenant . ' AND ' : '';
$scope = 'WHERE ' . $tenantCond . ($histo ? 'mbo_classe_at IS NOT NULL' : 'mbo_classe_at IS NULL');
$counts    = $pdo->query("SELECT mbo_statut, COUNT(*) n FROM fluxbox_documents $scope GROUP BY mbo_statut")->fetchAll(PDO::FETCH_KEY_PAIR);
$bacCounts = $pdo->query("SELECT mbo_bac, COUNT(*) n FROM fluxbox_documents $scope GROUP BY mbo_bac")->fetchAll(PDO::FETCH_KEY_PAIR);
$aAnalyser = (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_documents WHERE {$tenantCond}mbo_classe_at IS NULL AND mbo_ocr_at IS NULL")->fetchColumn();
$kHisto    = (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_documents WHERE {$tenantCond}mbo_classe_at IS NOT NULL")->fetchColumn();
$kFactures = (int)$pdo->query("SELECT COUNT(*) FROM fluxbox_documents WHERE {$tenantCond}mbo_classe_at IS NULL AND mbo_statut IN ('a_payer','en_attente')")->fetchColumn();
$mailboxes = [];
try { $mailboxes = $pdo->query("SELECT id, label, email, last_sync_at FROM mbo_mailboxes WHERE actif=1 ORDER BY label, email")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
$kNouveau = (int)($counts['nouveau'] ?? 0);
$kPayer   = (int)($counts['a_payer'] ?? 0);
$kTraite  = (int)($counts['traite'] ?? 0);
$kTotal   = array_sum(array_map('intval', $counts));
$totalDocs = count($docs);
$fmtSize = function ($o) { $o = (int)$o; if ($o < 1024) return $o . ' o'; if ($o < 1048576) return round($o/1024) . ' Ko'; return round($o/1048576, 1) . ' Mo'; };

// Tags couleur par type
$typeTagClass = function (string $t): string {
    if (in_array($t, ['note_frais'], true)) return 'frais';
    if (in_array($t, ['facture','devis','relance'], true)) return 'four';
    if (in_array($t, ['avis_echeance','quittance'], true)) return 'avis';
    if ($t === '' || $t === 'autre') return 'aclasser';
    return 'just';
};

$pageTitle    = '🗂️ MaBoxOffice';
$pageSubtitle = 'Le Bureau Documentaire';
$extraCss = <<<'CSS'
<style>
  .mbo{ --ink:#1e2b3d; --muted:#6b7890; --line:#e3e8ef; --navy:#22344d; --navy-d:#18263a; --gold:#c2a24d;
    --amber:#c9821b; --amber-bg:#fdf3e3; --red:#c0492f; --red-bg:#fbecea; --green:#2e8b47; --green-bg:#e9f5ec; --blue-bg:#eaf1fb;
    --radius:10px; --shadow:0 1px 3px rgba(30,43,61,.08),0 1px 2px rgba(30,43,61,.05); color:var(--ink); font-size:14px; }
  .mbo *{box-sizing:border-box}
  .mbo .topbar{display:flex;align-items:center;gap:12px;background:var(--navy);color:#fff;border-radius:var(--radius);padding:11px 16px;border-top:3px solid var(--gold);flex-wrap:wrap}
  .mbo .sync{flex:1;display:flex;align-items:center;gap:8px;min-width:280px}
  .mbo .sync input{flex:1;max-width:420px;padding:9px 12px;border-radius:8px;border:1px solid #3a4d68;background:#fff;color:var(--ink);font-size:13px}
  .mbo .btn{border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
  .mbo .btn-light{background:#fff;color:var(--navy)} .mbo .btn-ghost-w{background:transparent;color:#dbe4f0;border:1px solid #43587a}
  .mbo .counters{display:flex;gap:12px;margin:10px 0}
  .mbo .stat{flex:1;background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:8px 16px;box-shadow:var(--shadow);display:flex;align-items:baseline;gap:8px}
  .mbo .stat b{font-size:20px;line-height:1}.mbo .stat span{font-size:11px;letter-spacing:1px;color:var(--muted);text-transform:uppercase}
  .mbo .stat.pay b{color:var(--amber)} .mbo .stat.done b{color:var(--green)}
  .mbo .grid{display:grid;grid-template-columns:300px minmax(0,1fr) 320px;gap:12px;align-items:start}
  .mbo .panel{background:#fff;border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
  .mbo .col-list{display:flex;flex-direction:column;max-height:80vh;background:#f4f1fb;border-color:#e3daf5}
  .mbo .col-view{background:#f3f4f6;min-width:0}
  .mbo .col-act .card{background:#e8f2f3;border-color:#cfe6e7}
  .mbo .col-list .list-head{background:#efe9fa}
  .mbo .col-act .card h4{color:#2b6a70}
  .mbo .list-head{padding:12px;border-bottom:1px solid var(--line)}
  .mbo .search{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px}
  .mbo .filters{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:10px}
  .mbo .chip{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:20px;padding:6px 12px;font-size:12px;cursor:pointer;font-weight:600;text-decoration:none;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .mbo .chip .n{color:#9aa6b8;font-weight:700;margin-left:4px}
  .mbo .chip.active{background:var(--navy);color:#fff;border-color:var(--navy)} .mbo .chip.active .n{color:#c3d2e8}
  .mbo .chip-all{background:#fbf3e0;border-color:var(--gold);color:#8a6d1e;box-shadow:0 2px 0 #c2a24d,0 3px 5px rgba(194,162,77,.35);transition:transform .08s,box-shadow .12s}
  .mbo .chip-all .n{color:#b8912f}
  .mbo .chip-all:hover{transform:translateY(-1px);box-shadow:0 3px 0 #c2a24d,0 5px 8px rgba(194,162,77,.4)}
  .mbo .chip-all:active{transform:translateY(1px);box-shadow:0 1px 0 #c2a24d}
  .mbo .chip-all.active{background:var(--gold);border-color:var(--gold);color:#3a2e05} .mbo .chip-all.active .n{color:#5c4a10}
  .mbo .compta-bar{padding:10px 12px;border-bottom:1px solid var(--line);background:#fdf1ef}
  .mbo .cb-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--red);margin-bottom:8px;display:flex;align-items:center;gap:6px}
  .mbo .cb-title button{border:none;background:transparent;cursor:pointer;font-size:13px}
  .mbo .cb-btns{display:flex;flex-direction:column;gap:6px}
  .mbo .cb-send{display:block;width:100%;text-align:left;border:1px solid #e7c6bf;background:#fff;color:var(--red);border-radius:8px;padding:8px 10px;font-size:12.5px;font-weight:600;cursor:pointer}
  .mbo .cb-send:hover{background:#fff6f4}
  .mbo .cb-send small{display:block;color:#9aa6b8;font-weight:400}
  .mbo .list{overflow:auto;padding:8px}
  .mbo .item{display:flex;gap:10px;padding:10px;border-radius:8px;cursor:pointer;align-items:flex-start;border:1px solid transparent}
  .mbo .item:hover{background:#f6f8fb} .mbo .item.active{background:var(--blue-bg);border-color:#bcd3f2}
  .mbo .item input{margin-top:3px}
  .mbo .pick-col{display:flex;flex-direction:column;align-items:center;gap:5px;flex-shrink:0}
  /* Ligne toggle GED/MBO — pleine largeur de la card, sous l'en-tête (bouton aligné à gauche) */
  #dupBar{display:flex;gap:8px;align-items:stretch;margin:10px 16px 4px;padding-top:10px;border-top:1px dashed #e3e8ef}
  #dupBar #btnKeepDup{flex:1.6 1 0;background:#2e9e57;color:#fff;border:none;border-radius:10px;padding:10px 12px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap}
  #dupBar #btnKeepDup:hover{background:#268a4b}
  .mbo .dup-toggle{flex:1 1 0;display:flex;border:2px solid #cbd5e1;border-radius:10px;overflow:hidden;background:#f1f5f9}
  .mbo .dup-toggle .dt-opt{flex:1;display:flex;align-items:center;justify-content:center;padding:8px 4px;font-size:12.5px;font-weight:800;cursor:pointer;color:#64748b;white-space:nowrap;transition:all .12s}
  .mbo .dup-toggle .dt-opt.on[data-view="mbo"]{background:#7c3aed;color:#fff}
  .mbo .dup-toggle .dt-opt.on[data-view="ged"]{background:#2e9e57;color:#fff}
  /* Vue GED (toggle) : la barre du nom passe verte clignotante + nom éditable */
  .mbo .gedbanner.ged-dup{border-color:#2e9e57;color:#166534;background:#eefdf3;animation:gedDupBlink 1.1s ease-in-out infinite}
  @keyframes gedDupBlink{0%,100%{box-shadow:0 0 0 0 rgba(46,158,87,0);border-color:#2e9e57}50%{box-shadow:0 0 0 4px rgba(46,158,87,.28);border-color:#37c06b}}
  @media(prefers-reduced-motion:reduce){.mbo .gedbanner.ged-dup{animation:none}}
  .mbo .gedbanner .gedname-edit{flex:1;min-width:0;border:1.5px solid #37c06b;background:#fff;border-radius:8px;padding:7px 10px;font-family:Consolas,Monaco,monospace;font-size:14px;font-weight:700;color:#166534;text-align:left;outline:none}
  .mbo .gedbanner .gedname-edit:focus{border-color:#2e9e57;box-shadow:0 0 0 3px rgba(46,158,87,.18)}
  /* Bouton « Je garde ce doc » : pleine largeur sous les 4 boutons d'action */
  #btnKeepDup{background:#2e9e57;color:#fff;border:none;border-radius:10px;padding:11px 16px;font-size:14px;font-weight:800;cursor:pointer;margin-top:2px}
  #btnKeepDup:hover{background:#268a4b}
  .mbo .dupdot{width:11px;height:11px;border-radius:50%;box-shadow:0 0 0 2px #fff}
  .mbo .dupdot.ok{background:var(--green)} .mbo .dupdot.dup{background:var(--red)}
  .mbo .dupdot.dup.blink{animation:dotBlink 1s ease-in-out infinite}
  @keyframes dotBlink{0%,100%{box-shadow:0 0 0 2px #fff,0 0 0 3px rgba(220,53,69,.0)}50%{box-shadow:0 0 0 2px #fff,0 0 0 5px rgba(220,53,69,.55)}}
  @media(prefers-reduced-motion:reduce){.mbo .dupdot.dup.blink{animation:none}}
  .mbo .chip-sort{background:#eef2f8;border-color:#c7d3e3;color:#3a4a63;font-family:inherit}
  .mbo .filters-ico{grid-column:1/-1;display:flex;gap:6px}
  .mbo .chip-ico{flex:1;padding:7px 6px;font-size:16px;line-height:1;display:flex;align-items:center;justify-content:center}
  .mbo .thumb{width:34px;height:42px;border-radius:4px;background:#f0f3f8;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
  .mbo .item .meta{min-width:0} .mbo .item .name{font-weight:600;font-size:13px;line-height:1.25;word-break:break-word}
  .mbo .item .sub{color:var(--muted);font-size:11px;margin-top:3px}
  .mbo .tag{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;margin-top:5px;letter-spacing:.3px}
  .mbo .tag.frais{background:var(--amber-bg);color:var(--amber)} .mbo .tag.just{background:var(--blue-bg);color:#2a5da8}
  .mbo .tag.avis{background:#f2eafb;color:#7a3fb0} .mbo .tag.four{background:#e9f5ec;color:#2e8b47} .mbo .tag.aclasser{background:#eef1f6;color:var(--muted)}
  .mbo .badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px} .mbo .badge.new{background:var(--green-bg);color:var(--green)}
  .mbo .badge.ign{background:#eef1f6;color:#9aa6b8} .mbo .badge.pay{background:var(--red-bg);color:var(--red)} .mbo .badge.wait{background:#eaf1fb;color:#1d4ed8} .mbo .badge.done{background:var(--green-bg);color:var(--green)} .mbo .badge.urg{background:#fdeceb;color:#dc2626}
  /* Sélecteur de source Téléchargements ⇄ Mails */
  .mbo .srcsel{display:inline-flex;gap:4px;background:#eef1f6;border:1px solid var(--line);border-radius:12px;padding:4px;margin-bottom:12px}
  .mbo .src-tab{border:none;background:transparent;color:var(--muted);font-size:14px;font-weight:700;padding:9px 18px;border-radius:9px;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
  .mbo .src-tab.active{background:var(--navy);color:#fff;box-shadow:0 2px 8px rgba(36,59,92,.25)}
  .mbo .src-n{font-size:11px;font-weight:800;background:var(--gold);color:#3a2e05;border-radius:99px;padding:1px 7px}
  /* Espace Téléchargements */
  .mbo .dlws{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px}
  .mbo .dl-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
  .mbo .dl-title{font-size:17px;font-weight:800;color:var(--navy)}
  .mbo .dl-sub{font-size:12.5px;color:var(--muted);max-width:70ch;margin-top:4px}
  .mbo .dl-sub b{color:var(--navy)}
  .mbo .dl-actions{display:flex;gap:8px;flex-shrink:0}
  .mbo .dl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
  .mbo .dl-empty{grid-column:1/-1;padding:30px;text-align:center;color:var(--muted);font-size:13px}
  .mbo .dl-card{border:1px solid var(--line);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;background:#fff}
  .mbo .dl-prev{position:relative;height:170px;background:#f4f6f9;display:flex;align-items:center;justify-content:center;overflow:hidden}
  .mbo .dl-prev img{width:100%;height:100%;object-fit:cover}
  .mbo .dl-prev iframe.dl-pdf{width:100%;height:100%;border:0;pointer-events:none;background:#fff}
  .mbo .dl-prev .dl-ic{font-size:40px}
  .mbo .dl-zoom{position:absolute;top:6px;right:6px;width:28px;height:28px;border:none;border-radius:8px;background:rgba(36,59,92,.82);color:#fff;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.25)}
  .mbo .dl-zoom:hover{background:var(--navy)}
  .mbo .dl-body{padding:10px 12px;display:flex;flex-direction:column;gap:8px}
  .mbo .dl-nm{font-size:12.5px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .mbo .dl-meta{font-size:10.5px;color:var(--muted)}
  .mbo .dl-orient{display:grid;grid-template-columns:1fr 1fr;gap:4px}
  .mbo .dl-ob{border:1px solid var(--line);background:#fff;border-radius:7px;padding:5px 4px;font-size:11px;font-weight:700;color:var(--muted);cursor:pointer}
  .mbo .dl-ob.on{border-width:1.5px}
  .mbo .dl-ob[data-s="nouveau"].on{border-color:#6b7280;color:#374151;background:#f1f3f6}
  .mbo .dl-ob[data-s="a_payer"].on{border-color:var(--red);color:var(--red);background:var(--red-bg)}
  .mbo .dl-ob[data-s="a_traiter"].on{border-color:#1d4ed8;color:#1d4ed8;background:#eaf1fb}
  .mbo .dl-ob[data-s="urgent"].on{border-color:#dc2626;color:#dc2626;background:#fdeceb}
  .mbo .dl-bacs{display:grid;grid-template-columns:repeat(6,1fr);gap:3px}
  .mbo .dl-bb{border:1px solid var(--line);background:#fff;border-radius:6px;padding:5px 0;font-size:14px;cursor:pointer;line-height:1}
  .mbo .dl-bb:hover{border-color:#c7d3e3;background:#f6f8fb}
  .mbo .dl-bb.on{background:var(--navy);border-color:var(--navy);box-shadow:0 2px 6px rgba(36,59,92,.3)}
  .mbo .dl-cardact{display:flex;gap:6px}
  .mbo .dl-cardact button{flex:1;border-radius:8px;padding:7px 6px;font-size:12px;font-weight:800;cursor:pointer;border:1px solid transparent}
  .mbo .dl-classer{background:var(--navy);color:#fff}
  .mbo .dl-del{background:#fff;border-color:#f0d4d1;color:#b3261e}
  .mbo .dl-card.done{opacity:.5;pointer-events:none}
  .mbo .row-top{display:flex;justify-content:space-between;gap:6px;align-items:flex-start}
  .mbo .col-view{display:flex;flex-direction:column;max-height:80vh}
  .mbo .view-head{padding:12px 14px;border-bottom:1px solid var(--line)}
  .mbo .view-title{display:flex;align-items:center;gap:10px;min-width:0}
  .mbo .view-title h2{font-size:11px;font-weight:600;color:#6b7890;margin:0;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .mbo .view-title .tag{flex-shrink:0}
  .mbo .crumb{text-align:center;color:#14532d;font-size:13px;margin-top:6px;line-height:1.5;font-weight:600}.mbo .crumb b{color:#14532d;font-weight:700}
  .mbo .crumb a{color:#14532d;text-decoration:none;font-weight:700}
  .mbo .gedname{font-family:Consolas,Monaco,monospace;font-size:11px;color:#0f766e;font-weight:700;margin-top:6px;white-space:nowrap;overflow-x:auto}
  .mbo .attach{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;align-items:center}.mbo .attach .lbl{font-size:11px;color:var(--muted)}
  .mbo .achip{font-size:11px;background:#f4f6fa;border:1px solid var(--line);border-radius:6px;padding:3px 8px;color:#3a4a63;cursor:pointer}
  .mbo .achip.cur{background:var(--navy);color:#fff;border-color:var(--navy)}
  .mbo .msgbody{font-size:12px;color:#475569;margin-top:8px;max-height:120px;overflow:auto;display:none}
  .mbo .viewer{flex:1;overflow:hidden;background:#eef1f5;min-height:420px;padding:8px;border-radius:0 0 12px 12px}
  .mbo .viewer iframe,.mbo .viewer img{width:100%;height:71vh;border:0;background:#fff;object-fit:contain;display:block;border-radius:10px;box-shadow:0 1px 4px rgba(30,43,61,.12)}
  .mbo .viewer .empty{height:71vh;display:flex;align-items:center;justify-content:center;color:var(--muted)}
  .mbo .col-act{display:flex;flex-direction:column;gap:12px;position:sticky;top:12px}
  .mbo .card{padding:13px}.mbo .card h4{margin:0 0 10px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
  .mbo .gedbanner-row{display:flex;gap:8px;align-items:stretch;margin:0 0 12px}
  .mbo #btnGedLink{flex-shrink:0;border:2px solid #7c3aed;background:#7c3aed;color:#fff;font-weight:800;font-size:13px;border-radius:10px;padding:0 16px;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:6px}
  .mbo #btnGedLink:hover{background:#6b2fd6}
  .mbo .gedbanner{flex:1;text-align:center;font-family:Consolas,Monaco,monospace;font-size:16px;font-weight:800;color:#4c1d95;padding:9px 16px;background:#f5f1fc;border:2px solid #7c3aed;border-radius:10px;overflow-x:auto;white-space:nowrap;min-height:42px;display:flex;align-items:center;justify-content:center}
  .mbo .gedbanner:empty::before{content:'— nom GED —';color:#b9a9dd;font-weight:600}
  .mbo .gedbanner{gap:2px}
  .mbo .gedbanner .gseg{cursor:pointer;display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:8px;background:#efe9fb;border:1px solid #ddd0f5;transition:all .1s;font-family:-apple-system,'Segoe UI',system-ui,sans-serif;font-size:12.5px;line-height:1.2}
  .mbo .gedbanner .gseg:hover{background:#e0d0fa;border-color:#b9a0e8}
  .mbo .gedbanner .gseg .gi{font-size:13px}
  .mbo .gedbanner .gseg .gv{font-weight:700;color:#4c1d95}
  .mbo .gedbanner .gsep{opacity:.3;font-weight:400}
  .mbo .gedbanner .gext{opacity:.5;font-size:11px;margin-left:2px}
  /* Vue GED (vert) : pills vertes */
  .mbo .gedbanner.ged-dup .gseg{background:#e6f7ec;border-color:#bfe6cd}
  .mbo .gedbanner.ged-dup .gseg:hover{background:#d6f0df}
  .mbo .gedbanner.ged-dup .gseg .gv{color:#166534}
  .mbo .linker{position:relative;margin-top:8px;max-width:520px}
  .mbo .linker input{width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px}
  .mbo .linker input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px #f0e4c2}
  .mbo .ent-results{display:flex;flex-direction:column;gap:4px;margin-top:6px}
  .mbo .ent-results:empty{display:none}
  .mbo .ent-item{display:block;width:100%;text-align:left;border:1px solid var(--line);background:#fff;border-radius:8px;padding:7px 10px;font-size:12.5px;cursor:pointer}
  .mbo .ent-item:hover{background:var(--blue-bg);border-color:#bcd3f2}
  .mbo .ent-item .bdg{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#2a5da8;background:var(--blue-bg);border-radius:5px;padding:1px 6px;margin-right:6px}
  .mbo .ent-item .rep{color:var(--muted);font-size:11px;margin-left:4px}
  .mbo .head-body{display:flex;gap:14px;align-items:flex-start;margin-top:6px}
  .mbo .head-left{flex:1;min-width:0}
  .mbo .valid-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;flex-shrink:0;width:250px}
  .mbo .vb{border:1px solid var(--line);border-radius:9px;padding:10px 8px;font-size:12.5px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;background:#fff;transition:transform .07s,box-shadow .15s;white-space:nowrap}
  .mbo .vb:hover{transform:translateY(-1px)} .mbo .vb:active{transform:translateY(0)} .mbo .vb:disabled{opacity:.55;cursor:default;transform:none}
  .mbo .vb.gold{background:linear-gradient(135deg,#ecd27e,#c2a24d);color:#3a2e05;border-color:#c2a24d;box-shadow:0 3px 10px rgba(194,162,77,.4),inset 0 1px 0 rgba(255,255,255,.5)}
  .mbo .vb.gold:hover{box-shadow:0 6px 16px rgba(194,162,77,.55)}
  .mbo .vb.red{color:var(--red);border-color:#e7c6bf}.mbo .vb.red:hover{background:var(--red-bg)}
  .mbo .vb.blue{color:#1d4ed8;border-color:#c3d6f5}.mbo .vb.blue:hover{background:var(--blue-bg)}
  .mbo .vb.dark{color:#fff;background:#5b3fb0;border-color:#5b3fb0}.mbo .vb.dark:hover{background:#4c3396}
  @media(max-width:1300px){.mbo .head-body{flex-wrap:wrap}.mbo .valid-grid{width:100%}}
  .mbo .bacs{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .mbo .bac{border:1px solid var(--line);background:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;cursor:pointer;text-align:left;display:flex;align-items:center;gap:7px}
  .mbo .bac:hover{border-color:#b9c6da;background:#f8fafc} .mbo .bac.suggest{border-color:var(--gold);box-shadow:0 0 0 2px #f0e4c2 inset} .mbo .bac.sel{background:var(--navy);color:#fff;border-color:var(--navy)}
  .mbo .statut{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .mbo .sbtn{border:1px solid var(--line);background:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;cursor:pointer}
  .mbo .sbtn.pay:hover{background:var(--red-bg);border-color:var(--red);color:var(--red)} .mbo .sbtn.wait:hover{background:#eaf1fb;border-color:#1d4ed8;color:#1d4ed8} .mbo .sbtn.done:hover{background:var(--green-bg);border-color:var(--green);color:var(--green)}
  .mbo .sbtn.dup-red{background:var(--red-bg);border-color:var(--red);color:var(--red)}
  .mbo .sbtn.dup-green{background:var(--green-bg);border-color:var(--green);color:var(--green)}
  .mbo .statut4{display:grid;grid-template-columns:1fr 1fr;gap:6px}
  .mbo .sbtn.classer:hover,.mbo .sbtn.classer.on{background:#f1f3f6;border-color:#6b7280;color:#374151}
  .mbo .sbtn.urgent:hover,.mbo .sbtn.urgent.on{background:#fdeceb;border-color:#dc2626;color:#dc2626}
  .mbo .sbtn.pay.on{background:var(--red-bg);border-color:var(--red);color:var(--red)}
  .mbo .sbtn.wait.on{background:#eaf1fb;border-color:#1d4ed8;color:#1d4ed8}
  .mbo .sbtn.on{font-weight:800;box-shadow:inset 0 0 0 1px currentColor}
  .mbo .fields label{display:block;font-size:11px;color:var(--muted);margin:8px 0 3px}
  .mbo .fields input,.mbo .fields select{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px}
  .mbo .actions{display:flex;flex-direction:column;gap:8px}
  .mbo .btn-primary{background:var(--navy);color:#fff;justify-content:center}.mbo .btn-primary:hover{background:var(--navy-d)}
  .mbo .btn-ghost{background:#fff;color:var(--navy);border:1px solid var(--line);justify-content:center}
  .mbo .btn-ia{background:#5b3fb0;color:#fff;justify-content:center}
  .mbo .btn-danger{background:#fff;color:var(--red);border:1px solid #e7c6bf;justify-content:center}
  .mbo .msg{font-size:12px;color:var(--muted);min-height:16px}
  @media(max-width:1100px){.mbo .grid{grid-template-columns:1fr}}
  /* Modal cascade */
  .casc-overlay{position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(20,30,45,.5);display:none;align-items:center;justify-content:center;z-index:100000}
  .casc-soc{margin-left:auto;background:#1d4ed8;color:#fff;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
  .casc-seg-chip{background:#fbf3e0;border:2px solid #c2a24d;color:#5a4611;font-size:13px;font-weight:800;padding:8px 16px;border-radius:22px;cursor:pointer;white-space:nowrap;box-shadow:0 2px 5px rgba(194,162,77,.28);transition:transform .07s,box-shadow .15s}
  .casc-seg-chip:hover{transform:translateY(-1px);background:#f7e9c6;box-shadow:0 4px 9px rgba(194,162,77,.4)}
  .casc-seg-chip:active{transform:translateY(0)}
  .casc-soc:empty{display:none}
  .casc-overlay.open{display:flex}
  .casc-box{background:#fff;border-radius:14px;width:min(1100px,95vw);max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden}
  .casc-head{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #e3e8ef;background:#22344d;color:#fff}
  .casc-head b{font-size:15px}.casc-doc{flex:1;font-size:12px;color:#cdd8e8;font-family:Consolas,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .casc-x{background:transparent;border:none;color:#fff;font-size:18px;cursor:pointer}
  .casc-cols{display:grid;grid-template-columns:repeat(6,1fr);gap:0;flex:1;overflow:hidden;min-height:360px}
  .casc-col{display:flex;flex-direction:column;border-right:1px solid #eef1f6;min-height:0}
  .casc-col:last-child{border-right:none}
  .casc-col-h{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#22344d;padding:10px 12px;background:#f6f8fb;border-bottom:1px solid #eef1f6}
  .casc-search{margin:8px;padding:7px 10px;border:1px solid #e3e8ef;border-radius:7px;font-size:12.5px}
  .casc-list{flex:1;overflow:auto;padding:0 8px 8px}
  .casc-item{padding:8px 10px;border-radius:7px;cursor:pointer;font-size:12.5px;border:1px solid transparent;margin-bottom:3px}
  .casc-item:hover{background:#f1f5fb}
  .casc-item.on{background:#eaf1fb;border-color:#bcd3f2;font-weight:600}
  .casc-item .s{display:block;color:#8a97a8;font-size:11px}
  .casc-empty{color:#9aa6b8;font-size:12px;padding:10px}
  .casc-foot{display:flex;align-items:center;gap:14px;padding:12px 18px;border-top:1px solid #e3e8ef;background:#fafbfc}
  .casc-sel{flex:1;font-size:13px;color:#22344d}
  .casc-sel b{color:#0f766e}
  /* Mode PHOTOS : footer mis en évidence pour ne pas oublier le libellé du groupe */
  .casc-foot.casc-foot-photo{background:linear-gradient(0deg,#f4f8f5,#fbfdfb);border-top:2px solid #84a98c;box-shadow:0 -6px 18px rgba(132,169,140,.18)}
  .casc-foot.casc-foot-photo #photoGroupLabel{border:2px solid #84a98c!important;box-shadow:0 0 0 3px rgba(132,169,140,.18);animation:photoPulse 1.6s ease-in-out infinite}
  @keyframes photoPulse{0%,100%{box-shadow:0 0 0 3px rgba(132,169,140,.18)}50%{box-shadow:0 0 0 5px rgba(132,169,140,.30)}}
  @media(prefers-reduced-motion:reduce){.casc-foot.casc-foot-photo #photoGroupLabel{animation:none}}
  .casc-foot.casc-foot-photo #photoGroupLabel{font-size:18px!important;font-weight:600;color:#243B5C;padding:12px 16px!important;min-width:280px!important}
  #photoSave{font-size:16px;font-weight:800;padding:13px 30px;border-radius:14px;background:#D4A047;color:#243B5C;border:2px solid #c2932f;box-shadow:0 8px 20px rgba(212,160,71,.32);letter-spacing:.2px}
  #photoSave:hover:not(:disabled){background:#e0b054;transform:translateY(-1px)}
  #photoSave:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
  /* Modal Type de document — style Apple/Airbnb, palette MBI (navy/doré/amande/pétrole) */
  #typeModal .tym-box{background:#fff;width:min(600px,95vw);max-height:88vh;display:flex;flex-direction:column;border-radius:24px;overflow:hidden;border:2px solid #D4A047;box-shadow:0 0 0 5px rgba(212,160,71,.12), 0 30px 80px rgba(36,59,92,.34);font-family:-apple-system,'Segoe UI',system-ui,sans-serif}
  #typeModal .tym-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:24px 26px 14px}
  #typeModal .tym-title{font-size:20px;font-weight:800;color:#243B5C;letter-spacing:-.3px}
  #typeModal .tym-doc{font-size:12px;color:#8a9aa0;margin-top:4px;max-width:430px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:Consolas,Monaco,monospace}
  #typeModal .tym-x{border:none;background:#f1f3f6;color:#5b6b70;width:34px;height:34px;border-radius:50%;font-size:15px;cursor:pointer;flex-shrink:0;transition:background .15s}
  #typeModal .tym-x:hover{background:#e6eaef}
  #typeModal .tym-search{padding:0 26px 14px}
  #typeModal .tym-search input{width:100%;padding:13px 16px;border:1.5px solid #e7ebeb;border-radius:15px;font-size:14px;background:#f7f9f8;transition:border .15s,box-shadow .15s,background .15s}
  #typeModal .tym-search input:focus{outline:none;border-color:#84a98c;background:#fff;box-shadow:0 0 0 4px rgba(132,169,140,.2)}
  #typeModal .tym-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:0 26px 16px}
  #typeModal .tym-tab{border:none;background:#f1f4f4;color:#546a6f;font-size:13px;font-weight:600;padding:8px 15px;border-radius:22px;cursor:pointer;transition:all .15s}
  #typeModal .tym-tab:hover{background:#e7f0f1;color:#2d5f6b}
  #typeModal .tym-tab.on{background:#2d5f6b;color:#fff;box-shadow:0 5px 14px rgba(45,95,107,.32)}
  #typeModal .tym-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:11px;padding:4px 26px 26px;overflow:auto}
  #typeModal .tym-card{border:1.5px solid #ececec;background:#fff;border-radius:16px;padding:16px 14px;font-size:14px;font-weight:600;color:#2b3a40;cursor:pointer;text-align:center;line-height:1.3;transition:transform .1s,box-shadow .18s,border-color .15s,background .15s}
  #typeModal .tym-card:hover{transform:translateY(-2px);border-color:#84a98c;background:#f4f8f5;box-shadow:0 10px 22px rgba(132,169,140,.3)}
  #typeModal .tym-card:active{transform:translateY(0)}
  #typeModal .tym-add{border:1.6px dashed #D4A047;background:#fdf7ea;color:#a87f1e}
  #typeModal .tym-add:hover{background:#fbf0d8;border-color:#c2932f;box-shadow:0 8px 18px rgba(212,160,71,.28)}
  #typeModal .tym-box{width:min(720px,95vw)}
  #typeModal .tym-form{display:flex;flex-direction:column;gap:9px;border:2px solid #D4A047;background:#fffdf7;border-radius:18px;padding:16px 16px 14px;box-shadow:0 10px 26px rgba(212,160,71,.16)}
  #typeModal .tym-flab{font-size:12px;font-weight:700;letter-spacing:.02em;color:#a87f1e;text-transform:uppercase}
  #typeModal .tym-fin{width:100%;box-sizing:border-box;border:1.5px solid #e2d4b0;border-radius:12px;padding:11px 13px;font-size:15px;color:#243B5C;outline:none}
  #typeModal .tym-fin:focus{border-color:#D4A047;box-shadow:0 0 0 3px rgba(212,160,71,.16)}
  #typeModal .tym-fcodein{font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.04em;color:#2d5f6b;text-transform:uppercase}
  #typeModal .tym-fcode{font-size:12.5px;color:#5b6b73}
  #typeModal .tym-fcode b{font-family:ui-monospace,Menlo,Consolas,monospace;color:#2d5f6b;letter-spacing:.03em}
  #typeModal .tym-fdup{font-size:12.5px;font-weight:600;min-height:16px;color:#6b7a82}
  #typeModal .tym-fdup.on{color:#b3261e}
  #typeModal .tym-fdup.okc{color:#2e7d55}
  #typeModal .tym-fbtns{display:flex;gap:8px;justify-content:flex-end;margin-top:2px}
  #typeModal .tym-fcancel{border:1.5px solid #e0e0e0;background:#fff;color:#556;border-radius:11px;padding:8px 16px;font-size:13.5px;font-weight:600;cursor:pointer}
  #typeModal .tym-fok{border:none;background:#D4A047;color:#fff;border-radius:11px;padding:8px 20px;font-size:13.5px;font-weight:700;cursor:pointer}
  #typeModal .tym-fok:disabled{opacity:.45;cursor:not-allowed}
  @media(max-width:640px){#typeModal .tym-grid{grid-template-columns:1fr 1fr}}
  @media(max-width:440px){#typeModal .tym-grid{grid-template-columns:1fr}}
</style>
CSS;
require_once __DIR__ . '/inc/agency_layout_top.php';
?>
<div class="mbo">

  <!-- SÉLECTEUR DE SOURCE : Téléchargements ⇄ Mails (espaces étanches) -->
  <div class="srcsel" id="srcSel">
    <button type="button" class="src-tab" data-src="dl">📥 Téléchargements <span class="src-n" id="dlCount"></span></button>
    <button type="button" class="src-tab active" data-src="mail">📧 Mails</button>
  </div>

  <!-- ESPACE TÉLÉCHARGEMENTS (masqué par défaut) -->
  <div class="dlws" id="dlWorkspace" hidden>
    <div class="dl-head">
      <div>
        <div class="dl-title">📥 Dossier Téléchargements</div>
        <div class="dl-sub">Les fichiers restent sur ton disque. Choisis-en un, oriente-le, puis <b>Classer</b> (il monte dans la pile) ou <b>Supprimer</b> (effacé du disque). Aucun mélange avec les mails.</div>
      </div>
      <div class="dl-actions">
        <button type="button" class="btn btn-primary" id="dlPick">📁 Choisir mon dossier Téléchargements</button>
        <button type="button" class="btn" id="dlRefresh" hidden>↻ Recharger</button>
      </div>
    </div>
    <div class="dl-grid" id="dlGrid"><div class="dl-empty">Choisis ton dossier Téléchargements pour lister les fichiers à classer ou supprimer.</div></div>
  </div>

  <!-- ESPACE MAILS (existant) — enveloppé pour bascule -->
  <div id="mailWorkspace">

  <!-- TOP BAR : actions globales -->
  <div class="topbar">
    <div class="sync">
      <select id="syncBox" style="flex:1;max-width:340px;padding:9px 12px;border-radius:8px;border:1px solid #3a4d68;font-size:13px">
        <option value="">Toutes les boîtes actives<?= $mailboxes ? ' (' . count($mailboxes) . ')' : '' ?></option>
        <?php foreach ($mailboxes as $mb): ?>
          <option value="<?= (int)$mb['id'] ?>"><?= htmlspecialchars(($mb['label'] ?: $mb['email']) . ($mb['label'] ? ' — ' . $mb['email'] : '')) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" id="syncDays" value="90" style="width:70px;padding:9px;border-radius:8px;border:1px solid #3a4d68" title="jours">
      <button class="btn btn-light" id="syncBtn">⭳ Synchroniser</button>
      <button class="btn btn-ghost-w" id="anaBtn">🔍 Analyser (OCR)<?= $aAnalyser > 0 ? ' · ' . $aAnalyser : '' ?></button>
    </div>
    <span class="msg" id="topMsg" style="color:#cdd8e8"></span>
    <a class="btn btn-ghost-w" href="ged_glossaire_types.php" title="Gérer le vocabulaire des types de documents">📖 Glossaire</a>
    <a class="btn <?= $histo ? 'btn-light' : 'btn-ghost-w' ?>" href="<?= htmlspecialchars($histo ? $SELF : $SELF . '?histo=1') ?>"><?= $histo ? '↩ Retour inbox' : '🕘 Historique · ' . $kHisto ?></a>
  </div>

  <!-- COMPTEURS -->
  <div class="counters">
    <div class="stat"><b><?= $kNouveau ?></b><span>Nouveaux</span></div>
    <div class="stat pay"><b><?= $kPayer ?></b><span>À payer</span></div>
    <div class="stat done"><b><?= $kTraite ?></b><span>Traités</span></div>
    <div class="stat"><b><?= $totalDocs ?></b><span>Total</span></div>
    <button type="button" class="btn" id="btnRegles" style="background:#5b3fb0;color:#fff;flex-shrink:0" title="Apprendre à l'IA tes réflexes de reconnaissance">🎓 Éduquer l'IA</button>
  </div>

  <!-- NOM GED proposé — bouton d'exploration + bandeau centré -->
  <div class="gedbanner-row">
    <button type="button" id="btnGedLink" title="Explorer & lier les entités (reprend les liens actuels)">🔗 Lier</button>
    <div class="gedbanner" id="vGed"></div>
  </div>

  <div class="grid">

    <!-- COLONNE 1 : LA FILE -->
    <section class="panel col-list">
      <div class="list-head">
        <?php $sfx = $histo ? '&histo=1' : ''; ?>
        <form method="get" action="<?= htmlspecialchars($SELF) ?>">
          <?php if ($histo): ?><input type="hidden" name="histo" value="1"><?php endif; ?>
          <?php if ($fBac): ?><input type="hidden" name="bac" value="<?= htmlspecialchars($fBac) ?>"><?php endif; ?>
          <input class="search" type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Rechercher un document…">
        </form>
        <div class="filters">
          <?php
            $sortNext = $sort === 'ASC' ? 'desc' : 'asc';
            $sortParams = array_filter(['bac'=>$fBac ?: null, 'statut'=>$fStat ?: null, 'q'=>$q ?: null, 'histo'=>$histo ? '1' : null, 'sort'=>$sortNext]);
            $sortHref = $SELF . '?' . http_build_query($sortParams);
            $isTous = ($fBac === '' && $fStat === '');
            $aClasserHref = $SELF . '?statut=a_classer' . ($histo ? '&histo=1' : '');
          ?>
          <!-- 3 boutons compacts icône-seule sur UNE ligne : Tous · À classer · récents -->
          <div class="filters-ico">
            <a class="chip chip-ico chip-all <?= $isTous ? 'active' : '' ?>" href="<?= htmlspecialchars($SELF . ($histo ? '?histo=1' : '')) ?>" title="Tous">🗂️</a>
            <a class="chip chip-ico <?= $fStat === 'a_classer' ? 'active' : '' ?>" href="<?= htmlspecialchars($aClasserHref) ?>" title="À classer">📂</a>
            <a class="chip chip-ico chip-sort" href="<?= htmlspecialchars($sortHref) ?>" title="<?= $sort === 'DESC' ? 'Plus récents d\'abord' : 'Plus anciens d\'abord' ?>">📅<?= $sort === 'DESC' ? '↓' : '↑' ?></a>
          </div>
          <?php foreach ($BACS as $k => [$ic, $lab]): ?>
            <a class="chip <?= $fBac === $k ? 'active' : '' ?>" href="<?= htmlspecialchars($SELF . '?bac=' . $k . $sfx) ?>"><?= htmlspecialchars($lab) ?><span class="n"><?= (int)($bacCounts[$k] ?? 0) ?></span></a>
          <?php endforeach; ?>
        </div>
      </div>
<?php if ($kFactures > 0): ?>
      <div class="compta-bar" id="comptaBar">
        <div class="cb-title">📥 Transférer les factures à CEGID <button type="button" id="cbConfig" title="Configurer les adresses de dépôt">⚙️</button></div>
        <div class="cb-btns" id="cbBtns"><span class="msg">Chargement…</span></div>
      </div>
<?php endif; ?>
      <div class="list" id="list">
        <?php if (!$docs): ?>
          <div style="padding:24px;text-align:center;color:#6b7890">Aucun document.<br>Synchronise une boîte mail pour commencer.</div>
        <?php else: foreach ($docs as $d): $id = (int)$d['id'];
          $ext = strtolower(pathinfo((string)$d['fichier_nom'], PATHINFO_EXTENSION));
          $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','tif','tiff'], true);
          $ic = $ext === 'pdf' ? '📄'
              : ($isImg ? '🖼️'
              : (in_array($ext, ['msg','eml'], true) ? '📧'
              : (in_array($ext, ['xls','xlsx','csv','ods'], true) ? '📊'
              : (in_array($ext, ['doc','docx','odt','rtf','txt'], true) ? '📝'
              : (in_array($ext, ['zip','rar','7z'], true) ? '🗜️' : '📄')))));
          $tp = (string)($d['mbo_type_propose'] ?? '');
          $tpLabel = $tp !== '' ? mbo_type_label($tp) : 'À classer';
          $tagCls = $typeTagClass($tp);
          $st = (string)$d['mbo_statut'];
          $badgeCls = in_array($st, ['traite','paye'], true) ? 'done' : ($st === 'a_payer' ? 'pay' : ($st === 'urgent' ? 'urg' : (in_array($st, ['en_attente','a_traiter'], true) ? 'wait' : ($st === 'ignore' ? 'ign' : 'new'))));
          $badgeLab = $STATUTS[$st][1] ?? 'Nouveau';
          $bacPropose = $tp !== '' ? mbo_guess_bac($tp, (string)($d['mbo_metier'] ?? '')) : '';
          // chaîne de liens
          $chainHtml = '';
          if (!empty($d['mbo_entity_type'])) {
              $parts = [];
              foreach (mbo_entity_chain($pdo, (string)$d['mbo_entity_type'], (int)$d['mbo_entity_id']) as $lk)
                  $parts[] = '<a href="' . htmlspecialchars($lk['url']) . '" target="_blank">' . $lk['icon'] . ' ' . htmlspecialchars($lk['label']) . '</a>';
              $chainHtml = implode(' <span style="color:#9aa6b8">›</span> ', $parts);
          }
          $gedName = mbo_build_ged_name($pdo, $d);
          $iaBits = [];
          if ($tp !== '') $iaBits[] = mbo_type_label($tp);
          if (!empty($d['mbo_metier'])) $iaBits[] = 'métier ' . mbo_metier_label((string)$d['mbo_metier']);
          if (!empty($d['mbo_entity_label'])) $iaBits[] = 'reconnu : ' . $d['mbo_entity_label'];
          $iaComment = ($iaBits ? implode(' · ', $iaBits) . '. ' : '') . trim((string)($d['ocr_excerpt'] ?? ''));
          $recu = (string)($d['first_seen_at'] ?: $d['created_at']);
        ?>
          <div class="item" data-id="<?= $id ?>" data-soc="<?= (int)$d['tenant_id'] ?>" data-ext="<?= htmlspecialchars($ext) ?>"
               data-nom="<?= htmlspecialchars((string)$d['fichier_nom']) ?>"
               data-sub="<?= htmlspecialchars(($recu ? date('d/m/Y H:i', strtotime($recu)) : '') . ' · ' . $fmtSize($d['taille_octets']) . ' · ' . ($d['source_type'] ?: 'manual')) ?>"
               data-typelabel="<?= htmlspecialchars($tpLabel) ?>" data-tagcls="<?= $tagCls ?>"
               data-metierraw="<?= htmlspecialchars((string)($d['mbo_metier'] ?? '')) ?>"
               data-typeraw="<?= htmlspecialchars((string)($d['mbo_type_propose'] ?? '')) ?>"
               data-enttype="<?= htmlspecialchars((string)($d['mbo_entity_type'] ?? '')) ?>"
               data-entid="<?= (int)($d['mbo_entity_id'] ?? 0) ?>"
               data-entlabel="<?= htmlspecialchars((string)($d['mbo_entity_label'] ?? '')) ?>"
               data-bacpropose="<?= htmlspecialchars($bacPropose) ?>"
               data-baclabel="<?= htmlspecialchars($bacPropose && isset($BACS[$bacPropose]) ? $BACS[$bacPropose][1] : '') ?>"
               data-chain="<?= htmlspecialchars($chainHtml) ?>"
               data-classe="<?= htmlspecialchars(!empty($d['mbo_classe_at']) ? date('d/m/Y H:i', strtotime((string)$d['mbo_classe_at'])) : '') ?>"
               data-gedid="<?= (int)($d['mbo_ged_document_id'] ?? 0) ?>"
               data-gedname="<?= htmlspecialchars($gedName) ?>"
               data-geddupid="<?= (int)($d['ged_dup_id'] ?? 0) ?>"
               data-geddupname="<?= htmlspecialchars((string)($d['ged_dup_name'] ?? '')) ?>"
               data-iacomment="<?= htmlspecialchars($iaComment) ?>"
               data-iaanalyse="<?= htmlspecialchars((string)($d['mbo_ia_analyse'] ?? '')) ?>"
               data-iaactiontype="<?= htmlspecialchars((string)($d['mbo_ia_action_type'] ?? '')) ?>"
               data-iaactionlabel="<?= htmlspecialchars((string)($d['mbo_ia_action_label'] ?? '')) ?>"
               data-libelle="<?= htmlspecialchars((string)($d['mbo_libelle'] ?? '')) ?>"
               data-commentaire="<?= htmlspecialchars((string)($d['mbo_commentaire'] ?? '')) ?>"
               data-refsalaire="<?= htmlspecialchars(!empty($d['mbo_ref_salaire']) ? date('Y-m', strtotime((string)$d['mbo_ref_salaire'])) : '') ?>"
               data-refdate="<?= htmlspecialchars(!empty($d['mbo_ref_salaire']) ? date('Y-m-d', strtotime((string)$d['mbo_ref_salaire'])) : '') ?>"
               data-rhcat="<?= htmlspecialchars((string)($d['mbo_rh_categorie'] ?? '')) ?>"
               data-statut="<?= htmlspecialchars($st ?: 'nouveau') ?>">
            <?php
              $inGed  = (int)($d['ged_dup'] ?? 0) > 0;            // déjà présent en GED (même contenu, hash)
              $inPile = (int)($d['dup_cnt'] ?? 0) > 0;            // doublon dans la pile
              $isDup  = $inGed || $inPile;
              $dupTitle = $inGed ? 'Déjà classé en GED (contenu identique)' : ($inPile ? 'Doublon potentiel dans la pile' : 'Unique');
            ?>
            <div class="pick-col">
              <input type="checkbox" class="pick">
              <span class="dupdot <?= $isDup ? 'dup' : 'ok' ?><?= $inGed ? ' blink' : '' ?>" title="<?= htmlspecialchars($dupTitle) ?>"></span>
            </div>
            <div class="thumb"><?= $ic ?></div>
            <div class="meta">
              <div class="row-top"><span class="name"><?= htmlspecialchars((string)$d['fichier_nom']) ?></span><?php if ($d['mbo_statut'] !== 'nouveau'): ?><span class="badge <?= $badgeCls ?>"><?= htmlspecialchars($badgeLab) ?></span><?php endif; ?></div>
              <div class="sub"><?= htmlspecialchars($recu ? date('d/m H:i', strtotime($recu)) : '') ?> · <?= htmlspecialchars($fmtSize($d['taille_octets'])) ?></div>
              <span class="tag <?= $tagCls ?>"><?= htmlspecialchars($tpLabel) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <!-- COLONNE 2 : LIRE -->
    <section class="panel col-view">
      <div class="view-head">
        <div class="crumb" id="vCrumb"></div>
        <div class="head-body">
          <div class="head-left">
            <div class="view-title"><h2 id="vTitle">Sélectionne un document</h2></div>
            <div class="linker">
              <input type="text" id="entSearch" autocomplete="off" placeholder="🔗 Lier une entité : bien, immeuble, locataire, propriétaire, salarié…">
              <div class="ent-results" id="entResults"></div>
            </div>
            <div class="attach" id="vAttach" style="display:none"></div>
            <div class="msgbody" id="vMsgBody"></div>
            <div class="msg" id="pHint" style="margin-top:6px"></div>
            <div id="pIaAction" style="margin-top:6px;display:none"></div>
          </div>
          <div class="valid-grid">
            <button class="vb gold" id="btnClasser">✔ Valider</button>
            <button class="vb red"  id="btnDelete">🗑 Supprimer</button>
            <button class="vb blue" id="btnReana">🔄 Ré-analyse</button>
            <button class="vb dark" id="btnMail">✉️ Mail</button>
          </div>
        </div>
        <!-- Bandeau doublon AUTO : dès qu'un doublon est détecté à la sélection, on affiche
             le NOM du/des fichier(s) existant(s) + suppression rapide, sans clic supplémentaire. -->
        <div id="dupInline" style="display:none;margin:8px 16px 0;padding:9px 12px;border-radius:10px;background:#fbecea;border:1px solid #e7c6bf;color:#8a2f22;font-size:12.5px;line-height:1.45"></div>
        <div id="dupBar" style="display:none">
          <button type="button" id="btnKeepDup" title="Conserver le document actuellement affiché et supprimer l'autre">✓ Je conserve celui-ci</button>
          <div class="dup-toggle" id="dupToggle">
            <span class="dt-opt on" data-view="mbo">en MBO</span>
            <span class="dt-opt" data-view="ged">en GED</span>
          </div>
        </div>
      </div>
      <div class="viewer" id="viewer"><div class="empty">👈 Sélectionne un document dans la liste</div></div>
    </section>

    <!-- COLONNE 3 : DÉCIDER -->
    <aside class="col-act" id="decide" style="display:none">
      <div class="panel card">
        <h4>Orienter dans un bac</h4>
        <div class="bacs" id="bacBtns">
          <?php foreach ($BACS as $k => [$ic, $lab]): if ($k === 'non_classe' || $k === 'facture') continue; // facture = automatique via les statuts ?>
            <button type="button" class="bac" data-bac="<?= $k ?>"><?= $ic ?> <?= htmlspecialchars($lab) ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel card">
        <h4>Statut · orientation</h4>
        <div class="statut statut4">
          <button type="button" class="sbtn classer" data-statut="nouveau">📂 À classer</button>
          <button type="button" class="sbtn pay" data-statut="a_payer">💶 À payer</button>
          <button type="button" class="sbtn wait" data-statut="a_traiter">⏳ À traiter</button>
          <button type="button" class="sbtn urgent" data-statut="urgent">🔴 Urgent</button>
        </div>
        <div class="statut" style="margin-top:6px">
          <button type="button" class="sbtn" id="btnDup">⧉ Vérifier doublon</button>
          <button type="button" class="sbtn done" data-statut="paye">✔ Payé</button>
        </div>
      </div>

      <div class="panel card" id="mandatCard" style="display:none">
        <h4>Transaction</h4>
        <button type="button" class="btn btn-primary" id="btnMandat" style="width:100%;justify-content:center">➕ Créer le mandat sur le bien</button>
        <div class="msg" id="mandatMsg" style="margin-top:6px"></div>
      </div>

      <div class="panel card fields">
        <h4>Détails</h4>
        <div id="rhFields" style="display:none">
          <label>Bulletin (mois de paie)</label><input type="month" id="fMois">
          <label>Catégorie salaire</label>
          <select id="fCategorie">
            <option value="">— Aucune (doc RH hors salaire) —</option>
            <option value="frais_deplacement">Frais déplacement</option>
            <option value="ik">Indemnités km</option>
            <option value="stationnement">Stationnement</option>
            <option value="remboursement_achat">Achats</option>
            <option value="frais_professionnels">Frais pro</option>
            <option value="frais_reception">Frais réception</option>
            <option value="prime_admin">Prime admin</option>
            <option value="prime_exceptionnelle">Prime except.</option>
            <option value="commission_ca">Commission</option>
            <option value="bulletin">Bulletin de paie</option>
          </select>
        </div>
        <div id="dateField">
          <label>Date de référence (mandat, bail, contrat…)</label><input type="date" id="fDate">
        </div>
        <label>Libellé perso</label><input type="text" id="fLibelle" placeholder="Ajouter un libellé…">
        <label>Commentaire</label><input type="text" id="fComment" placeholder="Note interne…">
      </div>

    </aside>
  </div>

  <!-- Formulaires cachés (actions POST) -->
  <form method="post" id="fBac" style="display:none"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="bac"><input type="hidden" name="id" id="fBacId"><input type="hidden" name="bac" id="fBacVal"></form>
  <form method="post" id="fStatut" style="display:none"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="statut"><input type="hidden" name="id" id="fStatutId"><input type="hidden" name="statut" id="fStatutVal"></form>
  <form method="post" id="fMeta" style="display:none"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="savemeta"><input type="hidden" name="id" id="fMetaId"><input type="hidden" name="libelle" id="fMetaLib"><input type="hidden" name="commentaire" id="fMetaCom"></form>
  <form method="post" id="fDel" style="display:none"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="fDelId"></form>

  </div><!-- /#mailWorkspace -->

  <!-- MODAL : picker segment (société / agence / métier) -->
  <div class="casc-overlay" id="segModal">
    <div class="casc-box" style="width:min(520px,95vw)">
      <div class="casc-head"><b id="segTitle">Choisir</b><span class="casc-doc" id="segDoc"></span><button type="button" class="casc-x" id="segClose">✕</button></div>
      <div id="segGrid" style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:8px;overflow:auto;max-height:60vh"></div>
    </div>
  </div>

  <!-- MODAL : type de document (fixer le type manquant) -->
  <div class="casc-overlay" id="typeModal">
    <div class="tym-box">
      <div class="tym-head">
        <div><div class="tym-title">Type de document</div><div class="tym-doc" id="typeDoc"></div></div>
        <button type="button" class="tym-x" id="typeClose">✕</button>
      </div>
      <div class="tym-search"><input type="text" id="typeSearch" autocomplete="off" placeholder="Rechercher un type…"></div>
      <div id="typeTabs" class="tym-tabs"></div>
      <div id="typeGrid" class="tym-grid"></div>
    </div>
  </div>

  <!-- MODAL : vérification anti-doublon -->
  <div class="casc-overlay" id="dupModal">
    <div class="casc-box" style="width:min(620px,95vw)">
      <div class="casc-head"><b>⧉ Vérification anti-doublon</b><span class="casc-doc" id="dupDoc"></span><button type="button" class="casc-x" id="dupClose">✕</button></div>
      <div id="dupBody" style="padding:16px;overflow:auto;font-size:13px"></div>
    </div>
  </div>

  <!-- MODAL : message du mail (lecture confortable) -->
  <div class="casc-overlay" id="msgModal">
    <div class="casc-box" style="width:min(680px,95vw)">
      <div class="casc-head"><b>✉️ Message du mail</b><span class="casc-doc" id="msgFrom"></span><button type="button" class="casc-x" id="msgClose">✕</button></div>
      <div id="msgBody" style="padding:18px;overflow:auto;white-space:pre-wrap;font-size:14px;line-height:1.6;color:#2b3648"></div>
    </div>
  </div>

  <!-- MODAL : Éduquer l'IA (base de connaissance des réflexes) -->
  <div class="casc-overlay" id="regModal">
    <div class="casc-box" style="width:min(720px,95vw)">
      <div class="casc-head">
        <b>🎓 Éduquer l'agent IA de reconnaissance</b>
        <span class="casc-doc">Tes réflexes deviennent des règles appliquées à chaque analyse.</span>
        <button type="button" class="casc-x" id="regClose">✕</button>
      </div>
      <div style="padding:16px;display:flex;flex-direction:column;gap:12px;overflow:auto">
        <div style="display:flex;gap:8px">
          <input type="text" id="regInput" placeholder="Ex. « Prendre le nom de l'immeuble, jamais l'adresse » — Entrée pour ajouter" style="flex:1;padding:9px 12px;border:1px solid #e3e8ef;border-radius:8px;font-size:13px">
          <button type="button" class="btn btn-primary" id="regAdd">+ Ajouter</button>
        </div>
        <div id="regList" style="display:flex;flex-direction:column;gap:6px"></div>
      </div>
    </div>
  </div>

  <!-- MODAL : explorateur de liens en cascade (4 colonnes) -->
  <div class="casc-overlay" id="cascModal">
    <div class="casc-box">
      <div class="casc-head">
        <b id="cascTitle">⧉ Explorer & lier les entités</b>
        <span class="casc-doc" id="cascDoc"></span>
        <span class="casc-soc" id="cascSoc"></span>
        <button type="button" class="casc-x" id="cascClose">✕</button>
      </div>
      <div style="padding:10px 14px;border-bottom:1px solid #eef1f6;background:#fafbfc;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="text" id="cascGlobal" autocomplete="off" placeholder="🔎 Rechercher (locataire, immeuble, propriétaire, réf bien)…" style="flex:1;min-width:200px;max-width:380px;padding:9px 12px;border:1px solid #e3e8ef;border-radius:8px;font-size:13px">
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-left:auto">
          <button type="button" class="casc-seg-chip" id="cascChipSoc">🏢 Société</button>
          <button type="button" class="casc-seg-chip" id="cascChipAge">🏢 Agence</button>
          <button type="button" class="casc-seg-chip" id="cascChipMet">🗂️ Métier</button>
        </div>
      </div>
      <div class="casc-cols">
        <?php foreach ([['proprio','👤 Propriétaire'],['immeuble','🏢 Immeuble'],['bien','🏠 Bien'],['bail','🔑 Bail / Locataire'],['tiers','🧾 Tiers'],['personnel','🔒 Personnel']] as [$col,$lab]): ?>
        <div class="casc-col" data-col="<?= $col ?>">
          <div class="casc-col-h"><?= $lab ?></div>
          <input type="text" class="casc-search" data-col="<?= $col ?>" placeholder="Rechercher…">
          <div class="casc-list" data-col="<?= $col ?>"></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="casc-foot">
        <span class="casc-sel" id="cascSel">Sélectionne une entité dans une colonne…</span>
        <input type="text" id="photoGroupLabel" autocomplete="off" placeholder="📸 Nom du groupe (ex. Salle de bain, Annonce…)" style="display:none;min-width:220px;padding:9px 12px;border:1px solid #cdd8e8;border-radius:8px;font-size:13px">
        <button type="button" class="btn btn-primary" id="cascLink" disabled>🔗 Lier ce document</button>
        <button type="button" class="btn" id="photoSave" style="display:none;background:#84a98c;color:#fff" disabled>📸 Enregistrer les photos</button>
      </div>
    </div>
  </div>

  <div style="position:fixed;left:50%;transform:translateX(-50%);bottom:18px;background:var(--navy,#22344d);color:#fff;border-radius:12px;padding:10px 16px;display:none;gap:10px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:20" id="batch">
    <b style="background:#fff;color:#22344d;border-radius:20px;padding:1px 9px;font-size:12px" id="batchN">0</b> <span style="font-size:13px">sélectionnés</span>
    <button class="btn btn-light" id="batchPhotos" style="background:#84a98c;color:#fff">📸 Enregistrer en photos…</button>
    <button class="btn btn-ghost-w" id="batchCancel">Annuler</button>
  </div>
</div>

<script>
(function(){
  var Q = <?= json_encode(empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']) ?>;
  var FILE = <?= json_encode(app_url('/api/maboxoffice_file.php')) ?>;
  var PHOTOS_TO_BIEN = <?= json_encode(app_url('/api/maboxoffice_photos_to_bien.php')) ?>;
  var IMG_EXT = ['jpg','jpeg','png','gif','webp','bmp','tif','tiff'];
  var SYNC = <?= json_encode(app_url('/api/maboxoffice_sync.php')) ?>;
  var ANA  = <?= json_encode(app_url('/api/maboxoffice_analyze.php')) ?>;
  var POLL = <?= json_encode(app_url('/api/maboxoffice_ocr_poll.php')) ?>;
  var MSG  = <?= json_encode(app_url('/api/maboxoffice_message.php')) ?>;
  var IA   = <?= json_encode(app_url('/api/maboxoffice_ia.php')) ?>;
  var CLASSER = <?= json_encode(app_url('/api/maboxoffice_classer.php')) ?>;
  var RELANCE = <?= json_encode(app_url('/api/maboxoffice_relance_fournisseur.php')) ?>;
  var SELF = <?= json_encode($SELF) ?>;
  var CSRF = <?= json_encode($csrf) ?>;
  var HISTO = <?= $histo ? 'true' : 'false' ?>;
  var GEDSERVE = <?= json_encode(app_url('/api/ged_doc_serve.php')) ?>;
  var GEDRENAME = <?= json_encode(app_url('/api/maboxoffice_ged_rename.php')) ?>;
  var ENTSEARCH = <?= json_encode(app_url('/api/fluxbox_entity_search.php')) ?>;
  var SETENT = <?= json_encode(app_url('/api/maboxoffice_setentity.php')) ?>;
  var CASCADE = <?= json_encode(app_url('/api/maboxoffice_cascade.php')) ?>;
  var REGLES = <?= json_encode(app_url('/api/maboxoffice_regles.php')) ?>;
  var COMPTA = <?= json_encode(app_url('/api/maboxoffice_comptable.php')) ?>;
  var DOUBLON = <?= json_encode(app_url('/api/maboxoffice_doublon.php')) ?>;
  var SETTYPE = <?= json_encode(app_url('/api/maboxoffice_settype.php')) ?>;
  var SETSEG = <?= json_encode(app_url('/api/maboxoffice_setseg.php')) ?>;
  var MAILVIEW = <?= json_encode(app_url('/api/maboxoffice_mailview.php')) ?>;
  var CREATEMANDAT = <?= json_encode(app_url('/api/maboxoffice_create_mandat.php')) ?>;
  var FBAC = <?= json_encode($fBac) ?>;
  function esc(s){ return (s||'').replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}); }
  function nextItemOf(el){ var n=el.nextElementSibling; while(n&&!n.classList.contains('item'))n=n.nextElementSibling; if(!n){ n=el.previousElementSibling; while(n&&!n.classList.contains('item'))n=n.previousElementSibling; } return n; }
  function emptyState(msg){ document.getElementById('decide').style.display='none'; document.getElementById('viewer').innerHTML='<div class="empty">'+msg+'</div>'; document.getElementById('vTitle').textContent='—'; document.getElementById('vCrumb').innerHTML=''; document.getElementById('vGed').textContent=''; }
  function advance(el){ var n=nextItemOf(el); if(n){ n.scrollIntoView({block:'nearest'}); select(n); } else emptyState('✓ Tout est traité'); }
  function removeAndNext(el){ var n=nextItemOf(el); el.remove(); if(n){ n.scrollIntoView({block:'nearest'}); select(n); } else emptyState('✓ Inbox vide — tout est classé'); }
  function ajaxAction(params){ return fetch(SELF,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+encodeURIComponent(CSRF)+'&ajax=1&'+params}); }
  // Préchargement : on charge à l'avance les fichiers suivants → zéro latence à la navigation.
  var prefetched = {};
  function prefetchDoc(el){ if(!el) return; var id=el.dataset.id, ext=(el.dataset.ext||'').toLowerCase(); if(prefetched[id]) return; prefetched[id]=1;
    var url=FILE+'?id='+encodeURIComponent(id);
    if(['jpg','jpeg','png','gif','webp'].indexOf(ext)!==-1){ var im=new Image(); im.src=url; }         // images : cache navigateur
    else { var l=document.createElement('link'); l.rel='prefetch'; l.href=url; l.as='document'; document.head.appendChild(l); } // PDF & autres
  }
  function prefetchAround(el){ var n=el; for(var i=0;i<3 && n;i++){ n=nextItemOf(n); prefetchDoc(n); } }
  function jsSlug(s){ return (s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toUpperCase().replace(/[^A-Z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
  var items = document.querySelectorAll('.mbo .item');
  var cur = null, gedBase = '', dupViewOn = false;
  var topMsg = document.getElementById('topMsg');

  function submitForm(idEl, valEl, val, docId){ document.getElementById(idEl).value = docId; document.getElementById(valEl).value = val; }

  var GSEG_TITLE = ['Société','Agence','Métier','Professionnel','Immeuble','Bien','Bail / locataire','Type de document','Mois de paie','Libellé perso','Date'];
  var GSEG_ICON  = ['🏢','📍','💼','👤','🏛️','🚪','🔑','📄','📅','📝','🗓'];
  var GSEG_COL = {3:'proprio', 4:'immeuble', 5:'bien', 6:'bail'};
  function gedSegClick(pos){
    if(pos===0){ openSeg('societe'); }                                                                                          // société
    else if(pos===1){ openSeg('agence'); }                                                                                      // agence
    else if(pos===2){ openSeg('metier'); }                                                                                      // métier
    else if(pos<=6){ var col=GSEG_COL[pos]; openCascade(col); }                                                                 // entité, colonne ciblée
    else if(pos===7){ openTypeModal(); }                                                                                        // type
    else if(pos===8){ var isRH=document.getElementById('rhFields').style.display!=='none'; var f=document.getElementById(isRH?'fMois':'fDate'); if(f){ f.focus(); f.scrollIntoView({block:'nearest'}); } } // mois (paye) ou date
    else if(pos===9){ var l=document.getElementById('fLibelle'); if(l){ l.focus(); l.scrollIntoView({block:'nearest'}); } }      // libellé
  }
  // ── Pickers segment : société / agence / métier (cascade) ───────────────────
  function openSeg(field){ if(!cur) return;
    var titles={societe:'🏢 Choisir la société',agence:'🏢 Choisir l\'agence',metier:'🗂️ Choisir le métier',user:'🧑‍💼 Choisir le collaborateur'};
    document.getElementById('segTitle').textContent = titles[field]||'Choisir';
    document.getElementById('segDoc').textContent = cur.dataset.nom||'';
    var grid=document.getElementById('segGrid'); grid.innerHTML='<span class="msg" style="padding:8px">Chargement…</span>';
    var url = field==='agence' ? (SETSEG+'?list=agences&doc='+encodeURIComponent(cur.dataset.id))
            : field==='user'  ? (SETSEG+'?list=users&doc='+encodeURIComponent(cur.dataset.id))
            : (SETSEG+'?list='+(field==='societe'?'societes':'metiers'));
    fetch(url,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok || !d.items.length){ grid.innerHTML='<span class="msg" style="padding:8px">Aucun élément'+(field==='agence'?' — choisis d\'abord la société':'')+'</span>'; return; }
      grid.innerHTML=d.items.map(function(it){ return '<button type="button" class="ent-item" data-val="'+it.value+'" data-lbl="'+esc(it.label)+'"><b>'+esc(it.label)+'</b>'+(it.sub?'<span class="s" style="display:block;color:#8a97a8;font-size:11px">'+esc(it.sub)+'</span>':'')+'</button>'; }).join('');
      grid.querySelectorAll('[data-val]').forEach(function(b){ b.onclick=function(){
        if(field==='user'){ linkEntity('user', b.dataset.val, b.dataset.lbl); document.getElementById('segModal').classList.remove('open'); }
        else setSeg(field, b.dataset.val, b.dataset.lbl);
      }; });
    }).catch(function(){ grid.innerHTML='<span class="msg" style="padding:8px">Erreur</span>'; });
    document.getElementById('segModal').classList.add('open');
  }
  function setSeg(field,value,label){ if(!cur) return; var el=cur;
    fetch(SETSEG+'?doc='+encodeURIComponent(el.dataset.id)+'&field='+field+'&value='+encodeURIComponent(value),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'échec'); return; }
      el.dataset.gedname=d.gedname; gedBase=d.gedname; refreshGed();
      if(field==='metier'){ el.dataset.metierraw=value; document.getElementById('rhFields').style.display=(value==='rh')?'':'none'; document.getElementById('dateField').style.display=(value==='rh')?'none':''; } // sync RH
      // maj du chip correspondant dans le modal cascade
      var chipId = field==='societe'?'cascChipSoc':(field==='agence'?'cascChipAge':(field==='metier'?'cascChipMet':null));
      if(chipId && label){ var c=document.getElementById(chipId); if(c) c.textContent=(field==='metier'?'🗂️ ':'🏢 ')+label; }
      // Changer l'agence réaligne la société → on met à jour le chip société.
      if(field==='agence' && d.societe_label){ var cs=document.getElementById('cascChipSoc'); if(cs) cs.textContent='🏢 '+d.societe_label; }
      if(field==='societe'){ document.getElementById('segModal').classList.remove('open'); openSeg('agence'); }      // cascade société → agence
      else if(field==='metier' && value==='rh'){ document.getElementById('segModal').classList.remove('open'); openSeg('user'); } // RH → collaborateurs
      else document.getElementById('segModal').classList.remove('open');
    }).catch(function(e){ topMsg.textContent='⚠ '+e; });
  }
  // Bouton « Créer le mandat » → ensure_mandat sur le bien lié.
  document.getElementById('btnMandat').addEventListener('click', function(){ if(!cur) return; var el=cur, b=this, m=document.getElementById('mandatMsg');
    b.disabled=true; m.style.color='#64748b'; m.textContent='Création du mandat…';
    fetch(CREATEMANDAT+'?doc='+encodeURIComponent(el.dataset.id),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ b.disabled=false;
      if(!d.ok){ m.style.color='#c0492f'; m.textContent='⚠ '+(d.error||'échec'); return; }
      m.style.color='#2e8b47'; m.textContent='✓ '+d.message; el.dataset.metierraw=(d.type_mandat==='vente'?'transaction':'gestion');
    }).catch(function(e){ b.disabled=false; m.style.color='#c0492f'; m.textContent='⚠ '+e; });
  });
  // Bouton « 🔗 Lier » à gauche du nom GED → ouvre le modal en reprenant l'entité liée.
  document.getElementById('btnGedLink').addEventListener('click', function(){ if(!cur) return;
    // RH : on lie un COLLABORATEUR (users), pas la cascade immo.
    if(cur.dataset.metierraw==='rh'){ openSeg('user'); return; }
    openCascade();
    var map={BIEN:'bien', IMB:'immeuble', BAIL:'bail', TIERS:'proprio'};
    var lvl=map[cur.dataset.enttype||'']; var eid=parseInt(cur.dataset.entid||'0',10);
    if(lvl && eid>0) setTimeout(function(){ cascPick(lvl, eid, cur.dataset.entlabel||''); }, 30); // seed = entité actuelle
    else { var g=document.getElementById('cascGlobal'); if(g) setTimeout(function(){ g.focus(); },40); }
  });
  // Chips Société / Agence / Métier de l'en-tête du modal cascade → pickers.
  document.getElementById('cascChipSoc').addEventListener('click', function(){ openSeg('societe'); });
  document.getElementById('cascChipAge').addEventListener('click', function(){ openSeg('agence'); });
  document.getElementById('cascChipMet').addEventListener('click', function(){ openSeg('metier'); });
  document.getElementById('segClose').addEventListener('click', function(){ document.getElementById('segModal').classList.remove('open'); });
  document.getElementById('segModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
  function refreshGed(){
    var out = document.getElementById('vGed');
    if(!gedBase){ out.textContent=''; return; }
    var m = gedBase.match(/^(.*)\.([^.]+)$/); var ext=m?m[2]:'pdf'; var body=m?m[1]:gedBase;
    var segs = body.split('_');
    if(segs.length>=11){
      var isRHg = document.getElementById('rhFields').style.display!=='none';
      // libellé pos 10 : RH = catégorie (+ libellé libre) ; sinon libellé seul.
      if(isRHg){ var cat=document.getElementById('fCategorie').value.toUpperCase().replace(/_/g,'-'); var lib=jsSlug(document.getElementById('fLibelle').value); segs[9]=cat+(lib?('-'+lib):''); }
      else segs[9] = jsSlug(document.getElementById('fLibelle').value);
      if(isRHg){                                                            // paye → MM-AAAA
        var mv = document.getElementById('fMois').value;
        if(mv) segs[8] = mv.substr(5,2)+'-'+mv.substr(0,4);
      } else {                                                               // autre → JJ-MM-AAAA
        var dv = document.getElementById('fDate').value; // yyyy-mm-dd
        if(dv) segs[8] = dv.substr(8,2)+'-'+dv.substr(5,2)+'-'+dv.substr(0,4);
      }
    }
    var html = segs.map(function(s,i){
        return '<span class="gseg" data-pos="'+i+'" title="'+(GSEG_TITLE[i]||'')+' — cliquer pour modifier">'
             + '<span class="gi">'+(GSEG_ICON[i]||'')+'</span>'
             + '<span class="gv">'+esc(s||'·')+'</span></span>';
      }).join('<span class="gsep">|</span>') + '<span class="gext">.'+esc(ext)+'</span>';
    out.innerHTML = html;
    out.classList.toggle('ged-dup', dupViewOn); // vue « en GED » → bandeau vert
    out.querySelectorAll('.gseg').forEach(function(sp){ sp.onclick=function(){ gedSegClick(parseInt(sp.dataset.pos,10)); }; });
  }

  // Aperçu d'un document de la pile (PDF / image / mail / autre).
  function setViewer(id, ext){
    var v = document.getElementById('viewer'); var url = FILE+'?id='+encodeURIComponent(id);
    ext = (ext||'').toLowerCase();
    var indispo = '<div class="empty">⚠ Fichier indisponible — le fichier n\'est pas (ou plus) sur le serveur.<br><span style="font-size:12px;color:#8a97a8">Doc conservé en base ; re-synchronise la boîte mail pour le récupérer, ou supprime-le si obsolète.</span></div>';
    if(ext==='pdf') v.innerHTML = '<iframe src="'+url+'"></iframe>';
    else if(['jpg','jpeg','png','gif','webp','bmp','tif','tiff'].indexOf(ext)!==-1){
      v.innerHTML = '<img id="mboImg" src="'+url+'">';
      var im=document.getElementById('mboImg'); if(im) im.onerror=function(){ v.innerHTML=indispo; };
    }
    else if(ext==='msg'||ext==='eml') v.innerHTML = '<iframe src="'+MAILVIEW+'?id='+encodeURIComponent(id)+'"></iframe>';
    else v.innerHTML = '<div class="empty">Aperçu non disponible pour .'+ext+' — <a href="'+url+'" target="_blank" style="color:#2a5da8;font-weight:600">ouvrir / télécharger ↗</a></div>';
  }
  function select(el){
    cur = el;
    // reset mode doublon : vue MBO par défaut, ligne toggle masquée.
    dupViewOn=false;
    var _b=document.getElementById('vGed'); if(_b) _b.classList.remove('ged-dup');
    var _db=document.getElementById('dupBar'); if(_db) _db.style.display='none';
    var _di=document.getElementById('dupInline'); if(_di){ _di.style.display='none'; _di.innerHTML=''; }
    setToggleState('mbo');
    items.forEach(i=>i.classList.remove('active')); el.classList.add('active');
    var id = el.dataset.id, ext = (el.dataset.ext||'').toLowerCase();
    setViewer(id, ext);
    // head
    var vt0=document.getElementById('vTitle'); vt0.textContent = el.dataset.nom || ''; vt0.title = el.dataset.nom || '';
    document.getElementById('vCrumb').innerHTML = (el.dataset.chain||'') || ('<span style="color:#9aa6b8">Aucune entité reconnue — recherche ci-dessous</span>');
    document.getElementById('entSearch').value=''; document.getElementById('entResults').innerHTML='';
    gedBase = el.dataset.gedname||''; refreshGed();
    // décider
    document.getElementById('decide').style.display='';
    // hint : réservé à l'IA / historique (plus de ligne « Proposition »)
    var ph = document.getElementById('pHint');
    ph.innerHTML = el.dataset.iaanalyse ? ('🧠 ' + esc(el.dataset.iaanalyse)) : '';
    showIaAction(el.dataset.iaactiontype, el.dataset.iaactionlabel, id);
    // bac suggéré
    document.querySelectorAll('.mbo .bac').forEach(function(b){ b.classList.toggle('suggest', b.dataset.bac===el.dataset.bacpropose); b.classList.remove('sel'); });
    // Reflet du statut courant (défaut « nouveau » = À classer sélectionné).
    var curStat = el.dataset.statut || 'nouveau';
    document.querySelectorAll('.mbo .statut .sbtn[data-statut]').forEach(function(b){ b.classList.toggle('on', b.dataset.statut===curStat); });
    // RH fields
    var isRH = (el.dataset.metierraw==='rh');
    document.getElementById('rhFields').style.display = isRH ? '' : 'none';
    document.getElementById('dateField').style.display = isRH ? 'none' : '';
    // Bouton « Créer le mandat » : bien/bail lié + doc de type mandat/contrat ou métier transaction.
    var canMandat = (el.dataset.enttype==='BIEN'||el.dataset.enttype==='BAIL') && (['mandat','contrat'].indexOf(el.dataset.typeraw||'')!==-1 || el.dataset.metierraw==='transaction');
    document.getElementById('mandatCard').style.display = canMandat ? '' : 'none';
    document.getElementById('mandatMsg').textContent='';
    if(isRH){ document.getElementById('fMois').value = el.dataset.refsalaire||''; document.getElementById('fCategorie').value = el.dataset.rhcat || ''; }
    else { document.getElementById('fDate').value = el.dataset.refdate||''; }
    document.getElementById('fLibelle').value = el.dataset.libelle||'';
    document.getElementById('fComment').value = el.dataset.commentaire||'';
    // Colonne droite + boutons d'action TOUJOURS visibles. Si déjà classé, on l'indique seulement.
    var vg = document.querySelector('.mbo .valid-grid'); if(vg) vg.style.display='';
    document.getElementById('decide').style.display='';
    if(el.dataset.classe){ var g=el.dataset.gedid;
      ph.innerHTML='✓ Classé le <b>'+esc(el.dataset.classe||'')+'</b>'+(g&&g!=='0'?' — <a href="'+GEDSERVE+'?id='+g+'" target="_blank" style="color:#2a5da8;font-weight:600">Ouvrir dans la GED ↗</a>':'');
    }
    // message + PJ soeurs
    loadMessage(id);
    // Doc DÉJÀ en GED (doublon) : on affiche la ligne toggle « en MBO / en GED » sous les boutons.
    dupViewOn=false;
    if((el.dataset.geddupid||'0')!=='0'){ document.getElementById('dupBar').style.display=''; }
    // alerte anti-doublon automatique (rouge/vert)
    dupCheck(el);
    // précharge les documents suivants (navigation instantanée)
    prefetchAround(el);
  }
  items.forEach(el=>el.addEventListener('click', function(e){
    if(e.target.classList.contains('pick')) return;
    select(el);
  }));
  // Toggle « en MBO / en GED » (sous les boutons d'action) : bascule vue + couleur + aperçu + nom.
  document.querySelectorAll('#dupToggle .dt-opt').forEach(function(opt){
    opt.addEventListener('click', function(){ if(cur) setDupView(cur, opt.dataset.view); });
  });
  // Positionne l'état visuel du toggle (violet=MBO / vert=GED).
  function setToggleState(view){
    document.querySelectorAll('#dupToggle .dt-opt').forEach(function(o){ o.classList.toggle('on', o.dataset.view===view); });
  }
  // Bascule la vue MBO ⇄ GED : aperçu + nom + couleur du bandeau, sans changer de page.
  // Le nom reste SEGMENTÉ et CLIQUABLE (zones → modals) dans les 2 vues (refreshGed).
  function setDupView(el, view){
    if((el.dataset.geddupid||'0')==='0') return;
    if(view==='ged'){
      dupViewOn=true; setToggleState('ged');
      document.getElementById('viewer').innerHTML='<iframe src="'+GEDSERVE+'?id='+encodeURIComponent(el.dataset.geddupid)+'"></iframe>';
      gedBase=el.dataset.geddupname||''; refreshGed();   // nom GED existant, segmenté + vert (dupViewOn)
    } else {
      dupViewOn=false; setToggleState('mbo');
      setViewer(el.dataset.id, (el.dataset.ext||'').toLowerCase());
      gedBase=el.dataset.gedname||''; refreshGed();       // nom proposé, segmenté + violet
    }
  }
  // « Je conserve celui-ci » : garde le doc AFFICHÉ (selon le toggle), supprime l'autre, sauve le nom.
  document.getElementById('btnKeepDup').addEventListener('click', function(){
    var el=cur; if(!el) return; var gid=el.dataset.geddupid; if(!gid||gid==='0') return;
    if(dupViewOn){
      // On garde le doc GED (renommé si les zones ont été modifiées) → le doublon quitte la pile.
      var nm=(gedBase||el.dataset.geddupname||'').trim();
      ajaxAction('action=dedup_keep&id='+encodeURIComponent(el.dataset.id)+'&ged_id='+encodeURIComponent(gid)+'&ged_name='+encodeURIComponent(nm))
        .then(function(){ topMsg.textContent='✓ Doc GED conservé — doublon de la pile retiré'; removeAndNext(el); })
        .catch(function(e){ topMsg.textContent='⚠ '+e; });
    } else {
      // On garde le doc de la PILE → on supprime l'ancien doc GED, puis on classe celui-ci.
      if(!confirm('Remplacer le document déjà en GED par celui-ci ?\nL\'ancien document GED sera supprimé.')) return;
      ajaxAction('action=ged_delete&ged_id='+encodeURIComponent(gid))
        .then(function(){ return fetch(CLASSER+'?doc='+encodeURIComponent(el.dataset.id),{credentials:'same-origin'}).then(r=>r.json()); })
        .then(function(d){ if(d&&d.ok){ topMsg.textContent='✓ Ancien GED remplacé — ce document classé'; removeAndNext(el); } else { topMsg.textContent='⚠ '+((d&&d.error)||'échec'); } })
        .catch(function(e){ topMsg.textContent='⚠ '+e; });
    }
  });

  function showIaAction(type,label,id){
    var box=document.getElementById('pIaAction');
    if(!type||type==='classer'){ box.style.display='none'; box.innerHTML=''; return; }
    var color = type==='relance'?'#c9821b':(type==='a_payer'?'#2e8b47':'#6b7890');
    box.style.display=''; box.innerHTML='<button class="btn" style="background:'+color+';color:#fff">'+esc(label||type)+'</button>';
    box.querySelector('button').onclick=function(){
      if(type==='relance'){ if(confirm('Envoyer une demande de précisions au fournisseur ?')) fetch(RELANCE+'?doc='+id,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){alert(d.ok?('✓ '+d.message):('⚠ '+(d.error||'échec')));}); }
      else if(type==='a_payer'){ submitForm('fStatutId','fStatutVal','a_payer',id); document.getElementById('fStatut').action=SELF+Q; document.getElementById('fStatut').submit(); }
    };
  }

  var curMsg=null;
  function loadMessage(id){
    var box=document.getElementById('vAttach'); box.style.display='none'; box.innerHTML=''; curMsg=null;
    fetch(MSG+'?doc='+encodeURIComponent(id),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      var m=d.message; if(!m) return; curMsg=m;
      var pre=document.getElementById('vCrumb'); pre.innerHTML = 'De <b>'+esc(m.from||'')+'</b>'+(m.subject?' · « '+esc(m.subject)+' »':'')+'<br>'+(pre.innerHTML||'');
      if(m.body){ box.style.display=''; box.innerHTML='<button type="button" class="achip" id="openMsg">✉️ Voir le message du mail</button>';
        document.getElementById('openMsg').onclick=openMsgModal; }
    }).catch(function(){});
  }
  function openMsgModal(){ if(!curMsg) return;
    document.getElementById('msgFrom').innerHTML='De <b>'+esc(curMsg.from||'')+'</b>'+(curMsg.subject?' — « '+esc(curMsg.subject)+' »':'');
    document.getElementById('msgBody').textContent=curMsg.body||'(message vide)';
    document.getElementById('msgModal').classList.add('open');
  }

  // ── Champ « Lier » → ouvre le modal cascade et passe la main à la recherche globale ──
  var entSearch=document.getElementById('entSearch');
  entSearch.addEventListener('input', function(){ var q=this.value.trim(); if(q.length<2) return;
    openCascade(); var g=document.getElementById('cascGlobal'); g.value=q; g.focus(); cascGlobalSearch(q);
  });
  function linkEntity(type,id,label){ if(!cur) return; var el=cur;
    fetch(SETENT+'?doc='+encodeURIComponent(el.dataset.id)+'&type='+encodeURIComponent(type)+'&id='+encodeURIComponent(id)+'&label='+encodeURIComponent(label),{credentials:'same-origin'})
      .then(r=>r.json()).then(function(d){ if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'échec liaison'); return; }
        el.dataset.chain=d.chain||''; el.dataset.gedname=d.gedname||''; el.dataset.metierraw=d.metier||el.dataset.metierraw; if(d.refsalaire) el.dataset.refsalaire=d.refsalaire;
        document.getElementById('vCrumb').innerHTML=d.chain||'';
        gedBase=d.gedname||''; refreshGed();
        var isRH=(el.dataset.metierraw==='rh'); document.getElementById('rhFields').style.display=isRH?'':'none'; if(isRH && el.dataset.refsalaire) document.getElementById('fMois').value=el.dataset.refsalaire;
        entResults.innerHTML=''; entSearch.value='';
      }).catch(function(e){ topMsg.textContent='⚠ '+e; });
  }

  // ── Sélecteur de TYPE (fixer le type manquant → nom GED complet) ────────────
  var typeData=null, typeCurMetier=null, typeAdding=false, typeSeed='', typeCodeTouched=false;
  function typeLabelOf(code){ if(!typeData) return code; var t=typeData.types.filter(function(x){return x.code===code;})[0]; return t?t.libelle:code; }
  function renderTypeTabs(){ var tabs=document.getElementById('typeTabs'); if(!typeData) return;
    tabs.innerHTML = typeData.metiers.map(function(m){ return '<button type="button" class="tym-tab'+(m.key===typeCurMetier?' on':'')+'" data-mkey="'+m.key+'">'+esc(m.label)+'</button>'; }).join('');
    tabs.querySelectorAll('[data-mkey]').forEach(function(b){ b.onclick=function(){ typeCurMetier=b.dataset.mkey; typeAdding=false; var s=document.getElementById('typeSearch'); if(s) s.value=''; renderTypeTabs(); renderTypeGrid(); }; });
  }
  function renderTypeGrid(){ var grid=document.getElementById('typeGrid'); if(!typeData) return;
    var qraw=((document.getElementById('typeSearch')||{}).value||'').trim(), q=qraw.toLowerCase();
    var codes;
    if(q){ codes = typeData.types.filter(function(t){ return t.libelle.toLowerCase().indexOf(q)!==-1 || t.code.toLowerCase().indexOf(q)!==-1; }).map(function(t){return t.code;}); }
    else { var m=typeData.metiers.filter(function(x){return x.key===typeCurMetier;})[0]; codes = m?m.codes.slice():[]; }
    var seen={}, items=codes.filter(function(c){ if(seen[c])return false; seen[c]=1; return typeData.types.some(function(t){return t.code===c;}); });
    var cards = items.length ? items.map(function(c){ return '<button type="button" class="tym-card" data-code="'+c+'">'+esc(typeLabelOf(c))+'</button>'; }).join('')
                             : (q?'<div class="msg" style="grid-column:1/-1;padding:8px;text-align:center;color:#8a97a0">Aucun type existant — créez-le ci-dessous.</div>':'');
    var tail = typeAdding ? typeAddFormHtml(typeSeed)
                          : '<button type="button" class="tym-card tym-add" id="typeAdd">＋ Ajouter'+(qraw?' « '+esc(qraw)+' »':' un type')+'</button>';
    grid.innerHTML = cards + tail;
    grid.querySelectorAll('[data-code]').forEach(function(b){ b.onclick=function(){ setType(b.dataset.code); }; });
    var add=document.getElementById('typeAdd'); if(add) add.onclick=function(){ typeAdding=true; typeSeed=qraw; typeCodeTouched=false; renderTypeGrid(); };
    if(typeAdding) wireTypeAddForm();
  }
  function typeAddFormHtml(seed){
    return '<div class="tym-form" style="grid-column:1/-1">'
      +'<label class="tym-flab">Nouveau type — métier « '+esc(typeCurMetier)+' »</label>'
      +'<input id="tymLib" class="tym-fin" placeholder="Libellé (ex. Diagnostic amiante)" autocomplete="off" value="'+esc(seed||'')+'">'
      +'<label class="tym-flab" style="margin-top:4px">Code (identifiant stable, max 24 car.)</label>'
      +'<input id="tymCode" class="tym-fin tym-fcodein" placeholder="CODE" autocomplete="off" maxlength="24" value="'+esc(mboCode(seed||''))+'">'
      +'<label class="tym-flab" style="margin-top:4px">Abrév. GED — nom court affiché (optionnel, ex. EDLS)</label>'
      +'<input id="tymAbbr" class="tym-fin tym-fcodein" placeholder="(vide = code)" autocomplete="off" maxlength="16">'
      +'<div id="tymDup" class="tym-fdup"></div>'
      +'<div class="tym-fbtns"><button type="button" class="tym-fcancel" id="tymCancel">Annuler</button>'
      +'<button type="button" class="tym-fok" id="tymOk">Valider</button></div></div>';
  }
  function wireTypeAddForm(){
    var inp=document.getElementById('tymLib'), cd=document.getElementById('tymCode'), dp=document.getElementById('tymDup'), ok=document.getElementById('tymOk');
    function upd(){ var lib=inp.value.trim(), code=(cd.value||'').trim().toUpperCase();
      var dup = code?mboDupFind(lib,code):null;
      if(dup){ dp.innerHTML='⚠ Doublon : « '+esc(dup.libelle)+' » ('+esc(dup.code)+')'+(dup.where.length?' — déjà dans '+esc(dup.where.join(', ')):''); dp.className='tym-fdup on'; ok.disabled=true; }
      else if(!lib||!code){ dp.textContent=''; dp.className='tym-fdup'; ok.disabled=true; }
      else { dp.textContent='✓ Disponible'; dp.className='tym-fdup okc'; ok.disabled=false; } }
    inp.addEventListener('input', function(){ if(!typeCodeTouched){ cd.value=mboCode(inp.value); } upd(); });
    cd.addEventListener('input', function(){ typeCodeTouched=true; cd.value=mboCode(cd.value); upd(); });
    inp.addEventListener('keydown', function(e){ if(e.key==='Enter'&&!ok.disabled) ok.click(); });
    document.getElementById('tymCancel').onclick=function(){ typeAdding=false; renderTypeGrid(); };
    var ab=document.getElementById('tymAbbr');
    if(ab){ ab.addEventListener('input', function(){ ab.value=(ab.value||'').toUpperCase().replace(/[^A-Z0-9]/g,''); }); }
    ok.onclick=function(){ var lib=inp.value.trim(), code=(cd.value||'').trim(), abbr=ab?(ab.value||'').trim():''; if(!lib||!code) return; ok.disabled=true;
      fetch(SETTYPE+'?action=add&libelle='+encodeURIComponent(lib)+'&code='+encodeURIComponent(code)+'&abbr='+encodeURIComponent(abbr)+'&metier='+encodeURIComponent(typeCurMetier),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
        typeAdding=false; typeData=null;
        fetch(SETTYPE+'?list=1',{credentials:'same-origin'}).then(r=>r.json()).then(function(dd){ if(dd.ok){ typeData=dd; renderTypeTabs(); renderTypeGrid(); if(d.code) setType(d.code); } });
      }).catch(function(e){ dp.textContent='⚠ '+e; dp.className='tym-fdup on'; ok.disabled=false; });
    };
    upd();
  }
  // Normalisation code = MÊME règle que le serveur (MAJ, accents retirés, non-alphanum → _, plafond 24).
  function mboCode(s){ s=(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toUpperCase().replace(/[^A-Z0-9]+/g,'_').replace(/^_+|_+$/g,''); if(s.length>24) s=s.slice(0,24).replace(/_+$/,''); return s; }
  function mboDupFind(lib, code){ if(!typeData) return null; var C=(code||'').trim().toUpperCase(), L=(lib||'').trim().toLowerCase();
    var hit=typeData.types.filter(function(t){ return (C&&t.code.toUpperCase()===C) || (L&&(t.libelle||'').trim().toLowerCase()===L); })[0]||null;
    if(!hit) return null; var where=typeData.metiers.filter(function(m){return m.codes.indexOf(hit.code)!==-1;}).map(function(m){return m.label;});
    return {code:hit.code, libelle:hit.libelle, where:where}; }
  function openTypeModal(){ if(!cur) return; typeAdding=false; typeCodeTouched=false; document.getElementById('typeDoc').textContent=cur.dataset.nom||''; var ts=document.getElementById('typeSearch'); if(ts) ts.value='';
    var open=function(){ // métier par défaut = celui du doc s'il existe dans les onglets, sinon 1er
      var keys=typeData.metiers.map(function(m){return m.key;});
      typeCurMetier = (keys.indexOf(cur.dataset.metierraw)!==-1) ? cur.dataset.metierraw : keys[0];
      renderTypeTabs(); renderTypeGrid();
    };
    if(typeData) open(); else { document.getElementById('typeGrid').innerHTML='<span class="msg">Chargement…</span>'; fetch(SETTYPE+'?list=1',{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(d.ok){ typeData=d; open(); } }); }
    document.getElementById('typeModal').classList.add('open');
  }
  function setType(code){ if(!cur) return; var el=cur;
    fetch(SETTYPE+'?doc='+encodeURIComponent(el.dataset.id)+'&type='+encodeURIComponent(code),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'échec'); return; }
      el.dataset.gedname=d.gedname; el.dataset.typelabel=d.type_label; gedBase=d.gedname; refreshGed();
      document.getElementById('typeModal').classList.remove('open');
    }).catch(function(e){ topMsg.textContent='⚠ '+e; });
  }
  document.getElementById('typeClose').addEventListener('click', function(){ document.getElementById('typeModal').classList.remove('open'); });
  document.getElementById('typeSearch').addEventListener('input', function(){ typeAdding=false; renderTypeGrid(); });
  document.getElementById('typeModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });

  // ── Anti-doublon : ALERTE automatique (rouge = doublon, vert = unique) ──────
  function dupColor(el, d){ var b=document.getElementById('btnDup'); var n=(d&&d.ok&&d.matches)?d.matches.length:0;
    b.classList.remove('dup-red','dup-green'); b.classList.add(n>0?'dup-red':'dup-green');
    b.textContent = n>0 ? ('⧉ Doublon ! ('+n+')') : '✓ Unique';
    // Bandeau AUTO : affiche le NOM du/des doublon(s) + suppression rapide (décision immédiate).
    var bar=document.getElementById('dupInline'); if(!bar) return;
    if(cur!==el || n===0){ bar.style.display='none'; bar.innerHTML=''; return; }
    var names=d.matches.map(function(m){ var loc=m.classe?('déjà en GED le '+esc(m.classe)):('dans l\'inbox'+(m.quand?(' ('+esc(m.quand)+')'):'')); return '<b>'+esc(m.nom||'—')+'</b> <span style="color:#a55">— '+loc+'</span>'; }).join('<br>');
    bar.innerHTML='⚠ <b>Ce fichier existe déjà</b> ('+n+') :<br>'+names
      +'<button type="button" id="dupInlineDel" style="margin-top:8px;width:100%;padding:7px;border:1px solid #c0492f;background:#fff;color:#c0492f;border-radius:8px;font-weight:800;cursor:pointer">🗑 Supprimer ce doublon</button>';
    bar.style.display='';
    document.getElementById('dupInlineDel').onclick=function(){ ajaxAction('action=delete&id='+encodeURIComponent(el.dataset.id)).then(function(){ removeAndNext(el); }); };
  }
  function dupCheck(el){ el._dup=null; var b=document.getElementById('btnDup'); b.classList.remove('dup-red','dup-green'); b.textContent='⧉ Vérif…';
    fetch(DOUBLON+'?doc='+encodeURIComponent(el.dataset.id),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(cur!==el) return; el._dup=d; dupColor(el,d); }).catch(function(){});
  }
  function dupShow(el){ var d=el._dup; if(!d) return; document.getElementById('dupDoc').textContent=el.dataset.nom||''; var body=document.getElementById('dupBody');
    if(!d.ok){ body.innerHTML='⚠ '+esc(d.error||'erreur'); }
    else if(!d.matches.length){ body.innerHTML='<div style="color:#2e8b47;font-weight:600;font-size:14px">✅ Aucun doublon détecté — ce document est unique.</div>'; }
    else { body.innerHTML='<div style="color:#c0492f;font-weight:700;margin-bottom:10px">⚠ '+d.matches.length+' correspondance(s) trouvée(s)</div>'
        + d.matches.map(function(m){ var loc=m.classe?('déjà classé en GED le '+esc(m.classe)):('dans l\'inbox ('+esc(m.quand)+')');
            var link=m.ged_id?(' · <a href="'+GEDSERVE+'?id='+m.ged_id+'" target="_blank" style="color:#2a5da8">ouvrir GED ↗</a>'):'';
            return '<div style="border:1px solid #e3e8ef;border-radius:8px;padding:8px 10px;margin-bottom:6px"><b>'+esc(m.nom)+'</b><br><span style="font-size:12px;color:#6b7890">'+esc(m.motif)+' — '+loc+link+'</span></div>'; }).join('')
        + '<button type="button" class="btn btn-danger" id="dupDelete" style="margin-top:8px;width:100%;justify-content:center">🗑 C\'est un doublon — supprimer ce document</button>';
      document.getElementById('dupDelete').onclick=function(){ ajaxAction('action=delete&id='+encodeURIComponent(el.dataset.id)).then(function(){ document.getElementById('dupModal').classList.remove('open'); removeAndNext(el); }); };
    }
    document.getElementById('dupModal').classList.add('open');
  }
  document.getElementById('btnDup').addEventListener('click', function(){ if(cur) dupShow(cur); });
  document.getElementById('dupClose').addEventListener('click', function(){ document.getElementById('dupModal').classList.remove('open'); });
  document.getElementById('dupModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });

  // ── Barre « Transférer les factures à CEGID » (par société) ─────────────────
  var comptaSocs=[];
  function loadCompta(){ var bar=document.getElementById('cbBtns'); if(!bar) return;
    fetch(COMPTA+'?action=list',{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok){ bar.innerHTML='<span class="msg">⚠ '+esc(d.error||'erreur')+' — migration mbo_comptable passée ?</span>'; return; }
      comptaSocs=d.societes||[];
      if(!comptaSocs.length){ bar.innerHTML='<span class="msg">Aucune facture à transférer.</span>'; return; }
      bar.innerHTML=comptaSocs.map(function(s){
        var conf=s.email?('→ '+esc(s.email)):'⚠ adresse CEGID à configurer (clic)';
        return '<button type="button" class="cb-send" data-soc="'+s.id_societe+'">📥 '+esc(s.label)+' <b>('+(s.pending||0)+')</b><small>'+conf+'</small></button>';
      }).join('');
      bar.querySelectorAll('.cb-send').forEach(function(b){ b.onclick=function(){ var s=comptaSocs.filter(function(x){return String(x.id_societe)===b.dataset.soc;})[0]; comptaSend(s); }; });
    }).catch(function(){ bar.innerHTML='<span class="msg">Erreur réseau.</span>'; });
  }
  function comptaConfig(soc, cb){ var email=prompt('Adresse de dépôt CEGID (mail2box) pour cette société :'); if(!email) return;
    fetch(COMPTA+'?action=set&id_societe='+encodeURIComponent(soc)+'&email='+encodeURIComponent(email),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(d.ok){ loadCompta(); if(cb) cb(); } else alert('⚠ '+(d.error||'échec')); });
  }
  function comptaConfigPick(){ if(!comptaSocs.length){ alert('Aucune société avec factures.'); return; } comptaConfig(comptaSocs[0].id_societe); }
  function comptaSend(s){ if(!s) return;
    if(!s.email){ comptaConfig(s.id_societe); return; }
    if(!confirm('Déposer les '+(s.pending||0)+' facture(s) de '+s.label+' dans CEGID ('+s.email+') ?')) return;
    fetch(COMPTA+'?action=send&id_societe='+encodeURIComponent(s.id_societe),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(d.ok){ alert('✓ '+d.envoyes+' facture(s) déposée(s) dans CEGID · '+(d.classes||0)+' classée(s) en GED'); location.reload(); } else alert('⚠ '+(d.error||'échec'));
    }).catch(function(e){ alert('⚠ '+e); });
  }
  if(document.getElementById('comptaBar')){ loadCompta(); var cfg=document.getElementById('cbConfig'); if(cfg) cfg.onclick=function(){ comptaConfigPick(); }; }

  // ── Modal « Éduquer l'IA » (base de règles) ────────────────────────────────
  var regModal=document.getElementById('regModal');
  function loadRegles(){ fetch(REGLES,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
    var box=document.getElementById('regList'); if(!d.ok){ box.innerHTML='<div class="casc-empty">Erreur (migration exécutée ?)</div>'; return; }
    if(!d.regles.length){ box.innerHTML='<div class="casc-empty">Aucune règle. Ajoute ton premier réflexe ci-dessus.</div>'; return; }
    box.innerHTML=d.regles.map(function(r){ var on=r.actif==1;
      return '<div style="display:flex;gap:8px;align-items:center;border:1px solid #e3e8ef;border-radius:8px;padding:8px 10px;'+(on?'':'opacity:.5')+'">'
        +'<button type="button" class="btn btn-ghost" data-toggle="'+r.id+'" style="padding:3px 8px" title="Activer/désactiver">'+(on?'✅':'⬜')+'</button>'
        +'<span style="flex:1;font-size:13px">'+esc(r.regle)+'</span>'
        +'<button type="button" class="btn btn-ghost" data-del="'+r.id+'" style="padding:3px 8px;color:#c0492f">🗑</button></div>';
    }).join('');
    box.querySelectorAll('[data-toggle]').forEach(function(b){ b.onclick=function(){ fetch(REGLES+'?action=toggle&id='+b.dataset.toggle,{credentials:'same-origin'}).then(()=>loadRegles()); }; });
    box.querySelectorAll('[data-del]').forEach(function(b){ b.onclick=function(){ fetch(REGLES+'?action=delete&id='+b.dataset.del,{credentials:'same-origin'}).then(()=>loadRegles()); }; });
  }); }
  function addRegle(){ var i=document.getElementById('regInput'), v=i.value.trim(); if(v.length<3) return;
    fetch(REGLES+'?action=add&regle='+encodeURIComponent(v),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(d.ok){ i.value=''; loadRegles(); } else topMsg.textContent='⚠ '+(d.error||'échec'); }); }
  document.getElementById('btnRegles').addEventListener('click', function(){ regModal.classList.add('open'); loadRegles(); });
  document.getElementById('regClose').addEventListener('click', function(){ regModal.classList.remove('open'); });
  regModal.addEventListener('click', function(e){ if(e.target===regModal) regModal.classList.remove('open'); });
  document.getElementById('regAdd').addEventListener('click', addRegle);
  document.getElementById('regInput').addEventListener('keydown', function(e){ if(e.key==='Enter') addRegle(); });

  // ── Modal cascade (4 colonnes liées) ───────────────────────────────────────
  var cascModal=document.getElementById('cascModal'), cascSel=null; // {level,id,label}
  var COLLABEL={proprio:'propriétaire',immeuble:'immeuble',bien:'bien',bail:'bail'};
  function openCascade(focusCol){ if(!cur) return; if(typeof photoModeReset==='function') photoModeReset(); cascSel=null; document.getElementById('cascDoc').textContent=cur.dataset.nom||'';
    document.getElementById('cascSel').textContent='Sélectionne une entité dans une colonne…'; document.getElementById('cascLink').disabled=true;
    document.getElementById('cascSoc').textContent='';
    // Chips = valeurs actuelles du doc (métier), société/agence se rempliront à la sélection.
    var metMap={rh:'RH',gestion:'Gestion',syndic:'Syndic',transaction:'Transaction',compta:'Compta',fournisseur:'Fournisseur'};
    document.getElementById('cascChipSoc').textContent='🏢 Société';
    document.getElementById('cascChipAge').textContent='🏢 Agence';
    document.getElementById('cascChipMet').textContent='🗂️ '+(metMap[cur.dataset.metierraw]||'Métier');
    document.querySelectorAll('.casc-list').forEach(function(l){ l.innerHTML='<div class="casc-empty">Recherche ou clic dans une autre colonne…</div>'; });
    document.querySelectorAll('.casc-search').forEach(function(s){ s.value=''; });
    cascModal.classList.add('open');
    if(focusCol){ var fs=document.querySelector('.casc-search[data-col="'+focusCol+'"]'); if(fs) setTimeout(function(){ fs.focus(); },40); }
    // amorce : si une entité est déjà liée on part d'elle, sinon rien.
  }
  function closeCascade(){ cascModal.classList.remove('open'); if(typeof photoModeReset==='function') photoModeReset(); }
  document.getElementById('cascClose').addEventListener('click', closeCascade);
  cascModal.addEventListener('click', function(e){ if(e.target===cascModal) closeCascade(); });

  function cascRender(col, items){ var box=document.querySelector('.casc-list[data-col="'+col+'"]'); if(!box) return;
    if(!items || !items.length){ box.innerHTML='<div class="casc-empty">—</div>'; return; }
    box.innerHTML=items.map(function(it){ return '<div class="casc-item" data-col="'+col+'" data-id="'+it.id+'" data-label="'+esc(it.label||'')+'">'+esc(it.label||'')+(it.sub?'<span class="s">'+esc(String(it.sub))+'</span>':'')+'</div>'; }).join('');
    box.querySelectorAll('.casc-item').forEach(function(el){ el.onclick=function(){
      // Mode PHOTOS EN LOT : clic bien/bail → SÉLECTIONNE la cible (l'enregistrement se fait au bouton Valider).
      if(photoBatchMode){ var EMAP={proprio:'PROP',immeuble:'IMB',bien:'BIEN',bail:'BAIL',tiers:'TIERS'};
        photoSelectTarget(EMAP[col]||col.toUpperCase(), el.dataset.id, el.dataset.label); cascMarkSelectedPhoto(el); return; }
      // Bien / Bail / Tiers = liaison DIRECTE ; Propriétaire / Immeuble = cascade.
      if(col==='bien'||col==='bail'){ linkEntity(col, el.dataset.id, el.dataset.label); closeCascade(); }
      else if(col==='tiers'){ linkEntity('trs', el.dataset.id, el.dataset.label); closeCascade(); }
      else if(col==='personnel'){ linkEntity('user', el.dataset.id, el.dataset.label); closeCascade(); }  // collaborateur → EMP/RH
      else cascPick(col, el.dataset.id, el.dataset.label);
    }; });
  }
  function cascMarkSelected(){ document.querySelectorAll('.casc-item').forEach(function(el){ el.classList.toggle('on', cascSel && el.dataset.col===cascSel.level && el.dataset.id==cascSel.id); }); }
  function cascPick(level, id, label){ cascSel={level:level,id:id,label:label};
    document.getElementById('cascSel').innerHTML='Lier au '+COLLABEL[level]+' : <b>'+esc(label)+'</b>';
    document.getElementById('cascLink').disabled=false;
    fetch(CASCADE+'?level='+encodeURIComponent(level)+'&id='+encodeURIComponent(id),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok) return;
      // Affiche société + agence + métier DE L'ENTITÉ sélectionnée (modifiables via clic).
      document.getElementById('cascChipSoc').textContent = '🏢 ' + (d.societe || 'Société');
      document.getElementById('cascChipAge').textContent = '🏢 ' + (d.agence || 'Agence');
      document.getElementById('cascChipMet').textContent = '🗂️ ' + (d.metier_label || 'Métier');
      cascRender('proprio', d.proprio?[d.proprio]:[]);
      cascRender('immeuble', d.immeubles||[]);
      cascRender('bien', d.biens||[]);
      cascRender('bail', d.baux||[]);
      cascMarkSelected();
    });
  }
  document.querySelectorAll('.casc-search').forEach(function(inp){ var t=null; inp.addEventListener('input', function(){ var col=inp.dataset.col, q=inp.value.trim(); clearTimeout(t); if(q.length<2){ return; }
    t=setTimeout(function(){ fetch(CASCADE+'?col='+encodeURIComponent(col)+'&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(d.ok) cascRender(col, d.items); cascMarkSelected(); }); }, 250);
  }); });
  // Recherche globale : un mot → réparti dans les 4 colonnes.
  function cascGlobalSearch(q){ if(q.length<2) return;
    fetch(CASCADE+'?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(!d.ok) return;
      cascRender('proprio', d.proprio||[]); cascRender('immeuble', d.immeubles||[]); cascRender('bien', d.biens||[]); cascRender('bail', d.baux||[]); cascRender('tiers', d.tiers||[]); cascRender('personnel', d.personnel||[]); cascMarkSelected();
      // Auto-cascade depuis le match le plus PRÉCIS (bail > bien > immeuble > propriétaire)
      // → remplit propriétaire / immeuble / bien / bail + chips société / agence / métier.
      var pk = (d.baux&&d.baux[0]) ? ['bail',d.baux[0]]
             : (d.biens&&d.biens[0]) ? ['bien',d.biens[0]]
             : (d.immeubles&&d.immeubles[0]) ? ['immeuble',d.immeubles[0]]
             : (d.proprio&&d.proprio[0]) ? ['proprio',d.proprio[0]] : null;
      if(pk){ cascPick(pk[0], pk[1].id, pk[1].label); }
    });
  }
  var cascGlobal=document.getElementById('cascGlobal'), cgt=null;
  cascGlobal.addEventListener('input', function(){ var q=this.value.trim(); clearTimeout(cgt); cgt=setTimeout(function(){ cascGlobalSearch(q); }, 250); });
  function validateLink(){ if(!cascSel || !cur) return; linkEntity(cascSel.level, cascSel.id, cascSel.label); closeCascade(); }
  cascGlobal.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); validateLink(); } });
  entSearch.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); validateLink(); } });
  document.getElementById('cascLink').addEventListener('click', validateLink);

  // libellé / mois live → nom GED
  function gedAndSave(){ refreshGed(); autoSaveMeta(); }
  document.getElementById('fLibelle').addEventListener('input', gedAndSave);
  document.getElementById('fMois').addEventListener('input', gedAndSave);
  document.getElementById('fDate').addEventListener('input', gedAndSave);
  // valeur courante de la date de référence (paye = mois ; sinon date complète)
  function refDateParam(){ return document.getElementById('rhFields').style.display!=='none' ? document.getElementById('fMois').value : document.getElementById('fDate').value; }

  // bacs — orienter puis passer au suivant direct (ajax, sans reload)
  document.querySelectorAll('.mbo .bac').forEach(function(b){ b.addEventListener('click', function(){
    if(!cur) return; var el=cur; b.classList.add('sel');
    var lib=document.getElementById('fLibelle').value, com=document.getElementById('fComment').value, rd=refDateParam();
    // on fige aussi libellé/commentaire/date → à la reprise le nom GED proposé revient tel quel.
    ajaxAction('action=bac&id='+encodeURIComponent(el.dataset.id)+'&bac='+encodeURIComponent(b.dataset.bac)+'&libelle='+encodeURIComponent(lib)+'&commentaire='+encodeURIComponent(com)+'&refdate='+encodeURIComponent(rd))
      .then(function(){ el.dataset.libelle=lib; el.dataset.commentaire=com; advance(el); }).catch(function(){ advance(el); });
  }); });
  // statut
  document.querySelectorAll('.mbo .sbtn').forEach(function(b){ b.addEventListener('click', function(){
    if(!cur || !b.dataset.statut) return; var el=cur;
    el.dataset.statut = b.dataset.statut;   // reflet immédiat (badge/état au retour)
    ajaxAction('action=statut&id='+encodeURIComponent(el.dataset.id)+'&statut='+encodeURIComponent(b.dataset.statut))
      .then(function(){ advance(el); }).catch(function(){ advance(el); });
  }); });
  // save meta
  // Sauvegarde AUTOMATIQUE (débouncée) à la saisie — plus de bouton Enregistrer.
  var saveTimer=null;
  function autoSaveMeta(){ if(!cur) return; var el=cur; clearTimeout(saveTimer); saveTimer=setTimeout(function(){
    var lib=document.getElementById('fLibelle').value, com=document.getElementById('fComment').value, rd=refDateParam();
    var isRH=document.getElementById('rhFields').style.display!=='none'; var cat=isRH?document.getElementById('fCategorie').value:'';
    ajaxAction('action=savemeta&id='+encodeURIComponent(el.dataset.id)+'&libelle='+encodeURIComponent(lib)+'&commentaire='+encodeURIComponent(com)+'&refdate='+encodeURIComponent(rd)+'&categorie='+encodeURIComponent(cat))
      .then(function(){ el.dataset.libelle=lib; el.dataset.commentaire=com; if(cat) el.dataset.rhcat=cat; if(rd){ el.dataset.refdate=(rd.length===7?rd+'-01':rd); el.dataset.refsalaire=rd.substr(0,7); } topMsg.textContent='✓ enregistré'; })
      .catch(function(){});
  }, 600); }
  document.getElementById('fComment').addEventListener('input', autoSaveMeta);
  document.getElementById('fCategorie').addEventListener('change', function(){ refreshGed(); autoSaveMeta(); });
  // Supprimer — sans confirmation (gain de temps), ajax + passe au suivant.
  document.getElementById('btnDelete').addEventListener('click', function(){ if(!cur) return;
    var el=cur, b=this; b.disabled=true;
    ajaxAction('action=delete&id='+encodeURIComponent(el.dataset.id))
      .then(function(){ b.disabled=false; removeAndNext(el); })
      .catch(function(e){ b.disabled=false; topMsg.textContent='⚠ '+e; });
  });
  // Valider & classer en GED — sans confirmation ni message de réalisé ; passe au suivant.
  document.getElementById('btnClasser').addEventListener('click', function(){ if(!cur) return;
    var el=cur, b=this; b.disabled=true;
    // on enregistre d'abord libellé/commentaire saisis, puis on classe.
    var lib=document.getElementById('fLibelle').value, com=document.getElementById('fComment').value;
    var doClass=function(){ var p='?doc='+encodeURIComponent(el.dataset.id);
      if(el.dataset.metierraw==='rh'){ p+='&categorie='+encodeURIComponent(document.getElementById('fCategorie').value); var mv=document.getElementById('fMois').value; if(mv) p+='&mois='+encodeURIComponent(mv+'-01'); }
      fetch(CLASSER+p,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ b.disabled=false;
        if(d.ok){ removeAndNext(el); } else { topMsg.textContent='⚠ '+(d.error||'échec classement'); }
      }).catch(function(e){ b.disabled=false; topMsg.textContent='⚠ '+e; });
    };
    // fige libellé + commentaire + date de référence AVANT de classer.
    ajaxAction('action=savemeta&id='+encodeURIComponent(el.dataset.id)+'&libelle='+encodeURIComponent(lib)+'&commentaire='+encodeURIComponent(com)+'&refdate='+encodeURIComponent(refDateParam())).then(doClass).catch(doClass);
  });
  // Mail : ouvre le composeur générique avec le document courant en pièce jointe.
  var MAILCOMPOSE = <?= json_encode(app_url('/mail_compose.php')) ?>;
  document.getElementById('btnMail').addEventListener('click', function(){ if(!cur) return;
    var url = MAILCOMPOSE+'?ctx=MBO&id='+encodeURIComponent(cur.dataset.id)+'&back='+encodeURIComponent('maboxoffice.php'+Q);
    window.open(url, '_blank', 'noopener');
  });
  // ré-analyse OCR (scan → Mindee async → on attend le texte puis on reconnaît)
  document.getElementById('btnReana').addEventListener('click', function(){ if(!cur) return;
    var b=this; b.disabled=true; topMsg.textContent='Ré-analyse…';
    function poll(n){ fetch(POLL,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(d.ok && d.pending>0 && n<30){ topMsg.textContent='OCR scan (Mindee)… '+d.pending+' en attente'; setTimeout(function(){ poll(n+1); }, 4000); }
      else { b.disabled=false; location.reload(); }
    }).catch(function(){ b.disabled=false; location.reload(); }); }
    fetch(ANA+'?doc='+encodeURIComponent(cur.dataset.id)+'&force=1',{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok){ b.disabled=false; topMsg.textContent='⚠ '+(d.error||'échec'); return; }
      if(d.stats && d.stats.mindee_soumis>0){ topMsg.textContent='Scan envoyé à Mindee, récupération…'; setTimeout(function(){ poll(0); }, 3000); }
      else location.reload();
    }).catch(function(e){ b.disabled=false; topMsg.textContent='⚠ '+e; });
  });
  // sync IMAP (boîte choisie dans la liste, ou toutes si vide)
  var IMAPSYNC = <?= json_encode(app_url('/api/maboxoffice_imap_sync.php')) ?>;
  document.getElementById('syncBtn').addEventListener('click', function(){ var b=this; var box=document.getElementById('syncBox').value; var days=document.getElementById('syncDays').value||90;
    b.disabled=true; topMsg.textContent='Synchronisation…';
    fetch(IMAPSYNC+(box?('?box_id='+encodeURIComponent(box)):'?')+'&days='+encodeURIComponent(days),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ b.disabled=false;
      if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'erreur'); return; }
      var imp=0,dej=0,bru=0,err=0; (d.boxes||[]).forEach(function(x){ if(x.stats){ imp+=x.stats.importes||0; dej+=x.stats.deja||0; bru+=x.stats.bruit||0; err+=x.stats.erreurs||0; } });
      topMsg.textContent='✓ '+imp+' importés · '+dej+' déjà · '+bru+' bruit'+(err?(' · '+err+' err'):'');
      if(imp>0) setTimeout(()=>location.reload(),900);
    }).catch(e=>{ b.disabled=false; topMsg.textContent='⚠ '+e; });
  });
  // analyser OCR (boucle)
  document.getElementById('anaBtn').addEventListener('click', function(){ var b=this; b.disabled=true;
    function pollLoop(){ fetch(POLL,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'erreur OCR cloud'); b.disabled=false; return; }
      topMsg.textContent='OCR scans (Mindee)… '+d.pending+' en attente';
      if(d.pending>0) setTimeout(pollLoop, 4000); else { topMsg.textContent='✓ Analyse terminée'; setTimeout(()=>location.reload(),700); }
    }).catch(e=>{ topMsg.textContent='⚠ '+e; b.disabled=false; }); }
    function anaLoop(){ fetch(ANA+'?limit=15',{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'erreur OCR'); b.disabled=false; return; }
      topMsg.textContent='OCR gratuit… reste '+d.reste+(d.pending_mindee?(' · '+d.pending_mindee+' scans→Mindee'):'');
      if(d.reste>0) anaLoop();
      else if(d.pending_mindee>0){ topMsg.textContent='Scans envoyés à Mindee, récupération…'; setTimeout(pollLoop, 3000); }
      else { topMsg.textContent='✓ Analyse terminée'; setTimeout(()=>location.reload(),700); }
    }).catch(e=>{ topMsg.textContent='⚠ '+e; b.disabled=false; }); }
    anaLoop();
  });
  // batch
  var picks=document.querySelectorAll('.mbo .pick'), batch=document.getElementById('batch'), bN=document.getElementById('batchN');
  function pickedIds(){ return [...picks].filter(p=>p.checked).map(p=>p.closest('.item').dataset.id); }
  function refreshBatch(){ var n=pickedIds().length; bN.textContent=n; batch.style.display=n>0?'flex':'none'; }
  picks.forEach(p=>p.addEventListener('change',refreshBatch));
  function batchStatut(val){ var ids=pickedIds(); if(!ids.length) return; var f=document.createElement('form'); f.method='post'; f.action=SELF+Q; f.innerHTML='<input name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input name="action" value="statut"><input name="statut" value="'+val+'">'+ids.map(i=>'<input name="ids[]" value="'+i+'">').join(''); document.body.appendChild(f); f.submit(); }
  document.getElementById('batchCancel').onclick=function(){ picks.forEach(p=>p.checked=false); refreshBatch(); };

  // ── PHOTOS EN LOT : coche des photos → modal « Photos » (sélection cible + libellé groupe + Valider) ──
  var photoBatchMode=false, photoBatchIds=[], photoTarget=null;
  var photoSaveBtn=document.getElementById('photoSave'), photoLabelInp=document.getElementById('photoGroupLabel');
  function pickedPhotoEls(){ return [...picks].filter(p=>p.checked).map(p=>p.closest('.item'))
      .filter(el=>IMG_EXT.indexOf((el.dataset.ext||'').toLowerCase())!==-1); }
  function photoSaveRefresh(){ photoSaveBtn.disabled = !(photoTarget && photoLabelInp.value.trim()); }
  photoLabelInp.addEventListener('input', photoSaveRefresh);
  document.getElementById('batchPhotos').onclick=function(){
    var pickedAll=[...picks].filter(p=>p.checked), photos=pickedPhotoEls();
    if(!photos.length){ topMsg.textContent='⚠ Coche au moins une photo (JPG/PNG).'; return; }
    if(photos.length!==pickedAll.length && !confirm('Certains éléments cochés ne sont pas des photos et seront ignorés. Continuer ?')) return;
    photoBatchIds=photos.map(el=>el.dataset.id); photoTarget=null;
    openCascade('bien');
    photoBatchMode=true; // APRÈS openCascade (qui repasse en mode normal)
    document.getElementById('cascTitle').textContent='📸 Enregistrer '+photoBatchIds.length+' photo(s)';
    document.getElementById('cascDoc').textContent='Sélectionne le bien ou le locataire, puis donne un nom de groupe';
    document.getElementById('cascSel').textContent='Choisis le bien / locataire dans une colonne…';
    document.getElementById('cascLink').style.display='none';
    photoSaveBtn.style.display=''; photoSaveBtn.disabled=true;
    photoLabelInp.style.display=''; photoLabelInp.value='';
    var foot=document.querySelector('#cascModal .casc-foot'); if(foot) foot.classList.add('casc-foot-photo');
  };
  function photoSelectTarget(entType, entId, label){
    photoTarget={type:entType, id:entId, label:label};
    document.getElementById('cascSel').innerHTML='Cible : <b>'+esc(label)+'</b> — nom du groupe ?';
    photoSaveRefresh(); photoLabelInp.focus();
    // Enrichissement contexte (société / agence / métier + colonnes voisines) comme la cascade normale.
    fetch(CASCADE+'?level='+encodeURIComponent(entType.toLowerCase())+'&id='+encodeURIComponent(entId),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok) return;
      document.getElementById('cascChipSoc').textContent = '🏢 ' + (d.societe || 'Société');
      document.getElementById('cascChipAge').textContent = '🏢 ' + (d.agence || 'Agence');
      document.getElementById('cascChipMet').textContent = '🗂️ ' + (d.metier_label || 'Métier');
      cascRender('proprio', d.proprio?[d.proprio]:[]);
      cascRender('immeuble', d.immeubles||[]);
      cascRender('bien', d.biens||[]);
      cascRender('bail', d.baux||[]);
    });
  }
  function cascMarkSelectedPhoto(el){ document.querySelectorAll('.casc-item').forEach(function(x){ x.classList.remove('on'); }); if(el) el.classList.add('on'); }
  function photoModeReset(){ photoBatchMode=false; photoTarget=null;
    if(photoSaveBtn){ photoSaveBtn.style.display='none'; photoSaveBtn.disabled=true; }
    if(photoLabelInp){ photoLabelInp.style.display='none'; photoLabelInp.value=''; }
    var cl=document.getElementById('cascLink'); if(cl) cl.style.display='';
    var ct=document.getElementById('cascTitle'); if(ct) ct.textContent='⧉ Explorer & lier les entités';
    var foot=document.querySelector('#cascModal .casc-foot'); if(foot) foot.classList.remove('casc-foot-photo');
  }
  photoSaveBtn.onclick=function(){
    if(!photoTarget) return; var grp=photoLabelInp.value.trim(); if(!grp) return;
    photoSaveBtn.disabled=true; topMsg.textContent='⏳ Enregistrement des photos…';
    var body=new URLSearchParams(); photoBatchIds.forEach(i=>body.append('ids[]', i));
    body.append('entity_type', photoTarget.type); body.append('entity_id', photoTarget.id); body.append('group_label', grp);
    fetch(PHOTOS_TO_BIEN,{method:'POST',credentials:'same-origin',body:body}).then(r=>r.json()).then(function(d){
      if(!d.ok){ topMsg.textContent='⚠ '+(d.error||'échec'); photoSaveBtn.disabled=false; return; }
      photoBatchMode=false; closeCascade();
      var m=d.imported+' photo(s) enregistrée(s)'+(d.duplicates?' · '+d.duplicates+' doublon(s) ignoré(s)':'')+((d.errors&&d.errors.length)?' · '+d.errors.length+' erreur(s)':'');
      topMsg.textContent='✓ '+m+' — groupe '+String(d.groupe_no).padStart(2,'0')+' « '+(d.groupe_label||'')+' »';
      picks.forEach(p=>p.checked=false); refreshBatch();
      // Alerte VISIBLE si des photos étaient déjà présentes (dédoublonnage silencieux → explicite).
      if(d.duplicates){ alert('⚠ '+d.duplicates+' photo(s) déjà présente(s) dans ce bien — ignorée(s) (aucun doublon créé).\n'+d.imported+' nouvelle(s) ajoutée(s).'); }
      setTimeout(function(){ location.reload(); }, 700);
    }).catch(function(e){ topMsg.textContent='⚠ '+e; photoSaveBtn.disabled=false; });
  };

  // Portail : sortir les modals du conteneur (sidebar/layout est transformé → fixed cassé).
  ['cascModal','msgModal','regModal','dupModal','typeModal','segModal'].forEach(function(idm){ var el=document.getElementById(idm); if(el) document.body.appendChild(el); });
  var mc=document.getElementById('msgClose'); if(mc) mc.onclick=function(){ document.getElementById('msgModal').classList.remove('open'); };
  var mm=document.getElementById('msgModal'); if(mm) mm.addEventListener('click', function(e){ if(e.target===mm) mm.classList.remove('open'); });

  if(items.length) select(items[0]);
})();

/* ══ SÉLECTEUR DE SOURCE : Téléchargements ⇄ Mails + espace Téléchargements ══ */
(function(){
  var IMPORT = <?= json_encode(app_url('/api/maboxoffice_import_local.php')) ?>;
  var CSRF = <?= json_encode($csrf) ?>;
  // Les 6 bacs d'orientation (mêmes que le panneau « Orienter dans un bac »).
  var DLBACS = <?= json_encode(array_values(array_map(fn($k) => ['c'=>$k,'i'=>$BACS[$k][0],'l'=>$BACS[$k][1]], ['societe','fournisseur','client','immeuble','rh','personnel']))) ?>;
  var sel = document.getElementById('srcSel');
  var mailWs = document.getElementById('mailWorkspace');
  var dlWs = document.getElementById('dlWorkspace');
  if(!sel || !dlWs) return;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

  // Bascule d'espace (étanche : on masque l'un, on montre l'autre).
  sel.querySelectorAll('.src-tab').forEach(function(t){ t.onclick=function(){
    sel.querySelectorAll('.src-tab').forEach(function(x){ x.classList.remove('active'); });
    t.classList.add('active');
    var isDl = t.dataset.src==='dl';
    if(mailWs) mailWs.hidden = isDl;
    dlWs.hidden = !isDl;
  }; });

  // ── Espace Téléchargements ──
  var dirHandle=null, grid=document.getElementById('dlGrid'), pickBtn=document.getElementById('dlPick'),
      refreshBtn=document.getElementById('dlRefresh'), dlCount=document.getElementById('dlCount');
  var IMG=['jpg','jpeg','png','gif','webp','bmp','tif','tiff'];
  var IC={pdf:'📄',msg:'📧',eml:'📧',xls:'📊',xlsx:'📊',csv:'📊',doc:'📝',docx:'📝',txt:'📝',zip:'🗜️',rar:'🗜️'};
  // Lazy-load des aperçus PDF (iframe) quand la card entre dans le viewport → fluidité.
  var dlObs = new IntersectionObserver(function(entries){
    entries.forEach(function(en){ if(en.isIntersecting){ var f=en.target; if(f.dataset.src){ f.src=f.dataset.src; f.removeAttribute('data-src'); } dlObs.unobserve(f); } });
  }, {rootMargin:'200px'});

  function fmtSize(n){ n=+n||0; return n>1048576?(n/1048576).toFixed(1)+' Mo':(n>1024?(n/1024).toFixed(0)+' Ko':n+' o'); }

  async function loadDir(){
    if(!dirHandle) return;
    grid.innerHTML='<div class="dl-empty">Lecture du dossier…</div>';
    var files=[];
    try {
      for await (var entry of dirHandle.values()){ if(entry.kind==='file') files.push(entry); }
    } catch(e){ grid.innerHTML='<div class="dl-empty">⚠ '+esc(e.message)+'</div>'; return; }
    files.sort(function(a,b){ return a.name.localeCompare(b.name,'fr',{sensitivity:'base'}); });
    if(dlCount) dlCount.textContent = files.length||'';
    if(!files.length){ grid.innerHTML='<div class="dl-empty">Dossier vide.</div>'; return; }
    grid.innerHTML='';
    for(var i=0;i<files.length;i++){ renderCard(files[i]); }
  }

  async function renderCard(handle){
    var name=handle.name, ext=(name.split('.').pop()||'').toLowerCase();
    var card=document.createElement('div'); card.className='dl-card';
    var isImg=IMG.indexOf(ext)!==-1, isPdf=(ext==='pdf');
    var file=null, url='';
    try { file=await handle.getFile(); } catch(e){}
    if((isImg||isPdf) && file){ url=URL.createObjectURL(file); }   // préchargement aperçu (image + PDF)
    var prev = isImg&&url ? '<img src="'+url+'" alt="">'
             : (isPdf&&url ? '<iframe class="dl-pdf" data-src="'+url+'#toolbar=0&navpanes=0" title="'+esc(name)+'"></iframe>'
             : '<span class="dl-ic">'+(IC[ext]||'📄')+'</span>');
    card.innerHTML =
      '<div class="dl-prev">'+prev+(url?'<button type="button" class="dl-zoom" title="Voir en grand">⤢</button>':'')+'</div>'
      +'<div class="dl-body">'
      +  '<div class="dl-nm" title="'+esc(name)+'">'+esc(name)+'</div>'
      +  '<div class="dl-meta">'+esc(ext.toUpperCase())+(file?' · '+fmtSize(file.size):'')+'</div>'
      +  '<div class="dl-orient">'
      +    '<button type="button" class="dl-ob on" data-s="nouveau">📂 À classer</button>'
      +    '<button type="button" class="dl-ob" data-s="a_payer">💶 À payer</button>'
      +    '<button type="button" class="dl-ob" data-s="a_traiter">⏳ À traiter</button>'
      +    '<button type="button" class="dl-ob" data-s="urgent">🔴 Urgent</button>'
      +  '</div>'
      +  '<div class="dl-bacs" title="Orienter dans un bac (optionnel)">'
      +    DLBACS.map(function(b){ return '<button type="button" class="dl-bb" data-b="'+b.c+'" title="'+esc(b.l)+'">'+b.i+'</button>'; }).join('')
      +  '</div>'
      +  '<div class="dl-cardact">'
      +    '<button type="button" class="dl-classer">📥 Orienter</button>'
      +    '<button type="button" class="dl-del">🗑 Supprimer</button>'
      +  '</div>'
      +'</div>';
    // aperçu : zoom (nouvel onglet) + lazy-load de l'iframe PDF quand visible
    if(url){
      var zoom=card.querySelector('.dl-zoom'); if(zoom) zoom.onclick=function(e){ e.stopPropagation(); window.open(url,'_blank'); };
      var img=card.querySelector('.dl-prev img'); if(img) img.style.cursor='zoom-in', img.onclick=function(){ window.open(url,'_blank'); };
      var ifr=card.querySelector('iframe.dl-pdf'); if(ifr) dlObs.observe(ifr);
    }
    // orientation statut (défaut nouveau) + bac (optionnel, désélectionnable)
    var chosen='nouveau', chosenBac='';
    card.querySelectorAll('.dl-ob').forEach(function(b){ b.onclick=function(){
      card.querySelectorAll('.dl-ob').forEach(function(x){ x.classList.remove('on'); }); b.classList.add('on'); chosen=b.dataset.s;
    }; });
    card.querySelectorAll('.dl-bb').forEach(function(b){ b.onclick=function(){
      var was=b.classList.contains('on');
      card.querySelectorAll('.dl-bb').forEach(function(x){ x.classList.remove('on'); });
      if(was){ chosenBac=''; } else { b.classList.add('on'); chosenBac=b.dataset.b; }
    }; });
    // Classer → monte dans la pile MBO avec l'orientation
    card.querySelector('.dl-classer').onclick=async function(){
      var btn=this; btn.disabled=true; btn.textContent='⏳ …';
      try {
        var f = file || await handle.getFile();
        var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('statut',chosen); if(chosenBac) fd.append('bac',chosenBac); fd.append('file', f, name);
        var r=await fetch(IMPORT,{method:'POST',credentials:'same-origin',body:fd}); var d=await r.json();
        if(d&&d.ok){ card.classList.add('done'); card.querySelector('.dl-nm').textContent='✓ '+name+(d.duplicate?' (déjà en pile)':' — classé'); }
        else { btn.disabled=false; btn.textContent='📥 Classer'; alert('Échec : '+((d&&d.error)||'inconnu')); }
      } catch(e){ btn.disabled=false; btn.textContent='📥 Classer'; alert('Réseau : '+e.message); }
    };
    // Supprimer → efface le fichier du disque (Chrome, permission readwrite)
    card.querySelector('.dl-del').onclick=async function(){
      if(!confirm('Supprimer définitivement « '+name+' » de ton dossier Téléchargements ?')) return;
      try { await dirHandle.removeEntry(name); card.classList.add('done'); card.querySelector('.dl-nm').textContent='🗑 '+name+' — supprimé'; if(dlCount){ dlCount.textContent=Math.max(0,(+dlCount.textContent||1)-1)||''; } }
      catch(e){ alert('Suppression impossible : '+e.message+'\n(autorise l\'accès en écriture au dossier)'); }
    };
    grid.appendChild(card);
  }

  if(pickBtn) pickBtn.onclick=async function(){
    if(!window.showDirectoryPicker){ alert('Ton navigateur ne supporte pas la sélection de dossier (utilise Chrome/Edge).'); return; }
    try { dirHandle=await window.showDirectoryPicker({mode:'readwrite'}); }
    catch(e){ return; } // annulé
    if(refreshBtn) refreshBtn.hidden=false;
    pickBtn.textContent='📁 '+(dirHandle.name||'Dossier')+' — changer';
    loadDir();
  };
  if(refreshBtn) refreshBtn.onclick=loadDir;
})();
</script>
<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
