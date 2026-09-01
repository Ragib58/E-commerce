<?php

declare(strict_types=1);

namespace App\Payments;

use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\Exceptions\PaymentException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves gateway implementations by identifier.
 *
 * ## The seam
 *
 * This class and `config/payment.php` are the only places in the application
 * that connect a gateway *name* to a gateway *class*. Everything downstream —
 * PaymentService, OrderService, the controllers, the admin panel — asks for a
 * gateway by string and receives a {@see PaymentGatewayInterface}.
 *
 * That is what makes the brief's requirement hold: a new processor is a class
 * plus a config line, and no core order logic changes, because no core order
 * logic ever knew which processor it was talking to.
 *
 * ## Resolved once per identifier
 *
 * Gateways are stateless but not free — BkashGateway caches a grant token, and
 * constructing it repeatedly within one request would repeat that lookup. They
 * are memoised here rather than registered as container singletons because the
 * set is data, read from config at runtime, and a container binding per gateway
 * would have to be re-registered whenever that data changed.
 *
 * ## Runtime registration
 *
 * {@see extend()} allows a gateway to be added without touching config, which
 * is what lets a test substitute a fake for a real processor. Production code
 * should use the config array; this exists so tests do not have to make network
 * calls to assert on payment behaviour.
 */
final class PaymentGatewayManager
{
    /**
     * Instances already built, keyed by identifier.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    private array $resolved = [];

    /**
     * Gateways registered at runtime, keyed by identifier.
     *
     * @var array<string, \Closure(): PaymentGatewayInterface>
     */
    private array $extensions = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Resolve a gateway by identifier.
     *
     * Throws on an unknown name rather than falling back to a default. A silent
     * fallback would mean an order recorded against `stripe` being processed by
     * cash on delivery after a typo — settling money through the wrong
     * mechanism, which is worse than a loud failure.
     *
     * @throws InvalidArgumentException when no such gateway is registered.
     */
    public function gateway(?string $identifier = null): PaymentGatewayInterface
    {
        $identifier ??= $this->defaultIdentifier();

        if (isset($this->resolved[$identifier])) {
            return $this->resolved[$identifier];
        }

        if (isset($this->extensions[$identifier])) {
            return $this->resolved[$identifier] = ($this->extensions[$identifier])();
        }

        $class = $this->registry()[$identifier] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(sprintf(
                'No payment gateway is registered under "%s". Known gateways: %s.',
                $identifier,
                implode(', ', $this->identifiers()) ?: 'none',
            ));
        }

        $gateway = $this->container->make($class);

        if (! $gateway instanceof PaymentGatewayInterface) {
            throw new InvalidArgumentException(sprintf(
                'Gateway "%s" resolves to %s, which does not implement PaymentGatewayInterface.',
                $identifier,
                $class,
            ));
        }

        return $this->resolved[$identifier] = $gateway;
    }

    /**
     * Resolve a gateway, requiring that it is actually usable.
     *
     * The distinction matters at the point of taking money. {@see gateway()}
     * returns a disabled or half-configured gateway happily, which is right for
     * an admin panel listing every known processor; this refuses, so a payment
     * is never attempted through one whose credentials are absent.
     *
     * @throws PaymentException when the gateway exists but is not configured.
     */
    public function availableGateway(?string $identifier = null): PaymentGatewayInterface
    {
        $gateway = $this->gateway($identifier);

        if (! $gateway->isAvailable()) {
            throw PaymentException::notConfigured($gateway->identifier());
        }

        return $gateway;
    }

    /**
     * Whether a gateway is registered under this identifier.
     */
    public function has(string $identifier): bool
    {
        return isset($this->extensions[$identifier]) || isset($this->registry()[$identifier]);
    }

    /**
     * Every registered identifier, configured or not.
     *
     * @return array<int, string>
     */
    public function identifiers(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->registry()),
            array_keys($this->extensions),
        )));
    }

    /**
     * Every gateway that is switched on and fully configured.
     *
     * What checkout offers. A gateway missing a credential is absent rather
     * than shown-and-then-failing — the most expensive moment to discover a
     * configuration error is when a customer is trying to pay.
     *
     * @return array<int, PaymentGatewayInterface>
     */
    public function available(): array
    {
        $available = [];

        foreach ($this->identifiers() as $identifier) {
            $gateway = $this->gateway($identifier);

            if ($gateway->isAvailable()) {
                $available[] = $gateway;
            }
        }

        return $available;
    }

    /**
     * Every gateway, for an admin panel listing.
     *
     * @return array<int, PaymentGatewayInterface>
     */
    public function all(): array
    {
        return array_map(fn (string $id): PaymentGatewayInterface => $this->gateway($id), $this->identifiers());
    }

    /**
     * Register a gateway at runtime.
     *
     * Overrides a config entry of the same name, which is what lets a test
     * replace a real processor with a fake and assert on payment behaviour
     * without network calls.
     *
     * @param  \Closure(): PaymentGatewayInterface  $factory
     */
    public function extend(string $identifier, \Closure $factory): void
    {
        $this->extensions[$identifier] = $factory;

        // Any previously memoised instance for this name is now stale.
        unset($this->resolved[$identifier]);
    }

    /**
     * Discard memoised instances.
     *
     * Needed between tests that change credentials: a gateway resolved under
     * the old config would keep answering with it.
     */
    public function forgetResolved(): void
    {
        $this->resolved = [];
    }

    public function defaultIdentifier(): string
    {
        return (string) config('payment.default', 'cash_on_delivery');
    }

    /**
     * @return array<string, class-string>
     */
    private function registry(): array
    {
        /** @var array<string, class-string> $registry */
        $registry = config('payment.gateways', []);

        return $registry;
    }
}
