<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';
require_once dirname(__DIR__) . '/includes/queues.php';

$user = require_admin();

render_admin_queue('pickup');
