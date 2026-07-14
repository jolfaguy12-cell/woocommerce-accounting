@php
    // Included with: @include('pages.users.partials.party-link', ['formKey' => …, 'user' => …])
    // $businessRoles, $inputClass and $labelClass come from the parent view's scope.
    $user = $user ?? null;

    $isOld = old('_form') === $formKey;

    $currentMode = $isOld
        ? old('party_mode', 'none')
        : (($user['party_id'] ?? null) ? 'existing' : 'none');

    $currentPartyId = $isOld ? old('party_id') : ($user['party_id'] ?? null);
    $currentPartyName = $isOld ? null : ($user['party_name'] ?? null);

    $currentRoles = $isOld
        ? (array) old('business_roles', [])
        : collect($user['business_roles'] ?? [])->all();

    // The stored labels come back as Persian names; match on those.
    $activeRoleValues = collect($businessRoles)
        ->filter(fn ($label) => in_array($label, $currentRoles, true))
        ->keys()
        ->all();

    $checkedRoles = $isOld ? $currentRoles : $activeRoleValues;
@endphp

{{--
    «نقش‌های تجاری» — the half of this form that is NOT about logging in.

    A User is an access record; a Party is a business identity. This block links
    them, and it is the only place that does. Two rules it exists to enforce:

      · A business role cannot exist without a Party. Ticking «کارمند» activates
        the role AND creates the employee profile the salary attaches to — a role
        with no party behind it would be a label with no ledger under it.

      · A new Party is never created silently. This form is the easiest place in
        the system to make a second copy of somebody who already exists, so the
        server refuses on a matching phone, email or national id and names who it
        found. Search the existing parties first — that is what «طرف حساب موجود»
        is for.
--}}
<div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-800"
     x-data="{ mode: '{{ $currentMode }}' }">

    <div>
        <p class="text-sm font-medium text-gray-800 dark:text-white/90">نقش‌های تجاری</p>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
            جدا از «سطح دسترسی سیستم». این بخش تعیین می‌کند این شخص در دفاتر مالی چه کسی است، نه اینکه در نرم‌افزار چه اجازه‌ای دارد.
        </p>
    </div>

    <div class="flex flex-wrap gap-4">
        @foreach (['none' => 'بدون طرف حساب', 'existing' => 'طرف حساب موجود', 'new' => 'طرف حساب جدید'] as $value => $label)
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="radio" name="party_mode" value="{{ $value }}" x-model="mode" required
                    class="h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700">
                {{ $label }}
            </label>
        @endforeach
    </div>
    @if ($isOld && $errors->has('party_mode'))<p class="text-xs text-error-500">{{ $errors->first('party_mode') }}</p>@endif

    <div x-show="mode === 'existing'" x-cloak>
        <x-form.party-select name="party_id" label="طرف حساب"
            :value="$currentPartyId" :selected-name="$currentPartyName"
            help="در میان همه طرف حساب‌ها جستجو کنید تا هویت تکراری ساخته نشود." />
    </div>

    <div x-show="mode === 'new'" x-cloak class="space-y-3">
        <div>
            <label class="{{ $labelClass }}">نام طرف حساب</label>
            <input type="text" name="party_name" value="{{ $isOld ? old('party_name') : '' }}" class="{{ $inputClass }}">
            @if ($isOld && $errors->has('party_name'))<p class="mt-1 text-xs text-error-500">{{ $errors->first('party_name') }}</p>@endif
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}">شماره تماس (اختیاری)</label>
                <input type="text" name="party_phone" dir="ltr" value="{{ $isOld ? old('party_phone') : '' }}" class="{{ $inputClass }} text-left">
            </div>
            <div>
                <label class="{{ $labelClass }}">کد ملی (اختیاری)</label>
                <input type="text" name="party_national_id" dir="ltr" value="{{ $isOld ? old('party_national_id') : '' }}" class="{{ $inputClass }} text-left">
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            اگر طرف حسابی با همین شماره، ایمیل یا کد ملی وجود داشته باشد، ساخت مورد جدید متوقف می‌شود و همان پرونده معرفی می‌شود.
        </p>
    </div>

    <div x-show="mode !== 'none'" x-cloak>
        <label class="{{ $labelClass }}">نقش تجاری</label>
        <div class="flex flex-wrap gap-4">
            @foreach ($businessRoles as $value => $label)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" name="business_roles[]" value="{{ $value }}"
                        @checked(in_array($value, $checkedRoles, true))
                        class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700">
                    {{ $label }}
                </label>
            @endforeach
        </div>
        <p class="mt-1 text-xs text-gray-400">
            انتخاب هر نقش، همان نقش و پرونده مالی آن را روی طرف حساب فعال می‌کند. غیرفعال کردن نقش از تب «مدیریت نقش‌ها» انجام می‌شود و هیچ سابقه‌ای را حذف نمی‌کند.
        </p>
    </div>
</div>
