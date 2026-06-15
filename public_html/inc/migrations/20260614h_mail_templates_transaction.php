<?php
/**
 * Migration : modèles de mail "Transaction" (mail_templates).
 *
 * Seede 4 mails types réutilisés par la composition mail du dossier de vente
 * (transaction_mail.php). Placeholders : {{proprietaire}} {{bien}} {{adresse}}.
 * Idempotent : INSERT conditionnel sur le titre (pas de doublon au rejeu).
 */
return [
    'id'          => '20260614h_mail_templates_transaction',
    'title'       => 'Modèles de mail Transaction',
    'description' => "Crée mail_templates si absente et seede 4 modèles catégorie 'Transaction' (avis de valeur, mandat, offre, acte). Idempotent.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS mail_templates (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(100) NOT NULL,
    categorie VARCHAR(50) NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    corps LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categorie (categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mail_templates (titre, categorie, sujet, corps)
SELECT * FROM (SELECT
  'Envoi avis de valeur' AS titre, 'Transaction' AS categorie,
  'Votre avis de valeur — {{bien}}' AS sujet,
  'Bonjour {{proprietaire}},\n\nVeuillez trouver ci-joint l''avis de valeur établi pour votre bien {{bien}} situé {{adresse}}.\n\nNous restons à votre disposition pour en échanger.\n\nCordialement,\nL''équipe MaBoxImmo' AS corps
) t WHERE NOT EXISTS (SELECT 1 FROM mail_templates m WHERE m.titre = 'Envoi avis de valeur');

INSERT INTO mail_templates (titre, categorie, sujet, corps)
SELECT * FROM (SELECT
  'Envoi mandat de vente', 'Transaction',
  'Mandat de vente à signer — {{bien}}',
  'Bonjour {{proprietaire}},\n\nVeuillez trouver ci-joint le mandat de vente concernant le bien {{bien}} ({{adresse}}).\n\nMerci de le retourner signé.\n\nCordialement,\nL''équipe MaBoxImmo'
) t WHERE NOT EXISTS (SELECT 1 FROM mail_templates m WHERE m.titre = 'Envoi mandat de vente');

INSERT INTO mail_templates (titre, categorie, sujet, corps)
SELECT * FROM (SELECT
  'Transmission offre', 'Transaction',
  'Offre d''achat — {{bien}}',
  'Bonjour {{proprietaire}},\n\nNous avons le plaisir de vous transmettre une offre d''achat concernant votre bien {{bien}}.\n\nVous trouverez le détail en pièce jointe.\n\nCordialement,\nL''équipe MaBoxImmo'
) t WHERE NOT EXISTS (SELECT 1 FROM mail_templates m WHERE m.titre = 'Transmission offre');

INSERT INTO mail_templates (titre, categorie, sujet, corps)
SELECT * FROM (SELECT
  'Transmission acte / compromis', 'Transaction',
  'Acte / compromis — {{bien}}',
  'Bonjour,\n\nVeuillez trouver ci-joint les éléments relatifs à la vente du bien {{bien}} ({{adresse}}).\n\nRestant à votre disposition.\n\nCordialement,\nL''équipe MaBoxImmo'
) t WHERE NOT EXISTS (SELECT 1 FROM mail_templates m WHERE m.titre = 'Transmission acte / compromis');
SQL
    ,
    'down' => <<<'SQL'
DELETE FROM mail_templates WHERE categorie = 'Transaction'
  AND titre IN ('Envoi avis de valeur','Envoi mandat de vente','Transmission offre','Transmission acte / compromis');
SQL
];
