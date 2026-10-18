<?php

namespace App\Console\Commands;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Modules\Config\Services\Totp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProvisionOrbitAdmin extends Command
{
    protected $signature = 'orbit:admin:provision';
    protected $description = 'Provision the first Orbit administrator with required TOTP MFA (secure CLI only)';

    public function handle(Totp $totp): int
    {
        if (AdminUser::query()->exists()) {
            $this->error('An administrator already exists. No automatic privileged account will be added.');
            return self::FAILURE;
        }
        $name = trim((string) $this->ask('Administrator display name'));
        $email = strtolower(trim((string) $this->ask('Administrator email')));
        $password = (string) $this->secret('New password (minimum 12 characters)');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->error('Name, a valid email and a 12+ character password are required.');
            return self::FAILURE;
        }
        $secret = $totp->generateSecret();
        DB::transaction(function () use ($name, $email, $password, $secret): void {
            $role = AdminRole::query()->firstOrCreate(['name' => 'Super Admin'], [
                'permissions' => ['*'],
            ]);
            $admin = AdminUser::query()->create([
                'name' => $name, 'email' => $email, 'password' => $password, 'totp_secret' => $secret, 'status' => 'active',
            ]);
            $admin->roles()->attach($role->id);
        });
        $this->warn('ONE-TIME MFA ENROLLMENT: Keep the following secret private. This output must not be logged.');
        $this->line('Authenticator setup key: '.$secret);
        $this->line('Authenticator URI: otpauth://totp/Orbit:'.rawurlencode($email).'?secret='.$secret.'&issuer=Orbit&algorithm=SHA1&digits=6&period=30');
        $this->info('Administrator created. Add the key to an authenticator before signing in.');
        return self::SUCCESS;
    }
}
