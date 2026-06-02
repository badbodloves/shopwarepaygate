<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PayGateClient
{
    private const API_BASE = 'https://api.paygate.to';
    private const CHECKOUT_BASE = 'https://checkout.paygate.to';

    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(SystemConfigService $systemConfigService, LoggerInterface $logger)
    {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
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
                self::API_BASE,
                rawurlencode($merchant),
                rawurlencode($affiliate),
                rawurlencode($callbackUrl)
            );
        } else {
            $url = sprintf(
                '%s/control/wallet.php?address=%s&callback=%s',
                self::API_BASE,
                rawurlencode($merchant),
                rawurlencode($callbackUrl)
            );
        }

        $body = $this->httpGet($url);
        $data = $this->decodeJson($body);

        if (!is_array($data) || empty($data['address_in'])) {
            throw new \RuntimeException('PayGate.to wallet creation failed. Response: ' . $body);
        }

        return $data;
    }

    /**
     * Builds the redirect URL the customer is sent to on PayGate's checkout.
     */
    public function buildPaymentUrl(
        string $addressIn,
        float $amount,
        string $currency,
        ?string $provider = null,
        ?string $email = null,
        ?string $orderNumber = null
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
        if ($orderNumber !== null && $orderNumber !== '') {
            $params['order_id'] = $orderNumber;
        }

        return self::CHECKOUT_BASE . '/process-payment.php?' . http_build_query($params);
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
            self::API_BASE,
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
        $url = sprintf('%s/control/payment-status.php?ipn_token=%s', self::API_BASE, rawurlencode($ipnToken));
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
