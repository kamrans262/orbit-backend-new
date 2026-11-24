<?php
namespace App\Modules\Realtime\Http\Controllers;

use App\Modules\Realtime\Services\RealtimeRecovery;
use Illuminate\Http\Request;

final class AdminRealtimeRecoveryController
{
    public function __invoke(Request $request,RealtimeRecovery $recovery)
    {
        $actor=$request->user('admin');
        abort_unless($actor?->canPerform('config.manage'),403);
        $data=$request->validate(['request_id'=>'required|uuid','operation'=>'required|in:retry,probe',
            'reason'=>'required|string|min:10|max:200','confirm'=>'accepted']);
        $recovery->request($actor,$data['request_id'],$data['operation'],$data['reason']);
        return redirect()->route('orbit.admin.operations.realtime')->with('status','Bounded recovery request recorded.');
    }
}
