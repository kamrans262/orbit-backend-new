<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Session-only RTDB metadata, using the M5 signer and HTTPS service-account OAuth. */
final class FirebaseRealtimeProvider
{
    private ?string $access=null;
    private int $accessUntil=0;

    public function settings(): array
    {
        $v=config('orbit_realtime');
        foreach (['project_id','database_url','service_account_path','api_key','sender_id','android_app_id','ios_app_id','ios_bundle_id'] as $k) {
            $v[$k]=trim((string)($v[$k]??''));
        }
        // Legacy settings may be reused, but must never point at another project.
        $aliases=[
            'project_id'=>['orbit_circle.firebase.project_id','orbit_moments.fcm.project_id'],
            'database_url'=>['orbit_circle.firebase.database_url'],
            'api_key'=>['orbit_circle.firebase.api_key','orbit_moments.fcm.api_key'],
            'sender_id'=>['orbit_circle.firebase.sender_id','orbit_moments.fcm.sender_id'],
            'android_app_id'=>['orbit_moments.fcm.android_app_id'],
            'ios_app_id'=>['orbit_moments.fcm.ios_app_id'],
            'ios_bundle_id'=>['orbit_moments.fcm.ios_bundle_id'],
        ];
        foreach($aliases as $key=>$paths){
            foreach($paths as $path){
                $legacy=trim((string)config($path,''));
                if($key==='database_url'){$legacy=rtrim($legacy,'/');$v[$key]=rtrim($v[$key],'/');}
                if($legacy==='')continue;
                if($v[$key]!==''&&$v[$key]!==$legacy)throw new RuntimeException('Firebase configuration conflict.');
                $v[$key]=$legacy;
            }
        }
        if($v['service_account_path']==='')$v['service_account_path']=(string)config('orbit_circle.firebase.service_account_path','');
        $legacyApp=(string)config('orbit_circle.firebase.app_id','');
        if($legacyApp!==''){
            $k=str_contains($legacyApp,':ios:')?'ios_app_id':'android_app_id';
            if($v[$k]!==''&&$v[$k]!==$legacyApp)throw new RuntimeException('Firebase application conflict.');
            $v[$k]=$legacyApp;
        }
        if(!preg_match('/^[a-z][a-z0-9-]{4,62}$/D',$v['project_id']))throw new RuntimeException('Firebase project unavailable.');
        $base=preg_quote($v['project_id'],'/');
        if(!preg_match('/^https:\/\/'.$base.'(?:-default-rtdb)?(?:\.firebaseio\.com|\.[a-z0-9-]+\.firebasedatabase\.app)$/D',rtrim($v['database_url'],'/')))
            throw new RuntimeException('Firebase database origin mismatch.');
        $v['database_url']=rtrim($v['database_url'],'/');
        if($v['api_key']===''||!ctype_digit($v['sender_id']))throw new RuntimeException('Firebase client settings unavailable.');
        foreach(['android','ios'] as $platform){
            $id=$v[$platform.'_app_id'];
            if($id!==''&&!preg_match('/^1:'.preg_quote($v['sender_id'],'/').':'.$platform.':[a-f0-9]+$/D',$id))
                throw new RuntimeException('Firebase application identity mismatch.');
        }
        return $v;
    }

    private function credentials(array $v): array
    {
        $path=realpath($v['service_account_path']);
        if($path===false||!is_file($path)||!is_readable($path)||filesize($path)>65536)
            throw new RuntimeException('Firebase credential unavailable.');
        foreach([realpath(base_path()),realpath(public_path())] as $root){
            if($root!==false&&($path===$root||str_starts_with($path,$root.DIRECTORY_SEPARATOR)))
                throw new RuntimeException('Firebase credential path rejected.');
        }
        if(!app()->environment('testing')&&((fileperms($path)&0077)!==0))
            throw new RuntimeException('Firebase credential permissions rejected.');
        $c=json_decode((string)file_get_contents($path),true);
        if(!is_array($c)||($c['type']??null)!=='service_account'||($c['project_id']??null)!==$v['project_id']
            ||!filter_var($c['client_email']??null,FILTER_VALIDATE_EMAIL)
            ||!is_string($c['private_key']??null)||!openssl_pkey_get_private($c['private_key']))
            throw new RuntimeException('Firebase credential identity rejected.');
        return $c;
    }

    public function client(string $platform): array
    {
        $v=$this->settings();
        $this->credentials($v);
        if(!in_array($platform,['android','ios'],true)||$v[$platform.'_app_id']===''||($platform==='ios'&&$v['ios_bundle_id']===''))
            throw new RuntimeException('Firebase platform unavailable.');
        return ['project_id'=>$v['project_id'],'database_url'=>$v['database_url'],'api_key'=>$v['api_key'],
            'sender_id'=>$v['sender_id'],'app_id'=>$v[$platform.'_app_id'],'ios_bundle_id'=>$platform==='ios'?$v['ios_bundle_id']:null];
    }

    public function customToken(string $session,string $device,string $generation): string
    {
        $v=$this->settings();$c=$this->credentials($v);$now=time();
        return FirebaseServiceAccount::signedJwt([
            'iss'=>$c['client_email'],'sub'=>$c['client_email'],
            'aud'=>'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat'=>$now,'exp'=>$now+60,'uid'=>$session,
            'claims'=>['orbit_m25'=>true,'orbit_session'=>$session,'orbit_device'=>$device,'orbit_generation'=>$generation],
        ],$c);
    }

    /** Redacted offline readiness check, with no provider network traffic. */
    public function readiness():string
    {
        try{
            $v=$this->settings();$this->credentials($v);
            if($v['android_app_id']===''||$v['ios_app_id']===''||$v['ios_bundle_id']==='')return 'Platform registration incomplete';
            return 'Local configuration valid; live verification required';
        }catch(\Throwable){return 'Local configuration incomplete or invalid';}
    }

    /** Read only a dedicated, non-user probe node; never publish or activate anything. */
    public function probe():bool
    {
        if(config('orbit_realtime.enabled')!==true||config('orbit_realtime.verified')!==true)return false;
        try{
            $v=$this->settings();
            $r=Http::withToken($this->token($v))->acceptJson()->connectTimeout(2)->timeout(4)
                ->withOptions(['allow_redirects'=>false])->get($v['database_url'].'/orbit_m25/health_probe.json');
            return $r->successful()&&$r->json()===null;
        }catch(\Throwable){return false;}
    }

    private function token(array $v): string
    {
        if($this->access!==null&&$this->accessUntil>time())return $this->access;
        $c=$this->credentials($v);$now=time();
        $jwt=FirebaseServiceAccount::signedJwt([
            'iss'=>$c['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/identitytoolkit',
            'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3000,
        ],$c);
        $r=Http::asForm()->connectTimeout(2)->timeout(4)->withOptions(['allow_redirects'=>false])
            ->post('https://oauth2.googleapis.com/token',['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]);
        if(!$r->successful()||!is_string($r->json('access_token'))||$r->json('access_token')==='')throw new RuntimeException('Firebase OAuth unavailable.');
        $this->access=$r->json('access_token');
        $this->accessUntil=$now+max(0,min(3000,(int)$r->json('expires_in',0)-60));
        return $this->access;
    }

    /** The receipt is bound to the original project, never a caller-supplied target. */
    public function projectFingerprint():string
    {
        $v=$this->settings();
        return hash('sha256',$v['project_id'].'|'.$v['database_url']);
    }

    /** Remove only a permanently retired, server-registered session UID. */
    public function deleteAuth(string $session,string $fingerprint):void
    {
        if(!\Illuminate\Support\Str::isUuid($session)||!hash_equals($this->projectFingerprint(),$fingerprint))
            throw new RuntimeException('Firebase cleanup project mismatch.');
        $v=$this->settings();
        $r=Http::withToken($this->token($v))->acceptJson()->connectTimeout(2)->timeout(4)
            ->withOptions(['allow_redirects'=>false])->post(
                'https://identitytoolkit.googleapis.com/v1/projects/'.$v['project_id'].'/accounts:delete',
                ['localId'=>$session]);
        if(!$r->successful()&&$r->json('error.message')!=='USER_NOT_FOUND')
            throw new RuntimeException('Firebase Auth cleanup deferred.');
    }

    /** Compare-and-set whole session node; stale in-flight attempts cannot overwrite a newer version. */
    public function replace(string $session,?array $value): void
    {
        if(!preg_match('/^[0-9a-f-]{36}$/D',$session))throw new RuntimeException('Invalid realtime session.');
        $v=$this->settings();$url=$v['database_url'].'/orbit_m25/sessions/'.$session.'.json';
        $http=fn()=>Http::withToken($this->token($v))->acceptJson()->connectTimeout(2)->timeout(4)->withOptions(['allow_redirects'=>false]);
        $read=$http()->withHeaders(['X-Firebase-ETag'=>'true'])->get($url);
        $etag=$read->header('ETag');
        if(!$read->successful()||!is_string($etag)||$etag==='')throw new RuntimeException('Firebase metadata read unavailable.');
        // SQL locks serialize local callers. If an uncertain prior write lands
        // after this GET, the conditional PUT returns 412 and the worker retries.
        $write=$http()->withHeaders(['If-Match'=>$etag])->withBody(json_encode($value,JSON_THROW_ON_ERROR),'application/json')->put($url);
        if(!$write->successful())throw new RuntimeException('Firebase metadata update unavailable.');
    }
}
