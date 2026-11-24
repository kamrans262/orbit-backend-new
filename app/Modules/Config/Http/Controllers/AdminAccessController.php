<?php
namespace App\Modules\Config\Http\Controllers;
use App\Models\AdminUser;
use App\Models\AdminRole;
use App\Modules\Config\Services\OperationalAudit;
use App\Modules\Config\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AdminAccessController{
    // Explicit permissions, never arbitrary strings or wildcard grants. New
    // roles do not confer access until a different super administrator approves.
    public const DELEGABLE=[
        'users.read','users.manage','deletions.read','settings.audit.read','social.read','social.manage',
        'circles.read','circles.manage','media.read','media.quotas.read','media.jobs.retry','media.manage',
        'moments.read','moments.manage','camera.read','camera.manage','notifications.read','notifications.manage',
        'plans.read','plans.manage','plans.audit','safety.read','safety.audit','moderation.read','moderation.audit',
        'activity.read','activity.manage','activity.audit','relay.read','relay.audit',
        'billing.read','billing.audit','billing.reconcile','advertising.read','advertising.manage','advertising.analytics','advertising.audit',
    ];
    private function operator(Request $r,bool $fresh=false):AdminUser{
        $actor=$r->user('admin');abort_unless($actor?->canPerform('*'),403);
        if($fresh){
            $code=$r->validate(['mfa_code'=>'required|digits:6'])['mfa_code'];
            abort_unless(app(Totp::class)->verify($actor->totp_secret,(string)$code),403,'Fresh verification required.');
        }
        return $actor;
    }
    private function ready():bool{return Schema::hasTable('admin_access_reviews')&&Schema::hasColumn('admin_users','session_revision');}
    private function digest(AdminUser $target):string{
        return hash('sha256',json_encode([$target->status,(int)$target->session_revision,$target->roles()->orderBy('admin_roles.id')->pluck('admin_roles.id')->all()],JSON_THROW_ON_ERROR));
    }
    private function roleDigest(AdminRole $role):string{
        return hash('sha256',json_encode([$role->id,$role->permissions],JSON_THROW_ON_ERROR));
    }
    private function eligibleRole(AdminRole $role):bool{
        return is_array($role->permissions)&&count($role->permissions)>0&&!array_diff($role->permissions,self::DELEGABLE);
    }
    private function target(string $id,AdminUser $actor):AdminUser{
        $target=AdminUser::whereKey($id)->lockForUpdate()->firstOrFail();
        // No changes to super admins, including suspended wildcard roles.
        abort_if($target->id===$actor->id||$target->roles()->get()->contains(fn($r)=>in_array('*',$r->permissions??[],true)),403,'Protected administrator.');
        return $target;
    }
    public function index(Request $r){
        $this->operator($r);$ready=$this->ready();
        $admins=AdminUser::select('id','name','email','status','last_login_at')->with('roles')->orderBy('name')->paginate(25);
        $roles=AdminRole::orderBy('name')->get();
        $delegable=$roles->filter(fn($role)=>$this->eligibleRole($role));
        $reviews=$ready?DB::table('admin_access_reviews')->select('id','target_id','requested_by','decided_by','operation','role_id','state','reason','expires_at','created_at')->orderByDesc('created_at')->limit(50)->get():collect();
        return response()->view('orbit-admin.administrator-access',compact('admins','roles','delegable','reviews','ready')+['permissions'=>self::DELEGABLE])->header('Cache-Control','private, no-store');
    }
    public function createRole(Request $r,OperationalAudit $audit){
        $actor=$this->operator($r,true);abort_unless($this->ready(),503);
        $d=$r->validate(['name'=>'required|string|min:3|max:100|unique:admin_roles,name','permissions'=>'required|array|min:1|max:40','permissions.*'=>'required|distinct|in:'.implode(',',self::DELEGABLE),'reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        DB::transaction(function()use($d,$actor,$audit){
            $role=AdminRole::create(['name'=>$d['name'],'permissions'=>array_values($d['permissions'])]);
            $audit->record($actor,'administrators.role.create','role:'.$role->id,$d['reason'],[],['permissions'=>$role->permissions]);
        },3);
        return back()->with('status','Role created. Assignments require independent approval.');
    }
    public function propose(Request $r,OperationalAudit $audit){
        $actor=$this->operator($r,true);abort_unless($this->ready(),503);
        $d=$r->validate(['target_id'=>'required|uuid','operation'=>'required|in:grant,revoke,suspend,activate,sign_out',
            'role_id'=>'nullable|required_if:operation,grant,revoke|uuid','reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        abort_if(!in_array($d['operation'],['grant','revoke'],true)&&!empty($d['role_id']),422);
        DB::transaction(function()use($d,$actor,$audit){
            $target=$this->target($d['target_id'],$actor);$role=null;
            if(!empty($d['role_id'])){
                $role=AdminRole::whereKey($d['role_id'])->lockForUpdate()->firstOrFail();
                abort_unless($this->eligibleRole($role),403,'Role is not delegable.');
                $attached=$target->roles()->where('admin_roles.id',$role->id)->exists();
                abort_if($attached===($d['operation']==='grant'),409,'Role assignment already in requested state.');
            }
            abort_if(DB::table('admin_access_reviews')->where('target_id',$target->id)->where('state','pending')->where('expires_at','>',now())->exists(),409,'Another access review is pending.');
            $id=(string)Str::uuid7();
            DB::table('admin_access_reviews')->insert(['id'=>$id,'target_id'=>$target->id,'requested_by'=>$actor->id,'operation'=>$d['operation'],
                'role_id'=>$role?->id,'role_digest'=>$role?$this->roleDigest($role):null,'target_digest'=>$this->digest($target),
                'reason'=>$d['reason'],'state'=>'pending','expires_at'=>now()->addMinutes(30),'created_at'=>now(),'updated_at'=>now()]);
            $audit->record($actor,'administrators.review.request','review:'.$id,$d['reason'],[],['target'=>$target->id,'operation'=>$d['operation'],'role'=>$role?->id]);
        },3);
        return back()->with('status','Review requested. A different super administrator must approve.');
    }
    public function decide(Request $r,string $review,OperationalAudit $audit){
        $actor=$this->operator($r,true);abort_unless($this->ready(),503);
        $d=$r->validate(['decision'=>'required|in:approve,reject','reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        DB::transaction(function()use($review,$d,$actor,$audit){
            $row=DB::table('admin_access_reviews')->where('id',$review)->lockForUpdate()->first();
            abort_unless($row&&$row->state==='pending'&&now()->lt($row->expires_at),409,'Review expired or already decided.');
            abort_if($row->requested_by===$actor->id||$row->target_id===$actor->id,403,'Independent reviewer required.');
            $requester=AdminUser::find($row->requested_by);abort_unless($requester?->canPerform('*'),403);
            $target=$this->target($row->target_id,$actor);
            abort_unless(hash_equals($row->target_digest,$this->digest($target)),409,'Administrator access changed; request a new review.');
            $role=$row->role_id?AdminRole::whereKey($row->role_id)->lockForUpdate()->firstOrFail():null;
            if($role)abort_unless($this->eligibleRole($role)&&hash_equals($row->role_digest,$this->roleDigest($role)),409,'Role changed; request a new review.');
            $before=['status'=>$target->status,'revision'=>(int)$target->session_revision,'roles'=>$target->roles()->pluck('admin_roles.id')->all()];
            if($d['decision']==='approve'){
                match($row->operation){
                    'grant'=>$target->roles()->syncWithoutDetaching([$role->id]),
                    'revoke'=>$target->roles()->detach($role->id),
                    'suspend'=>$target->status='suspended',
                    'activate'=>$target->status='active',
                    'sign_out'=>null,
                    default=>abort(409)
                };
                // Old authenticated browser sessions cannot return on reactivation.
                $target->session_revision=(int)$target->session_revision+1;$target->save();
            }
            $state=$d['decision']==='approve'?'approved':'rejected';
            DB::table('admin_access_reviews')->where('id',$review)->update(['state'=>$state,'decided_by'=>$actor->id,'updated_at'=>now()]);
            $audit->record($actor,'administrators.review.'.$state,'review:'.$review,$d['reason'],$before,
                ['status'=>$target->status,'revision'=>(int)$target->session_revision,'roles'=>$target->roles()->pluck('admin_roles.id')->all()]);
        },3);
        return back()->with('status','Independent access decision recorded.');
    }
}
