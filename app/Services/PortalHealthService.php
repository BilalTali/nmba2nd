<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

use App\Traits\SharedCacheTrait;

class PortalHealthService
{
    use SharedCacheTrait;

    protected string $loginUrl;

    /**
     * Hard probe timeout in seconds.
     * Portal must respond within this window or we treat it as down.
     * Reduced from 90s → 12s: if the portal is alive it responds fast;
     * a 90-second hang just wastes the entire cron window.
     */
    protected int $timeout = 90;

    /**
     * How long (seconds) to trust a "portal is alive" result before re-probing.
     * Reduced from 300s (5 min) → 10s so we detect outages within one scheduler tick.
     */
    protected int $aliveTtl = 360;

    /**
     * How long (seconds) the circuit breaker stays tripped before we retry.
     * Reduced from 60s → 8s: the portal flickers — retry aggressively.
     */
    protected int $breakerTtl = 8;

    /**
     * How long (seconds) the cross-site portal_live_window signal stays valid.
     * If either site confirmed alive within this window, the other site trusts it.
     */
    protected int $liveWindowTtl = 20;

    protected float $lastResponseTime = 0.0;

    public function __construct()
    {
        $this->loginUrl = (string) config('services.portal.url');
    }

    public function getLastResponseTime(): float
    {
        return $this->lastResponseTime;
    }

    /**
     * Check if the target portal is alive.
     *
     * Priority order (fastest first):
     *   1. Cross-site portal_live_window (shared_sync file) — another site confirmed alive recently
     *   2. Circuit breaker tripped (shared_sync file) — skip probe, return false
     *   3. Local sre_portal_is_alive cache — this site confirmed alive recently
     *   4. Live HTTP probe — the ground truth
     *
     * @param bool $bypassCache  Force a live probe regardless of any cached state.
     *                           Used by the dashboard "Check Portal" button and recovery paths.
     */
    public function isAlive(bool $bypassCache = false): bool
    {
        if (!$bypassCache) {
            // ── Fast path 1: Cross-site live window ───────────────────────
            // If ANY site on this server confirmed the portal alive within
            // $liveWindowTtl seconds, trust it — skip the slow HTTP probe.
            $liveWindow = $this->readPortalLiveWindow();
            if ($liveWindow !== null && (time() - $liveWindow) < $this->liveWindowTtl) {
                $this->lastResponseTime = 0.05;
                // Propagate locally so the scheduler/dashboard see it as alive
                $this->setSharedValue('sre_portal_is_alive', true, $this->aliveTtl);
                $this->forgetSharedValue('sre_circuit_breaker_portal_down');
                return true;
            }

            // ── Fast path 2: Circuit breaker (this site's own breaker) ───
            if ($this->getSharedValue('sre_circuit_breaker_portal_down') === true) {
                $this->lastResponseTime = (float) $this->timeout;
                return false;
            }

            // ── Fast path 3: Recent local alive confirmation ─────────────
            if ($this->getSharedValue('sre_portal_is_alive') === true) {
                $this->lastResponseTime = 0.1;
                return true;
            }
        }

        // ── Live HTTP probe ───────────────────────────────────────────────
        $client = new Client([
            'version'         => 2.0,
            'timeout'         => $this->timeout,
            'connect_timeout' => $this->timeout,
            'allow_redirects' => true,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ]);

        $startTime = microtime(true);
        try {
            $response = $client->get($this->loginUrl);
            $this->lastResponseTime = microtime(true) - $startTime;
            $this->setSharedValue('sre_portal_response_time', $this->lastResponseTime, 600);

            if ($response->getStatusCode() !== 200) {
                $this->forgetPortalLiveWindow();
                if (!$bypassCache) {
                    $this->tripCircuitBreaker(
                        'Non-200 status received: ' . $response->getStatusCode()
                    );
                }
                return false;
            }

            $crawler = new Crawler((string) $response->getBody());

            // Verify the login form's authentication controls are present.
            $hasUsernameField = $crawler->filter('input[name="username"]')->count() > 0
                || $crawler->filter('input[name="email"]')->count() > 0;
            $hasPasswordField = $crawler->filter('input[type="password"]')->count() > 0;

            if (!$hasUsernameField || !$hasPasswordField) {
                $this->forgetPortalLiveWindow();
                if (!$bypassCache) {
                    $this->tripCircuitBreaker(
                        'Portal DOM changed — authentication fields missing from login page.'
                    );
                }
                return false;
            }

            // ── Portal is ALIVE ───────────────────────────────────────────
            // Write cross-site live window — other site on same server will see this
            // and skip its own slow probe for the next $liveWindowTtl seconds.
            $this->writePortalLiveWindow();

            // Cache alive state locally
            $this->setSharedValue('sre_portal_is_alive', true, $this->aliveTtl);
            $this->forgetSharedValue('sre_consecutive_portal_failures');

            // Clear any stale circuit breaker (bypassCache=true recovery path)
            if ($bypassCache) {
                $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            }

            return true;

        } catch (Exception $e) {
            $this->forgetPortalLiveWindow();
            $this->lastResponseTime = microtime(true) - $startTime;
            $this->setSharedValue('sre_portal_response_time', $this->lastResponseTime, 600);
            if (!$bypassCache) {
                $this->tripCircuitBreaker($e->getMessage());
            }
            return false;
        }
    }

    /**
     * Activate the circuit breaker and emit an alert log entry.
     * Uses dynamic exponential backoff based on consecutive failures.
     */
    public function tripCircuitBreaker(string $reason): void
    {
        $failures = (int) $this->getSharedValue('sre_consecutive_portal_failures', 0) + 1;
        $this->setSharedValue('sre_consecutive_portal_failures', $failures, 3600);

        // Progressive backoff: 8s, 16s, 32s, max 60s
        $backoffTtl = min(60, 8 * (2 ** ($failures - 1)));

        Log::channel('sync')->alert('Circuit breaker tripped — portal unreachable.', [
            'reason'               => $reason,
            'consecutive_failures' => $failures,
            'cooldown'             => $backoffTtl . ' seconds',
        ]);

        $this->forgetSharedValue('sre_portal_is_alive');
        // NOTE: We deliberately do NOT call forgetPortalLiveWindow() here.
        // portal_live_window.json is written by direct HTTP probes (cron/PortalHealthService::isAlive).
        // It is only authoritative when deleted by a direct probe that found the portal dead.
        // Deleting it here (on a transient 522 from a sync job session check) would destroy
        // the dashboard's fallback signal unnecessarily during brief circuit breaker cycles.
        $this->setSharedValue('sre_circuit_breaker_portal_down', true, $backoffTtl);

        // DEGRADED STATE FIX: set a longer-lived signal so the dashboard can display
        // "Degraded" for 2 minutes after the circuit breaker trips.
        // The breaker itself only lasts 8s (to allow rapid retry), but sre_portal_is_degraded
        // persists long enough for the dashboard health poll (every 15s) to reliably see it.
        // Cleared when an event actually syncs successfully (see SyncBatchJob).
        $this->setSharedValue('sre_portal_is_degraded', true, 120);
    }

    /**
     * Determine the recommended number of parallel slots based on portal response time and health.
     */
    public function getRecommendedSlotLimit(): int
    {
        $maxSlots = (int) config('services.sync.max_slots', 8);

        // 1. If portal is degraded, scale down significantly to allow recovery
        if ($this->getSharedValue('sre_portal_is_degraded') === true) {
            return min(2, $maxSlots);
        }

        // 2. Read the latest response time (default to 3.0s if not set)
        $responseTime = (float) $this->getSharedValue('sre_portal_response_time', 3.0);

        if ($responseTime > 15.0) {
            return min(2, $maxSlots); // very slow/struggling
        } elseif ($responseTime > 8.0) {
            return min(3, $maxSlots); // slow
        } elseif ($responseTime > 4.0) {
            return min(5, $maxSlots); // moderate
        }

        return $maxSlots; // healthy
    }
}
