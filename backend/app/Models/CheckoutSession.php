<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckoutStep;
use Database\Factories\CheckoutSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A checkout in progress.
 *
 * Holds the *answers* to each step and nothing else — no prices, no totals. See
 * the migration: a total persisted at step four and trusted at step seven is a
 * three-step window in which the catalog can move, and a writable surface a
 * crafted request can aim at. Every figure is recomputed on each read.
 *
 * @property int $id
 * @property string $token
 * @property int $cart_id
 * @property int|null $user_id
 * @property array<string, mixed>|null $data
 * @property string $current_step
 * @property int|null $order_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
class CheckoutSession extends Model
{
    /** @use HasFactory<CheckoutSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'token',
        'cart_id',
        'user_id',
        'data',
        'current_step',
        'order_id',
        'completed_at',
        'expires_at',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Session data
    |--------------------------------------------------------------------------
    */

    /**
     * Read one key from the collected answers.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data ?? [], $key, $default);
    }

    /**
     * Merge values into the collected answers.
     *
     * A merge rather than a replace: each step writes only its own key, and
     * overwriting the whole payload would make a client that omits an earlier
     * step's data silently erase it.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(array $values): void
    {
        $this->data = array_merge($this->data ?? [], $values);
    }

    /**
     * The step the shopper should be shown.
     *
     * Derived from what the session actually contains rather than from the
     * stored `current_step` column, which is a hint for resuming. Deriving it
     * means a session whose data was invalidated — a cart that emptied, an
     * address that failed revalidation — is sent back to the step that must be
     * redone, rather than resuming at a step whose prerequisites no longer hold.
     */
    public function nextStep(): CheckoutStep
    {
        return CheckoutStep::firstIncomplete($this->data ?? []);
    }

    /**
     * Whether every step before `place` has been satisfied.
     */
    public function isReadyToPlace(): bool
    {
        foreach (CheckoutStep::Place->prerequisites() as $step) {
            if (! $step->isSatisfiedBy($this->data ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Per-step completion, for rendering the progress indicator.
     *
     * @return array<int, array{step: string, label: string, position: int, is_complete: bool, is_current: bool}>
     */
    public function progress(): array
    {
        $data = $this->data ?? [];
        $current = $this->nextStep();

        return array_map(
            static fn (CheckoutStep $step): array => [
                'step' => $step->value,
                'label' => $step->label(),
                'position' => $step->position(),
                'is_complete' => $step->isSatisfiedBy($data),
                'is_current' => $step === $current,
            ],
            CheckoutStep::cases(),
        );
    }

    /**
     * Discard everything from the given step onward.
     *
     * Called when an earlier answer changes in a way that invalidates a later
     * one: switching to a country the chosen shipping method does not serve
     * must clear that choice rather than carry it forward. Leaving it would
     * price the order with a method that is no longer offered.
     */
    public function invalidateFrom(CheckoutStep $step): void
    {
        $keys = [
            CheckoutStep::ShippingAddress->value => ['shipping_address'],
            CheckoutStep::BillingAddress->value => ['billing_address', 'billing_same_as_shipping'],
            CheckoutStep::ShippingMethod->value => ['shipping_method_id'],
            CheckoutStep::PaymentMethod->value => ['payment_method'],
            CheckoutStep::Review->value => ['reviewed_at'],
        ];

        $data = $this->data ?? [];

        foreach (CheckoutStep::cases() as $case) {
            if ($case->position() < $step->position()) {
                continue;
            }

            foreach ($keys[$case->value] ?? [] as $key) {
                unset($data[$key]);
            }
        }

        $this->data = $data;
    }

    /**
     * Clear the review acknowledgement.
     *
     * Any change to a priced input must un-review the order: "you agreed to
     * this total" is only true of the total that was actually shown, and
     * letting a stale acknowledgement stand would let a shopper be charged for
     * a figure they never saw.
     */
    public function invalidateReview(): void
    {
        $data = $this->data ?? [];
        unset($data['reviewed_at']);
        $this->data = $data;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null || $this->order_id !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at ?? Carbon::now());
    }

    /**
     * Whether this session may still be used.
     */
    public function isUsable(?Carbon $at = null): bool
    {
        return ! $this->isCompleted() && ! $this->isExpired($at);
    }

    /**
     * @param  Builder<CheckoutSession>  $query
     * @return Builder<CheckoutSession>
     */
    public function scopeUsable(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->whereNull('completed_at')
            ->whereNull('order_id')
            ->where('expires_at', '>', $at ?? Carbon::now());
    }

    /**
     * Sessions eligible for pruning.
     *
     * Completed sessions are included: once an order exists the session's
     * personal data is duplicated onto it, and keeping a second copy of an
     * address in a table nobody reads is liability without benefit.
     *
     * @param  Builder<CheckoutSession>  $query
     * @return Builder<CheckoutSession>
     */
    public function scopePrunable(Builder $query, Carbon $before): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('expires_at', '<', $before)
            ->orWhere(fn (Builder $completed) => $completed
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $before)));
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
