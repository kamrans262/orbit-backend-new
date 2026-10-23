<?php

namespace App\Modules\Circle\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Circle\Services\CircleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CircleController extends Controller
{
    private function viewer(Request $r): string
    {
        return $r->attributes->get('orbit_session')->user_id;
    }
    private function result(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data'=>$data],$status)->header('Cache-Control','private, no-store');
    }
    private function page(Request $r): int
    {
        return (int) $r->validate(['page'=>['sometimes','integer','between:1,100']])['page'] ?? 1;
    }

    public function features(Request $r): JsonResponse
    {
        return $this->result([
            'temporary_circles'=>config('orbit_circle.temporary_circles_enabled') === true,
            'targeted_invites'=>config('orbit_circle.targeted_invites_enabled') === true,
            'join_codes'=>config('orbit_circle.join_codes_enabled') === true,
            'archive'=>config('orbit_circle.archive_enabled') === true,
            'role_changes'=>config('orbit_circle.role_changes_enabled') === true,
            'ownership_transfer'=>config('orbit_circle.ownership_transfer_enabled') === true,
        ]);
    }

    /**
     * Trusted-session Firebase Auth bootstrap; responds 404 while disabled.
     * Never leaks service-account credentials or consumer private data.
     */
    public function realtimeBootstrap(Request $r,
        \App\Modules\Circle\Services\CircleFirebaseBridge $firebase): JsonResponse
    {
        abort_unless($firebase->enabled(), 404);
        return $this->result($firebase->bootstrap($r->attributes->get('orbit_session')))
            ->header('Cache-Control', 'no-store');
    }

    public function primary(Request $r,
        \App\Modules\Circle\Services\CirclePrimaryService $service): JsonResponse
    {
        return $this->result($service->current($this->viewer($r)));
    }

    public function choosePrimary(Request $r,
        \App\Modules\Circle\Services\CirclePrimaryService $service): JsonResponse
    {
        $data=$r->validate(['circle_id'=>['present','nullable','uuid']]);
        return $this->result($service->choose($this->viewer($r),$data['circle_id']));
    }

    public function listing(Request $r, CircleService $circles): JsonResponse
    {
        $page = (int) ($r->validate(['page'=>['sometimes','integer','between:1,100']])['page'] ?? 1);
        return $this->result($circles->listing($this->viewer($r),$page));
    }
    public function create(Request $r, CircleService $circles): JsonResponse
    {
        $data = $r->validate([
            'name'=>['required','string','min:1','max:80'],
            'icon_key'=>['sometimes','nullable','string','max:40','regex:/^[a-zA-Z0-9._-]+$/D'],
            'color_value'=>['sometimes','nullable','regex:/^#[0-9a-fA-F]{6}$/D'],
            'expires_at'=>['sometimes','nullable','date','after:now'],
        ]);
        if (trim($data['name']) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['name'=>'Circle name is required.']);
        }
        $key = $r->header('Idempotency-Key');
        if ($key !== null && (!is_string($key) || !preg_match('/^[A-Za-z0-9._-]{8,128}$/D',$key))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'Idempotency-Key'=>'Use 8-128 URL-safe characters.',
            ]);
        }
        return $this->result($circles->create($this->viewer($r),$data,$key),201);
    }
    public function detail(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        return $this->result($circles->detail($circle,$this->viewer($r)));
    }
    public function update(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        $updates = $r->validate([
            'name'=>['sometimes','required','string','min:1','max:80'],
            'icon_key'=>['sometimes','nullable','string','max:40','regex:/^[a-zA-Z0-9._-]+$/D'],
            'color_value'=>['sometimes','nullable','regex:/^#[0-9a-fA-F]{6}$/D'],
        ]);
        if (!$updates || (isset($updates['name']) && trim($updates['name']) === '')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name'=>'Provide a valid Circle change.']);
        }
        return $this->result($circles->rename($circle,$this->viewer($r),$updates));
    }
    public function members(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        $page = (int) ($r->validate(['page'=>['sometimes','integer','between:1,100']])['page'] ?? 1);
        return $this->result($circles->members($circle,$this->viewer($r),$page));
    }
    public function invite(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        $data = $r->validate(['invitee_id'=>['required','uuid']]);
        return $this->result($circles->invite($circle,$this->viewer($r),$data['invitee_id']),201);
    }
    public function outgoing(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        $page = (int) ($r->validate(['page'=>['sometimes','integer','between:1,100']])['page'] ?? 1);
        return $this->result($circles->invites($circle,$this->viewer($r),$page));
    }
    public function incoming(Request $r, CircleService $circles): JsonResponse
    {
        $page = (int) ($r->validate(['page'=>['sometimes','integer','between:1,100']])['page'] ?? 1);
        return $this->result($circles->incoming($this->viewer($r),$page));
    }
    public function previewCode(Request $r, CircleService $circles): JsonResponse
    {
        $data=$r->validate(['code'=>['required','string','size:40','regex:/^[A-Za-z0-9]+$/D']]);
        return $this->result($circles->previewCode($this->viewer($r),$data['code']));
    }

    public function redeemCode(Request $r, CircleService $circles): JsonResponse
    {
        $data=$r->validate([
            'code'=>['required','string','size:40','regex:/^[A-Za-z0-9]+$/D'],
            'confirmation'=>['required',Rule::in(['JOIN'])],
        ]);
        return $this->result($circles->redeemCode($this->viewer($r),$data['code']));
    }

    public function accept(Request $r, CircleService $circles, string $invite): JsonResponse
    {
        return $this->result($circles->answer($invite,$this->viewer($r),true));
    }
    public function decline(Request $r, CircleService $circles, string $invite): JsonResponse
    {
        return $this->result($circles->answer($invite,$this->viewer($r),false));
    }
    public function revoke(Request $r, CircleService $circles, string $circle, string $invite): JsonResponse
    {
        $circles->revoke($circle,$this->viewer($r),$invite);
        return $this->result(['revoked'=>true]);
    }
    public function leave(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        $circles->leave($circle,$this->viewer($r));
        return $this->result(['left'=>true]);
    }
    public function remove(Request $r, CircleService $circles, string $circle, string $user): JsonResponse
    {
        $circles->remove($circle,$this->viewer($r),$user);
        return $this->result(['removed'=>true]);
    }
    public function role(Request $r, CircleService $circles, string $circle, string $user): JsonResponse
    {
        $data = $r->validate(['role'=>['required',Rule::in(['admin','member','restricted'])]]);
        $circles->changeRole($circle,$this->viewer($r),$user,$data['role']);
        return $this->result(['role'=>$data['role']]);
    }
    public function transfer(Request $r,
        \App\Modules\Circle\Services\CircleOwnershipService $ownership,
        string $circle): JsonResponse
    {
        $data = $r->validate([
            'recipient_id'=>['required','uuid'],
            'confirmation'=>['required', Rule::in(['TRANSFER'])],
        ]);
        return $this->result($ownership->transfer(
            $circle,$this->viewer($r),$data['recipient_id']));
    }

    public function archived(Request $r,
        \App\Modules\Circle\Services\CircleArchiveService $service): JsonResponse
    {
        $p=(int) ($r->validate(['page'=>['sometimes','integer','between:1,100']])['page'] ?? 1);
        return $this->result($service->archived($this->viewer($r),$p));
    }

    public function archive(Request $r,
        \App\Modules\Circle\Services\CircleArchiveService $service,
        string $circle): JsonResponse
    {
        $r->validate(['confirmation'=>['required',Rule::in(['ARCHIVE'])]]);
        return $this->result($service->archive($circle,$this->viewer($r)));
    }

    public function restore(Request $r,
        \App\Modules\Circle\Services\CircleArchiveService $service,
        string $circle): JsonResponse
    {
        $r->validate(['confirmation'=>['required',Rule::in(['RESTORE'])]]);
        return $this->result($service->restore($circle,$this->viewer($r)));
    }

    public function permissions(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        return $this->result($circles->permissions($circle,$this->viewer($r)));
    }
    public function revokePermission(Request $r, CircleService $circles, string $circle): JsonResponse
    {
        $data = $r->validate([
            'permission_key'=>['required',Rule::in([
                'location','presence','moments','check_in','plans','status','ping','message',
            ])],
            'enabled'=>['required','boolean',Rule::in([false,0,'0'])],
        ]);
        // Granting any privacy-sensitive capability requires explicit approved
        // duration/precision/audience UX and downstream authorization integration.
        return $this->result($circles->disablePermission($circle,$this->viewer($r),$data['permission_key']));
    }
}
