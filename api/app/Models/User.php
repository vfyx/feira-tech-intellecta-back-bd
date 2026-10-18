<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    public const ROLE_STUDENT = 'aluno';
    public const ROLE_MODERATOR = 'moderador';
    public const ROLE_TEACHER = 'professor';
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name', 'email', 'username', 'password', 'role', 'curso',
        'ano_escolar', 'banner_url', 'verificado_em',
    ];

    protected $hidden = [
        'password',
        'token_lembrete',
        'chave_recuperacao_hash',
        'chave_adicional_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verificado_em' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getRememberTokenName(): string
    {
        return 'token_lembrete';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isModerator(): bool
    {
        return in_array($this->role, [self::ROLE_MODERATOR, self::ROLE_ADMIN], true);
    }

    public function isVerifiedInstitutional(): bool
    {
        return $this->role === self::ROLE_ADMIN
            || ($this->role === self::ROLE_TEACHER && $this->verificado_em !== null);
    }
}
