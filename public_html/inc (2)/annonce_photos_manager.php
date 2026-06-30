<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_slug.php';

/**
 * AnnoncePhotosManager — Gestion des photos d'annonces
 *
 * Point d'entrée unique pour :
 *  - importer une photo depuis le dossier temporaire `uploads/imports/.../photos_X/`
 *  - générer les variantes WebP (thumb / medium / large)
 *  - mesurer dimensions, poids, md5
 *  - insérer les lignes dans `annonces_photos`
 *  - supprimer une photo (fichiers + BDD)
 *  - régénérer les noms quand le slug d'une annonce change
 *
 * Dépendances : extension GD avec support WebP (vérifié ≤ 2026-04-10).
 */
final class AnnoncePhotosManager
{
    /** Tailles cibles (largeur en px). 'original' = pas de redimensionnement. */
    private const TAILLES = [
        'thumb'   => 400,
        'medium'  => 800,
        'large'   => 1600,
    ];

    /** Qualité d'encodage */
    private const JPEG_QUALITE = 88;
    private const WEBP_QUALITE = 82;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =================================================================
    //  API PUBLIQUE
    // =================================================================

    /**
     * Importe une photo depuis un chemin temporaire vers le dossier final
     * de l'annonce, génère les variantes WebP et insère les lignes en BDD.
     *
     * @param int    $idAnnonce  ID de l'annonce cible
     * @param string $srcPath    Chemin absolu du fichier source (JPEG/PNG)
     * @param int    $ordre      Ordre d'affichage (1-99)
     * @param bool   $principale True si c'est la photo principale
     * @return array Résumé : ['ok' => bool, 'photos' => [...], 'error' => string|null]
     */
    public function importerDepuisFichier(
        int $idAnnonce,
        string $srcPath,
        int $ordre,
        bool $principale = false
    ): array {
        if (!is_readable($srcPath)) {
            return ['ok' => false, 'error' => 'Fichier source introuvable'];
        }

        // Charge contexte annonce + bien + agence pour le nommage
        $ctx = $this->chargerContexte($idAnnonce);
        if (!$ctx) {
            return ['ok' => false, 'error' => 'Annonce/bien/agence introuvable'];
        }

        // Prépare le dossier final
        $idSociete = (int)$ctx['annonce']['id_societe'];
        $dirAbs = SeoSlug::photoDirAbs($idSociete, $idAnnonce);
        $dirRel = SeoSlug::photoDirRel($idSociete, $idAnnonce);

        // Charge l'image source avec GD (et redresse via EXIF)
        $img = $this->chargerImage($srcPath);
        if (!$img) {
            return ['ok' => false, 'error' => 'Format image non supporté'];
        }
        [$imgRes, $srcExt] = $img;
        $srcLargeur = imagesx($imgRes);
        $srcHauteur = imagesy($imgRes);

        $inserees = [];

        try {
            $this->pdo->beginTransaction();

            // ---- 1. Original (on garde l'extension source, JPEG de préférence)
            $extOriginal = ($srcExt === 'png') ? 'jpg' : $srcExt;
            $nomOrig = SeoSlug::photoName($ctx['bien'], $ctx['annonce'], $ctx['agence'], $ordre, 'original', $extOriginal);
            $destOrig = $dirAbs . $nomOrig;

            // On réencode l'original en JPEG qualité fixe (normalise les tailles/métadonnées)
            if (!imagejpeg($imgRes, $destOrig, self::JPEG_QUALITE)) {
                throw new RuntimeException('Échec écriture original JPEG');
            }

            $hashOrig = md5_file($destOrig) ?: null;
            $poidsOrig = filesize($destOrig) ?: null;

            $altTxt   = SeoSlug::photoAlt($ctx['bien'], $ctx['annonce'], $ctx['agence'], $ordre);
            $caption  = $altTxt; // par défaut identique, peut être édité ensuite

            $idOrig = $this->insererLigne([
                'id_annonce'       => $idAnnonce,
                'url_photo'        => $dirRel . $nomOrig,
                'url_webp'         => null,
                'largeur'          => $srcLargeur,
                'hauteur'          => $srcHauteur,
                'poids_octets'     => $poidsOrig,
                'hash_md5'         => $hashOrig,
                'variante'         => 'original',
                'titre'            => null,
                'alt_photo'        => $altTxt,
                'caption'          => $caption,
                'ordre_affichage'  => $ordre,
                'principale'       => $principale ? 1 : 0,
            ]);
            $inserees[] = ['variante' => 'original', 'id' => $idOrig, 'fichier' => $nomOrig];

            // ---- 2. Variantes WebP (thumb / medium / large)
            foreach (self::TAILLES as $variante => $largeurCible) {
                // Pas de sur-agrandissement : si la source est plus petite, on garde sa taille
                $w = min($largeurCible, $srcLargeur);
                $h = (int)round($srcHauteur * ($w / $srcLargeur));

                $resized = imagecreatetruecolor($w, $h);
                imagecopyresampled($resized, $imgRes, 0, 0, 0, 0, $w, $h, $srcLargeur, $srcHauteur);

                $nomWebp = SeoSlug::photoName($ctx['bien'], $ctx['annonce'], $ctx['agence'], $ordre, $variante, 'webp');
                $destWebp = $dirAbs . $nomWebp;

                if (!imagewebp($resized, $destWebp, self::WEBP_QUALITE)) {
                    imagedestroy($resized);
                    throw new RuntimeException("Échec écriture WebP $variante");
                }
                imagedestroy($resized);

                $idVar = $this->insererLigne([
                    'id_annonce'       => $idAnnonce,
                    'url_photo'        => $dirRel . $nomWebp,
                    'url_webp'         => $dirRel . $nomWebp, // identique (c'est déjà du webp)
                    'largeur'          => $w,
                    'hauteur'          => $h,
                    'poids_octets'     => filesize($destWebp) ?: null,
                    'hash_md5'         => md5_file($destWebp) ?: null,
                    'variante'         => $variante,
                    'titre'            => null,
                    'alt_photo'        => $altTxt,
                    'caption'          => $caption,
                    'ordre_affichage'  => $ordre,
                    'principale'       => 0, // seule l'originale porte le flag
                ]);
                $inserees[] = ['variante' => $variante, 'id' => $idVar, 'fichier' => $nomWebp];
            }

            $this->pdo->commit();
            imagedestroy($imgRes);

            return ['ok' => true, 'photos' => $inserees];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            imagedestroy($imgRes);
            // Nettoyage partiel des fichiers créés
            foreach ($inserees as $p) {
                @unlink($dirAbs . $p['fichier']);
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Supprime une photo (toutes ses variantes partageant le même ordre_affichage)
     * — fichiers disque + lignes BDD.
     */
    public function supprimer(int $idAnnonce, int $ordre): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, url_photo FROM annonces_photos WHERE id_annonce = ? AND ordre_affichage = ?'
        );
        $stmt->execute([$idAnnonce, $ordre]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) return false;

        $base = dirname(__DIR__) . '/';
        foreach ($rows as $r) {
            @unlink($base . $r['url_photo']);
        }

        $del = $this->pdo->prepare(
            'DELETE FROM annonces_photos WHERE id_annonce = ? AND ordre_affichage = ?'
        );
        $del->execute([$idAnnonce, $ordre]);

        return true;
    }

    // =================================================================
    //  PRIVÉ
    // =================================================================

    /**
     * Charge annonce + bien + agence dans un tableau pour le nommage.
     */
    private function chargerContexte(int $idAnnonce): ?array
    {
        $sql = '
            SELECT
                a.id, a.id_bien, a.id_agence, a.id_societe,
                b.id AS bien_id, b.id_type_bien, b.sous_type_bien,
                b.surface_habitable, b.surface_terrain, b.ville,
                b.nb_pieces,
                tb.label AS type_bien,
                ag.id AS agence_id, ag.nom_agence, ag.nom_commercial, ag.slug AS agence_slug
            FROM annonces a
            JOIN biens b      ON b.id = a.id_bien
            LEFT JOIN base_types_bien tb ON tb.id = b.id_type_bien
            LEFT JOIN agences ag ON ag.id = a.id_agence
            WHERE a.id = ?
            LIMIT 1
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$idAnnonce]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return [
            'annonce' => [
                'id'         => (int)$row['id'],
                'id_societe' => (int)$row['id_societe'],
            ],
            'bien' => [
                'type_bien'         => $row['type_bien'] ?? null,
                'sous_type_bien'    => $row['sous_type_bien'] ?? null,
                'surface_habitable' => $row['surface_habitable'] ?? null,
                'surface_terrain'   => $row['surface_terrain'] ?? null,
                'ville'             => $row['ville'] ?? null,
                'nb_pieces'         => $row['nb_pieces'] ?? null,
            ],
            'agence' => [
                'nom_agence'     => $row['nom_agence'] ?? null,
                'nom_commercial' => $row['nom_commercial'] ?? null,
                'slug'           => $row['agence_slug'] ?? null,
            ],
        ];
    }

    /**
     * Charge une image avec GD et la redresse selon EXIF si nécessaire.
     * Retourne [ressource GD, extension détectée] ou null.
     */
    private function chargerImage(string $path): ?array
    {
        $info = @getimagesize($path);
        if (!$info) return null;

        $img = null;
        $ext = 'jpg';
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($path);
                $ext = 'jpg';
                // Redressement EXIF
                if ($img && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($path);
                    if (!empty($exif['Orientation'])) {
                        switch ((int)$exif['Orientation']) {
                            case 3: $img = imagerotate($img, 180, 0); break;
                            case 6: $img = imagerotate($img, -90, 0); break;
                            case 8: $img = imagerotate($img,  90, 0); break;
                        }
                    }
                }
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($path);
                $ext = 'png';
                break;
            case IMAGETYPE_WEBP:
                $img = @imagecreatefromwebp($path);
                $ext = 'webp';
                break;
        }

        return $img ? [$img, $ext] : null;
    }

    /**
     * Insère une ligne dans `annonces_photos` et retourne l'ID créé.
     */
    private function insererLigne(array $data): int
    {
        $sql = 'INSERT INTO annonces_photos
            (id_annonce, url_photo, url_webp, largeur, hauteur, poids_octets, hash_md5,
             variante, titre, alt_photo, caption, ordre_affichage, principale, date_creation)
            VALUES
            (:id_annonce, :url_photo, :url_webp, :largeur, :hauteur, :poids_octets, :hash_md5,
             :variante, :titre, :alt_photo, :caption, :ordre_affichage, :principale, NOW())';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int)$this->pdo->lastInsertId();
    }
}
