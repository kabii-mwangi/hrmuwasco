<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config.php';

// Set page title for header
$pageTitle = 'Dashboard';

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
        'manager' => 'badge-success'
    ];
    return $badges[$type] ?? 'badge-light';
}

function getEmployeeStatusBadge($status) {
    $badges = [
        'active' => 'badge-success',
        'on_leave' => 'badge-warning',
        'terminated' => 'badge-danger',
        'resigned' => 'badge-secondary'
    ];
    return $badges[$status] ?? 'badge-light';
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
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

// Financial Year Functions (copied/adapted from admin.php for dashboard use)
function getCurrentFinancialYear(?string $current_date = null, ?mysqli $mysqli = null): array {
    if ($current_date === null) {
        $current_date = date('Y-m-d');
    }
    if ($mysqli !== null) {
        return getCurrentFinancialYearFromDatabase($current_date, $mysqli);
    }
    return calculateFinancialYearByDate($current_date);
}

function getCurrentFinancialYearFromDatabase(string $current_date, mysqli $mysqli): array {
    $stmt = $mysqli->prepare("SELECT * FROM financial_years 
                             WHERE ? BETWEEN start_date AND end_date 
                             AND is_active = 1 
                             ORDER BY start_date DESC 
                             LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $current_date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return [
                'id' => $row['id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'year_name' => $row['year_name'],
                'from_database' => true
            ];
        }
    }
    $stmt = $mysqli->prepare("SELECT * FROM financial_years 
                             WHERE is_active = 1 
                             ORDER BY start_date DESC 
                             LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return [
                'id' => $row['id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'year_name' => $row['year_name'],
                'from_database' => true,
                'note' => 'Using most recent financial year from database'
            ];
        }
    }
    return calculateFinancialYearByDate($current_date);
}

function calculateFinancialYearByDate(string $current_date): array {
    $current_year = (int)(new DateTime($current_date))->format('Y');
    $current_month = (int)(new DateTime($current_date))->format('n');
    if ($current_month >= 7) {
        $start_year = $current_year;
        $end_year = $current_year + 1;
    } else {
        $start_year = $current_year - 1;
        $end_year = $current_year;
    }
    return [
        'start_date' => $start_year . '-07-01',
        'end_date' => $end_year . '-06-30',
        'year_name' => $start_year . '/' . substr($end_year, 2),
        'from_database' => false
    ];
}

function getNextFinancialYear(?string $current_date = null, ?mysqli $mysqli = null): array {
    $current_fy = getCurrentFinancialYear($current_date, $mysqli);
    $current_end_year = (int)explode('-', $current_fy['end_date'])[0];
    $next_start_year = $current_end_year;
    $next_end_year = $next_start_year + 1;
    return [
        'start_date' => $next_start_year . '-07-01',
        'end_date' => $next_end_year . '-06-30',
        'year_name' => $next_start_year . '/' . substr($next_end_year, 2)
    ];
}

function calculateTotalDays(string $start_date, string $end_date): int {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    return $end->diff($start)->days + 1;
}

// Get dashboard statistics
$conn = getConnection();

// Total employees
$result = $conn->query("SELECT COUNT(*) as count FROM employees WHERE employee_status = 'active'");
$totalEmployees = $result->fetch_assoc()['count'];

// Total departments
$result = $conn->query("SELECT COUNT(*) as count FROM departments");
$totalDepartments = $result->fetch_assoc()['count'];

// Total sections
$result = $conn->query("SELECT COUNT(*) as count FROM sections");
$totalSections = $result->fetch_assoc()['count'];

// Recent employees (last 30 days)
$result = $conn->query("SELECT COUNT(*) as count FROM employees WHERE hire_date >= (CURDATE() - INTERVAL 30 DAY)");
$recentHires = $result->fetch_assoc()['count'];

// Get recent employees for display
$result = $conn->query("
    SELECT e.*, 
           e.first_name,
           e.last_name,
           d.name as department_name, 
           s.name as section_name 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.id 
    LEFT JOIN sections s ON e.section_id = s.id 
    ORDER BY e.created_at DESC 
    LIMIT 5
");

$recentEmployees = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recentEmployees[] = $row;
    }
}

// Financial Year Notification Logic (for HR roles only)
$fyNotification = null;
if (hasPermission('hr_manager')) {
    $today = date('Y-m-d');
    $nextFy = getNextFinancialYear($today, $conn);
    $nextStart = new DateTime($nextFy['start_date']);
    $todayDate = new DateTime($today);
    $daysUntilStart = $nextStart->diff($todayDate)->days;
    
    // Show notification if within 14 days before start (two weeks)
    if ($daysUntilStart <= 14 && $daysUntilStart >= 0) {
        $fyNotification = [
            'days' => $daysUntilStart,
            'year' => $nextFy['year_name'],
            'start_date' => formatDate($nextFy['start_date'])
        ];
    }
}

// Close connection
$conn->close();

// Include the header (which handles theme and sets up the HTML document)
include 'header.php';
include 'nav_bar.php';
?>

<title><?php echo $pageTitle; ?> - HR Management System</title>

<style>
/* Sliding Notification Banner Styles */
.notification-banner {
    position: fixed;
    top: 100px; /* Adjust based on header/nav height */
    right: -100%; /* Start off-screen to the right */
    background: linear-gradient(45deg, #ff6b6b, #ee5a24);
    color: white;
    padding: 15px 20px;
    border-radius: 0 10px 10px 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    white-space: nowrap;
    font-weight: bold;
    font-size: 16px;
    animation: slideIn 10s linear infinite;
    cursor: pointer;
    transition: opacity 0.3s ease;
}

.notification-banner:hover {
    animation-play-state: paused;
}

@keyframes slideIn {
    0% { transform: translateX(100%); }
    10% { transform: translateX(0); }
    90% { transform: translateX(0); }
    100% { transform: translateX(-100%); }
}

/* Dismiss button for notification */
.notification-dismiss {
    margin-left: 15px;
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    padding: 5px 10px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 14px;
}
</style>

<body>
    <div class="container">
        <!-- Sidebar -->
        
        <!-- Main Content Area -->
        <div class="main-content">
            
            <!-- Content -->
            <div class="content">
                <?php $flash = getFlashMessage(); if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type']; ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($fyNotification): ?>
                <div class="notification-banner" onclick="this.style.display='none';">
                    🚨 New Financial Year <?php echo htmlspecialchars($fyNotification['year']); ?> starts in <?php echo $fyNotification['days']; ?> days (<?php echo $fyNotification['start_date']; ?>). 
                    Create it now in <a href="admin.php?tab=financial" style="color: #fff; text-decoration: underline;">Admin > Financial</a>!
                    <button class="notification-dismiss" onclick="event.stopPropagation(); this.parentElement.style.display='none';">&times;</button>
                </div>
                <?php endif; ?>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3><?php echo $totalEmployees; ?></h3>
                        <p>Active Employees</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $totalDepartments; ?></h3>
                        <p>Departments</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $totalSections; ?></h3>
                        <p>Sections</p>
                    </div>
                    <div class="stat-card">
                        <h3><?php echo $recentHires; ?></h3>
                        <p>New Hires (30 days)</p>
                    </div>
                </div>
                
                <div class="table-container">
                    <h3>Recent Employees</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Section</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Hire Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentEmployees)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">
                                        <i class="fas fa-users fa-2x" style="color: var(--text-muted); margin-bottom: 1rem;"></i>
                                        <p style="color: var(--text-muted);">No employees found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentEmployees as $employee): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($employee['employee_id']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($employee['section_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge <?php echo getEmployeeTypeBadge($employee['employee_type'] ?? ''); ?>">
                                            <?php 
                                            $type = $employee['employee_type'] ?? '';
                                            echo $type ? ucwords(str_replace('_', ' ', $type)) : 'N/A'; 
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getEmployeeStatusBadge($employee['employee_status'] ?? ''); ?>">
                                            <?php 
                                            $status = $employee['employee_status'] ?? '';
                                            echo $status ? ucwords($status) : 'N/A'; 
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($employee['hire_date']); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 0.25rem;">
                                            <a href="employees.php?action=view&id=<?php echo $employee['id']; ?>" 
                                               class="btn btn-sm" 
                                               style="background: linear-gradient(45deg, var(--secondary-color), #5a32a3); color: white; padding: 0.25rem 0.5rem;"
                                               title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (hasPermission('hr_manager')): ?>
                                            <a href="employees.php?action=edit&id=<?php echo $employee['id']; ?>" 
                                               class="btn btn-sm"
                                               style="background: linear-gradient(45deg, var(--warning-color), #e09900); color: white; padding: 0.25rem 0.5rem;"
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="action-buttons">
                    <a href="employees.php" class="btn btn-primary">
                        <i class="fas fa-users"></i> View All Employees
                    </a>
                    <?php if (hasPermission('hr_manager')): ?>
                        <a href="employees.php?action=add" class="btn btn-success">
                            <i class="fas fa-user-plus"></i> Add New Employee
                        </a>
                        <a href="reports.php" class="btn btn-secondary">
                            <i class="fas fa-chart-bar"></i> Generate Report
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>