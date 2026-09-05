<?php

namespace Tests\Unit\Geo;

use App\Services\Geo\CountryCode;
use App\Services\Geo\PlaceScale;
use PHPUnit\Framework\TestCase;

class CountryCodeTest extends TestCase
{
    /** @dataProvider named */
    public function test_a_country_written_in_any_of_the_sites_languages_is_recognised(string $place, string $code): void
    {
        $this->assertSame($code, CountryCode::forPlace($place));
    }

    public static function named(): array
    {
        return [
            // The one that put a Moto3 win at the Czech embassy in KL.
            'Malay word order'      => ['Litar Brno, Republik Czech', 'cz'],
            'English'               => ['Brno Circuit, Czech Republic', 'cz'],
            'prefix stripped'       => ['Seoul, Republic of Korea', 'kr'],
            'Malay prefix'          => ['Bandar Seri Begawan, Negara Brunei', 'bn'],
            'kingdom of'            => ['Bangkok, Kingdom of Thailand', 'th'],
            'Chinese'               => ['布拉格, 捷克', 'cz'],
            'trailing full stop'    => ['Kathmandu, Nepal.', 'np'],
            'Malaysia by default'   => ['Tasik Kenyir, Terengganu', 'my'],
            'Malaysia spelled out'  => ['Kuching, Sarawak, Malaysia', 'my'],
        ];
    }

    public function test_a_country_is_not_a_landmark_in_any_spelling(): void
    {
        foreach (['Republik Czech', 'Czech Republic', 'Republic of Korea', 'Negara Brunei', 'Jepun', '日本'] as $c) {
            $this->assertTrue(PlaceScale::isBareCountry($c), "{$c} should count as a bare country");
        }

        $this->assertFalse(PlaceScale::isBareCountry('Litar Brno'));
    }

    public function test_the_two_lists_do_not_disagree(): void
    {
        // Everything PlaceScale calls a country, CountryCode can name.
        foreach (PlaceScale::COUNTRIES as $name) {
            $this->assertNotNull(CountryCode::codeFor($name), "{$name} is in PlaceScale but not in CountryCode");
        }
    }
}
