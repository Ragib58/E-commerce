<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('castCases')]
    public function it_casts_stored_strings_to_their_php_type(SettingType $type, ?string $stored, mixed $expected): void
    {
        $this->assertSame($expected, $type->cast($stored));
    }

    /**
     * @return array<string, array{SettingType, string|null, mixed}>
     */
    public static function castCases(): array
    {
        return [
            'integer' => [SettingType::Integer, '42', 42],
            'float' => [SettingType::Float, '9.99', 9.99],
            'boolean true' => [SettingType::Boolean, '1', true],
            'boolean false' => [SettingType::Boolean, '0', false],
            'string' => [SettingType::String, 'hello', 'hello'],
            'colour' => [SettingType::Color, '#2563eb', '#2563eb'],
            'null passes through' => [SettingType::Integer, null, null],
        ];
    }

    #[Test]
    public function json_values_round_trip_without_loss(): void
    {
        $value = ['primary' => '#fff', 'sizes' => [1, 2, 3]];

        $serialized = SettingType::Json->serialize($value);

        $this->assertSame($value, SettingType::Json->cast($serialized));
    }

    #[Test]
    public function booleans_serialize_to_a_storable_scalar(): void
    {
        // Casting `false` with (string) yields "" — which then reads back as
        // null rather than false. This asserts the explicit 1/0 encoding.
        $this->assertSame('0', SettingType::Boolean->serialize(false));
        $this->assertSame('1', SettingType::Boolean->serialize(true));
        $this->assertFalse(SettingType::Boolean->cast('0'));
    }

    #[Test]
    public function only_file_types_are_treated_as_file_references(): void
    {
        $this->assertTrue(SettingType::Image->isFileReference());
        $this->assertTrue(SettingType::File->isFileReference());
        $this->assertFalse(SettingType::String->isFileReference());
        $this->assertFalse(SettingType::Url->isFileReference());
    }

    #[Test]
    public function payment_and_shipping_groups_are_not_publicly_exposable(): void
    {
        $this->assertFalse(SettingGroup::Payment->isPubliclyExposable());
        $this->assertFalse(SettingGroup::Shipping->isPubliclyExposable());
        $this->assertFalse(SettingGroup::Mail->isPubliclyExposable());

        $this->assertTrue(SettingGroup::Branding->isPubliclyExposable());
        $this->assertTrue(SettingGroup::Theme->isPubliclyExposable());
    }
}
