<?php

namespace App\Models;

use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Enums\StepType;
use App\Models\Concerns\BelongsToTracker;
use Database\Factories\StepFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A board column, owned independently per tracker (FR-2.2). */
#[ScopedBy(TrackerVisibilityScope::class)]
class Step extends Model
{
    /** @use HasFactory<StepFactory> */
    use BelongsToTracker, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'archived_at' => 'datetime',
            'requires_due_date' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
