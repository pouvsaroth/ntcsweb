<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            $table->string('domain')->nullable()->unique();

            $table->string('logo')->nullable();

            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->string('address')->nullable();

            $table->string('timezone')->default('Asia/Phnom_Penh');

            $table->string('status')->default('active');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};