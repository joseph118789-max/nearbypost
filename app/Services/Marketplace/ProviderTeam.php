<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

/**
 * Who may act for a business, and how much. Spec §8.6, §21.2.
 *
 * ⛔ OWNERSHIP TRANSFER IS ONE TRANSACTION OR IT IS NOTHING.
 *
 * The database holds a partial unique index - one active owner per provider -
 * so a transfer written as two statements fails halfway by design: promote the
 * new owner first and the index refuses; demote the old one first and the
 * business has no owner until the second statement lands. Either way an
 * interrupted request leaves a business nobody can administer, and the only
 * person who could fix it is the owner it no longer has.
 *
 * So the whole swap happens inside one transaction, in the order the index
 * allows, and the audit row is written with it.
 */
class ProviderTeam
{
    /** Spec §8.6. Ordered from most to least, so comparisons read naturally. */
    private const ROLES = ['owner', 'manager', 'content_editor', 'support', 'viewer'];

    /** What each role may do. Spec §21.2. */
    private const MAY = [
        'owner'          => ['edit', 'listings', 'media', 'reply', 'analytics', 'members', 'transfer', 'delete'],
        'manager'        => ['edit', 'listings', 'media', 'reply', 'analytics'],
        'content_editor' => ['listings', 'media'],
        'support'        => ['reply'],
        'viewer'         => ['analytics'],
    ];

    public static function may(int $userId, int $providerId, string $action): bool
    {
        $role = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('role');

        return $role !== null && in_array($action, self::MAY[(string) $role] ?? [], true);
    }

    /**
     * Invite somebody onto the team.
     *
     * @throws ProviderRefused
     */
    public static function invite(int $actorId, int $providerId, int $inviteeId, string $role): int
    {
        if (!self::may($actorId, $providerId, 'members')) {
            throw new ProviderRefused('Only the owner can add people to this business.');
        }

        if (!in_array($role, self::ROLES, true)) {
            throw new ProviderRefused('That is not a role this site has.');
        }

        // ⛔ A second owner cannot be invited. Ownership moves by transfer,
        // which is deliberate and audited; inviting one would either be refused
        // by the index or create the ambiguity the index exists to prevent.
        if ($role === 'owner') {
            throw new ProviderRefused('To hand the business over, use transfer ownership.');
        }

        $already = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $inviteeId)
            ->first(['id', 'status', 'role']);

        if ($already !== null) {
            throw new ProviderRefused('That person is already on this business.');
        }

        $id = (int) DB::table('provider_members')->insertGetId([
            'provider_profile_id' => $providerId,
            'user_id'             => $inviteeId,
            'role'                => $role,
            'status'              => 'invited',
            'invited_by_user_id'  => $actorId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        self::audit('provider.member_invited', $providerId, $actorId, null,
            ['user_id' => $inviteeId, 'role' => $role]);

        return $id;
    }

    /** The invitee accepts. Until then they can do nothing. */
    public static function accept(int $userId, int $providerId): void
    {
        $updated = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $userId)
            ->where('status', 'invited')
            ->update(['status' => 'active', 'accepted_at' => now(), 'updated_at' => now()]);

        if ($updated === 0) {
            throw new ProviderRefused('There is no invitation waiting for you here.');
        }

        self::audit('provider.member_accepted', $providerId, $userId, null, ['user_id' => $userId]);
    }

    /** @throws ProviderRefused */
    public static function changeRole(int $actorId, int $providerId, int $memberUserId, string $role): void
    {
        if (!self::may($actorId, $providerId, 'members')) {
            throw new ProviderRefused('Only the owner can change what people may do here.');
        }

        if ($role === 'owner') {
            throw new ProviderRefused('To hand the business over, use transfer ownership.');
        }

        $current = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $memberUserId)
            ->first(['role', 'status']);

        if ($current === null) {
            throw new ProviderRefused('That person is not on this business.');
        }

        // ⛔ The owner cannot demote themselves out of ownership, because that
        // would leave the business with none. They transfer it, or they close it.
        if ($current->role === 'owner') {
            throw new ProviderRefused('The owner cannot change their own role. Transfer the business instead.');
        }

        DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $memberUserId)
            ->update(['role' => $role, 'updated_at' => now()]);

        self::audit('provider.role_changed', $providerId, $actorId,
            ['user_id' => $memberUserId, 'role' => $current->role],
            ['user_id' => $memberUserId, 'role' => $role]);
    }

    /**
     * Hand the business over. Spec §8.6.
     *
     * ⛔ REAUTHENTICATION IS THE CALLER'S JOB AND IS NOT OPTIONAL. Spec §8.6
     * and §29 both require it, and this method cannot check a password itself -
     * it has no request. So it demands proof that the check happened, and a
     * caller that has not done it cannot fabricate the argument by accident.
     *
     * @param  bool  $reauthenticated  true only after the actor re-entered their password
     *
     * @throws ProviderRefused
     */
    public static function transferOwnership(int $actorId, int $providerId, int $toUserId, bool $reauthenticated): void
    {
        if (!$reauthenticated) {
            throw new ProviderRefused('Confirm your password before handing over a business.');
        }

        if (!self::may($actorId, $providerId, 'transfer')) {
            throw new ProviderRefused('Only the owner can hand this business over.');
        }

        if ($actorId === $toUserId) {
            throw new ProviderRefused('You already own this business.');
        }

        $recipient = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $toUserId)
            ->first(['role', 'status']);

        // ⛔ Only to somebody already on the team and active. Handing a
        // business to a stranger who has never accepted an invitation would
        // let one account push ownership onto another without consent.
        if ($recipient === null || $recipient->status !== 'active') {
            throw new ProviderRefused('Add them to the business first, and let them accept, before handing it over.');
        }

        DB::transaction(function () use ($actorId, $providerId, $toUserId, $recipient) {
            // Order matters: the partial unique index allows exactly one active
            // owner, so the outgoing one steps down first, inside the same
            // transaction that promotes the incoming one. Nothing outside this
            // block ever sees a business with two owners or none.
            DB::table('provider_members')
                ->where('provider_profile_id', $providerId)->where('user_id', $actorId)
                ->update(['role' => 'manager', 'updated_at' => now()]);

            DB::table('provider_members')
                ->where('provider_profile_id', $providerId)->where('user_id', $toUserId)
                ->update(['role' => 'owner', 'updated_at' => now()]);

            DB::table('provider_profiles')->where('id', $providerId)
                ->update(['created_by_user_id' => $toUserId, 'updated_at' => now()]);

            self::audit('provider.ownership_transferred', $providerId, $actorId,
                ['owner' => $actorId],
                ['owner' => $toUserId, 'previous_owner_now' => 'manager', 'their_previous_role' => $recipient->role]);
        });
    }

    /** @throws ProviderRefused */
    public static function remove(int $actorId, int $providerId, int $memberUserId): void
    {
        if (!self::may($actorId, $providerId, 'members')) {
            throw new ProviderRefused('Only the owner can remove people from this business.');
        }

        $role = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $memberUserId)
            ->value('role');

        if ($role === 'owner') {
            throw new ProviderRefused('The owner cannot be removed. Transfer the business first.');
        }

        DB::table('provider_members')
            ->where('provider_profile_id', $providerId)->where('user_id', $memberUserId)
            ->update(['status' => 'left', 'updated_at' => now()]);

        self::audit('provider.member_removed', $providerId, $actorId,
            ['user_id' => $memberUserId, 'role' => $role], null);
    }

    private static function audit(string $event, int $providerId, int $actorId, ?array $before, ?array $after): void
    {
        DB::table('audit_events')->insert([
            'event'         => $event,
            'subject_type'  => 'provider',
            'subject_id'    => $providerId,
            'actor_user_id' => $actorId,
            'before'        => $before === null ? null : json_encode($before),
            'after'         => $after === null ? null : json_encode($after),
            'created_at'    => now(),
        ]);
    }
}
