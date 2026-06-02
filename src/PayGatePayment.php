<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment;

use PayGateTo\PayGatePayment\Service\PayGatePaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class PayGatePayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->upsertPaymentMethod($installContext->getContext());
        parent::install($installContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->setPaymentMethodActive(true, $activateContext->getContext());
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setPaymentMethodActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->setPaymentMethodActive(false, $uninstallContext->getContext());
        parent::uninstall($uninstallContext);
    }

    private function upsertPaymentMethod(Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepo */
        $paymentRepo = $this->container->get('payment_method.repository');
        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);

        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        $data = [
            'handlerIdentifier' => PayGatePaymentHandler::class,
            'name' => 'PayGate.to',
            'description' => 'Pay with credit card, Apple Pay, Google Pay, SEPA or ACH via PayGate.to',
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => [
                    'name' => 'PayGate.to (Kreditkarte / Apple Pay / SEPA)',
                    'description' => 'Zahlen mit Kreditkarte, Apple Pay, Google Pay, SEPA oder ACH über PayGate.to',
                ],
                'en-GB' => [
                    'name' => 'PayGate.to (Card / Apple Pay / SEPA)',
                    'description' => 'Pay with credit card, Apple Pay, Google Pay, SEPA or ACH via PayGate.to',
                ],
            ],
        ];

        $criteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', PayGatePaymentHandler::class));
        $existingId = $paymentRepo->searchIds($criteria, $context)->firstId();
        if ($existingId !== null) {
            $data['id'] = $existingId;
        }

        $paymentRepo->upsert([$data], $context);
    }

    private function setPaymentMethodActive(bool $active, Context $context): void
    {
        /** @var EntityRepositoryInterface $paymentRepo */
        $paymentRepo = $this->container->get('payment_method.repository');

        $criteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', PayGatePaymentHandler::class));
        $id = $paymentRepo->searchIds($criteria, $context)->firstId();
        if ($id === null) {
            return;
        }

        $paymentRepo->update([['id' => $id, 'active' => $active]], $context);
    }
}
