<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Controller;

use PayGateTo\PayGatePayment\Service\PayGateClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class ProviderSelectController extends StorefrontController
{
    /**
     * Human-friendly grouping of PayGate providers shown to the customer.
     * Unknown active providers automatically land in an "other" bucket.
     */
    private const PROVIDER_GROUPS = [
        'card' => [
            'label' => 'Kreditkarte & Wallets',
            'description' => 'Sofortige Zahlung mit Kreditkarte, Apple Pay oder Google Pay',
            'icon' => 'credit-card',
            'providers' => ['stripe', 'moonpay', 'wert', 'rampnetwork', 'bitnovo', 'robinhood'],
        ],
        'bank' => [
            'label' => 'Banküberweisung',
            'description' => 'SEPA, ACH oder Sofortüberweisung',
            'icon' => 'bank',
            'providers' => ['transfi'],
        ],
        'local' => [
            'label' => 'Lokale Zahlungsmethoden',
            'description' => 'Länderspezifische Methoden',
            'icon' => 'globe',
            'providers' => ['upi', 'interac'],
        ],
    ];

    /**
     * Fallback provider list used when /control/provider-status is unreachable
     * or returns nothing usable. Keeps the picker functional in edge cases.
     */
    private const FALLBACK_PROVIDERS = [
        ['id' => 'stripe', 'provider_name' => 'Stripe', 'status' => 'active'],
        ['id' => 'moonpay', 'provider_name' => 'MoonPay', 'status' => 'active'],
        ['id' => 'wert', 'provider_name' => 'Wert', 'status' => 'active'],
        ['id' => 'rampnetwork', 'provider_name' => 'Ramp Network', 'status' => 'active'],
        ['id' => 'transfi', 'provider_name' => 'Transfi', 'status' => 'active'],
    ];

    /**
     * Status values that explicitly mean "do not offer this provider".
     * Everything else (active, online, up, unknown, …) is treated as available.
     */
    private const BAD_STATUSES = ['inactive', 'down', 'offline', 'disabled', 'maintenance', 'paused'];

    /**
     * Customer-facing labels for known providers. Overrides the raw
     * provider_name from the API so customers see merchant-style options
     * (Kreditkarte / SEPA / ...) instead of backend processor names.
     */
    private const PROVIDER_LABELS = [
        'stripe' => [
            'label' => 'Kreditkarte (Visa, Mastercard, Amex)',
            'subtitle' => 'Sofortige Zahlung mit 3D-Secure',
        ],
        'moonpay' => [
            'label' => 'Kreditkarte & Wallets',
            'subtitle' => 'Inkl. Apple Pay und Google Pay',
        ],
        'wert' => [
            'label' => 'Kreditkarte',
            'subtitle' => '3D-Secure abgesichert',
        ],
        'rampnetwork' => [
            'label' => 'Kreditkarte & Apple Pay',
            'subtitle' => 'Schnelle Abwicklung',
        ],
        'bitnovo' => [
            'label' => 'Kreditkarte (international)',
            'subtitle' => null,
        ],
        'robinhood' => [
            'label' => 'Kreditkarte',
            'subtitle' => null,
        ],
        'transfi' => [
            'label' => 'SEPA-Lastschrift / Banküberweisung',
            'subtitle' => 'Sicher direkt vom Bankkonto',
        ],
        'upi' => [
            'label' => 'UPI',
            'subtitle' => 'Für Kunden in Indien',
        ],
        'interac' => [
            'label' => 'Interac',
            'subtitle' => 'Für Kunden in Kanada',
        ],
    ];

    private EntityRepositoryInterface $orderTransactionRepository;
    private PayGateClient $payGateClient;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepositoryInterface $orderTransactionRepository,
        PayGateClient $payGateClient,
        LoggerInterface $logger
    ) {
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->payGateClient = $payGateClient;
        $this->logger = $logger;
    }

    /**
     * @Route(
     *     "/checkout/payment/select/{transactionId}",
     *     name="frontend.paygate.select",
     *     methods={"GET"}
     * )
     */
    public function select(string $transactionId, Context $context): Response
    {
        $transaction = $this->loadTransaction($transactionId, $context);
        $customFields = $transaction->getCustomFields() ?? [];

        if (empty($customFields['paygate_address_in'])) {
            throw $this->createNotFoundException();
        }

        try {
            $providers = $this->payGateClient->listProviders();
        } catch (\Throwable $e) {
            $this->logger->error('PayGate.to provider-status failed', ['exception' => $e->getMessage()]);
            $providers = [];
        }

        $orderCurrency = strtoupper((string) ($customFields['paygate_currency_original'] ?? 'EUR'));
        $orderAmount = (float) ($customFields['paygate_amount_original'] ?? 0);

        $providerGroups = $this->groupProviders($providers, $orderAmount, $orderCurrency);

        // If filtering left nothing (API down, unexpected status values, etc.)
        // fall back to a static list so the customer can still pay.
        if ($providerGroups === []) {
            $this->logger->warning('PayGate.to provider-status returned no usable providers; using fallback list', [
                'received_count' => count($providers),
            ]);
            $providerGroups = $this->groupProviders(self::FALLBACK_PROVIDERS, $orderAmount, $orderCurrency);
        }

        return $this->renderStorefront('@PayGatePayment/storefront/page/paygate/select-provider.html.twig', [
            'transactionId' => $transactionId,
            'providerGroups' => $providerGroups,
            'orderAmount' => $orderAmount,
            'orderCurrency' => $orderCurrency,
        ]);
    }

    /**
     * @Route(
     *     "/checkout/payment/select/{transactionId}/confirm",
     *     name="frontend.paygate.confirm",
     *     methods={"GET"}
     * )
     */
    public function confirm(string $transactionId, Request $request, Context $context): Response
    {
        $provider = trim((string) $request->query->get('provider', ''));
        if ($provider === '') {
            return $this->redirectToRoute('frontend.paygate.select', ['transactionId' => $transactionId]);
        }

        $transaction = $this->loadTransaction($transactionId, $context);
        $customFields = $transaction->getCustomFields() ?? [];

        $addressIn = (string) ($customFields['paygate_address_in'] ?? '');
        if ($addressIn === '') {
            throw $this->createNotFoundException();
        }

        $amount = (float) ($customFields['paygate_amount_original'] ?? 0);
        $currency = strtoupper((string) ($customFields['paygate_currency_original'] ?? 'EUR'));
        $email = (string) ($customFields['paygate_customer_email'] ?? '');

        // Apply provider-specific currency rules.
        if (in_array($provider, PayGateClient::USD_ONLY_PROVIDERS, true) && $currency !== 'USD') {
            $conversion = $this->payGateClient->convertToUsd($currency, $amount);
            if ($conversion === null) {
                $this->addFlash(
                    self::DANGER,
                    sprintf('Die Umrechnung zu USD ist fehlgeschlagen. Bitte wähle eine andere Zahlungsmethode.')
                );
                return $this->redirectToRoute('frontend.paygate.select', ['transactionId' => $transactionId]);
            }
            $amount = (float) $conversion['value_coin'];
            $currency = 'USD';
        }

        if (isset(PayGateClient::FIXED_CURRENCY_PROVIDERS[$provider])
            && $currency !== PayGateClient::FIXED_CURRENCY_PROVIDERS[$provider]
        ) {
            $this->addFlash(
                self::DANGER,
                sprintf(
                    'Diese Zahlungsmethode akzeptiert nur %s, deine Bestellung ist in %s.',
                    PayGateClient::FIXED_CURRENCY_PROVIDERS[$provider],
                    $currency
                )
            );
            return $this->redirectToRoute('frontend.paygate.select', ['transactionId' => $transactionId]);
        }

        $this->orderTransactionRepository->update([[
            'id' => $transactionId,
            'customFields' => array_merge($customFields, [
                'paygate_chosen_provider' => $provider,
                'paygate_amount_sent' => $amount,
                'paygate_currency_sent' => $currency,
            ]),
        ]], $context);

        $url = $this->payGateClient->buildPaymentUrl(
            $addressIn,
            $amount,
            $currency,
            $provider,
            $email !== '' ? $email : null
        );

        $this->logger->info('PayGate.to redirecting to process-payment.php', [
            'transactionId' => $transactionId,
            'provider' => $provider,
            'amount' => $amount,
            'currency' => $currency,
            'url' => $url,
        ]);

        return new RedirectResponse($url);
    }

    private function loadTransaction(string $transactionId, Context $context)
    {
        $criteria = new Criteria([$transactionId]);
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($transaction === null) {
            throw $this->createNotFoundException();
        }

        return $transaction;
    }

    /**
     * @param array<int, array<string, mixed>> $providers
     * @return array<int, array{label:string, description:string, icon:string, providers: array<int, array<string, mixed>>}>
     */
    private function groupProviders(array $providers, float $orderAmount, string $orderCurrency): array
    {
        $byId = [];
        foreach ($providers as $p) {
            if (!is_array($p) || !isset($p['id'])) {
                continue;
            }
            $status = strtolower((string) ($p['status'] ?? ''));
            if (in_array($status, self::BAD_STATUSES, true)) {
                continue;
            }
            $byId[(string) $p['id']] = $p;
        }

        $groups = [];
        $used = [];

        foreach (self::PROVIDER_GROUPS as $group) {
            $items = [];
            foreach ($group['providers'] as $pid) {
                if (isset($byId[$pid])) {
                    $items[] = $this->annotate($byId[$pid], $orderAmount, $orderCurrency);
                    $used[$pid] = true;
                }
            }
            if ($items !== []) {
                $groups[] = [
                    'label' => $group['label'],
                    'description' => $group['description'],
                    'icon' => $group['icon'],
                    'providers' => $items,
                ];
            }
        }

        $other = [];
        foreach ($byId as $pid => $p) {
            if (!isset($used[$pid])) {
                $other[] = $this->annotate($p, $orderAmount, $orderCurrency);
            }
        }
        if ($other !== []) {
            $groups[] = [
                'label' => 'Weitere Zahlungsmethoden',
                'description' => '',
                'icon' => 'wallet',
                'providers' => $other,
            ];
        }

        return $groups;
    }

    /**
     * Adds display-only fields to a provider entry: below-minimum flag and
     * customer-facing label/subtitle.
     */
    private function annotate(array $provider, float $orderAmount, string $orderCurrency): array
    {
        $minimumAmount = isset($provider['minimum_amount']) ? (float) $provider['minimum_amount'] : 0.0;
        $minimumCurrency = strtoupper((string) ($provider['minimum_currency'] ?? ''));

        // Only flag below-minimum when the currency matches exactly; cross-currency
        // comparisons would require an extra convert.php call per provider.
        $belowMinimum = $minimumAmount > 0
            && $minimumCurrency === $orderCurrency
            && $orderAmount < $minimumAmount;

        $id = (string) $provider['id'];
        $override = self::PROVIDER_LABELS[$id] ?? null;

        $provider['_below_minimum'] = $belowMinimum;
        $provider['_label'] = $override['label'] ?? (string) ($provider['provider_name'] ?? $id);
        $provider['_subtitle'] = $override['subtitle'] ?? null;

        return $provider;
    }
}
