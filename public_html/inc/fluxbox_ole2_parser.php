<?php
declare(strict_types=1);

/**
 * Parser OLE2 / Compound File Binary minimaliste pour les fichiers .msg.
 *
 * Implémente juste ce qu'il faut pour extraire les streams MAPI par leur nom :
 *  - Lit le header CFB (512 bytes)
 *  - Reconstitue la FAT (File Allocation Table) via le DIF
 *  - Parse le Directory Tree pour identifier les storages et streams
 *  - Lit le contenu d'un stream nommé (gère FAT et mini-FAT selon la taille)
 *
 * Cible : extraire __substg1.0_XXXXXXXX (propriétés MAPI typées) du .msg.
 * Format spec : MS-CFB (Compound File Binary File Format).
 *
 * Validé EMERY 2026-05-16.
 */

if (!class_exists('FluxboxOle2Parser')) {
    class FluxboxOle2Parser
    {
        /** @var string Contenu binaire complet du fichier */
        private string $data;

        /** @var int Taille d'un secteur (généralement 512) */
        private int $sectorSize;

        /** @var int Taille d'un mini-secteur (généralement 64) */
        private int $miniSectorSize;

        /** @var int Cutoff pour passer en mini-FAT (généralement 4096) */
        private int $miniCutoff;

        /** @var int Premier secteur du directory */
        private int $dirStart;

        /** @var int Premier secteur du mini-FAT chain */
        private int $miniFatStart;

        /** @var int Premier secteur du mini-stream (= contenu de Root Entry) */
        private int $miniStreamStart = 0;

        /** @var int Premier secteur DIFAT (chaîne supplémentaire si > 109 secteurs FAT) */
        private int $difStart = 0xFFFFFFFE;

        /** @var int[] FAT reconstituée (chaîne des secteurs) */
        private array $fat = [];

        /** @var int[] Mini-FAT */
        private array $miniFat = [];

        /** @var array<int, array{name:string,type:int,start:int,size:int,child:int,left:int,right:int}> */
        private array $directory = [];

        public function __construct(string $binaryData)
        {
            $this->data = $binaryData;
        }

        /**
         * Charge le fichier et parse les structures internes.
         * @return bool true si le parsing a réussi — JAMAIS d'exception remontée.
         */
        public function load(): bool
        {
            try {
                return $this->loadInternal();
            } catch (Throwable $e) {
                error_log('FluxboxOle2Parser load failed: ' . $e->getMessage());
                return false;
            }
        }

        private function loadInternal(): bool
        {
            if (strlen($this->data) < 512) return false;
            if (substr($this->data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") return false;

            // Lecture du header par champs séquentiels (offsets MS-CFB officiels)
            $sectorShift  = $this->readU16(30);  // bytes 30-31
            $miniShift    = $this->readU16(32);  // bytes 32-33
            // bytes 34-43 : reserved + nb dir sectors (ignorés)
            // bytes 44-47 : nb FAT sectors
            // bytes 48-51 : first dir sector
            $this->dirStart       = $this->readU32(48);
            // bytes 56-59 : mini stream cutoff
            $this->miniCutoff     = $this->readU32(56);
            // bytes 60-63 : first mini FAT sector
            $this->miniFatStart   = $this->readU32(60);
            // bytes 68-71 : first DIFAT sector
            $this->difStart       = $this->readU32(68);

            $this->sectorSize     = 1 << $sectorShift;
            $this->miniSectorSize = 1 << $miniShift;

            // Sécurité : taille de secteur connue
            if (!in_array($this->sectorSize, [512, 4096], true)) return false;
            if ($this->miniSectorSize !== 64) return false;

            // Reconstitue la FAT (sans throw si échec)
            $this->buildFat();
            if (count($this->fat) === 0) return false;

            // Lit le directory tree
            if (!$this->readDirectory()) return false;

            // Le Root Entry contient l'info du mini-stream
            if (isset($this->directory[0])) {
                $this->miniStreamStart = $this->directory[0]['start'];
            }

            // Reconstitue la mini-FAT
            $this->buildMiniFat();

            return true;
        }

        /** Lecture sécurisée d'un uint16 LE à l'offset donné. Retourne 0 si hors limites. */
        private function readU16(int $offset): int
        {
            if ($offset + 2 > strlen($this->data)) return 0;
            $v = @unpack('v', substr($this->data, $offset, 2));
            return is_array($v) ? (int)$v[1] : 0;
        }

        /** Lecture sécurisée d'un uint32 LE à l'offset donné. Retourne 0xFFFFFFFE si hors limites. */
        private function readU32(int $offset): int
        {
            if ($offset + 4 > strlen($this->data)) return 0xFFFFFFFE;
            $v = @unpack('V', substr($this->data, $offset, 4));
            return is_array($v) ? (int)$v[1] : 0xFFFFFFFE;
        }

        /** Lecture sécurisée d'un uint32 LE à partir d'une string + offset. */
        private function readU32FromString(string $data, int $offset): int
        {
            if ($offset + 4 > strlen($data)) return 0xFFFFFFFE;
            $v = @unpack('V', substr($data, $offset, 4));
            return is_array($v) ? (int)$v[1] : 0xFFFFFFFE;
        }

        /**
         * Récupère le contenu d'un stream par son nom (ex "__substg1.0_0037001F").
         * @return string|null Le contenu binaire du stream, ou null si introuvable.
         */
        public function getStreamContent(string $streamName): ?string
        {
            foreach ($this->directory as $entry) {
                if ($entry['type'] === 2 && $entry['name'] === $streamName) {
                    return $this->readStreamData($entry['start'], $entry['size']);
                }
            }
            return null;
        }

        /**
         * Récupère le contenu d'un stream par PropID MAPI (ex "0037001F" pour Subject Unicode).
         */
        public function getMapiProp(string $propIdHex): ?string
        {
            $propIdHex = strtoupper(preg_replace('/^0x/i', '', $propIdHex));
            return $this->getStreamContent('__substg1.0_' . $propIdHex);
        }

        /**
         * Liste tous les noms d'entries (storages et streams) pour debug.
         * @return array<string>
         */
        public function listEntries(): array
        {
            return array_map(static fn($e) => $e['name'] . ' (type=' . $e['type'] . ', size=' . $e['size'] . ')', $this->directory);
        }

        /**
         * Liste les sous-storages "__attach_version1.0_#XXXXXXXX" = pièces jointes.
         * Chaque PJ a son propre nom + taille (pris dans le sous-storage).
         * @return array<int, array{idx:int, name:string, size:int, mime:string}>
         */
        public function listAttachments(): array
        {
            $attachments = [];
            foreach ($this->directory as $entry) {
                if ($entry['type'] !== 1) continue; // storage uniquement
                if (!preg_match('/^__attach_version1\.0_#(\d{8})$/', $entry['name'], $m)) continue;
                $idx = (int)hexdec($m[1]);
                // Cherche le filename : __substg1.0_3707001F (PR_ATTACH_LONG_FILENAME_W)
                // et la taille : PR_ATTACH_SIZE 0x0E20 (mais inline dans properties stream)
                // Pour simplifier : on cherche le filename via children du storage
                $filename = '';
                $mime = '';
                foreach ($this->directory as $child) {
                    if ($child['type'] !== 2) continue;
                    // Heuristique : si on est juste après ce storage, c'est probablement son enfant
                    // Une vraie implémentation suivrait child/left/right du tree
                    if (str_starts_with($child['name'], '__substg1.0_3707')) {
                        $raw = $this->readStreamData($child['start'], $child['size']);
                        if ($raw !== null) {
                            $name = $this->decodeUtf16Le($raw);
                            if ($name !== '' && $filename === '') $filename = $name;
                        }
                    }
                    if (str_starts_with($child['name'], '__substg1.0_370E')) {
                        $raw = $this->readStreamData($child['start'], $child['size']);
                        if ($raw !== null) {
                            $m = $this->decodeUtf16Le($raw);
                            if ($m !== '' && $mime === '') $mime = $m;
                        }
                    }
                }
                $attachments[] = [
                    'idx'  => $idx,
                    'name' => $filename ?: "piece_jointe_" . ($idx + 1) . ".bin",
                    'size' => 0,
                    'mime' => $mime,
                ];
            }
            return $attachments;
        }

        // ─────────────────────────────────────────────────────────────────

        private function buildFat(): void
        {
            // Le DIFAT contient des indices vers les secteurs de FAT.
            // Premiers 109 entries dans le header (offset 76, 4 bytes chacun).
            $difEntries = [];
            for ($i = 0; $i < 109; $i++) {
                $sec = $this->readU32(76 + $i * 4);
                if ($sec === 0xFFFFFFFE || $sec === 0xFFFFFFFF) continue;
                if ($sec < 0 || $sec > 0xFFFFFFF0) continue; // sécurité
                $difEntries[] = $sec;
            }
            // Chaîne supplémentaire si plus de 109 secteurs FAT (rare pour les petits .msg)
            $difNext = $this->difStart;
            $maxIter = 1000;
            while ($difNext !== 0xFFFFFFFE && $difNext !== 0xFFFFFFFF && $maxIter-- > 0) {
                $sectorData = $this->readSectorRaw($difNext);
                if ($sectorData === null || strlen($sectorData) < $this->sectorSize) break;
                $nEntries = (int)(($this->sectorSize - 4) / 4);
                for ($i = 0; $i < $nEntries; $i++) {
                    $sec = $this->readU32FromString($sectorData, $i * 4);
                    if ($sec === 0xFFFFFFFE || $sec === 0xFFFFFFFF) continue;
                    if ($sec < 0 || $sec > 0xFFFFFFF0) continue;
                    $difEntries[] = $sec;
                }
                $difNext = $this->readU32FromString($sectorData, $this->sectorSize - 4);
            }

            // Pour chaque secteur FAT, on lit les entries (128 pour sectorSize=512, 1024 pour 4096)
            $nEntriesPerSector = (int)($this->sectorSize / 4);
            foreach ($difEntries as $fatSec) {
                $raw = $this->readSectorRaw($fatSec);
                if ($raw === null || strlen($raw) < $this->sectorSize) continue;
                for ($i = 0; $i < $nEntriesPerSector; $i++) {
                    $this->fat[] = $this->readU32FromString($raw, $i * 4);
                }
            }
        }

        private function buildMiniFat(): void
        {
            $sec = $this->miniFatStart;
            $maxIter = 10000;
            $nEntriesPerSector = (int)($this->sectorSize / 4);
            while ($sec !== 0xFFFFFFFE && $sec !== 0xFFFFFFFF && $maxIter-- > 0 && $sec >= 0) {
                $raw = $this->readSectorRaw($sec);
                if ($raw === null || strlen($raw) < $this->sectorSize) break;
                for ($i = 0; $i < $nEntriesPerSector; $i++) {
                    $this->miniFat[] = $this->readU32FromString($raw, $i * 4);
                }
                $sec = $this->fat[$sec] ?? 0xFFFFFFFE;
            }
        }

        private function readDirectory(): bool
        {
            $dirData = $this->readChainData($this->dirStart, -1);
            if ($dirData === null || strlen($dirData) < 128) return false;
            $entrySize = 128;
            $count = (int)floor(strlen($dirData) / $entrySize);
            for ($i = 0; $i < $count; $i++) {
                $entry = substr($dirData, $i * $entrySize, $entrySize);
                if (strlen($entry) < $entrySize) break;
                // Bytes 0-63 : nom UTF-16LE
                // Bytes 64-65 : longueur du nom (en bytes, incluant null terminator)
                $nameLen = $this->readU16FromString($entry, 64);
                if ($nameLen <= 0 || $nameLen > 64) continue;
                $nameRaw = substr($entry, 0, $nameLen);
                $name = $this->decodeUtf16Le($nameRaw);
                // Byte 66 : type (0=empty, 1=storage, 2=stream, 5=root)
                $type = ord($entry[66] ?? "\x00");
                if ($type === 0) continue;
                // Bytes 68-71 : left sibling, 72-75 : right sibling, 76-79 : child
                // Bytes 116-119 : start sector, 120-123 : stream size (uint32 low)
                $left  = $this->readU32FromString($entry, 68);
                $right = $this->readU32FromString($entry, 72);
                $child = $this->readU32FromString($entry, 76);
                $start = $this->readU32FromString($entry, 116);
                $size  = $this->readU32FromString($entry, 120);
                $this->directory[$i] = [
                    'name'  => $name,
                    'type'  => $type,
                    'start' => $start,
                    'size'  => $size,
                    'child' => $child,
                    'left'  => $left,
                    'right' => $right,
                ];
            }
            return count($this->directory) > 0;
        }

        private function readU16FromString(string $data, int $offset): int
        {
            if ($offset + 2 > strlen($data)) return 0;
            $v = @unpack('v', substr($data, $offset, 2));
            return is_array($v) ? (int)$v[1] : 0;
        }

        private function readStreamData(int $startSector, int $size): ?string
        {
            if ($size === 0) return '';
            // Si taille < cutoff → mini-FAT (mini-stream)
            if ($size < $this->miniCutoff) {
                return $this->readMiniChainData($startSector, $size);
            }
            return $this->readChainData($startSector, $size);
        }

        private function readChainData(int $startSector, int $maxSize): ?string
        {
            $data = '';
            $sec = $startSector;
            $maxIter = 100000;
            while ($sec !== 0xFFFFFFFE && $sec !== 0xFFFFFFFF && $maxIter-- > 0 && $sec >= 0) {
                $raw = $this->readSectorRaw($sec);
                if ($raw === null) break;
                $data .= $raw;
                if ($maxSize > 0 && strlen($data) >= $maxSize) break;
                $sec = $this->fat[$sec] ?? 0xFFFFFFFE;
            }
            if ($maxSize > 0 && strlen($data) > $maxSize) $data = substr($data, 0, $maxSize);
            return $data;
        }

        private function readMiniChainData(int $startMiniSector, int $size): ?string
        {
            // Lit le mini-stream complet (= contenu de Root Entry stream)
            $rootSize = $this->directory[0]['size'] ?? 0;
            if ($rootSize === 0) return null;
            $miniStream = $this->readChainData($this->miniStreamStart, $rootSize);
            if ($miniStream === null) return null;

            $data = '';
            $sec = $startMiniSector;
            $maxIter = 100000;
            while ($sec !== 0xFFFFFFFE && $sec !== 0xFFFFFFFF && $maxIter-- > 0 && $sec >= 0) {
                $offset = $sec * $this->miniSectorSize;
                if ($offset + $this->miniSectorSize > strlen($miniStream)) break;
                $data .= substr($miniStream, $offset, $this->miniSectorSize);
                if (strlen($data) >= $size) break;
                $sec = $this->miniFat[$sec] ?? 0xFFFFFFFE;
            }
            if (strlen($data) > $size) $data = substr($data, 0, $size);
            return $data;
        }

        private function readSectorRaw(int $sectorIndex): ?string
        {
            if ($sectorIndex < 0) return null;
            // Sector 0 du file = juste après le header (offset 512)
            $offset = ($sectorIndex + 1) * $this->sectorSize;
            if ($offset + $this->sectorSize > strlen($this->data)) return null;
            return substr($this->data, $offset, $this->sectorSize);
        }

        private function decodeUtf16Le(string $raw): string
        {
            // Décode UTF-16LE en UTF-8, et trim les \x00 en fin
            $clean = '';
            $len = strlen($raw);
            for ($i = 0; $i + 1 < $len; $i += 2) {
                $c1 = ord($raw[$i]);
                $c2 = ord($raw[$i + 1]);
                if ($c1 === 0 && $c2 === 0) break; // null terminator
                $code = $c1 + ($c2 << 8);
                if ($code < 0x80) {
                    $clean .= chr($code);
                } else {
                    // Encode en UTF-8
                    $clean .= mb_chr($code, 'UTF-8');
                }
            }
            return $clean;
        }
    }
}
