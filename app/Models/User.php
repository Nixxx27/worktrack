<?php

namespace App\Models;

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Users are NEVER deleted (AUTH-D27) — status transitions instead, so audit trails
 * and historical attribution stay intact.
 *
 * This model is deliberately NOT tracker-scoped. Scoping the user directory would
 * break the admin approval queue (FR-1.5), which must see every pending signup.
 * Instead, user LISTING is restricted: the only unscoped listing endpoint is the
 * admin user-management screen, and every picker (assignee, watcher, @mention) is
 * served by a tracker-scoped endpoint returning members only (AUTHZ-19).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
            'break_glass_locked_until' => 'datetime',
            'break_glass_last_used_at' => 'datetime',
            'break_glass_rotation_required' => 'boolean',
            'is_break_glass' => 'boolean',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'auth_provider' => AuthProvider::class,
        ];
    }

    /**
     * Trackers this user has been invited to.
     *
     * Read fresh on every request with no cache — FR-2.4 requires member removal to
     * take effect immediately, and a cache here silently downgrades that to eventual.
     */
    public function trackerMemberships(): HasMany
    {
        return $this->hasMany(TrackerMember::class);
    }

    public function trackers(): BelongsToMany
    {
        return $this->belongsToMany(Tracker::class, 'tracker_members')
            ->withPivot('added_at');
    }

    /**
     * The single most-called authorization predicate in the system.
     *
     * Read from the user row the auth guard already loaded, never from the session
     * or a cache — that is what makes FR-1.7's "suspended users are denied on their
     * very next request" true rather than aspirational.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && $this->role === UserRole::Admin;
    }

    public function isMemberOf(Tracker|int $tracker): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;   // admins see every tracker (D9)
        }

        $trackerId = $tracker instanceof Tracker ? $tracker->id : $tracker;

        return $this->trackerMemberships()->where('tracker_id', $trackerId)->exists();
    }
}
