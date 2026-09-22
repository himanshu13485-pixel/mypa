<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The second copy, somewhere the company owns.
 *
 * The archive on this server is the first copy and the fast one, but a
 * backup that lives on the same machine as the thing it backs up is not
 * really a backup. This pushes the same .eml files somewhere else:
 *
 *   s3      any S3-compatible bucket - AWS, Wasabi, DigitalOcean Spaces,
 *           Cloudflare R2, MinIO on your own hardware
 *   webdav  Nextcloud, ownCloud, or any WebDAV share
 *   gdrive  Google Drive or a Shared Drive, through a service account
 *
 * Signed and uploaded with plain HTTP, so nothing here needs a vendor SDK
 * installed on the server to keep working.
 *
 * Whatever the destination, the files are the same files: the company can
 * read their own backup without this application, which is the only kind of
 * backup worth having.
 */
class MailVault
{
    public const DRIVERS = ['s3', 'webdav', 'gdrive'];

    /** Copy everything in the mailbox's archive folder that is not there yet. */
    public function push(MailAccount $account, MailArchive $archive): array
    {
        $settings = (array) ($account->backup ?? []);
        $remote = (array) ($settings['remote'] ?? []);

        if (empty($remote['enabled']) || ! in_array($remote['driver'] ?? '', self::DRIVERS, true)) {
            return ['sent' => 0, 'bytes' => 0, 'skipped' => true];
        }

        $disk = Storage::disk('local');
        $folder = $archive->folder($account);
        $done = collect((array) ($settings['remote_done'] ?? []))->flip();

        $sent = 0;
        $bytes = 0;
        foreach ($disk->allFiles($folder) as $file) {
            $relative = Str::after($file, $folder . '/');
            // The index is not one of the messages - it goes up whole, below.
            if ($relative === 'index.jsonl' || $done->has($relative)) {
                continue;
            }
            $body = (string) $disk->get($file);
            $this->put($remote, $this->prefix($remote, $account) . '/' . $relative, $body, $this->typeOf($relative));
            $done[$relative] = true;
            $sent++;
            $bytes += strlen($body);
        }

        // The index goes up every time: it is small, and it is what makes the
        // copy readable on its own.
        if ($disk->exists($folder . '/index.jsonl')) {
            $this->put($remote, $this->prefix($remote, $account) . '/index.jsonl', (string) $disk->get($folder . '/index.jsonl'), 'application/x-ndjson');
        }

        $account->forceFill(['backup' => array_merge($settings, [
            'remote_done' => $done->keys()->all(),
            'remote_last_run_at' => now()->toIso8601String(),
            'remote_last_error' => null,
        ])])->save();

        return ['sent' => $sent, 'bytes' => $bytes, 'skipped' => false];
    }

    /** Write one small file, so somebody can see the connection works. */
    public function test(array $remote, string $label = 'netvork'): array
    {
        try {
            $name = 'netvork-mails-test-' . now()->format('Ymd-His') . '.txt';
            $where = trim((string) ($remote['path'] ?? ''), '/');
            $this->put($remote, ($where ? $where . '/' : '') . $name, "Netvork Mails could write here.\n" . now()->toDayDateTimeString() . "\n", 'text/plain');

            return ['ok' => true, 'message' => 'Wrote a test file. This destination is ready.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->plain($e)];
        }
    }

    /** Where this mailbox's copy lives inside the destination. */
    private function prefix(array $remote, MailAccount $account): string
    {
        $base = trim((string) ($remote['path'] ?? ''), '/');
        $box = Str::slug(str_replace(['@', '.'], ['-at-', '-'], (string) $account->email));

        return ($base ? $base . '/' : '') . 'mail-archive/' . $box;
    }

    private function typeOf(string $file): string
    {
        return str_ends_with($file, '.eml') ? 'message/rfc822' : 'application/octet-stream';
    }

    private function put(array $remote, string $path, string $body, string $type): void
    {
        match ($remote['driver']) {
            's3' => $this->putS3($remote, $path, $body, $type),
            'webdav' => $this->putWebdav($remote, $path, $body, $type),
            'gdrive' => $this->putDrive($remote, $path, $body, $type),
            default => throw new \RuntimeException('Unknown backup destination.'),
        };
    }

    // ---- S3 and anything that speaks its language -------------------------------------

    /**
     * A signed PUT, Signature Version 4.
     *
     * Written out rather than pulled in from an SDK: it is one request with
     * one header, and the whole of it is below.
     */
    private function putS3(array $remote, string $path, string $body, string $type): void
    {
        $region = (string) ($remote['region'] ?? 'us-east-1');
        $bucket = trim((string) ($remote['bucket'] ?? ''), '/');
        $endpoint = rtrim((string) ($remote['endpoint'] ?? "https://s3.{$region}.amazonaws.com"), '/');
        $key = (string) ($remote['key'] ?? '');
        $secret = (string) ($remote['secret'] ?? '');

        throw_if($bucket === '' || $key === '' || $secret === '', new \RuntimeException('The bucket, key and secret are all needed.'));

        // Path-style addressing works everywhere, including MinIO on a plain host.
        $host = parse_url($endpoint, PHP_URL_HOST) . (parse_url($endpoint, PHP_URL_PORT) ? ':' . parse_url($endpoint, PHP_URL_PORT) : '');
        $canonicalUri = '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $url = $endpoint . $canonicalUri;

        $now = gmdate('Ymd\THis\Z');
        $day = substr($now, 0, 8);
        $hash = hash('sha256', $body);

        $headers = [
            'content-type' => $type,
            'host' => $host,
            'x-amz-content-sha256' => $hash,
            'x-amz-date' => $now,
        ];
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hash}";
        $scope = "{$day}/{$region}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signing = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $day, 'AWS4' . $secret, true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $signing);

        $response = Http::withHeaders($headers + [
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ])->withBody($body, $type)->timeout(60)->put($url);

        throw_unless($response->successful(), new \RuntimeException('The bucket refused the upload: ' . $response->status() . ' ' . Str::limit(strip_tags($response->body()), 200)));
    }

    // ---- WebDAV: Nextcloud, ownCloud, and the rest ------------------------------------

    private function putWebdav(array $remote, string $path, string $body, string $type): void
    {
        $base = rtrim((string) ($remote['url'] ?? ''), '/');
        throw_if($base === '', new \RuntimeException('The WebDAV address is needed.'));

        $client = $this->webdavClient($remote);
        $segments = explode('/', ltrim($path, '/'));
        $file = array_pop($segments);

        // WebDAV will not create a folder tree for you, so each level is made
        // in turn. A folder that is already there answers 405, which is fine.
        $walked = '';
        foreach ($segments as $segment) {
            $walked .= '/' . rawurlencode($segment);
            $client->send('MKCOL', $base . $walked);
        }

        $response = $client->withBody($body, $type)->put($base . $walked . '/' . rawurlencode($file));
        throw_unless($response->successful(), new \RuntimeException('The WebDAV server refused the upload: ' . $response->status()));
    }

    private function webdavClient(array $remote): PendingRequest
    {
        return Http::withBasicAuth((string) ($remote['username'] ?? ''), (string) ($remote['password'] ?? ''))
            ->withOptions(['allow_redirects' => true])->timeout(60);
    }

    // ---- Google Drive, through a service account ---------------------------------------

    /**
     * Drive without a sign-in dance.
     *
     * The company makes a service account in Google Cloud, downloads its JSON
     * key, and shares a Drive folder with the address inside it. We sign our
     * own token with that key, which means no consent screen, no refresh
     * tokens, and nothing to re-authorise when somebody leaves.
     */
    private function putDrive(array $remote, string $path, string $body, string $type): void
    {
        $token = $this->driveToken($remote);
        $parent = (string) ($remote['folder_id'] ?? '');
        throw_if($parent === '', new \RuntimeException('The Drive folder id is needed - share a folder with the service account and paste its id.'));

        // Drive has no paths, only folders, so the archive's path becomes the
        // file's name. One flat folder, names that sort.
        $name = str_replace('/', '__', $path);

        $boundary = 'netvork' . Str::random(16);
        $metadata = json_encode(['name' => $name, 'parents' => [$parent]]);
        $multipart = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            . "--{$boundary}\r\nContent-Type: {$type}\r\n\r\n{$body}\r\n--{$boundary}--";

        $response = Http::withToken($token)
            ->withBody($multipart, "multipart/related; boundary={$boundary}")
            ->timeout(120)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true');

        throw_unless($response->successful(), new \RuntimeException('Google Drive refused the upload: ' . Str::limit($response->body(), 200)));
    }

    private function driveToken(array $remote): string
    {
        $json = $remote['service_account'] ?? null;
        $credentials = is_array($json) ? $json : json_decode((string) $json, true);
        throw_if(! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key']),
            new \RuntimeException('That does not look like a service account key file.'));

        $now = time();
        $claim = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $segments = [$this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), $this->base64url(json_encode($claim))];
        $signature = '';
        openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], 'sha256WithRSAEncryption');
        $jwt = implode('.', [...$segments, $this->base64url($signature)]);

        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        throw_unless($response->successful(), new \RuntimeException('Google refused the service account: ' . Str::limit($response->body(), 200)));

        return (string) $response->json('access_token');
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function plain(Throwable $e): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 300);
    }
}
