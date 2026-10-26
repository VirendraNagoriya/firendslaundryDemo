<?php
declare(strict_types=1);

/**
 * Create a customer login + customer record safely.
 *
 * The mobile number is the identity key. A customer record may already exist
 * because the owner created it while taking an order by phone. In that case the
 * new login is linked to the existing record instead of inserting a duplicate.
 *
 * The caller should wrap this in db_transaction() so the user row, customer row
 * and any order are committed together.
 *
 * @return array{user_id:int, customer_id:int, linked:bool}
 */
function register_customer_account(array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    $phone = normalise_phone((string) ($data['phone'] ?? ''));
    $key = phone_key($phone);
    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $address = trim((string) ($data['address'] ?? ''));
    $landmark = trim((string) ($data['landmark'] ?? ''));
    $city = trim((string) ($data['city'] ?? ''));
    $pincode = trim((string) ($data['pincode'] ?? ''));
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    $placeId = trim((string) ($data['place_id'] ?? ''));

    if ($key === '') {
        throw new RuntimeException('Please enter a valid 10-digit mobile number.');
    }

    $existingUser = db_row('SELECT id FROM users WHERE phone_key = ? LIMIT 1', [$key]);
    if ($existingUser !== null) {
        throw new RuntimeException('An account already exists with this mobile number. Please sign in instead.');
    }
    if ($email !== '') {
        $existingEmail = db_row('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($existingEmail !== null) {
            throw new RuntimeException('An account already exists with this email address. Please sign in instead.');
        }
    }

    $existingCustomer = db_row('SELECT * FROM customers WHERE phone_key = ? LIMIT 1', [$key]);
    if ($existingCustomer !== null && !empty($existingCustomer['user_id'])) {
        $linkedUser = db_row('SELECT id FROM users WHERE id = ? LIMIT 1', [(int) $existingCustomer['user_id']]);
        if ($linkedUser !== null) {
            throw new RuntimeException('This mobile number is already linked to an account. Please sign in instead, or contact us if you cannot access it.');
        }
        // The old link points to a deleted account - repair it below.
    }

    $userId = db_insert(
        'INSERT INTO users (role, name, email, phone, phone_key, password_hash) VALUES (?, ?, ?, ?, ?, ?)',
        [
            'customer',
            $name,
            $email !== '' ? $email : null,
            $phone,
            $key,
            password_hash($password, PASSWORD_DEFAULT),
        ]
    );

    if ($existingCustomer !== null) {
        $customerId = (int) $existingCustomer['id'];
        db_exec(
            'UPDATE customers
             SET user_id = ?, name = ?, email = COALESCE(NULLIF(?, \'\'), email),
                 address = COALESCE(NULLIF(?, \'\'), address),
                 landmark = COALESCE(NULLIF(?, \'\'), landmark),
                 city = COALESCE(NULLIF(?, \'\'), city),
                 pincode = COALESCE(NULLIF(?, \'\'), pincode),
                 latitude = COALESCE(?, latitude),
                 longitude = COALESCE(?, longitude),
                 place_id = COALESCE(NULLIF(?, \'\'), place_id)
             WHERE id = ?',
            [
                $userId,
                $name,
                $email,
                $address,
                $landmark,
                $city,
                $pincode,
                $latitude,
                $longitude,
                $placeId,
                $customerId,
            ]
        );
    } else {
        $customerId = db_insert(
            'INSERT INTO customers (user_id, name, phone, phone_key, email, address, landmark, city, pincode, latitude, longitude, place_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $name,
                $phone,
                $key,
                $email !== '' ? $email : null,
                $address !== '' ? $address : null,
                $landmark !== '' ? $landmark : null,
                $city !== '' ? $city : null,
                $pincode !== '' ? $pincode : null,
                $latitude,
                $longitude,
                $placeId !== '' ? $placeId : null,
                $userId,
            ]
        );
    }

    return ['user_id' => $userId, 'customer_id' => $customerId, 'linked' => $existingCustomer !== null];
}
