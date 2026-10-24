<?php
namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** No plaintext, keys, URLs, owners, Circle rosters or recipient identities in Admin. */
final class AdminMediaController extends Controller
{
    public function index(Request $r): Response
    {
        abort_unless(config('orbit_media.enabled')===true,404);
        abort_unless(Schema::hasTable('media_objects'),503,'Media migration not installed.');
        $filters=$r->validate(['media_id'=>['nullable','uuid'],'owner_id'=>['nullable','uuid']]);
        $metrics=[
            'objects'=>DB::table('media_objects')->count(),
            'ready'=>DB::table('media_objects')->where('state','ready')->count(),
            'verifying'=>DB::table('media_objects')->where('state','verifying')->count(),
            'deleting'=>DB::table('media_objects')->where('state','deleting')->count(),
            'encrypted_bytes'=>DB::table('media_objects')->where('state','ready')->sum('cipher_bytes'),
            'queued_purges'=>DB::table('media_delete_jobs')->where('status','pending')->count(),
            // The verification worker records failed sessions; rejected
            // originals transition to deleting, so counting media_objects
            // alone would silently hide these failures from operations.
            'failed'=>DB::table('media_upload_sessions')->where('state','failed')->count(),
            'expired_sessions'=>DB::table('media_upload_sessions')->where('state','expired')->count(),
            'active_reservations'=>DB::table('media_upload_sessions')->whereIn('state',['initiated','uploading'])->where('expires_at','>',now())->count(),
            'retried_deletions'=>DB::table('media_delete_jobs')->where('attempts','>',0)->where('status','pending')->count(),
            'held'=>DB::table('media_objects')->whereNotNull('legal_hold_until')
                ->where('legal_hold_until','>',now())->count(),
        ];
        $row=isset($filters['media_id'])
            ? DB::table('media_objects')->where('id',$filters['media_id'])
                ->first(['id','purpose','kind','state','cipher_bytes','created_at',
                    'ready_at','retention_deadline','deleted_at','policy_version'])
            : null;
        $quota=null;
        if(isset($filters['owner_id'])) {
            abort_unless(auth()->guard('admin')->user()?->canPerform('media.quotas.read'),
                403,'Media quota permission required.');
            $used=(int)DB::table('media_objects')->where('owner_user_id',$filters['owner_id'])
                ->whereIn('state',['ready','verifying','deleting'])->sum('cipher_bytes');
            $pending=(int)DB::table('media_upload_sessions')->where('owner_user_id',$filters['owner_id'])
                ->whereIn('state',['initiated','uploading'])->where('expires_at','>',now())
                ->sum('declared_bytes');
            $quota=['used_bytes'=>$used,'reserved_bytes'=>$pending,
                'limit_bytes'=>(int)config('orbit_media.per_owner_cipher_quota_bytes')];
        }
        // Job UUIDs only: no media/owner IDs, private names, key material or paths.
        $failedJobs=$r->user('admin')?->canPerform('media.jobs.retry')
            ? DB::table('media_delete_jobs')->where('status','pending')
                ->where('attempts','>',0)
                ->orderBy('next_attempt_at')->limit(20)
                ->get(['id','attempts','next_attempt_at'])
            : collect();
        return response()->view('orbit-admin.media',compact('metrics','row','quota','failedJobs'))
            ->header('Cache-Control','private, no-store')
            ->header('X-Content-Type-Options','nosniff');
    }

    /**
     * Requeues only previously failed deletion work with separate RBAC and
     * an accountable ticket. The worker still applies legal-hold checks.
     */
    public function retry(Request $r): \Illuminate\Http\RedirectResponse
    {
        abort_unless(config('orbit_media.enabled') === true, 404);
        $data=$r->validate([
            'job_id'=>['required','uuid'],
            'ticket'=>['required','string','min:10','max:80',
                'regex:/^[A-Za-z0-9._-]+$/D'],
            'confirm'=>['required','in:RETRY'],
        ]);
        $admin=auth()->guard('admin')->user();
        abort_unless($admin?->canPerform('media.jobs.retry'),403);
        $job=DB::table('media_delete_jobs')
            ->where('id',$data['job_id'])->first();
        abort_unless($job && $job->status === 'pending'
            && (int)$job->attempts > 0, 404, 'Retry not available.');
        DB::transaction(function () use ($job,$admin,$data): void {
            DB::table('media_delete_jobs')
                ->where('id',$job->id)->where('status','pending')
                ->update(['next_attempt_at'=>now(),'updated_at'=>now()]);
            DB::table('media_admin_audits')->insert([
                'id'=>(string)Str::uuid7(),
                'admin_user_id'=>$admin->id,
                'action'=>'media.delete.retry_scheduled',
                'subject_hmac'=>hash_hmac('sha256',$job->media_id,
                    (string)config('app.key')),
                'ticket'=>$data['ticket'],
                'created_at'=>now(),
            ]);
        });
        return redirect()->route('orbit.admin.media.index')
            ->with('status','Deletion retry scheduled for guarded worker review.');
    }

}
