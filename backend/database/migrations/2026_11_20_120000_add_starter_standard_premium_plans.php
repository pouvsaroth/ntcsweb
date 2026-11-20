<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The school system's plan lineup (owner, 2026-11-20), shown on the website's
 * plans page with its Monthly / Yearly switch:
 *  - Starter  $19 / month, $190 / year: Academics, Finance, Front Office; 300 students.
 *  - Standard $49 / month, $490 / year (Recommended): + Accounting, HRM, Payroll,
 *    Website, Marketing; 1,000 students.
 *  - Premium  $99 / month, $990 / year: every module; no student limit.
 * A yearly plan is 10 months' price for 12 months.
 *
 * Only adds a plan whose code is not there yet, so editing them later in
 * back office → Billing → Plans is never undone. The only change to other
 * plans: Standard becomes the one Recommended plan of its period.
 */
return new class extends Migration
{
    private const STARTER = ['academics', 'finance', 'front-office'];

    private const STANDARD = [...self::STARTER, 'accounting', 'hrm', 'payroll', 'website', 'marketing'];

    public function up(): void
    {
        // Tests build their own plans; the lineup test calls addPlans() itself.
        if (app()->runningUnitTests()) {
            return;
        }
        $this->addPlans();
    }

    public function addPlans(): void
    {
        $product = DB::table('bo_products')->where('code', 'school')->first(['id', 'modules']);
        if ($product === null) {
            return;
        }
        $premium = array_values(json_decode((string) $product->modules, true) ?: []);

        $tiers = [
            'starter' => [
                'name' => 'Starter', 'name_km' => 'កញ្ចប់ចាប់ផ្តើម', 'price' => 19, 'max' => 300, 'modules' => self::STARTER, 'recommended' => false,
                'tagline_en' => 'For small schools and tutoring centres', 'tagline_km' => 'សម្រាប់សាលាតូច និងមជ្ឈមណ្ឌលបង្រៀនគួរ',
                'features_en' => ['Students, classes and enrollment', 'Attendance and exams', 'Invoices and payments', 'Front office: inquiries and visitors', 'Up to 300 students'],
                'features_km' => ['សិស្ស ថ្នាក់ និងការចុះឈ្មោះ', 'វត្តមាន និងការប្រឡង', 'វិក្កយបត្រ និងការបង់ប្រាក់', 'ការិយាល័យខាងមុខ៖ ការសាកសួរ និងភ្ញៀវ', 'រហូតដល់សិស្ស ៣០០ នាក់'],
            ],
            'standard' => [
                'name' => 'Standard', 'name_km' => 'កញ្ចប់ស្តង់ដារ', 'price' => 49, 'max' => 1000, 'modules' => self::STANDARD, 'recommended' => true,
                'tagline_en' => 'For most schools', 'tagline_km' => 'សម្រាប់សាលាភាគច្រើន',
                'features_en' => ['Everything in Starter', 'Accounting and reports', 'Staff, HR and payroll', 'School website', 'Marketing campaigns', 'Up to 1,000 students'],
                'features_km' => ['អ្វីៗទាំងអស់ក្នុងកញ្ចប់ចាប់ផ្តើម', 'គណនេយ្យ និងរបាយការណ៍', 'បុគ្គលិក ធនធានមនុស្ស និងប្រាក់បៀវត្សរ៍', 'គេហទំព័រសាលា', 'យុទ្ធនាការទីផ្សារ', 'រហូតដល់សិស្ស ១ ០០០ នាក់'],
            ],
            'premium' => [
                'name' => 'Premium', 'name_km' => 'កញ្ចប់ពិសេស', 'price' => 99, 'max' => null, 'modules' => $premium, 'recommended' => false,
                'tagline_en' => 'For large schools and school groups', 'tagline_km' => 'សម្រាប់សាលាធំ និងក្រុមសាលា',
                'features_en' => ['Everything in Standard', 'Assets', 'Projects', 'Loans and funds', 'Unlimited students'],
                'features_km' => ['អ្វីៗទាំងអស់ក្នុងកញ្ចប់ស្តង់ដារ', 'ទ្រព្យសម្បត្តិ', 'គម្រោង', 'កម្ចី និងមូលនិធិ', 'សិស្សមិនកំណត់ចំនួន'],
            ],
        ];

        $order = 1;
        foreach ([['monthly', 1, 1], ['yearly', 12, 10]] as [$suffix, $months, $priceMonths]) {
            foreach ($tiers as $tier => $p) {
                $code = "{$tier}-{$suffix}";
                $websiteOrder = $order++;
                if (DB::table('bo_plans')->where('code', $code)->exists()) {
                    continue;
                }
                $yearly = $months === 12;
                if ($p['recommended']) {
                    // One Recommended plan per system and period.
                    DB::table('bo_plans')->where('product_id', $product->id)->where('period_months', $months)->update(['recommended' => false]);
                }
                DB::table('bo_plans')->insert([
                    'code' => $code,
                    'name' => $p['name'],
                    'name_km' => $p['name_km'],
                    'description' => null,
                    'price' => $p['price'] * $priceMonths,
                    'currency' => 'USD',
                    'period_months' => $months,
                    'max_students' => $p['max'],
                    'modules' => json_encode($p['modules']),
                    'menus' => null,
                    'product_id' => $product->id,
                    'placement' => 'shared',
                    'status' => 'active',
                    'show_on_website' => true,
                    'recommended' => $p['recommended'],
                    'website_order' => $websiteOrder,
                    'tagline_en' => $p['tagline_en'],
                    'tagline_km' => $p['tagline_km'],
                    'features_en' => implode("\n", [...$p['features_en'], ...($yearly ? ['2 months free'] : [])]),
                    'features_km' => implode("\n", [...$p['features_km'], ...($yearly ? ['ឥតគិតថ្លៃ ២ ខែ'] : [])]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only plans no school has been on.
        foreach (['starter', 'standard', 'premium'] as $tier) {
            foreach (['monthly', 'yearly'] as $suffix) {
                $id = DB::table('bo_plans')->where('code', "{$tier}-{$suffix}")->value('id');
                if ($id !== null && ! DB::table('bo_school_subscriptions')->where('plan_id', $id)->exists()) {
                    DB::table('bo_plans')->where('id', $id)->delete();
                }
            }
        }
    }
};
