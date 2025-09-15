
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'header.php';
require_once 'config.php';
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

// Check if user has permission to access this page
if (!hasPermission('hr_manager')) {
    header("Location: leave_management.php");
    exit();
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
}

// Initialize variables
$currentLeaves = [];
$allLeaves = [];

// Fetch data for displays
try {
    // Get employees currently on leave
    $currentLeavesQuery = "SELECT la.*, e.employee_id, e.first_name, e.last_name,
                          lt.name as leave_type_name
                          FROM leave_applications la
                          JOIN employees e ON la.employee_id = e.id
                          JOIN leave_types lt ON la.leave_type_id = lt.id
                          WHERE la.status = 'approved'
                          AND la.start_date <= CURDATE()
                          AND la.end_date >= CURDATE()
                          ORDER BY la.end_date ASC";
    $currentLeavesResult = $conn->query($currentLeavesQuery);
    $currentLeaves = $currentLeavesResult->fetch_all(MYSQLI_ASSOC);

    // Get all leave applications (recent 50)
    $allLeavesQuery = "SELECT la.*, e.employee_id, e.first_name, e.last_name,
                      lt.name as leave_type_name
                      FROM leave_applications la
                      JOIN employees e ON la.employee_id = e.id
                      JOIN leave_types lt ON la.leave_type_id = lt.id
                      ORDER BY la.applied_at DESC
                      LIMIT 50";
    $allLeavesResult = $conn->query($allLeavesQuery);
    $allLeaves = $allLeavesResult->fetch_all(MYSQLI_ASSOC);

} catch (Exception $e) {
    $error = "Error fetching data: " . $e->getMessage();
}
include 'nav_bar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave History - HR Management System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
   <div class="container">
       
        <!-- Main Content Area -->
        <div class="main-content">
            <div class="leave-tabs">
                    <a href="leave_management.php" class="leave-tab">Apply Leave</a>
                    <?php if (in_array($user['role'], ['hr_manager', 'dept_head', 'section_head', 'manager', 'managing_director','super_admin'])): ?>
                    <a href="manage.php" class="leave-tab">Manage Leave</a>
                    <?php endif; ?>
                    <?php if(in_array($user['role'], ['hr_manager', 'super_admin', 'manager','managing_director'])): ?>
                    <a href="history.php" class="leave-tab">Leave History</a>
                    <?php endif; ?>
                    <?php if(!hasPermission('hr_manager'))  :?>
                        <a href="holidays.php" class="leave-tab">Holidays</a>
                        <?php endif; ?>
                    <a href="profile.php" class="leave-tab active">My Leave Profile</a>
                </div>
                <!-- Leave History Tab Content -->
                <div class="tab-content">
                    <h3>Leave History</h3>

                    <!-- Employees Currently on Leave -->
                    <div class="table-container mb-4">
                        <h4>Employees Currently on Leave</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days</th>
                                    <th>Remaining Days</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($currentLeaves)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No employees currently on leave</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($currentLeaves as $leave): ?>
                                    <?php
                                        $today = new DateTime();
                                        $endDate = new DateTime($leave['end_date']);
                                        $remainingDays = $today->diff($endDate)->days;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($leave['employee_id'] . ' - ' . $leave['first_name'] . ' ' . $leave['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                                        <td><?php echo formatDate($leave['start_date']); ?></td>
                                        <td><?php echo formatDate($leave['end_date']); ?></td>
                                        <td><?php echo $leave['days_requested']; ?></td>
                                        <td><?php echo $remainingDays; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- All Leave History -->
                    <div class="table-container">
                        <h4>All Leave Applications (Recent 50)</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days</th>
                                    <th>Applied Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allLeaves as $leave): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($leave['employee_id'] . ' - ' . $leave['first_name'] . ' ' . $leave['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($leave['leave_type_name']); ?></td>
                                    <td><?php echo formatDate($leave['start_date']); ?></td>
                                    <td><?php echo formatDate($leave['end_date']); ?></td>
                                    <td><?php echo $leave['days_requested']; ?></td>
                                    <td><?php echo formatDate($leave['applied_at']); ?></td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'pending' => 'badge-warning',
                                            'approved' => 'badge-success',
                                            'rejected' => 'badge-danger',
                                            'cancelled' => 'badge-secondary'
                                        ];
                                        ?>
                                        <span class="badge <?php echo $statusClass[$leave['status']] ?? 'badge-light'; ?>">
                                            <?php echo ucfirst($leave['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
