<?php

namespace Tests\Feature;

use App\Jobs\ReplyToCommentJob;
use App\Models\AutoReplyRule;
use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use App\Services\AutoReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::singleton();
    }

    protected function connectAccount(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'test-token',
            'token_type' => 'user',
            'token_expires_at' => now()->addDays(30),
            'connected_at' => now(),
        ]);
    }

    protected function enableBot(): void
    {
        Setting::singleton()->update(['bot_enabled' => true]);
    }

    protected function fakeMediaAndComments(): void
    {
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
                    ['id' => '102', 'text' => 'Mantap!', 'username' => 'follower_fan', 'from' => ['id' => '888'], 'timestamp' => '2026-08-29T11:01:00+0000'],
                    ['id' => '103', 'text' => 'LOKASI DM', 'username' => 'rakurn299', 'from' => ['id' => '17841406718308216'], 'timestamp' => '2026-08-29T11:02:00+0000'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'not faked', 'code' => 1]]),
        ]);
    }

    public function test_process_matches_rule_and_dispatches_job(): void
    {
        $this->connectAccount();
        $this->enableBot();
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM untuk info harga.', 'is_active' => true]);

        Queue::fake();
        $this->fakeMediaAndComments();

        $result = app(AutoReplyService::class)->process();

        $this->assertSame(1, $result['media_seen']);
        $this->assertSame(3, $result['comments_seen']);
        $this->assertSame(2, $result['new']);
        $this->assertSame(1, $result['own']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['replied_dispatch']);
        $this->assertNull($result['reason']);

        Queue::assertPushed(ReplyToCommentJob::class, 1);

        $this->assertDatabaseHas('comments', ['comment_id' => '101', 'status' => Comment::STATUS_PENDING, 'reply_text' => 'Silakan DM untuk info harga.']);
        $this->assertDatabaseHas('comments', ['comment_id' => '102', 'status' => Comment::STATUS_SKIPPED]);
        $this->assertDatabaseMissing('comments', ['comment_id' => '103']);

        $this->assertDatabaseHas('comments', ['comment_id' => '101', 'media_url' => 'https://www.instagram.com/p/DWUsLU9EqZM/']);

        $this->assertSame(2, InstagramAccount::first()->fresh()->api_calls_count);

        $this->assertNotNull(Setting::singleton()->last_polled_at);
    }

    public function test_process_skips_duplicates_on_second_run(): void
    {
        $this->connectAccount();
        $this->enableBot();
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM.', 'is_active' => true]);
        Comment::create([
            'ig_user_id' => 17841406718308216,
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'text' => 'berapa harga?',
            'username' => 'user_baru',
            'status' => Comment::STATUS_REPLIED,
        ]);

        Queue::fake();
        $this->fakeMediaAndComments();

        $result = app(AutoReplyService::class)->process();

        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(0, $result['replied_dispatch']);
        $this->assertSame(1, $result['own']);

        Queue::assertNotPushed(ReplyToCommentJob::class);
    }

    public function test_process_aborts_when_bot_disabled(): void
    {
        $this->connectAccount();

        $result = app(AutoReplyService::class)->process();

        $this->assertTrue($result['aborted']);
        $this->assertStringContainsString('Bot nonaktif', $result['reason']);
    }

    public function test_process_aborts_when_account_missing(): void
    {
        $this->enableBot();

        $result = app(AutoReplyService::class)->process();

        $this->assertTrue($result['aborted']);
        $this->assertStringContainsString('belum terhubung', $result['reason']);
    }

    public function test_process_aborts_during_cooldown(): void
    {
        $this->connectAccount();
        $this->enableBot();
        Setting::singleton()->update(['poll_cooldown_until' => now()->addMinutes(30)]);

        $result = app(AutoReplyService::class)->process();

        $this->assertTrue($result['aborted']);
        $this->assertStringContainsString('Cooldown', $result['reason']);
    }

    public function test_own_comment_matching_rule_is_still_skipped(): void
    {
        $this->connectAccount();
        $this->enableBot();
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM.', 'is_active' => true]);

        Queue::fake();
        $this->fakeMediaAndComments();

        $result = app(AutoReplyService::class)->process();

        $this->assertSame(1, $result['own']);
        $this->assertDatabaseMissing('comments', ['comment_id' => '103']);
    }

    public function test_own_comment_is_replied_when_reply_to_own_enabled(): void
    {
        $this->connectAccount();
        $this->enableBot();
        Setting::singleton()->update(['reply_to_own_comments' => true]);
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM.', 'is_active' => true]);
        AutoReplyRule::create(['keyword' => 'lokasi', 'reply_text' => 'Lokasi: Jakarta.', 'is_active' => true]);

        Queue::fake();
        $this->fakeMediaAndComments();

        $result = app(AutoReplyService::class)->process();

        $this->assertSame(0, $result['own']);
        $this->assertSame(2, $result['replied_dispatch']);
        Queue::assertPushed(ReplyToCommentJob::class, 2);

        $this->assertDatabaseHas('comments', ['comment_id' => '103', 'status' => Comment::STATUS_PENDING, 'reply_text' => 'Lokasi: Jakarta.']);
        $this->assertDatabaseHas('comments', ['comment_id' => '101', 'status' => Comment::STATUS_PENDING]);
        $this->assertDatabaseHas('comments', ['comment_id' => '102', 'status' => Comment::STATUS_SKIPPED]);
    }

    public function test_process_handles_multiple_accounts_independently(): void
    {
        $this->enableBot();
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM!', 'is_active' => true]);

        $ig1 = 17841406718308216;
        $ig2 = 98765432101234;
        InstagramAccount::create(['ig_user_id' => $ig1, 'username' => 'rakurn299', 'access_token' => 't1']);
        InstagramAccount::create(['ig_user_id' => $ig2, 'username' => 'toko_b', 'access_token' => 't2']);

        Http::fake([
            "graph.facebook.com/v26.0/{$ig1}/media*" => Http::response([
                'data' => [['id' => '18077184818352540', 'media_type' => 'IMAGE']],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            "graph.facebook.com/v26.0/{$ig2}/media*" => Http::response([
                'data' => [['id' => '18077184818352541', 'media_type' => 'VIDEO']],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/v26.0/18077184818352540/comments*' => Http::response([
                'data' => [
                    ['id' => '101', 'text' => 'berapa harga?', 'username' => 'user_a', 'from' => ['id' => '999']],
                    ['id' => '103', 'text' => 'ada diskon?', 'username' => 'rakurn299', 'from' => ['id' => (string) $ig1]],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/v26.0/18077184818352541/comments*' => Http::response([
                'data' => [
                    ['id' => '201', 'text' => 'berapa harga itu?', 'username' => 'user_b', 'from' => ['id' => '888']],
                    ['id' => '202', 'text' => 'Mantap!', 'username' => 'user_c', 'from' => ['id' => '777']],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'not faked', 'code' => 1]]),
        ]);

        Queue::fake();

        $result = app(AutoReplyService::class)->process();

        $this->assertSame(2, $result['account_seen']);
        $this->assertSame(2, $result['media_seen']);
        $this->assertSame(4, $result['comments_seen']);
        $this->assertSame(1, $result['own']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $result['replied_dispatch']);

        Queue::assertPushed(ReplyToCommentJob::class, 2);

        $this->assertDatabaseHas('comments', ['ig_user_id' => $ig1, 'comment_id' => '101', 'status' => Comment::STATUS_PENDING]);
        $this->assertDatabaseMissing('comments', ['ig_user_id' => $ig1, 'comment_id' => '103']);
        $this->assertDatabaseHas('comments', ['ig_user_id' => $ig2, 'comment_id' => '201', 'status' => Comment::STATUS_PENDING]);
        $this->assertDatabaseHas('comments', ['ig_user_id' => $ig2, 'comment_id' => '202', 'status' => Comment::STATUS_SKIPPED]);
    }

    public function test_process_scans_only_specific_media_when_selected(): void
    {
        $this->connectAccount();
        $this->enableBot();
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM.', 'is_active' => true]);

        InstagramAccount::first()->update([
            'scan_recent' => false,
            'scan_specific' => true,
            'media_ids' => ['18077184818352540'],
        ]);

        // Endpoint daftar media sengaja TIDAK di-stub: jika recentMedia dipanggil,
        // catch-all error akan meng- abort siklus (bukti mode "tertentu" dipakai).
        Http::fake([
            'graph.facebook.com/v26.0/18077184818352540/comments*' => Http::response([
                'data' => [
                    ['id' => '101', 'text' => 'berapa harga?', 'username' => 'user_baru', 'from' => ['id' => '999'], 'timestamp' => '2026-08-29T11:00:00+0000'],
                    ['id' => '102', 'text' => 'Mantap!', 'username' => 'follower_fan', 'from' => ['id' => '888'], 'timestamp' => '2026-08-29T11:01:00+0000'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'not faked', 'code' => 1]]),
        ]);

        Queue::fake();

        $result = app(AutoReplyService::class)->process();

        $this->assertFalse($result['aborted']);
        $this->assertSame(1, $result['media_seen']);
        $this->assertSame(2, $result['comments_seen']);
        $this->assertSame(1, $result['replied_dispatch']);

        // Hanya 1 panggilan Graph (komentar), tanpa panggilan daftar media.
        $this->assertSame(1, InstagramAccount::first()->fresh()->api_calls_count);

        $this->assertDatabaseHas('comments', ['comment_id' => '101', 'status' => Comment::STATUS_PENDING]);
        $this->assertDatabaseHas('comments', ['comment_id' => '102', 'status' => Comment::STATUS_SKIPPED]);
    }

    public function test_process_combines_recent_and_specific_media_when_both_enabled(): void
    {
        $this->connectAccount();
        $this->enableBot();
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM.', 'is_active' => true]);

        // Kedua mode aktif: media terbaru (list 1 media) + media pilihan (id berbeda)
        // dan satu id yang tumpang-tindih (dedup, komentar hanya dipanggil sekali).
        InstagramAccount::first()->update([
            'scan_recent' => true,
            'scan_specific' => true,
            'media_ids' => ['18077184818352540', '18077184818352542'],
        ]);

        Http::fake([
            'graph.facebook.com/v26.0/17841406718308216/media*' => Http::response([
                'data' => [
                    ['id' => '18077184818352540', 'media_type' => 'IMAGE', 'timestamp' => '2026-08-29T10:00:00+0000'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/v26.0/18077184818352540/comments*' => Http::response([
                'data' => [
                    ['id' => '101', 'text' => 'berapa harga?', 'username' => 'user_baru', 'from' => ['id' => '999'], 'timestamp' => '2026-08-29T11:00:00+0000'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/v26.0/18077184818352542/comments*' => Http::response([
                'data' => [
                    ['id' => '201', 'text' => 'stok?', 'username' => 'joko', 'from' => ['id' => '888'], 'timestamp' => '2026-08-29T11:00:00+0000'],
                ],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'not faked', 'code' => 1]]),
        ]);

        Queue::fake();

        $result = app(AutoReplyService::class)->process();

        $this->assertFalse($result['aborted']);
        $this->assertSame(2, $result['media_seen']);
        $this->assertSame(2, $result['comments_seen']);
        $this->assertSame(0, $result['duplicates']);

        // 1 panggilan daftar media + 2 panggilan komentar (media id dobel di-dedup).
        $this->assertSame(3, InstagramAccount::first()->fresh()->api_calls_count);

        $this->assertDatabaseHas('comments', ['comment_id' => '101', 'status' => Comment::STATUS_PENDING]);
        $this->assertDatabaseHas('comments', ['comment_id' => '201', 'status' => Comment::STATUS_SKIPPED]);
    }

    public function test_api_quota_window_expires_and_resets_automatically(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'token',
            'api_calls_count' => 150,
            'api_calls_window_start' => now()->subHours(2),
        ]);

        $this->assertSame(200, InstagramAccount::first()->remainingCalls());

        InstagramAccount::first()->markApiCall();

        $fresh = InstagramAccount::first();
        $this->assertSame(1, $fresh->api_calls_count);
        $this->assertNotNull($fresh->api_calls_window_start);
        $this->assertSame(199, $fresh->remainingCalls());
    }

    public function test_find_rule_is_case_insensitive_contains(): void
    {
        $rules = collect([
            AutoReplyRule::create(['keyword' => 'lokasi', 'reply_text' => 'Lokasi: Jakarta.', 'is_active' => true]),
        ]);

        $service = app(AutoReplyService::class);

        $this->assertSame('Lokasi: Jakarta.', $service->findRule('DIMANA LOKASI KALIAN?', $rules)?->reply_text);
        $this->assertNull($service->findRule('tidak berkata apa-apa', $rules));
    }

    public function test_find_rule_trimmed_keyword(): void
    {
        $rules = collect([
            AutoReplyRule::create(['keyword' => 'harga ', 'reply_text' => 'Silakan DM.', 'is_active' => true]),
        ]);

        $service = app(AutoReplyService::class);

        $this->assertSame('Silakan DM.', $service->findRule('berapa harga?', $rules)?->reply_text);
        $this->assertSame('Silakan DM.', $service->findRule('info harga', $rules)?->reply_text);
    }

    public function test_find_rule_blank_keyword_never_matches(): void
    {
        $rules = collect([
            AutoReplyRule::create(['keyword' => '   ', 'reply_text' => 'Tidak seharusnya match.', 'is_active' => true]),
        ]);

        $service = app(AutoReplyService::class);

        $this->assertNull($service->findRule('apa pun', $rules));
        $this->assertNull($service->findRule('   ', $rules));
    }

    public function test_reprocess_skipped_deletes_comments(): void
    {
        Comment::create([
            'ig_user_id' => 17841406718308216,
            'comment_id' => 999000001,
            'media_id' => 18077184818352540,
            'text' => 'test',
            'status' => Comment::STATUS_SKIPPED,
        ]);

        Comment::create([
            'ig_user_id' => 17841406718308216,
            'comment_id' => 999000002,
            'media_id' => 18077184818352540,
            'text' => 'test2',
            'status' => Comment::STATUS_REPLIED,
        ]);

        $this->assertDatabaseCount('comments', 2);

        $this->artisan('instagram:reprocess-skipped')
            ->expectsOutputToContain('1 komentar skipped dihapus.')
            ->assertSuccessful();

        $this->assertDatabaseCount('comments', 1);
        $this->assertDatabaseHas('comments', ['comment_id' => 999000002, 'status' => Comment::STATUS_REPLIED]);
    }
}
