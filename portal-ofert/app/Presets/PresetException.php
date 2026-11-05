<?php

declare(strict_types=1);

namespace App\Presets;

/** Błędy w treści presetu — komunikaty dla człowieka, bez śladu stosu. */
final class PresetException extends \RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Preset zawiera błędy:\n - " . implode("\n - ", $errors));
    }
}
