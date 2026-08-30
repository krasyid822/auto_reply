<?php

namespace Tests\Feature;

use App\Jobs\ReplyToCommentJob;
use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplyToCommentJobTest extends TestCase
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
        ]);
    }

    protected function makeComment(string $text = 'berapa harga?', string $reply = 'Silakan DM!'): Comment
    {
        return Comment::create([
            'ig_user_id' => 17841406718308216,
            'comment_id' => '101',
            'media_id' => '18077184818352540',
            'text' => $text,
            'username' => 'user_baru',
            'status' => Comment::STATUS_PENDING,
            'reply_text' => $reply,
        ]);
    }

    public function test_successful_reply_marks_comment_replied(): void
    {
        $this->connectAccount();
        $comment = $this->makeComment();

        Http::fake([
            'graph.facebook.com/v26.0/101/replies' => Http::response(['id' => '2001']),
        ]);

        (new ReplyToCommentJob($comment))->handle();

        $this->assertSame(Comment::STATUS_REPLIED, $comment->fresh()->status);
        $this->assertNotNull($comment->fresh()->replied_at);
        $this->assertNull($comment->fresh()->error);

        Http::assertSent(fn ($request) => $request->data()['message'] === 'Silakan DM!');
    }

    public function test_token_expired_marks_failed_and_stops(): void
    {
        $this->connectAccount();
        $comment = $this->makeComment();

        Http::fake([
            'graph.facebook.com/v26.0/101/replies' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]]),
        ]);

        (new ReplyToCommentJob($comment))->handle();

        $this->assertSame(Comment::STATUS_FAILED, $comment->fresh()->status);
        $this->assertStringContainsString('kedaluwarsa', $comment->fresh()->error);
    }

    public function test_rate_limit_releases_job(): void
    {
        $this->connectAccount();
        $comment = $this->makeComment();

        Http::fake([
            'graph.facebook.com/v26.0/101/replies' => Http::response(['error' => ['message' => 'rate limit', 'code' => 429]], 429),
        ]);

        $job = new ReplyToCommentJob($comment);
        $job->handle();

        $this->assertSame(Comment::STATUS_PENDING, $comment->fresh()->status);
        $this->assertNull($comment->fresh()->replied_at);
    }

    public function test_already_replied_comment_is_skipped(): void
    {
        $comment = $this->makeComment();
        $comment->update(['status' => Comment::STATUS_REPLIED]);

        Http::fake();

        (new ReplyToCommentJob($comment))->handle();

        Http::assertNothingSent();
    }

    public function test_missing_account_marks_failed(): void
    {
        $comment = $this->makeComment();

        Http::fake();

        (new ReplyToCommentJob($comment))->handle();

        Http::assertNothingSent();
        $this->assertSame(Comment::STATUS_FAILED, $comment->fresh()->status);
        $this->assertNotNull($comment->fresh()->error);
    }
}
