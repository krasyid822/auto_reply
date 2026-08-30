<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessCommentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::singleton();
    }

    protected function fakeSuccessfulCycle(): void
    {
        InstagramAccount::create([
            'ig_user_id' => 17841406718308216,
            'username' => 'rakurn299',
            'access_token' => 'test-token',
        ]);
        AutoReplyRule::create(['keyword' => 'harga', 'reply_text' => 'Silakan DM!', 'is_active' => true]);
        Setting::singleton()->update(['bot_enabled' => true]);

        Http::fake([
            'graph.facebook.com/v26.0/17841406718308216/media*' => Http::response([
                'data' => [['id' => '18077184818352540', 'media_type' => 'IMAGE']],
                'paging' => ['cursors' => ['after' => null]],
            ]),
            'graph.facebook.com/v26.0/18077184818352540/comments*' => Http::response([
                'data' => [['id' => '101', 'text' => 'berapa harga?', 'username' => 'user_baru', 'from' => ['id' => '999']]],
                'paging' => ['cursors' => ['after' => null]],
            ]),
        ]);
    }

    public function test_command_success_path(): void
    {
        $this->fakeSuccessfulCycle();
        Queue::fake();

        $this->artisan('instagram:process-comments')->assertExitCode(0);

        $this->assertDatabaseHas('comments', [
            'comment_id' => '101',
            'status' => Comment::STATUS_PENDING,
            'reply_text' => 'Silakan DM!',
        ]);
        $this->assertNotNull(Setting::singleton()->last_polled_at);
    }

    public function test_command_aborts_when_bot_disabled(): void
    {
        $this->artisan('instagram:process-comments')
            ->expectsOutputToContain('Siklus dihentikan: Bot nonaktif')
            ->assertExitCode(1);
    }

    public function test_command_aborts_when_account_missing(): void
    {
        Setting::singleton()->update(['bot_enabled' => true]);

        $this->artisan('instagram:process-comments')
            ->expectsOutputToContain('Siklus dihentikan')
            ->assertExitCode(1);
    }
}
