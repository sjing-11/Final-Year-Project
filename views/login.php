<?php
// views/login.php
$errorMsg = null;
if (isset($_GET['error'])) {
    $errorMsg = 'Invalid email or password. Please try again.';
}
if (!function_exists('e')) {
  function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login · Inventor</title>
  <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body>
  <main class="login-page">
    <div class="login-wrap">
      <!-- Left visual -->
      <div class="login-left">
        <img src="images/logo.png" alt="Inventor" class="login-hero">
        <div class="brand-big">INVENTOR</div>
      </div>

      <!-- Right login card -->
      <div class="login-card">
        <div class="login-head">
          <div class="login-mini"><img src="images/logo.png" alt=""></div>
          <h1 class="login-title">Log in to your account</h1>
          <p class="login-sub">Welcome back! Please enter your details.</p>
        </div>

        <?php if ($errorMsg): ?>
            <!-- This is the new error message block -->
            <div class="alert error" style="margin:12px 0; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 12px; border-radius: 8px;">
                <?= e($errorMsg) ?>
            </div>
        <?php endif; ?>

        <!-- Form posts to the login handler -->
        <form class="login-form" method="post" action="/?page=login_handler">
          <div>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" placeholder="Enter your email" required>
          </div>

          <div>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" placeholder="Enter your password" required>
          </div>

          <div class="login-actions">
            <button class="btn btn-primary" type="submit">Sign in</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>