<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            // sha256 of the request, so a key can't be replayed with a different body.
            $table->string('request_hash', 64)->nullable();

            // The HTTP status to replay alongside the stored body.
            $table->unsignedSmallInteger('response_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn(['request_hash', 'response_code']);
        });
    }
};
