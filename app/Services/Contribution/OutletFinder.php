<?php

namespace App\Services\Contribution;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ask the model where a chain's branches are, so a nationwide story can be
 * shown to people near each of them.
 *
 * ⚠ THE ANSWER IS A DRAFT, NOT A FACT. A model asked to list a retailer's
 * Malaysian outlets will produce a plausible list, and some of it will be
 * wrong: branches that closed years ago, branches in the wrong mall, branches
 * that never existed. That is not a flaw to be engineered away here - it is the
 * nature of asking - so nothing this returns is ever published without a person
 * seeing the list first and deleting what does not belong.
 *
 * The prompt therefore asks for the model's own confidence per branch and
 * instructs it to return fewer, surer entries rather than pad the list, because
 * a short accurate list is what the editor wants to approve and a long
 * speculative one is what makes them give up and approve it unread.
 */
class OutletFinder
{
    private const MODEL = 'deepseek-chat';
    private const TIMEOUT = 60;
    /**
     * Past this many places a story is not local to anyone.
     *
     * The chain matters less than the spread: a promotion at twelve shops is
     * news near twelve high streets, while one at two hundred is simply news,
     * and putting it on two hundred map points would show it to the whole
     * country as though it were happening next door.
     */
    private const NATIONAL_ABOVE = 20;

    /**
     * @return array{
     *     brand: ?string,
     *     scale: string,
     *     outlets: list<array{name: string, place: string, confident: bool}>,
     *     note: string
     * }
     */
    public function propose(string $title, string $body): array
    {
        $key = (string) config('services.deepseek.key');

        if ($key === '') {
            return ['brand' => null, 'scale' => 'unknown', 'outlets' => [], 'note' => 'No reviewer configured.'];
        }

        try {
            $raw = $this->ask($key, $this->prompt($title, $body));
        } catch (\Throwable $e) {
            Log::warning('Outlet lookup failed', ['error' => $e->getMessage()]);

            return ['brand' => null, 'scale' => 'unknown', 'outlets' => [], 'note' => 'The lookup could not be completed. Add the places by hand.'];
        }

        $parsed = $this->parse($raw);

        if ($parsed === null) {
            return ['brand' => null, 'scale' => 'unknown', 'outlets' => [], 'note' => 'The lookup returned nothing usable. Add the places by hand.'];
        }

        $scale = mb_strtolower(trim((string) ($parsed['scale'] ?? '')));
        $scale = in_array($scale, ['national', 'branches'], true) ? $scale : 'unknown';

        // A national story has no set of places to pin, so anything the model
        // listed anyway is discarded rather than shown to the editor as a
        // choice. Offering ten thousand petrol stations for approval is not a
        // decision anyone can usefully make.
        if ($scale === 'national') {
            return [
                'brand'   => $this->clean($parsed['brand'] ?? null),
                'scale'   => 'national',
                'outlets' => [],
                'note'    => $this->clean($parsed['note'] ?? null)
                    ?? 'This affects the whole country, so it belongs to no particular place.',
            ];
        }

        $outlets = [];

        foreach ((array) ($parsed['outlets'] ?? []) as $row) {
            $place = trim((string) ($row['place'] ?? ''));

            if ($place === '') {
                continue;
            }

            $outlets[] = [
                'name'      => mb_substr(trim((string) ($row['name'] ?? $place)), 0, 190),
                'place'     => mb_substr($place, 0, 190),
                'confident' => (int) ($row['confident'] ?? 0) === 1,
            ];

        }

        // The count is its own answer. A model that listed every petrol station
        // in the country has told us the story is national whatever it wrote in
        // the scale field, so the number decides and the list is dropped.
        if (count($outlets) > self::NATIONAL_ABOVE) {
            return [
                'brand'   => $this->clean($parsed['brand'] ?? null),
                'scale'   => 'national',
                'outlets' => [],
                'note'    => 'This came back with ' . count($outlets) . ' places. Past '
                    . self::NATIONAL_ABOVE . ' the story is not near anyone in particular, so it '
                    . 'has been treated as national news and no places were added.',
            ];
        }

        return [
            'brand'   => $this->clean($parsed['brand'] ?? null),
            'scale'   => $scale,
            'outlets' => $outlets,
            'note'    => $this->clean($parsed['note'] ?? null) ?? '',
        ];
    }

    private function prompt(string $title, string $body): string
    {
        $safeTitle = mb_substr(trim($title), 0, 300);
        $safeBody = mb_substr(trim($body), 0, 3000);

        return <<<PROMPT
A Malaysian local news site wants to show the story below to readers who live
near each place it affects, rather than pinning it to a single point.

Work out which organisation or brand the story is about, then list the places in
Malaysia where a reader would be affected - usually that organisation's branches
or outlets.

DECIDE THE SCALE BEFORE YOU LIST ANYTHING

Some stories affect everybody in the country and belong to no particular place.
A fuel subsidy every motorist can claim, a change to income tax, a new public
holiday, a nationwide change to the school syllabus: a reader in Ipoh is not
affected because something stands near them, they are affected because they live
in Malaysia. Those are national stories.

It is tempting to answer such a story with the places where people would COLLECT
the benefit - every petrol station, every school, every clinic, every post
office. Do not. That is an entire class of business, not a list of branches, and
putting a national story on ten thousand map points would push it at every
reader as though it were happening at the end of their street.

  "national"  - it applies to everyone everywhere, or to a whole class of
                business rather than one named organisation's own outlets.
                Return an EMPTY list and explain in "note".

  "branches"  - one named organisation is doing something at its own premises,
                and those premises can be listed and counted.

If your list would run past 20 places, it is national. Answer national rather
than sending a long or truncated list - past that point the story is not near
anybody in particular.

RULES FOR THE LIST

Be accurate rather than complete. A short list you are sure of is far more use
than a long list containing branches that have closed or that you are guessing
at. Mark each entry: confident = 1 only if you are genuinely sure that branch
exists today, otherwise 0. A person will read this list and delete what is
wrong, so do not pad it.

Give the place as a town or suburb with its state - "Bukit Bintang, Kuala
Lumpur", "Bayan Lepas, Penang" - because that is what can be put on a map. Do
not invent street addresses.

If the story is not about an organisation with multiple locations, or you cannot
name any branch you are sure of, return an empty list and say why in "note".
That is a perfectly good answer.

At most 20 places.

Reply with JSON only:
{
  "brand": "the organisation, or null",
  "scale": "national" or "branches",
  "note": "one sentence for the editor: how sure you are overall, and what to check",
  "outlets": [
    {"name": "branch name as people would say it", "place": "town or suburb, state", "confident": 0 or 1}
  ]
}

Story title: {$safeTitle}
Story text:
{$safeBody}
PROMPT;
    }

    private function ask(string $key, string $prompt): string
    {
        $response = Http::withToken($key)
            ->timeout(self::TIMEOUT)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model'       => self::MODEL,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.1,
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

    private function clean($value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || mb_strtolower($value) === 'null') ? null : mb_substr($value, 0, 400);
    }
}
