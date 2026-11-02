<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Disabled-by-default transactional account security email interface.
 * No marketing, bulk recipient selection, or arbitrary admin-authored mail.
 * Sends generic safe-copy only to an existing verified email identity.
 */
final class NotificationSecurityEmailTransport
{
    public function enabled(): bool
    {
        if(config('orbit_notifications.security_email_enabled')!==true)return false;
        $domain=strtolower((string)config('orbit_notifications.verified_email_domain',''));
        $from=strtolower((string)config('mail.from.address',''));
        $mailer=(string)config('mail.default','');
        if($domain===''||!preg_match('/^[a-z0-9.-]+\\.[a-z]{2,}$/D',$domain)
            ||!filter_var($from,FILTER_VALIDATE_EMAIL)
            ||substr($from,strrpos($from,'@')+1)!==$domain
            ||in_array($mailer,['','array','log','failover'],true))return false;
        return true;
    }

    /** Return a safe delivery state, never an address or provider exception. */
    public function sendNotice(string $noticeId): array
    {
        if(!$this->enabled())return ['status'=>'disabled'];
        $row=DB::table('notification_inbox')->where('id',$noticeId)
            ->where('category','security')->whereNull('invalidated_at')->first();
        if(!$row)return ['status'=>'suppressed'];
        if(!app(NotificationPolicy::class)->canView($row,$row->recipient_user_id))
            return ['status'=>'suppressed'];
        $identity=DB::table('user_identities')->where('user_id',$row->recipient_user_id)
            ->whereNotNull('verified_at')->whereNotNull('email_norm')
            ->orderByDesc('verified_at')->first();
        if(!$identity||!filter_var($identity->email_norm,FILTER_VALIDATE_EMAIL))
            return ['status'=>'suppressed'];
        $template=DB::table('notification_templates')->where('key','security.notice')
            ->where('enabled',true)->first();
        if(!$template)return ['status'=>'suppressed'];
        try{
            Mail::raw((string)$template->body,static function($m)use($identity,$template){
                $m->to($identity->email_norm)->subject((string)$template->title);
            });
            return ['status'=>'accepted_by_mailer'];
        }catch(\Throwable){
            return ['status'=>'retry'];
        }
    }
}
