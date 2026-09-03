<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoReplyRule extends Model
{
    protected $fillable = [
        'keyword',
        'reply_text',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'rule_id');
    }

    public function matches(string $text): bool
    {
        $keyword = trim($this->keyword);

        if ($keyword === '') {
            return false;
        }

        return str_contains(mb_strtolower($text), mb_strtolower($keyword));
    }
}
