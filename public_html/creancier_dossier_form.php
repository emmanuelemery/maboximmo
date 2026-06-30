<?php
/**
 * creancier_dossier_form.php — Création GUIDÉE d'un dossier CRÉANCIERS (manager).
 *
 * Page unique, sections repliables, TOUT optionnel sauf le libellé. Permet de
 * monter un dossier complet À LA MAIN, sans aucune pièce jointe :
 *   ① Le dossier (libellé/objet/risque/statut/n° adverse)
 *   ② Débiteur(s)          → creancier_dossier_lien (role_dossier=debiteur)
 *   ③ Créances / montants  → creancier_dossier_item (DETTE) + créancier lié
 *   ④ Intervenants         → tiers_roles + lien (avocat, commissaire, expert…)
 *   ⑤ Procédures           → creancier_dossier_item (PROCEDURE) + date audience
 *   ⑥ Documents à demander → creancier_dossier_item (ACTION, statut a_demander)
 *   ⑦ Équipe / accès       → creancier_dossier_acces (lecture/edition/pilote)
 *   ⑧ Notes                → creancier_dossier_message (note_privee + feed)
 *
 * Tiers répétables : saisie libre → match anti-doublon (nom) sinon création.
 * Transactionnel. ACL pilote au créateur. Redirige vers le cockpit 360.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/tiers_selector.php';
require_login();

if (!function_exists('h')) { function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1, 2, 3, 7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); exit('Accès réservé aux managers.'); }

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$err = null;

/** Normalise un montant saisi (« 12 500,00 », « 12.500,00 », espaces insécables) en float|null. */
function cdf_montant(?string $raw): ?float {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $raw = str_replace([' ', "\xc2\xa0"], '', $raw);   // espaces + insécables
    $raw = str_replace(',', '.', $raw);
    // si plusieurs points (séparateur de milliers), ne garde que le dernier comme décimal
    if (substr_count($raw, '.') > 1) {
        $parts = explode('.', $raw);
        $dec   = array_pop($parts);
        $raw   = implode('', $parts) . '.' . $dec;
    }
    return is_numeric($raw) ? (float)$raw : null;
}

/**
 * Match (anti-doublon par nom, accent/casse-insensible via collation) sinon création d'un tiers.
 * $role = code de rôle global à garantir ('' pour ne pas en ajouter — ex. débiteur, géré par lien).
 * Retourne l'id du tiers (0 si nom vide).
 */
function cdf_match_or_create_tiers(PDO $pdo, string $name, string $role, ?int $soc, ?int $age, int $userId): int {
    $name = trim($name);
    if ($name === '') return 0;
    $st = $pdo->prepare("SELECT id FROM tiers
                         WHERE actif = 1 AND (nom_affichage = ? OR raison_sociale = ?)
                         ORDER BY id LIMIT 1");
    $st->execute([$name, $name]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id <= 0) {
        $ins = $pdo->prepare("INSERT INTO tiers (id_societe, id_agence, type_tiers, raison_sociale, nom_affichage, actif, id_user_createur, date_creation, date_modification)
                              VALUES (?,?, 'personne_morale', ?, ?, 1, ?, NOW(), NOW())");
        $ins->execute([$soc, $age, $name, $name, $userId]);
        $id = (int)$pdo->lastInsertId();
    }
    if ($role !== '') {
        $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, actif) VALUES (?, ?, NULL, 1)")
            ->execute([$id, $role]);
    }
    return $id;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf('creancier_dossier_form');
    $libelle = trim((string)($_POST['libelle'] ?? ''));
    $code    = strtoupper(trim((string)($_POST['code'] ?? '')));
    $risque  = in_array($_POST['niveau_risque'] ?? '', ['vert','orange','rouge'], true) ? $_POST['niveau_risque'] : 'orange';
    $statut  = in_array($_POST['statut'] ?? '', ['actif','surveillance','clos'], true) ? $_POST['statut'] : 'surveillance';
    $synth   = trim((string)($_POST['synthese'] ?? ''));
    $numAdv  = trim((string)($_POST['numero_dossier_adverse'] ?? ''));

    if ($libelle === '') {
        $err = 'Le libellé du dossier (débiteur principal) est obligatoire.';
    } else {
        // Code auto si absent + unicité.
        if ($code === '') {
            $base = preg_replace('/[^A-Z0-9]/', '', strtoupper(function_exists('iconv') ? (iconv('UTF-8','ASCII//TRANSLIT',$libelle) ?: $libelle) : $libelle));
            $code = substr($base ?: 'DOSSIER', 0, 8) ?: 'DOSSIER';
        }
        $stC = $pdo->prepare("SELECT 1 FROM creancier_dossier WHERE code = ? LIMIT 1");
        $b = $code; $i = 1;
        while (true) { $stC->execute([$code]); if (!$stC->fetchColumn()) break; $code = substr($b,0,6).$i; $i++; }

        // Tenant du créateur.
        $stU = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ?");
        $stU->execute([$userId]);
        $u   = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
        $soc = isset($u['id_societe']) ? (int)$u['id_societe'] : null;
        $age = isset($u['id_agence'])  ? (int)$u['id_agence']  : null;

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO creancier_dossier (code, libelle, statut, niveau_risque, synthese, numero_dossier_adverse, id_societe, id_agence, created_by)
                                  VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->execute([$code, mb_substr($libelle,0,190), $statut, $risque, $synth ?: null, $numAdv ?: null, $soc, $age, $userId]);
            $id = (int)$pdo->lastInsertId();

            // ACL pilote du créateur.
            $pdo->prepare("INSERT IGNORE INTO creancier_dossier_acces (id_dossier, id_user, niveau, created_by) VALUES (?,?, 'pilote', ?)")
                ->execute([$id, $userId, $userId]);

            $linkLien = $pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, created_by) VALUES (?, 'TIERS', ?, ?, ?)");

            // ── ② Débiteur(s) : principal (sélecteur) + libres ──────────────
            $debPrincipal = (int)($_POST['id_tiers_debiteur'] ?? 0);
            if ($debPrincipal > 0) { $linkLien->execute([$id, $debPrincipal, 'debiteur', $userId]); }
            foreach ((array)($_POST['deb_nom'] ?? []) as $dn) {
                $tid = cdf_match_or_create_tiers($pdo, (string)$dn, '', $soc, $age, $userId);
                if ($tid > 0) { $linkLien->execute([$id, $tid, 'debiteur', $userId]); }
            }

            // ── ③ Créances / montants → items DETTE + créancier lié ─────────
            $insItem = $pdo->prepare("INSERT INTO creancier_dossier_item (id_dossier, type, titre, description, montant, date_echeance, id_tiers_lie, statut, priorite, created_by)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $creaNoms  = (array)($_POST['crea_nom'] ?? []);
            $creaMnts  = (array)($_POST['crea_montant'] ?? []);
            $creaRefs  = (array)($_POST['crea_ref'] ?? []);
            $creaObjs  = (array)($_POST['crea_objet'] ?? []);
            $creaEchs  = (array)($_POST['crea_echeance'] ?? []);
            // Créancier principal via sélecteur (ligne 0 implicite).
            $creaPrincipal = (int)($_POST['id_tiers_creancier'] ?? 0);
            if ($creaPrincipal > 0) {
                $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, actif) VALUES (?, 'creancier', NULL, 1)")->execute([$creaPrincipal]);
                $linkLien->execute([$id, $creaPrincipal, 'creancier', $userId]);
            }
            foreach ($creaNoms as $k => $cn) {
                $cn  = trim((string)$cn);
                $mnt = cdf_montant($creaMnts[$k] ?? null);
                $ref = trim((string)($creaRefs[$k] ?? ''));
                $obj = trim((string)($creaObjs[$k] ?? ''));
                $ech = trim((string)($creaEchs[$k] ?? ''));
                $ech = ($ech !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech)) ? $ech : null;
                // Ligne vide ignorée
                if ($cn === '' && $mnt === null && $ref === '' && $obj === '') continue;
                // Tiers créancier : si nom saisi → match/create ; sinon retombe sur le principal.
                $cid = $cn !== '' ? cdf_match_or_create_tiers($pdo, $cn, 'creancier', $soc, $age, $userId) : $creaPrincipal;
                if ($cid > 0) { $linkLien->execute([$id, $cid, 'creancier', $userId]); }
                if ($mnt !== null && $mnt > 0) {
                    $cNomAff = $cn;
                    if ($cNomAff === '' && $cid > 0) {
                        $stn = $pdo->prepare("SELECT COALESCE(NULLIF(nom_affichage,''), raison_sociale) FROM tiers WHERE id = ?");
                        $stn->execute([$cid]); $cNomAff = (string)($stn->fetchColumn() ?: 'créancier');
                    }
                    $titre = 'Créance ' . ($cNomAff ?: 'créancier') . ($ref !== '' ? ' — ' . $ref : '');
                    $insItem->execute([$id, 'DETTE', mb_substr($titre,0,190), ($obj !== '' ? $obj : null), $mnt, $ech, ($cid ?: null), 'ouvert', 5, $userId]);
                }
            }

            // ── ④ Intervenants → tiers_roles + lien ─────────────────────────
            $intNoms  = (array)($_POST['interv_nom'] ?? []);
            $intRoles = (array)($_POST['interv_role'] ?? []);
            $rolesOk  = ['avocat','commissaire_justice','expert_comptable','notaire','mandataire'];
            foreach ($intNoms as $k => $inom) {
                $inom = trim((string)$inom);
                if ($inom === '') continue;
                $irole = in_array($intRoles[$k] ?? '', $rolesOk, true) ? $intRoles[$k] : 'avocat';
                $tid   = cdf_match_or_create_tiers($pdo, $inom, $irole, $soc, $age, $userId);
                if ($tid > 0) { $linkLien->execute([$id, $tid, $irole, $userId]); }
            }

            // ── ⑤ Procédures → items PROCEDURE ──────────────────────────────
            $procTypes = (array)($_POST['proc_type'] ?? []);
            $procJurs  = (array)($_POST['proc_juridiction'] ?? []);
            $procRgs   = (array)($_POST['proc_rg'] ?? []);
            $procDates = (array)($_POST['proc_date'] ?? []);
            foreach ($procJurs as $k => $pj) {
                $pj   = trim((string)$pj);
                $pt   = trim((string)($procTypes[$k] ?? ''));
                $prg  = trim((string)($procRgs[$k] ?? ''));
                $pdte = trim((string)($procDates[$k] ?? ''));
                $pdte = ($pdte !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdte)) ? $pdte : null;
                if ($pj === '' && $pt === '' && $prg === '' && $pdte === null) continue;
                $titre = trim(($pt ?: 'Procédure') . ($pj !== '' ? ' — ' . $pj : '') . ($prg !== '' ? ' (RG ' . $prg . ')' : ''));
                $insItem->execute([$id, 'PROCEDURE', mb_substr($titre,0,190), null, null, $pdte, null, 'en_cours', 7, $userId]);
            }

            // ── ⑥ Documents à demander → items ACTION (statut a_demander) ────
            foreach ((array)($_POST['doc_demande'] ?? []) as $dd) {
                $dd = trim((string)$dd);
                if ($dd === '') continue;
                $insItem->execute([$id, 'ACTION', mb_substr('📄 À demander : ' . $dd,0,190), null, null, null, null, 'a_demander', 4, $userId]);
            }

            // ── ⑦ Équipe / accès → creancier_dossier_acces ──────────────────
            $accUsers = (array)($_POST['acces_user'] ?? []);
            $accNivs  = (array)($_POST['acces_niveau'] ?? []);
            $insAcc   = $pdo->prepare("INSERT IGNORE INTO creancier_dossier_acces (id_dossier, id_user, niveau, created_by) VALUES (?,?,?,?)");
            foreach ($accUsers as $k => $au) {
                $au = (int)$au;
                if ($au <= 0 || $au === $userId) continue;
                $niv = in_array($accNivs[$k] ?? '', ['lecture','edition','pilote'], true) ? $accNivs[$k] : 'edition';
                $insAcc->execute([$id, $au, $niv, $userId]);
            }

            // ── ⑧ Notes : privée (auteur only) + partagée (feed) ────────────
            $notePriv = trim((string)($_POST['note_privee'] ?? ''));
            $noteShar = trim((string)($_POST['note_partagee'] ?? ''));
            $insMsg = $pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, canal, id_user, message) VALUES (?, 'user', ?, ?, ?)");
            if ($notePriv !== '') { $insMsg->execute([$id, 'note_privee', $userId, $notePriv]); }
            if ($noteShar !== '') { $insMsg->execute([$id, 'feed', $userId, $noteShar]); }

            $pdo->commit();
            header('Location: ' . (function_exists('app_url') ? app_url('/creancier_dossier360.php') : 'creancier_dossier360.php') . '?id_dossier=' . $id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

// ── Liste des collaborateurs (même société) pour la section Équipe ───────────
$collabs = [];
try {
    $stU = $pdo->prepare("SELECT id_societe FROM users WHERE id = ?");
    $stU->execute([$userId]);
    $mySoc = (int)($stU->fetchColumn() ?: 0);
    if ($mySoc > 0) {
        $stL = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE id_societe = ? AND id <> ? ORDER BY nom, prenom");
        $stL->execute([$mySoc, $userId]);
        $collabs = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) { $collabs = []; }

// Checklist de documents fréquents (cochables).
$docPreset = ['Mandat / contrat', 'Factures impayées', 'Devis signé', 'Bons de commande', 'Bons de livraison',
              'Relevé de compte client', 'Mise en demeure', 'Reconnaissance de dette', 'Jugement', 'RIB du débiteur'];

$layout_title   = 'Nouveau dossier créancier';
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';
$layout_extra_css = <<<'CSS'
<style>
.cf-wrap { padding:18px; max-width:860px; }
.cf-card { background:#fff; border:1px solid #e6e1d8; border-radius:12px; padding:6px 0; margin-bottom:14px; overflow:hidden; }
.cf-sec { border-bottom:1px solid #f0ece3; }
.cf-sec:last-child { border-bottom:none; }
.cf-sec-head { display:flex; align-items:center; gap:10px; padding:14px 20px; cursor:pointer; user-select:none; }
.cf-sec-head:hover { background:#faf8f3; }
.cf-sec-num { width:26px; height:26px; border-radius:50%; background:#243B5C; color:#fff; font-weight:700; font-size:13px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; }
.cf-sec-title { font-size:14px; font-weight:700; color:#243B5C; }
.cf-sec-sub { font-size:12px; color:#9a948a; margin-left:auto; }
.cf-sec-body { padding:4px 20px 18px; display:none; }
.cf-sec.is-open .cf-sec-body { display:block; }
.cf-sec.is-open .cf-caret { transform:rotate(90deg); }
.cf-caret { transition:transform .15s; color:#b8b2a8; }
.cf-row { margin-bottom:12px; }
.cf-row label { display:block; font-size:12px; color:#6b6358; font-weight:600; margin-bottom:5px; }
.cf-row input, .cf-row select, .cf-row textarea { width:100%; padding:9px 11px; border:1px solid #d8d2c8; border-radius:8px; font-size:14px; font-family:inherit; box-sizing:border-box; }
.cf-row textarea { min-height:64px; resize:vertical; }
.cf-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.cf-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
.cf-err { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:8px; padding:10px 12px; font-size:13px; margin:14px 20px; }
.cf-actions { padding:16px 20px; display:flex; gap:10px; position:sticky; bottom:0; background:#fff; border-top:1px solid #eee; }
.cf-rep { border:1px dashed #ddd6ca; border-radius:9px; padding:10px; margin-bottom:8px; position:relative; background:#fcfbf8; }
.cf-rep .cf-del { position:absolute; top:6px; right:8px; border:none; background:rgba(168,88,88,.1); color:#a85858; border-radius:50%; width:22px; height:22px; cursor:pointer; font-weight:700; }
.cf-add { border:1px solid #243B5C; background:#fff; color:#243B5C; border-radius:8px; padding:7px 12px; font-size:12px; font-weight:700; cursor:pointer; }
.cf-hint { font-size:11px; color:#9a948a; margin:-4px 0 10px; }
.cf-chips { display:flex; flex-wrap:wrap; gap:8px; }
.cf-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 10px; border:1px solid #d8d2c8; border-radius:20px; cursor:pointer; background:#fff; }
.cf-chip input { width:auto; margin:0; }
.cf-mini { font-size:11px; color:#9a5a18; font-weight:600; }
</style>
CSS;

ob_start();
?>
<div class="cf-wrap">
  <?php if ($err): ?><div class="cf-card"><div class="cf-err"><?= h($err) ?></div></div><?php endif; ?>
  <form method="post" id="cfForm">
    <?= csrf_field('creancier_dossier_form') ?>

    <div class="cf-card">
      <!-- ① Le dossier -->
      <div class="cf-sec is-open">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">1</span><span class="cf-sec-title">Le dossier</span>
          <span class="cf-sec-sub">obligatoire</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div class="cf-row"><label>Libellé du dossier (débiteur principal) *</label>
            <input type="text" name="libelle" required value="<?= h($_POST['libelle'] ?? '') ?>" placeholder="Ex. Chantier D124 — GROUPE SIR"></div>
          <div class="cf-grid">
            <div class="cf-row"><label>Objet / contexte (synthèse)</label>
              <input type="text" name="synthese" value="<?= h($_POST['synthese'] ?? '') ?>" placeholder="Ex. Impayés factures chantier Villeurbanne"></div>
            <div class="cf-row"><label>N° dossier adverse</label>
              <input type="text" name="numero_dossier_adverse" value="<?= h($_POST['numero_dossier_adverse'] ?? '') ?>"></div>
            <div class="cf-row"><label>Statut</label>
              <select name="statut">
                <option value="surveillance">Surveillance</option>
                <option value="actif" selected>Actif</option>
                <option value="clos">Clos</option>
              </select></div>
            <div class="cf-row"><label>Niveau de risque</label>
              <select name="niveau_risque">
                <option value="orange" selected>Orange</option>
                <option value="rouge">Rouge</option>
                <option value="vert">Vert</option>
              </select></div>
          </div>
          <div class="cf-row"><label>Code (auto si vide)</label>
            <input type="text" name="code" value="<?= h($_POST['code'] ?? '') ?>" placeholder="SIR"></div>
        </div>
      </div>

      <!-- ② Débiteurs -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">2</span><span class="cf-sec-title">Débiteur(s)</span>
          <span class="cf-sec-sub">qui doit l'argent</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div class="cf-row">
            <?php tiers_selector_render([
                'id'=>'debiteur_picker','name'=>'id_tiers_debiteur','label'=>'Débiteur principal (rechercher / créer)',
                'placeholder'=>'Nom du débiteur (société, SCI, particulier…)','allow_create'=>true,
            ]); ?>
          </div>
          <div class="cf-hint">Débiteurs supplémentaires (un nom par ligne, créés/réutilisés automatiquement) :</div>
          <div id="repDeb"></div>
          <button type="button" class="cf-add" onclick="cfAddDeb()">+ Ajouter un débiteur</button>
        </div>
      </div>

      <!-- ③ Créances / montants -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">3</span><span class="cf-sec-title">Créances / montants</span>
          <span class="cf-sec-sub">ce qui est dû</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div class="cf-row">
            <?php tiers_selector_render([
                'id'=>'creancier_picker','name'=>'id_tiers_creancier','label'=>'Créancier principal (à qui c\'est dû)',
                'role_filter'=>'creancier','placeholder'=>'Banque, fournisseur, copro, vous-même…',
                'allow_create'=>true,'default_roles'=>['creancier'],
            ]); ?>
          </div>
          <div class="cf-hint">Une ligne par créance. Le créancier vide reprend le créancier principal ci-dessus.</div>
          <div id="repCrea"></div>
          <button type="button" class="cf-add" onclick="cfAddCrea()">+ Ajouter une créance</button>
        </div>
      </div>

      <!-- ④ Intervenants -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">4</span><span class="cf-sec-title">Intervenants</span>
          <span class="cf-sec-sub">avocat, huissier, expert…</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div id="repInt"></div>
          <button type="button" class="cf-add" onclick="cfAddInt()">+ Ajouter un intervenant</button>
        </div>
      </div>

      <!-- ⑤ Procédures -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">5</span><span class="cf-sec-title">Procédures judiciaires</span>
          <span class="cf-sec-sub">tribunaux, audiences</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div id="repProc"></div>
          <button type="button" class="cf-add" onclick="cfAddProc()">+ Ajouter une procédure</button>
        </div>
      </div>

      <!-- ⑥ Documents à demander -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">6</span><span class="cf-sec-title">Documents à demander</span>
          <span class="cf-sec-sub">checklist</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div class="cf-chips">
            <?php foreach ($docPreset as $dp): ?>
              <label class="cf-chip"><input type="checkbox" name="doc_demande[]" value="<?= h($dp) ?>"> <?= h($dp) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="cf-hint" style="margin-top:10px;">Autre document à réclamer :</div>
          <div id="repDoc"></div>
          <button type="button" class="cf-add" onclick="cfAddDoc()">+ Autre document</button>
          <div class="cf-mini" style="margin-top:10px;">💡 Le lien de dépôt sécurisé (le débiteur dépose en ligne → ingestion GED auto) sera branché sur cette checklist.</div>
        </div>
      </div>

      <!-- ⑦ Équipe / accès -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">7</span><span class="cf-sec-title">Équipe / accès</span>
          <span class="cf-sec-sub">géré à plusieurs</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <?php if ($collabs): ?>
            <div class="cf-hint">Vous êtes pilote. Donnez l'accès à d'autres personnes (modifiable ensuite) :</div>
            <div id="repAcc"></div>
            <button type="button" class="cf-add" onclick="cfAddAcc()">+ Ajouter une personne</button>
          <?php else: ?>
            <div class="cf-hint">Aucun collaborateur listé pour votre société — vous pourrez partager le dossier depuis le cockpit.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ⑧ Notes -->
      <div class="cf-sec">
        <div class="cf-sec-head" onclick="cfToggle(this)">
          <span class="cf-sec-num">8</span><span class="cf-sec-title">Notes de départ</span>
          <span class="cf-sec-sub">perso + partagée</span><span class="cf-caret">▶</span>
        </div>
        <div class="cf-sec-body">
          <div class="cf-row"><label>🔒 Note personnelle (privée, vous seul)</label>
            <textarea name="note_privee" placeholder="Stratégie, rappels, points de vigilance…"><?= h($_POST['note_privee'] ?? '') ?></textarea></div>
          <div class="cf-row"><label>📣 Note partagée (fil signé, visible par l'équipe)</label>
            <textarea name="note_partagee" placeholder="Premier point de situation partagé…"><?= h($_POST['note_partagee'] ?? '') ?></textarea></div>
        </div>
      </div>
    </div>

    <div class="cf-card">
      <div class="cf-actions">
        <button type="submit" class="ph-btn primary" style="background:#243B5C;color:#fff;border:none;padding:11px 22px;border-radius:8px;font-weight:700;cursor:pointer;">Créer le dossier</button>
        <a href="<?= h(function_exists('app_url') ? app_url('/creancier_liste.php') : 'creancier_liste.php') ?>" class="ph-btn" style="padding:11px 22px;border-radius:8px;border:1px solid #d8d2c8;text-decoration:none;color:#6b6358;">Annuler</a>
      </div>
    </div>
  </form>
</div>

<template id="tplDeb">
  <div class="cf-rep"><button type="button" class="cf-del" onclick="this.closest('.cf-rep').remove()">×</button>
    <input type="text" name="deb_nom[]" placeholder="Nom du débiteur supplémentaire"></div>
</template>
<template id="tplCrea">
  <div class="cf-rep"><button type="button" class="cf-del" onclick="this.closest('.cf-rep').remove()">×</button>
    <div class="cf-grid">
      <div class="cf-row"><label>Créancier (vide = principal)</label><input type="text" name="crea_nom[]" placeholder="Ex. CEP DISTRIBUTION"></div>
      <div class="cf-row"><label>Montant dû (€)</label><input type="text" name="crea_montant[]" inputmode="decimal" placeholder="294 487,38"></div>
    </div>
    <div class="cf-grid-3">
      <div class="cf-row"><label>Référence</label><input type="text" name="crea_ref[]" placeholder="N° facture"></div>
      <div class="cf-row"><label>Objet</label><input type="text" name="crea_objet[]" placeholder="Chantier D124…"></div>
      <div class="cf-row"><label>Échéance</label><input type="date" name="crea_echeance[]"></div>
    </div>
  </div>
</template>
<template id="tplInt">
  <div class="cf-rep"><button type="button" class="cf-del" onclick="this.closest('.cf-rep').remove()">×</button>
    <div class="cf-grid">
      <div class="cf-row"><label>Nom / cabinet</label><input type="text" name="interv_nom[]" placeholder="Maître Dupont, Étude…"></div>
      <div class="cf-row"><label>Rôle</label>
        <select name="interv_role[]">
          <option value="avocat">Avocat</option>
          <option value="commissaire_justice">Commissaire de justice (huissier)</option>
          <option value="expert_comptable">Expert-comptable</option>
          <option value="notaire">Notaire</option>
          <option value="mandataire">Mandataire / autre</option>
        </select></div>
    </div>
  </div>
</template>
<template id="tplProc">
  <div class="cf-rep"><button type="button" class="cf-del" onclick="this.closest('.cf-rep').remove()">×</button>
    <div class="cf-grid-3">
      <div class="cf-row"><label>Type</label><input type="text" name="proc_type[]" placeholder="Injonction de payer, référé…"></div>
      <div class="cf-row"><label>Juridiction</label><input type="text" name="proc_juridiction[]" placeholder="TJ Lyon, Tribunal de commerce…"></div>
      <div class="cf-row"><label>N° RG</label><input type="text" name="proc_rg[]" placeholder="RG 24/01234"></div>
    </div>
    <div class="cf-row"><label>Date d'audience / butoir</label><input type="date" name="proc_date[]"></div>
  </div>
</template>
<template id="tplDoc">
  <div class="cf-rep"><button type="button" class="cf-del" onclick="this.closest('.cf-rep').remove()">×</button>
    <input type="text" name="doc_demande[]" placeholder="Autre document à demander"></div>
</template>
<template id="tplAcc">
  <div class="cf-rep"><button type="button" class="cf-del" onclick="this.closest('.cf-rep').remove()">×</button>
    <div class="cf-grid">
      <div class="cf-row"><label>Personne</label>
        <select name="acces_user[]">
          <option value="">— choisir —</option>
          <?php foreach ($collabs as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="cf-row"><label>Accès</label>
        <select name="acces_niveau[]">
          <option value="edition">Édition</option>
          <option value="lecture">Lecture seule</option>
          <option value="pilote">Pilote</option>
        </select></div>
    </div>
  </div>
</template>

<?php tiers_selector_assets(); ?>
<script>
function cfToggle(h){ h.parentElement.classList.toggle('is-open'); }
function cfClone(tplId, hostId){
  var t = document.getElementById(tplId);
  document.getElementById(hostId).appendChild(t.content.cloneNode(true));
}
function cfAddDeb(){ cfClone('tplDeb','repDeb'); }
function cfAddCrea(){ cfClone('tplCrea','repCrea'); }
function cfAddInt(){ cfClone('tplInt','repInt'); }
function cfAddProc(){ cfClone('tplProc','repProc'); }
function cfAddDoc(){ cfClone('tplDoc','repDoc'); }
function cfAddAcc(){ cfClone('tplAcc','repAcc'); }
// Une première ligne créance pré-affichée pour guider.
cfAddCrea();
</script>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
