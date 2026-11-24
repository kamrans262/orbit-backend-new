<?php
namespace App\Modules\Relay\Http\Controllers;
use App\Models\RemoteConfig;
use App\Modules\Config\Services\ConfigRegistry;
use App\Modules\Relay\Services\RelayPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminRelayController
{
    public function __construct(private readonly RelayPolicy $policy){}
    public function overview(){return $this->page('overview');}
    public function settings(){return $this->page('settings');}
    public function audits(){return $this->page('audits');}
    private function page(string $area){
        $ready=$this->policy->provisioned();
        $counts=[];$audits=collect();$metrics=collect();
        if($ready && $area==='overview'){
            $counts=[
                'Active leases'=>DB::table('relay_leases')->whereNull('revoked_at')->where('expires_at','>',now())->count(),
                'Expired leases'=>DB::table('relay_leases')->where('expires_at','<=',now())->count(),
                'Revoked leases'=>DB::table('relay_leases')->whereNotNull('revoked_at')->count(),
                'Reconciliation records'=>DB::table('relay_reconciliations')->count(),
                'Verified receipt records'=>DB::table('relay_receipts')->count(),
            ];
            $metrics=DB::table('relay_daily_metrics')->where('day','>=',now()->subDays(7)->toDateString())->orderByDesc('day')->limit(100)->get();
        }
        if($ready && $area==='audits'){
            $audits=DB::table('config_revisions')->where('resource_type','remote')->where('resource_key','relay.operations')
                ->orderByDesc('version')->paginate(30);
        }
        return view('orbit-admin.relay',['area'=>$area,'ready'=>$ready,'settings'=>$this->policy->settings(),
            'capabilities'=>$this->policy->capabilities(),'counts'=>$counts,'audits'=>$audits,'metrics'=>$metrics]);
    }
    public function save(Request $r){
        abort_unless($this->policy->provisioned(),503);
        $p=$r->validate(['enabled'=>'required|boolean','lease_seconds'=>'required|integer|min:60|max:900',
            'batch_limit'=>'required|integer|min:1|max:20','frame_bytes'=>'required|integer|min:1024|max:32768',
            'revision'=>'required|integer|min:1','reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        DB::transaction(function()use($r,$p):void {
            $record=RemoteConfig::query()->where('key','relay.operations')->lockForUpdate()->first();
            abort_unless($record && (int)$record->version===(int)$p['revision'],409,'Settings changed; reload the page.');
            $values=['enabled'=>(bool)$p['enabled'],'lease_seconds'=>(int)$p['lease_seconds'],
                'batch_limit'=>(int)$p['batch_limit'],'frame_bytes'=>(int)$p['frame_bytes']];
            $schema=['type'=>'object','additionalProperties'=>false,'required'=>array_keys($values),'properties'=>[
                'enabled'=>['type'=>'boolean'],'lease_seconds'=>['type'=>'number','minimum'=>60,'maximum'=>900],
                'batch_limit'=>['type'=>'number','minimum'=>1,'maximum'=>20],
                'frame_bytes'=>['type'=>'number','minimum'=>1024,'maximum'=>32768],
            ]];
            app(ConfigRegistry::class)->saveConfig(['key'=>'relay.operations','description'=>'Orbit Relay operational controls',
                'value_type'=>'object','value_json'=>json_encode($values,JSON_THROW_ON_ERROR),
                'schema_json'=>json_encode($schema,JSON_THROW_ON_ERROR),'published'=>true,'client_visible'=>false,
                'reason'=>$p['reason']],$r->user('admin'),$record);
            // Any revision invalidates outstanding leases for online reconciliation.
            DB::table('relay_leases')->whereNull('revoked_at')->update(['revoked_at'=>now(),'reason'=>'policy_changed']);
        });
        return redirect()->route('orbit.admin.relay.settings')->with('status','Relay controls saved.');
    }
}
