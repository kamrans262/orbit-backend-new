<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Social\Services\SocialDiscoveryService;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Explicitly opted-in contact matching. Input lives only in this request and
 * is never stored, returned, or written to audits; no public phone/email search.
 */
final class SocialContactController extends Controller
{
    public function match(Request $request, OtpService $otp,
        SocialGraphService $graph, SocialDiscoveryService $discovery): JsonResponse
    {
        $data = $request->validate([
            'consent_confirmed' => ['required','accepted'],
            'contacts' => ['required','array','min:1','max:25'],
            'contacts.*.type' => ['required', 'in:email,phone'],
            'contacts.*.value' => ['required','string','max:190'],
        ]);
        $session = $request->attributes->get('orbit_session');
        $viewer = $session->user_id;
        $matches = DB::transaction(function () use ($data, $otp, $viewer, $graph, $discovery): array {
            // Token/IP throttles also apply. A per-user daily DB quota prevents
            // multi-device circumvention. Only aggregate counts are persisted.
            $today = now()->toDateString();
            DB::table('social_contact_lookup_quotas')->insertOrIgnore([
                'user_id' => $viewer, 'quota_date' => $today, 'contact_count' => 0,
            ]);
            $quota = DB::table('social_contact_lookup_quotas')
                ->where('user_id', $viewer)->where('quota_date', $today)
                ->lockForUpdate()->first();
            $quantity = count($data['contacts']);
            if ($quota->contact_count + $quantity > 500) {
                abort(429, 'Contact lookup limit reached.');
            }
            DB::table('social_contact_lookup_quotas')->where('user_id',$viewer)
                ->where('quota_date',$today)->increment('contact_count',$quantity);

            $result = [];
            foreach ($data['contacts'] as $index => $contact) {
                $channel = $contact['type'] === 'phone' ? 'sms' : 'email';
                $normalized = $otp->normalized($channel, $contact['value']);
                if (!$normalized) continue;
                $provider = $contact['type'];
                $user = DB::table('user_identities as i')
                    ->join('orbit_users as u', 'u.id', '=', 'i.user_id')
                    ->join('social_discovery_settings as ds', 'ds.user_id', '=', 'u.id')
                    ->where('i.provider', $provider)
                    ->where('i.provider_subject', $normalized)
                    ->whereNotNull('i.verified_at')
                    ->where('ds.discoverable', true)
                    ->where('ds.discoverable_by_contacts', true)
                    ->where('u.status', 'active')
                    ->whereNull('u.deletion_requested_at')
                    ->where('u.id','!=',$viewer)
                    ->select(['u.id','u.display_name','u.username'])->first();
                if (!$user || $graph->isBlockedEitherWay($viewer,$user->id)) continue;
                $result[] = [
                    'contact_index' => $index,
                    'person' => [
                        'id' => $user->id, 'display_name' => $user->display_name,
                        'username' => $user->username,
                        'relationship' => $graph->relationship($viewer,$user->id),
                    ],
                ];
            }
            return $result;
        }, 3);
        return response()->json(['data'=>['items'=>$matches]])
            ->header('Cache-Control','no-store');
    }
}
