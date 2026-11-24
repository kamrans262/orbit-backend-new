<?php
namespace App\Modules\Camera\Services;

use App\Models\RemoteConfig;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** Policy over declarations only. Encrypted media cannot be probed by Laravel. */
final class CameraPolicy
{
    public const KEY='camera.operations';
    public const DEFAULTS=[
        'enhancements_enabled'=>false,
        'photo_long_edge_px'=>1440,
        'photo_data_saver_long_edge_px'=>1080,
        'photo_jpeg_quality'=>80,
        'video_duration_ms'=>60000,
        'video_max_long_edge_px'=>1280,
        'video_max_fps'=>30,
        'video_target_bps'=>4000000,
        'video_data_saver_target_bps'=>2000000,
        'voice_duration_ms'=>60000,
        'voice_codec'=>'AAC-LC',
        'voice_channels'=>1,
        'voice_target_bps'=>64000,
    ];
    // Lower bounds prevent accidental unusable settings; upper bounds never
    // enlarge the reviewed baseline. Codec/channels/voice target are fixed.
    public const RANGES=[
        'photo_long_edge_px'=>[720,1440],
        'photo_data_saver_long_edge_px'=>[480,1080],
        'photo_jpeg_quality'=>[60,80],
        'video_duration_ms'=>[1000,60000],
        'video_max_long_edge_px'=>[480,1280],
        'video_max_fps'=>[15,30],
        'video_target_bps'=>[500000,4000000],
        'video_data_saver_target_bps'=>[250000,2000000],
        'voice_duration_ms'=>[1000,60000],
    ];
    public static function schema():array
    {
        $properties=['enhancements_enabled'=>['type'=>'boolean']];
        foreach(self::RANGES as $key=>[$min,$max])$properties[$key]=['type'=>'number','minimum'=>$min,'maximum'=>$max];
        $properties['voice_codec']=['type'=>'string','enum'=>['AAC-LC']];
        $properties['voice_channels']=['type'=>'number','enum'=>[1]];
        $properties['voice_target_bps']=['type'=>'number','enum'=>[64000]];
        return ['type'=>'object','additionalProperties'=>false,'required'=>array_keys(self::DEFAULTS),'properties'=>$properties];
    }
    public function record(bool $lock=false):?RemoteConfig
    {
        if(!Schema::hasTable('remote_configs'))return null;
        $query=RemoteConfig::query()->where('key',self::KEY);
        if($lock)$query->lockForUpdate();
        return $query->first();
    }
    public function settings(bool $lock=false):array
    {
        $record=$this->record($lock);
        $v=$record?->published ? (array)$record->value : [];
        $safe=self::DEFAULTS;
        $valid=true;
        foreach(self::RANGES as $key=>[$min,$max]){
            if(!array_key_exists($key,$v)){$valid=false;continue;}
            $n=$v[$key];
            if(!is_int($n)||$n<$min||$n>$max){$valid=false;continue;}
            $safe[$key]=$n;
        }
        $safe['photo_data_saver_long_edge_px']=min($safe['photo_long_edge_px'],$safe['photo_data_saver_long_edge_px']);
        $safe['video_data_saver_target_bps']=min($safe['video_target_bps'],$safe['video_data_saver_target_bps']);
        $safe['video_duration_ms']=min($safe['video_duration_ms'],(int)config('orbit_media.max_video_duration_ms',60000));
        $safe['enhancements_enabled']=$valid&&($v['enhancements_enabled']??false)===true;
        return $safe+['revision'=>(int)($record?->version??0)];
    }
    public function publicHints():?array
    {
        $settings=$this->settings();
        if($settings['revision']===0)return null;
        return ['schema_version'=>1]+$settings;
    }

    /** Existing legacy clients may omit image dimensions with enhancements
     * disabled. Supplied dimensions and duration always obey bounded policy.
     * The new strict mode is opt-in only after Stage 3 device validation. */
    public function validate(array $data,int $status=422, bool $lock=false):void
    {
        if(($data['purpose']??null)!=='moment')return;
        $s=$this->settings($lock);
        $strict=$s['enhancements_enabled'];
        $kind=$data['kind']??'';
        $mime=$data['declared_mime']??'';
        $w=$data['declared_width']??null;$h=$data['declared_height']??null;
        $duration=$data['declared_duration_ms']??null;
        $bytes=$data['declared_bytes']??$data['cipher_bytes']??null;
        $limit=(int)config('orbit_media.limits.'.$kind,0);
        abort_if($limit<=0 || ($bytes!==null&&((int)$bytes<1||(int)$bytes>$limit+262144)),
            $status,'Moment encrypted size is outside current camera policy.');
        $mimes=match($kind){
            'image'=>$strict?['image/jpeg']:['image/jpeg','image/png','image/webp'],
            'video'=>['video/mp4'],
            'audio'=>$strict?['audio/mp4','audio/aac']:['audio/mp4','audio/aac','audio/ogg'],
            default=>[],
        };
        abort_unless(in_array($mime,$mimes,true),$status,'Moment format is outside current camera policy.');
        if($kind==='image'||$kind==='video'){
            $edge=$kind==='image'?$s['photo_long_edge_px']:$s['video_max_long_edge_px'];
            abort_if(($w===null)!==($h===null)||($strict&&$w===null)||
                ($w!==null&&((int)$w<1||(int)$h<1||max((int)$w,(int)$h)>$edge)),
                $status,'Moment dimensions are outside current camera policy.');
        }
        if($kind==='video'||$kind==='audio'){
            $max=$kind==='video'?$s['video_duration_ms']:$s['voice_duration_ms'];
            abort_if($duration===null||(int)$duration<1||(int)$duration>$max,
                $status,'Moment duration is outside current camera policy.');
        }elseif($duration!==null){
            abort_if((int)$duration!==0,$status,'Photo cannot declare a clip duration.');
        }
    }
}
