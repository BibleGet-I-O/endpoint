<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Pipeline;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Pipeline\QuoteContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The 30-verse limit on copyrighted editions holds for every caller except a
 * `reader`-scope credential on an edition flagged reader_full_text.
 */
class CopyrightLimitTest extends TestCase
{
    private static function credential(string $scope): Credential
    {
        return new Credential(
            CredentialType::Project,
            '00000000-0000-4000-8000-000000000001',
            $scope,
            $scope === 'reader' ? null : 1000,
            '00000000-0000-4000-8000-000000000002',
            null,
            null,
            $scope === 'reader'
        );
    }

    /**
     * @return array<string, array{?string, bool, bool}>
     */
    public static function decisionTable(): array
    {
        return [
            'no credential, edition not flagged' => [null, false, false],
            'no credential, edition flagged'     => [null, true, false],
            'read, edition not flagged'          => ['read', false, false],
            'read, edition flagged'              => ['read', true, false],
            'reader, edition not flagged'        => ['reader', false, false],
            'reader, edition flagged'            => ['reader', true, true],
        ];
    }

    #[DataProvider('decisionTable')]
    public function testOnlyReaderScopeOnFlaggedEditionLiftsLimit(?string $scope, bool $flagged, bool $lifted): void
    {
        $ctx = new QuoteContext(['query' => 'Genesis1'], null, '', 'GET', $scope === null ? null : self::credential($scope));

        $ctx->COPYRIGHT_VERSIONS        = ['CR'];
        $ctx->READER_FULL_TEXT_VERSIONS = $flagged ? ['CR'] : [];

        self::assertSame($lifted, $ctx->fullTextAllowed('CR'));
        self::assertSame($lifted ? [] : ['CR'], $ctx->copyrightLimitedVersions());
    }

    public function testFlagDoesNotLimitAnEditionWithoutCopyright(): void
    {
        $ctx = new QuoteContext([], null, '', 'GET', self::credential('read'));

        $ctx->COPYRIGHT_VERSIONS        = ['CR'];
        $ctx->READER_FULL_TEXT_VERSIONS = ['FREE'];

        self::assertSame(['CR'], $ctx->copyrightLimitedVersions());
    }

    public function testForceParametersAreNotParameters(): void
    {
        $ctx = new QuoteContext(['forcecopyright' => 'true', 'forceversion' => 'true', 'appid' => 'x', 'domain' => 'y', 'pluginversion' => 'z']);

        foreach (['forcecopyright', 'forceversion', 'appid', 'domain', 'pluginversion'] as $removed) {
            self::assertArrayNotHasKey($removed, QuoteContext::$defaultParameters);
        }
        self::assertSame([], $ctx->copyrightLimitedVersions());
    }
}
