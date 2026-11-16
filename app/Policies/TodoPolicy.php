<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

/** Owner-only: a user may only see and manage their own to-do items. */
class TodoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('todos.use');
    }

    public function view(User $user, Todo $todo): bool
    {
        return $user->can('todos.use') && $todo->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('todos.use');
    }

    public function update(User $user, Todo $todo): bool
    {
        return $user->can('todos.use') && $todo->user_id === $user->id;
    }

    public function delete(User $user, Todo $todo): bool
    {
        return $user->can('todos.use') && $todo->user_id === $user->id;
    }
}
