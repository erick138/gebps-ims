<?php

namespace App\Libraries;

/** Role based access control. Mirrors the permission table used by the web client. */
class Rbac
{
    public const ROLES = ['superadmin', 'admin', 'custodian', 'maintenance', 'staff'];

    public const LABELS = [
        'superadmin'  => 'Super Administrator',
        'admin'       => 'Administrator',
        'custodian'   => 'Property Custodian',
        'maintenance' => 'Maintenance Staff',
        'staff'       => 'Teacher / Staff',
    ];

    private const PERMS = [
        'equipment'   => ['superadmin', 'admin', 'custodian'],
        'personnel'   => ['superadmin', 'admin', 'custodian'],
        'locations'   => ['superadmin', 'admin', 'custodian'],
        'issuance'    => ['superadmin', 'admin', 'custodian'],
        'maintenance' => ['superadmin', 'admin', 'custodian', 'maintenance'],
        'approve'     => ['superadmin', 'admin', 'custodian'],
        'users'       => ['superadmin', 'admin'],
        'reports'     => ['superadmin', 'admin', 'custodian', 'maintenance'],
        'tags'        => ['superadmin', 'admin', 'custodian', 'maintenance'],
        'viewAll'     => ['superadmin', 'admin', 'custodian', 'maintenance'],
        'data'        => ['superadmin'],
        'super'       => ['superadmin'],
    ];

    public static function can(?string $role, string $perm): bool
    {
        return $role !== null && in_array($role, self::PERMS[$perm] ?? [], true);
    }

    public static function isAdminRole(string $role): bool
    {
        return in_array($role, ['admin', 'superadmin'], true);
    }

    /** Roles the acting user may hand out. Only a super administrator may create administrators. */
    public static function assignableRoles(string $actorRole): array
    {
        return $actorRole === 'superadmin'
            ? self::ROLES
            : array_values(array_filter(self::ROLES, fn ($r) => ! self::isAdminRole($r)));
    }

    /** Administrators may not manage other administrators; super administrators may manage anyone. */
    public static function canManage(array $actor, array $target): bool
    {
        return (int) $actor['id'] === (int) $target['id']
            || $actor['role'] === 'superadmin'
            || ! self::isAdminRole($target['role']);
    }
}
