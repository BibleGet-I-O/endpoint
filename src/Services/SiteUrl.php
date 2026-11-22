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
 * or an IP address that is not globally reachable (private, loopback, link-local, CGNAT, reserved). Nothing is
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
        return in_array($tld, self::INTERNAL_TLDS, true) || str_ends_with($host, '.home.arpa');
    }

    private static function isPublicIp(string $ip): bool
    {
        // An IPv4 address mapped into IPv6 (::ffff:10.0.0.1) is judged as the IPv4 address.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        // Shared address space (CGNAT, RFC 6598), not public whatever the filter says.
        return !self::inIpv4Range($ip, '100.64.0.0', 10);
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
