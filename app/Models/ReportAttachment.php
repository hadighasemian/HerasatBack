<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAttachment extends Model
{
    protected $fillable = [];

    protected $hidden = ['stored_path', 'sha256'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
