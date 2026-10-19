<?php

/*
|--------------------------------------------------------------------------
| The back office — see docs/backoffice/
|--------------------------------------------------------------------------
|
| Platform permissions are a catalogue in code, not rows: a key only means
| something if a route checks it, and every route that checks one is in this
| repository. Leaves are what a role holds; groups exist for the tree editor.
| Every key starts with `platform.` — EnsureStaffPermission refuses anything
| else.
|
*/

return [

    'permissions' => [
        [
            'key' => 'platform.staff',
            'label' => 'Staff',
            'label_km' => 'បុគ្គលិកវេទិកា',
            'children' => [
                ['key' => 'platform.staff.read', 'label' => 'View staff', 'label_km' => 'មើលបុគ្គលិក'],
                ['key' => 'platform.staff.write', 'label' => 'Add and change staff', 'label_km' => 'បន្ថែម និងកែប្រែបុគ្គលិក'],
            ],
        ],
        [
            'key' => 'platform.role',
            'label' => 'Roles',
            'label_km' => 'តួនាទី',
            'children' => [
                ['key' => 'platform.role.read', 'label' => 'View roles', 'label_km' => 'មើលតួនាទី'],
                ['key' => 'platform.role.write', 'label' => 'Add and change roles', 'label_km' => 'បន្ថែម និងកែប្រែតួនាទី'],
            ],
        ],
        [
            'key' => 'platform.school',
            'label' => 'Schools',
            'label_km' => 'សាលារៀន',
            'children' => [
                ['key' => 'platform.school.read', 'label' => 'View schools', 'label_km' => 'មើលសាលារៀន'],
                ['key' => 'platform.school.write', 'label' => 'Create and manage schools', 'label_km' => 'បង្កើត និងគ្រប់គ្រងសាលារៀន'],
                ['key' => 'platform.school.status', 'label' => 'Suspend, reactivate and archive schools', 'label_km' => 'ផ្អាក បើកវិញ និងរក្សាទុកសាលារៀន'],
                ['key' => 'platform.school.placement', 'label' => 'Move school data between placements', 'label_km' => 'ផ្លាស់ទីទិន្នន័យសាលារវាងទីតាំង'],
            ],
        ],
        [
            'key' => 'platform.data',
            'label' => 'Data ops',
            'label_km' => 'ប្រតិបត្តិការទិន្នន័យ',
            'children' => [
                ['key' => 'platform.data.read', 'label' => 'See databases, backups and health', 'label_km' => 'មើលមូលដ្ឋានទិន្នន័យ ការបម្រុងទុក និងសុខភាពប្រព័ន្ធ'],
                ['key' => 'platform.data.backup', 'label' => 'Run, download and schedule backups', 'label_km' => 'ដំណើរការ ទាញយក និងកំណត់ពេលបម្រុងទុក'],
                ['key' => 'platform.data.migrate', 'label' => 'Migrate schools and create missing databases', 'label_km' => 'ធ្វើបច្ចុប្បន្នភាពសាលា និងបង្កើតមូលដ្ឋានទិន្នន័យដែលបាត់'],
            ],
        ],
        [
            'key' => 'platform.billing',
            'label' => 'Billing',
            'label_km' => 'វិក្កយបត្រ',
            'children' => [
                ['key' => 'platform.billing.read', 'label' => 'View plans, subscriptions and payments', 'label_km' => 'មើលគម្រោង ការជាវ និងការបង់ប្រាក់'],
                ['key' => 'platform.billing.manage', 'label' => 'Change plans, subscriptions, payments and billing settings', 'label_km' => 'កែប្រែគម្រោង ការជាវ ការបង់ប្រាក់ និងការកំណត់វិក្កយបត្រ'],
            ],
        ],
        [
            'key' => 'platform.support',
            'label' => 'Support',
            'label_km' => 'ជំនួយ',
            'children' => [
                ['key' => 'platform.support.read', 'label' => 'See school health and support views', 'label_km' => 'មើលសុខភាពសាលា និងការមើលជំនួយ'],
                ['key' => 'platform.support.view', 'label' => 'Open and end support views of a school', 'label_km' => 'បើក និងបញ្ចប់ការមើលជំនួយរបស់សាលា'],
            ],
        ],
        [
            'key' => 'platform.security',
            'label' => 'Security',
            'label_km' => 'សុវត្ថិភាព',
            'children' => [
                ['key' => 'platform.security.manage', 'label' => 'See and change sign-in security (authenticator code)', 'label_km' => 'មើល និងកែប្រែសុវត្ថិភាពការចូល (លេខកូដកម្មវិធីផ្ទៀងផ្ទាត់)'],
            ],
        ],
        [
            'key' => 'platform.audit',
            'label' => 'Audit',
            'label_km' => 'សវនកម្ម',
            'children' => [
                ['key' => 'platform.audit.read', 'label' => 'View the audit log', 'label_km' => 'មើលកំណត់ហេតុសវនកម្ម'],
            ],
        ],
    ],

    // Shown in the authenticator app beside the account name.
    'two_factor_issuer' => env('PLATFORM_TWO_FACTOR_ISSUER', 'NTCSWEB Back office'),

    // Slugs a school can never take: each becomes `<slug>.<root domain>` and
    // the database `tn_<slug>`, so platform hostnames and words that read as
    // ours are kept back.
    'reserved_slugs' => [
        'admin', 'api', 'app', 'bo', 'backoffice', 'cdn', 'dev', 'docs', 'erp', 'ftp', 'help',
        'login', 'mail', 'ns1', 'ns2', 'platform', 'school', 'smtp', 'staging', 'static',
        'status', 'support', 'test', 'www',
    ],

    // Where a school's custom domain should point (the CNAME target shown on
    // the school's Domains tab).
    'domain_target' => env('PLATFORM_DOMAIN_TARGET', 'schools.ntcsweb.com'),

    // Backups (Stage 3). Where they are written, and the optional second copy:
    // a disk name from config/filesystems.php, or empty for none.
    'backup_disk' => 'backups',
    'backup_copy_disk' => env('PLATFORM_BACKUP_COPY_DISK') ?: null,
    'backup_timezone' => 'Asia/Phnom_Penh',

    // The console — where every school's users sign in. Invite links point here.
    'console_url' => env('PLATFORM_CONSOLE_URL', 'https://erp.newtechkh.com'),

    // Where a new school's data lives unless staff choose otherwise (as SmartGPS).
    'default_placement' => env('PLATFORM_DEFAULT_PLACEMENT', 'shared'),

    // Billing (Stage 5): the zone a subscription's dates are read in.
    'billing_timezone' => 'Asia/Phnom_Penh',

    // How long an owner's invite link stays good.
    'invite_days' => 7,

    // How long a correct password keeps the second step open.
    'pending_login_minutes' => 5,

];
