<?php

namespace App\Services\Knowledge;

use App\Services\Classification\CategoryScorer;
use App\Services\Contribution\CaseStudyExamples;
use App\Services\Contribution\ReviewRules;
use App\Services\SubCategoryTaxonomy;

/**
 * Builds the classification prompt out of its parts, and can hand back the
 * parts themselves.
 *
 * One assembler for both jobs on purpose. A prompt viewer that rebuilds the
 * text its own way is a viewer that shows you something the model never saw,
 * and it will drift apart from the real thing within a month. What is displayed
 * here is what is sent.
 *
 * Every part declares where it came from, because the useful question when an
 * answer looks wrong is not "what were we told" but "who can change it" - the
 * newsroom, the taxonomy, or a programmer.
 */
class PromptAssembler
{
    public const ORIGINS = [
        'code'     => 'Hardcoded — needs a developer',
        'playbook' => 'Playbook — editable here',
        'rules'    => 'House rules — editable here',
        'cases'    => 'Case studies — editable here',
        'briefing' => 'Country briefing — editable here, per country',
        'taxonomy' => 'Category taxonomy — from the database',
        'article'  => 'The story being judged',
    ];

    public function __construct(
        private Playbook $playbook = new Playbook(),
        private Briefing $briefing = new Briefing(),
    ) {
    }

    /**
     * The prompt exactly as the model receives it.
     */
    public function build(string $title, string $text, string $source, array $slots = []): string
    {
        $parts = $this->parts($title, $text, $source, $slots);

        return implode("\n", array_map(
            fn (array $part) => $part['text'],
            array_filter($parts, fn (array $part) => trim($part['text']) !== '')
        ));
    }

    /**
     * The same prompt, broken into labelled parts for the viewer.
     *
     * @return list<array{key: string, label: string, origin: string, text: string}>
     */
    public function parts(string $title, string $text, string $source, array $slots = []): array
    {
        // The publisher's country picks the playbook and the rules: a Tamil
        // or Chinese newsroom in Singapore is judged by Singapore's own text
        // where one has been written, and by the base text where not.
        $country  = \App\Services\Geo\SourceCountry::iso2($source);
        $playbook = $this->playbook->forCountry($country);
        $rules    = (new ReviewRules())->promptBlock('scraper', $country);

        $safeTitle = htmlspecialchars(mb_substr(trim($title), 0, 300), ENT_QUOTES, 'UTF-8');
        $safeSrc   = htmlspecialchars(trim($source), ENT_QUOTES, 'UTF-8');
        $safeText  = htmlspecialchars(mb_substr($text, 0, 3000), ENT_NOQUOTES, 'UTF-8');

        return [
            $this->part('role', 'Who the model is', 'code', $this->role()),
            $this->part('contract', 'The reply shape', 'code', $this->contract()),
            $this->part('discard', 'What is not news', 'playbook', $playbook->block('discard')),
            $this->part('rules', 'House rules', 'rules', $rules),
            $this->part('cases', 'Worked examples', 'cases', (new CaseStudyExamples())->promptBlock()),
            $this->part('relevance', 'Scoring relevance', 'playbook', $playbook->block('relevance')),
            $this->part('malaysia_angle', 'The Malaysian angle', 'playbook', $playbook->block('malaysia_angle')),
            $this->part('where', 'Where a story happened', 'playbook', $playbook->block('where')),
            $this->part('briefing', 'Country briefing', 'briefing', $this->briefing->promptBlock($country)),
            $this->part('reporting', 'GPS, ambiguity and sub-categories', 'playbook', $playbook->block('reporting')),
            $this->part('taxonomy', 'Categories and sub-categories', 'taxonomy', $this->taxonomy()),

            // ── Everything above this line is identical on every call ──────
            //
            // Which is the whole point: an identical run of tokens at the start
            // of a prompt is billed at roughly a tenth of the price after the
            // first call. Anything that varies goes below, however small - one
            // line of batch counter in the middle used to make the thirteen
            // thousand characters after it look new every time.
            $this->part('batch', 'This batch', 'code', $this->batch($slots)),
            $this->part('article', 'The story', 'article',
                "Article title: {$safeTitle}\nSource: {$safeSrc}\n" . $this->masthead($source) . "Content:\n{$safeText}\n"),
        ];
    }

    /** @return array{key: string, label: string, origin: string, text: string} */
    private function part(string $key, string $label, string $origin, string $text): array
    {
        return ['key' => $key, 'label' => $label, 'origin' => $origin, 'text' => $text];
    }

    private function role(): string
    {
        return <<<TEXT
        You are an expert hyperlocal news classifier for nearbypost.com. Respond with
        ONLY valid JSON - no markdown fences, no commentary.

        You judge RELEVANCE. You do not choose the category: relevance is multiplied by
        each category's weight elsewhere, and the highest score wins. Do not try to
        predict that outcome.

        TEXT;
    }

    /**
     * ⛔ Not editable from the panel, and not by accident.
     *
     * The parser that reads the reply is written against these exact field
     * names. An editor tidying "d" into "discard" would break every
     * classification on the site, and nothing would say so - stories would
     * simply stop appearing.
     */
    private function contract(): string
    {
        return <<<TEXT
        Fill the fields in the order they appear. "places_named" comes before
        "place" on purpose: list what the text actually says first, then choose
        from your own list. Answering "place" from memory of the first line is
        how a story about Semporna and Lahad Datu ends up filed as "Sabah".

        For every place you list, say what it DOES in the story and quote the
        words that tell you. Most places in a news article are not where the
        news happened - they are where it was filed, where an office sits,
        where somebody was taken afterwards, where a person is from. Deciding
        that per place, against the text, is the whole job; "place" is then
        just the one you marked "happened".

        Return this exact shape:
        {
          "is_article": true or false,
          "d": 0 or 1,
          "e": null or one of SPAM_DETECTED, OFF_TOPIC, INSUFFICIENT_CONTENT, INVALID_CONTENT, PAYWALL_BLOCKED, UNSUPPORTED_FORMAT, NOT_MALAYSIA_RELEVANT,
          "g": 0 or 1,
          "a": 0 or 1,
          "my": 0 or 1,
          "why": "a few words on the Malaysian angle, or why there is none",
          "rel": {"<category id>": <relevance 0-1>, ...}   ALWAYS fill this, even when d=1. A story you are discarding still has a subject: a foreign election is politics, a foreign match is sport. The category is what it is about, not whether we publish it,
          "sub": {"S<sub-category id>": <relevance 0-1>, ...},
          "new_sub": null, or {"name": "...", "why": "..."} ONLY when no existing sub-category fits the story at all. Name the thing, not the story: "Baseball", not "Arsenal owner buys baseball team". Leave it null if anything on the list is even roughly right - a near-miss is better than a taxonomy nobody can navigate,
          "title": "YOUR OWN headline for the story, in the language the article is written in: at most 90 characters, factual, plain, your own wording. Never reuse the publisher's headline or its phrasing - rewrite it from the facts, folding in the key point of the body where that reads better. SWAPPING A WORD OR TWO IS NOT A REWRITE: if your headline still tracks the publisher's line word for word with synonyms in place (try/attempt, say/report, married/weds), throw it away and write a new one from what the body says - who, what, where, how much. AND ADD NOTHING THAT IS NOT IN THE ARTICLE: never introduce a city, a number, a name or a cause the text does not state. Keep names, places and numbers exactly as written. No quotation marks around it, no clickbait.",
          "summary": "2-3 sentence summary in your own words. Where the story has a location, say it in the first sentence.",
          "places_named": [{"p": "the place, written as the text writes it", "role": "one of: happened, dateline, office, aftermath, speaker, origin, subject, mention - defined under WHERE", "why": "the words in the article that put it in this role"}],
          "place": "the narrowest p you marked \"happened\", written narrow-first with its wider place after it. null if you marked nothing \"happened\". THEN THE LAST STEP: stand at this point - what makes the story matter to a reader HERE more than anywhere else in Malaysia? Say it in \"why\". If nothing does, place is null and the story is national - see the end of WHERE",
          "lang": "ISO 639-1 code of the language the article is written in",
          "t": {
            "en": {"title": "your own headline in natural English (same rules as title)", "summary": "summary in natural English"},
            "ms": {"title": "your own headline in natural Malay (same rules as title)", "summary": "summary in natural Malay"},
            "zh": {"title": "your own headline in Simplified Chinese (same rules as title)", "summary": "summary in Simplified Chinese"}
          }
        }

        TEXT;
    }

    /**
     * The only instruction that changes between calls, kept to one line and
     * kept at the end so it cannot break the cache above it.
     */
    /**
     * Whose paper this is, and what that means for a place the text does not
     * qualify. A Malaysian paper writes Malaysian news without naming the
     * country and names the country or city when it reports from abroad; so
     * an unqualified place in its pages is Malaysian. Data, not code: the
     * country comes from the sources table, and a publisher with none gets no
     * prior at all.
     */
    private function masthead(string $source): string
    {
        $iso2 = \App\Services\Geo\SourceCountry::iso2($source);

        if ($iso2 === null) {
            return "Publisher: an international outlet with no home country. Do not assume a country for any place the text does not name one for.\n";
        }

        $country = \App\Services\Geo\Boundaries\Iso3166::name(\App\Services\Geo\Boundaries\Iso3166::iso3($iso2) ?? '') ?? $iso2;

        return "Publisher: {$country}-based. It writes {$country}'s own news without naming the country, and names the country or city when reporting from abroad. So: a place the text does not qualify is in {$country}; a place abroad will have its country or city stated - write that country after it.\n";
    }

    private function batch(array $slots): string
    {
        if ($slots === []) {
            return '';
        }

        return 'Remaining high-confidence slots in this batch: ' . json_encode($slots)
             . ". If a slot is 0 you may not use that level; choose the next one down.\n";
    }

    private function taxonomy(): string
    {
        $categoryList = (new CategoryScorer())->promptCategories();
        $subList = (new SubCategoryTaxonomy())->promptBlockWithIds();

        return "Categories (id: name):\n{$categoryList}\n\n"
             . "Sub-categories (id: name, grouped by category):\n{$subList}\n";
    }
}
