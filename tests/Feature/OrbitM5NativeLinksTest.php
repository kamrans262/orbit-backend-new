<?php

namespace Tests\Feature;

use Tests\TestCase;

final class OrbitM5NativeLinksTest extends TestCase
{
    public function test_browser_circle_invite_is_read_only_without_leaking_private_circle_data(): void
    {
        $code=str_repeat('A',40);
        $this->get('/invite/circle/'.$code)->assertOk()
            ->assertHeader('Cache-Control','private, no-store')
            ->assertHeader('Referrer-Policy','no-referrer')
            ->assertHeader('X-Robots-Tag','noindex, nofollow')
            ->assertSee($code)
            ->assertDontSee('Emma')
            ->assertDontSee('location_coordinates');
        $this->get('/invite/circle/invalid')->assertNotFound();
        $this->get('/invite/circle/'.str_repeat('A',41))->assertNotFound();
    }

    public function test_associated_domain_covers_both_friend_and_circle_routes_only_when_verified_ids_configured(): void
    {
        config()->set('orbit_social.ios_app_id','ABCDEFGHIJ.com.example.orbit');
        config()->set('orbit_social.android_package','com.example.orbit');
        config()->set('orbit_social.android_sha256',implode(':',array_fill(0,32,'AB')));
        $response=$this->get('/.well-known/apple-app-site-association')->assertOk();
        $paths=array_column($response->json('applinks.details.0.components'),'/');
        $this->assertContains('/invite/friend/*',$paths);
        $this->assertContains('/invite/circle/*',$paths);
        $android=$this->get('/.well-known/assetlinks.json')->assertOk();
        $this->assertSame('com.example.orbit',
            $android->json('0.target.package_name'));
        $this->assertNotEmpty($android->json('0.target.sha256_cert_fingerprints'));
        config()->set('orbit_social.ios_app_id','');
        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    }
}
