<?php

declare(strict_types=1);

namespace BibleGet\Api\Handlers;

use BibleGet\Api\Http\Enum\RequestMethod;
use BibleGet\Api\Http\Enum\StatusCode;
use BibleGet\Api\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves a self-hosted Swagger UI sandbox at /v3/docs.
 *
 * Loading the UI from the same origin as the API avoids every CSP
 * and CORS restriction that blocks external Swagger tools (issue #39).
 *
 * The page fetches the OpenAPI spec and the live bibleversions list in
 * parallel at runtime, then patches spec.servers and all version parameter
 * defaults before initialising SwaggerUIBundle — so Try-it-out works
 * correctly in every deployment environment without any hardcoded values.
 */
class DocsHandler extends AbstractHandler
{
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
        $this->allowedRequestMethods = [RequestMethod::GET, RequestMethod::OPTIONS];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(StatusCode::OK->value, [], null, $request->getProtocolVersion(), StatusCode::OK->reason());
            return $this->handlePreflightRequest($request, $response);
        }

        $this->validateRequestMethod($request);

        // Derive the spec URL from the deployment base path so it works on
        // any prefix (e.g. /v3/ in production, /api/v3/ elsewhere).
        // json_encode() escapes the value safely for a JS string literal.
        $specUrl = json_encode(rtrim(Router::$apiBase, '/') . '/openapi.json', JSON_UNESCAPED_SLASHES);

        // Per-request nonce eliminates the need for 'unsafe-inline' in script-src/style-src.
        $nonce = base64_encode(random_bytes(16));

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8"/>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>BibleGet API — Interactive Docs</title>
          <link rel="icon" href="data:,">
          <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
          <style nonce="{$nonce}">
            body { margin: 0; }
            #swagger-ui .topbar { background-color: #1a1a2e; }
            #swagger-ui .topbar .download-url-wrapper { display: none; }
            #spec-load-error { padding: 2rem; color: #c00; font-family: sans-serif; }
          </style>
        </head>
        <body>
          <div id="swagger-ui"></div>
          <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
          <script nonce="{$nonce}">
            window.onload = async function () {
              const specPath = {$specUrl};
              const apiBase  = specPath.replace('/openapi.json', '');

              try {
                // Fetch the spec and the available versions list in parallel.
                const [spec, versionsData] = await Promise.all([
                  fetch(specPath).then((r) => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                  }),
                  fetch(window.location.origin + apiBase + '/metadata/bibleversions?return=json')
                    .then((r) => r.json())
                    .catch(() => null),
                ]);

                // Replace the spec's hard-coded production server with the
                // current runtime origin so the Servers dropdown and all
                // Try-it-out requests target this server.
                spec.servers = [{ url: window.location.origin + apiBase }];

                // Patch every `version` parameter default to the first version
                // actually available on this server, so Try-it-out works out of
                // the box in every environment without hardcoding a version name.
                const firstVersion = versionsData?.validversions?.[0] ?? null;
                if (firstVersion) {
                  for (const pathItem of Object.values(spec.paths ?? {})) {
                    for (const operation of Object.values(pathItem)) {
                      for (const param of (operation.parameters ?? [])) {
                        if (!param.schema) continue;
                        // Patch singular `version` param (string).
                        if (param.name === 'version') {
                          param.schema.default = firstVersion;
                          param.schema.example = firstVersion;
                        }
                        // Patch plural `versions` param (array — e.g. versionindex).
                        if (param.name === 'versions') {
                          param.schema.default = [firstVersion];
                          param.schema.example = [firstVersion];
                        }
                      }
                    }
                  }
                }

                SwaggerUIBundle({
                  spec: spec,
                  dom_id: '#swagger-ui',
                  presets: [SwaggerUIBundle.presets.apis],
                  layout: 'BaseLayout',
                  deepLinking: true,
                  tryItOutEnabled: true,
                });
              } catch (err) {
                document.getElementById('swagger-ui').innerHTML =
                  '<p id="spec-load-error">Failed to load API specification: ' +
                  err.message + '. Please reload or try again later.</p>';
              }
            };
          </script>
        </body>
        </html>
        HTML;

        // CSP that allows Swagger UI assets from unpkg and same-origin API calls.
        // worker-src blob: is required by swagger-ui-bundle's web worker.
        // Per-request nonce replaces 'unsafe-inline' for both script-src and style-src.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://unpkg.com 'nonce-{$nonce}'",
            "style-src 'self' https://unpkg.com 'nonce-{$nonce}'",
            "img-src 'self' data: https://unpkg.com",
            'worker-src blob:',
            "connect-src 'self' https://unpkg.com",
        ]);

        $response = new Response(
            StatusCode::OK->value,
            [
                'Content-Type'            => 'text/html; charset=utf-8',
                'Content-Security-Policy' => $csp,
                'X-Content-Type-Options'  => 'nosniff',
                // No-store because the page bootstraps with live runtime data
                // (bibleversions list and origin-relative server URL).
                'Cache-Control'           => 'no-store',
            ],
            null,
            $request->getProtocolVersion(),
            StatusCode::OK->reason()
        );

        return $response->withBody(Stream::create($html));
    }
}
