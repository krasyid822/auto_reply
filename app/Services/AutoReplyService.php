<?php

namespace App\Services;

use App\Jobs\ReplyToCommentJob;
use App\Models\AutoReplyRule;
use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AutoReplyService
{
    /**
     * Satu siklus polling untuk semua akun terhubung:
     * media -> komentar -> dedup -> match rule -> dispatch balasan.
     *
     * @return array<string, mixed>
     */
    public function process(?int $maxMedia = null): array
    {
        $setting = Setting::singleton();

        $summary = [
            'aborted' => false,
            'reason' => null,
            'account_seen' => 0,
            'media_seen' => 0,
            'comments_seen' => 0,
            'new' => 0,
            'own' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'replied_dispatch' => 0,
            'failed' => 0,
        ];

        if (! $setting->bot_enabled) {
            return $this->abort($summary, 'Bot nonaktif (aktifkan di Settings).');
        }

        if ($setting->poll_cooldown_until !== null && $setting->poll_cooldown_until->isFuture()) {
            return $this->abort($summary, 'Cooldown rate-limit aktif sampai '.$setting->poll_cooldown_until->toDateTimeString().'.');
        }

        $accounts = InstagramAccount::query()
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->get();

        if ($accounts->isEmpty()) {
            return $this->abort($summary, 'Akun Instagram belum terhubung.');
        }

        foreach ($accounts as $account) {
            if ($this->processAccount($account, $maxMedia, $summary)) {
                break;
            }
        }

        if ($summary['aborted']) {
            return $summary;
        }

        $setting->update(['last_polled_at' => now()]);

        return $summary;
    }

    /**
     * Polling satu akun; mengisi $summary secara agregat.
     *
     * @return bool true = siklus harus dihentikan (rate limit / token error)
     */
    protected function processAccount(InstagramAccount $account, ?int $maxMedia, array &$summary): bool
    {
        $setting = Setting::singleton();
        $client = new InstagramClient($account);

        $summary['account_seen']++;

        try {
            $mediaItems = collect();

            if ($account->scan_recent) {
                $mediaItems = $mediaItems->merge(
                    $client->recentMedia($maxMedia ?? $setting->max_media_per_cycle)
                );
            }

            foreach ($this->specificMediaIds($account) ?? [] as $id) {
                $mediaItems->push(['id' => $id, 'media_type' => null]);
            }

            $mediaItems = $mediaItems->unique('id')->values();
        } catch (InstagramApiException $e) {
            if ($e->isRateLimited()) {
                $this->enterCooldown($setting);

                return $this->abortInto($summary, 'Rate limit: '.$e->getMessage());
            }

            return $this->abortInto($summary, $this->classifyError($e));
        }

        $summary['media_seen'] += $mediaItems->count();
        $rules = null;

        foreach ($mediaItems as $media) {
            $mediaUrl = $media['permalink'] ?? Comment::query()
                ->where('ig_user_id', (int) $account->ig_user_id)
                ->where('media_id', $media['id'])
                ->whereNotNull('media_url')
                ->value('media_url');

            try {
                $comments = $client->comments($media['id']);
            } catch (InstagramApiException $e) {
                if ($e->isRateLimited()) {
                    $this->enterCooldown($setting);

                    return $this->abortInto($summary, 'Rate limit: '.$e->getMessage());
                }

                $summary['failed']++;
                $summary['reason'] = $e->getMessage();

                continue;
            }

            foreach ($comments as $raw) {
                $summary['comments_seen']++;

                if ($this->isOwnComment($raw, $account) && ! $setting->reply_to_own_comments) {
                    $summary['own']++;

                    continue;
                }

                if (Comment::where('ig_user_id', $account->ig_user_id)->where('comment_id', $raw['id'])->exists()) {
                    $summary['duplicates']++;

                    continue;
                }

                $summary['new']++;

                if ($rules === null) {
                    $rules = AutoReplyRule::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->get();
                }

                $rule = $this->findRule((string) ($raw['text'] ?? ''), $rules);

                if ($rule === null) {
                    Comment::create([
                        'ig_user_id' => (int) $account->ig_user_id,
                        'comment_id' => $raw['id'],
                        'media_id' => $media['id'],
                        'media_type' => $media['media_type'] ?? null,
                        'media_url' => $mediaUrl,
                        'text' => $raw['text'] ?? null,
                        'username' => $raw['username'] ?? null,
                        'from_user_id' => isset($raw['from']['id']) ? (int) $raw['from']['id'] : null,
                        'status' => Comment::STATUS_SKIPPED,
                    ]);
                    $summary['skipped']++;

                    continue;
                }

                $comment = Comment::create([
                    'ig_user_id' => (int) $account->ig_user_id,
                    'comment_id' => $raw['id'],
                    'media_id' => $media['id'],
                    'media_type' => $media['media_type'] ?? null,
                    'media_url' => $mediaUrl,
                    'text' => $raw['text'] ?? null,
                    'username' => $raw['username'] ?? null,
                    'from_user_id' => isset($raw['from']['id']) ? (int) $raw['from']['id'] : null,
                    'status' => Comment::STATUS_PENDING,
                    'reply_text' => $rule->reply_text,
                    'rule_id' => $rule->id,
                ]);

                ReplyToCommentJob::dispatch($comment);
                $summary['replied_dispatch']++;
            }
        }

        return false;
    }

    public function findRule(string $text, Collection $rules): ?AutoReplyRule
    {
        if (blank($text)) {
            return null;
        }

        return $rules->first(fn (AutoReplyRule $rule) => $rule->matches($text));
    }

    public function classifyError(InstagramApiException $e): string
    {
        if ($e->isRateLimited()) {
            return 'Rate limit';
        }

        if ($e->isTokenExpired()) {
            return 'Access token kedaluwarsa atau dicabut';
        }

        return Str::limit($e->getMessage(), 200);
    }

    /**
     * Dari daftar postingan pilihan akun (mode "postingan tertentu"), dikumpulkan
     * tanpa aksi HTTP agar hemat kuota saat mode terbaru ikut aktif.
     *
     * @return array<int, string>|null
     */
    protected function specificMediaIds(?InstagramAccount $account): ?array
    {
        if ($account === null || ! $account->scan_specific) {
            return null;
        }

        $ids = array_values(array_filter($account->media_ids ?? []));

        return $ids === [] ? null : $ids;
    }

    protected function isOwnComment(array $raw, ?InstagramAccount $account): bool
    {
        if ($account === null) {
            return false;
        }

        $ownUsername = mb_strtolower((string) $account->username);
        $username = mb_strtolower((string) ($raw['username'] ?? ''));

        if ($username !== '' && $username === $ownUsername) {
            return true;
        }

        if (isset($raw['from']['id'])) {
            return (int) $account->ig_user_id === (int) $raw['from']['id'];
        }

        return false;
    }

    protected function enterCooldown(Setting $setting): void
    {
        $minutes = max(10, (int) $setting->poll_interval_minutes * 3);
        $setting->update(['poll_cooldown_until' => now()->addMinutes($minutes)]);
    }

    protected function abort(array $summary, string $reason, ?InstagramApiException $e = null): array
    {
        if ($e !== null) {
            $reason = $this->classifyError($e);
        }

        $summary['aborted'] = true;
        $summary['reason'] = $reason;

        return $summary;
    }

    protected function abortInto(array &$summary, string $reason): bool
    {
        $summary['aborted'] = true;
        $summary['reason'] = $reason;

        return true;
    }
}
