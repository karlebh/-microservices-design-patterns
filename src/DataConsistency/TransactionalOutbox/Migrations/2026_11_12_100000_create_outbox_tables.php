<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business table.
        Schema::create('outbox_orders', function (Blueprint $table) {
            $table->id();
            $table->string('customer');
            $table->unsignedInteger('total_cents');
            $table->string('status')->default('placed');
            $table->timestamps();
        });

        // The outbox. It lives in the SAME database as the business table,
        // which is the whole point: one local transaction covers both.
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();                          // insertion order == publish order
            $table->uuid('message_id')->unique();  // consumers dedupe on this
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('type');
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('outbox_orders');
    }
};
