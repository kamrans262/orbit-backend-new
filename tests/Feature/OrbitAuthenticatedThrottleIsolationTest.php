<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OrbitAuthenticatedThrottleIsolationTest extends TestCase
{
    public function test_route_buckets_are_independent_and_stable_across_resource_ids(): void
    {
        $make = function (string $uri, string $path, string $account): Request {
            $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.10']);
            $route = new Route(['POST'], $uri, fn () => null);
            $request->setRouteResolver(fn () => $route);
            $request->attributes->set('orbit_session', (object) ['user_id' => $account]);
            return $request;
        };
        $limiter = RateLimiter::limiter('orbit-route-10');
        $global = RateLimiter::limiter('orbit-authenticated');
        $upload = $make('api/v1/media/uploads', '/api/v1/media/uploads', 'account-a');
        $publish = $make('api/v1/moments', '/api/v1/moments', 'account-a');
        $other = $make('api/v1/media/uploads', '/api/v1/media/uploads', 'account-b');
        $first = $limiter($upload);
        $this->assertSame(10, $first[0]->maxAttempts);
        $this->assertSame(10, $first[1]->maxAttempts);
        $this->assertNotSame($first[0]->key, $limiter($publish)[0]->key);
        $this->assertNotSame($first[1]->key, $limiter($publish)[1]->key);
        $this->assertNotSame($first[0]->key, $limiter($other)[0]->key);
        $this->assertSame($first[1]->key, $limiter($other)[1]->key);
        $one = $make('api/v1/media/uploads/{upload}/complete', '/api/v1/media/uploads/one/complete', 'account-a');
        $two = $make('api/v1/media/uploads/{upload}/complete', '/api/v1/media/uploads/two/complete', 'account-a');
        $this->assertSame($limiter($one)[0]->key, $limiter($two)[0]->key);
        $this->assertSame($global($upload)[0]->key, $global($publish)[0]->key);
        $this->assertSame(600, $global($upload)[0]->maxAttempts);
    }
}
