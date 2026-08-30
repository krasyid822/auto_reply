<?php

namespace App\Http\Controllers;

use App\Models\AutoReplyRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RuleController extends Controller
{
    public function index(Request $request): View
    {
        $editing = null;

        if ($request->query('edit') !== null) {
            $editing = AutoReplyRule::find($request->query('edit'));
        }

        return view('rules.index', [
            'rules' => AutoReplyRule::query()->orderBy('sort_order')->orderBy('id')->get(),
            'editing' => $editing,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        AutoReplyRule::create($data + ['sort_order' => (int) AutoReplyRule::max('sort_order') + 1]);

        return redirect()->route('rules.index')->with('flash', 'Rule ditambahkan.');
    }

    public function update(Request $request, AutoReplyRule $rule): RedirectResponse
    {
        $this->validated($request);
        $rule->update([
            'keyword' => $request->string('keyword'),
            'reply_text' => $request->string('reply_text'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('rules.index')->with('flash', 'Rule diperbarui.');
    }

    public function destroy(AutoReplyRule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect()->route('rules.index')->with('flash', 'Rule dihapus.');
    }

    public function toggle(AutoReplyRule $rule): RedirectResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('flash', $rule->is_active ? 'Rule diaktifkan.' : 'Rule dinonaktifkan.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'reply_text' => ['required', 'string', 'max:1000'],
        ], [
            'keyword.required' => 'Keyword wajib diisi.',
            'reply_text.required' => 'Template balasan wajib diisi.',
        ]);
    }
}
