<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of customer a tenant is: School, Company, Organization, Ministry…
 * Each type keeps its own word for "school" in every language, and the admin
 * screens swap that word in after sign-in. A tenant with no type is a School.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_organization_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            // {en, en_plural, km, zh, ja, ko}
            $table->json('words');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        $now = now();
        $types = [
            ['school', ['en' => 'School', 'en_plural' => 'Schools', 'km' => 'សាលា', 'zh' => '学校', 'ja' => '学校', 'ko' => '학교']],
            ['company', ['en' => 'Company', 'en_plural' => 'Companies', 'km' => 'ក្រុមហ៊ុន', 'zh' => '公司', 'ja' => '会社', 'ko' => '회사']],
            ['organization', ['en' => 'Organization', 'en_plural' => 'Organizations', 'km' => 'អង្គការ', 'zh' => '机构', 'ja' => '組織', 'ko' => '기관']],
            ['ministry', ['en' => 'Ministry', 'en_plural' => 'Ministries', 'km' => 'ក្រសួង', 'zh' => '部委', 'ja' => '省庁', 'ko' => '부처']],
        ];
        foreach ($types as $i => [$code, $words]) {
            DB::table('bo_organization_types')->insert([
                'code' => $code, 'words' => json_encode($words, JSON_UNESCAPED_UNICODE), 'sort_order' => ($i + 1) * 10,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('organization_type_id')->nullable()->constrained('bo_organization_types')->nullOnDelete();
        });
        Schema::table('bo_signups', function (Blueprint $table) {
            $table->foreignId('organization_type_id')->nullable()->constrained('bo_organization_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bo_signups', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_type_id'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_type_id'));
        Schema::dropIfExists('bo_organization_types');
    }
};
