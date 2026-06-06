<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyncedEventsViewTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guests_are_redirected_to_login()
    {
        $response = $this->get(route('admin.synced-events'));
        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function non_admins_cannot_access_synced_events()
    {
        $user = User::factory()->create(['role' => 'district_creator']);

        $response = $this->actingAs($user)
            ->get(route('admin.synced-events'));

        $response->assertRedirect(route('block.events.index'));
    }

    /** @test */
    public function admins_can_access_synced_events_and_see_only_synced_data()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $block = Block::create([
            'id' => 1,
            'name' => 'Test Block',
            'slug' => 'test-block',
            'district_id' => 10
        ]);

        // Create one synced event
        $syncedEvent = Event::create([
            'event_name' => 'Synced Event',
            'event_date' => '2026-05-01',
            'event_venue' => 'Venue A',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $block->id,
            'actual_attendance' => 50,
            'attendance_range' => '40-100',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator X',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash1',
            'submission_id' => 'sub1',
            'sync_status' => 'synced',
            'synced_at' => '2026-05-20 10:52:00', // UTC 10:52:00 -> IST 04:22:00 PM
        ]);

        // Create one pending event (should not be in the view)
        $pendingEvent = Event::create([
            'event_name' => 'Pending Event',
            'event_date' => '2026-05-02',
            'event_venue' => 'Venue B',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $block->id,
            'actual_attendance' => 30,
            'attendance_range' => '20-50',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator Y',
            'event_coordinator_contact_number' => '9876543211',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash2',
            'submission_id' => 'sub2',
            'sync_status' => 'pending',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.synced-events'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Events/SyncedIndex')
            ->has('events.data', 1)
            ->where('events.data.0.event_name', 'Synced Event')
            ->where('events.data.0.formatted_synced_at', \Illuminate\Support\Carbon::parse($syncedEvent->synced_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i:s A'))
            ->has('blocks')
            ->has('filters')
            ->where('totalSynced', 1)
        );
    }

    /** @test */
    public function admins_can_filter_synced_events()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        
        $block1 = Block::create([
            'id' => 1,
            'name' => 'Block One',
            'slug' => 'block-one',
            'district_id' => 10
        ]);
        $block2 = Block::create([
            'id' => 2,
            'name' => 'Block Two',
            'slug' => 'block-two',
            'district_id' => 10
        ]);

        Event::create([
            'event_name' => 'Event Block One',
            'event_date' => '2026-05-01',
            'event_venue' => 'Venue A',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $block1->id,
            'actual_attendance' => 50,
            'attendance_range' => '40-100',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator X',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash1',
            'submission_id' => 'sub1',
            'sync_status' => 'synced',
            'synced_at' => '2026-05-20 10:52:00',
        ]);

        Event::create([
            'event_name' => 'Event Block Two',
            'event_date' => '2026-05-05',
            'event_venue' => 'Venue B',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $block2->id,
            'actual_attendance' => 30,
            'attendance_range' => '20-50',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator Y',
            'event_coordinator_contact_number' => '9876543211',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash2',
            'submission_id' => 'sub2',
            'sync_status' => 'synced',
            'synced_at' => '2026-05-20 10:55:00',
        ]);

        // Filter by block 1
        $response = $this->actingAs($admin)
            ->get(route('admin.synced-events', ['block_id' => $block1->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Events/SyncedIndex')
            ->has('events.data', 1)
            ->where('events.data.0.event_name', 'Event Block One')
        );

        // Filter by date range (excluding Block One event)
        $response = $this->actingAs($admin)
            ->get(route('admin.synced-events', [
                'start_date' => '2026-05-03',
                'end_date' => '2026-05-10',
            ]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Events/SyncedIndex')
            ->has('events.data', 1)
            ->where('events.data.0.event_name', 'Event Block Two')
        );
    }
}
