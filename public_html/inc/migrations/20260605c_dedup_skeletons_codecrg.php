<?php
/**
 * Migration LOCALE : suppression de 164 biens squelettes doublons (adresse+lot).
 * Squelette = reference_bien NULL, code_crg au format CODE_LOT, AUCUNE donnee.
 * Gardien = bien dont reference_bien = code-lot (underscore->tiret, lot padde) avec donnees CRG/prix : conserve.
 * CASCADE : baux (legacy), mandats (placeholders AUTO). Sans FK nettoye : biens_versions.
 * Sauvegarde: C:/tmp/dedup_skel_backup.json. LOCAL uniquement.
 */
return [
    'id' => '20260605c_dedup_skeletons_codecrg',
    'title' => 'Dedup biens squelettes code_crg (LOCAL) - 164 biens',
    'description' => 'Supprime 164 squelettes doublons (code_crg X_Y) dont le gardien CRG meme reference est conserve. Garde-fous anti-donnees.',
    'created_at' => '2026-06-05',
    'sql' => <<<SQL
-- 164 biens squelettes (code_crg X_Y) doublons d un gardien CRG de meme reference.
-- Sans donnee (prix/bail/CRG/annonce/locataire). Mandats AUTO et baux legacy partent en CASCADE.
DELETE bv FROM biens_versions bv JOIN biens b ON b.id=bv.id_bien WHERE b.id IN (632,633,634,635,636,637,638,639,640,641,642,643,644,645,646,647,648,649,650,651,652,653,654,655,656,657,658,659,660,661,662,663,664,665,666,667,668,669,670,671,672,673,674,675,676,677,678,679,680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,699,700,701,702,703,704,705,706,707,708,709,710,711,712,713,714,715,716,717,718,719,720,721,722,725,727,728,731,732,733,734,735,736,737,738,739,740,741,742,743,745,746,747,748,749,750,751,752,753,754,755,756,757,758,759,760,761,762,763,764,765,766,767,768,769,770,771,772,773,774,775,776,777,778,779,780,781,782,783,784,785,786,787,788,789,790,791,792,793,794,795,796,797,798,799,800,801,802)
   AND NOT EXISTS (SELECT 1 FROM bien_prix p WHERE p.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM bien_baux x WHERE x.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM crg_situations_locataires s WHERE s.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM annonces a WHERE a.id_bien=b.id)
   AND NOT EXISTS (SELECT 1 FROM locataires_statuts ls WHERE ls.id_bien=b.id);

DELETE FROM biens WHERE id IN (632,633,634,635,636,637,638,639,640,641,642,643,644,645,646,647,648,649,650,651,652,653,654,655,656,657,658,659,660,661,662,663,664,665,666,667,668,669,670,671,672,673,674,675,676,677,678,679,680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,699,700,701,702,703,704,705,706,707,708,709,710,711,712,713,714,715,716,717,718,719,720,721,722,725,727,728,731,732,733,734,735,736,737,738,739,740,741,742,743,745,746,747,748,749,750,751,752,753,754,755,756,757,758,759,760,761,762,763,764,765,766,767,768,769,770,771,772,773,774,775,776,777,778,779,780,781,782,783,784,785,786,787,788,789,790,791,792,793,794,795,796,797,798,799,800,801,802)
   AND id NOT IN (SELECT id_bien FROM bien_prix WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM bien_baux WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM crg_situations_locataires WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM annonces WHERE id_bien IS NOT NULL)
   AND id NOT IN (SELECT id_bien FROM locataires_statuts WHERE id_bien IS NOT NULL);

SQL,
];
