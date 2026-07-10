@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="هشدارها" />

@if (session('success'))
    <div class="mb-4"><x-ui.alert variant="success" :message="session('success')" /></div>
@endif

<div class="space-y-4" x-data="{ tab: 'rules' }">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        هشدارهای سیستمی که برای نقش‌های انتخاب‌شده ثبت می‌شوند. ارسال از طریق تلگرام هنوز پیاده‌سازی نشده — هشدارها فقط ثبت و ذخیره می‌شوند تا وقتی ربات تلگرام متصل شود.
    </p>

    <div class="flex gap-2 border-b border-gray-200 dark:border-gray-800">
        <button type="button" @click="tab = 'rules'" :class="tab === 'rules' ? 'border-brand-500 text-brand-500' : 'border-transparent text-gray-500 dark:text-gray-400'" class="border-b-2 px-3 pb-2 text-sm font-medium">هشدارها</button>
        <button type="button" @click="tab = 'templates'" :class="tab === 'templates' ? 'border-brand-500 text-brand-500' : 'border-transparent text-gray-500 dark:text-gray-400'" class="border-b-2 px-3 pb-2 text-sm font-medium">الگوی پیام‌ها</button>
    </div>

    <div x-show="tab === 'rules'" x-cloak class="space-y-4">
        @foreach ($alertTypes as $type)
            <x-common.component-card :title="$type->name">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="max-w-xl">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $type->description }}</p>
                        <p class="mt-2 text-xs text-gray-400">کد: <span dir="ltr">{{ $type->code }}</span></p>
                    </div>

                    <form method="POST" action="{{ route('tools.alerts.toggle', $type) }}">
                        @csrf
                        <button type="submit" class="rounded-md border px-3 py-1.5 text-sm {{ $type->is_active ? 'border-success-300 text-success-600' : 'border-gray-300 text-gray-500' }}">
                            {{ $type->is_active ? 'فعال' : 'غیرفعال' }}
                        </button>
                    </form>
                </div>

                <form method="POST" action="{{ route('tools.alerts.roles', $type) }}" class="mt-4">
                    @csrf
                    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">ارسال به نقش‌ها:</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($roles as $role)
                            <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="roles[]" value="{{ $role }}" @checked(in_array($role, $type->roles, true))>
                                {{ $role }}
                            </label>
                        @endforeach
                    </div>
                    <button type="submit" class="mt-3 rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white hover:bg-brand-600">ذخیره نقش‌ها</button>
                </form>
            </x-common.component-card>
        @endforeach

        <x-common.component-card title="آخرین رخدادها">
            @if ($recentEvents->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">هنوز هشداری ثبت نشده است.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-right text-gray-500 dark:border-gray-800 dark:text-gray-400">
                                <th class="py-2 font-normal">هشدار</th>
                                <th class="py-2 font-normal">پیام</th>
                                <th class="py-2 font-normal">وضعیت</th>
                                <th class="py-2 font-normal">زمان</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentEvents as $event)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                    <td class="py-2 text-gray-800 dark:text-white/90">{{ $event->alertType->name }}</td>
                                    <td class="max-w-md truncate py-2 text-gray-500 dark:text-gray-400" title="{{ $event->rendered_message }}">{{ $event->rendered_message ?: '—' }}</td>
                                    <td class="py-2"><x-ui.badge size="sm" :color="$event->status === 'dispatched' ? 'success' : 'light'">{{ $event->status }}</x-ui.badge></td>
                                    <td class="py-2 text-gray-500 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($event->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-common.component-card>
    </div>

    <div x-show="tab === 'templates'" x-cloak class="space-y-4">
        @foreach ($alertTypes as $type)
            <x-common.component-card :title="$type->name">
                <form method="POST" action="{{ route('tools.alerts.template', $type) }}">
                    @csrf
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">متن پیام</label>
                    <textarea name="message_template" rows="3" required
                        class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">{{ $type->message_template }}</textarea>

                    @if ($type->placeholders())
                        <p class="mt-2 text-xs text-gray-400">
                            جای‌گزین‌های قابل استفاده:
                            @foreach ($type->placeholders() as $ph)
                                <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-white/10" dir="ltr">{{'{'.$ph.'}'}}</code>
                            @endforeach
                        </p>
                    @endif

                    <button type="submit" class="mt-3 rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white hover:bg-brand-600">ذخیره الگو</button>
                </form>
            </x-common.component-card>
        @endforeach
    </div>

    <x-common.capability-list :available="[
        'فعال/غیرفعال کردن هر هشدار و انتخاب نقش‌های دریافت‌کننده',
        'شخصی‌سازی متن پیام هر هشدار',
        'ثبت هر رخداد هشدار و وضعیت تحویل آن به‌ازای هر کاربر (قابل مشاهده و حسابرسی)',
    ]" :future="[]" :missing="[
        'ارسال واقعی پیام در تلگرام هنوز پیاده‌سازی نشده — کاربران باید آیدی تلگرام خود را در تنظیمات پروفایل وارد کنند، اما فعلاً هیچ پیامی عملاً ارسال نمی‌شود؛ TelegramNotifier آماده اتصال است.',
    ]" />
</div>
@endsection
