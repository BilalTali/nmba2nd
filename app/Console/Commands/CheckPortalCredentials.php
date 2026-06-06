<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;
use App\Traits\SharedCacheTrait;

/**
 * FIX-SEC-02: Portal credential health-check command.
 *
 * Attempts a real authentication against the portal using credentials
 * stored in .env. Reports success/failure and logs the attempt.
 *
 * Usage:
 *   php artisan portal:check-credentials
 *
 * Scheduled: weekly (see App\Console\Kernel)
 */
class CheckPortalCredentials extends Command
{
    use SharedCacheTrait;

    protected $signature = 'portal:check-credentials
                            {--quiet-on-success : Suppress output if credentials are valid}';

    protected $description = 'Test portal authentication credentials and log the result';

    public function handle(): int
    {
        $url      = rtrim((string) config('services.portal.url'), '/');
        $email    = (string) config('services.portal.email');
        $password = (string) config('services.portal.password');

        if (empty($url) || empty($email) || empty($password)) {
            $this->error('PORTAL_URL, PORTAL_EMAIL, or PORTAL_PASSWORD is missing from .env');
            $this->writeLog('ERROR', 'Missing credentials in .env — test skipped.');
            return self::FAILURE;
        }

        $loginUrl = $url;
        $authUrl  = $url . '/authenticate';

        $this->line("Testing portal credentials for <info>{$email}</info> at <info>{$url}</info>...");

        try {
            $cookieJar = new CookieJar();
            $client = app()->bound(Client::class) ? app(Client::class) : new Client([
                'cookies'         => $cookieJar,
                'timeout'         => 15,
                'connect_timeout' => 10,
                'allow_redirects' => ['max' => 10, 'strict' => false],
                'headers'         => [
                    'User-Agent' => 'Mozilla/5.0 (NMBA-CredentialCheck/1.0)',
                ],
                'curl'            => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);

            // Step 1: GET login page and extract CSRF token
            $loginResponse = $client->get($loginUrl);
            $crawler = new Crawler((string) $loginResponse->getBody());

            $csrfToken = '';
            if ($crawler->filter('input[name="_token"]')->count() > 0) {
                $csrfToken = $crawler->filter('input[name="_token"]')->attr('value') ?? '';
            } elseif ($crawler->filter('meta[name="csrf-token"]')->count() > 0) {
                $csrfToken = $crawler->filter('meta[name="csrf-token"]')->attr('content') ?? '';
            }

            // Step 2: POST credentials
            $authResponse = $client->post($authUrl, [
                'headers'     => ['Referer' => $loginUrl],
                'form_params' => [
                    '_token'   => $csrfToken,
                    'email'    => $email,
                    'password' => $password,
                ],
            ]);

            $body  = strtolower((string) $authResponse->getBody());
            $isAuth = str_contains($body, 'logout')
                   || str_contains($body, 'sign out')
                   || str_contains($body, 'dashboard');

            if ($isAuth) {
                $message = "SUCCESS — Portal credentials are valid. ({$email})";
                $this->writeLog('SUCCESS', $message);
                $this->forgetSharedValue('portal_credentials_invalid');
                $this->forgetSharedValue('sre_consecutive_auth_failures');

                if (!$this->option('quiet-on-success')) {
                    $this->info("✓ {$message}");
                }
                return self::SUCCESS;
            }

            // Authentication did not produce an authenticated session
            $message = "FAILURE — Portal rejected credentials. ({$email}) — Portal may have changed or password is wrong.";
            $this->writeLog('FAILURE', $message);
            $this->setSharedValue('portal_credentials_invalid', true, 7200);
            $this->setSharedValue('auto_sync_paused', true, 7200);
            $this->error("✗ {$message}");
            return self::FAILURE;

        } catch (\Exception $e) {
            $message = "ERROR — Could not reach portal: {$e->getMessage()}";
            $this->writeLog('ERROR', $message);
            $this->error("✗ {$message}");
            return self::FAILURE;
        }
    }

    /**
     * Append a structured log entry to credential-checks.log.
     */
    private function writeLog(string $outcome, string $message): void
    {
        $logPath = storage_path('logs/credential-checks.log');
        $line    = '[' . now()->toDateTimeString() . '] [' . $outcome . '] ' . $message . PHP_EOL;
        file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);

        // Also send to the Laravel log channel for centralised visibility
        match ($outcome) {
            'SUCCESS' => Log::channel('sync')->info($message),
            'FAILURE' => Log::channel('sync')->warning($message),
            default   => Log::channel('sync')->error($message),
        };
    }
}
