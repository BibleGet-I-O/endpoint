<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Services\CopyrightPolicy;
use PHPUnit\Framework\TestCase;

final class CopyrightPolicyTest extends TestCase
{
    private static function credential(string $scope): Credential
    {
        return new Credential(CredentialType::Project, 'k1', $scope, null, 'a1', null, null, true);
    }

    private static function policy(): CopyrightPolicy
    {
        // NABRE copyrighted and read whole by the reader scope; CEI2008 copyrighted, never whole; DRB public domain.
        return new CopyrightPolicy(['NABRE', 'CEI2008'], ['NABRE', 'DRB']);
    }

    public function testPublicDomainIsNeverCapped(): void
    {
        foreach ([null, self::credential('read'), self::credential('reader')] as $credential) {
            self::assertFalse(self::policy()->isCapped('DRB', $credential));
        }
    }

    public function testCopyrightedIsCappedWithoutACredential(): void
    {
        self::assertTrue(self::policy()->isCapped('NABRE', null));
    }

    public function testReadScopeIsCapped(): void
    {
        self::assertTrue(self::policy()->isCapped('NABRE', self::credential('read')));
    }

    public function testReaderScopeReadsAFullTextEditionWhole(): void
    {
        self::assertFalse(self::policy()->isCapped('NABRE', self::credential('reader')));
    }

    public function testReaderScopeIsCappedWhereFullTextIsOff(): void
    {
        self::assertTrue(self::policy()->isCapped('CEI2008', self::credential('reader')));
    }

    public function testTheCapIsThirtyVerses(): void
    {
        self::assertSame(30, CopyrightPolicy::VERSE_CAP);
    }
}
