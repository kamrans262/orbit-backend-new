<?php
namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/** No plaintext, keys, URLs, owners, Circle rosters or recipient identities in Admin. */
final class AdminMediaController extends Controller
{
    public function index(Request $r): Response
    {
        abort_unless(config('orbit_media.enabled')===true,404);
        abort_unless(Schema::hasTable('media_objects'),503,'Media migration not installed.');
        $filters=$r->validate(['media_id'=>['nullable','uuid']]);
        $metrics=[
            'objects'=>DB::table('media_objects')->count(),
            'ready'=>DB::table('media_objects')->where('state','ready')->count(),
            'verifying'=>DB::table('media_objects')->where('state','verifying')->count(),
            'deleting'=>DB::table('media_objects')->where('state','deleting')->count(),
            'encrypted_bytes'=>DB::table('media_objects')->where('state','ready')->sum('cipher_bytes'),
            'queued_purges'=>DB::table('media_delete_jobs')->where('status','pending')->count(),
            'held'=>DB::table('media_objects')->whereNotNull('legal_hold_until')
                ->where('legal_hold_until','>',now())->count(),
        ];
        $row=isset($filters['media_id'])
            ? DB::table('media_objects')->where('id',$filters['media_id'])
                ->first(['id','purpose','kind','state','cipher_bytes','created_at',
                    'ready_at','retention_deadline','deleted_at','policy_version'])
            : null;
        return response()->view('orbit-admin.media',compact('metrics','row'))
            ->header('Cache-Control','private, no-store')
            ->header('X-Content-Type-Options','nosniff');
    }
}