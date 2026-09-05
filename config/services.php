<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'vision_model' => (string) env('DEEPSEEK_VISION_MODEL', 'deepseek-v4-flash-vision-exp'),   // reads the community report photo
    ],

    /*
     | Geocoding. Our own Nominatim (Malaysia/Singapore/Brunei extract, Docker
     | container "nominatim") has no rate limit; when it is down the code
     | falls back to the public instance, throttled. HERE is the one source
     | that is not OpenStreetMap underneath - off until a key is set.
     */
    // every country the site puts on its map (ISO2, comma-separated); a place elsewhere is a national story
    'site_countries' => (string) env('SITE_COUNTRIES', 'MY'),

    // Community Reports (owner's guide, Sep 2026): the GPS pin, the editor's pass, reactions, reports, profiles
    'community' => [
        'enabled'  => (bool) env('COMMUNITY_REPORTS', true),
        'provider' => (string) env('COMMUNITY_AI_PROVIDER', 'openai'),   // openai (gpt-5-nano, reads the photo) or deepseek
    ],

    'openai' => [
        'key'     => (string) env('OPENAI_API_KEY', ''),
        'model'   => (string) env('OPENAI_MODEL', 'gpt-5-nano'),
        'base'    => (string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 40),
    ],

    'nominatim' => [
        'url'       => rtrim((string) env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/'),
        // the countries the main container holds, and one container per further region
        'countries' => (string) env('NOMINATIM_LOCAL_COUNTRIES', 'my,sg,bn'),
        'regions'   => (string) env('NOMINATIM_REGIONS', ''),
    ],

    'here' => [
        'key' => env('HERE_API_KEY'),
    ],

    /*
     | Where a first-time reader probably is, so the feed opens near them
     | rather than always in Kuala Lumpur. Any provider returning JSON with a
     | "city" field will do; {ip} is replaced with the address. Turn it off and
     | the site simply falls back to its default place.
     |
     | ⚠ This sends the reader's IP to whoever is configured here.
     */
    /*
     | Reader submissions. A contributor an editor has vouched for publishes on
     | the automatic news check alone; everyone else waits in the queue at
     | /admin/contributions. Turn this on to queue every post from everyone.
     */
    'contributions' => [
        'always_review' => (bool) env('CONTRIBUTIONS_ALWAYS_REVIEW', false),
    ],

    'geoip' => [
        'enabled' => (bool) env('GEOIP_ENABLED', true),
        'url' => env('GEOIP_URL', 'https://ipwho.is/{ip}'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    // The AI control panel reads keys by NAME from here (ai_providers.key_name). Keys stay in .env.
    // Spec 27. ListingMine stays the source of truth; NearbyPost only mirrors.
    // Empty until ListingMine exposes a property feed - property:sync says so
    // out loud rather than failing quietly.
    'listingmine' => [
        'property_feed_url' => (string) env('LISTINGMINE_PROPERTY_FEED_URL', ''),
        'token'             => (string) env('LISTINGMINE_TOKEN', ''),
    ],

    'ai' => [
        'keys' => [
            'deepseek' => (string) env('DEEPSEEK_API_KEY', ''),
            'openai'   => (string) env('OPENAI_API_KEY', ''),
            'kimi'     => (string) env('MOONSHOT_API_KEY', ''),
            'gemini'   => (string) env('GEMINI_API_KEY', ''),
            'claude'   => (string) env('ANTHROPIC_API_KEY', ''),
            'minimax'  => (string) env('MINIMAX_API_KEY', ''),
        ],
        'adapter' => (string) env('AI_ADAPTER', 'deepseek'),
    ],
];
