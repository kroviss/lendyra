<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'branch_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Human-readable, translatable label for a role identifier. */
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            'admin' => __('Administrator'),
            'manager' => __('Manager'),
            'loan_officer' => __('Loan officer'),
            'cashier' => __('Cashier'),
            'accountant' => __('Accountant'),
            default => ucwords(str_replace('_', ' ', (string) $role)),
        };
    }

    /** Branch the current user is restricted to, or null for full visibility. */
    public function scopedBranchId(): ?int
    {
        if (! config('lms.branch_scoping') || in_array($this->role, ['admin', 'manager'], true)) {
            return null;
        }

        return $this->branch_id;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
