<?php

declare(strict_types=1);

namespace BibleGet\Tests\Support;

/**
 * Saves, clears and restores environment variables in all three places the
 * code reads them from ($_ENV, $_SERVER and getenv(), see
 * CredentialFactory::env()), so a variable exported by the shell or loaded
 * by the bootstrap cannot leak into a test that expects it unset.
 *
 * Call isolateEnvironment() in setUp() and restoreEnvironment() in tearDown().
 */
trait IsolatesEnvironment
{
    /** @var array<string, array{env: mixed, server: mixed, getenv: string|false}> */
    private array $isolatedEnvironment = [];

    /**
     * @param list<string> $keys variables to save and clear
     */
    protected function isolateEnvironment(array $keys): void
    {
        foreach ($keys as $k) {
            $this->isolatedEnvironment[$k] = [
                'env'    => $_ENV[$k] ?? null,
                'server' => $_SERVER[$k] ?? null,
                'getenv' => getenv($k),
            ];
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
    }

    protected function restoreEnvironment(): void
    {
        foreach ($this->isolatedEnvironment as $k => $saved) {
            if ($saved['env'] === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $saved['env'];
            }
            if ($saved['server'] === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $saved['server'];
            }
            putenv($saved['getenv'] === false ? $k : $k . '=' . $saved['getenv']);
        }
        $this->isolatedEnvironment = [];
    }
}
