<?php
require_once 'config.php';

if (currentUser()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Por favor ingresa tu nombre y contraseña.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM `User` WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            // Auto-registro
            $role = (strtolower($username) === 'admin') ? 'ADMIN' : 'USER';
            $ins = $db->prepare("INSERT INTO `User` (username, password, role, points, createdAt, updatedAt) VALUES (?, ?, ?, 0, NOW(), NOW())");
            $ins->execute([$username, $password, $role]);
            $userId = $db->lastInsertId();
            $_SESSION['user_id'] = $userId;
            header('Location: index.php');
            exit;
        } else {
            if ($user['password'] !== $password) {
                $error = 'Contraseña incorrecta.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                header('Location: index.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Iniciar Sesión – Quiniela Mundial 2026</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css?v=3.25" />
</head>
<body>
  <div class="login-page fade-in">
    <div class="login-card">
      <div class="login-badge">⚽</div>
      <h1 class="login-title">Quiniela 2026</h1>
      <p class="login-subtitle">Ingresa para jugar con tus amigos</p>

      <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="form-group">
          <label for="username">Tu Nombre o Apodo</label>
          <input type="text" id="username" name="username" class="form-input"
                 placeholder="Ej. Juanito, El Profe..." required
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
        </div>
        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" class="form-input"
                 placeholder="Tu contraseña personal" required />
        </div>
        <button type="submit" class="btn-login">Entrar al Torneo ⚡</button>
      </form>

      <p class="login-note">
        Si es la primera vez, escribe tu nombre y una contraseña para registrarte automáticamente.
      </p>
    </div>
  </div>
</body>
</html>
