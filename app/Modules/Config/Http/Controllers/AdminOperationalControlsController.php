<?php
namespace App\Modules\Config\Http\Controllers;
use App\Modules\Config\Services\OperationalControls;
use App\Modules\Config\Services\ConfigRegistry;
use Illuminate\Http\Request;

final class AdminOperationalControlsController{
    private function guard(string $module,OperationalControls $controls):array{
        $def=$controls->definition($module);
        abort_unless(auth()->guard('admin')->user()?->canPerform($def[1]),403);
        return $def;
    }
    public function show(string $module,OperationalControls $controls,ConfigRegistry $registry){
        $def=$this->guard($module,$controls);
        return response()->view('orbit-admin.operational-controls',[
            'module'=>$module,'definition'=>$def,'controls'=>$controls,'settings'=>$controls->snapshot($module),
            'history'=>$controls->installed()?$registry->history('remote',OperationalControls::PREFIX.$module):collect(),
        ])->header('Cache-Control','private, no-store');
    }
    public function save(Request $request,string $module,OperationalControls $controls){
        $def=$this->guard($module,$controls);
        abort_unless($request->user('admin')?->canPerform($def[2]),403);
        $rules=['revision'=>'required|integer|min:0','reason'=>'required|string|min:10|max:500','confirm'=>'accepted','values'=>'required|array:'.implode(',',array_keys($def[3]))];
        foreach($def[3] as $key=>$field)$rules['values.'.$key]='required|integer|between:'.$field[2].','.$controls->ceiling($field);
        $data=$request->validate($rules);
        $values=array_map('intval',$data['values']);
        $controls->save($module,$values,(int)$data['revision'],$data['reason'],$request->user('admin'));
        return redirect()->route('orbit.admin.operations.'.$module)->with('status','Operational settings saved and audited.');
    }
}
