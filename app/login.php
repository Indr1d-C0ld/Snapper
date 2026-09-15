<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
boot_session();

if (($_SESSION['ok'] ?? false) === true) {
    header('Location: /snapper/index.php');
    exit;
}

$ip     = client_ip();
$err    = '';
$expired = isset($_GET['expired']);
$needTotp = AUTH_TOTP_SECRET !== null && AUTH_TOTP_SECRET !== '';

$csrf = csrf_token();   // token di sessione, valido anche prima dell'accesso

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        // Sessione scaduta o richiesta di terze parti: non contiamo il
        // tentativo nel throttling, non è un tentativo di password.
        $err = 'Sessione scaduta, riprova.';
        audit("LOGIN csrf ip=$ip");
    } else {
    [$allowed, $wait] = login_gate($ip);
    if (!$allowed) {
        $err = "Troppi tentativi. Riprova tra {$wait}s.";
        audit("LOGIN blocked ip=$ip wait=$wait");
    } else {
        $u = (string)($_POST['u'] ?? '');
        $p = (string)($_POST['p'] ?? '');
        $otp = (string)($_POST['otp'] ?? '');

        $userOk = hash_equals(AUTH_USER, $u);
        $passOk = AUTH_PASS_HASH !== '' && password_verify($p, AUTH_PASS_HASH);
        $totpOk = !$needTotp || totp_verify(AUTH_TOTP_SECRET, $otp);

        if ($userOk && $passOk && $totpOk) {
            login_ok($ip);
            session_regenerate_id(true);
            $_SESSION['ok']   = true;
            $_SESSION['born'] = time();
            $_SESSION['seen'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            audit("LOGIN ok ip=$ip");
            header('Location: /snapper/index.php');
            exit;
        }

        login_fail($ip);
        audit("LOGIN fail ip=$ip user=" . ($userOk ? 'ok' : 'no')
            . " pass=" . ($passOk ? 'ok' : 'no')
            . " totp=" . ($totpOk ? 'ok' : 'no'));
        // Freno costante su OGNI tentativo fallito: il backoff di login_gate()
        // è per singolo IP e non morde un attacco distribuito. Un ritardo fisso
        // ne limita il ritmo senza introdurre un blocco globale, che darebbe a
        // un terzo il modo di chiudere fuori l'utente legittimo.
        usleep(750000);
        // messaggio volutamente generico
        $err = 'Credenziali non valide.';
    }
    }
}

layout_head('Snapper — accesso');
?>
<div class="login-shell">
  <div class="mount">
    <div class="filmtab">Snapper · camera oscura</div>
    <h1>Accesso</h1>
    <?php if ($expired): ?><p class="err">Sessione scaduta, effettua di nuovo l'accesso.</p><?php endif; ?>
    <?php if ($err !== ''): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label for="u">Utente</label>
      <input id="u" name="u" required autofocus autocomplete="username">
      <label for="p">Password</label>
      <input id="p" name="p" type="password" required autocomplete="current-password">
      <?php if ($needTotp): ?>
        <label for="otp">Codice 2FA</label>
        <input id="otp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" required>
      <?php endif; ?>
      <button type="submit">Entra</button>
    </form>
    <p class="note">Accesso protetto · tentativi registrati</p>
  </div>
</div>
<?php
layout_foot();
