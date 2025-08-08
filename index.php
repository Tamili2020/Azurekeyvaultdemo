<?php
// ==========================
// 🔹 Load Azure Key Vault Secrets
// ==========================

require 'vendor/autoload.php';
use GuzzleHttp\Client;

// ✅ Read credentials from Azure App Service environment variables
$tenantId     = getenv('TENANT_ID');
$clientId     = getenv('CLIENT_ID');
$clientSecret = getenv('CLIENT_SECRET');
$vaultName    = getenv('VAULT_NAME');

// ==========================
// 🔹 Get Access Token from Azure AD
// ==========================
$client = new Client();
$tokenResponse = $client->request('POST', "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token", [
    'form_params' => [
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'https://vault.azure.net/.default'
    ]
]);

$tokenData = json_decode($tokenResponse->getBody(), true);
$accessToken = $tokenData['access_token'];

// ==========================
// 🔹 Get SQL credentials from Key Vault
// ==========================
function getSecret($vaultName, $secretName, $accessToken) {
    $client = new Client();
    $url = "https://$vaultName.vault.azure.net/secrets/$secretName?api-version=7.4";
    $response = $client->request('GET', $url, [
        'headers' => [
            'Authorization' => "Bearer $accessToken"
        ]
    ]);
    $secretData = json_decode($response->getBody(), true);
    return $secretData['value'];
}

$sqlUsername = getSecret($vaultName, 'sql-username', $accessToken);
$sqlPassword = getSecret($vaultName, 'sql-password', $accessToken);
$sqlServer   = getSecret($vaultName, 'sql-server', $accessToken);
$sqlDatabase = getSecret($vaultName, 'sql-database', $accessToken);

// ==========================
// 🔹 Connect to Azure SQL Database
// ==========================
$connectionInfo = [
    "Database" => $sqlDatabase,
    "UID"      => $sqlUsername,
    "PWD"      => $sqlPassword,
    "Encrypt"  => "Yes",
    "TrustServerCertificate" => 0
];

$conn = sqlsrv_connect($sqlServer, $connectionInfo);
if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// ==========================
// 🔹 Handle Search Form
// ==========================
$lastname   = isset($_GET['lastname']) ? $_GET['lastname'] : '';
$department = isset($_GET['department']) ? $_GET['department'] : '';

$sql = "SELECT EmployeeID, FirstName, LastName, Department, Email FROM Employees WHERE 1=1";
$params = [];

if (!empty($lastname)) {
    $sql .= " AND LastName LIKE ?";
    $params[] = "%" . $lastname . "%";
}
if (!empty($department)) {
    $sql .= " AND Department = ?";
    $params[] = $department;
}

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Directory</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; }
        th { background-color: #f4f4f4; }
    </style>
</head>
<body>

<h2>🔍 Employee Search</h2>
<form method="GET" action="">
    Last Name: <input type="text" name="lastname" value="<?php echo htmlspecialchars($lastname); ?>">
    Department: <input type="text" name="department" value="<?php echo htmlspecialchars($department); ?>">
    <button type="submit">Search</button>
</form>

<h3>📋 Employee List</h3>
<table>
    <tr>
        <th>ID</th>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Department</th>
        <th>Email</th>
    </tr>
    <?php while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)): ?>
    <tr>
        <td><?php echo htmlspecialchars($row['EmployeeID']); ?></td>
        <td><?php echo htmlspecialchars($row['FirstName']); ?></td>
        <td><?php echo htmlspecialchars($row['LastName']); ?></td>
        <td><?php echo htmlspecialchars($row['Department']); ?></td>
        <td><?php echo htmlspecialchars($row['Email']); ?></td>
    </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
