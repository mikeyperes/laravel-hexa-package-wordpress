<?php

namespace hexa_package_wordpress\Services\Concerns\WordPressManager;

use hexa_package_whm\Models\WhmServer;

/** Read-only connection diagnostics: the publication manifest and the safe connection report. */
trait ReportsWordPressConnections
{
    /**
     * The one reader for a site's SMP publication manifest.
     *
     * WP Toolkit sites run the REST route inside WordPress through WP-CLI, so
     * the read never depends on the public CDN path (Cloudflare does not answer
     * uncached requests that originate from the hosting server itself). Other
     * sites use the guarded public HTTPS request.
     *
     * @return array{success: bool, message: string, status: int|null, state: string, data: array|null}
     */
    public function publicationManifest(array $target): array
    {
        $target = $this->normalizeTarget($target);
        if (! $this->usesWpToolkit($target)) {
            return $target['url'] !== ''
                ? $this->rest->discoverPublicationManifest($target['url'])
                : ['success' => false, 'message' => 'No WordPress URL is configured.', 'status' => null, 'state' => 'invalid_url', 'data' => null];
        }

        $result = $this->evaluatePhp($target, '$r = rest_do_request(new WP_REST_Request("GET", "/smpi/v1/publication-manifest"));'
            .' echo "HEXA_MANIFEST_BEGIN", wp_json_encode(["status" => $r->get_status(), "data" => $r->get_data()]), "HEXA_MANIFEST_END";');
        $decoded = preg_match('/HEXA_MANIFEST_BEGIN(.*)HEXA_MANIFEST_END/s', (string) ($result['stdout'] ?? ''), $match) === 1
            ? json_decode($match[1], true)
            : null;
        $status = is_array($decoded) ? (int) ($decoded['status'] ?? 0) : null;
        $data = is_array($decoded['data'] ?? null) && ! array_is_list($decoded['data']) ? $decoded['data'] : null;

        return match (true) {
            $status === 200 && $data !== null => ['success' => true, 'message' => 'SMP publication manifest read through WP-CLI.', 'status' => 200, 'state' => 'available', 'data' => $data],
            $status === 404 => ['success' => false, 'message' => 'SMP Publication Integration manifest was not detected.', 'status' => 404, 'state' => 'not_detected', 'data' => null],
            $status !== null => ['success' => false, 'message' => 'SMP publication manifest returned HTTP '.$status.'.', 'status' => $status, 'state' => 'http_error', 'data' => null],
            default => ['success' => false, 'message' => 'The SMP publication manifest could not be read through WP-CLI.', 'status' => null, 'state' => 'transport_error', 'data' => null],
        };
    }

    /**
     * Build a secret-free, persistable connection and capability report.
     *
     * The report intentionally retains only presence booleans, safe WordPress
     * actor fields, endpoint status, namespaces, and the publication feature
     * contract. It never returns a password, signing secret, auth header, nonce,
     * cookie, or raw remote payload.
     */
    public function connectionReport(array $target, ?callable $onActivity = null): array
    {
        $target = $this->normalizeTarget($target);
        $startedAt = microtime(true);
        $activity = [];
        $emit = function (string $level, string $stage, string $message, array $details = []) use (&$activity, $onActivity, $startedAt): void {
            $event = [
                'sequence' => count($activity) + 1,
                'at' => now()->utc()->toIso8601String(),
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'level' => in_array($level, ['info', 'success', 'warning', 'error'], true) ? $level : 'info',
                'stage' => preg_replace('/[^a-z0-9_.-]+/', '_', strtolower(trim($stage))) ?: 'connection',
                'message' => trim($message),
                'details' => array_filter($details, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
            ];
            $activity[] = $event;
            if (is_callable($onActivity)) {
                $onActivity($event);
            }
        };

        $emit('info', 'connection.start', 'Starting WordPress connection and capability test.', [
            'mode' => $this->usesWpToolkit($target) ? 'wptoolkit' : $target['mode'],
            'site' => $target['url'],
        ]);
        $authentication = $this->testConnection($target);
        $emit(
            ($authentication['success'] ?? false) ? 'success' : 'error',
            'connection.authentication',
            trim((string) ($authentication['message'] ?? 'WordPress authentication did not return a result.')),
        );
        $contract = $this->publicationFeatures($target);
        $mode = $this->usesWpToolkit($target) ? 'wptoolkit' : $target['mode'];
        $modeId = $mode === 'rest' ? 'wp_rest_api' : $mode;
        $restIndex = $target['url'] !== ''
            ? $this->rest->discoverRestIndex($target['url'])
            : ['success' => false, 'message' => 'No WordPress URL is configured.', 'status' => null, 'namespaces' => [], 'route_count' => 0];
        $namespaces = array_values(array_filter((array) ($restIndex['namespaces'] ?? []), 'is_string'));
        $emit(
            ($restIndex['success'] ?? false) ? 'success' : 'warning',
            'discovery.rest_index',
            trim((string) ($restIndex['message'] ?? 'WordPress REST discovery completed.')),
            [
                'status' => $restIndex['status'] ?? null,
                'namespace_count' => count($namespaces),
                'route_count' => (int) ($restIndex['route_count'] ?? 0),
            ],
        );

        $endpointDefinitions = [
            'posts' => ['label' => 'Posts', 'query' => ['context' => 'edit', 'per_page' => 1, '_fields' => 'id']],
            'media' => ['label' => 'Media', 'query' => ['context' => 'edit', 'per_page' => 1, '_fields' => 'id']],
            'categories' => ['label' => 'Categories', 'query' => ['per_page' => 1, '_fields' => 'id']],
            'tags' => ['label' => 'Tags', 'query' => ['per_page' => 1, '_fields' => 'id']],
            'users' => ['label' => 'Users', 'query' => ['context' => 'edit', 'per_page' => 1, '_fields' => 'id']],
            'types' => ['label' => 'Post types', 'query' => ['context' => 'edit', '_fields' => 'slug,rest_base']],
            'taxonomies' => ['label' => 'Taxonomies', 'query' => ['context' => 'edit', '_fields' => 'slug,rest_base']],
        ];
        $endpoints = [];
        $taxonomies = [];

        if (($authentication['success'] ?? false) === true && $mode !== 'wptoolkit') {
            foreach ($endpointDefinitions as $resource => $definition) {
                $result = $this->restRequest($target, 'GET', $resource, query: $definition['query']);
                $endpoints[$resource] = [
                    'label' => $definition['label'],
                    'supported' => (bool) ($result['success'] ?? false),
                    'status' => is_numeric($result['status'] ?? null) ? (int) $result['status'] : null,
                ];
                $emit(
                    ($result['success'] ?? false) ? 'success' : 'warning',
                    'discovery.endpoint.'.$resource,
                    $definition['label'].(($result['success'] ?? false) ? ' endpoint is available.' : ' endpoint is unavailable.'),
                    ['status' => $result['status'] ?? null],
                );
                if ($resource === 'taxonomies' && ($result['success'] ?? false) === true) {
                    $taxonomies = array_values(array_filter(array_map(
                        static fn (mixed $slug): string => is_string($slug)
                            && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $slug) === 1
                                ? $slug
                                : '',
                        array_keys((array) ($result['data'] ?? [])),
                    )));
                    sort($taxonomies);
                }
            }
        } elseif (($authentication['success'] ?? false) === true && $mode === 'wptoolkit') {
            foreach ($endpointDefinitions as $resource => $definition) {
                $endpoints[$resource] = [
                    'label' => $definition['label'],
                    'supported' => true,
                    'status' => null,
                ];
                $emit('success', 'discovery.endpoint.'.$resource, $definition['label'].' is available through the protected WP Toolkit binding.');
            }
        }

        $manifestResult = $this->publicationManifest($target);
        $manifest = is_array($manifestResult['data'] ?? null) ? $manifestResult['data'] : [];
        $plugin = is_array($manifest['plugin'] ?? null) ? $manifest['plugin'] : [];
        $manifestIdentityValid = ($manifestResult['success'] ?? false) === true
            && ($manifest['api_version'] ?? null) === 1
            && ($plugin['slug'] ?? null) === 'smp-publication-integration'
            && ($plugin['namespace'] ?? null) === 'smpi/v1'
            && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', (string) ($plugin['version'] ?? '')) === 1;
        $manifestState = $manifestIdentityValid
            ? 'available'
            : (($manifestResult['success'] ?? false) ? 'invalid' : (string) ($manifestResult['state'] ?? 'not_detected'));
        $emit(
            $manifestIdentityValid ? 'success' : (($manifestState === 'not_detected') ? 'warning' : 'error'),
            'discovery.smp_manifest',
            $manifestIdentityValid
                ? 'SMP Publication Integration manifest detected.'
                : trim((string) ($manifestResult['message'] ?? 'SMP Publication Integration manifest was not detected.')),
            [
                'status' => $manifestResult['status'] ?? null,
                'state' => $manifestState,
                'version' => $manifestIdentityValid ? (string) ($plugin['version'] ?? '') : null,
            ],
        );

        $deliveryCapabilities = $manifestIdentityValid && is_array($manifest['delivery_capabilities'] ?? null)
            ? $manifest['delivery_capabilities']
            : [];
        $manifestTaxonomies = array_values(array_filter((array) data_get($manifest, 'publishing_requirements.taxonomies', []), 'is_string'));
        $manifestArticleType = (string) data_get($manifest, 'schema.article_type_taxonomy', '');
        $rankMathInspection = null;
        if (($authentication['success'] ?? false) === true && $mode === 'wptoolkit') {
            $rankMathInspection = $this->inspectPlugin($target, 'seo-by-rank-math', ['rank-math.php']);
            $rankMathPlugin = is_array($rankMathInspection['plugin'] ?? null)
                ? $rankMathInspection['plugin']
                : [];
            $emit(
                ($rankMathPlugin['active'] ?? false) ? 'success' : 'warning',
                'discovery.rank_math_plugin',
                trim((string) ($rankMathInspection['message'] ?? 'Rank Math plugin inspection completed.')),
                [
                    'found' => ($rankMathPlugin['found'] ?? false) === true,
                    'active' => ($rankMathPlugin['active'] ?? false) === true,
                    'version' => trim((string) ($rankMathPlugin['version'] ?? '')) ?: null,
                ],
            );
        }
        $rankMathAvailable = $this->hasNamespace($namespaces, 'rankmath/')
            || data_get($rankMathInspection, 'plugin.active') === true;
        $yoastAvailable = $this->hasNamespace($namespaces, 'yoast/');
        $acfAvailable = $this->hasNamespace($namespaces, 'acf/');
        $rankMathAvailable = $rankMathAvailable || ($manifestIdentityValid && data_get($manifest, 'seo.provider') === 'rank_math');
        $rankMathSource = data_get($rankMathInspection, 'plugin.active') === true
            ? 'wptoolkit_plugin_inspection'
            : ($this->hasNamespace($namespaces, 'rankmath/')
                ? 'rest_namespace'
                : (($manifestIdentityValid && data_get($manifest, 'seo.provider') === 'rank_math') ? 'smp_manifest' : 'not_detected'));
        $postSummaryAvailable = $manifestIdentityValid && ($deliveryCapabilities['post_summary'] ?? false) === true;
        $faqRepeaterAvailable = $manifestIdentityValid && ($deliveryCapabilities['post_faq_items'] ?? false) === true;
        $articleAudioAvailable = $manifestIdentityValid && ($deliveryCapabilities['article_audio'] ?? false) === true;
        $articleTaxonomyAvailable = $manifestIdentityValid && (
            array_key_exists('smpi_article_type', $deliveryCapabilities)
                ? $deliveryCapabilities['smpi_article_type'] === true
                : ($manifestArticleType === 'smpi_article_type'
                    || in_array('smpi_article_type', $manifestTaxonomies, true))
        );
        $indexKnown = (bool) ($restIndex['success'] ?? false);

        $features = array_map(function (array $feature) use ($postSummaryAvailable, $faqRepeaterAvailable, $articleAudioAvailable, $articleTaxonomyAvailable, $manifestIdentityValid): array {
            if ($feature['key'] === 'post_summary') {
                $feature['support'] = $postSummaryAvailable ? 'supported' : 'unsupported';
                $feature['detail'] = $postSummaryAvailable
                    ? 'SMP manifest confirms the enabled post_summary field.'
                    : ($manifestIdentityValid ? 'SMP manifest reports post_summary disabled or unavailable.' : 'SMP plugin manifest was not detected.');
            } elseif ($feature['key'] === 'faq_repeater') {
                $feature['support'] = $faqRepeaterAvailable ? 'supported' : 'unsupported';
                $feature['detail'] = $faqRepeaterAvailable
                    ? 'SMP manifest confirms the enabled post_faq_items repeater.'
                    : ($manifestIdentityValid ? 'SMP manifest reports post_faq_items disabled or unavailable.' : 'SMP plugin manifest was not detected.');
            } elseif ($feature['key'] === 'article_type') {
                $feature['support'] = $articleTaxonomyAvailable ? 'supported' : 'unsupported';
                $feature['detail'] = $articleTaxonomyAvailable
                    ? 'SMP manifest confirms the smpi_article_type taxonomy.'
                    : ($manifestIdentityValid ? 'SMP manifest reports smpi_article_type disabled or unavailable.' : 'SMP plugin manifest was not detected.');
            } elseif ($feature['key'] === 'article_audio') {
                $feature['support'] = $articleAudioAvailable ? 'supported' : 'unsupported';
                $feature['detail'] = $articleAudioAvailable
                    ? 'SMP manifest confirms article-audio generation.'
                    : ($manifestIdentityValid ? 'SMP manifest reports article audio unavailable.' : 'SMP plugin manifest was not detected.');
            }

            return $feature;
        }, (array) ($contract['features'] ?? []));

        $optional = array_map(function (array $feature) use ($rankMathAvailable, $rankMathSource, $yoastAvailable, $indexKnown): array {
            if ($feature['key'] === 'rank_math_readback') {
                $feature['support'] = $rankMathAvailable ? 'supported' : ($indexKnown ? 'unsupported' : 'conditional');
                $feature['detail'] = $rankMathAvailable
                    ? 'Rank Math detected through '.str_replace('_', ' ', $rankMathSource).'.'
                    : ($yoastAvailable ? 'Yoast is active instead of Rank Math.' : 'Rank Math namespace not detected.');
            }

            return $feature;
        }, (array) ($contract['optional'] ?? []));

        $capabilities = [
            'smp_plugin' => $manifestIdentityValid,
            'post_summary' => $postSummaryAvailable,
            'post_faq_items' => $faqRepeaterAvailable,
            'smpi_article_type' => $articleTaxonomyAvailable,
            'article_audio' => $articleAudioAvailable,
            'rank_math' => $rankMathAvailable,
        ];
        foreach ($capabilities as $key => $available) {
            $emit(
                $available ? 'success' : 'warning',
                'capability.'.$key,
                match ($key) {
                    'smp_plugin' => $available ? 'SMP plugin structure is available.' : 'SMP plugin structure is unavailable.',
                    'post_summary' => $available ? 'Post Summary is enabled.' : 'Post Summary is unsupported.',
                    'post_faq_items' => $available ? 'FAQ repeater is enabled.' : 'FAQ repeater is unsupported.',
                    'smpi_article_type' => $available ? 'SMP Article Types taxonomy is enabled.' : 'SMP Article Types taxonomy is unsupported.',
                    'article_audio' => $available ? 'Article audio generation is enabled.' : 'Article audio generation is unsupported.',
                    'rank_math' => $available ? 'Rank Math is detected.' : 'Rank Math is not detected.',
                },
            );
        }

        $endpointReady = $endpoints !== []
            && collect($endpoints)->every(static fn (array $endpoint): bool => $endpoint['supported'] === true);
        $transportReady = (bool) ($authentication['success'] ?? false);
        $capabilityState = ! $transportReady
            ? 'blocked'
            : ($endpointReady ? 'ready' : 'limited');
        $authData = is_array($authentication['data'] ?? null) ? $authentication['data'] : [];
        $emit(
            $transportReady ? 'success' : 'error',
            'connection.complete',
            $transportReady
                ? 'Connection test completed; the detailed capability report is ready to save.'
                : 'Connection test completed with a transport failure; detected capabilities are ready to save.',
            ['capability_state' => $capabilityState],
        );

        $report = [
            'schema_version' => 2,
            'validated_at' => now()->utc()->toIso8601String(),
            'mode_id' => $modeId,
            'mode_label' => $this->connectionLabel($target),
            'configuration_state' => $this->connectionConfigured($target) ? 'configured' : 'incomplete',
            'transport_state' => $transportReady ? 'ready' : 'blocked',
            'capability_state' => $capabilityState,
            'validation' => [
                'success' => $transportReady,
                'message' => trim((string) ($authentication['message'] ?? '')),
            ],
            'authentication' => [
                'method' => match ($mode) {
                    'wptoolkit' => 'Protected WP Toolkit installation binding',
                    'hws_base_tools' => 'HMAC-signed HWS Base Tools key',
                    default => 'WordPress Application Password',
                },
                'credentials' => match ($mode) {
                    'wptoolkit' => [
                        'server_binding' => $target['server'] instanceof WhmServer,
                        'installation_binding' => ! empty($target['install_id']),
                    ],
                    'hws_base_tools' => [
                        'key_id' => trim($target['hws_key_id']) !== '',
                        'signing_secret' => $target['hws_api_secret'] !== '',
                    ],
                    default => [
                        'username' => trim($target['username']) !== '',
                        'application_password' => $target['application_password'] !== '',
                    ],
                },
                'actor' => array_filter([
                    'configured_username' => trim($target['username']) !== '' ? trim($target['username']) : trim($target['default_author']),
                    'user_id' => isset($authData['user_id']) ? (int) $authData['user_id'] : null,
                    'display_name' => isset($authData['user_name']) ? trim((string) $authData['user_name']) : null,
                    'slug' => isset($authData['user_slug']) ? trim((string) $authData['user_slug']) : null,
                    'roles' => array_values(array_filter((array) ($authData['roles'] ?? []), 'is_string')),
                ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []),
            ],
            'rest' => [
                'index_available' => $indexKnown,
                'index_status' => is_numeric($restIndex['status'] ?? null) ? (int) $restIndex['status'] : null,
                'route_count' => (int) ($restIndex['route_count'] ?? 0),
                'namespaces' => $namespaces,
                'endpoints' => $endpoints,
            ],
            'smp' => [
                'available' => $manifestIdentityValid,
                'state' => $manifestState,
                'status' => is_numeric($manifestResult['status'] ?? null) ? (int) $manifestResult['status'] : null,
                'endpoint' => rtrim($target['url'], '/').'/wp-json/smpi/v1/publication-manifest',
                'plugin_version' => $manifestIdentityValid ? (string) ($plugin['version'] ?? '') : null,
                'api_version' => $manifestIdentityValid ? (int) ($manifest['api_version'] ?? 0) : null,
                'fingerprint' => $manifestIdentityValid && preg_match('/^[a-f0-9]{64}$/', (string) data_get($manifest, 'meta.fingerprint', '')) === 1
                    ? (string) data_get($manifest, 'meta.fingerprint')
                    : null,
                'message' => trim((string) ($manifestResult['message'] ?? '')),
            ],
            'rank_math' => [
                'available' => $rankMathAvailable,
                'source' => $rankMathSource,
                'plugin_version' => data_get($rankMathInspection, 'plugin.version') ?: null,
                'rest_namespace_detected' => $this->hasNamespace($namespaces, 'rankmath/'),
                'smp_manifest_provider' => $manifestIdentityValid
                    ? data_get($manifest, 'seo.provider')
                    : null,
            ],
            'integrations' => [
                'seo_plugin' => $rankMathAvailable ? 'rank_math' : ($yoastAvailable ? 'yoast' : ($indexKnown ? 'none_detected' : 'unknown')),
                'rank_math_available' => $rankMathAvailable,
                'yoast_available' => $yoastAvailable,
                'acf_rest_available' => $indexKnown ? $acfAvailable : null,
                'hws_base_tools_transport' => $mode === 'hws_base_tools',
                'smp_plugin_available' => $manifestIdentityValid,
                'post_summary_available' => $postSummaryAvailable,
                'post_faq_items_available' => $faqRepeaterAvailable,
                'smpi_article_type_available' => $articleTaxonomyAvailable,
                'article_audio_available' => $articleAudioAvailable,
                'custom_taxonomies' => array_values(array_diff($taxonomies, ['category', 'post_tag'])),
            ],
            'capabilities' => $capabilities,
            'features' => $features,
            'optional_features' => $optional,
            'activity_log' => $activity,
        ];

        return $authentication + [
            'connection_report' => $report,
            'publication_features' => [
                'mode' => $contract['mode'] ?? $mode,
                'label' => $contract['label'] ?? $this->connectionLabel($target),
                'features' => $features,
                'optional' => $optional,
            ],
        ];
    }

    /** @param array<int, string> $namespaces */
    private function hasNamespace(array $namespaces, string $prefix): bool
    {
        return collect($namespaces)->contains(
            static fn (string $namespace): bool => str_starts_with(strtolower($namespace), strtolower($prefix)),
        );
    }

    private function connectionConfigured(array $target): bool
    {
        if ($this->usesWpToolkit($target)) {
            return $target['server'] instanceof WhmServer && ! empty($target['install_id']);
        }

        if ($this->usesPluginTransport($target)) {
            return $target['url'] !== '' && $target['hws_key_id'] !== '' && $target['hws_api_secret'] !== '';
        }

        return $target['url'] !== '' && $target['username'] !== '' && $target['application_password'] !== '';
    }
}
