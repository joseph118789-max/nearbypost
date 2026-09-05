<?php

namespace App\Services\Geo;

/**
 * "Mahkamah Majistret di sini" is not a place. It is a place minus a word.
 *
 * Malay wires write the town once, in the dateline, and then refer back to it
 * for the rest of the article: "Mahkamah Majistret di sini", "hospital di
 * sini", "kejadian di negeri ini". English wires do the same with "the court
 * here". The dateline is struck first - correctly, it is not what the story is
 * about - and that removes the only thing those phrases pointed at.
 *
 * Told this in prose, the classifier reasoned it out perfectly and then wrote
 * the phrase anyway:
 *
 *   {"p": "Mahkamah Majistret di sini",
 *    "why": "'di sini' refers to the dateline, George Town"}
 *
 * Right in the reason, wrong in the name, and only the name is used. It got it
 * right once in three runs. So the resolution happens here, where it happens
 * every time.
 *
 * This is not a judgement about what the story is worth - those belong in the
 * playbook where the newsroom can edit them. It is a name with a word missing,
 * and the missing word is always the dateline.
 */
class DeicticPlace
{
    /**
     * Phrases that point at the dateline instead of naming somewhere.
     *
     * Anchored to word boundaries: "here" must be the whole word, or "Amherst"
     * and "Cheras" would be quietly mangled into something a map cannot find.
     */
    private const MARKERS = [
        '/\s*,?\s*\bdi sini\b/iu',
        '/\s*,?\s*\bdi negeri ini\b/iu',
        '/\s*,?\s*\bdi bandar ini\b/iu',
        '/\s*,?\s*\bdi daerah ini\b/iu',
        '/\s*,?\s*\bdi kampung ini\b/iu',
        '/\s*,?\s*\bin this town\b/iu',
        '/\s*,?\s*\bin this city\b/iu',
        '/\s*,?\s*\bin this state\b/iu',
        '/\s*,?\s*\bhere\b/iu',
    ];

    /**
     * Replace a pointing phrase with the town it points at.
     *
     * Returns null when there is nothing to point at, because a name that names
     * nothing is worse than no name: it reaches the geocoder, fails there, and
     * lands in the human queue for somebody to read an article about.
     */
    public static function resolve(?string $place, mixed $roles, array &$corrections): ?string
    {
        if ($place === null || trim($place) === '') {
            return $place;
        }

        $stripped = $place;

        foreach (self::MARKERS as $marker) {
            $stripped = preg_replace($marker, '', $stripped);
        }

        $stripped = trim((string) $stripped, " \t\n\r\0\x0B,");

        // No pointing phrase in it - an ordinary name, left alone.
        if ($stripped === trim($place, " \t\n\r\0\x0B,")) {
            return $place;
        }

        $dateline = self::datelineFrom($roles);

        if ($dateline === null) {
            $corrections[] = 'deictic_no_dateline:' . mb_substr($place, 0, 40);

            return null;
        }

        // The phrase was the whole name: "di sini" on its own resolves to the
        // dateline town, which the dateline rule has already ruled out as an
        // answer. Nothing is left to place.
        if ($stripped === '') {
            $corrections[] = 'deictic_bare:' . mb_substr($place, 0, 40);

            return null;
        }

        $corrections[] = 'deictic_resolved:' . mb_substr($place, 0, 40);

        return $stripped . ', ' . $dateline;
    }

    /**
     * The dateline as a name rather than a shout. Wires file "GEORGETOWN:" and
     * "KUALA LUMPUR, Sept 1"; a reader is shown this, so it is title-cased -
     * but only when it is all capitals, or "McLaren" and "PJ" lose their shape.
     */
    private static function datelineFrom(mixed $roles): ?string
    {
        foreach ((array) $roles as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'dateline') {
                continue;
            }

            $name = trim((string) ($entry['p'] ?? ''));

            if ($name === '') {
                continue;
            }

            return mb_strtoupper($name) === $name
                ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8')
                : $name;
        }

        return null;
    }
}
