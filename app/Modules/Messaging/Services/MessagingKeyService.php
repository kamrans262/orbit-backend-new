<?php
namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\RateLimiter;

final class MessagingKeyService
{
    public const SIGNATURE_CONTEXT='orbit-m13-signed-prekey-v1';

    public function __construct(private readonly MessagingAccessPolicy $access) {}

    /** Strictly parse public material; there is no private-key API. */
    public static function bytes(string $encoded,int $length): string
    {
        $raw=base64_decode($encoded,true);
        abort_unless($raw!==false && strlen($raw)===$length
            && base64_encode($raw)===$encoded,422,'Invalid public key encoding.');
        return $raw;
    }

    public function register(string $user,string $device,array $input): array
    {
        $this->access->assertSession($user,$device);
        $identity=self::bytes($input['identity_ed25519'],32);
        $prekey=self::bytes($input['signed_prekey_x25519'],32);
        $signature=self::bytes($input['signed_prekey_signature'],64);
        abort_unless(function_exists('sodium_crypto_sign_verify_detached'),
            503,'Verified key signature support is unavailable.');
        $signed=self::SIGNATURE_CONTEXT.'|'.$device.'|'.$input['signed_prekey_id'].'|'.$prekey;
        abort_unless(sodium_crypto_sign_verify_detached($signature,$signed,$identity),
            422,'Signed prekey identity verification failed.');
        try{
            sodium_crypto_scalarmult(random_bytes(32),$prekey);
        }catch(\SodiumException){
            abort(422,'Invalid X25519 signed prekey.');
        }
        $new=$input['one_time_prekeys'] ?? [];
        $seen=[];
        foreach($new as $item) {
            $public=self::bytes($item['public_x25519'],32);
            try{
                sodium_crypto_scalarmult(random_bytes(32),$public);
            }catch(\SodiumException){
                abort(422,'Invalid X25519 one-time prekey.');
            }
            abort_if(isset($seen[$item['key_id']]),422,'Duplicate one-time prekey ID.');
            $seen[$item['key_id']]=true;
        }
        return DB::transaction(function() use($user,$device,$input,$new):array{
            $old=DB::table('messaging_device_keys')
                ->where('device_id',$device)->lockForUpdate()->first();
            $rotation=$old && ($old->identity_ed25519!==$input['identity_ed25519']
                || $old->signed_prekey_x25519!==$input['signed_prekey_x25519']
                || (int)$old->signed_prekey_id!==(int)$input['signed_prekey_id']);
            abort_if($rotation && ($input['confirm_key_rotation']??false)!==true,
                409,'Key rotation requires explicit device confirmation.');
            $version=(int)($old->key_version??0)+($old?($rotation?1:0):1);
            if($old) {
                DB::table('messaging_device_keys')->where('device_id',$device)->update([
                    'identity_ed25519'=>$input['identity_ed25519'],
                    'signed_prekey_x25519'=>$input['signed_prekey_x25519'],
                    'signed_prekey_signature'=>$input['signed_prekey_signature'],
                    'signed_prekey_id'=>$input['signed_prekey_id'],
                    'key_version'=>$version,'updated_at'=>now(),
                ]);
            }else{
                DB::table('messaging_device_keys')->insert([
                    'device_id'=>$device,'user_id'=>$user,
                    'identity_ed25519'=>$input['identity_ed25519'],
                    'signed_prekey_x25519'=>$input['signed_prekey_x25519'],
                    'signed_prekey_signature'=>$input['signed_prekey_signature'],
                    'signed_prekey_id'=>$input['signed_prekey_id'],
                    'key_version'=>$version,'registered_at'=>now(),'updated_at'=>now(),
                ]);
            }
            if($rotation) {
                DB::table('messaging_one_time_prekeys')->where('device_id',$device)->delete();
            }
            $remaining=DB::table('messaging_one_time_prekeys')
                ->where('device_id',$device)->whereNull('claimed_at')->count();
            abort_if($remaining+count($new)>(int)config('orbit_messaging.max_one_time_prekeys',100),
                422,'Too many unclaimed device prekeys.');
            foreach($new as $item) {
                $existing=DB::table('messaging_one_time_prekeys')
                    ->where('device_id',$device)->where('key_id',$item['key_id'])
                    ->lockForUpdate()->first();
                abort_if($existing && ($existing->claimed_at!==null ||
                    $existing->public_x25519!==$item['public_x25519']),409,
                    'One-time prekey ID was already published or consumed.');
                if(!$existing){
                    DB::table('messaging_one_time_prekeys')->insert([
                        'id'=>(string)Str::uuid(),'device_id'=>$device,
                        'key_id'=>$item['key_id'],'public_x25519'=>$item['public_x25519'],
                        'created_at'=>now(),
                    ]);
                }
            }
            return ['device_id'=>$device,'key_version'=>$version,
                'fingerprint'=>hash('sha256',$input['identity_ed25519']),
                'rotation'=>$rotation,'registered'=>true];
        });
    }

    public function mine(string $user,string $device): array
    {
        $this->access->assertSession($user,$device);
        $rows=DB::table('devices as d')
            ->leftJoin('messaging_device_keys as k','k.device_id','=','d.id')
            ->where('d.user_id',$user)->whereNull('d.revoked_at')
            ->select('d.id','d.trust_state','k.identity_ed25519','k.key_version')
            ->orderBy('d.id')->get();
        return ['devices'=>$rows->map(fn($row)=>[
            'id'=>$row->id,'trusted'=>$row->trust_state==='trusted',
            'messaging_keys_registered'=>$row->identity_ed25519!==null,
            'fingerprint'=>$row->identity_ed25519
                ?hash('sha256',$row->identity_ed25519):null,
            'key_version'=>$row->key_version===null?null:(int)$row->key_version,
        ])->all()];
    }

    public function discover(string $viewer,string $target): array
    {
        abort_unless($this->access->canDiscoverKeys($viewer,$target),
            404,'Messaging key directory unavailable.');
        $rate='messaging:prekey:'.hash('sha256',$viewer.'|'.$target);
        abort_if(RateLimiter::tooManyAttempts($rate,30),429,
            'Key directory request limit exceeded.');
        RateLimiter::hit($rate,60);
        return DB::transaction(function() use($viewer,$target):array{
            $records=DB::table('messaging_device_keys as k')
                ->join('devices as d','d.id','=','k.device_id')
                ->where('k.user_id',$target)->where('d.user_id',$target)
                ->where('d.trust_state','trusted')->whereNull('d.revoked_at')
                ->orderBy('k.device_id')
                ->limit((int)config('orbit_messaging.max_direct_devices',12)+1)
                ->get(['k.device_id','k.identity_ed25519','k.signed_prekey_x25519',
                    'k.signed_prekey_signature','k.signed_prekey_id','k.key_version']);
            abort_if($records->count()>(int)config('orbit_messaging.max_direct_devices',12),
                409,'Recipient has too many approved messaging devices.');
            $devices=[];
            foreach($records as $record){
                // One-time prekeys are claimed only for eligible authenticated
                // viewers, atomically and at most once globally.
                $oneTime=DB::table('messaging_one_time_prekeys')
                    ->where('device_id',$record->device_id)
                    ->whereNull('claimed_at')->orderBy('key_id')->lockForUpdate()->first();
                if($oneTime){
                    DB::table('messaging_one_time_prekeys')->where('id',$oneTime->id)
                        ->update(['claimed_by'=>$viewer,'claimed_at'=>now()]);
                }
                $devices[]=[
                    'device_id'=>$record->device_id,
                    'identity_ed25519'=>$record->identity_ed25519,
                    'signed_prekey_x25519'=>$record->signed_prekey_x25519,
                    'signed_prekey_signature'=>$record->signed_prekey_signature,
                    'signed_prekey_id'=>(int)$record->signed_prekey_id,
                    'key_version'=>(int)$record->key_version,
                    'fingerprint'=>hash('sha256',$record->identity_ed25519),
                    'one_time_prekey'=>$oneTime?[
                        'key_id'=>(int)$oneTime->key_id,
                        'public_x25519'=>$oneTime->public_x25519,
                    ]:null,
                ];
            }
            return ['user_id'=>$target,'devices'=>$devices];
        });
    }

    public function eligibleDevices(array $users): array
    {
        return DB::table('messaging_device_keys as k')
            ->join('devices as d','d.id','=','k.device_id')
            ->whereIn('k.user_id',$users)->whereColumn('d.user_id','k.user_id')
            ->where('d.trust_state','trusted')->whereNull('d.revoked_at')
            ->orderBy('k.device_id')->pluck('k.device_id')->map(strval(...))->all();
    }
}
