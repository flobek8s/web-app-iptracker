<?php
// api/get_vpn_settings.php - Get VPN configuration

require_once '../config.php';

$query = "SELECT * FROM vpn_settings WHERE id = 1";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    send_json(false, 'VPN settings not found');
}

$vpn = $result->fetch_assoc();
send_json(true, 'VPN settings retrieved', $vpn);
?>
