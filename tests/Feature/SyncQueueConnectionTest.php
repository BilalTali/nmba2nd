<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Jobs\SyncEventJob;
use App\Jobs\SyncBatchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncQueueConnectionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sync_event_job_constructor_sets_connection_to_database(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $event = Event::create([
            'event_name'                       => 'Test Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-test'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'pending',
            'sync_attempts'                    => 0,
        ]);

        $job = new SyncEventJob($event);

        $this->assertEquals('database', $job->connection, 'SyncEventJob must always use the database queue connection');
        $this->assertEquals('default', $job->queue, 'SyncEventJob must use the default queue');
    }

    /** @test */
    public function scheduler_does_not_dispatch_duplicate_job_if_lock_exists(): void
    {
        Queue::fake();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 5, 20, 12, 0, 0));

        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $event = Event::create([
            'event_name'                       => 'Test Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-test'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'pending',
            'sync_attempts'                    => 0,
            'last_attempt_at'                  => null,
        ]);

        // Establish the dispatch lock
        \Illuminate\Support\Facades\Cache::put("sre_sync_dispatch_lock_{$event->id}", true, 3600);

        // Mock the PortalHealthService so the scheduler is not skipped
        $healthMock = $this->mock(\App\Services\PortalHealthService::class);
        $healthMock->shouldReceive('isAlive')->andReturn(true);

        // Run the scheduler sweep
        $this->artisan('schedule:run');

        // Assert that the job was not dispatched because the lock was active
        Queue::assertNotPushed(SyncBatchJob::class);

        \Illuminate\Support\Carbon::setTestNow(null);
    }

    /** @test */
    public function scheduler_dispatches_job_when_no_lock_exists(): void
    {
        Queue::fake();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 5, 20, 12, 0, 0));

        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $event = Event::create([
            'event_name'                       => 'Test Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-test'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'pending',
            'sync_attempts'                    => 0,
            'last_attempt_at'                  => null,
        ]);

        // Mock the PortalHealthService so the scheduler is not skipped
        $healthMock = $this->mock(\App\Services\PortalHealthService::class);
        $healthMock->shouldReceive('isAlive')->andReturn(true);

        // Run the scheduler sweep
        $this->artisan('schedule:run');

        // Assert that the job was pushed
        Queue::assertPushed(SyncBatchJob::class, function ($job) use ($event) {
            return $job->connection === 'database';
        });

        // Assert that the lock was created in the cache
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has("sre_sync_dispatch_lock_{$event->id}"));

        \Illuminate\Support\Carbon::setTestNow(null);
    }

    /** @test */
    public function job_releases_itself_when_portal_is_offline(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $event = Event::create([
            'event_name'                       => 'Test Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-test'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'pending',
            'sync_attempts'                    => 0,
        ]);

        // Mock the PortalHealthService so it returns false (unreachable portal)
        $healthMock = $this->mock(\App\Services\PortalHealthService::class);
        $healthMock->shouldReceive('isAlive')->andReturn(false);

        // We mock the job class but only mock the release method
        $job = $this->getMockBuilder(SyncEventJob::class)
            ->setConstructorArgs([$event])
            ->onlyMethods(['release'])
            ->getMock();

        // Expect the release method to be called (with a 60 second delay)
        $job->expects($this->once())
            ->method('release')
            ->with(60);

        // Run the job handler via Laravel Container injection
        $this->app->call([$job, 'handle']);

        // Assert event status remains 'pending' and sync_attempts is incremented to 1
        $event->refresh();
        $this->assertEquals('pending', $event->sync_status);
        $this->assertEquals(1, $event->sync_attempts);
    }

    /** @test */
    public function admin_can_reset_all_failed_events_to_pending(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'block_id' => $block->id]);

        // Create a failed event
        $failedEvent = Event::create([
            'event_name'                       => 'Failed Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-failed'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'failed_permanently',
            'sync_attempts'                    => 10,
        ]);

        // Create a manually locked out event (attempts = -1)
        $lockedEvent = Event::create([
            'event_name'                       => 'Locked Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-locked'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'pending',
            'sync_attempts'                    => -1,
        ]);

        // Set circuit breaker in cache
        \Illuminate\Support\Facades\Cache::put('sre_circuit_breaker_portal_down', true, 600);

        // Make the reset call
        $response = $this->actingAs($admin)->post(route('events.reset-failed'));

        // Assert redirect and success flash message
        $response->assertStatus(302);
        $response->assertRedirect(route('dashboard'));
        $this->assertEquals(
            'Successfully reset 2 failed or quarantined events back to pending. The background sync daemon will process them shortly.',
            session('success')
        );

        // Assert database updates
        $failedEvent->refresh();
        $this->assertEquals('pending', $failedEvent->sync_status);
        $this->assertEquals(0, $failedEvent->sync_attempts);

        $lockedEvent->refresh();
        $this->assertEquals('pending', $lockedEvent->sync_status);
        $this->assertEquals(0, $lockedEvent->sync_attempts);

        // Assert circuit breaker cache cleared
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('sre_circuit_breaker_portal_down'));
    }

    /** @test */
    public function is_alive_returns_true_immediately_without_network_request_if_cached(): void
    {
        \Illuminate\Support\Facades\Cache::put('sre_portal_is_alive', true, 300);

        $service = new \App\Services\PortalHealthService();
        
        $this->assertTrue($service->isAlive());
    }

    /** @test */
    public function trip_circuit_breaker_sets_breaker_status_and_clears_alive_cache(): void
    {
        \Illuminate\Support\Facades\Cache::put('sre_portal_is_alive', true, 300);

        $service = new \App\Services\PortalHealthService();
        $service->tripCircuitBreaker('Test reason');

        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('sre_circuit_breaker_portal_down'));
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('sre_portal_is_alive'));
    }

    /** @test */
    public function job_auth_failure_threshold_breach_pauses_sync_and_sets_cache_flags(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $event = Event::create([
            'event_name'                       => 'Test Event',
            'event_date'                       => '2026-05-01',
            'event_venue'                      => 'Venue',
            'event_category'                   => ['Awareness'],
            'district_name'                    => 'Budgam',
            'block_id'                         => $block->id,
            'actual_attendance'                => 10,
            'attendance_range'                 => '10-50',
            'target_audience'                  => ['Students'],
            'age_group'                        => ['18-25'],
            'event_coordinator_name'           => 'Coordinator',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig'          => 'Teacher',
            'photo_paths'                      => [],
            'unique_hash'                      => md5(uniqid('', true)),
            'semantic_hash'                    => md5('semantic-test'),
            'submission_id'                    => md5(uniqid('', true)),
            'sync_status'                      => 'pending',
            'sync_attempts'                    => 0,
        ]);

        // Mock PortalSyncInterface to throw AuthenticationSyncException
        $syncServiceMock = $this->mock(\App\Services\Contracts\PortalSyncInterface::class);
        $syncServiceMock->shouldReceive('sync')
            ->andThrow(new \App\Exceptions\AuthenticationSyncException('Invalid credentials'));

        // Mock PortalHealthService to return true (alive) so it doesn't bypass auth failure handling
        $healthMock = $this->mock(\App\Services\PortalHealthService::class);
        $healthMock->shouldReceive('isAlive')->andReturn(true);

        // Run failures 1 to 9
        for ($i = 1; $i <= 9; $i++) {
            $event->refresh();
            $event->sync_status = 'pending';
            $event->sync_attempts = 0;
            $event->save();

            $job = new SyncEventJob($event);
            $this->app->call([$job, 'handle']);

            $this->assertEquals($i, \Illuminate\Support\Facades\Cache::get('sre_consecutive_auth_failures'));
            $this->assertFalse(\Illuminate\Support\Facades\Cache::has('portal_credentials_invalid'));
            $this->assertFalse(\Illuminate\Support\Facades\Cache::has('auto_sync_paused'));
        }

        // Run the 10th failure (threshold breach)
        $event->refresh();
        $event->sync_status = 'pending';
        $event->sync_attempts = 0;
        $event->save();

        $job10 = new SyncEventJob($event);
        $this->app->call([$job10, 'handle']);

        $this->assertEquals(10, \Illuminate\Support\Facades\Cache::get('sre_consecutive_auth_failures'));
        $this->assertTrue(\Illuminate\Support\Facades\Cache::get('portal_credentials_invalid'));
        $this->assertTrue(\Illuminate\Support\Facades\Cache::get('auto_sync_paused'));
    }

    /** @test */
    public function admin_updating_credentials_clears_invalid_credentials_cache_flag(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'block_id' => $block->id]);

        \Illuminate\Support\Facades\Cache::put('portal_credentials_invalid', true);

        // Submit credential updates to settings.env endpoint
        $response = $this->actingAs($admin)->post(route('settings.env'), [
            'portal_url' => 'https://nashamuktjk.org',
            'admin_id' => 'admin@test.com',
            'admin_password' => 'secret123',
        ]);

        $response->assertStatus(302);
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('portal_credentials_invalid'));
    }

    /** @test */
    public function admin_resetting_failed_events_clears_invalid_credentials_cache_flag(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'block_id' => $block->id]);

        \Illuminate\Support\Facades\Cache::put('portal_credentials_invalid', true);

        $response = $this->actingAs($admin)->post(route('events.reset-failed'));

        $response->assertStatus(302);
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('portal_credentials_invalid'));
    }

    /** @test */
    public function admin_forcing_sync_clears_invalid_credentials_cache_flag(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'block_id' => $block->id]);

        \Illuminate\Support\Facades\Cache::put('portal_credentials_invalid', true);

        $response = $this->actingAs($admin)->post(route('events.force-sync'));

        $response->assertStatus(302);
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('portal_credentials_invalid'));
    }

    /** @test */
    public function check_portal_credentials_command_sets_invalid_credentials_cache_flag_on_failure(): void
    {
        // Mock the Guzzle Client
        $mockClient = \Mockery::mock(\GuzzleHttp\Client::class);
        
        // Mock Step 1: GET login page and return CSRF token
        $mockClient->shouldReceive('get')
            ->once()
            ->andReturn(new \GuzzleHttp\Psr7\Response(200, [], '<input name="_token" value="test_token" />'));

        // Mock Step 2: POST credentials and return a body WITHOUT logout/dashboard keywords (failure)
        $mockClient->shouldReceive('post')
            ->once()
            ->andReturn(new \GuzzleHttp\Psr7\Response(200, [], '<html><input type="password" />Login failed</html>'));

        // Bind the mock to the container so app(Client::class) returns it
        $this->app->instance(\GuzzleHttp\Client::class, $mockClient);

        // Configure credentials in config so the command runs
        config(['services.portal.url' => 'https://nashamuktjk.org']);
        config(['services.portal.email' => 'admin@test.com']);
        config(['services.portal.password' => 'secret123']);

        // Ensure flags are false initially
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('portal_credentials_invalid'));
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('auto_sync_paused'));

        // Run the command
        $this->artisan('portal:check-credentials');

        // Assert flags are set on failure
        $this->assertTrue(\Illuminate\Support\Facades\Cache::get('portal_credentials_invalid'));
        $this->assertTrue(\Illuminate\Support\Facades\Cache::get('auto_sync_paused'));
    }

    /** @test */
    public function check_portal_credentials_command_clears_invalid_credentials_cache_flag_on_success(): void
    {
        // Mock the Guzzle Client
        $mockClient = \Mockery::mock(\GuzzleHttp\Client::class);
        
        // Mock Step 1: GET login page and return CSRF token
        $mockClient->shouldReceive('get')
            ->once()
            ->andReturn(new \GuzzleHttp\Psr7\Response(200, [], '<input name="_token" value="test_token" />'));

        // Mock Step 2: POST credentials and return a body WITH logout/dashboard keywords (success)
        $mockClient->shouldReceive('post')
            ->once()
            ->andReturn(new \GuzzleHttp\Psr7\Response(200, [], '<html>Dashboard Sign Out</html>'));

        // Bind the mock to the container
        $this->app->instance(\GuzzleHttp\Client::class, $mockClient);

        // Configure credentials in config
        config(['services.portal.url' => 'https://nashamuktjk.org']);
        config(['services.portal.email' => 'admin@test.com']);
        config(['services.portal.password' => 'secret123']);

        // Set flags initially to true
        \Illuminate\Support\Facades\Cache::put('portal_credentials_invalid', true);
        \Illuminate\Support\Facades\Cache::put('sre_consecutive_auth_failures', 5);

        // Run the command
        $this->artisan('portal:check-credentials');

        // Assert flags are cleared on success
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('portal_credentials_invalid'));
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('sre_consecutive_auth_failures'));
    }

    /** @test */
    public function check_portal_health_route_does_not_override_circuit_breaker_when_active(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'block_id' => $block->id]);

        // Create temporary directory for shared sync files
        $tempDir = sys_get_temp_dir() . '/shared_sync_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        config(['services.sync.shared_dir' => $tempDir]);

        // Create a simulated portal_live_window (confirmed alive 10 seconds ago)
        file_put_contents($tempDir . '/portal_live_window.json', json_encode([
            'probed_at' => time() - 10,
            'site' => 'test.com',
        ]));

        // Trip local circuit breaker (breaker active)
        file_put_contents($tempDir . '/sre_circuit_breaker_portal_down.json', json_encode([
            'value' => true,
            'expires_at' => time() + 60,
        ]));
        @unlink($tempDir . '/sre_portal_is_alive.json');
        \Illuminate\Support\Facades\Cache::put('sre_circuit_breaker_portal_down', true, 60);
        \Illuminate\Support\Facades\Cache::forget('sre_portal_is_alive');

        // Health check should return offline because circuit breaker is active,
        // and it should NOT clear the circuit breaker.
        $response = $this->actingAs($admin)->get(route('events.check-portal'));

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'offline');

        // Circuit breaker should still be active in cache
        $this->assertTrue(\Illuminate\Support\Facades\Cache::get('sre_circuit_breaker_portal_down'));

        // Clean up temp directory
        @unlink($tempDir . '/portal_live_window.json');
        @unlink($tempDir . '/sre_circuit_breaker_portal_down.json');
        @unlink($tempDir . '/sre_portal_is_alive.json');
        @rmdir($tempDir);
    }

    /** @test */
    public function check_portal_health_route_applies_fallback_when_circuit_breaker_is_not_active(): void
    {
        $block = Block::create([
            'id'          => 1,
            'name'        => 'Test Block',
            'slug'        => 'test-block',
            'district_id' => 1,
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'block_id' => $block->id]);

        // Create temporary directory for shared sync files
        $tempDir = sys_get_temp_dir() . '/shared_sync_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        config(['services.sync.shared_dir' => $tempDir]);

        // Create a simulated portal_live_window (confirmed alive 10 seconds ago)
        file_put_contents($tempDir . '/portal_live_window.json', json_encode([
            'probed_at' => time() - 10,
            'site' => 'test.com',
        ]));

        // Ensure no circuit breaker is active and portal alive cache is expired
        \Illuminate\Support\Facades\Cache::forget('sre_circuit_breaker_portal_down');
        \Illuminate\Support\Facades\Cache::forget('sre_portal_is_alive');

        // Health check should return online because live window is fresh,
        // and it should restore the online status.
        $response = $this->actingAs($admin)->get(route('events.check-portal'));

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'online');

        // It should restore the portal is alive cache flag
        $this->assertTrue(\Illuminate\Support\Facades\Cache::get('sre_portal_is_alive'));

        // Clean up temp directory
        @unlink($tempDir . '/portal_live_window.json');
        @rmdir($tempDir);
    }
}
