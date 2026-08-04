<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * Saved products, for signed-in customers.
 *
 * Guests keep a wishlist too, but on the client in localStorage — a
 * server-stored anonymous wishlist costs the same as a cart while being worth
 * far less, since the shopper has no way to return to it from another device.
 * {@see merge()} folds the local list in when they sign in.
 */
final class WishlistService
{
    /**
     * Ceiling per customer. Not a business rule so much as a guard: an
     * unbounded list is a way to use the products table as free storage, and no
     * shopper meaningfully curates more than this.
     */
    private const MAX_ITEMS = 200;

    /**
     * A customer's saved products, newest first.
     *
     * Unpublished products are filtered out by the scope rather than returned
     * with a flag: unlike a cart line — where a shopper needs to know why their
     * total changed — a saved item that is no longer for sale is simply not
     * shown, and reappears if it is republished.
     *
     * @return EloquentCollection<int, WishlistItem>
     */
    public function forUser(User $user): EloquentCollection
    {
        return WishlistItem::query()
            ->forListing((int) $user->getKey())
            ->limit(self::MAX_ITEMS)
            ->get();
    }

    /**
     * Save a product.
     *
     * Idempotent: saving something already saved is a success, not an error.
     * The unique index does the work, so two concurrent clicks cannot create
     * two rows.
     *
     * @throws ValidationException
     */
    public function add(User $user, string $productSlugOrUuid): WishlistItem
    {
        $product = $this->resolveProduct($productSlugOrUuid);

        $existing = WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->where('product_id', $product->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertCapacity($user);

        try {
            return WishlistItem::query()->create([
                'user_id' => $user->getKey(),
                'product_id' => $product->getKey(),
            ]);
        } catch (QueryException $exception) {
            // A concurrent request won the race. The outcome the caller asked
            // for — "this product is saved" — is true either way.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return WishlistItem::query()
                ->where('user_id', $user->getKey())
                ->where('product_id', $product->getKey())
                ->firstOrFail();
        }
    }

    /**
     * Remove a saved product. Removing something not saved is a no-op.
     *
     * @throws ValidationException
     */
    public function remove(User $user, string $productSlugOrUuid): void
    {
        $product = $this->resolveProduct($productSlugOrUuid);

        WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->where('product_id', $product->getKey())
            ->delete();
    }

    /**
     * Fold a guest's local wishlist into the account's.
     *
     * Called once after sign-in. Unknown or unpublished identifiers are skipped
     * silently: a stale localStorage entry pointing at a withdrawn product must
     * not fail the whole merge and cost the shopper the rest of their list.
     *
     * @param  array<int, string>  $identifiers
     * @return int Number of products newly saved.
     */
    public function merge(User $user, array $identifiers): int
    {
        if ($identifiers === []) {
            return 0;
        }

        $identifiers = array_slice(array_unique($identifiers), 0, self::MAX_ITEMS);

        // One query for every identifier rather than one per item: a shopper
        // signing in with thirty saved products should not cost thirty
        // round trips.
        $products = Product::query()
            ->published()
            ->where(fn ($query) => $query
                ->whereIn('uuid', $identifiers)
                ->orWhereIn('slug', $identifiers))
            ->get();

        if ($products->isEmpty()) {
            return 0;
        }

        $existing = WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->pluck('product_id')
            ->all();

        $capacity = max(0, self::MAX_ITEMS - count($existing));

        $rows = $products
            ->reject(fn (Product $product): bool => in_array($product->getKey(), $existing, strict: true))
            ->take($capacity)
            ->map(fn (Product $product): array => [
                'user_id' => $user->getKey(),
                'product_id' => $product->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return 0;
        }

        // insertOrIgnore rather than insert: another tab may have saved one of
        // these between the read above and this write, and a duplicate-key
        // error would lose the entire merge over one already-saved item.
        WishlistItem::query()->insertOrIgnore($rows);

        return count($rows);
    }

    /**
     * The product ids a customer has saved, for marking cards in a grid.
     *
     * Returns public identifiers (uuids) rather than integer ids, matching what
     * the storefront holds — the integer id is never exposed by the catalog API.
     *
     * @return array<int, string>
     */
    public function savedIdentifiers(User $user): array
    {
        return WishlistItem::query()
            ->where('user_id', $user->getKey())
            ->join('products', 'products.id', '=', 'wishlist_items.product_id')
            ->pluck('products.uuid')
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function resolveProduct(string $identifier): Product
    {
        $product = Product::query()
            ->published()
            ->where(fn ($query) => $query->where('uuid', $identifier)->orWhere('slug', $identifier))
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'product' => ['That product could not be found.'],
            ]);
        }

        return $product;
    }

    /**
     * @throws ValidationException
     */
    private function assertCapacity(User $user): void
    {
        $count = WishlistItem::query()->where('user_id', $user->getKey())->count();

        if ($count >= self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'wishlist' => ['Your wishlist is full. Remove an item before saving another.'],
            ]);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || $exception->getCode() === '23505';
    }
}
