<!DOCTYPE html>
<html>
<head>
    <title>Azure SQL Employee Portal</title>
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
    </style>
</head>
<body>
    <h1>Azure SQL Employee Portal</h1>

    <!-- Buttons -->
    <form method="post">
        <button class="btn" name="show_form" value="1">Add Employee</button>
        <button class="btn" name="show_list" value="1">Employee List</button>
    </form>

<?php
// DB Connection
$serverName = "tcp:mydemovm.database.windows.net,1433";
$connectionOptions = array(
    "Database" => "mydemodb",
    "Uid" => "azureadmin",
    "PWD" => "Welcome@123456",
    "Encrypt" => true,
    "TrustServerCertificate" => false
);

$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die("<p style='color:red;'>❌ Connection failed: " . print_r(sqlsrv_errors(), true) . "</p>");
}

// 1. Handle Delete
if (isset($_POST['delete_btn']) && isset($_POST['delete_id'])) {
    $deleteId = $_POST['delete_id'];
    $deleteQuery = "DELETE FROM Employees WHERE EmployeeID = ?";
    $stmt = sqlsrv_query($conn, $deleteQuery, array($deleteId));
    echo $stmt ? "<p style='color:green;'>✅ Deleted Employee ID $deleteId</p>"
               : "<p style='color:red;'>❌ Delete failed: " . print_r(sqlsrv_errors(), true) . "</p>";
}

// 2. Handle Insert
if (isset($_POST['submit'])) {
    $first = $_POST['first_name'];
    $last = $_POST['last_name'];
    $dept = $_POST['department'];
    $insert = "INSERT INTO Employees (FirstName, LastName, Department) VALUES (?, ?, ?)";
    $params = array($first, $last, $dept);
    $stmt = sqlsrv_query($conn, $insert, $params);
    echo $stmt ? "<p style='color:green;'>✅ Employee added successfully!</p>"
               : "<p style='color:red;'>❌ Insert failed: " . print_r(sqlsrv_errors(), true) . "</p>";
}

// 3. Show Add Form
if (isset($_POST['show_form'])) {
    echo '
    <form method="post">
        <h2>Add New Employee</h2>
        <input type="text" name="first_name" placeholder="First Name" required><br>
        <input type="text" name="last_name" placeholder="Last Name" required><br>
        <input type="text" name="department" placeholder="Department" required><br>
        <input class="btn" type="submit" name="submit" value="Save">
    </form>';
}

// 4. Show Search Form
echo '
<form method="post">
    <h2>Search Employees</h2>
    <input type="text" name="search_lastname" placeholder="Last Name (e.g., Pat%)">
    <input type="text" name="search_department" placeholder="Department (optional)">
    <input class="btn" type="submit" name="search_btn" value="Search">
</form>';

// 5. Handle Search
if (isset($_POST['search_btn'])) {
    $lastname = $_POST['search_lastname'] ?? '';
    $department = $_POST['search_department'] ?? '';

    $sql = "SELECT EmployeeID, FirstName, LastName, Department FROM Employees WHERE 1=1";
    $params = [];

    if (!empty($lastname)) {
        $sql .= " AND LastName LIKE ?";
        $params[] = $lastname;
    }

        $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt !== false) {
        echo "<h2>Search Results</h2><table><tr><th>ID</th><th>First</th><th>Last</th><th>Department</th><th>Action</th></tr>";
        $found = false;
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $found = true;
            echo "<tr>
                    <td>{$row['EmployeeID']}</td>
                    <td>{$row['FirstName']}</td>
                    <td>{$row['LastName']}</td>
                    <td>{$row['Department']}</td>
                    <td>
                        <form method='post' style='display:inline;'>
                            <input type='hidden' name='delete_id' value='{$row['EmployeeID']}'>
                            <button class='btn' style='background-color:red;' type='submit' name='delete_btn'>Delete</button>
                        </form>
                    </td>
                  </tr>";
        }
        echo "</table>";
        if (!$found) {
            echo "<p style='color:orange;'>No matching employees found.</p>";
        }
    } else {
        echo "<p style='color:red;'>❌ Search failed: " . print_r(sqlsrv_errors(), true) . "</p>";
    }
}

// 6. Show Full List
if (isset($_POST['show_list']) || isset($_POST['submit']) || isset($_POST['delete_btn'])) {
    $sql = "SELECT EmployeeID, FirstName, LastName, Department FROM Employees";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt !== false) {
        echo "<h2>Employee List</h2><table><tr><th>ID</th><th>First</th><th>Last</th><th>Department</th><th>Action</th></tr>";
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            echo "<tr>
                    <td>{$row['EmployeeID']}</td>
                    <td>{$row['FirstName']}</td>
                    <td>{$row['LastName']}</td>
                    <td>{$row['Department']}</td>
                    <td>
                        <form method='post' style='display:inline;'>
                            <input type='hidden' name='delete_id' value='{$row['EmployeeID']}'>
                            <button class='btn' style='background-color:red;' type='submit' name='delete_btn'>Delete</button>
                        </form>
                    </td>
                  </tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:red;'>❌ List fetch failed: " . print_r(sqlsrv_errors(), true) . "</p>";
    }
}

sqlsrv_close($conn);
?>
</body>
</html>
