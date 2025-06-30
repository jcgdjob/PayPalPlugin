<?php

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use GuzzleHttp\Exception\GuzzleException;
use Payum\Core\Payum;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\PayPalPlugin\Provider\OrderProviderInterface;
use Sylius\PayPalPlugin\Resolver\CapturePaymentResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

final class CreatePayPalOrderFromCartAction
{
    /** @var Payum */
    private $payum;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var FactoryInterface */
    private $stateMachineFactory;

    /** @var ObjectManager */
    private $paymentManager;

    /** @var OrderProviderInterface */
    private $orderProvider;

    /** @var CapturePaymentResolverInterface */
    private $capturePaymentResolver;

    private $orderProcessor = null;

    public function __construct(
        ?Payum $payum,
        ?OrderRepositoryInterface $orderRepository,
        ?FactoryInterface $stateMachineFactory,
        ObjectManager $paymentManager,
        OrderProviderInterface $orderProvider,
        CapturePaymentResolverInterface $capturePaymentResolver,
        ?OrderProcessorInterface $orderProcessor = null
    ) {
        $this->payum = $payum;
        $this->orderRepository = $orderRepository;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->paymentManager = $paymentManager;
        $this->orderProvider = $orderProvider;
        $this->capturePaymentResolver = $capturePaymentResolver;
        $this->orderProcessor = $orderProcessor;
    }

    public function __invoke(Request $request): Response
    {
        $id = $request->attributes->getInt('id');
        $order = $this->orderProvider->provideOrderById($id);

        try {
            $payment = $this->getPayment($order);
            $this->capturePaymentResolver->resolve($payment);
        } catch (GuzzleException $exception) {
            /** @var FlashBagInterface $flashBag */
            $flashBag = $request->getSession()->getBag('flashes');
            $flashBag->add('error', 'sylius.pay_pal.something_went_wrong');

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $this->paymentManager->flush();

        return new JsonResponse([
            'id' => $order->getId(),
            'orderID' => $payment->getDetails()['paypal_order_id'],
            'status' => $payment->getState(),
        ]);
    }

    private function getPayment(OrderInterface $order): PaymentInterface
    {
        /** @var PaymentInterface $payment */
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        /** @var PaymentMethodInterface|null $paymentMethod */
        $paymentMethod = $payment->getMethod();
        if (!null($paymentMethod) && !null($paymentMethod->getGatewayConfig()))
            $factoryName = $paymentMethod->getGatewayConfig()->getFactoryName();
        else
            $factoryName = '';

        if ($factoryName === 'sylius.pay_pal') {
            return $payment;
        }

        $this->removePayments($order);
        $this->orderProcessor->process($order);

        return $order->getLastPayment(PaymentInterface::STATE_CART);
    }
    public function canRemovePayments(OrderInterface $order): bool
    {
        return 0 === $order->getTotal();
    }

    public function removePayments(OrderInterface $order): void
    {
        $removablePayments = $order->getPayments()->filter(function (PaymentInterface $payment): bool {
            return $payment->getState() === PaymentInterface::STATE_CART;
        });

        foreach ($removablePayments as $payment) {
            $order->removePayment($payment);
        }
    }
}
