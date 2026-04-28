<?php

// -----------------------------
// CONFIG
// -----------------------------
$baseUrl = $_ENV['pihole_url'];
$piholePassword = $_ENV['pihole_pass'];

define('DB_HOST', $_ENV['mysql_host']);
define('DB_USER', $_ENV['mysql_user']);
define('DB_PASS', $_ENV['mysql_pass']);
define('DB_NAME', $_ENV['mysql_db']);

$verifySSL = false; // set to true if using valid cert

// -----------------------------
// DB CONNECTION (PDO)
// -----------------------------
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

// -----------------------------
// STEP 1: AUTHENTICATE
// -----------------------------
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => "$baseUrl/api/auth",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        "password" => $piholePassword
    ]),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json"
    ],
    CURLOPT_SSL_VERIFYPEER => $verifySSL,
    CURLOPT_SSL_VERIFYHOST => $verifySSL
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    die("Auth cURL error: " . curl_error($ch));
}

$authData = json_decode($response, true);

if (!isset($authData['session']['sid'])) {
    die("Failed to get SID: " . $response);
}

$sid = $authData['session']['sid'];

curl_close($ch);

// -----------------------------
// STEP 2: GET DNS ENTRIES
// -----------------------------
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => "$baseUrl/api/config/dns/hosts",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "accept: application/json",
        "sid: $sid"
    ],
    CURLOPT_SSL_VERIFYPEER => $verifySSL,
    CURLOPT_SSL_VERIFYHOST => $verifySSL
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    die("DNS cURL error: " . curl_error($ch));
}

$data = json_decode($response, true);

curl_close($ch);

if (!isset($data['config']['dns']['hosts'])) {
    die("Unexpected response: " . $response);
}

$hosts = $data['config']['dns']['hosts'];

// -----------------------------
// STEP 3: NORMALIZE DATA
// -----------------------------
$currentEntries = [];

foreach ($hosts as $entry) {
    $parts = explode(' ', $entry, 2);

    $ip = $parts[0] ?? null;
    $domain = $parts[1] ?? null;

    if ($ip && $domain) {
        $currentEntries[$domain] = $ip;
    }
}

// -----------------------------
// STEP 4: BACKUP FILE
// -----------------------------
// file_put_contents(
//     __DIR__ . '/dns_backup/dns_backup_' . date('Y-m-d_H-i-s') . '.json',
//     json_encode($currentEntries, JSON_PRETTY_PRINT)
// );

// -----------------------------
// STEP 5: SYNC WITH DATABASE
// -----------------------------
$pdo->beginTransaction();

try {

    // --- Get existing DB entries ---
    $stmt = $pdo->query("SELECT domain, ip, datecreated, dateupdated FROM pihole_dns_entries");
    $dbEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dbDomains = [];
    foreach ($dbEntries as $row) {
        $dbDomains[$row['domain']] = $row;
    }

    // --- Find deleted domains ---
    $domainsToRemove = array_diff(array_keys($dbDomains), array_keys($currentEntries));

    // --- Move deleted to history ---
    if (!empty($domainsToRemove)) {
        $insertHistory = $pdo->prepare("
            INSERT INTO pihole_dns_removed (domain, ip, datecreated, dateupdated)
            VALUES (:domain, :ip, :datecreated, :dateupdated)
        ");

        foreach ($domainsToRemove as $domain) {
            $row = $dbDomains[$domain];

            $insertHistory->execute([
                ':domain' => $row['domain'],
                ':ip' => $row['ip'],
                ':datecreated' => $row['datecreated'],
                ':dateupdated' => $row['dateupdated']
            ]);
        }

        // --- Delete from main table ---
        $placeholders = implode(',', array_fill(0, count($domainsToRemove), '?'));

        $deleteStmt = $pdo->prepare("
            DELETE FROM pihole_dns_entries
            WHERE domain IN ($placeholders)
        ");

        $deleteStmt->execute(array_values($domainsToRemove));
    }

    // --- Upsert current entries ---
    $upsert = $pdo->prepare("
        INSERT INTO pihole_dns_entries (domain, ip)
        VALUES (:domain, :ip)
        ON DUPLICATE KEY UPDATE
            ip = VALUES(ip),
            dateupdated = CURRENT_TIMESTAMP
    ");

    foreach ($currentEntries as $domain => $ip) {
        $upsert->execute([
            ':domain' => $domain,
            ':ip' => $ip
        ]);
    }

    $pdo->commit();

} catch (Exception $e) {
    $pdo->rollBack();
    die("Transaction failed: " . $e->getMessage());
}

// -----------------------------
// DONE
// -----------------------------
echo "Pi-hole DNS sync completed successfully.\n";
echo "Total active entries: " . count($currentEntries) . "\n";
echo "Removed entries this run: " . count($domainsToRemove) . "\n";