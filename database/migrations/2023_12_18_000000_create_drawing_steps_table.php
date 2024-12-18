<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('drawing_steps', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->timestamp('timestamp');
            $table->integer('step');
            $table->json('content');
            $table->string('status'); // 'active' or 'undone'
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            // Index for faster queries
            $table->index(['session_id', 'step']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('drawing_steps');
    }
};
