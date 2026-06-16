<?php
// ================================================
//  Proxmox VM Manager - Cron Sync Script
//  Run via cron: php /path/to/vm_cron_sync.php
// ================================================
//https://ntfy.lewisanalytix.com/
// define('BASE_URL', 'http://192.168.20.44/vm-checker/vm.php'); // ← Change if needed
// define('NTFY_TOPIC', 'pbs'); // ← Change to your ntfy topic
define('BASE_URL', getenv('BASE_URL') ?: 'http://127.0.0.1/vm.php');
define('NTFY_TOPIC', getenv('NTFY_TOPIC') ?: 'pbs');

echo "=== Sync started at " . date('Y-m-d H:i:s') . " ===\n";
echo "BASE_URL = " . BASE_URL . "\n";

function post($action, $data = []) {
    $ch = curl_init(BASE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['action' => $action], $data)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);           // increased for PBS
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);

    echo "POST $action → HTTP $httpCode\n";
    if ($error) echo "Curl error: $error\n";

    return [
        'success' => $httpCode === 200,
        'code'    => $httpCode,
        'body'    => $response ? json_decode($response, true) : null
    ];
}

// Get all hosts
$result = post('host_list');

if (!$result['success'] || empty($result['body']['rows'])) {
    send_ntfy("❌ Proxmox Sync Failed", "Could not retrieve host list.");
    exit(1);
}

$hosts = $result['body']['rows'];
$errors = [];
$synced_total = 0;

echo "Starting sync for " . count($hosts) . " hosts...\n";

foreach ($hosts as $host) {
    $host_id = $host['id'];
    $name    = $host['name'];

    if (empty($host['token_set'])) {
        $errors[] = "$name → No API token configured";
        continue;
    }

    echo "Syncing $name... ";

    $result = post('host_sync', ['host_id' => $host_id]);

    if ($result['success'] && !empty($result['body']['ok'])) {
        $synced = $result['body']['synced'] ?? 0;
        $synced_total += $synced;
        echo "✓ $synced items\n";
    } else {
        $msg = $result['body']['msg'] ?? 'Unknown error';
        echo "✗ Failed: $msg\n";
        $errors[] = "$name → $msg";
    }
}

// Only send alert if there were problems
if (!empty($errors)) {
    $message = "⚠️ Sync issues on " . count($errors) . " host(s):\n\n" . 
               implode("\n", $errors) . 
               "\n\nSuccessful items synced: $synced_total";
    
    send_ntfy("⚠️ Proxmox Sync Issues", $message);
}

function send_ntfy($title, $message) {
    $ch = curl_init("https://ntfy.lewisanalytix.com/" . NTFY_TOPIC);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Title: " . $title,
        "Tags: warning,proxmox",
        "Priority: high",
        "Click: https://ntfy.lewisanalytix.com/" . NTFY_TOPIC
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}