<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAdminSession;
use App\Models\AppLock;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LockController extends Controller
{
    public function show(Request $request): View
    {
        $admin = User::find($request->session()->get('admin_id'));

        return view('lock.show', ['admin' => $admin]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $admin = User::find($request->session()->get('admin_id'));

        if ($admin === null) {
            return redirect()->route('profiles.index');
        }

        $data = $request->validate([
            'pin' => ['required', 'string'],
        ], ['pin.required' => 'PIN wajib diisi.']);

        $lock = $admin->appLock;

        if ($lock === null || ! $lock->enabled || ! $lock->verifyPin($data['pin'])) {
            return back()->withErrors(['pin' => 'PIN salah.'])->withInput();
        }

        $request->session()->put(EnsureAdminSession::ACTIVITY_KEY, now()->getTimestamp());

        return redirect()->route('dashboard')->with('flash', 'Terkunci dibuka.');
    }

    public function lockNow(Request $request): RedirectResponse
    {
        $request->session()->put(EnsureAdminSession::ACTIVITY_KEY, 0);

        return redirect()->route('lock.show');
    }

    /**
     * Simpan/ubah App Lock (PIN + timeout) untuk profil aktif.
     */
    public function pinStore(Request $request): RedirectResponse
    {
        $admin = User::findOrFail((int) $request->session()->get('admin_id'));

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'pin' => ['required_without:keep_pin', 'nullable', 'string', 'min:4', 'max:6'],
            'timeout_minutes' => ['required', 'integer', 'between:1,60'],
        ], ['pin.min' => 'PIN minimal 4 digit.', 'pin.max' => 'PIN maksimal 6 digit.']);

        $lock = $admin->appLock ??= new AppLock(['user_id' => $admin->id]);

        $payload = [
            'enabled' => $request->boolean('enabled'),
            'timeout_minutes' => (int) $data['timeout_minutes'],
        ];

        if ($request->filled('pin')) {
            $payload['pin_hash'] = Hash::make($request->string('pin'));
        }

        $lock->fill($payload)->save();

        return back()->with('flash', 'App Lock diperbarui.');
    }
}
