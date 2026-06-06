<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

trait SharedCacheTrait
{
    /**
     * Path to the shared sync directory on the server.
     * Both nmbabudgam.in and ctetmonktest.fun have access to this directory,
     * making it the cross-site signal bus.
     */
    protected function getSharedDir(): string
    {
        return config('services.sync.shared_dir') ?: '/home/u335000182/shared_sync';
    }

    /**
     * Resolve a filename path inside the shared sync directory.
     * Returns null if the directory is missing or not writable.
     */
    protected function getSharedPath(string $filename): ?string
    {
        $dir = $this->getSharedDir();
        if (is_dir($dir) && is_writable($dir)) {
            return $dir . '/' . $filename;
        }
        return null;
    }

    /**
     * Get a cached value, checking the shared directory first, falling back to local cache.
     */
    protected function getSharedValue(string $key, $default = null)
    {
        $path = $this->getSharedPath("{$key}.json");
        if ($path && file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data) && isset($data['value'], $data['expires_at'])) {
                if (time() < $data['expires_at']) {
                    return $data['value'];
                }
                @unlink($path);
            }
        }
        return Cache::get($key, $default);
    }

    /**
     * Set a cached value, saving to the shared directory and local cache.
     */
    protected function setSharedValue(string $key, $value, $ttl = 1800): void
    {
        $ttlSeconds = 1800;
        if ($ttl instanceof \DateTimeInterface) {
            $ttlSeconds = max(0, $ttl->getTimestamp() - time());
        } elseif (is_int($ttl)) {
            $ttlSeconds = $ttl;
        }

        $path = $this->getSharedPath("{$key}.json");
        if ($path) {
            $data = [
                'value'      => $value,
                'expires_at' => time() + $ttlSeconds,
            ];
            file_put_contents($path, json_encode($data));
        }
        Cache::put($key, $value, $ttl);
    }

    /**
     * Delete a cached value from both the shared directory and local cache.
     */
    protected function forgetSharedValue(string $key): void
    {
        $path = $this->getSharedPath("{$key}.json");
        if ($path && file_exists($path)) {
            @unlink($path);
        }
        Cache::forget($key);
    }

    // ── Cross-Site Portal Live Window ─────────────────────────────────────────
    //
    // portal_live_window.json in the shared_sync directory is the fast signal
    // that tells EVERY site on this server: "the target portal was alive N seconds
    // ago — skip your own slow probe and start syncing immediately."
    //
    // Written by: whichever site just completed a successful live HTTP probe.
    // Read by: any site before it would otherwise do its own probe.
    // TTL: controlled by PortalHealthService::$liveWindowTtl (default 20s).

    /**
     * Write the portal live window signal to the shared directory.
     * Records the current Unix timestamp so readers can check freshness.
     */
    protected function writePortalLiveWindow(): void
    {
        $path = $this->getSharedPath('portal_live_window.json');
        if ($path) {
            file_put_contents($path, json_encode([
                'probed_at' => time(),
                'site'      => config('app.url', gethostname()),
            ]));
        }
    }

    /**
     * Read the portal live window signal.
     * Returns the Unix timestamp of the last successful probe, or null if absent/stale file.
     */
    protected function readPortalLiveWindow(): ?int
    {
        $path = $this->getSharedPath('portal_live_window.json');
        if (!$path || !file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['probed_at'])) {
            return null;
        }
        return (int) $data['probed_at'];
    }

    /**
     * Delete the portal live window signal (on circuit breaker trip).
     */
    protected function forgetPortalLiveWindow(): void
    {
        $path = $this->getSharedPath('portal_live_window.json');
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}
