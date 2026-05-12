<?php
/**
 * INTÉGRATION TEMPLATES ANNONCES VISUELS
 *
 * Exemple d'utilisation dans MaBoxImmo
 * Utilisation : render_annonce_visual('approche1', $data, 'a3')
 */

class AnnounceVisualRenderer {

  private $template_dir = __DIR__;
  private $available_approaches = [
    'approche1' => 'photo-grand',
    'approche2' => 'argument-avant',
    'approche3' => 'ciblage',
    'approche4' => 'multi-photos',
  ];

  private $available_formats = [
    'a3' => 'A3 Horizontal Vitrine',
    'a4' => 'A4 Vertical Papier/Email',
    'instagram-square' => 'Instagram Carré',
    'instagram-story' => 'Instagram Story',
    'facebook' => 'Facebook/LinkedIn',
  ];

  /**
   * Render visual for an announcement
   *
   * @param string $approach 'approche1', 'approche2', 'approche3', 'approche4'
   * @param array $data Variables to inject (logo_svg, title, image_url, price, etc.)
   * @param string $format 'a3', 'a4', 'instagram-square', 'instagram-story', 'facebook'
   * @return string HTML rendering
   */
  public function render($approach, $data, $format = 'a3') {
    // Validate inputs
    if (!isset($this->available_approaches[$approach])) {
      throw new Exception("Unknown approach: $approach");
    }
    if (!isset($this->available_formats[$format])) {
      throw new Exception("Unknown format: $format");
    }

    // Load template
    $template_file = $this->template_dir . "/{$approach}-{$this->available_approaches[$approach]}.html";
    if (!file_exists($template_file)) {
      throw new Exception("Template not found: $template_file");
    }

    $html = file_get_contents($template_file);

    // Extract visual container for specific format
    $html = $this->extractFormat($html, $format);

    // Replace variables (approach-specific)
    $html = $this->replaceVariables($html, $data, $approach);

    return $html;
  }

  /**
   * Extract a specific format section from HTML
   *
   * @param string $html Raw HTML
   * @param string $format Target format
   * @return string Extracted HTML for format
   */
  private function extractFormat($html, $format) {
    // For now, return entire HTML
    // In production: extract only the format-specific div

    // Example: find <div class="format-a3"> and extract it
    // This depends on actual HTML structure

    return $html;
  }

  /**
   * Replace template variables with actual data
   *
   * @param string $html Template HTML
   * @param array $data Data to inject
   * @param string $approach Current approach
   * @return string Modified HTML
   */
  private function replaceVariables(&$html, $data, $approach) {
    // Universal replacements
    if (isset($data['title'])) {
      $html = $this->replaceInElement($html, 'h1', $data['title']);
      $html = str_replace('BEL APPARTEMENT LUMINEUX', $data['title'], $html);
      $html = str_replace('CONFORT, LUMIÈRE', $data['title'], $html);
      $html = str_replace('BEL APPARTEMENT', $data['title'], $html);
    }

    if (isset($data['subtitle'])) {
      $html = str_replace('3 pièces – 68 m² – Balcon', $data['subtitle'], $html);
      $html = str_replace('et emplacement idéal !', $data['subtitle'], $html);
      $html = str_replace('AU CŒUR DE LA VILLE', $data['subtitle'], $html);
    }

    if (isset($data['image_url'])) {
      // Replace placeholder image URLs
      $html = preg_replace(
        '/src="https:\/\/via\.placeholder\.com[^"]*"/',
        'src="' . htmlspecialchars($data['image_url']) . '"',
        $html,
        1
      );
    }

    if (isset($data['price'])) {
      $html = str_replace('265 000 € FAI', htmlspecialchars($data['price']), $html);
      $html = str_replace('265 000 €', htmlspecialchars($data['price']), $html);
      $html = str_replace('540 € CC / MOIS', htmlspecialchars($data['price']), $html);
      $html = str_replace('540 € CC', htmlspecialchars($data['price']), $html);
    }

    if (isset($data['logo_svg'])) {
      // Replace SVG logo
      $html = preg_replace(
        '/<svg[^>]*>.*?<\/svg>/s',
        $data['logo_svg'],
        $html,
        1
      );
    }

    // Approach-specific replacements
    switch ($approach) {
      case 'approche2':
        if (isset($data['main_text'])) {
          $html = str_replace('CONFORT, LUMIÈRE', htmlspecialchars($data['main_text']), $html);
        }
        if (isset($data['features']) && is_array($data['features'])) {
          $html = $this->replaceFeatures($html, $data['features']);
        }
        break;

      case 'approche3':
        if (isset($data['target'])) {
          // Add target class for styling
          $html = str_replace('approach-target', "approach-target target-{$data['target']}", $html);
        }
        if (isset($data['header_title'])) {
          $html = str_replace('Étudiant ?', htmlspecialchars($data['header_title']), $html);
        }
        break;

      case 'approche4':
        if (isset($data['gallery_images']) && is_array($data['gallery_images'])) {
          $html = $this->replaceGalleryImages($html, $data['gallery_images']);
        }
        break;
    }

    return $html;
  }

  /**
   * Replace feature list
   *
   * @param string $html Template
   * @param array $features Features with icon/title/desc
   * @return string Modified HTML
   */
  private function replaceFeatures($html, $features) {
    $feature_html = '';
    foreach ($features as $feature) {
      $feature_html .= '<div class="argument__feature">
        <div class="argument__feature-icon">' . htmlspecialchars($feature['icon']) . '</div>
        <div>
          <strong style="color: #1a3a4a; display: block; font-size: 0.95rem;">'
            . htmlspecialchars($feature['title']) .
          '</strong>
          <span class="argument__feature-text">'
            . htmlspecialchars($feature['desc']) .
          '</span>
        </div>
      </div>';
    }

    // Find and replace features container
    $html = preg_replace(
      '/<div class="argument__features">.*?<\/div>/s',
      '<div class="argument__features">' . $feature_html . '</div>',
      $html,
      1
    );

    return $html;
  }

  /**
   * Replace gallery images
   *
   * @param string $html Template
   * @param array $urls Image URLs
   * @return string Modified HTML
   */
  private function replaceGalleryImages($html, $urls) {
    $count = 0;
    $html = preg_replace_callback(
      '/<img[^>]*src="https:\/\/via\.placeholder\.com[^"]*"[^>]*class="multi__gallery-item[^"]*"[^>]*>/i',
      function ($matches) use ($urls, &$count) {
        if ($count < count($urls)) {
          return str_replace(
            $matches[0],
            preg_replace(
              '/src="[^"]*"/',
              'src="' . htmlspecialchars($urls[$count++]) . '"',
              $matches[0]
            ),
            $matches[0]
          );
        }
        return $matches[0];
      },
      $html
    );

    return $html;
  }

  /**
   * Export to image (requires external tool)
   *
   * @param string $html HTML content
   * @param string $format Output format ('png', 'jpg')
   * @param string $output_path Path to save
   * @return bool Success
   */
  public function exportImage($html, $format = 'png', $output_path = null) {
    // Implementation requires wkhtmltoimage, puppeteer, or cloud API
    // Example with wkhtmltoimage:

    /*
    $temp_html = tempnam(sys_get_temp_dir(), 'announce_') . '.html';
    file_put_contents($temp_html, $html);

    $output = $output_path ?? tempnam(sys_get_temp_dir(), 'announce_') . '.' . $format;

    $cmd = "wkhtmltoimage --quiet $temp_html $output";
    exec($cmd, $output_array, $return_code);

    unlink($temp_html);

    return $return_code === 0 ? $output : false;
    */

    throw new Exception('Image export requires external tool (wkhtmltoimage, puppeteer)');
  }

  /**
   * Batch render all formats for an announcement
   *
   * @param string $approach Approach type
   * @param array $data Announcement data
   * @return array HTML by format
   */
  public function renderAllFormats($approach, $data) {
    $results = [];
    foreach (array_keys($this->available_formats) as $format) {
      $results[$format] = $this->render($approach, $data, $format);
    }
    return $results;
  }

  /**
   * List available approaches
   *
   * @return array
   */
  public function getApproaches() {
    return $this->available_approaches;
  }

  /**
   * List available formats
   *
   * @return array
   */
  public function getFormats() {
    return $this->available_formats;
  }
}

// ============================================================
// USAGE EXAMPLES
// ============================================================

if (php_sapi_name() === 'cli' || isset($_GET['test'])) {

  $renderer = new AnnounceVisualRenderer();

  // Example 1: Render Approche 1 - Photo en Grand (A3)
  $data1 = [
    'title' => 'BEL APPARTEMENT LUMINEUX',
    'subtitle' => '3 pièces – 68 m² – Balcon – Centre-Ville',
    'image_url' => 'https://via.placeholder.com/1122x1200?text=Photo+Immersive',
    'price' => '265 000 € FAI',
    'logo_svg' => '<svg viewBox="0 0 60 60" fill="none"><rect x="5" y="5" width="50" height="50" stroke="white" stroke-width="2"/><path d="M20 35L30 20L40 35" stroke="white" stroke-width="2" fill="none"/></svg>',
  ];

  try {
    $html1 = $renderer->render('approche1', $data1, 'a3');
    echo "✓ Approche 1 (A3) rendered successfully\n";
    // file_put_contents('output_a3.html', $html1);
  } catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
  }

  // Example 2: Render Approche 2 - Argument en Avant (A4)
  $data2 = [
    'title' => 'CONFORT, LUMIÈRE',
    'subtitle' => 'et emplacement idéal !',
    'image_url' => 'https://via.placeholder.com/560x1587?text=Séjour',
    'price' => '265 000 € FAI',
    'price_details' => '3 pièces – 68 m² – Balcon',
    'main_text' => 'CONFORT, LUMIÈRE',
    'features' => [
      ['icon' => '☀️', 'title' => 'EXPOSITION SUD-OUEST', 'desc' => 'Lumineuse toute la journée'],
      ['icon' => '📍', 'title' => 'EMPLACEMENT RECHERCHE', 'desc' => 'À deux pas des commerces'],
      ['icon' => '🏠', 'title' => 'RESIDENCE RECENTE', 'desc' => 'Calme et bien entretenue'],
      ['icon' => '€', 'title' => 'FAIBLES CHARGES', 'desc' => 'Excellente performance énergétique'],
    ]
  ];

  try {
    $html2 = $renderer->render('approche2', $data2, 'a4');
    echo "✓ Approche 2 (A4) rendered successfully\n";
  } catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
  }

  // Example 3: List capabilities
  echo "\nAvailable approaches:\n";
  print_r($renderer->getApproaches());

  echo "\nAvailable formats:\n";
  print_r($renderer->getFormats());
}

// ============================================================
// WORDPRESS / CMS INTEGRATION HELPER
// ============================================================

/**
 * Helper function for integration
 */
function mbi_render_annonce_visual($approach, $data, $format = 'a3') {
  static $renderer;
  if (!$renderer) {
    $renderer = new AnnounceVisualRenderer();
  }
  return $renderer->render($approach, $data, $format);
}

// Usage in template:
// echo mbi_render_annonce_visual('approche1', $bien_data, 'a3');
