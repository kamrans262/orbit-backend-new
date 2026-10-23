<?php
namespace App\Modules\Media\Services;

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Hostinger SHARED hosting adapter. Ciphertext is kept under Laravel
 * storage/app/private, outside public_html. No need for a VPS, S3, R2,
 * symlinks, PHP image decoders, ffmpeg, or exposed webserver upload paths.
 * Never log uploaded data or serve a path without MediaPolicy checks.
 */
final class HostingerPrivateMediaStore extends MediaObjectStore
{
    public function mode(): string { return 'local_chunked'; }
    public function partBytes(): int { return (int)config('orbit_media.hostinger_part_bytes',1048576); }

    private function root(): string
    {
        abort_unless(config('orbit_media.enabled') === true
            && config('orbit_media.driver') === 'hostinger_private',404);
        $root=storage_path('app/private/orbit-media-v1');
        // Refuse to use a symlink outside Laravel's private storage tree.
        foreach([storage_path('app/private'),$root] as $dir) {
            if(is_link($dir)) throw new HttpException(503,'Unsafe media storage.');
            if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) {
                throw new HttpException(503,'Private storage unavailable.');
            }
        }
        return $root;
    }

    private function finalPath(string $key): string
    {
        $hash=hash('sha256',$key);
        return $this->root().'/objects/'.substr($hash,0,2).'/'.$hash.'.bin';
    }

    private function staging(string $id): string
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/D',$id)===1,404);
        return $this->root().'/staging/'.$id;
    }

    private function directory(string $path): void
    {
        if(is_link($path)) throw new HttpException(503,'Invalid media directory.');
        if(!is_dir($path) && !@mkdir($path,0700,true) && !is_dir($path)) {
            throw new HttpException(503,'Private storage unavailable.');
        }
    }

    public function initiate(string $key): string
    {
        $id=bin2hex(random_bytes(16));
        $this->directory($this->staging($id));
        return $id;
    }

    public function signPart(string $key,string $uploadId,int $part,string $shaHex,int $length): array
    {
        throw new HttpException(409,'Use authenticated local media chunk endpoint.');
    }

    public function writePart(string $key,string $uploadId,int $part,string $body,string $checksum): string
    {
        $limit=$this->partBytes();
        abort_unless($part>=1 && $part<=10000 && strlen($body)>0 &&
            strlen($body)<=$limit && preg_match('/^[0-9a-f]{64}$/D',$checksum)===1,422);
        abort_unless(hash_equals($checksum,hash('sha256',$body)),422,'Ciphertext checksum mismatch.');
        $dir=$this->staging($uploadId);
        abort_unless(is_dir($dir) && !is_link($dir),410,'Upload session expired.');
        $target=$dir.'/'.sprintf('%05d',$part).'.part';
        if(is_link($target)) throw new HttpException(409,'Unsafe upload part.');
        $etag='"'.hash('sha256',$body).'"';
        if(is_file($target)) {
            abort_unless(hash_equals($checksum,(string)hash_file('sha256',$target)),409,'Upload part conflict.');
            return $etag;
        }
        $temp=tempnam($dir,'orbit-');
        if($temp===false)throw new HttpException(503,'Private disk unavailable.');
        @chmod($temp,0600);
        try {
            abort_unless(file_put_contents($temp,$body,LOCK_EX)===strlen($body),
                503,'Incomplete disk write.');
            if(!@rename($temp,$target))throw new HttpException(503,'Private storage write failed.');
            @chmod($target,0600);
        } finally {
            if(is_file($temp)) @unlink($temp);
        }
        return $etag;
    }

    public function uploadedParts(string $key,string $uploadId): array
    {
        $dir=$this->staging($uploadId);
        if(!is_dir($dir))return [];
        $out=[];
        foreach(glob($dir.'/*.part')?:[] as $file) {
            $name=basename($file);
            if(!preg_match('/^([0-9]{5})\.part$/D',$name,$match) || is_link($file))continue;
            $hash=hash_file('sha256',$file);
            $out[]=['part_number'=>(int)$match[1],
                'etag'=>'"'.$hash.'"','checksum_sha256'=>base64_encode(hex2bin($hash)),
                'bytes'=>filesize($file)];
        }
        usort($out,fn($a,$b)=>$a['part_number']<=>$b['part_number']);
        return $out;
    }

    public function finish(string $key,string $uploadId,array $parts): void
    {
        $dir=$this->staging($uploadId);
        abort_unless(is_dir($dir),410,'Upload chunks expired.');
        $final=$this->finalPath($key);
        $this->directory(dirname($final));
        // A deterministic final object key avoids duplicate objects on
        // retries; never expose it as a browser-accessible path.
        if(is_file($final))return;
        $temp=tempnam(dirname($final),'orbit-');
        if($temp===false)throw new HttpException(503,'Private storage unavailable.');
        @chmod($temp,0600);
        $out=fopen($temp,'wb');
        if(!$out)throw new HttpException(503,'Cannot assemble ciphertext.');
        try {
            foreach($parts as $part) {
                $src=$dir.'/'.sprintf('%05d',(int)$part['part_number']).'.part';
                abort_unless(is_file($src) && !is_link($src),409,'Missing upload chunk.');
                $stream=fopen($src,'rb');
                if(!$stream)throw new HttpException(503,'Cannot read upload chunk.');
                try { stream_copy_to_stream($stream,$out); }
                finally { fclose($stream); }
            }
            fflush($out);
        } finally { fclose($out); }
        if(!@rename($temp,$final))throw new HttpException(503,'Cannot finalize ciphertext.');
        @chmod($final,0600);
        $this->wipeParts($dir);
    }

    private function wipeParts(string $dir): void
    {
        if(!is_dir($dir) || is_link($dir))return;
        foreach(glob($dir.'/*')?:[] as $path) {
            if(is_file($path) || is_link($path)) @unlink($path);
        }
        @rmdir($dir);
    }

    public function verify(string $key,int $expectedBytes,string $shaHex): bool
    {
        $file=$this->finalPath($key);
        return is_file($file) && !is_link($file)
            && filesize($file)===$expectedBytes
            && hash_equals($shaHex,(string)hash_file('sha256',$file));
    }

    public function localPath(string $key): string
    {
        $file=$this->finalPath($key);
        abort_unless(is_file($file) && !is_link($file),404,'Ciphertext unavailable.');
        return $file;
    }

    public function download(string $key): string
    {
        throw new HttpException(409,'Use authenticated media download endpoint.');
    }

    public function abort(string $key,string $uploadId): void
    {
        $this->wipeParts($this->staging($uploadId));
    }

    public function delete(string $key): void
    {
        $file=$this->finalPath($key);
        if(is_link($file))throw new HttpException(503,'Unsafe media object.');
        if(is_file($file) && !@unlink($file)) {
            throw new HttpException(503,'Private media deletion failed.');
        }
    }
}
