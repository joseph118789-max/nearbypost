<?php

namespace App\Services\Geo;

/**
 * A place name nobody here can read is worse than no place name at all.
 *
 * Tamil and Chinese sources are live, and the classifier writes the place in
 * whatever alphabet the article used unless it remembers not to. The playbook
 * tells it to use the Latin alphabet and it obeys most of the time, which is
 * not good enough, because the failures are not merely ugly:
 *
 *   "பினாங்கு அனைத்துலக விமான நிலையம், பாயான் லெப்பாஸ்"  - geocoder finds nothing,
 *   so a correctly located story goes to the human queue for someone to read a
 *   Tamil article and type the answer in by hand.
 *
 *   "伯明翰机场, 伯明翰, 英国" - Birmingham Airport, in the UK - resolved to INTI
 *   International University in Nilai, Malaysia. Nothing downstream can catch
 *   that: the name does not say Britain in any alphabet the plausibility check
 *   reads, and the coordinates it came back with are perfectly ordinary.
 *
 * So a name that is not written in Latin letters is refused here, and the story
 * becomes national news - which is a correct outcome, and an honest one.
 *
 * This is not a judgement about the language of the SOURCE. A Tamil article is
 * as welcome as any other; it is the place NAME that has to be readable, and
 * the model has already shown it knows the Latin form - it puts it in the
 * reason field while writing the local script in the name.
 */
class LatinName
{
    /**
     * Can this name be shown to a reader and matched against a map?
     *
     * Latin letters, accents and the punctuation place names actually use are
     * allowed - "Kampung Sungai Bakap", "Jalan Tun H.S. Lee", "Sepang/KLIA",
     * "Kuala Lumpur (KL)" all pass. Anything with a character from another
     * script does not.
     */
    /**
     * The Latin form of a name that is not Latin: "西里京 (Serikin)" carries
     * its map name in brackets, and so does "万挠绕道高架公路（Rawang Bypass）"
     * with full-width brackets. Chinese and Tamil sources write the place in
     * their own script and often add the Latin name for exactly this reason.
     * Returns the name unchanged when it is already usable, the bracketed
     * Latin part when there is one, or null when nothing Latin can be had.
     */
    public static function latinForm(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '' || self::isUsable($name)) {
            return $name === '' ? null : $name;
        }

        // every bracketed part, ASCII or full-width brackets; the address
        // after a comma stays if it is Latin ("(Serikin), Bau, Sarawak")
        if (preg_match_all('/[(\x{FF08}]\s*([^()\x{FF08}\x{FF09}]{3,})\s*[)\x{FF09}]/u', $name, $m)) {
            foreach ($m[1] as $inside) {
                $inside = trim($inside);

                if (self::isUsable($inside) && preg_match('/\p{Latin}{3}/u', $inside)) {
                    $rest = trim((string) preg_replace('/[(\x{FF08}][^()\x{FF08}\x{FF09}]*[)\x{FF09}]/u', '', $name));
                    $tail = '';

                    // keep a Latin address tail after the first comma, if any
                    if (str_contains($rest, ',')) {
                        $after = trim(substr($rest, strpos($rest, ',') + 1));

                        if ($after !== '' && self::isUsable($after)) {
                            $tail = ', ' . $after;
                        }
                    }

                    return $inside . $tail;
                }
            }
        }

        return null;
    }

    /**
     * The Latin name from the model, for a name in Tamil or Chinese script
     * with no Latin form in brackets: "ஷா ஆலம், செக்சன் 32" is Shah Alam,
     * Seksyen 32; "必达士" is Bidor. One short call, temperature 0, JSON back.
     * Null when the model is not configured, fails, or is not sure.
     */
    /**
     * The Latin name from the story's own notes: the classifier's place roles
     * say "the incident happened in Sabah's Pitas (必达士) district" - the
     * Latin name stands right beside the script name. Read there before
     * asking the model, which guessed "Bidas" for 必达士 and put a Sabah
     * drowning in Kelantan.
     */
    public static function fromContext(?string $name, ?string $context): ?string
    {
        $name = trim((string) $name);
        $context = (string) $context;

        if ($name === '' || $context === '' || self::isUsable($name)) {
            return $name === '' ? null : $name;
        }

        $q = preg_quote($name, '/');

        // "Pitas (必达士)" / "Pitas（必达士）"  and  "必达士 (Pitas)"
        // up to four capitalised words before the bracket ("Sabah's Pitas"), or the bracket's own Latin content
        $word = '[\p{Lu}][\p{Latin}\'\-\.]*';
        foreach (['/((?:' . $word . '\s+){0,3}' . $word . ')\s*[(\x{FF08}]\s*' . $q . '\s*[)\x{FF09}]/u', '/' . $q . '\s*[(\x{FF08}]\s*([\p{Latin}][\p{Latin}\s\'\-\.]{2,60}?)\s*[)\x{FF09}]/u'] as $re) {
            if (preg_match($re, $context, $m)) {
                $latin = trim($m[1]);
                // drop a leading possessive or article: "Sabah's Pitas" -> "Pitas, Sabah"
                if (preg_match('/^([\p{Latin}]+)\'s\s+(.+)$/u', $latin, $p)) {
                    $latin = $p[2] . ', ' . $p[1];
                }

                if (self::isUsable($latin)) {
                    return $latin;
                }
            }
        }

        return null;
    }

    public static function viaModel(?string $name, ?string $context = null): ?string
    {
        $name = trim((string) $name);

        if ($name === '' || self::isUsable($name)) {
            return $name === '' ? null : $name;
        }

        try {
            $model = \App\Services\Ai\AiRouter::for('place_names');

            if (!$model->isConfigured()) {
                return null;
            }

            $ctx = trim(mb_substr((string) $context, 0, 1200));
            $prompt = "A place name from a Malaysian or Singaporean news story is written in Tamil or Chinese script: \"{$name}\".\n"
                . ($ctx !== '' ? "What the story's notes say about its places: {$ctx}\n" : '')
                . "Give its name in Latin letters as it appears on maps and road signs (Malay or English spelling), followed by the state, e.g. \"Pitas, Sabah\". Use the notes above to decide which place it is.\n"
                . "Reply with JSON only: {\"name\": \"...\", \"sure\": true|false}. If you cannot tell which place it is, or which state, reply {\"name\": null, \"sure\": false}.";
            $reply = (string) $model->complete($prompt, 0.0);
            $json  = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($reply))), true);
            $latin = trim((string) ($json['name'] ?? ''));

            if ($latin === '' || empty($json['sure']) || !self::isUsable($latin)) {
                return null;
            }

            return $latin;
        } catch (\Throwable $x) {
            \Illuminate\Support\Facades\Log::warning('LatinName model call failed', ['name' => $name, 'error' => mb_substr($x->getMessage(), 0, 120)]);

            return null;
        }
    }

    public static function isUsable(?string $name): bool
    {
        $name = trim((string) $name);

        if ($name === '') {
            return true;   // nothing to object to; emptiness is handled elsewhere
        }

        // Everything a place name legitimately contains, and nothing else.
        return preg_match('/^[\p{Latin}\p{Common}\p{Inherited}]+$/u', $name) === 1;
    }
}
