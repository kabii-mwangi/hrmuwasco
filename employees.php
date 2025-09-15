<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Get database connection
$conn = getConnection();

// Get current user from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id']
];
// Permission check function
function hasPermission($requiredRole) {
    $userRole = $_SESSION['user_role'] ?? 'guest';
    
    // Permission hierarchy
    $roles = [
        'super_admin' => 5,
        'hr_manager' =>4 ,
        'managing_director'=>3,
        'dept_head' => 2,
        'section head'=>1,
        'employee' => 0
    ];
    
    $userLevel = $roles[$userRole] ?? 0;
    $requiredLevel = $roles[$requiredRole] ?? 0;
    
    return $userLevel >= $requiredLevel;
}


// Helper functions
function getEmployeeTypeBadge($type) {
    $badges = [
        'full_time' => 'badge-primary',
        'part_time' => 'badge-info',
        'contract' => 'badge-warning',
        'temporary' => 'badge-secondary',
        'officer' => 'badge-primary',
        'section_head' => 'badge-info',
        'manager' => 'badge-success',
        'hr_manager' => 'badge-success',
        'dept_head' => 'badge-info',
        'managing_director' => 'badge-primary',
        'bod_chairman' => 'badge-primary'
    ];
    return $badges[$type] ?? 'badge-light';
}

function getEmployeeStatusBadge($status) {
    $badges = [
        'active' => 'badge-success',
        'on_leave' => 'badge-warning',
        'terminated' => 'badge-danger',
        'resigned' => 'badge-secondary',
        'inactive' => 'badge-secondary',
        'fired' => 'badge-danger',
        'retired' => 'badge-secondary'
    ];
    return $badges[$status] ?? 'badge-light';
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return false;
}

function redirectWithMessage($url, $message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

function sanitizeInput($data) {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(stripslashes(trim($data)));
}

// Get departments and sections for forms
$departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$sections = $conn->query("SELECT s.*, d.name as department_name FROM sections s LEFT JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name")->fetch_all(MYSQLI_ASSOC);

// Employees data (only if HR)
$employees = [];
if (hasPermission('hr_manager')) {
    $search = $_GET['search'] ?? '';
    $department_filter = $_GET['department'] ?? '';
    $section_filter = $_GET['section'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $where_conditions = [];
    $params = [];
    $types = '';
    if (!empty($search)) {
        $where_conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
        $types .= 'ssss';
    }
    if (!empty($department_filter)) {
        $where_conditions[] = "e.department_id = ?";
        $params[] = $department_filter;
        $types .= 'i';
    }
    if (!empty($section_filter)) {
        $where_conditions[] = "e.section_id = ?";
        $params[] = $section_filter;
        $types .= 'i';
    }
    if (!empty($type_filter)) {
        $where_conditions[] = "e.employee_type = ?";
        $params[] = $type_filter;
        $types .= 's';
    }
    if (!empty($status_filter)) {
        $where_conditions[] = "e.employee_status = ?";
        $params[] = $status_filter;
        $types .= 's';
    }
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    $query = "
        SELECT e.*,
               COALESCE(e.first_name, '') as first_name,
               COALESCE(e.last_name, '') as last_name,
               d.name as department_name,
               s.name as section_name
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN sections s ON e.section_id = s.id
        $where_clause
        ORDER BY e.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
}

// Define job groups
$job_groups = ['1', '2', '3', '3A', '3B', '3C', '4', '5', '6', '7', '8', '9', '10'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('hr_manager')) {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        if ($action === 'add') {
            $employee_id = sanitizeInput($_POST['employee_id']);
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $gender = isset($_POST['gender']) ? sanitizeInput($_POST['gender']) : '';
            $national_id = sanitizeInput($_POST['national_id']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['phone']);
            $address = sanitizeInput($_POST['address']);
            $date_of_birth = $_POST['date_of_birth'];
            $hire_date = $_POST['hire_date'];
            $designation = sanitizeInput($_POST['designation']) ?: 'Employee';
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
            $employee_type = $_POST['employee_type'];
            $employment_type = $_POST['employment_type'] ?: 'permanent';
            $job_group = in_array($_POST['job_group'], $job_groups) ? $_POST['job_group'] : null;

            // Handle multiple next of kin as JSON
            $next_of_kin_array = [];
            $nok_count = count($_POST['next_of_kin_name'] ?? []);
            for ($i = 0; $i < $nok_count; $i++) {
                $name = sanitizeInput($_POST['next_of_kin_name'][$i] ?? '');
                $relationship = sanitizeInput($_POST['next_of_kin_relationship'][$i] ?? '');
                $contact = sanitizeInput($_POST['next_of_kin_contact'][$i] ?? '');
                if (!empty($name)) {
                    $next_of_kin_array[] = [
                        'name' => $name,
                        'relationship' => $relationship,
                        'contact' => $contact
                    ];
                }
            }
            $next_of_kin = json_encode($next_of_kin_array);

            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare("INSERT INTO employees (employee_id, first_name, last_name, gender, national_id, phone, email, date_of_birth, designation, department_id, section_id, employee_type, employment_type, address, hire_date, job_group, next_of_kin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssssiissssss", $employee_id, $first_name, $last_name, $gender, $national_id, $phone, $email, $date_of_birth, $designation, $department_id, $section_id, $employee_type, $employment_type, $address, $hire_date, $job_group, $next_of_kin);
                $stmt->execute();
                $new_employee_id = $conn->insert_id;
                $payroll_status = 'active';
                $payroll_stmt = $conn->prepare("INSERT INTO payroll (emp_id, employment_type, status, job_group) VALUES (?, ?, ?, ?)");
                $payroll_stmt->bind_param("isss", $new_employee_id, $employment_type, $payroll_status, $job_group);
                $payroll_stmt->execute();
                $user_role = 'employee';
                switch($employee_type) {
                    case 'managing_director':
                    case 'bod_chairman':
                        $user_role = 'super_admin';
                        break;
                    case 'dept_head':
                        $user_role = 'dept_head';
                        break;
                    case 'hr_manager':
                        $user_role = 'hr_manager';
                        break;
                    case 'manager':
                        $user_role = 'manager';
                        break;
                    case 'section_head':
                        $user_role = 'section_head';
                        break;
                    default:
                        $user_role = 'employee';
                        break;
                }
                $hashed_password = password_hash($employee_id, PASSWORD_DEFAULT);
                $user_stmt = $conn->prepare("INSERT INTO users (email, first_name, last_name, gender, password, role, phone, address, employee_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $user_stmt->bind_param("sssssssss", $email, $first_name, $last_name, $gender, $hashed_password, $user_role, $phone, $address, $employee_id);
                $user_stmt->execute();
                $conn->commit();
                redirectWithMessage('employees.php', 'Employee, user account, and payroll entry created successfully! Default password is the employee ID.', 'success');
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error adding employee: ' . $e->getMessage();
            }
        } elseif ($action === 'edit') {
            $id = $_POST['id'];
            $employee_id = sanitizeInput($_POST['employee_id']);
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $gender = isset($_POST['gender']) ? sanitizeInput($_POST['gender']) : '';
            $national_id = sanitizeInput($_POST['national_id']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['phone']);
            $address = sanitizeInput($_POST['address']);
            $date_of_birth = $_POST['date_of_birth'];
            $hire_date = $_POST['hire_date'];
            $designation = sanitizeInput($_POST['designation']);
            $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
            $section_id = !empty($_POST['section_id']) ? $_POST['section_id'] : null;
            $employee_type = $_POST['employee_type'];
            $employment_type = $_POST['employment_type'];
            $employee_status = $_POST['employee_status'];
            $job_group = in_array($_POST['job_group'], $job_groups) ? $_POST['job_group'] : null;

            // Handle multiple next of kin as JSON
            $next_of_kin_array = [];
            $nok_count = count($_POST['next_of_kin_name'] ?? []);
            for ($i = 0; $i < $nok_count; $i++) {
                $name = sanitizeInput($_POST['next_of_kin_name'][$i] ?? '');
                $relationship = sanitizeInput($_POST['next_of_kin_relationship'][$i] ?? '');
                $contact = sanitizeInput($_POST['next_of_kin_contact'][$i] ?? '');
                if (!empty($name)) {
                    $next_of_kin_array[] = [
                        'name' => $name,
                        'relationship' => $relationship,
                        'contact' => $contact
                    ];
                }
            }
            $next_of_kin = json_encode($next_of_kin_array);

            try {
                $conn->begin_transaction();
                $current_emp_stmt = $conn->prepare("SELECT employee_id FROM employees WHERE id = ?");
                $current_emp_stmt->bind_param("i", $id);
                $current_emp_stmt->execute();
                $current_emp_result = $current_emp_stmt->get_result();
                $current_employee = $current_emp_result->fetch_assoc();
                $old_employee_id = $current_employee['employee_id'];
                $stmt = $conn->prepare("UPDATE employees SET employee_id=?, first_name=?, last_name=?, gender=?, national_id=?, email=?, phone=?, address=?, date_of_birth=?, hire_date=?, designation=?, department_id=?, section_id=?, employee_type=?, employment_type=?, employee_status=?, job_group=?, next_of_kin=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("ssssssssssiissssssi", $employee_id, $first_name, $last_name, $gender, $national_id, $email, $phone, $address, $date_of_birth, $hire_date, $designation, $department_id, $section_id, $employee_type, $employment_type, $employee_status, $job_group, $next_of_kin, $id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
                }
                $payroll_status = ($employee_status === 'active') ? 'active' : 'inactive';
                $payroll_stmt = $conn->prepare("UPDATE payroll SET job_group = ?, employment_type = ?, status = ? WHERE emp_id = ?");
                $payroll_stmt->bind_param("sssi", $job_group, $employment_type, $payroll_status, $id);
                $payroll_stmt->execute();
                $user_role = 'employee';
                switch($employee_type) {
                    case 'managing_director':
                    case 'bod_chairman':
                        $user_role = 'super_admin';
                        break;
                    case 'dept_head':
                        $user_role = 'dept_head';
                        break;
                    case 'hr_manager':
                        $user_role = 'hr_manager';
                        break;
                    case 'manager':
                        $user_role = 'manager';
                        break;
                    case 'section_head':
                        $user_role = 'section_head';
                        break;
                    default:
                        $user_role = 'employee';
                        break;
                }
                $user_update_stmt = $conn->prepare("UPDATE users SET email=?, first_name=?, last_name=?, gender=?, role=?, phone=?, address=?, employee_id=?, updated_at=NOW() WHERE employee_id=?");
                $user_update_stmt->bind_param("sssssssss", $email, $first_name, $last_name, $gender, $user_role, $phone, $address, $employee_id, $old_employee_id);
                $user_update_stmt->execute();
                $conn->commit();
                redirectWithMessage('employees.php', 'Employee and user account updated successfully!', 'success');
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error updating employee: ' . $e->getMessage();
            }
        }
    }
}

include 'header.php';
include 'nav_bar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - HR Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
      
        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Tabs -->
            <div class="tabs">
                <ul>
                    <li>
                        <a href="personal_profile.php" class="tab-link <?= basename($_SERVER['PHP_SELF']) === 'personal_profile.php' ? 'active' : '' ?>" data-tab="profile">
                            My Profile
                        </a>
                    </li>
                    <?php if (hasPermission('hr_manager')): ?>
                        <li>
                            <a href="employees.php" class="tab-link <?= basename($_SERVER['PHP_SELF']) === 'employees.php' ? 'active' : '' ?>" data-tab="employees">
                                Manage Employees
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <!-- Content -->
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type']; ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (hasPermission('hr_manager')): ?>
                    <div id="employees" class="tab-content active">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h2>Employees (<?php echo count($employees); ?>)</h2>
                            <button onclick="showAddModal()" class="btn btn-success">Add New Employee</button>
                        </div>
                        <!-- Search and Filters -->
                        <div class="search-filters">
                            <form method="GET" action="">
                                <div class="filter-row">
                                    <div class="form-group">
                                        <label for="search">Search</label>
                                        <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, ID, or Email">
                                    </div>
                                    <div class="form-group">
                                        <label for="department">Department</label>
                                        <select class="form-control" id="department" name="department">
                                            <option value="">All Departments</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="type">Employee Type</label>
                                        <select class="form-control" id="type" name="type">
                                            <option value="">All Types</option>
                                            <option value="officer" <?php echo $type_filter === 'officer' ? 'selected' : ''; ?>>Officer</option>
                                            <option value="section_head" <?php echo $type_filter === 'section_head' ? 'selected' : ''; ?>>Section Head</option>
                                            <option value="manager" <?php echo $type_filter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            <option value="hr_manager" <?php echo $type_filter === 'hr_manager' ? 'selected' : ''; ?>>Human Resource Manager</option>
                                            <option value="dept_head" <?php echo $type_filter === 'dept_head' ? 'selected' : ''; ?>>Department Head</option>
                                            <option value="managing_director" <?php echo $type_filter === 'managing_director' ? 'selected' : ''; ?>>Managing Director</option>
                                            <option value="bod_chairman" <?php echo $type_filter === 'bod_chairman' ? 'selected' : ''; ?>>BOD Chairman</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select class="form-control" id="status" name="status">
                                            <option value="">All Status</option>
                                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="resigned" <?php echo $status_filter === 'resigned' ? 'selected' : ''; ?>>Resigned</option>
                                            <option value="fired" <?php echo $status_filter === 'fired' ? 'selected' : ''; ?>>Fired</option>
                                            <option value="retired" <?php echo $status_filter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <button type="submit" class="btn btn-primary">Filter</button>
                                        <a href="employees.php" class="btn btn-secondary">Clear</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <!-- Employees Table -->
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Section</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Job Group</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($employees)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No employees found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($employees as $emp): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                                <td><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($emp['email'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($emp['department_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($emp['section_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge <?php echo getEmployeeTypeBadge($emp['employee_type'] ?? ''); ?>">
                                                        <?php
                                                        $type = $emp['employee_type'] ?? '';
                                                        echo $type ? ucwords(str_replace('_', ' ', $type)) : 'N/A';
                                                        ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo getEmployeeStatusBadge($emp['employee_status'] ?? ''); ?>">
                                                        <?php
                                                        $status = $emp['employee_status'] ?? '';
                                                        echo $status ? ucwords($status) : 'N/A';
                                                        ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($emp['job_group'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <button onclick="showEditModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)" class="btn btn-sm btn-primary">Edit</button>
                                                    <a href="personal_profile.php?view_employee=<?php echo $emp['id']; ?>" class="btn btn-sm btn-info">Profile</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">You do not have permission to view this page.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Add Employee Modal -->
    <?php if (hasPermission('hr_manager')): ?>
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Employee</h3>
                <span class="close" onclick="hideAddModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label for="employee_id">Employee ID</label>
                        <input type="text" class="form-control" id="employee_id" name="employee_id" required>
                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select class="form-control" id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="national_id">National ID</label>
                        <input type="text" class="form-control" id="national_id" name="national_id" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="designation">Designation</label>
                        <input type="text" class="form-control" id="designation" name="designation" required placeholder="e.g. Software Engineer">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" class="form-control" id="phone" name="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="hire_date">Hire Date</label>
                        <input type="date" class="form-control" id="hire_date" name="hire_date" required>
                    </div>
                    <div class="form-group">
                        <label for="employment_type">Employment Type</label>
                        <select class="form-control" id="employment_type" name="employment_type" required>
                            <option value="">Select Type</option>
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                            <option value="intern">Intern</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="employee_type">Employee Type</label>
                        <select class="form-control" id="employee_type" name="employee_type" required onchange="handleEmployeeTypeChange()">
                            <option value="">Select Type</option>
                            <option value="officer">Officer</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="hr_manager">Human Resource Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="managing_director">Managing Director</option>
                            <option value="bod_chairman">BOD Chairman</option>
                        </select>
                    </div>
                    <div class="form-group" id="department_group">
                        <label for="department_id">Department</label>
                        <select class="form-control" id="department_id" name="department_id" onchange="updateSections()">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="section_group">
                    <label for="section_id">Section</label>
                    <select class="form-control" id="section_id" name="section_id">
                        <option value="">Select Section</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="job_group">Job Group</label>
                    <select class="form-control" id="job_group" name="job_group" required>
                        <option value="">Select Job Group</option>
                        <?php foreach ($job_groups as $group): ?>
                            <option value="<?php echo htmlspecialchars($group); ?>"><?php echo htmlspecialchars($group); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Next of Kin</label>
                    <div id="next_of_kin_container">
                        <!-- Initial empty item -->
                        <div class="next_of_kin_item form-row">
                            <div class="form-group">
                                <input type="text" name="next_of_kin_name[]" placeholder="Name" class="form-control">
                            </div>
                            <div class="form-group">
                                <input type="text" name="next_of_kin_relationship[]" placeholder="Relationship" class="form-control">
                            </div>
                            <div class="form-group">
                                <input type="text" name="next_of_kin_contact[]" placeholder="Contact (Phone)" class="form-control">
                            </div>
                            <button type="button" onclick="removeNok(this)" class="btn btn-danger btn-sm">Remove</button>
                        </div>
                    </div>
                    <button type="button" onclick="addNok()" class="btn btn-secondary">Add Next of Kin</button>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Add Employee</button>
                    <button type="button" class="btn btn-secondary" onclick="hideAddModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Edit Employee Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Employee</h3>
                <span class="close" onclick="hideEditModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" id="edit_id" name="id">
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_employee_id">Employee ID</label>
                        <input type="text" class="form-control" id="edit_employee_id" name="employee_id" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_first_name">First Name</label>
                        <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_last_name">Last Name</label>
                        <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_gender">Gender</label>
                        <select class="form-control" id="edit_gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_national_id">National ID</label>
                        <input type="text" class="form-control" id="edit_national_id" name="national_id" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_designation">Designation</label>
                        <input type="text" class="form-control" id="edit_designation" name="designation" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_phone">Phone</label>
                        <input type="text" class="form-control" id="edit_phone" name="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control" id="edit_date_of_birth" name="date_of_birth" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_address">Address</label>
                    <textarea class="form-control" id="edit_address" name="address" rows="3"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_hire_date">Hire Date</label>
                        <input type="date" class="form-control" id="edit_hire_date" name="hire_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_employment_type">Employment Type</label>
                        <select class="form-control" id="edit_employment_type" name="employment_type" required>
                            <option value="">Select Type</option>
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                            <option value="intern">Intern</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_employee_type">Employee Type</label>
                        <select class="form-control" id="edit_employee_type" name="employee_type" required onchange="handleEditEmployeeTypeChange()">
                            <option value="">Select Type</option>
                            <option value="officer">Officer</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="hr_manager">Human Resource Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="managing_director">Managing Director</option>
                            <option value="bod_chairman">BOD Chairman</option>
                        </select>
                    </div>
                    <div class="form-group" id="edit_department_group">
                        <label for="edit_department_id">Department</label>
                        <select class="form-control" id="edit_department_id" name="department_id" onchange="updateEditSections()">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="edit_section_group">
                    <label for="edit_section_id">Section</label>
                    <select class="form-control" id="edit_section_id" name="section_id">
                        <option value="">Select Section</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_employee_status">Status</label>
                    <select class="form-control" id="edit_employee_status" name="employee_status" required>
                        <option value="">Select Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="resigned">Resigned</option>
                        <option value="fired">Fired</option>
                        <option value="retired">Retired</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_job_group">Job Group</label>
                    <select class="form-control" id="edit_job_group" name="job_group" required>
                        <option value="">Select Job Group</option>
                        <?php foreach ($job_groups as $group): ?>
                            <option value="<?php echo htmlspecialchars($group); ?>"><?php echo htmlspecialchars($group); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Next of Kin</label>
                    <div id="edit_next_of_kin_container">
                        <!-- Items will be populated dynamically -->
                    </div>
                    <button type="button" onclick="addEditNok()" class="btn btn-secondary">Add Next of Kin</button>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Update Employee</button>
                    <button type="button" class="btn btn-secondary" onclick="hideEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <script>
        // Sections data for dynamic population
        const sectionsData = <?php echo json_encode($sections); ?>;
        
        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
            updateSections();
            // Ensure at least one empty NOK field
            if (document.querySelectorAll('#next_of_kin_container .next_of_kin_item').length === 0) {
                addNok();
            }
        }
        
        function hideAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addModal').querySelector('form').reset();
            document.getElementById('section_id').innerHTML = '<option value="">Select Section</option>';
            document.getElementById('next_of_kin_container').innerHTML = '';
        }
        
        function showEditModal(employee) {
            document.getElementById('edit_id').value = employee.id;
            document.getElementById('edit_employee_id').value = employee.employee_id;
            document.getElementById('edit_first_name').value = employee.first_name;
            document.getElementById('edit_last_name').value = employee.last_name;
            document.getElementById('edit_gender').value = employee.gender || '';
            document.getElementById('edit_national_id').value = employee.national_id || '';
            document.getElementById('edit_email').value = employee.email || '';
            document.getElementById('edit_phone').value = employee.phone || '';
            document.getElementById('edit_address').value = employee.address || '';
            document.getElementById('edit_date_of_birth').value = employee.date_of_birth || '';
            document.getElementById('edit_hire_date').value = employee.hire_date || '';
            document.getElementById('edit_designation').value = employee.designation || '';
            document.getElementById('edit_employment_type').value = employee.employment_type || '';
            document.getElementById('edit_employee_type').value = employee.employee_type || '';
            document.getElementById('edit_department_id').value = employee.department_id || '';
            document.getElementById('edit_employee_status').value = employee.employee_status || '';
            document.getElementById('edit_job_group').value = employee.job_group || '';
            
            // Populate next of kin
            const editNokContainer = document.getElementById('edit_next_of_kin_container');
            editNokContainer.innerHTML = '';
            let nokList = [];
            try {
                nokList = JSON.parse(employee.next_of_kin) || [];
            } catch (e) {
                nokList = [];
            }
            if (nokList.length === 0) {
                addEditNok();
            } else {
                nokList.forEach(nok => {
                    addEditNok(nok.name, nok.relationship, nok.contact);
                });
            }

            updateEditSections(employee.section_id);
            handleEditEmployeeTypeChange();
            document.getElementById('editModal').style.display = 'block';
        }
        
        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editModal').querySelector('form').reset();
            document.getElementById('edit_section_id').innerHTML = '<option value="">Select Section</option>';
            document.getElementById('edit_next_of_kin_container').innerHTML = '';
        }
        
        function updateSections() {
            const departmentId = document.getElementById('department_id').value;
            const sectionSelect = document.getElementById('section_id');
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            if (departmentId) {
                const filteredSections = sectionsData.filter(section => section.department_id == departmentId);
                filteredSections.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.id;
                    option.textContent = section.name;
                    sectionSelect.appendChild(option);
                });
            }
        }
        
        function updateEditSections(selectedSectionId = '') {
            const departmentId = document.getElementById('edit_department_id').value;
            const sectionSelect = document.getElementById('edit_section_id');
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            if (departmentId) {
                const filteredSections = sectionsData.filter(section => section.department_id == departmentId);
                filteredSections.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section.id;
                    option.textContent = section.name;
                    if (section.id == selectedSectionId) {
                        option.selected = true;
                    }
                    sectionSelect.appendChild(option);
                });
            }
        }
        
        function handleEmployeeTypeChange() {
            const employeeType = document.getElementById('employee_type').value;
            const departmentGroup = document.getElementById('department_group');
            const sectionGroup = document.getElementById('section_group');
            if (employeeType === 'managing_director' || employeeType === 'bod_chairman') {
                departmentGroup.style.display = 'none';
                sectionGroup.style.display = 'none';
                document.getElementById('department_id').value = '';
                document.getElementById('section_id').value = '';
            } else {
                departmentGroup.style.display = 'block';
                sectionGroup.style.display = 'block';
            }
        }
        
        function handleEditEmployeeTypeChange() {
            const employeeType = document.getElementById('edit_employee_type').value;
            const departmentGroup = document.getElementById('edit_department_group');
            const sectionGroup = document.getElementById('edit_section_group');
            if (employeeType === 'managing_director' || employeeType === 'bod_chairman') {
                departmentGroup.style.display = 'none';
                sectionGroup.style.display = 'none';
                document.getElementById('edit_department_id').value = '';
                document.getElementById('edit_section_id').value = '';
            } else {
                departmentGroup.style.display = 'block';
                sectionGroup.style.display = 'block';
                updateEditSections(document.getElementById('edit_section_id').value);
            }
        }

        // Functions for adding/removing next of kin in add modal
        function addNok(name = '', relationship = '', contact = '') {
            const container = document.getElementById('next_of_kin_container');
            const item = document.createElement('div');
            item.className = 'next_of_kin_item form-row';
            item.innerHTML = `
                <div class="form-group">
                    <input type="text" name="next_of_kin_name[]" placeholder="Name" value="${name}" class="form-control">
                </div>
                <div class="form-group">
                    <input type="text" name="next_of_kin_relationship[]" placeholder="Relationship" value="${relationship}" class="form-control">
                </div>
                <div class="form-group">
                    <input type="text" name="next_of_kin_contact[]" placeholder="Contact (Phone)" value="${contact}" class="form-control">
                </div>
                <button type="button" onclick="removeNok(this)" class="btn btn-danger btn-sm">Remove</button>
            `;
            container.appendChild(item);
        }

        function removeNok(btn) {
            btn.parentElement.remove();
        }

        // Functions for adding/removing next of kin in edit modal
        function addEditNok(name = '', relationship = '', contact = '') {
            const container = document.getElementById('edit_next_of_kin_container');
            const item = document.createElement('div');
            item.className = 'next_of_kin_item form-row';
            item.innerHTML = `
                <div class="form-group">
                    <input type="text" name="next_of_kin_name[]" placeholder="Name" value="${name}" class="form-control">
                </div>
                <div class="form-group">
                    <input type="text" name="next_of_kin_relationship[]" placeholder="Relationship" value="${relationship}" class="form-control">
                </div>
                <div class="form-group">
                    <input type="text" name="next_of_kin_contact[]" placeholder="Contact (Phone)" value="${contact}" class="form-control">
                </div>
                <button type="button" onclick="removeEditNok(this)" class="btn btn-danger btn-sm">Remove</button>
            `;
            container.appendChild(item);
        }

        function removeEditNok(btn) {
            btn.parentElement.remove();
        }
        
        window.onclick = function(event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target == addModal) {
                hideAddModal();
            } else if (event.target == editModal) {
                hideEditModal();
            }
        }

        // Initialize with one empty NOK in add modal
        addNok();
    </script>
</body>
</html>