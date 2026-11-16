<?php

namespace App\Modules\Safety\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pseudonymous, chained incident audit; caller holds the incident row lock. */
final class SosAuditService
{
    public function incident(string $incident, ?string $actor, string $action): void
    {
        $previous=(string)(DB::table('sos_audit_events')->where('incident_id',$incident)
            ->orderByDesc('created_at')->orderByDesc('id')->value('event_hash') ?? str_repeat('0',64));
        $id=(string)Str::uuid7();
        $when=now()->toDateTimeString();
        $actorHash=$actor===null?null:hash_hmac('sha256',$actor,(string)config('app.key'));
        $hash=hash_hmac('sha256',implode('|',[$id,$incident,$actorHash??'', $action,$when,$previous]),
            (string)config('app.key'));
        DB::table('sos_audit_events')->insert([
            'id'=>$id,'incident_id'=>$incident,'actor_hash'=>$actorHash,'action'=>$action,
            'previous_hash'=>$previous,'event_hash'=>$hash,'created_at'=>$when,
        ]);
    }

    public function admin(string $actor, string $action, ?string $incident, string $reason): void
    {
        $mutex=DB::table('sos_controls')->where('key','escalation_enabled')->lockForUpdate()->first();
        $previous=(string)(DB::table('sos_admin_audits')->orderByDesc('created_at')
            ->orderByDesc('id')->value('event_hash') ?? str_repeat('0',64));
        $id=(string)Str::uuid7();
        $when=now()->toDateTimeString();
        $hashId=$incident===null?null:hash_hmac('sha256',$incident,(string)config('app.key'));
        $hash=hash_hmac('sha256',implode('|',[$id,$actor,$action,$hashId??'',$reason,$when,$previous]),
            (string)config('app.key'));
        DB::table('sos_admin_audits')->insert([
            'id'=>$id,'admin_id'=>$actor,'action'=>$action,'incident_hash'=>$hashId,
            'reason'=>$reason,'previous_hash'=>$previous,'event_hash'=>$hash,'created_at'=>$when,
        ]);
    }
}
