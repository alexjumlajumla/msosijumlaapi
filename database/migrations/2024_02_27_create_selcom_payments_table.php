<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('selcom_payments', function (Blueprint $table) {
            $table->id();
            $table->string('transid')->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->decimal('amount', 20, 2);
            $table->string('status');
            $table->json('payment_data')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('selcom_payments');
    }
};
