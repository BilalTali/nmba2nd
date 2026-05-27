<?php

namespace App\Services;

use App\Exceptions\PermanentSyncException;
use App\Exceptions\TransientSyncException;
use App\Models\Event;
use App\Services\Contracts\PortalSyncInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

use GuzzleHttp\Cookie\SetCookie;

class HttpPortalSyncService implements PortalSyncInterface
{
    protected string $baseUrl;
    protected string $loginUrl;
    protected string $submitUrl;
    protected string $dashboardUrl;
    protected string $username;
    protected string $password;

    /**
     * The session slot index (0–4) assigned to this service instance.
     * Each slot has its own isolated cookie jar and transmission lock,
     * allowing up to 5 completely independent portal sessions in parallel.
     */
    protected int $sessionSlot;

    /**
     * Pre-authenticated Guzzle client — set by ensureAuthenticated() and reused
     * by all sync() calls within the same SyncBatchJob execution cycle.
     * Null means no pre-authentication has been performed; sync() will
     * fall through to its own on-demand auth path.
     */
    protected ?Client $sharedClient = null;

    /**
     * The cookie jar backing $sharedClient. Held so it can be saved after each
     * successful submission and so that session cookies survive across the
     * entire 20-event batch.
     */
    protected ?CookieJar $sharedCookieJar = null;

    /**
     * The current CSRF token for the event submission form.
     * Refreshed after each successful login via ensureAuthenticated()
     * or mid-batch re-auth, and reused for every event in the batch.
     */
    protected ?string $submissionToken = null;

    /**
     * @param int $sessionSlot 0–7. Governs which cookie jar cache key and
     *                         which transmission lock this instance uses.
     *                         Defaults to 0 for backward compatibility with
     *                         SyncEventJob (single-event legacy path).
     */
    public function __construct(int $sessionSlot = 0)
    {
        $this->sessionSlot  = max(0, min(7, $sessionSlot)); // clamp to valid range
        $this->baseUrl      = rtrim((string) config('services.portal.url'), '/');
        $this->loginUrl     = $this->baseUrl . '/login';
        $this->submitUrl    = $this->baseUrl . '/event_create';
        $this->dashboardUrl = $this->baseUrl . '/dashboard';
        $this->username     = (string) config('services.portal.email');
        $this->password     = (string) config('services.portal.password');

        if (empty($this->username) || empty($this->password) || empty($this->baseUrl)) {
            throw new RuntimeException('Portal credentials or URL are missing from configuration.');
        }
    }

    // ── Session Slot Helpers ──────────────────────────────────────────────────

    /**
     * Cookie jar cache key for this slot.
     * Slot 0: 'sre_portal_session_slot_0'  (was: 'sre_portal_session_cookies')
     * Slot N: 'sre_portal_session_slot_N'
     */
    protected function cookieCacheKey(): string
    {
        return "sre_portal_session_slot_{$this->sessionSlot}";
    }

    /**
     * Transmission lock key for this slot — prevents two jobs from
     * POSTing on the same slot's session simultaneously.
     */
    protected function transmissionLockKey(): string
    {
        return "portal-sync-transmission-lock-slot-{$this->sessionSlot}";
    }

    // ── Cookie Jar Persistence ────────────────────────────────────────────────

    protected function loadCookieJar(): CookieJar
    {
        $cookieJar     = new CookieJar();
        $cachedCookies = Cache::get($this->cookieCacheKey());

        if (is_array($cachedCookies)) {
            foreach ($cachedCookies as $cookieArray) {
                if (isset($cookieArray['Name'], $cookieArray['Value'])) {
                    $cookieJar->setCookie(new SetCookie($cookieArray));
                }
            }
        }

        return $cookieJar;
    }

    protected function saveCookieJar(CookieJar $cookieJar): void
    {
        // 30-minute TTL — portal sessions typically expire in ~60 min.
        Cache::put($this->cookieCacheKey(), $cookieJar->toArray(), now()->addMinutes(30));
    }

    // ── Guzzle Client Factory ─────────────────────────────────────────────────

    protected function buildClient(CookieJar $cookieJar): Client
    {
        $isCli = (php_sapi_name() === 'cli');
        $timeout = $isCli ? 180 : 60; // 3 minutes on CLI, 60 seconds on Web SAPI (e.g. cron request)

        return new Client([
            'version'         => 2.0,
            'cookies'         => $cookieJar,
            'timeout'         => $timeout,
            'connect_timeout' => 15,  // Connect timeout of 15 seconds
            'read_timeout'    => $timeout, // Match timeout
            'allow_redirects' => ['max' => 10, 'strict' => false, 'track_redirects' => false],
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            ],
            'curl'            => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ]);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Establish (or reuse) an authenticated portal session for this slot.
     *
     * SyncBatchJob calls this ONCE before processing its 20 events.
     * After this method returns:
     *  - $this->sharedClient is ready for HTTP calls
     *  - $this->sharedCookieJar holds valid session cookies
     *  - $this->submissionToken holds the CSRF token for the event form
     *
     * If a valid session already exists in the cache the login handshake is
     * skipped and the cached cookies are reused — exactly like opening a
     * browser with a saved session cookie.
     *
     * @throws TransientSyncException              On network failures.
     * @throws \App\Exceptions\AuthenticationSyncException On bad credentials.
     */
    public function ensureAuthenticated(): void
    {
        $cookieJar = $this->loadCookieJar();
        $client    = $this->buildClient($cookieJar);

        // Probe the submission form — if we can see it, session is still alive.
        $token = $this->getSubmissionToken($client);

        if (!empty($token)) {
            Log::channel('sync')->info("Slot {$this->sessionSlot}: Reusing cached portal session.", [
                'slot' => $this->sessionSlot,
            ]);
        } else {
            // No active session — perform a full login for this slot.
            Log::channel('sync')->info("Slot {$this->sessionSlot}: No active session. Performing portal login.", [
                'slot' => $this->sessionSlot,
            ]);

            // Start fresh — discard any expired cookies.
            $cookieJar = new CookieJar();
            $client    = $this->buildClient($cookieJar);

            $loginToken = $this->executeLoginHandshake($client);
            $this->authenticateSession($client, $loginToken);
            $token      = $this->retrieveSubmissionToken($client, $loginToken);

            $this->saveCookieJar($cookieJar);

            Log::channel('sync')->info("Slot {$this->sessionSlot}: Portal login successful.", [
                'slot' => $this->sessionSlot,
            ]);
        }

        $this->sharedClient    = $client;
        $this->sharedCookieJar = $cookieJar;
        $this->submissionToken = $token;
    }

    /**
     * GET the login page and extract the initial CSRF token via multi-strategy fallback.
     */
    protected function executeLoginHandshake(Client $client): string
    {
        try {
            $response = $client->get($this->loginUrl);
        } catch (ConnectException $e) {
            throw new TransientSyncException(
                "Socket connection failed during login handshake: {$e->getMessage()}", 0, $e
            );
        }

        $crawler = new Crawler((string) $response->getBody());

        $token = null;

        if ($crawler->filter('input[name="_token"]')->count() > 0) {
            $token = $crawler->filter('input[name="_token"]')->attr('value');
        } elseif ($crawler->filter('meta[name="csrf-token"]')->count() > 0) {
            $token = $crawler->filter('meta[name="csrf-token"]')->attr('content');
        } elseif ($crawler->filter('input[name="csrf_token"]')->count() > 0) {
            $token = $crawler->filter('input[name="csrf_token"]')->attr('value');
        }

        if (empty($token)) {
            Log::channel('sync')->warning('CSRF handshake token extraction returned empty. Portal may not use CSRF. Proceeding without token.');
            $token = '';
        }

        return $token;
    }

    /**
     * POST credentials and verify an authenticated session was established.
     */
    protected function authenticateSession(Client $client, string $csrfToken): void
    {
        try {
            $postResponse = $client->post($this->baseUrl . '/authenticate', [
                'headers' => [
                    'Referer' => $this->loginUrl,
                ],
                'form_params' => [
                    '_token'   => $csrfToken,
                    'email'    => $this->username,
                    'password' => $this->password,
                ],
            ]);

            $dashHtml = strtolower((string) $postResponse->getBody());

            if (
                !str_contains($dashHtml, 'logout') &&
                !str_contains($dashHtml, 'sign out') &&
                !str_contains($dashHtml, 'dashboard')
            ) {
                // Determine if this is a standard redirect back to the login page (with password input field),
                // indicating a real credential failure, or a false-positive (e.g. Cloudflare interstitial,
                // maintenance page, rate limit, or server error).
                $crawler         = new Crawler((string) $postResponse->getBody());
                $hasPasswordField = $crawler->filter('input[type="password"]')->count() > 0;

                if (!$hasPasswordField) {
                    throw new TransientSyncException(
                        'Portal returned a non-login, non-dashboard page (likely Cloudflare challenge or transient server error). Treating as transient.'
                    );
                }

                throw new \App\Exceptions\AuthenticationSyncException(
                    'Invalid Portal Credentials! Please update the settings. Auto-sync is paused.'
                );
            }
        } catch (ConnectException $e) {
            throw new TransientSyncException(
                "Network loss during authentication transmission: {$e->getMessage()}", 0, $e
            );
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            throw new TransientSyncException(
                "Portal returned HTTP {$status} error during authentication: {$e->getMessage()}", 0, $e
            );
        }
    }

    /**
     * GET the event submission form page and extract the post-login CSRF token.
     * Falls back to the login token if the page cannot be reached.
     */
    protected function retrieveSubmissionToken(Client $client, string $fallbackToken): string
    {
        try {
            $response    = $client->get($this->submitUrl);
            $crawler     = new Crawler((string) $response->getBody());

            if ($crawler->filter('input[name="_token"]')->count() > 0) {
                return $crawler->filter('input[name="_token"]')->attr('value');
            }
            if ($crawler->filter('meta[name="csrf-token"]')->count() > 0) {
                return $crawler->filter('meta[name="csrf-token"]')->attr('content');
            }
        } catch (Exception $e) {
            Log::channel('sync')->warning('Post-login CSRF refresh failed; using fallback token.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $fallbackToken;
    }

    /**
     * Probe the submission form to check if the current session is still valid.
     * Returns null (not false) if the page is reachable but the user is not authenticated.
     */
    protected function getSubmissionToken(Client $client): ?string
    {
        try {
            $response = $client->get($this->submitUrl);
            $body     = (string) $response->getBody();
            $crawler  = new Crawler($body);

            if ($crawler->filter('input[name="event_name"]')->count() > 0) {
                if ($crawler->filter('input[name="_token"]')->count() > 0) {
                    return $crawler->filter('input[name="_token"]')->attr('value');
                }
                if ($crawler->filter('meta[name="csrf-token"]')->count() > 0) {
                    return $crawler->filter('meta[name="csrf-token"]')->attr('content');
                }
            }
        } catch (Exception $e) {
            Log::channel('sync')->warning('Session verification probe failed: ' . $e->getMessage());
        }
        return null;
    }

    // ── Main Sync Entry Point ─────────────────────────────────────────────────

    /**
     * Synchronize one event to the portal.
     *
     * In batch mode (called from SyncBatchJob):
     *   ensureAuthenticated() has already been called → $this->sharedClient
     *   and $this->submissionToken are populated. We skip the login handshake
     *   and jump straight to dispatchPayload().
     *
     *   If the portal returns a session-expiry signal mid-batch (login-page redirect),
     *   we catch TransientSyncException, re-authenticate on this slot, refresh the
     *   token, and retry the submission exactly once.
     *
     * In legacy single-event mode (called from SyncEventJob):
     *   $this->sharedClient is null. We fall through to the original on-demand
     *   session path using the slot-specific cookie cache.
     */
    public function sync(Event $event): bool
    {
        if ($event->sync_status === 'synced') {
            return true;
        }

        // ── Batch mode: pre-authenticated client already available ────────────
        if ($this->sharedClient !== null) {
            return $this->syncWithClient($this->sharedClient, $this->sharedCookieJar, $this->submissionToken, $event);
        }

        // ── Legacy / single-event mode: on-demand session ─────────────────────
        $cookieJar = $this->loadCookieJar();
        $client    = $this->buildClient($cookieJar);

        try {
            $submissionToken = $this->getSubmissionToken($client);

            if (empty($submissionToken)) {
                Log::channel('sync')->info("Slot {$this->sessionSlot}: No active portal session. Re-authenticating.");

                $cookieJar = new CookieJar();
                $client    = $this->buildClient($cookieJar);

                $loginToken      = $this->executeLoginHandshake($client);
                $this->authenticateSession($client, $loginToken);
                $submissionToken = $this->retrieveSubmissionToken($client, $loginToken);

                $this->saveCookieJar($cookieJar);
            } else {
                Log::channel('sync')->info("Slot {$this->sessionSlot}: Reusing active portal session.");
            }

            $success = $this->syncWithClient($client, $cookieJar, $submissionToken, $event);
            if ($success) {
                $this->saveCookieJar($cookieJar);
            }
            return $success;
        } finally {
            unset($client, $cookieJar);
        }
    }

    /**
     * Internal: performs dispatchPayload() with mid-session re-auth retry.
     *
     * If the portal returns a session-expired response (login-page redirect
     * wrapped as TransientSyncException), we re-login on this slot's client
     * and try once more. This handles the case where the portal's session
     * cookie expires mid-batch.
     */
    protected function syncWithClient(
        Client    $client,
        CookieJar $cookieJar,
        ?string   $submissionToken,
        Event     $event
    ): bool {
        try {
            return $this->dispatchPayload($client, $event, $submissionToken ?? '');
        } catch (TransientSyncException $e) {
            // Check if this looks like a session expiry (the portal redirected to the login page)
            if (str_contains($e->getMessage(), 'Session expired') || str_contains($e->getMessage(), 'Re-authentication required')) {
                Log::channel('sync')->warning("Slot {$this->sessionSlot}: Session expired mid-batch. Re-authenticating.", [
                    'slot'     => $this->sessionSlot,
                    'event_id' => $event->id,
                ]);

                // Re-authenticate on this slot's client
                $loginToken      = $this->executeLoginHandshake($client);
                $this->authenticateSession($client, $loginToken);
                $freshToken      = $this->retrieveSubmissionToken($client, $loginToken);

                // Update shared state for subsequent events in the batch
                $this->submissionToken = $freshToken;
                $this->saveCookieJar($cookieJar);

                // Single retry with fresh token
                return $this->dispatchPayload($client, $event, $freshToken);
            }

            // Not a session issue — re-throw for the caller to handle
            throw $e;
        }
    }

    // ── Payload Dispatch ──────────────────────────────────────────────────────

    /**
     * Build the multipart payload and POST it to the portal.
     * File handles are opened inside a try-finally to guarantee fclose() on all branches.
     *
     * Uses a per-slot transmission lock instead of the old global lock so that
     * different slots can submit simultaneously without serialization.
     */
    protected function dispatchPayload(Client $client, Event $event, string $submissionToken): bool
    {
        $streams = [];

        try {
            $multipart = [
                ['name' => '_token',                             'contents' => $submissionToken],
                ['name' => 'event_name',                        'contents' => $event->event_name],
                ['name' => 'event_date',                        'contents' => $event->event_date->format('d-m-Y')],
                ['name' => 'event_venue',                       'contents' => $event->event_venue],
                ['name' => 'district',                          'contents' => config('app.district_name')],
                ['name' => 'block',                             'contents' => (string) $event->block_id],
                ['name' => 'ward',                              'contents' => (string) ($event->ward ?? '')],
                ['name' => 'village',                           'contents' => (string) ($event->village ?? '')],
                ['name' => 'event_category_remark',             'contents' => (string) ($event->event_category_remark ?? '')],
                ['name' => 'attendance_range',                  'contents' => $event->attendance_range],
                ['name' => 'actual_attendance',                 'contents' => (string) $event->actual_attendance],
                ['name' => 'event_coordinator_name',            'contents' => $event->event_coordinator_name],
                ['name' => 'event_coordinator_contact_number',  'contents' => $event->event_coordinator_contact_number],
                ['name' => 'event_coordinator_desig',           'contents' => $event->event_coordinator_desig],
            ];

            foreach ($event->event_category as $cat) {
                $multipart[] = ['name' => 'event_category[]', 'contents' => $cat];
            }
            foreach ($event->target_audience as $aud) {
                $multipart[] = ['name' => 'target_audience[]', 'contents' => $aud];
            }
            foreach ($event->age_group as $age) {
                $multipart[] = ['name' => 'age_group[]', 'contents' => $age];
            }

            foreach ($event->photo_paths as $path) {
                $fullPath = Storage::disk('public')->path($path);

                if (!file_exists($fullPath)) {
                    throw new PermanentSyncException(
                        "Required photo asset missing from storage: {$path}"
                    );
                }

                $handle = fopen($fullPath, 'r');
                if ($handle === false) {
                    throw new PermanentSyncException(
                        "Cannot open file handle for asset: {$fullPath}"
                    );
                }

                $streams[]   = $handle;
                $multipart[] = [
                    'name'     => 'event_photos[]',
                    'contents' => $handle,
                    'filename' => basename($fullPath),
                ];
            }

            // Jitter delay proportional to attempt count to spread out retry storms.
            $jitterBase = min(5, $event->sync_attempts + 1);
            usleep(random_int(500_000 * $jitterBase, 1_500_000 * $jitterBase));

            // Per-slot transmission lock — different slots can run simultaneously.
            try {
                return Cache::lock($this->transmissionLockKey(), 60)->block(
                    5,
                    function () use ($client, $multipart) {
                        $response = $client->post($this->submitUrl, ['multipart' => $multipart]);
                        return $this->evaluateResponse(
                            $response->getStatusCode(),
                            (string) $response->getBody()
                        );
                    }
                );
            } catch (ConnectException $e) {
                throw new TransientSyncException(
                    "Connection lost during multipart upload: {$e->getMessage()}", 0, $e
                );
            } catch (RequestException $e) {
                $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
                if ($status >= 500) {
                    throw new TransientSyncException(
                        "Downstream server error HTTP {$status}.", 0, $e
                    );
                }
                throw new PermanentSyncException(
                    "Portal rejected submission with HTTP {$status}.", 0, $e
                );
            }

        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    // ── Response Evaluation ───────────────────────────────────────────────────

    /**
     * Analyse the portal response to determine success, session expiry, or validation failure.
     */
    protected function evaluateResponse(int $statusCode, string $body): bool
    {
        $lower   = strtolower($body);
        $crawler = new Crawler($body);

        // Detect mid-flight session expiry — portal redirected back to login page.
        if (
            str_contains($body, 'name="password"') ||
            str_contains($lower, 'type="password"') ||
            str_contains($lower, 'login-box') ||
            str_contains($lower, 'sign in')
        ) {
            throw new TransientSyncException(
                'Session expired mid-request. Portal redirected to login page. Re-authentication required.'
            );
        }

        // Detect silent validation rejection banners embedded in 200 responses.
        $errorNodes = $crawler->filter('.alert-danger, .error-message, .validation-errors, #error-container');
        if ($errorNodes->count() > 0) {
            throw new PermanentSyncException(
                'Portal returned a validation error banner: ' . trim($errorNodes->first()->text())
            );
        }

        // Detect downstream server exceptions leaked into the response body.
        if (str_contains($lower, 'sql error') || str_contains($lower, 'exception triggered')) {
            throw new TransientSyncException(
                'Downstream server exception detected in response body. Backing off.'
            );
        }

        // HTTP 302 redirect is typically a successful form submission.
        if ($statusCode === 302) {
            if (str_contains($lower, 'error') || str_contains($lower, 'invalid')) {
                throw new PermanentSyncException(
                    'Portal issued a 302 redirect containing error indicators.'
                );
            }
            return true;
        }

        // HTTP 200: scan for known success keywords.
        if ($statusCode === 200) {
            $successKeywords = ['success', 'saved successfully', 'record added', 'created successfully', 'activity logged'];
            foreach ($successKeywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }
}
