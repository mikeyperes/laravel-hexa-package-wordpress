<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

trait ExecutesWordPressRestRoutes
{
    /**
     * Execute exactly once. Toolkit uses the site's REST router and the saved
     * WordPress actor, including the route's normal permission callback.
     * HTTP status is retained so callers can distinguish absence from failure.
     */
    public function requestRestRoute(array $target, string $method, string $route, array $body = [], array $query = []): array
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! preg_match('#^/?[A-Za-z0-9_-]+/v[0-9]+/[A-Za-z0-9_/%:.-]+$#D', $route)
            || preg_match('~[\\x00-\\x20\\x7f\\\\\\\\?#]|(?:^|/)\\.\\.(?:/|$)~', rawurldecode($route))) {
            return ['success' => false, 'status' => 400, 'message' => 'Invalid WordPress REST request.', 'data' => null];
        }

        $target = $this->normalizeTarget($target);
        if (! $this->usesWpToolkit($target)) {
            if ($this->usesPluginTransport($target)) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'Arbitrary REST routes are not exposed through the HWS Base Tools publishing bridge.',
                    'data' => null,
                ];
            }

            return $this->rest->requestRoute($target['url'], $target['username'], $target['application_password'], $method, $route, $body, $query, 60);
        }

        $actor = $target['username'] ?: $target['default_author'];
        if ($actor === '') {
            return ['success' => false, 'status' => 401, 'message' => 'A saved WordPress actor is required for REST operations.', 'data' => null];
        }
        $input = base64_encode(json_encode([
            'actor' => $actor, 'origin' => $target['url'], 'method' => $method,
            'route' => '/'.ltrim($route, '/'), 'body' => $body, 'query' => $query,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $php = <<<'PHP'
$input = json_decode(base64_decode('__INPUT__'), true);
$finish = static function (int $status, $data): void {
    echo 'HEXA_REST_ROUTE:'.wp_json_encode(['status' => $status, 'data' => $data]);
};
if (!in_array(rtrim($input['origin'], '/'), [rtrim(home_url(), '/'), rtrim(site_url(), '/')], true)) {
    $finish(409, ['code' => 'site_binding_mismatch', 'message' => 'The saved WordPress origin does not match this installation.']);
    return;
}
$actor = ctype_digit((string) $input['actor']) ? get_user_by('id', (int) $input['actor']) : get_user_by('login', $input['actor']);
if (!$actor) {
    $finish(401, ['code' => 'rest_not_logged_in', 'message' => 'The saved WordPress actor was not found.']);
    return;
}
$previousUser = get_current_user_id();
try {
    wp_set_current_user($actor->ID);
    $request = new \WP_REST_Request($input['method'], $input['route']);
    $request->set_query_params($input['query']);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($input['body']));
    $response = rest_do_request($request);
    $finish($response->get_status(), $response->get_data());
} finally {
    wp_set_current_user($previousUser);
}
PHP;
        try {
            $result = $this->evaluatePhp($target, str_replace('__INPUT__', $input, $php));
            // A shutdown failure may follow a completed write. Interpret its one
            // marked receipt; never run a fallback evaluator or replay the write.
            $payload = $this->decodeMarkedPayload((string) ($result['stdout'] ?? ''), 'HEXA_REST_ROUTE:');
        } catch (\Throwable) {
            $payload = null;
        }
        if (! is_array($payload) || ! isset($payload['status']) || ! is_array($payload['data'] ?? null)) {
            return ['success' => false, 'status' => null, 'message' => 'WordPress returned no valid REST receipt; reconcile the operation before retrying.', 'data' => null];
        }
        $status = (int) $payload['status'];

        return [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'message' => (string) ($payload['data']['message'] ?? ($status < 300 ? 'REST request succeeded.' : 'WordPress REST request failed.')),
            'data' => $payload['data'],
        ];
    }
}
