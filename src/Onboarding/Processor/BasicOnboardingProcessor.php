<?php

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Onboarding\Processor;

use GuzzleHttp\ClientInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\PayPalPlugin\Exception\PayPalPluginException;
use Sylius\PayPalPlugin\Exception\PayPalWebhookUrlNotValidException;
use Sylius\PayPalPlugin\Provider\PayPalConfigurationProviderInterface;
use Sylius\PayPalPlugin\Registrar\SellerWebhookRegistrarInterface;
use Symfony\Component\HttpFoundation\Request;
use Webmozart\Assert\Assert;

final class BasicOnboardingProcessor implements OnboardingProcessorInterface
{
    /** @var ClientInterface */
    private $httpClient;

    /** @var SellerWebhookRegistrarInterface */
    private $sellerWebhookRegistrar;

    /** @var PayPalConfigurationProviderInterface */
    private $payPalConfigurationProvider;

    public function __construct(
        ClientInterface $httpClient,
        SellerWebhookRegistrarInterface $sellerWebhookRegistrar,
        PayPalConfigurationProviderInterface $payPalConfigurationProvider
    ) {
        $this->httpClient = $httpClient;
        $this->sellerWebhookRegistrar = $sellerWebhookRegistrar;
        $this->payPalConfigurationProvider = $payPalConfigurationProvider;
    }

    public function process(
        PaymentMethodInterface $paymentMethod,
        Request $request
    ): PaymentMethodInterface {
        if (!$this->supports($paymentMethod, $request)) {
            throw new \DomainException('not supported');
        }

        $gatewayConfig = $paymentMethod->getGatewayConfig();

        /** @var GatewayConfig $gatewayConfig */
        Assert::notNull($gatewayConfig);

        $onboardingId = (string) $request->query->get('onboarding_id');
        $checkPartnerReferralsResponse = $this->httpClient->request(
            'GET',
            sprintf(
                '%s/partner-referrals/check/%s', $this->payPalConfigurationProvider->getFacilitatorUrl(), $onboardingId
            ),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]
        );

        /** @var array $response */
        $response = (array) json_decode($checkPartnerReferralsResponse->getBody()->getContents(), true);

        if (!isset($response['client_id']) || !isset($response['client_secret'])) {
            throw new PayPalPluginException();
        }

        $gatewayConfig->setConfig([
            'client_id' => $response['client_id'],
            'client_secret' => $response['client_secret'],
            'merchant_id' => $response['merchant_id'],
            'sylius_merchant_id' => $response['sylius_merchant_id'],
            'onboarding_id' => $onboardingId,
            'partner_attribution_id' => $response['partner_attribution_id'],
        ]);

        $permissionsGranted = (bool) $request->query->get('permissionsGranted', true);
        if (!$permissionsGranted) {
            $paymentMethod->setEnabled(false);
        }

        try {
            $this->sellerWebhookRegistrar->register($paymentMethod);
        } catch (PayPalWebhookUrlNotValidException $exception) {
            $paymentMethod->setEnabled(false);
        }

        return $paymentMethod;
    }

    public function supports(PaymentMethodInterface $paymentMethod, Request $request): bool
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        if ($gatewayConfig === null) {
            return false;
        }

        if ($gatewayConfig->getFactoryName() !== 'sylius.pay_pal') {
            return false;
        }

        return $request->query->has('onboarding_id');
    }
}
