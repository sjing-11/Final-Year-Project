<?php
// views/dashboard.php
declare(strict_types=1);

/* --- Load PDO --- */
$root = dirname(__DIR__);
$loadedPdo = false;

foreach ([$root . '/db.php', $root . '/app/db.php', $root . '/config/db.php'] as $maybe) {
    if (is_file($maybe)) {
        require_once $maybe;
        $loadedPdo = true;
        break;
    }
}
if (!$loadedPdo || !isset($pdo) || !$pdo instanceof PDO) {
    die('<div style="padding:16px;color:#b00020;">Database connection failed.</div>');
}

/* --- Helpers --- */
if (!function_exists('e')) {
    function e($t): string
    {
        return htmlspecialchars((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
function money_fmt($v): string
{
    return 'RM' . number_format((float)($v ?? 0), 2);
}
function chip_class(string $s): string
{
    $s = strtolower($s);
    return $s === 'low stock' ? 'po-status st-delayed'
        : ($s === 'out of stock' ? 'po-status st-rejected' : 'status ok');
}

/* --- Data (top cards + table) --- */
try {
    $inv = $pdo->query("
    SELECT COALESCE(SUM(stock_quantity),0) AS in_hand,
           COALESCE((SELECT SUM(pod.quantity)
                     FROM purchase_order_details pod
                     JOIN purchase_order po ON po.po_id=pod.po_id
                     WHERE po.status IN ('Confirmed','Shipped','Delayed')),0) AS to_receive
    FROM item;
  ")->fetch(PDO::FETCH_ASSOC);

    $exp = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN expiry_date<=CURDATE() AND stock_quantity>0 THEN 1 ELSE 0 END),0) AS expired_count,
      COALESCE(SUM(CASE WHEN expiry_date<=CURDATE() AND stock_quantity>0 THEN stock_quantity*selling_price ELSE 0 END),0) AS expired_val,
      COALESCE(SUM(CASE WHEN expiry_date>CURDATE() AND expiry_date<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND stock_quantity>0 THEN 1 ELSE 0 END),0) AS expiring_count,
      COALESCE(SUM(CASE WHEN expiry_date>CURDATE() AND expiry_date<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND stock_quantity>0 THEN stock_quantity*selling_price ELSE 0 END),0) AS expiring_val
    FROM item;
  ")->fetch(PDO::FETCH_ASSOC);

    $po = $pdo->query("
    SELECT
      COALESCE(COUNT(DISTINCT po.po_id),0) AS purchase_count,
      COALESCE(SUM(pod.quantity*pod.unit_price),0) AS cost,
      COALESCE(SUM(CASE WHEN po.status='Rejected' THEN 1 ELSE 0 END),0) AS cancel_count,
      COALESCE(SUM(CASE WHEN po.status='Rejected' THEN pod.quantity*pod.unit_price ELSE 0 END),0) AS return_val
    FROM purchase_order po
    LEFT JOIN purchase_order_details pod ON po.po_id=pod.po_id;
  ")->fetch(PDO::FETCH_ASSOC);

    $prod = $pdo->query("
    SELECT
      COALESCE(COUNT(DISTINCT supplier_id),0) AS suppliers,
      (SELECT COALESCE(COUNT(category_id),0) FROM category) AS categories
    FROM item
    LIMIT 1;
  ")->fetch(PDO::FETCH_ASSOC);

    $action = $pdo->query("
    SELECT
      item_id,
      item_name AS Name,
      COALESCE(stock_quantity,0) AS Quantity,
      COALESCE(threshold_quantity,0) AS `Threshold Value`,
      CASE
        WHEN COALESCE(stock_quantity,0)=0 THEN 'Out of stock'
        WHEN COALESCE(stock_quantity,0) <= COALESCE(threshold_quantity,0) THEN 'Low stock'
        ELSE 'In-stock'
      END AS Availability
    FROM item
    WHERE COALESCE(stock_quantity,0) <= COALESCE(threshold_quantity,0)
    ORDER BY Quantity ASC
    LIMIT 5;
  ")->fetchAll(PDO::FETCH_ASSOC);


    /* ===================== CHART DATA (clean) ===================== */
    $year = (int)date('Y');

    /* --- MONTHLY (Jan–Dec of current year, padded) --- */
    $monthLabels  = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $monthBuckets = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthBuckets[$m] = ['label' => $monthLabels[$m - 1], 'fulfill' => 0, 'ontime' => 0];
    }

    $monthlyRows = $pdo->query("
    SELECT
        MONTH(gr.receive_date) AS m,
        ROUND(AVG(CASE WHEN po.status IN ('Completed') THEN 100 ELSE 0 END),0) AS fulfill_rate,
        ROUND(AVG(CASE WHEN po.expected_date IS NOT NULL AND gr.receive_date <= po.expected_date THEN 100 ELSE 0 END),0) AS on_time_rate
    FROM purchase_order po
    JOIN goods_receipt gr ON po.po_id = gr.po_id
    WHERE YEAR(gr.receive_date) = {$year}
    GROUP BY MONTH(gr.receive_date)
")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthlyRows as $r) {
        $m = (int)$r['m'];
        $monthBuckets[$m]['fulfill'] = (int)($r['fulfill_rate'] ?? 0);
        $monthBuckets[$m]['ontime']  = (int)($r['on_time_rate'] ?? 0);
    }

    $labelsMonthly      = array_values(array_column($monthBuckets, 'label'));
    $dataFulfillMonthly = array_values(array_map(fn($x) => $x['fulfill'], $monthBuckets));
    $dataOnTimeMonthly  = array_values(array_map(fn($x) => $x['ontime'],  $monthBuckets));


    /* --- WEEKLY (week-of-month Wk1..Wk5 per month) --- */
    $weeklyRows = $pdo->query("
    SELECT
        MONTH(gr.receive_date) AS m,
        CEIL(DAYOFMONTH(gr.receive_date)/7) AS wk, /* FIXED to gr.receive_date */
        ROUND(AVG(CASE WHEN po.status IN ('Completed') THEN 100 ELSE 0 END),0) AS fulfill_rate,
        ROUND(AVG(CASE WHEN po.expected_date IS NOT NULL AND gr.receive_date <= po.expected_date THEN 100 ELSE 0 END),0) AS on_time_rate
    FROM purchase_order po
    JOIN goods_receipt gr ON po.po_id = gr.po_id 
    WHERE YEAR(gr.receive_date) = {$year}
    GROUP BY MONTH(gr.receive_date), CEIL(DAYOFMONTH(gr.receive_date)/7)
")->fetchAll(PDO::FETCH_ASSOC);

    $weeklyByMonth = [];
    for ($m = 1; $m <= 12; $m++) {
        $weeklyByMonth[$m] = [
            'label'   => $monthLabels[$m - 1],
            'weeks'   => ['Wk 1', 'Wk 2', 'Wk 3', 'Wk 4', 'Wk 5'],
            'fulfill' => [0, 0, 0, 0, 0],
            'ontime'  => [0, 0, 0, 0, 0],
        ];
    }
    foreach ($weeklyRows as $r) {
        $m  = (int)$r['m'];
        $wk = max(1, min(5, (int)$r['wk'])) - 1;
        $weeklyByMonth[$m]['fulfill'][$wk] = (int)($r['fulfill_rate'] ?? 0);
        $weeklyByMonth[$m]['ontime'][$wk]  = (int)($r['on_time_rate'] ?? 0);
    }
    $weeklyByMonthJSON = json_encode($weeklyByMonth, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $err = $e->getMessage();
}
?>
<section class="page dashboard-page">
    <h1 class="page-title">Dashboard Overview</h1>

    <div class="dashboard-wrap">

        <!-- Row 1 -->
        <div class="dashboard-row-1">
            <!-- Upcoming Expiry -->
            <div class="card dash-card h163">
                <h2 class="dash-card-title">Upcoming Expiry</h2>
                <div class="dash-metrics-grid expiry-grid">
                    <div class="dash-metric">
                        <div class="dash-metric-value"><?= (int)($exp['expiring_count'] ?? 0) ?></div>
                        <div class="dash-metric-label">Expiring Items</div>
                    </div>
                    <div class="dash-metric">
                        <div class="dash-metric-value red"><?= money_fmt($exp['expiring_val'] ?? 0) ?></div>
                        <div class="dash-metric-label">Total Value Expiring</div>
                    </div>
                    <div class="dash-metric">
                        <div class="dash-metric-value"><?= (int)($exp['expired_count'] ?? 0) ?></div>
                        <div class="dash-metric-label">Expired Items</div>
                    </div>
                    <div class="dash-metric">
                        <div class="dash-metric-value red"><?= money_fmt($exp['expired_val'] ?? 0) ?></div>
                        <div class="dash-metric-label">Total Value Expired</div>
                    </div>
                </div>
            </div>

            <!-- Inventory Summary -->
            <div class="card dash-card h163">
                <h2 class="dash-card-title">Inventory Summary</h2>
                <div class="dash-icon-metrics">
                    <div class="dash-icon-metric">
                        <div class="dash-icon orange-bg">📦</div>
                        <div>
                            <div class="dash-icon-value"><?= (int)($inv['in_hand'] ?? 0) ?></div>
                            <div class="dash-icon-label">Quantity in Hand</div>
                        </div>
                    </div>
                    <div class="dash-icon-metric">
                        <div class="dash-icon purple-bg">📥</div>
                        <div>
                            <div class="dash-icon-value"><?= (int)($inv['to_receive'] ?? 0) ?></div>
                            <div class="dash-icon-label">To be Received</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2 -->
        <div class="dashboard-row-2 v2dash">
            <!-- Purchase Overview -->
            <div class="card dash-card h163">
                <h2 class="dash-card-title">Purchase Overview</h2>
                <div class="dash-metrics-grid purchase-grid">
                    <div class="dash-metric centered">
                        <div class="dash-metric-value"><?= (int)($po['purchase_count'] ?? 0) ?></div>
                        <div class="dash-metric-label">Purchase</div>
                    </div>
                    <div class="dash-metric centered">
                        <div class="dash-metric-value"><?= money_fmt($po['cost'] ?? 0) ?></div>
                        <div class="dash-metric-label">Cost</div>
                    </div>
                    <div class="dash-metric centered">
                        <div class="dash-metric-value"><?= (int)($po['cancel_count'] ?? 0) ?></div>
                        <div class="dash-metric-label">Cancel</div>
                    </div>
                    <div class="dash-metric centered">
                        <div class="dash-metric-value"><?= money_fmt($po['return_val'] ?? 0) ?></div>
                        <div class="dash-metric-label">Return</div>
                    </div>
                </div>
            </div>

            <!-- Product Summary -->
            <div class="card dash-card h163">
                <h2 class="dash-card-title">Product Summary</h2>
                <div class="dash-icon-metrics">
                    <div class="dash-icon-metric">
                        <div class="dash-icon blue-bg">👥</div>
                        <div>
                            <div class="dash-icon-value"><?= (int)($prod['suppliers'] ?? 0) ?></div>
                            <div class="dash-icon-label">Suppliers</div>
                        </div>
                    </div>
                    <div class="dash-icon-metric">
                        <div class="dash-icon blue-bg">📂</div>
                        <div>
                            <div class="dash-icon-value"><?= (int)($prod['categories'] ?? 0) ?></div>
                            <div class="dash-icon-label">Categories</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 4 -->
        <div class="dashboard-row-4">
            <div class="card dash-card h305" id="invActionCard">
                <div class="dash-card-header">
                    <h2 class="dash-card-title">Inventory Action Required</h2>


                    <!-- make the button and options discoverable in JS -->
                    <div class="dash-actions">
                        <a id="seeAllBtn" href="?page=items&av=all" class="btn btn-primary slim">See all</a>

                        <div class="filter-dropdown" id="actionFilter">
                            <button id="actionFilterBtn" class="btn btn-secondary slim">Filters ▾</button>
                            <div class="filter-dropdown-content">
                                <div class="filter-header">AVAILABILITY</div>
                                <a class="filter-option" data-filter="all">All</a>
                                <a class="filter-option" data-filter="low">Low Stock</a>
                                <a class="filter-option" data-filter="out">Out of Stock</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dash-action-table" id="actionTable">
                    <div class="dash-table-head">
                        <div>Name</div>
                        <div>Qty</div>
                        <div>Threshold</div>
                        <div>Status</div>
                        <div>Action</div>
                    </div>

                    <?php if ($action): foreach ($action as $r):
                            $availText = strtolower($r['Availability']);           // 'low stock' | 'out of stock' | 'in-stock'
                            $availKey  = str_contains($availText, 'out') ? 'out'
                                : (str_contains($availText, 'low') ? 'low' : 'ok');
                    ?>
                            <!-- add data-availability to each row -->
                            <div class="dash-table-row" data-availability="<?= $availKey ?>">
                                <div class="name-col"><?= e($r['Name']) ?></div>
                                <div><?= (int)$r['Quantity'] ?></div>
                                <div><?= (int)$r['Threshold Value'] ?></div>
                                <div><span class="<?= chip_class($r['Availability']) ?>"><?= e($r['Availability']) ?></span></div>
                                <div><a href="?page=item_details&id=<?= (int)$r['item_id'] ?>" class="btn btn-ghost slim">More</a></div>
                            </div>
                        <?php endforeach;
                    else: ?>
                        <div class="dash-table-row">
                            <div style="grid-column:1/-1;text-align:center;color:#667085;">No action required</div>
                        </div>
                    <?php endif; ?>

                    <!-- hidden placeholder for "no matches" after filtering -->
                    <div class="dash-table-row" id="actionNoMatchRow" style="display:none;">
                        <div style="grid-column:1/-1;text-align:center;color:#667085;">No items match this filter</div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Row 4: Chart.js -->
    <div class="dashboard-row-3">
        <div class="card dash-card h360">
            <div class="dash-card-header">
                <h2 class="dash-card-title">Overall Fulfillment & Delivery Rates</h2>
                <div style="display:flex; gap:6px; align-items:center;">
                    <select id="weeklyMonth" style="display:none"></select>
                    <div id="monthPicker" class="month-picker">
                        <button type="button" class="mp-btn">Jan ▾</button>
                        <div class="mp-menu"></div>
                    </div>
                    <button id="btnMonthly" class="btn btn-primary slim">Monthly</button>
                    <button id="btnWeekly" class="btn btn-secondary slim">Weekly</button>
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="fulfillmentChart"></canvas>
            </div>
            <div class="chart-legend">
                <div class="legend-item"><span class="legend-color blue"></span> Fulfillment Rate</div>
                <div class="legend-item"><span class="legend-color green"></span> On-Time Rate</div>
            </div>
        </div>
    </div><!-- /.dashboard-row-3 -->




    </div><!-- /.dashboard-wrap -->
</section>

<script>
    (function() {
        const table = document.getElementById('actionTable');
        const rows = Array.from(table.querySelectorAll('.dash-table-row'))
            .filter(r => r.hasAttribute('data-availability')); // only data rows
        const menu = document.querySelector('#actionFilter .filter-dropdown-content');
        const btn = document.getElementById('actionFilterBtn');
        const noMatchRow = document.getElementById('actionNoMatchRow');
        const seeAll = document.getElementById('seeAllBtn');

        function applyFilter(val) { // val: 'all' | 'low' | 'out'
            let any = false;
            rows.forEach(r => {
                const ok = (val === 'all') || (r.dataset.availability === val);
                r.style.display = ok ? 'grid' : 'none';
                if (ok) any = true;
            });
            noMatchRow.style.display = any ? 'none' : 'grid';
            // update button text for clarity
            const label = val === 'all' ? 'Filters ▾' : `Availability: ${val === 'low' ? 'Low Stock' : 'Out of Stock'} ▾`;
            btn.textContent = label;

            // mark active option
            menu.querySelectorAll('.filter-option').forEach(a => {
                a.classList.toggle('active', a.dataset.filter === val);
            });

            // Sync the "See all" link target (adjust URL to your route if needed)
            const targetBase = '?page=items'; // CHANGE THIS if your list page differs
            const param = `av=${encodeURIComponent(val)}`;
            seeAll.href = `${targetBase}&${param}`;
        }



        // Click handler for menu
        menu.addEventListener('click', (e) => {
            const a = e.target.closest('.filter-option');
            if (!a) return;
            e.preventDefault();
            applyFilter(a.dataset.filter);
        });

        // default = All
        applyFilter('all');
    })();
</script>


<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function() {
        /* PHP → JS */
        const monthly = {
            labels: <?= json_encode($labelsMonthly) ?>,
            fulfill: <?= json_encode($dataFulfillMonthly) ?>,
            ontime: <?= json_encode($dataOnTimeMonthly) ?>
        };
        const weeklyByMonth = <?= $weeklyByMonthJSON ?>; // {1:{label, weeks, fulfill[], ontime[]}, …, 12:…}

        /* Elements */
        const ctx = document.getElementById('fulfillmentChart').getContext('2d');
        const btnMonthly = document.getElementById('btnMonthly');
        const btnWeekly = document.getElementById('btnWeekly');
        const selMonth = document.getElementById('weeklyMonth');

        /* Populate month select (Jan..Dec) */
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const nowMonth = (new Date()).getMonth() + 1; // 1..12
        monthNames.forEach((n, i) => {
            const opt = document.createElement('option');
            opt.value = i + 1;
            opt.textContent = n;
            selMonth.appendChild(opt);
        });
        selMonth.value = String(nowMonth);

        // ------ Build custom month picker and sync to hidden select ------
        const mp = document.getElementById('monthPicker');
        const mpBtn = mp.querySelector('.mp-btn');
        const mpMenu = mp.querySelector('.mp-menu');


        monthNames.forEach((n, i) => {
            const item = document.createElement('div');
            item.className = 'mp-item';
            item.textContent = n;
            item.dataset.val = String(i + 1);
            mpMenu.appendChild(item);
        });

        function setMonthVal(m) {
            mpBtn.textContent = monthNames[m - 1] + ' ▾';
            mpMenu.querySelectorAll('.mp-item').forEach(el => {
                el.classList.toggle('active', el.dataset.val === String(m));
            });
            selMonth.value = String(m);
            selMonth.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        }


        setMonthVal(nowMonth);


        mpBtn.addEventListener('click', () => {
            mp.classList.toggle('open');
        });


        mpMenu.addEventListener('click', (e) => {
            const it = e.target.closest('.mp-item');
            if (!it) return;
            setMonthVal(parseInt(it.dataset.val, 10));
            mp.classList.remove('open');
        });


        document.addEventListener('click', (e) => {
            if (!mp.contains(e.target)) mp.classList.remove('open');
        });

        function setModeButton(which) {
            if (which === 'monthly') {
                btnMonthly.classList.add('btn-primary');
                btnMonthly.classList.remove('btn-secondary');
                btnWeekly.classList.add('btn-secondary');
                btnWeekly.classList.remove('btn-primary');
                mp.style.display = 'none';
            } else {
                btnWeekly.classList.add('btn-primary');
                btnWeekly.classList.remove('btn-secondary');
                btnMonthly.classList.add('btn-secondary');
                btnMonthly.classList.remove('btn-primary');
                mp.style.display = '';
            }
        }

        function datasetConfig(series) {
            return {
                type: 'bar',
                data: {
                    labels: series.labels,
                    datasets: [{
                            label: 'Fulfillment Rate',
                            data: series.fulfill,
                            backgroundColor: 'rgba(59, 130, 246, 0.6)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1,
                            barPercentage: 0.45,
                            categoryPercentage: 0.6
                        },
                        {
                            label: 'On-Time Rate',
                            data: series.ontime,
                            backgroundColor: 'rgba(16, 185, 129, 0.6)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 1,
                            barPercentage: 0.45,
                            categoryPercentage: 0.6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            left: 8,
                            right: 12,
                            top: 4,
                            bottom: 10
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y}%`
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#475569',
                                font: {
                                    size: 12
                                },
                                autoSkip: true,
                                maxRotation: 0,
                                minRotation: 0,
                                padding: 6
                            }
                        },
                        y: {
                            min: 0,
                            max: 100,
                            ticks: {
                                stepSize: 25,
                                callback: v => v + '%',
                                color: '#475569',
                                font: {
                                    size: 12
                                },
                                padding: 6
                            },
                            grid: {
                                color: '#eef2f6'
                            }
                        }
                    }
                }

            };
        }

        /* Build initial chart (Monthly) */
        let mode = 'monthly';
        setModeButton(mode);
        let chart = new Chart(ctx, datasetConfig({
            labels: monthly.labels,
            fulfill: monthly.fulfill,
            ontime: monthly.ontime
        }));

        /* Helpers for weekly series of selected month */
        function weeklySeriesForMonth(m) {
            const pack = weeklyByMonth[m];
            return {
                labels: pack.weeks,
                fulfill: pack.fulfill,
                ontime: pack.ontime
            };
        }

        /* Events */
        btnMonthly.addEventListener('click', () => {
            if (mode === 'monthly') return;
            mode = 'monthly';
            setModeButton(mode);
            chart.data.labels = monthly.labels;
            chart.data.datasets[0].data = monthly.fulfill;
            chart.data.datasets[1].data = monthly.ontime;
            chart.update();
        });

        btnWeekly.addEventListener('click', () => {
            if (mode === 'weekly') return;
            mode = 'weekly';
            setModeButton(mode);
            const m = parseInt(selMonth.value, 10);
            const s = weeklySeriesForMonth(m);
            chart.data.labels = s.labels;
            chart.data.datasets[0].data = s.fulfill;
            chart.data.datasets[1].data = s.ontime;
            chart.update();
        });

        selMonth.addEventListener('change', () => {
            if (mode !== 'weekly') return;
            const m = parseInt(selMonth.value, 10);
            const s = weeklySeriesForMonth(m);
            chart.data.labels = s.labels;
            chart.data.datasets[0].data = s.fulfill;
            chart.data.datasets[1].data = s.ontime;
            chart.update();
        });

        /* Keep crisp on resize */
        window.addEventListener('resize', () => chart.resize());
    })();
</script>