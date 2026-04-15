<?php
// agency_pdf_contrat_syndic.php — PDF contrat de syndic (4 parties ALUR)
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo = $GLOBALS['pdo'] ?? db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: agency_syndic_contrats.php'); exit; }

$stmt = $pdo->prepare("SELECT cs.*,
    i.nom AS imm_nom, i.adresse AS imm_adresse, i.ville AS imm_ville,
    e.nom AS etab_nom, e.adresse AS etab_adresse, e.code_postal AS etab_cp,
    e.ville AS etab_ville, e.telephone AS etab_tel, e.email AS etab_email,
    e.siret AS etab_siret, e.tva_intracommunautaire AS etab_tva, e.logo AS etab_logo
FROM contrat_syndic cs
LEFT JOIN immeubles i ON i.id=cs.id_immeuble
LEFT JOIN etablissements e ON e.id=cs.id_etablissement
WHERE cs.id=?");
$stmt->execute([$id]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); exit('Contrat introuvable'); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur($v) { return $v ? number_format((float)$v,2,',',' ').' €' : '—'; }
function dtFr($d) { return $d && $d !== '0000-00-00' ? date('d/m/Y',strtotime($d)) : '—'; }
function oui($v) { return $v ? '✓ Oui' : '✗ Non'; }

$tcpdf = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (file_exists($tcpdf)) {
    require_once $tcpdf;

    class PDF_ContratSyndic extends TCPDF {
        public array $data = [];
        public function Header() {
            $this->SetFont('helvetica','B',14);
            $this->SetTextColor(30,58,95);
            $this->SetXY(15,12);
            $this->Cell(90,7,$this->data['etab_nom']??'',0,0,'L');
            $this->SetFont('helvetica','B',12);
            $this->SetTextColor(37,99,235);
            $this->Cell(0,7,'CONTRAT DE SYNDIC',0,1,'R');
            $this->SetFont('helvetica','',8);
            $this->SetTextColor(100,116,139);
            $this->SetX(15);
            $addr = implode(' — ', array_filter([$this->data['etab_adresse']??'', trim(($this->data['etab_cp']??'').' '.($this->data['etab_ville']??''))]));
            $this->Cell(0,4,$addr,0,1,'L');
            if ($this->data['etab_tel']??'') { $this->SetX(15); $this->Cell(0,4,'Tél. '.($this->data['etab_tel']??'').($this->data['etab_email']??''?' — Email : '.($this->data['etab_email']??''):''),0,1,'L'); }
            $this->SetDrawColor(37,99,235); $this->SetLineWidth(0.6);
            $this->Line(15,34,195,34);
        }
        public function Footer() {
            $this->SetY(-14);
            $this->SetFont('helvetica','I',7);
            $this->SetTextColor(148,163,184);
            $this->Cell(0,4,'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages().' — Contrat de syndic — Loi ALUR — '.($this->data['etab_nom']??''),0,0,'C');
        }
        public function sectionTitle(string $t) {
            $this->SetFillColor(30,58,95);
            $this->SetTextColor(255,255,255);
            $this->SetFont('helvetica','B',9);
            $this->Cell(0,7,'  '.$t,0,1,'L',true);
            $this->SetTextColor(30,41,59);
            $this->Ln(2);
        }
        public function row(string $label, string $val, bool $odd=false) {
            if ($odd) $this->SetFillColor(248,250,252); else $this->SetFillColor(255,255,255);
            $this->SetFont('helvetica','B',8);
            $this->SetTextColor(100,116,139);
            $this->Cell(65,5,$label,0,0,'L',$odd);
            $this->SetFont('helvetica','',8);
            $this->SetTextColor(30,41,59);
            $this->MultiCell(0,5,$val,0,'L',$odd,1);
        }
    }

    $pdf = new PDF_ContratSyndic('P','mm','A4');
    $pdf->data = (array)$c;
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Contrat syndic — '.($c['nom_copropriete']??$c['imm_nom']??''));
    $pdf->SetMargins(15,38,15);
    $pdf->SetAutoPageBreak(true,18);
    $pdf->AddPage();

    $pdf->SetY(38);
    $pdf->SetFont('helvetica','',8);
    $pdf->SetTextColor(100,116,139);
    $pdf->Cell(0,5,'Contrat du '.dtFr($c['date_debut']).' au '.dtFr($c['date_fin']),0,1,'R');
    $pdf->Ln(2);

    // ── Identification copropriété ─────────────────────────────────────────
    $pdf->sectionTitle('PARTIE 1 — IDENTIFICATION DE LA COPROPRIÉTÉ');
    $odd = false;
    foreach ([
        ['Nom de la copropriété',    $c['nom_copropriete']??''],
        ['Adresse',                  $c['adresse_copropriete']??''],
        ['N° Immatriculation RCS',   $c['immatriculation_copropriete']??''],
        ['Représentant',             $c['representant_copro']??''],
        ['Date AG de désignation',   dtFr($c['date_ag_designation']??null)],
        ['Nombre de lots total',     $c['nb_lots_total'] ? (int)$c['nb_lots_total'] : ''],
        ['Nombre de lots principaux',$c['nb_lots_principaux'] ? (int)$c['nb_lots_principaux'] : ''],
        ['Assureur RC',              $c['assurance_rc_nom']??''],
        ['Date souscription RC',     dtFr($c['assurance_rc_date']??null)],
    ] as [$l,$v]) { $pdf->row($l, (string)$v, $odd); $odd=!$odd; }

    $pdf->Ln(4);
    $pdf->sectionTitle('Horaires d\'accueil');
    $odd = false;
    foreach ([
        ['Lun–Jeu',     $c['horaires_ouvrables_lun_jeu']??''],
        ['Vendredi',    $c['horaires_ouvrables_vendredi']??''],
        ['Accueil physique Lun–Ven', $c['accueil_physique_lun_ven']??''],
        ['Accueil physique Samedi',  $c['accueil_physique_sam']??''],
        ['Accueil tél. Lun–Ven',     $c['accueil_tel_lun_ven']??''],
        ['Accueil tél. Samedi',      $c['accueil_tel_sam']??''],
    ] as [$l,$v]) { $pdf->row($l,(string)$v,$odd); $odd=!$odd; }

    // ── Rémunération ──────────────────────────────────────────────────────
    $pdf->Ln(5);
    $pdf->sectionTitle('PARTIE 2 — RÉMUNÉRATION ET CONDITIONS');
    $odd = false;
    foreach ([
        ['Rémunération annuelle HT',  eur($c['remuneration_annuelle_ht']??0)],
        ['Rémunération annuelle TTC', eur(($c['remuneration_annuelle_ttc']??0) ?: ($c['remuneration_annuelle_ht']??0)*1.2)],
        ['Honoraires N+1 HT',        eur($c['honoraires_ht_nplus1']??0)],
        ['Fréquence facturation',    ucfirst($c['frequence_facturation']??'')],
        ['Nb visites annuelles',     $c['nb_visites_annuelles']??''],
        ['Durée visite (min)',        $c['duree_visite_minutes']??''],
        ['Réunions CS incluses',     $c['reunions_cs_inclues']??''],
        ['Durée AG (min)',           $c['ag_duree_minutes']??''],
        ['Plage horaire AG',         $c['plage_horaire_ag']??''],
        ['AG supp. incluses',        oui($c['ag_extra_incluse']??0)],
        ['Réunion CS incluse',       oui($c['reunion_cs_incluse']??0)],
        ['Affranchissement inclus',  oui($c['frais_affranchissement_inclus']??0)],
        ['Visite avec rapport',      oui($c['visite_avec_rapport']??0)],
        ['Visite avec CS',           oui($c['visite_avec_cs']??0)],
        ['AG tenue par le syndic',   oui($c['ag_tenue_par_syndic']??0)],
        ['AG tenue par un préposé',  oui($c['ag_tenue_par_prepose']??0)],
    ] as [$l,$v]) { if ((string)$v && $v !== '—') { $pdf->row($l,(string)$v,$odd); $odd=!$odd; } }
    if ($c['conditions_revision']??'') {
        $pdf->row('Conditions de révision', $c['conditions_revision'], $odd);
    }

    // ── Prestations particulières ─────────────────────────────────────────
    $pdf->Ln(5);
    $pdf->sectionTitle('PARTIE 3 — PRESTATIONS PARTICULIÈRES (Décret ALUR n°2015-342)');
    $odd = false;
    foreach ([
        ['Majoration horaire hors plage', $c['maj_horaire_hors_plage'] ? eur($c['maj_horaire_hors_plage']).' / heure' : ''],
        ['Visite hors forfait',       eur($c['tarif_visite_sup']??0)],
        ['AG supplémentaire',         $c['tarif_ag_sup']??''],
        ['Réunion CS supplémentaire', $c['tarif_reunion_cs_sup']??''],
        ['Gestion sinistres incluse', oui($c['gestion_sinistres']??0)],
        ['Déplacement sinistre',      eur($c['tarif_sinistre_deplacement']??0)],
        ['Assistance expertise',      eur($c['tarif_assistance_expertise']??0)],
        ['Suivi assureur',            eur($c['tarif_suivi_assureur']??0)],
        ['Majoration urgence',        $c['frais_urgence_majoration']??''],
        ['Modif. règlement copro',    $c['tarif_modif_reglement_copro']??''],
        ['Publication EDD modifié',   $c['tarif_publication_edd_modifie']??''],
        ['Frais LRAR',                $c['frais_lrar']??''],
        ['Reprise comptabilité ant.', $c['reprise_comptabilite_anterieure']??''],
        ['Dossier emprunt',           $c['dossier_emprunt']??''],
        ['Dossier subvention',        $c['dossier_subvention']??''],
        ['Immatriculation initiale',  $c['immatriculation_initiale']??''],
        ['Défraiement autre',         $c['defraiement_autre']??''],
    ] as [$l,$v]) { if ((string)$v && $v !== '—') { $pdf->row($l,(string)$v,$odd); $odd=!$odd; } }
    if ($c['prestations_particulieres']??'') {
        $pdf->row('Autres prestations', $c['prestations_particulieres'], $odd);
    }

    // ── Prestations copropriétaires ────────────────────────────────────────
    $pdf->Ln(5);
    $pdf->sectionTitle('PARTIE 4 — PRESTATIONS COPROPRIÉTAIRES');
    $odd = false;
    foreach ([
        ['Mise en demeure',           eur($c['frais_recouvrement_mise_demeure']??0)],
        ['Protocole d\'accord',       eur($c['protocole_accord']??0)],
        ['Frais hypothèque',          eur($c['frais_hypotheque']??0)],
        ['Mainlevée',                 eur($c['frais_mainlevee']??0)],
        ['Injonction',                eur($c['frais_injonction']??0)],
        ['Dossier auxiliaire justice',eur($c['frais_dossier_auxiliaire_justice']??0)],
        ['Dossier avocat',            eur($c['frais_dossier_avocat']??0)],
        ['Opposition mutation',       eur($c['frais_opposition_mutation']??0)],
        ['État daté TTC',             eur($c['etat_date_ttc']??0)],
        ['Repro carnet entretien',    eur($c['repro_carnet_entretien']??0)],
        ['Repro diagnostics',         eur($c['repro_diagnostics']??0)],
        ['Copie PV AG',               eur($c['copie_pv_ag']??0)],
    ] as [$l,$v]) { if ($v !== '—') { $pdf->row($l,(string)$v,$odd); $odd=!$odd; } }
    if ($c['ag_sup_copro_text']??'') { $pdf->row('AG supp. copropriété', $c['ag_sup_copro_text'], $odd); $odd=!$odd; }
    if ($c['ag_sup_date']??'') { $pdf->row('Date AG supp.', dtFr($c['ag_sup_date']), $odd); $odd=!$odd; }
    if ($c['ag_sup_lieu']??'') { $pdf->row('Lieu AG supp.', $c['ag_sup_lieu'], $odd); }

    // ── Signatures ────────────────────────────────────────────────────────
    $pdf->Ln(10);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(55,65,81);
    $yS = $pdf->GetY();
    $pdf->SetX(15); $pdf->Cell(80,5,'Le Syndic de copropriété',0,0,'C');
    $pdf->Cell(0,5,'Le Président du Conseil Syndical',0,1,'C');
    $pdf->SetFont('helvetica','I',8); $pdf->SetTextColor(100,116,139);
    $pdf->SetX(15); $pdf->Cell(80,4,'(Signature + cachet)',0,0,'C');
    $pdf->Cell(0,4,'(Signature + Lu et approuvé)',0,1,'C');
    $pdf->SetDrawColor(180,180,180); $pdf->SetLineWidth(0.3);
    $pdf->Line(20,$yS+22,90,$yS+22);
    $pdf->Line(115,$yS+22,190,$yS+22);

    $pdf->Output('Contrat_Syndic_'.($c['nom_copropriete']??$c['id']).'.pdf','I');
    exit;
}

// ── Fallback HTML ─────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Contrat syndic — <?= h($c['nom_copropriete']??$c['imm_nom']??'') ?></title>
<style>
body{font-family:Arial,sans-serif;max-width:820px;margin:0 auto;padding:20px;font-size:13px;color:#ffffff}
.header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:14px;border-bottom:2px solid #1e3a5f;margin-bottom:20px}
.badge{background:#1e3a5f;color:#fff;padding:6px 14px;border-radius:6px;font-weight:700;font-size:12px}
.partie{margin-bottom:24px}
.partie h2{background:#1e3a5f;color:#fff;padding:7px 14px;font-size:12px;border-radius:4px;margin-bottom:0}
table{width:100%;border-collapse:collapse}
tr:nth-child(odd) td{background:#f8fafc}
td{padding:6px 10px;border-bottom:1px solid #f1f5f9}
td:first-child{font-weight:700;color:#64748b;width:45%;font-size:11px;text-transform:uppercase;letter-spacing:.3px}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:40px;text-align:center}
.sign div{border-top:1px solid #ccc;padding-top:8px;font-size:12px;color:#64748b;padding-bottom:40px}
@media print{.no-print{display:none}}
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px">
    <button onclick="window.print()" style="padding:8px 20px;background:#1e3a5f;color:#fff;border:none;border-radius:6px;cursor:pointer">🖨️ Imprimer / PDF</button>
    <a href="agency_syndic_contrats.php" style="margin-left:12px;font-size:13px;color:#1e3a5f">← Retour liste</a>
</div>
<div class="header">
    <div>
        <strong style="font-size:16px"><?= h($c['etab_nom']??'') ?></strong><br>
        <span style="font-size:12px;color:#64748b"><?= h(($c['etab_adresse']??'').' '.($c['etab_cp']??'').' '.($c['etab_ville']??'')) ?></span>
    </div>
    <div><div class="badge">CONTRAT DE SYNDIC</div>
    <div style="font-size:11px;color:#64748b;margin-top:4px;text-align:right"><?= dtFr($c['date_debut']) ?> → <?= dtFr($c['date_fin']) ?></div></div>
</div>

<div class="partie"><h2>PARTIE 1 — Identification de la copropriété</h2><table>
<?php foreach (['nom_copropriete'=>'Nom','adresse_copropriete'=>'Adresse','immatriculation_copropriete'=>'Immatriculation','representant_copro'=>'Représentant','nb_lots_total'=>'Lots total','nb_lots_principaux'=>'Lots principaux','assurance_rc_nom'=>'Assureur RC'] as $k=>$l): ?>
<?php if ($c[$k]??''): ?><tr><td><?= $l ?></td><td><?= h($c[$k]) ?></td></tr><?php endif; ?>
<?php endforeach; ?>
</table></div>

<div class="partie"><h2>PARTIE 2 — Rémunération et conditions</h2><table>
<tr><td>Rémunération HT / an</td><td><?= eur($c['remuneration_annuelle_ht']??0) ?></td></tr>
<tr><td>Rémunération TTC / an</td><td><?= eur(($c['remuneration_annuelle_ttc']??0)?:($c['remuneration_annuelle_ht']??0)*1.2) ?></td></tr>
<?php if ($c['honoraires_ht_nplus1']??''): ?><tr><td>Honoraires N+1 HT</td><td><?= eur($c['honoraires_ht_nplus1']) ?></td></tr><?php endif; ?>
<tr><td>Fréquence facturation</td><td><?= h(ucfirst($c['frequence_facturation']??'')) ?></td></tr>
<tr><td>Nb visites / an</td><td><?= h($c['nb_visites_annuelles']??'—') ?></td></tr>
<tr><td>Réunions CS incluses</td><td><?= h($c['reunions_cs_inclues']??'—') ?></td></tr>
<tr><td>Plage horaire AG</td><td><?= h($c['plage_horaire_ag']??'—') ?></td></tr>
<tr><td>AG supp. incluses</td><td><?= oui($c['ag_extra_incluse']??0) ?></td></tr>
<tr><td>Affranchissement inclus</td><td><?= oui($c['frais_affranchissement_inclus']??0) ?></td></tr>
</table></div>

<div class="partie"><h2>PARTIE 3 — Prestations particulières (Décret ALUR n°2015-342)</h2><table>
<?php foreach (['tarif_ag_sup'=>'AG supplémentaire','tarif_reunion_cs_sup'=>'Réunion CS supp.','tarif_modif_reglement_copro'=>'Modif. règlement','frais_lrar'=>'Frais LRAR','maj_horaire_hors_plage'=>'Majoration horaire'] as $k=>$l): ?>
<?php if ($c[$k]??''): ?><tr><td><?= $l ?></td><td><?= h((string)$c[$k]) ?></td></tr><?php endif; ?>
<?php endforeach; ?>
<?php if ($c['tarif_visite_sup']??''): ?><tr><td>Visite hors forfait</td><td><?= eur($c['tarif_visite_sup']) ?></td></tr><?php endif; ?>
<?php if ($c['tarif_sinistre_deplacement']??''): ?><tr><td>Déplacement sinistre</td><td><?= eur($c['tarif_sinistre_deplacement']) ?></td></tr><?php endif; ?>
<?php if ($c['prestations_particulieres']??''): ?><tr><td>Autres prestations</td><td><?= nl2br(h($c['prestations_particulieres'])) ?></td></tr><?php endif; ?>
</table></div>

<div class="partie"><h2>PARTIE 4 — Prestations copropriétaires</h2><table>
<?php foreach (['frais_recouvrement_mise_demeure'=>'Mise en demeure','protocole_accord'=>'Protocole accord','frais_hypotheque'=>'Hypothèque','frais_mainlevee'=>'Mainlevée','frais_injonction'=>'Injonction','frais_dossier_avocat'=>'Dossier avocat','frais_opposition_mutation'=>'Opposition mutation','etat_date_ttc'=>'État daté TTC','repro_carnet_entretien'=>'Repro carnet','repro_diagnostics'=>'Repro diagnostics','copie_pv_ag'=>'Copie PV AG'] as $k=>$l): ?>
<?php if ($c[$k]??''): ?><tr><td><?= $l ?></td><td><?= eur($c[$k]) ?></td></tr><?php endif; ?>
<?php endforeach; ?>
</table></div>

<div class="sign">
    <div>Le Syndic<br><small>Signature + cachet</small></div>
    <div>Le Président du CS<br><small>Lu et approuvé + Signature + Date</small></div>
</div>
</body>
</html>
