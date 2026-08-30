<?php

namespace App\Http\Controllers;

use App\Jobs\ReplyToCommentJob;
use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class LogController extends Controller
{
    public function index(Request $request): View
    {
        $query = Comment::query()->with('rule')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->string('q')->trim()) {
            $query->where(fn ($q) => $q->where('text', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%"));
        }

        $comments = $query->paginate(25)->withQueryString();

        $groups = $comments->getCollection()
            ->groupBy('media_id')
            ->map(fn ($items, int $mediaId) => ['media_id' => $mediaId, 'items' => $items])
            ->values();

        $data = [
            'comments' => $comments,
            'groups' => $groups,
            'statuses' => [Comment::STATUS_PENDING, Comment::STATUS_REPLIED, Comment::STATUS_SKIPPED, Comment::STATUS_FAILED],
            'currentStatus' => $request->query('status'),
            'search' => $request->string('q'),
        ];

        if ($request->query('partial')) {
            return view('logs._rows', $data);
        }

        return view('logs.index', $data);
    }

    /**
     * Preview media untuk header kartu Riwayat Komentar: resolve permalink
     * (DB dulu, fallback Graph), lalu minta embed via oEmbed Read (tokenless,
     * Juni 2026). Hasil di-cache per media agar tidak bersyarat ke Graph.
     */
    public function mediaPreview(Request $request): JsonResponse
    {
        $mediaId = $request->string('media_id')->toString();

        if (! ctype_digit($mediaId)) {
            return response()->json(['ok' => false, 'message' => 'ID postingan tidak valid.'], 422);
        }

        $url = Comment::query()
            ->where('media_id', $mediaId)
            ->whereNotNull('media_url')
            ->value('media_url');

        if (blank($url)) {
            $comment = Comment::query()->where('media_id', $mediaId)->first();

            if ($comment !== null) {
                $account = InstagramAccount::query()
                    ->where('ig_user_id', $comment->ig_user_id)
                    ->first();

                try {
                    $url = $account !== null
                        ? (new InstagramClient($account))->permalink($mediaId)
                        : null;
                } catch (InstagramApiException $e) {
                    return response()->json(['ok' => false, 'message' => $e->getMessage()]);
                }

                if ($url !== null) {
                    Comment::query()->where('media_id', $mediaId)->update(['media_url' => $url]);
                }
            }
        }

        if (blank($url)) {
            return response()->json(['ok' => false, 'message' => 'URL postingan tidak ditemukan.']);
        }

        $html = Cache::remember('oembed:'.$mediaId, now()->addDay(), function () use ($url): ?string {
            $response = Http::baseUrl(rtrim((string) config('instagram.api_base'), '/'))
                ->timeout(20)
                ->get('/'.config('instagram.api_version').'/instagram_oembed', [
                    'url' => $url,
                    'omitscript' => true,
                ]);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            return isset($data['html']) ? (string) $data['html'] : null;
        });

        if (blank($html)) {
            return response()->json(['ok' => false, 'message' => 'Postingan tidak dapat dipratinjau (mungkin privat/hapus).']);
        }

        return response()->json(['ok' => true, 'media_url' => $url, 'html' => $html]);
    }

    public function retry(Comment $comment): RedirectResponse
    {
        if ($comment->status !== Comment::STATUS_FAILED || blank($comment->reply_text)) {
            return back()->with('flash', 'Hanya komentar gagal berbalasan yang bisa dicoba ulang.');
        }

        $comment->update(['status' => Comment::STATUS_PENDING, 'error' => null]);
        ReplyToCommentJob::dispatch($comment);

        return back()->with('flash', 'Balasan diantrekan ulang.');
    }
}
