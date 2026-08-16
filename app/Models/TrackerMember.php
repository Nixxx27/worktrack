<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The membership pivot — the visibility layer itself.
 *
 * Deliberately NOT tracker-scoped, and this exemption is load-bearing: the scope
 * resolves visibility BY READING THIS TABLE, so scoping it would be circular
 * (AccessContext would need visibility to determine visibility). Access is
 * protected instead by never exposing a listing endpoint for it outside the
 * tracker-scoped member picker and the admin screen.
 *
 * Any future exemption from the scope must be justified in a comment like this one.
 */
class TrackerMember extends Model
{
    protected $table = 'tracker_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['added_at' => 'datetime'];
    }

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
