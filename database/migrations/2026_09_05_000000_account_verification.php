<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has been confirmed about an account. Spec §7, §20.1, §3.4.
 *
 * ⛔ ADDITIVE ONLY. `users` is a live table with a working login, community
 * posting and a credibility score on it. Nothing here renames, moves or
 * repurposes an existing column; four nullable columns are added and every
 * existing row keeps working exactly as it did.
 *
 * ⛔ THE OTP FLOW DOES NOT EXIST YET, AND THAT IS THE POINT OF DOING THIS NOW.
 * Spec §7 gates personal listings, neighbour offers and Car Pool on a confirmed
 * phone, and no SMS or WhatsApp provider has been chosen - it costs money per
 * message and the owner has not picked one. So the COLUMN and the GATE go in
 * now, and stay honest: nobody has a confirmed phone, so nobody passes the
 * gate. When a provider is chosen, one service writes `phone_verified_at` and
 * every gate in the module starts working at once. Nothing else changes.
 *
 * The alternative - leaving the gate out until the provider exists - means
 * writing the whole of Phase 2 with no gate and adding it afterwards to code
 * that has learned to live without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // The moment a one-time code sent to their number came back.
            // Null means unconfirmed, which is every account today.
            $t->timestamp('phone_verified_at')->nullable()->after('mobile');

            // The number the code was sent to, normalised to digits. Kept apart
            // from any number a PROVIDER publishes: spec §19.4 requires the
            // personal contact detail and the public business one to be
            // separate, because they are often not the same number and the
            // reader must never be shown the private one.
            $t->string('phone_e164', 20)->nullable()->after('phone_verified_at');

            // none | submitted | confirmed | rejected
            // Spec §3.4 allows "Identity confirmed" as a badge; no mechanism
            // exists to earn it yet, so every account sits at none.
            $t->string('identity_verification_status', 16)->default('none')->after('phone_e164');

            // active | limited | suspended - account-level enforcement, which
            // §3.3 allows to consider all behaviour even though PUBLIC
            // reputation stays contextual.
            $t->string('account_status', 12)->default('active')->after('identity_verification_status');
        });

        Schema::table('users', function (Blueprint $t) {
            $t->index(['account_status'], 'users_account_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex('users_account_status_idx');
            $t->dropColumn(['phone_verified_at', 'phone_e164', 'identity_verification_status', 'account_status']);
        });
    }
};
