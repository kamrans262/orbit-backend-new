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
        return config('orbit_moments.enabled')===true
            && config('orbit_moments.fcm.enabled')===true;
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
