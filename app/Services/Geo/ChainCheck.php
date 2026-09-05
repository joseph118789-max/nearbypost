<?php

namespace App\Services\Geo;

use App\Services\Geo\Boundaries\Iso3166;
use Illuminate\Support\Facades\DB;

/**
 * Does a finished place chain contradict itself?
 *
 * The owner, 4 Sep 2026, looking at the live feed: "Shenzen surely not Kuala
 * Lumpur. Meaning after the process of identifying where is the location. A
 * validity check on the location chain is needed."
 *
 * WHY THE BOUNDARY LAYER DID NOT CATCH IT. BoundaryCheck asks whether the
 * POINT falls inside the country the text claims. On the story that reached the
 * front page it did, perfectly: China Press is a Malaysian paper, so the
 * masthead prior claimed Malaysia; the model extracted the place as the Chinese
 * characters for Shenzhen; the Malaysian Nominatim was asked for those
 * characters and answered with a building in Kuala Lumpur. Point in Malaysia,
 * claim Malaysia, verdict inside. Every check passed and the answer was 2,900
 * kilometres wrong.
 *
 * Nothing had asked the other question: is there any such place in Malaysia at
 * all? This asks it.
 *
 * THE TEST, AND WHY IT IS SHAPED THIS WAY. A name is only allowed to overrule
 * the placement when it is unambiguous in both directions:
 *
 *   - the narrowest named part is a major settlement somewhere else, and
 *   - no place of that name exists in the country we placed it in.
 *
 * Both halves are needed. Without the first, every small reused name would
 * raise an objection. Without the second, "Victoria, Labuan" would be refused -
 * Victoria is a capital city in the Seychelles, and it is also, genuinely, the
 * town in Labuan. The gazetteer knows the Labuan one, so the chain stands. It
 * knows no Malaysian Shenzhen, so that chain falls.
 *
 * WHAT IT DOES NOT DO. It never moves a pin and never guesses a better answer.
 * It returns a refusal with its reason, and the caller decides - which is
 * always either "geocode it again, now knowing the country the name implies" or
 * "put it in front of a person". Silently relocating a story to Shenzhen on the
 * strength of a name would be the same class of mistake in the other direction.
 */
final class ChainCheck
{
    /**
     * A settlement below this cannot arbitrate a country on its name alone.
     *
     * Measured against this table, not chosen: the names that reach here and
     * cause trouble are the ones any reader would recognise - Shenzhen,
     * Bangkok, Kathmandu. Below a couple of hundred thousand, name collisions
     * between countries are ordinary and mean nothing.
     */
    private const MIN_POPULATION = 200000;

    /**
     * @return array{ok: bool, reason: ?string, name: ?string, belongs_to: array<int, string>, implies: ?string}
     */
    public function verify(?string $chain, ?string $iso3): array
    {
        $pass = ['ok' => true, 'reason' => null, 'name' => null, 'belongs_to' => [], 'implies' => null];

        if ($chain === null || trim($chain) === '' || $iso3 === null) {
            return $pass;   // nothing placed, so nothing to contradict
        }

        $iso2 = Iso3166::iso2($iso3);

        if ($iso2 === null) {
            return $pass;
        }

        $head = $this->head($chain);

        if ($head === '' || mb_strlen($head) < 4) {
            return $pass;   // too short to be a settlement name worth trusting
        }

        $elsewhere = $this->majorSettlementCountries($head);

        if ($elsewhere === []) {
            return $pass;   // not a name the world knows; not our business
        }

        if (in_array(strtoupper($iso2), $elsewhere, true)) {
            return $pass;   // the big city of this name IS in the placed country
        }

        // The second half of the test, and the one that keeps honest chains.
        // A name that exists at all in the placed country is a local place
        // sharing a famous name, which is common and fine.
        if ($this->existsInCountry($head, $iso3)) {
            return $pass;
        }

        return [
            'ok'         => false,
            'reason'     => sprintf(
                '"%s" is a city in %s and there is no place of that name in %s',
                $head,
                implode('/', $elsewhere),
                $iso2
            ),
            'name'       => $head,
            'belongs_to' => $elsewhere,
            'implies'    => Iso3166::iso3($elsewhere[0]) ?: null,
        ];
    }

    /**
     * Which country should the map be asked about, when the masthead is wrong?
     *
     * The masthead prior is right nearly always and is why local stories work:
     * a Malaysian paper writes about Malaysia. But some Malaysian papers write
     * about China constantly, and China Press writing the Chinese characters
     * for Shenzhen got the Malaysian map asked for a Chinese city - which
     * politely answered with a building in Kuala Lumpur.
     *
     * By the time this is asked the name has been through LatinName, so the
     * characters have already become "Shenzhen" and the world gazetteer can
     * recognise it. Returns null in every ordinary case, which is the point:
     * it speaks only when the name could not possibly be in the home country.
     *
     * @return string|null ISO2 of the country the NAME insists on
     */
    public function impliedHome(?string $placeName, ?string $homeIso2): ?string
    {
        if ($placeName === null || $homeIso2 === null) {
            return null;
        }

        $iso3 = Iso3166::iso3($homeIso2);

        if ($iso3 === null) {
            return null;
        }

        $v = $this->verify($placeName, $iso3);

        return $v['ok'] ? null : ($v['belongs_to'][0] ?? null);
    }

    /** The narrowest named thing: what the chain is actually about. */
    private function head(string $chain): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $chain)), fn ($p) => $p !== ''));

        return $parts[0] ?? '';
    }

    /** @return array<int, string> ISO2 codes of countries with a major city of this name */
    private function majorSettlementCountries(string $name): array
    {
        $key = mb_strtolower($name);

        return DB::table('world_cities')
            ->where('name_key', $key)
            ->where('population', '>=', self::MIN_POPULATION)
            ->orderByDesc('population')
            ->pluck('country_code')
            ->map(fn ($c) => strtoupper((string) $c))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Is there ANY place of this name in that country? The gazetteer holds
     * 1.26 million rows and is the reason this check can be strict without
     * being wrong: it is what vouches for the Labuan Victoria.
     */
    private function existsInCountry(string $name, string $iso3): bool
    {
        $key = mb_strtolower($name);

        return DB::table('gazetteer')
                ->where('name_key', $key)
                ->where('country', $iso3)
                ->exists()
            || DB::table('world_cities')
                ->where('name_key', $key)
                ->where('country_code', Iso3166::iso2($iso3))
                ->exists();
    }
}
