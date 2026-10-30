<?php
namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\MessagingAccessPolicy;
use App\Modules\Messaging\Services\MessagingConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MessagingCircleConsentController extends Controller
{
    public function __construct(
        private readonly MessagingAccessPolicy $access,
        private readonly MessagingConversationService $conversations
    ) {}

    private function viewer(Request $request): string
    {
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401,'Authentication required.');
        $this->access->assertSession((string)$session->user_id,(string)$session->device_id);
        return (string)$session->user_id;
    }
    private function result(array $payload): JsonResponse
    {
        return response()->json(['data'=>$payload])
            ->header('Cache-Control','private, no-store')
            ->header('Vary','Authorization');
    }

    public function show(Request $r,string $circle): JsonResponse
    {
        $user=$this->viewer($r);
        $this->conversations->assertReady();
        return $this->result($this->conversations->consent($circle,$user));
    }

    public function update(Request $r,string $circle): JsonResponse
    {
        $user=$this->viewer($r);
        $this->conversations->assertReady();
        $data=$r->validate([
            'enabled'=>['required','boolean'],
            'confirm'=>['required_if:enabled,true','sometimes','boolean'],
        ]);
        abort_if($data['enabled']===true && ($data['confirm']??false)!==true,
            422,'Explicit Circle messaging consent is required.');
        return $this->result($this->conversations->updateConsent($circle,$user,
            (bool)$data['enabled']));
    }
}
