<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class PayGatePaymentHandler implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private PayGateClient $payGateClient;
    private RouterInterface $router;
    private SystemConfigService $systemConfigService;
    private EntityRepositoryInterface $orderTransactionRepository;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        PayGateClient $payGateClient,
        RouterInterface $router,
        SystemConfigService $systemConfigService,
        EntityRepositoryInterface $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->payGateClient = $payGateClient;
        $this->router = $router;
        $this->systemConfigService = $systemConfigService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
    }

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $order = $transaction->getOrder();
        $transactionId = $transaction->getOrderTransaction()->getId();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $callbackUrl = $this->router->generate(
            'frontend.paygate.callback',
            ['transactionId' => $transactionId],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            $wallet = $this->payGateClient->createWallet($callbackUrl, $salesChannelId);
        } catch (\Throwable $e) {
            $this->logger->error('PayGate.to wallet creation failed', [
                'transactionId' => $transactionId,
                'exception' => $e->getMessage(),
            ]);
            throw new AsyncPaymentProcessException(
                $transactionId,
                'Could not create PayGate.to payment wallet: ' . $e->getMessage()
            );
        }

        $amount = $order->getAmountTotal();
        $currency = strtoupper($salesChannelContext->getCurrency()->getIsoCode());
        $originalAmount = $amount;
        $originalCurrency = $currency;

        $checkoutMode = (string) $this->systemConfigService->get('PayGatePayment.config.checkoutMode', $salesChannelId);
        $provider = (string) $this->systemConfigService->get('PayGatePayment.config.paymentProvider', $salesChannelId);
        $convert = (bool) $this->systemConfigService->get('PayGatePayment.config.convertToUsd', $salesChannelId);
        $email = $order->getOrderCustomer() ? $order->getOrderCustomer()->getEmail() : null;

        if ($checkoutMode === 'picker') {
            // Store original order data; provider selection happens on the in-shop picker page.
            $this->persistTransactionData($transaction, $salesChannelContext->getContext(), [
                'paygate_address_in' => $wallet['address_in'],
                'paygate_ipn_token' => $wallet['ipn_token'] ?? null,
                'paygate_amount_original' => $originalAmount,
                'paygate_currency_original' => $originalCurrency,
                'paygate_customer_email' => $email,
                'paygate_callback_url' => $callbackUrl,
                'paygate_created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);

            $pickerUrl = $this->router->generate(
                'frontend.paygate.select',
                ['transactionId' => $transactionId],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            return new RedirectResponse($pickerUrl);
        }

        if ($checkoutMode === 'multi') {
            // Multi-provider mode: customer picks the provider on PayGate's hosted page.
            // No provider/currency constraints to enforce here.
            if ($convert && $currency !== 'USD') {
                $conversion = $this->payGateClient->convertToUsd($currency, $amount);
                if ($conversion !== null) {
                    $amount = (float) $conversion['value_coin'];
                    $currency = 'USD';
                }
            }
        } else {
            // Single-provider mode: enforce per-provider currency rules.
            $forceUsd = in_array($provider, PayGateClient::USD_ONLY_PROVIDERS, true);

            if (($forceUsd || $convert) && $currency !== 'USD') {
                $conversion = $this->payGateClient->convertToUsd($currency, $amount);
                if ($conversion !== null) {
                    $amount = (float) $conversion['value_coin'];
                    $currency = 'USD';
                } elseif ($forceUsd) {
                    throw new AsyncPaymentProcessException(
                        $transactionId,
                        sprintf('Provider "%s" requires USD but currency conversion failed.', $provider)
                    );
                }
            }

            if (isset(PayGateClient::FIXED_CURRENCY_PROVIDERS[$provider])
                && $currency !== PayGateClient::FIXED_CURRENCY_PROVIDERS[$provider]
            ) {
                throw new AsyncPaymentProcessException(
                    $transactionId,
                    sprintf(
                        'Provider "%s" only accepts %s currency; sales channel currency is %s.',
                        $provider,
                        PayGateClient::FIXED_CURRENCY_PROVIDERS[$provider],
                        $currency
                    )
                );
            }
        }

        if ($checkoutMode === 'multi') {
            $whiteLabel = $this->loadWhiteLabel($salesChannelId);
            $paymentUrl = $this->payGateClient->buildMultiProviderUrl(
                (string) $wallet['address_in'],
                $amount,
                $currency,
                $email,
                $whiteLabel
            );
        } elseif ($provider === '' || $provider === 'auto') {
            // process-payment.php requires an explicit provider; without one
            // PayGate replies "Bad request method!". Fall back to the
            // multi-provider hosted page (pay.php) which supports auto-selection.
            $whiteLabel = $this->loadWhiteLabel($salesChannelId);
            $paymentUrl = $this->payGateClient->buildMultiProviderUrl(
                (string) $wallet['address_in'],
                $amount,
                $currency,
                $email,
                $whiteLabel
            );
        } else {
            $paymentUrl = $this->payGateClient->buildPaymentUrl(
                (string) $wallet['address_in'],
                $amount,
                $currency,
                $provider,
                $email
            );
        }

        $this->persistTransactionData($transaction, $salesChannelContext->getContext(), [
            'paygate_address_in' => $wallet['address_in'],
            'paygate_ipn_token' => $wallet['ipn_token'] ?? null,
            'paygate_amount_sent' => $amount,
            'paygate_currency_sent' => $currency,
            'paygate_amount_original' => $originalAmount,
            'paygate_currency_original' => $originalCurrency,
            'paygate_customer_email' => $email,
            'paygate_callback_url' => $callbackUrl,
            'paygate_created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return new RedirectResponse($paymentUrl);
    }

    private function loadWhiteLabel(string $salesChannelId): array
    {
        return array_filter([
            'domain' => (string) $this->systemConfigService->get('PayGatePayment.config.whiteLabelDomain', $salesChannelId),
            'logo' => (string) $this->systemConfigService->get('PayGatePayment.config.whiteLabelLogo', $salesChannelId),
            'background' => (string) $this->systemConfigService->get('PayGatePayment.config.whiteLabelBackground', $salesChannelId),
            'theme' => (string) $this->systemConfigService->get('PayGatePayment.config.whiteLabelTheme', $salesChannelId),
            'button' => (string) $this->systemConfigService->get('PayGatePayment.config.whiteLabelButton', $salesChannelId),
        ], static fn ($v): bool => $v !== '');
    }

    private function persistTransactionData(
        AsyncPaymentTransactionStruct $transaction,
        \Shopware\Core\Framework\Context $context,
        array $newFields
    ): void {
        $existing = $transaction->getOrderTransaction()->getCustomFields() ?? [];
        $this->orderTransactionRepository->update([[
            'id' => $transaction->getOrderTransaction()->getId(),
            'customFields' => array_merge($existing, $newFields),
        ]], $context);
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        if ($request->query->getBoolean('cancel')) {
            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Customer canceled the payment on the PayGate.to page.'
            );
        }

        $verify = (bool) $this->systemConfigService->get(
            'PayGatePayment.config.verifyOnFinalize',
            $salesChannelContext->getSalesChannel()->getId()
        );

        if (!$verify) {
            return;
        }

        $customFields = $transaction->getOrderTransaction()->getCustomFields() ?? [];
        $ipnToken = $customFields['paygate_ipn_token'] ?? null;
        if (!is_string($ipnToken) || $ipnToken === '') {
            return;
        }

        try {
            $status = $this->payGateClient->checkPaymentStatus($ipnToken);
        } catch (\Throwable $e) {
            $this->logger->warning('PayGate.to payment-status check failed', [
                'transactionId' => $transactionId,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        if (($status['status'] ?? null) === 'paid') {
            try {
                $this->transactionStateHandler->paid($transactionId, $context);
            } catch (\Throwable $e) {
                throw new AsyncPaymentFinalizeException(
                    $transactionId,
                    'Could not update transaction state to paid: ' . $e->getMessage()
                );
            }
        }
    }
}
