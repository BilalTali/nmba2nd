<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;

/**
 * SyncTestEvent
 *
 * Allows developers to test and verify the sync logic for a specific event.
 * Portal-independent: uses local .env configurations.
 *
 * Usage:
 *   php artisan sync:test-event {event_id} {--real-submit} {--no-db-update}
 */
class SyncTestEvent extends Command
{
    protected $signature = 'sync:test-event
                            {id : The ID of the event to test sync}
                            {--real-submit : Actually send the POST request to the portal}
                            {--no-db-update : Do not update the event sync status in the database}';

    protected $description = 'Test the sync process of a specific event with detailed step-by-step output';

    public function handle(): int
    {
        $id = $this->argument('id');
        $realSubmit = $this->option('real-submit');
        $noDbUpdate = $this->option('no-db-update');

        $this->info("==========================================================");
        $this->info("          SYNC PROCESS CODE CHECKER (EVENT #{$id})");
        $this->info("==========================================================");

        // 1. Fetch the Event
        $this->comment("Step 1: Loading event from database...");
        $event = Event::find($id);
        if (!$event) {
            $this->error("✗ Event #{$id} not found in database.");
            return self::FAILURE;
        }
        $this->info("✓ Loaded Event: \"{$event->event_name}\" (Date: {$event->event_date->format('Y-m-d')})");
        $this->line("  Current Sync Status: <comment>{$event->sync_status}</comment>");
        $this->line("  Sync Attempts: {$event->sync_attempts}");

        // 2. Validate Photos
        $this->comment("\nStep 2: Checking photo assets in public storage...");
        $photos = $event->photo_paths ?? [];
        if (empty($photos)) {
            $this->warn("⚠ No photos associated with this event.");
        } else {
            $allPhotosOk = true;
            $disk = Storage::disk(config('filesystems.events_disk', 'public'));
            foreach ($photos as $index => $path) {
                $exists = $disk->exists($path);
                if (!$exists) {
                    // Try fallback
                    $fallbackPath = str_replace('events/', 'events/synced/', $path);
                    if ($disk->exists($fallbackPath)) {
                        $path = $fallbackPath;
                        $exists = true;
                    }
                }

                if ($exists) {
                    $sizeBytes = 0;
                    try {
                        $sizeBytes = $disk->size($path);
                    } catch (\Exception $e) {}
                    $this->info("  [Photo #{$index}] Found: " . basename($path) . " (" . number_format($sizeBytes / 1024, 1) . " KB)");
                } else {
                    $this->error("  [Photo #{$index}] Missing file in storage: {$path}");
                    $allPhotosOk = false;
                }
            }
            if (!$allPhotosOk) {
                $this->error("✗ One or more photo files are missing. Cannot sync this event.");
                return self::FAILURE;
            }
        }

        // 3. Load Portal Config
        $this->comment("\nStep 3: Checking portal configuration...");
        $url = rtrim((string) config('services.portal.url'), '/');
        $email = (string) config('services.portal.email');
        $password = (string) config('services.portal.password');

        if (empty($url) || empty($email) || empty($password)) {
            $this->error("✗ Missing PORTAL_URL, PORTAL_EMAIL, or PORTAL_PASSWORD in configuration/.env");
            return self::FAILURE;
        }

        $this->line("  Portal Target URL : <info>{$url}</info>");
        $this->line("  API Username/Email: <info>{$email}</info>");
        $this->line("  API Password Length: " . strlen($password) . " chars");

        // 4. Authenticate & Obtain CSRF Tokens
        $this->comment("\nStep 4: Simulating authentication handshake...");
        $cookieJar = new CookieJar();
        $client = new Client([
            'cookies' => $cookieJar,
            'timeout' => 25,
            'connect_timeout' => 10,
            'allow_redirects' => ['max' => 5, 'strict' => false],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ]
        ]);

        try {
            $this->line("  GET request to login portal: {$url} ...");
            $loginResponse = $client->get($url);
            $crawler = new Crawler((string) $loginResponse->getBody());

            $loginToken = '';
            if ($crawler->filter('input[name="_token"]')->count() > 0) {
                $loginToken = $crawler->filter('input[name="_token"]')->attr('value') ?? '';
            } elseif ($crawler->filter('meta[name="csrf-token"]')->count() > 0) {
                $loginToken = $crawler->filter('meta[name="csrf-token"]')->attr('content') ?? '';
            }
            $this->info("  ✓ Handshake OK. Extracted CSRF Token: " . ($loginToken ?: '(None)'));

            $this->line("  POST authenticating credentials...");
            $authResponse = $client->post($url . '/authenticate', [
                'headers' => ['Referer' => $url],
                'form_params' => [
                    '_token'   => $loginToken,
                    'email'    => $email,
                    'password' => $password,
                ],
            ]);

            $body = strtolower((string) $authResponse->getBody());
            $authenticated = str_contains($body, 'logout') || str_contains($body, 'sign out') || str_contains($body, 'dashboard');

            if (!$authenticated) {
                $this->error("✗ Authentication FAILED. Portal rejected credentials or returned custom wall.");
                return self::FAILURE;
            }
            $this->info("  ✓ Authentication SUCCESS! Session cookie established.");

            $this->line("  GET request to retrieve event creation CSRF token...");
            $submitResponse = $client->get($url . '/event_create');
            $submitCrawler = new Crawler((string) $submitResponse->getBody());

            $submitToken = '';
            if ($submitCrawler->filter('input[name="_token"]')->count() > 0) {
                $submitToken = $crawler->filter('input[name="_token"]')->attr('value') ?? '';
            }
            $this->info("  ✓ Retrieve Token OK. Submission CSRF: " . ($submitToken ?: '(None)'));

        } catch (\Exception $e) {
            $this->error("✗ Handshake/Authentication failed with exception: " . $e->getMessage());
            return self::FAILURE;
        }

        // 5. Construct Payload
        $this->comment("\nStep 5: Constructing event multipart payload...");
        $multipart = [
            ['name' => '_token',                             'contents' => $submitToken],
            ['name' => 'event_name',                        'contents' => $event->event_name],
            ['name' => 'event_date',                        'contents' => $event->event_date->format('d-m-Y')],
            ['name' => 'event_venue',                       'contents' => $event->event_venue],
            ['name' => 'district',                          'contents' => config('app.district_name', 'Budgam')],
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

        foreach ($event->event_category ?? [] as $cat) {
            $multipart[] = ['name' => 'event_category[]', 'contents' => $cat];
        }
        foreach ($event->target_audience ?? [] as $aud) {
            $multipart[] = ['name' => 'target_audience[]', 'contents' => $aud];
        }
        foreach ($event->age_group ?? [] as $age) {
            $multipart[] = ['name' => 'age_group[]', 'contents' => $age];
        }

        $this->info("  Payload fields constructed. Ready to append image binary streams...");

        // 6. Execution Decision
        if (!$realSubmit) {
            $this->warn("\n[DRY RUN] Skipping actual submission payload POST request.");
            $this->line("To run a live sync check, add the flag: <comment>--real-submit</comment>");
            $this->info("\n✓ Code check passed! Sync payload constructs cleanly, assets exist, and credentials check out.");
            return self::SUCCESS;
        }

        // 7. Submit Payload
        $this->comment("\nStep 6: Executing live upload (POST event_create)...");
        $streams = [];
        try {
            $disk = Storage::disk(config('filesystems.events_disk', 'public'));
            foreach ($photos as $path) {
                if (!$disk->exists($path)) {
                    $fallbackPath = str_replace('events/', 'events/synced/', $path);
                    if ($disk->exists($fallbackPath)) {
                        $path = $fallbackPath;
                    }
                }

                $handle = $disk->readStream($path);
                if ($handle === false || !is_resource($handle)) {
                    throw new \Exception("Could not open handle/stream for: {$path}");
                }
                $streams[] = $handle;
                $multipart[] = [
                    'name'     => 'event_photos[]',
                    'contents' => $handle,
                    'filename' => basename($path),
                ];
            }

            $startTime = microtime(true);
            $response = $client->post($url . '/event_create', [
                'multipart' => $multipart,
            ]);
            $duration = round(microtime(true) - $startTime, 2);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            $this->info("  POST response status code: {$statusCode} (Duration: {$duration}s)");

            // Evaluate response using the same logic as HttpPortalSyncService
            $lower = strtolower($responseBody);
            $success = false;
            $reason = '';

            if (str_contains($responseBody, 'name="password"') || str_contains($lower, 'type="password"')) {
                $reason = "Session expired or credentials rejected mid-flight (login redirect).";
            } else {
                $submitCrawler = new Crawler($responseBody);
                $errorNodes = $submitCrawler->filter('.alert-danger, .error-message, .validation-errors');
                if ($errorNodes->count() > 0) {
                    $reason = "Validation error from portal: " . trim($errorNodes->first()->text());
                } elseif ($statusCode === 302 || $statusCode === 200) {
                    $rawKeywords = config('services.portal.success_keywords', 'success,saved successfully,record added,created successfully');
                    $successKeywords = array_map('trim', explode(',', strtolower($rawKeywords)));
                    foreach ($successKeywords as $keyword) {
                        if (str_contains($lower, $keyword)) {
                            $success = true;
                            break;
                        }
                    }
                    if (!$success) {
                        $reason = "Status code was {$statusCode} but success keywords not found in response body.";
                    }
                } else {
                    $reason = "Unexpected HTTP status: {$statusCode}";
                }
            }

            if ($success) {
                $this->info("✓ LIVE SYNC SUCCESSFUL! The event was synchronized correctly.");
                if (!$noDbUpdate) {
                    $event->sync_status = 'synced';
                    $event->synced_at = now();
                    $event->save();
                    $this->info("  Database record updated to 'synced'.");
                } else {
                    $this->comment("  [--no-db-update] Database record left untouched.");
                }
                return self::SUCCESS;
            } else {
                $this->error("✗ Live Sync failed.");
                $this->error("  Reason: {$reason}");
                return self::FAILURE;
            }

        } catch (\Exception $e) {
            $this->error("✗ Live Sync encountered an exception: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
