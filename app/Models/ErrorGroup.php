<?php

namespace App\Models;

use Database\Factories\ErrorGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $fingerprint
 * @property string $message
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $occurrences
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['fingerprint', 'message', 'first_seen_at', 'last_seen_at', 'occurrences'])]
class ErrorGroup extends Model
{
    /** @use HasFactory<ErrorGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Same message and same first stack line always produce the same fingerprint.
     */
    public static function fingerprintFor(string $message, ?string $stack = null): string
    {
        $location = $stack === null ? '' : (strtok($stack, "\n") ?: '');

        return hash('sha256', $message."\n".$location);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
