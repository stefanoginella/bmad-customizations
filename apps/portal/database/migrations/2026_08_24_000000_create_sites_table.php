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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('site_url')->unique();
            $table->string('home_url')->nullable();
            $table->string('rest_base')->nullable();
            $table->text('site_key');
            $table->string('site_key_hash', 64)->unique();
            $table->string('connector_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('last_report')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
