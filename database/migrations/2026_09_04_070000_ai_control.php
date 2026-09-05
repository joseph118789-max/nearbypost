<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI control panel (owner, 4 Sep 2026): which model does which job, who
 * helps when it is down or the queue is long, and what each model is told
 * differently. Keys never live here - only the NAME of the config entry that
 * holds the key (config/services.php › ai.keys, filled from .env).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $t) {
            $t->string('key', 32)->primary();               // deepseek, openai, kimi, gemini, claude
            $t->string('name', 60);
            $t->string('base_url', 200);                     // OpenAI-compatible /chat/completions endpoint base
            $t->string('model', 80);
            $t->string('vision_model', 80)->nullable();      // model used when a photo is attached
            $t->string('key_name', 40);                      // config('services.ai.keys.<key_name>')
            $t->boolean('enabled')->default(true);
            $t->decimal('price_in', 8, 4)->default(0);       // USD per 1M input tokens
            $t->decimal('price_out', 8, 4)->default(0);      // USD per 1M output tokens
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('cooldown_until')->nullable();
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('ai_tasks', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->string('name', 80);
            $t->string('description', 300)->nullable();
            $t->string('primary_provider', 32)->default('deepseek');
            $t->string('helper_provider', 32)->nullable();
            $t->string('second_helper', 32)->nullable();
            $t->string('mode', 16)->default('failover');    // primary | failover | share
            $t->unsignedInteger('share_threshold')->default(50);   // share: hand every other call to the helper when the backlog is above this
            $t->boolean('enabled')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('ai_task_prompts', function (Blueprint $t) {
            $t->id();
            $t->string('task_key', 40);
            $t->string('provider_key', 32);
            $t->text('addendum');                            // appended to the prompt for this model only
            $t->timestamps();
            $t->unique(['task_key', 'provider_key']);
        });

        Schema::create('ai_call_log', function (Blueprint $t) {
            $t->id();
            $t->string('task_key', 40);
            $t->string('provider_key', 32);
            $t->string('model', 80)->nullable();
            $t->boolean('ok');
            $t->unsignedInteger('latency_ms')->default(0);
            $t->unsignedInteger('tokens_in')->nullable();
            $t->unsignedInteger('tokens_out')->nullable();
            $t->decimal('cost', 10, 6)->nullable();
            $t->string('error', 300)->nullable();
            $t->string('reason', 40)->nullable();             // why this provider got the call: primary | failover | share | only
            $t->timestamp('created_at');
            $t->index(['created_at']);
            $t->index(['task_key', 'created_at']);
            $t->index(['provider_key', 'created_at']);
        });

        $now = now();
        DB::table('ai_providers')->insert([
            ['key' => 'deepseek', 'name' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'vision_model' => 'deepseek-v4-flash-vision-exp', 'key_name' => 'deepseek', 'enabled' => true, 'price_in' => 0.27, 'price_out' => 1.10, 'notes' => 'The model everything ran on until 4 Sep 2026. Cheapest; no vision except the experimental v4 flash model.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'deepseek-reasoner', 'name' => 'DeepSeek Reasoner', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-reasoner', 'vision_model' => null, 'key_name' => 'deepseek', 'enabled' => false, 'price_in' => 0.55, 'price_out' => 2.19, 'notes' => 'Slower, thinks first. For the bench, not for the pipeline.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-5-nano', 'vision_model' => 'gpt-5-nano', 'key_name' => 'openai', 'enabled' => true, 'price_in' => 0.05, 'price_out' => 0.40, 'notes' => 'gpt-5-nano reads photos. Needs OPENAI_API_KEY in .env.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'kimi', 'name' => 'Kimi (Moonshot)', 'base_url' => 'https://api.moonshot.ai/v1', 'model' => 'kimi-k2-turbo-preview', 'vision_model' => null, 'key_name' => 'kimi', 'enabled' => false, 'price_in' => 0.60, 'price_out' => 2.50, 'notes' => 'Strong Chinese. Needs MOONSHOT_API_KEY in .env, then switch on.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'gemini', 'name' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.5-flash', 'vision_model' => 'gemini-2.5-flash', 'key_name' => 'gemini', 'enabled' => false, 'price_in' => 0.30, 'price_out' => 2.50, 'notes' => 'Reads photos. Needs GEMINI_API_KEY in .env, then switch on.', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'claude', 'name' => 'Anthropic Claude', 'base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-haiku-4-5', 'vision_model' => 'claude-haiku-4-5', 'key_name' => 'claude', 'enabled' => false, 'price_in' => 1.00, 'price_out' => 5.00, 'notes' => 'Reads photos. Needs ANTHROPIC_API_KEY in .env, then switch on.', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $tasks = [
            ['enrich', 'Classify and place a story', 'The main pipeline: is it news, what is it about, where did it happen, our headline and summary, the three translations.', 'deepseek', 'openai', 10],
            ['newsworthiness', 'Check a reader post', 'The automatic news check on a reader post before a person or the trust rule publishes it.', 'deepseek', 'openai', 20],
            ['community_review', 'Vet a community report', 'Community Reports moderation: publish, hold or reject; the edited headline and body; fact guard.', 'deepseek', 'openai', 30],
            ['comment_moderation', 'Moderate a comment', 'Hide abuse, spam and personal data in comments.', 'deepseek', 'openai', 40],
            ['comment_translate', 'Translate a comment', 'On demand, when a reader presses Translate under a comment.', 'deepseek', 'openai', 50],
            ['translate', 'Translate headlines and summaries', 'The three reading languages for gathered stories.', 'deepseek', 'openai', 60],
            ['retitle', 'Write our own headline', 'The backfill that rewrites publishers\' headlines in our words.', 'deepseek', 'openai', 70],
            ['dedupe', 'Confirm duplicate stories', 'Is this pair the same story? Yes or no, across languages.', 'deepseek', 'openai', 80],
            ['dedupe_live', 'Confirm duplicates on the live feed', 'The same question, asked of stories already served.', 'deepseek', 'openai', 90],
            ['place_names', 'Name places for the geocoder', 'Latin spellings of Chinese and Tamil place names; stand-in places from the story; coordinates as a last resort.', 'deepseek', 'openai', 100],
            ['outlet_finder', 'Find a publisher', 'Which outlet a reader\'s pasted link or name refers to.', 'deepseek', 'openai', 110],
            ['rule_suggester', 'Suggest a rule from corrections', 'Turns a run of similar corrections into a draft rule.', 'deepseek', null, 120],
            ['taxonomy_translate', 'Translate the taxonomy', 'Category and sub-category names in the reading languages.', 'deepseek', null, 130],
            ['marketplace_vetting', 'Vet a marketplace listing', 'Not in use yet: the check on a business or service listing when the Marketplace opens.', 'deepseek', 'openai', 140],
        ];

        foreach ($tasks as [$key, $name, $desc, $primary, $helper, $sort]) {
            DB::table('ai_tasks')->insert(['key' => $key, 'name' => $name, 'description' => $desc, 'primary_provider' => $primary, 'helper_provider' => $helper,
                'mode' => 'failover', 'share_threshold' => 50, 'enabled' => true, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['ai_call_log', 'ai_task_prompts', 'ai_tasks', 'ai_providers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
