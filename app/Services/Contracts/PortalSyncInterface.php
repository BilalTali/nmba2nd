<?php

namespace App\Services\Contracts;

use App\Models\Event;

/**
 * Contract for the portal synchronization service.
 * Concrete implementations must handle stateful HTTP session management,
 * CSRF token extraction, and multipart form submission to the target portal.
 *
 * Slot-aware design: each instance is assigned a session slot (0–4) that isolates
 * its cookie jar and transmission lock from all other parallel workers.
 */
interface PortalSyncInterface
{
    /**
     * Establish (or reuse) an authenticated portal session for this slot.
     * Must be called once before processing a batch of events so the
     * Guzzle client is ready and the session cookie is valid.
     *
     * @throws \App\Exceptions\TransientSyncException  If login fails due to network issues.
     * @throws \App\Exceptions\AuthenticationSyncException If credentials are rejected.
     */
    public function ensureAuthenticated(): void;

    /**
     * Synchronize a local event record to the external portal.
     * Reuses the client/session established by ensureAuthenticated() if available.
     *
     * @param Event $event The event model to synchronize.
     * @return bool True on confirmed successful submission.
     * @throws \App\Exceptions\TransientSyncException For retriable network/session faults.
     * @throws \App\Exceptions\PermanentSyncException For unrecoverable validation/config faults.
     */
    public function sync(Event $event): bool;
}
