<?php

use Mark\App;

require 'vendor/autoload.php';
$named_conf = '/root/zones/named.conf';

$api = new App('http://0.0.0.0:3000');
$api->count = 1; // process count

$api->any('/', function ($requst) {
    return 'BIND9 API v0.6.0';
});

$api->get('/zone/{zone}', function ($request, $zone) {
    return "Hello $zone";
});

$api->post('/zone', function ($request) use ($named_conf) {
    $result = $request->post();
    if (!array_key_exists('zone', $result) || !array_key_exists('email', $result) || !array_key_exists('nameservers', $result)) {
        throw new Exception('Missing required parameter: zone, email or nameservers');
    }
    $zone = htmlspecialchars($result['zone'], ENT_QUOTES, 'utf-8');
    $email = htmlspecialchars($result['email'], ENT_QUOTES, 'utf-8');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = str_replace("@", ".", $email);
    } else {
        throw new Exception('Invalid email address');
    }
    $nameservers = $result['nameservers'];
    foreach ($nameservers as $key => $value) {
        $nameservers[$key] = htmlspecialchars($value, ENT_QUOTES, 'utf-8');
    }

    $zone_file = '/root/zones/' . $zone;
    $template = '$TTL    86400
@       IN      SOA     ' . $nameservers[0] . '. ' . $email . ' (
                        ' . time() . ' ; serial
                        3600       ; refresh
                        1800       ; retry
                        604800     ; expire
                        86400 )    ; minimum
';
    $valid_nameservers = array();
    foreach ($nameservers as $i => $nameserver) {
        if (filter_var($nameserver, FILTER_VALIDATE_DOMAIN)) {
            $valid_nameservers[] = $nameserver;
        }
    }

    if (count($valid_nameservers) < 2 || count($valid_nameservers) > 13) {
        return json_encode(['code'=>1, 'message' => "Error: At least 2 and at most 13 valid nameservers are required."]);
    }

    foreach ($valid_nameservers as $i => $nameserver) {
        if (strpos($nameserver, $zone) !== false) {
            $template .= '@        IN      NS      ns' . ($i+1) . '.' . $zone . '.' . PHP_EOL;
            $template .= 'ns' . ($i+1) . '     IN      A       ' . $nameserver . PHP_EOL;
        } else {
            $template .= '@        IN      NS       ' . $nameserver . '.' . PHP_EOL;
        }
    }
    if (file_exists($zone_file)) {
        return json_encode(['code'=>1, 'message' => "Error: Zone $zone already exists."]);
    }

    try {
        // Check the file name
        if (preg_match('/[^a-zA-Z0-9\.\-_]/', basename($zone_file))) {
            throw new Exception("Invalid file name.");
        }
        // Check the template data
        $template = strip_tags($template);
        file_put_contents($zone_file, $template);
    } catch (Exception $e) {
        return json_encode(['code'=>1, 'message' => $e->getMessage()]);
    }

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

$api->delete('/zone', function ($request) use ($named_conf) {
    $result = $request->post();
    if (!array_key_exists('zone', $result)) {
        throw new Exception('Missing required parameter: zone');
    }
    $zone = htmlspecialchars($result['zone'], ENT_QUOTES, 'utf-8');

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

$api->post('/record', function ($request) {
    $result = $request->post();
    if (!array_key_exists('zone', $result) || !array_key_exists('record', $result) || !array_key_exists('type', $result) || !array_key_exists('value', $result)) {
        throw new Exception('Missing required parameter: zone, record, type or value');
    }
    $zone = htmlspecialchars($result['zone'], ENT_QUOTES, 'utf-8');
    $record = htmlspecialchars($result['record'], ENT_QUOTES, 'utf-8');
    $type = htmlspecialchars($result['type'], ENT_QUOTES, 'utf-8');
    $value = htmlspecialchars($result['value'], ENT_QUOTES, 'utf-8');

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

$api->delete('/record', function ($request) {
    $result = $request->post();
    if (!array_key_exists('zone', $result) || !array_key_exists('record', $result) || !array_key_exists('type', $result) || !array_key_exists('value', $result)) {
        throw new Exception('Missing required parameter: zone, record, type or value');
    }
    $zone = htmlspecialchars($result['zone'], ENT_QUOTES, 'utf-8');
    $record = htmlspecialchars($result['record'], ENT_QUOTES, 'utf-8');
    $type = htmlspecialchars($result['type'], ENT_QUOTES, 'utf-8');
    $value = htmlspecialchars($result['value'], ENT_QUOTES, 'utf-8');

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
    if ($zone_contents === file_get_contents($zone_file)) {
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
