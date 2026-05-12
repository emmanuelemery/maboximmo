<?php
/**
 * GÉNÉRATEUR DE VISUELS - BIEN 886
 * Page pour générer et afficher les visuels du bien 886
 */

// Include database config et functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config.php');
require_once(__DIR__ . '/integration.php');

// Récupérer les infos du bien 886
try {
  $connexion = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );

  // Récupérer le bien
  $query = "SELECT * FROM bien WHERE bien_id = 886 LIMIT 1";
  $stmt = $connexion->prepare($query);
  $stmt->execute();
  $bien = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$bien) {
    die("❌ Bien 886 non trouvé!");
  }

  // Récupérer les photos
  $query_photos = "SELECT photo_path FROM bien_photos WHERE bien_id = 886 ORDER BY photo_order ASC";
  $stmt_photos = $connexion->prepare($query_photos);
  $stmt_photos->execute();
  $photos = $stmt_photos->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
  die("❌ Erreur BD: " . $e->getMessage());
}

// Préparer les données pour les templates
$data = [
  'logo_svg' => '<svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="5" y="5" width="50" height="50" stroke="white" stroke-width="2"/><path d="M20 35L30 20L40 35" stroke="white" stroke-width="2" fill="none"/></svg>',
  'title' => $bien['bien_titre'] ?? 'BIEN ' . $bien['bien_id'],
  'subtitle' => ($bien['bien_pieces'] ?? 'N/A') . ' pièces – ' . ($bien['bien_surface'] ?? 'N/A') . ' m²',
  'image_url' => !empty($photos) ? '/uploads/' . $photos[0] : 'https://via.placeholder.com/1122x1200?text=Photo+Bien',
  'price' => number_format($bien['bien_prix'] ?? 0, 0, ',', ' ') . ' €',
  'main_text' => strtoupper($bien['bien_titre'] ?? 'Bien'),
  'main_image' => !empty($photos) ? '/uploads/' . $photos[0] : 'https://via.placeholder.com/1122x1200?text=Photo+Bien',
  'gallery_images' => array_map(function($p) { return '/uploads/' . $p; }, array_slice($photos, 1, 3)),
  'features' => [
    ['icon' => '🛏️', 'label' => ($bien['bien_pieces'] ?? 'N/A') . ' PIÈCES'],
    ['icon' => '📏', 'label' => ($bien['bien_surface'] ?? 'N/A') . ' m²'],
    ['icon' => '€', 'label' => number_format($bien['bien_prix'] ?? 0, 0) . ' €'],
  ]
];

// Déterminer quelle approche utiliser
$approach = 'approche1'; // Par défaut

if (!empty($bien['bien_prix']) && $bien['bien_prix'] > 400000) {
  $approach = 'approche4'; // Multi-photos pour haut de gamme
} elseif (!empty($bien['bien_titre']) && stripos($bien['bien_titre'], 'lumineux') !== false) {
  $approach = 'approche1'; // Photo en grand si lumineux
} else {
  $approach = 'approche2'; // Argument en avant par défaut
}

// Instancier le renderer
$renderer = new AnnounceVisualRenderer();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visuel Bien 886</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 2rem; }
    .container { max-width: 1400px; margin: 0 auto; }

    h1 { color: #1a3a4a; margin-bottom: 1rem; }
    .bien-info { background: white; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; }
    .bien-info p { margin: 0.5rem 0; color: #666; }

    .formats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
      gap: 2rem;
      margin-top: 2rem;
    }

    .format-box {
      background: white;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .format-label {
      background: #1a3a4a;
      color: white;
      padding: 0.75rem 1rem;
      font-weight: bold;
      font-size: 0.9rem;
    }

    .format-content {
      padding: 1rem;
      min-height: 300px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f9f9f9;
    }

    .format-content iframe {
      width: 100%;
      height: 600px;
      border: none;
      border-radius: 4px;
    }

    .download-btn {
      background: #c9a961;
      color: white;
      border: none;
      padding: 0.75rem 1.5rem;
      border-radius: 4px;
      cursor: pointer;
      font-weight: bold;
      text-decoration: none;
      display: inline-block;
      margin-top: 1rem;
    }

    .download-btn:hover {
      background: #b8975a;
    }

    .warning {
      background: #fff3cd;
      border-left: 4px solid #ffc107;
      padding: 1rem;
      margin-bottom: 1rem;
      border-radius: 4px;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>🎨 Visuels Annonce - Bien <?php echo $bien['bien_id']; ?></h1>

    <div class="bien-info">
      <p><strong>Titre:</strong> <?php echo htmlspecialchars($bien['bien_titre'] ?? 'N/A'); ?></p>
      <p><strong>Prix:</strong> <?php echo number_format($bien['bien_prix'] ?? 0, 0, ',', ' '); ?> €</p>
      <p><strong>Surface:</strong> <?php echo $bien['bien_surface'] ?? 'N/A'; ?> m²</p>
      <p><strong>Pièces:</strong> <?php echo $bien['bien_pieces'] ?? 'N/A'; ?></p>
      <p><strong>Approach sélectionnée:</strong> <span style="background: #e8d5b7; padding: 0.25rem 0.75rem; border-radius: 4px; font-weight: bold;"><?php echo strtoupper(str_replace('approche', 'Approche ', $approach)); ?></span></p>
    </div>

    <div class="warning">
      <strong>ℹ️ Note:</strong> Les visuels s'affichent en HTML. Pour convertir en PNG/JPG, utiliser un outil de capture d'écran ou un service cloud (wkhtmltoimage, puppeteer).
    </div>

    <div class="formats-grid">
      <!-- A3 HORIZONTAL -->
      <div class="format-box">
        <div class="format-label">📄 A3 Horizontal (Vitrine Agence)</div>
        <div class="format-content">
          <iframe srcdoc="<?php echo htmlspecialchars($renderer->render($approach, $data, 'a3')); ?>"></iframe>
        </div>
        <div style="padding: 1rem;">
          <p style="color: #666; font-size: 0.85rem;">1122×1587px • Imprimer 42×60cm</p>
          <button class="download-btn" onclick="downloadHTML('<?php echo $approach; ?>', 'a3', 'bien-886-a3.html')">⬇️ Télécharger</button>
        </div>
      </div>

      <!-- A4 VERTICAL -->
      <div class="format-box">
        <div class="format-label">📄 A4 Vertical (Email/Papier)</div>
        <div class="format-content">
          <iframe srcdoc="<?php echo htmlspecialchars($renderer->render($approach, $data, 'a4')); ?>"></iframe>
        </div>
        <div style="padding: 1rem;">
          <p style="color: #666; font-size: 0.85rem;">794×1123px • Format feuille</p>
          <button class="download-btn" onclick="downloadHTML('<?php echo $approach; ?>', 'a4', 'bien-886-a4.html')">⬇️ Télécharger</button>
        </div>
      </div>

      <!-- INSTAGRAM CARRÉ -->
      <div class="format-box">
        <div class="format-label">📸 Instagram Carré</div>
        <div class="format-content">
          <iframe srcdoc="<?php echo htmlspecialchars($renderer->render($approach, $data, 'instagram-square')); ?>"></iframe>
        </div>
        <div style="padding: 1rem;">
          <p style="color: #666; font-size: 0.85rem;">1080×1080px • Grid Instagram</p>
          <button class="download-btn" onclick="downloadHTML('<?php echo $approach; ?>', 'instagram-square', 'bien-886-ig-square.html')">⬇️ Télécharger</button>
        </div>
      </div>

      <!-- INSTAGRAM STORY -->
      <div class="format-box">
        <div class="format-label">🎬 Instagram Story</div>
        <div class="format-content">
          <iframe srcdoc="<?php echo htmlspecialchars($renderer->render($approach, $data, 'instagram-story')); ?>"></iframe>
        </div>
        <div style="padding: 1rem;">
          <p style="color: #666; font-size: 0.85rem;">1080×1920px • Vertical</p>
          <button class="download-btn" onclick="downloadHTML('<?php echo $approach; ?>', 'instagram-story', 'bien-886-ig-story.html')">⬇️ Télécharger</button>
        </div>
      </div>

      <!-- FACEBOOK/LINKEDIN -->
      <div class="format-box">
        <div class="format-label">💼 Facebook/LinkedIn</div>
        <div class="format-content">
          <iframe srcdoc="<?php echo htmlspecialchars($renderer->render($approach, $data, 'facebook')); ?>"></iframe>
        </div>
        <div style="padding: 1rem;">
          <p style="color: #666; font-size: 0.85rem;">1200×628px • Réseaux sociaux</p>
          <button class="download-btn" onclick="downloadHTML('<?php echo $approach; ?>', 'facebook', 'bien-886-fb.html')">⬇️ Télécharger</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    function downloadHTML(approach, format, filename) {
      // Récupérer le contenu HTML de l'iframe
      const iframe = event.target.closest('.format-box').querySelector('iframe');
      const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
      const html = iframeDoc.documentElement.outerHTML;

      // Créer un blob et télécharger
      const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);
    }
  </script>
</body>
</html>
