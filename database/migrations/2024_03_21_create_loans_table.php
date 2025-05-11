<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLoansTable extends Migration
{
    public function up()
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 20, 2);
            $table->decimal('interest_rate', 8, 2);
            $table->decimal('repayment_amount', 20, 2);
            $table->foreignId('disbursed_by')->constrained('users');
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('due_date');
            $table->enum('status', ['active', 'repaid', 'defaulted'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('loans');
    }
} 