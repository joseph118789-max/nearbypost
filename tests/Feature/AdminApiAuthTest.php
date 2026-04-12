<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdminApiAuthTest extends TestCase
{
    public function test_admin_news_requires_auth(): void
    {
        $res = $this->getJson('/api/admin/news');
        $res->assertStatus(401);
        $res->assertJson(['success' => false, 'message' => 'Unauthenticated. Admin access required.']);
    }

    public function test_admin_news_with_valid_session(): void
    {
        $admin = Admin::where('email', 'admin@nearbypost.com')->firstOrFail();

        $res = $this->actingAs($admin, 'admin')
            ->getJson('/api/admin/news');

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_admin_news_create_requires_auth(): void
    {
        $res = $this->postJson('/api/admin/news', [
            'title' => 'Test',
            'url' => 'https://example.com/test',
            'primary_category' => 'business',
        ]);
        $res->assertStatus(401);
    }

    public function test_admin_news_create_with_auth(): void
    {
        $admin = Admin::where('email', 'admin@nearbypost.com')->firstOrFail();

        $res = $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/news', [
                'title' => 'Test Article',
                'url' => 'https://example.com/test-' . time(),
                'primary_category' => 'business',
            ]);

        $res->assertStatus(201);
        $res->assertJson(['success' => true]);
    }

    public function test_admin_news_update_with_auth(): void
    {
        $admin = Admin::where('email', 'admin@nearbypost.com')->firstOrFail();

        // Update existing item 156
        $res = $this->actingAs($admin, 'admin')
            ->putJson('/api/admin/news/156', [
                'title' => 'Updated Title',
                'primary_category' => 'sports',
            ]);

        $res->assertStatus(200);
        $res->assertJson(['success' => true]);
    }

    public function test_admin_news_delete_with_auth(): void
    {
        $admin = Admin::where('email', 'admin@nearbypost.com')->firstOrFail();

        // Create then delete
        $create = $this->actingAs($admin, 'admin')
            ->postJson('/api/admin/news', [
                'title' => 'To be deleted',
                'url' => 'https://example.com/to-delete-' . time(),
                'primary_category' => 'business',
            ]);

        $id = $create->json('data.id');

        $res = $this->actingAs($admin, 'admin')
            ->deleteJson("/api/admin/news/{$id}");

        $res->assertStatus(200);
        $res->assertJson(['success' => true, 'message' => 'Deleted']);
    }
}
