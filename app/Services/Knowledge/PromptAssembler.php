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
        'briefing' => 'Malaysia briefing — editable here',
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
        $safeTitle = htmlspecialchars(mb_substr(trim($title), 0, 300), ENT_QUOTES, 'UTF-8');
        $safeSrc   = htmlspecialchars(trim($source), ENT_QUOTES, 'UTF-8');
        $safeText  = htmlspecialchars(mb_substr($text, 0, 3000), ENT_NOQUOTES, 'UTF-8');

        return [
            $this->part('role', 'Who the model is', 'code', $this->role()),
            $this->part('contract', 'The reply shape', 'code', $this->contract()),
            $this->part('discard', 'What is not news', 'playbook', $this->playbook->block('discard')),
            $this->part('rules', 'House rules', 'rules', (new ReviewRules())->promptBlock('scraper')),
            $this->part('cases', 'Worked examples', 'cases', (new CaseStudyExamples())->promptBlock()),
            $this->part('relevance', 'Scoring relevance', 'playbook', $this->playbook->block('relevance')),
            $this->part('malaysia_angle', 'The Malaysian angle', 'playbook', $this->playbook->block('malaysia_angle')),
            $this->part('where', 'Where a story happened', 'playbook', $this->playbook->block('where')),
            $this->part('briefing', 'Malaysia briefing', 'briefing', $this->briefing->promptBlock()),
            $this->part('reporting', 'GPS, ambiguity and sub-categories', 'playbook', $this->playbook->block('reporting')),
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
                "Article title: {$safeTitle}\nSource: {$safeSrc}\nContent:\n{$safeText}\n"),
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

        Return this exact shape:
        {
          "is_article": true or false,
          "d": 0 or 1,
          "e": null or one of SPAM_DETECTED, OFF_TOPIC, INSUFFICIENT_CONTENT, INVALID_CONTENT, PAYWALL_BLOCKED, UNSUPPORTED_FORMAT, NOT_MALAYSIA_RELEVANT,
          "g": 0 or 1,
          "a": 0 or 1,
          "my": 0 or 1,
          "why": "a few words on the Malaysian angle, or why there is none",
          "rel": {"<category id>": <relevance 0-1>, ...},
          "sub": {"S<sub-category id>": <relevance 0-1>, ...},
          "summary": "2-3 sentence summary. Where the story has a location, say it in the first sentence.",
          "places_named": ["every place the text names, in the order it names them, however small - a village, a road, a building, a district, a town, a state. [] if none."],
          "place": "the ONE place from places_named where the story HAPPENED, or null - see WHERE below",
          "lang": "ISO 639-1 code of the language the article is written in",
          "t": {
            "en": {"title": "headline in natural English", "summary": "summary in natural English"},
            "ms": {"title": "headline in natural Malay", "summary": "summary in natural Malay"},
            "zh": {"title": "headline in Simplified Chinese", "summary": "summary in Simplified Chinese"}
          }
        }

        TEXT;
    }

    /**
     * The only instruction that changes between calls, kept to one line and
     * kept at the end so it cannot break the cache above it.
     */
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
