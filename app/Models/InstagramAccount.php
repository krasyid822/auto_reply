<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramAccount extends Model
{
    protected $fillable = [
        'ig_user_id',
        'page_id',
        'page_name',
        'username',
        'scan_recent',
        'scan_specific',
        'media_ids',
        'access_token',
        'token_type',
        'token_expires_at',
        'token_invalid_at',
        'last_checked_at',
        'last_check_ok',
        'api_calls_window_start',
        'api_calls_count',
        'connected_at',
    ];

    protected $casts = [
        'media_ids' => 'array',
        'scan_recent' => 'boolean',
        'scan_specific' => 'boolean',
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'token_invalid_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_check_ok' => 'boolean',
        'api_calls_window_start' => 'datetime',
        'api_calls_count' => 'integer',
        'connected_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        if ($this->token_invalid_at !== null) {
            return true;
        }

        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Catat hasil pemeriksaan token terbaru.
     */
    public function markTokenChecked(bool $ok): void
    {
        $this->forceFill([
            'token_invalid_at' => $ok ? null : now(),
            'last_checked_at' => now(),
            'last_check_ok' => $ok,
        ])->save();
    }

    public function daysUntilExpiry(): ?int
    {
        if ($this->token_expires_at === null) {
            return null; // no-expiry
        }

        return max(0, (int) now()->diffInDays($this->token_expires_at, false));
    }

    public function rateLimitPerHour(): int
    {
        return (int) config('instagram.calls_per_hour_limit', 200);
    }

    /**
     * Catat satu pemakaian kuota Graph API (jendela 1 jam bergulir).
     */
    public function markApiCall(): void
    {
        $window = $this->api_calls_window_start;

        if ($window === null || $window->addHour()->isPast()) {
            $this->forceFill([
                'api_calls_window_start' => now(),
                'api_calls_count' => 1,
            ])->save();

            return;
        }

        $this->forceFill(['api_calls_count' => $this->api_calls_count + 1])->save();
    }

    /**
     * Sisa kuota Graph API per jam (diestimasi lokal; Meta tidak menyediakan endpoint kuota).
     */
    public function remainingCalls(): int
    {
        $window = $this->api_calls_window_start;

        if ($window === null || $window->addHour()->isPast()) {
            return $this->rateLimitPerHour();
        }

        return max(0, $this->rateLimitPerHour() - $this->api_calls_count);
    }
}
