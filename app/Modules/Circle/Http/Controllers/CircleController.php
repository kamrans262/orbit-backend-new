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
