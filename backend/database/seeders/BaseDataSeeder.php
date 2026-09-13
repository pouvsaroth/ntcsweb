<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\LookupValueTranslation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Idempotent — safe to run on every deploy, the same way RolePermissionSeeder
 * is. Every tenant gets the same starter set of categories/values (a school
 * can add its own on top, or deactivate one it doesn't want — see
 * LookupCategory's own docblock for why this is tenant-owned, not a fixed
 * platform enum). Re-running never overwrites a code/name a school has
 * already customised: `firstOrCreate` on the category/value, and
 * translations are only ever inserted, never updated, once they exist.
 */
class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $languagesByCode = Language::query()->get()->keyBy('code');

        Tenant::query()->withoutGlobalScopes()->chunkById(50, function ($tenants) use ($languagesByCode) {
            foreach ($tenants as $tenant) {
                $this->seedForTenant($tenant, $languagesByCode);
            }
        });
    }

    /**
     * `lookup_categories`/`lookup_values`/`lookup_value_translations` live
     * in the tenant database — runFor() only sets the logical TenantContext,
     * it never points the `tenant` connection at this tenant's actual
     * database. A seeder looping over every tenant is exactly the
     * long-lived, multi-tenant process ResolveTenant's own docblock warns
     * about, so that has to happen explicitly here — the same call
     * ResolveTenant/ProcessStudentImport make. Skipped under the test
     * runner for the same reason those skip it.
     *
     * @param  \Illuminate\Support\Collection<string, Language>  $languagesByCode
     */
    private function seedForTenant(Tenant $tenant, $languagesByCode): void
    {
        $tenantDatabases = app(DatabaseManager::class);
        if (! app()->environment('testing')) {
            $tenantDatabases->createTenantConnection($tenant);
        }

        try {
            app(TenantContext::class)->runFor($tenant, function () use ($languagesByCode) {
                foreach ($this->categories() as $categoryCode => $categoryData) {
                    // withTrashed(): a school deactivating/removing one of
                    // these (see class docblock) soft-deletes it, but
                    // (lookup_category_id, code) stays a plain, non-partial
                    // unique index — without withTrashed() here, the SELECT
                    // half of firstOrCreate can't see that row and tries to
                    // INSERT a duplicate, which the constraint then rejects.
                    $category = LookupCategory::withTrashed()->firstOrCreate(
                        ['code' => $categoryCode],
                        [
                            'name' => $categoryData['name'],
                            'description' => $categoryData['description'] ?? null,
                            'sort_order' => $categoryData['sort_order'] ?? 0,
                        ],
                    );

                    $sortOrder = 0;
                    foreach ($categoryData['values'] as $valueCode => $translations) {
                        $value = LookupValue::withTrashed()->firstOrCreate(
                            ['lookup_category_id' => $category->getKey(), 'code' => $valueCode],
                            ['sort_order' => $sortOrder],
                        );

                        foreach ($translations as $languageCode => $fields) {
                            $language = $languagesByCode->get($languageCode);
                            if ($language === null) {
                                continue;
                            }

                            LookupValueTranslation::query()->firstOrCreate(
                                ['lookup_value_id' => $value->getKey(), 'language_id' => $language->getKey()],
                                ['name' => $fields['name'], 'description' => $fields['description'] ?? null],
                            );
                        }

                        $sortOrder++;
                    }
                }
            });
        } finally {
            if (! app()->environment('testing')) {
                $tenantDatabases->purgeTenantConnection();
            }
        }
    }

    /**
     * @return array<string, array{name:string, description?:string, sort_order?:int, values: array<string, array<string, array{name:string, description?:string}>>}>
     */
    private function categories(): array
    {
        return [
            'GENDER' => [
                'name' => 'Gender',
                'description' => 'Gender options used across Student, Staff, and Teacher records.',
                'sort_order' => 0,
                'values' => [
                    'male' => [
                        'en' => ['name' => 'Male'], 'km' => ['name' => 'ប្រុស'], 'zh' => ['name' => '男'],
                        'ko' => ['name' => '남성'], 'ja' => ['name' => '男性'],
                    ],
                    'female' => [
                        'en' => ['name' => 'Female'], 'km' => ['name' => 'ស្រី'], 'zh' => ['name' => '女'],
                        'ko' => ['name' => '여성'], 'ja' => ['name' => '女性'],
                    ],
                    'other' => [
                        'en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'],
                        'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他'],
                    ],
                ],
            ],
            'GUARDIAN_TYPE' => [
                'name' => 'Guardian Type',
                'description' => 'How a guardian is related to a student.',
                'sort_order' => 1,
                'values' => [
                    'FATHER' => ['en' => ['name' => 'Father'], 'km' => ['name' => 'ឪពុក'], 'zh' => ['name' => '父亲'], 'ko' => ['name' => '아버지'], 'ja' => ['name' => '父']],
                    'MOTHER' => ['en' => ['name' => 'Mother'], 'km' => ['name' => 'ម្តាយ'], 'zh' => ['name' => '母亲'], 'ko' => ['name' => '어머니'], 'ja' => ['name' => '母']],
                    'BROTHER' => ['en' => ['name' => 'Brother'], 'km' => ['name' => 'បងប្អូនប្រុស'], 'zh' => ['name' => '兄弟'], 'ko' => ['name' => '형제'], 'ja' => ['name' => '兄弟']],
                    'SISTER' => ['en' => ['name' => 'Sister'], 'km' => ['name' => 'បងប្អូនស្រី'], 'zh' => ['name' => '姐妹'], 'ko' => ['name' => '자매'], 'ja' => ['name' => '姉妹']],
                    'UNCLE' => ['en' => ['name' => 'Uncle'], 'km' => ['name' => 'ពូ/មា'], 'zh' => ['name' => '叔叔'], 'ko' => ['name' => '삼촌'], 'ja' => ['name' => 'おじ']],
                    'AUNT' => ['en' => ['name' => 'Aunt'], 'km' => ['name' => 'មីង'], 'zh' => ['name' => '阿姨'], 'ko' => ['name' => '이모'], 'ja' => ['name' => 'おば']],
                    'GRANDPARENT' => ['en' => ['name' => 'Grandparent'], 'km' => ['name' => 'ជីដូនជីតា'], 'zh' => ['name' => '祖父母'], 'ko' => ['name' => '조부모'], 'ja' => ['name' => '祖父母']],
                    'OTHER' => ['en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'], 'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他']],
                ],
            ],
            'BOOK_TYPE' => [
                'name' => 'Book Type',
                'description' => 'The kind of material a book/textbook catalog entry is.',
                'sort_order' => 2,
                'values' => [
                    'TEXTBOOK' => ['en' => ['name' => 'Textbook'], 'km' => ['name' => 'សៀវភៅសិក្សា'], 'zh' => ['name' => '教科书'], 'ko' => ['name' => '교과서'], 'ja' => ['name' => '教科書']],
                    'WORKBOOK' => ['en' => ['name' => 'Workbook'], 'km' => ['name' => 'សៀវភៅលំហាត់'], 'zh' => ['name' => '练习册'], 'ko' => ['name' => '워크북'], 'ja' => ['name' => 'ワークブック']],
                    'REFERENCE' => ['en' => ['name' => 'Reference'], 'km' => ['name' => 'សៀវភៅយោង'], 'zh' => ['name' => '参考书'], 'ko' => ['name' => '참고서'], 'ja' => ['name' => '参考書']],
                    'PRACTICE_BOOK' => ['en' => ['name' => 'Practice Book'], 'km' => ['name' => 'សៀវភៅអនុវត្ត'], 'zh' => ['name' => '练习本'], 'ko' => ['name' => '연습서'], 'ja' => ['name' => '練習帳']],
                    'OTHER' => ['en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'], 'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他']],
                ],
            ],
            'PAYMENT_METHOD' => [
                'name' => 'Payment Method',
                'description' => 'Reference list of payment methods a school accepts — kept in sync by name only with App\\Support\\Billing\\PaymentMethod; billing logic itself still reads that Support class, not this table.',
                'sort_order' => 3,
                'values' => [
                    'CASH' => ['en' => ['name' => 'Cash'], 'km' => ['name' => 'សាច់ប្រាក់'], 'zh' => ['name' => '现金'], 'ko' => ['name' => '현금'], 'ja' => ['name' => '現金']],
                    'BANK' => ['en' => ['name' => 'Bank'], 'km' => ['name' => 'ធនាគារ'], 'zh' => ['name' => '银行'], 'ko' => ['name' => '은행'], 'ja' => ['name' => '銀行']],
                    'ABA' => ['en' => ['name' => 'ABA'], 'km' => ['name' => 'ABA'], 'zh' => ['name' => 'ABA'], 'ko' => ['name' => 'ABA'], 'ja' => ['name' => 'ABA']],
                    'ACLEDA' => ['en' => ['name' => 'ACLEDA'], 'km' => ['name' => 'ACLEDA'], 'zh' => ['name' => 'ACLEDA'], 'ko' => ['name' => 'ACLEDA'], 'ja' => ['name' => 'ACLEDA']],
                    'WING' => ['en' => ['name' => 'Wing'], 'km' => ['name' => 'Wing'], 'zh' => ['name' => 'Wing'], 'ko' => ['name' => 'Wing'], 'ja' => ['name' => 'Wing']],
                    'OTHER' => ['en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'], 'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他']],
                ],
            ],
            'STUDENT_STATUS' => [
                'name' => 'Student Status',
                'description' => 'Reference list mirroring App\\Models\\Student::STATUS_* — the Student model itself still owns the real column/constants. Codes are lowercase to match the column value exactly (see GENDER); `pending` is deliberately excluded — it is a system-only state set by StudentRegistrationService, never manually selectable (see Store/UpdateStudentRequest).',
                'sort_order' => 4,
                'values' => [
                    'active' => ['en' => ['name' => 'Active'], 'km' => ['name' => 'សកម្ម'], 'zh' => ['name' => '在读'], 'ko' => ['name' => '재학'], 'ja' => ['name' => '在籍']],
                    'inactive' => ['en' => ['name' => 'Inactive'], 'km' => ['name' => 'អសកម្ម'], 'zh' => ['name' => '停用'], 'ko' => ['name' => '비활성'], 'ja' => ['name' => '非アクティブ']],
                    'graduated' => ['en' => ['name' => 'Graduated'], 'km' => ['name' => 'បញ្ចប់ការសិក្សា'], 'zh' => ['name' => '毕业'], 'ko' => ['name' => '졸업'], 'ja' => ['name' => '卒業']],
                    'withdrawn' => ['en' => ['name' => 'Withdrawn'], 'km' => ['name' => 'ដកខ្លួន'], 'zh' => ['name' => '退学'], 'ko' => ['name' => '자퇴'], 'ja' => ['name' => '退学']],
                ],
            ],
            'ENROLLMENT_STATUS' => [
                'name' => 'Enrollment Status',
                'description' => 'Reference list mirroring App\\Models\\Enrollment::STATUSES_MANAGEABLE — the Enrollment model itself still owns the real column/constants. Codes are lowercase to match the column value exactly (see GENDER); `dropped` is deliberately excluded — it only ever happens as a side effect of EnrollmentService::cancel()/transferClass(), never manually chosen.',
                'sort_order' => 5,
                'values' => [
                    'not_started' => ['en' => ['name' => 'Not Started'], 'km' => ['name' => 'មិនចាប់ផ្តើមចូលរៀន'], 'zh' => ['name' => '尚未开始'], 'ko' => ['name' => '시작 전'], 'ja' => ['name' => '未開始']],
                    'active' => ['en' => ['name' => 'Studying'], 'km' => ['name' => 'កំពុង់សិក្សា'], 'zh' => ['name' => '学习中'], 'ko' => ['name' => '수강중'], 'ja' => ['name' => '受講中']],
                    'exam_ready' => ['en' => ['name' => 'Ready for Exam'], 'km' => ['name' => 'ត្រៀមប្រឡង'], 'zh' => ['name' => '备考中'], 'ko' => ['name' => '시험 준비중'], 'ja' => ['name' => '試験準備中']],
                    'completed' => ['en' => ['name' => 'Completed'], 'km' => ['name' => 'បញ្ចប់ការសិក្សា'], 'zh' => ['name' => '已完成'], 'ko' => ['name' => '완료'], 'ja' => ['name' => '完了']],
                    'abandoned' => ['en' => ['name' => 'Abandoned'], 'km' => ['name' => 'លះបង់ការសិក្សា'], 'zh' => ['name' => '已放弃'], 'ko' => ['name' => '포기'], 'ja' => ['name' => '放棄']],
                    'stopped' => ['en' => ['name' => 'Stopped'], 'km' => ['name' => 'បញ្ឈប់ការសិក្សា'], 'zh' => ['name' => '已停止'], 'ko' => ['name' => '중단'], 'ja' => ['name' => '中止']],
                    'suspended' => ['en' => ['name' => 'Suspended'], 'km' => ['name' => 'ផ្អាកការសិក្សា'], 'zh' => ['name' => '已暂停'], 'ko' => ['name' => '휴학중'], 'ja' => ['name' => '休学中']],
                ],
            ],
            'STAFF_TYPE' => [
                'name' => 'Staff Type',
                'description' => 'General staffing category, for reports/filters.',
                'sort_order' => 6,
                'values' => [
                    'TEACHING' => ['en' => ['name' => 'Teaching'], 'km' => ['name' => 'បង្រៀន'], 'zh' => ['name' => '教学'], 'ko' => ['name' => '교직'], 'ja' => ['name' => '教育']],
                    'ADMINISTRATIVE' => ['en' => ['name' => 'Administrative'], 'km' => ['name' => 'រដ្ឋបាល'], 'zh' => ['name' => '行政'], 'ko' => ['name' => '행정'], 'ja' => ['name' => '事務']],
                    'SUPPORT' => ['en' => ['name' => 'Support'], 'km' => ['name' => 'គាំទ្រ'], 'zh' => ['name' => '支持'], 'ko' => ['name' => '지원'], 'ja' => ['name' => 'サポート']],
                    'MANAGEMENT' => ['en' => ['name' => 'Management'], 'km' => ['name' => 'គ្រប់គ្រង'], 'zh' => ['name' => '管理'], 'ko' => ['name' => '관리'], 'ja' => ['name' => '管理']],
                ],
            ],
            'RELATIONSHIP_TYPE' => [
                'name' => 'Relationship Type',
                'description' => 'General person-to-person relationship, for emergency contacts and similar fields.',
                'sort_order' => 7,
                'values' => [
                    'SPOUSE' => ['en' => ['name' => 'Spouse'], 'km' => ['name' => 'ប្តី/ប្រពន្ធ'], 'zh' => ['name' => '配偶'], 'ko' => ['name' => '배우자'], 'ja' => ['name' => '配偶者']],
                    'PARENT' => ['en' => ['name' => 'Parent'], 'km' => ['name' => 'ឪពុកម្តាយ'], 'zh' => ['name' => '父母'], 'ko' => ['name' => '부모'], 'ja' => ['name' => '親']],
                    'CHILD' => ['en' => ['name' => 'Child'], 'km' => ['name' => 'កូន'], 'zh' => ['name' => '孩子'], 'ko' => ['name' => '자녀'], 'ja' => ['name' => '子供']],
                    'SIBLING' => ['en' => ['name' => 'Sibling'], 'km' => ['name' => 'បងប្អូន'], 'zh' => ['name' => '兄弟姐妹'], 'ko' => ['name' => '형제자매'], 'ja' => ['name' => '兄弟姉妹']],
                    'RELATIVE' => ['en' => ['name' => 'Relative'], 'km' => ['name' => 'សាច់ញាតិ'], 'zh' => ['name' => '亲戚'], 'ko' => ['name' => '친척'], 'ja' => ['name' => '親戚']],
                    'FRIEND' => ['en' => ['name' => 'Friend'], 'km' => ['name' => 'មិត្តភក្តិ'], 'zh' => ['name' => '朋友'], 'ko' => ['name' => '친구'], 'ja' => ['name' => '友人']],
                    'OTHER' => ['en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'], 'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他']],
                ],
            ],
            'ASSET_STATUS' => [
                'name' => 'Asset Status',
                'description' => 'Reference list mirroring the Asset module\'s own status concept — the Asset model itself still owns the real column/logic.',
                'sort_order' => 8,
                'values' => [
                    'ACTIVE' => ['en' => ['name' => 'Active'], 'km' => ['name' => 'សកម្ម'], 'zh' => ['name' => '使用中'], 'ko' => ['name' => '사용중'], 'ja' => ['name' => '使用中']],
                    'IN_REPAIR' => ['en' => ['name' => 'In Repair'], 'km' => ['name' => 'កំពុងជួសជុល'], 'zh' => ['name' => '维修中'], 'ko' => ['name' => '수리중'], 'ja' => ['name' => '修理中']],
                    'RETIRED' => ['en' => ['name' => 'Retired'], 'km' => ['name' => 'បានឈប់ប្រើ'], 'zh' => ['name' => '已淘汰'], 'ko' => ['name' => '퇴역'], 'ja' => ['name' => '退役']],
                    'LOST' => ['en' => ['name' => 'Lost'], 'km' => ['name' => 'បាត់បង់'], 'zh' => ['name' => '丢失'], 'ko' => ['name' => '분실'], 'ja' => ['name' => '紛失']],
                    'DISPOSED' => ['en' => ['name' => 'Disposed'], 'km' => ['name' => 'បោះបង់'], 'zh' => ['name' => '已处置'], 'ko' => ['name' => '폐기'], 'ja' => ['name' => '廃棄']],
                ],
            ],
            'EDUCATION_LEVEL' => [
                'name' => 'Education Level',
                'description' => 'Highest education level, for Staff/Teacher qualification records.',
                'sort_order' => 9,
                'values' => [
                    'PRIMARY' => ['en' => ['name' => 'Primary'], 'km' => ['name' => 'បឋមសិក្សា'], 'zh' => ['name' => '小学'], 'ko' => ['name' => '초등학교'], 'ja' => ['name' => '小学校']],
                    'SECONDARY' => ['en' => ['name' => 'Secondary'], 'km' => ['name' => 'មធ្យមសិក្សា'], 'zh' => ['name' => '中学'], 'ko' => ['name' => '중학교'], 'ja' => ['name' => '中学校']],
                    'HIGH_SCHOOL' => ['en' => ['name' => 'High School'], 'km' => ['name' => 'វិទ្យាល័យ'], 'zh' => ['name' => '高中'], 'ko' => ['name' => '고등학교'], 'ja' => ['name' => '高校']],
                    'BACHELOR' => ['en' => ['name' => 'Bachelor'], 'km' => ['name' => 'បរិញ្ញាបត្រ'], 'zh' => ['name' => '学士'], 'ko' => ['name' => '학사'], 'ja' => ['name' => '学士']],
                    'MASTER' => ['en' => ['name' => 'Master'], 'km' => ['name' => 'អនុបណ្ឌិត'], 'zh' => ['name' => '硕士'], 'ko' => ['name' => '석사'], 'ja' => ['name' => '修士']],
                    'DOCTORATE' => ['en' => ['name' => 'Doctorate'], 'km' => ['name' => 'បណ្ឌិត'], 'zh' => ['name' => '博士'], 'ko' => ['name' => '박사'], 'ja' => ['name' => '博士']],
                    'OTHER' => ['en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'], 'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他']],
                ],
            ],
            'DISCOUNT_REASON' => [
                'name' => 'Discount Reason',
                'description' => 'Why an invoice was discounted — used by the enrollment payment panel and available for any other invoice discount.',
                'sort_order' => 10,
                'values' => [
                    'SIBLING' => ['en' => ['name' => 'Sibling Discount'], 'km' => ['name' => 'បញ្ចុះតម្លៃបងប្អូន'], 'zh' => ['name' => '兄弟姐妹折扣'], 'ko' => ['name' => '형제자매 할인'], 'ja' => ['name' => '兄弟姉妹割引']],
                    'STAFF' => ['en' => ['name' => 'Staff Discount'], 'km' => ['name' => 'បញ្ចុះតម្លៃបុគ្គលិក'], 'zh' => ['name' => '员工折扣'], 'ko' => ['name' => '직원 할인'], 'ja' => ['name' => '職員割引']],
                    'EARLY_BIRD' => ['en' => ['name' => 'Early Bird'], 'km' => ['name' => 'ចុះឈ្មោះមុនគេ'], 'zh' => ['name' => '早鸟优惠'], 'ko' => ['name' => '얼리버드 할인'], 'ja' => ['name' => '早期割引']],
                    'SCHOLARSHIP' => ['en' => ['name' => 'Scholarship'], 'km' => ['name' => 'អាហារូបករណ៍'], 'zh' => ['name' => '奖学金'], 'ko' => ['name' => '장학금'], 'ja' => ['name' => '奨学金']],
                    'OTHER' => ['en' => ['name' => 'Other'], 'km' => ['name' => 'ផ្សេងទៀត'], 'zh' => ['name' => '其他'], 'ko' => ['name' => '기타'], 'ja' => ['name' => 'その他']],
                ],
            ],
            'STAFF_STATUS' => [
                'name' => 'Staff Status',
                'description' => 'Reference list mirroring App\\Models\\Staff::STATUS_* — the Staff model itself still owns the real column/constants.',
                'sort_order' => 11,
                'values' => [
                    'active' => ['en' => ['name' => 'Active'], 'km' => ['name' => 'កំពុងបម្រើការងារ'], 'zh' => ['name' => '在职'], 'ko' => ['name' => '재직중'], 'ja' => ['name' => '在職中']],
                    'probation' => ['en' => ['name' => 'Probation'], 'km' => ['name' => 'កំពុងសាកល្បងការងារ'], 'zh' => ['name' => '试用期'], 'ko' => ['name' => '수습'], 'ja' => ['name' => '試用期間']],
                    'on_leave' => ['en' => ['name' => 'On Leave'], 'km' => ['name' => 'កំពុងឈប់សម្រាក'], 'zh' => ['name' => '休假'], 'ko' => ['name' => '휴직'], 'ja' => ['name' => '休暇中']],
                    'suspended' => ['en' => ['name' => 'Suspended'], 'km' => ['name' => 'ត្រូវបានផ្អាកការងារ'], 'zh' => ['name' => '停职'], 'ko' => ['name' => '정직'], 'ja' => ['name' => '停職']],
                    'resigned' => ['en' => ['name' => 'Resigned'], 'km' => ['name' => 'បានលាលែងពីការងារ'], 'zh' => ['name' => '离职'], 'ko' => ['name' => '퇴사'], 'ja' => ['name' => '退職']],
                    'terminated' => ['en' => ['name' => 'Terminated'], 'km' => ['name' => 'ត្រូវបានបញ្ចប់ការងារ'], 'zh' => ['name' => '解雇'], 'ko' => ['name' => '해고'], 'ja' => ['name' => '解雇']],
                    'retired' => ['en' => ['name' => 'Retired'], 'km' => ['name' => 'ចូលនិវត្តន៍'], 'zh' => ['name' => '退休'], 'ko' => ['name' => '은퇴'], 'ja' => ['name' => '定年退職']],
                ],
            ],
            'STUDY_MODE' => [
                'name' => 'Study Mode',
                'description' => 'Replaces the old standalone study_modes table — Full Time / Part Time and anything a school adds later, managed like any other base-data category.',
                'sort_order' => 12,
                'values' => [
                    'FULL_TIME' => ['en' => ['name' => 'Full Time'], 'km' => ['name' => 'ពេញម៉ោង'], 'zh' => ['name' => '全日制'], 'ko' => ['name' => '전일제'], 'ja' => ['name' => '全日制']],
                    'PART_TIME' => ['en' => ['name' => 'Part Time'], 'km' => ['name' => 'ក្រៅម៉ោង'], 'zh' => ['name' => '兼职'], 'ko' => ['name' => '시간제'], 'ja' => ['name' => '非常勤']],
                ],
            ],
        ];
    }
}
