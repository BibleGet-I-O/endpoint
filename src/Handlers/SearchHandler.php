<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backward-compatible alias for KeywordSearchHandler.
 *
 * Retained so that existing `/v3/search` routes and references continue to work.
 * All logic lives in KeywordSearchHandler; this class simply delegates.
 */
class SearchHandler extends AbstractHandler
{
    private KeywordSearchHandler $delegate;

    /**
     * @param string[] $requestPathParams
     */
    public function __construct(ResponseFactoryInterface $responseFactory, array $requestPathParams = [])
    {
        parent::__construct($responseFactory, $requestPathParams);
        $this->delegate = new KeywordSearchHandler($responseFactory, $requestPathParams);
    }

    /**
     * Reset cached data (for testing only).
     */
    public static function resetCache(): void
    {
        KeywordSearchHandler::resetCache();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->delegate->handle($request);
    }
}
