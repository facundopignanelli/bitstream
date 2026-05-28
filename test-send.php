<?php
require_once('C:/Users/facun/Local Sites/fp-beta/app/public/wp-load.php');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$subscriptions = get_option('bitstream_push_subscriptions', []);
if (empty($subscriptions)) {
    echo "NO SUBSCRIPTIONS IN DB\n";
    exit;
}

$keys = BitStream_PWA_Manager::get_vapid_keys();
$private_key = $keys['private_key'];
$public_key = $keys['public_key'];

$subject = get_option('bitstream_push_subject', 'mailto:admin@test.com');

echo "SUBSCRIPTION ENDPOINT: " . $subscriptions[0]['endpoint'] . "\n";

$endpoint = $subscriptions[0]['endpoint'];
$parsed = wp_parse_url($endpoint);
$origin = $parsed['scheme'] . '://' . $parsed['host'];

// Generate JWT
$claims = [
    'aud' => $origin,
    'exp' => time() + 12 * 3600,
    'sub' => $subject
];

// Let's reflect sign_jwt method
$ref = new ReflectionMethod('BitStream_PWA_Manager', 'sign_jwt');
$ref->setAccessible(true);
$jwt = $ref->invoke(null, $private_key, $claims);

echo "GENERATED JWT: $jwt\n";

$headers = [
    'TTL' => '86400',
    'Urgency' => 'high',
    'Authorization' => 'vapid t=' . $jwt . ', k=' . $public_key,
];

$args = [
    'headers' => $headers,
    'body' => '',
    'timeout' => 10,
    'redirection' => 5,
    'httpversion' => '1.1',
    'blocking' => true,
];

$response = wp_remote_post($endpoint, $args);

if (is_wp_error($response)) {
    echo "WP_ERROR: " . $response->get_error_message() . "\n";
} else {
    echo "RESPONSE CODE: " . wp_remote_retrieve_response_code($response) . "\n";
    echo "RESPONSE BODY: " . wp_remote_retrieve_response_body($response) . "\n";
    echo "RESPONSE HEADERS: " . var_export(wp_remote_retrieve_headers($response), true) . "\n";
}
