<?php
namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

/** Sandbox adapters. Fixed TLS origins, no redirects. No provider payload is
 * logged or returned to the caller. Production and independent PKI review
 * deliberately remain release gates. */
final class StoreBillingProvider implements BillingProvider{
    private function cfg(string $key):string{return (string)config('orbit_billing.'.$key,'');}
    public function ready(string $p):bool{
        if($p==='apple')return $this->cfg('apple.bundle')!==''&&$this->cfg('apple.issuer')!==''&&$this->cfg('apple.key_id')!==''&&is_readable($this->cfg('apple.private_key_path'))&&is_readable($this->cfg('apple.root_ca_path'));
        return $p==='google'&&$this->cfg('google.package')!==''&&is_readable($this->cfg('google.service_account_path'));
    }
    private function requireReady(string $p):void{abort_unless($this->ready($p),503,'Provider unavailable.');}
    private function invalid():never{abort(422,'Unverified store evidence.');}
    private function b64(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
    private function unb64(string $s):string{
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$s))$this->invalid();
        $raw=base64_decode(strtr($s,'-_','+/'),true);
        if($raw===false||$this->b64($raw)!==$s)$this->invalid();return $raw;
    }
    private function decode(string $s):array{
        try{$x=json_decode($s,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){$this->invalid();}
        if(!is_array($x))$this->invalid();return $x;
    }
    private function parts(string $jws):array{
        if(strlen($jws)>65536)$this->invalid();
        $p=explode('.',$jws);if(count($p)!==3)$this->invalid();
        return [$this->decode($this->unb64($p[0])),$this->decode($this->unb64($p[1])),$p[0].'.'.$p[1],$this->unb64($p[2])];
    }
    private function der(string $raw):string{
        if(strlen($raw)!==64)$this->invalid();
        $out='';foreach([substr($raw,0,32),substr($raw,32)] as $n){
            $n=ltrim($n,"\0");if($n==='')$n="\0";if(ord($n[0])&128)$n="\0".$n;
            $out.="\x02".chr(strlen($n)).$n;
        }return "\x30".chr(strlen($out)).$out;
    }
    private function raw(string $der):string{
        if(strlen($der)<8||ord($der[0])!==48||ord($der[1])!==strlen($der)-2)$this->invalid();
        $i=2;$out='';for($k=0;$k<2;$k++){
            if(!isset($der[$i+1])||ord($der[$i++])!==2)$this->invalid();
            $n=ord($der[$i++]);$v=ltrim(substr($der,$i,$n),"\0");$i+=$n;
            if($n<1||strlen($v)>32)$this->invalid();$out.=str_pad($v,32,"\0",STR_PAD_LEFT);
        }if($i!==strlen($der))$this->invalid();return $out;
    }
    private function appleJws(string $jws):array{
        [$h,$p,$input,$sig]=$this->parts($jws);
        $chain=$h['x5c']??null;
        if(($h['alg']??null)!=='ES256'||!is_array($chain)||count($chain)!==3)$this->invalid();
        $certs=[];foreach($chain as $entry){
            if(!is_string($entry)||strlen($entry)>12000||base64_decode($entry,true)===false)$this->invalid();
            $pem="-----BEGIN CERTIFICATE-----\n".chunk_split($entry,64,"\n")."-----END CERTIFICATE-----\n";
            if(!@openssl_x509_read($pem))$this->invalid();$certs[]=$pem;
        }
        $leaf=openssl_x509_parse($certs[0]);$inter=openssl_x509_parse($certs[1]);
        // Apple's signing and intermediate certificate extension OIDs.
        if(!isset($leaf['extensions']['1.2.840.113635.100.6.11.1'])||!isset($inter['extensions']['1.2.840.113635.100.6.2.1']))$this->invalid();
        $rootPath=$this->cfg('apple.root_ca_path');
        if(!is_readable($rootPath))abort(503,'Provider unavailable.');
        $root=openssl_x509_read(file_get_contents($rootPath));
        if(!$root||openssl_x509_fingerprint($root,'sha256')!==openssl_x509_fingerprint($certs[2],'sha256'))$this->invalid();
        $tmp=tempnam(sys_get_temp_dir(),'orbit-apple-');
        if($tmp===false)abort(503,'Provider unavailable.');
        try{
            file_put_contents($tmp,$certs[1]);
            $purpose=openssl_x509_checkpurpose($certs[0],X509_PURPOSE_ANY,[$rootPath],$tmp);
            if($purpose!==true&&$purpose!==1)$this->invalid();
            $key=openssl_pkey_get_public($certs[0]);$details=$key?openssl_pkey_get_details($key):false;
            if(!$details||$details['type']!==OPENSSL_KEYTYPE_EC||($details['ec']['curve_name']??'')!=='prime256v1'||
                openssl_verify($input,$this->der($sig),$key,OPENSSL_ALGO_SHA256)!==1)$this->invalid();
        }finally{@unlink($tmp);}
        if(!is_int($p['signedDate']??null)||$p['signedDate']<1||$p['signedDate']>now()->timestamp*1000+300000)$this->invalid();
        return $p;
    }
    private function appleIdentity(array $p):void{
        if(($p['bundleId']??null)!==$this->cfg('apple.bundle')||($p['environment']??null)!=='Sandbox')$this->invalid();
    }
    private function jwt(array $header,array $claims,string $path,bool $ec):string{
        $input=$this->b64(json_encode($header,JSON_THROW_ON_ERROR)).'.'.$this->b64(json_encode($claims,JSON_THROW_ON_ERROR));
        $key=openssl_pkey_get_private(file_get_contents($path));
        if(!$key||!openssl_sign($input,$sig,$key,OPENSSL_ALGO_SHA256))abort(503,'Provider unavailable.');
        return $input.'.'.$this->b64($ec?$this->raw($sig):$sig);
    }
    private function http(){return Http::connectTimeout(4)->timeout(12)->withOptions(['allow_redirects'=>false])->acceptJson();}
    private function result($response):array{
        if(!$response->successful()){if($response->status()===404||$response->status()===400)$this->invalid();abort(503,'Provider unavailable.');}
        if(strlen($response->body())>1048576)abort(503,'Provider response unavailable.');
        return $this->decode($response->body());
    }
    private function googleToken():string{
        $sa=$this->decode(file_get_contents($this->cfg('google.service_account_path')));
        if(!is_string($sa['client_email']??null)||!is_string($sa['private_key']??null))abort(503,'Provider unavailable.');
        $now=now()->timestamp;
        $input=$this->b64('{"alg":"RS256","typ":"JWT"}').'.'.$this->b64(json_encode([
            'iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/androidpublisher','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+300
        ],JSON_THROW_ON_ERROR));
        if(!openssl_sign($input,$sig,$sa['private_key'],OPENSSL_ALGO_SHA256))abort(503,'Provider unavailable.');
        $r=$this->result($this->http()->asForm()->post('https://oauth2.googleapis.com/token',['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$input.'.'.$this->b64($sig)]));
        if(!is_string($r['access_token']??null))abort(503,'Provider unavailable.');
        return $r['access_token'];
    }
    private function timestamp(mixed $v,bool $ms=false):?string{
        if($v===null)return null;
        try{
            if($ms){if(!is_int($v)||$v<1||$v>4102444800000)$this->invalid();return CarbonImmutable::createFromTimestampMsUTC($v)->format('Y-m-d H:i:s');}
            if(!is_string($v)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D',$v))$this->invalid();
            return CarbonImmutable::parse($v)->utc()->format('Y-m-d H:i:s');
        }catch(\Throwable){$this->invalid();}
    }
    public function reference(string $p,string $proof):string{
        $this->requireReady($p);
        if($p==='google'){if(!preg_match('/^[A-Za-z0-9._~-]{8,4096}$/D',$proof))$this->invalid();return $proof;}
        $tx=$this->appleJws($proof);$this->appleIdentity($tx);
        if(!preg_match('/^[0-9]{1,64}$/D',(string)($tx['originalTransactionId']??'')))$this->invalid();
        return (string)$tx['originalTransactionId'];
    }
    public function lookup(string $p,string $ref):array{
        $this->requireReady($p);$observed=now('UTC')->format('Y-m-d H:i:s.u');
        try{
            if($p==='apple'){
                if(!preg_match('/^[0-9]{1,64}$/D',$ref))$this->invalid();
                $n=now()->timestamp;
                $jwt=$this->jwt(['alg'=>'ES256','kid'=>$this->cfg('apple.key_id'),'typ'=>'JWT'],['iss'=>$this->cfg('apple.issuer'),'iat'=>$n,'exp'=>$n+300,'aud'=>'appstoreconnect-v1','bid'=>$this->cfg('apple.bundle')],$this->cfg('apple.private_key_path'),true);
                $r=$this->result($this->http()->withToken($jwt)->get('https://api.storekit-sandbox.apple.com/inApps/v1/subscriptions/'.rawurlencode($ref)));
                $this->appleIdentity($r);$matches=[];
                foreach($r['data']??[] as $group)foreach($group['lastTransactions']??[] as $entry){
                    if(($entry['originalTransactionId']??null)!==$ref)continue;
                    $tx=$this->appleJws($entry['signedTransactionInfo']??'');$this->appleIdentity($tx);
                    $renew=$this->appleJws($entry['signedRenewalInfo']??'');
                    if(($tx['originalTransactionId']??null)!==$ref||($renew['originalTransactionId']??null)!==$ref||($renew['environment']??null)!=='Sandbox'||($tx['type']??null)!=='Auto-Renewable Subscription'||($tx['inAppOwnershipType']??null)!=='PURCHASED')$this->invalid();
                    $state=match($entry['status']??0){1=>'active',2=>'expired',3=>'hold',4=>'grace',5=>'revoked',default=>'unknown'};
                    if(isset($tx['revocationDate']))$state='revoked';
                    $matches[]=['product'=>$tx['productId']??'','base'=>'','binding'=>strtolower((string)($tx['appAccountToken']??'')),
                        'state'=>$state,'starts'=>$this->timestamp($tx['purchaseDate']??null,true),
                        'ends'=>$this->timestamp($state==='grace'?($renew['gracePeriodExpiresDate']??null):($tx['expiresDate']??null),true),
                        'renew'=>isset($renew['autoRenewStatus'])?$renew['autoRenewStatus']===1:null,'ack'=>'not_required','linked'=>null,'observed'=>$observed];
                }
                if(count($matches)!==1)$this->invalid();return $matches[0];
            }
            if(!preg_match('/^[A-Za-z0-9._~-]{8,4096}$/D',$ref))$this->invalid();
            $r=$this->result($this->http()->withToken($this->googleToken())->get('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'.rawurlencode($this->cfg('google.package')).'/purchases/subscriptionsv2/tokens/'.rawurlencode($ref)));
            // Google uses the same API for test and real purchases; never accept a real token.
            if(!array_key_exists('testPurchase',$r)||count($r['lineItems']??[])!==1)$this->invalid();
            $item=$r['lineItems'][0];$state=match($r['subscriptionState']??''){
                'SUBSCRIPTION_STATE_ACTIVE','SUBSCRIPTION_STATE_CANCELED'=>'active','SUBSCRIPTION_STATE_IN_GRACE_PERIOD'=>'grace',
                'SUBSCRIPTION_STATE_ON_HOLD'=>'hold','SUBSCRIPTION_STATE_PAUSED'=>'paused','SUBSCRIPTION_STATE_EXPIRED'=>'expired',
                'SUBSCRIPTION_STATE_PENDING'=>'pending','SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED'=>'expired',default=>'unknown'};
            return ['product'=>$item['productId']??'','base'=>$item['offerDetails']['basePlanId']??'',
                'binding'=>$r['externalAccountIdentifiers']['obfuscatedExternalAccountId']??'','state'=>$state,
                'starts'=>$this->timestamp($r['startTime']??null),'ends'=>$this->timestamp($item['expiryTime']??null),
                'renew'=>$item['autoRenewingPlan']['autoRenewEnabled']??null,'ack'=>($r['acknowledgementState']??'')==='ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED'?'acknowledged':'pending',
                'linked'=>$r['linkedPurchaseToken']??null,'observed'=>$observed];
        }catch(\Illuminate\Http\Client\ConnectionException){abort(503,'Provider unavailable.');}
    }
    public function acknowledge(string $p,string $ref,string $product):void{
        if($p!=='google')return;
        $this->requireReady($p);
        $r=$this->http()->withToken($this->googleToken())->post('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'.rawurlencode($this->cfg('google.package')).'/purchases/subscriptions/'.rawurlencode($product).'/tokens/'.rawurlencode($ref).':acknowledge',(object)[]);
        abort_unless($r->successful(),503,'Acknowledgement unavailable.');
    }
    private function googlePush(?string $bearer):void{
        if(!$bearer||$this->cfg('google.push_email')===''||$this->cfg('google.push_audience')==='')abort(401,'Invalid provider authentication.');
        [$h,$p,$input,$sig]=$this->parts($bearer);
        if(($h['alg']??null)!=='RS256'||!is_string($h['kid']??null))abort(401);
        $certs=Cache::remember('orbit-billing-google-push-certs',300,fn()=>$this->result($this->http()->get('https://www.googleapis.com/oauth2/v1/certs')));
        $cert=$certs[$h['kid']]??null;
        if(!is_string($cert)){Cache::forget('orbit-billing-google-push-certs');abort(401);}
        $n=now()->timestamp;
        abort_unless(openssl_verify($input,$sig,$cert,OPENSSL_ALGO_SHA256)===1&&in_array($p['iss']??null,['accounts.google.com','https://accounts.google.com'],true)
            &&($p['aud']??null)===$this->cfg('google.push_audience')&&($p['email']??null)===$this->cfg('google.push_email')&&($p['email_verified']??null)===true
            &&is_int($p['exp']??null)&&$p['exp']>$n&&is_int($p['iat']??null)&&$p['iat']<=$n+60&&$p['iat']>=$n-3600,401,'Invalid provider authentication.');
    }
    public function notice(string $p,array $body,?string $bearer):array{
        $this->requireReady($p);
        if($p==='apple'){
            $n=$this->appleJws(is_string($body['signedPayload']??null)?$body['signedPayload']:'');
            $d=$n['data']??[];$this->appleIdentity($d);
            if(!Str::isUuid($n['notificationUUID']??''))$this->invalid();
            if(($n['notificationType']??'')==='TEST')return ['event'=>$n['notificationUUID'],'reference'=>null];
            return ['event'=>$n['notificationUUID'],'reference'=>$this->reference('apple',$d['signedTransactionInfo']??'')];
        }
        $this->googlePush($bearer);
        abort_unless($this->cfg('google.push_subscription')!==''&&($body['subscription']??null)===$this->cfg('google.push_subscription'),401);
        $m=$body['message']??[];$id=$m['messageId']??null;
        if(!is_string($id)||strlen($id)>128||!is_string($m['data']??null))$this->invalid();
        $decoded=base64_decode($m['data'],true);if($decoded===false)$this->invalid();
        $n=$this->decode($decoded);if(($n['packageName']??null)!==$this->cfg('google.package'))$this->invalid();
        if(isset($n['testNotification']))return ['event'=>$id,'reference'=>null];
        $token=$n['subscriptionNotification']['purchaseToken']??$n['voidedPurchaseNotification']['purchaseToken']??null;
        if(!is_string($token))$this->invalid();
        return ['event'=>$id,'reference'=>$this->reference('google',$token)];
    }
}
