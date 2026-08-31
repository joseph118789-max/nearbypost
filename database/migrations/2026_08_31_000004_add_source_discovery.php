<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the source registry learn.
 *
 * Sources were a fixed list someone typed. That caps coverage at whatever was
 * known on the day it was written, which is why whole sub-categories stayed
 * empty: nine general news feeds simply do not carry badminton, tennis or
 * automotive coverage in any depth, however well they are classified.
 *
 * These columns let a source be recorded with its provenance, so a publisher
 * discovered automatically can be told apart from one that was chosen, and a
 * section feed can be tied to the publication it belongs to.
 *
 * discovery_status:
 *   manual     - entered by a person
 *   candidate  - seen in the wild, not yet probed or not yet trusted
 *   verified   - a working feed was found and validated
 *   rejected   - probed and found unusable; do not retry endlessly
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('discovery_status', 20)->default('manual')->after('priority_tier');
            $table->string('discovered_from')->nullable()->after('discovery_status');
            $table->unsignedBigInteger('parent_source_id')->nullable()->after('discovered_from');
            $table->string('section', 60)->nullable()->after('parent_source_id');

            // How often this publisher has been seen in the wild. A name that
            // keeps recurring is worth probing; one seen once may be noise.
            $table->unsignedInteger('sightings')->default(0)->after('section');
            $table->timestamp('last_probed_at')->nullable()->after('sightings');
            $table->text('probe_notes')->nullable()->after('last_probed_at');

            $table->index(['discovery_status', 'sightings']);
        });

        // Everything that already exists was put there by a person.
        \Illuminate\Support\Facades\DB::table('sources')->update(['discovery_status' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['discovery_status', 'sightings']);
            $table->dropColumn([
                'discovery_status', 'discovered_from', 'parent_source_id',
                'section', 'sightings', 'last_probed_at', 'probe_notes',
            ]);
        });
    }
};
