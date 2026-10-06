<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property string|null $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'key_prefix', 'key_hash'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    private const PREFIX = 'apm_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a key for the project. The plain text key is only available here,
     * since the database keeps just its hash.
     *
     * @return array{0: self, 1: string}
     */
    public static function generate(Project $project, ?string $name = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        return [self::fromPlain($project, $plain, $name), $plain];
    }

    /**
     * Register a key whose text is already known, such as the fixed public key of a demo.
     */
    public static function fromPlain(Project $project, string $plain, ?string $name = null): self
    {
        return $project->apiKeys()->create([
            'name' => $name,
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => self::hash($plain),
        ]);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Find the active key matching a plain text key.
     */
    public static function findActive(string $plain): ?self
    {
        return self::query()
            ->active()
            ->where('key_hash', self::hash($plain))
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
