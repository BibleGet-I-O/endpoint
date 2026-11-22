<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Http\Exception\ValidationException;
use BibleGet\Api\Services\SiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An installation's site_url: a public https address, kept as its origin only. Registration counts real
 * installations, so a site on an internal name or address cannot register itself (it asks for a project key).
 * Nothing is looked up: the endpoint never resolves or fetches the URL.
 */
final class SiteUrlTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function publicSites(): array
    {
        return [
            'origin kept'             => ['https://wiki.example.org', 'https://wiki.example.org'],
            'path, query dropped'     => ['https://example.com/w/index.php?title=Main#top', 'https://example.com'],
            'user info dropped'       => ['https://admin:secret@example.com/wiki', 'https://example.com'],
            'port kept'               => ['https://example.com:8443/wiki', 'https://example.com:8443'],
            'host and scheme lowered' => ['HTTPS://Wiki.Example.ORG/', 'https://wiki.example.org'],
            'public IPv4'             => ['https://93.184.215.14/wiki', 'https://93.184.215.14'],
            'public IPv6'             => ['https://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]/', 'https://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'],
            'example.com is public'   => ['https://example.com', 'https://example.com'],
            'mapped public IPv4'      => ['https://[::ffff:93.184.215.14]/', 'https://[::ffff:93.184.215.14]'],
            'mapped public, in hex'   => ['https://[::ffff:5db8:d70e]/', 'https://[::ffff:5db8:d70e]'],
            'mapped 8.8.8.8'          => ['https://[::ffff:8.8.8.8]/', 'https://[::ffff:8.8.8.8]'],
            'IPv6 with a dotted tail' => ['https://[2606:4700::104.16.132.229]/', 'https://[2606:4700::104.16.132.229]'],
        ];
    }

    #[DataProvider('publicSites')]
    public function testAPublicHttpsSiteIsKeptAsItsOrigin(string $url, string $origin): void
    {
        self::assertSame($origin, SiteUrl::origin($url));
    }

    /** @return array<string, array{string}> */
    public static function internalSites(): array
    {
        return [
            'localhost'             => ['https://localhost/wiki'],
            'single label'          => ['https://wiki/'],
            '.local'                => ['https://wiki.local'],
            '.localdomain'          => ['https://host.localdomain'],
            '.internal'             => ['https://wiki.corp.internal'],
            '.intranet'             => ['https://wiki.intranet'],
            '.lan'                  => ['https://nas.lan'],
            '.home'                 => ['https://wiki.home'],
            '.corp'                 => ['https://wiki.corp'],
            '.private'              => ['https://wiki.private'],
            '.test'                 => ['https://wiki.test'],
            '.example'              => ['https://wiki.example'],
            '.invalid'              => ['https://wiki.invalid'],
            '.localhost'            => ['https://wiki.localhost'],
            'home.arpa'             => ['https://nas.home.arpa'],
            'trailing dot'          => ['https://wiki.local./'],
            '10/8'                  => ['https://10.1.2.3'],
            '172.16/12'             => ['https://172.20.0.5'],
            '192.168/16'            => ['https://192.168.1.10'],
            'loopback'              => ['https://127.0.0.1:8080'],
            'link-local'            => ['https://169.254.10.10'],
            'CGNAT'                 => ['https://100.64.0.1'],
            'this network'          => ['https://0.0.0.0'],
            'IPv4 reserved'         => ['https://240.0.0.1'],
            'IPv6 loopback'         => ['https://[::1]/'],
            'IPv6 unique local'     => ['https://[fd00::1]/'],
            'IPv6 link-local'       => ['https://[fe80::1]/'],
            'IPv4-mapped private'   => ['https://[::ffff:192.168.1.1]/'],
            'mapped private, hex'   => ['https://[::ffff:c0a8:101]/'],
            'home.arpa itself'      => ['https://home.arpa'],
            'IPv4 multicast'        => ['https://224.0.0.1'],
            'IPv4 multicast 239'    => ['https://239.1.1.1'],
            'IPv6 multicast'        => ['https://[ff02::1]/'],
            'IPv6 site-local'       => ['https://[fec0::1]/'],
            'IPv4-compatible'       => ['https://[::5db8:d70e]/'],
            'NAT64 private'         => ['https://[64:ff9b::a00:1]/'],
            'NAT64 loopback'        => ['https://[64:ff9b::127.0.0.1]/'],
            'NAT64 public'          => ['https://[64:ff9b::808:808]/'],
            'compatible private'    => ['https://[::10.0.0.1]/'],
            'mapped loopback'       => ['https://[::ffff:7f00:1]/'],
            'mapped private'        => ['https://[::ffff:192.168.1.10]/'],
            'IPv6 documentation'    => ['https://[3fff::1]/'],
            'documentation, dotted' => ['https://[3fff::192.0.2.1]/'],
            'IPv6 doc 2001:db8'     => ['https://[2001:db8::1]/'],
            '6to4 private'          => ['https://[2002:c0a8:101::1]/'],
            '6to4 public'           => ['https://[2002:808:808::1]/'],
            'numeric host'          => ['https://2130706433/'],
            'short dotted host'     => ['https://127.1/'],
            'hex host'              => ['https://0x7f000001/'],
            'hex last label'        => ['https://wiki.0x7f/'],
        ];
    }

    #[DataProvider('internalSites')]
    public function testASiteOnAnInternalNameOrAddressCannotRegister(string $url): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/internal.*project key/s');
        SiteUrl::origin($url);
    }

    /** @return array<string, array{mixed}> */
    public static function malformed(): array
    {
        return [
            'http'          => ['http://wiki.example.org'],
            'not a url'     => ['not a url'],
            'ftp'           => ['ftp://example.com'],
            'no host'       => ['https:///wiki'],
            'too long'      => ['https://example.com/' . str_repeat('a', 240)],
            'control chars' => ["https://example.com/\x01"],
            'not a string'  => [42],
            'empty label'   => ['https://wiki..example.org/'],
        ];
    }

    #[DataProvider('malformed')]
    public function testOnlyAnHttpsUrlOfAtMost255CharactersIsAccepted(mixed $url): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('site_url must be an https URL of at most 255 characters.');
        SiteUrl::origin($url);
    }
}
