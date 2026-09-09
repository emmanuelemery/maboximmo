<?php
declare(strict_types=1);
/**
 * crgv2_norm.php — LA NORMALISATION, ÉCRITE UNE SEULE FOIS.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QU'UNE COMPARAISON D'IDENTITÉ A DEUX CÔTÉS, ET QU'ILS DOIVENT
 *    PASSER PAR LE MÊME CODE.
 *
 *    Le plan fabrique une clé à partir de ce qu'il a lu dans le CRG ; l'écrivain refabrique
 *    la même clé à partir de ce qu'il relit dans la base, pour savoir si la personne existe
 *    déjà. Si les deux normalisations divergent d'un point ou d'une apostrophe, le
 *    rapprochement échoue — sans exception, sans lenteur, sans ligne de journal. Il crée
 *    simplement des doublons, et on ne s'en aperçoit qu'en comptant les objets.
 *
 *    C'est arrivé : 1 808 tiers créés sur une base qui en portait 961, parce qu'un côté
 *    normalisait par `crgi_plat()` et l'autre par un `UPPER(TRIM())` SQL.
 *
 *    Deux copies du même code sont deux normalisations qui vont diverger. D'où ce fichier.
 */

/** La forme sur laquelle on COMPARE deux identités — jamais celle qu'on stocke. */
function plat(?string $s): string
{
    $s = strtr((string)$s, [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','à'=>'A','á'=>'A','â'=>'A',
        'ã'=>'A','ä'=>'A','å'=>'A','Ç'=>'C','ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'è'=>'E','é'=>'E','ê'=>'E','ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','ì'=>'I',
        'í'=>'I','î'=>'I','ï'=>'I','Ñ'=>'N','ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O',
        'Ö'=>'O','ò'=>'O','ó'=>'O','ô'=>'O','õ'=>'O','ö'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U',
        'Ü'=>'U','ù'=>'U','ú'=>'U','û'=>'U','ü'=>'U','Ý'=>'Y','ý'=>'Y','ÿ'=>'Y','Œ'=>'OE',
        'œ'=>'OE','Æ'=>'AE','æ'=>'AE',
    ]);
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

/**
 * La clé d'un tiers, à partir de son identité DÉJÀ ANALYSÉE.
 *
 * ⚠️ JAMAIS À PARTIR DE LA CHAÎNE BRUTE. La base ne stocke pas « Monsieur XERRI Florent » :
 *    elle stocke nom=XERRI, prenoms=Florent. Une clé prise sur le brut ne pourrait jamais
 *    être retrouvée en relisant la base.
 */
function cleTiers(?string $type, ?string $denomination, ?string $nom, ?string $prenoms): string
{
    return $type === 'MORALE'
         ? plat($denomination)
         : plat(trim((string)$nom . ' ' . (string)$prenoms));
}

/**
 * La clé d'un immeuble QUI N'A PAS DE CODE — son adresse, à défaut son nom.
 * 49 bâtiments du corpus sont dans ce cas : ils ont une rue, pas de numéro d'éditeur.
 */
function cleImmeubleSansCode(int $agence, ?string $adresse, ?string $cp, ?string $ville, ?string $nom): string
{
    $a = plat($adresse);
    if ($a !== '') return 'A:' . $agence . '|' . $a . '|' . trim((string)$cp) . '|' . plat($ville);
    return 'N:' . $agence . '|' . plat($nom);
}
