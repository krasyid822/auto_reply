<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AutoReplyService;
use Illuminate\Console\Command;

class ProcessCommentsCommand extends Command
{
    protected $signature = 'instagram:process-comments {--media= : Biasakan jumlah media yang dipindai per siklus}';

    protected $description = 'Pindai komentar Instagram terbaru dan proses auto-reply (dedup + match rule + dispatch balasan).';

    public function handle(AutoReplyService $service): int
    {
        $summary = $service->process($this->option('media') !== null ? (int) $this->option('media') : null);

        if ($summary['aborted']) {
            $this->warn('Siklus dihentikan: '.$summary['reason']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Akun %d | Media %d | Komentar %d | baru %d | balas queue %d | skip %d | sendiri %d | duplikat %d | gagal %d',
            $summary['account_seen'],
            $summary['media_seen'],
            $summary['comments_seen'],
            $summary['new'],
            $summary['replied_dispatch'],
            $summary['skipped'],
            $summary['own'],
            $summary['duplicates'],
            $summary['failed'],
        ));

        $setting = Setting::singleton();

        if (! blank($setting->last_polled_at)) {
            $this->info('Polling terakhir: '.$setting->last_polled_at->toDateTimeString());
        }

        return self::SUCCESS;
    }
}
