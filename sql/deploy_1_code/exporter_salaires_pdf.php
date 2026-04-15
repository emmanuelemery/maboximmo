<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
session_start();

try {
    require_once __DIR__ . '/inc/bootstrap.php';
    require_once __DIR__ . '/inc/auth.php';
    require_once __DIR__ . '/inc/rh_helpers.php';
} catch (Exception $e) {
    http_response_code(500);
    exit("Erreur include: " . htmlspecialchars($e->getMessage()));
}

require_login();

$roleId      = current_role_id();
$agenceScope = can_manage_salaires_agence();
if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Include TCPDF
$tcpdfIncluded = false;
try {
    foreach ([__DIR__ . '/tcpdf/tcpdf.php', __DIR__ . '/tcpdf_min/tcpdf.php'] as $p) {
        if (is_file($p)) {
            require_once $p;
            $tcpdfIncluded = true;
            break;
        }
    }
    if (!$tcpdfIncluded) {
        http_response_code(500);
        error_log("TCPDF not found in expected paths");
        exit("TCPDF introuvable");
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("TCPDF include error: " . $e->getMessage());
    exit("Erreur TCPDF: " . htmlspecialchars($e->getMessage()));
}

// Cache TCPDF
if (!defined('K_PATH_CACHE')) {
    $cacheDir = __DIR__ . '/tcpdf_cache/';
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0777, true)) {
            error_log("Failed to create TCPDF cache directory: " . $cacheDir);
        }
    }
    define('K_PATH_CACHE', $cacheDir);
}

// Verify TCPDF class exists
if (!class_exists('TCPDF')) {
    http_response_code(500);
    exit("ERREUR: Classe TCPDF non disponible après inclusion");
}

// Params
$mois = (int)($_GET['mois'] ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));
$societe_id = !empty($_GET['societe']) && $_GET['societe'] !== 'toutes' ? (int)$_GET['societe'] : null;

$mois_ref = sprintf('%04d-%02d-01', $annee, $mois);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m) { $n=[1=>'Janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre']; return $n[(int)$m]??''; }
function euro($v) { return number_format((float)$v, 2, ',', ' ').' €'; }

// Récupère tous les champs salaire
$allFields = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        $field = $col['Field'];
        // Exclure certains champs
        $excludeFields = ['id', 'id_user', 'mois_reference', 'termine_user', 'mois_cloture', 'commentaire_general', 'commentaire_admin', 'date_entree', 'numero_securite_sociale', 'ik_montant'];
        if (!in_array($field, $excludeFields)) {
            // Déterminer le type de champ
            $type = ($field === 'ik_nb_km') ? 'number' : 'money';
            $allFields[$field] = ['label' => ucwords(str_replace('_', ' ', $field)), 'type' => $type];
        }
    }
} catch (Exception $e) {
    error_log("Erreur SHOW COLUMNS: " . $e->getMessage());
}

// Requête principale: récupère les salaires avec users et societes
$fieldsList = !empty($allFields) ? ", " . implode(", ", array_map(fn($f) => "s.`$f`", array_keys($allFields))) : "";
$sql = "
    SELECT
        u.id as id_user,
        u.actif,
        CONCAT(IFNULL(u.prenom, ''), ' ', IFNULL(u.nom, '')) AS nom_complet,
        soc.nom as societe_nom,
        etab.nom_agence as agence_nom,
        s.id as id_salaire,
        s.mois_reference,
        s.termine_user,
        s.mois_cloture,
        s.commentaire_general,
        s.commentaire_admin
        " . $fieldsList . "
    FROM users u
    LEFT JOIN societes soc ON u.id_societe = soc.id
    LEFT JOIN agences etab ON u.id_agence = etab.id
    LEFT JOIN salaires s ON (s.id_user = u.id OR s.id_user = u.id_legacy)
        AND s.mois_reference = :mr
    WHERE s.id IS NOT NULL
";

$params = [':mr' => $mois_ref];

if ($agenceScope > 0) {
    // Gestionnaire agence : forcer le filtre sur son agence, ignorer les autres filtres
    $sql .= " AND u.id_agence = :agence_scope";
    $params[':agence_scope'] = $agenceScope;
} elseif ($societe_id !== null) {
    $sql .= " AND u.id_societe = :societe_id";
    $params[':societe_id'] = $societe_id;
}

$sql .= " ORDER BY soc.nom ASC, etab.nom_agence ASC, u.nom ASC, u.prenom ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $salaires = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Erreur SQL: " . $e->getMessage());
    exit('Erreur lors de la récupération des salaires: ' . $e->getMessage());
}

if (empty($salaires)) {
    http_response_code(404);
    exit('Aucun salaire trouvé pour cette période');
}

// Groupe les salaires par société > agence > utilisateur
$grouped = [];
foreach ($salaires as $row) {
    $societe = $row['societe_nom'] ?? 'Sans société';
    $agence = $row['agence_nom'] ?? 'Sans agence';

    if (!isset($grouped[$societe])) {
        $grouped[$societe] = [];
    }
    if (!isset($grouped[$societe][$agence])) {
        $grouped[$societe][$agence] = [];
    }
    $grouped[$societe][$agence][] = $row;
}

// PDF
class SalairesPDF extends TCPDF {
    public $footerText = '';
    public function Footer() {
        $this->SetY(-22);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->SetDrawColor(210, 210, 210);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);
        if ($this->footerText) {
            $this->MultiCell(0, 4, $this->footerText, 0, 'C', false);
        }
        $this->SetY(-10);
        $this->Cell(0, 4, 'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
    }
}

try {
    $pdf = new SalairesPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Export Salaires');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 24);
    $pdf->AddPage();

    // Titre
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetTextColor(30, 30, 30);
    $pdf->Cell(0, 10, 'REGISTRE DES SALAIRES', 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, mois_fr($mois) . ' ' . $annee, 0, 1, 'C');
    $pdf->Ln(6);

    // Parcours les sociétés
    foreach ($grouped as $societe_nom => $agences) {
    // Titre société
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->SetTextColor(35, 35, 35);
    $pdf->SetFillColor(238, 242, 248);
    $pdf->Cell(0, 8, $societe_nom, 0, 1, 'L', true);
    $pdf->Ln(2);

    // Parcours les agences
    foreach ($agences as $agence_nom => $users) {
        // Titre agence
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(55, 55, 55);
        $pdf->Cell(0, 7, '  › ' . $agence_nom, 0, 1, 'L');
        $pdf->Ln(1);

        // Parcours les utilisateurs
        foreach ($users as $user) {
            $isInactive = (int)$user['actif'] === 0;
            $isValidated = (int)$user['termine_user'] === 1;

            // Nom de l'utilisateur
            $pdf->SetFont('dejavusans', $isInactive ? 'BI' : 'B', 10);
            $pdf->SetTextColor($isInactive ? 160 : 70, $isInactive ? 160 : 70, $isInactive ? 160 : 70);
            $lockStatus = $isValidated ? '(V)' : '(X)';
            $userName = $lockStatus . ' ' . h($user['nom_complet']) . ($isInactive ? ' (parti de la société)' : '');
            $pdf->Cell(0, 6, '    ' . $userName, 0, 1, 'L');

            // Récupère les champs renseignés
            $filledFields = [];
            foreach ($allFields as $fieldName => $fieldMeta) {
                $value = $user[$fieldName];
                if ($value !== null && $value !== '' && (float)$value !== 0.0) {
                    $filledFields[$fieldName] = $value;
                }
            }

            // Affiche les champs
            if (!empty($filledFields)) {
                $pdf->SetFont('dejavusans', $isInactive ? 'I' : '', 9);
                $pdf->SetTextColor($isInactive ? 170 : 90, $isInactive ? 170 : 90, $isInactive ? 170 : 90);
                foreach ($filledFields as $fieldName => $value) {
                    $label = ucwords(str_replace('_', ' ', $fieldName));
                    // Format based on field type
                    if ($allFields[$fieldName]['type'] === 'number') {
                        $formatted = number_format((float)$value, 2, ',', ' ');
                    } else {
                        $formatted = euro($value);
                    }
                    // Utiliser une position fixe pour aligner les valeurs
                    $pdf->SetX(50);
                    $pdf->SetY($pdf->GetY());
                    $pdf->Cell(30, 5, '• ' . h($label), 0, 0, 'L');
                    $pdf->SetX(95);
                    $pdf->Cell(0, 5, $formatted, 0, 1, 'L');
                }
            } else {
                $pdf->SetFont('dejavusans', $isInactive ? 'I' : '', 9);
                $pdf->SetTextColor($isInactive ? 170 : 150, $isInactive ? 170 : 150, $isInactive ? 170 : 150);
                $pdf->Cell(0, 5, '      (Aucun champ renseigné)', 0, 1, 'L');
            }

            // Commentaire général
            if (!empty($user['commentaire_general'])) {
                $pdf->SetFont('dejavusans', 'I', 9);
                $pdf->SetTextColor($isInactive ? 170 : 120, $isInactive ? 170 : 120, $isInactive ? 170 : 120);
                $comment = trim((string)$user['commentaire_general']);
                $pdf->MultiCell(0, 4, '      Note: ' . h($comment), 0, 'L');
            }


            $pdf->Ln(2);
        }

        $pdf->Ln(2);
        // Saut de page après chaque agence
        $pdf->AddPage();
    }

    $pdf->Ln(4);
}

    // Sortie PDF
    $baseName = 'SALAIRES_' . $annee . '_' . str_pad((string)$mois, 2, '0', STR_PAD_LEFT) . '.pdf';
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $baseName . '"');
    $pdf->Output($baseName, 'I');
    exit;
} catch (Throwable $e) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="color: red; background: #f5f5f5; padding: 20px; border: 1px solid #ddd;">';
    echo "ERREUR PDF: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
    error_log("Erreur export PDF: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    exit;
}
