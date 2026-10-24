<?php
namespace App\Modules\Moments\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data minimization for already-verified M3 account erasure.
 * Called only within M3ErasureProcessor's authorized transaction, after
 * the 30-day grace / hold / operator checks. No filesystem IO or raw content.
 */
final class MomentErasureService
{
    public function eraseUser(string $user):array
    {
        if(!Schema::hasTable('moments'))return ['moments'=>0,'comments'=>0,'interactions'=>0,'reports'=>0];
        $owned=DB::table('moments')->where('creator_id',$user)->pluck('id')->all();

        // Never let FK cascades silently delete encryption metadata before
        // M6 proved that every owned ciphertext object was physically purged.
        if($owned){
            $media=DB::table('moment_media as mm')
                ->join('media_objects as mo','mo.id','=','mm.media_id')
                ->whereIn('mm.moment_id',$owned)
                ->where('mo.state','!=','deleted')->exists();
            if($media)throw new DomainException(
                'M7 erasure requires verified M6 ciphertext purge before deleting published Moments.');
        }
        // Report moderation audit must not be destroyed without a documented
        // legal review. A held or adjudicated report explicitly blocks erasure.
        $reviewed=DB::table('moment_reports as mr')
            ->join('moment_moderation_actions as ma','ma.report_id','=','mr.id')
            ->where(function($q)use($user,$owned){
                $q->where('mr.reporter_id',$user);
                if($owned)$q->orWhereIn('mr.moment_id',$owned);
            })->exists();
        if($reviewed)throw new DomainException(
            'M7 moderation audit requires independent legal retention review before account erasure.');

        $reports=DB::table('moment_reports')->where('reporter_id',$user);
        if($owned)$reports->orWhereIn('moment_id',$owned);
        $reportIds=$reports->pluck('id')->all();
        if($reportIds)DB::table('moment_moderation_actions')->whereIn('report_id',$reportIds)->delete();
        $reportCount=$reportIds?DB::table('moment_reports')->whereIn('id',$reportIds)->delete():0;

        // Rows in other users' Moments also contain this account's identity.
        $comments=DB::table('moment_comments')->where('author_id',$user)->delete();
        $reactions=DB::table('moment_reactions')->where('user_id',$user)->delete();
        $views=DB::table('moment_views')->where('viewer_id',$user)->delete();
        $recipients=DB::table('moment_recipients')->where('viewer_id',$user)->delete();
        $idempotency=DB::table('moment_idempotency')->where('owner_id',$user)->delete();

        if($owned){
            // Revoke/release before removing Moment rows; owned media is already
            // state=deleted, and M6 metadata will be removed by M3 next.
            DB::table('media_references')->where('consumer_type','moment')
                ->whereIn('consumer_id',$owned)->delete();
        }
        $ownCount=DB::table('moments')->where('creator_id',$user)->delete();
        return [
            'moments'=>$ownCount,'comments'=>$comments,
            'interactions'=>$reactions+$views+$recipients+$idempotency,
            'reports'=>$reportCount,
        ];
    }
}
