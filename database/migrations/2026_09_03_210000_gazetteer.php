<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The gazetteer: every named place with a coordinate we could download.
 *
 * One row per place per source (GeoNames, Wikidata, Overture, Foursquare,
 * Who's On First, Malaysian government lists). Searched by trigram on the
 * normalised name and on alternate names, inside a state or a box, before
 * any live service is asked. `state_code` is stamped from our own polygons,
 * never taken from the source's own idea of the state.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        DB::statement(<<<'SQL'
            CREATE TABLE gazetteer (
                id          bigserial PRIMARY KEY,
                source      varchar(16)  NOT NULL,
                source_id   varchar(80)  NOT NULL,
                name        varchar(250) NOT NULL,
                name_key    varchar(250) NOT NULL,
                alt_names   text         NULL,
                kind        varchar(64)  NULL,
                category    varchar(160) NULL,
                lat         double precision NOT NULL,
                lng         double precision NOT NULL,
                country     char(3)      NULL,
                state_code  varchar(16)  NULL,
                admin_text  varchar(250) NULL,
                population  integer      NULL,
                raw         jsonb        NULL,
                stamped_at  timestamp    NULL,
                created_at  timestamp    NOT NULL DEFAULT now(),
                UNIQUE (source, source_id)
            )
        SQL);

        DB::statement('CREATE INDEX gazetteer_name_key_trgm ON gazetteer USING gin (name_key gin_trgm_ops)');
        DB::statement('CREATE INDEX gazetteer_alt_names_trgm ON gazetteer USING gin (alt_names gin_trgm_ops)');
        DB::statement('CREATE INDEX gazetteer_name_key_btree ON gazetteer (name_key)');
        DB::statement('CREATE INDEX gazetteer_geo ON gazetteer (lat, lng)');
        DB::statement('CREATE INDEX gazetteer_state ON gazetteer (country, state_code)');
        DB::statement('CREATE INDEX gazetteer_unstamped ON gazetteer (id) WHERE stamped_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS gazetteer');
    }
};
