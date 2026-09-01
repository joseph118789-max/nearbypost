<?php

use App\Http\Controllers\Admin\BrainController;
use App\Http\Controllers\Admin\CaseStudyController;
use App\Http\Controllers\Admin\ContributionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntelController;
use App\Http\Controllers\Admin\NewsController;
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

        Route::get("/intel/analytics", [IntelController::class, "analytics"])->name("intel.analytics");

        // Reader submissions waiting for a person to read them.
        Route::get("/contributions", [ContributionController::class, "index"])->name("contributions.index");
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

        Route::get("/brain/constraints", [BrainController::class, "constraints"])->name("brain.constraints");
        Route::get("/brain/daily", [BrainController::class, "daily"])->name("brain.daily");
        Route::get("/brain/spend", [BrainController::class, "spend"])->name("brain.spend");
        Route::post("/brain/spend/refresh", [BrainController::class, "refreshBalance"])->name("brain.spend.refresh");

        Route::get("/brain/playbook", [BrainController::class, "playbook"])->name("brain.playbook");
        Route::put("/brain/playbook/{key}", [BrainController::class, "savePlaybook"])->name("brain.playbook.save");
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

        Route::get("/brain/corrections", [BrainController::class, "corrections"])->name("brain.corrections");
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
