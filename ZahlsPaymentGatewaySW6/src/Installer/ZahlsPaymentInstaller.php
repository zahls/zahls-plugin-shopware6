<?php

namespace ZahlsPaymentGateway\Installer;

use ZahlsPaymentGateway\Installer\Modules\PaymentMethodInstaller;
use ZahlsPaymentGateway\Util\Compatibility\EntityRepositoryDecorator;
use Shopware\Core\Checkout\Payment\PaymentMethodDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class ZahlsPaymentInstaller implements InstallerInterface
{
    /** @var ContainerInterface */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function install(InstallContext $context): void
    {
        $this->getPaymentMethodInstaller()->install($context);
        $this->setPlatform();
    }

    public function update(UpdateContext $context): void
    {
        $this->getPaymentMethodInstaller()->update($context);
        $this->setPlatform();
    }

    public function uninstall(UninstallContext $context): void
    {
        $this->getPaymentMethodInstaller()->uninstall($context);
    }

    public function activate(ActivateContext $context): void
    {
        $this->getPaymentMethodInstaller()->activate($context);
        $this->setPlatform();
    }

    public function deactivate(DeactivateContext $context): void
    {
        $this->getPaymentMethodInstaller()->deactivate($context);
    }

    /**
     * zahls.ch is the only platform, so there is no setting for it any more. The bundled
     * administration (connect button) still reads it from the plugin configuration.
     */
    private function setPlatform(): void
    {
        $systemConfig = $this->container->get(SystemConfigService::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if ($systemConfig instanceof SystemConfigService) {
            $systemConfig->set('ZahlsPaymentGatewaySW6.settings.platform', 'zahls.ch');
        }
    }

    private function getPaymentMethodInstaller(): InstallerInterface
    {
        $entityName = \sprintf('%s.repository', PaymentMethodDefinition::ENTITY_NAME);
        $entityRepository = $this->container->get($entityName, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        if (\interface_exists(EntityRepositoryInterface::class) && $entityRepository instanceof EntityRepositoryInterface) {
            $entityRepository = new EntityRepositoryDecorator($entityRepository);
        }
     
        if (!$entityRepository instanceof EntityRepository) {
            throw new ServiceNotFoundException($entityName);
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);


        return new PaymentMethodInstaller($entityRepository, $pluginIdProvider);
    }
}
