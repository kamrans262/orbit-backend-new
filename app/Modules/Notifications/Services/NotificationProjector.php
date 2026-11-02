<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NotificationProjector
{
    public function __construct(private readonly NotificationService $notices,
                                private readonly NotificationPolicy $policy) {}

    public function run(int $limit=75): array
    {
        $stats=['moments'=>0,'pings'=>0,'check_ins'=>0,'messages'=>0,'friends'=>0,'circles'=>0,'errors'=>0];
        if(config('orbit_notifications.enabled')!==true || !$this->policy->installed())return $stats;
        $limit=max(1,min(100,$limit));$since=now()->subDays(7);
        // Stage 2 bridges existing durable source records; no fake events and
        // no changes to M7's independently maintained Moment inbox.
        if(Schema::hasTable('moment_notification_inbox')){
            $rows=DB::table('moment_notification_inbox as n')
                ->where('n.created_at','>=',$since)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','moment_notice')->whereColumn('e.source_id','n.id'))
                ->orderBy('n.created_at')->limit($limit)->get(['n.*']);
            foreach($rows as $row){
                try{
                    $stats['moments']+=$this->notices->publish('moment_notice',$row->id,
                        'moments','moment.available',$row->moment_id,[$row->recipient_user_id],
                        $row->context_id);
                }catch(\Throwable){$stats['errors']++;}
            }
        }
        if(Schema::hasTable('pings')){
            $rows=DB::table('pings as p')->where('p.created_at','>=',$since)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','ping')->whereColumn('e.source_id','p.id'))
                ->orderBy('p.created_at')->limit($limit)->get(['p.*']);
            foreach($rows as $row){
                try{
                    $context=$row->context_type==='circle' && $row->context_id
                        ? 'circle:'.$row->context_id:null;
                    $stats['pings']+=$this->notices->publish('ping',$row->id,'pings',
                        'ping.received',$row->id,[$row->recipient_id],$context);
                }catch(\Throwable){$stats['errors']++;}
            }
        }
        if(Schema::hasTable('checkins') && Schema::hasTable('checkin_recipients')){
            $rows=DB::table('checkins as c')->where('c.created_at','>=',$since)
                ->whereNull('c.deleted_at')
                ->where(fn($q)=>$q->whereNull('c.expires_at')->orWhere('c.expires_at','>',now()))
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','check_in')->whereColumn('e.source_id','c.id'))
                ->orderBy('c.created_at')->limit(min($limit,40))->get(['c.*']);
            foreach($rows as $row){
                try{
                    $recipients=DB::table('checkin_recipients')->where('checkin_id',$row->id)
                        ->where('audience_revision',$row->audience_revision)
                        ->whereNull('revoked_at')->limit(1000)->pluck('recipient_user_id')->all();
                    $stats['check_ins']+=$this->notices->publish('check_in',$row->id,
                        'check_ins','check_in.available',$row->id,$recipients,
                        null,$row->expires_at);
                }catch(\Throwable){$stats['errors']++;}
            }
        }
        if(Schema::hasTable('messaging_messages') && Schema::hasTable('messaging_envelopes')){
            $rows=DB::table('messaging_messages as m')->where('m.created_at','>=',$since)
                ->whereNull('m.redacted_at')
                ->where(fn($q)=>$q->whereNull('m.expires_at')->orWhere('m.expires_at','>',now()))
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','message')->whereColumn('e.source_id','m.id'))
                ->orderBy('m.created_at')->limit(min($limit,40))->get(['m.*']);
            foreach($rows as $row){
                try{
                    $recipients=DB::table('messaging_envelopes as e')
                        ->join('devices as d','d.id','=','e.recipient_device_id')
                        ->where('e.message_id',$row->id)->where('d.user_id','!=',$row->sender_id)
                        ->whereNull('d.revoked_at')->distinct()->limit(201)->pluck('d.user_id')->all();
                    $conversation=DB::table('messaging_conversations')
                        ->where('id',$row->conversation_id)->first();
                    $context=$conversation && $conversation->kind==='circle'
                        && $conversation->circle_id ? 'circle:'.$conversation->circle_id : null;
                    $stats['messages']+=$this->notices->publish('message',$row->id,
                        'messages','message.available',$row->id,$recipients,
                        $context,$row->expires_at);
                }catch(\Throwable){$stats['errors']++;}
            }
        }
        if(Schema::hasTable('friend_requests')){
            $pending=DB::table('friend_requests as f')->where('f.status','pending')
                ->where('f.created_at','>=',$since)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','friend_request')->whereColumn('e.source_id','f.id'))
                ->orderBy('f.created_at')->limit($limit)->get(['f.*']);
            foreach($pending as $row){
                try{
                    $stats['friends']+=$this->notices->publish('friend_request',$row->id,
                        'friends','friend.requested',$row->id,[$row->recipient_id]);
                }catch(\Throwable){$stats['errors']++;}
            }
            $accepted=DB::table('friend_requests as f')->where('f.status','accepted')
                ->where('f.responded_at','>=',$since)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','friend_acceptance')->whereColumn('e.source_id','f.id'))
                ->orderBy('f.responded_at')->limit($limit)->get(['f.*']);
            foreach($accepted as $row){
                try{
                    $stats['friends']+=$this->notices->publish('friend_acceptance',$row->id,
                        'friends','friend.accepted',$row->id,[$row->sender_id]);
                }catch(\Throwable){$stats['errors']++;}
            }
        }
        if(Schema::hasTable('circle_invites')){
            $invited=DB::table('circle_invites as c')->where('c.status','pending')
                ->where('c.created_at','>=',$since)->where('c.expires_at','>',now())
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','circle_invite')->whereColumn('e.source_id','c.id'))
                ->orderBy('c.created_at')->limit($limit)->get(['c.*']);
            foreach($invited as $row){
                try{
                    $stats['circles']+=$this->notices->publish('circle_invite',$row->id,
                        'circles','circle.invited',$row->id,[$row->invitee_id],
                        'circle:'.$row->circle_id,$row->expires_at);
                }catch(\Throwable){$stats['errors']++;}
            }
            $joined=DB::table('circle_invites as c')->where('c.status','accepted')
                ->where('c.resolved_at','>=',$since)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('notification_events as e')
                    ->where('e.source_type','circle_acceptance')->whereColumn('e.source_id','c.id'))
                ->orderBy('c.resolved_at')->limit($limit)->get(['c.*']);
            foreach($joined as $row){
                try{
                    $stats['circles']+=$this->notices->publish('circle_acceptance',$row->id,
                        'circles','circle.joined',$row->id,[$row->inviter_id],
                        'circle:'.$row->circle_id);
                }catch(\Throwable){$stats['errors']++;}
            }
        }
        return $stats;
    }
}
