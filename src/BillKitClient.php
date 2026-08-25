<?php

declare(strict_types=1);

namespace BillKit;

use BillKit\Resource\AuditLogs;
use BillKit\Resource\BillingPortalSessions;
use BillKit\Resource\CheckoutSessions;
use BillKit\Resource\Coupons;
use BillKit\Resource\Customers;
use BillKit\Resource\Disputes;
use BillKit\Resource\Events;
use BillKit\Resource\Invoices;
use BillKit\Resource\OneShotPayments;
use BillKit\Resource\Payments;
use BillKit\Resource\Prices;
use BillKit\Resource\Products;
use BillKit\Resource\Refunds;
use BillKit\Resource\Subscriptions;
use BillKit\Resource\TaxRates;
use BillKit\Resource\Tenant;
use BillKit\Resource\WebhookEndpoints;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Top-level BillKit client.
 *
 * Wraps a single {@see Transport} and exposes every resource family as a
 * public readonly property:
 *
 *     $client = new \BillKit\BillKitClient('sk_test_...');
 *     $customer = $client->customers->create(['email' => 'ada@example.com']);
 *     $product  = $client->products->create(['name' => 'Pro']);
 *     $price    = $client->prices->create([
 *         'product_id'   => $product['id'],
 *         'amount_cents' => 999,
 *         'currency'     => 'EUR',
 *         'interval'     => 'month',
 *     ]);
 *
 * The API key resolves from the constructor argument, falling back to the
 * ``BILLKIT_API_KEY`` environment variable.
 *
 * The client is silent unless you pass a PSR-3 ``$logger``; see
 * {@see Transport} for what it logs and the (short) list of things it
 * deliberately never logs.
 */
final class BillKitClient
{
    public readonly Transport $transport;

    public readonly Customers $customers;
    public readonly Products $products;
    public readonly Prices $prices;
    public readonly CheckoutSessions $checkoutSessions;
    public readonly OneShotPayments $oneShotPayments;
    public readonly Subscriptions $subscriptions;
    public readonly Refunds $refunds;
    public readonly Disputes $disputes;
    public readonly WebhookEndpoints $webhookEndpoints;
    public readonly Events $events;
    public readonly Tenant $tenant;
    public readonly Coupons $coupons;
    public readonly TaxRates $taxRates;
    public readonly Invoices $invoices;
    public readonly AuditLogs $auditLogs;
    public readonly Payments $payments;
    public readonly BillingPortalSessions $billingPortalSessions;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        int $timeoutMs = Transport::DEFAULT_TIMEOUT_MS,
        ?RetryPolicy $retryPolicy = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $key = $apiKey ?? self::apiKeyFromEnv();
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException(
                'BillKit: missing API key. Pass $apiKey or set BILLKIT_API_KEY in the environment.',
            );
        }

        $this->transport = new Transport(
            $key,
            $baseUrl,
            $timeoutMs,
            $retryPolicy,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $logger,
        );

        $this->customers = new Customers($this->transport);
        $this->products = new Products($this->transport);
        $this->prices = new Prices($this->transport);
        $this->checkoutSessions = new CheckoutSessions($this->transport);
        $this->oneShotPayments = new OneShotPayments($this->transport);
        $this->subscriptions = new Subscriptions($this->transport);
        $this->refunds = new Refunds($this->transport);
        $this->disputes = new Disputes($this->transport);
        $this->webhookEndpoints = new WebhookEndpoints($this->transport);
        $this->events = new Events($this->transport);
        $this->tenant = new Tenant($this->transport);
        $this->coupons = new Coupons($this->transport);
        $this->taxRates = new TaxRates($this->transport);
        $this->invoices = new Invoices($this->transport);
        $this->auditLogs = new AuditLogs($this->transport);
        $this->payments = new Payments($this->transport);
        $this->billingPortalSessions = new BillingPortalSessions($this->transport);
    }

    private static function apiKeyFromEnv(): ?string
    {
        $value = getenv('BILLKIT_API_KEY');

        return $value === false || $value === '' ? null : $value;
    }
}
