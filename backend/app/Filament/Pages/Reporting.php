<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Fellwise\Bookings\Infrastructure\Reporting\PerformanceReport;
use Fellwise\Bookings\Infrastructure\Reporting\ReportResult;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Reporting API over Core's CQRS read model. Each card shows its query
 * time and index; the badge opens the real SQL and EXPLAIN plan.
 */
final class Reporting extends Page
{
    protected string $view = 'filament.pages.reporting';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Insight';

    protected static ?int $navigationSort = 1;

    /** @var array<string, string> */
    public const array RANGES = ['30d' => 'Last 30 days', '90d' => 'Last 90 days', '12m' => 'Last 12 months', '18m' => 'Last 18 months'];

    #[Url]
    public string $range = '12m';

    #[Url]
    public string $grain = 'month';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        [$from, $to] = $this->window();
        $report = app(PerformanceReport::class);
        $regions = DB::connection('cms')->table('regions')->pluck('name', 'id');

        $byRegion = $report->revenueByRegion($from, $to);
        $overTime = $report->bookingsOverTime($from, $to, $this->grain);
        $top = $report->topExperiences($from, $to);
        $lead = $report->leadTimeHistogram($from, $to);

        $revenue = array_sum(array_column($byRegion->rows, 'revenue_minor'));
        $bookings = array_sum(array_column($byRegion->rows, 'bookings'));
        $guests = array_sum(array_column($byRegion->rows, 'guests'));

        return [
            'regions' => $regions,
            'byRegion' => $byRegion,
            'overTime' => $overTime,
            'top' => $top,
            'lead' => $lead,
            'reports' => ['region' => $byRegion, 'time' => $overTime, 'top' => $top, 'lead' => $lead],
            'kpi' => [
                'revenue' => $revenue,
                'bookings' => $bookings,
                'guests' => $guests,
                'aov' => $bookings > 0 ? intdiv($revenue, $bookings) : 0,
            ],
            'rows' => [
                'rm' => (int) DB::connection('core')->table('rm_experience_performance')->count(),
                'bookings' => (int) DB::connection('core')->table('bookings')->count(),
            ],
            'maxRegion' => max([1, ...array_column($byRegion->rows, 'revenue_minor')]),
            'maxTime' => max([1, ...array_column($overTime->rows, 'bookings')]),
            'maxLead' => max([1, ...array_column($lead->rows, 'bookings')]),
            'from' => $from,
            'to' => $to,
        ];
    }

    public function explainAction(): Action
    {
        return Action::make('explain')
            ->modalHeading(fn (array $arguments) => match ($arguments['report'] ?? '') {
                'region' => 'Revenue by region',
                'time' => 'Bookings over time',
                'top' => 'Top experiences',
                default => 'Booking lead time',
            })
            ->modalDescription('The exact query behind this card, run against the Core read model, and MySQL\'s plan for it.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments) {
                [$from, $to] = $this->window();
                $report = app(PerformanceReport::class);
                /** @var ReportResult<array<string, mixed>> $result */
                $result = match ($arguments['report'] ?? '') {
                    'region' => $report->revenueByRegion($from, $to),
                    'time' => $report->bookingsOverTime($from, $to, $this->grain),
                    'top' => $report->topExperiences($from, $to),
                    default => $report->leadTimeHistogram($from, $to),
                };

                return view('filament.pages.partials.explain', ['result' => $result]);
            });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(): array
    {
        $to = CarbonImmutable::today();

        return [match ($this->range) {
            '30d' => $to->subDays(30),
            '90d' => $to->subDays(90),
            '18m' => $to->subMonths(18),
            default => $to->subMonths(12),
        }, $to];
    }
}
