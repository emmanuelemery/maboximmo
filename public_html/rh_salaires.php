<?php
/**
 * rh_salaires.php — Salaires RH (migré layout_maboximmo.php)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_helpers.php';
require_once __DIR__ . '/inc/rh_salaires_parser.php';
require_once __DIR__ . '/inc/rh_salaires_conges_pdf.php';
require_once __DIR__ . '/inc/rh_sepa.php';
require_once __DIR__ . '/inc/mailer.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_login();

$roleId = current_role_id();
if (!in_array($roleId, [1, 2, 3], true)) {
    deny_access('Accès RH restreint aux admins, managers et collaborateurs.');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

// Create Externe société if it doesn't exist
try {
    $stmt = $pdo->prepare("SELECT id FROM societes WHERE nom = ?");
    $stmt->execute(['Externe']);
    if ($stmt->rowCount() === 0) {
        $pdo->exec("INSERT INTO societes (nom, actif) VALUES ('Externe', 1)");
    }
} catch (Exception $e) {
    // Société might exist, continue
}

// Add missing columns if needed
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
    $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (!in_array('mois_cloture', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN mois_cloture DATETIME NULL");
    }
    if (!in_array('commentaire_general', $existing)) {
        $pdo->exec("ALTER TABLE salaires ADD COLUMN commentaire_general TEXT NULL");
    }
} catch (Exception $e) {}

// Ensure societes comptable + emetteur columns
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM societes");
    $existingSoc = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $colsSoc = [
        'comptable_nom' => 'VARCHAR(120) NULL',
        'comptable_email' => 'VARCHAR(190) NULL',
        'comptable_telephone' => 'VARCHAR(50) NULL',
        'comptable_societe' => 'VARCHAR(190) NULL',
        'rib_emetteur_nom' => 'VARCHAR(190) NULL',
        'rib_emetteur_iban' => 'VARCHAR(64) NULL',
        'rib_emetteur_bic' => 'VARCHAR(16) NULL'
    ];
    foreach ($colsSoc as $col => $type) {
        if (!in_array($col, $existingSoc, true)) {
            $pdo->exec("ALTER TABLE societes ADD COLUMN `$col` $type");
        }
    }
} catch (Exception $e) {}

// Ensure comparaison table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_salaires_comparaisons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_societe INT NOT NULL,
        mois TINYINT NOT NULL,
        annee SMALLINT NOT NULL,
        type VARCHAR(30) NOT NULL,
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        total_pdf_brut DECIMAL(12,2) NULL,
        total_expected_brut DECIMAL(12,2) NULL,
        total_pdf_net DECIMAL(12,2) NULL,
        compare_ok TINYINT DEFAULT 0,
        compare_json LONGTEXT NULL,
        parsed_json LONGTEXT NULL,
        sepa_path VARCHAR(500) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        INDEX idx_soc_mois (id_societe, mois, annee),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m){ $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[(int)$m]??''; }
function first_day_of($y,$m){ return sprintf('%04d-%02d-01',$y,$m); }
function qs_keep(array $keep){ $o=[]; foreach($keep as $k){ if(isset($_GET[$k])) $o[$k]=$_GET[$k]; } return http_build_query($o); }
function fmt_val($v, $type){
  if($v === null || $v === '') return '';
  if($type === 'money') return number_format((float)$v, 2, ',', '');
  if($type === 'int') return (string)(int)$v;
  return (string)$v;
}

function rh_expected_salary_lines(array $u): array {
  $brut = !empty($u['salaire_brut_base']) ? (float)$u['salaire_brut_base'] : 0;
  $mois_anci = !empty($u['anciennete']) ? (int)$u['anciennete'] : 0;
  $anci_val = ($mois_anci > 0 && $brut > 0) ? ($brut * $mois_anci * 0.01 / 12) : 0;
  $lines = [
    'Salaire de base' => $brut,
    'Prime ancienneté' => $anci_val,
    'Avantage en nature' => !empty($u['avantage_nature']) ? (float)$u['avantage_nature'] : 0,
    'Heures supp' => !empty($u['heures_supp']) ? (float)$u['heures_supp'] : 0,
    'Commissions CA' => !empty($u['commission_ca']) ? (float)$u['commission_ca'] : 0,
    'Commissions NA' => !empty($u['commission_ca_nouvelles_affaires']) ? (float)$u['commission_ca_nouvelles_affaires'] : 0,
    'Prime administrative' => !empty($u['prime_admin']) ? (float)$u['prime_admin'] : 0,
    'Prime exceptionnelle' => !empty($u['prime_exceptionnelle']) ? (float)$u['prime_exceptionnelle'] : 0,
    'Treizieme mois' => !empty($u['treizieme_mois']) ? (float)$u['treizieme_mois'] : 0,
    'Indemnité km' => !empty($u['total_ik']) ? (float)$u['total_ik'] : 0,
    'Remboursement achat' => !empty($u['remboursement_achat']) ? (float)$u['remboursement_achat'] : 0,
    'Frais professionnels' => !empty($u['frais_professionnels']) ? (float)$u['frais_professionnels'] : 0,
    'Frais reception' => !empty($u['frais_reception']) ? (float)$u['frais_reception'] : 0,
    'Stationnement' => !empty($u['stationnement']) ? (float)$u['stationnement'] : 0,
    'Frais deplacement' => !empty($u['frais_deplacement']) ? (float)$u['frais_deplacement'] : 0,
  ];
  return array_filter($lines, fn($v) => abs((float)$v) > 0.009);
}

function rh_expected_brut_total(array $u): float {
  $brut = !empty($u['salaire_brut_base']) ? (float)$u['salaire_brut_base'] : 0;
  $mois_anci = !empty($u['anciennete']) ? (int)$u['anciennete'] : 0;
  $anci_val = ($mois_anci > 0 && $brut > 0) ? ($brut * $mois_anci * 0.01 / 12) : 0;
  $total = $brut
         + (!empty($u['treizieme_mois']) ? (float)$u['treizieme_mois'] : 0)
         + $anci_val
         + (!empty($u['commission_ca']) ? (float)$u['commission_ca'] : 0)
         + (!empty($u['commission_ca_nouvelles_affaires']) ? (float)$u['commission_ca_nouvelles_affaires'] : 0)
         + (!empty($u['avantage_nature']) ? (float)$u['avantage_nature'] : 0)
         + (!empty($u['heures_supp']) ? (float)$u['heures_supp'] : 0)
         + (!empty($u['frais_professionnels']) ? (float)$u['frais_professionnels'] : 0)
         + (!empty($u['frais_reception']) ? (float)$u['frais_reception'] : 0)
         + (!empty($u['prime_admin']) ? (float)$u['prime_admin'] : 0)
         + (!empty($u['prime_exceptionnelle']) ? (float)$u['prime_exceptionnelle'] : 0)
         + (!empty($u['stationnement']) ? (float)$u['stationnement'] : 0)
         + (!empty($u['frais_deplacement']) ? (float)$u['frais_deplacement'] : 0)
         + (!empty($u['remboursement_achat']) ? (float)$u['remboursement_achat'] : 0)
         + (!empty($u['total_ik']) ? (float)$u['total_ik'] : 0);
  return $total;
}

function rh_compare_bulletins_expected(array $expectedByKey, array $parsedEmployees, float $tol = 0.02): array {
  $rows = [];
  $missing = [];
  $extra = [];
  $totalExpected = 0.0;
  $totalPdf = 0.0;

  foreach ($expectedByKey as $key => $exp) {
    $totalExpected += $exp['total_brut'];
    if (!isset($parsedEmployees[$key])) {
        $missing[] = $exp['name'];
        $rows[] = [
            'name' => $exp['name'],
            'expected_brut' => $exp['total_brut'],
            'pdf_brut' => null,
            'brut_diff' => null,
            'status' => 'missing',
            'line_diffs' => []
        ];
        $allOk = false;
        continue;
    }
    $pdf = $parsedEmployees[$key];
    $pdfBrut = isset($pdf['brut']) ? (float)$pdf['brut'] : null;
    if ($pdfBrut !== null) {
        $totalPdf += $pdfBrut;
    }
    $lineDiffs = [];
    $rowOk = true;
    foreach ($exp['lines'] as $label => $amount) {
        $pdfAmount = $pdf['items'][$label] ?? null;
        if ($pdfAmount === null) {
            $rowOk = false;
            $lineDiffs[] = ['label'=>$label,'expected'=>$amount,'pdf'=>null,'diff'=>null,'status'=>'missing'];
            continue;
        }
        $diff = (float)$pdfAmount - (float)$amount;
        $status = (abs($diff) <= $tol) ? 'ok' : 'diff';
        if ($status !== 'ok') $rowOk = false;
        $lineDiffs[] = ['label'=>$label,'expected'=>$amount,'pdf'=>$pdfAmount,'diff'=>$diff,'status'=>$status];
    }

    $brutDiff = null;
    if ($pdfBrut === null) {
        $rowOk = false;
    } else {
        $brutDiff = $pdfBrut - $exp['total_brut'];
        if (abs($brutDiff) > $tol) $rowOk = false;
    }

    if (!$rowOk) $allOk = false;
    $rows[] = [
        'name' => $exp['name'],
        'expected_brut' => $exp['total_brut'],
        'pdf_brut' => $pdfBrut,
        'brut_diff' => $brutDiff,
        'status' => $rowOk ? 'ok' : 'diff',
        'line_diffs' => $lineDiffs
    ];
  }

  foreach ($parsedEmployees as $key => $pdf) {
      if (!isset($expectedByKey[$key])) {
          $extra[] = $pdf['name'] ?? $key;
          $allOk = false;
      }
  }

  return [
    'ok' => $allOk && empty($missing) && empty($extra),
    'rows' => $rows,
    'missing' => $missing,
    'extra' => $extra,
    'total_expected' => $totalExpected,
    'total_pdf' => $totalPdf,
  ];
}

function rh_load_expected_map(PDO $pdo, int $societeId, string $moisRef): array {
  // Clé de matching : users.matricule_paie (logiciel de paie comptable),
  // alimenté via la migration 2026_05_04_users_matricule_paie. Les salariés
  // sans matricule sont ignorés du comparateur.
  $stmt = $pdo->prepare("
    SELECT u.id, u.matricule_paie, u.prenom, u.nom, u.id_legacy, s.*
    FROM users u
    LEFT JOIN salaires s ON (s.id_user = u.id OR (u.id_legacy IS NOT NULL AND s.id_user = u.id_legacy)) AND s.mois_reference = :mr
    WHERE u.actif = 1 AND u.est_salarie = 1 AND u.id_societe = :soc
      AND u.matricule_paie IS NOT NULL AND u.matricule_paie <> ''
    ORDER BY u.nom, u.prenom
  ");
  $stmt->execute([':mr' => $moisRef, ':soc' => $societeId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($rows as $row) {
      $name = trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''));
      $key = (string)($row['matricule_paie'] ?? '');
      if ($key === '') continue;
      $map[$key] = [
          'name' => $name,
          'matricule' => $key,
          'lines' => rh_expected_salary_lines($row),
          'total_brut' => rh_expected_brut_total($row),
      ];
  }
  return $map;
}

// Handle email sending
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_email_user'])) {
    verify_csrf();
    $idUser = (int)($_POST['send_email_user'] ?? 0);
    $mois_email = (int)($_POST['mois_email'] ?? date('n'));
    $annee_email = (int)($_POST['annee_email'] ?? date('Y'));

    $stmtUser = $pdo->prepare("SELECT email, prenom, nom FROM users WHERE id=? AND actif=1");
    $stmtUser->execute([$idUser]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($user['email'])) {
        $mois_label = mois_fr($mois_email);
        $nom_complet = trim(($user['prenom']??'').' '.($user['nom']??''));
        $subject = "MaBoxImmo - Création du salaire de $mois_label $annee_email";

        // Generate PDF content
        $pdfPath = null;
        $tcpdfFound = false;
        foreach ([__DIR__ . '/tcpdf/tcpdf.php', __DIR__ . '/tcpdf_min/tcpdf.php'] as $p) {
            if (is_file($p)) {
                require_once $p;
                $tcpdfFound = true;
                break;
            }
        }

        if ($tcpdfFound) {
            try {
                // Setup TCPDF cache directory
                if (!defined('K_PATH_CACHE')) {
                    $cacheDir = __DIR__ . '/tcpdf_cache/';
                    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
                    define('K_PATH_CACHE', $cacheDir);
                }

                class SalaryPDF extends TCPDF {
                    public function Footer() {
                        $this->SetY(-15);
                        $this->SetFont('dejavusans', 'I', 8);
                        $this->SetTextColor(128, 128, 128);
                        $this->Cell(0, 10, 'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
                    }
                }

                $pdf = new SalaryPDF('P', 'mm', 'A4', true, 'UTF-8', false);
                $pdf->SetCreator('MaBoxImmo');
                $pdf->SetTitle('Salaire ' . $mois_label . ' ' . $annee_email);
                $pdf->SetMargins(10, 10, 10);
                $pdf->SetAutoPageBreak(true, 15);
                $pdf->AddPage();

                // Title
                $pdf->SetFont('dejavusans', 'B', 14);
                $pdf->Cell(0, 10, 'Rappel de création de salaire', 0, 1, 'C');
                $pdf->Ln(5);

                // Content
                $pdf->SetFont('dejavusans', '', 11);
                $pdf->MultiCell(0, 5, "Madame, Monsieur $nom_complet,\n\nVeuillez créer ou mettre à jour votre fiche salaire pour le mois de $mois_label $annee_email avant le 29 du mois au plus tard.");
                $pdf->Ln(5);

                $pdf->SetFont('dejavusans', 'I', 10);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->MultiCell(0, 4, "Pour accéder à votre espace: rh_salaires_user.php?mois=" . $mois_email . "&annee=" . $annee_email);

                // Save PDF to temp — tempnam() génère un nom non prévisible
                $tempDir = sys_get_temp_dir();
                $pdfPath = tempnam($tempDir, 'mbi_sal_') . '.pdf';
                $pdf->Output($pdfPath, 'F');
            } catch (Exception $e) {
                // Continue without PDF if generation fails
            }
        }

        // HTML email body
        $htmlMessage = <<<HTML
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .header { background-color: #2d5016; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; line-height: 1.6; }
        .footer { background-color: #f5f5f5; padding: 10px; text-align: center; font-size: 12px; color: #999; }
        .deadline { background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MaBoxImmo</h1>
        <p>Gestion des salaires</p>
    </div>
    <div class="content">
        <p>Madame, Monsieur <strong>$nom_complet</strong>,</p>
        <p>Nous vous demandons de bien vouloir créer ou mettre à jour votre fiche salaire pour le mois de <strong>$mois_label $annee_email</strong>.</p>
        <div class="deadline">
            <strong>⚠️ Date limite :</strong> Merci de compléter cette saisie avant le <strong>29 du mois en cours</strong> au plus tard.
        </div>
        <p>Connectez-vous à votre espace MaBoxImmo pour procéder à cette saisie.</p>
        <p>Si vous rencontrez des difficultés, n'hésitez pas à contacter l'équipe RH.</p>
        <p><strong>Cordialement,</strong><br>L'équipe MaBoxImmo</p>
    </div>
    <div class="footer">
        <p>Email automatisé - Veuillez ne pas répondre à cet email</p>
    </div>
</body>
</html>
HTML;

        // Send email with attachment
        $attachments = $pdfPath ? [$pdfPath] : [];
        if (send_mail($user['email'], $subject, $htmlMessage, $attachments, true)) {
            // Clean up temp file
            if ($pdfPath && is_file($pdfPath)) {
                @unlink($pdfPath);
            }
            http_response_code(200);
            exit('Email envoyé avec succès');
        } else {
            // Clean up temp file on error
            if ($pdfPath && is_file($pdfPath)) {
                @unlink($pdfPath);
            }
            http_response_code(400);
            exit('Erreur lors de l\'envoi de l\'email');
        }
    }
    http_response_code(400);
    exit('Utilisateur non trouvé');
}

// Handle month closure (admin ou gestionnaire agence)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['close_month'])) {
    verify_csrf();
    $roleId      = current_role_id();
    if ($roleId !== 1) {
        http_response_code(403);
        exit('Accès refusé');
    }

    $mois = (int)($_POST['close_month_mois']??0);
    $annee = (int)($_POST['close_month_annee']??0);

    if ($mois < 1 || $mois > 12) {
        http_response_code(400);
        exit('Mois invalide');
    }

    $mois_ref = sprintf('%04d-%02d-01', $annee, $mois);
    $now = new DateTime('now', new DateTimeZone('Europe/Paris'));

    // Admin uniquement : clôture tous les salaires du mois
    $stmt = $pdo->prepare("UPDATE salaires SET mois_cloture=? WHERE mois_reference LIKE ?");

    // Close congés via mois_clos
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `mois_clos` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `mois` TINYINT NOT NULL,
          `annee` SMALLINT NOT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_mois (mois, annee)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmtClos = $pdo->prepare("INSERT IGNORE INTO mois_clos (mois, annee) VALUES (?, ?)");
        $stmtClos->execute([$mois, $annee]);
    } catch (Exception $e) {}
    $stmt->execute([$now->format('Y-m-d H:i:s'), "$annee-$mois-%"]);

    http_response_code(200);
    exit('Mois clôturé avec succès');
}

// Permission gestion salaires agence (non-admin avec flag spécial)
$agenceScope = can_manage_salaires_agence(); // 0 = pas de scope spécial, >0 = id_agence forcé

// Scope "me" : filtre forcé sur l'user connecté (même si manager/gestion_salaires).
// Utilisé quand on arrive via le bouton "Ouvrir le mois en cours" de rh_salaires_user_list.
$scopeMe = isset($_GET['scope']) && $_GET['scope'] === 'me';

$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$mois_sel    = $_GET['mois']    ?? $now->format('n');
$annee_sel   = $_GET['annee']   ?? $now->format('Y');
$societe_sel = $_GET['societe'] ?? 'toutes';
// Si l'utilisateur a un scope agence forcé, ignorer le paramètre GET
$agence_sel  = $agenceScope > 0 ? (string)$agenceScope : ($_GET['agence'] ?? 'toutes');
$modeles_only = !empty($_GET['modeles_only']) && $roleId === 1 ? true : false;

// Check if month is closed
$mois_ref_check = sprintf('%04d-%02d-01', (int)$annee_sel, (int)$mois_sel);
$stmtCheck = $pdo->prepare("SELECT COUNT(*) as cnt FROM salaires WHERE mois_reference LIKE ? AND mois_cloture IS NOT NULL LIMIT 1");
$stmtCheck->execute([sprintf('%04d-%02d-%%', (int)$annee_sel, (int)$mois_sel)]);
$monthClosed = (bool)$stmtCheck->fetch(PDO::FETCH_ASSOC)['cnt'];

$mois_int  = ($mois_sel!=='tous')?(int)$mois_sel:(int)$now->format('n');
$annee_int = ($annee_sel!=='toutes')?(int)$annee_sel:(int)$now->format('Y');
$mois_ref  = first_day_of($annee_int,$mois_int);
$lib_mois_annee = mois_fr($mois_int).' '.$annee_int;

$societes = $pdo->query("SELECT id, nom FROM societes WHERE nom != 'Externe' ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
$agences  = $pdo->query("SELECT id, nom_agence, id_societe FROM agences ORDER BY nom_agence ASC")->fetchAll(PDO::FETCH_ASSOC);

// Société / agence de l'utilisateur connecté (valeurs par défaut)
$stmtUserInfo = $pdo->prepare("SELECT id_societe, id_agence FROM users WHERE id = ? LIMIT 1");
$stmtUserInfo->execute([$_SESSION['user_id'] ?? 0]);
$userInfo = $stmtUserInfo->fetch(PDO::FETCH_ASSOC) ?: [];
$user_societe_default = !empty($userInfo['id_societe']) ? (string)$userInfo['id_societe'] : 'toutes';
$user_agence_default  = !empty($userInfo['id_agence'])  ? (string)$userInfo['id_agence']  : 'toutes';

// Collaborateur sans gestion_salaires : forcer sur sa propre société/agence (pas de navigation)
$isSimpleCollab = ($roleId === 3 && $agenceScope === 0);
// Manager (role 2) sans gestion_salaires : aucun droit de voir d'autres salaires
// → même traitement que simple collab (ne voit que son propre salaire)
if ($roleId === 2 && $agenceScope === 0) {
    $isSimpleCollab = true;
}
if ($isSimpleCollab) {
    $societe_sel = $user_societe_default;
    $agence_sel  = $user_agence_default;
}

// ── Sécurité : seul l'admin peut basculer de société / voir "toutes"
// Pour tous les autres (y compris gestion_salaires=1), on force
// systématiquement la société sur celle de l'utilisateur, peu importe
// les paramètres GET. Empêche ?societe=toutes ou ?societe=X d'exposer
// des salaires d'autres sociétés (cas Géraldine : agenceScope forcera
// aussi son agence).
if ($roleId !== 1) {
    $societe_sel = $user_societe_default;
    if ($societe_sel === 'toutes' && !empty($userInfo['id_societe'])) {
        $societe_sel = (string)$userInfo['id_societe'];
    }
}
// Admin : défaut sur sa propre société uniquement si aucun GET
if (!isset($_GET['societe']) && $roleId === 1 && $user_societe_default !== 'toutes') {
    $societe_sel = $user_societe_default;
}

// Agences filtrées selon la société sélectionnée
$agences_filtered = ($societe_sel !== 'toutes')
    ? array_values(array_filter($agences, fn($a) => (string)$a['id_societe'] === (string)$societe_sel))
    : $agences;

$societeInfo = [];
$projetRow = null;
$bulletinsRow = null;
$projetData = null;
$bulletinsData = null;

if ($societe_sel !== 'toutes') {
    $stmtSocInfo = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
    $stmtSocInfo->execute([(int)$societe_sel]);
    $societeInfo = $stmtSocInfo->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtProj = $pdo->prepare("SELECT * FROM rh_salaires_comparaisons WHERE id_societe = ? AND mois = ? AND annee = ? AND type = 'projet' ORDER BY created_at DESC LIMIT 1");
    $stmtProj->execute([(int)$societe_sel, (int)$mois_sel, (int)$annee_sel]);
    $projetRow = $stmtProj->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($projetRow) {
        $projetData = json_decode($projetRow['compare_json'] ?? '', true) ?: null;
    }

    $stmtBull = $pdo->prepare("SELECT * FROM rh_salaires_comparaisons WHERE id_societe = ? AND mois = ? AND annee = ? AND type = 'bulletins' ORDER BY created_at DESC LIMIT 1");
    $stmtBull->execute([(int)$societe_sel, (int)$mois_sel, (int)$annee_sel]);
    $bulletinsRow = $stmtBull->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($bulletinsRow) {
        $bulletinsData = json_decode($bulletinsRow['compare_json'] ?? '', true) ?: null;
    }
}

$COLS = [
  'salaire_brut_base'                 => ['label'=>'Brut','type'=>'money'],
  'treizieme_mois'                    => ['label'=>'13e','type'=>'money'],
  'anciennete'                        => ['label'=>'Anc.','type'=>'int'],
  'avantage_nature'                   => ['label'=>'Av. nat.','type'=>'money'],
  'heures_supp'                       => ['label'=>'H. supp','type'=>'money'],
  'commission_ca'                     => ['label'=>'Comm. CA','type'=>'money'],
  'commission_ca_nouvelles_affaires'  => ['label'=>'Comm. CA NA','type'=>'money'],
  'ik_nb_km'                          => ['label'=>'IK km','type'=>'money'],
  'total_ik'                          => ['label'=>'IK tot.','type'=>'money'],
  'remboursement_achat'               => ['label'=>'Achats','type'=>'money'],
  'frais_professionnels'              => ['label'=>'Frais prof.','type'=>'money'],
  'frais_reception'                   => ['label'=>'Récep.','type'=>'money'],
  'prime_admin'                       => ['label'=>'Prime admin','type'=>'money'],
  'prime_exceptionnelle'              => ['label'=>'Prime exc.','type'=>'money'],
  'stationnement'                     => ['label'=>'Station.','type'=>'money'],
  'frais_deplacement'                 => ['label'=>'Frais dépl.','type'=>'money'],
];

$COLS_ACTIONS = [
  'vehicule_utilise'                  => ['label'=>'Véhicule','type'=>'text'],
  'ik_montant'                        => ['label'=>'IK €','type'=>'money'],
];

$currentQS = qs_keep(['mois','annee','societe','agence']);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['send_to_comptable']) || isset($_POST['upload_projet_pdf']) || isset($_POST['upload_bulletins_pdf']))
) {
    verify_csrf();
    if ($roleId !== 1) {
        http_response_code(403);
        exit('Accès refusé');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_to_comptable'])) {
    $societeId = (int)($_POST['societe_id'] ?? 0);
    $moisPost = (int)($_POST['mois'] ?? date('n'));
    $anneePost = (int)($_POST['annee'] ?? date('Y'));
    // Le workflow comptable est PAR AGENCE : le PDF doit contenir uniquement
    // les users de l'agence sélectionnée. agenceScope (gestion_salaires=1) prime
    // sur le POST pour empêcher tout bricolage côté client.
    $idAgenceLog = $agenceScope > 0 ? $agenceScope : (int)($_POST['agence'] ?? 0);
    if ($societeId <= 0) {
        $_SESSION['message_err'] = 'Sélectionnez une société avant l\'envoi.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }
    if ($idAgenceLog <= 0) {
        $_SESSION['message_err'] = 'Sélectionnez une agence avant l\'envoi (le PDF est généré par agence, pas pour toute la société).';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }
    $stmtSoc = $pdo->prepare("SELECT * FROM societes WHERE id = ? LIMIT 1");
    $stmtSoc->execute([$societeId]);
    $societe = $stmtSoc->fetch(PDO::FETCH_ASSOC) ?: [];
    $to = trim((string)($societe['comptable_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message_err'] = 'Email comptable manquant pour cette société.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }
    // Nom de l'agence pour le filename + le sujet du mail
    $stmtAg = $pdo->prepare("SELECT nom_agence FROM agences WHERE id = ? LIMIT 1");
    $stmtAg->execute([$idAgenceLog]);
    $nomAgence = (string)($stmtAg->fetchColumn() ?: ('Agence_' . $idAgenceLog));

    $socName   = (string)($societe['nom'] ?? 'Société');
    $moisLabel = mois_fr($moisPost);

    try {
        // PDF scopé sur l'agence : le 4e param de rh_generate_salaires_conges_pdf
        // applique "AND u.id_agence = X" → uniquement les users de l'agence cible.
        $pdfContent = rh_generate_salaires_conges_pdf($pdo, $moisPost, $anneePost, $idAgenceLog);
        // Nom standardisé : Salaires_congés_<NOM_AGENCE>_<YYYY-MM>.pdf
        $pdfFilename = rh_wf_pdf_filename($nomAgence, $anneePost, $moisPost);
        $tmp = sys_get_temp_dir();
        $pdfPath = $tmp . DIRECTORY_SEPARATOR . $pdfFilename;
        file_put_contents($pdfPath, $pdfContent);

        // Mail personnalisé avec prénom du comptable si renseigné
        $comptableNom = trim((string)($societe['comptable_nom'] ?? ''));
        $bonjour = $comptableNom !== ''
            ? 'Bonjour ' . trim((string)preg_split('/\s+/', $comptableNom)[0])
            : 'Bonjour';
        $subject = "Salaires & Congés — $nomAgence — $moisLabel $anneePost";
        $body = "$bonjour,\n\nVeuillez trouver en pièce jointe le registre des salaires et congés pour $nomAgence ($moisLabel $anneePost).\n\nCordialement,\nRégie EMERY";

        // ─── DEV / LOCAL : pas d'envoi réel — on logue + on conserve le PDF ───
        // Détection environnement : dev.maboximmo.fr ou localhost = mode test
        // (ne JAMAIS spammer le comptable depuis dev). Sur prod, envoi normal.
        $hostNow = (string)($_SERVER['HTTP_HOST'] ?? '');
        $isDevOrLocal = (
            str_contains($hostNow, 'dev.maboximmo')
            || str_contains($hostNow, 'localhost')
            || str_contains($hostNow, '127.0.0.1')
        );

        // Emmanuel toujours en copie des envois comptable (suivi central)
        $ccDirection = 'emmanuel.emery@regie-emery.com';

        if ($isDevOrLocal) {
            // Mode test : pas d'envoi mail, on simule l'OK pour journaliser le PDF
            $ok = true;
            $devMessage = ' (mode test dev — mail NON envoyé, PDF conservé pour téléchargement)';
        } else {
            $ok = send_mail($to, $subject, $body, [$pdfPath], false, $ccDirection, 'salaire@maboximmo.fr');
            $devMessage = '';
        }

        // Workflow log : conserver le PDF et journaliser l'envoi (par agence)
        // ($idAgenceLog déjà calculé en début de handler)
        $loggedOk = false;
        if ($idAgenceLog > 0 && $ok) {
            $moisRefLog = sprintf('%04d-%02d-01', $anneePost, $moisPost);
            $relPath = rh_wf_save_file($societeId, $idAgenceLog, $moisRefLog, RH_WF_TYPE_ENVOI,
                rh_wf_next_iteration($pdo, $idAgenceLog, $moisRefLog, RH_WF_TYPE_ENVOI),
                $pdfContent, basename($pdfPath));
            rh_wf_log_action($pdo, $societeId, $idAgenceLog, $moisRefLog, RH_WF_TYPE_ENVOI,
                $relPath, basename($pdfPath), strlen($pdfContent), $to,
                (int)current_user_id(), 'ok',
                null,
                $isDevOrLocal ? '🧪 Mode test (mail non envoyé)' : null);
            $loggedOk = true;
        }

        if (is_file($pdfPath)) { @unlink($pdfPath); }

        if ($ok && $loggedOk) {
            $_SESSION['message_ok'] = 'PDF envoyé au comptable et archivé dans l\'historique ✅' . $devMessage;
        } elseif ($ok && !$loggedOk) {
            // Mail parti (ou simulé en dev) mais pas archivé → agence absente
            $_SESSION['message_err'] = '⚠️ PDF traité mais NON archivé : aucune agence sélectionnée. '
                . 'Sélectionne une agence avant l\'envoi pour conserver le PDF dans l\'historique.';
        } else {
            $_SESSION['message_err'] = 'Erreur lors de l\'envoi du mail au comptable.';
        }
    } catch (Throwable $e) {
        $_SESSION['message_err'] = 'Erreur génération PDF: ' . $e->getMessage();
    }

    header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_projet_pdf'])) {
    $societeId = (int)($_POST['societe_id'] ?? 0);
    $moisPost = (int)($_POST['mois'] ?? date('n'));
    $anneePost = (int)($_POST['annee'] ?? date('Y'));
    if ($societeId <= 0) {
        $_SESSION['message_err'] = 'Sélectionnez une société avant l\'import.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }
    if (empty($_FILES['projet_pdf']) || $_FILES['projet_pdf']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['message_err'] = 'Fichier PDF projet manquant.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $file = $_FILES['projet_pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        $_SESSION['message_err'] = 'Le fichier doit être un PDF.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $destDir = __DIR__ . '/uploads/salaires_comptable';
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0777, true);
    }
    $destName = 'projet_' . $societeId . '_' . $anneePost . str_pad((string)$moisPost, 2, '0', STR_PAD_LEFT) . '_' . time() . '.pdf';
    $destPath = $destDir . '/' . $destName;
    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        $_SESSION['message_err'] = 'Impossible de sauvegarder le PDF.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $moisRef = sprintf('%04d-%02d-01', $anneePost, $moisPost);
    $idAgenceLog = $agenceScope > 0 ? $agenceScope : (int)($_POST['agence'] ?? 0);

    // Archivage workflow_log AVANT extraction : garantit que le PDF apparait
    // dans "Historique des échanges" même si le parsing échoue (ex. extraction
    // PDF impossible sur prod sans pdftotext / vendor).
    $relPathLog = null;
    $contentSize = null;
    if ($idAgenceLog > 0) {
        $iter = rh_wf_next_iteration($pdo, $idAgenceLog, $moisRef, RH_WF_TYPE_PROJET);
        $content = @file_get_contents($destPath);
        if ($content !== false) {
            $contentSize = strlen($content);
            $relPathLog = rh_wf_save_file($societeId, $idAgenceLog, $moisRef, RH_WF_TYPE_PROJET,
                $iter, $content, $file['name']);
        }
    }

    $meta = [];
    $parsed = rh_parse_bulletins_file($destPath, $meta);
    if (empty($parsed['ok'])) {
        if ($idAgenceLog > 0 && $relPathLog) {
            rh_wf_log_action($pdo, $societeId, $idAgenceLog, $moisRef, RH_WF_TYPE_PROJET,
                $relPathLog, $file['name'], $contentSize, null,
                (int)current_user_id(), 'error',
                'Extraction PDF impossible (' . ($parsed['error'] ?? 'parser KO') . ')',
                'PDF archivé — reparse manuel possible.');
        }
        $_SESSION['message_err'] = 'Extraction PDF impossible — le PDF a été archivé dans l\'historique.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $expected = rh_load_expected_map($pdo, $societeId, $moisRef);
    $employees = $parsed['data']['employees'] ?? [];
    $compare = rh_compare_bulletins_expected($expected, $employees);

    $stmt = $pdo->prepare("INSERT INTO rh_salaires_comparaisons (id_societe, mois, annee, type, file_name, file_path, total_pdf_brut, total_expected_brut, compare_ok, compare_json, parsed_json, created_by) VALUES (?, ?, ?, 'projet', ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $societeId,
        $moisPost,
        $anneePost,
        $file['name'],
        '/uploads/salaires_comptable/' . $destName,
        $compare['total_pdf'],
        $compare['total_expected'],
        $compare['ok'] ? 1 : 0,
        json_encode($compare, JSON_UNESCAPED_UNICODE),
        json_encode($parsed['data'] ?? [], JSON_UNESCAPED_UNICODE),
        current_user_id()
    ]);

    if ($idAgenceLog > 0 && $relPathLog) {
        rh_wf_log_action($pdo, $societeId, $idAgenceLog, $moisRef, RH_WF_TYPE_PROJET,
            $relPathLog, $file['name'], $contentSize, null,
            (int)current_user_id(), 'ok',
            null, $compare['ok'] ? 'Comparaison OK' : 'Écarts détectés');
    }

    $_SESSION['message_ok'] = $compare['ok'] ? 'Projet comptable validé ✅' : 'Comparaison terminée, vérifiez les écarts.';
    header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_bulletins_pdf'])) {
    $societeId = (int)($_POST['societe_id'] ?? 0);
    $moisPost = (int)($_POST['mois'] ?? date('n'));
    $anneePost = (int)($_POST['annee'] ?? date('Y'));
    if ($societeId <= 0) {
        $_SESSION['message_err'] = 'Sélectionnez une société avant l\'import.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }
    if (empty($_FILES['bulletins_pdf']) || $_FILES['bulletins_pdf']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['message_err'] = 'Fichier PDF bulletins manquant.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $file = $_FILES['bulletins_pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        $_SESSION['message_err'] = 'Le fichier doit être un PDF.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $destDir = __DIR__ . '/uploads/salaires_comptable';
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0777, true);
    }
    $destName = 'bulletins_' . $societeId . '_' . $anneePost . str_pad((string)$moisPost, 2, '0', STR_PAD_LEFT) . '_' . time() . '.pdf';
    $destPath = $destDir . '/' . $destName;
    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        $_SESSION['message_err'] = 'Impossible de sauvegarder le PDF.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $moisRef = sprintf('%04d-%02d-01', $anneePost, $moisPost);
    $idAgenceLog = $agenceScope > 0 ? $agenceScope : (int)($_POST['agence'] ?? 0);

    // Archivage workflow_log AVANT extraction : PDF visible dans
    // "Historique des échanges" même en cas d'échec parser.
    $relPathLog = null;
    $contentSize = null;
    if ($idAgenceLog > 0) {
        $iter = rh_wf_next_iteration($pdo, $idAgenceLog, $moisRef, RH_WF_TYPE_BULLETINS);
        $content = @file_get_contents($destPath);
        if ($content !== false) {
            $contentSize = strlen($content);
            $relPathLog = rh_wf_save_file($societeId, $idAgenceLog, $moisRef, RH_WF_TYPE_BULLETINS,
                $iter, $content, $file['name']);
        }
    }

    $meta = [];
    $parsed = rh_parse_bulletins_file($destPath, $meta);
    if (empty($parsed['ok'])) {
        if ($idAgenceLog > 0 && $relPathLog) {
            rh_wf_log_action($pdo, $societeId, $idAgenceLog, $moisRef, RH_WF_TYPE_BULLETINS,
                $relPathLog, $file['name'], $contentSize, null,
                (int)current_user_id(), 'error',
                'Extraction PDF impossible (' . ($parsed['error'] ?? 'parser KO') . ')',
                'PDF archivé — reparse manuel possible.');
        }
        $_SESSION['message_err'] = 'Extraction PDF impossible — le PDF a été archivé dans l\'historique.';
        header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
        exit;
    }

    $expected = rh_load_expected_map($pdo, $societeId, $moisRef);
    $employees = $parsed['data']['employees'] ?? [];
    $compare = rh_compare_bulletins_expected($expected, $employees);
    $totalNet = 0.0;
    foreach ($employees as $emp) {
        if (isset($emp['net']) && $emp['net'] !== null) {
            $totalNet += (float)$emp['net'];
        }
    }

    $stmt = $pdo->prepare("INSERT INTO rh_salaires_comparaisons (id_societe, mois, annee, type, file_name, file_path, total_pdf_net, compare_ok, compare_json, parsed_json, sepa_path, created_by) VALUES (?, ?, ?, 'bulletins', ?, ?, ?, ?, ?, ?, ?, ?)");

    $sepaPathRel = null;
    $sepaErr = null;

    $bank = [];
    if ($societeId > 0) {
        $stmtBank = $pdo->prepare("SELECT rib_emetteur_nom AS nom, rib_emetteur_iban AS iban, rib_emetteur_bic AS bic FROM societes WHERE id = ? LIMIT 1");
        $stmtBank->execute([$societeId]);
        $bank = $stmtBank->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $transfers = [];
    $missingRib = [];
    $moisLabel = mois_fr($moisPost);

    // Mapping users : clé = matricule_paie (cohérent avec les clés du parser).
    $stmtUsers = $pdo->prepare("SELECT id, prenom, nom, matricule_paie FROM users WHERE actif = 1 AND est_salarie = 1 AND id_societe = ? AND matricule_paie IS NOT NULL AND matricule_paie <> ''");
    $stmtUsers->execute([$societeId]);
    $userRows = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    $userMap = [];
    foreach ($userRows as $ur) {
        $name = trim(($ur['prenom'] ?? '') . ' ' . ($ur['nom'] ?? ''));
        $key = (string)($ur['matricule_paie'] ?? '');
        if ($key !== '') {
            $userMap[$key] = ['id' => (int)$ur['id'], 'name' => $name];
        }
    }

    foreach ($employees as $key => $emp) {
        $net = $emp['net'] ?? null;
        if ($net === null) {
            continue;
        }
        $u = $userMap[$key] ?? null;
        if (!$u) {
            $missingRib[] = $emp['name'] ?? $key;
            continue;
        }
        $rib = rh_bank_get($pdo, (int)$u['id']);
        if (empty($rib['iban'])) {
            $missingRib[] = $u['name'];
            continue;
        }
        $transfers[] = [
            'name' => $u['name'],
            'iban' => $rib['iban'],
            'bic' => $rib['bic'] ?? '',
            'amount' => (float)$net,
            'remittance' => 'Salaire ' . $moisLabel . ' ' . $anneePost,
        ];
    }

    if (!empty($missingRib)) {
        $sepaErr = 'RIB manquant pour: ' . implode(', ', $missingRib);
    }
    if (!$bank || empty($bank['iban'])) {
        $sepaErr = 'RIB émetteur manquant (societe).';
    }

    if (!$sepaErr && !empty($transfers)) {
        $sepaXml = rh_generate_sepa_xml([
            'name' => $bank['nom'] ?? '',
            'iban' => $bank['iban'] ?? '',
            'bic' => $bank['bic'] ?? '',
        ], $transfers, [
            'message_id' => 'SAL-' . date('Ymd-His'),
            'payment_id' => 'SAL-' . $anneePost . sprintf('%02d', $moisPost),
        ]);

        if (!empty($sepaXml)) {
            $exportDir = __DIR__ . '/exports/sepa';
            if (!is_dir($exportDir)) {
                @mkdir($exportDir, 0777, true);
            }
            $sepaName = 'sepa_salaires_' . $societeId . '_' . $anneePost . str_pad((string)$moisPost, 2, '0', STR_PAD_LEFT) . '_' . time() . '.xml';
            $sepaAbs = $exportDir . '/' . $sepaName;
            $sepaPathRel = '/exports/sepa/' . $sepaName;
            file_put_contents($sepaAbs, $sepaXml);
        }
    } elseif (!$sepaErr) {
        $sepaErr = 'Aucune ligne valide pour générer le SEPA.';
    }
    $stmt->execute([
        $societeId,
        $moisPost,
        $anneePost,
        $file['name'],
        '/uploads/salaires_comptable/' . $destName,
        $totalNet,
        $compare['ok'] ? 1 : 0,
        json_encode($compare, JSON_UNESCAPED_UNICODE),
        json_encode($parsed['data'] ?? [], JSON_UNESCAPED_UNICODE),
        $sepaPathRel,
        current_user_id()
    ]);

    // Workflow log final : le fichier a déjà été archivé en début de handler ;
    // ici on inscrit juste l'action avec le résultat (compare + SEPA).
    if ($idAgenceLog > 0 && $relPathLog) {
        rh_wf_log_action($pdo, $societeId, $idAgenceLog, $moisRef, RH_WF_TYPE_BULLETINS,
            $relPathLog, $file['name'], $contentSize, null,
            (int)current_user_id(), $sepaErr ? 'error' : 'ok',
            $sepaErr ?: null,
            'Total net : ' . number_format($totalNet, 2, ',', ' ') . ' €');
    }

    if ($sepaErr) {
        $_SESSION['message_err'] = 'Bulletins importés, mais ' . $sepaErr;
    } else {
        $_SESSION['message_ok'] = 'Bulletins importés ✅';
    }

    header("Location: rh_salaires.php" . ($currentQS ? '?' . $currentQS : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_row'])) {
    if (!$modeles_only && $monthClosed) {
        http_response_code(403);
        exit('Mois cloture');
    }
    verify_csrf();
    $id_user = (int)($_POST['id_user'] ?? 0);
    $id_user_legacy = rh_user_salary_id($pdo, $id_user);
    $chk = $pdo->prepare("SELECT id FROM salaires WHERE id_user=:uid AND mois_reference=:mr LIMIT 1");
    $chk->execute([':uid'=>$id_user_legacy, ':mr'=>$mois_ref]);
    $idSalaire = $chk->fetchColumn();

    // Champs réservés à l'admin : jamais modifiables par un gestionnaire agence
    $adminOnlyFields = ['salaire_brut_base', 'treizieme_mois', 'anciennete'];

    $vals = []; $setParts = [];
    $allCols = array_merge($COLS, $COLS_ACTIONS);
    foreach ($allCols as $col => $meta) {
        // Gestionnaire agence : ignorer les champs protégés
        if ($agenceScope > 0 && in_array($col, $adminOnlyFields, true)) continue;
        $type = $meta['type'];
        $v = $_POST[$col] ?? null;
        if ($type === 'money') {
            $v = ($v === '' || $v === null) ? null : (float)str_replace(',', '.', $v);
        } elseif ($type === 'int') {
            $v = ($v === '' || $v === null) ? null : (int)$v;
        } elseif ($type === 'text') {
            $v = ($v === '' || $v === null) ? null : trim($v);
        }
        $vals[":$col"] = $v;
        $setParts[] = "`$col` = :$col";
    }

    // Handle termine_user checkbox
    $termine_user = isset($_POST['termine_user']) && $_POST['termine_user'] === '1' ? 1 : 0;
    $vals[":termine_user"] = $termine_user;
    $setParts[] = "`termine_user` = :termine_user";

    if ($idSalaire) {
        $sql = "UPDATE salaires SET ".implode(", ", $setParts)." WHERE id = :id";
        $params = $vals;
        $params[':id']=$idSalaire;
    } else {
        $insCols = array_merge(['`id_user`','`mois_reference`','`termine_user`'], array_keys(array_map(fn($c)=>"`$c`", $allCols)));
        $insVals = array_merge([':id_user',':mois_reference',':termine_user'], array_keys(array_map(fn($c)=>":$c", $allCols)));
        $sql = "INSERT INTO salaires (".implode(", ", $insCols).") VALUES (".implode(", ", $insVals).")";
        $params = array_merge([':id_user'=>$id_user_legacy, ':mois_reference'=>$mois_ref, ':termine_user'=>$termine_user], $vals);
    }
    $pdo->prepare($sql)->execute($params);
    $_SESSION['message_ok'] = 'Salaire enregistré ✅';
    header("Location: rh_salaires.php".($currentQS ? '?'.$currentQS : ''));
    exit;
}

$selectCols = implode(", ", array_map(fn($c)=>"s.`$c` AS `$c`", array_keys(array_merge($COLS, $COLS_ACTIONS))));

if ($modeles_only) {
    $sql = "
        SELECT u.id AS id_user, " . rh_user_name_expr('u') . " AS nom_complet,
               s.id AS id_salaire, s.mois_reference, s.termine_user, s.mois_cloture, $selectCols
        FROM users u
        LEFT JOIN salaires s ON (s.id_user=u.id OR (s.id_user=u.id_legacy AND u.id_legacy IS NOT NULL)) AND s.mois_reference='0000-00-00' AND s.salaire_modele=1
        LEFT JOIN societes soc ON u.id_societe = soc.id
        WHERE u.actif=1 AND u.est_salarie=1 AND (soc.nom IS NULL OR soc.nom != 'Externe')";
    $p = [];
} else {
    $sql = "
        SELECT u.id AS id_user, " . rh_user_name_expr('u') . " AS nom_complet,
               s.id AS id_salaire, s.mois_reference, s.termine_user, s.mois_cloture, $selectCols
        FROM users u
        LEFT JOIN salaires s ON (s.id_user=u.id OR (s.id_user=u.id_legacy AND u.id_legacy IS NOT NULL)) AND s.mois_reference=:mr
        LEFT JOIN societes soc ON u.id_societe = soc.id
        WHERE u.actif=1 AND u.est_salarie=1 AND (soc.nom IS NULL OR soc.nom != 'Externe')";
    $p = [':mr' => $mois_ref];
}

if($societe_sel !== 'toutes') { $sql .= " AND u.id_societe = :soc"; $p[':soc'] = (int)$societe_sel; }
if($agence_sel !== 'toutes') { $sql .= " AND u.id_agence = :age"; $p[':age'] = (int)$agence_sel; }

// Collaborateur sans gestion_salaires OU accès forcé via ?scope=me : ne voit que son propre salaire
if (($roleId === 3 && $agenceScope === 0) || $scopeMe) {
    $sql .= " AND u.id = :self_uid";
    $p[':self_uid'] = current_user_id();
}

$sql .= " ORDER BY nom_complet";
$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcul des totaux
$totalUsers      = count($users);
$totalBrut       = 0.0;
$total13eme      = 0.0;
$totalAnciennete = 0.0;
$totalCommCA     = 0.0;
$totalCommNA     = 0.0;
$totalAutres     = 0.0;
$totalAll        = 0.0;

try {
    foreach ($users as $u) {
        $brut      = !empty($u['salaire_brut_base']) ? (float)$u['salaire_brut_base'] : 0;
        $mois_anci = !empty($u['anciennete']) ? (int)$u['anciennete'] : 0;
        $anci_val  = ($mois_anci > 0 && $brut > 0) ? ($brut * $mois_anci * 0.01 / 12) : 0;
        $commCA    = !empty($u['commission_ca']) ? (float)$u['commission_ca'] : 0;
        $commNA    = !empty($u['commission_ca_nouvelles_affaires']) ? (float)$u['commission_ca_nouvelles_affaires'] : 0;
        $autres    = (!empty($u['avantage_nature']) ? (float)$u['avantage_nature'] : 0)
                   + (!empty($u['heures_supp']) ? (float)$u['heures_supp'] : 0)
                   + (!empty($u['frais_professionnels']) ? (float)$u['frais_professionnels'] : 0)
                   + (!empty($u['frais_reception']) ? (float)$u['frais_reception'] : 0)
                   + (!empty($u['prime_admin']) ? (float)$u['prime_admin'] : 0)
                   + (!empty($u['prime_exceptionnelle']) ? (float)$u['prime_exceptionnelle'] : 0)
                   + (!empty($u['stationnement']) ? (float)$u['stationnement'] : 0)
                   + (!empty($u['frais_deplacement']) ? (float)$u['frais_deplacement'] : 0)
                   + (!empty($u['remboursement_achat']) ? (float)$u['remboursement_achat'] : 0)
                   + (!empty($u['total_ik']) ? (float)$u['total_ik'] : 0);

        if ($brut > 0) $totalBrut += $brut;
        if (!empty($u['treizieme_mois'])) $total13eme += (float)$u['treizieme_mois'];
        $totalAnciennete += $anci_val;
        $totalCommCA     += $commCA;
        $totalCommNA     += $commNA;
        $totalAutres     += $autres;
        $totalAll        += $brut + ((float)($u['treizieme_mois'] ?? 0)) + $anci_val + $commCA + $commNA + $autres;
    }
} catch (Exception $e) {
    error_log("Erreur calcul totaux: " . $e->getMessage());
}

$msg_ok = $_SESSION['message_ok'] ?? '';
$msg_err = $_SESSION['message_err'] ?? '';
unset($_SESSION['message_ok']);
unset($_SESSION['message_err']);

// Boutons rapides mois/année
$cur_m  = (int)$now->format('n');
$cur_y  = (int)$now->format('Y');
$prev_m = $cur_m === 1  ? 12 : $cur_m - 1;
$next_m = $cur_m === 12 ?  1 : $cur_m + 1;
$prev_m_y = $cur_m === 1  ? $cur_y - 1 : $cur_y;
$next_m_y = $cur_m === 12 ? $cur_y + 1 : $cur_y;

$current_page = 'salaires';

/* ═══════════════════════════════════════════════════════════════════════
   LAYOUT VARIABLES
   ═══════════════════════════════════════════════════════════════════════ */

$layout_title   = 'Salaires';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#2f587d">'.$totalUsers.'</div><div class="ph-kpi-lbl">Collab.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#3a7a6a">'.number_format($totalBrut, 0, ',', ' ').'</div><div class="ph-kpi-lbl">Brut base</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#7a6830">'.number_format($total13eme + $totalAnciennete, 0, ',', ' ').'</div><div class="ph-kpi-lbl">13e + Anc.</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#4878a6">'.number_format($totalCommCA + $totalCommNA, 0, ',', ' ').'</div><div class="ph-kpi-lbl">Commissions</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#8a5040">'.number_format($totalAutres, 0, ',', ' ').'</div><div class="ph-kpi-lbl">Autres</div></div>
    <div class="ph-kpi"><div class="ph-kpi-val" style="color:#2f587d;font-weight:700">'.number_format($totalAll, 0, ',', ' ').'</div><div class="ph-kpi-lbl">Total brut</div></div>
';

$layout_head_actions = '
    <a href="exporter_salaires_pdf.php?mois='.$mois_sel.'&annee='.$annee_sel.'&societe='.$societe_sel.'&agence='.($agenceScope > 0 ? $agenceScope : urlencode((string)$agence_sel)).'"
       target="_blank" class="ph-btn" title="Export PDF — filtré sur la société/agence sélectionnée">Export</a>
    <a href="exporter_salaires_conges_pdf.php?mois='.$mois_sel.'&annee='.$annee_sel.'"
       target="_blank" class="ph-btn primary" title="Salaires & Congés — vue globale toutes agences/sociétés">Sal&amp;Cong</a>
    <span class="ph-btn dispo">attente</span>
    <span class="ph-btn dispo">attente</span>
';

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ═══════════════════════════════════════════════════════
       rh_salaires — Styles page-spécifiques
    ═══════════════════════════════════════════════════════ */
    meta[name="csrf-token"] { display: none; }

    /* ── Scope pills dans page content ── */
    .ph-scope {
        display: flex; flex-direction: column; gap: 11px;
        justify-content: center; min-width: 0;
    }
    .ph-scope-row {
        display: flex; align-items: center; gap: 15px; flex-wrap: wrap;
    }
    .ph-scope-label {
        font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500;
        text-transform: uppercase; letter-spacing: 0.10em; color: var(--shadow-dark);
        width: 46px; flex-shrink: 0; text-align: right;
    }
    .ph-scope-btns {
        display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .ph-scope-pill {
        height: 24px; padding: 0 12px; border-radius: 999px;
        background: var(--bg-primary, #ffffff);
        box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
        font-family: 'Sora', sans-serif; font-size: 10px; font-weight: 500;
        color: #8a8680; text-decoration: none;
        display: inline-flex; align-items: center;
        transition: box-shadow 0.12s, color 0.12s;
        white-space: nowrap;
    }
    .ph-scope-pill:hover { color: #36577d; }
    .ph-scope-pill.active {
        box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff);
        color: #36577d; font-weight: 700;
    }

    /* ── Collapse ── */
    .collapse-btn {
        width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0;
        background: var(--bg-primary, #ffffff);
        box-shadow: 2px 2px 6px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
        border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
        color: #7a9060; transition: transform 0.25s ease, box-shadow 0.15s;
    }
    .collapse-btn svg { width: 13px; height: 13px; transition: transform 0.25s ease; }
    .collapse-btn.collapsed svg { transform: rotate(180deg); }
    .collapsible {
        overflow: hidden;
        transition: max-height 0.3s ease, opacity 0.3s ease;
        max-height: 2000px; opacity: 1;
    }
    .collapsible.collapsed { max-height: 0; opacity: 0; margin-bottom: 0 !important; }

    /* ── Action strip ── */
    .action-strip {
        display: flex; flex-direction: row; align-items: flex-start;
        gap: 20px; margin-bottom: 20px;
    }
    .action-strip-filters {
        display: flex; flex-direction: column; gap: 12px;
        flex: 0 0 auto;
    }
    .filter-row {
        display: flex; align-items: center; gap: 15px;
    }
    .filter-label {
        font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500;
        text-transform: uppercase; letter-spacing: 0.12em; color: #a8a49e;
        width: 46px; flex-shrink: 0; text-align: right;
    }
    .filter-btns {
        display: flex; align-items: center; gap: 12px;
    }
    .filter-pill {
        height: 28px; width: 68px;
        border-radius: 999px;
        background: var(--bg-primary, #ffffff);
        box-shadow: 3px 3px 7px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
        border: none; cursor: pointer; outline: none;
        font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 500;
        color: #6a6660; transition: box-shadow 0.12s, color 0.12s;
        white-space: nowrap; text-align: center;
    }
    .filter-pill:hover { color: #36577d; }
    .filter-pill.active {
        box-shadow: inset 3px 3px 6px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff);
        color: #36577d; font-weight: 700;
    }
    .filter-more, .filter-select {
        height: 28px;
        background: var(--bg-primary, #ffffff);
        box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff);
        border: none; border-radius: 8px;
        font-family: 'Sora', sans-serif; font-size: 11px; color: #6a6660;
        cursor: pointer; outline: none;
    }
    .filter-more  { width: 68px; padding: 0 6px; text-align: center; }
    .filter-select { min-width: 120px; padding: 0 6px; }

    .action-strip-btns {
        display: grid;
        grid-template-columns: repeat(3, auto);
        justify-content: end;
        align-content: start;
        gap: 12px 19px;
        flex: 1;
    }
    .v2-btn {
        padding: 0 16px; height: 32px; border-radius: 999px;
        cursor: pointer; border: none; outline: none;
        font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.04em;
        background: var(--bg-primary, #ffffff);
        box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff);
        font-weight: 600; color: #3a3830;
        text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
        transition: box-shadow 0.15s;
    }
    .v2-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff); }
    .v2-btn.primary { background: #36577d; color: var(--bg-primary, #ffffff); box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff); }
    .v2-btn.success { background: #4a6038; color: var(--bg-primary, #ffffff); }
    .v2-btn.danger  { background: #8a5040; color: var(--bg-primary, #ffffff); }
    .v2-btn.locked  { background: #7a9060; color: var(--bg-primary, #ffffff); }

    /* ── Alertes v2 ── */
    .v2-alert {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 12px 16px; border-radius: 12px; margin-bottom: 14px;
        font-size: 13px; line-height: 1.5;
    }
    .v2-alert-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 3px; }
    .v2-alert.success { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .v2-alert.success .v2-alert-dot { background: var(--success-icon); }
    .v2-alert.warning { background: var(--warning-bg); color: var(--warning-text); border: 1px solid var(--warning-border); }
    .v2-alert.warning .v2-alert-dot { background: var(--warning-icon); }
    .v2-alert.error   { background: var(--danger-bg);  color: var(--danger-text);  border: 1px solid var(--danger-border); }
    .v2-alert.error   .v2-alert-dot { background: var(--danger-icon); }
    .v2-alert.info    { background: var(--info-bg);    color: var(--info-text);    border: 1px solid var(--info-border); }
    .v2-alert.info    .v2-alert-dot { background: var(--info-icon); }

    /* ── Card neumorphique ── */
    .v2-card {
        background: var(--bg-primary, #ffffff);
        border-radius: 20px;
        box-shadow: 8px 8px 18px var(--shadow-dark, #d4d7de), -8px -8px 18px var(--shadow-light, #fff);
        margin-bottom: 20px;
        overflow: visible;
        width: 100%;
        height: auto;
    }
    .v2-card-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 20px;
        border-bottom: 1px solid rgba(196,192,186,0.4);
    }
    .v2-card-title {
        font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
        text-transform: uppercase; letter-spacing: 0.22em; color: #7a9060;
    }
    .v2-card-body { padding: 16px 20px; }

    /* ── Workflow ── */
    .workflow-grid    { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 12px; margin-top: 10px; }
    .workflow-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 12px; margin-top: 14px; }
    .workflow-step {
        background: var(--bg-primary, #ffffff);
        border-radius: 14px;
        box-shadow: 5px 5px 12px var(--shadow-dark, #d4d7de), -5px -5px 12px var(--shadow-light, #fff);
        padding: 14px;
    }
    .workflow-step h4 {
        font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500;
        text-transform: uppercase; letter-spacing: 0.2em; color: #a8a49e; margin-bottom: 10px;
    }
    .workflow-step input[type=file] { width: 100%; font-size: 11px; color: #6a6660; margin-bottom: 10px; display: block; }
    .workflow-step-btn {
        width: 100%; padding: 8px 12px; border-radius: 999px;
        border: none; cursor: pointer; outline: none;
        font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
        background: var(--bg-primary, #ffffff); color: #36577d;
        box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff);
        transition: box-shadow 0.15s;
    }
    .workflow-step-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff); }
    .workflow-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 8px; }
    .workflow-info div { font-size: 12px; color: #6a6660; }
    .workflow-info strong { color: #1a1816; }

    /* Comparaison */
    .compare-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 10px; }
    .compare-table th { padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; color: #4a6038; font-weight: 700; border-bottom: 1px solid rgba(196,192,186,0.5); font-family: 'DM Mono', monospace; }
    .compare-table td { padding: 8px 10px; border-bottom: 1px solid rgba(196,192,186,0.3); color: #3a3830; font-size: 12px; }
    .compare-detail { font-size: 12px; color: #6a6660; margin-top: 6px; }
    .v2-badge {
        display: inline-flex; align-items: center; padding: 2px 10px;
        border-radius: 999px; font-size: 10px; font-weight: 600; letter-spacing: 0.04em;
        font-family: 'DM Mono', monospace;
    }
    .v2-badge.ok  { background: #d4f0df; color: #1a6035; }
    .v2-badge.bad { background: #fce8e6; color: #a03020; }
    .v2-badge.warn { background: #fef5db; color: #7a4e0a; }

    /* ── Tableau salaires ── */
    .salary-table {
        border-collapse: collapse; font-size: 11px;
        table-layout: fixed; width: max-content; min-width: 100%;
    }
    .salary-table th {
        background: #f0f1f3;
        padding: 8px 4px; text-align: center;
        font-family: 'DM Mono', monospace; font-size: 8.5px; font-weight: 700;
        letter-spacing: 0.12em; text-transform: uppercase; color: #4a6038;
        border-bottom: 2px solid rgba(196,192,186,0.6);
        white-space: nowrap; position: sticky; top: 0; z-index: 10;
        overflow: hidden; text-overflow: ellipsis;
    }
    .salary-table th.col-name  { width: 130px; text-align: left; padding-left: 8px; }
    .salary-table th.col-money { width: 62px; }
    .salary-table th.col-int   { width: 46px; }
    .salary-table th.col-med   { width: 72px; }
    .salary-table th.col-long  { width: 84px; }

    .salary-table td {
        padding: 5px 3px;
        border-bottom: 1px solid rgba(196,192,186,0.4);
        vertical-align: middle; text-align: center;
    }
    .salary-table td:first-child {
        width: 130px; font-weight: 600; font-size: 12px; color: #2f587d;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        border-right: 2px solid rgba(196,192,186,0.5);
        text-align: left; padding-left: 8px;
        vertical-align: top; padding-top: 7px;
    }

    /* Inputs dans cellules */
    .salary-table td input[type=text] {
        width: 55px; padding: 3px 4px;
        background: var(--bg-primary, #ffffff);
        box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff);
        border: none; border-radius: 6px;
        color: #1a1816; font-family: 'Sora', sans-serif; font-size: 11px;
        text-align: right; box-sizing: border-box; outline: none;
    }
    .salary-table td input[type=text]:focus {
        box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff),
                    0 0 0 2px rgba(74,96,56,0.25);
    }
    .salary-table td input[type=text].input-readonly {
        cursor: not-allowed; color: var(--shadow-dark, #d4d7de);
        box-shadow: inset 1px 1px 3px var(--shadow-dark, #d4d7de), inset -1px -1px 3px var(--bg-secondary, #f7f8fa);
    }
    .salary-table td input[type=text].input-left { text-align: left; }
    .salary-table td input[name="salaire_brut_base"] { width: 62px; }

    /* Champ empilé (CA/NA) */
    .stacked-field {
        display: flex; align-items: center; gap: 4px; margin-bottom: 2px;
    }
    .stacked-field:last-child { margin-bottom: 0; }
    .stacked-label {
        font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 600;
        color: #a8a49e; flex-shrink: 0; white-space: nowrap;
    }
    .stacked-field input[type=text] { width: 50px; min-width: 0; flex: none; }

    /* Alternance + états lignes */
    tr.data-row   { border-bottom: none; }
    tr.action-row td { padding: 2px 3px 3px; border-bottom: 2px solid rgba(139,127,115,0.45); }
    tr.row-even td,
    tr.action-row.row-even td { background: rgba(54,87,125,0.13); }
    tr.row-odd td,
    tr.action-row.row-odd td  { background: rgba(122,144,96,0.14); }
    tr.state-1    { background: rgba(54,87,125,0.07) !important; }
    tr.state-2    { background: rgba(74,96,56,0.10) !important; }
    tr.data-row:hover,
    tr.data-row:hover + tr.action-row { background: rgba(54,87,125,0.1) !important; }

    /* ── Boutons séparés tableau ── */
    .tbl-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 20px; height: 18px; flex-shrink: 0;
        border-radius: 5px;
        font-family: 'Sora', sans-serif; font-size: 10px; font-weight: 600;
        border: none; cursor: pointer; text-decoration: none;
        box-shadow: 2px 2px 4px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
        transition: box-shadow 0.12s;
        position: relative;
    }
    .tbl-btn:active { box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff); }
    .tbl-btn[title]:hover::after {
        content: attr(title);
        position: absolute;
        bottom: calc(100% + 6px);
        left: 50%; transform: translateX(-50%);
        background: #2c2a27; color: #f0ece6;
        font-size: 10px; font-weight: 500; white-space: nowrap;
        padding: 3px 7px; border-radius: 4px;
        pointer-events: none; z-index: 999;
    }
    .tbl-btn[title]:hover::before {
        content: '';
        position: absolute;
        bottom: calc(100% + 2px);
        left: 50%; transform: translateX(-50%);
        border: 4px solid transparent;
        border-top-color: #2c2a27;
        pointer-events: none; z-index: 999;
    }
    .tbl-btn--neutral { background: var(--bg-primary, #ffffff); color: #8a8680; }
    .tbl-btn--ok      { background: #d4f0df; color: #1a6035; }
    .tbl-btn--blue    { background: #dce8f5; color: #2f587d; }
    .tbl-btn--create  { background: var(--bg-primary, #ffffff); color: #4a6038; font-size: 16px; font-weight: 700; }
    .tbl-btn--empty   { cursor: default; pointer-events: none; opacity: .4; }
    .tbl-btn--check   { background: #d4f0df; color: #1a6035; font-size: 13px; cursor: default; }

    /* Boutons action cellules */
    .lock-btn   { background: none; border: none; cursor: pointer; font-size: 15px; padding: 0; color: #8a8680; }
    .action-btn {
        padding: 3px 9px; border-radius: 999px;
        background: var(--bg-primary, #ffffff);
        box-shadow: 3px 3px 6px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
        color: #36577d; font-size: 10px; font-weight: 600; cursor: pointer;
        white-space: nowrap; transition: box-shadow 0.15s;
        text-decoration: none; display: inline-flex; align-items: center;
        border: none; font-family: 'Sora', sans-serif;
    }
    .action-btn:active { box-shadow: inset 2px 2px 5px var(--shadow-dark, #d4d7de), inset -2px -2px 5px var(--shadow-light, #fff); }
    .action-btn--create {
        background: #d4f0df; color: #1a6035;
        box-shadow: 3px 3px 6px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
        font-size: 14px; font-weight: 700; padding: 3px 10px;
    }
    .action-buttons { display: flex; align-items: center; justify-content: center; gap: 20px; width: 100%; }
    .row-total-btn {
        display: inline-block;
        padding: 1px 8px; border-radius: 999px;
        background: var(--bg-primary, #ffffff);
        box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -1px -1px 3px #f5f2ed;
        font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700;
        color: #36577d; letter-spacing: 0.04em;
        white-space: nowrap; line-height: 1.4;
    }

    /* ── Page head local (scope + badge) ── */
    .page-head-local {
        display: flex; align-items: flex-start; gap: 24px; margin-bottom: 20px;
        padding-bottom: 16px; border-bottom: 1px solid rgba(196,192,186,0.3);
    }
    .page-head-module-label {
        font-family: 'DM Mono', monospace; font-size: 9px;
        text-transform: uppercase; letter-spacing: 0.22em; color: #a8a49e; margin-bottom: 4px;
    }
    .page-head-row-local { display: flex; align-items: center; gap: 10px; }
    .page-head-title-local { font-family: 'Sora'; font-size: 20px; font-weight: 700; color: #1a1816; }
    .badge-rh {
        display: inline-flex; align-items: center; padding: 3px 11px;
        border-radius: 999px; background: #36577d; color: var(--bg-primary, #ffffff);
        font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase;
    }

    @media (max-width: 900px) {
        .action-strip { flex-direction: column; }
    }
</style>
EXTRACSS;

// Prepare JS variables
$_csrf_token_for_js = h(csrf_token());
$_month_locked_js = ($monthClosed && !$modeles_only) ? 'true' : 'false';

$layout_extra_js = '<meta name="csrf-token" content="' . $_csrf_token_for_js . '">'
. '<script>
const CSRF_TOKEN = document.querySelector(\'meta[name="csrf-token"]\')?.content || \'\';
const CSRF_HEADERS = CSRF_TOKEN ? {\'X-CSRF-Token\': CSRF_TOKEN} : {};
const MONTH_LOCKED = ' . $_month_locked_js . ';
let saveTimeout = {};

function toggleSection(cardId, chevronId) {
    const card    = document.getElementById(cardId);
    const chevron = document.getElementById(chevronId);
    if (!card) return;
    card.classList.toggle(\'collapsed\');
    if (chevron) chevron.classList.toggle(\'collapsed\');
}

function toggleModeles(checked) {
    const form = document.getElementById(\'filter-form\');
    let input = form.querySelector(\'input[name="modeles_only"]\');
    if (checked) {
        if (!input) {
            input = document.createElement(\'input\');
            input.type = \'hidden\';
            input.name = \'modeles_only\';
            input.value = \'1\';
            form.appendChild(input);
        }
    } else {
        if (input) input.remove();
    }
    form.submit();
}

function setFilter(mois, annee) {
    document.getElementById(\'filter-mois-hidden\').value  = mois;
    document.getElementById(\'filter-annee-hidden\').value = annee;
    document.getElementById(\'filter-form\').submit();
}

function resetAgenceFilter(form) {
    const agenceSelect = form.querySelector(\'select[name="agence"]\');
    if (agenceSelect) {
        agenceSelect.value = \'toutes\';
    }
    form.submit();
}

const _CSRF = document.querySelector(\'meta[name=csrf-token]\')?.content || \'\';

function autoSaveField(formId, idUser) {
    if (MONTH_LOCKED) { alert(\'Mois cloture\'); return; }
    const form = document.getElementById(formId);
    if (!form) return;

    clearTimeout(saveTimeout[formId]);

    const formData = new FormData(form);
    formData.append(\'save_row\', \'1\');
    formData.append(\'csrf_token\', _CSRF);

    saveTimeout[formId] = setTimeout(() => {
        fetch(window.location.pathname + window.location.search, {
            method: \'POST\',
            body: formData
        })
        .then(r => r.text())
        .then(html => {
            console.log(\'Enregistre\');
        })
        .catch(e => console.error(\'Erreur:\', e));
    }, 500);
}

function toggleValidate(formId) {
    if (MONTH_LOCKED) { alert(\'Mois cloture\'); return; }
    const form = document.getElementById(formId);
    if (!form) return;

    const inputTermine = form.querySelector(\'input[name="termine_user"]\');
    if (!inputTermine) return;

    const currentValue = inputTermine.value;
    inputTermine.value = currentValue === \'1\' ? \'0\' : \'1\';

    autoSaveField(formId);
}

function closeMonth(mois, annee) {
    if (!confirm(\'Etes-vous sur? Cela empechera toute modification pour ce mois.\')) return;

    const formData = new FormData();
    formData.append(\'close_month\', \'1\');
    formData.append(\'close_month_mois\', mois);
    formData.append(\'close_month_annee\', annee);
    formData.append(\'csrf_token\', _CSRF);

    fetch(window.location.pathname + window.location.search, {
        method: \'POST\',
        body: formData
    })
    .then(r => r.text().then(text => ({ok: r.ok, text})))
    .then(({ok, text}) => {
        if (ok) {
            alert(\'Mois cloture avec succes\');
            location.reload();
        } else {
            alert(\'Erreur: \' + text);
        }
    })
    .catch(e => alert(\'Erreur: \' + e.message));
}

function sendEmailToUser(idUser, mois, annee) {
    if (!confirm(\'Envoyer un email de rappel a cet utilisateur ?\')) return;
    const formData = new FormData();
    formData.append(\'send_email_user\', idUser);
    formData.append(\'mois_email\', mois);
    formData.append(\'annee_email\', annee);
    formData.append(\'csrf_token\', _CSRF);
    fetch(window.location.pathname + window.location.search, {
        method: \'POST\',
        body: formData
    })
    .then(r => r.text().then(text => ({ok: r.ok, text})))
    .then(({ok, text}) => {
        if (ok) { alert(\'Email envoye avec succes\'); }
        else { alert(\'Erreur: \' + text); }
    })
    .catch(e => alert(\'Erreur: \' + e.message));
}

function createOneSalary(idUser, mois, annee, nomComplet, moisNom, anneeLabel) {
    if (MONTH_LOCKED) { alert(\'Mois cloture\'); return; }
    if (!confirm(\'Creation de la paie de \' + nomComplet + \'\\ndu mois de \' + moisNom + \' \' + anneeLabel + \'\\n\\nConfirmer ?\')) return;
    fetch(\'api/create_missing_salaries.php\', {
        method: \'POST\',
        headers: Object.assign({\'Content-Type\': \'application/json\'}, CSRF_HEADERS),
        body: JSON.stringify({mois: mois, annee: annee, id_user: idUser, notify: false})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const moisRef = String(annee) + \'-\' + String(mois).padStart(2, \'0\') + \'-01\';
            window.location.href = \'rh_salaire_detail.php?id_user=\' + idUser + \'&mois_ref=\' + encodeURIComponent(moisRef);
        } else {
            alert(\'Erreur: \' + data.message);
        }
    })
    .catch(e => alert(\'Erreur: \' + e.message));
}

function createMissingSalariesOnly(mois, annee, agenceScope = 0) {
    if (MONTH_LOCKED) { alert(\'Mois cloture\'); return; }
    if (!confirm(\'Creer tous les salaires manquants sans envoyer de notifications ?\')) return;

    fetch(\'api/create_missing_salaries.php\', {
        method: \'POST\',
        headers: Object.assign({\'Content-Type\': \'application/json\'}, CSRF_HEADERS),
        body: JSON.stringify({mois: mois, annee: annee, agence_id: agenceScope, notify: false})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(\'Erreur: \' + data.message);
        }
    })
    .catch(e => alert(\'Erreur: \' + e.message));
}

function createMissingSalaries(mois, annee, agenceScope = 0) {
    if (MONTH_LOCKED) { alert(\'Mois cloture\'); return; }
    if (!confirm(\'Creer tous les salaires manquants et envoyer les avis de cloture a chaque utilisateur ?\')) return;

    fetch(\'api/create_missing_salaries.php\', {
        method: \'POST\',
        headers: Object.assign({\'Content-Type\': \'application/json\'}, CSRF_HEADERS),
        body: JSON.stringify({mois: mois, annee: annee, agence_id: agenceScope, notify: true})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(\'Erreur: \' + data.message);
        }
    })
    .catch(e => alert(\'Erreur: \' + e.message));
}
</script>';

/* ═══════════════════════════════════════════════════════════════════════
   CONTENT (ob_start)
   ═══════════════════════════════════════════════════════════════════════ */
ob_start();
?>

<!-- ── Page head local (scope + titre + badge) ── -->
<div class="page-head-local">
    <div style="flex-shrink:0">
        <div class="page-head-module-label">Module RH</div>
        <div class="page-head-row-local">
            <span class="page-head-title-local">Salaires</span>
            <?php if($monthClosed && !$modeles_only): ?>
                <span class="v2-badge warn">Cloture</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- Scope société / agence inline -->
    <?php if ($agenceScope === 0 && !$isSimpleCollab): ?>
    <div class="ph-scope">
        <div class="ph-scope-row">
            <span class="ph-scope-label">Ste</span>
            <div class="ph-scope-btns">
                <a href="?<?=http_build_query(array_merge($_GET,['societe'=>'toutes','agence'=>'toutes']))?>"
                   class="ph-scope-pill <?=$societe_sel==='toutes'?'active':''?>">Toutes</a>
                <?php foreach ($societes as $s): ?>
                <a href="?<?=http_build_query(array_merge($_GET,['societe'=>$s['id'],'agence'=>'toutes']))?>"
                   class="ph-scope-pill <?=((string)$societe_sel===(string)$s['id']?'active':'')?>">
                    <?=h($s['nom'])?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($societe_sel !== 'toutes' && !empty($agences_filtered)): ?>
        <div class="ph-scope-row">
            <span class="ph-scope-label">Agc</span>
            <div class="ph-scope-btns">
                <a href="?<?=http_build_query(array_merge($_GET,['agence'=>'toutes']))?>"
                   class="ph-scope-pill <?=$agence_sel==='toutes'?'active':''?>">Toutes</a>
                <?php foreach ($agences_filtered as $ag): ?>
                <a href="?<?=http_build_query(array_merge($_GET,['agence'=>$ag['id']]))?>"
                   class="ph-scope-pill <?=((string)$agence_sel===(string)$ag['id']?'active':'')?>">
                    <?=h($ag['nom_agence'])?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div style="margin-left:auto">
        <?php require_once __DIR__ . '/inc/role_switcher.php'; ?>
    </div>
</div>

<!-- ── Alertes système ── -->
<?php if($monthClosed && !$modeles_only): ?>
    <div class="v2-alert warning">
        <span class="v2-alert-dot"></span>
        Mois cloture -- toute modification est bloquee
    </div>
<?php endif; ?>
<?php if($msg_ok): ?>
    <div class="v2-alert success">
        <span class="v2-alert-dot"></span>
        <?=h($msg_ok)?>
    </div>
<?php endif; ?>
<?php if($msg_err): ?>
    <div class="v2-alert error">
        <span class="v2-alert-dot"></span>
        <?=h($msg_err)?>
    </div>
<?php endif; ?>

<!-- ── Barre d'actions + filtres ── -->
<div class="section-header">
    <div class="section-title">
        <span class="line-l"></span>
        <span class="sec-txt">Actions</span>
        <span class="line-r"></span>
    </div>
</div>
<div class="action-strip">

    <!-- Filtres période / périmètre -->
    <form method="GET" id="filter-form" class="action-strip-filters">
        <?php if ($agenceScope > 0): ?>
        <input type="hidden" name="agence" value="<?=(int)$agenceScope?>">
        <?php endif; ?>
        <!-- Ligne 1 : Mois rapides + dropdown autres mois + toggle Modèles -->
        <div class="filter-row">
            <span class="filter-label">Mois</span>
            <div class="filter-btns">
                <?php
                $quickMonths = [
                    [$prev_m, $prev_m_y, mois_fr($prev_m)],
                    [$cur_m,  $cur_y,    mois_fr($cur_m)],
                    [$next_m, $next_m_y, mois_fr($next_m)],
                ];
                foreach ($quickMonths as [$qm, $qy, $ql]):
                    $active = ($mois_sel == $qm && $annee_sel == $qy);
                ?>
                <button type="button"
                        class="filter-pill <?=$active?'active':''?>"
                        onclick="setFilter(<?=$qm?>, <?=$qy?>)">
                    <?=$ql?>
                </button>
                <?php endforeach; ?>
                <select class="filter-more" onchange="setFilter(this.value, document.getElementById('filter-annee-hidden').value)" title="Autre mois">
                    <option value="">...</option>
                    <?php
                    $quickMVals = array_column($quickMonths, 0);
                    for ($m = 1; $m <= 12; $m++):
                        if (in_array($m, $quickMVals, true)) continue;
                    ?>
                    <option value="<?=$m?>" <?=($mois_sel==$m && !in_array($mois_sel,(array)array_column($quickMonths,0),true)?'selected':'')?>>
                        <?=mois_fr($m)?>
                    </option>
                    <?php endfor; ?>
                </select>
                <?php if ($roleId === 1): ?>
                <span style="width:1px;background:rgba(196,192,186,0.5);margin:0 8px;align-self:stretch;flex-shrink:0"></span>
                <span class="filter-label" style="width:auto">Modeles</span>
                <label class="toggle toggle-sm" title="Afficher les modeles de salaires">
                    <input type="checkbox"
                           id="modeles-toggle-cb"
                           <?=$modeles_only ? 'checked' : ''?>
                           onchange="toggleModeles(this.checked)">
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                </label>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ligne 2 : Années rapides + dropdown autres années -->
        <div class="filter-row">
            <span class="filter-label">Annee</span>
            <div class="filter-btns">
                <?php foreach ([$cur_y - 2, $cur_y - 1, $cur_y] as $qy):
                    $active = ($annee_sel == $qy);
                ?>
                <button type="button"
                        class="filter-pill <?=$active?'active':''?>"
                        onclick="setFilter(document.getElementById('filter-mois-hidden').value, <?=$qy?>)">
                    <?=$qy?>
                </button>
                <?php endforeach; ?>
                <select class="filter-more" onchange="setFilter(document.getElementById('filter-mois-hidden').value, this.value)" title="Autre annee">
                    <option value="">...</option>
                    <?php for ($a = $cur_y + 1; $a >= $cur_y - 5; $a--):
                        if (in_array($a, [$cur_y, $cur_y - 1, $cur_y - 2])) continue;
                    ?>
                    <option value="<?=$a?>" <?=($annee_sel==$a?'selected':'')?>><?=$a?></option>
                    <?php endfor; ?>
                </select>
                <?php if ($roleId === 1): ?>
                <span style="width:1px;background:rgba(196,192,186,0.5);margin:0 8px;align-self:stretch;flex-shrink:0"></span>
                <span class="filter-label" style="width:auto;opacity:.35">En attente</span>
                <label class="toggle toggle-sm" style="opacity:.35;pointer-events:none">
                    <input type="checkbox" disabled>
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                </label>
                <?php endif; ?>
            </div>
        </div>

        <!-- société/agence gérés par les boutons scope-bar au-dessus -->
        <?php if ($agenceScope === 0): ?>
        <input type="hidden" name="societe" value="<?=h($societe_sel)?>">
        <input type="hidden" name="agence"  value="<?=h($agence_sel)?>">
        <?php endif; ?>

        <!-- Champs hidden pour mois/année courants (utilisés par setFilter) -->
        <input type="hidden" id="filter-mois-hidden"  name="mois"  value="<?=$mois_sel?>">
        <input type="hidden" id="filter-annee-hidden" name="annee" value="<?=$annee_sel?>">
    </form>

    <!-- Boutons : 3 par ligne, alignés à gauche -->
    <div class="action-strip-btns">
        <a href="exporter_salaires_pdf.php?mois=<?=$mois_sel?>&annee=<?=$annee_sel?>&societe=<?=$societe_sel?>&agence=<?=$agenceScope > 0 ? $agenceScope : urlencode((string)$agence_sel)?>"
           target="_blank" class="v2-btn" title="Export PDF — filtré sur la société/agence sélectionnée">Export PDF</a>

        <?php if ($roleId === 1 || $agenceScope > 0): ?>
            <a href="exporter_salaires_conges_pdf.php?mois=<?=$mois_sel?>&annee=<?=$annee_sel?>"
               target="_blank" class="v2-btn success" title="Salaires &amp; Congés — vue globale toutes agences/sociétés">Salaires &amp; Congés</a>
        <?php endif; ?>

        <button type="button" class="v2-btn" disabled style="opacity:.35;cursor:not-allowed">en attente</button>

        <?php if ($roleId === 1): ?>
            <button onclick="createMissingSalariesOnly(<?=$mois_sel?>, <?=$annee_sel?>, 0)"
                    class="v2-btn"
                    title="Creer les salaires manquants sans envoyer de notifications">
                Creer sans notifier
            </button>
            <button onclick="createMissingSalaries(<?=$mois_sel?>, <?=$annee_sel?>, 0)"
                    class="v2-btn"
                    title="Creer les salaires manquants et envoyer les avis de cloture">
                Creer &amp; Notifier
            </button>
            <button onclick="closeMonth(<?=$mois_sel?>, <?=$annee_sel?>)"
                    class="v2-btn <?=$monthClosed ? 'locked' : 'danger'?>">
                <?=$monthClosed ? 'Mois cloture' : 'Cloturer le mois'?>
            </button>
        <?php endif; ?>
    </div>

</div>

<?php
// Workflow comptable : visible pour admin (roleId=1) ET pour gestion_salaires=1 (Géraldine, Alexandra).
// Les autres users (collaborateurs simples) ne voient rien du workflow.
$canSeeWorkflow = ($roleId === 1) || ($agenceScope > 0);
?>
<!-- ── Workflow comptable ── -->
<?php if ($canSeeWorkflow): ?>
<div class="section-header" style="cursor:pointer" onclick="toggleSection('workflow-card','workflow-chevron')">
    <div class="section-title">
        <span class="line-l"></span>
        <span class="sec-txt">Workflow comptable</span>
        <button type="button" class="collapse-btn collapsed" id="workflow-chevron" aria-label="Reduire/Agrandir">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <span class="line-r"></span>
    </div>
</div>
<div class="v2-card collapsible collapsed" id="workflow-card" style="margin-bottom:20px">
    <div class="v2-card-body">
        <?php
        // Le workflow est par AGENCE : on exige qu'une agence soit sélectionnée
        // (chaque agence a son comptable et ses users distincts).
        $agenceWf = $agenceScope > 0 ? (int)$agenceScope : (ctype_digit((string)$agence_sel) ? (int)$agence_sel : 0);
        ?>
        <?php if ($societe_sel === 'toutes'): ?>
            <div class="v2-alert info">
                <span class="v2-alert-dot"></span>
                Sélectionnez une société puis une agence pour activer le workflow comptable.
            </div>
        <?php elseif ($agenceWf <= 0): ?>
            <div class="v2-alert info">
                <span class="v2-alert-dot"></span>
                Sélectionnez une <strong>agence</strong> pour activer le workflow comptable (chaque agence a son propre comptable et ses propres salariés).
            </div>
        <?php else: ?>
            <div class="workflow-info" style="margin-bottom:16px">
                <div>Comptable : <strong><?=h($societeInfo['comptable_nom'] ?? 'Non renseigne')?></strong></div>
                <div>Email : <strong><?=h($societeInfo['comptable_email'] ?? 'Non renseigne')?></strong></div>
                <div>Tel : <strong><?=h($societeInfo['comptable_telephone'] ?? 'Non renseigne')?></strong></div>
                <div>Cabinet : <strong><?=h($societeInfo['comptable_societe'] ?? 'Non renseigne')?></strong></div>
                <div>Reception bulletins : <strong>salaire@maboximmo.fr</strong></div>
            </div>

            <?php
            // Preview du mail au comptable : subject/body construits identiquement
            // au bloc d'envoi (lignes ~582-583) — toute modif là-bas doit être
            // répercutée ici pour la cohérence.
            $previewMoisLabel = mois_fr((int)$mois_sel);
            $previewSocName   = (string)($societeInfo['nom'] ?? 'Société');
            // Nom de l'agence active pour le sujet + le filename prévisionnel
            $previewAgenceNom = '';
            if ($agenceWf > 0) {
                foreach ($agences as $a) {
                    if ((int)$a['id'] === $agenceWf) { $previewAgenceNom = (string)$a['nom_agence']; break; }
                }
            }
            $previewSubjectLabel = $previewAgenceNom !== '' ? $previewAgenceNom : $previewSocName;
            $previewComptable = trim((string)($societeInfo['comptable_nom'] ?? ''));
            $previewBonjour   = $previewComptable !== '' ? 'Bonjour ' . trim((string)preg_split('/\s+/', $previewComptable)[0]) : 'Bonjour';
            $previewSubject   = "Salaires & Congés — {$previewSubjectLabel} — {$previewMoisLabel} {$annee_sel}";
            $previewBody      = "{$previewBonjour},\n\nVeuillez trouver en pièce jointe le registre des salaires et congés pour {$previewSubjectLabel} ({$previewMoisLabel} {$annee_sel}).\n\nCordialement,\nRégie EMERY";
            $previewFilename  = $previewAgenceNom !== ''
                ? rh_wf_pdf_filename($previewAgenceNom, (int)$annee_sel, (int)$mois_sel)
                : 'salaires_conges_*.pdf';
            $previewTo        = (string)($societeInfo['comptable_email'] ?? '');
            $previewFrom      = 'salaire@maboximmo.fr';
            // Mode test : pas d'envoi réel sur dev/localhost
            $previewIsDev = str_contains((string)($_SERVER['HTTP_HOST'] ?? ''), 'dev.maboximmo')
                         || str_contains((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost')
                         || str_contains((string)($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1');
            ?>
            <div class="workflow-actions">
                <form method="post" action="rh_salaires.php?<?=h($currentQS)?>" class="workflow-step" id="comptable-form">
                    <h4>1. Envoyer au comptable</h4>
                    <input type="hidden" name="societe_id" value="<?=h($societe_sel)?>">
                    <input type="hidden" name="agence" value="<?=h((string)$agenceWf)?>">
                    <input type="hidden" name="mois" value="<?=h($mois_sel)?>">
                    <input type="hidden" name="annee" value="<?=h($annee_sel)?>">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="send_to_comptable" value="1">
                    <button type="button" class="workflow-step-btn" onclick="ouvrirPreviewMail()">👁️ Prévisualiser puis envoyer</button>
                </form>

                <!-- Modal preview du mail comptable -->
                <div id="preview-mail-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)fermerPreviewMail()">
                    <div style="background:#fff;border-radius:14px;max-width:680px;width:100%;max-height:90vh;overflow-y:auto;padding:0;box-shadow:0 20px 60px rgba(0,0,0,.4);">
                        <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
                            <h3 style="margin:0;font-size:17px;color:#0f172a;">📨 Prévisualisation du mail au comptable</h3>
                            <button type="button" onclick="fermerPreviewMail()" style="background:transparent;border:none;font-size:22px;cursor:pointer;color:#64748b;">×</button>
                        </div>
                        <div style="padding:20px 24px;">
                            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:14px;font-size:13px;line-height:1.6;">
                                <div><strong style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">DE :</strong> <?=h($previewFrom)?></div>
                                <div style="margin-top:6px;"><strong style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">À :</strong>
                                    <?php if ($previewTo === ''): ?>
                                        <span style="color:#dc2626;">⚠️ Email comptable manquant — renseigne-le sur la fiche société avant l'envoi.</span>
                                    <?php else: ?>
                                        <strong style="color:#0f172a;"><?=h($previewTo)?></strong>
                                        <?php if (!empty($societeInfo['comptable_nom'])): ?>
                                            <span style="color:#64748b;">(<?=h($societeInfo['comptable_nom'])?><?=!empty($societeInfo['comptable_societe']) ? ' · ' . h($societeInfo['comptable_societe']) : ''?>)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:6px;"><strong style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">CC :</strong> <strong style="color:#0f172a;">emmanuel.emery@regie-emery.com</strong> <span style="color:#64748b;">(Direction — copie systématique)</span></div>
                                <div style="margin-top:6px;"><strong style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">SUJET :</strong> <?=h($previewSubject)?></div>
                                <div style="margin-top:6px;"><strong style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">PIÈCE JOINTE :</strong> <code style="background:#fff;padding:2px 6px;border-radius:4px;font-size:11px;"><?=h($previewFilename)?></code></div>
                            </div>
                            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;background:#fff;font-size:13px;color:#0f172a;line-height:1.7;white-space:pre-wrap;font-family:'Manrope',sans-serif;"><?=h($previewBody)?></div>
                            <?php if ($previewIsDev): ?>
                                <div style="margin-top:14px;padding:12px 16px;background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;font-size:12px;color:#854d0e;line-height:1.5;">
                                    🧪 <strong>Mode test (dev/localhost)</strong> — le mail NE SERA PAS envoyé au comptable.
                                    Le PDF sera quand même généré, conservé dans la timeline et téléchargeable pour vérification.
                                </div>
                            <?php else: ?>
                                <div style="margin-top:14px;padding:10px 14px;background:#fef9c3;border-radius:8px;font-size:11px;color:#854d0e;line-height:1.5;">
                                    💡 Le PDF (registre des salaires et congés du mois sélectionné) sera généré et joint à l'envoi.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="padding:14px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;background:#f8fafc;border-radius:0 0 14px 14px;">
                            <button type="button" onclick="fermerPreviewMail()" style="padding:9px 18px;border-radius:8px;background:#fff;color:#475569;border:1px solid #cbd5e1;font-size:13px;font-weight:600;cursor:pointer;">Annuler</button>
                            <?php if ($previewTo !== '' || $previewIsDev): ?>
                                <button type="button" onclick="confirmerEnvoiMail()" style="padding:9px 18px;border-radius:8px;background:<?= $previewIsDev ? '#eab308' : '#16a34a' ?>;color:#fff;border:none;font-size:13px;font-weight:700;cursor:pointer;">
                                    <?= $previewIsDev ? '🧪 Tester (sans envoyer)' : '📤 Envoyer définitivement' ?>
                                </button>
                            <?php else: ?>
                                <a href="/societe.php" style="padding:9px 18px;border-radius:8px;background:#0ea5e9;color:#fff;border:none;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;">⚙️ Renseigner l'email comptable</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <script>
                function ouvrirPreviewMail() {
                    document.getElementById('preview-mail-modal').style.display = 'flex';
                }
                function fermerPreviewMail() {
                    document.getElementById('preview-mail-modal').style.display = 'none';
                }
                function confirmerEnvoiMail() {
                    document.getElementById('comptable-form').submit();
                }
                </script>
                <form method="post" action="rh_salaires.php?<?=h($currentQS)?>" enctype="multipart/form-data" class="workflow-step">
                    <h4>2. Importer le projet</h4>
                    <input type="hidden" name="societe_id" value="<?=h($societe_sel)?>">
                    <input type="hidden" name="agence" value="<?=h((string)$agenceWf)?>">
                    <input type="hidden" name="mois" value="<?=h($mois_sel)?>">
                    <input type="hidden" name="annee" value="<?=h($annee_sel)?>">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="file" name="projet_pdf" accept="application/pdf" required>
                    <button type="submit" name="upload_projet_pdf" value="1" class="workflow-step-btn">Importer</button>
                </form>
                <form method="post" action="rh_salaires.php?<?=h($currentQS)?>" enctype="multipart/form-data" class="workflow-step">
                    <h4>3. Importer les bulletins</h4>
                    <input type="hidden" name="societe_id" value="<?=h($societe_sel)?>">
                    <input type="hidden" name="agence" value="<?=h((string)$agenceWf)?>">
                    <input type="hidden" name="mois" value="<?=h($mois_sel)?>">
                    <input type="hidden" name="annee" value="<?=h($annee_sel)?>">
                    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
                    <input type="file" name="bulletins_pdf" accept="application/pdf" required>
                    <button type="submit" name="upload_bulletins_pdf" value="1" class="workflow-step-btn">Importer</button>
                </form>
            </div>

            <?php if ($projetData): ?>
                <div class="workflow-step" style="margin-top:12px;display:flex;align-items:center;gap:14px;">
                    <h4 style="margin:0;">Comparaison projet comptable</h4>
                    <span style="font-size:12px;color:#64748b;">
                        Attendu <strong style="color:#0f172a;"><?=number_format((float)($projetData['total_expected'] ?? 0), 2, ',', ' ')?> €</strong>
                        &nbsp;·&nbsp;
                        PDF <strong style="color:#0f172a;"><?=number_format((float)($projetData['total_pdf'] ?? 0), 2, ',', ' ')?> €</strong>
                    </span>
                    <?php if (!empty($projetData['ok'])): ?>
                        <span class="v2-badge ok">OK</span>
                    <?php else: ?>
                        <span class="v2-badge bad">Différences</span>
                    <?php endif; ?>
                    <button type="button"
                            onclick="ouvrirRapportComparaison()"
                            style="padding:8px 14px;border-radius:8px;background:#0ea5e9;color:#fff;border:none;font-size:12px;font-weight:700;cursor:pointer;margin-left:auto;">
                        📊 Voir le rapport ligne par ligne
                    </button>
                </div>

                <!-- Modal rapport de comparaison -->
                <div id="rapport-comparaison-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)fermerRapportComparaison()">
                    <div style="background:#fff;border-radius:14px;max-width:920px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.4);">
                        <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
                            <h3 style="margin:0;font-size:17px;color:#0f172a;">📊 Rapport de comparaison — projet comptable</h3>
                            <button type="button" onclick="fermerRapportComparaison()" style="background:transparent;border:none;font-size:22px;cursor:pointer;color:#64748b;">×</button>
                        </div>
                        <div style="padding:20px 24px;">
                            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 24px;font-size:13px;">
                                <div><span style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Total attendu</span><br><strong style="color:#0f172a;font-size:15px;"><?=number_format((float)($projetData['total_expected'] ?? 0), 2, ',', ' ')?> €</strong></div>
                                <div><span style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Total PDF projet</span><br><strong style="color:#0f172a;font-size:15px;"><?=number_format((float)($projetData['total_pdf'] ?? 0), 2, ',', ' ')?> €</strong></div>
                                <div><span style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Écart total</span><br><strong style="<?=!empty($projetData['ok'])?'color:#16a34a':'color:#dc2626'?>;font-size:15px;"><?=number_format((float)($projetData['total_pdf'] ?? 0) - (float)($projetData['total_expected'] ?? 0), 2, ',', ' ')?> €</strong></div>
                                <div><span style="color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Statut</span><br><?php if (!empty($projetData['ok'])): ?><span class="v2-badge ok">✓ Cohérent</span><?php else: ?><span class="v2-badge bad">⚠ Différences</span><?php endif; ?></div>
                            </div>

                            <?php if (!empty($projetData['missing'])): ?>
                                <div style="background:#fef2f2;border-left:4px solid #dc2626;padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:12px;color:#991b1b;">
                                    <strong>Salariés manquants dans le PDF du comptable :</strong> <?=h(implode(', ', $projetData['missing']))?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($projetData['extra'])): ?>
                                <div style="background:#fef9c3;border-left:4px solid #eab308;padding:10px 14px;border-radius:8px;margin-bottom:10px;font-size:12px;color:#854d0e;">
                                    <strong>Salariés en trop dans le PDF du comptable :</strong> <?=h(implode(', ', $projetData['extra']))?>
                                </div>
                            <?php endif; ?>

                            <table class="compare-table" style="width:100%;border-collapse:collapse;font-size:12px;">
                                <thead>
                                    <tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">
                                        <th style="padding:8px 10px;text-align:left;color:#64748b;font-weight:600;">Collaborateur</th>
                                        <th style="padding:8px 10px;text-align:right;color:#64748b;font-weight:600;">Brut attendu</th>
                                        <th style="padding:8px 10px;text-align:right;color:#64748b;font-weight:600;">Brut PDF</th>
                                        <th style="padding:8px 10px;text-align:right;color:#64748b;font-weight:600;">Écart</th>
                                        <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:600;">Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($projetData['rows'] ?? []) as $row):
                                        $diff = $row['brut_diff'] ?? null;
                                        $isOk = ($row['status'] ?? '') === 'ok';
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;<?=!$isOk?'background:#fffbeb;':''?>">
                                            <td style="padding:8px 10px;"><strong><?=h($row['name'] ?? '')?></strong></td>
                                            <td style="padding:8px 10px;text-align:right;font-family:monospace;"><?=number_format((float)($row['expected_brut'] ?? 0), 2, ',', ' ')?> €</td>
                                            <td style="padding:8px 10px;text-align:right;font-family:monospace;"><?=($row['pdf_brut'] === null ? '<span style="color:#cbd5e1;">—</span>' : number_format((float)$row['pdf_brut'], 2, ',', ' ') . ' €')?></td>
                                            <td style="padding:8px 10px;text-align:right;font-family:monospace;<?=($diff !== null && abs((float)$diff) > 0.01 ? 'color:#dc2626;font-weight:700;' : 'color:#94a3b8;')?>">
                                                <?=($diff === null ? '—' : (((float)$diff > 0 ? '+' : '') . number_format((float)$diff, 2, ',', ' ')))?>
                                            </td>
                                            <td style="padding:8px 10px;text-align:center;">
                                                <?php if ($isOk): ?>
                                                    <span class="v2-badge ok">✓ OK</span>
                                                <?php else: ?>
                                                    <span class="v2-badge bad">⚠ <?=h($row['status'] ?? '')?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p style="margin-top:14px;font-size:11px;color:#94a3b8;">
                                💡 La comparaison est calculée à l'import du PDF projet. Si tu importes une nouvelle version, ce rapport est mis à jour automatiquement (la dernière comparaison s'affiche).
                            </p>
                        </div>
                        <div style="padding:14px 24px;border-top:1px solid #e5e7eb;background:#f8fafc;border-radius:0 0 14px 14px;display:flex;justify-content:flex-end;">
                            <button type="button" onclick="fermerRapportComparaison()" style="padding:9px 18px;border-radius:8px;background:#0ea5e9;color:#fff;border:none;font-size:13px;font-weight:700;cursor:pointer;">Fermer</button>
                        </div>
                    </div>
                </div>
                <script>
                function ouvrirRapportComparaison() { document.getElementById('rapport-comparaison-modal').style.display = 'flex'; }
                function fermerRapportComparaison() { document.getElementById('rapport-comparaison-modal').style.display = 'none'; }
                </script>
            <?php endif; ?>

            <?php if ($bulletinsRow): ?>
                <div class="workflow-step" style="margin-top:12px">
                    <h4>Bulletins definitifs</h4>
                    <div class="compare-detail">
                        Statut :
                        <?php if ($bulletinsRow['compare_ok'] ?? 0): ?>
                            <span class="v2-badge ok">OK</span>
                        <?php else: ?>
                            <span class="v2-badge bad">Differences</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($bulletinsRow['sepa_path'])): ?>
                        <div style="margin-top:8px">
                            <a href="<?=h($bulletinsRow['sepa_path'])?>" target="_blank" class="v2-btn">Fichier SEPA</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- ─── HISTORIQUE DES ÉCHANGES (workflow_log) ─────────────── -->
            <?php
            $moisRefWf = sprintf('%04d-%02d-01', (int)$annee_sel, (int)$mois_sel);
            $wfHistory = rh_wf_history($pdo, $agenceWf, $moisRefWf);
            ?>
            <div style="margin-top:24px;padding-top:16px;border-top:1px dashed #e5e7eb;">
                <h4 style="margin:0 0 12px;font-size:13px;color:#475569;">📜 Historique des échanges — <?= h(mois_fr((int)$mois_sel)) ?> <?= h($annee_sel) ?></h4>
                <?php if (empty($wfHistory)): ?>
                    <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:14px;font-size:12px;color:#64748b;text-align:center;">
                        Aucun échange enregistré pour ce mois. La timeline se construit au fil des envois et imports.
                    </div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <?php foreach ($wfHistory as $wfRow):
                            $typeLabel = RH_WF_TYPES_LABELS[$wfRow['type_action']] ?? $wfRow['type_action'];
                            $iconBg = match($wfRow['type_action']) {
                                'envoi_comptable'   => '#dbeafe',
                                'import_projet'     => '#fef9c3',
                                'import_bulletins'  => '#dcfce7',
                                default             => '#f1f5f9',
                            };
                            $statusClass = $wfRow['status'] === 'ok' ? 'color:#16a34a;' : ($wfRow['status'] === 'error' ? 'color:#dc2626;' : 'color:#64748b;');
                        ?>
                            <div style="display:grid;grid-template-columns:auto 1fr auto auto auto;gap:12px;align-items:center;padding:10px 14px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;">
                                <span style="background:<?= $iconBg ?>;padding:4px 10px;border-radius:99px;font-weight:700;font-size:11px;white-space:nowrap;">
                                    <?= h($typeLabel) ?> #<?= (int)$wfRow['iteration'] ?>
                                </span>
                                <span style="color:#64748b;">
                                    <?= h(date('d/m/Y H:i', strtotime((string)$wfRow['date_action']))) ?>
                                    <?php if (!empty($wfRow['user_nom'])): ?>
                                        · <strong style="color:#0f172a;"><?= h($wfRow['user_nom']) ?></strong>
                                    <?php endif; ?>
                                    <?php if ($wfRow['type_action'] === 'envoi_comptable' && !empty($wfRow['destinataire'])): ?>
                                        · → <code style="font-size:11px;background:#f1f5f9;padding:1px 6px;border-radius:4px;"><?= h($wfRow['destinataire']) ?></code>
                                    <?php endif; ?>
                                    <?php if (!empty($wfRow['commentaire'])): ?>
                                        <span style="color:#94a3b8;font-size:11px;">— <?= h($wfRow['commentaire']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span style="<?= $statusClass ?>font-weight:700;font-size:11px;">
                                    <?= $wfRow['status'] === 'ok' ? '✓' : ($wfRow['status'] === 'error' ? '✗' : '⏳') ?>
                                </span>
                                <span style="color:#94a3b8;font-size:11px;">
                                    <?= h(rh_wf_human_size((int)$wfRow['fichier_taille'])) ?>
                                </span>
                                <?php if (!empty($wfRow['fichier_path'])):
                                    $wfFileLabel = h($wfRow['fichier_nom_original'] ?: ('document_' . $wfRow['id'] . '.pdf'));
                                ?>
                                    <span style="display:inline-flex;gap:6px;">
                                        <button type="button"
                                            onclick="ouvrirWfPreview(<?= (int)$wfRow['id'] ?>, '<?= addslashes($wfFileLabel) ?>')"
                                            style="padding:4px 10px;border-radius:6px;background:#7c3aed;color:#fff;border:none;cursor:pointer;font-size:11px;font-weight:600;">
                                            👁 Voir
                                        </button>
                                        <a href="rh_salaire_workflow_download.php?id=<?= (int)$wfRow['id'] ?>"
                                           style="padding:4px 10px;border-radius:6px;background:#0ea5e9;color:#fff;text-decoration:none;font-size:11px;font-weight:600;">
                                            📎 Télécharger
                                        </a>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;font-size:11px;">—</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($wfRow['error_msg'])): ?>
                                <div style="font-size:11px;color:#991b1b;background:#fef2f2;padding:6px 12px;border-radius:6px;margin-left:24px;">
                                    ⚠ <?= h($wfRow['error_msg']) ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ─── Modal visualisation PDF (timeline workflow) ─────────── -->
            <div id="wf-preview-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)fermerWfPreview()">
                <div style="background:#fff;border-radius:14px;max-width:1100px;width:100%;height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);">
                    <div style="padding:14px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                        <h3 id="wf-preview-title" style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">📄 Visualisation</h3>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <a id="wf-preview-download" href="#" style="padding:6px 14px;border-radius:6px;background:#0ea5e9;color:#fff;text-decoration:none;font-size:12px;font-weight:600;">📎 Télécharger</a>
                            <button type="button" onclick="fermerWfPreview()" style="background:transparent;border:none;font-size:24px;cursor:pointer;color:#64748b;line-height:1;">×</button>
                        </div>
                    </div>
                    <iframe id="wf-preview-iframe" src="about:blank" style="flex:1;width:100%;border:0;border-radius:0 0 14px 14px;"></iframe>
                </div>
            </div>
            <script>
            function ouvrirWfPreview(logId, label) {
                document.getElementById('wf-preview-title').textContent = '📄 ' + label;
                document.getElementById('wf-preview-iframe').src = 'rh_salaire_workflow_download.php?id=' + logId + '&inline=1';
                document.getElementById('wf-preview-download').href = 'rh_salaire_workflow_download.php?id=' + logId;
                document.getElementById('wf-preview-modal').style.display = 'flex';
            }
            function fermerWfPreview() {
                document.getElementById('wf-preview-modal').style.display = 'none';
                document.getElementById('wf-preview-iframe').src = 'about:blank';
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && document.getElementById('wf-preview-modal').style.display === 'flex') {
                    fermerWfPreview();
                }
            });
            </script>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Tableau salaires ── -->
<div class="section-header">
    <div class="section-title">
        <span class="line-l"></span>
        <span class="sec-txt">Tableau des salaires -- <?=h($lib_mois_annee)?></span>
        <span class="line-r"></span>
    </div>
</div>
<div class="v2-card">
    <div style="padding:0;overflow-x:auto;max-height:calc(100vh - 320px)">
        <?php
        // Ordre d'affichage : comm_na, prime_exc, total_ik fusionnés = 13 cols data
        $DISPLAY_COLS = [
            'salaire_brut_base'    => ['label'=>'Brut',        'type'=>'money', 'class'=>'col-money'],
            'treizieme_mois'       => ['label'=>'13e',          'type'=>'money', 'class'=>'col-money'],
            'anciennete'           => ['label'=>'Anc.',         'type'=>'int',   'class'=>'col-int'],
            'avantage_nature'      => ['label'=>'Av. nat.',     'type'=>'money', 'class'=>'col-money'],
            'heures_supp'          => ['label'=>'H. supp',      'type'=>'money', 'class'=>'col-money'],
            'commission_ca'        => ['label'=>'Commission',   'type'=>'money', 'class'=>'col-long',  'merge'=>'ca_na'],
            'prime_admin'          => ['label'=>'Prime',        'type'=>'money', 'class'=>'col-med',   'merge'=>'adm_exc'],
            'ik_nb_km'             => ['label'=>'IK',           'type'=>'money', 'class'=>'col-med',   'merge'=>'km_tot'],
            'remboursement_achat'  => ['label'=>'Achats',       'type'=>'money', 'class'=>'col-med'],
            'frais_professionnels' => ['label'=>'Frais prof.',  'type'=>'money', 'class'=>'col-long'],
            'frais_reception'      => ['label'=>'Recep.',       'type'=>'money', 'class'=>'col-med'],
            'stationnement'        => ['label'=>'Station.',     'type'=>'money', 'class'=>'col-med'],
            'frais_deplacement'    => ['label'=>'Frais depl.',  'type'=>'money', 'class'=>'col-long'],
        ];
        $nbCols = count($DISPLAY_COLS); // 13
        ?>
        <table class="salary-table">
            <thead>
                <tr>
                    <th class="col-name">Collaborateur</th>
                    <?php foreach($DISPLAY_COLS as $col=>$dc): ?>
                        <th class="<?=h($dc['class'])?>"><?=h($dc['label'])?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php
                $rowIdx = 0;
                foreach($users as $u):
                    $formId = "form_".$u['id_user'];
                    $state  = 0;
                    if ($u['id_salaire']) $state = $u['termine_user'] ? 2 : 1;
                    $stripe = ($rowIdx % 2 === 0) ? 'row-even' : 'row-odd';
                    $rowIdx++;
                    // Total ligne
                    $_brut  = (float)($u['salaire_brut_base'] ?? 0);
                    $_anci  = ($_brut > 0 && ($u['anciennete'] ?? 0) > 0) ? $_brut * (int)$u['anciennete'] * 0.01 / 12 : 0;
                    $_total = $_brut + $_anci
                            + (float)($u['treizieme_mois'] ?? 0)
                            + (float)($u['commission_ca'] ?? 0)
                            + (float)($u['commission_ca_nouvelles_affaires'] ?? 0)
                            + (float)($u['avantage_nature'] ?? 0)
                            + (float)($u['heures_supp'] ?? 0)
                            + (float)($u['frais_professionnels'] ?? 0)
                            + (float)($u['frais_reception'] ?? 0)
                            + (float)($u['prime_admin'] ?? 0)
                            + (float)($u['prime_exceptionnelle'] ?? 0)
                            + (float)($u['stationnement'] ?? 0)
                            + (float)($u['frais_deplacement'] ?? 0)
                            + (float)($u['remboursement_achat'] ?? 0)
                            + (float)($u['total_ik'] ?? 0);
                ?>
                <form id="<?=$formId?>" method="post" action="rh_salaires.php?<?=h($currentQS)?>"></form>
                <input type="hidden" name="id_user" value="<?=$u['id_user']?>" form="<?=$formId?>">

                <!-- Ligne 1 : données -->
                <tr class="data-row state-<?=$state?> <?=$stripe?>">
                    <td><?=h($u['nom_complet'])?></td>
                    <?php foreach($DISPLAY_COLS as $col=>$dc):
                        $isProtected = $agenceScope > 0 && in_array($col, ['salaire_brut_base','treizieme_mois','anciennete'], true);
                        $merge = $dc['merge'] ?? null;
                    ?>
                        <td>
                        <?php if ($merge === 'ca_na'): ?>
                            <div class="stacked-field">
                                <span class="stacked-label">CA =</span>
                                <input type="text" name="commission_ca"
                                       value="<?=h(fmt_val($u['commission_ca']??null,'money'))?>"
                                       form="<?=$formId?>" placeholder="-"
                                       onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                            </div>
                        <?php elseif ($merge === 'adm_exc'): ?>
                            <div class="stacked-field">
                                <span class="stacked-label">Adm =</span>
                                <input type="text" name="prime_admin"
                                       value="<?=h(fmt_val($u['prime_admin']??null,'money'))?>"
                                       form="<?=$formId?>" placeholder="-"
                                       onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                            </div>
                        <?php elseif ($merge === 'km_tot'): ?>
                            <div class="stacked-field">
                                <span class="stacked-label">km =</span>
                                <input type="text" name="ik_nb_km"
                                       value="<?=h(fmt_val($u['ik_nb_km']??null,'money'))?>"
                                       form="<?=$formId?>" placeholder="-"
                                       onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                            </div>
                        <?php elseif ($isProtected): ?>
                            <input type="text" class="input-readonly"
                                   value="<?=h(fmt_val($u[$col]??null,$dc['type']))?>"
                                   placeholder="-" readonly
                                   title="Modification reservee a l'administrateur">
                        <?php else: ?>
                            <input type="text" name="<?=$col?>"
                                   value="<?=h(fmt_val($u[$col]??null,$dc['type']))?>"
                                   form="<?=$formId?>" placeholder="-"
                                   onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                        <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>

                <!-- Ligne 2 : action bar + véhicule + IK -->
                <tr class="action-row state-<?=$state?> <?=$stripe?>">
                    <td>
                        <input type="hidden" name="termine_user" value="<?=($u['termine_user']?'1':'0')?>" form="<?=$formId?>">
                        <div class="action-buttons">
                            <!-- Btn 1 : Cadenas -->
                            <button type="button"
                                    class="tbl-btn <?=$u['termine_user'] ? 'tbl-btn--ok' : 'tbl-btn--neutral'?>"
                                    onclick="toggleValidate('<?=$formId?>')"
                                    title="<?=$u['termine_user'] ? 'Deverrouiller' : 'Valider'?>">
                                <?=$u['termine_user'] ? '&#x1F512;' : '&#x1F513;'?>
                            </button>

                            <!-- Btn 2 : Loupe / placeholder -->
                            <?php if ($u['id_salaire']): ?>
                            <a href="rh_salaire_detail.php?id_user=<?=$u['id_user']?>&mois_ref=<?=urlencode($mois_ref)?>"
                               class="tbl-btn tbl-btn--blue" title="Voir le detail">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="22" y2="22"/></svg>
                            </a>
                            <?php else: ?>
                            <span class="tbl-btn tbl-btn--blue tbl-btn--empty"></span>
                            <?php endif; ?>

                            <!-- Btn 3 : + créer / check validé -->
                            <?php if (!$u['id_salaire']): ?>
                            <button type="button"
                                    class="tbl-btn tbl-btn--blue"
                                    onclick="createOneSalary(<?=$u['id_user']?>, <?=$mois_sel?>, <?=$annee_sel?>, <?=h(json_encode($u['nom_complet']))?>, <?=h(json_encode(mois_fr($mois_sel)))?>, <?=$annee_sel?>)"
                                    title="Creer le salaire">+</button>
                            <?php else: ?>
                            <span class="tbl-btn tbl-btn--check" title="Salaire cree">&#x2713;</span>
                            <?php endif; ?>

                            <!-- Btn 4 : Mail -->
                            <button type="button"
                                    class="tbl-btn tbl-btn--neutral"
                                    onclick="sendEmailToUser(<?=$u['id_user']?>, <?=$mois_sel?>, <?=$annee_sel?>)"
                                    title="Envoyer un email de rappel">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            </button>
                        </div>
                    </td>
                    <!-- Total sur brut + 13e + anc (cols 1-3) -->
                    <td colspan="3" style="text-align:left;vertical-align:middle;padding-left:4px">
                        <?php if ($_total > 0): ?>
                        <div class="row-total-btn"><?=number_format($_total, 0, ',', ' ')?> &euro;</div>
                        <?php endif; ?>
                    </td>
                    <!-- Vide : av_nature + h_supp (cols 4-5) -->
                    <td colspan="2"></td>
                    <!-- NA = dans la colonne commission (col 6) -->
                    <td style="vertical-align:middle">
                        <div class="stacked-field">
                            <span class="stacked-label">NA =</span>
                            <input type="text"
                                   name="commission_ca_nouvelles_affaires"
                                   value="<?=h(fmt_val($u['commission_ca_nouvelles_affaires']??null, 'money'))?>"
                                   form="<?=$formId?>"
                                   placeholder="-"
                                   onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                        </div>
                    </td>
                    <!-- Exc = dans la colonne prime (col 7) -->
                    <td style="vertical-align:middle">
                        <div class="stacked-field">
                            <span class="stacked-label">Exc =</span>
                            <input type="text"
                                   name="prime_exceptionnelle"
                                   value="<?=h(fmt_val($u['prime_exceptionnelle']??null, 'money'))?>"
                                   form="<?=$formId?>"
                                   placeholder="-"
                                   onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                        </div>
                    </td>
                    <!-- IK Total = dans la colonne IK (col 8) -->
                    <td style="vertical-align:middle">
                        <div class="stacked-field">
                            <span class="stacked-label">IK =</span>
                            <input type="text"
                                   name="total_ik"
                                   value="<?=h(fmt_val($u['total_ik']??null, 'money'))?>"
                                   form="<?=$formId?>"
                                   placeholder="-"
                                   onchange="autoSaveField('<?=$formId?>', <?=$u['id_user']?>)">
                        </div>
                    </td>
                    <!-- Vide : colonnes restantes (cols 9 à nbCols) -->
                    <td colspan="<?=$nbCols - 8?>"></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
