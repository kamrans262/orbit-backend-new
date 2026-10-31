<?php
namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MessagingAdminControls
{
    public function provisioned(): bool
    {
        return Schema::hasTable('messaging_admin_controls')
            && Schema::hasTable('messaging_admin_activity');
    }

    private function setting(string $key): ?string
    {
        return $this->provisioned()
            ? DB::table('messaging_admin_controls')->where('key',$key)->value('value')
            : null;
    }

    public function retentionDays(): int
    {
        $stored=$this->setting('retention_days');
        $default=(int) config('orbit_messaging.undelivered_retention_days',30);
        $number=$stored!==null && ctype_digit($stored) ? (int)$stored : $default;
        return max(1,min(30,$number));
    }

    public function sendLimit(): int
    {
        $stored=$this->setting('send_rate_per_minute');
        $default=(int) config('orbit_messaging.per_minute_send',45);
        $number=$stored!==null && ctype_digit($stored) ? (int)$stored : $default;
        return max(5,min(120,$number));
    }

    public function typingEnabled(): bool
    {
        $stored=$this->setting('typing_http_enabled');
        return $stored===null
            ?config('orbit_messaging.typing_http_enabled')===true
            :$stored==='1';
    }

    public function values(): array
    {
        return [
            'retention_days'=>$this->retentionDays(),
            'send_rate_per_minute'=>$this->sendLimit(),
            'typing_http_enabled'=>$this->typingEnabled(),
        ];
    }

    public function update(array $input,string $actor): array
    {
        abort_unless($this->provisioned(),503,
            'Messaging administration migration is required.');
        // The controller validates a complete, tiny whitelist; no arbitrary
        // security flags or encryption-release settings can be edited here.
        $values=[
            'retention_days'=>(int)$input['retention_days'],
            'send_rate_per_minute'=>(int)$input['send_rate_per_minute'],
            'typing_http_enabled'=>(bool)$input['typing_http_enabled'],
        ];
        abort_unless($values['retention_days']>=1 &&
            $values['retention_days']<=30 &&
            $values['send_rate_per_minute']>=5 &&
            $values['send_rate_per_minute']<=120,422);
        $before=$this->values();
        DB::transaction(function()use($values,$before,$actor):void {
            foreach($values as $key=>$value){
                DB::table('messaging_admin_controls')->updateOrInsert(
                    ['key'=>$key],[
                        'value'=>is_bool($value)?($value?'1':'0'):(string)$value,
                        'updated_by'=>$actor,'updated_at'=>now(),
                    ]
                );
            }
            if($before!==$values){
                // Only safe operational integers/boolean enter audit details.
                DB::table('messaging_admin_activity')->insert([
                    'admin_user_id'=>$actor,'action'=>'settings.updated',
                    'details'=>json_encode([
                        'before'=>$before,'after'=>$values,
                    ],JSON_THROW_ON_ERROR),
                    'created_at'=>now(),
                ]);
            }
        });
        return $values;
    }

    public function audit(string $actor,string $action,array $details): void
    {
        abort_unless($this->provisioned(),503,
            'Messaging administration migration is required.');
        abort_unless(in_array($action,['retention.pruned'],true),422);
        DB::table('messaging_admin_activity')->insert([
            'admin_user_id'=>$actor,'action'=>$action,
            'details'=>json_encode($details,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }
}
