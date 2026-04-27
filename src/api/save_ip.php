<?php
// api/save_ip.php - Create or update an IP record

require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST' && $method !== 'PUT') {
    send_json(false, 'Invalid request method');
}

// Get JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    send_json(false, 'Invalid JSON data');
}

$id = isset($data['id']) ? intval($data['id']) : 0;
$ip = isset($data['ip']) ? $conn->real_escape_string($data['ip']) : '';
$system = isset($data['system']) ? $conn->real_escape_string($data['system']) : '';
$hostname = isset($data['hostname']) ? $conn->real_escape_string($data['hostname']) : '';
$mac = isset($data['mac']) ? $conn->real_escape_string($data['mac']) : '';
$notes = isset($data['notes']) ? $conn->real_escape_string($data['notes']) : '';
$is_assigned = isset($data['is_assigned']) ? intval($data['is_assigned']) : 0;
$category = isset($data['category']) ? $conn->real_escape_string($data['category']) : 'Other';
$behind_vpn = isset($data['behind_vpn']) ? intval($data['behind_vpn']) : 0;

// Validate IP
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    send_json(false, 'Invalid IP address');
}

if ($id > 0) {
    // Update existing
    $query = "UPDATE static_ips SET 
        ip = '$ip',
        ip_bin = INET_ATON('$ip'),
        `system` = '$system',
        hostname = '$hostname',
        mac = '$mac',
        notes = '$notes',
        is_assigned = $is_assigned,
        category = '$category',
        behind_vpn = $behind_vpn
        WHERE id = $id";
    
    if ($conn->query($query)) {
        send_json(true, 'IP updated successfully', ['id' => $id]);
    } else {
        send_json(false, 'Update failed: ' . $conn->error);
    }
} else {
    // Create new
    $query = "INSERT INTO static_ips (ip, ip_bin, `system`, hostname, mac, notes, is_assigned, category, behind_vpn)
        VALUES ('$ip', INET_ATON('$ip'), '$system', '$hostname', '$mac', '$notes', $is_assigned, '$category', $behind_vpn)";
    
    if ($conn->query($query)) {
        $new_id = $conn->insert_id;
        send_json(true, 'IP created successfully', ['id' => $new_id]);
    } else {
        send_json(false, 'Insert failed: ' . $conn->error);
    }
}
?>
