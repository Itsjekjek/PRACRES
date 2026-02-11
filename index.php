<?php
// DEVELOPMENT ONLY: enable error display while debugging.
// Remove or disable these lines on a production server.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => !empty($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict'
]);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ────────────────────────────────────────────────
// DATABASE CONFIG & CONNECTION
// ────────────────────────────────────────────────
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'code_typing_game';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ────────────────────────────────────────────────
// HANDLE AJAX RESULT SAVING
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $snippet_id   = (int)($data['snippet_id']   ?? 0);
    $wpm          = (int)($data['wpm']          ?? 0);
    $cpm          = (int)($data['cpm']          ?? 0);
    $accuracy     = (float)($data['accuracy']   ?? 0);
    $time_taken   = (int)($data['time_taken']   ?? 0);
    $lang         = $conn->real_escape_string($data['selected_lang'] ?? 'mixed');
    $mode         = $conn->real_escape_string($data['mode'] ?? 'normal');

    $stmt = $conn->prepare("
        INSERT INTO results 
        (user_id, snippet_id, wpm, cpm, language, mode, accuracy, time_taken, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit;
    }

    $bind = $stmt->bind_param(
        "iiiissdi",
        $_SESSION['user_id'],
        $snippet_id,
        $wpm,
        $cpm,
        $lang,
        $mode,
        $accuracy,
        $time_taken
    );

    if ($bind === false) {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
        $stmt->close();
        exit;
    }

    $success = $stmt->execute();

    echo json_encode([
        'success' => (bool)$success,
        'error'   => $success ? null : $stmt->error
    ]);

    $stmt->close();
    exit;
}

// ────────────────────────────────────────────────
// GET USER STATS ENDPOINT (?stats=1)
// ────────────────────────────────────────────────
if (isset($_GET['stats']) && $_GET['stats'] == '1') {
    header('Content-Type: application/json');

    $filter_mode = $_GET['mode'] ?? null;
    if (!in_array($filter_mode, ['normal','pro','expert'])) {
        $filter_mode = null;
    }

    $sql_stats = "
        SELECT 
            COUNT(*) as total_tests,
            AVG(wpm) as avg_wpm,
            AVG(accuracy) as avg_accuracy,
            AVG(time_taken) as avg_time,
            MAX(wpm) as best_wpm,
            MAX(accuracy) as best_accuracy,
            MIN(time_taken) as best_time
        FROM results
        WHERE user_id = ?
    ";
    $params = [$_SESSION['user_id']];
    $types = 'i';

    if ($filter_mode) {
        $sql_stats .= " AND mode = ?";
        $params[] = $filter_mode;
        $types .= 's';
    }

    $stmt = $conn->prepare($sql_stats);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stats_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sql_history = "
        SELECT 
            wpm, accuracy, time_taken, language, mode, created_at
        FROM results
        WHERE user_id = ?
    ";
    $h_params = [$_SESSION['user_id']];
    $h_types = 'i';

    if ($filter_mode) {
        $sql_history .= " AND mode = ?";
        $h_params[] = $filter_mode;
        $h_types .= 's';
    }

    $sql_history .= " ORDER BY created_at DESC LIMIT 10";

    $stmt = $conn->prepare($sql_history);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit;
    }
    $stmt->bind_param($h_types, ...$h_params);
    $stmt->execute();
    $history_result = $stmt->get_result();
    $history = [];
    while ($row = $history_result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();

    $improvement = ['wpm' => 0, 'accuracy' => 0];
    if (count($history) >= 2) {
        $latest = $history[0];
        $previous = $history[1];
        $improvement['wpm'] = $latest['wpm'] - $previous['wpm'];
        $improvement['accuracy'] = $latest['accuracy'] - $previous['accuracy'];
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats_result,
        'history' => $history,
        'improvement' => $improvement
    ]);
    exit;
}

// ────────────────────────────────────────────────
// GET LEADERBOARD ENDPOINT (?leaderboard=1) - FIXED
// ────────────────────────────────────────────────
if (isset($_GET['leaderboard']) && $_GET['leaderboard'] == '1') {
    header('Content-Type: application/json');

    $filter_mode = $_GET['mode'] ?? null;
    if (!in_array($filter_mode, ['normal','pro','expert'])) {
        $filter_mode = null;
    }

    $sql = "
        SELECT
            u.username,
            r.wpm,
            r.accuracy,
            r.time_taken,
            r.language,
            r.mode,
            r.created_at
        FROM results r
        LEFT JOIN users u ON r.user_id = u.id
    ";
    $params = [];
    $types = '';

    if ($filter_mode) {
        $sql .= " WHERE r.mode = ?";
        $params[] = $filter_mode;
        $types = 's';
    }

    $sql .= " ORDER BY r.wpm DESC, r.accuracy DESC, r.time_taken ASC LIMIT 50";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit;
    }

    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
    exit;
}

// ────────────────────────────────────────────────
// GET & VALIDATE QUERY PARAMETERS
// ────────────────────────────────────────────────
$lang   = $_GET['lang']   ?? 'mixed';
$timer  = $_GET['timer']  ?? '60';
$mode   = $_GET['mode']   ?? 'normal';

$validTimers    = ['15', '30', '60', '120', '300', '600', 'full'];
$validLanguages = ['mixed', 'js', 'html', 'css', 'php', 'python', 'java', 'cpp'];

if (!in_array($timer, $validTimers))    $timer = '60';
if (!in_array($lang, $validLanguages))  $lang  = 'mixed';
if (!in_array($mode, ['normal', 'pro', 'expert'])) $mode = 'normal';

$isFullMode       = $timer === 'full';
$timeLimitSeconds = match ($timer) {
    '15'   => 15,
    '30'   => 30,
    '60'   => 60,
    '120'  => 120,
    '300'  => 300,
    '600'  => 600,
    default => 0
};

// ────────────────────────────────────────────────
// FETCH RANDOM SNIPPET
// ────────────────────────────────────────────────
$sql = "SELECT id, code_text, language FROM snippets WHERE difficulty = ?";
$params = [$mode];
$types = 's';

if ($lang !== 'mixed') {
    $sql .= " AND language = ?";
    $params[] = $lang;
    $types .= 's';
} else {
    $sql .= " AND language IN ('js', 'css', 'html')";
}

$sql .= " ORDER BY RAND() LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $snippet = getDefaultSnippet($lang, $mode);
} else {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $snippet = $result->num_rows ? $result->fetch_assoc() : getDefaultSnippet($lang, $mode);
    $stmt->close();
}

function getDefaultSnippet($lang, $difficulty) {
    $snippets = [
        'html' => [
            'normal' => [
                ['id'=>0,'language'=>'html','code_text'=>"<!DOCTYPE html>\n<html>\n<head>\n<title>Page</title>\n</head>\n<body>\n<h1>Welcome</h1>\n<p>Hello World</p>\n</body>\n</html>"],
                ['id'=>0,'language'=>'html','code_text'=>"<html>\n<head>\n<title>Document</title>\n</head>\n<body>\n<h2>Section</h2>\n<p>Content here</p>\n</body>\n</html>"],
                ['id'=>0,'language'=>'html','code_text'=>"<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<title>My Page</title>\n</head>\n<body>\n<header>\n<h1>Header</h1>\n</header>\n<main>\n<p>Main content</p>\n</main>\n</body>\n</html>"],
                ['id'=>0,'language'=>'html','code_text'=>"<div class=\"container\">\n<h1>Title</h1>\n<ul>\n<li>Item One</li>\n<li>Item Two</li>\n<li>Item Three</li>\n</ul>\n<p>Description text</p>\n</div>"],
                ['id'=>0,'language'=>'html','code_text'=>"<nav>\n<a href=\"/home\">Home</a>\n<a href=\"/about\">About</a>\n<a href=\"/contact\">Contact</a>\n</nav>\n<section>\n<h2>Main Section</h2>\n<p>Some content here</p>\n</section>"],
            ],
            'pro' => [
                ['id'=>0,'language'=>'html','code_text'=>"<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>Professional Page</title>\n<link rel=\"stylesheet\" href=\"styles.css\">\n</head>\n<body>\n<header>\n<nav class=\"navbar\">\n<ul>\n<li><a href=\"#home\">Home</a></li>\n<li><a href=\"#about\">About</a></li>\n<li><a href=\"#services\">Services</a></li>\n</ul>\n</nav>\n</header>\n<main>\n<section id=\"hero\">\n<h1>Welcome to Our Site</h1>\n<p>This is a professional website</p>\n</section>\n</main>\n</body>\n</html>"],
                ['id'=>0,'language'=>'html','code_text'=>"<form action=\"/submit\" method=\"POST\">\n<div class=\"form-group\">\n<label for=\"name\">Name:</label>\n<input type=\"text\" id=\"name\" name=\"name\" required>\n</div>\n<div class=\"form-group\">\n<label for=\"email\">Email:</label>\n<input type=\"email\" id=\"email\" name=\"email\" required>\n</div>\n<div class=\"form-group\">\n<label for=\"message\">Message:</label>\n<textarea id=\"message\" name=\"message\" rows=\"5\"></textarea>\n</div>\n<button type=\"submit\">Send</button>\n<button type=\"reset\">Clear</button>\n</form>"],
                ['id'=>0,'language'=>'html','code_text'=>"<div class=\"card\">\n<img src=\"product.jpg\" alt=\"Product Image\" class=\"card-img\">\n<div class=\"card-body\">\n<h3 class=\"card-title\">Product Name</h3>\n<p class=\"card-text\">Product description goes here</p>\n<p class=\"card-price\">$99.99</p>\n<button class=\"btn-primary\">Add to Cart</button>\n</div>\n</div>"],
                ['id'=>0,'language'=>'html','code_text'=>"<table class=\"data-table\">\n<thead>\n<tr>\n<th>ID</th>\n<th>Name</th>\n<th>Email</th>\n<th>Status</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>1</td>\n<td>John Doe</td>\n<td>john@example.com</td>\n<td>Active</td>\n</tr>\n<tr>\n<td>2</td>\n<td>Jane Smith</td>\n<td>jane@example.com</td>\n<td>Inactive</td>\n</tr>\n</tbody>\n</table>"],
                ['id'=>0,'language'=>'html','code_text'=>"<article class=\"blog-post\">\n<header>\n<h2>Blog Post Title</h2>\n<p class=\"meta\">Posted on <time>2024-01-15</time> by Author</p>\n</header>\n<section class=\"content\">\n<p>This is the main content of the blog post.</p>\n<p>It can contain multiple paragraphs and other elements.</p>\n</section>\n<footer>\n<a href=\"#comments\">Comments (5)</a>\n</footer>\n</article>"],
            ],
            'expert' => [
                ['id'=>0,'language'=>'html','code_text'=>"<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<meta name=\"description\" content=\"Expert level HTML example\">\n<title>Expert HTML Page</title>\n<link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n<div id=\"app\">\n<header role=\"banner\" class=\"site-header\">\n<nav aria-label=\"Main Navigation\" class=\"navbar\">\n<ul class=\"nav-list\">\n<li><a href=\"#\">Home</a></li>\n<li><a href=\"#\">Services</a></li>\n<li><a href=\"#\">Contact</a></li>\n</ul>\n</nav>\n</header>\n<main role=\"main\" class=\"container\">\n<section aria-labelledby=\"intro-heading\">\n<h1 id=\"intro-heading\">Welcome</h1>\n<p>Expert content here</p>\n</section>\n</main>\n<footer role=\"contentinfo\" class=\"site-footer\">\n<p>&copy; 2024 Company Name</p>\n</footer>\n</div>\n</body>\n</html>"],
                ['id'=>0,'language'=>'html','code_text'=>"<form id=\"advancedForm\" method=\"POST\" action=\"/api/submit\" novalidate>\n<fieldset>\n<legend>User Registration</legend>\n<div class=\"form-control\">\n<label for=\"username\">Username:</label>\n<input type=\"text\" id=\"username\" name=\"username\" minlength=\"3\" maxlength=\"20\" required aria-required=\"true\">\n<span class=\"error\" id=\"username-error\"></span>\n</div>\n<div class=\"form-control\">\n<label for=\"password\">Password:</label>\n<input type=\"password\" id=\"password\" name=\"password\" minlength=\"8\" required>\n<span class=\"error\" id=\"password-error\"></span>\n</div>\n<div class=\"form-control\">\n<label for=\"email\">Email:</label>\n<input type=\"email\" id=\"email\" name=\"email\" required>\n<span class=\"error\" id=\"email-error\"></span>\n</div>\n<button type=\"submit\">Register</button>\n<button type=\"reset\">Clear</button>\n</fieldset>\n</form>"],
                ['id'=>0,'language'=>'html','code_text'=>"<section class=\"gallery\">\n<h2>Image Gallery</h2>\n<div class=\"gallery-grid\">\n<figure>\n<img src=\"image1.jpg\" alt=\"First image description\" loading=\"lazy\">\n<figcaption>Image 1 Caption</figcaption>\n</figure>\n<figure>\n<img src=\"image2.jpg\" alt=\"Second image description\" loading=\"lazy\">\n<figcaption>Image 2 Caption</figcaption>\n</figure>\n<figure>\n<img src=\"image3.jpg\" alt=\"Third image description\" loading=\"lazy\">\n<figcaption>Image 3 Caption</figcaption>\n</figure>\n</div>\n</section>"],
                ['id'=>0,'language'=>'html','code_text'=>"<video id=\"tutorial\" width=\"640\" height=\"360\" controls>\n<source src=\"video.mp4\" type=\"video/mp4\">\n<source src=\"video.webm\" type=\"video/webm\">\n<track src=\"captions.vtt\" kind=\"captions\" srclang=\"en\" label=\"English\">\nYour browser does not support HTML5 video.\n</video>"],
                ['id'=>0,'language'=>'html','code_text'=>"<details class=\"accordion-item\" open>\n<summary>What is this?</summary>\n<div class=\"details-content\">\n<p>This is expandable content that can be shown or hidden.</p>\n<p>The details element provides native accordion functionality.</p>\n</div>\n</details>\n<details class=\"accordion-item\">\n<summary>How does it work?</summary>\n<div class=\"details-content\">\n<p>Users can click the summary to toggle visibility.</p>\n<p>This is a semantic HTML5 element.</p>\n</div>\n</details>"],
            ],
        ],
        'css' => [
            'normal' => [
                ['id'=>0,'language'=>'css','code_text'=>"body {\n margin: 0;\n padding: 0;\n font-family: Arial, sans-serif;\n}\n\nh1 {\n color: #333;\n font-size: 2em;\n}\n\np {\n line-height: 1.6;\n color: #666;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>"a {\n color: #0066cc;\n text-decoration: none;\n}\n\na:hover {\n text-decoration: underline;\n color: #0052a3;\n}\n\nimg {\n max-width: 100%;\n height: auto;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".container {\n max-width: 960px;\n margin: 0 auto;\n padding: 20px;\n}\n\n.header {\n background-color: #f0f0f0;\n padding: 20px 0;\n border-bottom: 1px solid #ddd;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".button {\n display: inline-block;\n padding: 10px 20px;\n background-color: #007bff;\n color: white;\n border: none;\n border-radius: 4px;\n cursor: pointer;\n font-size: 16px;\n}\n\n.button:hover {\n background-color: #0056b3;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".card {\n border: 1px solid #ddd;\n border-radius: 8px;\n padding: 16px;\n box-shadow: 0 2px 4px rgba(0,0,0,0.1);\n}\n\n.card h3 {\n margin-top: 0;\n}\n\n.card p {\n margin-bottom: 0;\n}"],
            ],
            'pro' => [
                ['id'=>0,'language'=>'css','code_text'=>".navbar {\n display: flex;\n justify-content: space-between;\n align-items: center;\n background-color: #333;\n padding: 1rem;\n}\n\n.navbar a {\n color: white;\n text-decoration: none;\n padding: 0.5rem 1rem;\n}\n\n.navbar a:hover {\n background-color: #555;\n border-radius: 4px;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".grid {\n display: grid;\n grid-template-columns: repeat(3, 1fr);\n gap: 20px;\n padding: 20px;\n}\n\n.grid-item {\n background-color: #f5f5f5;\n padding: 20px;\n border-radius: 8px;\n}\n\n.grid-item h3 {\n margin-top: 0;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".form-group {\n margin-bottom: 16px;\n}\n\n.form-group label {\n display: block;\n margin-bottom: 4px;\n font-weight: bold;\n}\n\n.form-group input,\n.form-group textarea {\n width: 100%;\n padding: 8px;\n border: 1px solid #ddd;\n border-radius: 4px;\n font-size: 14px;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".flexbox-container {\n display: flex;\n flex-direction: row;\n justify-content: space-between;\n align-items: center;\n gap: 20px;\n}\n\n.flexbox-item {\n flex: 1;\n padding: 16px;\n background-color: #f9f9f9;\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".table {\n width: 100%;\n border-collapse: collapse;\n margin: 20px 0;\n}\n\n.table th,\n.table td {\n padding: 12px;\n text-align: left;\n border-bottom: 1px solid #ddd;\n}\n\n.table th {\n background-color: #f5f5f5;\n font-weight: bold;\n}"],
            ],
            'expert' => [
                ['id'=>0,'language'=>'css','code_text'=>"@media (max-width: 768px) {\n .grid {\n grid-template-columns: repeat(2, 1fr);\n }\n .navbar {\n flex-direction: column;\n }\n}\n\n@media (max-width: 480px) {\n .grid {\n grid-template-columns: 1fr;\n }\n body {\n font-size: 14px;\n }\n}"],
                ['id'=>0,'language'=>'css','code_text'=>":root {\n --primary-color: #007bff;\n --secondary-color: #6c757d;\n --success-color: #28a745;\n --danger-color: #dc3545;\n --spacing-unit: 8px;\n}\n\nbody {\n color: var(--secondary-color);\n}\n\n.btn-primary {\n background-color: var(--primary-color);\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".animation {\n animation: slideIn 0.5s ease-in-out;\n}\n\n@keyframes slideIn {\n from {\n opacity: 0;\n transform: translateX(-20px);\n }\n to {\n opacity: 1;\n transform: translateX(0);\n }\n}\n\n.box {\n transition: transform 0.3s, box-shadow 0.3s;\n}\n\n.box:hover {\n transform: translateY(-5px);\n box-shadow: 0 4px 8px rgba(0,0,0,0.2);\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".gradient {\n background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);\n color: white;\n padding: 40px;\n}\n\n.container {\n max-width: 1200px;\n margin: 0 auto;\n padding: 20px;\n}\n\n.shadow {\n box-shadow: 0 4px 6px rgba(0,0,0,0.1), 0 10px 20px rgba(0,0,0,0.15);\n}"],
                ['id'=>0,'language'=>'css','code_text'=>".sticky-header {\n position: sticky;\n top: 0;\n background-color: white;\n z-index: 100;\n box-shadow: 0 2px 4px rgba(0,0,0,0.1);\n}\n\n.container-fluid {\n width: 100%;\n padding: 0;\n}\n\n.aspect-ratio-16-9 {\n position: relative;\n padding-bottom: 56.25%;\n height: 0;\n overflow: hidden;\n}"],
            ],
        ],
        'js' => [
            'normal' => [
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
console.log('Hello World');
const message = 'Welcome to JavaScript';
console.log(message);
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const x = 10;
const y = 20;
const sum = x + y;
console.log('Sum:', sum);
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const numbers = [1, 2, 3, 4, 5];
const doubled = numbers.map(n => n * 2);
console.log('Doubled:', doubled);
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
for (let i = 0; i < 5; i++) {
  console.log('Number:', i);
}
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
function greet(name) {
  return `Hello, ${name}!`;
}
console.log(greet('Alice'));
JS
                ],
            ],
            'pro' => [
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const user = {
  name: 'John',
  age: 30,
  email: 'john@example.com',
  greet() {
    return `Hello, my name is ${this.name}`;
  }
};
console.log(user.greet());
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const data = [1, 2, 3, 4, 5];
const filtered = data.filter(n => n > 2);
const result = filtered.map(n => n * 2);
console.log('Result:', result);
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
function fetchData(url) {
  return fetch(url)
    .then(response => response.json())
    .then(data => {
      console.log('Data:', data);
      return data;
    })
    .catch(error => console.error('Error:', error));
}
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const arr = [1, 2, 3, 4, 5];
const sum = arr.reduce((acc, val) => acc + val, 0);
const avg = sum / arr.length;
console.log('Sum:', sum, 'Average:', avg);
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const person = { name: 'Alice', age: 25 };
const { name, age } = person;
console.log(`${name} is ${age} years old`);

const [a, b, ...rest] = [1, 2, 3, 4, 5];
console.log('a:', a, 'b:', b, 'rest:', rest);
JS
                ],
            ],
            'expert' => [
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
class Animal {
  constructor(name) {
    this.name = name;
  }
  speak() {
    return `${this.name} makes a sound`;
  }
}

class Dog extends Animal {
  speak() {
    return `${this.name} barks`;
  }
}

const dog = new Dog('Buddy');
console.log(dog.speak());
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
async function getUser(id) {
  try {
    const response = await fetch(`/api/users/${id}`);
    if (!response.ok) throw new Error('User not found');
    const user = await response.json();
    console.log('User:', user);
    return user;
  } catch (error) {
    console.error('Error:', error.message);
  }
}
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
      observer.unobserve(entry.target);
    }
  });
});

document.querySelectorAll('.lazy-load').forEach(el => {
  observer.observe(el);
});
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const createCounter = () => {
  let count = 0;
  return {
    increment() { return ++count; },
    decrement() { return --count; },
    getCount() { return count; }
  };
};

const counter = createCounter();
console.log(counter.increment());
console.log(counter.increment());
console.log(counter.getCount());
JS
                ],
                ['id'=>0,'language'=>'js','code_text'=> <<<'JS'
const debounce = (fn, delay) => {
  let timeoutId;
  return (...args) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn(...args), delay);
  };
};

const handleSearch = debounce((query) => {
  console.log('Searching for:', query);
}, 300);

window.addEventListener('input', (e) => handleSearch(e.target.value));
JS
                ],
            ],
        ],
    ];

    if ($lang === 'mixed') {
        $langs = array_keys($snippets);
        $lang = $langs[array_rand($langs)];
    }

    $pool = $snippets[$lang][$difficulty] ?? $snippets['js']['normal'];
    shuffle($pool);
    return $pool[0];
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TypePro</title>

<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen <?= $mode === 'pro' ? 'pro-mode' : ($mode === 'expert' ? 'expert-mode' : '') ?>">

<!-- HEADER -->
<header class="bg-gray-800 py-4 shadow-lg">
<div class="container mx-auto px-4 flex justify-between items-center">
<h1 class="text-2xl font-bold">CodeType Master</h1>
<div class="flex items-center space-x-6">
<span class="text-sm text-gray-300 font-mono">
Hi, <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>
</span>
<div id="live-timer" class="bg-gray-700 px-4 py-2 rounded-lg font-mono text-lg">00:00</div>

<button id="stats-btn"
class="flex items-center space-x-2 bg-gray-700 px-4 py-2 rounded-lg hover:bg-gray-600 transition">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
</svg>
<span>Stats</span>
</button>

<button id="leaderboard-btn" type="button"
class="flex items-center space-x-2 bg-gray-700 px-4 py-2 rounded-lg hover:bg-gray-600 transition">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
</svg>
<span>Leaderboard</span>
</button>

<a href="logout.php" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded-lg font-semibold text-white">
Log Out
</a>
</div>
</div>
</header>

<!-- MAIN CONTENT -->
<main class="container mx-auto px-4 py-8">
<div class="flex flex-col lg:flex-row gap-8">
<!-- CONTROLS -->
<div class="lg:w-1/4 bg-gray-800 p-6 rounded-xl shadow-lg">
<h2 class="text-lg font-semibold mb-4">Language</h2>
<select id="lang-select" class="w-full bg-gray-700 text-white p-3 rounded mb-6 focus:outline-none focus:ring-2 focus:ring-blue-500">
<option value="mixed">Mixed (HTML/CSS/JS)</option>
<option value="html">HTML</option>
<option value="css">CSS</option>
<option value="js">JavaScript</option>
</select>

<h2 class="text-lg font-semibold mb-4">Timer</h2>
<select id="timer-select" class="w-full bg-gray-700 text-white p-3 rounded mb-6 focus:outline-none focus:ring-2 focus:ring-blue-500">
<option value="15">15 seconds</option>
<option value="30">30 seconds</option>
<option value="60">1 minute</option>
<option value="120">2 minutes</option>
<option value="300">5 minutes</option>
<option value="600">10 minutes</option>
<option value="full">Full text</option>
</select>

<h2 class="text-lg font-semibold mb-4">Mode</h2>
<div class="grid grid-cols-3 gap-3">
<button class="mode-btn py-2 rounded bg-gray-700 hover:bg-gray-600 <?= $mode==='normal' ? 'bg-blue-600' : '' ?>" data-mode="normal">Normal</button>
<button class="mode-btn py-2 rounded bg-gray-700 hover:bg-gray-600 <?= $mode==='pro' ? 'bg-blue-600' : '' ?>" data-mode="pro">Pro</button>
<button class="mode-btn py-2 rounded bg-gray-700 hover:bg-gray-600 <?= $mode==='expert' ? 'bg-blue-600' : '' ?>" data-mode="expert">Expert</button>
</div>
</div>

<!-- TYPING AREA -->
<div class="lg:w-3/4 bg-gray-800 p-6 rounded-xl shadow-lg relative">
<div id="code-area" class="font-mono text-lg leading-relaxed whitespace-pre-wrap overflow-auto max-h-96">
<div id="text-display" class="select-none"></div>
<div id="caret" class="absolute w-0.5 h-5 bg-blue-400 animate-blink translate-y-6 translate-x-[6mm]"></div>
</div>

<!-- Result Overlay -->
<div id="result-overlay" class="hidden absolute inset-0 bg-gray-900/90 flex items-center justify-center backdrop-blur-sm">
<div class="text-center">
<h2 class="text-3xl font-bold mb-8">Results</h2>
<div class="grid grid-cols-3 gap-12 text-center">
<div>
<div class="text-5xl font-bold text-blue-400" id="final-wpm">—</div>
<div class="text-lg mt-2">WPM</div>
</div>
<div>
<div class="text-5xl font-bold text-green-400" id="final-acc">—</div>
<div class="text-lg mt-2">Accuracy</div>
</div>
<div>
<div class="text-5xl font-bold text-purple-400" id="final-time">—</div>
<div class="text-lg mt-2">Seconds</div>
</div>
</div>

<div class="grid grid-cols-2 gap-6 mt-8 mb-8">
<div id="improvement-section" class="hidden">
<div class="text-sm text-gray-400">Improvement from Last Test</div>
<div class="text-2xl font-bold mt-2">
<span id="improvement-wpm" class="text-blue-400"></span>
<span class="text-lg text-gray-500 ml-2">WPM</span>
</div>
<div class="text-2xl font-bold mt-2">
<span id="improvement-acc" class="text-green-400"></span>
<span class="text-lg text-gray-500 ml-2">Acc</span>
</div>
</div>

<div id="average-section" class="hidden">
<div class="text-sm text-gray-400">Your Averages</div>
<div class="text-2xl font-bold mt-2">
<span id="average-wpm" class="text-blue-400"></span>
<span class="text-lg text-gray-500 ml-2">WPM</span>
</div>
<div class="text-2xl font-bold mt-2">
<span id="average-acc" class="text-green-400"></span>
<span class="text-lg text-gray-500 ml-2">Acc</span>
</div>
</div>
</div>

<div class="mt-10 flex justify-center gap-4 flex-wrap">
<button id="try-again-btn" 
        onclick="location.href = location.pathname + (location.search ? location.search + '&' : '?') + 'rand=' + Date.now();"
        class="bg-purple-600 hover:bg-purple-700 px-6 py-3 rounded-lg text-lg font-bold">
    Next
</button>

<button id="run-code-btn" class="bg-green-600 hover:bg-green-700 px-6 py-3 rounded-lg text-lg font-bold">
Run Code
</button>


</div>
</div>
</div>

<!-- Progress + Live Stats -->
<div class="h-2 bg-gray-700 rounded-full overflow-hidden mb-4">
<div id="progress" class="h-full bg-blue-600 w-0 transition-all duration-300"></div>
</div>

<div class="flex justify-between text-lg font-mono">
<div>WPM: <span id="live-wpm" class="text-2xl font-bold">0</span></div>
<div>Accuracy: <span id="live-acc" class="text-2xl font-bold">100%</span></div>
<div>Time: <span id="live-timer-display" class="text-2xl font-bold">—</span></div>
</div>
</div>
</div>
</main>

<textarea id="hidden-input" class="sr-only" autocapitalize="off" autocomplete="off" spellcheck="false"></textarea>

<!-- RUNNER MODAL -->
<div id="run-modal" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center z-60">
<div class="bg-gray-900 rounded-xl p-6 w-full max-w-4xl max-h-[85vh] overflow-hidden border border-gray-700 shadow-2xl relative">
<button id="close-runner" class="absolute top-4 right-6 text-gray-400 hover:text-white text-3xl font-bold">×</button>

<h3 class="text-2xl font-bold mb-4">Run Output</h3>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 h-[70vh]">
<div class="bg-white text-black rounded p-2 overflow-auto">
<iframe id="runner-iframe" class="w-full h-full bg-white border" sandbox="allow-scripts"></iframe>
</div>
<div class="bg-black text-green-300 rounded p-3 overflow-auto">
<div class="flex justify-between items-center mb-2">
<div class="font-mono text-sm text-gray-400">Console</div>
<button id="clear-console" class="text-xs bg-gray-800 px-2 py-1 rounded">Clear</button>
</div>
<pre id="runner-console" class="whitespace-pre-wrap text-sm"></pre>
</div>
</div>
</div>
</div>

<!-- STATS MODAL -->
<div id="stats-modal" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center z-50">
  <div class="bg-gray-900 rounded-xl p-8 w-full max-w-4xl max-h-[85vh] overflow-auto border border-gray-700 shadow-2xl">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-bold">Your Statistics</h2>
      <button id="close-stats" class="text-gray-400 hover:text-white text-3xl font-bold">×</button>
    </div>

    <div id="stats-loading" class="text-center py-8">Loading statistics...</div>

    <div id="stats-empty" class="hidden text-center py-8 text-gray-400">
      No statistics yet. Complete your first test!
    </div>

    <div id="stats-content" class="hidden">
      <div class="grid grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-800 p-4 rounded-lg text-center">
          <div class="text-3xl font-bold text-blue-400" id="total-tests">0</div>
          <div class="text-sm text-gray-400">Total Tests</div>
        </div>
        <div class="bg-gray-800 p-4 rounded-lg text-center">
          <div class="text-3xl font-bold text-green-400" id="stats-avg-wpm">0</div>
          <div class="text-sm text-gray-400">Avg WPM</div>
        </div>
        <div class="bg-gray-800 p-4 rounded-lg text-center">
          <div class="text-3xl font-bold text-purple-400" id="stats-avg-acc">0%</div>
          <div class="text-sm text-gray-400">Avg Accuracy</div>
        </div>
        <div class="bg-gray-800 p-4 rounded-lg text-center">
          <div class="text-3xl font-bold text-orange-400" id="stats-best-wpm">0</div>
          <div class="text-sm text-gray-400">Best WPM</div>
        </div>
      </div>

      <div class="mb-4">
        <h3 class="text-xl font-semibold mb-3">Filter by Mode</h3>
        <div class="flex gap-2">
          <button class="stats-filter-btn bg-blue-600 px-4 py-2 rounded" data-filter="all">All</button>
          <button class="stats-filter-btn bg-gray-700 px-4 py-2 rounded hover:bg-gray-600" data-filter="normal">Normal</button>
          <button class="stats-filter-btn bg-gray-700 px-4 py-2 rounded hover:bg-gray-600" data-filter="pro">Pro</button>
          <button class="stats-filter-btn bg-gray-700 px-4 py-2 rounded hover:bg-gray-600" data-filter="expert">Expert</button>
        </div>
      </div>

      <table class="w-full text-left">
        <thead class="border-b border-gray-700 text-gray-400">
          <tr>
            <th class="p-3">#</th>
            <th class="p-3">WPM</th>
            <th class="p-3">Accuracy</th>
            <th class="p-3">Time</th>
            <th class="p-3">Language</th>
            <th class="p-3">Mode</th>
            <th class="p-3 text-right">Date</th>
          </tr>
        </thead>
        <tbody id="stats-history-body"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- LEADERBOARD MODAL - FIXED -->
<div id="leaderboard-modal" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center z-50">
  <div class="bg-gray-900 rounded-xl p-8 w-full max-w-4xl max-h-[85vh] overflow-auto border border-gray-700 shadow-2xl">
    <div class="flex justify-between items-center mb-6">
      <h2 class="text-2xl font-bold">Leaderboard</h2>
      <button id="close-leaderboard" class="text-gray-400 hover:text-white text-3xl font-bold">×</button>
    </div>

    <div id="leaderboard-loading" class="text-center py-8">Loading leaderboard...</div>

    <div id="leaderboard-empty" class="hidden text-center py-8 text-gray-400">
      No results yet. Be the first to complete a test!
    </div>

    <div id="leaderboard-content" class="hidden">
      <div class="mb-4">
        <h3 class="text-xl font-semibold mb-3">Filter by Mode</h3>
        <div class="flex gap-2">
          <button class="leaderboard-filter-btn bg-blue-600 px-4 py-2 rounded" data-filter="all">All</button>
          <button class="leaderboard-filter-btn bg-gray-700 px-4 py-2 rounded hover:bg-gray-600" data-filter="normal">Normal</button>
          <button class="leaderboard-filter-btn bg-gray-700 px-4 py-2 rounded hover:bg-gray-600" data-filter="pro">Pro</button>
          <button class="leaderboard-filter-btn bg-gray-700 px-4 py-2 rounded hover:bg-gray-600" data-filter="expert">Expert</button>
        </div>
      </div>

      <table class="w-full text-left">
        <thead class="border-b border-gray-700 text-gray-400">
          <tr>
            <th class="p-4">#</th>
            <th class="p-4">Username</th>
            <th class="p-4">WPM</th>
            <th class="p-4">Accuracy</th>
            <th class="p-4">Time</th>
            <th class="p-4">Language</th>
            <th class="p-4">Mode</th>
            <th class="p-4 text-right">Date</th>
          </tr>
        </thead>
        <tbody id="leaderboard-body"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
// ────────────────────────────────────────────────
// PHP → JavaScript variables
// ────────────────────────────────────────────────
const SNIPPET_ID = <?= $snippet['id'] ?>;
const SNIPPET_TEXT = <?= json_encode($snippet['code_text']) ?>;
const SNIPPET_LANG = <?= json_encode($snippet['language']) ?>;
let timeLimit = <?= $timeLimitSeconds ?>;
let currentMode = <?= json_encode($mode) ?>;

// Elements
const textDisplay = document.getElementById('text-display');
const hiddenInput = document.getElementById('hidden-input');
const caret = document.getElementById('caret');
const liveWpm = document.getElementById('live-wpm');
const liveAcc = document.getElementById('live-acc');
const liveTimer = document.getElementById('live-timer');
const liveTimerDisplay = document.getElementById('live-timer-display');
const overlay = document.getElementById('result-overlay');
const progress = document.getElementById('progress');

// Stats elements
const statsBtn = document.getElementById('stats-btn');
const statsModal = document.getElementById('stats-modal');
const closeStatsBtn = document.getElementById('close-stats');
const statsHistoryBody = document.getElementById('stats-history-body');
const statsLoading = document.getElementById('stats-loading');
const statsEmpty = document.getElementById('stats-empty');
const statsFilterBtns = document.querySelectorAll('.stats-filter-btn');
const improvementSection = document.getElementById('improvement-section');
const averageSection = document.getElementById('average-section');

// Leaderboard elements
const leaderboardBtn = document.getElementById('leaderboard-btn');
const leaderboardModal = document.getElementById('leaderboard-modal');
const closeLeaderboard = document.getElementById('close-leaderboard');
const leaderboardBody = document.getElementById('leaderboard-body');
const leaderboardLoading = document.getElementById('leaderboard-loading');
const leaderboardEmpty = document.getElementById('leaderboard-empty');
const leaderboardContent = document.getElementById('leaderboard-content');
const filterBtns = document.querySelectorAll('.leaderboard-filter-btn');

// Runner elements
const runModal = document.getElementById('run-modal');
const runIframe = document.getElementById('runner-iframe');
const runConsole = document.getElementById('runner-console');
const runBtn = document.getElementById('run-code-btn');
const closeRunnerBtn = document.getElementById('close-runner');
const clearConsoleBtn = document.getElementById('clear-console');

// State
let typedChars = [];
let startTime = null;
let timerInterval = null;
let gameActive = false;
let gameFinished = false;

// ────────────────────────────────────────────────
// HELPER FUNCTIONS
// ────────────────────────────────────────────────
function escapeHtml(unsafe) {
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Auto-scroll code area to follow typing
function autoScrollCodeArea() {
  const codeArea = document.getElementById('code-area');
  const current = textDisplay.querySelector('.current');
  
  if (!current) return;
  
  const rect = current.getBoundingClientRect();
  const container = codeArea.getBoundingClientRect();
  
  // If current character is below visible area, scroll down
  if (rect.bottom > container.bottom - 50) {
    codeArea.scrollTop += rect.bottom - container.bottom + 50;
  }
  
  // If current character is above visible area, scroll up
  if (rect.top < container.top + 50) {
    codeArea.scrollTop -= container.top - rect.top + 50;
  }
}

function renderText() {
  let html = '';
  for (let i = 0; i < SNIPPET_TEXT.length; i++) {
    const ch = SNIPPET_TEXT[i];
    let display = ch === ' ' ? '&nbsp;' : (ch === '\n' ? '<br>' : escapeHtml(ch));
    let cls = 'char';
    if (i < typedChars.length) {
      cls += typedChars[i] === ch ? ' correct' : ' incorrect';
    } else if (i === typedChars.length) {
      cls += ' current';
    }
    html += `<span class="${cls}">${display}</span>`;
  }
  textDisplay.innerHTML = html;
  updateCaret();
  updateProgress();
  autoScrollCodeArea();
}

function updateCaret() {
  const current = textDisplay.querySelector('.current');
  if (!current) return;
  const rect = current.getBoundingClientRect();
  const container = document.getElementById('code-area').getBoundingClientRect();
  caret.style.left = (rect.left - container.left) + 'px';
  caret.style.top = (rect.top - container.top) + 'px';
}

function updateProgress() {
  const progressPct = typedChars.length / SNIPPET_TEXT.length * 100;
  progress.style.width = Math.min(progressPct, 100) + '%';
}

function formatTime(seconds) {
  if (seconds >= 3600) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return `${h}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
  }
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}:${s.toString().padStart(2,'0')}` : s.toString();
}

function startTimer() {
  if (timeLimit <= 0) {
    liveTimer.textContent = liveTimerDisplay.textContent = '∞';
    return;
  }
  let remaining = timeLimit;
  liveTimer.textContent = liveTimerDisplay.textContent = formatTime(remaining);

  timerInterval = setInterval(() => {
    remaining--;
    liveTimer.textContent = liveTimerDisplay.textContent = formatTime(remaining);
    if (remaining <= 0) {
      clearInterval(timerInterval);
      finishGame();
    }
  }, 1000);
}

function updateStats() {
  if (!startTime) return { wpm: 0, acc: 100, elapsed: 0 };

  const elapsedMin = (Date.now() - startTime) / 60000;
  const correct = typedChars.reduce((sum, c, i) => sum + (c === SNIPPET_TEXT[i] ? 1 : 0), 0);
  const wpm = Math.round((correct / 5) / elapsedMin) || 0;
  const acc = typedChars.length ? Math.round((correct / typedChars.length) * 100) : 100;

  liveWpm.textContent = wpm;
  liveAcc.textContent = acc + '%';

  return { wpm, acc, correct, elapsed: Math.round((Date.now() - startTime) / 1000) };
}

function finishGame() {
  if (gameFinished) return;
  gameFinished = true;
  clearInterval(timerInterval);
  hiddenInput.disabled = true;

  const stats = updateStats();

  document.getElementById('final-wpm').textContent = stats.wpm;
  document.getElementById('final-acc').textContent = stats.acc + '%';
  document.getElementById('final-time').textContent = stats.elapsed;

  overlay.classList.remove('hidden');
  overlay.style.display = 'flex';

  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      snippet_id: SNIPPET_ID,
      wpm: stats.wpm,
      cpm: Math.round(stats.correct / (stats.elapsed / 60)) || 0,
      accuracy: stats.acc,
      time_taken: stats.elapsed,
      selected_lang: SNIPPET_LANG,
      mode: currentMode
    })
  }).catch(err => console.error('Save failed', err));

  loadUserStats('all');
}

// ────────────────────────────────────────────────
// USER STATS LOGIC
// ────────────────────────────────────────────────
function loadUserStats(filter = 'all') {
  const url = new URL(location);
  url.searchParams.set('stats', '1');
  if (filter !== 'all') url.searchParams.set('mode', filter);
  else url.searchParams.delete('mode');

  fetch(url)
    .then(res => res.json())
    .then(data => {
      if (!data.success) return;

      const stats = data.stats;
      const history = data.history;
      const improvement = data.improvement;

      if (history.length >= 1) {
        improvementSection.classList.remove('hidden');
        const wpmChange = improvement.wpm;
        const accChange = improvement.accuracy;
        const wpmEl = document.getElementById('improvement-wpm');
        const accEl = document.getElementById('improvement-acc');

        wpmEl.textContent = (wpmChange >= 0 ? '+' : '') + wpmChange;
        wpmEl.className = wpmChange >= 0 ? 'text-green-400' : 'text-red-400';

        accEl.textContent = (accChange >= 0 ? '+' : '') + accChange.toFixed(1) + '%';
        accEl.className = accChange >= 0 ? 'text-green-400' : 'text-red-400';
      }

      if (stats.total_tests > 0) {
        averageSection.classList.remove('hidden');
        document.getElementById('average-wpm').textContent = Math.round(stats.avg_wpm) || 0;
        document.getElementById('average-acc').textContent = Math.round(stats.avg_accuracy) || 0;
      }
    })
    .catch(err => console.error('Stats load failed', err));
}

function showStats(filter = 'all') {
  statsModal.classList.remove('hidden');
  statsLoading.classList.remove('hidden');
  statsEmpty.classList.add('hidden');
  document.getElementById('stats-content').classList.add('hidden');
  statsHistoryBody.innerHTML = '';

  statsFilterBtns.forEach(btn => {
    btn.classList.toggle('bg-blue-600', btn.dataset.filter === filter);
    btn.classList.toggle('hover:bg-gray-600', btn.dataset.filter !== filter);
    if (btn.dataset.filter !== filter) {
      btn.classList.add('bg-gray-700');
    } else {
      btn.classList.remove('bg-gray-700');
    }
  });

  const url = new URL(location);
  url.searchParams.set('stats', '1');
  if (filter !== 'all') url.searchParams.set('mode', filter);
  else url.searchParams.delete('mode');

  fetch(url)
    .then(res => res.json())
    .then(data => {
      statsLoading.classList.add('hidden');

      if (!data.success || !data.stats) {
        statsEmpty.classList.remove('hidden');
        return;
      }

      const stats = data.stats;
      let history = data.history;

      history.reverse();

      document.getElementById('total-tests').textContent = stats.total_tests || 0;
      document.getElementById('stats-avg-wpm').textContent = Math.round(stats.avg_wpm) || 0;
      document.getElementById('stats-avg-acc').textContent = (Math.round(stats.avg_accuracy) || 0) + '%';
      document.getElementById('stats-best-wpm').textContent = stats.best_wpm || 0;

      if (!history || history.length === 0) {
        statsEmpty.classList.remove('hidden');
        return;
      }

      history.forEach((row, index) => {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-gray-700 hover:bg-gray-700/50 transition-colors';
        const number = history.length - index;
        tr.innerHTML = `
          <td class="p-3 text-center">${number}</td>
          <td class="p-3 text-blue-400 font-bold text-center">${row.wpm}</td>
          <td class="p-3 text-green-400 text-center">${Math.round(row.accuracy)}%</td>
          <td class="p-3 text-center">${row.time_taken}s</td>
          <td class="p-3 text-center uppercase text-sm">${row.language}</td>
          <td class="p-3 text-center capitalize text-sm">${row.mode}</td>
          <td class="p-3 text-gray-400 text-xs text-right">
            ${new Date(row.created_at).toLocaleString('en-US', {
              month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
            })}
          </td>
        `;
        statsHistoryBody.appendChild(tr);
      });

      document.getElementById('stats-content').classList.remove('hidden');
    })
    .catch(err => {
      statsLoading.classList.add('hidden');
      statsEmpty.textContent = 'Error loading stats';
      statsEmpty.classList.remove('hidden');
      console.error(err);
    });
}

statsBtn.onclick = () => showStats('all');

closeStatsBtn.onclick = () => {
  statsModal.classList.add('hidden');
};

statsModal.onclick = e => {
  if (e.target === statsModal) statsModal.classList.add('hidden');
};

statsFilterBtns.forEach(btn => {
  btn.onclick = () => showStats(btn.dataset.filter);
});

// ────────────────────────────────────────────────
// LEADERBOARD LOGIC - FIXED
// ────────────────────────────────────────────────
function showLeaderboard(filter = 'all') {
  leaderboardModal.classList.remove('hidden');
  leaderboardLoading.classList.remove('hidden');
  leaderboardEmpty.classList.add('hidden');
  leaderboardContent.classList.add('hidden');
  leaderboardBody.innerHTML = '';

  filterBtns.forEach(btn => {
    btn.classList.toggle('bg-blue-600', btn.dataset.filter === filter);
    btn.classList.toggle('bg-gray-700', btn.dataset.filter !== filter);
    if (btn.dataset.filter !== filter) {
      btn.classList.add('hover:bg-gray-600');
    }
  });

  const url = new URL(location);
  url.searchParams.set('leaderboard', '1');
  if (filter !== 'all') url.searchParams.set('mode', filter);
  else url.searchParams.delete('mode');

  fetch(url)
    .then(res => res.json())
    .then(data => {
      leaderboardLoading.classList.add('hidden');

      if (!data.success || !data.results || data.results.length === 0) {
        leaderboardEmpty.classList.remove('hidden');
        return;
      }

      leaderboardContent.classList.remove('hidden');

      data.results.forEach((row, index) => {
        const tr = document.createElement('tr');
        tr.className = 'border-b border-gray-700 hover:bg-gray-700/50 transition-colors';
        tr.innerHTML = `
          <td class="p-4 text-center font-semibold">${index + 1}</td>
          <td class="p-4 font-medium">${escapeHtml(row.username || 'Anonymous')}</td>
          <td class="p-4 text-blue-400 font-bold text-center">${row.wpm}</td>
          <td class="p-4 text-green-400 text-center">${Math.round(row.accuracy)}%</td>
          <td class="p-4 text-center">${row.time_taken}s</td>
          <td class="p-4 text-center uppercase text-sm">${row.language}</td>
          <td class="p-4 text-center capitalize text-sm">${row.mode}</td>
          <td class="p-4 text-gray-400 text-sm text-right">
            ${new Date(row.created_at).toLocaleString('en-US', {
              month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
            })}
          </td>
        `;
        leaderboardBody.appendChild(tr);
      });
    })
    .catch(err => {
      leaderboardLoading.classList.add('hidden');
      leaderboardEmpty.textContent = 'Error loading leaderboard';
      leaderboardEmpty.classList.remove('hidden');
      console.error(err);
    });
}

leaderboardBtn.onclick = () => showLeaderboard('all');

closeLeaderboard.onclick = () => {
  leaderboardModal.classList.add('hidden');
};

leaderboardModal.onclick = e => {
  if (e.target === leaderboardModal) leaderboardModal.classList.add('hidden');
};

filterBtns.forEach(btn => {
  btn.onclick = () => showLeaderboard(btn.dataset.filter);
});

// ────────────────────────────────────────────────
// RUNNER: open sandboxed iframe and display console output
// ────────────────────────────────────────────────
function clearRunnerConsole() {
  runConsole.textContent = '';
}

function appendRunnerConsole(type, message) {
  const prefix = type === 'error' ? '[Error]' : type === 'warn' ? '[Warn]' : '[Log]';
  runConsole.textContent += `${prefix} ${message}\n`;
  runConsole.scrollTop = runConsole.scrollHeight;
}

function buildRunnerHTML(code, lang) {
  if (lang === 'html' || lang === 'mixed') {
    return `<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Runner</title></head>
<body>
${code}
<script>
(function(){
  function send(type, msg) {
    try { parent.postMessage({source:'codetype-runner', type: type, message: String(msg)}, '*'); } catch(e){}
  }
  ['log','warn','error','info'].forEach(function(m) {
    const orig = console[m] || function(){};
    console[m] = function() {
      try {
        orig.apply(console, arguments);
      } catch(e){}
      send(m, Array.from(arguments).map(function(a){try{return typeof a === 'object' ? JSON.stringify(a) : String(a);}catch(e){return String(a);}}).join(' '));
    };
  });
  window.addEventListener('error', function(ev) {
    send('error', ev.message + ' at ' + ev.filename + ':' + ev.lineno);
  });
})();
<\/script>
</body></html>`;
  }

  if (lang === 'css') {
    return `<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Runner CSS</title>
<style>${code}</style>
</head>
<body>
<div style="padding:20px;">
<h1>Preview</h1>
<p>This is a preview area when running CSS.</p>
<button class="button">Button</button>
</div>
<script>
(function(){
  function send(type, msg) {
    try { parent.postMessage({source:'codetype-runner', type: type, message: String(msg)}, '*'); } catch(e){}
  }
  window.addEventListener('error', function(ev) {
    send('error', ev.message + ' at ' + ev.filename + ':' + ev.lineno);
  });
  send('log','CSS injected');
})();
<\/script>
</body></html>`;
  }

  return `<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Runner JS</title></head>
<body>
<div id="output"></div>
<script>
(function(){
  function send(type, msg) {
    try { parent.postMessage({source:'codetype-runner', type: type, message: String(msg)}, '*'); } catch(e){}
  }
  ['log','warn','error','info'].forEach(function(m) {
    const orig = console[m] || function(){};
    console[m] = function() {
      try {
        orig.apply(console, arguments);
      } catch(e){}
      send(m, Array.from(arguments).map(function(a){try{return typeof a === 'object' ? JSON.stringify(a) : String(a);}catch(e){return String(a);}}).join(' '));
    };
  });
  try {
    ${code}
  } catch (err) {
    send('error', err.message || String(err));
  }
  window.addEventListener('error', function(ev) {
    send('error', ev.message + ' at ' + ev.filename + ':' + ev.lineno);
  });
})();
<\/script>
</body></html>`;
}

function openRunner(code, lang) {
  clearRunnerConsole();
  runModal.classList.remove('hidden');

  const html = buildRunnerHTML(code, lang);
  const blob = new Blob([html], { type: 'text/html' });
  const url = URL.createObjectURL(blob);

  runIframe.src = url;

  runIframe.onload = function() {
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  };
}

window.addEventListener('message', function(ev) {
  try {
    const data = ev.data;
    if (!data || data.source !== 'codetype-runner') return;
    appendRunnerConsole(data.type, data.message);
  } catch (e) {
    // ignore
  }
});

closeRunnerBtn.addEventListener('click', () => {
  runModal.classList.add('hidden');
  try { runIframe.src = 'about:blank'; } catch(e){}
});

clearConsoleBtn.addEventListener('click', clearRunnerConsole);

runBtn.addEventListener('click', () => {
  const userCode = typedChars.length ? typedChars.join('') : SNIPPET_TEXT;
  let lang = SNIPPET_LANG || 'js';
  if (lang === 'mixed') {
    const trimmed = userCode.trim();
    if (trimmed.startsWith('<')) lang = 'html';
    else if (trimmed.startsWith('body') || trimmed.includes('{') && trimmed.includes('}')) lang = 'css';
    else lang = 'js';
  }
  openRunner(userCode, lang);
});

runModal.addEventListener('click', (e) => {
  if (e.target === runModal) {
    closeRunnerBtn.click();
  }
});

// ────────────────────────────────────────────────
// TYPING EVENT LISTENERS & INIT
// ────────────────────────────────────────────────
hiddenInput.addEventListener('input', e => {
  if (!gameActive && !gameFinished) {
    startTime = Date.now();
    if (timeLimit > 0) startTimer();
    gameActive = true;
  }

  typedChars = [...e.target.value];
  renderText();
  updateCaret();
  updateStats();

  if (typedChars.length >= SNIPPET_TEXT.length) finishGame();
});

document.addEventListener('keydown', e => {
  if (!e.ctrlKey && !e.metaKey) hiddenInput.focus();
});

document.addEventListener('click', e => {
  if (!e.target.closest('header') && !e.target.closest('select') && !e.target.closest('button')) {
    hiddenInput.focus();
  }
});

document.querySelectorAll('.mode-btn').forEach(btn => {
  btn.onclick = () => {
    const url = new URL(location);
    url.searchParams.set('mode', btn.dataset.mode);
    location = url;
  };
});

document.getElementById('timer-select').onchange = e => {
  const url = new URL(location);
  url.searchParams.set('timer', e.target.value);
  location = url;
};

document.getElementById('lang-select').onchange = e => {
  const url = new URL(location);
  url.searchParams.set('lang', e.target.value);
  location = url;
};

document.getElementById('timer-select').value = '<?= $timer ?>';
document.getElementById('lang-select').value = '<?= $lang ?>';
document.querySelector(`.mode-btn[data-mode="${currentMode}"]`)?.classList.add('bg-blue-600');

if (timeLimit > 0) {
  liveTimer.textContent = liveTimerDisplay.textContent = formatTime(timeLimit);
}

renderText();
hiddenInput.focus();
</script>
</body>
</html>