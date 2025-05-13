<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_type')->comment('Type of AI request (voice, text, etc)');
            $table->text('input')->nullable()->comment('User input');
            $table->text('output')->nullable()->comment('AI response');
            $table->json('filters_detected')->nullable()->comment('Filters detected by AI');
            $table->json('product_ids')->nullable()->comment('Products recommended');
            $table->boolean('successful')->default(true)->comment('Whether request was successful');
            $table->integer('processing_time_ms')->nullable()->comment('Processing time in milliseconds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_logs');
    }
}; 