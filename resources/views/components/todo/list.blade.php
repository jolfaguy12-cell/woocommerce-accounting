@props([
    'title' => 'کارها',
    'desc' => null,
    'items' => [],          // see the contract below
    'interactive' => true,  // false → read-only (dashboard widget); true → checkboxes toggle
    'showProgress' => true,
    'emptyMessage' => 'کاری ثبت نشده است',
    'moreUrl' => null,
])

{{--
    Task list / checklist / reminder / upcoming / overdue — all the same shape,
    so one parameterised component (CLAUDE.md: no duplicate implementations).

    ITEM CONTRACT (design it once, attach a backend later without redesigning UI):
      [
        'id'       => int|string,
        'title'    => string,
        'done'     => bool,
        'priority' => 'high'|'medium'|'low'|null,
        'due'      => ?string,   // ALREADY-FORMATTED Jalali date — no date logic in views
        'overdue'  => bool,
        'status'   => ?string,   // resolved through StatusPresenter
        'assignee' => ?string,
        'url'      => ?string,
      ]

    There is NO task backend in this phase. `interactive` toggles completion in
    Alpine only (local, optimistic). To persist later, wrap each item in a POST
    form or point the checkbox at a route — the markup contract does not change.
--}}
@php
    $total = count($items);
    $done = count(array_filter($items, fn ($i) => (bool) ($i['done'] ?? false)));
    $pct = $total > 0 ? ($done / $total) * 100 : 0;

    $priorityMeta = [
        'high' => ['label' => 'فوری', 'class' => 'text-loss', 'dot' => 'bg-loss'],
        'medium' => ['label' => 'متوسط', 'class' => 'text-expense', 'dot' => 'bg-expense'],
        'low' => ['label' => 'کم', 'class' => 'text-gray-400', 'dot' => 'bg-gray-300 dark:bg-gray-600'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}
    x-data="{ items: {{ Illuminate\Support\Js::from(array_map(fn ($i) => ['id' => $i['id'] ?? null, 'done' => (bool) ($i['done'] ?? false)], $items)) }},
              toggle(id) {
                  const it = this.items.find(i => i.id === id);
                  if (it) it.done = !it.done;
              },
              get doneCount() { return this.items.filter(i => i.done).length; },
              get pct() { return this.items.length ? (this.doneCount / this.items.length) * 100 : 0; } }">

    <div class="flex items-start justify-between gap-3 px-5 py-4">
        <div>
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @if ($desc)
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $desc }}</p>
            @endif
        </div>
        @if ($moreUrl)
            <a href="{{ $moreUrl }}" class="shrink-0 text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">مشاهده همه</a>
        @endif
    </div>

    @if ($showProgress && $total > 0)
        <div class="px-5 pb-4">
            <div class="mb-1.5 flex items-center justify-between text-theme-xs text-gray-500 dark:text-gray-400">
                <span>پیشرفت</span>
                <span dir="ltr">
                    <span x-text="doneCount">{{ $done }}</span>/{{ $total }}
                </span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-badge bg-gray-100 dark:bg-white/10">
                <div class="h-full rounded-badge bg-brand-500 transition-all"
                    :style="`width: ${pct}%`" style="width: {{ $pct }}%"></div>
            </div>
        </div>
    @endif

    @if (empty($items))
        <div class="border-t border-gray-100 dark:border-gray-800">
            <x-states.state variant="empty" :message="$emptyMessage" />
        </div>
    @else
        <ul class="border-t border-gray-100 dark:border-gray-800">
            @foreach ($items as $item)
                @php
                    $id = $item['id'] ?? $loop->index;
                    $p = $priorityMeta[$item['priority'] ?? ''] ?? null;
                    $overdue = (bool) ($item['overdue'] ?? false);
                @endphp
                <li class="flex items-start gap-3 border-b border-gray-50 px-5 py-3 last:border-0 dark:border-gray-800/60">
                    <input type="checkbox"
                        @if ($interactive) @change="toggle({{ Illuminate\Support\Js::from($id) }})" @else disabled @endif
                        @checked($item['done'] ?? false)
                        aria-label="{{ $item['title'] }}"
                        class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand-500 focus:ring-brand-500/20 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900">

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-theme-sm text-gray-800 dark:text-white/90"
                                :class="items.find(i => i.id === {{ Illuminate\Support\Js::from($id) }})?.done ? 'line-through text-gray-400 dark:text-gray-500' : ''"
                                @class(['line-through text-gray-400 dark:text-gray-500' => $item['done'] ?? false])>
                                {{ $item['title'] }}
                            </span>

                            @isset($item['status'])
                                <x-ui.status :status="$item['status']" />
                            @endisset
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-caption">
                            @if ($p)
                                {{-- Priority: dot + text, never colour alone. --}}
                                <span class="inline-flex items-center gap-1 {{ $p['class'] }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $p['dot'] }}" aria-hidden="true"></span>
                                    {{ $p['label'] }}
                                </span>
                            @endif

                            @if ($item['due'] ?? null)
                                <span class="{{ $overdue ? 'font-medium text-loss' : 'text-gray-400' }}">
                                    {{ $overdue ? 'سررسید گذشته: ' : 'سررسید: ' }}{{ $item['due'] }}
                                </span>
                            @endif

                            @if ($item['assignee'] ?? null)
                                <span class="text-gray-400">{{ $item['assignee'] }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($item['url'] ?? null)
                        <a href="{{ $item['url'] }}" class="shrink-0 text-theme-xs text-brand-500 hover:text-brand-600 dark:text-brand-400">باز کردن</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
