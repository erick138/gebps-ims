<?php

namespace App\Controllers\Api;

use App\Libraries\Activity;
use App\Libraries\ApiException;
use App\Libraries\Db;
use App\Libraries\Rbac;
use App\Libraries\SmsQueue;

/** Account administration. Admins manage non-admin accounts; only the super administrator manages admins. */
class Users extends BaseApiController
{
    private const COLS = 'u.id, u.username, u.email, u.name, u.role, u.status, u.position, u.department_id, u.personnel_id, u.phone, u.sms_opt_in, u.must_change_password, u.last_login_at, u.created_at, d.name AS department_name';

    public function index()
    {
        return Db::bools(Db::all('SELECT ' . self::COLS . " FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY (u.status = 'pending') DESC, u.name"), ['sms_opt_in', 'must_change_password']);
    }

    private function target($id): array
    {
        $t = Db::one('SELECT * FROM users WHERE id = ?', [$this->id($id)]) ?? throw new ApiException('User not found.', 404);
        if (! Rbac::canManage($this->user(), $t)) {
            throw new ApiException('Only the super administrator can manage administrator accounts.', 403);
        }

        return $t;
    }

    private function row(int $id): array
    {
        return Db::bools(Db::one('SELECT ' . self::COLS . ' FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?', [$id]), ['sms_opt_in', 'must_change_password']);
    }

    public function create()
    {
        $in   = $this->input();
        $actor = $this->user();
        $role = $in['role'] ?? 'staff';
        if (! in_array($role, Rbac::assignableRoles($actor['role']), true)) {
            throw new ApiException('You cannot assign that role.', 403);
        }
        $pw   = (string) ($in['password'] ?? '');
        if (strlen($pw) < 8 || ! preg_match('/[A-Za-z]/', $pw) || ! preg_match('/\d/', $pw) || strlen($pw) > 72) {
            throw new ApiException('Password must be 8–72 characters with letters and numbers.', 422, ['password' => 'Too weak.']);
        }
        $user = strtolower(trim((string) ($in['username'] ?? '')));
        $mail = strtolower(trim((string) ($in['email'] ?? '')));
        $name = trim((string) ($in['name'] ?? ''));
        if (! preg_match('/^[a-z0-9_.]{3,30}$/', $user) || ! filter_var($mail, FILTER_VALIDATE_EMAIL) || $name === '') {
            throw new ApiException('Name, a valid username and a valid email are required.');
        }
        if (Db::one('SELECT 1 FROM users WHERE lower(username) = ? OR lower(email) = ?', [$user, $mail])) {
            throw new ApiException('That username or email is already registered.', 409);
        }
        $phone = ! empty($in['phone']) ? (SmsQueue::phone($in['phone']) ?? throw new ApiException('Enter a Philippine mobile number.')) : '';
        $u = Db::insert('users', [
            'username' => $user, 'email' => $mail, 'name' => $name, 'password_hash' => Auth::hash($pw), 'role' => $role,
            'status' => 'active', 'position' => trim((string) ($in['position'] ?? '')), 'phone' => $phone,
            'department_id' => ! empty($in['department_id']) ? (int) $in['department_id'] : null,
            'personnel_id' => ! empty($in['personnel_id']) ? (int) $in['personnel_id'] : null,
            'must_change_password' => 1,
        ]);
        Activity::log($actor, 'user', "Account created: $name ({$role}).");

        return $this->ok($this->row((int) $u['id']), 201);
    }

    public function update($id)
    {
        $t   = $this->target($id);
        $in  = $this->input();
        $act = $this->user();
        $set = [];
        foreach (['name' => 120, 'position' => 120] as $f => $max) {
            if (isset($in[$f])) {
                $v = trim((string) $in[$f]);
                if ($f === 'name' && $v === '') {
                    throw new ApiException('Name cannot be empty.');
                }
                $set[$f] = mb_substr($v, 0, $max);
            }
        }
        if (isset($in['role']) && $in['role'] !== $t['role']) {
            if ((int) $t['id'] === (int) $act['id']) {
                throw new ApiException('You cannot change your own role.', 403);
            }
            if (! in_array($in['role'], Rbac::assignableRoles($act['role']), true)) {
                throw new ApiException('You cannot assign that role.', 403);
            }
            $this->guardLastSuper($t);
            $set['role'] = $in['role'];
        }
        if (array_key_exists('phone', $in)) {
            $set['phone'] = $in['phone'] === '' ? '' : (SmsQueue::phone($in['phone']) ?? throw new ApiException('Enter a Philippine mobile number.'));
        }
        if (isset($in['email'])) {
            $e = strtolower(trim((string) $in['email']));
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException('Enter a valid email address.');
            }
            if (Db::one('SELECT 1 FROM users WHERE lower(email) = ? AND id <> ?', [$e, $t['id']])) {
                throw new ApiException('That email is already used.', 409);
            }
            $set['email'] = $e;
        }
        foreach (['department_id', 'personnel_id'] as $f) {
            if (array_key_exists($f, $in)) {
                $set[$f] = $in[$f] === '' || $in[$f] === null ? null : (int) $in[$f];
            }
        }
        Db::update('users', $t['id'], $set);
        Activity::log($act, 'user', 'Account updated: ' . ($set['name'] ?? $t['name']) . '.');

        return $this->row((int) $t['id']);
    }

    private function guardLastSuper(array $t): void
    {
        if ($t['role'] === 'superadmin' && $t['status'] === 'active'
            && (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active'") <= 1) {
            throw new ApiException('There must always be at least one active super administrator.', 409);
        }
    }

    private function setStatus($id, string $status, string $verb, array $from)
    {
        $t = $this->target($id);
        if (! in_array($t['status'], $from, true)) {
            throw new ApiException("That account cannot be $verb right now.");
        }
        if ($status === 'inactive') {
            if ((int) $t['id'] === (int) $this->user()['id']) {
                throw new ApiException('You cannot deactivate your own account.', 403);
            }
            $this->guardLastSuper($t);
        }
        Db::update('users', $t['id'], ['status' => $status]);
        Activity::log($this->user(), 'user', "Account $verb: {$t['name']}.");

        return $this->row((int) $t['id']);
    }

    /** POST users/{id}/approve  {role?, personnel_id?}  — activates a pending registration, optionally setting role and linking a personnel record. */
    public function approve($id)
    {
        $in = $this->input();
        $t  = $this->target($id);
        $set = [];
        if (! empty($in['role'])) {
            if (! in_array($in['role'], Rbac::assignableRoles($this->user()['role']), true)) {
                throw new ApiException('You cannot assign that role.', 403);
            }
            $set['role'] = $in['role'];
        }
        if (! empty($in['personnel_id'])) {
            $set['personnel_id'] = (int) $in['personnel_id'];
        }
        if ($set) {
            Db::update('users', $t['id'], $set);
        }

        return $this->setStatus($id, 'active', 'approved', ['pending']);
    }
    public function reject($id)     { return $this->setStatus($id, 'inactive', 'rejected', ['pending']); }
    public function deactivate($id) { return $this->setStatus($id, 'inactive', 'deactivated', ['active']); }
    public function activate($id)   { return $this->setStatus($id, 'active', 'activated', ['inactive']); }

    /** Sets a temporary password (shown once to the admin) and forces a change at next sign-in. */
    public function resetPassword($id)
    {
        $t   = $this->target($id);
        $tmp = substr(strtr(base64_encode(random_bytes(9)), '+/=', 'xyz'), 0, 10) . random_int(10, 99);
        Db::update('users', $t['id'], ['password_hash' => Auth::hash($tmp), 'must_change_password' => 1]);
        Activity::log($this->user(), 'user', "Password reset for {$t['name']}.");

        return ['temporary_password' => $tmp];
    }

    public function delete($id)
    {
        $t = $this->target($id);
        if ((int) $t['id'] === (int) $this->user()['id']) {
            throw new ApiException('You cannot delete your own account.', 403);
        }
        $this->guardLastSuper($t);
        Db::delete('users', (int) $t['id']);
        Activity::log($this->user(), 'user', "Account deleted: {$t['name']}.");

        return ['deleted' => true];
    }
}
