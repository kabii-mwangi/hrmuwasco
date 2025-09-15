<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Handle theme toggle
if (isset($_POST['toggle_theme'])) {
    $currentTheme = $_SESSION['theme'] ?? 'light';
    $_SESSION['theme'] = ($currentTheme === 'light') ? 'dark' : 'light';
    
    // Redirect back to the current page to refresh with new theme
    $redirectUrl = $_SERVER['REQUEST_URI'];
    header("Location: $redirectUrl");
    exit();
}

// Get current theme (default to light)
$currentTheme = $_SESSION['theme'] ?? 'light';

// Get user info from session
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id'] ?? null
];
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
</head>

<div class="main-header">
    <div class="header-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="page-title"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
    </div>
    
    <div class="header-right">
        <!-- Theme Toggle -->
        <div class="theme-toggle">
            <form method="POST" style="margin: 0;">
                <button type="submit" name="toggle_theme" class="theme-switch">
                    <div class="theme-slider">
                        <span class="theme-icon">
                            <?php if ($currentTheme === 'light'): ?>
                                🌞
                            <?php else: ?>
                                🌙
                            <?php endif; ?>
                        </span>
                    </div>
                </button>
            </form>
        </div>
        
        <!-- User Info -->
        <div class="user-info">
            <span class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
            <span class="role-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))); ?></span>
        </div>
        
        <!-- Logout Button -->
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
    }
}
</script>