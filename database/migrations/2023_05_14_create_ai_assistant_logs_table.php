<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiAssistantLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('a_i_assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('request_type')->nullable(); // voice_order, text_order, etc.
            $table->text('request_content')->nullable(); // The audio transcription or text input
            $table->text('response_content')->nullable(); // The AI response
            $table->boolean('successful')->default(false); // Whether the request was successful
            $table->integer('processing_time_ms')->nullable(); // Processing time in milliseconds
            $table->json('metadata')->nullable(); // Additional metadata (product recommendations, etc.)
            $table->boolean('is_feedback_provided')->default(false);
            $table->boolean('was_helpful')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('a_i_assistant_logs');
    }
} 