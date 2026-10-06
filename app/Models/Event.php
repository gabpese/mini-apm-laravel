<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $session_id
 * @property int|null $error_group_id
 * @property string $type
 * @property string|null $name
 * @property string $app_version
 * @property string|null $user_ref
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['session_id', 'error_group_id', 'type', 'name', 'app_version', 'user_ref', 'occurred_at', 'payload'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public const TYPE_SESSION_START = 'session_start';

    public const TYPE_FEATURE_USED = 'feature_used';

    public const TYPE_ERROR = 'error';

    public const TYPE_CRASH = 'crash';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_SESSION_START,
        self::TYPE_FEATURE_USED,
        self::TYPE_ERROR,
        self::TYPE_CRASH,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<AppSession, $this>
     */
    public function appSession(): BelongsTo
    {
        return $this->belongsTo(AppSession::class, 'session_id');
    }

    /**
     * @return BelongsTo<ErrorGroup, $this>
     */
    public function errorGroup(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class);
    }
}
