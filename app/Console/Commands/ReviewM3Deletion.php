<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Modules\Profile\Services\M3ErasureProcessor;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ReviewM3Deletion extends Command
{
    protected $signature = 'orbit:deletions:review
        {request_id : UUID of an existing user deletion request}
        {--execute : Actually erase current M1-M3 account data; default is dry-run}
        {--operator= : Approved admin UUID with users.delete AND deletions.process}
        {--ticket= : Non-sensitive approved change/ticket reference}';

    protected $description = 'Inspect deletion eligibility; erasure is disabled unless explicitly authorized.';

    public function handle(M3ErasureProcessor $processor): int
    {
        try {
            $id = (string) $this->argument('request_id');
            if (!Str::isUuid($id)) throw new DomainException('Invalid deletion request UUID.');
            $preview = $processor->preview($id);
            // Output intentionally excludes raw user UUID, contacts, preferences.
            $this->line(json_encode($preview, JSON_THROW_ON_ERROR));
            if (!$this->option('execute')) {
                $this->info('DRY RUN: no database records were changed.');
                return self::SUCCESS;
            }
            $operatorId = (string) $this->option('operator');
            $operator = Str::isUuid($operatorId)
                ? AdminUser::whereKey($operatorId)->where('status', 'active')->first()
                : null;
            if (!$operator) throw new DomainException('An active authorized admin operator is required.');
            $result = $processor->execute($id, $operator, (string) $this->option('ticket'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (DomainException $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
