<?php
declare(strict_types=1);

/**
 * Add extra charge to an order item
 * 
 * @param int $itemId The ID of the order item
 * @param float $amount The extra charge amount
 * @param string $notes Notes about the extra charge
 * @param int $staffId The ID of the staff adding the charge
 * @return bool True if successful, false otherwise
 */
function add_extra_charge(int $itemId, float $amount, string $notes, int $staffId): bool
{
    // Check if the item exists and belongs to an open order
    $item = db_row('SELECT oi.*, o.status FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id = ?', [$itemId]);
    if ($item === null) {
        return false;
    }

    // Check if the order is still open
    if (!order_accepts_items($item)) {
        return false;
    }
    // Insert the extra charge
    $result = db_query(
        'INSERT INTO order_item_extra_charges (item_id, amount, notes, staff_id, created_at) VALUES (?, ?, ?, ?, ?)',
        [$itemId, $amount, $notes, $staffId, now()]
    );

    if ($result) {
        // Update the item amount
        db_query(
            'UPDATE order_items SET amount = amount + ? WHERE id = ?',
            [$amount, $itemId]
        );

        // Update the order total
        recalc_order_total($item['order_id']);

        // Log the action
        audit('order.extra_charge_added', 'order_item', (string) $itemId, 'Extra charge added: ' . $amount);

        return true;
    }

    return false;
}

/**
 * Get all extra charges for an order item
 * 
 * @param int $itemId The ID of the order item
 * @return array Array of extra charges
 */
function get_extra_charges(int $itemId): array
{
    return db_all(
        'SELECT * FROM order_item_extra_charges WHERE item_id = ? ORDER BY created_at DESC',
        [$itemId]
    );
}

/**
 * Check if a user can modify service rates
 * 
 * @param array $user The user array
 * @return bool True if the user can modify service rates, false otherwise
 */
function can_modify_service_rates(array $user): bool
{
    return in_array($user['role'], ['super_admin', 'admin'], true);
}

/**
 * Check if a user can add extra charges
 * 
 * @param array $user The user array
 * @return bool True if the user can add extra charges, false otherwise
 */
function can_add_extra_charges(?array $user): bool
{
    return $user !== null && in_array($user['role'], ['staff', 'pickup', 'delivery'], true);
}

/**
 * Check if a user can modify due amounts
 * 
 * @param array $user The user array
 * @return bool True if the user can modify due amounts, false otherwise
 */
function can_modify_due_amounts(array $user): bool
{
    return in_array($user['role'], ['super_admin', 'admin'], true);
}
