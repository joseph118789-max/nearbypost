<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The site's editorial reasoning, held as text a person can change.
 *
 * Most of what this site has learned is not a rule and not a precedent. It is
 * method: how to decide where a story happened, when "nowhere" is the right
 * answer, which sport a Malaysian reader follows. That shape of knowledge had
 * no home, so it accumulated in a PHP heredoc where the newsroom could not read
 * it, could not change it, and could not see what had been changed.
 *
 * ⛔ THE OUTPUT CONTRACT IS NOT IN HERE, deliberately. The JSON shape, the field
 * names and the category lists stay in code because the parser that reads the
 * reply is written against them. A newsroom editing the reasoning cannot break
 * classification; a newsroom editing the contract could, silently, and the
 * damage would show up days later as stories quietly failing to appear.
 *
 * Cached under a version bumped on every save, so an edit takes effect on the
 * next story rather than after a wait.
 */
class Playbook
{
    private const TTL_SECONDS = 120;

    /**
     * The sections, in the order they appear in the prompt.
     *
     * Each carries the text sent to the model and, separately, a note saying
     * why it exists. The note is never sent - it is for the next person to
     * decide whether a rule still earns its place, which is impossible to
     * judge from the rule alone.
     */
    public const SECTIONS = [
        'discard'        => 'What is not news',
        'relevance'      => 'Scoring relevance',
        'malaysia_angle' => 'The Malaysian angle',
        'where'          => 'Where a story happened',
        'reporting'      => 'GPS, ambiguity and sub-categories',
    ];

    /**
     * @return array<string, array<string, mixed>> keyed by section key
     *
     * Cached as plain arrays rather than row objects: a cache driver that
     * serialises stdClass can hand it back as an incomplete class, and the
     * first property read then throws - during classification, on every story.
     */
    /** The country whose sections are read: null = the base (every country). */
    private ?string $country = null;

    /** The same playbook seen from one country: base sections, overridden by that country's own. */
    public function forCountry(?string $iso2): static
    {
        $copy = clone $this;
        $copy->country = $iso2 === null ? null : strtoupper($iso2);

        return $copy;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function sections(): array
    {
        return Cache::remember('playbook:' . ($this->country ?? 'base') . ':' . $this->version(), self::TTL_SECONDS, function () {
            $base = DB::table('playbook_sections')
                ->whereNull('country')
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($row) => (array) $row + ['override' => false])
                ->keyBy('key')
                ->all();

            if ($this->country === null) {
                return $base;
            }

            // the country's own text replaces the base text of the same key;
            // a key only the country has is added at the end
            foreach (DB::table('playbook_sections')->where('country', $this->country)->orderBy('sort_order')->get() as $row) {
                $row = (array) $row + ['override' => true];
                $base[$row['key']] = $row;
            }

            return $base;
        });
    }

    /**
     * One section's text, ready for the prompt.
     *
     * Returns an empty string when the section is missing or switched off, so
     * the prompt loses that guidance rather than gaining an empty heading -
     * which a model reads as "this newsroom has no view", a different claim
     * from saying nothing.
     */
    public function block(string $key, array $replacements = []): string
    {
        $section = $this->sections()[$key] ?? null;

        if (!$section || empty($section['is_active']) || trim((string) $section['body']) === '') {
            return '';
        }

        $body = trim((string) $section['body']);

        foreach ($replacements as $token => $value) {
            $body = str_replace('{{' . $token . '}}', (string) $value, $body);
        }

        return $body . "\n";
    }

    /**
     * Save a section, keeping what it said before.
     *
     * The previous text is written to the revision log first: a change to how
     * the site judges news is exactly the thing someone will want to read back
     * when the feed starts behaving differently and nobody remembers what was
     * changed.
     */
    public function save(string $key, string $body, ?string $note = null, ?int $editorId = null): void
    {
        $existing = DB::table('playbook_sections')->where('key', $key)
            ->when($this->country === null, fn ($q) => $q->whereNull('country'), fn ($q) => $q->where('country', $this->country))
            ->first();

        // the first edit for a country creates that country's own copy of the section
        if (!$existing && $this->country !== null) {
            $base = DB::table('playbook_sections')->where('key', $key)->whereNull('country')->first();

            if (!$base) {
                return;
            }

            DB::table('playbook_sections')->insert([
                'key' => $key, 'country' => $this->country, 'title' => $base->title, 'body' => $body, 'why' => $base->why,
                'sort_order' => $base->sort_order, 'is_active' => $base->is_active, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->bumpVersion();

            return;
        }

        if (!$existing) {
            return;
        }

        if (trim((string) $existing->body) !== trim($body)) {
            DB::table('playbook_revisions')->insert([
                'playbook_section_id' => $existing->id,
                'body'                => $existing->body,
                'note'                => $note ? mb_substr($note, 0, 200) : null,
                'edited_by'           => $editorId,
                'created_at'          => now(),
            ]);
        }

        DB::table('playbook_sections')->where('id', $existing->id)->update([
            'body'       => $body,
            'updated_at' => now(),
        ]);

        $this->bumpVersion();
    }

    /** Remove a country's own version of a section, so the base applies again. */
    public function revert(string $key): void
    {
        if ($this->country === null) {
            return;
        }

        DB::table('playbook_sections')->where('key', $key)->where('country', $this->country)->delete();
        $this->bumpVersion();
    }

    public function setActive(string $key, bool $active): void
    {
        $q = DB::table('playbook_sections')->where('key', $key)
            ->when($this->country === null, fn ($q) => $q->whereNull('country'), fn ($q) => $q->where('country', $this->country));

        if ($this->country !== null && !$q->exists()) {
            // switching off for one country: that country's copy, switched off
            $base = DB::table('playbook_sections')->where('key', $key)->whereNull('country')->first();

            if ($base) {
                DB::table('playbook_sections')->insert([
                    'key' => $key, 'country' => $this->country, 'title' => $base->title, 'body' => $base->body, 'why' => $base->why,
                    'sort_order' => $base->sort_order, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->bumpVersion();
            }

            return;
        }

        $q->update(['is_active' => $active, 'updated_at' => now()]);
        $this->bumpVersion();
    }

    public function version(): string
    {
        return (string) Cache::get('playbook:version', '1');
    }

    /**
     * Change the cache key so the next read comes from the database.
     *
     * NOT time(). Two saves in the same second produced the same version, so
     * the cache written between them was served as current and the second edit
     * was invisible until something else cleared it - which is how a rule could
     * be saved, recorded in the revision history, and still not reach the
     * model. Microseconds plus a counter make the key unique per call, however
     * fast they arrive.
     */
    public function bumpVersion(): void
    {
        static $counter = 0;

        Cache::forever('playbook:version', sprintf('%s-%d', microtime(true), ++$counter));
    }
}
