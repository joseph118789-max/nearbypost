<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person reads a new contributor's first story before anyone else does.
 *
 * The AI review is a quality filter: it refuses advertising, rants and empty
 * test posts, and it will be fooled by someone who sets out to fool it. Until
 * now anything it passed was live immediately and could only be taken down
 * afterwards, which means the first time a stranger tries something, it works.
 *
 * So a contributor is not trusted until an editor has approved one of their
 * posts by hand. Everything they send before that waits in a queue. Once
 * trusted, their posts publish the moment the AI passes them, because the point
 * is to look at a new person's work rather than to read the same regular's copy
 * for ever.
 *
 * `trusted_at` is the whole mechanism, and it is on the contributor rather than
 * on the post: trust is a judgement about a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trusted_at')->nullable();
            $table->unsignedBigInteger('trusted_by')->nullable();

            $table->index('trusted_at');
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // The queue is read by review_status, and it is read on every admin
            // page load while the rest of the table is 11,000 gathered articles
            // that will never match.
            $table->index(['review_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['review_status', 'created_at']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['trusted_at']);
            $table->dropColumn(['trusted_at', 'trusted_by']);
        });
    }
};
