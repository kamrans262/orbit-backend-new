<?php
namespace App\Modules\Camera\Http\Controllers;

use App\Modules\Camera\Services\CameraPolicy;
use App\Modules\Config\Services\ConfigRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminCameraController
{
    public function index(CameraPolicy $policy,ConfigRegistry $registry)
    {
        $settings=$policy->settings();
        return response()->view('orbit-admin.camera',[
            'settings'=>$settings,'ranges'=>CameraPolicy::RANGES,
            'ready'=>$settings['revision']>0,
            'audits'=>$settings['revision']>0?$registry->history('remote',CameraPolicy::KEY):collect(),
            'mediaAvailable'=>config('orbit_media.enabled')===true,
            'momentsAvailable'=>config('orbit_moments.enabled')===true,
        ])->header('Cache-Control','private, no-store');
    }
    public function save(Request $request,CameraPolicy $policy,ConfigRegistry $registry)
    {
        $rules=['enhancements_enabled'=>'required|boolean','revision'=>'required|integer|min:1',
            'reason'=>'required|string|min:10|max:500','confirm'=>'accepted'];
        foreach(CameraPolicy::RANGES as $key=>[$min,$max])$rules[$key]='required|integer|between:'.$min.','.$max;
        $input=$request->validate($rules);
        abort_if((int)$input['photo_data_saver_long_edge_px']>(int)$input['photo_long_edge_px']||
            (int)$input['video_data_saver_target_bps']>(int)$input['video_target_bps'],422,
            'Data saver settings cannot exceed the standard profile.');
        abort_if((int)$input['video_duration_ms']>(int)config('orbit_media.max_video_duration_ms',60000),422,
            'Video duration exceeds the existing media limit.');
        DB::transaction(function()use($request,$policy,$registry,$input):void{
            $record=$policy->record(true);
            abort_unless($record,503,'Camera policy is not provisioned.');
            abort_unless((int)$record->version===(int)$input['revision'],409,'Settings changed; reload the page.');
            $values=CameraPolicy::DEFAULTS;
            $values['enhancements_enabled']=(bool)$input['enhancements_enabled'];
            foreach(CameraPolicy::RANGES as $key=>$range)$values[$key]=(int)$input[$key];
            $registry->saveConfig([
                'key'=>CameraPolicy::KEY,'description'=>'Camera and editing operational policy',
                'value_type'=>'object','value_json'=>json_encode($values,JSON_THROW_ON_ERROR),
                'schema_json'=>json_encode(CameraPolicy::schema(),JSON_THROW_ON_ERROR),
                'published'=>true,'client_visible'=>false,'reason'=>$input['reason'],
            ],$request->user('admin'),$record);
        },3);
        return redirect()->route('orbit.admin.camera.index')->with('status','Camera and editing controls saved.');
    }
}
