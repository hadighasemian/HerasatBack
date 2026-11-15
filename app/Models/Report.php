<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = ['title', 'description', 'category', 'contact'];

    protected $hidden = ['tracking_hash', 'contact'];

    protected function casts(): array
    {
        return [
            'contact' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    public static function hashTrackingCode(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return hash('sha256', $normalized);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReportAttachment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ReportStatusHistory::class)->orderBy('id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
