<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports, decisions, verification and the audit trail.
 * Spec §17, §20.17, §20.18, §20.19, §20.20.
 *
 * ⛔ THE THIRD "MERGE LATER" DECISION IN THIS MODULE, AND THE LAST ONE ALLOWED.
 *
 * `community_reports` is news-only - news_item_id is NOT NULL - and is written
 * by four live classes. Making it polymorphic during Phase 1 would put the news
 * moderation path at risk for no Marketplace benefit, which is the same
 * reasoning that kept `categories` and `community_post_media` separate.
 *
 * Each of those three is individually right and collectively a habit. They are
 * recorded together as tracked debt in the build-order document, with the
 * consolidation as its own piece of work rather than a note nobody reads.
 * Until then: news reports there, Marketplace reports here, nothing reads both.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Someone said something is wrong. Spec §20.17.
         *
         * ⛔ SEVERITY IS SET WHEN THE REPORT ARRIVES, NOT WHEN SOMEBODY GETS TO
         * IT. Spec §17.3: a scam or an impersonation suspends immediately,
         * pending review; wrong opening hours does not. Deciding severity at
         * triage time means the queue order is the only protection, and a queue
         * is exactly what a busy week takes away.
         */
        Schema::create('marketplace_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reporter_user_id')->nullable();

            // listing | provider | professional | review | carpool_notice | user
            $t->string('target_type', 24);
            $t->unsignedBigInteger('target_id');

            $t->string('reason_code', 40);

            // low | medium | high | critical
            $t->string('severity', 10);

            $t->string('description', 1000)->nullable();
            $t->unsignedBigInteger('evidence_media_id')->nullable();

            // open | grouped | actioned | dismissed | reopened
            $t->string('status', 12)->default('open');

            // ⛔ Duplicates of the same complaint about the same thing share a
            // key so a queue shows one item, not forty. Spec §17.4 is explicit
            // that grouping must never suppress new evidence, so a report
            // carrying a description joins the group but still asks to be read.
            $t->string('group_key', 80)->nullable();

            $t->unsignedBigInteger('assigned_to_admin_id')->nullable();

            $t->string('decision_code', 40)->nullable();

            // Protected: the reasoning behind a decision is for the record and
            // for an appeal, not for the reported party to read.
            $t->text('decision_notes')->nullable();
            $t->timestamp('decided_at')->nullable();

            // Abuse signals. Spec §16.1: IP is ONE signal, never the rule -
            // households and workplaces share one and an attacker changes theirs.
            $t->string('ip_hash', 64)->nullable();
            $t->string('device_token', 64)->nullable();

            $t->timestamps();

            $t->index(['target_type', 'target_id', 'status'], 'mp_reports_target_idx');
            $t->index(['status', 'severity', 'created_at'], 'mp_reports_queue_idx');
            $t->index('group_key', 'mp_reports_group_idx');

            $t->foreign('evidence_media_id')->references('id')->on('media')->nullOnDelete();
        });

        // ⛔ A DECIDED REPORT MUST SAY WHY. An outcome with no reason cannot be
        // appealed against, cannot be audited, and cannot be explained to the
        // person it was applied to.
        DB::statement("ALTER TABLE marketplace_reports
                       ADD CONSTRAINT mp_reports_decision_has_reason
                       CHECK (decided_at IS NULL OR decision_code IS NOT NULL)");

        /**
         * What the AI or a moderator decided about a piece of content, and what
         * it looked like before. Spec §20.19.
         *
         * ⛔ THE ORIGINAL IS KEPT SEPARATELY FROM WHAT WAS PUBLISHED. Spec
         * §18.3 forbids the AI silently changing a factual commercial claim -
         * a price, a location, a certification. That rule is only enforceable
         * if both versions survive, so both are stored and neither overwrites
         * the other.
         */
        Schema::create('moderation_decisions', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 24);
            $t->unsignedBigInteger('subject_id');

            $t->jsonb('original_content')->nullable();
            $t->jsonb('proposed_content')->nullable();

            $t->string('provider', 32)->nullable();     // deepseek, openai, human
            $t->string('model', 64)->nullable();

            // accept | accept_with_formatting | request_correction | reject | manual_review
            $t->string('decision', 24);

            $t->jsonb('reason_codes')->nullable();
            $t->decimal('confidence', 4, 3)->nullable();
            $t->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $t->timestamps();

            $t->index(['subject_type', 'subject_id', 'created_at'], 'mp_decisions_subject_idx');
            $t->index(['decision', 'created_at'], 'mp_decisions_outcome_idx');
        });

        /**
         * One checked fact. Spec §20.18, §3.4.
         *
         * ⛔ THE BADGE A READER SEES IS BUILT FROM THESE ROWS, NEVER FROM A
         * SUMMARY COLUMN. "Business registration confirmed" is a row saying
         * exactly that, with who checked it and when. Nothing here can render
         * as "Trusted Merchant", because no row says that and none can.
         */
        Schema::create('verification_checks', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 24);              // provider | professional | user
            $t->unsignedBigInteger('subject_id');

            // identity | phone | business_registration | professional_credential
            //          | organisation_relationship | premises
            $t->string('verification_type', 40);

            // not_submitted | submitted | under_review | verified_register
            //   | verified_document | unable_to_verify | expired | suspended | rejected
            $t->string('status', 24)->default('not_submitted');

            // official_register | document | api_lookup | manual
            $t->string('method', 24)->nullable();

            // Protected reference to the evidence, never a public path.
            $t->string('evidence_reference', 200)->nullable();

            $t->unsignedBigInteger('checked_by_admin_id')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('next_review_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['subject_type', 'subject_id', 'verification_type'], 'mp_verification_unique');
            $t->index(['next_review_at'], 'mp_verification_recheck_idx');
        });

        // ⛔ A VERIFIED CHECK MUST NAME ITS METHOD. Spec §11.5 requires the
        // public wording to distinguish "verified against an official register"
        // from "verified from a document only", and it cannot if nobody
        // recorded which happened.
        DB::statement("ALTER TABLE verification_checks
                       ADD CONSTRAINT mp_verification_method_recorded
                       CHECK (status NOT IN ('verified_register','verified_document')
                              OR (method IS NOT NULL AND checked_at IS NOT NULL))");

        /**
         * Who did what to whom. Spec §20.20.
         *
         * ⛔ APPEND ONLY. There is no update path and no delete path in the
         * application: an audit trail that can be edited is not one. Ownership
         * transfers, role changes, credential decisions, suspensions and
         * review removals all land here.
         */
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->string('event', 60);

            $t->string('subject_type', 24);
            $t->unsignedBigInteger('subject_id');

            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->unsignedBigInteger('actor_admin_id')->nullable();

            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->string('reason', 400)->nullable();

            $t->string('ip_hash', 64)->nullable();

            // No updated_at: nothing here is ever updated.
            $t->timestamp('created_at');

            $t->index(['subject_type', 'subject_id', 'created_at'], 'mp_audit_subject_idx');
            $t->index(['event', 'created_at'], 'mp_audit_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('verification_checks');
        Schema::dropIfExists('moderation_decisions');
        Schema::dropIfExists('marketplace_reports');
    }
};
