<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

/**
 * What happens the moment a report arrives. Spec §17.3.
 *
 * ⛔ ONE CREDIBLE HIGH-SEVERITY REPORT IS ENOUGH. Spec §17.3 says so plainly:
 * "Do not wait for a fixed number such as 50 reports when one credible
 * high-severity report warrants action." A threshold protects a scammer for as
 * long as it takes to reach it, and the thing being protected in the meantime
 * is a listing taking people's money.
 *
 * ⛔ AND A LOW-SEVERITY REPORT REMOVES NOTHING. Wrong opening hours is worth
 * telling the provider about. It is not worth taking their listing down, and a
 * system that does becomes a weapon for their competitor.
 */
class ReportRouting
{
    /**
     * reason code => severity. Spec §17.1, §17.2.
     *
     * The severity is a property of the ACCUSATION, decided here, not of who
     * happened to be reading the queue.
     */
    private const SEVERITY = [
        // critical: someone may be harmed, or it is plainly illegal
        'threats_violence'        => 'critical',
        'illegal_goods'           => 'critical',
        'child_safety'            => 'critical',
        'minor_safety_carpool'    => 'critical',

        // high: the listing itself is the harm
        'suspected_scam'          => 'high',
        'impersonation'           => 'high',
        'stolen_credential'       => 'high',
        'false_qualification'     => 'high',
        'invalid_credential'      => 'high',
        'false_certification'     => 'high',
        'commercial_transport'    => 'high',
        'unsafe_vehicle'          => 'high',
        'food_safety'             => 'high',

        // medium: it misleads, and should stop being shown until checked
        'misleading_information'  => 'medium',
        'stolen_images'           => 'medium',
        'prohibited_product'      => 'medium',
        'misleading_allergens'    => 'medium',
        'not_associated_with_firm' => 'medium',
        'guaranteed_result_claim' => 'medium',
        'harassment'              => 'medium',

        // low: worth telling the provider, not worth hiding anything
        'wrong_location'          => 'low',
        'wrong_hours'             => 'low',
        'duplicate_listing'       => 'low',
        'business_closed'         => 'low',
        'other'                   => 'low',
    ];

    /** What each severity does to the target, immediately. */
    private const ACTION = [
        'critical' => 'remove_and_lock',
        'high'     => 'suspend_pending_review',
        'medium'   => 'hide_pending_review',
        'low'      => 'notify_provider',
    ];

    public static function severityOf(string $reasonCode): string
    {
        // ⛔ An unrecognised reason is MEDIUM, not low. A reason code this
        // class has never heard of is most likely one added to a form and not
        // added here, and quietly treating it as trivial is how a new category
        // of harm arrives unnoticed.
        return self::SEVERITY[$reasonCode] ?? 'medium';
    }

    public static function actionFor(string $severity): string
    {
        return self::ACTION[$severity] ?? 'notify_provider';
    }

    /**
     * File a report and apply its immediate consequence.
     *
     * @param  array{reporter_user_id?: ?int, description?: ?string, evidence_media_id?: ?int, ip?: ?string, device?: ?string}  $extra
     * @return array{report_id: int, severity: string, action: string, grouped: bool}
     */
    public static function file(string $targetType, int $targetId, string $reasonCode, array $extra = []): array
    {
        $severity = self::severityOf($reasonCode);
        $action   = self::actionFor($severity);

        $description = trim((string) ($extra['description'] ?? ''));
        $groupKey    = $targetType . ':' . $targetId . ':' . $reasonCode;

        // ⛔ Grouping hides the ROW, never the EVIDENCE. Spec §17.4: "substantial
        // new evidence must trigger reconsideration". A repeat report that says
        // something joins the group and still asks to be read; one that says
        // nothing new is just a second vote.
        $alreadyOpen = DB::table('marketplace_reports')
            ->where('group_key', $groupKey)
            ->whereIn('status', ['open', 'grouped'])
            ->exists();

        $grouped = $alreadyOpen && $description === '';

        $reportId = (int) DB::table('marketplace_reports')->insertGetId([
            'reporter_user_id'  => $extra['reporter_user_id'] ?? null,
            'target_type'       => $targetType,
            'target_id'         => $targetId,
            'reason_code'       => $reasonCode,
            'severity'          => $severity,
            'description'       => $description === '' ? null : mb_substr($description, 0, 1000),
            'evidence_media_id' => $extra['evidence_media_id'] ?? null,
            'status'            => $grouped ? 'grouped' : 'open',
            'group_key'         => $groupKey,
            'ip_hash'           => empty($extra['ip']) ? null : hash_hmac('sha256', $extra['ip'], (string) config('app.key')),
            'device_token'      => $extra['device'] ?? null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        self::apply($targetType, $targetId, $action, $reportId, $reasonCode);

        return ['report_id' => $reportId, 'severity' => $severity, 'action' => $action, 'grouped' => $grouped];
    }

    /**
     * The consequence, applied now.
     *
     * ⛔ Only a LISTING is acted on automatically at this stage of the build.
     * A provider or a professional carries other people's livelihoods, and
     * suspending one on a single unreviewed accusation is a heavier decision
     * than suspending one advertisement. Those go to the queue with their
     * severity attached and a person decides.
     */
    private static function apply(string $targetType, int $targetId, string $action, int $reportId, string $reasonCode): void
    {
        if ($action === 'notify_provider' || $targetType !== 'listing') {
            return;
        }

        $status = match ($action) {
            'remove_and_lock'        => 'removed',
            'suspend_pending_review' => 'suspended',
            'hide_pending_review'    => 'paused',
            default                  => null,
        };

        if ($status === null) {
            return;
        }

        $before = DB::table('marketplace_listings')->where('id', $targetId)
            ->first(['publication_status', 'moderation_status']);

        if ($before === null) {
            return;
        }

        DB::table('marketplace_listings')->where('id', $targetId)->update([
            'publication_status' => $status,
            'moderation_status'  => 'manual_review',
            'updated_at'         => now(),
        ]);

        DB::table('audit_events')->insert([
            'event'        => 'listing.' . $action,
            'subject_type' => 'listing',
            'subject_id'   => $targetId,
            'before'       => json_encode((array) $before),
            'after'        => json_encode(['publication_status' => $status, 'moderation_status' => 'manual_review']),
            'reason'       => 'report #' . $reportId . ': ' . $reasonCode,
            'created_at'   => now(),
        ]);
    }
}
