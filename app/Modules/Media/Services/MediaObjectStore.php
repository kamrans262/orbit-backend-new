<?php
namespace App\Modules\Media\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Private R2/S3 transport boundary. Requires the reviewed AWS PHP SDK on the
 * deploy environment. No public disk fallback; no Firebase dependency.
 * Replace this class with a test double for hermetic controller tests.
 */
class MediaObjectStore
{
    public function mode(): string { return 'multipart'; }
    public function partBytes(): int { return (int) config('orbit_media.part_size_bytes'); }
    public function writePart(string $key,string $uploadId,int $part,string $body,string $checksum): string {
        abort(404,'Local media upload disabled.');
    }
    public function localPath(string $key): string { abort(404,'Local media disabled.'); }
    protected function client(): object
    {
        if (config('orbit_media.enabled') !== true) throw new HttpException(404,'Media unavailable.');
        foreach(['bucket','endpoint','access_key','secret_key'] as $item) {
            if (!is_string(config('orbit_media.'.$item)) || config('orbit_media.'.$item) === '') {
                throw new HttpException(503,'Private storage is unavailable.');
            }
        }
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new HttpException(503,'Private storage adapter is not installed.');
        }
        return new \Aws\S3\S3Client([
            'version'=>'latest','region'=>config('orbit_media.region'),
            'endpoint'=>config('orbit_media.endpoint'),'use_path_style_endpoint'=>true,
            'credentials'=>['key'=>config('orbit_media.access_key'),
                            'secret'=>config('orbit_media.secret_key')],
        ]);
    }
    protected function bucket(): string { return (string) config('orbit_media.bucket'); }

    public function initiate(string $key): string
    {
        $c=$this->client();
        $result=$c->createMultipartUpload([
            'Bucket'=>$this->bucket(),'Key'=>$key,
            'ContentType'=>'application/octet-stream',
            'Metadata'=>['orbit-encrypted'=>'v1'],
            'ChecksumAlgorithm'=>'SHA256',
        ]);
        return (string)$result['UploadId'];
    }
    public function signPart(string $key,string $uploadId,int $part,string $shaHex,int $length): array
    {
        $c=$this->client();
        $cmd=$c->getCommand('UploadPart',[
            'Bucket'=>$this->bucket(),'Key'=>$key,'UploadId'=>$uploadId,
            'PartNumber'=>$part,'ContentLength'=>$length,
            'ChecksumSHA256'=>base64_encode(hex2bin($shaHex)),
        ]);
        $request=$c->createPresignedRequest($cmd,'+'.config('orbit_media.upload_url_ttl_seconds').' seconds');
        return [
            'url'=>(string)$request->getUri(),'method'=>'PUT',
            'required_headers'=>[
                'x-amz-checksum-sha256'=>base64_encode(hex2bin($shaHex)),
                'content-length'=>(string)$length,
            ],
            'expires_at'=>now()->addSeconds((int)config('orbit_media.upload_url_ttl_seconds'))->toIso8601String(),
        ];
    }
    public function uploadedParts(string $key,string $uploadId): array
    {
        $c=$this->client(); $list=[]; $marker=0;
        do {
            $response=$c->listParts([
                'Bucket'=>$this->bucket(),'Key'=>$key,'UploadId'=>$uploadId,
                ...($marker>0?['PartNumberMarker'=>$marker]:[]),
            ]);
            foreach($response['Parts']??[] as $p) {
                $marker=(int)$p['PartNumber'];
                $list[]=['part_number'=>$marker,'etag'=>(string)$p['ETag'],
                    'bytes'=>(int)$p['Size'],
                    'checksum_sha256'=>(string)($p['ChecksumSHA256']??'')];
            }
        } while (($response['IsTruncated']??false)===true);
        return $list;
    }
    public function finish(string $key,string $uploadId,array $parts): void
    {
        $c=$this->client();
        $c->completeMultipartUpload([
            'Bucket'=>$this->bucket(),'Key'=>$key,'UploadId'=>$uploadId,
            'MultipartUpload'=>['Parts'=>array_map(fn($p)=>[
                'PartNumber'=>(int)$p['part_number'],'ETag'=>$p['etag'],
                ...(($p['checksum_sha256']??'')!==''?['ChecksumSHA256'=>$p['checksum_sha256']]:[]),
            ],$parts)],
        ]);
    }
    public function verify(string $key,int $expectedBytes,string $shaHex): bool
    {
        $c=$this->client();
        $head=$c->headObject(['Bucket'=>$this->bucket(),'Key'=>$key]);
        if ((int)$head['ContentLength'] !== $expectedBytes) return false;
        $body=$c->getObject(['Bucket'=>$this->bucket(),'Key'=>$key])['Body'];
        $hash=hash_init('sha256'); $bytes=0;
        while (!$body->eof()) {
            $chunk=$body->read(65536);
            if ($chunk==='') break;
            hash_update($hash,$chunk); $bytes+=strlen($chunk);
            if ($bytes>$expectedBytes) return false;
        }
        return $bytes===$expectedBytes && hash_equals($shaHex,hash_final($hash));
    }
    public function download(string $key): string
    {
        $c=$this->client();
        $cmd=$c->getCommand('GetObject',['Bucket'=>$this->bucket(),'Key'=>$key]);
        return (string)$c->createPresignedRequest($cmd,
            '+'.config('orbit_media.url_ttl_seconds').' seconds')->getUri();
    }
    public function abort(string $key,string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket'=>$this->bucket(),'Key'=>$key,'UploadId'=>$uploadId,
        ]);
    }
    public function delete(string $key): void
    {
        $this->client()->deleteObject(['Bucket'=>$this->bucket(),'Key'=>$key]);
    }
}