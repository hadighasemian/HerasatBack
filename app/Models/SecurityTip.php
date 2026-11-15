<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityTip extends Model
{
    protected $fillable = ['title', 'body', 'icon', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
