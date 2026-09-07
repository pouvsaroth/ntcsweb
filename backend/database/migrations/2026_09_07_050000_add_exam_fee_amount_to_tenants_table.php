<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fixed fee an ExamApplication snapshots at submission time — see that
 * migration's docblock. Null means the school hasn't configured one yet;
 * ExamApplicationService::submit() refuses to accept an application in that
 * state rather than silently charging 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('exam_fee_amount', 10, 2)->nullable()->after('default_currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('exam_fee_amount');
        });
    }
};
