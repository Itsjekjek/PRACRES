<?php
// DEVELOPMENT ONLY: show errors while debugging.
// Remove or disable these lines on a production server.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => !empty($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict'
]);

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'code_typing_game';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please fill in both fields";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        if (!$stmt) {
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $userRow = $result->fetch_assoc();

            if ($userRow && password_verify($password, $userRow['password'])) {
                // Success → store session + redirect to game page
                $_SESSION['user_id']   = $userRow['id'];
                $_SESSION['username']  = $userRow['username'];

                header("Location: index.php");
                exit;
            } else {
                $error = "Incorrect username or password";
            }
            $stmt->close();
        }
    }
}

// ─────────────────────────────────────────────
// If we reach here → either GET request or login failed
// Show the login form
// ─────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TypePro - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center">

    <div class="w-full max-w-md bg-gray-800 p-10 rounded-xl border border-gray-700 shadow-2xl">
        <h1 class="text-4xl font-bold text-center mb-8 text-white">TypePro</h1>

        <?php if ($error): ?>
        <div class="bg-red-900/60 border border-red-700 text-red-100 px-4 py-3 rounded mb-6 text-center">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-gray-300 mb-2">Username</label>
                <input type="text" name="username" required autofocus
                       value="<?= htmlspecialchars($username ?? '') ?>"
                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-gray-300 mb-2">Password</label>
                <input type="password" name="password" required
                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white focus:outline-none focus:border-blue-500">
            </div>
            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded transition duration-200">
                LOG IN
            </button>
        </form>

        <p class="text-center mt-8 text-gray-400">
            Don't have an account yet?
            <a href="signup.php" class="text-blue-400 hover:underline">Sign up</a>
        </p>
    </div>

</body>
</html>

<?php $conn->close(); ?>