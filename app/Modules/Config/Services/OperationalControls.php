<?php
namespace App\Modules\Config\Services;

use App\Models\AdminUser;
use App\Models\RemoteConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * M24 allowlist of existing HTTP and bounded worker policy consumers. No infrastructure,
 * provider credentials, release switches, user preferences or retention
 * deletion settings are writable here. Numeric caps cannot exceed the
 * deployment's original configured bounds or the reviewed absolute bounds.
 */
final class OperationalControls
{
    public const PREFIX='m24.operations.';
    // Field => [actual config path, visible label, minimum, absolute maximum].
    public const MODULES=[
        'identity'=>['Accounts','users.read','users.manage',[
            'otp_requests'=>['orbit_identity.otp_request_per_recipient','Verification requests per recipient',1,3],
            'otp_ip_hour'=>['orbit_identity.otp_request_per_ip_hour','Verification requests per IP per hour',1,10],
            'otp_attempts'=>['orbit_identity.otp_verify_per_recipient','Verification attempts per recipient',1,5],
        ]],
        'social'=>['Friends & Blocking','social.read','social.manage',[
            'daily_requests'=>['orbit_social.daily_outbound_limit','Daily outbound requests',1,20],
            'new_account_requests'=>['orbit_social.new_account_daily_limit','New account daily requests',1,10],
            'pending_requests'=>['orbit_social.pending_outbound_limit','Pending outbound requests',1,20],
            'invite_days'=>['orbit_social.friend_invite_lifetime_days','Invitation validity (days)',1,7],
            'active_invites'=>['orbit_social.active_friend_invite_limit','Active invitation links',1,5],
            'search_minute'=>['orbit_social.search_per_account_per_minute','Discovery searches per account per minute',1,60],
            'contact_batch'=>['orbit_social.contact_batch_limit','Contacts per consented matching request',1,25],
            'contact_daily'=>['orbit_social.contact_daily_lookup_limit','Daily contact lookup allowance',1,500],
        ]],
        'circles'=>['Circles','circles.read','circles.manage',[
            'invite_hours'=>['orbit_circle.targeted_invite_lifetime_hours','Targeted invitation validity (hours)',1,168],
        ]],
        'media'=>['Encrypted Media','media.read','media.manage',[
            'owner_quota'=>['orbit_media.per_owner_cipher_quota_bytes','Owner ciphertext quota (bytes)',26214400,2147483648],
            'concurrent_uploads'=>['orbit_media.max_concurrent_uploads','Concurrent uploads per account',1,3],
            'daily_uploads'=>['orbit_media.daily_upload_limit','Daily uploads per account',1,200],
            'upload_hours'=>['orbit_media.upload_session_hours','New upload session validity (hours)',1,2],
        ]],
        'moments'=>['Moments','moments.read','moments.manage',[
            'recipient_limit'=>['orbit_moments.max_recipients','Publication recipient limit',1,100],
            'feed_page'=>['orbit_moments.max_feed_page','Maximum feed page size',1,30],
        ]],
        'dashboard'=>['Home Dashboard','config.manage','config.manage',[
            'circle_limit'=>['orbit_dashboard.circle_limit','Maximum circles in summary',1,8],
            'people_limit'=>['orbit_dashboard.people_limit','Maximum people in summary',1,12],
            'moment_limit'=>['orbit_dashboard.moment_limit','Maximum moments in summary',1,3],
            'dismiss_hours'=>['orbit_dashboard.dismiss_hours','Smart-card dismissal (hours)',1,24],
            'show_circles'=>['orbit_dashboard.sections.circles','Show circle summary',0,1],
            'show_people'=>['orbit_dashboard.sections.people','Show people summary',0,1],
            'show_moments'=>['orbit_dashboard.sections.moments','Show Moments summary',0,1],
        ]],
        'presence'=>['Presence','config.manage','config.manage',[
            'circle_members'=>['orbit_presence.max_circle_members','Maximum circle snapshot members',1,50],
            'typing_seconds'=>['orbit_presence.typing_ttl_seconds','Typing indicator validity (seconds)',3,5],
        ]],
        'location'=>['Location Sharing','config.manage','config.manage',[
            'sample_age'=>['orbit_location.sample_max_age_seconds','Maximum incoming sample age (seconds)',15,90],
            'map_candidates'=>['orbit_location.max_map_candidates','Maximum map candidates',1,200],
            'accuracy'=>['orbit_location.max_accuracy_m','Maximum accepted sample uncertainty (metres)',50,500],
            'latest_ttl'=>['orbit_location.latest_ttl_seconds','Maximum visible sample age (seconds)',30,120],
            'fresh_seconds'=>['orbit_location.fresh_seconds','Fresh sample threshold (seconds)',5,30],
        ]],
        'ping'=>['Ping','config.manage','config.manage',[
            'sender_minute'=>['orbit_ping.sender_per_minute','Requests per sender per minute',1,12],
            'pair_hour'=>['orbit_ping.pair_per_hour','Requests per sender/recipient per hour',1,6],
            'page_size'=>['orbit_ping.page_size_max','Maximum inbox page size',1,30],
        ]],
        'messaging'=>['Messaging','config.manage','config.manage',[
            'sync_limit'=>['orbit_messaging.sync_max','Maximum ciphertext sync batch',1,200],
        ]],
        'notifications'=>['Notifications','notifications.read','notifications.manage',[
            'page_size'=>['orbit_notifications.max_page','Maximum inbox page size',1,30],
            'project_batch'=>['orbit_notifications.project_batch','Maximum notification projection batch',1,75],
            'dispatch_batch'=>['orbit_notifications.dispatch_batch','Maximum notification dispatch batch',1,40],
        ]],
        'plans'=>['Plans & Reminders','plans.read','plans.manage',[
            'invitees'=>['orbit_plans.max_invitees','Maximum invitees for new or updated plans',1,100],
            'page_size'=>['orbit_plans.page_limit','Maximum plan page size',1,30],
            'worker_batch'=>['orbit_plans.worker_batch','Maximum reminder worker batch',1,40],
            'circle_limit'=>['orbit_plans.max_circles','Maximum invited Circles per plan',1,5],
        ]],
        'activity'=>['Activity Feed','activity.read','activity.manage',[
            'page_size'=>['orbit_activity.feed_page_max','Maximum feed page size',1,30],
            'project_batch'=>['orbit_activity.project_batch','Maximum activity projection batch',1,60],
        ]],
        'advertising'=>['Advertisements & Sponsorships','advertising.read','advertising.manage',[
            'asset_bytes'=>['orbit_advertising.asset_max_bytes','Maximum newly uploaded creative size (bytes)',65536,4194304],
        ]],
        'check-in'=>['Check-In','config.manage','config.manage',[
            'sender_minute'=>['orbit_check_in.sender_per_minute','Publications per sender per minute',1,10],
            'audience_scopes'=>['orbit_check_in.max_audience_scopes','Maximum audience scopes',1,25],
            'snapshot_recipients'=>['orbit_check_in.max_snapshot_recipients','Maximum snapshot recipients',1,1000],
            'expiry_days'=>['orbit_check_in.max_expiry_days','Maximum new/updated expiry (days)',1,30],
            'page_size'=>['orbit_check_in.page_size_max','Maximum feed page size',1,30],
        ]],
    ];
    // Stage 2 rows did not have these fields. Missing newly introduced fields
    // preserve deployment defaults, not arbitrary or elevated values.
    private const LEGACY_OPTIONAL=[
        'location'=>['latest_ttl','fresh_seconds'],
        'social'=>['search_minute','contact_batch','contact_daily'],
        'notifications'=>['project_batch','dispatch_batch'],
        'plans'=>['worker_batch','circle_limit'],
        'activity'=>['project_batch'],
    ];
    private const WORKER_FIELDS=['notifications'=>['project_batch','dispatch_batch'],'plans'=>['worker_batch'],'activity'=>['project_batch']];
    public static function reserved(?string $key):bool
    {
        return $key!==null&&(str_starts_with(strtolower($key),self::PREFIX)||in_array(strtolower($key),['m24.operational_limits','m24.camera_tools'],true));
    }
    public static function keys():array{return array_map(fn($name)=>self::PREFIX.$name,array_keys(self::MODULES));}
    public function installed():bool{return Schema::hasTable('remote_configs');}
    public function definition(string $module):array{return self::MODULES[$module]??abort(404);}
    /** Deployment baseline before the request-scoped overlay. */
    public function ceiling(array $field):int{
        $base=request()->attributes->get('orbit_m24_baseline',[]);
        return min($field[3],(int)($base[$field[0]]??config($field[0])));
    }
    public function snapshot(string $module):array{
        $def=$this->definition($module);
        $row=$this->installed()?RemoteConfig::where('key',self::PREFIX.$module)->first():null;
        $values=[];
        foreach($def[3] as $key=>$field)$values[$key]=(int)config($field[0]);
        return ['revision'=>(int)($row?->version??0),'values'=>$values];
    }
    /** Validate stored data independently of the admin form or schema JSON. */
    private function decode(string $module,RemoteConfig $row):array{
        $fields=$this->definition($module)[3];$values=(array)$row->value;
        $required=array_diff_key($fields,array_flip(self::LEGACY_OPTIONAL[$module]??[]));
        abort_unless($row->published&&$row->value_type==='object'&&!$row->client_visible&&
            !array_diff_key($required,$values)&&!array_diff_key($values,$fields),503,'Operational controls require review.');
        foreach($fields as $key=>$field){
            if(!array_key_exists($key,$values))continue;
            $v=$values[$key]??null;
            abort_unless(is_int($v)&&$v>=$field[2]&&$v<=$field[3],503,'Operational controls require review.');
        }
        return $values;
    }
    public function publicHints():?array{
        if(!$this->installed())return null;
        $out=[];
        foreach(['dashboard','ping','check-in'] as $module){
            $row=RemoteConfig::where('key',self::PREFIX.$module)->first();
            if(!$row)continue;
            $this->decode($module,$row);
            $out[$module]=$this->snapshot($module);
        }
        return $out===[]?null:['schema_version'=>1,'modules'=>$out];
    }
    /** Resolve a named worker cap directly at execution, including Artisan and queue processes.
     * No global config mutation, no worker activation, no increase over caller or deployment limits.
     */
    public function workerLimit(string $module,string $key,int $requested):int{
        if(!in_array($key,self::WORKER_FIELDS[$module]??[],true))throw new \InvalidArgumentException('Unsupported operational worker control.');
        $field=$this->definition($module)[3][$key];
        $ceiling=min($field[3],max(1,(int)config($field[0],$field[3])));
        $row=$this->installed()?RemoteConfig::where('key',self::PREFIX.$module)->first():null;
        $values=$row?$this->decode($module,$row):[];
        return max(1,min($requested,$ceiling,$values[$key]??$ceiling));
    }
    /** Request-scoped overlay for existing HTTP consumers. */
    public function overrides():array{
        if(!$this->installed())return [];
        $out=[];
        foreach(RemoteConfig::whereIn('key',self::keys())->get() as $row){
            $module=substr($row->key,strlen(self::PREFIX));$values=$this->decode($module,$row);
            foreach(self::MODULES[$module][3] as $key=>$field){
                if(!array_key_exists($key,$values))continue;
                // Deployment-level restrictions always win over saved controls.
                $out[$field[0]]=min($values[$key],(int)config($field[0]));
            }
        }
        return $out;
    }
    public function rollback(string $module,string $snapshot,int $expected,string $reason,AdminUser $actor):void{
        $row=DB::table('config_revisions')->where('id',$snapshot)->where('resource_type','remote')->where('resource_key',self::PREFIX.$module)->first();
        abort_unless($row,404);
        $saved=json_decode($row->snapshot,true,32,JSON_THROW_ON_ERROR);
        $values=$saved['value']??null;
        abort_unless(is_array($values),422,'Historical settings unavailable.');
        // A pre-expansion revision had no value for new fields. Restore its
        // deployment-default behavior, bounded by the current ceiling.
        foreach(self::LEGACY_OPTIONAL[$module]??[] as $key){
            if(!array_key_exists($key,$values))$values[$key]=$this->ceiling($this->definition($module)[3][$key]);
        }
        // Save is the same validation/ownership/revision path as a new edit.
        $this->save($module,$values,$expected,substr('Restore revision '.$row->version.': '.$reason,0,500),$actor);
    }
    public function save(string $module,array $values,int $revision,string $reason,AdminUser $actor):void{
        $def=$this->definition($module);
        abort_unless($actor->canPerform($def[1])&&$actor->canPerform($def[2]),403);
        abort_unless($this->installed(),503,'Operational controls not provisioned.');
        $properties=[];$normalized=[];
        foreach($def[3] as $key=>$field){
            $v=$values[$key]??null;
            if(!is_int($v)||$v<$field[2]||$v>$this->ceiling($field))
                throw ValidationException::withMessages(['values.'.$key=>'Value exceeds the approved operational range.']);
            $properties[$key]=['type'=>'number','minimum'=>$field[2],'maximum'=>$field[3]];
            $normalized[$key]=$v;
        }
        abort_if(array_diff_key($values,$def[3]),422);
        if($module==='social'&&$normalized['new_account_requests']>$normalized['daily_requests'])
            throw ValidationException::withMessages(['values.new_account_requests'=>'New-account limit cannot exceed the general daily limit.']);
        try{
            DB::transaction(function()use($module,$revision,$normalized,$properties,$actor,$reason,$def){
                $row=RemoteConfig::where('key',self::PREFIX.$module)->lockForUpdate()->first();
                abort_unless((int)($row?->version??0)===$revision,409,'Settings changed. Reload and try again.');
                app(ConfigRegistry::class)->saveConfig([
                    'key'=>self::PREFIX.$module,'description'=>$def[0].' operational limits',
                    'value_type'=>'object','value_json'=>json_encode($normalized,JSON_THROW_ON_ERROR),
                    'schema_json'=>json_encode(['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false],JSON_THROW_ON_ERROR),
                    'published'=>true,'client_visible'=>false,'reason'=>$reason,
                ],$actor,$row);
            },3);
        }catch(QueryException $e){
            if(in_array((string)$e->getCode(),['23000','23505'],true))abort(409,'Settings changed. Reload and try again.');
            throw $e;
        }
    }
}
