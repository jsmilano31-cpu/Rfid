<?php
use App\Services\AnalyticsService;
$analyticsSections=AnalyticsService::sections();$analyticsAdmin=str_starts_with($_SERVER['REQUEST_URI']??'','/admin');
?>
<section class="analytics-workspace" data-analytics data-endpoint="<?= e(url('/api/analytics.php')) ?>">
    <header class="analytics-heading">
        <div><p class="eyebrow">CAMPUS INSIGHTS</p><h1>Analytics</h1><p class="muted">Understand the patterns. Inspect the evidence. Decide what needs attention.</p></div>
        <div class="analytics-heading-actions">
            <button type="button" class="button button-secondary" data-analytics-print aria-label="Print the current analytics view"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17H4V9h16v8h-3M7 14h10v7H7zM17 11h1"/></svg><span>Print view</span></button>
            <a data-analytics-export data-no-page-loader class="button button-primary" href="#"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/></svg><span data-analytics-export-label>Export records</span><span class="analytics-action-short" aria-hidden="true">Export records</span></a>
        </div>
    </header>
    <nav class="analytics-tabs" aria-label="Analytics sections"><?php foreach($analyticsSections as $key=>$label): ?><a href="<?= e(url(($analyticsAdmin?'/admin/':'/index/').'?page=analytics&section='.$key)) ?>" data-analytics-tab="<?= e($key) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
    <form class="analytics-filters" data-analytics-filters aria-label="Filter analytics" aria-describedby="analytics-filter-help">
        <div class="analytics-filter-heading"><h2>Filter your view</h2><p id="analytics-filter-help">Choose a reporting period and optional academic filters.</p></div>
        <fieldset class="analytics-filter-group" data-analytics-period-group>
            <legend>Reporting period</legend>
            <div class="analytics-filter-fields">
                <label class="field"><span>Start date</span><input type="date" name="from" required></label>
                <label class="field"><span>End date</span><input type="date" name="to" required max="<?= e(date('Y-m-d')) ?>"></label>
                <label class="field"><span>Semester</span><select name="semester_id" data-searchable data-analytics-select data-empty-label="All semesters"><option value="0">All semesters</option></select></label>
            </div>
        </fieldset>
        <fieldset class="analytics-filter-group" data-analytics-academic-group>
            <legend>Academic filters</legend>
            <div class="analytics-filter-fields">
                <?php foreach(['department_id'=>['Department','All departments'],'program_id'=>['Program','All programs'],'section_id'=>['Section','All sections'],'teacher_id'=>['Teacher','All teachers'],'course_id'=>['Subject','All subjects'],'offering_id'=>['Class offering','All class offerings']] as $key=>[$label,$empty]): ?>
                    <label class="field"><span><?= e($label) ?></span><select name="<?= e($key) ?>" data-searchable data-analytics-select data-empty-label="<?= e($empty) ?>"><option value="0"><?= e($empty) ?></option></select></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="analytics-filter-footer"><p>Leave a selection at All to include every available option.</p><div class="analytics-filter-actions"><button class="button button-primary" type="submit">Apply filters</button><button type="button" class="button button-secondary" data-analytics-reset>Reset</button></div></div>
    </form>
    <div class="analytics-meta"><span data-analytics-scope></span><span data-analytics-freshness></span><button type="button" class="analytics-text-button" data-analytics-refresh>Refresh view</button></div>
    <p class="analytics-live muted" data-analytics-status role="status" aria-live="polite"></p>
    <section data-analytics-results aria-busy="true"><div class="analytics-cards" data-analytics-cards></div><aside class="analytics-brief" data-analytics-insights aria-label="Decision brief"></aside><div class="analytics-panels" data-analytics-panels></div></section>
    <details class="analytics-history"><summary>Data history and calculation notes</summary><p data-analytics-history></p><p>Rates retain their denominators. Missing data stays missing. Filters apply within your access scope; activities and communications use their own dates. Grade results retain their subject and lecture/laboratory component.</p><p data-analytics-print-filters></p></details>
    <dialog class="analytics-drawer" data-analytics-drawer aria-labelledby="analytics-record-title"><header><div><p class="eyebrow">SUPPORTING RECORDS</p><h2 id="analytics-record-title">Inspect the evidence</h2></div><button type="button" class="icon-action-button" data-analytics-close aria-label="Close supporting records"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><div class="analytics-drawer-controls"><label class="field"><span>Search records</span><input type="search" data-analytics-record-search placeholder="Name, school ID or subject"></label><a class="button button-secondary" data-analytics-record-export data-no-page-loader href="#">Export filtered records</a></div><p data-analytics-record-status role="status" aria-live="polite"></p><div class="analytics-table-scroll" data-analytics-records></div><footer><button type="button" class="button button-secondary" data-analytics-previous>Previous</button><span data-analytics-page></span><button type="button" class="button button-secondary" data-analytics-next>Next</button></footer></dialog>
</section>
