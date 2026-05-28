<?php
require_once('C:/Users/facun/Local Sites/fp-beta/app/public/wp-load.php');
$subscriptions = get_option('bitstream_push_subscriptions', []);
echo "SUBS_COUNT: " . count($subscriptions) . "\n";
echo "SUBS: " . var_export($subscriptions, true) . "\n";
