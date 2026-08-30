<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ReplyToCommentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public Comment $comment) {}

    public function handle(): void
    {
        if ($this->comment->status === Comment::STATUS_REPLIED) {
            return;
        }

        $account = InstagramAccount::query()
            ->where('ig_user_id', $this->comment->ig_user_id)
            ->first();

        if ($account === null || blank($account->access_token)) {
            $this->comment->update([
                'status' => Comment::STATUS_FAILED,
                'error' => 'Akun pemilik komentar sudah tidak terhubung — hubungkan ulang lewat Settings.',
            ]);
            $this->fail(new \RuntimeException('Instagram account missing for comment '.$this->comment->comment_id));

            return;
        }

        try {
            (new InstagramClient($account))->replyTo((string) $this->comment->comment_id, (string) $this->comment->reply_text);

            $this->comment->update([
                'status' => Comment::STATUS_REPLIED,
                'reply_text' => $this->comment->reply_text,
                'replied_at' => now(),
                'error' => null,
            ]);
        } catch (InstagramApiException $e) {
            if ($e->isTokenExpired()) {
                $this->comment->update([
                    'status' => Comment::STATUS_FAILED,
                    'error' => 'Access token kedaluwarsa — hubungkan ulang akun.',
                ]);
                $this->fail($e);

                return;
            }

            if ($e->isRateLimited()) {
                $this->release(120);

                return;
            }

            $this->comment->update([
                'status' => Comment::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 255),
            ]);

            throw $e;
        }
    }
}
