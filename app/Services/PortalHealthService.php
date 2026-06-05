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
    protected int $timeout = 30;

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
        $this->loginUrl = rtrim((string) config('services.portal.url'), '/') . '/login';
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
            Cache::put('sre_portal_response_time', $this->lastResponseTime, now()->addMinutes(10));

            if ($response->getStatusCode() !== 200) {
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

            // Clear any stale circuit breaker (bypassCache=true recovery path)
            if ($bypassCache) {
                $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            }

            return true;

        } catch (Exception $e) {
            $this->lastResponseTime = microtime(true) - $startTime;
            Cache::put('sre_portal_response_time', $this->lastResponseTime, now()->addMinutes(10));
            if (!$bypassCache) {
                $this->tripCircuitBreaker($e->getMessage());
            }
            return false;
        }
    }

    /**
     * Activate the circuit breaker and emit an alert log entry.
     * TTL reduced from 60s → 8s: retry aggressively since portal flickers.
     */
    public function tripCircuitBreaker(string $reason): void
    {
        Log::channel('sync')->alert('Circuit breaker tripped — portal unreachable.', [
            'reason'   => $reason,
            'cooldown' => $this->breakerTtl . ' seconds',
        ]);

        $this->forgetSharedValue('sre_portal_is_alive');
        $this->forgetPortalLiveWindow();
        $this->setSharedValue('sre_circuit_breaker_portal_down', true, $this->breakerTtl);
    }
}
