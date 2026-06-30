<?php
declare(strict_types=1);
/**
 * inc/rh_extractors/lettre_motivation.php — Extracteur lettre de motivation.
 *
 * Aucun champ profil alimenté. Sert à valider la reconnaissance du type
 * pour que le document soit chargeable comme "Lettre de motivation".
 */
function rhExtract_lettre_motivation(string $text, array $visionImages, string $filePath): array
{
    return [
        'fields'      => [],
        'validations' => [],
        'score'       => 60,
        'engine'      => $text !== '' ? 'regex' : 'vision',
    ];
}
