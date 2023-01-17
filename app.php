<?php
use Mark\App;

require 'vendor/autoload.php';
$named_conf = '/root/zones/named.conf';

$api = new App('http://127.0.0.1:3000');
$api->count = 1; // process count

$api->any('/', function ($requst) {
    return 'BIND9 API v0.5.0';
});

$api->post('/zone/create', function ($zone, $ns1, $ns2, $email) use ($named_conf) {
    $zone_file = '/root/zones/' . $zone;
    $template = '$TTL    86400
@       IN      SOA     ns1.' . $zone . '. ' . $email . ' (
                        ' . time() . ' ; serial
                        3600       ; refresh
                        1800       ; retry
                        604800     ; expire
                        86400 )    ; minimum
        IN      NS      ns1.' . $zone . '
        IN      NS      ns2.' . $zone . '
ns1     IN      A       ' . $ns1 . '
ns2     IN      A       ' . $ns2 . '
';
    if (file_exists($zone_file)) {
        return json_encode(['code'=>1, 'message' => "Error: Zone $zone already exists."]);
    }
    file_put_contents($zone_file, $template);
    $zone_config = 'zone "' . $zone . '" {
        type master;
        file "' . $zone_file . '";
    };
';
	exec('rndc addzone ' . $zone . ' ' . escapeshellarg($zone_config), $output, $return_var);
    if ($return_var != 0) {
        return json_encode(['code'=>1, 'message' => "Error: Failed to add zone $zone. Error: " . implode("\n", $output)]);
    }
    exec('rndc reload');
    return json_encode(['code'=>0 ,'message' => "Zone $zone created successfully."]);
});

$api->post('/zone/delete', function ($zone) use ($named_conf) {
    $zone_file = '/root/zones/' . $zone;
    // remove the zone file
    unlink($zone_file);
    // remove the zone from named.conf
    $named_contents = file_get_contents($named_conf);
    $start = strpos($named_contents, "zone \"$zone\"");
    $end = strpos($named_contents, "};", $start) + 2;
    $zone_config = substr($named_contents, $start, $end - $start);
    $named_contents = str_replace($zone_config, '', $named_contents);
    file_put_contents($named_conf, $named_contents);
    // reload bind
    exec('rndc reload');
    return json_encode(['code'=>0 ,'message' => "Zone $zone deleted successfully."]);
});

$api->post('/record/create', function ($zone, $record, $type, $value) {
	$zone_file = '/root/zones/' . $zone;

	// Check if the zone file exists
	if (!file_exists($zone_file)) {
		echo "Error: Zone file not found.\n";
		return;
	}

	// Check if the record already exists in the zone file
	$zone_contents = file_get_contents($zone_file);
	if (strpos($zone_contents, "$record\tIN\t$type\t$value") !== false) {
		echo "Error: Record already exists.\n";
		return;
	}

	// Construct the new DNS record
	$new_record = "$record\tIN\t$type\t$value\n";

	// Append the new record to the zone file, add new line
	$zone_contents = rtrim($zone_contents) . PHP_EOL . $new_record;

	// Write the updated contents back to the zone file
	file_put_contents($zone_file, $zone_contents);

	// Reload the BIND service to apply the changes
	exec('rndc reload');
    return json_encode(['code'=>0 ,'message' => "Record $record for $zone created successfully."]);
});

$api->post('/record/delete', function ($zone, $record, $type, $value) {
	$zone_file = '/root/zones/' . $zone;

	// Check if the zone file exists
	if (!file_exists($zone_file)) {
		echo "Error: Zone file not found.\n";
		return;
	}

	// Read the current contents of the zone file
	$zone_contents = file_get_contents($zone_file);

	// Construct the DNS record to be removed
	$record_to_remove = "$record\tIN\t$type\t$value\n";

	// Remove the record from the zone file
	$zone_contents = str_replace($record_to_remove, "", $zone_contents);
	// check if the record is not existing in the zone file
	if($zone_contents === file_get_contents($zone_file)){
		echo "Error: Record not found.\n";
		return;
	}
	// Write the updated contents back to the zone file
	file_put_contents($zone_file, $zone_contents);

	// Reload the BIND service to apply the changes
	exec('rndc reload');
    return json_encode(['code'=>0 ,'message' => "Record $record for $zone created successfully."]);
});

$api->start();
