<?php

namespace Database\Seeders;

use App\Models\SecurityTip;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        if (SecurityTip::count() === 0) {
            $tips = [
                ['رمز عبور قوی و یکتا', 'برای هر سامانه رمز عبور جداگانه با حداقل ۱۰ کاراکتر شامل حروف بزرگ و کوچک، عدد و نماد انتخاب کنید و آن را در اختیار هیچ‌کس قرار ندهید.', 'solar:key-minimalistic-square-2-line-duotone'],
                ['مراقب ایمیل‌ها و پیام‌های فیشینگ باشید', 'روی پیوندها و پیوست‌های ناشناس کلیک نکنید و پیش از واردکردن اطلاعات، نشانی سایت را بررسی کنید.', 'solar:letter-unread-line-duotone'],
                ['قفل کردن رایانه', 'هنگام ترک میز کار، صفحه رایانه را با کلیدهای Win + L قفل کنید.', 'solar:lock-keyhole-minimalistic-line-duotone'],
                ['حافظه‌های جانبی', 'از اتصال فلش و هارد ناشناس به رایانه‌های سازمان خودداری کنید و پیش از استفاده آن‌ها را اسکن کنید.', 'solar:usb-line-duotone'],
                ['به‌روزرسانی نرم‌افزارها', 'سیستم‌عامل و ضدویروس را همیشه به‌روز نگه دارید و فقط نرم‌افزارهای مجاز را نصب کنید.', 'solar:refresh-circle-line-duotone'],
                ['حفاظت از اسناد طبقه‌بندی‌شده', 'اسناد طبقه‌بندی‌شده را فقط در محیط‌های مجاز نگهداری کنید و از ارسال آن‌ها در پیام‌رسان‌ها بپرهیزید.', 'solar:document-medicine-line-duotone'],
            ];
            foreach ($tips as $i => [$title, $body, $icon]) {
                SecurityTip::create(['title' => $title, 'body' => $body, 'icon' => $icon, 'sort_order' => $i, 'is_active' => true]);
            }
        }
    }
}
