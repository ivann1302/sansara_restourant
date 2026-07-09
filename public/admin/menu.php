<?php

declare(strict_types=1);

session_start();

const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;
const MENU_RELATIVE_PATH = '/../menu/sansara-menu.pdf';
const PRIVATE_CONFIG_PATH = __DIR__ . '/../../server/config/admin.local.php';

$message = null;
$messageType = 'info';

function getAdminCredentials(): array
{
    $envPassword = getenv('ADMIN_MENU_PASSWORD');

    if (is_string($envPassword) && $envPassword !== '') {
        return [
            'source' => 'env',
            'password' => $envPassword,
            'password_hash' => null,
        ];
    }

    if (is_file(PRIVATE_CONFIG_PATH)) {
        $config = require PRIVATE_CONFIG_PATH;

        if (is_array($config) && isset($config['password_hash']) && is_string($config['password_hash']) && $config['password_hash'] !== '') {
            return [
                'source' => 'config',
                'password' => null,
                'password_hash' => $config['password_hash'],
            ];
        }

        if (is_array($config) && isset($config['password']) && is_string($config['password']) && $config['password'] !== '') {
            return [
                'source' => 'config',
                'password' => $config['password'],
                'password_hash' => null,
            ];
        }
    }

    return [
        'source' => null,
        'password' => null,
        'password_hash' => null,
    ];
}

function verifyAdminPassword(array $credentials, string $password): bool
{
    if (isset($credentials['password_hash']) && is_string($credentials['password_hash']) && $credentials['password_hash'] !== '') {
        return password_verify($password, $credentials['password_hash']);
    }

    if (isset($credentials['password']) && is_string($credentials['password']) && $credentials['password'] !== '') {
        return hash_equals($credentials['password'], $password);
    }

    return false;
}

function saveAdminPassword(string $password): bool
{
    $configDir = dirname(PRIVATE_CONFIG_PATH);

    if (!is_dir($configDir) && !mkdir($configDir, 0755, true)) {
        return false;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($hash) || $hash === '') {
        return false;
    }

    $content = "<?php\n\n"
        . "declare(strict_types=1);\n\n"
        . "return [\n"
        . "    'password_hash' => " . var_export($hash, true) . ",\n"
        . "];\n";

    $temporaryPath = PRIVATE_CONFIG_PATH . '.tmp';

    if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
        return false;
    }

    chmod($temporaryPath, 0600);

    if (!rename($temporaryPath, PRIVATE_CONFIG_PATH)) {
        @unlink($temporaryPath);
        return false;
    }

    chmod(PRIVATE_CONFIG_PATH, 0600);

    return true;
}

function isAuthenticated(): bool
{
    return isset($_SESSION['menu_admin_authenticated']) && $_SESSION['menu_admin_authenticated'] === true;
}

function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function formatBytes(int $bytes): string
{
    return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' МБ';
}

function currentMenuSize(): ?string
{
    $path = __DIR__ . MENU_RELATIVE_PATH;

    if (!is_file($path)) {
        return null;
    }

    $size = filesize($path);

    return $size === false ? null : formatBytes($size);
}

function validateUploadedPdf(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Не удалось загрузить файл. Проверьте PDF и попробуйте еще раз.';
    }

    if (!isset($file['tmp_name'], $file['size'], $file['name']) || !is_string($file['tmp_name'])) {
        return 'Не удалось прочитать файл.';
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Не удалось проверить загруженный файл.';
    }

    if ((int) $file['size'] <= 0 || (int) $file['size'] > MAX_UPLOAD_BYTES) {
        return 'Файл должен быть PDF размером до ' . formatBytes(MAX_UPLOAD_BYTES) . '.';
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

    if ($extension !== 'pdf') {
        return 'Можно загрузить только файл PDF.';
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file['tmp_name']);

    if ($mimeType !== 'application/pdf' && $mimeType !== 'application/x-pdf') {
        return 'Файл не похож на корректный PDF.';
    }

    $handle = fopen($file['tmp_name'], 'rb');
    $signature = $handle === false ? false : fread($handle, 5);

    if (is_resource($handle)) {
        fclose($handle);
    }

    if ($signature !== '%PDF-') {
        return 'Файл не похож на корректный PDF.';
    }

    return null;
}

$adminCredentials = getAdminCredentials();
$canChangePassword = ($adminCredentials['source'] ?? null) !== 'env';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = 'Сессия устарела. Обновите страницу и попробуйте снова.';
        $messageType = 'error';
    } elseif (isset($_POST['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: menu.php');
        exit;
    } elseif (!isAuthenticated()) {
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

        if (($adminCredentials['source'] ?? null) === null) {
            $message = 'Пароль админки не настроен на сервере.';
            $messageType = 'error';
        } elseif (verifyAdminPassword($adminCredentials, $password)) {
            session_regenerate_id(true);
            $_SESSION['menu_admin_authenticated'] = true;
            generateCsrfToken();
            header('Location: menu.php');
            exit;
        } else {
            $message = 'Неверный пароль.';
            $messageType = 'error';
        }
    } elseif (isset($_POST['change_password'])) {
        $currentPassword = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
        $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
        $newPasswordRepeat = isset($_POST['new_password_repeat']) && is_string($_POST['new_password_repeat']) ? $_POST['new_password_repeat'] : '';

        if (!$canChangePassword) {
            $message = 'Пароль задан на хостинге через переменную окружения. Измените его в настройках сервера.';
            $messageType = 'error';
        } elseif (!verifyAdminPassword($adminCredentials, $currentPassword)) {
            $message = 'Текущий пароль указан неверно.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Новый пароль должен быть не короче 8 символов.';
            $messageType = 'error';
        } elseif ($newPassword !== $newPasswordRepeat) {
            $message = 'Новый пароль и повтор не совпадают.';
            $messageType = 'error';
        } elseif (!saveAdminPassword($newPassword)) {
            $message = 'Не удалось сохранить новый пароль. Проверьте права на папку server/config.';
            $messageType = 'error';
        } else {
            $adminCredentials = getAdminCredentials();
            $message = 'Пароль обновлен.';
            $messageType = 'success';
        }
    } else {
        $file = $_FILES['menu_pdf'] ?? null;

        if (!is_array($file)) {
            $message = 'Выберите PDF-файл.';
            $messageType = 'error';
        } else {
            $error = validateUploadedPdf($file);

            if ($error !== null) {
                $message = $error;
                $messageType = 'error';
            } else {
                $targetPath = __DIR__ . MENU_RELATIVE_PATH;
                $targetDir = dirname($targetPath);
                $temporaryPath = $targetPath . '.tmp';

                if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                    $message = 'Не удалось подготовить папку для меню.';
                    $messageType = 'error';
                } elseif (!move_uploaded_file($file['tmp_name'], $temporaryPath)) {
                    $message = 'Не удалось сохранить PDF. Проверьте права на папку menu.';
                    $messageType = 'error';
                } elseif (!rename($temporaryPath, $targetPath)) {
                    @unlink($temporaryPath);
                    $message = 'Не удалось заменить меню.';
                    $messageType = 'error';
                } else {
                    chmod($targetPath, 0644);
                    $message = 'Меню обновлено.';
                    $messageType = 'success';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$authenticated = isAuthenticated();
$menuSize = currentMenuSize();
?>
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Админка меню | Сансара</title>
    <style>
      :root {
        --color-bg: #f9f2e5;
        --color-text: #47342d;
        --color-olive: #687059;
        --color-olive-light: #c4ca9b;
        --color-lemon: #d3d06f;
        --color-error: #9f3428;
        --color-success: #4f6742;
      }

      * {
        box-sizing: border-box;
      }

      body {
        display: grid;
        min-height: 100vh;
        margin: 0;
        padding: 24px;
        place-items: center;
        background:
          radial-gradient(circle at 15% 10%, rgb(211 208 111 / 0.16), transparent 28%),
          linear-gradient(180deg, var(--color-bg), #f4ead4);
        color: var(--color-text);
        font-family: Arial, sans-serif;
        line-height: 1.5;
      }

      main {
        width: min(100%, 680px);
        padding: clamp(24px, 5vw, 38px);
        border: 1px solid rgb(104 112 89 / 0.18);
        border-radius: 8px;
        background: rgb(255 250 240 / 0.72);
        box-shadow: 0 22px 60px rgb(71 52 45 / 0.12);
      }

      h1 {
        margin: 0 0 10px;
        color: var(--color-olive);
        font-family: Georgia, serif;
        font-size: clamp(2rem, 7vw, 3.4rem);
        font-weight: 400;
        line-height: 1;
      }

      p {
        margin: 0;
      }

      form {
        display: grid;
        gap: 16px;
        margin-top: 24px;
      }

      label {
        display: grid;
        gap: 8px;
        font-weight: 700;
      }

      input[type="password"],
      input[type="file"] {
        width: 100%;
        min-height: 46px;
        padding: 10px 12px;
        border: 1px solid rgb(104 112 89 / 0.32);
        border-radius: 8px;
        background: #fffaf0;
        color: var(--color-text);
        font: inherit;
      }

      input[type="file"].dropzone__input {
        position: absolute;
        width: 1px;
        height: 1px;
        opacity: 0;
        pointer-events: none;
      }

      input:focus-visible,
      button:focus-visible,
      a:focus-visible {
        outline: 3px solid var(--color-lemon);
        outline-offset: 3px;
      }

      button,
      .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 11px 18px;
        border: 0;
        border-radius: 8px;
        background: var(--color-olive);
        color: #fffaf0;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        text-decoration: none;
        transition:
          background-color 180ms ease,
          color 180ms ease,
          border-color 180ms ease,
          transform 180ms ease;
      }

      button:hover,
      .button:hover {
        background: #47342d;
        transform: translateY(-1px);
      }

      .button--secondary {
        border: 1px solid rgb(104 112 89 / 0.28);
        background: transparent;
        color: var(--color-olive);
      }

      .button--secondary:hover {
        border-color: #47342d;
        background: rgb(71 52 45 / 0.08);
        color: #47342d;
      }

      .button-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
      }

      .message {
        margin-top: 18px;
        padding: 12px 14px;
        border-radius: 8px;
        font-weight: 700;
      }

      .message--success {
        background: rgb(196 202 155 / 0.32);
        color: var(--color-success);
      }

      .message--error {
        background: rgb(159 52 40 / 0.1);
        color: var(--color-error);
      }

      .note {
        margin-top: 12px;
        color: rgb(71 52 45 / 0.72);
        font-size: 0.94rem;
      }

      .logout {
        margin-top: 18px;
      }

      .admin-section {
        margin-top: 22px;
      }

      .admin-section h2 {
        margin: 0 0 8px;
        color: var(--color-olive);
        font-size: 1.08rem;
      }

      .admin-section form {
        margin-top: 14px;
      }

      .tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 24px;
        padding: 6px;
        border: 1px solid rgb(104 112 89 / 0.18);
        border-radius: 8px;
        background: rgb(249 242 229 / 0.62);
      }

      .tab {
        flex: 1 1 190px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--color-olive);
      }

      .tab:hover {
        background: rgb(104 112 89 / 0.1);
        color: var(--color-text);
      }

      .tab[aria-selected="true"] {
        border-color: rgb(104 112 89 / 0.24);
        background: var(--color-olive);
        color: #fffaf0;
      }

      .tab-panel[hidden] {
        display: none;
      }

      .dropzone {
        position: relative;
        display: grid;
        min-height: 220px;
        padding: clamp(22px, 5vw, 34px);
        place-items: center;
        border: 2px dashed rgb(104 112 89 / 0.34);
        border-radius: 8px;
        background:
          linear-gradient(180deg, rgb(255 250 240 / 0.76), rgb(249 242 229 / 0.66)),
          rgb(196 202 155 / 0.12);
        cursor: pointer;
        text-align: center;
        transition:
          background-color 180ms ease,
          border-color 180ms ease,
          transform 180ms ease;
      }

      .dropzone:hover,
      .dropzone.is-dragover {
        border-color: var(--color-olive);
        background: rgb(196 202 155 / 0.2);
        transform: translateY(-1px);
      }

      .dropzone__content {
        display: grid;
        justify-items: center;
        gap: 10px;
      }

      .dropzone__icon {
        display: grid;
        width: 64px;
        height: 64px;
        place-items: center;
        border-radius: 50%;
        background: rgb(104 112 89 / 0.12);
        color: var(--color-olive);
        font-size: 2rem;
        font-weight: 700;
      }

      .dropzone__title {
        margin: 0;
        color: var(--color-text);
        font-size: clamp(1.18rem, 3vw, 1.55rem);
        font-weight: 700;
      }

      .dropzone__text,
      .dropzone__file {
        margin: 0;
        color: rgb(71 52 45 / 0.72);
        font-size: 0.96rem;
      }

      .dropzone__file {
        min-height: 1.5em;
        color: var(--color-olive);
        font-weight: 700;
      }

      .instructions {
        margin-top: 26px;
        padding-top: 20px;
        border-top: 1px solid rgb(104 112 89 / 0.18);
      }

      .instructions h2 {
        margin: 0 0 10px;
        color: var(--color-olive);
        font-size: 1rem;
      }

      .instructions ol {
        display: grid;
        gap: 7px;
        margin: 0;
        padding-left: 20px;
        color: rgb(71 52 45 / 0.76);
        font-size: 0.94rem;
      }

      @media (prefers-reduced-motion: reduce) {
        button,
        .button,
        .dropzone {
          transition: none;
        }

        button:hover,
        .button:hover,
        .dropzone:hover,
        .dropzone.is-dragover {
          transform: none;
        }
      }
    </style>
  </head>
  <body>
    <main>
      <h1>Меню ресторана</h1>
      <p>Здесь можно заменить PDF-файл, который открывается по кнопке «Меню» на сайте.</p>

      <?php if ($message !== null): ?>
        <p class="message message--<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </p>
      <?php endif; ?>

      <?php if (!$authenticated): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <label>
            Пароль администратора
            <input type="password" name="password" autocomplete="current-password" required>
          </label>
          <button type="submit">Войти</button>
        </form>
      <?php else: ?>
        <div class="tabs" role="tablist" aria-label="Разделы админки">
          <button class="tab" type="button" id="tab-menu" role="tab" aria-selected="true" aria-controls="panel-menu" data-tab="menu">Обновить меню</button>
          <button class="tab" type="button" id="tab-password" role="tab" aria-selected="false" aria-controls="panel-password" data-tab="password">Сменить пароль</button>
        </div>

        <section class="tab-panel" id="panel-menu" role="tabpanel" aria-labelledby="tab-menu" data-tab-panel="menu">
          <p class="note">
            Текущий файл: <?= $menuSize === null ? 'ещё не загружен' : htmlspecialchars($menuSize, ENT_QUOTES, 'UTF-8') ?>.
            Новый PDF заменит старый файл.
          </p>

          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <label class="dropzone" data-dropzone>
              <input class="dropzone__input" type="file" name="menu_pdf" accept="application/pdf,.pdf" required data-file-input>
              <span class="dropzone__content">
                <span class="dropzone__icon" aria-hidden="true">PDF</span>
                <span class="dropzone__title">Перетащите PDF сюда</span>
                <span class="dropzone__text">или нажмите, чтобы выбрать файл на компьютере</span>
                <span class="dropzone__file" data-file-name>Файл пока не выбран</span>
              </span>
            </label>
            <div class="button-row">
              <button type="submit">Обновить меню</button>
              <a class="button button--secondary" href="/menu/sansara-menu.pdf" target="_blank" rel="noopener noreferrer">Посмотреть текущее меню</a>
            </div>
            <p class="note">Можно загрузить только PDF до <?= htmlspecialchars(formatBytes(MAX_UPLOAD_BYTES), ENT_QUOTES, 'UTF-8') ?>. Новый файл сразу заменит старое меню.</p>
          </form>
        </section>

        <section class="admin-section tab-panel" id="panel-password" role="tabpanel" aria-labelledby="tab-password" data-tab-panel="password" hidden>
          <h2 id="password-title">Сменить пароль</h2>
          <?php if (!$canChangePassword): ?>
            <p class="note">Пароль задан на хостинге через переменную окружения. Его нужно менять в настройках сервера.</p>
          <?php else: ?>
            <p class="note">Новый пароль должен быть не короче 8 символов.</p>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <label>
                Текущий пароль
                <input type="password" name="current_password" autocomplete="current-password" required>
              </label>
              <label>
                Новый пароль
                <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
              </label>
              <label>
                Повторите новый пароль
                <input type="password" name="new_password_repeat" autocomplete="new-password" minlength="8" required>
              </label>
              <button type="submit" name="change_password" value="1">Сохранить новый пароль</button>
            </form>
          <?php endif; ?>
        </section>

        <form class="logout" method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <button class="button--secondary" type="submit" name="logout" value="1">Выйти из админки</button>
        </form>

        <section class="instructions" aria-labelledby="instructions-title">
          <h2 id="instructions-title">Короткая инструкция</h2>
          <ol>
            <li>Подготовьте новое меню в формате PDF.</li>
            <li>Перетащите файл в область загрузки или выберите его вручную.</li>
            <li>Нажмите «Обновить меню» и откройте текущее PDF для проверки.</li>
          </ol>
        </section>
      <?php endif; ?>
    </main>
    <script>
      const dropzone = document.querySelector("[data-dropzone]");
      const fileInput = document.querySelector("[data-file-input]");
      const fileName = document.querySelector("[data-file-name]");
      const tabs = Array.from(document.querySelectorAll("[data-tab]"));
      const tabPanels = Array.from(document.querySelectorAll("[data-tab-panel]"));

      if (dropzone instanceof HTMLElement && fileInput instanceof HTMLInputElement && fileName instanceof HTMLElement) {
        const updateFileName = () => {
          fileName.textContent = fileInput.files && fileInput.files.length > 0
            ? fileInput.files[0].name
            : "Файл пока не выбран";
        };

        fileInput.addEventListener("change", updateFileName);

        ["dragenter", "dragover"].forEach((eventName) => {
          dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add("is-dragover");
          });
        });

        ["dragleave", "drop"].forEach((eventName) => {
          dropzone.addEventListener(eventName, () => {
            dropzone.classList.remove("is-dragover");
          });
        });
      }

      if (tabs.length > 0 && tabPanels.length > 0) {
        const activateTab = (name) => {
          tabs.forEach((tab) => {
            const isActive = tab instanceof HTMLElement && tab.dataset.tab === name;
            tab.setAttribute("aria-selected", isActive ? "true" : "false");
          });

          tabPanels.forEach((panel) => {
            if (panel instanceof HTMLElement) {
              panel.hidden = panel.dataset.tabPanel !== name;
            }
          });
        };

        tabs.forEach((tab) => {
          tab.addEventListener("click", () => {
            if (tab instanceof HTMLElement && tab.dataset.tab) {
              activateTab(tab.dataset.tab);
            }
          });
        });
      }
    </script>
  </body>
</html>
