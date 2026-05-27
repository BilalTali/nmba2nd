<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalSyncStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Block $block;
    protected Event $pendingEvent;
    protected Event $syncedEvent;
    protected Event $rejectedEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        
        $this->block = Block::create([
            'id' => 1,
            'name' => 'Budgam Block',
            'slug' => 'budgam-block',
            'district_id' => 10
        ]);

        $this->pendingEvent = Event::create([
            'event_name' => 'Pending Event',
            'event_date' => '2026-05-01',
            'event_venue' => 'Venue A',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $this->block->id,
            'actual_attendance' => 50,
            'attendance_range' => '40-100',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator A',
            'event_coordinator_contact_number' => '9876543210',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash1',
            'submission_id' => 'sub1',
            'sync_status' => 'pending',
        ]);

        $this->syncedEvent = Event::create([
            'event_name' => 'Synced Event',
            'event_date' => '2026-05-02',
            'event_venue' => 'Venue B',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $this->block->id,
            'actual_attendance' => 50,
            'attendance_range' => '40-100',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator B',
            'event_coordinator_contact_number' => '9876543211',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash2',
            'submission_id' => 'sub2',
            'sync_status' => 'synced',
            'synced_at' => now(),
        ]);

        $this->rejectedEvent = Event::create([
            'event_name' => 'Rejected Event',
            'event_date' => '2026-05-03',
            'event_venue' => 'Venue C',
            'event_category' => ['Awareness'],
            'district_name' => 'Budgam',
            'block_id' => $this->block->id,
            'actual_attendance' => 50,
            'attendance_range' => '40-100',
            'target_audience' => ['Students'],
            'age_group' => ['15-25'],
            'event_coordinator_name' => 'Coordinator C',
            'event_coordinator_contact_number' => '9876543212',
            'event_coordinator_desig' => 'Teacher',
            'photo_paths' => [],
            'unique_hash' => 'hash3',
            'submission_id' => 'sub3',
            'sync_status' => 'failed_permanently',
        ]);
    }

    /** @test */
    public function portal_displays_all_events_by_default()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.events.portal'));

        $response->assertStatus(200);
        $response->assertSee('Pending Event');
        $response->assertSee('Synced Event');
        $response->assertSee('Rejected Event');
    }

    /** @test */
    public function portal_can_filter_by_pending_status()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.events.portal', ['sync_status' => 'Pending']));

        $response->assertStatus(200);
        $response->assertSee('Pending Event');
        $response->assertDontSee('Synced Event');
        $response->assertDontSee('Rejected Event');
    }

    /** @test */
    public function portal_can_filter_by_synced_status()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.events.portal', ['sync_status' => 'Synced']));

        $response->assertStatus(200);
        $response->assertDontSee('Pending Event');
        $response->assertSee('Synced Event');
        $response->assertDontSee('Rejected Event');
    }

    /** @test */
    public function portal_can_filter_by_rejected_status()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.events.portal', ['sync_status' => 'Rejected/Failed']));

        $response->assertStatus(200);
        $response->assertDontSee('Pending Event');
        $response->assertDontSee('Synced Event');
        $response->assertSee('Rejected Event');
    }

    /** @test */
    public function portal_csv_export_filters_by_sync_status()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.events.portal.export', ['sync_status' => 'Synced']));

        $response->assertStatus(200);
        $csvContent = $response->streamedContent();
        
        $this->assertStringContainsString('Synced Event', $csvContent);
        $this->assertStringNotContainsString('Pending Event', $csvContent);
        $this->assertStringNotContainsString('Rejected Event', $csvContent);
    }

    /** @test */
    public function portal_pdf_export_filters_by_sync_status()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.events.pdf', ['sync_status' => 'Pending']));

        $response->assertStatus(200);
        $response->assertSee('Pending Event');
        $response->assertDontSee('Synced Event');
        $response->assertDontSee('Rejected Event');
        $response->assertSee('Sync Status: <strong>Pending</strong>', false);
    }
}
