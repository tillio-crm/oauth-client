<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TillioCrm\OAuth\Client\TillioResourceOwner;

#[CoversClass(TillioResourceOwner::class)]
final class TillioResourceOwnerTest extends TestCase
{
    private const array SAMPLE = [
        'id'         => 3,
        'public_id'  => 'lukg0r',
        'tillio_id'  => 400,
        'first_name' => 'Łukasz',
        'last_name'  => 'Gorący',
        'email'      => 'l.goracy@muscode.pl',
        'avatar_url' => 'https://example.com/avatar.png',
    ];

    public function test_getters_expose_mapped_fields(): void
    {
        $owner = new TillioResourceOwner(self::SAMPLE);

        self::assertSame('3', $owner->getId());
        self::assertSame('lukg0r', $owner->getPublicId());
        self::assertSame('400', $owner->getTillioId());
        self::assertSame('Łukasz', $owner->getFirstName());
        self::assertSame('Gorący', $owner->getLastName());
        self::assertSame('Łukasz Gorący', $owner->getName());
        self::assertSame('l.goracy@muscode.pl', $owner->getEmail());
        self::assertSame('https://example.com/avatar.png', $owner->getAvatarUrl());
    }

    public function test_to_array_returns_raw_response(): void
    {
        $owner = new TillioResourceOwner(self::SAMPLE);

        self::assertSame(self::SAMPLE, $owner->toArray());
    }

    public function test_get_workspace_returns_section_when_present(): void
    {
        $owner = new TillioResourceOwner(self::SAMPLE + [
            'workspace' => ['id' => 4, 'slug' => 'lukgor', 'name' => 'Tillio'],
        ]);

        self::assertSame(['id' => 4, 'slug' => 'lukgor', 'name' => 'Tillio'], $owner->getWorkspace());
    }

    public function test_get_workspace_is_null_without_workspace_scope(): void
    {
        $owner = new TillioResourceOwner(self::SAMPLE);

        self::assertNull($owner->getWorkspace());
    }

    public function test_all_getters_return_null_for_empty_response(): void
    {
        $owner = new TillioResourceOwner([]);

        self::assertNull($owner->getId());
        self::assertNull($owner->getPublicId());
        self::assertNull($owner->getTillioId());
        self::assertNull($owner->getFirstName());
        self::assertNull($owner->getLastName());
        self::assertNull($owner->getName());
        self::assertNull($owner->getEmail());
        self::assertNull($owner->getAvatarUrl());
        self::assertSame([], $owner->toArray());
    }

    public function test_get_name_handles_missing_last_name(): void
    {
        $owner = new TillioResourceOwner(['first_name' => 'Łukasz']);

        self::assertSame('Łukasz', $owner->getName());
    }

    public function test_get_name_handles_missing_first_name(): void
    {
        $owner = new TillioResourceOwner(['last_name' => 'Gorący']);

        self::assertSame('Gorący', $owner->getName());
    }
}
