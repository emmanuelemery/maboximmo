<?php
/**
 * Migration : seed du barème d'honoraires (table societe_honoraires) pour
 * Régie EMERY (id_societe=1) et EMERY IMMOBILIER (id_societe=2).
 *
 * Alimente la page barème pilotée par la BDD (tarifs_societe.php).
 * NB : la page publique principale liée aux annonces (tarifs.php) est en dur
 *      et n'a PAS besoin de cette table — ce seed ne sert qu'au barème société.
 *
 * Idempotent : INSERT ... ON DUPLICATE KEY UPDATE (clé sur id_societe).
 * Si pas de clé unique sur id_societe, ne pas rejouer (créerait des doublons).
 */
return [
    'id'          => '20260617_seed_societe_honoraires',
    'title'       => 'Barème honoraires : seed societe_honoraires (Régie Emery + Emery Immo)',
    'description' => "Remplit vente (tranches), gestion locative (paliers en texte) et location ALUR 2026 (3 zones en texte) pour id_societe 1 et 2.",
    'created_at'  => '2026-06-17',
    'sql' => <<<'SQL'
INSERT INTO societe_honoraires
  (id_societe, vente_methode, vente_tranches_json, vente_charge_par_defaut,
   location_honoraires_etat_des_lieux_m2, gestion_prestations_html, contenu_html,
   date_mise_a_jour, date_creation)
VALUES
  (1, 'tranches',
   '[{"min":0,"max":60000,"pct":8},{"min":60000,"max":100000,"pct":7},{"min":100000,"max":200000,"pct":6},{"min":200000,"max":350000,"pct":5.5},{"min":350000,"max":null,"pct":5}]',
   'acquereur', 3.03,
   'Honoraires de gestion locative — % TTC du loyer annuel :\n• Jusqu''à 5 000 € : 8 % TTC\n• De 5 001 à 12 500 € : 7,5 % TTC\n• De 12 501 à 24 000 € : 7 % TTC\n• Au-delà de 24 000 € : 6 % TTC',
   'Honoraires de LOCATION à la charge du locataire — plafonds loi ALUR (baux signés à compter du 01/01/2026), € TTC / m² de surface habitable :\n• Zone très tendue : 12,10 €/m²\n• Zone tendue : 10,09 €/m²\n• Zone non tendue (ailleurs) : 8,07 €/m²\n• État des lieux (toutes zones) : 3,03 €/m²\nLa part du locataire ne peut excéder celle du bailleur.',
   NOW(), NOW()),
  (2, 'tranches',
   '[{"min":0,"max":60000,"pct":8},{"min":60000,"max":100000,"pct":7},{"min":100000,"max":200000,"pct":6},{"min":200000,"max":350000,"pct":5.5},{"min":350000,"max":null,"pct":5}]',
   'acquereur', 3.03,
   'Honoraires de gestion locative — % TTC du loyer annuel :\n• Jusqu''à 5 000 € : 8 % TTC\n• De 5 001 à 12 500 € : 7,5 % TTC\n• De 12 501 à 24 000 € : 7 % TTC\n• Au-delà de 24 000 € : 6 % TTC',
   'Honoraires de LOCATION à la charge du locataire — plafonds loi ALUR (baux signés à compter du 01/01/2026), € TTC / m² de surface habitable :\n• Zone très tendue : 12,10 €/m²\n• Zone tendue : 10,09 €/m²\n• Zone non tendue (ailleurs) : 8,07 €/m²\n• État des lieux (toutes zones) : 3,03 €/m²\nLa part du locataire ne peut excéder celle du bailleur.',
   NOW(), NOW())
ON DUPLICATE KEY UPDATE
  vente_methode=VALUES(vente_methode), vente_tranches_json=VALUES(vente_tranches_json),
  vente_charge_par_defaut=VALUES(vente_charge_par_defaut),
  location_honoraires_etat_des_lieux_m2=VALUES(location_honoraires_etat_des_lieux_m2),
  gestion_prestations_html=VALUES(gestion_prestations_html),
  contenu_html=VALUES(contenu_html), date_mise_a_jour=NOW();
SQL
];
