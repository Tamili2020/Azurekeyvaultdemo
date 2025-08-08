<!DOCTYPE html>
<html>
<head>
    <title>Azure SQL Employee Portal (Key Vault Secured)</title>
    <style>
        body {
            font-family: Arial;
            background-color: #f0f8ff;
            padding: 30px;
            text-align: center;
        }
        .btn {
            padding: 12px 25px;
            background-color: #0078D4;
            color: white;
            border: none;
            margin: 10px;
            cursor: pointer;
            font-size: 16px;
        }
        input {
            padding: 10px;
            margin: 10px;
            width: 250px;
        }
        table {
            border-collapse: collapse;
            width: 80%;
            margin: 20px auto;
            background: white;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #0078D4;
            color: white;
        }
        .error {
            color: red;
            background-color: #ffe6e6;
            padding: 10px;
            margin: 10px;
            border-radius: 5px;
        }
        .success {
            color: green;
            background-color: #e6ffe6;
            padding: 10px;
            margin: 10px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<h1>Azure SQL Employee Portal 🔐</h1>

<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==========================
// 🔹 Load Azure Key Vault Secrets
// ==========================
require_once 'vendor/autoload.php'; // Use require_once to prevent multiple inclusions

// Check if Guzzle is available
if (!class_exists('GuzzleHttp\Client')) {
    die("<div class='error'>❌ GuzzleHttp\Client class not found. Please install Guzzle via Composer: composer require guzzlehttp/guzzle</div>");
}

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;

// Azure Key Vault + Azure AD app details
$tenantId     = "2817eb0c-e3e7-4403-9e0b-171f475e2b9c";  // YOUR_TENANT_ID
$clientId     = "e674564e-6c0f-432e-82ae-8d8e56400c31";  // YOUR_APP_REGISTRATION_CLIENT_ID
$clientSecret = "c045b0fa-ebba-4241-86ce-6d9a2aa917c4";  // YOUR_APP_REGISTRATION_CLIENT_SECRET
$vaultName    = "mydemokeyvaultwebapp"; // YOUR_KEY_VAULT_NAME_ONLY

// Initialize variables
$sqlUser = null;
$sqlPass = null;
$conn = null;

try {
    // 1️⃣ Get Azure AD token
    $http = new Client([
        'timeout' => 30,
        'connect_timeout' => 10
    ]);
    
    $response = $http->post("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token", [
        'form_params' => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scope'         => 'https://vault.azure.net/.default'
        ],
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ]);
    
    $tokenData = json_decode($response->getBody()->getContents(), true);
    
    if (!isset($tokenData['access_token'])) {
        throw new Exception('Access token not found in response: ' . print_r($tokenData, true));
    }
    
    $token = $tokenData['access_token'];
    echo "<div class='success'>✅ Successfully obtained Azure AD token</div>";

} catch (RequestException $e) {
    $errorMsg = "HTTP Request failed: " . $e->getMessage();
    if ($e->hasResponse()) {
        $errorMsg .= "\nResponse: " . $e->getResponse()->getBody()->getContents();
    }
    die("<div class='error'>❌ Failed to get Azure AD token: $errorMsg</div>");
} catch (Exception $e) {
    die("<div class='error'>❌ Failed to get Azure AD token: " . $e->getMessage() . "</div>");
}

// 2️⃣ Function to retrieve secret value
function getSecret($secretName, $token, $vaultName) {
    try {
        $http = new Client([
            'timeout' => 30,
            'connect_timeout' => 10
        ]);
        
        $url = "https://$vaultName.vault.azure.net/secrets/$secretName?api-version=7.3";
        $response = $http->get($url, [
            'headers' => [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        ]);
        
        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($data['value'])) {
            throw new Exception("Secret value not found for '$secretName': " . print_r($data, true));
        }
        
        return $data['value'];
        
    } catch (ClientException $e) {
        if ($e->getResponse()->getStatusCode() == 404) {
            throw new Exception("Secret '$secretName' not found in Key Vault");
        }
        throw new Exception("Failed to get secret '$secretName': " . $e->getMessage());
    } catch (RequestException $e) {
        throw new Exception("HTTP error getting secret '$secretName': " . $e->getMessage());
    }
}

try {
    // 3️⃣ Get DB credentials from Key Vault
    $sqlUser = getSecret("sql-username", $token, $vaultName);
    $sqlPass = getSecret("sql-password", $token, $vaultName);
    
    echo "<div class='success'>✅ Successfully retrieved database credentials from Key Vault</div>";
    
} catch (Exception $e) {
    die("<div class='error'>❌ Failed to get secrets from Azure Key Vault: " . $e->getMessage() . "</div>");
}

// ==========================
// 🔹 Connect to Azure SQL
// ==========================

// Check if SQL Server extension is loaded
if (!extension_loaded('sqlsrv')) {
    die("<div class='error'>❌ SQL Server extension (sqlsrv) is not loaded. Please install the Microsoft SQL Server PHP driver.</div>");
}

$serverName = "tcp:mydemovm.database.windows.net,1433";
$connectionOptions = array(
    "Database" => "azureadmin",
    "Uid"      => $sqlUser,
    "PWD"      => $sqlPass,
    "Encrypt"  => true,
    "TrustServerCertificate" => false,
    "ConnectionPooling" => 0,  // Disable connection pooling for debugging
    "LoginTimeout" => 30,
    "QueryTimeout" => 30
);

$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    $errors = sqlsrv_errors();
    $errorMsg = "SQL connection failed:\n";
    foreach ($errors as $error) {
        $errorMsg .= "SQLSTATE: " . $error['SQLSTATE'] . "\n";
        $errorMsg .= "Code: " . $error['code'] . "\n";
        $errorMsg .= "Message: " . $error['message'] . "\n\n";
    }
    die("<div class='error'>❌ $errorMsg</div>");
} else {
    echo "<div class='success'>✅ Successfully connected to Azure SQL Database</div>";
}

// ==========================
// 🔹 Helper Functions
// ==========================
function sanitizeOutput($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function executeQuery($conn, $query, $params = array()) {
    $stmt = sqlsrv_query($conn, $query, $params);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $errorMsg = "Query execution failed:\n";
        foreach ($errors as $error) {
            $errorMsg .= "SQLSTATE: " . $error['SQLSTATE'] . "\n";
            $errorMsg .= "Code: " . $error['code'] . "\n";
            $errorMsg .= "Message: " . $error['message'] . "\n\n";
        }
        throw new Exception($errorMsg);
    }
    return $stmt;
}

// ==========================
// 🔹 Portal Buttons
// ==========================
?>
<form method="post">
    <button class="btn" name="show_form" value="1">Add Employee</button>
    <button class="btn" name="show_list" value="1">Employee List</button>
</form>
<?php

// ==========================
// 🔹 Delete Employee
// ==========================
if (isset($_POST['delete_btn']) && isset($_POST['delete_id'])) {
    try {
        $deleteId = intval($_POST['delete_id']); // Sanitize input
        if ($deleteId <= 0) {
            throw new Exception("Invalid employee ID");
        }
        
        $deleteQuery = "DELETE FROM Employees WHERE EmployeeID = ?";
        $stmt = executeQuery($conn, $deleteQuery, array($deleteId));
        
        $rowsAffected = sqlsrv_rows_affected($stmt);
        if ($rowsAffected > 0) {
            echo "<div class='success'>✅ Deleted Employee ID $deleteId</div>";
        } else {
            echo "<div class='error'>❌ No employee found with ID $deleteId</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Delete failed: " . $e->getMessage() . "</div>";
    }
}

// ==========================
// 🔹 Add Employee
// ==========================
if (isset($_POST['submit'])) {
    try {
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        
        // Validation
        if (empty($first) || empty($last) || empty($dept)) {
            throw new Exception("All fields are required");
        }
        
        if (strlen($first) > 50 || strlen($last) > 50 || strlen($dept) > 100) {
            throw new Exception("Input values too long");
        }
        
        $insert = "INSERT INTO Employees (FirstName, LastName, Department) VALUES (?, ?, ?)";
        $params = array($first, $last, $dept);
        $stmt = executeQuery($conn, $insert, $params);
        
        echo "<div class='success'>✅ Employee added successfully!</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Insert failed: " . $e->getMessage() . "</div>";
    }
}

// ==========================
// 🔹 Show Add Form
// ==========================
if (isset($_POST['show_form'])) {
    echo '
    <form method="post">
        <h2>Add New Employee</h2>
        <input type="text" name="first_name" placeholder="First Name" maxlength="50" required><br>
        <input type="text" name="last_name" placeholder="Last Name" maxlength="50" required><br>
        <input type="text" name="department" placeholder="Department" maxlength="100" required><br>
        <input class="btn" type="submit" name="submit" value="Save">
    </form>';
}

// ==========================
// 🔹 Search Form
// ==========================
echo '
<form method="post">
    <h2>Search Employees</h2>
    <input type="text" name="search_lastname" placeholder="Last Name (e.g., Smith or Smith%)" maxlength="50">
    <input type="text" name="search_department" placeholder="Department (optional)" maxlength="100">
    <input class="btn" type="submit" name="search_btn" value="Search">
</form>';

// ==========================
// 🔹 Search Logic
// ==========================
if (isset($_POST['search_btn'])) {
    try {
        $lastname = trim($_POST['search_lastname'] ?? '');
        $department = trim($_POST['search_department'] ?? '');

        $sql = "SELECT EmployeeID, FirstName, LastName, Department FROM Employees WHERE 1=1";
        $params = [];

        if (!empty($lastname)) {
            // If user didn't add %, add it for partial matching
            if (strpos($lastname, '%') === false) {
                $lastname = $lastname . '%';
            }
            $sql .= " AND LastName LIKE ?";
            $params[] = $lastname;
        }
        
        if (!empty($department)) {
            if (strpos($department, '%') === false) {
                $department = $department . '%';
            }
            $sql .= " AND Department LIKE ?";
            $params[] = $department;
        }

        $stmt = executeQuery($conn, $sql, $params);
        
        echo "<h2>Search Results</h2><table><tr><th>ID</th><th>First</th><th>Last</th><th>Department</th><th>Action</th></tr>";
        $found = false;
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $found = true;
            echo "<tr>
                    <td>" . sanitizeOutput($row['EmployeeID']) . "</td>
                    <td>" . sanitizeOutput($row['FirstName']) . "</td>
                    <td>" . sanitizeOutput($row['LastName']) . "</td>
                    <td>" . sanitizeOutput($row['Department']) . "</td>
                    <td>
                        <form method='post' style='display:inline;'>
                            <input type='hidden' name='delete_id' value='" . sanitizeOutput($row['EmployeeID']) . "'>
                            <button class='btn' style='background-color:red;' type='submit' name='delete_btn' onclick='return confirm(\"Are you sure you want to delete this employee?\")'>Delete</button>
                        </form>
                    </td>
                  </tr>";
        }
        echo "</table>";
        
        if (!$found) {
            echo "<p style='color:orange;'>No matching employees found.</p>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Search failed: " . $e->getMessage() . "</div>";
    }
}

// ==========================
// 🔹 Show Full List
// ==========================
if (isset($_POST['show_list']) || isset($_POST['submit']) || isset($_POST['delete_btn'])) {
    try {
        $sql = "SELECT EmployeeID, FirstName, LastName, Department FROM Employees ORDER BY EmployeeID";
        $stmt = executeQuery($conn, $sql);
        
        echo "<h2>Employee List</h2><table><tr><th>ID</th><th>First</th><th>Last</th><th>Department</th><th>Action</th></tr>";
        $count = 0;
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $count++;
            echo "<tr>
                    <td>" . sanitizeOutput($row['EmployeeID']) . "</td>
                    <td>" . sanitizeOutput($row['FirstName']) . "</td>
                    <td>" . sanitizeOutput($row['LastName']) . "</td>
                    <td>" . sanitizeOutput($row['Department']) . "</td>
                    <td>
                        <form method='post' style='display:inline;'>
                            <input type='hidden' name='delete_id' value='" . sanitizeOutput($row['EmployeeID']) . "'>
                            <button class='btn' style='background-color:red;' type='submit' name='delete_btn' onclick='return confirm(\"Are you sure you want to delete this employee?\")'>Delete</button>
                        </form>
                    </td>
                  </tr>";
        }
        echo "</table>";
        
        if ($count == 0) {
            echo "<p style='color:orange;'>No employees found in the database.</p>";
        } else {
            echo "<p>Total employees: $count</p>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ List fetch failed: " . $e->getMessage() . "</div>";
    }
}

// Close connection
if ($conn) {
    sqlsrv_close($conn);
}
?>
</body>
</html>
