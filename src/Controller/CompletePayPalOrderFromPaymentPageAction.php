<?php

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\PayPalPlugin\Exception\PaymentAmountMismatchException;
use Sylius\PayPalPlugin\Manager\PaymentStateManagerInterface;
use Sylius\PayPalPlugin\Provider\OrderProviderInterface;
use Sylius\PayPalPlugin\Verifier\PaymentAmountVerifierInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CompletePayPalOrderFromPaymentPageAction
{
    /** @var PaymentStateManagerInterface */
    private $paymentStateManager;

    /** @var UrlGeneratorInterface */
    private $router;

    /** @var OrderProviderInterface */
    private $orderProvider;

    /** @var FactoryInterface */
    private $stateMachine;

    /** @var ObjectManager */
    private $orderManager;

    private $paymentAmountVerifier = null;
    private $orderProcessor = null;

    public function __construct(
        PaymentStateManagerInterface $paymentStateManager,
        UrlGeneratorInterface $router,
        OrderProviderInterface $orderProvider,
        FactoryInterface $stateMachine,
        ObjectManager $orderManager,
        ?PaymentAmountVerifierInterface $paymentAmountVerifier = null,
        ?OrderProcessorInterface $orderProcessor = null
    ) {
        $this->paymentStateManager = $paymentStateManager;
        $this->router = $router;
        $this->orderProvider = $orderProvider;
        $this->stateMachine = $stateMachine;
        $this->orderManager = $orderManager;
        $this->paymentAmountVerifier = $paymentAmountVerifier;
        $this->orderProcessor = $orderProcessor;
    }

    public function __invoke(Request $request): Response
    {
        $orderId = $request->attributes->getInt('id');

        $order = $this->orderProvider->provideOrderById($orderId);
        /** @var PaymentInterface $payment */
        $payment = $order->getLastPayment(PaymentInterface::STATE_PROCESSING);

        try {
            if ($this->paymentAmountVerifier !== null) {
                $this->paymentAmountVerifier->verify($payment);
            } else {
                $this->verify($payment);
            }
        } catch (PaymentAmountMismatchException $e) {
            $this->paymentStateManager->cancel($payment);
            $order->removePayment($payment);

            if (null === $this->orderProcessor) {
                throw new \RuntimeException('Order processor is required to process the order.');
            }
            $this->orderProcessor->process($order);

            return new JsonResponse([
                'return_url' => $this->router->generate('sylius_shop_checkout_complete', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        }

        $this->paymentStateManager->complete($payment);

        $orderStateMachine = $this->stateMachine->get($order, OrderCheckoutTransitions::GRAPH);
        $orderStateMachine->apply(OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $orderStateMachine->apply(OrderCheckoutTransitions::TRANSITION_COMPLETE);

        $this->orderManager->flush();

        $request->getSession()->set('sylius_order_id', $order->getId());

        return new JsonResponse([
            'return_url' => $this->router->generate('sylius_shop_order_thank_you', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    private function verify(PaymentInterface $payment): void
    {
        $totalAmount = $this->getTotalPaymentAmountFromPaypal($payment);

        if ($payment->getOrder()->getTotal() !== $totalAmount) {
            throw new PaymentAmountMismatchException();
        }
    }

    private function getTotalPaymentAmountFromPaypal(PaymentInterface $payment): int
    {
        $details = $payment->getDetails();

        return $details['payment_amount'] ?? 0;
    }
}
