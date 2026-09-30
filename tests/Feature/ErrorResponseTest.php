<?php

namespace Tests\Feature;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorResponseTest extends TestCase
{
    public function test_client_errors_have_no_debug_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);

        Route::get('/api/_test/forbidden', fn () => abort(403, 'Nope.'));
        Route::get('/api/_test/throttled', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]));

        $this->getJson('/api/_test/forbidden')->assertForbidden()->assertExactJson(['message' => 'Nope.']);
        $this->getJson('/api/_test/throttled')
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('Retry-After', '30');
    }

    public function test_server_errors_do_not_leak_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/_test/boom', fn () => DB::select('select * from table_that_does_not_exist'));

        $response = $this->getJson('/api/_test/boom');

        $response->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('table_that_does_not_exist', $response->getContent());
    }
}
