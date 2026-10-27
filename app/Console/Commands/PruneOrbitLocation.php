<?php

namespace App\Console\Commands;

use App\Modules\Location\Services\LocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Must be explicitly scheduled after operational review; never auto-run on production. */
final class PruneOrbitLocation extends Command
{
    protected $signature='orbit:location:prune';
    protected $description='Purge expired M10 encrypted samples and aged consent metadata';

    public function handle(LocationService $location): int
    {
        if (!Schema::hasTable('location_latest')) {
            $this->error('M10 database tables not installed.');
            return self::FAILURE;
        }
        $result=$location->prune();
        // Aggregate totals only; never echo coordinates, recipients or account IDs.
        $this->info('M10 retention sweep complete: '.json_encode($result));
        return self::SUCCESS;
    }
}
