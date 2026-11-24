<?php
namespace App\Modules\Camera\Services;
use App\Models\RemoteConfig;
use App\Models\AdminUser;
use App\Modules\Config\Services\ConfigRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Only the exact already-approved local Flutter tools. Never arbitrary asset
 * URLs, executable filters, new paid features or access to captured media. */
final class CameraToolCatalog{
    public const KEY='m24.camera_tools';
    public const FILTERS=['natural','warm','cool','portrait','film','night','soft','vibrant','blackAndWhite'];
    public const STICKERS=['❤️','😂','😍','🔥','🎉','⭐','🌸','💖','👍','👑','🌈','✨'];
    public function snapshot():array{
        $row=Schema::hasTable('remote_configs')?RemoteConfig::where('key',self::KEY)->first():null;
        if(!$row)return ['schema_version'=>1,'revision'=>0,'filters'=>self::FILTERS,'stickers'=>self::STICKERS];
        $v=(array)$row->value;
        if(!$row->published||$row->client_visible||$row->value_type!=='object'||!$this->valid($v))
            return ['schema_version'=>1,'revision'=>(int)$row->version,'filters'=>['natural'],'stickers'=>[]];
        return ['schema_version'=>1,'revision'=>(int)$row->version,'filters'=>$v['filters'],'stickers'=>$v['stickers']];
    }
    private function valid(array $v):bool{
        return count($v)===2&&is_array($v['filters']??null)&&is_array($v['stickers']??null)&&
            array_is_list($v['filters'])&&array_is_list($v['stickers'])&&
            count($v['filters'])<=count(self::FILTERS)&&count($v['stickers'])<=count(self::STICKERS)&&
            collect($v['filters'])->every(fn($x)=>is_string($x)&&in_array($x,self::FILTERS,true))&&
            collect($v['stickers'])->every(fn($x)=>is_string($x)&&in_array($x,self::STICKERS,true))&&
            count(array_unique($v['filters']))===count($v['filters'])&&count(array_unique($v['stickers']))===count($v['stickers'])&&
            in_array('natural',$v['filters'],true);
    }
    public function publicHints():?array{$s=$this->snapshot();return $s['revision']>0?$s:null;}
    public function save(AdminUser $actor,array $filters,array $stickers,int $expected,string $reason):void{
        abort_unless($actor->canPerform('camera.read')&&$actor->canPerform('camera.manage'),403);
        abort_unless(Schema::hasTable('remote_configs'),503);
        $v=['filters'=>array_values($filters),'stickers'=>array_values($stickers)];
        abort_unless($this->valid($v),422,'Unsupported camera tool.');
        // Preserve the approved app ordering; this is availability, not layout.
        $v=['filters'=>array_values(array_intersect(self::FILTERS,$v['filters'])),'stickers'=>array_values(array_intersect(self::STICKERS,$v['stickers']))];
        $schema=['type'=>'object','required'=>['filters','stickers'],'additionalProperties'=>false,'properties'=>[
            'filters'=>['type'=>'array','minItems'=>1,'maxItems'=>count(self::FILTERS),'items'=>['type'=>'string','enum'=>self::FILTERS]],
            'stickers'=>['type'=>'array','maxItems'=>count(self::STICKERS),'items'=>['type'=>'string','enum'=>self::STICKERS]],
        ]];
        try{
            DB::transaction(function()use($actor,$expected,$reason,$v,$schema){
                $row=RemoteConfig::where('key',self::KEY)->lockForUpdate()->first();
                abort_unless((int)($row?->version??0)===$expected,409,'Catalog changed; reload.');
                app(ConfigRegistry::class)->saveConfig(['key'=>self::KEY,'description'=>'Approved camera tool availability',
                    'value_type'=>'object','value_json'=>json_encode($v,JSON_THROW_ON_ERROR),
                    'schema_json'=>json_encode($schema,JSON_THROW_ON_ERROR),'published'=>true,'client_visible'=>false,'reason'=>$reason],$actor,$row);
            },3);
        }catch(\Illuminate\Database\QueryException $e){if(in_array((string)$e->getCode(),['23000','23505'],true))abort(409,'Catalog changed; reload.');throw $e;}
    }
}
