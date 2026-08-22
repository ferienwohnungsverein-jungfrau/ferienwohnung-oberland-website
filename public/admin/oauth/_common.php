<?php
/**
 * GitHub-OAuth-Vermittler für Sveltia CMS (Redaktion des Vereins).
 *
 * Sveltia braucht einen winzigen Server-Teil, der das GitHub-Client-Secret
 * kennt und den OAuth-Code gegen ein Token tauscht. Dieser Teil läuft hier
 * auf dem Schweizer hosttech-Server (statt auf Cloudflare Workers).
 *
 * Zugangsdaten liegen NICHT im Repo, sondern in der Datei
 *   github-oauth.key   eine Ebene über httpdocs  (übersteht Deploys)
 * mit genau zwei Zeilen:
 *   CLIENT_ID=Iv1.xxxxxxxx
 *   CLIENT_SECRET=xxxxxxxxxxxxxxxx
 * Alternativ: Umgebungsvariablen GITHUB_OAUTH_CLIENT_ID / GITHUB_OAUTH_CLIENT_SECRET.
 */
declare(strict_types=1);

const FVJ_ALLOWED_ORIGINS = [
    'https://login.ferienwohnungsverein-jungfrau.ch',
    'https://ferienwohnungsverein-jungfrau.ch',
];

function fvj_oauth_credentials(): array
{
    $id = getenv('GITHUB_OAUTH_CLIENT_ID') ?: '';
    $secret = getenv('GITHUB_OAUTH_CLIENT_SECRET') ?: '';
    if ($id === '' || $secret === '') {
        // httpdocs/admin/oauth → drei Ebenen hoch = neben httpdocs
        $file = dirname(__DIR__, 3) . '/github-oauth.key';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with($line, 'CLIENT_ID=')) {
                    $id = trim(substr($line, 10));
                } elseif (str_starts_with($line, 'CLIENT_SECRET=')) {
                    $secret = trim(substr($line, 14));
                }
            }
        }
    }
    if ($id === '' || $secret === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Redaktion noch nicht freigeschaltet: github-oauth.key fehlt auf dem Server.\n");
    }
    return [$id, $secret];
}

function fvj_callback_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'login.ferienwohnungsverein-jungfrau.ch';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/oauth/x'), '/');
    return 'https://' . $host . $path . '/callback.php';
}

function fvj_no_cache(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
}
