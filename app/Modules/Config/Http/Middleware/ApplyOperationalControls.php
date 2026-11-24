<?php
namespace App\Modules\Config\Http\Middleware;
use App\Modules\Config\Services\OperationalControls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplyOperationalControls{
    public function handle(Request $request,Closure $next):Response{
        if(!$request->is('api/v1/*','admin','admin/*')||$request->is('admin/assets/*','admin/login','admin/mfa','admin/logout'))return $next($request);
        $overrides=app(OperationalControls::class)->overrides();
        $baseline=[];
        foreach($overrides as $key=>$value)$baseline[$key]=config($key);
        $request->attributes->set('orbit_m24_baseline',$baseline);
        try{config($overrides);return $next($request);}
        finally{config($baseline);$request->attributes->remove('orbit_m24_baseline');}
    }
}
