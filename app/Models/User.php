<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Concerns\BelongsToSchool;
use App\Support\Access\PermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use BelongsToSchool, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'avatar',
        'user_type',
        'status',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'user_type' => UserType::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Super admins are platform accounts with no school.
     */
    public function requiresSchool(): bool
    {
        return $this->user_type !== UserType::SuperAdmin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->user_type === UserType::SuperAdmin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function canAccessPortal(string $portal): bool
    {
        return $this->can(PermissionCatalog::portalPermission($portal));
    }

    /**
     * Portal keys this user can enter in the active school, in menu order.
     *
     * @return list<string>
     */
    public function accessiblePortals(): array
    {
        return array_values(array_filter(
            array_keys(config('access.portals')),
            fn (string $portal) => $this->canAccessPortal($portal),
        ));
    }

    /**
     * @return HasOne<StaffProfile, $this>
     */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /**
     * @return HasOne<StudentProfile, $this>
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * @return HasOne<ParentProfile, $this>
     */
    public function parentProfile(): HasOne
    {
        return $this->hasOne(ParentProfile::class);
    }
}
