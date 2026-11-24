<?php
namespace App\Modules\Config\Services;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called in the same transaction as the authorized mutation. No credentials. */
final class OperationalAudit{
    public function record(AdminUser $actor,string $action,string $target,string $reason,array $before,array $after):void{
        $prev=DB::table('admin_audit_log')->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first()?->event_hash;
        $id=(string)Str::uuid7();
        $bh=hash('sha256',json_encode($before,JSON_THROW_ON_ERROR));$ah=hash('sha256',json_encode($after,JSON_THROW_ON_ERROR));
        DB::table('admin_audit_log')->insert([
            'id'=>$id,'actor_id'=>$actor->id,'action'=>$action,'target'=>$target,'reason'=>$reason,
            'before_hash'=>$bh,'after_hash'=>$ah,'previous_hash'=>$prev,
            'event_hash'=>hash('sha256',implode('|',[$prev??'',$actor->id,$action,$target,$bh,$ah,$reason,$id])),
            'request_id'=>$id,'ip_address'=>request()->ip(),'created_at'=>now(),
        ]);
    }
}
