<?php
namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

final class MessagingSendRestrictionService
{
    public function provisioned(): bool
    {
        return Schema::hasTable('messaging_send_restrictions')
            && Schema::hasTable('messaging_send_restriction_audits');
    }

    public function isRestricted(string $user): bool
    {
        if(!$this->provisioned()) return false;
        return DB::table('messaging_send_restrictions')
            ->where('user_id',$user)
            ->where(function($q): void {
                $q->whereNull('ends_at')->orWhere('ends_at','>',now());
            })->exists();
    }

    public function assertMaySend(string $user): void
    {
        // Never silently bypass safety restrictions when an app release was
        // deployed before its moderation tables were migrated.
        abort_unless($this->provisioned(),503,
            'Messaging moderation storage must be initialized first.');
        abort_if($this->isRestricted($user),403,
            'Messaging has been temporarily restricted for this account.');
    }

    public function update(string $user,string $actor,array $data): void
    {
        abort_unless($this->provisioned(),503,
            'Messaging moderation migration is required.');
        $action=$data['action'];
        abort_unless(in_array($action,['suspend','restore'],true),422);
        $duration=$data['duration']??'24h';
        abort_unless(in_array($duration,['1h','24h','7d','30d','indefinite'],true),422);
        $reason=$data['reason']??'other';
        abort_unless(in_array($reason,['spam','harassment','fraud','abuse','other'],true),422);
        $end=match($duration){
            '1h'=>now()->addHour(),
            '24h'=>now()->addDay(),
            '7d'=>now()->addDays(7),
            '30d'=>now()->addDays(30),
            default=>null,
        };
        DB::transaction(function()use($user,$actor,$action,$reason,$end):void {
            // Serialize concurrent moderation changes against the account.
            DB::table('orbit_users')->where('id',$user)->lockForUpdate()->first()
                ?? abort(404,'Account unavailable.');
            if($action==='restore'){
                DB::table('messaging_send_restrictions')
                    ->where('user_id',$user)->delete();
            }else{
                DB::table('messaging_send_restrictions')->updateOrInsert(
                    ['user_id'=>$user],[
                        'reason'=>$reason,'ends_at'=>$end,
                        'updated_by'=>$actor,'updated_at'=>now(),
                    ]);
            }
            DB::table('messaging_send_restriction_audits')->insert([
                'user_id'=>$user,'admin_user_id'=>$actor,
                'action'=>$action,'reason'=>$action==='suspend'?$reason:null,
                'ends_at'=>$action==='suspend'?$end:null,
                'created_at'=>now(),
            ]);
        });
    }
}
