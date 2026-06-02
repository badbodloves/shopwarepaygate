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
        $currency = $salesChannelContext->getCurrency()->getIsoCode();

        $convert = (bool) $this->systemConfigService->get('PayGatePayment.config.convertToUsd', $salesChannelId);
        if ($convert && strtoupper($currency) !== 'USD') {
            $conversion = $this->payGateClient->convertToUsd($currency, $amount);
            if ($conversion !== null) {
                $amount = (float) $conversion['value_coin'];
                $currency = 'USD';
            }
        }

        $provider = (string) $this->systemConfigService->get('PayGatePayment.config.paymentProvider', $salesChannelId);
        $email = $order->getOrderCustomer() ? $order->getOrderCustomer()->getEmail() : null;

        $paymentUrl = $this->payGateClient->buildPaymentUrl(
            (string) $wallet['address_in'],
            $amount,
            $currency,
            $provider !== '' ? $provider : null,
            $email,
            $order->getOrderNumber()
        );

        $existingCustomFields = $transaction->getOrderTransaction()->getCustomFields() ?? [];
        $this->orderTransactionRepository->update([[
            'id' => $transactionId,
            'customFields' => array_merge($existingCustomFields, [
                'paygate_address_in' => $wallet['address_in'],
                'paygate_ipn_token' => $wallet['ipn_token'] ?? null,
                'paygate_amount_sent' => $amount,
                'paygate_currency_sent' => $currency,
                'paygate_callback_url' => $callbackUrl,
                'paygate_created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]),
        ]], $salesChannelContext->getContext());

        return new RedirectResponse($paymentUrl);
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
