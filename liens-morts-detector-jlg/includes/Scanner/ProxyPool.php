<?php

namespace JLG\BrokenLinks\Scanner {

class ProxyPool
{
    /** @var bool */
    private $enabled;

    /**
     * @var array<int, array{id:string,url:string,regions:array<int,string>,priority:int,headers:array<string,string>}> 
     */
    private $proxies;

    /**
     * @var array<string, array<int,string>>
     */
    private $regionFallbacks;

    /**
     * @var array<int, array{pattern:string,region:string}>
     */
    private $regionMappings;

    /**
     * @var array<string, string>
     */
    private $credentials;

    /**
     * @var array<string, array{failure_count:int,success_count:int,suspended_until:int,last_failure_at:int,last_success_at:int}>
     */
    private $health;

    /**
     * @var array<string,int>
     */
    private $rotationState = [];

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->proxies = $this->normaliseProxies(isset($config['proxies']) && is_array($config['proxies']) ? $config['proxies'] : []);
        $strategy = isset($config['strategy']) && is_array($config['strategy']) ? $config['strategy'] : [];
        $this->regionFallbacks = $this->normaliseFallbacks($strategy['fallbacks'] ?? []);
        $this->regionMappings  = $this->normaliseMappings($strategy['mappings'] ?? []);
        $this->credentials = isset($config['credentials']) && is_array($config['credentials']) ? $config['credentials'] : [];
        $this->health = $this->normaliseHealth(isset($config['health']) && is_array($config['health']) ? $config['health'] : []);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->proxies !== [];
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>|null
     */
    public function acquire(array $context = [])
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $host = isset($context['host']) && is_string($context['host']) ? strtolower($context['host']) : '';
        $region = '';
        if (isset($context['region']) && is_string($context['region'])) {
            $region = \sanitize_key($context['region']);
        }

        if ($region === '' && $host !== '') {
            $region = $this->resolveRegionForHost($host);
        }

        $preferredRegions = $this->determinePreferredRegions($region);
        $now = $this->currentTimestamp();

        foreach ($preferredRegions as $preferredRegion) {
            $candidates = [];
            foreach ($this->proxies as $proxy) {
                if (!$this->proxySupportsRegion($proxy, $preferredRegion)) {
                    continue;
                }

                $state = $this->health[$proxy['id']] ?? null;
                if ($state !== null && $state['suspended_until'] > $now) {
                    continue;
                }

                $candidates[] = $proxy;
            }

            if ($candidates === []) {
                continue;
            }

            $selected = $this->selectCandidate($preferredRegion, $candidates);
            if ($selected === null) {
                continue;
            }

            $credentials = $this->credentials[$selected['id']] ?? null;

            return [
                'id'          => $selected['id'],
                'url'         => $selected['url'],
                'region'      => $preferredRegion,
                'priority'    => $selected['priority'],
                'headers'     => $selected['headers'],
                'credentials' => $credentials,
            ];
        }

        return null;
    }

    /**
     * @param string $proxyId
     * @param bool   $success
     * @param int|null $timestamp
     *
     * @return bool
     */
    public function reportOutcome($proxyId, $success, $timestamp = null)
    {
        $proxyId = (string) $proxyId;
        if ($proxyId === '' || !$this->isEnabled()) {
            return false;
        }

        $state = blc_proxy_pool_register_outcome($proxyId, (bool) $success, $timestamp);
        if (is_array($state)) {
            $this->health[$proxyId] = $this->normaliseHealthEntry($state);
        }

        return true;
    }

    /**
     * @param string $proxyId
     * @param array<string,mixed> $state
     *
     * @return void
     */
    public function synchroniseHealth($proxyId, array $state)
    {
        $proxyId = (string) $proxyId;
        if ($proxyId === '') {
            return;
        }

        $this->health[$proxyId] = $this->normaliseHealthEntry($state);
    }

    /**
     * @return array<string, array{failure_count:int,success_count:int,suspended_until:int,last_failure_at:int,last_success_at:int}>
     */
    public function getHealthSnapshot()
    {
        return $this->health;
    }

    /**
     * @param string $url
     * @param array<string,mixed> $args
     * @param array<string,mixed> $selection
     *
     * @return array<string,mixed>
     */
    public function injectProxyArguments($url, array $args, array $selection)
    {
        if (!$this->isEnabled()) {
            return $args;
        }

        $proxyUrl = (string) ($selection['url'] ?? '');
        if ($proxyUrl === '') {
            return $args;
        }

        $headers = [];
        if (isset($args['headers']) && is_array($args['headers'])) {
            $headers = $args['headers'];
        }

        foreach ($selection['headers'] ?? [] as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $headers[$name] = (string) $value;
        }

        $credentials = isset($selection['credentials']) && is_string($selection['credentials']) ? $selection['credentials'] : '';
        if ($credentials !== '') {
            $headers['Proxy-Authorization'] = 'Basic ' . base64_encode($credentials);
        }

        $args['headers'] = $headers;

        $args = $this->injectCurlProxy($args, $proxyUrl, $credentials);
        $args = $this->injectStreamProxy($args, $proxyUrl, $credentials);

        return $args;
    }

    /**
     * @param bool $refresh
     *
     * @return self
     */
    public static function fromWordPressOptions($refresh = false)
    {
        $config = [
            'enabled'     => blc_get_proxy_pool_enabled_flag(),
            'proxies'     => blc_get_proxy_pool_entries(),
            'credentials' => blc_get_proxy_pool_credentials(true),
            'strategy'    => blc_get_proxy_pool_strategy(),
            'health'      => blc_get_proxy_pool_health_snapshot(),
        ];

        return new self($config);
    }

    /**
     * @param array<int, array<string,mixed>> $raw
     *
     * @return array<int, array{id:string,url:string,regions:array<int,string>,priority:int,headers:array<string,string>}>
     */
    private function normaliseProxies(array $raw)
    {
        $normalised = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = isset($entry['id']) ? \sanitize_key((string) $entry['id']) : '';
            $url = isset($entry['url']) ? \esc_url_raw((string) $entry['url']) : '';
            if ($id === '' || $url === '') {
                continue;
            }

            $regions = [];
            if (isset($entry['regions']) && is_array($entry['regions'])) {
                foreach ($entry['regions'] as $region) {
                    $region = \sanitize_key((string) $region);
                    if ($region === '') {
                        continue;
                    }

                    $regions[$region] = $region;
                }
            }

            $priority = isset($entry['priority']) ? (int) $entry['priority'] : 10;
            if ($priority <= 0) {
                $priority = 10;
            }

            $headers = [];
            if (isset($entry['headers']) && is_array($entry['headers'])) {
                foreach ($entry['headers'] as $name => $value) {
                    if (!is_string($name) || $name === '') {
                        continue;
                    }

                    if (!is_scalar($value)) {
                        continue;
                    }

                    $headers[$name] = (string) $value;
                }
            }

            $normalised[] = [
                'id'       => $id,
                'url'      => $url,
                'regions'  => array_values($regions),
                'priority' => $priority,
                'headers'  => $headers,
            ];
        }

        return $normalised;
    }

    /**
     * @param array<int, array{pattern:string,region:string}> $raw
     *
     * @return array<int, array{pattern:string,region:string}>
     */
    private function normaliseMappings(array $raw)
    {
        $mappings = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $pattern = isset($entry['pattern']) ? strtolower(trim((string) $entry['pattern'])) : '';
            $region  = isset($entry['region']) ? \sanitize_key((string) $entry['region']) : '';
            if ($pattern === '' || $region === '') {
                continue;
            }

            $mappings[] = ['pattern' => $pattern, 'region' => $region];
        }

        return $mappings;
    }

    /**
     * @param array<string, array<int,string>> $raw
     *
     * @return array<string, array<int,string>>
     */
    private function normaliseFallbacks($raw)
    {
        $fallbacks = [];
        if (!is_array($raw)) {
            $raw = [];
        }

        foreach ($raw as $region => $sequence) {
            $regionKey = \sanitize_key((string) $region);
            if ($regionKey === '') {
                continue;
            }

            $sequence = is_array($sequence) ? $sequence : [];
            $normalised = [];
            foreach ($sequence as $item) {
                $item = \sanitize_key((string) $item);
                if ($item === '') {
                    continue;
                }

                $normalised[$item] = $item;
            }

            if (!isset($normalised['global'])) {
                $normalised['global'] = 'global';
            }

            $fallbacks[$regionKey] = array_values($normalised);
        }

        if (!isset($fallbacks['default'])) {
            $fallbacks['default'] = ['global'];
        }

        return $fallbacks;
    }

    /**
     * @param array<string, array<string,mixed>> $raw
     *
     * @return array<string, array{failure_count:int,success_count:int,suspended_until:int,last_failure_at:int,last_success_at:int}>
     */
    private function normaliseHealth(array $raw)
    {
        $health = [];

        foreach ($raw as $proxyId => $state) {
            $proxyId = \sanitize_key((string) $proxyId);
            if ($proxyId === '') {
                continue;
            }

            if (!is_array($state)) {
                $state = [];
            }

            $health[$proxyId] = $this->normaliseHealthEntry($state);
        }

        return $health;
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array{failure_count:int,success_count:int,suspended_until:int,last_failure_at:int,last_success_at:int}
     */
    private function normaliseHealthEntry(array $state)
    {
        return [
            'failure_count'   => isset($state['failure_count']) ? max(0, (int) $state['failure_count']) : 0,
            'success_count'   => isset($state['success_count']) ? max(0, (int) $state['success_count']) : 0,
            'suspended_until' => isset($state['suspended_until']) ? max(0, (int) $state['suspended_until']) : 0,
            'last_failure_at' => isset($state['last_failure_at']) ? max(0, (int) $state['last_failure_at']) : 0,
            'last_success_at' => isset($state['last_success_at']) ? max(0, (int) $state['last_success_at']) : 0,
        ];
    }

    private function resolveRegionForHost($host)
    {
        $host = strtolower($host);
        foreach ($this->regionMappings as $mapping) {
            if ($this->patternMatchesHost($mapping['pattern'], $host)) {
                return $mapping['region'];
            }
        }

        return '';
    }

    /**
     * @return array<int,string>
     */
    private function determinePreferredRegions($region)
    {
        $preferred = [];
        $regionKey = \sanitize_key($region);
        if ($regionKey !== '' && isset($this->regionFallbacks[$regionKey])) {
            $preferred = $this->regionFallbacks[$regionKey];
        } elseif (isset($this->regionFallbacks['default'])) {
            $preferred = $this->regionFallbacks['default'];
        }

        if (!in_array('global', $preferred, true)) {
            $preferred[] = 'global';
        }

        return array_values(array_unique(array_filter($preferred)));
    }

    /**
     * @param array{id:string,url:string,regions:array<int,string>,priority:int,headers:array<string,string>} $proxy
     * @param string $region
     *
     * @return bool
     */
    private function proxySupportsRegion(array $proxy, $region)
    {
        $region = (string) $region;
        if ($region === '' || $region === 'global') {
            return true;
        }

        if ($proxy['regions'] === []) {
            return $region === 'global';
        }

        return in_array($region, $proxy['regions'], true);
    }

    /**
     * @param string $region
     * @param array<int, array{id:string,url:string,regions:array<int,string>,priority:int,headers:array<string,string>}> $candidates
     *
     * @return array{id:string,url:string,regions:array<int,string>,priority:int,headers:array<string,string>}|null
     */
    private function selectCandidate($region, array $candidates)
    {
        if ($candidates === []) {
            return null;
        }

        $maxPriority = null;
        foreach ($candidates as $candidate) {
            $priority = (int) $candidate['priority'];
            if ($maxPriority === null || $priority > $maxPriority) {
                $maxPriority = $priority;
            }
        }

        $shortlist = [];
        foreach ($candidates as $candidate) {
            if ((int) $candidate['priority'] === $maxPriority) {
                $shortlist[] = $candidate;
            }
        }

        usort($shortlist, static function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        $key = $region . ':' . $maxPriority;
        $index = $this->rotationState[$key] ?? 0;
        $selected = $shortlist[$index % count($shortlist)];
        $this->rotationState[$key] = ($index + 1) % count($shortlist);

        return $selected;
    }

    private function currentTimestamp()
    {
        if (function_exists('time')) {
            return (int) time();
        }

        return (int) gmdate('U');
    }

    private function patternMatchesHost($pattern, $host)
    {
        if ($pattern === '*') {
            return true;
        }

        if (strpos($pattern, '*') !== false) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

            return (bool) preg_match($regex, $host);
        }

        if ($pattern !== '' && $pattern[0] === '.') {
            return substr($host, -strlen($pattern)) === $pattern;
        }

        return $host === $pattern;
    }

    /**
     * @param array<string,mixed> $args
     * @param string              $proxyUrl
     * @param string              $credentials
     *
     * @return array<string,mixed>
     */
    private function injectCurlProxy(array $args, $proxyUrl, $credentials)
    {
        if (!defined('CURLOPT_PROXY')) {
            return $args;
        }

        if (!isset($args['curl']) || !is_array($args['curl'])) {
            $args['curl'] = [];
        }

        $args['curl'][CURLOPT_PROXY] = $proxyUrl;

        $scheme = strtolower((string) parse_url($proxyUrl, PHP_URL_SCHEME));
        if ($scheme !== '') {
            if (!isset($args['curl'][CURLOPT_PROXYTYPE])) {
                if ($scheme === 'socks5') {
                    $args['curl'][CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                } elseif ($scheme === 'socks4' || $scheme === 'socks4a') {
                    $args['curl'][CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
                } else {
                    $args['curl'][CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
                }
            }
        }

        if ($credentials !== '') {
            $args['curl'][CURLOPT_PROXYUSERPWD] = $credentials;
        }

        return $args;
    }

    /**
     * @param array<string,mixed> $args
     * @param string              $proxyUrl
     * @param string              $credentials
     *
     * @return array<string,mixed>
     */
    private function injectStreamProxy(array $args, $proxyUrl, $credentials)
    {
        $context = [];
        if (isset($args['stream_context']) && is_array($args['stream_context'])) {
            $context = $args['stream_context'];
        }

        if (!isset($context['http']) || !is_array($context['http'])) {
            $context['http'] = [];
        }

        $context['http']['proxy'] = $this->buildStreamProxyTarget($proxyUrl);
        $context['http']['request_fulluri'] = true;

        if ($credentials !== '') {
            $authHeader = 'Proxy-Authorization: Basic ' . base64_encode($credentials);
            if (!isset($context['http']['header'])) {
                $context['http']['header'] = $authHeader;
            } else {
                if (is_array($context['http']['header'])) {
                    $context['http']['header'][] = $authHeader;
                } else {
                    $context['http']['header'] = trim((string) $context['http']['header']);
                    if ($context['http']['header'] === '') {
                        $context['http']['header'] = $authHeader;
                    } else {
                        $context['http']['header'] .= "\r\n" . $authHeader;
                    }
                }
            }
        }

        $args['stream_context'] = $context;

        return $args;
    }

    private function buildStreamProxyTarget($proxyUrl)
    {
        $scheme = strtolower((string) parse_url($proxyUrl, PHP_URL_SCHEME));
        if ($scheme === 'http' || $scheme === 'https') {
            return $scheme . '://' . $this->stripScheme($proxyUrl);
        }

        return 'tcp://' . $this->stripScheme($proxyUrl);
    }

    private function stripScheme($proxyUrl)
    {
        $pos = strpos($proxyUrl, '://');
        if ($pos === false) {
            return $proxyUrl;
        }

        return substr($proxyUrl, $pos + 3);
    }
}

}

namespace {

use JLG\BrokenLinks\Scanner\ProxyPool;

function blc_get_proxy_pool_instance($refresh = false)
{
    static $instance = null;

    if ($refresh || !$instance instanceof ProxyPool) {
        $instance = ProxyPool::fromWordPressOptions();
    }

    return $instance;
}

function blc_get_proxy_pool_enabled_flag()
{
    $value = get_option('blc_proxy_pool_enabled', false);

    return (bool) $value;
}

function blc_get_proxy_pool_entries()
{
    $stored = get_option('blc_proxy_pool_entries', []);
    if (!is_array($stored)) {
        return [];
    }

    return $stored;
}

function blc_get_proxy_pool_credentials($decrypt = false)
{
    $stored = get_option('blc_proxy_pool_credentials', []);
    if (!is_array($stored)) {
        return [];
    }

    if (!$decrypt) {
        return $stored;
    }

    $decoded = [];
    foreach ($stored as $id => $secret) {
        $id = sanitize_key((string) $id);
        if ($id === '') {
            continue;
        }

        $decoded[$id] = blc_decrypt_secret($secret);
    }

    return $decoded;
}

function blc_get_proxy_pool_strategy()
{
    $stored = get_option('blc_proxy_pool_strategy', []);
    if (!is_array($stored)) {
        return [
            'mappings'  => [],
            'fallbacks' => ['default' => ['global']],
        ];
    }

    if (!isset($stored['mappings']) || !is_array($stored['mappings'])) {
        $stored['mappings'] = [];
    }

    if (!isset($stored['fallbacks']) || !is_array($stored['fallbacks'])) {
        $stored['fallbacks'] = ['default' => ['global']];
    }

    return $stored;
}

function blc_get_proxy_pool_health_snapshot()
{
    $stored = get_option('blc_proxy_pool_health', []);
    if (!is_array($stored)) {
        return [];
    }

    return $stored;
}

function blc_proxy_pool_register_outcome($proxyId, $success, $timestamp = null)
{
    $proxyId = sanitize_key((string) $proxyId);
    if ($proxyId === '') {
        return [];
    }

    if ($timestamp === null) {
        $timestamp = time();
    }

    $health = get_option('blc_proxy_pool_health', []);
    if (!is_array($health)) {
        $health = [];
    }

    $state = isset($health[$proxyId]) && is_array($health[$proxyId]) ? $health[$proxyId] : [];
    $failureCount = isset($state['failure_count']) ? (int) $state['failure_count'] : 0;
    $successCount = isset($state['success_count']) ? (int) $state['success_count'] : 0;

    if ($success) {
        $failureCount = 0;
        $successCount++;
        $suspendedUntil = 0;
        $lastSuccessAt = (int) $timestamp;
        $lastFailureAt = isset($state['last_failure_at']) ? (int) $state['last_failure_at'] : 0;
    } else {
        $failureCount++;
        $successCount = max(0, $successCount - 1);
        $penaltySeconds = min(600, max(30, 30 * $failureCount));
        $suspendedUntil = (int) $timestamp + $penaltySeconds;
        $lastFailureAt = (int) $timestamp;
        $lastSuccessAt = isset($state['last_success_at']) ? (int) $state['last_success_at'] : 0;
    }

    $newState = [
        'failure_count'   => $failureCount,
        'success_count'   => max(0, $successCount),
        'suspended_until' => $suspendedUntil,
        'last_failure_at' => $lastFailureAt,
        'last_success_at' => $lastSuccessAt,
    ];

    $health[$proxyId] = $newState;
    update_option('blc_proxy_pool_health', $health, false);

    $instance = blc_get_proxy_pool_instance();
    if ($instance instanceof ProxyPool) {
        $instance->synchroniseHealth($proxyId, $newState);
    }

    return $newState;
}

function blc_encrypt_secret($value)
{
    if (!is_string($value)) {
        $value = (string) $value;
    }

    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        return $value;
    }

    $keyMaterial = defined('AUTH_SALT') ? AUTH_SALT : (defined('AUTH_KEY') ? AUTH_KEY : 'blc-proxy');
    $keyMaterial .= defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '';
    $key = hash('sha256', $keyMaterial, true);

    if (function_exists('random_bytes')) {
        $iv = random_bytes(16);
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $iv = openssl_random_pseudo_bytes(16);
    } else {
        $iv = substr(hash('sha256', uniqid('blc', true)), 0, 16);
    }

    $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return $value;
    }

    return 'enc:' . base64_encode($iv . $cipher);
}

function blc_decrypt_secret($value)
{
    if (!is_string($value)) {
        $value = (string) $value;
    }

    if ($value === '') {
        return '';
    }

    if (strpos($value, 'enc:') !== 0) {
        return $value;
    }

    if (!function_exists('openssl_decrypt')) {
        return $value;
    }

    $payload = base64_decode(substr($value, 4), true);
    if (!is_string($payload) || strlen($payload) < 17) {
        return '';
    }

    $iv = substr($payload, 0, 16);
    $ciphertext = substr($payload, 16);

    $keyMaterial = defined('AUTH_SALT') ? AUTH_SALT : (defined('AUTH_KEY') ? AUTH_KEY : 'blc-proxy');
    $keyMaterial .= defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '';
    $key = hash('sha256', $keyMaterial, true);

    $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        return '';
    }

    return $plain;
}
}
