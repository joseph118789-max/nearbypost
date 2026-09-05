<?php

namespace App\Services\Community;

/**
 * The editor and moderator of a community report. An implementation reads
 * the submission and answers with a strict, validated structure: a decision,
 * scores, a safely improved title and body, and whether any claim was added.
 */
interface CommunityModerationProvider
{
    /**
     * @param array{title: string, body: string, place: ?string, language: ?string, trigger: string, context: ?string} $input
     * @return array{decision: string, scores: array<string, int|null>, breaking: bool, category: ?string, location_type: ?string,
     *               improved_title: ?string, improved_body: ?string, facts_preserved: bool, added_claims: list<string>,
     *               flags: list<string>, user_message: ?string, schema_version: string, model: ?string, latency_ms: int, raw: ?string}
     */
    public function review(array $input): array;
}
