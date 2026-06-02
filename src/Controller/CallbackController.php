<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Controller;

use PayGateTo\PayGatePayment\Service\PayGateClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class CallbackController extends StorefrontController
{
    private EntityRepositoryInterface $orderTransactionRepository;
    private OrderTransactionStateHandler $transactionStateHandler;
    private PayGateClient $payGateClient;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepositoryInterface $orderTransactionRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        PayGateClient $payGateClient,
        LoggerInterface $logger
    ) {
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->payGateClient = $payGateClient;
        $this->logger = $logger;
    }

    /**
     * @Route(
     *     "/checkout/payment/notify/{transactionId}",
     *     name="frontend.paygate.callback",
     *     methods={"GET"},
     *     defaults={"csrf_protected"=false, "XmlHttpRequest"=true, "auth_required"=false}
     * )
     */
    public function callback(string $transactionId, Request $request, Context $context): Response
    {
        $valueCoin = (string) $request->query->get('value_coin', '');
        $valueForwardedCoin = (string) $request->query->get('value_forwarded_coin', '');
        $txidIn = (string) $request->query->get('txid_in', '');
        $txidOut = (string) $request->query->get('txid_out', '');
        $addressIn = (string) $request->query->get('address_in', '');
        $coin = (string) $request->query->get('coin', '');

        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        if ($transaction === null) {
            $this->logger->warning('PayGate.to callback: transaction not found', [
                'transactionId' => $transactionId,
                'query' => $request->query->all(),
            ]);
            return new JsonResponse(['status' => 'unknown'], 404);
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $expectedAddress = $customFields['paygate_address_in'] ?? null;

        if (is_string($expectedAddress) && $expectedAddress !== '' && strcasecmp($expectedAddress, $addressIn) !== 0) {
            $this->logger->warning('PayGate.to callback: address_in mismatch', [
                'transactionId' => $transactionId,
                'expected' => $expectedAddress,
                'received' => $addressIn,
            ]);
            return new JsonResponse(['status' => 'mismatch'], 400);
        }

        // Optional defence in depth: verify against payment-status.php
        $ipnToken = $customFields['paygate_ipn_token'] ?? null;
        if (is_string($ipnToken) && $ipnToken !== '') {
            try {
                $remote = $this->payGateClient->checkPaymentStatus($ipnToken);
                if (($remote['status'] ?? null) !== 'paid') {
                    $this->logger->warning('PayGate.to callback received but payment-status is not paid', [
                        'transactionId' => $transactionId,
                        'remote' => $remote,
                    ]);
                    return new JsonResponse(['status' => 'not_paid'], 409);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('PayGate.to payment-status verification failed; trusting callback', [
                    'transactionId' => $transactionId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->transactionStateHandler->paid($transactionId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('PayGate.to callback: state transition to paid failed', [
                'transactionId' => $transactionId,
                'exception' => $e->getMessage(),
            ]);
        }

        $this->orderTransactionRepository->update([[
            'id' => $transactionId,
            'customFields' => array_merge($customFields, [
                'paygate_txid_in' => $txidIn,
                'paygate_txid_out' => $txidOut,
                'paygate_value_coin' => $valueCoin,
                'paygate_value_forwarded_coin' => $valueForwardedCoin,
                'paygate_coin' => $coin,
                'paygate_paid_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]),
        ]], $context);

        return new JsonResponse(['status' => 'ok']);
    }
}
