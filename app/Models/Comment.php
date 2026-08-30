<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ig_user_id',
        'comment_id',
        'media_id',
        'media_type',
        'media_url',
        'text',
        'username',
        'from_user_id',
        'status',
        'reply_text',
        'rule_id',
        'replied_at',
        'error',
        'attempts',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutoReplyRule::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'ig_user_id', 'ig_user_id');
    }
}
