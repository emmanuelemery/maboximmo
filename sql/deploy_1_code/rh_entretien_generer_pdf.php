<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Accès réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403); exit('Accès réservé aux managers et administrateurs.');
}

$entretienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($entretienId <= 0) { http_response_code(400); exit('Identifiant manquant.'); }

// --- Charger entretien ---
try {
    $stmt = $pdo->prepare("
        SELECT e.*,
               uc.prenom AS collab_prenom, uc.nom AS collab_nom,
               um.prenom AS manager_prenom, um.nom AS manager_nom,
               a.nom_agence
        FROM rh_entretiens e
        JOIN users uc ON uc.id = e.collaborateur_id
        JOIN users um ON um.id = e.manager_id
        LEFT JOIN agences a ON a.id = e.agence_id
        WHERE e.id = ?
    ");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('PDF entretien: ' . $e->getMessage());
    http_response_code(500); exit('Erreur base de données.');
}

if (!$entretien) { http_response_code(404); exit('Entretien introuvable.'); }

// Vérifier ownership
if ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId) {
    http_response_code(403); exit('Accès refusé.');
}

// --- Charger réponses visible_pdf=1 ---
$reponses = [];
try {
    $stmtR = $pdo->prepare("
        SELECT r.*, c.label AS critere_label
        FROM rh_entretien_reponses r
        LEFT JOIN rh_entretien_criteres c ON c.id = r.critere_id
        WHERE r.entretien_id = ? AND r.visible_pdf = 1
        ORDER BY r.rubrique_id ASC, r.id ASC
    ");
    $stmtR->execute([$entretienId]);
    $reponsesRaw = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reponsesRaw as $rep) {
        $rid = (int)$rep['rubrique_id'];
        if ($rid === 9) continue; // Rubrique 9 exclue
        $reponses[$rid][] = $rep;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Charger plan d'actions ---
$actions = [];
try {
    $stmtA = $pdo->prepare("
        SELECT a.*, CONCAT(u.prenom, ' ', u.nom) AS responsable
        FROM rh_entretien_actions a
        LEFT JOIN users u ON u.id = a.responsable_id
        WHERE a.entretien_id = ? AND a.visible_collaborateur = 1
        ORDER BY a.id ASC
    ");
    $stmtA->execute([$entretienId]);
    $actions = $stmtA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// --- Charger signatures ---
$sigManager = null;
$sigCollab  = null;
try {
    $stmtSig = $pdo->prepare("
        SELECT signataire_type, signature_data, signed_at
        FROM signatures
        WHERE module = 'rh_entretien' AND entity_id = ?
        ORDER BY signed_at DESC
    ");
    $stmtSig->execute([$entretienId]);
    foreach ($stmtSig->fetchAll(PDO::FETCH_ASSOC) as $sig) {
        if ($sig['signataire_type'] === 'manager'       && $sigManager === null) $sigManager = $sig;
        if ($sig['signataire_type'] === 'collaborateur' && $sigCollab  === null) $sigCollab  = $sig;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Include TCPDF ---
$tcpdfIncluded = false;
try {
    foreach ([__DIR__ . '/tcpdf/tcpdf.php', __DIR__ . '/tcpdf_min/tcpdf.php'] as $p) {
        if (is_file($p)) { require_once $p; $tcpdfIncluded = true; break; }
    }
    if (!$tcpdfIncluded) {
        error_log("TCPDF not found");
        http_response_code(500); exit("TCPDF introuvable");
    }
} catch (Exception $e) {
    http_response_code(500); exit("Erreur TCPDF : " . htmlspecialchars($e->getMessage()));
}

// Cache TCPDF
if (!defined('K_PATH_CACHE')) {
    $cacheDir = __DIR__ . '/tcpdf_cache/';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
    define('K_PATH_CACHE', $cacheDir);
}

// --- Classe PDF ---
class EntretienPDF extends TCPDF {
    public string $agence = '';
    public string $dateDoc = '';

    public function Header(): void {
        $this->SetFont('dejavusans', 'B', 14);
        $this->SetTextColor(26, 26, 46);
        $this->Cell(0, 8, 'COMPTE-RENDU D\'ENTRETIEN', 0, 1, 'C');
        $this->SetFont('dejavusans', '', 9);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, 'MaBoxImmo' . ($this->agence ? ' — ' . $this->agence : ''), 0, 1, 'C');
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(15, $this->GetY() + 1, $this->getPageWidth() - 15, $this->GetY() + 1);
        $this->Ln(4);
    }

    public function Footer(): void {
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'Document confidentiel — MaBoxImmo — Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages() . ' — ' . $this->dateDoc, 0, 0, 'C');
    }
}

// --- Helpers ---
function starsStr(int $note, int $max = 5): string {
    $s = '';
    for ($i = 1; $i <= $max; $i++) {
        $s .= ($i <= $note) ? "\u{2605}" : "\u{2606}";
    }
    return $s;
}


function tcpdf_has_png_alpha_support(): bool {
    return extension_loaded('gd') || extension_loaded('imagick');
}

function b64ImageToTmpFile(string $b64data, ?string &$mime = null, ?bool &$pngAlpha = null): ?string {
    $mime = null;
    $pngAlpha = false;
    if (preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,/', $b64data, $m)) {
        $mime = strtolower($m[1]);
        $b64data = substr($b64data, strpos($b64data, ',') + 1);
    }
    $decoded = base64_decode($b64data, true);
    if ($decoded === false) return null;

    if ($mime === null) {
        if (strncmp($decoded, "\x89PNG\r\n\x1a\n", 8) === 0) {
            $mime = 'png';
        } elseif (strncmp($decoded, "\xFF\xD8\xFF", 3) === 0) {
            $mime = 'jpeg';
        }
    }

    if ($mime === 'png' && strlen($decoded) > 26) {
        $colorType = ord($decoded[25]);
        if ($colorType === 4 || $colorType === 6) {
            $pngAlpha = true;
        }
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'sig_');
    file_put_contents($tmpFile, $decoded);
    return $tmpFile;
}// Noms de rubriques
$rubriquesNoms = [
    1  => 'Ouverture',
    2  => 'Bilan collaborateur',
    3  => 'Valorisation',
    4  => 'Objectifs',
    5  => 'Compétences',
    6  => 'Formation & Développement',
    7  => 'Motivation & Engagement',
    8  => 'Conditions de travail',
    9  => 'Rémunération (confidentiel)',
    10 => 'Perspectives',
    11 => 'Synthèse',
];

$typeLabel = [
    'annuel'        => 'Entretien Annuel',
    'professionnel' => 'Entretien Professionnel',
    'mi_annuel'     => 'Entretien Mi-Annuel',
    'recadrage'     => 'Entretien de Recadrage',
    'fin_periode'   => 'Fin de Période d\'Essai',
];
$typeStr = $typeLabel[$entretien['type_entretien'] ?? ''] ?? ($entretien['type_entretien'] ?? '');

$dateObj = !empty($entretien['date_entretien'])
    ? new DateTime($entretien['date_entretien'])
    : (!empty($entretien['date_planifiee']) ? new DateTime($entretien['date_planifiee']) : new DateTime());
$dateFormatted = $dateObj->format('d/m/Y');

// --- Construire PDF ---
ob_end_clean();

$pdf = new EntretienPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->agence  = $entretien['nom_agence'] ?? '';
$pdf->dateDoc = (new DateTime())->format('d/m/Y');

$pdf->SetCreator('MaBoxImmo');
$pdf->SetAuthor('MaBoxImmo RH');
$pdf->SetTitle('Compte-rendu entretien — ' . $entretien['collab_prenom'] . ' ' . $entretien['collab_nom']);
$pdf->SetSubject($typeStr);

$pdf->setMargins(15, 28, 15);
$pdf->SetAutoPageBreak(true, 20);

$pdf->AddPage('P', 'A4');

// Bloc infos
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->SetFont('dejavusans', 'B', 9);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetLineWidth(0.2);

$pdf->RoundedRect(15, $pdf->GetY(), $pdf->getPageWidth() - 30, 34, 3, '1111', 'DF');
$yInfo = $pdf->GetY() + 4;

$infoRows = [
    ['Collaborateur', $entretien['collab_prenom'] . ' ' . $entretien['collab_nom']],
    ['Manager',       $entretien['manager_prenom'] . ' ' . $entretien['manager_nom']],
    ['Type',          $typeStr],
    ['Date',          $dateFormatted],
];
if (!empty($entretien['nom_agence'])) {
    $infoRows[] = ['Agence', $entretien['nom_agence']];
}
$col = 0;
$xLeft  = 18;
$xRight = $pdf->getPageWidth() / 2 + 2;
foreach ($infoRows as $i => $row) {
    $x = ($i % 2 === 0) ? $xLeft : $xRight;
    $y = $yInfo + (int)floor($i / 2) * 7;
    $pdf->SetXY($x, $y);
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(28, 5, $row[0] . ' :', 0, 0, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(26, 26, 46);
    $pdf->Cell(60, 5, $row[1], 0, 0, 'L');
}
$pdf->SetY($yInfo + (int)ceil(count($infoRows) / 2) * 7 + 4);

// --- Synthèse (rubrique 11) ---
if (!empty($reponses[11])) {
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(255, 160, 64);
    $pdf->Cell(0, 7, 'SYNTHESE', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    foreach ($reponses[11] as $rep) {
        if (!empty($rep['texte_final'])) {
            $pdf->MultiCell(0, 5, $rep['texte_final'], 0, 'L');
            $pdf->Ln(2);
        }
    }
    $pdf->Ln(3);
}

// --- Plan d'actions ---
if (!empty($actions)) {
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(255, 160, 64);
    $pdf->Cell(0, 7, 'PLAN D\'ACTIONS', 0, 1, 'L');
    $pdf->SetLineWidth(0.15);
    $pdf->SetDrawColor(226, 232, 240);
    foreach ($actions as $act) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(26, 26, 46);
        $titre    = $act['libelle'] ?? '';
        $resp     = !empty($act['responsable']) ? $act['responsable'] : '';
        $echeance = !empty($act['echeance'])    ? (new DateTime($act['echeance']))->format('d/m/Y') : '';
        $meta = trim(implode(' — ', array_filter([$resp, $echeance ? 'Échéance : ' . $echeance : ''])));

        $pdf->SetX(15);
        $pdf->Cell(5, 5, "\u{25A1}", 0, 0, 'L');
        $pdf->MultiCell(0, 5, $titre . ($meta ? '   (' . $meta . ')' : ''), 0, 'L');
    }
    $pdf->Ln(3);
}

// --- Rubriques 1-8, 10, 11 déjà affichée ---
$ordreRubriques = [1, 2, 3, 4, 5, 6, 7, 8, 10];
foreach ($ordreRubriques as $rid) {
    if (empty($reponses[$rid])) continue;
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(255, 160, 64);
    $pdf->Cell(0, 7, strtoupper($rubriquesNoms[$rid] ?? 'Rubrique ' . $rid), 0, 1, 'L');
    $pdf->SetLineWidth(0.1);
    $pdf->SetDrawColor(241, 245, 249);

    foreach ($reponses[$rid] as $rep) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(51, 65, 85);

        $label = $rep['critere_label'] ?? '';
        $note  = (int)($rep['note'] ?? 0);
        $texte = $rep['texte_final'] ?? '';

        if ($label) {
            $pdf->SetX(15);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(80, 5, $label, 0, 0, 'L');
            if ($note > 0) {
                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetTextColor(255, 140, 0);
                $pdf->Cell(0, 5, starsStr($note), 0, 1, 'L');
            } else {
                $pdf->Ln(5);
            }
        }
        if ($texte) {
            $pdf->SetX(18);
            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->MultiCell($pdf->getPageWidth() - 36, 4.5, $texte, 0, 'L');
        }
    }
    $pdf->Ln(3);
}

// --- Signatures ---
$sigFiles = [];
if ($sigManager !== null || $sigCollab !== null) {
    $pdf->AddPage('P', 'A4');
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(255, 160, 64);
    $pdf->Cell(0, 7, 'SIGNATURES', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(26, 26, 46);
    $pdf->Ln(3);

    $sigPairs = [
        ['titre' => 'Manager',       'sig' => $sigManager],
        ['titre' => 'Collaborateur', 'sig' => $sigCollab],
    ];

    foreach ($sigPairs as $sp) {
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->Cell(0, 6, 'Signature ' . $sp['titre'], 0, 1, 'L');
        if ($sp['sig'] !== null && !empty($sp['sig']['signature_data'])) {
            $mime = null; $pngAlpha = false; $tmpF = b64ImageToTmpFile($sp['sig']['signature_data'], $mime, $pngAlpha);
            if ($tmpF) {
                $sigFiles[] = $tmpF;
                if ($pngAlpha && !tcpdf_has_png_alpha_support()) {
                    $pdf->SetFont('dejavusans', 'I', 8);
                    $pdf->SetTextColor(100, 116, 139);
                    $pdf->Cell(0, 5, '[Signature PNG avec transparence non supportee - activer GD/Imagick]', 0, 1, 'L');
                } else {
                    try {
                        $type = $mime ? strtoupper($mime) : 'PNG';
                        $pdf->Image($tmpF, 15, $pdf->GetY(), 80, 30, $type);
                    } catch (Exception $e) {
                        $pdf->SetFont('dejavusans', 'I', 8);
                        $pdf->Cell(0, 5, '[Image signature indisponible]', 0, 1, 'L');
                    }
                }
                $pdf->SetY($pdf->GetY() + 33);
            }
        } else {
            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(0, 5, 'Non signé', 0, 1, 'L');
            $pdf->Ln(3);
        }
        if (!empty($sp['sig']['signed_at'])) {
            $pdf->SetFont('dejavusans', 'I', 7.5);
            $pdf->SetTextColor(150, 150, 150);
            $signedDate = (new DateTime($sp['sig']['signed_at']))->format('d/m/Y à H:i');
            $pdf->Cell(0, 4, 'Signé le ' . $signedDate, 0, 1, 'L');
        }
        $pdf->Ln(4);
    }
}

// Nettoyer fichiers temporaires
foreach ($sigFiles as $f) {
    if (is_file($f)) @unlink($f);
}

// Nom fichier
$nomCollab = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtoupper($entretien['collab_nom']));
$dateFile  = $dateObj->format('Y-m-d');
$fileName  = 'ENTRETIEN_' . $nomCollab . '_' . $dateFile . '.pdf';

$pdf->Output($fileName, 'I');
