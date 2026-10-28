<?php
/**
 * Test credentials for local development and seeding.
 *
 * NOTE: This file contains plain‑text passwords and should **never** be
 * committed to version control or deployed to production. Add it to
 * `.gitignore` and restrict filesystem permissions (e.g. chmod 600).
 */
return [
    'super_admin' => [
        'email'    => 'superadmin@laundryfriends.test',
        'phone'    => '9000000001',
        'password' => 'superadmin@123',
    ],
    'owner' => [
        'email'    => 'owner@laundryfriends.test',
        'phone'    => '9000000002',
        'password' => 'owner@123',
    ],
    'pickup_staff_1' => [
        'email'    => 'ramesh@laundryfriends.test',
        'phone'    => '9000000003',
        'password' => 'pickup@123',
    ],
    'pickup_staff_2' => [
        'email'    => 'vikas@laundryfriends.test',
        'phone'    => '9000000005',
        'password' => 'pickup@123',
    ],
    // Additional staff can be added here following the same structure.
];
?>
