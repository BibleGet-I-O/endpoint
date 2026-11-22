<?php

declare(strict_types=1);

namespace BibleGet\Api\Services;

use BibleGet\Api\Http\Exception\ValidationException;

/**
 * An installation's site_url (POST /installations, PATCH /installations/self): the site's public https address,
 * kept as its origin (scheme://host[:port]; path, query, fragment and user info are dropped).
 *
 * Registration exists to count real installations and their requests, so a site on an internal name or address
 * cannot register itself: it asks the BibleGet website for a project key instead. Internal means a single-label
 * name (localhost, wiki), a name under a TLD that is not public (.local, .lan, .internal, .test, home.arpa, ...),
 * or an IP address that is not globally reachable (private, loopback, link-local, CGNAT, multicast, reserved, or
 * IPv6 outside global unicast 2000::/3; a mapped ::ffff: address is judged as its IPv4 address). Nothing is
 * resolved or fetched: the address is self-reported, and the endpoint never contacts it.
 */
final class SiteUrl
{
    public const MAX_LENGTH = 255;

    /** TLDs that name no public site (RFC 6761, RFC 6762, RFC 8375 and common private-network conventions). */
    private const INTERNAL_TLDS = [
        'localhost',
        'local',
        'localdomain',
        'internal',
        'intranet',
        'lan',
        'home',
        'corp',
        'private',
        'test',
        'example',
        'invalid',
    ];

    private const MALFORMED = 'site_url must be an https URL of at most 255 characters.';

    /** The origin of a public https site; a ValidationException (422) otherwise. */
    public static function origin(mixed $url): string
    {
        if (
            !is_string($url) || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new ValidationException(self::MALFORMED);
        }
        $parts  = parse_url($url);
        $scheme = strtolower((string) ( $parts['scheme'] ?? '' ));
        $host   = strtolower((string) ( $parts['host'] ?? '' ));
        if ($scheme !== 'https' || $host === '') {
            throw new ValidationException(self::MALFORMED);
        }
        if (self::isInternal($host)) {
            throw new ValidationException(
                "site_url must be the site's public address: {$host} is an internal name or address. A site on an internal "
                . 'address cannot register itself; ask for a project key on the BibleGet website instead.'
            );
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return 'https://' . $host . $port;
    }

    /** A host no public site has: see the class comment. */
    public static function isInternal(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return !self::isPublicIp(substr($host, 1, -1));
        }
        $host = rtrim($host, '.');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return !self::isPublicIp($host);
        }
        $labels = explode('.', $host);
        $tld    = end($labels);
        // A single label, an empty one (a..b); a numeric last label (no TLD is numeric or hexadecimal: 2130706433, 127.1, 0x7f000001 are addresses in disguise).
        if (count($labels) < 2 || in_array('', $labels, true) || ctype_digit($tld) || preg_match('/^0x[0-9a-f]+$/', $tld) === 1) {
            return true;
        }
        return in_array($tld, self::INTERNAL_TLDS, true) || $host === 'home.arpa' || str_ends_with($host, '.home.arpa');
    }

    private static function isPublicIp(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // An IPv4 address mapped into IPv6 (::ffff:0:0/96), however it is spelled, is judged as the IPv4 address.
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                return self::isPublicIpv4((string) inet_ntop(substr($packed, 12)));
            }
            // Only global unicast (2000::/3) is public: this leaves out multicast, site-local, IPv4-compatible and
            // NAT64 addresses, which the filter lets through, whatever IPv4 address they carry. The documentation
            // prefix 3fff::/20 (RFC 9637) lies inside 2000::/3 and is unknown to the filter; 6to4 (2002::/16, deprecated
            // by RFC 7526) is refused whatever IPv4 address it carries, rather than left to the filter's patch level.
            return ( ord($packed[0]) & 0xe0 ) === 0x20
                && !( $packed[0] === "\x3f" && $packed[1] === "\xff" && ( ord($packed[2]) & 0xf0 ) === 0 )
                && !str_starts_with($packed, "\x20\x02")
                && filter_var((string) inet_ntop($packed), FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        }
        return self::isPublicIpv4($ip);
    }

    private static function isPublicIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        // Shared address space (CGNAT, RFC 6598) and multicast (224/4), not public whatever the filter says.
        return !self::inIpv4Range($ip, '100.64.0.0', 10) && !self::inIpv4Range($ip, '224.0.0.0', 4);
    }

    private static function inIpv4Range(string $ip, string $base, int $bits): bool
    {
        $a = ip2long($ip);
        $b = ip2long($base);
        if ($a === false || $b === false) {
            return false;
        }
        $mask = -1 << ( 32 - $bits );
        return ( $a & $mask ) === ( $b & $mask );
    }
}
