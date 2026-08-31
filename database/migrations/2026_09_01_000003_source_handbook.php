<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Write down what we know about each source, and when to go back to it.
 *
 * Everything learned about a publisher so far has lived in three places that
 * cannot be read together: `fetch_recipe`, which the crawler writes for itself;
 * commit messages; and whoever happened to be looking at the time. So the same
 * discoveries get made twice - that Harian Metro stamps local time and labels
 * it +0000, that Malay Mail refuses an identified crawler, that a Google News
 * link is a redirect rather than an article.
 *
 * Three notes per source, aimed at three different readers:
 *
 *   expect_note   what kind of story comes from here, and roughly how much
 *   extract_note  how to get the text out, and what goes wrong when you do not
 *   tech_note     the specific technical trap: a header, a selector, a quirk
 *
 * The notes are prose on purpose. A human setting the rules has to be able to
 * read them, and so does a model asked to fix the crawler a year from now, and
 * neither is served by a column of flags.
 *
 * And a schedule per source, because a daily paper's property section does not
 * need visiting every fifteen minutes, and visiting it anyway costs requests
 * against publishers who can block us for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->text('expect_note')->nullable();
            $table->text('extract_note')->nullable();
            $table->text('tech_note')->nullable();

            // How often to come back. Null means the tier decides, which is
            // what every source does today.
            $table->smallInteger('fetch_interval_minutes')->nullable();

            // For a source read once a day: the hour to read it, 0-23, Kuala
            // Lumpur time. Null means any hour the interval allows.
            $table->smallInteger('fetch_at_hour')->nullable();

            $table->timestamp('notes_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn([
                'expect_note', 'extract_note', 'tech_note',
                'fetch_interval_minutes', 'fetch_at_hour', 'notes_updated_at',
            ]);
        });
    }
};
