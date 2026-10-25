<?php
namespace App\Modules\Moments\Services;

use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Media\Services\MediaPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;

final class MomentPolicy
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly CircleAccessPolicy $circles,
        private readonly MediaPolicy $media
    ) {}

    public function active(object $moment):bool
    {
        return config('orbit_moments.enabled')===true
            && config('orbit_media.enabled')===true
            && $moment->state==='published' && $moment->deleted_at===null
            && now()->lt($moment->view_expires_at);
    }

    /** Return only the caller's currently authorized context; never disclose other recipients. */
    public function context(object $moment,string $viewer,?string $requested=null):?string
    {
        if (!$this->active($moment)) return null;
        if($moment->creator_id===$viewer) {
            if($requested===null||$requested==='self')return 'self';
            return DB::table('moment_audience')->where('moment_id',$moment->id)
                ->where('version',$moment->audience_revision)->where('context_id',$requested)
                ->whereNull('revoked_at')->exists() ? $requested : null;
        }
        if($this->social->isBlockedEitherWay($viewer,$moment->creator_id))return null;
        $privacyRaw=DB::table('orbit_user_preferences')->where('user_id',$moment->creator_id)->value('privacy');
        $privacy=is_string($privacyRaw)?json_decode($privacyRaw,true):null;
        $global=is_array($privacy)?($privacy['moment_default_audience']??'Nobody'):'Nobody';
        $mediaId=DB::table('moment_media')->where('moment_id',$moment->id)->where('role','primary')->value('media_id');
        if(!$mediaId)return null;
        $media=DB::table('media_objects')->where('id',$mediaId)->first();
        if(!$media || !$this->media->canRead($media,$viewer))return null;
        $records=DB::table('moment_recipients as mr')
            ->join('media_audience_grants as g','g.id','=','mr.authorizing_grant_id')
            ->where('mr.moment_id',$moment->id)->where('mr.viewer_id',$viewer)
            ->where('mr.version',$moment->audience_revision)->whereNull('mr.revoked_at')
            ->whereNull('g.revoked_at')
            ->where(fn($q)=>$q->whereNull('g.expires_at')->orWhere('g.expires_at','>',now()))
            ->select(['mr.context_id','g.audience_kind','g.circle_id'])
            ->get();
        foreach($records as $row) {
            if($requested!==null && $requested!==$row->context_id)continue;
            if($row->context_id==='friends' && $global==='Selected People'
                && in_array($row->audience_kind,['accepted_friends','selected_friends'],true)
                && $this->social->isFriend($viewer,$moment->creator_id))return 'friends';
            if($global==='My Circle' && str_starts_with($row->context_id,'circle:') && $row->circle_id!==null
                && $row->context_id==='circle:'.$row->circle_id
                && $this->circles->canAccessProtected($row->circle_id,$viewer,$moment->creator_id,'moments'))return $row->context_id;
        }
        return null;
    }

    public function requireContext(object $moment,string $viewer,?string $context=null):string
    {
        return $this->context($moment,$viewer,$context) ?? abort(404,'Moment unavailable.');
    }
}
