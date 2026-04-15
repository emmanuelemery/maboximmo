<?php
declare(strict_types=1);

/**
 * BienPhotosManager — Bibliothèque photos rattachée au bien
 *
 * Stocke les photos brutes d'un bien (originaux JPEG/PNG/WebP) dans :
 *   uploads/biens/{id_societe}/{id_bien}/NN_{hash}.{ext}
 *
 * Et insère une ligne par photo dans la table `biens_photos`.
 *
 * Ces photos servent de bibliothèque source : quand l'utilisateur crée
 * une annonce, il sélectionne jusqu'à 7 photos depuis cette bibliothèque,
 * et AnnoncePhotosManager les COPIE + RENOMME en mode SEO + génère
 * les variantes WebP dans uploads/annonces/{soc}/{id_annonce}/.
 *
 * NB : ce manager ne fait PAS de redimensionnement / réencodage WebP.
 * C'est volontaire — on garde l'original tel quel pour préserver
 * la qualité maximale, et c'est AnnoncePhotosManager qui fait
 * le traitement SEO au moment de la diffusion.
 */
final class BienPhotosManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Ajoute une photo à la bibliothèque d'un bien.
     *
     * @param int    $idBien      ID du bien cible
     * @param int    $idSociete   ID de la société (pour l'arborescence disque)
     * @param string $srcPath     Chemin absolu du fichier source (uploadé temporairement)
     * @param string|null $nomOriginal Nom du fichier tel qu'uploadé (pour traçabilité)
     * @param int|null $idUserUpload  ID de l'utilisateur qui upload (optionnel)
     * @return array ['ok'=>bool, 'id'=>int|null, 'url'=>string|null, 'error'=>string|null]
     */
    public function ajouterPhoto(
        int $idBien,
        int $idSociete,
        string $srcPath,
        ?string $nomOriginal = null,
        ?int $idUserUpload = null
    ): array {
        if (!is_readable($srcPath)) {
            return ['ok' => false, 'error' => 'Fichier source introuvable'];
        }

        // Détection MIME + extension
        $info = @getimagesize($srcPath);
        if (!$info) {
            return ['ok' => false, 'error' => 'Format image invalide'];
        }
        $mime = $info['mime'] ?? '';
        $ext = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            default        => null,
        };
        if ($ext === null) {
            return ['ok' => false, 'error' => 'Format non supporté (JPG/PNG/WebP uniquement)'];
        }

        // Création du dossier disque
        $dirAbs = dirname(__DIR__) . '/uploads/biens/' . $idSociete . '/' . $idBien . '/';
        $dirRel = 'uploads/biens/' . $idSociete . '/' . $idBien . '/';
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
            return ['ok' => false, 'error' => 'Impossible de créer le dossier ' . $dirRel];
        }

        // Calcul de l'ordre suivant
        $st = $this->pdo->prepare("SELECT COALESCE(MAX(ordre), 0) FROM biens_photos WHERE id_bien = ?");
        $st->execute([$idBien]);
        $nextOrdre = ((int)$st->fetchColumn()) + 1;

        // Hash + nom de fichier
        $md5 = md5_file($srcPath) ?: substr(uniqid('', true), 0, 12);

        // Vérif déduplication par hash
        $st = $this->pdo->prepare("SELECT id, url_photo FROM biens_photos WHERE id_bien = ? AND hash_md5 = ? LIMIT 1");
        $st->execute([$idBien, $md5]);
        if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
            return [
                'ok' => true,
                'id' => (int)$dup['id'],
                'url' => $dup['url_photo'],
                'duplicate' => true,
            ];
        }

        $fileName = sprintf('%02d_%s.%s', $nextOrdre, substr($md5, 0, 8), $ext);
        $destAbs = $dirAbs . $fileName;
        $destRel = $dirRel . $fileName;

        if (!@copy($srcPath, $destAbs) && !@rename($srcPath, $destAbs)) {
            return ['ok' => false, 'error' => 'Impossible d\'écrire le fichier sur disque'];
        }

        $largeur = $info[0] ?? null;
        $hauteur = $info[1] ?? null;
        $poids = filesize($destAbs) ?: null;

        // Insertion BDD
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO biens_photos
                 (id_bien, ordre, url_photo, nom_original, largeur, hauteur, poids_octets, hash_md5, mime_type, id_user_upload, date_upload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $st->execute([
                $idBien,
                $nextOrdre,
                $destRel,
                $nomOriginal,
                $largeur,
                $hauteur,
                $poids,
                $md5,
                $mime,
                $idUserUpload,
            ]);
            $idPhoto = (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            @unlink($destAbs);
            return ['ok' => false, 'error' => 'Erreur BDD : ' . $e->getMessage()];
        }

        return [
            'ok'      => true,
            'id'      => $idPhoto,
            'url'     => $destRel,
            'ordre'   => $nextOrdre,
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'poids'   => $poids,
        ];
    }

    /**
     * Liste les photos d'un bien.
     */
    public function listePhotos(int $idBien): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, ordre, url_photo, nom_original, largeur, hauteur, poids_octets, date_upload
             FROM biens_photos
             WHERE id_bien = ?
             ORDER BY ordre ASC"
        );
        $st->execute([$idBien]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Supprime une photo (BDD + disque).
     */
    public function supprimer(int $idPhoto): bool
    {
        $st = $this->pdo->prepare("SELECT url_photo FROM biens_photos WHERE id = ?");
        $st->execute([$idPhoto]);
        $url = $st->fetchColumn();
        if (!$url) return false;

        $abs = dirname(__DIR__) . '/' . $url;
        @unlink($abs);

        $st = $this->pdo->prepare("DELETE FROM biens_photos WHERE id = ?");
        return $st->execute([$idPhoto]);
    }

    /**
     * Récupère le chemin absolu d'une photo (pour copy vers annonces).
     */
    public function getPathAbs(int $idPhoto): ?string
    {
        $st = $this->pdo->prepare("SELECT url_photo FROM biens_photos WHERE id = ?");
        $st->execute([$idPhoto]);
        $url = $st->fetchColumn();
        if (!$url) return null;
        $abs = dirname(__DIR__) . '/' . $url;
        return is_readable($abs) ? $abs : null;
    }

    /**
     * Récupère plusieurs photos par leurs IDs, dans l'ordre passé en paramètre.
     * Utile pour la sélection ordonnée vers annonces_photos.
     */
    public function getPhotosByIds(array $ids): array
    {
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, url_photo, nom_original, largeur, hauteur
             FROM biens_photos
             WHERE id IN ($placeholders)"
        );
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Indexer par id pour préserver l'ordre demandé
        $byId = [];
        foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[(int)$id])) {
                $ordered[] = $byId[(int)$id];
            }
        }
        return $ordered;
    }
}
