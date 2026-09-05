<?php

namespace App\Services\Community;

use App\Services\Ai\DeepSeekAdapter;
use Illuminate\Support\Facades\Log;

/**
 * DeepSeek as the community editor. One call, temperature 0.1, JSON only,
 * validated field by field; anything malformed is a "hold", never a publish.
 * The model is text only: the image is NOT semantically reviewed and the
 * result says so (image_relevance is null).
 */
final class DeepSeekCommunityModerationProvider implements CommunityModerationProvider
{
    public const SCHEMA = '1.1';

    /** @param DeepSeekAdapter|\App\Services\Ai\OpenAiAdapter|null $model */
    public function __construct(private ?object $model = null)
    {
    }

    /**
     * The configured editor: OpenAI (gpt-5-nano, reads the photo) when a key
     * is set and COMMUNITY_AI_PROVIDER is openai; DeepSeek otherwise.
     * Owner, 3 Sep: "may just use chatgpt nano".
     */
    public static function make(): self
    {
        // the AI panel decides: task "community_review", primary and helpers (4 Sep 2026)
        return new self(\App\Services\Ai\AiRouter::for('community_review'));
    }

    public function providerName(): string
    {
        if ($this->model && method_exists($this->model, 'providerName')) {
            return $this->model->providerName();
        }

        return $this->model instanceof \App\Services\Ai\OpenAiAdapter ? 'openai' : 'deepseek';
    }

    public function review(array $input): array
    {
        $model = $this->model ?? \App\Services\Ai\AiRouter::for('community_review');
        $t0 = microtime(true);

        if (!$model->isConfigured()) {
            return $this->hold('AI not configured', null, 0);
        }

        $imagePath = $input['image_path'] ?? null;
        $sees = $imagePath && is_file($imagePath) && method_exists($model, 'completeWithImage');

        try {
            $raw = $sees
                ? (string) $model->completeWithImage($this->prompt($input, true), $imagePath, 0.1)
                : (string) $model->complete($this->prompt($input, false), 0.1);
        } catch (\Throwable $x) {
            Log::warning('Community moderation call failed', ['error' => mb_substr($x->getMessage(), 0, 160)]);

            return $this->hold('AI unavailable', null, (int) ((microtime(true) - $t0) * 1000));
        }

        $latency = (int) ((microtime(true) - $t0) * 1000);
        $json = json_decode(trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw))), true);

        if (!is_array($json)) {
            return $this->hold('AI reply was not JSON', $raw, $latency);
        }

        $decision = in_array($json['decision'] ?? '', ['publish', 'needs_revision', 'hold', 'reject'], true) ? $json['decision'] : 'hold';
        $score = fn ($k) => isset($json[$k]) && is_numeric($json[$k]) ? max(0, min(100, (int) $json[$k])) : null;
        $flags = array_values(array_filter(array_map(fn ($f) => mb_substr(trim((string) $f), 0, 60), (array) ($json['flags'] ?? []))));
        $added = array_values(array_filter(array_map(fn ($f) => mb_substr(trim((string) $f), 0, 120), (array) ($json['added_claims'] ?? []))));

        $improvedTitle = $this->clean($json['improved_title'] ?? null, 200);
        $improvedBody  = $this->clean($json['improved_body'] ?? null, 5000);

        // our own check of the model's claim that no fact was added
        $guard = FactGuard::addedClaims($input['title'], $input['body'], (string) $improvedTitle, (string) $improvedBody, (string) ($input['place'] ?? ''));
        $factsPreserved = !empty($json['facts_preserved']) && $added === [] && $guard === [];

        return [
            'decision'        => $decision,
            'scores'          => [
                'quality' => $score('quality_score'), 'safety' => $score('safety_score'), 'location_confidence' => $score('location_confidence'),
                'newsworthiness' => $score('newsworthiness_score'), 'duplicate_probability' => $score('duplicate_probability'),
                'image_relevance' => $sees ? $score('image_relevance_score') : null,
            ],
            'image_reviewed'  => $sees,
            'visible_facts'   => $sees ? array_values(array_filter(array_map(fn ($f) => mb_substr(trim((string) $f), 0, 120), (array) ($json['visible_facts'] ?? [])))) : [],
            'breaking'        => !empty($json['breaking_news']) && ($score('newsworthiness_score') ?? 0) >= 60,
            'category'        => $this->clean($json['category'] ?? null, 80),
            'location_type'   => $this->clean($json['location_type'] ?? null, 24),
            'improved_title'  => $improvedTitle,
            'improved_body'   => $improvedBody,
            'facts_preserved' => $factsPreserved,
            'added_claims'    => array_values(array_unique(array_merge($added, $guard))),
            'flags'           => $flags,
            'user_message'    => $this->clean($json['user_message'] ?? null, 400),
            'schema_version'  => self::SCHEMA,
            'model'           => $model->model(),
            'latency_ms'      => $latency,
            'raw'             => mb_substr($raw, 0, 6000),
        ];
    }

    private function hold(string $why, ?string $raw, int $latency): array
    {
        return ['decision' => 'hold', 'scores' => [], 'breaking' => false, 'category' => null, 'location_type' => null,
            'improved_title' => null, 'improved_body' => null, 'facts_preserved' => false, 'added_claims' => [], 'flags' => ['ai_error'],
            'user_message' => null, 'schema_version' => self::SCHEMA, 'model' => null, 'latency_ms' => $latency, 'raw' => $raw ? mb_substr($why . ': ' . $raw, 0, 2000) : $why];
    }

    private function clean($v, int $limit): ?string
    {
        $v = is_string($v) ? trim(strip_tags($v)) : null;

        return $v === '' || $v === null ? null : mb_substr($v, 0, $limit);
    }

    private function prompt(array $in, bool $withImage = false): string
    {
        $imageRule = $withImage
            ? "A photo is attached. Say whether it plausibly shows what the text describes (image_relevance_score 0-100), list only what is VISIBLE in it as visible_facts (short phrases), and flag \"screenshot\", \"watermark\", \"stock_or_recycled\", \"faces\", \"number_plate\", \"minor\", \"graphic\" in flags when you see them. Never treat the photo as proof of an outcome the text does not state.\n"
            : "No photo was reviewed: set image_relevance_score to null.\n";
        $in['image_rule'] = $imageRule;

        $title = mb_substr(trim($in['title']), 0, 300);
        $body  = mb_substr(trim($in['body']), 0, 4000);
        $place = mb_substr(trim((string) ($in['place'] ?? '')), 0, 200);
        $ctx   = mb_substr(trim((string) ($in['context'] ?? '')), 0, 1500);
        $trigger = $in['trigger'] ?? 'submission';

        return <<<PROMPT
You are the editor and moderator of Community Reports on Nearbypost, a hyperlocal news site in Malaysia and Singapore.
A reader has posted a first-hand observation. You are an editor, not a witness: you may improve clarity, you must never invent facts.

RULES
- Improve the title strongly: a readable, searchable headline that says the place and the event, in the writer's language, using cautious words such as "reported", "seen", "appears".
- Improve the body lightly: spelling, grammar, repetition, making the location clear. Keep the writer's language and voice.
- Never add a person, organisation, location, time, cause, injury, number or outcome the writer did not give. Smoke is not proof a building is destroyed. Do not guess the factory from coordinates. Do not turn uncertainty into fact. No accusations against identifiable people. No clickbait.
- If your improved version would change meaning, certainty, names, time, numbers or location, set decision "needs_revision" and explain in user_message.
- Decide: "publish" (a genuine local observation, safe, clear enough), "needs_revision" (fixable by the writer; say what), "hold" (unsure, needs a person), "reject" (spam, advertising, harassment, hate, graphic, illegal, doxxing, not a local observation at all).
- breaking_news is true only for something happening now that people nearby need to know (fire, flood, crash, road closed, missing person, outage).
- location_type: one of incident, venue, road, area, event, other. safe_public_location_precision: exact, street, area.

Trigger: {$trigger}
Place the writer pinned: {$place}
{$in['image_rule']}{$ctx}

Submitted title: {$title}
Submitted body:
{$body}

Reply with JSON only, no prose, exactly these keys:
{"schema_version":"1.1","decision":"publish|needs_revision|hold|reject","quality_score":0-100,"safety_score":0-100,"location_confidence":0-100,"duplicate_probability":0-100,"image_relevance_score":0-100|null,"visible_facts":[],"breaking_news":true|false,"newsworthiness_score":0-100,"category":"...","location_type":"...","safe_public_location_precision":"exact|street|area","improved_title":"...","improved_body":"...","facts_preserved":true|false,"added_claims":[],"flags":[],"user_message":null|"..."}
PROMPT;
    }
}
