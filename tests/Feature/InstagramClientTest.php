<?php

namespace Tests\Feature;

use App\Models\InstagramAccount;
use App\Models\Setting;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstagramClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::singleton();
    }

    public function test_reply_to_comment_posts_correct_request(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'test-token',
        ]);

        Http::fake([
            'graph.facebook.com/v26.0/18103440589959202/replies' => Http::response(['id' => '17923889085185956']),
        ]);

        $result = (new InstagramClient($account))->replyTo('18103440589959202', 'Balasan uji');

        $this->assertSame('17923889085185956', $result['id']);

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v26.0/18103440589959202/replies'
            && $request->data()['message'] === 'Balasan uji'
            && $request->data()['access_token'] === 'test-token');
    }

    public function test_rate_limit_is_detected(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 1,
            'username' => 'u1',
            'access_token' => 't',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Rate limit', 'code' => 429]], 429),
        ]);

        try {
            (new InstagramClient($account))->recentMedia(3);
            $this->fail('Seharusnya melempar InstagramApiException.');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->isRateLimited());
        }
    }

    public function test_expired_token_is_detected(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 1,
            'username' => 'u1',
            'access_token' => 't',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]]),
        ]);

        try {
            (new InstagramClient($account))->recentMedia(3);
            $this->fail('Seharusnya melempar InstagramApiException.');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->isTokenExpired());
        }
    }

    public function test_account_info_parses_fields(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 't',
        ]);

        Http::fake([
            'graph.facebook.com/v26.0/17841406718308216*' => Http::response(['id' => '17841406718308216', 'username' => 'rakurn299']),
        ]);

        $info = (new InstagramClient($account))->accountInfo();

        $this->assertSame('17841406718308216', $info['id']);
        $this->assertSame('rakurn299', $info['username']);
    }

    public function test_revoked_token_error_marks_account_invalid(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 1,
            'username' => 'u1',
            'access_token' => 't',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]]),
        ]);

        try {
            (new InstagramClient($account))->recentMedia(3);
            $this->fail('Seharusnya melempar InstagramApiException.');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->isTokenExpired());
        }

        $account->refresh();
        $this->assertNotNull($account->token_invalid_at);
        $this->assertFalse($account->last_check_ok);
        $this->assertTrue($account->isExpired());
    }

    public function test_successful_account_info_marks_token_ok(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 't',
            'token_invalid_at' => now()->subHour(),
        ]);

        Http::fake([
            'graph.facebook.com/v26.0/17841406718308216*' => Http::response(['id' => '17841406718308216', 'username' => 'rakurn299']),
        ]);

        (new InstagramClient($account))->accountInfo();

        $account->refresh();
        $this->assertNull($account->token_invalid_at);
        $this->assertTrue($account->last_check_ok);
        $this->assertNotNull($account->last_checked_at);
        $this->assertFalse($account->isExpired());
    }

    public function test_injected_token_not_touching_stored_account(): void
    {
        $account = InstagramAccount::create([
            'ig_user_id' => 1,
            'username' => 'u1',
            'access_token' => 't',
            'token_expires_at' => now()->addDays(30),
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]]),
        ]);

        try {
            (new InstagramClient(token: 'injected'))->recentMedia(3);
            $this->fail('Seharusnya melempar InstagramApiException.');
        } catch (InstagramApiException) {
            //
        }

        $this->assertNull($account->fresh()->token_invalid_at);
    }
}
