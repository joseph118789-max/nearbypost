<?php

namespace App\Services\Community;

use App\Services\Ai\DeepSeekAdapter;
use Illuminate\Support\Facades\DB;

/** A comment is published at once and checked right after: spam, harassment, doxxing, links. A failed check hides it with a note. */
final class CommentModeration
{
    public static function check(int $commentId): void
    {
        $c = DB::table('community_comments')->where('id', $commentId)->first();

        if (!$c) {
            return;
        }

        $body = (string) $c->body;
        $flags = [];

        if (preg_match_all('/https?:\/\//i', $body) > 2) { $flags[] = 'links'; }
        if (preg_match('/\b(\+?6?01\d[\s-]?\d{3,4}[\s-]?\d{4}|\d{6}-\d{2}-\d{4})\b/', $body)) { $flags[] = 'personal_number'; }

        $model = \App\Services\Ai\AiRouter::for('comment_moderation');   // the panel decides which model, and who helps

        if ($model->isConfigured() && mb_strlen($body) >= 12) {
            try {
                $reply = (string) $model->complete("You moderate comments under community news reports in Malaysia and Singapore (English, Malay, Chinese, Tamil).\n"
                    . "Reply JSON only: {\"ok\": true|false, \"reason\": \"spam|harassment|hate|threat|doxxing|graphic|advertising|ok\"}. Refuse only what is clearly unacceptable; disagreement and criticism are fine.\n\nComment:\n" . mb_substr($body, 0, 2000), 0.0);
                $json = json_decode(trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($reply))), true);

                if (is_array($json) && empty($json['ok'])) {
                    $flags[] = (string) ($json['reason'] ?? 'refused');
                }
            } catch (\Throwable) {
                // the model was unavailable: the comment stands, the flags above still apply
            }
        }

        if ($flags !== []) {
            DB::table('community_comments')->where('id', $commentId)->update(['status' => 'hidden', 'moderation_note' => implode(', ', $flags), 'updated_at' => now()]);
        }
    }
}
