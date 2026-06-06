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

use App\Traits\SharedCacheTrait;

class HttpPortalSyncService implements PortalSyncInterface
{
    use SharedCacheTrait;

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
        $this->loginUrl     = $this->baseUrl;
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
        $cookieJar = new CookieJar();
        $sharedPath = $this->getSharedPath("cookies_slot_{$this->sessionSlot}.json");

        if ($sharedPath && file_exists($sharedPath)) {
            $content = @file_get_contents($sharedPath);
            $cachedCookies = json_decode($content, true);
        } else {
            $cachedCookies = Cache::get($this->cookieCacheKey());
        }

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
        $cookieArray = $cookieJar->toArray();
        $sharedPath = $this->getSharedPath("cookies_slot_{$this->sessionSlot}.json");

        if ($sharedPath) {
            @file_put_contents($sharedPath, json_encode($cookieArray));
        }

        // 30-minute TTL — portal sessions typically expire in ~60 min.
        Cache::put($this->cookieCacheKey(), $cookieArray, now()->addMinutes(30));
    }

    // ── Guzzle Client Factory ─────────────────────────────────────────────────

    protected function buildClient(CookieJar $cookieJar): Client
    {
        // Fetch the last known response time of the portal.
        // Default 55s — the portal is observed to take 47-50s under load.
        $lastResponseTime = (float) Cache::get('sre_portal_response_time', 55.0);

        // BN-2 FIX: Floor reduced 55 → 35s.
        // Rationale: as the portal stabilises and sre_portal_response_time
        // reflects real observed times, this allows the calculated timeout to
        // shrink proportionally instead of being artificially floored at 120s.
        $effectiveResponseTime = max(35.0, $lastResponseTime);

        // Dynamic timeout: response time × 3, clamped to [105s, 150s].
        // 105s floor: gives a 70s margin above a 35s baseline response.
        // 150s ceiling: prevents a single request holding up the job too long.
        $calculatedTimeout = (int) ceil($effectiveResponseTime * 3);
        $timeout = max(105, min(150, $calculatedTimeout));

        return new Client([
            'version'         => 2.0,
            'cookies'         => $cookieJar,
            'timeout'         => $timeout,
            // BN-2 FIX: connect_timeout reduced 45s → 10s.
            // TCP connection either completes in < 5s or the host is unreachable.
            // A 45s connect stall wastes the entire scheduler tick with no benefit.
            'connect_timeout' => 10,
            'read_timeout'    => $timeout,
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

    /**
     * Return the age in seconds of the cookie file for this slot, or null if missing.
     * Used to decide whether to skip the HTTP session probe.
     */
    protected function getCookieFileAgeSeconds(): ?int
    {
        $sharedPath = $this->getSharedPath("cookies_slot_{$this->sessionSlot}.json");
        if ($sharedPath && file_exists($sharedPath)) {
            $mtime = @filemtime($sharedPath);
            if ($mtime !== false) {
                return max(0, time() - $mtime);
            }
        }
        return null;
    }

    /**
     * Return true only when the cookie file for this slot contains at least one
     * real, unexpired session cookie.
     *
     * Instead of relying on file-modification time (which drifts from the actual
     * session expiry), we read the cookie's own Expires field and verify it is
     * still in the future with a 60-second safety buffer.
     */
    protected function hasFreshSessionCookies(): bool
    {
        // Try the shared-sync file first, fall back to Laravel cache
        $sharedPath = $this->getSharedPath("cookies_slot_{$this->sessionSlot}.json");
        $content = null;

        if ($sharedPath && file_exists($sharedPath)) {
            $content = @file_get_contents($sharedPath);
        }

        if ($content === null || $content === false || $content === '') {
            $cached = Cache::get($this->cookieCacheKey());
            if (!is_array($cached) || empty($cached)) {
                return false;
            }
            $cookies = $cached;
        } else {
            $cookies = json_decode($content, true);
            if (!is_array($cookies) || empty($cookies)) {
                return false;
            }
        }

        $now = time();
        foreach ($cookies as $c) {
            if (empty($c['Value'])) {
                continue; // skip cookies with empty values
            }

            // If the cookie has an explicit Expires timestamp, honour it.
            // Give a 60-second buffer so we don't use a cookie that's about to expire.
            if (!empty($c['Expires'])) {
                if ((int) $c['Expires'] > ($now + 60)) {
                    return true; // valid, unexpired cookie found
                }
                // else: this cookie is expired or expiring — keep scanning
                continue;
            }

            // No Expires field (session cookie) — trust the file mtime as a proxy.
            // Session cookies typically last 60 min; use a 50-minute threshold.
            $ageSeconds = $this->getCookieFileAgeSeconds();
            if ($ageSeconds !== null && $ageSeconds < 3000) {
                return true;
            }
        }

        return false;
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

        // ── Fast path: cookie-age check (no HTTP round-trip) ─────────────────
        //
        // The portal takes 47-55s to respond under load. An HTTP session probe
        // against /event_create would burn a full 60-120s just to tell us
        // "yes, cookies work" — costing more than an actual login.
        //
        // Instead: if the cookie file for this slot was saved < 25 minutes ago
        // AND contains at least one real session cookie, assume the session is
        // still alive and skip the full login handshake. The submission itself
        // will confirm validity; mid-batch re-auth handles the expiry case.
        if ($this->hasFreshSessionCookies()) {
            Log::channel('sync')->info("Slot {$this->sessionSlot}: Fresh cookies found — skipping login probe.", [
                'slot'            => $this->sessionSlot,
                'cookie_age_secs' => $this->getCookieFileAgeSeconds(),
            ]);

            // We still need a CSRF token. Use the cached cookie jar; if the
            // token is missing the portal either doesn't require one or we'll
            // get a 419 that triggers a re-auth on the first submission attempt.
            // null = "not yet fetched"; will be fetched before the first event.
            $this->sharedClient    = $client;
            $this->sharedCookieJar = $cookieJar;
            $this->submissionToken = null; // Fetched lazily on first event
            return;
        }

        // ── Slow path: fresh login required ──────────────────────────────────
        Log::channel('sync')->info("Slot {$this->sessionSlot}: No fresh session cookies — performing portal login.", [
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
            app(\App\Services\PortalHealthService::class)->tripCircuitBreaker("Socket connection failed during login handshake: {$e->getMessage()}");
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
            app(\App\Services\PortalHealthService::class)->tripCircuitBreaker("Network loss during authentication transmission: {$e->getMessage()}");
            throw new TransientSyncException(
                "Network loss during authentication transmission: {$e->getMessage()}", 0, $e
            );
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            if ($status >= 500) {
                app(\App\Services\PortalHealthService::class)->tripCircuitBreaker("Portal returned HTTP {$status} error during authentication.");
            }
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

        // --- Confirm target site is alive before attempting sync ---
        $healthService = app(\App\Services\PortalHealthService::class);
        if (!$healthService->isAlive()) {
            throw new TransientSyncException('Portal health check failed before sync. Target site is down or circuit breaker is active.');
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
        // If we skipped login via the fast-path cookie check, $submissionToken is null
        // ("not yet fetched"). Fetch it exactly ONCE here — subsequent events in the
        // batch will reuse $this->submissionToken (even if it's an empty string,
        // meaning the portal has no CSRF requirement on the event form).
        if ($submissionToken === null) {
            Log::channel('sync')->info("Slot {$this->sessionSlot}: Fetching submission CSRF token (first event in batch).", [
                'slot'     => $this->sessionSlot,
                'event_id' => $event->id,
            ]);

            // Use a SHORT-timeout probe client (30s) rather than the full batch
            // client (120s). The portal doesn't require CSRF on the event form,
            // so a timeout here just means we proceed with an empty token — safe.
            $probeClient = new Client([
                'version'         => 2.0,
                'cookies'         => $cookieJar,
                'timeout'         => 30,
                'connect_timeout' => 15,
                'allow_redirects' => ['max' => 5, 'strict' => false],
                'headers'         => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ],
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ]);

            $submissionToken = $this->retrieveSubmissionToken($probeClient, '');
            $this->submissionToken = $submissionToken; // '' is fine — portal accepts without CSRF
        }

        try {
            return $this->dispatchPayload($client, $event, $submissionToken);
        } catch (TransientSyncException $e) {
            // Check if this looks like a session expiry (the portal redirected to the login page)
            if (
                str_contains($e->getMessage(), 'Session expired') ||
                str_contains($e->getMessage(), 'Re-authentication required') ||
                str_contains($e->getMessage(), 'login page')
            ) {
                Log::channel('sync')->warning("Slot {$this->sessionSlot}: Session expired mid-batch. Re-authenticating.", [
                    'slot'     => $this->sessionSlot,
                    'event_id' => $event->id,
                ]);

                // Re-authenticate on this slot's client
                $loginToken  = $this->executeLoginHandshake($client);
                $this->authenticateSession($client, $loginToken);
                $freshToken  = $this->retrieveSubmissionToken($client, $loginToken);

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

            $updatedPaths = [];
            $pathChanged = false;

            foreach ($event->photo_paths as $path) {
                $fullPath = Storage::disk('public')->path($path);

                if (!file_exists($fullPath)) {
                    // Try fallback to the 'synced' directory
                    if (!str_contains($path, 'events/synced/')) {
                        $fallbackPath = str_replace('events/', 'events/synced/', $path);
                        $fallbackFullPath = Storage::disk('public')->path($fallbackPath);
                        if (file_exists($fallbackFullPath)) {
                            $path = $fallbackPath;
                            $fullPath = $fallbackFullPath;
                            $pathChanged = true;
                        }
                    }
                }

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
                $updatedPaths[] = $path;
            }

            // Save the updated paths if any fallback occurred
            if ($pathChanged) {
                $event->photo_paths = $updatedPaths;
                $event->save();
            }

            // Jitter delay proportional to attempt count to spread out retry storms.
            $jitterBase = min(5, $event->sync_attempts + 1);
            usleep(random_int(500_000 * $jitterBase, 1_500_000 * $jitterBase));

            // Per-slot transmission lock — different slots can run simultaneously.
            try {
                return $this->runWithTransmissionLock(
                    function () use ($client, $multipart) {
                        $response = $client->post($this->submitUrl, ['multipart' => $multipart]);
                        return $this->evaluateResponse(
                            $response->getStatusCode(),
                            (string) $response->getBody()
                        );
                    }
                );
            } catch (ConnectException $e) {
                app(\App\Services\PortalHealthService::class)->tripCircuitBreaker("Connection lost during multipart upload: {$e->getMessage()}");
                throw new TransientSyncException(
                    "Connection lost during multipart upload: {$e->getMessage()}", 0, $e
                );
            } catch (RequestException $e) {
                $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
                if ($status >= 500) {
                    app(\App\Services\PortalHealthService::class)->tripCircuitBreaker("Downstream server error HTTP {$status} during upload.");
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

    /**
     * Executes a callback within a shared transmission lock.
     * Prevents overlapping submissions in the same slot across servers.
     */
    protected function runWithTransmissionLock(callable $callback)
    {
        // IMPORTANT: the transmission lock must be SITE-LOCAL, not shared.
        //
        // The shared_sync directory is mounted by BOTH nmbabudgam.in and
        // ctetmonktest.fun. If we use a file in that directory, both sites
        // compete for the same flock() and the loser times out in 5 seconds.
        //
        // Each site has its own local DB for Cache::lock(). Using it here
        // means two sites can submit to the portal concurrently (which is fine
        // — they sync different local events to the same portal endpoint).
        //
        // The lock still protects against two workers on the SAME site and SAME
        // slot running simultaneously, which WithoutOverlapping already prevents.
        // This is kept as a belt-and-suspenders guard.
        return Cache::lock($this->transmissionLockKey(), 60)->block(10, $callback);
    }
}
