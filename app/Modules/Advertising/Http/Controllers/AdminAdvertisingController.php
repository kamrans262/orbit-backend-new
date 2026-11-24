<?php

namespace App\Modules\Advertising\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Advertising\Services\AdvertisingPolicy;
use App\Modules\Advertising\Services\AdvertisingCreativeRevisionHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AdminAdvertisingController extends Controller
{
    public function __construct(private readonly AdvertisingPolicy $policy) {}

    private function admin(): \App\Models\AdminUser
    {
        return auth()->guard('admin')->user() ?? abort(403);
    }

    private function ability(string $permission): void
    {
        abort_unless($this->admin()->canPerform($permission),403);
    }

    private function audit(string $action, string $type, string $id, string $reason,
        ?string $before=null, ?string $after=null): void
    {
        DB::table('ad_admin_audits')->insert([
            'id'=>(string)Str::uuid7(),'admin_id'=>$this->admin()->id,
            'action'=>$action,'subject_type'=>$type,'subject_id'=>$id,
            'old_state'=>$before,'new_state'=>$after,
            'reason'=>trim($reason),'created_at'=>now('UTC'),
        ]);
    }

    private function gate(Request $r, array $rules=[]): array
    {
        abort_unless($this->policy->installed(),503,'M20 migration required.');
        return $r->validate(array_merge([
            'reason'=>['required','string','min:10','max:500'],
            'confirm'=>['accepted'],
        ],$rules));
    }

    private function output(string $area)
    {
        $ready=$this->policy->installed();
        $counts=['sponsors'=>0,'verified'=>0,'creatives'=>0,
            'awaiting_review'=>0,'campaigns'=>0,'active'=>0,'impressions'=>0,'clicks'=>0];
        $sponsors=collect(); $creatives=collect(); $campaigns=collect();
        $slots=collect(); $daily=collect(); $audits=collect(); $campaignPlacements=collect();
        $sponsorChoices=collect(); $creativeChoices=collect();
        if ($ready) {
            $counts['sponsors']=DB::table('ad_sponsors')->count();
            $counts['verified']=DB::table('ad_sponsors')->where('status','verified')->count();
            $counts['creatives']=DB::table('ad_creatives')->count();
            $counts['awaiting_review']=DB::table('ad_creatives')->where('status','pending')->count();
            $counts['campaigns']=DB::table('ad_campaigns')->count();
            $counts['active']=DB::table('ad_campaigns')->where('status','active')->count();
            $counts['impressions']=(int)DB::table('ad_daily_aggregates')->sum('impressions');
            $counts['clicks']=(int)DB::table('ad_daily_aggregates')->sum('clicks');
            $states=match($area){
                'sponsors'=>['draft','verified','suspended','archived'],
                'creatives'=>['pending','approved','rejected'],
                'campaigns'=>['draft','pending','approved','rejected','scheduled','active','paused','completed','archived'],
                default=>[],
            };
            $filters=request()->validate([
                'query'=>['nullable','string','max:150'],
                'status'=>['nullable',Rule::in($states)],
                'from'=>['nullable','date_format:Y-m-d'],
                'to'=>['nullable','date_format:Y-m-d',... (request()->filled('from') ? ['after_or_equal:from'] : [])],
                'campaign_id'=>['nullable','uuid'],
            ]);
            $query=trim($filters['query']??'');
            // Bound LIKE input and treat wildcard characters literally.
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
            if ($area==='sponsors') {
                $sponsors=DB::table('ad_sponsors')
                    ->when($query!=='',fn($q)=>$q->where(fn($w)=>$w
                        ->whereRaw("display_name LIKE ? ESCAPE '!'",[$pattern])
                        ->orWhereRaw("legal_name LIKE ? ESCAPE '!'",[$pattern])
                        ->orWhere('id',$query)))
                    ->when($filters['status']??null,fn($q,$v)=>$q->where('status',$v))
                    ->orderBy('display_name')->orderBy('id')->paginate(25)->withQueryString();
            }
            if ($area==='creatives') {
                $creatives=DB::table('ad_creatives as a')
                    ->leftJoin('ad_sponsors as s','s.id','=','a.sponsor_id')
                    ->select('a.*','s.display_name as sponsor_name')
                    ->when($query!=='',fn($q)=>$q->where(fn($w)=>$w
                        ->whereRaw("a.title LIKE ? ESCAPE '!'",[$pattern])
                        ->orWhereRaw("s.display_name LIKE ? ESCAPE '!'",[$pattern])
                        ->orWhere('a.id',$query)))
                    ->when($filters['status']??null,fn($q,$v)=>$q->where('a.status',$v))
                    ->orderByDesc('a.created_at')->orderByDesc('a.id')->paginate(25)->withQueryString();
            }
            if ($area==='campaigns') {
                $campaigns=DB::table('ad_campaigns as c')
                    ->leftJoin('ad_creatives as a','a.id','=','c.creative_id')
                    ->leftJoin('ad_sponsors as s','s.id','=','c.sponsor_id')
                    ->select('c.*','a.title as creative_title','s.display_name as sponsor_name')
                    ->when($query!=='',fn($q)=>$q->where(fn($w)=>$w
                        ->whereRaw("c.name LIKE ? ESCAPE '!'",[$pattern])
                        ->orWhereRaw("s.display_name LIKE ? ESCAPE '!'",[$pattern])
                        ->orWhere('c.id',$query)))
                    ->when($filters['status']??null,fn($q,$v)=>$q->where('c.status',$v))
                    ->orderByDesc('c.created_at')->orderByDesc('c.id')->paginate(25)->withQueryString();
                $campaignPlacements=DB::table('ad_campaign_placements')
                    ->whereIn('campaign_id',$campaigns->pluck('id')->all())
                    ->get(['campaign_id','placement_key'])->groupBy('campaign_id');
            }
            // Suggestions are bounded conveniences, not eligibility limits:
            // full IDs from the paginated directories can always be entered.
            if (in_array($area,['creatives','campaigns'],true) && $this->admin()->canPerform('advertising.manage')) {
                $sponsorChoices=DB::table('ad_sponsors')->where('status','!=','archived')
                    ->orderBy('display_name')->orderBy('id')->limit(50)->get(['id','display_name']);
            }
            if ($area==='campaigns' && $this->admin()->canPerform('advertising.manage')) {
                $creativeChoices=DB::table('ad_creatives')->where('status','approved')
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get(['id','title','sponsor_id']);
            }
            if ($area==='placements') $slots=DB::table('ad_placements')->orderBy('key')->get();
            if ($area==='analytics') $daily=DB::table('ad_daily_aggregates')
                ->when($filters['from']??null,fn($q,$v)=>$q->where('day_utc','>=',$v))
                ->when($filters['to']??null,fn($q,$v)=>$q->where('day_utc','<=',$v))
                ->when($filters['campaign_id']??null,fn($q,$v)=>$q->where('campaign_id',$v))
                ->orderByDesc('day_utc')->orderBy('campaign_id')->orderBy('placement_key')
                ->paginate(25)->withQueryString();
            if ($area==='audits') $audits=DB::table('ad_admin_audits')
                ->when($filters['from']??null,fn($q,$v)=>$q->where('created_at','>=',$v.' 00:00:00'))
                ->when($filters['to']??null,fn($q,$v)=>$q->where('created_at','<',\Illuminate\Support\Carbon::parse($v,'UTC')->addDay()))
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        }
        return response()->view('orbit-admin.advertising',
            compact('area','ready','counts','sponsors','creatives','campaigns',
                'slots','daily','audits','campaignPlacements','sponsorChoices','creativeChoices'))->header('Cache-Control','private, no-store');
    }

    public function overview() { return $this->output('overview'); }
    public function sponsors() { return $this->output('sponsors'); }
    public function creatives() { return $this->output('creatives'); }
    public function campaigns() { return $this->output('campaigns'); }
    public function placements() { return $this->output('placements'); }
    public function analytics() { $this->ability('advertising.analytics'); return $this->output('analytics'); }
    public function audits() { $this->ability('advertising.audit'); return $this->output('audits'); }

    private function checkDomain(string $domain): string
    {
        $domain=strtolower(trim($domain));
        abort_unless(preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D',$domain)
            && filter_var($domain,FILTER_VALIDATE_IP)===false
            && !str_contains($domain,'..'),422,'Invalid sponsor domain.');
        return $domain;
    }

    public function createSponsor(Request $r)
    {
        $this->ability('advertising.manage');
        $data=$this->gate($r,[
            'display_name'=>['required','string','min:2','max:150'],
            'legal_name'=>['required','string','min:2','max:180'],
            'approved_domain'=>['required','string','max:190'],
            'contact'=>['nullable','email','max:190'],
        ]);
        $domain=$this->checkDomain($data['approved_domain']);
        DB::transaction(function () use($data,$domain): void {
            $id=(string)Str::uuid7();
            DB::table('ad_sponsors')->insert([
                'id'=>$id,'display_name'=>$data['display_name'],
                'legal_name'=>$data['legal_name'],'approved_domain'=>$domain,
                'status'=>'draft','verification_status'=>'pending',
                'created_by'=>$this->admin()->id,
                'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
            ]);
            if (!empty($data['contact'])) DB::table('ad_sponsor_contacts')->insert([
                'id'=>(string)Str::uuid7(),'sponsor_id'=>$id,
                'contact_kind'=>'email',
                'encrypted_contact'=>Crypt::encryptString($data['contact']),
                'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
            ]);
            $this->audit('sponsor.created','sponsor',$id,$data['reason'],null,'draft');
        },3);
        return back()->with('status','Sponsor draft created; not eligible for serving.');
    }

    public function editSponsor(Request $r,string $sponsor)
    {
        $this->ability('advertising.manage');
        $data=$this->gate($r,[
            'display_name'=>['required','string','min:2','max:150'],
            'legal_name'=>['required','string','min:2','max:180'],
            'approved_domain'=>['required','string','max:190'],
            'contact'=>['nullable','email','max:190'],
        ]);
        $domain=$this->checkDomain($data['approved_domain']);
        DB::transaction(function () use($sponsor,$data,$domain):void {
            $row=DB::table('ad_sponsors')->where('id',$sponsor)->lockForUpdate()->first();
            abort_unless($row && $row->status!=='archived',409);
            $changeDomain=$row->approved_domain!==$domain;
            DB::table('ad_sponsors')->where('id',$sponsor)->update([
                'display_name'=>$data['display_name'],
                'legal_name'=>$data['legal_name'],'approved_domain'=>$domain,
                'status'=>$changeDomain?'draft':$row->status,
                'verification_status'=>$changeDomain?'pending':$row->verification_status,
                'verified_by'=>$changeDomain?null:$row->verified_by,
                'verified_at'=>$changeDomain?null:$row->verified_at,
                'updated_at'=>now('UTC'),
            ]);
            if (!empty($data['contact'])) DB::table('ad_sponsor_contacts')->insert([
                'id'=>(string)Str::uuid7(),'sponsor_id'=>$sponsor,
                'contact_kind'=>'email',
                'encrypted_contact'=>Crypt::encryptString($data['contact']),
                'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
            ]);
            $this->audit('sponsor.edited','sponsor',$sponsor,$data['reason'],
                $row->status,$changeDomain?'draft':$row->status);
        },3);
        return back()->with('status','Sponsor details saved. Destination changes require new independent verification.');
    }

    public function sponsorStatus(Request $r,string $sponsor)
    {
        $this->ability('advertising.manage');
        $data=$this->gate($r,['status'=>['required',Rule::in(['verified','suspended','archived'])]]);
        DB::transaction(function () use($sponsor,$data): void {
            $row=DB::table('ad_sponsors')->where('id',$sponsor)->lockForUpdate()->first();
            abort_unless($row,404);
            $to=$data['status'];
            abort_unless($row->status!=='archived' && $row->status!==$to,409,'Invalid sponsor transition.');
            if ($to==='verified') {
                abort_unless($this->admin()->id!==$row->created_by,
                    403,'An independent operator must verify this sponsor.');
                $this->checkDomain($row->approved_domain);
            }
            DB::table('ad_sponsors')->where('id',$sponsor)->update([
                'status'=>$to,
                'verification_status'=>$to==='verified'?'verified':'revoked',
                'verified_by'=>$to==='verified'?$this->admin()->id:$row->verified_by,
                'verified_at'=>$to==='verified'?now('UTC'):$row->verified_at,
                'archived_at'=>$to==='archived'?now('UTC'):$row->archived_at,
                'updated_at'=>now('UTC'),
            ]);
            $this->audit('sponsor.transition','sponsor',$sponsor,
                $data['reason'],$row->status,$to);
        },3);
        return back()->with('status','Sponsor state updated and audited.');
    }

    private function validatedCreative(Request $r): array
    {
        return $this->gate($r,[
            'sponsor_id'=>['required','uuid','exists:ad_sponsors,id'],
            'title'=>['required','string','min:2','max:120'],
            'subtitle'=>['required','string','max:220'],
            'cta_url'=>['required','string','max:500','url:https'],
            'asset'=>['required','file','max:'.((int)config('orbit_advertising.asset_max_bytes')/1024)],
        ]);
    }

    private function saveAsset(Request $r): array
    {
        $file=$r->file('asset');
        abort_unless($file && $file->isValid(),422,'Invalid image.');
        $size=$file->getSize();
        $mime=$file->getMimeType();
        abort_unless($size && $size <= (int)config('orbit_advertising.asset_max_bytes')
            && in_array($mime,config('orbit_advertising.image_mimes',[]),true),
            422,'Unsupported creative asset.');
        $info=@getimagesize($file->getRealPath());
        abort_unless(is_array($info)
            && in_array($info['mime']??'', config('orbit_advertising.image_mimes',[]),true)
            && ($info['mime']??'')===$mime,422,'Image content mismatch.');
        [$width,$height]=$info;
        abort_unless($width >= config('orbit_advertising.min_width')
            && $height >= config('orbit_advertising.min_height')
            && $width <= config('orbit_advertising.max_width')
            && $height <= config('orbit_advertising.max_height'),422,
            'Image dimensions are outside the allowed range.');
        // Re-encode approved raster formats rather than copying originals.
        // This strips EXIF/GPS, text chunks, comments and embedded trailers
        // from uploaded advertising artwork before private persistence.
        $source=match ($mime) {
            'image/jpeg'=>@imagecreatefromjpeg($file->getRealPath()),
            'image/png'=>@imagecreatefrompng($file->getRealPath()),
            'image/webp'=>@imagecreatefromwebp($file->getRealPath()),
        };
        abort_unless($source instanceof \GdImage,422,'Image decoder rejected creative.');
        $temp=tempnam(sys_get_temp_dir(),'orbit-ad-');
        if ($temp === false) {
            imagedestroy($source);
            abort(503,'Creative sanitization unavailable.');
        }
        try {
            if ($mime==='image/png' || $mime==='image/webp') {
                imagealphablending($source,false);
                imagesavealpha($source,true);
            }
            $encoded=match ($mime) {
                'image/jpeg'=>imagejpeg($source,$temp,88),
                'image/png'=>imagepng($source,$temp,6),
                'image/webp'=>imagewebp($source,$temp,86),
            };
            abort_unless($encoded,422,'Creative image encoding failed.');
            $content=file_get_contents($temp);
            abort_unless(is_string($content)&&strlen($content)>0
                && strlen($content)<=(int)config('orbit_advertising.asset_max_bytes'),
                422,'Sanitized image exceeds safe limits.');
            $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
            $name=(string)Str::uuid7().'.'.$extension;
            $path='advertising/creatives/'.$name;
            abort_unless(Storage::disk('local')->put($path,$content),
                503,'Private asset storage unavailable.');
            return [$path,hash('sha256',$content),$mime,$width,$height];
        } finally {
            imagedestroy($source);
            @unlink($temp);
        }
    }

    public function uploadCreative(Request $r)
    {
        $this->ability('advertising.manage');
        $data=$this->validatedCreative($r);
        $sponsor=DB::table('ad_sponsors')->where('id',$data['sponsor_id'])->first();
        abort_unless($sponsor && $sponsor->status!=='archived',409);
        abort_unless($this->policy->destinationIsSafe($data['cta_url'],$sponsor->approved_domain),
            422,'CTA must be HTTPS on the sponsor approved domain.');
        [$path,$hash,$mime,$width,$height]=$this->saveAsset($r);
        try {
            DB::transaction(function () use($data,$path,$hash,$mime,$width,$height): void {
                $id=(string)Str::uuid7();
                DB::table('ad_creatives')->insert([
                    'id'=>$id,'sponsor_id'=>$data['sponsor_id'],
                    'revision'=>1,'title'=>$data['title'],'subtitle'=>$data['subtitle'],
                    'private_path'=>$path,'sha256'=>$hash,'mime'=>$mime,
                    'width'=>$width,'height'=>$height,'cta_url'=>$data['cta_url'],
                    'status'=>'pending','submitted_by'=>$this->admin()->id,
                    'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
                ]);
                app(AdvertisingCreativeRevisionHistory::class)->capture($id);
                $this->audit('creative.submitted','creative',$id,$data['reason'],null,'pending:1');
            },3);
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return back()->with('status','Creative uploaded privately; awaiting independent review.');
    }

    public function reviseCreative(Request $r,string $creative)
    {
        $this->ability('advertising.manage');
        $data=$this->validatedCreative($r);
        $sponsor=DB::table('ad_sponsors')->where('id',$data['sponsor_id'])->first();
        abort_unless($sponsor && $sponsor->status!=='archived'
            && $this->policy->destinationIsSafe($data['cta_url'],$sponsor->approved_domain),422);
        [$path,$hash,$mime,$width,$height]=$this->saveAsset($r);
        try {
            DB::transaction(function () use($creative,$data,$path,$hash,$mime,$width,$height):void {
                $old=DB::table('ad_creatives')->where('id',$creative)->lockForUpdate()->first();
                abort_unless($old && $old->sponsor_id===$data['sponsor_id'],409);
                $revision=$old->revision+1;
                DB::table('ad_creatives')->where('id',$creative)->update([
                    'revision'=>$revision,'title'=>$data['title'],
                    'subtitle'=>$data['subtitle'],'private_path'=>$path,
                    'sha256'=>$hash,'mime'=>$mime,'width'=>$width,'height'=>$height,
                    'cta_url'=>$data['cta_url'],'status'=>'pending',
                    'submitted_by'=>$this->admin()->id,
                    'reviewed_by'=>null,'reviewed_at'=>null,'rejection_reason'=>null,
                    'updated_at'=>now('UTC'),
                ]);
                app(AdvertisingCreativeRevisionHistory::class)->capture($creative);
                $this->audit('creative.revised','creative',$creative,$data['reason'],
                    $old->status.':'.$old->revision,'pending:'.$revision);
            },3);
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return back()->with('status','Creative revision pending review; prior campaign approval no longer serves.');
    }

    public function reviewCreative(Request $r,string $creative)
    {
        $this->ability('advertising.review');
        $data=$this->gate($r,[
            'revision'=>['required','integer','min:1'],
            'action'=>['required',Rule::in(['approved','rejected'])],
        ]);
        DB::transaction(function () use($creative,$data):void {
            $row=DB::table('ad_creatives')->where('id',$creative)->lockForUpdate()->first();
            abort_unless($row && $row->status==='pending'
                && (int)$row->revision===(int)$data['revision'],409,
                'Stale creative revision.');
            abort_unless($row->submitted_by!==$this->admin()->id,
                403,'Creator cannot approve their own creative.');
            DB::table('ad_creatives')->where('id',$creative)->update([
                'status'=>$data['action'],'reviewed_by'=>$this->admin()->id,
                'reviewed_at'=>now('UTC'),
                'rejection_reason'=>$data['action']==='rejected'?$data['reason']:null,
                'updated_at'=>now('UTC'),
            ]);
            app(AdvertisingCreativeRevisionHistory::class)->review(
                $creative,(int)$row->revision,$data['action'],$this->admin()->id,
                $data['action']==='rejected'?$data['reason']:null);
            $this->audit('creative.review','creative',$creative,$data['reason'],
                'pending:'.$row->revision,$data['action'].':'.$row->revision);
        },3);
        return back()->with('status','Creative review saved and audited.');
    }

    public function previewCreative(string $creative)
    {
        $this->ability('advertising.review');
        abort_unless($this->policy->installed(),503);
        $row=DB::table('ad_creatives')->where('id',$creative)->first();
        abort_unless($row && Storage::disk('local')->exists($row->private_path),404);
        $response=response()->file(Storage::disk('local')->path($row->private_path),[
            'Content-Type'=>$row->mime,'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'none'; sandbox",
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control','private, no-store, max-age=0');
        return $response;
    }

    public function createCampaign(Request $r)
    {
        $this->ability('advertising.manage');
        $data=$this->gate($r,[
            'name'=>['required','string','max:120'],
            'sponsor_id'=>['required','uuid','exists:ad_sponsors,id'],
            'creative_id'=>['required','uuid','exists:ad_creatives,id'],
            'starts_at'=>['required','date'],
            'ends_at'=>['required','date','after:starts_at'],
            'priority'=>['required','integer','min:1','max:10'],
            'placements'=>['required','array','min:1','max:2'],
            'placements.*'=>['required',Rule::in(config('orbit_advertising.slots',[]))],
        ]);
        DB::transaction(function () use($data):void {
            $creative=DB::table('ad_creatives')->where('id',$data['creative_id'])->first();
            abort_unless($creative && $creative->sponsor_id===$data['sponsor_id']
                && $creative->status==='approved',409,'Approved creative required.');
            $id=(string)Str::uuid7();
            DB::table('ad_campaigns')->insert([
                'id'=>$id,'name'=>$data['name'],'sponsor_id'=>$data['sponsor_id'],
                'creative_id'=>$data['creative_id'],'creative_revision'=>$creative->revision,
                'revision'=>1,'status'=>'draft',
                'starts_at'=>$data['starts_at'],'ends_at'=>$data['ends_at'],
                'priority'=>$data['priority'],'created_by'=>$this->admin()->id,
                'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
            ]);
            foreach (array_unique($data['placements']) as $slot)
                DB::table('ad_campaign_placements')->insert([
                    'campaign_id'=>$id,'placement_key'=>$slot,'created_at'=>now('UTC'),
                ]);
            $this->audit('campaign.created','campaign',$id,$data['reason'],null,'draft:1');
        },3);
        return back()->with('status','Draft campaign created. Nothing is live.');
    }

    public function editCampaign(Request $r,string $campaign)
    {
        $this->ability('advertising.manage');
        $data=$this->gate($r,[
            'revision'=>['required','integer','min:1'],
            'name'=>['required','string','max:120'],
            'sponsor_id'=>['required','uuid','exists:ad_sponsors,id'],
            'creative_id'=>['required','uuid','exists:ad_creatives,id'],
            'starts_at'=>['required','date'],
            'ends_at'=>['required','date','after:starts_at'],
            'priority'=>['required','integer','min:1','max:10'],
            'placements'=>['required','array','min:1','max:2'],
            'placements.*'=>['required',Rule::in(config('orbit_advertising.slots',[]))],
        ]);
        DB::transaction(function () use($campaign,$data):void {
            $old=DB::table('ad_campaigns')->where('id',$campaign)->lockForUpdate()->first();
            abort_unless($old && $old->status!=='archived'
                && (int)$old->revision===(int)$data['revision'],409,
                'Stale or archived campaign.');
            $creative=DB::table('ad_creatives')->where('id',$data['creative_id'])->first();
            abort_unless($creative && $creative->sponsor_id===$data['sponsor_id']
                && $creative->status==='approved',409,'Approved matching creative required.');
            DB::table('ad_campaigns')->where('id',$campaign)->update([
                'name'=>$data['name'],'sponsor_id'=>$data['sponsor_id'],
                'creative_id'=>$data['creative_id'],'creative_revision'=>$creative->revision,
                'starts_at'=>$data['starts_at'],'ends_at'=>$data['ends_at'],
                'priority'=>$data['priority'],'status'=>'draft',
                'revision'=>$old->revision+1,
                // The last editor may never self-approve the revised campaign.
                'created_by'=>$this->admin()->id,
                'reviewed_by'=>null,'reviewed_at'=>null,'updated_at'=>now('UTC'),
            ]);
            DB::table('ad_campaign_placements')->where('campaign_id',$campaign)->delete();
            foreach(array_unique($data['placements']) as $slot)
                DB::table('ad_campaign_placements')->insert([
                    'campaign_id'=>$campaign,'placement_key'=>$slot,'created_at'=>now('UTC'),
                ]);
            $this->audit('campaign.edited','campaign',$campaign,$data['reason'],
                $old->status.':'.$old->revision,'draft:'.($old->revision+1));
        },3);
        return back()->with('status','Revised campaign returned to draft; approval was invalidated.');
    }

    public function campaignTransition(Request $r,string $campaign)
    {
        abort_unless($this->admin()->canPerform('advertising.review') || $this->admin()->canPerform('advertising.manage'),403);
        $data=$this->gate($r,[
            'revision'=>['required','integer','min:1'],
            'status'=>['required',Rule::in(['draft','pending','approved','scheduled',
                'active','paused','completed','rejected','archived'])],
        ]);
        $to=$data['status'];
        $this->ability(in_array($to,['approved','rejected'],true)
            ?'advertising.review':'advertising.manage');
        DB::transaction(function () use($campaign,$data,$to):void {
            $row=DB::table('ad_campaigns')->where('id',$campaign)->lockForUpdate()->first();
            abort_unless($row && (int)$row->revision===(int)$data['revision'],
                409,'Stale campaign revision.');
            $allowed=[
                'draft'=>['pending','archived'],
                'pending'=>['draft','approved','rejected','archived'],
                'approved'=>['scheduled','active','archived'],
                'scheduled'=>['active','paused','completed','archived'],
                'active'=>['paused','completed'],
                'paused'=>['scheduled','active','completed','archived'],
                'rejected'=>['draft','archived'],
                'completed'=>['archived'],
                'archived'=>[],
            ];
            abort_unless(in_array($to,$allowed[$row->status]??[],true),409,
                'Invalid campaign transition.');
            if ($to==='approved') abort_unless(
                $row->created_by!==$this->admin()->id,403,
                'Campaign creator cannot approve their own campaign.');
            if (in_array($to,['pending','approved','scheduled','active'],true)) {
                $creative=DB::table('ad_creatives')->where('id',$row->creative_id)->first();
                $sponsor=DB::table('ad_sponsors')->where('id',$row->sponsor_id)->first();
                abort_unless($creative && $sponsor
                    && $creative->status==='approved'
                    && (int)$creative->revision===(int)$row->creative_revision
                    && $sponsor->status==='verified'
                    && $this->policy->destinationIsSafe($creative->cta_url,$sponsor->approved_domain),
                    409,'Sponsor and creative verification required.');
            }
            DB::table('ad_campaigns')->where('id',$campaign)->update([
                'status'=>$to,'revision'=>$row->revision+1,
                'reviewed_by'=>$to==='approved'?$this->admin()->id:$row->reviewed_by,
                'reviewed_at'=>$to==='approved'?now('UTC'):$row->reviewed_at,
                'updated_at'=>now('UTC'),
            ]);
            $this->audit('campaign.transition','campaign',$campaign,$data['reason'],
                $row->status.':'.$row->revision,$to.':'.($row->revision+1));
        },3);
        return back()->with('status','Campaign transition saved and audited.');
    }

    public function placementControl(Request $r,string $placement)
    {
        $this->ability('advertising.manage');
        abort_unless(in_array($placement,config('orbit_advertising.slots',[]),true),404);
        $data=$this->gate($r,[
            'enabled'=>['required','boolean'],
            'daily_decision_cap'=>['nullable','integer','min:1',
                'max:'.config('orbit_advertising.max_cap_per_day',20)],
            'daily_impression_cap'=>['nullable','integer','min:1',
                'max:'.config('orbit_advertising.max_cap_per_day',20)],
            'rule_version'=>['required','integer','min:1'],
        ]);
        if ((bool)$data['enabled']) abort_unless(
            isset($data['daily_decision_cap'],$data['daily_impression_cap']),422,
            'Explicit approved caps are required before enablement.');
        DB::transaction(function () use($placement,$data):void {
            $row=DB::table('ad_placements')->where('key',$placement)->lockForUpdate()->first();
            abort_unless($row && (int)$row->rule_version===(int)$data['rule_version'],
                409,'Stale placement rule.');
            $enabled=(bool)$data['enabled'];
            DB::table('ad_placements')->where('key',$placement)->update([
                'enabled'=>$enabled,
                'daily_decision_cap'=>$data['daily_decision_cap']??null,
                'daily_impression_cap'=>$data['daily_impression_cap']??null,
                'rule_version'=>$row->rule_version+1,
                'updated_by'=>$this->admin()->id,'updated_at'=>now('UTC'),
            ]);
            $this->audit('placement.updated','placement',$placement,$data['reason'],
                ($row->enabled?'enabled':'disabled').':'.$row->rule_version,
                ($enabled?'enabled':'disabled').':'.($row->rule_version+1));
        },3);
        return back()->with('status','Placement control saved. Environment policy gates remain authoritative.');
    }
}
