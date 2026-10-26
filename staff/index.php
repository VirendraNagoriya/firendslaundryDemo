<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_login();

switch ($user['role']) {
    case 'staff':
    case 'pickup':
    case 'delivery':
        redirect('staff/pickup.php');
    case 'customer':
        redirect('customer/index.php');
    default:
        redirect('admin/index.php');
}
