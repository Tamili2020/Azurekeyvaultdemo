<?php
require 'vendor/autoload.php';
use GuzzleHttp\Client;

// Azure Key Vault + App Registration
$tenantId     = "2817eb0c-e3e7-4403-9e0b-171f475e2b9c";
$clientId     = "e674564e-6c0f-432e-82ae-8d8e56400c31";
$clientSecret = "c045b0fa-ebba-4241-86ce-6d9a2aa917c4";
$vaultName    = "mydemokeyvaultwebapp";

try {
    $http = new Client();
    $response = $http->post("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token", [
        'form_params' => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scope'         => 'https://vault.azure.net/.default'
        ]
    ]);
    $token = json_decode($response->getBody(), true)['access_token'];

    function getSecret($secretName, $token, $vaultName) {
        $http = new Client();
        $url = "https://$vaultName.vault.azure.net/secrets/$secretName?api-version=7.4";
        $response = $http->get($url, [
            'headers' => ['Authorization' => "Bearer $token"]
        ]);
        $data = json_decode($response->getBody(), true);
        return $data['value'];
    }

    $sqlUser = getSecret("sql-username", $token, $vaultName);
    $sqlPass = getSecret("sql-password", $token, $vaultName);

} catch (Exception $e) {
    die("<p style='color:red;'>❌ Key Vault error: " . htmlspecialchars($e->getMessage()) . "</p>");
}

// Azure SQL connection
$serverName = "tcp:mydemovm.database.windows.net,1433";
$connectionOptions = [
    "Database" => "azureadmin", // ✅ MATCH your table's DB
    "Uid"      => $sqlUser,
    "PWD"      => $sqlPass,
    "Encrypt"  => true
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die("<p style='color:red;'>❌ SQL connection failed: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// Handle delete
if (isset($_POST['delete_btn'])) {
    $stmt = sqlsrv_query($conn, "DELETE FROM Employees WHERE EmployeeID = ?", [$_POST['delete_id']]);
    echo $stmt ? "<p style='color:green;'>✅ Deleted Employee</p>"
               : "<p style='color:red;'>❌ Delete failed: " . print_r(sqlsrv_errors(), true) . "</p>";
}

// Handle add
if (isset($_POST['submit'])) {
    $stmt = sqlsrv_query($conn, "INSERT INTO Employees (FirstName, LastName, Department) VALUES (?, ?, ?)",
                         [$_POST['first_name'], $_POST['last_name'], $_POST['department']]);
    echo $stmt ? "<p style='color:green;'>✅ Employee added!</p>"
               : "<p style='color:red;'>❌ Insert failed: " . print_r(sqlsrv_errors(), true) . "</p>";
}

// Handle search
if (isset($_POST['search_btn'])) {
    $sql = "SELECT * FROM Employees WHERE 1=1";
    $params = [];

    if (!empty($_POST['search_lastname'])) {
        $sql .= " AND LastName LIKE ?";
        $params[] = $_POST['search_lastname'];
    }
    if (!empty($_POST['search_department'])) {
        $sql .= " AND Department LIKE ?";
        $params[] = $_POST['search_department'];
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        echo "<h2>Search Results</h2>";
        echo "<table border='1'><tr><th>ID</th><th>First</th><th>Last</th><th>Dept</th><th>Action</th></tr>";
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            echo "<tr>
                    <td>{$row['EmployeeID']}</td>
                    <td>{$row['FirstName']}</td>
                    <td>{$row['LastName']}</td>
                    <td>{$row['Department']}</td>
                    <td>
                        <form method='post'>
                            <input type='hidden' name='delete_id' value='{$row['EmployeeID']}'>
                            <button name='delete_btn'>Delete</button>
                        </form>
                    </td>
                  </tr>";
        }
        echo "</table>";
    }
}

// Always show add form + full list
?>
<form method="post">
    <h2>Add New Employee</h2>
    <input type="text" name="first_name" placeholder="First Name" required>
    <input type="text" name="last_name" placeholder="Last Name" required>
    <input type="text" name="department" placeholder="Department" required>
    <input type="submit" name="submit" value="Save">
</form>

<form method="post">
    <h2>Search Employees</h2>
    <input type="text" name="search_lastname" placeholder="Last Name (e.g., Pat%)">
    <input type="text" name="search_department" placeholder="Department">
    <input type="submit" name="search_btn" value="Search">
</form>

<?php
// Show all employees
$stmt = sqlsrv_query($conn, "SELECT * FROM Employees");
if ($stmt) {
    echo "<h2>All Employees</h2><table border='1'><tr><th>ID</th><th>First</th><th>Last</th><th>Dept</th><th>Action</th></tr>";
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<tr>
                <td>{$row['EmployeeID']}</td>
                <td>{$row['FirstName']}</td>
                <td>{$row['LastName']}</td>
                <td>{$row['Department']}</td>
                <td>
                    <form method='post'>
                        <input type='hidden' name='delete_id' value='{$row['EmployeeID']}'>
                        <button name='delete_btn'>Delete</button>
                    </form>
                </td>
              </tr>";
    }
    echo "</table>";
}
sqlsrv_close($conn);
?>
