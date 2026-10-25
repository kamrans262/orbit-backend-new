<?php
namespace App\Modules\Moments\Services;

use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Media\Services\MediaPolicy;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;

final class MomentAudienceService
{
    public function __construct(
        private readonly SocialGraphService $social,
        private readonly CircleAccessPolicy $circles,
        private readonly MediaPolicy $policy
    ) {}

    /**
     * Returns context-scoped recipient/grant pairs. Every recipient's key envelopes
     * must already exist in M6; no publication before an authenticated upload.
     */
    public function resolve(string $owner,string $mediaId,string $kind,array $targets):array
    {
        abort_unless(in_array($kind,['only_me','accepted_friends','selected_friends','selected_circles'],true),422);
        // M3 global Moment privacy setting is an upper bound, not just a UI default.
        $raw=DB::table('orbit_user_preferences')->where('user_id',$owner)->value('privacy');
        $prefs=is_string($raw)?json_decode($raw,true):null;
        $global=is_array($prefs)?($prefs['moment_default_audience']??'Nobody'):'Nobody';
        if($kind==='selected_circles')abort_unless($global==='My Circle',403,'Circle Moment consent is not enabled.');
        if(in_array($kind,['accepted_friends','selected_friends'],true))
            abort_unless($global==='Selected People',403,'Friend Moment audience is not enabled.');
        $targets=array_values(array_unique($targets));
        abort_if(count($targets)>100,413);
        abort_if(in_array($kind,['only_me','accepted_friends'],true)&&count($targets)!==0,422,'Unexpected audience targets.');
        abort_if(in_array($kind,['selected_friends','selected_circles'],true)&&count($targets)===0,422,'Select an audience.');
        $m=DB::table('media_objects')->where('id',$mediaId)->where('owner_user_id',$owner)
            ->where('purpose','moment')->where('state','ready')->whereNull('deleted_at')->first();
        abort_unless($m,404,'Verified media unavailable.');
        $variant=match($m->kind){'image'=>'thumbnail','video'=>'poster','audio'=>'waveform',default=>'unsupported'};
        abort_unless($variant!=='unsupported',422,'Unsupported Moment media.');
        abort_unless(DB::table('media_variants')->where('media_id',$mediaId)
            ->where('variant',$variant)->where('state','ready')->exists(),409,
            'Encrypted thumbnail or poster must be verified first.');
        $pairs=[];
        if($kind==='only_me') $pairs[]=['recipient'=>$owner,'context'=>'self','circle'=>null];
        if($kind==='accepted_friends') {
            $friendships=DB::table('friendships')
                ->where('user_low_id',$owner)->orWhere('user_high_id',$owner)->get();
            foreach($friendships as $f){
                $viewer=$f->user_low_id===$owner?$f->user_high_id:$f->user_low_id;
                if($this->policy->recipientEligible($owner,$viewer,$kind,null))
                    $pairs[]=['recipient'=>$viewer,'context'=>'friends','circle'=>null];
            }
        }
        if($kind==='selected_friends') foreach($targets as $viewer){
            abort_unless($this->policy->recipientEligible($owner,$viewer,$kind,null),422,'Recipient unavailable.');
            $pairs[]=['recipient'=>$viewer,'context'=>'friends','circle'=>null];
        }
        if($kind==='selected_circles')foreach($targets as $circle){
            abort_unless($this->circles->isMember($circle,$owner),404,'Circle unavailable.');
            $members=DB::table('circle_members')->where('circle_id',$circle)->whereNull('removed_at')->pluck('user_id');
            foreach($members as $viewer){
                if($viewer===$owner)continue;
                if(!$this->policy->recipientEligible($owner,$viewer,$kind,$circle))continue;
                if(!$this->circles->canAccessProtected($circle,$viewer,$owner,'moments'))continue;
                $pairs[]=['recipient'=>$viewer,'context'=>'circle:'.$circle,'circle'=>$circle];
            }
        }
        abort_if(count($pairs)===0,422,'There are no eligible recipients.');
        abort_if(count(array_unique(array_column($pairs,'recipient')))>config('orbit_moments.max_recipients',100),413);
        foreach($pairs as &$pair){
            $query=DB::table('media_audience_grants')
                ->where('media_id',$mediaId)->where('issuer_user_id',$owner)
                ->where('recipient_user_id',$pair['recipient'])->where('audience_kind',$kind)
                ->whereNull('revoked_at')
                ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()));
            if($pair['circle']!==null)$query->where('circle_id',$pair['circle']);
            else $query->whereNull('circle_id');
            // Editing a Moment creates a fresh, versioned grant. The old
            // active grant can lack newly trusted devices, and must not be
            // chosen just because the database returned it first.
            $grants=$query->orderByDesc('policy_version')
                ->orderByDesc('created_at')->get();
            abort_if($grants->isEmpty(),409,'Recipient media grants are incomplete.');
            // Do not publish with a device missing either its original or derivative key.
            $devices=DB::table('devices')->where('user_id',$pair['recipient'])
                ->whereNotNull('approved_at')->whereNull('revoked_at')->where('trust_state','trusted')
                ->whereNotNull('public_key')->get(['id','public_key']);
            abort_if($devices->isEmpty(),409,'Recipient has no trusted device.');
            $validDevices=$devices->filter(function($device){
                $pub=base64_decode((string)$device->public_key,true);
                return $pub!==false && strlen($pub)===32;
            });
            abort_if($validDevices->isEmpty(),409,'Recipient has no valid trusted device key.');
            $chosen=null;
            foreach($grants as $candidate){
                $complete=true;
                foreach($validDevices as $device){
                    foreach(['original',$variant] as $v){
                        if(!DB::table('media_device_key_envelopes')
                            ->where('grant_id',$candidate->id)
                            ->where('recipient_device_id',$device->id)
                            ->where('variant',$v)->whereNull('revoked_at')->exists()){
                            $complete=false;break 2;
                        }
                    }
                }
                if($complete){$chosen=$candidate;break;}
            }
            abort_unless($chosen,409,'The encrypted device envelope manifest is incomplete.');
            $pair['grant_id']=$chosen->id;
        }
        unset($pair);
        return $pairs;
    }
}
