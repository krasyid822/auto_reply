<?php

namespace Tests\Feature;

use App\Models\InstagramAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::singleton();
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_start_redirects_to_facebook_and_keeps_state(): void
    {
        $this->withSession(['admin_id' => $this->admin->id])
            ->get('/connect')
            ->assertRedirect();

        $this->assertNotEmpty(session('oauth_state'));
    }

    public function test_oauth_callback_redirects_to_page_selection_with_pending_data(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/oauth/access_token*' => function ($request) {
                if (($request['grant_type'] ?? null) === 'fb_exchange_token') {
                    return Http::response(['access_token' => 'long-token', 'token_type' => 'bearer', 'expires_in' => 5184000]);
                }

                return Http::response(['access_token' => 'short-token', 'token_type' => 'bearer', 'expires_in' => 3600]);
            },
            'graph.facebook.com/v26.0/me/accounts*' => Http::response([
                'data' => [
                    ['id' => '100000000000001', 'name' => 'Auto-reply'],
                    ['id' => '100000000000002', 'name' => 'Lumen5'],
                ],
            ]),
            'graph.facebook.com/v26.0/100000000000001*' => Http::response([
                'instagram_business_account' => ['id' => '17841406718308216'],
            ]),
            'graph.facebook.com/v26.0/100000000000002*' => Http::response([]),
            'graph.facebook.com/v26.0/17841406718308216*' => Http::response(['username' => 'rakurn299']),
            'graph.facebook.com/*' => Http::response([]),
        ]);

        $this->withSession(['admin_id' => $this->admin->id, 'oauth_state' => 'state123'])
            ->get('/auth/facebook/callback?code=abcdef&state=state123')
            ->assertRedirect(route('connect.pages'));

        $this->assertSame('long-token', session('oauth.pending_token'));
        $this->assertSame('rakurn299', session('oauth.pending_pages')[0]['ig_username']);
        $this->assertTrue(session('oauth.pending_pages')[0]['has_ig']);
        $this->assertFalse(session('oauth.pending_pages')[1]['has_ig']);
        $this->assertDatabaseCount('instagram_accounts', 0);
    }

    public function test_page_selection_stores_instagram_account_for_selected_page(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/100000000000001*' => Http::response([
                'id' => '100000000000001',
                'name' => 'Auto-reply',
                'instagram_business_account' => ['id' => '17841406718308216'],
            ]),
            'graph.facebook.com/v26.0/17841406718308216*' => Http::response([
                'id' => '17841406718308216',
                'username' => 'rakurn299',
            ]),
        ]);

        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_expires_at' => '2026-10-01 00:00:00',
            'oauth.pending_pages' => [
                [
                    'id' => '100000000000001',
                    'name' => 'Auto-reply',
                    'has_ig' => true,
                    'ig_user_id' => '17841406718308216',
                    'ig_username' => 'rakurn299',
                ],
            ],
        ])->post('/connect/select', ['page_id' => '100000000000001'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('instagram_accounts', [
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'page_id' => '100000000000001',
            'page_name' => 'Auto-reply',
            'token_type' => 'user',
        ]);

        $account = InstagramAccount::first();
        $this->assertSame('long-token', $account->access_token);
        $this->assertSame('2026-10-01 00:00:00', $account->token_expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($account->token_invalid_at);
        $this->assertTrue($account->last_check_ok);
        $this->assertNull(session('oauth.pending_token'));
    }

    public function test_page_selection_requires_page_linked_to_instagram(): void
    {
        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_pages' => [
                [
                    'id' => '100000000000002',
                    'name' => 'Lumen5',
                    'has_ig' => false,
                    'ig_user_id' => null,
                    'ig_username' => null,
                ],
            ],
        ])->post('/connect/select', ['page_id' => '100000000000002'])
            ->assertRedirect(route('connect.pages'))
            ->assertSessionHas('flash-error');

        $this->assertDatabaseCount('instagram_accounts', 0);
        $this->assertSame('long-token', session('oauth.pending_token'));
    }

    public function test_page_selection_requires_pending_session(): void
    {
        $this->withSession(['admin_id' => $this->admin->id])
            ->post('/connect/select', ['page_id' => '100000000000001'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash-error');

        $this->assertDatabaseCount('instagram_accounts', 0);
    }

    public function test_page_selection_without_pending_session_redirects_to_settings(): void
    {
        $this->withSession(['admin_id' => $this->admin->id])
            ->get('/connect/pages')
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash-error');
    }

    public function test_page_selection_lists_pages_with_ig_status(): void
    {
        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_pages' => [
                [
                    'id' => '100000000000001',
                    'name' => 'Auto-reply',
                    'has_ig' => true,
                    'ig_user_id' => '17841406718308216',
                    'ig_username' => 'rakurn299',
                ],
                [
                    'id' => '100000000000002',
                    'name' => 'Lumen5',
                    'has_ig' => false,
                    'ig_user_id' => null,
                    'ig_username' => null,
                ],
            ],
        ])->get('/connect/pages')
            ->assertOk()
            ->assertSee('Auto-reply')
            ->assertSee('IG terhubung')
            ->assertSee('rakurn299')
            ->assertSee('Lumen5')
            ->assertSee('belum ter-link ke akun Instagram');
    }

    public function test_page_selection_with_no_pages_shows_creation_guide(): void
    {
        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_pages' => [],
        ])->get('/connect/pages')
            ->assertOk()
            ->assertSee('Tidak ditemukan halaman Facebook')
            ->assertSee('facebook.com/pages/create');
    }

    public function test_page_selection_when_no_page_has_ig_shows_link_guide(): void
    {
        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_pages' => [
                [
                    'id' => '100000000000002',
                    'name' => 'Lumen5',
                    'has_ig' => false,
                    'ig_user_id' => null,
                    'ig_username' => null,
                ],
            ],
        ])->get('/connect/pages')
            ->assertOk()
            ->assertSee('Panduan: sambungkan Instagram ke Halaman Facebook');
    }

    public function test_page_selection_keeps_existing_connected_accounts(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'old-token',
        ]);

        Http::fake([
            'graph.facebook.com/v26.0/200000000000001*' => Http::response([
                'id' => '200000000000001',
                'name' => 'Toko B',
                'instagram_business_account' => ['id' => '98765432101234'],
            ]),
            'graph.facebook.com/v26.0/98765432101234*' => Http::response([
                'id' => '98765432101234',
                'username' => 'toko_b',
            ]),
        ]);

        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_expires_at' => '2026-10-01 00:00:00',
            'oauth.pending_pages' => [
                [
                    'id' => '200000000000001',
                    'name' => 'Toko B',
                    'has_ig' => true,
                    'ig_user_id' => '98765432101234',
                    'ig_username' => 'toko_b',
                ],
            ],
        ])->post('/connect/select', ['page_id' => '200000000000001'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash');

        $this->assertDatabaseCount('instagram_accounts', 2);
        $this->assertSame('old-token', InstagramAccount::firstWhere('ig_user_id', 17841406718308216)->access_token);
        $this->assertDatabaseHas('instagram_accounts', [
            'ig_user_id' => 98765432101234,
            'username' => 'toko_b',
            'page_id' => '200000000000001',
            'page_name' => 'Toko B',
        ]);
    }

    public function test_disconnect_removes_only_targeted_account(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'old-token',
        ]);
        InstagramAccount::create([
            'ig_user_id' => 98765432101234,
            'username' => 'toko_b',
            'access_token' => 'other-token',
        ]);

        $this->withSession(['admin_id' => $this->admin->id])
            ->post('/connect/disconnect', ['ig_user_id' => 17841406718308216])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash');

        $this->assertDatabaseCount('instagram_accounts', 1);
        $this->assertDatabaseMissing('instagram_accounts', ['ig_user_id' => 17841406718308216]);
        $this->assertSame('other-token', InstagramAccount::firstWhere('ig_user_id', 98765432101234)->access_token);
    }

    public function test_oauth_callback_lists_pages_from_business_portfolio(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/oauth/access_token*' => function ($request) {
                if (($request['grant_type'] ?? null) === 'fb_exchange_token') {
                    return Http::response(['access_token' => 'long-token', 'token_type' => 'bearer', 'expires_in' => 5184000]);
                }

                return Http::response(['access_token' => 'short-token', 'token_type' => 'bearer', 'expires_in' => 3600]);
            },
            'graph.facebook.com/v26.0/me/accounts*' => Http::response([
                'data' => [['id' => '100000000000002', 'name' => 'Lumen5']],
            ]),
            'graph.facebook.com/v26.0/me/businesses*' => Http::response([
                'data' => [['id' => '123456789', 'name' => 'Portofolio Bisnis SAE']],
            ]),
            'graph.facebook.com/v26.0/123456789/owned_pages*' => Http::response([
                'data' => [
                    ['id' => '100000000000001', 'name' => 'Auto-reply', 'instagram_business_account' => ['id' => '17841406718308216']],
                ],
            ]),
            'graph.facebook.com/v26.0/100000000000002*' => Http::response([]),
            'graph.facebook.com/v26.0/17841406718308216*' => Http::response(['username' => 'rakurn299']),
        ]);

        $this->withSession(['admin_id' => $this->admin->id, 'oauth_state' => 'state123'])
            ->get('/auth/facebook/callback?code=abcdef&state=state123')
            ->assertRedirect(route('connect.pages'));

        $this->assertSame('long-token', session('oauth.pending_token'));
        $this->assertSame('Lumen5', session('oauth.pending_pages')[0]['name']);
        $this->assertFalse(session('oauth.pending_pages')[0]['has_ig']);
        $this->assertSame('Auto-reply', session('oauth.pending_pages')[1]['name']);
        $this->assertSame('rakurn299', session('oauth.pending_pages')[1]['ig_username']);
        $this->assertTrue(session('oauth.pending_pages')[1]['has_ig']);
    }

    public function test_page_selection_uses_session_data_when_live_verification_fails(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/100000000000001*' => Http::response([
                'error' => ['message' => '(#200) Permissions error', 'code' => 200],
            ]),
        ]);

        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_expires_at' => '2026-10-01 00:00:00',
            'oauth.pending_pages' => [
                [
                    'id' => '100000000000001',
                    'name' => 'Auto-reply',
                    'has_ig' => true,
                    'ig_user_id' => '17841406718308216',
                    'ig_username' => 'rakurn299',
                ],
            ],
        ])->post('/connect/select', ['page_id' => '100000000000001'])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash');

        $this->assertDatabaseHas('instagram_accounts', [
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'page_id' => '100000000000001',
            'page_name' => 'Auto-reply',
            'token_type' => 'user',
        ]);
    }

    public function test_cancel_clears_pending_connection(): void
    {
        $this->withSession([
            'admin_id' => $this->admin->id,
            'oauth.pending_token' => 'long-token',
            'oauth.pending_pages' => [],
        ])->post('/connect/cancel')
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash');

        $this->assertNull(session('oauth.pending_token'));
        $this->assertDatabaseCount('instagram_accounts', 0);
    }

    public function test_oauth_callback_rejects_mismatched_state(): void
    {
        $this->withSession(['admin_id' => $this->admin->id, 'oauth_state' => 'expected'])
            ->get('/auth/facebook/callback?code=abcdef&state=wrong')
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash-error');

        $this->assertDatabaseCount('instagram_accounts', 0);
    }

    public function test_oauth_callback_handles_declined_permissions(): void
    {
        $this->withSession(['admin_id' => $this->admin->id, 'oauth_state' => 'state123'])
            ->get('/auth/facebook/callback?error=access_denied&error_code=200&error_description=Permissions%20error&state=state123')
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash-error');

        $this->assertDatabaseCount('instagram_accounts', 0);
    }

    public function test_oauth_callback_surfaces_graph_api_error_during_page_listing(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/oauth/access_token*' => Http::response(['access_token' => 'short-token', 'token_type' => 'bearer']),
            'graph.facebook.com/v26.0/me/accounts*' => Http::response([
                'error' => [
                    'message' => '(#100) Page access token not valid',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ]),
            'graph.facebook.com/*' => Http::response([]),
        ]);

        $this->withSession(['admin_id' => $this->admin->id, 'oauth_state' => 'state123'])
            ->get('/auth/facebook/callback?code=abcdef&state=state123')
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('flash-error', fn ($value) => str_contains($value, '(#100) Page access token not valid'));

        $this->assertDatabaseCount('instagram_accounts', 0);
    }
}
