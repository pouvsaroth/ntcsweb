<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Marketing > Broadcast: a promotion message sent to the
 * school's linked Telegram groups, with how each group went (results).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->foreignId('marketing_campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->string('status', 20); // sent | partial | failed
            $table->json('results'); // [{group_id, group_name, ok, message}]
            $table->timestamp('sent_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index('sent_at');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'marketing_broadcasts');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_broadcasts');
    }
};
