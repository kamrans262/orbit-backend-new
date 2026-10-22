<?php

namespace App\Modules\Circle\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * M5 operational counters only. No Circle name, members, invitations,
 * content, location or administrative membership overrides are revealed.
 * Scoped role circles.read is required at route boundary.
 */
final class AdminCircleController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(config('orbit_circle.enabled') === true, 404);
        $filters = $request->validate(['circle_id'=>['nullable','uuid']]);
        $id = $filters['circle_id'] ?? null;
        $metrics = [
            'active' => DB::table('circles')->whereNull('archived_at')->count(),
            'archived' => DB::table('circles')->whereNotNull('archived_at')->count(),
            'memberships' => DB::table('circle_members')->whereNull('removed_at')->count(),
            'pending_invites' => DB::table('circle_invites')->where('status','pending')
                ->where('expires_at','>',now())->count(),
            'created_24h' => DB::table('circles')->where('created_at','>=',now()->subDay())->count(),
        ];
        $summary = null;
        if ($id) {
            $circle = DB::table('circles')->where('id',$id)->first(['id','archived_at','created_at','revision']);
            if ($circle) {
                $summary = [
                    'id'=>$circle->id,'status'=>$circle->archived_at?'archived':'active',
                    'created_at'=>$circle->created_at,'revision'=>$circle->revision,
                    'members'=>DB::table('circle_members')->where('circle_id',$id)->whereNull('removed_at')->count(),
                    'pending'=>DB::table('circle_invites')->where('circle_id',$id)
                        ->where('status','pending')->where('expires_at','>',now())->count(),
                ];
            }
        }
        return response()->view('orbit-admin.circles',compact('metrics','summary'))
            ->header('Cache-Control','private, no-store');
    }
}
