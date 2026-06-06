<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use App\Traits\SharedCacheTrait;

class SettingsController extends Controller
{
    use SharedCacheTrait;

    public function updateEnv(Request $request)
    {
        $request->validate([
            'portal_url'      => 'required|url',
            'admin_id'        => 'required|string',
            'admin_password'  => 'required|string',
        ]);

        $baseUrl = rtrim((string) $request->portal_url, '/');

        // BN-5 FIX: Atomic write via temp-file + rename.
        // If the process is killed mid-write the live .env is never touched.
        $this->setEnvironmentValueAtomic([
            'PORTAL_URL'      => $baseUrl,
            'PORTAL_EMAIL'    => $request->admin_id,
            'PORTAL_PASSWORD' => '"' . str_replace('"', '\"', $request->admin_password) . '"',
        ]);

        // Flush Laravel's config cache so the new values are picked up
        // by the next request/job without a server restart.
        Artisan::call('config:clear');

        // Invalidate all portal session cookie jars so the next SyncBatchJob
        // performs a fresh login with the new credentials instead of reusing
        // a cookie jar that was signed-in with the old (possibly invalid) password.
        for ($slot = 0; $slot < 8; $slot++) {
            Cache::forget("sre_portal_session_slot_{$slot}");
            $sharedPath = $this->getSharedPath("cookies_slot_{$slot}.json");
            if ($sharedPath && file_exists($sharedPath)) {
                @unlink($sharedPath);
            }
        }

        // Clear auth-related signal flags so sync resumes immediately.
        $this->forgetSharedValue('auto_sync_paused');
        $this->forgetSharedValue('sre_consecutive_auth_failures');
        $this->forgetSharedValue('portal_credentials_invalid');
        $this->forgetSharedValue('sre_circuit_breaker_portal_down');

        // Clear WithoutOverlapping slot locks so new SyncBatchJobs can start immediately.
        for ($i = 0; $i < 8; $i++) {
            Cache::forget("laravel-queue-overlap:App\\Jobs\\SyncBatchJob:sync_batch_slot_{$i}");
        }

        // Flush opcache if available, so any cached config bootstrap picks up new values.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return redirect()->back()->with('success', 'Credentials updated successfully. Portal session cookies cleared — sync will re-authenticate on the next attempt.');
    }

    /**
     * Write .env values atomically: build the new content in a temp file
     * in the same directory, then rename() it over the original.
     * rename() is atomic on POSIX filesystems — the live .env is never
     * in a partially-written state.
     */
    private function setEnvironmentValueAtomic(array $values): void
    {
        $envFile = app()->environmentFilePath();
        $str = File::get($envFile);

        // Normalize line endings
        $str = str_replace("\r\n", "\n", $str);
        $lines = explode("\n", $str);

        foreach ($values as $envKey => $envValue) {
            $found = false;
            foreach ($lines as &$line) {
                if (str_starts_with(trim($line), "{$envKey}=")) {
                    $line  = "{$envKey}={$envValue}";
                    $found = true;
                    break;
                }
            }
            unset($line);
            if (!$found) {
                $lines[] = "{$envKey}={$envValue}";
            }
        }

        $newContent = implode("\n", $lines);

        // Write to a temp file in the same directory, then atomically replace.
        $tmpFile = $envFile . '.tmp.' . getmypid();
        File::put($tmpFile, $newContent);
        rename($tmpFile, $envFile);
    }
}

