<?php

namespace App\Services\Ai;

/**
 * Reading JSON out of a model's reply.
 *
 * Asking for "JSON only" works most of the time and fails often enough to
 * matter: measured 4 Sep 2026, DeepSeek wrapped the headline job's answer in
 * prose on roughly two replies in eight, and every one of those stories
 * silently kept the publisher's headline - the exact thing that job exists to
 * prevent. Being strict about the reply punished the site, not the model.
 *
 * So: take the fences off, and if what is left will not parse, find the first
 * balanced [...] or {...} in it and parse that. Brackets inside strings are
 * skipped, so a headline containing one does not cut the object short.
 */
final class ModelJson
{
    /** @return array|null the decoded reply, or null when there is no JSON in it at all */
    public static function parse(?string $raw): ?array
    {
        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        // ```json … ``` in any position, and a stray leading "json" label
        $text = preg_replace('/^\s*```(?:json|JSON)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', (string) $text);
        $text = trim((string) $text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        foreach (self::blocks($text) as $block) {
            $decoded = json_decode($block, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Every balanced [...] and {...} in the text, longest first, so the whole
     * answer is preferred over a fragment of it.
     *
     * @return array<int, string>
     */
    private static function blocks(string $text): array
    {
        $found = [];
        $length = mb_strlen($text);

        for ($start = 0; $start < $length; $start++) {
            $open = mb_substr($text, $start, 1);

            if ($open !== '[' && $open !== '{') {
                continue;
            }

            $close = $open === '[' ? ']' : '}';
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start; $i < $length; $i++) {
                $c = mb_substr($text, $i, 1);

                if ($escaped) { $escaped = false; continue; }
                if ($c === '\\') { $escaped = true; continue; }
                if ($c === '"') { $inString = !$inString; continue; }
                if ($inString) { continue; }

                if ($c === $open) { $depth++; }

                if ($c === $close) {
                    $depth--;

                    if ($depth === 0) {
                        $found[] = mb_substr($text, $start, $i - $start + 1);
                        $start = $i;   // no nested block is a better answer than the one containing it
                        break;
                    }
                }
            }
        }

        usort($found, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $found;
    }
}
