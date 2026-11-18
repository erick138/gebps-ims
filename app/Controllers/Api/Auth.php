<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ApiException;
use App\Libraries\Db;
use App\Libraries\Jwt;
use App\Libraries\Rbac;
use App\Libraries\SmsQueue;

class Auth extends BaseApiController
{
    private const PUBLIC_COLS = 'id, username, email, name, role, status, position, department_id, personnel_id, phone, sms_opt_in, must_change_password, last_login_at';

    private function throttle(string $bucket, int $max): void
    {
        if (! \App\Core\Throttle::check($bucket . '|' . $this->request->getIPAddress(), $max, 60)) {
            throw new ApiException('Too many attempts. Wait a minute and try again.', 429);
        }
    }

    private static function checkPassword(string $p): void
    {
        if (strlen($p) < 8 || ! preg_match('/[A-Za-z]/', $p) || ! preg_match('/\d/', $p)) {
            throw new ApiException('Password must be at least 8 characters with letters and numbers.');
        }
        if (strlen($p) > 72) {
            throw new ApiException('Password is too long (max 72 characters).');
        }
    }

    public static function hash(string $p): string
    {
        return password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function login()
    {
        $this->throttle('login', 10);
        $in = $this->input();
        $id = trim((string) ($in['username'] ?? ''));
        $pw = (string) ($in['password'] ?? '');
        if ($id === '' || $pw === '') {
            throw new ApiException('Enter your username and password.');
        }
        $u = Db::one('SELECT * FROM users WHERE lower(username) = lower(?) OR lower(email) = lower(?)', [$id, $id]);
        // Same message whether the user or the password is wrong.
        if (! $u || ! password_verify($pw, $u['password_hash'])) {
            throw new ApiException('Incorrect username or password.', 401);
        }
        if ($u['status'] === 'pending') {
            throw new ApiException('Your account is waiting for approval by an administrator.', 403);
        }
        if ($u['status'] !== 'active') {
            throw new ApiException('This account has been deactivated. Contact an administrator.', 403);
        }
        Db::run('UPDATE users SET last_login_at = now() WHERE id = ?', [$u['id']]);
        Activity::log($u, 'auth', $u['name'] . ' signed in.');

        return ['token' => Jwt::issue($u), 'user' => $this->publicUser($u['id'])];
    }

    public function register()
    {
        $this->throttle('register', 5);
        $in   = $this->input();
        $name = trim((string) ($in['name'] ?? ''));
        $user = strtolower(trim((string) ($in['username'] ?? '')));
        $mail = strtolower(trim((string) ($in['email'] ?? '')));
        $err  = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $err['name'] = 'Enter your full name.';
        }
        if (! preg_match('/^[a-z0-9_.]{3,30}$/', $user)) {
            $err['username'] = '3–30 characters: letters, numbers, dot or underscore.';
        }
        if (! filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $err['email'] = 'Enter a valid email address.';
        }
        try {
            self::checkPassword((string) ($in['password'] ?? ''));
        } catch (ApiException $e) {
            $err['password'] = $e->getMessage();
        }
        $phone = '';
        if (! empty($in['phone'])) {
            $phone = SmsQueue::phone($in['phone']) ?? '';
            if ($phone === '') {
                $err['phone'] = 'Enter a Philippine mobile number, e.g. 0917 123 4567.';
            }
        }
        if ($err) {
            throw new ApiException('Please fix the highlighted fields.', 422, $err);
        }
        if (Db::one('SELECT 1 FROM users WHERE lower(username) = ? OR lower(email) = ?', [$user, $mail])) {
            throw new ApiException('That username or email is already registered.', 409);
        }
        // Self-registration always creates a pending Teacher / Staff account.
        $u = Db::insert('users', [
            'username' => $user, 'email' => $mail, 'name' => $name, 'password_hash' => self::hash($in['password']),
            'role' => 'staff', 'status' => 'pending', 'position' => trim((string) ($in['position'] ?? '')),
            'department_id' => ! empty($in['department_id']) ? (int) $in['department_id'] : null,
            'phone' => $phone, 'sms_opt_in' => $phone !== '' ? 1 : 0,
        ]);
        Activity::log(['id' => $u['id'], 'name' => $name], 'auth', "New account registered: $name (awaiting approval).");

        return $this->ok(['message' => 'Registration received. An administrator must approve your account before you can sign in.'], 201);
    }

    public function me()
    {
        return $this->publicUser($this->user()['id']);
    }

    public function updateProfile()
    {
        $u  = $this->user();
        $in = $this->input();
        $set = [];
        if (isset($in['name'])) {
            $n = trim((string) $in['name']);
            if ($n === '' || mb_strlen($n) > 120) {
                throw new ApiException('Enter your full name.');
            }
            $set['name'] = $n;
        }
        if (array_key_exists('position', $in)) {
            $set['position'] = mb_substr(trim((string) $in['position']), 0, 120);
        }
        if (array_key_exists('phone', $in)) {
            $set['phone'] = $in['phone'] === '' ? '' : (SmsQueue::phone($in['phone']) ?? throw new ApiException('Enter a Philippine mobile number, e.g. 0917 123 4567.'));
        }
        if (array_key_exists('sms_opt_in', $in)) {
            $set['sms_opt_in'] = filter_var($in['sms_opt_in'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('department_id', $in)) {
            $set['department_id'] = ($in['department_id'] === '' || $in['department_id'] === null) ? null : (int) $in['department_id'];
        }
        if (isset($in['email'])) {
            $e = strtolower(trim((string) $in['email']));
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException('Enter a valid email address.');
            }
            if (Db::one('SELECT 1 FROM users WHERE lower(email) = ? AND id <> ?', [$e, $u['id']])) {
                throw new ApiException('That email is already used.', 409);
            }
            $set['email'] = $e;
        }
        Db::update('users', $u['id'], $set);

        return $this->publicUser($u['id']);
    }

    public function changePassword()
    {
        $u   = $this->user();
        $in  = $this->input();
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (! password_verify((string) ($in['current_password'] ?? ''), $row['password_hash'])) {
            throw new ApiException('Your current password is incorrect.', 422, ['current_password' => 'Incorrect.']);
        }
        $new = (string) ($in['new_password'] ?? '');
        self::checkPassword($new);
        if ($new === ($in['current_password'] ?? '')) {
            throw new ApiException('Choose a password different from the current one.');
        }
        Db::update('users', $u['id'], ['password_hash' => self::hash($new), 'must_change_password' => 0]);
        Activity::log($u, 'auth', $u['name'] . ' changed their password.');

        return ['changed' => true];
    }

    /** Always answers the same way so it cannot be used to discover which emails are registered. */
    public function forgotPassword()
    {
        $this->throttle('forgot', 5);
        $mail = strtolower(trim((string) ($this->input()['email'] ?? '')));
        $u    = $mail ? Db::one("SELECT * FROM users WHERE lower(email) = ? AND status = 'active'", [$mail]) : null;
        if ($u) {
            $token = bin2hex(random_bytes(32));
            Db::run("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, now() + INTERVAL 1 HOUR)", [$u['id'], hash('sha256', $token)]);
            $link = rtrim((string) env('app.frontendUrl', ''), '/') . '/#reset=' . $token;
            $sent = false;
            if (env('mail.from')) {
                $sent = \App\Libraries\Mailer::send($u['email'], 'GEBPSOICS password reset',
                    "Hello {$u['name']},\n\nUse this link within 1 hour to choose a new password:\n$link\n\nIf you did not ask for this, ignore this message.");
            }
            if (! $sent) {
                log_message('warning', "Password reset for {$u['username']} could not be emailed. Link: $link");
            }
        }

        return ['message' => 'If that email belongs to an active account, a reset link has been sent.'];
    }

    public function resetPassword()
    {
        $this->throttle('reset', 10);
        $in    = $this->input();
        $token = (string) ($in['token'] ?? '');
        self::checkPassword((string) ($in['new_password'] ?? ''));
        $r = Db::one('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > now()', [hash('sha256', $token)]);
        if (! $r) {
            throw new ApiException('This reset link is invalid or has expired.', 400);
        }
        Db::transaction(function () use ($r, $in) {
            Db::update('users', $r['user_id'], ['password_hash' => self::hash($in['new_password']), 'must_change_password' => 0]);
            Db::run('UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL', [$r['user_id']]);
        });

        return ['reset' => true];
    }

    private function publicUser($id): array
    {
        $u = Db::one('SELECT ' . self::PUBLIC_COLS . ' FROM users WHERE id = ?', [$id]);
        $u = Db::bools($u, ['sms_opt_in', 'must_change_password']);
        $u['role_label'] = Rbac::LABELS[$u['role']] ?? $u['role'];
        $u['permissions'] = array_values(array_filter(['equipment', 'personnel', 'locations', 'issuance', 'maintenance', 'approve', 'users', 'reports', 'tags', 'viewAll', 'data', 'super'], fn ($p) => Rbac::can($u['role'], $p)));

        return $u;
    }
}
