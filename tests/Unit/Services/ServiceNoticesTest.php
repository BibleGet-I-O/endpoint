<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Auth\Credential;
use BibleGet\Api\Auth\CredentialType;
use BibleGet\Api\Services\ServiceNotices;
use PHPUnit\Framework\TestCase;

final class ServiceNoticesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(string $id, array $extra = []): array
    {
        return $extra + [
            'id'                     => $id,
            'message'                => "Notice $id",
            'link'                   => null,
            'published_at'           => '2026-11-22 10:00:00+00',
            'deprecation_at'         => null,
            'sunset_at'              => null,
            'target_plugin'          => null,
            'target_below_version'   => null,
            'target_credential_type' => null,
        ];
    }

    private static function project(): Credential
    {
        return new Credential(CredentialType::Project, 'k1', 'read', null, 'a1', null, null, false);
    }

    private static function installation(string $plugin): Credential
    {
        return new Credential(CredentialType::Installation, 'i1', 'read', null, null, 'i1', $plugin, false);
    }

    /** @param list<array<string, mixed>> $notices */
    private static function ids(array $notices): array
    {
        return array_column($notices, 'id');
    }

    public function testAnUntargetedNoticeGoesToEveryone(): void
    {
        foreach ([null, self::project(), self::installation('mediawiki')] as $c) {
            self::assertSame(['all'], self::ids(ServiceNotices::applicable([self::row('all')], $c, null)));
        }
    }

    public function testACredentialTypeTargetsOnlyThatType(): void
    {
        $rows = [self::row('plugins', ['target_credential_type' => 'installation'])];
        self::assertSame([], ServiceNotices::applicable($rows, self::project(), null));
        self::assertSame([], ServiceNotices::applicable($rows, null, null));
        self::assertSame(['plugins'], self::ids(ServiceNotices::applicable($rows, self::installation('wordpress'), null)));
    }

    public function testAPluginTargetsOnlyItsInstallations(): void
    {
        $rows = [self::row('mw', ['target_plugin' => 'mediawiki'])];
        self::assertSame(['mw'], self::ids(ServiceNotices::applicable($rows, self::installation('mediawiki'), '2.0.0')));
        self::assertSame([], ServiceNotices::applicable($rows, self::installation('wordpress'), '9.0.0'));
        self::assertSame([], ServiceNotices::applicable($rows, self::project(), null));
    }

    public function testBelowAVersionTargetsOnlyOlderInstallations(): void
    {
        $rows = [self::row('upgrade', ['target_plugin' => 'mediawiki', 'target_below_version' => '2.1.0'])];
        self::assertSame(['upgrade'], self::ids(ServiceNotices::applicable($rows, self::installation('mediawiki'), '2.0.9')));
        self::assertSame([], ServiceNotices::applicable($rows, self::installation('mediawiki'), '2.1.0'));
        self::assertSame([], ServiceNotices::applicable($rows, self::installation('mediawiki'), '3.0.0'));
        // An installation that never said its version is warned, to be safe.
        self::assertSame(['upgrade'], self::ids(ServiceNotices::applicable($rows, self::installation('mediawiki'), null)));
    }

    public function testThePublicShapeHasStableFieldsOnly(): void
    {
        $row      = self::row('v3-sunset', [
            'link'           => 'https://www.bibleget.io/news/v3-sunset',
            'deprecation_at' => '2027-01-01 00:00:00+00',
            'sunset_at'      => '2027-06-30 00:00:00+00',
        ]);
        $out      = ServiceNotices::applicable([$row], null, null);
        $expected = [
            'id'          => 'v3-sunset',
            'message'     => 'Notice v3-sunset',
            'link'        => 'https://www.bibleget.io/news/v3-sunset',
            'published'   => '2026-11-22',
            'deprecation' => '2027-01-01T00:00:00+00:00',
            'sunset'      => '2027-06-30T00:00:00+00:00',
        ];
        self::assertSame([$expected], $out);
    }

    public function testHeadersGiveTheEarliestDeprecationAndSunsetWithTheirLinks(): void
    {
        $notices = ServiceNotices::applicable([
            self::row('a', ['link' => 'https://www.bibleget.io/a', 'deprecation_at' => '2027-03-01 00:00:00+00']),
            self::row('b', ['link' => 'https://www.bibleget.io/b', 'deprecation_at' => '2027-01-01 00:00:00+00', 'sunset_at' => '2027-06-30 00:00:00+00']),
        ], null, null);
        self::assertSame([
            'Deprecation' => ['@' . strtotime('2027-01-01 00:00:00 UTC')],
            'Sunset'      => ['Wed, 30 Jun 2027 00:00:00 GMT'],
            'Link'        => ['<https://www.bibleget.io/b>; rel="deprecation"; type="text/html"', '<https://www.bibleget.io/b>; rel="sunset"; type="text/html"'],
        ], ServiceNotices::headers($notices));
    }

    public function testNoDatesNoHeaders(): void
    {
        self::assertSame([], ServiceNotices::headers(ServiceNotices::applicable([self::row('plain')], null, null)));
    }
}
