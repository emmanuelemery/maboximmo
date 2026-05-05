<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * scripts/agence_docs_alertes.php — Cron quotidien alertes 120/90/60 j
 * ═══════════════════════════════════════════════════════════════════════
 *
 * À planifier 1×/jour (proposition : 7h00 du matin).
 *
 * Pour chaque doc officiel `actif` dont la `date_validite` est à
 * exactement 120, 90 ou 60 jours du jour courant, et dont l'alerte
 * correspondante n'a pas encore été envoyée → envoie un email à
 * l'agence + tous les users id_role IN (1,2) de l'agence, et marque
 * le timestamp `alerte_Xj_envoyee_at = NOW()` (idempotent).
 *
 * Usage :
 *   php scripts/agence_docs_alertes.php
 *   php scripts/agence_docs_alertes.php --dry-run    (n'envoie pas, log seul)
 *   php scripts/agence_docs_alertes.php --force-all  (réenvoie même si déjà envoyé)
 *
 * Sortie : log textuel + code retour 0 si OK, 1 si erreur.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/mailer.php';

$opts = getopt('', ['dry-run', 'force-all']);
$dryRun = array_key_exists('dry-run', $opts);
$force  = array_key_exists('force-all', $opts);

$pdo = db();
$today = new DateTimeImmutable('today');

echo '[' . date('Y-m-d H:i:s') . '] cron agence_docs_alertes — ';
echo $dryRun ? 'DRY-RUN' : 'NORMAL';
echo $force  ? ' --force-all' : '';
echo "\n";

$labelsType = [
    'carte_pro'         => 'Carte professionnelle',
    'kbis'              => 'KBIS',
    'garant_financier'  => 'Garant financier',
    'rc_pro'            => 'Assurance RC pro',
    'bareme_honoraires' => 'Barème honoraires',
];

$nbCheck = 0;
$nbAlertes = 0;
$nbErreurs = 0;

// On vise les docs actifs avec date_validite renseignée
try {
    $st = $pdo->query("
        SELECT d.*, a.nom_agence, a.email AS email_agence, a.id_societe
        FROM agences_documents_officiels d
        JOIN agences a ON a.id = d.id_agence
        WHERE d.statut = 'actif' AND d.date_validite IS NOT NULL
    ");
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    fwrite(STDERR, '[ERREUR] SELECT docs : ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($docs as $d) {
    $nbCheck++;
    try {
        $dateVal = new DateTimeImmutable((string)$d['date_validite']);
    } catch (Throwable) { continue; }

    $jours = (int)$today->diff($dateVal)->format('%r%a');

    // Quel seuil est franchi aujourd'hui ?
    $seuils = [];
    if ($jours === 120) $seuils[] = 120;
    if ($jours ===  90) $seuils[] =  90;
    if ($jours ===  60) $seuils[] =  60;
    if (empty($seuils)) continue;

    foreach ($seuils as $j) {
        $col = "alerte_{$j}j_envoyee_at";
        if (!$force && !empty($d[$col])) {
            echo "  - doc#{$d['id']} type={$d['type_document']} J-{$j} déjà envoyé\n";
            continue;
        }

        // Charge les destinataires : email agence + users role 1-2 de l'agence
        $destinataires = [];
        $emailAgence = trim((string)($d['email_agence'] ?? ''));
        if ($emailAgence !== '' && filter_var($emailAgence, FILTER_VALIDATE_EMAIL)) {
            $destinataires[] = $emailAgence;
        }
        try {
            $stU = $pdo->prepare("SELECT email FROM users
                                  WHERE id_agence = :a AND id_role IN (1,2)
                                    AND email IS NOT NULL AND email != ''
                                    AND (actif = 1 OR actif IS NULL)");
            $stU->execute([':a' => (int)$d['id_agence']]);
            foreach ($stU->fetchAll(PDO::FETCH_COLUMN) ?: [] as $em) {
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) $destinataires[] = $em;
            }
        } catch (Throwable) {}
        $destinataires = array_values(array_unique($destinataires));

        if (empty($destinataires)) {
            echo "  - doc#{$d['id']} J-{$j} : aucun destinataire\n";
            continue;
        }

        $libType = $labelsType[$d['type_document']] ?? $d['type_document'];
        $sujet = "[Ma Box Immo] Renouvellement {$libType} — {$d['nom_agence']} — J-{$j}";
        $msg = sprintf(
            "Bonjour,\n\n"
          . "Le document « %s » de l'agence %s arrivera à expiration dans %d jours (le %s).\n\n"
          . "Numéro : %s\n"
          . "Émetteur : %s\n\n"
          . "Merci de préparer le renouvellement et de redéposer le nouveau document sur la page Documents officiels :\n"
          . "  Espace agence → Documents officiels\n\n"
          . "Le retard peut bloquer la diffusion des annonces (mention obligatoire loi Hoguet).\n\n"
          . "—\nMa Box Immo",
            $libType,
            $d['nom_agence'] ?: 'votre agence',
            $j,
            $d['date_validite'],
            $d['numero']   ?: '—',
            $d['emetteur'] ?: '—'
        );

        $to = array_shift($destinataires);
        $cc = implode(',', $destinataires);

        if ($dryRun) {
            echo "  [DRY] J-{$j} → {$to} (cc=" . ($cc ?: 'aucun') . ") : {$sujet}\n";
            continue;
        }

        $envoye = false;
        try {
            $envoye = function_exists('send_mail')
                ? send_mail($to, $sujet, $msg, [], false, $cc)
                : false;
        } catch (Throwable $e) {
            error_log('[agence_docs_alertes send] ' . $e->getMessage());
        }

        if ($envoye) {
            try {
                $up = $pdo->prepare("UPDATE agences_documents_officiels
                                     SET {$col} = NOW() WHERE id = :id");
                $up->execute([':id' => (int)$d['id']]);
                $nbAlertes++;
                echo "  ✓ doc#{$d['id']} J-{$j} envoyé à {$to}\n";
            } catch (Throwable $e) {
                $nbErreurs++;
                echo "  ✗ doc#{$d['id']} J-{$j} envoyé mais UPDATE KO : " . $e->getMessage() . "\n";
            }
        } else {
            $nbErreurs++;
            echo "  ✗ doc#{$d['id']} J-{$j} échec envoi\n";
        }
    }
}

echo "\n[" . date('H:i:s') . "] Bilan : {$nbCheck} docs vérifiés, {$nbAlertes} alertes envoyées, {$nbErreurs} erreurs.\n";
exit($nbErreurs > 0 ? 1 : 0);
