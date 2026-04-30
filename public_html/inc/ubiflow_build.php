<?php
declare(strict_types=1);

/**
 * ubiflow_build.php — Helper de construction du flux XML Ubiflow
 *
 * Extrait depuis api/ubiflow_trigger.php pour être réutilisable par :
 *   - api/ubiflow_trigger.php (batch manuel depuis le dashboard diffusion)
 *   - api/annonce_diffuser.php (diffusion d'une annonce unique V2)
 *
 * Expose :
 *   ubiflow_build_flux_xml(PDO $pdo, int $idAgence): array
 *     → ['xml' => string, 'count' => int, 'skipped' => int]
 */

require_once __DIR__ . '/../config/ubiflow_mapping.php';

if (!function_exists('ubiflow_build_flux_xml')) {
    function ubiflow_build_flux_xml(PDO $pdo, int $idAgence): array
    {
        $sql  = ubiflow_sql_select_annonces($idAgence);
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;
        $clientNode = $dom->createElement('client');
        $dom->appendChild($clientNode);

        $appendValue = static function (DOMDocument $dom, DOMElement $parent, string $name, $value): void {
            if ($value === null || $value === '') return;
            $s = (string)$value;
            $child = $dom->createElement($name);
            if (preg_match('/[<>&\r\n"\']/', $s)) {
                $child->appendChild($dom->createCDATASection($s));
            } else {
                $child->appendChild($dom->createTextNode($s));
            }
            $parent->appendChild($child);
        };
        $appendGroup = static function (DOMDocument $dom, DOMElement $parent, string $groupName, array $data) use ($appendValue): ?DOMElement {
            if (empty($data)) return null;
            $group = $dom->createElement($groupName);
            foreach ($data as $key => $value) {
                $appendValue($dom, $group, (string)$key, $value);
            }
            $parent->appendChild($group);
            return $group;
        };

        $count = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $idAnnonce = (int)($row['a_id'] ?? 0);
            if ($idAnnonce <= 0) continue;
            $photos = ubiflow_get_photos($pdo, $idAnnonce);
            $data   = build_ubiflow_annonce($row, $photos);
            if (!empty($data['_skipped'])) { $skipped++; continue; }

            $annonceNode = $dom->createElement('annonce');
            foreach ($data['annonce'] as $key => $value) {
                $appendValue($dom, $annonceNode, (string)$key, $value);
            }
            if (!empty($data['photos'])) {
                $photosNode = $dom->createElement('photos');
                foreach ($data['photos'] as $url) {
                    $appendValue($dom, $photosNode, 'photo', $url);
                }
                $annonceNode->appendChild($photosNode);
            }
            $bienNode = $appendGroup($dom, $annonceNode, 'bien', $data['bien']);
            if ($bienNode !== null && !empty($data['diagnostiques'])) {
                $appendGroup($dom, $bienNode, 'diagnostiques', $data['diagnostiques']);
            }
            $appendGroup($dom, $annonceNode, 'prestation', $data['prestation']);
            // Bloc <contact> : négociateur attribué (mobile + fixe + email)
            // → repris par LBC pour l'affichage du contact sur la page annonce
            if (!empty($data['contact'])) {
                $appendGroup($dom, $annonceNode, 'contact', $data['contact']);
            }
            $clientNode->appendChild($annonceNode);
            $count++;
        }

        $xml = $dom->saveXML();
        $xml = preg_replace(
            '/^<\?xml version="1\.0" encoding="UTF-8"\?>/',
            '<?xml version="1.0" encoding="utf-8"?>',
            $xml,
            1
        );

        return ['xml' => (string)$xml, 'count' => $count, 'skipped' => $skipped];
    }
}
