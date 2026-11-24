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
}
