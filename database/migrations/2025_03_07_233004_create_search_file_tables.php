<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('query');
            $table->integer('query_total')->nullable();
        });

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('audio_path');
            $table->string('audio_filename');
            $table->string('transcription_path')->nullable();
            $table->integer('query_count')->nullable();
            $table->foreignId('search_id')->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('searches');
        Schema::dropIfExists('files');
    }
};
