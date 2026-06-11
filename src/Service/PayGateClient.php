<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PayGateClient
{
    public const API_BASE_DEFAULT = 'https://api.paygate.to';
    public const CHECKOUT_BASE_DEFAULT = 'https://checkout.paygate.to';

    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(SystemConfigService $systemConfigService, LoggerInterface $logger)
    {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    public function apiBase(): string
    {
        $custom = trim((string) $this->systemConfigService->get('PayGatePayment.config.customApiBase'));
        return $custom !== '' ? rtrim($custom, '/') : self::API_BASE_DEFAULT;
    }

    public function checkoutBase(): string
    {
        $custom = trim((string) $this->systemConfigService->get('PayGatePayment.config.customCheckoutBase'));
        return $custom !== '' ? rtrim($custom, '/') : self::CHECKOUT_BASE_DEFAULT;
    }

    /**
     * Creates a temporary encrypted Polygon wallet that will forward USDC
     * to the merchant (and optionally affiliate) address once paid.
     *
     * @return array{address_in:string,ipn_token?:string}
     */
    public function createWallet(string $callbackUrl, ?string $salesChannelId = null): array
    {
        $merchant = trim((string) $this->systemConfigService->get('PayGatePayment.config.merchantWallet', $salesChannelId));
        $affiliate = trim((string) $this->systemConfigService->get('PayGatePayment.config.affiliateWallet', $salesChannelId));

        if ($merchant === '') {
            throw new \RuntimeException('PayGate.to merchant wallet is not configured.');
        }

        if ($affiliate !== '') {
            $url = sprintf(
                '%s/control/affiliate.php?address=%s&affiliate=%s&callback=%s',
                $this->apiBase(),
                rawurlencode($merchant),
                rawurlencode($affiliate),
                rawurlencode($callbackUrl)
            );
        } else {
            $url = sprintf(
                '%s/control/wallet.php?address=%s&callback=%s',
                $this->apiBase(),
                rawurlencode($merchant),
                rawurlencode($callbackUrl)
            );
        }

        $body = $this->httpGet($url);
        $data = $this->decodeJson($body);

        if (!is_array($data) || empty($data['address_in'])) {
            throw new \RuntimeException('PayGate.to wallet creation failed. Response: ' . $body);
        }

        // PayGate returns address_in and ipn_token already URL-encoded.
        // Decode once so they can be re-encoded cleanly by http_build_query
        // when we hand them to process-payment.php / pay.php / payment-status.php.
        $data['address_in'] = rawurldecode((string) $data['address_in']);
        if (isset($data['ipn_token'])) {
            $data['ipn_token'] = rawurldecode((string) $data['ipn_token']);
        }

        return $data;
    }

    /**
     * Providers that only accept USD as currency.
     */
    public const USD_ONLY_PROVIDERS = ['stripe', 'transfi', 'robinhood', 'bitnovo'];

    /**
     * Providers that require a specific (non-USD) currency.
     */
    public const FIXED_CURRENCY_PROVIDERS = [
        'upi' => 'INR',
        'interac' => 'CAD',
    ];

    /**
     * Builds the single-provider redirect URL (process-payment.php).
     */
    public function buildPaymentUrl(
        string $addressIn,
        float $amount,
        string $currency,
        ?string $provider = null,
        ?string $email = null
    ): string {
        $params = [
            'address' => $addressIn,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
        ];

        if ($provider !== null && $provider !== '' && $provider !== 'auto') {
            $params['provider'] = $provider;
        }
        if ($email !== null && $email !== '') {
            $params['email'] = $email;
        }

        return $this->checkoutBase() . '/process-payment.php?' . http_build_query($params);
    }

    /**
     * Builds the multi-provider hosted checkout URL (pay.php) with optional
     * white-label styling.
     *
     * @param array{domain?:string,logo?:string,background?:string,theme?:string,button?:string} $whiteLabel
     */
    public function buildMultiProviderUrl(
        string $addressIn,
        float $amount,
        string $currency,
        ?string $email = null,
        array $whiteLabel = []
    ): string {
        $params = [
            'address' => $addressIn,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
        ];

        if ($email !== null && $email !== '') {
            $params['email'] = $email;
        }

        foreach (['domain', 'logo', 'background', 'theme', 'button'] as $key) {
            if (isset($whiteLabel[$key]) && $whiteLabel[$key] !== '') {
                $params[$key] = $whiteLabel[$key];
            }
        }

        return $this->checkoutBase() . '/pay.php?' . http_build_query($params);
    }

    /**
     * Returns the live provider list (id, provider_name, status, minimum_amount, minimum_currency).
     * Handles common response shapes (bare array or {providers|data: [...]}) and logs the raw
     * response so unexpected payloads can be diagnosed in prod.log.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProviders(): array
    {
        $body = $this->httpGet($this->apiBase() . '/control/provider-status');
        $this->logger->info('PayGate.to provider-status response', [
            'body' => substr($body, 0, 2000),
        ]);

        $data = $this->decodeJson($body);
        if (!is_array($data)) {
            throw new \RuntimeException('PayGate.to provider-status returned non-JSON: ' . substr($body, 0, 200));
        }

        if (isset($data['providers']) && is_array($data['providers'])) {
            $data = $data['providers'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        } elseif (isset($data['result']) && is_array($data['result'])) {
            $data = $data['result'];
        }

        // If the array is associative (keyed by provider id), normalize to a list.
        if ($data !== [] && array_keys($data) !== range(0, count($data) - 1)) {
            $normalized = [];
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    if (!isset($value['id'])) {
                        $value['id'] = (string) $key;
                    }
                    $normalized[] = $value;
                }
            }
            $data = $normalized;
        }

        return $data;
    }

    /**
     * Converts an arbitrary currency amount into USD using PayGate's convert.php endpoint.
     *
     * @return array{value_coin:string,exchange_rate:string}|null
     */
    public function convertToUsd(string $fromCurrency, float $value): ?array
    {
        $url = sprintf(
            '%s/control/convert.php?from=%s&value=%s',
            $this->apiBase(),
            rawurlencode($fromCurrency),
            rawurlencode(number_format($value, 2, '.', ''))
        );

        try {
            $body = $this->httpGet($url);
            $data = $this->decodeJson($body);
            if (is_array($data) && ($data['status'] ?? null) === 'success' && isset($data['value_coin'])) {
                return $data;
            }
            $this->logger->warning('PayGate.to convert.php unexpected response', ['body' => $body]);
        } catch (\Throwable $e) {
            $this->logger->error('PayGate.to convert.php failed', ['exception' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Queries payment-status.php for a given ipn_token.
     *
     * @return array{status:string,value_coin?:string,txid_out?:string,coin?:string}
     */
    public function checkPaymentStatus(string $ipnToken): array
    {
        $url = sprintf('%s/control/payment-status.php?ipn_token=%s', $this->apiBase(), rawurlencode($ipnToken));
        $body = $this->httpGet($url);
        $data = $this->decodeJson($body);

        if (!is_array($data) || !isset($data['status'])) {
            throw new \RuntimeException('PayGate.to payment-status returned unexpected payload: ' . $body);
        }

        return $data;
    }

    private function httpGet(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "Accept: application/json\r\nUser-Agent: Shopware-PayGate-Plugin/1.0\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('HTTP request to PayGate.to failed: ' . $url);
        }

        return $body;
    }

    private function decodeJson(string $body)
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }
}
