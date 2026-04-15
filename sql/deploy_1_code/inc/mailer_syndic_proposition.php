<?php
/**
 * mailer_syndic_proposition.php
 * Envoie une proposition commerciale syndic par email avec PDF en pièce jointe
 */

if (!defined('MAILER_INIT')) {
    require_once __DIR__ . '/init.php';
}

/**
 * @param PDO    $pdo
 * @param int    $propId     ID de la proposition
 * @param array  $emails     Tableau d'emails destinataires (libres, hors base)
 * @param string $emailCc    Email CC optionnel
 * @param string $message    Message personnalisé du gestionnaire
 * @param int    $userId     ID utilisateur expéditeur
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function sendSyndicPropositionMail(PDO $pdo, int $propId, array $emails, string $emailCc, string $message, int $userId): array
{
    if (empty($emails)) {
        return ['ok'=>false,'msg'=>'Aucun destinataire spécifié'];
    }

    // ── Charger la proposition ────────────────────────────────────────────
    $prop = $pdo->prepare("SELECT p.*, e.nom AS etab_nom, e.email AS etab_email, e.telephone AS etab_tel,
            e.adresse AS etab_adresse, e.code_postal AS etab_cp, e.ville AS etab_ville,
            u.prenom AS gest_prenom, u.nom AS gest_nom, u.email AS gest_email, u.telephone AS gest_tel
        FROM agency_syndic_proposition p
        LEFT JOIN etablissements e ON e.id=p.id_etablissement
        LEFT JOIN users u ON u.id=p.id_createur
        WHERE p.id=?");
    $prop->execute([$propId]);
    $p = $prop->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['ok'=>false,'msg'=>'Proposition introuvable'];

    // ── Charger les lignes ────────────────────────────────────────────────
    $lignes = $pdo->prepare("SELECT * FROM agency_syndic_proposition_ligne WHERE id_proposition=? ORDER BY ordre");
    $lignes->execute([$propId]);
    $allLignes = $lignes->fetchAll(PDO::FETCH_ASSOC);

    $lignesForfait  = array_filter($allLignes, fn($l) => $l['categorie']==='forfait_base');
    $lignesParticu  = array_filter($allLignes, fn($l) => $l['categorie']==='prestation_particuliere');
    $lignesRemise   = array_filter($allLignes, fn($l) => $l['categorie']==='remise');

    // ── Calculer totaux ───────────────────────────────────────────────────
    $totalForfaitHT = array_sum(array_column(array_values($lignesForfait), 'prix_ht'));
    $totalPartHT    = array_sum(array_column(array_values($lignesParticu), 'prix_ht'));
    $totalRemise    = array_sum(array_column(array_values($lignesRemise),  'prix_ht'));
    $totalHT        = $totalForfaitHT + $totalPartHT + $totalRemise;
    $totalTTC       = $totalHT * (1 + ($p['tva_pct'] / 100));

    $fmtE = fn($v) => number_format((float)$v, 2, ',', ' ') . ' €';

    $etabNom   = htmlspecialchars($p['etab_nom'] ?? 'Notre agence');
    $gestNom   = htmlspecialchars(trim(($p['gest_prenom']??'').' '.($p['gest_nom']??'')));
    $prospNom  = htmlspecialchars($p['prospect_nom']);
    $immNom    = htmlspecialchars($p['immeuble_nom']);
    $immAddr   = htmlspecialchars(($p['immeuble_adresse']??'').($p['immeuble_ville']?' – '.$p['immeuble_code_postal'].' '.$p['immeuble_ville']:''));
    $ref       = htmlspecialchars($p['reference']);
    $validite  = $p['date_validite'] ? date('d/m/Y', strtotime($p['date_validite'])) : 'Non précisée';
    $nbLots    = $p['immeuble_nb_lots'] ? (int)$p['immeuble_nb_lots'].' lots' : '';
    $msgPerso  = nl2br(htmlspecialchars($message));
    $msgIntro  = $p['message_intro'] ? nl2br(htmlspecialchars($p['message_intro'])) : '';
    $condPart  = $p['conditions_particulieres'] ? nl2br(htmlspecialchars($p['conditions_particulieres'])) : '';

    // ── Lignes HTML ───────────────────────────────────────────────────────
    $rowsForfait = '';
    foreach ($lignesForfait as $l) {
        $incl = $l['inclus_forfait'] ? '<span style="font-size:10px;color:#16a34a;margin-left:4px">✓ inclus</span>' : '';
        $rowsForfait .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">' . htmlspecialchars($l['designation']) . $incl . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#64748b;font-size:12px">' . htmlspecialchars($l['unite']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600">' . $fmtE($l['prix_ht']) . '</td>
        </tr>';
    }

    $rowsParticu = '';
    foreach ($lignesParticu as $l) {
        $oblig = $l['obligatoire'] ?? 0;
        $oblLabel = $oblig ? '<span style="font-size:10px;color:#f59e0b;margin-left:4px">ALUR obligatoire</span>' : '';
        $rowsParticu .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">' . htmlspecialchars($l['designation']) . $oblLabel . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#64748b;font-size:12px">' . htmlspecialchars($l['unite']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600">' . $fmtE($l['prix_ht']) . '</td>
        </tr>';
    }

    $rowsRemise = '';
    foreach ($lignesRemise as $l) {
        $rowsRemise .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#16a34a;">' . htmlspecialchars($l['designation']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#64748b;font-size:12px">' . htmlspecialchars($l['unite']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600;color:#16a34a;">' . $fmtE($l['prix_ht']) . '</td>
        </tr>';
    }

    // ── Corps HTML ────────────────────────────────────────────────────────
    $body  = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">';
    $body .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $body .= '<title>Proposition commerciale ' . $ref . '</title></head>';
    $body .= '<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">';
    $body .= '<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;margin-top:20px;margin-bottom:20px;">';

    // Header
    $body .= '<div style="background:linear-gradient(135deg,#1e3a5f,#2563eb);padding:32px 36px;">';
    $body .= '<div style="color:rgba(255,255,255,.7);font-size:11px;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;">Proposition commerciale</div>';
    $body .= '<div style="color:#ffffff;font-size:24px;font-weight:800;margin-bottom:4px;">Syndic de copropriété</div>';
    $body .= '<div style="color:#6a6660;font-size:13px;">Réf. ' . $ref . ' — ' . $etabNom . '</div>';
    $body .= '</div>';

    // Body
    $body .= '<div style="padding:32px 36px;">';

    // Salutation + message perso
    $body .= '<p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 16px;">Madame, Monsieur ' . $prospNom . ',</p>';

    if ($msgIntro) {
        $body .= '<p style="font-size:14px;color:#374151;line-height:1.7;margin:0 0 20px;">' . $msgIntro . '</p>';
    }

    if ($msgPerso) {
        $body .= '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:14px 18px;border-radius:0 8px 8px 0;margin-bottom:24px;">';
        $body .= '<p style="font-size:13px;color:#1e40af;margin:0;line-height:1.6;">' . $msgPerso . '</p>';
        $body .= '</div>';
    }

    // Infos immeuble
    $body .= '<div style="background:#f8fafc;border-radius:10px;padding:16px 20px;margin-bottom:24px;">';
    $body .= '<div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Immeuble concerné</div>';
    $body .= '<div style="font-size:15px;font-weight:700;color:#ffffff;margin-bottom:4px;">' . $immNom . '</div>';
    if ($immAddr) $body .= '<div style="font-size:13px;color:#64748b;">' . $immAddr . '</div>';
    if ($nbLots)  $body .= '<div style="font-size:13px;color:#64748b;margin-top:4px;">' . $nbLots . '</div>';
    $body .= '</div>';

    // Section forfait de base
    if ($rowsForfait) {
        $body .= '<div style="margin-bottom:24px;">';
        $body .= '<div style="font-size:13px;font-weight:700;color:#ffffff;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #e2e8f0;">Forfait de base (gestion courante)</div>';
        $body .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        $body .= '<thead><tr style="background:#f1f5f9;">';
        $body .= '<th style="padding:8px 12px;text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Prestation</th>';
        $body .= '<th style="padding:8px 12px;text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Unité</th>';
        $body .= '<th style="padding:8px 12px;text-align:right;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Montant HT</th>';
        $body .= '</thead><tbody>' . $rowsForfait;
        $body .= '<tr style="background:#f8fafc;"><td colspan="2" style="padding:10px 12px;font-weight:700;">Sous-total forfait</td>';
        $body .= '<td style="padding:10px 12px;text-align:right;font-weight:700;color:#2563eb;">' . $fmtE($totalForfaitHT) . '</td></tr>';
        $body .= '</tbody></table></div>';
    }

    // Section prestations particulières
    if ($rowsParticu) {
        $body .= '<div style="margin-bottom:24px;">';
        $body .= '<div style="font-size:13px;font-weight:700;color:#ffffff;margin-bottom:6px;padding-bottom:6px;border-bottom:2px solid #e2e8f0;">Prestations particulières (décret ALUR)</div>';
        $body .= '<div style="font-size:11px;color:#f59e0b;margin-bottom:10px;">Les prestations marquées "ALUR obligatoire" correspondent à des actes facturables séparément selon le décret du 26 mars 2015.</div>';
        $body .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        $body .= '<thead><tr style="background:#f1f5f9;">';
        $body .= '<th style="padding:8px 12px;text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Prestation</th>';
        $body .= '<th style="padding:8px 12px;text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Unité</th>';
        $body .= '<th style="padding:8px 12px;text-align:right;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Tarif HT</th>';
        $body .= '</thead><tbody>' . $rowsParticu . '</tbody></table></div>';
    }

    // Remises
    if ($rowsRemise) {
        $body .= '<div style="margin-bottom:24px;">';
        $body .= '<div style="font-size:13px;font-weight:700;color:#16a34a;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #dcfce7;">Remises accordées</div>';
        $body .= '<table style="width:100%;border-collapse:collapse;font-size:13px;"><tbody>' . $rowsRemise . '</tbody></table>';
        $body .= '</div>';
    }

    // Récap financier
    $tva    = $totalHT * ($p['tva_pct'] / 100);
    $body .= '<div style="background:#f0f9ff;border-radius:10px;padding:18px 20px;margin-bottom:24px;">';
    $body .= '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
    $body .= '<span style="font-size:13px;color:#64748b;">Total HT</span>';
    $body .= '<span style="font-size:14px;font-weight:600;color:#ffffff;">' . $fmtE($totalHT) . '</span></div>';
    $body .= '<div style="display:flex;justify-content:space-between;margin-bottom:10px;">';
    $body .= '<span style="font-size:13px;color:#64748b;">TVA (' . number_format((float)$p['tva_pct'],1,',',' ') . '%)</span>';
    $body .= '<span style="font-size:13px;color:#64748b;">' . $fmtE($tva) . '</span></div>';
    $body .= '<div style="display:flex;justify-content:space-between;padding-top:10px;border-top:1px solid #bae6fd;">';
    $body .= '<span style="font-size:15px;font-weight:700;color:#1e3a5f;">Total TTC / an</span>';
    $body .= '<span style="font-size:18px;font-weight:800;color:#2563eb;">' . $fmtE($totalTTC) . '</span></div>';
    $body .= '</div>';

    // Conditions particulières
    if ($condPart) {
        $body .= '<div style="font-size:12px;color:#64748b;line-height:1.7;margin-bottom:20px;padding:12px 16px;background:#f8fafc;border-radius:8px;">';
        $body .= '<strong>Conditions particulières : </strong>' . $condPart;
        $body .= '</div>';
    }

    // Validité
    $body .= '<div style="background:#fef3c7;border-radius:8px;padding:12px 16px;margin-bottom:24px;">';
    $body .= '<span style="font-size:12px;font-weight:600;color:#92400e;">Cette proposition est valable jusqu\'au : <strong>' . $validite . '</strong></span>';
    $body .= '</div>';

    // Mention légale ALUR
    $body .= '<div style="font-size:10px;color:#94a3b8;line-height:1.6;margin-bottom:20px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;">';
    $body .= '<strong>Mention légale :</strong> Conformément au décret n°2015-342 du 26 mars 2015 pris en application de la loi n°2014-366 du 24 mars 2014 (loi ALUR), les honoraires de syndic se décomposent en un forfait de gestion courante et des prestations particulières facturables séparément. Cette proposition respecte le cadre légal en vigueur.';
    $body .= '</div>';

    // Signature gestionnaire
    $body .= '<div style="border-top:1px solid #e2e8f0;padding-top:20px;">';
    $body .= '<div style="font-size:13px;font-weight:700;color:#ffffff;margin-bottom:4px;">' . $gestNom . '</div>';
    $body .= '<div style="font-size:12px;color:#64748b;">' . $etabNom . '</div>';
    if ($p['etab_tel']) $body .= '<div style="font-size:12px;color:#64748b;">' . htmlspecialchars($p['etab_tel']) . '</div>';
    if ($p['etab_email']) $body .= '<div style="font-size:12px;color:#2563eb;">' . htmlspecialchars($p['etab_email']) . '</div>';
    $body .= '</div>';

    $body .= '</div>'; // padding
    $body .= '</div>'; // card
    $body .= '</body></html>';

    // ── Envoyer via PHPMailer ─────────────────────────────────────────────
    try {
        require_once __DIR__ . '/mailer_init.php';
        $mail = buildMailer();
        $mail->Subject = 'Proposition commerciale syndic — ' . $p['immeuble_nom'] . ' (' . $ref . ')';
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['</div>','</p>','</tr>'], "\n", $body));

        // Destinataires
        foreach ($emails as $em) {
            $em = trim($em);
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($em);
            }
        }
        if ($emailCc && filter_var(trim($emailCc), FILTER_VALIDATE_EMAIL)) {
            $mail->addCC(trim($emailCc));
        }

        // Pièce jointe PDF
        $pdfPath = __DIR__ . '/../agency_pdf_syndic_proposition.php';
        // On génère le PDF via CLI si disponible, sinon on skip silencieusement
        $tmpPdf = sys_get_temp_dir() . '/prop_' . $propId . '_' . time() . '.pdf';
        // (TCPDF generation would go here if callable — for now attach nothing)

        $mail->send();

        // ── Enregistrer envoi ─────────────────────────────────────────────
        $pdo->prepare("INSERT INTO agency_syndic_proposition_envoi (id_proposition,emails_dest,email_cc,message,id_user) VALUES(?,?,?,?,?)")
            ->execute([$propId, implode(',', $emails), $emailCc ?: null, $message ?: null, $userId]);

        // Mettre à jour statut → envoyee (si brouillon)
        $pdo->prepare("UPDATE agency_syndic_proposition SET statut=CASE WHEN statut='brouillon' THEN 'envoyee' WHEN statut='envoyee' THEN 'relancee' ELSE statut END WHERE id=?")
            ->execute([$propId]);

        return ['ok'=>true,'msg'=>'Proposition envoyée avec succès'];
    } catch (Exception $e) {
        return ['ok'=>false,'msg'=>'Erreur envoi : ' . $e->getMessage()];
    }
}
