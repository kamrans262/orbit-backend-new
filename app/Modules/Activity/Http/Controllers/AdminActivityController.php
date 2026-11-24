<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityPolicy;
use App\Modules\Activity\Services\ActivityProjector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AdminActivityController extends Controller
{
    public function __construct(private readonly ActivityPolicy $policy) {}

    private function render(string $area)
    {
        $ready = $this->policy->installed();
        $stats = ['events' => 0, 'moments' => 0, 'check_ins' => 0,
            'invalidated' => 0, 'completed_runs' => 0];
        $controls = collect(); $runs = collect(); $audits = collect();
        if ($ready) {
            $stats = [
                'events' => DB::table('activity_events')->count(),
                'moments' => DB::table('activity_events')
                    ->where('source_type', 'moment')->count(),
                'check_ins' => DB::table('activity_events')
                    ->where('source_type', 'check_in')->count(),
                'invalidated' => DB::table('activity_events')
                    ->whereNotNull('invalidated_at')->count(),
                'completed_runs' => DB::table('activity_projection_runs')
                    ->where('status','completed')->count(),
            ];
            $controls = DB::table('activity_controls')->orderBy('key')->get();
            if ($area === 'operations') {
                $runs = DB::table('activity_projection_runs')
                    ->orderByDesc('created_at')->orderByDesc('id')->paginate(25,[
                        'moments_added','check_ins_added','invalidated',
                        'status','error_code','created_at',
                    ])->withQueryString();
            }
            if ($area === 'audits') {
                $audits = DB::table('activity_admin_audits')
                    ->orderByDesc('created_at')->orderByDesc('id')->paginate(25,[
                        'action','subject_key','reason','admin_id',
                        'old_value','new_value','created_at',
                    ])->withQueryString();
            }
        }
        return response()->view('orbit-admin.activity',
            compact('area','ready','stats','controls','runs','audits'))
            ->header('Cache-Control','private, no-store');
    }

    public function overview() { return $this->render('overview'); }
    public function sources() { return $this->render('sources'); }
    public function operations() { return $this->render('operations'); }
    public function audits() { return $this->render('audits'); }

    private function approval(Request $r, array $rules = []): array
    {
        $this->policy->assertReady();
        return $r->validate(array_merge([
            'confirm' => ['accepted'],
            'reason' => ['required','string','min:10','max:500'],
        ], $rules));
    }

    private function audit(string $action, string $key, string $reason,
        ?string $oldValue = null, ?string $newValue = null): void
    {
        DB::table('activity_admin_audits')->insert([
            'id' => (string)Str::uuid7(),
            'admin_id' => (string)auth()->guard('admin')->id(),
            'action' => $action, 'subject_key' => $key,
            'reason' => trim($reason), 'created_at' => now(),
            'old_value' => $oldValue, 'new_value' => $newValue,
        ]);
    }

    public function control(Request $r)
    {
        $data = $this->approval($r, [
            'key' => ['required',Rule::in(['source.moment','source.check_in'])],
            'enabled' => ['required','boolean'],
        ]);
        DB::transaction(function () use ($data): void {
            $current = DB::table('activity_controls')->where('key', $data['key'])
                ->lockForUpdate()->first();
            abort_unless($current !== null, 404);
            $old = (bool)$current->enabled;
            $new = (bool)$data['enabled'];
            if ($old === $new) return; // No fake "changed" audit.
            DB::table('activity_controls')->where('key', $data['key'])->update([
                'enabled' => $new,
                'updated_by' => (string)auth()->guard('admin')->id(),
                'updated_at' => now(),
            ]);
            $this->audit('source.changed', $data['key'], $data['reason'],
                $old ? 'enabled' : 'disabled', $new ? 'enabled' : 'disabled');
        }, 3);
        return back()->with('status', 'Activity source control updated and audited.');
    }

    public function project(Request $r, ActivityProjector $worker)
    {
        $data = $this->approval($r);
        $result = $worker->run(40);
        $this->audit('projection.manual','bounded_projector',$data['reason']);
        return back()->with('status',
            'Bounded Activity projection completed: '.json_encode($result));
    }
}
