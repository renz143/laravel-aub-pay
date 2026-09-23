<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hosted checkout's two tables. Loaded automatically while `aub-pay.checkout.enabled` is on;
 * publish with `--tag=aub-pay-migrations` to own them instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aub_checkout_sessions', function (Blueprint $table) {
            // The page's address and its only credential: 32 hex characters of random_bytes(16).
            $table->string('id', 32)->primary();

            // The merchant's own reference, shown in the page header. Not unique: a merchant may
            // well open a second session for an order whose first one expired.
            $table->string('reference_number', 64)->nullable()->index();

            $table->string('status', 16)->default('active');
            $table->string('currency', 3)->default('PHP');

            // Minor units, like every AUB amount: the sum of the line items, computed on creation.
            $table->unsignedBigInteger('amount');

            $table->json('line_items');
            $table->text('description')->nullable();
            $table->json('billing')->nullable();
            $table->json('payment_methods');
            $table->json('metadata')->nullable();
            $table->text('success_url');
            $table->text('cancel_url');

            // The method that paid, once one has.
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('aub_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('checkout_session_id', 32)->index();

            // Which button, and which rail's events settle it.
            $table->string('method', 32);
            $table->string('rail', 16);

            // Our id for this payment at AUB — out_trade_no on the wallet rail, orderId on the
            // cashier one. Unique, because every notification is resolved through it.
            $table->string('order_id', 64)->unique();

            $table->unsignedBigInteger('amount');
            $table->string('status', 16)->default('pending');

            // AUB's own transaction id, once there is one, and QR Ph's invoice number, without
            // which a QR Ph order cannot be queried.
            $table->string('gateway_reference', 64)->nullable();
            $table->string('invoice_id', 32)->nullable();

            // What the customer was sent to or shown: a cashier page, or a QR payload and image.
            $table->text('redirect_url')->nullable();
            $table->text('qr_code')->nullable();
            $table->text('qr_image_url')->nullable();

            $table->timestamp('expires_at')->nullable();
            // When the page last asked AUB about this attempt, so polling cannot turn into a flood.
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('checkout_session_id')
                ->references('id')
                ->on('aub_checkout_sessions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aub_checkout_attempts');
        Schema::dropIfExists('aub_checkout_sessions');
    }
};
