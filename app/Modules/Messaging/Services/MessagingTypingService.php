<?php
namespace App\Modules\Messaging\Services;

use App\Modules\Circle\Services\CircleConsentPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class MessagingTypingService
{
    public function __construct(private readonly MessagingAccessPolicy $access) {}

    private function cache(): \Illuminate\Contracts\Cache\Repository
    {
        // File storage on one Hostinger node; array in disposable CI. Not WSS.
        return Cache::store((string)config('orbit_messaging.typing_cache_store','file'));
    }
    private function key(string $conversation,string $user): string
    {
        return 'orbit:m13:typing:'.hash_hmac('sha256',$conversation.'|'.$user,
            (string)config('app.key'));
    }
    private function allowed(object $conversation,string $user): bool
    {
        if($conversation->kind!=='circle')return true;
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)->value('privacy');
        $privacy=$raw?json_decode((string)$raw,true):null;
        if(!is_array($privacy)||($privacy['ghost_mode_enabled']??true)!==false) {
            return false;
        }
        $row=DB::table('circle_permissions')->where('circle_id',$conversation->circle_id)
            ->where('scope_key','user:'.$user)->where('subject_user_id',$user)
            ->where('permission_key','message')->first();
        if(!$row || !app(CircleConsentPolicy::class)->consentCurrentlyValid($row,'message')) {
            return false;
        }
        $options=json_decode((string)$row->value_json,true);
        return ($options['typing_enabled']??true)===true;
    }

    public function update(object $conversation,string $user,bool $active): array
    {
        $this->access->assertConversation($conversation,$user);
        $key=$this->key($conversation->id,$user);
        if(!$active){
            $this->cache()->forget($key);
            return ['accepted'=>true,'expires_in'=>0];
        }
        abort_unless($this->allowed($conversation,$user),403,
            'Typing is disabled for this Circle or Ghost Mode.');
        if(!$this->cache()->add($key.':rate',true,3)) {
            return ['accepted'=>false,'expires_in'=>5];
        }
        $this->cache()->put($key,true,5);
        return ['accepted'=>true,'expires_in'=>5];
    }

    public function current(object $conversation,string $viewer): array
    {
        $this->access->assertConversation($conversation,$viewer);
        $rows=[];
        foreach($this->access->recipients($conversation,$viewer) as $person){
            if($this->allowed($conversation,$person) &&
                $this->cache()->get($this->key($conversation->id,$person))===true) {
                $rows[]=$person;
            }
        }
        return ['typing_user_ids'=>$rows,'ttl_seconds'=>5,
            'transport'=>'foreground_https'];
    }

    public function circleSetting(string $circle,string $user,bool $enabled): array
    {
        $row=DB::table('circle_permissions')->where('circle_id',$circle)
            ->where('scope_key','user:'.$user)->where('subject_user_id',$user)
            ->where('permission_key','message')->first();
        abort_unless($row && app(CircleConsentPolicy::class)
            ->consentCurrentlyValid($row,'message'),403,
            'Enable Circle messaging consent first.');
        $options=json_decode((string)$row->value_json,true);
        abort_unless(is_array($options),403);
        $options['typing_enabled']=$enabled;
        DB::table('circle_permissions')->where('circle_id',$circle)
            ->where('scope_key','user:'.$user)->where('permission_key','message')
            ->update(['value_json'=>json_encode($options,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
        return ['circle_id'=>$circle,'typing_enabled'=>$enabled,
            'message_consent_unchanged'=>true];
    }
}
