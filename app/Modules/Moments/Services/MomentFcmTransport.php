<?php
namespace App\Modules\Moments\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Future FCM HTTP v1 transport. Disabled until real client Firebase inputs
 * and explicit ORBIT_M7_FCM_ENABLED. Never sends private content or IDs.
 */
final class MomentFcmTransport
{
    public function enabled():bool
    {
        // Preserve M7's original, independent transport rollout flags.
        return config('orbit_moments.enabled')===true
            && config('orbit_moments.fcm.enabled')===true
            && $this->notificationsReady();
    }

    /** Shared credential readiness, independent of legacy Moments rollout. */
    public function notificationsReady(): bool
    {
        $options=config('orbit_moments.fcm',[]);
        if(!is_array($options))return false;
        // Android push can be provisioned independently of Apple/APNs.
        // iOS clients still require their own Firebase app IDs and APNs
        // registration before requesting an FCM token.
        foreach(['project_id','api_key','sender_id','android_app_id'] as $field){
            if(!is_string($options[$field]??null) ||
                trim($options[$field])==='')return false;
        }
        // Enabling the flag alone cannot prompt Flutter for a Firebase token.
        // A readable service account outside public_html matching the same
        // Firebase project, with valid signing material, is required first.
        try{
            $credentials=$this->credentials();
            return openssl_pkey_get_private($credentials['private_key'])!==false;
        }catch(\Throwable){return false;}
    }

    private function credentials():array
    {
        $path=(string)config('orbit_moments.fcm.service_account_path');
        $real=$path!==''?realpath($path):false;
        $web=realpath(public_path());
        if($real===false || !is_file($real) || !is_readable($real)
            || filesize($real)>65536 || ($web!==false
                && ($web===$real || str_starts_with($real,$web.DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('M7 Firebase credentials are unavailable outside web root.');
        }
        $creds=json_decode((string)file_get_contents($real),true);
        $project=(string)config('orbit_moments.fcm.project_id');
        if(!preg_match('/^[a-z][a-z0-9-]{3,60}$/D',$project)
            || !is_array($creds) || ($creds['type']??null)!=='service_account'
            || ($creds['project_id']??null)!==$project
            || !filter_var($creds['client_email']??null,FILTER_VALIDATE_EMAIL)
            || !is_string($creds['private_key']??null)
            || !str_contains($creds['private_key'],'BEGIN PRIVATE KEY')) {
            throw new RuntimeException('M7 Firebase service account does not match project.');
        }
        return $creds;
    }

    private function accessToken():string
    {
        $creds=$this->credentials();
        $encode=static fn(array $data):string=>rtrim(strtr(base64_encode(
            json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $now=time();
        $header=$encode(['alg'=>'RS256','typ'=>'JWT']);
        $claims=$encode([
            'iss'=>$creds['client_email'],
            'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
            'aud'=>'https://oauth2.googleapis.com/token',
            'iat'=>$now,'exp'=>$now+3000,
        ]);
        $signed=$header.'.'.$claims;
        if(!openssl_sign($signed,$signature,$creds['private_key'],OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM assertion signing failed.');
        }
        $jwt=$signed.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=');
        $response=Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token',[
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'=>$jwt,
        ]);
        if(!$response->successful() || !is_string($response->json('access_token'))
            || $response->json('access_token')==='') {
            throw new RuntimeException('FCM OAuth token request failed.');
        }
        return $response->json('access_token');
    }

    /**
     * Shared M14 transport. Data-only, non-identifying generic trigger. The
     * receiving app fetches its authenticated inbox after rechecking access.
     * FCM acceptance is NOT an on-device display/open/read receipt.
     */
    /**
     * M14 generic data-only provider event; no personal content or target ID.
     * Only reviewed social categories share a stable collapse key. Safety
     * priority and SOS/APNs critical entitlements remain a later milestone.
     *
     * Return sanitized outcome only: never expose token, provider request,
     * OAuth response, sender/recipient or untrusted provider error message.
     */
    public function sendOperationalResult(string $fcmToken,string $category): array
    {
        if(!$this->notificationsReady())return ['status'=>'retry','code'=>'provider_off'];
        $allowed=['pings','check_ins','messages','friends','circles','security'];
        if(!in_array($category,$allowed,true))
            return ['status'=>'terminal','code'=>'category_unsupported'];

        $project=(string)config('orbit_moments.fcm.project_id');
        $collapse='orbit_'.$category;
        try{
            $response=Http::withToken($this->accessToken())->acceptJson()
                ->timeout(8)->post('https://fcm.googleapis.com/v1/projects/'
                    .$project.'/messages:send',[
                    'message'=>[
                        'token'=>$fcmToken,
                        // Public, generic OS-visible alert; no recipient, location,
                        // message text, Circle identity, or private target ID.
                        'notification'=>[
                            'title'=>'Orbit',
                            'body'=>'You have a new notification.',
                        ],
                        'data'=>['type'=>'notification_available'],
                        'android'=>[
                            'priority'=>'NORMAL',
                            'collapse_key'=>$collapse,
                        ],
                        'apns'=>[
                            'headers'=>[
                                'apns-priority'=>'5',
                                'apns-collapse-id'=>$collapse,
                            ],
                            'payload'=>['aps'=>['content-available'=>1]],
                        ],
                    ],
                ]);
        }catch(\Throwable) {
            return ['status'=>'retry','code'=>'network_or_oauth'];
        }
        if($response->successful()
            && is_string($response->json('name'))
            && str_starts_with($response->json('name'),
                'projects/'.$project.'/messages/')){
            return ['status'=>'accepted','code'=>null];
        }
        $details=$response->json('error.details');
        $fcmError=null;
        if(is_array($details))foreach($details as $detail){
            if(is_array($detail) &&
                ($detail['@type']??null)==='type.googleapis.com/google.firebase.fcm.v1.FcmError'
                && is_string($detail['errorCode']??null)){
                $fcmError=$detail['errorCode'];break;
            }
        }
        if(in_array($fcmError,['UNREGISTERED','SENDER_ID_MISMATCH','INVALID_ARGUMENT'],true)){
            return ['status'=>'invalid_token','code'=>'token_rejected'];
        }
        $http=$response->status();
        if($http===429 || $http===500 || $http===502 ||
            $http===503 || $http===504){
            // No Retry-After raw value is logged, stored or sent to clients.
            $after=filter_var($response->header('Retry-After'),
                FILTER_VALIDATE_INT);
            return ['status'=>'retry','code'=>'transient_provider',
                'retry_after'=>$after!==false ? max(0,min(3600,$after)) : null];
        }
        return ['status'=>'terminal','code'=>'provider_rejected'];
    }

    public function sendOperationalGeneric(string $fcmToken): bool
    {
        return $this->sendOperationalResult($fcmToken,'messages')['status']
            ==='accepted';
    }

    /** FCM acceptance is not device display/read receipt. */
    public function sendGeneric(string $fcmToken):bool
    {
        if(!$this->enabled())return false;
        $project=(string)config('orbit_moments.fcm.project_id');
        $response=Http::withToken($this->accessToken())->acceptJson()
            ->timeout(8)->post('https://fcm.googleapis.com/v1/projects/'
                .$project.'/messages:send',[
                'message'=>[
                    'token'=>$fcmToken,
                    // Preserve M7's generic, privacy-safe payload.
                    'notification'=>[
                        'title'=>'Orbit',
                        'body'=>'You have a new notification.',
                    ],
                    'data'=>['type'=>'moment_available'],
                    'android'=>['priority'=>'NORMAL'],
                    'apns'=>['headers'=>['apns-priority'=>'5'],
                        'payload'=>['aps'=>['content-available'=>1]]],
                ],
            ]);
        return $response->successful()
            && is_string($response->json('name'))
            && str_starts_with($response->json('name'),'projects/'.$project.'/messages/');
    }
}
