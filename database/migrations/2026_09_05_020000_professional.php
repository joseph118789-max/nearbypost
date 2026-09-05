<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Professionals, their credentials, and the authorities that issue them.
 * Spec §11, §20.10, §20.11, §20.12.
 *
 * ⛔ ONE PROFILE SHAPE FOR EVERY PROFESSION ON EARTH, AND ONE REPEATABLE
 * CREDENTIAL ROW. Spec §11.1: "Do not create a separate database schema for
 * each profession." A lawyer, a valuer, a doctor and an engineer differ in
 * which authority issues their licence and what it is called - not in the shape
 * of the fact. Modelling them separately would mean a new table every time the
 * site reaches a new country, and a new country is the whole point.
 *
 * ⛔ AND THE USER IS NEVER ASKED FOR AN ISSUE OR EXPIRY DATE. Spec §11.4 says
 * so twice: "Do NOT require the professional to enter issue date or expiry
 * date... If expiry information is obtained from an official register, store it
 * internally; do not force manual entry." A person copying a date off a
 * certificate gets it wrong, and the site would then be enforcing a date it
 * invented. `known_expiry_at` is nullable, system-derived, and there is no form
 * field behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Who issues credentials, where. Seeded per country by an operator, not
         * hard-coded: spec §26 forbids "Malaysian authority names in core
         * tables".
         */
        Schema::create('professional_authorities', function (Blueprint $t) {
            $t->id();
            $t->string('country_code', 2);
            $t->string('region_code', 12)->nullable();

            // legal | accounting | medical | engineering | architecture | valuation | ...
            $t->string('profession_family', 40);

            $t->string('name', 200);
            $t->string('short_name', 60)->nullable();

            // Where a moderator can check a number, when the authority publishes one.
            $t->string('register_url', 400)->nullable();

            // ⛔ Titles this authority's licence protects. Spec §11.9: using one
            // without the credential is what the site must block.
            $t->jsonb('protected_titles')->nullable();

            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index(['country_code', 'profession_family'], 'authorities_country_family_idx');
        });

        Schema::create('professional_profiles', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->string('slug', 160)->unique();
            $t->unsignedBigInteger('user_id');

            $t->string('public_professional_name', 160);

            // Protected: shown only where a professional rule requires it.
            $t->string('legal_name', 200)->nullable();

            $t->text('biography')->nullable();
            $t->string('country_of_residence', 2)->nullable();
            $t->jsonb('languages')->nullable();

            $t->string('professional_email', 190)->nullable();
            $t->string('whatsapp_country_code', 6)->nullable();
            $t->string('whatsapp_number', 24)->nullable();

            // draft | published | suspended | removed
            $t->string('publication_status', 16)->default('draft');

            $t->timestamps();
            $t->softDeletes();

            // Spec §20.10: one per account unless multi-persona is deliberately
            // wanted, and it is not.
            $t->unique('user_id', 'professional_profile_one_per_user');
        });

        Schema::create('professional_credentials', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('professional_profile_id');
            $t->unsignedBigInteger('profession_category_id')->nullable();
            $t->unsignedBigInteger('authority_id')->nullable();

            $t->string('jurisdiction_country_code', 2);
            $t->string('jurisdiction_region_code', 12)->nullable();

            // Free text as well as the id, because an authority the site has
            // not been taught yet must not stop somebody registering.
            $t->string('authority_name', 200);
            $t->string('credential_type', 60)->nullable();

            // ⛔ Normalised for lookup, stored as typed for display. A register
            // check that fails on a stray space or a lower-case letter is a
            // check that fails on the professional's behalf.
            $t->string('registration_number', 80);
            $t->string('registration_number_key', 80);

            $t->string('official_register_url', 400)->nullable();

            // Private. Spec §13.3: identity and credential documents never
            // reach the public disk.
            $t->unsignedBigInteger('document_media_id')->nullable();

            // Spec §11.5's nine states, exactly.
            $t->string('verification_status', 24)->default('not_submitted');
            $t->string('verification_method', 24)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedBigInteger('verified_by_admin_id')->nullable();
            $t->timestamp('next_review_at')->nullable();

            // ⛔ System-derived only. No form writes this.
            $t->timestamp('known_expiry_at')->nullable();

            $t->jsonb('verification_evidence')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['verification_status', 'next_review_at'], 'credentials_review_idx');
            $t->index(['jurisdiction_country_code', 'authority_name', 'registration_number_key'],
                      'credentials_lookup_idx');

            $t->foreign('professional_profile_id')->references('id')->on('professional_profiles')->cascadeOnDelete();
            $t->foreign('authority_id')->references('id')->on('professional_authorities')->nullOnDelete();
            $t->foreign('document_media_id')->references('id')->on('media')->nullOnDelete();
        });

        // ⛔ THE SAME NUMBER CANNOT BE REGISTERED TWICE WITH THE SAME AUTHORITY.
        // Two people claiming one practising certificate is either a typing
        // mistake or an impersonation, and both need a person to look.
        DB::statement("CREATE UNIQUE INDEX credentials_one_holder_per_number
                       ON professional_credentials
                       (jurisdiction_country_code, authority_name, registration_number_key)
                       WHERE deleted_at IS NULL
                         AND verification_status IN ('verified_register','verified_document')");

        // ⛔ A VERIFIED CREDENTIAL MUST NAME ITS METHOD AND ITS MOMENT.
        // Spec §11.5 needs the public wording to distinguish register-checked
        // from document-only, which is impossible if nobody recorded which.
        DB::statement("ALTER TABLE professional_credentials
                       ADD CONSTRAINT credentials_verified_has_provenance
                       CHECK (verification_status NOT IN ('verified_register','verified_document')
                              OR (verification_method IS NOT NULL AND verified_at IS NOT NULL))");

        /**
         * A professional's place in a firm. Spec §11.8, §20.12.
         *
         * ⛔ A CLINIC DOES NOT CONFER A LICENCE ON ITS STAFF. Spec §11.8: "Each
         * professional credential is verified independently. A clinic or firm
         * does not automatically confer valid status on every staff member."
         * So the membership is its own row with its own verification, and
         * nothing reads a firm's status onto a person.
         */
        Schema::create('professional_organisation_memberships', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('professional_profile_id');
            $t->unsignedBigInteger('provider_profile_id');

            $t->string('role_title', 120);

            // claimed | confirmed | ended | disputed
            $t->string('relationship_status', 16)->default('claimed');
            $t->string('verification_status', 24)->default('not_submitted');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();

            $t->unique(['professional_profile_id', 'provider_profile_id', 'role_title'],
                       'professional_org_unique');

            $t->foreign('professional_profile_id')->references('id')->on('professional_profiles')->cascadeOnDelete();
            $t->foreign('provider_profile_id')->references('id')->on('provider_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_organisation_memberships');
        Schema::dropIfExists('professional_credentials');
        Schema::dropIfExists('professional_profiles');
        Schema::dropIfExists('professional_authorities');
    }
};
