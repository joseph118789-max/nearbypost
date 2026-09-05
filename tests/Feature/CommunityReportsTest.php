<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Community\CommunityTrust;
use App\Services\Community\FactGuard;
use App\Services\Community\Usernames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Community Reports: the rules the guide asks to be proven. These run on
 * sqlite in memory without the AI (the moderation provider is never called
 * here); the end-to-end run with the model is /tmp/community_test.php.
 */
class CommunityReportsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $over = []): User
    {
        $u = User::create(array_merge(['name' => 'Reader', 'email' => uniqid('r') . '@example.test', 'password' => bcrypt('Password123!'), 'username' => Usernames::suggest('Reader ' . uniqid())], $over));

        // created_at and credibility are not mass-assignable; the weight rule reads them
        $direct = array_intersect_key($over, ['created_at' => 1, 'credibility' => 1]);

        if ($direct !== []) {
            DB::table('users')->where('id', $u->id)->update($direct);
        }

        return $u->fresh();
    }

    private function report(User $u, array $over = []): int
    {
        $id = DB::table('news_items')->insertGetId(array_merge([
            'title' => 'Factory fire reported in Kepong', 'body' => 'Smoke seen', 'source' => $u->name, 'url' => 'draft:' . uniqid(), 'published_at' => now(),
            'status' => 'active', 'origin' => 'user', 'contributor_id' => $u->id, 'review_status' => 'published', 'ai_status' => 'success', 'section' => 'nearme',
            'latitude' => 3.21, 'longitude' => 101.63, 'created_at' => now(), 'updated_at' => now(),
        ], $over));
        DB::table('community_post_meta')->insert(['news_item_id' => $id, 'original_title' => 'kepong factory burning now', 'original_body' => 'Smoke seen',
            'gps_lat_private' => 3.2101, 'gps_lng_private' => 101.6301, 'selected_lat' => 3.21, 'selected_lng' => 101.63, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_posting_requires_login(): void
    {
        $this->post('/contribute', ['title' => 'x'])->assertRedirect();
        $this->assertDatabaseCount('news_items', 0);
    }

    public function test_registration_makes_a_username(): void
    {
        $this->post('/contributor/register', ['name' => 'Ah Seng Tester', 'email' => 'seng@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!']);
        $this->assertSame('ah_seng_tester', User::where('email', 'seng@example.test')->value('username'));
        $this->assertSame('ah_seng_tester2', Usernames::suggest('Ah Seng Tester'));
        $this->assertTrue(Usernames::isReserved('admin'));
    }

    public function test_private_gps_never_reaches_the_page(): void
    {
        $u = $this->user();
        $id = $this->report($u);
        $html = $this->get('/post/' . $id)->assertOk()->getContent();
        $this->assertStringNotContainsString('3.2101', $html);
        $this->assertStringContainsString('Community Report', $html);
        $this->assertStringContainsString('@' . $u->username, $html);
    }

    public function test_one_factual_confirmation_per_user_and_status_flips_at_threshold(): void
    {
        $author = $this->user();
        $id = $this->report($author);
        $trusted = $this->user(['trusted_at' => now(), 'credibility' => 85]);
        $other = $this->user(['created_at' => now()->subDays(30)]);

        $this->actingAs($trusted, 'web')->post("/community/{$id}/reactions", ['type' => 'saw']);
        $this->actingAs($trusted, 'web')->post("/community/{$id}/reactions", ['type' => 'saw']);   // second press withdraws
        $this->assertSame(0, DB::table('community_reactions')->count());
        $this->actingAs($trusted, 'web')->post("/community/{$id}/reactions", ['type' => 'saw']);
        $this->actingAs($other, 'web')->post("/community/{$id}/reactions", ['type' => 'saw']);
        $this->assertSame(1, DB::table('community_reactions')->where('user_id', $trusted->id)->count());

        $meta = DB::table('community_post_meta')->where('news_item_id', $id)->first();
        $this->assertEquals(2.5, (float) $meta->saw_weight, json_encode(DB::table('community_reactions')->get(['user_id', 'weight'])->all()) . ' trusted=' . $trusted->id . ' other=' . $other->id . ' other_created=' . $other->created_at . ' trusted_at=' . $trusted->trusted_at);   // 1.5 + 1.0
        $this->assertSame('unverified', $meta->trust_status);

        $third = $this->user(['created_at' => now()->subDays(30)]);
        $this->actingAs($third, 'web')->post("/community/{$id}/reactions", ['type' => 'saw']);
        $this->assertSame('confirmed', DB::table('community_post_meta')->where('news_item_id', $id)->value('trust_status'));
        $this->assertSame(3, (int) DB::table('users')->where('id', $author->id)->value('points'));
    }

    public function test_reputation_is_awarded_once(): void
    {
        $u = $this->user();
        $id = $this->report($u);
        $this->assertTrue(CommunityTrust::ledger($u->id, $id, 'published', 1, 0));
        $this->assertFalse(CommunityTrust::ledger($u->id, $id, 'published', 1, 0));
        $this->assertSame(1, (int) DB::table('users')->where('id', $u->id)->value('points'));
        $this->assertSame(50, (int) DB::table('users')->where('id', $u->id)->value('credibility'));
    }

    public function test_weighted_reports_do_not_remove_a_post_by_themselves(): void
    {
        $u = $this->user();
        $id = $this->report($u);

        for ($i = 0; $i < 6; $i++) {
            DB::table('community_reports')->insert(['news_item_id' => $id, 'user_id' => null, 'device_token' => 'd' . $i, 'reason' => 'false', 'weight' => 0.1, 'created_at' => now(), 'updated_at' => now()]);
        }

        CommunityTrust::refresh($id, 'test');
        $this->assertSame('active', DB::table('news_items')->where('id', $id)->value('status'));
        $this->assertSame('unverified', DB::table('community_post_meta')->where('news_item_id', $id)->value('trust_status'));   // 0.6 < 2.0
    }

    public function test_fact_guard_catches_added_claims(): void
    {
        $added = FactGuard::addedClaims('kepong factory burning now', 'big smoke coming out. i saw around 10.30 morning',
            'Massive fire destroys Kepong factory and injures workers', 'A large plume of smoke was seen at 10:30 AM; three workers were injured.');
        $this->assertNotEmpty($added);
        $this->assertContains('injur', array_map(fn ($a) => substr($a, 0, 5), $added));

        $safe = FactGuard::addedClaims('kepong factory burning now', 'big smoke coming out. i saw around 10.30 morning',
            'Factory fire reported in Kepong as large smoke plume is seen', 'A large plume of smoke was seen coming from a factory in Kepong at around 10.30 in the morning.');
        $this->assertSame([], $safe);
    }

    public function test_rejected_or_removed_pages_are_not_public(): void
    {
        $u = $this->user();
        $id = $this->report($u, ['status' => 'held', 'review_status' => 'rejected']);
        $this->get('/post/' . $id)->assertNotFound();
        $xml = $this->get('/sitemap-news.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/post/' . $id, $xml);
    }

    public function test_breaking_report_enters_the_news_sitemap(): void
    {
        $u = $this->user();
        $id = $this->report($u);
        DB::table('community_post_meta')->where('news_item_id', $id)->update(['breaking' => true, 'seo_eligibility' => 'news']);
        $this->assertStringContainsString('/post/' . $id, $this->get('/sitemap-news.xml')->getContent());
    }

    public function test_a_user_cannot_edit_another_authors_post(): void
    {
        $a = $this->user();
        $b = $this->user();
        $id = $this->report($a);
        $this->actingAs($b, 'web')->get("/contribute/{$id}/edit")->assertNotFound();
    }

    public function test_comment_reply_depth_is_one_level(): void
    {
        $a = $this->user();
        $b = $this->user();
        $id = $this->report($a);
        $this->actingAs($b, 'web')->post("/community/{$id}/comments", ['body' => 'first comment']);
        $c1 = DB::table('community_comments')->value('id');
        $this->actingAs($a, 'web')->post("/community/{$id}/comments", ['body' => 'a reply', 'parent_id' => $c1]);
        $c2 = DB::table('community_comments')->orderByDesc('id')->value('id');
        $this->actingAs($b, 'web')->post("/community/{$id}/comments", ['body' => 'reply to the reply', 'parent_id' => $c2]);
        $this->assertSame((int) $c1, (int) DB::table('community_comments')->orderByDesc('id')->value('parent_id'));
    }

    public function test_an_accepted_correction_versions_the_post_once(): void
    {
        $a = $this->user();
        $b = $this->user();
        $id = $this->report($a);
        $this->actingAs($b, 'web')->post("/community/{$id}/corrections", ['field' => 'title', 'proposed_value' => 'Factory fire reported in Kepong, near Jalan Kepong']);
        $cid = DB::table('community_corrections')->value('id');
        $this->actingAs($a, 'web')->post("/community/corrections/{$cid}/accept");
        $this->actingAs($a, 'web')->post("/community/corrections/{$cid}/accept");
        $this->assertSame('corrected', DB::table('community_post_meta')->where('news_item_id', $id)->value('trust_status'));
        $this->assertSame(1, DB::table('community_post_versions')->where('news_item_id', $id)->count());
        $this->assertSame(1, DB::table('community_reputation_events')->where('user_id', $b->id)->count());
    }
}
