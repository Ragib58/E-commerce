# Dashboard and Reporting

Ten metrics, seven charts, seven reports, three export formats — and one rule
underneath all of them: **a dashboard must never be the most expensive page in
the application.**

---

## 1. What counts as a sale

The load-bearing distinction in this phase is that there are two different
questions an admin asks about orders, and answering both with the same query is
the usual way a sales figure ends up wrong:

| Question | Population | Where |
|---|---|---|
| "How much did we take?" | Orders whose payment settled, net of refunds | `RevenueScope::revenue()` |
| "How many orders came in?" | Every order placed, cancellations included | `RevenueScope::orders()` |

A cancelled order and a failed payment are both rows in `orders`. Neither is
money. But both are orders, and an operations dashboard that hid them would be
lying about volume.

So `RevenueScope` is the only place either population is defined, and every
metric, chart, and report routes through it. The definition of a sale cannot
drift between the dashboard tile and the report meant to reconcile with it.

**Net, not gross.** `grand_total` is what was charged and `refunded_total` is
what went back; sales figures use the difference. This is why a partially
refunded order stays *in* the population rather than being excluded — the
unrefunded remainder is genuine revenue, and dropping the whole order would
understate it.

Both lists are configurable in `config/reporting.php` (`revenue.paid_statuses`,
`revenue.excluded_statuses`) rather than hardcoded, because which statuses count
is an accounting policy, not a fact about the code.

## 2. Date ranges

`DateRange` is a value object carrying two instants, a granularity, and a cache
key. It exists because three things about a range are easy to get subtly wrong
independently in a dozen places:

- **The upper bound.** `whereBetween('created_at', [$from, $to])` with a `$to`
  of `2026-03-01` silently excludes everything placed that day after midnight.
  `DateRange` always resolves `to` to the end of its day.
- **The granularity.** "Last 7 days" grouped by month is one bar; grouped by
  hour it is 168. Each preset declares its own bucket size, and a custom range
  picks one from its span so a chart lands between roughly 7 and 60 points.
- **The comparison window.** A "+12% vs previous period" tile needs an
  equally-long window immediately preceding. `previous()` measures in seconds
  rather than re-applying the preset, so "this month" on the 10th compares
  against the 10 days before it started — not against all of last month, which
  would report a collapse every month.

Presets: `today`, `last_7_days`, `last_30_days`, `this_month`, `this_year`,
`custom`. Custom ranges require `from` and `to`, must not be inverted, and are
capped at `reporting.limits.max_range_days` (default ~3 years) — a five-year
range grouped by day scans most of the orders table to draw something nobody
reads.

## 3. Caching

This is the part the brief asks for by name, and the part that decides whether
the dashboard is usable.

Everything reporting computes goes under one Redis cache tag (`reports`), with
two TTLs picked per query rather than one compromise:

- **Short (5 min)** for windows containing *now*, and for live figures like the
  pending-order count.
- **Long (1 hour)** for windows that have already closed — last March cannot
  produce different numbers no matter how long the answer is held.

A warm dashboard runs **zero queries**; `ReportCachingTest` asserts exactly
that, because "we added caching" and "the second load is free" are different
claims.

**Invalidation is wholesale.** `InvalidateReportingCache` drops the entire tag
on `OrderPlaced`, `OrderStatusChanged`, `StockAdjusted`, `CustomerRegistered`,
and `CatalogChanged`. Working out *which* of a dozen aggregates one new order
touches is a calculation that would be wrong the moment a metric is added, and
being wrong shows up as a dashboard quietly disagreeing with the order list
beneath it. Recomputing costs a second; reconciling a wrong dashboard costs an
afternoon. The flush is synchronous, not queued — an admin who just marked an
order delivered and reloads must not see the old count.

Dropping the `reports` tag leaves `catalog`, `content`, and `settings`
untouched; there is a test for that too.

**Without a taggable store** (a file cache, some test configurations)
`ReportCache::enabled()` returns false and every method computes directly. A
store without tags is slower, never wrong.

## 4. Query shape

Caching is the second line of defence. The first is that the uncached queries
are cheap:

- **Conditional aggregation.** The order-status counts are one query with three
  `SUM(CASE WHEN ...)` columns, not three `COUNT`s over the same rows. Sales and
  order counts come back from a single pass over the window.
- **Everything is aggregated in the database.** Bucketing a year of orders into
  twelve points happens in SQL, not by transferring a year of rows to group them
  in PHP. `ReportCachingTest` asserts the query count does not grow with the
  data.
- **Ranked series are `LIMIT`ed in SQL**, so "top products" returns ten rows
  whether the store sells fifty products or fifty thousand.
- **No N+1 in aggregates.** The customer report's lifetime value is a `SUM` in a
  grouped left join, not a per-customer query in a loop — invisible on a seed
  database, fatal on a real one.
- **Correlated subqueries where a join would multiply.** The order report's item
  count is a subquery; joining `order_items` would duplicate each order row per
  line and require regrouping every money column back down.

Grouped reports are counted through a subquery. A `GROUP BY` query's naive
`->count()` returns the size of the first group rather than the number of
groups — a paginator claiming 900 pages for a 12-row report.

**Product grain.** Product-level aggregates group by `product_id`, not by the
snapshot name and SKU on the line. Those change: a product renamed or re-SKU'd
between two sales would split into two rows under a snapshot grouping, showing
it twice with neither figure correct. The *label* still comes from the snapshot,
so an item reports under the name it was sold under.

## 5. Metrics and charts

**Metrics** (`GET /admin/dashboard/metrics`) — total / today's / monthly sales,
total / pending / delivered / cancelled orders, total customers, total products,
low stock, out of stock, average order value, and period-on-period change.

Two deliberate choices:

- "Today's sales" and "monthly sales" are *fixed* windows regardless of the
  selected filter — a tile labelled "Today" must mean today.
- Status counts are *live*, not range-scoped: "pending orders" is a work queue
  asking what needs attention now, and filtering the dashboard to last March
  should not hide today's backlog.

Growth from zero reports `null` rather than `+100%`. There is no meaningful
percentage increase from nothing.

**Charts** (`GET /admin/dashboard/charts`) — sales overview, orders overview,
revenue by date (gross / refunds / net), top products, top categories, payment
methods, order status distribution.

Two properties are asserted because both fail silently:

- **Gaps are filled.** A day with no orders returns no row from `GROUP BY`, and
  a chart that omits the point draws a flat line across it — a sales chart
  lying about a bad week. Every series is projected onto the complete bucket
  list with zeros.
- **Every category appears.** A payment-method chart listing only methods used
  in the window is ambiguous between "nobody used bKash" and "bKash is not
  offered". Zeros are included.

Money is returned as **integer minor units** throughout. Formatting is the
client's job; a pre-formatted string would bake today's currency symbol into an
hour-long cache entry.

## 6. Reports

| Report | Rows are | Date-scoped | Searchable |
|---|---|---|---|
| Sales | Periods | ✓ | — |
| Orders | Orders | ✓ | ✓ |
| Product sales | Products | ✓ | ✓ |
| Customers | Customers | ✓ | ✓ |
| Payments | Payment attempts | ✓ | ✓ |
| Tax | Periods | ✓ | — |
| Inventory | Products | — | ✓ |

Columns are declared once, on `ReportType::columns()`, and read by the table,
the CSV writer, the spreadsheet writer, and the PDF template. Four
independently-maintained column lists is how an export ends up with a header row
that no longer matches its data.

Notes on individual reports:

- **Customers** uses a left join so customers who never ordered still appear at
  zero — a dormant-account list is one of the things the report is for, and an
  inner join would hide exactly those. The date range constrains the *orders
  joined in*, not which customers are listed, which is why those predicates sit
  in the `JOIN` condition rather than the `WHERE`.
- **Payments** includes failed attempts. A report used to investigate a
  gateway's failure rate is useless if it hides the failures.
- **Tax** derives the effective rate from collected tax over the taxable base
  rather than averaging `orders.tax_rate` — a period containing two rates should
  weight them by value, not by order count.
- **Inventory** is not date-scoped: stock is a present-tense fact, and "stock
  levels last March" is not a question the warehouse asks. The date filter is
  hidden for it rather than accepted and ignored.

Totals in a report footer cover the **whole report**, not the current page — a
footer that changes as you page through is worse than none — and respect the
active filters.

## 7. Exports

CSV, Excel (.xlsx), and PDF.

**CSV and Excel stream**; a fifty-thousand-row export holds one row in memory at
a time. **PDF cannot** — its layout engine needs the whole document to
paginate — so it is capped far lower (5,000 rows). An export that exceeds its
format's cap is refused *before any rows are fetched*, with a message saying to
narrow the range; refusing halfway through means the client already has a
partial file under a 200 status that cannot be retracted.

**CSV formula injection.** A cell beginning `=`, `+`, `-`, or `@` executes when
opened in Excel or Sheets. These reports carry customer-supplied strings — a
product name, someone's display name — so an unescaped export turns "download
the sales report" into a way to run a formula on a finance machine. Such cells
are prefixed with a tab, which every major spreadsheet treats as literal text
and none display. Files also open with a UTF-8 BOM so Excel on Windows reads
accented names correctly instead of mangling them to the legacy codepage.

**Excel is written directly as OOXML** rather than through PhpSpreadsheet, which
builds a whole workbook in memory before writing it. Numbers are written as
*numbers* with a currency format applied — the point of offering a spreadsheet
over a CSV is that the recipient can sum a column. Strings are inline rather
than shared, keeping memory flat, and are XML-escaped with illegal control
characters stripped, since one stray byte makes the workbook unopenable rather
than merely odd-looking.

**PDF** renders landscape (fourteen columns do not fit portrait A4 readably)
through the same Dompdf configuration invoices use, with remote resource loading
**off** — with it on, a crafted product name reaching an `img` src would make
the renderer issue HTTP requests from inside the network.

Both Excel and PDF depend on optional extensions (`ext-zip`, `dompdf/dompdf`).
`GET /admin/dashboard/filters` reports which formats this installation can
actually produce, so the panel hides a button rather than offering one that
returns a 503.

## 8. Access control

```
GET  /admin/dashboard              view_reports | manage_reports
GET  /admin/dashboard/metrics      view_reports | manage_reports
GET  /admin/dashboard/charts       view_reports | manage_reports
GET  /admin/dashboard/filters      view_reports | manage_reports
GET  /admin/reports                view_reports | manage_reports
GET  /admin/reports/{report}       view_reports | manage_reports
GET  /admin/reports/{report}/export             manage_reports
```

Exporting needs the stronger permission. Reading a summary on screen and walking
out with a file containing every customer's email address and lifetime value are
different acts, and the second is the one worth gating separately.

A dashboard aggregates the store's whole commercial position, so these are
gated on the reporting permissions specifically rather than on being an
authenticated admin — a support account that cannot open a single order should
not be able to read total revenue.

## 9. Query parameters

```
period          today | last_7_days | last_30_days | this_month | this_year | custom
from, to        YYYY-MM-DD, required when period=custom
search          free text, matched literally (% and _ are escaped)
status          order status, or product status for the inventory report
payment_status  filter for the order report
payment_method  filter for the order report
gateway         filter for the payment report
stock_state     in | low | out, for the inventory report
per_page        capped at reporting.limits.max_per_page
page
top             ranked-series size, capped at reporting.limits.max_top_n
format          csv | xlsx | pdf, on the export endpoint
```

Search terms are bound as parameters with `%` and `_` escaped, so a search for
"50%" finds the company with that in its name rather than matching every row.
