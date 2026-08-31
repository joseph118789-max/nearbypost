<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which country a source belongs to, so the work can be divided by it.
 *
 * The handbook is written per publisher, and writing it is local knowledge: the
 * person who knows that Harian Metro mislabels its timezone is not the person
 * who will know the equivalent about a Vietnamese paper. Grouping sources by
 * country lets that work be handed to whoever can actually do it, and lets an
 * editor open the list without scrolling past thirty publishers in a country
 * they have nothing to do with.
 *
 * Existing rows are assigned from their domain, which is right for the great
 * majority - a .my address is a Malaysian publisher - and wrong for a handful
 * that the panel can correct. Anything unrecognised defaults to Malaysia rather
 * than to null, because that is what this site is and an unassigned source is
 * one nobody owns.
 *
 * An admin may also be given a country, and is then shown only that one.
 */
return new class extends Migration
{
    /** Domain fragments that place a publisher, most specific first. */
    private const HOMES = [
        'aljazeera.com'        => 'QA',
        'scmp.com'             => 'HK',
        'news.cn'              => 'CN',
        'xinhua'               => 'CN',
        'businesstraveller.com' => 'GB',
        '5pillarsuk'           => 'GB',
        'law.asia'             => 'SG',
        'humanresourcesonline' => 'SG',
        'google.com'           => 'MY',
    ];

    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('country', 2)->default('MY');

            $table->index('country');
        });

        Schema::table('admins', function (Blueprint $table) {
            // Null means every country. A country admin sees only theirs.
            $table->string('country', 2)->nullable();
        });

        foreach (DB::table('sources')->get() as $source) {
            $haystack = mb_strtolower(
                (string) ($source->base_url ?? '') . ' ' .
                (string) ($source->rss_url ?? '') . ' ' .
                (string) ($source->index_url ?? '')
            );

            $country = 'MY';

            foreach (self::HOMES as $needle => $code) {
                if (str_contains($haystack, $needle)) {
                    $country = $code;
                    break;
                }
            }

            DB::table('sources')->where('id', $source->id)->update(['country' => $country]);
        }
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('country');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['country']);
            $table->dropColumn('country');
        });
    }
};
