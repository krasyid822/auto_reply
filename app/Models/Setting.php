<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public const DEFAULT_INTERVAL = 5;

    public const DEFAULT_MAX_MEDIA = 10;

    protected $fillable = [
        'id',
        'bot_enabled',
        'reply_to_own_comments',
        'poll_interval_minutes',
        'max_media_per_cycle',
        'poll_cooldown_until',
        'last_polled_at',
    ];

    protected $casts = [
        'bot_enabled' => 'boolean',
        'reply_to_own_comments' => 'boolean',
        'poll_cooldown_until' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    public static function singleton(): self
    {
        return static::query()->firstOrCreate([
            'id' => 1,
        ], [
            'bot_enabled' => false,
            'reply_to_own_comments' => false,
            'poll_interval_minutes' => self::DEFAULT_INTERVAL,
            'max_media_per_cycle' => self::DEFAULT_MAX_MEDIA,
        ]);
    }
}
