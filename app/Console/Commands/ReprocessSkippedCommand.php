<?php

namespace App\Console\Commands;

use App\Models\Comment;
use Illuminate\Console\Command;

class ReprocessSkippedCommand extends Command
{
    protected $signature = 'instagram:reprocess-skipped';

    protected $description = 'Hapus komentar status "skipped" agar diproses ulang dengan rule terbaru.';

    public function handle(): int
    {
        $count = Comment::where('status', Comment::STATUS_SKIPPED)->delete();

        $this->info("{$count} komentar skipped dihapus. Siklus berikutnya akan memproses ulang.");

        return self::SUCCESS;
    }
}
