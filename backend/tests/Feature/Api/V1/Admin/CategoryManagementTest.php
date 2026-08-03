<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Category CRUD, and the tree invariants that make unlimited nesting safe.
 */
final class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
    }

    private function asSuperAdmin(): self
    {
        $token = $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    #[Test]
    public function an_administrator_can_create_a_category(): void
    {
        $response = $this->asSuperAdmin()->postJson('/api/v1/admin/categories', [
            'name' => 'Outerwear',
            'description' => 'Coats and jackets.',
            'status' => ProductStatus::Published->value,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Outerwear')
            ->assertJsonPath('data.slug', 'outerwear');

        $this->assertDatabaseHas('categories', ['slug' => 'outerwear', 'depth' => 0]);
    }

    #[Test]
    public function a_slug_is_generated_uniquely_when_names_collide(): void
    {
        Category::factory()->create(['name' => 'Shoes', 'slug' => 'shoes']);

        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/categories', ['name' => 'Shoes'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'shoes-2');
    }

    #[Test]
    public function a_category_can_be_nested_to_arbitrary_depth(): void
    {
        $root = Category::factory()->create(['name' => 'Level 0']);

        $previous = $root;

        // Five levels deep. The materialised path must stay correct throughout,
        // since every subtree query depends on it.
        for ($level = 1; $level <= 5; $level++) {
            $response = $this->asSuperAdmin()->postJson('/api/v1/admin/categories', [
                'name' => "Level {$level}",
                'parent_id' => $previous->id,
            ]);

            $response->assertCreated()->assertJsonPath('data.depth', $level);

            $previous = Category::query()->find($response->json('data.id'));
        }

        $this->assertSame(5, $previous->depth);

        // The deepest node's path must contain every ancestor, which is what
        // makes a breadcrumb one query rather than five.
        $this->assertCount(5, $previous->ancestorIds());
        $this->assertContains($root->id, $previous->ancestorIds());
    }

    #[Test]
    public function a_category_cannot_be_moved_beneath_its_own_descendant(): void
    {
        $parent = Category::factory()->create(['name' => 'Parent']);
        $child = Category::factory()->childOf($parent)->create(['name' => 'Child']);

        // Would make the tree infinite: every traversal from either node would
        // loop forever.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/categories/{$parent->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($parent->refresh()->parent_id);
    }

    #[Test]
    public function a_category_cannot_be_its_own_parent(): void
    {
        $category = Category::factory()->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    #[Test]
    public function moving_a_category_rewrites_its_descendants_paths(): void
    {
        $oldParent = Category::factory()->create(['name' => 'Old Parent']);
        $newParent = Category::factory()->create(['name' => 'New Parent']);

        $moved = Category::factory()->childOf($oldParent)->create(['name' => 'Moved']);
        $grandchild = Category::factory()->childOf($moved)->create(['name' => 'Grandchild']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/categories/{$moved->id}", ['parent_id' => $newParent->id])
            ->assertOk();

        // The subtree must follow the moved node. If the grandchild's path
        // still pointed at the old parent, "products in New Parent including
        // subcategories" would silently omit it.
        $grandchild->refresh();

        $this->assertSame(2, $grandchild->depth);
        $this->assertStringContainsString("/{$newParent->id}/", (string) $grandchild->path);
        $this->assertStringNotContainsString("/{$oldParent->id}/", (string) $grandchild->path);
    }

    #[Test]
    public function a_non_empty_category_is_not_deleted_without_confirmation(): void
    {
        $category = Category::factory()->create();
        Category::factory()->childOf($category)->create();

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    #[Test]
    public function deleting_with_cascade_rehomes_children_and_keeps_products(): void
    {
        $root = Category::factory()->create();
        $doomed = Category::factory()->childOf($root)->create();
        $orphan = Category::factory()->childOf($doomed)->create();

        $product = Product::factory()->create(['category_id' => $doomed->id]);

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/categories/{$doomed->id}?cascade=1")
            ->assertOk();

        // The child is lifted a level rather than destroyed: deleting a
        // mid-level category is a restructure, not a decision to discard the
        // subtree.
        $this->assertSame($root->id, $orphan->refresh()->parent_id);

        // The product survives, uncategorised. It is saleable inventory with
        // order history — a taxonomy edit must not withdraw it from sale.
        $this->assertNotNull($product->refresh());
        $this->assertNull($product->category_id);
        $this->assertSoftDeleted('categories', ['id' => $doomed->id]);
    }

    #[Test]
    public function the_tree_endpoint_returns_nested_children(): void
    {
        $parent = Category::factory()->create(['name' => 'Parent']);
        Category::factory()->childOf($parent)->create(['name' => 'Child']);

        $response = $this->asSuperAdmin()->getJson('/api/v1/admin/categories?tree=1');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Parent')
            ->assertJsonPath('data.0.children.0.name', 'Child');
    }

    #[Test]
    public function an_administrator_without_permission_cannot_create_a_category(): void
    {
        $staff = Admin::factory()->withRole(RoleType::SupportStaff)->create();
        $token = $staff->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/categories', ['name' => 'Forbidden'])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['name' => 'Forbidden']);
    }

    #[Test]
    public function categories_can_be_reordered_in_one_request(): void
    {
        $first = Category::factory()->create(['sort_order' => 0]);
        $second = Category::factory()->create(['sort_order' => 1]);

        $this->asSuperAdmin()
            ->putJson('/api/v1/admin/categories/reorder', [
                'items' => [
                    ['id' => $first->id, 'sort_order' => 1],
                    ['id' => $second->id, 'sort_order' => 0],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, $first->refresh()->sort_order);
        $this->assertSame(0, $second->refresh()->sort_order);
    }
}
