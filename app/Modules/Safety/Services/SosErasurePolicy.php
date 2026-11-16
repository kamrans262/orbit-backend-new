<?php

namespace App\Modules\Safety\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M16 account-erasure boundary. Incident-linked records include delivery
 * attempts and an audit trail; generic FK cascade must never erase those
 * records without an explicit approved retention and incident plan.
 *
 * Contact requests with no incident involvement are safely FK-cascaded.
 */
final class SosErasurePolicy
{
    public function assertErasable(string $userId): void
    {
        if (!Schema::hasTable('sos_incidents')
            || !Schema::hasTable('sos_recipients')) return;

        $initiated = DB::table('sos_incidents')
            ->where('initiator_id', $userId)->exists();
        $received = DB::table('sos_recipients')
            ->where('user_id', $userId)->exists();

        if ($initiated || $received) {
            throw new DomainException(
                'SOS incident evidence requires an authorized retention and erasure review.'
            );
        }
    }
}
