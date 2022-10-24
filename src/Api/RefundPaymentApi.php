<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Api;

use Sylius\PayPalPlugin\Client\PayPalClientInterface;

final class RefundPaymentApi implements RefundPaymentApiInterface
{
    /** @var PayPalClientInterface */
    private $client;

    public function __construct(PayPalClientInterface $client)
    {
        $this->client = $client;
    }

    public function refund(
        string $token,
        string $paymentId,
        string $payPalAuthAssertion,
        string $invoiceNumber,
        string $amount,
        string $currencyCode
    ): array {
        $response = $this->client->post(
            sprintf('v2/payments/captures/%s/refund', $paymentId),
            $token,
            ['amount' => ['value' => $amount, 'currency_code' => $currencyCode], 'invoice_id' => $invoiceNumber],
            ['PayPal-Auth-Assertion' => $payPalAuthAssertion]
        );



        if (isset($response['id'])) {
            $r = $this->client->get(
                sprintf('v2/payments/refunds/%s', $response['id']),
                $token
            );
        }

        $result = [];

        if (isset($response['details'][0]['description']))
            $result['error'] = $response['details'][0]['description'];

        if (isset($response['id']))
            $result['id'] = $response['id'];

        if (isset($response['status']))
            $result['status'] = $response['status'];


        if (isset($r['id'])) {
            $result['refund']['id'] = $r['id'];

            //update to server time on accounts__movements table
            $payment_date = new \DateTimeImmutable($r['update_time']);
            $payment_date = new \DateTime( $payment_date->format('Y-m-d H:i:s') , new \DateTimeZone(date_default_timezone_get()));
            $payment_date = gmdate('Y-m-d H:i:s', (int)$payment_date->format('U'));
            $payment_date = addslashes($payment_date);

            $result['refund']['update_time'] = $payment_date;

            
            if (isset($r['seller_payable_breakdown']))
                $result['refund']['seller_payable_breakdown'] = $r['seller_payable_breakdown'];
        }
     
        return $result;

    }
}
