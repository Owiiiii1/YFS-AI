<?php

namespace App\Services\Voice\Identity;

use Illuminate\Http\Request;

final class ElevenLabsTrustedCallContext
{
    public function __construct(
        public readonly string $callerId,
        public readonly string $conversationId,
        public readonly bool $onBehalfOf,
    ) {}

    /**
     * Session identifiers come only from ElevenLabs system dynamic variables.
     * Top-level `phone` / `caller_id` are ignored even if the model sends them.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            callerId: self::firstString($request, [
                'system__caller_id',
                'dynamic_variables.system__caller_id',
            ]),
            conversationId: self::firstString($request, [
                'system__conversation_id',
                'dynamic_variables.system__conversation_id',
            ]),
            onBehalfOf: self::asBool($request->input('on_behalf_of', $request->input('arguments.on_behalf_of'))),
        );
    }

    /**
     * @param  list<string>  $paths
     */
    private static function firstString(Request $request, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $request->input($path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }
}
