<?php

use App\Http\Controllers\Admin\BrainController;
use App\Http\Controllers\Admin\CaseStudyController;
use App\Http\Controllers\Admin\ContributionController;
use App\Http\Controllers\Admin\MarketplaceAdminController;
use App\Http\Controllers\Admin\SourceRequestsAdmin;
use App\Http\Controllers\Admin\ProfessionalsAdminController;
use App\Http\Controllers\Admin\CountriesReportController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\NewsController;
use App\Http\Controllers\Admin\PlaceReviewController;
use App\Http\Controllers\Admin\RemovalController;
use App\Http\Controllers\Admin\RuleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::prefix("admin")->name("admin.")->group(function () {

    // Guest routes
    Route::middleware("guest:admin")->group(function () {
        Route::get("/login", [App\Http\Controllers\Admin\Auth\LoginController::class, "showLoginForm"])->name("login");
        Route::post("/login", [App\Http\Controllers\Admin\Auth\LoginController::class, "login"]);
    });

    // Protected routes
    Route::middleware("auth:admin")->group(function () {
        Route::get("/", [DashboardController::class, "index"])->name("index");
        Route::get("/dashboard", [DashboardController::class, "index"])->name("dashboard");
        Route::post("/logout", [App\Http\Controllers\Admin\Auth\LoginController::class, "logout"])->name("logout");

        Route::get("/news", [NewsController::class, "index"])->name("news.index");
        Route::post("/news", [NewsController::class, "store"])->name("news.store");
        Route::get("/news/{id}", [NewsController::class, "show"])->name("news.show");
        Route::put("/news/{id}", [NewsController::class, "update"])->name("news.update");
        Route::delete("/news/{id}", [NewsController::class, "destroy"])->name("news.destroy");

        Route::get("/subscribers", [SubscriberController::class, "index"])->name("subscribers.index");
        Route::post("/subscribers", [SubscriberController::class, "store"])->name("subscribers.store");
        Route::get("/subscribers/{id}", [SubscriberController::class, "show"])->name("subscribers.show");
        Route::put("/subscribers/{id}", [SubscriberController::class, "update"])->name("subscribers.update");
        Route::delete("/subscribers/{id}", [SubscriberController::class, "destroy"])->name("subscribers.destroy");

        Route::get("/settings/categories", [SettingsController::class, "getCategories"])->name("settings.categories");
        Route::post("/settings/categories", [SettingsController::class, "saveCategories"])->name("settings.categories.save");
        Route::get("/settings/wa-groups", [SettingsController::class, "getWAGroups"])->name("settings.wa-groups");
        Route::post("/settings/wa-groups", [SettingsController::class, "saveWAGroups"])->name("settings.wa-groups.save");

        // Intel Analytics was removed on the owner's instruction ("News
        // management is redundant can remove / delete... Intel Analytics"),
        // but this route survived the removal and went on answering.
        //
        // ⛔ It threw on EVERY call: news_items has no click_count column and
        // has not had one for as long as the log goes back. 100 five-hundreds
        // between 01:04 and 12:44 on 4 Sep 2026 alone, roughly one every seven
        // minutes, from something still polling a page nobody can reach from
        // the admin menu. A removed feature that still has a route is not
        // removed; it is invisible and still failing.

        // Reader submissions waiting for a person to read them.
        Route::get("/contributions", [ContributionController::class, "index"])->name("contributions.index");

        // the desks beside Official / Unofficial (owner, 4 Sep 2026)
        // The Marketplace desk. Was a placeholder view; now the real thing.
        // Publishers who offered their own site through the public form.
        Route::get("/source-requests", [SourceRequestsAdmin::class, "index"])->name("source-requests.index");
        Route::post("/source-requests/{id}/approve", [SourceRequestsAdmin::class, "approve"])->name("source-requests.approve");
        Route::post("/source-requests/{id}/decline", [SourceRequestsAdmin::class, "decline"])->name("source-requests.decline");
        Route::post("/source-requests/{id}/recheck", [SourceRequestsAdmin::class, "recheck"])->name("source-requests.recheck");
        Route::post("/source-requests/{id}/verify", [SourceRequestsAdmin::class, "verify"])->name("source-requests.verify");
        Route::post("/sources/{id}/revoke", [SourceRequestsAdmin::class, "revoke"])->name("sources.revoke");
        Route::get("/marketplace", [MarketplaceAdminController::class, "index"])->name("marketplace.index");
        // What the whole module is waiting on, derived at request time.
        Route::get("/marketplace/status", [MarketplaceAdminController::class, "status"])->name("marketplace.status");
        Route::post("/marketplace/listings/{id}/approve",  [MarketplaceAdminController::class, "approve"])->name("marketplace.approve");
        Route::post("/marketplace/listings/{id}/reject",   [MarketplaceAdminController::class, "reject"])->name("marketplace.reject");
        Route::post("/marketplace/listings/{id}/suspend",  [MarketplaceAdminController::class, "suspend"])->name("marketplace.suspend");
        Route::post("/marketplace/listings/{id}/restore",  [MarketplaceAdminController::class, "restore"])->name("marketplace.restore");
        Route::post("/marketplace/reports/{id}/resolve",   [MarketplaceAdminController::class, "resolveReport"])->name("marketplace.report.resolve");

        // The credential queue. Spec 25.1: professional credential verification
        // is its own queue because the decision is a different kind - one
        // moderator checking one number against one register.
        Route::get("/marketplace/professionals", [ProfessionalsAdminController::class, "index"])->name("professionals.index");
        Route::post("/marketplace/credentials/{id}/register", [ProfessionalsAdminController::class, "confirmFromRegister"])->name("professionals.register");
        Route::post("/marketplace/credentials/{id}/document", [ProfessionalsAdminController::class, "confirmFromDocument"])->name("professionals.document");
        Route::post("/marketplace/credentials/{id}/unable",   [ProfessionalsAdminController::class, "unableToVerify"])->name("professionals.unable");
        Route::post("/marketplace/credentials/{id}/reject",   [ProfessionalsAdminController::class, "reject"])->name("professionals.reject");
        Route::post("/marketplace/credentials/{id}/suspend",  [ProfessionalsAdminController::class, "suspend"])->name("professionals.suspend");
        Route::get("/members", [\App\Http\Controllers\Admin\MembersController::class, "index"])->name("members.index");
        Route::post("/members/{id}/trust", [\App\Http\Controllers\Admin\MembersController::class, "trust"])->whereNumber("id")->name("members.trust");
        Route::get("/admins", [\App\Http\Controllers\Admin\AdminsController::class, "index"])->name("admins.index");
        Route::post("/admins", [\App\Http\Controllers\Admin\AdminsController::class, "store"])->name("admins.store");
        Route::delete("/admins/{id}", [\App\Http\Controllers\Admin\AdminsController::class, "destroy"])->whereNumber("id")->name("admins.destroy");
        // the admin posts with the reader's form and it goes live at once (owner, 4 Sep 2026)
        Route::get("/post/{kind}", [\App\Http\Controllers\Admin\AdminPostController::class, "create"])->where("kind", "official|unofficial")->name("post.create");
        Route::post("/post/{kind}", [\App\Http\Controllers\Admin\AdminPostController::class, "store"])->where("kind", "official|unofficial")->name("post.store");
        Route::post("/contributions/{id}/approve", [ContributionController::class, "approve"])
            ->whereNumber("id")->name("contributions.approve");
        Route::post("/contributions/{id}/reject", [ContributionController::class, "reject"])
            ->whereNumber("id")->name("contributions.reject");
        Route::post("/contributions/{id}/unpublish", [ContributionController::class, "unpublish"])
            ->whereNumber("id")->name("contributions.unpublish");
        Route::post("/contributions/{id}/republish", [ContributionController::class, "republish"])
            ->whereNumber("id")->name("contributions.republish");
        Route::delete("/contributions/{id}", [ContributionController::class, "destroy"])
            ->whereNumber("id")->name("contributions.destroy");
        Route::post("/contributors/{id}/untrust", [ContributionController::class, "untrust"])
            ->whereNumber("id")->name("contributions.untrust");

        // What was taken down, and what it should teach the reviewer.
        Route::get("/removals", [RemovalController::class, "index"])->name("removals.index");
        Route::post("/removals/suggest", [RemovalController::class, "suggest"])->name("removals.suggest");
        Route::post("/removals/accept", [RemovalController::class, "accept"])->name("removals.accept");
        Route::post("/removals/dismiss", [RemovalController::class, "dismiss"])->name("removals.dismiss");

        // One story happening in many places at once.
        Route::get("/cases", [CaseStudyController::class, "index"])->name("cases.index");
        Route::get("/cases/new", [CaseStudyController::class, "create"])->name("cases.create");
        Route::post("/cases", [CaseStudyController::class, "store"])->name("cases.store");
        Route::get("/cases/{id}", [CaseStudyController::class, "show"])
            ->whereNumber("id")->name("cases.show");
        Route::post("/cases/{id}/places", [CaseStudyController::class, "addPlace"])
            ->whereNumber("id")->name("cases.places.add");
        Route::delete("/cases/{id}/places/{placeId}", [CaseStudyController::class, "removePlace"])
            ->whereNumber("id")->whereNumber("placeId")->name("cases.places.remove");
        Route::post("/cases/{id}/geocode", [CaseStudyController::class, "geocode"])
            ->whereNumber("id")->name("cases.geocode");
        Route::post("/cases/{id}/publish", [CaseStudyController::class, "publish"])
            ->whereNumber("id")->name("cases.publish");
        Route::post("/cases/{id}/unpublish", [CaseStudyController::class, "unpublish"])
            ->whereNumber("id")->name("cases.unpublish");

        // ── The resource centre ───────────────────────────────────────
        //
        // Everything the AI is told, and everything that says whether it is
        // getting it right. Grouped rather than scattered because the parts
        // only make sense together: a rule is worth writing when the
        // corrections say the same mistake keeps happening, and a playbook
        // edit is worth keeping when the bench says it scored better after.
        Route::get("/brain", [BrainController::class, "index"])->name("brain.index");
        Route::get("/brain/prompt", [BrainController::class, "prompt"])->name("brain.prompt");

        // the AI panel: which model does which job, who helps, what each is told (4 Sep 2026)
        Route::get("/brain/ai", [\App\Http\Controllers\Admin\AiPanelController::class, "index"])->name("brain.ai");
        Route::post("/brain/ai/provider/{key}", [\App\Http\Controllers\Admin\AiPanelController::class, "saveProvider"])->name("brain.ai.provider");
        Route::post("/brain/ai/provider/{key}/test", [\App\Http\Controllers\Admin\AiPanelController::class, "testProvider"])->name("brain.ai.test");
        Route::post("/brain/ai/provider/{key}/credit", [\App\Http\Controllers\Admin\AiPanelController::class, "saveCredit"])->name("brain.ai.credit");
        Route::post("/brain/ai/task/{key}", [\App\Http\Controllers\Admin\AiPanelController::class, "saveTask"])->name("brain.ai.task");
        Route::post("/brain/ai/prompt/{task}/{provider}", [\App\Http\Controllers\Admin\AiPanelController::class, "savePrompt"])->name("brain.ai.prompt");

        Route::get("/brain/constraints", [BrainController::class, "constraints"])->name("brain.constraints");
        Route::get("/brain/daily", [BrainController::class, "daily"])->name("brain.daily");
        Route::get("/brain/spend", [BrainController::class, "spend"])->name("brain.spend");
        Route::post("/brain/spend/refresh", [BrainController::class, "refreshBalance"])->name("brain.spend.refresh");

        Route::get("/brain/playbook", [BrainController::class, "playbook"])->name("brain.playbook");
        Route::put("/brain/playbook/{key}", [BrainController::class, "savePlaybook"])->name("brain.playbook.save");
        Route::delete("/brain/playbook/{key}/country", [BrainController::class, "revertPlaybook"])->name("brain.playbook.revert");
        Route::post("/brain/playbook/{key}/toggle", [BrainController::class, "togglePlaybook"])->name("brain.playbook.toggle");

        Route::get("/brain/briefing", [BrainController::class, "briefing"])->name("brain.briefing");
        Route::post("/brain/briefing", [BrainController::class, "addTerm"])->name("brain.briefing.add");
        Route::put("/brain/briefing/{id}", [BrainController::class, "updateTerm"])
            ->whereNumber("id")->name("brain.briefing.update");
        Route::delete("/brain/briefing/{id}", [BrainController::class, "deleteTerm"])
            ->whereNumber("id")->name("brain.briefing.delete");

        Route::get("/brain/bench", [BrainController::class, "bench"])->name("brain.bench");
        Route::post("/brain/bench/confirm", [BrainController::class, "confirm"])->name("brain.confirm");
        Route::post("/brain/bench/accept", [BrainController::class, "acceptProposal"])->name("brain.bench.accept");
        Route::post("/brain/bench/reject", [BrainController::class, "rejectProposal"])->name("brain.bench.reject");
        Route::put("/brain/bench/{id}", [BrainController::class, "updateBenchItem"])
            ->whereNumber("id")->name("brain.bench.update");
        Route::delete("/brain/bench/{id}", [BrainController::class, "removeBenchItem"])
            ->whereNumber("id")->name("brain.bench.remove");

        Route::get("/brain/taxonomy", [\App\Http\Controllers\Admin\TaxonomyController::class, "index"])->name("brain.taxonomy");
        Route::get("/community", [\App\Http\Controllers\Admin\CommunityAdminController::class, "index"])->name("community.index");
        Route::get("/community/{id}", [\App\Http\Controllers\Admin\CommunityAdminController::class, "show"])->whereNumber("id")->name("community.show");
        Route::get("/community/desk/appeals", [\App\Http\Controllers\Admin\CommunityAdminController::class, "appeals"])->name("community.appeals");
        Route::post("/community/appeals/{appeal}/{decision}", [\App\Http\Controllers\Admin\CommunityAdminController::class, "resolveAppeal"])->whereNumber("appeal")->where("decision", "uphold|deny")->name("community.appeal.resolve");
        Route::post("/community/corrections/{correction}/{decision}", [\App\Http\Controllers\Admin\CommunityAdminController::class, "resolveCorrection"])->whereNumber("correction")->where("decision", "accept|reject")->name("community.correction.resolve");
        Route::post("/community/comments/{comment}/{decision}", [\App\Http\Controllers\Admin\CommunityAdminController::class, "moderateComment"])->whereNumber("comment")->where("decision", "hide|remove|restore")->name("community.comment.moderate");
        Route::post("/community/moderators/{user}/{decision}", [\App\Http\Controllers\Admin\CommunityAdminController::class, "moderator"])->whereNumber("user")->where("decision", "assign|remove")->name("community.moderator");
        Route::post("/community/moderator-actions/{action}/reverse", [\App\Http\Controllers\Admin\CommunityAdminController::class, "reverseModeratorAction"])->whereNumber("action")->name("community.moderator.reverse");
        Route::post("/community/{id}/{action}", [\App\Http\Controllers\Admin\CommunityAdminController::class, "act"])->whereNumber("id")->name("community.act");
        Route::get("/brain/live", [BrainController::class, "live"])->name("brain.live");
        Route::get("/brain/live/{date}", [BrainController::class, "liveDay"])
            ->where("date", "\\d{4}-\\d{2}-\\d{2}")->name("brain.live.day");
        Route::get("/brain/corrections", [BrainController::class, "corrections"])->name("brain.corrections");
        Route::get("/brain/translations", [BrainController::class, "translations"])->name("brain.translations");

        // Places no map could identify, held for a person to settle.
        Route::get("/places", [PlaceReviewController::class, "index"])->name("places.index");
        Route::post("/places/{id}/resolve", [PlaceReviewController::class, "resolve"])
            ->whereNumber("id")->name("places.resolve");
        Route::post("/places/{id}/dismiss", [PlaceReviewController::class, "dismiss"])
            ->whereNumber("id")->name("places.dismiss");
        Route::post("/brain/corrections", [BrainController::class, "correct"])->name("brain.correct");

        // Editorial policy, in the newsroom's own words.
        Route::get("/rules", [RuleController::class, "index"])->name("rules.index");
        Route::post("/rules", [RuleController::class, "store"])->name("rules.store");
        Route::post("/rules/seed", [RuleController::class, "seed"])->name("rules.seed");
        Route::put("/rules/{id}", [RuleController::class, "update"])
            ->whereNumber("id")->name("rules.update");
        Route::delete("/rules/{id}", [RuleController::class, "destroy"])
            ->whereNumber("id")->name("rules.destroy");

        // Where the news comes from, and what we know about reading it.
        // Country, then publisher, then that publisher's section feeds.
        Route::get("/sources", [SourceController::class, "countries"])->name("sources.countries");
        Route::post("/sources/country", [SourceController::class, "addCountry"])->name("sources.countries.add");
        Route::get("/sources/list", [SourceController::class, "index"])->name("sources.index");
        Route::post("/sources/publishers", [SourceController::class, "addPublisher"])->name("sources.publishers.add");

        Route::get("/sources/failing", [SourceController::class, "failing"])->name("sources.failing");
        Route::get("/sources/blocked", [SourceController::class, "blocked"])->name("sources.blocked");

        // Where the news is: countries, a country's states by day, a state's sources.
        Route::get("/countries", [CountriesReportController::class, "index"])->name("countries.index");
        Route::get("/countries/{iso3}", [CountriesReportController::class, "country"])
            ->where("iso3", "[A-Za-z]{3}")->name("countries.country");
        Route::get("/countries/{iso3}/{state}", [CountriesReportController::class, "state"])
            ->where("iso3", "[A-Za-z]{3}")->name("countries.state");
        Route::get("/countries/{iso3}/{state}/{city}", [CountriesReportController::class, "state"])
            ->where("iso3", "[A-Za-z]{3}")->name("countries.city");
        Route::post("/sources/blocked", [SourceController::class, "block"])->name("sources.block");
        Route::delete("/sources/blocked/{id}", [SourceController::class, "unblock"])
            ->whereNumber("id")->name("sources.unblock");

        Route::get("/sources/{id}", [SourceController::class, "show"])
            ->whereNumber("id")->name("sources.show");
        Route::put("/sources/{id}", [SourceController::class, "update"])
            ->whereNumber("id")->name("sources.update");
        Route::post("/sources/{id}/test", [SourceController::class, "test"])
            ->whereNumber("id")->name("sources.test");
        Route::post("/sources/{id}/sections", [SourceController::class, "addSection"])
            ->whereNumber("id")->name("sources.sections.add");
        Route::delete("/sources/{id}", [SourceController::class, "destroy"])
            ->whereNumber("id")->name("sources.destroy");
    });
});
