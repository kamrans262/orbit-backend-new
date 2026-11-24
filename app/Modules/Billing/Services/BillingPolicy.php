<?php
namespace App\Modules\Billing\Services;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
final class BillingPolicy{
    public const ENV='sandbox';
    public const BENEFITS=['ad_free'=>false,'activity_days'=>30,'moment_days'=>7,
        'enhanced_sos'=>false,'advanced_visibility'=>false,'premium_themes'=>false];
    public function installed():bool{return Schema::hasTable('billing_products')&&Schema::hasTable('billing_entitlements');}
    public function key():string{
        $key=base64_decode((string)config('orbit_billing.binding_key'),true);
        abort_unless(is_string($key)&&strlen($key)===32,503,'Billing unavailable.');
        return $key;
    }
    public function enabled():bool{
        $key=base64_decode((string)config('orbit_billing.binding_key'),true);
        return $this->installed()&&config('orbit_billing.sandbox_enabled')===true&&is_string($key)&&strlen($key)===32;
    }
    public function digest(string $scope,string $value):string{return hash_hmac('sha256','orbit-billing-v1|'.self::ENV.'|'.$scope.'|'.$value,$this->key());}
    public function binding(string $owner,string $provider):string{
        $hex=$this->digest('account:'.$provider,$owner);
        if($provider==='google')return $hex;
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
    }
    public function product(string $provider,string $product,string $base):?object{
        return DB::table('billing_products')->where(['provider'=>$provider,'environment'=>self::ENV,'product_id'=>$product,'base_plan_id'=>$base])->first();
    }
}
