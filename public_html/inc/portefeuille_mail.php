<?php
// inc/portefeuille_mail.php — Corps HTML du mail d'envoi d'un portefeuille (confidentialité + CTA).
// Partagé par api/portefeuille_envoyer.php et les scripts de test.
declare(strict_types=1);

if (!function_exists('pf_envoi_email_html')) {
    /** Corps HTML du mail d'envoi (texte de confidentialité + CTA vers le lien personnel). */
    function pf_envoi_email_html(string $url, string $civilite = 'Madame, Monsieur', string $nomPf = ''): string
    {
        $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#243;line-height:1.6;max-width:640px;margin:auto;">'
          . '<p>' . $civilite . ',</p>'
          . '<p>Nous vous remercions de l\'intérêt que vous portez aux opportunités immobilières qui vous sont présentées.</p>'
          . '<p>Le portefeuille auquel vous accédez a été constitué spécifiquement à votre intention, en fonction des critères et informations que vous nous avez communiqués. Les biens, documents, données financières, estimations, analyses, diagnostics, photographies et informations associées sont communiqués à titre strictement confidentiel.</p>'
          . '<p>En accédant à ce portefeuille, vous reconnaissez que :</p>'
          . '<ul>'
          . '<li>Les informations communiquées sont destinées à votre seule étude personnelle ou à celle de votre structure directement concernée par le projet d\'acquisition ;</li>'
          . '<li>Vous vous engagez à ne pas diffuser, reproduire, transférer ou communiquer tout ou partie des informations, documents ou accès qui vous sont transmis à des tiers sans l\'accord préalable et écrit de notre société ;</li>'
          . '<li>Les identifiants, liens d\'accès et documents mis à votre disposition sont strictement personnels et ne peuvent être partagés ;</li>'
          . '<li>Les informations contenues dans ce portefeuille ne constituent pas une offre ferme de vente et peuvent être modifiées, complétées, retirées ou mises à jour à tout moment ;</li>'
          . '<li>La disponibilité des biens présentés ne peut être garantie tant qu\'un engagement contractuel n\'a pas été signé par les parties concernées.</li>'
          . '</ul>'
          . '<p>Toute utilisation, diffusion ou exploitation non autorisée des informations communiquées pourra entraîner la suppression immédiate des accès accordés et, le cas échéant, engager la responsabilité de son auteur.</p>'
          . '<p>Si vous souhaitez présenter une ou plusieurs opportunités à des partenaires, associés, investisseurs ou conseils intervenant directement dans votre projet, nous vous invitons à nous en informer afin que nous puissions organiser un accès dédié et sécurisé.</p>'
          . '<p>Nous restons naturellement à votre entière disposition pour tout complément d\'information, visite, transmission de documents supplémentaires ou étude d\'une offre portant sur tout ou partie des actifs présentés.</p>'
          . '<p>Nous vous remercions de votre confiance et vous souhaitons une excellente consultation de votre portefeuille.</p>'
          . '<p style="text-align:center;margin:28px 0;"><a href="' . $u . '" style="background:#1f5fc0;color:#fff;text-decoration:none;font-weight:bold;padding:14px 26px;border-radius:10px;display:inline-block;">Consulter mon portefeuille</a></p>'
          . '<p style="font-size:12px;color:#789;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>' . $u . '</p>'
          . '<p>Bien cordialement,<br><b>Régie EMERY</b><br>Location – Gestion – Syndic – Transaction</p>'
          . '</div>';
    }
}
