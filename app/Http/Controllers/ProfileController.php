<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAdminSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Halaman pilih / buat profil admin (tanpa login).
     */
    public function index(): View
    {
        $admins = User::query()->orderBy('name')->get();

        return view('profiles.index', ['admins' => $admins]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:users,name'],
        ]);

        $admin = User::create(['name' => $data['name']]);

        return $this->activate($request, $admin);
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $admin = User::findOrFail($data['user_id']);

        return $this->activate($request, $admin);
    }

    protected function activate(Request $request, User $admin): RedirectResponse
    {
        $request->session()->put('admin_id', $admin->id);
        $request->session()->put('admin_name', $admin->name);
        $request->session()->put(EnsureAdminSession::ACTIVITY_KEY, now()->getTimestamp());

        return redirect()->route('dashboard')->with('flash', 'Profil "'.$admin->name.'" aktif.');
    }
}
