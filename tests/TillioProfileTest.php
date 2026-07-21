<?php

declare(strict_types=1);

namespace TillioCrm\OAuth\Client\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TillioCrm\OAuth\Client\TillioProfile;

#[CoversClass(TillioProfile::class)]
final class TillioProfileTest extends TestCase
{
    /** Pełny profil ze wszystkimi scope'ami. */
    private const array FULL = [
        'tillio_id'               => 401,
        'first_name'              => 'Łukasz',
        'last_name'               => 'Gorący',
        'post'                    => 'Co-founder',
        'avatar_url'              => 'https://example.com/a.png',
        'settings'                => ['lang' => 'pl', 'timezone' => 'Europe/Warsaw'],
        'email'                   => 'lukasz.goracy@fatal.pl',
        'workspace'               => ['id' => 4, 'slug' => 'lukgor', 'name' => 'Tillio'],
        'acl'                     => ['_special.superadmin' => 1, 'dms.admin' => 1],
        'is_workspace_superadmin' => true,
        'role_ids'                => [1, 400],
        'profile_contact'         => ['phone' => '+48693080288', 'email' => 'l.goracy@tillio.pl'],
        'organization'            => ['name' => 'Tillio Sp. z o. o.', 'tax_id' => '7812016555'],
    ];

    public function test_exposes_all_sections_when_present(): void
    {
        $profile = new TillioProfile(self::FULL);

        self::assertSame('401', $profile->getTillioId());
        self::assertSame('Łukasz', $profile->getFirstName());
        self::assertSame('Gorący', $profile->getLastName());
        self::assertSame('Łukasz Gorący', $profile->getName());
        self::assertSame('lukasz.goracy@fatal.pl', $profile->getEmail());
        self::assertSame('Co-founder', $profile->getPost());
        self::assertSame('https://example.com/a.png', $profile->getAvatarUrl());
        self::assertSame(['lang' => 'pl', 'timezone' => 'Europe/Warsaw'], $profile->getSettings());
        self::assertSame(['id' => 4, 'slug' => 'lukgor', 'name' => 'Tillio'], $profile->getWorkspace());
        self::assertSame(['_special.superadmin' => 1, 'dms.admin' => 1], $profile->getAcl());
        self::assertTrue($profile->isWorkspaceSuperAdmin());
        self::assertSame([1, 400], $profile->getRoleIds());
        self::assertSame(['phone' => '+48693080288', 'email' => 'l.goracy@tillio.pl'], $profile->getContact());
        self::assertSame(['name' => 'Tillio Sp. z o. o.', 'tax_id' => '7812016555'], $profile->getOrganization());
    }

    public function test_gated_sections_default_when_scope_missing(): void
    {
        // Odpowiedź z samym `tillio_id` (żaden scope danych nie przyznany).
        $profile = new TillioProfile(['tillio_id' => 401]);

        self::assertSame('401', $profile->getTillioId());
        self::assertNull($profile->getFirstName());
        self::assertNull($profile->getEmail());
        self::assertSame([], $profile->getSettings());
        self::assertNull($profile->getWorkspace());
        self::assertSame([], $profile->getAcl());
        self::assertFalse($profile->isWorkspaceSuperAdmin());
        self::assertSame([], $profile->getRoleIds());
        self::assertNull($profile->getContact());
        self::assertNull($profile->getOrganization());
    }

    public function test_role_ids_are_cast_to_ints(): void
    {
        $profile = new TillioProfile(['role_ids' => ['1', '400']]);

        self::assertSame([1, 400], $profile->getRoleIds());
    }

    public function test_to_array_returns_raw_response(): void
    {
        $profile = new TillioProfile(self::FULL);

        self::assertSame(self::FULL, $profile->toArray());
    }
}
