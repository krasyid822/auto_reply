<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\InstagramAccount;
use App\Models\Setting;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard.index', $this->data());
    }

    /**
     * Fragmen HTML untuk auto-refresh Beranda via fetch (tanpa layout).
     */
    public function live(): View
    {
        return view('dashboard._live', $this->data());
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        $setting = Setting::singleton();

        $stats = Comment::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'accounts' => InstagramAccount::query()->orderBy('username')->get(),
            'setting' => $setting,
            'stats' => $stats,
            'recent' => Comment::query()->with('account:id,ig_user_id,username')->latest()->limit(10)->get(),
        ];
    }
}
