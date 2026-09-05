<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

/**
 * The edge of NearbyPost.
 *
 * A reader who taps "Contact on WhatsApp" leaves. Everything after that happens
 * between two people, and the site is not a party to it (§1, §19.3). This class
 * builds the link, records that it was opened, and refuses to build anything
 * that is not a WhatsApp conversation.
 *
 * ⛔ THE NUMBER IS REBUILT FROM DIGITS, NEVER PASSED THROUGH.
 *
 * Spec §29: "Validate redirect/WhatsApp URLs to prevent open redirects and
 * malicious schemes." A provider types their own number, so it is attacker-
 * controlled text. Anything that is not a digit is discarded and the URL is
 * assembled from what is left - so a stored value of
 * `60123456789?text=…&redirect=evil` cannot smuggle a parameter, and
 * `javascript:` cannot survive a strip that keeps only 0-9.
 */
class WhatsAppContact
{
    /** wa.me wants digits only, country code first, no plus and no spaces. */
    private const MIN_DIGITS = 7;
    private const MAX_DIGITS = 15;   // E.164's own limit

    /**
     * The link, or null when there is no usable number.
     *
     * Null is a real answer: the listing then shows no contact button rather
     * than a button that goes nowhere.
     */
    public static function link(?string $countryCode, ?string $number, string $listingTitle, string $publicUrl): ?string
    {
        $digits = self::digits($countryCode, $number);

        if ($digits === null) {
            return null;
        }

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode(
            self::message($listingTitle, $publicUrl)
        );
    }

    /**
     * The first message, prefilled.
     *
     * ⛔ The listing title and a PUBLIC url, and nothing else. Spec §14.3:
     * "Include the listing title and a stable public NearbyPost URL, not
     * private user data." The reader's name, their location and their account
     * are none of the provider's business until the reader chooses to say so.
     */
    public static function message(string $listingTitle, string $publicUrl): string
    {
        // Quotation marks around a title that may itself contain them would
        // read badly; the curly pair used here cannot be confused with the
        // title's own straight quotes.
        return sprintf(
            'Hi, I found your listing on NearbyPost: “%s”. Is it still available? %s',
            trim(mb_substr($listingTitle, 0, 120)),
            $publicUrl
        );
    }

    /**
     * Record that somebody left for WhatsApp.
     *
     * ⛔ Called AFTER the reader confirms the interstitial, never on page load.
     * Counting impressions as contacts would inflate a provider's numbers with
     * people who only scrolled past.
     */
    public static function record(int $listingId, ?int $providerId, ?int $userId, ?string $ip, ?string $country): void
    {
        DB::table('listing_contact_events')->insert([
            'listing_id'          => $listingId,
            'provider_profile_id' => $providerId,
            'channel'             => 'whatsapp',
            'user_id'             => $userId,
            // ⛔ Hashed with the app key, not stored raw. It exists to spot a
            // thousand clicks from one place, which a hash does just as well.
            'ip_hash'             => $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
            'country_code'        => $country === null ? null : strtoupper(substr($country, 0, 2)),
            'opened_at'           => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /**
     * How many people opened WhatsApp from this listing, over a window.
     *
     * A count of openings. Not leads, not enquiries, not sales - the wording
     * matters because a provider will read whatever word is used as a promise.
     */
    public static function openedCount(int $listingId, int $days = 30): int
    {
        return (int) DB::table('listing_contact_events')
            ->where('listing_id', $listingId)
            ->where('opened_at', '>', now()->subDays($days))
            ->count();
    }

    /**
     * Digits only, country code first.
     *
     * @return ?string  null when what is left could not be a phone number
     */
    private static function digits(?string $countryCode, ?string $number): ?string
    {
        $cc  = preg_replace('/\D+/', '', (string) $countryCode);
        $num = preg_replace('/\D+/', '', (string) $number);

        if ($num === '') {
            return null;
        }

        // ⛔ THE NUMBER MAY ALREADY CARRY ITS COUNTRY CODE. A provider given a
        // "country code" box and a "number" box types "+60 12 345 6789" into
        // the second one about as often as not. Prepending 60 to that produced
        // wa.me/6060123456789 - a link that looks right, validates, and reaches
        // nobody. The subscriber part has to be long enough that this is not a
        // domestic number which happens to begin with the same digits.
        if ($cc !== '' && str_starts_with($num, $cc) && strlen($num) >= strlen($cc) + 7) {
            $full = $num;
        } else {
            // A Malaysian provider often types "012-345 6789". The leading zero
            // is a domestic prefix and must go before the country code is
            // added, or the number becomes +60 012… and reaches nobody either.
            if ($cc !== '' && str_starts_with($num, '0')) {
                $num = ltrim($num, '0');
            }

            $full = $cc . $num;
        }

        if (strlen($full) < self::MIN_DIGITS || strlen($full) > self::MAX_DIGITS) {
            return null;
        }

        return $full;
    }
}
