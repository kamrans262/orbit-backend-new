<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM20AdvertisingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config([
            'orbit_advertising.enabled'=>true,
            'orbit_advertising.retention_approved'=>true,
            'orbit_advertising.age_protection_approved'=>true,
            'orbit_advertising.viewability_approved'=>true,
            'orbit_advertising.privacy_approved'=>true,
            'orbit_advertising.minimum_visible_ms'=>1000,
        ]);
        Storage::fake('local');
    }

    private function member(): array
    {
        $user=OrbitUser::create([
            'username'=>'sponsor_'.Str::random(12),
            'display_name'=>'Private Orbit member','status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'M20 device','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        $token=app(TokenService::class)->issue($user,$device,null)['access_token'];
        return [$user,$device,$token];
    }

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create([
            'name'=>'M20 role '.Str::uuid(),'permissions'=>$permissions,
        ]);
        $admin=AdminUser::create([
            'name'=>'Advertising operator',
            'email'=>Str::uuid().'@example.test',
            'password'=>'StrongTestPassword123!',
            'totp_secret'=>(new \App\Modules\Config\Services\Totp())->generateSecret(),
            'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    private function creativeReady(string $owner=''): array
    {
        $admin=$this->admin(['advertising.read','advertising.manage']);
        $sponsor=(string)Str::uuid();
        $creative=(string)Str::uuid();
        $campaign=(string)Str::uuid();
        DB::table('ad_sponsors')->insert([
            'id'=>$sponsor,'display_name'=>'Example sponsor','legal_name'=>'Example LLC',
            'approved_domain'=>'example.com','status'=>'verified',
            'verification_status'=>'verified','created_by'=>$admin->id,
            'verified_by'=>$admin->id,'verified_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        Storage::disk('local')->put('advertising/creatives/example.png','test-png-bytes');
        DB::table('ad_creatives')->insert([
            'id'=>$creative,'sponsor_id'=>$sponsor,'revision'=>1,
            'title'=>'Verified promotion','subtitle'=>'Safe supporting text',
            'private_path'=>'advertising/creatives/example.png',
            'sha256'=>str_repeat('a',64),'mime'=>'image/png',
            'width'=>600,'height'=>200,'cta_url'=>'https://example.com/product',
            'status'=>'approved','submitted_by'=>$admin->id,
            'reviewed_by'=>$admin->id,'reviewed_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('ad_campaigns')->insert([
            'id'=>$campaign,'sponsor_id'=>$sponsor,'creative_id'=>$creative,
            'name'=>'Demo campaign','creative_revision'=>1,'revision'=>3,
            'status'=>'active','starts_at'=>now()->subHour(),
            'ends_at'=>now()->addDay(),'priority'=>5,
            'created_by'=>$admin->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('ad_campaign_placements')->insert([
            'campaign_id'=>$campaign,'placement_key'=>'home.promo','created_at'=>now(),
        ]);
        DB::table('ad_placements')->where('key','home.promo')->update([
            'enabled'=>true,'daily_decision_cap'=>2,'daily_impression_cap'=>2,
        ]);
        return [$sponsor,$creative,$campaign];
    }

    public function test_erasure_purges_only_member_ad_state_and_reconciles_metrics(): void
    {
        $this->creativeReady();
        [$first,$d1,$token1]=$this->member();
        [$other,$d2,$token2]=$this->member();
        $one=$this->withToken($token1)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->json('data');
        $two=$this->withToken($token2)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->json('data');
        foreach ([[$one,$token1],[$two,$token2]] as [$d,$token]) {
            $this->withToken($token)->postJson('/api/v1/ads/measurements',[
                'decision_id'=>$d['decision_id'],'event_type'=>'impression',
                'client_event_id'=>(string)Str::uuid(),
                'measurement_token'=>$d['measurement_token'],
                'evidence'=>['visible_duration_ms'=>1600],
            ])->assertOk();
        }
        $this->assertDatabaseCount('ad_decisions',2);
        $this->assertSame(2,(int)DB::table('ad_daily_aggregates')->sum('impressions'));
        $counts=app(\App\Modules\Advertising\Services\AdvertisingErasureService::class)
            ->eraseViewer($first->id);
        $this->assertSame(['decisions'=>1,'measurement_events'=>1,
            'frequency_windows'=>1],$counts);
        $this->assertDatabaseCount('ad_decisions',1);
        $this->assertDatabaseCount('ad_measurement_events',1);
        $this->assertDatabaseCount('ad_frequency_windows',1);
        $this->assertSame(1,(int)DB::table('ad_daily_aggregates')->sum('impressions'));
        $this->assertDatabaseHas('ad_decisions',['id'=>$two['decision_id']]);
        $this->assertDatabaseMissing('ad_decisions',['id'=>$one['decision_id']]);
        $this->assertSame(['decisions'=>0,'measurement_events'=>0,
            'frequency_windows'=>0],app(\App\Modules\Advertising\Services\AdvertisingErasureService::class)
                ->eraseViewer($first->id));
    }

    public function test_disabled_by_default_and_trusted_capabilities_are_data_free(): void
    {
        $this->getJson('/api/v1/ads/capabilities')->assertUnauthorized();
        [$user,$device,$token]=$this->member();
        config(['orbit_advertising.enabled'=>false]);
        $this->withToken($token)->getJson('/api/v1/ads/capabilities')
            ->assertOk()->assertJsonPath('data.available',false)
            ; // Dot-containing placement keys are literal map keys.
        $this->assertFalse($this->withToken($token)
            ->getJson('/api/v1/ads/capabilities')->json('data.placements')['home.promo']);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertStatus(503);
        config(['orbit_advertising.enabled'=>true,
            'orbit_advertising.viewability_approved'=>false]);
        $this->withToken($token)->getJson('/api/v1/ads/capabilities')
            ->assertJsonPath('data.available',false);
        config(['orbit_advertising.viewability_approved'=>true]);
        $device->update(['trust_state'=>'pending']);
        $this->withToken($token)->getJson('/api/v1/ads/capabilities')->assertForbidden();
    }

    public function test_no_placements_are_enabled_at_migration_and_no_unapproved_surface(): void
    {
        [$user,$device,$token]=$this->member();
        $this->assertDatabaseHas('ad_placements',['key'=>'home.promo','enabled'=>0]);
        $this->assertDatabaseHas('ad_placements',['key'=>'moments.promo','enabled'=>0]);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->assertExactJson(['data'=>null]);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'sos.alert','request_id'=>(string)Str::uuid(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('ad_decisions',0);
    }

    public function test_real_decision_is_not_an_impression_and_measurement_is_idempotent(): void
    {
        [$sponsor,$creative,$campaign]=$this->creativeReady();
        [$user,$device,$token]=$this->member();
        $requestId=(string)Str::uuid();
        $payload=['placement'=>'home.promo','request_id'=>$requestId];
        $response=$this->withToken($token)->postJson('/api/v1/ads/decisions',$payload)
            ->assertOk()->assertJsonPath('data.creative.disclosure','Sponsored');
        $item=$response->json('data');
        $this->assertSame('https://example.com/product',$item['cta']['destination_url']);
        $response->assertDontSee($user->id)->assertDontSee('recipient_user_id')
            ->assertDontSee('location');
        $this->assertDatabaseCount('ad_decisions',1);
        $this->assertDatabaseCount('ad_measurement_events',0);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',$payload)
            ->assertJsonPath('data.decision_id',$item['decision_id']);
        $this->assertDatabaseCount('ad_decisions',1);
        $event=[
            'decision_id'=>$item['decision_id'],
            'event_type'=>'impression','client_event_id'=>(string)Str::uuid(),
            'measurement_token'=>$item['measurement_token'],
            'evidence'=>['visible_duration_ms'=>200],
        ];
        $this->withToken($token)->postJson('/api/v1/ads/measurements',$event)
            ->assertUnprocessable();
        $event['evidence']['visible_duration_ms']=1500;
        $this->withToken($token)->postJson('/api/v1/ads/measurements',$event)
            ->assertJsonPath('data.accepted',true)->assertJsonPath('data.deduplicated',false);
        $this->withToken($token)->postJson('/api/v1/ads/measurements',$event)
            ->assertJsonPath('data.deduplicated',true);
        $event['client_event_id']=(string)Str::uuid();
        $this->withToken($token)->postJson('/api/v1/ads/measurements',$event)
            ->assertJsonPath('data.deduplicated',true);
        $event['event_type']='click';
        $event['client_event_id']=(string)Str::uuid();
        $this->withToken($token)->postJson('/api/v1/ads/measurements',$event)
            ->assertJsonPath('data.accepted',true);
        $this->assertDatabaseHas('ad_daily_aggregates',[
            'campaign_id'=>$campaign,'placement_key'=>'home.promo',
            'impressions'=>1,'clicks'=>1,
        ]);
        $this->assertDatabaseCount('ad_measurement_events',2);
    }

    public function test_atomic_frequency_caps_across_distinct_and_replayed_requests(): void
    {
        $this->creativeReady();
        [$viewer,$device,$token]=$this->member();
        DB::table('ad_placements')->where('key','home.promo')->update([
            'daily_decision_cap'=>1,'daily_impression_cap'=>1,
        ]);
        $payload=['placement'=>'home.promo','request_id'=>(string)Str::uuid()];
        $first=$this->withToken($token)->postJson('/api/v1/ads/decisions',$payload)
            ->assertOk()->json('data.decision_id');
        $this->assertNotNull($first);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',$payload)
            ->assertJsonPath('data.decision_id',$first);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertExactJson(['data'=>null]);
        $this->assertDatabaseCount('ad_decisions',1);
        $window=DB::table('ad_frequency_windows')->first();
        $this->assertSame(1,(int)$window->decisions);
        $this->assertSame(0,(int)$window->impressions);
    }

    public function test_suspension_and_creative_revision_revoke_immediately(): void
    {
        [$sponsor,$creative,$campaign]=$this->creativeReady();
        [$viewer,$device,$token]=$this->member();
        $item=$this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->json('data');
        DB::table('ad_sponsors')->where('id',$sponsor)->update(['status'=>'suspended']);
        $this->withToken($token)->postJson('/api/v1/ads/measurements',[
            'decision_id'=>$item['decision_id'],'event_type'=>'impression',
            'client_event_id'=>(string)Str::uuid(),
            'measurement_token'=>$item['measurement_token'],
            'evidence'=>['visible_duration_ms'=>2000],
        ])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertExactJson(['data'=>null]);
        DB::table('ad_sponsors')->where('id',$sponsor)->update(['status'=>'verified']);
        DB::table('ad_creatives')->where('id',$creative)->update([
            'revision'=>2,'status'=>'pending',
        ]);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertExactJson(['data'=>null]);
    }

    public function test_signed_private_asset_requires_current_member_and_signature(): void
    {
        $this->creativeReady();
        [$viewer,$device,$token]=$this->member();
        $item=$this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->json('data');
        $url=$item['creative']['image_url'];
        $path=parse_url($url,PHP_URL_PATH).'?'.parse_url($url,PHP_URL_QUERY);
        $assetResponse=$this->withToken($token)->get($path)->assertOk();
        $cache=(string)$assetResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('private',$cache);
        $this->assertStringContainsString('no-store',$cache);
        $this->assertStringNotContainsString('public',$cache);
        [$other,$d2,$otherToken]=$this->member();
        $this->withToken($otherToken)->get($path)->assertNotFound();
        $this->withToken($token)->get(explode('?',$path)[0]
            .'?decision='.$item['decision_id'])->assertForbidden();
    }

    public function test_administration_is_independently_permissioned_and_audited(): void
    {
        $this->get('/admin/advertising')->assertRedirect(route('orbit.admin.login'));
        $outsider=$this->admin(['users.read','config.manage']);
        $this->be($outsider,'admin');
        $this->get('/admin/advertising')->assertForbidden();
        $readonly=$this->admin(['advertising.read']);
        $this->be($readonly,'admin');
        $this->get('/admin/advertising')->assertOk()
            ->assertSee('Advertisements &amp; Sponsorships',false);
        $this->post('/admin/advertising/sponsors',[
            'display_name'=>'Test Sponsor','legal_name'=>'Test LLC',
            'approved_domain'=>'example.com','reason'=>'Creating test sponsor',
            'confirm'=>'1',
        ])->assertForbidden();
        $creator=$this->admin(['advertising.read','advertising.manage']);
        $this->be($creator,'admin');
        $this->post('/admin/advertising/sponsors',[
            'display_name'=>'Test Sponsor','legal_name'=>'Test LLC',
            'approved_domain'=>'example.com','reason'=>'Creating approved sponsor record',
            'confirm'=>'1',
        ])->assertRedirect();
        $sponsor=DB::table('ad_sponsors')->first();
        $this->post('/admin/advertising/sponsors/'.$sponsor->id.'/status',[
            'status'=>'verified','reason'=>'Verify independent sponsor','confirm'=>'1',
        ])->assertForbidden();
        $reviewer=$this->admin(['advertising.read','advertising.manage','advertising.review',
            'advertising.analytics','advertising.audit']);
        $this->be($reviewer,'admin');
        $this->post('/admin/advertising/sponsors/'.$sponsor->id.'/status',[
            'status'=>'verified','reason'=>'Independent sponsor verification',
            'confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('ad_sponsors',[
            'id'=>$sponsor->id,'status'=>'verified',
        ]);
        $this->assertDatabaseHas('ad_admin_audits',[
            'subject_type'=>'sponsor','subject_id'=>$sponsor->id,
            'old_state'=>'draft','new_state'=>'verified',
        ]);
        $this->get('/admin/advertising/audits')->assertOk()
            ->assertSee('Independent sponsor verification');
        $this->get('/admin/advertising/analytics')->assertOk()
            ->assertDontSee('recipient_user_id');
    }

    public function test_uploaded_creative_must_be_reviewed_by_independent_operator_and_revised_again(): void
    {
        [$sponsor,$existing,$campaign]=$this->creativeReady();
        $manager=$this->admin(['advertising.read','advertising.manage']);
        $this->be($manager,'admin');
        $data=[
            'sponsor_id'=>$sponsor,'title'=>'Original Sponsor Artwork',
            'subtitle'=>'Reviewed static asset with original copy',
            'cta_url'=>'https://example.com/original',
            'asset'=>\Illuminate\Http\UploadedFile::fake()->image('creative.png',600,200),
            'reason'=>'Submit original approved domain creative','confirm'=>'1',
        ];
        $this->post('/admin/advertising/creatives',$data)->assertRedirect();
        $creative=DB::table('ad_creatives')->where('title','Original Sponsor Artwork')->first();
        $this->assertNotNull($creative);
        $this->assertSame('pending',$creative->status);
        $this->assertSame('image/png',$creative->mime);
        $this->assertDatabaseHas('ad_creative_revisions',[
            'creative_id'=>$creative->id,'revision'=>1,'status'=>'pending',
            'title'=>'Original Sponsor Artwork',
        ]);
        Storage::disk('local')->assertExists($creative->private_path);
        $this->post('/admin/advertising/creatives/'.$creative->id.'/review',[
            'revision'=>1,'action'=>'approved',
            'reason'=>'Attempt creator self review','confirm'=>'1',
        ])->assertForbidden();

        $reviewer=$this->admin(['advertising.read','advertising.review']);
        $this->be($reviewer,'admin');
        $this->post('/admin/advertising/creatives/'.$creative->id.'/review',[
            'revision'=>2,'action'=>'approved',
            'reason'=>'Intentionally stale creative review','confirm'=>'1',
        ])->assertStatus(409);
        $this->post('/admin/advertising/creatives/'.$creative->id.'/review',[
            'revision'=>1,'action'=>'approved',
            'reason'=>'Reviewed the exact static image','confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('ad_creatives',[
            'id'=>$creative->id,'revision'=>1,'status'=>'approved',
            'reviewed_by'=>$reviewer->id,
        ]);
        $this->assertDatabaseHas('ad_creative_revisions',[
            'creative_id'=>$creative->id,'revision'=>1,'status'=>'approved',
            'reviewed_by'=>$reviewer->id,
        ]);

        $this->be($manager,'admin');
        $data['title']='Revised Creative Artwork';
        $data['asset']=\Illuminate\Http\UploadedFile::fake()->image('revision.jpg',600,200);
        $this->post('/admin/advertising/creatives/'.$creative->id.'/revise',$data)
            ->assertRedirect();
        $this->assertDatabaseHas('ad_creatives',[
            'id'=>$creative->id,'revision'=>2,'status'=>'pending','reviewed_by'=>null,
        ]);
        $this->assertDatabaseHas('ad_creative_revisions',[
            'creative_id'=>$creative->id,'revision'=>1,'status'=>'approved',
            'title'=>'Original Sponsor Artwork',
        ]);
        $this->assertDatabaseHas('ad_creative_revisions',[
            'creative_id'=>$creative->id,'revision'=>2,'status'=>'pending',
            'title'=>'Revised Creative Artwork',
        ]);
        $this->assertSame(2,DB::table('ad_creative_revisions')
            ->where('creative_id',$creative->id)->count());
    }

    public function test_campaign_edits_invalidate_approval_and_scheduled_state_is_time_scoped(): void
    {
        [$sponsor,$creative,$campaign]=$this->creativeReady();
        [$viewer,$device,$token]=$this->member();
        $before=$this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->json('data');
        $this->assertNotNull($before);
        $manager=$this->admin(['advertising.read','advertising.manage']);
        $this->be($manager,'admin');
        $this->post('/admin/advertising/campaigns/'.$campaign.'/edit',[
            'revision'=>3,'name'=>'Reviewed edited campaign','sponsor_id'=>$sponsor,
            'creative_id'=>$creative,
            'starts_at'=>now()->subMinutes(3)->format('Y-m-d H:i:s'),
            'ends_at'=>now()->addHours(6)->format('Y-m-d H:i:s'),
            'priority'=>3,'placements'=>['home.promo'],
            'reason'=>'Material campaign edit resets approval','confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('ad_campaigns',[
            'id'=>$campaign,'status'=>'draft','revision'=>4,
            'created_by'=>$manager->id,'reviewed_by'=>null,
        ]);
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertExactJson(['data'=>null]);
        $this->post('/admin/advertising/campaigns/'.$campaign.'/transition',[
            'revision'=>4,'status'=>'pending',
            'reason'=>'Submit modified campaign for review','confirm'=>'1',
        ])->assertRedirect();
        $this->post('/admin/advertising/campaigns/'.$campaign.'/transition',[
            'revision'=>5,'status'=>'approved',
            'reason'=>'Cannot self approve own campaign','confirm'=>'1',
        ])->assertForbidden();
        $reviewer=$this->admin(['advertising.read','advertising.review']);
        $this->be($reviewer,'admin');
        $this->post('/admin/advertising/campaigns/'.$campaign.'/transition',[
            'revision'=>5,'status'=>'approved',
            'reason'=>'Independently approve revised campaign','confirm'=>'1',
        ])->assertRedirect();
        $this->be($manager,'admin');
        $this->post('/admin/advertising/campaigns/'.$campaign.'/transition',[
            'revision'=>6,'status'=>'scheduled',
            'reason'=>'Schedule independently approved campaign','confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('ad_campaigns',[
            'id'=>$campaign,'status'=>'scheduled','revision'=>7,
        ]);
        $decision=$this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertOk()->json('data');
        $this->assertNotNull($decision);
        $this->post('/admin/advertising/campaigns/'.$campaign.'/transition',[
            'revision'=>7,'status'=>'paused',
            'reason'=>'Pause scheduled campaign immediately','confirm'=>'1',
        ])->assertRedirect();
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertExactJson(['data'=>null]);
    }

    public function test_sponsor_destination_edit_removes_verification_immediately(): void
    {
        [$sponsor,$creative,$campaign]=$this->creativeReady();
        $manager=$this->admin(['advertising.read','advertising.manage']);
        $this->be($manager,'admin');
        $this->post('/admin/advertising/sponsors/'.$sponsor.'/edit',[
            'display_name'=>'Updated sponsor name',
            'legal_name'=>'Updated corporate entity',
            'approved_domain'=>'newdomain.example',
            'reason'=>'Review updated domain verification needed',
            'confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('ad_sponsors',[
            'id'=>$sponsor,'status'=>'draft',
            'verification_status'=>'pending','verified_by'=>null,
            'approved_domain'=>'newdomain.example',
        ]);
        [$viewer,$dev,$token]=$this->member();
        $this->withToken($token)->postJson('/api/v1/ads/decisions',[
            'placement'=>'home.promo','request_id'=>(string)Str::uuid(),
        ])->assertExactJson(['data'=>null]);
    }

    public function test_rejects_unsafe_domain_and_unapproved_campaign_mutations(): void
    {
        $this->creativeReady();
        $creator=$this->admin(['advertising.read','advertising.manage']);
        $this->be($creator,'admin');
        $this->post('/admin/advertising/sponsors',[
            'display_name'=>'Unsafe','legal_name'=>'Unsafe LLC',
            'approved_domain'=>'127.0.0.1','reason'=>'Reject local host domains',
            'confirm'=>'1',
        ])->assertStatus(422);
        $this->post('/admin/advertising/campaigns',[
            'name'=>'Bad campaign','sponsor_id'=>(string)Str::uuid(),
            'creative_id'=>(string)Str::uuid(),
            'starts_at'=>now()->toDateTimeString(),
            'ends_at'=>now()->addDay()->toDateTimeString(),
            'priority'=>5,'placements'=>['messages.private'],
            'reason'=>'Never advertise on private messages','confirm'=>'1',
        ])->assertSessionHasErrors();
    }
}
