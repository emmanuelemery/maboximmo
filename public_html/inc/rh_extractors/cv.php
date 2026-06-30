<?php
declare(strict_types=1);
/**
 * inc/rh_extractors/cv.php — Extracteur CV (curriculum vitae).
 *
 * Le CV n'alimente AUCUN champ du profil users (pas de mapping dans
 * rhDxFieldMapping). Cet extracteur sert juste à valider la reconnaissance
 * du type pour que le pipeline retourne success=true (le doc est ensuite
 * chargeable comme document RH "CV").
 */
function rhExtract_cv(string $text, array $visionImages, string $filePath): array
{
    return [
        'fields'      => [],
        'validations' => [],
        'score'       => 60,
        'engine'      => $text !== '' ? 'regex' : 'vision',
    ];
}
