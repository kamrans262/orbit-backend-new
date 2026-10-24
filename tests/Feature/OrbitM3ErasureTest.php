<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitUser;
use App\Models\UserIdentity;
use App\Modules\Config\Services\Totp;
use App\Modules\Profile\Services\M3ErasureProcessor;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM3ErasureTest extends TestCase
{
    use RefreshDatabase;

    private function operator(array $permissions = ['users.delete','deletions.process']): AdminUser
    {
        $role = AdminRole::create(['name' => 'Erasure operator '.Str::uuid(),
            'permissions' => $permissions]);
        $operator = AdminUser::create([
            'name' => 'Erasure reviewer', 'email' => Str::uuid().'@test.example',
            'password' => 'Secure123!password',
            'totp_secret' => (new Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $operator->roles()->attach($role->id);
        return $operator;
    }

    private function request(bool $mature = true): array
    {
        $user = OrbitUser::create([
            'display_name'=>'Sensitive profile',
            'username'=>'u_'.Str::lower(Str::random(10)),
            'status'=>'active',
            'deletion_requested_at'=>now()->subDays($mature ? 31 : 1),
        ]);
        UserIdentity::create(['user_id'=>$user->id,'provider'=>'email',
            'provider_subject'=>Str::random(12).'@example.test',
            'email_norm'=>Str::random(12).'@example.test', 'verified_at'=>now()]);
        DB::table('orbit_user_preferences')->insert([
            'user_id'=>$user->id,
            'privacy'=>json_encode(['location_visibility'=>'Nobody','marker'=>'PRIVATE_LOCATION']),
            'notifications'=>json_encode(['marker'=>'PRIVATE_NOTIFICATIONS']),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $requestId=(string) Str::uuid();
        DB::table('orbit_account_deletion_requests')->insert([
            'request_id'=>$requestId,'user_id'=>$user->id,'status'=>'pending',
            'requested_at'=>now()->subDays($mature ? 31 : 1),
            'eligible_for_erasure_at'=>$mature ? now()->subDay() : now()->addDays(29),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$user,$requestId];
    }

    public function test_dry_run_never_changes_personal_data_and_production_execution_defaults_off(): void
    {
        [$user,$id] = $this->request();
        $this->artisan('orbit:deletions:review', ['request_id'=>$id])
            ->expectsOutputToContain('DRY RUN: no database records were changed.')
            ->assertExitCode(0);
        $this->assertDatabaseHas('orbit_users',['id'=>$user->id]);
        $this->assertDatabaseCount('orbit_erasure_receipts',0);
        $this->expectException(DomainException::class);
        app(M3ErasureProcessor::class)->execute($id,$this->operator(),'CHANGE-123456');
    }

    public function test_manual_processing_requires_verified_operator_and_mature_request(): void
    {
        config()->set('orbit_erasure.execution_enabled',true);
        [$user,$id]=$this->request(false);
        try {
            app(M3ErasureProcessor::class)->execute($id,$this->operator(),'CHANGE-123456');
            $this->fail('Young deletion request incorrectly erased');
        } catch (DomainException $e) {
            $this->assertStringContainsString('grace period',$e->getMessage());
        }
        DB::table('orbit_account_deletion_requests')->where('request_id',$id)
            ->update(['eligible_for_erasure_at'=>now()->subSecond()]);
        $this->expectException(DomainException::class);
        app(M3ErasureProcessor::class)->execute($id,$this->operator(['users.delete']),'CHANGE-123456');
    }

    public function test_retention_hold_blocks_erasure_until_released(): void
    {
        config()->set('orbit_erasure.execution_enabled',true);
        [$user,$id]=$this->request();
        DB::table('orbit_data_retention_holds')->insert([
            'id'=>(string) Str::uuid(), 'user_id'=>$user->id,
            'reason_code'=>'legal_review','created_at'=>now(),'updated_at'=>now(),
        ]);
        try {
            app(M3ErasureProcessor::class)->execute($id,$this->operator(),'CHANGE-123456');
            $this->fail('Active retention hold was ignored');
        } catch (DomainException $e) {
            $this->assertStringContainsString('retention hold',$e->getMessage());
        }
        $this->assertDatabaseHas('orbit_users',['id'=>$user->id]);
        $this->assertDatabaseCount('orbit_erasure_receipts',0);
    }

    public function test_current_scope_erases_only_approved_user_records_and_preserves_anonymous_receipt(): void
    {
        config()->set('orbit_erasure.execution_enabled',true);
        [$user,$id]=$this->request();
        [$other,$otherId]=$this->request();
        $operator=$this->operator();
        $result=app(M3ErasureProcessor::class)->execute($id,$operator,'CHANGE-123456');
        $this->assertSame(['status'=>'completed','scope'=>'identity_profile_social_m4'],$result);
        $this->assertDatabaseMissing('orbit_users',['id'=>$user->id]);
        $this->assertDatabaseMissing('user_identities',['user_id'=>$user->id]);
        $this->assertDatabaseMissing('orbit_user_preferences',['user_id'=>$user->id]);
        $this->assertDatabaseMissing('orbit_account_deletion_requests',['request_id'=>$id]);
        $this->assertDatabaseHas('orbit_users',['id'=>$other->id]);
        $this->assertDatabaseHas('orbit_account_deletion_requests',['request_id'=>$otherId]);
        $receipt=DB::table('orbit_erasure_receipts')->where('request_id',$id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('identity_profile_social_m4',$receipt->scope);
        $this->assertNotSame($user->id,$receipt->user_reference_hash);
        $this->assertStringNotContainsString($user->id,json_encode($receipt));
        $this->assertStringNotContainsString('PRIVATE_LOCATION',json_encode($receipt));
        $this->assertSame(['status'=>'completed','scope'=>'identity_profile_social_m4'],
            app(M3ErasureProcessor::class)->execute($id,$operator,'CHANGE-123456'));
        $this->assertDatabaseCount('orbit_erasure_receipts',1);
    }

    public function test_cancelled_request_is_never_eligible_for_processing(): void
    {
        config()->set('orbit_erasure.execution_enabled',true);
        [$user,$id]=$this->request();
        DB::table('orbit_account_deletion_requests')->where('request_id',$id)
            ->update(['status'=>'cancelled','cancelled_at'=>now()]);
        $this->expectException(DomainException::class);
        app(M3ErasureProcessor::class)->execute($id,$this->operator(),'CHANGE-123456');
    }

    public function test_admin_cannot_bypass_pending_grace_period_by_direct_delete(): void
    {
        [$user, $id] = $this->request();
        $this->be($this->operator(['users.delete', 'users.read']), 'admin');
        $this->delete('/admin/users/'.$user->id, [
            'confirmation' => 'DELETE',
            'reason' => 'Reviewed user privacy request',
        ])->assertStatus(409);
        $this->assertDatabaseHas('orbit_users', ['id' => $user->id]);
        $this->assertDatabaseHas('orbit_account_deletion_requests', [
            'request_id' => $id, 'status' => 'pending',
        ]);
        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertDontSee('data-open-delete-user', false);
    }
    public function test_m7_metadata_erasure_requires_verified_storage_cleanup_then_removes_private_references():void
    {
        config()->set('orbit_erasure.execution_enabled',true);
        [$owner,$request]=$this->request();
        $media=(string)Str::uuid7();$moment=(string)Str::uuid7();$report=(string)Str::uuid7();
        DB::table('media_objects')->insert([
            'id'=>$media,'owner_user_id'=>$owner->id,'purpose'=>'moment',
            'kind'=>'image','declared_mime'=>'image/jpeg','cipher_suite'=>'aes256gcm-v1',
            'object_key'=>'orbit-private/m7/'.Str::uuid(),'state'=>'ready',
            'cipher_bytes'=>64,'cipher_sha256'=>str_repeat('a',64),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('moments')->insert([
            'id'=>$moment,'creator_id'=>$owner->id,'client_id'=>(string)Str::uuid(),
            'request_key_hash'=>str_repeat('1',64),'request_payload_hash'=>str_repeat('2',64),
            'state'=>'published','audience_kind'=>'only_me','published_at'=>now(),
            'rail_expires_at'=>now()->addDay(),'view_expires_at'=>now()->addDays(7),
            'purge_due_at'=>now()->addDays(30),'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('moment_media')->insert(['moment_id'=>$moment,'media_id'=>$media,'role'=>'primary','position'=>0,'created_at'=>now()]);
        DB::table('media_references')->insert([
            'id'=>(string)Str::uuid7(),'media_id'=>$media,'owner_user_id'=>$owner->id,
            'consumer_type'=>'moment','consumer_id'=>$moment,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('moment_reports')->insert([
            'id'=>$report,'moment_id'=>$moment,'reporter_id'=>$owner->id,'context_id'=>'self',
            'reason'=>'privacy','status'=>'pending','evidence_consent'=>false,
            'request_key_hash'=>str_repeat('3',64),'request_payload_hash'=>str_repeat('4',64),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        try {
            app(M3ErasureProcessor::class)->execute($request,$this->operator(),'ERASURE-CHANGE-789');
            $this->fail('Storage-backed Moment erased without ciphertext purge');
        }catch(DomainException $error){
            $this->assertStringContainsString('ciphertext purge',$error->getMessage());
        }
        $this->assertDatabaseHas('moments',['id'=>$moment]);
        DB::table('media_objects')->where('id',$media)->update(['state'=>'deleted','deleted_at'=>now()]);
        $result=app(M3ErasureProcessor::class)->execute($request,$this->operator(),'ERASURE-CHANGE-789');
        $this->assertSame('completed',$result['status']);
        $this->assertDatabaseMissing('orbit_users',['id'=>$owner->id]);
        $this->assertDatabaseMissing('moments',['id'=>$moment]);
        $this->assertDatabaseMissing('media_references',['media_id'=>$media]);
        $this->assertDatabaseMissing('moment_reports',['id'=>$report]);
        $this->assertDatabaseMissing('media_objects',['id'=>$media]);
    }

}
