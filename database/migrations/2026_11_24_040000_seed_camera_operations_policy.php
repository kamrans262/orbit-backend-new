<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Modules\Camera\Services\CameraPolicy;

return new class extends Migration {
    public function up():void
    {
        DB::table('remote_configs')->insertOrIgnore([
            'id'=>(string)Str::uuid(),'key'=>CameraPolicy::KEY,
            'description'=>'Camera and editing operational policy',
            'value'=>json_encode(CameraPolicy::DEFAULTS,JSON_THROW_ON_ERROR),
            'schema_json'=>json_encode(CameraPolicy::schema(),JSON_THROW_ON_ERROR),
            'value_type'=>'object','published'=>true,'client_visible'=>false,
            'version'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    public function down():void
    {
        DB::table('remote_configs')->where('key',CameraPolicy::KEY)->delete();
        // Existing audit history is retained.
    }
};
