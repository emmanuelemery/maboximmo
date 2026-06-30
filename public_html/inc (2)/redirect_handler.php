<?php
/**
 * Redirect Handler - Gestion des redirections 301/302/410
 *
 * Doit être appelé au tout début du fichier principal (default.php)
 * pour intercepter les URLs anciennes et les rediriger correctement
 * sans pénaliser le SEO.
 */

function handleRedirect() {
    global $pdo;

    if (!isset($pdo)) {
        return; // Pas de PDO disponible
    }

    // Récupérer l'URL actuelle (chemin)
    $currentUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/default.php', PHP_URL_PATH);

    if (!is_string($currentUrl) || $currentUrl === '') {
        return;
    }

    try {
        // Chercher une redirection pour cette URL
        $stmt = $pdo->prepare("
            SELECT type_redirect, new_url, id
            FROM url_redirects
            WHERE old_url = ?
            AND actif = 1
            LIMIT 1
        ");
        $stmt->execute([$currentUrl]);
        $redirect = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($redirect && !empty($redirect['new_url'])) {
            // Incrémenter le compteur de redirections
            $updateStmt = $pdo->prepare("
                UPDATE url_redirects
                SET nb_redirections = nb_redirections + 1,
                    last_redirect_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$redirect['id']]);

            // Effectuer la redirection
            $statusCode = (int) $redirect['type_redirect'];
            http_response_code($statusCode);
            header("Location: " . $redirect['new_url']);
            header("X-Redirect-Type: {$statusCode}");
            exit;
        } elseif ($redirect && $redirect['type_redirect'] === '410') {
            // Redirection 410 Gone (contenu supprimé)
            // Incrémenter le compteur
            $updateStmt = $pdo->prepare("
                UPDATE url_redirects
                SET nb_redirections = nb_redirections + 1,
                    last_redirect_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$redirect['id']]);

            http_response_code(410);
            header("Content-Type: text/html; charset=utf-8");
            echo "<!DOCTYPE html><html><head><title>Page supprimée</title></head>";
            echo "<body><h1>410 - Page supprimée</h1>";
            echo "<p>Le contenu que vous recherchez a été supprimé.</p>";
            echo "</body></html>";
            exit;
        }
    } catch (\Exception $e) {
        // Erreur de redirection non-critique, laisser la requête passer
        error_log("Redirect handler error: " . $e->getMessage());
    }
}

// Appeler le handler
handleRedirect();
?>
