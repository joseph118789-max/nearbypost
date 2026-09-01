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
    public function sections(): array
    {
        return Cache::remember('playbook:' . $this->version(), self::TTL_SECONDS, function () {
            return DB::table('playbook_sections')
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->keyBy('key')
                ->all();
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
        $existing = DB::table('playbook_sections')->where('key', $key)->first();

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

        DB::table('playbook_sections')->where('key', $key)->update([
            'body'       => $body,
            'updated_at' => now(),
        ]);

        $this->bumpVersion();
    }

    public function setActive(string $key, bool $active): void
    {
        DB::table('playbook_sections')->where('key', $key)->update([
            'is_active'  => $active,
            'updated_at' => now(),
        ]);

        $this->bumpVersion();
    }

    public function version(): string
    {
        return (string) Cache::get('playbook:version', '1');
    }

    public function bumpVersion(): void
    {
        Cache::forever('playbook:version', (string) time());
    }
}
