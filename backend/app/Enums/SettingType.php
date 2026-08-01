<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Storage type for a dynamic setting value.
 *
 * Settings are stored as text in a single column; this enum drives the cast
 * applied on read and the validation rule applied on write, so `is_dark_mode`
 * comes back as a real bool and `theme_colors` as a real array.
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Json = 'json';
    case Color = 'color';
    case Image = 'image';
    case File = 'file';
    case Url = 'url';
    case Email = 'email';

    /**
     * Cast a raw database string into its PHP representation.
     */
    public function cast(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Integer => (int) $value,
            self::Float => (float) $value,
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            self::Json => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * Serialise a PHP value into its storable string form.
     */
    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => $value ? '1' : '0',
            self::Json => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    /**
     * Validation rules applied when this setting is written from the admin panel.
     *
     * @return array<int, string>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::String => ['nullable', 'string', 'max:255'],
            self::Text => ['nullable', 'string', 'max:65535'],
            self::Integer => ['nullable', 'integer'],
            self::Float => ['nullable', 'numeric'],
            self::Boolean => ['nullable', 'boolean'],
            self::Json => ['nullable', 'array'],
            self::Color => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            self::Image, self::File => ['nullable', 'string', 'max:2048'],
            self::Url => ['nullable', 'url', 'max:2048'],
            self::Email => ['nullable', 'email', 'max:255'],
        };
    }

    /**
     * Whether the stored value is a path into the public disk and must be
     * expanded to an absolute URL before it leaves the API.
     */
    public function isFileReference(): bool
    {
        return $this === self::Image || $this === self::File;
    }

    public function label(): string
    {
        return match ($this) {
            self::String => 'Short text',
            self::Text => 'Long text',
            self::Integer => 'Whole number',
            self::Float => 'Decimal number',
            self::Boolean => 'Toggle',
            self::Json => 'Structured data',
            self::Color => 'Colour',
            self::Image => 'Image',
            self::File => 'File',
            self::Url => 'URL',
            self::Email => 'Email address',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
