<?php

namespace App\Console\Commands;

use App\Services\Ai\AiSpend;
use Illuminate\Console\Command;

/**
 * Write down what is left, so tomorrow's difference is a real spend figure.
 *
 * Estimated cost is worked out from tokens and a price list, and a price list
 * goes out of date without telling anyone. The balance falling is what actually
 * happened. Recording it daily is the only way to have both, and to notice when
 * they stop agreeing.
 *
 * Run daily from cron, shortly after midnight.
 */
class RecordAiBalance extends Command
{
    protected $signature = 'ai:balance {--quiet-ok : Say nothing when it worked}';

    protected $description = "Record the AI provider's remaining balance";

    public function handle(AiSpend $spend): int
    {
        $balance = $spend->record();

        if ($balance === null) {
            $this->error('Could not read the balance. Nothing recorded.');

            return 1;
        }

        if (!$this->option('quiet-ok')) {
            $this->info(sprintf('Recorded: %.2f USD remaining.', $balance));

            $left = $spend->daysLeft($balance);

            if ($left !== null) {
                $this->line("At the last seven days' rate that is about {$left} days.");
            }
        }

        return 0;
    }
}
