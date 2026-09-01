<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The resource centre: everything the model is told, and everything that says
 * whether it is getting it right.
 *
 * Until now roughly three fifths of the instruction lived in a PHP heredoc, the
 * newsroom could edit the remaining fifth, and nothing anywhere measured
 * whether an answer was correct. These tables move the policy out of the code,
 * give the domain knowledge somewhere to live, and make a model's accuracy a
 * number rather than an impression.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The Playbook: the prompt's policy, as editable text ────────────
        //
        // Only the reasoning lives here. The output contract - the JSON shape,
        // the category lists - stays in code, because it has to stay in step
        // with the parser that reads the reply, and a newsroom editing it by
        // hand would break classification silently.
        Schema::create('playbook_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->text('why')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Every edit kept, so a change in the site's judgement can be read back
        // months later - which is the question asked whenever the feed starts
        // behaving differently and nobody remembers what was changed.
        Schema::create('playbook_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_section_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // ── The Briefing: what a model trained elsewhere will not know ─────
        Schema::create('briefing_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 80);
            $table->string('expansion', 200);
            // The part that earns its place. Knowing MB means Menteri Besar is
            // trivia; knowing that a story about the MB of Kedah is a Kedah
            // story regardless of its dateline is the classification.
            $table->text('implication')->nullable();
            $table->string('kind', 20)->default('term');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['term', 'kind']);
        });

        // ── The Bench: stories with an answer a person has confirmed ───────
        Schema::create('bench_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_item_id');
            $table->string('title', 500);
            $table->boolean('expect_keep')->default(true);
            $table->string('expect_category', 60)->nullable();
            $table->string('expect_sub', 80)->nullable();
            // Null place and expect_nowhere = true are different claims: the
            // first says nobody has decided, the second says the correct answer
            // IS nowhere - which is the answer this site gets wrong most often
            // and therefore the one most worth testing.
            $table->string('expect_place', 200)->nullable();
            $table->boolean('expect_nowhere')->default(false);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamps();
            $table->unique('news_item_id');
        });

        Schema::create('bench_runs', function (Blueprint $table) {
            $table->id();
            $table->string('adapter', 40);
            $table->string('model', 80);
            $table->string('prompt_version', 20)->nullable();
            $table->unsignedSmallInteger('items')->default(0);
            $table->json('scores')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('bench_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bench_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bench_item_id');
            $table->boolean('got_keep')->nullable();
            $table->string('got_category', 60)->nullable();
            $table->string('got_sub', 80)->nullable();
            $table->string('got_place', 200)->nullable();
            $table->json('correct')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index('bench_item_id');
        });

        // ── The Correction Log: every human fix, kept ──────────────────────
        //
        // The removals table records only deletions, and has never had a row.
        // Most corrections are not deletions: a story in the wrong place, under
        // the wrong heading, refused when it should have been kept. Each one is
        // a fact about the job, and each was previously spent once and thrown
        // away.
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_item_id')->nullable();
            $table->string('title', 500)->nullable();
            $table->string('field', 20);
            $table->text('ai_answer')->nullable();
            $table->text('correct_answer')->nullable();
            $table->text('reason')->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->boolean('used_in_bench')->default(false);
            $table->timestamps();
            $table->index(['field', 'created_at']);
        });

        // ── Source strategy the extractor obeys ────────────────────────────
        //
        // Forty-three sources have handbook notes explaining how to read them,
        // written for people, and the pipeline reads none of it. One structured
        // field turns the most important sentence in each note into something
        // the code can act on.
        Schema::table('sources', function (Blueprint $table) {
            $table->string('extraction_strategy', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('extraction_strategy');
        });

        Schema::dropIfExists('corrections');
        Schema::dropIfExists('bench_results');
        Schema::dropIfExists('bench_runs');
        Schema::dropIfExists('bench_items');
        Schema::dropIfExists('briefing_terms');
        Schema::dropIfExists('playbook_revisions');
        Schema::dropIfExists('playbook_sections');
    }
};
