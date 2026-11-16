<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Todo;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TodoTest extends TestCase
{
    public function test_user_can_crud_own_todos_and_filter_them(): void
    {
        $u = $this->makeUser(['user']);

        $created = $this->actingAs($u)->postJson('/api/todos', [
            'title' => 'پیگیری نامه',
            'notes' => 'تماس با امور اداری',
            'due_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('todo');

        $this->assertSame('پیگیری نامه', $created['title']);
        $this->assertFalse($created['is_done']);
        $this->assertSame($created['due_date'], now()->addDay()->toDateString());

        $this->actingAs($u)->getJson('/api/todos')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $created['id']);

        $done = Todo::factory()->done()->create(['user_id' => $u->id, 'title' => 'انجام‌شده']);
        Todo::factory()->create(['user_id' => $u->id, 'title' => 'باز']);

        $this->actingAs($u)->getJson('/api/todos?status=open')->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->actingAs($u)->getJson('/api/todos?status=done')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $done->id);
        $this->actingAs($u)->getJson('/api/todos?status=all')->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($u)->putJson('/api/todos/'.$created['id'], [
            'title' => 'پیگیری نامه به‌روز',
            'is_done' => true,
        ])->assertOk()
            ->assertJsonPath('todo.title', 'پیگیری نامه به‌روز')
            ->assertJsonPath('todo.is_done', true);

        $this->actingAs($u)->putJson('/api/todos/'.$created['id'], ['is_done' => false])
            ->assertOk()->assertJsonPath('todo.is_done', false);

        $this->actingAs($u)->deleteJson('/api/todos/'.$created['id'])->assertOk();
        $this->assertDatabaseMissing('todos', ['id' => $created['id']]);
        $this->actingAs($u)->getJson('/api/todos/'.$created['id'])->assertStatus(404);
    }

    public function test_user_cannot_read_update_or_delete_another_users_todo(): void
    {
        $owner = $this->makeUser(['user']);
        $other = $this->makeUser(['user']);
        $todo = Todo::factory()->create(['user_id' => $owner->id, 'title' => 'محرمانه']);

        $this->actingAs($other)->getJson('/api/todos')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($other)->getJson("/api/todos/{$todo->id}")->assertStatus(403);
        $this->actingAs($other)->putJson("/api/todos/{$todo->id}", ['title' => 'هک'])->assertStatus(403);
        $this->actingAs($other)->deleteJson("/api/todos/{$todo->id}")->assertStatus(403);

        $this->assertDatabaseHas('todos', ['id' => $todo->id, 'title' => 'محرمانه']);
        $this->assertSame('محرمانه', $todo->fresh()->title);
        $this->assertNull($todo->fresh()->completed_at);
    }

    public function test_admin_cannot_access_another_users_todo_either(): void
    {
        $owner = $this->makeUser(['user']);
        $admin = $this->makeUser(['admin']);
        $todo = Todo::factory()->create(['user_id' => $owner->id, 'title' => 'شخصی']);

        $this->actingAs($admin)->getJson("/api/todos/{$todo->id}")->assertStatus(403);
        $this->actingAs($admin)->putJson("/api/todos/{$todo->id}", ['is_done' => true])->assertStatus(403);
        $this->actingAs($admin)->deleteJson("/api/todos/{$todo->id}")->assertStatus(403);
    }

    public function test_todos_use_permission_is_required(): void
    {
        $u = $this->makeUser(['senior_security_specialist']);
        $this->assertFalse($u->can('todos.use'));
        $this->actingAs($u)->getJson('/api/todos')->assertStatus(403);
        $this->actingAs($u)->postJson('/api/todos', ['title' => 'x'])->assertStatus(403);
    }

    public function test_todo_validation(): void
    {
        $u = $this->makeUser(['user']);
        $this->actingAs($u)->postJson('/api/todos', ['title' => ''])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/todos', ['title' => str_repeat('ا', 201)])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/todos', ['title' => 'ok', 'due_date' => 'not-a-date'])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/todos', ['title' => 'ok', 'notes' => str_repeat('ن', 2001)])->assertStatus(422);
    }

    public function test_seeder_grants_todos_use_to_existing_user_role_idempotently(): void
    {
        $role = Role::findByName('user', 'web');
        $role->revokePermissionTo('todos.use');
        $this->assertFalse($role->fresh()->hasPermissionTo('todos.use'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $role->refresh();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($role->hasPermissionTo('todos.use'));

        $count = Permission::where('name', 'todos.use')->count();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame($count, Permission::where('name', 'todos.use')->count());
    }
}
