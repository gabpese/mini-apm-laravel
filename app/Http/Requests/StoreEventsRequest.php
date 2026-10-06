<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventsRequest extends FormRequest
{
    public const MAX_EVENTS_PER_BATCH = 100;

    /**
     * Authentication is handled by the API key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS_PER_BATCH],
            'events.*.type' => ['required', 'string', Rule::in(Event::TYPES)],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.app_version' => ['required', 'string', 'max:32'],
            'events.*.user_ref' => ['nullable', 'string', 'max:255'],
            'events.*.name' => ['nullable', 'string', 'max:255'],
            'events.*.message' => ['nullable', 'string', 'max:2000'],
            'events.*.stack' => ['nullable', 'string', 'max:20000'],
            'events.*.properties' => ['nullable', 'array'],
            'events.*.env' => ['nullable', 'array'],
            'events.*.env.os' => ['nullable', 'string', 'max:255'],
            'events.*.env.ram_mb' => ['nullable', 'integer', 'min:0'],
            'events.*.env.gpu' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A feature event needs a name, and an error or crash needs a message.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                foreach ((array) $this->input('events') as $index => $event) {
                    $type = is_array($event) ? ($event['type'] ?? null) : null;

                    if ($type === Event::TYPE_FEATURE_USED && blank($event['name'] ?? null)) {
                        $validator->errors()->add("events.$index.name", 'The name is required for feature_used events.');
                    }

                    if (in_array($type, [Event::TYPE_ERROR, Event::TYPE_CRASH], true) && blank($event['message'] ?? null)) {
                        $validator->errors()->add("events.$index.message", "The message is required for $type events.");
                    }
                }
            },
        ];
    }
}
