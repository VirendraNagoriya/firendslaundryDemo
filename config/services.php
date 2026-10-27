<?php
declare(strict_types=1);

/**
 * Weight-based laundry service type
 */
function weight_service_type(): string
{
    return 'weight_based';
}

/**
 * Label for weight-based service type
 */
function weight_service_type_label(): string
{
    return 'Weight Based Laundry';
}

/**
 * Add weight-based service type to the existing service types
 */
function service_types_with_weight(): array
{
    $types = service_types();
    $types['weight_based'] = 'Weight Based Laundry';
    return $types;
}

/**
 * Weight-based service mode
 */
function weight_service_mode(): string
{
    return 'per_kg';
}

/**
 * Label for weight-based service mode
 */
function weight_service_mode_label(): string
{
    return 'Per kg';
}

/**
 * Add weight-based service mode to the existing service modes
 */
function service_modes_with_weight(): array
{
    $modes = service_modes();
    $modes['per_kg'] = 'Per kg';
    return $modes;
}
