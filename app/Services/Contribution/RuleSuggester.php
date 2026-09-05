<?php

namespace App\Services\Contribution;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turn a pile of removal reasons into wording a newsroom could adopt.
 *
 * The value is in the aggregate. One editor writing "this is an advert" is an
 * incident; forty of them are a rule that should have existed. A model is good
 * at seeing which forty complaints are the same complaint, and bad at deciding
 * what a publication's policy ought to be - so it proposes and a person
 * decides.
 *
 * It is given the rules already in force and told not to restate them, because
 * the failure mode here is a rule list that grows by six near-duplicates a
 * month until nobody reads it and the reviewer is handed a wall of text.
 */
class RuleSuggester
{
    private const MODEL = 'deepseek-chat';
    private const TIMEOUT = 60;
    private const MAX_PROPOSALS = 5;

    /**
     * @param  list<object>  $removals  rows with title, origin and reason
     * @param  list<string>  $existing  rules already in force
     * @return list<array{rule: string, applies_to: string, because: string}>
     */
    public function propose(array $removals, array $existing): array
    {
        $key = (string) (\App\Services\Ai\AiRouter::for('rule_suggester')->isConfigured() ? 'via-ai-panel' : '');

        if ($key === '' || $removals === []) {
            return [];
        }

        try {
            $raw = $this->ask($key, $this->prompt($removals, $existing));
        } catch (\Throwable $e) {
            Log::warning('Rule suggestion failed', ['error' => $e->getMessage()]);

            return [];
        }

        $parsed = $this->parse($raw);

        if ($parsed === null) {
            return [];
        }

        $out = [];

        foreach ((array) ($parsed['rules'] ?? []) as $row) {
            $rule = trim((string) ($row['rule'] ?? ''));

            if ($rule === '') {
                continue;
            }

            $applies = (string) ($row['applies_to'] ?? 'both');

            $out[] = [
                'rule'       => mb_substr($rule, 0, 400),
                'applies_to' => in_array($applies, ['both', 'contributor', 'scraper'], true) ? $applies : 'both',
                'because'    => mb_substr(trim((string) ($row['because'] ?? '')), 0, 300),
            ];

            if (count($out) >= self::MAX_PROPOSALS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<object>  $removals
     * @param  list<string>  $existing
     */
    private function prompt(array $removals, array $existing): string
    {
        $lines = [];

        foreach ($removals as $i => $r) {
            $lines[] = sprintf(
                '%d. [%s] "%s" — removed because: %s',
                $i + 1,
                $r->origin === 'user' ? 'reader post' : 'gathered article',
                mb_substr((string) $r->title, 0, 120),
                mb_substr((string) $r->reason, 0, 240)
            );
        }

        $removalList = implode("\n", $lines);

        $current = $existing === []
            ? '(none yet)'
            : implode("\n", array_map(fn ($r) => '- ' . $r, $existing));

        return <<<PROMPT
You are helping the editors of a Malaysian local news site turn their own
corrections into policy.

Below are stories they published and then removed, each with the reason they
gave. Find the patterns: which of these are the same complaint said different
ways. Propose rules that would have stopped those stories being published in the
first place.

RULES ALREADY IN FORCE - do not restate or slightly reword any of these:
{$current}

WHAT THEY REMOVED:
{$removalList}

HOW TO WRITE A RULE

Write it as an instruction to a reviewer reading one story, in one sentence,
in plain English. "No advertising for a business, including a discount or an
offer" works. "Avoid promotional content" does not, because it does not say
what to look for.

Only propose a rule where the same problem appears at least twice. A single
removal is an incident, not a policy, and a rule written from one story will
refuse things nobody wanted refused.

Say whether it applies to reader submissions, to gathered articles, or to both.
Something that only readers do - a phone number, a plug for their own shop - is
"contributor". Something only a newspaper does is "scraper".

Propose at most 5. Fewer good rules beat more weak ones: every rule is read on
every submission, and a long list of near-duplicates is how a rule list stops
being read.

If the removals show no repeated pattern, return an empty list. That is a
correct answer.

Reply with JSON only:
{
  "rules": [
    {
      "rule": "the rule, one sentence",
      "applies_to": "both" | "contributor" | "scraper",
      "because": "which removals this came from, briefly"
    }
  ]
}
PROMPT;
    }

    private function ask(string $key, string $prompt): string
    {
        $response = \App\Services\Ai\AiRouter::for('rule_suggester')->post(self::TIMEOUT, [
                'model'       => self::MODEL,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.2,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('DeepSeek ' . $response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('DeepSeek returned no content');
        }

        return $content;
    }

    private function parse(string $raw): ?array
    {
        $content = preg_replace('/^```(?:json)?\s*/', '', trim($raw));
        $content = preg_replace('/```\s*$/', '', (string) $content);

        $parsed = json_decode(trim((string) $content), true);

        if (!is_array($parsed) && preg_match('/\{.*\}/s', (string) $content, $m)) {
            $parsed = json_decode($m[0], true);
        }

        return is_array($parsed) ? $parsed : null;
    }
}
