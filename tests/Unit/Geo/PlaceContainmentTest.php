<?php

namespace Tests\Unit\Geo;

use App\Services\Geo\PlaceContainment;
use PHPUnit\Framework\TestCase;

/**
 * Every case here is a real pair from the 116-name sweep of 2 September 2026.
 * The "wrong" ones are what a loosened search actually returned.
 */
class PlaceContainmentTest extends TestCase
{
    /** @dataProvider inside */
    public function test_an_answer_inside_the_named_place_holds(string $asked, string $answer, ?string $state): void
    {
        $this->assertTrue(PlaceContainment::holds($asked, $answer, $state));
    }

    public static function inside(): array
    {
        return [
            'town appears'           => ['Kuala Sungai Mersing, Mersing, Johor', 'Sungai Mersing, Mersing, Johor', 'Johor'],
            'state via alias'        => ['Penang Port limits, Penang', 'Penang Port, George Town, Pulau Pinang', 'Pulau Pinang'],
            'district appears'       => ['Jalan Kampung Kaman, Bau, Sarawak', 'Kampung Kaman, Bau, Sarawak', 'Sarawak'],
            'state only, no rival'   => ['Batu Laut Beach, Sepang, Selangor', 'Pantai Batu Laut, Kampung Batu Laut, Kuala Langat, Selangor', 'Selangor'],
            'KL named and answered'  => ['Kuala Lumpur Court Complex, Kuala Lumpur', 'Mahkamah Kuala Lumpur, Kuala Lumpur', 'Kuala Lumpur'],
            'state from provider'    => ['Sibu Jaya bypass, Sibu, Sarawak', 'Sibu Jaya', 'Sarawak'],
        ];
    }

    /** @dataProvider outside */
    public function test_an_answer_somewhere_else_is_refused(string $asked, string $answer, ?string $state): void
    {
        $this->assertFalse(PlaceContainment::holds($asked, $answer, $state));
    }

    public static function outside(): array
    {
        return [
            'court in another city'  => ['Mahkamah Majistret, Jasin, Melaka', 'Mahkamah Majistret, Jalan Raja Laut, Chow Kit, Bukit Bintang, Kuala Lumpur', 'Kuala Lumpur'],
            'kampung 1,600 km away'  => ['Kampung Tanduo, Lahad Datu, Sabah', 'Kampung Namek Tandop, Sik, Kedah', 'Kedah'],
            'shares two words'       => ['Kampung Ayer Merbau, Jasin, Melaka', 'Jalan Merbau, Kampung Ayer Itam, Serdang, Bandar Baharu, Kedah', 'Kedah'],
            'burger not car park'    => ['Abe Yie Parking, Rantau Panjang, Pasir Mas, Kelantan', 'Abe Yie Burger, Jerantut, Pahang', 'Pahang'],
            'right road, wrong state' => ['North-South Expressway (Plus), KM328.3, Tapah, Perak', 'Lebuhraya Utara-Selatan, Tebrau, Johor Bahru, Johor', 'Johor'],
            'no anchor to hold to'   => ['Mahkamah Majistret di sini', 'Mahkamah Majistret, Butterworth, Pulau Pinang', 'Pulau Pinang'],
        ];
    }

    public function test_a_word_inside_another_word_does_not_count(): void
    {
        // "Perak" inside "Perakaunan" is not the state.
        $this->assertFalse(PlaceContainment::holds('Pejabat, Ipoh, Perak', 'Jabatan Perakaunan Negara, Putrajaya', 'Putrajaya'));
    }
}
