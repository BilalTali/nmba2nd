<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class PortalHealthService
{
    protected string $loginUrl;
    protected int $timeout = 30;
    protected float $lastResponseTime = 0.0;

    public function __construct()
    {
        $this->loginUrl = rtrim((string) config('services.portal.url'), '/') . '/login';
    }

    public function getLastResponseTime(): float
    {
        return $this->lastResponseTime;
    }

    public function isAlive(bool $bypassCache = false): bool
    {
        if (!$bypassCache) {
            // If the circuit breaker is already tripped, skip the probe entirely unless bypassed.
            if (Cache::get('sre_circuit_breaker_portal_down') === true) {
                $this->lastResponseTime = (float) $this->timeout;
                return false;
            }

            // If we recently verified the portal is alive, return true immediately.
            if (Cache::get('sre_portal_is_alive') === true) {
                $this->lastResponseTime = 0.1;
                return true;
            }
        }

        $client = new Client([
            'version' => 2.0,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ],
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ]);

        $startTime = microtime(true);
        try {
            $response = $client->get($this->loginUrl);
            $this->lastResponseTime = microtime(true) - $startTime;

            if ($response->getStatusCode() !== 200) {
                if (!$bypassCache) {
                    $this->tripCircuitBreaker(
                        'Non-200 status received: ' . $response->getStatusCode()
                    );
                }
                return false;
            }

            $crawler = new Crawler((string) $response->getBody());

            // Verify that the login form's authentication controls are still present.
            $hasUsernameField = $crawler->filter('input[name="username"]')->count() > 0
                || $crawler->filter('input[name="email"]')->count() > 0;
            $hasPasswordField = $crawler->filter('input[type="password"]')->count() > 0;

            if (!$hasUsernameField || !$hasPasswordField) {
                if (!$bypassCache) {
                    $this->tripCircuitBreaker(
                        'Portal DOM structure changed — authentication fields missing from login page.'
                    );
                }
                return false;
            }

            // If we successfully connected, cache the alive status for 5 minutes
            Cache::put('sre_portal_is_alive', true, now()->addMinutes(5));

            // If we bypassed cache and successfully connected, untrip the circuit breaker!
            if ($bypassCache) {
                Cache::forget('sre_circuit_breaker_portal_down');
            }

            return true;

        } catch (Exception $e) {
            $this->lastResponseTime = microtime(true) - $startTime;
            if (!$bypassCache) {
                $this->tripCircuitBreaker($e->getMessage());
            }
            return false;
        }
    }

    /**
     * Activate the circuit breaker for 1 minute and emit an alert log entry.
     */
    public function tripCircuitBreaker(string $reason): void
    {
        Log::channel('sync')->alert('Circuit breaker tripped — portal unreachable or structurally degraded.', [
            'reason' => $reason,
            'cooldown' => '1 minute',
        ]);

        Cache::forget('sre_portal_is_alive');
        // Place a lock in cache for 1 minute
        Cache::put('sre_circuit_breaker_portal_down', true, now()->addMinutes(1));
    }
}
