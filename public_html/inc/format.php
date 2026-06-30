<?php
/**
 * inc/format.php — Helpers d'affichage CENTRALISÉS (montants, surfaces).
 * Source de vérité unique : ne jamais reformater un montant "à la main".
 * Chargé par inc/bootstrap.php → disponible partout.
 *
 * Convention montants MaBoxImmo : séparateur de milliers "." et "€" après.
 *   fmt_euro(21410)        => "21.410 €"
 *   fmt_euro(1234.5, 2)    => "1.234,50 €"
 *   fmt_euro_dash(0)       => "—"   (tiret si 0 / vide)
 *   fmt_m2(63.89)          => "63,89 m²"
 */
declare(strict_types=1);

if (!function_exists('fmt_euro')) {
    function fmt_euro($v, int $dec = 0): string {
        return number_format((float)$v, $dec, ',', '.') . ' €';
    }
}

if (!function_exists('fmt_euro_dash')) {
    function fmt_euro_dash($v, int $dec = 0): string {
        return ((float)$v) > 0 ? fmt_euro($v, $dec) : '—';
    }
}

if (!function_exists('fmt_m2')) {
    function fmt_m2($v, int $dec = 2): string {
        return number_format((float)$v, $dec, ',', ' ') . ' m²';
    }
}
