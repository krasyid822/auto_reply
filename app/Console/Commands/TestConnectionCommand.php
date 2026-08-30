<?php

namespace App\Console\Commands;

use App\Models\InstagramAccount;
use App\Services\Instagram\InstagramApiException;
use App\Services\Instagram\InstagramClient;
use Illuminate\Console\Command;

class TestConnectionCommand extends Command
{
    protected $signature = 'instagram:test-connection {--user= : IG user id tertentu (opsional; default semua akun)}';

    protected $description = 'Uji token & akses akun Instagram yang tersimpan.';

    public function handle(): int
    {
        $query = InstagramAccount::query()->orderBy('username');

        if ($this->option('user') !== null) {
            $query->where('ig_user_id', $this->option('user'));
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->error('Belum ada akun Instagram tersimpan. Hubungkan lewat dashboard ("Connect with Facebook") atau isi lewat instagram_accounts.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            $this->line(sprintf(
                '[@%s] Token: %s | expire: %s',
                $account->username,
                $account->token_type ?? 'user',
                $account->token_expires_at?->toDateTimeString() ?? 'no-expiry',
            ));

            try {
                $info = (new InstagramClient($account))->accountInfo();
                $this->info('OK — terhubung sebagai @'.$info['username'].' (IG user id '.$info['id'].')');
            } catch (InstagramApiException $e) {
                $failures++;
                $this->error('Gagal: '.$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
