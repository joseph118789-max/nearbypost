<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two sports the taxonomy had no room for.
 *
 * Snooker had nowhere to go at all - it fell into "Others" alongside everything
 * else the list forgot - despite being a sport with a real Malaysian following
 * and a Malaysian world-ranking player.
 *
 * Marathons were folded into Athletics / Running, which is right for a track
 * meet and wrong for a road race. A marathon is a located event people travel
 * to and enter, and readers want to know which ones are coming up near them -
 * so it is marked as carrying a place, which is what lets a race in Penang
 * reach a reader in Penang.
 *
 * Ids are appended rather than inserted in rank order. The scorer matches a
 * sub-category to its parent by name, not by an id range, so nothing depends
 * on them being contiguous - and renumbering would silently re-file every
 * story already classified.
 */
return new class extends Migration
{
    private const ADDITIONS = [
        [
            'sub_category'    => 'Marathons & Road Races',
            'sub_category_ms' => 'Maraton & Larian Jalan Raya',
            'sub_category_zh' => '马拉松与路跑',
            'weight'          => 6.2,
            'gps'             => 'YES',
        ],
        [
            'sub_category'    => 'Snooker & Cue Sports',
            'sub_category_ms' => 'Snuker & Sukan Kiu',
            'sub_category_zh' => '斯诺克与桌球',
            'weight'          => 5.4,
            'gps'             => 'NO',
        ],
    ];

    public function up(): void
    {
        $primary = DB::table('subcategories')
            ->whereRaw("LOWER(primary_category) LIKE '%sport%'")
            ->value('primary_category');

        if (!$primary) {
            return;
        }

        foreach (self::ADDITIONS as $row) {
            $exists = DB::table('subcategories')
                ->whereRaw('LOWER(sub_category) = ?', [mb_strtolower($row['sub_category'])])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('subcategories')->insert($row + [
                'primary_category' => $primary,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subcategories')
            ->whereIn('sub_category', array_column(self::ADDITIONS, 'sub_category'))
            ->delete();
    }
};
