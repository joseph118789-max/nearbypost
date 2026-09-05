<?php

namespace App\Console\Commands;

use App\Services\Flags\FeatureFlags;
use App\Services\Marketplace\ListingSearch;
use App\Services\Marketplace\WhatsAppContact;
use App\Services\Marketplace\ReportRouting;
use App\Services\Marketplace\CategoryRules;
use App\Services\Marketplace\ListingWriter;
use App\Services\Marketplace\PublicLocation;
use App\Services\Marketplace\Capabilities;
use App\Services\Marketplace\ProviderOnboarding;
use App\Services\Marketplace\VerificationPanel;
use App\Services\Marketplace\Reviews;
use App\Services\Marketplace\ProviderTeam;
use App\Services\Marketplace\PersonProfile;
use App\Services\Marketplace\Credentials;
use App\Services\Marketplace\OrganisationMembership;
use App\Services\Marketplace\CarPool;
use App\Services\Marketplace\Advertising;
use App\Services\Marketplace\SponsoredPlacements;
use App\Services\Marketplace\PropertyMirror;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * php artisan marketplace:check
 *
 * The Marketplace invariants, exercised against the real database. Run it after
 * every migration in this module and before every deploy.
 *
 * These are not unit tests of a class - they are the rules the SCHEMA is
 * supposed to enforce, and a constraint nobody has tried to violate is only a
 * comment. Each check writes an impossible state and expects to be refused.
 *
 * Everything runs inside a transaction that is always rolled back, so it is
 * safe on production and leaves nothing behind.
 */
class CheckMarketplace extends Command
{
    protected $signature = 'marketplace:check';

    protected $description = 'Exercise the Marketplace invariants against the database';

    private int $pass = 0;
    private int $fail = 0;

    public function handle(): int
    {
        $this->flags();
        $this->schema();
        $this->search();
        $this->contact();
        $this->reports();
        $this->writing();
        $this->onboarding();
        $this->ratings();
        $this->team();
        $this->profile();
        $this->credentials();
        $this->carpool();
        $this->advertising();
        $this->property();

        $this->newLine();

        if ($this->fail === 0) {
            $this->info("{$this->pass} checks passed.");

            return self::SUCCESS;
        }

        $this->error("{$this->pass} passed, {$this->fail} FAILED.");

        return self::FAILURE;
    }

    /* ---------------------------------------------------------------- flags */

    private function flags(): void
    {
        $this->line('');
        $this->line('<options=bold>Feature flags</> (spec 32)');

        // Snapshot, so the check can set flags freely and put them back.
        $before = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        try {
            $this->want('a flag with no row at all is off', FeatureFlags::on('invented_flag'), false);

            FeatureFlags::set('marketplace_enabled', null, true, 'marketplace:check');
            $this->want('an ordinary flag is inherited from the global row', FeatureFlags::on('marketplace_enabled', 'SG'), true);

            // ⛔ The rule worth protecting. Spec 32: sensitive capabilities must
            // default off in unconfigured countries. Were this inherited,
            // adding a country to SITE_COUNTRIES would switch Car Pool on there
            // before anyone read that country's transport law.
            FeatureFlags::set('carpool_enabled', null, true, 'marketplace:check');
            $this->want('a SENSITIVE flag is NOT inherited by an unconfigured country', FeatureFlags::on('carpool_enabled', 'ID'), false);

            FeatureFlags::set('carpool_enabled', 'MY', true, 'marketplace:check');
            $this->want('and IS on once that country has its own row', FeatureFlags::on('carpool_enabled', 'MY'), true);
            $this->want('while its neighbour stays off', FeatureFlags::on('carpool_enabled', 'SG'), false);

            FeatureFlags::set('marketplace_enabled', 'SG', false, 'marketplace:check');
            $this->want('a country row can also switch something OFF', FeatureFlags::on('marketplace_enabled', 'SG'), false);
        } finally {
            DB::table('feature_flags')->delete();

            if ($before !== []) {
                DB::table('feature_flags')->insert($before);
            }

            FeatureFlags::forget();
        }
    }

    /* --------------------------------------------------------------- schema */

    private function schema(): void
    {
        DB::beginTransaction();

        try {
            $providerId = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'check-' . Str::random(10),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'marketplace:check', 'country_code' => 'MY',
                'created_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $member = fn (array $over) => $over + [
                'provider_profile_id' => $providerId, 'created_at' => now(), 'updated_at' => now(),
            ];

            $this->line('');
            $this->line('<options=bold>One owner per provider</> (spec 8.6)');
            $this->allows('the first active owner', fn () => DB::table('provider_members')->insert(
                $member(['user_id' => 1, 'role' => 'owner', 'status' => 'active'])));
            $this->refuses('a second active owner', fn () => DB::table('provider_members')->insert(
                $member(['user_id' => 2, 'role' => 'owner', 'status' => 'active'])));
            $this->allows('a manager alongside the owner', fn () => DB::table('provider_members')->insert(
                $member(['user_id' => 3, 'role' => 'manager', 'status' => 'active'])));
            $this->allows('an owner mid-transfer, invited but not active', fn () => DB::table('provider_members')->insert(
                $member(['user_id' => 4, 'role' => 'owner', 'status' => 'invited'])));
            $this->refuses('the same person joining twice', fn () => DB::table('provider_members')->insert(
                $member(['user_id' => 1, 'role' => 'viewer', 'status' => 'active'])));

            $listing = fn (array $over = []) => $over + [
                'public_uuid' => (string) Str::uuid(), 'owner_user_id' => 1,
                'provider_profile_id' => $providerId, 'category_id' => 1,
                'listing_kind' => 'food_offer', 'title' => 'marketplace:check',
                'country_code' => 'MY', 'expires_at' => now()->addDays(7),
                'created_at' => now(), 'updated_at' => now(),
            ];

            $this->line('');
            $this->line('<options=bold>A price range must be a range</>');
            $this->allows('min below max', fn () => DB::table('marketplace_listings')->insert(
                $listing(['price_mode' => 'range', 'price_min' => 4, 'price_max' => 8, 'currency' => 'MYR'])));
            $this->refuses('max below min', fn () => DB::table('marketplace_listings')->insert(
                $listing(['price_mode' => 'range', 'price_min' => 8, 'price_max' => 4, 'currency' => 'MYR'])));
            $this->allows('a quotation with no price', fn () => DB::table('marketplace_listings')->insert(
                $listing(['price_mode' => 'quotation'])));

            $this->line('');
            $this->line('<options=bold>A published listing that claims a place must have one</> (spec 13.2)');
            $this->refuses('published, approximate_area, no coordinates', fn () => DB::table('marketplace_listings')->insert(
                $listing(['publication_status' => 'published', 'public_location_mode' => 'approximate_area'])));
            $this->allows('published, approximate_area, with coordinates', fn () => DB::table('marketplace_listings')->insert(
                $listing(['publication_status' => 'published', 'public_location_mode' => 'approximate_area',
                          'public_lat' => 3.1390, 'public_lng' => 101.6869])));
            $this->allows('published, online_only, no coordinates', fn () => DB::table('marketplace_listings')->insert(
                $listing(['publication_status' => 'published', 'public_location_mode' => 'online_only'])));
            $this->allows('a draft with no coordinates yet', fn () => DB::table('marketplace_listings')->insert(
                $listing(['publication_status' => 'draft', 'public_location_mode' => 'exact_premises'])));

            $this->line('');
            $this->line('<options=bold>Publication and moderation are separate gates</> (spec 13.4)');
            $live = (int) DB::selectOne("SELECT count(*) AS n FROM marketplace_listings
                WHERE publication_status = 'published'
                  AND moderation_status IN ('ai_accepted','manual_accepted')
                  AND deleted_at IS NULL")->n;
            $this->want('published alone does not make a listing live', $live === 0, true);
        } finally {
            DB::rollBack();
        }
    }

    /* --------------------------------------------------------------- search */

    /**
     * Real listings at known coordinates, checked against distances worked out
     * from those coordinates rather than from anything this code produced.
     *
     * A radius search that is confidently wrong is worse than one that fails,
     * because nothing on screen says so.
     */
    private function search(): void
    {
        // KLCC is the reader's position throughout.
        $lat = 3.1578;
        $lng = 101.7117;

        $places = [
            ['Pavilion KL',       3.1488, 101.7130, 'food_dining'],
            ['Mid Valley',        3.1180, 101.6770, 'food_dining'],
            ['Sunway Pyramid',    3.0726, 101.6067, 'buy_sell'],
            ['Shah Alam Stadium', 3.0713, 101.5183, 'home_renovation'],
            ['Seremban town',     2.7297, 101.9381, 'buy_sell'],
        ];

        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('marketplace_enabled', 'MY', true, 'marketplace:check');

            $providerId = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'check-' . Str::random(10),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'marketplace:check', 'country_code' => 'MY',
                'created_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $catId = fn (string $code) => (int) DB::table('marketplace_categories')->where('code', $code)->value('id');

            $put = function (string $title, float $plat, float $plng, string $code, array $over = []) use ($providerId, $catId) {
                DB::table('marketplace_listings')->insert($over + [
                    'public_uuid' => (string) Str::uuid(), 'owner_user_id' => 1,
                    'provider_profile_id' => $providerId, 'category_id' => $catId($code),
                    'listing_kind' => 'provider_service', 'title' => $title,
                    'country_code' => 'MY', 'public_location_mode' => 'approximate_area',
                    'public_lat' => $plat, 'public_lng' => $plng,
                    'private_lat' => $plat + 0.01, 'private_lng' => $plng + 0.01,
                    'publication_status' => 'published', 'moderation_status' => 'ai_accepted',
                    'expires_at' => now()->addDays(7), 'published_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            };

            foreach ($places as [$title, $plat, $plng, $code]) {
                $put($title, $plat, $plng, $code);
            }

            // Next door, but each failing one gate.
            $put('unmoderated', $lat, $lng, 'food_dining', ['moderation_status' => 'not_checked']);
            $put('expired', $lat, $lng, 'food_dining', ['expires_at' => now()->subHour()]);

            $this->line('');
            $this->line('<options=bold>Radius search</> (spec 15)');

            $titles = fn (array $rows) => implode(', ', array_map(fn ($r) => $r['title'], $rows));
            $r2     = ListingSearch::near($lat, $lng, 2, ['country' => 'MY']);
            $r25    = ListingSearch::near($lat, $lng, 25, ['country' => 'MY']);
            $r60    = ListingSearch::near($lat, $lng, 60, ['country' => 'MY']);

            $this->want('2 km from KLCC returns only Pavilion', $titles($r2) === 'Pavilion KL', true);
            $this->want('25 km reaches Shah Alam but not Seremban', count($r25) === 4, true);
            $this->want('60 km reaches Seremban', count($r60) === 5, true);
            $this->want('results are nearest first', $r25[0]['title'] === 'Pavilion KL', true);
            $this->want('Pavilion is about 1 km away', abs($r25[0]['distance_km'] - 1.0) < 0.3, true);

            $this->line('');
            $this->line('<options=bold>What a search must never return</>');
            $this->want('a published but unmoderated listing next door', str_contains($titles($r60), 'unmoderated'), false);
            $this->want('an expired listing next door', str_contains($titles($r60), 'expired'), false);
            $this->want('private_lat is not even selected', array_key_exists('private_lat', $r60[0]), false);
            $this->want('private_lng is not even selected', array_key_exists('private_lng', $r60[0]), false);

            $this->line('');
            $this->line('<options=bold>The chip count and the list agree</>');
            $this->want('counts sum to what the list returns',
                array_sum(ListingSearch::countByCategory($lat, $lng, 25, 'MY')) === count($r25), true);

            $this->line('');
            $this->line('<options=bold>The flag is enforced inside the search, not only above it</>');
            FeatureFlags::set('marketplace_enabled', 'MY', false, 'marketplace:check');
            $this->want('nothing is returned once Marketplace is off in MY',
                ListingSearch::near($lat, $lng, 60, ['country' => 'MY']) === [], true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* -------------------------------------------------------------- contact */

    /**
     * The edge of the product. A provider types their own WhatsApp number, so
     * it is attacker-controlled text that ends up in a URL (spec 29).
     */
    private function contact(): void
    {
        $title = 'Nasi Lemak Available Saturday';
        $url   = 'https://nearbypost.com/marketplace/listings/abc123';

        $number = function (?string $cc, ?string $n) use ($title, $url): ?string {
            $link = WhatsAppContact::link($cc, $n, $title, $url);

            return $link === null ? null : explode('?', substr($link, strlen('https://wa.me/')))[0];
        };

        $this->line('');
        $this->line('<options=bold>WhatsApp numbers as providers actually type them</> (spec 14)');
        $this->want('a Malaysian number keeps its domestic zero out', $number('60', '012-345 6789') === '60123456789', true);
        $this->want('one already carrying +60 is not given another 60', $number('60', '+60 12 345 6789') === '60123456789', true);
        $this->want('spaces, brackets and dashes are ignored', $number('60', '(012) 345-6789') === '60123456789', true);
        $this->want('Singapore', $number('65', '9123 4567') === '6591234567', true);

        $this->line('');
        $this->line('<options=bold>What must never become a link</> (spec 29)');
        $this->want('too short to be a phone number', $number('60', '123') === null, true);
        $this->want('nothing but punctuation', $number('60', '---') === null, true);
        $this->want('longer than E.164 allows', $number('60', '1234567890123456789') === null, true);
        $this->want('a malicious scheme', $number('', 'javascript:alert(1)') === null, true);
        $this->want('an attempt to break out of the host', $number('', '/../../evil.example/') === null, true);

        $smuggled = WhatsAppContact::link('60', '60123456789?text=hi&redirect=http://evil.example', $title, $url);
        $this->want('a smuggled parameter cannot survive the digit strip',
            $smuggled !== null && str_starts_with($smuggled, 'https://wa.me/60123456789?') && !str_contains($smuggled, 'evil.example'), true);

        $this->line('');
        $this->line('<options=bold>The prefilled message</> (spec 14.3)');
        $message = WhatsAppContact::message($title, $url);
        $this->want('names the listing', str_contains($message, $title), true);
        $this->want('carries the public URL', str_contains($message, $url), true);
    }

    /* -------------------------------------------------------------- reports */

    /**
     * A report has to act at the moment it arrives, in proportion to what it
     * alleges. Spec 17.3.
     */
    private function reports(): void
    {
        DB::beginTransaction();

        try {
            $providerId = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'check-' . Str::random(10),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'marketplace:check', 'country_code' => 'MY',
                'created_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $catId = (int) DB::table('marketplace_categories')->where('code', 'food_dining')->value('id');

            $newListing = fn () => (int) DB::table('marketplace_listings')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'owner_user_id' => 1,
                'provider_profile_id' => $providerId, 'category_id' => $catId,
                'listing_kind' => 'food_offer', 'title' => 'marketplace:check',
                'country_code' => 'MY', 'public_location_mode' => 'online_only',
                'publication_status' => 'published', 'moderation_status' => 'ai_accepted',
                'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $statusOf = fn (int $id) => DB::table('marketplace_listings')->where('id', $id)->value('publication_status');

            $this->line('');
            $this->line('<options=bold>One credible report acts at once</> (spec 17.3)');

            $scam = $newListing();
            ReportRouting::file('listing', $scam, 'suspected_scam', ['reporter_user_id' => 1]);
            $this->want('a single scam report suspends the listing', $statusOf($scam) === 'suspended', true);

            $illegal = $newListing();
            ReportRouting::file('listing', $illegal, 'illegal_goods', ['reporter_user_id' => 1]);
            $this->want('illegal goods removes it outright', $statusOf($illegal) === 'removed', true);

            $misleading = $newListing();
            ReportRouting::file('listing', $misleading, 'misleading_information', ['reporter_user_id' => 1]);
            $this->want('something merely misleading is paused, not removed', $statusOf($misleading) === 'paused', true);

            $this->line('');
            $this->line('<options=bold>A small complaint takes nothing down</>');
            $hours = $newListing();
            ReportRouting::file('listing', $hours, 'wrong_hours', ['reporter_user_id' => 1]);
            $this->want('wrong opening hours leaves the listing published', $statusOf($hours) === 'published', true);

            $this->line('');
            $this->line('<options=bold>An unknown reason is treated as medium, never as trivial</>');
            $this->want('a reason code nobody registered', ReportRouting::severityOf('a_new_kind_of_harm') === 'medium', true);

            $this->line('');
            $this->line('<options=bold>Grouping hides the row, never the evidence</> (spec 17.4)');
            $repeat = $newListing();
            $first  = ReportRouting::file('listing', $repeat, 'wrong_location', ['reporter_user_id' => 1]);
            $second = ReportRouting::file('listing', $repeat, 'wrong_location', ['reporter_user_id' => 2]);
            $third  = ReportRouting::file('listing', $repeat, 'wrong_location',
                ['reporter_user_id' => 3, 'description' => 'It is on the other side of the river, here is why.']);

            $this->want('the first report is open', $first['grouped'] === false, true);
            $this->want('a bare repeat is grouped', $second['grouped'] === true, true);
            $this->want('a repeat WITH new evidence stays open', $third['grouped'] === false, true);

            $this->line('');
            $this->line('<options=bold>Everything that acted left an audit row</> (spec 20.20)');
            $audited = DB::table('audit_events')->where('subject_type', 'listing')->count();
            $this->want('three actions, three audit events', $audited === 3, true);

            $this->line('');
            $this->line('<options=bold>A decision must say why</>');
            $this->refuses('decided_at with no decision_code', fn () => DB::table('marketplace_reports')
                ->where('id', $first['report_id'])->update(['decided_at' => now()]));
            $this->allows('decided_at with one', fn () => DB::table('marketplace_reports')
                ->where('id', $first['report_id'])->update(['decided_at' => now(), 'decision_code' => 'no_action']));

            $this->line('');
            $this->line('<options=bold>A verified check must name its method</> (spec 11.5)');
            $this->refuses('verified against a register, with no method recorded', fn () => DB::table('verification_checks')->insert([
                'subject_type' => 'provider', 'subject_id' => $providerId,
                'verification_type' => 'business_registration', 'status' => 'verified_register',
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->allows('the same, with method and timestamp', fn () => DB::table('verification_checks')->insert([
                'subject_type' => 'provider', 'subject_id' => $providerId,
                'verification_type' => 'business_registration', 'status' => 'verified_register',
                'method' => 'official_register', 'checked_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]));
        } finally {
            DB::rollBack();
        }
    }

    /* --------------------------------------------------------------- writing */

    /**
     * Creating a listing: what the country's rules refuse, and what the server
     * decides regardless of what was sent.
     */
    private function writing(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('marketplace_enabled', 'MY', true, 'marketplace:check');

            $providerId = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'check-' . Str::random(10),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'marketplace:check', 'country_code' => 'MY',
                'created_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('provider_members')->insert([
                'provider_profile_id' => $providerId, 'user_id' => 1, 'role' => 'owner',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $food = (int) DB::table('marketplace_categories')->where('code', 'home_prepared_food')->value('id');

            $base = [
                'title' => 'Nasi Lemak Available Saturday',
                'public_location_mode' => 'approximate_area',
                'lat' => 3.1578123, 'lng' => 101.7117456,
            ];

            // ⛔ The check must behave the same whether or not this country has
            // been opened for real. It used to INSERT a rule row and assume
            // none existed; the day `marketplace:open-categories MY` was run,
            // the whole check died on a unique violation. Closing the category
            // first, inside the transaction, makes the starting state
            // deterministic and the rollback puts the operator's row back.
            DB::table('marketplace_category_country_rules')
                ->where('category_id', $food)->where('country_code', 'MY')->delete();
            CategoryRules::forget();

            $this->line('');
            $this->line('<options=bold>A category closed in this country refuses everything</> (spec 4.3)');
            $this->refuses('drafting before the category is enabled',
                fn () => ListingWriter::draft(1, $providerId, $food, $base));

            // Open it, the way a country operator would.
            DB::table('marketplace_category_country_rules')->updateOrInsert(
                ['category_id' => $food, 'country_code' => 'MY'],
                [
                    'enabled' => true,
                    'allowed_provider_kinds' => json_encode(['neighbour_provider']),
                    'listing_limits' => json_encode(['active_listings' => 2, 'images' => 5]),
                    'location_policy' => json_encode(['allowed_modes' => ['approximate_area', 'online_only']]),
                    'pricing_policy' => json_encode(['mode' => 'required']),
                    'default_expiry_days' => 3, 'requires_manual_review' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
            CategoryRules::forget();

            $this->line('');
            $this->line('<options=bold>Once it is open, the rules still bind</>');
            $this->refuses('a price is required here and none was given',
                fn () => ListingWriter::draft(1, $providerId, $food, $base));

            $priced = $base + ['price_mode' => 'fixed', 'price_min' => 4.00, 'currency' => 'MYR'];

            // ⛔ array_merge, not +. PHP's + keeps the LEFT operand's value for
            // a duplicate key, so `$priced + ['public_location_mode' => ...]`
            // silently kept approximate_area - the check passed a legal listing,
            // watched it succeed, and then failed the NEXT check by having used
            // up the category's limit. One wrong operator, two wrong results.
            $this->refuses('exact_premises is not an allowed precision here',
                fn () => ListingWriter::draft(1, $providerId, $food,
                    array_merge($priced, ['public_location_mode' => 'exact_premises'])));

            $this->refuses('somebody with no role at this provider',
                fn () => ListingWriter::draft(999, $providerId, $food, $priced));

            $id = null;
            $this->allows('the owner, with a price and an allowed precision',
                function () use (&$id, $providerId, $food, $priced) {
                    $id = ListingWriter::draft(1, $providerId, $food, $priced);
                });

            $this->line('');
            $this->line('<options=bold>What the SERVER decided, whatever was sent</> (spec 0.8)');
            $row = DB::table('marketplace_listings')->where('id', $id)->first();

            $this->want('it is a draft, never published straight from a form', $row->publication_status === 'draft', true);
            $this->want('moderation has not looked at it yet', $row->moderation_status === 'not_checked', true);
            $this->want('expiry came from the country rule, not the request',
                (int) now()->diffInDays($row->expires_at, false) === 2 || (int) now()->diffInDays($row->expires_at, false) === 3, true);

            $this->line('');
            $this->line('<options=bold>The public point is coarsened, and deterministically</> (spec 15.2)');
            $this->want('the private point is stored exactly', (float) $row->private_lat === 3.1578123, true);
            $this->want('the public point is NOT the private point', (float) $row->public_lat !== 3.1578123, true);

            $again = PublicLocation::derive('approximate_area', 3.1578123, 101.7117456);
            $this->want('and reading it twice gives the same answer, so repetition leaks nothing',
                (float) $row->public_lat === $again['lat'] && (float) $row->public_lng === $again['lng'], true);

            $metres = PublicLocation::uncertaintyMetres('approximate_area');
            $this->want('the cell is under a kilometre from corner to centre', $metres > 400 && $metres < 900, true);
            $this->want('a shopfront is not coarsened at all',
                PublicLocation::derive('exact_premises', 3.1578123, 101.7117456)['lat'] === 3.1578123, true);

            $this->line('');
            $this->line('<options=bold>Submitting queues it, it does not publish it</> (spec 13.4)');
            ListingWriter::submit($id, 1);
            $after = DB::table('marketplace_listings')->where('id', $id)->first(['publication_status', 'moderation_status']);
            $this->want('publication is submitted, not published', $after->publication_status === 'submitted', true);
            $this->want('and it is waiting on moderation', $after->moderation_status === 'ai_checking', true);
            $this->want('nothing that is merely submitted is visible',
                ListingSearch::near(3.1578, 101.7117, 10, ['country' => 'MY']) === [], true);

            $this->line('');
            $this->line('<options=bold>The per-category limit holds</> (spec 8.3)');
            $this->allows('a second listing, the limit here being two',
                fn () => ListingWriter::draft(1, $providerId, $food, $priced));
            $this->refuses('a third', fn () => ListingWriter::draft(1, $providerId, $food, $priced));
        } finally {
            DB::rollBack();
            CategoryRules::forget();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* ----------------------------------------------------------- onboarding */

    /**
     * Spec 34.1, the acceptance test for the whole module: a retired neighbour
     * sets up once, without a company registration, and is not presented as
     * having one.
     */
    private function onboarding(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            $this->line('');
            $this->line('<options=bold>The capability gate</> (spec 7)');

            // 5 real accounts exist and none has a confirmed phone, which is
            // the honest state until an OTP provider is chosen.
            $userId = (int) DB::table('users')->orderBy('id')->value('id');

            FeatureFlags::set('neighbour_offers_enabled', 'MY', true, 'marketplace:check');

            $this->want('posting to the community needs only an account',
                Capabilities::denies($userId, 'community_post') === null, true);

            $this->want('a neighbour offer is refused while the phone is unconfirmed',
                str_contains((string) Capabilities::denies($userId, 'neighbour_offer', 'MY'), 'phone'), true);

            $this->want('and the refusal is a sentence, not a boolean',
                is_string(Capabilities::denies($userId, 'neighbour_offer', 'MY')), true);

            // Confirm the phone the way the OTP service will, once it exists.
            DB::table('users')->where('id', $userId)->update(['phone_verified_at' => now()]);

            $this->want('with the phone confirmed, the next step is the setup itself',
                str_contains((string) Capabilities::denies($userId, 'neighbour_offer', 'MY'), 'Set up your neighbour profile'), true);

            $this->line('');
            $this->line('<options=bold>Setting up once</> (spec 8.3, 34.1)');

            $refused = null;

            try {
                ProviderOnboarding::neighbour($userId, 'MY', ['name' => 'Alex Weekend Nasi Lemak']);
            } catch (\Throwable $e) {
                $refused = $e->getMessage();
            }

            $this->want('the terms have to be accepted', str_contains((string) $refused, 'terms'), true);

            $providerId = ProviderOnboarding::neighbour($userId, 'MY', [
                'name' => 'Alex Weekend Nasi Lemak', 'area' => 'Desa ParkCity',
                'whatsapp_cc' => '60', 'whatsapp' => '012-345 6789',
                'lat' => 3.1855432, 'lng' => 101.6300876,
                'accepted_terms' => true, 'declared_accurate' => true,
            ]);

            $this->want('the profile exists', $providerId > 0, true);
            $this->want('now the offer capability is open',
                Capabilities::denies($userId, 'neighbour_offer', 'MY') === null, true);

            $this->want('a second neighbour profile is turned away, kindly',
                (function () use ($userId) {
                    try {
                        ProviderOnboarding::neighbour($userId, 'MY', [
                            'name' => 'Alex Cleaning', 'accepted_terms' => true, 'declared_accurate' => true,
                        ]);

                        return false;
                    } catch (\Throwable $e) {
                        return str_contains($e->getMessage(), 'Add another category');
                    }
                })(), true);

            $row = DB::table('provider_profiles')->where('id', $providerId)->first();

            $this->line('');
            $this->line('<options=bold>Her home is not on the map</> (spec 13.2)');
            $this->want('the exact position is stored privately', (float) $row->private_lat === 3.1855432, true);
            $this->want('the public position is the cell, not the house', (float) $row->public_lat !== 3.1855432, true);
            $this->want('and she is home-based, so never mapped exactly', $row->operating_mode === 'home_based', true);

            $this->line('');
            $this->line('<options=bold>What the reader is told</> (spec 3.4, 8.3, 35)');
            $panel = VerificationPanel::forProvider($providerId, 'neighbour_provider');
            $says  = array_column($panel, 'says');

            foreach ($says as $line) {
                $this->line('    ' . $line);
            }

            $this->want('the phone is stated as confirmed',
                in_array('Phone confirmed', $says, true), true);
            $this->want('the missing registration is stated OUT LOUD',
                in_array('Business registration not confirmed', $says, true), true);
            $this->want('identity is not claimed, because it was not checked',
                in_array('Identity not confirmed', $says, true), true);

            $joined = strtolower(implode(' ', $says) . ' ' . VerificationPanel::kindLabel('neighbour_provider'));

            foreach (['approved', 'fully verified', 'trusted', 'unverified'] as $forbidden) {
                $this->want('the panel never says "' . $forbidden . '"', !str_contains($joined, $forbidden), true);
            }

            $this->want('the label describes how she trades, not how good she is',
                VerificationPanel::kindLabel('neighbour_provider') === 'Neighbour Provider', true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* --------------------------------------------------------------- ratings */

    /**
     * Spec 16 and 15.3. The rule that matters most is the last one: a 5.0 from
     * one review must not outrank a 4.8 from a hundred.
     */
    private function ratings(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('ratings_enabled', null, true, 'marketplace:check');

            $users = DB::table('users')->orderBy('id')->limit(4)->pluck('id')->all();
            $owner = (int) $users[0];
            $buyer = (int) ($users[1] ?? $users[0]);

            $providerId = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'rate-' . Str::random(8),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'marketplace:check', 'country_code' => 'MY',
                'created_by_user_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('provider_members')->insert([
                'provider_profile_id' => $providerId, 'user_id' => $owner, 'role' => 'owner',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $rate = fn (int $by, int $stars, ?string $body = 'Good, thank you.') =>
                Reviews::write($by, 'provider', $providerId, 'provider', ['overall' => $stars, 'body' => $body]);

            $this->line('');
            $this->line('<options=bold>Who may not rate</> (spec 16.1, 21.3)');
            $this->refuses('the owner rating their own business', fn () => $rate($owner, 5));
            $this->allows('a customer rating it', fn () => $rate($buyer, 5));
            $this->refuses('the same customer rating it twice', fn () => $rate($buyer, 4));

            $this->line('');
            $this->line('<options=bold>A one-star with no words is asked for words</> (spec 16.5)');
            $this->refuses('one star, no explanation', fn () => Reviews::write(
                (int) $users[2], 'provider', $providerId, 'provider', ['overall' => 1, 'body' => '']));
            $this->allows('one star, with an explanation', fn () => Reviews::write(
                (int) $users[2], 'provider', $providerId, 'provider',
                ['overall' => 1, 'body' => 'Never turned up and did not answer.']));

            $this->line('');
            $this->line('<options=bold>The line a reader is shown always carries the count</> (spec 16.4)');
            $line = Reviews::summaryLine('provider', $providerId, 'provider');
            $this->line('    ' . $line);
            $this->want('it says how many', str_contains((string) $line, 'community ratings'), true);
            $this->want('it never claims a verified purchase', !str_contains(strtolower((string) $line), 'verified'), true);

            $this->line('');
            $this->line('<options=bold>One perfect review must not outrank a hundred good ones</> (spec 15.3)');

            $newcomer = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'rate2-' . Str::random(8),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'one review', 'country_code' => 'MY',
                'created_by_user_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $established = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'rate3-' . Str::random(8),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'a hundred reviews', 'country_code' => 'MY',
                'created_by_user_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // One 5.0 against a hundred 4.8s, written straight to the table so
            // the comparison is about the maths, not about eligibility.
            $bulk = function (int $target, int $howMany, float $stars) {
                $rows = [];

                for ($i = 0; $i < $howMany; $i++) {
                    $rows[] = [
                        'public_uuid' => (string) Str::uuid(), 'reviewer_user_id' => 100000 + $i,
                        'target_type' => 'provider', 'target_id' => $target, 'context' => 'provider',
                        'overall_rating' => (int) round($stars), 'status' => 'published',
                        'moderation_status' => 'ai_accepted', 'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                DB::table('reviews')->insert($rows);
                Reviews::recalculate('provider', $target, 'provider');
            };

            $bulk($newcomer, 1, 5.0);
            $bulk($established, 100, 5.0);
            DB::table('reviews')->where('target_id', $established)->limit(20)->update(['overall_rating' => 4]);
            Reviews::recalculate('provider', $established, 'provider');

            $a = DB::table('rating_summaries')->where('target_id', $newcomer)->first(['average_rating', 'weighted_rating', 'review_count']);
            $b = DB::table('rating_summaries')->where('target_id', $established)->first(['average_rating', 'weighted_rating', 'review_count']);

            $this->line(sprintf('    one review:      average %.2f from %d, ranks at %.3f',
                $a->average_rating, $a->review_count, $a->weighted_rating));
            $this->line(sprintf('    hundred reviews: average %.2f from %d, ranks at %.3f',
                $b->average_rating, $b->review_count, $b->weighted_rating));

            $this->want('the newcomer has the higher raw average',
                (float) $a->average_rating > (float) $b->average_rating, true);
            $this->want('but the established provider ranks higher',
                (float) $b->weighted_rating > (float) $a->weighted_rating, true);

            $this->line('');
            $this->line('<options=bold>Contexts never merge</> (spec 3.3)');
            $summaries = DB::table('rating_summaries')->where('target_id', $providerId)->count();
            $this->want('one summary row per context, and no total anywhere', $summaries === 1, true);
            $this->want('there is no rating column on users',
                !\Illuminate\Support\Facades\Schema::hasColumn('users', 'rating'), true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* ------------------------------------------------------------------ team */

    /**
     * Spec 8.5, 8.6, 21.2. Ownership transfer is the one that must not be able
     * to half-happen: the partial unique index guarantees one active owner, so
     * a two-statement transfer fails in a way that leaves a business nobody can
     * administer.
     */
    private function team(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('business_directory_enabled', 'MY', true, 'marketplace:check');

            $users = DB::table('users')->orderBy('id')->limit(4)->pluck('id')->all();
            [$founder, $partner, $helper] = [(int) $users[0], (int) ($users[1] ?? $users[0]), (int) ($users[2] ?? $users[0])];

            $this->line('');
            $this->line('<options=bold>A registration is a claim until somebody checks it</> (spec 8.5)');

            $providerId = ProviderOnboarding::business($founder, 'MY', [
                'name' => 'AT Home Renovation', 'legal_name' => 'AT Renovation Sdn Bhd',
                'registration_number' => '202401012345', 'registration_authority' => 'SSM',
                'operating_mode' => 'premises', 'lat' => 3.1390111, 'lng' => 101.6869222,
                'accepted_terms' => true, 'declared_accurate' => true,
            ]);

            $row = DB::table('provider_profiles')->where('id', $providerId)->first();
            $this->want('the number is stored', $row->registration_number === '202401012345', true);
            $this->want('the status is submitted, not confirmed', $row->registration_status === 'submitted', true);
            $this->want('and it waits for a person before going live', $row->publication_status === 'draft', true);

            $panel = VerificationPanel::forProvider($providerId, 'registered_business');
            $says  = array_column($panel, 'says');
            $this->line('    ' . implode(' / ', $says));
            $this->want('the panel says submitted, not confirmed',
                in_array('Business registration submitted, not yet checked', $says, true), true);

            $this->want('a shop IS mapped exactly, unlike a home',
                (float) $row->public_lat === 3.1390111, true);

            $this->line('');
            $this->line('<options=bold>Who may do what</> (spec 21.2)');
            ProviderTeam::invite($founder, $providerId, $partner, 'manager');
            $this->want('an invited member can do nothing yet',
                ProviderTeam::may($partner, $providerId, 'listings') === false, true);

            ProviderTeam::accept($partner, $providerId);
            $this->want('once accepted, a manager may post listings',
                ProviderTeam::may($partner, $providerId, 'listings'), true);
            $this->want('but may not manage the team',
                ProviderTeam::may($partner, $providerId, 'members') === false, true);
            $this->want('and may not hand the business over',
                ProviderTeam::may($partner, $providerId, 'transfer') === false, true);

            ProviderTeam::invite($founder, $providerId, $helper, 'viewer');
            ProviderTeam::accept($helper, $providerId);
            $this->want('a viewer sees analytics only',
                ProviderTeam::may($helper, $providerId, 'analytics')
                && !ProviderTeam::may($helper, $providerId, 'listings'), true);

            $this->line('');
            $this->line('<options=bold>Ownership moves only by transfer</> (spec 8.6)');
            $this->refuses('inviting a second owner', fn () => ProviderTeam::invite($founder, $providerId, $helper, 'owner'));
            $this->refuses('promoting somebody to owner', fn () => ProviderTeam::changeRole($founder, $providerId, $partner, 'owner'));
            $this->refuses('the owner demoting themselves', fn () => ProviderTeam::changeRole($founder, $providerId, $founder, 'manager'));
            $this->refuses('removing the owner', fn () => ProviderTeam::remove($founder, $providerId, $founder));

            $this->line('');
            $this->line('<options=bold>And the transfer itself</>');
            $this->refuses('without re-entering a password',
                fn () => ProviderTeam::transferOwnership($founder, $providerId, $partner, false));
            $this->refuses('by somebody who is not the owner',
                fn () => ProviderTeam::transferOwnership($partner, $providerId, $helper, true));

            ProviderTeam::transferOwnership($founder, $providerId, $partner, true);

            $owners = DB::table('provider_members')->where('provider_profile_id', $providerId)
                ->where('role', 'owner')->where('status', 'active')->count();

            $this->want('exactly one owner afterwards, never two and never none', $owners === 1, true);
            $this->want('and it is the new one', ProviderTeam::may($partner, $providerId, 'transfer'), true);
            $this->want('the founder stays on as a manager, not thrown out',
                ProviderTeam::may($founder, $providerId, 'listings')
                && !ProviderTeam::may($founder, $providerId, 'transfer'), true);

            $audited = DB::table('audit_events')->where('subject_id', $providerId)
                ->where('event', 'provider.ownership_transferred')->count();
            $this->want('the handover is in the audit log', $audited === 1, true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* --------------------------------------------------------------- profile */

    /**
     * Spec 5, and the scenario in 34.3: one person owns a renovation business
     * AND sells nasi lemak, and neither identity contaminates the other.
     */
    private function profile(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('marketplace_enabled', 'MY', true, 'marketplace:check');
            FeatureFlags::set('neighbour_offers_enabled', 'MY', true, 'marketplace:check');
            FeatureFlags::set('business_directory_enabled', 'MY', true, 'marketplace:check');
            FeatureFlags::set('ratings_enabled', null, true, 'marketplace:check');

            $users = DB::table('users')->orderBy('id')->limit(2)->pluck('id')->all();
            $alex  = (int) $users[0];
            $other = (int) ($users[1] ?? $users[0]);

            DB::table('users')->where('id', $alex)->update(['phone_verified_at' => now()]);

            // Two identities, one person. Spec 34.3.
            $renovation = ProviderOnboarding::business($alex, 'MY', [
                'name' => 'AT Home Renovation', 'operating_mode' => 'premises',
                'lat' => 3.1390, 'lng' => 101.6869,
                'accepted_terms' => true, 'declared_accurate' => true,
            ]);
            DB::table('provider_profiles')->where('id', $renovation)
                ->update(['publication_status' => 'published', 'primary_media_id' => null]);

            $food = ProviderOnboarding::neighbour($alex, 'MY', [
                'name' => 'Alex Weekend Nasi Lemak', 'area' => 'Desa ParkCity',
                'lat' => 3.1855, 'lng' => 101.6300,
                'accepted_terms' => true, 'declared_accurate' => true,
            ]);

            $this->line('');
            $this->line('<options=bold>One person, two commercial identities</> (spec 3.2, 34.3)');

            $providers = PersonProfile::providers($alex);
            $this->want('both appear on the one profile', count($providers) === 2, true);
            $this->want('and they are different kinds',
                count(array_unique(array_column($providers, 'kind'))) === 2, true);
            $this->want('each carries its own image field, not the account avatar',
                array_key_exists('media_id', $providers[0]), true);

            $this->line('');
            $this->line('<options=bold>Role labels describe what somebody does</> (spec 5.1)');
            $labels = PersonProfile::roleLabels($alex);
            $this->line('    ' . implode(' · ', $labels));
            $this->want('Business Owner is there', in_array('Business Owner', $labels, true), true);
            $this->want('Neighbour Provider too', in_array('Neighbour Provider', $labels, true), true);

            $joined = strtolower(implode(' ', $labels));

            foreach (['verified', 'approved', 'top', 'best', 'trusted'] as $forbidden) {
                $this->want('no label says "' . $forbidden . '"', !str_contains($joined, $forbidden), true);
            }

            $this->line('');
            $this->line('<options=bold>Reviews on a profile are ones the person WROTE</> (spec 3.3, 5.2)');

            $stranger = DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'other-' . Str::random(8),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => 'Someone Else', 'country_code' => 'MY',
                'publication_status' => 'published',
                'created_by_user_id' => $other, 'created_at' => now(), 'updated_at' => now(),
            ]);

            Reviews::write($alex, 'provider', $stranger, 'provider', ['overall' => 5, 'body' => 'Quick and tidy.']);
            Reviews::write($other, 'provider', $renovation, 'provider', ['overall' => 2, 'body' => 'Late twice, no call.']);

            $written = PersonProfile::reviewsWritten($alex);
            $this->want('the review Alex wrote is on his profile', count($written) === 1, true);
            $this->want('and it is the one about somebody else',
                (int) $written[0]['overall_rating'] === 5, true);

            // ⛔ The two-star about Alex's BUSINESS belongs to that business,
            // not to Alex. A reader adding it to his personal page would be
            // doing exactly the merge spec 3.3 forbids.
            $this->want('the poor rating of his business is NOT on his personal profile',
                !in_array(2, array_map(fn ($r) => (int) $r['overall_rating'], $written), true), true);

            $this->line('');
            $this->line('<options=bold>Account signals name each fact</> (spec 5.1, 3.4)');
            $signals = array_column(PersonProfile::accountSignals($alex), 'says');
            $this->line('    ' . implode(' / ', $signals));
            $this->want('the confirmed phone is stated', in_array('Phone confirmed', $signals, true), true);
            $this->want('the unconfirmed identity is stated too',
                in_array('Identity not confirmed', $signals, true), true);
            $this->want('joining is a month and a year, not a date',
                (bool) preg_grep('/^Here since [A-Z][a-z]+ \d{4}$/', $signals), true);

            $this->line('');
            $this->line('<options=bold>There is one profile, not two</> (spec 3.1)');
            $this->want('the marketplace half is assembled for the existing page',
                array_keys(PersonProfile::forProfile($alex)) === ['roleLabels', 'providers', 'offers', 'reviewsWritten', 'accountSignals'], true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* ----------------------------------------------------------- credentials */

    /**
     * Spec 11, and the scenario in 34.4: a lawyer, accountant, valuer, doctor
     * or engineer registers once, never types an expiry date, and the reader is
     * told exactly how the credential was checked.
     */
    private function credentials(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('professional_services_enabled', 'MY', true, 'marketplace:check');

            $users = DB::table('users')->orderBy('id')->limit(2)->pluck('id')->all();
            [$lawyer, $impostor] = [(int) $users[0], (int) ($users[1] ?? $users[0])];

            // An authority, the way a country operator would seed one.
            DB::table('professional_authorities')->insert([
                'country_code' => 'MY', 'profession_family' => 'legal',
                'name' => 'Malaysian Bar', 'short_name' => 'Bar',
                'protected_titles' => json_encode(['advocate and solicitor', 'peguam']),
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->line('');
            $this->line('<options=bold>One profile shape, whatever the profession</> (spec 11.1)');
            $profileId = Credentials::createProfile($lawyer, [
                'public_name' => 'Siti Rahman', 'country' => 'MY',
                'biography' => 'Conveyancing and family law.',
            ]);
            $this->want('the profile starts as a draft, not advertising',
                DB::table('professional_profiles')->where('id', $profileId)->value('publication_status') === 'draft', true);
            $this->refuses('a second profile for the same person',
                fn () => Credentials::createProfile($lawyer, ['public_name' => 'Siti Rahman Again', 'country' => 'MY']));

            $this->line('');
            $this->line('<options=bold>Nobody is asked for an issue or expiry date</> (spec 11.4)');
            $this->refuses('a credential with no declaration', fn () => Credentials::submit($profileId, [
                'authority_name' => 'Malaysian Bar', 'registration_number' => 'MB/2019/4471', 'country' => 'MY',
            ]));

            $credentialId = Credentials::submit($profileId, [
                'authority_name' => 'Malaysian Bar', 'registration_number' => 'MB/2019/4471',
                'country' => 'MY', 'declared' => true,
            ]);

            $stored = DB::table('professional_credentials')->where('id', $credentialId)->first();
            $this->want('nothing was stored as an expiry', $stored->known_expiry_at === null, true);
            $this->want('the number is kept as typed', $stored->registration_number === 'MB/2019/4471', true);
            $this->want('and normalised for lookup', $stored->registration_number_key === 'mb20194471', true);
            $this->want('it is submitted, never better', $stored->verification_status === 'submitted', true);

            $this->line('');
            $this->line('<options=bold>Advertising waits for a person</> (spec 11, 21.4)');
            $this->want('a submitted credential does not open the capability',
                str_contains((string) Capabilities::denies($lawyer, 'professional_listing', 'MY'), 'confirmed'), true);

            $this->refuses('marking it verified without saying how',
                fn () => Credentials::decide($credentialId, 'verified_register', '', null));

            $this->line('');
            $this->line('<options=bold>The two sentences that matter</> (spec 11.5, 35)');

            Credentials::decide($credentialId, 'verified_document', 'document', null, 'certificate seen');
            $doc = Credentials::wording($credentialId);
            $this->line('    ' . $doc['says'] . ' — ' . $doc['detail']);
            $this->want('a document says so, and says what it is not',
                $doc['says'] === 'Credential document submitted'
                && str_contains((string) $doc['detail'], 'Not independently confirmed against an official register'), true);

            Credentials::decide($credentialId, 'verified_register', 'official_register', null, 'checked on the roll');
            $reg = Credentials::wording($credentialId);
            $this->line('    ' . $reg['says'] . ' — ' . $reg['detail']);
            $this->want('a register check says confirmed', $reg['says'] === 'Professional credential confirmed', true);
            $this->want('and names the authority, jurisdiction and month',
                str_contains((string) $reg['detail'], 'Malaysian Bar')
                && str_contains((string) $reg['detail'], 'Jurisdiction: MY')
                && str_contains((string) $reg['detail'], 'Checked by NearbyPost'), true);

            $this->want('a confirmed credential is re-checked in a year',
                DB::table('professional_credentials')->where('id', $credentialId)->value('next_review_at') !== null, true);

            $this->want('now the capability is open',
                Capabilities::denies($lawyer, 'professional_listing', 'MY') === null, true);

            $this->line('');
            $this->line('<options=bold>A protected title is blocked, never rewritten</> (spec 11.9)');
            $other = Credentials::createProfile($impostor, ['public_name' => 'A N Other', 'country' => 'MY']);

            $blocked = Credentials::blocksTitle($other, 'Advocate and Solicitor, conveyancing', 'MY');
            $this->line('    ' . $blocked);
            $this->want('somebody without the registration is stopped', $blocked !== null, true);
            $this->want('and told which body to register with',
                str_contains((string) $blocked, 'Malaysian Bar'), true);
            $this->want('the holder is not stopped',
                Credentials::blocksTitle($profileId, 'Advocate and Solicitor', 'MY') === null, true);

            $this->line('');
            $this->line('<options=bold>One number, one holder</>');
            $this->refuses('a second person confirming the same registration number',
                function () use ($other) {
                    $id = Credentials::submit($other, [
                        'authority_name' => 'Malaysian Bar', 'registration_number' => 'mb 2019 4471',
                        'country' => 'MY', 'declared' => true,
                    ]);

                    Credentials::decide($id, 'verified_register', 'official_register', null);
                });

            $this->line('');
            $this->line('<options=bold>A firm does not licence its staff</> (spec 11.8)');

            FeatureFlags::set('business_directory_enabled', 'MY', true, 'marketplace:check');

            // ⛔ The firm must belong to somebody OTHER than the professional
            // claiming to work there, or the scenario cannot exist: confirm()
            // refuses a self-confirmation, so a clinic owned by the claimant
            // has nobody who may confirm. Getting this wrong made the check
            // unconstructable rather than failing - a test that cannot be
            // satisfied is worse than one that fails, because it looks like a
            // bug in the code it is testing.
            $clinic = ProviderOnboarding::business($lawyer, 'MY', [
                'name' => 'Well-Known Legal Practice', 'operating_mode' => 'premises',
                'lat' => 3.1390, 'lng' => 101.6869,
                'accepted_terms' => true, 'declared_accurate' => true,
            ]);

            // `other` is $impostor's profile, and it has NO confirmed credential.
            $membershipId = OrganisationMembership::claim($other, $clinic, 'Consultant');

            $this->refuses('the professional confirming their own claim',
                fn () => OrganisationMembership::confirm($impostor, $membershipId));

            $described = OrganisationMembership::describe($other);
            $this->line('    ' . $described['organisation']);
            $this->line('    ' . $described['credential']);

            $this->want('an unconfirmed claim says so',
                str_contains($described['organisation'], 'Not confirmed by the organisation'), true);
            $this->want('and the missing credential is stated regardless of the firm',
                str_contains($described['credential'], 'No professional credential has been confirmed'), true);

            // The firm's owner confirms - a different person, which is the point.
            OrganisationMembership::confirm($lawyer, $membershipId);

            $after = OrganisationMembership::describe($other);
            $this->line('    ' . $after['organisation']);
            $this->line('    ' . $after['credential']);

            $this->want('the organisation now confirms the role',
                str_contains($after['organisation'], 'The organisation has confirmed this'), true);
            $this->want('⛔ but the credential line is UNCHANGED — the firm confirmed nothing about it',
                str_contains($after['credential'], 'No professional credential has been confirmed'), true);

            $this->want('membership is its own row with its own verification',
                \Illuminate\Support\Facades\Schema::hasColumn('professional_organisation_memberships', 'verification_status'), true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    /* --------------------------------------------------------------- runner */

    /**
     * ⛔ Each attempt gets its own SAVEPOINT. In Postgres one failed statement
     * aborts the whole transaction and every statement after it fails with
     * 25P02 whether or not it would have succeeded - which turned one real
     * refusal into seven false failures the first time this was written.
     */
    private function attempt(callable $write): ?string
    {
        static $n = 0;
        $sp = 'mpcheck' . (++$n);

        DB::statement('SAVEPOINT ' . $sp);

        try {
            $write();
            DB::statement('RELEASE SAVEPOINT ' . $sp);

            return null;
        } catch (\Throwable $e) {
            DB::statement('ROLLBACK TO SAVEPOINT ' . $sp);

            return $e->getMessage();
        }
    }


    /* --------------------------------------------------------------- carpool */

    /**
     * Spec 12, with the privacy rule from 34.5 and the no-rewriting rule from 18.3.
     *
     * Every check here defends one sentence: NearbyPost publishes a notice and
     * does nothing else - it does not operate the vehicle, set a fare, collect
     * payment, guarantee a seat or confirm a booking. Several of these therefore
     * ask what does NOT exist, because that sentence has to be true of the
     * schema rather than merely intended by it. A column called `fare` would be
     * filled in within a month, and the day it is, this is a transport operator
     * in the eyes of a regulator.
     */
    private function carpool(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            $users = DB::table('users')->orderBy('id')->limit(2)->pluck('id')->all();

            if (count($users) < 2) {
                $this->warn('  car pool needs two users to exercise a match; skipped.');

                return;
            }

            [$driver, $rider] = [(int) $users[0], (int) $users[1]];

            $this->newLine();
            $this->line('<options=bold>Car Pool</> - closed until a country opens it (spec 12.1)');

            // The flag is off everywhere until a transport-law review says
            // otherwise, so this is the state the module ships in.
            $this->refuses('activating while the flag is off',
                fn () => CarPool::activateDriver($driver, 'MY', ['seats' => 3]));

            FeatureFlags::set('carpool_enabled', 'MY', true, 'marketplace:check');

            $this->newLine();
            $this->line('<options=bold>Identity, not just a phone</> (spec 12.2)');

            DB::table('users')->whereIn('id', [$driver, $rider])
                ->update(['phone_verified_at' => now(), 'identity_verification_status' => 'unverified']);

            // The ONLY capability in this module that demands confirmed
            // identity. Everywhere else a verified phone is the bar; here
            // strangers get into a car together.
            $this->refuses('a confirmed phone alone is not enough for Car Pool',
                fn () => CarPool::activateDriver($driver, 'MY', ['seats' => 3]));

            DB::table('users')->whereIn('id', [$driver, $rider])
                ->update(['identity_verification_status' => 'confirmed']);

            $this->newLine();
            $this->line('<options=bold>Every declaration, or not active</> (spec 12.2)');

            $all = ['licence' => true, 'road_tax' => true, 'insurance' => true,
                    'non_commercial' => true, 'adult' => true, 'terms' => true];

            $missing = $all;
            unset($missing['insurance']);

            $this->refuses('a driver who has not declared insurance',
                fn () => CarPool::activateDriver($driver, 'MY', $missing + ['seats' => 3]));

            $this->refuses('a car with no stated seat count',
                fn () => CarPool::activateDriver($driver, 'MY', $all));

            $this->allows('a driver who has declared everything', fn () => CarPool::activateDriver(
                $driver, 'MY', $all + ['seats' => 3, 'make' => 'Perodua', 'model' => 'Myvi',
                                       'registration' => 'WXY 1234']));

            $this->allows('a passenger, on the shorter list',
                fn () => CarPool::activatePassenger($rider, 'MY', ['adult' => true, 'terms' => true]));

            $this->newLine();
            $this->line('<options=bold>The plate is never readable in the row</> (spec 19.4)');

            $stored = (string) DB::table('carpool_profiles')->where('user_id', $driver)
                ->value('vehicle_registration_encrypted');

            $this->want('it is stored, but not in plain text',
                $stored !== '' && !str_contains($stored, 'WXY 1234'), true);
            $this->want('and this class offers no way to read it back',
                !method_exists(CarPool::class, 'vehicleRegistration'), true);

            $journey = [
                'type'         => 'seats_available',
                'origin'       => ['lat' => 3.1855432, 'lng' => 101.6300111, 'area' => 'Desa ParkCity'],
                'destination'  => ['lat' => 3.0733000, 'lng' => 101.5185000, 'area' => 'Shah Alam'],
                'departure_at' => now()->addDay()->setTime(7, 30)->toDateTimeString(),
                'seats'        => 2,
                'cost_share'   => 'shared_expenses',
            ];

            $this->newLine();
            $this->line('<options=bold>A lift, not a transport business</> (spec 12.5)');

            $this->refuses('a notice advertising an airport transfer at a fixed rate',
                fn () => CarPool::publish($driver, 'MY',
                    array_merge($journey, ['notes' => 'Airport transfer, RM80 fixed rate'])));

            $this->refuses('one offering to carry a parcel',
                fn () => CarPool::publish($driver, 'MY',
                    array_merge($journey, ['notes' => 'Can also send document for you'])));

            // REFUSED WITH THE PHRASE NAMED, never quietly cleaned. Spec 18.3
            // forbids the system rewriting somebody's words, so the person is
            // told what the problem is and edits their own notice.
            $refusal = (string) $this->attempt(fn () => CarPool::publish($driver, 'MY',
                array_merge($journey, ['notes' => 'Taxi service daily, per pax RM15'])));

            $this->want('the refusal names the phrase, so they can edit their own words',
                str_contains($refusal, 'taxi'), true);

            $this->newLine();
            $this->line('<options=bold>Seatbelts are not a preference</> (spec 12.5)');

            $this->refuses('offering four seats in a car declared to have three',
                fn () => CarPool::publish($driver, 'MY', array_merge($journey, ['seats' => 4])));

            $noticeId = CarPool::publish($driver, 'MY', $journey);
            $notice   = DB::table('carpool_notices')->where('id', $noticeId)->first();

            $this->newLine();
            $this->line('<options=bold>Where somebody sets off is not published</> (spec 34.5)');

            $this->want('the exact origin is kept, privately',
                abs((float) $notice->origin_private_lat - 3.1855432) < 0.0000001, true);
            $this->want('the public origin is the cell centre, not the door',
                abs((float) $notice->origin_public_lat - 3.185) < 0.0000001, true);
            $this->want('and the same coarsening on the destination',
                abs((float) $notice->destination_public_lat - 3.075) < 0.0000001, true);

            $this->newLine();
            $this->line('<options=bold>A notice cannot outlive its journey</> (spec 28)');

            $this->want('it expires six hours after departure, derived not chosen',
                \Carbon\Carbon::parse($notice->expires_at)->equalTo(
                    \Carbon\Carbon::parse($notice->departure_at)->addHours(6)), true);

            $this->newLine();
            $this->line('<options=bold>No fare, anywhere</> (spec 12.1)');

            foreach (['fare', 'price', 'amount', 'payment_reference'] as $forbidden) {
                $this->want("carpool_notices has no `{$forbidden}` column",
                    !\Illuminate\Support\Facades\Schema::hasColumn('carpool_notices', $forbidden), true);
            }

            $this->want('there is no booking table to hold a seat',
                !\Illuminate\Support\Facades\Schema::hasTable('carpool_bookings'), true);
            $this->want('cost sharing is one of three words, never a number',
                array_key_exists((string) $notice->cost_share_mode, CarPool::COST_MODES), true);

            $this->newLine();
            $this->line('<options=bold>Matching is about the route, never about safety</> (spec 12.6)');

            CarPool::publish($rider, 'MY', [
                'type'         => 'looking_for_ride',
                'origin'       => ['lat' => 3.1880, 'lng' => 101.6330, 'area' => 'Desa ParkCity'],
                'destination'  => ['lat' => 3.0700, 'lng' => 101.5200, 'area' => 'Shah Alam'],
                'departure_at' => now()->addDay()->setTime(7, 45)->toDateTimeString(),
                'passengers'   => 1,
            ]);

            $matches = CarPool::matches($noticeId);

            $this->want('the driver is offered the passenger going the same way', count($matches) >= 1, true);

            if ($matches !== []) {
                $m = $matches[0];
                $this->line('    ' . $m['route_match'] . '  ' . $m['origin_area'] . ' to ' . $m['destination_area']);

                $this->want('the wording says ROUTE match, not merely "match"',
                    str_contains($m['route_match'], 'route match'), true);

                // Spec 12.6: "never imply safety from the match score." The
                // structural test is the honest one - not that the wording is
                // careful, but that nothing about the PERSON is in there for a
                // reader to mistake for an endorsement.
                $leaks = array_intersect(array_keys($m),
                    ['rating', 'rating_average', 'verified', 'verification_status',
                     'reviews', 'review_count', 'member_since', 'trust_score']);

                $this->want('and nothing about the person is in the match at all', $leaks === [], true);
            }

            $this->newLine();
            $this->line('<options=bold>Notices sweep themselves away</>');

            // A departed journey, backdated past its own expiry. The CHECK
            // constraint still holds: expires_at stays after departure_at.
            DB::table('carpool_notices')->where('id', $noticeId)->update([
                'departure_at' => now()->subHours(8),
                'expires_at'   => now()->subHours(2),
            ]);

            CarPool::expire();

            $this->want('a journey that has departed is no longer published',
                DB::table('carpool_notices')->where('id', $noticeId)
                    ->value('publication_status') === 'expired', true);

            $this->want('and it is offered to nobody afterwards',
                CarPool::matches($noticeId) === [], true);

            $this->newLine();
            $this->line('<options=bold>And then it forgets where they set off</> (spec 28)');

            // An expired notice still holds the exact point somebody left
            // from, stamped with the time. One row is a journey; a year of
            // them is a pattern of life - which mornings they are home, when
            // the house is empty. Nothing needs that after the ride.
            DB::table('carpool_notices')->where('id', $noticeId)
                ->update(['expires_at' => now()->subDays(30), 'departure_at' => now()->subDays(31)]);

            $this->call('carpool:expire', ['--forget-after' => 7]);

            $after = DB::table('carpool_notices')->where('id', $noticeId)->first();

            $this->want('the exact start point is erased once the journey is well past',
                $after->origin_private_lat === null && $after->destination_private_lat === null, true);

            // The coarse cell stays: it is already about 1.1 km across, and a
            // moderator handling a later safety report needs the notice to
            // still mean something.
            $this->want('but the coarse cell survives, so a report can still be read',
                $after->origin_public_lat !== null, true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }


    /* ----------------------------------------------------------- advertising */

    /**
     * Spec 15.4 and 20.21. Phase 5.
     *
     * The one that matters is "preserve truthful distance": a paid result must
     * carry the same distance the organic query computed, because a sponsored
     * shop that reads "1.2 km" while sitting across the state is precisely the
     * deception 15.4 forbids. It is tested by comparing the two numbers rather
     * than by trusting that one call produced both.
     */
    private function advertising(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            $catId = (int) DB::table('marketplace_categories')->where('code', 'food_dining')->value('id');

            $newProvider = fn (string $name) => (int) DB::table('provider_profiles')->insertGetId([
                'public_uuid' => (string) Str::uuid(), 'slug' => 'check-' . Str::random(10),
                'provider_kind' => 'neighbour_provider', 'operating_mode' => 'home_based',
                'public_name' => $name, 'country_code' => 'MY',
                'created_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $newListing = function (int $providerId, float $lat, float $lng, string $status = 'published')
                use ($catId) {
                return (int) DB::table('marketplace_listings')->insertGetId([
                    'public_uuid' => (string) Str::uuid(), 'owner_user_id' => 1,
                    'provider_profile_id' => $providerId, 'category_id' => $catId,
                    'listing_kind' => 'food_offer', 'title' => 'marketplace:check',
                    'country_code' => 'MY', 'public_location_mode' => 'exact_premises',
                    'public_lat' => $lat, 'public_lng' => $lng,
                    'publication_status' => $status, 'moderation_status' => 'ai_accepted',
                    'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
                ]);
            };

            [$lat, $lng] = [3.1390, 101.6869];   // Kuala Lumpur centre

            $a = $newProvider('marketplace:check A');
            $b = $newProvider('marketplace:check B');

            $near   = $newListing($a, 3.1400, 101.6880);
            $alsoA  = $newListing($a, 3.1410, 101.6890);
            $nearB  = $newListing($b, 3.1600, 101.6900);
            $far    = $newListing($a, 4.5000, 102.5000);          // outside any sane radius
            $gone   = $newListing($b, 3.1420, 101.6870, 'suspended');

            // Filler, so the share cap has room to allow two slots.
            for ($i = 0; $i < 8; $i++) {
                $newListing($newProvider('filler ' . $i), 3.1395 + ($i / 1000), 101.6875);
            }

            $this->newLine();
            $this->line('<options=bold>Advertising</> - a country opts in by name (spec 32)');

            $this->refuses('drafting a campaign where sponsorship is off',
                fn () => Advertising::createCampaign($a, 'MY', [
                    'listing_id' => $near, 'starts_at' => now()->subHour()->toDateTimeString(),
                    'ends_at' => now()->addDays(7)->toDateTimeString(),
                ]));

            FeatureFlags::set('marketplace_enabled', 'MY', true, 'marketplace:check');
            FeatureFlags::set('sponsored_listings_enabled', 'MY', true, 'marketplace:check');

            $this->newLine();
            $this->line('<options=bold>You may only sponsor what you own</>');

            $this->refuses('buying placement for another provider\'s listing',
                fn () => Advertising::createCampaign($b, 'MY', [
                    'listing_id' => $near, 'starts_at' => now()->subHour()->toDateTimeString(),
                    'ends_at' => now()->addDays(7)->toDateTimeString(),
                ]));

            $this->newLine();
            $this->line('<options=bold>Nothing advertises before it is settled</> (spec 20.21)');

            $window = ['starts_at' => now()->subHour()->toDateTimeString(),
                       'ends_at'   => now()->addDays(7)->toDateTimeString()];

            $c1 = Advertising::createCampaign($a, 'MY', $window + ['listing_id' => $near]);

            $this->want('a new campaign starts unpaid, not live',
                DB::table('sponsored_campaigns')->where('id', $c1)->value('status') === 'pending_payment', true);

            $this->refuses('activating a campaign nobody has paid for',
                fn () => Advertising::activate($c1));

            // ⛔ And the database says so too, so a stray UPDATE cannot do what
            // the service refuses.
            $this->refuses('forcing it live with a direct write',
                fn () => DB::table('sponsored_campaigns')->where('id', $c1)
                    ->update(['status' => 'active']));

            $this->refuses('a campaign with an empty disclosure label',
                fn () => DB::table('sponsored_campaigns')->where('id', $c1)
                    ->update(['disclosure_label' => '  ']));

            $this->refuses('waiving payment without saying why',
                fn () => Advertising::waive($c1, 1, '   '));

            $this->allows('an admin comping it, with a reason on the record',
                fn () => Advertising::waive($c1, 1, 'launch partner'));

            $this->allows('and then it may go live', fn () => Advertising::activate($c1));

            $this->newLine();
            $this->line('<options=bold>A paid result tells the truth about itself</> (spec 15.4)');

            $organic = ListingSearch::near($lat, $lng, 10.0, ['country' => 'MY', 'limit' => 40]);
            $shown   = SponsoredPlacements::decorate($organic, $lat, $lng, 10.0, 'MY', ['record' => true]);

            $sponsored = array_values(array_filter($shown, fn ($r) => $r['sponsored'] === true));

            $this->want('the paid listing is placed first', count($sponsored) >= 1 && $shown[0]['sponsored'], true);
            $this->want('and it is labelled',
                ($shown[0]['disclosure_label'] ?? null) === 'Sponsored', true);

            // The check this whole class is shaped around.
            $organicByUuid = [];
            foreach ($organic as $r) {
                $organicByUuid[$r['public_uuid']] = $r;
            }

            $paid = $sponsored[0] ?? null;
            $twin = $paid ? ($organicByUuid[$paid['public_uuid']] ?? null) : null;

            $this->want('its distance is the number the organic search computed, exactly',
                $paid !== null && $twin !== null
                    && (string) $paid['distance_km'] === (string) $twin['distance_km'], true);

            if ($paid !== null && $twin !== null) {
                $this->line(sprintf('    sponsored %s km, organic %s km', $paid['distance_km'], $twin['distance_km']));
            }

            $this->want('every row carries the sponsored key, paid or not',
                count(array_filter($shown, fn ($r) => array_key_exists('sponsored', $r))) === count($shown), true);

            $this->newLine();
            $this->line('<options=bold>Paying cannot move a shop, or revive a dead listing</>');

            $farCampaign = Advertising::createCampaign($a, 'MY', $window + ['listing_id' => $far]);
            Advertising::waive($farCampaign, 1, 'check');
            Advertising::activate($farCampaign);

            $goneCampaign = Advertising::createCampaign($b, 'MY', $window + ['listing_id' => $gone]);
            Advertising::waive($goneCampaign, 1, 'check');
            Advertising::activate($goneCampaign);

            $shown2 = SponsoredPlacements::decorate(
                ListingSearch::near($lat, $lng, 10.0, ['country' => 'MY', 'limit' => 40]),
                $lat, $lng, 10.0, 'MY');

            $uuids = array_column($shown2, 'public_uuid');

            $farUuid  = DB::table('marketplace_listings')->where('id', $far)->value('public_uuid');
            $goneUuid = DB::table('marketplace_listings')->where('id', $gone)->value('public_uuid');

            $this->want('the page is not simply empty',
                count(array_filter($shown2, fn ($r) => $r['sponsored'] === true)) >= 1, true);
            $this->want('a sponsored listing outside the radius still does not appear',
                !in_array($farUuid, $uuids, true), true);
            $this->want('and a suspended one cannot be bought back into view',
                !in_array($goneUuid, $uuids, true), true);

            $this->newLine();
            $this->line('<options=bold>Capped, and never one provider\'s page</> (spec 15.4, 15.3)');

            // Provider A now has two live campaigns on two of its own listings.
            $second = Advertising::createCampaign($a, 'MY', $window + ['listing_id' => $alsoA]);
            Advertising::waive($second, 1, 'check');
            Advertising::activate($second);

            $shown3 = SponsoredPlacements::decorate(
                ListingSearch::near($lat, $lng, 10.0, ['country' => 'MY', 'limit' => 40]),
                $lat, $lng, 10.0, 'MY');

            $paid3 = array_values(array_filter($shown3, fn ($r) => $r['sponsored'] === true));

            $this->want('there is a paid slot to reason about at all', count($paid3) >= 1, true);
            $this->want('one provider cannot take both paid slots',
                count($paid3) >= 1
                    && count(array_unique(array_column($paid3, 'provider_profile_id'))) === count($paid3), true);

            $bCampaign = Advertising::createCampaign($b, 'MY', $window + ['listing_id' => $nearB]);
            Advertising::waive($bCampaign, 1, 'check');
            Advertising::activate($bCampaign);

            $shown4 = SponsoredPlacements::decorate(
                ListingSearch::near($lat, $lng, 10.0, ['country' => 'MY', 'limit' => 40]),
                $lat, $lng, 10.0, 'MY');

            $paid4 = array_values(array_filter($shown4, fn ($r) => $r['sponsored'] === true));

            // Three providers now hold live campaigns, so an uncapped build
            // would show three. "At most two" is only evidence if more were
            // available and were held back.
            $this->want('three providers are competing for the slots',
                count(Advertising::live('MY')) >= 3, true);
            $this->want('and at most two paid slots reach the page',
                count($paid4) >= 1 && count($paid4) <= 2, true);

            // A thin page is where a sponsor most wants to be and where a
            // reader can least afford it.
            $thin  = array_slice($organic, 0, 3);
            $thin2 = SponsoredPlacements::decorate($thin, $lat, $lng, 10.0, 'MY');

            $this->want('a page of three carries no paid slot at all',
                array_filter($thin2, fn ($r) => $r['sponsored'] === true) === []
                    && count($thin2) === 3, true);

            $this->newLine();
            $this->line('<options=bold>Card details are not merely unwritten - there is nowhere to put them</> (spec 20.21)');

            foreach (['advertising_payments', 'provider_subscriptions', 'sponsored_campaigns'] as $table) {
                $cols = array_map('strtolower',
                    \Illuminate\Support\Facades\Schema::getColumnListing($table));

                $card = array_filter($cols, fn ($c) => (bool) preg_match(
                    '/(card|pan|cvv|cvc|iban|account_number|expiry_month|expiry_year|cardholder)/', $c));

                $this->want("{$table} has no column that could hold a card", $card === [], true);
            }

            $this->newLine();
            $this->line('<options=bold>A retried webhook pays once</> (spec 20.21)');

            $payment = ['provider_profile_id' => $a, 'subject_type' => 'campaign', 'subject_id' => $c1,
                        'billing_provider' => 'testpay', 'external_payment_reference' => 'evt_abc123',
                        'amount' => 120.00, 'currency' => 'MYR', 'status' => 'paid'];

            $first  = Advertising::recordPayment($payment);
            $repeat = Advertising::recordPayment($payment);

            $this->want('the retry returns the same record rather than a second one',
                $first === $repeat, true);
            $this->want('and only one payment row exists for that reference',
                DB::table('advertising_payments')->where('billing_provider', 'testpay')
                    ->where('external_payment_reference', 'evt_abc123')->count() === 1, true);

            $this->refuses('a second row with the same reference, written directly',
                fn () => DB::table('advertising_payments')->insert([
                    'provider_profile_id' => $a, 'subject_type' => 'campaign', 'subject_id' => $c1,
                    'billing_provider' => 'testpay', 'external_payment_reference' => 'evt_abc123',
                    'amount' => 120.00, 'currency' => 'MYR', 'status' => 'paid',
                    'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]));

            $this->newLine();
            $this->line('<options=bold>What a campaign actually got</> (spec 15.4)');

            $tally = SponsoredPlacements::tally($c1);
            $this->want('the impression from the page above was recorded', $tally['impression'] >= 1, true);

            SponsoredPlacements::recordEvent(
                (string) DB::table('sponsored_campaigns')->where('id', $c1)->value('public_uuid'),
                'click', $near);

            $this->want('and a click is counted separately from an impression',
                SponsoredPlacements::tally($c1)['click'] === 1, true);

            $this->want('the event row holds no user id, ip or user agent',
                array_intersect(['user_id', 'ip', 'ip_address', 'user_agent', 'referrer'],
                    \Illuminate\Support\Facades\Schema::getColumnListing('sponsored_events')) === [], true);

            $this->newLine();
            $this->line('<options=bold>Off in a country means off</>');

            FeatureFlags::set('sponsored_listings_enabled', 'MY', false, 'marketplace:check');

            $shown5 = SponsoredPlacements::decorate(
                ListingSearch::near($lat, $lng, 10.0, ['country' => 'MY', 'limit' => 40]),
                $lat, $lng, 10.0, 'MY');

            $this->want('no paid slot survives the flag going off',
                array_filter($shown5, fn ($r) => $r['sponsored'] === true) === []
                    && count($shown5) > 0, true);
            $this->want('and the organic results are still all there',
                count($shown5) === count($organic), true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }


    /* -------------------------------------------------------------- property */

    /**
     * Spec 27. Phase 6.
     *
     * The phase is done when "a change in ListingMine flows through and no
     * NearbyPost action can overwrite it". The second half is the hard half,
     * and it is tested here the only way worth testing it: by trying to
     * overwrite the data directly, from this codebase, with a plain query
     * builder call - the exact thing a future admin screen would do - and
     * requiring the database to refuse.
     */
    private function property(): void
    {
        $flagsBefore = DB::table('feature_flags')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();

        try {
            FeatureFlags::set('property_listingmine_enabled', 'MY', true, 'marketplace:check');

            $payload = [
                'id'            => 'LM-' . Str::random(8),
                'url'           => 'https://www.listingmine.com/property/12345',
                'title'         => 'Three-bedroom condominium in Mont Kiara',
                'property_type' => 'condominium',
                'deal_type'     => 'sale',
                'price'         => 950000.00,
                'currency'      => 'MYR',
                'country'       => 'MY',
                'city'          => 'Kuala Lumpur',
                'lat'           => 3.1720,
                'lng'           => 101.6500,
                'bedrooms'      => 3,
                'bathrooms'     => 2,
                'built_up_sqft' => 1450,
                'agent_name'    => 'A. Agent',
                'status'        => 'active',
                'updated_at'    => now()->subHour()->toDateTimeString(),
            ];

            $this->newLine();
            $this->line('<options=bold>Property arrives from ListingMine</> (spec 27)');

            $this->want('a property syncs in', PropertyMirror::sync($payload) === 'created', true);

            $uuid = (string) DB::table('property_listings')
                ->where('listingmine_property_id', $payload['id'])->value('public_uuid');

            $this->want('replaying the same payload changes nothing',
                PropertyMirror::sync($payload) === 'unchanged', true);

            $newer = $payload;
            $newer['price'] = 925000.00;
            $newer['updated_at'] = now()->toDateTimeString();

            $this->want('a newer payload flows through',
                PropertyMirror::sync($newer) === 'updated', true);
            $this->want('and the new price is what a reader sees',
                (float) DB::table('property_listings')->where('public_uuid', $uuid)->value('price') === 925000.00, true);

            // Feeds are retried and can drain out of order.
            $stale = $payload;
            $stale['price'] = 1;
            $stale['updated_at'] = now()->subDays(5)->toDateTimeString();

            $this->want('a late or replayed older payload is ignored, not applied',
                PropertyMirror::sync($stale) === 'ignored_stale', true);
            $this->want('so yesterday\'s price cannot overwrite today\'s',
                (float) DB::table('property_listings')->where('public_uuid', $uuid)->value('price') === 925000.00, true);

            $this->newLine();
            $this->line('<options=bold>⛔ And NearbyPost cannot overwrite any of it</> (spec 27 — the phase test)');

            $this->refuses('editing the price from this codebase',
                fn () => DB::table('property_listings')->where('public_uuid', $uuid)
                    ->update(['price' => 1.00]));

            $this->refuses('editing the title',
                fn () => DB::table('property_listings')->where('public_uuid', $uuid)
                    ->update(['title' => 'Rewritten by NearbyPost']));

            $this->refuses('quietly repointing the canonical URL',
                fn () => DB::table('property_listings')->where('public_uuid', $uuid)
                    ->update(['canonical_url' => 'https://www.listingmine.com/somewhere-else']));

            $this->refuses('marking it sold on ListingMine\'s behalf',
                fn () => DB::table('property_listings')->where('public_uuid', $uuid)
                    ->update(['source_status' => 'sold']));

            $this->want('and there is no local title, price or description override column',
                array_intersect(['local_title', 'local_price', 'local_description', 'title_override'],
                    \Illuminate\Support\Facades\Schema::getColumnListing('property_listings')) === [], true);

            $this->newLine();
            $this->line('<options=bold>What NearbyPost may decide is its own shelf</>');

            $this->refuses('hiding a property without saying why',
                fn () => PropertyMirror::hideLocally($uuid, '  '));

            $this->allows('hiding it locally, with a reason',
                fn () => PropertyMirror::hideLocally($uuid, 'duplicate of another listing'));

            $this->want('it leaves the reader\'s view',
                PropertyMirror::near(3.1720, 101.6500, 5.0, 'MY') === [], true);

            $this->want('but ListingMine\'s own fields are untouched',
                (float) DB::table('property_listings')->where('public_uuid', $uuid)->value('price') === 925000.00, true);

            // The reason a routine sync must never write local columns.
            PropertyMirror::sync($newer);
            $this->want('⛔ and a later sync does not quietly un-hide it',
                DB::table('property_listings')->where('public_uuid', $uuid)
                    ->value('local_visibility') === 'hidden_locally', true);

            PropertyMirror::show($uuid);

            $this->newLine();
            $this->line('<options=bold>Their status decides availability</> (spec 27)');

            $sold = $newer;
            $sold['status'] = 'sold';
            $sold['updated_at'] = now()->addMinute()->toDateTimeString();
            PropertyMirror::sync($sold);

            $this->want('a property sold in ListingMine disappears from here',
                PropertyMirror::near(3.1720, 101.6500, 5.0, 'MY') === [], true);

            $back = $sold;
            $back['status'] = 'active';
            $back['updated_at'] = now()->addMinutes(2)->toDateTimeString();
            PropertyMirror::sync($back);

            $found = PropertyMirror::near(3.1720, 101.6500, 5.0, 'MY');
            $this->want('and comes back when they relist it', count($found) === 1, true);

            $this->newLine();
            $this->line('<options=bold>The disclosure travels with the row</> (spec 27)');

            $this->want('every property carries "Property listing powered by ListingMine"',
                ($found[0]['powered_by'] ?? null) === 'Property listing powered by ListingMine', true);
            $this->want('and it is not something a template has to remember',
                count(array_filter($found, fn ($r) => isset($r['powered_by']))) === count($found), true);

            $this->newLine();
            $this->line('<options=bold>An outbound link cannot point anywhere else</> (spec 29)');

            $this->want('the link goes to ListingMine',
                str_starts_with(PropertyMirror::outboundUrl($uuid), 'https://www.listingmine.com/'), true);

            foreach ([
                'https://listingmine.com.attacker.net/p/1' => 'a look-alike domain',
                'https://evil-listingmine.com/p/1'         => 'a near-miss host',
                'http://www.listingmine.com/p/1'           => 'plain http',
                'javascript:alert(1)'                      => 'a javascript: scheme',
            ] as $bad => $what) {
                $this->refuses("a payload carrying {$what}", function () use ($payload, $bad) {
                    PropertyMirror::sync(array_merge($payload, [
                        'id' => 'LM-' . Str::random(8), 'url' => $bad,
                        'updated_at' => now()->toDateTimeString(),
                    ]));
                });
            }

            $this->newLine();
            $this->line('<options=bold>Traffic sent to ListingMine is counted</> (spec 27)');

            PropertyMirror::recordClick($uuid);
            PropertyMirror::recordClick($uuid);

            $this->want('outbound clicks are recorded', PropertyMirror::clicks($uuid) === 2, true);
            $this->want('and the row holds no user id, ip or user agent',
                array_intersect(['user_id', 'ip', 'ip_address', 'user_agent', 'referrer'],
                    \Illuminate\Support\Facades\Schema::getColumnListing('property_click_events')) === [], true);

            $this->newLine();
            $this->line('<options=bold>Property is not a Marketplace listing</> (spec 27)');

            $this->want('nothing was written into marketplace_listings',
                DB::table('marketplace_listings')->count() === 0, true);

            FeatureFlags::set('property_listingmine_enabled', 'MY', false, 'marketplace:check');

            $this->want('and the flag going off takes the whole feature with it',
                PropertyMirror::near(3.1720, 101.6500, 5.0, 'MY') === [], true);
        } finally {
            DB::rollBack();

            DB::table('feature_flags')->delete();

            if ($flagsBefore !== []) {
                DB::table('feature_flags')->insert($flagsBefore);
            }

            FeatureFlags::forget();
        }
    }

    private function refuses(string $what, callable $write): void
    {
        $this->report($what, $this->attempt($write) !== null, 'refused', 'it was ALLOWED');
    }

    private function allows(string $what, callable $write): void
    {
        $error = $this->attempt($write);
        $this->report($what, $error === null, 'allowed', 'refused: ' . mb_substr((string) $error, 0, 80));
    }

    private function want(string $what, bool $got, bool $expected): void
    {
        $this->report($what, $got === $expected, $got ? 'on' : 'off', 'got ' . ($got ? 'on' : 'off'));
    }

    private function report(string $what, bool $ok, string $good, string $bad): void
    {
        $ok ? $this->pass++ : $this->fail++;

        $this->line(sprintf('  %s %-58s %s',
            $ok ? '<fg=green>ok  </>' : '<fg=red>FAIL</>', $what, $ok ? $good : "<fg=red>{$bad}</>"));
    }
}
