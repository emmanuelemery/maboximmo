<?php
declare(strict_types=1);

require_once __DIR__ . '/image_tools.php';

/**
 * BienPhotosManager — Bibliothèque photos rattachée au bien
 *
 * Stocke les photos d'un bien dans :
 *   uploads/biens/{id_societe}/{id_bien}/NN_{hash}.jpg      (original compressé)
 *   uploads/biens/{id_societe}/{id_bien}/NN_{hash}.lbc.jpg  (variante Le Bon Coin)
 *
 * À l'upload, l'image est :
 *   - chargée avec redressement EXIF
 *   - downscale à max ORIGINAL_MAX_WIDTH si plus large
 *   - réencodée JPEG qualité ORIGINAL_QUALITY (métadonnées EXIF droppées)
 *   - dupliquée en variante LBC : max LBC_MAX_WIDTH, JPEG forcé < LBC_MAX_BYTES
 *
 * Le tout ramène des photos smartphone ~8 Mo à ~600 Ko (original) + ~300 Ko
 * (variante LBC), soit un facteur ~15× sur l'usage disque.
 */
final class BienPhotosManager
{
    public const ORIGINAL_MAX_WIDTH = 2500;
    public const ORIGINAL_QUALITY   = 85;
    public const LBC_MAX_WIDTH      = 1200;
    public const LBC_MAX_BYTES      = 1_900_000;
    public const LBC_SUFFIX         = '.lbc.jpg';

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

        // Hash du fichier SOURCE (pré-compression) → utilisé pour la déduplication
        // même si l'upload du même fichier brut produit un binaire compressé différent.
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

        // Charge l'image avec redressement EXIF
        $loaded = it_load_and_orient($srcPath);
        if (!$loaded) {
            return ['ok' => false, 'error' => 'Format image invalide ou non supporté (JPG/PNG/WebP uniquement)'];
        }
        [$imgRes, $srcExt] = $loaded;

        // Création du dossier disque
        $dirAbs = dirname(__DIR__) . '/uploads/biens/' . $idSociete . '/' . $idBien . '/';
        $dirRel = 'uploads/biens/' . $idSociete . '/' . $idBien . '/';
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
            imagedestroy($imgRes);
            return ['ok' => false, 'error' => 'Impossible de créer le dossier ' . $dirRel];
        }

        // Calcul de l'ordre suivant
        $st = $this->pdo->prepare("SELECT COALESCE(MAX(ordre), 0) FROM biens_photos WHERE id_bien = ?");
        $st->execute([$idBien]);
        $nextOrdre = ((int)$st->fetchColumn()) + 1;

        // Downscale pour l'original
        $origRes = it_resize_max_width($imgRes, self::ORIGINAL_MAX_WIDTH);
        $origResIsNew = ($origRes !== $imgRes);

        $baseName = sprintf('%02d_%s', $nextOrdre, substr($md5, 0, 8));
        $origFile = $baseName . '.jpg';
        $lbcFile  = $baseName . self::LBC_SUFFIX;
        $origAbs = $dirAbs . $origFile;
        $lbcAbs  = $dirAbs . $lbcFile;
        $origRel = $dirRel . $origFile;
        $lbcRel  = $dirRel . $lbcFile;

        if (!it_save_jpeg($origRes, $origAbs, self::ORIGINAL_QUALITY)) {
            if ($origResIsNew) imagedestroy($origRes);
            imagedestroy($imgRes);
            return ['ok' => false, 'error' => 'Impossible d\'écrire l\'original sur disque'];
        }

        // Variante LBC : downscale indépendant depuis l'image d'origine redressée
        $lbcRes = it_resize_max_width($imgRes, self::LBC_MAX_WIDTH);
        $lbcResIsNew = ($lbcRes !== $imgRes);
        $lbcQualityUsed = it_save_jpeg_under_size($lbcRes, $lbcAbs, self::LBC_MAX_BYTES);
        if ($lbcResIsNew) imagedestroy($lbcRes);

        // Nettoyage ressources GD
        if ($origResIsNew) imagedestroy($origRes);
        imagedestroy($imgRes);

        // Mesures finales
        $origInfo = it_measure($origAbs);
        $largeur = $origInfo['largeur'] ?: null;
        $hauteur = $origInfo['hauteur'] ?: null;
        $poids   = $origInfo['poids']   ?: null;
        $mime    = $origInfo['mime']    ?: 'image/jpeg';

        // Insertion BDD
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO biens_photos
                 (id_bien, ordre, url_photo, url_lbc, nom_original, largeur, hauteur, poids_octets, hash_md5, mime_type, id_user_upload, date_upload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $st->execute([
                $idBien,
                $nextOrdre,
                $origRel,
                $lbcQualityUsed > 0 ? $lbcRel : null,
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
            @unlink($origAbs);
            @unlink($lbcAbs);
            return ['ok' => false, 'error' => 'Erreur BDD : ' . $e->getMessage()];
        }

        return [
            'ok'       => true,
            'id'       => $idPhoto,
            'url'      => $origRel,
            'url_lbc'  => $lbcQualityUsed > 0 ? $lbcRel : null,
            'ordre'    => $nextOrdre,
            'largeur'  => $largeur,
            'hauteur'  => $hauteur,
            'poids'    => $poids,
            'lbc_q'    => $lbcQualityUsed,
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
        $st = $this->pdo->prepare("SELECT url_photo, url_lbc FROM biens_photos WHERE id = ?");
        $st->execute([$idPhoto]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $base = dirname(__DIR__) . '/';
        if (!empty($row['url_photo'])) @unlink($base . ltrim((string)$row['url_photo'], '/'));
        if (!empty($row['url_lbc']))   @unlink($base . ltrim((string)$row['url_lbc'],   '/'));

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
