<?php

/*
|--------------------------------------------------------------------------
| School portal access control (RBAC)
|--------------------------------------------------------------------------
|
| Single source of truth for which roles may use each area of the school
| API. Routes declare their area with the `access:{area}` middleware:
|   - GET / HEAD / OPTIONS requests need a role listed in `read`
|   - every other method needs a role listed in `write`
| A role allowed to write may always read.
|
| `super_admin` (platform owner) bypasses these checks. Parent and student
| roles have no staff-portal areas until their own portals are built.
|
| The same map is sent to the portal in /auth/me as `user.permissions`
| so the UI can hide what the user cannot open.
|
*/

$admins = ['school_owner', 'school_admin'];
$leaders = [...$admins, 'head_teacher', 'headteacher', 'deputy_head'];
$academic = [...$leaders, 'academic_master'];
$teaching = [...$academic, 'teacher'];
$staff = [...$teaching, 'accountant', 'librarian', 'store_keeper', 'hostel_warden', 'clerk'];

return [

    'platform_role' => 'super_admin',

    'areas' => [
        'school-profile' => [
            'label' => 'School profile',
            'read' => $staff,
            'write' => [...$admins, 'head_teacher', 'headteacher'],
        ],
        'students' => [
            'label' => 'Students & parents',
            'read' => $staff,
            'write' => [...$academic, 'clerk'],
        ],
        'admissions' => [
            'label' => 'Admissions',
            'read' => [...$academic, 'clerk'],
            'write' => [...$academic, 'clerk'],
        ],
        'staff' => [
            'label' => 'Staff & HR',
            'read' => [...$leaders, 'accountant'],
            'write' => $leaders,
        ],
        'academic' => [
            'label' => 'Academic setup',
            'read' => $staff,
            'write' => $academic,
        ],
        'attendance' => [
            'label' => 'Attendance',
            'read' => [...$teaching, 'clerk'],
            'write' => [...$teaching, 'clerk'],
        ],
        'examinations' => [
            'label' => 'Examinations',
            'read' => $teaching,
            'write' => $teaching,
        ],
        'exam-control' => [
            'label' => 'Publish & lock results',
            'read' => $academic,
            'write' => $academic,
        ],
        'report-cards' => [
            'label' => 'Report cards',
            'read' => $teaching,
            'write' => $academic,
        ],
        'promotions' => [
            'label' => 'Promotions',
            'read' => $teaching,
            'write' => $academic,
        ],
        'learning' => [
            'label' => 'Learning & homework',
            'read' => $teaching,
            'write' => $teaching,
        ],
        'finance' => [
            'label' => 'Finance & fees',
            'read' => [...$leaders, 'accountant'],
            'write' => [...$admins, 'accountant'],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'read' => [...$leaders, 'accountant'],
            'write' => [...$admins, 'accountant'],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'read' => [...$leaders, 'store_keeper', 'accountant'],
            'write' => [...$admins, 'store_keeper'],
        ],
        'library' => [
            'label' => 'Library',
            'read' => [...$teaching, 'librarian'],
            'write' => [...$admins, 'librarian'],
        ],
        'transport' => [
            'label' => 'Transport',
            'read' => [...$leaders, 'accountant'],
            'write' => $leaders,
        ],
        'hostel' => [
            'label' => 'Hostel',
            'read' => [...$leaders, 'hostel_warden', 'accountant'],
            'write' => [...$leaders, 'hostel_warden'],
        ],
        'messaging' => [
            'label' => 'Parent messaging',
            'read' => [...$teaching, 'clerk', 'accountant'],
            'write' => [...$teaching, 'clerk', 'accountant'],
        ],
        'announcements' => [
            'label' => 'Announcements & notice board',
            'read' => $staff,
            'write' => [...$academic, 'clerk'],
        ],
        'reports' => [
            'label' => 'Reports & analytics',
            'read' => [...$academic, 'accountant'],
            'write' => $academic,
        ],
        'compliance' => [
            'label' => 'Government compliance',
            'read' => $academic,
            'write' => $academic,
        ],
        'users' => [
            'label' => 'Users & roles',
            'read' => $leaders,
            'write' => $admins,
        ],
        'admin' => [
            'label' => 'School administration',
            'read' => $admins,
            'write' => $admins,
        ],
        'modules' => [
            'label' => 'Other modules',
            'read' => $staff,
            'write' => $admins,
        ],
    ],

    /*
    | School statuses whose users are locked out of the school API.
    */
    'blocked_school_statuses' => ['suspended', 'rejected'],

];
