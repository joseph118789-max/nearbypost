<?php

namespace App\Services\Classification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Let the model add a sub-category when nothing fits - carefully.
 *
 * The owner's instruction after a story about Arsenal's owner buying a baseball
 * team was filed under nothing: "please allow the AI to auto create
 * sub-categories when needed". The taxonomy lists eighteen sports and baseball
 * is not among them, so the model had a real subject and no shelf to put it on.
 *
 * The danger is not that it will refuse to create one. It is that it will
 * create four: Baseball, Base Ball, MLB, and Major League Baseball, over a
 * fortnight, and the taxonomy stops being a way of finding things. Every guard
 * below exists for that.
 *
 *   NOTHING FITS. A proposal is only considered when the model's best existing
 *   sub-category scored below the threshold at which it would have been filed
 *   as "Others" anyway. If something fits, that something is used.
 *
 *   NOT A NEAR-DUPLICATE. Compared against every existing name in the same
 *   category, and against the names of any pending proposals, at the same 0.82
 *   the deduplicator uses on headlines.
 *
 *   A REAL CATEGORY. It has to hang under one of the twenty-one primaries. The
 *   model does not get to invent those.
 *
 *   PROPOSED, NOT CREATED, at first. It is written to the taxonomy so the story
 *   can be filed today, and flagged so a person sees what was added and can
 *   rename or remove it. Silent growth is how a taxonomy rots.
 */
class SubCategoryProposals
{
    /** Near this to something that exists, it IS that thing under another name. */
    private const TOO_SIMILAR = 0.82;

    /** A day's worth. Beyond this something is wrong and should be looked at. */
    private const MAX_PER_DAY = 8;

    /**
     * The categories the model may extend.
     *
     * Light subjects where a missing shelf is a real gap and a wrong one costs
     * little. Everything absent from this list - Property, Markets & Finance,
     * Government & Policy, Health, Crime, Religion, Defence - is left alone: a
     * sub-category there is a statement about how serious business is
     * organised, and that is a person's decision, made in the panel.
     *
     * Short on purpose. Adding to it is one line; removing a bad row from a
     * live taxonomy is not.
     */
    private const OPEN_CATEGORIES = [
        'sports',
        'food & lifestyle',
        'entertainment / arts & culture',
        'travel',
        'automotive',
    ];

    /**
     * The sports that may become a sub-category.
     *
     * Held as a list because the sports people follow are a known set that
     * changes over decades, not something a publisher invents by wording a
     * headline differently. Anything genuinely missing is added by a person in
     * the panel - which is where a judgement about what the product covers
     * belongs.
     */
    private const SPORTS = [
        'archery', 'athletics / running', 'badminton', 'baseball', 'basketball',
        'billiards & snooker', 'bowling', 'boxing', 'combat sports', 'cricket',
        'cycling', 'darts', 'diving', 'equestrian', 'esports', 'fencing',
        'field hockey', 'football / soccer', 'futsal', 'golf', 'gymnastics',
        'handball', 'ice hockey', 'lacrosse', 'racing', 'multi-sport events',
        'netball', 'rowing', 'rugby', 'sailing', 'sepak takraw', 'skateboarding',
        'squash', 'surfing', 'swimming & aquatics', 'table tennis', 'taekwondo',
        'tennis', 'volleyball', 'water polo', 'weightlifting', 'wrestling',
        'american football', 'australian rules football', 'triathlon',
        'climbing', 'karate', 'judo', 'silat', 'hockey', 'polo', 'softball',
        'winter sports', 'skiing', 'figure skating', 'chess',
        'shooting', 'petanque', 'lawn bowls', 'canoeing & kayaking',
        'sport climbing', 'billiards & snooker', 'sepak takraw', 'netball',
    ];

    /**
     * What people call a sport when they are not calling it by its name.
     *
     * A league is not a sport: MLB is baseball, the EPL is football. Left
     * unmapped, each would arrive as its own sub-category and the taxonomy
     * would list the same game three times.
     */
    private const SPORT_ALIASES = [
        'mlb' => 'baseball', 'major league baseball' => 'baseball',
        'base ball' => 'baseball', 'base-ball' => 'baseball',
        'nba' => 'basketball', 'wnba' => 'basketball',
        'nfl' => 'american football', 'gridiron' => 'american football',
        'nhl' => 'ice hockey',
        'epl' => 'football / soccer', 'premier league' => 'football / soccer',
        'la liga' => 'football / soccer', 'serie a' => 'football / soccer',
        'bundesliga' => 'football / soccer', 'champions league' => 'football / soccer',
        'soccer' => 'football / soccer', 'football' => 'football / soccer',
        'motorsports' => 'racing', 'motorsport' => 'racing', 'motor racing' => 'racing',
        'f1' => 'racing', 'formula 1' => 'racing', 'formula one' => 'racing',
        'motogp' => 'racing', 'moto gp' => 'racing', 'rally' => 'racing',
        'rallying' => 'racing', 'nascar' => 'racing', 'indycar' => 'racing',
        'karting' => 'racing', 'go-kart' => 'racing', 'drag racing' => 'racing',
        'endurance racing' => 'racing', 'le mans' => 'racing',
        // Racing without an engine, which has nowhere else to go. Cycling,
        // athletics, swimming, sailing and rowing all keep their own shelves.
        'horse racing' => 'racing', 'harness racing' => 'racing',
        'powerboat racing' => 'racing', 'drone racing' => 'racing',
        'dog racing' => 'racing', 'greyhound racing' => 'racing',
        'track and field' => 'athletics / running', 'athletics' => 'athletics / running',
        'marathon' => 'athletics / running', 'running' => 'athletics / running',
        'snooker' => 'billiards & snooker', 'pool' => 'billiards & snooker',
        'mma' => 'combat sports', 'ufc' => 'combat sports',
        'muay thai' => 'combat sports', 'martial arts' => 'combat sports',
        'swimming' => 'swimming & aquatics', 'aquatics' => 'swimming & aquatics',
        'ping pong' => 'table tennis',
        'takraw' => 'sepak takraw', 'sepaktakraw' => 'sepak takraw',
        'pencak silat' => 'silat', 'seni silat' => 'silat',
        'kayaking' => 'canoeing & kayaking', 'canoeing' => 'canoeing & kayaking',
        'dragon boat' => 'canoeing & kayaking',
        'bowls' => 'lawn bowls', 'boules' => 'petanque',
        'climbing' => 'sport climbing', 'bouldering' => 'sport climbing',
        'tae kwon do' => 'taekwondo', 'archery' => 'archery',
        'ten pin bowling' => 'bowling', 'tenpin bowling' => 'bowling',
        'ten-pin bowling' => 'bowling',
        'olympics' => 'multi-sport events', 'sea games' => 'multi-sport events',
        'asian games' => 'multi-sport events', 'commonwealth games' => 'multi-sport events',
    ];

    /**
     * Consider a proposal. Returns the sub-category row to use, or null to
     * leave the story with whatever the scorer decided.
     */
    public function consider(?array $proposal, string $primaryCategory): ?object
    {
        if (!is_array($proposal)) {
            return null;
        }

        $name = $this->tidy((string) ($proposal['name'] ?? ''));

        // The alias map first. The length guard exists to reject fragments, and
        // "F1" is not a fragment - it is how everybody writes Formula One. Two
        // characters was enough to refuse it before the map was consulted.
        if (isset(self::SPORT_ALIASES[mb_strtolower($name)])) {
            $name = mb_convert_case(self::SPORT_ALIASES[mb_strtolower($name)], MB_CASE_TITLE, 'UTF-8');
        }

        if ($name === '' || mb_strlen($name) < 3 || mb_strlen($name) > 48) {
            return null;
        }

        // It must hang under a primary that already exists. The twenty-one
        // top-level categories are the shape of the product and are not the
        // model's to change.
        $primary = DB::table('subcategories')
            ->whereRaw('lower(primary_category) = ?', [mb_strtolower($primaryCategory)])
            ->value('primary_category');

        if (!$primary) {
            return null;
        }

        // And it must be one of the light categories. A missing shelf under
        // Food is a gap; an invented one under Property or Finance is a claim
        // about serious business that nobody asked the model to make.
        if (!in_array(mb_strtolower($primary), self::OPEN_CATEGORIES, true)) {
            Log::info('Sub-category proposal refused: category is not open to the model', [
                'primary' => $primary, 'proposed' => $proposal['name'] ?? '',
            ]);

            return null;
        }

        // Under Sports, only a real sport, and only under its usual name.
        if (mb_strtolower($primary) === 'sports') {
            $name = $this->resolveSport($name);

            if ($name === null) {
                Log::info('Refused a sub-category that is not a recognised sport', [
                    'proposed' => $proposal['name'] ?? '',
                ]);

                return null;
            }
        }

        if ($existing = $this->findSimilar($name, $primary)) {
            // Not a rejection worth logging loudly: this is the guard working,
            // and the story gets the sub-category it should have had.
            return $existing;
        }

        if ($this->createdToday() >= self::MAX_PER_DAY) {
            Log::warning('Sub-category proposals capped for today', [
                'refused' => $name, 'primary' => $primary,
            ]);

            return null;
        }

        $id = DB::table('subcategories')->insertGetId([
            'primary_category' => $primary,
            'sub_category'     => $name,
            // Mid weight: it has not earned a position and should not outrank
            // the ones a person chose.
            'weight'           => 5.0,
            'gps'              => 'NO',
            'created_by'       => 'ai',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        Log::info('Sub-category created by the classifier', [
            'id' => $id, 'primary' => $primary, 'name' => $name,
            'why' => mb_substr((string) ($proposal['why'] ?? ''), 0, 200),
        ]);

        return DB::table('subcategories')->where('id', $id)->first();
    }

    /**
     * The sport this proposal is really naming, or null if it is not one.
     *
     * An alias is resolved before the list is consulted, so "MLB" becomes
     * "Baseball" and is then found to exist already - which is the outcome
     * wanted, rather than a second row meaning the same game.
     */
    private function resolveSport(string $name): ?string
    {
        $key = mb_strtolower(trim($name));

        if (isset(self::SPORT_ALIASES[$key])) {
            $key = self::SPORT_ALIASES[$key];
        }

        if (in_array($key, self::SPORTS, true)) {
            return mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
        }

        // A near-miss on the list itself: "Table-Tennis", "Weight Lifting".
        foreach (self::SPORTS as $sport) {
            similar_text($key, $sport, $percent);

            if ($percent / 100 >= 0.88) {
                return mb_convert_case($sport, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * Title-cased, trimmed, and without the words a model reaches for when it
     * is naming a category rather than a thing: "News", "Updates", "Related".
     */
    private function tidy(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $name = preg_replace('/\s+(news|updates?|related|general)$/i', '', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s&\/\'-]/u', '', $name);

        return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Something close enough that adding this would be adding it twice.
     *
     * Compared within the category only: "Others" under Sports and "Others"
     * under Weather are both wanted, and a Cricket under Sports has nothing to
     * do with anything under Food.
     */
    private function findSimilar(string $name, string $primary): ?object
    {
        $candidates = DB::table('subcategories')
            ->where('primary_category', $primary)
            ->get(['id', 'primary_category', 'sub_category', 'weight']);

        $a = mb_strtolower($name);

        foreach ($candidates as $row) {
            $b = mb_strtolower($row->sub_category);

            if ($a === $b) {
                return $row;
            }

            // One inside the other: "Baseball" against "Baseball & Softball".
            // Skipped between two recognised sports for the same reason as
            // below - "Hockey" and "Field Hockey" are genuinely different.
            if (!($this->isKnownSport($a) && $this->isKnownSport($b))
                && (str_contains($a, $b) || str_contains($b, $a))) {
                return $row;
            }

            // TWO DIFFERENT SPORTS NEVER MERGE, however alike they read.
            //
            // "baseball" and "basketball" share ba-s-ball and score 84%, which
            // folded baseball into basketball and lost the distinction the
            // owner asked for: "soccer and baseball relevence is too wide, so
            // need to create one". Spelling is not the test - both being a
            // recognised sport, and not the same one, settles it.
            if ($this->isKnownSport($a) && $this->isKnownSport($b)) {
                continue;
            }

            similar_text($a, $b, $percent);

            if ($percent / 100 >= self::TOO_SIMILAR) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Is this exactly one of the sports we recognise, by name or by alias?
     *
     * Exactness is the point. It is what lets two sports that read alike stay
     * apart: both being on the list, and not being the same entry, is a
     * stronger statement than any similarity score.
     */
    private function isKnownSport(string $name): bool
    {
        $key = mb_strtolower(trim($name));
        $key = self::SPORT_ALIASES[$key] ?? $key;

        return in_array($key, self::SPORTS, true);
    }

    private function createdToday(): int
    {
        // Only what the model added. Seeding eighteen sports by hand once
        // exhausted this budget and the next real proposal was refused - a
        // limit on the model should count the model.
        return DB::table('subcategories')
            ->whereDate('created_at', now()->toDateString())
            ->where('created_by', 'ai')
            ->count();
    }
}
