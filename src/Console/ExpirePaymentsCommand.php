<?php

namespace Nous\CryptApiCheckout\Console;

use Illuminate\Console\Command;
use Nous\CryptApiCheckout\CryptApi;

class ExpirePaymentsCommand extends Command
{
    protected $signature = 'cryptapi:expire';

    protected $description = 'Mark crypto payments expired once their window closed';

    public function handle(CryptApi $api): int
    {
        $count = $api->sweepExpired();

        $this->info('expired crypto payments: ' . $count);

        return self::SUCCESS;
    }
}
