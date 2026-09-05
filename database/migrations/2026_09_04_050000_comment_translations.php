<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comments are shown as written; a reader may ask for a translation, made once per comment and language and kept here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_comment_translations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('comment_id');
            $t->string('locale', 5);
            $t->string('source_lang', 8)->nullable();
            $t->text('body');
            $t->string('model', 64)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['comment_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_comment_translations');
    }
};
