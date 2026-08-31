<?php

namespace App\Services\Classification;

/**
 * Spec v2.0 sections 2.6, 2.7, 4 and 8 - what to refuse, and why.
 *
 * Until now nothing could be refused. Everything ingested was classified, so
 * opinion columns, speculation and clickbait were filed as news beside reported
 * facts. That was tolerable while every source was chosen by hand. It is not
 * tolerable now that sources are discovered automatically and reach the feed
 * without a person ever looking at them.
 *
 * The checks are deliberately cheap and ordered cheapest first: a URL pattern
 * costs nothing, a word count costs a string scan, and neither needs the model.
 * Refusing early is the whole point - it is the requests we never make that
 * make this affordable.
 *
 * Each refusal carries the spec's error code, so a rejected item can be
 * explained rather than merely absent.
 */
class ContentPolicy
{
    /** Spec 4.2 - top-level domains that are not news. */
    private const SPAM_TLDS = ['.xyz', '.click', '.loan', '.win', '.bid', '.date', '.download', '.top', '.gq'];

    /** Spec 4.2 - domain fragments that announce themselves. */
    private const SPAM_DOMAIN_HINTS = ['free-money', 'click-here', 'win-prize', 'freegift', 'casino', 'betting'];

    private const OFF_TOPIC_TLDS = ['.porn', '.adult', '.gambling', '.sex'];

    /** Spec 4.4 - blacklist, counted rather than merely matched. */
    private const BLACKLIST = [
        'gambling' => ['casino', 'poker', 'jackpot', 'roulette', 'blackjack', 'betting odds'],
        'adult'    => ['porn', 'escort', 'xxx', 'nude photos'],
        'piracy'   => ['free download', 'torrent', 'crack version', 'serial key'],
        'clickbait'=> ["you won't believe", 'you wont believe', 'shocking', 'mind-blowing', 'viral now'],
        'affiliate'=> ['buy now', 'limited offer', 'discount code', 'affiliate link'],
    ];

    /** Spec 2.6 - speculation and opinion markers. */
    private const SPECULATION = ['rumour', 'rumor', 'rumoured', 'rumored', 'allegedly', 'reportedly may'];
    private const HEDGING     = ['might ', 'could possibly', 'possibly ', 'maybe ', 'perhaps '];
    private const OPINION     = ['i think', 'in my opinion', 'we believe', 'i believe', 'should be', 'need to be'];

    private const MIN_WORDS         = 50;
    private const MIN_UNIQUE_RATIO  = 0.30;
    private const MAX_AD_DENSITY    = 0.10;
    private const BLACKLIST_HITS    = 3;

    /**
     * Spec 4.2 - judge a URL before spending a request on it.
     * Returns an error code, or null to proceed.
     */
    public function screenUrl(string $url): ?string
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return 'INVALID_URL';
        }

        // Loopback and private ranges are never a public news source.
        if ($host === 'localhost' || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) {
            return 'INVALID_URL';
        }

        foreach (self::OFF_TOPIC_TLDS as $tld) {
            if (str_ends_with($host, $tld)) {
                return 'OFF_TOPIC';
            }
        }

        foreach (self::SPAM_TLDS as $tld) {
            if (str_ends_with($host, $tld)) {
                return 'SPAM_TLD';
            }
        }

        foreach (self::SPAM_DOMAIN_HINTS as $hint) {
            if (str_contains($host, $hint)) {
                return 'SPAM_DOMAIN';
            }
        }

        return null;
    }

    /**
     * Spec 4.3 and 4.4 - judge extracted content.
     * Returns an error code, or null to proceed.
     */
    public function screenContent(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return 'EMPTY_RESPONSE';
        }

        $words = preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($words);

        if ($count < self::MIN_WORDS) {
            return 'INSUFFICIENT_CONTENT';
        }

        // Repetition: a page of the same phrase is not an article.
        if (count(array_unique($words)) / $count < self::MIN_UNIQUE_RATIO) {
            return 'INVALID_CONTENT';
        }

        $lower = mb_strtolower($text);

        foreach (self::BLACKLIST as $kind => $terms) {
            $hits = 0;

            foreach ($terms as $term) {
                $hits += substr_count($lower, $term);
            }

            if ($hits >= self::BLACKLIST_HITS) {
                return in_array($kind, ['gambling', 'adult', 'piracy'], true) ? 'OFF_TOPIC' : 'SPAM_DETECTED';
            }
        }

        // Spec 4.3 - advertising density rather than mere presence, since a
        // legitimate retail story will say "buy" once without being an advert.
        $adHits = 0;

        foreach (['buy', 'click', 'free', 'win', 'offer', 'discount'] as $term) {
            $adHits += substr_count($lower, ' ' . $term . ' ');
        }

        if ($count > 0 && ($adHits / $count) > self::MAX_AD_DENSITY) {
            return 'SPAM_DETECTED';
        }

        return null;
    }

    /**
     * Spec 2.6 and 2.7 - is this news, or someone thinking aloud?
     *
     * Returns ['discard' => bool, 'cap' => float, 'reasons' => string[]].
     * The cap is the highest relevance this content may be given, which is how
     * the spec stops a confident classification being attached to a rumour.
     */
    public function assessNewsValue(string $title, ?string $body, bool $haveArticleBody = true): array
    {
        $text    = mb_strtolower($title . ' ' . (string) $body);
        $reasons = [];
        $cap     = 1.0;

        foreach (self::SPECULATION as $marker) {
            if (str_contains($text, $marker)) {
                $cap = min($cap, 0.4);
                $reasons[] = 'speculation';
                break;
            }
        }

        foreach (self::OPINION as $marker) {
            if (str_contains($text, $marker)) {
                $cap = min($cap, 0.5);
                $reasons[] = 'opinion';
                break;
            }
        }

        foreach (self::HEDGING as $marker) {
            if (str_contains($text, $marker)) {
                $cap = min($cap, 0.5);
                $reasons[] = 'hedging';
                break;
            }
        }

        // Spec 2.6 - content with no verb reports no event.
        //
        // Only applied where it can actually work: to a fetched article body,
        // in Latin script. A headline is too short to judge this way, and an
        // English verb list says nothing about a Malay, Chinese or Tamil
        // article - all of which this pipeline ingests by design. Measured
        // against 150 real articles, applying it more widely refused half of
        // them.
        $latinScript = preg_match('/\p{Han}|\p{Tamil}|\p{Devanagari}|\p{Hiragana}|\p{Hangul}/u', $text) === 0;

        if ($haveArticleBody && $latinScript && !preg_match('/\b(said|says|announced|approved|launched|opened|arrested|charged|won|lost|died|killed|injured|jailed|fined|rose|fell|signed|named|held|found|seized|banned|urged|warned|reported|added|told|confirmed|revealed|expects|plans|begins|starts|ends|hits|wins|will|has|have|was|were|is|are|berkata|akan|telah|dikatakan)\b/u', $text)) {
            $cap = min($cap, 0.3);
            $reasons[] = 'no_action';
        }

        // Advisory only. The discard decision belongs to the classifier, which
        // reads the content in its own language; see the note at the head of
        // this method. What is returned here is a ceiling and a reason, both
        // passed into the prompt.
        return [
            'cap'     => $cap,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Spec 11 - how sure the system is of its own classification.
     *
     * Distinct from relevance, which is about the content. A model can be
     * perfectly clear that an article is about football while the system knows
     * it only ever saw the headline.
     */
    public function confidence(array $flags): float
    {
        $confidence = 1.0;

        if (!($flags['url_used'] ?? false)) {
            $confidence -= 0.2;
        }

        if ($flags['url_error'] ?? false) {
            $confidence -= 0.15;
        }

        if (($flags['word_count'] ?? 999) < 100) {
            $confidence -= 0.1;
        }

        if ($flags['ambiguous'] ?? false) {
            $confidence -= 0.1;
        }

        if ($flags['batch_downgrade'] ?? false) {
            $confidence -= 0.05;
        }

        $confidence -= 0.05 * (int) ($flags['retries'] ?? 0);

        if ($flags['non_primary_language'] ?? false) {
            $confidence -= 0.1;
        }

        if ($flags['spam_signals'] ?? false) {
            $confidence -= 0.1;
        }

        // Spec 11 - never below 0.3 for an output we are keeping.
        return round(max(0.3, min(1.0, $confidence)), 2);
    }

    /**
     * Is this an index of stories rather than a story?
     *
     * Publishers run recurring roundups - picture galleries, headline digests,
     * market summaries, video bulletins - which carry a headline and a date and
     * nothing else. Read as articles they contribute a card that says "Here are
     * today's popular news:" and links to a list.
     *
     * The patterns are deliberately narrow. "Top stories" alone appears in real
     * headlines; a roundup announces itself with a date, a bulletin number or a
     * video marker as well.
     */
    public function isListingPage(string $title, ?string $summary = null): bool
    {
        $t = mb_strtolower(trim($title));

        $titlePatterns = [
            '/\bnews in pictures\b/u',
            '/\bin pictures\b.*\b(today|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u',
            '/\bpopular news\b/u',
            '/\btop headlines\b/u',
            '/\bnews@\d/u',
            '/\bmarket pulse\b/u',
            '/\b(daily|morning|evening) (digest|brief|briefing|roundup|round-up)\b/u',
            '/\bweek in review\b/u',
            '/\bwhat you need to know today\b/u',
            '/\blive (updates|blog)\b/u',
            '/\bphoto gallery\b/u',
            '/^\s*berita popular\b/u',
            '/\bnotice of .*\b(annual general meeting|agm|egm)\b/u',
        ];

        foreach ($titlePatterns as $pattern) {
            if (preg_match($pattern, $t)) {
                return true;
            }
        }

        // A [WATCH] or [VIDEO] marker is NOT evidence of a roundup. Measured
        // against 9,230 live stories, treating it as one wrongly flagged
        // "Maybank to shift to new HQ at Menara Merdeka 118 [WATCH]" and two
        // other ordinary reports that happened to carry video and a year.
        // Recurring bulletins announce themselves by name instead, and those
        // patterns above already catch every genuine one.

        $s = mb_strtolower(trim((string) $summary));

        // "Here are today's biggest stories:" introduces a list, not a report.
        if (preg_match("/^(here are|these are|below are|the following)\b.*\b(stories|news|headlines)\b/u", $s)) {
            return true;
        }

        return false;
    }

    /**
     * Strip publishing-system leftovers from a summary.
     *
     * Feeds carry a reporter's email, a byline, a desk name, an editorial slug
     * or a CMS node reference in the description field. None of it means
     * anything to a reader, and it is not grounds to reject the article - the
     * story behind "bt@nst.com.my" was a real one.
     *
     * Returns the cleaned text, or null when nothing usable is left, in which
     * case enrichment writes a summary from the article body instead.
     */
    public function cleanSummary(?string $summary): ?string
    {
        $text = trim((string) $summary);

        if ($text === '') {
            return null;
        }

        // CMS node references and editorial slugs.
        $text = preg_replace('/\[\[[^\]]*\]\]/u', ' ', $text);
        $text = preg_replace('/\bSLUG\s*:\s*\S+/iu', ' ', $text);

        // A bare contact address is a byline, not a summary.
        $text = preg_replace('/\b[\w.\-]+@[\w.\-]+\.\w{2,}\b/u', ' ', $text);

        // Trailing "The post ... appeared first on ..." boilerplate.
        $text = preg_replace('/\bThe post\b.*\bappeared first on\b.*$/iu', ' ', $text);
        $text = preg_replace('/\bRead more\b\s*$/iu', ' ', $text);

        $text = trim(preg_replace('/\s+/u', ' ', $text));

        // What remains has to be a sentence, not a desk name. A handful of
        // words with no verb-like structure is a label.
        if (mb_strlen($text) < 25 || str_word_count($text) < 5) {
            return null;
        }

        return $text;
    }
}
