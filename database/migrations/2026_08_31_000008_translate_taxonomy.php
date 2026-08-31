<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category names in the reading languages.
 *
 * The stories were translated but the taxonomy around them was not, so a Malay
 * or Chinese reader saw translated headlines filed under English labels -
 * "Crime & Safety", "Government & Policy" - in the sidebar, on every card and in
 * every page title.
 *
 * The names live beside the taxonomy rather than in language files because that
 * is where the taxonomy already lives: one row per category carrying its weight,
 * its GPS flag and now its names. A new category cannot then be added with its
 * translations forgotten.
 *
 * The 21 primaries are seeded here by hand. They are few, they are load-bearing
 * - they appear in page titles and URLs - and they are worth getting exactly
 * right rather than generating. The 156 sub-categories are filled by
 * categories:translate, which is a separate, re-runnable step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_ms')->nullable()->after('name');
            $table->string('name_zh')->nullable()->after('name_ms');
        });

        Schema::table('subcategories', function (Blueprint $table) {
            $table->string('sub_category_ms')->nullable()->after('sub_category');
            $table->string('sub_category_zh')->nullable()->after('sub_category_ms');
        });

        // [id, Malay, Chinese]
        $names = [
            [1,  'Hartanah', '房地产'],
            [2,  'Makanan & Gaya Hidup', '美食与生活'],
            [3,  'Infrastruktur', '基础设施'],
            [4,  'Pengangkutan & Mobiliti', '交通出行'],
            [5,  'Jenayah & Keselamatan', '罪案与安全'],
            [6,  'Alam Sekitar', '环境'],
            [7,  'Pendidikan', '教育'],
            [8,  'Kesihatan', '健康'],
            [9,  'Pelancongan', '旅游'],
            [10, 'Hiburan, Seni & Budaya', '娱乐与艺术文化'],
            [11, 'Kebajikan & NGO', '慈善与公益'],
            [12, 'Cuaca', '天气'],
            [13, 'Pertahanan & Ketenteraan', '国防与军事'],
            [14, 'Pasaran & Kewangan', '市场与金融'],
            [15, 'Perniagaan & Korporat', '商业与企业'],
            [16, 'Teknologi & Digital', '科技与数码'],
            [17, 'Automotif', '汽车'],
            [18, 'Kerajaan & Dasar', '政府与政策'],
            [19, 'Sains', '科学'],
            [20, 'Sukan', '体育'],
            [21, 'Agama', '宗教'],
        ];

        foreach ($names as [$id, $ms, $zh]) {
            DB::table('categories')->where('id', $id)->update([
                'name_ms'    => $ms,
                'name_zh'    => $zh,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropColumn(['sub_category_ms', 'sub_category_zh']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name_ms', 'name_zh']);
        });
    }
};
