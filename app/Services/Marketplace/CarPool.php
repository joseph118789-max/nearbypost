<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Journey notices. Spec §12.
 *
 * ⛔ NEARBYPOST PUBLISHES NOTICES. IT DOES NOT RUN A TRANSPORT SERVICE.
 *
 * There is no book(), no reserve(), no confirm() and no fare anywhere in this
 * class, and their absence is the design. Spec §12.1: the site "does not
 * operate the vehicle, set a fare, collect payment, guarantee a seat or confirm
 * a booking". Two people find each other here and everything after that is
 * theirs.
 */
class CarPool
{
    /** Spec §12.4. Three, and none is an amount. */
    public const COST_MODES = [
        'free'            => 'Offered free',
        'shared_expenses' => 'Sharing running costs, agreed between you',
        'discuss'         => 'To be discussed directly',
    ];

    /**
     * Phrases that turn a lift into a business. Spec §12.5.
     *
     * ⛔ Matched against the notes, because the notes are where somebody
     * advertises what the form would not let them say. A person writing "airport
     * transfer, RM80 fixed, daily" has described a taxi service, whatever they
     * ticked.
     */
    private const COMMERCIAL_PHRASES = [
        'hire a driver', 'driver for hire', 'taxi', 'e-hailing', 'grab car',
        'airport transfer', 'transfer service', 'chauffeur', 'driver on demand',
        'per pax', 'per person rm', 'fixed rate', 'fixed fare', 'booking fee',
        'parcel', 'delivery service', 'send document', 'courier',
    ];

    /* --------------------------------------------------------- activation */

    /**
     * Activate as a driver. Spec §12.2.
     *
     * @param  array{licence: bool, road_tax: bool, insurance: bool, non_commercial: bool,
     *               adult: bool, terms: bool, make?: ?string, model?: ?string,
     *               colour?: ?string, registration?: ?string, seats: int}  $d
     *
     * @throws CarPoolRefused
     */
    public static function activateDriver(int $userId, string $country, array $d): void
    {
        self::requireOpen($country);
        self::requireIdentity($userId);

        foreach (['licence' => 'you hold a valid driving licence',
                  'road_tax' => 'the vehicle is registered and taxed',
                  'insurance' => 'the vehicle is insured',
                  'non_commercial' => 'this is car pooling, not a transport business',
                  'adult' => 'you are an adult',
                  'terms' => 'you accept the safety terms'] as $key => $what) {
            if (empty($d[$key])) {
                throw new CarPoolRefused('Confirm that ' . $what . '.');
            }
        }

        $seats = (int) ($d['seats'] ?? 0);

        if ($seats < 1 || $seats > 7) {
            throw new CarPoolRefused('How many passenger seats does the car have, with seatbelts?');
        }

        DB::table('carpool_profiles')->updateOrInsert(
            ['user_id' => $userId],
            [
                'driver_status'                   => 'active',
                'driving_declaration_at'          => now(),
                'road_registration_declaration_at' => now(),
                'insurance_declaration_at'        => now(),
                'non_commercial_declaration_at'   => now(),
                'adult_declaration_at'            => now(),
                'vehicle_make'                    => $d['make'] ?? null,
                'vehicle_model'                   => $d['model'] ?? null,
                'vehicle_colour'                  => $d['colour'] ?? null,

                // ⛔ Encrypted here, and there is no method on this class that
                // decrypts it for display. A moderator handling a safety report
                // reads it through the admin path, with that access audited.
                'vehicle_registration_encrypted'  => empty($d['registration'])
                    ? null : Crypt::encryptString((string) $d['registration']),

                'vehicle_seat_capacity'           => $seats,
                'safety_terms_version'            => '1.0',
                'safety_terms_accepted_at'        => now(),
                'updated_at'                      => now(),
                'created_at'                      => now(),
            ]
        );

        self::audit('carpool.driver_activated', $userId, ['seats' => $seats]);
    }

    /** Activate as a passenger. Spec §12.2 — a shorter list, and identity still required. */
    public static function activatePassenger(int $userId, string $country, array $d): void
    {
        self::requireOpen($country);
        self::requireIdentity($userId);

        if (empty($d['adult']) || empty($d['terms'])) {
            throw new CarPoolRefused('Confirm you are an adult and accept the safety terms.');
        }

        DB::table('carpool_profiles')->updateOrInsert(
            ['user_id' => $userId],
            [
                'passenger_status'         => 'active',
                'adult_declaration_at'     => now(),
                'safety_terms_version'     => '1.0',
                'safety_terms_accepted_at' => now(),
                'updated_at'               => now(),
                'created_at'               => now(),
            ]
        );

        self::audit('carpool.passenger_activated', $userId, null);
    }

    /* ------------------------------------------------------------ notices */

    /**
     * Publish a journey notice.
     *
     * @param  array{type: string, origin: array{lat: float, lng: float, area?: ?string},
     *               destination: array{lat: float, lng: float, area?: ?string},
     *               departure_at: string, flexibility?: int, seats?: int, passengers?: int,
     *               cost_share?: string, notes?: ?string}  $n
     *
     * @throws CarPoolRefused
     */
    public static function publish(int $userId, string $country, array $n): int
    {
        self::requireOpen($country);

        $type = (string) ($n['type'] ?? '');

        if (!in_array($type, ['seats_available', 'looking_for_ride'], true)) {
            throw new CarPoolRefused('Are you offering a seat, or looking for one?');
        }

        $profile = DB::table('carpool_profiles')->where('user_id', $userId)
            ->first(['driver_status', 'passenger_status', 'vehicle_seat_capacity']);

        $needed = $type === 'seats_available' ? 'driver_status' : 'passenger_status';

        if ($profile === null || $profile->{$needed} !== 'active') {
            throw new CarPoolRefused('Complete the one-time Car Pool setup first.');
        }

        $departure = \Carbon\Carbon::parse($n['departure_at']);

        if ($departure->isPast()) {
            throw new CarPoolRefused('That departure time has already gone.');
        }

        $notes = trim((string) ($n['notes'] ?? ''));
        $flags = self::commercialPhrasesIn($notes);

        // ⛔ Refused, not quietly cleaned. Spec §18.3 forbids the system
        // rewriting somebody's words, and §12.5 requires this to be blocked. So
        // the person is told which phrase is the problem and edits it
        // themselves - their notice stays their own.
        if ($flags !== []) {
            throw new CarPoolRefused(sprintf(
                'This reads as a transport service rather than car pooling (%s). '
                . 'Car Pool is for a journey you were making anyway.',
                implode(', ', $flags)
            ));
        }

        $seats = $type === 'seats_available' ? (int) ($n['seats'] ?? 0) : null;

        if ($seats !== null) {
            if ($seats < 1) {
                throw new CarPoolRefused('How many seats are you offering?');
            }

            // ⛔ Spec §12.5: "More passengers than declared safe seats" is on
            // the prohibited list, and it is a seatbelt question, not a
            // preference.
            if ($seats > (int) $profile->vehicle_seat_capacity) {
                throw new CarPoolRefused(sprintf(
                    'You told us the car has %d passenger seats with belts. Offer no more than that.',
                    (int) $profile->vehicle_seat_capacity
                ));
            }
        }

        // ⛔ The public points are cells, the exact ones are kept privately.
        $originPublic = PublicLocation::cell((float) $n['origin']['lat'], (float) $n['origin']['lng']);
        $destPublic   = PublicLocation::cell((float) $n['destination']['lat'], (float) $n['destination']['lng']);

        $id = (int) DB::table('carpool_notices')->insertGetId([
            'public_uuid'   => (string) Str::uuid(),
            'user_id'       => $userId,
            'notice_type'   => $type,

            'origin_private_lat' => (float) $n['origin']['lat'],
            'origin_private_lng' => (float) $n['origin']['lng'],
            'origin_public_lat'  => $originPublic['lat'],
            'origin_public_lng'  => $originPublic['lng'],
            'origin_area'        => $n['origin']['area'] ?? null,

            'destination_private_lat' => (float) $n['destination']['lat'],
            'destination_private_lng' => (float) $n['destination']['lng'],
            'destination_public_lat'  => $destPublic['lat'],
            'destination_public_lng'  => $destPublic['lng'],
            'destination_area'        => $n['destination']['area'] ?? null,

            'departure_at'        => $departure,
            'flexibility_minutes' => max(0, min(240, (int) ($n['flexibility'] ?? 0))),
            'seat_count'          => $seats,
            'passenger_count'     => $type === 'looking_for_ride' ? max(1, (int) ($n['passengers'] ?? 1)) : null,
            'cost_share_mode'     => array_key_exists((string) ($n['cost_share'] ?? ''), self::COST_MODES)
                ? $n['cost_share'] : 'discuss',
            'notes'               => $notes === '' ? null : mb_substr($notes, 0, 1000),

            'publication_status'  => 'published',
            'moderation_status'   => 'ai_checking',

            // ⛔ Derived from the journey, never chosen. Six hours after
            // departure the notice is of no use to anybody and is a standing
            // record of where somebody goes and when.
            'expires_at'          => $departure->copy()->addHours(6),

            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        self::audit('carpool.notice_published', $userId, ['notice_id' => $id, 'type' => $type]);

        return $id;
    }

    /**
     * Notices going roughly the same way. Spec §12.6.
     *
     * ⛔ THE SCORE IS ABOUT ROUTE, NEVER ABOUT SAFETY. Spec §12.6: "never imply
     * safety from the match score." So it is called a route match, the wording
     * says route, and nothing in the calculation touches ratings, verification
     * or how long somebody has been a member.
     *
     * @return list<array<string, mixed>>
     */
    public static function matches(int $noticeId, int $limit = 20): array
    {
        $me = DB::table('carpool_notices')->where('id', $noticeId)
            ->first(['id', 'notice_type', 'origin_public_lat', 'origin_public_lng',
                     'destination_public_lat', 'destination_public_lng', 'departure_at',
                     'flexibility_minutes']);

        if ($me === null) {
            return [];
        }

        $wanted = $me->notice_type === 'seats_available' ? 'looking_for_ride' : 'seats_available';

        $rows = DB::table('carpool_notices')
            ->where('notice_type', $wanted)
            ->where('publication_status', 'published')
            ->whereNull('deleted_at')
            ->where('expires_at', '>', now())
            ->whereBetween('departure_at', [
                \Carbon\Carbon::parse($me->departure_at)->subHours(3),
                \Carbon\Carbon::parse($me->departure_at)->addHours(3),
            ])
            ->limit(200)
            ->get();

        $out = [];

        foreach ($rows as $r) {
            $originKm = self::km((float) $me->origin_public_lat, (float) $me->origin_public_lng,
                                 (float) $r->origin_public_lat, (float) $r->origin_public_lng);
            $destKm   = self::km((float) $me->destination_public_lat, (float) $me->destination_public_lng,
                                 (float) $r->destination_public_lat, (float) $r->destination_public_lng);

            $minutes = abs(\Carbon\Carbon::parse($me->departure_at)
                ->diffInMinutes(\Carbon\Carbon::parse($r->departure_at), false));

            // Each part falls to zero at the edge of usefulness: 15 km apart at
            // either end, or three hours apart in time, is not the same journey.
            $originScore = max(0, 1 - ($originKm / 15));
            $destScore   = max(0, 1 - ($destKm / 15));
            $timeScore   = max(0, 1 - ($minutes / 180));

            $score = (int) round(100 * (0.4 * $originScore + 0.4 * $destScore + 0.2 * $timeScore));

            if ($score < 40) {
                continue;
            }

            $out[] = [
                'public_uuid'    => $r->public_uuid,
                'notice_type'    => $r->notice_type,
                'origin_area'    => $r->origin_area,
                'destination_area' => $r->destination_area,
                'departure_at'   => $r->departure_at,
                'cost_share_mode' => $r->cost_share_mode,
                'origin_km'      => round($originKm, 1),
                'destination_km' => round($destKm, 1),
                'minutes_apart'  => $minutes,

                // ⛔ The wording carries the meaning. "88% route match" is a
                // statement about geography; "88% match" invites the reader to
                // fill in what is being matched.
                'route_match'    => $score . '% route match',
            ];
        }

        usort($out, fn ($a, $b) => (int) $b['route_match'] <=> (int) $a['route_match']);

        return array_slice($out, 0, $limit);
    }

    /** Sweep expired notices. Idempotent; safe to run from the scheduler. */
    public static function expire(): int
    {
        return DB::table('carpool_notices')
            ->where('publication_status', 'published')
            ->where('expires_at', '<=', now())
            ->update(['publication_status' => 'expired', 'updated_at' => now()]);
    }

    /** @return list<string> */
    public static function commercialPhrasesIn(string $text): array
    {
        $haystack = mb_strtolower($text);
        $found    = [];

        foreach (self::COMMERCIAL_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    /* ------------------------------------------------------------ helpers */

    private static function requireOpen(string $country): void
    {
        if (FeatureFlags::off('carpool_enabled', $country)) {
            throw new CarPoolRefused('Car Pool is not available here.');
        }
    }

    /**
     * ⛔ IDENTITY, NOT JUST A PHONE. Spec §12.2 requires confirmed identity for
     * drivers AND passengers, and this is the only capability in the module
     * that does. It is also the only one where strangers get into a car
     * together.
     */
    private static function requireIdentity(int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)
            ->first(['phone_verified_at', 'identity_verification_status']);

        if ($user === null || $user->phone_verified_at === null) {
            throw new CarPoolRefused('Confirm your phone number first.');
        }

        if ($user->identity_verification_status !== 'confirmed') {
            throw new CarPoolRefused('Car Pool needs your identity confirmed first.');
        }
    }

    private static function km(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $x = deg2rad($bLat - $aLat);
        $y = deg2rad($bLng - $aLng);
        $h = sin($x / 2) ** 2 + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($y / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }

    private static function audit(string $event, int $userId, ?array $after): void
    {
        DB::table('audit_events')->insert([
            'event'         => $event,
            'subject_type'  => 'carpool',
            'subject_id'    => $userId,
            'actor_user_id' => $userId,
            'after'         => $after === null ? null : json_encode($after),
            'created_at'    => now(),
        ]);
    }
}
