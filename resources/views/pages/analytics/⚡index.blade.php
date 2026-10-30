<?php

use App\Enums\Division;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Analytics')] class extends Component {
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        abort_unless(Auth::user()?->isIT(), 403, 'Unauthorized. Only IT staff can access the analytics dashboard.');

        if (empty($this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        try {
            $date = Carbon::createFromFormat('Y-m', $this->month)->subMonth();
            $this->month = $date->format('Y-m');
        } catch (\Throwable) {
            $this->month = now()->subMonth()->format('Y-m');
        }
    }

    public function nextMonth(): void
    {
        try {
            $date = Carbon::createFromFormat('Y-m', $this->month)->addMonth();
            $this->month = $date->format('Y-m');
        } catch (\Throwable) {
            $this->month = now()->addMonth()->format('Y-m');
        }
    }

    public function resetToCurrentMonth(): void
    {
        $this->month = now()->format('Y-m');
    }

    /**
     * Helper to format minutes into human-readable duration (mins, hrs, days).
     */
    public function formatDuration(?float $minutes): string
    {
        if ($minutes === null) {
            return __('N/A');
        }

        if ($minutes < 1) {
            return '< 1 ' . __('min');
        }

        if ($minutes < 60) {
            return round($minutes) . ' ' . __('mins');
        }

        $hours = round($minutes / 60, 1);
        if ($hours < 24) {
            return $hours . ' ' . __('hrs');
        }

        $days = round($hours / 24, 1);
        return $days . ' ' . __('days');
    }

    /**
     * Carbon start and end dates for the selected month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dateRange(): array
    {
        try {
            $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        } catch (\Throwable) {
            $start = now()->startOfMonth();
        }

        $end = (clone $start)->endOfMonth();

        return [$start, $end];
    }

    /**
     * 12 selectable months for the dropdown filter.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function availableMonths(): array
    {
        $months = [];
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $date = (clone $cursor)->subMonths($i);
            $months[$date->format('Y-m')] = $date->format('F Y');
        }

        return $months;
    }

    /**
     * Label of the selected month.
     */
    #[Computed]
    public function currentMonthLabel(): string
    {
        [$start] = $this->dateRange();
        return $start->format('F Y');
    }

    /**
     * Formatted date range string (e.g. October 1 – October 31, 2026).
     */
    #[Computed]
    public function formattedDateRange(): string
    {
        [$start, $end] = $this->dateRange();
        return $start->format('M d') . ' – ' . $end->format('M d, Y');
    }

    /**
     * Monthly key performance indicators including FRT and Reopen metrics.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function monthlyStats(): array
    {
        [$start, $end] = $this->dateRange();

        $prevStart = (clone $start)->subMonth()->startOfMonth();
        $prevEnd = (clone $prevStart)->endOfMonth();

        // 1. Submitted tickets
        $submitted = Ticket::whereBetween('created_at', [$start, $end])->count();
        $prevSubmitted = Ticket::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        // 2. Resolved tickets
        $resolved = Ticket::whereBetween('resolved_at', [$start, $end])->count();
        $prevResolved = Ticket::whereBetween('resolved_at', [$prevStart, $prevEnd])->count();

        // 3. Closed tickets
        $closed = Ticket::whereBetween('closed_at', [$start, $end])->count();
        $prevClosed = Ticket::whereBetween('closed_at', [$prevStart, $prevEnd])->count();

        // 4. Completed (resolved + closed)
        $completed = $resolved + $closed;
        $prevCompleted = $prevResolved + $prevClosed;

        // Resolution rate %
        $resolutionRate = $submitted > 0 ? round(($completed / $submitted) * 100, 1) : 0;

        // 5. Average Resolution Time (MTTR)
        $resolvedTickets = Ticket::whereBetween('resolved_at', [$start, $end])
            ->whereNotNull('created_at')
            ->select(['created_at', 'resolved_at'])
            ->get();

        $avgResolutionMinutes = null;
        if ($resolvedTickets->isNotEmpty()) {
            $avgResolutionMinutes = (float) $resolvedTickets->avg(function (Ticket $t): float {
                return (float) $t->created_at->diffInMinutes($t->resolved_at);
            });
        }

        // 6. First Response Time (FRT)
        $respondedTickets = Ticket::whereBetween('created_at', [$start, $end])
            ->whereNotNull('first_responded_at')
            ->select(['created_at', 'first_responded_at'])
            ->get();

        $respondedCount = $respondedTickets->count();
        $responseCoverageRate = $submitted > 0 ? round(($respondedCount / $submitted) * 100, 1) : 0;

        $avgFirstResponseMinutes = null;
        if ($respondedTickets->isNotEmpty()) {
            $avgFirstResponseMinutes = (float) $respondedTickets->avg(function (Ticket $t): float {
                return (float) $t->created_at->diffInMinutes($t->first_responded_at);
            });
        }

        // Previous month FRT for trend comparison
        $prevRespondedTickets = Ticket::whereBetween('created_at', [$prevStart, $prevEnd])
            ->whereNotNull('first_responded_at')
            ->select(['created_at', 'first_responded_at'])
            ->get();
        $prevAvgFRT = $prevRespondedTickets->isNotEmpty() ? (float) $prevRespondedTickets->avg(fn(Ticket $t) => (float) $t->created_at->diffInMinutes($t->first_responded_at)) : null;

        // 7. Reopened Tickets Rate
        // Tickets created in month that were reopened, or had a reopen event recorded in this month
        $reopenedTicketsCount = Ticket::where(function (Builder $query) use ($start, $end): void {
            $query->whereBetween('reopened_at', [$start, $end])->orWhere(function (Builder $sub) use ($start, $end): void {
                $sub->whereBetween('created_at', [$start, $end])->where('reopened_count', '>', 0);
            });
        })->count();

        $totalReopenEvents = (int) Ticket::whereBetween('created_at', [$start, $end])->sum('reopened_count');

        // Industry standard: Reopened count relative to completed (resolved + closed) or submitted
        $reopenBase = $completed > 0 ? $completed : $submitted;
        $reopenRate = $reopenBase > 0 ? round(($reopenedTicketsCount / $reopenBase) * 100, 1) : 0.0;

        // Percentage comparison helper
        $calcDelta = function (int $current, int $prev): ?float {
            if ($prev === 0) {
                return $current > 0 ? 100.0 : 0.0;
            }
            return round((($current - $prev) / $prev) * 100, 1);
        };

        return [
            'submitted' => $submitted,
            'submitted_delta' => $calcDelta($submitted, $prevSubmitted),
            'resolved' => $resolved,
            'resolved_delta' => $calcDelta($resolved, $prevResolved),
            'closed' => $closed,
            'closed_delta' => $calcDelta($closed, $prevClosed),
            'completed' => $completed,
            'completed_delta' => $calcDelta($completed, $prevCompleted),
            'resolution_rate' => $resolutionRate,
            'avg_resolution_minutes' => $avgResolutionMinutes,
            'avg_first_response_minutes' => $avgFirstResponseMinutes,
            'responded_count' => $respondedCount,
            'response_coverage_rate' => $responseCoverageRate,
            'reopened_count' => $reopenedTicketsCount,
            'total_reopen_events' => $totalReopenEvents,
            'reopen_rate' => $reopenRate,
            'currently_open' => Ticket::whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])->count(),
        ];
    }

    /**
     * Breakdown of tickets assigned to each IT team member for the month.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function itMemberWorkload(): Collection
    {
        [$start, $end] = $this->dateRange();

        $itStaff = User::where('role', UserRole::ITStaff)->orderBy('name')->get();
        $totalSubmittedInMonth = Ticket::whereBetween('created_at', [$start, $end])->count();

        return $itStaff->map(function (User $member) use ($start, $end, $totalSubmittedInMonth): array {
            $assignedCount = Ticket::where('assigned_to_id', $member->id)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $resolvedCount = Ticket::where('assigned_to_id', $member->id)
                ->whereBetween('resolved_at', [$start, $end])
                ->count();

            $closedCount = Ticket::where('assigned_to_id', $member->id)
                ->whereBetween('closed_at', [$start, $end])
                ->count();

            $activeCount = Ticket::where('assigned_to_id', $member->id)
                ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
                ->count();

            // First Response Time for tickets assigned to this staff member
            $memberResponded = Ticket::where('assigned_to_id', $member->id)
                ->whereBetween('created_at', [$start, $end])
                ->whereNotNull('first_responded_at')
                ->get(['created_at', 'first_responded_at']);

            $memberAvgFrtMinutes = $memberResponded->isNotEmpty() ? (float) $memberResponded->avg(fn(Ticket $t) => (float) $t->created_at->diffInMinutes($t->first_responded_at)) : null;

            // Reopened count for tickets assigned to this staff member
            $memberReopenedCount = Ticket::where('assigned_to_id', $member->id)
                ->whereBetween('created_at', [$start, $end])
                ->where('reopened_count', '>', 0)
                ->count();

            $sharePercent = $totalSubmittedInMonth > 0 ? round(($assignedCount / $totalSubmittedInMonth) * 100, 1) : 0;

            $resolutionPercent = $assignedCount > 0 ? round((($resolvedCount + $closedCount) / $assignedCount) * 100, 1) : 0;

            return [
                'user' => $member,
                'assigned' => $assignedCount,
                'resolved' => $resolvedCount,
                'closed' => $closedCount,
                'active' => $activeCount,
                'avg_frt_minutes' => $memberAvgFrtMinutes,
                'reopened_count' => $memberReopenedCount,
                'share' => $sharePercent,
                'resolution_rate' => $resolutionPercent,
            ];
        });
    }

    /**
     * Unassigned ticket metrics.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function unassignedStats(): array
    {
        [$start, $end] = $this->dateRange();

        return [
            'month_unassigned' => Ticket::whereNull('assigned_to_id')
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'current_unassigned_active' => Ticket::whereNull('assigned_to_id')
                ->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress])
                ->count(),
        ];
    }

    /**
     * Breakdown of tickets by category for the selected month.
     *
     * @return array<string, array{label: string, count: int, percentage: float}>
     */
    #[Computed]
    public function categoryBreakdown(): array
    {
        [$start, $end] = $this->dateRange();
        $total = Ticket::whereBetween('created_at', [$start, $end])->count();

        $data = [];
        foreach (TicketCategory::cases() as $category) {
            $count = Ticket::where('category', $category)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $data[$category->value] = [
                'label' => $category->label(),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
            ];
        }

        return $data;
    }

    /**
     * Breakdown of tickets by priority for the selected month.
     *
     * @return array<string, array{label: string, count: int, percentage: float, color: string, icon: string}>
     */
    #[Computed]
    public function priorityBreakdown(): array
    {
        [$start, $end] = $this->dateRange();
        $total = Ticket::whereBetween('created_at', [$start, $end])->count();

        $data = [];
        foreach (TicketPriority::cases() as $priority) {
            $count = Ticket::where('priority', $priority)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $data[$priority->value] = [
                'label' => $priority->label(),
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                'color' => $priority->color(),
                'icon' => $priority->icon(),
            ];
        }

        return $data;
    }

    /**
     * Breakdown of tickets by division for the selected month.
     *
     * @return array<string, array{label: string, count: int, percentage: float}>
     */
    #[Computed]
    public function divisionBreakdown(): array
    {
        [$start, $end] = $this->dateRange();
        $total = Ticket::whereBetween('created_at', [$start, $end])->count();

        $data = [];
        foreach (Division::cases() as $division) {
            $count = Ticket::where('division', $division)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            if ($count > 0 || $total === 0) {
                $data[$division->value] = [
                    'label' => $division->label(),
                    'count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                ];
            }
        }

        uasort($data, fn($a, $b) => $b['count'] <=> $a['count']);

        return $data;
    }

    /**
     * Daily timeline of submitted vs resolved tickets across the month.
     *
     * @return array<int, array{day: int, label: string, submitted: int, resolved: int}>
     */
    #[Computed]
    public function dailyActivity(): array
    {
        [$start, $end] = $this->dateRange();
        $daysInMonth = $start->daysInMonth;

        $submittedByDay = Ticket::whereBetween('created_at', [$start, $end])
            ->get(['id', 'created_at'])
            ->groupBy(fn(Ticket $t) => (int) $t->created_at->format('j'))
            ->map(fn($group) => $group->count());

        $resolvedByDay = Ticket::whereBetween('resolved_at', [$start, $end])
            ->get(['id', 'resolved_at'])
            ->groupBy(fn(Ticket $t) => (int) $t->resolved_at->format('j'))
            ->map(fn($group) => $group->count());

        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = (clone $start)->day($d);
            $days[] = [
                'day' => $d,
                'label' => $date->format('M j'),
                'submitted' => (int) ($submittedByDay->get($d) ?? 0),
                'resolved' => (int) ($resolvedByDay->get($d) ?? 0),
            ];
        }

        return $days;
    }
}; ?>

<div class="flex flex-col gap-6 w-full">
    <!-- Header with Month Selector -->
    <div class="flex w-full flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <flux:heading size="xl">{{ __('IT Helpdesk Analytics') }}</flux:heading>
            <flux:subheading>
                {{ __('Monthly performance metrics, response speed, resolution quality, and staff workload.') }}
            </flux:subheading>
        </div>

        <!-- Date Range Controls -->
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <div
                class="flex items-center gap-1 rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:button wire:click="previousMonth" variant="ghost" size="sm" icon="chevron-left"
                    aria-label="{{ __('Previous Month') }}" />

                <div class="w-40 sm:w-44">
                    <flux:select wire:model.live="month" class="text-sm">
                        @foreach ($this->availableMonths as $val => $lbl)
                            <flux:select.option value="{{ $val }}">{{ $lbl }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <flux:button wire:click="nextMonth" variant="ghost" size="sm" icon="chevron-right"
                    aria-label="{{ __('Next Month') }}" />
            </div>

            @if ($month !== now()->format('Y-m'))
                <flux:button wire:click="resetToCurrentMonth" variant="subtle" size="sm" icon="calendar">
                    {{ __('Current Month') }}
                </flux:button>
            @endif
        </div>
    </div>

    <!-- Active Period Badge Bar -->
    <div
        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50/70 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/50">
        <div class="flex items-center gap-2">
            <flux:icon name="calendar-days" class="size-4 text-zinc-500" />
            <span
                class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Reporting Period') }}:</span>
            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $this->currentMonthLabel }}</span>
            <span class="text-xs text-zinc-400">({{ $this->formattedDateRange }})</span>
        </div>
        <div class="flex items-center gap-2 text-xs text-zinc-500">
            <span>{{ __('Live IT Queue') }}:</span>
            <flux:badge size="xs" color="sky">{{ $this->monthlyStats['currently_open'] }}
                {{ __('Active Now') }}</flux:badge>
            @if ($this->unassignedStats['current_unassigned_active'] > 0)
                <flux:badge size="xs" color="red">{{ $this->unassignedStats['current_unassigned_active'] }}
                    {{ __('Unassigned') }}</flux:badge>
            @endif
        </div>
    </div>

    <!-- Section 1: Monthly Volume & Completion Overview -->
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="base" class="text-zinc-700 dark:text-zinc-300 font-semibold">
                {{ __('Ticket Volume & Status') }}</flux:heading>
            <span
                class="text-xs text-zinc-400">{{ __('Overview for :month', ['month' => $this->currentMonthLabel]) }}</span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Submitted in Month -->
            <flux:card class="flex flex-col justify-between p-5">
                <div class="flex items-center justify-between">
                    <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400">
                        {{ __('Tickets Submitted') }}</flux:text>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                        <flux:icon name="inbox-stack" class="size-5" />
                    </div>
                </div>
                <div class="mt-3">
                    <flux:heading size="xl"
                        class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                        {{ $this->monthlyStats['submitted'] }}
                    </flux:heading>
                    <div class="mt-1.5 flex items-center gap-1.5 text-xs text-zinc-500">
                        @php $sDelta = $this->monthlyStats['submitted_delta']; @endphp
                        @if ($sDelta > 0)
                            <span class="font-medium text-amber-600 dark:text-amber-400">+{{ $sDelta }}%</span>
                        @elseif ($sDelta < 0)
                            <span
                                class="font-medium text-emerald-600 dark:text-emerald-400">{{ $sDelta }}%</span>
                        @else
                            <span class="text-zinc-400">0%</span>
                        @endif
                        <span>{{ __('vs prior month') }}</span>
                    </div>
                </div>
            </flux:card>

            <!-- Resolved in Month -->
            <flux:card class="flex flex-col justify-between p-5">
                <div class="flex items-center justify-between">
                    <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400">
                        {{ __('Tickets Resolved') }}</flux:text>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                        <flux:icon name="check-circle" class="size-5" />
                    </div>
                </div>
                <div class="mt-3">
                    <flux:heading size="xl"
                        class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                        {{ $this->monthlyStats['resolved'] }}
                    </flux:heading>
                    <div class="mt-1.5 flex items-center gap-1.5 text-xs text-zinc-500">
                        @php $rDelta = $this->monthlyStats['resolved_delta']; @endphp
                        @if ($rDelta > 0)
                            <span
                                class="font-medium text-emerald-600 dark:text-emerald-400">+{{ $rDelta }}%</span>
                        @elseif ($rDelta < 0)
                            <span class="font-medium text-red-600 dark:text-red-400">{{ $rDelta }}%</span>
                        @else
                            <span class="text-zinc-400">0%</span>
                        @endif
                        <span>{{ __('vs prior month') }}</span>
                    </div>
                </div>
            </flux:card>

            <!-- Closed in Month -->
            <flux:card class="flex flex-col justify-between p-5">
                <div class="flex items-center justify-between">
                    <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400">
                        {{ __('Tickets Closed') }}</flux:text>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <flux:icon name="archive-box" class="size-5" />
                    </div>
                </div>
                <div class="mt-3">
                    <flux:heading size="xl"
                        class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                        {{ $this->monthlyStats['closed'] }}
                    </flux:heading>
                    <div class="mt-1.5 flex items-center gap-1.5 text-xs text-zinc-500">
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                            {{ $this->monthlyStats['completed'] }}
                        </span>
                        <span>{{ __('total finished (resolved + closed)') }}</span>
                    </div>
                </div>
            </flux:card>

            <!-- Resolution Rate -->
            <flux:card class="flex flex-col justify-between p-5">
                <div class="flex items-center justify-between">
                    <flux:text size="sm" class="font-medium text-zinc-500 dark:text-zinc-400">
                        {{ __('Resolution Rate') }}</flux:text>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                        <flux:icon name="bolt" class="size-5" />
                    </div>
                </div>
                <div class="mt-3">
                    <div class="flex items-baseline gap-2">
                        <flux:heading size="xl"
                            class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ $this->monthlyStats['resolution_rate'] }}%
                        </flux:heading>
                    </div>
                    <div class="mt-1.5 flex items-center gap-1.5 text-xs text-zinc-500">
                        <span>{{ $this->monthlyStats['completed'] }} of {{ $this->monthlyStats['submitted'] }}
                            {{ __('tickets finished') }}</span>
                    </div>
                </div>
            </flux:card>
        </div>
    </div>

    <!-- Section 2: Speed & Quality Benchmarks (First Response Time & Reopened Tickets) -->
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <flux:heading size="base" class="text-zinc-700 dark:text-zinc-300 font-semibold">
                {{ __('Service Speed & Quality Benchmarks') }}</flux:heading>
            <span class="text-xs text-zinc-400">{{ __('Responsiveness & resolution durability') }}</span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- Metric 1: First Response Time (FRT) -->
            <flux:card class="flex flex-col justify-between p-5 border-l-4 border-l-sky-500">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text size="sm" class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('First Response Time (FRT)') }}</flux:text>
                        <span
                            class="text-[11px] text-zinc-400">{{ __('Time from submission to first staff message') }}</span>
                    </div>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                        <flux:icon name="chat-bubble-left-right" class="size-5" />
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex items-baseline gap-2.5">
                        <flux:heading size="xl"
                            class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ $this->formatDuration($this->monthlyStats['avg_first_response_minutes']) }}
                        </flux:heading>
                        @if ($this->monthlyStats['avg_first_response_minutes'] !== null)
                            @if ($this->monthlyStats['avg_first_response_minutes'] <= 60)
                                <flux:badge size="xs" color="emerald">{{ __('Fast (< 1h)') }}</flux:badge>
                            @elseif ($this->monthlyStats['avg_first_response_minutes'] <= 240)
                                <flux:badge size="xs" color="sky">{{ __('Standard') }}</flux:badge>
                            @else
                                <flux:badge size="xs" color="amber">{{ __('Delayed (> 4h)') }}</flux:badge>
                            @endif
                        @endif
                    </div>

                    <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
                        <span>
                            <strong>{{ $this->monthlyStats['responded_count'] }}</strong> /
                            {{ $this->monthlyStats['submitted'] }} {{ __('responded') }}
                        </span>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                            {{ $this->monthlyStats['response_coverage_rate'] }}% {{ __('coverage') }}
                        </span>
                    </div>
                </div>
            </flux:card>

            <!-- Metric 2: Average Resolution Turnaround (MTTR) -->
            <flux:card class="flex flex-col justify-between p-5 border-l-4 border-l-emerald-500">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text size="sm" class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Avg Resolution Turnaround') }}</flux:text>
                        <span
                            class="text-[11px] text-zinc-400">{{ __('Mean Time to Resolve (creation to solved)') }}</span>
                    </div>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                        <flux:icon name="clock" class="size-5" />
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex items-baseline gap-2.5">
                        <flux:heading size="xl"
                            class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ $this->formatDuration($this->monthlyStats['avg_resolution_minutes']) }}
                        </flux:heading>
                        @if ($this->monthlyStats['avg_resolution_minutes'] !== null)
                            @if ($this->monthlyStats['avg_resolution_minutes'] <= 1440)
                                <flux:badge size="xs" color="emerald">{{ __('Within 24h') }}</flux:badge>
                            @else
                                <flux:badge size="xs" color="zinc">{{ __('> 24h') }}</flux:badge>
                            @endif
                        @endif
                    </div>

                    <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
                        <span>
                            <strong>{{ $this->monthlyStats['resolved'] }}</strong>
                            {{ __('tickets resolved in period') }}
                        </span>
                        <span class="font-medium text-emerald-600 dark:text-emerald-400">
                            {{ __('Fixed') }}
                        </span>
                    </div>
                </div>
            </flux:card>

            <!-- Metric 3: Reopened Tickets Rate -->
            <flux:card
                class="flex flex-col justify-between p-5 border-l-4 {{ $this->monthlyStats['reopen_rate'] > 10 ? 'border-l-red-500' : ($this->monthlyStats['reopen_rate'] > 5 ? 'border-l-amber-500' : 'border-l-indigo-500') }}">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text size="sm" class="font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Reopened Tickets Rate') }}</flux:text>
                        <span
                            class="text-[11px] text-zinc-400">{{ __('Tickets reopened after resolved/closed') }}</span>
                    </div>
                    <div
                        class="flex size-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">
                        <flux:icon name="arrow-path" class="size-5" />
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex items-baseline gap-2.5">
                        <flux:heading size="xl"
                            class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-zinc-100">
                            {{ $this->monthlyStats['reopen_rate'] }}%
                        </flux:heading>
                        @if ($this->monthlyStats['reopen_rate'] <= 5)
                            <flux:badge size="xs" color="emerald">{{ __('Healthy (< 5%)') }}</flux:badge>
                        @elseif ($this->monthlyStats['reopen_rate'] <= 12)
                            <flux:badge size="xs" color="amber">{{ __('Moderate') }}</flux:badge>
                        @else
                            <flux:badge size="xs" color="red">{{ __('High Reopen Rate') }}</flux:badge>
                        @endif
                    </div>

                    <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
                        <span>
                            <strong>{{ $this->monthlyStats['reopened_count'] }}</strong> {{ __('tickets reopened') }}
                        </span>
                        <span class="text-zinc-400">
                            ({{ $this->monthlyStats['total_reopen_events'] }} {{ __('total reopen events') }})
                        </span>
                    </div>
                </div>
            </flux:card>
        </div>
    </div>

    <!-- IT Team Members Workload Breakdown -->
    <flux:card class="p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">{{ __('Tickets Assigned Per IT Member') }}</flux:heading>
                    <flux:badge size="sm" color="zinc">{{ $this->itMemberWorkload->count() }}
                        {{ __('Staff') }}</flux:badge>
                </div>
                <flux:subheading>
                    {{ __('Workload distribution, first response responsiveness, and resolution durability for :month.', ['month' => $this->currentMonthLabel]) }}
                </flux:subheading>
            </div>

            <!-- Unassigned Summary Badge -->
            <div class="flex items-center gap-2">
                @if ($this->unassignedStats['month_unassigned'] > 0)
                    <div
                        class="flex items-center gap-1.5 rounded-md bg-amber-50 px-2.5 py-1 text-xs text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                        <flux:icon name="exclamation-circle" class="size-4" />
                        <span><strong>{{ $this->unassignedStats['month_unassigned'] }}</strong>
                            {{ __('tickets submitted without an assignee') }}</span>
                    </div>
                @endif
                <flux:button href="{{ route('tickets.index') }}" variant="ghost" size="sm"
                    icon-trailing="arrow-top-right-on-square" wire:navigate>
                    {{ __('View Tickets') }}
                </flux:button>
            </div>
        </div>

        <div class="mt-6 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('IT Staff Member') }}</flux:table.column>
                    <flux:table.column>{{ __('Assigned In Month') }}</flux:table.column>
                    <flux:table.column>{{ __('Resolved / Closed') }}</flux:table.column>
                    <flux:table.column>{{ __('Avg First Response') }}</flux:table.column>
                    <flux:table.column>{{ __('Reopened') }}</flux:table.column>
                    <flux:table.column>{{ __('Active Queue Now') }}</flux:table.column>
                    <flux:table.column>{{ __('Monthly Workload Share') }}</flux:table.column>
                    <flux:table.column class="text-end">{{ __('Resolution Rate') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->itMemberWorkload as $item)
                        @php /** @var \App\Models\User $staff */ $staff = $item['user']; @endphp
                        <flux:table.row :key="$staff->id">
                            <!-- Staff info -->
                            <flux:table.cell class="py-3.5">
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" :name="$staff->name"
                                        :initials="$staff->initials()" />
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $staff->name }}</span>
                                            @if ($staff->id === auth()->id())
                                                <flux:badge size="xs" color="sky">{{ __('You') }}
                                                </flux:badge>
                                            @endif
                                        </div>
                                        <span class="text-xs text-zinc-500 font-mono">{{ $staff->email }}</span>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <!-- Assigned count -->
                            <flux:table.cell class="py-3.5 font-medium tabular-nums">
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $item['assigned'] }}</span>
                                    <span class="text-xs text-zinc-400">{{ __('assigned') }}</span>
                                </div>
                            </flux:table.cell>

                            <!-- Resolved/Closed -->
                            <flux:table.cell class="py-3.5 tabular-nums">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="font-medium text-emerald-600 dark:text-emerald-400">
                                        {{ $item['resolved'] }} {{ __('resolved') }}
                                    </span>
                                    <span class="text-zinc-300 dark:text-zinc-600">&bull;</span>
                                    <span class="text-zinc-500 dark:text-zinc-400">
                                        {{ $item['closed'] }} {{ __('closed') }}
                                    </span>
                                </div>
                            </flux:table.cell>

                            <!-- Avg First Response (FRT) -->
                            <flux:table.cell class="py-3.5 tabular-nums text-xs">
                                @if ($item['avg_frt_minutes'] !== null)
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon name="chat-bubble-left" class="size-3.5 text-zinc-400" />
                                        <span class="font-medium text-zinc-800 dark:text-zinc-200">
                                            {{ $this->formatDuration($item['avg_frt_minutes']) }}
                                        </span>
                                    </div>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </flux:table.cell>

                            <!-- Reopened count -->
                            <flux:table.cell class="py-3.5 tabular-nums text-xs">
                                @if ($item['reopened_count'] > 0)
                                    <flux:badge size="xs" color="amber">
                                        {{ $item['reopened_count'] }} {{ __('reopened') }}
                                    </flux:badge>
                                @else
                                    <span class="text-zinc-400">0</span>
                                @endif
                            </flux:table.cell>

                            <!-- Active right now -->
                            <flux:table.cell class="py-3.5">
                                @if ($item['active'] > 0)
                                    <flux:badge size="sm" color="amber">
                                        {{ $item['active'] }} {{ __('Active') }}
                                    </flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">
                                        0 {{ __('Active') }}
                                    </flux:badge>
                                @endif
                            </flux:table.cell>

                            <!-- Share bar -->
                            <flux:table.cell class="py-3.5 w-44">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center justify-between text-xs text-zinc-500">
                                        <span>{{ $item['share'] }}%</span>
                                    </div>
                                    <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div class="h-full rounded-full bg-sky-500"
                                            style="width: {{ min(100, $item['share']) }}%"></div>
                                    </div>
                                </div>
                            </flux:table.cell>

                            <!-- Resolution % -->
                            <flux:table.cell class="py-3.5 text-end tabular-nums font-medium">
                                <span
                                    class="{{ $item['resolution_rate'] >= 75 ? 'text-emerald-600 dark:text-emerald-400' : ($item['resolution_rate'] >= 40 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500') }}">
                                    {{ $item['resolution_rate'] }}%
                                </span>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8" class="py-8 text-center text-zinc-500">
                                {{ __('No IT staff members found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    <!-- Daily Timeline Activity -->
    <flux:card class="p-6">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="lg">{{ __('Daily Activity Trend') }}</flux:heading>
                <flux:subheading>
                    {{ __('Daily volume of submitted tickets vs resolved tickets in :month.', ['month' => $this->currentMonthLabel]) }}
                </flux:subheading>
            </div>
            <div class="flex items-center gap-4 text-xs">
                <div class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-full bg-sky-500"></span>
                    <span class="text-zinc-600 dark:text-zinc-400">{{ __('Submitted') }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="size-2.5 rounded-full bg-emerald-500"></span>
                    <span class="text-zinc-600 dark:text-zinc-400">{{ __('Resolved') }}</span>
                </div>
            </div>
        </div>

        @php
            $maxCount = 1;
            foreach ($this->dailyActivity as $d) {
                if ($d['submitted'] > $maxCount) {
                    $maxCount = $d['submitted'];
                }
                if ($d['resolved'] > $maxCount) {
                    $maxCount = $d['resolved'];
                }
            }
        @endphp

        <div class="mt-6 flex h-44 items-end gap-1 overflow-x-auto pb-2 pt-6">
            @foreach ($this->dailyActivity as $day)
                @php
                    $subH = $day['submitted'] > 0 ? max(8, round(($day['submitted'] / $maxCount) * 110)) : 2;
                    $resH = $day['resolved'] > 0 ? max(8, round(($day['resolved'] / $maxCount) * 110)) : 2;
                @endphp
                <div class="group flex flex-1 min-w-[18px] flex-col items-center justify-end gap-1 h-full"
                    title="{{ $day['label'] }}: {{ $day['submitted'] }} submitted, {{ $day['resolved'] }} resolved">
                    <!-- Tooltip value indicator on hover -->
                    <div class="flex items-end gap-0.5 w-full justify-center">
                        <!-- Submitted bar -->
                        <div class="w-2 rounded-t transition-all {{ $day['submitted'] > 0 ? 'bg-sky-500 group-hover:bg-sky-400' : 'bg-zinc-200 dark:bg-zinc-800' }}"
                            style="height: {{ $subH }}px;"></div>
                        <!-- Resolved bar -->
                        <div class="w-2 rounded-t transition-all {{ $day['resolved'] > 0 ? 'bg-emerald-500 group-hover:bg-emerald-400' : 'bg-zinc-200 dark:bg-zinc-800' }}"
                            style="height: {{ $resH }}px;"></div>
                    </div>
                    <!-- Day label -->
                    <span
                        class="text-[10px] text-zinc-400 {{ $day['day'] % 5 === 1 ? 'font-medium text-zinc-600 dark:text-zinc-300' : 'hidden sm:block' }}">
                        {{ $day['day'] }}
                    </span>
                </div>
            @endforeach
        </div>
    </flux:card>

    <!-- Issue Breakdown Grids: Category, Priority, Division -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Category Breakdown -->
        <flux:card class="p-5">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="base">{{ __('By Category') }}</flux:heading>
                <flux:badge size="xs" color="zinc">{{ count($this->categoryBreakdown) }}
                    {{ __('types') }}</flux:badge>
            </div>
            <div class="space-y-3.5">
                @forelse ($this->categoryBreakdown as $catKey => $catData)
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $catData['label'] }}</span>
                            <span class="tabular-nums text-zinc-500 font-semibold">{{ $catData['count'] }} <span
                                    class="font-normal text-zinc-400">({{ $catData['percentage'] }}%)</span></span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full bg-indigo-500"
                                style="width: {{ $catData['percentage'] }}%"></div>
                        </div>
                    </div>
                @empty
                    <flux:text size="sm" class="text-zinc-400">{{ __('No category data recorded.') }}
                    </flux:text>
                @endforelse
            </div>
        </flux:card>

        <!-- Priority Breakdown -->
        <flux:card class="p-5">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="base">{{ __('By Priority') }}</flux:heading>
                <flux:badge size="xs" color="zinc">{{ count($this->priorityBreakdown) }}
                    {{ __('levels') }}</flux:badge>
            </div>
            <div class="space-y-3">
                @forelse ($this->priorityBreakdown as $priKey => $priData)
                    <div
                        class="flex items-center justify-between rounded-lg border border-zinc-100 p-2.5 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="$priData['color']">
                                {{ $priData['label'] }}
                            </flux:badge>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span
                                class="font-semibold text-zinc-900 dark:text-zinc-100 tabular-nums">{{ $priData['count'] }}</span>
                            <span class="text-zinc-400">({{ $priData['percentage'] }}%)</span>
                        </div>
                    </div>
                @empty
                    <flux:text size="sm" class="text-zinc-400">{{ __('No priority data recorded.') }}
                    </flux:text>
                @endforelse
            </div>
        </flux:card>

        <!-- Division (Department) Breakdown -->
        <flux:card class="p-5">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="base">{{ __('Top Divisions Requesting Help') }}</flux:heading>
                <flux:badge size="xs" color="zinc">{{ __('Departments') }}</flux:badge>
            </div>
            <div class="space-y-3">
                @forelse ($this->divisionBreakdown as $divKey => $divData)
                    @if ($divData['count'] > 0 || $loop->iteration <= 4)
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span
                                    class="font-medium text-zinc-800 dark:text-zinc-200">{{ $divData['label'] }}</span>
                                <span
                                    class="tabular-nums font-semibold text-zinc-900 dark:text-zinc-100">{{ $divData['count'] }}
                                    <span
                                        class="font-normal text-zinc-400">({{ $divData['percentage'] }}%)</span></span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full bg-teal-500"
                                    style="width: {{ $divData['percentage'] }}%"></div>
                            </div>
                        </div>
                    @endif
                @empty
                    <flux:text size="sm" class="text-zinc-400">{{ __('No division data recorded.') }}
                    </flux:text>
                @endforelse
            </div>
        </flux:card>
    </div>
</div>
