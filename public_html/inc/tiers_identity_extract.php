<?php
/**
 * inc/tiers_identity_extract.php — Extraction structurée d'identité (société KBIS / personne CNI)
 * depuis un fichier, RÉUTILISABLE côté serveur (FluxBox auto-commit, batch…).
 *
 * La cascade de nommage FluxBox ne capte QUE les champs de nommage (type, date, immeuble…),
 * jamais siren/raison/date_naissance. Cette fonction lance une extraction IA dédiée (mêmes
 * champs que api/bail_candidat_extract.php) sur un chemin de fichier local (PDF ou image).
 *
 * tiers_identity_extract_from_file(string $path): ?array
 *   → { type, raison_sociale, forme_juridique, siren, capital_social, nom, prenom,
 *       representant_nom, representant_qualite, adresse, date_naissance, nationalite, … } | null
 *
 * ⚠️ Coût IA : 1 appel OpenAI. L'APPELANT est responsable de ne l'invoquer que si utile
 * (fiche tiers incomplète). Voir tiers_identity_should_extract().
 */
declare(strict_types=1);

require_once __DIR__ . '/ia_analyse.php'; // extractPdfText()

if (!function_exists('tiers_identity_should_extract')) {
    /**
     * La fiche tiers a-t-elle encore quelque chose à compléter pour ce type de doc ?
     * Évite de payer une extraction IA quand tout est déjà renseigné.
     * @param string $kind 'societe' | 'physique'
     */
    function tiers_identity_should_extract(PDO $pdo, int $tiersId, string $kind): bool
    {
        if ($tiersId <= 0) return false;
        try {
            if ($kind === 'societe') {
                $st = $pdo->prepare("SELECT raison_sociale, siren, forme_juridique, infos_juridiques_json FROM tiers WHERE id=? LIMIT 1");
                $st->execute([$tiersId]); $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) return false;
                return empty($r['raison_sociale']) || empty($r['siren']) || empty($r['forme_juridique']) || empty($r['infos_juridiques_json']);
            }
            $st = $pdo->prepare("SELECT nom, prenom, date_naissance, nationalite FROM tiers WHERE id=? LIMIT 1");
            $st->execute([$tiersId]); $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return false;
            return empty($r['nom']) || empty($r['prenom']) || empty($r['date_naissance']) || empty($r['nationalite']);
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('tiers_identity_extract_from_file')) {
    /**
     * @param string $path      chemin absolu local (PDF / image)
     * @param string $hintType  'societe' | 'physique' | '' — indice pour orienter l'IA (facultatif)
     * @return array|null champs extraits, ou null si illisible / erreur.
     */
    function tiers_identity_extract_from_file(string $path, string $hintType = ''): ?array
    {
        if ($path === '' || !is_file($path)) return null;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $IMG = ['jpg','jpeg','png','webp','gif','bmp','heic','heif'];
        $IMG_MIME = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','bmp'=>'image/bmp','heic'=>'image/heic','heif'=>'image/heif'];

        $texte = ''; $images = [];
        if ($ext === 'pdf' && function_exists('extractPdfText')) {
            $t = (string)extractPdfText($path);
            if (strlen(trim($t)) >= 60) $texte = $t;
        } elseif (in_array($ext, $IMG, true)) {
            $bytes = @file_get_contents($path);
            if ($bytes !== false && strlen($bytes) <= 12 * 1024 * 1024) {
                $images[] = 'data:' . ($IMG_MIME[$ext] ?? 'image/jpeg') . ';base64,' . base64_encode($bytes);
            }
        }
        if (trim($texte) === '' && !$images) return null;

        $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
        if (!$api_key) return null;

        $texte = mb_substr($texte, 0, 40000);
        $hint = $hintType === 'societe' ? "\nCe document est un extrait KBIS / document de société : renseigne type='societe'."
              : ($hintType === 'physique' ? "\nCe document est une pièce d'identité (CNI/passeport) : renseigne type='physique'." : '');
        $system = "Tu es un assistant juridique. À partir d'un document (extrait KBIS, pièce d'identité…), tu extrais l'identité. Réponds UNIQUEMENT en JSON valide.";
        $user = <<<PROMPT
Extrais l'identité au format JSON strict :
{
  "type": "societe|physique",
  "raison_sociale": "string ou null",
  "forme_juridique": "string ou null (SAS, SARL, SASU…)",
  "siren": "string ou null (9 chiffres)",
  "capital_social": "number ou null",
  "nom": "string ou null (si personne physique)",
  "prenom": "string ou null",
  "representant_nom": "string ou null (dirigeant / gérant / président)",
  "representant_qualite": "string ou null (Gérant, Président…)",
  "adresse": "string ou null (siège si société, domicile si personne)",
  "date_naissance": "string ou null (AAAA-MM-JJ)",
  "lieu_naissance": "string ou null",
  "nationalite": "string ou null"
}
Règles : info absente → null. Montants en euros sans symbole. Dates AAAA-MM-JJ.{$hint}

DOCUMENT (texte) :
{$texte}
PROMPT;

        $userContent = [['type'=>'text','text'=>$user]];
        foreach ($images as $d) $userContent[] = ['type'=>'image_url','image_url'=>['url'=>$d, 'detail'=>'high']];
        $payload = ['model'=>'gpt-4o-mini','messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$userContent]],
            'response_format'=>['type'=>'json_object'],'max_tokens'=>1200,'temperature'=>0.1];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$api_key],
            CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_TIMEOUT=>90]);
        $resp = curl_exec($ch); $code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200) return null;
        $res = json_decode((string)$resp, true);
        $content = $res['choices'][0]['message']['content'] ?? '';
        $fields = json_decode((string)$content, true);
        if (!$fields && preg_match('/\{[\s\S]*\}/u', (string)$content, $m)) $fields = json_decode($m[0], true);
        return is_array($fields) ? $fields : null;
    }
}
