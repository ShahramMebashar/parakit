<?php
declare(strict_types=1);

namespace Shah\Parakit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Shah\Parakit\Support\CorrelationId;

class AssignCorrelationId
{
    /**
     * Accept caller-supplied correlation IDs only if they look safe.
     * Restricts to ULID/base64url-ish characters and bounds length, which
     * blocks log-injection via newlines/control characters and prevents
     * unbounded growth in log lines.
     */
    private const VALID_ID = '/^[A-Za-z0-9_-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $client = (string) $request->header('X-Correlation-Id', '');
        $id = preg_match(self::VALID_ID, $client) === 1
            ? $client
            : CorrelationId::generate();

        app()->instance(CorrelationId::CONTEXT_KEY, $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $id);
        return $response;
    }

    /**
     * Reset request-scoped state after the response is sent so that long-running
     * workers (Octane, RoadRunner) cannot leak the correlation id into the
     * next request handled by the same container instance.
     */
    public function terminate(Request $request, Response $response): void
    {
        CorrelationId::reset();
    }
}
