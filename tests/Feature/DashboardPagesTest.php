<?php

namespace Tests\Feature;

use App\Jobs\ReplyToCommentJob;
use App\Models\AutoReplyRule;
use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardPagesTest extends TestCase
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

    protected function withAdminSession(): static
    {
        return $this->withSession([
            'admin_id' => $this->admin->id,
            'admin_name' => 'Admin',
        ]);
    }

    public function test_home_redirects_without_session(): void
    {
        $this->get('/')->assertRedirect('/profil');
    }

    public function test_dashboard_renders(): void
    {
        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'text' => 'berapa harga?',
            'username' => 'user_baru',
            'status' => Comment::STATUS_REPLIED,
            'reply_text' => 'Silakan DM!',
        ]);

        $this->withAdminSession()
            ->get('/')
            ->assertOk()
            ->assertSee('Beranda')
            ->assertSee('berapa harga?')
            ->assertSee('Dibalas');
    }

    public function test_rules_crud_and_toggle(): void
    {
        $this->withAdminSession()
            ->get('/rules')
            ->assertOk()
            ->assertSee('Aturan Balasan');

        $rule = AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM!', 'is_active' => true]);

        $this->withAdminSession()
            ->post('/rules', ['keyword' => 'lokasi', 'reply_text' => 'Kami di Jakarta.'])
            ->assertRedirect('/rules');

        $this->assertDatabaseHas('auto_reply_rules', ['keyword' => 'lokasi', 'is_active' => true]);

        $this->withAdminSession()
            ->post(route('rules.toggle', $rule))
            ->assertRedirect();

        $this->assertDatabaseHas('auto_reply_rules', ['id' => $rule->id, 'is_active' => false]);

        $this->withAdminSession()
            ->post(route('rules.update', $rule), ['keyword' => 'PM', 'reply_text' => 'DM saja.', 'is_active' => 1])
            ->assertRedirect('/rules');

        $this->assertDatabaseHas('auto_reply_rules', ['id' => $rule->id, 'keyword' => 'PM']);

        $this->withAdminSession()
            ->delete(route('rules.destroy', $rule))
            ->assertRedirect('/rules');

        $this->assertDatabaseMissing('auto_reply_rules', ['id' => $rule->id]);
    }

    public function test_logs_list_filter_and_filtered_view(): void
    {
        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'text' => 'berapa harga?',
            'username' => 'user_baru',
            'status' => Comment::STATUS_FAILED,
            'reply_text' => 'Silakan DM!',
            'error' => 'rate limit',
        ]);

        $this->withAdminSession()
            ->get('/logs')
            ->assertOk()
            ->assertSee('berapa harga?');

        $this->withAdminSession()
            ->get('/logs?status=failed')
            ->assertOk()
            ->assertSee('berapa harga?');

        $this->withAdminSession()
            ->get('/logs?status=replied')
            ->assertOk()
            ->assertDontSee('berapa harga?');
    }

    public function test_logs_groups_comments_by_media(): void
    {
        $mediaOne = '18077184818352540';
        $mediaTwo = '18077184818352541';

        Comment::create(['comment_id' => '101', 'media_id' => $mediaOne, 'text' => 'harga', 'username' => 'ace', 'status' => Comment::STATUS_REPLIED]);
        Comment::create(['comment_id' => '102', 'media_id' => $mediaOne, 'text' => 'stok?', 'username' => 'joko', 'status' => Comment::STATUS_PENDING]);
        Comment::create(['comment_id' => '103', 'media_id' => $mediaTwo, 'text' => 'minat', 'username' => 'budi', 'status' => Comment::STATUS_REPLIED]);

        $html = $this->withAdminSession()->get('/logs')->assertOk()->getContent();

        $this->assertStringContainsString('Postingan '.$mediaOne, $html);
        $this->assertStringContainsString('Postingan '.$mediaTwo, $html);
        $this->assertStringContainsString('2 komentar', $html);
        $this->assertSame(2, substr_count($html, 'Postingan '));
    }

    public function test_logs_media_preview_returns_oembed_html_when_url_known(): void
    {
        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'media_url' => 'https://www.instagram.com/p/DWUsLU9EqZM/',
            'text' => 'harga',
            'username' => 'ace',
            'status' => Comment::STATUS_REPLIED,
        ]);

        Http::fake([
            'graph.facebook.com/*/instagram_oembed*' => Http::response([
                'version' => '1.0',
                'type' => 'rich',
                'width' => 658,
                'html' => '<blockquote class="instagram-media">...</blockquote>',
            ]),
        ]);

        $this->withAdminSession()
            ->get('/logs/media-preview?media_id=18077184818352540')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media_url', 'https://www.instagram.com/p/DWUsLU9EqZM/')
            ->assertJsonPath('html', '<blockquote class="instagram-media">...</blockquote>');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/instagram_oembed')
            && $request['url'] === 'https://www.instagram.com/p/DWUsLU9EqZM/');
    }

    public function test_logs_media_preview_resolves_permalink_via_graph_when_missing(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);

        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'ig_user_id' => $account->ig_user_id,
            'text' => 'harga',
            'username' => 'ace',
            'status' => Comment::STATUS_REPLIED,
        ]);

        Http::fake([
            'graph.facebook.com/*/18077184818352540*' => Http::response([
                'permalink' => 'https://www.instagram.com/p/DWUsLU9EqZM/',
            ]),
            'graph.facebook.com/*/instagram_oembed*' => Http::response([
                'version' => '1.0',
                'type' => 'rich',
                'html' => '<blockquote>embed</blockquote>',
            ]),
        ]);

        $this->withAdminSession()
            ->get('/logs/media-preview?media_id=18077184818352540')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media_url', 'https://www.instagram.com/p/DWUsLU9EqZM/');

        $this->assertDatabaseHas('comments', [
            'comment_id' => '101',
            'media_url' => 'https://www.instagram.com/p/DWUsLU9EqZM/',
        ]);
    }

    public function test_logs_media_preview_reports_missing_url(): void
    {
        Comment::create([
            'comment_id' => '101',
            'media_id' => '9999',
            'text' => 'harga',
            'username' => 'ace',
            'status' => Comment::STATUS_REPLIED,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $this->withAdminSession()
            ->get('/logs/media-preview?media_id=9999')
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    public function test_logs_media_preview_rejects_invalid_media_id(): void
    {
        $this->withAdminSession()
            ->get('/logs/media-preview?media_id=abc')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_settings_update_and_app_lock(): void
    {
        $this->withAdminSession()
            ->get('/settings')
            ->assertOk()
            ->assertSee('Pengaturan')
            ->assertSee('Hubungkan dengan Facebook');

        $this->withAdminSession()
            ->post('/settings', [
                'bot_enabled' => 1,
                'reply_to_own_comments' => 1,
                'poll_interval_minutes' => 7,
                'max_media_per_cycle' => 12,
            ])
            ->assertRedirect();

        $setting = Setting::singleton();
        $this->assertTrue($setting->bot_enabled);
        $this->assertTrue($setting->reply_to_own_comments);
        $this->assertSame(7, $setting->poll_interval_minutes);
        $this->assertSame(12, $setting->max_media_per_cycle);

        $this->withAdminSession()
            ->post('/settings/app-lock', [
                'enabled' => 1,
                'pin' => '1234',
                'timeout_minutes' => 5,
            ])
            ->assertRedirect();

        $this->assertTrue($this->admin->appLock->enabled);
        $this->assertTrue($this->admin->appLock->verifyPin('1234'));
    }

    public function test_dashboard_shows_remaining_api_quota(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
            'api_calls_count' => 5,
            'api_calls_window_start' => now(),
        ]);

        $this->withAdminSession()
            ->get('/')
            ->assertOk()
            ->assertSee('Sisa kuota')
            ->assertSee('rakurn299')
            ->assertSee('195');
    }

    public function test_account_media_selection_form_saves_and_validates(): void
    {
        $acct = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);

        $this->withAdminSession()
            ->get('/settings')
            ->assertOk()
            ->assertSee('Pindai postingan')
            ->assertSee('Postingan terbaru')
            ->assertSee('Postingan tertentu');

        $this->withAdminSession()
            ->post(route('settings.account-media', $acct), [
                'scan_recent' => '0',
                'scan_specific' => '1',
                'media_ids' => "18077184818352540,\n18077184818352541",
            ])
            ->assertRedirect();

        $fresh = $acct->fresh();
        $this->assertFalse($fresh->scan_recent);
        $this->assertTrue($fresh->scan_specific);
        $this->assertSame(['18077184818352540', '18077184818352541'], $fresh->media_ids);

        $this->withAdminSession()
            ->post(route('settings.account-media', $acct), [
                'scan_specific' => '1',
                'media_ids' => 'abc-not-a-number',
            ])
            ->assertSessionHasErrors('media_ids');

        $this->withAdminSession()
            ->post(route('settings.account-media', $acct), [
                'scan_recent' => '0',
            ])
            ->assertSessionHasErrors('media_mode');
    }

    public function test_account_media_form_converts_url_to_media_id_from_db(): void
    {
        $acct = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);

        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'ig_user_id' => $acct->ig_user_id,
            'text' => 'harga',
            'username' => 'ace',
            'status' => Comment::STATUS_REPLIED,
            'media_url' => 'https://www.instagram.com/p/DWUsLU9EqZM/',
        ]);

        Http::fake();

        $this->withAdminSession()
            ->post(route('settings.account-media', $acct), [
                'scan_specific' => '1',
                'media_ids' => 'https://www.instagram.com/p/DWUsLU9EqZM/',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($acct->fresh()->scan_specific);
        $this->assertSame(['18077184818352540'], $acct->fresh()->media_ids);
    }

    public function test_account_media_form_rejects_unresolvable_url(): void
    {
        $acct = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);

        Http::fake([
            'graph.facebook.com/*/17841406718308216/media*' => Http::response([
                'data' => [],
                'paging' => ['cursors' => ['after' => null]],
            ]),
        ]);

        $this->withAdminSession()
            ->post(route('settings.account-media', $acct), [
                'scan_specific' => '1',
                'media_ids' => 'https://www.instagram.com/p/TIDAKADA123/',
            ])
            ->assertSessionHasErrors('media_ids');
    }

    public function test_resolve_media_endpoint_converts_urls_and_keeps_ids(): void
    {
        $acct = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);

        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'ig_user_id' => $acct->ig_user_id,
            'text' => 'harga',
            'username' => 'ace',
            'status' => Comment::STATUS_REPLIED,
            'media_url' => 'https://www.instagram.com/p/DWUsLU9EqZM/',
        ]);

        Http::fake([
            'graph.facebook.com/*/17841406718308216/media*' => Http::response([
                'data' => [],
                'paging' => ['cursors' => ['after' => null]],
            ]),
        ]);

        $this->withAdminSession()
            ->postJson(route('settings.resolve-media', $acct), [
                'tokens' => [
                    '18077184818352541',
                    'https://www.instagram.com/p/DWUsLU9EqZM/',
                    'https://www.instagram.com/reel/TIDAKADA789/',
                    'barang-bukan-url',
                ],
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'results' => [
                    ['value' => '18077184818352541', 'media_id' => '18077184818352541', 'error' => null],
                    ['value' => 'https://www.instagram.com/p/DWUsLU9EqZM/', 'media_id' => '18077184818352540', 'error' => null],
                    ['value' => 'https://www.instagram.com/reel/TIDAKADA789/', 'media_id' => '', 'error' => 'https://www.instagram.com/reel/TIDAKADA789/'],
                    ['value' => 'barang-bukan-url', 'media_id' => '', 'error' => 'barang-bukan-url'],
                ],
            ]);
    }

    public function test_resolve_media_endpoint_falls_back_to_graph_when_not_in_db(): void
    {
        $acct = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);

        Http::fake([
            'graph.facebook.com/*/17841406718308216/media*' => Http::response([
                'data' => [
                    ['id' => '99999999999999999', 'permalink' => 'https://www.instagram.com/p/DQUERYABC1/'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
        ]);

        $this->withAdminSession()
            ->postJson(route('settings.resolve-media', $acct), [
                'tokens' => ['https://www.instagram.com/p/DQUERYABC1/'],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.media_id', '99999999999999999')
            ->assertJsonPath('results.0.error', null);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/17841406718308216/media'));
    }

    public function test_dashboard_live_partial_renders(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
        ]);
        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'text' => 'berapa harga?',
            'username' => 'user_baru',
            'status' => Comment::STATUS_REPLIED,
        ]);

        $this->withAdminSession()
            ->get('/dashboard/live')
            ->assertOk()
            ->assertSee('Sisa kuota')
            ->assertSee('berapa harga?')
            ->assertSee('Dibalas');
    }

    public function test_logs_live_partial_renders_with_filters(): void
    {
        Comment::create([
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'text' => 'berapa harga?',
            'username' => 'user_baru',
            'status' => Comment::STATUS_REPLIED,
        ]);

        $this->withAdminSession()
            ->get('/logs?partial=1')
            ->assertOk()
            ->assertSee('berapa harga?')
            ->assertSee('Dibalas');

        $this->withAdminSession()
            ->get('/logs?partial=1&status=failed')
            ->assertOk()
            ->assertDontSee('berapa harga?');
    }

    public function test_poll_now_returns_json_when_requested(): void
    {
        $this->withAdminSession()
            ->post('/settings/poll-now', [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['ok', 'message'])
            ->assertJson(['ok' => false]);
    }

    public function test_lock_button_hidden_when_app_lock_inactive(): void
    {
        $this->withAdminSession()
            ->get('/')
            ->assertOk()
            ->assertDontSee('Kunci (Admin)');

        $this->withAdminSession()
            ->post('/settings/app-lock', ['enabled' => 1, 'pin' => '1234', 'timeout_minutes' => 5])
            ->assertRedirect();

        $this->withAdminSession()
            ->get('/')
            ->assertOk()
            ->assertSee('🔒 Kunci (Admin)');
    }

    public function test_lock_flow(): void
    {
        $this->withAdminSession()
            ->post('/settings/app-lock', ['enabled' => 1, 'pin' => '4321', 'timeout_minutes' => 5])
            ->assertRedirect();

        $this->withAdminSession()
            ->post('/lock/now')
            ->assertRedirect('/lock');

        $this->get('/lock')->assertOk();

        $this->withSession(['admin_id' => $this->admin->id, 'admin_name' => 'Admin'])
            ->post('/lock', ['pin' => '9999'])
            ->assertSessionHasErrors('pin');

        $this->withSession(['admin_id' => $this->admin->id, 'admin_name' => 'Admin'])
            ->post('/lock', ['pin' => '4321'])
            ->assertRedirect('/');
    }

    public function test_poll_now_aborts_when_bot_disabled(): void
    {
        $this->withAdminSession()
            ->post('/settings/poll-now')
            ->assertRedirect()
            ->assertSessionHas('flash');
    }

    public function test_poll_now_triggered_by_button_runs_processing(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'test-token',
            'token_type' => 'user',
            'token_expires_at' => now()->addDays(30),
            'connected_at' => now(),
        ]);
        Setting::singleton()->update(['bot_enabled' => true]);
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM.', 'is_active' => true]);

        Http::fake([
            'graph.facebook.com/v26.0/17841406718308216/media*' => Http::response([
                'data' => [
                    ['id' => '18077184818352540', 'media_type' => 'CAROUSEL_ALBUM', 'timestamp' => '2026-08-29T10:00:00+0000', 'permalink' => 'https://www.instagram.com/p/DWUsLU9EqZM/'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/v26.0/18077184818352540/comments*' => Http::response([
                'data' => [
                    ['id' => '101', 'text' => 'berapa harga?', 'username' => 'user_baru', 'from' => ['id' => '999'], 'timestamp' => '2026-08-29T11:00:00+0000'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'not faked', 'code' => 1]]),
        ]);

        Queue::fake();

        $this->withAdminSession()
            ->post('/settings/poll-now', [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Polling selesai: 1 komentar baru, 1 balas diantrekan.');

        Queue::assertPushed(ReplyToCommentJob::class, 1);
        $this->assertDatabaseHas('comments', ['comment_id' => '101', 'status' => Comment::STATUS_PENDING]);
    }

    public function test_test_connection_no_account(): void
    {
        $this->withAdminSession()
            ->post('/settings/test')
            ->assertRedirect()
            ->assertSessionHas('flash-error');
    }
}
