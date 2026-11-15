<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: safe to run again after upgrades (adds missing roles/permissions, never removes custom ones). */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        // name => [display_name, group]
        'users.view' => ['مشاهده کاربران', 'کاربران'],
        'users.create' => ['افزودن کاربر', 'کاربران'],
        'users.update' => ['ویرایش کاربر / فعال‌سازی / رفع قفل', 'کاربران'],
        'users.delete' => ['حذف کاربر', 'کاربران'],
        'users.reset_password' => ['بازنشانی رمز عبور کاربر', 'کاربران'],
        'users.assign_roles' => ['تخصیص نقش به کاربر', 'کاربران'],
        'users.import' => ['ورود گروهی کاربران از فایل', 'کاربران'],
        'roles.view' => ['مشاهده نقش‌ها و دسترسی‌ها', 'نقش‌ها و دسترسی‌ها'],
        'roles.manage' => ['مدیریت نقش‌ها و تخصیص دسترسی', 'نقش‌ها و دسترسی‌ها'],
        'permissions.manage' => ['تعریف دسترسی (اقدام) جدید', 'نقش‌ها و دسترسی‌ها'],
        'reports.review' => ['بررسی و تأیید/رد گزارش‌های مردمی', 'گزارش‌ها'],
        'reports.view_approved' => ['مشاهده گزارش‌های تأییدشده', 'گزارش‌ها'],
        'reports.view_all' => ['مشاهده همه گزارش‌ها (فقط خواندنی)', 'گزارش‌ها'],
        'audit.view' => ['مشاهده گزارش رویدادها (Audit Log)', 'سیستم'],
        'content.manage' => ['مدیریت محتوای سایت (اسلایدها و نکات امنیتی)', 'محتوا'],
    ];

    public const ROLES = [
        // name => [display_name (fa), title_en, permissions]
        'admin' => ['مدیر سیستم', 'Administrator', '*'],
        'user' => ['کاربر', 'User', []],
        'security_affairs_manager' => ['مدیر امور حراست', 'Security Affairs Manager', ['reports.view_all']],
        'head_personnel_security' => ['رئیس حفاظت پرسنلی و سلامت اقتصادی', 'Head of Personnel Security and Economic Integrity', ['reports.review']],
        'head_physical_security' => ['رئیس حفاظت فیزیکی و پدافند غیرعامل', 'Head of Physical Security and Passive Defense', []],
        'head_electronic_security' => ['رئیس حفاظت الکترونیک و پایش', 'Head of Electronic Security and Monitoring', []],
        'head_document_it_security' => ['رئیس حفاظت اسناد و فناوری اطلاعات', 'Head of Document and IT Security', []],
        'head_explosives_security' => ['رئیس حفاظت مواد ناریه و انفجاری', 'Head of Security for Explosives/Blasting Materials', []],
        'ict_affairs_manager' => ['مدیر امور فناوری اطلاعات و ارتباطات', 'ICT Affairs Manager', []],
        'head_communications' => ['رئیس ارتباطات', 'Head of Communications', []],
        'head_it_support' => ['رئیس پشتیبانی فناوری اطلاعات', 'Head of IT Support', []],
        'senior_security_specialist' => ['کارشناس ارشد حراست', 'Senior Security Specialist', []],
        'personnel_security_supervisor' => ['سرپرست حفاظت پرسنلی', 'Personnel Security Supervisor', ['reports.view_approved']],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => [$display, $group]) {
            $p = Permission::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $p->display_name = $display;
            $p->group = $group;
            $p->is_system = true;
            $p->save();
        }

        foreach (self::ROLES as $name => [$display, $titleEn, $perms]) {
            $role = Role::firstOrNew(['name' => $name, 'guard_name' => 'web']);
            $isNew = ! $role->exists;
            $role->display_name = $display;
            $role->title_en = $titleEn;
            $role->is_system = true;
            $role->save();
            if ($perms === '*') {
                $role->syncPermissions(Permission::all());
            } elseif ($isNew) {
                // only set defaults on first creation so admin changes are preserved on re-seed
                $role->syncPermissions($perms);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
