<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\NassWallet;

use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\Support\Money;

final class NassWalletGateway extends AbstractGateway implements SupportsStatusCheck
{
    private const LANGUAGES = ['en', 'ku', 'ar'];

    private readonly NassWalletClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        $this->client = new NassWalletClient(
            baseUrl: (string) $config['base_url'],
            tokens: new NassWalletTokenCache(
                (string) $config['base_url'],
                (string) ($config['basic_token'] ?? ''),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
            ),
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        // NassWallet settles IQD only. Reject other currencies up front rather
        // than silently charging IQD while echoing the requested currency.
        if ($request->currency !== Currency::IQD) {
            throw new InvalidArgumentException(
                'NassWallet settles IQD only; got ' . $request->currency->value
            );
        }

        // Derive a numeric orderId from the stable idempotency key. crc32 is
        // deterministic, so a retried performCharge re-sends the SAME orderId
        // and never creates a duplicate NassWallet transaction.
        $idemKey = $request->idempotencyKey ?? IdempotencyKey::derive(
            $this->name(),
            $request->reference,
            $request->amount,
            $request->currency->value,
        );
        $orderId = (string) crc32($idemKey);

        $raw = $this->client->initTransaction([
            'userIdentifier' => (string) ($this->config['username'] ?? ''),
            'transactionPin' => (string) ($this->config['transaction_pin'] ?? ''),
            'orderId' => $orderId,
            // NassWallet expects the amount as a 2-decimal string.
            'amount' => number_format($request->amount, 2, '.', ''),
            'languageCode' => $this->languageCode($request),
        ]);

        $data = (array) ($raw['data'] ?? []);
        $transactionId = (string) ($data['transactionId'] ?? '');
        $token = (string) ($data['token'] ?? '');
        if ($transactionId === '' || $token === '') {
            throw new GatewayUnavailableException('NassWallet initTransaction returned no transactionId/token');
        }

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: $transactionId,
            reference: $request->reference,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            redirectUrl: $this->redirectUrl($transactionId, $token),
            raw: $raw,
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        $raw = $this->client->checkTransaction($gatewayTransactionId);
        [$status, $amount] = $this->parseCheckTransaction($raw);

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }

    /**
     * NassWallet's callback carries no signature, so the notification body
     * cannot be trusted on its own. The trust boundary is the checkTransaction
     * endpoint: we read only the InitTransactionId from the callback, then
     * re-fetch the authoritative state server-to-server. Any failure on that
     * call is a verification failure (401 at the controller).
     */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $initTransactionId = (string) $request->input('data.InitTransactionId', '');
        if ($initTransactionId === '') {
            throw new InvalidWebhookSignatureException('NassWallet callback missing InitTransactionId');
        }

        try {
            $raw = $this->client->checkTransaction($initTransactionId);
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'NassWallet callback verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        [$status, $amount] = $this->parseCheckTransaction($raw);

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $initTransactionId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: Currency::IQD,
            eventId: $initTransactionId . ':' . $status->value,
            occurredAt: new DateTimeImmutable(),
            raw: $raw,
        );
    }

    /**
     * Parse a checkTransaction body. The endpoint has two documented response
     * shapes: a flat `data.transactionStatus`, or a rich
     * `data.TransactionHistoryList[]` carrying `TransactionStatus`/`Amount`.
     * Returns [PaymentStatus, amount-in-minor-units].
     *
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: int}
     */
    private function parseCheckTransaction(array $raw): array
    {
        $data = (array) ($raw['data'] ?? []);

        $statusText = $data['transactionStatus'] ?? null;
        $amountText = null;

        if (!is_string($statusText)) {
            // Rich shape — take the first history entry.
            $history = (array) ($data['TransactionHistoryList'] ?? []);
            $first = is_array($history[0] ?? null) ? $history[0] : [];
            $statusText = $first['TransactionStatus'] ?? '';
            $amountText = $first['Amount'] ?? null;
        }

        $status = NassWalletStatusMap::toStatus((string) $statusText);

        $amount = is_string($amountText) && preg_match('/^\d+(\.\d+)?$/', $amountText) === 1
            ? Money::parse($amountText, Currency::IQD)
            : 0;

        return [$status, $amount];
    }

    /** Build the hosted checkout-portal URL the customer is sent to. */
    private function redirectUrl(string $transactionId, string $token): string
    {
        $portal = rtrim((string) ($this->config['portal_url'] ?? ''), '/');

        return $portal . '/payment-gateway?' . http_build_query([
            'id' => $transactionId,
            'token' => $token,
            'userIdentifier' => (string) ($this->config['username'] ?? ''),
        ]);
    }

    private function languageCode(PaymentRequest $request): string
    {
        $lang = strtolower((string) ($request->metadata['language'] ?? 'en'));

        return in_array($lang, self::LANGUAGES, true) ? $lang : 'en';
    }
}
