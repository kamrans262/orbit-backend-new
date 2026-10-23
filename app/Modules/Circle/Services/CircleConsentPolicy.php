<?php

namespace App\Modules\Circle\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Future feature-consumer privacy contract. A stored preference is not a
 * grant: delivery also needs active membership, current consent, privacy
 * precedence and a separately enabled, security-reviewed consumer.
 *
 * This intentionally contains NO Firebase or websocket implementation.
 */
final class CircleConsentPolicy
{
    public const CAPABILITIES = [
        'location','presence','moments','check_in','plans','status','ping','message',
    ];

    public const LOCATION_FIDELITY = ['hidden','approximate','precise'];

    public function preview(string $circleId, string $subject): array
    {
        $rows=DB::table('circle_permissions')
            ->where('circle_id',$circleId)
            ->where('scope_key','user:'.$subject)
            ->get(['permission_key','enabled','value_json','expires_at']);

        $saved=[];
        foreach ($rows as $row) {
            $settings = $row->value_json
                ? json_decode($row->value_json,true) : null;
            $saved[$row->permission_key]=[
                // Never present a stored unreviewed preference as live sharing.
                'enabled'=>false,
                'requested'=>$row->enabled===true || (int)$row->enabled===1,
                'expires_at'=>$row->expires_at,
                'settings'=>is_array($settings) ? $settings : null,
            ];
        }

        return [
            'sharing_active'=>false,
            'grants_enabled'=>false,
            'defaults'=>[
                'location'=>'hidden','presence'=>false,'moments'=>false,
                'check_in'=>false,'plans'=>false,'status'=>false,
                'ping'=>false,'message'=>false,
            ],
            'saved'=>$saved,
        ];
    }

    /**
     * Pure calculation for future consumers; never publish data solely from
     * the result of this method. A consumer must ALSO pass an enabled feature,
     * a fresh device-scoped subscription and authenticated recipient check.
     */
    public function consentCurrentlyValid(object $record, string $capability): bool
    {
        if (!in_array($capability,self::CAPABILITIES,true)) return false;
        if (!(bool)$record->enabled) return false;
        if ($record->expires_at === null ||
            Carbon::parse($record->expires_at)->lte(now())) return false;
        $settings=$record->value_json
            ? json_decode($record->value_json,true) : null;
        if(!is_array($settings) || ($settings['reviewed'] ?? false)!==true) {
            return false;
        }
        if($capability==='location') {
            $precision=$settings['precision']??'hidden';
            return in_array($precision,['approximate','precise'],true);
        }
        return true;
    }

    /** Always revocable by the subject, even in a temporary read-only Circle. */
    public function revoke(string $circleId, string $subject,
        string $capability): void
    {
        if(!in_array($capability,self::CAPABILITIES,true)) {
            abort(422,'Invalid Circle permission.');
        }
        DB::table('circle_permissions')->updateOrInsert([
            'circle_id'=>$circleId,
            'scope_key'=>'user:'.$subject,
            'permission_key'=>$capability,
        ],[
            'subject_user_id'=>$subject,'enabled'=>false,
            'expires_at'=>null,'value_json'=>null,
            'updated_at'=>now(),
        ]);
    }
}
