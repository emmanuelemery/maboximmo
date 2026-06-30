<?php
/**
 * Migration : logos EMERY IMMOBILIER (succ. SERVAJEAN) — société + agences.
 *
 * Renseigne le logo PNG couleurs de la société EMERY IMMOBILIER (id=2) et de
 * ses deux agences (RIOM id=5, CHAMALIERES id=6). Consommé par les vitrines
 * (vitrine_header.php / net_context.php) et le SEO (seo_jsonld.php),
 * avec fallback agence -> société.
 *
 * Fichiers requis (présents côté prod ET local), renommés en minuscules sans espace :
 *   images/logos/logo-emery-servjean.png             (société)
 *   images/logos/logo-emery-servjean-chamalieres.png (agence Chamalières)
 *   images/logos/logo-emery-servjean-riom.png        (agence Riom)
 *
 * NB : on ÉCRASE volontairement societes(id=2).logo_url (emery-immo.jpg) car
 * le PNG couleurs SERVAJEAN devient le logo officiel de la société.
 */
return [
    'id'          => '20260626_emery_servjean_logos',
    'title'       => 'Logos EMERY IMMOBILIER / SERVAJEAN (société + agences)',
    'description' => 'Renseigne logo_url société 2 + agences 5 (RIOM) et 6 (CHAMALIERES).',
    'created_at'  => '2026-06-26',
    'sql' => <<<'SQL'
UPDATE `societes` SET `logo_url` = 'images/logos/logo-emery-servjean.png'
  WHERE `id` = 2;

UPDATE `agences` SET `logo_url` = 'images/logos/logo-emery-servjean-chamalieres.png'
  WHERE `id` = 6;

UPDATE `agences` SET `logo_url` = 'images/logos/logo-emery-servjean-riom.png'
  WHERE `id` = 5;
SQL,
];
