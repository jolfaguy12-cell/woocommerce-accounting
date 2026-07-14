<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Accounting\Models\Party;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'telegram_id',
        'party_id',
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The person behind the login, if the login belongs to one.
     *
     * A User is an ACCESS record — «سطح دسترسی سیستم», what this account may do
     * in the software. A Party is a BUSINESS identity — «نقش‌های تجاری», who this
     * person is to the company. They are not the same question, so they are not
     * the same table: an admin login that belongs to no employee is normal, and a
     * partner who never logs in is normal too.
     *
     * The link exists so the two can be joined when they DO refer to one person —
     * so the bookkeeper's salary has somewhere to attach.
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
