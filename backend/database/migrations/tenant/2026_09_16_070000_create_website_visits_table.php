<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. One row per calendar day this school's public site was
 * visited, incremented once per visitor per day (deduped client-side via
 * localStorage — see WebsiteVisitStats::record()). Today/yesterday/week/
 * month/year figures are all summed from this single table rather than
 * counting individual hits, which keeps it small regardless of traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_visits', function (Blueprint $table) {
            $table->id();

            $table->date('visit_date')->unique();
            $table->unsignedInteger('visits')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_visits');
    }
};
