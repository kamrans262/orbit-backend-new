<?php
namespace Tests\Feature;
use App\Modules\Billing\Services\StoreBillingProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OrbitM19ProviderAdapterTest extends TestCase{
    private string $keyFile;
    private string $public;
    private string $private;
    protected function setUp():void{
        parent::setUp();
        $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key,$pem);$this->private=$pem;$this->public=openssl_pkey_get_details($key)['key'];
        $this->keyFile=tempnam(sys_get_temp_dir(),'orbit-billing-test-');
        file_put_contents($this->keyFile,json_encode(['client_email'=>'sandbox@example.test','private_key'=>$pem]));
        config(['orbit_billing.google.package'=>'com.example.orbit_ui','orbit_billing.google.service_account_path'=>$this->keyFile,
            'orbit_billing.google.push_email'=>'push@example.test','orbit_billing.google.push_audience'=>'https://sandbox.example.test/webhook',
            'orbit_billing.google.push_subscription'=>'projects/test/subscriptions/orbit']);
        Http::preventStrayRequests();
    }
    protected function tearDown():void{@unlink($this->keyFile);parent::tearDown();}
    private function b64(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
    private function jwt(array $override=[]):string{
        $p=array_replace(['iss'=>'https://accounts.google.com','aud'=>'https://sandbox.example.test/webhook','email'=>'push@example.test',
            'email_verified'=>true,'iat'=>now()->timestamp,'exp'=>now()->addMinutes(5)->timestamp],$override);
        $raw=$this->b64('{"alg":"RS256","kid":"test-key"}').'.'.$this->b64(json_encode($p));
        openssl_sign($raw,$sig,$this->private,OPENSSL_ALGO_SHA256);return $raw.'.'.$this->b64($sig);
    }
    private function reject(callable $f,int $status):void{
        try{$f();$this->fail('Invalid provider proof accepted');}catch(HttpExceptionInterface $e){$this->assertSame($status,$e->getStatusCode());}
    }
    public function test_google_authoritative_test_purchase_and_fixed_origin():void{
        $response=['testPurchase'=>(object)[],'subscriptionState'=>'SUBSCRIPTION_STATE_CANCELED','startTime'=>'2026-11-01T00:00:00Z',
            'externalAccountIdentifiers'=>['obfuscatedExternalAccountId'=>str_repeat('a',64)],
            'acknowledgementState'=>'ACKNOWLEDGEMENT_STATE_PENDING','lineItems'=>[['productId'=>'orbit.lite','expiryTime'=>'2026-12-01T00:00:00Z',
                'offerDetails'=>['basePlanId'=>'monthly'],'autoRenewingPlan'=>['autoRenewEnabled'=>false]]]];
        Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'fake-oauth']),
            'https://androidpublisher.googleapis.com/*'=>Http::response($response)]);
        $adapter=app(StoreBillingProvider::class);$r=$adapter->lookup('google','sandbox-token');
        $this->assertSame('active',$r['state']);$this->assertFalse($r['renew']);$this->assertSame('monthly',$r['base']);$this->assertSame('2026-12-01 00:00:00',$r['ends']);
        Http::assertSent(fn($r)=>$r->url()==='https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.example.orbit_ui/purchases/subscriptionsv2/tokens/sandbox-token'&&$r->hasHeader('Authorization','Bearer fake-oauth'));
        $this->reject(fn()=>$adapter->reference('google','https://other.test/token'),422);
    }
    public function test_real_google_purchase_is_never_accepted_in_sandbox():void{
        Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'fake-oauth']),
            'https://androidpublisher.googleapis.com/*'=>Http::response(['subscriptionState'=>'SUBSCRIPTION_STATE_ACTIVE'])]);
        $this->reject(fn()=>app(StoreBillingProvider::class)->lookup('google','real-purchase-token'),422);
    }
    public function test_google_push_signature_audience_identity_and_package():void{
        Http::fake(['https://www.googleapis.com/oauth2/v1/certs'=>Http::response(['test-key'=>$this->public])]);
        $payload=['subscription'=>'projects/test/subscriptions/orbit','message'=>['messageId'=>'123','data'=>base64_encode(json_encode([
            'packageName'=>'com.example.orbit_ui','subscriptionNotification'=>['purchaseToken'=>'sandbox-token']]))]];
        $adapter=app(StoreBillingProvider::class);
        $this->assertSame(['event'=>'123','reference'=>'sandbox-token'],$adapter->notice('google',$payload,$this->jwt()));
        $this->reject(fn()=>$adapter->notice('google',$payload,$this->jwt(['aud'=>'wrong'])),401);
        $this->reject(fn()=>$adapter->notice('google',$payload,$this->jwt(['email'=>'wrong@example.test'])),401);
        $this->reject(fn()=>$adapter->notice('google',$payload,$this->jwt(['exp'=>now()->timestamp-1])),401);
        $this->reject(fn()=>$adapter->notice('google',$payload,null),401);
        $parts=explode('.',$this->jwt());$parts[2]=$this->b64(str_repeat('x',256));
        $this->reject(fn()=>$adapter->notice('google',$payload,implode('.',$parts)),401);
    }
    public function test_apple_fails_closed_without_trusted_signing_chain():void{
        config(['orbit_billing.apple.bundle'=>'com.example.orbit_ui','orbit_billing.apple.issuer'=>'issuer','orbit_billing.apple.key_id'=>'id',
            'orbit_billing.apple.private_key_path'=>$this->keyFile,'orbit_billing.apple.root_ca_path'=>$this->keyFile]);
        $adapter=app(StoreBillingProvider::class);
        $forged=$this->b64('{"alg":"none"}').'.'.$this->b64('{"bundleId":"com.example.orbit_ui","environment":"Sandbox","originalTransactionId":"123"}').'.'.$this->b64('fake');
        $this->reject(fn()=>$adapter->reference('apple',$forged),422);
        $this->reject(fn()=>$adapter->notice('apple',['signedPayload'=>$forged],null),422);
        Http::assertNothingSent();
    }

    private function openssl(string $dir,array $args):void{
        (new \Symfony\Component\Process\Process(['openssl',...$args],$dir))->setTimeout(15)->mustRun();
    }
    private function signedApple(array $payload,array $chain,string $private):string{
        $head=$this->b64(json_encode(['alg'=>'ES256','x5c'=>$chain]));
        $body=$this->b64(json_encode($payload));$input=$head.'.'.$body;
        openssl_sign($input,$sig,$private,OPENSSL_ALGO_SHA256);
        $offset=2;$raw='';
        for($i=0;$i<2;$i++){
            $this->assertSame(2,ord($sig[$offset++]));$len=ord($sig[$offset++]);
            $raw.=str_pad(ltrim(substr($sig,$offset,$len),"\0"),32,"\0",STR_PAD_LEFT);$offset+=$len;
        }
        return $input.'.'.$this->b64($raw);
    }
    public function test_apple_signed_chain_and_authoritative_status_fixture():void{
        // Generated private fixture trust anchor, never Apple production
        // credentials. Exercises actual OpenSSL path and signature validation.
        $dir=sys_get_temp_dir().'/orbit-apple-fixture-'.bin2hex(random_bytes(8));mkdir($dir,0700);
        try{
            $this->openssl($dir,['req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:prime256v1','-nodes','-subj','/CN=FixtureRoot',
                '-days','3','-keyout','root.key','-out','root.pem','-addext','basicConstraints=critical,CA:TRUE','-addext','keyUsage=critical,keyCertSign,cRLSign']);
            foreach(['intermediate','leaf'] as $part){
                $this->openssl($dir,['req','-new','-newkey','ec','-pkeyopt','ec_paramgen_curve:prime256v1','-nodes','-subj','/CN=Fixture'.$part,'-keyout',$part.'.key','-out',$part.'.csr']);
                $parent=$part==='intermediate'?'root':'intermediate';
                file_put_contents($dir.'/'.$part.'.ext',$part==='intermediate'?
                    "basicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n1.2.840.113635.100.6.2.1=ASN1:NULL\n":
                    "basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\n1.2.840.113635.100.6.11.1=ASN1:NULL\n");
                $this->openssl($dir,['x509','-req','-in',$part.'.csr','-CA',$parent.'.pem','-CAkey',$parent.'.key','-CAcreateserial','-days','3','-extfile',$part.'.ext','-out',$part.'.pem']);
            }
            $chain=array_map(fn($name)=>preg_replace('/-----[^-]+-----|\s+/','',file_get_contents($dir.'/'.$name.'.pem')),['leaf','intermediate','root']);
            $private=file_get_contents($dir.'/leaf.key');
            config(['orbit_billing.apple.bundle'=>'com.example.orbit_ui','orbit_billing.apple.issuer'=>'fixture-issuer','orbit_billing.apple.key_id'=>'fixture-key',
                'orbit_billing.apple.private_key_path'=>$dir.'/leaf.key','orbit_billing.apple.root_ca_path'=>$dir.'/root.pem']);
            $base=['bundleId'=>'com.example.orbit_ui','environment'=>'Sandbox','originalTransactionId'=>'12345','signedDate'=>now()->timestamp*1000];
            $transaction=$base+['productId'=>'orbit.lite','type'=>'Auto-Renewable Subscription','inAppOwnershipType'=>'PURCHASED',
                'appAccountToken'=>'12345678-1234-4234-8234-123456789012','purchaseDate'=>now()->subDay()->timestamp*1000,'expiresDate'=>now()->addDays(20)->timestamp*1000];
            $tx=$this->signedApple($transaction,$chain,$private);
            $renew=$this->signedApple($base+['autoRenewStatus'=>1],$chain,$private);
            Http::fake(['https://api.storekit-sandbox.apple.com/inApps/v1/subscriptions/12345'=>Http::response([
                'bundleId'=>'com.example.orbit_ui','environment'=>'Sandbox','data'=>[['lastTransactions'=>[['originalTransactionId'=>'12345','status'=>1,'signedTransactionInfo'=>$tx,'signedRenewalInfo'=>$renew]]]]
            ])]);
            $adapter=app(StoreBillingProvider::class);
            $this->assertSame('12345',$adapter->reference('apple',$tx));
            $verified=$adapter->lookup('apple','12345');
            $this->assertSame('active',$verified['state']);$this->assertSame('orbit.lite',$verified['product']);$this->assertTrue($verified['renew']);
            $notice=$this->signedApple(['signedDate'=>now()->timestamp*1000,'notificationUUID'=>'12345678-1234-4234-8234-123456789012',
                'notificationType'=>'DID_RENEW','data'=>['bundleId'=>'com.example.orbit_ui','environment'=>'Sandbox','signedTransactionInfo'=>$tx]],$chain,$private);
            $this->assertSame('12345',$adapter->notice('apple',['signedPayload'=>$notice],null)['reference']);
            $wrong=$this->signedApple(array_replace($transaction,['environment'=>'Production']),$chain,$private);
            $this->reject(fn()=>$adapter->reference('apple',$wrong),422);
            $parts=explode('.',$tx);$parts[2]=$this->b64(str_repeat('x',64));
            $this->reject(fn()=>$adapter->reference('apple',implode('.',$parts)),422);
        }finally{foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);}
    }
}
