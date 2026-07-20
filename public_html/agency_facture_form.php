<?php
// agency_facture_form.php — Création / Édition facture V2 MaBoxImmo (layout_maboximmo)
// Numérotation auto, lignes dynamiques, TVA par ligne, calcul HT/TVA/TTC, statuts, envoi mail
$current_page = 'factures';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$roleId = (int)current_role_id();
if ($roleId > 2) { header('Location: agency_factures.php'); exit; }
$pdo    = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew = ($id === 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur(float $v): string { return number_format($v,2,',',' ').' €'; }

function generateNumeroFacture(PDO $pdo, int $idEtab, int $annee): array {
    $st = $pdo->prepare("SELECT sigle FROM etablissements WHERE id=?");
    $st->execute([$idEtab]);
    $sigle  = $st->fetchColumn() ?: 'F';
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/','',trim((string)$sigle)),0,3)) ?: 'FAC';
    $st2 = $pdo->prepare("SELECT COALESCE(MAX(seq_num),0)+1 FROM agency_facture WHERE id_etablissement=? AND annee=?");
    $st2->execute([$idEtab,$annee]);
    $next = (int)$st2->fetchColumn();
    return ['seq_num'=>$next,'numero'=>sprintf('%s-%04d-%06d',$prefix,$annee,$next)];
}

// ── AJAX changer statut ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_statut'])) {
    $ns = $_POST['new_statut'] ?? '';
    $allowed = ['brouillon','validee','envoyee','payee','annulee'];
    if (in_array($ns,$allowed,true) && $id) {
        $pdo->prepare("UPDATE agency_facture SET statut=? WHERE id=?")->execute([$ns,$id]);
        echo json_encode(['ok'=>true,'statut'=>$ns]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── AJAX envoi mail ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_mail']) && $id) {
    require_once __DIR__ . '/inc/mailer_facture.php';
    $result = sendFactureMail($pdo, $id);
    if ($result) {
        $pdo->prepare("UPDATE agency_facture SET statut='envoyee', date_envoi_mail=NOW() WHERE id=? AND statut='validee'")->execute([$id]);
    }
    header("Location: agency_facture_form.php?id=$id&mail=".($result?'ok':'err')); exit;
}

// ── AJAX marquer payée ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['marquer_payee']) && $id) {
    $pdo->prepare("UPDATE agency_facture SET statut='payee', date_paiement=CURDATE() WHERE id=?")->execute([$id]);
    header("Location: agency_facture_form.php?id=$id&payee=1"); exit;
}

// ── Charger presets lignes ────────────────────────────────────────────
$presets = [];
try { $presets = $pdo->query("SELECT id,libelle,prix_unitaire_ht,tva_taux FROM agency_prestation_type WHERE actif=1 ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e){}

// ── POST enregistrer ──────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_facture'])) {
    $client         = trim($_POST['client']        ?? '');
    $type_client    = $_POST['type_client']         ?? 'syndicat';
    $immeuble_txt   = trim($_POST['immeuble_txt']   ?? '');
    $id_immeuble    = (int)($_POST['id_immeuble']   ?? 0) ?: null;
    $id_etab        = (int)($_POST['id_etablissement'] ?? ($_SESSION['id_etablissement'] ?? 0));
    $date_emission  = $_POST['date_emission']        ?? date('Y-m-d');
    $date_echeance  = $_POST['date_echeance']         ?: null;
    $statut         = $_POST['statut']                ?? 'brouillon';
    $mode_paiement  = $_POST['mode_paiement']         ?? 'virement';
    $tva_defaut     = (float)str_replace(',','.',$_POST['tva_defaut'] ?? '20');
    $mail_to        = trim($_POST['mail_destinataire'] ?? '');
    $mail_cc        = trim($_POST['mail_cc']            ?? '');
    $notes          = trim($_POST['notes']              ?? '');

    $desigs = $_POST['ligne_designation'] ?? [];
    $qtes   = $_POST['ligne_quantite']    ?? [];
    $pus    = $_POST['ligne_pu_ht']       ?? [];
    $tvas   = $_POST['ligne_tva']         ?? [];

    if (empty($desigs) || !array_filter($desigs)) $errors[] = 'Au moins une ligne est requise.';
    if (!$id_etab)                                  $errors[] = 'L\'établissement est obligatoire.';

    if (empty($errors)) {
        $annee   = (int)date('Y', strtotime($date_emission));
        $total_ht  = 0.0; $total_tva = 0.0; $total_ttc = 0.0;

        foreach ($desigs as $i => $des) {
            if (trim($des) === '') continue;
            $q  = (float)str_replace(',','.',$qtes[$i] ?? 1);
            $pu = (float)str_replace(',','.',$pus[$i]  ?? 0);
            $tv = (float)str_replace(',','.',$tvas[$i] ?? $tva_defaut);
            $ht  = round($q * $pu, 2);
            $tva = round($ht * $tv / 100, 2);
            $total_ht  += $ht;
            $total_tva += $tva;
            $total_ttc += $ht + $tva;
        }
        $total_ht  = round($total_ht,2);
        $total_tva = round($total_tva,2);
        $total_ttc = round($total_ttc,2);

        if ($isNew) {
            $gen = generateNumeroFacture($pdo, $id_etab, $annee);
            $pdo->prepare("INSERT INTO agency_facture (id_etablissement,id_createur,client,type_client,immeuble_txt,id_immeuble,annee,seq_num,numero,date_emission,date_echeance,statut,tva_defaut,mode_paiement,mail_destinataire,mail_cc,notes,total_ht,total_tva,total_ttc,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$id_etab,$userId,$client,$type_client,$immeuble_txt,$id_immeuble,$annee,$gen['seq_num'],$gen['numero'],$date_emission,$date_echeance,$statut,$tva_defaut,$mode_paiement,$mail_to,$mail_cc,$notes,$total_ht,$total_tva,$total_ttc]);
            $id = (int)$pdo->lastInsertId(); $isNew = false;
        } else {
            $pdo->prepare("UPDATE agency_facture SET client=?,type_client=?,immeuble_txt=?,id_immeuble=?,date_emission=?,date_echeance=?,statut=?,tva_defaut=?,mode_paiement=?,mail_destinataire=?,mail_cc=?,notes=?,total_ht=?,total_tva=?,total_ttc=? WHERE id=?")
                ->execute([$client,$type_client,$immeuble_txt,$id_immeuble,$date_emission,$date_echeance,$statut,$tva_defaut,$mode_paiement,$mail_to,$mail_cc,$notes,$total_ht,$total_tva,$total_ttc,$id]);
        }
        // Lignes
        $pdo->prepare("DELETE FROM agency_facture_ligne WHERE id_facture=?")->execute([$id]);
        $ordre = 1;
        foreach ($desigs as $i => $des) {
            if (trim($des) === '') continue;
            $q  = (float)str_replace(',','.',$qtes[$i] ?? 1);
            $pu = (float)str_replace(',','.',$pus[$i]  ?? 0);
            $tv = (float)str_replace(',','.',$tvas[$i] ?? $tva_defaut);
            $pdo->prepare("INSERT INTO agency_facture_ligne (id_facture,ordre,designation,quantite,prix_unitaire_ht,tva_taux) VALUES (?,?,?,?,?,?)")
                ->execute([$id,$ordre++,$des,$q,$pu,$tv]);
        }
        header("Location: agency_facture_form.php?id=$id&saved=1"); exit;
    }
}

// ── Charger facture ────────────────────────────────────────────────────
$facture = null; $lignes = [];
if (!$isNew) {
    $stmt = $pdo->prepare("SELECT f.*,e.nom AS etab_nom,e.adresse AS etab_adresse,e.telephone AS etab_tel,e.email AS etab_email,e.siret AS etab_siret,i.nom_immeuble AS imm_nom FROM agency_facture f LEFT JOIN etablissements e ON e.id=f.id_etablissement LEFT JOIN immeubles i ON i.id=f.id_immeuble WHERE f.id=?");
    $stmt->execute([$id]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$facture) { header('Location: agency_factures.php'); exit; }
    $lst = $pdo->prepare("SELECT * FROM agency_facture_ligne WHERE id_facture=? ORDER BY ordre");
    $lst->execute([$id]); $lignes = $lst->fetchAll(PDO::FETCH_ASSOC);
}

// ── Pré-remplissage depuis une source externe (ex. dossier de transaction) ──
// N'enregistre RIEN : ouvre simplement le formulaire pré-rempli, l'utilisateur
// contrôle/édite puis valide comme d'habitude. Aucun envoi automatique.
if ($isNew && isset($_GET['prefill'])) {
    $facture = [
        'client'            => trim((string)($_GET['client'] ?? '')),
        'type_client'       => in_array(($_GET['type_client'] ?? ''), ['syndicat','mandant','autre'], true) ? $_GET['type_client'] : 'autre',
        'mail_destinataire' => trim((string)($_GET['mail'] ?? '')),
        'immeuble_txt'      => trim((string)($_GET['immeuble_txt'] ?? '')),
        'notes'             => trim((string)($_GET['notes'] ?? '')),
        'tva_defaut'        => (float)str_replace(',', '.', (string)($_GET['tva'] ?? '20')),
    ];
    $montantPrefill = (float)str_replace(',', '.', (string)($_GET['montant'] ?? '0'));
    if ($montantPrefill > 0) {
        $lignes = [[
            'designation'      => trim((string)($_GET['ligne'] ?? 'Honoraires de négociation')),
            'quantite'         => 1,
            'prix_unitaire_ht' => $montantPrefill,
            'tva_taux'         => (float)str_replace(',', '.', (string)($_GET['tva'] ?? '20')),
        ]];
    }
}

$immeubles = $pdo->query("SELECT id, nom_immeuble AS nom, reference_immeuble AS reference FROM immeubles ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$etabs     = $pdo->query("SELECT id,nom,sigle FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$STAT = ['brouillon'=>['Brouillon','#9a9690','var(--bg-primary,#e4e8f0)'],'validee'=>['Validée','#4878a6','#d8e8f5'],'envoyee'=>['Envoyée','#7a6830','#f8eddc'],'payee'=>['Payée','#3a7a6a','#d8eee3'],'annulee'=>['Annulée','#8a5040','#fce8e8']];
$STAT_LBL = ['brouillon'=>'Brouillon','validee'=>'Validée','envoyee'=>'Envoyée','payee'=>'Payée','annulee'=>'Annulée'];
$curStat = $facture['statut'] ?? 'brouillon';

// ── Layout ──────────────────────────────────────────────────────────
$layout_title   = $isNew ? 'Nouvelle facture' : ('Facture '.($facture['numero'] ?? ''));
$layout_module  = 'Ma Box Agency';
$layout_sidebar = 'sidebar_agency';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val">'.($isNew?'NEW':h($facture['numero']??'—')).'</div><div class="ph-kpi-lbl">N°</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.eur((float)($facture['total_ht']??0)).'</div><div class="ph-kpi-lbl">Total HT</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.eur((float)($facture['total_tva']??0)).'</div><div class="ph-kpi-lbl">TVA</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.eur((float)($facture['total_ttc']??0)).'</div><div class="ph-kpi-lbl">TTC</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:'.($STAT[$curStat][1]??'#9a9690').'">'.($STAT_LBL[$curStat]??$curStat).'</div><div class="ph-kpi-lbl">Statut</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val">'.count($lignes).'</div><div class="ph-kpi-lbl">Lignes</div></div>
';

$layout_head_actions = '
    <a href="agency_factures.php" class="ph-btn">Liste</a>
    '.(!$isNew ? '<a href="agency_pdf_facture.php?id='.$id.'" target="_blank" class="ph-btn primary">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        PDF
    </a>' : '<a href="#" class="ph-btn dispo">dispo</a>').'
    <a href="#" class="ph-btn dispo">dispo</a>
    <a href="#" class="ph-btn dispo">dispo</a>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
.fact-grid{display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start}
.fc{background:var(--bg-primary,var(--bg-primary,#e4e8f0));border-radius:18px;box-shadow:6px 6px 16px var(--shadow-dark,#d4d7de),-6px -6px 14px var(--shadow-light,#fff);padding:20px 24px;margin-bottom:18px}
.fc-title{font-family:'Sora',sans-serif;font-size:13px;font-weight:700;color:#4a6038;margin-bottom:14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e4e6ec;padding-bottom:10px}
.fc-title svg{width:15px;height:15px;stroke:#4878a6;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
.fg label{font-family:'DM Mono',monospace;font-size:10px;color:#4a6038;text-transform:uppercase;letter-spacing:.1em}
.fi,.fs,.fta{background:var(--bg-secondary,#eef1f6);border:none;border-radius:10px;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee;padding:9px 13px;font-family:'Sora',sans-serif;font-size:13px;color:#2c2a28;width:100%;transition:box-shadow .2s}
.fi:focus,.fs:focus,.fta:focus{outline:none;box-shadow:inset 2px 2px 5px #cac6c0,inset -2px -2px 5px #f8f4ee,0 0 0 3px rgba(72,120,166,.3)}
.fta{resize:vertical;min-height:60px}
.f2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.f3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.lignes-table{width:100%;border-collapse:collapse;margin-bottom:10px}
.lignes-table thead th{font-family:'DM Mono',monospace;font-size:10px;color:#4a6038;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:6px 8px;border-bottom:1px solid #e4e6ec;white-space:nowrap}
.lignes-table tbody td{padding:5px 4px;vertical-align:middle}
.li{background:var(--bg-secondary,#eef1f6);border:none;border-radius:7px;box-shadow:inset 2px 2px 4px #cac6c0,inset -2px -2px 4px #f8f4ee;padding:6px 9px;font-family:'Sora',sans-serif;font-size:12px;color:#2c2a28;width:100%}
.li:focus{outline:none;box-shadow:inset 2px 2px 4px #cac6c0,inset -2px -2px 4px #f8f4ee,0 0 0 2px rgba(72,120,166,.3)}
.li-del{width:26px;height:26px;border-radius:50%;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:2px 2px 5px var(--shadow-dark,#d4d7de),-2px -2px 5px var(--shadow-light,#fff);border:none;cursor:pointer;color:#8a5040;font-size:15px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.total-row{display:flex;justify-content:space-between;padding:5px 0;font-family:'Sora',sans-serif;font-size:13px;color:#4a4844}
.total-row.grand{font-weight:700;font-size:16px;color:#2c2a28;border-top:2px solid #e4e6ec;padding-top:10px;margin-top:4px}
.stat-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;border-radius:12px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;border:none;cursor:pointer;width:100%;margin-bottom:6px;transition:opacity .15s;text-align:left}
.act-btn{display:flex;align-items:center;gap:8px;padding:9px 14px;border-radius:12px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:4px 4px 10px var(--shadow-dark,#d4d7de),-4px -4px 10px var(--shadow-light,#fff);text-decoration:none;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;color:#4878a6;border:none;cursor:pointer;transition:box-shadow .15s;width:100%;margin-bottom:6px;justify-content:flex-start}
.act-btn:hover{box-shadow:2px 2px 6px var(--shadow-dark,#d4d7de),-2px -2px 6px var(--shadow-light,#fff)}
.act-btn svg,.stat-btn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
.fact-header-preview{background:linear-gradient(135deg,#6898bf22,#4878a611);border-radius:14px;padding:16px 20px;margin-bottom:16px;border-left:4px solid #4878a6}
#stoa{position:fixed;bottom:20px;right:20px;background:#2c2a28;color:#fff;border-radius:12px;padding:10px 18px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;display:none;z-index:999;box-shadow:0 4px 16px rgba(0,0,0,.2)}
.btn-xs{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 7px var(--shadow-dark,#d4d7de),-3px -3px 6px var(--shadow-light,#fff);border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:11px;font-weight:600;color:#4878a6}
.btn-primary{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:13px;font-weight:700;text-decoration:none;box-shadow:3px 6px 14px rgba(72,120,166,.3)}
.btn-primary svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2}
.btn-secondary{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:var(--bg-primary,var(--bg-primary,#e4e8f0));box-shadow:3px 3px 8px var(--shadow-dark,#d4d7de),-3px -3px 7px var(--shadow-light,#fff);color:#6a6864;border:none;cursor:pointer;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;text-decoration:none}
.btn-secondary svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2}
@media (max-width:900px){.fact-grid{grid-template-columns:1fr}}
</style>
EXTRACSS;

$layout_extra_js = <<<'EXTRAJS'
<script>
const FACT_ID = window.__FACT_ID__ || 0;

function toast(m) { const t=document.getElementById('stoa'); if(!t) return; t.textContent=m||'✅'; t.style.display='block'; clearTimeout(t._t); t._t=setTimeout(()=>t.style.display='none',2000); }

function parseN(s) { return parseFloat((s||'0').replace(/\s/g,'').replace(',','.')) || 0; }

function calcRow(input) {
    const row = input.closest('.ligne-row');
    if (!row) return;
    const q   = parseN(row.querySelector('[name="ligne_quantite[]"]').value);
    const pu  = parseN(row.querySelector('[name="ligne_pu_ht[]"]').value);
    const tva = parseN(row.querySelector('[name="ligne_tva[]"]').value);
    const ht  = Math.round(q * pu * 100) / 100;
    const ttc = Math.round(ht * (1 + tva/100) * 100) / 100;
    row.querySelector('.li-total-ht').textContent  = fmtEur(ht);
    row.querySelector('.li-total-ttc').textContent = fmtEur(ttc);
    calcTotals();
}

function calcTotals() {
    let ht=0, tva=0;
    document.querySelectorAll('.ligne-row').forEach(row => {
        const q   = parseN(row.querySelector('[name="ligne_quantite[]"]').value);
        const pu  = parseN(row.querySelector('[name="ligne_pu_ht[]"]').value);
        const tv  = parseN(row.querySelector('[name="ligne_tva[]"]').value);
        const rht = Math.round(q * pu * 100) / 100;
        ht  += rht;
        tva += Math.round(rht * tv / 100 * 100) / 100;
    });
    ht  = Math.round(ht*100)/100;
    tva = Math.round(tva*100)/100;
    document.getElementById('t-ht').textContent  = fmtEur(ht);
    document.getElementById('t-tva').textContent = fmtEur(tva);
    document.getElementById('t-ttc').textContent = fmtEur(ht+tva);
}

function fmtEur(n) {
    return n.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €';
}

function addLigne(des='', q=1, pu=0, tv=null) {
    if (tv === null) tv = parseN(document.getElementById('tva_defaut')?.value || '20');
    const idx = document.querySelectorAll('.ligne-row').length;
    const ht  = Math.round(q*pu*100)/100;
    const ttc = Math.round(ht*(1+tv/100)*100)/100;
    const html = `<tr class="ligne-row" data-idx="${idx}">
        <td><input type="text" name="ligne_designation[]" class="li" value="${escHtml(des)}" placeholder="Description de la prestation" oninput="calcRow(this)"></td>
        <td><input type="number" name="ligne_quantite[]" class="li" style="text-align:center" value="${q}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td><input type="text" name="ligne_pu_ht[]" class="li" style="text-align:right" value="${pu.toFixed(2).replace('.',',')}" placeholder="0,00" oninput="calcRow(this)"></td>
        <td><input type="number" name="ligne_tva[]" class="li" style="text-align:center" value="${tv}" min="0" max="100" step="0.1" oninput="calcRow(this)"></td>
        <td><span class="li-total-ht" style="display:block;text-align:right;font-family:'DM Mono',monospace;font-size:12px;font-weight:600;padding:6px 9px">${fmtEur(ht)}</span></td>
        <td><span class="li-total-ttc" style="display:block;text-align:right;font-family:'DM Mono',monospace;font-size:12px;color:#4878a6;font-weight:700;padding:6px 9px">${fmtEur(ttc)}</span></td>
        <td><button type="button" class="li-del" onclick="removeLigne(this)">×</button></td>
    </tr>`;
    document.getElementById('lignes-body').insertAdjacentHTML('beforeend', html);
    calcTotals();
}
function escHtml(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

function removeLigne(btn) {
    if (document.querySelectorAll('.ligne-row').length <= 1) return;
    btn.closest('.ligne-row').remove();
    calcTotals();
}

function addPreset(sel) {
    const opt = sel.selectedOptions[0];
    if (!opt || !opt.value) return;
    addLigne(opt.value, 1, parseFloat(opt.dataset.pu)||0, parseFloat(opt.dataset.tva)||20);
    sel.value = '';
}

function updateAllTva(val) {
    document.querySelectorAll('[name="ligne_tva[]"]').forEach(i => { i.value = val; calcRow(i); });
}

function changeStatut(ns) {
    if (!FACT_ID) return;
    fetch('agency_facture_form.php?id='+FACT_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_statut=1&new_statut='+encodeURIComponent(ns)
    }).then(r=>r.json()).then(d=>{
        if (!d.ok) return;
        const labels = {brouillon:'Brouillon',validee:'Validée',envoyee:'Envoyée',payee:'Payée',annulee:'Annulée'};
        const colors = {brouillon:'#9a9690',validee:'#4878a6',envoyee:'#7a6830',payee:'#3a7a6a',annulee:'#8a5040'};
        const bgs    = {brouillon:'var(--bg-primary,#e4e8f0)',validee:'#d8e8f5',envoyee:'#f8eddc',payee:'#d8eee3',annulee:'#fce8e8'};
        const badge  = document.getElementById('stat-badge');
        if (badge) { badge.textContent=labels[ns]; badge.style.color=colors[ns]; badge.style.background=bgs[ns]; }
        const sel = document.querySelector('[name="statut"]');
        if (sel) sel.value = ns;
        document.querySelectorAll('.stat-btn').forEach(b => {
            const m = b.getAttribute('onclick').match(/'([^']+)'/);
            const sv = m?.[1];
            const tick = b.querySelector('span');
            if (sv===ns) { if(!tick){ b.innerHTML+=`<span style="margin-left:auto">✓</span>`; } b.style.boxShadow='inset 2px 2px 5px rgba(0,0,0,.1)'; }
            else         { if(tick) tick.remove(); b.style.boxShadow=''; }
        });
        toast('Statut mis à jour');
    });
}

document.addEventListener('DOMContentLoaded', calcTotals);
</script>
EXTRAJS;

// Injecte FACT_ID avant le script principal
$layout_extra_js = '<script>window.__FACT_ID__ = '.$id.';</script>' . $layout_extra_js;

ob_start();
?>

<?php if (!$isNew && !empty($facture['numero'])): ?>
<h1 style="font-family:'Sora',sans-serif;font-size:20px;font-weight:700;color:#2c2a28;margin-bottom:14px;display:flex;align-items:center;gap:10px">
    <?= h($facture['numero']) ?>
    <span id="stat-badge" style="background:<?= $STAT[$curStat][2]??'var(--bg-primary,#e4e8f0)' ?>;color:<?= $STAT[$curStat][1]??'#9a9690' ?>;padding:3px 12px;border-radius:999px;font-size:12px;font-weight:700;font-family:'DM Mono',monospace"><?= $STAT_LBL[$curStat]??$curStat ?></span>
</h1>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?><div style="background:#d8eee3;color:#3a7a6a;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">✅ Facture enregistrée.</div><?php endif; ?>
<?php if (isset($_GET['payee'])): ?><div style="background:#d8eee3;color:#3a7a6a;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px">✅ Facture marquée comme payée.</div><?php endif; ?>
<?php if (isset($_GET['mail'])): ?><div style="background:<?= $_GET['mail']==='ok'?'#d8eee3':'#fce8e8' ?>;color:<?= $_GET['mail']==='ok'?'#3a7a6a':'#8a5040' ?>;border-radius:10px;padding:8px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px"><?= $_GET['mail']==='ok'?'✅ Facture envoyée par e-mail.':'❌ Erreur lors de l\'envoi.' ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div style="background:#fce8e8;color:#8a5040;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-family:'Sora',sans-serif;font-size:12px"><?= implode('<br>',array_map('h',$errors)) ?></div><?php endif; ?>

<div id="stoa">✅ Sauvegardé</div>

<form method="POST" id="frmFact">
    <input type="hidden" name="save_facture" value="1">
    <div class="fact-grid">

        <!-- Colonne principale -->
        <div>
            <!-- En-tête facture preview -->
            <?php if (!$isNew && $facture['etab_nom']): ?>
            <div class="fact-header-preview">
                <div style="font-family:'Sora',sans-serif;font-weight:700;font-size:14px;color:#2c2a28"><?= h($facture['etab_nom']) ?></div>
                <?php if ($facture['etab_adresse']): ?><div style="font-size:11px;color:#9a9690;margin-top:2px"><?= h($facture['etab_adresse']) ?></div><?php endif; ?>
                <?php if ($facture['etab_siret']): ?><div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin-top:2px">SIRET : <?= h($facture['etab_siret']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Infos générales -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    Informations générales
                </div>
                <div class="f3">
                    <div class="fg">
                        <label>Date d'émission</label>
                        <input type="date" name="date_emission" class="fi" value="<?= h($facture['date_emission']??date('Y-m-d')) ?>">
                    </div>
                    <div class="fg">
                        <label>Date d'échéance</label>
                        <input type="date" name="date_echeance" class="fi" value="<?= h($facture['date_echeance']??'') ?>">
                    </div>
                    <div class="fg">
                        <label>Statut</label>
                        <select name="statut" class="fs">
                            <?php foreach ($STAT_LBL as $sv=>$sl): ?>
                            <option value="<?= $sv ?>" <?= ($curStat===$sv)?'selected':'' ?>><?= $sl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="f2">
                    <?php if ($roleId===1): ?>
                    <div class="fg">
                        <label>Établissement *</label>
                        <select name="id_etablissement" class="fs">
                            <?php foreach ($etabs as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= (($facture['id_etablissement']??($_SESSION['id_etablissement']??0))==$e['id'])?'selected':'' ?>><?= h($e['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="id_etablissement" value="<?= (int)($_SESSION['id_etablissement']??0) ?>">
                    <?php endif; ?>
                    <div class="fg">
                        <label>Mode de paiement</label>
                        <select name="mode_paiement" class="fs">
                            <?php foreach (['virement'=>'Virement','cheque'=>'Chèque','prelevement'=>'Prélèvement','especes'=>'Espèces','cb'=>'Carte bancaire'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= (($facture['mode_paiement']??'virement')===$v)?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Client -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Client / Destinataire
                </div>
                <div class="f2">
                    <div class="fg">
                        <label>Type de client</label>
                        <select name="type_client" class="fs">
                            <?php foreach (['syndicat'=>'Syndicat de copropriété','mandant'=>'Mandant','autre'=>'Autre'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= (($facture['type_client']??'syndicat')===$v)?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Nom du client *</label>
                        <input type="text" name="client" class="fi" value="<?= h($facture['client']??'') ?>" placeholder="Syndicat des copropriétaires de…" required>
                    </div>
                    <div class="fg">
                        <label>Immeuble (texte)</label>
                        <input type="text" name="immeuble_txt" class="fi" value="<?= h($facture['immeuble_txt']??'') ?>" placeholder="Nom ou adresse libre">
                    </div>
                    <div class="fg">
                        <label>Immeuble (liaison BDD)</label>
                        <select name="id_immeuble" class="fs">
                            <option value="">— Aucun —</option>
                            <?php foreach ($immeubles as $im): ?>
                            <option value="<?= $im['id'] ?>" <?= (($facture['id_immeuble']??0)==$im['id'])?'selected':'' ?>><?= h($im['nom']) ?> (<?= h($im['reference']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="f2">
                    <div class="fg">
                        <label>Mail destinataire</label>
                        <input type="email" name="mail_destinataire" class="fi" value="<?= h($facture['mail_destinataire']??'') ?>" placeholder="client@exemple.fr">
                    </div>
                    <div class="fg">
                        <label>Mail CC</label>
                        <input type="email" name="mail_cc" class="fi" value="<?= h($facture['mail_cc']??'') ?>" placeholder="copie@exemple.fr">
                    </div>
                </div>
            </div>

            <!-- Lignes de facturation -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    Lignes de facturation
                    <!-- Presets -->
                    <?php if (!empty($presets)): ?>
                    <div style="margin-left:auto">
                        <select id="preset-select" onchange="addPreset(this)" class="fs" style="height:28px;font-size:11px;width:auto">
                            <option value="">+ Ajouter prestation type</option>
                            <?php foreach ($presets as $p): ?>
                            <option value="<?= h($p['libelle']) ?>" data-pu="<?= $p['prix_unitaire_ht'] ?>" data-tva="<?= $p['tva_taux'] ?>"><?= h($p['libelle']) ?> — <?= eur((float)$p['prix_unitaire_ht']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- TVA défaut -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <span style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690">TVA par défaut :</span>
                    <select name="tva_defaut" id="tva_defaut" class="fs" style="width:90px;height:30px;font-size:12px" onchange="updateAllTva(this.value)">
                        <?php foreach ([0,5.5,10,20] as $tv): ?>
                        <option value="<?= $tv ?>" <?= (($facture['tva_defaut']??20)==$tv)?'selected':'' ?>><?= $tv ?> %</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <table class="lignes-table" id="lignes-table">
                    <thead>
                        <tr>
                            <th style="width:40%">Désignation</th>
                            <th style="width:10%;text-align:center">Qté</th>
                            <th style="width:14%;text-align:right">PU HT</th>
                            <th style="width:10%;text-align:center">TVA %</th>
                            <th style="width:14%;text-align:right">Total HT</th>
                            <th style="width:12%;text-align:right">Total TTC</th>
                            <th style="width:26px"></th>
                        </tr>
                    </thead>
                    <tbody id="lignes-body">
                    <?php
                    $displayLignes = !empty($lignes) ? $lignes : [['designation'=>'','quantite'=>1,'prix_unitaire_ht'=>0,'tva_taux'=>$facture['tva_defaut']??20]];
                    foreach ($displayLignes as $i => $lg):
                    ?>
                    <tr class="ligne-row" data-idx="<?= $i ?>">
                        <td><input type="text" name="ligne_designation[]" class="li" value="<?= h($lg['designation']??'') ?>" placeholder="Description de la prestation" oninput="calcRow(this)"></td>
                        <td><input type="number" name="ligne_quantite[]" class="li" style="text-align:center" value="<?= $lg['quantite']??1 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                        <td><input type="text" name="ligne_pu_ht[]" class="li" style="text-align:right" value="<?= number_format((float)($lg['prix_unitaire_ht']??0),2,',','') ?>" placeholder="0,00" oninput="calcRow(this)"></td>
                        <td><input type="number" name="ligne_tva[]" class="li" style="text-align:center" value="<?= $lg['tva_taux']??20 ?>" min="0" max="100" step="0.1" oninput="calcRow(this)"></td>
                        <td><span class="li-total-ht" style="display:block;text-align:right;font-family:'DM Mono',monospace;font-size:12px;font-weight:600;padding:6px 9px"><?= number_format((float)($lg['quantite']??1)*(float)($lg['prix_unitaire_ht']??0),2,',',' ') ?> €</span></td>
                        <td><span class="li-total-ttc" style="display:block;text-align:right;font-family:'DM Mono',monospace;font-size:12px;color:#4878a6;font-weight:700;padding:6px 9px">
                            <?php $ht=round((float)($lg['quantite']??1)*(float)($lg['prix_unitaire_ht']??0),2); echo number_format($ht+round($ht*(float)($lg['tva_taux']??20)/100,2),2,',',' '); ?> €</span></td>
                        <td><button type="button" class="li-del" onclick="removeLigne(this)">×</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="btn-xs" onclick="addLigne()" style="margin-bottom:16px">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Ajouter une ligne
                </button>
                <!-- Totaux -->
                <div style="max-width:320px;margin-left:auto">
                    <div class="total-row"><span>Total HT</span><span id="t-ht" style="font-family:'DM Mono',monospace"><?= eur((float)($facture['total_ht']??0)) ?></span></div>
                    <div class="total-row"><span>Total TVA</span><span id="t-tva" style="font-family:'DM Mono',monospace"><?= eur((float)($facture['total_tva']??0)) ?></span></div>
                    <div class="total-row grand"><span>Total TTC</span><span id="t-ttc" style="font-family:'DM Mono',monospace;color:#4878a6"><?= eur((float)($facture['total_ttc']??0)) ?></span></div>
                </div>
            </div>

            <!-- Notes -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/></svg>
                    Notes / Mentions légales
                </div>
                <textarea name="notes" class="fta" placeholder="Conditions de paiement, mentions légales, coordonnées bancaires…"><?= h($facture['notes']??'') ?></textarea>
            </div>

            <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:30px">
                <a href="agency_factures.php" class="btn-secondary">Annuler</a>
                <button type="submit" class="btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                    <?= $isNew ? 'Créer la facture' : 'Enregistrer' ?>
                </button>
            </div>
        </div>

        <!-- Sidebar droite -->
        <div>
            <?php if (!$isNew): ?>
            <!-- Statut rapide -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Statut
                </div>
                <?php foreach ($STAT_LBL as $sv=>$sl): ?>
                <button type="button" class="stat-btn" onclick="changeStatut('<?= $sv ?>')"
                    style="background:<?= $STAT[$sv][2]??'var(--bg-primary,#e4e8f0)' ?>;color:<?= $STAT[$sv][1]??'#9a9690' ?>;<?= $curStat===$sv?'box-shadow:inset 2px 2px 5px rgba(0,0,0,.1)':'' ?>">
                    <svg viewBox="0 0 24 24" style="stroke:<?= $STAT[$sv][1]??'#9a9690' ?>"><circle cx="12" cy="12" r="5"/></svg>
                    <?= $sl ?>
                    <?php if ($curStat===$sv): ?><span style="margin-left:auto">✓</span><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Actions -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    Actions
                </div>
                <a href="agency_pdf_facture.php?id=<?= $id ?>" target="_blank" class="act-btn">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Télécharger PDF
                </a>
                <?php if (!empty($facture['mail_destinataire']) && in_array($curStat,['validee','envoyee'])): ?>
                <form method="POST">
                    <button type="submit" name="send_mail" value="1" class="act-btn" style="color:#7a6830"
                        onclick="return confirm('Envoyer la facture à <?= h($facture['mail_destinataire']) ?> ?')">
                        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Envoyer par e-mail
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($curStat !== 'payee' && $curStat !== 'annulee'): ?>
                <form method="POST" onsubmit="return confirm('Marquer comme payée ?')">
                    <button type="submit" name="marquer_payee" value="1" class="act-btn" style="color:#3a7a6a">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        Marquer payée
                    </button>
                </form>
                <?php endif; ?>
                <a href="agency_factures.php?duplicate=<?= $id ?>" class="act-btn">
                    <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    Dupliquer
                </a>
            </div>

            <!-- Récap montants -->
            <div class="fc">
                <div class="fc-title">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    Montants
                </div>
                <div style="font-family:'DM Mono',monospace;font-size:11px;color:#6a6864;margin-bottom:5px">HT : <b><?= eur((float)($facture['total_ht']??0)) ?></b></div>
                <div style="font-family:'DM Mono',monospace;font-size:11px;color:#6a6864;margin-bottom:5px">TVA : <b><?= eur((float)($facture['total_tva']??0)) ?></b></div>
                <div style="font-family:'DM Mono',monospace;font-size:16px;color:#4878a6;font-weight:700">TTC : <?= eur((float)($facture['total_ttc']??0)) ?></div>
                <?php if (!empty($facture['date_paiement'])): ?>
                <div style="margin-top:10px;font-family:'DM Mono',monospace;font-size:10px;color:#3a7a6a">Payé le <?= date('d/m/Y',strtotime($facture['date_paiement'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <!-- /Sidebar -->

    </div>
</form>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
