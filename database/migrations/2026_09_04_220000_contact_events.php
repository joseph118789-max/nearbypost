<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Someone opened WhatsApp from a listing. Spec §14.3, §30.
 *
 * ⛔ THIS RECORDS AN INTENTION, NOT A SALE. Spec §30 is explicit: "Do not label
 * a WhatsApp click as a lead closed or transaction completed." Nobody knows
 * whether a message was sent, answered, or led to anything. The column is
 * called `opened_at` for that reason - it is the last moment NearbyPost can
 * honestly observe.
 *
 * ⛔ AND IT DOES NOT STORE WHO, BY DEFAULT. A provider's analytics should say
 * "eleven people opened WhatsApp from this listing this week", not name them.
 * user_id is kept only so a person can be rate-limited for click spam (§29)
 * and is never exposed to the provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_contact_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id')->nullable();
            $t->unsignedBigInteger('provider_profile_id')->nullable();

            // whatsapp | phone | email | website | listingmine
            $t->string('channel', 16)->default('whatsapp');

            // ⛔ Never shown to the provider. Abuse control only.
            $t->unsignedBigInteger('user_id')->nullable();

            // A coarse bucket, not an address: enough to notice a thousand
            // clicks from one place, not enough to follow anybody around.
            $t->string('ip_hash', 64)->nullable();

            $t->string('country_code', 2)->nullable();
            $t->unsignedBigInteger('sponsored_campaign_id')->nullable();

            $t->timestamp('opened_at');
            $t->timestamps();

            $t->index(['listing_id', 'opened_at'], 'contact_listing_idx');
            $t->index(['provider_profile_id', 'opened_at'], 'contact_provider_idx');

            // The rate-limit lookup: has this person clicked a lot, recently?
            $t->index(['user_id', 'opened_at'], 'contact_user_idx');

            $t->foreign('listing_id')->references('id')->on('marketplace_listings')->cascadeOnDelete();
            $t->foreign('provider_profile_id')->references('id')->on('provider_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_contact_events');
    }
};
