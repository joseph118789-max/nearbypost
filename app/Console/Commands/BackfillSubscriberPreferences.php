<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillSubscriberPreferences extends Command
{
    protected $signature = 'subscribers:backfill-preferences 
        {--dry-run : Run without making changes}
        {--category= : Default category to set}
        {--radius= : Default alert radius in km}
        {--frequency= : Default notification frequency}';

    protected $description = 'Backfill subscriber preferences from existing data';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $defaultCategory = $this->option('category');
        $defaultRadius = $this->option('radius');
        $defaultFrequency = $this->option('frequency');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no changes will be made');
        }

        // Get subscribers without preferences (PostgreSQL JSON handling)
        $subscribers = Subscriber::where(function ($query) {
            $query->whereNull('preferred_categories')
                ->orWhereRaw("preferred_categories::text = '[]'")
                ->orWhereNull('alert_radius_km')
                ->orWhereNull('notification_frequency');
        })->get();

        $this->info("Found {$subscribers->count()} subscribers needing preferences");

        if ($subscribers->isEmpty()) {
            $this->info('No subscribers need backfilling');
            return Command::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($subscribers as $subscriber) {
            $changes = [];

            // Build preferences object
            $preferences = [];

            // Set default category if provided
            if ($defaultCategory) {
                $preferences['preferred_categories'] = [$defaultCategory];
                $changes['preferred_categories'] = [$defaultCategory];
            }

            // Set default radius if provided
            if ($defaultRadius) {
                $preferences['alert_radius_km'] = (float) $defaultRadius;
                $changes['alert_radius_km'] = (float) $defaultRadius;
            } elseif (!$subscriber->alert_radius_km) {
                $preferences['alert_radius_km'] = 10.00;
                $changes['alert_radius_km'] = 10.00;
            }

            // Set default frequency if provided
            if ($defaultFrequency) {
                $preferences['notification_frequency'] = $defaultFrequency;
                $changes['notification_frequency'] = $defaultFrequency;
            } elseif (!$subscriber->notification_frequency) {
                $preferences['notification_frequency'] = Subscriber::FREQUENCY_IMMEDIATE;
                $changes['notification_frequency'] = Subscriber::FREQUENCY_IMMEDIATE;
            }

            if (empty($changes)) {
                $skipped++;
                $this->line("Skipped subscriber {$subscriber->id} - no defaults to apply");
                continue;
            }

            if ($dryRun) {
                $this->line("Would update subscriber {$subscriber->id}: " . json_encode($changes));
            } else {
                try {
                    $subscriber->updatePreferences($preferences);
                    $updated++;
                    $this->line("Updated subscriber {$subscriber->id}");
                } catch (\Exception $e) {
                    $this->error("Failed to update subscriber {$subscriber->id}: {$e->getMessage()}");
                    Log::error('Backfill failed', ['id' => $subscriber->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $action = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$action} {$updated} subscribers, skipped {$skipped}");

        Log::info('Subscriber preferences backfilled', [
            'updated' => $updated,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }
}