<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a note to an order's thread.
 *
 * `is_customer_visible` is the field that matters, and it defaults to false in
 * two places — the database column and the controller's read of this request.
 * An internal note reading "customer is being difficult, hold the refund"
 * rendered on their order page is a serious incident, so exposing a note has to
 * be a deliberate act rather than the outcome of omitting a field.
 */
final class StoreOrderNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware carries `permission:update_orders`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],

            // Absent means internal. See the class docblock.
            'is_customer_visible' => ['sometimes', 'boolean'],

            /*
             * Whether to email the note to the customer. Only meaningful for a
             * visible note — the controller refuses the combination rather than
             * silently ignoring it, because "notify" on a hidden note is more
             * likely a mistake about visibility than about notification.
             */
            'notify_customer' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something before saving the note.',
        ];
    }
}
