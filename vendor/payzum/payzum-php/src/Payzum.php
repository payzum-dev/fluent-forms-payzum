<?php

declare(strict_types=1);

namespace Payzum;

use Payzum\Http\Transport;
use Payzum\Resources\Currencies;
use Payzum\Resources\Invoices;
use Payzum\Resources\Payments;
use Payzum\Resources\Rates;
use Payzum\Webhooks\Verifier;

/**
 * Entry point for the Payzum PHP SDK.
 *
 *     $payzum = new Payzum('your-api-key');
 *
 *     $invoice = $payzum->payments->create(
 *         priceAmount: '49.99',
 *         priceCurrency: 'usd',
 *         payCurrency: 'all',       // let the buyer choose
 *         orderId: 'ORDER-12345',
 *     );
 *     header('Location: ' . $invoice['invoice_url']);
 *
 * And in your webhook handler, against the RAW body:
 *
 *     $payload = $payzum->webhooks($secret)->verifyPaymentIpn(
 *         file_get_contents('php://input'),
 *         getallheaders(),
 *     );
 */
final class Payzum
{
    public const VERSION = '0.1.0';

    /** Production API host. `api.payzum.com` does NOT serve the API. */
    public const BASE_URL = Config::PRODUCTION;

    /** Sandbox host. Isolated data, separate API keys. */
    public const SANDBOX_URL = Config::SANDBOX;

    public readonly Client $client;
    public readonly Payments $payments;
    public readonly Invoices $invoices;
    public readonly Currencies $currencies;
    public readonly Rates $rates;

    public function __construct(
        string $apiKey,
        string $baseUrl = self::BASE_URL,
        ?Transport $transport = null,
    ) {
        $config = new Config($apiKey, $baseUrl);
        $this->client = $transport === null
            ? new Client($config)
            : new Client($config, $transport);

        $this->payments = new Payments($this->client);
        $this->invoices = new Invoices($this->client);
        $this->currencies = new Currencies($this->client);
        $this->rates = new Rates($this->client);
    }

    /** Point the SDK at staging. */
    public static function sandbox(string $apiKey, ?Transport $transport = null): self
    {
        return new self($apiKey, self::SANDBOX_URL, $transport);
    }

    /**
     * Webhook verifier for the given signing secret.
     *
     * The secret is shown once, at merchant creation or rotation, and is
     * separate from the API key.
     */
    public function webhooks(string $webhookSecret): Verifier
    {
        return new Verifier($webhookSecret);
    }

    /**
     * API diagnostics (GET /v1/status). Public — useful as a connectivity
     * probe before blaming your own network. (`/health` exists separately for
     * the platform's own probes and is deliberately not exposed here.)
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->client->request('GET', '/v1/status', authenticated: false);
    }
}
