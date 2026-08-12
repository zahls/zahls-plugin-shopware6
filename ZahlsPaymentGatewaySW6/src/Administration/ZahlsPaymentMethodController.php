<?php

declare(strict_types=1);

namespace ZahlsPaymentGateway\Administration;

use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ZahlsPaymentMethodController
{
    /**
     * @Route("/api/_action/zahls_payment/validate-api-credentials", name="api.action.zahls_payment.validate.api.credentials", methods={"POST"})
     * @throws \Zahls\ZahlsException
     */
    #[Route(path: '/api/_action/zahls_payment/validate-api-credentials', name: 'api.action.zahls_payment.validate.api.credentials', methods: ['POST'])]
    public function validateApiCredentials(Request $request, Context $context): JsonResponse
    {
        require_once dirname(dirname(__DIR__)). '/vendor/autoload.php';
        $config = $request->get('credentials', []);

        $platform = !empty($config['platform']) ? $config['platform'] : '';
        $zahls = new \Zahls\Zahls($config['instanceName'], $config['apiKey'], '', $platform);

        $signatureCheck = new \Zahls\Models\Request\SignatureCheck();

        $error = '';
        try {
            $zahls->getOne($signatureCheck);
        } catch(\Zahls\ZahlsException $e) {
            $error = $e;
        }
        return new JsonResponse(['credentialsValid' => !$error, 'error' => $error]);

    }

}
