<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

logout_user();
start_session();
flash('success', 'You have been signed out.');
redirect('index.php');
