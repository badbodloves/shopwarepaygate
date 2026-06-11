<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Drives the one-time Cloudflare setup: finds the zone for the merchant
 * domain, creates proxied DNS records for the API and checkout subdomains,
 * uploads the translation worker and binds it via worker routes. The
 * resulting custom-domain URLs are written back to the plugin config so the
 * runtime PayGateClient transparently switches over.
 */
class CloudflareSetupService
{
    private const CF_API = 'https://api.cloudflare.com/client/v4';

    private SystemConfigService $config;
    private LoggerInterface $logger;

    public function __construct(SystemConfigService $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Runs the full setup. Returns a list of human-readable progress lines.
     *
     * @return string[]
     */
    public function setup(): array
    {
        $domain = trim((string) $this->config->get('PayGatePayment.config.cloudflareDomain'));
        if ($domain === '') {
            throw new \RuntimeException('Cloudflare domain is not configured (PayGate plugin settings).');
        }

        $apiSub = trim((string) ($this->config->get('PayGatePayment.config.cloudflareApiSubdomain') ?: 'api'));
        $payerSub = trim((string) ($this->config->get('PayGatePayment.config.cloudflareCheckoutSubdomain') ?: 'pay'));
        $apiHost = $apiSub . '.' . $domain;
        $checkoutHost = $payerSub . '.' . $domain;

        $scriptName = trim((string) ($this->config->get('PayGatePayment.config.cloudflareWorkerName') ?: 'paygate-mirror'));
        $translate = (bool) $this->config->get('PayGatePayment.config.translateCheckoutPage');
        $affiliateWallet = trim((string) $this->config->get('PayGatePayment.config.affiliateWallet'));

        $log = [];

        $zone = $this->findZone($domain);
        $zoneId = (string) $zone['id'];
        $accountId = (string) ($zone['account']['id'] ?? '');
        if ($accountId === '') {
            throw new \RuntimeException('Cloudflare zone has no account id.');
        }
        $log[] = "Zone found: {$domain} (id {$zoneId}, account {$accountId})";

        $this->upsertDnsRecord($zoneId, $apiHost);
        $log[] = "DNS A record (proxied): {$apiHost}";

        $this->upsertDnsRecord($zoneId, $checkoutHost);
        $log[] = "DNS A record (proxied): {$checkoutHost}";

        $workerJs = $this->renderWorker([
            'API_DOMAIN' => $apiHost,
            'CHECKOUT_DOMAIN' => $checkoutHost,
            'AFFILIATE_WALLET' => $affiliateWallet,
            'TRANSLATE' => $translate ? 'true' : 'false',
        ]);
        $this->uploadWorker($accountId, $scriptName, $workerJs);
        $log[] = "Worker uploaded: {$scriptName}";

        $this->upsertRoute($zoneId, $apiHost . '/*', $scriptName);
        $log[] = "Route: {$apiHost}/* -> {$scriptName}";

        $this->upsertRoute($zoneId, $checkoutHost . '/*', $scriptName);
        $log[] = "Route: {$checkoutHost}/* -> {$scriptName}";

        $this->config->set('PayGatePayment.config.customApiBase', 'https://' . $apiHost);
        $this->config->set('PayGatePayment.config.customCheckoutBase', 'https://' . $checkoutHost);
        $log[] = "Plugin now uses https://{$apiHost} and https://{$checkoutHost}";

        return $log;
    }

    private function findZone(string $domain): array
    {
        $resp = $this->cfRequest('GET', '/zones?name=' . rawurlencode($domain));
        if (empty($resp['result'])) {
            throw new \RuntimeException("No Cloudflare zone found for domain '{$domain}'. Add the domain to Cloudflare first.");
        }
        return $resp['result'][0];
    }

    private function upsertDnsRecord(string $zoneId, string $name): void
    {
        $existing = $this->cfRequest('GET', sprintf(
            '/zones/%s/dns_records?type=A&name=%s',
            rawurlencode($zoneId),
            rawurlencode($name)
        ));

        $payload = [
            'type' => 'A',
            'name' => $name,
            'content' => '192.0.2.1',
            'proxied' => true,
            'ttl' => 1,
        ];

        if (!empty($existing['result'])) {
            $id = $existing['result'][0]['id'];
            $this->cfRequest('PUT', sprintf('/zones/%s/dns_records/%s', $zoneId, $id), $payload);
        } else {
            $this->cfRequest('POST', sprintf('/zones/%s/dns_records', $zoneId), $payload);
        }
    }

    private function uploadWorker(string $accountId, string $scriptName, string $jsCode): void
    {
        $path = sprintf('/accounts/%s/workers/scripts/%s', rawurlencode($accountId), rawurlencode($scriptName));
        $this->cfRequest('PUT', $path, $jsCode, 'application/javascript');
    }

    private function upsertRoute(string $zoneId, string $pattern, string $scriptName): void
    {
        $existing = $this->cfRequest('GET', sprintf('/zones/%s/workers/routes', $zoneId));
        foreach ($existing['result'] ?? [] as $route) {
            if (($route['pattern'] ?? null) === $pattern) {
                $id = $route['id'];
                $this->cfRequest('PUT', sprintf('/zones/%s/workers/routes/%s', $zoneId, $id), [
                    'pattern' => $pattern,
                    'script' => $scriptName,
                ]);
                return;
            }
        }
        $this->cfRequest('POST', sprintf('/zones/%s/workers/routes', $zoneId), [
            'pattern' => $pattern,
            'script' => $scriptName,
        ]);
    }

    /**
     * @param string|array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function cfRequest(string $method, string $path, $body = null, string $contentType = 'application/json'): array
    {
        $url = self::CF_API . $path;

        $headers = $this->authHeaders();
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: ' . $contentType;
        $headers[] = 'User-Agent: Shopware-PayGate-Plugin/1.0';

        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            throw new \RuntimeException("Cloudflare API request failed: {$method} {$path}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Cloudflare API returned non-JSON ({$method} {$path}): " . substr($response, 0, 300));
        }

        if (!($decoded['success'] ?? false)) {
            $errors = $decoded['errors'] ?? [];
            $msg = json_encode($errors, JSON_UNESCAPED_SLASHES);
            throw new \RuntimeException("Cloudflare API error ({$method} {$path}): {$msg}");
        }

        return $decoded;
    }

    /**
     * @return string[]
     */
    private function authHeaders(): array
    {
        $token = trim((string) $this->config->get('PayGatePayment.config.cloudflareApiToken'));
        if ($token !== '') {
            return ['Authorization: Bearer ' . $token];
        }

        $email = trim((string) $this->config->get('PayGatePayment.config.cloudflareEmail'));
        $key = trim((string) $this->config->get('PayGatePayment.config.cloudflareGlobalKey'));
        if ($email !== '' && $key !== '') {
            return [
                'X-Auth-Email: ' . $email,
                'X-Auth-Key: ' . $key,
            ];
        }

        throw new \RuntimeException('Cloudflare credentials missing: configure either API Token or Email + Global Key.');
    }

    private function renderWorker(array $replacements): string
    {
        $template = file_get_contents(__DIR__ . '/../Resources/cloudflare/worker.js');
        if ($template === false) {
            throw new \RuntimeException('worker.js template not found.');
        }

        foreach ($replacements as $placeholder => $value) {
            $template = str_replace('__' . $placeholder . '__', (string) $value, $template);
        }
        return $template;
    }
}
