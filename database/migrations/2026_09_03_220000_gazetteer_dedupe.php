<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One row per place. A row whose name matches another's (trigram >= 0.5)
 * within ~300 m points at that row; the representative is chosen by source
 * (Wikidata, then Overture, then GeoNames, then Who's On First). Nothing is
 * deleted: provenance stays, the search reads representatives only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gazetteer ADD COLUMN IF NOT EXISTS dup_of bigint NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS gazetteer_representatives ON gazetteer (id) WHERE dup_of IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gazetteer DROP COLUMN IF EXISTS dup_of');
    }
};
