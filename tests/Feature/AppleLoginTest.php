<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppleLoginTest extends TestCase
{
    public function test_apple_login_requires_identity_token_and_nonce(): void
    {
        $this->postJson('/api/v1/mobile/apple-login')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['identity_token', 'raw_nonce']);
    }
}
