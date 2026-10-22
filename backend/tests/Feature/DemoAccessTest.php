<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\Concerns\RefreshContexts;
use Tests\TestCase;

final class DemoAccessTest extends TestCase
{
    use RefreshContexts;

    public function test_the_public_demo_login_has_one_button_and_no_password_prompt(): void
    {
        config(['demo.enabled' => true]);
        $this->get('/login')->assertOk()->assertSee('View demo')->assertDontSee('type="password"', false);
    }

    public function test_one_click_sign_in_uses_only_the_seeded_demo_account(): void
    {
        config(['demo.enabled' => true]);
        $demo = User::factory()->create(['email' => 'ops@fellwise.co.uk']);
        $other = User::factory()->create(['email' => 'someone@fellwise.co.uk']);
        $this->post('/demo/sign-in', ['email' => $other->email])->assertRedirect('/');
        $this->assertAuthenticatedAs($demo);
    }

    public function test_the_demo_entry_is_disabled_outside_the_public_demo(): void
    {
        config(['demo.enabled' => false]);
        $this->post('/demo/sign-in')->assertNotFound();
        $this->assertGuest();
        $this->get('/login')->assertOk()->assertSee('type="password"', false);
    }
}
